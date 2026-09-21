<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('campaigns.dashboard', ['admin','director','manager_director','sales_director','sales_manager','operations_director','operations_manager','qa_director','qa_manager','email_marketing_director','email_marketing_manager']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$canOpsCampaignScoreTable = isAdmin() || hasRole(['operations_manager', 'operations_director']);
$conn = getDbConnection();
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));

$range = strtolower(trim((string)($_GET['range'] ?? '')));
if (!in_array($range, ['today', 'week', 'month', 'overall', 'custom'], true)) {
    $range = (isset($_GET['date_from']) || isset($_GET['date_to'])) ? 'custom' : 'today';
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$todayDate = $opsNow->format('Y-m-d');
$weekStartDate = $opsNow->modify('monday this week')->format('Y-m-d');
$weekEndDate = $opsNow->modify('sunday this week')->format('Y-m-d');
$monthStartDate = $opsNow->format('Y-m-01');
$monthEndDate = $opsNow->format('Y-m-t');

if ($range === 'today') {
    $dateFrom = $todayDate;
    $dateTo = $todayDate;
} elseif ($range === 'week') {
    $dateFrom = $weekStartDate;
    $dateTo = $weekEndDate;
} elseif ($range === 'month') {
    $dateFrom = $monthStartDate;
    $dateTo = $monthEndDate;
} elseif ($range === 'overall') {
    $dateFrom = '';
    $dateTo = '';
}

$overviewRows = getCampaignOverviewByStatus(null, null, null);
$campaignMatchesRange = static function (array $row, ?string $from = null, ?string $to = null): bool {
    $start = trim((string)($row['start_date'] ?? ''));
    $end = trim((string)($row['end_date'] ?? ''));
    if ($from !== null && $from !== '' && $end !== '' && $end < $from) return false;
    if ($to !== null && $to !== '' && $start !== '' && $start > $to) return false;
    return true;
};
$overviewRows = array_values(array_filter($overviewRows, fn($row) => $campaignMatchesRange((array)$row, $dateFrom ?: null, $dateTo ?: null)));

if (isSDR()) {
    $assigned = getAssignedCampaignIdsForUser($userId);
    $overviewRows = array_values(array_filter($overviewRows, fn($r) => isset($assigned[(int)($r['id'] ?? 0)])));
}
if (isQA() && !isAdmin()) {
    $visible = getQaVisibleCampaignIdsForUser($userId, $role);
    if ($visible !== null) {
        $overviewRows = array_values(array_filter($overviewRows, fn($r) => isset($visible[(int)($r['id'] ?? 0)])));
        $tabCounts = array_fill_keys(['Draft','Active','Pause','Complete','Live','All'], 0);
        foreach ($overviewRows as $r) {
            $st = (string)($r['status'] ?? '');
            if ($st === 'Paused') $st = 'Pause';
            if ($st === 'Completed') $st = 'Complete';
            if (array_key_exists($st, $tabCounts)) {
                $tabCounts[$st] += 1;
                $tabCounts['All'] += 1;
            }
        }
    }
}

$tabCounts = ['Draft' => 0, 'Active' => 0, 'Live' => 0, 'Pause' => 0, 'Complete' => 0, 'All' => 0];
foreach ($overviewRows as $r) {
    $st = (string)($r['status'] ?? '');
    if ($st === 'Paused') $st = 'Pause';
    if ($st === 'Completed') $st = 'Complete';
    if (array_key_exists($st, $tabCounts)) {
        $tabCounts[$st] += 1;
        $tabCounts['All'] += 1;
    }
}

$campaignIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $overviewRows), fn($v) => $v > 0));
$campaignIdsByStatus = [
    'Live' => [],
    'Pause' => [],
    'Complete' => [],
    'Active' => [],
    'Draft' => [],
];
foreach ($overviewRows as $row) {
    $cid = (int)($row['id'] ?? 0);
    if ($cid <= 0) continue;
    $status = (string)($row['status'] ?? '');
    if ($status === 'Paused') $status = 'Pause';
    if ($status === 'Completed') $status = 'Complete';
    if (isset($campaignIdsByStatus[$status])) {
        $campaignIdsByStatus[$status][] = $cid;
    }
}

$buildLeadWhere = function(array $ids, ?string $from = null, ?string $to = null, string $alias = 'l'): array {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($ids)) {
        return ['where' => '1=0', 'params' => [], 'types' => ''];
    }
    $createdExpr = function_exists('leadCreatedAtExpr')
        ? leadCreatedAtExpr($alias)
        : ((trim($alias) !== '' ? (rtrim($alias, '.') . '.') : '') . 'created_at');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $where = ["{$alias}.campaign_id IN ($in)"];
    $params = $ids;
    $types = str_repeat('i', count($ids));
    if ($from !== null && $from !== '') {
        $where[] = "$createdExpr >= ?";
        $params[] = $from;
        $types .= 's';
    }
    if ($to !== null && $to !== '') {
        $where[] = "$createdExpr <= ?";
        $params[] = $to;
        $types .= 's';
    }
    return ['where' => implode(' AND ', $where), 'params' => $params, 'types' => $types];
};

$queryLeadStats = function(array $ids, ?string $from = null, ?string $to = null) use ($conn, $buildLeadWhere): array {
    $base = [
        'total' => 0,
        'qualified' => 0,
        'pending_qa' => 0,
        'reopened' => 0,
        'in_progress' => 0,
        'disqualified' => 0,
        'rework' => 0,
        'duplicate' => 0,
        'form_submitted' => 0,
        'pending_forms' => 0,
    ];
    $q = $buildLeadWhere($ids, $from, $to, 'l');
    $stmt = $conn->prepare("
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN l.qa_status = 'Reopened' THEN 1 ELSE 0 END) AS reopened,
          SUM(CASE WHEN l.qa_status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
          SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          SUM(CASE WHEN l.qa_status = 'Rework Needed' THEN 1 ELSE 0 END) AS rework,
          SUM(CASE WHEN l.qa_status IN ('Duplicate', 'Duplicate/Supp') THEN 1 ELSE 0 END) AS duplicate,
          SUM(CASE WHEN l.qa_status = 'Qualified' AND LOWER(TRIM(COALESCE(l.form_done, ''))) = 'yes' THEN 1 ELSE 0 END) AS form_submitted,
          SUM(CASE WHEN l.qa_status = 'Qualified' AND (l.form_done IS NULL OR TRIM(l.form_done) = '' OR LOWER(TRIM(l.form_done)) <> 'yes') THEN 1 ELSE 0 END) AS pending_forms,
          SUM(CASE WHEN l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status = 'Pending' THEN 1 ELSE 0 END) AS pending_qa
        FROM leads l
        WHERE {$q['where']}
    ");
    if (!$stmt) return $base;
    if ($q['types'] !== '') mysqliBindParams($stmt, $q['types'], $q['params']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($base as $k => $_) {
        $base[$k] = (int)($row[$k] ?? 0);
    }
    return $base;
};

$queryCampaignLeadRollup = function(array $ids, ?string $from = null, ?string $to = null) use ($conn, $buildLeadWhere): array {
    $delivered = [];
    $accepted = [];
    $pendingQa = [];
    $lastLeadAt = [];
    $qualified = [];
    if (empty($ids)) return [$delivered, $accepted, $pendingQa, $lastLeadAt, $qualified];

    $q = $buildLeadWhere($ids, $from, $to, 'l');
    $createdExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
    $stmt = $conn->prepare("
        SELECT
          l.campaign_id,
          SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
          SUM(CASE WHEN l.client_delivery_status = 'Accepted' THEN 1 ELSE 0 END) AS accepted,
          SUM(CASE WHEN l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status = 'Pending' THEN 1 ELSE 0 END) AS pending_qa,
          SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          MAX($createdExpr) AS last_lead_at
        FROM leads l
        WHERE {$q['where']}
        GROUP BY l.campaign_id
    ");
    if (!$stmt) return [$delivered, $accepted, $pendingQa, $lastLeadAt, $qualified];
    if ($q['types'] !== '') mysqliBindParams($stmt, $q['types'], $q['params']);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
        $cid = (int)($row['campaign_id'] ?? 0);
        if ($cid <= 0) continue;
        $delivered[$cid] = (int)($row['delivered'] ?? 0);
        $accepted[$cid] = (int)($row['accepted'] ?? 0);
        $pendingQa[$cid] = (int)($row['pending_qa'] ?? 0);
        $lastLeadAt[$cid] = $row['last_lead_at'] ?? null;
        $qualified[$cid] = (int)($row['qualified'] ?? 0);
    }
    $stmt->close();
    return [$delivered, $accepted, $pendingQa, $lastLeadAt, $qualified];
};

$queryCampaignScoreStats = function(array $ids, ?string $from = null, ?string $to = null) use ($conn, $buildLeadWhere): array {
    $rows = [];
    if (empty($ids)) return $rows;

    $q = $buildLeadWhere($ids, $from, $to, 'l');
    $stmt = $conn->prepare("
        SELECT
          l.campaign_id,
          COUNT(*) AS generated,
          SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          SUM(CASE WHEN l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status = 'Pending' THEN 1 ELSE 0 END) AS pending_qa
        FROM leads l
        WHERE {$q['where']}
        GROUP BY l.campaign_id
    ");
    if (!$stmt) return $rows;
    if ($q['types'] !== '') mysqliBindParams($stmt, $q['types'], $q['params']);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
        $cid = (int)($row['campaign_id'] ?? 0);
        if ($cid <= 0) continue;
        $rows[$cid] = [
            'generated' => (int)($row['generated'] ?? 0),
            'qualified' => (int)($row['qualified'] ?? 0),
            'disqualified' => (int)($row['disqualified'] ?? 0),
            'pending_qa' => (int)($row['pending_qa'] ?? 0),
        ];
    }
    $stmt->close();
    return $rows;
};

$selectedRangeUtc = ($dateFrom !== '' && $dateTo !== '' && function_exists('opsDateRangeUtc')) ? opsDateRangeUtc($dateFrom, $dateTo) : [];
$selectedFromDt = !empty($selectedRangeUtc['start']) ? (string)$selectedRangeUtc['start'] : null;
$selectedToDt = !empty($selectedRangeUtc['end']) ? (string)$selectedRangeUtc['end'] : null;
$todayUtc = function_exists('opsDateRangeUtc') ? opsDateRangeUtc($todayDate, $todayDate) : ['start' => $todayDate . ' 00:00:00', 'end' => $todayDate . ' 23:59:59'];
$weekUtc = function_exists('opsDateRangeUtc') ? opsDateRangeUtc($weekStartDate, $weekEndDate) : ['start' => $weekStartDate . ' 00:00:00', 'end' => $weekEndDate . ' 23:59:59'];
$monthUtc = function_exists('opsDateRangeUtc') ? opsDateRangeUtc($monthStartDate, $monthEndDate) : ['start' => $monthStartDate . ' 00:00:00', 'end' => $monthEndDate . ' 23:59:59'];
$todayStart = (string)($todayUtc['start'] ?? ($todayDate . ' 00:00:00'));
$todayEnd = (string)($todayUtc['end'] ?? ($todayDate . ' 23:59:59'));
$weekStart = (string)($weekUtc['start'] ?? ($weekStartDate . ' 00:00:00'));
$weekEnd = (string)($weekUtc['end'] ?? ($weekEndDate . ' 23:59:59'));
$monthStart = (string)($monthUtc['start'] ?? ($monthStartDate . ' 00:00:00'));
$monthEnd = (string)($monthUtc['end'] ?? ($monthEndDate . ' 23:59:59'));

[$deliveredByCampaign, $acceptedByCampaign, $pendingQaByCampaign, $lastLeadAtByCampaign, $qualifiedByCampaign] = $queryCampaignLeadRollup($campaignIds, $selectedFromDt, $selectedToDt);
$campaignScoreStatsById = $queryCampaignScoreStats($campaignIds, $selectedFromDt, $selectedToDt);
$selectedLeadStats = $queryLeadStats($campaignIds, $selectedFromDt, $selectedToDt);
$todayLeadStats = $queryLeadStats($campaignIds, $todayStart, $todayEnd);
$weekLeadStats = $queryLeadStats($campaignIds, $weekStart, $weekEnd);
$monthLeadStats = $queryLeadStats($campaignIds, $monthStart, $monthEnd);
$overallLeadStats = $queryLeadStats($campaignIds, null, null);

$liveLeadStats = $queryLeadStats($campaignIdsByStatus['Live'] ?? [], $selectedFromDt, $selectedToDt);
$pausedLeadStats = $queryLeadStats($campaignIdsByStatus['Pause'] ?? [], $selectedFromDt, $selectedToDt);
$completedLeadStats = $queryLeadStats($campaignIdsByStatus['Complete'] ?? [], $selectedFromDt, $selectedToDt);

// Agent login stats for today (operation agents only)
$agentLoginStats = ['total_present' => 0, 'on_time' => 0, 'late' => 0];
$todayDate = $opsNow->format('Y-m-d');
if (function_exists('getAttendanceDashboard')) {
    $allAttendance = getAttendanceDashboard($todayDate);
    foreach ($allAttendance as $attendance) {
        $role = (string)($attendance['role'] ?? '');
        // Only count operation agents
        if ($role !== 'operation' && $role !== 'Operation') {
            continue;
        }
        $status = (string)($attendance['status'] ?? '');
        $lateMinutes = (int)($attendance['late_minutes'] ?? 0);
        $isPresentLike = (int)($attendance['is_present_like'] ?? 0);
        if ($isPresentLike || $status === 'present' || $status === 'late') {
            $agentLoginStats['total_present']++;
            if ($lateMinutes > 0) {
                $agentLoginStats['late']++;
            } else {
                $agentLoginStats['on_time']++;
            }
        }
    }
}

$budgetTotals = [
    'allocation_leads' => 0,
    'delivered_leads' => 0,
    'pending_allocation_leads' => 0,
];
foreach ($overviewRows as $r) {
    $cid = (int)($r['id'] ?? 0);
    $allocation = (int)($r['total_leads'] ?? 0);
    $delivered = $cid > 0 ? (int)($deliveredByCampaign[$cid] ?? 0) : 0;
    $budgetTotals['allocation_leads'] += $allocation;
    $budgetTotals['delivered_leads'] += $delivered;
    $budgetTotals['pending_allocation_leads'] += max(0, $allocation - $delivered);
}

$campaignScoreRows = [];
$campaignScoreTotals = [
    'generated' => 0,
    'qualified' => 0,
    'disqualified' => 0,
    'pending_qa' => 0,
];
foreach ($overviewRows as $r) {
    $cid = (int)($r['id'] ?? 0);
    if ($cid <= 0) continue;
    $stats = $campaignScoreStatsById[$cid] ?? ['generated' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending_qa' => 0];
    $campaignScoreRows[] = [
        'id' => $cid,
        'name' => (string)($r['name'] ?? ''),
        'code' => (string)($r['code'] ?? ''),
        'client_code' => (string)($r['client_code'] ?? ''),
        'generated' => (int)($stats['generated'] ?? 0),
        'qualified' => (int)($stats['qualified'] ?? 0),
        'disqualified' => (int)($stats['disqualified'] ?? 0),
        'pending_qa' => (int)($stats['pending_qa'] ?? 0),
    ];
    $campaignScoreTotals['generated'] += (int)($stats['generated'] ?? 0);
    $campaignScoreTotals['qualified'] += (int)($stats['qualified'] ?? 0);
    $campaignScoreTotals['disqualified'] += (int)($stats['disqualified'] ?? 0);
    $campaignScoreTotals['pending_qa'] += (int)($stats['pending_qa'] ?? 0);
}
$campaignScoreRows = array_values(array_filter($campaignScoreRows, static function(array $row): bool {
    return (int)($row['generated'] ?? 0) > 0
        || (int)($row['qualified'] ?? 0) > 0
        || (int)($row['disqualified'] ?? 0) > 0
        || (int)($row['pending_qa'] ?? 0) > 0;
}));
usort($campaignScoreRows, static function(array $a, array $b): int {
    $byGenerated = ((int)($b['generated'] ?? 0)) <=> ((int)($a['generated'] ?? 0));
    if ($byGenerated !== 0) return $byGenerated;
    $byQualified = ((int)($b['qualified'] ?? 0)) <=> ((int)($a['qualified'] ?? 0));
    if ($byQualified !== 0) return $byQualified;
    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$countRows = [
    [
        'label' => 'Selected Range',
        'note' => 'Updates every dashboard card and table',
        'stats' => $selectedLeadStats,
    ],
    [
        'label' => "Today's Counts",
        'note' => 'Current day only',
        'stats' => $todayLeadStats,
    ],
    [
        'label' => "This Week's Counts",
        'note' => $weekStartDate . ' to ' . $weekEndDate,
        'stats' => $weekLeadStats,
    ],
    [
        'label' => "This Month's Counts",
        'note' => $opsNow->format('F Y'),
        'stats' => $monthLeadStats,
    ],
    [
        'label' => 'Overall Counts',
        'note' => ($dateFrom !== '' || $dateTo !== '') ? 'All visible campaign leads across all dates' : 'All visible campaign leads',
        'stats' => $overallLeadStats,
    ],
];

$riskCampaigns = [];
$riskAnchorDate = $dateTo !== '' ? $dateTo : $todayDate;
try {
    $riskAnchorStart = new DateTimeImmutable($riskAnchorDate . ' 00:00:00', opsBusinessTz());
    $riskAnchorEndSoon = new DateTimeImmutable($riskAnchorDate . ' 23:59:59', opsBusinessTz());
    $riskAnchorEndSoon = $riskAnchorEndSoon->modify('+3 days');
} catch (Throwable $e) {
    $riskAnchorStart = $opsNow->setTime(0, 0, 0);
    $riskAnchorEndSoon = $opsNow->setTime(23, 59, 59)->modify('+3 days');
}
$liveRowsAll = array_values(array_filter($overviewRows, fn($r) => ((string)($r['status'] ?? '')) === 'Live'));
foreach ($liveRowsAll as $r) {
    $end = trim((string)($r['end_date'] ?? ''));
    if ($end === '') continue;
    try {
        $endDt = new DateTimeImmutable($end . ' 23:59:59', opsBusinessTz());
    } catch (Throwable $e) {
        continue;
    }
    if ($endDt < $riskAnchorStart || $endDt > $riskAnchorEndSoon) continue;
    $cid = (int)($r['id'] ?? 0);
    $alloc = (int)($r['total_leads'] ?? 0);
    if ($alloc <= 0) continue;
    $del = (int)($deliveredByCampaign[$cid] ?? 0);
    $pct = $alloc > 0 ? (int)round(($del / $alloc) * 100) : 0;
    if ($pct >= 80) continue;
    $riskCampaigns[] = [
        'id' => $cid,
        'code' => (string)($r['code'] ?? ''),
        'end_date' => $end,
        'days_left' => max(0, (int)ceil(($endDt->getTimestamp() - $riskAnchorStart->getTimestamp()) / 86400)),
        'allocation' => $alloc,
        'delivered' => $del,
        'pacing_pct' => $pct,
        'pending_qa' => (int)($pendingQaByCampaign[$cid] ?? 0),
    ];
}
usort($riskCampaigns, function ($a, $b) {
    $byDays = ($a['days_left'] ?? 0) <=> ($b['days_left'] ?? 0);
    if ($byDays !== 0) return $byDays;
    return ($a['pacing_pct'] ?? 0) <=> ($b['pacing_pct'] ?? 0);
});
$riskCampaigns = array_slice($riskCampaigns, 0, 20);
$riskCount = count($riskCampaigns);

usort($liveRowsAll, fn($a, $b) => ((int)($b['total_leads'] ?? 0)) <=> ((int)($a['total_leads'] ?? 0)));
$top10Live = array_slice($liveRowsAll, 0, 10);
$top10Chart = ['labels' => [], 'allocation' => [], 'delivered' => [], 'pending' => []];
foreach ($top10Live as $r) {
    $cid = (int)($r['id'] ?? 0);
    $alloc = (int)($r['total_leads'] ?? 0);
    $del = (int)($deliveredByCampaign[$cid] ?? 0);
    $label = trim((string)($r['code'] ?? '')) !== '' ? trim((string)($r['code'] ?? '')) : trim((string)($r['name'] ?? ''));
    $top10Chart['labels'][] = $label;
    $top10Chart['allocation'][] = $alloc;
    $top10Chart['delivered'][] = $del;
    $top10Chart['pending'][] = max(0, $alloc - $del);
}

$clientRollups = [];
foreach ($overviewRows as $r) {
    $campId = (int)($r['id'] ?? 0);
    $clientId = (int)($r['client_id'] ?? 0);
    $clientCode = trim((string)($r['client_code'] ?? ''));
    $clientName = trim((string)($r['client_name'] ?? ''));
    $key = $clientId > 0 ? ('id:' . $clientId) : ($clientCode !== '' ? ('code:' . $clientCode) : 'no-client');
    if (!isset($clientRollups[$key])) {
        $clientRollups[$key] = [
            'client_code' => $clientCode,
            'client_name' => $clientName,
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
    $clientRollups[$key]['allocation_leads'] += (int)($r['total_leads'] ?? 0);
    $clientRollups[$key]['delivered_leads'] += (int)($deliveredByCampaign[$campId] ?? 0);
    $clientRollups[$key]['accepted_leads'] += (int)($acceptedByCampaign[$campId] ?? 0);
    $clientRollups[$key]['pending_qa'] += (int)($pendingQaByCampaign[$campId] ?? 0);
    $lastLeadAt = $lastLeadAtByCampaign[$campId] ?? null;
    $lastLeadAtDt = $lastLeadAt ? appParseUtc((string)$lastLeadAt) : null;
    $currentLastLeadDt = !empty($clientRollups[$key]['last_lead_at']) ? appParseUtc((string)$clientRollups[$key]['last_lead_at']) : null;
    if ($lastLeadAtDt && (!$currentLastLeadDt || $lastLeadAtDt > $currentLastLeadDt)) {
        $clientRollups[$key]['last_lead_at'] = $lastLeadAt;
    }
}
$clientRollupRows = array_values($clientRollups);
foreach ($clientRollupRows as &$row) {
    $row['pending_leads'] = max(0, (int)$row['allocation_leads'] - (int)$row['delivered_leads']);
    $row['pacing_pct'] = (int)$row['allocation_leads'] > 0
        ? min(100, (int)round(((int)$row['delivered_leads'] / max(1, (int)$row['allocation_leads'])) * 100))
        : 0;
}
unset($row);
usort($clientRollupRows, fn($a, $b) => (($b['pending_leads'] ?? 0) <=> ($a['pending_leads'] ?? 0)));
$clientRollupRows = array_slice($clientRollupRows, 0, 25);

// Client Live Campaign Summary logic (similar to QA dashboard)
$clientLiveSummaryRows = [];
$clientLiveSummaryTotals = [
    'live_campaigns' => 0,
    'total_allocation' => 0,
    'generated_leads' => 0,
    'qualified_leads' => 0,
    'disqualified_leads' => 0,
    'new_leads' => 0,
    'delivered' => 0,
    'pending' => 0,
];
$clientCampaignDetails = [];
$liveCampaignRows = [];
$stmt = $conn->prepare("
    SELECT c.id, c.name, d.client_id, d.client_code, d.code AS campaign_code, d.total_leads
    FROM campaigns c
    JOIN campaign_details d ON d.campaign_id = c.id
    WHERE d.status = 'Live'
    ORDER BY COALESCE(NULLIF(TRIM(d.client_code), ''), 'ZZZ'), c.name ASC
");
if ($stmt) {
    $stmt->execute();
    $liveCampaignRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}
$liveCampaignIds = array_values(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $liveCampaignRows), static fn($id): bool => $id > 0));
$leadMetricsByCampaign = [];
if (!empty($liveCampaignIds)) {
    $in = implode(',', array_fill(0, count($liveCampaignIds), '?'));
    $createdExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
    $stmt = $conn->prepare("
        SELECT l.campaign_id,
               SUM(CASE WHEN $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS generated_leads,
               SUM(CASE WHEN l.qa_status = 'Qualified' AND $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS qualified_leads,
               SUM(CASE WHEN l.qa_status = 'Disqualified' AND $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS disqualified_leads,
               SUM(CASE WHEN l.qa_status = 'Qualified' AND $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS new_leads,
               SUM(CASE WHEN l.client_delivery_status = 'Delivered' AND $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS delivered,
               SUM(CASE WHEN l.qa_status = 'Qualified' AND l.client_delivery_status = 'Pending' AND $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS pending
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE l.campaign_id IN ($in)
          AND (d.start_date IS NULL OR DATE($createdExpr) >= d.start_date)
          AND (d.end_date IS NULL OR DATE($createdExpr) <= d.end_date)
        GROUP BY l.campaign_id
    ");
    if ($stmt) {
        $types = 'ssssssssssss' . str_repeat('i', count($liveCampaignIds));
        $params = [$selectedFromDt, $selectedToDt, $selectedFromDt, $selectedToDt, $selectedFromDt, $selectedToDt, $selectedFromDt, $selectedToDt, $selectedFromDt, $selectedToDt, $selectedFromDt, $selectedToDt, ...$liveCampaignIds];
        mysqliBindParams($stmt, $types, $params);
        $stmt->execute();
        $metricRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($metricRows as $metricRow) {
            $leadMetricsByCampaign[(int)($metricRow['campaign_id'] ?? 0)] = $metricRow;
        }
    }
}
$clientBuckets = [];
foreach ($liveCampaignRows as $campaignRow) {
    $campaignId = (int)($campaignRow['id'] ?? 0);
    if ($campaignId <= 0) continue;
    $clientId = (int)($campaignRow['client_id'] ?? 0);
    $clientCode = trim((string)($campaignRow['client_code'] ?? ''));
    $bucketKey = $clientId > 0 ? ('id:' . $clientId) : ($clientCode !== '' ? ('code:' . $clientCode) : ('campaign:' . $campaignId));
    if (!isset($clientBuckets[$bucketKey])) {
        $clientBuckets[$bucketKey] = [
            'client_code' => $clientCode !== '' ? $clientCode : 'No Client',
            'live_campaigns' => 0,
            'total_allocation' => 0,
            'generated_leads' => 0,
            'qualified_leads' => 0,
            'disqualified_leads' => 0,
            'new_leads' => 0,
            'delivered' => 0,
            'pending' => 0,
            'campaigns' => [],
        ];
    }
    $allocation = (int)($campaignRow['total_leads'] ?? 0);
    $campaignMetrics = (array)($leadMetricsByCampaign[$campaignId] ?? []);
    $generatedLeads = (int)($campaignMetrics['generated_leads'] ?? 0);
    $qualifiedLeads = (int)($campaignMetrics['qualified_leads'] ?? 0);
    $disqualifiedLeads = (int)($campaignMetrics['disqualified_leads'] ?? 0);
    $newLeads = (int)($campaignMetrics['new_leads'] ?? 0);
    $delivered = (int)($campaignMetrics['delivered'] ?? 0);
    $pending = (int)($campaignMetrics['pending'] ?? 0);
    $clientBuckets[$bucketKey]['live_campaigns'] += 1;
    $clientBuckets[$bucketKey]['total_allocation'] += $allocation;
    $clientBuckets[$bucketKey]['generated_leads'] += $generatedLeads;
    $clientBuckets[$bucketKey]['qualified_leads'] += $qualifiedLeads;
    $clientBuckets[$bucketKey]['disqualified_leads'] += $disqualifiedLeads;
    $clientBuckets[$bucketKey]['new_leads'] += $newLeads;
    $clientBuckets[$bucketKey]['delivered'] += $delivered;
    $clientBuckets[$bucketKey]['pending'] += $pending;
    $clientBuckets[$bucketKey]['campaigns'][] = [
        'campaign_id' => $campaignId,
        'campaign_name' => (string)($campaignRow['name'] ?? ''),
        'campaign_code' => trim((string)($campaignRow['campaign_code'] ?? '')),
        'allocation' => $allocation,
        'generated_leads' => $generatedLeads,
        'qualified_leads' => $qualifiedLeads,
        'disqualified_leads' => $disqualifiedLeads,
        'new_leads' => $newLeads,
        'delivered' => $delivered,
        'pending' => $pending,
    ];
}
$clientLiveSummaryRows = array_values($clientBuckets);
usort($clientLiveSummaryRows, static function (array $a, array $b): int {
    $byCode = strcasecmp((string)($a['client_code'] ?? ''), (string)($b['client_code'] ?? ''));
    if ($byCode !== 0) return $byCode;
    return ((int)($b['live_campaigns'] ?? 0)) <=> ((int)($a['live_campaigns'] ?? 0));
});
foreach ($clientLiveSummaryRows as $clientSummaryRow) {
    $clientLiveSummaryTotals['live_campaigns'] += (int)($clientSummaryRow['live_campaigns'] ?? 0);
    $clientLiveSummaryTotals['total_allocation'] += (int)($clientSummaryRow['total_allocation'] ?? 0);
    $clientLiveSummaryTotals['generated_leads'] += (int)($clientSummaryRow['generated_leads'] ?? 0);
    $clientLiveSummaryTotals['qualified_leads'] += (int)($clientSummaryRow['qualified_leads'] ?? 0);
    $clientLiveSummaryTotals['disqualified_leads'] += (int)($clientSummaryRow['disqualified_leads'] ?? 0);
    $clientLiveSummaryTotals['new_leads'] += (int)($clientSummaryRow['new_leads'] ?? 0);
    $clientLiveSummaryTotals['delivered'] += (int)($clientSummaryRow['delivered'] ?? 0);
    $clientLiveSummaryTotals['pending'] += (int)($clientSummaryRow['pending'] ?? 0);
}

$agentPerformanceRows = [];
$agentClientDetails = [];
$liveCampaignIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $liveRowsAll), fn($v) => $v > 0));
if (!empty($liveCampaignIds)) {
    $q = $buildLeadWhere($liveCampaignIds, $selectedFromDt, $selectedToDt, 'l');
    $stmt = $conn->prepare("
        SELECT
          l.agent_id,
          COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(l.agent_name), '')) AS agent_name,
          u.role,
          u.job_title,
          COUNT(*) AS total,
          SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status = 'Pending' THEN 1 ELSE 0 END) AS pending,
          SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          ROUND((SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 1) AS performance_pct
        FROM leads l
        LEFT JOIN users u ON u.id = l.agent_id
        WHERE {$q['where']}
        GROUP BY l.agent_id, agent_name, u.role, u.job_title
        ORDER BY performance_pct DESC, qualified DESC, total DESC
        LIMIT 20
    ");
    if ($stmt) {
        if ($q['types'] !== '') mysqliBindParams($stmt, $q['types'], $q['params']);
        $stmt->execute();
        $agentPerformanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    // Fetch client code-wise details for each agent
    $agentIds = array_values(array_filter(array_map(fn($r) => (int)($r['agent_id'] ?? 0), $agentPerformanceRows), fn($v) => $v > 0));
    if (!empty($agentIds)) {
        $in = implode(',', array_fill(0, count($agentIds), '?'));
        $stmt = $conn->prepare("
            SELECT
              l.agent_id,
              d.client_code,
              COUNT(*) AS generated,
              SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
              SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
            FROM leads l
            JOIN campaign_details d ON d.campaign_id = l.campaign_id
            WHERE l.agent_id IN ($in) AND {$q['where']}
            GROUP BY l.agent_id, d.client_code
            ORDER BY l.agent_id, d.client_code
        ");
        if ($stmt) {
            $types = $q['types'] . str_repeat('i', count($agentIds));
            $params = array_merge($q['params'], $agentIds);
            mysqliBindParams($stmt, $types, $params);
            $stmt->execute();
            $clientRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($clientRows as $row) {
                $agentId = (int)($row['agent_id'] ?? 0);
                if ($agentId > 0) {
                    $agentClientDetails[$agentId][] = $row;
                }
            }
        }
    }
}

$topPerformers = [];
$bottomPerformers = [];
if (function_exists('funneltrackGetConfig') && function_exists('funneltrackComputeAgentRangeSummaries') && function_exists('getInternalPayrollUsers')) {
    try {
        $cfg = funneltrackGetConfig();
        $visibleRangeStart = null;
        $visibleRangeEnd = null;
        foreach ($overviewRows as $r) {
            $start = trim((string)($r['start_date'] ?? ''));
            $end = trim((string)($r['end_date'] ?? ''));
            if ($start !== '' && ($visibleRangeStart === null || $start < $visibleRangeStart)) $visibleRangeStart = $start;
            if ($end !== '' && ($visibleRangeEnd === null || $end > $visibleRangeEnd)) $visibleRangeEnd = $end;
        }
        $monthRangeStart = $dateFrom !== '' ? $dateFrom : ($visibleRangeStart ?: $monthStartDate);
        $monthRangeEnd = $dateTo !== '' ? $dateTo : ($visibleRangeEnd ?: $todayDate);
        if ($monthRangeEnd > $todayDate) $monthRangeEnd = $todayDate;
        $allowedRoles = ['agent','operations_agent','email_marketing_agent','email_marketing_executive','form_filler'];
        $performerRows = [];
        $payrollUsers = getInternalPayrollUsers();
        $scopedAgentIds = [];
        foreach ($agentPerformanceRows as $agentPerformanceRow) {
            $aid = (int)($agentPerformanceRow['agent_id'] ?? 0);
            if ($aid > 0) $scopedAgentIds[$aid] = true;
        }
        $performerIds = [];
        foreach ($payrollUsers as $u) {
            $aid = (int)($u['id'] ?? 0);
            $uRole = strtolower((string)($u['role'] ?? ''));
            if ($aid <= 0 || !in_array($uRole, $allowedRoles, true) || !isset($scopedAgentIds[$aid])) continue;
            $performerIds[] = $aid;
        }
        $summaries = !empty($performerIds) ? funneltrackComputeAgentRangeSummaries($performerIds, $monthRangeStart, $monthRangeEnd, $cfg) : [];
        foreach ($payrollUsers as $u) {
            $aid = (int)($u['id'] ?? 0);
            $uRole = strtolower((string)($u['role'] ?? ''));
            if ($aid <= 0 || !in_array($uRole, $allowedRoles, true) || !isset($scopedAgentIds[$aid])) continue;
            $summary = $summaries[$aid] ?? [];
            $performerRows[] = [
                'user_id' => $aid,
                'name' => (string)($u['full_name'] ?? ('User #' . $aid)),
                'role' => (string)($u['role'] ?? ''),
                'job_title' => (string)($u['job_title'] ?? ''),
                'rank' => (string)($summary['rank'] ?? ''),
                'productivity_pct' => (float)($summary['productivity_pct'] ?? 0.0),
                'earned_incentive' => ((int)($summary['incentives']['range_total'] ?? 0) > 0) ? 'Yes' : 'No',
                'amount' => (int)($summary['incentives']['range_total'] ?? 0),
            ];
        }
        usort($performerRows, fn($a, $b) => (($b['productivity_pct'] ?? 0.0) <=> ($a['productivity_pct'] ?? 0.0)) ?: (($b['amount'] ?? 0) <=> ($a['amount'] ?? 0)));
        $topPerformers = array_slice($performerRows, 0, 3);
        $bottomSorted = $performerRows;
        usort($bottomSorted, fn($a, $b) => (($a['productivity_pct'] ?? 0.0) <=> ($b['productivity_pct'] ?? 0.0)) ?: (($a['amount'] ?? 0) <=> ($b['amount'] ?? 0)));
        $bottomPerformers = array_slice($bottomSorted, 0, 3);
    } catch (Throwable $e) {
        $topPerformers = [];
        $bottomPerformers = [];
    }
}

$selectedRangeLabel = 'All visible data';
$selectedRangeMeta = 'Overall';
if ($range === 'today') {
    $selectedRangeLabel = 'Today';
    $selectedRangeMeta = $todayDate;
} elseif ($range === 'week') {
    $selectedRangeLabel = 'This Week';
    $selectedRangeMeta = $weekStartDate . ' to ' . $weekEndDate;
} elseif ($range === 'month') {
    $selectedRangeLabel = 'This Month';
    $selectedRangeMeta = $opsNow->format('F Y');
} elseif ($range === 'custom') {
    if ($dateFrom !== '' && $dateTo !== '') {
        $selectedRangeLabel = 'Custom Range';
        $selectedRangeMeta = $dateFrom . ' to ' . $dateTo;
    } elseif ($dateFrom !== '') {
        $selectedRangeLabel = 'Custom Range';
        $selectedRangeMeta = 'From ' . $dateFrom;
    } elseif ($dateTo !== '') {
        $selectedRangeLabel = 'Custom Range';
        $selectedRangeMeta = 'Up to ' . $dateTo;
    } else {
        $selectedRangeLabel = 'Custom Range';
        $selectedRangeMeta = 'No custom dates applied';
    }
}

$pageTitle = 'Campaign Dashboard';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<style>
  .campaign-dash-card {
    border: 1px solid rgba(37, 99, 235, 0.12);
    box-shadow: 0 18px 42px -34px rgba(37, 99, 235, 0.24);
    border-radius: 1.15rem;
    background: var(--app-surface);
    color: var(--app-text);
  }
  .campaign-dash-hero {
    background: linear-gradient(135deg, rgba(37,99,235,.98) 0%, #1d4ed8 58%, #0f172a 100%);
    color: #fff;
  }
  .campaign-dash-hero .text-muted,
  .campaign-dash-hero .small {
    color: rgba(255,255,255,.78) !important;
  }
  .campaign-metric-chip {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 999px;
    padding: .4rem .7rem;
    font-size: .8rem;
  }
  .campaign-stat-card {
    background: linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(248,250,252,.96) 100%);
    border: 1px solid rgba(37, 99, 235, 0.12);
    border-radius: 1.05rem;
    box-shadow: 0 16px 40px -34px rgba(37, 99, 235, 0.22);
  }
  .campaign-stat-label {
    color: #64748b;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .campaign-stat-value {
    font-size: 1.6rem;
    font-weight: 700;
    line-height: 1.15;
  }
  .campaign-kv {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .9rem 1.2rem;
  }
  .campaign-kv .kv-item {
    padding-bottom: .65rem;
    border-bottom: 1px dashed rgba(148, 163, 184, 0.4);
  }
  .campaign-kv .kv-key {
    color: #64748b;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .campaign-kv .kv-value {
    font-size: 1.15rem;
    font-weight: 700;
  }
  .campaign-soft-panel {
    background: linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(248,250,252,.96) 100%);
    border: 1px solid rgba(37, 99, 235, .12);
    border-radius: 1rem;
  }
  .campaign-dash-filter-card {
    border: 1px solid rgba(37, 99, 235, 0.12);
    border-radius: 1.1rem;
    background: linear-gradient(180deg, rgba(255,255,255,.96) 0%, rgba(248,250,252,.94) 100%);
  }
  .campaign-range-switch {
    display: flex;
    align-items: center;
    gap: .55rem;
    flex-wrap: wrap;
  }
  .campaign-range-switch .btn {
    border-radius: 999px;
    padding-inline: .95rem;
  }
  .campaign-custom-fields {
    padding-top: .25rem;
  }
  .campaign-selected-range-note {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .45rem .72rem;
    border-radius: 999px;
    border: 1px solid rgba(37, 99, 235, .14);
    background: rgba(37,99,235,.06);
    font-size: .8rem;
    color: var(--app-muted);
  }
  .campaign-dark-table thead.table-light th {
    background: rgba(15,23,42,.05);
  }
  [data-bs-theme="dark"] .campaign-dash-card {
    border-color: rgba(34,211,238,.16);
    background: linear-gradient(180deg, rgba(8,15,28,.98) 0%, rgba(11,20,36,.96) 100%);
    box-shadow: 0 24px 44px -36px rgba(34,211,238,.18);
  }
  [data-bs-theme="dark"] .campaign-stat-card,
  [data-bs-theme="dark"] .campaign-soft-panel,
  [data-bs-theme="dark"] .campaign-dash-filter-card {
    background: linear-gradient(180deg, rgba(8,15,28,.98) 0%, rgba(11,20,36,.96) 100%);
    border-color: rgba(34,211,238,.16);
    color: var(--app-text);
  }
  [data-bs-theme="dark"] .campaign-stat-label,
  [data-bs-theme="dark"] .campaign-kv .kv-key,
  [data-bs-theme="dark"] .campaign-selected-range-note,
  [data-bs-theme="dark"] .campaign-dash-card .text-muted,
  [data-bs-theme="dark"] .campaign-dash-card .small.text-muted {
    color: rgba(226,232,240,.72) !important;
  }
  [data-bs-theme="dark"] .campaign-kv .kv-item {
    border-bottom-color: rgba(148,163,184,.24);
  }
  [data-bs-theme="dark"] .campaign-dark-table thead.table-light th,
  [data-bs-theme="dark"] .campaign-dark-table.table > :not(caption) > * > * {
    background-color: transparent;
    color: var(--app-text);
    border-color: var(--app-border);
  }
  [data-bs-theme="dark"] .campaign-dark-table thead.table-light th {
    background: rgba(255,255,255,.04);
  }
  [data-bs-theme="dark"] .campaign-dark-table tbody tr:hover > * {
    background: rgba(34,211,238,.06);
  }
  [data-bs-theme="dark"] .campaign-dash-card .btn-light {
    background: rgba(255,255,255,.04);
    border-color: rgba(255,255,255,.12);
    color: var(--app-text);
  }
  [data-bs-theme="dark"] .campaign-dash-card .progress {
    background: rgba(255,255,255,.08);
  }
  .campaign-count-table thead th {
    white-space: nowrap;
  }
  .campaign-count-table .row-title {
    min-width: 180px;
  }
  @media (max-width: 1199.98px) {
    .campaign-dash-hero .card-body,
    .campaign-dash-filter-card .card-body,
    .campaign-stat-card.p-4,
    .campaign-panel .card-body {
      padding: 1.15rem !important;
    }
    .campaign-kv {
      gap: .8rem 1rem;
    }
    .campaign-range-switch .btn {
      min-width: 88px;
    }
    .campaign-soft-panel.p-3 {
      padding: .9rem !important;
    }
  }
  @media (max-width: 991.98px) {
    .campaign-dash-hero .card-body,
    .campaign-dash-filter-card .card-body,
    .campaign-stat-card.p-4,
    .campaign-panel .card-body {
      padding: 1rem !important;
    }
    .campaign-metric-chip {
      width: 100%;
      justify-content: center;
      text-align: center;
    }
    .campaign-range-switch {
      gap: .45rem;
    }
    .campaign-range-switch .btn {
      flex: 1 1 calc(33.333% - .5rem);
    }
    .campaign-selected-range-note {
      width: 100%;
      justify-content: center;
      text-align: center;
    }
    .campaign-custom-fields {
      padding-top: 0;
    }
    .campaign-custom-fields.d-flex {
      flex-wrap: wrap;
    }
    .campaign-custom-fields.d-flex .btn {
      flex: 1 1 calc(50% - .4rem);
      justify-content: center;
    }
    .campaign-kv {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .campaign-panel .card-header,
    .campaign-stat-card .card-header {
      padding: 1rem 1rem 0 !important;
      gap: .6rem;
      flex-wrap: wrap;
    }
    .campaign-workspace .table-responsive {
      border-radius: .95rem;
    }
    .campaign-workspace canvas {
      max-height: 250px;
    }
  }
  @media (max-width: 575.98px) {
    .campaign-dash-hero .card-body,
    .campaign-dash-filter-card .card-body,
    .campaign-stat-card.p-4,
    .campaign-panel .card-body {
      padding: .9rem !important;
    }
    .campaign-range-switch .btn,
    .campaign-custom-fields.d-flex .btn {
      flex: 1 1 100%;
    }
    .campaign-kv {
      grid-template-columns: 1fr;
      gap: .65rem;
    }
    .campaign-stat-value {
      font-size: 1.35rem;
    }
    .campaign-panel .card-header,
    .campaign-stat-card .card-header {
      padding: .95rem .95rem 0 !important;
    }
    .campaign-count-table .row-title {
      min-width: 150px;
    }
    .campaign-workspace .table-responsive {
      font-size: .83rem;
    }
  }
</style>

<div class="container-fluid px-0 campaign-workspace">
  <div class="card campaign-dash-card campaign-dash-hero mb-3">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h3 class="mb-1">Campaign Dashboard</h3>
          <div class="small">Live campaign visibility, QA movement, form submission tracking, risk monitoring, and agent performance in one place.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <span class="campaign-metric-chip"><i class="bi bi-calendar3 me-1"></i><?php echo htmlspecialchars($selectedRangeLabel); ?>: <?php echo htmlspecialchars($selectedRangeMeta); ?></span>
          <span class="campaign-metric-chip"><i class="bi bi-broadcast me-1"></i>Live Campaigns: <?php echo number_format((int)($tabCounts['Live'] ?? 0)); ?></span>
          <span class="campaign-metric-chip"><i class="bi bi-exclamation-triangle me-1"></i>Ending Soon Risk: <?php echo number_format($riskCount); ?></span>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3 flex-wrap">
        <a class="btn btn-light btn-sm" href="list"><i class="bi bi-list-ul me-1"></i>Manage Campaigns</a>
        <?php if (isAdmin() || isSalesDirector() || isSalesManager() || hasRole(['director','manager_director','operations_director'])): ?>
          <a class="btn btn-warning btn-sm" href="create"><i class="bi bi-plus-circle me-1"></i>Create Campaign</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card campaign-dash-filter-card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12">
          <div class="campaign-range-switch">
            <button class="btn btn-sm <?php echo $range === 'today' ? 'btn-primary' : 'btn-outline-primary'; ?>" type="submit" name="range" value="today">Today</button>
            <button class="btn btn-sm <?php echo $range === 'week' ? 'btn-primary' : 'btn-outline-primary'; ?>" type="submit" name="range" value="week">Week</button>
            <button class="btn btn-sm <?php echo $range === 'month' ? 'btn-primary' : 'btn-outline-primary'; ?>" type="submit" name="range" value="month">Month</button>
            <button class="btn btn-sm <?php echo $range === 'overall' ? 'btn-primary' : 'btn-outline-primary'; ?>" type="submit" name="range" value="overall">Overall</button>
            <button class="btn btn-sm <?php echo $range === 'custom' ? 'btn-primary' : 'btn-outline-primary'; ?>" type="submit" name="range" value="custom">Custom</button>
            <span class="campaign-selected-range-note"><i class="bi bi-clock-history"></i><?php echo htmlspecialchars($selectedRangeLabel . ' · ' . $selectedRangeMeta); ?></span>
          </div>
        </div>
        <div class="col-md-4 campaign-custom-fields">
          <label class="form-label">From</label>
          <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-md-4 campaign-custom-fields">
          <label class="form-label">To</label>
          <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-md-4 d-flex gap-2 campaign-custom-fields">
          <button class="btn btn-primary btn-sm" type="submit" name="range" value="custom"><i class="bi bi-funnel me-1"></i>Apply Custom Filter</button>
          <a class="btn btn-outline-secondary btn-sm" href="dashboard">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-7">
      <div class="campaign-stat-card h-100 p-4">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
          <div>
            <div class="campaign-stat-label">Live</div>
            <div class="small text-muted">Current live campaign workload in the selected dashboard view.</div>
          </div>
          <span class="badge text-bg-success">Status: Active</span>
        </div>
        <div class="campaign-kv">
          <div class="kv-item">
            <div class="kv-key">Total Leads</div>
            <div class="kv-value"><?php echo number_format((int)($liveLeadStats['total'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Risk</div>
            <div class="kv-value"><?php echo number_format($riskCount); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Qualified</div>
            <div class="kv-value text-success"><?php echo number_format((int)($liveLeadStats['qualified'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">In Progress</div>
            <div class="kv-value"><?php echo number_format((int)($liveLeadStats['in_progress'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Disqualified</div>
            <div class="kv-value text-danger"><?php echo number_format((int)($liveLeadStats['disqualified'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Pending QA</div>
            <div class="kv-value"><?php echo number_format((int)($liveLeadStats['pending_qa'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Rework</div>
            <div class="kv-value"><?php echo number_format((int)($liveLeadStats['rework'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Total Present</div>
            <div class="kv-value text-primary"><?php echo number_format((int)($agentLoginStats['total_present'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Agents On Time</div>
            <div class="kv-value text-success"><?php echo number_format((int)($agentLoginStats['on_time'] ?? 0)); ?></div>
          </div>
          <div class="kv-item">
            <div class="kv-key">Agents Late</div>
            <div class="kv-value text-danger"><?php echo number_format((int)($agentLoginStats['late'] ?? 0)); ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="campaign-stat-card h-100 p-4">
        <div class="campaign-stat-label mb-2">Paused & Completed Leads</div>
        <div class="small text-muted mb-3">Lead volume sitting in paused and completed campaigns for the current dashboard view.</div>
        <div class="row g-3">
          <div class="col-6">
            <div class="campaign-soft-panel p-3 h-100">
              <div class="campaign-stat-label">Paused</div>
              <div class="campaign-stat-value text-warning"><?php echo number_format((int)($pausedLeadStats['total'] ?? 0)); ?></div>
              <div class="small text-muted">Campaigns: <?php echo number_format(count($campaignIdsByStatus['Pause'] ?? [])); ?></div>
            </div>
          </div>
          <div class="col-6">
            <div class="campaign-soft-panel p-3 h-100">
              <div class="campaign-stat-label">Completed</div>
              <div class="campaign-stat-value text-secondary"><?php echo number_format((int)($completedLeadStats['total'] ?? 0)); ?></div>
              <div class="small text-muted">Campaigns: <?php echo number_format(count($campaignIdsByStatus['Complete'] ?? [])); ?></div>
            </div>
          </div>
          <div class="col-12">
            <div class="campaign-soft-panel p-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="campaign-stat-label">Lead Volume & Ending Soon</div>
                  <div class="small text-muted">Rechecked against live campaign pacing and dashboard filters.</div>
                </div>
                <div class="text-end">
                  <div class="fw-semibold"><?php echo number_format((int)$selectedLeadStats['total']); ?> in selected range</div>
                  <div class="small text-muted"><?php echo number_format($riskCount); ?> campaigns at risk</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-8">
      <div class="campaign-stat-card h-100">
        <div class="card-header border-0 pt-4 px-4">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="campaign-stat-label">Lead Count Rows</div>
                  <div class="small text-muted">Selected range, today, week, month, and overall counts shown together for comparison.</div>
            </div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 campaign-count-table campaign-dark-table">
            <thead class="table-light">
              <tr>
                <th class="row-title">Row</th>
                <th class="text-end">Total Leads</th>
                <th class="text-end">Qualified</th>
                <th class="text-end">Pending QA</th>
                <th class="text-end">Reopened</th>
                <th class="text-end">In Progress</th>
                <th class="text-end">Disqualified</th>
                <th class="text-end">Form Submitted</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($countRows as $row): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?php echo htmlspecialchars((string)$row['label']); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars((string)$row['note']); ?></div>
                  </td>
                  <td class="text-end font-monospace fw-semibold"><?php echo number_format((int)($row['stats']['total'] ?? 0)); ?></td>
                  <td class="text-end font-monospace text-success"><?php echo number_format((int)($row['stats']['qualified'] ?? 0)); ?></td>
                  <td class="text-end font-monospace text-warning"><?php echo number_format((int)($row['stats']['pending_qa'] ?? 0)); ?></td>
                  <td class="text-end font-monospace"><?php echo number_format((int)($row['stats']['reopened'] ?? 0)); ?></td>
                  <td class="text-end font-monospace"><?php echo number_format((int)($row['stats']['in_progress'] ?? 0)); ?></td>
                  <td class="text-end font-monospace text-danger"><?php echo number_format((int)($row['stats']['disqualified'] ?? 0)); ?></td>
                  <td class="text-end">
                    <div class="font-monospace fw-semibold"><?php echo number_format((int)($row['stats']['form_submitted'] ?? 0)); ?></div>
                    <div class="small text-muted">Pending: <?php echo number_format((int)($row['stats']['pending_forms'] ?? 0)); ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="campaign-stat-card h-100 p-4">
        <div class="campaign-stat-label">Form Submitted</div>
        <div class="small text-muted mb-3">Only qualified leads are included in this section.</div>
        <div class="campaign-soft-panel p-3 mb-3">
          <div class="campaign-stat-label">Submitted</div>
          <div class="campaign-stat-value text-primary"><?php echo number_format((int)($selectedLeadStats['form_submitted'] ?? 0)); ?></div>
          <div class="small text-muted">Qualified leads with forms submitted</div>
        </div>
        <div class="campaign-soft-panel p-3">
          <div class="campaign-stat-label">Pending Form Submissions</div>
          <div class="campaign-stat-value text-warning"><?php echo number_format((int)($selectedLeadStats['pending_forms'] ?? 0)); ?></div>
          <div class="small text-muted">Qualified leads still waiting on form completion</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-12">
      <div class="card campaign-dash-card campaign-panel h-100">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-building me-1"></i>Client Live Campaign Summary (<?php echo htmlspecialchars($selectedRangeLabel); ?>)</span>
          <span class="text-muted small">Filtered by selected range</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
            <thead class="table-light">
              <tr>
                <th>Client Code</th>
                <th class="text-center">Live</th>
                <th class="text-center">Alloc</th>
                <th class="text-center">Generated</th>
                <th class="text-center">Qualified</th>
                <th class="text-center">Disqual</th>
                <th class="text-center">New</th>
                <th class="text-center">Delivered</th>
                <th class="text-center">Pending</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($clientLiveSummaryRows)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No live client campaign data in the current view.</td></tr>
              <?php else: ?>
                <?php foreach ($clientLiveSummaryRows as $index => $clientSummaryRow): ?>
                  <?php
                    $clientCode = (string)($clientSummaryRow['client_code'] ?? 'No Client');
                    $campaigns = $clientSummaryRow['campaigns'] ?? [];
                    $rowId = 'client-details-' . md5($clientCode . $index);
                  ?>
                  <tr>
                    <td class="fw-semibold">
                      <a class="text-decoration-none client-toggle" href="javascript:void(0)" data-row-id="<?php echo $rowId; ?>">
                        <i class="bi bi-chevron-right me-1 toggle-icon"></i><?php echo htmlspecialchars($clientCode); ?>
                      </a>
                    </td>
                    <td class="text-center font-monospace"><?php echo number_format((int)($clientSummaryRow['live_campaigns'] ?? 0)); ?></td>
                    <td class="text-center font-monospace"><?php echo number_format((int)($clientSummaryRow['total_allocation'] ?? 0)); ?></td>
                    <td class="text-center font-monospace"><?php echo number_format((int)($clientSummaryRow['generated_leads'] ?? 0)); ?></td>
                    <td class="text-center font-monospace text-success"><?php echo number_format((int)($clientSummaryRow['qualified_leads'] ?? 0)); ?></td>
                    <td class="text-center font-monospace text-danger"><?php echo number_format((int)($clientSummaryRow['disqualified_leads'] ?? 0)); ?></td>
                    <td class="text-center font-monospace text-success"><?php echo number_format((int)($clientSummaryRow['new_leads'] ?? 0)); ?></td>
                    <td class="text-center font-monospace"><?php echo number_format((int)($clientSummaryRow['delivered'] ?? 0)); ?></td>
                    <td class="text-center font-monospace text-warning"><?php echo number_format((int)($clientSummaryRow['pending'] ?? 0)); ?></td>
                  </tr>
                  <tr class="client-details-row" id="<?php echo $rowId; ?>" style="display: none;">
                    <td colspan="9" class="p-0">
                      <div class="p-3 bg-light border-top">
                        <div class="fw-semibold mb-2">Campaign-wise Details</div>
                        <table class="table table-sm table-bordered mb-0">
                          <thead class="table-light">
                            <tr>
                              <th>Campaign Code</th>
                              <th>Campaign Name</th>
                              <th class="text-end">Allocation</th>
                              <th class="text-end">Generated</th>
                              <th class="text-end">Qualified</th>
                              <th class="text-end">Disqualified</th>
                              <th class="text-end">New</th>
                              <th class="text-end">Delivered</th>
                              <th class="text-end">Pending</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($campaigns)): ?>
                              <tr><td colspan="9" class="text-center text-muted py-2">No campaign data available.</td></tr>
                            <?php else: ?>
                              <?php foreach ($campaigns as $camp): ?>
                                <tr>
                                  <td><?php echo htmlspecialchars((string)($camp['campaign_code'] ?? 'No Code')); ?></td>
                                  <td><?php echo htmlspecialchars((string)($camp['campaign_name'] ?? 'No Name')); ?></td>
                                  <td class="text-end font-monospace"><?php echo number_format((int)($camp['allocation'] ?? 0)); ?></td>
                                  <td class="text-end font-monospace"><?php echo number_format((int)($camp['generated_leads'] ?? 0)); ?></td>
                                  <td class="text-end font-monospace text-success"><?php echo number_format((int)($camp['qualified_leads'] ?? 0)); ?></td>
                                  <td class="text-end font-monospace text-danger"><?php echo number_format((int)($camp['disqualified_leads'] ?? 0)); ?></td>
                                  <td class="text-end font-monospace text-success"><?php echo number_format((int)($camp['new_leads'] ?? 0)); ?></td>
                                  <td class="text-end font-monospace"><?php echo number_format((int)($camp['delivered'] ?? 0)); ?></td>
                                  <td class="text-end font-monospace text-warning"><?php echo number_format((int)($camp['pending'] ?? 0)); ?></td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <?php if (!empty($clientLiveSummaryRows)): ?>
              <tfoot>
                <tr>
                  <td class="fw-bold">Total</td>
                  <td class="text-center font-monospace fw-bold"><?php echo number_format((int)$clientLiveSummaryTotals['live_campaigns']); ?></td>
                  <td class="text-center font-monospace fw-bold"><?php echo number_format((int)$clientLiveSummaryTotals['total_allocation']); ?></td>
                  <td class="text-center font-monospace fw-bold"><?php echo number_format((int)$clientLiveSummaryTotals['generated_leads']); ?></td>
                  <td class="text-center font-monospace fw-bold text-success"><?php echo number_format((int)$clientLiveSummaryTotals['qualified_leads']); ?></td>
                  <td class="text-center font-monospace fw-bold text-danger"><?php echo number_format((int)$clientLiveSummaryTotals['disqualified_leads']); ?></td>
                  <td class="text-center font-monospace fw-bold text-success"><?php echo number_format((int)$clientLiveSummaryTotals['new_leads']); ?></td>
                  <td class="text-center font-monospace fw-bold"><?php echo number_format((int)$clientLiveSummaryTotals['delivered']); ?></td>
                  <td class="text-center font-monospace fw-bold text-warning"><?php echo number_format((int)$clientLiveSummaryTotals['pending']); ?></td>
                </tr>
              </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card campaign-dash-card campaign-panel h-100">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-trophy me-1"></i>Top 3 Performers</span>
          <span class="text-muted small"><?php echo htmlspecialchars($selectedRangeMeta); ?></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
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
                <tr><td colspan="5" class="text-center text-muted py-4">No performer data available for the selected range.</td></tr>
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
                      <div class="fw-semibold"><?php echo htmlspecialchars((string)$p['name']); ?></div>
                      <div class="small text-muted">Performance tier: <?php echo htmlspecialchars((string)($p['rank'] ?: 'Unranked')); ?></div>
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
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card campaign-dash-card campaign-panel h-100">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-graph-down-arrow me-1"></i>Bottom 3 Performers</span>
          <span class="text-muted small"><?php echo htmlspecialchars($selectedRangeMeta); ?></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
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
                <tr><td colspan="5" class="text-center text-muted py-4">No performer data available for the selected range.</td></tr>
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
                      <div class="fw-semibold"><?php echo htmlspecialchars((string)$p['name']); ?></div>
                      <div class="small text-muted">Performance tier: <?php echo htmlspecialchars((string)($p['rank'] ?: 'Unranked')); ?></div>
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
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card campaign-dash-card campaign-panel h-100">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-bar-chart me-1"></i>Top 10 Live Campaigns</span>
          <span class="text-muted small">Top 10 by allocation</span>
        </div>
        <div class="card-body">
          <canvas id="top10Chart" height="220"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card campaign-dash-card campaign-panel h-100">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-buildings me-1"></i>Client Rollup</span>
          <span class="text-muted small">Top 25 by pending leads</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
            <thead class="table-light">
              <tr>
                <th>Client</th>
                <th class="text-end">Campaigns</th>
                <th class="text-end">Live</th>
                <th class="text-end">Alloc</th>
                <th class="text-end">Del</th>
                <th class="text-end">Acc</th>
                <th class="text-end">Pend</th>
                <th class="text-end">Pacing</th>
                <th class="text-end">PQA</th>
                <th class="text-end">Last Lead</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($clientRollupRows)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No client data in the current view.</td></tr>
              <?php else: ?>
                <?php foreach ($clientRollupRows as $cr): ?>
                  <?php
                    $clientCode = trim((string)($cr['client_code'] ?? ''));
                    if ($clientCode === '') $clientCode = 'No Client';
                    $clientHref = '../campaigns/list?' . http_build_query(array_filter([
                        'status' => 'Live',
                        'search' => trim((string)($cr['client_code'] ?? '')),
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'range' => $range,
                    ], fn($v) => $v !== '' && $v !== null));
                  ?>
                  <tr>
                    <td class="fw-semibold">
                      <?php if (trim((string)($cr['client_code'] ?? '')) !== ''): ?>
                        <a class="text-decoration-none" href="<?php echo htmlspecialchars($clientHref); ?>"><?php echo htmlspecialchars($clientCode); ?></a>
                      <?php else: ?>
                        <?php echo htmlspecialchars($clientCode); ?>
                      <?php endif; ?>
                    </td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['campaigns'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['live'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['allocation_leads'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['delivered_leads'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['accepted_leads'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['pending_leads'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['pacing_pct'] ?? 0)); ?>%</td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['pending_qa'] ?? 0)); ?></td>
                    <td class="text-end text-muted small"><?php echo !empty($cr['last_lead_at']) ? htmlspecialchars(opsFormatUtc((string)$cr['last_lead_at'], 'd M, H:i')) : '—'; ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card campaign-dash-card campaign-panel mb-3">
    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-person-badge me-1"></i>Live Campaigns Agent Performance</span>
      <span class="text-muted small">Performance % = Qualified / Total live-campaign leads in selected range</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
        <thead class="table-light">
          <tr>
            <th>Agent</th>
            <th class="text-end">Total</th>
            <th class="text-end">Qualified</th>
            <th class="text-end">Pending</th>
            <th class="text-end">Disqualified</th>
            <th class="text-end">Performance %</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($agentPerformanceRows)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No agent data in the current view.</td></tr>
          <?php else: ?>
            <?php foreach ($agentPerformanceRows as $a): ?>
              <?php
                $name = trim((string)($a['agent_name'] ?? ''));
                $userRole = trim((string)($a['role'] ?? ''));
                $label = $name !== '' ? formatUserNameWithRole($name, $userRole, (string)($a['job_title'] ?? '')) : ('Agent #' . (int)($a['agent_id'] ?? 0));
                $agentId = (int)($a['agent_id'] ?? 0);
                $agentClientRows = $agentClientDetails[$agentId] ?? [];
              ?>
              <tr>
                <td class="fw-semibold">
                  <a class="text-decoration-none agent-toggle" href="javascript:void(0)" data-agent-id="<?php echo $agentId; ?>">
                    <i class="bi bi-chevron-right me-1 toggle-icon"></i><?php echo htmlspecialchars($label); ?>
                  </a>
                </td>
                <td class="text-end font-monospace"><?php echo number_format((int)($a['total'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-success"><?php echo number_format((int)($a['qualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($a['pending'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-danger"><?php echo number_format((int)($a['disqualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace fw-semibold"><?php echo number_format((float)($a['performance_pct'] ?? 0), 1); ?>%</td>
              </tr>
              <tr class="agent-details-row" id="agent-details-<?php echo $agentId; ?>" style="display: none;">
                <td colspan="6" class="p-0">
                  <div class="p-3 bg-light border-top">
                    <div class="fw-semibold mb-2">Client Code-wise Details</div>
                    <table class="table table-sm table-bordered mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Client Code</th>
                          <th class="text-end">Generated</th>
                          <th class="text-end">Qualified</th>
                          <th class="text-end">Disqualified</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($agentClientRows)): ?>
                          <tr><td colspan="4" class="text-center text-muted py-2">No client data available.</td></tr>
                        <?php else: ?>
                          <?php foreach ($agentClientRows as $cr): ?>
                            <tr>
                              <td><?php echo htmlspecialchars((string)($cr['client_code'] ?? 'No Client')); ?></td>
                              <td class="text-end font-monospace"><?php echo number_format((int)($cr['generated'] ?? 0)); ?></td>
                              <td class="text-end font-monospace text-success"><?php echo number_format((int)($cr['qualified'] ?? 0)); ?></td>
                              <td class="text-end font-monospace text-danger"><?php echo number_format((int)($cr['disqualified'] ?? 0)); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($canOpsCampaignScoreTable): ?>
  <div class="card campaign-dash-card campaign-panel mb-3">
    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-table me-1"></i>Campaign-wise Scored Leads</span>
      <span class="text-muted small"><?php echo htmlspecialchars($selectedRangeMeta); ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
        <thead class="table-light">
          <tr>
            <th>Campaign Name</th>
            <th class="text-end">Generated</th>
            <th class="text-end">Qualified</th>
            <th class="text-end">Disqualified</th>
            <th class="text-end">Pending QA</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($campaignScoreRows)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No campaign lead scores found for the selected filters.</td></tr>
          <?php else: ?>
            <?php foreach ($campaignScoreRows as $row): ?>
              <?php $campaignLabel = crmCampaignDisplayName((string)($row['name'] ?? ''), (string)($row['client_code'] ?? ''), (string)($row['code'] ?? '')); ?>
              <tr>
                <td class="fw-semibold">
                  <a class="text-decoration-none" href="view?id=<?php echo (int)($row['id'] ?? 0); ?>"><?php echo htmlspecialchars($campaignLabel); ?></a>
                </td>
                <td class="text-end font-monospace"><?php echo number_format((int)($row['generated'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-success"><?php echo number_format((int)($row['qualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-danger"><?php echo number_format((int)($row['disqualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-warning"><?php echo number_format((int)($row['pending_qa'] ?? 0)); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <th>Total</th>
            <th class="text-end font-monospace"><?php echo number_format((int)($campaignScoreTotals['generated'] ?? 0)); ?></th>
            <th class="text-end font-monospace text-success"><?php echo number_format((int)($campaignScoreTotals['qualified'] ?? 0)); ?></th>
            <th class="text-end font-monospace text-danger"><?php echo number_format((int)($campaignScoreTotals['disqualified'] ?? 0)); ?></th>
            <th class="text-end font-monospace text-warning"><?php echo number_format((int)($campaignScoreTotals['pending_qa'] ?? 0)); ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card campaign-dash-card campaign-panel mb-3">
    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-broadcast me-1"></i>Live Campaigns</span>
      <span class="text-muted small">Allocation, delivered, pacing, pending QA, and ending date in selected view</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
        <thead class="table-light">
          <tr>
            <th>Campaign</th>
            <th>Client</th>
            <th class="text-end">Allocation</th>
            <th class="text-end">Delivered</th>
            <th class="text-end">Pending</th>
            <th style="width: 220px;">Pacing</th>
            <th class="text-end">Health</th>
            <th class="text-end">PQA</th>
            <th class="text-end">Last Lead</th>
            <th class="text-end">End</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php $liveRows = array_slice($liveRowsAll, 0, 25); ?>
          <?php if (empty($liveRows)): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No live campaigns in the current view.</td></tr>
          <?php else: ?>
            <?php foreach ($liveRows as $r): ?>
              <?php
                $campaignId = (int)($r['id'] ?? 0);
                $allocation = (int)($r['total_leads'] ?? 0);
                $delivered = (int)($deliveredByCampaign[$campaignId] ?? 0);
                $pending = max(0, $allocation - $delivered);
                $pacingPct = $allocation > 0 ? min(100, (int)round(($delivered / $allocation) * 100)) : 0;
                $pendingQaCount = (int)($pendingQaByCampaign[$campaignId] ?? 0);
                $lastLeadAt = $lastLeadAtByCampaign[$campaignId] ?? null;
                $end = trim((string)($r['end_date'] ?? ''));
                $daysLeft = null;
                if ($end !== '') {
                    try {
                        $endDt = new DateTimeImmutable($end . ' 23:59:59', opsBusinessTz());
                        $daysLeft = (int)max(0, ceil(($endDt->getTimestamp() - $riskAnchorStart->getTimestamp()) / 86400));
                    } catch (Throwable $e) {
                        $daysLeft = null;
                    }
                }
                $lastAgeHours = null;
                if ($lastLeadAt) {
                    $llDt = appParseUtc((string)$lastLeadAt);
                    if ($llDt instanceof DateTimeImmutable) {
                        $lastAgeHours = max(0, ($riskAnchorStart->getTimestamp() - $llDt->getTimestamp()) / 3600);
                    }
                }
                $pendingQaRatio = $allocation > 0 ? ($pendingQaCount / $allocation) : 0;
                $healthPoints = 0;
                if ($pacingPct < 60) $healthPoints += 3;
                elseif ($pacingPct < 80) $healthPoints += 2;
                elseif ($pacingPct < 90) $healthPoints += 1;
                if ($daysLeft !== null) {
                    if ($daysLeft <= 1) $healthPoints += 2;
                    elseif ($daysLeft <= 3) $healthPoints += 1;
                }
                if ($lastAgeHours === null) {
                    $healthPoints += 1;
                } else {
                    if ($lastAgeHours > 48) $healthPoints += 2;
                    elseif ($lastAgeHours > 24) $healthPoints += 1;
                }
                if ($allocation > 0) {
                    if ($pendingQaCount >= 30 || $pendingQaRatio > 0.30) $healthPoints += 2;
                    elseif ($pendingQaCount >= 15 || $pendingQaRatio > 0.15) $healthPoints += 1;
                }
                $healthLabel = 'Healthy';
                $healthClass = 'bg-success-subtle text-success';
                if ($healthPoints >= 6) {
                    $healthLabel = 'Critical';
                    $healthClass = 'bg-danger-subtle text-danger';
                } elseif ($healthPoints >= 3) {
                    $healthLabel = 'Watch';
                    $healthClass = 'bg-warning-subtle text-warning';
                }
                $ageText = '—';
                if ($lastAgeHours !== null) {
                    if ($lastAgeHours < 1) $ageText = 'Just now';
                    elseif ($lastAgeHours < 24) $ageText = round($lastAgeHours) . 'h ago';
                    else $ageText = round($lastAgeHours / 24) . 'd ago';
                }
                $tooltip = 'Pacing: ' . $pacingPct . '%'
                    . ' | Days left: ' . ($daysLeft === null ? '—' : (string)$daysLeft)
                    . ' | Pending QA: ' . $pendingQaCount
                    . ' | Last lead: ' . $ageText;
              ?>
              <tr>
                <td class="fw-semibold">
                  <?php echo htmlspecialchars((string)($r['name'] ?? '')); ?>
                  <div class="text-muted small"><?php echo htmlspecialchars((string)($r['code'] ?? '')); ?></div>
                </td>
                <td class="text-muted small"><?php echo htmlspecialchars(trim((string)($r['client_code'] ?? '')) !== '' ? (string)($r['client_code'] ?? '') : (string)($r['client_name'] ?? '')); ?></td>
                <td class="text-end font-monospace"><?php echo number_format($allocation); ?></td>
                <td class="text-end font-monospace"><?php echo number_format($delivered); ?></td>
                <td class="text-end font-monospace"><?php echo number_format($pending); ?></td>
                <td>
                  <div class="progress" style="height: 12px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $pacingPct; ?>%"></div>
                  </div>
                  <div class="d-flex justify-content-between text-muted small mt-1">
                    <span class="font-monospace"><?php echo $pacingPct; ?>%</span>
                    <span class="font-monospace"><?php echo number_format($delivered); ?>/<?php echo number_format($allocation); ?></span>
                  </div>
                </td>
                <td class="text-end">
                  <span class="badge border <?php echo htmlspecialchars($healthClass); ?>" title="<?php echo htmlspecialchars($tooltip); ?>">
                    <?php echo htmlspecialchars($healthLabel); ?>
                  </span>
                </td>
                <td class="text-end font-monospace"><?php echo number_format($pendingQaCount); ?></td>
                <td class="text-end text-muted small"><?php echo $lastLeadAt ? htmlspecialchars(opsFormatUtc((string)$lastLeadAt, 'd M, H:i')) : '—'; ?></td>
                <td class="text-end text-muted small"><?php echo $end !== '' ? htmlspecialchars($end) : '—'; ?></td>
                <td class="text-end"><a class="btn btn-sm btn-light border" href="view?id=<?php echo $campaignId; ?>"><i class="bi bi-eye"></i></a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card campaign-dash-card campaign-panel">
    <div class="card-header fw-semibold">Recent Campaigns</div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 campaign-dark-table">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Code</th>
            <th>Status</th>
            <th>Start</th>
            <th>End</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($overviewRows)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No campaigns available.</td></tr>
          <?php else: ?>
            <?php foreach (array_slice($overviewRows, 0, 15) as $r): ?>
              <?php
                $status = (string)($r['status'] ?? '');
                if ($status === 'Paused') $status = 'Pause';
                if ($status === 'Completed') $status = 'Complete';
                $statusMap = ['Live' => 'success', 'Active' => 'primary', 'Pause' => 'warning', 'Complete' => 'secondary', 'Draft' => 'dark'];
                $statusClass = $statusMap[$status] ?? 'secondary';
              ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['code'] ?? '')); ?></td>
                <td><span class="badge bg-<?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($status); ?></span></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['start_date'] ?? '')); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['end_date'] ?? '')); ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-light border" href="view?id=<?php echo (int)($r['id'] ?? 0); ?>"><i class="bi bi-eye"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
  if (!window.Chart) return;
  const top10 = <?php echo json_encode($top10Chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark'
    || document.body.getAttribute('data-bs-theme') === 'dark'
    || document.documentElement.getAttribute('data-theme') === 'dark'
    || document.body.getAttribute('data-theme') === 'dark';
  const tickColor = isDark ? 'rgba(226,232,240,.78)' : 'rgba(51,65,85,.88)';
  const gridColor = isDark ? 'rgba(148,163,184,.12)' : 'rgba(148,163,184,.20)';
  const legendColor = tickColor;
  const chartDefaults = {
    responsive: true,
    plugins: {
      legend: {
        position: 'bottom',
        labels: { color: legendColor }
      },
      tooltip: { mode: 'index', intersect: false }
    },
    interaction: { mode: 'index', intersect: false },
    scales: {
      x: {
        ticks: { maxRotation: 0, autoSkip: true, color: tickColor },
        grid: { color: gridColor }
      },
      y: {
        beginAtZero: true,
        ticks: { precision: 0, color: tickColor },
        grid: { color: gridColor }
      }
    }
  };


  const top10El = document.getElementById('top10Chart');
  if (top10El && top10 && Array.isArray(top10.labels)) {
    new Chart(top10El, {
      type: 'bar',
      data: {
        labels: top10.labels,
        datasets: [
          { label: 'Allocation', data: top10.allocation || [], backgroundColor: 'rgba(59, 130, 246, 0.65)' },
          { label: 'Delivered', data: top10.delivered || [], backgroundColor: 'rgba(34, 197, 94, 0.75)' },
          { label: 'Pending', data: top10.pending || [], backgroundColor: 'rgba(245, 158, 11, 0.75)' }
        ]
      },
      options: chartDefaults
    });
  }

  // Agent details toggle functionality
  document.querySelectorAll('.agent-toggle').forEach(toggle => {
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      const agentId = this.getAttribute('data-agent-id');
      const detailsRow = document.getElementById('agent-details-' + agentId);
      const icon = this.querySelector('.toggle-icon');
      
      if (detailsRow) {
        const isHidden = detailsRow.style.display === 'none';
        detailsRow.style.display = isHidden ? 'table-row' : 'none';
        if (icon) {
          icon.classList.toggle('bi-chevron-right', !isHidden);
          icon.classList.toggle('bi-chevron-down', isHidden);
        }
      }
    });
  });

  // Client details toggle functionality
  document.querySelectorAll('.client-toggle').forEach(toggle => {
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      const rowId = this.getAttribute('data-row-id');
      const detailsRow = document.getElementById(rowId);
      const icon = this.querySelector('.toggle-icon');
      
      if (detailsRow) {
        const isHidden = detailsRow.style.display === 'none';
        detailsRow.style.display = isHidden ? 'table-row' : 'none';
        if (icon) {
          icon.classList.toggle('bi-chevron-right', !isHidden);
          icon.classList.toggle('bi-chevron-down', isHidden);
        }
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
