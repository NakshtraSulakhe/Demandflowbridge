<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('hr.attendance_admin.page', []);
ensureCsrfToken();

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : '';
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];

$rows = getAttendanceMonthlyReport($year, $month);

@set_time_limit(0);
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance_monthly_' . $monthStr . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['month','user_id','name','role','department','working_days','present_days','half_days','paid_leave_days','unpaid_leave_days','absent_days','ncns_days','late_days','late_minutes','paid_days','attendance_percent']);
foreach ($rows as $r) {
    fputcsv($out, [
        $monthStr,
        (int)($r['user_id'] ?? 0),
        (string)($r['name'] ?? ''),
        (string)($r['role'] ?? ''),
        (string)($r['department'] ?? ''),
        (int)($r['working_days'] ?? 0),
        (int)($r['present_days'] ?? 0),
        (int)($r['half_days'] ?? 0),
        (int)($r['paid_leave_days'] ?? 0),
        (int)($r['unpaid_leave_days'] ?? 0),
        (int)($r['absent_days'] ?? 0),
        (int)($r['ncns_days'] ?? 0),
        (int)($r['late_days'] ?? 0),
        (int)($r['late_minutes'] ?? 0),
        number_format((float)($r['paid_days'] ?? 0), 1, '.', ''),
        number_format((float)($r['attendance_percent'] ?? 0), 1, '.', ''),
    ]);
}
fclose($out);
exit;
