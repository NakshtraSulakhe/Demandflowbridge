<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('hr.payroll.page', []);
ensureCsrfToken();

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : '';
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];

$rows = getPayrollSummaryRows($year, $month);

@set_time_limit(0);
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payroll_summary_' . $monthStr . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'month','user_id','name','role','department',
    'working_days','full_month_working_days','salary_working_days','present_days','half_days','absent_days','paid_leave_days','unpaid_leave_days','ncns_days','paid_days','salary_payable_days',
    'basic','allowances','daily_salary_rate','base_prorated','incentives','bonus','gross','salary_proration_rule',
    'pf','professional_tax','tds','loan_emi','other_deductions','ncns_penalty_days','ncns_penalty_amount','deductions_total',
    'net_salary'
]);
foreach ($rows as $r) {
    fputcsv($out, [
        $monthStr,
        (int)($r['user_id'] ?? 0),
        (string)($r['name'] ?? ''),
        (string)($r['role'] ?? ''),
        (string)($r['department'] ?? ''),
        number_format((float)($r['working_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['full_month_working_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['salary_working_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['present_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['half_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['absent_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['paid_leave_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['unpaid_leave_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['ncns_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['paid_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['salary_payable_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['basic'] ?? 0), 2, '.', ''),
        number_format((float)($r['allowances'] ?? 0), 2, '.', ''),
        number_format((float)($r['daily_salary_rate'] ?? 0), 2, '.', ''),
        number_format((float)($r['base_prorated'] ?? 0), 2, '.', ''),
        number_format((float)($r['incentives'] ?? 0), 2, '.', ''),
        number_format((float)($r['bonus'] ?? 0), 2, '.', ''),
        number_format((float)($r['gross'] ?? 0), 2, '.', ''),
        (string)($r['salary_proration_rule'] ?? ''),
        number_format((float)($r['pf'] ?? 0), 2, '.', ''),
        number_format((float)($r['professional_tax'] ?? 0), 2, '.', ''),
        number_format((float)($r['tds'] ?? 0), 2, '.', ''),
        number_format((float)($r['loan_emi'] ?? 0), 2, '.', ''),
        number_format((float)($r['other'] ?? 0), 2, '.', ''),
        number_format((float)($r['ncns_penalty_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['ncns_penalty_amount'] ?? 0), 2, '.', ''),
        number_format((float)($r['deductions_total'] ?? 0), 2, '.', ''),
        number_format((float)($r['net_salary'] ?? 0), 2, '.', ''),
    ]);
}
fclose($out);
exit;
