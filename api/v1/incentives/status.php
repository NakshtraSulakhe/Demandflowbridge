<?php
/**
 * DemandFlow Bridge CRM - Payout Status & Audit Trail API
 * GET /api/v1/incentives/status.php?payout_id=123
 */

require_once __DIR__ . '/lib/bootstrap.php';

$user = IncentiveAuth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    IncentiveResponse::error('Method not allowed. Only GET is accepted.', 'METHOD_NOT_ALLOWED', 405);
}

$payoutId = isset($_GET['payout_id']) ? (int)$_GET['payout_id'] : 0;
if ($payoutId <= 0) {
    IncentiveResponse::error('Valid payout_id parameter is required.', 'INVALID_PAYOUT_ID', 400);
}

try {
    $conn = getDbConnection();
    ensureIncentiveSchema($conn);

    $stmt = $conn->prepare("
        SELECT p.*, u.full_name AS agent_name, u.employee_id, u.department,
               appr.full_name AS approved_by_name
        FROM daily_incentive_payouts p
        JOIN users u ON u.id = p.agent_id
        LEFT JOIN users appr ON appr.id = p.approved_by
        WHERE p.id = ?
        LIMIT 1
    ");
    $payout = null;
    if ($stmt) {
        $stmt->bind_param('i', $payoutId);
        $stmt->execute();
        $payout = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$payout) {
        IncentiveResponse::notFound("Payout record with ID {$payoutId} not found.");
    }

    $agentId = (int)$payout['agent_id'];

    // Permission check: SDRs can only view their own payout status unless manager/admin
    $callerId = (int)($user['id'] ?? 0);
    $callerRole = strtolower(trim((string)($user['role'] ?? '')));
    $isManager = IncentiveAuthZ::canApprove($user);
    if (!$isManager && $callerId > 0 && $callerId !== $agentId) {
        IncentiveResponse::forbidden('You are only authorized to view your own incentive payouts.');
    }

    // Fetch approval history
    $approvals = [];
    $stmtA = $conn->prepare("
        SELECT a.*, u.full_name AS manager_name
        FROM incentive_payout_approvals a
        JOIN users u ON u.id = a.manager_id
        WHERE a.payout_id = ?
        ORDER BY a.id ASC
    ");
    if ($stmtA) {
        $stmtA->bind_param('i', $payoutId);
        $stmtA->execute();
        $resA = $stmtA->get_result();
        while ($rowA = $resA->fetch_assoc()) {
            $approvals[] = [
                'id' => (int)$rowA['id'],
                'manager_name' => (string)$rowA['manager_name'],
                'action' => (string)$rowA['approval_action'],
                'reason' => (string)($rowA['reason'] ?? ''),
                'created_at' => (string)$rowA['created_at'],
            ];
        }
        $stmtA->close();
    }

    // Fetch payment attempts
    $attempts = [];
    $stmtAtt = $conn->prepare("
        SELECT id, attempt_number, provider, idempotency_key, provider_payout_id, 
               provider_reference, amount, payment_method, attempt_status, failure_code, 
               failure_reason, initiated_at, completed_at
        FROM incentive_payment_attempts
        WHERE payout_id = ?
        ORDER BY attempt_number ASC
    ");
    if ($stmtAtt) {
        $stmtAtt->bind_param('i', $payoutId);
        $stmtAtt->execute();
        $resAtt = $stmtAtt->get_result();
        while ($rowAtt = $resAtt->fetch_assoc()) {
            $attempts[] = [
                'attempt_number' => (int)$rowAtt['attempt_number'],
                'provider' => (string)$rowAtt['provider'],
                'provider_reference' => (string)($rowAtt['provider_reference'] ?? ''),
                'status' => (string)$rowAtt['attempt_status'],
                'amount' => (float)$rowAtt['amount'],
                'failure_reason' => (string)($rowAtt['failure_reason'] ?? ''),
                'initiated_at' => (string)$rowAtt['initiated_at'],
                'completed_at' => $rowAtt['completed_at'],
            ];
        }
        $stmtAtt->close();
    }

    // Fetch event audit history
    $events = [];
    $stmtEv = $conn->prepare("
        SELECT event_type, event_source, event_data, created_at
        FROM incentive_payout_events
        WHERE payout_id = ?
        ORDER BY id ASC
    ");
    if ($stmtEv) {
        $stmtEv->bind_param('i', $payoutId);
        $stmtEv->execute();
        $resEv = $stmtEv->get_result();
        while ($rowEv = $resEv->fetch_assoc()) {
            $events[] = [
                'event_type' => (string)$rowEv['event_type'],
                'source' => (string)$rowEv['event_source'],
                'data' => json_decode((string)($rowEv['event_data'] ?? '{}'), true),
                'created_at' => (string)$rowEv['created_at'],
            ];
        }
        $stmtEv->close();
    }

    $ben = PayoutService::resolveBeneficiary($agentId, (string)$payout['payment_method']);

    $response = [
        'payout' => [
            'id' => (int)$payout['id'],
            'agent_id' => $agentId,
            'agent_name' => (string)$payout['agent_name'],
            'employee_id' => (string)($payout['employee_id'] ?? ''),
            'department' => (string)($payout['department'] ?? ''),
            'work_date' => (string)$payout['work_date'],
            'target_points' => (float)$payout['target_points'],
            'achieved_points' => (float)$payout['achieved_points'],
            'qualified_leads_count' => (int)$payout['qualified_leads_count'],
            'disqualified_leads_count' => (int)$payout['disqualified_leads_count'],
            'base_incentive' => (float)$payout['base_incentive'],
            'slab_incentive' => (float)$payout['slab_incentive'],
            'total_incentive' => (float)$payout['total_incentive'],
            'eligibility_status' => (string)$payout['eligibility_status'],
            'eligibility_reason' => (string)$payout['eligibility_reason'],
            'approval_status' => (string)$payout['approval_status'],
            'approved_by_name' => (string)($payout['approved_by_name'] ?? ''),
            'approved_at' => $payout['approved_at'],
            'payment_status' => (string)$payout['payment_status'],
            'payment_method' => (string)$payout['payment_method'],
            'created_at' => (string)$payout['created_at'],
            'updated_at' => (string)$payout['updated_at'],
            'beneficiary' => $ben ? [
                'name' => $ben['name'],
                'masked_account' => $ben['masked_account'],
                'masked_upi' => $ben['masked_upi'],
                'method' => $ben['method'],
            ] : null,
        ],
        'approvals' => $approvals,
        'attempts' => $attempts,
        'events' => $events,
    ];

    IncentiveResponse::success($response, "Payout details retrieved successfully.");

} catch (Throwable $e) {
    IncentiveResponse::error('Failed to retrieve payout status: ' . $e->getMessage(), 'STATUS_ERROR', 500);
}
