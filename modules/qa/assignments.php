<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','qa_director','qa_manager']);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

$visible = getQaVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
$conn = getDbConnection();

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$message = '';
$messageType = 'success';

$openRequests = [];
if (isAdmin()) {
    try {
        $rs = $conn->query("
            SELECT r.id, r.message, r.created_at, u.full_name, u.role, u.job_title
            FROM qa_assignment_requests r
            LEFT JOIN users u ON u.id = r.requested_by
            WHERE r.status = 'Open'
            ORDER BY r.created_at DESC
            LIMIT 20
        ");
        if ($rs) $openRequests = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

$campaigns = [];
if ($visible === null) {
    $rs = $conn->query("SELECT c.id, c.name, d.code, d.client_code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
    if ($rs) $campaigns = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
    $ids = array_keys($visible);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT c.id, c.name, d.code, d.client_code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id IN ($in) ORDER BY c.name");
        $types = str_repeat('i', count($ids));
        if ($stmt) {
            mysqliBindParams($stmt, $types, $ids);
            $stmt->execute();
            $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }
    }
}

if ($campaignId <= 0 && !empty($campaigns)) {
    $campaignId = (int)($campaigns[0]['id'] ?? 0);
}
if ($visible !== null && $campaignId > 0 && !isset($visible[$campaignId])) {
    $campaignId = 0;
}

$assignableUsers = getQaAssignableUsers($role);

$directorRoles = ['qa_director'];
$agentRoles = ['qa_agent','qa'];
$managerRoles = ['qa_manager'];
$canAssignManagers = ($role === 'admin' || $role === 'qa_director');
$canAssignDirectors = ($role === 'admin');

$assignedAgent = [];
$assignedManager = [];
$assignedDirector = [];
if ($campaignId > 0) {
    $rs = $conn->query("SELECT a.user_id, u.role FROM qa_campaign_assignments a LEFT JOIN users u ON u.id = a.user_id WHERE a.campaign_id = ".(int)$campaignId);
    $rows = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
    foreach ($rows as $r) {
        $uid = (int)($r['user_id'] ?? 0);
        $rRole = (string)($r['role'] ?? '');
        if ($uid <= 0) continue;
        if (in_array($rRole, $agentRoles, true)) $assignedAgent[$uid] = true;
        if (in_array($rRole, $managerRoles, true)) $assignedManager[$uid] = true;
        if (in_array($rRole, $directorRoles, true)) $assignedDirector[$uid] = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'resolve_request' && isAdmin()) {
            $rid = (int)($_POST['request_id'] ?? 0);
            if ($rid > 0) {
                $stmt = $conn->prepare("UPDATE qa_assignment_requests SET status='Resolved', resolved_by=?, resolved_at=NOW() WHERE id=?");
                if ($stmt) {
                    $stmt->bind_param('ii', $userId, $rid);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            header('Location: assignments');
            exit;
        }
        $prevAssigned = array_fill_keys(array_merge(array_keys($assignedDirector), array_keys($assignedManager), array_keys($assignedAgent)), true);
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            $message = 'Select a campaign.';
            $messageType = 'danger';
        } elseif ($visible !== null && !isset($visible[$campaignId])) {
            $message = 'Not allowed.';
            $messageType = 'danger';
        } else {
            $selectedAgents = $_POST['qa_agent_user_ids'] ?? [];
            $selectedManagers = $_POST['qa_manager_user_ids'] ?? [];
            $selectedDirectors = $_POST['qa_director_user_ids'] ?? [];

            $allowedIdsByRole = [];
            foreach ($assignableUsers as $u) {
                $uid = (int)($u['id'] ?? 0);
                $rRole = (string)($u['role'] ?? '');
                if ($uid <= 0) continue;
                $allowedIdsByRole[$uid] = $rRole;
            }

            $agentIds = [];
            if (is_array($selectedAgents)) {
                foreach ($selectedAgents as $sid) {
                    $sid = (int)$sid;
                    if ($sid <= 0) continue;
                    $rRole = (string)($allowedIdsByRole[$sid] ?? '');
                    if (!in_array($rRole, $agentRoles, true)) continue;
                    $agentIds[] = $sid;
                }
            }

            $managerIds = [];
            if ($canAssignManagers && is_array($selectedManagers)) {
                foreach ($selectedManagers as $sid) {
                    $sid = (int)$sid;
                    if ($sid <= 0) continue;
                    $rRole = (string)($allowedIdsByRole[$sid] ?? '');
                    if (!in_array($rRole, $managerRoles, true)) continue;
                    $managerIds[] = $sid;
                }
            }

            $directorIds = [];
            if ($canAssignDirectors && is_array($selectedDirectors)) {
                foreach ($selectedDirectors as $sid) {
                    $sid = (int)$sid;
                    if ($sid <= 0) continue;
                    $rRole = (string)($allowedIdsByRole[$sid] ?? '');
                    if (!in_array($rRole, $directorRoles, true)) continue;
                    $directorIds[] = $sid;
                }
            }

            $deleteRoles = $canAssignDirectors ? array_merge($directorRoles, $managerRoles, $agentRoles) : ($canAssignManagers ? array_merge($managerRoles, $agentRoles) : $agentRoles);
            $inR = implode(',', array_fill(0, count($deleteRoles), '?'));
            $typesR = str_repeat('s', count($deleteRoles));
            $stmtD = $conn->prepare("
                DELETE a FROM qa_campaign_assignments a
                JOIN users u ON u.id = a.user_id
                WHERE a.campaign_id = ? AND u.role IN ($inR)
            ");
            $paramsD = array_merge([(int)$campaignId], array_values($deleteRoles));
            mysqliBindParams($stmtD, 'i'.$typesR, $paramsD);
            $stmtD->execute();
            $stmtD->close();

            $stmtI = $conn->prepare("INSERT IGNORE INTO qa_campaign_assignments (campaign_id, user_id, assigned_by, assigned_at) VALUES (?,?,?,NOW())");
            foreach (array_merge($directorIds, $managerIds, $agentIds) as $uid) {
                $stmtI->bind_param('iii', $campaignId, $uid, $userId);
                $stmtI->execute();
            }
            $stmtI->close();

            $assignedAgent = array_fill_keys($agentIds, true);
            $assignedManager = array_fill_keys($managerIds, true);
            $assignedDirector = array_fill_keys($directorIds, true);

            $newAssigned = array_fill_keys(array_merge($directorIds, $managerIds, $agentIds), true);
            $added = array_values(array_diff(array_keys($newAssigned), array_keys($prevAssigned)));
            if (!empty($added)) {
                $stmtN = $conn->prepare("SELECT name FROM campaigns WHERE id = ? LIMIT 1");
                $campName = '';
                if ($stmtN) {
                    $stmtN->bind_param('i', $campaignId);
                    $stmtN->execute();
                    $campName = (string)(($stmtN->get_result()->fetch_assoc() ?: [])['name'] ?? '');
                    $stmtN->close();
                }
                $title = 'New campaign allocated';
                $msg = ($campName !== '' ? $campName : ('Campaign #' . $campaignId)) . ' assigned to you (QA).';
                $link = '../campaigns/view?id=' . $campaignId;
                notifyUsers($added, 'campaign.assigned', $title, $msg, $link);
            }

            $message = 'QA assignments updated.';
            $messageType = 'success';
        }
    }
}

$campaignLabel = '';
foreach ($campaigns as $c) {
    if ((int)($c['id'] ?? 0) === $campaignId) {
        $campaignLabel = (string)($c['name'] ?? '');
        break;
    }
}
?>

<?php $pageTitle = 'QA Assignments'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">QA Campaign Assignments</h3>
      <div class="text-muted small"><?php echo htmlspecialchars($campaignLabel !== '' ? $campaignLabel : 'Select a campaign'); ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="audit"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <?php if (isAdmin() && !empty($openRequests)): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-light fw-semibold">Open Assignment Requests</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>User</th>
              <th>Message</th>
              <th class="text-muted">Created</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($openRequests as $r): ?>
              <tr>
                <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole((string)($r['full_name'] ?? ''), (string)($r['role'] ?? ''), (string)($r['job_title'] ?? ''))); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['message'] ?? '')); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)($r['created_at'] ?? 'now')))); ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="resolve_request">
                    <input type="hidden" name="request_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                    <button class="btn btn-sm btn-outline-success" type="submit">Mark Resolved</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-lg-6">
          <label class="form-label">Campaign</label>
          <select class="form-select form-select-sm" name="campaign_id" onchange="this.form.submit()">
            <?php foreach ($campaigns as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$c['id'] === $campaignId) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c) . (!empty($c['status']) ? (' · ' . (string)$c['status']) : '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
      <hr>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="campaign_id" value="<?php echo (int)$campaignId; ?>">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">QA Agents</label>
            <select class="form-select" name="qa_agent_user_ids[]" multiple size="10">
              <?php foreach ($assignableUsers as $u): ?>
                <?php $uid = (int)($u['id'] ?? 0); $rRole = (string)($u['role'] ?? ''); ?>
                <?php if (!in_array($rRole, $agentRoles, true)) continue; ?>
                <option value="<?php echo $uid; ?>" <?php echo isset($assignedAgent[$uid]) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), $rRole, (string)($u['job_title'] ?? ''))); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">QA Managers</label>
            <select class="form-select" name="qa_manager_user_ids[]" multiple size="10" <?php echo $canAssignManagers ? '' : 'disabled'; ?>>
              <?php foreach ($assignableUsers as $u): ?>
                <?php $uid = (int)($u['id'] ?? 0); $rRole = (string)($u['role'] ?? ''); ?>
                <?php if (!in_array($rRole, $managerRoles, true)) continue; ?>
                <option value="<?php echo $uid; ?>" <?php echo isset($assignedManager[$uid]) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), $rRole, (string)($u['job_title'] ?? ''))); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">QA Directors</label>
            <select class="form-select" name="qa_director_user_ids[]" multiple size="10" <?php echo $canAssignDirectors ? '' : 'disabled'; ?>>
              <?php foreach ($assignableUsers as $u): ?>
                <?php $uid = (int)($u['id'] ?? 0); $rRole = (string)($u['role'] ?? ''); ?>
                <?php if (!in_array($rRole, $directorRoles, true)) continue; ?>
                <option value="<?php echo $uid; ?>" <?php echo isset($assignedDirector[$uid]) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), $rRole, (string)($u['job_title'] ?? ''))); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
          <button class="btn btn-primary" type="submit">Save Assignments</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
