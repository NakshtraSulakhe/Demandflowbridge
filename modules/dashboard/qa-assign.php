<?php
require __DIR__ . '/../qa/assignments.php';
exit;
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
            SELECT r.id, r.message, r.created_at, u.full_name, u.role
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
    $rs = $conn->query("SELECT c.id, c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
    if ($rs) $campaigns = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
    $ids = array_keys($visible);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT c.id, c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id IN ($in) ORDER BY c.name");
        $types = str_repeat('i', count($ids));
        if ($stmt) {
            $stmt->bind_param($types, ...$ids);
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
            header('Location: qa-assign');
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
            $stmtD->bind_param('i'.$typesR, $campaignId, ...$deleteRoles);
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
                <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole((string)($r['full_name'] ?? ''), (string)($r['role'] ?? ''))); ?></td>
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

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label">Campaign</label>
          <select class="form-select form-select-sm" name="campaign_id" onchange="this.form.submit()">
            <option value="">Select campaign</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)($c['id'] ?? 0) === $campaignId) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(($c['name'] ?? '').' ['.($c['code'] ?? '').']'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>
  </div>

  <?php if ($campaignId <= 0): ?>
    <div class="text-muted">No campaigns available for assignment.</div>
  <?php else: ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold">Assign QA Team</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="campaign_id" value="<?php echo (int)$campaignId; ?>">

          <?php if ($canAssignDirectors): ?>
            <div class="mb-3">
              <div class="fw-semibold mb-2">QA Directors</div>
              <div class="row g-2">
                <?php
                  $dirs = array_values(array_filter($assignableUsers, fn($u) => in_array((string)($u['role'] ?? ''), $directorRoles, true)));
                ?>
                <?php if (empty($dirs)): ?>
                  <div class="col-12 text-muted">No QA directors found.</div>
                <?php else: ?>
                  <?php foreach ($dirs as $u): ?>
                    <?php $uid = (int)($u['id'] ?? 0); ?>
                    <div class="col-md-6">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="qa_director_user_ids[]" value="<?php echo $uid; ?>" id="dir_<?php echo $uid; ?>" <?php echo isset($assignedDirector[$uid]) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="dir_<?php echo $uid; ?>">
                          <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), (string)($u['role'] ?? ''))); ?>
                        </label>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($canAssignManagers): ?>
            <div class="mb-3">
              <div class="fw-semibold mb-2">QA Managers</div>
              <div class="row g-2">
                <?php
                  $mgrs = array_values(array_filter($assignableUsers, fn($u) => in_array((string)($u['role'] ?? ''), $managerRoles, true)));
                ?>
                <?php if (empty($mgrs)): ?>
                  <div class="col-12 text-muted">No QA managers found.</div>
                <?php else: ?>
                  <?php foreach ($mgrs as $u): ?>
                    <?php $uid = (int)($u['id'] ?? 0); ?>
                    <div class="col-md-6">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="qa_manager_user_ids[]" value="<?php echo $uid; ?>" id="mgr_<?php echo $uid; ?>" <?php echo isset($assignedManager[$uid]) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="mgr_<?php echo $uid; ?>">
                          <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), (string)($u['role'] ?? ''))); ?>
                        </label>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="mb-3">
            <div class="fw-semibold mb-2">QA Agents</div>
            <div class="row g-2">
              <?php
                $ags = array_values(array_filter($assignableUsers, fn($u) => in_array((string)($u['role'] ?? ''), $agentRoles, true)));
              ?>
              <?php if (empty($ags)): ?>
                <div class="col-12 text-muted">No QA agents found.</div>
              <?php else: ?>
                <?php foreach ($ags as $u): ?>
                  <?php $uid = (int)($u['id'] ?? 0); ?>
                  <div class="col-md-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="qa_agent_user_ids[]" value="<?php echo $uid; ?>" id="ag_<?php echo $uid; ?>" <?php echo isset($assignedAgent[$uid]) ? 'checked' : ''; ?>>
                      <label class="form-check-label" for="ag_<?php echo $uid; ?>">
                        <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), (string)($u['role'] ?? ''))); ?>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="d-flex justify-content-end">
            <button class="btn btn-primary btn-sm" type="submit">Save Assignments</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
