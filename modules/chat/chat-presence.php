<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
requirePermissionOrRole('chat.presence.endpoint', []);
header('Content-Type: application/json');
$raw = file_get_contents('php://input');
$data = json_decode($raw,true) ?: [];
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);
    exit;
}
$online = !empty($data['online']);
$user = getCurrentUser();
$conn = getDbConnection();
$stmt = $conn->prepare('INSERT INTO user_presence (user_id, last_seen, is_online) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE last_seen=NOW(), is_online=VALUES(is_online)');
$flag = $online ? 1 : 0;
$userId = (int)($user['id'] ?? 0);
$stmt->bind_param('ii', $userId, $flag);
$ok = $stmt->execute();
echo json_encode(['ok'=>$ok]);
exit;
