<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$allowed = ['admin','director','manager_director','operations_director','operations_manager'];
requireRole($allowed);

$campaignId = (int)($_GET['campaign_id'] ?? 0);
if ($campaignId <= 0) { header('Location: list'); exit; }
if (hasRole($allowed)) {
    $qs = http_build_query(['campaign_id' => $campaignId]);
    header('Location: ../dashboard/operations-assign?'.$qs);
    exit;
}
header('Location: list');
exit;
