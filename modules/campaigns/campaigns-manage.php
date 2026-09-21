<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
if (isClient()) {
    header('Location: ../clients/client-campaigns');
    exit;
}
requirePermissionOrRole('campaigns.list.page', []);
ensureCsrfToken();

$currentUser = getCurrentUser();
$canOpsActions = isAdmin() || hasRole(['operations_director']);
$canManageCampaigns = isAdmin() || isSalesDirector() || isSalesManager() || isClientAdmin() || hasRole(['director','manager_director','operations_director','operations_manager']);
$canAssignVendors = isAdmin() || isSalesDirector() || isSalesManager() || hasRole(['director','manager_director','operations_director']);
$canViewFinancials = isAdmin() || isSalesDirector() || isSalesManager();
ensureCampaignDetailsColumns();
$isSdr = isSDR();
$isVendor = isVendor();
$isClient = isClient();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh_counts') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit;
    }
    if (!$canManageCampaigns) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
    $df = $_POST['date_from'] ?? '';
    $dt = $_POST['date_to'] ?? '';
    // Trigger aggregation (computed on render); here we just validate inputs
    getCampaignTabCounts($df ?: null, $dt ?: null);
    echo json_encode(['ok'=>true]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit; }
    if (!$canManageCampaigns) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
    $cid = (int)($_POST['campaign_id'] ?? 0);
    $newStatus = (string)($_POST['status'] ?? '');
    if ($cid <= 0 || $newStatus === '') { echo json_encode(['ok'=>false,'error'=>'Invalid request']); exit; }
    try {
        $ok = setCampaignStatus($cid, $newStatus, (int)($currentUser['id'] ?? 0));
        echo json_encode(['ok'=>$ok]); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['list_vendor_assignments','assign_vendor','remove_vendor_assignment'], true)) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit; }
    if (!$canAssignVendors) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
    $action = (string)$_POST['action'];
    $conn = getDbConnection();
    if ($action === 'list_vendor_assignments') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        if ($cid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        $stmt = $conn->prepare("SELECT v.id AS vendor_id, v.vendor_code, v.name, m.vendor_cpl, m.vendor_cpl_currency, m.uploads_enabled, m.assigned_at FROM vendor_campaign_map m JOIN vendors v ON v.id = m.vendor_id WHERE m.campaign_id = ? ORDER BY v.name");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
    } elseif ($action === 'assign_vendor') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $vid = (int)($_POST['vendor_id'] ?? 0);
        $cpl = $_POST['vendor_cpl'] !== '' ? (float)$_POST['vendor_cpl'] : null;
        $cur = $_POST['vendor_cpl_currency'] ?? null;
        $uploads = isset($_POST['uploads_enabled']) && $_POST['uploads_enabled'] === '1' ? 1 : 0;
        if ($cid <= 0 || $vid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        $stmt = $conn->prepare("INSERT INTO vendor_campaign_map (vendor_id, campaign_id, vendor_cpl, vendor_cpl_currency, uploads_enabled, assigned_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vendor_cpl=VALUES(vendor_cpl), vendor_cpl_currency=VALUES(vendor_cpl_currency), uploads_enabled=VALUES(uploads_enabled), assigned_by=VALUES(assigned_by), assigned_at=NOW()");
        $uid = (int)($currentUser['id'] ?? 0);
        $stmt->bind_param('iissii', $vid, $cid, $cpl, $cur, $uploads, $uid);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok]); exit;
    } elseif ($action === 'remove_vendor_assignment') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $vid = (int)($_POST['vendor_id'] ?? 0);
        if ($cid <= 0 || $vid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        $stmt = $conn->prepare("DELETE FROM vendor_campaign_map WHERE campaign_id = ? AND vendor_id = ?");
        $stmt->bind_param('ii', $cid, $vid);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok]); exit;
    }
}
$manualDateFrom = trim((string)($_GET['date_from'] ?? ''));
$manualDateTo = trim((string)($_GET['date_to'] ?? ''));
$period = strtolower(trim((string)($_GET['period'] ?? 'overall')));
if (!in_array($period, ['this_month', 'quarterly', 'overall'], true)) {
    $period = 'overall';
}
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$dateFrom = $manualDateFrom;
$dateTo = $manualDateTo;
$periodLabel = 'This Month';
if ($period === 'this_month') {
    $dateFrom = $opsNow->format('Y-m-01');
    $dateTo = $opsNow->format('Y-m-t');
} elseif ($period === 'quarterly') {
    $monthNum = (int)$opsNow->format('n');
    $quarterStartMonth = (int)(floor(($monthNum - 1) / 3) * 3) + 1;
    $quarterStart = new DateTimeImmutable($opsNow->format('Y') . '-' . sprintf('%02d', $quarterStartMonth) . '-01', $opsNow->getTimezone());
    $dateFrom = $quarterStart->format('Y-m-d');
    $dateTo = $quarterStart->modify('+2 months')->modify('last day of this month')->format('Y-m-d');
    $periodLabel = 'Quarterly';
} else {
    $periodLabel = 'Overall';
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {

      
        $dateTo = '';
    }
}
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusRequested = array_key_exists('status', $_GET);
$status = $statusRequested ? (string)$_GET['status'] : '';
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10,(int)$_GET['per_page']) : 25;
$filters = [];
if (!empty($dateFrom)) { $filters['date_from'] = $dateFrom; }
if (!empty($dateTo)) { $filters['date_to'] = $dateTo; }
if (!empty($search)) { $filters['search'] = $search; }
if ($status !== '') { $filters['status'] = $status; }
$filters['period'] = $period;
$statusOptions = ['Draft','Active','Pause','Complete','Live'];
$legacyToNew = ['Paused' => 'Pause', 'Completed' => 'Complete', 'Quotes' => 'Draft'];
if (isset($legacyToNew[$status])) $status = $legacyToNew[$status];
$statusLabel = $status === '' ? 'All' : ($status === 'Live' ? 'Active' : $status);
$scopeCampaignIds = null;
if (!$canManageCampaigns && !$isVendor && !$isClient) {
    $visibleMap = getScopedVisibleCampaignIdsForUser((int)($currentUser['id'] ?? 0), (string)($currentUser['role'] ?? ''));
    $scopeCampaignIds = $visibleMap === null ? null : array_keys($visibleMap);
}

$overviewRows = getCampaignOverviewByStatus($status ?: null, $dateFrom ?: null, $dateTo ?: null, $scopeCampaignIds);

if ($isVendor) {
    $vendorId = (int)($currentUser['vendor_id'] ?? 0);
    $conn = getDbConnection();
    $assigned = [];
    $stmt = $conn->prepare("SELECT campaign_id FROM vendor_campaign_map WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) $assigned[(int)$r['campaign_id']] = true;
    $stmt->close();

    if ($currentUser['role'] === 'vendor_user') {
        $userAssigned = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $currentUser['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $userAssigned[(int)$r['campaign_id']] = true;
        $stmt->close();
        $overviewRows = array_values(array_filter($overviewRows, function($r) use ($assigned, $userAssigned) {
            $id = (int)($r['id'] ?? 0);
            return isset($assigned[$id]) && isset($userAssigned[$id]);
        }));
    } else {
        $overviewRows = array_values(array_filter($overviewRows, function($r) use ($assigned) {
            $id = (int)($r['id'] ?? 0);
            return isset($assigned[$id]);
        }));
    }
}
if ($isClient) {
    $clientId = (int)($currentUser['client_id'] ?? 0);
    $overviewRows = array_values(array_filter($overviewRows, function($r) use ($clientId) {
        return (int)($r['client_id'] ?? 0) === $clientId;
    }));
    if ($currentUser['role'] === 'client_sdr') {
        $userAssigned = [];
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $currentUser['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $userAssigned[(int)$r['campaign_id']] = true;
        $stmt->close();
        $overviewRows = array_values(array_filter($overviewRows, function($r) use ($userAssigned) {
            $id = (int)($r['id'] ?? 0);
            return isset($userAssigned[$id]);
        }));
    }
}
$tabCounts = getCampaignTabCounts($dateFrom ?: null, $dateTo ?: null, $scopeCampaignIds);
if ($search !== '') {
    $q = mb_strtolower($search);
    $overviewRows = array_values(array_filter($overviewRows, function($r) use ($q){
        return (strpos(mb_strtolower($r['name'] ?? ''), $q) !== false)
            || (strpos(mb_strtolower($r['code'] ?? ''), $q) !== false)
            || (strpos(mb_strtolower($r['client_code'] ?? ''), $q) !== false)
            || (strpos(mb_strtolower($r['client_name'] ?? ''), $q) !== false);
    }));
}

// Fetch status update author names
if (!empty($overviewRows)) {
    $uids = array_values(array_filter(array_unique(array_map(fn($r) => (int)($r['status_updated_by'] ?? 0), $overviewRows))));
    $authorMap = [];
    if (!empty($uids)) {
        $conn = getDbConnection();
        $in = implode(',', array_fill(0, count($uids), '?'));
        $stmt = $conn->prepare("SELECT id, full_name, username FROM users WHERE id IN ($in)");
        $stmt->bind_param(str_repeat('i', count($uids)), ...$uids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($u = $res->fetch_assoc()) {
            $authorMap[(int)$u['id']] = $u['full_name'] ?: $u['username'];
        }
        $stmt->close();
    }
    foreach ($overviewRows as &$row) {
        $sid = (int)($row['status_updated_by'] ?? 0);
        $row['status_updated_by_name'] = $authorMap[$sid] ?? '';
    }
    unset($row);
}

$campaignIdsForStats = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $overviewRows), fn($v) => $v > 0));
$campaignStatsById = !empty($campaignIdsForStats) ? getCampaignLeadStatsBulk($campaignIdsForStats, null, null, true) : [];
$visibleCampaignCount = count($overviewRows);
$activeFilterCount = count(array_filter([
    $period !== 'overall' ? 1 : 0,
    ($period === 'overall' && $dateFrom !== '') ? 1 : 0,
    ($period === 'overall' && $dateTo !== '') ? 1 : 0,
    $search !== '' ? 1 : 0,
    $status !== '' ? 1 : 0,
]));
$countScopeText = 'Lead metrics below use only leads created within each campaign start and end date window.';
// SR numbers
$srStart = 1;
?>
<?php $pageTitle = 'Campaigns'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
#campaignTable th,
#campaignTable td {
  vertical-align: middle;
}
.pacing-circle {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  position: relative;
  background: conic-gradient(#198754 0%, #e9ecef 0%);
}
.pacing-circle::before {
  content: '';
  position: absolute;
  inset: 4px;
  border-radius: 50%;
  background: #fff;
}
.pacing-circle span {
  position: relative;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1;
  color: #198754;
}
 </style>
<div class="container-fluid px-0 campaign-workspace">
    <div class="campaign-page-header campaign-hero mb-3">
        <div>
            <div class="campaign-eyebrow mb-2"><i class="bi bi-megaphone"></i>Campaign Operations</div>
            <div class="campaign-page-title">Campaigns</div>
            <div class="campaign-page-subtitle">Filter, track pacing, and manage campaign setup with campaign-window lead counts instead of lifetime totals.</div>
        </div>
        <div class="campaign-page-actions justify-content-end">
            <?php if ($canOpsActions): ?>
                <a href="create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Create Campaign</a>
            <?php endif; ?>
            <div class="dropdown">
                <button class="btn btn-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="export?date_from=<?php echo htmlspecialchars($dateFrom); ?>&date_to=<?php echo htmlspecialchars($dateTo); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&format=csv">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>CSV
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="export?date_from=<?php echo htmlspecialchars($dateFrom); ?>&date_to=<?php echo htmlspecialchars($dateTo); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&format=xls">
                            <i class="bi bi-file-earmark-excel me-2"></i>XLS
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <div class="card border-0 shadow-sm campaign-panel campaign-form-shell mb-3">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-funnel me-1"></i>Filters</span>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#campaignFilters" aria-expanded="false" aria-controls="campaignFilters" id="campaignFiltersToggle">Show Filters</button>
        </div>
        <div id="campaignFilters" class="collapse">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end campaign-filter-form">
                        <div class="col-xl-2 col-lg-3 col-md-4">
                            <label class="form-label small text-muted" for="period">Period</label>
                            <select class="form-select form-select-sm" id="period" name="period">
                                <option value="this_month" <?php echo $period === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="quarterly" <?php echo $period === 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                <option value="overall" <?php echo $period === 'overall' ? 'selected' : ''; ?>>Overall</option>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label small text-muted" for="date_from">Date From</label>
                            <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label small text-muted" for="date_to">Date To</label>
                            <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label small text-muted" for="search">Keyword</label>
                            <input type="text" class="form-control form-control-sm" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or code">
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-4">
                            <label class="form-label small text-muted" for="status">Status</label>
                            <select class="form-select form-select-sm" id="status" name="status">
                                <option value="">All</option>
                                <?php foreach ($statusOptions as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo ($status===$st)?'selected':''; ?>><?php echo $st; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-4 ms-auto d-flex justify-content-end">
                            <button class="btn btn-primary btn-sm w-100" type="submit">
                                <i class="bi bi-funnel me-1"></i>Apply
                            </button>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-4 d-flex justify-content-end">
                            <a class="btn btn-light border btn-sm w-100" href="list">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                            </a>
                        </div>
                </form>
            </div>
        </div>
    </div>
    <div class="card border-0 shadow-sm campaign-panel mb-3">
        <div class="campaign-table-toolbar">
            <div class="campaign-table-toolbar-main">
                <span class="campaign-table-count"><i class="bi bi-table"></i> Showing <?php echo number_format($visibleCampaignCount); ?> campaigns</span>
                <div>
                    <div class="campaign-table-title">Campaign List</div>
                    <div class="campaign-table-subtitle"><?php echo htmlspecialchars($countScopeText); ?></div>
                </div>
            </div>
            <div class="campaign-table-toolbar-side">
                <?php if ($activeFilterCount > 0): ?>
                    <span class="campaign-filter-pill"><i class="bi bi-funnel"></i><?php echo $activeFilterCount; ?> filters active</span>
                <?php endif; ?>
                <span class="campaign-filter-pill"><i class="bi bi-calendar3"></i>Period: <?php echo htmlspecialchars($periodLabel); ?></span>
                <span class="campaign-filter-pill"><i class="bi bi-calendar-range"></i>Count Scope: Campaign Dates</span>
                <span class="campaign-filter-pill"><i class="bi bi-collection"></i>Status: <?php echo htmlspecialchars($statusLabel); ?></span>
            </div>
        </div>
        <div class="card-header bg-light campaign-list-header">
            <div class="campaign-list-head">
                <div class="campaign-list-tabs">
                    <ul class="nav nav-tabs card-header-tabs campaign-tabs">
                    <?php $tabs=[
                        ['label'=>'Draft','status'=>'Draft','count'=>$tabCounts['Draft']??0],
                        ['label'=>'Active','status'=>'Active','count'=>$tabCounts['Active']??0],
                        ['label'=>'Live','status'=>'Live','count'=>$tabCounts['Live']??0],
                        ['label'=>'Pause','status'=>'Pause','count'=>$tabCounts['Pause']??0],
                        ['label'=>'Complete','status'=>'Complete','count'=>$tabCounts['Complete']??0],
                        ['label'=>'All Campaigns','status'=>'','count'=>$tabCounts['All']??0],
                    ];
                    foreach($tabs as $t):
                        $isActive = (($status===$t['status']) || ($t['status']==='' && $status===''));
                        $qs = $_GET; $qs['status'] = $t['status']; $url='list?' . http_build_query($qs);
                    ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $isActive?'active':''; ?>" href="<?php echo $url; ?>">
                            <?php echo htmlspecialchars($t['label']); ?>
                            <span class="badge bg-secondary ms-1"><?php echo (int)$t['count']; ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <div class="campaign-list-actions">
                    <?php if ($canManageCampaigns): ?>
                    <button id="refreshCounts" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Update Counts</button>
                    <?php endif; ?>
                </div>
            </div>  
            <!-- <div class="campaign-column-legend">
                <span class="campaign-column-chip"><strong>Alloc</strong> Allocation</span>
                <span class="campaign-column-chip"><strong>Gen</strong> Generated</span>
                <span class="campaign-column-chip"><strong>PQA</strong> Pending QA</span>
                <span class="campaign-column-chip"><strong>DQ</strong> Disqualified</span>
                <span class="campaign-column-chip"><strong>Q</strong> Qualified</span>
                <span class="campaign-column-chip"><strong>Del</strong> Delivered</span>
                <span class="campaign-column-chip"><strong>Acc</strong> Accepted</span>
                <span class="campaign-column-chip"><strong>Pend</strong> Pending Allocation</span>
            </div>
        </div> -->
                <div class="card-body p-0">
                    <div class="table-responsive campaign-table-wrap">
                        <table id="campaignTable" class="table table-striped table-hover align-middle table-sm" style="font-size:0.9rem;">
                    <thead>
                        <tr>
                            <th class="text-start">Campaign</th>
                            <th class="text-start">Code</th>
                            <th>Status</th>
                            <th>Start</th>
                            <th>End</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Total Allocation">Alloc</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Generated Leads in Campaign Date Window">Gen</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Pending QA">PQA</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Disqualified Leads">DQ</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Qualified Leads">Q</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Delivered to Client">Del</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Client Accepted">Acc</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Pending Allocation">Pend</th>
                            <th class="text-center" data-bs-toggle="tooltip" title="Delivery Progress (Client Delivered / Allocation)">Pacing</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($overviewRows)): ?>
                            <tr><td colspan="15" class="campaign-empty-state">No campaigns found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($overviewRows as $r): ?>
                                <tr>
                                    <td class="text-start campaign-name-cell">
                                        <span class="campaign-name-main"><?php echo htmlspecialchars($r['name'] ?? ''); ?></span>
                                        <span class="campaign-name-meta">Window: <?php echo htmlspecialchars(($r['start_date'] ?? 'N/A') . ' to ' . ($r['end_date'] ?? 'N/A')); ?></span>
                                    </td>
                                    <td class="text-start text-muted"><?php echo htmlspecialchars($r['code'] ?? ''); ?></td>
                                    <?php
                                        $statusVal = (string)($r['status'] ?? '');
                                        $statusMap = ['Live'=>'success','Active'=>'primary','Pause'=>'warning','Draft'=>'secondary','Complete'=>'dark'];
                                        $statusCls = $statusMap[$statusVal] ?? 'secondary';
                                        $statusTextCls = ($statusVal === 'Pause') ? 'text-dark' : '';
                                    ?>
                                    <td class="campaign-status-cell">
                                        <span class="badge bg-<?php echo $statusCls; ?> <?php echo $statusTextCls; ?>"><?php echo htmlspecialchars($statusVal); ?></span>
                                        <?php if ($statusVal === 'Pause' && !empty($r['status_updated_by_name'])): ?>
                                            <div class="x-small text-muted mt-1" style="font-size: 0.7rem;">by <?php echo htmlspecialchars($r['status_updated_by_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="campaign-date-cell"><?php echo htmlspecialchars($r['start_date'] ?? ''); ?></td>
                                    <td class="campaign-date-cell"><?php echo htmlspecialchars($r['end_date'] ?? ''); ?></td>
                                    <?php 
                                        $allocation = (int)($r['total_leads'] ?? 0);
                                        $cid = (int)($r['id'] ?? 0);
                                        $st = $cid > 0 ? (array)($campaignStatsById[$cid] ?? getCampaignLeadTableStats($cid, null, null, true)) : ['total'=>0,'pending_qa'=>0,'approved'=>0,'rejected'=>0,'client_delivered'=>0, 'client_accepted'=>0,'last_submitted_at'=>null];
                                        $G = (int)($st['total'] ?? 0);
                                        $pendingQa = (int)($st['pending_qa'] ?? 0);
                                        $Q = (int)($st['approved'] ?? 0);
                                        $DQ = (int)($st['rejected'] ?? 0);
                                        $delivered = (int)($st['client_delivered'] ?? 0);
                                        $accepted = (int)($st['client_accepted'] ?? 0);
                                        $pendingAlloc = max(0, $allocation - $delivered);
                                        $pct = $allocation > 0 ? round((min($delivered, $allocation) / $allocation) * 100) : 0;
                                    ?>
                                    <td class="campaign-metric-cell"><?php echo $allocation; ?></td>
                                    <td class="campaign-metric-cell"><?php echo $G; ?></td>
                                    <td class="campaign-metric-cell"><?php echo $pendingQa; ?></td>
                                    <td class="campaign-metric-cell"><?php echo $DQ; ?></td>
                                    <td class="campaign-metric-cell"><?php echo $Q; ?></td>
                                    <td class="campaign-metric-cell"><?php echo $delivered; ?></td>
                                    <td class="campaign-metric-cell"><?php echo $accepted; ?></td>
                                    <td class="campaign-metric-cell"><?php echo $pendingAlloc; ?></td>
                                    <td class="text-center text-nowrap campaign-pacing-cell">
                                      <div class="pacing-circle" style="background: conic-gradient(#198754 <?php echo $pct; ?>%, #e9ecef 0%);">
                                        <span><?php echo $pct; ?>%</span>
                                      </div>
                                    </td>
                                    <td class="text-end text-nowrap campaign-actions-cell">
                                        <div class="campaign-action-group" role="group">
                                            <a class="btn btn-light border" href="view?id=<?php echo (int)$r['id']; ?>" title="View Campaign" data-bs-toggle="tooltip">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($canOpsActions): ?>
                                                <a class="btn btn-light border" href="leads?campaign_id=<?php echo (int)$r['id']; ?>" title="Campaign Leads" data-bs-toggle="tooltip">
                                                    <i class="bi bi-list-ul"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($canOpsActions): ?>
                                                <a class="btn btn-light border" href="edit?id=<?php echo (int)$r['id']; ?>" title="Edit Campaign" data-bs-toggle="tooltip">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a class="btn btn-light border" href="../forms/forms-manage.php?campaign_id=<?php echo (int)$r['id']; ?>" title="Manage Forms" data-bs-toggle="tooltip">
                                                    <i class="bi bi-ui-checks"></i>
                                                </a>
                                                <a class="btn btn-light border" href="allocation?campaign_id=<?php echo (int)$r['id']; ?>" title="Allocation" data-bs-toggle="tooltip">
                                                    <i class="bi bi-bullseye"></i>
                                                </a>
                                                <a class="btn btn-light border" href="files?campaign_id=<?php echo (int)$r['id']; ?>" title="Campaign Files" data-bs-toggle="tooltip">
                                                    <i class="bi bi-box-arrow-in-down"></i>
                                                </a>
                                                <?php if ($canAssignVendors): ?>
                                                <button class="btn btn-light border" type="button" title="Assign Vendors" data-bs-toggle="tooltip" data-action="open-vendor-assign" data-campaign-id="<?php echo (int)$r['id']; ?>">
                                                    <i class="bi bi-truck"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php
                                                    $curStatus = (string)($r['status'] ?? '');
                                                    $hasForm = (int)($r['assigned_form_id'] ?? 0) > 0;
                                                ?>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-light border dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Status Actions">
                                                        <i class="bi bi-sliders"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <?php if ($curStatus === 'Draft'): ?>
                                                            <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Active">Activate</a></li>
                                                        <?php elseif ($curStatus === 'Active'): ?>
                                                            <li><a class="dropdown-item <?php echo $hasForm ? '' : 'disabled'; ?>" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Live">Go Live</a></li>
                                                        <?php elseif ($curStatus === 'Pause'): ?>
                                                            <li><a class="dropdown-item <?php echo $hasForm ? '' : 'disabled'; ?>" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Live">Resume Live</a></li>
                                                        <?php elseif ($curStatus === 'Live'): ?>
                                                            <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Pause">Pause</a></li>
                                                            <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Complete">Complete</a></li>
                                                        <?php endif; ?>
                                                        <?php if (!$hasForm && ($curStatus === 'Active' || $curStatus === 'Pause')): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="../forms/forms-manage.php?campaign_id=<?php echo (int)$r['id']; ?>">Assign Form</a></li>
                                                        <?php endif; ?>
                                                        <?php if ($canManageCampaigns): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="#" data-action="delete" data-cid="<?php echo (int)$r['id']; ?>">Delete</a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
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

<?php
$vendorsList = [];
if ($canAssignVendors) {
    $rs = getDbConnection()->query("SELECT id, vendor_code, name FROM vendors WHERE is_active=1 ORDER BY name");
    if ($rs) { $vendorsList = $rs->fetch_all(MYSQLI_ASSOC) ?: []; }
}
?>
<?php if ($canAssignVendors): ?>
<div class="modal fade" id="vendorAssignModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Assign Vendors</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form class="row g-2 align-items-end" id="vendorAssignForm">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="assign_vendor">
          <input type="hidden" name="campaign_id" id="assign_campaign_id" value="0">
          <div class="col-md-6">
            <label class="form-label small text-muted">Vendor</label>
            <select class="form-select form-select-sm" name="vendor_id" id="assign_vendor_id">
              <option value="">Select Vendor</option>
              <?php foreach ($vendorsList as $v): ?>
                <option value="<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars(($v['name'] ?? '').' ['.($v['vendor_code'] ?? '').']'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small text-muted">Vendor CPL</label>
            <input type="number" step="0.01" class="form-control form-control-sm" name="vendor_cpl" id="assign_vendor_cpl" placeholder="0.00">
          </div>
          <div class="col-md-2">
            <label class="form-label small text-muted">Currency</label>
            <select class="form-select form-select-sm" name="vendor_cpl_currency" id="assign_vendor_cpl_currency">
              <option value="">—</option>
              <option value="USD">USD</option>
              <option value="EUR">EUR</option>
              <option value="GBP">GBP</option>
              <option value="INR">INR</option>
            </select>
          </div>
          <div class="col-md-1 d-flex align-items-end">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="assign_uploads_enabled" name="uploads_enabled" value="1" checked>
              <label class="form-check-label small" for="assign_uploads_enabled">Upload</label>
            </div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add/Update</button>
          </div>
        </form>
        <hr>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Vendor</th>
                <th class="text-end">CPL</th>
                <th class="text-center">Uploads</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody id="vendorAssignBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
  </div>

<script>
const token = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
const canManageCampaigns = <?php echo $canManageCampaigns ? 'true' : 'false'; ?>;
const isAdmin = <?php echo isAdmin() ? 'true' : 'false'; ?>;
const notify = (type, message, title) => {
  if (window.appNotify) return window.appNotify(type, message, title || '');
  alert(String(message || ''));
};
async function fetchJson(url, options) {
  const r = await fetch(url, options);
  let data = null;
  try { data = await r.json(); } catch (e) {}
  if (!r.ok) {
    const msg = (data && (data.error || data.message)) ? (data.error || data.message) : ('Request failed (' + r.status + ')');
    throw new Error(msg);
  }
  return data;
}
function initTooltips() {
  if (!window.bootstrap || !bootstrap.Tooltip) return;
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });
}
document.addEventListener('DOMContentLoaded', initTooltips);
window.addEventListener('load', initTooltips);
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action="delete"]');
  if (!btn) return;
  const id = btn.getAttribute('data-cid');
  if(confirm('Delete this campaign?')){
    const fd = new FormData();
    fd.append('csrf_token', token);
    fd.append('id', id);
    try { window.__appLoader?.show?.(); } catch (e) {}
    fetchJson('delete', {method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
      .then(d => {
        if (d && d.ok) {
          notify('success', d.message || 'Campaign deleted', 'Success');
          setTimeout(() => location.reload(), 350);
        } else {
          notify('danger', d?.error || 'Failed to delete campaign', 'Error');
        }
      })
      .catch(err => notify('danger', err?.message || 'Failed to delete campaign', 'Error'))
      .finally(() => { try { window.__appLoader?.hide?.(); } catch (e) {} });
  }
});
<?php if ($canManageCampaigns): ?>
document.getElementById('refreshCounts')?.addEventListener('click', function(){
  const fd = new FormData();
  fd.append('csrf_token', token);
  fd.append('action', 'refresh_counts');
  fd.append('date_from', document.getElementById('date_from').value||'');
  fd.append('date_to', document.getElementById('date_to').value||'');
  try { window.__appLoader?.show?.(); } catch (e) {}
  fetchJson('list', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(d => {
      if (d && d.ok) {
        notify('success', 'Counts updated', 'Success');
        setTimeout(() => location.reload(), 350);
      } else {
        notify('danger', d?.error || 'Failed to update counts', 'Error');
      }
    })
    .catch(err => notify('danger', err?.message || 'Failed to update counts', 'Error'))
    .finally(() => { try { window.__appLoader?.hide?.(); } catch (e) {} });
});
<?php endif; ?>
document.addEventListener('click', (e) => {
  const el = e.target.closest('[data-action="status"]');
  if (!el) return;
  e.preventDefault();
  if (el.classList.contains('disabled')) return;
  const cid = el.getAttribute('data-cid');
  const st = el.getAttribute('data-next');
  if(!cid || !st) return;
  const fd = new FormData();
  fd.append('csrf_token', token);
  fd.append('action', 'update_status');
  fd.append('campaign_id', cid);
  fd.append('status', st);
  try { window.__appLoader?.show?.(); } catch (e) {}
  fetchJson('list', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(d => {
      if (d && d.ok) {
        notify('success', 'Status updated', 'Success');
        setTimeout(() => location.reload(), 350);
      } else {
        notify('danger', d?.error || 'Failed to update status', 'Error');
      }
    })
    .catch(err => notify('danger', err?.message || 'Failed to update status', 'Error'))
    .finally(() => { try { window.__appLoader?.hide?.(); } catch (e) {} });
});
const filtersEl = document.getElementById('campaignFilters');
const filtersBtn = document.getElementById('campaignFiltersToggle');
if (filtersEl && filtersBtn) {
  const setLabel = () => { filtersBtn.textContent = filtersEl.classList.contains('show') ? 'Hide Filters' : 'Show Filters'; };
  filtersEl.addEventListener('shown.bs.collapse', setLabel);
  filtersEl.addEventListener('hidden.bs.collapse', setLabel);
  setLabel();
}
</script>
<script>
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action="open-vendor-assign"]');
  if (!btn) return;
  const cid = btn.getAttribute('data-campaign-id');
  const mEl = document.getElementById('vendorAssignModal');
  if (!cid || !mEl) return;
  document.getElementById('assign_campaign_id').value = cid;
  const body = document.getElementById('vendorAssignBody');
  body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>';
  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
  fd.append('action', 'list_vendor_assignments');
  fd.append('campaign_id', cid);
  fetch('list', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(r=>r.json()).then(d=>{
      if (d && d.ok) {
        if (!Array.isArray(d.rows) || d.rows.length===0) {
          body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No vendors assigned.</td></tr>';
        } else {
          body.innerHTML = '';
          d.rows.forEach(row=>{
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>'+eh(row.name||'')+' <span class="text-muted small">['+eh(row.vendor_code||'')+']</span></td>'
              + '<td class="text-end">'+(row.vendor_cpl? String(row.vendor_cpl) : '-')+' '+eh(row.vendor_cpl_currency||'')+'</td>'
              + '<td class="text-center">'+(parseInt(row.uploads_enabled)===1?'<span class="badge bg-success-subtle text-success border">Yes</span>':'<span class="badge bg-secondary-subtle text-secondary border">No</span>')+'</td>'
              + '<td class="text-end"><button class="btn btn-sm btn-outline-danger" data-action="remove-vendor-assignment" data-vendor-id="'+String(row.vendor_id)+'"><i class="bi bi-trash"></i></button></td>';
            body.appendChild(tr);
          });
        }
        if (window.bootstrap && bootstrap.Modal) {
          new bootstrap.Modal(mEl).show();
        }
      } else {
        body.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">'+eh(d?.error||'Failed to load')+'</td></tr>';
      }
    }).catch(()=>{ body.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">Failed to load</td></tr>'; });
});
document.getElementById('vendorAssignForm')?.addEventListener('submit', function(ev){
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  fetchJson('list', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(d => {
      if (d && d.ok) {
        notify('success', 'Vendor assignment updated', 'Success');
        const cid = document.getElementById('assign_campaign_id').value;
        const btn = document.querySelector('[data-action="open-vendor-assign"][data-campaign-id="'+CSS.escape(String(cid))+'"]');
        if (btn) btn.click();
      } else {
        notify('danger', d?.error || 'Failed', 'Error');
      }
    })
    .catch(err => notify('danger', err?.message || 'Failed', 'Error'));
});
document.addEventListener('click', (e)=>{
  const rm = e.target.closest('[data-action="remove-vendor-assignment"]');
  if (!rm) return;
  const vid = rm.getAttribute('data-vendor-id');
  const cid = document.getElementById('assign_campaign_id').value;
  if (!vid || !cid) return;
  if (!confirm('Remove this vendor from campaign?')) return;
  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
  fd.append('action', 'remove_vendor_assignment');
  fd.append('campaign_id', cid);
  fd.append('vendor_id', vid);
  fetchJson('list', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(d => {
      if (d && d.ok) {
        notify('success', 'Vendor removed from campaign', 'Success');
        const btn = document.querySelector('[data-action="open-vendor-assign"][data-campaign-id="'+CSS.escape(String(cid))+'"]');
        if (btn) btn.click();
      } else {
        notify('danger', d?.error || 'Failed', 'Error');
      }
    })
    .catch(err => notify('danger', err?.message || 'Failed', 'Error'));
});
function eh(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/\\'/g,'&#39;');}
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
