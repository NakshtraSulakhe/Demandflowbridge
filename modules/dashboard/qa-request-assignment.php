<?php
require __DIR__ . '/../qa/request-assignment.php';
exit;
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $text = trim((string)($_POST['message'] ?? ''));
        if ($text === '') {
            $message = 'Please enter a request message.';
            $messageType = 'danger';
        } else {
            $conn = getDbConnection();
            $stmt = $conn->prepare("INSERT INTO qa_assignment_requests (requested_by, message, status, created_at) VALUES (?,?, 'Open', NOW())");
            if ($stmt) {
                $stmt->bind_param('is', $userId, $text);
                $stmt->execute();
                $stmt->close();
                $message = 'Request submitted. Admin will review and assign campaigns.';
                $messageType = 'success';
            } else {
                $message = 'Unable to submit request.';
                $messageType = 'danger';
            }
        }
    }
}

$requests = [];
try {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT id, message, status, created_at, resolved_at
        FROM qa_assignment_requests
        WHERE requested_by = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} catch (Throwable $e) {}

$role = (string)($user['role'] ?? '');
$visible = getQaVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
$assignedCampaigns = [];
if ($visible !== null) {
    $ids = array_keys($visible);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT c.id, c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id=c.id WHERE c.id IN ($in) ORDER BY c.name");
        if ($stmt) {
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
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
    <a class="btn btn-light border btn-sm" href="qa-audit.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
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
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Status</th>
                <th>Message</th>
                <th class="text-muted">Created</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($requests)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No requests yet.</td></tr>
              <?php else: ?>
                <?php foreach ($requests as $r): ?>
                  <?php
                    $st = (string)($r['status'] ?? 'Open');
                    $cls = $st === 'Resolved' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
                  ?>
                  <tr>
                    <td><span class="badge border <?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                    <td class="text-muted small"><?php echo htmlspecialchars((string)($r['message'] ?? '')); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('Y-m-d', strtotime((string)($r['created_at'] ?? 'now')))); ?></td>
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
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
