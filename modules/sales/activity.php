<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('sales.activity.page', []);
ensureCsrfToken();

header('Content-Type: application/json; charset=utf-8');

if (!isAjaxRequest()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$isSdr = isSDR();
$isManager = isSalesManager();
$isDirector = isSalesDirector() || isAdmin();
$conn = getDbConnection();
$statuses = ['New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];

$canEditLead = function(int $leadOwnerId) use ($conn, $userId, $isSdr, $isManager, $isDirector): bool {
    if ($isDirector) return true;
    if ($isSdr) return $leadOwnerId === $userId;
    if (!$isManager) return false;
    if ($leadOwnerId === $userId) return true;
    return getSalesManagerForSdr($leadOwnerId) === $userId;
};

$parseDt = function(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = str_replace('T', ' ', $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $raw)) $raw .= ':00';
    $ts = strtotime($raw);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
};

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'add_activity') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId <= 0) throw new RuntimeException('Invalid prospect');

        $stmt = $conn->prepare("SELECT id, owner_id FROM sales_leads WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $leadId);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$lead) throw new RuntimeException('Prospect not found');

        $ownerId = (int)($lead['owner_id'] ?? 0);
        if (!$canEditLead($ownerId)) throw new RuntimeException('Not allowed');

        $status = trim((string)($_POST['status'] ?? 'New'));
        if (!in_array($status, $statuses, true)) {
            throw new RuntimeException('Invalid status');
        }
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($comment === '') {
            throw new RuntimeException('Comment is required');
        }
        $nf = $parseDt((string)($_POST['next_follow_up_at'] ?? ''));

        addSalesLeadActivity($leadId, $status, $comment, $userId, $nf);
        updateSalesLead($leadId, ['status' => $status], $userId);
    } elseif ($action === 'edit_activity') {
        $activityId = (int)($_POST['activity_id'] ?? 0);
        if ($activityId <= 0) throw new RuntimeException('Invalid activity');

        $stmt = $conn->prepare("SELECT id, sales_lead_id FROM sales_lead_activities WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $activityId);
        $stmt->execute();
        $act = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$act) throw new RuntimeException('Activity not found');

        $leadId = (int)($act['sales_lead_id'] ?? 0);
        if ($leadId <= 0) throw new RuntimeException('Invalid prospect');

        $stmt = $conn->prepare("SELECT id, owner_id FROM sales_leads WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $leadId);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$lead) throw new RuntimeException('Prospect not found');

        $ownerId = (int)($lead['owner_id'] ?? 0);
        if (!$canEditLead($ownerId)) throw new RuntimeException('Not allowed');

        $status = trim((string)($_POST['status'] ?? ''));
        if (!in_array($status, $statuses, true)) {
            throw new RuntimeException('Invalid status');
        }
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($comment === '') {
            throw new RuntimeException('Comment is required');
        }
        $nf = $parseDt((string)($_POST['next_follow_up_at'] ?? ''));

        updateSalesLeadActivity($activityId, $status, $comment, $userId);
        updateSalesLead($leadId, ['status' => $status, 'next_follow_up_at' => $nf], $userId);
    } else {
        throw new RuntimeException('Unknown action');
    }

    $leadId = 0;
    if (!empty($_POST['lead_id'])) $leadId = (int)$_POST['lead_id'];
    if ($leadId <= 0 && !empty($_POST['activity_id'])) {
        $stmt = $conn->prepare("SELECT sales_lead_id FROM sales_lead_activities WHERE id = ? LIMIT 1");
        $aid = (int)$_POST['activity_id'];
        $stmt->bind_param('i', $aid);
        $stmt->execute();
        $leadId = (int)($stmt->get_result()->fetch_assoc()['sales_lead_id'] ?? 0);
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT sl.status, sl.priority, sl.next_follow_up_at, sl.last_activity_at, sl.updated_at,
        o.full_name AS owner_name, o.role AS owner_role, m.full_name AS manager_name
        FROM sales_leads sl
        LEFT JOIN users o ON o.id = sl.owner_id
        LEFT JOIN users m ON m.id = sl.sales_manager_id
        WHERE sl.id = ? LIMIT 1");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $leadRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $last = [];
    $stmt = $conn->prepare("SELECT id, comment, created_at FROM sales_lead_activities WHERE sales_lead_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    echo json_encode([
        'ok' => true,
        'data' => [
            'lead_id' => $leadId,
            'status' => $leadRow['status'] ?? '',
            'priority' => $leadRow['priority'] ?? '',
            'next_follow_up_at' => $leadRow['next_follow_up_at'] ?? null,
            'last_activity_at' => $leadRow['last_activity_at'] ?? null,
            'updated_at' => $leadRow['updated_at'] ?? null,
            'owner_name' => $leadRow['owner_name'] ?? '',
            'owner_role' => $leadRow['owner_role'] ?? '',
            'manager_name' => $leadRow['manager_name'] ?? '',
            'last_activity_id' => $last['id'] ?? null,
            'last_comment' => $last['comment'] ?? '',
            'last_comment_at' => $last['created_at'] ?? null,
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
