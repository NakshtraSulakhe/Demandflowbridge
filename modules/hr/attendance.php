<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.attendance.page', ['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();
$user = getCurrentUser();

$today = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$message = '';
$messageType = 'success';
$autoCloseStart = date('Y-m-d', strtotime($today . ' -2 day'));
hrAutoCloseOpenAttendanceForUserRange((int)($user['id'] ?? 0), $autoCloseStart, $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'punch_in') {
            $ok = punchIn((int)$user['id']);
            $message = $ok ? 'Punched in.' : 'Failed to punch in.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'punch_out') {
            $ok = punchOut((int)$user['id']);
            $message = $ok ? 'Punched out.' : 'Failed to punch out.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'start_state') {
            $state = (string)($_POST['state'] ?? '');
            $res = startAttendanceState((int)$user['id'], $state);
            $ok = !empty($res['ok']);
            $message = $ok ? 'Status updated.' : (string)($res['error'] ?? 'Failed to update status.');
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'end_state') {
            $ok = endAttendanceState((int)$user['id']);
            $message = $ok ? 'Status ended.' : 'Failed to end status.';
            $messageType = $ok ? 'success' : 'danger';
        }
    }
}

$hasRequestedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']);
$selDate = $hasRequestedDate ? (string)$_GET['date'] : $today;
$view = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'month';
if (!in_array($view, ['month','week','day'], true)) $view = 'month';
$joinDate = hrAttendanceStartDateForUser((int)($user['id'] ?? 0));

function fmtMinutesLabel(int $m): string {
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%dh %02dm', $h, $mm);
}

function fmtMinutesHHMM(int $m): string {
    $m = max(0, (int)$m);
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%02d:%02d', $h, $mm);
}

function attendancePageNormalizeSummaryForPendingToday(array $summary, string $startDate, string $endDate, string $today, bool $pendingToday): array {
    return $pendingToday
        ? hrNormalizeAttendanceSummaryForOpenDay($summary, $startDate, $endDate, $today, 'Pending')
        : $summary;
}

function attendancePageResolveRange(string $selDate, string $view, ?string $joinDate): array {
    if ($joinDate !== null && $selDate < $joinDate) $selDate = $joinDate;

    $rangeStart = $selDate;
    $rangeEnd = $selDate;
    if ($view === 'month') {
        $rangeStart = substr($selDate, 0, 7) . '-01';
        $rangeEnd = date('Y-m-t', strtotime($rangeStart));
    } elseif ($view === 'week') {
        $ts = strtotime($selDate . ' 00:00:00');
        $n = (int)date('N', $ts);
        $rangeStart = date('Y-m-d', strtotime($selDate . ' -' . ($n - 1) . ' day'));
        $rangeEnd = date('Y-m-d', strtotime($rangeStart . ' +6 day'));
    }
    if ($joinDate !== null && $rangeStart < $joinDate) $rangeStart = $joinDate;

    return [$selDate, $rangeStart, $rangeEnd];
}

[$selDate, $rangeStart, $rangeEnd] = attendancePageResolveRange($selDate, $view, $joinDate);

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = strtolower(trim((string)$_GET['ajax']));
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $joinDate = hrAttendanceStartDateForUser($userId);
    if ($ajax === 'calendar') {
        $v = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'month';
        if (!in_array($v, ['month','week','day'], true)) $v = 'month';
        $d = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : $today;
        $start = $d;
        $end = $d;
        if ($v === 'month') {
            $start = substr($d, 0, 7) . '-01';
            $end = date('Y-m-t', strtotime($start));
        } elseif ($v === 'week') {
            $ts = strtotime($d . ' 00:00:00');
            $n = (int)date('N', $ts);
            $start = date('Y-m-d', strtotime($d . ' -' . ($n - 1) . ' day'));
            $end = date('Y-m-d', strtotime($start . ' +6 day'));
        }

        if ($joinDate !== null) {
            if ($end < $joinDate) {
                echo json_encode(['ok' => true, 'view' => $v, 'start' => $start, 'end' => $end, 'days' => []], JSON_UNESCAPED_SLASHES);
                exit;
            }
            if ($start < $joinDate) $start = $joinDate;
        }
        $rows = getAttendanceDaysForUserRange($userId, $start, $end);
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
                    'break_hhmm' => '00:00',
                    'shift' => '',
                    'overtime_hhmm' => '',
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
            $holidayName = $holidaysMap[$cur] ?? '';
            $isHoliday = $holidayName !== '';
            $isWeekend = !$isHoliday && hrIsWeekend($cur);
            $pIn = $r ? (string)($r['punch_in'] ?? '') : '';
            $pOut = $r ? (string)($r['punch_out'] ?? '') : '';
            $status = $r ? (string)($r['status'] ?? 'Absent') : 'Absent';
            $lateMin = $r ? (int)($r['late_minutes'] ?? 0) : 0;
            $states = ($r && (int)($r['id'] ?? 0) > 0 && $pIn !== '' && $pOut === '') ? getAttendanceStatesForDay((int)$r['id']) : [];
            $workMin = $r ? hrAttendanceDisplayWorkMinutes($r, $states) : 0;
            $breakMin = $r ? hrAttendanceDisplayBreakMinutes($r, $states) : 0;
            $shiftName = $r ? (string)($r['shift_name'] ?? '') : '';
            $overtimeMin = hrOvertimeMinutesFromWorking($workMin);

            $statusLabel = hrAttendanceDisplayStatus($r ?: ['status' => $status, 'punch_in' => $pIn, 'punch_out' => $pOut, 'working_minutes' => $workMin, 'break_minutes' => $breakMin], $cur, true, 'US');
            $missingPunch = ($pIn !== '' && $pOut === '') || ($pIn === '' && $pOut !== '') && $cur < $today;
            // Keep Missing Punch label which isn't handled by hrAttendanceDisplayStatus
            if ($statusLabel === 'Absent' && $missingPunch) {
                $statusLabel = 'Missing Punch';
            }

            $colorKey = 'absent';
            if ($statusLabel === 'Holiday') $colorKey = 'holiday';
            elseif ($statusLabel === 'Weekly Off') $colorKey = 'weekoff';
            elseif ($statusLabel === 'Pending') $colorKey = 'pending';
            elseif ($statusLabel === 'Leave Approved') $colorKey = 'leave';
            elseif ($statusLabel === 'Missing Punch') $colorKey = 'missing';
            elseif ($statusLabel === 'NCNS') $colorKey = 'ncns';
            elseif ($statusLabel === 'Half Day') $colorKey = 'half';
            elseif ($statusLabel === 'Full Day' || $statusLabel === 'Present') $colorKey = ($lateMin > 0 ? 'late' : 'present');
            elseif ($statusLabel === 'In Progress') $colorKey = ($lateMin > 0 ? 'late' : 'present');

            $out[] = [
                'date' => $cur,
                'status' => $statusLabel,
                'status_raw' => $status,
                'color' => $colorKey,
                'punch_in' => $pIn !== '' ? hrFormatAttendanceForDisplay($pIn, 'H:i', $r ?: ['work_date' => $cur]) : '',
                'punch_out' => $pOut !== '' ? hrFormatAttendanceForDisplay($pOut, 'H:i', $r ?: ['work_date' => $cur]) : '',
                'work_hhmm' => fmtMinutesHHMM($workMin),
                'break_hhmm' => fmtMinutesHHMM($breakMin),
                'shift' => $shiftName,
                'overtime_hhmm' => $overtimeMin > 0 ? fmtMinutesHHMM($overtimeMin) : '',
                'late_min' => $lateMin,
                'overtime_min' => $overtimeMin,
                'holiday_name' => $holidayName,
                'is_holiday' => $isHoliday,
                'is_weekoff' => $isWeekend,
            ];
            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }
        echo json_encode(['ok' => true, 'view' => $v, 'start' => $start, 'end' => $end, 'days' => $out], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($ajax === 'detail') {
        $d = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : $today;
        if ($joinDate !== null && $d < $joinDate) {
            echo json_encode([
                'ok' => true,
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
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $row = getAttendanceDayWithShift($userId, $d);
        $dayId = $row ? (int)($row['id'] ?? 0) : 0;
        $states = $dayId > 0 ? getAttendanceStatesForDay($dayId) : [];
        $pIn = $row ? (string)($row['punch_in'] ?? '') : '';
        $pOut = $row ? (string)($row['punch_out'] ?? '') : '';
        $workMin = $row ? hrAttendanceDisplayWorkMinutes($row, $states) : 0;
        $breakMin = $row ? hrAttendanceDisplayBreakMinutes($row, $states) : 0;
        $lateMin = $row ? (int)($row['late_minutes'] ?? 0) : 0;
        $status = hrAttendanceDisplayStatus($row ?: ['status' => 'Absent', 'punch_in' => $pIn, 'punch_out' => $pOut, 'working_minutes' => $workMin, 'break_minutes' => $breakMin], $d, true, 'US');
        $shiftName = $row ? (string)($row['shift_name'] ?? '') : '';
        $shiftStart = $row ? substr((string)($row['shift_start_time_full'] ?? ''), 0, 5) : '';
        $shiftEnd = $row ? substr((string)($row['shift_end_time_full'] ?? ''), 0, 5) : '';
        $overtimeMin = hrOvertimeMinutesFromWorking($workMin);

        $timeline = [];
        $detailContext = $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd];
        if ($pIn !== '') {
            $timeline[] = [
                'at' => hrFormatAttendanceForDisplay($pIn, 'Y-m-d H:i', $detailContext),
                'label' => 'Punch In Recorded',
                'ts' => hrResolveAttendanceDateTime($pIn, $detailContext)?->getTimestamp() ?? 0,
            ];
        }
        foreach ($states as $s) {
            $st = (string)($s['state'] ?? '');
            $sa = (string)($s['start_at'] ?? '');
            $ea = (string)($s['end_at'] ?? '');
            $stLabel = hrAttendanceStateDisplayLabel($st);
            if ($st !== '' && $sa !== '') {
                $timeline[] = [
                    'at' => hrFormatAttendanceForDisplay($sa, 'Y-m-d H:i', $detailContext),
                    'label' => $stLabel . ' Started',
                    'ts' => hrResolveAttendanceDateTime($sa, $detailContext)?->getTimestamp() ?? 0,
                ];
            }
            if ($st !== '' && $ea !== '') {
                $timeline[] = [
                    'at' => hrFormatAttendanceForDisplay($ea, 'Y-m-d H:i', $detailContext),
                    'label' => $stLabel . ' Ended',
                    'ts' => hrResolveAttendanceDateTime($ea, $detailContext)?->getTimestamp() ?? 0,
                ];
            }
        }
        if ($pOut !== '') {
            $timeline[] = [
                'at' => hrFormatAttendanceForDisplay($pOut, 'Y-m-d H:i', $detailContext),
                'label' => 'Punch Out Recorded',
                'ts' => hrResolveAttendanceDateTime($pOut, $detailContext)?->getTimestamp() ?? 0,
            ];
        }
        usort($timeline, static function (array $a, array $b): int {
            $tsCompare = ((int)($a['ts'] ?? 0)) <=> ((int)($b['ts'] ?? 0));
            if ($tsCompare !== 0) return $tsCompare;
            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        });
        $timeline = array_values(array_map(static function (array $item): array {
            return [
                'at' => (string)($item['at'] ?? ''),
                'label' => (string)($item['label'] ?? ''),
            ];
        }, $timeline));

        echo json_encode([
            'ok' => true,
            'date' => $d,
            'shift' => ['name' => $shiftName, 'start' => $shiftStart, 'end' => $shiftEnd],
            'attendance' => [
                'status' => $status,
                'punch_in' => $pIn !== '' ? hrFormatAttendanceForDisplay($pIn, 'Y-m-d H:i:s', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]) : '',
                'punch_out' => $pOut !== '' ? hrFormatAttendanceForDisplay($pOut, 'Y-m-d H:i:s', $row ?: ['work_date' => $d, 'shift_start_time_full' => $shiftStart, 'shift_end_time_full' => $shiftEnd]) : '',
                'work_hhmm' => fmtMinutesHHMM($workMin),
                'break_hhmm' => fmtMinutesHHMM($breakMin),
                'overtime_hhmm' => $overtimeMin > 0 ? fmtMinutesHHMM($overtimeMin) : '00:00',
                'late_min' => $lateMin,
            ],
            'timeline' => $timeline,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown request']);
    exit;
}

$attendanceSnapshot = hrGetAttendanceSnapshot((int)($user['id'] ?? 0), $today, true);
$activeWorkDate = (string)($attendanceSnapshot['work_date'] ?? $today);
$activeDay = is_array($attendanceSnapshot['row'] ?? null) ? $attendanceSnapshot['row'] : null;
$open = is_array($attendanceSnapshot['open_state'] ?? null) ? $attendanceSnapshot['open_state'] : null;
$activeStates = is_array($attendanceSnapshot['states'] ?? null) ? $attendanceSnapshot['states'] : [];
$uiToday = $activeWorkDate !== '' ? $activeWorkDate : $today;
if (!$hasRequestedDate && $activeWorkDate !== '') {
    [$selDate, $rangeStart, $rangeEnd] = attendancePageResolveRange($activeWorkDate, $view, $joinDate);
}
$currentState = (string)($attendanceSnapshot['current_state'] ?? 'Off');
$status = (string)($attendanceSnapshot['status'] ?? 'Absent');
$breakMin = (int)($attendanceSnapshot['display_break_minutes'] ?? 0);
$workMin = (int)($attendanceSnapshot['display_work_minutes'] ?? 0);
$lateMin = (int)($attendanceSnapshot['late_minutes'] ?? 0);
$pIn = (string)($attendanceSnapshot['punch_in'] ?? '');
$pOut = (string)($attendanceSnapshot['punch_out'] ?? '');

$statusBadgeClass = 'bg-secondary';
if ($status === 'Full Day') {
    $statusBadgeClass = 'bg-success';
} elseif ($status === 'Half Day') {
    $statusBadgeClass = 'bg-warning text-dark';
} elseif ($status === 'Holiday' || $status === 'Weekly Off') {
    $statusBadgeClass = 'bg-info text-dark';
} elseif ($status === 'Pending') {
    $statusBadgeClass = 'bg-primary';
} elseif ($status === 'Paid Leave') {
    $statusBadgeClass = 'bg-success-subtle text-success border';
} elseif ($status === 'Unpaid Leave') {
    $statusBadgeClass = 'bg-danger-subtle text-danger border';
} elseif ($status === 'NCNS' || $status === 'ncns') {
    $statusBadgeClass = 'bg-danger';
} elseif ($status === 'In Progress') {
    $statusBadgeClass = 'bg-info text-dark';
} elseif ($status === 'Absent') {
    $statusBadgeClass = 'bg-danger';
}

$stateLabel = hrAttendanceStateDisplayLabel($currentState);
$stateBadgeClass = 'bg-secondary';
if ($currentState !== 'Off') {
    $stateBadgeClass = 'bg-primary';
} else {
    if ($pIn === '' && $pOut === '') {
        $stateLabel = 'Not Started';
        $stateBadgeClass = 'bg-secondary';
    } elseif ($pIn !== '' && $pOut === '') {
        $stateLabel = 'Checked In';
        $stateBadgeClass = 'bg-info text-dark';
    } elseif ($pIn !== '' && $pOut !== '') {
        $stateLabel = 'Checked Out';
        $stateBadgeClass = 'bg-dark';
    }
}
$lateBadgeText = $lateMin > 0 ? ('Late +' . number_format($lateMin) . 'm') : '';

$selYear = (int)substr($rangeStart, 0, 4);
$selMonth = (int)substr($rangeStart, 5, 2);
$monthSummary = getAttendanceMonthSummary((int)($user['id'] ?? 0), $selYear, $selMonth);
$monthSummary = attendancePageNormalizeSummaryForPendingToday(
    $monthSummary,
    sprintf('%04d-%02d-01', $selYear, $selMonth),
    sprintf('%04d-%02d-%02d', $selYear, $selMonth, cal_days_in_month(CAL_GREGORIAN, $selMonth, $selYear)),
    $today,
    $status === 'Pending' || $status === 'In Progress'
);
$monthSummary = hrNormalizeAttendanceSummaryDisplayMinutesForSnapshot(
    $monthSummary,
    $attendanceSnapshot,
    sprintf('%04d-%02d-01', $selYear, $selMonth),
    sprintf('%04d-%02d-%02d', $selYear, $selMonth, cal_days_in_month(CAL_GREGORIAN, $selMonth, $selYear))
);
$workingDays = (int)($monthSummary['working_days'] ?? 0);
$presentDays = (int)($monthSummary['present_days'] ?? 0);
$halfDays = (int)($monthSummary['half_days'] ?? 0);
$absentDays = (int)($monthSummary['absent_days'] ?? 0);
$ncnsDays = (int)($monthSummary['ncns_days'] ?? 0);
$pendingDays = (int)($monthSummary['pending_days'] ?? 0);
$paidLeaveDays = (int)($monthSummary['paid_leave_days'] ?? 0);
$unpaidLeaveDays = (int)($monthSummary['unpaid_leave_days'] ?? 0);
$lateDays = (int)($monthSummary['late_days'] ?? 0);
$paidDays = (float)($monthSummary['paid_days'] ?? 0.0);
$attendancePct = (float)($monthSummary['attendance_percent'] ?? 0.0);

$monthStart = sprintf('%04d-%02d-01', $selYear, $selMonth);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthCalcStart = $joinDate !== null ? hrMaxDate($monthStart, $joinDate) : $monthStart;
$monthRows = getAttendanceDaysForUserRange((int)($user['id'] ?? 0), $monthStart, $monthEnd);
$totalWorkMin = 0;
$totalOverMin = 0;
foreach ($monthRows as $r) {
    $wm = (int)($r['working_minutes'] ?? 0);
    if ((string)($r['work_date'] ?? '') === $activeWorkDate) {
        $wm = $workMin;
    }
    $totalWorkMin += max(0, $wm);
    $totalOverMin += hrOvertimeMinutesFromWorking($wm);
}
$monthHolidays = getHolidaysForMonth($selYear, $selMonth, 'US');
$holidayCount = 0;
$holidaySet = [];
foreach ($monthHolidays as $h) {
    $dt = (string)($h['holiday_date'] ?? '');
    if ($dt !== '') {
        if ($dt >= $monthCalcStart && $dt <= $monthEnd) $holidayCount++;
        $holidaySet[$dt] = true;
    }
}
$weekOffCount = 0;
$dCur = $monthCalcStart;
while ($dCur <= $monthEnd) {
    if (hrIsWeekend($dCur) && !isset($holidaySet[$dCur])) $weekOffCount++;
    $dCur = date('Y-m-d', strtotime($dCur . ' +1 day'));
}

$shift = getUserShiftForDate((int)($user['id'] ?? 0), $activeWorkDate);
$shiftName = (string)($shift['name'] ?? '');
$shiftStart = substr((string)($shift['start_time'] ?? ''), 0, 5);
$shiftEnd = substr((string)($shift['end_time'] ?? ''), 0, 5);
$policy = getAttendancePolicySettings();
$shiftGrace = (int)($policy['grace_minutes'] ?? 10);
$effectiveGrace = $activeDay ? (int)($activeDay['grace_minutes'] ?? $shiftGrace) : $shiftGrace;

$allowedBreak = 75;

$displayNow = new DateTimeImmutable('now', hrDisplayTz());
$serverNowTs = $displayNow->getTimestamp();
$attendanceContext = is_array($activeDay) ? $activeDay : [
    'work_date' => $activeWorkDate,
    'user_id' => (int)($user['id'] ?? 0),
    'punch_in' => $pIn,
    'punch_out' => $pOut,
];
$breakUsageByState = hrAttendanceBreakMinutesByState($attendanceContext, $activeStates, $displayNow);
$usedB1 = max($activeDay ? getBreakMinutesUsed((int)$activeDay['id'], 'Break1') : 0, (int)($breakUsageByState['Break1'] ?? 0));
$usedB2 = max($activeDay ? getBreakMinutesUsed((int)$activeDay['id'], 'Break2') : 0, (int)($breakUsageByState['Break2'] ?? 0));
$usedLunch = max($activeDay ? getBreakMinutesUsed((int)$activeDay['id'], 'Lunch') : 0, (int)($breakUsageByState['Lunch'] ?? 0));
$totalBreakUsed = max($breakMin, max(0, (int)$usedB1) + max(0, (int)$usedB2) + max(0, (int)$usedLunch));
$exceededBreak = max(0, $totalBreakUsed - $allowedBreak);
$pInTs = $pIn !== '' ? (hrResolveAttendanceDateTime($pIn, $attendanceContext)?->getTimestamp() ?? null) : null;
$pOutTs = $pOut !== '' ? (hrResolveAttendanceDateTime($pOut, $attendanceContext)?->getTimestamp() ?? null) : null;
$openState = $open ? (string)($open['state'] ?? '') : '';
$openStartTs = $open ? (hrResolveAttendanceDateTime((string)($open['start_at'] ?? ''), $attendanceContext)?->getTimestamp() ?? null) : null;
?>
<?php $pageTitle = 'My Attendance'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
  .hr-attendance-page .hr-card .card-header {
    border-bottom-color: rgba(148, 163, 184, 0.16);
  }
  .hr-attendance-page .hr-card .card-body {
    padding: 1rem;
  }
  .hr-attendance-page .hr-att-compact-note {
    color: var(--bs-secondary-color);
    font-size: .79rem;
    line-height: 1.45;
  }
  .hr-attendance-page .hr-att-mini-stat {
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: .75rem;
    padding: .7rem .8rem;
    background: rgba(248, 250, 252, 0.75);
    height: 100%;
  }
  .hr-attendance-page .hr-att-mini-stat .text-muted {
    font-size: .76rem;
  }
  .hr-attendance-page .hr-att-mini-stat .fw-semibold {
    color: var(--bs-emphasis-color);
  }
  .hr-attendance-page .hr-att-summary-row {
    border-top: 1px dashed rgba(148, 163, 184, 0.2);
    margin-top: .75rem;
    padding-top: .75rem;
  }
  .hr-attendance-page .hr-att-action-btn-lite {
    min-height: 2.35rem;
    font-weight: 600;
    box-shadow: 0 0.2rem 0.5rem rgba(15, 23, 42, 0.06);
  }
  .hr-attendance-page .hr-att-action-btn-lite.btn-outline-primary,
  .hr-attendance-page .hr-att-action-btn-lite.btn-outline-secondary,
  .hr-attendance-page .hr-att-action-btn-lite.btn-outline-warning,
  .hr-attendance-page .hr-att-action-btn-lite.btn-outline-dark {
    border-width: 1px;
  }
  .hr-attendance-page .hr-att-action-btn-lite:disabled {
    box-shadow: none;
  }
  .hr-attendance-page #hr-att-calendar .hr-att-cal-cell,
  .hr-attendance-page #hr-att-calendar .hr-att-week-card,
  .hr-attendance-page #hr-att-calendar .hr-att-day {
    border-radius: .85rem;
    border: 1px solid rgba(148, 163, 184, 0.14);
    box-shadow: 0 0.2rem 0.65rem rgba(15, 23, 42, 0.05);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-cal-head {
    font-size: .78rem;
    color: var(--bs-secondary-color);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-cal-line,
  .hr-attendance-page #hr-att-calendar .hr-att-cal-status {
    color: var(--bs-secondary-color);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-cal-strong,
  .hr-attendance-page #hr-att-calendar .hr-att-cal-day {
    color: var(--bs-emphasis-color);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-present {
    background: rgba(22, 163, 74, 0.08);
    border-color: rgba(22, 163, 74, 0.22);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-late {
    background: rgba(245, 158, 11, 0.10);
    border-color: rgba(245, 158, 11, 0.24);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-half {
    background: rgba(249, 115, 22, 0.09);
    border-color: rgba(249, 115, 22, 0.22);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-absent,
  .hr-attendance-page #hr-att-calendar .hr-att-status-ncns,
  .hr-attendance-page #hr-att-calendar .hr-att-status-missing {
    background: rgba(239, 68, 68, 0.08);
    border-color: rgba(239, 68, 68, 0.22);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-leave {
    background: rgba(59, 130, 246, 0.08);
    border-color: rgba(59, 130, 246, 0.22);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-holiday {
    background: rgba(139, 92, 246, 0.09);
    border-color: rgba(139, 92, 246, 0.24);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-weekoff {
    background: rgba(6, 182, 212, 0.08);
    border-color: rgba(6, 182, 212, 0.22);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-na {
    background: rgba(148, 163, 184, 0.08);
    border-color: rgba(148, 163, 184, 0.18);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-pending {
    background: rgba(59, 130, 246, 0.06);
    border-color: rgba(59, 130, 246, 0.20);
  }
  .hr-attendance-page #hr-att-calendar .hr-att-status-present .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-late .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-half .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-absent .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-ncns .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-missing .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-leave .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-holiday .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-weekoff .hr-att-cal-status,
  .hr-attendance-page #hr-att-calendar .hr-att-status-pending .hr-att-cal-status {
    font-weight: 600;
  }
  .hr-attendance-page #hrAttDrawer .offcanvas-header {
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  }
  .hr-attendance-page #hrAttDrawer .hr-kpi {
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: .85rem;
    background: rgba(248, 250, 252, 0.78);
    padding: .85rem .9rem;
    height: 100%;
  }
  .hr-attendance-page #hrAttDrawer .list-group-item {
    border-color: rgba(148, 163, 184, 0.14);
  }
  [data-bs-theme="dark"] .hr-attendance-page .hr-att-mini-stat,
  [data-bs-theme="dark"] .hr-attendance-page #hrAttDrawer .hr-kpi {
    background: rgba(15, 23, 42, 0.55);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-present {
    background: rgba(22, 163, 74, 0.16);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-late {
    background: rgba(245, 158, 11, 0.18);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-half {
    background: rgba(249, 115, 22, 0.16);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-absent,
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-ncns,
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-missing {
    background: rgba(239, 68, 68, 0.16);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-leave {
    background: rgba(59, 130, 246, 0.15);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-holiday {
    background: rgba(139, 92, 246, 0.18);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-weekoff {
    background: rgba(6, 182, 212, 0.16);
  }
  [data-bs-theme="dark"] .hr-attendance-page #hr-att-calendar .hr-att-status-pending {
    background: rgba(59, 130, 246, 0.14);
  }
</style>
<div class="container-fluid px-0 hr-attendance-page">
    <?php
        $headerBadges = [
            ['text' => $selDate, 'class' => 'bg-light text-dark font-monospace'],
            ['text' => $status, 'class' => $statusBadgeClass],
            ['text' => $stateLabel, 'class' => $stateBadgeClass],
        ];
        if ($lateBadgeText !== '') {
            $headerBadges[] = ['text' => $lateBadgeText, 'class' => 'bg-danger'];
        }
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Attendance'],
            ],
            'My Attendance',
            'Punch in/out, track breaks & working hours',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
            ],
            $headerBadges
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Calendar view">
                            <a class="btn <?php echo $view === 'month' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?view=month&date=<?php echo urlencode($selDate); ?>">Monthly</a>
                            <a class="btn <?php echo $view === 'week' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?view=week&date=<?php echo urlencode($selDate); ?>">Weekly</a>
                            <a class="btn <?php echo $view === 'day' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?view=day&date=<?php echo urlencode($selDate); ?>">Daily</a>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <a class="btn btn-outline-secondary btn-sm" href="?view=<?php echo urlencode($view); ?>&date=<?php echo urlencode(date('Y-m-d', strtotime($selDate . ' -1 ' . ($view === 'month' ? 'month' : 'day')))); ?>"><i class="bi bi-chevron-left"></i></a>
                            <input class="form-control form-control-sm font-monospace" id="hr-att-date" type="date" value="<?php echo htmlspecialchars($selDate); ?>" style="max-width: 170px;">
                            <a class="btn btn-outline-secondary btn-sm" href="?view=<?php echo urlencode($view); ?>&date=<?php echo urlencode(date('Y-m-d', strtotime($selDate . ' +1 ' . ($view === 'month' ? 'month' : 'day')))); ?>"><i class="bi bi-chevron-right"></i></a>
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#hrAttFilters"><i class="bi bi-funnel"></i> Filters</button>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-lg-8">
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-calendar3 me-1"></i> Attendance Calendar</span>
                                    <span class="badge bg-secondary font-monospace" id="hr-att-range"><?php echo htmlspecialchars($rangeStart . ' → ' . $rangeEnd); ?></span>
                                </div>
                                <div class="card-body">
                                    <div id="hr-att-calendar"></div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                <div class="col-xl-6">
                                    <div class="card hr-card">
                                        <div class="card-header bg-light fw-semibold"><i class="bi bi-bar-chart me-1"></i> Trend</div>
                                        <div class="card-body">
                                            <canvas id="hrAttChartWork" height="120"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <div class="card hr-card">
                                        <div class="card-header bg-light fw-semibold"><i class="bi bi-alarm me-1"></i> Late / OT</div>
                                        <div class="card-body">
                                            <canvas id="hrAttChartLateOt" height="120"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card hr-card mb-3">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-activity me-1"></i> Today</span>
                                    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                                        <span class="badge <?php echo htmlspecialchars($statusBadgeClass); ?>"><?php echo htmlspecialchars($status); ?></span>
                                        <?php if ($lateBadgeText !== ''): ?>
                                            <span class="badge bg-danger"><?php echo htmlspecialchars($lateBadgeText); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($stateLabel); ?></div>
                                        <span class="badge <?php echo htmlspecialchars($stateBadgeClass); ?>"><?php echo htmlspecialchars($stateLabel); ?></span>
                                    </div>
                                    <div class="hr-att-compact-note mt-2">Punch in first, then use status buttons only while your attendance is open. End the current status before punch out.</div>
                                    <div class="row g-2 mt-2">
                                        <div class="col-6">
                                            <div class="hr-att-mini-stat">
                                                <div class="text-muted small">Punch In</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($pIn !== '' ? hrFormatAttendanceForDisplay($pIn, 'H:i', $activeDay ?: ['work_date' => $activeWorkDate]) : '—'); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="hr-att-mini-stat">
                                                <div class="text-muted small">Punch Out</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($pOut !== '' ? hrFormatAttendanceForDisplay($pOut, 'H:i', $activeDay ?: ['work_date' => $activeWorkDate]) : '—'); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="hr-att-mini-stat">
                                                <div class="text-muted small">Working</div>
                                                <div class="fw-semibold" id="hr-live-working"><?php echo htmlspecialchars(fmtMinutesLabel($workMin)); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="hr-att-mini-stat">
                                                <div class="text-muted small">Break</div>
                                                <div class="fw-semibold" id="hr-live-break-sub"><?php echo htmlspecialchars(fmtMinutesLabel($breakMin)); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="hr-att-summary-row">
                                        <div class="text-muted small">Break Used</div>
                                        <div class="fw-semibold"><span id="hr-live-break-used-compact"><?php echo htmlspecialchars(fmtMinutesLabel($totalBreakUsed)); ?> / <?php echo htmlspecialchars(fmtMinutesLabel($allowedBreak)); ?><?php echo $exceededBreak > 0 ? htmlspecialchars(' (Exceeded ' . fmtMinutesLabel($exceededBreak) . ')') : ''; ?></span></div>
                                        <div class="text-muted small mt-2">Shift</div>
                                        <div class="fw-semibold">
                                            <?php if ($shiftName !== '' && $shiftStart !== '' && $shiftEnd !== ''): ?>
                                                <?php echo htmlspecialchars($shiftName); ?> · <?php echo htmlspecialchars($shiftStart . '–' . $shiftEnd); ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-lightning-charge me-1"></i> Quick Actions</span>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="hrAttOpenToday"><i class="bi bi-eye"></i> Details</button>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="punch_in">
                                                <button class="btn btn-success btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo $pIn !== '' ? 'disabled' : ''; ?>><i class="bi bi-box-arrow-in-right"></i> Punch In</button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="punch_out">
                                                <button class="btn btn-danger btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo ($pIn === '' || $pOut !== '') ? 'disabled' : ''; ?>><i class="bi bi-box-arrow-right"></i> Punch Out</button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="end_state">
                                                <button class="btn btn-outline-dark btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo ($pIn === '' || $pOut !== '' || !$open) ? 'disabled' : ''; ?>><i class="bi bi-stop-circle"></i> End State</button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Working">
                                                <button class="btn btn-outline-primary btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo $pIn === '' || $pOut !== '' ? 'disabled' : ''; ?>><i class="bi bi-briefcase"></i> Working</button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Meeting">
                                                <button class="btn btn-outline-secondary btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo $pIn === '' || $pOut !== '' ? 'disabled' : ''; ?>><i class="bi bi-people"></i> Meeting</button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Dinner">
                                                <button class="btn btn-outline-warning btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo ($pIn === '' || $pOut !== '') ? 'disabled' : ''; ?>><i class="bi bi-egg-fried"></i> Dinner</button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Break1">
                                                <button class="btn btn-outline-warning btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo ($pIn === '' || $pOut !== '') ? 'disabled' : ''; ?>><i class="bi bi-cup-hot"></i> Tea Break 1</button>
                                            </form>
                                        </div>
                                        <div class="col-6">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Break2">
                                                <button class="btn btn-outline-warning btn-sm w-100 hr-att-action-btn-lite" type="submit" <?php echo ($pIn === '' || $pOut !== '') ? 'disabled' : ''; ?>><i class="bi bi-cup-straw"></i> Tea Break 2</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="mt-2 small text-muted">Policy: Tea Break 1 15m, Tea Break 2 15m, Dinner 45m (max 75m/day).</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="hrAttDrawer" aria-labelledby="hrAttDrawerLabel">
  <div class="offcanvas-header">
    <div>
      <div class="offcanvas-title fw-semibold" id="hrAttDrawerLabel">Attendance Details</div>
      <div class="text-muted small font-monospace" id="hrAttDrawerDate"></div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="row g-2">
      <div class="col-12">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Status</div>
          <div class="hr-kpi-value" id="hrAttDrawerStatus">—</div>
          <div class="hr-kpi-sub" id="hrAttDrawerShift">—</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Punch In</div>
          <div class="hr-kpi-value" id="hrAttDrawerIn">—</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Punch Out</div>
          <div class="hr-kpi-value" id="hrAttDrawerOut">—</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Total Worked</div>
          <div class="hr-kpi-value" id="hrAttDrawerWork">00:00</div>
          <div class="hr-kpi-sub" id="hrAttDrawerBreak">Break 00:00</div>
        </div>
      </div>
      <div class="col-6">
        <div class="hr-kpi">
          <div class="hr-kpi-label">Overtime</div>
          <div class="hr-kpi-value" id="hrAttDrawerOt">00:00</div>
          <div class="hr-kpi-sub" id="hrAttDrawerLate">Late 0 min</div>
        </div>
      </div>
    </div>

    <div class="mt-3">
      <div class="fw-semibold mb-2"><i class="bi bi-clock-history me-1"></i> History Timeline</div>
      <div class="list-group" id="hrAttDrawerTimeline"></div>
    </div>
  </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="hrAttFilters" aria-labelledby="hrAttFiltersLabel">
  <div class="offcanvas-header">
    <div class="offcanvas-title fw-semibold" id="hrAttFiltersLabel">Filters</div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="mb-2">
      <label class="form-label small text-muted">Status</label>
      <select class="form-select form-select-sm" id="hrAttFilterStatus">
        <option value="">All</option>
        <option value="present">Present</option>
        <option value="late">Late</option>
        <option value="half">Half Day</option>
        <option value="absent">Absent</option>
        <option value="ncns">NCNS</option>
        <option value="holiday">Holiday</option>
        <option value="weekoff">Weekly Off</option>
        <option value="pending">Pending</option>
        <option value="leave">Leave</option>
        <option value="missing">Missing Punch</option>
      </select>
    </div>
    <div class="mb-2">
      <label class="form-label small text-muted">Min Work (HH:MM)</label>
      <input class="form-control form-control-sm font-monospace" id="hrAttFilterMinWork" placeholder="00:00">
    </div>
    <div class="mb-2">
      <label class="form-label small text-muted">Shift</label>
      <input class="form-control form-control-sm" id="hrAttFilterShift" placeholder="Shift name contains...">
    </div>
    <button class="btn btn-primary btn-sm w-100" type="button" id="hrAttApplyFilters"><i class="bi bi-check2"></i> Apply</button>
    <button class="btn btn-outline-secondary btn-sm w-100 mt-2" type="button" id="hrAttClearFilters"><i class="bi bi-x"></i> Clear</button>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script id="hr-live-attendance-data" type="application/json"><?php
    echo json_encode([
        'serverNowTs' => $serverNowTs,
        'pInTs' => $pInTs,
        'pOutTs' => $pOutTs,
        'openState' => $openState,
        'openStartTs' => $openStartTs,
        'baseDisplayWorkMin' => (int)$workMin,
        'baseDisplayBreakMin' => (int)$breakMin,
        'allowedBreakMin' => (int)$allowedBreak,
        'allowedB1Min' => 15,
        'allowedB2Min' => 15,
        'allowedLunchMin' => 45,
        'usedB1Min' => (int)$usedB1,
        'usedB2Min' => (int)$usedB2,
        'usedLunchMin' => (int)$usedLunch,
    ], JSON_UNESCAPED_SLASHES);
?></script>
<script>
(() => {
    const dataEl = document.getElementById('hr-live-attendance-data');
    if (!dataEl) return;
    let data = null;
    try { data = JSON.parse(dataEl.textContent || '{}'); } catch { data = null; }
    if (!data) return;

    const pInTs = Number.isFinite(data.pInTs) ? data.pInTs : null;
    const pOutTs = Number.isFinite(data.pOutTs) ? data.pOutTs : null;
    const serverNowTs = Number.isFinite(data.serverNowTs) ? data.serverNowTs : null;
    if (!pInTs || pOutTs || !serverNowTs) return;

    const openState = String(data.openState || '');
    const openStartTs = Number.isFinite(data.openStartTs) ? data.openStartTs : null;
    const baseDisplayWorkMin = Number.isFinite(data.baseDisplayWorkMin) ? data.baseDisplayWorkMin : 0;
    const baseDisplayBreakMin = Number.isFinite(data.baseDisplayBreakMin) ? data.baseDisplayBreakMin : 0;
    const allowedBreakMin = Number.isFinite(data.allowedBreakMin) ? data.allowedBreakMin : 75;
    const allowedB1Min = Number.isFinite(data.allowedB1Min) ? data.allowedB1Min : 15;
    const allowedB2Min = Number.isFinite(data.allowedB2Min) ? data.allowedB2Min : 15;
    const allowedLunchMin = Number.isFinite(data.allowedLunchMin) ? data.allowedLunchMin : 45;
    const usedB1Min = Number.isFinite(data.usedB1Min) ? data.usedB1Min : 0;
    const usedB2Min = Number.isFinite(data.usedB2Min) ? data.usedB2Min : 0;
    const usedLunchMin = Number.isFinite(data.usedLunchMin) ? data.usedLunchMin : 0;

    const workEl = document.getElementById('hr-live-working');
    const breakSubEl = document.getElementById('hr-live-break-sub');
    const breakUsedEl = document.getElementById('hr-live-break-used');
    const breakUsedCompactEl = document.getElementById('hr-live-break-used-compact');
    if (!workEl && !breakSubEl && !breakUsedEl && !breakUsedCompactEl) return;

    const offsetMs = (serverNowTs * 1000) - Date.now();
    const nowSec = () => Math.floor((Date.now() + offsetMs) / 1000);

    const fmtHms = (sec) => {
        const total = Math.max(0, Math.floor(sec));
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        return `${h}h ${String(m).padStart(2,'0')}m ${String(s).padStart(2,'0')}s`;
    };

    const allowedByStateMin = { Break1: allowedB1Min, Break2: allowedB2Min, Lunch: allowedLunchMin };
    const usedByStateMin = { Break1: usedB1Min, Break2: usedB2Min, Lunch: usedLunchMin };

    const computeLive = () => {
        const n = nowSec();
        const deltaSinceServerSec = Math.max(0, n - serverNowTs);
        const isBreakState = openState in allowedByStateMin;
        const breakSec = Math.max(0, (baseDisplayBreakMin * 60) + (isBreakState ? deltaSinceServerSec : 0));
        const workSec = Math.max(0, (baseDisplayWorkMin * 60) + (!isBreakState ? deltaSinceServerSec : 0));

        if (workEl) workEl.textContent = fmtHms(workSec);
        if (breakSubEl) breakSubEl.textContent = fmtHms(breakSec);

        const usedByStateSec = {
            Break1: (usedByStateMin.Break1 * 60) + (openState === 'Break1' ? deltaSinceServerSec : 0),
            Break2: (usedByStateMin.Break2 * 60) + (openState === 'Break2' ? deltaSinceServerSec : 0),
            Lunch: (usedByStateMin.Lunch * 60) + (openState === 'Lunch' ? deltaSinceServerSec : 0),
        };
        const totalBreakUsedSec = Math.max(breakSec, usedByStateSec.Break1 + usedByStateSec.Break2 + usedByStateSec.Lunch);
        const exceededSec = Math.max(0, totalBreakUsedSec - (allowedBreakMin * 60));
        const breakUsedText = `${fmtHms(totalBreakUsedSec)} / ${fmtHms(allowedBreakMin * 60)}${exceededSec > 0 ? ` (Exceeded ${fmtHms(exceededSec)})` : ''}`;
        if (breakUsedEl) breakUsedEl.textContent = breakUsedText;
        if (breakUsedCompactEl) breakUsedCompactEl.textContent = breakUsedText;
    };

    computeLive();
    window.setInterval(computeLive, 1000);
})();
</script>

<script id="hr-att-init" type="application/json"><?php
    echo json_encode([
        'view' => $view,
        'date' => $selDate,
        'today' => $uiToday,
    ], JSON_UNESCAPED_SLASHES);
?></script>
<script>
(() => {
  const initEl = document.getElementById('hr-att-init');
  if (!initEl) return;
  let init = null;
  try { init = JSON.parse(initEl.textContent || '{}'); } catch { init = null; }
  if (!init) return;

  const calendarEl = document.getElementById('hr-att-calendar');
  const rangeEl = document.getElementById('hr-att-range');
  const dateEl = document.getElementById('hr-att-date');
  const btnTodayDetails = document.getElementById('hrAttOpenToday');

  const drawerEl = document.getElementById('hrAttDrawer');
  const drawer = drawerEl ? new bootstrap.Offcanvas(drawerEl) : null;

  const filterStatusEl = document.getElementById('hrAttFilterStatus');
  const filterMinWorkEl = document.getElementById('hrAttFilterMinWork');
  const filterShiftEl = document.getElementById('hrAttFilterShift');
  const applyFiltersEl = document.getElementById('hrAttApplyFilters');
  const clearFiltersEl = document.getElementById('hrAttClearFilters');
  let filters = { status: '', minWork: '', shift: '' };

  const parseHHMM = (v) => {
    v = String(v || '').trim();
    if (!v) return 0;
    const m = v.match(/^(\d{1,2}):(\d{2})$/);
    if (!m) return 0;
    const h = parseInt(m[1], 10);
    const mm = parseInt(m[2], 10);
    if (!Number.isFinite(h) || !Number.isFinite(mm)) return 0;
    return Math.max(0, (h * 60) + Math.max(0, Math.min(59, mm)));
  };

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

  const displayStatus = (d) => {
    if (!d) return '';
    if (d.is_holiday) return 'US Holiday';
    return String(d.status || '');
  };

  const statusTitle = (d) => {
    if (!d) return '';
    if (d.is_holiday && d.holiday_name) return String(d.holiday_name);
    return String(d.status || '');
  };

  const shouldShow = (d) => {
    if (!d) return false;
    if (filters.status && String(d.color) !== filters.status) return false;
    if (filters.shift) {
      const sh = String(d.shift || '').toLowerCase();
      if (!sh.includes(filters.shift.toLowerCase())) return false;
    }
    if (filters.minWork) {
      const min = parseHHMM(filters.minWork);
      const w = parseHHMM(d.work_hhmm);
      if (w < min) return false;
    }
    return true;
  };

  const fetchJson = async (url) => {
    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    return await res.json();
  };

  const openDetail = async (dateStr) => {
    if (!drawer) return;
    const j = await fetchJson(`?ajax=detail&date=${encodeURIComponent(dateStr)}`);
    if (!j || !j.ok) return;
    document.getElementById('hrAttDrawerDate').textContent = j.date || dateStr;
    document.getElementById('hrAttDrawerStatus').textContent = (j.attendance && j.attendance.status) ? j.attendance.status : '—';
    const sh = j.shift || {};
    document.getElementById('hrAttDrawerShift').textContent =
      (sh.name ? sh.name : '—') + (sh.start && sh.end ? ` · ${sh.start}–${sh.end}` : '');
    document.getElementById('hrAttDrawerIn').textContent = (j.attendance && j.attendance.punch_in) ? j.attendance.punch_in : '—';
    document.getElementById('hrAttDrawerOut').textContent = (j.attendance && j.attendance.punch_out) ? j.attendance.punch_out : '—';
    document.getElementById('hrAttDrawerWork').textContent = (j.attendance && j.attendance.work_hhmm) ? j.attendance.work_hhmm : '00:00';
    document.getElementById('hrAttDrawerBreak').textContent = `Break ${(j.attendance && j.attendance.break_hhmm) ? j.attendance.break_hhmm : '00:00'}`;
    document.getElementById('hrAttDrawerOt').textContent = (j.attendance && j.attendance.overtime_hhmm) ? j.attendance.overtime_hhmm : '00:00';
    document.getElementById('hrAttDrawerLate').textContent = `Late ${(j.attendance && Number.isFinite(j.attendance.late_min)) ? j.attendance.late_min : 0} min`;

    const tl = document.getElementById('hrAttDrawerTimeline');
    tl.innerHTML = '';
    const rows = Array.isArray(j.timeline) ? j.timeline : [];
    if (!rows.length) {
      const a = document.createElement('div');
      a.className = 'list-group-item text-muted';
      a.textContent = 'No events.';
      tl.appendChild(a);
    } else {
      rows.forEach((e) => {
        const it = document.createElement('div');
        it.className = 'list-group-item d-flex justify-content-between align-items-center gap-2';
        const left = document.createElement('div');
        left.textContent = String(e.label || '');
        const right = document.createElement('div');
        right.className = 'text-muted small font-monospace';
        right.textContent = String(e.at || '');
        it.appendChild(left);
        it.appendChild(right);
        tl.appendChild(it);
      });
    }
    drawer.show();
  };

  const fmtLocalDate = (dt) => {
    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, '0');
    const d = String(dt.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  const renderMonth = (start, end, days) => {
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
      const inMonth = ds >= start && ds <= end;

      const cell = document.createElement('button');
      cell.type = 'button';
      cell.className = 'hr-att-cal-cell';
      if (!inMonth) cell.classList.add('is-out');
      if (ds === init.today) cell.classList.add('is-today');
      if (d) cell.classList.add(statusClass(d.color));
      if (d && d.overtime_min > 0) cell.classList.add('hr-att-has-ot');
      if (d) cell.title = statusTitle(d);
      cell.dataset.date = ds;

      const top = document.createElement('div');
      top.className = 'hr-att-cal-top';
      const dayNum = document.createElement('div');
      dayNum.className = 'hr-att-cal-day';
      dayNum.textContent = String(cur.getDate());
      const status = document.createElement('div');
      status.className = 'hr-att-cal-status';
      status.textContent = displayStatus(d);
      if (d) status.title = statusTitle(d);
      top.appendChild(dayNum);
      top.appendChild(status);

      const meta = document.createElement('div');
      meta.className = 'hr-att-cal-meta';
      const p = document.createElement('div');
      p.className = 'hr-att-cal-line';
      p.textContent = d ? ((d.punch_in ? d.punch_in : '—') + ' → ' + (d.punch_out ? d.punch_out : '—')) : '';
      const w = document.createElement('div');
      w.className = 'hr-att-cal-line hr-att-cal-strong';
      w.textContent = d ? ('Work ' + (d.work_hhmm || '00:00') + (d.overtime_hhmm ? (' · OT ' + d.overtime_hhmm) : '')) : '';
      const sh = document.createElement('div');
      sh.className = 'hr-att-cal-line';
      sh.textContent = d && d.shift ? d.shift : '';
      meta.appendChild(p);
      meta.appendChild(w);
      meta.appendChild(sh);

      cell.appendChild(top);
      cell.appendChild(meta);
      cell.style.display = shouldShow(d) || (!filters.status && !filters.minWork && !filters.shift) ? '' : 'none';
      cell.addEventListener('click', () => openDetail(ds));
      grid.appendChild(cell);

      cur.setDate(cur.getDate() + 1);
    }
    calendarEl.innerHTML = '';
    calendarEl.appendChild(grid);
  };

  const renderWeek = (start, end, days) => {
    const wrap = document.createElement('div');
    wrap.className = 'hr-att-week';
    days.forEach((d) => {
      if (!shouldShow(d) && (filters.status || filters.minWork || filters.shift)) return;
      const card = document.createElement('button');
      card.type = 'button';
      card.className = `hr-att-week-card ${statusClass(d.color)} ${d.overtime_min > 0 ? 'hr-att-has-ot' : ''}`;
      card.dataset.date = d.date;
      card.innerHTML =
        `<div class="d-flex justify-content-between align-items-center gap-2">` +
          `<div class="fw-semibold font-monospace">${d.date}</div>` +
          `<span class="badge bg-secondary">${displayStatus(d)}</span>` +
        `</div>` +
        `<div class="small text-muted mt-1">${d.shift || ''}</div>` +
        `<div class="mt-2 font-monospace">${(d.punch_in || '—')} → ${(d.punch_out || '—')}</div>` +
        `<div class="mt-1 fw-semibold">Work ${d.work_hhmm || '00:00'}${d.overtime_hhmm ? (' · OT ' + d.overtime_hhmm) : ''}</div>`;
      card.addEventListener('click', () => openDetail(d.date));
      wrap.appendChild(card);
    });
    calendarEl.innerHTML = '';
    calendarEl.appendChild(wrap);
  };

  const renderDay = (start, end, days) => {
    const d = days && days.length ? days[0] : null;
    const box = document.createElement('div');
    box.className = 'hr-att-day';
    if (!d) {
      box.textContent = 'No data.';
    } else {
      box.innerHTML =
        `<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">` +
          `<div class="fw-semibold font-monospace">${d.date}</div>` +
          `<span class="badge bg-secondary">${displayStatus(d)}</span>` +
        `</div>` +
        `<div class="small text-muted mt-1">${d.shift || ''}</div>` +
        `<div class="row g-2 mt-2">` +
          `<div class="col-md-6"><div class="hr-kpi"><div class="hr-kpi-label">Punch In</div><div class="hr-kpi-value">${d.punch_in || '—'}</div></div></div>` +
          `<div class="col-md-6"><div class="hr-kpi"><div class="hr-kpi-label">Punch Out</div><div class="hr-kpi-value">${d.punch_out || '—'}</div></div></div>` +
          `<div class="col-md-6"><div class="hr-kpi"><div class="hr-kpi-label">Work</div><div class="hr-kpi-value">${d.work_hhmm || '00:00'}</div></div></div>` +
          `<div class="col-md-6"><div class="hr-kpi"><div class="hr-kpi-label">Overtime</div><div class="hr-kpi-value">${d.overtime_hhmm || '00:00'}</div></div></div>` +
        `</div>` +
        `<div class="mt-3"><button class="btn btn-primary btn-sm" type="button" id="hrAttOpenDay">Open Details</button></div>`;
    }
    calendarEl.innerHTML = '';
    calendarEl.appendChild(box);
    const btn = document.getElementById('hrAttOpenDay');
    if (btn && d) btn.addEventListener('click', () => openDetail(d.date));
  };

  let chartWork = null;
  let chartLateOt = null;
  const renderCharts = (days) => {
    const labels = days.map(d => d.date.slice(8,10));
    const work = days.map(d => parseHHMM(d.work_hhmm));
    const late = days.map(d => Number.isFinite(d.late_min) ? d.late_min : 0);
    const ot = days.map(d => Number.isFinite(d.overtime_min) ? d.overtime_min : 0);

    const workCtx = document.getElementById('hrAttChartWork');
    const lateCtx = document.getElementById('hrAttChartLateOt');
    if (chartWork) { chartWork.destroy(); chartWork = null; }
    if (chartLateOt) { chartLateOt.destroy(); chartLateOt = null; }
    if (workCtx) {
      chartWork = new Chart(workCtx, {
        type: 'line',
        data: { labels, datasets: [{ label: 'Work (min)', data: work, borderColor: '#0EA5E9', backgroundColor: 'rgba(14,165,233,.15)', fill: true, tension: 0.3, pointRadius: 2 }] },
        options: { responsive: true, plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true } } }
      });
    }
    if (lateCtx) {
      chartLateOt = new Chart(lateCtx, {
        type: 'bar',
        data: {
          labels,
          datasets: [
            { label: 'Late (min)', data: late, backgroundColor: 'rgba(245,158,11,.55)' },
            { label: 'Overtime (min)', data: ot, backgroundColor: 'rgba(22,163,74,.50)' }
          ]
        },
        options: { responsive: true, plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true } } }
      });
    }
  };

  const load = async () => {
    const v = String(init.view || 'month');
    const d = String(init.date || '');
    const j = await fetchJson(`?ajax=calendar&view=${encodeURIComponent(v)}&date=${encodeURIComponent(d)}`);
    if (!j || !j.ok) return;
    if (rangeEl) rangeEl.textContent = `${j.start} → ${j.end}`;
    const days = Array.isArray(j.days) ? j.days : [];

    if (v === 'month') renderMonth(j.start, j.end, days);
    else if (v === 'week') renderWeek(j.start, j.end, days);
    else renderDay(j.start, j.end, days);
    if (v === 'month') renderCharts(days.filter(d => d.date >= j.start && d.date <= j.end && d.color !== 'na' && d.color !== 'holiday' && d.color !== 'weekoff'));
    else renderCharts(days);
  };

  let refreshBusy = false;
  const refreshCurrentView = async () => {
    if (refreshBusy || document.hidden) return;
    refreshBusy = true;
    try {
      await load();
    } finally {
      refreshBusy = false;
    }
  };

  if (dateEl) {
    dateEl.addEventListener('change', () => {
      const v = String(init.view || 'month');
      const next = dateEl.value;
      if (!next) return;
      window.location.href = `?view=${encodeURIComponent(v)}&date=${encodeURIComponent(next)}`;
    });
  }
  if (btnTodayDetails) btnTodayDetails.addEventListener('click', () => openDetail(init.today));

  if (applyFiltersEl) applyFiltersEl.addEventListener('click', () => {
    filters = {
      status: filterStatusEl ? String(filterStatusEl.value || '') : '',
      minWork: filterMinWorkEl ? String(filterMinWorkEl.value || '') : '',
      shift: filterShiftEl ? String(filterShiftEl.value || '') : ''
    };
    load();
    const el = document.getElementById('hrAttFilters');
    if (el) bootstrap.Offcanvas.getOrCreateInstance(el).hide();
  });
  if (clearFiltersEl) clearFiltersEl.addEventListener('click', () => {
    filters = { status: '', minWork: '', shift: '' };
    if (filterStatusEl) filterStatusEl.value = '';
    if (filterMinWorkEl) filterMinWorkEl.value = '';
    if (filterShiftEl) filterShiftEl.value = '';
    load();
  });

  load();
  window.setInterval(refreshCurrentView, 60000);
})();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
