<?php
/**
 * DemandFlow Bridge CRM - Asynchronous Payment Webhook API
 * POST /api/v1/incentives/webhook.php
 * 
 * Handles webhook notifications from payment providers (RazorpayX, Cashfree, Mock)
 */

require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    IncentiveResponse::error('Method not allowed. Only POST is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$rawPayload = file_get_contents('php://input');
if ($rawPayload === false || trim($rawPayload) === '') {
    IncentiveResponse::error('Empty webhook payload.', 'EMPTY_PAYLOAD', 400);
}

$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$headerMap = [];
foreach ($headers as $k => $v) {
    $headerMap[strtolower($k)] = trim((string)$v);
}

$signature = $headerMap['x-webhook-signature'] ?? ($headerMap['x-razorpay-signature'] ?? '');
$webhookSecret = (string)(getenv('PAYOUT_WEBHOOK_SECRET') ?: 'mock_webhook_secret');
$providerName = (string)(getenv('PAYOUT_PROVIDER') ?: 'sandbox');

$provider = PaymentProviderFactory::getProvider($providerName);

// 1. Signature verification
if ($providerName !== 'sandbox' && !$provider->verifyWebhookSignature($rawPayload, $signature, $webhookSecret)) {
    IncentiveResponse::error('Invalid webhook signature.', 'INVALID_SIGNATURE', 401);
}

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    IncentiveResponse::error('Malformed JSON in webhook body.', 'INVALID_JSON', 400);
}

$conn = getDbConnection();
ensureIncentiveSchema($conn);

// 2. Extract event parameters
// Generic extraction supporting standard payout webhooks
$eventType = (string)($payload['event'] ?? ($payload['type'] ?? 'payout.processed'));
$eventId = (string)($payload['event_id'] ?? ($payload['id'] ?? md5($rawPayload)));
$payoutEntity = $payload['payload']['payout']['entity'] ?? ($payload['data']['payout'] ?? $payload);

$providerPayoutId = (string)($payoutEntity['id'] ?? ($payoutEntity['payout_id'] ?? ''));
$utr = (string)($payoutEntity['utr'] ?? ($payoutEntity['reference_id'] ?? ''));
$status = strtolower((string)($payoutEntity['status'] ?? ''));

if ($providerPayoutId === '') {
    IncentiveResponse::error('Missing provider payout identifier in webhook payload.', 'MISSING_PAYOUT_ID', 400);
}

// 3. Replay attack and deduplication check
$stmtDedup = $conn->prepare("
    SELECT id FROM incentive_payout_events 
    WHERE event_type = ? AND event_data LIKE ? 
    LIMIT 1
");
$dedupQuery = "%\"event_id\":\"{$eventId}\"%";
$alreadyHandled = false;
if ($stmtDedup) {
    $stmtDedup->bind_param('ss', $eventType, $dedupQuery);
    $stmtDedup->execute();
    $alreadyHandled = (bool)$stmtDedup->get_result()->fetch_assoc();
    $stmtDedup->close();
}

if ($alreadyHandled) {
    IncentiveResponse::success(['deduplicated' => true], "Event {$eventId} already processed.");
}

// 4. Match attempt and payout
$stmtMatch = $conn->prepare("
    SELECT a.id AS attempt_id, a.payout_id, a.attempt_status, p.payment_status, p.total_incentive, p.agent_id, p.work_date
    FROM incentive_payment_attempts a
    JOIN daily_incentive_payouts p ON p.id = a.payout_id
    WHERE a.provider_payout_id = ?
    ORDER BY a.id DESC 
    LIMIT 1
");

$match = null;
if ($stmtMatch) {
    $stmtMatch->bind_param('s', $providerPayoutId);
    $stmtMatch->execute();
    $match = $stmtMatch->get_result()->fetch_assoc();
    $stmtMatch->close();
}

if (!$match) {
    // Attempt match by idempotency key or reference if provider ID was late
    IncentiveResponse::error("No payment attempt matching provider payout ID '{$providerPayoutId}'.", 'PAYOUT_NOT_FOUND', 404);
}

$attemptId = (int)$match['attempt_id'];
$payoutId = (int)$match['payout_id'];
$currentPayStatus = (string)$match['payment_status'];
$amount = (float)$match['total_incentive'];
$agentId = (int)$match['agent_id'];
$workDate = (string)$match['work_date'];

// 5. Monotonic state transition
// Never move backwards from 'paid'
if ($currentPayStatus === 'paid') {
    IncentiveResponse::success(['status' => 'already_paid'], "Payout {$payoutId} already confirmed as paid.");
}

$conn->begin_transaction();
try {
    if (in_array($status, ['processed', 'success', 'paid'], true)) {
        $conn->query("UPDATE incentive_payment_attempts SET attempt_status = 'success', provider_reference = '{$utr}', completed_at = NOW() WHERE id = {$attemptId}");
        $conn->query("UPDATE daily_incentive_payouts SET payment_status = 'paid', updated_at = NOW() WHERE id = {$payoutId}");

        SettlementService::recordEvent($conn, $payoutId, 'webhook_payout_success', 'webhook', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'provider_payout_id' => $providerPayoutId,
            'utr' => $utr,
        ]);

        if (function_exists('createNotificationSmart')) {
            $msg = sprintf("Your daily incentive of INR %s for %s has been confirmed by the bank.", number_format($amount, 2), $workDate);
            createNotificationSmart($agentId, 'incentive.paid', 'Incentive Transfer Confirmed', $msg, '../performance/dashboard', [
                'dedup_key' => "inc_wb_{$payoutId}",
            ]);
        }
    } elseif (in_array($status, ['failed', 'reversed', 'rejected'], true)) {
        $failureReason = (string)($payoutEntity['failure_reason'] ?? ($payoutEntity['error']['description'] ?? 'Bank transfer failed or was reversed'));
        $stmtFail = $conn->prepare("UPDATE incentive_payment_attempts SET attempt_status = 'failed', failure_reason = ?, completed_at = NOW() WHERE id = ?");
        if ($stmtFail) {
            $stmtFail->bind_param('si', $failureReason, $attemptId);
            $stmtFail->execute();
            $stmtFail->close();
        }
        $conn->query("UPDATE daily_incentive_payouts SET payment_status = 'failed', updated_at = NOW() WHERE id = {$payoutId}");

        SettlementService::recordEvent($conn, $payoutId, 'webhook_payout_failed', 'webhook', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'reason' => $failureReason,
        ]);
    }

    $conn->commit();
    IncentiveResponse::success(['processed' => true, 'payout_id' => $payoutId], "Webhook event {$eventId} processed successfully.");

} catch (Throwable $e) {
    $conn->rollback();
    IncentiveResponse::error('Failed to process webhook transaction: ' . $e->getMessage(), 'WEBHOOK_PROCESS_ERROR', 500);
}
