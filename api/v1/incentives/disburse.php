<?php
/**
 * DemandFlow Bridge CRM - Bank / UPI Payout Disbursement API
 * POST /api/v1/incentives/disburse.php
 * 
 * Initiates authorized payment disbursement through configured payment provider
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();
IncentiveAuthZ::enforceCanDisburse($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    IncentiveResponse::error('Method not allowed. Only POST is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$payoutId = isset($input['payout_id']) ? (int)$input['payout_id'] : 0;
if ($payoutId <= 0) {
    IncentiveResponse::error('Valid payout_id is required.', 'INVALID_PAYOUT_ID', 400);
}

$paymentMethod = isset($input['payment_method']) ? strtolower(trim((string)$input['payment_method'])) : null;
if ($paymentMethod !== null && !in_array($paymentMethod, ['bank_transfer', 'upi'], true)) {
    IncentiveResponse::error("Invalid payment_method. Expected 'bank_transfer' or 'upi'.", 'INVALID_PAYMENT_METHOD', 400);
}

try {
    $initiatorId = (int)($user['id'] ?? 0);
    $result = PayoutService::disbursePayout($payoutId, $initiatorId, $paymentMethod);

    if ($result['success']) {
        IncentiveResponse::success($result, "Disbursement request processed. Current status: {$result['payment_status']}.", 200);
    } else {
        IncentiveResponse::error($result['error'] ?? 'Disbursement failed.', $result['error_code'] ?? 'DISBURSE_FAILED', 400);
    }

} catch (Throwable $e) {
    IncentiveResponse::error('Disbursement processing failed: ' . $e->getMessage(), 'DISBURSE_ERROR', 500);
}
