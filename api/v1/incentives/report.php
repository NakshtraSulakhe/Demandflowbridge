<?php
/**
 * DemandFlow Bridge CRM - Incentive Payroll Reconciliation & Audit Report API
 * GET /api/v1/incentives/report.php?month=YYYY-MM&export=csv|json
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();
IncentiveAuthZ::enforceCanApprove($user);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    IncentiveResponse::error('Method not allowed. Only GET is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$monthStr = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    IncentiveResponse::error('Invalid month format. Expected YYYY-MM.', 'INVALID_MONTH', 400);
}

$year = (int)$m[1];
$month = (int)$m[2];
$export = isset($_GET['export']) ? strtolower(trim((string)$_GET['export'])) : 'json';

try {
    $report = ReconciliationService::getMonthlyPayrollReconciliation($year, $month);

    if ($export === 'csv') {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"incentive_payroll_reconciliation_{$monthStr}.csv\"");
        }
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Agent ID',
            'Agent Name',
            'Employee ID',
            'Department',
            'Settled Days',
            'Paid Days',
            'Total Settled Incentives (INR)',
            'Already Disbursed Daily (INR)',
            'Processing Daily (INR)',
            'Approved Unpaid (INR)',
            'Pending Approval (INR)',
            'Recommended Payroll Deduction (INR)',
        ]);

        foreach ($report['agents'] as $a) {
            fputcsv($out, [
                $a['agent_id'],
                $a['agent_name'],
                $a['employee_id'],
                $a['department'],
                $a['settled_days_count'],
                $a['paid_days_count'],
                number_format($a['total_settled_incentives'], 2, '.', ''),
                number_format($a['daily_disbursed_total'], 2, '.', ''),
                number_format($a['daily_processing_total'], 2, '.', ''),
                number_format($a['approved_unpaid_total'], 2, '.', ''),
                number_format($a['pending_approval_total'], 2, '.', ''),
                number_format($a['recommended_payroll_deduction'], 2, '.', ''),
            ]);
        }
        fclose($out);
        exit;
    }

    IncentiveResponse::success($report, "Monthly payroll reconciliation report for {$monthStr} generated successfully.");

} catch (Throwable $e) {
    IncentiveResponse::error('Failed to generate payroll report: ' . $e->getMessage(), 'REPORT_ERROR', 500);
}
