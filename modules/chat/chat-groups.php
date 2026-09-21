<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requirePermissionOrRole('chat.groups.endpoint', []);
ensureChatSchema();

header('Content-Type: application/json; charset=utf-8');
$user = getCurrentUser();
$uid = (int)($user['id'] ?? 0);
$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    $where = "gm.user_id = ?";
    if ($q !== '') $where .= " AND g.group_name LIKE CONCAT('%', ?, '%')";
    $sql = "
        SELECT g.id, g.group_name, g.created_by, g.created_at, gm.role,
               (SELECT COUNT(*) FROM chat_group_members m2 WHERE m2.group_id = g.id) AS member_count
        FROM chat_group_members gm
        JOIN chat_groups g ON g.id = gm.group_id
        WHERE {$where}
        ORDER BY g.created_at DESC
        LIMIT 300
    ";
    if ($q !== '') {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $uid, $q);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $uid);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'id' => (int)$r['id'],
            'group_name' => (string)$r['group_name'],
            'role' => (string)($r['role'] ?? 'member'),
            'member_count' => (int)($r['member_count'] ?? 0),
        ];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'groups' => $out]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = (string)($_POST['action'] ?? '');
if ($action !== 'create') {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$name = trim((string)($_POST['group_name'] ?? ''));
if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Group name required']);
    exit;
}
if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

$memberIdsRaw = $_POST['member_ids'] ?? [];
if (!is_array($memberIdsRaw)) $memberIdsRaw = [];
$memberIds = [];
foreach ($memberIdsRaw as $v) {
    $id = (int)$v;
    if ($id > 0 && $id !== $uid) $memberIds[$id] = true;
}
$memberIds = array_keys($memberIds);

$allowedUserIds = function_exists('dfChatAllowedPeerIdsForUser') ? dfChatAllowedPeerIdsForUser($user) : null;
if (is_array($allowedUserIds)) {
    $set = array_flip($allowedUserIds);
    $memberIds = array_values(array_filter($memberIds, fn($id) => isset($set[$id])));
}

$stmt = $conn->prepare('INSERT INTO chat_groups (group_name, created_by, created_at) VALUES (?, ?, NOW())');
$stmt->bind_param('si', $name, $uid);
$ok = $stmt->execute();
$groupId = (int)($stmt->insert_id ?? 0);
$stmt->close();
if (!$ok || $groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create group']);
    exit;
}

$stmtM = $conn->prepare("INSERT INTO chat_group_members (group_id, user_id, role, added_by, added_at) VALUES (?, ?, 'admin', ?, NOW())");
$stmtM->bind_param('iii', $groupId, $uid, $uid);
$stmtM->execute();
$stmtM->close();

if (!empty($memberIds)) {
    $ins = $conn->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, added_by, added_at) VALUES (?, ?, 'member', ?, NOW())");
    foreach ($memberIds as $mid) {
        $ins->bind_param('iii', $groupId, $mid, $uid);
        $ins->execute();
    }
    $ins->close();

    $senderName = (string)($user['full_name'] ?? 'User');
    $rs = $conn->prepare('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_map('intval', $memberIds)) . ')');
    $rs->execute();
    $res = $rs->get_result();
    $membersById = [];
    while ($r = $res->fetch_assoc()) $membersById[(int)$r['id']] = (string)($r['full_name'] ?? '');
    $rs->close();

    foreach ($memberIds as $mid) {
        $targetName = $membersById[$mid] ?? 'User';
        $msg = $senderName . ' added ' . $targetName;
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $msg);
        $stmtSys->execute();
        $stmtSys->close();

        createNotificationSmart($mid, 'chat.added_to_group', 'Added to group', $name, '../chat/chat?group_id=' . $groupId, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'chat_added:' . $groupId . ':' . $mid,
            'dedup_window_min' => 10,
        ]);
    }
}

echo json_encode(['ok' => true, 'group' => ['id' => $groupId, 'group_name' => $name]]);
exit;
