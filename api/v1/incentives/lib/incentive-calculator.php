<?php
/**
 * DemandFlow Bridge CRM - Authoritative Incentive Calculator Bridge
 * Reuses existing CRM functions (funneltrackComputeDailyIncentive, funneltrackComputeAgentPeriodPoints)
 * without duplicating business logic.
 */

class IncentiveCalculator {
    /**
     * Calculates daily incentive breakdown for a single agent on a specific date
     */
    public static function calculateAgentDaily(int $agentId, string $date, ?array $cfg = null): array {
        $conn = getDbConnection();
        $cfg = $cfg ?? (function_exists('funneltrackGetConfig') ? funneltrackGetConfig() : []);

        // 1. Resolve agent basic profile
        $agentName = '';
        $role = '';
        $stmtU = $conn->prepare("SELECT id, full_name, role FROM users WHERE id = ? LIMIT 1");
        if ($stmtU) {
            $stmtU->bind_param('i', $agentId);
            $stmtU->execute();
            $u = $stmtU->get_result()->fetch_assoc();
            $stmtU->close();
            if ($u) {
                $agentName = (string)$u['full_name'];
                $role = (string)$u['role'];
            }
        }

        $year = (int)date('Y', strtotime($date));
        $month = (int)date('n', strtotime($date));

        // 2. Fetch daily target points from productivity_targets table
        $target = function_exists('getAgentMonthlyTarget') ? getAgentMonthlyTarget($agentId, $year, $month) : null;
        $dailyTarget = (float)($target['daily_target'] ?? 0.0);

        // 3. Resolve UTC timestamp boundaries for the target date
        $rangeUtc = function_exists('opsDateRangeUtc') ? opsDateRangeUtc($date, $date) : [
            'start' => $date . ' 00:00:00',
            'end' => $date . ' 23:59:59',
        ];
        $startTs = (string)($rangeUtc['start'] ?? ($date . ' 00:00:00'));
        $endTs = (string)($rangeUtc['end'] ?? ($date . ' 23:59:59'));

        // 4. Calculate achieved points and scored leads using authoritative CRM functions
        $periodPoints = function_exists('funneltrackComputeAgentPeriodPoints')
            ? funneltrackComputeAgentPeriodPoints($agentId, $startTs, $endTs, $cfg)
            : ['points' => 0.0, 'breakdown' => [], 'qualified' => 0];

        $achievedPoints = (float)($periodPoints['points'] ?? 0.0);
        $pointsBreakdown = $periodPoints['breakdown'] ?? [];

        $quality = function_exists('funneltrackComputeAgentQuality')
            ? funneltrackComputeAgentQuality($agentId, $startTs, $endTs)
            : ['qualified' => 0, 'disqualified' => 0];

        $qualifiedCount = (int)($quality['qualified'] ?? 0);
        $disqualifiedCount = (int)($quality['disqualified'] ?? 0);
        $totalScored = $qualifiedCount + $disqualifiedCount;

        // 5. Check attendance presence on the given date
        $attendanceStatus = 'Not Recorded';
        $isPresent = false;
        $stmtA = $conn->prepare("SELECT status FROM hr_attendance_days WHERE user_id = ? AND work_date = ? LIMIT 1");
        if ($stmtA) {
            $stmtA->bind_param('is', $agentId, $date);
            $stmtA->execute();
            $attRow = $stmtA->get_result()->fetch_assoc();
            $stmtA->close();
            if ($attRow && !empty($attRow['status'])) {
                $attendanceStatus = (string)$attRow['status'];
                $statusLower = strtolower($attendanceStatus);
                $isPresent = in_array($statusLower, ['full day', 'present', 'half day', 'in progress'], true);
            }
        }

        // 6. Check active QA escalation blocking
        $hasEscalation = function_exists('funneltrackHasActiveEscalation')
            ? funneltrackHasActiveEscalation($agentId, $date, $date)
            : false;

        $escMode = (string)($cfg['escalation_rule']['mode'] ?? 'disqualify');

        // 7. Calculate incentive amount using existing authoritative function
        $calculatedIncentive = 0;
        if ($dailyTarget > 0 && function_exists('funneltrackComputeDailyIncentive')) {
            $calculatedIncentive = funneltrackComputeDailyIncentive($dailyTarget, $achievedPoints, $cfg);
        }

        // Break down base vs slab incentive
        $dailyCfg = $cfg['daily_incentive'] ?? [];
        $baseAmountConfig = (int)($dailyCfg['base_amount'] ?? 500);
        $baseThresholdPct = (float)($dailyCfg['base_threshold_pct'] ?? 100);
        $thresholdPoints = ($baseThresholdPct / 100.0) * $dailyTarget;

        $baseIncentive = 0;
        $slabIncentive = 0;

        if ($dailyTarget > 0 && ($achievedPoints + 1e-9 >= $thresholdPoints)) {
            $baseIncentive = min($calculatedIncentive, $baseAmountConfig);
            $slabIncentive = max(0, $calculatedIncentive - $baseIncentive);
        }

        // Apply escalation adjustment if applicable
        $totalIncentive = $calculatedIncentive;
        if ($hasEscalation && $totalIncentive > 0) {
            if ($escMode === 'disqualify') {
                $totalIncentive = 0;
                $baseIncentive = 0;
                $slabIncentive = 0;
            } elseif ($escMode === 'partial') {
                $dedPct = (float)($cfg['escalation_rule']['deduction_pct'] ?? 100);
                $dedPct = max(0, min(100, $dedPct));
                $totalIncentive = (int)round($totalIncentive * (1.0 - ($dedPct / 100.0)));
                $baseIncentive = (int)round($baseIncentive * (1.0 - ($dedPct / 100.0)));
                $slabIncentive = max(0, $totalIncentive - $baseIncentive);
            }
        }

        // 8. Eligibility evaluation
        $eligibilityStatus = 'eligible';
        $eligibilityReason = 'Target achieved and compliance verified';

        if ($dailyTarget <= 0) {
            $eligibilityStatus = 'ineligible';
            $eligibilityReason = 'No daily target points allocated for this agent';
            $totalIncentive = 0;
        } elseif (!$isPresent) {
            $eligibilityStatus = 'ineligible';
            $eligibilityReason = "Agent not marked present in attendance on {$date} (Status: {$attendanceStatus})";
            $totalIncentive = 0;
        } elseif ($achievedPoints + 1e-9 < $thresholdPoints) {
            $eligibilityStatus = 'ineligible';
            $pctAchieved = $dailyTarget > 0 ? round(($achievedPoints / $dailyTarget) * 100, 1) : 0;
            $eligibilityReason = "Daily points below threshold ({$pctAchieved}% vs {$baseThresholdPct}% required)";
            $totalIncentive = 0;
        } elseif ($hasEscalation && $escMode === 'disqualify') {
            $eligibilityStatus = 'ineligible';
            $eligibilityReason = 'Active QA escalation blocks daily incentive';
            $totalIncentive = 0;
        } elseif ($totalIncentive <= 0) {
            $eligibilityStatus = 'ineligible';
            $eligibilityReason = 'Calculated incentive is zero';
        }

        $completionPct = $dailyTarget > 0 ? round(($achievedPoints / $dailyTarget) * 100, 1) : 0.0;

        return [
            'agent_id' => $agentId,
            'agent_name' => $agentName,
            'role' => $role,
            'work_date' => $date,
            'target_points' => $dailyTarget,
            'achieved_points' => $achievedPoints,
            'completion_pct' => $completionPct,
            'points_breakdown' => $pointsBreakdown,
            'qualified_leads_count' => $qualifiedCount,
            'disqualified_leads_count' => $disqualifiedCount,
            'total_scored_leads' => $totalScored,
            'base_incentive' => (float)$baseIncentive,
            'slab_incentive' => (float)$slabIncentive,
            'total_incentive' => (float)$totalIncentive,
            'attendance_status' => $attendanceStatus,
            'is_present' => $isPresent,
            'has_escalation' => $hasEscalation,
            'eligibility_status' => $eligibilityStatus,
            'eligibility_reason' => $eligibilityReason,
        ];
    }

    /**
     * Calculates daily incentive breakdown for all active agents for a specific date
     */
    public static function calculateAllAgentsDaily(string $date, ?array $cfg = null): array {
        $conn = getDbConnection();
        $cfg = $cfg ?? (function_exists('funneltrackGetConfig') ? funneltrackGetConfig() : []);

        $roles = function_exists('funneltrackPerformanceAgentRoles')
            ? funneltrackPerformanceAgentRoles()
            : ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];

        $roleIn = "'" . implode("','", array_map([$conn, 'real_escape_string'], $roles)) . "'";
        $query = "SELECT id FROM users WHERE is_active = 1 AND role IN ({$roleIn}) ORDER BY full_name ASC";
        $res = $conn->query($query);

        $agents = [];
        $totalEarned = 0.0;
        $eligibleCount = 0;

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $calc = self::calculateAgentDaily((int)$row['id'], $date, $cfg);
                $agents[] = $calc;
                if ($calc['eligibility_status'] === 'eligible' && $calc['total_incentive'] > 0) {
                    $eligibleCount++;
                    $totalEarned += $calc['total_incentive'];
                }
            }
        }

        return [
            'work_date' => $date,
            'total_agents' => count($agents),
            'eligible_agents' => $eligibleCount,
            'total_incentive' => $totalEarned,
            'agents' => $agents,
        ];
    }
}
