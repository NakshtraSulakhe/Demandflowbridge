<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

requirePermissionOrRole('admin.analytics.agents.page', ['admin','director','operations_director','operations_manager']);
ensureDatabaseSchema();
ensureCsrfToken();

$conn = getDbConnection();
$now = new DateTime();

$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($now->format('Y-m-01') . ' 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($now->format('Y-m-t') . ' 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('') : 'created_at';
$leadCreatedExprAliased = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$leadCreatedDateExpr = "DATE($leadCreatedExpr)";

$agents = [];
$agents = getAgents();
$analyticsTabs = [
    'campaigns' => ['label' => 'Campaigns', 'href' => appBasePath() . '/modules/admin/analytics/campaigns', 'icon' => 'bi-megaphone'],
    'agents' => ['label' => 'Agents', 'href' => appBasePath() . '/modules/admin/analytics/agents', 'icon' => 'bi-people'],
    'sales' => ['label' => 'Sales', 'href' => appBasePath() . '/modules/admin/analytics/sales', 'icon' => 'bi-funnel'],
    'dbms' => ['label' => 'DBMS', 'href' => appBasePath() . '/modules/admin/analytics/dbms', 'icon' => 'bi-database-check'],
];
$currentTab = 'agents';
$adminDashboardUrl = appBasePath() . '/modules/dashboard/admin-dashboard';
$leadManagerUrl = appBasePath() . '/modules/leads/list';
$performanceTeamUrl = appBasePath() . '/modules/performance/team';
$attendanceAdminUrl = appBasePath() . '/modules/hr/attendance-admin';

$agentName = 'All Agents';
if ($agentId > 0) {
    foreach ($agents as $a) {
        if ((int)($a['id'] ?? 0) === $agentId) {
            $agentName = (string)($a['full_name'] ?? '');
            break;
        }
    }
    if ($agentName === '') $agentName = 'Agent';
}

$summary = ['generated' => 0, 'pending_qa' => 0, 'qualified' => 0, 'disqualified' => 0, 'delivered' => 0, 'accepted' => 0, 'forms' => 0, 'forms_pending' => 0];
$trendRows = [];
if ($agentId > 0) {
    $stmt = $conn->prepare("
        SELECT
          COUNT(*) AS generated,
          SUM(CASE WHEN (qa_status IS NULL OR qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
          SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
          SUM(CASE WHEN client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS accepted,
          SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) AS forms,
          SUM(CASE WHEN qa_status = 'Qualified' AND form_done = 'No' THEN 1 ELSE 0 END) AS forms_pending
        FROM leads
        WHERE agent_id = ? AND $leadCreatedExpr BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $rangeStart, $rangeEnd);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc() ?: $summary;
        $stmt->close();
    }

    $stmt = $conn->prepare("
        SELECT $leadCreatedDateExpr AS d,
               COUNT(*) AS generated,
               SUM(CASE WHEN qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
               SUM(CASE WHEN client_delivery_status='Delivered' THEN 1 ELSE 0 END) AS delivered,
               SUM(CASE WHEN client_delivery_status='Accepted' THEN 1 ELSE 0 END) AS accepted
        FROM leads
        WHERE agent_id = ? AND $leadCreatedExpr BETWEEN ? AND ?
        GROUP BY $leadCreatedDateExpr
        ORDER BY d ASC
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $rangeStart, $rangeEnd);
        $stmt->execute();
        $trendRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("
        SELECT
          COUNT(*) AS generated,
          SUM(CASE WHEN (qa_status IS NULL OR qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
          SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
          SUM(CASE WHEN client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS accepted,
          SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) AS forms,
          SUM(CASE WHEN qa_status = 'Qualified' AND form_done = 'No' THEN 1 ELSE 0 END) AS forms_pending
        FROM leads
        WHERE $leadCreatedExpr BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $rangeStart, $rangeEnd);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc() ?: $summary;
        $stmt->close();
    }

    $stmt = $conn->prepare("
        SELECT $leadCreatedDateExpr AS d,
               COUNT(*) AS generated,
               SUM(CASE WHEN qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
               SUM(CASE WHEN client_delivery_status='Delivered' THEN 1 ELSE 0 END) AS delivered,
               SUM(CASE WHEN client_delivery_status='Accepted' THEN 1 ELSE 0 END) AS accepted
        FROM leads
        WHERE $leadCreatedExpr BETWEEN ? AND ?
        GROUP BY $leadCreatedDateExpr
        ORDER BY d ASC
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $rangeStart, $rangeEnd);
        $stmt->execute();
        $trendRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$todayDate = $now->format('Y-m-d');
$lateMin = 0;
$attStatus = '';
if ($agentId > 0) {
    $stmt = $conn->prepare("SELECT late_minutes, status FROM hr_attendance_days WHERE user_id = ? AND work_date = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('is', $agentId, $todayDate);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $lateMin = (int)($r['late_minutes'] ?? 0);
        $attStatus = (string)($r['status'] ?? '');
    }
} else {
    $attStatus = 'All Agents';
}

$yy = (int)$now->format('Y');
$mo = (int)$now->format('m');
$target = getAgentMonthlyTarget($agentId, $yy, $mo) ?: ['daily_target' => 0, 'working_days' => 0, 'monthly_target' => 0];
$dailyTarget = (int)($target['daily_target'] ?? 0);
$monthlyTarget = (int)($target['monthly_target'] ?? 0);
$workingDays = (int)($target['working_days'] ?? 0);

$daysInRange = 0;
$sdTs = strtotime(substr($rangeStart,0,10));
$edTs = strtotime(substr($rangeEnd,0,10));
if ($sdTs !== false && $edTs !== false && $edTs >= $sdTs) {
    $daysInRange = (int)floor(($edTs - $sdTs) / 86400) + 1;
}
$avgPerDay = $daysInRange > 0 ? ((int)$summary['qualified'] / $daysInRange) : 0.0;
$projMonth = $workingDays > 0 ? round($avgPerDay * $workingDays, 1) : 0.0;
$projPct = $monthlyTarget > 0 ? min(100.0, round(($projMonth / $monthlyTarget) * 100, 1)) : 0.0;
$deliveryPct = ((int)$summary['generated'] > 0) ? round(((int)$summary['delivered'] / max(1, (int)$summary['generated'])) * 100, 1) : 0.0;

$agentRows = [];
$performanceRoles = function_exists('funneltrackPerformanceAgentRoles')
    ? funneltrackPerformanceAgentRoles()
    : ['agent','operations_agent','email_marketing_agent','email_marketing_executive','form_filler'];
$performanceRoles = array_values(array_filter(array_map(static fn($v) => strtolower(trim((string)$v)), $performanceRoles), static fn($v) => $v !== ''));
$roleIn = implode(',', array_fill(0, count($performanceRoles), '?'));
$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.role,
        u.job_title,
        COUNT(l.id) AS generated,
        SUM(CASE WHEN (l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
        SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN l.client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS accepted
    FROM users u
    LEFT JOIN leads l ON l.agent_id = u.id AND $leadCreatedExprAliased BETWEEN ? AND ?
    WHERE u.is_active = 1 AND LOWER(u.role) IN ($roleIn)
    GROUP BY u.id
    ORDER BY qualified DESC, delivered DESC, generated DESC, u.full_name ASC
");
if ($stmt) {
    $bindParams = array_merge([$rangeStart, $rangeEnd], $performanceRoles);
    mysqliBindParams($stmt, 'ss' . str_repeat('s', count($performanceRoles)), $bindParams);
    $stmt->execute();
    $agentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$ty = (int)date('Y', strtotime(substr($rangeStart, 0, 10)));
$tm = (int)date('m', strtotime(substr($rangeStart, 0, 10)));
$targets = getMonthlyTargets($ty, $tm);
$targetMap = [];
foreach ($targets as $t) $targetMap[(int)($t['agent_id'] ?? 0)] = $t;
$daysInRangeForPerf = $daysInRange > 0 ? $daysInRange : 1;
foreach ($agentRows as &$ar) {
    $aid = (int)($ar['id'] ?? 0);
    $t = $targetMap[$aid] ?? null;
    $dt = (int)($t['daily_target'] ?? 0);
    $q = (int)($ar['qualified'] ?? 0);
    $ar['daily_target'] = $dt;
    $ar['avg_qualified_per_day'] = $daysInRangeForPerf > 0 ? round($q / $daysInRangeForPerf, 2) : 0.0;
    $ar['performance_pct'] = ($dt > 0 && $daysInRangeForPerf > 0) ? round(($q / max(1, $dt * $daysInRangeForPerf)) * 100, 1) : null;
    $ar['delivery_pct'] = ((int)($ar['generated'] ?? 0) > 0) ? round(((int)($ar['delivered'] ?? 0) / max(1, (int)($ar['generated'] ?? 0))) * 100, 1) : 0.0;
}
unset($ar);

if ($agentId <= 0) {
    $dailyTarget = 0;
    $monthlyTarget = 0;
    $workingDays = 0;
    foreach ($agentRows as $aggregateRow) {
        $dailyTarget += (int)($aggregateRow['daily_target'] ?? 0);
        $monthlyTarget += (int)($targetMap[(int)($aggregateRow['id'] ?? 0)]['monthly_target'] ?? 0);
        $workingDays = max($workingDays, (int)($targetMap[(int)($aggregateRow['id'] ?? 0)]['working_days'] ?? 0));
    }
    $projMonth = $workingDays > 0 ? round($avgPerDay * $workingDays, 1) : 0.0;
    $projPct = $monthlyTarget > 0 ? min(100.0, round(($projMonth / $monthlyTarget) * 100, 1)) : 0.0;
}

$pageTitle = 'Agent Analytics';
include __DIR__ . '/../../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 admin-dashboard analytics-workspace">
    <div class="analytics-hero mb-3">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <span class="analytics-eyebrow"><i class="bi bi-people"></i>Admin Analytics</span>
                <div class="analytics-title mt-2">Agent Analytics</div>
                <div class="analytics-subtitle">Review agent output, qualification pace, attendance context, and target tracking with direct access to linked CRM operations pages.</div>
            </div>
            <div class="analytics-chip-row">
                <span class="analytics-chip"><?php echo htmlspecialchars($agentName); ?></span>
                <span class="analytics-chip"><?php echo htmlspecialchars($rangeLabel); ?></span>
                <span class="analytics-chip"><?php echo number_format($deliveryPct, 1); ?>Delivered %</span>
                <span class="analytics-chip"><?php echo number_format((int)$summary['qualified']); ?> Qualified</span>
            </div>
        </div>
        <div class="analytics-nav nav nav-pills mt-3">
            <?php foreach ($analyticsTabs as $key => $tab): ?>
                <a class="nav-link <?php echo $currentTab === $key ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($tab['href']); ?>">
                    <i class="bi <?php echo htmlspecialchars((string)$tab['icon']); ?> me-1"></i><?php echo htmlspecialchars((string)$tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="analytics-action-row mt-3">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($adminDashboardUrl); ?>"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($leadManagerUrl); ?>"><i class="bi bi-table me-1"></i>Lead Manager</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($performanceTeamUrl); ?>"><i class="bi bi-trophy me-1"></i>Performance</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($attendanceAdminUrl); ?>"><i class="bi bi-calendar-check me-1"></i>Attendance</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel analytics-filter-card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end analytics-inline-form" method="get">
        <div class="col-md-3">
            <label class="form-label small text-muted">Agent</label>
            <select class="form-select form-select-sm" name="agent_id" onchange="this.form.submit();">
                <option value="0" <?php echo $agentId <= 0 ? 'selected' : ''; ?>>All Agents</option>
                <?php foreach ($agents as $a): ?>
                    <?php $id = (int)($a['id'] ?? 0); ?>
                    <option value="<?php echo $id; ?>" <?php echo $id===$agentId?'selected':''; ?>>
                        <?php echo htmlspecialchars(formatUserNameWithRole((string)($a['full_name'] ?? ''), (string)($a['role'] ?? ''), (string)($a['job_title'] ?? ''))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
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
        <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Generated</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['generated']); ?></div>
                        <div class="small text-muted">Forms: <?php echo number_format((int)$summary['forms']); ?> • Pending: <?php echo number_format((int)$summary['forms_pending']); ?></div>
                    </div>
                    <div class="text-primary fs-3"><i class="bi bi-collection"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Pending QA</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['pending_qa']); ?></div>
                    </div>
                    <div class="text-warning fs-3"><i class="bi bi-hourglass-split"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Qualified</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['qualified']); ?></div>
                    </div>
                    <div class="text-success fs-3"><i class="bi bi-check2-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Disqualified</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['disqualified']); ?></div>
                    </div>
                    <div class="text-danger fs-3"><i class="bi bi-x-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Delivered</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['delivered']); ?></div>
                    </div>
                    <div class="text-primary fs-3"><i class="bi bi-truck"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Accepted</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['accepted']); ?></div>
                    </div>
                    <div class="fs-3" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Today Attendance</div>
                        <div class="h5 mb-0"><?php echo htmlspecialchars($attStatus !== '' ? $attStatus : '—'); ?></div>
                        <div class="small text-muted">Late: <?php echo number_format($lateMin); ?> min</div>
                    </div>
                    <div class="text-info fs-3"><i class="bi bi-calendar-check"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-people me-1"></i>Agent Performance (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
        <div class="analytics-table-toolbar">
            <div class="analytics-toolbar-main">
                <span class="analytics-chip"><?php echo number_format(count($agentRows)); ?> agents tracked</span>
                <span class="analytics-metric-note">Delivered and Accepted are now split from the CRM client-delivery status so both counts stay visible separately.</span>
            </div>
        </div>
        <div class="card-body p-0 analytics-table-wrap">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Agent</th>
                            <th class="text-end">Generated</th>
                            <th class="text-end">Qualified</th>
                            <th class="text-end">Pending QA</th>
                            <th class="text-end">DQ</th>
                            <th class="text-end">Delivered</th>
                            <th class="text-end">Accepted</th>
                            <th class="text-end">Delivery %</th>
                            <th class="text-end">Target/Day</th>
                            <th class="text-end">Avg Q/Day</th>
                            <th class="text-end">Performance </th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agentRows as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?><div class="text-muted small"><?php echo htmlspecialchars(getRoleLabelShort((string)($r['role'] ?? ''))); ?></div></td>
                                <td class="text-end"><?php echo number_format((int)($r['generated'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['qualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['pending_qa'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['disqualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['accepted'] ?? 0)); ?></td>
                                <td class="text-end"><span class="badge bg-primary"><?php echo number_format((float)($r['delivery_pct'] ?? 0), 1); ?>%</span></td>
                                <td class="text-end"><?php echo number_format((int)($r['daily_target'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((float)($r['avg_qualified_per_day'] ?? 0), 2); ?></td>
                                <td class="text-end">
                                    <?php if (($r['performance_pct'] ?? null) === null): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <span class="badge <?php echo ((float)$r['performance_pct'] >= 100.0) ? 'bg-success' : (((float)$r['performance_pct'] >= 80.0) ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                                            <?php echo number_format((float)$r['performance_pct'], 1); ?>%
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['agent_id' => (int)($r['id'] ?? 0)]))); ?>" title="Focus this agent"><i class="bi bi-arrow-right-circle"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($agentRows)): ?>
                            <tr><td colspan="12" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm analytics-panel h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-bullseye me-1"></i>Target & Projection (<?php echo htmlspecialchars($now->format('M Y')); ?>)</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6"><div class="analytics-summary-card"><div class="analytics-summary-label">Daily Target</div><div class="analytics-summary-value"><?php echo number_format($dailyTarget); ?></div><div class="small text-muted">Working days: <?php echo number_format($workingDays); ?></div></div></div>
                        <div class="col-6"><div class="analytics-summary-card"><div class="analytics-summary-label">Monthly Target</div><div class="analytics-summary-value"><?php echo number_format($monthlyTarget); ?></div><div class="small text-muted">Projected qualified: <?php echo number_format($projMonth, 1); ?></div></div></div>
                        <div class="col-12"><div class="analytics-summary-card"><div class="analytics-summary-label">Projected Productivity</div><div class="analytics-summary-value"><?php echo number_format($projPct, 1); ?>%</div><div class="small text-muted">Based on average qualified leads per day in the selected range.</div></div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm analytics-panel h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-activity me-1"></i>Daily Trend</div>
                <div class="analytics-table-toolbar">
                    <div class="analytics-toolbar-main">
                        <span class="analytics-chip"><?php echo htmlspecialchars($agentName); ?></span>
                        <span class="analytics-metric-note">Generated, qualified, delivered, and accepted counts by date for the selected agent.</span>
                    </div>
                </div>
                <div class="card-body p-0 analytics-table-wrap">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Date</th><th class="text-end">Generated</th><th class="text-end">Qualified</th><th class="text-end">Delivered</th><th class="text-end">Accepted</th></tr></thead>
                            <tbody>
                                <?php foreach ($trendRows as $t): ?>
                                    <tr>
                                        <td class="text-muted"><?php echo htmlspecialchars((string)($t['d'] ?? '')); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['generated'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['qualified'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['delivered'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['accepted'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($trendRows)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/layout/app_end.php'; ?>
