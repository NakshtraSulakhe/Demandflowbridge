<?php
/**
 * DemandFlow Bridge CRM - End-of-Day Automated Incentive Settlement Job
 * Runs via CLI Cron or Secured HTTP Webhook
 * 
 * Usage (CLI):
 *   php api/v1/incentives/jobs/daily-settlement.php [date=YYYY-MM-DD] [token=SECURE_TOKEN]
 * 
 * Usage (HTTP Webhook / cURL):
 *   POST https://crm.yourdomain.com/api/v1/incentives/jobs/daily-settlement.php?date=YYYY-MM-DD
 *   Header: Authorization: Bearer <SECURE_TOKEN>
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

// Security check: If accessed via HTTP, require valid auth token
if (!$isCli) {
    $user = IncentiveAuth::requireAuth();
    IncentiveAuthZ::enforceCanManageSettlement($user);
}

// Parse input arguments
$readArgs = function (): array {
    global $argv;
    $out = [];
    if (!isset($argv) || !is_array($argv)) return $out;
    foreach (array_slice($argv, 1) as $arg) {
        $arg = trim((string)$arg);
        if ($arg === '') continue;
        $eqPos = strpos($arg, '=');
        if ($eqPos === false) {
            $out[$arg] = '1';
            continue;
        }
        $key = trim(substr($arg, 0, $eqPos));
        $val = trim(substr($arg, $eqPos + 1));
        if ($key !== '') $out[$key] = $val;
    }
    return $out;
};

$args = $isCli ? $readArgs() : array_merge($_GET ?? [], $_POST ?? []);

// Determine target date (defaults to operational business date)
$workDate = isset($args['date']) && is_string($args['date']) ? trim($args['date']) : '';
if ($workDate === '') {
    $workDate = incentiveBusinessDate();
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    if ($isCli) {
        fwrite(STDERR, "[ERROR] Invalid date format: {$workDate}. Expected YYYY-MM-DD.\n");
        exit(1);
    }
    IncentiveResponse::error("Invalid date format: {$workDate}. Expected YYYY-MM-DD.", 'INVALID_DATE', 400);
}

$mode = isset($args['mode']) && in_array(strtolower($args['mode']), ['finalize', 'dry_run', 'recalculate_unpaid'], true)
    ? strtolower($args['mode'])
    : 'finalize';

try {
    if ($isCli) {
        echo sprintf("[%s] Starting End-of-Day Incentive Settlement for %s (Mode: %s)...\n", date('Y-m-d H:i:s'), $workDate, $mode);
    }

    $result = SettlementService::settleWorkDate($workDate, $mode, 0);

    if ($isCli) {
        echo sprintf("[%s] Settlement Complete!\n", date('Y-m-d H:i:s'));
        echo sprintf("  - Total Agents Evaluated: %d\n", $result['total_agents'] ?? 0);
        echo sprintf("  - Eligible Agents:        %d\n", $result['eligible_count'] ?? 0);
        echo sprintf("  - Total Incentive Amount: INR %s\n", number_format((float)($result['total_settled_amount'] ?? 0), 2));
        echo sprintf("  - Records Created:        %d\n", $result['records_created'] ?? 0);
        echo sprintf("  - Records Updated:        %d\n", $result['records_updated'] ?? 0);
        echo sprintf("  - Records Skipped:        %d\n", $result['records_skipped'] ?? 0);
        echo "[NOTICE] Incentive records are locked and awaiting manager approval. No funds were transferred.\n";
        exit(0);
    }

    IncentiveResponse::success($result, "End-of-day settlement for {$workDate} executed successfully via scheduler.");

} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "[ERROR] Settlement Job Failed: " . $e->getMessage() . "\n");
        exit(1);
    }
    IncentiveResponse::error('Daily settlement job failed: ' . $e->getMessage(), 'JOB_ERROR', 500);
}
