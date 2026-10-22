<?php
/**
 * DemandFlow Bridge CRM - Manager Incentive Approval API
 * POST /api/v1/incentives/approve.php
 * 
 * Supports single and bulk manager approval/rejection with immutable audit logs
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();
IncentiveAuthZ::enforceCanApprove($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    IncentiveResponse::error('Method not allowed. Only POST is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$payoutIds = $input['payout_ids'] ?? [];
if (!is_array($payoutIds)) {
    if (isset($input['payout_id'])) {
        $payoutIds = [(int)$input['payout_id']];
    } else {
        IncentiveResponse::error('payout_ids must be an array of integers.', 'INVALID_PAYOUT_IDS', 400);
    }
}

if (empty($payoutIds)) {
    IncentiveResponse::error('No payout IDs provided for approval/rejection.', 'EMPTY_PAYOUT_IDS', 400);
}

$action = isset($input['action']) ? strtolower(trim((string)$input['action'])) : 'approve';
if (!in_array($action, ['approve', 'reject'], true)) {
    IncentiveResponse::error("Invalid action '{$action}'. Expected 'approve' or 'reject'.", 'INVALID_ACTION', 400);
}

$remarks = isset($input['remarks']) ? trim((string)$input['remarks']) : '';
$managerId = (int)($user['id'] ?? 0);

try {
    $result = PayoutService::processApprovals($payoutIds, $action, $managerId, $remarks);

    $msg = sprintf(
        "Processed %d payout(s): %d %sd, %d failed.",
        $result['total_requested'],
        $result['successful_count'],
        $action,
        $result['failed_count']
    );

    IncentiveResponse::success($result, $msg, 200);

} catch (Throwable $e) {
    IncentiveResponse::error('Approval processing failed: ' . $e->getMessage(), 'APPROVAL_ERROR', 500);
}
