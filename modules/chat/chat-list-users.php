<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requirePermissionOrRole('chat.users.endpoint', []);
ensureChatSchema();
header('Content-Type: application/json');
$user = getCurrentUser();
$uid = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$allowedUserIds = function_exists('dfChatAllowedPeerIdsForUser') ? dfChatAllowedPeerIdsForUser($user) : null;

$where = "u.is_active = 1 AND u.id <> ?";
if (is_array($allowedUserIds)) {
    if (empty($allowedUserIds)) { echo json_encode(['ok'=>true,'users'=>[]]); exit; }
    $in = implode(',', array_fill(0, count($allowedUserIds), '?'));
    $where .= " AND u.id IN ($in)";
}

$sql = "SELECT 
  u.id, u.full_name, u.username, u.email, u.role, u.profile_pic, COALESCE(p.is_online,0) AS is_online, p.last_seen,
  (
    SELECT MAX(m.created_at) FROM chat_messages m 
    WHERE m.group_id IS NULL AND ((m.sender_id=u.id AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=u.id))
  ) AS last_msg_at,
  (
    SELECT COUNT(*) FROM chat_messages m2 
    WHERE m2.group_id IS NULL AND m2.sender_id=u.id AND m2.receiver_id=? AND m2.read_at IS NULL
  ) AS unread_count
FROM users u 
LEFT JOIN user_presence p ON p.user_id = u.id 
WHERE {$where}
ORDER BY (last_msg_at IS NULL) ASC, last_msg_at DESC, u.full_name ASC";
$stmt = $conn->prepare($sql);
if (is_array($allowedUserIds)) {
    $types = 'iiii' . str_repeat('i', count($allowedUserIds));
    $params = array_merge([$uid, $uid, $uid, $uid], $allowedUserIds);
    mysqliBindParams($stmt, $types, $params);
} else {
    $stmt->bind_param('iiii', $uid, $uid, $uid, $uid);
}
$stmt->execute();
$res = $stmt->get_result();
$users = [];
while($row = $res->fetch_assoc()){
  $users[] = [
    'id'=>(int)$row['id'],
    'full_name'=>$row['full_name'],
    'username'=>(string)($row['username'] ?? ''),
    'email'=>(string)($row['email'] ?? ''),
    'role'=>$row['role'],
    'profile_pic'=>(string)($row['profile_pic'] ?? ''),
    'is_online'=>(int)$row['is_online']===1,
    'last_seen'=>$row['last_seen'],
    'last_msg_at'=>$row['last_msg_at'],
    'unread_count'=>(int)($row['unread_count'] ?? 0)
  ];
}
echo json_encode(['ok'=>true,'users'=>$users]);
exit;
