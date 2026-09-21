<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('admin');
ensureDatabaseSchema();

$conn = getDbConnection();
$currentUser = getCurrentUser() ?: [];
$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts((int)($currentUser['id'] ?? 0)) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$todayRange = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => $opsNow->format('Y-m-d 00:00:00'), 'end' => $opsNow->format('Y-m-d 23:59:59'), 'start_date' => $opsNow->format('Y-m-d')];
$opsTodayDate = (string)($todayRange['start_date'] ?? $opsNow->format('Y-m-d'));
$hrTodayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$todayStart = (string)($todayRange['start'] ?? '1970-01-01 00:00:00');
$todayEnd = (string)($todayRange['end'] ?? '2999-12-31 23:59:59');
$nowUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');
$monthStartFallback = $opsNow->format('Y-m-01 00:00:00');
$monthEndFallback = $opsNow->format('Y-m-t 23:59:59');

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? $monthStartFallback);
$rangeEnd = (string)($range['end'] ?? $monthEndFallback);
$rangeLabel = (string)($range['label'] ?? 'This Month');
$isOverall = ($preset === 'overall');
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$leadCreatedExprBare = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('') : 'created_at';
$adminSettingsUrl = appBasePath() . '/modules/admin/settings';
$campaignAnalyticsUrl = appBasePath() . '/modules/admin/analytics/campaigns';
$dbmsAnalyticsUrl = appBasePath() . '/modules/admin/analytics/dbms';

$todayKpi = ['generated' => 0, 'pending_qa' => 0, 'qa_in_progress' => 0, 'qa_rework_needed' => 0, 'qa_reopened' => 0, 'qa_rectified' => 0, 'qa_duplicate_supp' => 0, 'qa_tbd' => 0, 'qualified' => 0, 'disqualified' => 0, 'delivered' => 0, 'client_delivered' => 0, 'client_accepted' => 0, 'client_rejected' => 0, 'client_tbd' => 0, 'client_in_progress' => 0, 'forms' => 0, 'forms_pending' => 0];
$rangeKpi = $todayKpi;

$kpiSqlAll = "
    SELECT
      COUNT(*) AS generated,
      SUM(CASE WHEN (qa_status IS NULL OR qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
      SUM(CASE WHEN qa_status = 'In Progress' THEN 1 ELSE 0 END) AS qa_in_progress,
      SUM(CASE WHEN qa_status = 'Rework Needed' THEN 1 ELSE 0 END) AS qa_rework_needed,
      SUM(CASE WHEN qa_status = 'Reopened' THEN 1 ELSE 0 END) AS qa_reopened,
      SUM(CASE WHEN qa_status = 'Rectified' THEN 1 ELSE 0 END) AS qa_rectified,
      SUM(CASE WHEN qa_status = 'Duplicate/Supp' THEN 1 ELSE 0 END) AS qa_duplicate_supp,
      SUM(CASE WHEN qa_status = 'TBD' OR qa_status = 'TBD(To be discussed)' THEN 1 ELSE 0 END) AS qa_tbd,
      SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
      SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
      SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
      SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS client_delivered,
      SUM(CASE WHEN client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS client_accepted,
      SUM(CASE WHEN client_delivery_status = 'Rejected' THEN 1 ELSE 0 END) AS client_rejected,
      SUM(CASE WHEN client_delivery_status = 'TBD(To be discussed)' THEN 1 ELSE 0 END) AS client_tbd,
      SUM(CASE WHEN client_delivery_status = 'In Progress' THEN 1 ELSE 0 END) AS client_in_progress,
      SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) AS forms,
      SUM(CASE WHEN qa_status = 'Qualified' AND form_done = 'No' THEN 1 ELSE 0 END) AS forms_pending
    FROM leads
";
$kpiSqlRange = $kpiSqlAll . " WHERE $leadCreatedExprBare BETWEEN ? AND ?";
$stmt = $conn->prepare($kpiSqlRange);
if ($stmt) {
    $stmt->bind_param('ss', $todayStart, $todayEnd);
    $stmt->execute();
    $todayKpi = $stmt->get_result()->fetch_assoc() ?: $todayKpi;
    $stmt->close();
}
$stmt = $conn->prepare($isOverall ? $kpiSqlAll : $kpiSqlRange);
if ($stmt) {
    if (!$isOverall) {
        $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    }
    $stmt->execute();
    $rangeKpi = $stmt->get_result()->fetch_assoc() ?: $rangeKpi;
    $stmt->close();
}
$vendorLeadCondition = "((vendor_id IS NOT NULL AND vendor_id > 0) OR LOWER(COALESCE(lead_source,'')) = 'vendor')";
$todayVendorLeads = 0;
$rangeVendorLeads = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM leads WHERE $vendorLeadCondition AND $leadCreatedExprBare BETWEEN ? AND ?");
if ($stmt) {
    $stmt->bind_param('ss', $todayStart, $todayEnd);
    $stmt->execute();
    $todayVendorLeads = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmt->close();
}
$stmt = $conn->prepare($isOverall
    ? "SELECT COUNT(*) AS c FROM leads WHERE $vendorLeadCondition"
    : "SELECT COUNT(*) AS c FROM leads WHERE $vendorLeadCondition AND $leadCreatedExprBare BETWEEN ? AND ?");
if ($stmt) {
    if (!$isOverall) {
        $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    }
    $stmt->execute();
    $rangeVendorLeads = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmt->close();
}

$liveCampaigns = 0;
$stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM campaigns c
    JOIN campaign_details d ON d.campaign_id = c.id
    WHERE c.active = 1 AND d.status = 'Live'
");
if ($stmt) { $stmt->execute(); $liveCampaigns = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0); $stmt->close(); }
$activeCampaigns = 0;
$rs = $conn->query("SELECT COUNT(*) AS c FROM campaigns WHERE active = 1");
if ($rs) $activeCampaigns = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);

$onlineUsers = 0;
$onlineInternal = 0;
$rs = $conn->query("SELECT COUNT(*) AS c FROM user_presence WHERE is_online = 1");
if ($rs) $onlineUsers = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);
$rs = $conn->query("
    SELECT COUNT(*) AS c
    FROM user_presence p
    JOIN users u ON u.id = p.user_id
    WHERE p.is_online = 1 AND u.is_active = 1 AND u.role NOT LIKE 'client_%' AND u.role NOT LIKE 'vendor_%'
");
if ($rs) $onlineInternal = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);

$agentsLoggedInToday = 0;
$agentsLateToday = 0;
$agentRoles = ['operations_agent','agent','email_marketing_agent','email_marketing_executive','form_filler','qa_agent','qa'];
$inRoles = implode(',', array_fill(0, count($agentRoles), '?'));
$typesRoles = str_repeat('s', count($agentRoles));
$attendanceAgentIds = [];
$stmt = $conn->prepare("SELECT u.id FROM users u WHERE u.is_active = 1 AND u.role IN ($inRoles)");
if ($stmt) {
    mysqliBindParams($stmt, $typesRoles, $agentRoles);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) $attendanceAgentIds[] = (int)($row['id'] ?? 0);
    $stmt->close();
}
$attendanceAggregate = hrGetAttendanceAggregate($attendanceAgentIds, $hrTodayDate);
$agentsLoggedInToday = (int)($attendanceAggregate['today']['logged_in'] ?? 0);
$agentsLateToday = (int)($attendanceAggregate['today']['late'] ?? 0);
$agentsOnTimeToday = (int)($attendanceAggregate['today']['on_time'] ?? max(0, $agentsLoggedInToday - $agentsLateToday));

$todayCapacity = 0;
$yy = (int)$opsNow->format('Y');
$mo = (int)$opsNow->format('m');
$todayCapacity = hrGetTodayAttendanceCapacity($attendanceAgentIds, $yy, $mo, $hrTodayDate);

$invoiceTodayTotal = 0.0;
$invoiceRangeTotal = 0.0;
$paidRangeTotal = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE issue_date = ?");
if ($stmt) { $stmt->bind_param('s', $opsTodayDate); $stmt->execute(); $invoiceTodayTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }
$sd = substr($rangeStart, 0, 10);
$ed = substr($rangeEnd, 0, 10);
if ($isOverall) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices");
    if ($stmt) { $stmt->execute(); $invoiceRangeTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE status = 'Paid'");
    if ($stmt) { $stmt->execute(); $paidRangeTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }
} else {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE issue_date BETWEEN ? AND ?");
    if ($stmt) { $stmt->bind_param('ss', $sd, $ed); $stmt->execute(); $invoiceRangeTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE status = 'Paid' AND issue_date BETWEEN ? AND ?");
    if ($stmt) { $stmt->bind_param('ss', $sd, $ed); $stmt->execute(); $paidRangeTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }
}

$topCampaigns = [];
$stmt = $conn->prepare("
    SELECT c.name, COUNT(l.id) AS c
    FROM leads l
    JOIN campaigns c ON c.id = l.campaign_id
    " . ($isOverall ? "" : "WHERE $leadCreatedExpr BETWEEN ? AND ?") . "
    GROUP BY l.campaign_id
    ORDER BY c DESC
    LIMIT 3
");
if ($stmt) { if (!$isOverall) { $stmt->bind_param('ss', $rangeStart, $rangeEnd); } $stmt->execute(); $topCampaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; $stmt->close(); }

$topAgents = [];
$stmt = $conn->prepare("
    SELECT u.full_name, COUNT(l.id) AS c
    FROM leads l
    JOIN users u ON u.id = l.agent_id
    " . ($isOverall ? "" : "WHERE $leadCreatedExpr BETWEEN ? AND ?") . "
    GROUP BY l.agent_id
    ORDER BY c DESC
    LIMIT 3
");
if ($stmt) { if (!$isOverall) { $stmt->bind_param('ss', $rangeStart, $rangeEnd); } $stmt->execute(); $topAgents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; $stmt->close(); }

$topPerformers = [];
$bottomPerformers = [];
$bottomPerfAgents = [];
try {
    if (function_exists('funneltrackGetConfig') && function_exists('funneltrackComputeAgentRangeSummaries') && function_exists('funneltrackComputeAgentMonthSummaries')) {
        $cfg = funneltrackGetConfig();
        $monthNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monthYear = (int)$monthNow->format('Y');
        $monthNumber = (int)$monthNow->format('n');
        $monthStartDate = $monthNow->format('Y-m-01');
        $monthEndDate = $monthNow->format('Y-m-d');

        $cand = [];
        $rsU = $conn->query("
            SELECT id, full_name, role, job_title, department
            FROM users
            WHERE is_active = 1 AND role IN ('agent','operations_agent','email_marketing_agent','email_marketing_executive','form_filler')
            ORDER BY full_name ASC
        ");
        if ($rsU) $cand = $rsU->fetch_all(MYSQLI_ASSOC) ?: [];

        $candIds = [];
        foreach ($cand as $u) {
            $aid = (int)($u['id'] ?? 0);
            if ($aid > 0) $candIds[] = $aid;
        }
        $monthSummaries = !empty($candIds) ? funneltrackComputeAgentMonthSummaries($candIds, $monthYear, $monthNumber, $cfg) : [];
        $monthRangeSummaries = !empty($candIds) ? funneltrackComputeAgentRangeSummaries($candIds, $monthStartDate, $monthEndDate, $cfg) : [];
        $rows = [];
        foreach ($cand as $u) {
            $aid = (int)($u['id'] ?? 0);
            if ($aid <= 0) continue;
            $sum = $monthSummaries[$aid] ?? [];
            $rangeSum = $monthRangeSummaries[$aid] ?? [];
            $score = (float)($sum['score']['total'] ?? 0.0);
            $productivity = (float)($sum['productivity_pct'] ?? 0.0);
            $qualified = (int)($sum['quality']['qualified'] ?? 0);
            $incentiveAmount = (int)($rangeSum['incentives']['range_total'] ?? 0) + (int)($sum['incentives']['monthly'] ?? 0);
            if ($score <= 0 && $productivity <= 0 && $qualified <= 0 && $incentiveAmount <= 0) continue;
            $rows[] = [
                'id' => $aid,
                'name' => (string)($u['full_name'] ?? ''),
                'role' => (string)($u['role'] ?? ''),
                'job_title' => (string)($u['job_title'] ?? ''),
                'department' => (string)($u['department'] ?? ''),
                'score' => $score,
                'rank' => (string)($sum['rank'] ?? ''),
                'productivity_pct' => $productivity,
                'earned_incentive' => $incentiveAmount > 0 ? 'Yes' : 'No',
                'amount' => $incentiveAmount,
            ];
        }
        
        // Sort by score for top performers (highest first)
        usort($rows, fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));
        $topPerformers = array_slice($rows, 0, 3);
        
        // Sort by score for bottom performers (lowest first)
        usort($rows, fn($a, $b) => ($a['score'] <=> $b['score']) ?: strcmp($a['name'], $b['name']));
        $bottomPerformers = array_slice($rows, 0, 3);
        $bottomPerfAgents = $bottomPerformers;
    }
} catch (Throwable $e) {}

$recentLeads = [];
$rs = $conn->query("
    SELECT l.id, l.company_name, l.email, l.qa_status, l.client_delivery_status, l.created_at,
           c.name AS campaign_name, u.full_name AS agent_name
    FROM leads l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN users u ON u.id = l.agent_id
    ORDER BY $leadCreatedExpr DESC
    LIMIT 12
");
if ($rs) $recentLeads = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$campPerf = [];
$stmt = $conn->prepare("
    SELECT c.id, c.name, COUNT(l.id) AS leads_count
    FROM campaigns c
    LEFT JOIN leads l ON l.campaign_id = c.id " . ($isOverall ? "" : "AND $leadCreatedExpr BETWEEN ? AND ?") . "
    WHERE c.active = 1
    GROUP BY c.id
");
if ($stmt) { if (!$isOverall) { $stmt->bind_param('ss', $rangeStart, $rangeEnd); } $stmt->execute(); $campPerf = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; $stmt->close(); }

$revByCamp = [];
$stmt = $conn->prepare($isOverall
    ? "SELECT campaign_id, COALESCE(SUM(total),0) AS rev FROM revenue_invoices GROUP BY campaign_id"
    : "SELECT campaign_id, COALESCE(SUM(total),0) AS rev FROM revenue_invoices WHERE issue_date BETWEEN ? AND ? GROUP BY campaign_id"
);
if ($stmt) {
    if (!$isOverall) { $stmt->bind_param('ss', $sd, $ed); }
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) $revByCamp[(int)($r['campaign_id'] ?? 0)] = (float)($r['rev'] ?? 0);
}
$expByCamp = [];
$stmt = $conn->prepare($isOverall
    ? "SELECT campaign_id, COALESCE(SUM(amount),0) AS exp FROM revenue_manual_expenses GROUP BY campaign_id"
    : "SELECT campaign_id, COALESCE(SUM(amount),0) AS exp FROM revenue_manual_expenses WHERE expense_date BETWEEN ? AND ? GROUP BY campaign_id"
);
if ($stmt) {
    if (!$isOverall) { $stmt->bind_param('ss', $sd, $ed); }
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) $expByCamp[(int)($r['campaign_id'] ?? 0)] = (float)($r['exp'] ?? 0);
}

$roiRows = [];
foreach ($campPerf as $r) {
    $cid = (int)($r['id'] ?? 0);
    $rev = (float)($revByCamp[$cid] ?? 0);
    $exp = (float)($expByCamp[$cid] ?? 0);
    $roi = $exp > 0 ? (($rev - $exp) / $exp) : ($rev > 0 ? 1.0 : 0.0);
    $roiRows[] = ['name' => (string)($r['name'] ?? ''), 'leads' => (int)($r['leads_count'] ?? 0), 'revenue' => $rev, 'expense' => $exp, 'roi' => $roi];
}
usort($roiRows, fn($a,$b) => ($b['roi'] <=> $a['roi']));
$top3Roi = array_slice($roiRows, 0, 3);
$bottom3Roi = array_slice(array_reverse($roiRows), 0, 3);

$dateFrom = $sd;
$dateTo = $ed;
$tabCounts = function_exists('getCampaignTabCounts') ? getCampaignTabCounts($dateFrom, $dateTo) : [];
$overviewRows = function_exists('getCampaignOverviewByStatus') ? getCampaignOverviewByStatus(null, $dateFrom, $dateTo) : [];
$deliveredByCampaign = [];
$acceptedByCampaign = [];
$pendingQaByCampaign = [];
$lastLeadAtByCampaign = [];
$campaignIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $overviewRows), fn($v) => $v > 0));
if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $types = $isOverall ? str_repeat('i', count($campaignIds)) : (str_repeat('i', count($campaignIds)) . 'ss');
    $params = $isOverall ? $campaignIds : array_merge($campaignIds, [$rangeStart, $rangeEnd]);
    $stmt = $conn->prepare("
        SELECT
          l.campaign_id,
          SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
          SUM(CASE WHEN l.client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS accepted,
          SUM(CASE WHEN (l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
          MAX($leadCreatedExpr) AS last_lead_at
        FROM leads l
        WHERE l.campaign_id IN ($in) " . ($isOverall ? "" : "AND $leadCreatedExpr BETWEEN ? AND ?") . "
        GROUP BY l.campaign_id
    ");
    if ($stmt) {
        mysqliBindParams($stmt, $types, $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $cid = (int)($r['campaign_id'] ?? 0);
            if ($cid <= 0) continue;
            $deliveredByCampaign[$cid] = (int)($r['delivered'] ?? 0);
            $acceptedByCampaign[$cid] = (int)($r['accepted'] ?? 0);
            $pendingQaByCampaign[$cid] = (int)($r['pending_qa'] ?? 0);
            $lastLeadAtByCampaign[$cid] = $r['last_lead_at'] ?? null;
        }
    }
}

$budgetTotals = [
    'allocation_leads' => 0,
    'delivered_leads' => 0,
    'accepted_leads' => 0,
    'pending_leads' => 0,
    'allocation_usd' => 0.0,
    'delivered_usd' => 0.0,
    'accepted_usd' => 0.0,
    'pending_usd' => 0.0,
];
$riskCampaigns = [];
foreach ($overviewRows as $r) {
    $campId = (int)($r['id'] ?? 0);
    $alloc = (int)($r['total_leads'] ?? 0);
    $del = $campId > 0 ? (int)($deliveredByCampaign[$campId] ?? 0) : (int)($r['delivered'] ?? 0);
    $acc = $campId > 0 ? (int)($acceptedByCampaign[$campId] ?? 0) : 0;
    $cpl = (float)($r['cpl'] ?? 0);
    $budgetTotals['allocation_leads'] += $alloc;
    $budgetTotals['delivered_leads'] += $del;
    $budgetTotals['accepted_leads'] += $acc;
    $budgetTotals['pending_leads'] += max(0, $alloc - $del);
    $budgetTotals['allocation_usd'] += ($alloc * $cpl);
    $budgetTotals['delivered_usd'] += ($del * $cpl);
    $budgetTotals['accepted_usd'] += ($acc * $cpl);
    $budgetTotals['pending_usd'] += (max(0, $alloc - $del) * $cpl);

    $status = (string)($r['status'] ?? '');
    $endDate = (string)($r['end_date'] ?? '');
    if ($status !== 'Live' || $endDate === '') continue;
    try {
        $endDt = new DateTimeImmutable($endDate . ' 23:59:59', opsBusinessTz());
    } catch (Throwable $e) {
        continue;
    }
    $daysLeft = (int)ceil(($endDt->getTimestamp() - $opsNow->getTimestamp()) / 86400);
    if ($daysLeft < 0 || $daysLeft > 3) continue;
    $pct = $alloc > 0 ? (int)round(($del / max(1, $alloc)) * 100) : 0;
    if ($pct >= 80) continue;
    $riskCampaigns[] = [
        'id' => $campId,
        'code' => (string)($r['code'] ?? ''),
        'name' => (string)($r['name'] ?? ''),
        'end_date' => $endDate,
        'days_left' => $daysLeft,
        'allocation' => $alloc,
        'delivered' => $del,
        'pacing_pct' => $pct,
        'pending_qa' => $campId > 0 ? (int)($pendingQaByCampaign[$campId] ?? 0) : 0,
    ];
}
usort($riskCampaigns, function($a,$b){
    $d = ($a['days_left'] ?? 0) <=> ($b['days_left'] ?? 0);
    if ($d !== 0) return $d;
    return ($a['pacing_pct'] ?? 0) <=> ($b['pacing_pct'] ?? 0);
});
$riskCampaigns = array_slice($riskCampaigns, 0, 12);

$clientRollups = [];
foreach ($overviewRows as $r) {
    $campId = (int)($r['id'] ?? 0);
    $cid = (int)($r['client_id'] ?? 0);
    $cc = trim((string)($r['client_code'] ?? ''));
    $cn = trim((string)($r['client_name'] ?? ''));
    $key = $cid > 0 ? ('id:' . $cid) : ($cc !== '' ? ('code:' . $cc) : 'no-client');
    if (!isset($clientRollups[$key])) {
        $clientRollups[$key] = [
            'client_id' => $cid,
            'client_code' => $cc,
            'client_name' => $cn,
            'campaigns' => 0,
            'live' => 0,
            'allocation_leads' => 0,
            'delivered_leads' => 0,
            'accepted_leads' => 0,
            'pending_qa' => 0,
            'last_lead_at' => null,
        ];
    }
    $clientRollups[$key]['campaigns'] += 1;
    if (((string)($r['status'] ?? '')) === 'Live') $clientRollups[$key]['live'] += 1;
    $alloc = (int)($r['total_leads'] ?? 0);
    $del = $campId > 0 ? (int)($deliveredByCampaign[$campId] ?? 0) : (int)($r['delivered'] ?? 0);
    $acc = $campId > 0 ? (int)($acceptedByCampaign[$campId] ?? 0) : 0;
    $clientRollups[$key]['allocation_leads'] += $alloc;
    $clientRollups[$key]['delivered_leads'] += $del;
    $clientRollups[$key]['accepted_leads'] += $acc;
    $clientRollups[$key]['pending_qa'] += $campId > 0 ? (int)($pendingQaByCampaign[$campId] ?? 0) : 0;
    $ll = $campId > 0 ? ($lastLeadAtByCampaign[$campId] ?? null) : null;
    if ($ll) {
        if (!$clientRollups[$key]['last_lead_at'] || strtotime((string)$ll) > strtotime((string)$clientRollups[$key]['last_lead_at'])) {
            $clientRollups[$key]['last_lead_at'] = $ll;
        }
    }
}
$clientRollupRows = array_values($clientRollups);
foreach ($clientRollupRows as &$cr) {
    $cr['pending_leads'] = max(0, (int)$cr['allocation_leads'] - (int)$cr['delivered_leads']);
    $cr['pacing_pct'] = ((int)$cr['allocation_leads'] > 0)
        ? min(100, round(((int)$cr['delivered_leads'] / max(1, (int)$cr['allocation_leads'])) * 100))
        : 0;
}
unset($cr);
usort($clientRollupRows, fn($a,$b) => (($b['pending_leads'] ?? 0) <=> ($a['pending_leads'] ?? 0)));
$clientRollupRows = array_slice($clientRollupRows, 0, 10);

$sales = ['overdue' => 0, 'open' => 0, 'won' => 0, 'lost' => 0];
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sales_leads WHERE next_follow_up_at IS NOT NULL AND next_follow_up_at < ? AND status NOT IN ('Closed Won','Closed Lost')");
if ($stmt) {
    $stmt->bind_param('s', $nowUtc);
    $stmt->execute();
    $sales['overdue'] = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmt->close();
}

$incentiveMetrics = function_exists('funneltrackGetIncentiveMetrics') ? funneltrackGetIncentiveMetrics() : [
    'today_daily_incentive' => 0,
    'total_daily_incentive_paid' => 0,
    'monthly_incentive' => 0,
    'monthly_productivity_pct_avg' => 0.0,
    'monthly_eligible_users' => 0,
    'monthly_visible_users' => 0,
    'bonus' => 0,
    'this_month_total' => 0,
    'previous_month_total' => 0,
];
$stmt = $conn->prepare("
    SELECT
      SUM(CASE WHEN status NOT IN ('Closed Won','Closed Lost') THEN 1 ELSE 0 END) AS open_cnt,
      SUM(CASE WHEN status = 'Closed Won' THEN 1 ELSE 0 END) AS won_cnt,
      SUM(CASE WHEN status = 'Closed Lost' THEN 1 ELSE 0 END) AS lost_cnt
    FROM sales_leads
    " . ($isOverall ? "" : "WHERE created_at BETWEEN ? AND ?") . "
");
if ($stmt) {
    if (!$isOverall) { $stmt->bind_param('ss', $rangeStart, $rangeEnd); }
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $sales['open'] = (int)($r['open_cnt'] ?? 0);
    $sales['won'] = (int)($r['won_cnt'] ?? 0);
    $sales['lost'] = (int)($r['lost_cnt'] ?? 0);
}

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 admin-dashboard">
    <div class="admin-dashboard-head mb-3">
        <div>
            <div class="h3 mb-1">Admin Dashboard</div>
            <div class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></div>
        </div>
        <div class="admin-dashboard-actions">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($adminSettingsUrl); ?>"><i class="bi bi-sliders me-1"></i>Settings</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($campaignAnalyticsUrl); ?>"><i class="bi bi-graph-up-arrow me-1"></i>Campaign Analytics</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($dbmsAnalyticsUrl); ?>"><i class="bi bi-database-check me-1"></i>DBMS Analytics</a>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3 admin-dashboard-filter" method="get">
        <div class="col-md-3">
            <label class="form-label small text-muted">Quick Range</label>
            <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
                <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
                <option value="this_month" <?php echo $preset==='this_month'?'selected':''; ?>>This Month</option>
                <option value="overall" <?php echo $preset==='overall'?'selected':''; ?>>Overall</option>
                <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom Range</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Start</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($sd); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">End</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($ed); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Online Users</div>
                        <div class="h4 mb-0"><?php echo number_format($onlineUsers); ?></div>
                        <div class="small text-muted">Internal: <?php echo number_format($onlineInternal); ?></div>
                    </div>
                    <div class="text-primary fs-2"><i class="bi bi-people"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Live Campaigns</div>
                        <div class="h4 mb-0"><?php echo number_format($liveCampaigns); ?></div>
                        <div class="small text-muted">Active: <?php echo number_format($activeCampaigns); ?> · Risk: <?php echo number_format(count($riskCampaigns)); ?></div>
                    </div>
                    <div class="text-success fs-2"><i class="bi bi-broadcast"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Agents Logged In (Today)</div>
                        <div class="h4 mb-0"><?php echo number_format($agentsLoggedInToday); ?></div>
                        <div class="small text-muted">Late: <?php echo number_format($agentsLateToday); ?> · On-time: <?php echo number_format($agentsOnTimeToday); ?></div>
                    </div>
                    <div class="text-info fs-2"><i class="bi bi-box-arrow-in-right"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Max Production Capacity (Today)</div>
                        <div class="h4 mb-0"><?php echo number_format($todayCapacity); ?></div>
                        <div class="small text-muted">Avg/agent: <?php echo number_format($agentsLoggedInToday > 0 ? round($todayCapacity / max(1, $agentsLoggedInToday)) : 0); ?> · Based on targets</div>
                    </div>
                    <div class="text-warning fs-2"><i class="bi bi-bullseye"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 admin-panel">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-calendar-day me-1"></i>Today</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Generated</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['generated']); ?></div>
                            </div>
                            <div class="text-primary fs-4"><i class="bi bi-collection"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Vendor Leads</div>
                                <div class="h4 mb-0"><?php echo number_format($todayVendorLeads); ?></div>
                                <div class="small text-muted">Leads created from vendor accounts today</div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-truck"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Form Submitted</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['forms']); ?></div>
                                <div class="small text-muted">Pending forms: <?php echo number_format((int)$todayKpi['forms_pending']); ?></div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-ui-checks"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Pending QA</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['pending_qa']); ?></div>
                                <div class="small text-muted">
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($opsTodayDate); ?>&date_to=<?php echo htmlspecialchars($opsTodayDate); ?>&qa_status=In+Progress" class="text-decoration-none text-info">In Progress: <?php echo number_format((int)($todayKpi['qa_in_progress'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($opsTodayDate); ?>&date_to=<?php echo htmlspecialchars($opsTodayDate); ?>&qa_status=Rework+Needed" class="text-decoration-none text-danger">Rework: <?php echo number_format((int)($todayKpi['qa_rework_needed'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($opsTodayDate); ?>&date_to=<?php echo htmlspecialchars($opsTodayDate); ?>&qa_status=Reopened" class="text-decoration-none text-warning">Reopened: <?php echo number_format((int)($todayKpi['qa_reopened'] ?? 0)); ?></a>
                                </div>
                                <div class="small text-muted">
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($opsTodayDate); ?>&date_to=<?php echo htmlspecialchars($opsTodayDate); ?>&qa_status=Rectified" class="text-decoration-none text-success">Rectified: <?php echo number_format((int)($todayKpi['qa_rectified'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($opsTodayDate); ?>&date_to=<?php echo htmlspecialchars($opsTodayDate); ?>&qa_status=Duplicate%2FSupp" class="text-decoration-none text-secondary">Duplicate: <?php echo number_format((int)($todayKpi['qa_duplicate_supp'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($opsTodayDate); ?>&date_to=<?php echo htmlspecialchars($opsTodayDate); ?>&qa_status=TBD" class="text-decoration-none text-primary">TBD: <?php echo number_format((int)($todayKpi['qa_tbd'] ?? 0)); ?></a>
                                </div>
                            </div>
                            <div class="text-warning fs-4"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Qualified</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['qualified']); ?></div>
                            </div>
                            <div class="text-success fs-4"><i class="bi bi-check2-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <!-- <div class="text-muted small">Disqualified</div> -->
                                <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($opsTodayDate); ?>&date_to=<?php echo htmlspecialchars($opsTodayDate); ?>&qa_status=Disqualified" class="text-decoration-none text-dandanger">Disqualified</a>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['disqualified']); ?></div>
                            </div>
                            <div class="text-danger fs-4"><i class="bi bi-x-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Client Delivered</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['client_delivered']); ?></div>
                            </div>
                            <div class="text-success fs-4"><i class="bi bi-truck"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Client Accepted</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['client_accepted']); ?></div>
                            </div>
                            <div class="fs-4" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Revenue Issued</div>
                                <div class="h4 mb-0"><?php echo number_format($invoiceTodayTotal, 2); ?></div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-currency-dollar"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>">
                        <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                            <div class="card-body d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="text-muted small">My Tasks</div>
                                    <div class="h4 mb-0"><?php echo number_format((int)$taskWidget['pending']); ?></div>
                                    <div class="small text-muted">Due: <?php echo number_format((int)$taskWidget['due_today']); ?> • Overdue: <?php echo number_format((int)$taskWidget['overdue']); ?></div>
                                </div>
                                <div class="text-warning fs-4"><i class="bi bi-kanban"></i></div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-range me-1"></i>Selected Range</span>
            <span class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Generated</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['generated']); ?></div>
                            </div>
                            <div class="text-primary fs-4"><i class="bi bi-collection"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Vendor Leads</div>
                                <div class="h4 mb-0"><?php echo number_format($rangeVendorLeads); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($rangeLabel); ?> vendor-source lead volume</div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-truck"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Form Submitted</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['forms']); ?></div>
                                <div class="small text-muted">Pending forms: <?php echo number_format((int)$rangeKpi['forms_pending']); ?></div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-ui-checks"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Pending QA</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['pending_qa']); ?></div>
                                <div class="small text-muted">
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($sd); ?>&date_to=<?php echo htmlspecialchars($ed); ?>&qa_status=In+Progress" class="text-decoration-none text-info">In Progress: <?php echo number_format((int)($rangeKpi['qa_in_progress'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($sd); ?>&date_to=<?php echo htmlspecialchars($ed); ?>&qa_status=Rework+Needed" class="text-decoration-none text-danger">Rework: <?php echo number_format((int)($rangeKpi['qa_rework_needed'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($sd); ?>&date_to=<?php echo htmlspecialchars($ed); ?>&qa_status=Reopened" class="text-decoration-none text-warning">Reopened: <?php echo number_format((int)($rangeKpi['qa_reopened'] ?? 0)); ?></a>
                                </div>
                                <div class="small text-muted">
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($sd); ?>&date_to=<?php echo htmlspecialchars($ed); ?>&qa_status=Rectified" class="text-decoration-none text-success">Rectified: <?php echo number_format((int)($rangeKpi['qa_rectified'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($sd); ?>&date_to=<?php echo htmlspecialchars($ed); ?>&qa_status=Duplicate%2FSupp" class="text-decoration-none text-secondary">Duplicate: <?php echo number_format((int)($rangeKpi['qa_duplicate_supp'] ?? 0)); ?></a>
                                    · 
                                    <a href="<?php echo appBasePath(); ?>/modules/leads/leads-edit.php?date_from=<?php echo htmlspecialchars($sd); ?>&date_to=<?php echo htmlspecialchars($ed); ?>&qa_status=TBD" class="text-decoration-none text-primary">TBD: <?php echo number_format((int)($rangeKpi['qa_tbd'] ?? 0)); ?></a>
                                </div>
                            </div>
                            <div class="text-warning fs-4"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Qualified</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['qualified']); ?></div>
                            </div>
                            <div class="text-success fs-4"><i class="bi bi-check2-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Disqualified</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['disqualified']); ?></div>
                            </div>
                            <div class="text-danger fs-4"><i class="bi bi-x-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Client Delivered</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['client_delivered']); ?></div>
                            </div>
                            <div class="text-success fs-4"><i class="bi bi-truck"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Client Accepted</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['client_accepted']); ?></div>
                            </div>
                            <div class="fs-4" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Revenue</div>
                                <div class="h5 mb-0"><?php echo number_format($invoiceRangeTotal, 2); ?></div>
                                <div class="small text-muted">Paid: <?php echo number_format($paidRangeTotal, 2); ?></div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-cash-stack"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
                            <div class="fw-semibold">Client QA Status</div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge bg-primary-subtle text-primary border">Delivered: <?php echo number_format((int)($rangeKpi['client_delivered'] ?? 0)); ?></span>
                                <span class="badge bg-success-subtle text-success border">Accepted: <?php echo number_format((int)($rangeKpi['client_accepted'] ?? 0)); ?></span>
                                <span class="badge bg-danger-subtle text-danger border">Rejected: <?php echo number_format((int)($rangeKpi['client_rejected'] ?? 0)); ?></span>
                                <span class="badge bg-secondary-subtle text-secondary border">TBD: <?php echo number_format((int)($rangeKpi['client_tbd'] ?? 0)); ?></span>
                                <span class="badge bg-info-subtle text-info border">In Progress: <?php echo number_format((int)($rangeKpi['client_in_progress'] ?? 0)); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    <div class="row g-3 mb-3 mt-1">
    <div class="col-lg-6">
      <div class="card campaign-dash-card h-100 admin-panel">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-trophy me-1"></i>Top 3 Performers (Current Month)</span>
          <span class="text-muted small"><?php echo htmlspecialchars(date('F Y')); ?></span>
        </div>
        <div class="admin-table-shell">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Rank</th>
                <th>Performer</th>
                <th class="text-end">Productivity</th>
                <th class="text-end">Earned Incentive</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($topPerformers)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No current-month performer data available.</td></tr>
              <?php else: ?>
                <?php foreach ($topPerformers as $index => $p): ?>
                  <?php $podium = getPerformerPodiumMeta((int)$index); ?>
                  <tr>
                    <td>
                      <div class="performer-rank-stack">
                        <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                        <span class="performer-podium-note"><?php echo htmlspecialchars($podium['place']); ?></span>
                      </div>
                    </td>
                    <td>
                      <div class="admin-cell-primary"><?php echo htmlspecialchars(formatUserNameWithRole((string)$p['name'], (string)$p['role'], (string)($p['job_title'] ?? ''))); ?></div>
                      <div class="admin-cell-secondary">Performance tier: <?php echo htmlspecialchars((string)($p['rank'] ?: 'Unranked')); ?></div>
                    </td>
                    <td class="text-end font-monospace"><?php echo number_format((float)($p['productivity_pct'] ?? 0), 1); ?>%</td>
                    <td class="text-end"><?php echo htmlspecialchars((string)($p['earned_incentive'] ?? 'No')); ?></td>
                    <td class="text-end font-monospace">Rs. <?php echo number_format((int)($p['amount'] ?? 0)); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="admin-table-note">Highlights the strongest current-month contributors by productivity and earned incentive amount.</div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card campaign-dash-card h-100 admin-panel">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-graph-down-arrow me-1"></i>Bottom 3 Performers (Current Month)</span>
          <span class="text-muted small"><?php echo htmlspecialchars(date('F Y')); ?></span>
        </div>
        <div class="admin-table-shell">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Rank</th>
                <th>Performer</th>
                <th class="text-end">Productivity</th>
                <th class="text-end">Earned Incentive</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($bottomPerformers)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No current-month performer data available.</td></tr>
              <?php else: ?>
                <?php foreach ($bottomPerformers as $index => $p): ?>
                  <?php $podium = getPerformerPodiumMeta((int)$index, 'bottom'); ?>
                  <tr>
                    <td>
                      <div class="performer-rank-stack">
                        <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                        <span class="performer-podium-note"><?php echo htmlspecialchars($podium['place']); ?></span>
                      </div>
                    </td>
                    <td>
                      <div class="admin-cell-primary"><?php echo htmlspecialchars(formatUserNameWithRole((string)$p['name'], (string)$p['role'], (string)($p['job_title'] ?? ''))); ?></div>
                      <div class="admin-cell-secondary">Performance tier: <?php echo htmlspecialchars((string)($p['rank'] ?: 'Unranked')); ?></div>
                    </td>
                    <td class="text-end font-monospace"><?php echo number_format((float)($p['productivity_pct'] ?? 0), 1); ?>%</td>
                    <td class="text-end"><?php echo htmlspecialchars((string)($p['earned_incentive'] ?? 'No')); ?></td>
                    <td class="text-end font-monospace">Rs. <?php echo number_format((int)($p['amount'] ?? 0)); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="admin-table-note">Surfaces the lowest current-month productivity rows so managers can intervene faster.</div>
        </div>
      </div>
    </div>
  </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 admin-panel">
                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle me-1"></i>Ending Soon (Risk)</span>
                    <span class="text-muted small">Live · ≤ 3 days · &lt; 80%</span>
                </div>
                <div class="card-body p-0">
                    <div class="admin-table-shell">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Campaign</th>
                                    <th class="text-end">Days</th>
                                    <th class="text-end">Alloc</th>
                                    <th class="text-end">Del</th>
                                    <th class="text-end">Pace</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($riskCampaigns as $r): ?>
                                    <?php
                                        $label = trim((string)($r['code'] ?? ''));
                                        if ($label === '') $label = (string)($r['name'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="admin-cell-primary">
                                            <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/campaigns/view?id=<?php echo (int)($r['id'] ?? 0); ?>"><?php echo htmlspecialchars($label); ?></a>
                                            </span>
                                            <div class="admin-cell-secondary">PQA: <?php echo number_format((int)($r['pending_qa'] ?? 0)); ?></div>
                                            <div class="progress mt-2" style="height:6px;">
                                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo (int)max(0, min(100, (int)($r['pacing_pct'] ?? 0))); ?>%"></div>
                                            </div>
                                        </td>
                                        <td class="text-end"><?php echo number_format((int)($r['days_left'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['allocation'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                        <td class="text-end"><span class="badge bg-warning text-dark"><?php echo number_format((int)($r['pacing_pct'] ?? 0)); ?>%</span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($riskCampaigns)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No risk campaigns in this range.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="admin-table-note">Campaigns near end date with weaker delivery pace are grouped here for quick risk review.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 admin-panel">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-cash-coin me-1"></i>Incentive Summary</div>
                <div class="card-body p-0">
                    <div class="admin-table-shell">
                    <div class="p-3">
                        <div class="admin-inline-grid">
                            <div class="admin-inline-metric kpi-tile kpi-primary h-100">
                                <div class="text-muted small">Today's Daily Incentive</div>
                                <div class="h4 mb-0">₹<?php echo number_format((int)($incentiveMetrics['today_daily_incentive'] ?? 0)); ?></div>
                                <div class="small text-muted">Daily incentive for <?php echo htmlspecialchars($opsTodayDate); ?></div>
                            </div>
                            <div class="admin-inline-metric kpi-tile kpi-info h-100">
                                <div class="text-muted small">Total Daily Incentive (This Month)</div>
                                <div class="h4 mb-0">₹<?php echo number_format((int)($incentiveMetrics['total_daily_incentive_paid'] ?? 0)); ?></div>
                                <div class="small text-muted">Daily incentive for current month</div>
                            </div>
                            <div class="admin-inline-metric kpi-tile <?php echo !empty($incentiveMetrics['is_current_month_complete']) ? 'kpi-success' : 'kpi-secondary'; ?> h-100">
                                <div class="text-muted small">Monthly Incentive</div>
                                <?php if (!empty($incentiveMetrics['is_current_month_complete'])): ?>
                                    <div class="h4 mb-0">₹<?php echo number_format((int)($incentiveMetrics['monthly_incentive'] ?? 0)); ?></div>
                                    <div class="small text-muted">Productivity <?php echo number_format((float)($incentiveMetrics['monthly_productivity_pct_avg'] ?? 0), 1); ?>% · Eligible <?php echo number_format((int)($incentiveMetrics['monthly_eligible_users'] ?? 0)); ?>/<?php echo number_format((int)($incentiveMetrics['monthly_visible_users'] ?? 0)); ?></div>
                                    <div class="small text-muted">Amount shows only for monthly eligible agents.</div>
                                <?php else: ?>
                                    <div class="h4 mb-0">—</div>
                                    <div class="small text-muted">Pending month end</div>
                                <?php endif; ?>
                            </div>
                            <div class="admin-inline-metric kpi-tile kpi-warning h-100">
                                <div class="text-muted small">Bonus</div>
                                <div class="h4 mb-0">₹<?php echo number_format((int)($incentiveMetrics['bonus'] ?? 0)); ?></div>
                                <div class="small text-muted">Manual bonus from overrides</div>
                            </div>
                            <div class="admin-inline-metric kpi-tile kpi-purple h-100">
                                <div class="text-muted small">This Month's Total</div>
                                <div class="h4 mb-0">₹<?php echo number_format((int)($incentiveMetrics['this_month_total'] ?? 0)); ?></div>
                                <div class="small text-muted">Daily + Monthly + Bonus</div>
                            </div>
                            <div class="admin-inline-metric kpi-tile kpi-danger h-100">
                                <div class="text-muted small">Previous Month's Total</div>
                                <div class="h4 mb-0">₹<?php echo number_format((int)($incentiveMetrics['previous_month_total'] ?? 0)); ?></div>
                                <div class="small text-muted">Last month's total incentives</div>
                            </div>
                        </div>
                        <div class="text-muted small mt-3">Incentive calculations based on configured slabs, thresholds, and agent performance metrics.</div>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 admin-panel">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-arrows-angle-contract me-1"></i>Allocation vs Delivered</div>
                <div class="card-body">
                    <div class="admin-inline-grid">
                        <div class="admin-inline-metric kpi-tile kpi-primary h-100">
                                <div class="text-muted small">Allocation (Leads)</div>
                                <div class="h4 mb-0"><?php echo number_format((int)($budgetTotals['allocation_leads'] ?? 0)); ?></div>
                                <div class="small text-muted">Pending: <?php echo number_format((int)($budgetTotals['pending_leads'] ?? 0)); ?></div>
                        </div>
                        <div class="admin-inline-metric kpi-tile kpi-success h-100">
                                <div class="text-muted small">Delivered (Leads)</div>
                                <div class="h4 mb-0"><?php echo number_format((int)($budgetTotals['delivered_leads'] ?? 0)); ?></div>
                                <div class="small text-muted">Pacing: <?php echo number_format(((int)($budgetTotals['allocation_leads'] ?? 0)) > 0 ? round(((int)($budgetTotals['delivered_leads'] ?? 0) / max(1, (int)($budgetTotals['allocation_leads'] ?? 0))) * 100) : 0); ?>%</div>
                        </div>
                        <div class="admin-inline-metric kpi-tile kpi-purple h-100">
                                <div class="text-muted small">Accepted (Leads)</div>
                                <div class="h4 mb-0"><?php echo number_format((int)($budgetTotals['accepted_leads'] ?? 0)); ?></div>
                                <div class="small text-muted">Client accepted count in range</div>
                        </div>
                        <div class="admin-inline-metric kpi-tile kpi-info h-100">
                                <div class="text-muted small">Allocation (USD)</div>
                                <div class="h4 mb-0"><?php echo number_format((float)($budgetTotals['allocation_usd'] ?? 0), 2); ?></div>
                                <div class="small text-muted">Pending: <?php echo number_format((float)($budgetTotals['pending_usd'] ?? 0), 2); ?></div>
                        </div>
                        <div class="admin-inline-metric kpi-tile kpi-info h-100">
                                <div class="text-muted small">Delivered (USD)</div>
                                <div class="h4 mb-0"><?php echo number_format((float)($budgetTotals['delivered_usd'] ?? 0), 2); ?></div>
                                <div class="small text-muted">Range: <?php echo htmlspecialchars($rangeLabel); ?></div>
                        </div>
                        <div class="admin-inline-metric kpi-tile kpi-purple h-100">
                                <div class="text-muted small">Accepted (USD)</div>
                                <div class="h4 mb-0"><?php echo number_format((float)($budgetTotals['accepted_usd'] ?? 0), 2); ?></div>
                                <div class="small text-muted">Range: <?php echo htmlspecialchars($rangeLabel); ?></div>
                        </div>
                    </div>
                    <div class="text-muted small mt-3">Totals are calculated using campaign CPL × client delivered and client accepted leads in the selected range.</div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 admin-panel">
                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-building me-1"></i>Client Rollup</span>
                    <span class="text-muted small">Top by pending</span>
                </div>
                <div class="card-body p-0">
                    <div class="admin-table-shell">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Client</th>
                                    <th class="text-end">Live</th>
                                    <th class="text-end">Allocation</th>
                                    <th class="text-end">Delivered</th>
                                    <th class="text-end">Accepted</th>
                                    <th class="text-end">Pending</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientRollupRows as $r): ?>
                                    <?php
                                        $label = trim((string)($r['client_code'] ?? ''));
                                        if ($label === '') $label = trim((string)($r['client_name'] ?? ''));
                                        if ($label === '') $label = 'No Client';
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="admin-cell-primary"><?php echo htmlspecialchars($label); ?></span>
                                            <div class="admin-cell-secondary">PQA: <?php echo number_format((int)($r['pending_qa'] ?? 0)); ?> · Pace: <?php echo number_format((int)($r['pacing_pct'] ?? 0)); ?>%</div>
                                        </td>
                                        <td class="text-end"><?php echo number_format((int)($r['live'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['allocation_leads'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['delivered_leads'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['accepted_leads'] ?? 0)); ?></td>
                                        <td class="text-end"><span class="badge bg-secondary"><?php echo number_format((int)($r['pending_leads'] ?? 0)); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($clientRollupRows)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No client rollup data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="admin-table-note">Rolls up live client performance so pending load and pacing issues are visible at account level.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 admin-panel">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-cash-coin me-1"></i>Earnings Overview (Campaign ROI)</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <div class="fw-semibold mb-2">Top 3</div>
                    <div class="admin-table-shell"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Campaign</th><th class="text-end">Leads</th><th class="text-end">Revenue</th><th class="text-end">ROI</th></tr></thead>
                        <tbody>
                            <?php foreach ($top3Roi as $r): ?>
                                <tr><td><span class="admin-cell-primary"><?php echo htmlspecialchars($r['name']); ?></span><span class="admin-cell-secondary">Highest ROI campaigns in range</span></td><td class="text-end"><?php echo number_format($r['leads']); ?></td><td class="text-end"><?php echo number_format($r['revenue'],2); ?></td><td class="text-end"><?php echo number_format($r['roi']*100,1); ?>%</td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($top3Roi)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div></div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="fw-semibold mb-2">Bottom 3</div>
                    <div class="admin-table-shell"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Campaign</th><th class="text-end">Leads</th><th class="text-end">Revenue</th><th class="text-end">ROI</th></tr></thead>
                        <tbody>
                            <?php foreach ($bottom3Roi as $r): ?>
                                <tr><td><span class="admin-cell-primary"><?php echo htmlspecialchars($r['name']); ?></span><span class="admin-cell-secondary">Lowest ROI campaigns in range</span></td><td class="text-end"><?php echo number_format($r['leads']); ?></td><td class="text-end"><?php echo number_format($r['revenue'],2); ?></td><td class="text-end"><?php echo number_format($r['roi']*100,1); ?>%</td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($bottom3Roi)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-bar-chart-line me-1"></i>Sales Department</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Open</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['open']); ?></div>
                            </div>
                            <div class="text-primary fs-2"><i class="bi bi-folder2-open"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Overdue</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['overdue']); ?></div>
                            </div>
                            <div class="text-warning fs-2"><i class="bi bi-alarm"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Closed Won</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['won']); ?></div>
                            </div>
                            <div class="text-success fs-2"><i class="bi bi-check2-all"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Closed Lost</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['lost']); ?></div>
                            </div>
                            <div class="text-danger fs-2"><i class="bi bi-x-octagon"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm admin-panel">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-clock-history me-1"></i>Recent Leads</div>
        <div class="card-body p-0">
            <div class="admin-table-shell">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Company</th>
                            <th>Email</th>
                            <th>Campaign</th>
                            <th>Agent</th>
                            <th>Status</th>
                            <th class="text-end">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLeads as $l): ?>
                            <?php
                                $qa = (string)($l['qa_status'] ?? '');
                                $del = (string)($l['client_delivery_status'] ?? '');
                                $status = $del === 'Delivered' ? 'Delivered' : ($qa !== '' ? $qa : 'Pending');
                                $badge = 'secondary';
                                if ($status === 'Qualified') $badge = 'success';
                                elseif ($status === 'Disqualified') $badge = 'danger';
                                elseif ($status === 'Delivered') $badge = 'primary';
                            ?>
                            <tr>
                                <td class="text-muted"><?php echo (int)($l['id'] ?? 0); ?></td>
                                <td><span class="admin-cell-primary"><?php echo htmlspecialchars((string)($l['company_name'] ?? '')); ?></span></td>
                                <td class="text-muted"><?php echo htmlspecialchars((string)($l['email'] ?? '')); ?></td>
                                <td><span class="admin-cell-primary"><?php echo htmlspecialchars((string)($l['campaign_name'] ?? '')); ?></span></td>
                                <td><span class="admin-cell-secondary"><?php echo htmlspecialchars((string)($l['agent_name'] ?? '')); ?></span></td>
                                <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                <td class="text-end text-muted small"><?php echo htmlspecialchars((string)($l['created_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentLeads)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No recent leads.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-table-note">Latest lead entries across the CRM with campaign, owner, and current QA or delivery state.</div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
