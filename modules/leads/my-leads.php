<?php
/**
 * My Leads (Agent View)
 * 
 * Provides agents with a focused view of their own lead generation history, 
 * performance status, and editing capabilities.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Enforce manifest-driven My Leads page access.
requirePermissionOrRole('leads.my.page', []);
$user = getCurrentUser();
$isVendor = isVendor();
$vendorId = (int)($user['vendor_id'] ?? 0);
$isVendorUser = ((string)($user['role'] ?? '') === 'vendor_user');
$canLeadEntry = userHasPermission('leads.entry');

// CSRF token generation
ensureCsrfToken();

// Handle lead edit submission
$editMessage = '';
$editMessageClass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_lead') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $editMessage = 'Invalid request token.';
        $editMessageClass = 'danger';
    } else {
        $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
        $lead = $leadId > 0 ? getLeadById($leadId) : null;
        if (!$lead) {
            $editMessage = 'Lead not found.';
            $editMessageClass = 'danger';
        } elseif (!isAdmin() && $isVendor) {
            $leadVendorId = (int)($lead['vendor_id'] ?? 0);
            $leadAgentId = (int)($lead['agent_id'] ?? 0);
            if ($vendorId <= 0 || $leadVendorId !== $vendorId) {
                $editMessage = 'You can only edit your own leads.';
                $editMessageClass = 'danger';
            } elseif ($isVendorUser && $leadAgentId !== (int)$user['id']) {
                $editMessage = 'You can only edit your own leads.';
                $editMessageClass = 'danger';
            }
        } elseif (!isAdmin() && (int)($lead['agent_id'] ?? 0) !== (int)$user['id']) {
            $editMessage = 'You can only edit your own leads.';
            $editMessageClass = 'danger';
        } else {
            $cf = $_POST['cf'] ?? [];
            $update = [
                'updated_by' => (int)($user['id'] ?? 0),
            ];
            $setIfPosted = function(string $key, ?string $targetKey = null) use (&$update): void {
                if (!array_key_exists($key, $_POST)) return;
                $k = $targetKey ?? $key;
                $v = $_POST[$key];
                if (is_array($v)) return;
                $update[$k] = $v;
            };
            foreach ([
                'first_name',
                'last_name',
                'job_title',
                'email',
                'linkedin_link',
                'contact_phone',
                'company_linkedin',
                'company_name',
                'company_size',
                'country',
                'industry',
            ] as $k) {
                $setIfPosted($k);
            }
            if (array_key_exists('lead_comment', $_POST)) {
                $update['lead_comment'] = (string)($_POST['lead_comment'] ?? '');
            }

            $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : (int)($lead['campaign_id'] ?? 0);
            $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
            if ($campaignId > 0 && $formId > 0) {
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
                    'company_linkedin','company_linkedin_link','company_linkedin_url','companylinkedin','companylinkedinurl',
                    'industry',
                    'lead_comment','comment','notes',
                ]), true);
                $form = getFormById($formId) ?: getFormForCampaign($campaignId);
                $fields = (array)($form['schema']['fields'] ?? []);
                $selectMap = getSelectOptionsByFormSchema((array)($form['schema'] ?? []), ['industry','employee_size','company_size','country','software_implementation_timeline']);
                if (array_key_exists('industry', $_POST) && !empty($selectMap['industry']) && !valueInAllowedOptions((string)($update['industry'] ?? ''), $selectMap['industry'])) {
                    $editMessage = 'Invalid Industry. Allowed: ' . implode(' | ', $selectMap['industry']);
                    $editMessageClass = 'danger';
                }
                $empOpts = $selectMap['employee_size'] ?? ($selectMap['company_size'] ?? null);
                if (empty($editMessage) && array_key_exists('company_size', $_POST) && is_array($empOpts) && !valueInAllowedOptions((string)($update['company_size'] ?? ''), $empOpts)) {
                    $editMessage = 'Invalid Employee Size. Allowed: ' . implode(' | ', $empOpts);
                    $editMessageClass = 'danger';
                }
                if (empty($editMessage) && array_key_exists('country', $_POST) && !empty($selectMap['country']) && !valueInAllowedOptions((string)($update['country'] ?? ''), $selectMap['country'])) {
                    $editMessage = 'Invalid Country. Allowed: ' . implode(' | ', $selectMap['country']);
                    $editMessageClass = 'danger';
                }
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
                        if (array_key_exists($key, $cf)) {
                            $typeLower = strtolower((string)$type);
                            $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
                            $val = $cf[$key];
                            if (!empty($opts) && in_array($typeLower, ['select','radio'], true) && is_scalar($val) && trim((string)$val) !== '') {
                                if (!valueInAllowedOptions((string)$val, $opts)) {
                                    $editMessage = 'Invalid value for ' . (string)($f['label'] ?? $key);
                                    $editMessageClass = 'danger';
                                    break;
                                }
                            }
                            if (!empty($opts) && $typeLower === 'checkbox' && is_array($val) && !empty($val)) {
                                foreach ($val as $vv) {
                                    if (!valueInAllowedOptions((string)$vv, $opts)) {
                                        $editMessage = 'Invalid value for ' . (string)($f['label'] ?? $key);
                                        $editMessageClass = 'danger';
                                        break 2;
                                    }
                                }
                            }
                            $data[$key] = $cf[$key];
                        }
                    }
                }
                $companyWebsite = trim((string)($_POST['company_website'] ?? ($cf['company_website'] ?? ($cf['website'] ?? ''))));
                if ($companyWebsite !== '') {
                    $data['company_website'] = $companyWebsite;
                    $update['company_website'] = $companyWebsite;
                }
                $timelineAnswer = trim((string)($_POST['software_implementation_timeline'] ?? ($cf['software_implementation_timeline'] ?? ($cf['implementation_timeline'] ?? ''))));
                if ($timelineAnswer !== '') {
                    if (!empty($selectMap['software_implementation_timeline']) && !valueInAllowedOptions($timelineAnswer, $selectMap['software_implementation_timeline'])) {
                        $editMessage = 'Invalid Implementation Timeline. Allowed: ' . implode(' | ', $selectMap['software_implementation_timeline']);
                        $editMessageClass = 'danger';
                    } else {
                        $data['software_implementation_timeline'] = $timelineAnswer;
                        $update['software_implementation_timeline'] = $timelineAnswer;
                    }
                }
                $li = trim((string)($_POST['linkedin_link'] ?? ''));
                if ($li !== '') $data['linkedin_link'] = $li;
                $cli = trim((string)($_POST['company_linkedin'] ?? ''));
                if ($cli !== '') $data['company_linkedin'] = $cli;
                if (!empty($data)) {
                    // My Leads can save form payloads, but form completion stays owned by form filler.
                    saveFormSubmission((int)($form['form_id'] ?? $formId), $campaignId, $leadId, (int)$user['id'], $data);
                    logLeadActivity($leadId, (int)($user['id'] ?? 0), 'form_submission_saved', ['form_id' => (int)($form['form_id'] ?? $formId)]);
                }
            }
            
            // Handle recording removal or upload
            $removeRecId = !empty($_POST['delete_recording_id']) ? (int)$_POST['delete_recording_id'] : 0;
            if ($removeRecId > 0) {
                deleteLeadRecording($leadId, $removeRecId, (int)($user['id'] ?? 0));
            }

            if (isset($_FILES['recording']) && !empty($_FILES['recording']['name'])) {
                $files = $_FILES['recording'];
                $uploadedPaths = [];
                if (is_array($files['name'])) {
                    foreach ($files['name'] as $i => $name) {
                        if ($files['error'][$i] === UPLOAD_ERR_OK) {
                            $f = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                            $uploaded = uploadRecording($f);
                            if ($uploaded) $uploadedPaths[] = $uploaded;
                        }
                    }
                } else if ($files['error'] === UPLOAD_ERR_OK) {
                    $uploaded = uploadRecording($files);
                    if ($uploaded) $uploadedPaths[] = $uploaded;
                }

                if (!empty($uploadedPaths)) {
                    foreach ($uploadedPaths as $path) {
                        addLeadRecording($leadId, $path, (int)($user['id'] ?? 0));
                        // Also update primary for compatibility if it's the first one
                        if (!isset($update['recording_path'])) $update['recording_path'] = $path;
                    }
                }
            }
            
            if (empty($editMessage)) {
                if (updateLead($leadId, $update)) {
                    logLeadActivity($leadId, (int)($user['id'] ?? 0), 'lead_updated', ['fields' => array_keys($update)]);
                    $editMessage = 'Lead updated successfully.';
                    $editMessageClass = 'success';
                } else {
                    $editMessage = 'Failed to update lead.';
                    $editMessageClass = 'danger';
                }
            }
        }
    }
}

// Get filter parameters
$campaignId = $_GET['campaign_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$qaStatus = $_GET['qa_status'] ?? '';
$search = trim((string)($_GET['search'] ?? ''));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(5, (int)$_GET['per_page']) : 10;
$perPage = min(500, $perPage);

// Build filters array
$filters = [];
if (!isAdmin()) {
    if ($isVendor) {
        if ($vendorId > 0) $filters['vendor_id'] = $vendorId;
        if ($isVendorUser) $filters['agent_id'] = $user['id'];
    } else {
        $filters['agent_id'] = $user['id'];
    }
}
if (!empty($campaignId)) $filters['campaign_id'] = $campaignId;
if (!empty($dateFrom)) $filters['date_from'] = $dateFrom;
if (!empty($dateTo)) $filters['date_to'] = $dateTo;
if (!empty($qaStatus)) $filters['qa_status'] = $qaStatus;
if ($search !== '') $filters['search'] = $search;
$filters['include_submission'] = true;

// Get leads
$leadsData = getLeads($filters, $perPage, $page);
$leads = $leadsData['leads'];
$total = $leadsData['total'];
$totalPages = $leadsData['totalPages'];
$visibleCount = count($leads);
$activeFilterCount = count(array_filter([
    $campaignId !== '' ? 1 : 0,
    $qaStatus !== '' ? 1 : 0,
    $dateFrom !== '' ? 1 : 0,
    $dateTo !== '' ? 1 : 0,
    $search !== '' ? 1 : 0,
]));
$campaigns = getCampaigns();

function buildMyLeadsQuery(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return 'my?' . http_build_query($params);
}

?>
<?php $pageTitle = 'My Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
    <style>
        .my-leads-filter-row{display:grid;grid-template-columns:1.45fr 1.6fr 1fr .95fr .95fr 1.15fr;gap:12px;align-items:end}
        .my-leads-filter-actions{display:grid;grid-template-columns:.8fr 1.2fr 1fr;gap:8px;align-items:end}
        .my-leads-filter-actions .form-label{grid-column:1/-1}
        @media (max-width: 1199.98px){
            .my-leads-filter-row{grid-template-columns:repeat(2,minmax(0,1fr))}
            .my-leads-filter-actions{grid-template-columns:1fr 1fr 1fr}
        }
        @media (max-width: 767.98px){
            .my-leads-filter-row{grid-template-columns:1fr}
            .my-leads-filter-actions{grid-template-columns:1fr 1fr}
            .my-leads-filter-actions .form-label{display:none}
        }
    </style>

    <div class="container-fluid px-0 leads-workspace">
        <div class="leads-page-header leads-hero mb-4">
            <div>
                <div class="leads-eyebrow mb-2"><i class="bi bi-collection"></i>Agent Queue</div>
                <h2 class="leads-page-title mb-0">My Leads</h2>
                <div class="leads-page-subtitle">Review the leads you created, filter quickly by campaign and QA state, and jump into details or edits without losing context.</div>
            </div>
            <div class="leads-page-actions">
                <?php if ($canLeadEntry): ?><a href="entry" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> New Lead
                </a><?php endif; ?>
            </div>
        </div>

        <?php if (!empty($editMessage)): ?>
            <div class="alert alert-<?php echo $editMessageClass; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                <?php echo htmlspecialchars($editMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card border-0 shadow-sm leads-panel mb-4">
            <div class="card-body">
                <form method="get" class="leads-filter-form my-leads-filter-row">
                    <div>
                        <label for="campaign_id" class="form-label small text-muted">Campaign</label>
                        <select class="form-select form-select-sm" id="campaign_id" name="campaign_id">
                            <option value="">All Campaigns</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($campaignId == $c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="search" class="form-label small text-muted">Quick Search</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input
                                type="text"
                                class="form-control border-start-0"
                                id="search"
                                name="search"
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Name, email, company, lead ID"
                            >
                        </div>
                    </div>
                    <div>
                        <label for="qa_status" class="form-label small text-muted">QA Status</label>
                        <select class="form-select form-select-sm" id="qa_status" name="qa_status">
                            <option value="">All Status</option>
                            <?php foreach (getQaStatusOptions() as $qaStatusValue => $qaStatusLabel): ?>
                                <option value="<?php echo htmlspecialchars($qaStatusValue); ?>" <?php echo normalizeQaStatus((string)$qaStatus) === normalizeQaStatus($qaStatusValue) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($qaStatusLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="date_from" class="form-label small text-muted">From</label>
                        <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div>
                        <label for="date_to" class="form-label small text-muted">To</label>
                        <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="my-leads-filter-actions">
                        <label for="per_page" class="form-label small text-muted">Rows</label>
                        <select class="form-select form-select-sm" id="per_page" name="per_page">
                            <?php foreach ([10,25,50,100,500] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <a href="my" class="btn btn-light btn-sm border">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Leads Table -->
        <div class="card border-0 shadow-sm leads-panel">
            <div class="leads-table-toolbar">
                <div class="leads-table-toolbar-main">
                    <span class="leads-table-count"><i class="bi bi-table"></i> Showing <?php echo number_format($visibleCount); ?> of <?php echo number_format($total); ?> leads</span>
                    <div>
                        <div class="leads-table-title">Lead Queue</div>
                        <div class="leads-table-subtitle">Filtered lead results stay visible here without needing to scroll to the footer.</div>
                    </div>
                </div>
                <div class="leads-table-toolbar-side">
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="leads-filter-pill"><i class="bi bi-funnel"></i><?php echo $activeFilterCount; ?> filters active</span>
                    <?php endif; ?>
                    <span class="leads-filter-pill"><i class="bi bi-layout-text-window-reverse"></i><?php echo (int)$perPage; ?> rows per page</span>
                </div>
            </div>
            <div class="table-responsive leads-table-wrap">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">SR No.</th>
                            <th class="ps-3">Date</th>
                            <th>Lead</th>
                            <th>Contact</th>
                            <th>Company</th>
                            <th>Campaign</th>
                            <th>QA / Comments</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No leads found.</td>
                            </tr>
                        <?php else: ?>
                            <?php $serialStart = (($page - 1) * $perPage) + 1; $i = 0; ?>
                            <?php foreach ($leads as $lead): $i++; ?>
                                <?php
                                    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                                    if ($name === '') $name = 'Lead #' . (int)($lead['id'] ?? 0);
                                    $email = trim((string)($lead['email'] ?? ''));
                                    if ($email === '') $email = 'No email';
                                    $phone = trim((string)($lead['contact_phone'] ?? ''));
                                    if ($phone === '') $phone = 'N/A';
                                    $companyName = trim((string)($lead['company_name'] ?? ''));
                                    if ($companyName === '') $companyName = 'No company';
                                    $jobTitle = trim((string)($lead['job_title'] ?? ''));
                                    if ($jobTitle === '') $jobTitle = 'N/A';
                                    $campaignSubtitle = !empty($lead['campaign_code']) ? ('Campaign code · ' . (string)$lead['campaign_code']) : 'Campaign assignment';
                                    $leadQaStatus = normalizeQaStatus((string)($lead['qa_status'] ?? 'Pending'));
                                    $qaClass = qaStatusBadgeClass($leadQaStatus);
                                ?>
                                <tr>
                                    <td class="ps-3 small text-muted"><?php echo $serialStart + $i - 1; ?></td>
                                    <td class="ps-3">
                                        <div class="leads-row-primary"><?php echo htmlspecialchars(date('d M, Y', strtotime($lead['created_at']))); ?></div>
                                        <span class="leads-row-secondary"><?php echo htmlspecialchars('Created at · ' . date('H:i', strtotime($lead['created_at']))); ?></span>
                                    </td>
                                    <td>
                                        <div class="leads-row-primary"><?php echo htmlspecialchars($name); ?></div>
                                        <span class="leads-row-secondary"><?php echo htmlspecialchars('Lead reference · ' . ((string)($lead['lead_id'] ?? 'N/A'))); ?></span>
                                    </td>
                                    <td>
                                        <div class="leads-row-primary"><?php echo htmlspecialchars($email); ?></div>
                                        <span class="leads-row-secondary"><?php echo htmlspecialchars('Contact number · ' . $phone); ?></span>
                                    </td>
                                    <td>
                                        <div class="leads-row-primary"><?php echo htmlspecialchars($companyName); ?></div>
                                        <span class="leads-row-secondary"><?php echo htmlspecialchars('Job title · ' . $jobTitle); ?></span>
                                    </td>
                                    <td>
                                        <div class="leads-row-primary"><?php echo htmlspecialchars(crmCampaignNameWithClientCode((string)($lead['campaign_name'] ?? 'N/A'), (string)($lead['client_code'] ?? ''), (string)($lead['campaign_code'] ?? ''))); ?></div>
                                        <span class="leads-row-secondary"><?php echo htmlspecialchars($campaignSubtitle); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars(qaStatusDisplayLabel($leadQaStatus)); ?></span>
                                        <?php if (!empty($lead['qa_comment'])): ?>
                                            <div class="leads-row-secondary mt-1"><?php echo htmlspecialchars('QA: ' . (string)$lead['qa_comment']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($lead['qa_client_comment'])): ?>
                                            <div class="leads-row-secondary"><?php echo htmlspecialchars('Client: ' . (string)$lead['qa_client_comment']); ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($lead['qa_comment']) && empty($lead['qa_client_comment'])): ?>
                                            <div class="leads-row-secondary mt-1">No QA comments yet</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="leads-action-group">
                                            <a href="view?id=<?php echo $lead['id']; ?>" class="btn btn-light border" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($leadQaStatus === 'Pending' || $leadQaStatus === 'Rework Needed' || isAdmin()): ?>
                                                <button class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#editLeadModal" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Edit Lead">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="p-3 border-top leads-footer-bar">
                <div class="small text-muted">Showing <?php echo count($leads); ?> of <?php echo number_format($total); ?> leads</div>
                <?php if ($totalPages > 1): ?>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars(buildMyLeadsQuery(['page' => max(1, $page - 1)])); ?>">Prev</a>
                        </li>
                        <?php
                            $range = 2; // Number of pages to show before and after current page
                            $show_dots_at_start = false;
                            $show_dots_at_end = false;

                            for ($i = 1; $i <= $totalPages; $i++) {
                                if ($i === 1 || $i === $totalPages || ($i >= $page - $range && $i <= $page + $range)) {
                                    ?>
                                    <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(buildMyLeadsQuery(['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                    <?php
                                    $show_dots_at_start = false;
                                    $show_dots_at_end = false;
                                } elseif ($i < $page - $range && !$show_dots_at_start) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    $show_dots_at_start = true;
                                } elseif ($i > $page + $range && !$show_dots_at_end) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    $show_dots_at_end = true;
                                }
                            }
                        ?>
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars(buildMyLeadsQuery(['page' => min($totalPages, $page + 1)])); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Lead Modal (Simplified for Agents) -->
    <div class="modal fade" id="editLeadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="editLeadBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('editLeadModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const leadId = button.getAttribute('data-lead-id');
        fetch('details?id=' + encodeURIComponent(leadId) + '&edit=1&format=html&embed=1', { 
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(r => r.text())
            .then(html => { document.getElementById('editLeadBody').innerHTML = html; })
            .catch(() => { document.getElementById('editLeadBody').innerHTML = '<div class="text-danger p-3">Unable to load lead details.</div>'; });
    });
    </script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
