<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$conn = getDbConnection();
$user = getCurrentUser();
$error = '';
$success = '';

$campaignOptions = [];
$rsCampaigns = $conn->query("SELECT c.id, c.name, d.code, d.client_code FROM campaigns c LEFT JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
if ($rsCampaigns) $campaignOptions = $rsCampaigns->fetch_all(MYSQLI_ASSOC) ?: [];

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];
$start = sprintf('%04d-%02d-01', $year, $month) . ' 00:00:00';
$end = date('Y-m-t', strtotime(substr($start, 0, 10))) . ' 23:59:59';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_revenue') {
                $campaignId = (int)($_POST['campaign_id'] ?? 0);
                $revenue = trim((string)($_POST['revenue'] ?? ''));
                $currency = trim((string)($_POST['currency'] ?? 'USD'));
                if ($campaignId <= 0) throw new RuntimeException('Invalid campaign.');
                $revVal = $revenue !== '' ? (float)$revenue : null;
                $updatedBy = (int)($user['id'] ?? 0);
                $stmt = $conn->prepare("INSERT INTO campaign_revenue (campaign_id, revenue, currency, updated_by) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE revenue = VALUES(revenue), currency = VALUES(currency), updated_by = VALUES(updated_by)");
                $stmt->bind_param('idsi', $campaignId, $revVal, $currency, $updatedBy);
                if (!$stmt->execute()) throw new RuntimeException('Failed to save revenue.');
                $stmt->close();
                $success = 'Revenue saved.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$params = [$start, $end, $start, $end];
$types = 'ssss';
$campaignFilterId = (int)($_GET['campaign_id'] ?? 0);
$filters = [];
if ($q !== '') {
    $filters[] = "(c.name LIKE ? OR d.code LIKE ? OR d.client_code LIKE ?)";
    $like = '%'.$q.'%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}
$selectedCampaign = null;
if ($campaignFilterId > 0) {
    $filters[] = "c.id = ?";
    $params[] = $campaignFilterId;
    $types .= 'i';
    foreach ($campaignOptions as $campaignRow) {
        if ((int)($campaignRow['id'] ?? 0) === $campaignFilterId) {
            $selectedCampaign = $campaignRow;
            break;
        }
    }
}
$whereSql = !empty($filters) ? ('WHERE ' . implode(' AND ', $filters)) : '';
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';

$sql = "SELECT c.id, c.name, c.active, d.code, d.client_code, d.status, d.cpl, d.cpl_currency,
        r.revenue, r.currency AS revenue_currency, r.updated_at,
        SUM(CASE WHEN l.client_delivery_status = 'Accepted' AND $leadCreatedExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS delivered_month,
         SUM(CASE WHEN l.client_delivery_status = 'Accepted' AND $leadCreatedExpr BETWEEN ? AND ? THEN COALESCE(d.cpl, 0) ELSE 0 END) AS generated_month
        FROM campaigns c
        LEFT JOIN campaign_details d ON d.campaign_id = c.id
        LEFT JOIN campaign_revenue r ON r.campaign_id = c.id
        LEFT JOIN leads l ON l.campaign_id = c.id
        $whereSql
        GROUP BY c.id, c.name, c.active, d.code, d.client_code, d.status, d.cpl, d.cpl_currency, r.revenue, r.currency, r.updated_at
        ORDER BY c.id DESC";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$totalDelivered = 0;
$totalsGenerated = [];
$totalsAllocatedAuto = [];
$totalsAllocatedManual = [];
foreach ($rows as $r) {
    $totalDelivered += (int)($r['delivered_month'] ?? 0);
    $gCur = strtoupper(trim((string)($r['cpl_currency'] ?? 'USD')));
    if ($gCur === '') $gCur = 'USD';
    $gVal = (float)($r['generated_month'] ?? 0);  
    if (!isset($totalsGenerated[$gCur])) $totalsGenerated[$gCur] = 0.0;
    $totalsGenerated[$gCur] += $gVal;
    if (!isset($totalsAllocatedAuto[$gCur])) $totalsAllocatedAuto[$gCur] = 0.0;
    $totalsAllocatedAuto[$gCur] += $gVal;

    $mCur = strtoupper(trim((string)($r['revenue_currency'] ?? $gCur)));
    if ($mCur === '') $mCur = $gCur;
    $mVal = (float)($r['revenue'] ?? 0);
    if ($mVal > 0) {
        if (!isset($totalsAllocatedManual[$mCur])) $totalsAllocatedManual[$mCur] = 0.0;
        $totalsAllocatedManual[$mCur] += $mVal;
    }
}
?>

<?php $pageTitle = 'Revenue'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 sales-workspace revenue-workspace">
  <?php $activeFilterCount = ($q !== '' ? 1 : 0) + ($campaignFilterId > 0 ? 1 : 0); ?>
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-currency-dollar"></i>Campaign Revenue</div>
      <h3 class="sales-page-title">Revenue</h3>
      <div class="sales-page-subtitle">Review accepted lead revenue by campaign, compare auto allocation against manual override values, and move directly into invoice creation for the selected month.</div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <span class="sales-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
        <?php if ($q !== ''): ?><span class="sales-chip"><i class="bi bi-search"></i><?php echo htmlspecialchars($q); ?></span><?php endif; ?>
        <?php if ($selectedCampaign): ?><span class="sales-chip"><i class="bi bi-megaphone"></i><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($selectedCampaign)); ?></span><?php endif; ?>
      </div>
    </div>
    <div class="sales-page-actions">
      <a class="btn btn-outline-primary btn-sm" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-columns-gap me-1"></i>Dashboard</a>
      <a class="btn btn-outline-secondary btn-sm" href="invoices.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-file-earmark-text me-1"></i>Invoices</a>
      <a class="btn btn-light border btn-sm" href="expenses.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-receipt-cutoff me-1"></i>Expenses</a>
    </div>
  </div>

  <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav revenue-nav mb-3">
    <li class="nav-item"><a class="nav-link" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>">Dashboard</a></li>
    <li class="nav-item"><a class="nav-link active" href="revenue.php?month=<?php echo urlencode($monthStr); ?>">Campaign Revenue</a></li>
    <li class="nav-item"><a class="nav-link" href="expenses.php?month=<?php echo urlencode($monthStr); ?>">Expenses</a></li>
    <li class="nav-item"><a class="nav-link" href="invoices.php?month=<?php echo urlencode($monthStr); ?>">Invoices</a></li>
    <li class="nav-item"><a class="nav-link" href="billto-templates.php">Bill To Templates</a></li>
    <li class="nav-item"><a class="nav-link" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>">Agent ROI</a></li>
  </ul>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="revenue-callout h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Accepted (Billable) Leads</div>
          <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-check2-circle"></i></span>
        </div>
        <div class="revenue-callout-value mt-1"><?php echo number_format($totalDelivered); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="revenue-callout h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Allocated (Accepted × CPL)</div>
          <span class="badge bg-success-subtle text-success border"><i class="bi bi-calculator"></i></span>
        </div>
        <div class="revenue-callout-value mt-1">
          <?php
            $gTopCur = 'USD'; $gTopVal = 0.0;
            foreach ($totalsAllocatedAuto as $c => $v) { if ($v > $gTopVal) { $gTopVal = (float)$v; $gTopCur = (string)$c; } }
            echo htmlspecialchars($gTopCur . ' ' . number_format($gTopVal, 2));
          ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="revenue-callout h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Manual Allocated</div>
          <span class="badge bg-warning-subtle text-warning border"><i class="bi bi-pencil-square"></i></span>
        </div>
        <div class="revenue-callout-value mt-1">
          <?php
            $aTopCur = 'USD'; $aTopVal = 0.0;
            foreach ($totalsAllocatedManual as $c => $v) { if ($v > $aTopVal) { $aTopVal = (float)$v; $aTopCur = (string)$c; } }
            echo htmlspecialchars($aTopCur . ' ' . number_format($aTopVal, 2));
          ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="revenue-callout h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Month</div>
          <span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-calendar3"></i></span>
        </div>
        <div class="revenue-callout-value mt-1"><?php echo htmlspecialchars($monthStr); ?></div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm sales-panel revenue-panel">
    <div class="card-body pb-0">
      <div class="revenue-filter-shell mb-3">
        <div class="revenue-filter-meta">
          <div>
            <div class="revenue-section-title">Campaign Revenue Filters</div>
            <div class="revenue-section-text">Refine billing results by month, campaign, or search terms to review allocation gaps and move directly into invoice creation.</div>
          </div>
          <div class="revenue-chip-row">
            <span class="revenue-chip"><i class="bi bi-list-check"></i><?php echo number_format(count($rows)); ?> campaign<?php echo count($rows) === 1 ? '' : 's'; ?></span>
            <span class="revenue-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
            <?php if ($activeFilterCount > 0): ?><span class="revenue-chip"><i class="bi bi-funnel"></i><?php echo number_format($activeFilterCount); ?> active filter<?php echo $activeFilterCount === 1 ? '' : 's'; ?></span><?php endif; ?>
          </div>
        </div>
        <form class="row g-3 align-items-end sales-filter-form revenue-inline-form" method="get">
          <div class="col-lg-4">
            <label class="form-label small text-muted mb-1">Search</label>
            <input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Campaign / Code / Client Code">
          </div>
          <div class="col-lg-4">
            <label class="form-label small text-muted mb-1">Campaign</label>
            <select class="form-select" name="campaign_id">
              <option value="">All Campaigns</option>
              <?php foreach ($campaignOptions as $campaign): ?>
                <?php $campaignId = (int)($campaign['id'] ?? 0); ?>
                <option value="<?php echo $campaignId; ?>" <?php echo $campaignId === $campaignFilterId ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($campaign)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-2 col-md-6">
            <label class="form-label small text-muted mb-1">Month</label>
            <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthStr); ?>">
          </div>
          <div class="col-lg-2 col-md-6 d-grid">
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search me-1"></i>Apply Filters</button>
          </div>
          <div class="col-12">
            <a class="btn btn-light border btn-sm" href="revenue.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-x-circle me-1"></i>Reset</a>
          </div>
        </form>
      </div>
    </div>
    <div class="table-responsive sales-table-responsive revenue-table-wrap revenue-table-wrap-padded">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Campaign</th>
            <th>Client</th>
            <th class="text-end">Accepted</th>
            <th class="text-end">Allocated (Auto)</th>
            <th class="text-end">Manual Allocated</th>
            <th class="text-end">Variance</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No campaigns found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $del = (int)($r['delivered_month'] ?? 0);
                $gCur = strtoupper(trim((string)($r['cpl_currency'] ?? 'USD')));
                if ($gCur === '') $gCur = 'USD';
                $auto = (float)($r['generated_month'] ?? 0);
                $mCur = strtoupper(trim((string)($r['revenue_currency'] ?? $gCur)));
                if ($mCur === '') $mCur = $gCur;
                $manual = (float)($r['revenue'] ?? 0);
                $variance = $manual > 0 ? ($manual - $auto) : 0.0;
                $status = (string)($r['status'] ?? '');
                $statusClass = $status === 'Active' ? 'is-paid' : 'is-draft';
              ?>
              <tr>
                <td>
                  <div class="revenue-entity-block">
                    <div class="revenue-entity-title"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></div>
                    <div class="revenue-entity-subline">
                      <?php if (!empty($r['code'])): ?><span class="revenue-entity-chip">Campaign</span><?php endif; ?>
                      <span><?php echo !empty($r['code']) ? htmlspecialchars((string)$r['code']) : 'Code not set'; ?></span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="revenue-entity-block">
                    <div class="revenue-entity-title"><?php echo htmlspecialchars((string)($r['client_code'] ?? '')); ?></div>
                    <div class="revenue-entity-subline">
                      <span class="revenue-entity-chip">Client</span>
                      <span><?php echo $status !== '' ? '' : 'Status not set'; ?></span>
                    </div>
                  </div>
                  <?php if ($status !== ''): ?>
                    <div class="mt-1"><span class="revenue-status-badge <?php echo htmlspecialchars($statusClass); ?>"><span class="revenue-status-dot"></span><?php echo htmlspecialchars($status); ?></span></div>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?php echo number_format($del); ?></td>
                <td class="text-end fw-semibold"><?php echo $del > 0 ? htmlspecialchars($gCur . ' ' . number_format($auto, 2)) : '<span class="text-muted">—</span>'; ?></td>
                <td class="text-end">
                  <?php if ($manual > 0): ?>
                    <?php echo htmlspecialchars($mCur . ' ' . number_format($manual, 2)); ?>
                    <div class="text-muted small">Override</div>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                    <div class="text-muted small">Auto used</div>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?php if ($manual > 0): ?>
                    <?php echo htmlspecialchars($mCur . ' ' . number_format($variance, 2)); ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <form method="post" class="d-inline-block">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="save_revenue">
                    <input type="hidden" name="campaign_id" value="<?php echo (int)$r['id']; ?>">
                    <div class="revenue-pill-actions">
                      <input class="form-control form-control-sm" style="width:130px" name="revenue" value="<?php echo htmlspecialchars((string)($r['revenue'] ?? '')); ?>" placeholder="Manual">
                      <input class="form-control form-control-sm" style="width:88px" name="currency" value="<?php echo htmlspecialchars((string)($r['revenue_currency'] ?? $gCur)); ?>" placeholder="USD">
                      <button class="revenue-pill-btn revenue-pill-btn-primary" type="submit" title="Save revenue"><i class="bi bi-check2"></i><span>Save</span></button>
                      <a class="revenue-pill-btn" href="invoices?month=<?php echo urlencode($monthStr); ?>&campaign_id=<?php echo (int)$r['id']; ?>" title="Open invoices"><i class="bi bi-receipt"></i><span>Invoice</span></a>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="revenue-stat-note">Campaign revenue is fed by accepted leads and campaign CPL from `campaign_details`, while manual values save into `campaign_revenue` and link straight into the invoice flow.</div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
