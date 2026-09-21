<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('leads.view', ['admin','vendor_admin','vendor_user']);
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

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(25, (int)($_GET['per_page'] ?? 25));

$filters = [];
$filters['vendor_id'] = $vendorId;
if ($campaignId > 0) $filters['campaign_id'] = $campaignId;
if ($q !== '') $filters['search'] = $q;

$data = getLeads($filters, $perPage, $page);
$rows = $data['leads'] ?? [];
$total = (int)($data['total'] ?? 0);
$pages = (int)($data['totalPages'] ?? 1);

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT c.id, c.name, d.client_code, d.code FROM campaigns c JOIN vendor_campaign_map m ON m.campaign_id=c.id LEFT JOIN campaign_details d ON d.campaign_id = c.id WHERE m.vendor_id=? ORDER BY c.name");
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$pageTitle = 'Vendor Leads';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Vendor Leads</div>
      <div class="text-muted small">Leads submitted by your team</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="vendor-campaigns.php"><i class="bi bi-megaphone me-1"></i>Campaigns</a>
      <a class="btn btn-primary btn-sm" href="../leads/bulk-upload.php"><i class="bi bi-upload me-1"></i>Bulk Upload</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <?php if (isAdmin()): ?>
          <div class="col-md-3">
            <label class="form-label small text-muted">Vendor ID</label>
            <input class="form-control form-control-sm" name="vendor_id" value="<?php echo (int)$vendorId; ?>">
          </div>
        <?php endif; ?>
        <div class="col-md-4">
          <label class="form-label small text-muted">Campaign</label>
          <select class="form-select form-select-sm" name="campaign_id">
            <option value="">All</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$campaignId === (int)$c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="ID, email, company, name">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">Rows</label>
          <select class="form-select form-select-sm" name="per_page">
            <?php foreach ([25,50,100,500] as $n): ?>
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
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
      <div class="fw-semibold">Leads</div>
      <div class="text-muted small">Total: <?php echo (int)$total; ?></div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Date</th>
              <th>Lead</th>
              <th>Email</th>
              <th>Company</th>
              <th>Campaign</th>
              <th>QA</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No leads found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td class="ps-3"><?php echo htmlspecialchars(substr((string)($r['created_at'] ?? ''), 0, 10)); ?></td>
                <td class="fw-semibold"><?php echo htmlspecialchars(((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')) ?: '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['company_name'] ?? '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['campaign_name'] ?? '—'); ?></td>
                <td>
                  <?php
                    $qa = (string)($r['qa_status'] ?? 'Pending');
                    $cls = $qa==='Qualified' || $qa==='Approved' ? 'bg-success-subtle text-success' : ($qa==='Disqualified' || $qa==='Rejected' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning');
                  ?>
                  <span class="badge <?php echo $cls; ?> border"><?php echo htmlspecialchars($qa); ?></span>
                </td>
                <td class="text-end pe-3">
                  <a class="btn btn-outline-secondary btn-xs" href="../leads/lead-details.php?id=<?php echo (int)($r['id'] ?? 0); ?>"><i class="bi bi-eye"></i></a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-light d-flex justify-content-between align-items-center">
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?vendor_id=<?php echo (int)$vendorId; ?>&campaign_id=<?php echo (int)$campaignId; ?>&q=<?php echo urlencode($q); ?>&per_page=<?php echo (int)$perPage; ?>&page=<?php echo max(1, $page-1); ?>">Prev</a></li>
          <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $pages; ?></span></li>
          <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="?vendor_id=<?php echo (int)$vendorId; ?>&campaign_id=<?php echo (int)$campaignId; ?>&q=<?php echo urlencode($q); ?>&per_page=<?php echo (int)$perPage; ?>&page=<?php echo min($pages, $page+1); ?>">Next</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
