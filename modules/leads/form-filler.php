<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('leads.marketing.page', []);
ensureCsrfToken();

// Handle inline update form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $flash = null;
  $csrfValid = isset($_POST['csrf_token']) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
  if (!$csrfValid) {
    $flash = ['type' => 'danger', 'message' => 'Invalid form token. Please try again.'];
  } else {
    // Legacy handler via modal: action=update_form_details
    if (isset($_POST['action']) && $_POST['action'] === 'update_form_details') {
      $id = (int)($_POST['lead_id'] ?? 0);
      $formFilledTimeRaw = trim($_POST['form_filled_time'] ?? '');
      $formFilled = trim($_POST['form_filled'] ?? ''); // Yes/No (legacy)
      $ip = trim($_POST['ip'] ?? '');
      $actor = (int)((getCurrentUser()['id'] ?? 0));
      $updateData = [];
      if ($formFilled === 'Yes' || $formFilled === 'No') {
        $updateData['form_done'] = $formFilled;
        if ($formFilled === 'Yes') {
          // Convert HTML5 datetime-local to MySQL DATETIME
          if ($formFilledTimeRaw !== '') {
            $updateData['form_filled_time'] = str_replace('T', ' ', $formFilledTimeRaw) . (strlen($formFilledTimeRaw) === 16 ? ':00' : '');
          } else {
            $updateData['form_filled_time'] = date('Y-m-d H:i:s');
          }
        } else {
          $updateData['form_filled_time'] = null; // clear if marked No
        }
      }
      if ($ip !== '') {
        $updateData['ip_address'] = $ip;
      }
      if (!empty($updateData)) {
        $updateData['updated_by'] = $actor;
      }
      $ok = !empty($updateData) ? updateLead($id, $updateData) : true;
      if ($ok && !empty($updateData)) {
        logLeadActivity($id, $actor, 'lead_updated', ['fields' => array_keys($updateData)]);
      }
      $flash = ['type' => $ok ? 'success' : 'danger', 'message' => $ok ? 'Lead updated successfully.' : 'Failed to update lead.'];
    }
    // New handler: update QA/form via generic modal
    elseif (isset($_POST['update_lead_id'])) {
      $id = (int)($_POST['update_lead_id'] ?? 0);
      $qaStatus = trim($_POST['qa_status'] ?? '');
      $qaComment = trim($_POST['qa_comment'] ?? '');
      $clientComment = trim($_POST['client_comment'] ?? '');
      $formDone = trim($_POST['form_done'] ?? '');
      $ok = true;
      if ($qaStatus !== '') {
        $user = getCurrentUser();
        $reviewerId = $user['id'] ?? 0;
        $ok = $ok && updateLeadQuality($id, $qaStatus, $qaComment, $reviewerId, $clientComment);
      }
      $updateData = [];
      if ($formDone === 'Yes' || $formDone === 'No') {
        $updateData['form_done'] = $formDone;
        if ($formDone === 'Yes') { $updateData['form_filled_time'] = date('Y-m-d H:i:s'); }
        if ($formDone === 'No') { $updateData['form_filled_time'] = null; }
      }
      if (!empty($updateData)) {
        $updateData['updated_by'] = (int)($reviewerId ?? 0);
        $ok = $ok && updateLead($id, $updateData);
        if ($ok) {
          logLeadActivity($id, (int)($reviewerId ?? 0), 'lead_updated', ['fields' => array_keys($updateData)]);
        }
      }
      $flash = ['type' => $ok ? 'success' : 'danger', 'message' => $ok ? 'Lead updated successfully.' : 'Failed to update lead.'];
    }
  }
}

// Handle filters and pagination from query params
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : null;
$clientCode = isset($_GET['client_code']) ? trim($_GET['client_code']) : null;
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : null;
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : null;
$qaStatus = isset($_GET['qa_status']) ? $_GET['qa_status'] : null;
$formDone = isset($_GET['form_done']) ? $_GET['form_done'] : null; // Yes/No
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'created_at';
$sortDir = isset($_GET['sort_dir']) ? strtoupper($_GET['sort_dir']) : 'DESC';
$perPage = isset($_GET['per_page']) ? max(5, (int)$_GET['per_page']) : 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$allowedSortBy = ['created_at','lead_id','agent','campaign','email','job_title','company_name','qa_status','form_status'];
if (!in_array($sortBy, $allowedSortBy, true)) {
  $sortBy = 'created_at';
}
if (!in_array($sortDir, ['ASC','DESC'], true)) {
  $sortDir = 'DESC';
}

$filters = [
  'campaign_id' => $campaignId,
  'agent_id' => $agentId,
  'client_code' => $clientCode,
  'date_from' => $dateFrom,
  'date_to' => $dateTo,
  'qa_status' => $qaStatus,
  'form_done' => $formDone,
  'search' => $search,
  'sort_by' => $sortBy,
  'sort_dir' => $sortDir,
  'include_submission' => true,
];

$result = getLeads($filters, $perPage, $page);
$leads = $result['leads'];
$total = (int)$result['total'];
$totalPages = (int)$result['totalPages'];
$visibleCount = count($leads);
$activeFilterCount = count(array_filter([
  !empty($campaignId) ? 1 : 0,
  !empty($agentId) ? 1 : 0,
  !empty($clientCode) ? 1 : 0,
  !empty($dateFrom) ? 1 : 0,
  !empty($dateTo) ? 1 : 0,
  !empty($qaStatus) ? 1 : 0,
  !empty($formDone) ? 1 : 0,
  $search !== '' ? 1 : 0,
]));

$campaigns = getCampaigns();
$agents = getAgents();
$clientCodes = getClientCodes();

function buildQuery($overrides = []) {
  $params = array_merge($_GET, $overrides);
  return '?' . http_build_query($params);
}

?>
<?php $pageTitle = 'Form Filler'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 leads-workspace">
  <?php if (isset($flash)): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['message']) ?></div>
  <?php endif; ?>
  <div class="leads-page-header leads-hero mb-3">
    <div>
      <div class="leads-eyebrow mb-2"><i class="bi bi-ui-checks-grid"></i>Form Completion</div>
      <h4 class="leads-page-title mb-0">Form Filler</h4>
      <div class="leads-page-subtitle">Work through filtered leads, fill missing form data, and keep QA and submission status aligned from one operational queue.</div>
    </div>
    <div class="leads-page-actions">
      <!-- Export CSV: posts current filters to export.php like QA section -->
      <form method="post" action="export.php" target="_blank" class="d-inline">
        <input type="hidden" name="action" value="export">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($campaignId ?? '') ?>">
        <input type="hidden" name="agent_id" value="<?= htmlspecialchars($agentId ?? '') ?>">
        <input type="hidden" name="client_code" value="<?= htmlspecialchars($clientCode ?? '') ?>">
        <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom ?? '') ?>">
        <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo ?? '') ?>">
        <input type="hidden" name="qa_status" value="<?= htmlspecialchars($qaStatus ?? '') ?>">
        <input type="hidden" name="form_done" value="<?= htmlspecialchars($formDone ?? '') ?>">
        <input type="hidden" name="form_filled" value="<?= htmlspecialchars($formDone ?? '') ?>"><!-- legacy alias -->
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" name="export" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-download"></i> Export CSV
        </button>
      </form>
    </div>
  </div>

  <!-- Filters -->
  <div class="card border-0 shadow-sm leads-panel mb-3">
    <div class="card-body">
      <div class="p-3">
        <form method="get" class="row g-3 align-items-end leads-filter-form">
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="campaign_id">Campaign</label>
            <select name="campaign_id" id="campaign_id" class="form-select form-select-sm">
              <option value="">All Campaigns</option>
              <?php foreach ($campaigns as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($campaignId == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars(crmCampaignDisplayNameFromRow($c)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="agent_id">Agent</label>
            <select name="agent_id" id="agent_id" class="form-select form-select-sm">
              <option value="">All</option>
              <?php foreach ($agents as $a): ?>
                <option value="<?= $a['id'] ?>" <?= ($agentId == $a['id']) ? 'selected' : '' ?>><?= htmlspecialchars((string)($a['full_name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="client_code">Client</label>
            <select name="client_code" id="client_code" class="form-select form-select-sm">
              <option value="">All Clients</option>
              <?php foreach ($clientCodes as $code): ?>
                <option value="<?= htmlspecialchars($code) ?>" <?= ($clientCode === $code) ? 'selected' : '' ?>><?= htmlspecialchars($code) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="date_from">From</label>
            <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom ?? '') ?>" class="form-control form-control-sm" />
          </div>
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="date_to">To</label>
            <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo ?? '') ?>" class="form-control form-control-sm" />
          </div>
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="qa_status">QA Status</label>
            <select name="qa_status" id="qa_status" class="form-select form-select-sm">
              <option value="">All</option>
              <?php foreach (["Qualified","Disqualified","Pending"] as $qs): ?>
                <option value="<?= $qs ?>" <?= ($qaStatus === $qs) ? 'selected' : '' ?>><?= $qs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="form_done">Form Status</label>
            <select name="form_done" id="form_done" class="form-select form-select-sm">
              <option value="">All</option>
              <?php foreach (["Yes","No"] as $fs): ?>
                <option value="<?= $fs ?>" <?= ($formDone === $fs) ? 'selected' : '' ?>><?= $fs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-xl-3 col-lg-4 col-md-6">
            <label class="form-label small text-muted" for="search">Search</label>
            <input type="text" name="search" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, company, email, phone..." class="form-control form-control-sm" />
          </div>
          <div class="col-xl-2 col-lg-3 col-md-4">
            <label class="form-label small text-muted" for="per_page">Rows</label>
            <select name="per_page" id="per_page" class="form-select form-select-sm">
              <?php foreach ([10,25,50,100] as $r): ?>
                <option value="<?= $r ?>" <?= ($perPage == $r) ? 'selected' : '' ?>><?= $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-xl-3 col-lg-4 col-md-6 ms-auto d-flex justify-content-end gap-2">
            <button class="btn btn-primary flex-grow-1" type="submit">
              <i class="bi bi-funnel"></i> Apply
            </button>
            <a class="btn btn-outline-secondary flex-grow-1" href="marketing">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Leads Table -->
  <div class="card border-0 shadow-sm leads-panel">
    <div class="leads-table-toolbar">
      <div class="leads-table-toolbar-main">
        <span class="leads-table-count"><i class="bi bi-table"></i> Showing <?= number_format($visibleCount) ?> of <?= number_format($total) ?> leads</span>
        <div>
          <div class="leads-table-title">Form Filler Queue</div>
          <div class="leads-table-subtitle">The filtered result count and current queue summary now stay visible above the working table.</div>
        </div>
      </div>
      <div class="leads-table-toolbar-side">
        <?php if ($activeFilterCount > 0): ?>
          <span class="leads-filter-pill"><i class="bi bi-funnel"></i><?= $activeFilterCount ?> filters active</span>
        <?php endif; ?>
        <span class="leads-filter-pill"><i class="bi bi-layout-text-window-reverse"></i><?= (int)$perPage ?> rows per page</span>
      </div>
    </div>
    <div class="table-responsive leads-table-wrap">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th class="serial-col sticky-header">#</th>
            <?php
              function th_sort(string $key, string $label): void {
                $current = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'created_at';
                $dir = isset($_GET['sort_dir']) ? strtoupper($_GET['sort_dir']) : 'DESC';
                $nextDir = ($current === $key && $dir === 'ASC') ? 'DESC' : 'ASC';
                $icon = '';
                if ($current === $key) {
                  $icon = $dir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>';
                }
                $href = htmlspecialchars(buildQuery(['sort_by' => $key, 'sort_dir' => $nextDir, 'page' => 1]));
                echo "<th><a class='sort-link' href='$href'>$label $icon</a></th>";
              }
            ?>
            <?php th_sort('created_at','Date'); ?>
            <?php th_sort('lead_id','Lead ID'); ?>
            <?php th_sort('agent','Agent'); ?>
            <?php th_sort('campaign','Campaign'); ?>
            <?php th_sort('email','Email'); ?>
            <th>Name</th>
            <?php th_sort('job_title','Job Title'); ?>
            <?php th_sort('company_name','Company'); ?>
            <?php th_sort('qa_status','QA'); ?>
            <?php th_sort('form_status','Form'); ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($leads)): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No leads found</td></tr>
          <?php else: ?>
            <?php $serialStart = (($page - 1) * $perPage) + 1; $i = 0; ?>
            <?php foreach ($leads as $lead): $i++; ?>
              <tr>
                <td class="text-muted"><?= $serialStart + $i - 1 ?></td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars(date('d M, Y', strtotime($lead['created_at']))) ?></div>
                  <span class="leads-row-secondary"><?= htmlspecialchars('Created at · ' . date('H:i', strtotime($lead['created_at']))) ?></span>
                </td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars($lead['lead_id'] ?? $lead['id']) ?></div>
                  <span class="leads-row-secondary">Lead reference</span>
                </td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars($lead['agent_name'] ?? '') ?></div>
                  <span class="leads-row-secondary">Assigned agent</span>
                </td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars(crmCampaignNameWithClientCode((string)($lead['campaign_name'] ?? ''), (string)($lead['client_code'] ?? ''), (string)($lead['campaign_code'] ?? ''))) ?></div>
                  <span class="leads-row-secondary"><?= !empty($lead['campaign_code']) ? htmlspecialchars('Campaign code · ' . (string)$lead['campaign_code']) : 'Campaign assignment' ?></span>
                </td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars($lead['email'] ?? '') ?></div>
                  <span class="leads-row-secondary">Primary email</span>
                </td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))) ?></div>
                  <span class="leads-row-secondary">Lead name</span>
                </td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars($lead['job_title'] ?? '') ?></div>
                  <span class="leads-row-secondary">Job title</span>
                </td>
                <td>
                  <div class="leads-row-primary"><?= htmlspecialchars($lead['company_name'] ?? '') ?></div>
                  <span class="leads-row-secondary">Company</span>
                </td>
                
                <td>
                  <span class="badge bg-<?php
                    echo ($lead['qa_status'] === 'Qualified') ? 'success' : (($lead['qa_status'] === 'Disqualified') ? 'danger' : 'secondary');
                  ?>"><?= htmlspecialchars($lead['qa_status'] ?? 'Pending') ?></span>
                </td>
                <td>
                  <?php $formStatus = normalizeFormDone((string)($lead['form_done'] ?? 'No')); ?>
                  <span class="badge bg-<?= ($formStatus === 'Yes') ? 'primary' : 'warning' ?>"><?= htmlspecialchars($formStatus) ?></span>
                </td>
                <td class="text-end sticky-actions">
                  <div class="leads-action-group">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#formFillerModal<?= $lead['id'] ?>" title="Update lead"><i class="bi bi-pencil"></i></button>
                    <?php if ($formStatus !== 'Yes'): ?>
                      <a href="details?id=<?= $lead['id'] ?>&edit=1&post_to=form-filler" class="btn btn-sm btn-primary" title="Fill form"><i class="bi bi-ui-checks"></i></a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php
    $currentSortBy = $sortBy; $currentSortDir = $sortDir;
    $window = 3;
    function pageLink(int $p, string $currentSortBy, string $currentSortDir): string {
      return htmlspecialchars(buildQuery(['page' => $p, 'sort_by' => $currentSortBy, 'sort_dir' => $currentSortDir]));
    }
  ?>
  <div class="leads-footer-bar mt-3">
    <div class="text-muted">Showing <?= count($leads) ?> of <?= $total ?> leads</div>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= pageLink(max(1, $page-1), $currentSortBy, $currentSortDir) ?>">Prev</a>
        </li>
        <?php
          $start = max(1, $page - $window);
          $end = min($totalPages, $page + $window);
          for ($p = $start; $p <= $end; $p++):
        ?>
          <li class="page-item <?= ($p == $page) ? 'active' : '' ?>">
            <a class="page-link" href="<?= pageLink($p, $currentSortBy, $currentSortDir) ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= pageLink(min($totalPages, $page+1), $currentSortBy, $currentSortDir) ?>">Next</a>
        </li>
      </ul>
    </nav>
  </div>
</div>

<?php foreach ($leads as $lead): ?>
  <?php
    $prospectLinkedin = trim((string)($lead['linkedin_link'] ?? ''));
    $companyLinkedin = trim((string)($lead['company_linkedin'] ?? ''));
    $qaCommentText = trim((string)($lead['qa_comment'] ?? ''));
    $clientCommentText = trim((string)($lead['qa_client_comment'] ?? ($lead['client_comment'] ?? '')));
    if ($prospectLinkedin !== '' && !preg_match('~^https?://~i', $prospectLinkedin)) {
      $prospectLinkedin = 'https://' . ltrim($prospectLinkedin, '/');
    }
    if ($companyLinkedin !== '' && !preg_match('~^https?://~i', $companyLinkedin)) {
      $companyLinkedin = 'https://' . ltrim($companyLinkedin, '/');
    }
  ?>
  <div class="modal fade" id="formFillerModal<?php echo $lead['id']; ?>" tabindex="-1" aria-labelledby="formFillerModalLabel<?php echo $lead['id']; ?>" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-warning">
          <h5 class="modal-title" id="formFillerModalLabel<?php echo $lead['id']; ?>">Form Filler Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_form_details">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="lead_id" value="<?php echo (int)$lead['id']; ?>">

            <div class="mb-3">
              <label for="form_filled_time<?php echo $lead['id']; ?>" class="form-label">Form Filled Time</label>
              <input type="datetime-local" class="form-control" id="form_filled_time<?php echo $lead['id']; ?>" name="form_filled_time" value="<?php echo !empty($lead['form_filled_time']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($lead['form_filled_time']))) : ''; ?>">
            </div>

            <div class="mb-3">
              <label for="form_filled<?php echo $lead['id']; ?>" class="form-label">Form Filled</label>
              <select class="form-select" id="form_filled<?php echo $lead['id']; ?>" name="form_filled">
                <?php $modalFormStatus = normalizeFormDone((string)($lead['form_done'] ?? 'No')); ?>
                <option value="No" <?php echo ($modalFormStatus === 'No') ? 'selected' : ''; ?>>No</option>
                <option value="Yes" <?php echo ($modalFormStatus === 'Yes') ? 'selected' : ''; ?>>Yes</option>
              </select>
            </div>

            <div class="mb-3">
              <label for="ip<?php echo $lead['id']; ?>" class="form-label">IP Address</label>
              <input type="text" class="form-control" id="ip<?php echo $lead['id']; ?>" name="ip" value="<?php echo htmlspecialchars($lead['ip_address'] ?? ''); ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Lead Details</label>
              <div class="card">
                <div class="card-body">
                  <p><strong>Name:</strong> <?php echo htmlspecialchars(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')); ?></p>
                  <p><strong>Job Title:</strong> <?php echo htmlspecialchars($lead['job_title'] ?? 'N/A'); ?></p>
                  <p><strong>Company:</strong> <?php echo htmlspecialchars($lead['company_name'] ?? 'N/A'); ?></p>
                  <p><strong>Email:</strong> <?php echo htmlspecialchars($lead['email'] ?? 'N/A'); ?></p>
                  <p><strong>Phone:</strong> <?php echo htmlspecialchars($lead['contact_phone'] ?? 'N/A'); ?></p>
                  <p><strong>Industry:</strong> <?php echo htmlspecialchars($lead['industry'] ?? 'N/A'); ?></p>
                  <p><strong>Company Size:</strong> <?php echo htmlspecialchars($lead['company_size'] ?? 'N/A'); ?></p>
                  <p><strong>Country:</strong> <?php echo htmlspecialchars($lead['country'] ?? 'N/A'); ?></p>
                  <p><strong>Software Implementation Timeline:</strong> <?php echo htmlspecialchars($lead['software_implementation_timeline'] ?? 'N/A'); ?></p>
                  <p>
                    <strong>Prospect LinkedIn:</strong>
                    <?php if ($prospectLinkedin !== ''): ?>
                      <a href="<?php echo htmlspecialchars($prospectLinkedin); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none ms-1">
                        <i class="bi bi-linkedin text-primary me-1"></i>Open profile
                      </a>
                    <?php else: ?>
                      N/A
                    <?php endif; ?>
                  </p>
                  <p>
                    <strong>Company LinkedIn:</strong>
                    <?php if ($companyLinkedin !== ''): ?>
                      <a href="<?php echo htmlspecialchars($companyLinkedin); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none ms-1">
                        <i class="bi bi-linkedin text-primary me-1"></i>Open company page
                      </a>
                    <?php else: ?>
                      N/A
                    <?php endif; ?>
                  </p>
                  <p><strong>QA Comment:</strong> <?php echo htmlspecialchars($qaCommentText !== '' ? $qaCommentText : 'N/A'); ?></p>
                  <p><strong>Client Comments:</strong> <?php echo htmlspecialchars($clientCommentText !== '' ? $clientCommentText : 'N/A'); ?></p>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>




