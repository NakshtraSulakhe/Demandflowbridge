<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.assignment', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$role = normalizeRole((string)($user['role'] ?? ''));
$conn = getDbConnection();
$canDeleteInvalidRecords = in_array($role, ['admin', 'director', 'manager_director', 'operations_director', 'operations_manager'], true);

$queueDefinitions = dbmsWorkflowQueueDefinitions();
$queueOrder = ['pending_research', 'research_rework', 'pending_cdqa', 'research_assigned', 'cdqa_assigned', 'approved', 'rejected', 'duplicate'];
$queue = dbmsNormalizeWorkflowStatus((string)($_GET['queue'] ?? 'pending_research'));
if (!isset($queueDefinitions[$queue])) $queue = 'pending_research';
$q = trim((string)($_GET['q'] ?? ''));
$batchId = (int)($_GET['batch_id'] ?? 0);
$deptFilter = trim((string)($_GET['department'] ?? ''));
$unassignedOnly = ((string)($_GET['unassigned'] ?? '1') === '1');
$assignmentDepartments = dbmsWorkflowAssignmentDepartments();

$canPermCfg = getAccessRolePermissionsConfig();
$getUsers = function() use ($conn): array {
    $rs = $conn->query("SELECT id, full_name, role, is_active FROM users WHERE is_active = 1 ORDER BY full_name ASC");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
};
$users = $getUsers();
$usersById = [];
foreach ($users as $u) {
    $uid = (int)($u['id'] ?? 0);
    if ($uid > 0) $usersById[$uid] = $u;
}
$userCanHandleDepartment = function(int $uid, string $department) use ($usersById, $canPermCfg): bool {
    if ($uid <= 0) return false;
    $u = $usersById[$uid] ?? null;
    if (!is_array($u)) return false;
    return dbmsUserCanHandleDepartment($u, $canPermCfg, $department);
};

$hasRevenueLink = function_exists('dbmsContactsHasColumn') ? dbmsContactsHasColumn('revenue_link') : false;

$buildWhere = function() use ($queue, $q, $batchId, $deptFilter, $unassignedOnly, $hasRevenueLink, &$params, &$types): string {
    $params = [];
    $types = '';
    $w = [dbmsWorkflowQueueConditionSql($queue, 'c', $unassignedOnly)];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql = "(c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company_name LIKE ? OR c.job_title LIKE ?";
        $params = array_merge($params, [$like,$like,$like,$like,$like,$like]);
        $types .= 'ssssss';
        if ($hasRevenueLink) {
            $sql .= " OR c.revenue_link LIKE ?";
            $params[] = $like;
            $types .= 's';
        }
        $sql .= ")";
        $w[] = $sql;
    }
    if ($batchId > 0) {
        $w[] = "c.batch_id = ?";
        $params[] = $batchId;
        $types .= 'i';
    }
    if ($deptFilter !== '') {
        $w[] = "c.assigned_department = ?";
        $params[] = $deptFilter;
        $types .= 's';
    }
    return !empty($w) ? ('WHERE ' . implode(' AND ', $w)) : '';
};

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid request token.');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'delete_invalid_contact') {
            if (!$canDeleteInvalidRecords) {
                throw new RuntimeException('You are not allowed to delete invalid DBMS records.');
            }
            dbmsDeleteInvalidContact((int)($_POST['contact_id'] ?? 0), $userId, 'dbms.assignments');
            $message = 'Invalid DBMS record removed successfully.';
            $messageType = 'success';
        } elseif ($action === 'delete_invalid_selected') {
            if (!$canDeleteInvalidRecords) {
                throw new RuntimeException('You are not allowed to delete invalid DBMS records.');
            }
            $contactIds = $_POST['ids'] ?? [];
            if (!is_array($contactIds)) {
                $contactIds = [];
            }
            $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn($value) => $value > 0)));
            if (empty($contactIds)) {
                throw new RuntimeException('Select invalid records to delete.');
            }
            $deletedCount = 0;
            foreach ($contactIds as $contactId) {
                dbmsDeleteInvalidContact($contactId, $userId, 'dbms.assignments');
                $deletedCount++;
            }
            $message = 'Deleted ' . number_format($deletedCount) . ' invalid DBMS records.';
            $messageType = 'success';
        } else {
            if ($action !== 'assign') throw new RuntimeException('Invalid action.');

            $assignTo = (int)($_POST['assign_to'] ?? 0);
            $dept = trim((string)($_POST['department'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $mode = trim((string)($_POST['select_mode'] ?? 'ids'));
            $allowReassign = ((string)($_POST['allow_reassign'] ?? '0') === '1');
            if (!isset($assignmentDepartments[$dept])) throw new RuntimeException('Select department.');
            if ($assignTo <= 0) throw new RuntimeException('Select user to assign.');
            if (!$userCanHandleDepartment($assignTo, $dept)) throw new RuntimeException('Selected user does not have access to this DBMS workflow lane.');

            $params = []; $types = '';
            $whereSql = $buildWhere();
            $contactIds = [];

            if ($mode === 'all_filtered') {
                $stmtCt = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_contacts c {$whereSql}");
                $matchCount = 0;
                if ($stmtCt) {
                    if ($types !== '') mysqliBindParams($stmtCt, $types, $params);
                    $stmtCt->execute();
                    $matchCount = (int)(($stmtCt->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
                    $stmtCt->close();
                }
                if ($matchCount <= 0) throw new RuntimeException('No records matched the current queue/filters.');
                if ($matchCount > 50000) throw new RuntimeException('Too many records matched. Narrow filters (max 50,000).');
                $stmtIds = $conn->prepare("SELECT c.id FROM dbms_contacts c {$whereSql} ORDER BY c.id ASC LIMIT 50000");
                if (!$stmtIds) throw new RuntimeException('Database error.');
                if ($types !== '') mysqliBindParams($stmtIds, $types, $params);
                $stmtIds->execute();
                $rsIds = $stmtIds->get_result();
                while ($row = $rsIds->fetch_assoc()) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) $contactIds[] = $id;
                }
                $stmtIds->close();
            } else {
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) $ids = [];
                foreach ($ids as $id) {
                    $id = (int)$id;
                    if ($id > 0) $contactIds[] = $id;
                }
                $contactIds = array_values(array_unique($contactIds));
                if (empty($contactIds)) throw new RuntimeException('No records selected.');
                if (count($contactIds) > 5000) throw new RuntimeException('Too many records selected. Use All Filtered mode or select fewer records.');
            }

            $result = dbmsAssignContacts($contactIds, $dept, $assignTo, $userId, $note, $allowReassign, 'dbms.assignments');
            $assignedCount = (int)($result['assigned_count'] ?? 0);
            if ($assignedCount <= 0) throw new RuntimeException('No records were assigned. They may already be assigned or not belong to this workflow queue.');

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

$queueCounts = [
    'pending_research' => 0,
    'research_rework' => 0,
    'pending_cdqa' => 0,
    'research_assigned' => 0,
    'cdqa_assigned' => 0,
    'approved' => 0,
    'duplicate' => 0,
    'rejected' => 0,
];
$rs = $conn->query("
    SELECT
      SUM(" . dbmsWorkflowQueueConditionSql('pending_research', 'c', true) . ") AS pending_research_cnt,
      SUM(" . dbmsWorkflowQueueConditionSql('research_rework', 'c', true) . ") AS research_rework_cnt,
      SUM(" . dbmsWorkflowQueueConditionSql('pending_cdqa', 'c', true) . ") AS pending_cdqa_cnt,
      SUM(" . dbmsWorkflowQueueConditionSql('research_assigned', 'c') . ") AS research_assigned_cnt,
      SUM(" . dbmsWorkflowQueueConditionSql('cdqa_assigned', 'c') . ") AS cdqa_assigned_cnt,
      SUM(" . dbmsWorkflowQueueConditionSql('approved', 'c') . ") AS approved_cnt,
      SUM(" . dbmsWorkflowQueueConditionSql('duplicate', 'c') . ") AS dup_cnt,
      SUM(" . dbmsWorkflowQueueConditionSql('rejected', 'c') . ") AS rej_cnt
    FROM dbms_contacts c
");
if ($rs) {
    $r = $rs->fetch_assoc() ?: [];
    $queueCounts['pending_research'] = (int)($r['pending_research_cnt'] ?? 0);
    $queueCounts['research_rework'] = (int)($r['research_rework_cnt'] ?? 0);
    $queueCounts['pending_cdqa'] = (int)($r['pending_cdqa_cnt'] ?? 0);
    $queueCounts['research_assigned'] = (int)($r['research_assigned_cnt'] ?? 0);
    $queueCounts['cdqa_assigned'] = (int)($r['cdqa_assigned_cnt'] ?? 0);
    $queueCounts['approved'] = (int)($r['approved_cnt'] ?? 0);
    $queueCounts['duplicate'] = (int)($r['dup_cnt'] ?? 0);
    $queueCounts['rejected'] = (int)($r['rej_cnt'] ?? 0);
}

$params = []; $types = '';
$whereSql = $buildWhere();

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 50;
$offset = ($page - 1) * $pageSize;

$total = 0;
$stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_contacts c $whereSql");
if ($stmtC) {
    if ($types !== '') mysqliBindParams($stmtC, $types, $params);
    $stmtC->execute();
    $total = (int)(($stmtC->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmtC->close();
}

$rows = [];
$reasonMap = [];
$sql = "
    SELECT c.*, " . dbmsWorkflowStatusSql('c') . " AS workflow_queue, u.full_name AS assigned_to_name
    FROM dbms_contacts c
    LEFT JOIN users u ON u.id = c.assigned_to_user_id
    $whereSql
    ORDER BY c.updated_at DESC, c.created_at DESC, c.id DESC
    LIMIT $pageSize OFFSET $offset
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}
if (in_array($queue, ['rejected', 'duplicate'], true) && !empty($rows)) {
    $ids = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows), fn($x) => $x > 0));
    $ids = array_values(array_unique($ids));
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types2 = str_repeat('i', count($ids));
        $sql2 = "SELECT contact_id, reason FROM dbms_batch_rows WHERE contact_id IN ($in) ORDER BY id DESC";
        $stmt2 = $conn->prepare($sql2);
        if ($stmt2) {
            mysqliBindParams($stmt2, $types2, $ids);
            $stmt2->execute();
            $rs2 = $stmt2->get_result();
            while ($r = $rs2->fetch_assoc()) {
                $cid = (int)($r['contact_id'] ?? 0);
                if ($cid > 0 && !isset($reasonMap[$cid])) $reasonMap[$cid] = (string)($r['reason'] ?? '');
            }
            $stmt2->close();
        }
    }
}

$pageTitle = 'DBMS Assignments';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="h3 mb-1">DBMS Assignment Management</div>
      <div class="text-muted small">Research and CDQA workflow queues for operational distribution</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge bg-primary">DBMS</span>
      <span class="badge bg-light text-dark border">Assignments</span>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="card hr-card mb-3">
    <div class="card-body">
      <div class="d-flex gap-2 flex-wrap">
        <?php foreach ($queueOrder as $k): ?>
          <?php if (!isset($queueDefinitions[$k])) continue; ?>
          <a class="btn btn-sm <?php echo $queue === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>"
             href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/assignments?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['queue' => $k, 'page' => 1]))); ?>">
            <?php echo htmlspecialchars((string)$queueDefinitions[$k]['label']); ?>
            <span class="badge bg-light text-dark border ms-1"><?php echo number_format((int)($queueCounts[$k] ?? 0)); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <hr class="my-3">
      <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="queue" value="<?php echo htmlspecialchars($queue); ?>">
        <div class="col-lg-5">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name / company / email / title<?php echo $hasRevenueLink ? ' / revenue link' : ''; ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Batch ID</label>
          <input class="form-control form-control-sm font-monospace" name="batch_id" value="<?php echo $batchId > 0 ? (int)$batchId : ''; ?>" placeholder="Batch">
        </div>
        <div class="col-lg-3">
          <label class="form-label small text-muted">Assigned Department</label>
          <select class="form-select form-select-sm" name="department">
            <option value="">All</option>
            <?php foreach ($assignmentDepartments as $d): ?>
              <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $deptFilter === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2 d-flex gap-2">
          <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
          <a class="btn btn-outline-secondary btn-sm w-100" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/assignments?queue=<?php echo htmlspecialchars($queue); ?>"><i class="bi bi-x me-1"></i>Reset</a>
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="unassignedOnly" name="unassigned" value="1" <?php echo $unassignedOnly ? 'checked' : ''; ?>>
            <label class="form-check-label small" for="unassignedOnly">Show only unassigned (where applicable)</label>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-xl-8">
      <div class="card hr-card">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-list-check me-1"></i>Queue Records</span>
          <span class="badge bg-secondary"><?php echo number_format($total); ?></span>
        </div>
        <div class="card-body">
          <?php if (empty($rows)): ?>
            <div class="text-muted">No records found for this queue/filter.</div>
          <?php else: ?>
            <form method="post" id="assignForm">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
              <input type="hidden" name="action" id="assignFormAction" value="assign">
              <input type="hidden" name="select_mode" id="selectMode" value="ids">
              <input type="hidden" name="department" id="assignDeptHidden" value="">
              <input type="hidden" name="assign_to" id="assignToHidden" value="">
              <input type="hidden" name="note" id="assignNoteHidden" value="">
              <input type="hidden" name="allow_reassign" id="allowReassignHidden" value="0">
              <input type="hidden" name="contact_id" id="deleteContactIdHidden" value="">
              <?php if ($canDeleteInvalidRecords && $queue === 'rejected'): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                  <div class="small text-muted">Delete is enabled for invalid records only in the rejected queue.</div>
                  <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAssignRows(true)">
                      <i class="bi bi-check2-square me-1"></i>Select All
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAssignRows(false)">
                      <i class="bi bi-square me-1"></i>Clear
                    </button>
                    <span class="small text-muted align-self-center" id="assignSelectedCount">0 invalid records selected</span>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteSelectedInvalid()">
                      <i class="bi bi-trash me-1"></i>Delete Selected
                      <span class="ms-1">Invalid Only</span>
                    </button>
                  </div>
                </div>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle hr-table mb-0">
                  <thead>
                    <tr>
                      <th style="width:38px"><input type="checkbox" class="form-check-input" id="chkAll"></th>
                      <th>ID</th>
                      <th>Name</th>
                      <th>Company</th>
                      <th>Job Title</th>
                      <th>Email</th>
                      <?php if ($hasRevenueLink): ?><th>Revenue Link</th><?php endif; ?>
                      <th>Workflow</th>
                      <th>Assigned To</th>
                      <th>View</th>
                      <?php if ($canDeleteInvalidRecords && $queue === 'rejected'): ?><th>Delete</th><?php endif; ?>
                      <?php if (in_array($queue, ['rejected', 'duplicate'], true)): ?><th>Reason</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r): ?>
                      <?php
                        $cid = (int)($r['id'] ?? 0);
                        $nm = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                        if ($nm === '') $nm = trim((string)($r['name'] ?? ''));
                        $rl = trim((string)($r['revenue_link'] ?? ''));
                        $reason = $cid > 0 ? (string)($reasonMap[$cid] ?? '') : '';
                      ?>
                      <tr>
                        <td><input type="checkbox" class="form-check-input chkRow" name="ids[]" value="<?php echo $cid; ?>"></td>
                        <td class="font-monospace"><?php echo $cid; ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($nm !== '' ? $nm : '—'); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['company_name'] ?? '')); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['job_title'] ?? '')); ?></td>
                        <td class="font-monospace small"><?php echo htmlspecialchars((string)($r['email'] ?? '')); ?></td>
                        <?php if ($hasRevenueLink): ?>
                          <td class="text-muted small">
                            <?php if ($rl !== ''): ?><a href="<?php echo htmlspecialchars($rl); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($rl); ?></a><?php else: ?>—<?php endif; ?>
                          </td>
                        <?php endif; ?>
                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(dbmsWorkflowLabel((string)($r['workflow_queue'] ?? ''))); ?></span></td>
                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['assigned_to_name'] ?? '')); ?></td>
                        <td><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/view?id=<?php echo $cid; ?>"><i class="bi bi-eye"></i></a></td>
                        <?php if ($canDeleteInvalidRecords && $queue === 'rejected'): ?>
                          <td>
                            <button type="button" class="btn btn-outline-danger btn-sm" title="Delete invalid record only" onclick="deleteSingleInvalid(<?php echo $cid; ?>)">
                              <i class="bi bi-trash"></i><span class="ms-1">Invalid Only</span>
                            </button>
                          </td>
                        <?php endif; ?>
                        <?php if (in_array($queue, ['rejected', 'duplicate'], true)): ?><td class="text-muted small"><?php echo htmlspecialchars($reason !== '' ? $reason : '—'); ?></td><?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </form>
            <?php
              $pages = (int)ceil($total / $pageSize);
              $qs = $_GET;
            ?>
            <?php if ($pages > 1): ?>
              <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <div class="text-muted small">Page <?php echo number_format($page); ?> / <?php echo number_format($pages); ?></div>
                <div class="btn-group btn-group-sm">
                  <?php $qs['page'] = max(1, $page - 1); ?>
                  <a class="btn btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Prev</a>
                  <?php $qs['page'] = min($pages, $page + 1); ?>
                  <a class="btn btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Next</a>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card hr-card">
      <div class="card-header bg-light fw-semibold"><i class="bi bi-person-check me-1"></i>Assign</div>
        <div class="card-body">
          <div id="assignControls">
            <div class="mb-2">
              <label class="form-label small text-muted">Department</label>
              <select class="form-select form-select-sm" id="assignDept" required>
                <option value="">Select</option>
                <?php foreach ($assignmentDepartments as $d): ?>
                  <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small text-muted">Assign To</label>
              <select class="form-select form-select-sm" id="assignTo" required>
                <option value="">Select user</option>
                <?php foreach ($users as $u): ?>
                  <?php $uid = (int)($u['id'] ?? 0); if ($uid <= 0) continue; ?>
                  <?php
                    $canResearch = $userCanHandleDepartment($uid, 'Research');
                    $canCdqa = $userCanHandleDepartment($uid, 'CDQA');
                    $laneLabel = $canResearch && $canCdqa ? 'Research, CDQA' : ($canResearch ? 'Research' : ($canCdqa ? 'CDQA' : 'No lane'));
                  ?>
                  <option value="<?php echo $uid; ?>" <?php echo (!$canResearch && !$canCdqa) ? 'disabled' : ''; ?>>
                    <?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?>
                    <?php echo htmlspecialchars(' (' . $laneLabel . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small text-muted">Note (optional)</label>
              <textarea class="form-control form-control-sm" id="assignNote" rows="2" placeholder="Assignment note"></textarea>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="allowReassign" value="1">
              <label class="form-check-label small" for="allowReassign">Allow re-assign already assigned records</label>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary btn-sm w-100" type="button" onclick="setMode('ids')"><i class="bi bi-person-check me-1"></i>Assign Selected</button>
              <button class="btn btn-outline-primary btn-sm w-100" type="button" onclick="setMode('all_filtered')"><i class="bi bi-funnel me-1"></i>Assign All Filtered</button>
            </div>
          </div>
          <div class="small text-muted mt-2">
            Assign Selected uses checkboxes. Assign All Filtered assigns up to 50,000 records from the current workflow queue + filters.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  (function(){
    const chkAll = document.getElementById('chkAll');
    const assignCountLabel = document.getElementById('assignSelectedCount');
    const updateAssignSelectedCount = function(){
      if (!assignCountLabel) return;
      const count = document.querySelectorAll('.chkRow:checked').length;
      assignCountLabel.textContent = count + ' invalid record' + (count === 1 ? '' : 's') + ' selected';
    };
    if (chkAll) {
      chkAll.addEventListener('change', function(){
        document.querySelectorAll('.chkRow').forEach((c) => { c.checked = chkAll.checked; });
        updateAssignSelectedCount();
      });
    }
    document.querySelectorAll('.chkRow').forEach((checkbox) => {
      checkbox.addEventListener('change', function(){
        if (chkAll) {
          const total = document.querySelectorAll('.chkRow').length;
          const checked = document.querySelectorAll('.chkRow:checked').length;
          chkAll.checked = total > 0 && total === checked;
        }
        updateAssignSelectedCount();
      });
    });
    window.toggleAssignRows = function(checked){
      const master = document.getElementById('chkAll');
      if (master) {
        master.checked = !!checked;
      }
      document.querySelectorAll('.chkRow').forEach((checkbox) => {
        checkbox.checked = !!checked;
      });
      updateAssignSelectedCount();
    };
    window.deleteSelectedInvalid = function(){
      const f = document.getElementById('assignForm');
      const actionField = document.getElementById('assignFormAction');
      const selected = Array.from(document.querySelectorAll('.chkRow:checked'));
      if (!f || !actionField) return;
      if (!selected.length) {
        alert('Select invalid records first.');
        return;
      }
      const deleteIdField = document.getElementById('deleteContactIdHidden');
      if (deleteIdField) deleteIdField.value = '';
      actionField.value = 'delete_invalid_selected';
      if (!confirm('Delete the selected invalid DBMS records permanently?')) {
        return;
      }
      f.submit();
    };
    window.deleteSingleInvalid = function(contactId){
      const f = document.getElementById('assignForm');
      const actionField = document.getElementById('assignFormAction');
      const deleteIdField = document.getElementById('deleteContactIdHidden');
      if (!f || !actionField || !deleteIdField || !contactId) return;
      actionField.value = 'delete_invalid_contact';
      deleteIdField.value = String(contactId);
      if (!confirm('Delete this invalid DBMS record permanently?')) {
        return;
      }
      f.submit();
    };
    window.setMode = function(mode){
      const f = document.getElementById('assignForm');
      if (!f) return;
      const dept = document.getElementById('assignDept');
      const to = document.getElementById('assignTo');
      const note = document.getElementById('assignNote');
      const allow = document.getElementById('allowReassign');
      const deptVal = dept ? String(dept.value || '').trim() : '';
      const toVal = to ? String(to.value || '').trim() : '';
      if (!deptVal) return;
      if (!toVal) return;
      const hDept = document.getElementById('assignDeptHidden');
      const hTo = document.getElementById('assignToHidden');
      const hNote = document.getElementById('assignNoteHidden');
      const hAllow = document.getElementById('allowReassignHidden');
      const actionField = document.getElementById('assignFormAction');
      const deleteIdField = document.getElementById('deleteContactIdHidden');
      if (hDept) hDept.value = deptVal;
      if (hTo) hTo.value = toVal;
      if (hNote) hNote.value = note ? String(note.value || '') : '';
      if (hAllow) hAllow.value = (allow && allow.checked) ? '1' : '0';
      if (actionField) actionField.value = 'assign';
      if (deleteIdField) deleteIdField.value = '';
      const m1 = document.getElementById('selectMode');
      if (m1) m1.value = mode;
      f.submit();
    };
    updateAssignSelectedCount();
  })();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
