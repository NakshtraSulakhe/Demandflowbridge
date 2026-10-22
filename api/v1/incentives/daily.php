<?php
/**
 * DemandFlow Bridge CRM - Daily Incentive Settlement Records API
 * GET /api/v1/incentives/daily.php?date=YYYY-MM-DD
 * 
 * Returns settled records, approval states, and payment statuses for a specific date
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

$approvalFilter = isset($_GET['approval_status']) ? trim((string)$_GET['approval_status']) : '';
$paymentFilter = isset($_GET['payment_status']) ? trim((string)$_GET['payment_status']) : '';
$agentIdFilter = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;

$page = max(1, (int)($_GET['page'] ?? 1));
$limitParam = $_GET['limit'] ?? 50;
$limit = ($limitParam === 'all') ? 1000 : max(1, min(100, (int)$limitParam));
$offset = ($page - 1) * $limit;

try {
    $conn = getDbConnection();
    ensureIncentiveSchema($conn);

    // Build filter WHERE clauses
    $where = ["p.work_date = ?"];
    $types = "s";
    $params = [$date];

    if ($approvalFilter !== '') {
        $where[] = "p.approval_status = ?";
        $types .= "s";
        $params[] = $approvalFilter;
    }
    if ($paymentFilter !== '') {
        $where[] = "p.payment_status = ?";
        $types .= "s";
        $params[] = $paymentFilter;
    }
    if ($agentIdFilter > 0) {
        $where[] = "p.agent_id = ?";
        $types .= "i";
        $params[] = $agentIdFilter;
    }

    $whereSql = implode(' AND ', $where);

    // Summary aggregates for the date
    $stmtSum = $conn->prepare("
        SELECT 
            COUNT(*) AS total_records,
            SUM(CASE WHEN eligibility_status = 'eligible' AND total_incentive > 0 THEN 1 ELSE 0 END) AS eligible_agents,
            COALESCE(SUM(total_incentive), 0.00) AS total_incentive,
            COALESCE(SUM(CASE WHEN approval_status = 'pending_approval' THEN total_incentive ELSE 0.00 END), 0.00) AS pending_approval_amount,
            COALESCE(SUM(CASE WHEN approval_status = 'approved' AND payment_status != 'paid' THEN total_incentive ELSE 0.00 END), 0.00) AS approved_unpaid_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_incentive ELSE 0.00 END), 0.00) AS paid_amount
        FROM daily_incentive_payouts
        WHERE work_date = ?
    ");
    $summary = [
        'total_agents' => 0,
        'eligible_agents' => 0,
        'total_incentive' => 0.00,
        'pending_approval_amount' => 0.00,
        'approved_unpaid_amount' => 0.00,
        'paid_amount' => 0.00,
    ];
    if ($stmtSum) {
        $stmtSum->bind_param('s', $date);
        $stmtSum->execute();
        $rowSum = $stmtSum->get_result()->fetch_assoc();
        $stmtSum->close();
        if ($rowSum) {
            $summary = [
                'total_agents' => (int)$rowSum['total_records'],
                'eligible_agents' => (int)$rowSum['eligible_agents'],
                'total_incentive' => (float)$rowSum['total_incentive'],
                'pending_approval_amount' => (float)$rowSum['pending_approval_amount'],
                'approved_unpaid_amount' => (float)$rowSum['approved_unpaid_amount'],
                'paid_amount' => (float)$rowSum['paid_amount'],
            ];
        }
    }

    // Query records with agent profile information
    $recordsSql = "
        SELECT 
            p.*,
            u.full_name AS agent_name,
            u.employee_id,
            u.department,
            appr.full_name AS approved_by_name,
            (SELECT provider_reference FROM incentive_payment_attempts WHERE payout_id = p.id AND attempt_status = 'success' ORDER BY id DESC LIMIT 1) AS payment_reference,
            (SELECT failure_reason FROM incentive_payment_attempts WHERE payout_id = p.id AND attempt_status = 'failed' ORDER BY id DESC LIMIT 1) AS last_failure_reason
        FROM daily_incentive_payouts p
        JOIN users u ON u.id = p.agent_id
        LEFT JOIN users appr ON appr.id = p.approved_by
        WHERE {$whereSql}
        ORDER BY p.total_incentive DESC, u.full_name ASC
        LIMIT ? OFFSET ?
    ";

    $typesFull = $types . "ii";
    $paramsFull = array_merge($params, [$limit, $offset]);

    $stmtRows = $conn->prepare($recordsSql);
    $agents = [];
    $totalFiltered = 0;

    if ($stmtRows) {
        $stmtRows->bind_param($typesFull, ...$paramsFull);
        $stmtRows->execute();
        $res = $stmtRows->get_result();
        while ($r = $res->fetch_assoc()) {
            $agentId = (int)$r['agent_id'];
            $ben = PayoutService::resolveBeneficiary($agentId, (string)$r['payment_method']);

            $agents[] = [
                'payout_id' => (int)$r['id'],
                'agent_id' => $agentId,
                'agent_name' => (string)$r['agent_name'],
                'employee_id' => (string)($r['employee_id'] ?? ''),
                'department' => (string)($r['department'] ?? ''),
                'work_date' => (string)$r['work_date'],
                'target_points' => (float)$r['target_points'],
                'achieved_points' => (float)$r['achieved_points'],
                'completion_pct' => (float)$r['target_points'] > 0 ? round(((float)$r['achieved_points'] / (float)$r['target_points']) * 100, 1) : 0.0,
                'qualified_leads_count' => (int)$r['qualified_leads_count'],
                'disqualified_leads_count' => (int)$r['disqualified_leads_count'],
                'base_incentive' => (float)$r['base_incentive'],
                'slab_incentive' => (float)$r['slab_incentive'],
                'total_incentive' => (float)$r['total_incentive'],
                'eligibility_status' => (string)$r['eligibility_status'],
                'eligibility_reason' => (string)$r['eligibility_reason'],
                'approval_status' => (string)$r['approval_status'],
                'approved_by' => $r['approved_by'] ? (int)$r['approved_by'] : null,
                'approved_by_name' => (string)($r['approved_by_name'] ?? ''),
                'approved_at' => $r['approved_at'],
                'payment_status' => (string)$r['payment_status'],
                'payment_method' => (string)$r['payment_method'],
                'payment_reference' => (string)($r['payment_reference'] ?? ''),
                'failure_reason' => (string)($r['last_failure_reason'] ?? ''),
                'beneficiary' => $ben ? [
                    'masked_account' => $ben['masked_account'],
                    'masked_upi' => $ben['masked_upi'],
                    'method' => $ben['method'],
                ] : null,
                'created_at' => (string)$r['created_at'],
                'updated_at' => (string)$r['updated_at'],
            ];
        }
        $stmtRows->close();
    }

    $paginationMeta = [
        'page' => $page,
        'limit' => $limit,
        'count' => count($agents),
        'filters' => [
            'date' => $date,
            'approval_status' => $approvalFilter ?: null,
            'payment_status' => $paymentFilter ?: null,
            'agent_id' => $agentIdFilter ?: null,
        ],
    ];

    IncentiveResponse::success([
        'work_date' => $date,
        'summary' => $summary,
        'agents' => $agents,
    ], "Daily settlement records for {$date} retrieved.", 200, $paginationMeta);

} catch (Throwable $e) {
    IncentiveResponse::error('Failed to retrieve daily settlement records: ' . $e->getMessage(), 'QUERY_ERROR', 500);
}
