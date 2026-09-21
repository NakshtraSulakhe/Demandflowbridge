<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('leads.email.page', []);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

$error = '';
$success = '';

$visible = getOpsVisibleCampaignIdsForUser($userId, $role);

$campaignId = isset($_GET['campaign_id']) && $_GET['campaign_id'] !== '' ? (int)$_GET['campaign_id'] : 0;
$statusFilter = trim((string)($_GET['email_status'] ?? 'All'));
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10, (int)$_GET['per_page']) : 25;
$perPage = min(200, $perPage);

$allowedEmailStatuses = ['Pending','Sent','Delivered','Opened','Bounced','Unsubscribed','No Response'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedEmailStatuses, true) && $statusFilter !== 'All') {
    $statusFilter = 'All';
}

$campaigns = [];
if ($visible === null) {
    $rs = $conn->query("SELECT c.id, c.name, d.code, d.client_code FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE d.status IN ('Live','Active') ORDER BY c.name");
    $campaigns = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
} else {
    $ids = array_keys($visible);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT c.id, c.name, d.code, d.client_code FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id IN ($in) ORDER BY c.name");
        mysqliBindParams($stmt, $types, $ids);
        $stmt->execute();
        $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

if ($campaignId > 0 && $visible !== null && !isset($visible[$campaignId])) $campaignId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        try {
            if ($action === 'update_email_lead') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                if ($leadId <= 0) throw new RuntimeException('Invalid lead.');

                $lead = getLeadById($leadId);
                if (!$lead) throw new RuntimeException('Lead not found.');

                $leadCampaignId = (int)($lead['campaign_id'] ?? 0);
                if ($visible !== null && $leadCampaignId > 0 && !isset($visible[$leadCampaignId])) {
                    throw new RuntimeException('Not allowed.');
                }

                $emailStatus = trim((string)($_POST['email_status'] ?? ''));
                if ($emailStatus === '') $emailStatus = 'Pending';
                if (!in_array($emailStatus, $allowedEmailStatuses, true)) throw new RuntimeException('Invalid email status.');
                $emailComment = trim((string)($_POST['email_status_comment'] ?? ''));

                $update = [
                    'first_name' => trim((string)($_POST['first_name'] ?? '')),
                    'last_name' => trim((string)($_POST['last_name'] ?? '')),
                    'job_title' => trim((string)($_POST['job_title'] ?? '')),
                    'email' => trim((string)($_POST['email'] ?? '')),
                    'linkedin_link' => trim((string)($_POST['linkedin_link'] ?? '')),
                    'contact_phone' => trim((string)($_POST['contact_phone'] ?? '')),
                    'company_name' => trim((string)($_POST['company_name'] ?? '')),
                    'company_linkedin' => trim((string)($_POST['company_linkedin'] ?? '')),
                    'industry' => trim((string)($_POST['industry'] ?? '')),
                    'company_size' => trim((string)($_POST['company_size'] ?? '')),
                    'country' => trim((string)($_POST['country'] ?? '')),
                    'lead_comment' => trim((string)($_POST['lead_comment'] ?? '')),
                    'email_status' => $emailStatus,
                    'email_status_comment' => $emailComment,
                    'email_status_updated_by' => $userId,
                    'email_status_updated_at' => date('Y-m-d H:i:s'),
                    'updated_by' => $userId,
                ];

                foreach (['first_name','last_name','job_title','email','linkedin_link','contact_phone','company_name','company_linkedin','industry','company_size','country','lead_comment'] as $k) {
                    if ($update[$k] === '') $update[$k] = null;
                }

                if ($emailStatus === 'Bounced') {
                    $update['qa_status'] = 'Disqualified';
                    $update['qa_reviewed_by'] = $userId;
                    $update['qa_updated_at'] = date('Y-m-d H:i:s');
                    $update['qa_comment'] = $emailComment !== '' ? ('Email bounced: ' . $emailComment) : 'Email bounced';
                }

                if (!updateLead($leadId, $update)) throw new RuntimeException('Failed to update lead.');

                $success = 'Lead updated.';
            } elseif ($action === 'edit_lead') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                if ($leadId <= 0) throw new RuntimeException('Invalid lead.');

                $lead = getLeadById($leadId);
                if (!$lead) throw new RuntimeException('Lead not found.');

                $leadCampaignId = (int)($lead['campaign_id'] ?? 0);
                if ($visible !== null && $leadCampaignId > 0 && !isset($visible[$leadCampaignId])) {
                    throw new RuntimeException('Not allowed.');
                }

                $campaignForForm = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : $leadCampaignId;
                if ($campaignForForm <= 0) $campaignForForm = $leadCampaignId;
                $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;

                $update = [
                    'first_name' => $_POST['first_name'] ?? null,
                    'last_name' => $_POST['last_name'] ?? null,
                    'job_title' => $_POST['job_title'] ?? null,
                    'email' => $_POST['email'] ?? null,
                    'linkedin_link' => $_POST['linkedin_link'] ?? null,
                    'contact_phone' => $_POST['contact_phone'] ?? null,
                    'industry' => $_POST['industry'] ?? null,
                    'company_linkedin' => $_POST['company_linkedin'] ?? null,
                    'company_name' => $_POST['company_name'] ?? null,
                    'company_size' => $_POST['company_size'] ?? null,
                    'country' => $_POST['country'] ?? null,
                    'lead_comment' => $_POST['lead_comment'] ?? null,
                    'updated_by' => $userId,
                ];

                $emailStatus = trim((string)($_POST['email_status'] ?? ''));
                if ($emailStatus !== '') {
                    if (!in_array($emailStatus, $allowedEmailStatuses, true)) throw new RuntimeException('Invalid email status.');
                    $emailComment = trim((string)($_POST['email_status_comment'] ?? ''));
                    $update['email_status'] = $emailStatus;
                    $update['email_status_comment'] = $emailComment !== '' ? $emailComment : null;
                    $update['email_status_updated_by'] = $userId;
                    $update['email_status_updated_at'] = date('Y-m-d H:i:s');
                    if ($emailStatus === 'Bounced') {
                        $update['qa_status'] = 'Disqualified';
                        $update['qa_reviewed_by'] = $userId;
                        $update['qa_updated_at'] = date('Y-m-d H:i:s');
                        $update['qa_comment'] = $emailComment !== '' ? ('Email bounced: ' . $emailComment) : 'Email bounced';
                    }
                }

                $norm = function(string $s): string {
                    $s = strtolower(trim($s));
                    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
                    $s = preg_replace('/_+/', '_', $s);
                    return trim($s, '_');
                };
                $skipNorms = array_fill_keys(array_map($norm, [
                    'first_name','firstname','first','given_name',
                    'last_name','lastname','last','surname','family_name',
                    'full_name','name','contact_name',
                    'job_title','jobtitle','title','designation',
                    'email','email_address','emailaddress',
                    'linkedin','linkedin_link','linkedin_url','linkedin_profile','linkedinprofile',
                    'phone','contact_phone','phone_number','mobile','mobile_number','contact_number',
                    'company','company_name','companyname','organization','organisation','account_name',
                    'company_linkedin','company_linkedin_url','companylinkedin','companylinkedinurl',
                    'company_size','employee_size','employee_sizes','employees','headcount',
                    'country','country_name','location',
                    'industry',
                    'implementation_timeline','software_implementation_timeline','timeline',
                    'lead_comment','comment','notes',
                ]), true);

                $form = $formId > 0 ? (getFormById($formId) ?: null) : null;
                if (!$form) $form = $campaignForForm > 0 ? getFormForCampaign($campaignForForm) : null;
                $actualFormId = (int)($form['form_id'] ?? $formId);
                $fields = (array)($form['schema']['fields'] ?? []);
                $cf = $_POST['cf'] ?? [];
                $cfFiles = $_FILES['cffile'] ?? [];
                $data = [];

                foreach ($fields as $f) {
                    if (is_array($f) && array_key_exists('visible', $f) && empty($f['visible'])) continue;
                    $key = (string)($f['key'] ?? '');
                    if ($key === '') continue;
                    if (isset($skipNorms[$norm($key)])) continue;
                    $type = (string)($f['type'] ?? 'text');
                    if ($type === 'file_upload') {
                        if (isset($cfFiles['name'][$key]) && (string)$cfFiles['name'][$key] !== '') {
                            $file = [
                                'name' => $cfFiles['name'][$key] ?? '',
                                'type' => $cfFiles['type'][$key] ?? '',
                                'tmp_name' => $cfFiles['tmp_name'][$key] ?? '',
                                'error' => $cfFiles['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                                'size' => $cfFiles['size'][$key] ?? 0,
                            ];
                            $p = saveLeadFieldFile($leadId, $key, $file);
                            if ($p) $data[$key] = $p;
                        } else {
                            if (isset($_POST['existing_cf'][$key]) && (string)$_POST['existing_cf'][$key] !== '') {
                                $data[$key] = (string)$_POST['existing_cf'][$key];
                            }
                        }
                    } else {
                        if (is_array($cf) && array_key_exists($key, $cf)) {
                            $data[$key] = $cf[$key];
                        }
                    }
                }

                $companyWebsite = trim((string)($_POST['company_website'] ?? ''));
                if ($companyWebsite !== '') {
                    $data['company_website'] = $companyWebsite;
                }
                $timelineAnswer = trim((string)($_POST['software_implementation_timeline'] ?? ''));
                if ($timelineAnswer !== '') {
                    $data['software_implementation_timeline'] = $timelineAnswer;
                }
                $li = trim((string)($_POST['linkedin_link'] ?? ''));
                if ($li !== '') $data['linkedin_link'] = $li;
                $cli = trim((string)($_POST['company_linkedin'] ?? ''));
                if ($cli !== '') $data['company_linkedin'] = $cli;

                if (!empty($data) && $actualFormId > 0 && $campaignForForm > 0) {
                    // Email workflow can save submission data, but only form filler owns form status.
                    saveFormSubmission($actualFormId, $campaignForForm, $leadId, $userId, $data);
                    logLeadActivity($leadId, $userId, 'form_submission_saved', ['form_id' => $actualFormId]);
                }

                if (!updateLead($leadId, $update)) throw new RuntimeException('Failed to update lead.');
                logLeadActivity($leadId, $userId, 'lead_updated', ['fields' => array_keys(array_filter($update, fn($v) => $v !== null))]);
                $success = 'Lead updated.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$where = ["l.qa_status = 'Qualified'"];
$params = [];
$types = '';

if ($visible !== null) {
    $ids = array_keys($visible);
    if (empty($ids)) {
        $rows = [];
        $pageTitle = 'Email Leads';
        include __DIR__ . '/../../includes/layout/app_start.php';
        ?>
        <div class="container-fluid px-0 leads-workspace">
          <div class="leads-page-header leads-hero mb-3">
            <div>
              <div class="leads-eyebrow mb-2"><i class="bi bi-envelope-check"></i>Email Workflow</div>
              <h3 class="leads-page-title mb-1">Email Leads</h3>
              <div class="leads-page-subtitle">No campaigns are currently assigned to this email workflow queue.</div>
            </div>
          </div>
        </div>
        <?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
        <?php
        exit;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "l.campaign_id IN ($in)";
    $params = array_merge($params, $ids);
    $types .= str_repeat('i', count($ids));
}

if ($campaignId > 0) {
    $where[] = 'l.campaign_id = ?';
    $params[] = $campaignId;
    $types .= 'i';
}

if ($dateFrom !== '') {
    $createdExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
    $where[] = "$createdExpr >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo !== '') {
    $createdExpr = $createdExpr ?? (function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at');
    $where[] = "$createdExpr <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}

if ($statusFilter === 'All') {
} elseif ($statusFilter === 'Pending') {
    $where[] = "(l.email_status IS NULL OR l.email_status = '' OR l.email_status = 'Pending')";
} else {
    $where[] = 'l.email_status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(l.lead_id LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)";
    $q = '%'.$search.'%';
    array_push($params, $q, $q, $q, $q, $q);
    $types .= 'sssss';
}

$whereSql = implode(' AND ', $where);
$createdExpr = $createdExpr ?? (function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at');
$countSql = "SELECT COUNT(*) FROM leads l WHERE $whereSql";
$countStmt = $conn->prepare($countSql);
if ($types) mysqliBindParams($countStmt, $types, $params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_row()[0] ?? 0);
$countStmt->close();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = max(0, ($page - 1) * $perPage);
$sql = "SELECT l.*,
    c.name AS campaign_name,
    d.code AS campaign_code,
    d.client_code AS client_code
  FROM leads l
  LEFT JOIN campaigns c ON c.id = l.campaign_id
  LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
  WHERE $whereSql
  ORDER BY $createdExpr DESC
  LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$params2 = $params;
$params2[] = $perPage;
$params2[] = $offset;
mysqliBindParams($stmt, $types . 'ii', $params2);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

foreach ($rows as &$row) {
    $leadDbId = (int)($row['id'] ?? 0);
    $leadCampaignId = (int)($row['campaign_id'] ?? 0);
    if ($leadDbId <= 0 || $leadCampaignId <= 0) continue;
    $submission = getLatestFormSubmissionForLead($leadDbId, $leadCampaignId);
    if (!$submission) continue;
    $row = deriveLeadDisplayFieldsFromSubmission($row, json_encode($submission['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
unset($row);

$visibleCount = count($rows);
$activeFilterCount = count(array_filter([
    $campaignId > 0 ? 1 : 0,
    ($statusFilter !== '' && $statusFilter !== 'All') ? 1 : 0,
    $search !== '' ? 1 : 0,
    $dateFrom !== '' ? 1 : 0,
    $dateTo !== '' ? 1 : 0,
]));

function buildEmailQuery(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}

$pageTitle = 'Email Leads';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 leads-workspace">
  <div class="leads-page-header leads-hero mb-3">
    <div>
      <div class="leads-eyebrow mb-2"><i class="bi bi-envelope-check"></i>Email Workflow</div>
      <h3 class="leads-page-title mb-1">Email Leads</h3>
      <div class="leads-page-subtitle">Review assigned campaign leads, update outreach status quickly, and open full lead context when needed.</div>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card border-0 shadow-sm leads-panel mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end leads-filter-form" method="get">
        <div class="col-md-4">
          <label class="form-label">Campaign</label>
          <select class="form-select form-select-sm" name="campaign_id">
            <option value="">All Campaigns</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo $campaignId === (int)$c['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Email Status</label>
          <select class="form-select form-select-sm" name="email_status">
            <option value="All" <?php echo $statusFilter === 'All' ? 'selected' : ''; ?>>All</option>
            <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
            <?php foreach (['Sent','Delivered','Opened','Bounced','Unsubscribed','No Response'] as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Search</label>
          <input class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, company">
        </div>
        <div class="col-md-2">
          <label class="form-label">Rows</label>
          <select class="form-select form-select-sm" name="per_page">
            <?php foreach ([25,50,100,200] as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 d-flex justify-content-end gap-2">
          <a class="btn btn-light btn-sm" href="email">Reset</a>
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm leads-panel">
    <div class="leads-table-toolbar">
      <div class="leads-table-toolbar-main">
        <span class="leads-table-count"><i class="bi bi-table"></i> Showing <?php echo number_format($visibleCount); ?> of <?php echo number_format($total); ?> leads</span>
        <div>
          <div class="leads-table-title">Email Queue</div>
          <div class="leads-table-subtitle">Filtered email-ready leads remain visible at the top of the list with cleaner action spacing.</div>
        </div>
      </div>
      <div class="leads-table-toolbar-side">
        <?php if ($activeFilterCount > 0): ?>
          <span class="leads-filter-pill"><i class="bi bi-funnel"></i><?php echo $activeFilterCount; ?> filters active</span>
        <?php endif; ?>
        <span class="leads-filter-pill"><i class="bi bi-layout-text-window-reverse"></i><?php echo (int)$perPage; ?> rows per page</span>
        <span class="leads-filter-pill"><i class="bi bi-envelope-paper"></i><?php echo htmlspecialchars($statusFilter); ?> status</span>
      </div>
    </div>
    <div class="table-responsive leads-table-wrap">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Lead</th>
            <th>Company</th>
            <th>Email</th>
            <th>Campaign</th>
            <th class="text-end">Email Status</th>
            <th class="text-muted">Last Updated</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No leads found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $lid = (int)($r['id'] ?? 0);
                $name = trim((string)($r['first_name'] ?? '').' '.(string)($r['last_name'] ?? ''));
                $status = (string)($r['email_status'] ?? '');
                if ($status === '') $status = 'Pending';
                $updatedAt = (string)($r['email_status_updated_at'] ?? '');
              ?>
              <tr>
                <td>
                  <div class="leads-row-primary"><?php echo htmlspecialchars($name !== '' ? $name : ('Lead #'.$lid)); ?></div>
                  <span class="leads-row-secondary"><?php echo htmlspecialchars('Lead reference · ' . ((string)($r['lead_id'] ?? 'N/A'))); ?></span>
                </td>
                <td>
                  <div class="leads-row-primary"><?php echo htmlspecialchars((string)($r['company_name'] ?? '')); ?></div>
                  <span class="leads-row-secondary">Company</span>
                </td>
                <td>
                  <div class="leads-row-primary"><?php echo htmlspecialchars((string)($r['email'] ?? '')); ?></div>
                  <span class="leads-row-secondary">Primary email</span>
                </td>
                <td>
                  <div class="leads-row-primary"><?php echo htmlspecialchars(crmCampaignNameWithClientCode((string)($r['campaign_name'] ?? ''), (string)($r['client_code'] ?? ''), (string)($r['campaign_code'] ?? ''))); ?></div>
                  <span class="leads-row-secondary"><?php echo !empty($r['campaign_code']) ? htmlspecialchars('Campaign code · ' . (string)$r['campaign_code']) : 'Campaign assignment'; ?></span>
                </td>
                <td class="text-end">
                  <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($status); ?></span>
                </td>
                <td>
                  <div class="leads-row-primary"><?php echo $updatedAt ? htmlspecialchars(date('d M, Y', strtotime($updatedAt))) : '—'; ?></div>
                  <?php if ($updatedAt): ?><span class="leads-row-secondary"><?php echo htmlspecialchars('Last updated · ' . date('H:i', strtotime($updatedAt))); ?></span><?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="leads-action-group">
                    <button type="button" class="btn btn-sm btn-light border"
                      data-bs-toggle="modal"
                      data-bs-target="#emailModal"
                      data-lead-id="<?php echo $lid; ?>"
                      data-first-name="<?php echo htmlspecialchars((string)($r['first_name'] ?? ''), ENT_QUOTES); ?>"
                      data-last-name="<?php echo htmlspecialchars((string)($r['last_name'] ?? ''), ENT_QUOTES); ?>"
                      data-job-title="<?php echo htmlspecialchars((string)($r['job_title'] ?? ''), ENT_QUOTES); ?>"
                      data-email="<?php echo htmlspecialchars((string)($r['email'] ?? ''), ENT_QUOTES); ?>"
                      data-linkedin="<?php echo htmlspecialchars((string)($r['linkedin_link'] ?? ''), ENT_QUOTES); ?>"
                      data-phone="<?php echo htmlspecialchars((string)($r['contact_phone'] ?? ''), ENT_QUOTES); ?>"
                      data-company="<?php echo htmlspecialchars((string)($r['company_name'] ?? ''), ENT_QUOTES); ?>"
                      data-company-linkedin="<?php echo htmlspecialchars((string)($r['company_linkedin'] ?? ''), ENT_QUOTES); ?>"
                      data-industry="<?php echo htmlspecialchars((string)($r['industry'] ?? ''), ENT_QUOTES); ?>"
                      data-company-size="<?php echo htmlspecialchars((string)($r['company_size'] ?? ''), ENT_QUOTES); ?>"
                      data-country="<?php echo htmlspecialchars((string)($r['country'] ?? ''), ENT_QUOTES); ?>"
                      data-lead-comment="<?php echo htmlspecialchars((string)($r['lead_comment'] ?? ''), ENT_QUOTES); ?>"
                      data-email-status="<?php echo htmlspecialchars($status, ENT_QUOTES); ?>"
                      data-email-status-comment="<?php echo htmlspecialchars((string)($r['email_status_comment'] ?? ''), ENT_QUOTES); ?>">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <a class="btn btn-sm btn-light border" href="details?id=<?php echo $lid; ?>&edit=1&post_to=email-leads" target="_blank"><i class="bi bi-ui-checks"></i></a>
                    <a class="btn btn-sm btn-light border" href="details?id=<?php echo $lid; ?>&format=html" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="leads-footer-bar mt-3">
    <div class="text-muted">Showing <?php echo number_format($visibleCount); ?> of <?php echo number_format($total); ?> leads</div>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo htmlspecialchars(buildEmailQuery(['page' => max(1, $page - 1)])); ?>">Prev</a>
        </li>
        <?php
          $window = 3;
          $start = max(1, $page - $window);
          $end = min($totalPages, $page + $window);
          for ($p = $start; $p <= $end; $p++):
        ?>
          <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
            <a class="page-link" href="<?php echo htmlspecialchars(buildEmailQuery(['page' => $p])); ?>"><?php echo $p; ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo htmlspecialchars(buildEmailQuery(['page' => min($totalPages, $page + 1)])); ?>">Next</a>
        </li>
      </ul>
    </nav>
  </div>
</div>

<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <div class="modal-title fw-semibold">Update Email Lead</div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="update_email_lead">
          <input type="hidden" name="lead_id" id="m_lead_id" value="">

          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">First Name</label>
              <input class="form-control form-control-sm" name="first_name" id="m_first_name" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Last Name</label>
              <input class="form-control form-control-sm" name="last_name" id="m_last_name" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Job Title</label>
              <input class="form-control form-control-sm" name="job_title" id="m_job_title" readonly>
            </div>

            <div class="col-md-6">
              <label class="form-label d-flex justify-content-between"><span>Email</span><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-copy="#m_email" title="Copy Email"><i class="bi bi-copy"></i></button></label>
              <input class="form-control form-control-sm" name="email" id="m_email" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label d-flex justify-content-between"><span>Prospect LinkedIn</span><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-copy="#m_linkedin" title="Copy Prospect LinkedIn"><i class="bi bi-copy"></i></button></label>
              <input class="form-control form-control-sm" name="linkedin_link" id="m_linkedin" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label d-flex justify-content-between"><span>Phone</span><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-copy="#m_phone" title="Copy Phone"><i class="bi bi-copy"></i></button></label>
              <input class="form-control form-control-sm" name="contact_phone" id="m_phone" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label d-flex justify-content-between"><span>Company</span><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-copy="#m_company" title="Copy Company"><i class="bi bi-copy"></i></button></label>
              <input class="form-control form-control-sm" name="company_name" id="m_company" readonly>
            </div>

            <div class="col-md-6">
              <label class="form-label d-flex justify-content-between"><span>Company LinkedIn</span><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-copy="#m_company_linkedin" title="Copy Company LinkedIn"><i class="bi bi-copy"></i></button></label>
              <input class="form-control form-control-sm" name="company_linkedin" id="m_company_linkedin" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label">Industry</label>
              <input class="form-control form-control-sm" name="industry" id="m_industry" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label">Company Size</label>
              <input class="form-control form-control-sm" name="company_size" id="m_company_size" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label">Country</label>
              <input class="form-control form-control-sm" name="country" id="m_country" readonly>
            </div>

            <div class="col-md-4">
              <label class="form-label">Email Status</label>
              <select class="form-select form-select-sm" name="email_status" id="m_email_status" required>
                <?php foreach ($allowedEmailStatuses as $s): ?>
                  <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Email Asset Title</label>
              <input class="form-control form-control-sm" name="email_status_comment" id="m_email_status_comment" placeholder="e.g. bounced reason, sent note">
            </div>

            <div class="col-12">
              <label class="form-label">Lead Comment</label>
              <textarea class="form-control form-control-sm" rows="2" name="lead_comment" id="m_lead_comment"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function setVal(id, v){ const el = document.getElementById(id); if (el) el.value = v || ''; }
document.getElementById('emailModal').addEventListener('show.bs.modal', (e) => {
  const b = e.relatedTarget;
  if (!b) return;
  setVal('m_lead_id', b.getAttribute('data-lead-id'));
  setVal('m_first_name', b.getAttribute('data-first-name'));
  setVal('m_last_name', b.getAttribute('data-last-name'));
  setVal('m_job_title', b.getAttribute('data-job-title'));
  setVal('m_email', b.getAttribute('data-email'));
  setVal('m_linkedin', b.getAttribute('data-linkedin'));
  setVal('m_phone', b.getAttribute('data-phone'));
  setVal('m_company', b.getAttribute('data-company'));
  setVal('m_company_linkedin', b.getAttribute('data-company-linkedin'));
  setVal('m_industry', b.getAttribute('data-industry'));
  setVal('m_company_size', b.getAttribute('data-company-size'));
  setVal('m_country', b.getAttribute('data-country'));
  setVal('m_lead_comment', b.getAttribute('data-lead-comment'));
  setVal('m_email_status', b.getAttribute('data-email-status'));
  setVal('m_email_status_comment', b.getAttribute('data-email-status-comment'));
});

async function copyText(text) {
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch (e) {}
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch (e) { return false; }
}

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  const sel = btn.getAttribute('data-copy');
  const el = document.querySelector(sel);
  if (!el) return;
  
  const val = el.value || '';
  if (val === '') return;
  
  const ok = await copyText(val);
  if (ok) {
    const icon = btn.querySelector('i');
    if (icon) {
      const oldClass = icon.className;
      icon.className = 'bi bi-check2 text-success';
      setTimeout(() => { icon.className = oldClass; }, 1500);
    }
  }
});
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
