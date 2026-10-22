<?php
/**
 * DemandFlow Bridge CRM - Historical Incentive Payouts API
 * GET /api/v1/incentives/history.php?agent_id=...&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    IncentiveResponse::error('Method not allowed. Only GET is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$from = isset($_GET['from']) && is_string($_GET['from']) ? trim($_GET['from']) : date('Y-m-01');
$to = isset($_GET['to']) && is_string($_GET['to']) ? trim($_GET['to']) : incentiveBusinessDate();
$reqAgentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$approvalStatus = isset($_GET['approval_status']) ? trim((string)$_GET['approval_status']) : '';
$paymentStatus = isset($_GET['payment_status']) ? trim((string)$_GET['payment_status']) : '';

$page = max(1, (int)($_GET['page'] ?? 1));
$limitParam = $_GET['limit'] ?? 50;
$limit = ($limitParam === 'all') ? 1000 : max(1, min(100, (int)$limitParam));
$offset = ($page - 1) * $limit;

// Access control
$callerId = (int)($user['id'] ?? 0);
$isManager = IncentiveAuthZ::canApprove($user);
if (!$isManager && $callerId > 0) {
    $reqAgentId = $callerId; // Force individual SDR to only see their own history
}

try {
    $conn = getDbConnection();
    ensureIncentiveSchema($conn);

    $where = ["p.work_date BETWEEN ? AND ?"];
    $types = "ss";
    $params = [$from, $to];

    if ($reqAgentId > 0) {
        $where[] = "p.agent_id = ?";
        $types .= "i";
        $params[] = $reqAgentId;
    }
    if ($approvalStatus !== '') {
        $where[] = "p.approval_status = ?";
        $types .= "s";
        $params[] = $approvalStatus;
    }
    if ($paymentStatus !== '') {
        $where[] = "p.payment_status = ?";
        $types .= "s";
        $params[] = $paymentStatus;
    }

    $whereSql = implode(' AND ', $where);

    // Summary query
    $stmtSum = $conn->prepare("
        SELECT 
            COUNT(*) AS total_records,
            COALESCE(SUM(p.total_incentive), 0.00) AS total_incentive,
            COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.total_incentive ELSE 0.00 END), 0.00) AS paid_total,
            COALESCE(SUM(CASE WHEN p.payment_status != 'paid' AND p.approval_status = 'approved' THEN p.total_incentive ELSE 0.00 END), 0.00) AS approved_unpaid_total
        FROM daily_incentive_payouts p
        WHERE {$whereSql}
    ");
    $summary = ['total_records' => 0, 'total_incentive' => 0.00, 'paid_total' => 0.00, 'approved_unpaid_total' => 0.00];
    if ($stmtSum) {
        $stmtSum->bind_param($types, ...$params);
        $stmtSum->execute();
        $rowSum = $stmtSum->get_result()->fetch_assoc();
        $stmtSum->close();
        if ($rowSum) {
            $summary = [
                'total_records' => (int)$rowSum['total_records'],
                'total_incentive' => (float)$rowSum['total_incentive'],
                'paid_total' => (float)$rowSum['paid_total'],
                'approved_unpaid_total' => (float)$rowSum['approved_unpaid_total'],
            ];
        }
    }

    // Rows query
    $typesFull = $types . "ii";
    $paramsFull = array_merge($params, [$limit, $offset]);

    $stmtRows = $conn->prepare("
        SELECT p.*, u.full_name AS agent_name, u.employee_id, u.department,
               appr.full_name AS approved_by_name,
               (SELECT provider_reference FROM incentive_payment_attempts WHERE payout_id = p.id AND attempt_status = 'success' ORDER BY id DESC LIMIT 1) AS payment_reference
        FROM daily_incentive_payouts p
        JOIN users u ON u.id = p.agent_id
        LEFT JOIN users appr ON appr.id = p.approved_by
        WHERE {$whereSql}
        ORDER BY p.work_date DESC, p.total_incentive DESC
        LIMIT ? OFFSET ?
    ");

    $records = [];
    if ($stmtRows) {
        $stmtRows->bind_param($typesFull, ...$paramsFull);
        $stmtRows->execute();
        $res = $stmtRows->get_result();
        while ($r = $res->fetch_assoc()) {
            $records[] = [
                'id' => (int)$r['id'],
                'agent_id' => (int)$r['agent_id'],
                'agent_name' => (string)$r['agent_name'],
                'employee_id' => (string)($r['employee_id'] ?? ''),
                'department' => (string)($r['department'] ?? ''),
                'work_date' => (string)$r['work_date'],
                'target_points' => (float)$r['target_points'],
                'achieved_points' => (float)$r['achieved_points'],
                'qualified_leads_count' => (int)$r['qualified_leads_count'],
                'disqualified_leads_count' => (int)$r['disqualified_leads_count'],
                'base_incentive' => (float)$r['base_incentive'],
                'slab_incentive' => (float)$r['slab_incentive'],
                'total_incentive' => (float)$r['total_incentive'],
                'approval_status' => (string)$r['approval_status'],
                'approved_by_name' => (string)($r['approved_by_name'] ?? ''),
                'approved_at' => $r['approved_at'],
                'payment_status' => (string)$r['payment_status'],
                'payment_method' => (string)$r['payment_method'],
                'payment_reference' => (string)($r['payment_reference'] ?? ''),
                'created_at' => (string)$r['created_at'],
            ];
        }
        $stmtRows->close();
    }

    IncentiveResponse::success([
        'range' => ['from' => $from, 'to' => $to],
        'summary' => $summary,
        'records' => $records,
    ], "Incentive history retrieved.", 200, ['page' => $page, 'limit' => $limit, 'count' => count($records)]);

} catch (Throwable $e) {
    IncentiveResponse::error('Failed to query incentive history: ' . $e->getMessage(), 'HISTORY_ERROR', 500);
}
