<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$opsMonth = function_exists('opsMonthRange') ? opsMonthRange() : ['start' => $opsNow->format('Y-m-01 00:00:00'), 'end' => $opsNow->format('Y-m-t 23:59:59')];

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($opsMonth['start'] ?? '1970-01-01 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($opsMonth['end'] ?? '2999-12-31 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$start = substr($rangeStart, 0, 10);
$end = substr($rangeEnd, 0, 10);
$startDt = $rangeStart;
$endDt = $rangeEnd;
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$startFieldValue = $preset === 'custom' ? $start : $start;
$endFieldValue = $preset === 'custom' ? $end : $end;

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : substr($end, 0, 7);
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    $monthStr = substr($end, 0, 7);
    preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m);
}
$year = (int)($m[1] ?? (int)$opsNow->format('Y'));
$month = (int)($m[2] ?? (int)$opsNow->format('m'));

$money = function($amount, string $currency): string {
    $v = (float)$amount;
    $cur = trim($currency) !== '' ? trim($currency) : 'INR';
    return $cur . ' ' . number_format($v, 2);
};

$payslips = [];
$stmt = $conn->prepare("SELECT p.user_id, u.full_name, u.job_title, p.salary_data FROM hr_payslips p JOIN users u ON u.id = p.user_id WHERE p.year = ? AND p.month = ? ORDER BY u.full_name");
if ($stmt) {
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $payslips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$payrollRows = [];
$totalNet = 0.0;
$totalIncentives = 0.0;
foreach ($payslips as $r) {
    $data = json_decode((string)($r['salary_data'] ?? ''), true);
    if (!is_array($data)) $data = [];
    $earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
    $net = (float)($data['net_salary'] ?? 0);
    $incent = (float)($earn['incentives'] ?? 0);
    $totalNet += $net;
    $totalIncentives += $incent;
    $payrollRows[] = [
        'user_id' => (int)($r['user_id'] ?? 0),
        'full_name' => (string)($r['full_name'] ?? ''),
        'job_title' => (string)($r['job_title'] ?? ''),
        'net' => $net,
        'incentives' => $incent,
        'total' => $net,
    ];
}

$expenses = [];
$stmt = $conn->prepare("SELECT id, expense_date, category, description, amount, currency, campaign_id FROM revenue_manual_expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC, id DESC");
if ($stmt) {
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}
$expensesTotal = 0.0;
foreach ($expenses as $e) {
    $expensesTotal += (float)($e['amount'] ?? 0);
}

$clientRows = [];
$stmt = $conn->prepare("
    SELECT
        COALESCE(cl.client_code, d.client_code, '') AS client_code,
        COALESCE(cl.name, '') AS client_name,
        COALESCE(d.cpl_currency, 'USD') AS currency,
        COUNT(l.id) AS billable,
        SUM(COALESCE(d.cpl, 0)) AS revenue
    FROM leads l
    LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
    LEFT JOIN clients cl ON cl.client_code = d.client_code
    WHERE l.client_delivery_status = 'Accepted' AND $leadCreatedExpr BETWEEN ? AND ?
    GROUP BY client_code, client_name, currency
    ORDER BY revenue DESC
");
if ($stmt) {
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $clientRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$agentRows = [];
$stmt = $conn->prepare("
    SELECT
        l.agent_id,
        COALESCE(l.agent_name, '') AS agent_name,
        COALESCE(d.cpl_currency, 'USD') AS currency,
        COUNT(l.id) AS billable,
        SUM(COALESCE(d.cpl, 0)) AS revenue
    FROM leads l
    LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
    WHERE l.client_delivery_status = 'Accepted' AND $leadCreatedExpr BETWEEN ? AND ?
    GROUP BY l.agent_id, agent_name, currency
    ORDER BY revenue DESC
");
if ($stmt) {
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $agentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$campaignRows = [];
$stmt = $conn->prepare("
    SELECT
        c.id AS campaign_id,
        c.name,
        d.code,
        d.client_code,
        COALESCE(cl.name, '') AS client_name,
        d.cpl,
        COALESCE(d.cpl_currency, 'USD') AS currency,
        SUM(CASE WHEN l.client_delivery_status = 'Accepted' AND $leadCreatedExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS billable,
        SUM(CASE WHEN l.client_delivery_status = 'Accepted' AND $leadCreatedExpr BETWEEN ? AND ? THEN COALESCE(d.cpl, 0) ELSE 0 END) AS generated,
        r.revenue AS allocated,
        COALESCE(r.currency, d.cpl_currency, 'USD') AS allocated_currency
    FROM campaigns c
    LEFT JOIN campaign_details d ON d.campaign_id = c.id
    LEFT JOIN clients cl ON cl.client_code = d.client_code
    LEFT JOIN campaign_revenue r ON r.campaign_id = c.id
    LEFT JOIN leads l ON l.campaign_id = c.id
    GROUP BY c.id, c.name, d.code, d.client_code, client_name, d.cpl, currency, r.revenue, allocated_currency
    ORDER BY generated DESC
");
if ($stmt) {
    $stmt->bind_param('ssss', $startDt, $endDt, $startDt, $endDt);
    $stmt->execute();
    $campaignRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$revenueTotalsByCur = [];
foreach ($campaignRows as $r) {
    $cur = strtoupper(trim((string)($r['currency'] ?? 'USD')));
    if ($cur === '') $cur = 'USD';
    $val = (float)($r['generated'] ?? 0);
    if (!isset($revenueTotalsByCur[$cur])) $revenueTotalsByCur[$cur] = 0.0;
    $revenueTotalsByCur[$cur] += $val;
}
$primaryCur = 'USD';
$primaryVal = 0.0;
foreach ($revenueTotalsByCur as $cur => $val) {
    if ($val > $primaryVal) {
        $primaryVal = $val;
        $primaryCur = (string)$cur;
    }
}

$usdInr = getUsdInrRate($end) ?? getUsdInrRate($opsNow->format('Y-m-d'));
$usdGenerated = (float)($revenueTotalsByCur['USD'] ?? 0);
$inrGenerated = (float)($revenueTotalsByCur['INR'] ?? 0);
$inrFromUsd = $usdInr !== null ? ($usdGenerated * (float)$usdInr) : 0.0;
$generatedInrTotal = $inrGenerated + $inrFromUsd;

$totalPayrollCost = $totalNet;
$roiOverall = $totalPayrollCost > 0 ? ($generatedInrTotal / $totalPayrollCost) : null;
$roiOverallPct = $totalPayrollCost > 0 ? ((($generatedInrTotal - $totalPayrollCost) / $totalPayrollCost) * 100.0) : null;

$agentCostMap = [];
foreach ($payrollRows as $pr) {
    $uid = (int)($pr['user_id'] ?? 0);
    if ($uid <= 0) continue;
    $agentCostMap[$uid] = (float)($pr['total'] ?? 0);
}

$agentAgg = [];
foreach ($agentRows as $r) {
    $aid = (int)($r['agent_id'] ?? 0);
    if ($aid <= 0) continue;
    if (!isset($agentAgg[$aid])) {
        $agentAgg[$aid] = [
            'agent_id' => $aid,
            'agent_name' => (string)($r['agent_name'] ?? ''),
            'billable' => 0,
            'usd' => 0.0,
            'inr' => 0.0,
            'inr_from_usd' => 0.0,
            'revenue_inr' => 0.0,
            'cost_inr' => (float)($agentCostMap[$aid] ?? 0),
        ];
    }
    $agentAgg[$aid]['billable'] += (int)($r['billable'] ?? 0);
    $cur = strtoupper(trim((string)($r['currency'] ?? 'USD')));
    if ($cur === '') $cur = 'USD';
    $rev = (float)($r['revenue'] ?? 0);
    if ($cur === 'USD') {
        $agentAgg[$aid]['usd'] += $rev;
        if ($usdInr !== null) $agentAgg[$aid]['inr_from_usd'] += ($rev * (float)$usdInr);
    } elseif ($cur === 'INR') {
        $agentAgg[$aid]['inr'] += $rev;
    }
}
foreach ($agentAgg as $aid => $row) {
    $agentAgg[$aid]['revenue_inr'] = (float)$row['inr'] + (float)$row['inr_from_usd'];
}
$agentRoiRows = array_values($agentAgg);
usort($agentRoiRows, function($a, $b) {
    $av = (float)($a['revenue_inr'] ?? 0);
    $bv = (float)($b['revenue_inr'] ?? 0);
    if ($av === $bv) return 0;
    return $av > $bv ? -1 : 1;
});

$chartCurrencyLabels = array_keys($revenueTotalsByCur);
$chartCurrencyValues = array_values($revenueTotalsByCur);

$filterCur = $primaryCur;
$clientChartRows = array_values(array_filter($clientRows, function($r) use ($filterCur) {
    return strtoupper(trim((string)($r['currency'] ?? ''))) === $filterCur;
}));
$agentChartRows = array_values(array_filter($agentRows, function($r) use ($filterCur) {
    return strtoupper(trim((string)($r['currency'] ?? ''))) === $filterCur;
}));

$clientLabels = [];
$clientValues = [];
$clientTop = array_slice($clientChartRows, 0, 6);
$clientOther = 0.0;
foreach ($clientTop as $r) {
    $label = (string)($r['client_name'] ?? '');
    if ($label === '') $label = (string)($r['client_code'] ?? 'Client');
    $clientLabels[] = $label;
    $clientValues[] = (float)($r['revenue'] ?? 0);
}
foreach (array_slice($clientChartRows, 6) as $r) {
    $clientOther += (float)($r['revenue'] ?? 0);
}
if ($clientOther > 0) {
    $clientLabels[] = 'Others';
    $clientValues[] = $clientOther;
}

$agentLabels = [];
$agentValues = [];
foreach (array_slice($agentChartRows, 0, 10) as $r) {
    $agentLabels[] = (string)($r['agent_name'] ?? 'Agent');
    $agentValues[] = (float)($r['revenue'] ?? 0);
}

$campaignLabels = [];
$campaignValues = [];
foreach (array_slice($campaignRows, 0, 10) as $r) {
    $campaignLabels[] = (string)($r['name'] ?? 'Campaign');
    $campaignValues[] = (float)($r['generated'] ?? 0);
}

$pageTitle = 'Revenue Dashboard';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace revenue-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-cash-coin"></i>Revenue Intelligence</div>
            <h3 class="sales-page-title">Revenue Dashboard</h3>
            <div class="sales-page-subtitle">Monitor payroll cost, accepted lead revenue, invoice readiness, manual expenses, and sales-linked ROI from one CRM-aligned workspace.</div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="sales-chip"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($rangeLabel); ?></span>
                <span class="sales-chip"><i class="bi bi-clock-history"></i><?php echo htmlspecialchars($start); ?> to <?php echo htmlspecialchars($end); ?></span>
                <span class="sales-chip"><i class="bi bi-wallet2"></i>Payroll month <?php echo htmlspecialchars($monthStr); ?></span>
            </div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="revenue.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-currency-dollar me-1"></i>Campaign Revenue</a>
            <a class="btn btn-outline-secondary btn-sm" href="invoices.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-file-earmark-text me-1"></i>Invoices</a>
            <a class="btn btn-light border btn-sm" href="expenses.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-receipt-cutoff me-1"></i>Expenses</a>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav revenue-nav mb-3">
        <li class="nav-item"><a class="nav-link active" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="revenue.php?month=<?php echo urlencode($monthStr); ?>">Campaign Revenue</a></li>
        <li class="nav-item"><a class="nav-link" href="expenses.php?month=<?php echo urlencode($monthStr); ?>">Expenses</a></li>
        <li class="nav-item"><a class="nav-link" href="invoices.php?month=<?php echo urlencode($monthStr); ?>">Invoices</a></li>
        <li class="nav-item"><a class="nav-link" href="billto-templates.php">Bill To Templates</a></li>
        <li class="nav-item"><a class="nav-link" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>">Agent ROI</a></li>
    </ul>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel revenue-panel mb-3">
        <div class="card-body">
            <div class="revenue-filter-shell">
                <div class="performance-toolbar-actions mb-2">
                    <div>
                        <div class="revenue-section-title">Dashboard Filters</div>
                        <div class="revenue-section-text">Choose a revenue date window and a payroll month together so cost, revenue, invoices, and ROI stay aligned.</div>
                    </div>
                    <div class="revenue-range-readout">
                        <span class="sales-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($start); ?> to <?php echo htmlspecialchars($end); ?></span>
                    </div>
                </div>
                <form method="get" class="row g-3 align-items-end sales-filter-form revenue-inline-form">
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Range</label>
                <select class="form-select" name="range_preset" onchange="this.form.submit();">
                    <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
                    <option value="this_month" <?php echo $preset==='this_month'?'selected':''; ?>>This Month</option>
                    <option value="overall" <?php echo $preset==='overall'?'selected':''; ?>>Overall</option>
                    <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom Range</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Start</label>
                <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($startFieldValue); ?>" <?php echo $preset==='custom'?'':'readonly'; ?>>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">End</label>
                <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($endFieldValue); ?>" <?php echo $preset==='custom'?'':'readonly'; ?>>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Payroll</label>
                <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthStr); ?>">
            </div>
            <div class="col-12 col-md-1 d-flex justify-content-end">
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="revenue-callout h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="text-muted small">Total Salary (Net)</div>
                    <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-wallet2"></i></span>
                </div>
                <div class="revenue-callout-value mt-1"><?php echo $money($totalNet, 'INR'); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="revenue-callout h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="text-muted small">Total Incentives</div>
                    <span class="badge bg-success-subtle text-success border"><i class="bi bi-award"></i></span>
                </div>
                <div class="revenue-callout-value mt-1"><?php echo $money($totalIncentives, 'INR'); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="revenue-callout h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="text-muted small">Manual Expenses</div>
                    <span class="badge bg-danger-subtle text-danger border"><i class="bi bi-receipt-cutoff"></i></span>
                </div>
                <div class="revenue-callout-value mt-1"><?php echo $money($expensesTotal, 'INR'); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="revenue-callout h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="text-muted small">Generated Revenue</div>
                    <span class="badge bg-warning-subtle text-warning border"><i class="bi bi-graph-up-arrow"></i></span>
                </div>
                <div class="revenue-callout-value mt-1"><?php echo $money($primaryVal, $primaryCur); ?></div>
                <div class="text-muted small mt-1">
                    <span class="me-2">₹ <?php echo number_format((float)$generatedInrTotal, 2); ?></span>
                    <?php if ($usdGenerated > 0 && $usdInr === null): ?>
                        <span class="me-2">Set USD→INR FX Rate in Sales Targets</span>
                        <a class="text-decoration-none" href="../sales/targets.php"><i class="bi bi-currency-exchange me-1"></i>FX Rate</a>
                    <?php else: ?>
                        <span class="me-2">USD→INR: <?php echo $usdInr !== null ? number_format((float)$usdInr, 2) : '—'; ?></span>
                        <a class="text-decoration-none" href="../sales/targets.php"><i class="bi bi-currency-exchange me-1"></i>FX Rate</a>
                        <?php if ($usdGenerated > 0 && $usdInr !== null): ?>
                            <span class="ms-2">USD <?php echo number_format((float)$usdGenerated, 2); ?> × <?php echo number_format((float)$usdInr, 2); ?> = ₹ <?php echo number_format((float)$inrFromUsd, 2); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100 revenue-panel">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="text-muted small">ROI (Revenue ÷ Payroll)</div>
                    <span class="badge bg-info-subtle text-info border"><i class="bi bi-percent"></i></span>
                </div>
                <div class="h4 mb-0 mt-1">
                    <?php echo $roiOverall !== null ? number_format((float)$roiOverall, 2) . 'x' : '—'; ?>
                </div>
                <div class="text-muted small mt-1">
                    <span class="me-2">₹ <?php echo number_format((float)$generatedInrTotal, 2); ?> / ₹ <?php echo number_format((float)$totalPayrollCost, 2); ?></span>
                    <?php if ($roiOverallPct !== null): ?>
                        <span class="<?php echo $roiOverallPct >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($roiOverallPct >= 0 ? '+' : '') . number_format((float)$roiOverallPct, 1); ?>%</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-9">
            <div class="card border-0 shadow-sm sales-panel revenue-panel h-100">
                <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Agent ROI (Details)</div>
                    <a class="btn btn-sm btn-light border" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-people me-1"></i>Open</a>
                </div>
                <div class="text-muted small mt-2">ROI is calculated as Revenue (converted to INR using FX Rate) ÷ (Salary Net + Incentives) from Payslips.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sales-panel revenue-panel h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-pie-chart me-2"></i>Revenue by Currency</div>
                    <div class="text-muted small"><?php echo htmlspecialchars($monthStr); ?></div>
                </div>
                <div class="card-body">
                    <div style="height: 240px;"><canvas id="chartCurrency"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm sales-panel revenue-panel h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-diagram-3 me-2"></i>Top Clients (<?php echo htmlspecialchars($filterCur); ?>)</div>
                    <a class="btn btn-sm btn-light border" href="revenue?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-list-ul me-1"></i>Details</a>
                </div>
                <div class="card-body">
                    <div style="height: 240px;"><canvas id="chartClients"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm sales-panel revenue-panel h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-people me-2"></i>Top Agents (<?php echo htmlspecialchars($filterCur); ?>)</div>
                    <span class="text-muted small"> </span>
                </div>
                <div class="card-body">
                    <div style="height: 260px;"><canvas id="chartAgents"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm sales-panel h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-megaphone me-2"></i>Top Campaigns (<?php echo htmlspecialchars($filterCur); ?>)</div>
                    <a class="btn btn-sm btn-light border" href="revenue?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-pencil-square me-1"></i>Allocate</a>
                </div>
                <div class="card-body">
                    <div style="height: 260px;"><canvas id="chartCampaigns"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm sales-panel revenue-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Payroll Summary</div>
                    <a class="btn btn-sm btn-outline-primary" href="../hr/payroll.php"><i class="bi bi-calculator me-1"></i>Payroll</a>
                </div>
                <div class="table-responsive sales-table-responsive revenue-table-wrap">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Job Title</th>
                                <th class="text-end">Net</th>
                                <th class="text-end">Incentives</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payrollRows)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No payslips generated for this month.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payrollRows as $r): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($r['full_name']); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars($r['job_title']); ?></td>
                                        <td class="text-end"><?php echo $money($r['net'], 'INR'); ?></td>
                                        <td class="text-end"><?php echo $money($r['incentives'], 'INR'); ?></td>
                                        <td class="text-end fw-semibold"><?php echo $money($r['total'], 'INR'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="revenue-stat-note">Payroll figures are pulled from `hr_payslips`, while related action links open the connected HR, revenue, and invoice flows with the selected month preserved.</div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a class="btn btn-sm btn-light border" href="expenses.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-receipt-cutoff me-1"></i>Expenses</a>
                    <a class="btn btn-sm btn-light border" href="revenue?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-graph-up-arrow me-1"></i>Campaign Revenue</a>
                    <a class="btn btn-sm btn-light border" href="invoices?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-file-earmark-text me-1"></i>Invoices</a>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm sales-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Client-wise Revenue (Accepted Leads)</div>
                    <a class="btn btn-sm btn-outline-primary" href="revenue?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-currency-dollar me-1"></i>Open Revenue</a>
                </div>
                <div class="table-responsive sales-table-responsive revenue-table-wrap">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th class="text-end">Accepted</th>
                                <th class="text-end">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clientRows)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">No accepted (billable) leads in this month.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($clientRows, 0, 12) as $r): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['client_name'] ?? ($r['client_code'] ?? ''))); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['billable'] ?? 0)); ?></td>
                                        <td class="text-end fw-semibold"><?php echo htmlspecialchars((string)($r['currency'] ?? 'USD') . ' ' . number_format((float)($r['revenue'] ?? 0), 2)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="revenue-stat-note">Client revenue is calculated from accepted leads and campaign CPL data, then linked forward into invoice generation and expense tracking.</div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <a class="btn btn-sm btn-outline-primary" href="invoices?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-receipt me-1"></i>Create Invoice</a>
                    <a class="btn btn-sm btn-light border" href="expenses.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-receipt-cutoff me-1"></i>Add Expense</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm sales-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Agent ROI (Month)</div>
                    <a class="btn btn-sm btn-outline-primary" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-diagram-2 me-1"></i>Details</a>
                </div>
                <div class="table-responsive sales-table-responsive revenue-table-wrap">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Agent</th>
                                <th class="text-end">Accepted</th>
                                <th class="text-end">Revenue (INR)</th>
                                <th class="text-end">Cost (INR)</th>
                                <th class="text-end">ROI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($agentRoiRows)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No accepted (billable) leads in this month.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($agentRoiRows, 0, 12) as $r): ?>
                                    <?php
                                        $revInr = (float)($r['revenue_inr'] ?? 0);
                                        $costInr = (float)($r['cost_inr'] ?? 0);
                                        $roiX = $costInr > 0 ? ($revInr / $costInr) : null;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <a class="text-decoration-none" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>&agent_id=<?php echo (int)($r['agent_id'] ?? 0); ?>">
                                                <?php echo htmlspecialchars((string)($r['agent_name'] ?? '')); ?>
                                            </a>
                                        </td>
                                        <td class="text-end"><?php echo number_format((int)($r['billable'] ?? 0)); ?></td>
                                        <td class="text-end fw-semibold">₹ <?php echo number_format($revInr, 2); ?></td>
                                        <td class="text-end">₹ <?php echo number_format($costInr, 2); ?></td>
                                        <td class="text-end"><?php echo $roiX !== null ? number_format((float)$roiX, 2) . 'x' : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="revenue-stat-note">ROI uses invoice-side FX conversion settings and HR payslip cost to keep revenue and payroll comparisons aligned.</div>
                <div class="card-footer d-flex justify-content-end">
                    <a class="btn btn-sm btn-light border" href="../users/manage-users.php"><i class="bi bi-people me-1"></i>Users</a>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm sales-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Campaign-wise Revenue</div>
                    <a class="btn btn-sm btn-outline-primary" href="revenue?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-pencil-square me-1"></i>Allocate Revenue</a>
                </div>
                <div class="table-responsive sales-table-responsive revenue-table-wrap">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Campaign</th>
                                <th class="text-end">Accepted</th>
                                <th class="text-end">Generated</th>
                                <th class="text-end">Allocated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaignRows)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No campaigns.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($campaignRows, 0, 12) as $r): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['billable'] ?? 0)); ?></td>
                                        <td class="text-end fw-semibold"><?php echo htmlspecialchars((string)($r['currency'] ?? 'USD') . ' ' . number_format((float)($r['generated'] ?? 0), 2)); ?></td>
                                        <td class="text-end"><?php echo !empty($r['allocated']) ? htmlspecialchars((string)($r['allocated_currency'] ?? 'USD') . ' ' . number_format((float)($r['allocated'] ?? 0), 2)) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    const currencyLabels = <?php echo json_encode($chartCurrencyLabels, JSON_UNESCAPED_UNICODE); ?>;
    const currencyValues = <?php echo json_encode($chartCurrencyValues, JSON_UNESCAPED_UNICODE); ?>;
    const clientLabels = <?php echo json_encode($clientLabels, JSON_UNESCAPED_UNICODE); ?>;
    const clientValues = <?php echo json_encode($clientValues, JSON_UNESCAPED_UNICODE); ?>;
    const agentLabels = <?php echo json_encode($agentLabels, JSON_UNESCAPED_UNICODE); ?>;
    const agentValues = <?php echo json_encode($agentValues, JSON_UNESCAPED_UNICODE); ?>;
    const campaignLabels = <?php echo json_encode($campaignLabels, JSON_UNESCAPED_UNICODE); ?>;
    const campaignValues = <?php echo json_encode($campaignValues, JSON_UNESCAPED_UNICODE); ?>;

    function cssVar(name, fallback) {
        const v = getComputedStyle(document.body).getPropertyValue(name).trim();
        return v || fallback;
    }

    function palette(n) {
        const base = [
            '#4f46e5','#22c55e','#f59e0b','#ef4444','#06b6d4','#a855f7','#14b8a6','#f97316',
            '#3b82f6','#84cc16','#eab308','#ec4899'
        ];
        const out = [];
        for (let i = 0; i < n; i++) out.push(base[i % base.length]);
        return out;
    }

    function chartThemeOptions() {
        const text = cssVar('--app-text', '#111827');
        const muted = cssVar('--app-muted', '#6b7280');
        const border = cssVar('--app-border', 'rgba(0,0,0,0.1)');
        return { text, muted, border };
    }

    function commonOptions() {
        const t = chartThemeOptions();
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: t.text } },
                tooltip: { enabled: true }
            },
            scales: {
                x: {
                    ticks: { color: t.muted },
                    grid: { color: t.border }
                },
                y: {
                    ticks: { color: t.muted },
                    grid: { color: t.border }
                }
            }
        };
    }

    const charts = [];

    function initCharts() {
        if (!window.Chart) return;
        const t = chartThemeOptions();

        const ctxCur = document.getElementById('chartCurrency');
        if (ctxCur) {
            charts.push(new Chart(ctxCur, {
                type: 'doughnut',
                data: {
                    labels: currencyLabels,
                    datasets: [{
                        data: currencyValues,
                        backgroundColor: palette(currencyLabels.length),
                        borderColor: cssVar('--app-surface', '#fff'),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { color: t.text } } }
                }
            }));
        }

        const ctxClients = document.getElementById('chartClients');
        if (ctxClients) {
            charts.push(new Chart(ctxClients, {
                type: 'pie',
                data: {
                    labels: clientLabels,
                    datasets: [{
                        data: clientValues,
                        backgroundColor: palette(clientLabels.length),
                        borderColor: cssVar('--app-surface', '#fff'),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right', labels: { color: t.text } } }
                }
            }));
        }

        const ctxAgents = document.getElementById('chartAgents');
        if (ctxAgents) {
            charts.push(new Chart(ctxAgents, {
                type: 'bar',
                data: {
                    labels: agentLabels,
                    datasets: [{
                        label: 'Revenue',
                        data: agentValues,
                        backgroundColor: 'rgba(79, 70, 229, 0.55)',
                        borderColor: 'rgba(79, 70, 229, 1)',
                        borderWidth: 1,
                        borderRadius: 8
                    }]
                },
                options: {
                    ...commonOptions(),
                    indexAxis: 'y',
                    plugins: { legend: { display: false } }
                }
            }));
        }

        const ctxCamp = document.getElementById('chartCampaigns');
        if (ctxCamp) {
            charts.push(new Chart(ctxCamp, {
                type: 'bar',
                data: {
                    labels: campaignLabels,
                    datasets: [{
                        label: 'Generated',
                        data: campaignValues,
                        backgroundColor: 'rgba(34, 197, 94, 0.55)',
                        borderColor: 'rgba(34, 197, 94, 1)',
                        borderWidth: 1,
                        borderRadius: 8
                    }]
                },
                options: {
                    ...commonOptions(),
                    plugins: { legend: { display: false } }
                }
            }));
        }
    }

    function refreshChartsTheme() {
        const t = chartThemeOptions();
        charts.forEach(ch => {
            if (ch.options?.plugins?.legend?.labels) ch.options.plugins.legend.labels.color = t.text;
            if (ch.options?.scales?.x?.ticks) ch.options.scales.x.ticks.color = t.muted;
            if (ch.options?.scales?.y?.ticks) ch.options.scales.y.ticks.color = t.muted;
            if (ch.options?.scales?.x?.grid) ch.options.scales.x.grid.color = t.border;
            if (ch.options?.scales?.y?.grid) ch.options.scales.y.grid.color = t.border;
            ch.update();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initCharts();
    });

    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-theme-toggle]')) {
            setTimeout(refreshChartsTheme, 0);
        }
    });
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
