<?php
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
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$agentId = (int)($_GET['agent_id'] ?? 0);
$reviewerId = (int)($_GET['reviewer_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$qaStatus = trim((string)($_GET['qa_status'] ?? 'Pending'));
$clientDeliveryStatus = trim((string)($_GET['client_delivery_status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$qaStatusOptions = function_exists('getQaStatusOptions') ? getQaStatusOptions() : [];

$appBaseVal = (function() {
    $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $sn = trim($sn, '/');
    if ($sn === '') return '';
    $parts = explode('/', $sn);
    $root = $parts[0] ?? '';
    return $root !== '' ? ('/' . $root) : '';
})();

$toUrl = function(?string $p) use ($appBaseVal): string {
    $p = trim((string)$p);
    if ($p === '') return '';
    if ($p[0] === '/' || str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) return $p;
    if (str_starts_with($p, 'uploads/') || str_starts_with($p, 'modules/') || str_starts_with($p, 'storage/') || str_starts_with($p, 'tmp/')) {
        return $appBaseVal . '/' . $p;
    }
    return $appBaseVal !== '' ? ($appBaseVal . '/' . ltrim($p, '/')) : $p;
};

if ($visible !== null && $campaignId > 0 && !isset($visible[$campaignId])) {
    $campaignId = 0;
}

$campaigns = [];
if ($visible === null) {
    $rs = $conn->query("SELECT c.id, c.name, d.code, d.client_code FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
    if ($rs) $campaigns = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
    $ids = array_keys($visible);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT c.id, c.name, d.code, d.client_code FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id IN ($in) ORDER BY c.name");
        $types = str_repeat('i', count($ids));
        if ($stmt) {
            mysqliBindParams($stmt, $types, $ids);
            $stmt->execute();
            $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }
    }
}

$agentRoles = ['agent','operations_agent','operations_manager','operations_director'];
$qaRoles = ['qa','qa_agent','qa_manager','qa_director'];
$inAgentRoles = implode(',', array_fill(0, count($agentRoles), '?'));
$stmtA = $conn->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role IN ($inAgentRoles) ORDER BY full_name");
if ($stmtA) {
    mysqliBindParams($stmtA, str_repeat('s', count($agentRoles)), $agentRoles);
    $stmtA->execute();
    $agents = $stmtA->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtA->close();
} else {
    $agents = [];
}

$inQaRoles = implode(',', array_fill(0, count($qaRoles), '?'));
$stmtQ = $conn->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role IN ($inQaRoles) ORDER BY full_name");
if ($stmtQ) {
    mysqliBindParams($stmtQ, str_repeat('s', count($qaRoles)), $qaRoles);
    $stmtQ->execute();
    $reviewers = $stmtQ->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtQ->close();
} else {
    $reviewers = [];
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
        foreach ($ids as $id) { $params[] = (int)$id; $types .= 'i'; }
    }
}

if ($campaignId > 0) { $where[] = 'l.campaign_id = ?'; $params[] = $campaignId; $types .= 'i'; }
if ($agentId > 0) { $where[] = 'l.agent_id = ?'; $params[] = $agentId; $types .= 'i'; }
if ($reviewerId > 0) { $where[] = 'l.qa_reviewed_by = ?'; $params[] = $reviewerId; $types .= 'i'; }
if ($dateFrom !== '') { $where[] = "$leadCreatedExpr >= ?"; $params[] = $dateFrom . ' 00:00:00'; $types .= 's'; }
if ($dateTo !== '') { $where[] = "$leadCreatedExpr <= ?"; $params[] = $dateTo . ' 23:59:59'; $types .= 's'; }
if ($qaStatus !== '') {
    $normQa = normalizeQaStatus($qaStatus);
    $qaVals = qaStatusFilterValues($qaStatus);
    if ($normQa === 'Pending') {
        $in = implode(',', array_fill(0, count($qaVals), '?'));
        $where[] = "(l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status IN ($in))";
        foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
    } elseif (count($qaVals) === 1) {
        $where[] = '(l.qa_status IS NOT NULL AND l.qa_status = ?)';
        $params[] = $qaVals[0];
        $types .= 's';
    } else {
        $in = implode(',', array_fill(0, count($qaVals), '?'));
        $where[] = "(l.qa_status IS NOT NULL AND l.qa_status IN ($in))";
        foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
    }
}
if ($clientDeliveryStatus !== '') {
    $deliveryVals = clientDeliveryStatusFilterValues($clientDeliveryStatus);
    if (count($deliveryVals) === 1) {
        $where[] = '(l.client_delivery_status = ?)';
        $params[] = $deliveryVals[0];
        $types .= 's';
    } else {
        $in = implode(',', array_fill(0, count($deliveryVals), '?'));
        $where[] = "(l.client_delivery_status IN ($in))";
        foreach ($deliveryVals as $v) { $params[] = $v; $types .= 's'; }
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
    if ($types) mysqliBindParams($cntStmt, $types, $params);
    $cntStmt->execute();
    $total = (int)($cntStmt->get_result()->fetch_row()[0] ?? 0);
    $cntStmt->close();
}

$sql = "
    SELECT l.id, l.lead_id, l.first_name, l.last_name, l.company_name, l.email, l.contact_phone, l.job_title,
           l.created_at, l.qa_status, l.qa_comment, l.qa_client_comment, l.client_delivery_status, l.form_done,
           COALESCE(NULLIF(lfr_last.call_recording_path, ''), l.recording_path) AS recording_path,
           (SELECT COUNT(*) FROM lead_files WHERE lead_id = l.id AND field_id = 'call_recording') AS recording_count,
           c.name AS campaign_name, d.code AS campaign_code, d.client_code AS client_code,
           u.full_name AS agent_name,
           r.full_name AS reviewer_name
    FROM leads l
    LEFT JOIN (
      SELECT lf1.lead_id, lf1.file_path AS call_recording_path
      FROM lead_files lf1
      JOIN (
        SELECT lead_id, MAX(id) AS max_id
        FROM lead_files
        WHERE field_id = 'call_recording'
        GROUP BY lead_id
      ) lf2 ON lf2.max_id = lf1.id
    ) lfr_last ON lfr_last.lead_id = l.id
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
    LEFT JOIN users u ON u.id = l.agent_id
    LEFT JOIN users r ON r.id = l.qa_reviewed_by
    WHERE $whereSql
    ORDER BY $leadCreatedExpr DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$params2 = $params;
$params2[] = $perPage;
$params2[] = $offset;
$leads = [];
if ($stmt) {
    mysqliBindParams($stmt, $types . 'ii', $params2);
    $stmt->execute();
    $leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$getRecordingUrl = function(array $lead, bool $inline = true): string {
    $leadId = (int)($lead['id'] ?? 0);
    $lfId = (int)($lead['call_recording_id'] ?? 0);
    if ($lfId > 0 && function_exists('appBuildLeadFileDownloadUrl')) {
        return appBuildLeadFileDownloadUrl($lfId, $inline);
    }

    $rp = trim((string)($lead['recording_path'] ?? ''));
    if ($rp === '' || strtolower($rp) === 'null') return '';
    if (preg_match('#^https?://#i', $rp)) return $rp;
    if (function_exists('appBuildLeadPathDownloadUrl')) {
        $u = appBuildLeadPathDownloadUrl($leadId, $rp, $inline);
        if ($u !== '') return $u;
    }

    $rp = '/' . ltrim($rp, '/');
    $base = function_exists('appBasePath') ? (string)appBasePath() : '';
    return $base . $rp;
};

$totalPages = (int)ceil($total / $perPage);
$canAssign = hasRole(['admin','qa_director','qa_manager']);
$statusSummaryFilters = [];
if ($visible !== null) $statusSummaryFilters['campaign_ids'] = array_keys($visible);
if ($campaignId > 0) $statusSummaryFilters['campaign_id'] = $campaignId;
if ($agentId > 0) $statusSummaryFilters['agent_id'] = $agentId;
if ($dateFrom !== '') $statusSummaryFilters['date_from'] = $dateFrom;
if ($dateTo !== '') $statusSummaryFilters['date_to'] = $dateTo;
if ($search !== '') $statusSummaryFilters['search'] = $search;
$statusSummary = function_exists('getLeadWorkflowCounts') ? getLeadWorkflowCounts($statusSummaryFilters) : ['qa_counts' => [], 'client_delivery_counts' => []];
?>

<?php $pageTitle = 'QA Audit'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
  .modal-xl { max-width: 1200px; }
  .border-2 { border-width: 1px !important; }
  .bg-light-subtle { background-color: #fafbfc !important; }
  #qa_lead_details_container { padding: 1rem !important; overflow-x: hidden; }
  #qa_lead_details_container .card { border: 1px solid #e9ecef !important; box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important; background: white !important; margin-bottom: 1rem !important; border-radius: 0.5rem !important; }
  #qa_lead_details_container .card-header { background-color: #f8f9fa !important; border-bottom: 1px solid #e9ecef !important; padding: 0.75rem 1rem !important; font-weight: 600 !important; }
  #qa_lead_details_container .card-body { padding: 1rem !important; }
  #qa_lead_details_container .h4 { font-size: 1.25rem !important; margin-bottom: 0.5rem !important; }
  #qa_lead_details_container .h5 { font-size: 1rem !important; }
  #qa_lead_details_container .row { margin-left: -0.5rem; margin-right: -0.5rem; }
  #qa_lead_details_container .col-lg-6, #qa_lead_details_container .col-md-6, #qa_lead_details_container .col-12, #qa_lead_details_container .col-lg-12 { padding-left: 0.5rem; padding-right: 0.5rem; }
  #qa_lead_details_container .text-muted.x-small { font-size: 0.75rem !important; }
  #qa_lead_details_container .fw-semibold.text-dark.small { font-size: 0.875rem !important; }
  .modal-body { padding: 0 !important; }
  .modal-header { padding: 1rem 1.5rem !important; }
  .modal-footer { padding: 1rem 1.5rem !important; }
  
  /* Custom Scrollbar for a lighter look */
  #qa_lead_details_container::-webkit-scrollbar, 
  .col-lg-5.bg-white::-webkit-scrollbar {
    width: 6px;
  }
  #qa_lead_details_container::-webkit-scrollbar-track,
  .col-lg-5.bg-white::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
  }
  #qa_lead_details_container::-webkit-scrollbar-thumb,
  .col-lg-5.bg-white::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
  }
  #qa_lead_details_container::-webkit-scrollbar-thumb:hover,
  .col-lg-5.bg-white::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
  }
  
  /* Hide horizontal scrollbar */
  #qa_lead_details_container { overflow-x: hidden !important; }
  
  .sticky-actions { position: sticky; right: 0; background: white; z-index: 10; }
  .table-hover tbody tr:hover .sticky-actions { background: #f8f9fa; }
  
  /* Improve form field spacing in right column */
  .col-lg-5.bg-white .form-label { margin-bottom: 0.5rem !important; }
  .col-lg-5.bg-white .form-select, .col-lg-5.bg-white .form-control { margin-bottom: 0.25rem !important; }
</style>
<div class="container-fluid px-0 leads-workspace">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">QA Audit Queue</h3>
      <div class="text-muted small">Review and update QA status for submitted leads.</div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($canAssign): ?>
        <a class="btn btn-outline-primary btn-sm" href="assignments"><i class="bi bi-person-check me-1"></i>Assignments</a>
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
        <a class="btn btn-outline-primary btn-sm" href="request">
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
                <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?>
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
          <label class="form-label">Reviewed By</label>
          <select class="form-select form-select-sm" name="reviewer_id">
            <option value="0">All</option>
            <?php foreach ($reviewers as $r): ?>
              <option value="<?php echo (int)$r['id']; ?>" <?php echo ((int)$r['id'] === $reviewerId) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label">QA Status</label>
          <select class="form-select form-select-sm" name="qa_status">
            <option value="" <?php echo $qaStatus === '' ? 'selected' : ''; ?>>All</option>
            <?php foreach ($qaStatusOptions as $v => $lbl): ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo normalizeQaStatus($qaStatus) === normalizeQaStatus($v) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($lbl); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label">Client Delivery</label>
          <select class="form-select form-select-sm" name="client_delivery_status">
            <option value="">All</option>
            <?php foreach (getClientDeliveryStatusOptions() as $v => $lbl): ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo normalizeClientDeliveryStatus($clientDeliveryStatus) === normalizeClientDeliveryStatus($v) ? 'selected' : ''; ?>>
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
          <a class="btn btn-light border btn-sm" href="audit">Reset</a>
          <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="small fw-semibold text-uppercase text-muted mb-2">QA Status Counts</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach (getQaStatusOptions() as $statusValue => $statusLabel): ?>
              <span class="badge bg-light text-dark border">
                <?php echo htmlspecialchars($statusLabel); ?>: <?php echo number_format((int)($statusSummary['qa_counts'][$statusValue] ?? 0)); ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="small fw-semibold text-uppercase text-muted mb-2">Client Delivery Counts</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach (getClientDeliveryStatusOptions() as $statusValue => $statusLabel): ?>
              <span class="badge bg-light text-dark border">
                <?php echo htmlspecialchars($statusLabel); ?>: <?php echo number_format((int)($statusSummary['client_delivery_counts'][$statusValue] ?? 0)); ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light">
          <tr>
            <th class="ps-3">SR No.</th>
            <th>Created Date</th>
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
            <tr><td colspan="8" class="text-center py-5 text-muted">No leads found matching your criteria.</td></tr>
          <?php else: ?>
            <?php $serialStart = (($page - 1) * $perPage) + 1; ?>
            <?php foreach ($leads as $i => $l): ?>
              <?php
                $name = trim((string)($l['first_name'] ?? '').' '.(string)($l['last_name'] ?? ''));
                $recInline = $getRecordingUrl($l, true);
                $recDl = $getRecordingUrl($l, false);
                $qaS = normalizeQaStatus((string)($l['qa_status'] ?? 'Pending'));
                $qaClass = qaStatusBadgeClass($qaS);
              ?>
              <tr>
                <td class="ps-3 text-muted small"><?php echo $serialStart + $i; ?></td>
                  <td>
                  <div class="leads-row-primary"><?php echo htmlspecialchars(!empty($l['created_at']) ? date('d M Y', strtotime((string)$l['created_at'])) : 'Not available'); ?></div>
                  <span class="leads-row-secondary"><?php echo htmlspecialchars(!empty($l['created_at']) ? date('h:i A', strtotime((string)$l['created_at'])) : 'Creation time unavailable'); ?></span>
                </td>
                <td>
                  <div class="leads-row-primary"><?php echo htmlspecialchars($name); ?></div>
                  <span class="leads-row-secondary"><?php echo htmlspecialchars('Primary email · ' . ((string)($l['email'] ?? 'N/A'))); ?></span>
                  <span class="leads-row-secondary"><?php echo htmlspecialchars('Contact number · ' . ((string)($l['contact_phone'] ?? 'N/A'))); ?></span>
                </td>
              
                <td>
                  <div class="leads-row-primary"><?php echo htmlspecialchars((string)($l['company_name'] ?? '')); ?></div>
                  <span class="leads-row-secondary"><?php echo htmlspecialchars('Job title · ' . ((string)($l['job_title'] ?? 'N/A'))); ?></span>
                </td>
                <td>
                  <div class="leads-campaign-stack">
                    <div class="leads-campaign-item">
                      <div class="leads-campaign-value">
                        <div class="leads-row-primary"><?php echo htmlspecialchars(crmCampaignNameWithClientCode((string)($l['campaign_name'] ?? 'N/A'), (string)($l['client_code'] ?? ''), (string)($l['campaign_code'] ?? ''))); ?></div>
                      </div>
                    </div>
                    <div class="leads-campaign-item">
                      <div class="leads-campaign-value">
                        <div class="leads-row-secondary"><?php echo htmlspecialchars('Assigned agent · ' . ((string)($l['agent_name'] ?? 'N/A'))); ?></div>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if (normalizeFormDone((string)($l['form_done'] ?? 'No')) === 'Yes'): ?>
                    <span class="badge bg-success-subtle text-success border">Filled</span>
                  <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning border">Pending</span>
                  <?php endif; ?>
                </td>
                <td>
                    <span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars(qaStatusDisplayLabel($qaS)); ?></span>
                  <?php if (!empty($l['reviewer_name'])): ?>
                    <div class="small text-muted mt-1" style="font-size:0.7rem;">by <?php echo htmlspecialchars((string)($l['reviewer_name'] ?? '')); ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-end pe-3 sticky-actions">
                  <div class="btn-group btn-group-sm">
                    <?php 
                      $recCount = (int)($l['recording_count'] ?? 0);
                      if ($recCount === 0 && !empty($l['recording_path'])) $recCount = 1;
                    ?>
                    <?php if ($recCount > 0): ?>
                      <button type="button" class="btn btn-light border position-relative" data-bs-toggle="modal" data-bs-target="#recordingModal" data-lead-name="<?php echo htmlspecialchars($name); ?>" data-lead-id="<?php echo (int)$l['id']; ?>" title="Play Recordings">
                        <i class="bi bi-play-fill"></i>
                        <?php if ($recCount > 1): ?>
                          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem; padding: 0.2rem 0.4rem;">
                            <?php echo $recCount; ?>
                          </span>
                        <?php endif; ?>
                      </button>
                    <?php endif; ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qaModal"
                      data-lead-id="<?php echo (int)($l['id'] ?? 0); ?>"
                      data-lead-name="<?php echo htmlspecialchars($name); ?>"
                      data-qa-status="<?php echo htmlspecialchars($qaS); ?>"
                      data-qa-comment="<?php echo htmlspecialchars((string)($l['qa_comment'] ?? '')); ?>"
                      data-qa-client-comment="<?php echo htmlspecialchars((string)($l['qa_client_comment'] ?? '')); ?>"
                      data-client-delivery-status="<?php echo htmlspecialchars(normalizeClientDeliveryStatus((string)($l['client_delivery_status'] ?? 'Pending'))); ?>"
                      data-recording-inline="<?php echo htmlspecialchars($recInline); ?>"
                      data-recording-dl="<?php echo htmlspecialchars($recDl); ?>" title="Update QA">
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
        <h5 class="modal-title">Lead Recordings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body py-3" id="recordingModalBody">
        <h6 id="recLeadName" class="mb-3 fw-bold text-primary px-3"></h6>
        <div id="recModalList" class="px-3">
          <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="qaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content border-0 shadow-lg">
      <form method="post" action="action">
        <div class="modal-header bg-light border-bottom-0 py-3">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-shield-check me-2"></i>QA Decision & Review</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
          <div class="row g-0">
            <!-- Left Column: Lead Details (Scrollable) -->
            <div class="col-lg-7 border-end bg-light-subtle" style="max-height: 80vh; overflow-y: auto;">
              <div id="qa_lead_details_container">
                <div class="text-center py-5">
                  <div class="spinner-border text-primary" role="status"></div>
                  <div class="mt-2 text-muted small">Loading lead details...</div>
                </div>
              </div>
            </div>
            
            <!-- Right Column: QA Form -->
            <div class="col-lg-5 bg-white" style="max-height: 80vh; overflow-y: auto;">
              <div class="p-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="lead_id" id="qa_lead_id" value="">
                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                
                <div class="mb-4 pb-3 border-bottom">
                  <label class="form-label small text-muted text-uppercase fw-bold mb-2">Lead Information</label>
                  <div id="qa_lead_name" class="fw-bold h4 text-primary mb-1"></div>
                </div>
                
                <div class="mb-4">
                  <label class="form-label small text-muted text-uppercase fw-bold mb-2">Quality Assessment</label>
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label small text-dark fw-semibold mb-1">Quality Status</label>
                      <select class="form-select" name="qa_status" id="qa_status_select">
                        <?php foreach ($qaStatusOptions as $qaStatusValue => $qaStatusLabel): ?>
                          <option value="<?php echo htmlspecialchars($qaStatusValue); ?>"><?php echo htmlspecialchars($qaStatusLabel); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-12">
                      <label class="form-label small text-dark fw-semibold mb-1">Delivery Status (Client)</label>
                      <select class="form-select" name="client_delivery_status" id="client_delivery_status_select">
                        <?php foreach (getClientDeliveryStatuses() as $v): ?>
                          <option value="<?php echo htmlspecialchars($v); ?>"><?php echo htmlspecialchars(clientDeliveryStatusDisplayLabel($v)); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </div>
                
                <div class="mb-4">
                  <label class="form-label small text-muted text-uppercase fw-bold mb-2">Comments & Notes</label>
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label small text-dark fw-semibold mb-1">Internal Comments</label>
                      <textarea class="form-control" id="qa_comment_internal" name="qa_comment_internal" rows="3" placeholder="Notes for internal team..."></textarea>
                    </div>
                    <div class="col-12">
                      <label class="form-label small text-dark fw-semibold mb-1">Client Comments</label>
                      <textarea class="form-control" id="qa_comment_client" name="qa_comment_client" rows="3" placeholder="Notes visible to client..."></textarea>
                    </div>
                  </div>
                </div>

                <div id="qaRecordingContainer" class="p-3 bg-light rounded-3 mt-4 border" style="display:none;">
                  <label class="form-label small text-muted text-uppercase fw-bold d-block mb-2">Recording Audit</label>
                  <div class="recording-player-shell" data-recording-player>
                    <div class="recording-head">
                      <span class="fw-bold small">Primary Recording</span>
                      <a id="qaDownloadLink" class="btn btn-sm btn-outline-secondary" href="#" download>
                        <i class="bi bi-download me-1"></i>Download
                      </a>
                    </div>
                    <audio id="qaAudioPlayer" controls preload="metadata" class="recording-audio"></audio>
                    <div class="recording-seek-controls">
                      <button type="button" class="btn btn-light border btn-sm" data-recording-jump="-10">-10s</button>
                      <input type="range" class="form-range recording-seek-range" data-recording-range min="0" max="0" step="0.1" value="0">
                      <button type="button" class="btn btn-light border btn-sm" data-recording-jump="10">+10s</button>
                    </div>
                    <div class="recording-time-meta">
                      <span data-recording-current>00:00</span>
                      <span data-recording-duration>00:00</span>
                    </div>
                  </div>
                </div>

                <!-- AI Transcript Section -->
                <div id="qaAiTranscriptContainer" class="p-3 bg-white rounded-3 mt-3 border shadow-sm" style="display:none;">
                  <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-robot text-primary fs-5"></i>
                      <span class="fw-bold small text-dark">AI Call Transcript</span>
                      <span id="qaAiStatusBadge" class="badge bg-secondary-subtle text-secondary border">Loading...</span>
                    </div>
                  </div>

                  <div id="qaAiStatusAlert" class="alert alert-info py-2 px-3 small mb-2" style="display:none;"></div>

                  <ul class="nav nav-tabs nav-tabs-sm small mb-2" id="qaAiTranscriptTabs" role="tablist">
                    <li class="nav-item">
                      <button class="nav-link active py-1 px-2 small" id="tab-formatted-link" data-bs-toggle="tab" data-bs-target="#tab-formatted-pane" type="button">Formatted</button>
                    </li>
                    <li class="nav-item">
                      <button class="nav-link py-1 px-2 small" id="tab-raw-link" data-bs-toggle="tab" data-bs-target="#tab-raw-pane" type="button">Raw Transcript</button>
                    </li>
                    <li class="nav-item" id="tab-final-item" style="display:none;">
                      <button class="nav-link py-1 px-2 small" id="tab-final-link" data-bs-toggle="tab" data-bs-target="#tab-final-pane" type="button">Reviewed Final</button>
                    </li>
                  </ul>

                  <div class="tab-content" id="qaAiTranscriptTabContent">
                    <div class="tab-pane fade show active" id="tab-formatted-pane">
                      <div id="qaAiFormattedText" class="p-2 bg-light rounded font-monospace small text-dark" style="max-height: 220px; overflow-y: auto; white-space: pre-wrap; font-size: 0.8rem;"></div>
                    </div>
                    <div class="tab-pane fade" id="tab-raw-pane">
                      <div id="qaAiRawText" class="p-2 bg-light rounded font-monospace small text-secondary" style="max-height: 220px; overflow-y: auto; white-space: pre-wrap; font-size: 0.8rem;"></div>
                    </div>
                    <div class="tab-pane fade" id="tab-final-pane">
                      <textarea id="qaAiFinalTextarea" class="form-control font-monospace small" rows="7" style="font-size:0.8rem;"></textarea>
                    </div>
                  </div>

                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2 pt-2 border-top">
                    <div class="btn-group btn-group-sm">
                      <button type="button" class="btn btn-light border btn-sm" id="btnCopyTranscript" title="Copy Transcript">
                        <i class="bi bi-clipboard me-1"></i>Copy
                      </button>
                      <button type="button" class="btn btn-light border btn-sm" id="btnAutofillComment" title="Auto-fill into QA internal comment">
                        <i class="bi bi-box-arrow-in-down me-1"></i>Use for QA Note
                      </button>
                    </div>
                    <div class="btn-group btn-group-sm">
                      <button type="button" class="btn btn-light border btn-sm" id="btnReformatTranscript" title="Re-format using current AI settings">
                        <i class="bi bi-arrow-repeat me-1"></i>Re-Format
                      </button>
                      <button type="button" class="btn btn-outline-primary btn-sm" id="btnSaveFinalTranscript" title="Save Human Edit">
                        <i class="bi bi-check-lg me-1"></i>Save Human Edit
                      </button>
                      <button type="button" class="btn btn-outline-danger btn-sm" id="btnRetryTranscription" style="display:none;">
                        <i class="bi bi-arrow-clockwise me-1"></i>Retry
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-light border-top-0 py-3">
          <button type="button" class="btn btn-light border btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold">Save QA Decision</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const appBase = <?php echo json_encode($appBaseVal); ?>;
  const formatAudioTime = (seconds) => {
    const total = Number.isFinite(seconds) && seconds >= 0 ? Math.floor(seconds) : 0;
    const mins = Math.floor(total / 60);
    const secs = total % 60;
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  };
  const enhanceRecordingPlayers = (root) => {
    (root || document).querySelectorAll('[data-recording-player]').forEach((shell) => {
      if (shell.dataset.recordingEnhanced === '1') return;
      const audio = shell.querySelector('audio');
      const range = shell.querySelector('[data-recording-range]');
      const currentEl = shell.querySelector('[data-recording-current]');
      const durationEl = shell.querySelector('[data-recording-duration]');
      if (!audio || !range || !currentEl || !durationEl) return;
      shell.dataset.recordingEnhanced = '1';

      const syncDuration = () => {
        const duration = Number.isFinite(audio.duration) ? audio.duration : 0;
        range.max = duration > 0 ? String(duration) : '0';
        range.disabled = duration <= 0;
        durationEl.textContent = formatAudioTime(duration);
      };
      const syncCurrent = () => {
        const current = Number.isFinite(audio.currentTime) ? audio.currentTime : 0;
        range.value = String(current);
        currentEl.textContent = formatAudioTime(current);
      };

      audio.addEventListener('loadedmetadata', () => {
        syncDuration();
        syncCurrent();
      });
      audio.addEventListener('durationchange', syncDuration);
      audio.addEventListener('timeupdate', syncCurrent);
      audio.addEventListener('emptied', () => {
        range.value = '0';
        range.max = '0';
        range.disabled = true;
        currentEl.textContent = '00:00';
        durationEl.textContent = '00:00';
      });
      range.addEventListener('input', () => {
        if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;
        audio.currentTime = Number(range.value || 0);
        syncCurrent();
      });
      shell.querySelectorAll('[data-recording-jump]').forEach((btn) => {
        btn.addEventListener('click', () => {
          if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;
          const step = Number(btn.getAttribute('data-recording-jump') || 0);
          audio.currentTime = Math.min(audio.duration, Math.max(0, audio.currentTime + step));
          syncCurrent();
        });
      });

      syncDuration();
      syncCurrent();
    });
  };

  const recordingModal = document.getElementById('recordingModal');
  if (recordingModal) {
    recordingModal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      const name = button.getAttribute('data-lead-name') || '';
      const leadId = button.getAttribute('data-lead-id') || '';
      document.getElementById('recLeadName').textContent = name;
      
      const list = document.getElementById('recModalList');
      list.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
      
      fetch(appBase + '/modules/leads/details?id=' + encodeURIComponent(leadId) + '&format=json&only_recordings=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      })
        .then(r => {
          if (!r.ok) throw new Error('Network response was not ok');
          return r.json();
        })
        .then(data => {
          if (data.ok && Array.isArray(data.recordings)) {
            const recs = data.recordings;
            if (recs.length > 0) {
              list.innerHTML = recs.map((r, i) => `
                <div class="mb-3 p-2 border rounded bg-light">
                  <div class="recording-player-shell" data-recording-player>
                    <div class="recording-head">
                      <span class="fw-bold small">Recording #${i + 1}</span>
                      <a href="${r.dl || r.url}" download class="text-primary text-decoration-none small">Download</a>
                    </div>
                    <audio controls preload="metadata" class="recording-audio" src="${r.url}"></audio>
                    <div class="recording-seek-controls">
                      <button type="button" class="btn btn-light border btn-sm" data-recording-jump="-10">-10s</button>
                      <input type="range" class="form-range recording-seek-range" data-recording-range min="0" max="0" step="0.1" value="0">
                      <button type="button" class="btn btn-light border btn-sm" data-recording-jump="10">+10s</button>
                    </div>
                    <div class="recording-time-meta">
                      <span data-recording-current>00:00</span>
                      <span data-recording-duration>00:00</span>
                    </div>
                  </div>
                </div>
              `).join('');
              enhanceRecordingPlayers(list);
            } else {
              list.innerHTML = '<div class="text-muted small text-center py-3">No recordings found</div>';
            }
          } else {
            list.innerHTML = '<div class="text-danger small text-center py-3">Error loading recordings</div>';
          }
        })
        .catch(err => {
          console.error('Fetch error:', err);
          list.innerHTML = `<div class="text-danger small text-center py-3">Failed to load recordings: ${err.message}</div>`;
        });
    });

    recordingModal.addEventListener('hidden.bs.modal', function() {
      const audios = document.getElementById('recModalList').querySelectorAll('audio');
      audios.forEach(a => { a.pause(); a.src = ''; });
    });
  }

  // --- AI Call Transcript JS Module ---
  let qaAiPollingTimer = null;
  let currentQaLeadId = null;

  function stopQaAiPolling() {
    if (qaAiPollingTimer) {
      clearInterval(qaAiPollingTimer);
      qaAiPollingTimer = null;
    }
  }

  function updateQaAiStatusBadge(status) {
    const badge = document.getElementById('qaAiStatusBadge');
    if (!badge) return;
    badge.className = 'badge border ';
    switch (status) {
      case 'pending':
        badge.className += 'bg-warning-subtle text-warning border-warning';
        badge.textContent = 'Pending Queue';
        break;
      case 'transcribing':
        badge.className += 'bg-info-subtle text-info border-info';
        badge.textContent = 'Transcribing Audio...';
        break;
      case 'transcribed':
        badge.className += 'bg-info-subtle text-info border-info';
        badge.textContent = 'Transcribed';
        break;
      case 'formatting':
        badge.className += 'bg-primary-subtle text-primary border-primary';
        badge.textContent = 'Formatting with AI...';
        break;
      case 'completed':
        badge.className += 'bg-success-subtle text-success border-success';
        badge.textContent = 'Completed';
        break;
      case 'failed':
        badge.className += 'bg-danger-subtle text-danger border-danger';
        badge.textContent = 'Failed';
        break;
      default:
        badge.className += 'bg-secondary-subtle text-secondary';
        badge.textContent = status || 'Unknown';
        break;
    }
  }

  function loadQaAiTranscript(leadId) {
    currentQaLeadId = leadId;
    stopQaAiPolling();

    const container = document.getElementById('qaAiTranscriptContainer');
    const alertEl = document.getElementById('qaAiStatusAlert');
    const formattedEl = document.getElementById('qaAiFormattedText');
    const rawEl = document.getElementById('qaAiRawText');
    const finalEl = document.getElementById('qaAiFinalTextarea');
    const finalTabItem = document.getElementById('tab-final-item');
    const btnRetry = document.getElementById('btnRetryTranscription');
    const csrfToken = document.querySelector('#qaModal input[name="csrf_token"]')?.value || '';

    if (!container) return;

    container.style.display = 'block';
    updateQaAiStatusBadge('loading');
    alertEl.style.display = 'none';
    formattedEl.textContent = 'Loading transcript data...';
    rawEl.textContent = 'Loading raw speech...';
    finalEl.value = '';
    if (finalTabItem) finalTabItem.style.display = 'none';
    if (btnRetry) btnRetry.style.display = 'none';

    function fetchTranscript() {
      if (!currentQaLeadId) return;

      fetch('transcript_action', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          action: 'get_transcript',
          lead_id: currentQaLeadId,
          csrf_token: csrfToken
        })
      })
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          if (data.error && data.error.toLowerCase().includes('no transcript record')) {
            container.style.display = 'none';
          } else {
            container.style.display = 'block';
            updateQaAiStatusBadge('error');
            alertEl.className = 'alert alert-warning py-2 px-3 small mb-2';
            alertEl.textContent = data.error || 'Unable to load transcript.';
            alertEl.style.display = 'block';
            formattedEl.textContent = 'No transcript available.';
            rawEl.textContent = 'No raw transcript available.';
          }
          stopQaAiPolling();
          return;
        }

        container.style.display = 'block';
        const status = data.status || 'pending';
        updateQaAiStatusBadge(status);

        if (data.raw_transcript) rawEl.textContent = data.raw_transcript;
        if (data.formatted_transcript) formattedEl.textContent = data.formatted_transcript;

        if (data.final_transcript) {
          finalEl.value = data.final_transcript;
          if (finalTabItem) finalTabItem.style.display = 'inline-block';
        } else if (data.formatted_transcript) {
          finalEl.value = data.formatted_transcript;
          if (finalTabItem) finalTabItem.style.display = 'inline-block';
        }

        if (status === 'completed') {
          stopQaAiPolling();
          alertEl.style.display = 'none';
          if (btnRetry) btnRetry.style.display = 'none';
        } else if (status === 'failed') {
          stopQaAiPolling();
          alertEl.className = 'alert alert-danger py-2 px-3 small mb-2';
          alertEl.textContent = 'Transcription process failed: ' + (data.error_message || 'Processing error');
          alertEl.style.display = 'block';
          if (btnRetry) btnRetry.style.display = 'inline-block';
        } else {
          // In progress: pending, transcribing, transcribed, formatting
          alertEl.className = 'alert alert-info py-2 px-3 small mb-2';
          alertEl.textContent = 'AI processing is currently in progress (' + status + '). Updates will display automatically...';
          alertEl.style.display = 'block';

          if (!qaAiPollingTimer) {
            qaAiPollingTimer = setInterval(fetchTranscript, 3000);
          }
        }
      })
      .catch(err => {
        console.error('Transcript fetch error:', err);
      });
    }

    fetchTranscript();
  }

  // Bind Action Buttons
  const btnCopy = document.getElementById('btnCopyTranscript');
  if (btnCopy) {
    btnCopy.addEventListener('click', function() {
      const activeTab = document.querySelector('#qaAiTranscriptTabs .nav-link.active');
      let textToCopy = '';
      if (activeTab && activeTab.id === 'tab-raw-link') {
        textToCopy = document.getElementById('qaAiRawText')?.textContent || '';
      } else if (activeTab && activeTab.id === 'tab-final-link') {
        textToCopy = document.getElementById('qaAiFinalTextarea')?.value || '';
      } else {
        textToCopy = document.getElementById('qaAiFormattedText')?.textContent || '';
      }

      if (!textToCopy) return;
      navigator.clipboard.writeText(textToCopy).then(() => {
        const origHtml = btnCopy.innerHTML;
        btnCopy.innerHTML = '<i class="bi bi-check2 text-success me-1"></i>Copied!';
        setTimeout(() => { btnCopy.innerHTML = origHtml; }, 2000);
      }).catch(err => {
        console.error('Copy error:', err);
      });
    });
  }

  const btnAutofill = document.getElementById('btnAutofillComment');
  if (btnAutofill) {
    btnAutofill.addEventListener('click', function() {
      const finalVal = document.getElementById('qaAiFinalTextarea')?.value;
      const formattedVal = document.getElementById('qaAiFormattedText')?.textContent;
      const textToUse = (finalVal && finalVal.trim() !== '') ? finalVal : formattedVal;

      if (!textToUse) return;
      const commentInput = document.getElementById('qa_comment_internal');
      if (commentInput) {
        if (commentInput.value.trim() !== '') {
          commentInput.value += "\n\n--- AI Call Transcript ---\n" + textToUse;
        } else {
          commentInput.value = textToUse;
        }
        const origHtml = btnAutofill.innerHTML;
        btnAutofill.innerHTML = '<i class="bi bi-check2 text-success me-1"></i>Added to QA Note!';
        setTimeout(() => { btnAutofill.innerHTML = origHtml; }, 2000);
      }
    });
  }

  const btnReformat = document.getElementById('btnReformatTranscript');
  if (btnReformat) {
    btnReformat.addEventListener('click', function() {
      if (!currentQaLeadId) return;
      const csrfToken = document.querySelector('#qaModal input[name="csrf_token"]')?.value || '';
      const origHtml = btnReformat.innerHTML;
      btnReformat.disabled = true;
      btnReformat.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Formatting...';

      fetch('transcript_action', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          action: 'reformat',
          lead_id: currentQaLeadId,
          csrf_token: csrfToken
        })
      })
      .then(r => r.json())
      .then(data => {
        btnReformat.disabled = false;
        btnReformat.innerHTML = origHtml;
        if (data.success) {
          document.getElementById('qaAiFormattedText').textContent = data.formatted_transcript || '';
          updateQaAiStatusBadge('completed');
          const alertEl = document.getElementById('qaAiStatusAlert');
          alertEl.className = 'alert alert-success py-2 px-3 small mb-2';
          alertEl.textContent = 'Transcript successfully re-formatted with latest AI settings!';
          alertEl.style.display = 'block';
        } else {
          alert('Re-format failed: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        btnReformat.disabled = false;
        btnReformat.innerHTML = origHtml;
        alert('Network error during re-formatting.');
      });
    });
  }

  const btnSaveFinal = document.getElementById('btnSaveFinalTranscript');
  if (btnSaveFinal) {
    btnSaveFinal.addEventListener('click', function() {
      if (!currentQaLeadId) return;
      const finalVal = document.getElementById('qaAiFinalTextarea')?.value || '';
      const csrfToken = document.querySelector('#qaModal input[name="csrf_token"]')?.value || '';
      const origHtml = btnSaveFinal.innerHTML;
      btnSaveFinal.disabled = true;
      btnSaveFinal.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

      fetch('transcript_action', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          action: 'save_final',
          lead_id: currentQaLeadId,
          final_transcript: finalVal,
          csrf_token: csrfToken
        })
      })
      .then(r => r.json())
      .then(data => {
        btnSaveFinal.disabled = false;
        btnSaveFinal.innerHTML = origHtml;
        if (data.success) {
          const origText = btnSaveFinal.innerHTML;
          btnSaveFinal.innerHTML = '<i class="bi bi-check2 text-success me-1"></i>Saved!';
          setTimeout(() => { btnSaveFinal.innerHTML = origText; }, 2000);
        } else {
          alert('Failed to save human edit: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        btnSaveFinal.disabled = false;
        btnSaveFinal.innerHTML = origHtml;
        alert('Network error saving edit.');
      });
    });
  }

  const btnRetry = document.getElementById('btnRetryTranscription');
  if (btnRetry) {
    btnRetry.addEventListener('click', function() {
      if (!currentQaLeadId) return;
      const csrfToken = document.querySelector('#qaModal input[name="csrf_token"]')?.value || '';
      btnRetry.disabled = true;

      fetch('transcript_action', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          action: 'retry',
          lead_id: currentQaLeadId,
          csrf_token: csrfToken
        })
      })
      .then(r => r.json())
      .then(data => {
        btnRetry.disabled = false;
        if (data.success) {
          loadQaAiTranscript(currentQaLeadId);
        } else {
          alert('Retry failed: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        btnRetry.disabled = false;
        alert('Network error triggering retry.');
      });
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
      const recInline = button.getAttribute('data-recording-inline') || button.getAttribute('data-recording') || '';
      const recDl = button.getAttribute('data-recording-dl') || recInline;

      document.getElementById('qa_lead_id').value = leadId;
      document.getElementById('qa_lead_name').textContent = leadName;
      document.getElementById('qa_status_select').value = qaStatus;
      document.getElementById('client_delivery_status_select').value = cds;
      document.getElementById('qa_comment_internal').value = qaComment;
      document.getElementById('qa_comment_client').value = qaClientComment;

      const detailsContainer = document.getElementById('qa_lead_details_container');
      if (detailsContainer && leadId) {
        detailsContainer.innerHTML = `
          <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <div class="mt-2 text-muted small">Fetching lead details...</div>
          </div>
        `;
        fetch(appBase + '/modules/leads/details?id=' + encodeURIComponent(leadId) + '&format=html&embed=1', {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        })
          .then(r => {
            if (!r.ok) throw new Error('Network response was not ok');
            return r.text();
          })
          .then(html => { detailsContainer.innerHTML = html; })
          .catch(() => { detailsContainer.innerHTML = '<div class="alert alert-danger m-3 small">Failed to load lead details.</div>'; });
      }

      const wrap = document.getElementById('qaRecordingContainer');
      if (wrap) {
        if (recInline && recInline !== 'null') {
          wrap.style.display = '';
          document.getElementById('qaAudioPlayer').src = recInline;
          document.getElementById('qaDownloadLink').href = recDl;
          enhanceRecordingPlayers(wrap);
        } else {
          wrap.style.display = 'none';
          document.getElementById('qaAudioPlayer').src = '';
          document.getElementById('qaDownloadLink').href = '#';
        }
      }

      // Load AI Transcript for lead
      if (leadId) {
        loadQaAiTranscript(leadId);
      }
    });

    qaModal.addEventListener('hidden.bs.modal', function() {
      const ap = document.getElementById('qaAudioPlayer');
      if (ap) { ap.pause(); ap.src = ''; }
      document.getElementById('qa_lead_details_container').innerHTML = '';
      stopQaAiPolling();
    });
  }
});
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
