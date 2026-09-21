<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('files.download.endpoint', []);
ensureDatabaseSchema();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
if ($userId <= 0) {
    http_response_code(401);
    exit;
}

$getProxySecret = function(): string {
    if (!isset($_SESSION['file_proxy_secret']) || !is_string($_SESSION['file_proxy_secret']) || strlen((string)$_SESSION['file_proxy_secret']) < 16) {
        $_SESSION['file_proxy_secret'] = bin2hex(random_bytes(18));
    }
    return (string)$_SESSION['file_proxy_secret'];
};

$normalizeUploadPath = function(string $p): string {
    $p = str_replace('\\', '/', trim($p));
    $p = ltrim($p, '/');
    while (str_starts_with($p, './')) $p = substr($p, 2);
    return $p;
};

$isSafeUploadPath = function(string $p) use ($normalizeUploadPath): bool {
    $p = $normalizeUploadPath($p);
    if ($p === '') return false;
    if (!str_starts_with($p, 'uploads/')) return false;
    if (str_contains($p, '..')) return false;
    if (preg_match('/^[a-zA-Z]:/', $p)) return false;
    if (str_contains($p, "\0")) return false;
    return true;
};

$resolveAbsPath = function(string $rel) use ($normalizeUploadPath): ?string {
    $root = realpath(__DIR__ . '/../../');
    if ($root === false) return null;
    $rel = $normalizeUploadPath($rel);
    $abs = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if ($abs === false) return null;
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $absNorm = str_replace('\\', '/', $abs);
    if (!str_starts_with($absNorm . (is_dir($abs) ? '/' : ''), $rootNorm)) return null;
    return $abs;
};

$serveFile = function(string $relPath, string $downloadName, string $mimeHint = '', bool $inline = false) use ($isSafeUploadPath, $resolveAbsPath): void {
    $relPath = str_replace('\\', '/', $relPath);
    if (!$isSafeUploadPath($relPath)) {
        http_response_code(404);
        exit;
    }
    $abs = $resolveAbsPath($relPath);
    if ($abs === null || !is_file($abs)) {
        http_response_code(404);
        exit;
    }

    $mime = $mimeHint;
    if ($mime === '' && function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mt = finfo_file($fi, $abs);
            finfo_close($fi);
            if (is_string($mt) && $mt !== '') $mime = $mt;
        }
    }
    if ($mime === '') $mime = 'application/octet-stream';

    $downloadName = trim($downloadName);
    if ($downloadName === '') $downloadName = basename($abs);
    $downloadName = str_replace(["\r", "\n"], '', $downloadName);

    $size = (int)filesize($abs);
    $start = 0;
    $end = max(0, $size - 1);
    $isPartial = false;
    $rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($size > 0 && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $m)) {
        $rangeStart = $m[1] !== '' ? (int)$m[1] : null;
        $rangeEnd = $m[2] !== '' ? (int)$m[2] : null;
        if ($rangeStart === null && $rangeEnd !== null) {
            $suffixLength = min($size, max(0, $rangeEnd));
            $start = max(0, $size - $suffixLength);
        } elseif ($rangeStart !== null) {
            $start = min(max(0, $rangeStart), $end);
            if ($rangeEnd !== null) {
                $end = min(max($start, $rangeEnd), $end);
            }
        }
        if ($start <= $end) {
            $isPartial = true;
        }
    }
    $length = ($end >= $start) ? ($end - $start + 1) : 0;

    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);
    if ($isPartial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . (string)$length);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($downloadName) . '"');
    $fp = fopen($abs, 'rb');
    if ($fp === false) {
        http_response_code(404);
        exit;
    }
    if ($start > 0) {
        fseek($fp, $start);
    }
    $remaining = $length;
    $chunkSize = 8192;
    while (!feof($fp) && $remaining > 0) {
        $read = (int)min($chunkSize, $remaining);
        $buffer = fread($fp, $read);
        if ($buffer === false || $buffer === '') {
            break;
        }
        echo $buffer;
        $remaining -= strlen($buffer);
        flush();
    }
    fclose($fp);
    exit;
};

$conn = getDbConnection();
$kind = strtolower(trim((string)($_GET['kind'] ?? '')));
$id = (int)($_GET['id'] ?? 0);
$inline = (int)($_GET['inline'] ?? 0) === 1;

$deny = function(): void {
    http_response_code(403);
    exit;
};

$canAccessCampaign = function(int $campaignId) use ($userId, $userRole): bool {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return false;
    $clientVendorRoles = ['client_admin', 'client_sdr', 'vendor_admin', 'vendor_user'];
    if (in_array($userRole, $clientVendorRoles, true)) {
        $map = getUserCampaignAccessMapForNotifications($userId);
    } elseif (function_exists('getScopedVisibleCampaignIdsForUser')) {
        $map = getScopedVisibleCampaignIdsForUser($userId, $userRole);
    } else {
        $map = getUserCampaignAccessMapForNotifications($userId);
    }
    if ($map === null) return true;
    return isset($map[$campaignId]);
};

if ($kind === 'bulk_report') {
    requirePermissionOrRole('leads.bulk.page', []);
    $file = trim((string)($_GET['file'] ?? ''));
    $name = trim((string)($_GET['name'] ?? ''));
    if ($file === '' || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..')) $deny();
    if (!preg_match('/^bulk_[a-z0-9_]+_(success|rejected)\\.(csv|xls|xlsx)$/i', $file)) $deny();
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = '';
    if ($ext === 'csv') $mime = 'text/csv';
    elseif ($ext === 'xls') $mime = 'application/vnd.ms-excel';
    elseif ($ext === 'xlsx') $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $rel = 'uploads/bulk_upload_reports/' . $file;
    $downloadName = $name !== '' ? $name : $file;
    $serveFile($rel, $downloadName, $mime, false);
}

if ($kind === 'dbms_batch_file') {
    requirePermissionOrRole('dbms.batch.page', []);
    if ($id <= 0) $deny();
    $stmt = $conn->prepare("SELECT id, stored_filename, original_filename FROM dbms_batches WHERE id = ? LIMIT 1");
    if (!$stmt) $deny();
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $file = basename((string)($b['stored_filename'] ?? ''));
    $orig = trim((string)($b['original_filename'] ?? ''));
    if ($file === '' || str_contains($file, '..')) $deny();
    if (!preg_match('/^dbms_[a-z0-9_]+\\.(csv|xls|xlsx)$/i', $file)) $deny();
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = '';
    if ($ext === 'csv') $mime = 'text/csv';
    elseif ($ext === 'xls') $mime = 'application/vnd.ms-excel';
    elseif ($ext === 'xlsx') $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $rel = 'uploads/dbms_uploads/' . $file;
    $downloadName = $orig !== '' ? $orig : $file;
    $serveFile($rel, $downloadName, $mime, false);
}

if ($kind === 'task_file') {
    if ($id <= 0) $deny();
    requirePermissionOrRole('tasks.api.endpoint', []);
    $stmt = $conn->prepare("
        SELECT tf.id, tf.file_path, tf.original_name, tf.mime_type, t.id AS task_id, t.created_by
        FROM task_files tf
        JOIN tasks t ON t.id = tf.task_id
        WHERE tf.id = ?
        LIMIT 1
    ");
    if (!$stmt) $deny();
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $taskId = (int)($row['task_id'] ?? 0);
    if ($taskId <= 0) $deny();
    $creatorId = (int)($row['created_by'] ?? 0);
    $allowed = false;
    if (isAdmin() || userHasPermission('tasks.manage')) {
        $allowed = true;
    } elseif ($creatorId > 0 && $creatorId === $userId) {
        $allowed = true;
    } else {
        $stmtA = $conn->prepare("SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ? LIMIT 1");
        if ($stmtA) {
            $stmtA->bind_param('ii', $taskId, $userId);
            $stmtA->execute();
            $a = $stmtA->get_result()->fetch_assoc();
            $stmtA->close();
            if (!empty($a)) $allowed = true;
        }
    }
    if (!$allowed) $deny();
    $serveFile((string)($row['file_path'] ?? ''), (string)($row['original_name'] ?? ''), (string)($row['mime_type'] ?? ''), false);
}

if ($kind === 'lead_file') {
    if ($id <= 0) $deny();
    requirePermissionOrRole('leads.details.page', []);
    $stmt = $conn->prepare("
        SELECT lf.id, lf.lead_id, lf.file_path, lf.field_id, lf.uploaded_at, l.campaign_id
        FROM lead_files lf
        JOIN leads l ON l.id = lf.lead_id
        WHERE lf.id = ?
        LIMIT 1
    ");
    if (!$stmt) $deny();
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $campaignId = (int)($row['campaign_id'] ?? 0);
    if ($campaignId <= 0 || !$canAccessCampaign($campaignId)) $deny();
    $fp = (string)($row['file_path'] ?? '');
    $name = basename(str_replace('\\', '/', $fp));
    $serveFile($fp, $name, '', $inline);
}

if ($kind === 'chat_attachment') {
    if ($id <= 0) $deny();
    requirePermissionOrRole('chat.fetch.endpoint', []);
    $stmt = $conn->prepare("SELECT id, sender_id, receiver_id, group_id, attachment_path, attachment_name FROM chat_messages WHERE id = ? LIMIT 1");
    if (!$stmt) $deny();
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $senderId = (int)($m['sender_id'] ?? 0);
    $receiverId = (int)($m['receiver_id'] ?? 0);
    $groupId = (int)($m['group_id'] ?? 0);
    $path = (string)($m['attachment_path'] ?? '');
    if ($path === '') $deny();

    $allowed = false;
    if (isAdmin()) {
        $allowed = true;
    } elseif ($groupId > 0) {
        $stmtG = $conn->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
        if ($stmtG) {
            $stmtG->bind_param('ii', $groupId, $userId);
            $stmtG->execute();
            $x = $stmtG->get_result()->fetch_assoc();
            $stmtG->close();
            if (!empty($x)) $allowed = true;
        }
    } else {
        if ($senderId === $userId || $receiverId === $userId) $allowed = true;
    }
    if (!$allowed) $deny();

    $name = (string)($m['attachment_name'] ?? '');
    if ($name === '') $name = basename(str_replace('\\', '/', $path));
    $serveFile($path, $name, '', $inline);
}

if ($kind === 'lead_path') {
    $leadId = (int)($_GET['lead_id'] ?? 0);
    $path = (string)($_GET['path'] ?? '');
    $exp = (int)($_GET['exp'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');
    if ($leadId <= 0 || $path === '' || $exp <= 0 || $sig === '') $deny();
    if ($exp < time() - 60 || $exp > time() + 3600) $deny();
    $secret = $getProxySecret();
    $base = $leadId . '|' . $path . '|' . $exp;
    $calc = hash_hmac('sha256', $base, $secret);
    if (!hash_equals($calc, $sig)) $deny();

    requirePermissionOrRole('leads.view', getKnownRoles());
    $stmt = $conn->prepare("SELECT campaign_id FROM leads WHERE id = ? LIMIT 1");
    if (!$stmt) $deny();
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $campaignId = (int)($r['campaign_id'] ?? 0);
    if ($campaignId <= 0 || !$canAccessCampaign($campaignId)) $deny();

    $p = $normalizeUploadPath($path);
    $serveFile($p, basename(str_replace('\\', '/', $p)), '', $inline);
}

if ($kind === 'campaign_delivery') {
    if ($id <= 0) {
        $deny();
    }
    requirePermissionOrRole('campaigns.view', getKnownRoles());
    $stmt = $conn->prepare("
        SELECT f.id, f.campaign_id, f.file_path, f.file_name
        FROM campaign_delivery_files f
        WHERE f.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        $deny();
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $campaignId = (int)($row['campaign_id'] ?? 0);
    $reqCampaignId = (int)($_GET['campaign_id'] ?? 0);
    if ($campaignId <= 0 || ($reqCampaignId > 0 && $reqCampaignId !== $campaignId) || !$canAccessCampaign($campaignId)) {
        $deny();
    }
    $fp = (string)($row['file_path'] ?? '');
    $name = trim((string)($row['file_name'] ?? ''));
    if ($name === '') {
        $name = basename(str_replace('\\', '/', $fp));
    }
    $serveFile($fp, $name, '', false);
}

if ($kind === 'campaign_path') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $path = $normalizeUploadPath((string)($_GET['path'] ?? ''));
    if ($campaignId <= 0 || $path === '') {
        $deny();
    }
    requirePermissionOrRole('campaigns.view', getKnownRoles());
    if (!$canAccessCampaign($campaignId) || !campaignOwnsUploadPath($campaignId, $path)) {
        $deny();
    }
    $serveFile($path, basename(str_replace('\\', '/', $path)), '', false);
}

http_response_code(404);
exit;
