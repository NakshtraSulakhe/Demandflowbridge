<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
ensureCsrfToken();
ensureTaskManagementSchema();
ensureTeamSchema();

$conn = getDbConnection();
$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$userDept = trim((string)($user['department'] ?? ''));

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    echo 'Missing task id';
    exit;
}

$stmt = $conn->prepare("SELECT t.*, cb.full_name AS created_by_name FROM tasks t LEFT JOIN users cb ON cb.id = t.created_by WHERE t.id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo 'Unable to load task';
    exit;
}
$stmt->bind_param('i', $taskId);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if (!$task) {
    http_response_code(404);
    echo 'Task not found';
    exit;
}

$assignees = [];
$assigneeIds = [];
$stmt = $conn->prepare("SELECT u.id, u.full_name, u.role, u.department FROM task_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.task_id = ? ORDER BY u.full_name");
if ($stmt) {
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $assignees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($assignees as $a) { $assigneeIds[] = (int)($a['id'] ?? 0); }
    $assigneeIds = array_values(array_filter(array_unique($assigneeIds), fn($x) => $x > 0));
}

$canManage = function_exists('userHasPermission') ? userHasPermission('tasks.manage') : false;
$canAssign = function_exists('userHasPermission') ? userHasPermission('tasks.assign') : false;
$canOverride = function_exists('userHasPermission') ? userHasPermission('tasks.override') : false;
$canEdit = $canManage || $canOverride || (int)($task['created_by'] ?? 0) === $userId || in_array($userId, $assigneeIds, true);
$canView = $canEdit || ($canAssign && $userDept !== '' && $userDept === (string)($task['department'] ?? ''));
if (!$canView) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$crmLinks = [];
$campaignId = (int)($task['campaign_id'] ?? 0);
$leadId = (int)($task['lead_id'] ?? 0);
$salesLeadId = (int)($task['sales_lead_id'] ?? 0);
$clientId = (int)($task['client_id'] ?? 0);
$vendorId = (int)($task['vendor_id'] ?? 0);
if ($campaignId > 0) {
    $name = '';
    $stmt = $conn->prepare("SELECT name FROM campaigns WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $name = (string)($row['name'] ?? '');
    }
    $crmLinks[] = ['label' => 'Campaign', 'text' => ($name !== '' ? $name : ('Campaign #' . $campaignId)), 'href' => appBasePath() . '/modules/campaigns/view?id=' . $campaignId];
}
if ($leadId > 0) {
    $crmLinks[] = ['label' => 'Lead', 'text' => 'Lead #' . $leadId, 'href' => appBasePath() . '/modules/leads/lead-details.php?id=' . $leadId];
}
if ($salesLeadId > 0) {
    $nm = '';
    $stmt = $conn->prepare("SELECT company_name FROM sales_leads WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $salesLeadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $nm = (string)($row['company_name'] ?? '');
    }
    $crmLinks[] = ['label' => 'Sales Prospect', 'text' => ($nm !== '' ? $nm : ('Prospect #' . $salesLeadId)), 'href' => appBasePath() . '/modules/sales/lead-view.php?id=' . $salesLeadId];
}
if ($clientId > 0) {
    $nm = '';
    $code = '';
    $stmt = $conn->prepare("SELECT client_code, name FROM clients WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $nm = (string)($row['name'] ?? '');
        $code = (string)($row['client_code'] ?? '');
    }
    $label = trim(($code !== '' ? $code . ' · ' : '') . ($nm !== '' ? $nm : ('Client #' . $clientId)));
    $crmLinks[] = ['label' => 'Client', 'text' => $label, 'href' => appBasePath() . '/modules/clients/clients.php?client_id=' . $clientId];
}
if ($vendorId > 0) {
    $nm = '';
    $code = '';
    $stmt = $conn->prepare("SELECT vendor_code, name FROM vendors WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $nm = (string)($row['name'] ?? '');
        $code = (string)($row['vendor_code'] ?? '');
    }
    $label = trim(($code !== '' ? $code . ' · ' : '') . ($nm !== '' ? $nm : ('Vendor #' . $vendorId)));
    $crmLinks[] = ['label' => 'Vendor', 'text' => $label, 'href' => appBasePath() . '/modules/vendors/vendors.php?vendor_id=' . $vendorId];
}

$message = '';
$messageType = 'success';

$sanitize = function(?string $v, int $maxLen = 5000): string {
    $v = (string)$v;
    $v = str_replace(["\0", "\r"], ['', "\n"], $v);
    $v = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $v);
    $v = trim($v);
    $v = preg_replace("/[ \\t]+/", ' ', $v);
    $v = preg_replace("/\\n{3,}/", "\n\n", $v);
    if ($maxLen > 0 && strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
    return $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'upload_files') {
            if (!$canEdit) {
                http_response_code(403);
                echo 'Access denied';
                exit;
            }
            $uploadsDir = __DIR__ . '/../../uploads/task_files';
            if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0775, true);
            $denyFile = $uploadsDir . '/.htaccess';
            if (!is_file($denyFile)) {
                @file_put_contents($denyFile, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
            }
            $allowedExt = ['png','jpg','jpeg','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt','mp3','wav','m4a','aac','ogg','zip'];
            $saved = 0;
            if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
                $names = $_FILES['attachments']['name'];
                $tmps = $_FILES['attachments']['tmp_name'];
                $errs = $_FILES['attachments']['error'];
                $sizes = $_FILES['attachments']['size'];
                $typesF = $_FILES['attachments']['type'];
                for ($fi = 0; $fi < count($names); $fi++) {
                    if (($errs[$fi] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    $orig = (string)($names[$fi] ?? '');
                    $tmp = (string)($tmps[$fi] ?? '');
                    $size = (int)($sizes[$fi] ?? 0);
                    if ($tmp === '' || !is_uploaded_file($tmp)) continue;
                    if ($size <= 0 || $size > (25 * 1024 * 1024)) continue;
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    if ($ext === '' || !in_array($ext, $allowedExt, true)) continue;
                    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)pathinfo($orig, PATHINFO_FILENAME));
                    $base = $base !== '' ? $base : 'file';
                    $destName = 'T' . $taskId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $base . '.' . $ext;
                    $destAbs = $uploadsDir . '/' . $destName;
                    $rel = 'uploads/task_files/' . $destName;
                    if (!@move_uploaded_file($tmp, $destAbs)) continue;
                    $mime = (string)($typesF[$fi] ?? '');
                    $stmt = $conn->prepare("INSERT INTO task_files (task_id, user_id, file_path, original_name, file_size, mime_type, created_at) VALUES (?,?,?,?,?,?,NOW())");
                    if ($stmt) {
                        $stmt->bind_param('iissis', $taskId, $userId, $rel, $orig, $size, $mime);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $saved++;
                    logTaskActivity($taskId, $userId, 'file_uploaded', ['file' => $orig]);
                }
            }
            if ($saved > 0) {
                $targets = array_values(array_filter(array_unique(array_merge($assigneeIds, [(int)($task['created_by'] ?? 0)])), fn($x) => $x > 0 && $x !== $userId));
                notifyTaskUsers($targets, 'task.file_uploaded', 'New file uploaded', (string)($task['task_code'] ?? '') . ' · ' . (string)($task['title'] ?? ''), appBasePath() . '/modules/tasks/view?id=' . $taskId, [
                    'importance' => 'normal',
                    'show_toast' => true,
                    'dedup_key' => 'task_file:' . $taskId . ':' . date('YmdHis'),
                    'dedup_window_min' => 1,
                ]);
                $message = 'Files uploaded.';
                $messageType = 'success';
            } else {
                $message = 'No files uploaded.';
                $messageType = 'warning';
            }
        }
    }
}

$stmt = $conn->prepare("SELECT id, item_text, is_done FROM task_checklist_items WHERE task_id = ? ORDER BY sort_order ASC, id ASC");
$checklist = [];
if ($stmt) {
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $checklist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$stmt = $conn->prepare("SELECT c.*, u.full_name FROM task_comments c JOIN users u ON u.id = c.user_id WHERE c.task_id = ? ORDER BY c.created_at ASC, c.id ASC");
$comments = [];
if ($stmt) {
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$stmt = $conn->prepare("SELECT a.*, u.full_name FROM task_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.task_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 200");
$activity = [];
if ($stmt) {
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$stmt = $conn->prepare("SELECT * FROM task_files WHERE task_id = ? ORDER BY created_at DESC, id DESC");
$files = [];
if ($stmt) {
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$stmt = $conn->prepare("SELECT user_id, event, event_at FROM task_time_logs WHERE task_id = ? ORDER BY user_id ASC, event_at ASC, id ASC");
$timeLogs = [];
if ($stmt) {
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $timeLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$timeByUser = [];
foreach ($timeLogs as $l) {
    $uid = (int)($l['user_id'] ?? 0);
    if ($uid <= 0) continue;
    $timeByUser[$uid][] = $l;
}

$computeUserMinutes = function(array $logs): array {
    $activeStart = null;
    $activeMinutes = 0;
    $openedAt = null;
    $idleMinutes = 0;
    foreach ($logs as $l) {
        $ev = (string)($l['event'] ?? '');
        $ts = (string)($l['event_at'] ?? '');
        $t = strtotime($ts);
        if ($t === false) continue;
        if ($openedAt === null) $openedAt = $t;
        if (in_array($ev, ['started','resumed'], true)) {
            if ($activeStart === null) $activeStart = $t;
        } elseif (in_array($ev, ['paused','completed'], true)) {
            if ($activeStart !== null) {
                $diff = max(0, $t - $activeStart);
                $activeMinutes += (int)floor($diff / 60);
                $activeStart = null;
            }
        }
    }
    if ($activeStart !== null) {
        $diff = max(0, time() - $activeStart);
        $activeMinutes += (int)floor($diff / 60);
    }
    if ($openedAt !== null) {
        $total = (int)floor((time() - $openedAt) / 60);
        $idleMinutes = max(0, $total - $activeMinutes);
    }
    return ['active' => $activeMinutes, 'idle' => $idleMinutes];
};

$timeStats = ['active' => 0, 'idle' => 0, 'per_user' => []];
foreach ($timeByUser as $uid => $logs) {
    $ms = $computeUserMinutes($logs);
    $timeStats['per_user'][$uid] = $ms;
    $timeStats['active'] += (int)$ms['active'];
    $timeStats['idle'] += (int)$ms['idle'];
}

if ($canEdit) {
    $stmt = $conn->prepare("SELECT event_at FROM task_time_logs WHERE task_id = ? AND user_id = ? AND event = 'opened' ORDER BY event_at DESC, id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $taskId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        $last = $row ? strtotime((string)($row['event_at'] ?? '')) : false;
        if ($last === false || (time() - (int)$last) > 300) {
            $stmt2 = $conn->prepare("INSERT INTO task_time_logs (task_id, user_id, event, event_at) VALUES (?,?, 'opened', NOW())");
            if ($stmt2) {
                $stmt2->bind_param('ii', $taskId, $userId);
                $stmt2->execute();
                $stmt2->close();
            }
        }
    }
}

$statusOptions = ['Not Started','Assigned','In Progress','Waiting For Input','On Hold','Under Review','Completed','Rejected','Cancelled','Overdue'];
$priorityOptions = ['Critical','High','Medium','Low'];

$prio = (string)($task['priority'] ?? 'Medium');
$pcls = 'text-bg-secondary';
if ($prio === 'Critical') $pcls = 'text-bg-danger';
elseif ($prio === 'High') $pcls = 'text-bg-warning';
elseif ($prio === 'Medium') $pcls = 'text-bg-primary';

$st = (string)($task['status'] ?? 'Assigned');
$scls = 'text-bg-secondary';
if (in_array($st, ['Completed'], true)) $scls = 'text-bg-success';
elseif (in_array($st, ['Overdue'], true)) $scls = 'text-bg-danger';
elseif (in_array($st, ['Under Review'], true)) $scls = 'text-bg-info';
elseif (in_array($st, ['On Hold','Waiting For Input'], true)) $scls = 'text-bg-warning';

$due = (string)($task['due_at'] ?? '');
$isOver = ($due !== '' && strtotime($due) !== false && strtotime($due) < time() && !in_array($st, ['Completed','Cancelled','Rejected'], true));

$allUsers = [];
$teams = [];
if ($canAssign || $canOverride) {
    $resU = $conn->query("SELECT id, full_name, department FROM users WHERE is_active = 1 AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) ORDER BY full_name");
    if ($resU) while ($r = $resU->fetch_assoc()) $allUsers[] = $r;
    $resT = $conn->query("SELECT id, team_name FROM teams ORDER BY team_name");
    if ($resT) while ($r = $resT->fetch_assoc()) $teams[] = $r;
}

$pageTitle = (string)($task['task_code'] ?? 'Task');
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h3 class="mb-0"><?php echo htmlspecialchars((string)($task['task_code'] ?? '')); ?></h3>
        <span class="badge <?php echo $pcls; ?>"><?php echo htmlspecialchars($prio); ?></span>
        <span class="badge <?php echo $scls; ?>"><?php echo htmlspecialchars($st); ?></span>
        <?php if ($isOver): ?><span class="badge text-bg-danger">Past due</span><?php endif; ?>
      </div>
      <div class="text-muted small mt-1"><?php echo htmlspecialchars((string)($task['title'] ?? '')); ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
      <?php if ($canAssign || $canOverride): ?>
        <button type="button" class="btn btn-outline-primary btn-sm" id="openReassign"><i class="bi bi-people me-1"></i>Reassign</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-xl-8">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="text-muted small">Department</div>
              <div class="fw-semibold"><?php echo htmlspecialchars((string)($task['department'] ?? '—')); ?></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Task Type</div>
              <div class="fw-semibold"><?php echo htmlspecialchars((string)($task['task_type'] ?? '')); ?></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Assigned By</div>
              <div class="fw-semibold"><?php echo htmlspecialchars((string)($task['created_by_name'] ?? '')); ?></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Assigned To</div>
              <div class="fw-semibold"><?php echo htmlspecialchars(implode(', ', array_map(fn($a) => (string)($a['full_name'] ?? ''), $assignees))); ?></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Created</div>
              <div class="fw-semibold"><?php echo htmlspecialchars((string)($task['created_at'] ?? '')); ?></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Due</div>
              <div class="fw-semibold <?php echo $isOver ? 'text-danger' : ''; ?>"><?php echo $due !== '' ? htmlspecialchars($due) : '—'; ?></div>
            </div>
          </div>

          <hr>

          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div style="min-width:220px;">
              <div class="d-flex justify-content-between small text-muted">
                <span>Progress</span>
                <span id="progressLabel"><?php echo (int)($task['progress'] ?? 0); ?>%</span>
              </div>
              <div class="progress" style="height:8px;">
                <div class="progress-bar" id="progressBar" role="progressbar" style="width: <?php echo (int)($task['progress'] ?? 0); ?>%"></div>
              </div>
            </div>
            <?php if ($canEdit): ?>
              <div class="d-flex gap-2 flex-wrap">
                <div class="btn-group btn-group-sm">
                  <?php foreach ([10,25,50,75,100] as $p): ?>
                    <button type="button" class="btn btn-outline-secondary setProgress" data-progress="<?php echo (int)$p; ?>"><?php echo (int)$p; ?>%</button>
                  <?php endforeach; ?>
                </div>
                <div class="btn-group btn-group-sm">
                  <button type="button" class="btn btn-outline-primary timeEvent" data-event="started"><i class="bi bi-play-fill me-1"></i>Start</button>
                  <button type="button" class="btn btn-outline-primary timeEvent" data-event="paused"><i class="bi bi-pause-fill me-1"></i>Pause</button>
                  <button type="button" class="btn btn-outline-primary timeEvent" data-event="resumed"><i class="bi bi-play me-1"></i>Resume</button>
                  <button type="button" class="btn btn-outline-success timeEvent" data-event="completed"><i class="bi bi-check2-circle me-1"></i>Complete</button>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($canEdit): ?>
            <div class="row g-2 mt-3">
              <div class="col-md-6">
                <label class="form-label small text-muted">Update Status</label>
                <select class="form-select form-select-sm" id="statusSelect">
                  <?php foreach ($statusOptions as $s): ?>
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $st === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold">Description</div>
        <div class="card-body">
          <div class="mb-3"><?php echo nl2br(htmlspecialchars((string)($task['description'] ?? '—'))); ?></div>
          <?php if (trim((string)($task['instructions'] ?? '')) !== ''): ?>
            <div class="fw-semibold mb-1">Instructions</div>
            <div class="mb-3"><?php echo nl2br(htmlspecialchars((string)($task['instructions'] ?? ''))); ?></div>
          <?php endif; ?>
          <?php if (trim((string)($task['notes'] ?? '')) !== ''): ?>
            <div class="fw-semibold mb-1">Notes</div>
            <div><?php echo nl2br(htmlspecialchars((string)($task['notes'] ?? ''))); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span>Checklist</span>
          <?php if ($canEdit): ?>
            <div class="d-flex gap-2 flex-wrap ms-auto" style="max-width:100%;">
              <input class="form-control form-control-sm" id="newChecklistText" placeholder="Add checklist item" style="width:min(260px,100%);">
              <button type="button" class="btn btn-sm btn-outline-primary" id="addChecklist"><i class="bi bi-plus-circle me-1"></i>Add</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (empty($checklist)): ?>
            <div class="text-muted small">No checklist items.</div>
          <?php else: ?>
            <div class="d-flex flex-column gap-2" id="checklistList">
              <?php foreach ($checklist as $it): ?>
                <div class="form-check">
                  <input class="form-check-input checklistToggle" type="checkbox" data-item-id="<?php echo (int)$it['id']; ?>" <?php echo !empty($it['is_done']) ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
                  <label class="form-check-label"><?php echo htmlspecialchars((string)($it['item_text'] ?? '')); ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Comments</div>
        <div class="card-body">
          <?php if ($canEdit): ?>
            <div class="mb-3">
              <textarea class="form-control" id="commentBody" rows="3" placeholder="Write a comment. Use @username to mention."></textarea>
              <div class="d-flex justify-content-end mt-2">
                <button type="button" class="btn btn-primary btn-sm" id="addComment"><i class="bi bi-send me-1"></i>Post</button>
              </div>
            </div>
            <hr>
          <?php endif; ?>

          <div id="commentsList" class="d-flex flex-column gap-3">
            <?php if (empty($comments)): ?>
              <div class="text-muted small">No comments yet.</div>
            <?php else: ?>
              <?php foreach ($comments as $c): ?>
                <div class="border rounded p-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="fw-semibold small"><?php echo htmlspecialchars((string)($c['full_name'] ?? '')); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars((string)($c['created_at'] ?? '')); ?></div>
                  </div>
                  <div class="mt-2"><?php echo nl2br(htmlspecialchars((string)($c['body'] ?? ''))); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold">CRM Links</div>
        <div class="card-body">
          <?php if (empty($crmLinks)): ?>
            <div class="text-muted small">No CRM links.</div>
          <?php else: ?>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($crmLinks as $l): ?>
                <div class="border rounded p-2 d-flex justify-content-between align-items-center gap-2">
                  <div class="small">
                    <div class="text-muted"><?php echo htmlspecialchars((string)($l['label'] ?? '')); ?></div>
                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($l['text'] ?? '')); ?></div>
                  </div>
                  <a class="btn btn-sm btn-light border" href="<?php echo htmlspecialchars((string)($l['href'] ?? '#')); ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold">Attachments</div>
        <div class="card-body">
          <?php if ($canEdit): ?>
            <form method="post" enctype="multipart/form-data" class="mb-3">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="upload_files">
              <input type="file" class="form-control form-control-sm" name="attachments[]" multiple>
              <div class="d-grid mt-2">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1"></i>Upload</button>
              </div>
            </form>
          <?php endif; ?>

          <?php if (empty($files)): ?>
            <div class="text-muted small">No files uploaded.</div>
          <?php else: ?>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($files as $f): ?>
                <?php $fp = (string)($f['file_path'] ?? ''); ?>
                <div class="border rounded p-2 d-flex justify-content-between align-items-center gap-2">
                  <div class="small">
                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($f['original_name'] ?? basename($fp))); ?></div>
                    <div class="text-muted"><?php echo htmlspecialchars((string)($f['created_at'] ?? '')); ?></div>
                  </div>
                  <a class="btn btn-sm btn-light border" href="<?php echo htmlspecialchars(appBasePath() . '/modules/files/download?kind=task_file&id=' . (int)($f['id'] ?? 0)); ?>" download><i class="bi bi-download"></i></a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold">Time Tracking</div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-6">
              <div class="text-muted small">Active</div>
              <div class="fw-semibold"><?php echo number_format((int)floor($timeStats['active'] / 60)); ?>h <?php echo number_format((int)($timeStats['active'] % 60)); ?>m</div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Idle</div>
              <div class="fw-semibold"><?php echo number_format((int)floor($timeStats['idle'] / 60)); ?>h <?php echo number_format((int)($timeStats['idle'] % 60)); ?>m</div>
            </div>
          </div>
          <?php if (!empty($timeStats['per_user'])): ?>
            <hr>
            <div class="text-muted small mb-2">By user</div>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($assignees as $a): ?>
                <?php
                  $uid = (int)($a['id'] ?? 0);
                  $ms = $timeStats['per_user'][$uid] ?? ['active' => 0, 'idle' => 0];
                ?>
                <div class="border rounded p-2">
                  <div class="fw-semibold small"><?php echo htmlspecialchars((string)($a['full_name'] ?? '')); ?></div>
                  <div class="small text-muted">Active: <?php echo (int)floor(((int)$ms['active']) / 60); ?>h <?php echo (int)(((int)$ms['active']) % 60); ?>m · Idle: <?php echo (int)floor(((int)$ms['idle']) / 60); ?>h <?php echo (int)(((int)$ms['idle']) % 60); ?>m</div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold">Activity Feed</div>
        <div class="card-body">
          <?php if (empty($activity)): ?>
            <div class="text-muted small">No activity yet.</div>
          <?php else: ?>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($activity as $a): ?>
                <?php
                  $act = (string)($a['action'] ?? '');
                  $who = (string)($a['full_name'] ?? 'System');
                  $when = (string)($a['created_at'] ?? '');
                ?>
                <div class="border rounded p-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="fw-semibold small"><?php echo htmlspecialchars($act); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($when); ?></div>
                  </div>
                  <div class="small text-muted mt-1"><?php echo htmlspecialchars($who); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($canAssign || $canOverride): ?>
<div class="modal fade" id="reassignModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reassign Task</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-lg-7">
            <label class="form-label small text-muted">Assignees</label>
            <?php $selSet = []; foreach ($assigneeIds as $x) $selSet[(int)$x] = true; ?>
            <select class="form-select" id="reassignUsers" multiple size="12">
              <?php foreach ($allUsers as $u): ?>
                <option value="<?php echo (int)$u['id']; ?>" <?php echo !empty($selSet[(int)$u['id']]) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?><?php echo ($u['department'] ?? '') !== '' ? ' · ' . htmlspecialchars((string)$u['department']) : ''; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-5">
            <label class="form-label small text-muted">Team (optional)</label>
            <select class="form-select" id="reassignTeam">
              <option value="0">None</option>
              <?php foreach ($teams as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)($task['team_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string)($t['team_name'] ?? '')); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="text-muted small mt-2">Team members are added to the assignee list.</div>
            <div class="alert alert-danger d-none mt-3" id="reassignErr"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="saveReassign"><i class="bi bi-check2-circle me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const csrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const apiUrl = <?php echo json_encode(appBasePath() . '/modules/tasks/api', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const taskId = <?php echo (int)$taskId; ?>;

  function postJson(payload){
    return fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(r => r.json());
  }

  const statusSelect = document.getElementById('statusSelect');
  statusSelect?.addEventListener('change', async function(){
    const val = String(statusSelect.value || '').trim();
    if (!val) return;
    const res = await postJson({ action:'update_status', csrf_token: csrf, task_id: taskId, status: val }).catch(()=>null);
    if (!res || !res.ok) location.reload();
    else location.reload();
  });

  document.querySelectorAll('.setProgress').forEach(btn => btn.addEventListener('click', async () => {
    const p = Number(btn.getAttribute('data-progress') || '0');
    const res = await postJson({ action:'update_progress', csrf_token: csrf, task_id: taskId, progress: p }).catch(()=>null);
    if (!res || !res.ok) return;
    const bar = document.getElementById('progressBar');
    const lbl = document.getElementById('progressLabel');
    if (bar) bar.style.width = String(p) + '%';
    if (lbl) lbl.textContent = String(p) + '%';
  }));

  document.querySelectorAll('.checklistToggle').forEach(cb => cb.addEventListener('change', async () => {
    const itemId = Number(cb.getAttribute('data-item-id') || '0');
    const isDone = cb.checked ? 1 : 0;
    const res = await postJson({ action:'toggle_checklist', csrf_token: csrf, task_id: taskId, item_id: itemId, is_done: isDone }).catch(()=>null);
    if (!res || !res.ok) location.reload();
  }));

  const addChecklistBtn = document.getElementById('addChecklist');
  addChecklistBtn?.addEventListener('click', async () => {
    const input = document.getElementById('newChecklistText');
    const txt = String(input?.value || '').trim();
    if (!txt) return;
    const res = await postJson({ action:'add_checklist', csrf_token: csrf, task_id: taskId, item_text: txt }).catch(()=>null);
    if (!res || !res.ok) return;
    location.reload();
  });

  const addCommentBtn = document.getElementById('addComment');
  addCommentBtn?.addEventListener('click', async () => {
    const ta = document.getElementById('commentBody');
    const body = String(ta?.value || '').trim();
    if (!body) return;
    addCommentBtn.disabled = true;
    const res = await postJson({ action:'add_comment', csrf_token: csrf, task_id: taskId, body }).catch(()=>null);
    addCommentBtn.disabled = false;
    if (!res || !res.ok) return;
    location.reload();
  });

  document.querySelectorAll('.timeEvent').forEach(btn => btn.addEventListener('click', async () => {
    const ev = String(btn.getAttribute('data-event') || '').trim();
    if (!ev) return;
    const res = await postJson({ action:'time_event', csrf_token: csrf, task_id: taskId, event: ev }).catch(()=>null);
    if (!res || !res.ok) return;
    location.reload();
  }));

  const openReassign = document.getElementById('openReassign');
  const reassignModalEl = document.getElementById('reassignModal');
  openReassign?.addEventListener('click', () => {
    bootstrap.Modal.getOrCreateInstance(reassignModalEl).show();
  });

  const saveReassign = document.getElementById('saveReassign');
  saveReassign?.addEventListener('click', async () => {
    const sel = document.getElementById('reassignUsers');
    const team = document.getElementById('reassignTeam');
    const err = document.getElementById('reassignErr');
    if (err) { err.classList.add('d-none'); err.textContent = ''; }
    const ids = Array.from(sel?.selectedOptions || []).map(o => Number(o.value || 0)).filter(Boolean);
    const teamId = Number(team?.value || 0);
    const res = await postJson({ action:'reassign', csrf_token: csrf, task_id: taskId, assignee_ids: ids, team_id: teamId }).catch(()=>null);
    if (!res || !res.ok) {
      if (err) { err.classList.remove('d-none'); err.textContent = (res && res.error) ? String(res.error) : 'Unable to reassign.'; }
      return;
    }
    location.reload();
  });
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
