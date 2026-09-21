<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/../qa/action.php';
    exit;
}
header('Location: ../qa/dashboard');
exit;
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: qa-audit');
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
    header('Location: qa-audit?error=invalid_token');
    exit;
}

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

$leadId = (int)($_POST['lead_id'] ?? 0);
$qaStatus = (string)($_POST['qa_status'] ?? 'Pending');
$qaComment = (string)($_POST['qa_comment_internal'] ?? ($_POST['qa_comment'] ?? ''));
$qaClientComment = (string)($_POST['qa_comment_client'] ?? '');
$clientDeliveryStatus = (string)($_POST['client_delivery_status'] ?? 'Pending');
$returnUrl = (string)($_POST['return_url'] ?? '');

if ($leadId <= 0) {
    header('Location: qa-audit?error=invalid_lead');
    exit;
}

$lead = getLeadById($leadId);
if (!$lead) {
    header('Location: qa-audit?error=not_found');
    exit;
}

$cid = (int)($lead['campaign_id'] ?? 0);
$visible = getQaVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
if ($visible !== null && $cid > 0 && !isset($visible[$cid])) {
    http_response_code(403);
    echo 'Not allowed';
    exit;
}

updateLeadQuality($leadId, $qaStatus, $qaComment, $userId, $qaClientComment, $clientDeliveryStatus);

$parsed = $returnUrl !== '' ? parse_url($returnUrl) : null;
$path = is_array($parsed) ? (string)($parsed['path'] ?? '') : '';
if ($path !== '' && str_starts_with($path, '/leads/')) {
    header('Location: ' . $returnUrl);
    exit;
}
if ($returnUrl !== '' && !preg_match('/^https?:\\/\\//i', $returnUrl) && !str_contains($returnUrl, "\n") && !str_contains($returnUrl, "\r")) {
    header('Location: ' . $returnUrl);
    exit;
}

header('Location: qa-audit?updated=1');
exit;
