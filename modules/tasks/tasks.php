<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
ensureCsrfToken();
ensureTaskManagementSchema();

$conn = getDbConnection();
$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$userDept = trim((string)($user['department'] ?? ''));

$view = strtolower(trim((string)($_GET['view'] ?? 'list')));
if (!in_array($view, ['list','kanban','calendar'], true)) $view = 'list';

$scope = strtolower(trim((string)($_GET['scope'] ?? 'my')));
if (!in_array($scope, ['my','team','department','org'], true)) $scope = 'my';

$canOrg = function_exists('userHasPermission') ? userHasPermission('tasks.override') : isAdmin();
if ($scope === 'org' && !$canOrg) $scope = 'my';

$canTeam = function_exists('userHasPermission') ? userHasPermission('tasks.assign') : false;
if ($scope === 'team' && !$canTeam) $scope = 'my';

$canCreate = function_exists('userHasPermission') ? userHasPermission('tasks.create') : false;
$canReports = function_exists('userHasPermission') ? userHasPermission('tasks.reports') : false;
$canManage = function_exists('userHasPermission') ? userHasPermission('tasks.manage') : false;
if (function_exists('userHasPermission')) {
    $hasAny = userHasPermission('tasks.access') || $canCreate || $canReports || $canManage || $canTeam || $canOrg;
    if (!$hasAny) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$priority = trim((string)($_GET['priority'] ?? ''));
$department = trim((string)($_GET['department'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$assigneeId = (int)($_GET['assignee_id'] ?? 0);
$dueFrom = trim((string)($_GET['due_from'] ?? ''));
$dueTo = trim((string)($_GET['due_to'] ?? ''));

$statusOptions = ['Not Started','Assigned','In Progress','Waiting For Input','On Hold','Under Review','Completed','Rejected','Cancelled','Overdue'];
$priorityOptions = ['Critical','High','Medium','Low'];

$taskTypes = [];
$resTypes = $conn->query("SELECT DISTINCT task_type FROM tasks WHERE task_type IS NOT NULL AND task_type <> '' ORDER BY task_type");
if ($resTypes) {
    while ($r = $resTypes->fetch_assoc()) { $taskTypes[] = (string)($r['task_type'] ?? ''); }
}

$teamIds = [];
if ($canTeam) {
    $stmt = $conn->prepare("
        SELECT DISTINCT id FROM teams WHERE manager_user_id = ?
        UNION
        SELECT DISTINCT team_id AS id FROM team_members WHERE user_id = ? AND member_role = 'lead'
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) { $teamIds[] = (int)($r['id'] ?? 0); }
        $teamIds = array_values(array_filter(array_unique($teamIds), fn($x) => $x > 0));
    }
}

$teamMemberIds = [];
if (!empty($teamIds)) {
    $in = implode(',', array_fill(0, count($teamIds), '?'));
    $types = str_repeat('i', count($teamIds));
    $stmt = $conn->prepare("SELECT DISTINCT user_id FROM team_members WHERE team_id IN ($in)");
    if ($stmt) {
        $stmt->bind_param($types, ...$teamIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) { $teamMemberIds[] = (int)($r['user_id'] ?? 0); }
        $teamMemberIds = array_values(array_filter(array_unique($teamMemberIds), fn($x) => $x > 0));
    }
}

$where = ["1=1"];
$params = [];
$types = '';
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$opsTodayDate = function_exists('opsTodayDate') ? opsTodayDate() : $opsNow->format('Y-m-d');
$todayRange = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => $opsTodayDate . ' 00:00:00', 'end' => $opsTodayDate . ' 23:59:59'];
$nowUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');
$nowUtcDt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($scope === 'my') {
    $where[] = "(t.created_by = ? OR ta.user_id = ?)";
    $params[] = $userId;
    $params[] = $userId;
    $types .= 'ii';
} elseif ($scope === 'team') {
    if (!empty($teamMemberIds)) {
        $in = implode(',', array_fill(0, count($teamMemberIds), '?'));
        $where[] = "(t.created_by = ? OR ta.user_id IN ($in) OR (t.team_id IS NOT NULL AND t.team_id IN (" . implode(',', array_fill(0, count($teamIds), '?')) . ")))";
        $params[] = $userId;
        $types .= 'i';
        foreach ($teamMemberIds as $id) { $params[] = $id; $types .= 'i'; }
        foreach ($teamIds as $id) { $params[] = $id; $types .= 'i'; }
    } else {
        $where[] = "(t.created_by = ? OR ta.user_id = ?)";
        $params[] = $userId;
        $params[] = $userId;
        $types .= 'ii';
    }
} elseif ($scope === 'department') {
    if ($userDept !== '') {
        $where[] = "(t.department = ? OR ta.user_id = ? OR t.created_by = ?)";
        $params[] = $userDept;
        $params[] = $userId;
        $params[] = $userId;
        $types .= 'sii';
    } else {
        $where[] = "(t.created_by = ? OR ta.user_id = ?)";
        $params[] = $userId;
        $params[] = $userId;
        $types .= 'ii';
    }
} else {
    if (!$canOrg) {
        $where[] = "(t.created_by = ? OR ta.user_id = ?)";
        $params[] = $userId;
        $params[] = $userId;
        $types .= 'ii';
    }
}

if ($q !== '') {
    $where[] = "(t.title LIKE ? OR t.task_code LIKE ? OR t.description LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

if ($status !== '' && in_array($status, $statusOptions, true)) {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= 's';
}
if ($priority !== '' && in_array($priority, $priorityOptions, true)) {
    $where[] = "t.priority = ?";
    $params[] = $priority;
    $types .= 's';
}
if ($department !== '') {
    $where[] = "t.department = ?";
    $params[] = $department;
    $types .= 's';
}
if ($type !== '') {
    $where[] = "t.task_type = ?";
    $params[] = $type;
    $types .= 's';
}
if ($assigneeId > 0) {
    $where[] = "ta.user_id = ?";
    $params[] = $assigneeId;
    $types .= 'i';
}
if (($dueFrom !== '' || $dueTo !== '') && function_exists('opsDateRangeUtc')) {
    $dueRange = opsDateRangeUtc($dueFrom !== '' ? $dueFrom : $dueTo, $dueTo !== '' ? $dueTo : $dueFrom);
    if (!empty($dueRange['start'])) {
        $where[] = "t.due_at IS NOT NULL AND t.due_at >= ?";
        $params[] = $dueRange['start'];
        $types .= 's';
    }
    if (!empty($dueRange['end'])) {
        $where[] = "t.due_at IS NOT NULL AND t.due_at <= ?";
        $params[] = $dueRange['end'];
        $types .= 's';
    }
}

$whereSql = implode(' AND ', $where);

$departments = [];
$resDept = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> '' ORDER BY department");
if ($resDept) {
    while ($r = $resDept->fetch_assoc()) { $departments[] = (string)($r['department'] ?? ''); }
}

$assigneesForFilter = [];
if ($canOrg || $scope === 'org') {
    $resU = $conn->query("SELECT id, full_name, role, department FROM users WHERE is_active = 1 AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) ORDER BY full_name");
    if ($resU) {
        while ($r = $resU->fetch_assoc()) { $assigneesForFilter[] = $r; }
    }
} else {
    $ids = [$userId];
    foreach ($teamMemberIds as $id) $ids[] = $id;
    $ids = array_values(array_filter(array_unique($ids), fn($x) => $x > 0));
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $t = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT id, full_name, role, department FROM users WHERE is_active = 1 AND id IN ($in) ORDER BY full_name");
        if ($stmt) {
            $stmt->bind_param($t, ...$ids);
            $stmt->execute();
            $assigneesForFilter = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }
    }
}

$counts = [
    'pending' => 0,
    'due_today' => 0,
    'overdue' => 0,
    'completed' => 0,
];
$sqlCounts = "
    SELECT
        SUM(CASE WHEN t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS pending_cnt,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_cnt,
        SUM(CASE WHEN t.due_at IS NOT NULL AND t.due_at BETWEEN ? AND ? AND t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS due_today_cnt,
        SUM(CASE WHEN t.due_at IS NOT NULL AND t.due_at < ? AND t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS overdue_cnt
    FROM tasks t
    LEFT JOIN task_assignees ta ON ta.task_id = t.id
    WHERE $whereSql
";
$stmtC = $conn->prepare($sqlCounts);
if ($stmtC) {
    $countTypes = $types . 'sss';
    $countParams = array_merge($params, [(string)($todayRange['start'] ?? '1970-01-01 00:00:00'), (string)($todayRange['end'] ?? '2999-12-31 23:59:59'), $nowUtc]);
    $stmtC->bind_param($countTypes, ...$countParams);
    $stmtC->execute();
    $rowC = $stmtC->get_result()->fetch_assoc() ?: [];
    $stmtC->close();
    $counts['pending'] = (int)($rowC['pending_cnt'] ?? 0);
    $counts['completed'] = (int)($rowC['completed_cnt'] ?? 0);
    $counts['due_today'] = (int)($rowC['due_today_cnt'] ?? 0);
    $counts['overdue'] = (int)($rowC['overdue_cnt'] ?? 0);
}

$sql = "
    SELECT
        t.*,
        cb.full_name AS created_by_name,
        GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS assignee_names,
        GROUP_CONCAT(DISTINCT u.id ORDER BY u.id SEPARATOR ',') AS assignee_ids
    FROM tasks t
    LEFT JOIN users cb ON cb.id = t.created_by
    LEFT JOIN task_assignees ta ON ta.task_id = t.id
    LEFT JOIN users u ON u.id = ta.user_id
    WHERE $whereSql
    GROUP BY t.id
    ORDER BY
        CASE WHEN t.status IN ('Overdue') THEN 0
             WHEN t.status IN ('Assigned','Not Started','In Progress','Waiting For Input','On Hold','Under Review') THEN 1
             WHEN t.status IN ('Rejected') THEN 2
             WHEN t.status IN ('Cancelled') THEN 3
             WHEN t.status IN ('Completed') THEN 4
             ELSE 5 END,
        CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END,
        t.due_at ASC,
        t.created_at DESC
    LIMIT 500
";
$stmt = $conn->prepare($sql);
$tasks = [];
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$pageTitle = 'Task Management';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 tasks-workspace">
  <div class="tasks-page-header tasks-hero mb-3">
    <div>
      <h3 class="mb-1">Task Management</h3>
      <div class="text-muted small">Centralized tasks across departments with tracking, activity and deadlines.</div>
    </div>
    <div class="tasks-page-actions">
      <?php if ($canReports): ?>
        <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks/reports'); ?>"><i class="bi bi-bar-chart me-1"></i>Reports</a>
      <?php endif; ?>
      <?php if ($canCreate): ?>
        <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks/create'); ?>"><i class="bi bi-plus-circle me-1"></i>Create Task</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="tasks-summary-grid mb-3">
    <div class="tasks-summary-item">
      <span class="tasks-summary-label">Pending</span>
      <div class="tasks-summary-value"><?php echo number_format((int)$counts['pending']); ?></div>
      <div class="tasks-summary-meta">Open tasks in the current scope</div>
    </div>
    <div class="tasks-summary-item">
      <span class="tasks-summary-label">Due Today</span>
      <div class="tasks-summary-value"><?php echo number_format((int)$counts['due_today']); ?></div>
      <div class="tasks-summary-meta">Items that need action today</div>
    </div>
    <div class="tasks-summary-item">
      <span class="tasks-summary-label">Overdue</span>
      <div class="tasks-summary-value text-danger"><?php echo number_format((int)$counts['overdue']); ?></div>
      <div class="tasks-summary-meta">Tasks already past due</div>
    </div>
    <div class="tasks-summary-item">
      <span class="tasks-summary-label">Completed</span>
      <div class="tasks-summary-value text-success"><?php echo number_format((int)$counts['completed']); ?></div>
      <div class="tasks-summary-meta">Closed work in the current scope</div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3 tasks-toolbar">
    <div class="card-body">
      <div class="tasks-toolbar-top">
        <div class="tasks-toolbar-main">
          <div>
            <div class="tasks-table-title">Task Queue</div>
            <div class="tasks-table-subtitle">Filter by scope, ownership, due window, and assignee without losing context.</div>
          </div>
          <span class="tasks-table-count"><i class="bi bi-list-task"></i><?php echo number_format(count($tasks)); ?> tasks shown</span>
        </div>
        <div class="tasks-toolbar-side">
          <div class="tasks-scope-switch">
            <div class="btn-group btn-group-sm" role="group">
          <a class="btn btn-outline-primary <?php echo $scope === 'my' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['scope' => 'my'])); ?>">My Tasks</a>
          <?php if ($canTeam): ?>
            <a class="btn btn-outline-primary <?php echo $scope === 'team' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['scope' => 'team'])); ?>">Team</a>
          <?php endif; ?>
          <a class="btn btn-outline-primary <?php echo $scope === 'department' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['scope' => 'department'])); ?>">Department</a>
          <?php if ($canOrg): ?>
            <a class="btn btn-outline-primary <?php echo $scope === 'org' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['scope' => 'org'])); ?>">Organization</a>
          <?php endif; ?>
            </div>
          </div>

          <div class="tasks-view-switch">
            <div class="btn-group btn-group-sm" role="group">
              <a class="btn btn-outline-secondary <?php echo $view === 'list' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'list'])); ?>"><i class="bi bi-list-ul me-1"></i>List</a>
              <a class="btn btn-outline-secondary <?php echo $view === 'kanban' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'kanban'])); ?>"><i class="bi bi-kanban me-1"></i>Kanban</a>
              <a class="btn btn-outline-secondary <?php echo $view === 'calendar' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'calendar'])); ?>"><i class="bi bi-calendar3 me-1"></i>Calendar</a>
            </div>
          </div>
        </div>
      </div>

      <hr class="my-3">

      <form class="row g-2 align-items-end tasks-toolbar-form" method="get">
        <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope); ?>">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
        <div class="col-lg-3">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Task code, title, text">
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Status</label>
          <select class="form-select form-select-sm" name="status">
            <option value="">All</option>
            <?php foreach ($statusOptions as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Task Type</label>
          <select class="form-select form-select-sm" name="type">
            <option value="">All</option>
            <?php foreach ($taskTypes as $tt): ?>
              <option value="<?php echo htmlspecialchars($tt); ?>" <?php echo $type === $tt ? 'selected' : ''; ?>><?php echo htmlspecialchars($tt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Priority</label>
          <select class="form-select form-select-sm" name="priority">
            <option value="">All</option>
            <?php foreach ($priorityOptions as $p): ?>
              <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $priority === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Department</label>
          <select class="form-select form-select-sm" name="department">
            <option value="">All</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $department === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Due From</label>
          <input type="date" class="form-control form-control-sm" name="due_from" value="<?php echo htmlspecialchars($dueFrom); ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Due To</label>
          <input type="date" class="form-control form-control-sm" name="due_to" value="<?php echo htmlspecialchars($dueTo); ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Assignee</label>
          <select class="form-select form-select-sm" name="assignee_id">
            <option value="0">All</option>
            <?php foreach ($assigneesForFilter as $au): ?>
              <option value="<?php echo (int)$au['id']; ?>" <?php echo $assigneeId === (int)$au['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($au['full_name'] ?? ''); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-1 tasks-filter-actions">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-filter me-1"></i>Filter</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($view === 'kanban'): ?>
    <?php
      $cols = [
        'Assigned' => ['Assigned','Not Started','Waiting For Input'],
        'In Progress' => ['In Progress'],
        'On Hold' => ['On Hold'],
        'Review' => ['Under Review'],
        'Completed' => ['Completed'],
      ];
      $byCol = [];
      foreach ($cols as $k => $_) $byCol[$k] = [];
      foreach ($tasks as $t) {
        $st = (string)($t['status'] ?? '');
        $placed = false;
        foreach ($cols as $col => $sts) {
          if (in_array($st, $sts, true)) { $byCol[$col][] = $t; $placed = true; break; }
        }
        if (!$placed) $byCol['Assigned'][] = $t;
      }
    ?>
    <div class="row g-3">
      <?php foreach ($byCol as $col => $items): ?>
        <div class="col-xl">
          <div class="card border-0 shadow-sm h-100 tasks-board-card">
            <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
              <span><?php echo htmlspecialchars($col); ?></span>
              <span class="badge text-bg-light border"><?php echo number_format(count($items)); ?></span>
            </div>
            <div class="card-body p-2 kanban-col" data-target-status="<?php echo htmlspecialchars($col === 'Review' ? 'Under Review' : ($col === 'Completed' ? 'Completed' : ($col === 'On Hold' ? 'On Hold' : ($col === 'In Progress' ? 'In Progress' : 'Assigned')))); ?>">
              <?php if (empty($items)): ?>
                <div class="text-muted small p-2">No tasks</div>
              <?php else: ?>
                <?php foreach ($items as $t): ?>
                  <?php
                    $prio = (string)($t['priority'] ?? 'Medium');
                    $badge = 'text-bg-secondary';
                    if ($prio === 'Critical') $badge = 'text-bg-danger';
                    elseif ($prio === 'High') $badge = 'text-bg-warning';
                    elseif ($prio === 'Medium') $badge = 'text-bg-primary';
                    $due = (string)($t['due_at'] ?? '');
                    $dueDt = $due !== '' ? appParseUtc($due) : null;
                    $isOver = ($dueDt instanceof DateTimeImmutable && $dueDt < $nowUtcDt && !in_array((string)($t['status'] ?? ''), ['Completed','Cancelled','Rejected'], true));
                  ?>
                  <div class="card border-0 shadow-sm mb-2 kanban-card" draggable="true" data-task-id="<?php echo (int)$t['id']; ?>">
                    <div class="card-body p-2">
                      <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="fw-semibold small"><?php echo htmlspecialchars($t['title'] ?? ''); ?></div>
                        <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($prio); ?></span>
                      </div>
                      <div class="text-muted small mt-1"><?php echo htmlspecialchars($t['task_code'] ?? ''); ?></div>
                      <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="small <?php echo $isOver ? 'text-danger' : 'text-muted'; ?>"><?php echo $dueDt ? htmlspecialchars($dueDt->setTimezone(opsBusinessTz())->format('d M, H:i')) : 'No due'; ?></div>
                        <a class="btn btn-sm btn-light border" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks/view?id=' . (int)$t['id']); ?>"><i class="bi bi-arrow-right"></i></a>
                      </div>
                      <div class="progress mt-2" style="height:6px;">
                        <div class="progress-bar" role="progressbar" style="width: <?php echo (int)($t['progress'] ?? 0); ?>%"></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($view === 'calendar'): ?>
    <?php
      $calMode = strtolower(trim((string)($_GET['cal'] ?? 'month')));
      if (!in_array($calMode, ['month','week','day'], true)) $calMode = 'month';

      $dateParam = trim((string)($_GET['date'] ?? ''));
      try {
        $baseDate = $dateParam !== '' ? new DateTime($dateParam, opsBusinessTz()) : new DateTime('now', opsBusinessTz());
      } catch (Throwable $e) {
        $baseDate = new DateTime('now', opsBusinessTz());
      }

      if ($calMode === 'month') {
        $rangeStartDt = (clone $baseDate)->modify('first day of this month')->setTime(0,0,0);
        $rangeEndDt = (clone $baseDate)->modify('last day of this month')->setTime(23,59,59);
      } elseif ($calMode === 'week') {
        $wk = (int)$baseDate->format('N');
        $rangeStartDt = (clone $baseDate)->modify('-' . max(0, $wk - 1) . ' days')->setTime(0,0,0);
        $rangeEndDt = (clone $rangeStartDt)->modify('+6 days')->setTime(23,59,59);
      } else {
        $rangeStartDt = (clone $baseDate)->setTime(0,0,0);
        $rangeEndDt = (clone $baseDate)->setTime(23,59,59);
      }

      $rangeStart = $rangeStartDt->format('Y-m-d H:i:s');
      $rangeEnd = $rangeEndDt->format('Y-m-d H:i:s');

      $whereCal = $where;
      $paramsCal = $params;
      $typesCal = $types;
      $whereCal[] = "t.due_at IS NOT NULL AND t.due_at BETWEEN ? AND ?";
      $paramsCal[] = $rangeStart;
      $paramsCal[] = $rangeEnd;
      $typesCal .= 'ss';

      $whereCalSql = implode(' AND ', $whereCal);
      $sqlCal = "
        SELECT
          t.id, t.task_code, t.title, t.priority, t.status, t.progress, t.due_at,
          GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS assignee_names
        FROM tasks t
        LEFT JOIN task_assignees ta ON ta.task_id = t.id
        LEFT JOIN users u ON u.id = ta.user_id
        WHERE $whereCalSql
        GROUP BY t.id
        ORDER BY t.due_at ASC, t.priority ASC, t.created_at DESC
        LIMIT 5000
      ";
      $calRows = [];
      $stmtCal = $conn->prepare($sqlCal);
      if ($stmtCal) {
        if ($typesCal !== '') $stmtCal->bind_param($typesCal, ...$paramsCal);
        $stmtCal->execute();
        $calRows = $stmtCal->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmtCal->close();
      }

      $byDay = [];
      foreach ($calRows as $r) {
        $d = (string)($r['due_at'] ?? '');
        $ts = strtotime($d);
        if ($ts === false) continue;
        $key = appFormatUtcForTimezone($d, opsBusinessTz(), 'Y-m-d');
        if ($key === '') continue;
        if (!isset($byDay[$key])) $byDay[$key] = [];
        $byDay[$key][] = $r;
      }

      $buildUrl = function(array $overrides) {
        $qv = array_merge($_GET, $overrides);
        return '?' . http_build_query($qv);
      };

      $prevDate = clone $baseDate;
      $nextDate = clone $baseDate;
      if ($calMode === 'month') { $prevDate->modify('-1 month'); $nextDate->modify('+1 month'); }
      elseif ($calMode === 'week') { $prevDate->modify('-7 days'); $nextDate->modify('+7 days'); }
      else { $prevDate->modify('-1 day'); $nextDate->modify('+1 day'); }

      $label = $calMode === 'month' ? $baseDate->format('F Y') : ($calMode === 'week' ? ($rangeStartDt->format('d M') . ' → ' . $rangeEndDt->format('d M Y')) : $baseDate->format('d M Y'));

      $prioBadge = function(string $prio): string {
        if ($prio === 'Critical') return 'text-bg-danger';
        if ($prio === 'High') return 'text-bg-warning';
        if ($prio === 'Medium') return 'text-bg-primary';
        return 'text-bg-secondary';
      };
    ?>

    <div class="card hr-card mb-3">
      <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <a class="btn btn-sm btn-light border" href="<?php echo htmlspecialchars($buildUrl(['view' => 'calendar', 'cal' => $calMode, 'date' => $prevDate->format('Y-m-d')])); ?>"><i class="bi bi-chevron-left"></i></a>
          <div class="fw-semibold"><?php echo htmlspecialchars($label); ?></div>
          <a class="btn btn-sm btn-light border" href="<?php echo htmlspecialchars($buildUrl(['view' => 'calendar', 'cal' => $calMode, 'date' => $nextDate->format('Y-m-d')])); ?>"><i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary <?php echo $calMode === 'day' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildUrl(['view' => 'calendar', 'cal' => 'day', 'date' => $baseDate->format('Y-m-d')])); ?>">Day</a>
          <a class="btn btn-outline-secondary <?php echo $calMode === 'week' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildUrl(['view' => 'calendar', 'cal' => 'week', 'date' => $baseDate->format('Y-m-d')])); ?>">Week</a>
          <a class="btn btn-outline-secondary <?php echo $calMode === 'month' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildUrl(['view' => 'calendar', 'cal' => 'month', 'date' => $baseDate->format('Y-m-d')])); ?>">Month</a>
        </div>
      </div>
    </div>

    <?php if ($calMode === 'month'): ?>
      <?php
        $first = (clone $rangeStartDt);
        $last = (clone $rangeEndDt);
        $firstW = (int)$first->format('N');
        $gridStart = (clone $first)->modify('-' . max(0, $firstW - 1) . ' days');
        $lastW = (int)$last->format('N');
        $gridEnd = (clone $last)->modify('+' . max(0, 7 - $lastW) . ' days');

        $cur = (clone $gridStart);
        $days = [];
        while ($cur <= $gridEnd) {
          $days[] = $cur->format('Y-m-d');
          $cur->modify('+1 day');
          if (count($days) > 42) break;
        }
        $weekdays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
      ?>
      <?php
        $dayCellClass = function(array $items): string {
          if (empty($items)) return '';
          $hasOverdue = false;
          $allDone = true;
          foreach ($items as $it) {
            $st = (string)($it['status'] ?? '');
            if ($st === 'Overdue') $hasOverdue = true;
            if (!in_array($st, ['Completed','Cancelled','Rejected'], true)) $allDone = false;
          }
          if ($hasOverdue) return 'hr-att-status-absent';
          if ($allDone) return 'hr-att-status-present';
          return 'hr-att-status-late';
        };
        $todayKey = $opsTodayDate;
      ?>
      <div class="card hr-card">
        <div class="card-body">
          <div class="hr-att-cal mb-2">
            <?php foreach ($weekdays as $wd): ?>
              <div class="hr-att-cal-head"><?php echo htmlspecialchars($wd); ?></div>
            <?php endforeach; ?>
          </div>
          <div class="hr-att-cal">
            <?php foreach ($days as $d): ?>
              <?php
                $inMonth = $d !== '' && substr($d, 0, 7) === $baseDate->format('Y-m');
                $items = $d !== '' ? ($byDay[$d] ?? []) : [];
                $cls = $inMonth ? '' : 'is-out';
                if ($d === $todayKey) $cls .= ' is-today';
                $cls .= ' ' . $dayCellClass($items);
              ?>
              <div class="hr-att-cal-cell <?php echo htmlspecialchars(trim($cls)); ?> task-cal-cell" role="button" tabindex="0" data-date="<?php echo htmlspecialchars($d); ?>">
                <div class="hr-att-cal-top">
                  <div class="hr-att-cal-day"><?php echo $d !== '' ? htmlspecialchars((string)((int)substr($d, 8, 2))) : ''; ?></div>
                  <div class="hr-att-cal-status">
                    <?php if ($d === $todayKey): ?>
                      Today
                    <?php elseif (!empty($items)): ?>
                      <?php echo number_format(count($items)); ?> Tasks
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </div>
                </div>
                <div class="hr-att-cal-meta">
                  <?php if (empty($items)): ?>
                    <div class="hr-att-cal-line">No tasks due</div>
                  <?php else: ?>
                    <?php
                      $shown = 0;
                      foreach ($items as $it) {
                        $shown++;
                        if ($shown > 3) break;
                        $prio = (string)($it['priority'] ?? 'Medium');
                        $st = (string)($it['status'] ?? 'Assigned');
                    ?>
                      <div class="hr-att-cal-line">
                        <span class="badge <?php echo htmlspecialchars($prioBadge($prio)); ?> me-1"><?php echo htmlspecialchars(substr($prio, 0, 1)); ?></span>
                        <span class="hr-att-cal-strong"><?php echo htmlspecialchars((string)($it['task_code'] ?? '')); ?></span>
                        <span class="text-muted">·</span>
                        <span><?php echo htmlspecialchars((string)($it['title'] ?? '')); ?></span>
                        <span class="text-muted">·</span>
                        <span class="text-muted"><?php echo htmlspecialchars($st); ?></span>
                      </div>
                    <?php } ?>
                    <?php if (count($items) > 3): ?>
                      <div class="hr-att-cal-line text-muted">+<?php echo number_format(count($items) - 3); ?> more</div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <?php
        $cur = (clone $rangeStartDt);
        $days = [];
        while ($cur <= $rangeEndDt) {
          $days[] = $cur->format('Y-m-d');
          $cur->modify('+1 day');
          if (count($days) > 14) break;
        }
      ?>
      <div class="hr-att-week">
        <?php foreach ($days as $d): ?>
          <?php $items = $byDay[$d] ?? []; ?>
          <?php $displayDay = DateTimeImmutable::createFromFormat('!Y-m-d', $d, opsBusinessTz()); ?>
          <div class="hr-att-week-card task-cal-cell" role="button" tabindex="0" data-date="<?php echo htmlspecialchars($d); ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="fw-semibold"><?php echo htmlspecialchars($displayDay ? $displayDay->format('D, d M Y') : $d); ?></div>
              <span class="badge bg-light text-dark border"><?php echo number_format(count($items)); ?></span>
            </div>
            <div class="hr-att-cal-meta">
              <?php if (empty($items)): ?>
                <div class="hr-att-cal-line">No tasks due</div>
              <?php else: ?>
                <?php $shown = 0; foreach ($items as $it): $shown++; if ($shown > 6) break; ?>
                  <?php
                    $prio = (string)($it['priority'] ?? 'Medium');
                    $st = (string)($it['status'] ?? 'Assigned');
                  ?>
                  <div class="hr-att-cal-line">
                    <span class="badge <?php echo htmlspecialchars($prioBadge($prio)); ?> me-1"><?php echo htmlspecialchars($prio); ?></span>
                    <span class="hr-att-cal-strong"><?php echo htmlspecialchars((string)($it['task_code'] ?? '')); ?></span>
                    <span class="text-muted">·</span>
                    <span><?php echo htmlspecialchars((string)($it['title'] ?? '')); ?></span>
                    <span class="text-muted">·</span>
                    <span class="text-muted"><?php echo htmlspecialchars($st); ?></span>
                  </div>
                <?php endforeach; ?>
                <?php if (count($items) > 6): ?>
                  <div class="hr-att-cal-line text-muted">+<?php echo number_format(count($items) - 6); ?> more</div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
      $byDayMini = [];
      foreach ($byDay as $d => $items) {
        $mini = [];
        foreach ($items as $it) {
          $mini[] = [
            'id' => (int)($it['id'] ?? 0),
            'task_code' => (string)($it['task_code'] ?? ''),
            'title' => (string)($it['title'] ?? ''),
            'priority' => (string)($it['priority'] ?? ''),
            'status' => (string)($it['status'] ?? ''),
            'department' => (string)($it['department'] ?? ''),
            'assignees' => (string)($it['assignee_names'] ?? ''),
            'due_at' => (string)($it['due_at'] ?? ''),
          ];
        }
        $byDayMini[$d] = $mini;
      }
    ?>
    <div class="offcanvas offcanvas-end" tabindex="-1" id="taskDayDrawer" aria-labelledby="taskDayDrawerLabel" style="width:min(520px,calc(100vw - 16px));max-width:calc(100vw - 16px);">
      <div class="offcanvas-header">
        <div>
          <div class="offcanvas-title fw-semibold" id="taskDayDrawerLabel">Tasks</div>
          <div class="text-muted small font-monospace" id="taskDayDrawerDate"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="small text-muted" id="taskDayDrawerCount"></div>
          <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks/create'); ?>"><i class="bi bi-plus-circle me-1"></i>Create</a>
        </div>
        <div class="d-flex flex-column gap-2" id="taskDayDrawerList"></div>
      </div>
    </div>
  <?php else: ?>
    <div class="card border-0 shadow-sm tasks-table-shell">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Task</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Progress</th>
              <th>Assignees</th>
              <th>Department</th>
              <th>Due</th>
              <th class="text-end pe-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($tasks)): ?>
              <tr><td colspan="8" class="tasks-empty-state">No tasks found.</td></tr>
            <?php else: ?>
              <?php foreach ($tasks as $t): ?>
                <?php
                  $prio = (string)($t['priority'] ?? 'Medium');
                  $pcls = 'text-bg-secondary';
                  if ($prio === 'Critical') $pcls = 'text-bg-danger';
                  elseif ($prio === 'High') $pcls = 'text-bg-warning';
                  elseif ($prio === 'Medium') $pcls = 'text-bg-primary';
                  $st = (string)($t['status'] ?? 'Assigned');
                  $scls = 'text-bg-secondary';
                  if (in_array($st, ['Completed'], true)) $scls = 'text-bg-success';
                  elseif (in_array($st, ['Overdue'], true)) $scls = 'text-bg-danger';
                  elseif (in_array($st, ['Under Review'], true)) $scls = 'text-bg-info';
                  elseif (in_array($st, ['On Hold','Waiting For Input'], true)) $scls = 'text-bg-warning';
                  $due = (string)($t['due_at'] ?? '');
                  $dueDt = $due !== '' ? appParseUtc($due) : null;
                  $isOver = ($dueDt instanceof DateTimeImmutable && $dueDt < $nowUtcDt && !in_array($st, ['Completed','Cancelled','Rejected'], true));
                ?>
                <tr>
                  <td class="ps-3">
                    <div class="fw-semibold"><a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks/view?id=' . (int)$t['id']); ?>"><?php echo htmlspecialchars($t['title'] ?? ''); ?></a></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($t['task_code'] ?? ''); ?> · Created by <?php echo htmlspecialchars($t['created_by_name'] ?? ''); ?></div>
                  </td>
                  <td><span class="badge <?php echo $pcls; ?>"><?php echo htmlspecialchars($prio); ?></span></td>
                  <td><span class="badge <?php echo $scls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                  <td style="min-width:140px;">
                    <div class="d-flex justify-content-between small text-muted"><span><?php echo (int)($t['progress'] ?? 0); ?>%</span><span><?php echo (int)($t['checklist_done'] ?? 0); ?>/<?php echo (int)($t['checklist_total'] ?? 0); ?></span></div>
                    <div class="progress" style="height:6px;">
                      <div class="progress-bar" role="progressbar" style="width: <?php echo (int)($t['progress'] ?? 0); ?>%"></div>
                    </div>
                  </td>
                  <td class="small"><?php echo htmlspecialchars($t['assignee_names'] ?? ''); ?></td>
                  <td class="small text-muted"><?php echo htmlspecialchars($t['department'] ?? ''); ?></td>
                  <td class="small <?php echo $isOver ? 'text-danger' : 'text-muted'; ?>"><?php echo $dueDt ? htmlspecialchars($dueDt->setTimezone(opsBusinessTz())->format('d M, H:i')) : '—'; ?></td>
                  <td class="text-end pe-3">
                    <a class="btn btn-sm btn-light border" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks/view?id=' . (int)$t['id']); ?>"><i class="bi bi-eye"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const csrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const apiUrl = <?php echo json_encode(appBasePath() . '/modules/tasks/api', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

  function postJson(payload){
    return fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(r => r.json());
  }

  const cards = Array.from(document.querySelectorAll('.kanban-card'));
  const cols = Array.from(document.querySelectorAll('.kanban-col'));
  if (cards.length && cols.length) {
    let dragging = null;
    cards.forEach(c => {
      c.addEventListener('dragstart', (e) => {
        dragging = c;
        c.classList.add('opacity-50');
        e.dataTransfer.effectAllowed = 'move';
      });
      c.addEventListener('dragend', () => {
        c.classList.remove('opacity-50');
        dragging = null;
      });
    });

    cols.forEach(col => {
      col.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
      });
      col.addEventListener('drop', async (e) => {
        e.preventDefault();
        if (!dragging) return;
        const taskId = Number(dragging.getAttribute('data-task-id') || '0');
        const newStatus = String(col.getAttribute('data-target-status') || '').trim();
        if (!taskId || !newStatus) return;

        col.appendChild(dragging);
        try {
          const res = await postJson({ action: 'update_status', csrf_token: csrf, task_id: taskId, status: newStatus });
          if (!res || !res.ok) location.reload();
        } catch (err) {
          location.reload();
        }
      });
    });
  }

  const byDay = <?php echo json_encode($byDayMini ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const drawerEl = document.getElementById('taskDayDrawer');
  const listEl = document.getElementById('taskDayDrawerList');
  const dateEl = document.getElementById('taskDayDrawerDate');
  const countEl = document.getElementById('taskDayDrawerCount');
  if (!drawerEl || !listEl || !dateEl || !countEl) return;

  const off = bootstrap.Offcanvas.getOrCreateInstance(drawerEl);

  const badgeClass = (priority) => {
    if (priority === 'Critical') return 'text-bg-danger';
    if (priority === 'High') return 'text-bg-warning';
    if (priority === 'Medium') return 'text-bg-primary';
    return 'text-bg-secondary';
  };

  const statusBadge = (st) => {
    if (st === 'Completed') return 'text-bg-success';
    if (st === 'Overdue') return 'text-bg-danger';
    if (st === 'Under Review') return 'text-bg-info';
    if (st === 'On Hold' || st === 'Waiting For Input') return 'text-bg-warning';
    return 'text-bg-secondary';
  };

  const openDay = (d) => {
    if (!d) return;
    const items = Array.isArray(byDay[d]) ? byDay[d] : [];
    dateEl.textContent = d;
    countEl.textContent = items.length ? (items.length + ' tasks due') : 'No tasks due';
    listEl.innerHTML = '';
    if (!items.length) {
      const div = document.createElement('div');
      div.className = 'text-muted';
      div.textContent = 'No tasks due on this date.';
      listEl.appendChild(div);
    } else {
      items.forEach(it => {
        const wrap = document.createElement('div');
        wrap.className = 'border rounded-3 p-2';
        const title = document.createElement('div');
        title.className = 'd-flex justify-content-between align-items-start gap-2';
        const left = document.createElement('div');
        left.className = 'small';
        const t = document.createElement('div');
        t.className = 'fw-semibold';
        t.textContent = (it.task_code ? it.task_code + ' · ' : '') + (it.title || '');
        const meta = document.createElement('div');
        meta.className = 'text-muted';
        meta.textContent = [it.department, it.assignees].filter(Boolean).join(' · ');
        left.appendChild(t);
        left.appendChild(meta);
        const right = document.createElement('div');
        right.className = 'd-flex align-items-center gap-2 flex-wrap';
        const p = document.createElement('span');
        p.className = 'badge ' + badgeClass(it.priority || '');
        p.textContent = it.priority || '—';
        const s = document.createElement('span');
        s.className = 'badge ' + statusBadge(it.status || '');
        s.textContent = it.status || 'Assigned';
        const a = document.createElement('a');
        a.className = 'btn btn-sm btn-light border';
        a.href = <?php echo json_encode(appBasePath() . '/modules/tasks/view?id=', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?> + String(it.id || 0);
        a.innerHTML = '<i class="bi bi-eye"></i>';
        right.appendChild(p);
        right.appendChild(s);
        right.appendChild(a);
        title.appendChild(left);
        title.appendChild(right);
        wrap.appendChild(title);
        listEl.appendChild(wrap);
      });
    }
    off.show();
  };

  document.querySelectorAll('.task-cal-cell').forEach(el => {
    el.addEventListener('click', () => openDay(el.getAttribute('data-date') || ''));
    el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openDay(el.getAttribute('data-date') || '');
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
