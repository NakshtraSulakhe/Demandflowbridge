<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('tasks.deadline.endpoint', []);
ensureTaskManagementSchema();
ensureTeamSchema();

header('Content-Type: application/json; charset=utf-8');

$conn = getDbConnection();
$nowUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');
$nowUtcDt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$warnRules = [
    1 => ['sec' => 24 * 3600, 'label' => '24h remaining', 'type' => 'task.deadline.warning.24h', 'title' => 'Deadline warning (24h)'],
    2 => ['sec' => 12 * 3600, 'label' => '12h remaining', 'type' => 'task.deadline.warning.12h', 'title' => 'Deadline warning (12h)'],
    3 => ['sec' => 6 * 3600, 'label' => '6h remaining', 'type' => 'task.deadline.warning.6h', 'title' => 'Deadline warning (6h)'],
    4 => ['sec' => 1 * 3600, 'label' => '1h remaining', 'type' => 'task.deadline.warning.1h', 'title' => 'Deadline warning (1h)'],
];

$escalationThresholds = [
    1 => 0,
    2 => 4,
    3 => 12,
    4 => 24,
];

$stmt = $conn->prepare("
    SELECT t.*
    FROM tasks t
    WHERE t.due_at IS NOT NULL
      AND t.status NOT IN ('Completed','Cancelled','Rejected')
    ORDER BY t.due_at ASC
    LIMIT 5000
");
$tasks = [];
if ($stmt) {
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$getAssignees = function(int $taskId) use ($conn): array {
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

$getTeamManager = function(int $teamId) use ($conn): ?int {
    if ($teamId <= 0) return null;
    $stmt = $conn->prepare("SELECT manager_user_id FROM teams WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $teamId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $id = $r ? (int)($r['manager_user_id'] ?? 0) : 0;
    return $id > 0 ? $id : null;
};

$getDeptManagers = function(string $dept) use ($conn): array {
    $dept = trim($dept);
    if ($dept === '') return [];
    $stmt = $conn->prepare("
        SELECT id FROM users
        WHERE is_active = 1
          AND department = ?
          AND (
            role LIKE '%manager%' OR role LIKE '%director%' OR role IN ('director','manager_director')
          )
    ");
    if (!$stmt) return [];
    $stmt->bind_param('s', $dept);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)($r['id'] ?? 0); }
    return array_values(array_filter(array_unique($ids), fn($x) => $x > 0));
};

$getDirectors = function() use ($conn): array {
    $rows = $conn->query("SELECT id FROM users WHERE is_active = 1 AND (role LIKE '%director%' OR role IN ('director','manager_director'))");
    $ids = [];
    if ($rows) {
        while ($r = $rows->fetch_assoc()) { $ids[] = (int)($r['id'] ?? 0); }
    }
    return array_values(array_filter(array_unique($ids), fn($x) => $x > 0));
};

$directorsCache = null;
$sentWarn = 0;
$sentOverdue = 0;
$sentEsc = 0;
$updatedOverdue = 0;

foreach ($tasks as $t) {
    $taskId = (int)($t['id'] ?? 0);
    if ($taskId <= 0) continue;
    $dueAt = (string)($t['due_at'] ?? '');
    $dueDt = appParseUtc($dueAt);
    if (!($dueDt instanceof DateTimeImmutable)) continue;

    $assignees = $getAssignees($taskId);
    $creatorId = (int)($t['created_by'] ?? 0);
    $baseTargets = array_values(array_filter(array_unique(array_merge($assignees, [$creatorId])), fn($x) => $x > 0));

    $remaining = $dueDt->getTimestamp() - $nowUtcDt->getTimestamp();
    if ($remaining > 0) {
        $level = 0;
        if ($remaining <= $warnRules[4]['sec']) $level = 4;
        elseif ($remaining <= $warnRules[3]['sec']) $level = 3;
        elseif ($remaining <= $warnRules[2]['sec']) $level = 2;
        elseif ($remaining <= $warnRules[1]['sec']) $level = 1;
        if ($level > 0) {
            $stmtA = $conn->prepare("SELECT last_level FROM task_deadline_alerts WHERE task_id = ? LIMIT 1");
            $lastLevel = 0;
            if ($stmtA) {
                $stmtA->bind_param('i', $taskId);
                $stmtA->execute();
                $r = $stmtA->get_result()->fetch_assoc() ?: null;
                $stmtA->close();
                $lastLevel = $r ? (int)($r['last_level'] ?? 0) : 0;
            }
            if ($level > $lastLevel) {
                $rule = $warnRules[$level];
                $link = appBasePath() . '/modules/tasks/view?id=' . $taskId;
                $msg = (string)($t['task_code'] ?? '') . ' · ' . (string)($t['title'] ?? '') . ' · ' . $rule['label'];
                notifyTaskUsers($baseTargets, $rule['type'], $rule['title'], $msg, $link, [
                    'importance' => $level >= 3 ? 'high' : 'normal',
                    'show_toast' => true,
                    'dedup_key' => 'task_deadline:' . $taskId . ':' . $level,
                    'dedup_window_min' => 120,
                ]);
                $stmtW = $conn->prepare("INSERT INTO task_deadline_alerts (task_id, last_level, last_sent_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_level = VALUES(last_level), last_sent_at = VALUES(last_sent_at)");
                if ($stmtW) {
                    $stmtW->bind_param('iis', $taskId, $level, $nowUtc);
                    $stmtW->execute();
                    $stmtW->close();
                }
                $conn->query("UPDATE tasks SET deadline_warning_level = GREATEST(deadline_warning_level, $level) WHERE id = $taskId");
                logTaskActivity($taskId, null, 'deadline_warning', ['level' => $level, 'remaining_sec' => $remaining]);
                $sentWarn++;
            }
        }
        continue;
    }

    if ((string)($t['status'] ?? '') !== 'Overdue') {
        $stmtU = $conn->prepare("UPDATE tasks SET status = 'Overdue', updated_at = ? WHERE id = ? LIMIT 1");
        if ($stmtU) {
            $stmtU->bind_param('si', $nowUtc, $taskId);
            $stmtU->execute();
            $stmtU->close();
        }
        $updatedOverdue++;
    }

    notifyTaskUsers($baseTargets, 'task.overdue', 'Task overdue', (string)($t['task_code'] ?? '') . ' · ' . (string)($t['title'] ?? ''), appBasePath() . '/modules/tasks/view?id=' . $taskId, [
        'importance' => 'high',
        'show_toast' => true,
        'dedup_key' => 'task_overdue:' . $taskId,
        'dedup_window_min' => 720,
    ]);
    $sentOverdue++;

    $overHours = (int)floor(abs($remaining) / 3600);
    $level = 1;
    if ($overHours >= $escalationThresholds[4]) $level = 4;
    elseif ($overHours >= $escalationThresholds[3]) $level = 3;
    elseif ($overHours >= $escalationThresholds[2]) $level = 2;

    $curLevel = (int)($t['escalation_level'] ?? 0);
    if ($level <= $curLevel) continue;

    $recipients = $assignees;
    $teamManager = $getTeamManager((int)($t['team_id'] ?? 0));
    if ($level >= 2 && $teamManager !== null) $recipients[] = $teamManager;
    if ($level >= 3) $recipients = array_merge($recipients, $getDeptManagers((string)($t['department'] ?? '')));
    if ($level >= 4) {
        if ($directorsCache === null) $directorsCache = $getDirectors();
        $recipients = array_merge($recipients, $directorsCache);
    }
    $recipients[] = $creatorId;
    $recipients = array_values(array_filter(array_unique($recipients), fn($x) => $x > 0));

    $link = appBasePath() . '/modules/tasks/view?id=' . $taskId;
    notifyTaskUsers($recipients, 'task.escalated', 'Task escalated', (string)($t['task_code'] ?? '') . ' · ' . (string)($t['title'] ?? '') . ' · Level ' . $level, $link, [
        'importance' => 'high',
        'show_toast' => true,
        'dedup_key' => 'task_escalation:' . $taskId . ':' . $level,
        'dedup_window_min' => 720,
    ]);

    $stmtE = $conn->prepare("INSERT INTO task_escalations (task_id, level, reason, triggered_at, notified_user_ids) VALUES (?,?,?,?,?)");
    if ($stmtE) {
        $reason = 'Overdue by ' . $overHours . 'h';
        $uidsJson = json_encode($recipients, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmtE->bind_param('iisss', $taskId, $level, $reason, $nowUtc, $uidsJson);
        $stmtE->execute();
        $stmtE->close();
    }
    $conn->query("UPDATE tasks SET escalation_level = $level WHERE id = $taskId");
    logTaskActivity($taskId, null, 'escalated', ['level' => $level, 'overdue_hours' => $overHours]);
    $sentEsc++;
}

echo json_encode([
    'ok' => true,
    'scanned' => count($tasks),
    'warnings_sent' => $sentWarn,
    'overdue_status_updated' => $updatedOverdue,
    'overdue_notifications_sent' => $sentOverdue,
    'escalations_sent' => $sentEsc,
], JSON_UNESCAPED_UNICODE);

