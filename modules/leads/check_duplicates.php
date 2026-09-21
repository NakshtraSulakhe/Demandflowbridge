<?php
// Duplicate leads check endpoint
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Enforce manifest-driven duplicate-check endpoint access.
requirePermissionOrRole('leads.check_duplicates.endpoint', []);

header('Content-Type: application/json');

// Parse JSON body if available
$raw = file_get_contents('php://input');
$data = [];
if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
// Fallback to POST params
if (empty($data)) {
    $data = $_POST;
}

// CSRF validation (required)
$csrf = $data['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$firstName = trim($data['first_name'] ?? '');
$lastName = trim($data['last_name'] ?? '');
$email = trim($data['email'] ?? '');
$companyName = trim($data['company_name'] ?? '');
$search = trim($data['search'] ?? '');
$campaignId = (int)($data['campaign_id'] ?? 0);
$allData = !empty($data['all_data']);

if ($allData) {
    $campaignId = 0;
}

// Require at least one field provided
if ($firstName === '' && $lastName === '' && $email === '' && $companyName === '' && $search === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Provide at least one field to check']);
    exit;
}

// Use existing helper to find potential duplicates
$matches = findDuplicateLeads($firstName, $lastName, $email, $companyName, 20, $campaignId, $search);

echo json_encode([
    'ok' => true,
    'count' => count($matches),
    'matches' => $matches,
]);
?>
