<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requirePermissionOrRole('chat.fetch.endpoint', []);
header('Content-Type: application/json');
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$peerId = isset($_GET['peer_id']) ? (int)$_GET['peer_id'] : 0;
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$conn = getDbConnection();
ensureChatSchema();

function dfChatLoadReactionsForMessages(mysqli $conn, array $messageIds, int $currentUserId): array {
    $messageIds = array_values(array_filter(array_map('intval', $messageIds), fn($v) => $v > 0));
    if (empty($messageIds)) return [];
    $in = implode(',', array_fill(0, count($messageIds), '?'));
    $types = str_repeat('i', count($messageIds));

    $stmt = $conn->prepare("SELECT message_id, reaction, COUNT(*) AS cnt FROM chat_message_reactions WHERE message_id IN ($in) GROUP BY message_id, reaction");
    if (!$stmt) return [];
    mysqliBindParams($stmt, $types, $messageIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT message_id, reaction FROM chat_message_reactions WHERE user_id = ? AND message_id IN ($in)");
    if (!$stmt2) return [];
    $types2 = 'i' . $types;
    $bind = array_merge([$currentUserId], $messageIds);
    mysqliBindParams($stmt2, $types2, $bind);
    $stmt2->execute();
    $mineRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt2->close();

    $mine = [];
    foreach ($mineRows as $r) {
        $mid = (int)($r['message_id'] ?? 0);
        $rx = (string)($r['reaction'] ?? '');
        if ($mid > 0 && $rx !== '') $mine[$mid . ':' . $rx] = true;
    }

    $out = [];
    foreach ($rows as $r) {
        $mid = (int)($r['message_id'] ?? 0);
        $rx = (string)($r['reaction'] ?? '');
        $cnt = (int)($r['cnt'] ?? 0);
        if ($mid <= 0 || $rx === '' || $cnt <= 0) continue;
        if (!isset($out[$mid])) $out[$mid] = [];
        $out[$mid][] = ['reaction' => $rx, 'count' => $cnt, 'me' => isset($mine[$mid . ':' . $rx])];
    }
    return $out;
}

function dfChatFetchConversationAttachments(mysqli $conn, int $userId, int $peerId, int $groupId, string $view): array {
    $where = [];
    $params = [];
    $types = '';

    if ($groupId > 0) {
        $where[] = 'm.group_id = ?';
        $params[] = $groupId;
        $types .= 'i';
    } else {
        $where[] = "m.group_id IS NULL AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))";
        array_push($params, $userId, $peerId, $peerId, $userId);
        $types .= 'iiii';
    }

    $where[] = "m.is_deleted = 0";
    $where[] = "m.attachment_path IS NOT NULL";
    $where[] = "m.attachment_path <> ''";

    if ($view === 'media') {
        $where[] = "m.message_type = 'image'";
    } else {
        $where[] = "m.message_type IN ('file','image','video','audio')";
    }

    $sql = "
        SELECT m.id, m.sender_id, m.attachment_path, m.attachment_name, m.attachment_size, m.attachment_mime, m.message_type, m.created_at,
               u.full_name AS sender_name
        FROM chat_messages m
        LEFT JOIN users u ON u.id = m.sender_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 200
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $mid = (int)($r['id'] ?? 0);
        if ($mid <= 0 || isset($seen[$mid])) continue;
        $seen[$mid] = true;
        $out[] = [
            'id' => $mid,
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
    return $out;
}

if ($groupId > 0) {
    $stmtM = $conn->prepare('SELECT role, last_read_message_id FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $stmtM->bind_param('ii', $groupId, $userId);
    $stmtM->execute();
    $mem = $stmtM->get_result()->fetch_assoc() ?: null;
    $stmtM->close();
    if (!$mem) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }

    $view = strtolower(trim((string)($_GET['view'] ?? '')));
    if ($view === 'files' || $view === 'media') {
        $items = dfChatFetchConversationAttachments($conn, $userId, 0, $groupId, $view);
        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    }

    $stmt = $conn->prepare('
        SELECT m.id, m.sender_id, m.receiver_id, m.group_id, m.message, m.attachment_path, m.attachment_name, m.attachment_size, m.attachment_mime, m.message_type,
               m.reply_to_id, m.forwarded_from_id, m.edited_at, m.edited_by,
               m.delivered_at, m.read_at, m.is_deleted, m.deleted_by, m.deleted_at, m.created_at,
               u.full_name AS sender_name, u.profile_pic AS sender_profile_pic,
               rm.message AS reply_message, rm.attachment_name AS reply_attachment_name, rm.attachment_path AS reply_attachment_path, rm.is_deleted AS reply_is_deleted,
               ru.full_name AS reply_sender_name
        FROM chat_messages m
        LEFT JOIN users u ON u.id = m.sender_id
        LEFT JOIN chat_messages rm ON rm.id = m.reply_to_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE m.group_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 200
    ');
    $stmt->bind_param('ii', $groupId, $sinceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    $maxId = 0;
    $ids = [];
    while($row = $res->fetch_assoc()){
        $maxId = max($maxId, (int)$row['id']);
        $ids[] = (int)$row['id'];
        if (!empty($row['is_deleted'])) {
            $row['message'] = 'This message was deleted';
            $row['attachment_path'] = null;
        }
        if (!empty($row['reply_is_deleted'])) {
            $row['reply_message'] = 'This message was deleted';
            $row['reply_attachment_path'] = null;
        }
        $messages[] = $row;
    }
    $stmt->close();
    if ($maxId > 0) {
        $stmtU = $conn->prepare('UPDATE chat_group_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE group_id = ? AND user_id = ?');
        $stmtU->bind_param('iii', $maxId, $groupId, $userId);
        $stmtU->execute();
        $stmtU->close();
    }
    $rx = dfChatLoadReactionsForMessages($conn, $ids, $userId);
    if (!empty($rx)) {
        foreach ($messages as &$m) {
            $mid = (int)($m['id'] ?? 0);
            $m['reactions'] = $rx[$mid] ?? [];
        }
        unset($m);
    } else {
        foreach ($messages as &$m) { $m['reactions'] = []; }
        unset($m);
    }
    echo json_encode(['ok'=>true,'messages'=>$messages]);
    exit;
}

if($peerId<=0){ echo json_encode(['ok'=>false,'error'=>'Peer required']); exit; }

$peer = null;
$stmtP = $conn->prepare('SELECT id, role, client_id, vendor_id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
if ($stmtP) {
    $stmtP->bind_param('i', $peerId);
    $stmtP->execute();
    $peer = $stmtP->get_result()->fetch_assoc() ?: null;
    $stmtP->close();
}
if (!$peer) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Peer not found']);
    exit;
}

$meRole = strtolower((string)($user['role'] ?? ''));
$peerRole = strtolower((string)($peer['role'] ?? ''));

if (str_starts_with($meRole, 'client_') || str_starts_with($meRole, 'vendor_')) {
    $allowed = function_exists('dfChatAllowedPeerIdsForUser') ? dfChatAllowedPeerIdsForUser($user) : null;
    if (!is_array($allowed) || !in_array($peerId, $allowed, true)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Not allowed']);
        exit;
    }
} else {
    if (str_starts_with($peerRole, 'client_')) {
        $clientId = (int)($peer['client_id'] ?? 0);
        $allowedSales = function_exists('dfChatConnectedSalesUserIdsForClient') ? dfChatConnectedSalesUserIdsForClient($clientId) : [];
        if (empty($allowedSales) || !in_array($userId, $allowedSales, true)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not allowed']);
            exit;
        }
    } elseif (str_starts_with($peerRole, 'vendor_')) {
        $vendorId = (int)($peer['vendor_id'] ?? 0);
        $allowedSales = function_exists('dfChatConnectedSalesUserIdsForVendor') ? dfChatConnectedSalesUserIdsForVendor($vendorId) : [];
        if (empty($allowedSales) || !in_array($userId, $allowedSales, true)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not allowed']);
            exit;
        }
    }
}

$view = strtolower(trim((string)($_GET['view'] ?? '')));
if ($view === 'files' || $view === 'media') {
    $items = dfChatFetchConversationAttachments($conn, $userId, $peerId, 0, $view);
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

$stmt = $conn->prepare('
    SELECT m.id, m.sender_id, m.receiver_id, m.group_id, m.message, m.attachment_path, m.attachment_name, m.attachment_size, m.attachment_mime, m.message_type,
           m.reply_to_id, m.forwarded_from_id, m.edited_at, m.edited_by,
           m.delivered_at, m.read_at, m.is_deleted, m.deleted_by, m.deleted_at, m.created_at,
           u.full_name AS sender_name, u.profile_pic AS sender_profile_pic,
           rm.message AS reply_message, rm.attachment_name AS reply_attachment_name, rm.attachment_path AS reply_attachment_path, rm.is_deleted AS reply_is_deleted,
           ru.full_name AS reply_sender_name
    FROM chat_messages m
    LEFT JOIN users u ON u.id = m.sender_id
    LEFT JOIN chat_messages rm ON rm.id = m.reply_to_id
    LEFT JOIN users ru ON ru.id = rm.sender_id
    WHERE m.group_id IS NULL
      AND ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))
      AND m.id>?
    ORDER BY m.id ASC
    LIMIT 200
');
$stmt->bind_param('iiiii', $userId, $peerId, $peerId, $userId, $sinceId);
$stmt->execute();
$res = $stmt->get_result();
$messages = [];
$toMark = [];
$msgIds = [];
while($row = $res->fetch_assoc()){
    if (!empty($row['is_deleted'])) {
        $row['message'] = 'This message was deleted';
        $row['attachment_path'] = null;
    }
    if (!empty($row['reply_is_deleted'])) {
        $row['reply_message'] = 'This message was deleted';
        $row['reply_attachment_path'] = null;
    }
    $messages[] = $row;
    $msgIds[] = (int)$row['id'];
    if ((int)$row['receiver_id'] === $userId && empty($row['read_at'])) { $toMark[] = (int)$row['id']; }
}
if (!empty($toMark)) {
    $markIds = implode(',', array_map('intval',$toMark));
    @$conn->query("UPDATE chat_messages SET read_at = NOW() WHERE id IN ($markIds)");
}
$rx = dfChatLoadReactionsForMessages($conn, $msgIds, $userId);
if (!empty($rx)) {
    foreach ($messages as &$m) {
        $mid = (int)($m['id'] ?? 0);
        $m['reactions'] = $rx[$mid] ?? [];
    }
    unset($m);
} else {
    foreach ($messages as &$m) { $m['reactions'] = []; }
    unset($m);
}
echo json_encode(['ok'=>true,'messages'=>$messages]);
exit;
