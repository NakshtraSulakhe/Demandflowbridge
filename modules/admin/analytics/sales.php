<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

requirePermissionOrRole('admin.analytics.sales.page', ['admin','director','manager_director']);
ensureDatabaseSchema();
ensureCsrfToken();

$conn = getDbConnection();
$now = new DateTime();

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($now->format('Y-m-01') . ' 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($now->format('Y-m-t') . ' 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$analyticsTabs = [
    'campaigns' => ['label' => 'Campaigns', 'href' => appBasePath() . '/modules/admin/analytics/campaigns', 'icon' => 'bi-megaphone'],
    'agents' => ['label' => 'Agents', 'href' => appBasePath() . '/modules/admin/analytics/agents', 'icon' => 'bi-people'],
    'sales' => ['label' => 'Sales', 'href' => appBasePath() . '/modules/admin/analytics/sales', 'icon' => 'bi-funnel'],
    'dbms' => ['label' => 'DBMS', 'href' => appBasePath() . '/modules/admin/analytics/dbms', 'icon' => 'bi-database-check'],
];
$currentTab = 'sales';
$salesDashboardUrl = appBasePath() . '/modules/sales/dashboard.php';
$salesProspectsUrl = appBasePath() . '/modules/sales/leads.php';
$salesActivityUrl = appBasePath() . '/modules/sales/activity.php';

$statuses = ['New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];
$counts = array_fill_keys($statuses, 0);
$total = 0;
$stmt = $conn->prepare("SELECT status, COUNT(*) AS c FROM sales_leads WHERE created_at BETWEEN ? AND ? GROUP BY status");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $st = (string)($r['status'] ?? '');
        if ($st === '') continue;
        $counts[$st] = (int)($r['c'] ?? 0);
        $total += (int)($r['c'] ?? 0);
    }
}

$overdue = 0;
$rs = $conn->query("SELECT COUNT(*) AS c FROM sales_leads WHERE next_follow_up_at IS NOT NULL AND next_follow_up_at < NOW() AND status NOT IN ('Closed Won','Closed Lost')");
if ($rs) $overdue = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);

$owners = [];
$stmt = $conn->prepare("
    SELECT u.full_name, u.role, u.job_title,
           COUNT(sl.id) AS total,
           SUM(sl.status = 'Closed Won') AS won,
           SUM(sl.status = 'Closed Lost') AS lost,
           SUM(sl.status IN ('Proposal Sent','Negotiation')) AS opportunities
    FROM sales_leads sl
    LEFT JOIN users u ON u.id = sl.owner_id
    WHERE sl.created_at BETWEEN ? AND ?
    GROUP BY sl.owner_id
    ORDER BY won DESC, opportunities DESC, total DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $owners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}
$openPipeline = max(0, $total - (int)($counts['Closed Won'] ?? 0) - (int)($counts['Closed Lost'] ?? 0));

$pageTitle = 'Sales Pipeline';
include __DIR__ . '/../../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 analytics-workspace">
    <div class="analytics-hero mb-3">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <span class="analytics-eyebrow"><i class="bi bi-funnel"></i>Admin Analytics</span>
                <div class="analytics-title mt-2">Sales Pipeline Analytics</div>
                <div class="analytics-subtitle">Track pipeline stages, overdue follow-ups, owner output, and top opportunity movement with direct paths back into the sales module.</div>
            </div>
            <div class="analytics-chip-row">
                <span class="analytics-chip"><?php echo htmlspecialchars($rangeLabel); ?></span>
                <span class="analytics-chip"><?php echo number_format($total); ?> prospects</span>
                <span class="analytics-chip"><?php echo number_format($openPipeline); ?> open</span>
                <span class="analytics-chip"><?php echo number_format($overdue); ?> overdue follow-ups</span>
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
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($salesDashboardUrl); ?>"><i class="bi bi-house-door me-1"></i>Sales Home</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($salesProspectsUrl); ?>"><i class="bi bi-funnel me-1"></i>Prospects</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($salesDashboardUrl); ?>"><i class="bi bi-speedometer2 me-1"></i>Sales Dashboard</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($salesActivityUrl); ?>"><i class="bi bi-clock-history me-1"></i>Activity</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel analytics-filter-card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end analytics-inline-form" method="get">
        <div class="col-md-4">
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
        <div class="col-md-2 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <a class="analytics-kpi-link" href="<?php echo htmlspecialchars($salesProspectsUrl); ?>">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Total Prospects (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
                        <div class="h4 mb-0"><?php echo number_format($total); ?></div>
                        <div class="small text-muted">Open pipeline: <?php echo number_format($openPipeline); ?></div>
                    </div>
                    <div class="text-primary fs-3"><i class="bi bi-people"></i></div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Opportunities</div>
                        <div class="h4 mb-0"><?php echo number_format((int)($counts['Proposal Sent'] ?? 0) + (int)($counts['Negotiation'] ?? 0)); ?></div>
                        <div class="small text-muted">Proposal + Negotiation</div>
                    </div>
                    <div class="text-warning fs-3"><i class="bi bi-lightning-charge"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Closed Won</div>
                        <div class="h4 mb-0"><?php echo number_format((int)($counts['Closed Won'] ?? 0)); ?></div>
                    </div>
                    <div class="text-success fs-3"><i class="bi bi-trophy"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Closed Lost</div>
                        <div class="h4 mb-0"><?php echo number_format((int)($counts['Closed Lost'] ?? 0)); ?></div>
                        <div class="small text-muted">Overdue follow-ups: <?php echo number_format($overdue); ?></div>
                    </div>
                    <div class="text-danger fs-3"><i class="bi bi-x-octagon"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Funnel</div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($statuses as $st): ?>
                    <div class="col-6 col-lg-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small"><?php echo htmlspecialchars($st); ?></div>
                            <div class="h4 mb-0"><?php echo number_format((int)($counts[$st] ?? 0)); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-person-lines-fill me-1"></i>Owner Productivity</div>
        <div class="analytics-table-toolbar">
            <div class="analytics-toolbar-main">
                <span class="analytics-chip"><?php echo number_format(count($owners)); ?> owners</span>
                <span class="analytics-metric-note">Owner totals are filtered by created date, while overdue follow-ups are tracked from the live open pipeline.</span>
            </div>
        </div>
        <div class="card-body p-0 analytics-table-wrap">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Owner</th>
                            <th>Role</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Won</th>
                            <th class="text-end">Lost</th>
                            <th class="text-end">Opportunities</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($owners as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? 'Unassigned')); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars(getUserDisplayTitle((string)($r['role'] ?? ''), (string)($r['job_title'] ?? ''))); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['total'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['won'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['lost'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['opportunities'] ?? 0)); ?></td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($salesProspectsUrl); ?>" title="Open sales leads"><i class="bi bi-box-arrow-up-right"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($owners)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/layout/app_end.php'; ?>
