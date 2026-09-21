<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('notifications.mark_read_ajax.endpoint', []);
ensureCsrfToken();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$id = (int)($_POST['id'] ?? 0);
if ($userId <= 0 || $id <= 0) {
    echo json_encode(['ok' => false]);
    exit;
}

$ok = markNotificationRead($userId, $id);
echo json_encode(['ok' => (bool)$ok]);
exit;
