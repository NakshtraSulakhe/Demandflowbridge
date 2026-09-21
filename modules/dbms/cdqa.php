<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.cdqa', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();
$workflowExpr = dbmsWorkflowStatusSql('c');

$message = '';
$messageType = 'success';

$sanitize = function($v, int $maxLen = 4000): string {
    $v = (string)$v;
    $v = str_replace(["\0", "\r"], ['', "\n"], $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    $v = trim($v);
    $v = preg_replace("/[ \t]+/", ' ', $v);
    $v = preg_replace("/\n{3,}/", "\n\n", $v);
    if ($maxLen > 0 && strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
    return $v;
};

$loadAssignedCdqaRecord = function(int $contactId) use ($conn, $userId, $workflowExpr): ?array {
    $sql = "
        SELECT c.*, {$workflowExpr} AS workflow_queue
        FROM dbms_contacts c
        WHERE c.id = ?
          AND c.assigned_to_user_id = ?
          AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'cdqa'
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
    if (!in_array($workflow, ['cdqa_assigned', 'pending_cdqa'], true)) return null;
    return $row;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) throw new RuntimeException('Invalid request token.');

        $action = (string)($_POST['action'] ?? '');
        $contactId = (int)($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) throw new RuntimeException('Invalid record.');

        $contact = $loadAssignedCdqaRecord($contactId);
        if (!$contact) throw new RuntimeException('Record not found or not assigned to you.');

        $comment = $sanitize((string)($_POST['comment'] ?? ''), 4000);

        if ($action === 'approve') {
            dbmsTransitionContactWorkflow(
                $contactId,
                'approved',
                $userId,
                'dbms.cdqa',
                [
                    'expected_assignee_id' => $userId,
                    'expected_department' => 'CDQA',
                    'cdqa_decision' => 'approved',
                    'cdqa_comment' => $comment,
                    'remarks' => $comment !== '' ? $comment : 'Approved by CDQA.',
                ]
            );
            if ($comment !== '') dbmsAddContactComment($contactId, $userId, $comment);
            $message = 'Record approved.';
            $messageType = 'success';
        } elseif ($action === 'reject_back') {
            if ($comment === '') throw new RuntimeException('CDQA comment is required when rejecting back to Research.');
            dbmsAddContactComment($contactId, $userId, $comment);
            dbmsTransitionContactWorkflow(
                $contactId,
                'research_rework',
                $userId,
                'dbms.cdqa',
                [
                    'expected_assignee_id' => $userId,
                    'expected_department' => 'CDQA',
                    'clear_assignment' => true,
                    'cdqa_decision' => 'rejected',
                    'cdqa_comment' => $comment,
                    'increment_rework_count' => true,
                    'remarks' => $comment,
                ]
            );
            $message = 'Record returned to Research rework queue.';
            $messageType = 'warning';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$workflowFilter = dbmsNormalizeWorkflowStatus((string)($_GET['workflow'] ?? ''));
if (!in_array($workflowFilter, ['', 'cdqa_assigned', 'pending_cdqa'], true)) $workflowFilter = '';

$where = "WHERE c.assigned_to_user_id = ? AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'cdqa' AND {$workflowExpr} IN ('cdqa_assigned', 'pending_cdqa')";
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
if ($workflowFilter !== '') {
    $where .= " AND {$workflowExpr} = ?";
    $params[] = $workflowFilter;
    $types .= 's';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

$getCountryDisplay = function(array $row): string {
    foreach (['prospect_country', 'country', 'company_country'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '—';
};
$getPhoneDisplay = function(array $row): string {
    foreach (['direct_number', 'company_phone'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '—';
};
$getProspectLocation = function(array $row) use ($getCountryDisplay): string {
    $prospectLocation = trim((string)($row['prospect_location'] ?? ''));
    if ($prospectLocation !== '') return $prospectLocation;
    $parts = array_values(array_filter([
        trim((string)($row['city'] ?? '')),
        trim((string)($row['state'] ?? '')),
        $getCountryDisplay($row),
    ], static fn($v) => $v !== '' && $v !== '—'));
    return $parts ? implode(', ', $parts) : '—';
};
$getCompanyLocation = function(array $row): string {
    $parts = array_values(array_filter([
        trim((string)($row['company_city'] ?? '')) !== '' ? trim((string)$row['company_city']) : trim((string)($row['city'] ?? '')),
        trim((string)($row['company_state'] ?? '')) !== '' ? trim((string)$row['company_state']) : trim((string)($row['state'] ?? '')),
        trim((string)($row['company_country'] ?? '')) !== '' ? trim((string)$row['company_country']) : trim((string)($row['country'] ?? '')),
    ], static fn($v) => $v !== ''));
    return $parts ? implode(', ', $parts) : '—';
};

$stats = ['total' => 0, 'cdqa_assigned' => 0, 'pending_cdqa' => 0];
$stmtStats = $conn->prepare("
    SELECT
      COUNT(*) AS total_rows,
      SUM({$workflowExpr} = 'cdqa_assigned') AS assigned_rows,
      SUM({$workflowExpr} = 'pending_cdqa') AS pending_rows
    FROM dbms_contacts c
    WHERE c.assigned_to_user_id = ?
      AND LOWER(TRIM(COALESCE(c.assigned_department, ''))) = 'cdqa'
      AND {$workflowExpr} IN ('cdqa_assigned', 'pending_cdqa')
");
if ($stmtStats) {
    $stmtStats->bind_param('i', $userId);
    $stmtStats->execute();
    $statsRow = $stmtStats->get_result()->fetch_assoc() ?: [];
    $stats['total'] = (int)($statsRow['total_rows'] ?? 0);
    $stats['cdqa_assigned'] = (int)($statsRow['assigned_rows'] ?? 0);
    $stats['pending_cdqa'] = (int)($statsRow['pending_rows'] ?? 0);
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

$pageTitle = 'DBMS CDQA Queue';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 dbms-workspace">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="h3 mb-1">DBMS CDQA Queue</div>
      <div class="text-muted small">Assigned CDQA records for final review, approval, and reject-back workflow</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge bg-primary">CDQA</span>
      <span class="badge bg-light text-dark border"><?php echo number_format($stats['total']); ?> Assigned</span>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card hr-card dbms-panel h-100"><div class="card-body">
        <div class="text-muted small">Total Assigned</div>
        <div class="h3 mb-0"><?php echo number_format($stats['total']); ?></div>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card hr-card dbms-panel h-100"><div class="card-body">
        <div class="text-muted small">In Review</div>
        <div class="h3 mb-0"><?php echo number_format($stats['cdqa_assigned']); ?></div>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card hr-card dbms-panel h-100"><div class="card-body">
        <div class="text-muted small">Pending CDQA</div>
        <div class="h3 mb-0"><?php echo number_format($stats['pending_cdqa']); ?></div>
      </div></div>
    </div>
  </div>

  <div class="card hr-card dbms-panel mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-lg-8">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name / company / email / title">
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Queue</label>
          <select class="form-select form-select-sm" name="workflow">
            <option value="">All</option>
            <option value="cdqa_assigned" <?php echo $workflowFilter === 'cdqa_assigned' ? 'selected' : ''; ?>>CDQA Assigned</option>
            <option value="pending_cdqa" <?php echo $workflowFilter === 'pending_cdqa' ? 'selected' : ''; ?>>Pending CDQA</option>
          </select>
        </div>
        <div class="col-lg-2 d-flex gap-2">
          <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
          <a class="btn btn-outline-secondary btn-sm w-100" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/cdqa"><i class="bi bi-x me-1"></i>Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card hr-card dbms-panel">
    <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
      <span><i class="bi bi-shield-check me-1"></i>CDQA Records</span>
      <span class="badge bg-secondary"><?php echo number_format($total); ?></span>
    </div>
    <div class="card-body">
      <?php if (empty($rows)): ?>
        <div class="text-muted">No CDQA records assigned.</div>
      <?php else: ?>
        <div class="accordion" id="cdqaDbmsAcc">
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $cid = (int)($r['id'] ?? 0);
              $nm = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
              $company = (string)($r['company_name'] ?? '');
              $title = (string)($r['job_title'] ?? '');
              $workflowQueue = (string)($r['workflow_queue'] ?? '');
              $loc = $getProspectLocation($r);
              $companyLoc = $getCompanyLocation($r);
              $countryDisplay = $getCountryDisplay($r);
              $phoneDisplay = $getPhoneDisplay($r);
              $intent = trim((string)($r['intent_topics'] ?? ''));
            ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="ch<?php echo $cid; ?>">
                <button class="accordion-button <?php echo $i === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#cc<?php echo $cid; ?>" aria-expanded="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                  <div class="w-100 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                      <div class="fw-semibold"><?php echo htmlspecialchars($nm !== '' ? $nm : '—'); ?> <span class="text-muted small">· <?php echo htmlspecialchars($company !== '' ? $company : '—'); ?></span></div>
                      <div class="small text-muted"><?php echo htmlspecialchars($title !== '' ? $title : 'No title'); ?><?php echo $loc !== '' ? (' · ' . htmlspecialchars($loc)) : ''; ?></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                      <span class="badge bg-primary"><?php echo htmlspecialchars(dbmsWorkflowLabel($workflowQueue)); ?></span>
                      <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/view?id=<?php echo $cid; ?>"><i class="bi bi-eye me-1"></i>View</a>
                      <span class="badge bg-light text-dark border font-monospace">#<?php echo $cid; ?></span>
                    </div>
                  </div>
                </button>
              </h2>
              <div id="cc<?php echo $cid; ?>" class="accordion-collapse collapse <?php echo $i === 0 ? 'show' : ''; ?>" data-bs-parent="#cdqaDbmsAcc">
                <div class="accordion-body">
                  <div class="row g-3">
                    <div class="col-md-4"><div class="small text-muted">Email</div><div class="font-monospace"><?php echo htmlspecialchars((string)($r['email'] ?? '—')); ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Phone</div><div class="font-monospace"><?php echo htmlspecialchars($phoneDisplay); ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Country</div><div><?php echo htmlspecialchars($countryDisplay); ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Industry</div><div><?php echo htmlspecialchars((string)($r['industry'] ?? '—')); ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Website</div><div><?php echo htmlspecialchars((string)($r['website'] ?? '—')); ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">LinkedIn</div><?php $li = (string)($r['linkedin_url'] ?? ''); ?><?php if ($li !== ''): ?><a href="<?php echo htmlspecialchars($li); ?>" target="_blank" rel="noopener" class="small">Open</a><?php else: ?><div class="text-muted">—</div><?php endif; ?></div>
                    <div class="col-md-6"><div class="small text-muted">Prospect Location</div><div><?php echo htmlspecialchars($loc); ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Company Location</div><div><?php echo htmlspecialchars($companyLoc); ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Intent Topics</div><div><?php echo htmlspecialchars($intent !== '' ? $intent : '—'); ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Revenue Link</div><?php $rl = (string)($r['revenue_link'] ?? ''); ?><?php if ($rl !== ''): ?><a href="<?php echo htmlspecialchars($rl); ?>" target="_blank" rel="noopener" class="small">Open</a><?php else: ?><div class="text-muted">—</div><?php endif; ?></div>
                  </div>

                  <div class="row g-3 mt-2">
                    <div class="col-lg-6">
                      <div class="card hr-card dbms-panel h-100">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-patch-check me-1"></i>Approve Record</div>
                        <div class="card-body">
                          <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="contact_id" value="<?php echo $cid; ?>">
                            <div class="col-12">
                              <label class="form-label small text-muted">CDQA Comment (optional)</label>
                              <textarea class="form-control form-control-sm" name="comment" rows="4" placeholder="Approval note, validation remarks, or QA context"></textarea>
                            </div>
                            <div class="col-12">
                              <button class="btn btn-success btn-sm w-100" type="submit"><i class="bi bi-check-circle me-1"></i>Approve</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>
                    <div class="col-lg-6">
                      <div class="card hr-card dbms-panel h-100">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i>Reject Back To Research</div>
                        <div class="card-body">
                          <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="reject_back">
                            <input type="hidden" name="contact_id" value="<?php echo $cid; ?>">
                            <div class="col-12">
                              <label class="form-label small text-muted">CDQA Comment</label>
                              <textarea class="form-control form-control-sm" name="comment" rows="4" placeholder="Explain the quality issue or missing correction required" required></textarea>
                            </div>
                            <div class="col-12">
                              <button class="btn btn-outline-danger btn-sm w-100" type="submit"><i class="bi bi-arrow-return-left me-1"></i>Send To Research Rework</button>
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
