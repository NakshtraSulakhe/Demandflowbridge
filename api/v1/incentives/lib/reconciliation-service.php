<?php
/**
 * DemandFlow Bridge CRM - Incentive Reconciliation Service
 * 1. Resolves asynchronous gateway payout states (processing -> paid/failed)
 * 2. Generates Monthly Payroll Reconciliation datasets to prevent double-compensation
 */

class ReconciliationService {
    /**
     * Polls and reconciles all active payouts currently in 'processing' status
     */
    public static function reconcileProcessingPayouts(): array {
        $conn = getDbConnection();
        ensureIncentiveSchema($conn);

        // Fetch payouts currently processing
        $query = "
            SELECT p.id AS payout_id, p.agent_id, p.work_date, p.total_incentive,
                   a.id AS attempt_id, a.provider, a.provider_payout_id, a.provider_reference
            FROM daily_incentive_payouts p
            JOIN incentive_payment_attempts a ON a.payout_id = p.id AND a.attempt_status = 'processing'
            WHERE p.payment_status = 'processing'
            ORDER BY p.id ASC
        ";

        $res = $conn->query($query);
        $reconciled = [];
        $stillProcessing = [];

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $payoutId = (int)$row['payout_id'];
                $attemptId = (int)$row['attempt_id'];
                $providerName = (string)$row['provider'];
                $provPayoutId = (string)$row['provider_payout_id'];
                $provRef = (string)$row['provider_reference'];
                $amount = (float)$row['total_incentive'];
                $agentId = (int)$row['agent_id'];
                $workDate = (string)$row['work_date'];

                $provider = PaymentProviderFactory::getProvider($providerName);
                $statusResult = $provider->getPayoutStatus($provPayoutId, $provRef);
                $newStatus = strtolower((string)($statusResult['status'] ?? 'processing'));

                if ($newStatus === 'success' || $newStatus === 'processed') {
                    $conn->begin_transaction();
                    try {
                        $conn->query("UPDATE incentive_payment_attempts SET attempt_status = 'success', completed_at = NOW() WHERE id = {$attemptId}");
                        $conn->query("UPDATE daily_incentive_payouts SET payment_status = 'paid', updated_at = NOW() WHERE id = {$payoutId}");
                        SettlementService::recordEvent($conn, $payoutId, 'reconciliation_confirmed_paid', 'reconciliation_service', [
                            'provider' => $providerName,
                            'reference' => $provRef,
                        ]);
                        $conn->commit();
                        $reconciled[] = ['payout_id' => $payoutId, 'status' => 'paid', 'reference' => $provRef];

                        if (function_exists('createNotificationSmart')) {
                            $msg = sprintf("Your daily incentive of INR %s for %s has been confirmed and paid.", number_format($amount, 2), $workDate);
                            createNotificationSmart($agentId, 'incentive.paid', 'Daily Incentive Confirmed', $msg, '../performance/dashboard', [
                                'dedup_key' => "inc_paid_{$payoutId}",
                            ]);
                        }
                    } catch (Throwable $e) {
                        $conn->rollback();
                    }
                } elseif ($newStatus === 'failed' || $newStatus === 'reversed' || $newStatus === 'rejected') {
                    $conn->begin_transaction();
                    try {
                        $conn->query("UPDATE incentive_payment_attempts SET attempt_status = 'failed', completed_at = NOW() WHERE id = {$attemptId}");
                        $conn->query("UPDATE daily_incentive_payouts SET payment_status = 'failed', updated_at = NOW() WHERE id = {$payoutId}");
                        SettlementService::recordEvent($conn, $payoutId, 'reconciliation_marked_failed', 'reconciliation_service', [
                            'provider' => $providerName,
                            'reference' => $provRef,
                        ]);
                        $conn->commit();
                        $reconciled[] = ['payout_id' => $payoutId, 'status' => 'failed', 'reference' => $provRef];
                    } catch (Throwable $e) {
                        $conn->rollback();
                    }
                } else {
                    $stillProcessing[] = ['payout_id' => $payoutId, 'status' => 'processing'];
                }
            }
        }

        return [
            'reconciled_count' => count($reconciled),
            'still_processing_count' => count($stillProcessing),
            'reconciled' => $reconciled,
            'still_processing' => $stillProcessing,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Generates a monthly payroll reconciliation report
     * Calculates already disbursed daily incentives to prevent double-payment in monthly payslips
     */
    public static function getMonthlyPayrollReconciliation(int $year, int $month): array {
        $conn = getDbConnection();
        ensureIncentiveSchema($conn);

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $query = "
            SELECT 
                u.id AS agent_id,
                u.full_name AS agent_name,
                u.employee_id,
                u.department,
                COALESCE(SUM(p.total_incentive), 0.00) AS total_settled_incentives,
                COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.total_incentive ELSE 0.00 END), 0.00) AS daily_disbursed_total,
                COALESCE(SUM(CASE WHEN p.payment_status = 'processing' THEN p.total_incentive ELSE 0.00 END), 0.00) AS daily_processing_total,
                COALESCE(SUM(CASE WHEN p.payment_status = 'unpaid' AND p.approval_status = 'approved' THEN p.total_incentive ELSE 0.00 END), 0.00) AS approved_unpaid_total,
                COALESCE(SUM(CASE WHEN p.approval_status = 'pending_approval' THEN p.total_incentive ELSE 0.00 END), 0.00) AS pending_approval_total,
                COUNT(p.id) AS settled_days_count,
                SUM(CASE WHEN p.payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_days_count
            FROM users u
            LEFT JOIN daily_incentive_payouts p ON p.agent_id = u.id AND p.work_date BETWEEN '{$startDate}' AND '{$endDate}'
            WHERE u.is_active = 1
            GROUP BY u.id
            HAVING total_settled_incentives > 0 OR daily_disbursed_total > 0
            ORDER BY u.full_name ASC
        ";

        $res = $conn->query($query);
        $agents = [];
        $totalDailyDisbursed = 0.0;
        $totalSettled = 0.0;

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $disbursed = (float)$row['daily_disbursed_total'];
                $settled = (float)$row['total_settled_incentives'];
                $totalDailyDisbursed += $disbursed;
                $totalSettled += $settled;

                $agents[] = [
                    'agent_id' => (int)$row['agent_id'],
                    'agent_name' => (string)$row['agent_name'],
                    'employee_id' => (string)($row['employee_id'] ?? ''),
                    'department' => (string)($row['department'] ?? ''),
                    'total_settled_incentives' => $settled,
                    'daily_disbursed_total' => $disbursed,
                    'daily_processing_total' => (float)$row['daily_processing_total'],
                    'approved_unpaid_total' => (float)$row['approved_unpaid_total'],
                    'pending_approval_total' => (float)$row['pending_approval_total'],
                    'settled_days_count' => (int)$row['settled_days_count'],
                    'paid_days_count' => (int)$row['paid_days_count'],
                    'recommended_payroll_deduction' => $disbursed, // To be entered into payroll incentive override
                ];
            }
        }

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_agents_with_incentives' => count($agents),
                'total_settled_amount' => $totalSettled,
                'total_already_disbursed_daily' => $totalDailyDisbursed,
                'reconciliation_rule' => 'To prevent double compensation, monthly gross salary must deduct daily_disbursed_total from the Productivity incentives bucket in hr/payroll.php',
            ],
            'agents' => $agents,
        ];
    }
}
