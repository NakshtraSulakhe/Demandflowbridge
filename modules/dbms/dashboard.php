<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.dashboard.page', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();
$workflowExpr = dbmsWorkflowStatusSql('c');

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? date('Y-m-01 00:00:00'));
$rangeEnd = (string)($range['end'] ?? date('Y-m-t 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));

$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');

$hasResearchSubmittedAt = dbmsContactsHasColumn('research_submitted_at');
$hasResearchSubmittedBy = dbmsContactsHasColumn('research_submitted_by');
$hasCdqaDecision = dbmsContactsHasColumn('cdqa_decision');
$hasCdqaDecisionAt = dbmsContactsHasColumn('cdqa_decision_at');
$hasCdqaDecisionBy = dbmsContactsHasColumn('cdqa_decision_by');
$hasQueueEnteredAt = dbmsContactsHasColumn('queue_entered_at');
$hasLastActivityAt = dbmsContactsHasColumn('last_activity_at');
$hasBatchProcessedRows = dbmsBatchesHasColumn('processed_rows');

$touchExpr = $hasLastActivityAt
    ? "COALESCE(c.last_activity_at, c.updated_at, c.created_at)"
    : "COALESCE(c.updated_at, c.created_at)";
$assignmentBaseExpr = "COALESCE(c.assigned_at, c.created_at)";
$meaningfulTouchExpr = "({$touchExpr} > {$assignmentBaseExpr})";
$agingBaseExpr = $hasQueueEnteredAt
    ? "COALESCE(c.queue_entered_at, c.assigned_at, c.created_at)"
    : $assignmentBaseExpr;

$counts = [
    'total' => 0,
    'pending_research' => 0,
    'research_assigned' => 0,
    'research_rework' => 0,
    'pending_cdqa' => 0,
    'cdqa_assigned' => 0,
    'approved' => 0,
    'rejected' => 0,
    'duplicate' => 0,
    'my_assigned' => 0,
    'my_research' => 0,
    'my_cdqa' => 0,
];
$stmtCounts = $conn->prepare("
    SELECT
      COUNT(*) AS total_cnt,
      SUM({$workflowExpr} = 'pending_research') AS pending_research_cnt,
      SUM({$workflowExpr} = 'research_assigned') AS research_assigned_cnt,
      SUM({$workflowExpr} = 'research_rework') AS research_rework_cnt,
      SUM({$workflowExpr} = 'pending_cdqa') AS pending_cdqa_cnt,
      SUM({$workflowExpr} = 'cdqa_assigned') AS cdqa_assigned_cnt,
      SUM({$workflowExpr} = 'approved') AS approved_cnt,
      SUM({$workflowExpr} = 'rejected') AS rejected_cnt,
      SUM({$workflowExpr} = 'duplicate') AS duplicate_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) = ?) AS my_assigned_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) = ? AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'research' AND {$workflowExpr} IN ('research_assigned','research_rework')) AS my_research_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) = ? AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'cdqa' AND {$workflowExpr} = 'cdqa_assigned') AS my_cdqa_cnt
    FROM dbms_contacts c
");
if ($stmtCounts) {
    $stmtCounts->bind_param('iii', $userId, $userId, $userId);
    $stmtCounts->execute();
    $row = $stmtCounts->get_result()->fetch_assoc() ?: [];
    $stmtCounts->close();
    $counts['total'] = (int)($row['total_cnt'] ?? 0);
    $counts['pending_research'] = (int)($row['pending_research_cnt'] ?? 0);
    $counts['research_assigned'] = (int)($row['research_assigned_cnt'] ?? 0);
    $counts['research_rework'] = (int)($row['research_rework_cnt'] ?? 0);
    $counts['pending_cdqa'] = (int)($row['pending_cdqa_cnt'] ?? 0);
    $counts['cdqa_assigned'] = (int)($row['cdqa_assigned_cnt'] ?? 0);
    $counts['approved'] = (int)($row['approved_cnt'] ?? 0);
    $counts['rejected'] = (int)($row['rejected_cnt'] ?? 0);
    $counts['duplicate'] = (int)($row['duplicate_cnt'] ?? 0);
    $counts['my_assigned'] = (int)($row['my_assigned_cnt'] ?? 0);
    $counts['my_research'] = (int)($row['my_research_cnt'] ?? 0);
    $counts['my_cdqa'] = (int)($row['my_cdqa_cnt'] ?? 0);
}

$rangeCounts = [
    'created' => 0,
    'research_assigned' => 0,
    'research_submitted' => 0,
    'cdqa_assigned' => 0,
    'approved' => 0,
    'rework' => 0,
];
$rangeFields = [
    "SUM(c.created_at BETWEEN ? AND ?) AS created_cnt",
    "SUM(c.assigned_at BETWEEN ? AND ? AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'research') AS research_assigned_cnt",
    "SUM(c.assigned_at BETWEEN ? AND ? AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'cdqa') AS cdqa_assigned_cnt",
];
if ($hasResearchSubmittedAt) {
    $rangeFields[] = "SUM(c.research_submitted_at BETWEEN ? AND ?) AS research_submitted_cnt";
} else {
    $rangeFields[] = "0 AS research_submitted_cnt";
}
if ($hasCdqaDecision && $hasCdqaDecisionAt) {
    $rangeFields[] = "SUM(c.cdqa_decision = 'approved' AND c.cdqa_decision_at BETWEEN ? AND ?) AS approved_cnt";
    $rangeFields[] = "SUM(c.cdqa_decision = 'rejected' AND c.cdqa_decision_at BETWEEN ? AND ?) AS rework_cnt";
} else {
    $rangeFields[] = "SUM({$workflowExpr} = 'approved' AND c.updated_at BETWEEN ? AND ?) AS approved_cnt";
    $rangeFields[] = "SUM({$workflowExpr} = 'research_rework' AND c.updated_at BETWEEN ? AND ?) AS rework_cnt";
}
$stmtRange = $conn->prepare("SELECT " . implode(",\n       ", $rangeFields) . "\nFROM dbms_contacts c");
if ($stmtRange) {
    $params = [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd];
    if ($hasResearchSubmittedAt) {
        $params[] = $rangeStart;
        $params[] = $rangeEnd;
    }
    $params[] = $rangeStart;
    $params[] = $rangeEnd;
    $params[] = $rangeStart;
    $params[] = $rangeEnd;
    mysqliBindParams($stmtRange, str_repeat('s', count($params)), $params);
    $stmtRange->execute();
    $row = $stmtRange->get_result()->fetch_assoc() ?: [];
    $stmtRange->close();
    $rangeCounts['created'] = (int)($row['created_cnt'] ?? 0);
    $rangeCounts['research_assigned'] = (int)($row['research_assigned_cnt'] ?? 0);
    $rangeCounts['research_submitted'] = (int)($row['research_submitted_cnt'] ?? 0);
    $rangeCounts['cdqa_assigned'] = (int)($row['cdqa_assigned_cnt'] ?? 0);
    $rangeCounts['approved'] = (int)($row['approved_cnt'] ?? 0);
    $rangeCounts['rework'] = (int)($row['rework_cnt'] ?? 0);
}

$windowMetrics = [
    'today' => ['label' => 'Daily', 'sub' => 'Today', 'start' => $todayStart, 'end' => $todayEnd],
    'month' => ['label' => 'Monthly', 'sub' => 'This Month', 'start' => $monthStart, 'end' => $monthEnd],
    'total' => ['label' => 'Total', 'sub' => 'All Time', 'start' => null, 'end' => null],
];
$collectWindowMetrics = function(?string $start, ?string $end) use ($conn, $touchExpr, $meaningfulTouchExpr, $hasResearchSubmittedAt, $hasCdqaDecisionAt, $hasCdqaDecision): array {
    $metrics = [
        'uploaded_rows' => 0,
        'uploaded_batches' => 0,
        'records_created' => 0,
        'assignments_made' => 0,
        'touched_records' => 0,
        'research_done' => 0,
        'cdqa_done' => 0,
        'approved' => 0,
        'rework' => 0,
    ];

    if ($start !== null && $end !== null) {
        $stmtContacts = $conn->prepare("
            SELECT
              SUM(c.created_at BETWEEN ? AND ?) AS records_created_cnt,
              SUM(c.assigned_at BETWEEN ? AND ?) AS assignments_made_cnt,
              SUM({$meaningfulTouchExpr} AND {$touchExpr} BETWEEN ? AND ?) AS touched_records_cnt,
              " . ($hasResearchSubmittedAt ? "SUM(c.research_submitted_at BETWEEN ? AND ?) AS research_done_cnt" : "0 AS research_done_cnt") . ",
              " . (($hasCdqaDecision && $hasCdqaDecisionAt) ? "SUM(c.cdqa_decision_at BETWEEN ? AND ?) AS cdqa_done_cnt" : "SUM({$touchExpr} BETWEEN ? AND ? AND {$workflowExpr} = 'approved') AS cdqa_done_cnt") . ",
              " . (($hasCdqaDecision && $hasCdqaDecisionAt) ? "SUM(c.cdqa_decision = 'approved' AND c.cdqa_decision_at BETWEEN ? AND ?) AS approved_cnt" : "SUM({$touchExpr} BETWEEN ? AND ? AND {$workflowExpr} = 'approved') AS approved_cnt") . ",
              " . (($hasCdqaDecision && $hasCdqaDecisionAt) ? "SUM(c.cdqa_decision = 'rejected' AND c.cdqa_decision_at BETWEEN ? AND ?) AS rework_cnt" : "SUM({$touchExpr} BETWEEN ? AND ? AND {$workflowExpr} = 'research_rework') AS rework_cnt") . "
            FROM dbms_contacts c
        ");
        if ($stmtContacts) {
            $params = [$start, $end, $start, $end, $start, $end];
            if ($hasResearchSubmittedAt) {
                $params[] = $start;
                $params[] = $end;
            }
            $params[] = $start;
            $params[] = $end;
            $params[] = $start;
            $params[] = $end;
            $params[] = $start;
            $params[] = $end;
            mysqliBindParams($stmtContacts, str_repeat('s', count($params)), $params);
            $stmtContacts->execute();
            $row = $stmtContacts->get_result()->fetch_assoc() ?: [];
            $stmtContacts->close();
            $metrics['records_created'] = (int)($row['records_created_cnt'] ?? 0);
            $metrics['assignments_made'] = (int)($row['assignments_made_cnt'] ?? 0);
            $metrics['touched_records'] = (int)($row['touched_records_cnt'] ?? 0);
            $metrics['research_done'] = (int)($row['research_done_cnt'] ?? 0);
            $metrics['cdqa_done'] = (int)($row['cdqa_done_cnt'] ?? 0);
            $metrics['approved'] = (int)($row['approved_cnt'] ?? 0);
            $metrics['rework'] = (int)($row['rework_cnt'] ?? 0);
        }

        $stmtBatches = $conn->prepare("
            SELECT COUNT(*) AS batch_cnt, COALESCE(SUM(total_rows), 0) AS uploaded_rows_cnt
            FROM dbms_batches
            WHERE uploaded_at BETWEEN ? AND ?
        ");
        if ($stmtBatches) {
            $stmtBatches->bind_param('ss', $start, $end);
            $stmtBatches->execute();
            $row = $stmtBatches->get_result()->fetch_assoc() ?: [];
            $stmtBatches->close();
            $metrics['uploaded_batches'] = (int)($row['batch_cnt'] ?? 0);
            $metrics['uploaded_rows'] = (int)($row['uploaded_rows_cnt'] ?? 0);
        }
    } else {
        $row = $conn->query("
            SELECT
              COUNT(*) AS records_created_cnt,
              SUM(COALESCE(c.assigned_to_user_id, 0) > 0) AS assignments_made_cnt,
              SUM({$meaningfulTouchExpr}) AS touched_records_cnt,
              " . ($hasResearchSubmittedAt ? "SUM(c.research_submitted_at IS NOT NULL) AS research_done_cnt" : "0 AS research_done_cnt") . ",
              " . (($hasCdqaDecision && $hasCdqaDecisionAt) ? "SUM(c.cdqa_decision_at IS NOT NULL) AS cdqa_done_cnt" : "SUM({$workflowExpr} = 'approved') AS cdqa_done_cnt") . ",
              " . (($hasCdqaDecision && $hasCdqaDecisionAt) ? "SUM(c.cdqa_decision = 'approved') AS approved_cnt" : "SUM({$workflowExpr} = 'approved') AS approved_cnt") . ",
              " . (($hasCdqaDecision && $hasCdqaDecisionAt) ? "SUM(c.cdqa_decision = 'rejected') AS rework_cnt" : "SUM({$workflowExpr} = 'research_rework') AS rework_cnt") . "
            FROM dbms_contacts c
        ");
        if ($row) {
            $stats = $row->fetch_assoc() ?: [];
            $metrics['records_created'] = (int)($stats['records_created_cnt'] ?? 0);
            $metrics['assignments_made'] = (int)($stats['assignments_made_cnt'] ?? 0);
            $metrics['touched_records'] = (int)($stats['touched_records_cnt'] ?? 0);
            $metrics['research_done'] = (int)($stats['research_done_cnt'] ?? 0);
            $metrics['cdqa_done'] = (int)($stats['cdqa_done_cnt'] ?? 0);
            $metrics['approved'] = (int)($stats['approved_cnt'] ?? 0);
            $metrics['rework'] = (int)($stats['rework_cnt'] ?? 0);
        }

        $row = $conn->query("SELECT COUNT(*) AS batch_cnt, COALESCE(SUM(total_rows), 0) AS uploaded_rows_cnt FROM dbms_batches");
        if ($row) {
            $stats = $row->fetch_assoc() ?: [];
            $metrics['uploaded_batches'] = (int)($stats['batch_cnt'] ?? 0);
            $metrics['uploaded_rows'] = (int)($stats['uploaded_rows_cnt'] ?? 0);
        }
    }

    return $metrics;
};
foreach ($windowMetrics as $key => $cfg) {
    $windowMetrics[$key]['metrics'] = $collectWindowMetrics($cfg['start'], $cfg['end']);
}

$ownershipSummary = [
    'assigned_records' => 0,
    'unassigned_records' => 0,
    'touched_today' => 0,
    'touched_month' => 0,
    'untouched_records' => 0,
    'research_owned' => 0,
    'cdqa_owned' => 0,
];
$stmtOwnership = $conn->prepare("
    SELECT
      SUM(COALESCE(c.assigned_to_user_id, 0) > 0) AS assigned_records_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) = 0) AS unassigned_records_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) > 0 AND {$meaningfulTouchExpr} AND {$touchExpr} BETWEEN ? AND ?) AS touched_today_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) > 0 AND {$meaningfulTouchExpr} AND {$touchExpr} BETWEEN ? AND ?) AS touched_month_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) > 0 AND NOT {$meaningfulTouchExpr}) AS untouched_records_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) > 0 AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'research') AS research_owned_cnt,
      SUM(COALESCE(c.assigned_to_user_id, 0) > 0 AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'cdqa') AS cdqa_owned_cnt
    FROM dbms_contacts c
");
if ($stmtOwnership) {
    $stmtOwnership->bind_param('ssss', $todayStart, $todayEnd, $monthStart, $monthEnd);
    $stmtOwnership->execute();
    $row = $stmtOwnership->get_result()->fetch_assoc() ?: [];
    $stmtOwnership->close();
    $ownershipSummary['assigned_records'] = (int)($row['assigned_records_cnt'] ?? 0);
    $ownershipSummary['unassigned_records'] = (int)($row['unassigned_records_cnt'] ?? 0);
    $ownershipSummary['touched_today'] = (int)($row['touched_today_cnt'] ?? 0);
    $ownershipSummary['touched_month'] = (int)($row['touched_month_cnt'] ?? 0);
    $ownershipSummary['untouched_records'] = (int)($row['untouched_records_cnt'] ?? 0);
    $ownershipSummary['research_owned'] = (int)($row['research_owned_cnt'] ?? 0);
    $ownershipSummary['cdqa_owned'] = (int)($row['cdqa_owned_cnt'] ?? 0);
}

$ownerRows = [];
$stmtOwnerLoad = $conn->prepare("
    SELECT
      c.assigned_to_user_id AS id,
      u.full_name,
      LOWER(TRIM(COALESCE(c.assigned_department, ''))) AS lane_key,
      COUNT(*) AS current_assigned,
      SUM({$meaningfulTouchExpr} AND {$touchExpr} BETWEEN ? AND ?) AS touched_today,
      SUM({$meaningfulTouchExpr} AND {$touchExpr} BETWEEN ? AND ?) AS touched_month,
      SUM(NOT {$meaningfulTouchExpr}) AS untouched_current,
      SUM(TIMESTAMPDIFF(DAY, {$agingBaseExpr}, NOW()) >= 3) AS stale_queue,
      MAX({$touchExpr}) AS last_touch_at
    FROM dbms_contacts c
    JOIN users u ON u.id = c.assigned_to_user_id
    WHERE COALESCE(c.assigned_to_user_id, 0) > 0
    GROUP BY c.assigned_to_user_id, u.full_name, lane_key
");
if ($stmtOwnerLoad) {
    $stmtOwnerLoad->bind_param('ssss', $todayStart, $todayEnd, $monthStart, $monthEnd);
    $stmtOwnerLoad->execute();
    foreach ($stmtOwnerLoad->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $laneKey = (string)($row['lane_key'] ?? '');
        $laneLabel = $laneKey === 'research' ? 'Research' : ($laneKey === 'cdqa' ? 'CDQA' : 'General');
        $ownerRows[$id . ':' . $laneKey] = [
            'id' => $id,
            'full_name' => (string)($row['full_name'] ?? ('User #' . $id)),
            'lane_key' => $laneKey,
            'lane_label' => $laneLabel,
            'current_assigned' => (int)($row['current_assigned'] ?? 0),
            'touched_today' => (int)($row['touched_today'] ?? 0),
            'touched_month' => (int)($row['touched_month'] ?? 0),
            'untouched_current' => (int)($row['untouched_current'] ?? 0),
            'stale_queue' => (int)($row['stale_queue'] ?? 0),
            'last_touch_at' => (string)($row['last_touch_at'] ?? ''),
            'research_done_today' => 0,
            'research_done_month' => 0,
            'cdqa_done_today' => 0,
            'cdqa_done_month' => 0,
        ];
    }
    $stmtOwnerLoad->close();
}

if ($hasResearchSubmittedAt && $hasResearchSubmittedBy) {
    $stmtResearchDone = $conn->prepare("
        SELECT
          c.research_submitted_by AS id,
          SUM(c.research_submitted_at BETWEEN ? AND ?) AS research_done_today,
          SUM(c.research_submitted_at BETWEEN ? AND ?) AS research_done_month
        FROM dbms_contacts c
        WHERE COALESCE(c.research_submitted_by, 0) > 0
        GROUP BY c.research_submitted_by
    ");
    if ($stmtResearchDone) {
        $stmtResearchDone->bind_param('ssss', $todayStart, $todayEnd, $monthStart, $monthEnd);
        $stmtResearchDone->execute();
        foreach ($stmtResearchDone->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            $key = $id . ':research';
            if (!isset($ownerRows[$key])) {
                continue;
            }
            $ownerRows[$key]['research_done_today'] = (int)($row['research_done_today'] ?? 0);
            $ownerRows[$key]['research_done_month'] = (int)($row['research_done_month'] ?? 0);
        }
        $stmtResearchDone->close();
    }
}

if ($hasCdqaDecision && $hasCdqaDecisionAt && $hasCdqaDecisionBy) {
    $stmtCdqaDone = $conn->prepare("
        SELECT
          c.cdqa_decision_by AS id,
          SUM(c.cdqa_decision_at BETWEEN ? AND ?) AS cdqa_done_today,
          SUM(c.cdqa_decision_at BETWEEN ? AND ?) AS cdqa_done_month
        FROM dbms_contacts c
        WHERE COALESCE(c.cdqa_decision_by, 0) > 0
        GROUP BY c.cdqa_decision_by
    ");
    if ($stmtCdqaDone) {
        $stmtCdqaDone->bind_param('ssss', $todayStart, $todayEnd, $monthStart, $monthEnd);
        $stmtCdqaDone->execute();
        foreach ($stmtCdqaDone->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            $key = $id . ':cdqa';
            if (!isset($ownerRows[$key])) {
                continue;
            }
            $ownerRows[$key]['cdqa_done_today'] = (int)($row['cdqa_done_today'] ?? 0);
            $ownerRows[$key]['cdqa_done_month'] = (int)($row['cdqa_done_month'] ?? 0);
        }
        $stmtCdqaDone->close();
    }
}

$ownerRows = array_values($ownerRows);
usort($ownerRows, static function(array $a, array $b): int {
    $scoreA = (int)($a['current_assigned'] ?? 0) + (int)($a['touched_month'] ?? 0);
    $scoreB = (int)($b['current_assigned'] ?? 0) + (int)($b['touched_month'] ?? 0);
    return ($scoreB <=> $scoreA) ?: strcmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
});

$researchTeam = array_values(array_filter($ownerRows, static fn(array $row): bool => (string)($row['lane_key'] ?? '') === 'research'));
$cdqaTeam = array_values(array_filter($ownerRows, static fn(array $row): bool => (string)($row['lane_key'] ?? '') === 'cdqa'));

$aging = [
    'research_0_2' => 0,
    'research_3_5' => 0,
    'research_6_plus' => 0,
    'cdqa_0_2' => 0,
    'cdqa_3_5' => 0,
    'cdqa_6_plus' => 0,
];
$stmtAging = $conn->prepare("
    SELECT
      SUM({$workflowExpr} IN ('pending_research','research_assigned','research_rework') AND TIMESTAMPDIFF(DAY, {$agingBaseExpr}, NOW()) BETWEEN 0 AND 2) AS research_0_2,
      SUM({$workflowExpr} IN ('pending_research','research_assigned','research_rework') AND TIMESTAMPDIFF(DAY, {$agingBaseExpr}, NOW()) BETWEEN 3 AND 5) AS research_3_5,
      SUM({$workflowExpr} IN ('pending_research','research_assigned','research_rework') AND TIMESTAMPDIFF(DAY, {$agingBaseExpr}, NOW()) >= 6) AS research_6_plus,
      SUM({$workflowExpr} IN ('pending_cdqa','cdqa_assigned') AND TIMESTAMPDIFF(DAY, {$agingBaseExpr}, NOW()) BETWEEN 0 AND 2) AS cdqa_0_2,
      SUM({$workflowExpr} IN ('pending_cdqa','cdqa_assigned') AND TIMESTAMPDIFF(DAY, {$agingBaseExpr}, NOW()) BETWEEN 3 AND 5) AS cdqa_3_5,
      SUM({$workflowExpr} IN ('pending_cdqa','cdqa_assigned') AND TIMESTAMPDIFF(DAY, {$agingBaseExpr}, NOW()) >= 6) AS cdqa_6_plus
    FROM dbms_contacts c
");
if ($stmtAging) {
    $stmtAging->execute();
    $row = $stmtAging->get_result()->fetch_assoc() ?: [];
    $stmtAging->close();
    foreach (array_keys($aging) as $key) {
        $aging[$key] = (int)($row[$key] ?? 0);
    }
}

$uploaderRows = [];
$stmtUploaders = $conn->prepare("
    SELECT b.uploaded_by AS id, u.full_name,
           COUNT(*) AS batches_count,
           COALESCE(SUM(b.total_rows), 0) AS uploaded_rows,
           MAX(b.uploaded_at) AS last_uploaded_at
    FROM dbms_batches b
    LEFT JOIN users u ON u.id = b.uploaded_by
    WHERE b.uploaded_at BETWEEN ? AND ?
    GROUP BY b.uploaded_by, u.full_name
    ORDER BY uploaded_rows DESC, batches_count DESC, u.full_name ASC
    LIMIT 8
");
if ($stmtUploaders) {
    $stmtUploaders->bind_param('ss', $rangeStart, $rangeEnd);
    $stmtUploaders->execute();
    $uploaderRows = $stmtUploaders->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtUploaders->close();
}

$recentBatches = [];
$rsBatches = $conn->query("
    SELECT b.*, u.full_name AS uploaded_by_name
    FROM dbms_batches b
    LEFT JOIN users u ON u.id = b.uploaded_by
    ORDER BY b.uploaded_at DESC, b.id DESC
    LIMIT 10
");
if ($rsBatches) {
    $recentBatches = $rsBatches->fetch_all(MYSQLI_ASSOC) ?: [];
}

$recentBatchCounts = [];
$recentBatchIds = array_values(array_filter(array_map(static fn(array $batch): int => (int)($batch['id'] ?? 0), $recentBatches), static fn(int $id): bool => $id > 0));
if (!empty($recentBatchIds)) {
    $in = implode(',', array_fill(0, count($recentBatchIds), '?'));
    $stmtBatchCounts = $conn->prepare("
        SELECT c.batch_id,
               SUM({$workflowExpr} = 'pending_research') AS pending_research,
               SUM({$workflowExpr} = 'pending_cdqa') AS pending_cdqa,
               SUM({$workflowExpr} = 'approved') AS approved,
               SUM({$workflowExpr} = 'research_rework') AS research_rework,
               SUM({$workflowExpr} = 'rejected') AS rejected,
               SUM({$workflowExpr} = 'duplicate') AS duplicate
        FROM dbms_contacts c
        WHERE c.batch_id IN ({$in})
        GROUP BY c.batch_id
    ");
    if ($stmtBatchCounts) {
        mysqliBindParams($stmtBatchCounts, str_repeat('i', count($recentBatchIds)), $recentBatchIds);
        $stmtBatchCounts->execute();
        foreach ($stmtBatchCounts->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $recentBatchCounts[(int)($row['batch_id'] ?? 0)] = $row;
        }
        $stmtBatchCounts->close();
    }
}

$ownerStatusMeta = static function(array $row): array {
    $currentAssigned = (int)($row['current_assigned'] ?? 0);
    $touchedToday = (int)($row['touched_today'] ?? 0);
    $touchedMonth = (int)($row['touched_month'] ?? 0);
    $untouchedCurrent = (int)($row['untouched_current'] ?? 0);
    $staleQueue = (int)($row['stale_queue'] ?? 0);
    if ($currentAssigned <= 0) {
        return ['label' => 'Idle', 'class' => 'dbms-status-slate'];
    }
    if ($untouchedCurrent >= $currentAssigned) {
        return ['label' => 'Not Touched', 'class' => 'dbms-status-danger'];
    }
    if ($touchedToday > 0) {
        return ['label' => 'Active Today', 'class' => 'dbms-status-success'];
    }
    if ($staleQueue > 0) {
        return ['label' => 'Needs Follow-up', 'class' => 'dbms-status-warning'];
    }
    if ($touchedMonth > 0) {
        return ['label' => 'Updated This Month', 'class' => 'dbms-status-info'];
    }
    return ['label' => 'Assigned', 'class' => 'dbms-status-slate'];
};
$dateTimeLabel = static function(string $value): string {
    $ts = strtotime($value);
    return $ts ? date('d M, Y H:i', $ts) : 'No activity yet';
};
$metricPct = static function(int $part, int $whole): string {
    if ($whole <= 0) {
        return '0%';
    }
    return number_format(($part / max(1, $whole)) * 100, 1) . '%';
};

$pageTitle = 'DBMS';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 dbms-workspace dbms-ops-workspace">
  <div class="dbms-page-header mb-3">
    <div class="dbms-hero flex-grow-1">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
          <span class="dbms-eyebrow"><i class="bi bi-database-check"></i>DBMS Operations Board</span>
          <div class="dbms-page-title mt-2">Assignment, Touch, and Throughput Control Center</div>
          <div class="dbms-page-subtitle">Track who owns each DBMS lane, how many records are currently assigned, how many were touched daily and monthly, who still has untouched load, and how Research, CDQA, and uploads are moving across the workflow.</div>
        </div>
        <div class="dbms-chip-group">
          <span class="dbms-chip tone-info"><?php echo htmlspecialchars($rangeLabel); ?> focus</span>
          <span class="dbms-chip tone-success"><?php echo number_format($ownershipSummary['assigned_records']); ?> assigned now</span>
          <span class="dbms-chip tone-warning"><?php echo number_format($ownershipSummary['untouched_records']); ?> not touched</span>
          <span class="dbms-chip tone-company"><?php echo number_format($windowMetrics['month']['metrics']['uploaded_rows'] ?? 0); ?> uploaded this month</span>
        </div>
      </div>
      <div class="dbms-ops-actions mt-3">
        <?php if (userHasPermission('dbms.upload')): ?>
          <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/upload"><i class="bi bi-cloud-arrow-up me-1"></i>Upload Leads</a>
        <?php endif; ?>
        <?php if (userHasPermission('dbms.assignment')): ?>
          <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/assignments"><i class="bi bi-person-check me-1"></i>Assignments</a>
        <?php endif; ?>
        <?php if (userHasPermission('dbms.research')): ?>
          <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/research"><i class="bi bi-journal-text me-1"></i>Research Queue</a>
        <?php endif; ?>
        <?php if (userHasPermission('dbms.cdqa')): ?>
          <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/cdqa"><i class="bi bi-shield-check me-1"></i>CDQA Queue</a>
        <?php endif; ?>
        <?php if (userHasPermission('dbms.reports')): ?>
          <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch"><i class="bi bi-bar-chart me-1"></i>Batches</a>
        <?php endif; ?>
        <?php if (userHasPermission('admin.analytics')): ?>
          <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/admin/analytics/dbms"><i class="bi bi-graph-up-arrow me-1"></i>Admin Analytics</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card hr-card dbms-panel mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end dbms-filter-form" method="get">
        <div class="col-md-3">
          <label class="form-label small text-muted">Focus Window</label>
          <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
            <option value="today" <?php echo $preset === 'today' ? 'selected' : ''; ?>>Today</option>
            <option value="this_month" <?php echo $preset === 'this_month' ? 'selected' : ''; ?>>This Month</option>
            <option value="overall" <?php echo $preset === 'overall' ? 'selected' : ''; ?>>Overall</option>
            <option value="custom" <?php echo $preset === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted">Start</label>
          <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($sd); ?>" <?php echo $preset === 'custom' ? '' : 'disabled'; ?>>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted">End</label>
          <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($ed); ?>" <?php echo $preset === 'custom' ? '' : 'disabled'; ?>>
        </div>
        <div class="col-md-3 d-flex justify-content-end">
          <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply Focus</button>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Total DBMS Records</div>
            <div class="h4 mb-0"><?php echo number_format($counts['total']); ?></div>
            <div class="small text-muted">My queue: <?php echo number_format($counts['my_assigned']); ?></div>
          </div>
          <div class="fs-4 text-primary"><i class="bi bi-database"></i></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Currently Assigned</div>
            <div class="h4 mb-0"><?php echo number_format($ownershipSummary['assigned_records']); ?></div>
            <div class="small text-muted">Research <?php echo number_format($ownershipSummary['research_owned']); ?> · CDQA <?php echo number_format($ownershipSummary['cdqa_owned']); ?></div>
          </div>
          <div class="fs-4 text-success"><i class="bi bi-person-lines-fill"></i></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Touched Today</div>
            <div class="h4 mb-0"><?php echo number_format($ownershipSummary['touched_today']); ?></div>
            <div class="small text-muted"><?php echo htmlspecialchars($metricPct($ownershipSummary['touched_today'], $ownershipSummary['assigned_records'])); ?> of live ownership</div>
          </div>
          <div class="fs-4 text-warning"><i class="bi bi-activity"></i></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Untouched Assigned</div>
            <div class="h4 mb-0"><?php echo number_format($ownershipSummary['untouched_records']); ?></div>
            <div class="small text-muted">Unassigned pool: <?php echo number_format($ownershipSummary['unassigned_records']); ?></div>
          </div>
          <div class="fs-4 text-danger"><i class="bi bi-exclamation-diamond"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <?php foreach ($windowMetrics as $window): ?>
      <?php $metrics = (array)($window['metrics'] ?? []); ?>
      <div class="col-12 col-xl-4">
        <div class="card hr-card dbms-panel h-100">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-week me-1"></i><?php echo htmlspecialchars((string)$window['label']); ?> Count</span>
            <span class="dbms-chip tone-slate"><?php echo htmlspecialchars((string)$window['sub']); ?></span>
          </div>
          <div class="card-body">
            <div class="dbms-period-grid">
              <div class="dbms-period-stat">
                <div class="dbms-period-label">Uploaded Leads</div>
                <div class="dbms-period-value"><?php echo number_format((int)($metrics['uploaded_rows'] ?? 0)); ?></div>
                <div class="dbms-period-note"><?php echo number_format((int)($metrics['uploaded_batches'] ?? 0)); ?> batches</div>
              </div>
              <div class="dbms-period-stat">
                <div class="dbms-period-label">Assignments</div>
                <div class="dbms-period-value"><?php echo number_format((int)($metrics['assignments_made'] ?? 0)); ?></div>
                <div class="dbms-period-note">Records assigned</div>
              </div>
              <div class="dbms-period-stat">
                <div class="dbms-period-label">Updated / Touched</div>
                <div class="dbms-period-value"><?php echo number_format((int)($metrics['touched_records'] ?? 0)); ?></div>
                <div class="dbms-period-note">Meaningful activity</div>
              </div>
              <div class="dbms-period-stat">
                <div class="dbms-period-label">Research Done</div>
                <div class="dbms-period-value"><?php echo number_format((int)($metrics['research_done'] ?? 0)); ?></div>
                <div class="dbms-period-note">Submitted onward</div>
              </div>
              <div class="dbms-period-stat">
                <div class="dbms-period-label">CDQA Done</div>
                <div class="dbms-period-value"><?php echo number_format((int)($metrics['cdqa_done'] ?? 0)); ?></div>
                <div class="dbms-period-note">Decisions made</div>
              </div>
              <div class="dbms-period-stat">
                <div class="dbms-period-label">Approved / Rework</div>
                <div class="dbms-period-value"><?php echo number_format((int)($metrics['approved'] ?? 0)); ?> / <?php echo number_format((int)($metrics['rework'] ?? 0)); ?></div>
                <div class="dbms-period-note">Outcome split</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12 col-xl-8">
      <div class="card hr-card dbms-panel h-100">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-people me-1"></i>Ownership and Touch Tracking</span>
          <span class="dbms-note"><?php echo htmlspecialchars($rangeLabel); ?> focus with fixed daily and monthly touch counts</span>
        </div>
        <div class="dbms-table-toolbar">
          <div class="dbms-table-toolbar-main">
            <span class="dbms-table-count"><?php echo number_format(count($ownerRows)); ?> owners</span>
            <span class="dbms-table-count"><?php echo number_format($ownershipSummary['assigned_records']); ?> records assigned</span>
            <span class="dbms-table-count"><?php echo number_format($ownershipSummary['untouched_records']); ?> untouched backlog</span>
          </div>
          <div class="dbms-table-toolbar-side">
            <span class="dbms-note">Shows who holds the load now, how much they touched today and this month, and how much still remains untouched.</span>
          </div>
        </div>
        <div class="dbms-table-wrap">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>Owner</th>
                  <th>Lane</th>
                  <th class="text-end">Current Assigned</th>
                  <th class="text-end">Updated Today</th>
                  <th class="text-end">Updated Month</th>
                  <th class="text-end">Not Touched</th>
                  <th class="text-end">Research Done</th>
                  <th class="text-end">CDQA Done</th>
                  <th>Status</th>
                  <th>Last Activity</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ownerRows)): ?>
                  <tr>
                    <td colspan="10" class="text-center text-muted py-4">No DBMS ownership data is available yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($ownerRows as $row): ?>
                    <?php $status = $ownerStatusMeta($row); ?>
                    <tr>
                      <td>
                        <div class="dbms-meta-stack">
                          <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($row['full_name'] ?? '')); ?></div>
                          <div class="dbms-meta-subtitle">Owner #<?php echo (int)($row['id'] ?? 0); ?></div>
                        </div>
                      </td>
                      <td>
                        <span class="dbms-chip <?php echo htmlspecialchars(((string)($row['lane_key'] ?? '') === 'cdqa') ? 'tone-info' : 'tone-warning'); ?>">
                          <?php echo htmlspecialchars((string)($row['lane_label'] ?? 'General')); ?>
                        </span>
                      </td>
                      <td class="text-end fw-semibold"><?php echo number_format((int)($row['current_assigned'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['touched_today'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['touched_month'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['untouched_current'] ?? 0)); ?></td>
                      <td class="text-end">
                        <div class="dbms-owner-metric">
                          <span class="dbms-owner-metric-main"><?php echo number_format((int)($row['research_done_month'] ?? 0)); ?></span>
                          <span class="dbms-owner-metric-sub">Today <?php echo number_format((int)($row['research_done_today'] ?? 0)); ?></span>
                        </div>
                      </td>
                      <td class="text-end">
                        <div class="dbms-owner-metric">
                          <span class="dbms-owner-metric-main"><?php echo number_format((int)($row['cdqa_done_month'] ?? 0)); ?></span>
                          <span class="dbms-owner-metric-sub">Today <?php echo number_format((int)($row['cdqa_done_today'] ?? 0)); ?></span>
                        </div>
                      </td>
                      <td>
                        <span class="dbms-status-badge <?php echo htmlspecialchars((string)($status['class'] ?? 'dbms-status-slate')); ?>">
                          <?php echo htmlspecialchars((string)($status['label'] ?? 'Assigned')); ?>
                        </span>
                      </td>
                      <td>
                        <div class="dbms-meta-subtitle"><?php echo htmlspecialchars($dateTimeLabel((string)($row['last_touch_at'] ?? ''))); ?></div>
                        <div class="dbms-meta-subtitle">Stale queue: <?php echo number_format((int)($row['stale_queue'] ?? 0)); ?></div>
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

    <div class="col-12 col-xl-4">
      <div class="row g-3">
        <div class="col-12">
          <div class="card hr-card dbms-panel">
            <div class="card-header fw-semibold"><i class="bi bi-binoculars me-1"></i>Queue Attention</div>
            <div class="card-body dbms-insight-stack">
              <div class="dbms-insight-card">
                <div class="dbms-insight-label">Pending Research</div>
                <div class="dbms-insight-value"><?php echo number_format($counts['pending_research']); ?></div>
                <div class="dbms-insight-note">Unowned or waiting to be pushed into Research work.</div>
              </div>
              <div class="dbms-insight-card">
                <div class="dbms-insight-label">Pending CDQA</div>
                <div class="dbms-insight-value"><?php echo number_format($counts['pending_cdqa']); ?></div>
                <div class="dbms-insight-note">Ready for QA review but not yet fully closed.</div>
              </div>
              <div class="dbms-insight-card">
                <div class="dbms-insight-label">Research 6+ Days</div>
                <div class="dbms-insight-value"><?php echo number_format($aging['research_6_plus']); ?></div>
                <div class="dbms-insight-note">Records that need leadership attention inside the Research lane.</div>
              </div>
              <div class="dbms-insight-card">
                <div class="dbms-insight-label">CDQA 6+ Days</div>
                <div class="dbms-insight-value"><?php echo number_format($aging['cdqa_6_plus']); ?></div>
                <div class="dbms-insight-note">Long-aging QA queue that may affect turnaround.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card hr-card dbms-panel">
            <div class="card-header fw-semibold"><i class="bi bi-cloud-arrow-up me-1"></i>Upload Ownership</div>
            <div class="card-body">
              <?php if (empty($uploaderRows)): ?>
                <div class="dbms-note">No uploads were found in the selected focus window.</div>
              <?php else: ?>
                <div class="dbms-uploader-list">
                  <?php foreach ($uploaderRows as $row): ?>
                    <div class="dbms-uploader-item">
                      <div>
                        <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($row['full_name'] ?? 'System')); ?></div>
                        <div class="dbms-meta-subtitle"><?php echo htmlspecialchars($dateTimeLabel((string)($row['last_uploaded_at'] ?? ''))); ?></div>
                      </div>
                      <div class="text-end">
                        <div class="dbms-owner-metric-main"><?php echo number_format((int)($row['uploaded_rows'] ?? 0)); ?></div>
                        <div class="dbms-owner-metric-sub"><?php echo number_format((int)($row['batches_count'] ?? 0)); ?> batches</div>
                      </div>
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
    <div class="col-lg-6">
      <div class="card hr-card dbms-panel h-100">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-journal-text me-1"></i>Research Team View</span>
          <span class="dbms-chip tone-warning"><?php echo number_format(count($researchTeam)); ?> owners</span>
        </div>
        <div class="dbms-table-wrap">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>Research Owner</th>
                  <th class="text-end">Current</th>
                  <th class="text-end">Touched Month</th>
                  <th class="text-end">Research Done</th>
                  <th class="text-end">Not Touched</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($researchTeam)): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">No Research ownership data found.</td></tr>
                <?php else: ?>
                  <?php foreach ($researchTeam as $row): ?>
                    <tr>
                      <td>
                        <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($row['full_name'] ?? '')); ?></div>
                        <div class="dbms-meta-subtitle"><?php echo htmlspecialchars($dateTimeLabel((string)($row['last_touch_at'] ?? ''))); ?></div>
                      </td>
                      <td class="text-end fw-semibold"><?php echo number_format((int)($row['current_assigned'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['touched_month'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['research_done_month'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['untouched_current'] ?? 0)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card hr-card dbms-panel h-100">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-shield-check me-1"></i>CDQA Team View</span>
          <span class="dbms-chip tone-info"><?php echo number_format(count($cdqaTeam)); ?> owners</span>
        </div>
        <div class="dbms-table-wrap">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>CDQA Owner</th>
                  <th class="text-end">Current</th>
                  <th class="text-end">Touched Month</th>
                  <th class="text-end">CDQA Done</th>
                  <th class="text-end">Not Touched</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($cdqaTeam)): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">No CDQA ownership data found.</td></tr>
                <?php else: ?>
                  <?php foreach ($cdqaTeam as $row): ?>
                    <tr>
                      <td>
                        <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($row['full_name'] ?? '')); ?></div>
                        <div class="dbms-meta-subtitle"><?php echo htmlspecialchars($dateTimeLabel((string)($row['last_touch_at'] ?? ''))); ?></div>
                      </td>
                      <td class="text-end fw-semibold"><?php echo number_format((int)($row['current_assigned'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['touched_month'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['cdqa_done_month'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['untouched_current'] ?? 0)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card hr-card dbms-panel h-100">
        <div class="card-header fw-semibold">Research SLA Aging</div>
        <div class="card-body">
          <div class="dbms-period-grid dbms-period-grid-compact">
            <div class="dbms-period-stat">
              <div class="dbms-period-label">0-2 Days</div>
              <div class="dbms-period-value"><?php echo number_format($aging['research_0_2']); ?></div>
              <div class="dbms-period-note">Healthy queue</div>
            </div>
            <div class="dbms-period-stat">
              <div class="dbms-period-label">3-5 Days</div>
              <div class="dbms-period-value"><?php echo number_format($aging['research_3_5']); ?></div>
              <div class="dbms-period-note">Needs monitoring</div>
            </div>
            <div class="dbms-period-stat">
              <div class="dbms-period-label">6+ Days</div>
              <div class="dbms-period-value"><?php echo number_format($aging['research_6_plus']); ?></div>
              <div class="dbms-period-note">Escalation risk</div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card hr-card dbms-panel h-100">
        <div class="card-header fw-semibold">CDQA SLA Aging</div>
        <div class="card-body">
          <div class="dbms-period-grid dbms-period-grid-compact">
            <div class="dbms-period-stat">
              <div class="dbms-period-label">0-2 Days</div>
              <div class="dbms-period-value"><?php echo number_format($aging['cdqa_0_2']); ?></div>
              <div class="dbms-period-note">Healthy queue</div>
            </div>
            <div class="dbms-period-stat">
              <div class="dbms-period-label">3-5 Days</div>
              <div class="dbms-period-value"><?php echo number_format($aging['cdqa_3_5']); ?></div>
              <div class="dbms-period-note">Needs monitoring</div>
            </div>
            <div class="dbms-period-stat">
              <div class="dbms-period-label">6+ Days</div>
              <div class="dbms-period-value"><?php echo number_format($aging['cdqa_6_plus']); ?></div>
              <div class="dbms-period-note">Escalation risk</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card hr-card dbms-panel">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
      <span><i class="bi bi-clock-history me-1"></i>Recent Upload Batches</span>
      <?php if (userHasPermission('dbms.reports')): ?>
        <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch">View All</a>
      <?php endif; ?>
    </div>
    <div class="dbms-table-wrap">
      <?php if (empty($recentBatches)): ?>
        <div class="analytics-empty">No DBMS batches uploaded yet.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Uploaded</th>
                <th>File</th>
                <th>By</th>
                <th class="text-end">Rows</th>
                <th class="text-end">Processed</th>
                <th class="text-end">Pending Research</th>
                <th class="text-end">Pending CDQA</th>
                <th class="text-end">Approved</th>
                <th class="text-end">Rework</th>
                <th>Status</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentBatches as $batch): ?>
                <?php
                  $batchId = (int)($batch['id'] ?? 0);
                  $wf = $recentBatchCounts[$batchId] ?? [];
                  $processedRows = $hasBatchProcessedRows ? (int)($batch['processed_rows'] ?? 0) : (int)($batch['total_rows'] ?? 0);
                  if ($processedRows <= 0) {
                      $processedRows = (int)($batch['total_rows'] ?? 0);
                  }
                ?>
                <tr>
                  <td class="font-monospace">#<?php echo $batchId; ?></td>
                  <td class="font-monospace"><?php echo htmlspecialchars((string)($batch['uploaded_at'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string)($batch['original_filename'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string)($batch['uploaded_by_name'] ?? 'System')); ?></td>
                  <td class="text-end"><?php echo number_format((int)($batch['total_rows'] ?? 0)); ?></td>
                  <td class="text-end"><?php echo number_format($processedRows); ?></td>
                  <td class="text-end"><?php echo number_format((int)($wf['pending_research'] ?? (int)($batch['append_rows'] ?? 0))); ?></td>
                  <td class="text-end"><?php echo number_format((int)($wf['pending_cdqa'] ?? (int)($batch['valid_rows'] ?? 0))); ?></td>
                  <td class="text-end"><?php echo number_format((int)($wf['approved'] ?? 0)); ?></td>
                  <td class="text-end"><?php echo number_format((int)($wf['research_rework'] ?? 0)); ?></td>
                  <td><span class="dbms-status-badge dbms-status-slate"><?php echo htmlspecialchars((string)($batch['status'] ?? 'uploaded')); ?></span></td>
                  <td class="text-end">
                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch?id=<?php echo $batchId; ?>"><i class="bi bi-eye"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
