<?php
/**
 * DemandFlow Bridge CRM - Payment Provider Abstraction Layer
 * Supports Sandbox/Mock simulation and production adapters (RazorpayX, Cashfree)
 */

interface PaymentProviderInterface {
    /**
     * Initiates a payout transfer to the beneficiary
     * 
     * @param array $params [
     *   'amount' => float,
     *   'currency' => string,
     *   'payment_method' => 'bank_transfer' | 'upi',
     *   'idempotency_key' => string,
     *   'beneficiary' => [
     *       'name' => string,
     *       'account_number' => string,
     *       'ifsc' => string,
     *       'upi_id' => string,
     *   ],
     *   'narration' => string,
     * ]
     * @return array [
     *   'success' => bool,
     *   'provider_payout_id' => string,
     *   'provider_reference' => string,
     *   'status' => 'queued' | 'processing' | 'success' | 'failed',
     *   'raw_response' => array,
     *   'error_code' => ?string,
     *   'error_message' => ?string,
     * ]
     */
    public function createPayout(array $params): array;

    /**
     * Polls the provider for the authoritative status of a payout
     */
    public function getPayoutStatus(string $providerPayoutId, string $providerReference): array;

    /**
     * Validates incoming webhook signature
     */
    public function verifyWebhookSignature(string $rawPayload, string $signature, string $secret): bool;
}

/**
 * Safe Sandbox / Mock Provider for testing full lifecycle without moving real funds
 */
class MockPaymentProvider implements PaymentProviderInterface {
    public function createPayout(array $params): array {
        $idempotencyKey = (string)($params['idempotency_key'] ?? uniqid('idemp_', true));
        $amount = (float)($params['amount'] ?? 0.0);

        // Simulation hooks for automated testing
        if ($amount == 999.99) {
            return [
                'success' => false,
                'provider_payout_id' => '',
                'provider_reference' => '',
                'status' => 'failed',
                'raw_response' => ['simulated_failure' => true],
                'error_code' => 'INSUFFICIENT_FUNDS',
                'error_message' => 'Simulated test failure: Insufficient balance in payout account.',
            ];
        }

        $payoutId = 'mock_pout_' . substr(md5($idempotencyKey), 0, 16);
        $reference = 'MOCK-REF-' . strtoupper(substr(md5(microtime()), 0, 10));

        // Payouts start in 'processing' state to match asynchronous banking realities
        return [
            'success' => true,
            'provider_payout_id' => $payoutId,
            'provider_reference' => $reference,
            'status' => 'processing',
            'raw_response' => [
                'id' => $payoutId,
                'entity' => 'payout',
                'fund_account_id' => 'mock_fa_' . substr(md5($payoutId), 0, 10),
                'amount' => (int)($amount * 100),
                'currency' => 'INR',
                'status' => 'processing',
                'utr' => $reference,
                'mode' => strtoupper(($params['payment_method'] ?? 'bank_transfer') === 'upi' ? 'UPI' : 'IMPS'),
            ],
            'error_code' => null,
            'error_message' => null,
        ];
    }

    public function getPayoutStatus(string $providerPayoutId, string $providerReference): array {
        return [
            'status' => 'success',
            'provider_payout_id' => $providerPayoutId,
            'provider_reference' => $providerReference,
            'utr' => $providerReference,
            'raw_response' => ['status' => 'processed'],
        ];
    }

    public function verifyWebhookSignature(string $rawPayload, string $signature, string $secret): bool {
        // Accept mock signature or check test token
        return !empty($signature);
    }
}

/**
 * RazorpayX Payout Provider Adapter
 */
class RazorpayXPaymentProvider implements PaymentProviderInterface {
    private string $keyId;
    private string $keySecret;
    private string $accountNumber;
    private string $baseUrl;

    public function __construct() {
        $this->keyId = (string)(getenv('RAZORPAYX_KEY_ID') ?: '');
        $this->keySecret = (string)(getenv('RAZORPAYX_KEY_SECRET') ?: '');
        $this->accountNumber = (string)(getenv('RAZORPAYX_ACCOUNT_NUMBER') ?: '');
        $this->baseUrl = 'https://api.razorpay.com/v1';
    }

    public function createPayout(array $params): array {
        if ($this->keyId === '' || $this->keySecret === '') {
            return [
                'success' => false,
                'provider_payout_id' => '',
                'provider_reference' => '',
                'status' => 'failed',
                'raw_response' => [],
                'error_code' => 'GATEWAY_NOT_CONFIGURED',
                'error_message' => 'RazorpayX credentials are not configured.',
            ];
        }

        $idempotencyKey = (string)($params['idempotency_key'] ?? '');
        $amountInPaise = (int)round(((float)$params['amount']) * 100);
        $method = ($params['payment_method'] ?? 'bank_transfer') === 'upi' ? 'UPI' : 'IMPS';

        $body = [
            'account_number' => $this->accountNumber,
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'mode' => $method,
            'purpose' => 'incentive',
            'narration' => substr((string)($params['narration'] ?? 'Daily Incentive'), 0, 30),
        ];

        // Format fund account details
        $beneficiary = $params['beneficiary'] ?? [];
        if ($method === 'UPI') {
            $body['fund_account'] = [
                'account_type' => 'vpa',
                'vpa' => ['address' => (string)($beneficiary['upi_id'] ?? '')],
                'contact' => [
                    'name' => (string)($beneficiary['name'] ?? 'Agent'),
                    'type' => 'employee',
                ],
            ];
        } else {
            $body['fund_account'] = [
                'account_type' => 'bank_account',
                'bank_account' => [
                    'name' => (string)($beneficiary['name'] ?? 'Agent'),
                    'ifsc' => (string)($beneficiary['ifsc'] ?? ''),
                    'account_number' => (string)($beneficiary['account_number'] ?? ''),
                ],
                'contact' => [
                    'name' => (string)($beneficiary['name'] ?? 'Agent'),
                    'type' => 'employee',
                ],
            ];
        }

        $ch = curl_init($this->baseUrl . '/payouts');
        curl_setopt($ch, CURLOPT_USERPWD, $this->keyId . ':' . $this->keySecret);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Payout-Idempotency: ' . $idempotencyKey,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'provider_payout_id' => '',
                'provider_reference' => '',
                'status' => 'failed',
                'raw_response' => ['curl_error' => $curlErr],
                'error_code' => 'GATEWAY_TIMEOUT',
                'error_message' => 'Network timeout contacting RazorpayX: ' . $curlErr,
            ];
        }

        $decoded = json_decode($response, true) ?: [];
        if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['id'])) {
            $status = strtolower((string)($decoded['status'] ?? 'processing'));
            $mappedStatus = ($status === 'processed') ? 'success' : 'processing';
            return [
                'success' => true,
                'provider_payout_id' => (string)$decoded['id'],
                'provider_reference' => (string)($decoded['utr'] ?? $decoded['id']),
                'status' => $mappedStatus,
                'raw_response' => $decoded,
                'error_code' => null,
                'error_message' => null,
            ];
        }

        return [
            'success' => false,
            'provider_payout_id' => (string)($decoded['id'] ?? ''),
            'provider_reference' => '',
            'status' => 'failed',
            'raw_response' => $decoded,
            'error_code' => (string)($decoded['error']['code'] ?? 'PAYOUT_REJECTED'),
            'error_message' => (string)($decoded['error']['description'] ?? 'Gateway rejected payout request.'),
        ];
    }

    public function getPayoutStatus(string $providerPayoutId, string $providerReference): array {
        $ch = curl_init($this->baseUrl . '/payouts/' . urlencode($providerPayoutId));
        curl_setopt($ch, CURLOPT_USERPWD, $this->keyId . ':' . $this->keySecret);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string)$response, true) ?: [];
        $st = strtolower((string)($decoded['status'] ?? ''));
        $status = 'processing';
        if ($st === 'processed') $status = 'success';
        if ($st === 'reversed' || $st === 'rejected' || $st === 'failed') $status = 'failed';

        return [
            'status' => $status,
            'provider_payout_id' => $providerPayoutId,
            'provider_reference' => (string)($decoded['utr'] ?? $providerReference),
            'utr' => (string)($decoded['utr'] ?? ''),
            'raw_response' => $decoded,
        ];
    }

    public function verifyWebhookSignature(string $rawPayload, string $signature, string $secret): bool {
        if ($signature === '' || $secret === '') return false;
        $expected = hash_hmac('sha256', $rawPayload, $secret);
        return hash_equals($expected, $signature);
    }
}

/**
 * Provider Factory
 */
class PaymentProviderFactory {
    public static function getProvider(?string $providerName = null): PaymentProviderInterface {
        $name = strtolower(trim($providerName ?: (getenv('PAYOUT_PROVIDER') ?: 'sandbox')));

        // Safety enforcement: If live mode is not explicitly enabled, keep on Sandbox
        $liveEnabled = (bool)(getenv('PAYOUT_LIVE_ENABLED') === 'true');

        if ($name === 'razorpayx' && $liveEnabled) {
            return new RazorpayXPaymentProvider();
        }

        // Default safe sandbox provider
        return new MockPaymentProvider();
    }
}
