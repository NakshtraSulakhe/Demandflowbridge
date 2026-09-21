<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','qa','qa_agent','qa_manager','qa_director']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/../qa/action.php';
    exit;
}
header('Location: ../qa/audit');
exit;

$overviewSql = "
    SELECT
        -- total leads (no form_done filter)
        COUNT(*) AS total_generated,

        -- QA totals (unchanged)
        SUM(CASE WHEN qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS total_reviewed,
        SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS total_pass,
        SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS total_fail,
        SUM(CASE WHEN qa_status IS NULL OR qa_status = 'Pending' THEN 1 ELSE 0 END) AS total_pending,

        -- today totals (generated = all leads created today)
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_generated,
        SUM(CASE WHEN DATE(created_at) = CURDATE() AND qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS today_reviewed,
        SUM(CASE WHEN DATE(created_at) = CURDATE() AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS today_pass,
        SUM(CASE WHEN DATE(created_at) = CURDATE() AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS today_fail,
        SUM(CASE WHEN DATE(created_at) = CURDATE() AND (qa_status IS NULL OR qa_status = 'Pending') THEN 1 ELSE 0 END) AS today_pending,

        -- month totals (generated = all leads in current month)
        SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_generated,
        SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS month_reviewed,
        SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS month_pass,
        SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS month_fail,
        SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND (qa_status IS NULL OR qa_status = 'Pending') THEN 1 ELSE 0 END) AS month_pending
    FROM leads
    {$whereScope}
";
$overviewRes = $scopeTypes !== '' ? (function() use ($conn, $overviewSql, $scopeTypes, $scopeParams) {
    $stmt = $conn->prepare($overviewSql);
    if (!$stmt) return false;
    $stmt->bind_param($scopeTypes, ...$scopeParams);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
})() : $conn->query($overviewSql);
$qaOverview = [
    'total_generated' => 0, 'total_reviewed' => 0, 'total_pass' => 0, 'total_fail' => 0, 'total_pending' => 0,
    'today_generated' => 0, 'today_reviewed' => 0, 'today_pass' => 0, 'today_fail' => 0, 'today_pending' => 0,
    'month_generated' => 0, 'month_reviewed' => 0, 'month_pass' => 0, 'month_fail' => 0, 'month_pending' => 0,
];

if ($overviewRes) {
    $qaOverview = $overviewRes->fetch_assoc();
}
function pct($part, $whole) { return $whole > 0 ? round(($part * 100.0) / $whole, 1) : 0; }
$todayPassPct = pct((int)$qaOverview['today_pass'], (int)$qaOverview['today_reviewed']);
$todayFailPct = pct((int)$qaOverview['today_fail'], (int)$qaOverview['today_reviewed']);
$monthPassPct = pct((int)$qaOverview['month_pass'], (int)$qaOverview['month_reviewed']);
$monthFailPct = pct((int)$qaOverview['month_fail'], (int)$qaOverview['month_reviewed']);
$totalPassPct = pct((int)$qaOverview['total_pass'], (int)$qaOverview['total_reviewed']);
$totalFailPct = pct((int)$qaOverview['total_fail'], (int)$qaOverview['total_reviewed']);
$todayStr = date('Y-m-d');
$monthStartStr = date('Y-m-01');
?>
<?php $pageTitle = 'QA Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
    .recording-player { width: 100%; margin-bottom: 15px; }
    .quality-badge { font-size: 0.8rem; padding: 0.25rem 0.5rem; }
</style>

    <div class="container-fluid px-0">
        <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
            <div class="alert alert-success alert-dismissible fade show">
                Lead quality status updated successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">QA Dashboard</h4>
            </div>
            <div class="card-body">
                <!-- Analytics Section: Today / Current Month / Total -->
                <div class="row mb-4">
                    <!-- Today -->
                    <div class="col-md-4">
                        <a href="leads-edit.php?date_from=<?php echo $todayStr; ?>&date_to=<?php echo $todayStr; ?>" class="text-decoration-none">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Today</h6>
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <div class="small text-muted">Generated</div>
                                            <div class="fs-4 fw-bold"><?php echo number_format($qaOverview['today_generated']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-success">Qualified</div>
                                            <div class="fs-5 fw-semibold text-success"><?php echo number_format($qaOverview['today_pass']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-danger">Disqualified</div>
                                            <div class="fs-5 fw-semibold text-danger"><?php echo number_format($qaOverview['today_fail']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-warning">Pending</div>
                                            <div class="fs-5 fw-semibold text-warning"><?php echo number_format($qaOverview['today_pending']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <!-- Current Month -->
                    <div class="col-md-4">
                        <a href="../leads/leads-edit.php?date_from=<?php echo $monthStartStr; ?>&date_to=<?php echo $todayStr; ?>" class="text-decoration-none">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Current Month</h6>
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <div class="small text-muted">Generated</div>
                                            <div class="fs-4 fw-bold"><?php echo number_format($qaOverview['month_generated']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-success">Qualified</div>
                                            <div class="fs-5 fw-semibold text-success"><?php echo number_format($qaOverview['month_pass']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-danger">Disqualified</div>
                                            <div class="fs-5 fw-semibold text-danger"><?php echo number_format($qaOverview['month_fail']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-warning">Pending</div>
                                            <div class="fs-5 fw-semibold text-warning"><?php echo number_format($qaOverview['month_pending']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <!-- Total -->
                    <div class="col-md-4">
                        <a href="../leads/leads-edit.php" class="text-decoration-none">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Total</h6>
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <div class="small text-muted">Generated</div>
                                            <div class="fs-4 fw-bold"><?php echo number_format($qaOverview['total_generated']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-success">Qualified</div>
                                            <div class="fs-5 fw-semibold text-success"><?php echo number_format($qaOverview['total_pass']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-danger">Disqualified</div>
                                            <div class="fs-5 fw-semibold text-danger"><?php echo number_format($qaOverview['total_fail']); ?></div>
                                        </div>
                                        <div>
                                            <div class="small text-warning">Pending</div>
                                            <div class="fs-5 fw-semibold text-warning"><?php echo number_format($qaOverview['total_pending']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
                
                <!-- Performance Tabs (Agent-wise and Campaign-wise) -->
                <?php 
                    // Build agent-wise performance tables for Today, Current Month, and Total (all agents)
                    $agentPerfToday = [];
                    $agentPerfMonth = [];
                    $agentPerfTotal = [];
                    $connAgents = getDbConnection();
                    // Today
                    $sqlAgentToday = "SELECT u.id, u.full_name as name,
                                             COUNT(l.id) as total,
                                             SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) as qualified,
                                             SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) as disqualified,
                                             SUM(CASE WHEN l.qa_status='Pending' THEN 1 ELSE 0 END) as pending,
                                             SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) as filled
                                      FROM users u
                                      LEFT JOIN leads l ON l.agent_id = u.id AND DATE(l.created_at) = CURDATE()
                                      WHERE u.role='agent' AND u.is_active = 1
                                      GROUP BY u.id, u.full_name
                                      ORDER BY u.full_name";
                    if ($res = $connAgents->query($sqlAgentToday)) {
                        while ($r = $res->fetch_assoc()) { $agentPerfToday[] = $r; }
                    }
                    // Current Month
                    $sqlAgentMonth = "SELECT u.id, u.full_name as name,
                                             COUNT(l.id) as total,
                                             SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) as qualified,
                                             SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) as disqualified,
                                             SUM(CASE WHEN l.qa_status='Pending' THEN 1 ELSE 0 END) as pending,
                                             SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) as filled
                                      FROM users u
                                      LEFT JOIN leads l ON l.agent_id = u.id AND YEAR(l.created_at) = YEAR(CURDATE()) AND MONTH(l.created_at) = MONTH(CURDATE())
                                      WHERE u.role='agent' AND u.is_active = 1
                                      GROUP BY u.id, u.full_name
                                      ORDER BY u.full_name";
                    if ($res = $connAgents->query($sqlAgentMonth)) {
                        while ($r = $res->fetch_assoc()) { $agentPerfMonth[] = $r; }
                    }
                    // Total
                    $sqlAgentTotal = "SELECT u.id, u.full_name as name,
                                             COUNT(l.id) as total,
                                             SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) as qualified,
                                             SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) as disqualified,
                                             SUM(CASE WHEN l.qa_status='Pending' THEN 1 ELSE 0 END) as pending,
                                             SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) as filled
                                      FROM users u
                                      LEFT JOIN leads l ON l.agent_id = u.id
                                      WHERE u.role='agent' AND u.is_active = 1
                                      GROUP BY u.id, u.full_name
                                      ORDER BY u.full_name";
                    if ($res = $connAgents->query($sqlAgentTotal)) {
                        while ($r = $res->fetch_assoc()) { $agentPerfTotal[] = $r; }
                    }

                    // Build campaign-wise performance tables for Today, Current Month, and Total (all campaigns)
                    $campPerfToday = [];
                    $campPerfMonth = [];
                    $campPerfTotal = [];
                    $connCamps = getDbConnection();
                    // Today
                    $sqlCampToday = "SELECT c.id, c.name,
                                          COUNT(l.id) as total,
                                          SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) as qualified,
                                          SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) as disqualified,
                                          SUM(CASE WHEN l.qa_status='Pending' THEN 1 ELSE 0 END) as pending,
                                          SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) as filled
                                   FROM campaigns c
                                   LEFT JOIN leads l ON l.campaign_id = c.id AND DATE(l.created_at) = CURDATE()
                                   WHERE c.active = 1
                                   GROUP BY c.id, c.name
                                   ORDER BY c.name";
                    if ($res = $connCamps->query($sqlCampToday)) {
                        while ($r = $res->fetch_assoc()) { $campPerfToday[] = $r; }
                    }
                    // Current Month
                    $sqlCampMonth = "SELECT c.id, c.name,
                                          COUNT(l.id) as total,
                                          SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) as qualified,
                                          SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) as disqualified,
                                          SUM(CASE WHEN l.qa_status='Pending' THEN 1 ELSE 0 END) as pending,
                                          SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) as filled
                                   FROM campaigns c
                                   LEFT JOIN leads l ON l.campaign_id = c.id AND YEAR(l.created_at) = YEAR(CURDATE()) AND MONTH(l.created_at) = MONTH(CURDATE())
                                   WHERE c.active = 1
                                   GROUP BY c.id, c.name
                                   ORDER BY c.name";
                    if ($res = $connCamps->query($sqlCampMonth)) {
                        while ($r = $res->fetch_assoc()) { $campPerfMonth[] = $r; }
                    }
                    // Total
                    $sqlCampTotal = "SELECT c.id, c.name,
                                          COUNT(l.id) as total,
                                          SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) as qualified,
                                          SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) as disqualified,
                                          SUM(CASE WHEN l.qa_status='Pending' THEN 1 ELSE 0 END) as pending,
                                          SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) as filled
                                   FROM campaigns c
                                   LEFT JOIN leads l ON l.campaign_id = c.id
                                   WHERE c.active = 1
                                   GROUP BY c.id, c.name
                                   ORDER BY c.name";
                    if ($res = $connCamps->query($sqlCampTotal)) {
                        while ($r = $res->fetch_assoc()) { $campPerfTotal[] = $r; }
                    }
                    // Pre-compute totals for agent tables
                    $agentTodayTotals = ['total' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0, 'filled' => 0];
                    foreach ($agentPerfToday as $r) {
                        $agentTodayTotals['total'] += (int)($r['total'] ?? 0);
                        $agentTodayTotals['qualified'] += (int)($r['qualified'] ?? 0);
                        $agentTodayTotals['disqualified'] += (int)($r['disqualified'] ?? 0);
                        $agentTodayTotals['pending'] += (int)($r['pending'] ?? 0);
                        $agentTodayTotals['filled'] += (int)($r['filled'] ?? 0);
                    }
                    $agentMonthTotals = ['total' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0, 'filled' => 0];
                    foreach ($agentPerfMonth as $r) {
                        $agentMonthTotals['total'] += (int)($r['total'] ?? 0);
                        $agentMonthTotals['qualified'] += (int)($r['qualified'] ?? 0);
                        $agentMonthTotals['disqualified'] += (int)($r['disqualified'] ?? 0);
                        $agentMonthTotals['pending'] += (int)($r['pending'] ?? 0);
                        $agentMonthTotals['filled'] += (int)($r['filled'] ?? 0);
                    }
                    $agentTotalTotals = ['total' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0, 'filled' => 0];
                    foreach ($agentPerfTotal as $r) {
                        $agentTotalTotals['total'] += (int)($r['total'] ?? 0);
                        $agentTotalTotals['qualified'] += (int)($r['qualified'] ?? 0);
                        $agentTotalTotals['disqualified'] += (int)($r['disqualified'] ?? 0);
                        $agentTotalTotals['pending'] += (int)($r['pending'] ?? 0);
                        $agentTotalTotals['filled'] += (int)($r['filled'] ?? 0);
                    }

                    // Pre-compute totals for campaign tables
                    $campTodayTotals = ['total' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0, 'filled' => 0];
                    foreach ($campPerfToday as $r) {
                        $campTodayTotals['total'] += (int)($r['total'] ?? 0);
                        $campTodayTotals['qualified'] += (int)($r['qualified'] ?? 0);
                        $campTodayTotals['disqualified'] += (int)($r['disqualified'] ?? 0);
                        $campTodayTotals['pending'] += (int)($r['pending'] ?? 0);
                        $campTodayTotals['filled'] += (int)($r['filled'] ?? 0);
                    }
                    $campMonthTotals = ['total' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0, 'filled' => 0];
                    foreach ($campPerfMonth as $r) {
                        $campMonthTotals['total'] += (int)($r['total'] ?? 0);
                        $campMonthTotals['qualified'] += (int)($r['qualified'] ?? 0);
                        $campMonthTotals['disqualified'] += (int)($r['disqualified'] ?? 0);
                        $campMonthTotals['pending'] += (int)($r['pending'] ?? 0);
                        $campMonthTotals['filled'] += (int)($r['filled'] ?? 0);
                    }
                    $campTotalTotals = ['total' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0, 'filled' => 0];
                    foreach ($campPerfTotal as $r) {
                        $campTotalTotals['total'] += (int)($r['total'] ?? 0);
                        $campTotalTotals['qualified'] += (int)($r['qualified'] ?? 0);
                        $campTotalTotals['disqualified'] += (int)($r['disqualified'] ?? 0);
                        $campTotalTotals['pending'] += (int)($r['pending'] ?? 0);
                        $campTotalTotals['filled'] += (int)($r['filled'] ?? 0);
                    }
                ?>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-light">
                                <ul class="nav nav-tabs card-header-tabs" id="agentPerfTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="agent-today-tab" data-bs-toggle="tab" data-bs-target="#agent-today" type="button" role="tab">Today</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="agent-month-tab" data-bs-toggle="tab" data-bs-target="#agent-month" type="button" role="tab">Current Month</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="agent-total-tab" data-bs-toggle="tab" data-bs-target="#agent-total" type="button" role="tab">Total</button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="agentPerfTabContent">
                                    <div class="tab-pane fade show active" id="agent-today" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Agent</th><th>Total</th><th>Qualified</th><th>Disqualified</th><th>Pending</th><th>Form Filled</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($agentPerfToday as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo number_format($row['total']); ?></td>
                                                            <td><?php echo number_format($row['qualified']); ?></td>
                                                            <td><?php echo number_format($row['disqualified']); ?></td>
                                                            <td><?php echo number_format($row['pending']); ?></td>
                                                            <td><?php echo number_format($row['filled']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($agentPerfToday)): ?>
                                                        <tr><td colspan="6" class="text-center">No data</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-secondary">
                                                        <th>Total</th>
                                                        <th><?php echo number_format($agentTodayTotals['total']); ?></th>
                                                        <th><?php echo number_format($agentTodayTotals['qualified']); ?></th>
                                                        <th><?php echo number_format($agentTodayTotals['disqualified']); ?></th>
                                                        <th><?php echo number_format($agentTodayTotals['pending']); ?></th>
                                                        <th><?php echo number_format($agentTodayTotals['filled']); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="agent-month" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Agent</th><th>Total</th><th>Qualified</th><th>Disqualified</th><th>Pending</th><th>Form Filled</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($agentPerfMonth as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo number_format($row['total']); ?></td>
                                                            <td><?php echo number_format($row['qualified']); ?></td>
                                                            <td><?php echo number_format($row['disqualified']); ?></td>
                                                            <td><?php echo number_format($row['pending']); ?></td>
                                                            <td><?php echo number_format($row['filled']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($agentPerfMonth)): ?>
                                                        <tr><td colspan="6" class="text-center">No data</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-secondary">
                                                        <th>Total</th>
                                                        <th><?php echo number_format($agentMonthTotals['total']); ?></th>
                                                        <th><?php echo number_format($agentMonthTotals['qualified']); ?></th>
                                                        <th><?php echo number_format($agentMonthTotals['disqualified']); ?></th>
                                                        <th><?php echo number_format($agentMonthTotals['pending']); ?></th>
                                                        <th><?php echo number_format($agentMonthTotals['filled']); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="agent-total" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Agent</th><th>Total</th><th>Qualified</th><th>Disqualified</th><th>Pending</th><th>Form Filled</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($agentPerfTotal as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo number_format($row['total']); ?></td>
                                                            <td><?php echo number_format($row['qualified']); ?></td>
                                                            <td><?php echo number_format($row['disqualified']); ?></td>
                                                            <td><?php echo number_format($row['pending']); ?></td>
                                                            <td><?php echo number_format($row['filled']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($agentPerfTotal)): ?>
                                                        <tr><td colspan="6" class="text-center">No data</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-secondary">
                                                        <th>Total</th>
                                                        <th><?php echo number_format($agentTotalTotals['total']); ?></th>
                                                        <th><?php echo number_format($agentTotalTotals['qualified']); ?></th>
                                                        <th><?php echo number_format($agentTotalTotals['disqualified']); ?></th>
                                                        <th><?php echo number_format($agentTotalTotals['pending']); ?></th>
                                                        <th><?php echo number_format($agentTotalTotals['filled']); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-light">
                                <ul class="nav nav-tabs card-header-tabs" id="campaignPerfTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="campaign-today-tab" data-bs-toggle="tab" data-bs-target="#campaign-today" type="button" role="tab">Today</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="campaign-month-tab" data-bs-toggle="tab" data-bs-target="#campaign-month" type="button" role="tab">Current Month</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="campaign-total-tab" data-bs-toggle="tab" data-bs-target="#campaign-total" type="button" role="tab">Total</button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="campaignPerfTabContent">
                                    <div class="tab-pane fade show active" id="campaign-today" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Campaign</th><th>Total</th><th>Qualified</th><th>Disqualified</th><th>Pending</th><th>Form Filled</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($campPerfToday as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo number_format($row['total']); ?></td>
                                                            <td><?php echo number_format($row['qualified']); ?></td>
                                                            <td><?php echo number_format($row['disqualified']); ?></td>
                                                            <td><?php echo number_format($row['pending']); ?></td>
                                                            <td><?php echo number_format($row['filled']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($campPerfToday)): ?>
                                                        <tr><td colspan="6" class="text-center">No data</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-secondary">
                                                        <th>Total</th>
                                                        <th><?php echo number_format($campTodayTotals['total']); ?></th>
                                                        <th><?php echo number_format($campTodayTotals['qualified']); ?></th>
                                                        <th><?php echo number_format($campTodayTotals['disqualified']); ?></th>
                                                        <th><?php echo number_format($campTodayTotals['pending']); ?></th>
                                                        <th><?php echo number_format($campTodayTotals['filled']); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="campaign-month" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Campaign</th><th>Total</th><th>Qualified</th><th>Disqualified</th><th>Pending</th><th>Form Filled</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($campPerfMonth as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo number_format($row['total']); ?></td>
                                                            <td><?php echo number_format($row['qualified']); ?></td>
                                                            <td><?php echo number_format($row['disqualified']); ?></td>
                                                            <td><?php echo number_format($row['pending']); ?></td>
                                                            <td><?php echo number_format($row['filled']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($campPerfMonth)): ?>
                                                        <tr><td colspan="6" class="text-center">No data</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-secondary">
                                                        <th>Total</th>
                                                        <th><?php echo number_format($campMonthTotals['total']); ?></th>
                                                        <th><?php echo number_format($campMonthTotals['qualified']); ?></th>
                                                        <th><?php echo number_format($campMonthTotals['disqualified']); ?></th>
                                                        <th><?php echo number_format($campMonthTotals['pending']); ?></th>
                                                        <th><?php echo number_format($campMonthTotals['filled']); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="campaign-total" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Campaign</th><th>Total</th><th>Qualified</th><th>Disqualified</th><th>Pending</th><th>Form Filled</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($campPerfTotal as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo number_format($row['total']); ?></td>
                                                            <td><?php echo number_format($row['qualified']); ?></td>
                                                            <td><?php echo number_format($row['disqualified']); ?></td>
                                                            <td><?php echo number_format($row['pending']); ?></td>
                                                            <td><?php echo number_format($row['filled']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($campPerfTotal)): ?>
                                                        <tr><td colspan="6" class="text-center">No data</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-secondary">
                                                        <th>Total</th>
                                                        <th><?php echo number_format($campTotalTotals['total']); ?></th>
                                                        <th><?php echo number_format($campTotalTotals['qualified']); ?></th>
                                                        <th><?php echo number_format($campTotalTotals['disqualified']); ?></th>
                                                        <th><?php echo number_format($campTotalTotals['pending']); ?></th>
                                                        <th><?php echo number_format($campTotalTotals['filled']); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php
                    // Prepare pending list (top 10) for dashboard
                    $pendingLeadsData = getLeads(['qa_status' => 'Pending', 'form_done' => 'Yes'], 10, 1);
                    $pendingLeads = $pendingLeadsData['leads'];
                ?>

                <?php
                    // Earnings (QA) — show personal incentives if applicable
                    try {
                        $now = new DateTime();
                        $curYear = (int)$now->format('Y');
                        $curMonth = (int)$now->format('m');
                        $prev = (clone $now)->modify('first day of last month');
                        $prevYear = (int)$prev->format('Y');
                        $prevMonth = (int)$prev->format('m');

                        $curStats = getAgentMonthlyStats($user['id'], $curYear, $curMonth);
                        $prevStats = getAgentMonthlyStats($user['id'], $prevYear, $prevMonth);

                        $curDaily = $curStats['incentives']['daily_total'] ?? 0;
                        $curMonthly = $curStats['incentives']['monthly_bonus'] ?? 0;
                        $curTotal = $curStats['incentives']['total'] ?? ($curDaily + $curMonthly);
                        $prevTotal = $prevStats['incentives']['total'] ?? (($prevStats['incentives']['daily_total'] ?? 0) + ($prevStats['incentives']['monthly_bonus'] ?? 0));
                    } catch (Throwable $e) {
                        $curDaily = $curMonthly = $curTotal = $prevTotal = 0;
                    }
                ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-cash-coin"></i> Earnings</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="p-3 border rounded h-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="text-muted">Current Month Total</span>
                                        <i class="bi bi-coin text-success"></i>
                                    </div>
                                    <div class="mt-2 fs-4 fw-bold">Rs. <?php echo number_format($curTotal); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 border rounded h-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="text-muted">Daily Incentives</span>
                                        <i class="bi bi-lightning-charge text-warning"></i>
                                    </div>
                                    <div class="mt-2 fs-4 fw-bold">Rs. <?php echo number_format($curDaily); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 border rounded h-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="text-muted">Monthly Bonus</span>
                                        <i class="bi bi-award text-primary"></i>
                                    </div>
                                    <div class="mt-2 fs-4 fw-bold">Rs. <?php echo number_format($curMonthly); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 border rounded h-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="text-muted">Previous Month Total</span>
                                        <i class="bi bi-calendar3 text-secondary"></i>
                                    </div>
                                    <div class="mt-2 fs-4 fw-bold">Rs. <?php echo number_format($prevTotal); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                    <div class="col-md-2">
                        <a href="../leads/leads-edit.php?qa_status=Rectified" class="text-decoration-none">
                            <div class="card text-center">
                                <div class="card-body">
                                    <div class="fs-3"><i class="bi bi-wrench-adjustable text-info"></i></div>
                                    <div class="fw-semibold">Rectified</div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2">
                        <a href="../leads/leads-edit.php?qa_status=Rework%20Needed" class="text-decoration-none">
                            <div class="card text-center">
                                <div class="card-body">
                                    <div class="fs-3"><i class="bi bi-clipboard-check text-primary"></i></div>
                                    <div class="fw-semibold">Rework Needed</div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2">
                        <a href="../leads/leads-edit.php?qa_status=Duplicate" class="text-decoration-none">
                            <div class="card text-center">
                                <div class="card-body">
                                    <div class="fs-3"><i class="bi bi-files text-dark"></i></div>
                                    <div class="fw-semibold">Duplicate</div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Pending QA Assessment -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Pending QA Assessment</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Lead ID</th>
                                        <th>Date</th>
                                        <th>Campaign</th>
                                        <th>Agent</th>
                                        <th>Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pendingLeads)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No pending items</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($pendingLeads as $p): ?>
                                            <?php
                                                $pName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                                                $pDate = !empty($p['created_at']) ? date('Y-m-d', strtotime($p['created_at'])) : '';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($p['lead_id'] ?? ''); ?></td>
                                                <td><?php echo $pDate; ?></td>
                                                <td><?php echo htmlspecialchars($p['campaign_name'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($p['agent_name'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($pName); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-info view-lead-details"
                                                        data-id="<?php echo (int)($p['id'] ?? 0); ?>"
                                                        data-lead-code="<?php echo htmlspecialchars($p['lead_id'] ?? ''); ?>"
                                                        data-lead-name="<?php echo htmlspecialchars($pName); ?>">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <a class="btn btn-sm btn-primary" href="../leads/leads-edit.php?qa_status=Pending&form_filled=Yes">
                                                        <i class="bi bi-list-check"></i> Open Audit
                                                    </a>
                                                </td>
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
    </div>
    
    <!-- Recording Modal -->
    <div class="modal fade" id="recordingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Recording Player</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6 id="recordingLeadName"></h6>
                    <audio id="audioPlayer" class="recording-player" controls>
                        Your browser does not support the audio element.
                    </audio>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Review Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6 id="reviewLeadName"></h6>
                    
                    <div id="reviewRecordingContainer" class="mb-3">
                        <label class="form-label">Recording</label>
                        <audio id="reviewAudioPlayer" class="recording-player" controls>
                            Your browser does not support the audio element.
                        </audio>
                        <div class="mt-2">
                            <a id="reviewDownloadLink" class="btn btn-sm btn-outline-secondary" href="#" download>Download Recording</a>
                        </div>
                    </div>
                    
                    <form id="reviewForm" method="post" action="qa.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" id="lead_id" name="lead_id">
                        
                        <div class="mb-3">
                            <label for="qa_status" class="form-label">QA Status</label>
                            <select class="form-select" id="review_qa_status" name="qa_status" required>
                                <option value="Pending">Pending</option>
                                <option value="Qualified">Qualified</option>
                                <option value="Disqualified">Disqualified</option>
                                <option value="Rectified">Rectified</option>
                                <option value="Rework Needed">Rework Needed</option>
                                <option value="Duplicate">Duplicate</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="qa_comment" class="form-label">Comments</label>
                            <textarea class="form-control" id="qa_comment" name="qa_comment" rows="4"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitReview">Save Review</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Lead Details Modal -->
    <div class="modal fade" id="leadDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Lead Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="leadDetailsBody">
                    <div class="text-center text-muted">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle recording modal
        document.addEventListener('DOMContentLoaded', function() {
            // Lead Details modal logic
            const leadDetailsModalEl = document.getElementById('leadDetailsModal');
            const leadDetailsBody = document.getElementById('leadDetailsBody');
            const leadDetailsModal = new bootstrap.Modal(leadDetailsModalEl);

            document.addEventListener('click', function(ev) {
                const btn = ev.target.closest('.view-lead-details');
                if (!btn) return;
                ev.preventDefault();
                const id = btn.getAttribute('data-id');
                const code = btn.getAttribute('data-lead-code');
                let url = 'get_lead_details.php?';
                if (id && id !== '0') {
                    url += 'id=' + encodeURIComponent(id);
                } else if (code) {
                    url += 'lead_id=' + encodeURIComponent(code);
                } else {
                    return;
                }

                leadDetailsBody.innerHTML = '<div class="text-center text-muted">Loading...</div>';
                leadDetailsModal.show();

                fetch(url, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            leadDetailsBody.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error) + '</div>';
                            return;
                        }
                        const badgeClass = (data.qa_status === 'Qualified') ? 'success' : ((data.qa_status === 'Disqualified') ? 'danger' : 'warning');
                        const fullName = [data.first_name || '', data.last_name || ''].join(' ').trim();
                        function fmt(dt){ return dt ? new Date(dt.replace(' ', 'T')).toLocaleString() : '—'; }
                        const campaignDisplay = data.campaign_name || (data.campaign_id ? ('#' + data.campaign_id) : '');
                        const recHtml = data.recording_path ? (`<hr><h6>Recording</h6><audio controls src="${escapeHtml(data.recording_path)}" class="w-100"></audio>`) : '';
                        leadDetailsBody.innerHTML = `
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Lead ID:</strong> ${escapeHtml(data.lead_id || String(data.id || ''))}</p>
                                    <p><strong>Campaign:</strong> ${escapeHtml(campaignDisplay)}</p>
                                    <p><strong>Agent:</strong> ${escapeHtml(data.agent_name || '')}</p>
                                    <p><strong>Name:</strong> ${escapeHtml(fullName)}</p>
                                    <p><strong>Email:</strong> ${escapeHtml(data.email || '')}</p>
                                    <p><strong>Phone:</strong> ${escapeHtml(data.phone || '')}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Created At:</strong> ${fmt(data.created_at)}</p>
                                    <p><strong>Form Filled Time:</strong> ${fmt(data.form_filled_time)}</p>
                                    <p><strong>QA Updated At:</strong> ${fmt(data.qa_updated_at)}</p>
                                    <p><strong>Reviewed By:</strong> ${escapeHtml(data.reviewer_name || '')}</p>
                                    <p><strong>Form Status:</strong> ${escapeHtml(data.form_done || '')}</p>
                                </div>
                            </div>
                            <hr>
                            <p><strong>QA Status:</strong> <span class="badge bg-${badgeClass}">${escapeHtml(data.qa_status || 'Pending')}</span></p>
                            <p><strong>QA Comment:</strong> ${escapeHtml(data.quality_comments || '')}</p>
                            ${recHtml}
                        `;
                    })
                    .catch(() => {
                        leadDetailsBody.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>';
                    });
            });

            function escapeHtml(str){
                if (str == null) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/\'/g, '&#39;');
            }

            const recordingModal = document.getElementById('recordingModal');
            recordingModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const recordingPath = button.getAttribute('data-recording');
                const leadName = button.getAttribute('data-lead-name');
                
                document.getElementById('recordingLeadName').textContent = 'Lead: ' + leadName;
                document.getElementById('audioPlayer').src = recordingPath;
            });
            
            recordingModal.addEventListener('hidden.bs.modal', function() {
                document.getElementById('audioPlayer').pause();
            });
            
            // Handle review modal
            const reviewModal = document.getElementById('reviewModal');
            reviewModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const leadId = button.getAttribute('data-lead-id');
                const leadName = button.getAttribute('data-lead-name');
                const qaStatus = button.getAttribute('data-qa-status');
                const qaComment = button.getAttribute('data-qa-comment');
                const recordingPath = button.getAttribute('data-recording');
                
                document.getElementById('reviewLeadName').textContent = 'Lead: ' + leadName;
                document.getElementById('lead_id').value = leadId;
                document.getElementById('review_qa_status').value = qaStatus;
                document.getElementById('qa_comment').value = qaComment;
                
                const reviewRecordingContainer = document.getElementById('reviewRecordingContainer');
                const reviewAudioPlayer = document.getElementById('reviewAudioPlayer');
                const reviewDownloadLink = document.getElementById('reviewDownloadLink');
                
                if (recordingPath) {
                    reviewRecordingContainer.style.display = 'block';
                    reviewAudioPlayer.src = recordingPath;
                    reviewDownloadLink.href = recordingPath;
                    reviewDownloadLink.style.display = 'inline-block';
                } else {
                    reviewRecordingContainer.style.display = 'none';
                    reviewAudioPlayer.removeAttribute('src');
                    reviewDownloadLink.removeAttribute('href');
                    reviewDownloadLink.style.display = 'none';
                }
            });
            
            reviewModal.addEventListener('hidden.bs.modal', function() {
                document.getElementById('reviewAudioPlayer').pause();
            });
            
            // Submit review form
            document.getElementById('submitReview').addEventListener('click', function() {
                document.getElementById('reviewForm').submit();
            });
        });
    </script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
