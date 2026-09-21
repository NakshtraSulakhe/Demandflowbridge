<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

requirePermissionOrRole('admin.analytics.campaigns.page', ['admin','director','manager_director']);
ensureDatabaseSchema();
ensureCsrfToken();

$conn = getDbConnection();
$now = new DateTime();
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($now->format('Y-m-01') . ' 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($now->format('Y-m-t') . ' 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$leadCreatedExprBase = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('') : 'created_at';
$qaStatusExpr = "UPPER(TRIM(COALESCE(l.qa_status, '')))";
$qaStatusExprBase = "UPPER(TRIM(COALESCE(qa_status, '')))";
$pendingQaExpr = "($qaStatusExpr = '' OR $qaStatusExpr IN ('PENDING','REOPENED','PENDING QA'))";
$pendingQaExprBase = "($qaStatusExprBase = '' OR $qaStatusExprBase IN ('PENDING','REOPENED','PENDING QA'))";
$otherPendingExpr = "($qaStatusExpr <> '' AND $qaStatusExpr NOT IN ('PENDING','REOPENED','PENDING QA','QUALIFIED','DISQUALIFIED','DUPLICATE','DUPLICATE/SUPP','APPROVED','REJECTED'))";
$otherPendingExprBase = "($qaStatusExprBase <> '' AND $qaStatusExprBase NOT IN ('PENDING','REOPENED','PENDING QA','QUALIFIED','DISQUALIFIED','DUPLICATE','DUPLICATE/SUPP','APPROVED','REJECTED'))";
$joinedPendingQaExpr = "(l.id IS NOT NULL AND $pendingQaExpr)";
$joinedOtherPendingExpr = "(l.id IS NOT NULL AND $otherPendingExpr)";
$joinedQualifiedExpr = "(l.id IS NOT NULL AND $qaStatusExpr = 'QUALIFIED')";
$joinedDisqualifiedExpr = "(l.id IS NOT NULL AND $qaStatusExpr = 'DISQUALIFIED')";
$campaignDetailsRollupSql = "
    SELECT
        campaign_id,
        MAX(COALESCE(status, '')) AS status,
        MAX(COALESCE(client_id, 0)) AS client_id,
        MAX(COALESCE(cpl, 0)) AS cpl,
        MAX(COALESCE(cpl_currency, 'USD')) AS cpl_currency
    FROM campaign_details
    GROUP BY campaign_id
";

$campaigns = getAllCampaignsBasic();
$analyticsTabs = [
    'campaigns' => ['label' => 'Campaigns', 'href' => appBasePath() . '/modules/admin/analytics/campaigns', 'icon' => 'bi-megaphone'],
    'agents' => ['label' => 'Agents', 'href' => appBasePath() . '/modules/admin/analytics/agents', 'icon' => 'bi-people'],
    'sales' => ['label' => 'Sales', 'href' => appBasePath() . '/modules/admin/analytics/sales', 'icon' => 'bi-funnel'],
    'dbms' => ['label' => 'DBMS', 'href' => appBasePath() . '/modules/admin/analytics/dbms', 'icon' => 'bi-database-check'],
];
$currentTab = 'campaigns';
$adminDashboardUrl = appBasePath() . '/modules/dashboard/admin-dashboard';
$adminSettingsUrl = appBasePath() . '/modules/admin/settings';
$campaignListUrl = appBasePath() . '/modules/campaigns/list';
$leadManagerUrl = appBasePath() . '/modules/leads/list';
$revenueUrl = appBasePath() . '/modules/revenue/revenue.php';
$revenueInvoicesUrl = appBasePath() . '/modules/revenue/invoices.php';
$qaAuditUrl = appBasePath() . '/modules/qa/audit';

$selectedCampaignName = '';
foreach ($campaigns as $campaignOption) {
    if ((int)($campaignOption['id'] ?? 0) === $campaignId) {
        $selectedCampaignName = crmCampaignDisplayNameFromRow($campaignOption);
        break;
    }
}

$summary = ['generated' => 0, 'pending_qa' => 0, 'other_pending' => 0, 'qualified' => 0, 'disqualified' => 0, 'delivered' => 0, 'accepted' => 0, 'forms_submitted' => 0, 'forms_pending' => 0, 'revenue' => 0.0, 'expense' => 0.0];
$summarySql = "
    SELECT
        COUNT(*) AS generated,
        SUM(CASE WHEN $pendingQaExprBase THEN 1 ELSE 0 END) AS pending_qa,
        SUM(CASE WHEN $otherPendingExprBase THEN 1 ELSE 0 END) AS other_pending,
        SUM(CASE WHEN $qaStatusExprBase = 'QUALIFIED' THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN $qaStatusExprBase = 'DISQUALIFIED' THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS accepted,
        SUM(CASE WHEN $qaStatusExprBase = 'QUALIFIED' AND LOWER(TRIM(COALESCE(form_done, ''))) = 'yes' THEN 1 ELSE 0 END) AS forms_submitted,
        SUM(CASE WHEN $qaStatusExprBase = 'QUALIFIED' AND (form_done IS NULL OR TRIM(form_done) = '' OR LOWER(TRIM(form_done)) <> 'yes') THEN 1 ELSE 0 END) AS forms_pending
    FROM leads
    WHERE $leadCreatedExprBase BETWEEN ? AND ?
";
$summaryTypes = 'ss';
$summaryParams = [$rangeStart, $rangeEnd];
if ($campaignId > 0) {
    $summarySql .= " AND campaign_id = ?";
    $summaryTypes .= 'i';
    $summaryParams[] = $campaignId;
}
$stmt = $conn->prepare($summarySql);
if ($stmt) {
    mysqliBindParams($stmt, $summaryTypes, $summaryParams);
    $stmt->execute();
    $summary = array_merge($summary, $stmt->get_result()->fetch_assoc() ?: []);
    $stmt->close();
}

$cardStartDate = $sd;
$cardEndDate = $ed;
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS revenue FROM revenue_invoices WHERE issue_date BETWEEN ? AND ?" . ($campaignId > 0 ? " AND campaign_id = ?" : ""));
if ($stmt) {
    $types = 'ss' . ($campaignId > 0 ? 'i' : '');
    $params = [$cardStartDate, $cardEndDate];
    if ($campaignId > 0) $params[] = $campaignId;
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $summary['revenue'] = (float)($row['revenue'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS expense FROM revenue_manual_expenses WHERE expense_date BETWEEN ? AND ?" . ($campaignId > 0 ? " AND campaign_id = ?" : ""));
if ($stmt) {
    $types = 'ss' . ($campaignId > 0 ? 'i' : '');
    $params = [$cardStartDate, $cardEndDate];
    if ($campaignId > 0) $params[] = $campaignId;
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $summary['expense'] = (float)($row['expense'] ?? 0);
    $stmt->close();
}

$rows = [];
$rowsSql = "
    SELECT
        c.id,
        c.name,
        COALESCE(d.status,'') AS campaign_status,
        COALESCE(d.client_id,0) AS client_id,
        COALESCE(cl.client_code,'') AS client_code,
        COALESCE(cl.name,'') AS client_name,
        COALESCE(d.cpl,0) AS set_cpl,
        COALESCE(d.cpl_currency,'USD') AS cpl_currency,
        COUNT(l.id) AS generated,
        SUM(CASE WHEN $joinedPendingQaExpr THEN 1 ELSE 0 END) AS pending_qa,
        SUM(CASE WHEN $joinedOtherPendingExpr THEN 1 ELSE 0 END) AS other_pending,
        SUM(CASE WHEN $joinedQualifiedExpr THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN $joinedDisqualifiedExpr THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN l.client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS client_accepted,
        SUM(CASE WHEN l.client_delivery_status = 'Rejected' THEN 1 ELSE 0 END) AS client_rejected,
        SUM(CASE WHEN l.client_delivery_status = 'TBD(To be discussed)' THEN 1 ELSE 0 END) AS client_tbd,
        SUM(CASE WHEN l.client_delivery_status = 'In Progress' THEN 1 ELSE 0 END) AS client_in_progress,
        SUM(CASE WHEN $joinedQualifiedExpr AND LOWER(TRIM(COALESCE(l.form_done, ''))) = 'yes' THEN 1 ELSE 0 END) AS forms_submitted,
        SUM(CASE WHEN $joinedQualifiedExpr AND (l.form_done IS NULL OR TRIM(l.form_done) = '' OR LOWER(TRIM(l.form_done)) <> 'yes') THEN 1 ELSE 0 END) AS forms_pending
    FROM campaigns c
    LEFT JOIN ($campaignDetailsRollupSql) d ON d.campaign_id = c.id
    LEFT JOIN clients cl ON cl.id = d.client_id
    LEFT JOIN leads l ON l.campaign_id = c.id AND $leadCreatedExpr BETWEEN ? AND ?
    WHERE c.active = 1
";
if ($campaignId > 0) {
    $rowsSql .= " AND c.id = ?";
}
$rowsSql .= "
    GROUP BY c.id
    ORDER BY generated DESC, c.name ASC
";
$stmt = $conn->prepare($rowsSql);
if ($stmt) {
    $types = 'ss' . ($campaignId > 0 ? 'i' : '');
    $params = [$rangeStart, $rangeEnd];
    if ($campaignId > 0) $params[] = $campaignId;
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$revByCamp = [];
$stmt = $conn->prepare("SELECT campaign_id, COALESCE(SUM(total),0) AS rev, COALESCE(SUM(CASE WHEN status='Paid' THEN total ELSE 0 END),0) AS paid FROM revenue_invoices WHERE issue_date BETWEEN ? AND ?" . ($campaignId > 0 ? " AND campaign_id = ?" : "") . " GROUP BY campaign_id");
if ($stmt) {
    $types = 'ss' . ($campaignId > 0 ? 'i' : '');
    $params = [$sd, $ed];
    if ($campaignId > 0) $params[] = $campaignId;
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) {
        $cid = (int)($r['campaign_id'] ?? 0);
        $revByCamp[$cid] = ['rev' => (float)($r['rev'] ?? 0), 'paid' => (float)($r['paid'] ?? 0)];
    }
}

$expByCamp = [];
$stmt = $conn->prepare("SELECT campaign_id, COALESCE(SUM(amount),0) AS exp FROM revenue_manual_expenses WHERE expense_date BETWEEN ? AND ?" . ($campaignId > 0 ? " AND campaign_id = ?" : "") . " GROUP BY campaign_id");
if ($stmt) {
    $types = 'ss' . ($campaignId > 0 ? 'i' : '');
    $params = [$sd, $ed];
    if ($campaignId > 0) $params[] = $campaignId;
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) $expByCamp[(int)($r['campaign_id'] ?? 0)] = (float)($r['exp'] ?? 0);
}

foreach ($rows as &$r) {
    $cid = (int)($r['id'] ?? 0);
    $rev = (float)(($revByCamp[$cid]['rev'] ?? 0) ?: 0);
    $paid = (float)(($revByCamp[$cid]['paid'] ?? 0) ?: 0);
    $exp = (float)($expByCamp[$cid] ?? 0);
    $del = (int)($r['delivered'] ?? 0);
    $roi = $exp > 0 ? (($rev - $exp) / $exp) : ($rev > 0 ? 1.0 : 0.0);
    $effCpl = $del > 0 ? ($rev / $del) : 0.0;
    $r['revenue'] = $rev;
    $r['paid'] = $paid;
    $r['expense'] = $exp;
    $r['roi'] = $roi;
    $r['effective_cpl'] = $effCpl;
    $r['delivery_pending'] = max(0, (int)($r['generated'] ?? 0) - (int)($r['delivered'] ?? 0));
    $r['delivery_pct'] = ((int)($r['generated'] ?? 0) > 0) ? round(((int)($r['delivered'] ?? 0) / max(1, (int)($r['generated'] ?? 0))) * 100, 1) : 0.0;
}
unset($r);
$campaignCount = count($rows);
$leadAcceptancePct = $summary['generated'] > 0 ? round(($summary['delivered'] / max(1, $summary['generated'])) * 100, 1) : 0.0;

$clientRows = [];
$clientRowsSql = "
    SELECT
        cl.id,
        COALESCE(cl.client_code,'') AS client_code,
        COALESCE(cl.name,'') AS client_name,
        COUNT(DISTINCT c.id) AS campaigns,
        COUNT(l.id) AS generated,
        SUM(CASE WHEN $joinedPendingQaExpr THEN 1 ELSE 0 END) AS pending_qa,
        SUM(CASE WHEN $joinedQualifiedExpr THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN $joinedDisqualifiedExpr THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN l.client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS accepted
    FROM clients cl
    JOIN ($campaignDetailsRollupSql) d ON d.client_id = cl.id
    JOIN campaigns c ON c.id = d.campaign_id AND c.active = 1
    LEFT JOIN leads l ON l.campaign_id = c.id AND $leadCreatedExpr BETWEEN ? AND ?
";
if ($campaignId > 0) {
    $clientRowsSql .= " WHERE c.id = ?";
}
$clientRowsSql .= "
    GROUP BY cl.id
    ORDER BY delivered DESC, generated DESC, client_name ASC
";
$stmt = $conn->prepare($clientRowsSql);
if ($stmt) {
    $types = 'ss' . ($campaignId > 0 ? 'i' : '');
    $params = [$rangeStart, $rangeEnd];
    if ($campaignId > 0) $params[] = $campaignId;
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $clientRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($clientRows as &$cr) {
        $cr['delivery_pending'] = max(0, (int)($cr['generated'] ?? 0) - (int)($cr['delivered'] ?? 0));
        $cr['delivery_pct'] = ((int)($cr['generated'] ?? 0) > 0) ? round(((int)($cr['delivered'] ?? 0) / max(1, (int)($cr['generated'] ?? 0))) * 100, 1) : 0.0;
    }
    unset($cr);
}
$clientQaTotals = ['pending_qa' => 0, 'qualified' => 0, 'disqualified' => 0, 'delivered' => 0, 'accepted' => 0];
foreach ($clientRows as $clientRow) {
    $clientQaTotals['pending_qa'] += (int)($clientRow['pending_qa'] ?? 0);
    $clientQaTotals['qualified'] += (int)($clientRow['qualified'] ?? 0);
    $clientQaTotals['disqualified'] += (int)($clientRow['disqualified'] ?? 0);
    $clientQaTotals['delivered'] += (int)($clientRow['delivered'] ?? 0);
    $clientQaTotals['accepted'] += (int)($clientRow['accepted'] ?? 0);
}

$pageTitle = 'Campaign Analytics';
include __DIR__ . '/../../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 admin-dashboard analytics-workspace">
    <div class="analytics-hero mb-3">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <span class="analytics-eyebrow"><i class="bi bi-graph-up-arrow"></i>Admin Analytics</span>
                <div class="analytics-title mt-2">Campaign Analytics</div>
                <div class="analytics-subtitle">Validate campaign output, client delivery health, revenue coverage, and the connected campaign workflow from one admin view.</div>
            </div>
            <div class="analytics-chip-row">
                <span class="analytics-chip"><?php echo htmlspecialchars($rangeLabel); ?></span>
                <span class="analytics-chip"><?php echo number_format($campaignCount); ?> campaigns</span>
                <span class="analytics-chip"><?php echo number_format($leadAcceptancePct, 1); ?>% delivered</span>
                <?php if ($selectedCampaignName !== ''): ?><span class="analytics-chip"><?php echo htmlspecialchars($selectedCampaignName); ?></span><?php endif; ?>
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
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($adminSettingsUrl); ?>"><i class="bi bi-sliders me-1"></i>Settings</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($campaignListUrl); ?>"><i class="bi bi-list-ul me-1"></i>Campaign List</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($leadManagerUrl); ?>"><i class="bi bi-table me-1"></i>Lead Manager</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($revenueUrl); ?>"><i class="bi bi-cash-stack me-1"></i>Revenue</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel analytics-filter-card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end analytics-inline-form" method="get">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Range</label>
                    <select class="form-select form-select-sm" name="range_preset">
                        <option value="today" <?php echo $preset === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="this_month" <?php echo $preset === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="overall" <?php echo $preset === 'overall' ? 'selected' : ''; ?>>Overall</option>
                        <option value="custom" <?php echo $preset === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Start</label>
                    <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($sd); ?>" <?php echo $preset === 'custom' ? '' : 'disabled'; ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">End</label>
                    <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($ed); ?>" <?php echo $preset === 'custom' ? '' : 'disabled'; ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Campaign</label>
                    <select class="form-select form-select-sm" name="campaign_id">
                        <option value="">All Campaigns</option>
                        <?php foreach ($campaigns as $c): ?>
                            <?php $cid = (int)($c['id'] ?? 0); ?>
                            <option value="<?php echo $cid; ?>" <?php echo $cid === $campaignId ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-2">
            <a class="analytics-kpi-link" href="<?php echo htmlspecialchars($campaignListUrl); ?>">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Generated</div>
                        <div class="h4 mb-0"><?php echo number_format($summary['generated']); ?></div>
                        <div class="small text-muted">Forms: <?php echo number_format($summary['forms_submitted']); ?> • Pending: <?php echo number_format($summary['forms_pending']); ?></div>
                    </div>
                    <div class="text-primary fs-3"><i class="bi bi-collection"></i></div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a class="analytics-kpi-link" href="<?php echo htmlspecialchars($qaAuditUrl); ?>">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Pending QA</div>
                        <div class="h4 mb-0"><?php echo number_format($summary['pending_qa']); ?></div>
                        <div class="small text-muted">Other Pending: <?php echo number_format($summary['other_pending']); ?></div>
                    </div>
                    <div class="text-warning fs-3"><i class="bi bi-hourglass-split"></i></div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a class="analytics-kpi-link" href="<?php echo htmlspecialchars($campaignListUrl); ?>">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Qualified</div>
                        <div class="h4 mb-0"><?php echo number_format($summary['qualified']); ?></div>
                    </div>
                    <div class="text-success fs-3"><i class="bi bi-check2-circle"></i></div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Disqualified</div>
                        <div class="h4 mb-0"><?php echo number_format($summary['disqualified']); ?></div>
                    </div>
                    <div class="text-danger fs-3"><i class="bi bi-x-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <a class="analytics-kpi-link" href="<?php echo htmlspecialchars($revenueUrl); ?>">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Delivered</div>
                        <div class="h4 mb-0"><?php echo number_format($summary['delivered']); ?></div>
                    </div>
                    <div class="text-primary fs-3"><i class="bi bi-truck"></i></div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a class="analytics-kpi-link" href="<?php echo htmlspecialchars($revenueUrl); ?>">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Accepted</div>
                        <div class="h4 mb-0"><?php echo number_format($summary['accepted']); ?></div>
                    </div>
                    <div class="fs-3" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a class="analytics-kpi-link" href="<?php echo htmlspecialchars($revenueInvoicesUrl); ?>">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Revenue / Expense</div>
                        <div class="h5 mb-0"><?php echo number_format($summary['revenue'],2); ?></div>
                        <div class="small text-muted">Expense: <?php echo number_format($summary['expense'],2); ?></div>
                    </div>
                    <div class="text-info fs-3"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-building me-1"></i>Client Delivery Summary</div>
        <div class="analytics-table-toolbar">
            <div class="analytics-toolbar-main">
                <span class="analytics-chip"><?php echo number_format(count($clientRows)); ?> clients</span>
                <span class="analytics-metric-note">Use this table to validate client-level campaign volume, QA progression, delivered output, and accepted output with the selected filters.</span>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending QA <?php echo number_format((int)$clientQaTotals['pending_qa']); ?></span>
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Qualified <?php echo number_format((int)$clientQaTotals['qualified']); ?></span>
                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">DQ <?php echo number_format((int)$clientQaTotals['disqualified']); ?></span>
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Delivered <?php echo number_format((int)$clientQaTotals['delivered']); ?></span>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Accepted <?php echo number_format((int)$clientQaTotals['accepted']); ?></span>
            </div>
        </div>
        <div class="card-body p-0 analytics-table-wrap">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Client</th>
                            <th class="text-end">Campaigns</th>
                            <th class="text-end">Generated</th>
                            <th class="text-end">Pending QA</th>
                            <th class="text-end">Qualified</th>
                            <th class="text-end">DQ</th>
                            <th class="text-end">Delivered</th>
                            <th class="text-end">Accepted</th>
                            <th class="text-end">Pending</th>
                            <th class="text-end">Delivery%</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientRows as $r): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?php echo htmlspecialchars(trim((string)($r['client_code'] ?? '')) !== '' ? (string)$r['client_code'] : (string)($r['client_name'] ?? '')); ?>
                                </td>
                                <td class="text-end"><?php echo number_format((int)($r['campaigns'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['generated'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['pending_qa'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['qualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['disqualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['accepted'] ?? 0)); ?></td>
                                <td class="text-end"><span class="badge bg-secondary"><?php echo number_format((int)($r['delivery_pending'] ?? 0)); ?></span></td>
                                <td class="text-end"><span class="badge bg-primary"><?php echo number_format((float)($r['delivery_pct'] ?? 0), 1); ?>%</span></td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($campaignListUrl); ?>" title="Open campaigns list"><i class="bi bi-box-arrow-up-right"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientRows)): ?>
                            <tr><td colspan="11" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm analytics-panel mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-table me-1"></i>Campaign Summary</div>
        <div class="analytics-table-toolbar">
            <div class="analytics-toolbar-main">
                <span class="analytics-chip"><?php echo number_format($campaignCount); ?> campaigns tracked</span>
                <span class="analytics-metric-note">Counts are driven by leads created in the selected range and revenue/expense rows tied to the same campaign, with separate delivered and accepted counts.</span>
            </div>
        </div>
        <div class="card-body p-0 analytics-table-wrap">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Campaign</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th class="text-end">Generated</th>
                            <th class="text-end">Pending QA</th>
                            <th class="text-end">QU.</th>
                            <th class="text-end">DQ</th>
                            <th class="text-end">Del.</th>
                            <th class="text-end">Acc.</th>
                            <th>Client Status</th>
                            <th class="text-end">Pending</th>
                            <th class="text-end">Delivery%</th>
                            <th class="text-end">Forms</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Expense</th>
                            <th class="text-end">ROI</th>
                            <th class="text-end">Eff. CPL</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                                <td class="text-muted small">
                                    <?php
                                        $cc = trim((string)($r['client_code'] ?? ''));
                                        $cn = trim((string)($r['client_name'] ?? ''));
                                        echo htmlspecialchars($cc !== '' ? $cc : $cn);
                                    ?>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['campaign_status'] ?? '')); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['generated'] ?? 0)); ?></td>
                                <td class="text-end">
                                    <?php echo number_format((int)($r['pending_qa'] ?? 0)); ?>
                                    <div class="text-muted small">Other: <?php echo number_format((int)($r['other_pending'] ?? 0)); ?></div>
                                </td>
                                <td class="text-end"><?php echo number_format((int)($r['qualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['disqualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['client_accepted'] ?? 0)); ?></td>
                                <td class="text-muted small">
                                    <span class="badge bg-success-subtle text-success border">A: <?php echo number_format((int)($r['client_accepted'] ?? 0)); ?></span>
                                    <span class="badge bg-danger-subtle text-danger border">R: <?php echo number_format((int)($r['client_rejected'] ?? 0)); ?></span>
                                    <span class="badge bg-secondary-subtle text-secondary border">TBD: <?php echo number_format((int)($r['client_tbd'] ?? 0)); ?></span>
                                    <span class="badge bg-info-subtle text-info border">IP: <?php echo number_format((int)($r['client_in_progress'] ?? 0)); ?></span>
                                </td>
                                <td class="text-end"><span class="badge bg-secondary"><?php echo number_format((int)($r['delivery_pending'] ?? 0)); ?></span></td>
                                <td class="text-end"><span class="badge bg-primary"><?php echo number_format((float)($r['delivery_pct'] ?? 0), 1); ?>%</span></td>
                                <td class="text-end">
                                    <?php echo number_format((int)($r['forms_submitted'] ?? 0)); ?>
                                    <span class="text-muted small">/ <?php echo number_format((int)($r['forms_pending'] ?? 0)); ?></span>
                                </td>
                                <td class="text-end"><?php echo number_format((float)($r['revenue'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo number_format((float)($r['expense'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo number_format(((float)($r['roi'] ?? 0))*100, 1); ?>%</td>
                                <td class="text-end"><?php echo number_format((float)($r['effective_cpl'] ?? 0), 2); ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/campaigns/view?id=<?php echo (int)($r['id'] ?? 0); ?>" title="View campaign"><i class="bi bi-eye"></i></a>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($revenueUrl); ?>" title="Open revenue"><i class="bi bi-cash-stack"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="18" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../../../includes/layout/app_end.php'; ?>
