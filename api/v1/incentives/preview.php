<?php
/**
 * DemandFlow Bridge CRM - Daily Incentive Preview API
 * GET /api/v1/incentives/preview.php?date=YYYY-MM-DD
 * 
 * Read-only preview of estimated daily incentives based on current QA results
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    IncentiveResponse::error('Method not allowed. Only GET is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$date = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
if ($date === '') {
    $date = incentiveBusinessDate();
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    IncentiveResponse::error('Invalid date format. Expected YYYY-MM-DD.', 'INVALID_DATE', 400);
}

try {
    $conn = getDbConnection();
    ensureIncentiveSchema($conn);

    $calculation = IncentiveCalculator::calculateAllAgentsDaily($date);

    $responseMeta = [
        'mode' => 'preview',
        'is_provisional' => true,
        'notice' => 'Provisional QA results: Scores, points, and incentive amounts may change if QA audits are updated before end-of-day settlement cutoff.',
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    IncentiveResponse::success($calculation, "Daily incentive preview for {$date} generated successfully.", 200, $responseMeta);

} catch (Throwable $e) {
    IncentiveResponse::error('Failed to generate daily preview: ' . $e->getMessage(), 'PREVIEW_ERROR', 500);
}
