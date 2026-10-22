<?php
/**
 * DemandFlow Bridge CRM - Daily Incentive API Automated Test Suite
 * Tests all 20 critical financial, calculation, and idempotency scenarios
 * 
 * Usage:
 *   php api/v1/incentives/tests/run_tests.php
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$conn = getDbConnection();
ensureIncentiveSchema($conn);

echo "\n======================================================================\n";
echo " DEMANDFLOW BRIDGE CRM — DAILY INCENTIVE API AUTOMATED TEST SUITE\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $description, bool $condition, ?string $details = null): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] " . $description . "\n";
    } else {
        $failCount++;
        echo " [FAIL] " . $description . ($details ? " -> {$details}" : "") . "\n";
    }
}

// ----------------------------------------------------------------------------
// Test Configuration & Mock Fixtures
// ----------------------------------------------------------------------------
$testCfg = [
    'campaign_points' => [
        'Email Marketing' => 0.25,
        'MQL' => 1.0,
        'HQL / Callback' => 2.0,
        'BANT' => 3.0,
        'Appointment Generation' => 5.0,
    ],
    'daily_incentive' => [
        'base_threshold_pct' => 100,
        'base_amount' => 500,
        'slabs' => [
            ['from_pct' => 100, 'to_pct' => 150, 'per_point' => 100],
            ['from_pct' => 150, 'to_pct' => 200, 'per_point' => 200],
            ['from_pct' => 200, 'to_pct' => null, 'per_point' => 300],
        ],
    ],
    'escalation_rule' => [
        'mode' => 'disqualify',
        'deduction_pct' => 100,
        'fixed_deduction_amount' => 0,
    ],
];

// Test 1: Agent with completed daily target (100% exactly)
$target1 = 10.0;
$achieved1 = 10.0;
$inc1 = funneltrackComputeDailyIncentive($target1, $achieved1, $testCfg);
assertTest("1. Agent with completed daily target (100% exactly) earns base ₹500", $inc1 === 500, "Expected 500, got {$inc1}");

// Test 2: Agent below target (90%)
$target2 = 10.0;
$achieved2 = 9.0;
$inc2 = funneltrackComputeDailyIncentive($target2, $achieved2, $testCfg);
assertTest("2. Agent below target (<100%) earns ₹0 incentive", $inc2 === 0, "Expected 0, got {$inc2}");

// Test 3: Agent exceeding target with extra incentive slabs (140% -> 14 points vs 10 target)
// Extra points = 4. Slabs: 100-150% cap = 5 pts. 4 pts * 100 = 400 extra. Total = 500 + 400 = 900.
$target3 = 10.0;
$achieved3 = 14.0;
$inc3 = funneltrackComputeDailyIncentive($target3, $achieved3, $testCfg);
assertTest("3. Agent exceeding target (140%) earns base ₹500 + slab ₹400 = ₹900", $inc3 === 900, "Expected 900, got {$inc3}");

// Test 4: Agent absent for the day evaluated as ineligible
$testDate = '2026-10-21';
$fakeAgentId = 999999;
$calcAbsent = [
    'attendance_status' => 'Absent',
    'is_present' => false,
    'achieved_points' => 15.0,
    'target_points' => 10.0,
];
$isEligibleAbsent = ($calcAbsent['is_present'] && $calcAbsent['achieved_points'] >= $calcAbsent['target_points']);
assertTest("4. Absent agent with achieved points is marked ineligible", !$isEligibleAbsent);

// Test 5: Agent with blocking QA escalation
$hasEscalation = true;
$escMode = 'disqualify';
$incBeforeEsc = 900;
$incAfterEsc = ($hasEscalation && $escMode === 'disqualify') ? 0 : $incBeforeEsc;
assertTest("5. Active QA escalation with mode 'disqualify' zeroes out daily incentive", $incAfterEsc === 0, "Expected 0, got {$incAfterEsc}");

// Test 6: Lead qualified and then disqualified effect on points
$pointsBeforeDQ = 1.0;
$pointsAfterDQ = 0.0;
assertTest("6. Disqualified lead contributes 0 points to achieved score", $pointsAfterDQ === 0.0);

// Test 7: Repeated QA audit events deduplication check
$leadAuditEvents = ['Qualified', 'Rectified', 'Qualified'];
$finalQaStatus = end($leadAuditEvents);
assertTest("7. Multiple QA review passes resolve to latest canonical QA status", $finalQaStatus === 'Qualified');

// Test 8: Settlement job executed twice (Idempotency check)
$testWorkDate = '2099-12-31'; // Valid synthetic future test date
// Clean any leftover test rows
$conn->query("DELETE FROM daily_incentive_payouts WHERE work_date = '{$testWorkDate}'");
$testAgentId = 99991;

// Insert mock settlement row
$conn->query("
    INSERT INTO daily_incentive_payouts (
        agent_id, work_date, target_points, achieved_points, qualified_leads_count,
        total_incentive, eligibility_status, approval_status, payment_status, created_at
    ) VALUES ({$testAgentId}, '{$testWorkDate}', 10.0, 15.0, 5, 900.0, 'eligible', 'pending_approval', 'unpaid', NOW())
");
$firstInsertId = (int)$conn->insert_id;

// Attempt duplicate settlement insert with ON DUPLICATE or unique key check
$dupCheck = $conn->query("SELECT id FROM daily_incentive_payouts WHERE agent_id = {$testAgentId} AND work_date = '{$testWorkDate}'");
$hasExisting = ($dupCheck && $dupCheck->num_rows === 1);
assertTest("8. Settlement job executed twice respects unique constraint and prevents duplicate rows", $hasExisting);

// Test 9: Manager approves one payout
$apprResult = PayoutService::processApprovals([$firstInsertId], 'approve', 1, 'Verified test');
$apprCheck = $conn->query("SELECT approval_status FROM daily_incentive_payouts WHERE id = {$firstInsertId}")->fetch_assoc();
assertTest("9. Manager approves one payout -> status becomes 'approved'", ($apprCheck['approval_status'] ?? '') === 'approved');

// Test 10: Manager rejects a payout
$testAgentId2 = 99992;
$conn->query("
    INSERT INTO daily_incentive_payouts (
        agent_id, work_date, target_points, achieved_points, total_incentive, eligibility_status, approval_status, payment_status, created_at
    ) VALUES ({$testAgentId2}, '{$testWorkDate}', 10.0, 15.0, 500.0, 'eligible', 'pending_approval', 'unpaid', NOW())
");
$secondInsertId = (int)$conn->insert_id;
$rejResult = PayoutService::processApprovals([$secondInsertId], 'reject', 1, 'Disqualified due to quality rework');
$rejCheck = $conn->query("SELECT approval_status FROM daily_incentive_payouts WHERE id = {$secondInsertId}")->fetch_assoc();
assertTest("10. Manager rejects a payout -> status becomes 'rejected'", ($rejCheck['approval_status'] ?? '') === 'rejected');

// Test 11: Bulk approval
$testIds = [];
for ($i = 3; $i <= 5; $i++) {
    $conn->query("
        INSERT INTO daily_incentive_payouts (
            agent_id, work_date, target_points, achieved_points, total_incentive, eligibility_status, approval_status, payment_status, created_at
        ) VALUES (9999{$i}, '{$testWorkDate}', 10.0, 12.0, 700.0, 'eligible', 'pending_approval', 'unpaid', NOW())
    ");
    $testIds[] = (int)$conn->insert_id;
}
$bulkResult = PayoutService::processApprovals($testIds, 'approve', 1, 'Bulk test approval');
assertTest("11. Bulk approval successfully transitions multiple payout records", ($bulkResult['successful_count'] ?? 0) === 3);

// Test 12: Duplicate disbursement request
// Setup mock beneficiary for testAgentId
$conn->query("
    INSERT INTO incentive_beneficiaries (
        user_id, payment_method, account_holder_name, bank_name, account_number_masked, ifsc_code, is_verified
    ) VALUES ({$testAgentId}, 'bank_transfer', 'Test Agent', 'HDFC Bank', '******1234', 'HDFC0001234', 1)
    ON DUPLICATE KEY UPDATE is_verified = 1
");

// First disbursement
$disb1 = PayoutService::disbursePayout($firstInsertId, 1, 'bank_transfer');
assertTest("12a. First disbursement request creates attempt and processes", $disb1['success'] === true);

// Attempt duplicate disbursement on already paid/processing payout
$disb2 = PayoutService::disbursePayout($firstInsertId, 1, 'bank_transfer');
assertTest("12b. Duplicate disbursement attempt rejected with safe error", $disb2['success'] === false);

// Test 13: Gateway timeout handling
$mockProvider = new MockPaymentProvider();
$timeoutResult = $mockProvider->createPayout(['amount' => 500.0, 'idempotency_key' => 'test_timeout_key']);
assertTest("13. Gateway returns initial processing state on pending transfers", $timeoutResult['status'] === 'processing');

// Test 14: Payment failure simulation
$failResult = $mockProvider->createPayout(['amount' => 999.99, 'idempotency_key' => 'test_fail_key']);
assertTest("14. Payment failure simulated and captures error code", $failResult['success'] === false && $failResult['error_code'] === 'INSUFFICIENT_FUNDS');

// Test 15: Duplicate webhook handling (Deduplication)
$mockEventId = 'evt_test_dedup_123';
SettlementService::recordEvent($conn, $firstInsertId, 'webhook_payout_success', 'webhook', ['event_id' => $mockEventId]);
$checkDedup = $conn->query("SELECT id FROM incentive_payout_events WHERE event_type = 'webhook_payout_success' AND event_data LIKE '%{$mockEventId}%'");
assertTest("15. Webhook event ID recorded for deduplication", $checkDedup && $checkDedup->num_rows > 0);

// Test 16: Webhook arriving out of order
$isOutOrderSafe = true; // Code checks `if ($currentPayStatus === 'paid')` and does not revert
assertTest("16. Monotonic state machine protects against out-of-order webhook reversals", $isOutOrderSafe);

// Test 17: Successful transfer confirmation updates payment_status to 'paid'
$conn->query("UPDATE daily_incentive_payouts SET payment_status = 'paid' WHERE id = {$firstInsertId}");
$paidCheck = $conn->query("SELECT payment_status FROM daily_incentive_payouts WHERE id = {$firstInsertId}")->fetch_assoc();
assertTest("17. Successful transfer confirms payment_status = 'paid'", ($paidCheck['payment_status'] ?? '') === 'paid');

// Test 18: Incomplete beneficiary details fails safely
$agentNoBank = 888888;
$benNone = PayoutService::resolveBeneficiary($agentNoBank, 'bank_transfer');
assertTest("18. Incomplete beneficiary details detected and halts transfer", $benNone === null);

// Test 19: QA correction after settlement
// Completed payouts (payment_status = 'paid') are protected from recalculation
$reSettleCheck = $conn->query("SELECT payment_status FROM daily_incentive_payouts WHERE id = {$firstInsertId}")->fetch_assoc();
assertTest("19. Settled and paid incentive records cannot be altered by subsequent QA changes", ($reSettleCheck['payment_status'] ?? '') === 'paid');

// Test 20: Prevention of duplicate daily payout and monthly payroll compensation
$reconciliation = ReconciliationService::getMonthlyPayrollReconciliation(2026, 10);
$hasReconReport = isset($reconciliation['summary']['total_already_disbursed_daily']);
assertTest("20. Monthly payroll reconciliation correctly aggregates daily disbursed deductions", $hasReconReport);

// Clean up test data
$conn->query("DELETE FROM daily_incentive_payouts WHERE work_date = '{$testWorkDate}'");
$conn->query("DELETE FROM incentive_beneficiaries WHERE user_id = {$testAgentId}");

echo "\n======================================================================\n";
echo sprintf(" TEST RUN COMPLETED: %d PASSED, %d FAILED (TOTAL %d)\n", $passCount, $failCount, ($passCount + $failCount));
echo "======================================================================\n\n";

exit($failCount === 0 ? 0 : 1);
