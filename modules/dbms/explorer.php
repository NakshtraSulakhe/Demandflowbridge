<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.explorer', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$message = '';
$messageType = 'success';

$hasRevenueLink = dbmsContactsHasColumn('revenue_link');
$hasEmployeeSizeBracket = dbmsContactsHasColumn('employee_size_bracket');
$hasRevenueBracket = dbmsContactsHasColumn('revenue_bracket');

$safeCsvCell = function($v): string {
    $s = (string)$v;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = trim(preg_replace('/\\s+/', ' ', $s));
    $first = $s !== '' ? $s[0] : '';
    if ($first !== '' && in_array($first, ['=','+','-','@'], true)) $s = "'" . $s;
    return $s;
};

$getUsers = function() use ($conn): array {
    $rs = $conn->query("SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name ASC");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
};

$users = $getUsers();
$usersById = [];
foreach ($users as $u) {
    $uid = (int)($u['id'] ?? 0);
    if ($uid > 0) $usersById[$uid] = $u;
}
$permCfg = getAccessRolePermissionsConfig();
$assignmentDepartments = dbmsWorkflowAssignmentDepartments();
$userCanHandleDepartment = function(int $uid, string $department) use ($usersById, $permCfg): bool {
    if ($uid <= 0) return false;
    $u = $usersById[$uid] ?? null;
    if (!is_array($u)) return false;
    return dbmsUserCanHandleDepartment($u, $permCfg, $department);
};

$employeeBrackets = array_map('dbmsNormalizeFilterText', ['1-10','11-50','51-200','201-500','501-1000','1000-5000','5000-10000','10000+']);
$revenueBrackets = array_map('dbmsNormalizeFilterText', [
    'Less than $1 Million',
    '$1 Million – $10 Million',
    '$10 Million – $20 Million',
    '$20 Million – $50 Million',
    '$50 Million – $100 Million',
    '$100 Million – $250 Million',
    '$250 Million – $500 Million',
    '$500 Million – $1 Billion',
    '$1 Billion – $5 Billion',
    'More than $5 Billion',
]);
$emailStatusOptions = ['Valid','Invalid','Accept All','Bounced','Unknown'];
$lastEmailStatusOptions = ['Delivered','Open','Clicked','Bounced'];
$phoneStatusOptions = ['Connected','Connected - VM','Connected - Company VM','Invalid','Replaced'];
$dataStatusOptions = ['Valid','Invalid'];

$filters = dbmsExplorerFiltersFromRequest();
$explorerUrl = appBasePath() . '/modules/dbms/explorer';

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = (int)($_GET['ps'] ?? 50);
if (!in_array($pageSize, [25,50,100,200], true)) $pageSize = 50;
$offset = ($page - 1) * $pageSize;

$filterQuery = [];
foreach ($filters as $fk => $fv) {
    if ($fk === 'batch_id' || $fk === 'assigned_to') {
        if ((int)$fv > 0) {
            $filterQuery[$fk] = (int)$fv;
        }
        continue;
    }
    if (is_string($fv) && trim($fv) !== '') {
        $filterQuery[$fk] = $fv;
    }
}
$gridFormAction = $filterQuery !== [] ? ('?' . http_build_query($filterQuery)) : '';

$fetchIdsForFilter = function(string $whereSql, string $types, array $params): array {
    $conn = getDbConnection();
    $ids = [];
    $stmt = $conn->prepare("SELECT c.id FROM dbms_contacts c $whereSql ORDER BY c.id ASC");
    if (!$stmt) return $ids;
    if ($types !== '') mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
        if (count($ids) >= 50000) break;
    }
    $stmt->close();
    return $ids;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) throw new RuntimeException('Invalid request token.');

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'assign') {
            $assignTo = (int)($_POST['assign_to'] ?? 0);
            $dept = trim((string)($_POST['department'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $mode = trim((string)($_POST['select_mode'] ?? 'ids'));
            if (!isset($assignmentDepartments[$dept])) throw new RuntimeException('Select department.');
            if ($assignTo <= 0) throw new RuntimeException('Select user to assign.');
            if (!$userCanHandleDepartment($assignTo, $dept)) throw new RuntimeException('Selected user does not have access to this DBMS workflow lane.');

            $contactIds = [];
            if ($mode === 'all_filtered') {
                $params = []; $types = '';
                $whereSql = dbmsBuildExplorerWhere($filters, $params, $types);
                $stmtCt = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_contacts c $whereSql");
                $matchCount = 0;
                if ($stmtCt) {
                    if ($types !== '') mysqliBindParams($stmtCt, $types, $params);
                    $stmtCt->execute();
                    $matchCount = (int)(($stmtCt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
                    $stmtCt->close();
                }
                if ($matchCount <= 0) throw new RuntimeException('No records matched the current filters.');
                if ($matchCount > 50000) throw new RuntimeException('Too many records matched. Narrow your filters (max 50,000).');
                $contactIds = $fetchIdsForFilter($whereSql, $types, $params);
            } else {
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) $ids = [];
                foreach ($ids as $id) {
                    $id = (int)$id;
                    if ($id > 0) $contactIds[$id] = true;
                }
                $contactIds = array_keys($contactIds);
            }

            if (empty($contactIds)) throw new RuntimeException('Select records first.');

            $result = dbmsAssignContacts($contactIds, $dept, $assignTo, $userId, $note, false, 'dbms.explorer');
            $assignedCount = (int)($result['assigned_count'] ?? 0);
            if ($assignedCount <= 0) throw new RuntimeException('No records were assigned. They may already be assigned or not belong to this workflow lane.');

            $title = 'DBMS Records Assigned';
            $body = number_format($assignedCount) . ' records assigned to you (' . $dept . ').';
            $link = appBasePath() . '/modules/dbms/my';
            notifyUsers([$assignTo], 'dbms.records_assigned', $title, $body, $link, ['dedup_key' => 'dbms.assigned.' . $assignTo . '.' . time(), 'show_toast' => true]);

            $message = 'Assigned ' . number_format($assignedCount) . ' records.';
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$params = [];
$types = '';
$whereSql = dbmsBuildExplorerWhere($filters, $params, $types);
$queryError = '';

$total = 0;
$stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_contacts c $whereSql");
if ($stmtC) {
    if ($types !== '') {
        mysqliBindParams($stmtC, $types, $params);
    }
    $stmtC->execute();
    $total = (int)(($stmtC->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmtC->close();
} else {
    $queryError = (string)$conn->error;
}

$rows = [];
$stmt = $conn->prepare("
    SELECT c.*,
           " . dbmsWorkflowStatusSql('c') . " AS workflow_queue,
           uo.full_name AS owner_name,
           ua.full_name AS assigned_to_name
    FROM dbms_contacts c
    LEFT JOIN users uo ON uo.id = c.owner_user_id
    LEFT JOIN users ua ON ua.id = c.assigned_to_user_id
    $whereSql
    ORDER BY c.updated_at DESC, c.created_at DESC, c.id DESC
    LIMIT $pageSize OFFSET $offset
");
if ($stmt) {
    if ($types !== '') {
        mysqliBindParams($stmt, $types, $params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
} elseif ($queryError === '') {
    $queryError = (string)$conn->error;
}

$fetchDimensionOptions = function(array $columns, int $limit = 200) use ($conn): array {
    $merged = [];
    foreach ($columns as $column) {
        $column = trim($column);
        if ($column === '' || !dbmsContactsHasColumn($column)) continue;
        $sql = "SELECT TRIM(`{$column}`) AS value, COUNT(*) AS c
                FROM dbms_contacts
                WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) <> ''
                GROUP BY TRIM(`{$column}`)
                ORDER BY c DESC, value ASC
                LIMIT {$limit}";
        $rs = $conn->query($sql);
        if (!$rs) continue;
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $value = trim((string)($row['value'] ?? ''));
            if ($value === '') continue;
            $merged[$value] = ($merged[$value] ?? 0) + (int)($row['c'] ?? 0);
        }
    }
    uksort($merged, 'strnatcasecmp');
    uasort($merged, static function(int $a, int $b): int {
        return $b <=> $a;
    });
    $result = [];
    foreach ($merged as $value => $count) {
        $result[] = ['value' => $value, 'c' => $count];
        if (count($result) >= $limit) break;
    }
    return $result;
};

$countries = $fetchDimensionOptions(['prospect_country', 'country', 'company_country']);
$states = $fetchDimensionOptions(['state', 'company_state']);
$cities = $fetchDimensionOptions(['city', 'company_city']);
$industries = [];
$rs = $conn->query("SELECT industry, COUNT(*) AS c FROM dbms_contacts WHERE industry IS NOT NULL AND industry <> '' GROUP BY industry ORDER BY c DESC, industry ASC LIMIT 200");
if ($rs) $industries = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
$seniorityOptions = [];
$rs = $conn->query("SELECT seniority, COUNT(*) AS c FROM dbms_contacts WHERE seniority IS NOT NULL AND seniority <> '' GROUP BY seniority ORDER BY c DESC, seniority ASC LIMIT 200");
if ($rs) $seniorityOptions = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
$deptOptions = [];
$rs = $conn->query("SELECT department, COUNT(*) AS c FROM dbms_contacts WHERE department IS NOT NULL AND department <> '' GROUP BY department ORDER BY c DESC, department ASC LIMIT 200");
if ($rs) $deptOptions = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
$employeeBracketOptions = [];
if ($hasEmployeeSizeBracket) {
    $rs = $conn->query("SELECT employee_size_bracket, COUNT(*) AS c FROM dbms_contacts WHERE employee_size_bracket IS NOT NULL AND employee_size_bracket <> '' GROUP BY employee_size_bracket ORDER BY c DESC, employee_size_bracket ASC LIMIT 200");
    if ($rs) $employeeBracketOptions = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}
$revenueBracketOptions = [];
if ($hasRevenueBracket) {
    $rs = $conn->query("SELECT revenue_bracket, COUNT(*) AS c FROM dbms_contacts WHERE revenue_bracket IS NOT NULL AND revenue_bracket <> '' GROUP BY revenue_bracket ORDER BY c DESC, revenue_bracket ASC LIMIT 200");
    if ($rs) $revenueBracketOptions = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}

$groupMeta = [
    'discover' => ['label' => 'Discover', 'tone' => 'info'],
    'people' => ['label' => 'People', 'tone' => 'people'],
    'company' => ['label' => 'Company', 'tone' => 'company'],
    'signals' => ['label' => 'Signals', 'tone' => 'warning'],
    'workflow' => ['label' => 'Workflow', 'tone' => 'success'],
];
$filterMeta = [
    'q' => ['label' => 'Search', 'group' => 'discover'],
    'name' => ['label' => 'Name', 'group' => 'people'],
    'email' => ['label' => 'Email', 'group' => 'people'],
    'job_title' => ['label' => 'Job Title', 'group' => 'people'],
    'seniority' => ['label' => 'Seniority', 'group' => 'people'],
    'dept' => ['label' => 'Department', 'group' => 'people'],
    'company_name' => ['label' => 'Company Name', 'group' => 'company'],
    'employee_size_bracket' => ['label' => 'Employee Bracket', 'group' => 'company'],
    'industry' => ['label' => 'Industry', 'group' => 'company'],
    'website' => ['label' => 'Website', 'group' => 'company'],
    'revenue_link' => ['label' => 'Revenue Link', 'group' => 'company'],
    'prospect_location' => ['label' => 'Prospect Location', 'group' => 'company'],
    'prospect_country' => ['label' => 'Prospect Country', 'group' => 'company'],
    'company_address' => ['label' => 'Company Address', 'group' => 'company'],
    'company_city' => ['label' => 'Company City', 'group' => 'company'],
    'company_state' => ['label' => 'Company State', 'group' => 'company'],
    'post_code' => ['label' => 'Post Code', 'group' => 'company'],
    'company_country' => ['label' => 'Company Country', 'group' => 'company'],
    'revenue_bracket' => ['label' => 'Revenue Bracket', 'group' => 'company'],
    'total_funding' => ['label' => 'Total Funding', 'group' => 'company'],
    'industry_keywords' => ['label' => 'Industry Keywords', 'group' => 'signals'],
    'technologies' => ['label' => 'Technologies', 'group' => 'signals'],
    'sic' => ['label' => 'SIC', 'group' => 'signals'],
    'naics' => ['label' => 'NAICS', 'group' => 'signals'],
    'retail_locations' => ['label' => 'Retail Locations', 'group' => 'signals'],
    'email_status' => ['label' => 'Email Status', 'group' => 'signals'],
    'last_email_status' => ['label' => 'Last Email Status', 'group' => 'signals'],
    'email_from' => ['label' => 'Email From', 'group' => 'signals'],
    'email_to' => ['label' => 'Email To', 'group' => 'signals'],
    'intent_topics' => ['label' => 'Intent Topics', 'group' => 'signals'],
    'phone_status' => ['label' => 'Phone Status', 'group' => 'signals'],
    'data_status' => ['label' => 'Data Status', 'group' => 'signals'],
    'workflow' => ['label' => 'Workflow Queue', 'group' => 'workflow'],
    'unassigned_only' => ['label' => 'Unassigned Only', 'group' => 'workflow'],
    'stage' => ['label' => 'Stage', 'group' => 'workflow'],
    'review_status' => ['label' => 'Review Status', 'group' => 'workflow'],
    'batch_id' => ['label' => 'Batch', 'group' => 'workflow'],
    'assigned_department' => ['label' => 'Assigned Department', 'group' => 'workflow'],
    'assigned_to' => ['label' => 'Assigned To', 'group' => 'workflow'],
];
$formatFilterValue = function(string $key, mixed $value) use ($usersById): string {
    if ($key === 'workflow') {
        return dbmsWorkflowLabel((string)$value);
    }
    if ($key === 'assigned_to') {
        $uid = (int)$value;
        return (string)($usersById[$uid]['full_name'] ?? ('User #' . $uid));
    }
    if ($key === 'batch_id') {
        return '#' . (int)$value;
    }
    if ($key === 'unassigned_only') {
        return 'Yes';
    }
    if ($key === 'email_from' || $key === 'email_to') {
        return (string)$value;
    }
    return trim((string)$value);
};
$activeGroupCounts = [];
foreach (array_keys($groupMeta) as $groupKey) {
    $activeGroupCounts[$groupKey] = 0;
}
$activeFilterItems = [];
foreach ($filterMeta as $key => $meta) {
    $value = $filters[$key] ?? null;
    $isActive = false;
    if ($key === 'assigned_to' || $key === 'batch_id') {
        $isActive = (int)$value > 0;
    } elseif ($key === 'unassigned_only') {
        $isActive = !empty($value);
    } else {
        $isActive = is_string($value) && trim($value) !== '';
    }
    if (!$isActive) continue;
    $activeFilterItems[] = [
        'group' => (string)($meta['group'] ?? 'discover'),
        'label' => (string)($meta['label'] ?? $key),
        'value' => $formatFilterValue($key, $value),
    ];
    $activeGroupCounts[(string)$meta['group']]++;
}
$activeFilterCount = count($activeFilterItems);
$showingFrom = $total > 0 ? ($offset + 1) : 0;
$showingTo = $total > 0 ? min($total, $offset + $pageSize) : 0;
$getProspectLocation = function(array $row): string {
    $prospectLocation = trim((string)($row['prospect_location'] ?? ''));
    if ($prospectLocation !== '') return $prospectLocation;
    $parts = array_values(array_filter([
        trim((string)($row['city'] ?? '')),
        trim((string)($row['state'] ?? '')),
        trim((string)($row['prospect_country'] ?? '')) !== '' ? trim((string)$row['prospect_country']) : trim((string)($row['country'] ?? '')),
    ], static fn($v) => $v !== ''));
    return $parts ? implode(', ', $parts) : '—';
};
$getCompanyLocation = function(array $row): string {
    $parts = array_values(array_filter([
        trim((string)($row['company_city'] ?? '')) !== '' ? trim((string)$row['company_city']) : trim((string)($row['city'] ?? '')),
        trim((string)($row['company_state'] ?? '')) !== '' ? trim((string)$row['company_state']) : trim((string)($row['state'] ?? '')),
        trim((string)($row['company_country'] ?? '')) !== '' ? trim((string)$row['company_country']) : (trim((string)($row['country'] ?? '')) !== '' ? trim((string)$row['country']) : trim((string)($row['prospect_country'] ?? ''))),
    ], static fn($v) => $v !== ''));
    return $parts ? implode(', ', $parts) : '—';
};
$statusTone = function(string $context, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') return 'slate';
    if ($context === 'workflow') {
        return match ($value) {
            'approved' => 'success',
            'pending_research', 'research_rework', 'pending_cdqa' => 'warning',
            'research_assigned', 'cdqa_assigned' => 'info',
            'rejected', 'duplicate' => 'danger',
            default => 'slate',
        };
    }
    if ($context === 'email') {
        return match ($value) {
            'valid', 'delivered', 'open', 'clicked' => 'success',
            'accept all', 'unknown' => 'warning',
            'invalid', 'bounced' => 'danger',
            default => 'slate',
        };
    }
    if ($context === 'phone') {
        return match ($value) {
            'connected', 'connected - vm', 'connected - company vm' => 'success',
            'replaced' => 'warning',
            'invalid' => 'danger',
            default => 'slate',
        };
    }
    if ($context === 'data') {
        return match ($value) {
            'valid' => 'success',
            'invalid' => 'danger',
            default => 'slate',
        };
    }
    return 'slate';
};
$getLeadLabels = function(array $row) use ($statusTone): array {
    $labels = [];
    $workflow = trim((string)($row['workflow_queue'] ?? ''));
    if ($workflow !== '') {
        $labels[] = ['text' => dbmsWorkflowLabel($workflow), 'tone' => $statusTone('workflow', $workflow)];
    }
    $emailStatus = trim((string)($row['email_status'] ?? ''));
    if ($emailStatus !== '') {
        $labels[] = ['text' => 'Email: ' . $emailStatus, 'tone' => $statusTone('email', $emailStatus)];
    }
    $phoneStatus = trim((string)($row['phone_status'] ?? ''));
    if ($phoneStatus !== '') {
        $labels[] = ['text' => 'Phone: ' . $phoneStatus, 'tone' => $statusTone('phone', $phoneStatus)];
    }
    $dataStatus = trim((string)($row['data_status'] ?? ''));
    if ($dataStatus !== '') {
        $labels[] = ['text' => 'Data: ' . $dataStatus, 'tone' => $statusTone('data', $dataStatus)];
    }
    $assignedDepartment = trim((string)($row['assigned_department'] ?? ''));
    if ($assignedDepartment !== '') {
        $labels[] = ['text' => $assignedDepartment, 'tone' => 'company'];
    }
    return array_slice($labels, 0, 5);
};

$pageTitle = 'DBMS Explorer';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 dbms-workspace">
  <div class="dbms-page-header mb-3">
    <div class="dbms-hero flex-grow-1">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
          <span class="dbms-eyebrow"><i class="bi bi-database-check"></i>DBMS Explorer</span>
          <div class="dbms-page-title mt-2">People and Company Explorer</div>
          <div class="dbms-page-subtitle">Apollo-style filtering for people, company, signals, and workflow queues with separate scroll regions for the left filter rail and the results grid.</div>
        </div>
        <div class="dbms-chip-group">
          <span class="dbms-chip tone-info"><?php echo number_format($total); ?> matches</span>
          <span class="dbms-chip tone-people"><?php echo number_format($activeGroupCounts['people'] ?? 0); ?> people filters</span>
          <span class="dbms-chip tone-company"><?php echo number_format($activeGroupCounts['company'] ?? 0); ?> company filters</span>
          <span class="dbms-chip tone-success"><?php echo number_format($activeGroupCounts['workflow'] ?? 0); ?> workflow filters</span>
        </div>
      </div>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>
  <?php if ($queryError !== ''): ?>
    <div class="alert alert-danger">Filter query failed: <?php echo htmlspecialchars($queryError); ?></div>
  <?php endif; ?>

  <div class="row g-3 dbms-split">
    <div class="col-xl-4 col-xxl-3">
      <div class="card hr-card dbms-panel dbms-filter-card dbms-scroll-card">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span><i class="bi bi-funnel me-1"></i>Filter Studio</span>
          <span class="dbms-chip tone-info"><?php echo number_format($activeFilterCount); ?> active</span>
        </div>
        <form method="get" class="dbms-filter-form" action="<?php echo htmlspecialchars($explorerUrl); ?>">
          <input type="hidden" name="page" value="1">
          <div class="dbms-filter-nav">
            <?php foreach ($groupMeta as $groupKey => $meta): ?>
              <a class="dbms-filter-jump" href="#filter-<?php echo htmlspecialchars($groupKey); ?>">
                <span><?php echo htmlspecialchars((string)$meta['label']); ?></span>
                <span class="badge"><?php echo number_format((int)($activeGroupCounts[$groupKey] ?? 0)); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
          <div class="dbms-filter-scroll">
            <section class="dbms-filter-section" id="filter-discover">
              <div class="dbms-section-head">
                <div>
                  <div class="dbms-section-title">Discover</div>
                  <div class="dbms-section-note">Use a broad Apollo-style search, then narrow by persona, account, or queue.</div>
                </div>
                <span class="dbms-chip tone-info"><?php echo number_format((int)($activeGroupCounts['discover'] ?? 0)); ?> active</span>
              </div>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label small text-muted">Quick Search</label>
                  <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="Name, email, company, job title...">
                </div>
              </div>
            </section>

            <section class="dbms-filter-section" id="filter-people">
              <div class="dbms-section-head">
                <div>
                  <div class="dbms-section-title">People Filters</div>
                  <div class="dbms-section-note">Target the right contact persona using person-level profile fields.</div>
                </div>
                <span class="dbms-chip tone-people"><?php echo number_format((int)($activeGroupCounts['people'] ?? 0)); ?> active</span>
              </div>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label small text-muted">Name</label>
                  <input class="form-control form-control-sm" name="name" value="<?php echo htmlspecialchars($filters['name']); ?>" placeholder="Name">
                </div>
                <div class="col-12">
                  <label class="form-label small text-muted">Email</label>
                  <input class="form-control form-control-sm" name="email" value="<?php echo htmlspecialchars($filters['email']); ?>" placeholder="Email">
                </div>
                <div class="col-12">
                  <label class="form-label small text-muted">Job Title</label>
                  <input class="form-control form-control-sm" name="job_title" value="<?php echo htmlspecialchars($filters['job_title']); ?>" placeholder="Job Title">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Seniority</label>
                  <select class="form-select form-select-sm" name="seniority">
                    <option value="">All</option>
                    <?php foreach ($seniorityOptions as $c): ?>
                      <?php $v = (string)($c['seniority'] ?? ''); if ($v === '') continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo dbmsNormalizeFilterText((string)$filters['seniority']) === dbmsNormalizeFilterText($v) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Department</label>
                  <select class="form-select form-select-sm" name="dept">
                    <option value="">All</option>
                    <?php foreach ($deptOptions as $c): ?>
                      <?php $v = (string)($c['department'] ?? ''); if ($v === '') continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo dbmsNormalizeFilterText((string)$filters['dept']) === dbmsNormalizeFilterText($v) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </section>

            <section class="dbms-filter-section" id="filter-company">
              <div class="dbms-section-head">
                <div>
                  <div class="dbms-section-title">Company Filters</div>
                  <div class="dbms-section-note">Filter account fit using company, location, size, and revenue attributes.</div>
                </div>
                <span class="dbms-chip tone-company"><?php echo number_format((int)($activeGroupCounts['company'] ?? 0)); ?> active</span>
              </div>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label small text-muted">Company Name</label>
                  <input class="form-control form-control-sm" name="company_name" value="<?php echo htmlspecialchars($filters['company_name']); ?>" placeholder="Company Name">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Employee Size Bracket</label>
                  <select class="form-select form-select-sm" name="employee_size_bracket">
                    <option value="">All</option>
                    <?php foreach ($employeeBrackets as $v): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['employee_size_bracket'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($employeeBracketOptions as $c): ?>
                      <?php $v = (string)($c['employee_size_bracket'] ?? ''); if ($v === '' || in_array($v, $employeeBrackets, true)) continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['employee_size_bracket'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Industry</label>
                  <select class="form-select form-select-sm" name="industry">
                    <option value="">All</option>
                    <?php foreach ($industries as $c): ?>
                      <?php $v = (string)($c['industry'] ?? ''); if ($v === '') continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo dbmsNormalizeFilterText((string)$filters['industry']) === dbmsNormalizeFilterText($v) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Website</label>
                  <input class="form-control form-control-sm" name="website" value="<?php echo htmlspecialchars($filters['website']); ?>" placeholder="Website">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Revenue Link</label>
                  <input class="form-control form-control-sm" name="revenue_link" value="<?php echo htmlspecialchars((string)($filters['revenue_link'] ?? '')); ?>" placeholder="Revenue link">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Prospect Location</label>
                  <input class="form-control form-control-sm" name="prospect_location" value="<?php echo htmlspecialchars($filters['prospect_location']); ?>" placeholder="City / State">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Prospect Country</label>
                  <select class="form-select form-select-sm" name="prospect_country">
                    <option value="">All</option>
                    <?php foreach ($countries as $c): ?>
                      <?php $v = (string)($c['value'] ?? ''); if ($v === '') continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['prospect_country'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label small text-muted">Company Address</label>
                  <input class="form-control form-control-sm" name="company_address" value="<?php echo htmlspecialchars($filters['company_address']); ?>" placeholder="Company Address">
                </div>
                <div class="col-md-4">
                  <label class="form-label small text-muted">Company City</label>
                  <select class="form-select form-select-sm" name="company_city">
                    <option value="">All</option>
                    <?php foreach ($cities as $c): ?>
                      <?php $v = (string)($c['value'] ?? ''); if ($v === '') continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['company_city'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label small text-muted">Company State</label>
                  <select class="form-select form-select-sm" name="company_state">
                    <option value="">All</option>
                    <?php foreach ($states as $c): ?>
                      <?php $v = (string)($c['value'] ?? ''); if ($v === '') continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['company_state'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label small text-muted">Post Code</label>
                  <input class="form-control form-control-sm" name="post_code" value="<?php echo htmlspecialchars($filters['post_code']); ?>" placeholder="Post Code">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Company Country</label>
                  <select class="form-select form-select-sm" name="company_country">
                    <option value="">All</option>
                    <?php foreach ($countries as $c): ?>
                      <?php $v = (string)($c['value'] ?? ''); if ($v === '') continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['company_country'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Revenue Bracket</label>
                  <select class="form-select form-select-sm" name="revenue_bracket">
                    <option value="">All</option>
                    <?php foreach ($revenueBrackets as $v): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['revenue_bracket'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($revenueBracketOptions as $c): ?>
                      <?php $v = (string)($c['revenue_bracket'] ?? ''); if ($v === '' || in_array($v, $revenueBrackets, true)) continue; ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['revenue_bracket'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Total Funding</label>
                  <input class="form-control form-control-sm" name="total_funding" value="<?php echo htmlspecialchars($filters['total_funding']); ?>" placeholder="Total funding">
                </div>
              </div>
            </section>

            <section class="dbms-filter-section" id="filter-signals">
              <div class="dbms-section-head">
                <div>
                  <div class="dbms-section-title">Signals and Validation</div>
                  <div class="dbms-section-note">Narrow records with intent, data quality, and outreach validation signals.</div>
                </div>
                <span class="dbms-chip tone-warning"><?php echo number_format((int)($activeGroupCounts['signals'] ?? 0)); ?> active</span>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small text-muted">Industry Keywords</label>
                  <input class="form-control form-control-sm" name="industry_keywords" value="<?php echo htmlspecialchars($filters['industry_keywords']); ?>" placeholder="Industry Keywords">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Technologies</label>
                  <input class="form-control form-control-sm" name="technologies" value="<?php echo htmlspecialchars($filters['technologies']); ?>" placeholder="Technologies">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">SIC Codes</label>
                  <input class="form-control form-control-sm" name="sic" value="<?php echo htmlspecialchars($filters['sic']); ?>" placeholder="SIC">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">NAICS Codes</label>
                  <input class="form-control form-control-sm" name="naics" value="<?php echo htmlspecialchars($filters['naics']); ?>" placeholder="NAICS">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Retail Locations</label>
                  <input class="form-control form-control-sm" name="retail_locations" value="<?php echo htmlspecialchars($filters['retail_locations']); ?>" placeholder="Retail Locations">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Intent Topics</label>
                  <input class="form-control form-control-sm" name="intent_topics" value="<?php echo htmlspecialchars($filters['intent_topics']); ?>" placeholder="Intent Topics">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Email Status</label>
                  <select class="form-select form-select-sm" name="email_status">
                    <option value="">All</option>
                    <?php foreach ($emailStatusOptions as $v): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['email_status'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Last Email Status</label>
                  <select class="form-select form-select-sm" name="last_email_status">
                    <option value="">All</option>
                    <?php foreach ($lastEmailStatusOptions as $v): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['last_email_status'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label small text-muted">Email Timestamp</label>
                  <div class="row g-2">
                    <div class="col-6">
                      <input type="date" class="form-control form-control-sm" name="email_from" value="<?php echo htmlspecialchars($filters['email_from']); ?>">
                    </div>
                    <div class="col-6">
                      <input type="date" class="form-control form-control-sm" name="email_to" value="<?php echo htmlspecialchars($filters['email_to']); ?>">
                    </div>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Phone Status</label>
                  <select class="form-select form-select-sm" name="phone_status">
                    <option value="">All</option>
                    <?php foreach ($phoneStatusOptions as $v): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['phone_status'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Data Status</label>
                  <select class="form-select form-select-sm" name="data_status">
                    <option value="">All</option>
                    <?php foreach ($dataStatusOptions as $v): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $filters['data_status'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </section>

            <section class="dbms-filter-section" id="filter-workflow">
              <div class="dbms-section-head">
                <div>
                  <div class="dbms-section-title">Workflow and Ownership</div>
                  <div class="dbms-section-note">Focus the result set by queue, assignment, and DBMS workflow stage.</div>
                </div>
                <span class="dbms-chip tone-success"><?php echo number_format((int)($activeGroupCounts['workflow'] ?? 0)); ?> active</span>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small text-muted">Workflow Queue</label>
                  <select class="form-select form-select-sm" name="workflow">
                    <option value="">All</option>
                    <?php foreach (dbmsWorkflowQueueDefinitions() as $k => $def): ?>
                      <option value="<?php echo htmlspecialchars($k); ?>" <?php echo dbmsNormalizeWorkflowStatus((string)($filters['workflow'] ?? '')) === $k ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)($def['label'] ?? $k)); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                  <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="unassignedOnly" name="unassigned_only" value="1" <?php echo !empty($filters['unassigned_only']) ? 'checked' : ''; ?>>
                    <label class="form-check-label small text-muted" for="unassignedOnly">Unassigned only for pending queues</label>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Stage</label>
                  <select class="form-select form-select-sm" name="stage">
                    <option value="">All</option>
                    <?php foreach (['ready'=>'Ready','append'=>'Append','assigned'=>'Assigned','rejected'=>'Rejected','duplicate'=>'Duplicate'] as $k => $lbl): ?>
                      <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $filters['stage'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Review Status</label>
                  <input class="form-control form-control-sm" name="review_status" value="<?php echo htmlspecialchars($filters['review_status']); ?>" placeholder="pending_cdqa / approved / assigned">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Upload Batch</label>
                  <input class="form-control form-control-sm font-monospace" name="batch_id" value="<?php echo $filters['batch_id'] > 0 ? (int)$filters['batch_id'] : ''; ?>" placeholder="Batch ID">
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Assigned Department</label>
                  <select class="form-select form-select-sm" name="assigned_department">
                    <option value="">All</option>
                    <?php foreach ($assignmentDepartments as $d): ?>
                      <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $filters['assigned_department'] === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Assigned To</label>
                  <select class="form-select form-select-sm" name="assigned_to">
                    <option value="">All</option>
                    <?php foreach ($users as $u): ?>
                      <?php $uid = (int)($u['id'] ?? 0); if ($uid <= 0) continue; ?>
                      <option value="<?php echo $uid; ?>" <?php echo $filters['assigned_to'] === $uid ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small text-muted">Rows per page</label>
                  <select class="form-select form-select-sm" name="ps">
                    <?php foreach ([25, 50, 100, 200] as $psOpt): ?>
                      <option value="<?php echo $psOpt; ?>" <?php echo $pageSize === $psOpt ? 'selected' : ''; ?>><?php echo $psOpt; ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </section>
          </div>
          <div class="dbms-filter-actions">
            <div class="dbms-note">Left rail scrolls independently so long filters stay accessible while the results grid remains visible.</div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Apply Filters</button>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($explorerUrl); ?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="col-xl-8 col-xxl-9">
      <div class="card hr-card dbms-panel dbms-results-card dbms-scroll-card">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span><i class="bi bi-table me-1"></i>Results</span>
          <div class="d-flex gap-2 flex-wrap">
            <?php if (userHasPermission('dbms.export')): ?>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/export?<?php echo htmlspecialchars(http_build_query($_GET)); ?>"><i class="bi bi-download me-1"></i>Export</a>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#assignModal"><i class="bi bi-person-check me-1"></i>Assign</button>
          </div>
        </div>
        <div class="card-body">
          <div class="dbms-table-toolbar">
            <div class="dbms-table-toolbar-main">
              <span class="dbms-table-count"><?php echo number_format($total); ?> records</span>
              <span class="dbms-table-count"><?php echo $showingFrom > 0 ? (number_format($showingFrom) . ' - ' . number_format($showingTo)) : '0'; ?> visible</span>
              <span class="dbms-table-count">Page <?php echo number_format($page); ?></span>
            </div>
            <div class="dbms-table-toolbar-side">
              <span class="dbms-note">Right pane scrolls independently so you can browse results while keeping the filter rail fixed.</span>
            </div>
          </div>
          <div class="px-3 pt-3">
            <?php if (!empty($activeFilterItems)): ?>
              <div class="dbms-filter-summary">
                <?php foreach ($activeFilterItems as $item): ?>
                  <?php $tone = (string)($groupMeta[$item['group']]['tone'] ?? 'info'); ?>
                  <span class="dbms-filter-pill tone-<?php echo htmlspecialchars($tone); ?>">
                    <strong><?php echo htmlspecialchars((string)$item['label']); ?></strong>
                    <span><?php echo htmlspecialchars((string)$item['value']); ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="dbms-note">No filters are active. Use the left panel to narrow the DBMS people and company list.</div>
            <?php endif; ?>
          </div>

          <form method="post" id="dbmsGridForm" action="<?php echo htmlspecialchars($gridFormAction); ?>" class="d-flex flex-column flex-grow-1 min-h-0">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="select_mode" id="selectMode" value="ids">
            <div class="dbms-results-scroll">
              <div class="table-responsive dbms-table-wrap">
                <table class="table table-sm table-striped align-middle hr-table mb-0">
                  <thead>
                    <tr>
                      <th style="width:38px"><input type="checkbox" class="form-check-input" id="chkAll"></th>
                      <th>ID</th>
                      <th class="dbms-prospect-cell">Prospect</th>
                      <th class="dbms-company-cell">Company</th>
                      <th class="dbms-geo-cell">Geography</th>
                      <th class="dbms-signal-cell">Signals</th>
                      <th class="dbms-owner-cell">Ownership</th>
                      <th class="dbms-label-cell">Lead Labels</th>
                      <th>View</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r): ?>
                      <?php
                        $cid = (int)($r['id'] ?? 0);
                        $nm = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                        if ($nm === '') $nm = trim((string)($r['name'] ?? ''));
                        $prospectLocation = $getProspectLocation($r);
                        $companyLocation = $getCompanyLocation($r);
                        $website = trim((string)($r['website'] ?? ''));
                        $revenueLink = trim((string)($r['revenue_link'] ?? ''));
                        $labels = $getLeadLabels($r);
                        $intentTopics = trim((string)($r['intent_topics'] ?? ''));
                        $assignedDepartment = trim((string)($r['assigned_department'] ?? ''));
                      ?>
                      <tr>
                        <td><input type="checkbox" class="form-check-input chkRow" name="ids[]" value="<?php echo $cid; ?>"></td>
                        <td class="font-monospace text-muted small">#<?php echo $cid; ?></td>
                        <td class="dbms-prospect-cell">
                          <div class="dbms-meta-stack">
                            <div class="dbms-meta-title"><?php echo htmlspecialchars($nm !== '' ? $nm : '—'); ?></div>
                            <div class="dbms-meta-subtitle"><?php echo htmlspecialchars((string)($r['job_title'] ?? 'No job title')); ?></div>
                            <div class="dbms-chip-group">
                              <?php if (trim((string)($r['seniority'] ?? '')) !== ''): ?><span class="dbms-chip tone-people"><?php echo htmlspecialchars((string)($r['seniority'] ?? '')); ?></span><?php endif; ?>
                              <?php if (trim((string)($r['department'] ?? '')) !== ''): ?><span class="dbms-chip tone-people"><?php echo htmlspecialchars((string)($r['department'] ?? '')); ?></span><?php endif; ?>
                            </div>
                            <div class="small text-muted font-monospace"><?php echo htmlspecialchars((string)($r['email'] ?? '—')); ?></div>
                          </div>
                        </td>
                        <td class="dbms-company-cell">
                          <div class="dbms-meta-stack">
                            <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($r['company_name'] ?? '—')); ?></div>
                            <div class="dbms-meta-subtitle"><?php echo htmlspecialchars((string)($r['industry'] ?? 'No industry')); ?></div>
                            <div class="dbms-chip-group">
                              <?php if (trim((string)($r['employee_size_bracket'] ?? '')) !== ''): ?><span class="dbms-chip tone-company"><?php echo htmlspecialchars((string)($r['employee_size_bracket'] ?? '')); ?></span><?php endif; ?>
                              <?php if (trim((string)($r['revenue_bracket'] ?? '')) !== ''): ?><span class="dbms-chip tone-company"><?php echo htmlspecialchars((string)($r['revenue_bracket'] ?? '')); ?></span><?php endif; ?>
                            </div>
                            <div class="small">
                              <?php if ($website !== ''): ?>
                                <a href="<?php echo htmlspecialchars($website); ?>" target="_blank" rel="noopener" class="text-decoration-none" title="Open website" aria-label="Open website">
                                  <i class="bi bi-globe2"></i>
                                </a>
                              <?php else: ?>
                                <span class="text-muted">No website</span>
                              <?php endif; ?>
                              <span class="text-muted mx-1">|</span>
                              <?php if ($revenueLink !== ''): ?>
                                <a href="<?php echo htmlspecialchars($revenueLink); ?>" target="_blank" rel="noopener" class="text-decoration-none" title="Open revenue link" aria-label="Open revenue link">
                                  <i class="bi bi-currency-dollar"></i>
                                </a>
                              <?php else: ?>
                                <span class="text-muted">No revenue link</span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </td>
                        <td class="dbms-geo-cell">
                          <div class="dbms-meta-stack">
                            <div>
                              <div class="small text-muted">Prospect</div>
                              <div><?php echo htmlspecialchars($prospectLocation); ?></div>
                            </div>
                            <div>
                              <div class="small text-muted">Company</div>
                              <div><?php echo htmlspecialchars($companyLocation); ?></div>
                            </div>
                          </div>
                        </td>
                        <td class="dbms-signal-cell">
                          <div class="dbms-meta-stack">
                            <div class="dbms-chip-group">
                              <?php $emailStatus = trim((string)($r['email_status'] ?? '')); ?>
                              <?php if ($emailStatus !== ''): ?><span class="dbms-chip tone-<?php echo htmlspecialchars($statusTone('email', $emailStatus)); ?>"><?php echo htmlspecialchars($emailStatus); ?></span><?php endif; ?>
                              <?php $lastEmailStatus = trim((string)($r['last_email_status'] ?? '')); ?>
                              <?php if ($lastEmailStatus !== ''): ?><span class="dbms-chip tone-<?php echo htmlspecialchars($statusTone('email', $lastEmailStatus)); ?>"><?php echo htmlspecialchars($lastEmailStatus); ?></span><?php endif; ?>
                            </div>
                            <div class="dbms-chip-group">
                              <?php $phoneStatus = trim((string)($r['phone_status'] ?? '')); ?>
                              <?php if ($phoneStatus !== ''): ?><span class="dbms-chip tone-<?php echo htmlspecialchars($statusTone('phone', $phoneStatus)); ?>"><?php echo htmlspecialchars($phoneStatus); ?></span><?php endif; ?>
                              <?php $dataStatus = trim((string)($r['data_status'] ?? '')); ?>
                              <?php if ($dataStatus !== ''): ?><span class="dbms-chip tone-<?php echo htmlspecialchars($statusTone('data', $dataStatus)); ?>"><?php echo htmlspecialchars($dataStatus); ?></span><?php endif; ?>
                            </div>
                            <div class="small text-muted"><?php echo htmlspecialchars($intentTopics !== '' ? $intentTopics : 'No intent topics'); ?></div>
                          </div>
                        </td>
                        <td class="dbms-owner-cell">
                          <div class="dbms-meta-stack">
                            <div>
                              <div class="small text-muted">Owner</div>
                              <div><?php echo htmlspecialchars((string)($r['owner_name'] ?? '—')); ?></div>
                            </div>
                            <div>
                              <div class="small text-muted">Assigned To</div>
                              <div><?php echo htmlspecialchars((string)($r['assigned_to_name'] ?? '—')); ?></div>
                            </div>
                            <div class="dbms-chip-group">
                              <span class="dbms-chip tone-<?php echo htmlspecialchars($statusTone('workflow', (string)($r['workflow_queue'] ?? ''))); ?>"><?php echo htmlspecialchars(dbmsWorkflowLabel((string)($r['workflow_queue'] ?? ''))); ?></span>
                              <?php if ($assignedDepartment !== ''): ?><span class="dbms-chip tone-company"><?php echo htmlspecialchars($assignedDepartment); ?></span><?php endif; ?>
                            </div>
                            <div class="small text-muted">Review: <?php echo htmlspecialchars((string)($r['review_status'] ?? '—')); ?><?php echo (int)($r['duplicate_of_contact_id'] ?? 0) > 0 ? (' · Duplicate of #' . (int)($r['duplicate_of_contact_id'] ?? 0)) : ''; ?></div>
                          </div>
                        </td>
                        <td class="dbms-label-cell">
                          <div class="dbms-chip-group">
                            <?php if (empty($labels)): ?>
                              <span class="dbms-chip tone-slate">No labels</span>
                            <?php else: ?>
                              <?php foreach ($labels as $label): ?>
                                <span class="dbms-chip tone-<?php echo htmlspecialchars((string)($label['tone'] ?? 'slate')); ?>"><?php echo htmlspecialchars((string)($label['text'] ?? '')); ?></span>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/view?id=<?php echo $cid; ?>" title="View record"><i class="bi bi-eye"></i></a></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                      <tr><td colspan="9" class="text-center text-muted py-5">No DBMS records matched the selected filters.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </form>

          <?php $pages = (int)ceil($total / $pageSize); $qs = $_GET; ?>
          <?php if ($pages > 1): ?>
            <div class="dbms-footer-bar">
              <div class="dbms-note">Showing <?php echo number_format($showingFrom); ?>-<?php echo number_format($showingTo); ?> of <?php echo number_format($total); ?> records</div>
              <div class="btn-group btn-group-sm">
                <?php $qs['page'] = max(1, $page - 1); ?>
                <a class="btn btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Prev</a>
                <?php $qs['page'] = min($pages, $page + 1); ?>
                <a class="btn btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Next</a>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title fw-semibold">Assign Records</div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border small mb-2">
          Use Selected IDs (checked rows) or Select Entire Result Set (current filters).
        </div>
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label small text-muted">Selection</label>
            <select class="form-select form-select-sm" id="selModeUi">
              <option value="ids" selected>Selected records</option>
              <option value="all_filtered">Entire result set (filters)</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small text-muted">Department</label>
            <select class="form-select form-select-sm" id="assignDept">
              <option value="">Select</option>
              <?php foreach ($assignmentDepartments as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small text-muted">Assign To</label>
            <select class="form-select form-select-sm" id="assignTo">
              <option value="">Select user</option>
              <?php foreach ($users as $u): ?>
                <?php $uid = (int)($u['id'] ?? 0); if ($uid <= 0) continue; ?>
                <?php
                  $canResearch = $userCanHandleDepartment($uid, 'Research');
                  $canCdqa = $userCanHandleDepartment($uid, 'CDQA');
                  $laneLabel = $canResearch && $canCdqa ? 'Research, CDQA' : ($canResearch ? 'Research' : ($canCdqa ? 'CDQA' : 'No lane'));
                ?>
                <option value="<?php echo $uid; ?>" <?php echo (!$canResearch && !$canCdqa) ? 'disabled' : ''; ?>>
                  <?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?><?php echo htmlspecialchars(' (' . $laneLabel . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small text-muted">Note (optional)</label>
            <input class="form-control form-control-sm" id="assignNote" placeholder="Reason / distribution note...">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnAssign"><i class="bi bi-person-check me-1"></i>Assign</button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const chkAll = document.getElementById('chkAll');
  const rows = Array.from(document.querySelectorAll('.chkRow'));
  if (chkAll) {
    chkAll.addEventListener('change', () => {
      rows.forEach(r => r.checked = chkAll.checked);
    });
  }

  const btn = document.getElementById('btnAssign');
  const form = document.getElementById('dbmsGridForm');
  const dept = document.getElementById('assignDept');
  const to = document.getElementById('assignTo');
  const note = document.getElementById('assignNote');
  const modeUi = document.getElementById('selModeUi');
  const mode = document.getElementById('selectMode');

  const ensureHidden = (name, value) => {
    let el = form.querySelector('input[name="' + name + '"]');
    if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = name; form.appendChild(el); }
    el.value = value;
  };

  if (btn && form) {
    btn.addEventListener('click', () => {
      if (!dept.value) return;
      if (!to.value) return;
      mode.value = modeUi.value;
      ensureHidden('department', dept.value);
      ensureHidden('assign_to', to.value);
      ensureHidden('note', note.value || '');
      form.submit();
    });
  }
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
