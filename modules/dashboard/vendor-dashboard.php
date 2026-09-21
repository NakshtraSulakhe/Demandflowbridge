<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dashboard.vendor', ['admin', 'vendor_admin', 'vendor_user']);

$user = getCurrentUser();
$vendorId = (int)($user['vendor_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
$canBulkUpload = userHasPermission('leads.bulk_upload');
$canManualEntry = userHasPermission('leads.entry');
$canRevenue = userHasPermission('revenue.access');

if ($vendorId <= 0 && !isAdmin()) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    $_SESSION['access_denied_context'] = [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'required_roles' => ['admin','vendor_admin','vendor_user'],
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

// Get assigned campaigns for this vendor
$campaigns = [];
$sql = "SELECT c.id, c.name, d.client_code, d.status, m.vendor_cpl, m.vendor_cpl_currency, m.uploads_enabled,
               d.status_updated_by, (SELECT full_name FROM users WHERE id = d.status_updated_by LIMIT 1) AS status_updated_by_name
        FROM campaigns c
        JOIN campaign_details d ON c.id = d.campaign_id
        JOIN vendor_campaign_map m ON c.id = m.campaign_id
        WHERE m.vendor_id = ?
        ORDER BY c.name";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

// Filter for vendor_user
if ($userRole === 'vendor_user') {
    $assignedIds = [];
    $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) { $assignedIds[(int)$r['campaign_id']] = true; }
        $stmt->close();
    }
    
    $campaigns = array_values(array_filter($campaigns, fn($c) => isset($assignedIds[(int)$c['id']])));
}

// Get stats per campaign
$stats = [];
$totalSubmitted = 0;
$totalApproved = 0;
$totalPending = 0;
$totalRejected = 0;
$totalRevenue = 0;
$revenueByCurrency = [];

foreach ($campaigns as $c) {
    $cid = (int)$c['id'];
    $cpl = (float)($c['vendor_cpl'] ?? 0);
    
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN qa_status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN qa_status IN ('Qualified', 'Approved') THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN qa_status IN ('Disqualified', 'Rejected') THEN 1 ELSE 0 END) as rejected
        FROM leads 
        WHERE campaign_id = ? AND vendor_id = ? AND $leadCreatedExpr BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('iiss', $cid, $vendorId, $rangeStart, $rangeEnd);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
        $stmt->close();
    } else {
        $row = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
    }
    
    $row['revenue'] = $row['approved'] * $cpl;
    $row['currency'] = $c['vendor_cpl_currency'] ?: 'USD';
    $stats[$cid] = $row;
    
    $totalSubmitted += $row['total'];
    $totalApproved += $row['approved'];
    $totalPending += $row['pending'];
    $totalRejected += $row['rejected'];
    $totalRevenue += $row['revenue'];
    $cur = strtoupper(trim((string)($row['currency'] ?? 'USD')));
    if ($cur === '') $cur = 'USD';
    if (!isset($revenueByCurrency[$cur])) $revenueByCurrency[$cur] = 0.0;
    $revenueByCurrency[$cur] += (float)$row['revenue'];
}

$pageTitle = 'Vendor Dashboard';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 dashboard-lite-workspace">
    <div class="dashboard-lite-header dashboard-lite-hero mb-3">
        <div>
            <h2 class="h3 mb-1">Vendor Dashboard</h2>
            <p class="text-muted small mb-0">Performance overview for your assigned campaigns · <?php echo htmlspecialchars($rangeLabel); ?></p>
        </div>
        <div class="dashboard-lite-actions">
            <?php if ($canBulkUpload): ?><a href="../leads/bulk-upload.php" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Bulk Upload</a><?php endif; ?>
            <?php if ($canManualEntry): ?><a href="../leads/agent.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Manual Entry</a><?php endif; ?>
            <a href="../vendors/vendor-campaigns.php" class="btn btn-light border btn-sm"><i class="bi bi-megaphone me-1"></i>Campaigns</a>
            <a href="../vendors/vendor-leads.php" class="btn btn-light border btn-sm"><i class="bi bi-list-ul me-1"></i>Leads</a>
            <?php if ($canRevenue): ?><a href="../vendors/vendor-revenue.php" class="btn btn-light border btn-sm"><i class="bi bi-cash-coin me-1"></i>Revenue</a><?php endif; ?>
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

    <!-- Quick Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small fw-semibold text-uppercase">Total Submitted</div>
                <div class="h3 mb-0 mt-1"><?php echo number_format($totalSubmitted); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small fw-semibold text-uppercase text-success">Approved</div>
                <div class="h3 mb-0 mt-1 text-success"><?php echo number_format($totalApproved); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small fw-semibold text-uppercase text-warning">Pending QA</div>
                <div class="h3 mb-0 mt-1 text-warning"><?php echo number_format($totalPending); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small fw-semibold text-uppercase">Est. Revenue</div>
                <div class="h3 mb-0 mt-1"><?php echo number_format($totalRevenue, 2); ?></div>
                <div class="small text-muted mt-1">
                    <?php
                        $currencyParts = [];
                        foreach ($revenueByCurrency as $currencyCode => $currencyValue) {
                            $currencyParts[] = $currencyCode . ' ' . number_format((float)$currencyValue, 2);
                        }
                        echo htmlspecialchars(!empty($currencyParts) ? implode(' · ', $currencyParts) : 'No revenue yet');
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Assigned Campaigns</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Campaign</th>
                        <th>CPL</th>
                        <th>Submitted</th>
                        <th>Approved</th>
                        <th>Pending</th>
                        <th>Rejected</th>
                        <th>Revenue</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campaigns)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No campaigns assigned.</td></tr>
                    <?php else: ?>
                        <?php foreach ($campaigns as $c): ?>
                            <?php $s = $stats[(int)$c['id']] ?? ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0,'revenue'=>0,'currency'=>'USD']; ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold"><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></div>
                                    <span class="badge <?php echo $c['status']==='Live'?'bg-success':'bg-secondary'; ?> small"><?php echo htmlspecialchars($c['status']); ?></span>
                                    <?php if ($c['status'] === 'Pause' && !empty($c['status_updated_by_name'])): ?>
                                        <div class="x-small text-muted mt-1" style="font-size: 0.65rem;">by <?php echo htmlspecialchars($c['status_updated_by_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((float)$c['vendor_cpl'], 2); ?> <span class="small text-muted"><?php echo htmlspecialchars($c['vendor_cpl_currency']); ?></span></td>
                                <td><?php echo number_format($s['total']); ?></td>
                                <td class="text-success fw-semibold"><?php echo number_format($s['approved']); ?></td>
                                <td class="text-warning"><?php echo number_format($s['pending']); ?></td>
                                <td class="text-danger"><?php echo number_format($s['rejected']); ?></td>
                                <td class="fw-bold"><?php echo number_format($s['revenue'], 2); ?></td>
                                <td class="text-end pe-3">
                                    <?php if (!empty($c['uploads_enabled'])): ?>
                                        <a href="../leads/bulk-upload.php?campaign_id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-primary btn-xs" title="Upload Leads"><i class="bi bi-upload"></i></a>
                                    <?php endif; ?>
                                    <a href="../vendors/vendor-campaign-details.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-secondary btn-xs" title="View Details"><i class="bi bi-eye"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
