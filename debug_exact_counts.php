<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$conn = getDbConnection();

$agentId = 102; // Change this to your test agent
$startDate = '2026-07-09';
$endDate = '2026-07-09';

$rangeUtc = opsDateRangeUtc($startDate, $endDate);
$startTs = $rangeUtc['start'];
$endTs = $rangeUtc['end'];

echo "<h1>Debug Exact Counts for Agent ID: {$agentId} on {$startDate} to {$endDate}</h1>";

echo "<h2>1. Team.php's count (exact query)</h2>";
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
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'><tr><th>Lead ID</th><th>Created At</th><th>QA Updated At</th><th>Qualified Date</th></tr>";
$teamLeadIds = [];
foreach ($teamPhpLeads as $lead) {
    $teamLeadIds[] = $lead['id'];
    echo "<tr><td>{$lead['id']}</td><td>{$lead['created_at']}</td><td>" . ($lead['qa_updated_at'] ?? 'NULL') . "</td><td>{$lead['qualified_date']}</td></tr>";
}
echo "</table>";
echo "<strong>Team.php's count: " . count($teamPhpLeads) . "</strong> (IDs: " . implode(", ", $teamLeadIds) . ")<br><br>";

echo "<h2>2. funneltrackComputeDailyDetailRows's output</h2>";
$detailRows = funneltrackComputeDailyDetailRows([$agentId], $startDate, $endDate);
echo "<pre>"; print_r($detailRows); echo "</pre>";

echo "<h2>3. Let's manually compute what funneltrackComputeDailyDetailRows should count</h2>";
$createdExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$qualifiedExpr = "COALESCE(l.qa_updated_at, $createdExpr)";
$stmt = $conn->prepare("
    SELECT DISTINCT l.id AS lead_id, l.agent_id, l.campaign_id, $qualifiedExpr AS qualified_at, DATE($qualifiedExpr) AS work_date, l.qa_status
    FROM leads l
    WHERE l.agent_id = ?
      AND l.qa_status IN ('Qualified', 'Disqualified')
      AND $qualifiedExpr >= ?
      AND $qualifiedExpr <= ?
");
$stmt->bind_param('iss', $agentId, $startTs, $endTs);
$stmt->execute();
$myLeads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'><tr><th>Lead ID</th><th>Work Date</th><th>QA Status</th><th>Campaign ID</th></tr>";
$myCount = 0;
$myLeadIds = [];
foreach ($myLeads as $l) {
    if ($l['qa_status'] == 'Qualified') {
        $myCount++;
        $myLeadIds[] = $l['lead_id'];
    }
    echo "<tr><td>{$l['lead_id']}</td><td>{$l['work_date']}</td><td>{$l['qa_status']}</td><td>{$l['campaign_id']}</td></tr>";
}
echo "</table>";
echo "<strong>Manual qualified count: {$myCount}</strong> (IDs: " . implode(", ", $myLeadIds) . ")<br><br>";

echo "<h2>4. Difference Check</h2>";
$teamSet = array_flip($teamLeadIds);
$mySet = array_flip($myLeadIds);
$onlyInTeam = array_diff_key($teamSet, $mySet);
$onlyInMy = array_diff_key($mySet, $teamSet);
if (empty($onlyInTeam) && empty($onlyInMy)) {
    echo "All leads match!";
} else {
    if (!empty($onlyInTeam)) {
        echo "Leads only in Team.php: " . implode(", ", array_keys($onlyInTeam)) . "<br>";
    }
    if (!empty($onlyInMy)) {
        echo "Leads only in our manual count: " . implode(", ", array_keys($onlyInMy)) . "<br>";
    }
}
?>