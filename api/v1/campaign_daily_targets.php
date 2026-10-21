<?php
/**
 * DemandFlow Bridge - Campaign Daily Targets Admin API
 * 
 * Allows the external slide dashboard admin panel to:
 * 1. Fetch live campaigns with monthly allocation (total_leads) and current daily targets.
 * 2. Set or update daily targets for campaigns on any given date.
 * 
 * Endpoints:
 * - GET  /api/v1/campaign_daily_targets.php?date=YYYY-MM-DD
 * - POST /api/v1/campaign_daily_targets.php
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Webhook-Secret');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

/**
 * Ensures the target storage table exists without modifying any existing schema files
 */
function ensureCampaignDailyTargetsTable(mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS campaign_daily_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        target_date DATE NOT NULL,
        daily_target INT NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        allocated_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_campaign_date (campaign_id, target_date),
        INDEX idx_date (target_date),
        INDEX idx_campaign (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

$scriptFile = isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME']) ? basename($_SERVER['SCRIPT_FILENAME']) : '';
if ($scriptFile !== basename(__FILE__)) {
    return;
}

try {
    $conn = getDbConnection();
    ensureCampaignDailyTargetsTable($conn);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
        $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
            ? (string)$_GET['date']
            : $todayDate;

        $statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'Live';

        // Fetch campaigns
        $whereClause = "WHERE c.active = 1";
        if (strtolower($statusFilter) !== 'all') {
            $whereClause .= " AND (d.status = 'Live' OR d.status IS NULL)";
        }

        $query = "
            SELECT 
                c.id AS campaign_id,
                c.name AS campaign_name,
                c.active,
                d.code AS campaign_code,
                d.client_code,
                d.status AS campaign_status,
                d.campaign_type,
                COALESCE(d.total_leads, 0) AS monthly_allocation,
                d.pacing_type,
                COALESCE(d.pacing_count, 0) AS pacing_count,
                d.start_date,
                d.end_date,
                COALESCE(t.daily_target, 0) AS daily_target,
                t.notes AS target_notes,
                t.updated_at AS target_updated_at
            FROM campaigns c
            LEFT JOIN campaign_details d ON d.campaign_id = c.id
            LEFT JOIN campaign_daily_targets t ON t.campaign_id = c.id AND t.target_date = ?
            {$whereClause}
            ORDER BY c.name ASC
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $campaigns = [];

        // Pre-fetch today's lead statistics per campaign
        $leadStatsQuery = "
            SELECT 
                campaign_id,
                COUNT(*) AS generated_leads,
                SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified_leads,
                SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified_leads,
                SUM(CASE WHEN qa_status NOT IN ('Qualified', 'Disqualified') OR qa_status IS NULL THEN 1 ELSE 0 END) AS pending_qa
            FROM leads
            WHERE DATE(created_at) = ?
            GROUP BY campaign_id
        ";
        $leadStatsStmt = $conn->prepare($leadStatsQuery);
        $leadStats = [];
        if ($leadStatsStmt) {
            $leadStatsStmt->bind_param('s', $date);
            $leadStatsStmt->execute();
            $lsResult = $leadStatsStmt->get_result();
            while ($row = $lsResult->fetch_assoc()) {
                $leadStats[(int)$row['campaign_id']] = $row;
            }
            $leadStatsStmt->close();
        }

        while ($c = $result->fetch_assoc()) {
            $cid = (int)$c['campaign_id'];
            $stats = $leadStats[$cid] ?? [
                'generated_leads' => 0,
                'qualified_leads' => 0,
                'disqualified_leads' => 0,
                'pending_qa' => 0,
            ];

            $dailyTarget = (int)$c['daily_target'];
            $qualified = (int)$stats['qualified_leads'];
            $generated = (int)$stats['generated_leads'];
            $disqualified = (int)$stats['disqualified_leads'];
            $pendingQa = (int)$stats['pending_qa'];

            $remainingQualified = max(0, $dailyTarget - $qualified);
            $remainingGenerated = max(0, $dailyTarget - $generated);

            $completionPct = $dailyTarget > 0 ? round(($qualified / $dailyTarget) * 100, 1) : 0.0;

            $campaigns[] = [
                'campaign_id' => $cid,
                'campaign_name' => (string)($c['campaign_name'] ?? ''),
                'campaign_code' => (string)($c['campaign_code'] ?? ''),
                'client_code' => (string)($c['client_code'] ?? ''),
                'campaign_status' => (string)($c['campaign_status'] ?? 'Live'),
                'campaign_type' => (string)($c['campaign_type'] ?? ''),
                'monthly_allocation' => (int)$c['monthly_allocation'],
                'pacing_type' => (string)($c['pacing_type'] ?? ''),
                'pacing_count' => (int)$c['pacing_count'],
                'start_date' => $c['start_date'],
                'end_date' => $c['end_date'],
                'daily_target' => $dailyTarget,
                'target_notes' => $c['target_notes'],
                'target_updated_at' => $c['target_updated_at'],
                'generated_leads' => $generated,
                'qualified_leads' => $qualified,
                'disqualified_leads' => $disqualified,
                'pending_qa' => $pendingQa,
                'remaining_leads_to_qualify' => $remainingQualified,
                'remaining_leads_to_generate' => $remainingGenerated,
                'completion_percentage' => $completionPct,
            ];
        }
        $stmt->close();

        echo json_encode([
            'ok' => true,
            'target_date' => $date,
            'total_campaigns' => count($campaigns),
            'campaigns' => $campaigns
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
        $targetDate = isset($body['target_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$body['target_date'])
            ? (string)$body['target_date']
            : $todayDate;

        $allocatedBy = isset($body['allocated_by']) ? (int)$body['allocated_by'] : null;

        $items = [];
        if (isset($body['targets']) && is_array($body['targets'])) {
            $items = $body['targets'];
        } elseif (isset($body['campaign_id'])) {
            $items = [$body];
        }

        if (empty($items)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'No target entries provided. Expecting "campaign_id" and "daily_target" or a "targets" array.']);
            exit;
        }

        $upsertStmt = $conn->prepare("
            INSERT INTO campaign_daily_targets (campaign_id, target_date, daily_target, notes, allocated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                daily_target = VALUES(daily_target),
                notes = COALESCE(VALUES(notes), notes),
                allocated_by = COALESCE(VALUES(allocated_by), allocated_by),
                updated_at = NOW()
        ");

        if (!$upsertStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        $saved = 0;
        foreach ($items as $item) {
            $cid = (int)($item['campaign_id'] ?? 0);
            $dt = max(0, (int)($item['daily_target'] ?? 0));
            $notes = isset($item['notes']) ? trim((string)$item['notes']) : null;
            $ab = isset($item['allocated_by']) ? (int)$item['allocated_by'] : $allocatedBy;

            if ($cid <= 0) continue;

            $upsertStmt->bind_param('isisi', $cid, $targetDate, $dt, $notes, $ab);
            $upsertStmt->execute();
            $saved++;
        }
        $upsertStmt->close();

        echo json_encode([
            'ok' => true,
            'message' => "Successfully allocated daily targets for {$saved} campaign(s).",
            'target_date' => $targetDate,
            'saved_count' => $saved
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use GET or POST.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
