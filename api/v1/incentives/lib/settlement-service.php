<?php
/**
 * DemandFlow Bridge CRM - End-of-Day Incentive Settlement Service
 * Creates idempotent, auditable settlement records in daily_incentive_payouts
 */

class SettlementService {
    /**
     * Executes the end-of-day settlement for a specific work date
     * 
     * @param string $workDate 'YYYY-MM-DD'
     * @param string $mode 'finalize' | 'dry_run' | 'recalculate_unpaid'
     * @param int|null $initiatedBy User ID of actor or 0 for system scheduler
     */
    public static function settleWorkDate(string $workDate, string $mode = 'finalize', ?int $initiatedBy = 0): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            throw new InvalidArgumentException("Invalid work_date format. Expected YYYY-MM-DD.");
        }

        $conn = getDbConnection();
        ensureIncentiveSchema($conn);

        // 1. Calculate daily metrics for all floor agents using CRM engine
        $calculatedData = IncentiveCalculator::calculateAllAgentsDaily($workDate);
        $agentCalcs = $calculatedData['agents'] ?? [];

        if ($mode === 'dry_run') {
            return [
                'work_date' => $workDate,
                'mode' => 'dry_run',
                'total_agents' => count($agentCalcs),
                'eligible_count' => $calculatedData['eligible_agents'] ?? 0,
                'total_settled_amount' => $calculatedData['total_incentive'] ?? 0.0,
                'records_created' => 0,
                'records_updated' => 0,
                'records_skipped' => count($agentCalcs),
                'preview_only' => true,
                'agents' => $agentCalcs,
            ];
        }

        // 2. Transactional settlement persistence
        $conn->begin_transaction();
        try {
            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $totalSettledAmount = 0.0;
            $eligibleCount = 0;

            foreach ($agentCalcs as $calc) {
                $agentId = (int)$calc['agent_id'];
                $targetPts = (float)$calc['target_points'];
                $achievedPts = (float)$calc['achieved_points'];
                $qCount = (int)$calc['qualified_leads_count'];
                $dqCount = (int)$calc['disqualified_leads_count'];
                $baseInc = (float)$calc['base_incentive'];
                $slabInc = (float)$calc['slab_incentive'];
                $totalInc = (float)$calc['total_incentive'];
                $eligStatus = (string)$calc['eligibility_status'];
                $eligReason = (string)$calc['eligibility_reason'];

                if ($eligStatus === 'eligible' && $totalInc > 0) {
                    $eligibleCount++;
                    $totalSettledAmount += $totalInc;
                }

                // Check for existing settlement record
                $stmtCheck = $conn->prepare("
                    SELECT id, approval_status, payment_status, total_incentive 
                    FROM daily_incentive_payouts 
                    WHERE agent_id = ? AND work_date = ? 
                    FOR UPDATE
                ");
                $existing = null;
                if ($stmtCheck) {
                    $stmtCheck->bind_param('is', $agentId, $workDate);
                    $stmtCheck->execute();
                    $existing = $stmtCheck->get_result()->fetch_assoc();
                    $stmtCheck->close();
                }

                if ($existing) {
                    $payoutId = (int)$existing['id'];
                    $payStatus = (string)($existing['payment_status'] ?? '');
                    $apprStatus = (string)($existing['approval_status'] ?? '');

                    // CRITICAL RULE: Never overwrite completed payouts when scores change later!
                    if ($payStatus === 'paid') {
                        $skippedCount++;
                        continue;
                    }

                    // If already approved or queued for payment, leave intact unless explicitly recalculating unpaid
                    if ($apprStatus === 'approved' && $mode !== 'recalculate_unpaid') {
                        $skippedCount++;
                        continue;
                    }

                    // Update existing pending record
                    $stmtUpd = $conn->prepare("
                        UPDATE daily_incentive_payouts SET
                            target_points = ?,
                            achieved_points = ?,
                            qualified_leads_count = ?,
                            disqualified_leads_count = ?,
                            base_incentive = ?,
                            slab_incentive = ?,
                            total_incentive = ?,
                            eligibility_status = ?,
                            eligibility_reason = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmtUpd) {
                        $stmtUpd->bind_param(
                            'ddiidddssi',
                            $targetPts,
                            $achievedPts,
                            $qCount,
                            $dqCount,
                            $baseInc,
                            $slabInc,
                            $totalInc,
                            $eligStatus,
                            $eligReason,
                            $payoutId
                        );
                        $stmtUpd->execute();
                        $stmtUpd->close();
                        $updatedCount++;

                        self::recordEvent($conn, $payoutId, 'settlement_updated', 'settlement_service', [
                            'initiated_by' => $initiatedBy,
                            'points' => $achievedPts,
                            'total_incentive' => $totalInc,
                            'eligibility_status' => $eligStatus,
                        ]);
                    }
                } else {
                    // Create new settlement row
                    $stmtIns = $conn->prepare("
                        INSERT INTO daily_incentive_payouts (
                            agent_id, work_date, target_points, achieved_points,
                            qualified_leads_count, disqualified_leads_count,
                            base_incentive, slab_incentive, total_incentive,
                            eligibility_status, eligibility_reason,
                            approval_status, payment_status, calculation_version, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', 'unpaid', 'v1', NOW())
                    ");
                    if ($stmtIns) {
                        $stmtIns->bind_param(
                            'isddiidddss',
                            $agentId,
                            $workDate,
                            $targetPts,
                            $achievedPts,
                            $qCount,
                            $dqCount,
                            $baseInc,
                            $slabInc,
                            $totalInc,
                            $eligStatus,
                            $eligReason
                        );
                        $stmtIns->execute();
                        $newId = (int)$stmtIns->insert_id;
                        $stmtIns->close();
                        $createdCount++;

                        self::recordEvent($conn, $newId, 'settlement_created', 'settlement_service', [
                            'initiated_by' => $initiatedBy,
                            'target_points' => $targetPts,
                            'achieved_points' => $achievedPts,
                            'total_incentive' => $totalInc,
                            'eligibility_status' => $eligStatus,
                        ]);
                    }
                }
            }

            $conn->commit();

            return [
                'work_date' => $workDate,
                'mode' => $mode,
                'total_agents' => count($agentCalcs),
                'eligible_count' => $eligibleCount,
                'total_settled_amount' => $totalSettledAmount,
                'records_created' => $createdCount,
                'records_updated' => $updatedCount,
                'records_skipped' => $skippedCount,
                'settled_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];

        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /**
     * Records an immutable event in incentive_payout_events
     */
    public static function recordEvent(mysqli $conn, int $payoutId, string $eventType, string $source, array $data = []): void {
        $stmt = $conn->prepare("
            INSERT INTO incentive_payout_events (payout_id, event_type, event_source, event_data, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt->bind_param('isss', $payoutId, $eventType, $source, $jsonData);
            $stmt->execute();
            $stmt->close();
        }
    }
}
