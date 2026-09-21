<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','client_admin','client_sdr']);
ensureCsrfToken();

$user = getCurrentUser();
$clientId = (int)($user['client_id'] ?? 0);
if (isAdmin()) $clientId = (int)($_GET['client_id'] ?? $clientId);
$campaignId = (int)($_GET['id'] ?? 0);
if ($campaignId <= 0) { header('Location: client-campaigns'); exit; }
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
$row = null;
if (clientOwnsCampaign($clientId, $campaignId)) {
  $stmt = $conn->prepare("
      SELECT c.id, c.name, d.code, d.status, d.start_date, d.end_date, d.total_leads, d.delivery_format, d.campaign_type, d.pacing_type, d.pacing_count, d.instruction
      FROM campaigns c
      JOIN campaign_details d ON d.campaign_id = c.id
      WHERE c.id = ?
      LIMIT 1
  ");
  $stmt->bind_param('i', $campaignId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}
if (!$row) { header('Location: client-campaigns'); exit; }

$form = getFormForCampaign($campaignId);
$fields = (array)(($form['schema']['fields'] ?? []) ?: []);

$perf = ['delivered'=>0];
$stmt = $conn->prepare("
    SELECT
      SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered
    FROM leads
    WHERE campaign_id = ?
");
$stmt->bind_param('i', $campaignId);
$stmt->execute();
$rowPerf = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
foreach ($perf as $k => $_) $perf[$k] = (int)($rowPerf[$k] ?? 0);

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
      <a class="btn btn-light border btn-sm" href="client-campaigns.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
      <?php if (hasRole('client_admin')): ?>
        <a class="btn btn-primary btn-sm" href="../campaigns/campaign-edit.php?id=<?php echo (int)$campaignId; ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
      <?php endif; ?>
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
        <div class="card-header bg-light fw-semibold">Campaign Form Questions</div>
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
      <div class="row g-3">
        <div class="col-12">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-light fw-semibold">Delivered Leads</div>
            <div class="card-body">
              <div class="border rounded p-2 text-center">
                <div class="text-muted small">Delivered</div>
                <div class="h5 mb-0 text-success"><?php echo number_format((int)($perf['delivered'] ?? 0)); ?></div>
              </div>
              <div class="mt-3">
                <a class="btn btn-outline-secondary btn-sm w-100" href="client-leads.php?campaign_id=<?php echo (int)$campaignId; ?>"><i class="bi bi-list-ul me-1"></i>View Delivered Leads</a>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Leads</div>
        <div class="card-body">
          <div class="text-muted small mb-2">View campaign leads with filters.</div>
          <a class="btn btn-outline-secondary btn-sm" href="client-leads.php?campaign_id=<?php echo (int)$campaignId; ?>"><i class="bi bi-list-ul me-1"></i>View Leads</a>
        </div>
      </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
