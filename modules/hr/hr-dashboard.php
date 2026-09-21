<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.dashboard.page', ['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();
ensureDatabaseSchema();
$user = getCurrentUser();

$today = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$now = new DateTimeImmutable('now', hrBaseTz());
$year = (int)$now->format('Y');
$month = (int)$now->format('m');
$monthStr = $now->format('Y-m');

$range = function_exists('hrDashboardRange') ? hrDashboardRange($_GET, 'today') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($now->format('Y-m-01') . ' 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($now->format('Y-m-t') . ' 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$uid = (int)($user['id'] ?? 0);
$joinDate = hrAttendanceStartDateForUser($uid);
if ($joinDate !== null && $sd < $joinDate) $sd = $joinDate;
$attendanceFocus = strtolower(trim((string)($_GET['attendance_focus'] ?? 'today')));
if (!in_array($attendanceFocus, ['today', 'range'], true)) $attendanceFocus = 'today';
$attendanceStatusFilter = strtolower(trim((string)($_GET['attendance_status'] ?? 'all')));
$allowedAttendanceStatusFilters = ['all','present','half','late','pending','absent','leave','ncns','holiday','weekoff','inprogress'];
if (!in_array($attendanceStatusFilter, $allowedAttendanceStatusFilters, true)) $attendanceStatusFilter = 'all';
$hrDashboardQueryBase = $_GET;
unset($hrDashboardQueryBase['attendance_focus'], $hrDashboardQueryBase['attendance_status']);
$makeHrDashboardUrl = static function(array $overrides = []) use ($hrDashboardQueryBase): string {
    $query = array_merge($hrDashboardQueryBase, $overrides);
    return '?' . http_build_query($query);
};

$canAttendanceAdmin = userHasAnyPermission(['hr.attendance_admin', 'hr.attendance_admin.page']);
$canAttendanceReview = $canAttendanceAdmin || hasRole(['operations_manager', 'operations_director']);
$canPayroll = userHasAnyPermission(['hr.payroll', 'hr.payroll.page']);
$canShifts = userHasAnyPermission(['hr.shifts', 'hr.shifts.page']);
$canPayslips = userHasAnyPermission(['hr.payslips', 'hr.payslips.page']);
$workDate = $today;
$autoCloseStart = date('Y-m-d', strtotime($today . ' -2 day'));
hrAutoCloseOpenAttendanceForUserRange($uid, $autoCloseStart, $today);
$attendanceSnapshot = hrGetAttendanceSnapshot($uid, $today, true);
$workDate = (string)($attendanceSnapshot['work_date'] ?? $today);
$day = is_array($attendanceSnapshot['row'] ?? null) ? $attendanceSnapshot['row'] : null;
$pIn = (string)($attendanceSnapshot['punch_in'] ?? '');
$pOut = (string)($attendanceSnapshot['punch_out'] ?? '');
$status = (string)($attendanceSnapshot['status'] ?? 'Absent');
$currentState = (string)($attendanceSnapshot['current_state'] ?? 'Off');
$workMin = (int)($attendanceSnapshot['display_work_minutes'] ?? 0);
$breakMin = (int)($attendanceSnapshot['display_break_minutes'] ?? 0);
$lateToday = (int)($attendanceSnapshot['late_minutes'] ?? 0);

$monthSum = getAttendanceMonthSummary((int)($user['id'] ?? 0), $year, $month);
$monthSum = hrDashboardNormalizeSummaryForOpenToday($monthSum, sprintf('%04d-%02d-01', $year, $month), sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year)), $today, $status);
$monthSum = hrNormalizeAttendanceSummaryDisplayMinutesForSnapshot(
    $monthSum,
    $attendanceSnapshot,
    sprintf('%04d-%02d-01', $year, $month),
    sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year))
);
$workingDays = (int)($monthSum['working_days'] ?? 0);
$present = (int)($monthSum['present_days'] ?? 0);
$half = (int)($monthSum['half_days'] ?? 0);
$absent = (int)($monthSum['absent_days'] ?? 0);
$ncnsDays = (int)($monthSum['ncns_days'] ?? 0);
$pendingDays = (int)($monthSum['pending_days'] ?? 0);
$monthLateDays = (int)($monthSum['late_days'] ?? 0);
$monthLateMinutes = (int)($monthSum['late_minutes'] ?? 0);
$paidDays = (float)($monthSum['paid_days'] ?? 0.0);
$monthPct = (float)($monthSum['attendance_percent'] ?? 0.0);

$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts((int)($user['id'] ?? 0)) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];

$rangeAtt = [
    'days' => 0,
    'full' => 0,
    'half' => 0,
    'absent' => 0,
    'ncns' => 0,
    'work_minutes' => 0,
    'break_minutes' => 0,
    'late_minutes' => 0,
];
$conn = getDbConnection();
$rangeSummary = getAttendanceRangeSummary($uid, $sd, $ed);
$rangeSummary = hrDashboardNormalizeSummaryForOpenToday($rangeSummary, $sd, $ed, $today, $status);
$rangeSummary = hrNormalizeAttendanceSummaryDisplayMinutesForSnapshot($rangeSummary, $attendanceSnapshot, $sd, $ed);
$rangeAtt['days'] = (int)($rangeSummary['working_days'] ?? 0) + (int)($rangeSummary['pending_days'] ?? 0);
$rangeAtt['full'] = (int)($rangeSummary['present_days'] ?? 0);
$rangeAtt['half'] = (int)($rangeSummary['half_days'] ?? 0);
$rangeAtt['absent'] = (int)($rangeSummary['absent_days'] ?? 0);
$rangeAtt['ncns'] = (int)($rangeSummary['ncns_days'] ?? 0);
$rangeAtt['pending'] = (int)($rangeSummary['pending_days'] ?? 0);
$rangeAtt['work_minutes'] = (int)($rangeSummary['work_minutes'] ?? 0);
$rangeAtt['break_minutes'] = (int)($rangeSummary['break_minutes'] ?? 0);
$rangeAtt['late_minutes'] = (int)($rangeSummary['late_minutes'] ?? 0);

$attendanceDetailRows = [];
$attendanceTodayRows = [];
$attendanceTodayCounts = [
    'all' => 0,
    'present' => 0,
    'half' => 0,
    'late' => 0,
    'pending' => 0,
    'absent' => 0,
    'leave' => 0,
    'ncns' => 0,
    'holiday' => 0,
    'weekoff' => 0,
    'inprogress' => 0,
];
if ($canAttendanceReview) {
    $stmt = $conn->prepare("
        SELECT
          d.id,
          d.work_date,
          d.user_id,
          u.full_name,
          u.job_title,
          u.role,
          d.punch_in,
          d.punch_out,
          d.break_minutes,
          d.working_minutes,
          d.late_minutes,
          d.current_state,
          d.status
        FROM hr_attendance_days d
        JOIN users u ON u.id = d.user_id
        WHERE d.work_date BETWEEN ? AND ?
          AND d.work_date >= COALESCE(u.date_of_joining, DATE(u.created_at))
          AND u.is_active = 1
          AND u.role NOT LIKE 'client_%'
          AND u.role NOT LIKE 'vendor_%'
          AND (
            (d.punch_in IS NOT NULL AND d.punch_in <> '')
            OR (d.punch_out IS NOT NULL AND d.punch_out <> '')
          )
        ORDER BY d.work_date DESC, u.full_name ASC
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $sd, $ed);
        $stmt->execute();
        $attendanceDetailRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    $attendanceDayIds = array_values(array_map(
        static fn(array $row): int => (int)($row['id'] ?? 0),
        array_filter($attendanceDetailRows, static fn(array $row): bool => (int)($row['id'] ?? 0) > 0)
    ));
    if (!empty($attendanceDayIds)) {
        $placeholders = implode(',', array_fill(0, count($attendanceDayIds), '?'));
        $types = str_repeat('i', count($attendanceDayIds));
        $stmt = $conn->prepare("
            SELECT attendance_day_id, state, start_at, end_at
            FROM hr_attendance_states
            WHERE attendance_day_id IN ($placeholders)
            ORDER BY attendance_day_id ASC, start_at ASC, id ASC
        ");
        if ($stmt) {
            $stmt->bind_param($types, ...$attendanceDayIds);
            $stmt->execute();
            $stateRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();

            $statesByDayId = [];
            foreach ($stateRows as $stateRow) {
                $dayId = (int)($stateRow['attendance_day_id'] ?? 0);
                if ($dayId <= 0) continue;
                if (!isset($statesByDayId[$dayId])) $statesByDayId[$dayId] = [];
                $statesByDayId[$dayId][] = $stateRow;
            }

            foreach ($attendanceDetailRows as &$attendanceDetailRow) {
                $dayId = (int)($attendanceDetailRow['id'] ?? 0);
                $states = $statesByDayId[$dayId] ?? [];
                $attendanceDetailRow['break_timing'] = hrDashboardAttendanceStateSummary($states, (int)($attendanceDetailRow['break_minutes'] ?? 0), $attendanceDetailRow, $now);
                $attendanceDetailRow['display_work_minutes'] = hrDashboardDisplayWorkMinutes($attendanceDetailRow, $states, $now);
            }
            unset($attendanceDetailRow);
        }
    }

    foreach ($attendanceDetailRows as &$attendanceDetailRow) {
        if (!isset($attendanceDetailRow['break_timing'])) {
            $attendanceDetailRow['break_timing'] = (int)($attendanceDetailRow['break_minutes'] ?? 0) > 0
                ? fmtMinutesHrDash((int)($attendanceDetailRow['break_minutes'] ?? 0))
                : '—';
        }
        if (!isset($attendanceDetailRow['display_work_minutes'])) {
            $attendanceDetailRow['display_work_minutes'] = hrDashboardDisplayWorkMinutes($attendanceDetailRow, [], $now);
        }
    }
    unset($attendanceDetailRow);

    $stmt = $conn->prepare("
        SELECT
          d.id AS attendance_day_id,
          ? AS work_date,
          u.id AS user_id,
          u.full_name,
          u.job_title,
          u.role,
          d.punch_in,
          d.punch_out,
          d.break_minutes,
          d.working_minutes,
          d.late_minutes,
          d.current_state,
          d.status,
          COALESCE(d.shift_start_time, s.start_time) AS shift_start_time_full,
          COALESCE(d.shift_end_time, s.end_time) AS shift_end_time_full
        FROM users u
        LEFT JOIN hr_attendance_days d ON d.user_id = u.id AND d.work_date = ?
        LEFT JOIN hr_shifts s ON s.id = d.shift_id
        WHERE u.is_active = 1
          AND u.role NOT LIKE 'client_%'
          AND u.role NOT LIKE 'vendor_%'
          AND COALESCE(u.date_of_joining, DATE(u.created_at)) <= ?
        ORDER BY u.full_name ASC
    ");
    if ($stmt) {
        $stmt->bind_param('sss', $today, $today, $today);
        $stmt->execute();
        $attendanceTodayRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    $attendanceTodayDayIds = array_values(array_map(
        static fn(array $row): int => (int)($row['attendance_day_id'] ?? 0),
        array_filter($attendanceTodayRows, static fn(array $row): bool => (int)($row['attendance_day_id'] ?? 0) > 0)
    ));
    if (!empty($attendanceTodayDayIds)) {
        $placeholders = implode(',', array_fill(0, count($attendanceTodayDayIds), '?'));
        $types = str_repeat('i', count($attendanceTodayDayIds));
        $stmt = $conn->prepare("
            SELECT attendance_day_id, state, start_at, end_at
            FROM hr_attendance_states
            WHERE attendance_day_id IN ($placeholders)
            ORDER BY attendance_day_id ASC, start_at ASC, id ASC
        ");
        if ($stmt) {
            $stmt->bind_param($types, ...$attendanceTodayDayIds);
            $stmt->execute();
            $stateRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            $statesByDayId = [];
            foreach ($stateRows as $stateRow) {
                $dayId = (int)($stateRow['attendance_day_id'] ?? 0);
                if ($dayId <= 0) continue;
                if (!isset($statesByDayId[$dayId])) $statesByDayId[$dayId] = [];
                $statesByDayId[$dayId][] = $stateRow;
            }
            foreach ($attendanceTodayRows as &$attendanceTodayRow) {
                $dayId = (int)($attendanceTodayRow['attendance_day_id'] ?? 0);
                $states = $statesByDayId[$dayId] ?? [];
                $attendanceTodayRow['break_timing'] = hrDashboardAttendanceStateSummary($states, (int)($attendanceTodayRow['break_minutes'] ?? 0), $attendanceTodayRow, $now);
                $attendanceTodayRow['display_work_minutes'] = hrDashboardDisplayWorkMinutes($attendanceTodayRow, $states, $now);
            }
            unset($attendanceTodayRow);
        }
    }

    foreach ($attendanceTodayRows as &$attendanceTodayRow) {
        if (!isset($attendanceTodayRow['break_timing'])) {
            $attendanceTodayRow['break_timing'] = (int)($attendanceTodayRow['break_minutes'] ?? 0) > 0
                ? fmtMinutesHrDash((int)($attendanceTodayRow['break_minutes'] ?? 0))
                : '—';
        }
        if (!isset($attendanceTodayRow['display_work_minutes'])) {
            $attendanceTodayRow['display_work_minutes'] = hrDashboardDisplayWorkMinutes($attendanceTodayRow, [], $now);
        }
        $meta = hrDashboardTodayAttendanceMeta($attendanceTodayRow, $today);
        $attendanceTodayRow['status_display'] = $meta['label'];
        $attendanceTodayRow['status_badge_class'] = $meta['badge_class'];
        $attendanceTodayRow['filter_key'] = $meta['filter_key'];
        $attendanceTodayRow['is_late'] = $meta['is_late'];
        // Normalize visible current-state badges for today's attendance grid.
        if ($meta['label'] === 'Pending') {
            $attendanceTodayRow['current_state'] = 'Off';
        } elseif ($meta['label'] === 'In Progress') {
            $normalizedCurrentState = trim((string)($attendanceTodayRow['current_state'] ?? ''));
            if ($normalizedCurrentState === '' || strcasecmp($normalizedCurrentState, 'Off') === 0) {
                $attendanceTodayRow['current_state'] = 'Working';
            }
        }
        $attendanceTodayCounts['all']++;
        if (isset($attendanceTodayCounts[$meta['filter_key']])) $attendanceTodayCounts[$meta['filter_key']]++;
        if (!empty($meta['is_present_like'])) $attendanceTodayCounts['present']++;
        if (!empty($meta['is_late'])) $attendanceTodayCounts['late']++;
    }
    unset($attendanceTodayRow);

    if ($attendanceFocus === 'today' && $attendanceStatusFilter !== 'all') {
        $attendanceTodayRows = array_values(array_filter($attendanceTodayRows, static function(array $row) use ($attendanceStatusFilter): bool {
            if ($attendanceStatusFilter === 'late') return !empty($row['is_late']);
            if ($attendanceStatusFilter === 'present') return in_array((string)($row['filter_key'] ?? ''), ['present','half','inprogress'], true);
            return (string)($row['filter_key'] ?? '') === $attendanceStatusFilter;
        }));
    }
}

$payrollLocked = hrIsPayrollLocked($year, $month);
$payslip = $payrollLocked ? null : getPayslip((int)($user['id'] ?? 0), $year, $month);
$payslipStatus = $payrollLocked ? 'Hidden While Locked' : ($payslip ? 'Generated' : 'Not Generated');

$upcoming = getUpcomingHolidays($today, 6, 'US');
$holidaysThisMonth = getHolidaysForMonth($year, $month, 'US');

function fmtMinutesHrDash(int $m): string {
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%dh %02dm', $h, $mm);
}

function hrDashboardDisplayWorkMinutes(array $row, array $states = [], ?DateTimeImmutable $now = null): int {
    return hrAttendanceDisplayWorkMinutes($row, $states, $now);
}

function hrDashboardAttendanceStateSummary(array $states, int $fallbackBreakMinutes = 0, array $context = [], ?DateTimeImmutable $now = null): string {
    return hrAttendanceStateSummary($states, $fallbackBreakMinutes, $context, $now);
}

function hrDashboardNormalizeSummaryForOpenToday(array $summary, string $startDate, string $endDate, string $today, string $todayStatus): array {
    return hrNormalizeAttendanceSummaryForOpenDay($summary, $startDate, $endDate, $today, $todayStatus);
}

function hrDashboardAttendanceBadgeClass(string $status): string {
    $status = trim($status);
    return match ($status) {
        'Full Day' => 'bg-success',
        'Half Day' => 'bg-warning text-dark',
        'Absent', 'NCNS' => 'bg-danger',
        'Pending' => 'bg-primary',
        'Holiday', 'Weekly Off', 'Leave Approved' => 'bg-info text-dark',
        'In Progress' => 'bg-secondary',
        'Missing Punch' => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
}

function hrDashboardTodayAttendanceMeta(array $row, string $today): array {
    $meta = hrAttendanceStatusMeta($row, $today, $today, 'US', true);
    $meta['badge_class'] = hrDashboardAttendanceBadgeClass((string)($meta['label'] ?? 'Absent'));
    return $meta;
}
?>
<?php $pageTitle = 'HR Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 hr-dashboard-workspace">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR'],
            ],
            'HR Dashboard',
            'Attendance, payroll, payslips and quick exports',
            [
                ['label' => 'Attendance', 'href' => 'attendance', 'icon' => 'bi-fingerprint', 'class' => 'btn-outline-primary'],
                ['label' => 'Paid Leaves', 'href' => 'paid-leaves', 'icon' => 'bi-calendar2-week', 'class' => 'btn-outline-secondary'],
                $canPayslips ? ['label' => 'Payslips', 'href' => 'payslips', 'icon' => 'bi-receipt', 'class' => 'btn-outline-secondary'] : [],
                $canPayroll ? ['label' => 'Payroll', 'href' => 'payroll', 'icon' => 'bi-calculator', 'class' => 'btn-outline-secondary'] : [],
            ],
            [
                ['text' => $workDate, 'class' => 'bg-light text-dark font-monospace'],
                ['text' => $payrollLocked ? 'Payroll Locked' : 'Payroll Open', 'class' => $payrollLocked ? 'bg-danger' : 'bg-secondary'],
            ]
        );
    ?>

    <form class="row g-2 align-items-end mb-3 shared-dashboard-filter" method="get">
        <div class="col-md-3">
            <label class="form-label small text-muted">Range</label>
            <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
                <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
                <option value="this_month" <?php echo $preset==='this_month'?'selected':''; ?>>This Month</option>
                <option value="overall" <?php echo $preset==='overall'?'selected':''; ?>>Overall</option>
                <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom Range</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Start</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($sd); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">End</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($ed); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </form>

    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-fingerprint me-1"></i> Attendance Today</div>
                                            <span class="badge <?php echo htmlspecialchars(hrDashboardAttendanceBadgeClass($status)); ?>"><?php echo htmlspecialchars($status); ?></span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Punch In</div>
                                                <div class="hr-kpi-value font-monospace"><?php echo $pIn !== '' ? htmlspecialchars(hrFormatAttendanceForDisplay($pIn, 'H:i', $day ?: ['work_date' => $workDate])) : '—'; ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Punch Out</div>
                                                <div class="hr-kpi-value font-monospace"><?php echo $pOut !== '' ? htmlspecialchars(hrFormatAttendanceForDisplay($pOut, 'H:i', $day ?: ['work_date' => $workDate])) : '—'; ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Work</div>
                                                <div class="hr-kpi-value"><?php echo htmlspecialchars(fmtMinutesHrDash($workMin)); ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Break</div>
                                                <div class="hr-kpi-value"><?php echo htmlspecialchars(fmtMinutesHrDash($breakMin)); ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Late</div>
                                                <div class="hr-kpi-value"><?php echo $lateToday > 0 ? (number_format($lateToday) . ' min') : 'On time'; ?></div>
                                            </div>
                                        </div>
                                        <div class="mt-3 d-flex flex-wrap gap-2">
                                            <a class="btn btn-outline-primary btn-sm" href="attendance?date=<?php echo urlencode($workDate); ?>&view=day"><i class="bi bi-arrow-right-circle"></i> My Day</a>
                                            <?php if ($canAttendanceReview): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'all']) . '#attendance-user-list'); ?>"><i class="bi bi-table"></i> Today Detail</a>
                                            <?php endif; ?>
                                            <?php if ($canAttendanceAdmin): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="attendance-admin"><i class="bi bi-people"></i> Admin</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">Current state: <span class="fw-semibold"><?php echo htmlspecialchars($currentState); ?></span></div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-calendar2-week me-1"></i> Attendance This Month</div>
                                            <span class="badge bg-<?php echo $monthPct >= 90 ? 'success' : ($monthPct >= 70 ? 'warning text-dark' : 'secondary'); ?>"><?php echo number_format($monthPct, 1); ?>%</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Working</div>
                                                <div class="hr-kpi-value"><?php echo number_format($workingDays); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Present</div>
                                                <div class="hr-kpi-value text-success"><?php echo number_format($present); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Half</div>
                                                <div class="hr-kpi-value text-warning"><?php echo number_format($half); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Absent</div>
                                                <div class="hr-kpi-value text-danger"><?php echo number_format($absent); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Pending</div>
                                                <div class="hr-kpi-value text-primary"><?php echo number_format($pendingDays); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">NCNS</div>
                                                <div class="hr-kpi-value text-danger"><?php echo number_format($ncnsDays); ?></div>
                                            </div>
                                        </div>
                                        <div class="mt-3 d-flex flex-wrap gap-2">
                                            <a class="btn btn-outline-primary btn-sm" href="attendance?date=<?php echo urlencode($today); ?>&view=month"><i class="bi bi-calendar3"></i> My Month</a>
                                            <?php if ($canAttendanceReview): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'this_month', 'attendance_focus' => 'range', 'attendance_status' => 'all']) . '#attendance-user-list'); ?>"><i class="bi bi-table"></i> Detail</a>
                                            <?php endif; ?>
                                            <?php if ($canAttendanceAdmin): ?>
                                                <a class="btn btn-outline-primary btn-sm" href="attendance-monthly-report?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-graph-up"></i> Report</a>
                                                <a class="btn btn-outline-secondary btn-sm" href="attendance-monthly-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> CSV</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">Paid days: <span class="fw-semibold"><?php echo number_format($paidDays, 1); ?></span> · Late: <span class="fw-semibold"><?php echo number_format($monthLateMinutes); ?></span> min (<?php echo number_format($monthLateDays); ?> days)</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-activity me-1"></i> Attendance (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
                                            <span class="badge bg-secondary"><?php echo number_format((int)$rangeAtt['days']); ?> days</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Full</div>
                                                <div class="hr-kpi-value text-success"><?php echo number_format((int)$rangeAtt['full']); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Half</div>
                                                <div class="hr-kpi-value text-warning"><?php echo number_format((int)$rangeAtt['half']); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Absent</div>
                                                <div class="hr-kpi-value text-danger"><?php echo number_format((int)$rangeAtt['absent']); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">Pending</div>
                                                <div class="hr-kpi-value text-primary"><?php echo number_format((int)($rangeAtt['pending'] ?? 0)); ?></div>
                                            </div>
                                            <div class="col-4 col-lg-2">
                                                <div class="hr-kpi-label">NCNS</div>
                                                <div class="hr-kpi-value text-danger"><?php echo number_format((int)$rangeAtt['ncns']); ?></div>
                                            </div>
                                            <div class="col-6 col-lg-3">
                                                <div class="hr-kpi-label">Work</div>
                                                <div class="hr-kpi-value"><?php echo htmlspecialchars(fmtMinutesHrDash((int)$rangeAtt['work_minutes'])); ?></div>
                                            </div>
                                            <div class="col-6 col-lg-3">
                                                <div class="hr-kpi-label">Late</div>
                                                <div class="hr-kpi-value"><?php echo (int)$rangeAtt['late_minutes'] > 0 ? (number_format((int)$rangeAtt['late_minutes']) . ' min') : 'On time'; ?></div>
                                            </div>
                                        </div>
                                        <div class="mt-3 d-flex flex-wrap gap-2">
                                            <a class="btn btn-outline-primary btn-sm" href="attendance?date=<?php echo urlencode($ed); ?>&view=<?php echo $preset === 'today' ? 'day' : ($preset === 'custom' ? 'month' : 'month'); ?>"><i class="bi bi-clock-history"></i> My Attendance</a>
                                            <?php if ($canAttendanceReview): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['attendance_focus' => 'range', 'attendance_status' => 'all']) . '#attendance-user-list'); ?>"><i class="bi bi-table"></i> Detail</a>
                                            <?php endif; ?>
                                            <a class="btn btn-outline-secondary btn-sm" href="attendance-monthly-report?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-graph-up"></i> Monthly Report</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-receipt me-1"></i> Payslip</div>
                                            <span class="badge bg-<?php echo $payrollLocked ? 'warning text-dark' : ($payslip ? 'success' : 'secondary'); ?>"><?php echo htmlspecialchars($payslipStatus); ?></span>
                                        </div>
                                        <div class="hr-kpi-label">Month</div>
                                        <div class="fw-semibold font-monospace"><?php echo htmlspecialchars($monthStr); ?></div>
                                        <div class="mt-3 d-flex flex-wrap gap-2">
                                            <?php if ($canPayslips): ?>
                                                <a class="btn btn-outline-primary btn-sm" href="payslips"><i class="bi bi-arrow-right-circle"></i> View Payslips</a>
                                            <?php endif; ?>
                                            <?php if ($payslip && !$payrollLocked): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="payslip-view?month=<?php echo urlencode($monthStr); ?>&user_id=<?php echo (int)($user['id'] ?? 0); ?>"><i class="bi bi-eye"></i> Open</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">Payroll status: <span class="fw-semibold"><?php echo $payrollLocked ? 'Locked' : 'Open'; ?></span><?php if ($payrollLocked): ?> · Payslips are hidden until unlocked<?php endif; ?></div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-cash-stack me-1"></i> Quick Links</div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>"><i class="bi bi-kanban"></i> Tasks</a>
                                            <?php if ($canPayroll): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="salary-setup"><i class="bi bi-wallet2"></i> Salary Setup</a>
                                                <a class="btn btn-outline-secondary btn-sm" href="bonus-loans"><i class="bi bi-gift"></i> Bonus & Loans</a>
                                                <a class="btn btn-outline-danger btn-sm" href="payroll"><i class="bi bi-calculator"></i> Payroll</a>
                                                <a class="btn btn-outline-secondary btn-sm" href="payroll-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> Payroll CSV</a>
                                            <?php endif; ?>
                                            <?php if ($canShifts): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="shifts"><i class="bi bi-clock-history"></i> Shifts</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">US holidays this month: <span class="fw-semibold"><?php echo number_format(count($holidaysThisMonth)); ?></span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card hr-card mb-3">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-kanban me-1"></i> My Tasks</span>
                                    <span class="badge bg-secondary"><?php echo number_format((int)$taskWidget['pending']); ?></span>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <div class="text-muted small">Pending</div>
                                            <div class="fw-semibold"><?php echo number_format((int)$taskWidget['pending']); ?></div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Due Today</div>
                                            <div class="fw-semibold"><?php echo number_format((int)$taskWidget['due_today']); ?></div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Overdue</div>
                                            <div class="fw-semibold text-danger"><?php echo number_format((int)$taskWidget['overdue']); ?></div>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>"><i class="bi bi-arrow-right-circle"></i> Open Tasks</a>
                                    </div>
                                </div>
                            </div>
                            <div class="card hr-card mb-3">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-calendar-event me-1"></i> Upcoming Holidays</span>
                                    <span class="badge bg-secondary"><?php echo number_format(count($upcoming)); ?></span>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($upcoming)): ?>
                                        <div class="text-muted">No upcoming holidays.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0 hr-table">
                                                <tbody>
                                                    <?php foreach ($upcoming as $h): ?>
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
                            <?php if ($canAttendanceAdmin || $canPayroll): ?>
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-lightning-charge me-1"></i> Quick Exports</div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <?php if ($canAttendanceAdmin): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="attendance-export?date=<?php echo urlencode($today); ?>"><i class="bi bi-download"></i> Attendance (Today)</a>
                                            <a class="btn btn-outline-primary btn-sm" href="attendance-monthly-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> Attendance (Month)</a>
                                        <?php endif; ?>
                                        <?php if ($canPayroll): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="payroll-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> Payroll Summary</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php if ($canAttendanceReview): ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card hr-card" id="attendance-user-list">
                <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold"><i class="bi bi-table me-1"></i>Attendance User List</div>
                        <div class="text-muted small">
                            <?php echo $attendanceFocus === 'today' ? 'Today view shows full internal-user attendance with pending, leave, holiday, late, and absent statuses.' : 'Range view shows punch activity records in the selected range.'; ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Attendance detail focus">
                            <a class="btn <?php echo $attendanceFocus === 'today' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'all']) . '#attendance-user-list'); ?>">Today</a>
                            <a class="btn <?php echo $attendanceFocus === 'range' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['attendance_focus' => 'range', 'attendance_status' => 'all']) . '#attendance-user-list'); ?>">Selected Range</a>
                        </div>
                        <?php if ($attendanceFocus === 'today'): ?>
                            <span class="badge bg-secondary"><?php echo number_format(count($attendanceTodayRows)); ?> rows</span>
                            <span class="text-muted small"><?php echo htmlspecialchars($today); ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?php echo number_format(count($attendanceDetailRows)); ?> rows</span>
                            <span class="text-muted small"><?php echo htmlspecialchars($sd); ?> → <?php echo htmlspecialchars($ed); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($attendanceFocus === 'today'): ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <a class="btn btn-sm <?php echo $attendanceStatusFilter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'all']) . '#attendance-user-list'); ?>">All <?php echo number_format((int)$attendanceTodayCounts['all']); ?></a>
                            <a class="btn btn-sm <?php echo $attendanceStatusFilter === 'present' ? 'btn-success' : 'btn-outline-success'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'present']) . '#attendance-user-list'); ?>">Present <?php echo number_format((int)$attendanceTodayCounts['present']); ?></a>
                            <a class="btn btn-sm <?php echo $attendanceStatusFilter === 'late' ? 'btn-warning text-dark' : 'btn-outline-warning'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'late']) . '#attendance-user-list'); ?>">Late <?php echo number_format((int)$attendanceTodayCounts['late']); ?></a>
                            <a class="btn btn-sm <?php echo $attendanceStatusFilter === 'pending' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'pending']) . '#attendance-user-list'); ?>">Pending <?php echo number_format((int)$attendanceTodayCounts['pending']); ?></a>
                            <a class="btn btn-sm <?php echo $attendanceStatusFilter === 'absent' ? 'btn-danger' : 'btn-outline-danger'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'absent']) . '#attendance-user-list'); ?>">Absent <?php echo number_format((int)$attendanceTodayCounts['absent']); ?></a>
                            <a class="btn btn-sm <?php echo $attendanceStatusFilter === 'leave' ? 'btn-info text-dark' : 'btn-outline-info'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'leave']) . '#attendance-user-list'); ?>">Leave <?php echo number_format((int)$attendanceTodayCounts['leave']); ?></a>
                            <a class="btn btn-sm <?php echo $attendanceStatusFilter === 'ncns' ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo htmlspecialchars($makeHrDashboardUrl(['range_preset' => 'today', 'attendance_focus' => 'today', 'attendance_status' => 'ncns']) . '#attendance-user-list'); ?>">NCNS <?php echo number_format((int)$attendanceTodayCounts['ncns']); ?></a>
                        </div>
                    <?php endif; ?>
                    <?php if ($attendanceFocus === 'today' && empty($attendanceTodayRows)): ?>
                        <div class="text-muted">No attendance rows match the selected today filter.</div>
                    <?php elseif ($attendanceFocus === 'range' && empty($attendanceDetailRows)): ?>
                        <div class="text-muted">No users have clocked in or out in the selected range.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle hr-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Name</th>
                                        <th>Break Timing</th>
                                        <th>Hours Worked</th>
                                        <th>Punch In</th>
                                        <th>Punch Out</th>
                                        <th>Late</th>
                                        <th>Attendance</th>
                                        <th>Current Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($attendanceFocus === 'today' ? $attendanceTodayRows : $attendanceDetailRows) as $row): ?>
                                        <?php
                                            $rowName = (string)($row['full_name'] ?? '');
                                            $rowRole = (string)($row['role'] ?? '');
                                            $rowJobTitle = (string)($row['job_title'] ?? '');
                                            $rowState = (string)($row['current_state'] ?? 'Working');
                                            $rowStatus = $attendanceFocus === 'today'
                                                ? (string)($row['status_display'] ?? 'Absent')
                                                : (string)($row['status'] ?? 'Absent');
                                            if ($rowStatus === 'In Progress') {
                                                $normalizedRowState = trim($rowState);
                                                if ($normalizedRowState === '' || strcasecmp($normalizedRowState, 'Off') === 0) {
                                                    $rowState = 'Working';
                                                }
                                            }
                                            $rowStatusBadge = $attendanceFocus === 'today'
                                                ? (string)($row['status_badge_class'] ?? hrDashboardAttendanceBadgeClass($rowStatus))
                                                : hrDashboardAttendanceBadgeClass($rowStatus);
                                            $rowPunchIn = (string)($row['punch_in'] ?? '');
                                            $rowPunchOut = (string)($row['punch_out'] ?? '');
                                            $rowLate = (int)($row['late_minutes'] ?? 0);
                                        ?>
                                        <tr>
                                            <td class="font-monospace"><?php echo htmlspecialchars((string)($row['work_date'] ?? ($attendanceFocus === 'today' ? $today : ''))); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($rowName); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars(getUserDisplayTitle($rowRole, $rowJobTitle)); ?></div>
                                            </td>
                                            <td class="small text-muted"><?php echo htmlspecialchars((string)($row['break_timing'] ?? '—')); ?></td>
                                            <td><?php echo htmlspecialchars(fmtMinutesHrDash((int)($row['display_work_minutes'] ?? ($row['working_minutes'] ?? 0)))); ?></td>
                                            <td class="font-monospace"><?php echo $rowPunchIn !== '' ? htmlspecialchars(hrFormatAttendanceForDisplay($rowPunchIn, 'H:i', $row)) : '—'; ?></td>
                                            <td class="font-monospace"><?php echo $rowPunchOut !== '' ? htmlspecialchars(hrFormatAttendanceForDisplay($rowPunchOut, 'H:i', $row)) : '—'; ?></td>
                                            <td><?php echo $rowLate > 0 ? htmlspecialchars(number_format($rowLate) . ' min') : '—'; ?></td>
                                            <td><span class="badge <?php echo htmlspecialchars($rowStatusBadge); ?>"><?php echo htmlspecialchars($rowStatus); ?></span></td>
                                            <td>
                                                <span class="badge bg-<?php echo in_array($rowState, ['Break1','Break2','Lunch'], true) ? 'warning text-dark' : ($rowState === 'Meeting' ? 'info text-dark' : (in_array($rowState, ['Working', 'In Progress'], true) ? 'success' : 'secondary')); ?>">
                                                    <?php echo htmlspecialchars(hrAttendanceStateDisplayLabel($rowState)); ?>
                                                </span>
                                            </td>
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
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
