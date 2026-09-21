<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$user = getCurrentUser() ?: [];
$role = normalizeRole((string)($user['role'] ?? ''));
$permCfg = getAccessRolePermissionsConfig();
$canResearch = ($role === 'admin') || roleHasPermission($role, 'dbms.research', $permCfg);
$canCdqa = ($role === 'admin') || roleHasPermission($role, 'dbms.cdqa', $permCfg);
$canAssignment = ($role === 'admin') || roleHasPermission($role, 'dbms.assignment', $permCfg);

if (!$canAssignment && $canResearch && !$canCdqa) {
    header('Location: ' . appBasePath() . '/modules/dbms/research');
    exit;
}
if (!$canAssignment && $canCdqa && !$canResearch) {
    header('Location: ' . appBasePath() . '/modules/dbms/cdqa');
    exit;
}

requirePermissionOrRole('dbms.my', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$bindStmt = function(mysqli_stmt $stmt, string $types, array &$params): void {
    $args = [$types];
    foreach ($params as $i => &$p) { $args[] = &$p; }
    call_user_func_array([$stmt, 'bind_param'], $args);
};

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
$commentRequired = ['Invalid Data','Wrong Contact','Left Company','Duplicate Contact'];

$sanitize = function($v, int $maxLen = 2000): string {
    $v = (string)$v;
    $v = str_replace(["\0", "\r"], ['', "\n"], $v);
    $v = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $v);
    $v = trim($v);
    $v = preg_replace("/[ \\t]+/", ' ', $v);
    $v = preg_replace("/\\n{3,}/", "\n\n", $v);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) throw new RuntimeException('Invalid request token.');

        $action = (string)($_POST['action'] ?? '');
        $contactId = (int)($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) throw new RuntimeException('Invalid record.');

        $stmtOwn = $conn->prepare("SELECT * FROM dbms_contacts WHERE id = ? AND assigned_to_user_id = ? LIMIT 1");
        if (!$stmtOwn) throw new RuntimeException('Database error.');
        $stmtOwn->bind_param('ii', $contactId, $userId);
        $stmtOwn->execute();
        $contact = $stmtOwn->get_result()->fetch_assoc() ?: null;
        $stmtOwn->close();
        if (!$contact) throw new RuntimeException('Record not found or not assigned to you.');

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';

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

            if ($comment !== '') {
                $stmtC = $conn->prepare("INSERT INTO dbms_contact_comments (contact_id, user_id, comment) VALUES (?,?,?)");
                if ($stmtC) {
                    $stmtC->bind_param('iis', $contactId, $userId, $comment);
                    $stmtC->execute();
                    $stmtC->close();
                }
            }

            $stmtL = $conn->prepare("INSERT INTO dbms_audit_logs (entity_type, entity_id, action, field_name, old_value, new_value, remarks, user_id, module_source, ip_address, user_agent) VALUES ('contact', ?, 'Status Change', 'agent_status', ?, ?, ?, ?, 'dbms.my', ?, ?)");
            if ($stmtL) {
                $remarks = $comment !== '' ? $comment : null;
                $stmtL->bind_param('isssiss', $contactId, $oldStatus, $newStatus, $remarks, $userId, $ip, $ua);
                $stmtL->execute();
                $stmtL->close();
            }

            $message = 'Status updated.';
            $messageType = 'success';
        }

        if ($action === 'update_intent') {
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
                $stmtU = $conn->prepare("UPDATE dbms_contacts SET intent_topics = ?, last_activity_at = NOW(), updated_at = NOW() WHERE id = ? AND assigned_to_user_id = ?");
                if (!$stmtU) throw new RuntimeException('Database error.');
                $stmtU->bind_param('sii', $mergedStr, $contactId, $userId);
                $stmtU->execute();
                $stmtU->close();

                $stmtH = $conn->prepare("INSERT INTO dbms_intent_topic_history (contact_id, prev_topics, new_topics, updated_by) VALUES (?,?,?,?)");
                if ($stmtH) {
                    $stmtH->bind_param('issi', $contactId, $prev, $mergedStr, $userId);
                    $stmtH->execute();
                    $stmtH->close();
                }

                $stmtL = $conn->prepare("INSERT INTO dbms_audit_logs (entity_type, entity_id, action, field_name, old_value, new_value, remarks, user_id, module_source, ip_address, user_agent) VALUES ('contact', ?, 'Intent Update', 'intent_topics', ?, ?, ?, ?, 'dbms.my', ?, ?)");
                if ($stmtL) {
                    $remarks = $comment !== '' ? $comment : null;
                    $stmtL->bind_param('isssiss', $contactId, $prev, $mergedStr, $remarks, $userId, $ip, $ua);
                    $stmtL->execute();
                    $stmtL->close();
                }
            }

            if ($comment !== '') {
                $stmtC = $conn->prepare("INSERT INTO dbms_contact_comments (contact_id, user_id, comment) VALUES (?,?,?)");
                if ($stmtC) {
                    $stmtC->bind_param('iis', $contactId, $userId, $comment);
                    $stmtC->execute();
                    $stmtC->close();
                }
            }

            $message = 'Intent topics updated.';
            $messageType = 'success';
        }

        if ($action === 'update_append') {
            $stage = (string)($contact['stage'] ?? '');
            if ($stage !== 'append') throw new RuntimeException('This record is not in Append stage.');

            $fields = [
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
            $typesU = '';
            foreach ($fields as $col => $maxLen) {
                if (!array_key_exists($col, $_POST)) continue;
                if (function_exists('dbmsContactsHasColumn') && !dbmsContactsHasColumn($col)) continue;
                $val = $sanitize((string)($_POST[$col] ?? ''), (int)$maxLen);
                $updates[] = "`$col` = ?";
                $vals[] = $val;
                $typesU .= 's';
            }
            if (empty($updates)) throw new RuntimeException('No fields to update.');

            $updates[] = "last_activity_at = NOW()";
            $updates[] = "updated_at = NOW()";

            $sqlU = "UPDATE dbms_contacts SET " . implode(', ', $updates) . " WHERE id = ? AND assigned_to_user_id = ?";
            $stmtU = $conn->prepare($sqlU);
            if (!$stmtU) throw new RuntimeException('Database error.');
            $vals[] = $contactId;
            $vals[] = $userId;
            $typesU .= 'ii';
            $bindStmt($stmtU, $typesU, $vals);
            $stmtU->execute();
            $stmtU->close();

            $release = ((string)($_POST['release_to_pool'] ?? '0') === '1');
            if ($release) {
                $stmtR = $conn->prepare("SELECT * FROM dbms_contacts WHERE id = ? AND assigned_to_user_id = ? LIMIT 1");
                if (!$stmtR) throw new RuntimeException('Database error.');
                $stmtR->bind_param('ii', $contactId, $userId);
                $stmtR->execute();
                $after = $stmtR->get_result()->fetch_assoc() ?: null;
                $stmtR->close();
                if (!$after) throw new RuntimeException('Record not found.');

                $hasCompany = trim((string)($after['company_name'] ?? '')) !== '';
                $hasCountry = trim((string)($after['prospect_country'] ?? '')) !== '';
                $hasIndustry = trim((string)($after['industry'] ?? '')) !== '';
                $hasTitle = trim((string)($after['job_title'] ?? '')) !== '';
                $hasId = trim((string)($after['email'] ?? '')) !== '' || trim((string)($after['linkedin_url'] ?? '')) !== '' || trim((string)($after['website'] ?? '')) !== '' || trim((string)($after['direct_number'] ?? '')) !== '';
                if (!($hasCompany && $hasCountry && $hasIndustry && $hasTitle && $hasId)) {
                    throw new RuntimeException('Cannot release to Ready Pool. Fill Company, Prospect Country, Industry, Job Title and at least one identifier (Email/LinkedIn/Website/Direct Number).');
                }

                $stmtRel = $conn->prepare("
                    UPDATE dbms_contacts
                    SET stage = 'ready',
                        review_status = 'ready',
                        assigned_to_user_id = NULL,
                        assigned_department = NULL,
                        assigned_at = NULL,
                        updated_at = NOW()
                    WHERE id = ? AND assigned_to_user_id = ? AND stage = 'append'
                ");
                if (!$stmtRel) throw new RuntimeException('Database error.');
                $stmtRel->bind_param('ii', $contactId, $userId);
                $stmtRel->execute();
                $stmtRel->close();

                $stmtL = $conn->prepare("INSERT INTO dbms_audit_logs (entity_type, entity_id, action, field_name, old_value, new_value, remarks, user_id, module_source, ip_address, user_agent) VALUES ('contact', ?, 'Append Released', 'stage', 'append', 'ready', ?, ?, 'dbms.my', ?, ?)");
                if ($stmtL) {
                    $remarks = 'Released to Ready Pool';
                    $stmtL->bind_param('isiss', $contactId, $remarks, $userId, $ip, $ua);
                    $stmtL->execute();
                    $stmtL->close();
                }

                $message = 'Append updated and released to Ready Pool.';
            } else {
                $message = 'Append updated.';
            }
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$where = "WHERE c.assigned_to_user_id = ?";
$params = [$userId];
$types = 'i';
if ($q !== '') {
    $where .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company_name LIKE ? OR c.job_title LIKE ?";
    $qq = '%' . $q . '%';
    array_push($params, $qq, $qq, $qq, $qq, $qq);
    $types .= 'sssss';
    if (function_exists('dbmsContactsHasColumn') && dbmsContactsHasColumn('revenue_link')) {
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

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

$total = 0;
$stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_contacts c $where");
if ($stmtC) {
    $bindStmt($stmtC, $types, $params);
    $stmtC->execute();
    $total = (int)(($stmtC->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
    $stmtC->close();
}

$rows = [];
$stmt = $conn->prepare("
    SELECT c.*
    FROM dbms_contacts c
    $where
    ORDER BY c.assigned_at DESC, c.id DESC
    LIMIT $pageSize OFFSET $offset
");
if ($stmt) {
    $bindStmt($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$pageTitle = 'DBMS My Records';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="h3 mb-1">DBMS</div>
      <div class="text-muted small">My Assigned Records</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge bg-primary">DBMS</span>
      <span class="badge bg-light text-dark border">My Records</span>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="card hr-card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-lg-6">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name / company / email / title">
        </div>
        <div class="col-lg-4">
          <label class="form-label small text-muted">Status</label>
          <select class="form-select form-select-sm" name="status">
            <option value="">All</option>
            <?php foreach ($statusList as $st): ?>
              <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2 d-flex gap-2">
          <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
          <a class="btn btn-outline-secondary btn-sm w-100" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/my"><i class="bi bi-x me-1"></i>Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card hr-card">
    <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
      <span><i class="bi bi-inbox me-1"></i>Records</span>
      <span class="badge bg-secondary"><?php echo number_format($total); ?></span>
    </div>
    <div class="card-body">
      <?php if (empty($rows)): ?>
        <div class="text-muted">No assigned records.</div>
      <?php else: ?>
        <div class="accordion" id="myDbmsAcc">
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
              $loc = trim((string)($r['city'] ?? '') . ($r['state'] ? ', ' . $r['state'] : '') . ($r['country'] ? ', ' . $r['country'] : ''));
            ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="h<?php echo $cid; ?>">
                <button class="accordion-button <?php echo $i === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#c<?php echo $cid; ?>" aria-expanded="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                  <div class="w-100 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                      <div class="fw-semibold"><?php echo htmlspecialchars($nm !== '' ? $nm : '—'); ?> <span class="text-muted small">· <?php echo htmlspecialchars($company); ?></span></div>
                      <div class="small text-muted"><?php echo htmlspecialchars($title); ?><?php echo $loc !== '' ? (' · ' . htmlspecialchars($loc)) : ''; ?></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                      <span class="badge bg-secondary"><?php echo htmlspecialchars($agentSt); ?></span>
                      <span class="badge bg-light text-dark border font-monospace">#<?php echo $cid; ?></span>
                    </div>
                  </div>
                </button>
              </h2>
              <div id="c<?php echo $cid; ?>" class="accordion-collapse collapse <?php echo $i === 0 ? 'show' : ''; ?>" data-bs-parent="#myDbmsAcc">
                <div class="accordion-body">
                  <div class="row g-2">
                    <div class="col-md-4">
                      <div class="small text-muted">Email</div>
                      <div class="font-monospace"><?php echo htmlspecialchars($email !== '' ? $email : '—'); ?></div>
                    </div>
                    <div class="col-md-4">
                      <div class="small text-muted">Phone</div>
                      <div class="font-monospace"><?php echo htmlspecialchars($phone !== '' ? $phone : '—'); ?></div>
                    </div>
                    <div class="col-md-4">
                      <div class="small text-muted">LinkedIn</div>
                      <?php $li = (string)($r['linkedin_url'] ?? ''); ?>
                      <?php if ($li !== ''): ?>
                        <a href="<?php echo htmlspecialchars($li); ?>" target="_blank" rel="noopener" class="small">Open</a>
                      <?php else: ?>
                        <div class="text-muted">—</div>
                      <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                      <div class="small text-muted">Revenue Link</div>
                      <?php $rl = (string)($r['revenue_link'] ?? ''); ?>
                      <?php if ($rl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($rl); ?>" target="_blank" rel="noopener" class="small">Open</a>
                      <?php else: ?>
                        <div class="text-muted">—</div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <?php if ((string)($r['stage'] ?? '') === 'append'): ?>
                    <div class="row g-3 mt-2">
                      <div class="col-12">
                        <div class="card hr-card">
                          <div class="card-header bg-light fw-semibold"><i class="bi bi-pencil-square me-1"></i>Append Enrichment</div>
                          <div class="card-body">
                            <form method="post" class="row g-2">
                              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                              <input type="hidden" name="action" value="update_append">
                              <input type="hidden" name="contact_id" value="<?php echo $cid; ?>">
                              <div class="col-md-6">
                                <label class="form-label small text-muted">Job Title</label>
                                <input class="form-control form-control-sm" name="job_title" value="<?php echo htmlspecialchars((string)($r['job_title'] ?? '')); ?>">
                              </div>
                              <div class="col-md-6">
                                <label class="form-label small text-muted">Department</label>
                                <input class="form-control form-control-sm" name="department" value="<?php echo htmlspecialchars((string)($r['department'] ?? '')); ?>">
                              </div>
                              <div class="col-md-4">
                                <label class="form-label small text-muted">Seniority</label>
                                <input class="form-control form-control-sm" name="seniority" value="<?php echo htmlspecialchars((string)($r['seniority'] ?? '')); ?>">
                              </div>
                              <div class="col-md-4">
                                <label class="form-label small text-muted">Industry</label>
                                <input class="form-control form-control-sm" name="industry" value="<?php echo htmlspecialchars((string)($r['industry'] ?? '')); ?>">
                              </div>
                              <div class="col-md-4">
                                <label class="form-label small text-muted">Prospect Country</label>
                                <input class="form-control form-control-sm" name="prospect_country" value="<?php echo htmlspecialchars((string)($r['prospect_country'] ?? '')); ?>">
                              </div>
                              <div class="col-md-6">
                                <label class="form-label small text-muted">Website</label>
                                <input class="form-control form-control-sm" name="website" value="<?php echo htmlspecialchars((string)($r['website'] ?? '')); ?>">
                              </div>
                              <div class="col-md-6">
                                <label class="form-label small text-muted">LinkedIn URL</label>
                                <input class="form-control form-control-sm" name="linkedin_url" value="<?php echo htmlspecialchars((string)($r['linkedin_url'] ?? '')); ?>">
                              </div>
                              <div class="col-md-6">
                                <label class="form-label small text-muted">Revenue Link</label>
                                <input class="form-control form-control-sm" name="revenue_link" value="<?php echo htmlspecialchars((string)($r['revenue_link'] ?? '')); ?>">
                              </div>
                              <div class="col-md-3">
                                <label class="form-label small text-muted">Employees</label>
                                <input class="form-control form-control-sm" name="employee_count" value="<?php echo htmlspecialchars((string)($r['employee_count'] ?? '')); ?>">
                              </div>
                              <div class="col-md-3">
                                <label class="form-label small text-muted">Revenue Bracket</label>
                                <input class="form-control form-control-sm" name="revenue_bracket" value="<?php echo htmlspecialchars((string)($r['revenue_bracket'] ?? '')); ?>">
                              </div>
                              <div class="col-12">
                                <div class="form-check">
                                  <input class="form-check-input" type="checkbox" name="release_to_pool" value="1" id="rel<?php echo $cid; ?>">
                                  <label class="form-check-label small" for="rel<?php echo $cid; ?>">Release to Ready Pool after saving</label>
                                </div>
                              </div>
                              <div class="col-12">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save me-1"></i>Save Append Updates</button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>

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
                              <textarea class="form-control form-control-sm" name="comment" rows="2" placeholder="Context / call notes..."></textarea>
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

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
