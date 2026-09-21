<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.view', ['admin','vendor_admin','vendor_user']);
ensureCsrfToken();

$user = getCurrentUser();
$vendorId = (int)($user['vendor_id'] ?? 0);
if (isAdmin()) $vendorId = (int)($_GET['vendor_id'] ?? $vendorId);
$campaignId = (int)($_GET['id'] ?? 0);
if ($campaignId <= 0) { header('Location: vendor-campaigns'); exit; }
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
$stmt = $conn->prepare("
    SELECT c.id, c.name, d.code, d.status, d.start_date, d.end_date, d.total_leads, d.delivery_format, d.campaign_type, d.pacing_type, d.pacing_count, d.instruction,
           d.script_path, d.tal_path, d.suppression_path,
           m.vendor_cpl, m.vendor_cpl_currency, m.uploads_enabled
    FROM campaigns c
    JOIN campaign_details d ON d.campaign_id = c.id
    JOIN vendor_campaign_map m ON m.campaign_id = c.id
    WHERE c.id = ? AND m.vendor_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $campaignId, $vendorId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) { header('Location: vendor-campaigns'); exit; }

$form = getFormForCampaign($campaignId);
$fields = (array)(($form['schema']['fields'] ?? []) ?: []);
$additional = [];
$rs = $conn->query("SELECT file_title AS file_name, file_path, created_at AS uploaded_at FROM campaign_additional_files WHERE campaign_id = ".(int)$campaignId." ORDER BY created_at DESC");
if ($rs) $additional = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$paths = function(string $path): string {
    $p = trim($path);
    if ($p === '') return '<span class="text-muted">—</span>';
    $href = '';
    if (preg_match('/^(uploads\/|assets\/)/i', $p)) $href = '../../' . ltrim($p, '/');
    if ($href !== '') {
        return '<a class="text-decoration-none" href="'.htmlspecialchars($href).'" target="_blank" rel="noopener" title="'.htmlspecialchars($p).'"><span class="d-inline-block text-truncate" style="max-width: 260px;">'.htmlspecialchars($p).'</span></a>';
    }
    return '<span class="d-inline-block text-truncate text-muted" style="max-width: 260px;" title="'.htmlspecialchars($p).'">'.htmlspecialchars($p).'</span>';
};

$pageTitle = 'Campaign Details';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1"><?php echo htmlspecialchars($row['name'] ?? ''); ?></div>
      <div class="text-muted small"><?php echo htmlspecialchars($row['code'] ?? ''); ?></div>
    </div>
    <div class="d-flex gap-2">
      <?php if (!empty($row['uploads_enabled'])): ?>
        <a class="btn btn-primary btn-sm" href="../leads/bulk-upload.php?campaign_id=<?php echo (int)$campaignId; ?>"><i class="bi bi-upload me-1"></i>Upload</a>
      <?php endif; ?>
      <a class="btn btn-light border btn-sm" href="vendor-campaigns.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Overview</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <div class="text-muted small">Status</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($row['status'] ?? ''); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Allocation</div>
              <div class="fw-semibold"><?php echo number_format((int)($row['total_leads'] ?? 0)); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">CPL</div>
              <div class="fw-semibold"><?php echo number_format((float)($row['vendor_cpl'] ?? 0), 2); ?> <span class="text-muted small"><?php echo htmlspecialchars($row['vendor_cpl_currency'] ?? ''); ?></span></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Delivery</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($row['delivery_format'] ?? ''); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Type</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($row['campaign_type'] ?? ''); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Pacing</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($row['pacing_type'] ?? ''); ?> <?php echo (int)($row['pacing_count'] ?? 0); ?></div>
            </div>
          </div>
          <hr class="my-3">
          <div class="fw-semibold mb-2">Instructions</div>
          <div class="text-muted"><?php echo nl2br(htmlspecialchars((string)($row['instruction'] ?? ''))); ?></div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light fw-semibold">Campaign Form Requirements</div>
        <div class="card-body">
          <?php if (empty($fields)): ?>
            <div class="text-muted">No form assigned.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Field</th>
                    <th>Type</th>
                    <th class="text-center">Required</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($fields as $f): ?>
                    <?php
                      $lbl = (string)($f['label'] ?? ($f['key'] ?? ''));
                      $type = (string)($f['type'] ?? 'text');
                      $req = !empty($f['required']);
                    ?>
                    <tr>
                      <td class="fw-semibold"><?php echo htmlspecialchars($lbl); ?></td>
                      <td class="text-muted small"><?php echo htmlspecialchars($type); ?></td>
                      <td class="text-center"><?php echo $req ? '<span class="badge bg-danger-subtle text-danger border">Yes</span>' : '<span class="badge bg-light text-muted border">No</span>'; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Uploads</div>
        <div class="card-body">
          <?php if (!empty($row['uploads_enabled'])): ?>
            <div class="text-muted small mb-2">Uploads are enabled for this campaign.</div>
            <a class="btn btn-primary btn-sm" href="../leads/bulk-upload.php?campaign_id=<?php echo (int)$campaignId; ?>"><i class="bi bi-upload me-1"></i>Bulk Upload</a>
          <?php else: ?>
            <div class="text-muted">Uploads are disabled by Admin.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light fw-semibold">Upload Readiness</div>
        <div class="card-body">
          <?php
            $hasForm = !empty($fields);
            $requiredCount = 0;
            foreach ($fields as $f) { if (!empty($f['required'])) $requiredCount++; }
            $hasInstructions = trim((string)($row['instruction'] ?? '')) !== '';
            $hasScript = trim((string)($row['script_path'] ?? '')) !== '';
            $hasTAL = trim((string)($row['tal_path'] ?? '')) !== '';
            $hasSupp = trim((string)($row['suppression_path'] ?? '')) !== '';
          ?>
          <div class="d-grid gap-2">
            <div class="d-flex justify-content-between align-items-center">
              <span class="small">Uploads Enabled</span>
              <?php echo !empty($row['uploads_enabled']) ? '<span class="badge bg-success-subtle text-success border">Yes</span>' : '<span class="badge bg-secondary-subtle text-secondary border">No</span>'; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small">Form Assigned</span>
              <?php echo $hasForm ? '<span class="badge bg-success-subtle text-success border">Yes</span>' : '<span class="badge bg-danger-subtle text-danger border">No</span>'; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small">Required Fields</span>
              <span class="badge bg-light text-dark border"><?php echo (int)$requiredCount; ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small">Instructions</span>
              <?php echo $hasInstructions ? '<span class="badge bg-success-subtle text-success border">Yes</span>' : '<span class="badge bg-warning-subtle text-warning border">Missing</span>'; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small">Script</span>
              <?php echo $hasScript ? '<span class="badge bg-success-subtle text-success border">Attached</span>' : '<span class="badge bg-light text-muted border">—</span>'; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small">TAL</span>
              <?php echo $hasTAL ? '<span class="badge bg-success-subtle text-success border">Attached</span>' : '<span class="badge bg-light text-muted border">—</span>'; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small">Suppression</span>
              <?php echo $hasSupp ? '<span class="badge bg-success-subtle text-success border">Attached</span>' : '<span class="badge bg-light text-muted border">—</span>'; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light fw-semibold">Files</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">Name</th>
                  <th>File</th>
                  <th class="text-nowrap pe-3">Uploaded</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $baseFiles = [
                    ['Script', (string)($row['script_path'] ?? '')],
                    ['TAL', (string)($row['tal_path'] ?? '')],
                    ['Suppression', (string)($row['suppression_path'] ?? '')],
                  ];
                ?>
                <?php foreach ($baseFiles as $bf): ?>
                  <tr>
                    <td class="ps-3 fw-semibold"><?php echo htmlspecialchars($bf[0]); ?></td>
                    <td class="small"><?php echo $paths((string)$bf[1]); ?></td>
                    <td class="text-muted small pe-3">—</td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!empty($additional)): ?>
                  <?php foreach ($additional as $f): ?>
                    <tr>
                      <td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($f['file_name'] ?? 'File')); ?></td>
                      <td class="small"><?php echo $paths((string)($f['file_path'] ?? '')); ?></td>
                      <td class="text-muted small pe-3"><?php echo htmlspecialchars(substr((string)($f['uploaded_at'] ?? ''), 0, 10)); ?></td>
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
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
