<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requirePermissionOrRole('chat.send.endpoint', []);
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){ echo json_encode(['ok'=>false,'error'=>'Invalid method']); exit; }
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);
    exit;
}
$user = getCurrentUser();
$action = (string)($_POST['action'] ?? '');
$receiverId = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$message = trim($_POST['message'] ?? '');
$replyToId = isset($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : 0;
$senderId = (int)($user['id'] ?? 0);
if($groupId<=0 && $receiverId<=0){ echo json_encode(['ok'=>false,'error'=>'Receiver required']); exit; }
$conn = getDbConnection();
ensureDatabaseSchema();
ensureChatSchema();

if ($action === 'react') {
    $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
    $reaction = trim((string)($_POST['reaction'] ?? ''));
    if ($messageId <= 0 || $reaction === '') { echo json_encode(['ok'=>false,'error'=>'Invalid reaction']); exit; }
    if (mb_strlen($reaction) > 8) { echo json_encode(['ok'=>false,'error'=>'Invalid reaction']); exit; }

    $stmt = $conn->prepare("SELECT id, sender_id, receiver_id, group_id FROM chat_messages WHERE id = ? LIMIT 1");
    if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'Database error']); exit; }
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$m) { echo json_encode(['ok'=>false,'error'=>'Message not found']); exit; }

    $msgGroupId = (int)($m['group_id'] ?? 0);
    if ($msgGroupId > 0) {
        $stmtM = $conn->prepare('SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
        $stmtM->bind_param('ii', $msgGroupId, $senderId);
        $stmtM->execute();
        $mem = $stmtM->get_result()->fetch_row();
        $stmtM->close();
        if (!$mem) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
    } else {
        $other = ((int)($m['sender_id'] ?? 0) === $senderId) ? (int)($m['receiver_id'] ?? 0) : (int)($m['sender_id'] ?? 0);
        if ($other <= 0 || ($senderId !== (int)($m['sender_id'] ?? 0) && $senderId !== (int)($m['receiver_id'] ?? 0))) {
            echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit;
        }
    }

    $check = $conn->prepare("SELECT 1 FROM chat_message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ? LIMIT 1");
    $check->bind_param('iis', $messageId, $senderId, $reaction);
    $check->execute();
    $exists = $check->get_result()->fetch_row() ? true : false;
    $check->close();

    if ($exists) {
        $del = $conn->prepare("DELETE FROM chat_message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ?");
        $del->bind_param('iis', $messageId, $senderId, $reaction);
        $ok = $del->execute();
        $del->close();
        echo json_encode(['ok'=>(bool)$ok,'toggled'=>'off']);
        exit;
    }

    $ins = $conn->prepare("INSERT INTO chat_message_reactions (message_id, user_id, reaction) VALUES (?,?,?)");
    $ins->bind_param('iis', $messageId, $senderId, $reaction);
    $ok = $ins->execute();
    $ins->close();
    echo json_encode(['ok'=>(bool)$ok,'toggled'=>'on']);
    exit;
}

$attachmentPath = null;
$attachmentName = null;
$attachmentSize = null;
$attachmentMime = null;
$messageType = 'text';
if(!empty($_FILES['attachment']['name'])){
    $allowedExt = ['pdf','doc','docx','xls','xlsx','csv','png','jpg','jpeg','gif','webp','mp3','wav','m4a','ogg','mp4','mov','zip','txt','ppt','pptx'];
    $maxSize = 10*1024*1024;
    $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
    if(!in_array($ext,$allowedExt,true)){ echo json_encode(['ok'=>false,'error'=>'Unsupported file type']); exit; }
    if($_FILES['attachment']['size']>$maxSize){ echo json_encode(['ok'=>false,'error'=>'File too large']); exit; }
    $dir = __DIR__.'/../../uploads/chat_attachments';
    if(!is_dir($dir)){ @mkdir($dir,0775,true); }
    $name = uniqid('chat_').'.'.$ext; $target = $dir.'/'.$name;
    if(!move_uploaded_file($_FILES['attachment']['tmp_name'],$target)){ echo json_encode(['ok'=>false,'error'=>'Upload failed']); exit; }
    $attachmentPath = 'uploads/chat_attachments/'.$name;
    $attachmentName = (string)($_FILES['attachment']['name'] ?? '');
    $attachmentSize = (int)($_FILES['attachment']['size'] ?? 0);
    $attachmentMime = (string)($_FILES['attachment']['type'] ?? '');
    if (in_array($ext, ['png','jpg','jpeg','gif','webp'], true)) $messageType = 'image';
    elseif (in_array($ext, ['mp3','wav','m4a','ogg'], true)) $messageType = 'audio';
    elseif (in_array($ext, ['mp4','mov'], true)) $messageType = 'video';
    else $messageType = 'file';
}

if ($groupId > 0) {
    $stmtM = $conn->prepare('SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $stmtM->bind_param('ii', $groupId, $senderId);
    $stmtM->execute();
    $mem = $stmtM->get_result()->fetch_assoc() ?: null;
    $stmtM->close();
    if (!$mem) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }

    $stmt = $conn->prepare('INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, attachment_path, attachment_name, attachment_size, attachment_mime, message_type, reply_to_id, delivered_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
    $zero = 0;
    $replyVal = $replyToId > 0 ? $replyToId : 0;
    $attachmentSizeVal = $attachmentSize !== null ? (int)$attachmentSize : 0;
    $attachmentMimeVal = $attachmentMime !== null ? (string)$attachmentMime : '';
    $attachmentNameVal = $attachmentName !== null ? (string)$attachmentName : '';
    $attachmentPathVal = $attachmentPath !== null ? (string)$attachmentPath : '';
    $stmt->bind_param('iiisssissi', $senderId, $zero, $groupId, $message, $attachmentPathVal, $attachmentNameVal, $attachmentSizeVal, $attachmentMimeVal, $messageType, $replyVal);
    $ok = $stmt->execute();
    $newId = (int)($stmt->insert_id ?? 0);
    $stmt->close();

    if ($ok && $senderId > 0) {
        $gStmt = $conn->prepare('SELECT group_name FROM chat_groups WHERE id = ? LIMIT 1');
        $gStmt->bind_param('i', $groupId);
        $gStmt->execute();
        $gRow = $gStmt->get_result()->fetch_assoc() ?: [];
        $gStmt->close();
        $groupName = (string)($gRow['group_name'] ?? 'Group');
        $senderName = (string)($user['full_name'] ?? 'User');
        $title = $groupName;
        $preview = $messageType !== 'text' ? 'Sent an attachment' : $message;
        $preview = trim(preg_replace('/\s+/', ' ', (string)$preview));
        if (mb_strlen($preview) > 80) $preview = mb_substr($preview, 0, 80) . '...';
        $body = $senderName . ': ' . $preview;
        $link = '../chat/chat?group_id=' . (int)$groupId;

        $rs = $conn->prepare('SELECT user_id FROM chat_group_members WHERE group_id = ?');
        $rs->bind_param('i', $groupId);
        $rs->execute();
        $res = $rs->get_result();
        while ($r = $res->fetch_assoc()) {
            $to = (int)($r['user_id'] ?? 0);
            if ($to <= 0 || $to === $senderId) continue;
            createNotificationSmart($to, 'chat.group_message', $title, $body, $link, [
                'importance' => 'high',
                'show_toast' => true,
                'dedup_key' => 'chat_gm:' . $groupId . ':' . $newId,
                'dedup_window_min' => 1,
            ]);
        }
        $rs->close();
    }

    echo json_encode(['ok'=>$ok]);
    exit;
}

$isVendorUser = function_exists('isVendor') && isVendor();
$isClientUser = function_exists('isClient') && isClient();
$allowedUserIds = null;
if ($receiverId <= 0) { echo json_encode(['ok'=>false,'error'=>'Receiver required']); exit; }
if ($senderId > 0 && $receiverId === $senderId) { echo json_encode(['ok'=>false,'error'=>'Invalid receiver']); exit; }
$stmtR = $conn->prepare('SELECT id, role, client_id, vendor_id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
if (!$stmtR) { echo json_encode(['ok'=>false,'error'=>'Database error']); exit; }
$stmtR->bind_param('i', $receiverId);
$stmtR->execute();
$rr = $stmtR->get_result()->fetch_assoc() ?: [];
$stmtR->close();
if (empty($rr)) { echo json_encode(['ok'=>false,'error'=>'Receiver not found']); exit; }

$senderRole = strtolower((string)($user['role'] ?? ''));
$receiverRole = strtolower((string)($rr['role'] ?? ''));
$receiverClientId = (int)($rr['client_id'] ?? 0);
$receiverVendorId = (int)($rr['vendor_id'] ?? 0);

if (str_starts_with($senderRole, 'client_') || str_starts_with($senderRole, 'vendor_')) {
    $allowed = function_exists('dfChatAllowedPeerIdsForUser') ? dfChatAllowedPeerIdsForUser($user) : null;
    if (!is_array($allowed) || !in_array($receiverId, $allowed, true)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Access denied']);
        exit;
    }
} else {
    if (str_starts_with($receiverRole, 'client_')) {
        $allowedSales = function_exists('dfChatConnectedSalesUserIdsForClient') ? dfChatConnectedSalesUserIdsForClient($receiverClientId) : [];
        if (empty($allowedSales) || !in_array($senderId, $allowedSales, true)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Access denied']);
            exit;
        }
    } elseif (str_starts_with($receiverRole, 'vendor_')) {
        $allowedSales = function_exists('dfChatConnectedSalesUserIdsForVendor') ? dfChatConnectedSalesUserIdsForVendor($receiverVendorId) : [];
        if (empty($allowedSales) || !in_array($senderId, $allowedSales, true)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Access denied']);
            exit;
        }
    }
}

$stmt = $conn->prepare('INSERT INTO chat_messages (sender_id, receiver_id, message, attachment_path, attachment_name, attachment_size, attachment_mime, message_type, reply_to_id, delivered_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())');
$replyVal = $replyToId > 0 ? $replyToId : 0;
$attachmentSizeVal = $attachmentSize !== null ? (int)$attachmentSize : 0;
$attachmentMimeVal = $attachmentMime !== null ? (string)$attachmentMime : '';
$attachmentNameVal = $attachmentName !== null ? (string)$attachmentName : '';
$attachmentPathVal = $attachmentPath !== null ? (string)$attachmentPath : '';
$stmt->bind_param('iisssissi', $senderId, $receiverId, $message, $attachmentPathVal, $attachmentNameVal, $attachmentSizeVal, $attachmentMimeVal, $messageType, $replyVal);
$ok = $stmt->execute();
$stmt->close();
if ($ok && $senderId > 0 && $receiverId > 0 && $senderId !== $receiverId) {
    $senderName = (string)($user['full_name'] ?? 'User');
    $title = 'New message';
    $preview = $messageType !== 'text' ? 'Sent an attachment' : $message;
    $preview = trim(preg_replace('/\s+/', ' ', (string)$preview));
    if (mb_strlen($preview) > 80) $preview = mb_substr($preview, 0, 80) . '...';
    $body = $senderName . ': ' . $preview;
    $link = '../chat/chat?user_id=' . $senderId;
    createNotificationSmart($receiverId, 'chat.message', $title, $body, $link, ['importance'=>'high','show_toast'=>true]);
}
echo json_encode(['ok'=>$ok]);
exit;
