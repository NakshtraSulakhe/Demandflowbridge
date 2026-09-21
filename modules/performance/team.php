<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('funneltrack360.team.page', []);
ensureDatabaseSchema();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$canReports = function_exists('userHasPermission') ? userHasPermission('funneltrack360.reports.page') : true;
$canAdmin = function_exists('userHasPermission') ? userHasPermission('funneltrack360.admin.page') : true;

$cfg = funneltrackGetConfig();
$tz = opsBusinessTz();
$now = new DateTimeImmutable('now', $tz);

$preset = isset($_GET['range_preset']) ? (string)$_GET['range_preset'] : 'today';
$startInput = isset($_GET['start_date']) ? (string)$_GET['start_date'] : '';
$endInput = isset($_GET['end_date']) ? (string)$_GET['end_date'] : '';
$range = funneltrackRangeFromPreset($preset, $startInput, $endInput);
$startDate = (string)($range['start'] ?? $now->format('Y-m-d'));
$endDate = (string)($range['end'] ?? $startDate);
$rangeLabel = (string)($range['label'] ?? '');
$startFieldValue = $preset === 'custom' ? $startInput : $startDate;
$endFieldValue = $preset === 'custom' ? $endInput : $endDate;

$conn = getDbConnection();

$visibleUsers = funneltrackGetVisiblePerformanceUsers($userId);
$visibleUserIds = [];
foreach ($visibleUsers as $uid => $urow) {
    $uid = (int)$uid;
    if ($uid > 0) $visibleUserIds[$uid] = true;
}

$startDateStr = substr((string)$startDate, 0, 10);
$endDateStr = substr((string)$endDate, 0, 10);
$rangeUtc = function_exists('opsDateRangeUtc') ? opsDateRangeUtc($startDateStr, $endDateStr) : ['start' => $startDateStr . ' 00:00:00', 'end' => $endDateStr . ' 23:59:59'];
$rangeStartTs = (string)($rangeUtc['start'] ?? ($startDateStr . ' 00:00:00'));
$rangeEndTs = (string)($rangeUtc['end'] ?? ($endDateStr . ' 23:59:59'));
$qualifiedLeads = [];
if (!empty($visibleUserIds)) {
    $ids = array_keys($visibleUserIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("
        SELECT agent_id, COUNT(*) AS count
        FROM leads
        WHERE agent_id IN ($in)
          AND qa_status = 'Qualified'
          AND COALESCE(qa_updated_at, created_at) >= ?
          AND COALESCE(qa_updated_at, created_at) <= ?
        GROUP BY agent_id
    ");
    if ($stmt) {
        $params = array_merge($ids, [$rangeStartTs, $rangeEndTs]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $qRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($qRows as $q) {
            $qualifiedLeads[(int)($q['agent_id'] ?? 0)] = (int)($q['count'] ?? 0);
        }
    }
}

$targetsByAgent = [];
$targetYear = (int)$now->format('Y');
$targetMonth = (int)$now->format('n');
if (preg_match('/^(\d{4})-(\d{2})/', $startDateStr, $matches)) {
    $targetYear = (int)$matches[1];
    $targetMonth = (int)$matches[2];
}
for ($i = 0; $i < 3; $i++) {
    $tryYear = $targetYear;
    $tryMonth = $targetMonth - $i;
    while ($tryMonth < 1) {
        $tryMonth += 12;
        $tryYear--;
    }
    foreach (getMonthlyTargets($tryYear, $tryMonth) as $t) {
        $aid = (int)($t['agent_id'] ?? 0);
        if ($aid > 0) $targetsByAgent[$aid] = $t;
    }
    if (!empty($targetsByAgent)) break;
}

$presentDaysByUser = [];
if (!empty($visibleUserIds)) {
    $ids = array_keys($visibleUserIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("
        SELECT user_id, status, COUNT(*) AS c
        FROM hr_attendance_days
        WHERE user_id IN ($in)
          AND work_date BETWEEN ? AND ?
          AND status IN ('Full Day','Present','present','Half Day','half_day')
        GROUP BY user_id, status
    ");
    if ($stmt) {
        $params = array_merge($ids, [$startDateStr, $endDateStr]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $aRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($aRows as $a) {
            $uid = (int)($a['user_id'] ?? 0);
            $status = strtolower((string)($a['status'] ?? ''));
            $count = (int)($a['c'] ?? 0);
            if (!isset($presentDaysByUser[$uid])) $presentDaysByUser[$uid] = 0.0;
            if ($status === 'full day' || $status === 'present') $presentDaysByUser[$uid] += $count;
            if ($status === 'half day' || $status === 'half_day') $presentDaysByUser[$uid] += ($count * 0.5);
        }
    }
}

$rows = [];
if (!empty($visibleUserIds)) {
    $ids = array_keys($visibleUserIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $summaries = funneltrackComputeAgentRangeSummaries($ids, $startDate, $endDate, $cfg);
    $monthAnchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDateStr) ? $endDateStr : $now->format('Y-m-d');
    $monthStart = substr($monthAnchor, 0, 7) . '-01';
    $monthlySummaries = funneltrackComputeAgentRangeSummaries($ids, $monthStart, $monthAnchor, $cfg);
    $stmtU = $conn->prepare("SELECT id, full_name, role, job_title, department FROM users WHERE id IN ($in) AND is_active = 1 ORDER BY full_name");
    if ($stmtU) {
        $stmtU->bind_param($types, ...$ids);
        $stmtU->execute();
        $urows = $stmtU->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmtU->close();
        foreach ($urows as $u) {
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) continue;
            $sum = $summaries[$uid] ?? [];
            $monthlySum = $monthlySummaries[$uid] ?? [];
            $target = $targetsByAgent[$uid] ?? ['daily_target' => 0, 'working_days' => 0];
            $presentDays = (float)($presentDaysByUser[$uid] ?? 0.0);
            $rows[] = [
                'user_id' => $uid,
                'name' => (string)($u['full_name'] ?? ''),
                'role' => (string)($u['role'] ?? ''),
                'job_title' => (string)($u['job_title'] ?? ''),
                'department' => (string)($u['department'] ?? ''),
                'score' => (float)($sum['score']['total'] ?? 0.0),
                'rank_label' => (string)($sum['rank'] ?? ''),
                'productivity_pct' => (float)($sum['productivity_pct'] ?? 0.0),
                'monthly_productivity_pct' => (float)($monthlySum['productivity_pct'] ?? 0.0),
                'range_incentive' => (int)($sum['incentives']['range_total'] ?? 0),
                'monthly_bonus' => (int)($monthlySum['incentives']['monthly_bonus'] ?? 0),
                'eligible' => (bool)($monthlySum['incentives']['eligible'] ?? false),
                'daily_target' => (int)($target['daily_target'] ?? 0),
                'working_days' => (int)($target['working_days'] ?? 0),
                'qualified_leads' => (int)($qualifiedLeads[$uid] ?? 0),
                'generated_leads' => (int)($sum['quality']['qualified'] ?? 0) + (int)($sum['quality']['disqualified'] ?? 0),
                'disqualified_leads' => (int)($sum['quality']['disqualified'] ?? 0),
                'target_points' => (float)($sum['targets']['total'] ?? 0.0),
                'eligible_working_days' => (int)($sum['targets']['eligible_working_days'] ?? 0),
                'achieved_points' => (float)($sum['points']['achieved'] ?? 0.0),
                'attendance_pct' => (float)($sum['attendance']['attendance_pct'] ?? 0.0),
                'adherence_pct' => (float)($sum['adherence']['adherence_pct'] ?? 0.0),
                'avg_minutes' => (int)($sum['adherence']['avg_minutes'] ?? 0),
                'required_minutes' => (int)($sum['adherence']['required_minutes'] ?? 0),
                'quality_ratio_pct' => (float)($sum['quality']['ratio_pct'] ?? 0.0),
                'qualified_total' => (int)($sum['quality']['qualified'] ?? 0),
                'disqualified_total' => (int)($sum['quality']['disqualified'] ?? 0),
                'score_productivity' => (float)($sum['score']['productivity'] ?? 0.0),
                'score_adherence' => (float)($sum['score']['adherence'] ?? 0.0),
                'score_absenteeism' => (float)($sum['score']['absenteeism'] ?? 0.0),
                'score_quality' => (float)($sum['score']['quality'] ?? 0.0),
                'escalation_active' => (bool)($sum['incentives']['escalation_active'] ?? false),
            ];
        }
    }
}

usort($rows, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['productivity_pct'] <=> $a['productivity_pct']) ?: strcmp($a['name'], $b['name']));

foreach ($rows as $i => &$r) {
    $r['pos'] = $i + 1;
}
unset($r);

$teamCount = count($rows);
$eligibleCount = 0;
$avgScore = 0.0;
$avgProductivity = 0.0;
foreach ($rows as $r) {
    if (!empty($r['eligible'])) $eligibleCount++;
    $avgScore += (float)($r['score'] ?? 0.0);
    $avgProductivity += (float)($r['productivity_pct'] ?? 0.0);
}
if ($teamCount > 0) {
    $avgScore /= $teamCount;
    $avgProductivity /= $teamCount;
}
$formatPresentDays = static function(float $value): string {
    return abs($value - floor($value)) < 0.001 ? number_format($value, 0) : number_format($value, 1);
};
$rankBadgeClass = static function(int $pos): string {
    if ($pos === 1) return 'performance-rank-top1';
    if ($pos === 2) return 'performance-rank-top2';
    if ($pos === 3) return 'performance-rank-top3';
    return 'performance-rank-default';
};
$statusBadgeClass = static function(bool $eligible): string {
    return $eligible ? 'performance-status-badge performance-status-eligible' : 'performance-status-badge performance-status-pending';
};

$pageTitle = 'DemandFlow Bridge – Performance';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace performance-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-trophy"></i>Team Leaderboard</div>
            <h3 class="sales-page-title">Performance</h3>
            <div class="sales-page-subtitle">Compare productivity, score, incentive eligibility, and ranking across your visible team without leaving the CRM workflow.</div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>"><i class="bi bi-speedometer2 me-1"></i>My Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=all&range_preset=' . urlencode((string)$preset) . '&start_date=' . urlencode((string)$startDate) . '&end_date=' . urlencode((string)$endDate)); ?>"><i class="bi bi-table me-1"></i>Daily Detail</a>
            <?php if ($canReports): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Reports</a><?php endif; ?>
            <?php if ($canAdmin): ?><a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>"><i class="bi bi-gear me-1"></i>Admin</a><?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav performance-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>">My Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=all&range_preset=' . urlencode((string)$preset) . '&start_date=' . urlencode((string)$startDate) . '&end_date=' . urlencode((string)$endDate)); ?>">Daily Detail</a></li>
        <li class="nav-item"><a class="nav-link active" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>">Team View</a></li>
        <?php if ($canReports): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>">Reports</a></li><?php endif; ?>
        <?php if ($canAdmin): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>">Admin</a></li><?php endif; ?>
    </ul>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Visible Users</div><div class="performance-callout-value"><?php echo number_format($teamCount); ?></div><div class="small text-muted mt-1"><?php echo htmlspecialchars($rangeLabel); ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Eligible Users</div><div class="performance-callout-value"><?php echo number_format($eligibleCount); ?></div><div class="small text-muted mt-1"><?php echo $teamCount > 0 ? htmlspecialchars(number_format(($eligibleCount / $teamCount) * 100, 1) . '% of visible team') : 'No users in scope'; ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Average Score</div><div class="performance-callout-value"><?php echo number_format($avgScore, 1); ?></div><div class="small text-muted mt-1">Across all visible users</div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Average Productivity</div><div class="performance-callout-value"><?php echo number_format($avgProductivity, 1); ?>%</div><div class="small text-muted mt-1">Points vs target in selected range</div></div></div>
    </div>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel mb-3">
        <div class="card-body">
            <div class="performance-toolbar-actions mb-2">
                <div>
                    <div class="fw-semibold">Leaderboard Filter</div>
                    <div class="text-muted small">Use quick presets or a custom date range to compare the latest team standings.</div>
                </div>
                <div class="performance-range-readout">
                    <span class="sales-chip"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></span>
                </div>
            </div>
            <form class="row g-2 align-items-end sales-filter-form" method="get">
        <div class="col-md-3">
            <label class="form-label small text-muted">Range</label>
            <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
                <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
                <option value="month" <?php echo $preset==='month'?'selected':''; ?>>Month</option>
                <option value="week" <?php echo $preset==='week'?'selected':''; ?>>Week</option>
                <option value="quarter" <?php echo $preset==='quarter'?'selected':''; ?>>Quarter</option>
                <option value="year" <?php echo $preset==='year'?'selected':''; ?>>Year</option>
                <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Start</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($startFieldValue); ?>" <?php echo $preset==='custom'?'':'readonly'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">End</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($endFieldValue); ?>" <?php echo $preset==='custom'?'':'readonly'; ?>>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel">
        <div class="card-header">
            <div class="performance-table-head">
                <div>
                    <div class="performance-table-title"><i class="bi bi-trophy me-1"></i>Leaderboard</div>
                    <div class="performance-table-subtitle">Daily targets, attendance presence, qualified leads, score, and eligibility stay in one structured table so the team ranking is easy to audit.</div>
                </div>
                <div class="performance-range-readout">
                    <span class="sales-chip"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($rangeLabel); ?></span>
                </div>
            </div>
        </div>
        <div class="table-responsive sales-table-responsive performance-table-wrap">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-end">#</th>
                            <th>Agent</th>
                            <th>Department</th>
                            <th class="text-end">Daily Target</th>
                            <th class="text-end">Generated Leads</th>
                            <th class="text-end">Qualified Leads</th>
                            <th class="text-end">Disqualified Leads</th>
                            <th class="text-end">Productivity %</th>
                            <th class="text-end">Score</th>
                            <th>Rank</th>
                            <th class="text-end">Incentive</th>
                            <th class="text-end">Monthly Bonus</th>
                            <th>Monthly Eligibility</th>
                            <th class="text-end">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="13" class="performance-empty-state">No users found in the selected range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $rankClass = $rankBadgeClass((int)$r['pos']); ?>
                                <tr>
                                    <td class="text-end font-monospace"><?php echo (int)$r['pos']; ?></td>
                                    <td>
                                        <div class="performance-row-primary"><?php echo htmlspecialchars((string)$r['name']); ?></div>
                                        <div class="performance-row-secondary"><?php echo htmlspecialchars(getUserDisplayTitle((string)($r['role'] ?? ''), (string)($r['job_title'] ?? ''))); ?></div>
                                    </td>
                                    <td>
                                        <div class="performance-row-primary"><?php echo htmlspecialchars((string)$r['department']); ?></div>
                                        <div class="performance-row-secondary">Visible in selected team scope</div>
                                    </td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['daily_target']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['generated_leads']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['qualified_leads']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['disqualified_leads']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((float)$r['productivity_pct'], 1); ?>%</td>
                                    <td class="text-end font-monospace"><?php echo number_format((float)$r['score'], 1); ?></td>
                                    <td><span class="performance-rank-badge <?php echo htmlspecialchars($rankClass); ?>">#<?php echo (int)$r['pos']; ?> <?php echo htmlspecialchars((string)$r['rank_label']); ?></span></td>
                                    <td class="text-end font-monospace">₹<?php echo number_format((int)$r['range_incentive']); ?></td>
                                    <td class="text-end font-monospace">₹<?php echo number_format((int)$r['monthly_bonus']); ?><div class="small text-muted">MTD <?php echo number_format((float)($r['monthly_productivity_pct'] ?? 0), 1); ?>%</div></td>
                                    <td><span class="<?php echo $statusBadgeClass(!empty($r['eligible'])); ?>"><?php echo !empty($r['eligible']) ? 'Monthly Eligible' : 'Not Monthly Eligible'; ?></span></td>
                                    <td class="text-end">
                                        <a
                                            class="btn btn-outline-secondary btn-sm"
                                            href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?range_preset=' . urlencode((string)$preset) . '&start_date=' . urlencode((string)$startDate) . '&end_date=' . urlencode((string)$endDate) . '&agent_id=' . urlencode((string)((int)$r['user_id']))); ?>"
                                            title="Open daily productivity details"
                                        ><i class="bi bi-box-arrow-up-right"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <div class="performance-table-note">Scores sort by total performance first, then productivity. Open the detail page for any row to inspect day-wise lead generation, scoring buckets, and incentive earned across the selected range.</div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
