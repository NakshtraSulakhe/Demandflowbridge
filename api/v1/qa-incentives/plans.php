<?php
/**
 * DemandFlow Bridge - QA Team Incentive Plan Management API
 * Routes:
 * - GET    /api/v1/qa-incentives/plans.php         (List all active plans or single plan if ?id=X)
 * - POST   /api/v1/qa-incentives/plans.php         (Create a new QA incentive plan)
 * - PUT    /api/v1/qa-incentives/plans.php?id=X    (Update an existing plan)
 * - DELETE /api/v1/qa-incentives/plans.php?id=X    (Deactivate or remove a plan)
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
    ensureQaIncentiveSchema($conn);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawInput = file_get_contents('php://input');
    $bodyData = !empty($rawInput) ? json_decode($rawInput, true) : [];
    if (!is_array($bodyData))
        $bodyData = [];

    // Helper to format a plan row
    $formatPlan = function (array $p): array {
        return [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'qa_user_ids' => !empty($p['qa_user_ids']) ? (is_array($p['qa_user_ids']) ? $p['qa_user_ids'] : json_decode((string) $p['qa_user_ids'], true)) : [],
            'client_code' => !empty($p['client_code']) ? (string) $p['client_code'] : null,
            'campaign_ids' => !empty($p['campaign_ids']) ? (is_array($p['campaign_ids']) ? $p['campaign_ids'] : json_decode((string) $p['campaign_ids'], true)) : [],
            'apply_all_campaigns' => (bool) ($p['apply_all_campaigns'] ?? 1),
            'frequency' => (string) ($p['frequency'] ?? 'daily'),
            'base_target' => (int) ($p['base_target'] ?? 50),
            'base_reward' => (float) ($p['base_reward'] ?? 500.00),
            'tiers' => !empty($p['tiers_json']) ? (is_array($p['tiers_json']) ? $p['tiers_json'] : json_decode((string) $p['tiers_json'], true)) : [],
            'is_active' => (bool) ($p['is_active'] ?? 1),
            'created_at' => $p['created_at'] ?? null,
            'updated_at' => $p['updated_at'] ?? null,
        ];
    };

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM qa_incentive_plans WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Plan not found'], JSON_PRETTY_PRINT);
                exit;
            }
            echo json_encode(['ok' => true, 'plan' => $formatPlan($row)], JSON_PRETTY_PRINT);
            exit;
        }

        $allQuery = "SELECT * FROM qa_incentive_plans WHERE is_active = 1 ORDER BY id DESC";
        $res = $conn->query($allQuery);
        $plans = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $plans[] = $formatPlan($row);
            }
        }

        echo json_encode([
            'ok' => true,
            'total_plans' => count($plans),
            'plans' => $plans,
            'data' => $plans
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        $data = !empty($bodyData) ? $bodyData : $_POST;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Plan name is required'], JSON_PRETTY_PRINT);
            exit;
        }

        $qaUserIds = isset($data['qa_user_ids']) && is_array($data['qa_user_ids']) ? json_encode(array_values(array_map('intval', $data['qa_user_ids']))) : null;
        $clientCode = !empty($data['client_code']) ? strtoupper(trim((string) $data['client_code'])) : null;
        $campaignIds = isset($data['campaign_ids']) && is_array($data['campaign_ids']) ? json_encode(array_values(array_map('intval', $data['campaign_ids']))) : null;
        $applyAll = isset($data['apply_all_campaigns']) ? ((int) $data['apply_all_campaigns'] ? 1 : 0) : 1;
        $freq = strtolower(trim((string) ($data['frequency'] ?? 'daily')));
        if (!in_array($freq, ['daily', 'monthly'], true))
            $freq = 'daily';
        $baseTarget = max(0, (int) ($data['base_target'] ?? 50));
        $baseReward = max(0.0, (float) ($data['base_reward'] ?? 500.00));

        $tiersJson = null;
        if (!empty($data['tiers'])) {
            $tiersJson = is_array($data['tiers']) ? json_encode($data['tiers']) : (string) $data['tiers'];
        } elseif (!empty($data['tiers_json'])) {
            $tiersJson = is_array($data['tiers_json']) ? json_encode($data['tiers_json']) : (string) $data['tiers_json'];
        }

        $isActive = isset($data['is_active']) ? ((int) $data['is_active'] ? 1 : 0) : 1;
        $createdBy = isset($data['created_by']) ? (int) $data['created_by'] : null;

        $stmt = $conn->prepare("
            INSERT INTO qa_incentive_plans 
            (name, qa_user_ids, client_code, campaign_ids, apply_all_campaigns, frequency, base_target, base_reward, tiers_json, is_active, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('ssssisidsii', $name, $qaUserIds, $clientCode, $campaignIds, $applyAll, $freq, $baseTarget, $baseReward, $tiersJson, $isActive, $createdBy);
        $ok = $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save QA incentive plan: ' . $conn->error], JSON_PRETTY_PRINT);
            exit;
        }

        $stGet = $conn->prepare("SELECT * FROM qa_incentive_plans WHERE id = ? LIMIT 1");
        $stGet->bind_param('i', $newId);
        $stGet->execute();
        $saved = $stGet->get_result()->fetch_assoc();
        $stGet->close();

        http_response_code(201);
        echo json_encode([
            'ok' => true,
            'message' => 'QA incentive plan created successfully',
            'plan' => $formatPlan($saved),
            'id' => $newId
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($bodyData['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing plan ID'], JSON_PRETTY_PRINT);
            exit;
        }

        $data = $bodyData;
        $updates = [];
        $types = '';
        $params = [];

        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $types .= "s";
            $params[] = trim((string) $data['name']);
        }
        if (array_key_exists('qa_user_ids', $data)) {
            $updates[] = "qa_user_ids = ?";
            $types .= "s";
            $params[] = is_array($data['qa_user_ids']) ? json_encode(array_values(array_map('intval', $data['qa_user_ids']))) : null;
        }
        if (array_key_exists('client_code', $data)) {
            $updates[] = "client_code = ?";
            $types .= "s";
            $params[] = !empty($data['client_code']) ? strtoupper(trim((string) $data['client_code'])) : null;
        }
        if (array_key_exists('campaign_ids', $data)) {
            $updates[] = "campaign_ids = ?";
            $types .= "s";
            $params[] = is_array($data['campaign_ids']) ? json_encode(array_values(array_map('intval', $data['campaign_ids']))) : null;
        }
        if (isset($data['apply_all_campaigns'])) {
            $updates[] = "apply_all_campaigns = ?";
            $types .= "i";
            $params[] = (int) $data['apply_all_campaigns'] ? 1 : 0;
        }
        if (isset($data['frequency'])) {
            $updates[] = "frequency = ?";
            $types .= "s";
            $params[] = in_array(strtolower($data['frequency']), ['daily', 'monthly'], true) ? strtolower($data['frequency']) : 'daily';
        }
        if (isset($data['base_target'])) {
            $updates[] = "base_target = ?";
            $types .= "i";
            $params[] = max(0, (int) $data['base_target']);
        }
        if (isset($data['base_reward'])) {
            $updates[] = "base_reward = ?";
            $types .= "d";
            $params[] = max(0.0, (float) $data['base_reward']);
        }
        if (array_key_exists('tiers', $data) || array_key_exists('tiers_json', $data)) {
            $rawTiers = $data['tiers'] ?? $data['tiers_json'];
            $updates[] = "tiers_json = ?";
            $types .= "s";
            $params[] = is_array($rawTiers) ? json_encode($rawTiers) : (string) $rawTiers;
        }
        if (isset($data['is_active'])) {
            $updates[] = "is_active = ?";
            $types .= "i";
            $params[] = (int) $data['is_active'] ? 1 : 0;
        }

        if (empty($updates)) {
            echo json_encode(['ok' => true, 'message' => 'No changes provided'], JSON_PRETTY_PRINT);
            exit;
        }

        $sql = "UPDATE qa_incentive_plans SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?";
        $types .= "i";
        $params[] = $id;

        $stmt = $conn->prepare($sql);
        if (function_exists('mysqliBindParams')) {
            mysqliBindParams($stmt, $types, $params);
        } else {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $stmt->close();

        $stGet = $conn->prepare("SELECT * FROM qa_incentive_plans WHERE id = ? LIMIT 1");
        $stGet->bind_param('i', $id);
        $stGet->execute();
        $updated = $stGet->get_result()->fetch_assoc();
        $stGet->close();

        echo json_encode([
            'ok' => true,
            'message' => 'QA incentive plan updated successfully',
            'plan' => $formatPlan($updated)
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($bodyData['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing plan ID'], JSON_PRETTY_PRINT);
            exit;
        }

        // Soft delete by setting is_active = 0
        $stmt = $conn->prepare("UPDATE qa_incentive_plans SET is_active = 0, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['ok' => true, 'message' => 'QA incentive plan deactivated successfully', 'id' => $id], JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

