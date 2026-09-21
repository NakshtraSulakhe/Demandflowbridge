<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('tasks.api.endpoint', []);
ensureTaskManagementSchema();

header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($data['action'] ?? '');
$conn = getDbConnection();

$getTask = function(int $taskId) use ($conn): ?array {
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};

$getAssigneeIds = function(int $taskId) use ($conn): array {
    $stmt = $conn->prepare("SELECT user_id FROM task_assignees WHERE task_id = ?");
    if (!$stmt) return [];
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)($r['user_id'] ?? 0); }
    return array_values(array_filter(array_unique($ids), fn($x) => $x > 0));
};

$canManage = function(): bool {
    return function_exists('userHasPermission') ? userHasPermission('tasks.manage') : false;
};
$canAssign = function(): bool {
    return function_exists('userHasPermission') ? userHasPermission('tasks.assign') : false;
};
$canOverride = function(): bool {
    return function_exists('userHasPermission') ? userHasPermission('tasks.override') : false;
};

$hasAnyTasksAccess = function() use ($canManage, $canAssign, $canOverride): bool {
    if (!function_exists('userHasPermission')) return true;
    return userHasPermission('tasks.access')
        || userHasPermission('tasks.create')
        || userHasPermission('tasks.reports')
        || $canManage()
        || $canAssign()
        || $canOverride();
};

$canReopen = function() use ($canManage, $canAssign, $canOverride): bool {
    return $canOverride() || $canManage() || $canAssign();
};

$canModifyTask = function(array $task, array $assignees) use ($userId, $canManage): bool {
    if ($canManage()) return true;
    if ((int)($task['created_by'] ?? 0) === $userId) return true;
    return in_array($userId, $assignees, true);
};

$taskId = (int)($data['task_id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing task_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$task = $getTask($taskId);
if (!$task) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Task not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignees = $getAssigneeIds($taskId);
if (!$hasAnyTasksAccess()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$canModifyTask($task, $assignees) && !$canOverride()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$taskLink = appBasePath() . '/modules/tasks/view?id=' . $taskId;
$titleBase = (string)($task['title'] ?? '');
$codeBase = (string)($task['task_code'] ?? '');

if ($action === 'update_status') {
    $statusOptions = ['Not Started','Assigned','In Progress','Waiting For Input','On Hold','Under Review','Completed','Rejected','Cancelled','Overdue'];
    $newStatus = trim((string)($data['status'] ?? ''));
    if (!in_array($newStatus, $statusOptions, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $oldStatus = (string)($task['status'] ?? '');
    $isCreator = (int)($task['created_by'] ?? 0) === $userId;
    $isAssignee = in_array($userId, $assignees, true);

    if ($newStatus === 'Overdue' && !$canOverride()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (in_array($newStatus, ['Cancelled','Rejected'], true) && !($isCreator || $canManage() || $canOverride())) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($oldStatus === 'Completed' && $newStatus !== 'Completed' && !$canReopen()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($newStatus === 'Completed' && !($isAssignee || $isCreator || $canManage() || $canOverride())) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $progress = (int)($task['progress'] ?? 0);
    $completedAt = null;
    if ($newStatus === 'Completed') {
        $progress = 100;
        $completedAt = date('Y-m-d H:i:s');
    }

    $stmt = $conn->prepare("UPDATE tasks SET status = ?, progress = ?, completed_at = COALESCE(?, completed_at), updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->bind_param('sisii', $newStatus, $progress, $completedAt, $userId, $taskId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    logTaskActivity($taskId, $userId, 'status_updated', ['from' => $oldStatus, 'to' => $newStatus]);

    $targets = array_values(array_filter(array_unique(array_merge($assignees, [(int)($task['created_by'] ?? 0)])), fn($x) => $x > 0 && $x !== $userId));
    $type = $newStatus === 'Completed' ? 'task.completed' : ($newStatus === 'Rejected' ? 'task.rejected' : 'task.status_changed');
    $ttl = $newStatus === 'Completed' ? 'Task completed' : ($newStatus === 'Rejected' ? 'Task requires rework' : 'Task status changed');
    $msg = $codeBase . ' · ' . $titleBase . ' → ' . $newStatus;
    notifyTaskUsers($targets, $type, $ttl, $msg, $taskLink, [
        'importance' => $newStatus === 'Completed' ? 'high' : 'normal',
        'show_toast' => true,
        'dedup_key' => 'task_status:' . $taskId . ':' . $newStatus,
        'dedup_window_min' => 2,
    ]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update_progress') {
    $p = (int)($data['progress'] ?? 0);
    if ($p < 0) $p = 0;
    if ($p > 100) $p = 100;
    $stmt = $conn->prepare("UPDATE tasks SET progress = ?, updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->bind_param('iii', $p, $userId, $taskId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    logTaskActivity($taskId, $userId, 'progress_updated', ['progress' => $p]);
    $targets = array_values(array_filter(array_unique(array_merge($assignees, [(int)($task['created_by'] ?? 0)])), fn($x) => $x > 0 && $x !== $userId));
    notifyTaskUsers($targets, 'task.progress', 'Task progress updated', $codeBase . ' · ' . $titleBase . ' → ' . $p . '%', $taskLink, [
        'importance' => 'normal',
        'show_toast' => false,
        'dedup_key' => 'task_progress:' . $taskId . ':' . $p,
        'dedup_window_min' => 2,
    ]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'toggle_checklist') {
    $itemId = (int)($data['item_id'] ?? 0);
    $isDone = !empty($data['is_done']) ? 1 : 0;
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing item_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $conn->prepare("UPDATE task_checklist_items SET is_done = ?, completed_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END WHERE id = ? AND task_id = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->bind_param('iiii', $isDone, $isDone, $itemId, $taskId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN is_done = 1 THEN 1 ELSE 0 END) AS done FROM task_checklist_items WHERE task_id = ?");
    $total = 0; $done = 0;
    if ($stmt) {
        $stmt->bind_param('i', $taskId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $total = (int)($r['total'] ?? 0);
        $done = (int)($r['done'] ?? 0);
    }
    $pct = $total > 0 ? (int)floor(($done / $total) * 100) : 0;
    $stmt = $conn->prepare("UPDATE tasks SET checklist_total = ?, checklist_done = ?, progress = GREATEST(progress, ?), updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iiiii', $total, $done, $pct, $userId, $taskId);
        $stmt->execute();
        $stmt->close();
    }

    logTaskActivity($taskId, $userId, 'checklist_toggled', ['item_id' => $itemId, 'is_done' => $isDone, 'checklist_done' => $done, 'checklist_total' => $total]);
    echo json_encode(['ok' => true, 'checklist_total' => $total, 'checklist_done' => $done, 'progress_suggested' => $pct], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'add_checklist') {
    $text = trim((string)($data['item_text'] ?? ''));
    if ($text === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing text'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($text) > 255) $text = substr($text, 0, 255);
    $stmt = $conn->prepare("INSERT INTO task_checklist_items (task_id, item_text, is_done, sort_order, created_by, created_at) VALUES (?,?,0,0,?,NOW())");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Insert failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->bind_param('isi', $taskId, $text, $userId);
    $ok = $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Insert failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $conn->query("UPDATE tasks SET checklist_total = (SELECT COUNT(*) FROM task_checklist_items WHERE task_id = $taskId), checklist_done = (SELECT SUM(CASE WHEN is_done=1 THEN 1 ELSE 0 END) FROM task_checklist_items WHERE task_id = $taskId), updated_by = $userId, updated_at = NOW() WHERE id = $taskId");
    logTaskActivity($taskId, $userId, 'checklist_added', ['item_id' => $newId]);
    echo json_encode(['ok' => true, 'item_id' => $newId, 'item_text' => $text], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'add_comment') {
    $body = trim((string)($data['body'] ?? ''));
    if ($body === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Empty comment'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($body) > 5000) $body = substr($body, 0, 5000);
    $stmt = $conn->prepare("INSERT INTO task_comments (task_id, user_id, body, created_at) VALUES (?,?,?,NOW())");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Insert failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->bind_param('iis', $taskId, $userId, $body);
    $ok = $stmt->execute();
    $commentId = (int)$stmt->insert_id;
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Insert failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    logTaskActivity($taskId, $userId, 'comment_added', ['comment_id' => $commentId]);

    $baseTargets = array_values(array_filter(array_unique(array_merge($assignees, [(int)($task['created_by'] ?? 0)])), fn($x) => $x > 0 && $x !== $userId));
    notifyTaskUsers($baseTargets, 'task.comment', 'New comment added', $codeBase . ' · ' . $titleBase, $taskLink, [
        'importance' => 'normal',
        'show_toast' => true,
        'dedup_key' => 'task_comment:' . $taskId . ':' . $commentId,
        'dedup_window_min' => 2,
    ]);

    $mentions = extractMentionUserIds($body);
    $mentions = array_values(array_filter(array_unique($mentions), fn($x) => $x > 0 && $x !== $userId));
    if (!empty($mentions)) {
        notifyTaskUsers($mentions, 'task.mention', 'You were mentioned', $codeBase . ' · ' . $titleBase, $taskLink, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'task_mention:' . $taskId . ':' . $commentId,
            'dedup_window_min' => 5,
        ]);
    }

    echo json_encode(['ok' => true, 'comment_id' => $commentId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'time_event') {
    $event = trim((string)($data['event'] ?? ''));
    $allowed = ['opened','started','paused','resumed','completed'];
    if (!in_array($event, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid event'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($event === 'completed') {
        $isCreator = (int)($task['created_by'] ?? 0) === $userId;
        $isAssignee = in_array($userId, $assignees, true);
        if (!($isAssignee || $isCreator || $canManage() || $canOverride())) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $stmt = $conn->prepare("INSERT INTO task_time_logs (task_id, user_id, event, event_at) VALUES (?,?,?,NOW())");
    if ($stmt) {
        $stmt->bind_param('iis', $taskId, $userId, $event);
        $stmt->execute();
        $stmt->close();
    }
    if ($event === 'started') {
        $conn->query("UPDATE tasks SET start_at = COALESCE(start_at, NOW()), updated_by = $userId, updated_at = NOW() WHERE id = $taskId");
    }
    if ($event === 'completed') {
        $conn->query("UPDATE tasks SET status = 'Completed', progress = 100, completed_at = NOW(), updated_by = $userId, updated_at = NOW() WHERE id = $taskId");
    }
    logTaskActivity($taskId, $userId, 'time_event', ['event' => $event]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reassign') {
    if (!$canAssign() && !$canOverride()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$canOverride() && !$canManage() && (int)($task['created_by'] ?? 0) !== $userId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $assigneeIds = $data['assignee_ids'] ?? [];
    if (!is_array($assigneeIds)) $assigneeIds = [];
    $newIds = [];
    foreach ($assigneeIds as $id) {
        $id = (int)$id;
        if ($id > 0) $newIds[$id] = true;
    }
    $newIds = array_keys($newIds);
    $teamId = (int)($data['team_id'] ?? 0);

    if ($teamId > 0) {
        ensureTeamSchema();
        $stmt = $conn->prepare("SELECT user_id FROM team_members WHERE team_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $teamId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $r) { $id = (int)($r['user_id'] ?? 0); if ($id > 0) $newIds[$id] = true; }
            $newIds = array_keys($newIds);
        }
    }

    if (empty($newIds)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Select at least one assignee'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $old = $assignees;
    $added = array_values(array_diff($newIds, $old));
    $removed = array_values(array_diff($old, $newIds));

    if (!empty($removed)) {
        $in = implode(',', array_fill(0, count($removed), '?'));
        $t = str_repeat('i', count($removed));
        $stmt = $conn->prepare("DELETE FROM task_assignees WHERE task_id = ? AND user_id IN ($in)");
        if ($stmt) {
            $args = array_merge([$taskId], $removed);
            $stmt->bind_param('i' . $t, ...$args);
            $stmt->execute();
            $stmt->close();
        }
    }
    foreach ($added as $id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO task_assignees (task_id, user_id, assigned_by, assigned_at) VALUES (?,?,?,NOW())");
        if ($stmt) {
            $stmt->bind_param('iii', $taskId, $id, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $stmt = $conn->prepare("UPDATE tasks SET team_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
    if ($stmt) {
        $tid = $teamId > 0 ? $teamId : null;
        $stmt->bind_param('iii', $tid, $userId, $taskId);
        $stmt->execute();
        $stmt->close();
    }

    logTaskActivity($taskId, $userId, 'reassigned', ['added' => $added, 'removed' => $removed, 'team_id' => $teamId > 0 ? $teamId : null]);

    if (!empty($added)) {
        notifyTaskUsers($added, 'task.assigned', 'New task assigned', $codeBase . ' · ' . $titleBase, $taskLink, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'task_assigned:' . $taskId,
            'dedup_window_min' => 5,
        ]);
    }

    echo json_encode(['ok' => true, 'added' => $added, 'removed' => $removed], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
