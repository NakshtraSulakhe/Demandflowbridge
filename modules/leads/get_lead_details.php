<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('leads.details.page', []);

// Release session lock early to prevent blocking other requests
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Only ensure schema if not an AJAX request to speed up modal loading
if (!isAjaxRequest() && empty($_GET['edit']) && (($_GET['format'] ?? '') !== 'html') && empty($_GET['embed']) && empty($_GET['post_to'])) {
    ensureDatabaseSchema();
}

$currentUser = getCurrentUser();
$conn = getDbConnection();

$leadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$leadCode = isset($_GET['lead_id']) ? trim($_GET['lead_id']) : '';
if ($leadId <= 0 && $leadCode === '') {
    http_response_code(400);
    echo 'Missing lead identifier';
    exit;
}

if ($leadId > 0) {
    // Check if we have a campaign ID to use the dynamic lead table
    $stmt = $conn->prepare("SELECT campaign_id FROM leads WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $leadId);
    $stmt->execute();
    $cRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $campaignId = (int)($cRow['campaign_id'] ?? 0);
    if ($campaignId > 0) {
        $lead = getLeadByIdDynamic($leadId, $campaignId);
    } else {
        $lead = getLeadById($leadId);
    }
} else {
    $lead = getLeadByCode($leadCode);
}
if (!$lead) {
    http_response_code(404);
    echo 'Lead not found';
    exit;
}

$currentRole = (string)($currentUser['role'] ?? '');
if (!canUserAccessLead($currentUser, $lead)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$leadId = (int)($lead['id'] ?? $leadId);
$recordings = getLeadRecordings($leadId);

$lead = enrichLeadRow($lead);

$campaignId = (int)($lead['campaign_id'] ?? 0);

$format = $_GET['format'] ?? '';
$edit = !empty($_GET['edit']);
if ($edit) {
    $format = 'html';
}
if ($format === '') {
    $format = 'json';
}

$buildRecordingUrls = function(int $fileId, string $path) use ($leadId): array {
    $path = trim($path);
    if ($path === '' || strtolower($path) === 'null') {
        return ['url' => '', 'dl' => ''];
    }
    if (preg_match('/^https?:\\/\\//i', $path)) {
        return ['url' => $path, 'dl' => $path];
    }

    if ($fileId > 0 && function_exists('appBuildLeadFileDownloadUrl')) {
        return [
            'url' => appBuildLeadFileDownloadUrl($fileId, true),
            'dl' => appBuildLeadFileDownloadUrl($fileId, false),
        ];
    }

    $normalized = function_exists('appNormalizeUploadsRelPath') ? appNormalizeUploadsRelPath($path) : '';
    if ($normalized !== '' && function_exists('appBuildLeadPathDownloadUrl')) {
        return [
            'url' => appBuildLeadPathDownloadUrl($leadId, $normalized, true),
            'dl' => appBuildLeadPathDownloadUrl($leadId, $normalized, false),
        ];
    }

    $fallback = rtrim(appBasePath(), '/') . '/' . ltrim($path, '/');
    return ['url' => $fallback, 'dl' => $fallback];
};

$onlyRecordings = !empty($_GET['only_recordings']);
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60'); // Cache for 60 seconds

    // Process recordings to add proper URLs
    $processedRecordings = [];
    foreach ($recordings as $r) {
        $fid = (int)($r['id'] ?? 0);
        $rp = (string)($r['file_path'] ?? '');
        $urls = $buildRecordingUrls($fid, $rp);
        $processedRecordings[] = array_merge($r, $urls);
    }

    // Also add legacy recording_path if not already in processedRecordings
    $primaryPath = trim((string)($lead['recording_path'] ?? ''));
    if ($primaryPath !== '') {
        $primaryInList = false;
        foreach ($processedRecordings as $pr) {
            if ($pr['file_path'] === $primaryPath) {
                $primaryInList = true;
                break;
            }
        }
        if (!$primaryInList) {
            $urls = $buildRecordingUrls(0, $primaryPath);
            array_unshift($processedRecordings, [
                'id' => 0, 
                'file_path' => $primaryPath, 
                'uploaded_at' => $lead['created_at'],
                'url' => $urls['url'],
                'dl' => $urls['dl'],
            ]);
        }
    }

    if ($onlyRecordings) {
        // Ultra lightweight response just for recordings
        echo json_encode([
            'ok' => true,
            'recordings' => $processedRecordings
        ]);
        exit;
    }

    $qaStatus = $lead['qa_status'] ?? '';
    $qualityStatus = 'pending';
    switch ($qaStatus) {
        case 'Qualified': $qualityStatus = 'approved'; break;
        case 'Disqualified': $qualityStatus = 'rejected'; break;
        case 'Rectified': $qualityStatus = 'approved'; break;
        case 'Duplicate': $qualityStatus = 'rejected'; break;
        default: $qualityStatus = 'pending';
    }

    echo json_encode([
        'ok' => true,
        'recordings' => $processedRecordings,
        'id' => (int)($lead['id'] ?? 0),
        'lead_id' => $lead['lead_id'] ?? '',
        'campaign_id' => isset($lead['campaign_id']) ? (int)$lead['campaign_id'] : null,
        'agent_id' => isset($lead['agent_id']) ? (int)$lead['agent_id'] : null,
        'first_name' => $lead['first_name'] ?? '',
        'last_name' => $lead['last_name'] ?? '',
        'agent_name' => $lead['agent_name'] ?? '',
        'campaign_name' => $lead['campaign_name'] ?? '',
        'job_title' => $lead['job_title'] ?? '',
        'phone' => $lead['contact_phone'] ?? ($lead['phone'] ?? ''),
        'contact_phone' => $lead['contact_phone'] ?? ($lead['phone'] ?? ''),
        'email' => $lead['email'] ?? '',
        'linkedin_link' => $lead['linkedin_link'] ?? '',
        'qa_status' => $lead['qa_status'] ?? 'Pending',
        'client_delivery_status' => $lead['client_delivery_status'] ?? 'Pending',
        'quality_status' => $qualityStatus,
        'quality_comments' => $lead['qa_comment'] ?? '',
        'quality_client_comments' => $lead['qa_client_comment'] ?? '',
        'qa_reviewed_by' => isset($lead['qa_reviewed_by']) ? (int)$lead['qa_reviewed_by'] : null,
        'reviewer_name' => $lead['reviewer_name'] ?? '',
        'created_at' => $lead['created_at'] ?? null,
        'updated_at' => $lead['updated_at'] ?? null,
        'qa_updated_at' => $lead['qa_updated_at'] ?? null,
        'form_filled_time' => $lead['form_filled_time'] ?? null,
        'form_done' => normalizeFormDone((string)($lead['form_done'] ?? 'No')),
        'recording_path' => $lead['recording_path'] ?? '',
        'industry' => $lead['industry'] ?? '',
        'company_name' => $lead['company_name'] ?? '',
        'company_linkedin' => $lead['company_linkedin'] ?? '',
        'company_size' => $lead['company_size'] ?? '',
        'country' => $lead['country'] ?? '',
        'software_implementation_timeline' => $lead['software_implementation_timeline'] ?? ($lead['implementation_timeline'] ?? ''),
        'lead_comment' => $lead['lead_comment'] ?? '',
    ]);
    exit;
}

// Data extraction for HTML formats (Edit and Details)
header('Content-Type: text/html; charset=utf-8');
// Add cache control for AJAX requests to speed up modals
if (isAjaxRequest()) {
    header('Cache-Control: private, max-age=60'); // Cache for 60 seconds
}
$embed = !empty($_GET['embed']) || isAjaxRequest();

$fullName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
$comment = $lead['lead_comment'] ?? '';
$qaStatus = (string)($lead['qa_status'] ?? 'Pending');
$qaClass = 'bg-warning-subtle text-warning';
if ($qaStatus === 'Qualified' || $qaStatus === 'Rectified') $qaClass = 'bg-success-subtle text-success';
if ($qaStatus === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
if ($qaStatus === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
$formDone = normalizeFormDone((string)($lead['form_done'] ?? 'No'));
$formClass = ($formDone === 'Yes') ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
$createdAt = !empty($lead['created_at']) ? date('Y-m-d H:i', strtotime($lead['created_at'])) : '—';
$rawSubmittedAt = $lead['form_filled_time'] ?? $lead['form_filled_at'] ?? $lead['submitted_at'] ?? null;
$formFilledAt = !empty($rawSubmittedAt) ? date('Y-m-d H:i', strtotime((string)$rawSubmittedAt)) : '—';
$rawQaUpdatedAt = $lead['qa_updated_at'] ?? $lead['qa_reviewed_at'] ?? null;
$qaUpdatedAt = !empty($rawQaUpdatedAt) ? date('Y-m-d H:i', strtotime((string)$rawQaUpdatedAt)) : '—';

$campaignId = (int)($lead['campaign_id'] ?? 0);
$leadDbId = (int)($lead['id'] ?? 0);
$submission = ($campaignId > 0 && $leadDbId > 0) ? getLatestFormSubmissionForLead($leadDbId, $campaignId) : null;
if ($formFilledAt === '—' && !empty($submission['submitted_at'])) {
    $formFilledAt = date('Y-m-d H:i', strtotime((string)$submission['submitted_at']));
}
$formData = is_array($submission['data'] ?? null) ? $submission['data'] : [];

// Pre-index normalized keys to speed up link extraction and form picking
$indexedData = [];
foreach ($formData as $k => $v) {
    $nk = normalizeSubmissionKey((string)$k);
    if ($nk !== '') $indexedData[$nk] = $v;
}

$pickFrom = function(array $data, array $aliases): string {
    foreach ($aliases as $a) {
        if (array_key_exists($a, $data)) {
            $val = $data[$a];
            if ($val !== null && !is_array($val) && trim((string)$val) !== '') return trim((string)$val);
        }
        $na = normalizeSubmissionKey($a);
        if ($na !== $a && array_key_exists($na, $data)) {
            $val = $data[$na];
            if ($val !== null && !is_array($val) && trim((string)$val) !== '') return trim((string)$val);
        }
    }
    return '';
};

$fastPick = function(array $aliases, string $fallback = '') use ($formData, $indexedData, $lead, $pickFrom): string {
    // 1. Try exact/normalized aliases in formData
    $val = $pickFrom($formData, $aliases);
    if ($val !== '') return $val;
    
    // 2. Try normalized aliases in indexedData
    $val = $pickFrom($indexedData, $aliases);
    if ($val !== '') return $val;

    // 3. Try aliases in the lead record itself
    $val = $pickFrom($lead, $aliases);
    if ($val !== '') return $val;

    return trim((string)$fallback);
};

$linkedin = $fastPick(['linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_url','prospect_linkedin_profile','linkedin','linkedin_link_url']);
$companyLinkedin = $fastPick(['company_linkedin','company_linkedin_url','company_linkedin_link','companylinkedin','companylinkedinurl','linkedin_company','company_li']);
$companyWebsite = $fastPick(['company_website','website','domain','company_domain','company_website_url','company_url','company_website_link']);
$phone = $fastPick(['contact_phone','phone','phone_number','mobile','mobile_number','contact_number','prospect_phone','phone_no','mobile_no']);
$companySize = $fastPick(['company_size','companysize','employee_size','employee_range','employees','company_employee_size','company_size_range','employee_count']);
$industry = $fastPick(['industry','company_industry','sector','business_type','company_sector']);
$jobTitle = $fastPick(['job_title','jobtitle','title','designation','contact_job_title','role','position']);
$email = $fastPick(['email','email_address','emailaddress','work_email','prospect_email','contact_email','primary_email']);
$companyName = $fastPick(['company_name','company','companyname','organization','organisation','account_name','companyname','entity_name']);
$firstName = $fastPick(['first_name','firstname','first','given_name','f_name','fname']);
$lastName = $fastPick(['last_name','lastname','last','surname','family_name','l_name','lname']);

// Helper to ensure external links have a protocol
$ensureProtocol = function($url) {
    if ($url === '') return '';
    if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
        return 'https://' . $url;
    }
    return $url;
};

$linkedin = $ensureProtocol($linkedin);
$companyLinkedin = $ensureProtocol($companyLinkedin);
$companyWebsite = $ensureProtocol($companyWebsite);

if ($edit) {
    $postTo = $_GET['post_to'] ?? 'my-leads.php';
    $embed = !empty($_GET['embed']) || isAjaxRequest();
    $standalone = !$embed;
    $companySizeOptions = ['Myself Only','2-10','11-50','51-200','201-500','501-1,000','1,001-5,000','5,001-10,000','10,001+'];
    $countryOptions = ['United States','United Kingdom','Canada','Australia','Germany','France','India','Other'];
    $timelineOptions = ['0-3 Months','3-6 Months','6-9 Months','9-12 Months'];
    
    $form = null;
    $formName = '';
    $formId = 0;
    if ($submission && (int)($submission['form_id'] ?? 0) > 0) {
        $formId = (int)$submission['form_id'];
        $form = getFormById($formId);
        $formName = (string)($form['name'] ?? '');
    } else {
        $form = ($campaignId > 0) ? getFormForCampaign($campaignId) : null;
        $formId = (int)($form['form_id'] ?? 0);
        $formName = (string)($form['name'] ?? '');
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
        'company_linkedin','company_linkedin_link','company_linkedin_url','companylinkedin','companylinkedinurl',
        'industry',
        'lead_comment','comment','notes',
    ]), true);
    $hideFilled = hasRole(['form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);
    
    $leadVal = function($v): string {
        if ($v === null) return '';
        if (is_array($v)) return '';
        return trim((string)$v);
    };
    
    $pick = function(array $aliases, string $fallback = '') use ($fastPick): string {
        return $fastPick($aliases, $fallback);
    };
    
    $isFilled = function($v) use ($leadVal): bool {
        return $leadVal($v) !== '';
    };

    if ($standalone) {
        $pageTitle = 'Edit Lead';
        include __DIR__ . '/../../includes/layout/app_start.php';
    }
    ?>
    <div class="<?php echo $standalone ? 'container-fluid py-3' : ''; ?>">
        <div class="card border-0 <?php echo $standalone ? 'shadow-sm' : ''; ?>">
            <?php if ($standalone): ?>
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">Fill Lead Details</h5>
            </div>
            <?php endif; ?>
            <div class="card-body p-0">
                <form method="post" action="<?php echo htmlspecialchars($postTo); ?>" enctype="multipart/form-data" class="<?php echo $standalone ? 'p-4' : ''; ?>">

                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="edit_lead">
                    <input type="hidden" name="lead_id" value="<?php echo (int)$lead['id']; ?>">
                    <input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>">
                    <input type="hidden" name="form_id" value="<?php echo $formId; ?>">

                    <?php if ($hideFilled): ?>
                    <style>
                        .filled-field { display: none; }
                    </style>
                    <?php endif; ?>

                    <style>
                        .x-small { font-size: 0.75rem; }
                        .italic { font-style: italic; }
                        .card-body { padding: 0.75rem !important; }
                        .form-control-sm, .form-select-sm { font-size: 0.8rem; }
                        .form-label { margin-bottom: 0.2rem; }
                    </style>

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="h5 mb-0 text-primary"><?php echo htmlspecialchars($fullName ?: 'Lead'); ?></div>
                                    <div class="small text-muted">ID: <?php echo htmlspecialchars($lead['lead_id'] ?? (string)($lead['id'] ?? '')); ?></div>
                                </div>
                                <span class="badge bg-primary-subtle text-primary border rounded-pill px-3"><?php echo htmlspecialchars($lead['campaign_name'] ?? ''); ?></span>
                            </div>
                        </div>

                        <?php if ($hideFilled): ?>
                        <div class="col-12">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="toggleFilledFields">
                                <label class="form-check-label x-small text-muted" for="toggleFilledFields">Show already filled fields</label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($hideFilled): ?>
                        <div class="col-12">
                            <div class="card border-0 bg-light-subtle border-start border-4 border-info">
                                <div class="card-body py-2">
                                    <div class="small fw-bold mb-2"><i class="bi bi-envelope me-1"></i> Email Status</div>
                                    <div class="row g-2">
                                        <?php
                                            $emailStatuses = ['Pending','Sent','Delivered','Opened','Bounced','Unsubscribed','No Response'];
                                            $emailStatusVal = (string)($lead['email_status'] ?? '');
                                            if ($emailStatusVal === '') $emailStatusVal = 'Pending';
                                        ?>
                                        <div class="col-md-4">
                                            <label class="form-label x-small text-muted mb-1">Email Status</label>
                                            <select class="form-select form-select-sm" name="email_status">
                                                <?php foreach ($emailStatuses as $s): ?>
                                                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $emailStatusVal === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label x-small text-muted mb-1">Email Status Comment</label>
                                            <input class="form-control form-control-sm" name="email_status_comment" value="<?php echo htmlspecialchars((string)($lead['email_status_comment'] ?? '')); ?>" placeholder="e.g. bounced reason, opened note">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <div class="card border-0 bg-light-subtle">
                                <div class="card-body py-2">
                                    <div class="small fw-bold mb-2"><i class="bi bi-person-lines-fill me-1"></i> Lead Information</div>
                                    <div class="row g-2">
                                        <?php
                                            $first = $pick(['first_name','firstname','first','given_name'], (string)($lead['first_name'] ?? ''));
                                            $last = $pick(['last_name','lastname','last','surname','family_name'], (string)($lead['last_name'] ?? ''));
                                            $job = $pick(['job_title','jobtitle','title','designation'], (string)($lead['job_title'] ?? ''));
                                            $email = $pick(['email','email_address','emailaddress','work_email','prospect_email'], (string)($lead['email'] ?? ''));
                                            $li = $pick(['linkedin_link','linkedin_url','linkedin_profile','linkedinprofile','prospect_linkedin','prospect_linkedin_url'], (string)($lead['linkedin_link'] ?? ''));
                                            $phone = $pick(['contact_phone','phone','phone_number','mobile','mobile_number','contact_number','prospect_phone'], (string)($lead['contact_phone'] ?? ''));
                                            $company = $pick(['company_name','company','companyname','organization','organisation','account_name','companyname'], (string)($lead['company_name'] ?? ''));
                                            $companyLi = $pick(['company_linkedin','company_linkedin_url','company_linkedin_link','companylinkedin','companylinkedinurl','linkedin_company'], (string)($lead['company_linkedin'] ?? ''));
                                            $industry = $pick(['industry','company_industry','sector'], (string)($lead['industry'] ?? ''));
                                        ?>
                                        <div class="col-md-3 <?php echo ($hideFilled && $isFilled($first)) ? 'filled-field' : ''; ?>">
                                            <label class="form-label x-small text-muted mb-1">First Name</label>
                                            <input class="form-control form-control-sm" name="first_name" value="<?php echo htmlspecialchars($first); ?>">
                                        </div>
                                        <div class="col-md-3 <?php echo ($hideFilled && $isFilled($last)) ? 'filled-field' : ''; ?>">
                                            <label class="form-label x-small text-muted mb-1">Last Name</label>
                                            <input class="form-control form-control-sm" name="last_name" value="<?php echo htmlspecialchars($last); ?>">
                                        </div>
                                        <div class="col-md-3 <?php echo ($hideFilled && $isFilled($job)) ? 'filled-field' : ''; ?>">
                                            <label class="form-label x-small text-muted mb-1">Job Title</label>
                                            <input class="form-control form-control-sm" name="job_title" value="<?php echo htmlspecialchars($job); ?>">
                                        </div>
                                        <div class="col-md-3 <?php echo ($hideFilled && $isFilled($email)) ? 'filled-field' : ''; ?>">
                                            <label class="form-label x-small text-muted mb-1">Email</label>
                                            <input class="form-control form-control-sm" type="email" name="email" value="<?php echo htmlspecialchars($email); ?>">
                                        </div>
                                        <div class="col-md-4 <?php echo ($hideFilled && $isFilled($li)) ? 'filled-field' : ''; ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <label class="form-label x-small text-muted mb-1">LinkedIn Link</label>
                                                <?php if ($li !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($li); ?>" target="_blank" class="ms-2 text-primary" title="Visit LinkedIn">
                                    <i class="bi bi-linkedin fs-6"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <input class="form-control form-control-sm" name="linkedin_link" value="<?php echo htmlspecialchars($li); ?>">
                    </div>
                    <div class="col-md-4 <?php echo ($hideFilled && $isFilled($phone)) ? 'filled-field' : ''; ?>">
                        <label class="form-label x-small text-muted mb-1">Contact Phone</label>
                        <input class="form-control form-control-sm" name="contact_phone" value="<?php echo htmlspecialchars($phone); ?>">
                    </div>
                    <div class="col-md-4 <?php echo ($hideFilled && $isFilled($company)) ? 'filled-field' : ''; ?>">
                        <label class="form-label x-small text-muted mb-1">Company Name</label>
                        <input class="form-control form-control-sm" name="company_name" value="<?php echo htmlspecialchars($company); ?>">
                    </div>
                    <div class="col-md-4 <?php echo ($hideFilled && $isFilled($companyLi)) ? 'filled-field' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label x-small text-muted mb-1">Company LinkedIn</label>
                            <?php if ($companyLi !== ''): ?>
                                <a href="<?php echo htmlspecialchars($companyLi); ?>" target="_blank" class="ms-2 text-primary" title="Visit Company LinkedIn">
                                    <i class="bi bi-linkedin fs-6"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <input class="form-control form-control-sm" name="company_linkedin" value="<?php echo htmlspecialchars($companyLi); ?>">
                    </div>
                                        <div class="col-md-4 <?php echo ($hideFilled && $isFilled($industry)) ? 'filled-field' : ''; ?>">
                                            <label class="form-label x-small text-muted mb-1">Industry</label>
                                            <input class="form-control form-control-sm" name="industry" value="<?php echo htmlspecialchars($industry); ?>">
                                        </div>
                                        <div class="col-md-4 <?php echo ($hideFilled && $isFilled($comment)) ? 'filled-field' : ''; ?>">
                                            <label class="form-label x-small text-muted mb-1">Comment</label>
                                            <textarea class="form-control form-control-sm" name="lead_comment" rows="1"><?php echo htmlspecialchars($comment); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="card border-0 bg-light-subtle">
                                <div class="card-body py-2">
                                    <div class="row g-2 align-items-start">
                                        <div class="col-md-6">
                                            <div class="small fw-bold mb-1"><i class="bi bi-mic me-1"></i> Call Recordings</div>
                                            <?php 
                                                // Combine primary recording_path (if any and not already in $recordings) with $recordings
                                                $allRecs = $recordings;
                                                $primaryPath = trim((string)($lead['recording_path'] ?? ''));
                                                $primaryInList = false;
                                                foreach($allRecs as $r) {
                                                    if ($r['file_path'] === $primaryPath) { $primaryInList = true; break; }
                                                }
                                                if ($primaryPath !== '' && !$primaryInList) {
                                                    // This handles legacy paths that might not be in lead_files yet
                                                    array_unshift($allRecs, ['id' => 0, 'file_path' => $primaryPath, 'uploaded_at' => $lead['created_at']]);
                                                }
                                            ?>
                                            
                                            <?php if (!empty($allRecs)): ?>
                                                <div class="d-flex flex-column gap-2 mt-2">
                                                    <?php foreach ($allRecs as $idx => $r): ?>
                                                        <?php
                                                            $fid = (int)($r['id'] ?? 0);
                                                            $rp = (string)($r['file_path'] ?? '');
                                                            $recUrls = $buildRecordingUrls($fid, $rp);
                                                            $recUrl = (string)($recUrls['url'] ?? '');
                                                        ?>
                                                        <div class="p-2 border rounded bg-white">
                                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                                <span class="x-small fw-semibold text-muted">Recording #<?php echo $idx + 1; ?></span>
                                                                <?php if ($fid > 0): ?>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" name="delete_recording_id" value="<?php echo $fid; ?>" id="del_rec_<?php echo $fid; ?>">
                                                                        <label class="form-check-label x-small text-danger" for="del_rec_<?php echo $fid; ?>">Delete</label>
                                                                    </div>
                                                                <?php elseif ($primaryPath !== ''): ?>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" id="remove_recording" name="remove_recording" value="1">
                                                                        <label class="form-check-label x-small text-danger" for="remove_recording">Remove</label>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <audio controls class="w-100" style="height: 28px;">
                                                                <source src="<?php echo htmlspecialchars($recUrl); ?>">
                                                                Your browser does not support the audio element.
                                                            </audio>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="x-small text-muted italic">No recordings found</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label x-small text-muted mb-1">Add More Recording(s)</label>
                                            <input type="file" class="form-control form-control-sm" name="recording[]" accept="audio/*" multiple>
                                            <div class="form-text x-small text-muted mt-1">Select one or more files to add to this lead.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($form && !empty($form['schema']['fields']) && is_array($form['schema']['fields'])): ?>
                            <?php
                                $customFields = [];
                                $seenNorms = [];
                                foreach ($form['schema']['fields'] as $ff) {
                                    if (array_key_exists('visible', $ff) && empty($ff['visible'])) continue;
                                    $k = (string)($ff['key'] ?? '');
                                    if ($k === '') continue;
                                    $lbl = (string)($ff['label'] ?? $k);
                                    $kn = $norm($k);
                                    $ln = $norm($lbl);
                                    if (isset($skipNorms[$kn]) || isset($skipNorms[$ln])) continue;
                                    if ($kn !== '' && isset($seenNorms[$kn])) continue;
                                    if ($ln !== '' && isset($seenNorms[$ln])) continue;
                                    if ($kn !== '') $seenNorms[$kn] = true;
                                    if ($ln !== '') $seenNorms[$ln] = true;
                                    $customFields[] = $ff;
                                }
                            ?>
                            <?php if (!empty($customFields)): ?>
                            <div class="col-12">
                                <div class="card border-0 bg-light-subtle">
                                    <div class="card-body py-2">
                                        <div class="small fw-bold mb-2"><i class="bi bi-ui-checks me-1"></i> Campaign Form: <?php echo htmlspecialchars($formName); ?></div>
                                        <div class="row g-2">
                                            <?php foreach ($customFields as $f): ?>
                                                <?php
                                                    $key = (string)($f['key'] ?? '');
                                                    if ($key === '') continue;
                                                    $label = (string)($f['label'] ?? $key);
                                                    $type = (string)($f['type'] ?? 'text');
                                                    $required = !empty($f['required']);
                                                    $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
                                                    
                                                    // Robust lookup for custom field value with synonyms
                                                    $aliases = [$key, $label];
                                                    $nk = normalizeSubmissionKey($key);
                                                    $nl = normalizeSubmissionKey($label);
                                                    if ($nk !== '') $aliases[] = $nk;
                                                    if ($nl !== '') $aliases[] = $nl;
                                                    
                                                    $syns = [
                                                        'company_website' => ['website', 'domain', 'company_domain', 'company_url'],
                                                        'website' => ['company_website', 'domain', 'company_domain'],
                                                        'software_implementation_timeline' => ['implementation_timeline', 'timeline', 'when_is_your_company_planning_to_implement_new_software', 'cq1'],
                                                        'implementation_timeline' => ['software_implementation_timeline', 'timeline'],
                                                        'company_size' => ['employee_size', 'employees', 'headcount'],
                                                        'employee_size' => ['company_size', 'employees', 'headcount'],
                                                        'country' => ['location', 'country_name'],
                                                    ];
                                                    $search = $aliases;
                                                    foreach ($aliases as $a) {
                                                        if (isset($syns[$a])) $search = array_merge($search, $syns[$a]);
                                                    }
                                                    $search = array_unique($search);

                                                    $val = null;
                                                    foreach ($search as $s) {
                                                        if (isset($formData[$s]) && $formData[$s] !== '' && $formData[$s] !== null) {
                                                            $val = $formData[$s]; break;
                                                        }
                                                        $ns = normalizeSubmissionKey($s);
                                                        if (isset($indexedData[$ns]) && $indexedData[$ns] !== '' && $indexedData[$ns] !== null) {
                                                            $val = $indexedData[$ns]; break;
                                                        }
                                                        if (isset($lead[$s]) && $lead[$s] !== '' && $lead[$s] !== null) {
                                                            $val = $lead[$s]; break;
                                                        }
                                                        if ($ns !== '' && isset($lead[$ns]) && $lead[$ns] !== '' && $lead[$ns] !== null) {
                                                            $val = $lead[$ns]; break;
                                                        }
                                                    }
                                                ?>
                                                <?php
                                                    $valStr = '';
                                                    if (is_array($val)) $valStr = implode(',', array_filter(array_map('strval', $val)));
                                                    else $valStr = trim((string)($val ?? ''));
                                                    $filledClass = ($hideFilled && $valStr !== '') ? 'filled-field' : '';
                                                    
                                                    // Determine column width based on type
                                                    $colWidth = 'col-md-4';
                                                    if ($type === 'textarea' || $type === 'checkbox' || $type === 'radio') $colWidth = 'col-md-6';
                                                ?>
                                                <div class="<?php echo $colWidth; ?> <?php echo $filledClass; ?>">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <label class="form-label x-small text-muted mb-0">
                                                            <?php echo htmlspecialchars($label); ?>
                                                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                                        </label>
                                                        <?php 
                                                            $isUrl = filter_var($valStr, FILTER_VALIDATE_URL);
                                                            $isLikelyUrl = preg_match('/website|linkedin|link|url/i', $key) || preg_match('/website|linkedin|link|url/i', $label);
                                                            if (($isUrl || $isLikelyUrl) && $valStr !== '' && !is_array($val)): 
                                                                $visitUrl = $valStr;
                                                                if (!preg_match('/^https?:\\/\\//i', $visitUrl)) $visitUrl = 'https://' . $visitUrl;
                                                                $iconClass = 'bi-box-arrow-up-right';
                                                                if (stripos($key, 'linkedin') !== false || stripos($label, 'linkedin') !== false) $iconClass = 'bi-linkedin';
                                                                elseif (stripos($key, 'website') !== false || stripos($label, 'website') !== false) $iconClass = 'bi-globe';
                                                        ?>
                                                            <a href="<?php echo htmlspecialchars($visitUrl); ?>" target="_blank" class="text-primary" title="Visit Link">
                                                                <i class="bi <?php echo $iconClass; ?> fs-6"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($type === 'textarea'): ?>
                                                        <textarea class="form-control form-control-sm" name="cf[<?php echo htmlspecialchars($key); ?>]" rows="1" <?php echo $required ? 'required' : ''; ?>><?php echo htmlspecialchars((string)($val ?? '')); ?></textarea>
                                                    <?php elseif ($type === 'select'): ?>
                                                        <select class="form-select form-select-sm" name="cf[<?php echo htmlspecialchars($key); ?>]" <?php echo $required ? 'required' : ''; ?>>
                                                            <option value="">Select</option>
                                                            <?php foreach ($opts as $o): ?>
                                                                <?php 
                                                                    $oStr = trim((string)$o); 
                                                                    $vStr = trim((string)($val ?? ''));
                                                                    $selected = ($vStr === $oStr) ? 'selected' : '';
                                                                ?>
                                                                <option value="<?php echo htmlspecialchars($oStr); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($oStr); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php elseif ($type === 'radio'): ?>
                                                        <div class="d-flex flex-wrap gap-3 mt-0">
                                                            <?php foreach ($opts as $o): ?>
                                                                <?php $oStr = (string)$o; $id = 'cf_'.$key.'_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $oStr); ?>
                                                                <div class="form-check">
                                                                    <input class="form-check-input" type="radio" id="<?php echo htmlspecialchars($id); ?>" name="cf[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars($oStr); ?>" <?php echo ((string)($val ?? '') === $oStr) ? 'checked' : ''; ?> <?php echo $required ? 'required' : ''; ?>>
                                                                    <label class="form-check-label x-small" for="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($oStr); ?></label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php elseif ($type === 'checkbox'): ?>
                                                        <?php $arrVal = is_array($val) ? $val : (is_string($val) ? array_filter(array_map('trim', explode(',', $val))) : []); ?>
                                                        <div class="d-flex flex-wrap gap-3 mt-0">
                                                            <?php foreach ($opts as $o): ?>
                                                                <?php $oStr = (string)$o; $id = 'cf_'.$key.'_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $oStr); ?>
                                                                <div class="form-check">
                                                                    <input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars($id); ?>" name="cf[<?php echo htmlspecialchars($key); ?>][]" value="<?php echo htmlspecialchars($oStr); ?>" <?php echo in_array($oStr, $arrVal, true) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label x-small" for="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($oStr); ?></label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php elseif ($type === 'file_upload'): ?>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <?php if (is_string($val) && $val !== ''): ?>
                                                                <a href="<?php echo htmlspecialchars($val); ?>" target="_blank" class="btn btn-xs btn-outline-info p-1"><i class="bi bi-file-earmark-text"></i></a>
                                                                <input type="hidden" name="existing_cf[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                                            <?php endif; ?>
                                                            <input type="file" class="form-control form-control-sm" name="cffile[<?php echo htmlspecialchars($key); ?>]" <?php echo $required ? 'required' : ''; ?>>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php
                                                            $inputType = $type;
                                                            if (!in_array($inputType, ['text','email','tel','url','number','date'], true)) $inputType = 'text';
                                                        ?>
                                                        <input class="form-control form-control-sm" type="<?php echo htmlspecialchars($inputType); ?>" name="cf[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars((string)($val ?? '')); ?>" <?php echo $required ? 'required' : ''; ?>>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-3 border-top pt-3 <?php echo $standalone ? '' : 'px-4 pb-4'; ?>">
                        <a href="<?php echo htmlspecialchars($postTo); ?>" class="btn btn-light btn-sm px-4" <?php echo $standalone ? '' : 'data-bs-dismiss="modal"'; ?>>Cancel</a>
                        <button type="submit" class="btn btn-primary btn-sm px-5">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($hideFilled): ?>
    <script>
        (function(){
            const t = document.getElementById('toggleFilledFields');
            if (!t) return;
            t.addEventListener('change', () => {
                document.querySelectorAll('.filled-field').forEach(el => {
                    el.style.display = t.checked ? '' : 'none';
                });
            });
        })();
    </script>
    <?php endif; ?>
    <?php
    if ($standalone) {
        include __DIR__ . '/../../includes/layout/app_end.php';
    }
    exit;
}

if ($format === 'html') {
    $activity = [];
    $stmt = $conn->prepare("
        SELECT la.*, u.full_name AS user_name, u.role AS user_role
        FROM lead_activity la
        LEFT JOIN users u ON la.actor_id = u.id
        WHERE la.lead_id = ?
        ORDER BY la.created_at DESC
        LIMIT 50
    ");
    if ($stmt) {
        $stmt->bind_param('i', $leadId);
        $stmt->execute();
        $activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    $embed = !empty($_GET['embed']) || isAjaxRequest();
    $standalone = !$embed;
    if ($standalone) {
        $pageTitle = 'Lead Details';
        include __DIR__ . '/../../includes/layout/app_start.php';
    }
}
?>
<?php if ($format === 'html' && $standalone): ?>
<style>
    .btn-linkedin {
        background-color: #0077b5 !important;
        border-color: #0077b5 !important;
        color: #fff !important;
    }
    .btn-linkedin:hover {
        background-color: #005582 !important;
        border-color: #005582 !important;
    }
</style>
<div class="container-fluid py-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
<?php endif; ?>
<div class="<?php echo ($format === 'html' && !$standalone) ? 'p-3' : 'p-4'; ?>">
    <div class="d-flex justify-content-between align-items-start <?php echo $standalone ? 'mb-4' : 'mb-2'; ?>">
        <div>
            <div class="h4 mb-1"><?php echo htmlspecialchars($fullName ?: 'Lead'); ?></div>
            <div class="text-muted">
                Lead ID: <?php echo htmlspecialchars($lead['lead_id'] ?? (string)($lead['id'] ?? '')); ?>
                <span class="mx-2">•</span>
                Created: <?php echo htmlspecialchars($createdAt); ?>
            </div>
        </div>
        <div class="text-end">
            <div class="d-flex flex-wrap gap-2 justify-content-end mb-2">
                <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo htmlspecialchars((string)($lead['campaign_name'] ?? '')); ?></span>
                <span class="badge <?php echo $formClass; ?> border rounded-pill px-3 py-2">Submitted: <?php echo htmlspecialchars(($formDone === 'Yes') ? 'Submitted' : 'Not Submitted'); ?></span>
                <span class="badge <?php echo $qaClass; ?> border rounded-pill px-3 py-2">QA: <?php echo htmlspecialchars($qaStatus ?: 'Pending'); ?></span>
            </div>
            <?php if (!empty($lead['agent_name'])): ?>
                <div class="small text-muted">Agent: <span class="fw-semibold text-dark"><?php echo htmlspecialchars((string)$lead['agent_name']); ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row <?php echo $standalone ? 'g-4' : 'g-3'; ?>">
        <div class="col-lg-6">
            <div class="card border-0 bg-light h-100">
                <div class="card-header bg-transparent border-0 fw-bold <?php echo $standalone ? 'pt-3' : 'pt-2 small'; ?>"><i class="bi bi-person-badge me-1"></i> Contact Information</div>
                <div class="card-body <?php echo $standalone ? '' : 'pt-0 pb-2'; ?>">
                    <div class="row <?php echo $standalone ? 'g-3' : 'g-3'; ?>">
                        <div class="col-12">
                            <div class="text-muted x-small mb-0">Job Title</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars((string)($lead['job_title'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted x-small mb-0">Email</div>
                            <div class="fw-semibold text-dark small text-break"><?php echo htmlspecialchars((string)($lead['email'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted x-small mb-0">Phone</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars($phone !== '' ? $phone : '—'); ?></div>
                        </div>
                        <div class="col-12 <?php echo $standalone ? 'mt-3' : 'mt-1'; ?>">
                            <?php if ($linkedin !== ''): ?>
                                <a class="text-linkedin me-2" href="<?php echo htmlspecialchars((string)$linkedin); ?>" target="_blank" rel="noopener noreferrer" title="LinkedIn">
                                    <i class="bi bi-linkedin fs-5" style="color: #0077b5;"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 bg-light h-100">
                <div class="card-header bg-transparent border-0 fw-bold <?php echo $standalone ? 'pt-3' : 'pt-2 small'; ?>"><i class="bi bi-building me-1"></i> Company Details</div>
                <div class="card-body <?php echo $standalone ? '' : 'pt-0 pb-2'; ?>">
                    <div class="row <?php echo $standalone ? 'g-3' : 'g-3'; ?>">
                        <div class="col-12">
                            <div class="text-muted x-small mb-0">Company Name</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars((string)($lead['company_name'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted x-small mb-0">Size</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars($companySize !== '' ? $companySize : '—'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted x-small mb-0">Country</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars((string)($lead['country'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted x-small mb-0">Industry</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars((string)($lead['industry'] ?? '—')); ?></div>
                        </div>
                        <div class="col-12 <?php echo $standalone ? 'mt-2' : 'mt-1'; ?>">
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($companyLinkedin !== ''): ?>
                                    <a class="text-linkedin" href="<?php echo htmlspecialchars((string)$companyLinkedin); ?>" target="_blank" rel="noopener noreferrer" title="Company LinkedIn">
                                        <i class="bi bi-linkedin fs-5" style="color: #0077b5;"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($companyWebsite !== ''): ?>
                                    <a class="text-info" href="<?php echo htmlspecialchars((string)$companyWebsite); ?>" target="_blank" rel="noopener noreferrer" title="Company Website">
                                        <i class="bi bi-browser-safari fs-5"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 bg-light h-100">
                <div class="card-header bg-transparent border-0 fw-bold <?php echo $standalone ? 'pt-3' : 'pt-2 small'; ?>"><i class="bi bi-shield-check me-1"></i> QA & Status Tracking</div>
                <div class="card-body <?php echo $standalone ? '' : 'pt-0 pb-2'; ?>">
                    <div class="row <?php echo $standalone ? 'g-3' : 'g-3'; ?>">
                        <div class="col-md-6">
                            <div class="text-muted x-small mb-0">Submitted At</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars((string)$formFilledAt); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted x-small mb-0">QA Updated</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars((string)$qaUpdatedAt); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted x-small mb-0">QA Reviewed By</div>
                            <div class="fw-semibold text-dark small"><?php echo htmlspecialchars((string)($lead['reviewer_name'] ?? '—')); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted x-small mb-0">QA Internal Comment</div>
                            <div class="p-1 bg-white rounded border x-small text-dark" style="min-height: 40px;">
                                <?php echo nl2br(htmlspecialchars((string)(($lead['qa_comment'] ?? '') !== '' ? $lead['qa_comment'] : 'No comments.'))); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 bg-light h-100">
                <div class="card-header bg-transparent border-0 fw-bold <?php echo $standalone ? 'pt-3' : 'pt-2 small'; ?>"><i class="bi bi-sticky me-1"></i> Notes & Media</div>
                <div class="card-body <?php echo $standalone ? '' : 'pt-0 pb-2'; ?>">
                    <div class="<?php echo $standalone ? 'mb-4' : 'mb-2'; ?>">
                        <div class="text-muted x-small mb-1">Agent Comment</div>
                        <div class="p-1 bg-white rounded border x-small text-dark" style="min-height: 40px;">
                            <?php echo nl2br(htmlspecialchars((string)($comment !== '' ? $comment : 'No notes.'))); ?>
                        </div>
                    </div>
                    <?php if (!$embed): // Only show recordings in standalone view to speed up QA modal ?>
                    <div>
                        <div class="text-muted x-small mb-1">Call Recording(s)</div>
                        <?php 
                            // Combine primary and lead_files for view mode
                            $allRecsView = $recordings;
                            $primaryPathView = trim((string)($lead['recording_path'] ?? ''));
                            $primaryInListView = false;
                            foreach($allRecsView as $r) {
                                if ($r['file_path'] === $primaryPathView) { $primaryInListView = true; break; }
                            }
                            if ($primaryPathView !== '' && !$primaryInListView) {
                                array_unshift($allRecsView, ['id' => 0, 'file_path' => $primaryPathView]);
                            }
                        ?>
                        <?php if (!empty($allRecsView)): ?>
                            <div class="d-flex flex-column gap-1">
                                <?php foreach ($allRecsView as $idx => $r): ?>
                                    <?php 
                                        $rp = (string)($r['file_path'] ?? '');
                                        $fid = (int)($r['id'] ?? 0);
                                        $recUrls = $buildRecordingUrls($fid, $rp);
                                        $recUrl = (string)($recUrls['url'] ?? '');
                                        $recDl = (string)($recUrls['dl'] ?? $recUrl);
                                    ?>
                                    <div class="bg-white border rounded p-1 mb-1">
                                        <div class="d-flex justify-content-between x-small text-muted mb-1 px-1">
                                            <span>#<?php echo $idx + 1; ?></span>
                                            <a href="<?php echo htmlspecialchars($recDl); ?>" download class="text-primary text-decoration-none">Download</a>
                                        </div>
                                        <audio controls class="w-100 rounded" style="height: 24px;" src="<?php echo htmlspecialchars($recUrl); ?>"></audio>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="x-small text-muted italic">No recording</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php 
        // Add custom form fields section for both standalone and embed views
        $form = null;
        $formName = '';
        $formId = 0;
        if ($submission && (int)($submission['form_id'] ?? 0) > 0) {
            $formId = (int)$submission['form_id'];
            $form = getFormById($formId);
            $formName = (string)($form['name'] ?? '');
        } else {
            $form = ($campaignId > 0) ? getFormForCampaign($campaignId) : null;
            $formId = (int)($form['form_id'] ?? 0);
            $formName = (string)($form['name'] ?? '');
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
            'company_linkedin','company_linkedin_link','company_linkedin_url','companylinkedin','companylinkedinurl',
            'industry',
            'lead_comment','comment','notes',
        ]), true);
        
        if ($form && !empty($form['schema']['fields']) && is_array($form['schema']['fields'])):
            $customFields = [];
            $seenNorms = [];
            foreach ($form['schema']['fields'] as $ff) {
                if (array_key_exists('visible', $ff) && empty($ff['visible'])) continue;
                $k = (string)($ff['key'] ?? '');
                if ($k === '') continue;
                $lbl = (string)($ff['label'] ?? $k);
                $kn = $norm($k);
                $ln = $norm($lbl);
                if (isset($skipNorms[$kn]) || isset($skipNorms[$ln])) continue;
                if ($kn !== '' && isset($seenNorms[$kn])) continue;
                if ($ln !== '' && isset($seenNorms[$ln])) continue;
                if ($kn !== '') $seenNorms[$kn] = true;
                if ($ln !== '') $seenNorms[$ln] = true;
                $customFields[] = $ff;
            }
        ?>
        <?php if (!empty($customFields)): ?>
        <div class="col-lg-12">
            <div class="card border-0 bg-light h-100">
                <div class="card-header bg-transparent border-0 fw-bold <?php echo $standalone ? 'pt-3' : 'pt-2 small'; ?>"><i class="bi bi-ui-checks me-1"></i> Campaign Form: <?php echo htmlspecialchars($formName); ?></div>
                <div class="card-body <?php echo $standalone ? '' : 'pt-0 pb-2'; ?>">
                    <div class="row <?php echo $standalone ? 'g-3' : 'g-2'; ?>">
                        <?php foreach ($customFields as $f): ?>
                            <?php
                                $key = (string)($f['key'] ?? '');
                                if ($key === '') continue;
                                $label = (string)($f['label'] ?? $key);
                                $type = (string)($f['type'] ?? 'text');
                                $required = !empty($f['required']);
                                $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
                                
                                // Robust lookup for custom field value with synonyms
                                $aliases = [$key, $label];
                                $nk = normalizeSubmissionKey($key);
                                $nl = normalizeSubmissionKey($label);
                                if ($nk !== '') $aliases[] = $nk;
                                if ($nl !== '') $aliases[] = $nl;
                                
                                $syns = [
                                    'company_website' => ['website', 'domain', 'company_domain', 'company_url'],
                                    'website' => ['company_website', 'domain', 'company_domain'],
                                    'software_implementation_timeline' => ['implementation_timeline', 'timeline', 'when_is_your_company_planning_to_implement_new_software', 'cq1'],
                                    'implementation_timeline' => ['software_implementation_timeline', 'timeline'],
                                    'company_size' => ['employee_size', 'employees', 'headcount'],
                                    'employee_size' => ['company_size', 'employees', 'headcount'],
                                    'country' => ['location', 'country_name'],
                                ];
                                $search = $aliases;
                                foreach ($aliases as $a) {
                                    if (isset($syns[$a])) $search = array_merge($search, $syns[$a]);
                                }
                                $search = array_unique($search);

                                $val = null;
                                foreach ($search as $s) {
                                    if (isset($formData[$s]) && $formData[$s] !== '' && $formData[$s] !== null) {
                                        $val = $formData[$s]; break;
                                    }
                                    $ns = normalizeSubmissionKey($s);
                                    if (isset($indexedData[$ns]) && $indexedData[$ns] !== '' && $indexedData[$ns] !== null) {
                                        $val = $indexedData[$ns]; break;
                                    }
                                    if (isset($lead[$s]) && $lead[$s] !== '' && $lead[$s] !== null) {
                                        $val = $lead[$s]; break;
                                    }
                                    if ($ns !== '' && isset($lead[$ns]) && $lead[$ns] !== '' && $lead[$ns] !== null) {
                                        $val = $lead[$ns]; break;
                                    }
                                }
                                
                                $valStr = '';
                                if (is_array($val)) $valStr = implode(', ', array_filter(array_map('strval', $val)));
                                else $valStr = trim((string)($val ?? ''));
                                
                                // Determine column width based on type
                                $colWidth = 'col-md-6';
                                if ($type === 'textarea' || $type === 'checkbox' || $type === 'radio') $colWidth = 'col-md-12';
                            ?>
                            <div class="<?php echo $colWidth; ?>">
                                <div class="text-muted x-small mb-0">
                                    <?php echo htmlspecialchars($label); ?>
                                    <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                </div>
                                <div class="fw-semibold text-dark small">
                                    <?php if ($valStr !== ''): ?>
                                        <?php echo nl2br(htmlspecialchars($valStr)); ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($standalone): ?>
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-header bg-white py-3 fw-bold"><i class="bi bi-clock-history me-1"></i> Activity History & Timeline</div>
                <div class="card-body p-0">
                    <?php if (empty($activity)): ?>
                        <div class="p-5 text-muted small text-center">
                            <i class="bi bi-inbox fs-2 mb-2 d-block"></i>
                            No activity recorded for this lead yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-muted">
                                        <th class="ps-4">Date & Time</th>
                                        <th>Action Type</th>
                                        <th>Activity Details</th>
                                        <th class="pe-4">Performed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activity as $a): ?>
                                        <?php 
                                            $action = (string)($a['action'] ?? '');
                                            $meta = !empty($a['meta_json']) ? json_decode((string)$a['meta_json'], true) : [];
                                            if (!is_array($meta)) $meta = [];

                                            $detailsParts = [];
                                            if ($action === 'qa_updated') {
                                                $prev = (string)($meta['qa_prev_status'] ?? '—');
                                                $cur = (string)($meta['qa_status'] ?? '—');
                                                $detailsParts[] = 'QA Status changed from ' . $prev . ' to ' . $cur;

                                                $qaNote = trim((string)($meta['qa_comment'] ?? ''));
                                                if ($qaNote !== '') $detailsParts[] = 'QA Note: ' . $qaNote;

                                                $clientNote = trim((string)($meta['qa_client_comment'] ?? ''));
                                                if ($clientNote !== '') $detailsParts[] = 'Client Note: ' . $clientNote;
                                            } elseif ($action === 'lead_updated') {
                                                $fields = $meta['fields'] ?? [];
                                                if (is_array($fields) && !empty($fields)) {
                                                    $detailsParts[] = 'Fields updated: ' . implode(', ', array_map('strval', $fields));
                                                }
                                            } elseif ($action === 'form_submission_saved') {
                                                $fid = (int)($meta['form_id'] ?? 0);
                                                $detailsParts[] = $fid > 0 ? ('Form submission saved (Form #' . $fid . ')') : 'Form submission saved';
                                            } elseif ($action === 'recording_replaced') {
                                                $detailsParts[] = 'Call recording file replaced';
                                            } elseif ($action === 'recording_removed') {
                                                $detailsParts[] = 'Call recording file removed';
                                            }

                                            $note = trim((string)($meta['note'] ?? ''));
                                            if ($note !== '') $detailsParts[] = 'Additional Note: ' . $note;

                                            $details = !empty($detailsParts) ? implode(' | ', $detailsParts) : '—';
                                        ?>
                                        <tr>
                                            <td class="ps-4 small text-muted"><?php echo date('M j, Y • H:i', strtotime((string)$a['created_at'])); ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark border small fw-normal">
                                                    <?php echo str_replace('_', ' ', ucfirst((string)$a['action'])); ?>
                                                </span>
                                            </td>
                                            <td class="small text-muted py-3"><?php echo nl2br(htmlspecialchars($details)); ?></td>
                                            <td class="pe-4">
                                                <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($a['user_name'] ?? 'System'); ?></div>
                                                <div class="x-small text-muted"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($a['user_role'] ?? '')))); ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
    </div>
</div>
<?php if ($format === 'html' && $standalone): ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
if ($format === 'html' && $standalone) {
    include __DIR__ . '/../../includes/layout/app_end.php';
}
?>
