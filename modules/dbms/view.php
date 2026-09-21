<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.view', ['admin']);
ensureDatabaseSchema();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$role = normalizeRole((string)($user['role'] ?? ''));
$permCfg = getAccessRolePermissionsConfig();
$conn = getDbConnection();
$contactId = max(1, (int)($_GET['id'] ?? 0));
$workflowExpr = dbmsWorkflowStatusSql('c');

if ($contactId <= 0) {
    http_response_code(400);
    exit('Invalid DBMS record.');
}

$canViewAny = $role === 'admin'
    || roleHasPermission($role, 'dbms.access', $permCfg)
    || roleHasPermission($role, 'dbms.explorer', $permCfg)
    || roleHasPermission($role, 'dbms.assignment', $permCfg)
    || roleHasPermission($role, 'dbms.reports', $permCfg)
    || roleHasPermission($role, 'dbms.upload', $permCfg);

$contactSql = "
    SELECT c.*,
           {$workflowExpr} AS workflow_queue,
           bo.full_name AS owner_name,
           ba.full_name AS assigned_to_name,
           bc.full_name AS created_by_name,
           b.id AS batch_id_ref,
           b.original_filename AS batch_filename,
           b.uploaded_at AS batch_uploaded_at,
           bu.full_name AS batch_uploaded_by_name
    FROM dbms_contacts c
    LEFT JOIN users bo ON bo.id = c.owner_user_id
    LEFT JOIN users ba ON ba.id = c.assigned_to_user_id
    LEFT JOIN users bc ON bc.id = c.created_by
    LEFT JOIN dbms_batches b ON b.id = c.batch_id
    LEFT JOIN users bu ON bu.id = b.uploaded_by
    WHERE c.id = ?
    LIMIT 1
";
$stmtContact = $conn->prepare($contactSql);
$contact = null;
if ($stmtContact) {
    $stmtContact->bind_param('i', $contactId);
    $stmtContact->execute();
    $contact = $stmtContact->get_result()->fetch_assoc() ?: null;
    $stmtContact->close();
}
if (!$contact) {
    http_response_code(404);
    exit('DBMS record not found.');
}

if (!$canViewAny) {
    $allowed = false;
    if ((int)($contact['assigned_to_user_id'] ?? 0) === $userId) {
        $allowed = true;
    }
    if (!$allowed) {
        $checks = [];
        $checks[] = $conn->prepare("SELECT 1 FROM dbms_assignments WHERE contact_id = ? AND assigned_to_user_id = ? LIMIT 1");
        $checks[] = $conn->prepare("SELECT 1 FROM dbms_contact_comments WHERE contact_id = ? AND user_id = ? LIMIT 1");
        $checks[] = $conn->prepare("SELECT 1 FROM dbms_intent_topic_history WHERE contact_id = ? AND updated_by = ? LIMIT 1");
        $checks[] = $conn->prepare("SELECT 1 FROM dbms_audit_logs WHERE entity_type = 'contact' AND entity_id = ? AND user_id = ? LIMIT 1");
        foreach ($checks as $stmtCheck) {
            if (!$stmtCheck) continue;
            $stmtCheck->bind_param('ii', $contactId, $userId);
            $stmtCheck->execute();
            $exists = $stmtCheck->get_result()->fetch_row();
            $stmtCheck->close();
            if ($exists) {
                $allowed = true;
                break;
            }
        }
    }
    if (!$allowed) {
        http_response_code(403);
        exit('You do not have access to this DBMS record.');
    }
}

$hasAssignmentType = dbmsAssignmentsHasColumn('assignment_type');
$hasQueueName = dbmsAssignmentsHasColumn('queue_name');
$hasFromWorkflowStatus = dbmsAssignmentsHasColumn('from_workflow_status');
$hasToWorkflowStatus = dbmsAssignmentsHasColumn('to_workflow_status');
$hasFromUserId = dbmsAssignmentsHasColumn('from_user_id');
$hasIsReassignment = dbmsAssignmentsHasColumn('is_reassignment');

$assignmentCols = [
    'a.id',
    'a.contact_id',
    'a.department',
    'a.assigned_to_user_id',
    'a.assigned_by',
    'a.assigned_at',
    'a.note',
    'tu.full_name AS assigned_to_name',
    'bu.full_name AS assigned_by_name',
];
if ($hasAssignmentType) $assignmentCols[] = 'a.assignment_type';
if ($hasQueueName) $assignmentCols[] = 'a.queue_name';
if ($hasFromWorkflowStatus) $assignmentCols[] = 'a.from_workflow_status';
if ($hasToWorkflowStatus) $assignmentCols[] = 'a.to_workflow_status';
if ($hasFromUserId) $assignmentCols[] = 'a.from_user_id';
if ($hasFromUserId) $assignmentCols[] = 'fu.full_name AS from_user_name';
if ($hasIsReassignment) $assignmentCols[] = 'a.is_reassignment';

$assignmentSql = "
    SELECT " . implode(",\n           ", $assignmentCols) . "
    FROM dbms_assignments a
    LEFT JOIN users tu ON tu.id = a.assigned_to_user_id
    LEFT JOIN users bu ON bu.id = a.assigned_by
    " . ($hasFromUserId ? "LEFT JOIN users fu ON fu.id = a.from_user_id" : "") . "
    WHERE a.contact_id = ?
    ORDER BY a.assigned_at DESC, a.id DESC
";
$assignments = [];
$stmtAssignments = $conn->prepare($assignmentSql);
if ($stmtAssignments) {
    $stmtAssignments->bind_param('i', $contactId);
    $stmtAssignments->execute();
    $assignments = $stmtAssignments->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtAssignments->close();
}

$comments = [];
$stmtComments = $conn->prepare("
    SELECT c.*, u.full_name AS user_name
    FROM dbms_contact_comments c
    LEFT JOIN users u ON u.id = c.user_id
    WHERE c.contact_id = ?
    ORDER BY c.created_at DESC, c.id DESC
");
if ($stmtComments) {
    $stmtComments->bind_param('i', $contactId);
    $stmtComments->execute();
    $comments = $stmtComments->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtComments->close();
}

$intentHistory = [];
$stmtIntent = $conn->prepare("
    SELECT h.*, u.full_name AS updated_by_name
    FROM dbms_intent_topic_history h
    LEFT JOIN users u ON u.id = h.updated_by
    WHERE h.contact_id = ?
    ORDER BY h.updated_at DESC, h.id DESC
");
if ($stmtIntent) {
    $stmtIntent->bind_param('i', $contactId);
    $stmtIntent->execute();
    $intentHistory = $stmtIntent->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtIntent->close();
}

$auditLogs = [];
$stmtAudit = $conn->prepare("
    SELECT l.*, u.full_name AS user_name
    FROM dbms_audit_logs l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.entity_type = 'contact' AND l.entity_id = ?
    ORDER BY l.created_at DESC, l.id DESC
");
if ($stmtAudit) {
    $stmtAudit->bind_param('i', $contactId);
    $stmtAudit->execute();
    $auditLogs = $stmtAudit->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtAudit->close();
}

$batchRows = [];
$stmtBatchRows = $conn->prepare("
    SELECT r.*
    FROM dbms_batch_rows r
    WHERE r.contact_id = ?
    ORDER BY r.id DESC
    LIMIT 20
");
if ($stmtBatchRows) {
    $stmtBatchRows->bind_param('i', $contactId);
    $stmtBatchRows->execute();
    $batchRows = $stmtBatchRows->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtBatchRows->close();
}

$timeline = [];
foreach ($assignments as $a) {
    $timeline[] = [
        'ts' => (string)($a['assigned_at'] ?? ''),
        'type' => 'Assignment',
        'title' => 'Assigned to ' . ((string)($a['assigned_to_name'] ?? '') !== '' ? (string)$a['assigned_to_name'] : ('User #' . (int)($a['assigned_to_user_id'] ?? 0))),
        'meta' => trim((string)($a['department'] ?? '')),
        'detail' => trim((string)($a['note'] ?? '')),
    ];
}
foreach ($comments as $c) {
    $timeline[] = [
        'ts' => (string)($c['created_at'] ?? ''),
        'type' => 'Comment',
        'title' => 'Comment by ' . ((string)($c['user_name'] ?? '') !== '' ? (string)$c['user_name'] : ('User #' . (int)($c['user_id'] ?? 0))),
        'meta' => '',
        'detail' => trim((string)($c['comment'] ?? '')),
    ];
}
foreach ($intentHistory as $h) {
    $timeline[] = [
        'ts' => (string)($h['updated_at'] ?? ''),
        'type' => 'Intent',
        'title' => 'Intent topics updated',
        'meta' => (string)($h['updated_by_name'] ?? ''),
        'detail' => trim((string)($h['new_topics'] ?? '')),
    ];
}
foreach ($auditLogs as $l) {
    $detailParts = [];
    $old = trim((string)($l['old_value'] ?? ''));
    $new = trim((string)($l['new_value'] ?? ''));
    if ($old !== '' || $new !== '') {
        $detailParts[] = ($old !== '' ? $old : '—') . ' -> ' . ($new !== '' ? $new : '—');
    }
    $remarks = trim((string)($l['remarks'] ?? ''));
    if ($remarks !== '') $detailParts[] = $remarks;
    $timeline[] = [
        'ts' => (string)($l['created_at'] ?? ''),
        'type' => 'Audit',
        'title' => trim((string)($l['action'] ?? 'Activity')) . ((string)($l['field_name'] ?? '') !== '' ? (' · ' . (string)$l['field_name']) : ''),
        'meta' => (string)($l['user_name'] ?? ''),
        'detail' => implode(' | ', array_filter($detailParts, fn($x) => trim((string)$x) !== '')),
    ];
}
usort($timeline, function(array $a, array $b): int {
    return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
});

$researchUpdates = array_values(array_filter($auditLogs, function(array $row): bool {
    return (string)($row['module_source'] ?? '') === 'dbms.research'
        || stripos((string)($row['action'] ?? ''), 'Research') !== false;
}));
$cdqaDecisions = array_values(array_filter($auditLogs, function(array $row): bool {
    return (string)($row['module_source'] ?? '') === 'dbms.cdqa'
        || stripos((string)($row['action'] ?? ''), 'CDQA') !== false
        || stripos((string)($row['action'] ?? ''), 'Workflow Update') !== false;
}));

$pageTitle = 'DBMS Record View';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="h3 mb-1">DBMS Record View</div>
      <div class="text-muted small">Single source of truth for DBMS record audit, workflow, history, and decisions</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge bg-primary">DBMS</span>
      <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(dbmsWorkflowLabel((string)($contact['workflow_queue'] ?? ''))); ?></span>
      <span class="badge bg-light text-dark border font-monospace">#<?php echo (int)$contactId; ?></span>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card hr-card h-100"><div class="card-body">
        <div class="text-muted small">Workflow Status</div>
        <div class="h5 mb-0"><?php echo htmlspecialchars(dbmsWorkflowLabel((string)($contact['workflow_queue'] ?? ''))); ?></div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card hr-card h-100"><div class="card-body">
        <div class="text-muted small">Assigned Department</div>
        <div class="h5 mb-0"><?php echo htmlspecialchars((string)($contact['assigned_department'] ?? '—')); ?></div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card hr-card h-100"><div class="card-body">
        <div class="text-muted small">Assigned To</div>
        <div class="h5 mb-0"><?php echo htmlspecialchars((string)($contact['assigned_to_name'] ?? '—')); ?></div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card hr-card h-100"><div class="card-body">
        <div class="text-muted small">Batch</div>
        <div class="h5 mb-0"><?php echo (int)($contact['batch_id_ref'] ?? 0) > 0 ? ('#' . (int)$contact['batch_id_ref']) : '—'; ?></div>
      </div></div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-xl-8">
      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-person-vcard me-1"></i>Contact Information</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4"><div class="small text-muted">First Name</div><div><?php echo htmlspecialchars((string)($contact['first_name'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Last Name</div><div><?php echo htmlspecialchars((string)($contact['last_name'] ?? '—')); ?></div></div>
            <?php $fullName = trim((string)($contact['name'] ?? '')) !== '' ? (string)($contact['name'] ?? '') : trim((string)($contact['first_name'] ?? '') . ' ' . (string)($contact['last_name'] ?? '')); ?>
            <div class="col-md-4"><div class="small text-muted">Full Name</div><div><?php echo htmlspecialchars($fullName !== '' ? $fullName : '—'); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Email</div><div class="font-monospace"><?php echo htmlspecialchars((string)($contact['email'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Direct Number</div><div class="font-monospace"><?php echo htmlspecialchars((string)($contact['direct_number'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Extension</div><div><?php echo htmlspecialchars((string)($contact['extension'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Job Title</div><div><?php echo htmlspecialchars((string)($contact['job_title'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Department</div><div><?php echo htmlspecialchars((string)($contact['department'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Seniority</div><div><?php echo htmlspecialchars((string)($contact['seniority'] ?? '—')); ?></div></div>
            <div class="col-md-6"><div class="small text-muted">LinkedIn</div><?php $li = trim((string)($contact['linkedin_url'] ?? '')); ?><?php if ($li !== ''): ?><a href="<?php echo htmlspecialchars($li); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($li); ?></a><?php else: ?><div>—</div><?php endif; ?></div>
            <div class="col-md-6"><div class="small text-muted">Location</div><div><?php echo htmlspecialchars(trim((string)($contact['city'] ?? '') . ((string)($contact['state'] ?? '') !== '' ? ', ' . (string)$contact['state'] : '') . ((string)($contact['country'] ?? '') !== '' ? ', ' . (string)$contact['country'] : '')) ?: '—'); ?></div></div>
          </div>
        </div>
      </div>

      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-building me-1"></i>Company Information</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4"><div class="small text-muted">Company Name</div><div><?php echo htmlspecialchars((string)($contact['company_name'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Industry</div><div><?php echo htmlspecialchars((string)($contact['industry'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Prospect Country</div><div><?php echo htmlspecialchars((string)($contact['prospect_country'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Website</div><div><?php echo htmlspecialchars((string)($contact['website'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Company Phone</div><div class="font-monospace"><?php echo htmlspecialchars((string)($contact['company_phone'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Employee Count</div><div><?php echo htmlspecialchars((string)($contact['employee_count'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Employee Bracket</div><div><?php echo htmlspecialchars((string)($contact['employee_size_bracket'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Revenue</div><div><?php echo htmlspecialchars((string)($contact['revenue'] ?? '—')); ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Revenue Bracket</div><div><?php echo htmlspecialchars((string)($contact['revenue_bracket'] ?? '—')); ?></div></div>
            <div class="col-md-6"><div class="small text-muted">Revenue Link</div><?php $rl = trim((string)($contact['revenue_link'] ?? '')); ?><?php if ($rl !== ''): ?><a href="<?php echo htmlspecialchars($rl); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($rl); ?></a><?php else: ?><div>—</div><?php endif; ?></div>
            <div class="col-md-6"><div class="small text-muted">Company Location</div><div><?php echo htmlspecialchars(trim((string)($contact['company_city'] ?? '') . ((string)($contact['company_state'] ?? '') !== '' ? ', ' . (string)$contact['company_state'] : '') . ((string)($contact['company_country'] ?? '') !== '' ? ', ' . (string)$contact['company_country'] : '')) ?: '—'); ?></div></div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card hr-card h-100">
            <div class="card-header bg-light fw-semibold"><i class="bi bi-journal-text me-1"></i>Research Information</div>
            <div class="card-body">
              <div class="small text-muted mb-1">Research Submission</div>
              <div class="mb-2"><?php echo htmlspecialchars((string)($contact['research_submitted_at'] ?? '—')); ?></div>
              <div class="small text-muted mb-1">Intent Topics</div>
              <div class="mb-2"><?php echo htmlspecialchars((string)($contact['intent_topics'] ?? '—')); ?></div>
              <div class="small text-muted mb-1">Latest Research Updates</div>
              <?php if (empty($researchUpdates)): ?>
                <div class="text-muted">No research updates logged yet.</div>
              <?php else: ?>
                <div class="list-group list-group-flush">
                  <?php foreach (array_slice($researchUpdates, 0, 5) as $item): ?>
                    <div class="list-group-item px-0">
                      <div class="small fw-semibold"><?php echo htmlspecialchars((string)($item['action'] ?? 'Research Update')); ?></div>
                      <div class="small text-muted"><?php echo htmlspecialchars((string)($item['created_at'] ?? '')); ?><?php echo (string)($item['user_name'] ?? '') !== '' ? (' · ' . htmlspecialchars((string)$item['user_name'])) : ''; ?></div>
                      <div class="small"><?php echo htmlspecialchars((string)($item['remarks'] ?? '—')); ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card hr-card h-100">
            <div class="card-header bg-light fw-semibold"><i class="bi bi-shield-check me-1"></i>CDQA Information</div>
            <div class="card-body">
              <div class="small text-muted mb-1">CDQA Decision</div>
              <div class="mb-2"><?php echo htmlspecialchars((string)($contact['cdqa_decision'] ?? '—')); ?></div>
              <div class="small text-muted mb-1">Decision Time</div>
              <div class="mb-2"><?php echo htmlspecialchars((string)($contact['cdqa_decision_at'] ?? '—')); ?></div>
              <div class="small text-muted mb-1">Rework Count</div>
              <div class="mb-2"><?php echo htmlspecialchars((string)($contact['rework_count'] ?? '0')); ?></div>
              <div class="small text-muted mb-1">CDQA Comment</div>
              <div class="mb-2"><?php echo nl2br(htmlspecialchars((string)($contact['cdqa_comment'] ?? '—'))); ?></div>
              <div class="small text-muted mb-1">Recent CDQA Decisions</div>
              <?php if (empty($cdqaDecisions)): ?>
                <div class="text-muted">No CDQA activity logged yet.</div>
              <?php else: ?>
                <div class="list-group list-group-flush">
                  <?php foreach (array_slice($cdqaDecisions, 0, 5) as $item): ?>
                    <div class="list-group-item px-0">
                      <div class="small fw-semibold"><?php echo htmlspecialchars((string)($item['action'] ?? 'CDQA')); ?></div>
                      <div class="small text-muted"><?php echo htmlspecialchars((string)($item['created_at'] ?? '')); ?><?php echo (string)($item['user_name'] ?? '') !== '' ? (' · ' . htmlspecialchars((string)$item['user_name'])) : ''; ?></div>
                      <div class="small"><?php echo htmlspecialchars((string)($item['remarks'] ?? '—')); ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-activity me-1"></i>Activity Timeline</div>
        <div class="card-body">
          <?php if (empty($timeline)): ?>
            <div class="text-muted">No activity recorded yet.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach (array_slice($timeline, 0, 50) as $item): ?>
                <div class="list-group-item px-0">
                  <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                      <div class="fw-semibold"><?php echo htmlspecialchars((string)($item['title'] ?? 'Activity')); ?></div>
                      <div class="small text-muted"><?php echo htmlspecialchars((string)($item['type'] ?? '')); ?><?php echo trim((string)($item['meta'] ?? '')) !== '' ? (' · ' . htmlspecialchars((string)$item['meta'])) : ''; ?></div>
                    </div>
                    <div class="small text-muted font-monospace"><?php echo htmlspecialchars((string)($item['ts'] ?? '')); ?></div>
                  </div>
                  <?php if (trim((string)($item['detail'] ?? '')) !== ''): ?>
                    <div class="small mt-1"><?php echo nl2br(htmlspecialchars((string)($item['detail'] ?? ''))); ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-info-circle me-1"></i>Record Summary</div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-12"><div class="small text-muted">Agent Status</div><div><?php echo htmlspecialchars((string)($contact['agent_status'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Review Status</div><div><?php echo htmlspecialchars((string)($contact['review_status'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Legacy Stage</div><div><?php echo htmlspecialchars((string)($contact['stage'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Owner</div><div><?php echo htmlspecialchars((string)($contact['owner_name'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Created By</div><div><?php echo htmlspecialchars((string)($contact['created_by_name'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Created At</div><div class="font-monospace"><?php echo htmlspecialchars((string)($contact['created_at'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Updated At</div><div class="font-monospace"><?php echo htmlspecialchars((string)($contact['updated_at'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Last Activity</div><div class="font-monospace"><?php echo htmlspecialchars((string)($contact['last_activity_at'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Batch File</div><div><?php echo htmlspecialchars((string)($contact['batch_filename'] ?? '—')); ?></div></div>
            <div class="col-12"><div class="small text-muted">Batch Uploaded By</div><div><?php echo htmlspecialchars((string)($contact['batch_uploaded_by_name'] ?? '—')); ?></div></div>
          </div>
        </div>
      </div>

      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-person-check me-1"></i>Assignment History</div>
        <div class="card-body">
          <?php if (empty($assignments)): ?>
            <div class="text-muted">No assignment history.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($assignments as $a): ?>
                <div class="list-group-item px-0">
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($a['assigned_to_name'] ?? 'User #' . (int)($a['assigned_to_user_id'] ?? 0))); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($a['department'] ?? '')); ?> · <?php echo htmlspecialchars((string)($a['assigned_at'] ?? '')); ?></div>
                  <div class="small text-muted">By <?php echo htmlspecialchars((string)($a['assigned_by_name'] ?? '—')); ?></div>
                  <?php if (trim((string)($a['from_workflow_status'] ?? '')) !== '' || trim((string)($a['to_workflow_status'] ?? '')) !== ''): ?>
                    <div class="small"><?php echo htmlspecialchars(dbmsWorkflowLabel((string)($a['from_workflow_status'] ?? ''))); ?> -> <?php echo htmlspecialchars(dbmsWorkflowLabel((string)($a['to_workflow_status'] ?? ''))); ?></div>
                  <?php endif; ?>
                  <?php if (trim((string)($a['note'] ?? '')) !== ''): ?>
                    <div class="small"><?php echo nl2br(htmlspecialchars((string)($a['note'] ?? ''))); ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-chat-left-text me-1"></i>Comment History</div>
        <div class="card-body">
          <?php if (empty($comments)): ?>
            <div class="text-muted">No comments logged.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($comments as $c): ?>
                <div class="list-group-item px-0">
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($c['user_name'] ?? 'User #' . (int)($c['user_id'] ?? 0))); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($c['created_at'] ?? '')); ?></div>
                  <div class="small"><?php echo nl2br(htmlspecialchars((string)($c['comment'] ?? ''))); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-tags me-1"></i>Intent History</div>
        <div class="card-body">
          <?php if (empty($intentHistory)): ?>
            <div class="text-muted">No intent history logged.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($intentHistory as $h): ?>
                <div class="list-group-item px-0">
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($h['updated_by_name'] ?? 'User #' . (int)($h['updated_by'] ?? 0))); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($h['updated_at'] ?? '')); ?></div>
                  <div class="small"><span class="text-muted">Previous:</span> <?php echo htmlspecialchars((string)($h['prev_topics'] ?? '—')); ?></div>
                  <div class="small"><span class="text-muted">New:</span> <?php echo htmlspecialchars((string)($h['new_topics'] ?? '—')); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card hr-card mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-inbox me-1"></i>Batch Trace</div>
        <div class="card-body">
          <?php if (empty($batchRows)): ?>
            <div class="text-muted">No batch trace rows found for this record.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($batchRows as $row): ?>
                <div class="list-group-item px-0">
                  <div class="fw-semibold">Batch #<?php echo (int)($row['batch_id'] ?? 0); ?> · Row <?php echo (int)($row['row_num'] ?? 0); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($row['status'] ?? '')); ?> · <?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></div>
                  <div class="small"><?php echo htmlspecialchars((string)($row['reason'] ?? '—')); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card hr-card">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-shield-lock me-1"></i>Audit Logs</div>
        <div class="card-body">
          <?php if (empty($auditLogs)): ?>
            <div class="text-muted">No audit logs found.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach (array_slice($auditLogs, 0, 30) as $log): ?>
                <div class="list-group-item px-0">
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($log['action'] ?? 'Activity')); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($log['created_at'] ?? '')); ?><?php echo (string)($log['user_name'] ?? '') !== '' ? (' · ' . htmlspecialchars((string)$log['user_name'])) : ''; ?></div>
                  <div class="small"><?php echo htmlspecialchars(trim((string)($log['field_name'] ?? '')) !== '' ? ((string)$log['field_name'] . ': ') : ''); ?><?php echo htmlspecialchars(trim((string)($log['old_value'] ?? '')) !== '' || trim((string)($log['new_value'] ?? '')) !== '' ? (((string)($log['old_value'] ?? '—')) . ' -> ' . ((string)($log['new_value'] ?? '—'))) : ''); ?></div>
                  <?php if (trim((string)($log['remarks'] ?? '')) !== ''): ?><div class="small"><?php echo nl2br(htmlspecialchars((string)($log['remarks'] ?? ''))); ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
