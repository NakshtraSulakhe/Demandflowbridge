<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requirePermissionOrRole('chat.delete.endpoint', []);
ensureChatSchema();
header('Content-Type: application/json; charset=utf-8');

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

$user = getCurrentUser();
$uid = (int)($user['id'] ?? 0);
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
if ($messageId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid message']);
    exit;
}

$conn = getDbConnection();
$stmt = $conn->prepare('SELECT id, sender_id, receiver_id, group_id, is_deleted FROM chat_messages WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $messageId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}
if (!empty($row['is_deleted'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$senderId = (int)($row['sender_id'] ?? 0);
$groupId = (int)($row['group_id'] ?? 0);
$allowed = false;
if ($groupId > 0) {
    if ($senderId === $uid) {
        $allowed = true;
    } else {
        $stmtM = $conn->prepare('SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
        $stmtM->bind_param('ii', $groupId, $uid);
        $stmtM->execute();
        $mem = $stmtM->get_result()->fetch_assoc() ?: null;
        $stmtM->close();
        $allowed = $mem && strtolower((string)($mem['role'] ?? '')) === 'admin';
    }
} else {
    $allowed = ($senderId === $uid);
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$stmtU = $conn->prepare('UPDATE chat_messages SET is_deleted = 1, deleted_by = ?, deleted_at = NOW() WHERE id = ?');
$stmtU->bind_param('ii', $uid, $messageId);
$ok = $stmtU->execute();
$stmtU->close();

echo json_encode(['ok' => (bool)$ok]);
exit;
