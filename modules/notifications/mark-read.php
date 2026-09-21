<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('notifications.mark_read.page', []);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$to = trim((string)($_GET['to'] ?? ''));
$token = (string)($_GET['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo 'Invalid token';
    exit;
}

if ($id > 0 && $userId > 0) {
    markNotificationRead($userId, $id);
}

$to = str_replace(["\r", "\n"], '', $to);
$dest = '';
if ($to !== '') {
    $base = rtrim((string)appBasePath(), '/');
    $normalize = function (string $path) use ($base): string {
        if ($path === '' || $path[0] !== '/') return '';
        if ($base === '' || $base === '/') return $path;
        if ($path === $base || str_starts_with($path, $base . '/')) return $path;
        if (str_starts_with($path, '/modules/') || str_starts_with($path, '/uploads/') || str_starts_with($path, '/api/')) return $base . $path;
        return $path;
    };

    if (preg_match('#^https?://#i', $to)) {
        $u = @parse_url($to);
        $host = strtolower((string)($u['host'] ?? ''));
        $thisHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && $thisHost !== '' && $host === $thisHost) {
            $path = (string)($u['path'] ?? '');
            $qs = isset($u['query']) && (string)$u['query'] !== '' ? ('?' . (string)$u['query']) : '';
            $dest = $normalize($path) . $qs;
        }
    } elseif ($to[0] === '/') {
        $dest = $normalize($to);
    } else {
        $dest = ($base !== '' ? $base : '') . '/' . ltrim($to, '/');
    }
}
$safe = $dest !== '' ? appSafeInternalUrl($dest) : '';
if ($safe !== '') { header('Location: ' . $safe); exit; }

header('Location: ../users/profile');
exit;
