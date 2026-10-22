<?php
/**
 * DemandFlow Bridge CRM - Incentive API Bootstrap
 * Isolated initialization for the Daily Incentive Payout API Layer
 */

// Enable clean JSON headers and CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Webhook-Signature, X-Idempotency-Key');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Load Core CRM files without modifying them
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/constants.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Load Incentive Subsystem Components
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/authentication.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/incentive-calculator.php';
require_once __DIR__ . '/payment-provider.php';
require_once __DIR__ . '/payout-service.php';
require_once __DIR__ . '/settlement-service.php';
require_once __DIR__ . '/reconciliation-service.php';

/**
 * Ensures incentive subsystem tables exist in the database without altering existing tables
 */
function ensureIncentiveSchema(mysqli $conn): void {
    static $initialized = false;
    if ($initialized) return;

    $migrationFile = __DIR__ . '/../migrations/create_incentive_tables.sql';
    if (file_exists($migrationFile)) {
        $sql = file_get_contents($migrationFile);
        if ($sql) {
            // Strip SQL line comments
            $cleanSql = preg_replace('/--[^\r\n]*/', '', $sql);
            $statements = array_filter(array_map('trim', explode(';', (string)$cleanSql)));
            foreach ($statements as $stmt) {
                if ($stmt !== '') {
                    @$conn->query($stmt);
                }
            }
        }
    }
    $initialized = true;
}

/**
 * Resolves the operational business date in CRM timezone
 */
function incentiveBusinessDate(): string {
    if (function_exists('opsBusinessTz')) {
        $tz = opsBusinessTz();
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    }
    return date('Y-m-d');
}

/**
 * Resolves the operational business timezone
 */
function incentiveBusinessTimezone(): DateTimeZone {
    if (function_exists('opsBusinessTz')) {
        return opsBusinessTz();
    }
    return new DateTimeZone(date_default_timezone_get() ?: 'UTC');
}
