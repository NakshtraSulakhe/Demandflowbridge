<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('leads.entry', [
    'admin',
    'director',
    'manager_director',
    'operations_director',
    'operations_manager',
    'operations_agent',
    'agent',
    'form_filler',
    'email_marketing_executive',
    'email_marketing_agent',
    'email_marketing_manager',
    'email_marketing_director',
    'vendor_admin',
    'vendor_user',
]);

header('Content-Type: application/json; charset=utf-8');

$currentUser = getCurrentUser() ?: [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentRole = normalizeRole((string)($currentUser['role'] ?? ''));

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
if ($campaignId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid campaign']);
    exit;
}

$det = getCampaignDetailsById($campaignId);
if (!$det) {
    echo json_encode(['ok' => false, 'message' => 'Campaign not found']);
    exit;
}

$canManageCampaigns = isAdmin() || isSalesDirector() || isSalesManager();
if (!$canManageCampaigns) {
    $visible = getOpsVisibleCampaignIdsForUser($currentUserId, $currentRole);
    if (is_array($visible)) {
        if (!in_array($currentRole, ['vendor_admin', 'vendor_user'], true)) {
            $teamIds = getUserTeamIds($currentUserId);
            if (!empty($teamIds)) {
                $teamCampaigns = getTeamCampaignIds($teamIds);
                foreach ($teamCampaigns as $cid => $v) {
                    $visible[(int)$cid] = true;
                }
            }
            $directAssigned = getAssignedCampaignIdsForUser($currentUserId);
            foreach ($directAssigned as $cid => $v) {
                $visible[(int)$cid] = true;
            }
        }
        if (!isset($visible[$campaignId])) {
            echo json_encode(['ok' => false, 'message' => 'Not allowed']);
            exit;
        }
    } elseif (isSDR()) {
        $assigned = getAssignedCampaignIdsForUser($currentUserId);
        if (!isset($assigned[$campaignId])) {
            echo json_encode(['ok' => false, 'message' => 'Not allowed']);
            exit;
        }
    } else {
        $st = (string)($det['status'] ?? '');
        if (!in_array($st, ['Live','Active'], true)) {
            echo json_encode(['ok' => false, 'message' => 'Not allowed']);
            exit;
        }
    }
}

$form = getFormForCampaign($campaignId);
if (!$form) {
    echo json_encode(['ok' => true, 'form' => null]);
    exit;
}

echo json_encode([
    'ok' => true,
    'form' => [
        'form_id' => $form['form_id'],
        'name' => $form['name'],
        'schema' => $form['schema'],
    ],
]);
