<?php
/**
 * DemandFlow Bridge CRM - Incentive Payout & Approval Workflow Service
 * Handles manager approvals, beneficiary resolution, and secure idempotent disbursements
 */

class PayoutService {
    /**
     * Executes manager approval or rejection on a batch of settlement payout records
     */
    public static function processApprovals(array $payoutIds, string $action, int $managerId, ?string $reason = ''): array {
        $action = strtolower(trim($action));
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException("Invalid approval action. Expected 'approve' or 'reject'.");
        }

        $conn = getDbConnection();
        ensureIncentiveSchema($conn);

        $successful = [];
        $failed = [];

        foreach ($payoutIds as $rawId) {
            $payoutId = (int)$rawId;
            if ($payoutId <= 0) continue;

            $conn->begin_transaction();
            try {
                // Fetch payout with locking
                $stmt = $conn->prepare("
                    SELECT id, agent_id, work_date, total_incentive, approval_status, payment_status, eligibility_status
                    FROM daily_incentive_payouts 
                    WHERE id = ? 
                    FOR UPDATE
                ");
                $payout = null;
                if ($stmt) {
                    $stmt->bind_param('i', $payoutId);
                    $stmt->execute();
                    $payout = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                if (!$payout) {
                    $conn->rollback();
                    $failed[] = ['id' => $payoutId, 'reason' => 'Payout record not found.'];
                    continue;
                }

                $agentId = (int)$payout['agent_id'];
                $apprStatus = (string)$payout['approval_status'];
                $payStatus = (string)$payout['payment_status'];
                $eligStatus = (string)$payout['eligibility_status'];

                // 1. Separation of duties validation
                if ($managerId > 0 && $managerId === $agentId) {
                    $conn->rollback();
                    $failed[] = ['id' => $payoutId, 'reason' => 'Separation of duties violation: Manager cannot approve their own incentive.'];
                    continue;
                }

                // 2. State validation
                if ($payStatus === 'paid') {
                    $conn->rollback();
                    $failed[] = ['id' => $payoutId, 'reason' => 'Payout has already been disbursed.'];
                    continue;
                }

                if ($eligStatus !== 'eligible' && $action === 'approve') {
                    $conn->rollback();
                    $failed[] = ['id' => $payoutId, 'reason' => 'Cannot approve an ineligible incentive record.'];
                    continue;
                }

                if ($apprStatus === 'approved' && $action === 'approve') {
                    $conn->rollback();
                    $failed[] = ['id' => $payoutId, 'reason' => 'Payout is already approved.'];
                    continue;
                }

                // 3. Record approval history
                $stmtHist = $conn->prepare("
                    INSERT INTO incentive_payout_approvals (
                        payout_id, manager_id, approval_action, reason, approved_at, rejected_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                if ($stmtHist) {
                    $appAt = ($action === 'approve') ? gmdate('Y-m-d H:i:s') : null;
                    $rejAt = ($action === 'reject') ? gmdate('Y-m-d H:i:s') : null;
                    $stmtHist->bind_param('iissss', $payoutId, $managerId, $action, $reason, $appAt, $rejAt);
                    $stmtHist->execute();
                    $stmtHist->close();
                }

                // 4. Update payout state
                $newApprovalStatus = ($action === 'approve') ? 'approved' : 'rejected';
                $stmtUpd = $conn->prepare("
                    UPDATE daily_incentive_payouts SET 
                        approval_status = ?,
                        approved_by = ?,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                if ($stmtUpd) {
                    $stmtUpd->bind_param('sii', $newApprovalStatus, $managerId, $payoutId);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }

                // 5. Emit audit event
                SettlementService::recordEvent($conn, $payoutId, 'payout_' . $action . 'd', 'manager_action', [
                    'manager_id' => $managerId,
                    'action' => $action,
                    'reason' => $reason,
                    'previous_status' => $apprStatus,
                ]);

                $conn->commit();
                $successful[] = ['id' => $payoutId, 'action' => $action, 'status' => $newApprovalStatus];

            } catch (Throwable $e) {
                $conn->rollback();
                $failed[] = ['id' => $payoutId, 'reason' => 'Internal error: ' . $e->getMessage()];
            }
        }

        return [
            'action' => $action,
            'total_requested' => count($payoutIds),
            'successful_count' => count($successful),
            'failed_count' => count($failed),
            'successful' => $successful,
            'failed' => $failed,
        ];
    }

    /**
     * Resolves the verified beneficiary bank or UPI details for an agent
     */
    public static function resolveBeneficiary(int $agentId, string $method = 'bank_transfer'): ?array {
        $conn = getDbConnection();

        // 1. Check incentive_beneficiaries first
        $stmtB = $conn->prepare("
            SELECT payment_method, account_holder_name, bank_name, account_number_masked, 
                   account_number_encrypted, ifsc_code, upi_id_masked, upi_id_encrypted, is_verified 
            FROM incentive_beneficiaries 
            WHERE user_id = ? AND payment_method = ? 
            LIMIT 1
        ");
        if ($stmtB) {
            $stmtB->bind_param('is', $agentId, $method);
            $stmtB->execute();
            $ben = $stmtB->get_result()->fetch_assoc();
            $stmtB->close();
            if ($ben && !empty($ben['is_verified'])) {
                return [
                    'source' => 'incentive_beneficiaries',
                    'method' => $method,
                    'name' => (string)($ben['account_holder_name'] ?? ''),
                    'bank_name' => (string)($ben['bank_name'] ?? ''),
                    'account_number' => (string)($ben['account_number_encrypted'] ?? $ben['account_number_masked'] ?? ''),
                    'ifsc' => (string)($ben['ifsc_code'] ?? ''),
                    'upi_id' => (string)($ben['upi_id_encrypted'] ?? $ben['upi_id_masked'] ?? ''),
                    'masked_account' => (string)($ben['account_number_masked'] ?? ''),
                    'masked_upi' => (string)($ben['upi_id_masked'] ?? ''),
                ];
            }
        }

        // 2. Fallback to existing CRM user_bank_details table
        $stmtU = $conn->prepare("
            SELECT b.bank_name, b.account_number, b.account_type, b.ifsc_code, b.pan_number, u.full_name
            FROM user_bank_details b
            JOIN users u ON u.id = b.user_id
            WHERE b.user_id = ? 
            LIMIT 1
        ");
        if ($stmtU) {
            $stmtU->bind_param('i', $agentId);
            $stmtU->execute();
            $ub = $stmtU->get_result()->fetch_assoc();
            $stmtU->close();
            if ($ub && !empty($ub['account_number']) && !empty($ub['ifsc_code'])) {
                $acc = trim((string)$ub['account_number']);
                $ifsc = strtoupper(trim((string)$ub['ifsc_code']));
                return [
                    'source' => 'user_bank_details',
                    'method' => 'bank_transfer',
                    'name' => (string)($ub['full_name'] ?? 'Agent'),
                    'bank_name' => (string)($ub['bank_name'] ?? ''),
                    'account_number' => $acc,
                    'ifsc' => $ifsc,
                    'upi_id' => '',
                    'masked_account' => IncentiveAuth::maskAccountNumber($acc),
                    'masked_upi' => '—',
                ];
            }
        }

        return null;
    }

    /**
     * Executes the authorized disbursement for an approved incentive payout
     */
    public static function disbursePayout(int $payoutId, int $initiatorId, ?string $overrideMethod = null): array {
        $conn = getDbConnection();
        ensureIncentiveSchema($conn);

        $conn->begin_transaction();
        try {
            // Lock and fetch payout record
            $stmt = $conn->prepare("
                SELECT id, agent_id, work_date, total_incentive, approval_status, payment_status, payment_method
                FROM daily_incentive_payouts 
                WHERE id = ? 
                FOR UPDATE
            ");
            $payout = null;
            if ($stmt) {
                $stmt->bind_param('i', $payoutId);
                $stmt->execute();
                $payout = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            if (!$payout) {
                $conn->rollback();
                return ['success' => false, 'error' => 'Payout record not found.'];
            }

            $agentId = (int)$payout['agent_id'];
            $amount = (float)$payout['total_incentive'];
            $workDate = (string)$payout['work_date'];
            $apprStatus = (string)$payout['approval_status'];
            $payStatus = (string)$payout['payment_status'];
            $method = $overrideMethod ?: (string)($payout['payment_method'] ?? 'bank_transfer');

            // 1. Validation checks
            if ($apprStatus !== 'approved') {
                $conn->rollback();
                return ['success' => false, 'error' => 'Payout must be approved by an authorized manager before disbursement.'];
            }

            if ($payStatus === 'paid') {
                $conn->rollback();
                return ['success' => false, 'error' => 'Payout has already been paid and cannot be disbursed again.'];
            }

            if ($payStatus === 'processing') {
                $conn->rollback();
                return ['success' => false, 'error' => 'Payout is currently being processed by the payment provider.'];
            }

            if ($amount <= 0.0) {
                $conn->rollback();
                return ['success' => false, 'error' => 'Incentive amount must be greater than zero.'];
            }

            // 2. Beneficiary resolution
            $beneficiary = self::resolveBeneficiary($agentId, $method);
            if (!$beneficiary) {
                $conn->rollback();
                return ['success' => false, 'error' => "No verified {$method} beneficiary details found for this agent."];
            }

            // 3. Count attempts & construct stable idempotency key
            $stmtAttCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM incentive_payment_attempts WHERE payout_id = ?");
            $attemptNumber = 1;
            if ($stmtAttCount) {
                $stmtAttCount->bind_param('i', $payoutId);
                $stmtAttCount->execute();
                $rowCnt = $stmtAttCount->get_result()->fetch_assoc();
                $stmtAttCount->close();
                $attemptNumber = ((int)($rowCnt['cnt'] ?? 0)) + 1;
            }

            $idempotencyKey = sprintf(
                'df_inc_%d_att%d_%s',
                $payoutId,
                $attemptNumber,
                substr(md5("{$payoutId}_{$agentId}_{$amount}_{$workDate}_{$attemptNumber}"), 0, 10)
            );

            $providerName = getenv('PAYOUT_PROVIDER') ?: 'sandbox';

            // 4. Create attempt record in 'queued' status
            $stmtAtt = $conn->prepare("
                INSERT INTO incentive_payment_attempts (
                    payout_id, attempt_number, provider, idempotency_key, amount, 
                    payment_method, attempt_status, initiated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'queued', NOW())
            ");
            $attemptId = 0;
            if ($stmtAtt) {
                $stmtAtt->bind_param('iissds', $payoutId, $attemptNumber, $providerName, $idempotencyKey, $amount, $method);
                $stmtAtt->execute();
                $attemptId = (int)$stmtAtt->insert_id;
                $stmtAtt->close();
            }

            // Mark payout processing
            $stmtUpd = $conn->prepare("UPDATE daily_incentive_payouts SET payment_status = 'processing', updated_at = NOW() WHERE id = ?");
            if ($stmtUpd) {
                $stmtUpd->bind_param('i', $payoutId);
                $stmtUpd->execute();
                $stmtUpd->close();
            }

            $conn->commit();

        } catch (Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Database error initiating payment: ' . $e->getMessage()];
        }

        // 5. Submit payout call to provider
        $provider = PaymentProviderFactory::getProvider($providerName);
        $providerResult = $provider->createPayout([
            'amount' => $amount,
            'currency' => 'INR',
            'payment_method' => $method,
            'idempotency_key' => $idempotencyKey,
            'beneficiary' => $beneficiary,
            'narration' => "Daily Incentive {$workDate}",
        ]);

        // 6. Update attempt and payout status based on provider response
        $conn->begin_transaction();
        try {
            $provPayoutId = (string)($providerResult['provider_payout_id'] ?? '');
            $provRef = (string)($providerResult['provider_reference'] ?? '');
            $resStatus = (string)($providerResult['status'] ?? 'failed');
            $errCode = (string)($providerResult['error_code'] ?? '');
            $errMsg = (string)($providerResult['error_message'] ?? '');

            if ($providerResult['success'] && ($resStatus === 'success' || $resStatus === 'processing')) {
                $finalPayoutStatus = ($resStatus === 'success') ? 'paid' : 'processing';
                $attemptStatus = ($resStatus === 'success') ? 'success' : 'processing';

                $stmtAttUpd = $conn->prepare("
                    UPDATE incentive_payment_attempts SET 
                        provider_payout_id = ?,
                        provider_reference = ?,
                        attempt_status = ?,
                        completed_at = CASE WHEN ? = 'success' THEN NOW() ELSE NULL END
                    WHERE id = ?
                ");
                if ($stmtAttUpd) {
                    $stmtAttUpd->bind_param('ssssi', $provPayoutId, $provRef, $attemptStatus, $attemptStatus, $attemptId);
                    $stmtAttUpd->execute();
                    $stmtAttUpd->close();
                }

                $stmtPayUpd = $conn->prepare("
                    UPDATE daily_incentive_payouts SET 
                        payment_status = ?,
                        payment_method = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                if ($stmtPayUpd) {
                    $stmtPayUpd->bind_param('ssi', $finalPayoutStatus, $method, $payoutId);
                    $stmtPayUpd->execute();
                    $stmtPayUpd->close();
                }

                SettlementService::recordEvent($conn, $payoutId, 'payment_' . $finalPayoutStatus, 'payout_service', [
                    'attempt_id' => $attemptId,
                    'provider' => $providerName,
                    'provider_reference' => $provRef,
                    'amount' => $amount,
                    'masked_beneficiary' => $beneficiary['masked_account'] ?: $beneficiary['masked_upi'],
                ]);

                // Dispatch notification to agent if paid immediately
                if ($finalPayoutStatus === 'paid' && function_exists('createNotificationSmart')) {
                    $msg = sprintf("Your daily incentive of INR %s for %s has been disbursed to your account (%s).", number_format($amount, 2), $workDate, $beneficiary['masked_account'] ?: $beneficiary['masked_upi']);
                    createNotificationSmart($agentId, 'incentive.paid', 'Daily Incentive Paid', $msg, '../performance/dashboard', [
                        'dedup_key' => "inc_paid_{$payoutId}",
                    ]);
                }

                $conn->commit();

                return [
                    'success' => true,
                    'payout_id' => $payoutId,
                    'amount' => $amount,
                    'payment_status' => $finalPayoutStatus,
                    'provider' => $providerName,
                    'provider_reference' => $provRef,
                    'beneficiary' => [
                        'name' => $beneficiary['name'],
                        'masked_account' => $beneficiary['masked_account'],
                        'masked_upi' => $beneficiary['masked_upi'],
                    ],
                ];

            } else {
                // Failure handling
                $stmtAttUpd = $conn->prepare("
                    UPDATE incentive_payment_attempts SET 
                        provider_payout_id = ?,
                        attempt_status = 'failed',
                        failure_code = ?,
                        failure_reason = ?,
                        completed_at = NOW()
                    WHERE id = ?
                ");
                if ($stmtAttUpd) {
                    $stmtAttUpd->bind_param('sssi', $provPayoutId, $errCode, $errMsg, $attemptId);
                    $stmtAttUpd->execute();
                    $stmtAttUpd->close();
                }

                $stmtPayUpd = $conn->prepare("UPDATE daily_incentive_payouts SET payment_status = 'failed', updated_at = NOW() WHERE id = ?");
                if ($stmtPayUpd) {
                    $stmtPayUpd->bind_param('i', $payoutId);
                    $stmtPayUpd->execute();
                    $stmtPayUpd->close();
                }

                SettlementService::recordEvent($conn, $payoutId, 'payment_failed', 'payout_service', [
                    'attempt_id' => $attemptId,
                    'provider' => $providerName,
                    'error_code' => $errCode,
                    'error_message' => $errMsg,
                ]);

                $conn->commit();

                return [
                    'success' => false,
                    'payout_id' => $payoutId,
                    'error' => $errMsg ?: 'Payment provider rejected transfer request.',
                    'error_code' => $errCode,
                ];
            }

        } catch (Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Database error updating payout outcome: ' . $e->getMessage()];
        }
    }
}
