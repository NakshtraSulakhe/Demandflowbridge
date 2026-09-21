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

$conn = getDbConnection();
ensureCampaignDetailsColumns();
$isClientAdmin = hasRole('client_admin') || isAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array((string)$_POST['action'], ['update_status','assign_campaign_sdr'], true)) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid token']); exit;
    }
    if (!$isClientAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Not allowed']); exit;
    }
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if ($campaignId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid campaign']); exit; }

    if (!clientOwnsCampaign($clientId, $campaignId)) {
        echo json_encode(['ok' => false, 'error' => 'Not found']); exit;
    }

    $action = (string)$_POST['action'];
    if ($action === 'update_status') {
        try {
            $ok = setCampaignStatus($campaignId, (string)($_POST['status'] ?? ''), (int)($user['id'] ?? 0));
            echo json_encode(['ok' => (bool)$ok]); exit;
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
        }
    }

    if ($action === 'assign_campaign_sdr') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid <= 0) { echo json_encode(['ok' => false, 'error' => 'Select SDR']); exit; }
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND client_id = ? AND role = 'client_sdr' AND is_active = 1 LIMIT 1");
        if (!$stmt) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmt->bind_param('ii', $uid, $clientId);
        $stmt->execute();
        $okUser = $stmt->get_result()->fetch_row()[0] ?? null;
        $stmt->close();
        if (!$okUser) { echo json_encode(['ok' => false, 'error' => 'Invalid SDR']); exit; }

        ensureLeadsTrackingColumns();

        $rm = $conn->prepare("
            DELETE a
            FROM campaign_user_assignments a
            JOIN users u ON u.id = a.user_id
            WHERE a.campaign_id = ? AND u.client_id = ? AND u.role = 'client_sdr'
        ");
        if ($rm) {
            $rm->bind_param('ii', $campaignId, $clientId);
            $rm->execute();
            $rm->close();
        }

        $ins = $conn->prepare("INSERT IGNORE INTO campaign_user_assignments (campaign_id, user_id, assigned_by) VALUES (?,?,?)");
        if (!$ins) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $assignerId = (int)($user['id'] ?? 0);
        $ins->bind_param('iii', $campaignId, $uid, $assignerId);
        $ok = $ins->execute();
        $ins->close();

        $upd = $conn->prepare("
            UPDATE leads l
            SET l.assigned_to_user = ?, l.updated_by = ?
            WHERE l.campaign_id = ?
        ");
        if ($upd) {
            $upd->bind_param('iii', $uid, $assignerId, $campaignId);
            $upd->execute();
            $upd->close();
        }

        if ($ok) {
            $stmtN = $conn->prepare("SELECT name FROM campaigns WHERE id = ? LIMIT 1");
            $campName = '';
            if ($stmtN) {
                $stmtN->bind_param('i', $campaignId);
                $stmtN->execute();
                $campName = (string)(($stmtN->get_result()->fetch_assoc() ?: [])['name'] ?? '');
                $stmtN->close();
            }
            $title = 'New campaign allocated';
            $msg = ($campName !== '' ? $campName : ('Campaign #' . $campaignId)) . ' assigned to you (Client SDR).';
            $link = '../campaigns/view?id=' . $campaignId;
            createNotification($uid, 'campaign.assigned', $title, $msg, $link);
        }

        echo json_encode(['ok' => (bool)$ok]); exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown']); exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, (int)($_GET['per_page'] ?? 25));
$offset = ($page - 1) * $perPage;
$campaignIds = getClientCampaignIdsForClient($clientId);

$rows = [];
$total = 0;
$pages = 1;
if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $params = $campaignIds;
    $types = str_repeat('i', count($campaignIds));
    $where = "WHERE c.id IN ($in)";
    if ($q !== '') {
        $where .= " AND (c.name LIKE ? OR d.code LIKE ?)";
        $like = '%'.$q.'%';
        $params[] = $like; $types .= 's';
        $params[] = $like; $types .= 's';
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM campaigns c JOIN campaign_details d ON d.campaign_id=c.id $where");
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    $pages = max(1, (int)ceil($total / $perPage));

    $sql = "SELECT c.id, c.name, d.code, d.status, d.start_date, d.end_date, d.total_leads,
                   d.delivery_format, d.campaign_type, d.pacing_type, d.pacing_count, d.instruction,
                   d.cpl, d.cpl_currency, d.cpc, d.status_updated_by, d.client_id,
                   (SELECT full_name FROM users WHERE id = d.status_updated_by LIMIT 1) AS status_updated_by_name,
                   d.targeted_country, d.job_title, d.departments, d.seniority_levels, d.industries, d.employee_sizes, d.revenue_sizes,
                   (SELECT COUNT(*) FROM leads l WHERE l.campaign_id = c.id AND l.client_delivery_status = 'Delivered') AS delivered_count,
                   (SELECT u.id FROM campaign_user_assignments a JOIN users u ON u.id = a.user_id WHERE a.campaign_id = c.id AND u.client_id = ? AND u.role = 'client_sdr' LIMIT 1) AS assigned_sdr_id,
                   (SELECT u.full_name FROM campaign_user_assignments a JOIN users u ON u.id = a.user_id WHERE a.campaign_id = c.id AND u.client_id = ? AND u.role = 'client_sdr' LIMIT 1) AS assigned_sdr_name
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            $where
            ORDER BY c.name
            LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $params2 = array_merge([$clientId, $clientId], $params);
    mysqliBindParams($stmt, 'ii' . $types, $params2);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$sdrUsers = getClientUsersByRole($clientId, 'client_sdr');

$pageTitle = 'Client Campaigns';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Client Campaigns</div>
      <div class="text-muted small">Manage and view your campaigns</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="../dashboard/client-dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <?php if (hasRole('client_admin')): ?>
        <a class="btn btn-primary btn-sm" href="../campaigns/campaign-create.php"><i class="bi bi-plus-circle me-1"></i>Create</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <?php if (isAdmin()): ?>
          <div class="col-md-3">
            <label class="form-label small text-muted">Client ID</label>
            <input class="form-control form-control-sm" name="client_id" value="<?php echo (int)$clientId; ?>">
          </div>
        <?php endif; ?>
        <div class="col-md-6">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name or code">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">Rows</label>
          <select class="form-select form-select-sm" name="per_page">
            <?php foreach ([25,50,100] as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-grid">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light fw-semibold">Campaigns</div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Campaign</th>
            <th class="text-muted">Code</th>
            <th>Status</th>
            <th class="text-end">Allocation</th>
            <th class="text-end">Delivered</th>
            <th style="width: 220px;">Progress</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No campaigns.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <?php
              $A = (int)($r['total_leads'] ?? 0);
              $D = (int)($r['delivered_count'] ?? 0);
              $P = max(0, $A - $D);
              $pct = $A > 0 ? (int)round((min($D, $A) / max(1, $A)) * 100) : 0;
              $detailsPayload = [
                'id' => (int)($r['id'] ?? 0),
                'name' => (string)($r['name'] ?? ''),
                'code' => (string)($r['code'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'start_date' => (string)($r['start_date'] ?? ''),
                'end_date' => (string)($r['end_date'] ?? ''),
                'total_leads' => $A,
                'delivered_count' => $D,
                'delivery_format' => (string)($r['delivery_format'] ?? ''),
                'campaign_type' => (string)($r['campaign_type'] ?? ''),
                'pacing_type' => (string)($r['pacing_type'] ?? ''),
                'pacing_count' => (int)($r['pacing_count'] ?? 0),
                'instruction' => (string)($r['instruction'] ?? ''),
                'cpl' => $r['cpl'] !== null ? (float)$r['cpl'] : null,
                'cpl_currency' => (string)($r['cpl_currency'] ?? ''),
                'cpc' => $r['cpc'] !== null ? (float)$r['cpc'] : null,
                'targeted_country' => $r['targeted_country'] ?? null,
                'job_title' => $r['job_title'] ?? null,
                'departments' => $r['departments'] ?? null,
                'seniority_levels' => $r['seniority_levels'] ?? null,
                'industries' => $r['industries'] ?? null,
                'employee_sizes' => $r['employee_sizes'] ?? null,
                'revenue_sizes' => $r['revenue_sizes'] ?? null,
              ];
              $detailsJson = htmlspecialchars(json_encode($detailsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
              $cid = (int)($r['id'] ?? 0);
              $sdrId = (int)($r['assigned_sdr_id'] ?? 0);
              $sdrName = (string)($r['assigned_sdr_name'] ?? '');
              $statusVal = (string)($r['status'] ?? '');
              $statusMap = ['Live'=>'bg-success-subtle text-success','Active'=>'bg-primary-subtle text-primary','Pause'=>'bg-warning-subtle text-warning','Draft'=>'bg-secondary-subtle text-secondary','Complete'=>'bg-dark-subtle text-dark'];
              $statusCls = $statusMap[$statusVal] ?? 'bg-secondary-subtle text-secondary';
            ?>
            <tr>
              <td class="ps-3">
                <div class="fw-semibold"><?php echo htmlspecialchars($r['name'] ?? ''); ?></div>
                <div class="text-muted small"><?php echo htmlspecialchars((string)$r['start_date']); ?> – <?php echo htmlspecialchars((string)$r['end_date']); ?></div>
              </td>
              <td class="text-muted small"><?php echo htmlspecialchars($r['code'] ?? ''); ?></td>
              <td>
                <span class="badge <?php echo htmlspecialchars($statusCls); ?> border"><?php echo htmlspecialchars($statusVal); ?></span>
                <?php if ($statusVal === 'Pause' && !empty($r['status_updated_by_name'])): ?>
                  <div class="x-small text-muted mt-1" style="font-size: 0.7rem;">by <?php echo htmlspecialchars($r['status_updated_by_name']); ?></div>
                <?php endif; ?>
              </td>
              <td class="text-end font-monospace"><?php echo number_format($A); ?></td>
              <td class="text-end font-monospace text-success"><?php echo number_format($D); ?></td>
              <td class="text-nowrap">
                <div class="progress" style="height: 12px;">
                  <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (int)$pct; ?>%" aria-valuenow="<?php echo (int)$pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="d-flex justify-content-between text-muted small mt-1">
                  <span><?php echo (int)$pct; ?>%</span>
                  <span><?php echo number_format($D); ?> / <?php echo number_format($A); ?></span>
                </div>
              </td>
              <td class="text-end pe-3">
                <div class="d-flex justify-content-end gap-1 flex-wrap">
                  <button class="btn btn-outline-secondary btn-xs" type="button" title="Details" data-action="campaign-details" data-campaign="<?php echo $detailsJson; ?>"><i class="bi bi-eye"></i></button>
                  <a class="btn btn-outline-secondary btn-xs" href="client-leads.php?campaign_id=<?php echo (int)$cid; ?>" title="Leads"><i class="bi bi-list-ul"></i></a>
                  <?php if (hasRole('client_admin')): ?>
                    <button class="btn btn-outline-secondary btn-xs" type="button"
                      data-action="assign-sdr"
                      data-campaign-id="<?php echo (int)$cid; ?>"
                      data-campaign-name="<?php echo htmlspecialchars((string)($r['name'] ?? '')); ?>"
                      data-current-sdr-id="<?php echo (int)$sdrId; ?>"
                      data-current-sdr-name="<?php echo htmlspecialchars($sdrName); ?>"
                      title="Assign SDR">
                      <i class="bi bi-person-check"></i>
                    </button>
                    <a class="btn btn-outline-primary btn-xs" href="../campaigns/campaign-edit.php?id=<?php echo (int)$cid; ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                    <div class="btn-group btn-group-sm" role="group">
                      <button type="button" class="btn btn-light border btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Status">
                        <i class="bi bi-sliders"></i>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <?php if ($statusVal === 'Draft'): ?>
                          <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$cid; ?>" data-next="Active">Activate</a></li>
                        <?php elseif ($statusVal === 'Active'): ?>
                          <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$cid; ?>" data-next="Live">Go Live</a></li>
                        <?php elseif ($statusVal === 'Pause'): ?>
                          <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$cid; ?>" data-next="Live">Resume Live</a></li>
                        <?php elseif ($statusVal === 'Live'): ?>
                          <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$cid; ?>" data-next="Pause">Pause</a></li>
                          <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$cid; ?>" data-next="Complete">Complete</a></li>
                        <?php endif; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-light d-flex justify-content-between align-items-center">
      <div class="text-muted small">Total: <?php echo (int)$total; ?></div>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?client_id=<?php echo (int)$clientId; ?>&q=<?php echo urlencode($q); ?>&per_page=<?php echo (int)$perPage; ?>&page=<?php echo max(1, $page-1); ?>">Prev</a></li>
          <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $pages; ?></span></li>
          <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="?client_id=<?php echo (int)$clientId; ?>&q=<?php echo urlencode($q); ?>&per_page=<?php echo (int)$perPage; ?>&page=<?php echo min($pages, $page+1); ?>">Next</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<div class="modal fade" id="campaignDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-0">
        <div>
          <div class="h5 mb-0" id="cdmTitle">Campaign</div>
          <div class="text-muted small" id="cdmSubtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="text-muted small">Status</div>
            <div class="fw-semibold" id="cdmStatus">—</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small">Allocation</div>
            <div class="fw-semibold" id="cdmAlloc">—</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small">Delivery</div>
            <div class="fw-semibold" id="cdmDelivery">—</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small">Delivered</div>
            <div class="fw-semibold" id="cdmDelivered">—</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small">CPL</div>
            <div class="fw-semibold" id="cdmCpl">—</div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Type</div>
            <div class="fw-semibold" id="cdmType">—</div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Pacing</div>
            <div class="fw-semibold" id="cdmPacing">—</div>
          </div>
        </div>
        <hr class="my-3">
        <div class="fw-semibold mb-2">Targeting</div>
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <div class="text-muted small">Country</div>
            <div id="cdmTargetCountry"></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Job Title</div>
            <div id="cdmJobTitle"></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Industry</div>
            <div id="cdmIndustries"></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Seniority</div>
            <div id="cdmSeniority"></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Departments</div>
            <div id="cdmDepartments"></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Employee Size</div>
            <div id="cdmEmployee"></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Revenue Size</div>
            <div id="cdmRevenue"></div>
          </div>
        </div>
        <div class="fw-semibold mb-2">Instructions</div>
        <div class="text-muted" id="cdmInstruction" style="white-space: pre-wrap;">—</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light border" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="assignSdrModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-0">
        <div>
          <div class="h6 mb-0">Assign Campaign</div>
          <div class="text-muted small" id="assignSdrSubtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger d-none" id="assignSdrError"></div>
        <input type="hidden" id="assign_campaign_id" value="0">
        <div class="mb-2">
          <label class="form-label small text-muted">Client SDR</label>
          <select class="form-select form-select-sm" id="assign_sdr_user">
            <option value="">Select SDR</option>
            <?php foreach ($sdrUsers as $u): ?>
              <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="text-muted small">This will assign all leads of this campaign to the selected SDR.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="button" id="assignSdrSave"><i class="bi bi-save me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const csrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const modalEl = document.getElementById('campaignDetailsModal');
  if (!window.bootstrap) return;
  const titleEl = document.getElementById('cdmTitle');
  const subEl = document.getElementById('cdmSubtitle');
  const stEl = document.getElementById('cdmStatus');
  const alEl = document.getElementById('cdmAlloc');
  const delEl = document.getElementById('cdmDelivery');
  const delivEl = document.getElementById('cdmDelivered');
  const cplEl = document.getElementById('cdmCpl');
  const tyEl = document.getElementById('cdmType');
  const paEl = document.getElementById('cdmPacing');
  const insEl = document.getElementById('cdmInstruction');
  const tcEl = document.getElementById('cdmTargetCountry');
  const jtEl = document.getElementById('cdmJobTitle');
  const indEl = document.getElementById('cdmIndustries');
  const senEl = document.getElementById('cdmSeniority');
  const depEl = document.getElementById('cdmDepartments');
  const empEl = document.getElementById('cdmEmployee');
  const revEl = document.getElementById('cdmRevenue');

  const esc = (s) => String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const chips = (val) => {
    if (val === null || val === undefined) return '<span class="text-muted">—</span>';
    let arr = null;
    if (Array.isArray(val)) arr = val;
    else if (typeof val === 'string') {
      const t = val.trim();
      if (!t) return '<span class="text-muted">—</span>';
      if (t.startsWith('[') || t.startsWith('{')) {
        try {
          const parsed = JSON.parse(t);
          if (Array.isArray(parsed)) arr = parsed;
        } catch (e) {}
      }
      if (!arr) arr = t.split(',').map(x => x.trim()).filter(Boolean);
    } else {
      arr = [String(val)];
    }
    if (!arr || !arr.length) return '<span class="text-muted">—</span>';
    return '<div class="d-flex flex-wrap gap-1">' + arr.map(x => `<span class="badge rounded-pill bg-light text-dark border">${esc(x)}</span>`).join('') + '</div>';
  };

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action="campaign-details"]');
    if (!btn) return;
    const raw = btn.getAttribute('data-campaign') || '';
    if (!raw) return;
    let d = null;
    try { d = JSON.parse(raw); } catch (err) { d = null; }
    if (!d) return;
    if (!modalEl) return;
    titleEl.textContent = d.name || 'Campaign';
    subEl.textContent = [d.code || '', (d.start_date || d.end_date) ? `${d.start_date || ''} – ${d.end_date || ''}` : ''].filter(Boolean).join(' · ');
    stEl.textContent = d.status || '—';
    alEl.textContent = (typeof d.total_leads === 'number') ? String(d.total_leads) : '—';
    delEl.textContent = d.delivery_format || '—';
    delivEl.textContent = (typeof d.delivered_count === 'number') ? String(d.delivered_count) : '—';
    if (cplEl) {
      const cur = String(d.cpl_currency || '').trim();
      const cpl = (typeof d.cpl === 'number' && !Number.isNaN(d.cpl)) ? d.cpl : null;
      cplEl.textContent = (cpl !== null) ? (cur ? `${String(cpl)} ${cur}` : String(cpl)) : '—';
    }
    tyEl.textContent = d.campaign_type || '—';
    paEl.textContent = (d.pacing_type ? String(d.pacing_type) : '—') + (d.pacing_count ? ` ${String(d.pacing_count)}` : '');
    insEl.textContent = (d.instruction || '').trim() || '—';
    if (tcEl) tcEl.innerHTML = chips(d.targeted_country);
    if (jtEl) jtEl.innerHTML = chips(d.job_title);
    if (indEl) indEl.innerHTML = chips(d.industries);
    if (senEl) senEl.innerHTML = chips(d.seniority_levels);
    if (depEl) depEl.innerHTML = chips(d.departments);
    if (empEl) empEl.innerHTML = chips(d.employee_sizes);
    if (revEl) revEl.innerHTML = chips(d.revenue_sizes);
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  });

  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action="status"]');
    if (!a) return;
    e.preventDefault();
    const cid = Number(a.getAttribute('data-cid') || 0);
    const next = String(a.getAttribute('data-next') || '');
    if (!cid || !next) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'update_status');
    fd.append('campaign_id', String(cid));
    fd.append('status', next);
    fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(r => r.json())
      .then(d => {
        if (d && d.ok) {
          if (window.appNotify) window.appNotify('success', 'Status updated', 'Success');
          setTimeout(() => location.reload(), 350);
        } else {
          const msg = (d && d.error) ? d.error : 'Failed';
          if (window.appNotify) window.appNotify('danger', msg, 'Error');
          else alert(msg);
        }
      })
      .catch(() => { if (window.appNotify) window.appNotify('danger', 'Network error', 'Error'); else alert('Failed'); });
  });

  const assignModalEl = document.getElementById('assignSdrModal');
  const assignSubtitle = document.getElementById('assignSdrSubtitle');
  const assignErr = document.getElementById('assignSdrError');
  const assignCampaignId = document.getElementById('assign_campaign_id');
  const assignUserSel = document.getElementById('assign_sdr_user');
  const assignSave = document.getElementById('assignSdrSave');

  function showAssignErr(msg) {
    if (!assignErr) return;
    assignErr.textContent = msg || 'Error';
    assignErr.classList.remove('d-none');
  }
  function hideAssignErr() {
    if (!assignErr) return;
    assignErr.textContent = '';
    assignErr.classList.add('d-none');
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action="assign-sdr"]');
    if (!btn) return;
    if (!assignModalEl || !assignCampaignId || !assignUserSel) return;
    hideAssignErr();
    const cid = Number(btn.getAttribute('data-campaign-id') || 0);
    const name = btn.getAttribute('data-campaign-name') || '';
    const curId = Number(btn.getAttribute('data-current-sdr-id') || 0);
    const curName = btn.getAttribute('data-current-sdr-name') || '';
    assignCampaignId.value = String(cid || 0);
    assignSubtitle.textContent = name ? name : '';
    assignUserSel.value = curId ? String(curId) : '';
    if (!curId && curName) assignUserSel.value = '';
    bootstrap.Modal.getOrCreateInstance(assignModalEl).show();
  });

  assignSave?.addEventListener('click', () => {
    hideAssignErr();
    const cid = Number(assignCampaignId?.value || 0);
    const uid = Number(assignUserSel?.value || 0);
    if (!cid) { showAssignErr('Invalid campaign.'); return; }
    if (!uid) { showAssignErr('Select an SDR.'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'assign_campaign_sdr');
    fd.append('campaign_id', String(cid));
    fd.append('user_id', String(uid));
    assignSave.disabled = true;
    fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(r => r.json())
      .then(d => {
        if (d && d.ok) location.reload();
        else showAssignErr((d && d.error) ? d.error : 'Failed');
      })
      .catch(() => showAssignErr('Failed'))
      .finally(() => { assignSave.disabled = false; });
  });
})();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
