<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.research', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();
$workflowExpr = dbmsWorkflowStatusSql('c');

$message = '';
$messageType = 'success';

$statusList = [
    'Pending',
    'Positive',
    'Interested',
    'Meeting Requested',
    'Qualified',
    'Marketing Qualified',
    'Sales Qualified',
    'Neutral',
    'Follow Up Required',
    'Callback Requested',
    'Information Sent',
    'Voice Mail',
    'Negative',
    'Not Interested',
    'Invalid Data',
    'Wrong Contact',
    'Left Company',
    'Competitor',
    'Duplicate Contact',
    'DNC Request',
];
$commentRequired = ['Invalid Data', 'Wrong Contact', 'Left Company', 'Duplicate Contact'];

$sanitize = function($v, int $maxLen = 2000): string {
    $v = (string)$v;
    $v = str_replace(["\0", "\r"], ['', "\n"], $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    $v = trim($v);
    $v = preg_replace("/[ \t]+/", ' ', $v);
    $v = preg_replace("/\n{3,}/", "\n\n", $v);
    if ($maxLen > 0 && strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
    return $v;
};

$normalizeTopics = function(string $topicsRaw): array {
    $topicsRaw = trim($topicsRaw);
    if ($topicsRaw === '') return [];
    $parts = preg_split('/[,\n]+/', $topicsRaw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $key = strtolower($p);
        if (!isset($out[$key])) $out[$key] = $p;
    }
    return array_values($out);
};

$loadAssignedResearchRecord = function(int $contactId) use ($conn, $userId, $workflowExpr): ?array {
    $sql = "
        SELECT c.*, {$workflowExpr} AS workflow_queue
        FROM dbms_contacts c
        WHERE c.id = ?
          AND c.assigned_to_user_id = ?
          AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'research'
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('ii', $contactId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return null;
    $workflow = dbmsNormalizeWorkflowStatus((string)($row['workflow_queue'] ?? ''));
    if (!in_array($workflow, ['research_assigned', 'research_rework'], true)) return null;
    return $row;
};

$saveResearchFields = function(array $contact) use ($conn, $userId, $sanitize): void {
    $contactId = (int)($contact['id'] ?? 0);
    $fields = [
        'first_name' => 120,
        'last_name' => 120,
        'email' => 180,
        'job_title' => 180,
        'department' => 120,
        'seniority' => 80,
        'industry' => 160,
        'website' => 255,
        'linkedin_url' => 2000,
        'company_name' => 200,
        'prospect_country' => 120,
        'employee_size_bracket' => 60,
        'employee_count' => 60,
        'revenue_bracket' => 80,
        'revenue' => 80,
        'revenue_link' => 500,
        'company_phone' => 40,
        'direct_number' => 40,
        'extension' => 20,
    ];

    $updates = [];
    $vals = [];
    $types = '';
    foreach ($fields as $col => $maxLen) {
        if (!array_key_exists($col, $_POST)) continue;
        if (!dbmsContactsHasColumn($col)) continue;
        $val = $sanitize((string)($_POST[$col] ?? ''), $maxLen);
        $updates[] = "`{$col}` = ?";
        $vals[] = $val;
        $types .= 's';
    }
    if (empty($updates)) return;
    if (dbmsContactsHasColumn('last_activity_at')) $updates[] = 'last_activity_at = NOW()';
    $updates[] = 'updated_at = NOW()';
    $vals[] = $contactId;
    $vals[] = $userId;
    $types .= 'ii';
    $stmt = $conn->prepare("UPDATE dbms_contacts SET " . implode(', ', $updates) . " WHERE id = ? AND assigned_to_user_id = ?");
    if (!$stmt) throw new RuntimeException('Database error.');
    mysqliBindParams($stmt, $types, $vals);
    $stmt->execute();
    $stmt->close();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) throw new RuntimeException('Invalid request token.');

        $action = (string)($_POST['action'] ?? '');
        $contactId = (int)($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) throw new RuntimeException('Invalid record.');

        $contact = $loadAssignedResearchRecord($contactId);
        if (!$contact) throw new RuntimeException('Record not found or not assigned to you.');

        if ($action === 'update_status') {
            $newStatus = trim((string)($_POST['agent_status'] ?? ''));
            $comment = $sanitize((string)($_POST['comment'] ?? ''), 2000);
            if (!in_array($newStatus, $statusList, true)) throw new RuntimeException('Invalid status.');
            if (in_array($newStatus, $commentRequired, true) && $comment === '') throw new RuntimeException('Comment is required for this status.');

            $oldStatus = (string)($contact['agent_status'] ?? '');
            $stmt = $conn->prepare("UPDATE dbms_contacts SET agent_status = ?, agent_status_updated_at = NOW(), last_activity_at = NOW(), updated_at = NOW() WHERE id = ? AND assigned_to_user_id = ?");
            if (!$stmt) throw new RuntimeException('Database error.');
            $stmt->bind_param('sii', $newStatus, $contactId, $userId);
            $stmt->execute();
            $stmt->close();

            if ($comment !== '') dbmsAddContactComment($contactId, $userId, $comment);
            dbmsInsertAuditLog($contactId, 'Status Change', 'agent_status', $oldStatus, $newStatus, $comment !== '' ? $comment : null, $userId, 'dbms.research');

            $message = 'Research status updated.';
            $messageType = 'success';
        } elseif ($action === 'update_intent') {
            $newTopicsRaw = $sanitize((string)($_POST['intent_topics'] ?? ''), 2000);
            $comment = $sanitize((string)($_POST['comment'] ?? ''), 2000);
            $prev = (string)($contact['intent_topics'] ?? '');

            $prevArr = $normalizeTopics($prev);
            $newArr = $normalizeTopics($newTopicsRaw);
            $merged = [];
            foreach ($prevArr as $t) $merged[strtolower($t)] = $t;
            foreach ($newArr as $t) $merged[strtolower($t)] = $t;
            $mergedStr = implode(', ', array_values($merged));

            if ($mergedStr !== $prev) {
                $stmt = $conn->prepare("UPDATE dbms_contacts SET intent_topics = ?, last_activity_at = NOW(), updated_at = NOW() WHERE id = ? AND assigned_to_user_id = ?");
                if (!$stmt) throw new RuntimeException('Database error.');
                $stmt->bind_param('sii', $mergedStr, $contactId, $userId);
                $stmt->execute();
                $stmt->close();

                $stmtH = $conn->prepare("INSERT INTO dbms_intent_topic_history (contact_id, prev_topics, new_topics, updated_by) VALUES (?,?,?,?)");
                if ($stmtH) {
                    $stmtH->bind_param('issi', $contactId, $prev, $mergedStr, $userId);
                    $stmtH->execute();
                    $stmtH->close();
                }
                dbmsInsertAuditLog($contactId, 'Intent Update', 'intent_topics', $prev, $mergedStr, $comment !== '' ? $comment : null, $userId, 'dbms.research');
            }

            if ($comment !== '') dbmsAddContactComment($contactId, $userId, $comment);
            $message = 'Intent topics updated.';
            $messageType = 'success';
        } elseif ($action === 'save_research') {
            $saveResearchFields($contact);
            $comment = $sanitize((string)($_POST['comment'] ?? ''), 2000);
            if ($comment !== '') {
                dbmsAddContactComment($contactId, $userId, $comment);
                dbmsInsertAuditLog($contactId, 'Research Update', 'comment', null, null, $comment, $userId, 'dbms.research');
            } else {
                dbmsInsertAuditLog($contactId, 'Research Update', 'record', null, null, 'Research details updated.', $userId, 'dbms.research');
            }
            $message = 'Research updates saved.';
            $messageType = 'success';
        } elseif ($action === 'submit_to_cdqa') {
            $saveResearchFields($contact);
            $comment = $sanitize((string)($_POST['comment'] ?? ''), 2000);
            $after = $loadAssignedResearchRecord($contactId);
            if (!$after) throw new RuntimeException('Record not found.');
            if (!dbmsIsCdqaReadyRecord($after)) {
                throw new RuntimeException('Cannot submit to CDQA yet. Fill First Name, Last Name, Job Title, Company Name, Industry, Prospect Country, and at least one identifier (Email, LinkedIn, Website, or Direct Number).');
            }
            if ($comment !== '') dbmsAddContactComment($contactId, $userId, $comment);
            dbmsTransitionContactWorkflow(
                $contactId,
                'pending_cdqa',
                $userId,
                'dbms.research',
                [
                    'expected_assignee_id' => $userId,
                    'expected_department' => 'Research',
                    'clear_assignment' => true,
                    'touch_research_submission' => true,
                    'remarks' => $comment !== '' ? $comment : 'Submitted to CDQA from Research queue.',
                ]
            );
            $message = 'Record submitted to CDQA queue.';
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$workflowFilter = dbmsNormalizeWorkflowStatus((string)($_GET['workflow'] ?? ''));
if (!in_array($workflowFilter, ['', 'research_assigned', 'research_rework'], true)) $workflowFilter = '';

$where = "WHERE c.assigned_to_user_id = ? AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'research' AND {$workflowExpr} IN ('research_assigned', 'research_rework')";
$params = [$userId];
$types = 'i';

if ($q !== '') {
    $qq = '%' . $q . '%';
    $where .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company_name LIKE ? OR c.job_title LIKE ?";
    $params = array_merge($params, [$qq, $qq, $qq, $qq, $qq]);
    $types .= 'sssss';
    if (dbmsContactsHasColumn('revenue_link')) {
        $where .= " OR c.revenue_link LIKE ?";
        $params[] = $qq;
        $types .= 's';
    }
    $where .= ")";
}
if ($statusFilter !== '' && in_array($statusFilter, $statusList, true)) {
    $where .= " AND c.agent_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($workflowFilter !== '') {
    $where .= " AND {$workflowExpr} = ?";
    $params[] = $workflowFilter;
    $types .= 's';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

$stats = ['total' => 0, 'research_assigned' => 0, 'research_rework' => 0];
$stmtStats = $conn->prepare("
    SELECT
      COUNT(*) AS total_rows,
      SUM({$workflowExpr} = 'research_assigned') AS assigned_rows,
      SUM({$workflowExpr} = 'research_rework') AS rework_rows
    FROM dbms_contacts c
    WHERE c.assigned_to_user_id = ?
      AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'research'
      AND {$workflowExpr} IN ('research_assigned', 'research_rework')
");
if ($stmtStats) {
    $stmtStats->bind_param('i', $userId);
    $stmtStats->execute();
    $statsRow = $stmtStats->get_result()->fetch_assoc() ?: [];
    $stats['total'] = (int)($statsRow['total_rows'] ?? 0);
    $stats['research_assigned'] = (int)($statsRow['assigned_rows'] ?? 0);
    $stats['research_rework'] = (int)($statsRow['rework_rows'] ?? 0);
    $stmtStats->close();
}

$total = 0;
$stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_contacts c {$where}");
if ($stmtC) {
    mysqliBindParams($stmtC, $types, $params);
    $stmtC->execute();
    $total = (int)(($stmtC->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmtC->close();
}

$rows = [];
$stmt = $conn->prepare("
    SELECT c.*, {$workflowExpr} AS workflow_queue
    FROM dbms_contacts c
    {$where}
    ORDER BY c.assigned_at DESC, c.id DESC
    LIMIT {$pageSize} OFFSET {$offset}
");
if ($stmt) {
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$pageTitle = 'DBMS Research Queue';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="h3 mb-1">DBMS Research Queue</div>
      <div class="text-muted small">Assigned research records, enrichment updates, and submit-to-CDQA workflow</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge bg-warning text-dark">Research</span>
      <span class="badge bg-light text-dark border"><?php echo number_format($stats['total']); ?> Assigned</span>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card hr-card h-100"><div class="card-body">
        <div class="text-muted small">Total Assigned</div>
        <div class="h3 mb-0"><?php echo number_format($stats['total']); ?></div>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card hr-card h-100"><div class="card-body">
        <div class="text-muted small">Active Research</div>
        <div class="h3 mb-0"><?php echo number_format($stats['research_assigned']); ?></div>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card hr-card h-100"><div class="card-body">
        <div class="text-muted small">Research Rework</div>
        <div class="h3 mb-0"><?php echo number_format($stats['research_rework']); ?></div>
      </div></div>
    </div>
  </div>

  <div class="card hr-card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-lg-5">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name / company / email / title">
        </div>
        <div class="col-lg-3">
          <label class="form-label small text-muted">Research Queue</label>
          <select class="form-select form-select-sm" name="workflow">
            <option value="">All</option>
            <option value="research_assigned" <?php echo $workflowFilter === 'research_assigned' ? 'selected' : ''; ?>>Research Assigned</option>
            <option value="research_rework" <?php echo $workflowFilter === 'research_rework' ? 'selected' : ''; ?>>Research Rework</option>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Lead Status</label>
          <select class="form-select form-select-sm" name="status">
            <option value="">All</option>
            <?php foreach ($statusList as $st): ?>
              <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2 d-flex gap-2">
          <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
          <a class="btn btn-outline-secondary btn-sm w-100" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/research"><i class="bi bi-x me-1"></i>Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card hr-card">
    <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
      <span><i class="bi bi-journal-text me-1"></i>Research Records</span>
      <span class="badge bg-secondary"><?php echo number_format($total); ?></span>
    </div>
    <div class="card-body">
      <?php if (empty($rows)): ?>
        <div class="text-muted">No research records assigned.</div>
      <?php else: ?>
        <div class="accordion" id="researchDbmsAcc">
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $cid = (int)($r['id'] ?? 0);
              $nm = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
              $company = (string)($r['company_name'] ?? '');
              $title = (string)($r['job_title'] ?? '');
              $email = (string)($r['email'] ?? '');
              $phone = (string)($r['direct_number'] ?? '');
              $intent = (string)($r['intent_topics'] ?? '');
              $agentSt = (string)($r['agent_status'] ?? 'Pending');
              $workflowQueue = (string)($r['workflow_queue'] ?? '');
              $loc = trim((string)($r['city'] ?? '') . ($r['state'] ? ', ' . $r['state'] : '') . ($r['country'] ? ', ' . $r['country'] : ''));
            ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="rh<?php echo $cid; ?>">
                <button class="accordion-button <?php echo $i === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#rc<?php echo $cid; ?>" aria-expanded="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                  <div class="w-100 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                      <div class="fw-semibold"><?php echo htmlspecialchars($nm !== '' ? $nm : '—'); ?> <span class="text-muted small">· <?php echo htmlspecialchars($company !== '' ? $company : '—'); ?></span></div>
                      <div class="small text-muted"><?php echo htmlspecialchars($title !== '' ? $title : 'No title'); ?><?php echo $loc !== '' ? (' · ' . htmlspecialchars($loc)) : ''; ?></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                      <span class="badge <?php echo $workflowQueue === 'research_rework' ? 'bg-danger' : 'bg-warning text-dark'; ?>"><?php echo htmlspecialchars(dbmsWorkflowLabel($workflowQueue)); ?></span>
                      <span class="badge bg-secondary"><?php echo htmlspecialchars($agentSt); ?></span>
                      <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/view?id=<?php echo $cid; ?>"><i class="bi bi-eye me-1"></i>View</a>
                      <span class="badge bg-light text-dark border font-monospace">#<?php echo $cid; ?></span>
                    </div>
                  </div>
                </button>
              </h2>
              <div id="rc<?php echo $cid; ?>" class="accordion-collapse collapse <?php echo $i === 0 ? 'show' : ''; ?>" data-bs-parent="#researchDbmsAcc">
                <div class="accordion-body">
                  <div class="row g-2">
                    <div class="col-md-3"><div class="small text-muted">Email</div><div class="font-monospace"><?php echo htmlspecialchars($email !== '' ? $email : '—'); ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">Phone</div><div class="font-monospace"><?php echo htmlspecialchars($phone !== '' ? $phone : '—'); ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">LinkedIn</div><?php $li = (string)($r['linkedin_url'] ?? ''); ?><?php if ($li !== ''): ?><a href="<?php echo htmlspecialchars($li); ?>" target="_blank" rel="noopener" class="small">Open</a><?php else: ?><div class="text-muted">—</div><?php endif; ?></div>
                    <div class="col-md-3"><div class="small text-muted">Revenue Link</div><?php $rl = (string)($r['revenue_link'] ?? ''); ?><?php if ($rl !== ''): ?><a href="<?php echo htmlspecialchars($rl); ?>" target="_blank" rel="noopener" class="small">Open</a><?php else: ?><div class="text-muted">—</div><?php endif; ?></div>
                  </div>

                  <div class="row g-3 mt-2">
                    <div class="col-12">
                      <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-pencil-square me-1"></i>Research Enrichment</div>
                        <div class="card-body">
                          <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="contact_id" value="<?php echo $cid; ?>">
                            <div class="col-md-3">
                              <label class="form-label small text-muted">First Name</label>
                              <input class="form-control form-control-sm" name="first_name" value="<?php echo htmlspecialchars((string)($r['first_name'] ?? '')); ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label small text-muted">Last Name</label>
                              <input class="form-control form-control-sm" name="last_name" value="<?php echo htmlspecialchars((string)($r['last_name'] ?? '')); ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label small text-muted">Email</label>
                              <input class="form-control form-control-sm" name="email" value="<?php echo htmlspecialchars((string)($r['email'] ?? '')); ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label small text-muted">Direct Number</label>
                              <input class="form-control form-control-sm" name="direct_number" value="<?php echo htmlspecialchars((string)($r['direct_number'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                              <label class="form-label small text-muted">Company Name</label>
                              <input class="form-control form-control-sm" name="company_name" value="<?php echo htmlspecialchars((string)($r['company_name'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                              <label class="form-label small text-muted">Job Title</label>
                              <input class="form-control form-control-sm" name="job_title" value="<?php echo htmlspecialchars((string)($r['job_title'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                              <label class="form-label small text-muted">Industry</label>
                              <input class="form-control form-control-sm" name="industry" value="<?php echo htmlspecialchars((string)($r['industry'] ?? '')); ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label small text-muted">Department</label>
                              <input class="form-control form-control-sm" name="department" value="<?php echo htmlspecialchars((string)($r['department'] ?? '')); ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label small text-muted">Seniority</label>
                              <input class="form-control form-control-sm" name="seniority" value="<?php echo htmlspecialchars((string)($r['seniority'] ?? '')); ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label small text-muted">Prospect Country</label>
                              <input class="form-control form-control-sm" name="prospect_country" value="<?php echo htmlspecialchars((string)($r['prospect_country'] ?? '')); ?>">
                            </div>
                            <div class="col-md-3">
                              <label class="form-label small text-muted">Website</label>
                              <input class="form-control form-control-sm" name="website" value="<?php echo htmlspecialchars((string)($r['website'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                              <label class="form-label small text-muted">LinkedIn URL</label>
                              <input class="form-control form-control-sm" name="linkedin_url" value="<?php echo htmlspecialchars((string)($r['linkedin_url'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                              <label class="form-label small text-muted">Revenue Link</label>
                              <input class="form-control form-control-sm" name="revenue_link" value="<?php echo htmlspecialchars((string)($r['revenue_link'] ?? '')); ?>">
                            </div>
                            <div class="col-md-2">
                              <label class="form-label small text-muted">Employees</label>
                              <input class="form-control form-control-sm" name="employee_count" value="<?php echo htmlspecialchars((string)($r['employee_count'] ?? '')); ?>">
                            </div>
                            <div class="col-md-2">
                              <label class="form-label small text-muted">Revenue</label>
                              <input class="form-control form-control-sm" name="revenue" value="<?php echo htmlspecialchars((string)($r['revenue'] ?? '')); ?>">
                            </div>
                            <div class="col-12">
                              <label class="form-label small text-muted">Research Comment</label>
                              <textarea class="form-control form-control-sm" name="comment" rows="2" placeholder="Research notes, missing-data findings, or enrichment context"></textarea>
                            </div>
                            <div class="col-12 d-flex gap-2 flex-wrap">
                              <button class="btn btn-outline-primary btn-sm" type="submit" name="action" value="save_research"><i class="bi bi-save me-1"></i>Save Research Updates</button>
                              <button class="btn btn-success btn-sm" type="submit" name="action" value="submit_to_cdqa"><i class="bi bi-send-check me-1"></i>Submit To CDQA</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-3 mt-2">
                    <div class="col-lg-6">
                      <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-check2-circle me-1"></i>Update Lead Status</div>
                        <div class="card-body">
                          <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="contact_id" value="<?php echo $cid; ?>">
                            <div class="col-12">
                              <label class="form-label small text-muted">Status</label>
                              <select class="form-select form-select-sm" name="agent_status">
                                <?php foreach ($statusList as $st): ?>
                                  <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $agentSt === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                            <div class="col-12">
                              <label class="form-label small text-muted">Comment</label>
                              <textarea class="form-control form-control-sm" name="comment" rows="2" placeholder="Add comment (required for Invalid/Wrong/Left/Duplicate)"></textarea>
                            </div>
                            <div class="col-12">
                              <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-save me-1"></i>Save</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>

                    <div class="col-lg-6">
                      <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-tags me-1"></i>Intent Topics</div>
                        <div class="card-body">
                          <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_intent">
                            <input type="hidden" name="contact_id" value="<?php echo $cid; ?>">
                            <div class="col-12">
                              <label class="form-label small text-muted">Topics (comma separated)</label>
                              <textarea class="form-control form-control-sm" name="intent_topics" rows="2" placeholder="CRM, ERP, Cybersecurity"><?php echo htmlspecialchars($intent); ?></textarea>
                              <div class="form-text">New topics are merged and history is preserved.</div>
                            </div>
                            <div class="col-12">
                              <label class="form-label small text-muted">Comment (optional)</label>
                              <textarea class="form-control form-control-sm" name="comment" rows="2" placeholder="Context / research notes..."></textarea>
                            </div>
                            <div class="col-12">
                              <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-save me-1"></i>Update Topics</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php $pages = (int)ceil($total / $pageSize); $qs = $_GET; ?>
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

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
