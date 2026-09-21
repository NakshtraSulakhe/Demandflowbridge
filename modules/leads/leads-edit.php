<?php
/**
 * Manage Leads
 * 
 * Provides filters, normalized data table, pagination, export, and lead editing/QA updates.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$requestPath = strtolower((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
$mode = (string)($_GET['mode'] ?? '');
$isVendorModeRequest = ($mode === 'vendor') || str_contains($requestPath, '/modules/leads/vendors');
requirePermissionOrRole($isVendorModeRequest ? 'leads.vendor.page' : 'leads.list.page', []);

$user = getCurrentUser();
$isVendor = isVendor();
$isClient = isClient();
$isEmailDept = hasRole(['email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);
$isPrivInternal = isAdmin() || hasRole(['director','manager_director','operations_director','operations_manager','sales_director','sales_manager','sdr', 'email_marketing_manager'])  || isQA();
$canDeleteLead = function_exists('canDeleteLeadForUser') ? canDeleteLeadForUser($user) : (isAdmin() || hasRole(['director','manager_director','operations_director','email_marketing_manager','email_marketing_director']));
$qaStatusOptions = function_exists('getQaStatusOptions') ? getQaStatusOptions() : [];

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
        } else {
            if (!$isPrivInternal) {
                $ownerId = (int)($lead['agent_id'] ?? 0);
                if ($ownerId !== (int)($user['id'] ?? 0)) {
                    $editMessage = 'Access denied.';
                    $editMessageClass = 'danger';
                }
            }
        }
        if ($editMessage === '') {
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
                'industry',
                'company_linkedin',
                'company_name',
                'company_website',
                'company_size',
                'country',
                'software_implementation_timeline',
            ] as $k) {
                $setIfPosted($k);
            }
            if (array_key_exists('lead_comment', $_POST)) {
                $update['lead_comment'] = (string)($_POST['lead_comment'] ?? '');
            }
            $camp = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
            if ($camp > 0) { $update['campaign_id'] = $camp; }

            $campaignForForm = $camp > 0 ? $camp : (int)($lead['campaign_id'] ?? 0);
            $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
            if ($campaignForForm > 0 && $formId > 0) {
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
                $form = getFormById($formId) ?: getFormForCampaign($campaignForForm);
                $fields = (array)($form['schema']['fields'] ?? []);
                $cf = $_POST['cf'] ?? [];
                $cfFiles = $_FILES['cffile'] ?? [];
                $data = [];
                foreach ($fields as $f) {
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
                            $data[$key] = $cf[$key];
                        }
                    }
                }
                if (!empty($data)) {
                    // Saving a form submission here should not flip the marketing-owned form status.
                    saveFormSubmission((int)($form['form_id'] ?? $formId), $campaignForForm, $leadId, (int)($user['id'] ?? 0), $data);
                    logLeadActivity($leadId, (int)($user['id'] ?? 0), 'form_submission_saved', ['form_id' => (int)($form['form_id'] ?? $formId)]);
                }

                if ($editMessage === '') {
                    $selectMap = getSelectOptionsByFormSchema((array)($form['schema'] ?? []), ['industry','employee_size','company_size','country','software_implementation_timeline']);
                    if (!empty($selectMap['industry']) && array_key_exists('industry', $_POST) && !valueInAllowedOptions((string)($update['industry'] ?? ''), $selectMap['industry'])) {
                        $editMessage = 'Invalid Industry. Allowed: ' . implode(' | ', $selectMap['industry']);
                        $editMessageClass = 'danger';
                    }
                    $empOpts = $selectMap['employee_size'] ?? ($selectMap['company_size'] ?? null);
                    if ($editMessage === '' && is_array($empOpts) && array_key_exists('company_size', $_POST) && !valueInAllowedOptions((string)($update['company_size'] ?? ''), $empOpts)) {
                        $editMessage = 'Invalid Employee Size. Allowed: ' . implode(' | ', $empOpts);
                        $editMessageClass = 'danger';
                    }
                    if ($editMessage === '' && !empty($selectMap['country']) && array_key_exists('country', $_POST) && !valueInAllowedOptions((string)($update['country'] ?? ''), $selectMap['country'])) {
                        $editMessage = 'Invalid Country. Allowed: ' . implode(' | ', $selectMap['country']);
                        $editMessageClass = 'danger';
                    }
                    if ($editMessage === '' && !empty($selectMap['software_implementation_timeline']) && array_key_exists('software_implementation_timeline', $_POST) && !valueInAllowedOptions((string)($update['software_implementation_timeline'] ?? ''), $selectMap['software_implementation_timeline'])) {
                        $editMessage = 'Invalid Timeline. Allowed: ' . implode(' | ', $selectMap['software_implementation_timeline']);
                        $editMessageClass = 'danger';
                    }
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
                    $conn = getDbConnection();
                    $dbError = $conn->error ?? 'Unknown database error';
                    $editMessage = 'Failed to update lead. Database error: ' . $dbError;
                    $editMessageClass = 'danger';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_lead') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $editMessage = 'Invalid request token.';
        $editMessageClass = 'danger';
    } elseif (!(function_exists('canDeleteLeadForActor') ? canDeleteLeadForActor((int)($user['id'] ?? 0)) : $canDeleteLead)) {
        $editMessage = 'Access denied.';
        $editMessageClass = 'danger';
    } else {
        $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
        $confirm = strtoupper(trim((string)($_POST['confirm_text'] ?? '')));
        if ($confirm !== 'DELETE') {
            $editMessage = 'Type DELETE to confirm.';
            $editMessageClass = 'danger';
        } elseif ($leadId <= 0) {
            $editMessage = 'Lead not found.';
            $editMessageClass = 'danger';
        } else {
            $deleteFiles = !empty($_POST['delete_files']);
            if (deleteSingleLead($leadId, $deleteFiles, (int)($user['id'] ?? 0))) {
                $editMessage = 'Lead deleted.';
                $editMessageClass = 'success';
            } else {
                $editMessage = 'Failed to delete lead.';
                $editMessageClass = 'danger';
            }
        }
    }
}

// Filters
$campaignId = $_GET['campaign_id'] ?? '';
$clientCode = trim((string)($_GET['client_code'] ?? ''));
$agentId = $_GET['agent_id'] ?? '';
$filterType = strtolower(trim((string)($_GET['filter_type'] ?? 'overall')));
if (!in_array($filterType, ['overall', 'today', 'monthly', 'quarterly', 'custom'], true)) $filterType = 'overall';
$dateFromInput = (string)($_GET['date_from'] ?? '');
$dateToInput = (string)($_GET['date_to'] ?? '');
$qaStatus = $_GET['qa_status'] ?? '';
$clientDeliveryStatus = $_GET['client_delivery_status'] ?? '';
$formFilled = $_GET['form_filled'] ?? '';
$search = $_GET['search'] ?? '';
$isVendorMode = ($mode === 'vendor');
$view = $_GET['view'] ?? 'table';
$view = in_array($view, ['table','cards'], true) ? $view : 'table';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 25;



$perPage = min(500, $perPage);
$dateFrom = '';
$dateTo = '';
$dateRangeLabel = 'Overall';
$normalizeDate = static function (string $value): string {
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
};
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
if ($filterType === 'today') {
    $dateFrom = $opsNow->format('Y-m-d');
    $dateTo = $dateFrom;
    $dateRangeLabel = 'Today';
} elseif ($filterType === 'monthly') {
    $dateFrom = $opsNow->modify('first day of this month')->format('Y-m-d');
    $dateTo = $opsNow->modify('last day of this month')->format('Y-m-d');
    $dateRangeLabel = 'This Month';
} elseif ($filterType === 'quarterly') {
    $monthNum = (int)$opsNow->format('n');
    $quarterStartMonth = (int)(floor(($monthNum - 1) / 3) * 3) + 1;
    $quarterStart = new DateTimeImmutable($opsNow->format('Y') . '-' . sprintf('%02d', $quarterStartMonth) . '-01', $opsNow->getTimezone());
    $dateFrom = $quarterStart->format('Y-m-d');
    $dateTo = $quarterStart->modify('+2 months')->modify('last day of this month')->format('Y-m-d');
    $dateRangeLabel = 'This Quarter';
} elseif ($filterType === 'custom') {
    $dateFrom = $normalizeDate($dateFromInput);
    $dateTo = $normalizeDate($dateToInput);
    if ($dateFrom === '' && $dateTo !== '') $dateFrom = $dateTo;
    if ($dateTo === '' && $dateFrom !== '') $dateTo = $dateFrom;
    if ($dateFrom !== '' && $dateTo !== '' && $dateTo < $dateFrom) {
        $tmp = $dateFrom;
        $dateFrom = $dateTo;
        $dateTo = $tmp;
    }
    $dateRangeLabel = ($dateFrom !== '' || $dateTo !== '') ? 'Custom Range' : 'Custom';
}
$exportDateFrom = $filterType === 'custom' ? $dateFromInput : $dateFrom;
$exportDateTo = $filterType === 'custom' ? $dateToInput : $dateTo;

$filters = [];
if (!empty($campaignId)) { $filters['campaign_id'] = (int)$campaignId; }
if ($clientCode !== '') { $filters['client_code'] = $clientCode; }
if (!empty($agentId)) { $filters['agent_id'] = (int)$agentId; }
if (!empty($dateFrom)) { $filters['date_from'] = $dateFrom; }
if (!empty($dateTo)) { $filters['date_to'] = $dateTo; }
if (!empty($qaStatus)) { $filters['qa_status'] = $qaStatus; }
if (!empty($clientDeliveryStatus)) { $filters['client_delivery_status'] = $clientDeliveryStatus; }
if (!empty($formFilled)) { $filters['form_filled'] = $formFilled; }
if (!empty($search)) { $filters['search'] = $search; }

if ($isVendorMode && !$isVendor && !$isClient) {
    $filters['vendor_only'] = true;
}

if ($isVendor) {
    $filters['vendor_id'] = (int)($user['vendor_id'] ?? 0);
    if ($user['role'] === 'vendor_user') {
        $conn = getDbConnection();
        $assigned = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $assigned[] = (int)$r['campaign_id'];
        $stmt->close();
        $filters['campaign_ids'] = $assigned;
    }
} elseif ($isClient) {
    $filters['client_id'] = (int)($user['client_id'] ?? 0);
    if ($user['role'] === 'client_sdr') {
        $conn = getDbConnection();
        $assigned = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $assigned[] = (int)$r['campaign_id'];
        $stmt->close();
        $filters['campaign_ids'] = $assigned;
    }
} else {
    $privForThisPage = $isPrivInternal || ($isVendorMode && $isEmailDept);
    if (!$privForThisPage) {
        $filters['agent_id'] = (int)($user['id'] ?? 0);
    }
}
$filters['include_submission'] = true;

$leadsData = getLeads($filters, $perPage, $page);
$leads = $leadsData['leads'];
$total = $leadsData['total'];
$totalPages = $leadsData['totalPages'];
$statusSummaryFilters = $filters;
unset($statusSummaryFilters['qa_status'], $statusSummaryFilters['client_delivery_status']);
$statusSummary = function_exists('getLeadWorkflowCounts') ? getLeadWorkflowCounts($statusSummaryFilters) : ['qa_counts' => [], 'client_delivery_counts' => []];
$visibleCount = count($leads);
$activeFilterCount = count(array_filter([
    $filterType !== 'overall' ? 1 : 0,
    $campaignId !== '' ? 1 : 0,
    $clientCode !== '' ? 1 : 0,
    $agentId !== '' ? 1 : 0,
    $qaStatus !== '' ? 1 : 0,
    $clientDeliveryStatus !== '' ? 1 : 0,
    $formFilled !== '' ? 1 : 0,
    trim((string)$search) !== '' ? 1 : 0,
]));
$getRecordingUrl = function(array $lead, bool $inline = true): string {
    $leadId = (int)($lead['id'] ?? 0);
    $lfId = (int)($lead['call_recording_id'] ?? 0);
    if ($lfId > 0 && function_exists('appBuildLeadFileDownloadUrl')) {
        return appBuildLeadFileDownloadUrl($lfId, $inline);
    }

    $rp = (string)($lead['recording_path'] ?? '');
    $rp = trim($rp);
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

// Dropdown data
$campaigns = [];
if ($isVendor || $isClient || $isPrivInternal) {
    $campaigns = getAllCampaignsBasic();
} else {
    $scopeMap = getTeamVisibleCampaignIdsForUser((int)($user['id'] ?? 0), (isQA() ? getUserAssignedCampaignIds((int)($user['id'] ?? 0), ['qa_campaign_assignments']) : getUserAssignedCampaignIds((int)($user['id'] ?? 0))));
    $campaigns = $scopeMap === null ? getAllCampaignsBasic() : getCampaignsBasicByIds(array_keys($scopeMap), false);
}
$agents = getAgents();
$clientCodeOptions = [];
foreach ($campaigns as $campaignRow) {
    $code = trim((string)($campaignRow['client_code'] ?? ''));
    if ($code === '') {
        continue;
    }
    $clientCodeOptions[$code] = $code;
}
if (!empty($clientCodeOptions)) {
    natcasesort($clientCodeOptions);
}
?>
<?php $pageTitle = $isVendorMode ? 'Vendor Leads' : 'All Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

<div class="container-fluid px-0 leads-workspace">
    <div class="leads-page-header leads-hero mb-4">
        <div>
            <div class="leads-eyebrow mb-2"><i class="bi bi-layout-text-window"></i>Lead Operations</div>
            <h2 class="leads-page-title"><?php echo $isVendorMode ? 'Vendor Leads' : 'All Leads'; ?></h2>
            <div class="leads-page-subtitle">Manage lead review, recordings, QA updates, exports, and owner-level filtering from a single workspace.</div>
        </div>
        <div class="leads-page-actions">
            <div class="btn-group btn-group-sm" role="group" aria-label="View toggle">
                <?php $qsBase = $_GET; unset($qsBase['page']); ?>
                <a class="btn btn-outline-primary <?php echo $view === 'table' ? 'active' : ''; ?>" href="list?<?php echo htmlspecialchars(http_build_query(array_merge($qsBase, ['view' => 'table']))); ?>">
                    <i class="bi bi-table me-1"></i>Table
                </a>
                <a class="btn btn-outline-primary <?php echo $view === 'cards' ? 'active' : ''; ?>" href="list?<?php echo htmlspecialchars(http_build_query(array_merge($qsBase, ['view' => 'cards']))); ?>">
                    <i class="bi bi-grid-3x3-gap me-1"></i>Cards
                </a>
            </div>
            <?php
                $canExport = hasRole([
                    'admin',
                    'director',
                    'manager_director',
                    'operations_director',
                    'operations_manager',
                    'operations_agent',
                    'sales_director',
                    'sales_manager',
                    'sdr',
                    'agent',
                    'qa',
                    'qa_agent',
                    'qa_manager',
                    'qa_director',
                    'email_marketing_director',
                    'email_marketing_manager',
                    'email_marketing_agent',
                    'email_marketing_executive',
                    'form_filler',
                ]);
            ?>
            <?php if ($canExport): ?>
                <form method="post" action="export.php" target="_blank">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                    <input type="hidden" name="agent_id" value="<?php echo htmlspecialchars($agentId); ?>">
                    <input type="hidden" name="filter_type" value="<?php echo htmlspecialchars($filterType); ?>">
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($exportDateFrom); ?>">
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($exportDateTo); ?>">
                    <input type="hidden" name="client_code" value="<?php echo htmlspecialchars($clientCode); ?>">
                    <input type="hidden" name="qa_status" value="<?php echo htmlspecialchars($qaStatus); ?>">
                    <input type="hidden" name="client_delivery_status" value="<?php echo htmlspecialchars($clientDeliveryStatus); ?>">
                    <input type="hidden" name="form_filled" value="<?php echo htmlspecialchars($formFilled); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php
                        $allowAllCampaignsExportUi = isAdmin() || isDirector() || isSalesDirector() || isSalesManager() || hasRole(['manager_director','operations_director','qa_director','qa_manager','qa_agent']);
                        $mustSelectCampaign = !$allowAllCampaignsExportUi;
                        $disableExport = $mustSelectCampaign && ((int)$campaignId <= 0);
                        $exportTitle = '';
                        if ($disableExport) $exportTitle = 'Select a campaign to export';
                        elseif ((int)$campaignId <= 0) $exportTitle = 'Exports current filtered leads (without custom fields)';
                    ?>
                    <button type="submit" name="export" class="btn btn-outline-primary btn-sm" <?php echo $disableExport ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars($exportTitle); ?>">
                        <i class="bi bi-download me-1"></i> Export CSV
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($editMessage)): ?>
        <div class="alert alert-<?php echo $editMessageClass; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <?php echo htmlspecialchars($editMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm leads-panel mb-3">
        <div class="card-body">
        <form method="get" action="list" class="row g-3 align-items-end leads-filter-form">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            <div class="col-xl-3 col-lg-4 col-md-6">
                <label for="search" class="form-label">Quick Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, company...">
                </div>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="filter_type" class="form-label">Filter Type</label>
                <select class="form-select form-select-sm" id="filter_type" name="filter_type">
                    <option value="overall" <?php echo $filterType === 'overall' ? 'selected' : ''; ?>>Overall</option>
                    <option value="today" <?php echo $filterType === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="monthly" <?php echo $filterType === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="quarterly" <?php echo $filterType === 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                    <option value="custom" <?php echo $filterType === 'custom' ? 'selected' : ''; ?>>Custom</option>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="campaign_id" class="form-label">Campaign</label>
                <select class="form-select form-select-sm" id="campaign_id" name="campaign_id">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaigns as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($campaignId == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="client_code" class="form-label">Client Code</label>
                <select class="form-select form-select-sm" id="client_code" name="client_code">
                    <option value="">All Client Codes</option>
                    <?php foreach ($clientCodeOptions as $code): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $clientCode === $code ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($code); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="agent_id" class="form-label">Agent</label>
                <select class="form-select form-select-sm" id="agent_id" name="agent_id">
                    <option value="">All Agents</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php echo ($agentId == $a['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)($a['full_name'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="qa_status" class="form-label">QA Status</label>
                <select class="form-select form-select-sm" id="qa_status" name="qa_status">
                    <option value="">All Status</option>
                    <?php foreach ($qaStatusOptions as $qaStatusValue => $qaStatusLabel): ?>
                        <option value="<?php echo htmlspecialchars($qaStatusValue); ?>" <?php echo normalizeQaStatus((string)$qaStatus) === normalizeQaStatus($qaStatusValue) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($qaStatusLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="client_delivery_status" class="form-label">Client Delivery</label>
                <select class="form-select form-select-sm" id="client_delivery_status" name="client_delivery_status">
                    <option value="">All Status</option>
                    <?php foreach (getClientDeliveryStatusOptions() as $deliveryValue => $deliveryLabel): ?>
                        <option value="<?php echo htmlspecialchars($deliveryValue); ?>" <?php echo normalizeClientDeliveryStatus((string)$clientDeliveryStatus) === normalizeClientDeliveryStatus($deliveryValue) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($deliveryLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="form_filled" class="form-label">Form Status</label>
                <select class="form-select form-select-sm" id="form_filled" name="form_filled">
                    <option value="">All</option>
                    <option value="Yes" <?php echo ($formFilled == 'Yes') ? 'selected' : ''; ?>>Filled</option>
                    <option value="No" <?php echo ($formFilled == 'No') ? 'selected' : ''; ?>>Not Filled</option>
                </select>
            </div>
            <div class="col-xl-1 col-lg-2 col-md-3">
                <label for="per_page" class="form-label">Rows</label>
                <select class="form-select form-select-sm" id="per_page" name="per_page">
                    <option value="25" <?php echo ($perPage == 25) ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo ($perPage == 50) ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo ($perPage == 100) ? 'selected' : ''; ?>>100</option>
                    <option value="500" <?php echo ($perPage == 500) ? 'selected' : ''; ?>>500</option>
                </select>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
                <a href="list" class="btn btn-light btn-sm flex-grow-1">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
            <div class="col-12 <?php echo $filterType === 'custom' ? '' : 'd-none'; ?>" id="advancedFiltersSection">
                <div class="border rounded-3 p-3 bg-light-subtle">
                    <div class="small fw-semibold text-uppercase text-muted mb-3">Advanced Filter</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="date_from" class="form-label small">Date From</label>
                            <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFromInput); ?>" <?php echo $filterType === 'custom' ? '' : 'disabled'; ?>>
                        </div>
                        <div class="col-md-3">
                            <label for="date_to" class="form-label small">Date To</label>
                            <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateToInput); ?>" <?php echo $filterType === 'custom' ? '' : 'disabled'; ?>>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="small text-muted">Choose `Custom` in Filter Type to filter leads by a manual date range.</div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm leads-panel mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="small fw-semibold text-uppercase text-muted mb-2">QA Status Counts</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (getQaStatusOptions() as $statusValue => $statusLabel): ?>
                            <span class="leads-filter-pill">
                                <i class="bi bi-shield-check"></i><?php echo htmlspecialchars($statusLabel); ?>: <?php echo number_format((int)($statusSummary['qa_counts'][$statusValue] ?? 0)); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="small fw-semibold text-uppercase text-muted mb-2">Client Delivery Counts</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (getClientDeliveryStatusOptions() as $statusValue => $statusLabel): ?>
                            <span class="leads-filter-pill">
                                <i class="bi bi-truck"></i><?php echo htmlspecialchars($statusLabel); ?>: <?php echo number_format((int)($statusSummary['client_delivery_counts'][$statusValue] ?? 0)); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm leads-panel mb-3">
        <div class="leads-table-toolbar">
            <div class="leads-table-toolbar-main">
                <span class="leads-table-count"><i class="bi bi-table"></i> Showing <?php echo number_format($visibleCount); ?> of <?php echo number_format($total); ?> leads</span>
                <div>
                    <div class="leads-table-title"><?php echo $view === 'cards' ? 'Card View Results' : 'Lead Table Results'; ?></div>
                    <div class="leads-table-subtitle">The filtered result count stays visible above the dataset so you do not need to scroll to the footer.</div>
                </div>
            </div>
            <div class="leads-table-toolbar-side">
                <?php if ($activeFilterCount > 0): ?>
                    <span class="leads-filter-pill"><i class="bi bi-funnel"></i><?php echo $activeFilterCount; ?> filters active</span>
                <?php endif; ?>
                <span class="leads-filter-pill"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($dateRangeLabel); ?></span>
                <span class="leads-filter-pill"><i class="bi bi-grid-1x2"></i><?php echo htmlspecialchars(ucfirst($view)); ?> view</span>
                <span class="leads-filter-pill"><i class="bi bi-layout-text-window-reverse"></i><?php echo (int)$perPage; ?> rows per page</span>
            </div>
        </div>
    </div>

    <?php if ($view === 'cards'): ?>
        <div class="row g-3">
            <?php if (empty($leads)): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm leads-panel">
                        <div class="card-body leads-empty-state">No leads found matching your criteria.</div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($leads as $lead): ?>
                    <?php
                        $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                        $recInline = $getRecordingUrl($lead, true);
                        $recDl = $getRecordingUrl($lead, false);
                        $recCount = (int)($lead['recording_count'] ?? 0);
                        $createdAtLabel = !empty($lead['created_at']) ? date('d M Y, h:i A', strtotime((string)$lead['created_at'])) : 'Not available';
                        $qaStatusRow = normalizeQaStatus((string)($lead['qa_status'] ?? 'Pending'));
                        $qaClass = qaStatusBadgeClass($qaStatusRow);
                    ?>
                    <div class="col-xl-4 col-lg-6">
                        <div class="card border-0 shadow-sm leads-panel h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="min-w-0">
                                        <div class="fw-bold text-truncate"><?php echo htmlspecialchars($name !== '' ? $name : '—'); ?></div>
                                        <div class="text-muted small text-truncate"><?php echo htmlspecialchars($lead['email'] ?? ''); ?></div>
                                        <div class="text-muted small text-truncate"><?php echo htmlspecialchars($lead['contact_phone'] ?? ''); ?></div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars(qaStatusDisplayLabel($qaStatusRow)); ?></span>
                                        <?php if (!empty($lead['reviewer_name'])): ?>
                                            <div class="text-muted small mt-1" style="font-size:0.7rem;">by <?php echo htmlspecialchars($lead['reviewer_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr class="my-3">

                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="text-muted small">Company</div>
                                        <div class="fw-semibold small text-truncate"><?php echo htmlspecialchars($lead['company_name'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Title</div>
                                        <div class="small text-truncate"><?php echo htmlspecialchars($lead['job_title'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Campaign</div>
                                        <div class="small text-truncate"><?php echo htmlspecialchars(crmCampaignNameOnly((string)($lead['campaign_name'] ?? 'N/A'), (string)($lead['client_code'] ?? ''), (string)($lead['campaign_code'] ?? ''))); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Agent</div>
                                        <div class="small text-truncate"><?php echo htmlspecialchars($lead['agent_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-12">
                                        <div class="text-muted small">Form</div>
                                        <?php if (normalizeFormDone((string)($lead['form_done'] ?? 'No')) === 'Yes'): ?>
                                            <span class="badge bg-success-subtle text-success border">Filled</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border">Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-12">
                                        <div class="text-muted small">Created Date</div>
                                        <div class="small text-truncate"><?php echo htmlspecialchars($createdAtLabel); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-white border-0 pt-0">
                                <div class="leads-action-group">
                                    <?php 
                                        $recCount = (int)($lead['recording_count'] ?? 0);
                                        if ($recCount === 0 && !empty($lead['recording_path'])) $recCount = 1;
                                    ?>
                                    <?php if ($recCount > 0): ?>
                                        <button type="button" class="btn btn-sm btn-light border position-relative" data-bs-toggle="modal" data-bs-target="#recordingModal" data-lead-name="<?php echo htmlspecialchars($name); ?>" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Play Recordings">
                                            <i class="bi bi-play-fill"></i>
                                            <?php if ($recCount > 1): ?>
                                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem; padding: 0.2rem 0.4rem;">
                                                    <?php echo $recCount; ?>
                                                </span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#qaModal"
                                        data-lead-id="<?php echo (int)$lead['id']; ?>"
                                        data-lead-name="<?php echo htmlspecialchars($name); ?>"
                                        data-qa-status="<?php echo htmlspecialchars($qaStatusRow); ?>"
                                        data-qa-comment="<?php echo htmlspecialchars($lead['qa_comment'] ?? ''); ?>"
                                        data-qa-client-comment="<?php echo htmlspecialchars($lead['qa_client_comment'] ?? ''); ?>"
                                        data-client-delivery-status="<?php echo htmlspecialchars(normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Pending'))); ?>"
                                        data-recording-inline="<?php echo htmlspecialchars($recInline); ?>"
                                        data-recording-dl="<?php echo htmlspecialchars($recDl); ?>" title="Update QA">
                                        <i class="bi bi-shield-check"></i>
                                    </button>
                                    <?php $canEditRow = $isPrivInternal || ((int)($lead['agent_id'] ?? 0) === (int)($user['id'] ?? 0)); ?>
                                    <?php if ($canEditRow): ?>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editLeadModal" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Edit Lead">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-light border" href="view?id=<?php echo (int)$lead['id']; ?>" title="View Full Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($canDeleteLead): ?>
                                        <button type="button" class="btn btn-sm btn-danger delete-lead" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Delete Lead">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="mt-3 leads-footer-bar">
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
                            for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm leads-panel">
        <div class="table-responsive leads-table-wrap">
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
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">No leads found matching your criteria.</td>
                        </tr>
                    <?php else: ?>
                        <?php $serialStart = (($page - 1) * $perPage) + 1; ?>
                        
                        <?php foreach ($leads as $i => $lead): ?>
                            <?php 
                                $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                                $recInline = $getRecordingUrl($lead, true);
                                $recDl = $getRecordingUrl($lead, false);
                                $recCount = (int)($lead['recording_count'] ?? 0);
                                $canEditRow = $isPrivInternal || ((int)($lead['agent_id'] ?? 0) === (int)($user['id'] ?? 0));
                            ?>
                            <tr>
                                <td class="ps-3 text-muted small"><?php echo $serialStart + $i; ?></td>
                                   <td>
                                    <div class="leads-row-primary"><?php echo htmlspecialchars(!empty($lead['created_at']) ? date('d M Y', strtotime((string)$lead['created_at'])) : 'Not available'); ?></div>
                                    <span class="leads-row-secondary"><?php echo htmlspecialchars(!empty($lead['created_at']) ? date('h:i A', strtotime((string)$lead['created_at'])) : 'Creation time unavailable'); ?></span>
                                </td>
                                
                                <td>
                                    <div class="leads-row-primary"><?php echo htmlspecialchars($name); ?></div>
                                    <span class="leads-row-secondary"><?php echo htmlspecialchars('Primary email · ' . ((string)($lead['email'] ?? 'N/A'))); ?></span>
                                    <span class="leads-row-secondary"><?php echo htmlspecialchars('Contact number · ' . ((string)($lead['contact_phone'] ?? 'N/A'))); ?></span>
                                </td>
                             
                                <td>
                                    <div class="leads-row-primary"><?php echo htmlspecialchars($lead['company_name'] ?? ''); ?></div>
                                    <span class="leads-row-secondary"><?php echo htmlspecialchars('Country · ' . ((string)($lead['country'] ?? 'N/A'))); ?></span>
                                    <span class="leads-row-secondary"><?php echo htmlspecialchars('Job title · ' . ((string)($lead['job_title'] ?? 'N/A'))); ?></span>
                                </td>
                                <td>
                                    <div class="leads-campaign-stack">
                                        <div class="leads-campaign-item">
                                            <div class="leads-campaign-value">
                                                <div class="leads-row-primary"><?php echo htmlspecialchars(crmCampaignNameWithClientCode((string)($lead['campaign_name'] ?? 'N/A'), (string)($lead['client_code'] ?? ''), (string)($lead['campaign_code'] ?? ''))); ?></div>
                                            </div>
                                        </div>
                                        <div class="leads-campaign-item">
                                            <div class="leads-campaign-value">
                                                <div class="leads-row-secondary"><?php echo htmlspecialchars('Assigned agent · ' . ((string)($lead['agent_name'] ?? 'N/A'))); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (normalizeFormDone((string)($lead['form_done'] ?? 'No')) === 'Yes'): ?>
                                        <span class="badge bg-success-subtle text-success border">Filled</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $qaStatus = normalizeQaStatus((string)($lead['qa_status'] ?? 'Pending'));
                                    $qaClass = qaStatusBadgeClass($qaStatus);
                                    ?>
                                    <span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars(qaStatusDisplayLabel($qaStatus)); ?></span>
                                    <?php if (!empty($lead['reviewer_name'])): ?>
                                        <div class="small text-muted mt-1" style="font-size: 0.7rem;">by <?php echo htmlspecialchars($lead['reviewer_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3 sticky-actions">
                                <div class="leads-action-group">
                                    <?php 
                                        $recCount = (int)($lead['recording_count'] ?? 0);
                                        if ($recCount === 0 && !empty($lead['recording_path'])) $recCount = 1;
                                    ?>
                                    <?php if ($recCount > 0): ?>
                                        <button type="button" class="btn btn-light border position-relative" data-bs-toggle="modal" data-bs-target="#recordingModal" data-lead-name="<?php echo htmlspecialchars($name); ?>" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Play Recordings">
                                            <i class="bi bi-play-fill"></i>
                                            <?php if ($recCount > 1): ?>
                                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem; padding: 0.2rem 0.4rem;">
                                                    <?php echo $recCount; ?>
                                                </span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endif; ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qaModal" 
                                            data-lead-id="<?php echo (int)$lead['id']; ?>" 
                                            data-lead-name="<?php echo htmlspecialchars($name); ?>" 
                                            data-qa-status="<?php echo htmlspecialchars($qaStatus); ?>" 
                                            data-qa-comment="<?php echo htmlspecialchars($lead['qa_comment'] ?? ''); ?>" 
                                            data-qa-client-comment="<?php echo htmlspecialchars($lead['qa_client_comment'] ?? ''); ?>" 
                                            data-client-delivery-status="<?php echo htmlspecialchars(normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Pending'))); ?>" 
                                            data-recording-inline="<?php echo htmlspecialchars($recInline); ?>"
                                            data-recording-dl="<?php echo htmlspecialchars($recDl); ?>" title="Update QA">
                                            <i class="bi bi-shield-check"></i>
                                        </button>
                                        <?php if ($canEditRow): ?>
                                            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editLeadModal" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Edit Lead">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a class="btn btn-light border" href="view?id=<?php echo (int)$lead['id']; ?>" title="View Full Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($canDeleteLead): ?>
                                            <button type="button" class="btn btn-danger delete-lead" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Delete Lead">
                                                <i class="bi bi-trash"></i>
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
                        <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Prev</a>
                    </li>
                    <?php
                        $window = 2;
                        $startPage = max(1, $page - $window);
                        $endPage = min($totalPages, $page + $window);
                        for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>"><?php echo $i; ?></a>
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
    <?php endif; ?>
</div>

<form id="deleteLeadForm" method="post" action="list" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="delete_lead">
    <input type="hidden" name="lead_id" id="deleteLeadId" value="">
    <input type="hidden" name="confirm_text" id="deleteConfirmText" value="">
    <input type="hidden" name="delete_files" id="deleteFiles" value="0">
</form>

<!-- Modals -->
<!-- Recording Player Modal -->
<div class="modal fade" id="recordingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title">Lead Recordings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body py-3">
        <h6 id="recLeadName" class="mb-3 fw-bold text-primary px-3"></h6>
        <div id="recModalList" class="px-3">
          <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- QA Update Modal -->
<div class="modal fade" id="qaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post" action="../qa/action">
                <div class="modal-header">
                    <h5 class="modal-title">Update Lead Quality</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    <input type="hidden" id="qa_lead_id" name="lead_id">
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted text-uppercase fw-bold">Lead Name</label>
                        <div id="qa_lead_name" class="fw-bold h6"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="qa_status_select" class="form-label small text-muted text-uppercase fw-bold">Quality Status</label>
                        <select class="form-select" id="qa_status_select" name="qa_status" required>
                            <?php foreach ($qaStatusOptions as $qaStatusValue => $qaStatusLabel): ?>
                                <option value="<?php echo htmlspecialchars($qaStatusValue); ?>"><?php echo htmlspecialchars($qaStatusLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="client_delivery_status_select" class="form-label small text-muted text-uppercase fw-bold">Client Delivery Status</label>
                        <select class="form-select" id="client_delivery_status_select" name="client_delivery_status" required>
                            <?php foreach (getClientDeliveryStatuses() as $v): ?>
                                <option value="<?php echo htmlspecialchars($v); ?>"><?php echo htmlspecialchars(clientDeliveryStatusDisplayLabel($v)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="qa_comment_internal" class="form-label small text-muted text-uppercase fw-bold">Internal Comments</label>
                        <textarea class="form-control" id="qa_comment_internal" name="qa_comment_internal" rows="3" placeholder="Internal QA notes..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="qa_comment_client" class="form-label small text-muted text-uppercase fw-bold">Client Comments (visible to client)</label>
                        <textarea class="form-control" id="qa_comment_client" name="qa_comment_client" rows="3" placeholder="Client-facing notes..."></textarea>
                    </div>
                    
                    <div id="qaRecordingContainer" class="p-3 bg-light rounded-3 mb-3" style="display:none;">
                        <label class="form-label small text-muted text-uppercase fw-bold d-block">Recording Audit</label>
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
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Lead Modal -->
<div class="modal fade" id="editLeadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title">Edit Lead</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="editLeadBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
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

    // Recording Modal Logic
    const recordingModal = document.getElementById('recordingModal');
    if (recordingModal) {
        recordingModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const name = button.getAttribute('data-lead-name');
            const leadId = button.getAttribute('data-lead-id');
            document.getElementById('recLeadName').textContent = name;
            
            const list = document.getElementById('recModalList');
            list.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
            
            fetch('details?id=' + encodeURIComponent(leadId) + '&format=json&only_recordings=1', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => {
                if (!r.ok) throw new Error('Network response was not ok');
                return r.json();
            })
            .then(data => {
                if (data.ok && data.recordings) {
                    const recs = data.recordings;
                    
                    if (recs.length > 0) {
                        list.innerHTML = recs.map((r, i) => `
                            <div class="mb-3 p-2 border rounded bg-light">
                                <div class="recording-player-shell" data-recording-player>
                                    <div class="recording-head">
                                        <span class="fw-bold small">Recording #${i+1}</span>
                                        <a href="${r.url}" download class="text-primary text-decoration-none small">Download</a>
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

    // QA Modal Logic
    const qaModal = document.getElementById('qaModal');
    if (qaModal) {
        qaModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const leadId = button.getAttribute('data-lead-id');
            const name = button.getAttribute('data-lead-name');
            const qaStatus = button.getAttribute('data-qa-status');
            const qaComment = button.getAttribute('data-qa-comment');
            const qaClientComment = button.getAttribute('data-qa-client-comment');
            const clientDeliveryStatus = button.getAttribute('data-client-delivery-status');
            const recInline = button.getAttribute('data-recording-inline') || button.getAttribute('data-recording') || '';
            const recDl = button.getAttribute('data-recording-dl') || recInline;

            document.getElementById('qa_lead_id').value = leadId;
            document.getElementById('qa_lead_name').textContent = name;
            document.getElementById('qa_status_select').value = qaStatus || 'Pending';
            document.getElementById('client_delivery_status_select').value = clientDeliveryStatus || 'Pending';
            document.getElementById('qa_comment_internal').value = qaComment || '';
            document.getElementById('qa_comment_client').value = qaClientComment || '';

            const container = document.getElementById('qaRecordingContainer');
            const player = document.getElementById('qaAudioPlayer');
            const download = document.getElementById('qaDownloadLink');

            if (recInline && recInline !== 'null') {
                container.style.display = 'block';
                player.src = recInline;
                download.href = recDl;
                enhanceRecordingPlayers(container);
            } else {
                container.style.display = 'none';
                player.src = '';
                download.href = '#';
            }
        });
        qaModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('qaAudioPlayer').pause();
            document.getElementById('qaAudioPlayer').src = '';
        });
    }

    const editLeadModalEl = document.getElementById('editLeadModal');
    if (editLeadModalEl) {
        editLeadModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const leadId = button.getAttribute('data-lead-id');
            document.getElementById('editLeadBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>';
            fetch('details?id=' + encodeURIComponent(leadId) + '&edit=1&format=html&embed=1&post_to=list', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(r => r.text())
                .then(html => { document.getElementById('editLeadBody').innerHTML = html; })
                .catch(() => { document.getElementById('editLeadBody').innerHTML = '<div class="alert alert-danger m-3">Error loading edit form.</div>'; });
        });
    }

    document.querySelectorAll('.delete-lead').forEach(btn => {
        btn.addEventListener('click', function() {
            const leadId = this.getAttribute('data-lead-id');
            if (!leadId) return;
            if (!confirm('Are you sure you want to delete this lead?')) return;
            const text = prompt('Type DELETE to confirm.');
            if ((text || '').trim().toUpperCase() !== 'DELETE') return;
            const delFiles = confirm('Also delete uploaded lead files/recording from server?');
            document.getElementById('deleteLeadId').value = leadId;
            document.getElementById('deleteConfirmText').value = 'DELETE';
            document.getElementById('deleteFiles').value = delFiles ? '1' : '0';
            document.getElementById('deleteLeadForm').submit();
        });
    });

    const filterTypeEl = document.getElementById('filter_type');
    const advancedFiltersSection = document.getElementById('advancedFiltersSection');
    const dateFromEl = document.getElementById('date_from');
    const dateToEl = document.getElementById('date_to');
    if (filterTypeEl && advancedFiltersSection && dateFromEl && dateToEl) {
        const syncLeadFilterType = () => {
            const isCustom = filterTypeEl.value === 'custom';
            advancedFiltersSection.classList.toggle('d-none', !isCustom);
            dateFromEl.disabled = !isCustom;
            dateToEl.disabled = !isCustom;
        };
        filterTypeEl.addEventListener('change', syncLeadFilterType);
        syncLeadFilterType();
    }
});
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>


