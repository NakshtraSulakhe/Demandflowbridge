<?php
header('Location: ../qa/audit');
exit;
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

$visible = getQaVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
$conn = getDbConnection();

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$agentId = (int)($_GET['agent_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$qaStatus = trim((string)($_GET['qa_status'] ?? 'Pending'));
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$appBase = function(): string {
    $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $sn = trim($sn, '/');
    if ($sn === '') return '';
    $parts = explode('/', $sn);
    $root = $parts[0] ?? '';
    return $root !== '' ? ('/' . $root) : '';
};
$toUrl = function(?string $p) use ($appBase): string {
    $p = trim((string)$p);
    if ($p === '') return '';
    if (preg_match('/^https?:\\/\\//i', $p)) return $p;
    if (str_starts_with($p, '/')) return $p;
    if (preg_match('/^uploads\\//i', $p)) return $appBase() . '/' . $p;
    return $p;
};

if ($visible !== null && $campaignId > 0 && !isset($visible[$campaignId])) {
    $campaignId = 0;
}

$campaigns = [];
if ($visible === null) {
    $rs = $conn->query("SELECT c.id, c.name, d.code FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
    if ($rs) $campaigns = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
    $ids = array_keys($visible);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT c.id, c.name, d.code FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id IN ($in) ORDER BY c.name");
        $types = str_repeat('i', count($ids));
        if ($stmt) {
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }
    }
}

$agentRoles = ['agent','operations_agent','operations_manager','operations_director'];
$inAgentRoles = implode(',', array_fill(0, count($agentRoles), '?'));
$stmtA = $conn->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role IN ($inAgentRoles) ORDER BY full_name");
if ($stmtA) {
    $stmtA->bind_param(str_repeat('s', count($agentRoles)), ...$agentRoles);
    $stmtA->execute();
    $agents = $stmtA->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtA->close();
} else {
    $agents = [];
}

$where = ['1=1'];
$params = [];
$types = '';

if ($visible !== null) {
    $ids = array_keys($visible);
    if (empty($ids)) {
        $where[] = '1=0';
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($ids as $id) {
            $params[] = (int)$id;
            $types .= 'i';
        }
    }
}

if ($campaignId > 0) { $where[] = 'l.campaign_id = ?'; $params[] = $campaignId; $types .= 'i'; }
if ($agentId > 0) { $where[] = 'l.agent_id = ?'; $params[] = $agentId; $types .= 'i'; }
if ($dateFrom !== '') { $where[] = 'DATE(l.created_at) >= ?'; $params[] = $dateFrom; $types .= 's'; }
if ($dateTo !== '') { $where[] = 'DATE(l.created_at) <= ?'; $params[] = $dateTo; $types .= 's'; }
if ($qaStatus !== '') {
    $normQa = normalizeQaStatus($qaStatus);
    if ($normQa === 'Pending') {
        $where[] = '(l.qa_status IS NULL OR l.qa_status IN (\'Pending\',\'Reopened\'))';
    } else {
        $where[] = '(l.qa_status IS NOT NULL AND l.qa_status = ?)';
        $params[] = $normQa;
        $types .= 's';
    }
}
if ($search !== '') {
    $where[] = '(l.lead_id LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)';
    $q = '%'.$search.'%';
    array_push($params, $q, $q, $q, $q, $q);
    $types .= 'sssss';
}

$whereSql = implode(' AND ', $where);
$offset = ($page - 1) * $perPage;

$cntStmt = $conn->prepare("SELECT COUNT(*) FROM leads l WHERE $whereSql");
$total = 0;
if ($cntStmt) {
    if ($types) $cntStmt->bind_param($types, ...$params);
    $cntStmt->execute();
    $total = (int)($cntStmt->get_result()->fetch_row()[0] ?? 0);
    $cntStmt->close();
}

$sql = "
    SELECT l.id, l.lead_id, l.first_name, l.last_name, l.company_name, l.email, l.contact_phone, l.job_title,
           l.created_at, l.qa_status, l.qa_comment, l.qa_client_comment, l.client_delivery_status, l.form_done, l.recording_path,
           c.name AS campaign_name, d.code AS campaign_code,
           u.full_name AS agent_name,
           r.full_name AS reviewer_name
    FROM leads l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
    LEFT JOIN users u ON u.id = l.agent_id
    LEFT JOIN users r ON r.id = l.qa_reviewed_by
    WHERE $whereSql
    ORDER BY l.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$params2 = $params;
$params2[] = $perPage;
$params2[] = $offset;
$leads = [];
if ($stmt) {
    $stmt->bind_param($types.'ii', ...$params2);
    $stmt->execute();
    $leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$totalPages = (int)ceil($total / $perPage);

$canAssign = hasRole(['admin','qa_director','qa_manager']);
?>

<?php $pageTitle = 'QA Audit'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">QA Audit Queue</h3>
      <div class="text-muted small">Review and update QA status for submitted leads.</div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($canAssign): ?>
        <a class="btn btn-outline-primary btn-sm" href="qa-assign.php"><i class="bi bi-person-check me-1"></i>Assignments</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($visible !== null && empty($visible)): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body d-flex align-items-start justify-content-between gap-3">
        <div>
          <div class="fw-semibold mb-1">No Campaigns Assigned</div>
          <div class="text-muted small">You currently have no campaigns allocated for QA review.</div>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="qa-request-assignment.php">
          <i class="bi bi-send me-1"></i> Request Assignment
        </a>
      </div>
    </div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-lg-3">
          <label class="form-label">Search</label>
          <input type="text" class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Lead ID, email, company">
        </div>
        <div class="col-lg-3">
          <label class="form-label">Campaign</label>
          <select class="form-select form-select-sm" name="campaign_id">
            <option value="0">All</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$c['id'] === $campaignId) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(($c['name'] ?? '').' ['.($c['code'] ?? '').']'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label">Agent</label>
          <select class="form-select form-select-sm" name="agent_id">
            <option value="0">All</option>
            <?php foreach ($agents as $a): ?>
              <option value="<?php echo (int)$a['id']; ?>" <?php echo ((int)$a['id'] === $agentId) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)($a['full_name'] ?? '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label">QA Status</label>
          <select class="form-select form-select-sm" name="qa_status">
            <?php
              $opts = ['Pending' => 'Pending QA', 'Reopened' => 'Reopened', 'Qualified' => 'Qualified', 'Disqualified' => 'Disqualified', 'Rework Needed' => 'Needs Correction'];
            ?>
            <?php foreach ($opts as $v => $lbl): ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo normalizeQaStatus($qaStatus) === normalizeQaStatus($v) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($lbl); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-1">
          <label class="form-label">From</label>
          <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-lg-1">
          <label class="form-label">To</label>
          <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-12 d-flex justify-content-end gap-2">
          <a class="btn btn-light border btn-sm" href="qa-audit.php">Reset</a>
          <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light">
          <tr>
            <th class="ps-3">SR No.</th>
            <th>Lead Info</th>
            <th>Company</th>
            <th>Campaign/Agent</th>
            <th>Form</th>
            <th>Quality</th>
            <th class="text-end pe-3 sticky-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($leads)): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">No leads found matching your criteria.</td>
            </tr>
          <?php else: ?>
            <?php $serialStart = (($page - 1) * $perPage) + 1; ?>
            <?php foreach ($leads as $i => $l): ?>
              <?php
                $name = trim((string)($l['first_name'] ?? '').' '.(string)($l['last_name'] ?? ''));
                $rec = (string)($l['recording_path'] ?? '');
                $recUrl = $toUrl($rec);
                $qaS = normalizeQaStatus((string)($l['qa_status'] ?? 'Pending'));
                $qaClass = 'bg-warning-subtle text-warning';
                if ($qaS === 'Qualified') $qaClass = 'bg-success-subtle text-success';
                if ($qaS === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
                if ($qaS === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
                if ($qaS === 'Rework Needed') $qaClass = 'bg-info-subtle text-info';
              ?>
              <tr>
                <td class="ps-3 text-muted small"><?php echo $serialStart + $i; ?></td>
                <td>
                  <div class="fw-bold"><?php echo htmlspecialchars($name); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($l['email'] ?? '')); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($l['contact_phone'] ?? '')); ?></div>
                </td>
                <td>
                  <div class="small fw-semibold"><?php echo htmlspecialchars((string)($l['company_name'] ?? '')); ?></div>
                  <div class="small text-muted"><?php echo htmlspecialchars((string)($l['job_title'] ?? '')); ?></div>
                </td>
                <td>
                  <div class="small"><span class="text-muted">Camp:</span> <?php echo htmlspecialchars((string)($l['campaign_name'] ?? 'N/A')); ?></div>
                  <div class="small"><span class="text-muted">Agent:</span> <?php echo htmlspecialchars((string)($l['agent_name'] ?? 'N/A')); ?></div>
                </td>
                <td>
                  <?php if (((string)($l['form_done'] ?? 'No')) === 'Yes'): ?>
                    <span class="badge bg-success-subtle text-success border">Filled</span>
                  <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning border">Pending</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars($qaS); ?></span>
                  <?php if (!empty($l['reviewer_name'])): ?>
                    <div class="small text-muted mt-1" style="font-size:0.7rem;">by <?php echo htmlspecialchars((string)($l['reviewer_name'] ?? '')); ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-end pe-3 sticky-actions">
                  <div class="btn-group btn-group-sm">
                    <?php if ($recUrl !== ''): ?>
                      <button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#recordingModal" data-lead-name="<?php echo htmlspecialchars($name); ?>" data-recording="<?php echo htmlspecialchars($recUrl); ?>" title="Play Recording">
                        <i class="bi bi-play-fill"></i>
                      </button>
                    <?php endif; ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qaModal"
                      data-lead-id="<?php echo (int)($l['id'] ?? 0); ?>"
                      data-lead-name="<?php echo htmlspecialchars($name); ?>"
                      data-qa-status="<?php echo htmlspecialchars($qaS); ?>"
                      data-qa-comment="<?php echo htmlspecialchars((string)($l['qa_comment'] ?? '')); ?>"
                      data-qa-client-comment="<?php echo htmlspecialchars((string)($l['qa_client_comment'] ?? '')); ?>"
                      data-client-delivery-status="<?php echo htmlspecialchars((string)($l['client_delivery_status'] ?? 'Pending')); ?>"
                      data-recording="<?php echo htmlspecialchars($recUrl); ?>" title="Update QA">
                      <i class="bi bi-shield-check"></i>
                    </button>
                    <a class="btn btn-light border" href="../leads/view?id=<?php echo (int)($l['id'] ?? 0); ?>" title="View Full Details">
                      <i class="bi bi-eye"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 border-top d-flex align-items-center justify-content-between">
      <div class="small text-muted">Showing <?php echo count($leads); ?> of <?php echo number_format($total); ?> leads</div>
      <?php if ($totalPages > 1): ?>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
              <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Prev</a>
            </li>
            <?php
              $window = 2;
              $startPage = max(1, $page - $window);
              $endPage = min($totalPages, $page + $window);
              for ($p = $startPage; $p <= $endPage; $p++):
            ?>
              <li class="page-item <?php echo ($p === $page) ? 'active' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $p; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>"><?php echo $p; ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
              <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Next</a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="recordingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title">Recording Player</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <h6 id="recLeadName" class="mb-3 fw-bold text-primary"></h6>
        <audio id="recAudio" controls class="w-100 mb-2">Your browser does not support the audio element.</audio>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="qaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="qa-action.php">
        <div class="modal-header">
          <h5 class="modal-title">QA Decision</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="lead_id" id="qa_lead_id" value="">
          <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
          <div class="mb-3">
            <label class="form-label small text-muted text-uppercase fw-bold">Lead Name</label>
            <div id="qa_lead_name" class="fw-bold h6"></div>
          </div>
          <div class="mb-3">
            <label class="form-label small text-muted text-uppercase fw-bold">Quality Status</label>
            <select class="form-select" name="qa_status" id="qa_status_select">
              <option value="Pending">Pending QA</option>
              <?php if (hasRole(['admin','qa_director','qa_manager'])): ?>
                <option value="Reopened">Reopened</option>
              <?php endif; ?>
              <option value="Qualified">Qualified</option>
              <option value="Disqualified">Disqualified</option>
              <option value="Rework Needed">Needs Correction</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small text-muted text-uppercase fw-bold">Client Delivery Status</label>
            <select class="form-select" name="client_delivery_status" id="client_delivery_status_select">
              <?php foreach (getClientDeliveryStatuses() as $v): ?>
                <option value="<?php echo htmlspecialchars($v); ?>"><?php echo htmlspecialchars($v); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small text-muted text-uppercase fw-bold">Internal Comments</label>
            <textarea class="form-control" id="qa_comment_internal" name="qa_comment_internal" rows="3" placeholder="Internal QA notes..."></textarea>
          </div>
          <div class="mb-0">
            <label class="form-label small text-muted text-uppercase fw-bold">Client Comments (visible to client)</label>
            <textarea class="form-control" id="qa_comment_client" name="qa_comment_client" rows="3" placeholder="Client-facing notes..."></textarea>
          </div>
          <div id="qaRecordingContainer" class="p-3 bg-light rounded-3 mt-3" style="display:none;">
            <label class="form-label small text-muted text-uppercase fw-bold d-block">Recording Audit</label>
            <audio id="qaAudioPlayer" controls class="w-100 mb-2"></audio>
            <a id="qaDownloadLink" class="btn btn-sm btn-outline-secondary w-100" href="#" download>
              <i class="bi bi-download me-1"></i> Download File
            </a>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const recordingModal = document.getElementById('recordingModal');
  if (recordingModal) {
    recordingModal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      const name = button.getAttribute('data-lead-name') || '';
      const rec = button.getAttribute('data-recording') || '';
      document.getElementById('recLeadName').textContent = name;
      document.getElementById('recAudio').src = rec;
    });
    recordingModal.addEventListener('hidden.bs.modal', function() {
      document.getElementById('recAudio').pause();
      document.getElementById('recAudio').src = '';
    });
  }

  const qaModal = document.getElementById('qaModal');
  if (qaModal) {
    qaModal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      const leadId = button.getAttribute('data-lead-id') || '';
      const leadName = button.getAttribute('data-lead-name') || '';
      const qaStatus = button.getAttribute('data-qa-status') || 'Pending';
      const qaComment = button.getAttribute('data-qa-comment') || '';
      const qaClientComment = button.getAttribute('data-qa-client-comment') || '';
      const cds = button.getAttribute('data-client-delivery-status') || 'Pending';
      const rec = button.getAttribute('data-recording') || '';

      document.getElementById('qa_lead_id').value = leadId;
      document.getElementById('qa_lead_name').textContent = leadName;
      document.getElementById('qa_status_select').value = qaStatus;
      document.getElementById('client_delivery_status_select').value = cds;
      document.getElementById('qa_comment_internal').value = qaComment;
      document.getElementById('qa_comment_client').value = qaClientComment;

      const wrap = document.getElementById('qaRecordingContainer');
      if (wrap) {
        if (rec) {
          wrap.style.display = '';
          document.getElementById('qaAudioPlayer').src = rec;
          document.getElementById('qaDownloadLink').href = rec;
        } else {
          wrap.style.display = 'none';
          document.getElementById('qaAudioPlayer').src = '';
          document.getElementById('qaDownloadLink').href = '#';
        }
      }
    });
    qaModal.addEventListener('hidden.bs.modal', function() {
      const ap = document.getElementById('qaAudioPlayer');
      if (ap) { ap.pause(); ap.src = ''; }
    });
  }
});
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
