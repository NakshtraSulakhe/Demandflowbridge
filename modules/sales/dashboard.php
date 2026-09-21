<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr','admin','director','manager_director']);
ensureCsrfToken();

try {
$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts($userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$canTasks = function_exists('userHasPermission')
  ? (userHasPermission('tasks.access') || userHasPermission('tasks.create') || userHasPermission('tasks.assign') || userHasPermission('tasks.manage') || userHasPermission('tasks.override') || userHasPermission('tasks.reports'))
  : true;
$isSdr = isSDR();
$isManager = isSalesManager();
$isDirector = isSalesDirector();
$salesScope = function_exists('getSalesScopeUserIdsForCurrentUser') ? getSalesScopeUserIdsForCurrentUser($user) : [$userId];
$salesScopeIds = is_array($salesScope) ? array_values(array_filter(array_map('intval', $salesScope), fn($v) => $v > 0)) : null;
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$opsMonth = function_exists('opsMonthRange') ? opsMonthRange() : ['start' => $opsNow->format('Y-m-01 00:00:00'), 'end' => $opsNow->format('Y-m-t 23:59:59')];
$nowUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');

$statuses = ['New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];

$where = [];
$params = [];
$types = '';
if ($salesScopeIds === null) {
    $whereSql = '';
} elseif (!empty($salesScopeIds)) {
    $in = implode(',', array_fill(0, count($salesScopeIds), '?'));
    $where[] = "sl.owner_id IN ($in)";
    $params = array_merge($params, $salesScopeIds);
    $types .= str_repeat('i', count($salesScopeIds));
    $whereSql = 'WHERE '.implode(' AND ', $where);
} else {
    $whereSql = 'WHERE 1=0';
}

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($opsMonth['start'] ?? '1970-01-01 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($opsMonth['end'] ?? '2999-12-31 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));

$statusWhereSql = $whereSql;
$statusTypes = $types;
$statusParams = $params;
if ($statusWhereSql === '') {
    $statusWhereSql = 'WHERE sl.created_at BETWEEN ? AND ?';
} else {
    $statusWhereSql .= ' AND sl.created_at BETWEEN ? AND ?';
}
$statusTypes .= 'ss';
$statusParams = array_merge($statusParams, [$rangeStart, $rangeEnd]);

$sql = "SELECT sl.status, COUNT(*) AS cnt FROM sales_leads sl $statusWhereSql GROUP BY sl.status";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($statusTypes, ...$statusParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
} else {
    $rows = [];
}

$countsByStatus = array_fill_keys($statuses, 0);
$totalProspects = 0;
foreach ($rows as $r) {
    $st = (string)($r['status'] ?? '');
    $cnt = (int)($r['cnt'] ?? 0);
    $totalProspects += $cnt;
    if (isset($countsByStatus[$st])) $countsByStatus[$st] = $cnt;
}

$recentSql = "SELECT a.id, a.sales_lead_id, a.status, a.comment, a.created_at, u.full_name AS created_by_name, u.role AS created_by_role, u.job_title AS created_by_job_title,
    sl.company_name
    FROM sales_lead_activities a
    LEFT JOIN users u ON u.id = a.created_by
    LEFT JOIN sales_leads sl ON sl.id = a.sales_lead_id
    $whereSql
    ORDER BY a.created_at DESC
    LIMIT 12";
$stmt = $conn->prepare($recentSql);
if ($stmt) {
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
} else {
    $recent = [];
}

$teamSql = "SELECT
    sl.owner_id,
    u.full_name,
    COUNT(DISTINCT sl.id) AS prospects,
    COUNT(a.id) AS activities,
    SUM(CASE WHEN sl.status = 'Closed Won' THEN 1 ELSE 0 END) AS won,
    SUM(CASE WHEN sl.status = 'Closed Lost' THEN 1 ELSE 0 END) AS lost
    FROM sales_leads sl
    LEFT JOIN users u ON u.id = sl.owner_id
    LEFT JOIN sales_lead_activities a ON a.sales_lead_id = sl.id
    $whereSql
    GROUP BY sl.owner_id, u.full_name
    ORDER BY prospects DESC
    LIMIT 10";
$stmt = $conn->prepare($teamSql);
if ($stmt) {
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $team = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
} else {
    $team = [];
}

$opportunities = (int)($countsByStatus['Proposal Sent'] ?? 0) + (int)($countsByStatus['Negotiation'] ?? 0);
$conversionRate = $totalProspects > 0 ? round(((int)($countsByStatus['Closed Won'] ?? 0) / $totalProspects) * 100, 1) : 0.0;

$viewYear = (int)$opsNow->format('Y');
$viewMonth = (int)$opsNow->format('m');
$targetRow = function_exists('getSalesMonthlyTargetForUserScope')
    ? getSalesMonthlyTargetForUserScope($user, $viewYear, $viewMonth)
    : (getSalesMonthlyTarget($userId, $viewYear, $viewMonth) ?: ['target_new_accounts' => 0, 'target_revenue_usd' => 0]);
$targetNewAccounts = (int)($targetRow['target_new_accounts'] ?? 0);
$targetRevenueUsd = (float)($targetRow['target_revenue_usd'] ?? 0.0);
$achievedAccounts = (int)(getSalesNewAccountsProgress($user, $viewYear, $viewMonth)['achieved'] ?? 0);
$usdInr = getUsdInrRate($opsNow->format('Y-m-d'));
$targetsTitle = ($salesScopeIds === null) ? 'Department Targets' : (($isManager && !$isDirector) ? 'Team Targets' : 'My Targets');

$monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $viewYear, $viewMonth), opsBusinessTz());
$monthEnd = $monthStart->modify('first day of next month')->modify('-1 second');
$yearStart = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $viewYear), opsBusinessTz());
$yearEnd = $yearStart->modify('+1 year')->modify('-1 second');

$q = (int)floor(((int)$opsNow->format('n') - 1) / 3) + 1;
$qStartMonth = 1 + (($q - 1) * 3);
$quarterStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $viewYear, $qStartMonth), opsBusinessTz());
$quarterEnd = $quarterStart->modify('+3 month')->modify('-1 second');

$salesCommissionPct = function_exists('getSalesCommissionPercentForUser') ? getSalesCommissionPercentForUser($userId) : 0.0;
$scopePctMap = (function_exists('getSalesCommissionPercentMap') && is_array($salesScopeIds)) ? getSalesCommissionPercentMap($salesScopeIds) : [];

$moneyAgg = function(string $sql, string $startTs, string $endTs) use ($conn, $salesScopeIds): array {
    $out = ['USD' => 0.0, 'INR' => 0.0];
    if ($salesScopeIds === null) {
        $stmt = $conn->prepare($sql . " AND ri.sales_owner_id IS NOT NULL");
        if (!$stmt) return $out;
        $stmt->bind_param('ss', $startTs, $endTs);
    } else {
        if (empty($salesScopeIds)) return $out;
        $in = implode(',', array_fill(0, count($salesScopeIds), '?'));
        $stmt = $conn->prepare($sql . " AND ri.sales_owner_id IN ($in)");
        if (!$stmt) return $out;
        $types = 'ss' . str_repeat('i', count($salesScopeIds));
        $params = array_merge([$startTs, $endTs], $salesScopeIds);
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $out['USD'] = (float)($row['usd_total'] ?? 0.0);
    $out['INR'] = (float)($row['inr_total'] ?? 0.0);
    return $out;
};

$paidInvoiceAggSql = "
    SELECT
      COALESCE(SUM(CASE WHEN ri.currency = 'USD' THEN ri.total ELSE 0 END),0) AS usd_total,
      COALESCE(SUM(CASE WHEN ri.currency = 'INR' THEN ri.total ELSE 0 END),0) AS inr_total
    FROM revenue_invoices ri
    WHERE ri.status = 'Paid'
      AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) >= ?
      AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) <= ?
";

$monthPaid = $moneyAgg($paidInvoiceAggSql, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s'));
$quarterPaid = $moneyAgg($paidInvoiceAggSql, $quarterStart->format('Y-m-d H:i:s'), $quarterEnd->format('Y-m-d H:i:s'));
$yearPaid = $moneyAgg($paidInvoiceAggSql, $yearStart->format('Y-m-d H:i:s'), $yearEnd->format('Y-m-d H:i:s'));
$rangePaid = $moneyAgg($paidInvoiceAggSql, $rangeStart, $rangeEnd);

$commissionAgg = function(string $startTs, string $endTs, string $status) use ($conn, $salesScopeIds): array {
    $out = ['USD' => 0.0, 'INR' => 0.0];
    if ($salesScopeIds === null) {
        $stmt = $conn->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN currency = 'USD' THEN commission_amount ELSE 0 END),0) AS usd_total,
              COALESCE(SUM(CASE WHEN currency = 'INR' THEN commission_amount ELSE 0 END),0) AS inr_total
            FROM sales_commissions
            WHERE sales_user_id IS NOT NULL
              AND status = ?
              AND ((paid_at IS NOT NULL AND paid_at >= ? AND paid_at <= ?) OR (paid_at IS NULL AND issue_date >= ? AND issue_date <= ?))
        ");
        if (!$stmt) return $out;
        $startDate = substr($startTs, 0, 10);
        $endDate = substr($endTs, 0, 10);
        $stmt->bind_param('sssss', $status, $startTs, $endTs, $startDate, $endDate);
    } else {
        if (empty($salesScopeIds)) return $out;
        $in = implode(',', array_fill(0, count($salesScopeIds), '?'));
        $stmt = $conn->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN currency = 'USD' THEN commission_amount ELSE 0 END),0) AS usd_total,
              COALESCE(SUM(CASE WHEN currency = 'INR' THEN commission_amount ELSE 0 END),0) AS inr_total
            FROM sales_commissions
            WHERE sales_user_id IN ($in)
              AND status = ?
              AND ((paid_at IS NOT NULL AND paid_at >= ? AND paid_at <= ?) OR (paid_at IS NULL AND issue_date >= ? AND issue_date <= ?))
        ");
        if (!$stmt) return $out;
        $types = str_repeat('i', count($salesScopeIds)) . 'sssss';
        $startDate = substr($startTs, 0, 10);
        $endDate = substr($endTs, 0, 10);
        $params = array_merge($salesScopeIds, [$status, $startTs, $endTs, $startDate, $endDate]);
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $out['USD'] = (float)($row['usd_total'] ?? 0.0);
    $out['INR'] = (float)($row['inr_total'] ?? 0.0);
    return $out;
};

$monthCommPaid = $commissionAgg($monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s'), 'paid');
$monthCommPending = $commissionAgg($monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s'), 'pending');

$projectedComm = ['USD' => 0.0, 'INR' => 0.0];
if (function_exists('ensureDatabaseSchema')) ensureDatabaseSchema();
$monthStartDate = $monthStart->format('Y-m-d');
$monthEndDate = $monthEnd->format('Y-m-d');
if ($salesScopeIds === null) {
    $stmt = $conn->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN ri.currency = 'USD' THEN (ri.total * (COALESCE(scs.commission_percent,0) / 100.0)) ELSE 0 END),0) AS usd_total,
          COALESCE(SUM(CASE WHEN ri.currency = 'INR' THEN (ri.total * (COALESCE(scs.commission_percent,0) / 100.0)) ELSE 0 END),0) AS inr_total
        FROM revenue_invoices ri
        LEFT JOIN sales_commission_settings scs ON scs.user_id = ri.sales_owner_id
        WHERE ri.sales_owner_id IS NOT NULL
          AND ri.status IN ('Draft','Sent')
          AND ri.issue_date >= ?
          AND ri.issue_date <= ?
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $monthStartDate, $monthEndDate);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $projectedComm['USD'] = (float)($r['usd_total'] ?? 0.0);
        $projectedComm['INR'] = (float)($r['inr_total'] ?? 0.0);
    }
} elseif (!empty($salesScopeIds)) {
    $in = implode(',', array_fill(0, count($salesScopeIds), '?'));
    $stmt = $conn->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN ri.currency = 'USD' THEN (ri.total * (COALESCE(scs.commission_percent,0) / 100.0)) ELSE 0 END),0) AS usd_total,
          COALESCE(SUM(CASE WHEN ri.currency = 'INR' THEN (ri.total * (COALESCE(scs.commission_percent,0) / 100.0)) ELSE 0 END),0) AS inr_total
        FROM revenue_invoices ri
        LEFT JOIN sales_commission_settings scs ON scs.user_id = ri.sales_owner_id
        WHERE ri.sales_owner_id IN ($in)
          AND ri.status IN ('Draft','Sent')
          AND ri.issue_date >= ?
          AND ri.issue_date <= ?
    ");
    if ($stmt) {
        $projTypes = str_repeat('i', count($salesScopeIds)) . 'ss';
        $projParams = array_merge($salesScopeIds, [$monthStartDate, $monthEndDate]);
        $stmt->bind_param($projTypes, ...$projParams);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $projectedComm['USD'] = (float)($r['usd_total'] ?? 0.0);
        $projectedComm['INR'] = (float)($r['inr_total'] ?? 0.0);
    }
}

$revByClient = [];
$revByCampaign = [];
$topRevenueUsers = [];
$topCommissionUsers = [];
$rangeStartTs = $rangeStart;
$rangeEndTs = $rangeEnd;

$scopeSql = '';
$scopeTypes = '';
$scopeParams = [];
if ($salesScopeIds === null) {
    $scopeSql = " AND ri.sales_owner_id IS NOT NULL";
} elseif (!empty($salesScopeIds)) {
    $in = implode(',', array_fill(0, count($salesScopeIds), '?'));
    $scopeSql = " AND ri.sales_owner_id IN ($in)";
    $scopeTypes = str_repeat('i', count($salesScopeIds));
    $scopeParams = $salesScopeIds;
} else {
    $scopeSql = " AND 1=0";
}

$stmt = $conn->prepare("
    SELECT
      ri.client_id,
      COALESCE(c.name, ri.client_name, ri.client_code) AS client_name,
      COALESCE(SUM(CASE WHEN ri.currency = 'USD' THEN ri.total ELSE 0 END),0) AS usd_rev,
      COALESCE(SUM(CASE WHEN ri.currency = 'INR' THEN ri.total ELSE 0 END),0) AS inr_rev,
      COALESCE(SUM(CASE WHEN sc.currency = 'USD' THEN sc.commission_amount ELSE 0 END),0) AS usd_comm,
      COALESCE(SUM(CASE WHEN sc.currency = 'INR' THEN sc.commission_amount ELSE 0 END),0) AS inr_comm
    FROM revenue_invoices ri
    LEFT JOIN clients c ON c.id = ri.client_id
    LEFT JOIN sales_commissions sc
      ON sc.invoice_id = ri.id AND sc.sales_user_id = ri.sales_owner_id AND sc.status = 'paid'
    WHERE ri.status = 'Paid'
      AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) >= ?
      AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) <= ?
      $scopeSql
    GROUP BY ri.client_id, client_name
    ORDER BY (usd_rev + (inr_rev / 100.0)) DESC
    LIMIT 12
");
if ($stmt) {
    $t = 'ss' . $scopeTypes;
    $p = array_merge([$rangeStartTs, $rangeEndTs], $scopeParams);
    $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $revByClient = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
      ri.campaign_id,
      COALESCE(cp.name, CONCAT('Campaign #', ri.campaign_id)) AS campaign_name,
      COALESCE(SUM(CASE WHEN ri.currency = 'USD' THEN ri.total ELSE 0 END),0) AS usd_rev,
      COALESCE(SUM(CASE WHEN ri.currency = 'INR' THEN ri.total ELSE 0 END),0) AS inr_rev,
      COALESCE(SUM(CASE WHEN sc.currency = 'USD' THEN sc.commission_amount ELSE 0 END),0) AS usd_comm,
      COALESCE(SUM(CASE WHEN sc.currency = 'INR' THEN sc.commission_amount ELSE 0 END),0) AS inr_comm
    FROM revenue_invoices ri
    LEFT JOIN campaigns cp ON cp.id = ri.campaign_id
    LEFT JOIN sales_commissions sc
      ON sc.invoice_id = ri.id AND sc.sales_user_id = ri.sales_owner_id AND sc.status = 'paid'
    WHERE ri.status = 'Paid'
      AND ri.campaign_id IS NOT NULL
      AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) >= ?
      AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) <= ?
      $scopeSql
    GROUP BY ri.campaign_id, campaign_name
    ORDER BY (usd_rev + (inr_rev / 100.0)) DESC
    LIMIT 12
");
if ($stmt) {
    $t = 'ss' . $scopeTypes;
    $p = array_merge([$rangeStartTs, $rangeEndTs], $scopeParams);
    $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $revByCampaign = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$showLeaderboards = $isManager || $isDirector || isAdmin() || isDirector();
if ($showLeaderboards) {
    $stmt = $conn->prepare("
        SELECT
          ri.sales_owner_id AS uid,
          u.full_name,
          u.role,
          u.job_title,
          COALESCE(SUM(CASE WHEN ri.currency = 'USD' THEN ri.total ELSE 0 END),0) AS usd_rev,
          COALESCE(SUM(CASE WHEN ri.currency = 'INR' THEN ri.total ELSE 0 END),0) AS inr_rev
        FROM revenue_invoices ri
        LEFT JOIN users u ON u.id = ri.sales_owner_id
        WHERE ri.status = 'Paid'
          AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) >= ?
          AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) <= ?
          $scopeSql
        GROUP BY ri.sales_owner_id, u.full_name, u.role, u.job_title
        ORDER BY (usd_rev + (inr_rev / 100.0)) DESC
        LIMIT 10
    ");
    if ($stmt) {
        $t = 'ss' . $scopeTypes;
        $p = array_merge([$rangeStartTs, $rangeEndTs], $scopeParams);
        $stmt->bind_param($t, ...$p);
        $stmt->execute();
        $topRevenueUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    $scopeSql2 = '';
    $scopeTypes2 = '';
    $scopeParams2 = [];
    if ($salesScopeIds === null) {
        $scopeSql2 = " AND sc.sales_user_id IS NOT NULL";
    } elseif (!empty($salesScopeIds)) {
        $in = implode(',', array_fill(0, count($salesScopeIds), '?'));
        $scopeSql2 = " AND sc.sales_user_id IN ($in)";
        $scopeTypes2 = str_repeat('i', count($salesScopeIds));
        $scopeParams2 = $salesScopeIds;
    } else {
        $scopeSql2 = " AND 1=0";
    }
    $stmt = $conn->prepare("
        SELECT
          sc.sales_user_id AS uid,
          u.full_name,
          u.role,
          u.job_title,
          COALESCE(SUM(CASE WHEN sc.currency = 'USD' THEN sc.commission_amount ELSE 0 END),0) AS usd_comm,
          COALESCE(SUM(CASE WHEN sc.currency = 'INR' THEN sc.commission_amount ELSE 0 END),0) AS inr_comm
        FROM sales_commissions sc
        LEFT JOIN users u ON u.id = sc.sales_user_id
        WHERE sc.status = 'paid'
          AND sc.paid_at IS NOT NULL
          AND sc.paid_at >= ?
          AND sc.paid_at <= ?
          $scopeSql2
        GROUP BY sc.sales_user_id, u.full_name, u.role, u.job_title
        ORDER BY (usd_comm + (inr_comm / 100.0)) DESC
        LIMIT 10
    ");
    if ($stmt) {
        $t = 'ss' . $scopeTypes2;
        $p = array_merge([$rangeStartTs, $rangeEndTs], $scopeParams2);
        $stmt->bind_param($t, ...$p);
        $stmt->execute();
        $topCommissionUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$overdueCount = 0;
$overdueLeads = [];
$overdueWhereSql = $whereSql ? ($whereSql . " AND sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at < ? AND sl.status NOT IN ('Closed Won','Closed Lost')") : "WHERE sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at < ? AND sl.status NOT IN ('Closed Won','Closed Lost')";
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM sales_leads sl $overdueWhereSql");
if ($stmt) {
    $overdueTypes = $types . 's';
    $overdueParams = array_merge($params, [$nowUtc]);
    $stmt->bind_param($overdueTypes, ...$overdueParams);
    $stmt->execute();
    $overdueCount = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT sl.id, sl.company_name, sl.status, sl.priority, sl.next_follow_up_at, u.full_name AS owner_name, u.role AS owner_role, u.job_title AS owner_job_title
    FROM sales_leads sl
    LEFT JOIN users u ON u.id = sl.owner_id
    $overdueWhereSql
    ORDER BY sl.next_follow_up_at ASC
    LIMIT 10");
if ($stmt) {
    $overdueTypes = $types . 's';
    $overdueParams = array_merge($params, [$nowUtc]);
    $stmt->bind_param($overdueTypes, ...$overdueParams);
    $stmt->execute();
    $overdueLeads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$search = trim((string)($_GET['q'] ?? ''));
$searchResults = [];
if ($search !== '') {
    $sWhere = $where ? $where : [];
    $sParams = $params;
    $sTypes = $types;
    $like = '%'.$search.'%';
    $sWhere[] = "(sl.company_name LIKE ? OR sl.website LIKE ? OR sl.contact_email LIKE ? OR sl.contact_name LIKE ? OR sl.linkedin_url LIKE ?)";
    array_push($sParams, $like, $like, $like, $like, $like);
    $sTypes .= 'sssss';
    $sWhereSql = 'WHERE '.implode(' AND ', $sWhere);
    $stmt = $conn->prepare("SELECT sl.id, sl.company_name, sl.status, sl.priority, sl.country, sl.industry, sl.updated_at
        FROM sales_leads sl $sWhereSql ORDER BY sl.updated_at DESC, sl.created_at DESC LIMIT 15");
    if ($stmt) {
        if ($sTypes) $stmt->bind_param($sTypes, ...$sParams);
        $stmt->execute();
        $searchResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$rangeNewProspects = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sales_leads sl $whereSql " . ($whereSql ? "AND" : "WHERE") . " sl.created_at BETWEEN ? AND ?");
if ($stmt) {
    $t = $types . 'ss';
    $p = array_merge($params, [$rangeStart, $rangeEnd]);
    if ($t) $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $rangeNewProspects = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmt->close();
}

$rangeActivities = 0;
$stmt = $conn->prepare("
  SELECT COUNT(*) AS c
  FROM sales_lead_activities a
  JOIN sales_leads sl ON sl.id = a.sales_lead_id
  $whereSql
  " . ($whereSql ? "AND" : "WHERE") . " a.created_at BETWEEN ? AND ?
");
if ($stmt) {
    $t = $types . 'ss';
    $p = array_merge($params, [$rangeStart, $rangeEnd]);
    if ($t) $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $rangeActivities = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmt->close();
}

$rangeWon = 0;
$rangeLost = 0;
$stmt = $conn->prepare("
  SELECT
    SUM(CASE WHEN a.status = 'Closed Won' THEN 1 ELSE 0 END) AS won_cnt,
    SUM(CASE WHEN a.status = 'Closed Lost' THEN 1 ELSE 0 END) AS lost_cnt
  FROM (
    SELECT sales_lead_id, MAX(id) AS max_id
    FROM sales_lead_activities
    WHERE created_at BETWEEN ? AND ?
    GROUP BY sales_lead_id
  ) x
  JOIN sales_lead_activities a ON a.id = x.max_id
  JOIN sales_leads sl ON sl.id = a.sales_lead_id
  $whereSql
");
if ($stmt) {
    $t = 'ss' . $types;
    $p = array_merge([$rangeStart, $rangeEnd], $params);
    if ($t) $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $rangeWon = (int)($r['won_cnt'] ?? 0);
    $rangeLost = (int)($r['lost_cnt'] ?? 0);
}

$teamRange = [];
$topTeam = [];
$bottomTeam = [];
$stmt = $conn->prepare("
  SELECT
    sl.owner_id,
    u.full_name,
    SUM(CASE WHEN sl.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS new_prospects,
    SUM(CASE WHEN a.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS activities,
    SUM(CASE WHEN a.status = 'Closed Won' AND a.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS won,
    SUM(CASE WHEN a.status = 'Closed Lost' AND a.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS lost
  FROM sales_leads sl
  LEFT JOIN users u ON u.id = sl.owner_id
  LEFT JOIN sales_lead_activities a ON a.sales_lead_id = sl.id
  $whereSql
  GROUP BY sl.owner_id, u.full_name
  ORDER BY won DESC, activities DESC, new_prospects DESC
  LIMIT 12
");
if ($stmt) {
    $t = 'ssssssss' . $types;
    $p = array_merge([$rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd], $params);
    if ($t) $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $teamRange = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($teamRange as &$r) {
        $r['new_prospects'] = (int)($r['new_prospects'] ?? 0);
        $r['activities'] = (int)($r['activities'] ?? 0);
        $r['won'] = (int)($r['won'] ?? 0);
        $r['lost'] = (int)($r['lost'] ?? 0);
    }
    unset($r);
    $tmp = array_values(array_filter($teamRange, fn($r) => (int)($r['activities'] ?? 0) > 0 || (int)($r['new_prospects'] ?? 0) > 0));
    usort($tmp, fn($a,$b) => ((int)($b['won'] ?? 0) <=> (int)($a['won'] ?? 0)) ?: ((int)($b['activities'] ?? 0) <=> (int)($a['activities'] ?? 0)));
    $topTeam = array_slice($tmp, 0, 3);
    usort($tmp, fn($a,$b) => ((int)($a['won'] ?? 0) <=> (int)($b['won'] ?? 0)) ?: ((int)($a['activities'] ?? 0) <=> (int)($b['activities'] ?? 0)));
    $bottomTeam = array_slice($tmp, 0, 3);
}

$attendance = ['status' => '', 'work_minutes' => 0, 'late_minutes' => 0];
$todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$stmt = $conn->prepare("SELECT status, work_minutes, late_minutes FROM hr_attendance_days WHERE user_id = ? AND work_date = ? LIMIT 1");
if ($stmt) {
  $stmt->bind_param('is', $userId, $todayDate);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();
  $attendance['status'] = (string)($r['status'] ?? '');
  $attendance['work_minutes'] = (int)($r['work_minutes'] ?? 0);
  $attendance['late_minutes'] = (int)($r['late_minutes'] ?? 0);
}

$canRevenue = function_exists('userHasPermission')
  ? (userHasPermission('revenue.access') || isAdmin() || isDirector() || isSalesDirector())
  : (isAdmin() || isDirector() || isSalesDirector());
$revenueRange = ['invoices' => 0.0, 'expenses' => 0.0];
if ($canRevenue) {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE issue_date BETWEEN ? AND ?");
  if ($stmt) {
    $stmt->bind_param('ss', $sd, $ed);
    $stmt->execute();
    $revenueRange['invoices'] = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0);
    $stmt->close();
  }
  $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS s FROM revenue_manual_expenses WHERE expense_date BETWEEN ? AND ?");
  if ($stmt) {
    $stmt->bind_param('ss', $sd, $ed);
    $stmt->execute();
    $revenueRange['expenses'] = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0);
    $stmt->close();
  }
}
} catch (Throwable $e) {
  error_log('Sales dashboard error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
  http_response_code(500);
  if (function_exists('appFlashError')) {
    appFlashError('Sales dashboard failed to load. Please contact the administrator.');
  }
  $pageTitle = 'Sales Dashboard';
  include __DIR__ . '/../../includes/layout/app_start.php';
  echo '<div class="container-fluid px-0"><div class="alert alert-danger border-0 shadow-sm">Sales dashboard failed to load.</div></div>';
  include __DIR__ . '/../../includes/layout/app_end.php';
  exit;
}
?>

<?php $pageTitle = 'Sales Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 sales-workspace sales-dashboard">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-graph-up-arrow"></i>Sales Performance</div>
      <h3 class="sales-page-title">Sales Dashboard</h3>
      <div class="sales-page-subtitle"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['role'] ?? ''), ($user['job_title'] ?? ''))); ?> · Track pipeline movement, target progress, paid revenue, and follow-up risk from one view.</div>
    </div>
    <div class="sales-page-actions">
      <a href="lead-create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Prospect</a>
      <a href="leads.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1"></i>Pipeline</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm sales-toolbar mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end sales-filter-form" method="get">
        <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
        <div class="col-md-3">
          <label class="form-label small text-muted">Range</label>
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
    </div>
  </div>

  <div class="card mb-3 border-0 shadow-sm sales-toolbar">
    <div class="card-body">
      <form class="row g-2 sales-filter-form" method="get">
        <input type="hidden" name="range_preset" value="<?php echo htmlspecialchars($preset); ?>">
        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($sd); ?>">
        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($ed); ?>">
        <div class="col-lg-10">
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search prospect: company, website, contact, email, LinkedIn">
        </div>
        <div class="col-lg-2 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
        </div>
      </form>

      <?php if ($search !== ''): ?>
        <div class="mt-3 table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Company</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Country</th>
                <th>Industry</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($searchResults)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No matching prospects found.</td></tr>
              <?php else: ?>
                <?php foreach ($searchResults as $r): ?>
                  <tr>
                    <td class="fw-semibold">
                      <a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['company_name'] ?? ''); ?></a>
                    </td>
                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['status'] ?? ''); ?></span></td>
                    <td><?php echo htmlspecialchars($r['priority'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['country'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['industry'] ?? ''); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3 mb-3">
      <div class="col-md-4 col-lg-3">
        <div class="card h-100 border-0 shadow-sm sales-kpi-card kpi-primary">
        <div class="card-body">
            <div class="sales-stat-label">New Prospects (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
            <div class="sales-stat-value"><?php echo (int)$rangeNewProspects; ?></div>
          <div class="text-muted small mt-1">Won: <?php echo (int)$rangeWon; ?> · Lost: <?php echo (int)$rangeLost; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card h-100 border-0 shadow-sm sales-kpi-card kpi-info">
        <div class="card-body">
            <div class="sales-stat-label">Activities (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
            <div class="sales-stat-value"><?php echo (int)$rangeActivities; ?></div>
          <div class="text-muted small mt-1">Follow-up + notes + stage updates</div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card h-100 border-0 shadow-sm sales-kpi-card kpi-success">
        <div class="card-body">
            <div class="sales-stat-label">Pipeline Snapshot</div>
            <div class="sales-stat-value"><?php echo (int)$totalProspects; ?></div>
          <div class="text-muted small mt-1">Opp: <?php echo (int)$opportunities; ?> · Conv: <?php echo htmlspecialchars((string)$conversionRate); ?>%</div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-3">
      <a class="text-decoration-none" href="leads.php?<?php echo htmlspecialchars(http_build_query(['due' => 'Overdue'])); ?>">
        <div class="card h-100 border-danger-subtle sales-kpi-card kpi-danger">
          <div class="card-body">
            <div class="sales-stat-label">Overdue Follow-ups</div>
            <div class="sales-stat-value text-danger"><?php echo (int)$overdueCount; ?></div>
          </div>
        </div>
      </a>
    </div>
    <?php if ($canTasks): ?>
      <div class="col-md-4 col-lg-3">
        <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>">
          <div class="card h-100 border-0 shadow-sm sales-kpi-card">
            <div class="card-body">
              <div class="text-muted small">My Tasks</div>
              <div class="fs-5 fw-semibold">
                <span class="me-2">Pending: <?php echo (int)($taskWidget['pending'] ?? 0); ?></span>
                <span class="me-2">Due: <?php echo (int)($taskWidget['due_today'] ?? 0); ?></span>
                <span class="text-danger">Overdue: <?php echo (int)($taskWidget['overdue'] ?? 0); ?></span>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>
    <div class="col-md-4 col-lg-3">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/hr/attendance'); ?>">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small">Attendance (Today)</div>
            <div class="fs-3 fw-semibold"><?php echo number_format((int)floor(((int)$attendance['work_minutes']) / 60)) . ':' . str_pad((string)(((int)$attendance['work_minutes']) % 60), 2, '0', STR_PAD_LEFT); ?></div>
            <div class="text-muted small mt-1"><?php echo htmlspecialchars($attendance['status'] !== '' ? $attendance['status'] : 'Not started'); ?> · Late: <?php echo number_format((int)$attendance['late_minutes']); ?> min</div>
          </div>
        </div>
      </a>
    </div>
    <?php if ($canRevenue): ?>
      <div class="col-md-4 col-lg-3">
        <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/revenue/revenue-dashboard'); ?>">
          <div class="card h-100 border-0 shadow-sm sales-kpi-card">
            <div class="card-body">
              <div class="text-muted small">Revenue vs Expenses (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
              <div class="fs-5 fw-semibold">₹<?php echo number_format((float)$revenueRange['invoices'], 2); ?> / ₹<?php echo number_format((float)$revenueRange['expenses'], 2); ?></div>
              <div class="text-muted small mt-1">Net: ₹<?php echo number_format(((float)$revenueRange['invoices'] - (float)$revenueRange['expenses']), 2); ?></div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>
    <?php foreach (['New','Contacted','Meeting Scheduled','Proposal Sent','Closed Won','Closed Lost'] as $st): ?>
      <div class="col-md-4 col-lg-3">
        <div class="card h-100 sales-kpi-card">
          <div class="card-body">
            <div class="text-muted small"><?php echo htmlspecialchars($st); ?></div>
            <div class="fs-3 fw-semibold"><?php echo (int)($countsByStatus[$st] ?? 0); ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="card sales-panel border-0 shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold"><?php echo htmlspecialchars($targetsTitle); ?> (<?php echo htmlspecialchars(date('F Y', mktime(0,0,0,$viewMonth,1,$viewYear))); ?>)</div>
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">USD→INR: <?php echo $usdInr !== null ? htmlspecialchars(number_format((float)$usdInr, 2)) : '—'; ?></span>
            <?php if (isAdmin() || isSalesDirector()): ?>
              <a class="btn btn-outline-primary btn-sm" href="targets.php"><i class="bi bi-bullseye me-1"></i>Set Targets</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-lg-4">
              <div class="sales-inline-metric h-100">
                <div class="text-muted small">New Accounts</div>
                <div class="d-flex align-items-end justify-content-between">
                  <div class="fs-4 fw-semibold"><?php echo (int)$achievedAccounts; ?><span class="text-muted fs-6 fw-normal"> / <?php echo (int)$targetNewAccounts; ?></span></div>
                  <div class="text-muted small">Achieved / Target</div>
                </div>
                <?php
                  $pctA = $targetNewAccounts > 0 ? min(100, round(($achievedAccounts / $targetNewAccounts) * 100)) : 0;
                  $deltaA = $targetNewAccounts - $achievedAccounts;
                ?>
                <div class="progress mt-2" style="height: 10px;">
                  <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (int)$pctA; ?>%"></div>
                </div>
                <div class="text-muted small mt-2">
                  Remaining: <?php echo $targetNewAccounts > 0 ? htmlspecialchars((string)$deltaA) : '—'; ?> · Pipeline: Won <?php echo (int)($countsByStatus['Closed Won'] ?? 0); ?> · Proposal <?php echo (int)($countsByStatus['Proposal Sent'] ?? 0); ?> · Meeting <?php echo (int)($countsByStatus['Meeting Scheduled'] ?? 0); ?>
                </div>
              </div>
            </div>
            <div class="col-lg-8">
              <div class="sales-inline-metric h-100">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small">Billable Revenue (Paid Invoices)</div>
                    <div class="fs-4 fw-semibold">
                      $<?php echo htmlspecialchars(number_format((float)($monthPaid['USD'] ?? 0.0), 2)); ?>
                      <span class="text-muted fs-6 fw-normal"> / $<?php echo htmlspecialchars(number_format((float)$targetRevenueUsd, 2)); ?></span>
                    </div>
                  </div>
                  <div class="text-muted small text-end">
                    Commission %: <?php echo htmlspecialchars(number_format((float)$salesCommissionPct, 2)); ?>%<br>
                    Month (INR): ₹<?php echo htmlspecialchars(number_format((float)($monthPaid['INR'] ?? 0.0), 2)); ?>
                  </div>
                </div>
                <?php
                  $pctR = $targetRevenueUsd > 0 ? min(100, round((((float)($monthPaid['USD'] ?? 0.0)) / $targetRevenueUsd) * 100)) : 0;
                  $deltaR = $targetRevenueUsd - (float)($monthPaid['USD'] ?? 0.0);
                ?>
                <div class="progress mt-2" style="height: 10px;">
                  <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (int)$pctR; ?>%"></div>
                </div>
                <div class="text-muted small mt-2">
                  Remaining: <?php echo $targetRevenueUsd > 0 ? htmlspecialchars('$' . number_format((float)$deltaR, 2)) : '—'; ?>
                </div>
                <div class="row g-2 mt-3">
                  <div class="col-md-4">
                    <div class="text-muted small">Paid (<?php echo htmlspecialchars(date('F Y', mktime(0,0,0,$viewMonth,1,$viewYear))); ?>)</div>
                    <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($monthPaid['USD'] ?? 0.0), 2)); ?></div>
                    <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($monthPaid['INR'] ?? 0.0), 2)); ?></div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small">Paid (This Quarter)</div>
                    <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($quarterPaid['USD'] ?? 0.0), 2)); ?></div>
                    <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($quarterPaid['INR'] ?? 0.0), 2)); ?></div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small">Paid (This Year)</div>
                    <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($yearPaid['USD'] ?? 0.0), 2)); ?></div>
                    <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($yearPaid['INR'] ?? 0.0), 2)); ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card h-100 border-0 shadow-sm sales-panel">
        <div class="card-header bg-light fw-semibold">Revenue Generated</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="text-muted small">Overall (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
              <div class="fs-5 fw-semibold">$<?php echo htmlspecialchars(number_format((float)($rangePaid['USD'] ?? 0.0), 2)); ?> <span class="text-muted fs-6 fw-normal">· ₹<?php echo htmlspecialchars(number_format((float)($rangePaid['INR'] ?? 0.0), 2)); ?></span></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Monthly</div>
              <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($monthPaid['USD'] ?? 0.0), 2)); ?></div>
              <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($monthPaid['INR'] ?? 0.0), 2)); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Quarterly</div>
              <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($quarterPaid['USD'] ?? 0.0), 2)); ?></div>
              <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($quarterPaid['INR'] ?? 0.0), 2)); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Yearly</div>
              <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($yearPaid['USD'] ?? 0.0), 2)); ?></div>
              <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($yearPaid['INR'] ?? 0.0), 2)); ?></div>
            </div>
          </div>
          <div class="text-muted small mt-3">Revenue is calculated only from paid invoices (billable revenue).</div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100 border-0 shadow-sm sales-panel">
        <div class="card-header bg-light fw-semibold">Commission</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="text-muted small">Current Month Commission (Paid)</div>
              <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($monthCommPaid['USD'] ?? 0.0), 2)); ?> <span class="text-muted fw-normal">· ₹<?php echo htmlspecialchars(number_format((float)($monthCommPaid['INR'] ?? 0.0), 2)); ?></span></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Pending Commission (This Month)</div>
              <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($monthCommPending['USD'] ?? 0.0), 2)); ?> <span class="text-muted fw-normal">· ₹<?php echo htmlspecialchars(number_format((float)($monthCommPending['INR'] ?? 0.0), 2)); ?></span></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Projected Commission (Unpaid Invoices)</div>
              <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($projectedComm['USD'] ?? 0.0), 2)); ?> <span class="text-muted fw-normal">· ₹<?php echo htmlspecialchars(number_format((float)($projectedComm['INR'] ?? 0.0), 2)); ?></span></div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Commission % (My Rate)</div>
              <div class="fw-semibold"><?php echo htmlspecialchars(number_format((float)$salesCommissionPct, 2)); ?>%</div>
            </div>
          </div>
          <div class="text-muted small mt-3">Commission is calculated as Total Billable Revenue × Commission %.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm sales-panel">
        <div class="card-header bg-light fw-semibold">Revenue By Client (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
        <div class="sales-table-shell">
        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Client</th>
                <th class="text-end">Revenue</th>
                <th class="text-end">Commission</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($revByClient)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No paid invoices in range.</td></tr>
              <?php else: ?>
                <?php foreach ($revByClient as $r): ?>
                  <tr>
                    <td>
                      <span class="sales-cell-primary"><?php echo htmlspecialchars((string)($r['client_name'] ?? '')); ?></span>
                      <span class="sales-cell-secondary">Paid invoice rollup for selected range</span>
                    </td>
                    <td class="text-end">
                      <div class="sales-value-stack">
                        <div class="font-monospace">$<?php echo htmlspecialchars(number_format((float)($r['usd_rev'] ?? 0.0), 2)); ?></div>
                        <div class="text-muted small font-monospace">₹<?php echo htmlspecialchars(number_format((float)($r['inr_rev'] ?? 0.0), 2)); ?></div>
                      </div>
                    </td>
                    <td class="text-end">
                      <div class="sales-value-stack">
                        <div class="font-monospace">$<?php echo htmlspecialchars(number_format((float)($r['usd_comm'] ?? 0.0), 2)); ?></div>
                        <div class="text-muted small font-monospace">₹<?php echo htmlspecialchars(number_format((float)($r['inr_comm'] ?? 0.0), 2)); ?></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="sales-table-note">Shows billable paid-invoice revenue and paid commission grouped by client for the active range.</div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm sales-panel">
        <div class="card-header bg-light fw-semibold">Revenue By Campaign (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
        <div class="sales-table-shell">
        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Campaign</th>
                <th class="text-end">Revenue</th>
                <th class="text-end">Commission</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($revByCampaign)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No paid invoices in range.</td></tr>
              <?php else: ?>
                <?php foreach ($revByCampaign as $r): ?>
                  <tr>
                    <td>
                      <span class="sales-cell-primary"><?php echo htmlspecialchars((string)($r['campaign_name'] ?? '')); ?></span>
                      <span class="sales-cell-secondary">Paid invoice rollup for selected range</span>
                    </td>
                    <td class="text-end">
                      <div class="sales-value-stack">
                        <div class="font-monospace">$<?php echo htmlspecialchars(number_format((float)($r['usd_rev'] ?? 0.0), 2)); ?></div>
                        <div class="text-muted small font-monospace">₹<?php echo htmlspecialchars(number_format((float)($r['inr_rev'] ?? 0.0), 2)); ?></div>
                      </div>
                    </td>
                    <td class="text-end">
                      <div class="sales-value-stack">
                        <div class="font-monospace">$<?php echo htmlspecialchars(number_format((float)($r['usd_comm'] ?? 0.0), 2)); ?></div>
                        <div class="text-muted small font-monospace">₹<?php echo htmlspecialchars(number_format((float)($r['inr_comm'] ?? 0.0), 2)); ?></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="sales-table-note">Keeps revenue and earned commission together so campaign-wise contribution is easier to compare.</div>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($topRevenueUsers) || !empty($topCommissionUsers)): ?>
    <div class="row g-3 mb-3">
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm sales-panel">
          <div class="card-header bg-light fw-semibold">Top Revenue Generators (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
          <div class="table-responsive sales-table-responsive">
            <table class="table table-sm mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th class="text-end">#</th>
                  <th>User</th>
                  <th class="text-end">Revenue</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($topRevenueUsers)): ?>
                  <tr><td colspan="3" class="text-center text-muted py-3">No revenue data.</td></tr>
                <?php else: ?>
                  <?php foreach ($topRevenueUsers as $i => $r): ?>
                    <tr>
                      <td class="text-end font-monospace"><?php echo (int)($i + 1); ?></td>
                      <td>
                        <span class="sales-cell-primary"><?php echo htmlspecialchars(formatUserNameWithRole((string)($r['full_name'] ?? ''), (string)($r['role'] ?? ''), (string)($r['job_title'] ?? ''))); ?></span>
                        <span class="sales-cell-secondary">Ranked by paid revenue in range</span>
                      </td>
                      <td class="text-end">
                        <div class="sales-value-stack">
                          <div class="font-monospace">$<?php echo htmlspecialchars(number_format((float)($r['usd_rev'] ?? 0.0), 2)); ?></div>
                          <div class="text-muted small font-monospace">₹<?php echo htmlspecialchars(number_format((float)($r['inr_rev'] ?? 0.0), 2)); ?></div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm sales-panel">
          <div class="card-header bg-light fw-semibold">Top Commission Earners (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
          <div class="table-responsive sales-table-responsive">
            <table class="table table-sm mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th class="text-end">#</th>
                  <th>User</th>
                  <th class="text-end">Commission</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($topCommissionUsers)): ?>
                  <tr><td colspan="3" class="text-center text-muted py-3">No commission data.</td></tr>
                <?php else: ?>
                  <?php foreach ($topCommissionUsers as $i => $r): ?>
                    <tr>
                      <td class="text-end font-monospace"><?php echo (int)($i + 1); ?></td>
                      <td>
                        <span class="sales-cell-primary"><?php echo htmlspecialchars(formatUserNameWithRole((string)($r['full_name'] ?? ''), (string)($r['role'] ?? ''), (string)($r['job_title'] ?? ''))); ?></span>
                        <span class="sales-cell-secondary">Ranked by paid commission in range</span>
                      </td>
                      <td class="text-end">
                        <div class="sales-value-stack">
                          <div class="font-monospace">$<?php echo htmlspecialchars(number_format((float)($r['usd_comm'] ?? 0.0), 2)); ?></div>
                          <div class="text-muted small font-monospace">₹<?php echo htmlspecialchars(number_format((float)($r['inr_comm'] ?? 0.0), 2)); ?></div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card sales-panel">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Recent Activity</div>
          <a class="small text-decoration-none" href="leads.php">View pipeline</a>
        </div>
        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>When</th>
                <th>Prospect</th>
                <th>Status</th>
                <th>Comment</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No activity yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recent as $a): ?>
                  <tr>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($a['created_at']))); ?></td>
                    <td>
                      <span class="sales-cell-primary">
                      <a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$a['sales_lead_id']; ?>"><?php echo htmlspecialchars($a['company_name'] ?? ''); ?></a>
                      </span>
                    </td>
                    <td><?php echo $a['status'] ? '<span class="badge bg-secondary">'.htmlspecialchars($a['status']).'</span>' : '<span class="text-muted">—</span>'; ?></td>
                    <td class="small"><?php echo htmlspecialchars(mb_strimwidth((string)($a['comment'] ?? ''), 0, 80, '…')); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($a['created_by_name'] ?? ''), ($a['created_by_role'] ?? ''), ($a['created_by_job_title'] ?? ''))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card mb-3 sales-panel">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Overdue Follow-ups</div>
          <a class="small text-decoration-none" href="leads.php?<?php echo htmlspecialchars(http_build_query(['due' => 'Overdue'])); ?>">Open list</a>
        </div>
        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Prospect</th>
                <th>Due</th>
                <th>Owner</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($overdueLeads)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No overdue follow-ups.</td></tr>
              <?php else: ?>
                <?php foreach ($overdueLeads as $o): ?>
                  <tr>
                    <td class="fw-semibold">
                      <a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars($o['company_name'] ?? ''); ?></a>
                      <div class="text-muted small"><?php echo htmlspecialchars($o['status'] ?? ''); ?> · <?php echo htmlspecialchars($o['priority'] ?? ''); ?></div>
                    </td>
                    <td class="text-danger small"><?php echo !empty($o['next_follow_up_at']) ? htmlspecialchars(date('d M, H:i', strtotime((string)$o['next_follow_up_at']))) : '—'; ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($o['owner_name'] ?? ''), ($o['owner_role'] ?? ''), ($o['owner_job_title'] ?? ''))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card sales-panel">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Team Metrics (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
          <span class="text-muted small">Top/Bottom by won → activities</span>
        </div>
        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Owner</th>
                <th class="text-end">New</th>
                <th class="text-end">Activities</th>
                <th class="text-end">Won</th>
                <th class="text-end">Lost</th>
                <th class="text-end">Conv.</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($teamRange)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No data yet.</td></tr>
              <?php else: ?>
                <?php foreach ($teamRange as $t): ?>
                  <?php
                    $p = (int)($t['new_prospects'] ?? 0);
                    $w = (int)($t['won'] ?? 0);
                    $conv = $p > 0 ? round(($w / $p) * 100, 1) : 0.0;
                  ?>
                  <tr>
                    <td>
                      <span class="sales-cell-primary"><?php echo htmlspecialchars($t['full_name'] ?? ''); ?></span>
                      <span class="sales-cell-secondary">Range contribution</span>
                    </td>
                    <td class="text-end fw-semibold"><?php echo $p; ?></td>
                    <td class="text-end"><?php echo (int)($t['activities'] ?? 0); ?></td>
                    <td class="text-end"><?php echo (int)($t['won'] ?? 0); ?></td>
                    <td class="text-end"><?php echo (int)($t['lost'] ?? 0); ?></td>
                    <td class="text-end"><?php echo htmlspecialchars((string)$conv); ?>%</td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer bg-white">
          <div class="row g-2">
            <div class="col-6">
              <div class="text-muted small mb-2"><i class="bi bi-trophy me-1"></i>Top Performers</div>
              <?php if (empty($topTeam)): ?>
                <div class="text-muted small">No data in range.</div>
              <?php else: ?>
                <div class="performer-mini-list">
                <?php foreach ($topTeam as $idx => $r): ?>
                                    <?php $podium = getPerformerPodiumMeta((int)$idx); ?>
                  <div class="performer-mini-item">
                    <div class="performer-mini-main">
                      <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                      <div class="performer-mini-copy">
                        <div class="performer-mini-name"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></div>
                        <div class="performer-mini-meta"><?php echo htmlspecialchars($podium['place']); ?></div>
                      </div>
                    </div>
                    <span class="performer-mini-value">Won <?php echo (int)($r['won'] ?? 0); ?> · Act <?php echo (int)($r['activities'] ?? 0); ?></span>
                  </div>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-6">
              <div class="text-muted small mb-2"><i class="bi bi-graph-down-arrow me-1"></i>Bottom Performers</div>
              <?php if (empty($bottomTeam)): ?>
                <div class="text-muted small">No data in range.</div>
              <?php else: ?>
                <div class="performer-mini-list">
                <?php foreach ($bottomTeam as $idx => $r): ?>
                  <?php $podium = getPerformerPodiumMeta((int)$idx, 'bottom'); ?>
                  <div class="performer-mini-item">
                    <div class="performer-mini-main">
                      <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                      <div class="performer-mini-copy">
                        <div class="performer-mini-name"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></div>
                        <div class="performer-mini-meta"><?php echo htmlspecialchars($podium['place']); ?></div>
                      </div>
                    </div>
                    <span class="performer-mini-value">Won <?php echo (int)($r['won'] ?? 0); ?> · Act <?php echo (int)($r['activities'] ?? 0); ?></span>
                  </div>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-3 sales-panel">
        <div class="card-header fw-semibold">Pipeline View</div>
        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Status</th>
                <th class="text-end">Count</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($countsByStatus as $st => $cnt): ?>
                <tr>
                  <td><?php echo htmlspecialchars($st); ?></td>
                  <td class="text-end fw-semibold"><?php echo (int)$cnt; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
