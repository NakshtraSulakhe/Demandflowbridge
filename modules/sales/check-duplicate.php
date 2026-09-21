<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('sales.check_duplicate.endpoint', []);

header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
$scopeUserIds = getSalesScopeUserIdsForCurrentUser($user);
$scopeUserIds = is_array($scopeUserIds)
    ? array_values(array_filter(array_map('intval', $scopeUserIds), fn($v) => $v > 0))
    : null;

$payload = [
    'company_name' => $_GET['company_name'] ?? '',
    'website' => $_GET['website'] ?? '',
    'contact_email' => $_GET['contact_email'] ?? '',
    'linkedin_url' => $_GET['linkedin_url'] ?? '',
    'country' => $_GET['country'] ?? '',
    'owner_id' => (int)($_GET['owner_id'] ?? 0),
];
$excludeId = (int)($_GET['exclude_id'] ?? 0);
 $type = strtolower((string)($_GET['type'] ?? 'sales'));

try {
    if ($type === 'client') {
        $rows = findDuplicateClientsByNameOrDomain((string)$payload['company_name'], (string)$payload['website']);
        echo json_encode(['ok' => true, 'matches' => $rows], JSON_UNESCAPED_UNICODE);
    } else {
        if (is_array($scopeUserIds)) {
            if ($payload['owner_id'] > 0 && !in_array((int)$payload['owner_id'], $scopeUserIds, true)) {
                echo json_encode(['ok' => true, 'matches' => []], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $payload['scope_user_ids'] = $scopeUserIds;
        }
        $matches = findDuplicateSalesLeads($payload, $excludeId, 10);
        echo json_encode(['ok' => true, 'matches' => $matches], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to check duplicates'], JSON_UNESCAPED_UNICODE);
}
