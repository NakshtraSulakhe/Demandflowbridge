<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$conn = getDbConnection();

$agentId = 102; // Replace with your test agent id
$startDate = '2026-07-09'; // Replace with your test start date
$endDate = '2026-07-09'; // Replace with your test end date

echo "<h1>Debugging Lead Counts for Agent {$agentId}</h1>";

// -------------------
// Query 1: Team.php's query (counts qualified leads)
// -------------------
echo "<h2>1. Team.php's Qualified Leads Query</h2>";
$rangeUtc = opsDateRangeUtc($startDate, $endDate);
$startTs = $rangeUtc['start'];
$endTs = $rangeUtc['end'];
$stmt = $conn->prepare("
    SELECT id, created_at, qa_status, qa_updated_at, COALESCE(qa_updated_at, created_at) AS qualified_date
    FROM leads
    WHERE agent_id = ?
      AND qa_status = 'Qualified'
      AND COALESCE(qa_updated_at, created_at) >= ?
      AND COALESCE(qa_updated_at, created_at) <= ?
    ORDER BY id
");
$stmt->bind_param('iss', $agentId, $startTs, $endTs);
$stmt->execute();
$teamPhpLeads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Lead ID</th><th>Created At</th><th>QA Status</th><th>QA Updated At</th><th>Qualified Date</th></tr>";
$teamLeadIds = [];
foreach ($teamPhpLeads as $lead) {
    $teamLeadIds[] = $lead['id'];
    echo "<tr>";
    echo "<td>{$lead['id']}</td>";
    echo "<td>{$lead['created_at']}</td>";
    echo "<td>{$lead['qa_status']}</td>";
    echo "<td>{$lead['qa_updated_at']}</td>";
    echo "<td>{$lead['qualified_date']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p><strong>Team.php Total Qualified: " . count($teamPhpLeads) . "</strong> (IDs: " . implode(', ', $teamLeadIds) . ")</p>";

// -------------------
// Query 2: funneltrackComputeDailyDetailRows's query
// -------------------
echo "<h2>2. funneltrackComputeDailyDetailRows's Step 1 Query (unique leads)</h2>";
$createdExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$qualifiedExpr = "COALESCE(l.qa_updated_at, $createdExpr)";
$stmt = $conn->prepare("
    SELECT DISTINCT
        l.id AS lead_id,
        l.agent_id,
        l.campaign_id,
        DATE($qualifiedExpr) AS work_date,
        l.qa_status
    FROM leads l
    WHERE l.agent_id = ?
      AND l.qa_status IN ('Qualified','Disqualified')
      AND $qualifiedExpr >= ?
      AND $qualifiedExpr <= ?
");
$stmt->bind_param('iss', $agentId, $startTs, $endTs);
$stmt->execute();
$leadRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Lead ID</th><th>Work Date</th><th>QA Status</th><th>Campaign ID</th></tr>";
foreach ($leadRows as $leadRow) {
    echo "<tr>";
    echo "<td>{$leadRow['lead_id']}</td>";
    echo "<td>{$leadRow['work_date']}</td>";
    echo "<td>{$leadRow['qa_status']}</td>";
    echo "<td>{$leadRow['campaign_id']}</td>";
    echo "</tr>";
}
echo "</table>";

// -------------------
// Now get campaign type for each lead
// -------------------
echo "<h2>3. Campaign Type for Each Lead</h2>";
$bucketKeys = array_keys(funneltrackCampaignBucketMeta());
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Lead ID</th><th>Campaign ID</th><th>Campaign Type</th><th>Normalized Bucket</th></tr>";
$qualifiedCount = 0;
$dqCount = 0;
$processedLeadIds = [];
foreach ($leadRows as $leadRow) {
    $leadId = (int)$leadRow['lead_id'];
    if (in_array($leadId, $processedLeadIds)) continue;
    $processedLeadIds[] = $leadId;
    
    $campaignId = $leadRow['campaign_id'];
    $campaignType = null;
    if ($campaignId) {
        $stmt2 = $conn->prepare("SELECT d.campaign_type FROM campaign_details d WHERE d.campaign_id = ? LIMIT 1");
        $stmt2->bind_param('i', $campaignId);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        if ($result2 && $campaignRow = $result2->fetch_assoc()) {
            $campaignType = $campaignRow['campaign_type'];
        }
        $stmt2->close();
    }
    $normalizedBucket = funneltrackNormalizeCampaignType((string)$campaignType);
    
    echo "<tr>";
    echo "<td>{$leadId}</td>";
    echo "<td>" . ($campaignId ?? 'NULL') . "</td>";
    echo "<td>" . ($campaignType ?? 'NULL') . "</td>";
    echo "<td>" . ($normalizedBucket ?? 'NULL') . "</td>";
    echo "</tr>";
    
    if ($leadRow['qa_status'] === 'Qualified') {
        $qualifiedCount++;
    } else {
        $dqCount++;
    }
}
echo "</table>";
echo "<p><strong>Calculated Qualified Count: {$qualifiedCount}, DQ Count: {$dqCount}</strong></p>";

// -------------------
// Now call funneltrackComputeDailyDetailRows
// -------------------
echo "<h2>4. funneltrackComputeDailyDetailRows Result</h2>";
$detailRows = funneltrackComputeDailyDetailRows([$agentId], $startDate, $endDate);
echo "<pre>";
print_r($detailRows);
echo "</pre>";
?>