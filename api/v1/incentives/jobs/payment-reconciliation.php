<?php
/**
 * DemandFlow Bridge CRM - Payout Reconciliation Job
 * Reconciles ambiguous/processing provider payouts against banking API
 * 
 * Usage (CLI):
 *   php api/v1/incentives/jobs/payment-reconciliation.php [token=SECURE_TOKEN]
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $user = IncentiveAuth::requireAuth();
    IncentiveAuthZ::enforceCanDisburse($user);
}

try {
    if ($isCli) {
        echo sprintf("[%s] Checking for processing incentive payouts...\n", date('Y-m-d H:i:s'));
    }

    $result = ReconciliationService::reconcileProcessingPayouts();

    if ($isCli) {
        echo sprintf("[%s] Reconciliation Job Complete!\n", date('Y-m-d H:i:s'));
        echo sprintf("  - Payouts Reconciled:   %d\n", $result['reconciled_count']);
        echo sprintf("  - Still Processing:     %d\n", $result['still_processing_count']);
        exit(0);
    }

    IncentiveResponse::success($result, "Payment reconciliation job completed.");

} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "[ERROR] Reconciliation Job Failed: " . $e->getMessage() . "\n");
        exit(1);
    }
    IncentiveResponse::error('Payment reconciliation failed: ' . $e->getMessage(), 'RECONCILIATION_ERROR', 500);
}
