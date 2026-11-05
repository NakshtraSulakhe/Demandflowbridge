<?php
/**
 * DemandFlow Bridge - QA Team Live Audit Incentive Calculation API
 * Endpoint: GET / POST /api/v1/qa-incentives/calculate.php
 * 
 * Used by Admin Portal & Slides App to calculate QA incentives based on:
 * - Actual leads audited
 * - Status counts (Qualified, Disqualified, Pending, etc.)
 * - Base Auditing Target & Base Reward
 * - Tier Volume Accelerators (Slab bonuses)
 * - Client Code & Campaign filtering
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../slides_analytics.php';

try {
    $conn = getDbConnection();

    $rawInput = file_get_contents('php://input');
    $bodyData = !empty($rawInput) ? json_decode($rawInput, true) : [];
    if (!is_array($bodyData))
        $bodyData = [];

    $params = array_merge($_GET, $_POST, $bodyData);
    $data = getQaDailyAuditsData($conn, $params);

    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

