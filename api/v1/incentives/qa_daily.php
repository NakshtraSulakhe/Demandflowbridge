<?php
/**
 * DemandFlow Bridge CRM - QA Daily Incentive Audits API
 * Endpoint: GET /api/v1/incentives/qa_daily.php?date=YYYY-MM-DD
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/../slides_analytics.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    IncentiveResponse::error('Method not allowed. Only GET is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$date = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
if ($date === '') {
    $date = function_exists('incentiveBusinessDate') ? incentiveBusinessDate() : date('Y-m-d');
}

try {
    $conn = getDbConnection();
    $data = getQaDailyAuditsData($conn, $_GET);
    IncentiveResponse::success($data, "QA daily audit and incentive feed for {$date} generated successfully.", 200);
} catch (Throwable $e) {
    IncentiveResponse::error('Failed to generate QA audit feed: ' . $e->getMessage(), 'QA_FEED_ERROR', 500);
}

