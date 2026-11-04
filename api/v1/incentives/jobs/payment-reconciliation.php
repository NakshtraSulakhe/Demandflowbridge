<?php
/**
 * DemandFlow Bridge CRM - Payout Reconciliation Job
 * Note: Automatic incentive payment feature has been removed from this project.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    echo "[NOTICE] Automatic incentive payment reconciliation is disabled as the payment feature has been removed.\n";
    exit(0);
}

IncentiveResponse::error('Automatic incentive payment reconciliation is disabled.', 'PAYMENT_FEATURE_REMOVED', 410);
