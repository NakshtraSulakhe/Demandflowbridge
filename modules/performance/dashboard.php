<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('funneltrack360.dashboard.page', []);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$canTeam = function_exists('userHasPermission') ? userHasPermission('funneltrack360.team.page') : true;
$canReports = function_exists('userHasPermission') ? userHasPermission('funneltrack360.reports.page') : true;
$canAdmin = function_exists('userHasPermission') ? userHasPermission('funneltrack360.admin.page') : true;

$cfg = funneltrackGetConfig();
$tz = opsBusinessTz();
$now = new DateTimeImmutable('now', $tz);
$year = (int)$now->format('Y');
$month = (int)$now->format('n');
$today = $now->format('Y-m-d');

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($now->format('Y-m-01') . ' 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($now->format('Y-m-t') . ' 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));

$monthSummaryBatch = function_exists('funneltrackComputeAgentMonthSummaries') ? funneltrackComputeAgentMonthSummaries([$userId], $year, $month, $cfg) : [];
$monthSummary = $monthSummaryBatch[$userId] ?? funneltrackComputeAgentMonthSummary($userId, $year, $month, $cfg);
$dailyTarget = (int)($monthSummary['targets']['daily'] ?? 0);
$monthlyBefore = (int)($monthSummary['incentives']['monthly_before_deductions'] ?? (int)($monthSummary['incentives']['monthly'] ?? 0));
$monthlyAfter = (int)($monthSummary['incentives']['monthly'] ?? 0);
$monthDeductions = $monthSummary['incentives']['deductions'] ?? [];
if (!is_array($monthDeductions)) $monthDeductions = [];

$todayRange = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => $today . ' 00:00:00', 'end' => $today . ' 23:59:59'];
$startToday = (string)($todayRange['start'] ?? ($today . ' 00:00:00'));
$endToday = (string)($todayRange['end'] ?? ($today . ' 23:59:59'));
$todayPoints = funneltrackComputeAgentPeriodPoints($userId, $startToday, $endToday, $cfg);
$achievedToday = (float)($todayPoints['points'] ?? 0.0);
$todayPct = $dailyTarget > 0 ? round(($achievedToday / (float)$dailyTarget) * 100.0, 2) : 0.0;

$rangePoints = funneltrackComputeAgentPeriodPoints($userId, $rangeStart, $rangeEnd, $cfg);
$achievedRange = (float)($rangePoints['points'] ?? 0.0);

$escActive = (bool)($monthSummary['incentives']['escalation_active'] ?? false);
$escMode = (string)($cfg['escalation_rule']['mode'] ?? 'disqualify');
$eligible = (bool)($monthSummary['incentives']['eligible'] ?? false);
$dailyEligible = !($escActive && $escMode === 'disqualify');
$dailyIncentive = $dailyEligible ? funneltrackComputeDailyIncentive((float)$dailyTarget, $achievedToday, $cfg) : 0;
if ($escActive && $dailyIncentive > 0 && $escMode === 'partial') {
    $dedPct = (float)($cfg['escalation_rule']['deduction_pct'] ?? 100);
    if ($dedPct < 0) $dedPct = 0;
    if ($dedPct > 100) $dedPct = 100;
    $dailyIncentive = (int)round($dailyIncentive * (1.0 - ($dedPct / 100.0)));
}

$conn = getDbConnection();
$startMonth = sprintf('%04d-%02d-01', $year, $month);
$endMonth = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
$monthRangeUtc = function_exists('opsDateRangeUtc') ? opsDateRangeUtc($startMonth, $endMonth) : ['start' => $startMonth . ' 00:00:00', 'end' => $endMonth . ' 23:59:59'];
$startTs = (string)($monthRangeUtc['start'] ?? ($startMonth . ' 00:00:00'));
$endTs = (string)($monthRangeUtc['end'] ?? ($endMonth . ' 23:59:59'));

$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts($userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$attendance = ['status' => '', 'work_minutes' => 0, 'late_minutes' => 0];
$attendanceToday = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$stmt = $conn->prepare("SELECT status, work_minutes, late_minutes FROM hr_attendance_days WHERE user_id = ? AND work_date = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('is', $userId, $attendanceToday);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $attendance['status'] = (string)($r['status'] ?? '');
    $attendance['work_minutes'] = (int)($r['work_minutes'] ?? 0);
    $attendance['late_minutes'] = (int)($r['late_minutes'] ?? 0);
}

$pointsMap = funneltrackCampaignPointsMap($cfg);
$dailyTrend = [];
$stmt = $conn->prepare("
    SELECT COALESCE(l.qa_updated_at, l.created_at) AS qualified_at, d2.campaign_type
    FROM leads l
    JOIN campaign_details d2 ON d2.campaign_id = l.campaign_id
    WHERE l.agent_id = ?
      AND l.qa_status = 'Qualified'
      AND COALESCE(l.qa_updated_at, l.created_at) >= ?
      AND COALESCE(l.qa_updated_at, l.created_at) <= ?
");
if ($stmt) {
    $stmt->bind_param('iss', $userId, $startTs, $endTs);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $d = opsDateFromUtc((string)($r['qualified_at'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $ct = funneltrackNormalizeCampaignType((string)($r['campaign_type'] ?? ''));
        if ($ct === '' || !array_key_exists($ct, $pointsMap)) continue;
        if (!isset($dailyTrend[$d])) $dailyTrend[$d] = 0.0;
        $dailyTrend[$d] += (float)$pointsMap[$ct];
    }
}
ksort($dailyTrend);
$trendLabels = array_keys($dailyTrend);
$trendValues = array_map(fn($v) => round((float)$v, 2), array_values($dailyTrend));

$endCapDate = ($today < $endMonth) ? $today : $endMonth;
$elapsedDates = funneltrackWorkingDatesInRange($startMonth, $endCapDate, $cfg);
$paidLeaveElapsed = funneltrackPaidLeaveDateSet($userId, $startMonth, $endCapDate);
$daysElapsed = 0;
foreach ($elapsedDates as $d) {
    if (!isset($paidLeaveElapsed[$d])) $daysElapsed++;
}
$eligibleWorkingDays = (int)($monthSummary['targets']['eligible_working_days'] ?? 0);
$monthlyTarget = (float)($monthSummary['targets']['monthly'] ?? 0);
$achievedMonth = (float)($monthSummary['points']['achieved'] ?? 0.0);
$avgPerDay = $daysElapsed > 0 ? ($achievedMonth / (float)$daysElapsed) : 0.0;
$projectedPoints = $eligibleWorkingDays > 0 ? ($avgPerDay * (float)$eligibleWorkingDays) : 0.0;
$projectedPct = $monthlyTarget > 0 ? round(($projectedPoints / $monthlyTarget) * 100.0, 2) : 0.0;
$projectedMonthlyIncentive = $eligible ? funneltrackComputeMonthlyIncentive($projectedPct, $cfg) : 0;

$monthDates = funneltrackWorkingDatesInRange($startMonth, $endMonth, $cfg);
$paidLeaveSet = funneltrackPaidLeaveDateSet($userId, $startMonth, $endMonth);
$dailyHistory = [];
$dailyIncentivesMonth = 0;
foreach ($monthDates as $d) {
    $isPaidLeave = isset($paidLeaveSet[$d]);
    $target = $isPaidLeave ? 0 : (int)$dailyTarget;
    $pts = (float)($dailyTrend[$d] ?? 0.0);
    $pct = $target > 0 ? round(($pts / (float)$target) * 100.0, 1) : 0.0;
    $inc = ($dailyEligible && !$isPaidLeave) ? funneltrackComputeDailyIncentive((float)$target, $pts, $cfg) : 0;
    if ($escActive && $inc > 0 && $escMode === 'partial') {
        $dedPct = (float)($cfg['escalation_rule']['deduction_pct'] ?? 100);
        if ($dedPct < 0) $dedPct = 0;
        if ($dedPct > 100) $dedPct = 100;
        $inc = (int)round($inc * (1.0 - ($dedPct / 100.0)));
    }
    $dailyIncentivesMonth += (int)$inc;
    $dailyHistory[] = ['date' => $d, 'points' => $pts, 'target' => $target, 'pct' => $pct, 'incentive' => (int)$inc, 'paid_leave' => $isPaidLeave];
}

$monthlyHistory = [];
for ($i = 0; $i < 12; $i++) {
    $dt = $now->modify('-' . $i . ' month');
    $yy = (int)$dt->format('Y');
    $mm = (int)$dt->format('n');
    $sum = funneltrackComputeAgentMonthSummary($userId, $yy, $mm, $cfg);
    $monthlyHistory[] = [
        'ym' => $dt->format('Y-m'),
        'productivity_pct' => (float)($sum['productivity_pct'] ?? 0.0),
        'score' => (float)($sum['score']['total'] ?? 0.0),
        'rank' => (string)($sum['rank'] ?? ''),
        'incentive' => (int)($sum['incentives']['monthly'] ?? 0),
        'eligible' => (bool)($sum['incentives']['eligible'] ?? false),
    ];
}

$rank = null;
$topPeers = [];
$bottomPeers = [];
try {
    $users = getInternalPayrollUsers();
    $scores = [];
    $nameMap = [];
    $peerIds = [];
    foreach ($users as $u) {
        $uid = (int)($u['id'] ?? 0);
        $role = strtolower((string)($u['role'] ?? ''));
        if ($uid <= 0) continue;
        if (!in_array($role, ['agent','operations_agent','email_marketing_agent','email_marketing_executive','form_filler'], true)) continue;
        $nameMap[$uid] = (string)($u['full_name'] ?? ('User #' . $uid));
        $peerIds[] = $uid;
    }
    $peerMonthSummaries = !empty($peerIds) && function_exists('funneltrackComputeAgentMonthSummaries')
        ? funneltrackComputeAgentMonthSummaries($peerIds, $year, $month, $cfg)
        : [];
    foreach ($peerIds as $uid) {
        $s = $peerMonthSummaries[$uid] ?? funneltrackComputeAgentMonthSummary($uid, $year, $month, $cfg);
        $scores[] = ['user_id' => $uid, 'score' => (float)($s['score']['total'] ?? 0.0)];
    }
    usort($scores, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['user_id'] <=> $b['user_id']));
    $pos = 0;
    foreach ($scores as $i => $row) {
        if ((int)$row['user_id'] === $userId) { $pos = $i + 1; break; }
    }
    if ($pos > 0) $rank = ['pos' => $pos, 'total' => count($scores)];
    $topPeers = array_slice($scores, 0, 3);
    $bottomPeers = array_slice(array_reverse($scores), 0, 3);
    foreach ($topPeers as &$r) { $r['name'] = $nameMap[(int)($r['user_id'] ?? 0)] ?? ''; }
    unset($r);
    foreach ($bottomPeers as &$r) { $r['name'] = $nameMap[(int)($r['user_id'] ?? 0)] ?? ''; }
    unset($r);
} catch (Throwable $e) {}

$monthProductivityPct = (float)($monthSummary['productivity_pct'] ?? 0.0);
$monthScore = (float)($monthSummary['score']['total'] ?? 0.0);
$attendancePct = (float)($monthSummary['attendance']['attendance_pct'] ?? 0.0);
$qualityPct = 100.0 - (float)($monthSummary['quality']['ratio_pct'] ?? 0.0);
$statusChips = [];
$statusChips[] = $eligible ? 'Monthly incentive eligible' : 'Monthly incentive blocked';
if ($escActive) {
    $statusChips[] = 'Escalation mode: ' . $escMode;
}
if ($rank) {
    $statusChips[] = 'Leaderboard rank ' . (int)$rank['pos'] . '/' . (int)$rank['total'];
}

$pageTitle = 'DemandFlow Bridge – Performance & Incentives';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace performance-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-graph-up-arrow"></i>Performance Intelligence</div>
            <h3 class="sales-page-title">Performance & Incentives</h3>
            <div class="sales-page-subtitle">Track daily points, productivity trend, score quality, and incentive readiness from one CRM-aligned workspace.</div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="sales-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($now->format('M Y')); ?></span>
                <span class="sales-chip"><i class="bi bi-clock-history"></i><?php echo htmlspecialchars($rangeLabel); ?></span>
                <?php foreach ($statusChips as $chip): ?>
                    <span class="sales-chip"><?php echo htmlspecialchars($chip); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=' . urlencode((string)$userId)); ?>"><i class="bi bi-table me-1"></i>Daily Detail</a>
            <?php if ($canTeam): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>"><i class="bi bi-people me-1"></i>Team View</a>
            <?php endif; ?>
            <?php if ($canReports): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Reports</a>
            <?php endif; ?>
            <?php if ($canAdmin): ?>
                <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>"><i class="bi bi-gear me-1"></i>Admin</a>
            <?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav performance-nav mb-3">
        <li class="nav-item"><a class="nav-link active" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>">My Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=' . urlencode((string)$userId)); ?>">Daily Detail</a></li>
        <?php if ($canTeam): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>">Team View</a></li><?php endif; ?>
        <?php if ($canReports): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>">Reports</a></li><?php endif; ?>
        <?php if ($canAdmin): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>">Admin</a></li><?php endif; ?>
    </ul>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Monthly Score</div>
                <div class="performance-callout-value"><?php echo number_format($monthScore, 1); ?>/100</div>
                <div class="small text-muted mt-1"><?php echo htmlspecialchars((string)($monthSummary['rank'] ?? '')); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Productivity</div>
                <div class="performance-callout-value"><?php echo number_format($monthProductivityPct, 1); ?>%</div>
                <div class="small text-muted mt-1">Target progress for the current month</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Attendance</div>
                <div class="performance-callout-value"><?php echo number_format($attendancePct, 1); ?>%</div>
                <div class="small text-muted mt-1">Attendance contribution to final score</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Quality</div>
                <div class="performance-callout-value"><?php echo number_format($qualityPct, 1); ?>%</div>
                <div class="small text-muted mt-1">Based on qualified vs disqualified output</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel mb-3">
        <div class="card-body">
            <div class="performance-toolbar-actions mb-2">
                <div>
                    <div class="fw-semibold">Filter View</div>
                    <div class="text-muted small">Switch between quick presets or a custom date range for your productivity trend and incentives.</div>
                </div>
            </div>
            <form class="row g-2 align-items-end sales-filter-form" method="get">
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
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm sales-kpi-card kpi-primary h-100">
                <div class="card-body">
                    <div class="text-muted small">Today’s Points</div>
                    <div class="h4 mb-0"><?php echo number_format($achievedToday, 2); ?> / <?php echo number_format((float)$dailyTarget, 0); ?></div>
                    <div class="small text-muted">Productivity: <?php echo number_format($todayPct, 1); ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm sales-kpi-card kpi-info h-100">
                <div class="card-body">
                    <div class="text-muted small">Points (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
                    <div class="h4 mb-0"><?php echo number_format($achievedRange, 2); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars($sd); ?> → <?php echo htmlspecialchars($ed); ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm sales-kpi-card <?php echo $dailyEligible ? 'kpi-success' : 'kpi-danger'; ?> h-100">
                <div class="card-body">
                    <div class="text-muted small">Today’s Incentive</div>
                    <div class="h4 mb-0">₹<?php echo number_format((int)$dailyIncentive); ?></div>
                    <div class="small text-muted"><?php echo $dailyEligible ? 'Eligible' : 'Not eligible (Escalation)'; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm sales-kpi-card kpi-warning h-100">
                <div class="card-body">
                    <div class="text-muted small">Monthly Points</div>
                    <div class="h4 mb-0"><?php echo number_format((float)($monthSummary['points']['achieved'] ?? 0.0), 2); ?> / <?php echo number_format((float)($monthSummary['targets']['monthly'] ?? 0), 0); ?></div>
                    <div class="small text-muted">Productivity: <?php echo number_format((float)($monthSummary['productivity_pct'] ?? 0.0), 1); ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm sales-kpi-card h-100">
                <div class="card-body">
                    <div class="text-muted small">Performance Score</div>
                    <div class="h4 mb-0"><?php echo number_format((float)($monthSummary['score']['total'] ?? 0.0), 1); ?>/100</div>
                    <div class="small text-muted"><?php echo htmlspecialchars((string)($monthSummary['rank'] ?? '')); ?><?php echo $rank ? (' · Rank ' . (int)$rank['pos'] . '/' . (int)$rank['total']) : ''; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-4">
            <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>">
                <div class="card border-0 shadow-sm sales-panel h-100">
                    <div class="card-body">
                    <div class="text-muted small">My Tasks</div>
                    <div class="h4 mb-0"><?php echo number_format((int)$taskWidget['pending']); ?></div>
                    <div class="small text-muted">Due: <?php echo number_format((int)$taskWidget['due_today']); ?> · Overdue: <?php echo number_format((int)$taskWidget['overdue']); ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12 col-lg-4">
            <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/hr/attendance'); ?>">
                <div class="card border-0 shadow-sm sales-panel h-100">
                    <div class="card-body">
                    <div class="text-muted small">Attendance (Today)</div>
                    <div class="h4 mb-0"><?php echo number_format((int)floor(((int)$attendance['work_minutes']) / 60)) . ':' . str_pad((string)(((int)$attendance['work_minutes']) % 60), 2, '0', STR_PAD_LEFT); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars($attendance['status'] !== '' ? $attendance['status'] : 'Not started'); ?> · Late: <?php echo number_format((int)$attendance['late_minutes']); ?> min</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm sales-panel h-100">
                <div class="card-header fw-semibold"><i class="bi bi-trophy me-1"></i>Top / Bottom Performers (This Month)</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="text-muted small mb-2">Top</div>
                            <?php if (empty($topPeers)): ?>
                                <div class="text-muted small">No data.</div>
                            <?php else: ?>
                                <div class="performer-mini-list">
                                <?php foreach ($topPeers as $idx => $r): ?>
                  <?php $podium = getPerformerPodiumMeta((int)$idx); ?>
                                    <div class="performer-mini-item">
                                        <div class="performer-mini-main">
                                            <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                                            <div class="performer-mini-copy">
                                                <div class="performer-mini-name"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></div>
                                                <div class="performer-mini-meta"><?php echo htmlspecialchars($podium['place']); ?></div>
                                            </div>
                                        </div>
                                        <span class="performer-mini-value"><?php echo number_format((float)($r['score'] ?? 0.0), 1); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small mb-2">Bottom</div>
                            <?php if (empty($bottomPeers)): ?>
                                <div class="text-muted small">No data.</div>
                            <?php else: ?>
                                <div class="performer-mini-list">
                                <?php foreach ($bottomPeers as $idx => $r): ?>
                                    <?php $podium = getPerformerPodiumMeta((int)$idx, 'bottom'); ?>
                                    <div class="performer-mini-item">
                                        <div class="performer-mini-main">
                                            <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                                            <div class="performer-mini-copy">
                                                <div class="performer-mini-name"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></div>
                                                <div class="performer-mini-meta"><?php echo htmlspecialchars($podium['place']); ?></div>
                                            </div>
                                        </div>
                                        <span class="performer-mini-value"><?php echo number_format((float)($r['score'] ?? 0.0), 1); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm sales-panel h-100">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-up me-1"></i>Daily Productivity Trend</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#prodInfo"><i class="bi bi-info-circle me-1"></i>How Productivity Works</button>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="90"></canvas>
                    <div class="performance-table-note">
                        Shows qualified-lead points day by day for the current month so you can spot drop-offs before monthly incentives are affected.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sales-panel h-100">
                <div class="card-header fw-semibold"><i class="bi bi-award me-1"></i>Monthly Incentive</div>
                <div class="card-body">
                    <div class="text-muted small">Earned</div>
                    <div class="h4">₹<?php echo number_format((int)($monthSummary['incentives']['monthly'] ?? 0)); ?></div>
                    <?php if ($monthlyBefore > $monthlyAfter): ?>
                        <div class="small text-muted">After deductions (was ₹<?php echo number_format((int)$monthlyBefore); ?>)</div>
                    <?php endif; ?>
                    <div class="text-muted small mt-2">Projected</div>
                    <div class="fw-semibold">₹<?php echo number_format((int)$projectedMonthlyIncentive); ?> <span class="text-muted small">(<?php echo number_format($projectedPct, 1); ?>%)</span></div>
                    <div class="text-muted small mt-2">Daily Incentives (Month)</div>
                    <div class="fw-semibold">₹<?php echo number_format((int)$dailyIncentivesMonth); ?></div>
                    <div class="text-muted small mt-2">Quality</div>
                    <div class="fw-semibold"><?php echo number_format(100.0 - (float)($monthSummary['quality']['ratio_pct'] ?? 0.0), 1); ?>%</div>
                    <div class="text-muted small mt-2">Attendance</div>
                    <div class="fw-semibold"><?php echo number_format((float)($monthSummary['attendance']['attendance_pct'] ?? 0.0), 1); ?>%</div>
                    <div class="text-muted small mt-2">Adherence</div>
                    <div class="fw-semibold"><?php echo number_format((float)($monthSummary['adherence']['adherence_pct'] ?? 0.0), 1); ?>% (Avg <?php echo number_format((int)($monthSummary['adherence']['avg_minutes'] ?? 0)); ?>m)</div>
                    <div class="small text-muted">Minutes: <?php echo number_format((int)($monthSummary['adherence']['worked_total_minutes'] ?? 0)); ?> / <?php echo number_format((int)($monthSummary['adherence']['required_total_minutes'] ?? 0)); ?></div>
                    <div class="performance-stacked-note mt-3">Projected payout is based on current pace and can still change with attendance, quality, adherence, or escalation deductions.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card border-0 shadow-sm sales-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-clock-history me-1"></i>History</div>
                    <ul class="nav nav-pills nav-pills-sm" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="hist-daily-tab" data-bs-toggle="tab" data-bs-target="#hist-daily" type="button" role="tab">Daily (Month)</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="hist-monthly-tab" data-bs-toggle="tab" data-bs-target="#hist-monthly" type="button" role="tab">Monthly (12M)</button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="hist-daily" role="tabpanel" aria-labelledby="hist-daily-tab">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-end">Target</th>
                                            <th class="text-end">Points</th>
                                            <th class="text-end">%</th>
                                            <th class="text-end">Incentive</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($dailyHistory)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No data.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($dailyHistory as $h): ?>
                                                <tr>
                                                    <td class="font-monospace"><?php echo htmlspecialchars((string)$h['date']); ?></td>
                                                    <td class="text-end font-monospace"><?php echo number_format((int)$h['target']); ?></td>
                                                    <td class="text-end font-monospace"><?php echo number_format((float)$h['points'], 2); ?></td>
                                                    <td class="text-end font-monospace"><?php echo number_format((float)$h['pct'], 1); ?>%</td>
                                                    <td class="text-end font-monospace">₹<?php echo number_format((int)$h['incentive']); ?></td>
                                                    <td><?php echo $h['paid_leave'] ? '<span class="badge bg-secondary">Paid Leave</span>' : '<span class="badge bg-light text-dark border">Workday</span>'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="hist-monthly" role="tabpanel" aria-labelledby="hist-monthly-tab">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-end">Productivity %</th>
                                            <th class="text-end">Score</th>
                                            <th>Rank</th>
                                            <th class="text-end">Incentive</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthlyHistory as $m): ?>
                                            <tr>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)$m['ym']); ?></td>
                                                <td class="text-end font-monospace"><?php echo number_format((float)$m['productivity_pct'], 1); ?>%</td>
                                                <td class="text-end font-monospace"><?php echo number_format((float)$m['score'], 1); ?></td>
                                                <td><?php echo htmlspecialchars((string)$m['rank']); ?></td>
                                                <td class="text-end font-monospace">₹<?php echo number_format((int)$m['incentive']); ?></td>
                                                <td><?php echo $m['eligible'] ? '<span class="badge bg-success">Eligible</span>' : '<span class="badge bg-secondary">Not Eligible</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="prodInfo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="fw-semibold"><i class="bi bi-info-circle me-1"></i>Productivity Calculation</div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 fw-semibold">Monthly Productivity %</div>
                <div class="text-muted small">Productivity % = (Achieved Points ÷ Monthly Target Points) × 100</div>
                <hr>
                <div class="mb-2 fw-semibold">Point Allocation (Qualified Leads Only)</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Campaign Type</th>
                                <th class="text-end">Points per Qualified Lead</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pointsMap as $k => $v): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$k); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((float)$v, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <hr>
                <div class="mb-2 fw-semibold">Eligibility Notes</div>
                <ul class="mb-0">
                    <li>Only Internal QA Status = Qualified is counted for points.</li>
                    <li>Escalation rule can impact incentive eligibility based on admin configuration.</li>
                    <li>Daily and monthly incentives use configurable slabs.</li>
                </ul>
                <hr>
                <div class="mb-2 fw-semibold">Monthly Incentive Deductions</div>
                <ul class="mb-0">
                    <li>Paid Leave: no target is counted for that day (it is excluded from target working days).</li>
                    <li>Unpaid Leave: target is still counted for that day, and monthly incentive gets a 30% deduction if any unpaid leave exists in the month.</li>
                    <li>If Disqualification Ratio is above <?php echo number_format((float)($cfg['thresholds']['max_disqualification_ratio_pct'] ?? 10), 0); ?>%: 10% deduction on monthly incentive.</li>
                    <li>If total login minutes for the month is below (<?php echo number_format((int)($monthSummary['adherence']['required_minutes'] ?? 465)); ?> × eligible working days): 20% deduction on monthly incentive.</li>
                </ul>
                <?php if (!empty($monthDeductions)): ?>
                    <div class="small text-muted mt-2">
                        Applied this month:
                        <?php echo htmlspecialchars(implode(', ', array_map(fn($d) => trim((string)($d['pct'] ?? '')) . '% - ' . trim((string)($d['label'] ?? '')), $monthDeductions))); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(() => {
  const labels = <?php echo json_encode(array_values($trendLabels), JSON_UNESCAPED_SLASHES); ?>;
  const values = <?php echo json_encode(array_values($trendValues), JSON_UNESCAPED_SLASHES); ?>;
  const el = document.getElementById('trendChart');
  if (!el) return;
  const ctx = el.getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Points',
        data: values,
        borderColor: '#0d6efd',
        backgroundColor: 'rgba(13,110,253,0.12)',
        fill: true,
        tension: 0.25,
        pointRadius: 2
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } }
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
