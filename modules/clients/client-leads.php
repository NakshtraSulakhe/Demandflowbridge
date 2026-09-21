<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','client_admin','client_sdr']);
ensureCsrfToken();

$user = getCurrentUser();
$clientId = (int)($user['client_id'] ?? 0);
if (isAdmin()) {
    $clientId = (int)($_GET['client_id'] ?? $clientId);
}
if ($clientId <= 0 && !isAdmin()) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    $_SESSION['access_denied_context'] = [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'required_roles' => ['admin','client_admin','client_sdr'],
        'time' => time(),
    ];
    header("Location: " . appBasePath() . "/modules/auth/access-denied");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array((string)$_POST['action'], ['get_tagging','add_tag','remove_tag','edit_tag_activity'], true)) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid token']); exit;
    }

    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid lead']); exit; }

    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT l.id, l.campaign_id, l.qa_status, l.client_delivery_status
        FROM leads l
        WHERE l.id = ?
        LIMIT 1
    ");
    if (!$stmt) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $leadRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$leadRow || !canUserAccessLead($leadRow, ['allow_client_pending' => false])) {
        echo json_encode(['ok' => false, 'error' => 'Not found']); exit;
    }

    $cds = normalizeClientDeliveryStatus((string)($leadRow['client_delivery_status'] ?? 'Pending'));
    if (!isAdmin() && $cds !== 'Delivered') {
        echo json_encode(['ok' => false, 'error' => 'Tagging allowed only for delivered leads']); exit;
    }

    $action = (string)$_POST['action'];
    $userId = (int)($user['id'] ?? 0);
    $canRemove = isAdmin() || isClientAdmin();
    $canEditAll = isAdmin() || isClientAdmin();

    if ($action === 'add_tag') {
        $tagName = trim((string)($_POST['tag_name'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $stage = trim((string)($_POST['stage'] ?? ''));
        if ($tagName === '') { echo json_encode(['ok' => false, 'error' => 'Tag is required']); exit; }
        $names = array_values(array_filter(array_map('trim', preg_split('/[,]+/', $tagName)), fn($v) => $v !== ''));
        if (empty($names)) { echo json_encode(['ok' => false, 'error' => 'Tag is required']); exit; }
        $ok = true;
        foreach ($names as $n) {
            $ok = addTagToLead($leadId, $n, $userId, $note, $stage) && $ok;
        }
        $editableId = 0;
        $stmtE = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($stmtE) { $stmtE->bind_param('ii', $leadId, $userId); $stmtE->execute(); $editableId = (int)($stmtE->get_result()->fetch_row()[0] ?? 0); $stmtE->close(); }
        echo json_encode(['ok' => $ok, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $editableId, 'can_edit_all' => $canEditAll]); exit;
    }

    if ($action === 'remove_tag') {
        if (!$canRemove) { echo json_encode(['ok' => false, 'error' => 'Not allowed']); exit; }
        $tagIdToRemove = (int)($_POST['tag_id'] ?? 0);
        if ($tagIdToRemove <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid tag']); exit; }
        $ok = removeTagFromLead($leadId, $tagIdToRemove, $userId);
        $editableId = 0;
        $stmtE = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($stmtE) { $stmtE->bind_param('ii', $leadId, $userId); $stmtE->execute(); $editableId = (int)($stmtE->get_result()->fetch_row()[0] ?? 0); $stmtE->close(); }
        echo json_encode(['ok' => $ok, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $editableId, 'can_edit_all' => $canEditAll]); exit;
    }

    if ($action === 'edit_tag_activity') {
        $activityId = (int)($_POST['activity_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $stage = trim((string)($_POST['stage'] ?? ''));
        if ($activityId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid activity']); exit; }

        $stmtA = $conn->prepare("SELECT id, actor_id, action, meta_json FROM lead_activity WHERE id = ? AND lead_id = ? LIMIT 1");
        if (!$stmtA) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmtA->bind_param('ii', $activityId, $leadId);
        $stmtA->execute();
        $aRow = $stmtA->get_result()->fetch_assoc();
        $stmtA->close();
        if (!$aRow || (string)($aRow['action'] ?? '') !== 'lead_tag_added') { echo json_encode(['ok' => false, 'error' => 'Not editable']); exit; }

        $actorId = (int)($aRow['actor_id'] ?? 0);
        if (!$canEditAll && $actorId !== $userId) { echo json_encode(['ok' => false, 'error' => 'Not allowed']); exit; }

        $latestId = 0;
        if ($canEditAll) {
            $stmtL = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' ORDER BY created_at DESC, id DESC LIMIT 1");
            if ($stmtL) { $stmtL->bind_param('i', $leadId); $stmtL->execute(); $latestId = (int)($stmtL->get_result()->fetch_row()[0] ?? 0); $stmtL->close(); }
        } else {
            $stmtL = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
            if ($stmtL) { $stmtL->bind_param('ii', $leadId, $userId); $stmtL->execute(); $latestId = (int)($stmtL->get_result()->fetch_row()[0] ?? 0); $stmtL->close(); }
        }
        if ($latestId <= 0 || $latestId !== $activityId) { echo json_encode(['ok' => false, 'error' => 'Only last tag note can be edited']); exit; }

        $meta = !empty($aRow['meta_json']) ? json_decode((string)$aRow['meta_json'], true) : [];
        if (!is_array($meta)) $meta = [];
        $meta['note'] = $note;
        $meta['stage'] = $stage;
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) $metaJson = null;

        $stmtU = $conn->prepare("UPDATE lead_activity SET meta_json = ? WHERE id = ? LIMIT 1");
        if (!$stmtU) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmtU->bind_param('si', $metaJson, $activityId);
        $ok = $stmtU->execute();
        $stmtU->close();
        if ($ok) {
            $tagName = (string)($meta['tag'] ?? '');
            logLeadActivity($leadId, $userId, 'lead_tag_edited', ['tag' => $tagName, 'note' => $note, 'stage' => $stage, 'activity_id' => $activityId]);
            
            // Sync Stage with Client Delivery Status if it matches one of the valid statuses
            if ($stage !== '') {
                $validStatuses = getClientDeliveryStatuses();
                $isMatch = false;
                $normalizedStage = $stage;
                foreach ($validStatuses as $vs) {
                    if (strtolower($stage) === strtolower($vs)) {
                        $isMatch = true;
                        $normalizedStage = $vs;
                        break;
                    }
                }
                
                if ($isMatch) {
                    $updatedAtUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');
                    $stmtS = $conn->prepare("UPDATE leads SET client_delivery_status = ?, updated_at_utc = ?, updated_at_source = 'client_stage_edit' WHERE id = ?");
                    if ($stmtS) {
                        $stmtS->bind_param('ssi', $normalizedStage, $updatedAtUtc, $leadId);
                        if ($stmtS->execute()) {
                            logLeadActivity($leadId, $userId, 'qa_updated', [
                                'client_delivery_status' => $normalizedStage,
                                'qa_client_comment' => 'Status updated via Stage edit (' . $stage . ')'
                            ]);
                        }
                        $stmtS->close();
                    }
                }
            }
        }
        echo json_encode(['ok' => $ok, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $latestId, 'can_edit_all' => $canEditAll]); exit;
    }

    $editableId = 0;
    $stmtE = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    if ($stmtE) { $stmtE->bind_param('ii', $leadId, $userId); $stmtE->execute(); $editableId = (int)($stmtE->get_result()->fetch_row()[0] ?? 0); $stmtE->close(); }
    echo json_encode(['ok' => true, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $editableId, 'can_edit_all' => $canEditAll]); exit;
}

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, (int)($_GET['per_page'] ?? 25));
$perPage = min(500, $perPage);
$tagId = (int)($_GET['tag_id'] ?? 0);
$campaignIds = getClientCampaignIdsForClient($clientId);

$filters = [];
if (!empty($campaignIds)) $filters['campaign_ids'] = $campaignIds;
if ($campaignId > 0) $filters['campaign_id'] = $campaignId;
if ($q !== '') $filters['search'] = $q;
$filters['client_delivery_status'] = 'Delivered';
if ($tagId > 0) $filters['tag_id'] = $tagId;

$data = getLeads($filters, $perPage, $page);
$rows = $data['leads'] ?? [];
$total = (int)($data['total'] ?? 0);
$pages = (int)($data['totalPages'] ?? 1);
$offset = ($page - 1) * $perPage;

$conn = getDbConnection();
$campaigns = [];
if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $stmt = $conn->prepare("SELECT c.id, c.name, d.client_code, d.code FROM campaigns c JOIN campaign_details d ON d.campaign_id=c.id WHERE c.id IN ($in) ORDER BY c.name");
    if ($stmt) {
        mysqliBindParams($stmt, str_repeat('i', count($campaignIds)), $campaignIds);
        $stmt->execute();
        $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

ensureLeadTagsSchema();
$leadTagsMap = [];
if (!empty($rows)) {
    $leadIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows), fn($v) => $v > 0));
    if (!empty($leadIds)) {
        $in = implode(',', array_fill(0, count($leadIds), '?'));
        $types = str_repeat('i', count($leadIds));
        $stmt = $conn->prepare("SELECT lt.lead_id, t.name FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id WHERE lt.lead_id IN ($in) ORDER BY lt.added_at DESC");
        if ($stmt) {
            mysqliBindParams($stmt, $types, $leadIds);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $lid = (int)($r['lead_id'] ?? 0);
                $name = trim((string)($r['name'] ?? ''));
                if ($lid <= 0 || $name === '') continue;
                if (!isset($leadTagsMap[$lid])) $leadTagsMap[$lid] = [];
                if (!in_array($name, $leadTagsMap[$lid], true)) $leadTagsMap[$lid][] = $name;
            }
            $stmt->close();
        }
    }
}

ensureLeadTagsSchema();
$tagOptions = [];
if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $stmt = $conn->prepare("SELECT DISTINCT t.id, t.name FROM tags t JOIN lead_tags lt ON lt.tag_id = t.id JOIN leads l ON l.id = lt.lead_id WHERE l.campaign_id IN ($in) ORDER BY t.name");
    if ($stmt) {
        mysqliBindParams($stmt, str_repeat('i', count($campaignIds)), $campaignIds);
        $stmt->execute();
        $tagOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$pageTitle = 'Client Leads';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Client Leads</div>
      <div class="text-muted small">Leads visible to your account</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="client-campaigns.php"><i class="bi bi-megaphone me-1"></i>Campaigns</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <?php if (isAdmin()): ?>
          <div class="col-md-3">
            <label class="form-label small text-muted">Client ID</label>
            <input class="form-control form-control-sm" name="client_id" value="<?php echo (int)$clientId; ?>">
          </div>
        <?php endif; ?>
        <div class="col-md-4">
          <label class="form-label small text-muted">Campaign</label>
          <select class="form-select form-select-sm" name="campaign_id">
            <option value="">All</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$campaignId === (int)$c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">Tag</label>
          <select class="form-select form-select-sm" name="tag_id">
            <option value="">All</option>
            <?php foreach ($tagOptions as $t): ?>
              <option value="<?php echo (int)$t['id']; ?>" <?php echo ($tagId === (int)$t['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name'] ?? ''); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="ID, email, company, name">
        </div>
        <div class="col-md-1">
          <label class="form-label small text-muted">Rows</label>
          <select class="form-select form-select-sm" name="per_page">
            <?php foreach ([10,25,50,100,250,500] as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-grid">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
        <div class="col-md-1 d-grid">
          <a class="btn btn-light border btn-sm" href="client-leads.php">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
      <div class="fw-semibold">Leads</div>
      <div class="text-muted small">
        <?php
          $from = $total > 0 ? ($offset + 1) : 0;
          $to = min($total, $offset + count($rows));
        ?>
        Showing <?php echo (int)$from; ?>–<?php echo (int)$to; ?> of <?php echo (int)$total; ?>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">#</th>
              <th>Date</th>
              <th>Lead</th>
              <th>Email</th>
              <th>Company</th>
              <th>Campaign</th>
              <th>Delivery</th>
              <th>Tags</th>
              <th>Assigned SDR</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="10" class="text-center text-muted py-4">No leads found.</td></tr>
            <?php else: foreach ($rows as $idx => $r): ?>
              <?php
                $lid = (int)($r['id'] ?? 0);
                $tags = $lid > 0 ? ($leadTagsMap[$lid] ?? []) : [];
                $maxChips = 3;
                $shown = array_slice($tags, 0, $maxChips);
                $more = max(0, count($tags) - count($shown));
              ?>
              <tr>
                <td class="ps-3 text-muted small"><?php echo (int)($offset + (int)$idx + 1); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(substr((string)($r['created_at'] ?? ''), 0, 10)); ?></td>
                <td class="fw-semibold"><?php echo htmlspecialchars(((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')) ?: '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['company_name'] ?? '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['campaign_name'] ?? '—'); ?></td>
                <td>
                  <?php 
                    $st = normalizeClientDeliveryStatus((string)($r['client_delivery_status'] ?? 'Delivered'));
                    $cls = 'bg-success-subtle text-success';
                    if ($st === 'Rejected') $cls = 'bg-danger-subtle text-danger';
                    if ($st === 'TBD(To be discussed)') $cls = 'bg-warning-subtle text-warning';
                    if ($st === 'In Progress') $cls = 'bg-info-subtle text-info';
                  ?>
                  <span class="badge <?php echo $cls; ?> border"><?php echo htmlspecialchars($st); ?></span>
                </td>
                <td>
                  <?php if (empty($shown)): ?>
                    <span class="text-muted small">—</span>
                  <?php else: ?>
                    <div class="d-flex flex-wrap gap-1">
                      <?php foreach ($shown as $tname): ?>
                        <span class="badge rounded-pill bg-light text-dark border"><?php echo htmlspecialchars($tname); ?></span>
                      <?php endforeach; ?>
                      <?php if ($more > 0): ?>
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary border">+<?php echo (int)$more; ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <?php
                  $assignedName = (string)($r['assigned_to_name'] ?? '');
                  $assignedRole = (string)($r['assigned_to_role'] ?? '');
                  $assignedShow = ($assignedName !== '' && ($assignedRole === 'client_sdr' || $assignedRole === 'client_admin')) ? $assignedName : '—';
                ?>
                <td class="text-muted small"><?php echo htmlspecialchars($assignedShow); ?></td>
                <td class="text-end pe-3">
                  <div class="d-flex justify-content-end gap-1">
                    <button class="btn btn-outline-primary btn-xs" type="button"
                      data-tag-lead="1"
                      data-lead-id="<?php echo (int)($r['id'] ?? 0); ?>"
                      data-company="<?php echo htmlspecialchars($r['company_name'] ?? ''); ?>"
                      data-person="<?php echo htmlspecialchars(trim(((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')))); ?>"
                      title="Tag">
                      <i class="bi bi-tags"></i>
                    </button>
                    <a class="btn btn-outline-secondary btn-xs" href="client-lead-details.php?id=<?php echo (int)($r['id'] ?? 0); ?>" title="View Lead"><i class="bi bi-eye"></i></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-light d-flex justify-content-between align-items-center">
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php
            $base = '?client_id='.(int)$clientId.'&campaign_id='.(int)$campaignId.'&tag_id='.(int)$tagId.'&q='.urlencode($q).'&per_page='.(int)$perPage;
            $startPg = max(1, $page - 2);
            $endPg = min($pages, $page + 2);
          ?>
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=1">First</a></li>
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo max(1, $page-1); ?>">Prev</a></li>
          <?php for ($p = $startPg; $p <= $endPg; $p++): ?>
            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo (int)$p; ?>"><?php echo (int)$p; ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo min($pages, $page+1); ?>">Next</a></li>
          <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo (int)$pages; ?>">Last</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<style>
  #tagLeadCurrent .tag-chip {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    padding: 0.25rem 0.75rem;
    border-radius: 50rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    transition: all 0.2s;
  }
  #tagLeadCurrent .tag-chip:hover {
    background: #e9ecef;
    border-color: #adb5bd;
  }
  #tagLeadTimeline tr:last-child {
    border-bottom: none;
  }
  .timeline-badge {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 10px;
    position: relative;
  }
  .timeline-badge::after {
    content: '';
    position: absolute;
    top: 10px;
    left: 4px;
    width: 2px;
    height: 100px;
    background: #f1f3f5;
    z-index: -1;
  }
  #tagLeadTimeline tr:last-child .timeline-badge::after {
    display: none;
  }
  #tagLeadModal .input-group-lg > .form-select {
    background-color: transparent;
    border: 0;
    box-shadow: none;
    padding-left: 0;
    min-height: 48px;
  }
  #tagLeadModal .input-group-lg > .form-select:focus {
    box-shadow: none;
  }
</style>

<div class="modal fade" id="tagLeadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden;">
      <div class="modal-header bg-white border-0 pt-4 px-4">
        <div class="d-flex align-items-center">
          <div class="bg-primary bg-opacity-10 p-3 rounded-4 me-3 text-primary">
            <i class="bi bi-person-badge fs-3"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold text-dark mb-0">Engagement Intelligence</h5>
            <div class="text-muted small d-flex align-items-center mt-1" id="tagLeadSubtitle"></div>
          </div>
        </div>
        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="alert alert-danger d-none mb-4 rounded-3 border-0 shadow-sm" id="tagLeadError"></div>
        
        <div class="row g-4">
          <!-- Left Column: Actions -->
          <div class="col-lg-5">
            <div class="card border-0 bg-white rounded-4 shadow-sm mb-4">
              <div class="card-body p-4">
                <h6 class="fw-bold text-dark mb-4 d-flex align-items-center">
                  <span class="badge bg-primary rounded-pill me-2" style="width: 24px; height: 24px; padding: 5px;">1</span>
                  Add New Engagement
                </h6>
                
                <!-- Tag -->
                <div class="mb-4">
                  <label class="form-label x-small fw-bold text-uppercase text-muted tracking-wider">Tag</label>
                  <div class="input-group input-group-lg border rounded-3 overflow-hidden bg-light shadow-none">
                    <span class="input-group-text bg-transparent border-0"><i class="bi bi-tag text-muted"></i></span>
                    <select class="form-select bg-transparent border-0 ps-0 shadow-none" style="font-size: 1rem;" id="tagLeadInput">
                      <option value="">Select tag</option>
                      <option>New</option>
                      <option>Contacted</option>
                      <option>Follow Up</option>
                      <option>Meeting Booked</option>
                      <option>No Response</option>
                      <option>Wrong Contact</option>
                      <option>Not Interested</option>
                    </select>
                    <button class="btn btn-primary px-4" type="button" id="tagLeadAddBtn">
                      <i class="bi bi-send-fill"></i>
                    </button>
                  </div>
                </div>

                <!-- Note -->
                <div class="mb-4">
                  <label class="form-label x-small fw-bold text-uppercase text-muted tracking-wider">Internal Note</label>
                  <textarea class="form-control border-0 bg-light rounded-3 px-3 py-2 shadow-none" id="tagLeadNote" rows="3" placeholder="What happened during this engagement?"></textarea>
                </div>

                <!-- Lead Status -->
                <div class="mb-4">
                  <label class="form-label x-small fw-bold text-uppercase text-muted tracking-wider">Lead Status</label>
                  <select class="form-select border-0 bg-light rounded-3 px-3 py-2 shadow-none" id="tagLeadStage">
                    <option value="">Select lead status</option>
                    <option>Accepted</option>
                    <option>Rejected</option>
                    <option>In Progress</option>
                    <option>TBD(To be discussed)</option>
                  </select>
                </div>

                <!-- Quick Presets -->
                <div class="mb-0">
                  <label class="form-label x-small fw-bold text-uppercase text-muted tracking-wider mb-3">Quick Actions</label>
                  <div class="d-flex flex-wrap gap-2" id="tagLeadPresets"></div>
                </div>
              </div>
            </div>

            <!-- Current Tags (No scrollbar, chip based) -->
            <div class="card border-0 bg-white rounded-4 shadow-sm">
              <div class="card-body p-4">
                <h6 class="fw-bold text-dark mb-3">Assigned Tags</h6>
                <div class="d-flex flex-wrap gap-2" id="tagLeadCurrent"></div>
              </div>
            </div>
          </div>

          <!-- Right Column: Timeline -->
          <div class="col-lg-7">
            <div class="card border-0 bg-white rounded-4 shadow-sm h-100 overflow-hidden">
              <div class="card-header bg-white border-0 py-4 px-4 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0">
                  <i class="bi bi-clock-history me-2 text-primary"></i>History
                </h6>
                <button class="btn btn-primary btn-sm rounded-pill px-3 d-none" id="btnToggleEditBox" style="font-size: 0.75rem;">
                  <i class="bi bi-pencil-square me-1"></i>Edit Last
                </button>
              </div>
              <div class="card-body p-0">
                <!-- Highlighted Edit Box -->
                <div class="px-4 py-3 bg-primary bg-opacity-5 border-top border-bottom d-none" id="tagLeadEditBox">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3">Editing Last Action</span>
                    <button type="button" class="btn-close small" id="btnCloseEditBox" style="font-size: 0.6rem;"></button>
                  </div>
                  <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                      <select class="form-select form-select-sm border-0 bg-white rounded-3 shadow-sm" id="tagLeadEditStage">
                        <option value="">Update Lead Status</option>
                        <option>Accepted</option>
                        <option>Rejected</option>
                        <option>In Progress</option>
                        <option>TBD(To be discussed)</option>
                      </select>
                    </div>
                    <div class="col-md-7">
                      <div class="input-group input-group-sm shadow-sm">
                        <input class="form-control border-0 rounded-start-3" id="tagLeadEditNote" placeholder="Update note content...">
                        <button class="btn btn-primary px-3 rounded-end-3" type="button" id="tagLeadEditSave">Save</button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Timeline Table -->
                <div class="table-responsive">
                  <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                      <tr style="border-bottom: 2px solid #f1f3f5;">
                        <th class="ps-4 py-3 border-0 text-uppercase x-small fw-bold text-muted">Date</th>
                        <th class="py-3 border-0 text-uppercase x-small fw-bold text-muted">Activity</th>
                        <th class="py-3 border-0 text-uppercase x-small fw-bold text-muted">Lead Status</th>
                        <th class="py-3 border-0 text-uppercase x-small fw-bold text-muted">Tag</th>
                        <th class="py-3 border-0 text-uppercase x-small fw-bold text-muted">Internal Note</th>
                        <th class="pe-4 py-3 border-0 text-uppercase x-small fw-bold text-muted text-end">By</th>
                      </tr>
                    </thead>
                    <tbody id="tagLeadTimeline"></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button type="button" class="btn btn-light px-4 rounded-3 border-0" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const modalEl = document.getElementById('tagLeadModal');
  if (!modalEl || !window.bootstrap) return;

  const csrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const canRemoveDefault = <?php echo json_encode((bool)(isAdmin() || isClientAdmin())); ?>;
  const subtitleEl = document.getElementById('tagLeadSubtitle');
  const errEl = document.getElementById('tagLeadError');
  const currentEl = document.getElementById('tagLeadCurrent');
  const timelineEl = document.getElementById('tagLeadTimeline');
  const inputEl = document.getElementById('tagLeadInput');
  const stageEl = document.getElementById('tagLeadStage');
  const noteEl = document.getElementById('tagLeadNote');
  const addBtn = document.getElementById('tagLeadAddBtn');
  const presetsEl = document.getElementById('tagLeadPresets');
  const editBoxEl = document.getElementById('tagLeadEditBox');
  const btnToggleEditBox = document.getElementById('btnToggleEditBox');
  const btnCloseEditBox = document.getElementById('btnCloseEditBox');
  const editStageEl = document.getElementById('tagLeadEditStage');
  const editNoteEl = document.getElementById('tagLeadEditNote');
  const editSaveEl = document.getElementById('tagLeadEditSave');

  let activeLeadId = 0;
  let canRemove = canRemoveDefault;
  let editableActivityId = 0;
  let canEditAll = false;
  const currentUserId = <?php echo json_encode((int)($user['id'] ?? 0)); ?>;

  const esc = (s) => String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const fmt = (dt) => {
    const s = String(dt || '');
    if (!s) return '—';
    return s.replace('T', ' ').slice(0, 16);
  };

  function showErr(msg) {
    errEl.textContent = msg || 'Error';
    errEl.classList.remove('d-none');
  }
  function hideErr() {
    errEl.textContent = '';
    errEl.classList.add('d-none');
  }

  function renderCurrent(rows) {
    if (!Array.isArray(rows) || !rows.length) {
      currentEl.innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-info-circle me-1"></i> No tags assigned yet.</div>';
      return;
    }
    const html = rows.map(r => {
      const name = esc(r.tag_name);
      const role = String(r.added_by_role || '');
      const by = role.startsWith('client_') ? esc(r.added_by_name || 'System') : 'TaRaj Global Solutions';
      const when = fmt(r.added_at);
      const btn = canRemove ? `<button class="btn btn-link text-danger p-0" data-remove-tag="${Number(r.tag_id)}" title="Remove Tag"><i class="bi bi-trash"></i></button>` : '';
      return `<div class="d-flex justify-content-between align-items-center bg-white border rounded-3 px-3 py-2 mb-2 shadow-xs">
        <div>
          <div class="fw-bold text-dark" style="font-size: 0.9rem;">${name}</div>
          <div class="text-muted small" style="font-size: 0.75rem;">by ${by} · ${esc(when)}</div>
        </div>
        <div>${btn}</div>
      </div>`;
    }).join('');
    currentEl.innerHTML = html;
  }

  function renderTimeline(rows) {
    if (!Array.isArray(rows) || !rows.length) {
      timelineEl.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">No engagement history found.</td></tr>';
      return;
    }
    timelineEl.innerHTML = rows.map(r => {
      const act = String(r.action || '');
      let label = act;
      let labelCls = 'bg-secondary';
      if (act === 'lead_tag_added') { label = 'Tag Added'; labelCls = 'bg-success'; }
      else if (act === 'lead_tag_removed') { label = 'Tag Removed'; labelCls = 'bg-danger'; }
      else if (act === 'lead_tag_edited') { label = 'Edited'; labelCls = 'bg-info'; }

      const stage = esc((r.meta && r.meta.stage) || '');
      const tag = esc((r.meta && (r.meta.tag || r.meta.tag_name)) || '');
      const note = esc((r.meta && (r.meta.note || r.meta.comment)) || '');
      const ar = String(r.actor_role || '');
      const who = ar.startsWith('client_') ? esc(r.actor_name || 'System') : 'TaRaj Global Solutions';
      
      return `<tr>
        <td class="ps-4 text-muted small">${esc(fmt(r.created_at))}</td>
        <td><span class="badge ${labelCls} bg-opacity-10 text-${labelCls.replace('bg-', '')} border border-${labelCls.replace('bg-', '')} small px-2 py-1" style="font-size: 0.7rem;">${esc(label)}</span></td>
        <td class="small fw-semibold text-dark">${stage || '—'}</td>
        <td class="small text-muted">${tag || '—'}</td>
        <td class="small">
          <div class="text-muted text-truncate" style="max-width: 180px;" title="${note}">${note || '—'}</div>
        </td>
        <td class="pe-4 text-muted small">${who}</td>
      </tr>`;
    }).join('');
  }

  async function api(action, extra) {
    const body = new URLSearchParams();
    body.set('csrf_token', csrf);
    body.set('action', action);
    body.set('lead_id', String(activeLeadId));
    Object.entries(extra || {}).forEach(([k,v]) => body.set(k, String(v)));
    const res = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    const json = await res.json();
    if (!json || !json.ok) throw new Error((json && json.error) ? json.error : 'Request failed');
    
    canRemove = !!json.can_remove;
    editableActivityId = Number(json.editable_activity_id || 0);
    canEditAll = !!json.can_edit_all;
    
    renderCurrent(json.current || []);
    renderTimeline(json.timeline || []);
    
    // Update Toggle Button Visibility
    if (btnToggleEditBox) {
      if (editableActivityId > 0) btnToggleEditBox.classList.remove('d-none');
      else {
        btnToggleEditBox.classList.add('d-none');
        if (editBoxEl) editBoxEl.classList.add('d-none');
      }
    }
  }

  // Handle Toggle Button
  if (btnToggleEditBox) {
    btnToggleEditBox.addEventListener('click', () => {
      editBoxEl.classList.toggle('d-none');
      if (!editBoxEl.classList.contains('d-none')) {
        // Pre-fill edit fields from the last action in timeline
        const firstRow = timelineEl.querySelector('tr');
        if (firstRow) {
          const stage = firstRow.querySelector('td:nth-child(3)').textContent.trim();
          const note = firstRow.querySelector('td:nth-child(5) .text-truncate').textContent.trim();
          editStageEl.value = stage !== '—' ? stage : '';
          editNoteEl.value = note !== '—' ? note : '';
        }
      }
    });
  }

  if (btnCloseEditBox) {
    btnCloseEditBox.addEventListener('click', () => editBoxEl.classList.add('d-none'));
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-tag-lead]');
    if (!btn) return;
    hideErr();
    activeLeadId = Number(btn.getAttribute('data-lead-id') || 0);
    const company = btn.getAttribute('data-company') || '';
    const person = btn.getAttribute('data-person') || '';
    subtitleEl.innerHTML = `<i class="bi bi-building me-1"></i> ${esc(company)} <span class="mx-2">•</span> <i class="bi bi-person me-1"></i> ${esc(person)}`;
    
    inputEl.value = '';
    if (stageEl) stageEl.value = '';
    noteEl.value = '';
    if (editBoxEl) editBoxEl.classList.add('d-none');
    
    currentEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
    timelineEl.innerHTML = '<tr><td colspan="6" class="text-center py-5"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>';
    
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    try {
      await api('get_tagging');
    } catch (err) {
      showErr(err.message || 'Unable to load engagement data');
      currentEl.innerHTML = '';
      timelineEl.innerHTML = '';
    }
  });

    addBtn.addEventListener('click', async () => {
    hideErr();
    const name = (inputEl.value || '').trim();
    if (!name) { showErr('Please select a tag.'); return; }
    addBtn.disabled = true;
    try {
      const note = (noteEl.value || '').trim();
      const stage = stageEl ? (stageEl.value || '').trim() : '';
      await api('add_tag', { tag_name: name, note, stage });
      inputEl.value = '';
      if (stageEl) stageEl.value = '';
      noteEl.value = '';
    } catch (err) {
      showErr(err.message || 'Operation failed');
    } finally {
      addBtn.disabled = false;
    }
  });

  if (presetsEl) {
    const presets = ['New','Contacted','Follow Up','Meeting Booked','No Response','Wrong Contact','Not Interested','Accepted','Rejected','In Progress','TBD(To be discussed)'];
    presetsEl.innerHTML = presets.map(p => `<button type="button" class="btn btn-outline-secondary btn-xs rounded-pill px-3" style="font-size: 0.7rem;" data-tag-preset="${esc(p)}">${esc(p)}</button>`).join('');
    presetsEl.addEventListener('click', (e) => {
      const b = e.target.closest('[data-tag-preset]');
      if (!b) return;
      const val = b.getAttribute('data-tag-preset') || '';
      if (['Accepted','Rejected','In Progress','TBD(To be discussed)'].includes(val)) {
        if (stageEl) stageEl.value = val;
      } else {
        inputEl.value = val;
      }
      inputEl.focus();
    });
  }

  if (editSaveEl) {
    editSaveEl.addEventListener('click', async () => {
      hideErr();
      const note = (editNoteEl.value || '').trim();
      const stage = (editStageEl.value || '').trim();
      if (!editableActivityId) { showErr('Action context lost.'); return; }
      editSaveEl.disabled = true;
      try {
        await api('edit_tag_activity', { activity_id: String(editableActivityId), note, stage });
        editBoxEl.classList.add('d-none');
      } catch (err) {
        showErr(err.message || 'Update failed');
      } finally {
        editSaveEl.disabled = false;
      }
    });
  }

  timelineEl.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-edit-tag]');
    if (!btn) return;
    const id = Number(btn.getAttribute('data-edit-tag') || 0);
    if (!id || id !== editableActivityId) return;
    const row = btn.closest('tr');
    if (!row) return;
    const stageTd = row.querySelector('td:nth-child(3)');
    const noteTd = row.querySelector('td:nth-child(5)');
    const stage = stageTd ? (stageTd.textContent || '').trim() : '';
    const note = noteTd ? (noteTd.textContent || '').trim() : '';
    if (editStageEl) editStageEl.value = stage !== '—' ? stage : '';
    if (editNoteEl) editNoteEl.value = (note && note !== '—') ? note : '';
    if (editBoxEl) editBoxEl.classList.remove('d-none');
  });

  currentEl.addEventListener('click', async (e) => {
    const rm = e.target.closest('[data-remove-tag]');
    if (!rm) return;
    hideErr();
    const tagId = Number(rm.getAttribute('data-remove-tag') || 0);
    if (!tagId) return;
    try {
      await api('remove_tag', { tag_id: tagId });
    } catch (err) {
      showErr(err.message || 'Unable to remove tag');
    }
  });
})();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
