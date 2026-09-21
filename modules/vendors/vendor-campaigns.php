<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.view', ['admin','vendor_admin','vendor_user']);
ensureCsrfToken();

$user = getCurrentUser();
$role = (string)($user['role'] ?? '');
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

$conn = getDbConnection();
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, (int)($_GET['per_page'] ?? 25));
$offset = ($page - 1) * $perPage;

$params = [];
$types = '';
$where = "WHERE m.vendor_id = ?";
$params[] = $vendorId; $types .= 'i';
if ($q !== '') {
    $where .= " AND (c.name LIKE ? OR d.code LIKE ?)";
    $like = '%'.$q.'%';
    $params[] = $like; $types .= 's';
    $params[] = $like; $types .= 's';
}

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM campaigns c JOIN campaign_details d ON d.campaign_id=c.id JOIN vendor_campaign_map m ON m.campaign_id=c.id $where");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();
$pages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT c.id, c.name, d.code, d.status, d.start_date, d.end_date, d.total_leads, m.vendor_cpl, m.vendor_cpl_currency, m.uploads_enabled,
               d.status_updated_by, (SELECT full_name FROM users WHERE id = d.status_updated_by LIMIT 1) AS status_updated_by_name
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        JOIN vendor_campaign_map m ON m.campaign_id = c.id
        $where
        ORDER BY c.name
        LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$pageTitle = 'Vendor Campaigns';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Vendor Campaigns</div>
      <div class="text-muted small">Assigned campaigns and delivery settings</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="../dashboard/vendor-dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <a class="btn btn-primary btn-sm" href="../leads/bulk-upload.php"><i class="bi bi-upload me-1"></i>Bulk Upload</a>
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
        <div class="col-md-6">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name or code">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">Rows</label>
          <select class="form-select form-select-sm" name="per_page">
            <?php foreach ([25,50,100] as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-grid">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light fw-semibold">Assigned Campaigns</div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Campaign</th>
            <th class="text-muted">Code</th>
            <th>Status</th>
            <th>Allocation</th>
            <th>CPL</th>
            <th>Uploads</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No campaigns.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold"><?php echo htmlspecialchars($r['name'] ?? ''); ?></div>
                <div class="text-muted small"><?php echo htmlspecialchars((string)$r['start_date']); ?> – <?php echo htmlspecialchars((string)$r['end_date']); ?></div>
              </td>
              <td class="text-muted small"><?php echo htmlspecialchars($r['code'] ?? ''); ?></td>
              <td>
                <span class="badge <?php echo ($r['status']==='Live'?'bg-success-subtle text-success':'bg-secondary-subtle text-secondary'); ?> border"><?php echo htmlspecialchars($r['status'] ?? ''); ?></span>
                <?php if ($r['status'] === 'Pause' && !empty($r['status_updated_by_name'])): ?>
                  <div class="x-small text-muted mt-1" style="font-size: 0.7rem;">by <?php echo htmlspecialchars($r['status_updated_by_name']); ?></div>
                <?php endif; ?>
              </td>
              <td><?php echo number_format((int)($r['total_leads'] ?? 0)); ?></td>
              <td><?php echo number_format((float)($r['vendor_cpl'] ?? 0), 2); ?> <span class="text-muted small"><?php echo htmlspecialchars($r['vendor_cpl_currency'] ?? ''); ?></span></td>
              <td><?php echo !empty($r['uploads_enabled']) ? '<span class="badge bg-success-subtle text-success border">Enabled</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Disabled</span>'; ?></td>
              <td class="text-end pe-3">
                <div class="d-flex justify-content-end gap-1">
                  <?php if (!empty($r['uploads_enabled'])): ?>
                    <a class="btn btn-outline-primary btn-xs" href="../leads/bulk-upload.php?campaign_id=<?php echo (int)$r['id']; ?>" title="Upload"><i class="bi bi-upload"></i></a>
                  <?php endif; ?>
                  <a class="btn btn-outline-secondary btn-xs" href="vendor-campaign-details.php?id=<?php echo (int)$r['id']; ?>" title="Details"><i class="bi bi-eye"></i></a>
                  <a class="btn btn-outline-secondary btn-xs" href="vendor-leads.php?campaign_id=<?php echo (int)$r['id']; ?>" title="Leads"><i class="bi bi-list-ul"></i></a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-light d-flex justify-content-between align-items-center">
      <div class="text-muted small">Total: <?php echo (int)$total; ?></div>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?vendor_id=<?php echo (int)$vendorId; ?>&q=<?php echo urlencode($q); ?>&per_page=<?php echo (int)$perPage; ?>&page=<?php echo max(1, $page-1); ?>">Prev</a></li>
          <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $pages; ?></span></li>
          <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="?vendor_id=<?php echo (int)$vendorId; ?>&q=<?php echo urlencode($q); ?>&per_page=<?php echo (int)$perPage; ?>&page=<?php echo min($pages, $page+1); ?>">Next</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
