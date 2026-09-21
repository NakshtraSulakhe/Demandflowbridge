<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('revenue.access', ['admin','vendor_admin','vendor_user']);
ensureCsrfToken();

$user = getCurrentUser();
$vendorId = (int)($user['vendor_id'] ?? 0);
if (isAdmin()) {
    $vendorId = (int)($_GET['vendor_id'] ?? $vendorId);
}
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

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$billableStatus = 'Accepted';

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT c.id, c.name, d.code, m.vendor_cpl, m.vendor_cpl_currency FROM campaigns c JOIN campaign_details d ON d.campaign_id=c.id JOIN vendor_campaign_map m ON m.campaign_id=c.id WHERE m.vendor_id=? ORDER BY c.name");
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$stats = [];
$totalApproved = 0;
$totalRevenue = 0.0;
foreach ($campaigns as $c) {
    $cid = (int)$c['id'];
    $where = "campaign_id = ? AND vendor_id = ? AND client_delivery_status = ?";
    $params = [$cid, $vendorId, $billableStatus];
    $types = 'iis';
    if ($dateFrom !== '') { $where .= " AND created_at >= ?"; $params[] = $dateFrom.' 00:00:00'; $types .= 's'; }
    if ($dateTo !== '') { $where .= " AND created_at <= ?"; $params[] = $dateTo.' 23:59:59'; $types .= 's'; }
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM leads WHERE $where");
    $stmt2->bind_param($types, ...$params);
    $stmt2->execute();
    $cnt = (int)($stmt2->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt2->close();
    $rev = $cnt * (float)($c['vendor_cpl'] ?? 0);
    $stats[$cid] = ['approved' => $cnt, 'revenue' => $rev, 'currency' => (string)($c['vendor_cpl_currency'] ?? 'USD')];
    $totalApproved += $cnt;
    $totalRevenue += $rev;
}

$params = [$vendorId, $billableStatus];
$types = 'is';
$dateWhere = '';
if ($dateFrom !== '') { $dateWhere .= " AND l.created_at >= ?"; $params[] = $dateFrom.' 00:00:00'; $types .= 's'; }
if ($dateTo !== '') { $dateWhere .= " AND l.created_at <= ?"; $params[] = $dateTo.' 23:59:59'; $types .= 's'; }

if (($_GET['export'] ?? '') === 'csv') {
    @set_time_limit(0);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    $fn = 'vendor_revenue_'.$vendorId.'_'.date('Ymd_His').'.csv';
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Campaign', 'Code', 'Client Delivery Status', 'Leads', 'CPL', 'Currency', 'Revenue', 'From', 'To']);
    foreach ($campaigns as $c) {
        $cid = (int)$c['id'];
        $s = $stats[$cid] ?? ['approved'=>0,'revenue'=>0];
        fputcsv($out, [
            (string)($c['name'] ?? ''),
            (string)($c['code'] ?? ''),
            $billableStatus,
            (int)($s['approved'] ?? 0),
            (float)($c['vendor_cpl'] ?? 0),
            (string)($c['vendor_cpl_currency'] ?? ''),
            (float)($s['revenue'] ?? 0),
            $dateFrom,
            $dateTo,
        ]);
    }
    fputcsv($out, ['TOTAL', '', $billableStatus, $totalApproved, '', 'USD', $totalRevenue, $dateFrom, $dateTo]);
    fclose($out);
    exit;
}

$daily = ['labels'=>[], 'leads'=>[], 'revenue'=>[]];
$stmt = $conn->prepare("
    SELECT DATE(l.created_at) AS dt, COUNT(*) AS leads, SUM(m.vendor_cpl) AS revenue
    FROM leads l
    JOIN vendor_campaign_map m ON m.campaign_id = l.campaign_id AND m.vendor_id = l.vendor_id
    WHERE l.vendor_id = ? AND l.client_delivery_status = ? $dateWhere
    GROUP BY dt
    ORDER BY dt
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rs = $stmt->get_result();
while ($r = $rs->fetch_assoc()) {
    $daily['labels'][] = (string)($r['dt'] ?? '');
    $daily['leads'][] = (int)($r['leads'] ?? 0);
    $daily['revenue'][] = (float)($r['revenue'] ?? 0);
}
$stmt->close();

$weekly = ['labels'=>[], 'leads'=>[], 'revenue'=>[]];
$stmt = $conn->prepare("
    SELECT YEARWEEK(l.created_at, 1) AS yw, MIN(DATE(l.created_at)) AS week_start, COUNT(*) AS leads, SUM(m.vendor_cpl) AS revenue
    FROM leads l
    JOIN vendor_campaign_map m ON m.campaign_id = l.campaign_id AND m.vendor_id = l.vendor_id
    WHERE l.vendor_id = ? AND l.client_delivery_status = ? $dateWhere
    GROUP BY yw
    ORDER BY yw
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rs = $stmt->get_result();
while ($r = $rs->fetch_assoc()) {
    $lbl = (string)($r['week_start'] ?? '');
    $weekly['labels'][] = $lbl !== '' ? $lbl : (string)($r['yw'] ?? '');
    $weekly['leads'][] = (int)($r['leads'] ?? 0);
    $weekly['revenue'][] = (float)($r['revenue'] ?? 0);
}
$stmt->close();

$pageTitle = 'Vendor Revenue';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Vendor Revenue</div>
      <div class="text-muted small">Revenue based on billable leads (Client Delivery Status: <?php echo htmlspecialchars($billableStatus); ?>)</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="../dashboard/vendor-dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <a class="btn btn-light border btn-sm" href="vendor-campaigns.php"><i class="bi bi-megaphone me-1"></i>Campaigns</a>
      <a class="btn btn-outline-primary btn-sm" href="?vendor_id=<?php echo (int)$vendorId; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&export=csv"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <?php if (isAdmin()): ?>
          <div class="col-md-3">
            <label class="form-label small text-muted">Vendor ID</label>
            <input class="form-control form-control-sm" name="vendor_id" value="<?php echo (int)$vendorId; ?>">
          </div>
        <?php endif; ?>
        <div class="col-md-2">
          <label class="form-label small text-muted">Billable Status</label>
          <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($billableStatus); ?>" readonly>
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">From</label>
          <input class="form-control form-control-sm" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">To</label>
          <input class="form-control form-control-sm" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="card border-0 shadow-sm text-center p-3">
        <div class="text-muted small fw-semibold text-uppercase">Total Billable</div>
        <div class="h3 mb-0 mt-1"><?php echo number_format($totalApproved); ?></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card border-0 shadow-sm text-center p-3">
        <div class="text-muted small fw-semibold text-uppercase">Total Revenue</div>
        <div class="h3 mb-0 mt-1"><?php echo number_format($totalRevenue, 2); ?> <span class="small text-muted">USD</span></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Daily</div>
        <div class="card-body">
          <canvas id="revDailyChart" height="120"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Weekly</div>
        <div class="card-body">
          <canvas id="revWeeklyChart" height="120"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light fw-semibold">Revenue by Campaign</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Campaign</th>
            <th>Code</th>
            <th class="text-end">Leads</th>
            <th class="text-end">CPL</th>
            <th class="text-end pe-3">Revenue</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($campaigns)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No campaigns.</td></tr>
          <?php else: foreach ($campaigns as $c): $s = $stats[(int)$c['id']] ?? ['approved'=>0,'revenue'=>0,'currency'=>'USD']; ?>
            <tr>
              <td class="ps-3"><?php echo htmlspecialchars($c['name'] ?? ''); ?></td>
              <td class="text-muted small"><?php echo htmlspecialchars($c['code'] ?? ''); ?></td>
              <td class="text-end"><?php echo number_format((int)$s['approved']); ?></td>
              <td class="text-end"><?php echo number_format((float)($c['vendor_cpl'] ?? 0), 2); ?> <span class="text-muted small"><?php echo htmlspecialchars($c['vendor_cpl_currency'] ?? ''); ?></span></td>
              <td class="text-end pe-3 fw-semibold"><?php echo number_format((float)$s['revenue'], 2); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
  if (!window.Chart) return;
  const daily = <?php echo json_encode($daily, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const weekly = <?php echo json_encode($weekly, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  function render(el, data) {
    if (!el || !data || !Array.isArray(data.labels)) return;
    new Chart(el, {
      data: {
        labels: data.labels,
        datasets: [
          { type: 'bar', label: 'Leads', data: data.leads || [], yAxisID: 'y', backgroundColor: 'rgba(59, 130, 246, 0.55)' },
          { type: 'line', label: 'Revenue', data: data.revenue || [], yAxisID: 'y1', borderColor: 'rgba(34, 197, 94, 0.9)', backgroundColor: 'rgba(34, 197, 94, 0.25)', tension: 0.3 }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }
        }
      }
    });
  }

  render(document.getElementById('revDailyChart'), daily);
  render(document.getElementById('revWeeklyChart'), weekly);
})();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
