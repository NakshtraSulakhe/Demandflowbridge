<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('dashboard.banners.endpoint', []);
ensureCsrfToken();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0 || !function_exists('dfDashboardAllBannersHtml')) {
    echo json_encode(['ok' => false]);
    exit;
}

$auto = dfDashboardAllBannersHtml($userId);
$html = (string)($auto['html'] ?? '');
echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
