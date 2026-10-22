<?php
/**
 * DemandFlow Bridge CRM - End-of-Day Settlement API
 * POST /api/v1/incentives/settle.php
 * 
 * Finalizes day's QA audit scoring into immutable settlement records
 * Does NOT disburse funds. Prepares records for manager approval.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();
IncentiveAuthZ::enforceCanManageSettlement($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    IncentiveResponse::error('Method not allowed. Only POST is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$workDate = isset($input['work_date']) && is_string($input['work_date']) ? trim($input['work_date']) : '';
if ($workDate === '') {
    $workDate = incentiveBusinessDate();
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    IncentiveResponse::error('Invalid work_date format. Expected YYYY-MM-DD.', 'INVALID_DATE', 400);
}

$mode = isset($input['mode']) && is_string($input['mode']) ? strtolower(trim($input['mode'])) : 'finalize';
if (!in_array($mode, ['finalize', 'dry_run', 'recalculate_unpaid'], true)) {
    $mode = 'finalize';
}

try {
    $result = SettlementService::settleWorkDate($workDate, $mode, (int)($user['id'] ?? 0));

    IncentiveResponse::success(
        $result,
        "Settlement for {$workDate} completed successfully ({$mode}). Eligible payouts are awaiting manager approval.",
        200
    );

} catch (Throwable $e) {
    IncentiveResponse::error('Settlement execution failed: ' . $e->getMessage(), 'SETTLEMENT_ERROR', 500);
}
