<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin', 'client_admin', 'client_sdr']);

$user = getCurrentUser();
$clientId = (int)($user['client_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');

if ($clientId <= 0 && !isAdmin()) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    $_SESSION['access_denied_context'] = [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'required_roles' => ['admin','client_admin','client_sdr'],
        'time' => time(),
    ];
    header("Location: " . appBasePath() . "/modules/auth/access-denied");
    exit;
}

$conn = getDbConnection();
$opsMonth = function_exists('opsMonthRange') ? opsMonthRange() : ['start' => '1970-01-01 00:00:00', 'end' => '2999-12-31 23:59:59'];
$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($opsMonth['start'] ?? '1970-01-01 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($opsMonth['end'] ?? '2999-12-31 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('') : 'created_at';
$campaignIds = getClientCampaignIdsForClient($clientId);

// Get active campaigns for this client
$campaigns = [];
if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $types = str_repeat('i', count($campaignIds));
    $sql = "SELECT c.id, c.name, d.client_code, d.status, d.total_leads AS target_leads, d.start_date, d.end_date
            FROM campaigns c
            JOIN campaign_details d ON c.id = d.campaign_id
            WHERE c.id IN ($in)
            ORDER BY c.name";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        mysqliBindParams($stmt, $types, $campaignIds);
        $stmt->execute();
        $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

// Get stats per campaign
$stats = [];
$totalDelivered = 0;
$totalAllocation = 0;

foreach ($campaigns as $c) {
    $cid = (int)$c['id'];
    
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) as delivered
        FROM leads 
        WHERE campaign_id = ? AND $leadCreatedExpr BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $cid, $rangeStart, $rangeEnd);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['delivered'=>0];
        $stmt->close();
    } else {
        $row = ['delivered'=>0];
    }
    
    $stats[$cid] = $row;
    $totalDelivered += (int)($row['delivered'] ?? 0);
    $totalAllocation += (int)($c['target_leads'] ?? 0);
}

// Recent activities (comments/updates by client users)
$activities = [];
if (!empty($campaigns)) {
    $cids = array_map(fn($c) => (int)$c['id'], $campaigns);
    $in = implode(',', array_fill(0, count($cids), '?'));
    $types = str_repeat('i', count($cids)) . 'ss';
    $sqlAct = "
        SELECT la.*, u.full_name AS user_name, c.name AS campaign_name, l.company_name, l.lead_id AS lead_code
        FROM lead_activity la
        JOIN leads l ON la.lead_id = l.id
        JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON la.actor_id = u.id
        WHERE l.campaign_id IN ($in)
          AND l.client_delivery_status = 'Delivered'
          AND la.created_at BETWEEN ? AND ?
        ORDER BY la.created_at DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($sqlAct);
    if ($stmt) {
        $params = array_merge($cids, [$rangeStart, $rangeEnd]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

// SDR Performance
$sdrPerformance = [];
if ($userRole === 'client_admin' && !empty($campaigns)) {
    $cids = array_map(fn($c) => (int)$c['id'], $campaigns);
    $in = implode(',', array_fill(0, count($cids), '?'));
    $types = str_repeat('i', count($cids)) . 'ss';
    $sqlSdr = "
        SELECT u.id, u.full_name, COUNT(la.id) AS activity_count
        FROM users u
        JOIN lead_activity la ON u.id = la.actor_id
        JOIN leads l ON la.lead_id = l.id
        WHERE u.role = 'client_sdr' AND l.campaign_id IN ($in) AND l.client_delivery_status = 'Delivered' AND la.created_at BETWEEN ? AND ?
        GROUP BY u.id, u.full_name
        ORDER BY activity_count DESC
    ";
    $stmt = $conn->prepare($sqlSdr);
    if ($stmt) {
        $params = array_merge($cids, [$rangeStart, $rangeEnd]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $sdrPerformance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$pageTitle = 'Client Dashboard';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 dashboard-lite-workspace">
    <div class="dashboard-lite-header dashboard-lite-hero mb-3">
        <div>
            <h2 class="h3 mb-1">Client Dashboard</h2>
            <p class="text-muted small mb-0">Overview of your campaign lead performance · <?php echo htmlspecialchars($rangeLabel); ?></p>
        </div>
        <div class="dashboard-lite-actions">
          <a href="../clients/client-campaigns.php" class="btn btn-primary btn-sm"><i class="bi bi-megaphone me-1"></i>View Campaigns</a>
          <a href="../clients/client-leads.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-list-ul me-1"></i>View Leads</a>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3 shared-dashboard-filter dashboard-lite-filter" method="get">
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

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small fw-semibold text-uppercase">Campaigns</div>
                <div class="h3 mb-0 mt-1"><?php echo number_format(count($campaigns)); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small fw-semibold text-uppercase">Allocation</div>
                <div class="h3 mb-0 mt-1"><?php echo number_format($totalAllocation); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small fw-semibold text-uppercase text-success">Delivered</div>
                <div class="h3 mb-0 mt-1 text-success"><?php echo number_format($totalDelivered); ?></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light fw-semibold">Active Campaigns</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Campaign</th>
                                <th>Allocation</th>
                                <th class="text-success">Delivered</th>
                                <th>Progress</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaigns)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No active campaigns.</td></tr>
                            <?php else: ?>
                                <?php foreach ($campaigns as $c): ?>
                                    <?php 
                                        $s = $stats[(int)$c['id']] ?? ['delivered'=>0]; 
                                        $target = (int)($c['target_leads'] ?? 0);
                                        $del = (int)($s['delivered'] ?? 0);
                                        $percent = $target > 0 ? min(100, round(($del / max(1, $target)) * 100)) : 0;
                                    ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-semibold"><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></div>
                                            <span class="badge <?php echo $c['status']==='Live'?'bg-success':'bg-secondary'; ?> small"><?php echo htmlspecialchars($c['status']); ?></span>
                                        </td>
                                        <td><?php echo number_format($target); ?></td>
                                        <td class="text-success fw-semibold"><?php echo number_format($del); ?></td>
                                        <td style="width: 150px;">
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo $percent; ?>%;"></div>
                                            </div>
                                            <div class="text-muted small mt-1"><?php echo $percent; ?>% reached</div>
                                        </td>
                                        <td class="text-end pe-3">
                                            <a href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/campaigns/view?id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-secondary btn-xs" title="View Details"><i class="bi bi-eye"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Recent Lead Activities</div>
                <div class="card-body p-0">
                    <?php if (empty($activities)): ?>
                        <div class="p-3 text-muted small text-center">No recent activities.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activities as $a): ?>
                                <?php $meta = !empty($a['meta_json']) ? json_decode((string)$a['meta_json'], true) : []; ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <div class="fw-semibold small text-primary"><?php echo htmlspecialchars($a['user_name'] ?? 'System'); ?></div>
                                        <div class="text-muted small"><?php echo date('M j, H:i', strtotime((string)$a['created_at'])); ?></div>
                                    </div>
                                    <div class="small mb-1">
                                        <?php echo str_replace('_', ' ', ucfirst((string)$a['action'])); ?> for 
                                        <span class="fw-semibold"><?php echo htmlspecialchars($a['company_name'] ?? $a['lead_code']); ?></span>
                                    </div>
                                    <div class="text-muted small italic"><?php echo htmlspecialchars($a['campaign_name']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if ($userRole === 'client_admin'): ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-light fw-semibold">SDR Performance</div>
                    <div class="card-body p-0">
                        <?php if (empty($sdrPerformance)): ?>
                            <div class="p-3 text-muted small text-center">No SDR activity recorded.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($sdrPerformance as $sdr): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                        <div>
                                            <div class="fw-semibold small"><?php echo htmlspecialchars($sdr['full_name']); ?></div>
                                            <div class="text-muted small">Total Activities</div>
                                        </div>
                                        <div class="badge bg-primary rounded-pill"><?php echo number_format($sdr['activity_count']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Resources</div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <a href="../leads/leads-edit.php" class="list-group-item list-group-item-action border-0 px-0 d-flex align-items-center gap-2">
                            <i class="bi bi-funnel text-primary"></i>
                            <div class="small">Lead Management</div>
                        </a>
                        <a href="../campaigns/campaigns-manage.php" class="list-group-item list-group-item-action border-0 px-0 d-flex align-items-center gap-2">
                            <i class="bi bi-megaphone text-success"></i>
                            <div class="small">Campaign Overview</div>
                        </a>
                        <a href="../clients/client-users.php?client_id=<?php echo $clientId; ?>" class="list-group-item list-group-item-action border-0 px-0 d-flex align-items-center gap-2">
                            <i class="bi bi-people text-warning"></i>
                            <div class="small">Manage My Team</div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
