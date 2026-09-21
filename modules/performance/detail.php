<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('funneltrack360.detail.page', []);
ensureDatabaseSchema();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$canTeam = function_exists('userHasPermission') ? userHasPermission('funneltrack360.team.page') : true;
$canReports = function_exists('userHasPermission') ? userHasPermission('funneltrack360.reports.page') : true;
$canAdmin = function_exists('userHasPermission') ? userHasPermission('funneltrack360.admin.page') : true;
$canSwitchAgents = isAdmin() || isDirector() || $canTeam || $canAdmin || $canReports;

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

$visibleUsers = funneltrackGetVisiblePerformanceUsers($userId);
if (!isset($visibleUsers[$userId]) && $userId > 0) {
    $visibleUsers[$userId] = [
        'id' => $userId,
        'full_name' => (string)($user['full_name'] ?? 'My Productivity'),
        'role' => (string)($user['role'] ?? ''),
        'job_title' => (string)($user['job_title'] ?? ''),
        'department' => (string)($user['department'] ?? ''),
    ];
}

$selectedAgent = isset($_GET['agent_id']) ? trim((string)$_GET['agent_id']) : '';
$selectedUserIds = [$userId];
$scopeLabel = 'My Daily Productivity';
if ($canSwitchAgents) {
    if ($selectedAgent === 'all') {
        $selectedUserIds = array_values(array_map('intval', array_keys($visibleUsers)));
        $scopeLabel = 'All Visible Agents';
    } else {
        $selectedUserId = (int)$selectedAgent;
        if ($selectedUserId > 0 && isset($visibleUsers[$selectedUserId])) {
            $selectedUserIds = [$selectedUserId];
        } elseif (!empty($visibleUsers)) {
            $selectedUserIds = [$userId];
        }
        $activeUserId = (int)($selectedUserIds[0] ?? $userId);
        $activeUser = $visibleUsers[$activeUserId] ?? null;
        if (is_array($activeUser)) {
            $scopeLabel = (string)($activeUser['full_name'] ?? 'Agent Productivity');
        }
    }
} else {
    $selectedUserIds = [$userId];
    $scopeLabel = 'My Daily Productivity';
}

$detailRows = funneltrackComputeDailyDetailRows($selectedUserIds, $startDate, $endDate, $cfg, $userId);
$bucketMeta = funneltrackCampaignBucketMeta($cfg);
$bucketOrder = array_keys($bucketMeta);
$formatBucketPoints = static function(float $points): string {
    $text = number_format($points, 2, '.', '');
    return rtrim(rtrim($text, '0'), '.');
};
$weightCfg = (array)($cfg['weights'] ?? []);
$thresholdCfg = (array)($cfg['thresholds'] ?? []);
$dailyIncentiveCfg = (array)($cfg['daily_incentive'] ?? []);
$dailyIncentiveSlabs = is_array($dailyIncentiveCfg['slabs'] ?? null) ? $dailyIncentiveCfg['slabs'] : [];
$rangeDisplay = $startDate === $endDate ? $startDate : ($startDate . ' to ' . $endDate);

$summarizedRows = [];
foreach ($detailRows as $row) {
    $summaryKey = (int)($row['user_id'] ?? 0);
    if ($summaryKey <= 0) continue;
    if (!isset($summarizedRows[$summaryKey])) {
        $summarizedRows[$summaryKey] = [
            'date' => $rangeDisplay,
            'user_id' => $summaryKey,
            'agent_name' => (string)($row['agent_name'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'job_title' => (string)($row['job_title'] ?? ''),
            'department' => (string)($row['department'] ?? ''),
            'target_points' => 0.0,
            'generated' => 0,
            'pending_qa' => 0,
            'total_qualified' => 0,
            'qualified_buckets' => array_fill_keys($bucketOrder, 0),
            'total_dq' => 0,
            'dq_buckets' => array_merge(array_fill_keys($bucketOrder, 0), ['Other' => 0]),
            'points' => 0.0,
            'points_formula' => '—',
            'daily_incentive' => 0,
            'escalation_active' => false,
            'escalation_days' => 0,
            'active_dates' => [],
            'eligible_target_days' => 0,
            'days_with_generated' => 0,
            'days_with_qualified' => 0,
            'range_productivity_pct' => 0.0,
        ];
    }

    $summarizedRows[$summaryKey]['target_points'] += (float)($row['target_points'] ?? 0);
    $summarizedRows[$summaryKey]['generated'] += (int)($row['generated'] ?? 0);
    $summarizedRows[$summaryKey]['pending_qa'] += (int)($row['pending_qa'] ?? 0);
    $summarizedRows[$summaryKey]['total_qualified'] += (int)($row['total_qualified'] ?? 0);
    $summarizedRows[$summaryKey]['total_dq'] += (int)($row['total_dq'] ?? 0);
    $summarizedRows[$summaryKey]['points'] += (float)($row['points'] ?? 0.0);
    $summarizedRows[$summaryKey]['daily_incentive'] += (int)($row['daily_incentive'] ?? 0);

    foreach ($bucketOrder as $bucketKey) {
        $summarizedRows[$summaryKey]['qualified_buckets'][$bucketKey] += (int)($row['qualified_buckets'][$bucketKey] ?? 0);
        $summarizedRows[$summaryKey]['dq_buckets'][$bucketKey] += (int)($row['dq_buckets'][$bucketKey] ?? 0);
    }
    $summarizedRows[$summaryKey]['dq_buckets']['Other'] += (int)($row['dq_buckets']['Other'] ?? 0);

    if (!empty($row['escalation_active'])) {
        $summarizedRows[$summaryKey]['escalation_active'] = true;
        $summarizedRows[$summaryKey]['escalation_days']++;
    }
    if ((float)($row['target_points'] ?? 0.0) > 0) $summarizedRows[$summaryKey]['eligible_target_days']++;
    if ((int)($row['generated'] ?? 0) > 0) $summarizedRows[$summaryKey]['days_with_generated']++;
    if ((int)($row['total_qualified'] ?? 0) > 0) $summarizedRows[$summaryKey]['days_with_qualified']++;
    $rowDate = (string)($row['date'] ?? '');
    if ($rowDate !== '') $summarizedRows[$summaryKey]['active_dates'][$rowDate] = true;
}

foreach ($summarizedRows as &$summaryRow) {
    $summaryRow['points'] = round((float)$summaryRow['points'], 2);
    $summaryRow['range_productivity_pct'] = (float)($summaryRow['target_points'] ?? 0) > 0
        ? round((((float)$summaryRow['points']) / max(0.01, (float)$summaryRow['target_points'])) * 100.0, 2)
        : 0.0;
    $formulaParts = [];
    foreach ($bucketOrder as $bucketKey) {
        $count = (int)($summaryRow['qualified_buckets'][$bucketKey] ?? 0);
        $pointValue = (float)($bucketMeta[$bucketKey]['points'] ?? 0.0);
        if ($count > 0 && $pointValue > 0) {
            $formulaParts[] = (string)($bucketMeta[$bucketKey]['label'] ?? $bucketKey) . ' ' . $count . 'x' . $formatBucketPoints($pointValue);
        }
    }
    $summaryRow['points_formula'] = empty($formulaParts)
        ? '—'
        : (implode(' + ', $formulaParts) . ' = ' . number_format((float)$summaryRow['points'], 2));
}
unset($summaryRow);

$summarizedRows = array_values($summarizedRows);
usort($summarizedRows, static function (array $a, array $b): int {
    $nameCmp = strcasecmp((string)($a['agent_name'] ?? ''), (string)($b['agent_name'] ?? ''));
    if ($nameCmp !== 0) return $nameCmp;
    return ((int)($a['user_id'] ?? 0)) <=> ((int)($b['user_id'] ?? 0));
});

$totals = [
    'target_points' => 0.0,
    'generated' => 0,
    'pending_qa' => 0,
    'qualified' => 0,
    'dq' => 0,
    'points' => 0.0,
    'incentive' => 0,
];
$totalQualifiedBuckets = array_fill_keys($bucketOrder, 0);
$totalDqBuckets = array_merge(array_fill_keys($bucketOrder, 0), ['Other' => 0]);
foreach ($summarizedRows as $row) {
    $totals['target_points'] += (float)($row['target_points'] ?? 0.0);
    $totals['generated'] += (int)($row['generated'] ?? 0);
    $totals['pending_qa'] += (int)($row['pending_qa'] ?? 0);
    $totals['qualified'] += (int)($row['total_qualified'] ?? 0);
    $totals['dq'] += (int)($row['total_dq'] ?? 0);
    $totals['points'] += (float)($row['points'] ?? 0.0);
    $totals['incentive'] += (int)($row['daily_incentive'] ?? 0);
    foreach ($bucketOrder as $bucketKey) {
        $totalQualifiedBuckets[$bucketKey] += (int)($row['qualified_buckets'][$bucketKey] ?? 0);
        $totalDqBuckets[$bucketKey] += (int)($row['dq_buckets'][$bucketKey] ?? 0);
    }
    $totalDqBuckets['Other'] += (int)($row['dq_buckets']['Other'] ?? 0);
}
$visibleAgentCount = count($summarizedRows);

$formatBreakdown = static function(array $buckets, array $meta, bool $includeOther = false): string {
    $parts = [];
    foreach ($meta as $bucketKey => $bucketCfg) {
        $count = (int)($buckets[$bucketKey] ?? 0);
        if ($count > 0) $parts[] = (string)($bucketCfg['label'] ?? $bucketKey) . ' ' . $count;
    }
    if ($includeOther) {
        $otherCount = (int)($buckets['Other'] ?? 0);
        if ($otherCount > 0) $parts[] = 'Other ' . $otherCount;
    }
    return empty($parts) ? '—' : implode(' | ', $parts);
};
$totalFormulaParts = [];
foreach ($bucketOrder as $bucketKey) {
    $count = (int)($totalQualifiedBuckets[$bucketKey] ?? 0);
    $pointValue = (float)($bucketMeta[$bucketKey]['points'] ?? 0.0);
    if ($count > 0 && $pointValue > 0) {
        $totalFormulaParts[] = (string)($bucketMeta[$bucketKey]['label'] ?? $bucketKey) . ' ' . $count . 'x' . $formatBucketPoints($pointValue);
    }
}
$grandTotalFormula = empty($totalFormulaParts)
    ? '—'
    : (implode(' + ', $totalFormulaParts) . ' = ' . number_format((float)$totals['points'], 2));

$pageTitle = 'DemandFlow Bridge – Daily Productivity Detail';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<style>
    .performance-table-wrap {
        position: relative;
    }
    .performance-info-trigger {
        padding: .15rem .5rem;
    }
    .performance-calc-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: .75rem;
    }
    .performance-calc-metric {
        border: 1px solid rgba(0, 0, 0, 0.08);
        border-radius: .75rem;
        padding: .75rem;
        background: rgba(248, 249, 250, 0.9);
    }
    .performance-calc-metric .label {
        display: block;
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #6c757d;
        margin-bottom: .2rem;
    }
    .performance-calc-metric .value {
        font-weight: 700;
    }
    .performance-calc-list {
        margin: 0;
        padding-left: 1rem;
    }
    .performance-sticky-total th,
    .performance-sticky-total td {
        position: sticky;
        bottom: 0;
        z-index: 2;
        background: #f8f9fa;
        box-shadow: inset 0 1px 0 rgba(0, 0, 0, 0.08);
    }
    .performance-sticky-total td:first-child,
    .performance-sticky-total th:first-child {
        z-index: 3;
    }
    .performance-sticky-total td:first-child,
    .performance-sticky-total th:first-child {
        left: 0;
        min-width: 120px;
    }
    .performance-sticky-total td:nth-child(2),
    .performance-sticky-total th:nth-child(2) {
        left: 120px;
        z-index: 3;
        min-width: 180px;
    }
    [data-bs-theme="dark"] .performance-sticky-total th,
    [data-bs-theme="dark"] .performance-sticky-total td {
        background: #212529;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
    }
    [data-bs-theme="dark"] .performance-calc-metric {
        border-color: rgba(255, 255, 255, 0.08);
        background: rgba(33, 37, 41, 0.95);
    }
    [data-bs-theme="dark"] .performance-calc-metric .label {
        color: #adb5bd;
    }
</style>
<div class="container-fluid px-0 sales-workspace performance-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-table"></i>Daily Productivity Detail</div>
            <h3 class="sales-page-title">Performance Detail</h3>
            <div class="sales-page-subtitle">Review productivity totals for the selected date or date range with one summarized row per agent instead of long day-wise repetition.</div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="sales-chip"><i class="bi bi-person-badge"></i><?php echo htmlspecialchars($scopeLabel); ?></span>
                <span class="sales-chip"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($rangeLabel); ?></span>
                <span class="sales-chip"><i class="bi bi-people"></i><?php echo number_format($visibleAgentCount); ?> agent<?php echo $visibleAgentCount === 1 ? '' : 's'; ?></span>
            </div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>"><i class="bi bi-speedometer2 me-1"></i>My Dashboard</a>
            <?php if ($canTeam): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>"><i class="bi bi-people me-1"></i>Team View</a><?php endif; ?>
            <?php if ($canReports): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Reports</a><?php endif; ?>
            <?php if ($canAdmin): ?><a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>"><i class="bi bi-gear me-1"></i>Admin</a><?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav performance-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>">My Dashboard</a></li>
        <li class="nav-item"><a class="nav-link active" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail'); ?>">Daily Detail</a></li>
        <?php if ($canTeam): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>">Team View</a></li><?php endif; ?>
        <?php if ($canReports): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>">Reports</a></li><?php endif; ?>
        <?php if ($canAdmin): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>">Admin</a></li><?php endif; ?>
    </ul>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-2">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Generated</div>
                <div class="performance-callout-value"><?php echo number_format($totals['generated']); ?></div>
                <div class="small text-muted mt-1">Rows in selected range</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Pending QA</div>
                <div class="performance-callout-value"><?php echo number_format($totals['pending_qa']); ?></div>
                <div class="small text-muted mt-1">Open QA queue volume</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Qualified</div>
                <div class="performance-callout-value"><?php echo number_format($totals['qualified']); ?></div>
                <div class="small text-muted mt-1">Scored productivity output</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Total DQ</div>
                <div class="performance-callout-value"><?php echo number_format($totals['dq']); ?></div>
                <div class="small text-muted mt-1">Campaign-type review failures</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Points</div>
                <div class="performance-callout-value"><?php echo number_format($totals['points'], 2); ?></div>
                <div class="small text-muted mt-1">Qualified scoring total</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="performance-callout h-100">
                <div class="performance-callout-title">Daily Incentive</div>
                <div class="performance-callout-value">₹<?php echo number_format($totals['incentive']); ?></div>
                <div class="small text-muted mt-1">Earned in selected range</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel mb-3">
        <div class="card-body">
            <div class="performance-toolbar-actions mb-2">
                <div>
                    <div class="fw-semibold">Detail Filter</div>
                    <div class="text-muted small">Switch range presets or choose a visible agent. Managers and admins can review all visible agents in one table.</div>
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
                        <option value="week" <?php echo $preset==='week'?'selected':''; ?>>Week</option>
                        <option value="month" <?php echo $preset==='month'?'selected':''; ?>>Month</option>
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
                <?php if ($canSwitchAgents): ?>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Agent Scope</label>
                        <select class="form-select form-select-sm" name="agent_id">
                            <option value="all" <?php echo $selectedAgent==='all'?'selected':''; ?>>All Visible Agents</option>
                            <?php foreach ($visibleUsers as $visibleUserId => $visibleUser): ?>
                                <option value="<?php echo (int)$visibleUserId; ?>" <?php echo ((int)($selectedUserIds[0] ?? 0) === (int)$visibleUserId && $selectedAgent !== 'all') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(formatUserNameWithRole((string)($visibleUser['full_name'] ?? ''), (string)($visibleUser['role'] ?? ''), (string)($visibleUser['job_title'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-<?php echo $canSwitchAgents ? '12' : '3'; ?>">
                    <button class="btn btn-outline-primary btn-sm <?php echo $canSwitchAgents ? '' : 'w-100'; ?>" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel">
        <div class="card-header">
            <div class="performance-table-head">
                <div>
                    <div class="performance-table-title"><i class="bi bi-calendar3 me-1"></i>Daily Productivity Table</div>
                    <div class="performance-table-subtitle">The table shows one cumulative row per agent for the selected day or range, with qualified campaign buckets summarized as EML, MQL, HQL/Call, BANT, and AG.</div>
                </div>
                <div class="performance-range-readout">
                    <span class="sales-chip"><i class="bi bi-clipboard-data"></i><?php echo htmlspecialchars($scopeLabel); ?></span>
                </div>
            </div>
        </div>
        <div class="table-responsive sales-table-responsive performance-table-wrap">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2">Date / Range</th>
                        <th rowspan="2">Agent Name</th>
                        <th rowspan="2" class="text-end">Target Points</th>
                        <th rowspan="2" class="text-end">Generated</th>
                        <th rowspan="2" class="text-end">Pending QA</th>
                        <th rowspan="2" class="text-end">Total Qualified Leads</th>
                        <th colspan="<?php echo count($bucketOrder); ?>" class="text-center">Qualified Leads</th>
                        <th rowspan="2">Total DQ</th>
                        <th rowspan="2">Points Calculation</th>
                        <th rowspan="2" class="text-end">Daily Incentives</th>
                    </tr>
                    <tr>
                        <?php foreach ($bucketOrder as $bucketKey): ?>
                            <?php $meta = $bucketMeta[$bucketKey]; ?>
                            <th class="text-end"><?php echo htmlspecialchars((string)$meta['label']); ?> (<?php echo htmlspecialchars($formatBucketPoints((float)$meta['points'])); ?>)</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($summarizedRows)): ?>
                        <tr><td colspan="<?php echo 10 + count($bucketOrder); ?>" class="performance-empty-state">No productivity rows found in the selected scope and range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($summarizedRows as $row): ?>
                            <tr>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($row['date'] ?? '')); ?></td>
                                <td>
                                    <div class="performance-row-primary"><?php echo htmlspecialchars((string)($row['agent_name'] ?? '')); ?></div>
                                    <div class="performance-row-secondary"><?php echo htmlspecialchars(getUserDisplayTitle((string)($row['role'] ?? ''), (string)($row['job_title'] ?? ''))); ?></div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm performance-info-trigger mt-2" data-bs-toggle="modal" data-bs-target="#prodInfoAgent<?php echo (int)($row['user_id'] ?? 0); ?>">
                                        <i class="bi bi-info-circle me-1"></i>Info
                                    </button>
                                </td>
                                <td class="text-end font-monospace"><?php echo number_format((float)($row['target_points'] ?? 0), 0); ?></td>
                                <td class="text-end font-monospace"><?php echo number_format((int)($row['generated'] ?? 0)); ?></td>
                                <td class="text-end font-monospace"><?php echo number_format((int)($row['pending_qa'] ?? 0)); ?></td>
                                <td class="text-end font-monospace"><?php echo number_format((int)($row['total_qualified'] ?? 0)); ?></td>
                                <?php foreach ($bucketOrder as $bucketKey): ?>
                                    <td class="text-end font-monospace"><?php echo number_format((int)(($row['qualified_buckets'][$bucketKey] ?? 0))); ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <div class="performance-row-primary"><?php echo number_format((int)($row['total_dq'] ?? 0)); ?></div>
                                    <div class="performance-row-secondary"><?php echo htmlspecialchars($formatBreakdown((array)($row['dq_buckets'] ?? []), $bucketMeta, true)); ?></div>
                                </td>
                                <td>
                                    <div class="performance-row-primary"><?php echo number_format((float)($row['points'] ?? 0.0), 2); ?> pts</div>
                                    <div class="performance-row-secondary"><?php echo htmlspecialchars((string)($row['points_formula'] ?? '—')); ?></div>
                                </td>
                                <td class="text-end">
                                    <div class="performance-row-primary font-monospace">₹<?php echo number_format((int)($row['daily_incentive'] ?? 0)); ?></div>
                                    <div class="performance-row-secondary">
                                        <?php
                                            if (!empty($row['escalation_active'])) {
                                                echo htmlspecialchars('Escalation on ' . number_format((int)($row['escalation_days'] ?? 0)) . ' day(s)');
                                            } else {
                                                echo 'Standard rule';
                                            }
                                        ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($summarizedRows)): ?>
                    <tfoot class="table-light performance-sticky-total">
                        <tr class="fw-semibold">
                            <td class="font-monospace">Grand Total</td>
                            <td>
                                <div class="performance-row-primary"><?php echo number_format($visibleAgentCount); ?> Agent<?php echo $visibleAgentCount === 1 ? '' : 's'; ?></div>
                                <div class="performance-row-secondary"><?php echo htmlspecialchars($rangeDisplay); ?></div>
                            </td>
                            <td class="text-end font-monospace"><?php echo number_format((float)$totals['target_points'], 0); ?></td>
                            <td class="text-end font-monospace"><?php echo number_format((int)$totals['generated']); ?></td>
                            <td class="text-end font-monospace"><?php echo number_format((int)$totals['pending_qa']); ?></td>
                            <td class="text-end font-monospace"><?php echo number_format((int)$totals['qualified']); ?></td>
                            <?php foreach ($bucketOrder as $bucketKey): ?>
                                <td class="text-end font-monospace"><?php echo number_format((int)($totalQualifiedBuckets[$bucketKey] ?? 0)); ?></td>
                            <?php endforeach; ?>
                            <td>
                                <div class="performance-row-primary"><?php echo number_format((int)$totals['dq']); ?></div>
                                <div class="performance-row-secondary"><?php echo htmlspecialchars($formatBreakdown($totalDqBuckets, $bucketMeta, true)); ?></div>
                            </td>
                            <td>
                                <div class="performance-row-primary"><?php echo number_format((float)$totals['points'], 2); ?> pts</div>
                                <div class="performance-row-secondary"><?php echo htmlspecialchars($grandTotalFormula); ?></div>
                            </td>
                            <td class="text-end font-monospace">₹<?php echo number_format((int)$totals['incentive']); ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
            <div class="performance-table-note">Generated and pending QA totals follow lead creation date. Pending QA counts every non-final QA status, while qualified buckets, points, DQ totals, and incentive totals follow QA completion date and are summed across the selected range for each agent.</div>
            <div class="border-top p-3 bg-light-subtle">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <div class="fw-semibold">Detail Filter</div>
                    <div class="text-muted small">Quickly change the range or agent scope from the bottom of the table.</div>
                </div>
                <form class="row g-2 align-items-end sales-filter-form" method="get">
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Range</label>
                        <select class="form-select form-select-sm" name="range_preset">
                            <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
                            <option value="week" <?php echo $preset==='week'?'selected':''; ?>>Week</option>
                            <option value="month" <?php echo $preset==='month'?'selected':''; ?>>Month</option>
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
                    <?php if ($canSwitchAgents): ?>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Agent Scope</label>
                            <select class="form-select form-select-sm" name="agent_id">
                                <option value="all" <?php echo $selectedAgent==='all'?'selected':''; ?>>All Visible Agents</option>
                                <?php foreach ($visibleUsers as $visibleUserId => $visibleUser): ?>
                                    <option value="<?php echo (int)$visibleUserId; ?>" <?php echo ((int)($selectedUserIds[0] ?? 0) === (int)$visibleUserId && $selectedAgent !== 'all') ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(formatUserNameWithRole((string)($visibleUser['full_name'] ?? ''), (string)($visibleUser['role'] ?? ''), (string)($visibleUser['job_title'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-<?php echo $canSwitchAgents ? '12' : '3'; ?>">
                        <button class="btn btn-outline-primary btn-sm <?php echo $canSwitchAgents ? '' : 'w-100'; ?>" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php if (!empty($summarizedRows)): ?>
    <?php foreach ($summarizedRows as $row): ?>
        <?php
            $infoModalId = 'prodInfoAgent' . (int)($row['user_id'] ?? 0);
            $targetDays = (int)($row['eligible_target_days'] ?? 0);
            $avgDailyTarget = $targetDays > 0 ? ((float)($row['target_points'] ?? 0.0) / $targetDays) : 0.0;
            $resolvedLeads = (int)($row['total_qualified'] ?? 0) + (int)($row['total_dq'] ?? 0);
        ?>
        <div class="modal fade" id="<?php echo htmlspecialchars($infoModalId); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <div class="fw-semibold"><i class="bi bi-info-circle me-1"></i>Productivity Calculation</div>
                            <div class="small text-muted"><?php echo htmlspecialchars((string)($row['agent_name'] ?? '')); ?> · <?php echo htmlspecialchars($rangeDisplay); ?></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="performance-calc-grid mb-3">
                            <div class="performance-calc-metric">
                                <span class="label">Target Points</span>
                                <div class="value"><?php echo number_format((float)($row['target_points'] ?? 0.0), 0); ?></div>
                                <div class="small text-muted"><?php echo number_format($targetDays); ?> eligible working day<?php echo $targetDays === 1 ? '' : 's'; ?>, avg <?php echo number_format($avgDailyTarget, 2); ?> pts/day</div>
                            </div>
                            <div class="performance-calc-metric">
                                <span class="label">Achieved Points</span>
                                <div class="value"><?php echo number_format((float)($row['points'] ?? 0.0), 2); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars((string)($row['points_formula'] ?? '—')); ?></div>
                            </div>
                            <div class="performance-calc-metric">
                                <span class="label">Range Productivity</span>
                                <div class="value"><?php echo number_format((float)($row['range_productivity_pct'] ?? 0.0), 2); ?>%</div>
                                <div class="small text-muted">Achieved points / target points x 100</div>
                            </div>
                            <div class="performance-calc-metric">
                                <span class="label">Daily Incentive Total</span>
                                <div class="value">₹<?php echo number_format((int)($row['daily_incentive'] ?? 0)); ?></div>
                                <div class="small text-muted"><?php echo !empty($row['escalation_active']) ? htmlspecialchars('Escalation affected ' . number_format((int)($row['escalation_days'] ?? 0)) . ' day(s)') : 'Standard rule only'; ?></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="fw-semibold mb-2">How This Row Is Calculated</div>
                            <ul class="performance-calc-list small">
                                <li>`Generated` counts leads created by this agent inside the selected range.</li>
                                <li>`Pending QA` counts leads in the selected range whose current QA status is still open, including blank, `Pending QA`, `In Progress`, `Reopened`, `Rectified`, and other non-final states.</li>
                                <li>`Total Qualified Leads` counts leads whose QA result became `Qualified` in the selected range.</li>
                                <li>`Total DQ` counts leads whose QA result became `Disqualified` in the selected range.</li>
                                <li>`Target Points` sums the configured daily target for each eligible working day in the range. Paid leave days contribute zero target.</li>
                                <li>`Daily Incentive` is added day by day using the configured threshold and slabs, then adjusted by escalation rules if an escalation is active.</li>
                            </ul>
                        </div>

                        <div class="mb-3">
                            <div class="fw-semibold mb-2">Campaign Type Scoring</div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Bucket</th>
                                            <th>Campaign Type</th>
                                            <th class="text-end">Points / Qualified</th>
                                            <th class="text-end">Qualified Count</th>
                                            <th class="text-end">Points Earned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bucketOrder as $bucketKey): ?>
                                            <?php
                                                $bucketCfg = $bucketMeta[$bucketKey];
                                                $bucketCount = (int)($row['qualified_buckets'][$bucketKey] ?? 0);
                                                $bucketPointValue = (float)($bucketCfg['points'] ?? 0.0);
                                                $bucketPointsEarned = $bucketCount * $bucketPointValue;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)($bucketCfg['label'] ?? $bucketKey)); ?></td>
                                                <td><?php echo htmlspecialchars((string)$bucketKey); ?></td>
                                                <td class="text-end font-monospace"><?php echo number_format($bucketPointValue, 2); ?></td>
                                                <td class="text-end font-monospace"><?php echo number_format($bucketCount); ?></td>
                                                <td class="text-end font-monospace"><?php echo number_format($bucketPointsEarned, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-semibold">
                                            <td colspan="3">Total</td>
                                            <td class="text-end font-monospace"><?php echo number_format((int)($row['total_qualified'] ?? 0)); ?></td>
                                            <td class="text-end font-monospace"><?php echo number_format((float)($row['points'] ?? 0.0), 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="fw-semibold mb-2">Range Snapshot</div>
                            <div class="performance-calc-grid">
                                <div class="performance-calc-metric">
                                    <span class="label">Generated</span>
                                    <div class="value"><?php echo number_format((int)($row['generated'] ?? 0)); ?></div>
                                    <div class="small text-muted"><?php echo number_format((int)($row['days_with_generated'] ?? 0)); ?> active generated day<?php echo ((int)($row['days_with_generated'] ?? 0)) === 1 ? '' : 's'; ?></div>
                                </div>
                                <div class="performance-calc-metric">
                                    <span class="label">Pending QA</span>
                                    <div class="value"><?php echo number_format((int)($row['pending_qa'] ?? 0)); ?></div>
                                    <div class="small text-muted">Open QA queue in this range</div>
                                </div>
                                <div class="performance-calc-metric">
                                    <span class="label">Resolved Leads</span>
                                    <div class="value"><?php echo number_format($resolvedLeads); ?></div>
                                    <div class="small text-muted"><?php echo number_format((int)($row['days_with_qualified'] ?? 0)); ?> day<?php echo ((int)($row['days_with_qualified'] ?? 0)) === 1 ? '' : 's'; ?> with qualified output</div>
                                </div>
                                <div class="performance-calc-metric">
                                    <span class="label">DQ Breakdown</span>
                                    <div class="value"><?php echo number_format((int)($row['total_dq'] ?? 0)); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($formatBreakdown((array)($row['dq_buckets'] ?? []), $bucketMeta, true)); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="fw-semibold mb-2">Module Score Rules</div>
                            <ul class="performance-calc-list small">
                                <li>`Productivity` weight: <?php echo number_format((float)($weightCfg['productivity'] ?? 60), 0); ?>% of score.</li>
                                <li>`Adherence` weight: <?php echo number_format((float)($weightCfg['adherence'] ?? 10), 0); ?>% of score, based on average login minutes against <?php echo number_format((int)($thresholdCfg['required_login_minutes'] ?? 465)); ?> required minutes.</li>
                                <li>`Absenteeism` weight: <?php echo number_format((float)($weightCfg['absenteeism'] ?? 10), 0); ?>% of score, compared against minimum attendance <?php echo number_format((float)($thresholdCfg['min_attendance_pct'] ?? 90), 0); ?>%.</li>
                                <li>`Quality` weight: <?php echo number_format((float)($weightCfg['quality'] ?? 20), 0); ?>% of score, with max disqualification ratio <?php echo number_format((float)($thresholdCfg['max_disqualification_ratio_pct'] ?? 10), 0); ?>% before deductions apply.</li>
                                <li>`Monthly incentive eligibility` starts at productivity <?php echo number_format((float)($thresholdCfg['min_expected_productivity_pct'] ?? 90), 0); ?>% and then follows the configured escalation rule.</li>
                            </ul>
                        </div>

                        <div>
                            <div class="fw-semibold mb-2">Daily Incentive Rule</div>
                            <ul class="performance-calc-list small mb-2">
                                <li>Base threshold: <?php echo number_format((float)($dailyIncentiveCfg['base_threshold_pct'] ?? 100), 0); ?>% of the day target.</li>
                                <li>Base amount after crossing threshold: ₹<?php echo number_format((int)($dailyIncentiveCfg['base_amount'] ?? 0)); ?>.</li>
                            </ul>
                            <?php if (!empty($dailyIncentiveSlabs)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>From %</th>
                                                <th>To %</th>
                                                <th class="text-end">Bonus / Extra Point</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dailyIncentiveSlabs as $slab): ?>
                                                <tr>
                                                    <td><?php echo number_format((float)($slab['from_pct'] ?? 0), 0); ?>%</td>
                                                    <td><?php echo array_key_exists('to_pct', $slab) && $slab['to_pct'] !== null ? number_format((float)$slab['to_pct'], 0) . '%' : 'Open'; ?></td>
                                                    <td class="text-end font-monospace">₹<?php echo number_format((int)($slab['per_point'] ?? 0)); ?></td>
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
    <?php endforeach; ?>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
