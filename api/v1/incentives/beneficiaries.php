<?php
/**
 * DemandFlow Bridge CRM - Incentive Beneficiary Configuration API
 * GET /api/v1/incentives/beneficiaries.php?agent_id=123
 * POST /api/v1/incentives/beneficiaries.php
 * 
 * Allows configuring Bank Transfer and UPI payout preferences securely
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$conn = getDbConnection();
ensureIncentiveSchema($conn);

if ($method === 'GET') {
    $agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : (int)($user['id'] ?? 0);
    if ($agentId <= 0) {
        IncentiveResponse::error('agent_id parameter is required.', 'INVALID_AGENT_ID', 400);
    }

    // Role check: Only manager or self
    $callerId = (int)($user['id'] ?? 0);
    $isManager = IncentiveAuthZ::canApprove($user);
    if (!$isManager && $callerId > 0 && $callerId !== $agentId) {
        IncentiveResponse::forbidden('You are only authorized to view your own beneficiary settings.');
    }

    $bankBen = PayoutService::resolveBeneficiary($agentId, 'bank_transfer');
    $upiBen = PayoutService::resolveBeneficiary($agentId, 'upi');

    IncentiveResponse::success([
        'agent_id' => $agentId,
        'bank_transfer' => $bankBen ? [
            'name' => $bankBen['name'],
            'bank_name' => $bankBen['bank_name'],
            'masked_account' => $bankBen['masked_account'],
            'ifsc' => $bankBen['ifsc'],
            'source' => $bankBen['source'],
        ] : null,
        'upi' => $upiBen ? [
            'name' => $upiBen['name'],
            'masked_upi' => $upiBen['masked_upi'],
            'source' => $upiBen['source'],
        ] : null,
    ], "Beneficiary details retrieved.");

} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $agentId = isset($input['agent_id']) ? (int)$input['agent_id'] : (int)($user['id'] ?? 0);
    if ($agentId <= 0) {
        IncentiveResponse::error('agent_id is required.', 'INVALID_AGENT_ID', 400);
    }

    $callerId = (int)($user['id'] ?? 0);
    $isManager = IncentiveAuthZ::canApprove($user);
    if (!$isManager && $callerId > 0 && $callerId !== $agentId) {
        IncentiveResponse::forbidden('You are only authorized to configure your own beneficiary settings.');
    }

    $paymentMethod = isset($input['payment_method']) ? strtolower(trim((string)$input['payment_method'])) : 'bank_transfer';
    if (!in_array($paymentMethod, ['bank_transfer', 'upi'], true)) {
        IncentiveResponse::error("Invalid payment_method. Expected 'bank_transfer' or 'upi'.", 'INVALID_METHOD', 400);
    }

    $holderName = trim((string)($input['account_holder_name'] ?? ''));
    $bankName = trim((string)($input['bank_name'] ?? ''));
    $accountNumber = trim((string)($input['account_number'] ?? ''));
    $ifsc = strtoupper(trim((string)($input['ifsc_code'] ?? '')));
    $upiId = strtolower(trim((string)($input['upi_id'] ?? '')));

    if ($paymentMethod === 'bank_transfer') {
        if ($accountNumber === '' || strlen($accountNumber) < 6) {
            IncentiveResponse::error('A valid bank account number is required.', 'INVALID_ACCOUNT_NUMBER', 400);
        }
        if ($ifsc === '' || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            IncentiveResponse::error('A valid 11-character Indian IFSC code is required (e.g. HDFC0001234).', 'INVALID_IFSC', 400);
        }
    } elseif ($paymentMethod === 'upi') {
        if ($upiId === '' || !str_contains($upiId, '@')) {
            IncentiveResponse::error('A valid UPI ID is required (e.g. username@okaxis).', 'INVALID_UPI_ID', 400);
        }
    }

    $maskedAccount = $accountNumber !== '' ? IncentiveAuth::maskAccountNumber($accountNumber) : null;
    $maskedUpi = $upiId !== '' ? IncentiveAuth::maskUpiId($upiId) : null;

    $stmtSave = $conn->prepare("
        INSERT INTO incentive_beneficiaries (
            user_id, payment_method, account_holder_name, bank_name,
            account_number_masked, account_number_encrypted, ifsc_code,
            upi_id_masked, upi_id_encrypted, is_verified, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            account_holder_name = VALUES(account_holder_name),
            bank_name = VALUES(bank_name),
            account_number_masked = VALUES(account_number_masked),
            account_number_encrypted = VALUES(account_number_encrypted),
            ifsc_code = VALUES(ifsc_code),
            upi_id_masked = VALUES(upi_id_masked),
            upi_id_encrypted = VALUES(upi_id_encrypted),
            is_verified = 1,
            updated_at = NOW()
    ");

    if ($stmtSave) {
        $stmtSave->bind_param(
            'issssssss',
            $agentId,
            $paymentMethod,
            $holderName,
            $bankName,
            $maskedAccount,
            $accountNumber,
            $ifsc,
            $maskedUpi,
            $upiId
        );
        $stmtSave->execute();
        $stmtSave->close();

        IncentiveResponse::success([
            'agent_id' => $agentId,
            'payment_method' => $paymentMethod,
            'masked_account' => $maskedAccount,
            'masked_upi' => $maskedUpi,
            'is_verified' => true,
        ], "Beneficiary configuration saved successfully.");
    } else {
        IncentiveResponse::error('Database error saving beneficiary details.', 'DB_ERROR', 500);
    }
} else {
    IncentiveResponse::error('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}
