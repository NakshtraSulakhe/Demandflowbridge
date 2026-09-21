<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requirePermissionOrRole('chat.group_members.endpoint', []);
ensureChatSchema();

header('Content-Type: application/json; charset=utf-8');
$user = getCurrentUser();
$uid = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : (int)($_POST['group_id'] ?? 0);
if ($groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group']);
    exit;
}

$stmtM = $conn->prepare('SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
$stmtM->bind_param('ii', $groupId, $uid);
$stmtM->execute();
$mem = $stmtM->get_result()->fetch_assoc() ?: null;
$stmtM->close();
if (!$mem) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}
$myRole = strtolower((string)($mem['role'] ?? 'member'));
$isAdmin = $myRole === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $view = strtolower(trim((string)($_GET['view'] ?? 'members')));
    if ($view === 'files' || $view === 'media') {
        $where = "m.group_id = ? AND m.is_deleted = 0 AND m.attachment_path IS NOT NULL AND m.attachment_path <> ''";
        if ($view === 'media') {
            $where .= " AND m.message_type IN ('image','video','audio')";
        } else {
            $where .= " AND m.message_type IN ('file','image','video','audio')";
        }
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.attachment_path, m.attachment_name, m.attachment_size, m.attachment_mime, m.message_type, m.created_at,
                   u.full_name AS sender_name
            FROM chat_messages m
            LEFT JOIN users u ON u.id = m.sender_id
            WHERE {$where}
            ORDER BY m.id DESC
            LIMIT 50
        ");
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'sender_id' => (int)($r['sender_id'] ?? 0),
                'sender_name' => (string)($r['sender_name'] ?? ''),
                'attachment_path' => (string)($r['attachment_path'] ?? ''),
                'attachment_name' => (string)($r['attachment_name'] ?? ''),
                'attachment_size' => (int)($r['attachment_size'] ?? 0),
                'attachment_mime' => (string)($r['attachment_mime'] ?? ''),
                'message_type' => (string)($r['message_type'] ?? 'file'),
                'created_at' => $r['created_at'] ?? null,
            ];
        }
        echo json_encode(['ok' => true, 'items' => $out, 'my_role' => $myRole]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT gm.user_id, gm.role, gm.added_at, u.full_name, u.role AS user_role, u.profile_pic
        FROM chat_group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY (gm.role = 'admin') DESC, u.full_name ASC
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'user_id' => (int)$r['user_id'],
            'full_name' => (string)($r['full_name'] ?? ''),
            'profile_pic' => (string)($r['profile_pic'] ?? ''),
            'role' => (string)($r['role'] ?? 'member'),
            'user_role' => (string)($r['user_role'] ?? ''),
            'added_at' => $r['added_at'] ?? null,
        ];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'members' => $out, 'my_role' => $myRole]);
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
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}

if ($action === 'add') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }

    $allowedUserIds = function_exists('dfChatAllowedPeerIdsForUser') ? dfChatAllowedPeerIdsForUser($user) : null;
    if (is_array($allowedUserIds) && !in_array($userId, $allowedUserIds, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed']);
        exit;
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, added_by, added_at) VALUES (?, ?, 'member', ?, NOW())");
    $stmt->bind_param('iii', $groupId, $userId, $uid);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $who = (string)($user['full_name'] ?? 'User');
        $nStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $nStmt->bind_param('i', $userId);
        $nStmt->execute();
        $nr = $nStmt->get_result()->fetch_assoc() ?: [];
        $nStmt->close();
        $target = (string)($nr['full_name'] ?? 'User');
        $sys = $who . ' added ' . $target;
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $sys);
        $stmtSys->execute();
        $stmtSys->close();
        createNotificationSmart($userId, 'chat.added_to_group', 'Added to group', $sys, '../chat/chat?group_id=' . $groupId, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'chat_added:' . $groupId . ':' . $userId,
            'dedup_window_min' => 10,
        ]);
    }
    echo json_encode(['ok' => (bool)$ok]);
    exit;
}

if ($action === 'remove') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0 || $userId === $uid) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }
    $stmt = $conn->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $groupId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $who = (string)($user['full_name'] ?? 'User');
        $nStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $nStmt->bind_param('i', $userId);
        $nStmt->execute();
        $nr = $nStmt->get_result()->fetch_assoc() ?: [];
        $nStmt->close();
        $target = (string)($nr['full_name'] ?? 'User');
        $sys = $who . ' removed ' . $target;
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $sys);
        $stmtSys->execute();
        $stmtSys->close();
    }
    echo json_encode(['ok' => (bool)$ok]);
    exit;
}

if ($action === 'set_role') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $role = strtolower(trim((string)($_POST['role'] ?? 'member')));
    if ($userId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }
    if (!in_array($role, ['admin','member'], true)) { echo json_encode(['ok' => false, 'error' => 'Invalid role']); exit; }
    $stmt = $conn->prepare("UPDATE chat_group_members SET role = ? WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param('sii', $role, $groupId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $who = (string)($user['full_name'] ?? 'User');
        $nStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $nStmt->bind_param('i', $userId);
        $nStmt->execute();
        $nr = $nStmt->get_result()->fetch_assoc() ?: [];
        $nStmt->close();
        $target = (string)($nr['full_name'] ?? 'User');
        $sys = $who . ' set ' . $target . ' as ' . ($role === 'admin' ? 'Admin' : 'Member');
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $sys);
        $stmtSys->execute();
        $stmtSys->close();
    }
    echo json_encode(['ok' => (bool)$ok]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
