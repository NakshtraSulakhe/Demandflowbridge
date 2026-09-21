<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.attendance_admin.page', ['admin', 'director', 'manager_director']);
ensureCsrfToken();
$user = getCurrentUser();

$now = new DateTimeImmutable('now', hrBaseTz());
$selMonthStr = isset($_GET['month']) ? (string)$_GET['month'] : $now->format('Y-m');
if (preg_match('/^(\d{4})-(\d{2})$/', $selMonthStr, $m)) {
    $year = (int)$m[1];
    $month = (int)$m[2];
} else {
    $year = (int)$now->format('Y');
    $month = (int)$now->format('m');
    $selMonthStr = sprintf('%04d-%02d', $year, $month);
}

$rows = getAttendanceMonthlyReport($year, $month);
$holidays = getHolidaysForMonth($year, $month, 'US');

$totWorking = 0;
$totPresent = 0;
$totHalf = 0;
$totAbsent = 0;
$totNCNS = 0;
$totPL = 0;
$totUL = 0;
$totPaid = 0.0;
$totLateDays = 0;
$totLateMinutes = 0;
foreach ($rows as $r) {
    $totWorking += (int)($r['working_days'] ?? 0);
    $totPresent += (int)($r['present_days'] ?? 0);
    $totHalf += (int)($r['half_days'] ?? 0);
    $totAbsent += (int)($r['absent_days'] ?? 0);
    $totNCNS += (int)($r['ncns_days'] ?? 0);
    $totPL += (int)($r['paid_leave_days'] ?? 0);
    $totUL += (int)($r['unpaid_leave_days'] ?? 0);
    $totLateDays += (int)($r['late_days'] ?? 0);
    $totLateMinutes += (int)($r['late_minutes'] ?? 0);
    $totPaid += (float)($r['paid_days'] ?? 0);
}
$avgPct = $totWorking > 0 ? round((($totPaid / (float)$totWorking) * 100.0), 1) : 0.0;

function monthName($y, $m) { return date('F Y', mktime(0,0,0,$m,1,$y)); }
?>
<?php $pageTitle = 'Attendance Monthly Report'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Attendance Monthly Report'],
            ],
            'Attendance Monthly Report',
            'Monthly present/half/absent summary',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
                ['label' => 'Export CSV', 'href' => 'attendance-monthly-export?month=' . urlencode($selMonthStr), 'icon' => 'bi-download', 'class' => 'btn-outline-secondary'],
            ]
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Month</label>
                            <form method="get" class="d-flex gap-2">
                                <input type="month" name="month" class="form-control form-control-sm" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-funnel"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <?php hrKpi('Month', monthName($year, $month), 'bi-calendar3', 'US holidays: ' . number_format(count($holidays))); ?>
                        </div>
                        <div class="col-md-3">
                            <?php hrKpi('Present / Half', number_format($totPresent) . ' / ' . number_format($totHalf), 'bi-people', 'Paid days: ' . number_format($totPaid, 1)); ?>
                        </div>
                        <div class="col-md-3">
                            <?php hrKpi('Working Days (Total)', number_format($totWorking), 'bi-calendar2-week', 'Excludes Sat/Sun + US holidays'); ?>
                        </div>
                        <div class="col-md-3">
                            <?php hrKpi('Average Attendance', number_format($avgPct, 1) . '%', 'bi-graph-up', 'Paid days / working days'); ?>
                        </div>
                        <div class="col-md-3">
                            <?php hrKpi('Paid Leave / Unpaid Leave', number_format($totPL) . ' / ' . number_format($totUL), 'bi-calendar2-plus', 'Absent: ' . number_format($totAbsent)); ?>
                        </div>
                        <div class="col-md-3">
                            <?php hrKpi('NCNS (Total)', number_format($totNCNS), 'bi-exclamation-triangle', ''); ?>
                        </div>
                        <div class="col-md-3">
                            <?php hrKpi('Late (Total)', number_format($totLateMinutes) . ' min', 'bi-alarm', number_format($totLateDays) . ' late days'); ?>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-9">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle hr-table">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Job Title</th>
                                            <th>Department</th>
                                            <th class="text-end">Working</th>
                                            <th class="text-end text-success">Present</th>
                                            <th class="text-end text-warning">Half</th>
                                            <th class="text-end text-success">PL</th>
                                            <th class="text-end text-danger">UL</th>
                                            <th class="text-end text-danger">Absent</th>
                                            <th class="text-end text-danger">NCNS</th>
                                            <th class="text-end">Late Days</th>
                                            <th class="text-end">Late (min)</th>
                                            <th class="text-end">Paid Days</th>
                                            <th class="text-end">Adherence</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                                                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['job_title'] ?? '')); ?></td>
                                                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['department'] ?? '')); ?></td>
                                                <td class="text-end"><?php echo number_format((int)($r['working_days'] ?? 0)); ?></td>
                                                <td class="text-end text-success"><?php echo number_format((int)($r['present_days'] ?? 0)); ?></td>
                                                <td class="text-end text-warning"><?php echo number_format((int)($r['half_days'] ?? 0)); ?></td>
                                                <td class="text-end text-success"><?php echo number_format((int)($r['paid_leave_days'] ?? 0)); ?></td>
                                                <td class="text-end text-danger"><?php echo number_format((int)($r['unpaid_leave_days'] ?? 0)); ?></td>
                                                <td class="text-end text-danger"><?php echo number_format((int)($r['absent_days'] ?? 0)); ?></td>
                                                <td class="text-end text-danger"><?php echo number_format((int)($r['ncns_days'] ?? 0)); ?></td>
                                                <td class="text-end"><?php echo number_format((int)($r['late_days'] ?? 0)); ?></td>
                                                <td class="text-end"><?php echo number_format((int)($r['late_minutes'] ?? 0)); ?></td>
                                                <td class="text-end"><?php echo number_format((float)($r['paid_days'] ?? 0), 1); ?></td>
                                                <td class="text-end"><?php echo number_format((float)($r['attendance_percent'] ?? 0), 1); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($rows)): ?>
                                            <tr><td colspan="14" class="text-center text-muted">No users</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-calendar-event me-1"></i> US Holidays</div>
                                <div class="card-body">
                                    <?php if (empty($holidays)): ?>
                                        <div class="text-muted">No holidays in this month.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0 hr-table">
                                                <tbody>
                                                    <?php foreach ($holidays as $h): ?>
                                                        <tr>
                                                            <td class="font-monospace"><?php echo htmlspecialchars((string)($h['holiday_date'] ?? '')); ?></td>
                                                            <td class="text-muted small"><?php echo htmlspecialchars((string)($h['name'] ?? '')); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
