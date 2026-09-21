<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','qa','qa_agent','qa_manager','qa_director']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts($userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$todayRange = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => $opsNow->format('Y-m-d 00:00:00'), 'end' => $opsNow->format('Y-m-d 23:59:59')];
$monthRange = function_exists('opsMonthRange') ? opsMonthRange() : ['start' => $opsNow->format('Y-m-01 00:00:00'), 'end' => $opsNow->format('Y-m-t 23:59:59')];
$todayStart = (string)($todayRange['start'] ?? '1970-01-01 00:00:00');
$todayEnd = (string)($todayRange['end'] ?? '2999-12-31 23:59:59');
$monthStart = (string)($monthRange['start'] ?? '1970-01-01 00:00:00');
$monthEnd = (string)($monthRange['end'] ?? '2999-12-31 23:59:59');

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? $monthStart);
$rangeEnd = (string)($range['end'] ?? $monthEnd);
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$leadQaUpdatedAtExpr = static function(string $alias = 'l'): string {
  $column = trim($alias) !== '' ? rtrim($alias, '.') . '.' : '';
  return "{$column}qa_updated_at";
};

$visible = getQaVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
$stats = getQaStatsScoped($visible, $todayStart, $todayEnd);
$conn = getDbConnection();

// Define QA status filters
$qaFinalStatuses = ['Qualified', 'Disqualified', 'Duplicate/Supp'];
$qaPendingStatuses = ['Pending', 'In Progress', 'Rework Needed', 'Reopened', 'Rectified'];
$qaProductivityStatuses = ['Qualified', 'Disqualified'];

// SQL for final statuses (reviewed leads)
$qaFinalStatusesSql = "'" . implode("','", array_map('addslashes', $qaFinalStatuses)) . "'";
// SQL for productivity counts (qualified/disqualified)
$qaProductivityStatusesSql = "'" . implode("','", array_map('addslashes', $qaProductivityStatuses)) . "'";
// SQL for pending leads (not yet reviewed)
$qaPendingCardSql = "(l.id IS NOT NULL AND (l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status NOT IN ($qaFinalStatusesSql)))";

$qaScopeIds = $visible === null ? [] : array_values(array_map('intval', array_keys($visible)));
$fetchQaStatusSnapshot = static function () use ($conn, $qaScopeIds): array {
  $counts = getQaCountBuckets();
  $rawCounts = [
    'Pending QA' => 0,
    'Blank' => 0,
    'Approved' => 0,
  ];
  if (empty($qaScopeIds)) {
    $stmt = $conn->prepare("SELECT l.qa_status, COUNT(*) AS cnt FROM leads l GROUP BY l.qa_status");
  } else {
    $in = implode(',', array_fill(0, count($qaScopeIds), '?'));
    $stmt = $conn->prepare("SELECT l.qa_status, COUNT(*) AS cnt FROM leads l WHERE l.campaign_id IN ($in) GROUP BY l.qa_status");
  }
  if (!$stmt) return ['normalized' => $counts, 'raw' => $rawCounts];
  if (!empty($qaScopeIds)) mysqliBindParams($stmt, str_repeat('i', count($qaScopeIds)), $qaScopeIds);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();
  foreach ($rows as $row) {
    $qaStatus = $row['qa_status'] ?? null;
    $count = (int)($row['cnt'] ?? 0);
    if ($count <= 0) continue;
    accumulateQaStatusCount($counts, $qaStatus, $count);
    $trimmedStatus = $qaStatus === null ? '' : trim((string)$qaStatus);
    $lowerStatus = strtolower($trimmedStatus);
    if ($trimmedStatus === '') {
      $rawCounts['Blank'] += $count;
    } elseif ($lowerStatus === 'pending qa') {
      $rawCounts['Pending QA'] += $count;
    } elseif ($lowerStatus === 'approved') {
      $rawCounts['Approved'] += $count;
    }
  }
  return ['normalized' => $counts, 'raw' => $rawCounts];
};
$qaStatusSnapshot = $fetchQaStatusSnapshot();
$qaStatusCounts = (array)($qaStatusSnapshot['normalized'] ?? []);
$qaRawStatusCounts = (array)($qaStatusSnapshot['raw'] ?? []);

// Calculate pending count (sum of all non-final statuses)
$pendingOnlyCount = 0;
foreach ($qaPendingStatuses as $status) {
  $pendingOnlyCount += (int)($qaStatusCounts[$status] ?? 0);
}
$pendingOnlyCount += (int)($qaRawStatusCounts['Pending QA'] ?? 0); // Add legacy 'Pending QA'
$pendingOnlyCount += (int)($qaRawStatusCounts['Blank'] ?? 0); // Add blank/NULL statuses

$pendingStatusBreakdown = [
  ['label' => 'Reopened', 'count' => (int)($qaStatusCounts['Reopened'] ?? 0)],
  ['label' => 'Pending QA', 'count' => (int)($qaRawStatusCounts['Pending QA'] ?? 0)],
  ['label' => 'Blank', 'count' => (int)($qaRawStatusCounts['Blank'] ?? 0)],
  ['label' => 'In Progress', 'count' => (int)($qaStatusCounts['In Progress'] ?? 0)],
  ['label' => 'Rework Needed', 'count' => (int)($qaStatusCounts['Rework Needed'] ?? 0)],
  ['label' => 'Rectified', 'count' => (int)($qaStatusCounts['Rectified'] ?? 0)],
  ['label' => 'Duplicate/Supp', 'count' => (int)($qaStatusCounts['Duplicate/Supp'] ?? 0)],
];
// Fetch reviewed summary from leads table based on qa_reviewed_by and qa_updated_at
// Use timezone-aware conversion to match admin dashboard behavior
$fetchReviewedSummary = static function (?string $startDate, ?string $endDate) use ($conn, $qaScopeIds, $qaProductivityStatusesSql, $leadQaUpdatedAtExpr): array {
  $summary = ['reviewed' => 0, 'qualified' => 0, 'disqualified' => 0];
  $dateSql = '';
  $types = '';
  $params = [];
  $qaUpdatedAtExpr = $leadQaUpdatedAtExpr('l');
  
  if ($startDate !== null && $endDate !== null) {
    // Use timezone-aware datetime range directly from dfDashboardRange
    $dateSql = "
      SUM(CASE WHEN $qaUpdatedAtExpr BETWEEN ? AND ? AND l.qa_status IN ($qaProductivityStatusesSql) AND l.qa_reviewed_by IS NOT NULL THEN 1 ELSE 0 END) AS reviewed,
      SUM(CASE WHEN $qaUpdatedAtExpr BETWEEN ? AND ? AND l.qa_status = 'Qualified' AND l.qa_reviewed_by IS NOT NULL THEN 1 ELSE 0 END) AS qualified,
      SUM(CASE WHEN $qaUpdatedAtExpr BETWEEN ? AND ? AND l.qa_status = 'Disqualified' AND l.qa_reviewed_by IS NOT NULL THEN 1 ELSE 0 END) AS disqualified
    ";
    $types .= 'ssssss';
    array_push($params, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);
  } else {
    $dateSql = "
      SUM(CASE WHEN l.qa_status IN ($qaProductivityStatusesSql) AND l.qa_reviewed_by IS NOT NULL THEN 1 ELSE 0 END) AS reviewed,
      SUM(CASE WHEN l.qa_status = 'Qualified' AND l.qa_reviewed_by IS NOT NULL THEN 1 ELSE 0 END) AS qualified,
      SUM(CASE WHEN l.qa_status = 'Disqualified' AND l.qa_reviewed_by IS NOT NULL THEN 1 ELSE 0 END) AS disqualified
    ";
  }

  if (empty($qaScopeIds)) {
    $stmt = $conn->prepare("SELECT $dateSql FROM leads l");
  } else {
    $in = implode(',', array_fill(0, count($qaScopeIds), '?'));
    $stmt = $conn->prepare("SELECT $dateSql FROM leads l WHERE l.campaign_id IN ($in)");
    $types .= str_repeat('i', count($qaScopeIds));
    $params = array_merge($params, $qaScopeIds);
  }
  if (!$stmt) return $summary;
  if ($types !== '') mysqliBindParams($stmt, $types, $params);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();
  $summary['reviewed'] = (int)($row['reviewed'] ?? 0);
  $summary['qualified'] = (int)($row['qualified'] ?? 0);
  $summary['disqualified'] = (int)($row['disqualified'] ?? 0);
  return $summary;
};
// Fetch reviewed status breakdown from leads table based on qa_updated_at
// Use timezone-aware conversion to match admin dashboard behavior
$fetchReviewedStatusBreakdown = static function (string $startDate, string $endDate) use ($conn, $qaScopeIds, $leadQaUpdatedAtExpr): array {
  $statuses = ['Qualified', 'Disqualified', 'In Progress', 'Reopened', 'Rework Needed', 'Rectified'];
  $counts = array_fill_keys($statuses, 0);
  $qaUpdatedAtExpr = $leadQaUpdatedAtExpr('l');
  if (empty($qaScopeIds)) {
    $stmt = $conn->prepare("
      SELECT l.qa_status, COUNT(*) AS cnt
      FROM leads l
      WHERE $qaUpdatedAtExpr BETWEEN ? AND ?
        AND l.qa_status IN ('Qualified','Disqualified','In Progress','Reopened','Rework Needed','Rectified')
        AND l.qa_reviewed_by IS NOT NULL
      GROUP BY l.qa_status
    ");
    if ($stmt) {
      $stmt->bind_param('ss', $startDate, $endDate);
    }
  } else {
    $in = implode(',', array_fill(0, count($qaScopeIds), '?'));
    $stmt = $conn->prepare("
      SELECT l.qa_status, COUNT(*) AS cnt
      FROM leads l
      WHERE $qaUpdatedAtExpr BETWEEN ? AND ?
        AND l.campaign_id IN ($in)
        AND l.qa_status IN ('Qualified','Disqualified','In Progress','Reopened','Rework Needed','Rectified')
        AND l.qa_reviewed_by IS NOT NULL
      GROUP BY l.qa_status
    ");
    if ($stmt) {
      $types = 'ss' . str_repeat('i', count($qaScopeIds));
      $params = [$startDate, $endDate, ...$qaScopeIds];
      mysqliBindParams($stmt, $types, $params);
    }
  }
  if (empty($stmt)) return $counts;
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();
  foreach ($rows as $row) {
    $status = trim((string)($row['qa_status'] ?? ''));
    if ($status !== '' && array_key_exists($status, $counts)) {
      $counts[$status] = (int)($row['cnt'] ?? 0);
    }
  }
  return $counts;
};

$todayReviewed = $fetchReviewedSummary($todayStart, $todayEnd);
$rangeReviewed = $fetchReviewedSummary($rangeStart, $rangeEnd);
$overallReviewed = $fetchReviewedSummary(null, null);
$todayReviewedStatusBreakdown = $fetchReviewedStatusBreakdown($todayStart, $todayEnd);

// Fetch recent reviewed leads from leads table
// Use timezone-aware conversion to match admin dashboard behavior
$recentReviewed = [];
$qaUpdatedAtExpr = $leadQaUpdatedAtExpr('l');
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT l.id, l.lead_id, l.company_name, l.qa_status, l.qa_updated_at,
           u.full_name AS reviewer_name, u.role AS reviewer_role, u.job_title AS reviewer_job_title,
           c.name AS campaign_name
    FROM leads l
    LEFT JOIN users u ON u.id = l.qa_reviewed_by
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    WHERE l.qa_status IN ($qaFinalStatusesSql) 
      AND l.qa_reviewed_by IS NOT NULL
      AND $qaUpdatedAtExpr BETWEEN ? AND ?
    ORDER BY l.qa_updated_at DESC
    LIMIT 10
  ");
  if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $recentReviewed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
  }
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT l.id, l.lead_id, l.company_name, l.qa_status, l.qa_updated_at,
             u.full_name AS reviewer_name, u.role AS reviewer_role, u.job_title AS reviewer_job_title,
             c.name AS campaign_name
      FROM leads l
      LEFT JOIN users u ON u.id = l.qa_reviewed_by
      LEFT JOIN campaigns c ON c.id = l.campaign_id
      WHERE l.qa_status IN ($qaFinalStatusesSql) 
        AND l.qa_reviewed_by IS NOT NULL
        AND l.campaign_id IN ($in)
        AND $qaUpdatedAtExpr BETWEEN ? AND ?
      ORDER BY l.qa_updated_at DESC
      LIMIT 10
    ");
    if ($stmt) {
      $types = str_repeat('i', count($ids)) . 'ss';
      $params = array_merge($ids, [$rangeStart, $rangeEnd]);
      mysqliBindParams($stmt, $types, $params);
      $stmt->execute();
      $recentReviewed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
    }
  }
}

$pending = [];
if ($visible === null) {
  $rs = $conn->query("
    SELECT l.id, l.lead_id, l.company_name, l.created_at,
           c.name AS campaign_name,
           u.full_name AS agent_name, u.role AS agent_role, u.job_title AS agent_job_title
    FROM leads l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN users u ON u.id = l.agent_id
    WHERE $qaPendingCardSql
    ORDER BY $leadCreatedExpr DESC
    LIMIT 10
  ");
  if ($rs) $pending = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT l.id, l.lead_id, l.company_name, l.created_at,
             c.name AS campaign_name,
             u.full_name AS agent_name, u.role AS agent_role, u.job_title AS agent_job_title
      FROM leads l
      LEFT JOIN campaigns c ON c.id = l.campaign_id
      LEFT JOIN users u ON u.id = l.agent_id
      WHERE $qaPendingCardSql
        AND l.campaign_id IN ($in)
      ORDER BY $leadCreatedExpr DESC
      LIMIT 10
    ");
    if ($stmt) {
      mysqliBindParams($stmt, str_repeat('i', count($ids)), $ids);
      $stmt->execute();
      $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
    }
  }
}

$campaignWorkload = [];
$reviewerPerf = [];
$approvalRatio = ['qualified' => 0, 'disqualified' => 0];

// First get all campaign basic info (names and codes)
$campaignBasicInfo = [];
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT c.id, c.name, d.code
    FROM campaigns c
    JOIN campaign_details d ON d.campaign_id = c.id
  ");
  if ($stmt) {
    $stmt->execute();
    $campaignData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($campaignData as $row) {
      $campaignBasicInfo[$row['id']] = [
        'name' => $row['name'],
        'code' => $row['code']
      ];
    }
  }
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT c.id, c.name, d.code
      FROM campaigns c
      JOIN campaign_details d ON d.campaign_id = c.id
      WHERE c.id IN ($in)
    ");
    if ($stmt) {
      $types = str_repeat('i', count($ids));
      mysqliBindParams($stmt, $types, $ids);
      $stmt->execute();
      $campaignData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
      foreach ($campaignData as $row) {
        $campaignBasicInfo[$row['id']] = [
          'name' => $row['name'],
          'code' => $row['code']
        ];
      }
    }
  }
}

// Get pending counts (current status from leads table)
$pendingCounts = [];
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT l.campaign_id,
           SUM(CASE WHEN $qaPendingCardSql THEN 1 ELSE 0 END) AS pending
    FROM leads l
    GROUP BY l.campaign_id
  ");
  if ($stmt) {
    $stmt->execute();
    $pendingData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($pendingData as $row) {
      $pendingCounts[$row['campaign_id']] = (int)$row['pending'];
    }
  }
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT l.campaign_id,
             SUM(CASE WHEN $qaPendingCardSql THEN 1 ELSE 0 END) AS pending
      FROM leads l
      WHERE l.campaign_id IN ($in)
      GROUP BY l.campaign_id
    ");
    if ($stmt) {
      $types = str_repeat('i', count($ids));
      mysqliBindParams($stmt, $types, $ids);
      $stmt->execute();
      $pendingData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
      foreach ($pendingData as $row) {
        $pendingCounts[$row['campaign_id']] = (int)$row['pending'];
      }
    }
  }
}

// Get reviewed, qualified, disqualified from leads table based on qa_updated_at
// Use timezone-aware conversion to match admin dashboard behavior
$reviewCounts = [];
$qaUpdatedAtExpr = $leadQaUpdatedAtExpr('l');
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT l.campaign_id,
           COUNT(*) AS reviewed,
           SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
           SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
    FROM leads l
    WHERE $qaUpdatedAtExpr BETWEEN ? AND ?
      AND l.qa_status IN ($qaProductivityStatusesSql)
      AND l.qa_reviewed_by IS NOT NULL
    GROUP BY l.campaign_id
  ");
  if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $reviewData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($reviewData as $row) {
      $reviewCounts[$row['campaign_id']] = [
        'reviewed' => (int)$row['reviewed'],
        'qualified' => (int)$row['qualified'],
        'disqualified' => (int)$row['disqualified']
      ];
    }
  }
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT l.campaign_id,
             COUNT(*) AS reviewed,
             SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
             SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
      FROM leads l
      WHERE l.campaign_id IN ($in)
        AND $qaUpdatedAtExpr BETWEEN ? AND ?
        AND l.qa_status IN ($qaProductivityStatusesSql)
        AND l.qa_reviewed_by IS NOT NULL
      GROUP BY l.campaign_id
    ");
    if ($stmt) {
      $types = str_repeat('i', count($ids)) . 'ss';
      $params = [...$ids, $rangeStart, $rangeEnd];
      mysqliBindParams($stmt, $types, $params);
      $stmt->execute();
      $reviewData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
      foreach ($reviewData as $row) {
        $reviewCounts[$row['campaign_id']] = [
          'reviewed' => (int)$row['reviewed'],
          'qualified' => (int)$row['qualified'],
          'disqualified' => (int)$row['disqualified']
        ];
      }
    }
  }
}

// Merge pending and review counts, including campaigns that only have review data
$allCampaignIds = array_unique(array_merge(array_keys($pendingCounts), array_keys($reviewCounts)));
$campaignWorkload = [];
foreach ($allCampaignIds as $campaignId) {
  $basicInfo = $campaignBasicInfo[$campaignId] ?? ['name' => 'Unknown Campaign', 'code' => ''];
  $pendingCount = $pendingCounts[$campaignId] ?? 0;
  $reviewData = $reviewCounts[$campaignId] ?? ['reviewed' => 0, 'qualified' => 0, 'disqualified' => 0];
  
  $campaignWorkload[] = [
    'id' => $campaignId,
    'name' => $basicInfo['name'],
    'code' => $basicInfo['code'],
    'pending' => $pendingCount,
    'reviewed' => $reviewData['reviewed'],
    'qualified' => $reviewData['qualified'],
    'disqualified' => $reviewData['disqualified'],
  ];
}

// Now sort campaignWorkload
usort($campaignWorkload, function($a, $b) {
  if ($a['reviewed'] != $b['reviewed']) return $b['reviewed'] - $a['reviewed'];
  if ($a['pending'] != $b['pending']) return $b['pending'] - $a['pending'];
  return strcmp($a['name'], $b['name']);
});

// Get reviewer performance from leads table based on qa_reviewed_by
// Use timezone-aware conversion to match admin dashboard behavior
$qaRoles = ['qa_director','qa_manager','qa_agent','qa'];
$qaUpdatedAtExpr = $leadQaUpdatedAtExpr('l');
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT u.id,
           COALESCE(NULLIF(TRIM(u.full_name), ''), CONCAT('User #', l.qa_reviewed_by)) AS full_name,
           COUNT(*) AS reviewed,
           SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
           SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
    FROM leads l
    LEFT JOIN users u ON u.id = l.qa_reviewed_by
    WHERE l.qa_reviewed_by IS NOT NULL
      AND $qaUpdatedAtExpr BETWEEN ? AND ?
      AND l.qa_status IN ($qaProductivityStatusesSql)
      AND (u.id IS NULL OR (u.role NOT LIKE 'client_%' AND u.role NOT LIKE 'vendor_%'))
    GROUP BY u.id
    ORDER BY reviewed DESC, full_name ASC
  ");
  $types = 'ss';
  $params = [$rangeStart, $rangeEnd];
  if ($stmt) {
    mysqliBindParams($stmt, $types, $params);
  }
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT u.id,
             COALESCE(NULLIF(TRIM(u.full_name), ''), CONCAT('User #', l.qa_reviewed_by)) AS full_name,
             COUNT(*) AS reviewed,
             SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
             SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
      FROM leads l
      LEFT JOIN users u ON u.id = l.qa_reviewed_by
      WHERE l.qa_reviewed_by IS NOT NULL
        AND l.campaign_id IN ($in)
        AND $qaUpdatedAtExpr BETWEEN ? AND ?
        AND l.qa_status IN ($qaProductivityStatusesSql)
        AND (u.id IS NULL OR (u.role NOT LIKE 'client_%' AND u.role NOT LIKE 'vendor_%'))
      GROUP BY u.id
      ORDER BY reviewed DESC, full_name ASC
    ");
    $types = str_repeat('i', count($ids)) . 'ss';
    $params = [...$ids, $rangeStart, $rangeEnd];
    if ($stmt) {
      mysqliBindParams($stmt, $types, $params);
    }
  } else {
    $stmt = null;
  }
}
if (isset($stmt) && $stmt) {
  $stmt->execute();
  $reviewerPerf = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();
}

// Remove redundant approval ratio calculation - will be calculated from reviewerPerf later

$campaignWorkloadTotals = ['pending' => 0, 'reviewed' => 0, 'qualified' => 0, 'disqualified' => 0];
foreach ($campaignWorkload as &$campaignRow) {
  $campaignRow['pending'] = (int)($campaignRow['pending'] ?? 0);
  $campaignRow['reviewed'] = (int)($campaignRow['reviewed'] ?? 0);
  $campaignRow['qualified'] = (int)($campaignRow['qualified'] ?? 0);
  $campaignRow['disqualified'] = (int)($campaignRow['disqualified'] ?? 0);
  $campaignWorkloadTotals['pending'] += $campaignRow['pending'];
  $campaignWorkloadTotals['reviewed'] += $campaignRow['reviewed'];
  $campaignWorkloadTotals['qualified'] += $campaignRow['qualified'];
  $campaignWorkloadTotals['disqualified'] += $campaignRow['disqualified'];
}
unset($campaignRow);

$reviewerPerfTotals = ['reviewed' => 0, 'qualified' => 0, 'disqualified' => 0];
foreach ($reviewerPerf as &$reviewerRow) {
  $reviewerRow['reviewed'] = (int)($reviewerRow['reviewed'] ?? 0);
  $reviewerRow['qualified'] = (int)($reviewerRow['qualified'] ?? 0);
  $reviewerRow['disqualified'] = (int)($reviewerRow['disqualified'] ?? 0);
  $reviewerPerfTotals['reviewed'] += $reviewerRow['reviewed'];
  $reviewerPerfTotals['qualified'] += $reviewerRow['qualified'];
  $reviewerPerfTotals['disqualified'] += $reviewerRow['disqualified'];
}
unset($reviewerRow);
// Calculate approval ratio from reviewer performance totals
$approvalRatio = [
  'qualified' => (int)$reviewerPerfTotals['qualified'],
  'disqualified' => (int)$reviewerPerfTotals['disqualified'],
];

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
$liveCampaignRows = [];
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT c.id, d.client_id, d.client_code, d.total_leads
    FROM campaigns c
    JOIN campaign_details d ON d.campaign_id = c.id
    WHERE d.status = 'Live'
    ORDER BY COALESCE(NULLIF(TRIM(d.client_code), ''), 'ZZZ'), c.name ASC
  ");
} else {
  $ids = array_values(array_filter($qaScopeIds, static fn($id): bool => (int)$id > 0));
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT c.id, d.client_id, d.client_code, d.total_leads
      FROM campaigns c
      JOIN campaign_details d ON d.campaign_id = c.id
      WHERE d.status = 'Live'
        AND c.id IN ($in)
      ORDER BY COALESCE(NULLIF(TRIM(d.client_code), ''), 'ZZZ'), c.name ASC
    ");
    if ($stmt) {
      mysqliBindParams($stmt, str_repeat('i', count($ids)), $ids);
    }
  } else {
    $stmt = null;
  }
}
if (!empty($stmt)) {
  $stmt->execute();
  $liveCampaignRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();
}
$liveCampaignIds = array_values(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $liveCampaignRows), static fn($id): bool => $id > 0));
$leadMetricsByCampaign = [];
if (!empty($liveCampaignIds)) {
  $in = implode(',', array_fill(0, count($liveCampaignIds), '?'));
  $qaUpdatedAtExpr = $leadQaUpdatedAtExpr('l');
  $stmt = $conn->prepare("
    SELECT l.campaign_id,
           SUM(CASE WHEN $qaUpdatedAtExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS generated_leads,
           SUM(CASE WHEN l.qa_status = 'Qualified' AND $qaUpdatedAtExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS qualified_leads,
           SUM(CASE WHEN l.qa_status = 'Disqualified' AND $qaUpdatedAtExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS disqualified_leads,
           SUM(CASE WHEN l.qa_status = 'Qualified' AND $qaUpdatedAtExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS new_leads,
           SUM(CASE WHEN l.client_delivery_status = 'Delivered' AND $qaUpdatedAtExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS delivered,
           SUM(CASE WHEN l.qa_status = 'Qualified' AND l.client_delivery_status = 'Pending' AND $qaUpdatedAtExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) AS pending
    FROM leads l
    JOIN campaign_details d ON d.campaign_id = l.campaign_id
    WHERE l.campaign_id IN ($in)
      AND (d.start_date IS NULL OR DATE($leadCreatedExpr) >= d.start_date)
      AND (d.end_date IS NULL OR DATE($leadCreatedExpr) <= d.end_date)
    GROUP BY l.campaign_id
  ");
  if ($stmt) {
    $types = 'ssssssssssss' . str_repeat('i', count($liveCampaignIds));
    $params = [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd, ...$liveCampaignIds];
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

$attendance = ['status' => '', 'work_minutes' => 0, 'late_minutes' => 0];
$todayDate = substr($todayStart, 0, 10);
$attendanceSnapshot = function_exists('hrGetAttendanceSnapshot') ? hrGetAttendanceSnapshot($userId, $todayDate, true) : [];
$attendanceRow = is_array($attendanceSnapshot['row'] ?? null) ? $attendanceSnapshot['row'] : [];
$attendance['status'] = (string)($attendanceSnapshot['status'] ?? ($attendanceRow['status'] ?? ''));
$attendance['work_minutes'] = (int)($attendanceSnapshot['display_work_minutes'] ?? ($attendanceRow['work_minutes'] ?? 0));
$attendance['late_minutes'] = (int)($attendanceRow['late_minutes'] ?? 0);

$topReviewers = [];
$bottomReviewers = [];
$tmp = array_values(array_filter($reviewerPerf, fn($r) => (int)($r['reviewed'] ?? 0) > 0));
usort($tmp, fn($a,$b) => ((int)($b['reviewed'] ?? 0) <=> (int)($a['reviewed'] ?? 0)) ?: strcmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? '')));
$topReviewers = array_slice($tmp, 0, 3);
usort($tmp, fn($a,$b) => ((int)($a['reviewed'] ?? 0) <=> (int)($b['reviewed'] ?? 0)) ?: strcmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? '')));
$bottomReviewers = array_slice($tmp, 0, 3);
?>
<?php $pageTitle = 'Quality Assurance Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
  .qa-hero-copy {
    max-width: 920px;
  }
  .qa-header-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
  }
  .qa-header-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.42rem 0.78rem;
    border-radius: 999px;
    border: 1px solid rgba(59, 130, 246, 0.16);
    background: rgba(59, 130, 246, 0.08);
    color: var(--app-text);
    font-size: 0.78rem;
    font-weight: 600;
  }
  .qa-table-shell tfoot td {
    font-weight: 700;
    background: rgba(15, 23, 42, 0.03);
  }
  [data-bs-theme="dark"] .qa-table-shell tfoot td {
    background: rgba(255, 255, 255, 0.04);
  }
</style>
<div class="container-fluid px-0 qa-workspace">
  <div class="qa-page-header qa-hero mb-3">
    <div class="qa-hero-copy">
      <h3 class="mb-1">Quality Assurance Dashboard</h3>
      <div class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['role'] ?? ''), ($user['job_title'] ?? ''))); ?></div>
      <div class="qa-header-chips">
        <span class="qa-header-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($rangeLabel); ?></span>
        <span class="qa-header-chip"><i class="bi bi-diagram-3"></i><?php echo number_format(count($campaignWorkload)); ?> campaigns in workload</span>
        <span class="qa-header-chip"><i class="bi bi-people"></i><?php echo number_format(count($reviewerPerf)); ?> reviewers tracked</span>
      </div>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3 shared-dashboard-filter" method="get">
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

  <?php if ($visible !== null && empty($visible)): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body d-flex align-items-start justify-content-between gap-3">
        <div>
          <div class="fw-semibold mb-1">No Campaigns Assigned</div>
          <div class="text-muted small">You currently have no campaigns allocated for QA review.</div>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="request">
          <i class="bi bi-send me-1"></i> Request Assignment
        </a>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-hourglass-split fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Pending QA</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format($pendingOnlyCount); ?></h3>
          <div class="text-muted small mt-1">Matches QA Status Counts -> Pending</div>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($pendingStatusBreakdown as $breakdown): ?>
              <span class="badge bg-light text-dark border">
                <?php echo htmlspecialchars((string)$breakdown['label']); ?>: <?php echo number_format((int)($breakdown['count'] ?? 0)); ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
              <i class="bi bi-calendar-day fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Reviewed Today</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)$todayReviewed['reviewed']); ?></h3>
          <div class="text-muted small mt-1">
            Qualified: <?php echo number_format((int)$todayReviewed['qualified']); ?> · Disqualified: <?php echo number_format((int)$todayReviewed['disqualified']); ?>
          </div>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($todayReviewedStatusBreakdown as $statusLabel => $statusCount): ?>
              <span class="badge bg-light text-dark border">
                <?php echo htmlspecialchars($statusLabel); ?>: <?php echo number_format((int)$statusCount); ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-secondary-subtle text-secondary rounded-3 p-2 me-3">
              <i class="bi bi-calendar3 fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Reviewed (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($rangeReviewed['reviewed']); ?></h3>
          <div class="text-muted small mt-1">
            Qualified: <?php echo number_format($rangeReviewed['qualified']); ?> · Disqualified: <?php echo number_format($rangeReviewed['disqualified']); ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
              <i class="bi bi-check2-square fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Reviewed Total</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)$overallReviewed['reviewed']); ?></h3>
          <div class="text-muted small mt-1">
            Qualified: <?php echo number_format((int)$overallReviewed['qualified']); ?> · Disqualified: <?php echo number_format((int)$overallReviewed['disqualified']); ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>">
        <div class="card stats-card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
                <i class="bi bi-kanban fs-4"></i>
              </div>
              <h6 class="card-subtitle text-muted mb-0">My Tasks</h6>
            </div>
            <h3 class="card-title mb-0"><?php echo number_format((int)$taskWidget['pending']); ?></h3>
            <div class="text-muted small mt-1">Due: <?php echo number_format((int)$taskWidget['due_today']); ?> · Overdue: <?php echo number_format((int)$taskWidget['overdue']); ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-3">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/hr/attendance'); ?>">
        <div class="card stats-card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
                <i class="bi bi-calendar-check fs-4"></i>
              </div>
              <h6 class="card-subtitle text-muted mb-0">Attendance (Today)</h6>
            </div>
            <h3 class="card-title mb-0"><?php echo number_format((int)floor(((int)$attendance['work_minutes']) / 60)) . ':' . str_pad((string)(((int)$attendance['work_minutes']) % 60), 2, '0', STR_PAD_LEFT); ?></h3>
            <div class="text-muted small mt-1"><?php echo htmlspecialchars($attendance['status'] !== '' ? $attendance['status'] : 'Not started'); ?> · Late: <?php echo number_format((int)$attendance['late_minutes']); ?> min</div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3 qa-panel">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="small fw-semibold text-uppercase text-muted mb-2">QA Status Counts</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach (getQaStatusOptions() as $statusValue => $statusLabel): ?>
              <?php $statusCount = (int)($qaStatusCounts[$statusValue] ?? 0); ?>
              <span class="badge bg-light text-dark border">
                <?php echo htmlspecialchars($statusLabel); ?>: <?php echo number_format($statusCount); ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="small fw-semibold text-uppercase text-muted mb-2">Client Delivery Counts</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach (getClientDeliveryStatusOptions() as $statusValue => $statusLabel): ?>
              <span class="badge bg-light text-dark border">
                <?php echo htmlspecialchars($statusLabel); ?>: <?php echo number_format((int)($stats['client_delivery_counts'][$statusValue] ?? 0)); ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3 qa-panel">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Client Live Campaign Summary (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
      <div class="small text-muted">New Leads, Delivered, and Pending all follow the selected range. Total Allocation stays tied to live campaigns.</div>
    </div>
    <div class="qa-table-shell">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Client Code</th>
              <th class="text-center">Live Campaign Count</th>
              <th class="text-center">Total Allocation</th>
              <th class="text-center">Generated Leads</th>
              <th class="text-center">Qualified Leads</th>
              <th class="text-center">Disqualified Leads</th>
              <th class="text-center">New Leads</th>
              <th class="text-center">Delivered</th>
              <th class="text-center">Pending</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($clientLiveSummaryRows)): ?>
              <tr><td colspan="9" class="text-center text-muted py-3">No live client campaign data in the current QA scope.</td></tr>
            <?php else: ?>
              <?php foreach ($clientLiveSummaryRows as $clientSummaryRow): ?>
                <tr>
                  <td><span class="qa-cell-primary"><?php echo htmlspecialchars((string)($clientSummaryRow['client_code'] ?? 'No Client')); ?></span></td>
                  <td class="text-center"><?php echo number_format((int)($clientSummaryRow['live_campaigns'] ?? 0)); ?></td>
                  <td class="text-center"><?php echo number_format((int)($clientSummaryRow['total_allocation'] ?? 0)); ?></td>
                  <td class="text-center"><?php echo number_format((int)($clientSummaryRow['generated_leads'] ?? 0)); ?></td>
                  <td class="text-center text-success"><?php echo number_format((int)($clientSummaryRow['qualified_leads'] ?? 0)); ?></td>
                  <td class="text-center text-danger"><?php echo number_format((int)($clientSummaryRow['disqualified_leads'] ?? 0)); ?></td>
                  <td class="text-center text-success"><?php echo number_format((int)($clientSummaryRow['new_leads'] ?? 0)); ?></td>
                  <td class="text-center"><?php echo number_format((int)($clientSummaryRow['delivered'] ?? 0)); ?></td>
                  <td class="text-center text-warning"><?php echo number_format((int)($clientSummaryRow['pending'] ?? 0)); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <?php if (!empty($clientLiveSummaryRows)): ?>
            <tfoot>
              <tr>
                <td>Total</td>
                <td class="text-center"><?php echo number_format((int)$clientLiveSummaryTotals['live_campaigns']); ?></td>
                <td class="text-center"><?php echo number_format((int)$clientLiveSummaryTotals['total_allocation']); ?></td>
                <td class="text-center"><?php echo number_format((int)$clientLiveSummaryTotals['generated_leads']); ?></td>
                <td class="text-center text-success"><?php echo number_format((int)$clientLiveSummaryTotals['qualified_leads']); ?></td>
                <td class="text-center text-danger"><?php echo number_format((int)$clientLiveSummaryTotals['disqualified_leads']); ?></td>
                <td class="text-center text-success"><?php echo number_format((int)$clientLiveSummaryTotals['new_leads']); ?></td>
                <td class="text-center"><?php echo number_format((int)$clientLiveSummaryTotals['delivered']); ?></td>
                <td class="text-center text-warning"><?php echo number_format((int)$clientLiveSummaryTotals['pending']); ?></td>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm h-100 qa-panel">
        <div class="card-header fw-semibold">Campaign QA Workload</div>
        <div class="qa-table-shell">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Campaign</th>
                <th>Code</th>
                <th class="text-center">Queue</th>
                <th class="text-center">Reviewed</th>
                <th class="text-center">Qualified</th>
                <th class="text-center">Disqualified</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($campaignWorkload)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No campaigns available.</td></tr>
              <?php else: ?>
                <?php foreach ($campaignWorkload as $r): ?>
                  <tr>
                    <td><span class="qa-cell-primary"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></span></td>
                    <td><span class="qa-cell-secondary"><?php echo htmlspecialchars((string)($r['code'] ?? '')); ?></span></td>
                    <td class="text-center"><?php echo (int)($r['pending'] ?? 0); ?></td>
                    <td class="text-center fw-semibold"><?php echo (int)($r['reviewed'] ?? 0); ?></td>
                    <td class="text-center text-success"><?php echo (int)($r['qualified'] ?? 0); ?></td>
                    <td class="text-center text-danger"><?php echo (int)($r['disqualified'] ?? 0); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <?php if (!empty($campaignWorkload)): ?>
              <tfoot>
                <tr>
                  <td colspan="2">Total</td>
                  <td class="text-center"><?php echo number_format((int)$campaignWorkloadTotals['pending']); ?></td>
                  <td class="text-center"><?php echo number_format((int)$campaignWorkloadTotals['reviewed']); ?></td>
                  <td class="text-center text-success"><?php echo number_format((int)$campaignWorkloadTotals['qualified']); ?></td>
                  <td class="text-center text-danger"><?php echo number_format((int)$campaignWorkloadTotals['disqualified']); ?></td>
                </tr>
              </tfoot>
            <?php endif; ?>
          </table>
        </div>
        <div class="qa-table-note">The top Pending QA card now mirrors the QA Status Counts Pending bucket. Queue totals below still use the broader non-qualified queue rule for workload tracking.</div>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100 qa-panel">
        <?php
          $tq = (int)($approvalRatio['qualified'] ?? 0);
          $td = (int)($approvalRatio['disqualified'] ?? 0);
          $tr = $tq + $td;
          $qPct = $tr > 0 ? round(($tq / $tr) * 100) : 0;
          $dPct = $tr > 0 ? (100 - $qPct) : 0;
        ?>
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">QA Productivity (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
          <div class="small text-muted"><?php echo $qPct; ?>% / <?php echo $dPct; ?>%</div>
        </div>
        <div class="qa-table-shell">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Reviewer</th>
                <th class="text-center">Reviewed</th>
                <th class="text-center">Q</th>
                <th class="text-center">DQ</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($reviewerPerf)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No QA activity yet.</td></tr>
              <?php else: ?>
                <?php foreach ($reviewerPerf as $r): ?>
                  <tr>
                    <td><span class="qa-cell-primary"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></span></td>
                    <td class="text-center"><?php echo (int)($r['reviewed'] ?? 0); ?></td>
                    <td class="text-center text-success"><?php echo (int)($r['qualified'] ?? 0); ?></td>
                    <td class="text-center text-danger"><?php echo (int)($r['disqualified'] ?? 0); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <?php if (!empty($reviewerPerf)): ?>
              <tfoot>
                <tr>
                  <td>Total</td>
                  <td class="text-center"><?php echo number_format((int)$reviewerPerfTotals['reviewed']); ?></td>
                  <td class="text-center text-success"><?php echo number_format((int)$reviewerPerfTotals['qualified']); ?></td>
                  <td class="text-center text-danger"><?php echo number_format((int)$reviewerPerfTotals['disqualified']); ?></td>
                </tr>
              </tfoot>
            <?php endif; ?>
          </table>
        </div>
        <div class="qa-table-note">Reviewer-level output now uses the same selected-range review event counts as the cards above, with totals shown for reconciliation.</div>
        </div>
        <div class="card-footer bg-white">
          <div class="row g-2">
            <div class="col-6">
              <div class="text-muted small mb-2"><i class="bi bi-trophy me-1"></i>Top Performers</div>
              <?php if (empty($topReviewers)): ?>
                <div class="text-muted small">No data in range.</div>
              <?php else: ?>
                <div class="performer-mini-list">
                <?php foreach ($topReviewers as $idx => $r): ?>
                  <?php $podium = getPerformerPodiumMeta((int)$idx); ?>
                  <div class="performer-mini-item">
                    <div class="performer-mini-main">
                      <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                      <div class="performer-mini-copy">
                        <div class="performer-mini-name"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></div>
                        <div class="performer-mini-meta"><?php echo htmlspecialchars($podium['place']); ?></div>
                      </div>
                    </div>
                    <span class="performer-mini-value"><?php echo (int)($r['reviewed'] ?? 0); ?> reviewed</span>
                  </div>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-6">
              <div class="text-muted small mb-2"><i class="bi bi-graph-down-arrow me-1"></i>Bottom Performers</div>
              <?php if (empty($bottomReviewers)): ?>
                <div class="text-muted small">No data in range.</div>
              <?php else: ?>
                <div class="performer-mini-list">
                <?php foreach ($bottomReviewers as $idx => $r): ?>
                  <?php $podium = getPerformerPodiumMeta((int)$idx, 'bottom'); ?>
                  <div class="performer-mini-item">
                    <div class="performer-mini-main">
                      <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                      <div class="performer-mini-copy">
                        <div class="performer-mini-name"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></div>
                        <div class="performer-mini-meta"><?php echo htmlspecialchars($podium['place']); ?></div>
                      </div>
                    </div>
                    <span class="performer-mini-value"><?php echo (int)($r['reviewed'] ?? 0); ?> reviewed</span>
                  </div>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm h-100 qa-panel">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Recent Reviewed</div>
          <a class="small text-decoration-none" href="audit">Open QA Processing</a>
        </div>
        <div class="qa-table-shell">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Lead</th>
                <th>Company</th>
                <th>Status</th>
                <th>Campaign</th>
                <th>Reviewer</th>
                <th class="text-muted">When</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentReviewed)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No reviewed leads yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recentReviewed as $l): ?>
                  <tr>
                    <td><span class="qa-cell-primary"><?php echo htmlspecialchars($l['lead_id'] ?? (string)($l['id'] ?? '')); ?></span></td>
                    <td><span class="qa-cell-primary"><?php echo htmlspecialchars($l['company_name'] ?? ''); ?></span></td>
                    <?php $recentQaStatus = normalizeQaStatus((string)($l['qa_status'] ?? 'Pending')); ?>
                    <td><span class="badge <?php echo htmlspecialchars(qaStatusBadgeClass($recentQaStatus)); ?>"><?php echo htmlspecialchars(qaStatusDisplayLabel($recentQaStatus)); ?></span></td>
                    <td><span class="qa-cell-secondary"><?php echo htmlspecialchars($l['campaign_name'] ?? ''); ?></span></td>
                    <td><span class="qa-cell-secondary"><?php echo htmlspecialchars(formatUserNameWithRole(($l['reviewer_name'] ?? ''), ($l['reviewer_role'] ?? ''), ($l['reviewer_job_title'] ?? ''))); ?></span></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$l['qa_updated_at']))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="qa-table-note">Recently reviewed records show the lead, campaign, reviewer, and latest QA outcome together.</div>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100 qa-panel">
        <div class="card-header fw-semibold">Newest QA Queue</div>
        <div class="qa-table-shell">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Lead</th>
                <th>Company</th>
                <th>Agent</th>
                <th class="text-muted">Created</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($pending)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No pending leads.</td></tr>
              <?php else: ?>
                <?php foreach ($pending as $l): ?>
                  <tr>
                    <td><span class="qa-cell-primary"><?php echo htmlspecialchars($l['lead_id'] ?? (string)($l['id'] ?? '')); ?></span></td>
                    <td><span class="qa-cell-primary"><?php echo htmlspecialchars($l['company_name'] ?? ''); ?></span></td>
                    <td><span class="qa-cell-secondary"><?php echo htmlspecialchars(formatUserNameWithRole(($l['agent_name'] ?? ''), ($l['agent_role'] ?? ''), ($l['agent_job_title'] ?? ''))); ?></span></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$l['created_at']))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="qa-table-note">Shows the newest leads currently in the QA queue using the same blank, Pending, and Reopened rule.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="audit">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">QA Processing</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-shield-check"></i></div>
            </div>
            <div class="text-muted small mt-2">Review and qualify/disqualify leads.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/list">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">All Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-clipboard-data"></i></div>
            </div>
            <div class="text-muted small mt-2">Search, filter, and audit lead records.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/export.php">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">Export Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-download"></i></div>
            </div>
            <div class="text-muted small mt-2">Export filtered lead data.</div>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
