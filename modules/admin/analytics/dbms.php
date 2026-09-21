
<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

requirePermissionOrRole('admin.analytics.dbms.page', ['admin','director','manager_director','operations_director','operations_manager']);
ensureDatabaseSchema();
ensureCsrfToken();

$conn = getDbConnection();
$now = new DateTime();
$workflowExpr = dbmsWorkflowStatusSql('c');

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? date('Y-m-01 00:00:00'));
$rangeEnd = (string)($range['end'] ?? date('Y-m-t 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));

$analyticsTabs = [
    'campaigns' => ['label' => 'Campaigns', 'href' => appBasePath() . '/modules/admin/analytics/campaigns', 'icon' => 'bi-megaphone'],
    'agents' => ['label' => 'Agents', 'href' => appBasePath() . '/modules/admin/analytics/agents', 'icon' => 'bi-people'],
    'sales' => ['label' => 'Sales', 'href' => appBasePath() . '/modules/admin/analytics/sales', 'icon' => 'bi-funnel'],
    'dbms' => ['label' => 'DBMS', 'href' => appBasePath() . '/modules/admin/analytics/dbms', 'icon' => 'bi-database-check'],
];
$currentTab = 'dbms';
$adminDashboardUrl = appBasePath() . '/modules/dashboard/admin-dashboard';

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
      SUM({$workflowExpr} = 'duplicate') AS duplicate_cnt
    FROM dbms_contacts c
");
if ($stmtCounts) {
    $stmtCounts->execute();
    $r = $stmtCounts->get_result()->fetch_assoc() ?: [];
    $stmtCounts->close();
    $counts['total'] = (int)($r['total_cnt'] ?? 0);
    $counts['pending_research'] = (int)($r['pending_research_cnt'] ?? 0);
    $counts['research_assigned'] = (int)($r['research_assigned_cnt'] ?? 0);
    $counts['research_rework'] = (int)($r['research_rework_cnt'] ?? 0);
    $counts['pending_cdqa'] = (int)($r['pending_cdqa_cnt'] ?? 0);
    $counts['cdqa_assigned'] = (int)($r['cdqa_assigned_cnt'] ?? 0);
    $counts['approved'] = (int)($r['approved_cnt'] ?? 0);
    $counts['rejected'] = (int)($r['rejected_cnt'] ?? 0);
    $counts['duplicate'] = (int)($r['duplicate_cnt'] ?? 0);
}

$hasResearchSubmittedAt = dbmsContactsHasColumn('research_submitted_at');
$hasResearchSubmittedBy = dbmsContactsHasColumn('research_submitted_by');
$hasCdqaDecision = dbmsContactsHasColumn('cdqa_decision');
$hasCdqaDecisionAt = dbmsContactsHasColumn('cdqa_decision_at');
$hasCdqaDecisionBy = dbmsContactsHasColumn('cdqa_decision_by');
$hasQueueEnteredAt = dbmsContactsHasColumn('queue_entered_at');
$hasBatchProcessedRows = dbmsBatchesHasColumn('processed_rows');

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
$rangeCounts = [
    'created' => 0,
    'research_assigned' => 0,
    'research_submitted' => 0,
    'cdqa_assigned' => 0,
    'approved' => 0,
    'rework' => 0,
];
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
    $r = $stmtRange->get_result()->fetch_assoc() ?: [];
    $stmtRange->close();
    $rangeCounts['created'] = (int)($r['created_cnt'] ?? 0);
    $rangeCounts['research_assigned'] = (int)($r['research_assigned_cnt'] ?? 0);
    $rangeCounts['research_submitted'] = (int)($r['research_submitted_cnt'] ?? 0);
    $rangeCounts['cdqa_assigned'] = (int)($r['cdqa_assigned_cnt'] ?? 0);
    $rangeCounts['approved'] = (int)($r['approved_cnt'] ?? 0);
    $rangeCounts['rework'] = (int)($r['rework_cnt'] ?? 0);
}

$batchSummary = ['batches' => 0, 'rows' => 0, 'processed' => 0];
$stmtBatchSummary = $conn->prepare("
    SELECT COUNT(*) AS batch_cnt,
           COALESCE(SUM(total_rows), 0) AS total_rows_cnt,
           COALESCE(SUM(" . ($hasBatchProcessedRows ? "processed_rows" : "total_rows") . "), 0) AS processed_rows_cnt
    FROM dbms_batches
    WHERE uploaded_at BETWEEN ? AND ?
");
if ($stmtBatchSummary) {
    $stmtBatchSummary->bind_param('ss', $rangeStart, $rangeEnd);
    $stmtBatchSummary->execute();
    $r = $stmtBatchSummary->get_result()->fetch_assoc() ?: [];
    $stmtBatchSummary->close();
    $batchSummary['batches'] = (int)($r['batch_cnt'] ?? 0);
    $batchSummary['rows'] = (int)($r['total_rows_cnt'] ?? 0);
    $batchSummary['processed'] = (int)($r['processed_rows_cnt'] ?? 0);
}

$buildTeamMap = function(): array {
    return [];
};
$mergeMetric = function(array &$team, int $id, string $name, string $metric, int $value): void {
    if ($id <= 0) return;
    if (!isset($team[$id])) {
        $team[$id] = [
            'id' => $id,
            'full_name' => $name !== '' ? $name : ('User #' . $id),
            'assigned_in_range' => 0,
            'submitted_in_range' => 0,
            'approved_in_range' => 0,
            'rejected_in_range' => 0,
            'active_queue' => 0,
            'rework_queue' => 0,
        ];
    }
    $team[$id][$metric] = $value;
};
$researchTeam = $buildTeamMap();
$cdqaTeam = $buildTeamMap();

$stmtResearchAssigned = $conn->prepare("
    SELECT a.assigned_to_user_id AS id, u.full_name, COUNT(*) AS metric_value
    FROM dbms_assignments a
    JOIN users u ON u.id = a.assigned_to_user_id
    WHERE LOWER(TRIM(COALESCE(a.department, ''))) = 'research'
      AND a.assigned_at BETWEEN ? AND ?
    GROUP BY a.assigned_to_user_id, u.full_name
");
if ($stmtResearchAssigned) {
    $stmtResearchAssigned->bind_param('ss', $rangeStart, $rangeEnd);
    $stmtResearchAssigned->execute();
    foreach ($stmtResearchAssigned->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
        $mergeMetric($researchTeam, (int)($row['id'] ?? 0), (string)($row['full_name'] ?? ''), 'assigned_in_range', (int)($row['metric_value'] ?? 0));
    }
    $stmtResearchAssigned->close();
}

$stmtResearchActive = $conn->prepare("
    SELECT c.assigned_to_user_id AS id, u.full_name,
           SUM({$workflowExpr} = 'research_assigned') AS active_queue,
           SUM({$workflowExpr} = 'research_rework') AS rework_queue
    FROM dbms_contacts c
    JOIN users u ON u.id = c.assigned_to_user_id
    WHERE LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'research'
      AND {$workflowExpr} IN ('research_assigned','research_rework')
    GROUP BY c.assigned_to_user_id, u.full_name
");
if ($stmtResearchActive) {
    $stmtResearchActive->execute();
    foreach ($stmtResearchActive->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
        $id = (int)($row['id'] ?? 0);
        $name = (string)($row['full_name'] ?? '');
        $mergeMetric($researchTeam, $id, $name, 'active_queue', (int)($row['active_queue'] ?? 0));
        $mergeMetric($researchTeam, $id, $name, 'rework_queue', (int)($row['rework_queue'] ?? 0));
    }
    $stmtResearchActive->close();
}

if ($hasResearchSubmittedAt && $hasResearchSubmittedBy) {
    $stmtResearchSubmitted = $conn->prepare("
        SELECT c.research_submitted_by AS id, u.full_name, COUNT(*) AS metric_value
        FROM dbms_contacts c
        JOIN users u ON u.id = c.research_submitted_by
        WHERE c.research_submitted_at BETWEEN ? AND ?
        GROUP BY c.research_submitted_by, u.full_name
    ");
    if ($stmtResearchSubmitted) {
        $stmtResearchSubmitted->bind_param('ss', $rangeStart, $rangeEnd);
        $stmtResearchSubmitted->execute();
        foreach ($stmtResearchSubmitted->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $mergeMetric($researchTeam, (int)($row['id'] ?? 0), (string)($row['full_name'] ?? ''), 'submitted_in_range', (int)($row['metric_value'] ?? 0));
        }
        $stmtResearchSubmitted->close();
    }
}

$stmtCdqaAssigned = $conn->prepare("
    SELECT a.assigned_to_user_id AS id, u.full_name, COUNT(*) AS metric_value
    FROM dbms_assignments a
    JOIN users u ON u.id = a.assigned_to_user_id
    WHERE LOWER(TRIM(COALESCE(a.department, ''))) = 'cdqa'
      AND a.assigned_at BETWEEN ? AND ?
    GROUP BY a.assigned_to_user_id, u.full_name
");
if ($stmtCdqaAssigned) {
    $stmtCdqaAssigned->bind_param('ss', $rangeStart, $rangeEnd);
    $stmtCdqaAssigned->execute();
    foreach ($stmtCdqaAssigned->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
        $mergeMetric($cdqaTeam, (int)($row['id'] ?? 0), (string)($row['full_name'] ?? ''), 'assigned_in_range', (int)($row['metric_value'] ?? 0));
    }
    $stmtCdqaAssigned->close();
}

$stmtCdqaActive = $conn->prepare("
    SELECT c.assigned_to_user_id AS id, u.full_name, COUNT(*) AS active_queue
    FROM dbms_contacts c
    JOIN users u ON u.id = c.assigned_to_user_id
    WHERE LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'cdqa'
      AND {$workflowExpr} = 'cdqa_assigned'
    GROUP BY c.assigned_to_user_id, u.full_name
");
if ($stmtCdqaActive) {
    $stmtCdqaActive->execute();
    foreach ($stmtCdqaActive->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
        $mergeMetric($cdqaTeam, (int)($row['id'] ?? 0), (string)($row['full_name'] ?? ''), 'active_queue', (int)($row['active_queue'] ?? 0));
    }
    $stmtCdqaActive->close();
}

if ($hasCdqaDecision && $hasCdqaDecisionAt && $hasCdqaDecisionBy) {
    $stmtCdqaDecisions = $conn->prepare("
        SELECT c.cdqa_decision_by AS id, u.full_name,
               SUM(c.cdqa_decision = 'approved') AS approved_in_range,
               SUM(c.cdqa_decision = 'rejected') AS rejected_in_range
        FROM dbms_contacts c
        JOIN users u ON u.id = c.cdqa_decision_by
        WHERE c.cdqa_decision_at BETWEEN ? AND ?
        GROUP BY c.cdqa_decision_by, u.full_name
    ");
    if ($stmtCdqaDecisions) {
        $stmtCdqaDecisions->bind_param('ss', $rangeStart, $rangeEnd);
        $stmtCdqaDecisions->execute();
        foreach ($stmtCdqaDecisions->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            $name = (string)($row['full_name'] ?? '');
            $mergeMetric($cdqaTeam, $id, $name, 'approved_in_range', (int)($row['approved_in_range'] ?? 0));
            $mergeMetric($cdqaTeam, $id, $name, 'rejected_in_range', (int)($row['rejected_in_range'] ?? 0));
        }
        $stmtCdqaDecisions->close();
    }
}

$researchTeam = array_values($researchTeam);
$cdqaTeam = array_values($cdqaTeam);
usort($researchTeam, fn($a, $b) => (($b['submitted_in_range'] + $b['active_queue']) <=> ($a['submitted_in_range'] + $a['active_queue'])) ?: strcmp((string)$a['full_name'], (string)$b['full_name']));
usort($cdqaTeam, fn($a, $b) => (($b['approved_in_range'] + $b['active_queue']) <=> ($a['approved_in_range'] + $a['active_queue'])) ?: strcmp((string)$a['full_name'], (string)$b['full_name']));

$ageExpr = $hasQueueEnteredAt ? "COALESCE(c.queue_entered_at, c.assigned_at, c.created_at)" : "COALESCE(c.assigned_at, c.created_at)";
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
      SUM({$workflowExpr} IN ('pending_research','research_assigned','research_rework') AND TIMESTAMPDIFF(DAY, {$ageExpr}, NOW()) BETWEEN 0 AND 2) AS research_0_2,
      SUM({$workflowExpr} IN ('pending_research','research_assigned','research_rework') AND TIMESTAMPDIFF(DAY, {$ageExpr}, NOW()) BETWEEN 3 AND 5) AS research_3_5,
      SUM({$workflowExpr} IN ('pending_research','research_assigned','research_rework') AND TIMESTAMPDIFF(DAY, {$ageExpr}, NOW()) >= 6) AS research_6_plus,
      SUM({$workflowExpr} IN ('pending_cdqa','cdqa_assigned') AND TIMESTAMPDIFF(DAY, {$ageExpr}, NOW()) BETWEEN 0 AND 2) AS cdqa_0_2,
      SUM({$workflowExpr} IN ('pending_cdqa','cdqa_assigned') AND TIMESTAMPDIFF(DAY, {$ageExpr}, NOW()) BETWEEN 3 AND 5) AS cdqa_3_5,
      SUM({$workflowExpr} IN ('pending_cdqa','cdqa_assigned') AND TIMESTAMPDIFF(DAY, {$ageExpr}, NOW()) >= 6) AS cdqa_6_plus
    FROM dbms_contacts c
");
if ($stmtAging) {
    $stmtAging->execute();
    $r = $stmtAging->get_result()->fetch_assoc() ?: [];
    $stmtAging->close();
    foreach (array_keys($aging) as $key) {
        $aging[$key] = (int)($r[$key] ?? 0);
    }
}

$recentBatches = [];
$rsB = $conn->query("
  SELECT b.*, u.full_name AS uploaded_by_name
  FROM dbms_batches b
  LEFT JOIN users u ON u.id = b.uploaded_by
  ORDER BY b.uploaded_at DESC, b.id DESC
  LIMIT 8
");
if ($rsB) {
    $recentBatches = $rsB->fetch_all(MYSQLI_ASSOC) ?: [];
}

$recentBatchCounts = [];
$recentBatchIds = array_values(array_filter(array_map(fn($b) => (int)($b['id'] ?? 0), $recentBatches), fn($x) => $x > 0));
if (!empty($recentBatchIds)) {
    $in = implode(',', array_fill(0, count($recentBatchIds), '?'));
    $types = str_repeat('i', count($recentBatchIds));
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
        mysqliBindParams($stmtBatchCounts, $types, $recentBatchIds);
        $stmtBatchCounts->execute();
        foreach ($stmtBatchCounts->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $recentBatchCounts[(int)($row['batch_id'] ?? 0)] = $row;
        }
        $stmtBatchCounts->close();
    }
}

$approvalPct = $rangeCounts['created'] > 0 ? round(($rangeCounts['approved'] / max(1, $rangeCounts['created'])) * 100, 1) : 0.0;

$pageTitle = 'DBMS Analytics';
include __DIR__ . '/../../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 admin-dashboard analytics-workspace">
  <div class="analytics-hero mb-3">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
      <div>
        <span class="analytics-eyebrow"><i class="bi bi-database-check"></i>Admin Analytics</span>
        <div class="analytics-title mt-2">DBMS Department Analytics</div>
        <div class="analytics-subtitle">Admin-level view of DBMS queue health, research and CDQA throughput, backlog aging, and upload tracking with direct links into the operational DBMS pages.</div>
      </div>
      <div class="analytics-chip-row">
        <span class="analytics-chip"><?php echo htmlspecialchars($rangeLabel); ?></span>
        <span class="analytics-chip"><?php echo number_format($counts['total']); ?> total records</span>
        <span class="analytics-chip"><?php echo number_format($approvalPct, 1); ?>% approved in range</span>
        <span class="analytics-chip"><?php echo number_format($batchSummary['batches']); ?> batches in range</span>
      </div>
    </div>
    <div class="analytics-nav nav nav-pills mt-3">
      <?php foreach ($analyticsTabs as $key => $tab): ?>
        <a class="nav-link <?php echo $currentTab === $key ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($tab['href']); ?>">
          <i class="bi <?php echo htmlspecialchars((string)$tab['icon']); ?> me-1"></i><?php echo htmlspecialchars((string)$tab['label']); ?>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="analytics-action-row mt-3">
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars($adminDashboardUrl); ?>"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms"><i class="bi bi-speedometer2 me-1"></i>DBMS Dashboard</a>
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/explorer"><i class="bi bi-search me-1"></i>Explorer</a>
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/assignments"><i class="bi bi-person-check me-1"></i>Assignments</a>
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch"><i class="bi bi-bar-chart me-1"></i>Batches</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm analytics-panel analytics-filter-card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end analytics-inline-form" method="get">
        <div class="col-md-3">
          <label class="form-label small text-muted">Range</label>
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
          <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
      <a class="analytics-kpi-link" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/explorer">
        <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
          <div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small">Total Records</div>
              <div class="h4 mb-0"><?php echo number_format($counts['total']); ?></div>
              <div class="small text-muted">New in range: <?php echo number_format($rangeCounts['created']); ?></div>
            </div>
            <div class="text-primary fs-3"><i class="bi bi-database"></i></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-lg-3">
      <a class="analytics-kpi-link" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/assignments?queue=pending_research&unassigned=1">
        <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
          <div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small">Pending Research</div>
              <div class="h4 mb-0"><?php echo number_format($counts['pending_research']); ?></div>
              <div class="small text-muted">Rework: <?php echo number_format($counts['research_rework']); ?></div>
            </div>
            <div class="text-warning fs-3"><i class="bi bi-journal-text"></i></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-lg-3">
      <a class="analytics-kpi-link" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/assignments?queue=pending_cdqa&unassigned=1">
        <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
          <div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small">Pending CDQA</div>
              <div class="h4 mb-0"><?php echo number_format($counts['pending_cdqa']); ?></div>
              <div class="small text-muted">Assigned: <?php echo number_format($counts['cdqa_assigned']); ?></div>
            </div>
            <div class="text-info fs-3"><i class="bi bi-shield-check"></i></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-lg-3">
      <a class="analytics-kpi-link" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch">
        <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
          <div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small">Approved In Range</div>
              <div class="h4 mb-0"><?php echo number_format($rangeCounts['approved']); ?></div>
              <div class="small text-muted">Batches: <?php echo number_format($batchSummary['batches']); ?></div>
            </div>
            <div class="text-success fs-3"><i class="bi bi-patch-check"></i></div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12 col-xl-8">
      <div class="card border-0 shadow-sm analytics-panel h-100">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-speedometer2 me-1"></i>DBMS Workflow Throughput</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6 col-md-4 col-xl-2"><div class="analytics-summary-card"><div class="analytics-summary-label">New Records</div><div class="analytics-summary-value"><?php echo number_format($rangeCounts['created']); ?></div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="analytics-summary-card"><div class="analytics-summary-label">Research Assigned</div><div class="analytics-summary-value"><?php echo number_format($rangeCounts['research_assigned']); ?></div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="analytics-summary-card"><div class="analytics-summary-label">Submitted to CDQA</div><div class="analytics-summary-value"><?php echo number_format($rangeCounts['research_submitted']); ?></div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="analytics-summary-card"><div class="analytics-summary-label">CDQA Assigned</div><div class="analytics-summary-value"><?php echo number_format($rangeCounts['cdqa_assigned']); ?></div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="analytics-summary-card"><div class="analytics-summary-label">Approved</div><div class="analytics-summary-value"><?php echo number_format($rangeCounts['approved']); ?></div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="analytics-summary-card"><div class="analytics-summary-label">Rework</div><div class="analytics-summary-value"><?php echo number_format($rangeCounts['rework']); ?></div></div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-4">
      <div class="card border-0 shadow-sm analytics-panel h-100">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-cloud-arrow-up me-1"></i>Batch Tracking</div>
        <div class="card-body">
          <div class="analytics-summary-card mb-3">
            <div class="analytics-summary-label">Uploaded Batches</div>
            <div class="analytics-summary-value"><?php echo number_format($batchSummary['batches']); ?></div>
            <div class="small text-muted">Rows: <?php echo number_format($batchSummary['rows']); ?> · Processed: <?php echo number_format($batchSummary['processed']); ?></div>
          </div>
          <div class="analytics-summary-card">
            <div class="analytics-summary-label">Current Queue Risk</div>
            <div class="analytics-summary-value"><?php echo number_format($aging['research_6_plus'] + $aging['cdqa_6_plus']); ?></div>
            <div class="small text-muted">Records aged 6+ days across Research and CDQA queues.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm analytics-panel h-100">
        <div class="card-header bg-light fw-semibold">Research Team Productivity</div>
        <div class="card-body p-0 analytics-table-wrap">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>User</th>
                  <th class="text-end">Assigned</th>
                  <th class="text-end">Submitted</th>
                  <th class="text-end">Active</th>
                  <th class="text-end">Rework</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($researchTeam)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-3">No Research productivity data in range.</td></tr>
                <?php else: ?>
                  <?php foreach (array_slice($researchTeam, 0, 8) as $row): ?>
                    <tr>
                      <td class="text-muted small"><?php echo htmlspecialchars((string)($row['full_name'] ?? '')); ?></td>
                      <td class="text-end fw-semibold"><?php echo number_format((int)($row['assigned_in_range'] ?? 0)); ?></td>
                      <td class="text-end fw-semibold"><?php echo number_format((int)($row['submitted_in_range'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['active_queue'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['rework_queue'] ?? 0)); ?></td>
                      <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/research"><i class="bi bi-box-arrow-up-right"></i></a></td>
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
      <div class="card border-0 shadow-sm analytics-panel h-100">
        <div class="card-header bg-light fw-semibold">CDQA Productivity</div>
        <div class="card-body p-0 analytics-table-wrap">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>User</th>
                  <th class="text-end">Assigned</th>
                  <th class="text-end">Approved</th>
                  <th class="text-end">Rejected</th>
                  <th class="text-end">In Review</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($cdqaTeam)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-3">No CDQA productivity data in range.</td></tr>
                <?php else: ?>
                  <?php foreach (array_slice($cdqaTeam, 0, 8) as $row): ?>
                    <tr>
                      <td class="text-muted small"><?php echo htmlspecialchars((string)($row['full_name'] ?? '')); ?></td>
                      <td class="text-end fw-semibold"><?php echo number_format((int)($row['assigned_in_range'] ?? 0)); ?></td>
                      <td class="text-end fw-semibold"><?php echo number_format((int)($row['approved_in_range'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['rejected_in_range'] ?? 0)); ?></td>
                      <td class="text-end"><?php echo number_format((int)($row['active_queue'] ?? 0)); ?></td>
                      <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/cdqa"><i class="bi bi-box-arrow-up-right"></i></a></td>
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
      <div class="card border-0 shadow-sm analytics-panel h-100">
        <div class="card-header bg-light fw-semibold">Research SLA Aging</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-4"><div class="analytics-summary-card"><div class="analytics-summary-label">0-2 Days</div><div class="analytics-summary-value"><?php echo number_format($aging['research_0_2']); ?></div></div></div>
            <div class="col-4"><div class="analytics-summary-card"><div class="analytics-summary-label">3-5 Days</div><div class="analytics-summary-value"><?php echo number_format($aging['research_3_5']); ?></div></div></div>
            <div class="col-4"><div class="analytics-summary-card"><div class="analytics-summary-label">6+ Days</div><div class="analytics-summary-value"><?php echo number_format($aging['research_6_plus']); ?></div></div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm analytics-panel h-100">
        <div class="card-header bg-light fw-semibold">CDQA SLA Aging</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-4"><div class="analytics-summary-card"><div class="analytics-summary-label">0-2 Days</div><div class="analytics-summary-value"><?php echo number_format($aging['cdqa_0_2']); ?></div></div></div>
            <div class="col-4"><div class="analytics-summary-card"><div class="analytics-summary-label">3-5 Days</div><div class="analytics-summary-value"><?php echo number_format($aging['cdqa_3_5']); ?></div></div></div>
            <div class="col-4"><div class="analytics-summary-card"><div class="analytics-summary-label">6+ Days</div><div class="analytics-summary-value"><?php echo number_format($aging['cdqa_6_plus']); ?></div></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm analytics-panel">
    <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
      <span><i class="bi bi-clock-history me-1"></i>Recent Upload Batches</span>
      <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch">View All</a>
    </div>
    <div class="card-body p-0 analytics-table-wrap">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
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
          <?php if (empty($recentBatches)): ?>
            <tr><td colspan="12" class="text-center text-muted py-4">No DBMS batches uploaded yet.</td></tr>
          <?php else: ?>
            <?php foreach ($recentBatches as $b): ?>
              <?php
                $bid = (int)($b['id'] ?? 0);
                $wf = $recentBatchCounts[$bid] ?? [];
                $processedRows = $hasBatchProcessedRows ? (int)($b['processed_rows'] ?? 0) : (int)($b['total_rows'] ?? 0);
                if ($processedRows <= 0) $processedRows = (int)($b['total_rows'] ?? 0);
              ?>
              <tr>
                <td class="font-monospace">#<?php echo $bid; ?></td>
                <td class="font-monospace"><?php echo htmlspecialchars((string)($b['uploaded_at'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($b['original_filename'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($b['uploaded_by_name'] ?? '')); ?></td>
                <td class="text-end"><?php echo number_format((int)($b['total_rows'] ?? 0)); ?></td>
                <td class="text-end"><?php echo number_format($processedRows); ?></td>
                <td class="text-end"><?php echo number_format((int)($wf['pending_research'] ?? 0)); ?></td>
                <td class="text-end"><?php echo number_format((int)($wf['pending_cdqa'] ?? 0)); ?></td>
                <td class="text-end"><?php echo number_format((int)($wf['approved'] ?? 0)); ?></td>
                <td class="text-end"><?php echo number_format((int)($wf['research_rework'] ?? 0)); ?></td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)($b['status'] ?? '')); ?></span></td>
                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch?id=<?php echo $bid; ?>"><i class="bi bi-eye"></i></a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../../includes/layout/app_end.php'; ?>
