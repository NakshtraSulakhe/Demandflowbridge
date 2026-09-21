<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.attendance_admin.page', ['admin', 'director', 'manager_director']);
ensureCsrfToken();
$user = getCurrentUser();

$todayIst = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$date = isset($_GET['date']) ? (string)$_GET['date'] : $todayIst;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $todayIst;

$view = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'month';
if (!in_array($view, ['month','week','day'], true)) $view = 'month';

$message = '';
$messageType = 'success';

function fmtMinutesHHMM(int $m): string {
    $m = max(0, (int)$m);
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%02d:%02d', $h, $mm);
}

function adminRangeFromView(string $view, string $date): array {
    $start = $date;
    $end = $date;
    if ($view === 'month') {
        $start = substr($date, 0, 7) . '-01';
        $end = date('Y-m-t', strtotime($start));
    } elseif ($view === 'week') {
        $ts = strtotime($date . ' 00:00:00');
        $n = (int)date('N', $ts);
        $start = date('Y-m-d', strtotime($date . ' -' . ($n - 1) . ' day'));
        $end = date('Y-m-d', strtotime($start . ' +6 day'));
    }
    return [$start, $end];
}

function adminUserListRange(string $preset, string $anchorDate, string $today, string $fromDate, string $toDate): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorDate)) $anchorDate = $today;
    $allowed = ['today', 'week', 'month', 'custom'];
    if (!in_array($preset, $allowed, true)) $preset = 'today';

    $start = $anchorDate;
    $end = $anchorDate;
    $label = 'Today';

    if ($preset === 'week') {
        $ts = strtotime($anchorDate . ' 00:00:00');
        $n = (int)date('N', $ts);
        $start = date('Y-m-d', strtotime($anchorDate . ' -' . ($n - 1) . ' day'));
        $end = date('Y-m-d', strtotime($start . ' +6 day'));
        $label = 'This Week';
    } elseif ($preset === 'month') {
        $start = substr($anchorDate, 0, 7) . '-01';
        $end = date('Y-m-t', strtotime($start));
        $label = 'This Month';
    } elseif ($preset === 'custom') {
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) ? $fromDate : $anchorDate;
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) ? $toDate : $start;
        if ($end < $start) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }
        $label = 'Custom Range';
    }

    return [
        'preset' => $preset,
        'start' => $start,
        'end' => $end,
        'label' => $label,
        'single_day' => $start === $end,
    ];
}

function adminAttendanceSummaryBadgeClass(string $status): string {
    $status = trim($status);
    if ($status === 'Present') return 'bg-success-subtle text-success border border-success-subtle';
    if ($status === 'Late') return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    if ($status === 'Leave') return 'bg-info-subtle text-info-emphasis border border-info-subtle';
    if ($status === 'Absent') return 'bg-danger-subtle text-danger border border-danger-subtle';
    if ($status === 'NCNS') return 'bg-danger text-white';
    if ($status === 'Pending') return 'bg-primary-subtle text-primary border border-primary-subtle';
    if ($status === 'Holiday' || $status === 'Weekly Off') return 'bg-info-subtle text-info-emphasis border border-info-subtle';
    if ($status === 'Half Day') return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    if ($status === 'In Progress') return 'bg-secondary-subtle text-secondary border border-secondary-subtle';
    if ($status === 'Missing Punch') return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    return 'bg-secondary-subtle text-secondary border border-secondary-subtle';
}

function attendanceAdminDayStatusMeta(array $row, string $workDate, string $today): array {
    return hrAttendanceStatusMeta($row, $workDate, $today, 'US', true);
}

function attendanceAdminMatchesDailyStatus(string $statusFilter, array $meta): bool {
    $statusFilter = strtolower(trim($statusFilter));
    if ($statusFilter === '' || $statusFilter === 'all') return true;
    return match ($statusFilter) {
        'present' => !empty($meta['is_present_like']),
        'late' => !empty($meta['is_late']),
        'leave' => (string)($meta['filter_key'] ?? '') === 'leave',
        'absent' => (string)($meta['filter_key'] ?? '') === 'absent',
        'pending' => (string)($meta['filter_key'] ?? '') === 'pending',
        'ncns' => (string)($meta['filter_key'] ?? '') === 'ncns',
        'holiday' => (string)($meta['filter_key'] ?? '') === 'holiday',
        'weekly off', 'weekoff' => (string)($meta['filter_key'] ?? '') === 'weekoff',
        'half day', 'half' => (string)($meta['filter_key'] ?? '') === 'half',
        'in progress', 'inprogress' => (string)($meta['filter_key'] ?? '') === 'inprogress',
        default => strtolower((string)($meta['primary_status'] ?? '')) === $statusFilter,
    };
}

function hrAttendanceAdminStatusOptions(): array {
    return [
        ['value' => 'status:Full Day', 'label' => 'Full Day'],
        ['value' => 'status:Half Day', 'label' => 'Half Day'],
        ['value' => 'status:Absent', 'label' => 'Absent'],
        ['value' => 'status:Paid Leave', 'label' => 'Paid Leave'],
        ['value' => 'status:Unpaid Leave', 'label' => 'Unpaid Leave'],
        ['value' => 'status:NCNS', 'label' => 'NCNS'],
        ['value' => 'status:In Progress', 'label' => 'In Progress'],
        ['value' => 'state:Working', 'label' => 'Working'],
        ['value' => 'state:Break1', 'label' => 'Tea Break 1'],
        ['value' => 'state:Break2', 'label' => 'Tea Break 2'],
        ['value' => 'state:Lunch', 'label' => 'Dinner'],
        ['value' => 'state:Meeting', 'label' => 'Meeting'],
    ];
}

function hrAttendanceAdminParseSelection(string $selection): array {
    $selection = trim($selection);
    $dayStatuses = ['Full Day','Half Day','Absent','Paid Leave','Unpaid Leave','NCNS'];
    if ($selection === '') return ['ok' => false];

    $kind = 'status';
    $value = $selection;
    if (strpos($selection, ':') !== false) {
        [$kind, $value] = explode(':', $selection, 2);
        $kind = strtolower(trim((string)$kind));
        $value = trim((string)$value);
    }

    if (($kind === 'status' || $kind === '') && in_array($value, $dayStatuses, true)) {
        return ['ok' => true, 'type' => 'status', 'status' => $value, 'current_state' => 'Off', 'selection' => 'status:' . $value];
    }

    if (($kind === 'status' || $kind === '') && strcasecmp($value, 'In Progress') === 0) {
        return ['ok' => true, 'type' => 'progress', 'status' => 'In Progress', 'current_state' => 'Working', 'selection' => 'status:In Progress'];
    }

    $state = hrNormalizeAttendanceStateKey($value);
    if (($kind === 'state' || $kind === '') && in_array($state, ['Working','Break1','Break2','Lunch','Meeting'], true)) {
        return ['ok' => true, 'type' => 'state', 'status' => 'In Progress', 'current_state' => $state, 'selection' => 'state:' . $state];
    }

    return ['ok' => false];
}

function hrAttendanceAdminCurrentSelection(string $status, string $currentState): string {
    $status = trim($status);
    $currentState = hrNormalizeAttendanceStateKey($currentState);
    if ($status === 'In Progress') {
        if (in_array($currentState, ['Break1','Break2','Lunch','Meeting'], true)) {
            return 'state:' . $currentState;
        }
        return 'status:In Progress';
    }
    return 'status:' . ($status !== '' ? $status : 'Absent');
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = strtolower(trim((string)$_GET['ajax']));

    if ($ajax === 'cleanup_prejoin_preview') {
        ensureDatabaseSchema();
        $conn = getDbConnection();

        $totDays = 0;
        $totUsers = 0;
        $totStates = 0;
        $totAudits = 0;
        $top = [];

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM hr_attendance_days d
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
        ");
        if ($stmt) {
            $stmt->execute();
            $totDays = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT d.user_id) AS c
            FROM hr_attendance_days d
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
        ");
        if ($stmt) {
            $stmt->execute();
            $totUsers = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM hr_attendance_states s
            JOIN hr_attendance_days d ON d.id = s.attendance_day_id
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
        ");
        if ($stmt) {
            $stmt->execute();
            $totStates = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM hr_attendance_audit_logs a
            JOIN hr_attendance_days d ON d.id = a.attendance_day_id
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
        ");
        if ($stmt) {
            $stmt->execute();
            $totAudits = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare("
            SELECT
                d.user_id,
                u.full_name,
                COALESCE(u.date_of_joining, DATE(u.created_at)) AS join_date,
                COUNT(*) AS rows_count,
                MIN(d.work_date) AS first_date,
                MAX(d.work_date) AS last_date
            FROM hr_attendance_days d
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
            GROUP BY d.user_id, u.full_name, join_date
            ORDER BY rows_count DESC, u.full_name ASC
            LIMIT 50
        ");
        if ($stmt) {
            $stmt->execute();
            $top = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }

        echo json_encode([
            'ok' => true,
            'days' => $totDays,
            'users' => $totUsers,
            'states' => $totStates,
            'audits' => $totAudits,
            'top' => $top,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($ajax === 'team_calendar') {
        $v = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'month';
        if (!in_array($v, ['month','week','day'], true)) $v = 'month';
        $d = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : $todayIst;
        [$start, $end] = adminRangeFromView($v, $d);
        $holidaysMap = hrGetHolidayMapForRange($start, $end, 'US');
        $out = [];
        $cur = $start;
        while ($cur <= $end) {
            $rows = getAttendanceDashboard($cur);
            $totalEmployees = is_array($rows) ? count($rows) : 0;
            $full = 0; $half = 0; $abs = 0; $pl = 0; $ul = 0; $ncns = 0; $inProg = 0; $late = 0; $missing = 0; $ot = 0; $pending = 0; $holiday = 0; $weekoff = 0;
            foreach ($rows as $r) {
                $st = (string)($r['status'] ?? 'Absent');
                $meta = attendanceAdminDayStatusMeta($r, $cur, $todayIst);
                if (($meta['filter_key'] ?? '') === 'holiday') $holiday++;
                elseif (($meta['filter_key'] ?? '') === 'weekoff') $weekoff++;
                elseif (($meta['filter_key'] ?? '') === 'pending') $pending++;
                elseif (($meta['filter_key'] ?? '') === 'leave') {
                    if ($st === 'Paid Leave' || $st === 'paid_leave') $pl++;
                    else $ul++;
                } elseif (($meta['filter_key'] ?? '') === 'ncns') $ncns++;
                elseif (($meta['filter_key'] ?? '') === 'missing') $missing++;
                elseif (($meta['filter_key'] ?? '') === 'half') $half++;
                elseif (($meta['filter_key'] ?? '') === 'inprogress') $inProg++;
                elseif (($meta['filter_key'] ?? '') === 'present') $full++;
                else $abs++;

                if (!empty($meta['is_late'])) $late++;

                $workMin = (int)($r['working_minutes'] ?? 0);
                if ($workMin > 465) $ot++;
            }

            $holidayName = $holidaysMap[$cur] ?? '';
            $isHoliday = $holidayName !== '';
            $isWeekOff = !$isHoliday && hrIsWeekend($cur);
            $label = $isHoliday ? ($holidayName !== '' ? $holidayName : 'Holiday') : ($isWeekOff ? 'Weekly Off' : 'Working Day');
            $color = $isHoliday ? 'holiday' : ($isWeekOff ? 'weekoff' : ($missing > 0 ? 'missing' : ($ncns > 0 ? 'ncns' : ($abs > 0 ? 'absent' : ($pending > 0 ? 'pending' : ($late > 0 ? 'late' : 'present'))))));

            $out[] = [
                'date' => $cur,
                'label' => $label,
                'color' => $color,
                'holiday_name' => $holidayName,
                'total' => $totalEmployees,
                'full' => $full,
                'half' => $half,
                'absent' => $abs,
                'paid_leave' => $pl,
                'unpaid_leave' => $ul,
                'ncns' => $ncns,
                'in_progress' => $inProg,
                'late' => $late,
                'missing' => $missing,
                'overtime' => $ot,
                'pending' => $pending,
                'holiday' => $holiday,
                'weekoff' => $weekoff,
            ];
            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }
        echo json_encode(['ok' => true, 'view' => $v, 'start' => $start, 'end' => $end, 'days' => $out], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($ajax === 'team_day_list') {
        $d = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : $todayIst;
        $rows = getAttendanceDashboard($d);
        $out = [];
        foreach ($rows as $r) {
            $pIn = (string)($r['punch_in'] ?? '');
            $pOut = (string)($r['punch_out'] ?? '');
            $meta = attendanceAdminDayStatusMeta($r, $d, $todayIst);
            $out[] = [
                'user_id' => (int)($r['user_id'] ?? 0),
                'full_name' => (string)($r['full_name'] ?? ''),
                'job_title' => (string)($r['job_title'] ?? ''),
                'shift_start_time' => (string)($r['shift_start_time'] ?? ''),
                'grace_minutes' => (int)($r['grace_minutes'] ?? 0),
                'punch_in' => $pIn !== '' ? hrFormatAttendanceForDisplay($pIn, 'H:i', $r + ['work_date' => $d]) : '',
                'punch_out' => $pOut !== '' ? hrFormatAttendanceForDisplay($pOut, 'H:i', $r + ['work_date' => $d]) : '',
                'state' => hrAttendanceStateDisplayLabel((string)($r['current_state'] ?? 'Off')),
                'break_min' => (int)($r['break_minutes'] ?? 0),
                'work_hhmm' => fmtMinutesHHMM((int)($r['working_minutes'] ?? 0)),
                'late_min' => (int)($r['late_minutes'] ?? 0),
                'status' => (string)($meta['label'] ?? 'Absent'),
                'status_key' => (string)($meta['filter_key'] ?? 'absent'),
            ];
        }
        echo json_encode(['ok' => true, 'date' => $d, 'rows' => $out], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($ajax === 'employee_calendar') {
        $empId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        if ($empId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }
        $v = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'month';
        if (!in_array($v, ['month','week','day'], true)) $v = 'month';
        $d = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : $todayIst;
        [$start, $end] = adminRangeFromView($v, $d);
        $joinDate = hrAttendanceStartDateForUser($empId);
        if ($joinDate !== null && $end < $joinDate) {
            echo json_encode(['ok' => true, 'view' => $v, 'start' => $start, 'end' => $end, 'days' => []], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $rows = getAttendanceDaysForUserRange($empId, $start, $end);
        $byDate = [];
        foreach ($rows as $r) {
            $wd = (string)($r['work_date'] ?? '');
            if ($wd !== '') $byDate[$wd] = $r;
        }
        $holidaysMap = hrGetHolidayMapForRange($start, $end, 'US');
        $out = [];
        $cur = $start;
        while ($cur <= $end) {
            if ($joinDate !== null && $cur < $joinDate) {
                $out[] = [
                    'date' => $cur,
                    'status' => 'Not Joined',
                    'status_raw' => '',
                    'color' => 'na',
                    'punch_in' => '',
                    'punch_out' => '',
                    'work_hhmm' => '00:00',
                    'shift' => '',
                    'late_min' => 0,
                    'overtime_min' => 0,
                    'holiday_name' => '',
                    'is_holiday' => false,
                    'is_weekoff' => false,
                ];
                $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
                continue;
            }
            $r = $byDate[$cur] ?? null;
            $pIn = $r ? (string)($r['punch_in'] ?? '') : '';
            $pOut = $r ? (string)($r['punch_out'] ?? '') : '';
            $status = $r ? (string)($r['status'] ?? 'Absent') : 'Absent';
            $lateMin = $r ? (int)($r['late_minutes'] ?? 0) : 0;
            $workMin = $r ? (int)($r['working_minutes'] ?? 0) : 0;
            $shiftName = $r ? (string)($r['shift_name'] ?? '') : '';
            $meta = attendanceAdminDayStatusMeta($r ?: [], $cur, $todayIst);
            $statusLabel = (string)($meta['label'] ?? $status);
            $colorKey = match ((string)($meta['filter_key'] ?? 'absent')) {
                'holiday' => 'holiday',
                'weekoff' => 'weekoff',
                'pending' => 'pending',
                'leave' => 'leave',
                'missing' => 'missing',
                'ncns' => 'ncns',
                'half' => 'half',
                default => !empty($meta['is_late']) ? 'late' : (($meta['filter_key'] ?? '') === 'present' || ($meta['filter_key'] ?? '') === 'inprogress' ? 'present' : 'absent'),
            };
            $holidayName = hrHolidayNameForDate($cur, 'US');
            $isHoliday = $holidayName !== '';
            $isWeekend = !$isHoliday && hrIsWeekend($cur);

            $out[] = [
                'date' => $cur,
                'status' => $statusLabel,
                'status_raw' => $status,
                'color' => $colorKey,
                'punch_in' => $pIn !== '' ? hrFormatAttendanceForDisplay($pIn, 'H:i', $r ?: ['work_date' => $cur]) : '',
                'punch_out' => $pOut !== '' ? hrFormatAttendanceForDisplay($pOut, 'H:i', $r ?: ['work_date' => $cur]) : '',
                'work_hhmm' => fmtMinutesHHMM($workMin),
                'shift' => $shiftName,
                'late_min' => $lateMin,
                'overtime_min' => max(0, $workMin - 465),
                'holiday_name' => $holidayName,
                'is_holiday' => $isHoliday,
                'is_weekoff' => $isWeekend,
            ];
            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }
        echo json_encode(['ok' => true, 'view' => $v, 'start' => $start, 'end' => $end, 'days' => $out], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($ajax === 'admin_detail') {
        $empId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        $d = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : $todayIst;
        if ($empId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }
        $joinDate = hrAttendanceStartDateForUser($empId);
        if ($joinDate !== null && $d < $joinDate) {
            echo json_encode([
                'ok' => true,
                'day_id' => 0,
                'date' => $d,
                'shift' => ['name' => '', 'start' => '', 'end' => ''],
                'attendance' => [
                    'status' => 'Not Joined',
                    'punch_in' => '',
                    'punch_out' => '',
                    'work_hhmm' => '00:00',
                    'break_hhmm' => '00:00',
                    'overtime_hhmm' => '00:00',
                    'late_min' => 0,
                ],
                'timeline' => [],
                'audit' => [],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $row = getAttendanceDayWithShift($empId, $d);
        $dayId = $row ? (int)($row['id'] ?? 0) : 0;
        $states = $dayId > 0 ? getAttendanceStatesForDay($dayId) : [];
        $audit = $dayId > 0 ? getAttendanceAuditForDay($dayId) : [];
        $pIn = $row ? (string)($row['punch_in'] ?? '') : '';
        $pOut = $row ? (string)($row['punch_out'] ?? '') : '';
        $workMin = $row ? (int)($row['working_minutes'] ?? 0) : 0;
        $breakMin = $row ? (int)($row['break_minutes'] ?? 0) : 0;
        $lateMin = $row ? (int)($row['late_minutes'] ?? 0) : 0;
        $rawStatus = $row ? (string)($row['status'] ?? 'Absent') : 'Absent';
        $status = $rawStatus;
        $meta = attendanceAdminDayStatusMeta($row ?: [], $d, $todayIst);
        $status = (string)($meta['label'] ?? $status);
        $shiftName = $row ? (string)($row['shift_name'] ?? '') : '';
        $shiftStart = $row ? substr((string)($row['shift_start_time_full'] ?? ''), 0, 5) : '';
        $shiftEnd = $row ? substr((string)($row['shift_end_time_full'] ?? ''), 0, 5) : '';
        $timeline = [];
        if ($pIn !== '') $timeline[] = ['at' => hrFormatAttendanceForDisplay($pIn, 'Y-m-d H:i', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]), 'label' => 'Punch In Recorded'];
        foreach ($states as $s) {
            $st = (string)($s['state'] ?? '');
            $sa = (string)($s['start_at'] ?? '');
            $ea = (string)($s['end_at'] ?? '');
            $stLabel = hrAttendanceStateDisplayLabel($st);
            if ($st !== '' && $sa !== '') $timeline[] = ['at' => hrFormatAttendanceForDisplay($sa, 'Y-m-d H:i', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]), 'label' => $stLabel . ' Started'];
            if ($st !== '' && $ea !== '') $timeline[] = ['at' => hrFormatAttendanceForDisplay($ea, 'Y-m-d H:i', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]), 'label' => $stLabel . ' Ended'];
        }
        if ($pOut !== '') $timeline[] = ['at' => hrFormatAttendanceForDisplay($pOut, 'Y-m-d H:i', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]), 'label' => 'Punch Out Recorded'];
        echo json_encode([
            'ok' => true,
            'day_id' => $dayId,
            'date' => $d,
            'shift' => ['name' => $shiftName, 'start' => $shiftStart, 'end' => $shiftEnd],
            'attendance' => [
                'status' => $status,
                'current_state' => (string)($row['current_state'] ?? 'Off'),
                'current_state_label' => hrAttendanceStateDisplayLabel((string)($row['current_state'] ?? 'Off')),
                'edit_selection' => hrAttendanceAdminCurrentSelection($rawStatus, (string)($row['current_state'] ?? 'Off')),
                'punch_in' => $pIn !== '' ? hrFormatAttendanceForDisplay($pIn, 'Y-m-d H:i:s', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]) : '',
                'punch_out' => $pOut !== '' ? hrFormatAttendanceForDisplay($pOut, 'Y-m-d H:i:s', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]) : '',
                'work_hhmm' => fmtMinutesHHMM($workMin),
                'break_hhmm' => fmtMinutesHHMM($breakMin),
                'overtime_hhmm' => fmtMinutesHHMM(max(0, $workMin - 465)),
                'late_min' => $lateMin,
            ],
            'timeline' => $timeline,
            'audit' => $audit,
            'csrf' => (string)($_SESSION['csrf_token'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown request']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cleanup_prejoin_delete') {
    header('Content-Type: application/json; charset=utf-8');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) { echo json_encode(['ok' => false, 'error' => 'Invalid security token']); exit; }

    ensureDatabaseSchema();
    $conn = getDbConnection();

    $deletedStates = 0;
    $deletedAudits = 0;
    $deletedDays = 0;

    $conn->begin_transaction();
    try {
        $sql1 = "
            DELETE s
            FROM hr_attendance_states s
            JOIN hr_attendance_days d ON d.id = s.attendance_day_id
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
        ";
        $conn->query($sql1);
        $deletedStates = (int)$conn->affected_rows;

        $sql2 = "
            DELETE a
            FROM hr_attendance_audit_logs a
            JOIN hr_attendance_days d ON d.id = a.attendance_day_id
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
        ";
        $conn->query($sql2);
        $deletedAudits = (int)$conn->affected_rows;

        $sql3 = "
            DELETE d
            FROM hr_attendance_days d
            JOIN users u ON u.id = d.user_id
            WHERE d.work_date < COALESCE(u.date_of_joining, DATE(u.created_at))
        ";
        $conn->query($sql3);
        $deletedDays = (int)$conn->affected_rows;

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Cleanup failed: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'deleted' => [
            'days' => $deletedDays,
            'states' => $deletedStates,
            'audits' => $deletedAudits,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_update_day_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) { echo json_encode(['ok' => false, 'error' => 'Invalid security token']); exit; }

    $userId = (int)($_POST['user_id'] ?? 0);
    $workDate = (string)($_POST['work_date'] ?? '');
    $selection = trim((string)($_POST['status'] ?? ''));
    $pIn = trim((string)($_POST['punch_in'] ?? ''));
    $pOut = trim((string)($_POST['punch_out'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $editMeta = hrAttendanceAdminParseSelection($selection);
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || empty($editMeta['ok'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $status = (string)($editMeta['status'] ?? 'Absent');

    $before = getAttendanceDay($userId, $workDate);
    $day = ensureAttendanceDay($userId, $workDate);
    if (!$day) { echo json_encode(['ok' => false, 'error' => 'Attendance record missing']); exit; }
    $dayId = (int)($day['id'] ?? 0);
    if ($dayId <= 0) { echo json_encode(['ok' => false, 'error' => 'Attendance record missing']); exit; }

    $toDb = function(string $d, string $t): ?string {
        $t = trim($t);
        if ($t === '') return null;
        if (!preg_match('/^\d{2}:\d{2}$/', $t)) return null;
        try {
            $dt = new DateTimeImmutable($d . ' ' . $t . ':00', hrDisplayTz());
            return $dt->setTimezone(hrBaseTz())->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    };

    $conn = getDbConnection();
    $closeOpenState = static function(int $attendanceDayId, ?string $at = null): void {
        $open = getOpenAttendanceState($attendanceDayId);
        if (!$open) return;
        $closeAt = $at ?: (string)($open['start_at'] ?? hrNow());
        endAttendanceStateByDayId($attendanceDayId, $closeAt);
    };
    $resolveDefaultPunchInDb = static function(int $userId, string $workDate) use ($toDb): ?string {
        $shiftStart = '';
        $dayRow = function_exists('getAttendanceDay') ? getAttendanceDay($userId, $workDate) : null;
        if (is_array($dayRow)) {
            $shiftStart = trim((string)($dayRow['shift_start_time'] ?? ''));
        }
        if ($shiftStart === '' && function_exists('getUserShiftForDate')) {
            $shift = getUserShiftForDate($userId, $workDate);
            $shiftStart = trim((string)($shift['start_time'] ?? ''));
        }
        if ($shiftStart !== '') {
            return $toDb($workDate, substr($shiftStart, 0, 5));
        }
        return $toDb($workDate, '09:30');
    };
    $insertOpenState = static function(int $attendanceDayId, string $state, string $at) use ($conn): void {
        $stmt = $conn->prepare("INSERT INTO hr_attendance_states (attendance_day_id, state, start_at) VALUES (?, ?, ?)");
        if (!$stmt) return;
        $stmt->bind_param('iss', $attendanceDayId, $state, $at);
        $stmt->execute();
        $stmt->close();
    };
    $pInDb = $toDb($workDate, $pIn);
    $pOutDb = $toDb($workDate, $pOut);
    if ($pOutDb !== null && $pInDb === null) {
        echo json_encode(['ok' => false, 'error' => 'Punch in is required before punch out.']);
        exit;
    }
    if (in_array($status, ['Full Day','Half Day'], true)) {
        $desiredWork = $status === 'Full Day' ? 465 : 300;
        $breakMinutes = 0;
        foreach (hrAttendanceBreakStates() as $k) {
            $m = function_exists('getBreakMinutesUsed') ? getBreakMinutesUsed($dayId, $k) : 0;
            $breakMinutes += max(0, (int)$m);
        }

        if ($pInDb === null) {
            $pInDb = $resolveDefaultPunchInDb($userId, $workDate);
        }

        if ($pInDb !== null && $pOutDb === null) {
            $inDt = hrParseBase($pInDb);
            if ($inDt) {
                $outDt = $inDt->modify('+' . max(0, ($desiredWork + $breakMinutes)) . ' minutes');
                $pOutDb = $outDt->format('Y-m-d H:i:s');
            }
        }
    }
    if ($pInDb !== null && $pOutDb !== null) {
        $inDt = hrParseBase($pInDb);
        $outDt = hrParseBase($pOutDb);
        if ($inDt && $outDt && $outDt <= $inDt) $pOutDb = $outDt->modify('+1 day')->format('Y-m-d H:i:s');
    }

    if ($editMeta['type'] === 'status' && in_array($status, ['Absent','Paid Leave','Unpaid Leave','NCNS'], true)) {
        $closeOpenState($dayId, $pOutDb ?: $pInDb ?: null);
        $stmt = $conn->prepare("
            UPDATE hr_attendance_days
            SET
                punch_in = NULL,
                punch_out = NULL,
                current_state = 'Off',
                break_minutes = 0,
                working_minutes = 0,
                late_minutes = 0,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        if (!$stmt) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmt->bind_param('si', $status, $dayId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            recomputeAttendanceDay($dayId);
        }
    } elseif ($editMeta['type'] === 'status') {
        $closeOpenState($dayId, $pOutDb ?: $pInDb ?: null);
        $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_in = ?, punch_out = ?, status = ?, current_state = 'Off', updated_at = NOW() WHERE id = ?");
        if (!$stmt) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmt->bind_param('sssi', $pInDb, $pOutDb, $status, $dayId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) recomputeAttendanceDay($dayId);
    } else {
        if ($pInDb === null) {
            $pInDb = $resolveDefaultPunchInDb($userId, $workDate);
        }

        $desiredState = (string)($editMeta['current_state'] ?? 'Working');
        $closeOpenState($dayId, $pInDb ?: null);

        $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_in = ?, punch_out = NULL, status = 'In Progress', current_state = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmt->bind_param('ssi', $pInDb, $desiredState, $dayId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok && $desiredState !== 'Working') {
            $insertOpenState($dayId, $desiredState, $pInDb ?: hrNow());
        }
        if ($ok) recomputeAttendanceDay($dayId);
    }

    $after = getAttendanceDay($userId, $workDate);
    $adminId = (int)($user['id'] ?? 0);
    if ($before && $after) {
        foreach (['status','current_state','punch_in','punch_out','working_minutes','break_minutes','late_minutes'] as $k) {
            $ov = $before[$k] ?? null;
            $nv = $after[$k] ?? null;
            if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Admin Update', $k, $ov, $nv, $remarks !== '' ? $remarks : null, $adminId);
        }
    } else {
        logAttendanceAudit($dayId, $userId, $workDate, 'Admin Update', 'status', $before['status'] ?? null, $status, $remarks !== '' ? $remarks : null, $adminId);
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_day') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $workDate = (string)($_POST['work_date'] ?? '');
        $pIn = trim((string)($_POST['punch_in'] ?? ''));
        $pOut = trim((string)($_POST['punch_out'] ?? ''));
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $message = 'Invalid request.';
            $messageType = 'danger';
        } else {
            $before = getAttendanceDay($userId, $workDate);
            $day = ensureAttendanceDay($userId, $workDate);
            if (!$day) {
                $message = 'Attendance record not found.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $toDb = function(string $d, string $t): ?string {
                    $t = trim($t);
                    if ($t === '') return null;
                    if (!preg_match('/^\d{2}:\d{2}$/', $t)) return null;
                    try {
                        $dt = new DateTimeImmutable($d . ' ' . $t . ':00', hrDisplayTz());
                        return $dt->setTimezone(hrBaseTz())->format('Y-m-d H:i:s');
                    } catch (Throwable $e) {
                        return null;
                    }
                };
                $pInDb = $toDb($workDate, $pIn);
                $pOutDb = $toDb($workDate, $pOut);
                if ($pOutDb !== null && $pInDb === null) {
                    $message = 'Punch in is required before punch out.';
                    $messageType = 'danger';
                } else {
                if ($pInDb !== null && $pOutDb !== null) {
                    $inDt = hrParseBase($pInDb);
                    $outDt = hrParseBase($pOutDb);
                    if ($inDt && $outDt && $outDt <= $inDt) {
                        $pOutDb = $outDt->modify('+1 day')->format('Y-m-d H:i:s');
                    }
                }
                $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_in = ?, punch_out = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $dayId = (int)$day['id'];
                    $stmt->bind_param('ssi', $pInDb, $pOutDb, $dayId);
                    $stmt->execute();
                    $stmt->close();
                    recomputeAttendanceDay($dayId);
                    $after = getAttendanceDay($userId, $workDate);
                    $adminId = (int)($user['id'] ?? 0);
                    if ($after) {
                        hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? 'Absent'), $adminId, 'Attendance edited manually by admin.');
                    }
                    if ($before && $after) {
                        foreach (['punch_in','punch_out'] as $k) {
                            $ov = $before[$k] ?? null;
                            $nv = $after[$k] ?? null;
                            if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Admin Edit', $k, $ov, $nv, null, $adminId);
                        }
                    } else {
                        logAttendanceAudit($dayId, $userId, $workDate, 'Admin Edit', 'punch_in', $before['punch_in'] ?? null, $pInDb, null, $adminId);
                        logAttendanceAudit($dayId, $userId, $workDate, 'Admin Edit', 'punch_out', $before['punch_out'] ?? null, $pOutDb, null, $adminId);
                    }
                    $message = 'Attendance updated.';
                    $messageType = 'success';
                }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_status') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $workDate = (string)($_POST['work_date'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        $allowed = ['Full Day','Half Day','Absent','Paid Leave','Unpaid Leave','NCNS'];
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || !in_array($status, $allowed, true)) {
            $message = 'Invalid request.';
            $messageType = 'danger';
        } else {
            $before = getAttendanceDay($userId, $workDate);
            $day = ensureAttendanceDay($userId, $workDate);
            if (!$day) {
                $message = 'Attendance record not found.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $dayId = (int)($day['id'] ?? 0);
                if (in_array($status, ['Absent','Paid Leave','Unpaid Leave','NCNS'], true)) {
                    $stmt = $conn->prepare("
                        UPDATE hr_attendance_days
                        SET
                            punch_in = NULL,
                            punch_out = NULL,
                            current_state = 'Off',
                            break_minutes = 0,
                            working_minutes = 0,
                            late_minutes = 0,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('si', $status, $dayId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $after = getAttendanceDay($userId, $workDate);
                        $adminId = (int)($user['id'] ?? 0);
                        if ($ok && $after) {
                            hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? $status), $adminId, 'Attendance status set manually by admin.');
                        }
                        if ($before && $after) {
                            foreach (['status','punch_in','punch_out','working_minutes','break_minutes','late_minutes'] as $k) {
                                $ov = $before[$k] ?? null;
                                $nv = $after[$k] ?? null;
                                if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Admin Set Status', $k, $ov, $nv, null, $adminId);
                            }
                        } else {
                            logAttendanceAudit($dayId, $userId, $workDate, 'Admin Set Status', 'status', $before['status'] ?? null, $status, null, $adminId);
                        }
                        $message = $ok ? 'Status updated.' : 'Failed to update status.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                } elseif ($status === 'Half Day') {
                    $defaultMin = 210;
                    $stmt = $conn->prepare("
                        UPDATE hr_attendance_days
                        SET
                            current_state = 'Off',
                            break_minutes = 0,
                            working_minutes = CASE WHEN COALESCE(working_minutes, 0) > 0 THEN working_minutes ELSE ? END,
                            status = 'Half Day',
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ii', $defaultMin, $dayId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $after = getAttendanceDay($userId, $workDate);
                        $adminId = (int)($user['id'] ?? 0);
                        if ($before && $after) {
                            foreach (['status','working_minutes'] as $k) {
                                $ov = $before[$k] ?? null;
                                $nv = $after[$k] ?? null;
                                if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Admin Set Status', $k, $ov, $nv, null, $adminId);
                            }
                        } else {
                            logAttendanceAudit($dayId, $userId, $workDate, 'Admin Set Status', 'status', $before['status'] ?? null, 'Half Day', null, $adminId);
                        }
                        $message = $ok ? 'Status updated.' : 'Failed to update status.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                } else {
                    $shift = getUserShiftForDate($userId, $workDate);
                    $shiftStart = substr((string)($shift['start_time'] ?? '09:30:00'), 0, 8);
                    $shiftEnd = substr((string)($shift['end_time'] ?? '18:30:00'), 0, 8);
                    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftStart)) $shiftStart = '09:30:00';
                    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftEnd)) $shiftEnd = '18:30:00';

                    $pInDb = $workDate . ' ' . $shiftStart;
                    $pOutDb = $workDate . ' ' . $shiftEnd;
                    if ($shiftEnd <= $shiftStart) {
                        $pOutDb = date('Y-m-d H:i:s', strtotime($pOutDb . ' +1 day'));
                    }

                    $stmt = $conn->prepare("
                        UPDATE hr_attendance_days
                        SET
                            punch_in = ?,
                            punch_out = ?,
                            current_state = 'Off',
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ssi', $pInDb, $pOutDb, $dayId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        if ($ok) recomputeAttendanceDay($dayId);
                        $after = getAttendanceDay($userId, $workDate);
                        $adminId = (int)($user['id'] ?? 0);
                        if ($ok && $after) {
                            hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? 'Absent'), $adminId, 'Attendance status set manually by admin.');
                        }
                        if ($before && $after) {
                            foreach (['punch_in','punch_out','status','working_minutes','late_minutes'] as $k) {
                                $ov = $before[$k] ?? null;
                                $nv = $after[$k] ?? null;
                                if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Admin Set Status', $k, $ov, $nv, null, $adminId);
                            }
                        } else {
                            logAttendanceAudit($dayId, $userId, $workDate, 'Admin Set Status', 'punch_in', $before['punch_in'] ?? null, $pInDb, null, $adminId);
                            logAttendanceAudit($dayId, $userId, $workDate, 'Admin Set Status', 'punch_out', $before['punch_out'] ?? null, $pOutDb, null, $adminId);
                        }
                        $message = $ok ? 'Status updated.' : 'Failed to update status.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_month') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $monthStr = (string)($_POST['month'] ?? '');
        $status = (string)($_POST['status'] ?? 'Full Day');
        $workingOnly = (int)($_POST['working_only'] ?? 0) === 1;
        $overwrite = (int)($_POST['overwrite'] ?? 0) === 1;
        $allowed = ['Full Day','Half Day','Absent','Paid Leave','Unpaid Leave','NCNS'];

        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $monthStr) || !in_array($status, $allowed, true)) {
            $message = 'Invalid request.';
            $messageType = 'danger';
        } else {
            $yy = (int)substr($monthStr, 0, 4);
            $mo = (int)substr($monthStr, 5, 2);
            if ($yy < 1970 || $yy > 2100 || $mo < 1 || $mo > 12) {
                $message = 'Invalid month.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $stmtU = $conn->prepare("SELECT id FROM users WHERE id = ? AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) LIMIT 1");
                $existsUser = false;
                if ($stmtU) {
                    $stmtU->bind_param('i', $userId);
                    $stmtU->execute();
                    $existsUser = (bool)($stmtU->get_result()->fetch_assoc() ?: null);
                    $stmtU->close();
                }
                if (!$existsUser) {
                    $message = 'User not found.';
                    $messageType = 'danger';
                } else {
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mo, $yy);
                    $holidaySet = [];
                    if ($workingOnly) {
                        foreach (getHolidaysForMonth($yy, $mo, 'US') as $h) {
                            $d = (string)($h['holiday_date'] ?? '');
                            if ($d !== '') $holidaySet[$d] = true;
                        }
                    }

                    $updated = 0;
                    $created = 0;
                    $skipped = 0;
                    $adminId = (int)($user['id'] ?? 0);
                    $bulkRemarks = 'Bulk month ' . $monthStr . ': ' . $status . ($workingOnly ? ' (working-only)' : '') . ($overwrite ? ' (overwrite)' : '');

                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $ts = mktime(0, 0, 0, $mo, $day, $yy);
                        if ($ts === false) { $skipped++; continue; }
                        $workDate = date('Y-m-d', $ts);
                        if ($workingOnly) {
                            $weekday = (int)date('N', $ts);
                            if ($weekday > 5) { $skipped++; continue; }
                            if (isset($holidaySet[$workDate])) { $skipped++; continue; }
                        }

                        $before = getAttendanceDay($userId, $workDate);
                        if (!$overwrite && $before) {
                            $beforeStatus = (string)($before['status'] ?? 'Absent');
                            $beforeIn = (string)($before['punch_in'] ?? '');
                            $beforeOut = (string)($before['punch_out'] ?? '');
                            if ($beforeStatus !== 'Absent' || $beforeIn !== '' || $beforeOut !== '') {
                                $skipped++;
                                continue;
                            }
                        }

                        $dayRow = ensureAttendanceDay($userId, $workDate);
                        if (!$dayRow) { $skipped++; continue; }
                        if (!$before) $created++;
                        $dayId = (int)($dayRow['id'] ?? 0);
                        if ($dayId <= 0) { $skipped++; continue; }

                        if (in_array($status, ['Absent','Paid Leave','Unpaid Leave','NCNS'], true)) {
                            $stmt = $conn->prepare("
                                UPDATE hr_attendance_days
                                SET
                                    punch_in = NULL,
                                    punch_out = NULL,
                                    current_state = 'Off',
                                    break_minutes = 0,
                                    working_minutes = 0,
                                    late_minutes = 0,
                                    status = ?,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            if ($stmt) {
                                $stmt->bind_param('si', $status, $dayId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) {
                                    $updated++;
                                    $after = getAttendanceDay($userId, $workDate);
                                    hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? $status), $adminId, $bulkRemarks);
                                    if ($before && $after) {
                                        foreach (['status','punch_in','punch_out','working_minutes','break_minutes','late_minutes'] as $k) {
                                            $ov = $before[$k] ?? null;
                                            $nv = $after[$k] ?? null;
                                            if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Bulk Month', $k, $ov, $nv, $bulkRemarks, $adminId);
                                        }
                                    } else {
                                        logAttendanceAudit($dayId, $userId, $workDate, 'Bulk Month', 'status', $before['status'] ?? null, $status, $bulkRemarks, $adminId);
                                    }
                                } else {
                                    $skipped++;
                                }
                            } else {
                                $skipped++;
                            }
                        } elseif ($status === 'Half Day') {
                            $defaultMin = 210;
                            $stmt = $conn->prepare("
                                UPDATE hr_attendance_days
                                SET
                                    current_state = 'Off',
                                    break_minutes = 0,
                                    working_minutes = CASE WHEN COALESCE(working_minutes, 0) > 0 THEN working_minutes ELSE ? END,
                                    status = 'Half Day',
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            if ($stmt) {
                                $stmt->bind_param('ii', $defaultMin, $dayId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) {
                                    $updated++;
                                    $after = getAttendanceDay($userId, $workDate);
                                    hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? 'Half Day'), $adminId, $bulkRemarks);
                                    if ($before && $after) {
                                        foreach (['status','working_minutes'] as $k) {
                                            $ov = $before[$k] ?? null;
                                            $nv = $after[$k] ?? null;
                                            if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Bulk Month', $k, $ov, $nv, $bulkRemarks, $adminId);
                                        }
                                    } else {
                                        logAttendanceAudit($dayId, $userId, $workDate, 'Bulk Month', 'status', $before['status'] ?? null, 'Half Day', $bulkRemarks, $adminId);
                                    }
                                } else {
                                    $skipped++;
                                }
                            } else {
                                $skipped++;
                            }
                        } else {
                            $shift = getUserShiftForDate($userId, $workDate);
                            $shiftStart = substr((string)($shift['start_time'] ?? '09:30:00'), 0, 8);
                            $shiftEnd = substr((string)($shift['end_time'] ?? '18:30:00'), 0, 8);
                            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftStart)) $shiftStart = '09:30:00';
                            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftEnd)) $shiftEnd = '18:30:00';

                            $pInDb = $workDate . ' ' . $shiftStart;
                            $pOutDb = $workDate . ' ' . $shiftEnd;
                            if ($shiftEnd <= $shiftStart) {
                                $pOutDb = date('Y-m-d H:i:s', strtotime($pOutDb . ' +1 day'));
                            }

                            $stmt = $conn->prepare("
                                UPDATE hr_attendance_days
                                SET
                                    punch_in = ?,
                                    punch_out = ?,
                                    current_state = 'Off',
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            if ($stmt) {
                                $stmt->bind_param('ssi', $pInDb, $pOutDb, $dayId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) {
                                    recomputeAttendanceDay($dayId);
                                    $updated++;
                                    $after = getAttendanceDay($userId, $workDate);
                                    hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? 'Absent'), $adminId, $bulkRemarks);
                                    if ($before && $after) {
                                        foreach (['punch_in','punch_out','status','working_minutes','late_minutes'] as $k) {
                                            $ov = $before[$k] ?? null;
                                            $nv = $after[$k] ?? null;
                                            if ((string)$ov !== (string)$nv) logAttendanceAudit($dayId, $userId, $workDate, 'Bulk Month', $k, $ov, $nv, $bulkRemarks, $adminId);
                                        }
                                    } else {
                                        logAttendanceAudit($dayId, $userId, $workDate, 'Bulk Month', 'punch_in', $before['punch_in'] ?? null, $pInDb, $bulkRemarks, $adminId);
                                        logAttendanceAudit($dayId, $userId, $workDate, 'Bulk Month', 'punch_out', $before['punch_out'] ?? null, $pOutDb, $bulkRemarks, $adminId);
                                    }
                                } else {
                                    $skipped++;
                                }
                            } else {
                                $skipped++;
                            }
                        }
                    }

                    $message = "Monthly attendance updated. Updated: {$updated}, Created: {$created}, Skipped: {$skipped}.";
                    $messageType = $updated > 0 || $created > 0 ? 'success' : 'warning';
                }
            }
        }
    }
}

$rows = getAttendanceDashboard($date);
$yy = (int)substr($date, 0, 4);
$mo = (int)substr($date, 5, 2);
$selMonthStr = sprintf('%04d-%02d', $yy, $mo);
$holidays = getHolidaysForMonth($yy, $mo, 'US');
$fullCount = 0;
$halfCount = 0;
$absCount = 0;
$plCount = 0;
$ulCount = 0;
$ncnsCount = 0;
$inProg = 0;
$presentLate = 0;
$pendingCount = 0;
$holidayCount = 0;
$weekOffCount = 0;
foreach ($rows as $r) {
    $st = (string)($r['status'] ?? 'Absent');
    $meta = attendanceAdminDayStatusMeta($r, $date, $todayIst);
    if (($meta['filter_key'] ?? '') === 'holiday') $holidayCount++;
    elseif (($meta['filter_key'] ?? '') === 'weekoff') $weekOffCount++;
    elseif (($meta['filter_key'] ?? '') === 'pending') $pendingCount++;
    elseif (($meta['filter_key'] ?? '') === 'leave') {
        if ($st === 'Paid Leave' || $st === 'paid_leave') $plCount++;
        else $ulCount++;
    } elseif (($meta['filter_key'] ?? '') === 'ncns') $ncnsCount++;
    elseif (($meta['filter_key'] ?? '') === 'half') $halfCount++;
    elseif (($meta['filter_key'] ?? '') === 'inprogress') $inProg++;
    elseif (($meta['filter_key'] ?? '') === 'present') $fullCount++;
    else $absCount++;
    if (!empty($meta['is_late'])) $presentLate++;
}

$tab = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'team';
if (!in_array($tab, ['team','employee','daily'], true)) $tab = 'team';
$empId = isset($_GET['emp_id']) ? (int)$_GET['emp_id'] : 0;
if ($empId <= 0 && !empty($rows)) $empId = (int)($rows[0]['user_id'] ?? 0);
[$rangeStart, $rangeEnd] = adminRangeFromView($view, $date);

$attendanceUsers = getInternalPayrollUsers();
$dailyPreset = isset($_GET['daily_range']) ? strtolower(trim((string)$_GET['daily_range'])) : 'today';
$dailySearch = trim((string)($_GET['daily_q'] ?? ''));
$dailyStatus = trim((string)($_GET['daily_status'] ?? 'All'));
$dailyRole = trim((string)($_GET['daily_role'] ?? ''));
$dailyDepartment = trim((string)($_GET['daily_department'] ?? ''));
$dailyDateFrom = isset($_GET['daily_date_from']) ? (string)$_GET['daily_date_from'] : $date;
$dailyDateTo = isset($_GET['daily_date_to']) ? (string)$_GET['daily_date_to'] : $date;
$dailyRange = adminUserListRange($dailyPreset, $date, $todayIst, $dailyDateFrom, $dailyDateTo);
$dailyStart = (string)($dailyRange['start'] ?? $date);
$dailyEnd = (string)($dailyRange['end'] ?? $date);
$dailyRangeLabel = (string)($dailyRange['label'] ?? 'Today');
$dailySingleDay = !empty($dailyRange['single_day']);
$dailySummaryRows = [];
$dailyRoleOptions = [];
$dailyDepartmentOptions = [];
$dailyHolidayMap = [];
$dailyExportQuery = '';
$dailyEmployeeCount = 0;
$dailyPresentCount = 0;
$dailyHalfCount = 0;
$dailyLeaveCount = 0;
$dailyAbsentCount = 0;
$dailyPendingCount = 0;
$dailyNcnsCount = 0;
$dailyLateCount = 0;
$dailyWorkMinutes = 0;
$dailyHolidayCount = 0;
$dailyWeekOffCount = 0;
$dailyUserMetaById = [];

foreach ($attendanceUsers as $attendanceUser) {
    $attendanceUserId = (int)($attendanceUser['id'] ?? 0);
    if ($attendanceUserId > 0) $dailyUserMetaById[$attendanceUserId] = $attendanceUser;
    $roleValue = trim((string)($attendanceUser['role'] ?? ''));
    $departmentValue = trim((string)($attendanceUser['department'] ?? ''));
    if ($roleValue !== '') $dailyRoleOptions[$roleValue] = true;
    if ($departmentValue !== '') $dailyDepartmentOptions[$departmentValue] = true;
}
$dailyRoleOptions = array_keys($dailyRoleOptions);
$dailyDepartmentOptions = array_keys($dailyDepartmentOptions);
sort($dailyRoleOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($dailyDepartmentOptions, SORT_NATURAL | SORT_FLAG_CASE);

if ($tab === 'daily') {
    $dailyHolidayMap = hrGetHolidayMapForRange($dailyStart, $dailyEnd, 'US');
    if ($dailySingleDay) {
        $dailySearchNeedle = strtolower($dailySearch);
        foreach (getAttendanceDashboard($dailyStart) as $dayRow) {
            $userId = (int)($dayRow['user_id'] ?? 0);
            $userMeta = $dailyUserMetaById[$userId] ?? [];
            $name = (string)($dayRow['full_name'] ?? ($userMeta['full_name'] ?? ''));
            $employeeId = (string)($dayRow['employee_id'] ?? ($userMeta['employee_id'] ?? ''));
            $jobTitle = (string)($dayRow['job_title'] ?? ($userMeta['job_title'] ?? ''));
            $role = (string)($dayRow['role'] ?? ($userMeta['role'] ?? ''));
            $department = (string)($userMeta['department'] ?? '');
            if ($dailySearchNeedle !== '') {
                $haystack = strtolower(trim($name . ' ' . $employeeId . ' ' . $jobTitle . ' ' . $role . ' ' . $department));
                if (strpos($haystack, $dailySearchNeedle) === false) continue;
            }
            if ($dailyRole !== '' && strcasecmp($role, $dailyRole) !== 0) continue;
            if ($dailyDepartment !== '' && strcasecmp($department, $dailyDepartment) !== 0) continue;

            $meta = attendanceAdminDayStatusMeta($dayRow, $dailyStart, $todayIst);
            if (!attendanceAdminMatchesDailyStatus($dailyStatus, $meta)) continue;

            $rawStatus = (string)($dayRow['status'] ?? 'Absent');
            $presentDays = in_array((string)($meta['filter_key'] ?? ''), ['present', 'inprogress'], true) ? 1 : 0;
            $halfDays = (string)($meta['filter_key'] ?? '') === 'half' ? 1 : 0;
            $paidLeaveDays = in_array($rawStatus, ['Paid Leave','paid_leave'], true) ? 1 : 0;
            $unpaidLeaveDays = in_array($rawStatus, ['Unpaid Leave','unpaid_leave'], true) ? 1 : 0;
            $absentDays = (string)($meta['filter_key'] ?? '') === 'absent' ? 1 : 0;
            $pendingDays = (string)($meta['filter_key'] ?? '') === 'pending' ? 1 : 0;
            $ncnsDays = (string)($meta['filter_key'] ?? '') === 'ncns' ? 1 : 0;
            $lateDays = !empty($meta['is_late']) ? 1 : 0;
            $workingDays = !empty($meta['is_working_day']) ? 1 : 0;
            $workMinutes = (int)($dayRow['working_minutes'] ?? 0);
            $breakMinutes = (int)($dayRow['break_minutes'] ?? 0);
            $lateMinutes = (int)($dayRow['late_minutes'] ?? 0);
            $paidDays = $presentDays + $paidLeaveDays + ($halfDays * 0.5);
            $attendancePercent = $workingDays > 0 ? round((($paidDays / $workingDays) * 100.0), 1) : 0.0;

            if (($meta['filter_key'] ?? '') === 'holiday') $dailyHolidayCount++;
            if (($meta['filter_key'] ?? '') === 'weekoff') $dailyWeekOffCount++;

            $dailySummaryRows[] = [
                'user_id' => $userId,
                'name' => $name,
                'employee_id' => $employeeId,
                'job_title' => $jobTitle,
                'role' => $role,
                'department' => $department,
                'primary_status' => (string)($meta['primary_status'] ?? 'Absent'),
                'working_days' => $workingDays,
                'present_days' => $presentDays,
                'half_days' => $halfDays,
                'paid_leave_days' => $paidLeaveDays,
                'unpaid_leave_days' => $unpaidLeaveDays,
                'absent_days' => $absentDays,
                'pending_days' => $pendingDays,
                'ncns_days' => $ncnsDays,
                'late_days' => $lateDays,
                'late_minutes' => $lateMinutes,
                'break_minutes' => $breakMinutes,
                'work_minutes' => $workMinutes,
                'paid_days' => $paidDays,
                'attendance_percent' => $attendancePercent,
            ];
        }
        usort($dailySummaryRows, static function (array $a, array $b): int {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
    } else {
        $dailySummaryRows = getAttendanceRangeReport($dailyStart, $dailyEnd, [
            'search' => $dailySearch,
            'status' => $dailyStatus,
            'role' => $dailyRole,
            'department' => $dailyDepartment,
        ]);
    }

    foreach ($dailySummaryRows as $dailySummaryRow) {
        $dailyEmployeeCount++;
        $dailyPresentCount += (int)($dailySummaryRow['present_days'] ?? 0);
        $dailyHalfCount += (int)($dailySummaryRow['half_days'] ?? 0);
        $dailyLeaveCount += (int)($dailySummaryRow['paid_leave_days'] ?? 0) + (int)($dailySummaryRow['unpaid_leave_days'] ?? 0);
        $dailyAbsentCount += (int)($dailySummaryRow['absent_days'] ?? 0);
        $dailyPendingCount += (int)($dailySummaryRow['pending_days'] ?? 0);
        $dailyNcnsCount += (int)($dailySummaryRow['ncns_days'] ?? 0);
        $dailyLateCount += (int)($dailySummaryRow['late_days'] ?? 0);
        $dailyWorkMinutes += (int)($dailySummaryRow['work_minutes'] ?? 0);
    }
}

$dailyExportQuery = http_build_query([
    'tab' => 'daily',
    'date' => $date,
    'daily_range' => $dailyPreset,
    'daily_date_from' => $dailyDateFrom,
    'daily_date_to' => $dailyDateTo,
    'daily_q' => $dailySearch,
    'daily_status' => $dailyStatus,
    'daily_role' => $dailyRole,
    'daily_department' => $dailyDepartment,
    'export' => 'daily_csv',
]);

if ((string)($_GET['export'] ?? '') === 'daily_csv') {
    @set_time_limit(0);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    $fileRange = $dailyStart === $dailyEnd ? $dailyStart : ($dailyStart . '_to_' . $dailyEnd);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_user_list_' . $fileRange . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'range_label',
        'start_date',
        'end_date',
        'user_id',
        'employee_id',
        'name',
        'job_title',
        'role',
        'department',
        'primary_status',
        'working_days',
        'present_days',
        'half_days',
        'paid_leave_days',
        'unpaid_leave_days',
        'leave_days',
        'absent_days',
        'pending_days',
        'ncns_days',
        'late_days',
        'late_minutes',
        'break_minutes',
        'work_minutes',
        'work_hhmm',
        'paid_days',
        'attendance_percent',
    ]);
    foreach ($dailySummaryRows as $r) {
        $paidLeaveDays = (int)($r['paid_leave_days'] ?? 0);
        $unpaidLeaveDays = (int)($r['unpaid_leave_days'] ?? 0);
        fputcsv($out, [
            $dailyRangeLabel,
            $dailyStart,
            $dailyEnd,
            (int)($r['user_id'] ?? 0),
            (string)($r['employee_id'] ?? ''),
            (string)($r['name'] ?? ''),
            (string)($r['job_title'] ?? ''),
            (string)($r['role'] ?? ''),
            (string)($r['department'] ?? ''),
            (string)($r['primary_status'] ?? ''),
            (int)($r['working_days'] ?? 0),
            (int)($r['present_days'] ?? 0),
            (int)($r['half_days'] ?? 0),
            $paidLeaveDays,
            $unpaidLeaveDays,
            $paidLeaveDays + $unpaidLeaveDays,
            (int)($r['absent_days'] ?? 0),
            (int)($r['pending_days'] ?? 0),
            (int)($r['ncns_days'] ?? 0),
            (int)($r['late_days'] ?? 0),
            (int)($r['late_minutes'] ?? 0),
            (int)($r['break_minutes'] ?? 0),
            (int)($r['work_minutes'] ?? 0),
            fmtMinutesHHMM((int)($r['work_minutes'] ?? 0)),
            number_format((float)($r['paid_days'] ?? 0), 1, '.', ''),
            number_format((float)($r['attendance_percent'] ?? 0), 1, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}
?>
<?php $pageTitle = 'Attendance Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
  .hr-userlist-filter-card {
    border: 1px solid var(--app-border);
    border-radius: 16px;
    background: linear-gradient(180deg, rgba(14, 165, 233, 0.06), rgba(255, 255, 255, 0.68));
  }
  [data-bs-theme="dark"] .hr-userlist-filter-card {
    background: linear-gradient(180deg, rgba(34, 211, 238, 0.08), rgba(255, 255, 255, 0.02));
  }
  .hr-userlist-name {
    min-width: 240px;
  }
  .hr-userlist-name .small {
    line-height: 1.35;
  }
  .hr-userlist-table thead th {
    white-space: nowrap;
  }
  .hr-userlist-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .hr-userlist-stats .badge {
    padding: 0.45rem 0.65rem;
    font-weight: 600;
  }
</style>
<div class="container-fluid px-0">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Attendance Admin'],
            ],
            'Attendance Dashboard',
            'Daily attendance overview and corrections',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
                ['label' => 'Monthly', 'href' => 'attendance-monthly-report?month=' . urlencode($selMonthStr), 'icon' => 'bi-calendar3', 'class' => 'btn-outline-secondary'],
                ['label' => 'Export', 'href' => 'attendance-export?date=' . urlencode($date), 'icon' => 'bi-download', 'class' => 'btn-outline-secondary'],
            ]
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <div class="hr-admin-toolbar mb-3">
                        <div class="hr-admin-toolbar-main">
                            <div>
                                <div class="hr-admin-section-title">Attendance Workspace</div>
                                <div class="hr-admin-section-subtitle">Switch between team, employee, and daily attendance views without leaving the admin surface.</div>
                            </div>
                            <span class="hr-admin-pill"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($rangeStart . ' → ' . $rangeEnd); ?></span>
                        </div>
                        <div class="hr-admin-toolbar-side">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Admin tabs">
                            <a class="btn <?php echo $tab === 'team' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?tab=team&date=<?php echo urlencode($date); ?>&view=<?php echo urlencode($view); ?>">Team Calendar</a>
                            <a class="btn <?php echo $tab === 'employee' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?tab=employee&date=<?php echo urlencode($date); ?>&view=<?php echo urlencode($view); ?>&emp_id=<?php echo (int)$empId; ?>">Employee Calendar</a>
                            <a class="btn <?php echo $tab === 'daily' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?tab=daily&date=<?php echo urlencode($date); ?>">Daily List</a>
                            </div>
                            <input type="date" id="hrAdminDate" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars($date); ?>" style="max-width: 170px;">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Calendar view">
                                <a class="btn <?php echo $view === 'month' ? 'btn-secondary' : 'btn-outline-secondary'; ?>" href="?tab=<?php echo urlencode($tab); ?>&date=<?php echo urlencode($date); ?>&view=month<?php echo $tab === 'employee' ? ('&emp_id=' . (int)$empId) : ''; ?>">Month</a>
                                <a class="btn <?php echo $view === 'week' ? 'btn-secondary' : 'btn-outline-secondary'; ?>" href="?tab=<?php echo urlencode($tab); ?>&date=<?php echo urlencode($date); ?>&view=week<?php echo $tab === 'employee' ? ('&emp_id=' . (int)$empId) : ''; ?>">Week</a>
                                <a class="btn <?php echo $view === 'day' ? 'btn-secondary' : 'btn-outline-secondary'; ?>" href="?tab=<?php echo urlencode($tab); ?>&date=<?php echo urlencode($date); ?>&view=day<?php echo $tab === 'employee' ? ('&emp_id=' . (int)$empId) : ''; ?>">Day</a>
                            </div>
                        </div>
                    </div>

                    <div class="card hr-card mb-3">
                        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-broom me-1"></i> Cleanup: Pre-Joining Attendance Rows</span>
                            <span class="badge bg-light text-dark border">Safe Preview → Optional Delete</span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-outline-secondary btn-sm" type="button" id="hrCleanupPreviewBtn"><i class="bi bi-search"></i> Preview</button>
                                <button class="btn btn-danger btn-sm" type="button" id="hrCleanupDeleteBtn" disabled><i class="bi bi-trash"></i> Delete</button>
                            </div>
                            <div class="small text-muted mt-2" id="hrCleanupMsg">Removes historical rows where attendance date is earlier than employee joining date. Deletes related break states and audit logs for those rows.</div>
                            <div class="table-responsive mt-2" id="hrCleanupTableWrap" style="display:none;"></div>
                        </div>
                    </div>

                    <div class="tab-content pt-2">
                        <div class="tab-pane fade <?php echo $tab === 'team' ? 'show active' : ''; ?>" id="tab-team" role="tabpanel">
                            <div class="row g-3 mb-3">
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('Employees', number_format(count($rows)), 'bi-people', ''); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('Present', number_format($fullCount + $halfCount + $inProg), 'bi-check2-circle', 'Full + half + in progress'); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('Late', number_format($presentLate), 'bi-alarm', ''); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('Absent', number_format($absCount), 'bi-x-circle', 'Holiday, week off, and pending excluded', 'text-danger'); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('Pending', number_format($pendingCount), 'bi-hourglass', 'Blank today/future working days', 'text-primary'); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('On Leave', number_format($plCount + $ulCount), 'bi-calendar2-week', ''); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('Weekly Off', number_format($weekOffCount), 'bi-calendar-week', ''); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('Holiday', number_format($holidayCount), 'bi-balloon', 'US holiday rows'); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('In Progress', number_format($inProg), 'bi-arrow-repeat', ''); ?></div>
                                <div class="col-6 col-md-3 col-xl-2"><?php hrKpi('NCNS', number_format($ncnsCount), 'bi-exclamation-triangle', '', 'text-danger'); ?></div>
                            </div>
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-calendar3 me-1"></i> Team Calendar</span>
                                    <span class="badge bg-secondary font-monospace" id="hrAdminRange"><?php echo htmlspecialchars($rangeStart . ' → ' . $rangeEnd); ?></span>
                                </div>
                                <div class="card-body">
                                    <div id="hrAdminTeamCalendar"></div>
                                    <div class="small text-muted mt-2">Click any date to view employee list, edit attendance, and review audit trail.</div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $tab === 'employee' ? 'show active' : ''; ?>" id="tab-employee" role="tabpanel">
                            <div class="row g-3 mb-3">
                                <div class="col-lg-5">
                                    <label class="form-label small text-muted">Search</label>
                                    <input type="text" class="form-control form-control-sm" id="hrAdminEmpSearch" placeholder="Search employee..." autocomplete="off">
                                </div>
                                <div class="col-lg-5">
                                    <label class="form-label small text-muted">Employee</label>
                                    <select class="form-select form-select-sm" id="hrAdminEmpSelect">
                                        <?php foreach ($rows as $uRow): ?>
                                            <?php $uid = (int)($uRow['user_id'] ?? 0); ?>
                                            <?php
                                                $nm = trim((string)($uRow['full_name'] ?? ''));
                                                $role = trim((string)($uRow['role'] ?? ''));
                                                $empCode = trim((string)($uRow['employee_id'] ?? ''));
                                                $optText = $nm . ($role !== '' ? ' (' . $role . ')' : '') . ($empCode !== '' ? ' — ' . $empCode : '');
                                            ?>
                                            <option value="<?php echo $uid; ?>" data-name="<?php echo htmlspecialchars($nm); ?>" data-code="<?php echo htmlspecialchars($empCode); ?>" <?php echo $uid === (int)$empId ? 'selected' : ''; ?>>
                                                <?php
                                                    echo htmlspecialchars($optText);
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 d-flex align-items-end gap-2 flex-wrap">
                                    <div class="hr-admin-note w-100">Edit punches and attendance status from the drawer. Every update stays audit-logged.</div>
                                </div>
                            </div>
                            <div class="card hr-card hr-admin-calendar-card">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-person-badge me-1"></i> <span id="hrAdminEmpTitle">Employee Calendar</span></span>
                                    <span class="badge bg-secondary font-monospace" id="hrAdminEmpRange"><?php echo htmlspecialchars($rangeStart . ' → ' . $rangeEnd); ?></span>
                                </div>
                                <div class="card-body">
                                    <div id="hrAdminEmpCalendar"></div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $tab === 'daily' ? 'show active' : ''; ?>" id="tab-daily" role="tabpanel">
                            <div class="row g-3 mb-3">
                                <div class="col-lg-4">
                                    <div class="card hr-card h-100 hr-userlist-filter-card">
                                        <div class="card-header bg-transparent border-0 fw-semibold d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-funnel"></i>
                                                <span>Attendance User List Filters</span>
                                            </div>
                                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($dailyRangeLabel); ?>: <?php echo htmlspecialchars($dailyStart); ?> to <?php echo htmlspecialchars($dailyEnd); ?></span>
                                        </div>
                                        <div class="card-body">
                                            <form method="get" class="row g-2 align-items-end">
                                                <input type="hidden" name="tab" value="daily">
                                                <div class="col-md-3">
                                                    <label class="form-label small text-muted">Range</label>
                                                    <select name="daily_range" class="form-select form-select-sm">
                                                        <option value="today" <?php echo $dailyPreset === 'today' ? 'selected' : ''; ?>>Today</option>
                                                        <option value="week" <?php echo $dailyPreset === 'week' ? 'selected' : ''; ?>>This Week</option>
                                                        <option value="month" <?php echo $dailyPreset === 'month' ? 'selected' : ''; ?>>This Month</option>
                                                        <option value="custom" <?php echo $dailyPreset === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small text-muted">Anchor Date</label>
                                                    <input type="date" name="date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small text-muted">Date From</label>
                                                    <input type="date" name="daily_date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dailyDateFrom); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small text-muted">Date To</label>
                                                    <input type="date" name="daily_date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dailyDateTo); ?>">
                                                </div>
                                                <div class="col-12">
                                                    <div class="small text-muted">Use the anchor date for today, week, or month views. Use the custom dates when you want a custom date range.</div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Search Employee</label>
                                                    <input type="text" name="daily_q" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dailySearch); ?>" placeholder="Name, employee ID, role, department">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted">Status</label>
                                                    <select name="daily_status" class="form-select form-select-sm">
                                                        <option value="All" <?php echo strcasecmp($dailyStatus, 'All') === 0 ? 'selected' : ''; ?>>All</option>
                                                        <option value="Present" <?php echo strcasecmp($dailyStatus, 'Present') === 0 ? 'selected' : ''; ?>>Present</option>
                                                        <option value="Late" <?php echo strcasecmp($dailyStatus, 'Late') === 0 ? 'selected' : ''; ?>>Late</option>
                                                        <option value="Leave" <?php echo strcasecmp($dailyStatus, 'Leave') === 0 ? 'selected' : ''; ?>>Leave</option>
                                                        <option value="Absent" <?php echo strcasecmp($dailyStatus, 'Absent') === 0 ? 'selected' : ''; ?>>Absent</option>
                                                        <option value="Pending" <?php echo strcasecmp($dailyStatus, 'Pending') === 0 ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="Holiday" <?php echo strcasecmp($dailyStatus, 'Holiday') === 0 ? 'selected' : ''; ?>>Holiday</option>
                                                        <option value="Weekly Off" <?php echo strcasecmp($dailyStatus, 'Weekly Off') === 0 ? 'selected' : ''; ?>>Weekly Off</option>
                                                        <option value="NCNS" <?php echo strcasecmp($dailyStatus, 'NCNS') === 0 ? 'selected' : ''; ?>>NCNS</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small text-muted">Role</label>
                                                    <select name="daily_role" class="form-select form-select-sm">
                                                        <option value="">All Roles</option>
                                                        <?php foreach ($dailyRoleOptions as $dailyRoleOption): ?>
                                                            <option value="<?php echo htmlspecialchars($dailyRoleOption); ?>" <?php echo strcasecmp($dailyRole, $dailyRoleOption) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($dailyRoleOption); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small text-muted">Department</label>
                                                    <select name="daily_department" class="form-select form-select-sm">
                                                        <option value="">All Departments</option>
                                                        <?php foreach ($dailyDepartmentOptions as $dailyDepartmentOption): ?>
                                                            <option value="<?php echo htmlspecialchars($dailyDepartmentOption); ?>" <?php echo strcasecmp($dailyDepartment, $dailyDepartmentOption) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($dailyDepartmentOption); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 d-grid">
                                                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Apply Filters</button>
                                                </div>
                                                <div class="col-md-2 d-grid">
                                                    <a class="btn btn-outline-primary btn-sm" href="?<?php echo htmlspecialchars($dailyExportQuery); ?>"><i class="bi bi-download"></i> Export CSV</a>
                                                </div>
                                                <div class="col-md-2 d-grid">
                                                    <a class="btn btn-outline-secondary btn-sm" href="?tab=daily&date=<?php echo urlencode($todayIst); ?>&daily_range=today">Reset</a>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-8">
                                    <div class="card hr-card h-100">
                                        <div class="card-header bg-light fw-semibold d-flex align-items-center gap-2">
                                            <i class="bi bi-calendar2-plus"></i>
                                            <span>Monthly Bulk Entry (Single Agent)</span>
                                        </div>
                                        <div class="card-body">
                                            <form method="post" class="row g-2 align-items-end">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="bulk_month">
                                                <div class="col-md-5">
                                                    <label class="form-label small text-muted">Employee</label>
                                                    <select class="form-select form-select-sm" name="user_id" required>
                                                        <option value="">Select agent</option>
                                                        <?php foreach ($attendanceUsers as $uRow): ?>
                                                            <option value="<?php echo (int)($uRow['id'] ?? 0); ?>">
                                                                <?php
                                                                    $nm = (string)($uRow['full_name'] ?? '');
                                                                    $role = (string)($uRow['role'] ?? '');
                                                                    echo htmlspecialchars($nm . ($role !== '' ? ' (' . $role . ')' : ''));
                                                                ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small text-muted">Month</label>
                                                    <input type="month" class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Set As</label>
                                                    <select class="form-select form-select-sm" name="status">
                                                        <option value="Full Day" selected>Full Day (Shift)</option>
                                                        <option value="Half Day">Half Day</option>
                                                        <option value="Paid Leave">Paid Leave</option>
                                                        <option value="Unpaid Leave">Unpaid Leave</option>
                                                        <option value="Absent">Absent</option>
                                                        <option value="NCNS">NCNS</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-8 d-flex flex-wrap gap-3">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="working_only" value="1" id="workingOnly" checked>
                                                        <label class="form-check-label small" for="workingOnly">Working days only</label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="overwriteDays">
                                                        <label class="form-check-label small" for="overwriteDays">Overwrite existing</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-check2-circle"></i> Apply</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Employees', (string)number_format($dailyEmployeeCount), 'bi-people', 'Filtered users'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Present Days', (string)number_format($dailyPresentCount), 'bi-check2-circle', '', 'text-success'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Half Days', (string)number_format($dailyHalfCount), 'bi-hourglass-split', '', 'text-warning'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Leave Days', (string)number_format($dailyLeaveCount), 'bi-calendar2-week', 'Paid + unpaid'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Absent Days', (string)number_format($dailyAbsentCount), 'bi-x-circle', 'Pending, holiday, and weekly off excluded', 'text-danger'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Pending Days', (string)number_format($dailyPendingCount), 'bi-hourglass', 'Blank today/future working days'); ?></div>
                                <?php if ($dailySingleDay): ?>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Weekly Off', (string)number_format($dailyWeekOffCount), 'bi-calendar-week', '', 'text-info'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Holiday', (string)number_format($dailyHolidayCount), 'bi-balloon', '', 'text-info'); ?></div>
                                <?php endif; ?>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('NCNS', (string)number_format($dailyNcnsCount), 'bi-exclamation-triangle', '', 'text-danger'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Late Days', (string)number_format($dailyLateCount), 'bi-alarm', '', 'text-warning'); ?></div>
                                <div class="col-6 col-md-4 col-xl-2"><?php hrKpi('Work Logged', htmlspecialchars(fmtMinutesHHMM($dailyWorkMinutes)), 'bi-clock-history', 'HH:MM'); ?></div>
                            </div>

                            <div class="row g-3">
                                <div class="col-lg-9">
                                    <div class="hr-admin-table-shell">
                                        <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle hr-table hr-userlist-table">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Role</th>
                                                    <th>Department</th>
                                                    <th class="text-end">Working</th>
                                                    <th class="text-end text-success">Present</th>
                                                    <th class="text-end text-warning">Half</th>
                                                    <th class="text-end">Leave</th>
                                                    <th class="text-end text-danger">Absent</th>
                                                    <th class="text-end text-primary">Pending</th>
                                                    <th class="text-end text-danger">NCNS</th>
                                                    <th class="text-end">Late</th>
                                                    <th class="text-end">Work</th>
                                                    <th class="text-end">Attendance</th>
                                                    <th>Status</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($dailySummaryRows as $r): ?>
                                                    <?php
                                                        $st = (string)($r['primary_status'] ?? 'No Record');
                                                        $stCls = adminAttendanceSummaryBadgeClass($st);
                                                        $jt = trim((string)($r['job_title'] ?? ''));
                                                        $employeeId = trim((string)($r['employee_id'] ?? ''));
                                                        $leaveDays = (int)($r['paid_leave_days'] ?? 0) + (int)($r['unpaid_leave_days'] ?? 0);
                                                        $employeeHref = '?tab=employee&date=' . urlencode($dailyEnd) . '&view=month&emp_id=' . (int)($r['user_id'] ?? 0);
                                                    ?>
                                                    <tr>
                                                        <td class="hr-userlist-name">
                                                            <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></div>
                                                            <div class="small text-muted">
                                                                <?php
                                                                    $metaParts = [];
                                                                    if ($employeeId !== '') $metaParts[] = $employeeId;
                                                                    if ($jt !== '') $metaParts[] = $jt;
                                                                    echo htmlspecialchars(!empty($metaParts) ? implode(' | ', $metaParts) : 'No employee metadata');
                                                                ?>
                                                            </div>
                                                        </td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['role'] ?? '')); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['department'] ?? '')); ?></td>
                                                        <td class="text-end"><?php echo number_format((int)($r['working_days'] ?? 0)); ?></td>
                                                        <td class="text-end text-success"><?php echo number_format((int)($r['present_days'] ?? 0)); ?></td>
                                                        <td class="text-end text-warning"><?php echo number_format((int)($r['half_days'] ?? 0)); ?></td>
                                                        <td class="text-end"><?php echo number_format($leaveDays); ?></td>
                                                        <td class="text-end text-danger"><?php echo number_format((int)($r['absent_days'] ?? 0)); ?></td>
                                                        <td class="text-end text-primary"><?php echo number_format((int)($r['pending_days'] ?? 0)); ?></td>
                                                        <td class="text-end text-danger"><?php echo number_format((int)($r['ncns_days'] ?? 0)); ?></td>
                                                        <td class="text-end"><?php echo number_format((int)($r['late_days'] ?? 0)); ?></td>
                                                        <td class="text-end font-monospace"><?php echo htmlspecialchars(fmtMinutesHHMM((int)($r['work_minutes'] ?? 0))); ?></td>
                                                        <td class="text-end">
                                                            <span class="badge bg-light text-dark border"><?php echo number_format((float)($r['attendance_percent'] ?? 0), 1); ?>%</span>
                                                        </td>
                                                        <td><span class="badge <?php echo $stCls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                                                        <td class="text-end">
                                                            <div class="d-flex justify-content-end gap-2">
                                                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($employeeHref); ?>"><i class="bi bi-person-lines-fill"></i></a>
                                                                <?php if ($dailySingleDay): ?>
                                                                    <button class="btn btn-outline-primary btn-sm" type="button" data-admin-open-detail data-user-id="<?php echo (int)($r['user_id'] ?? 0); ?>" data-date="<?php echo htmlspecialchars($dailyStart); ?>"><i class="bi bi-eye"></i></button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($dailySummaryRows)): ?>
                                                    <tr><td colspan="15" class="text-center text-muted py-4">No attendance users matched the selected filters.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-3">
                                    <div class="card hr-card">
                                        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-calendar-event me-1"></i> Range Snapshot</span>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($dailyRangeLabel); ?></span>
                                        </div>
                                        <div class="card-body">
                                            <div class="small text-muted mb-3">Range: <span class="font-monospace"><?php echo htmlspecialchars($dailyStart); ?></span> to <span class="font-monospace"><?php echo htmlspecialchars($dailyEnd); ?></span></div>
                                            <div class="hr-userlist-stats mb-3">
                                                <span class="badge bg-light text-dark border">Employees <?php echo number_format($dailyEmployeeCount); ?></span>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Absent <?php echo number_format($dailyAbsentCount); ?></span>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Pending <?php echo number_format($dailyPendingCount); ?></span>
                                                <?php if ($dailySingleDay): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Weekly Off <?php echo number_format($dailyWeekOffCount); ?></span>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Holiday <?php echo number_format($dailyHolidayCount); ?></span>
                                                <?php endif; ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Late <?php echo number_format($dailyLateCount); ?></span>
                                            </div>
                                            <div class="small text-muted mb-2">US holidays in selected range</div>
                                            <?php if (empty($dailyHolidayMap)): ?>
                                                <div class="text-muted">No holidays in this range.</div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm mb-0 hr-table">
                                                        <tbody>
                                                            <?php foreach ($dailyHolidayMap as $holidayDate => $holidayName): ?>
                                                                <tr>
                                                                    <td class="font-monospace"><?php echo htmlspecialchars((string)$holidayDate); ?></td>
                                                                    <td class="text-muted small"><?php echo htmlspecialchars((string)$holidayName); ?></td>
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
    </div>
</div>

<div class="modal fade" id="hrAdminDayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div class="modal-title fw-semibold">Team Attendance</div>
          <div class="text-muted small font-monospace" id="hrAdminDayModalDate"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle hr-table mb-0">
            <thead>
              <tr>
                <th>User</th>
                <th>Job Title</th>
                <th>Punch In</th>
                <th>Punch Out</th>
                <th class="text-end">Work</th>
                <th class="text-end">Late</th>
                <th>Status</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody id="hrAdminDayModalBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="offcanvas offcanvas-end hr-admin-detail-drawer" tabindex="-1" id="hrAdminDetailDrawer" aria-labelledby="hrAdminDetailDrawerLabel">
  <div class="offcanvas-header">
    <div>
      <div class="offcanvas-title fw-semibold" id="hrAdminDetailDrawerLabel">Attendance Details</div>
      <div class="text-muted small font-monospace" id="hrAdminDetailMeta"></div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="row g-2">
      <div class="col-12">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Status</div>
          <div class="hr-kpi-value" id="hrAdminDetailStatus">—</div>
          <div class="hr-kpi-sub" id="hrAdminDetailShift">—</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Punch In</div>
          <div class="hr-kpi-value" id="hrAdminDetailIn">—</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Punch Out</div>
          <div class="hr-kpi-value" id="hrAdminDetailOut">—</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Worked</div>
          <div class="hr-kpi-value" id="hrAdminDetailWork">00:00</div>
          <div class="hr-kpi-sub" id="hrAdminDetailBreak">Break 00:00</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Overtime</div>
          <div class="hr-kpi-value" id="hrAdminDetailOt">00:00</div>
          <div class="hr-kpi-sub" id="hrAdminDetailLate">Late 0 min</div>
        </div>
      </div>
    </div>

    <div class="card hr-card mt-3">
      <div class="card-header bg-light fw-semibold"><i class="bi bi-pencil-square me-1"></i> Edit (Audit Logged)</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label small text-muted">Attendance / Current Status</label>
            <select class="form-select form-select-sm" id="hrAdminEditStatus">
              <?php foreach (hrAttendanceAdminStatusOptions() as $opt): ?>
                <option value="<?php echo htmlspecialchars((string)$opt['value']); ?>"><?php echo htmlspecialchars((string)$opt['label']); ?></option>
              <?php endforeach; ?>
            </select>
            
          </div>
          <div class="col-6">
            <label class="form-label small text-muted">Punch In (HH:MM)</label>
            <input class="form-control form-control-sm font-monospace" id="hrAdminEditIn" placeholder="09:30">
          </div>
          <div class="col-6">
            <label class="form-label small text-muted">Punch Out (HH:MM)</label>
            <input class="form-control form-control-sm font-monospace" id="hrAdminEditOut" placeholder="18:30">
          </div>
          <div class="col-12">
            <label class="form-label small text-muted">Remarks</label>
            <textarea class="form-control form-control-sm" id="hrAdminEditRemarks" rows="2" placeholder="Reason / note for audit trail..."></textarea>
          </div>
          <div class="col-12">
            <div class="alert alert-danger py-2 px-3 mb-0 d-none" id="hrAdminEditAlert"></div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary btn-sm w-100" type="button" id="hrAdminEditSave"><i class="bi bi-check2-circle"></i> Save Changes</button>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-3">
      <div class="fw-semibold mb-2"><i class="bi bi-clock-history me-1"></i> Timeline</div>
      <div class="list-group hr-admin-list-group" id="hrAdminDetailTimeline"></div>
    </div>

    <div class="mt-3">
      <div class="fw-semibold mb-2"><i class="bi bi-shield-check me-1"></i> Audit Trail</div>
      <div class="list-group hr-admin-list-group" id="hrAdminDetailAudit"></div>
    </div>
  </div>
</div>

<script id="hr-admin-init" type="application/json"><?php
  echo json_encode([
    'tab' => $tab,
    'view' => $view,
    'date' => $date,
    'empId' => (int)$empId,
    'csrf' => (string)($_SESSION['csrf_token'] ?? ''),
  ], JSON_UNESCAPED_SLASHES);
?></script>
<script>
(() => {
  const initEl = document.getElementById('hr-admin-init');
  if (!initEl) return;
  let init = null;
  try { init = JSON.parse(initEl.textContent || '{}'); } catch { init = null; }
  if (!init) return;

  const statusClass = (k) => {
    const map = {
      present: 'hr-att-status-present',
      late: 'hr-att-status-late',
      half: 'hr-att-status-half',
      absent: 'hr-att-status-absent',
      ncns: 'hr-att-status-ncns',
      na: 'hr-att-status-na',
      pending: 'hr-att-status-pending',
      holiday: 'hr-att-status-holiday',
      weekoff: 'hr-att-status-weekoff',
      leave: 'hr-att-status-leave',
      missing: 'hr-att-status-missing',
    };
    return map[k] || 'hr-att-status-absent';
  };

  const fetchJson = async (url, opts) => {
    const cfg = opts ? { ...opts } : {};
    const headers = new Headers(cfg.headers || {});
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    cfg.headers = headers;
    if (!('credentials' in cfg)) cfg.credentials = 'same-origin';
    const target = (url === '' || url == null) ? window.location.href : url;
    const res = await fetch(target, cfg);
    const text = await res.text();
    let data = null;
    if (text !== '') {
      try { data = JSON.parse(text); } catch { data = null; }
    }
    if (!res.ok) {
      return { ok: false, error: (data && data.error) ? String(data.error) : `Request failed (${res.status})` };
    }
    if (!data) return { ok: false, error: 'Invalid server response' };
    return data;
  };

  const teamCalEl = document.getElementById('hrAdminTeamCalendar');
  const empCalEl = document.getElementById('hrAdminEmpCalendar');
  const rangeEl = document.getElementById('hrAdminRange');
  const empRangeEl = document.getElementById('hrAdminEmpRange');
  const dateInput = document.getElementById('hrAdminDate');
  const empSelect = document.getElementById('hrAdminEmpSelect');
  const empSearch = document.getElementById('hrAdminEmpSearch');
  const empTitleEl = document.getElementById('hrAdminEmpTitle');

  const cleanupPreviewBtn = document.getElementById('hrCleanupPreviewBtn');
  const cleanupDeleteBtn = document.getElementById('hrCleanupDeleteBtn');
  const cleanupMsgEl = document.getElementById('hrCleanupMsg');
  const cleanupWrapEl = document.getElementById('hrCleanupTableWrap');

  const dayModalEl = document.getElementById('hrAdminDayModal');
  const dayModal = dayModalEl ? new bootstrap.Modal(dayModalEl) : null;
  const dayModalBody = document.getElementById('hrAdminDayModalBody');
  const dayModalDate = document.getElementById('hrAdminDayModalDate');

  const detailEl = document.getElementById('hrAdminDetailDrawer');
  const detail = detailEl ? new bootstrap.Offcanvas(detailEl) : null;

  const renderCleanupPreview = (j) => {
    if (!cleanupWrapEl || !cleanupMsgEl || !cleanupDeleteBtn) return;
    const days = parseInt(j.days || 0, 10) || 0;
    const users = parseInt(j.users || 0, 10) || 0;
    const states = parseInt(j.states || 0, 10) || 0;
    const audits = parseInt(j.audits || 0, 10) || 0;
    cleanupMsgEl.textContent = `Pre-join rows found: Days ${days}, Users ${users}, States ${states}, Audits ${audits}.`;

    const top = Array.isArray(j.top) ? j.top : [];
    const rowsHtml = top.map(r => {
      const nm = String(r.full_name || '');
      const uid = parseInt(r.user_id || 0, 10) || 0;
      const join = String(r.join_date || '');
      const c = parseInt(r.rows_count || 0, 10) || 0;
      const fd = String(r.first_date || '');
      const ld = String(r.last_date || '');
      return `<tr>
        <td class="fw-semibold">${nm}</td>
        <td class="font-monospace">${uid}</td>
        <td class="font-monospace">${join}</td>
        <td class="text-end">${c}</td>
        <td class="font-monospace">${fd}</td>
        <td class="font-monospace">${ld}</td>
      </tr>`;
    }).join('');

    cleanupWrapEl.style.display = '';
    cleanupWrapEl.innerHTML = `
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>User</th>
            <th>User ID</th>
            <th>Join Date</th>
            <th class="text-end">Rows</th>
            <th>First</th>
            <th>Last</th>
          </tr>
        </thead>
        <tbody>
          ${rowsHtml || `<tr><td colspan="6" class="text-center text-muted py-3">No pre-joining attendance rows found.</td></tr>`}
        </tbody>
      </table>
    `;

    cleanupDeleteBtn.disabled = days <= 0;
  };

  if (cleanupPreviewBtn) {
    cleanupPreviewBtn.addEventListener('click', async () => {
      if (cleanupMsgEl) cleanupMsgEl.textContent = 'Loading preview...';
      if (cleanupDeleteBtn) cleanupDeleteBtn.disabled = true;
      if (cleanupWrapEl) { cleanupWrapEl.style.display = 'none'; cleanupWrapEl.innerHTML = ''; }
      const j = await fetchJson('?ajax=cleanup_prejoin_preview');
      if (!j || !j.ok) {
        if (cleanupMsgEl) cleanupMsgEl.textContent = (j && j.error) ? String(j.error) : 'Preview failed.';
        return;
      }
      renderCleanupPreview(j);
    });
  }

  if (cleanupDeleteBtn) {
    cleanupDeleteBtn.addEventListener('click', async () => {
      const ok = window.confirm('Delete all pre-joining attendance rows (and their states/audit logs)? This cannot be undone.');
      if (!ok) return;
      if (cleanupMsgEl) cleanupMsgEl.textContent = 'Deleting...';
      cleanupDeleteBtn.disabled = true;
      const body = new URLSearchParams();
      body.set('action', 'cleanup_prejoin_delete');
      body.set('csrf_token', init.csrf || '');
      const j = await fetchJson('', { method: 'POST', body });
      if (!j || !j.ok) {
        if (cleanupMsgEl) cleanupMsgEl.textContent = (j && j.error) ? String(j.error) : 'Delete failed.';
        return;
      }
      const d = j.deleted || {};
      cleanupMsgEl.textContent = `Deleted: Days ${parseInt(d.days || 0, 10) || 0}, States ${parseInt(d.states || 0, 10) || 0}, Audits ${parseInt(d.audits || 0, 10) || 0}.`;
      if (cleanupWrapEl) cleanupWrapEl.style.display = 'none';
      const p = await fetchJson('?ajax=cleanup_prejoin_preview');
      if (p && p.ok) renderCleanupPreview(p);
    });
  }

  let currentDetail = { userId: 0, date: '', csrf: init.csrf || '' };

  const openDetail = async (userId, dateStr) => {
    if (!detail) return;
    currentDetail = { userId: parseInt(userId || 0, 10) || 0, date: String(dateStr || ''), csrf: init.csrf || '' };
    const j = await fetchJson(`?ajax=admin_detail&user_id=${encodeURIComponent(currentDetail.userId)}&date=${encodeURIComponent(currentDetail.date)}`);
    if (!j || !j.ok) return;
    if (j.csrf) init.csrf = String(j.csrf);
    currentDetail.csrf = init.csrf || '';
    document.getElementById('hrAdminDetailMeta').textContent = `User ${currentDetail.userId} · ${j.date}`;
    const detailStatusText = (j.attendance && j.attendance.status) ? String(j.attendance.status) : '—';
    const detailStateText = (j.attendance && j.attendance.current_state_label) ? String(j.attendance.current_state_label) : '';
    document.getElementById('hrAdminDetailStatus').textContent = detailStateText && detailStatusText === 'In Progress' ? `${detailStatusText} · ${detailStateText}` : detailStatusText;
    const sh = j.shift || {};
    document.getElementById('hrAdminDetailShift').textContent = (sh.name ? sh.name : '—') + (sh.start && sh.end ? ` · ${sh.start}–${sh.end}` : '');
    document.getElementById('hrAdminDetailIn').textContent = (j.attendance && j.attendance.punch_in) ? j.attendance.punch_in : '—';
    document.getElementById('hrAdminDetailOut').textContent = (j.attendance && j.attendance.punch_out) ? j.attendance.punch_out : '—';
    document.getElementById('hrAdminDetailWork').textContent = (j.attendance && j.attendance.work_hhmm) ? j.attendance.work_hhmm : '00:00';
    document.getElementById('hrAdminDetailBreak').textContent = `Break ${(j.attendance && j.attendance.break_hhmm) ? j.attendance.break_hhmm : '00:00'}`;
    document.getElementById('hrAdminDetailOt').textContent = (j.attendance && j.attendance.overtime_hhmm) ? j.attendance.overtime_hhmm : '00:00';
    document.getElementById('hrAdminDetailLate').textContent = `Late ${(j.attendance && Number.isFinite(j.attendance.late_min)) ? j.attendance.late_min : 0} min`;

    const statusSel = document.getElementById('hrAdminEditStatus');
    const inEl = document.getElementById('hrAdminEditIn');
    const outEl = document.getElementById('hrAdminEditOut');
    if (statusSel && j.attendance && j.attendance.edit_selection) statusSel.value = String(j.attendance.edit_selection);
    if (inEl) inEl.value = (j.attendance && j.attendance.punch_in) ? String(j.attendance.punch_in).slice(11,16) : '';
    if (outEl) outEl.value = (j.attendance && j.attendance.punch_out) ? String(j.attendance.punch_out).slice(11,16) : '';

    const tl = document.getElementById('hrAdminDetailTimeline');
    tl.innerHTML = '';
    const rows = Array.isArray(j.timeline) ? j.timeline : [];
    if (!rows.length) {
      const it = document.createElement('div');
      it.className = 'list-group-item text-muted';
      it.textContent = 'No events.';
      tl.appendChild(it);
    } else {
      rows.forEach((e) => {
        const it = document.createElement('div');
        it.className = 'list-group-item d-flex justify-content-between align-items-center gap-2';
        it.innerHTML = `<div>${String(e.label || '')}</div><div class="text-muted small font-monospace">${String(e.at || '')}</div>`;
        tl.appendChild(it);
      });
    }

    const audit = document.getElementById('hrAdminDetailAudit');
    audit.innerHTML = '';
    const arows = Array.isArray(j.audit) ? j.audit : [];
    if (!arows.length) {
      const it = document.createElement('div');
      it.className = 'list-group-item text-muted';
      it.textContent = 'No audit entries.';
      audit.appendChild(it);
    } else {
      arows.forEach((a) => {
        const it = document.createElement('div');
        it.className = 'list-group-item';
        const who = a.created_by_name ? String(a.created_by_name) : (a.created_by ? ('User ' + a.created_by) : 'System');
        const when = a.created_at ? String(a.created_at) : '';
        const field = a.field_name ? String(a.field_name) : '';
        const ov = a.old_value !== null ? String(a.old_value) : '';
        const nv = a.new_value !== null ? String(a.new_value) : '';
        it.innerHTML =
          `<div class="d-flex justify-content-between align-items-center gap-2">` +
            `<div class="fw-semibold">${String(a.action || '')}${field ? (' · ' + field) : ''}</div>` +
            `<div class="text-muted small font-monospace">${when}</div>` +
          `</div>` +
          `<div class="small text-muted mt-1">${who}${a.remarks ? (' · ' + String(a.remarks)) : ''}</div>` +
          `<div class="small font-monospace mt-1">${ov ? ('Old: ' + ov) : ''}${ov && nv ? ' · ' : ''}${nv ? ('New: ' + nv) : ''}</div>`;
        audit.appendChild(it);
      });
    }

    detail.show();
  };

  const fmtLocalDate = (dt) => {
    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, '0');
    const d = String(dt.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  const renderTeamMonth = (start, end, days) => {
    const byDate = new Map(days.map(d => [d.date, d]));
    const startDate = new Date(start + 'T00:00:00');
    const first = new Date(startDate.getTime());
    const dow = (first.getDay() + 6) % 7;
    first.setDate(first.getDate() - dow);

    const endDate = new Date(end + 'T00:00:00');
    const last = new Date(endDate.getTime());
    const lastDow = (last.getDay() + 6) % 7;
    last.setDate(last.getDate() + (6 - lastDow));

    const weekdays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    const grid = document.createElement('div');
    grid.className = 'hr-att-cal';
    weekdays.forEach((w) => {
      const h = document.createElement('div');
      h.className = 'hr-att-cal-head';
      h.textContent = w;
      grid.appendChild(h);
    });

    const cur = new Date(first.getTime());
    while (cur <= last) {
      const ds = fmtLocalDate(cur);
      const d = byDate.get(ds);
      const inRange = ds >= start && ds <= end;
      const cell = document.createElement('button');
      cell.type = 'button';
      cell.className = 'hr-att-cal-cell';
      if (!inRange) cell.classList.add('is-out');
      if (d) cell.classList.add(statusClass(d.color));
      cell.dataset.date = ds;
      const top = document.createElement('div');
      top.className = 'hr-att-cal-top';
      top.innerHTML = `<div class="hr-att-cal-day">${cur.getDate()}</div><div class="hr-att-cal-status">${d ? (d.label || '') : ''}</div>`;
      const meta = document.createElement('div');
      meta.className = 'hr-att-cal-meta';
      if (d) {
        meta.innerHTML =
          `<div class="hr-att-cal-line">P ${d.full + d.half + d.in_progress} · A ${d.absent} · L ${d.late}</div>` +
          `<div class="hr-att-cal-line hr-att-cal-strong">Missing ${d.missing} · OT ${d.overtime}</div>`;
      }
      cell.appendChild(top);
      cell.appendChild(meta);
      cell.addEventListener('click', async () => {
        if (!dayModal) return;
        const j = await fetchJson(`?ajax=team_day_list&date=${encodeURIComponent(ds)}`);
        if (!j || !j.ok) return;
        if (dayModalDate) dayModalDate.textContent = j.date || ds;
        if (dayModalBody) {
          dayModalBody.innerHTML = '';
          (j.rows || []).forEach((r) => {
            const tr = document.createElement('tr');
            const getStatusBadgeClass = (status) => {
              const s = String(status || '').toLowerCase();
              if (s.includes('holiday')) return 'bg-info-subtle text-info-emphasis border';
              if (s.includes('weekly off')) return 'bg-info-subtle text-info-emphasis border';
              if (s.includes('pending')) return 'bg-primary-subtle text-primary border';
              if (s.includes('full')) return 'bg-success';
              if (s.includes('half')) return 'bg-warning text-dark';
              if (s.includes('leave')) return 'bg-info-subtle text-info-emphasis border';
              if (s.includes('missing punch')) return 'bg-warning text-dark';
              if (s.includes('ncns')) return 'bg-danger';
              if (s.includes('absent')) return 'bg-danger';
              if (s.includes('in progress')) return 'bg-secondary';
              return 'bg-secondary';
            };
            tr.innerHTML =
              `<td class="fw-semibold">${String(r.full_name || '')}</td>` +
              `<td class="text-muted small">${String(r.job_title || '')}</td>` +
              `<td class="font-monospace">${r.punch_in ? String(r.punch_in) : '—'}</td>` +
              `<td class="font-monospace">${r.punch_out ? String(r.punch_out) : '—'}</td>` +
              `<td class="text-end font-monospace">${String(r.work_hhmm || '00:00')}</td>` +
              `<td class="text-end">${String(r.late_min || 0)}</td>` +
              `<td><span class="badge ${getStatusBadgeClass(r.status)}">${String(r.status || '')}</span></td>` +
              `<td class="text-end"><button class="btn btn-outline-primary btn-sm" type="button" data-open-detail="${r.user_id}" data-date="${ds}"><i class="bi bi-eye"></i></button></td>`;
            dayModalBody.appendChild(tr);
          });
        }
        dayModal.show();
        setTimeout(() => {
          document.querySelectorAll('[data-open-detail]').forEach((btn) => {
            btn.addEventListener('click', () => {
              const uid = btn.getAttribute('data-open-detail');
              const dd = btn.getAttribute('data-date');
              try { bootstrap.Modal.getOrCreateInstance(dayModalEl).hide(); } catch (e) {}
              openDetail(uid, dd);
            });
          });
        }, 50);
      });
      grid.appendChild(cell);
      cur.setDate(cur.getDate() + 1);
    }
    teamCalEl.innerHTML = '';
    teamCalEl.appendChild(grid);
  };

  const renderEmployeeMonth = (start, end, days) => {
    const byDate = new Map(days.map(d => [d.date, d]));
    const startDate = new Date(start + 'T00:00:00');
    const first = new Date(startDate.getTime());
    const dow = (first.getDay() + 6) % 7;
    first.setDate(first.getDate() - dow);

    const endDate = new Date(end + 'T00:00:00');
    const last = new Date(endDate.getTime());
    const lastDow = (last.getDay() + 6) % 7;
    last.setDate(last.getDate() + (6 - lastDow));

    const weekdays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    const grid = document.createElement('div');
    grid.className = 'hr-att-cal';
    weekdays.forEach((w) => {
      const h = document.createElement('div');
      h.className = 'hr-att-cal-head';
      h.textContent = w;
      grid.appendChild(h);
    });

    const cur = new Date(first.getTime());
    while (cur <= last) {
      const ds = fmtLocalDate(cur);
      const d = byDate.get(ds);
      const inRange = ds >= start && ds <= end;
      const cell = document.createElement('button');
      cell.type = 'button';
      cell.className = 'hr-att-cal-cell';
      if (!inRange) cell.classList.add('is-out');
      if (d) cell.classList.add(statusClass(d.color));
      cell.dataset.date = ds;
      const top = document.createElement('div');
      top.className = 'hr-att-cal-top';
      const statusText = d ? ((d.is_holiday && d.holiday_name) ? d.holiday_name : (d.status || '')) : '';
      top.innerHTML = `<div class="hr-att-cal-day">${cur.getDate()}</div><div class="hr-att-cal-status">${statusText}</div>`;
      const meta = document.createElement('div');
      meta.className = 'hr-att-cal-meta';
      if (d) {
        meta.innerHTML =
          `<div class="hr-att-cal-line">${(d.punch_in || '—')} → ${(d.punch_out || '—')}</div>` +
          `<div class="hr-att-cal-line hr-att-cal-strong">Work ${d.work_hhmm || '00:00'}</div>` +
          `<div class="hr-att-cal-line">${d.shift || ''}</div>`;
      }
      cell.appendChild(top);
      cell.appendChild(meta);
      cell.addEventListener('click', () => openDetail(init.empId, ds));
      grid.appendChild(cell);
      cur.setDate(cur.getDate() + 1);
    }
    empCalEl.innerHTML = '';
    empCalEl.appendChild(grid);
  };

  const loadTeam = async () => {
    if (!teamCalEl) return;
    const j = await fetchJson(`?ajax=team_calendar&view=${encodeURIComponent(init.view)}&date=${encodeURIComponent(init.date)}`);
    if (!j || !j.ok) return;
    if (rangeEl) rangeEl.textContent = `${j.start} → ${j.end}`;
    renderTeamMonth(j.start, j.end, Array.isArray(j.days) ? j.days : []);
  };

  const loadEmployee = async () => {
    if (!empCalEl) return;
    const j = await fetchJson(`?ajax=employee_calendar&user_id=${encodeURIComponent(init.empId)}&view=${encodeURIComponent(init.view)}&date=${encodeURIComponent(init.date)}`);
    if (!j || !j.ok) return;
    if (empRangeEl) empRangeEl.textContent = `${j.start} → ${j.end}`;
    renderEmployeeMonth(j.start, j.end, Array.isArray(j.days) ? j.days : []);
  };

  if (dateInput) {
    dateInput.addEventListener('change', () => {
      const d = dateInput.value;
      if (!d) return;
      const params = new URLSearchParams(window.location.search);
      params.set('date', d);
      window.location.search = params.toString();
    });
  }

  if (empSelect) {
    empSelect.addEventListener('change', () => {
      const v = empSelect.value;
      const params = new URLSearchParams(window.location.search);
      params.set('tab', 'employee');
      params.set('emp_id', v);
      if (typeof updateEmpTitle === 'function') updateEmpTitle();
      if (init && init.tab === 'employee') {
        init.empId = parseInt(v || 0, 10) || 0;
        history.replaceState(null, '', `${window.location.pathname}?${params.toString()}`);
        loadEmployee();
      } else {
        window.location.search = params.toString();
      }
    });
  }

  if (empSearch && empSelect) {
    const filterOptions = () => {
      const q = String(empSearch.value || '').trim().toLowerCase();
      const opts = Array.from(empSelect.options || []);
      let firstVisible = null;
      opts.forEach((o) => {
        const t = String(o.text || '').toLowerCase();
        const ok = q === '' || t.includes(q);
        o.hidden = !ok;
        if (ok && !firstVisible && o.value) firstVisible = o;
      });
      return firstVisible;
    };
    empSearch.addEventListener('input', () => { filterOptions(); });
    empSearch.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const first = filterOptions();
      if (first) {
        empSelect.value = first.value;
        empSelect.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    filterOptions();
  }

  const updateEmpTitle = () => {
    if (!empTitleEl || !empSelect) return;
    const opt = empSelect.options[empSelect.selectedIndex];
    if (!opt) { empTitleEl.textContent = 'Employee Calendar'; return; }
    const name = String(opt.getAttribute('data-name') || '').trim();
    const code = String(opt.getAttribute('data-code') || '').trim();
    empTitleEl.textContent = name !== '' ? (`${name}${code !== '' ? ' · ' + code : ''}`) : 'Employee Calendar';
  };
  updateEmpTitle();

  document.querySelectorAll('[data-admin-open-detail]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const uid = btn.getAttribute('data-user-id');
      const dd = btn.getAttribute('data-date');
      openDetail(uid, dd);
    });
  });

  const saveBtn = document.getElementById('hrAdminEditSave');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      const alertEl = document.getElementById('hrAdminEditAlert');
      const showAlert = (type, msg) => {
        if (!alertEl) return;
        alertEl.className = `alert alert-${type} py-2 px-3 mb-0`;
        alertEl.textContent = String(msg || '');
        alertEl.classList.remove('d-none');
      };
      const hideAlert = () => { if (alertEl) alertEl.classList.add('d-none'); };
      hideAlert();
      if (!currentDetail.userId || !currentDetail.date) {
        showAlert('warning', 'Select an employee date first.');
        return;
      }
      const st = document.getElementById('hrAdminEditStatus').value;
      const pi = document.getElementById('hrAdminEditIn').value;
      const po = document.getElementById('hrAdminEditOut').value;
      const rm = document.getElementById('hrAdminEditRemarks').value;
      const body = new URLSearchParams();
      body.set('csrf_token', init.csrf || '');
      body.set('action', 'admin_update_day_ajax');
      body.set('user_id', String(currentDetail.userId || 0));
      body.set('work_date', String(currentDetail.date || ''));
      body.set('status', String(st || ''));
      body.set('punch_in', String(pi || ''));
      body.set('punch_out', String(po || ''));
      body.set('remarks', String(rm || ''));

      let j = null;
      try {
        j = await fetchJson(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
      } catch (e) {
        showAlert('danger', 'Save failed.');
        return;
      }
      if (!j || !j.ok) {
        showAlert('danger', (j && j.error) ? j.error : 'Save failed.');
        return;
      }
      showAlert('success', 'Saved.');
      openDetail(currentDetail.userId, currentDetail.date);
      if (init.tab === 'team') loadTeam();
      if (init.tab === 'employee') loadEmployee();
    });
  }

  if (init.tab === 'team') loadTeam();
  if (init.tab === 'employee') loadEmployee();
})();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
