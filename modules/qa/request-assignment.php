<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

$message = '';
$messageType = 'success';
$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $msg = trim((string)($_POST['message'] ?? ''));
        if ($msg === '') {
            $message = 'Message is required.';
            $messageType = 'danger';
        } else {
            $stmt = $conn->prepare("INSERT INTO qa_assignment_requests (requested_by, message, status, created_at) VALUES (?, ?, 'Open', NOW())");
            if ($stmt) {
                $stmt->bind_param('is', $userId, $msg);
                $stmt->execute();
                $stmt->close();
                $message = 'Request submitted.';
                $messageType = 'success';
            } else {
                $message = 'Failed to submit request.';
                $messageType = 'danger';
            }
        }
    }
}

$requests = [];
try {
    $stmt = $conn->prepare("
        SELECT r.*, u.full_name AS resolved_name, u.role AS resolved_role, u.job_title AS resolved_job_title
        FROM qa_assignment_requests r
        LEFT JOIN users u ON u.id = r.resolved_by
        WHERE r.requested_by = ?
        ORDER BY r.created_at DESC
        LIMIT 15
    ");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} catch (Throwable $e) {}

$visible = getQaVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
$assignedCampaigns = [];
if ($visible !== null) {
    $ids = array_keys($visible);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT c.id, c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id=c.id WHERE c.id IN ($in) ORDER BY c.name");
        if ($stmt) {
            mysqliBindParams($stmt, str_repeat('i', count($ids)), $ids);
            $stmt->execute();
            $assignedCampaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }
    }
}
?>

<?php $pageTitle = 'Request Assignment'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Request Campaign Assignment</h3>
      <div class="text-muted small">Send a request to Admin for campaign allocation.</div>
    </div>
    <a class="btn btn-light border btn-sm" href="audit"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <?php if ($visible !== null): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-light fw-semibold">Current Assignments</div>
      <div class="card-body">
        <?php if (empty($assignedCampaigns)): ?>
          <div class="text-muted">No campaigns are assigned to your QA account yet.</div>
        <?php else: ?>
          <div class="text-muted small mb-2">You are assigned to <?php echo number_format(count($assignedCampaigns)); ?> campaign(s).</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($assignedCampaigns as $c): ?>
              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)($c['code'] ?? '')); ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">New Request</div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-3">
              <label class="form-label">Message</label>
              <textarea class="form-control" name="message" rows="4" placeholder="Example: Please assign me campaigns for client code 1010 (campaign TG-1010-001) for QA review."></textarea>
              <div class="text-muted small mt-1">Include campaign code(s) or client code(s) you need.</div>
            </div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i>Submit Request</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">My Requests</div>
        <div class="card-body">
          <?php if (empty($requests)): ?>
            <div class="text-muted">No requests submitted yet.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($requests as $r): ?>
                <div class="list-group-item px-0">
                  <div class="d-flex align-items-center justify-content-between">
                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars(date('Y-m-d', strtotime((string)($r['created_at'] ?? 'now')))); ?></div>
                  </div>
                  <div class="text-muted small mt-1"><?php echo nl2br(htmlspecialchars((string)($r['message'] ?? ''))); ?></div>
                  <?php if ((string)($r['status'] ?? '') === 'Resolved'): ?>
                    <div class="text-muted small mt-1">Resolved by <?php echo htmlspecialchars(formatUserNameWithRole((string)($r['resolved_name'] ?? ''), (string)($r['resolved_role'] ?? ''), (string)($r['resolved_job_title'] ?? ''))); ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
