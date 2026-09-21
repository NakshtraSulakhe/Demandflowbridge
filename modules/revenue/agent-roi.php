<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];
$start = sprintf('%04d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));
$startDt = $start . ' 00:00:00';
$endDt = $end . ' 23:59:59';

$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$usdInr = getUsdInrRate($end) ?? getUsdInrRate(date('Y-m-d'));
$agentOptions = getAgents(['agent','operations_agent','operations_manager','operations_director']);

$payrollByUser = [];
$stmt = $conn->prepare("SELECT p.user_id, u.full_name, p.salary_data FROM hr_payslips p JOIN users u ON u.id = p.user_id WHERE p.year = ? AND p.month = ?");
if ($stmt) {
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $data = json_decode((string)($r['salary_data'] ?? ''), true);
        if (!is_array($data)) $data = [];
        $earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
        $net = (float)($data['net_salary'] ?? 0);
        $incent = (float)($earn['incentives'] ?? 0);
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid > 0) {
            $payrollByUser[$uid] = [
                'user_id' => $uid,
                'full_name' => (string)($r['full_name'] ?? ''),
                'net' => $net,
                'incentives' => $incent,
                'total' => $net,
            ];
        }
    }
}

$agentList = [];
$stmt = $conn->prepare("
    SELECT l.agent_id, COALESCE(l.agent_name, '') AS agent_name,
           COALESCE(d.cpl_currency, 'USD') AS currency,
           COUNT(l.id) AS billable,
           SUM(COALESCE(d.cpl, 0)) AS revenue
    FROM leads l
    LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
    WHERE l.client_delivery_status = 'Accepted' AND l.created_at BETWEEN ? AND ?
    GROUP BY l.agent_id, agent_name, currency
    ORDER BY revenue DESC
");
if ($stmt) {
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $agentList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$agg = [];
foreach ($agentList as $r) {
    $aid = (int)($r['agent_id'] ?? 0);
    if ($aid <= 0) continue;
    if ($agentId > 0 && $aid !== $agentId) continue;
    if (!isset($agg[$aid])) {
        $agg[$aid] = [
            'agent_id' => $aid,
            'agent_name' => (string)($r['agent_name'] ?? ''),
            'billable' => 0,
            'usd' => 0.0,
            'inr' => 0.0,
            'inr_from_usd' => 0.0,
            'revenue_inr' => 0.0,
            'cost_inr' => (float)($payrollByUser[$aid]['total'] ?? 0),
            'net_inr' => (float)($payrollByUser[$aid]['net'] ?? 0),
            'incentives_inr' => (float)($payrollByUser[$aid]['incentives'] ?? 0),
        ];
    }
    $agg[$aid]['billable'] += (int)($r['billable'] ?? 0);
    $cur = strtoupper(trim((string)($r['currency'] ?? 'USD')));
    if ($cur === '') $cur = 'USD';
    $rev = (float)($r['revenue'] ?? 0);
    if ($cur === 'USD') {
        $agg[$aid]['usd'] += $rev;
        if ($usdInr !== null) $agg[$aid]['inr_from_usd'] += ($rev * (float)$usdInr);
    } elseif ($cur === 'INR') {
        $agg[$aid]['inr'] += $rev;
    }
}
foreach ($agg as $aid => $r) {
    $agg[$aid]['revenue_inr'] = (float)$r['inr'] + (float)$r['inr_from_usd'];
}
$list = array_values($agg);
usort($list, function($a, $b) {
    $av = (float)($a['revenue_inr'] ?? 0);
    $bv = (float)($b['revenue_inr'] ?? 0);
    if ($av === $bv) return 0;
    return $av > $bv ? -1 : 1;
});

$pageTitle = 'Agent ROI';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace revenue-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-diagram-2"></i>Agent Profitability</div>
            <h3 class="sales-page-title">Agent ROI</h3>
            <div class="sales-page-subtitle">Compare accepted-lead revenue against payroll cost and incentives per agent, using the same FX rate logic and payslip data shown on the revenue dashboard.</div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="sales-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
                <span class="sales-chip"><i class="bi bi-currency-exchange"></i>USD→INR <?php echo $usdInr !== null ? number_format((float)$usdInr, 2) : '—'; ?></span>
            </div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="invoices.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-file-earmark-text me-1"></i>Invoices</a>
            <a class="btn btn-light border btn-sm" href="revenue.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-currency-dollar me-1"></i>Campaign Revenue</a>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav revenue-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="revenue.php?month=<?php echo urlencode($monthStr); ?>">Campaign Revenue</a></li>
        <li class="nav-item"><a class="nav-link" href="expenses.php?month=<?php echo urlencode($monthStr); ?>">Expenses</a></li>
        <li class="nav-item"><a class="nav-link" href="invoices.php?month=<?php echo urlencode($monthStr); ?>">Invoices</a></li>
        <li class="nav-item"><a class="nav-link" href="billto-templates.php">Bill To Templates</a></li>
        <li class="nav-item"><a class="nav-link active" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>">Agent ROI</a></li>
    </ul>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel revenue-panel mb-3">
        <div class="card-body">
            <div class="revenue-filter-shell">
                <div class="revenue-filter-meta">
                    <div>
                        <div class="revenue-section-title">ROI Filters</div>
                        <div class="revenue-section-text">Review agent profitability by month or focus on a single owner while keeping the same FX and payroll logic used by the revenue dashboard.</div>
                    </div>
                    <div class="revenue-chip-row">
                        <span class="revenue-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
                        <span class="revenue-chip"><i class="bi bi-people"></i><?php echo number_format(count($list)); ?> agent<?php echo count($list) === 1 ? '' : 's'; ?></span>
                    </div>
                </div>
                <form method="get" class="row g-3 align-items-end sales-filter-form revenue-inline-form">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Month</label>
                    <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthStr); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1">Agent</label>
                    <select class="form-select" name="agent_id">
                        <option value="">All</option>
                        <?php foreach ($agentOptions as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>" <?php echo $agentId === (int)$r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel revenue-panel">
        <div class="card-header fw-semibold">ROI Details</div>
        <div class="table-responsive sales-table-responsive revenue-table-wrap">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Agent</th>
                        <th class="text-end">Accepted</th>
                        <th class="text-end">Revenue (INR)</th>
                        <th class="text-end">Net (INR)</th>
                        <th class="text-end">Incentives (INR)</th>
                        <th class="text-end">Cost (INR)</th>
                        <th class="text-end">ROI</th>
                        <th class="text-end">Links</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No accepted (billable) leads in this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $r): ?>
                            <?php
                                $revInr = (float)($r['revenue_inr'] ?? 0);
                                $costInr = (float)($r['cost_inr'] ?? 0);
                                $roiX = $costInr > 0 ? ($revInr / $costInr) : null;
                            ?>
                            <tr>
                                <td>
                                    <div class="revenue-entity-block">
                                        <div class="revenue-entity-title"><?php echo htmlspecialchars((string)($r['agent_name'] ?? '')); ?></div>
                                        <div class="revenue-entity-subline">
                                            <span class="revenue-entity-chip">Agent</span>
                                            <span>Payroll and revenue aligned for selected month</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end"><?php echo number_format((int)($r['billable'] ?? 0)); ?></td>
                                <td class="text-end fw-semibold">₹ <?php echo number_format($revInr, 2); ?></td>
                                <td class="text-end">₹ <?php echo number_format((float)($r['net_inr'] ?? 0), 2); ?></td>
                                <td class="text-end">₹ <?php echo number_format((float)($r['incentives_inr'] ?? 0), 2); ?></td>
                                <td class="text-end">₹ <?php echo number_format($costInr, 2); ?></td>
                                <td class="text-end">
                                    <?php if ($roiX !== null): ?>
                                        <span class="revenue-status-badge <?php echo $roiX >= 1 ? 'is-paid' : 'is-sent'; ?>"><span class="revenue-status-dot"></span><?php echo number_format((float)$roiX, 2); ?>x</span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="revenue-pill-btn" href="../hr/payslip-view.php?month=<?php echo urlencode($monthStr); ?>&user_id=<?php echo (int)$r['agent_id']; ?>" title="Payslip"><i class="bi bi-receipt"></i><span>Payslip</span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="revenue-stat-note">ROI data is connected to `hr_payslips` for cost and accepted lead revenue for output, so this view stays aligned with both HR and campaign delivery modules.</div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
