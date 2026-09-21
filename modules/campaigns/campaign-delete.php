<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.delete.endpoint', []);
ensureCsrfToken();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid campaign ID']);
    exit;
}

$campaign = getCampaignById($id);
if (!$campaign) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Campaign not found']);
    exit;
}

$ok = deleteCampaignHard($id);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to delete campaign']);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Campaign deleted']);
exit;
