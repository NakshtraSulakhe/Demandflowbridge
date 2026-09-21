<?php
/**
 * Agent Form
 * 
 * Form for agents to submit new leads
 */

// Include required files
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Enforce manifest-driven Lead Entry page access.
requirePermissionOrRole('leads.entry.page', []);

// Ensure CSRF token is available
ensureCsrfToken();

// Get current user
$user = getCurrentUser();
$canMyLeads = userHasPermission('leads.my');

// Process form submission
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid security token. Please reload the form and try again.';
    } else {
        // Validate required fields
    $requiredFields = ['campaign_id'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        $error = 'Please fill in all required fields: ' . implode(', ', $missingFields);
    } else {
        // Get form data
        $campaignId = (int)$_POST['campaign_id'];
        $campaign = getCampaignById($campaignId);
        $campaignName = $campaign['name'];
        $agentId = (int)$user['id']; // Use logged in user as agent
        $agentName = $user['full_name'];
        
        // Generate unique lead ID (alphanumeric)
        $leadId = 'LD' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 6));
        
        $cfPosted = (array)($_POST['cf'] ?? []);
        $cfIndex = [];
        foreach ($cfPosted as $k => $v) {
            $nk = normalizeFieldKey((string)$k);
            if ($nk === '') continue;
            if (is_array($v)) $cfIndex[$nk] = implode(', ', array_map('strval', $v));
            else $cfIndex[$nk] = trim((string)$v);
        }
        $pick = function(array $aliases) use ($cfIndex): ?string {
            foreach ($aliases as $a) {
                $na = normalizeFieldKey((string)$a);
                if ($na !== '' && array_key_exists($na, $cfIndex) && $cfIndex[$na] !== '') return $cfIndex[$na];
            }
            return null;
        };

        $firstName = (string)($pick(['first_name','firstname','first','given_name']) ?? '');
        $lastName = (string)($pick(['last_name','lastname','last','surname','family_name']) ?? '');
        $jobTitle = $pick(['job_title','jobtitle','title','designation','job_position','position','role','contact_title','contact_role']);
        $email = $pick(['email','email_address','work_email']);
        $linkedinLink = $pick(['linkedin_link','linkedin_profile','linkedin_url','profile_linkedin_url','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_url']);
        $contactPhone = $pick(['contact_phone','phone','phone_number','mobile','mobile_number','contact_number']);
        $industry = $pick(['industry']);
        $companyLinkedin = $pick(['company_linkedin','company_linkedin_url','companylinkedin','companylinkedinurl','company_linkedin_link']);
        $companyName = $pick(['company_name','company','organization','organisation','account_name']);
        $companySize = $pick(['company_size','employee_size','employees','headcount']);
        $country = $pick(['country','location_country','country_name']);
        $timelineAnswer = $pick([
            'software_implementation_timeline',
            'implementation_timeline',
            'decision_timeline',
            'timeline',
            'when_is_your_company_planning_to_implement_new_software',
            'when_is_your_company_planning_to_implement_this_solution',
            'when_is_your_company_planning_to_implement_new_software_solution',
        ]);
        $softwareImplementationTimeline = null;
        $leadComment = $pick(['lead_comment','comment','comments','notes','n']);
        $companyWebsite = $pick(['company_website','website','domain']);

        if ($jobTitle === null || trim((string)$jobTitle) === '') {
            foreach ($cfIndex as $k => $v) {
                if ($v === '') continue;
                if (str_contains($k, 'job') && (str_contains($k, 'title') || str_contains($k, 'designation') || str_contains($k, 'position'))) {
                    $jobTitle = $v;
                    break;
                }
            }
        }

        if (trim($firstName) === '' || trim($lastName) === '') {
            $error = 'First Name and Last Name are required.';
        }

        $formForCampaign = getFormForCampaign($campaignId);
        if (empty($jobTitle) && $formForCampaign) {
            $schemaFields = $formForCampaign['schema']['fields'] ?? [];
            $cfRaw = $_POST['cf'] ?? [];
            if (is_array($schemaFields) && is_array($cfRaw)) {
                foreach ($schemaFields as $f) {
                    if (!is_array($f)) continue;
                    $key = (string)($f['key'] ?? '');
                    if ($key === '') continue;
                    $label = strtolower(trim((string)($f['label'] ?? '')));
                    if ($label === '') continue;
                    if (str_contains($label, 'job title') || str_contains($label, 'designation') || str_contains($label, 'job position')) {
                        $v = $cfRaw[$key] ?? null;
                        if (is_scalar($v) && trim((string)$v) !== '') {
                            $jobTitle = trim((string)$v);
                            break;
                        }
                    }
                }
            }
        }
        $strictSelectOptions = $formForCampaign ? getSelectOptionsByFormSchema((array)($formForCampaign['schema'] ?? []), ['industry','employee_size','company_size','country','software_implementation_timeline']) : [];
        if (empty($error)) {
            if ($industry !== null && isset($strictSelectOptions['industry']) && !valueInAllowedOptions($industry, $strictSelectOptions['industry'])) {
                $error = 'Invalid Industry. Allowed: ' . implode(' | ', $strictSelectOptions['industry']);
            }
            $empOpts = $strictSelectOptions['employee_size'] ?? ($strictSelectOptions['company_size'] ?? null);
            if (empty($error) && $companySize !== null && is_array($empOpts) && !valueInAllowedOptions($companySize, $empOpts)) {
                $error = 'Invalid Employee Size. Allowed: ' . implode(' | ', $empOpts);
            }
            if (empty($error) && $country !== null && isset($strictSelectOptions['country']) && !valueInAllowedOptions($country, $strictSelectOptions['country'])) {
                $error = 'Invalid Country. Allowed: ' . implode(' | ', $strictSelectOptions['country']);
            }
        }

        if (empty($error) && $formForCampaign) {
            $schemaFields = $formForCampaign['schema']['fields'] ?? [];
            $cf = $_POST['cf'] ?? [];
            if (is_array($schemaFields) && is_array($cf)) {
                foreach ($schemaFields as $f) {
                    if (is_array($f) && array_key_exists('visible', $f) && empty($f['visible'])) continue;
                    if (!is_array($f)) continue;
                    $key = (string)($f['key'] ?? '');
                    if ($key === '') continue;
                    $type = strtolower(trim((string)($f['type'] ?? 'text')));
                    $required = !empty($f['required']);
                    $options = is_array($f['options'] ?? null) ? $f['options'] : [];

                    if ($type === 'file_upload') continue;
                    $has = array_key_exists($key, $cf);
                    $val = $has ? $cf[$key] : null;

                    if ($required) {
                        if ($type === 'checkbox') {
                            if (!is_array($val) || empty(array_filter(array_map('trim', array_map('strval', $val)), fn($v) => $v !== ''))) {
                                $error = 'Missing required field: ' . ($f['label'] ?? $key);
                                break;
                            }
                        } else {
                            if (!is_scalar($val) || trim((string)$val) === '') {
                                $error = 'Missing required field: ' . ($f['label'] ?? $key);
                                break;
                            }
                        }
                    }

                    if (!empty($options) && in_array($type, ['select','radio'], true) && is_scalar($val) && trim((string)$val) !== '') {
                        if (!valueInAllowedOptions((string)$val, $options)) {
                            $error = 'Invalid value for ' . ($f['label'] ?? $key);
                            break;
                        }
                    }
                    if (!empty($options) && $type === 'checkbox' && is_array($val) && !empty($val)) {
                        foreach ($val as $vv) {
                            if (!valueInAllowedOptions((string)$vv, $options)) {
                                $error = 'Invalid value for ' . ($f['label'] ?? $key);
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        // Handle recording upload
        $recordingPaths = [];
        if (isset($_FILES['recording']) && !empty($_FILES['recording']['name'])) {
            $files = $_FILES['recording'];
            if (is_array($files['name'])) {
                foreach ($files['name'] as $i => $name) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $f = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i]
                        ];
                        $uploaded = uploadRecording($f);
                        if ($uploaded) $recordingPaths[] = $uploaded;
                    }
                }
            } else if ($files['error'] === UPLOAD_ERR_OK) {
                $uploaded = uploadRecording($files);
                if ($uploaded) $recordingPaths[] = $uploaded;
            }
            
            if (empty($recordingPaths) && !empty($files['name'][0])) {
                $error = 'Error uploading recording(s). Please check file size and type.';
            }
        }
        $recordingPath = !empty($recordingPaths) ? $recordingPaths[0] : null;
        
        $campaignForm = $formForCampaign ?: getFormForCampaign($campaignId);
        $formId = (int)($campaignForm['form_id'] ?? 0);
        if (!$campaignForm || $formId <= 0) {
            $error = 'Form is not assigned to this campaign. Assign a form before submitting leads.';
        }

        if (empty($error)) {
            $dupRules = dfGetDuplicateRulesFromSchema((array)($campaignForm['schema'] ?? []));
            if (!empty($dupRules)) {
                $dupInput = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => (string)($email ?? ''),
                    'company_name' => (string)($companyName ?? ''),
                    'contact_phone' => (string)($contactPhone ?? ''),
                    'phone' => (string)($contactPhone ?? ''),
                    'job_title' => (string)($jobTitle ?? ''),
                    'industry' => (string)($industry ?? ''),
                    'company_linkedin_link' => (string)($companyLinkedin ?? ''),
                    'prospect_linkedin_link' => (string)($linkedinLink ?? ''),
                    'employee_size' => (string)($companySize ?? ''),
                    'country' => (string)($country ?? ''),
                    'software_implementation_timeline' => (string)($softwareImplementationTimeline ?? ''),
                ];
                $cf0 = $_POST['cf'] ?? [];
                if (is_array($cf0)) $dupInput = array_merge($dupInput, $cf0);
                $baseCounts = dfDuplicatePreloadCountsForCampaign($campaignId, $dupRules);
                $dupRes = dfDuplicateValidateInput($campaignId, $dupRules, $dupInput, $baseCounts, []);
                if (empty($dupRes['ok'])) {
                    $error = (string)($dupRes['message'] ?? 'Duplicate rule failed.');
                }
            }
        }

        $companyDomain = extractDomain((string)$email);
        if ($companyDomain === '') {
            $cf0 = $_POST['cf'] ?? [];
            if (is_array($cf0)) {
                foreach (['company_website', 'website', 'domain'] as $k) {
                    $vv = trim((string)($cf0[$k] ?? ''));
                    if ($vv !== '') { $companyDomain = extractDomain($vv); break; }
                }
            }
        }
        if ($companyDomain !== '') {
            $hit = findClientDomainSuppressionLead($campaignId, $companyDomain, 90);
            if ($hit) {
                $when = (string)($hit['qa_updated_at'] ?? $hit['created_at'] ?? '');
                $whenText = $when !== '' ? date('Y-m-d', strtotime($when)) : '—';
                $error = 'This domain is blocked for this client for 90 days after delivery. Existing delivered lead: ' . (string)($hit['lead_id'] ?? ('#' . (string)($hit['id'] ?? ''))) . ' (Delivered: ' . $whenText . ')';
            }
        }

        // If no errors, insert into database
        if (empty($error)) {
            $conn = getDbConnection();
            ensureLeadsTrackingColumns();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $clientId = getCampaignClientId($campaignId);
            $timestampSeed = function_exists('leadCanonicalNowSeed') ? leadCanonicalNowSeed('agent_create_utc') : [];
            $createdAtUtc = (string)($timestampSeed['created_at_utc'] ?? gmdate('Y-m-d H:i:s'));
            $updatedAtUtc = (string)($timestampSeed['updated_at_utc'] ?? $createdAtUtc);
            $createdAtSource = (string)($timestampSeed['created_at_source'] ?? 'agent_create_utc');
            $updatedAtSource = (string)($timestampSeed['updated_at_source'] ?? 'agent_create_utc');
            $timezoneMigrated = (int)($timestampSeed['timezone_migrated'] ?? 1);
            
            $stmt = $conn->prepare("
                INSERT INTO leads (
                    lead_id, campaign_id, campaign_name, client_id, agent_id, agent_name, 
                    first_name, last_name, job_title, email, company_domain, 
                    contact_phone, industry, company_name, 
                    company_size, country, lead_comment, recording_path, ip_address,
                    created_by, updated_by, created_at_utc, updated_at_utc, created_at_source, updated_at_source, timezone_migrated, vendor_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if (!$stmt) {
                $dbErr = (string)($conn->error ?? 'unknown');
                error_log('agent.php lead insert prepare failed: ' . $dbErr);
                $error = (($user['role'] ?? '') === 'admin')
                    ? ('Database error while saving lead: ' . $dbErr)
                    : 'Database error while saving lead. Please contact admin.';
            } else {
                $userVendorId = (int)($user['vendor_id'] ?? 0);
                $types = "sisiis" . str_repeat("s", 13) . "iissssii";
                $stmt->bind_param(
                    $types,
                    $leadId,
                    $campaignId,
                    $campaignName,
                    $clientId,
                    $agentId,
                    $agentName,
                    $firstName,
                    $lastName,
                    $jobTitle,
                    $email,
                    $companyDomain,
                    $contactPhone,
                    $industry,
                    $companyName,
                    $companySize,
                    $country,
                    $leadComment,
                    $recordingPath,
                    $ipAddress,
                    $agentId,
                    $agentId,
                    $createdAtUtc,
                    $updatedAtUtc,
                    $createdAtSource,
                    $updatedAtSource,
                    $timezoneMigrated,
                    $userVendorId
                );
            }
            
            if (!empty($error)) {
                // keep $error as-is
            } elseif (!$stmt) {
                $dbErr = (string)($conn->error ?? 'unknown');
                error_log('agent.php lead insert prepare failed: ' . $dbErr);
                $error = (($user['role'] ?? '') === 'admin')
                    ? ('Database error while saving lead: ' . $dbErr)
                    : 'Database error while saving lead. Please contact admin.';
            } elseif ($stmt->execute()) {
                $success = true;
                $leadDbId = (int)$conn->insert_id;
                $stmt->close();

                logLeadActivity($leadDbId, $agentId, 'lead_created', ['campaign_id' => $campaignId]);
                notifyLeadCreated($leadDbId, $campaignId, $agentId);
                
                // Save all recordings to lead_files
                if (!empty($recordingPaths)) {
                    foreach ($recordingPaths as $path) {
                        addLeadRecording($leadDbId, $path, $agentId);
                    }
                }

                $campaignCustom = [];
                if ($campaignForm) {
                    $cf = $_POST['cf'] ?? [];
                    $cfFiles = $_FILES['cffile'] ?? [];
                    $data = [];
                    $fields = $campaignForm['schema']['fields'] ?? [];
                    if (is_array($fields)) {
                        foreach ($fields as $f) {
                            if (is_array($f) && array_key_exists('visible', $f) && empty($f['visible'])) continue;
                            $key = $f['key'] ?? '';
                            if ($key === '') continue;
                            $type = $f['type'] ?? 'text';
                            if ($type === 'file_upload') {
                                if (isset($cfFiles['name'][$key]) && (string)$cfFiles['name'][$key] !== '') {
                                    $file = [
                                        'name' => $cfFiles['name'][$key] ?? '',
                                        'type' => $cfFiles['type'][$key] ?? '',
                                        'tmp_name' => $cfFiles['tmp_name'][$key] ?? '',
                                        'error' => $cfFiles['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                                        'size' => $cfFiles['size'][$key] ?? 0,
                                    ];
                                    $p = saveLeadFieldFile($leadDbId, (string)$key, $file);
                                    if ($p) {
                                        $data[$key] = $p;
                                        $campaignCustom[$key] = $p;
                                    }
                                }
                            } elseif (array_key_exists($key, $cf)) {
                                $data[$key] = $cf[$key];
                                $campaignCustom[$key] = $cf[$key];
                            }
                        }
                    }
                    if (!array_key_exists('linkedin_link', $data) && $linkedinLink !== null && trim((string)$linkedinLink) !== '') {
                        $data['linkedin_link'] = $linkedinLink;
                    }
                    if (!array_key_exists('company_linkedin', $data) && $companyLinkedin !== null && trim((string)$companyLinkedin) !== '') {
                        $data['company_linkedin'] = $companyLinkedin;
                    }
                    if (!array_key_exists('software_implementation_timeline', $data) && $timelineAnswer !== null && trim((string)$timelineAnswer) !== '') {
                        $data['software_implementation_timeline'] = $timelineAnswer;
                    }
                    if (!array_key_exists('company_website', $data) && $companyWebsite !== null && trim((string)$companyWebsite) !== '') {
                        $data['company_website'] = $companyWebsite;
                    }
                    if (!array_key_exists('job_title', $data) && $jobTitle !== null && trim((string)$jobTitle) !== '') {
                        $data['job_title'] = $jobTitle;
                    }
                    if (!empty($data)) {
                        saveFormSubmission((int)$campaignForm['form_id'], $campaignId, $leadDbId, $agentId, $data);
                        logLeadActivity($leadDbId, $agentId, 'form_submission_saved', ['form_id' => (int)$campaignForm['form_id']]);
                    }
                }

                // Sync to campaign-specific lead table
                syncLeadToCampaignTable($leadDbId);

                foreach ($campaignCustom as $k => $v) {
                    if ($companyDomain === '' && in_array((string)$k, ['company_website', 'website', 'domain'], true)) {
                        $companyDomain = extractDomain(trim((string)$v));
                    }
                }

                pushLeadToGoogleSheet($campaignName, [
                    'lead_id' => $leadId,
                    'campaign' => $campaignName,
                    'agent_name' => $agentName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'job_title' => $jobTitle,
                    'email' => $email,
                    'linkedin_link' => $linkedinLink,
                    'contact_phone' => $contactPhone,
                    'industry' => $industry,
                    'company_linkedin' => $companyLinkedin,
                    'company_name' => $companyName,
                    'company_size' => $companySize,
                    'country' => $country,
                    'software_implementation_timeline' => $timelineAnswer,
                    'lead_comment' => $leadComment,
                    'recording_path' => $recordingPath,
                    'ip_address' => $ipAddress,
                    'vendor_id' => $userVendorId,
                    'created_at' => date('c')
                ]);
            } else {
                $dbErr = (string)($stmt->error ?: ($conn->error ?? 'unknown'));
                $stmt->close();
                $error = (($user['role'] ?? '') === 'admin')
                    ? ('Error saving lead: ' . $dbErr)
                    : 'Error saving lead. Please contact admin.';
                error_log('agent.php lead insert execute failed: ' . $dbErr);
            }
        }
    }
}
}

// Get campaigns for dropdown
$campaigns = getOpsCampaignsForUser((int)($user['id'] ?? 0), (string)($user['role'] ?? ''));
$campaignTargetingMap = [];
foreach ($campaigns as $c) {
    $cid = (int)($c['id'] ?? 0);
    if ($cid <= 0) continue;
    $d = getCampaignDetailsById($cid);
    if (!$d) continue;
    $campaignTargetingMap[$cid] = [
        'campaign_name' => (string)($d['name'] ?? $c['name'] ?? ''),
        'campaign_code' => (string)($d['code'] ?? ''),
        'client_code' => (string)($d['client_code'] ?? ''),
        'targeted_country' => is_array($d['targeted_country'] ?? null) ? $d['targeted_country'] : [],
        'job_title' => (string)($d['job_title'] ?? ''),
        'departments' => is_array($d['departments'] ?? null) ? $d['departments'] : [],
        'seniority_levels' => is_array($d['seniority_levels'] ?? null) ? $d['seniority_levels'] : [],
        'industries' => is_array($d['industries'] ?? null) ? $d['industries'] : [],
        'employee_sizes' => is_array($d['employee_sizes'] ?? null) ? $d['employee_sizes'] : [],
        'revenue_sizes' => is_array($d['revenue_sizes'] ?? null) ? $d['revenue_sizes'] : [],
    ];
}
?>
<?php $pageTitle = 'Submit Lead'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

<div id="loading-overlay" class="leads-loading-overlay">
    <div class="leads-spinner-container">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-3 fw-medium text-secondary">Processing lead submission...</p>
    </div>
</div>

<div class="container-fluid px-0 leads-workspace">
    <div class="leads-page-header leads-hero mb-4">
        <div>
            <div class="leads-eyebrow mb-2"><i class="bi bi-person-plus"></i>Lead Entry</div>
            <h1 class="leads-page-title">Submit Lead</h1>
            <p class="leads-page-subtitle mb-0">Enter new lead information for your assigned campaigns, validate suppression before submission, and keep recordings linked in one place.</p>
        </div>
        <div class="leads-page-actions">
            <button type="button" class="btn btn-warning shadow-sm" data-bs-toggle="modal" data-bs-target="#dupCheckModal">
                <i class="bi bi-shield-check me-2"></i>Check Suppression
            </button>
            <?php if ($canMyLeads): ?><a href="my" class="btn btn-outline-secondary shadow-sm">
                <i class="bi bi-list-ul me-2"></i>My Leads
            </a><?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-3 fs-4"></i>
            <div>
                <h6 class="alert-heading mb-1 fw-bold">Success!</h6>
                <span>Lead submitted successfully and synced to campaign data.</span>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-3 fs-4"></i>
            <div>
                <h6 class="alert-heading mb-1 fw-bold">Error</h6>
                <span><?php echo nl2br(htmlspecialchars($error)); ?></span>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="post" action="" enctype="multipart/form-data" id="leadForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        
        <div class="row">
            <!-- Left Column: Main Form -->
            <div class="col-lg-8">
                <!-- Step 1: Campaign Selection -->
                <div class="card border-0 shadow-sm leads-panel">
                    <div class="card-header">
                        <h5 class="h5"><i class="bi bi-flag me-2 text-primary"></i>Campaign Selection</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="campaign_id" class="form-label required-field">Select Campaign</label>
                                <select class="form-select form-select-sm" id="campaign_id" name="campaign_id" required>
                                    <option value="">Choose a campaign...</option>
                                    <?php foreach ($campaigns as $campaign): ?>
                                        <?php
                                            $cid = (int)($campaign['id'] ?? 0);
                                            $clientCode = '';
                                            $campaignCode = '';
                                            if ($cid > 0 && isset($campaignTargetingMap[$cid])) {
                                                $clientCode = trim((string)($campaignTargetingMap[$cid]['client_code'] ?? ''));
                                                $campaignCode = trim((string)($campaignTargetingMap[$cid]['campaign_code'] ?? ''));
                                            }
                                            $nm = (string)($campaign['name'] ?? '');
                                            $label = crmCampaignDisplayName($nm, $clientCode, $campaignCode);
                                        ?>
                                        <option value="<?php echo (int)$cid; ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text mt-2 text-muted small">Only active campaigns assigned to you are listed here.</div>
                                <?php if (empty($campaigns)): ?>
                                    <div class="alert alert-warning border-0 shadow-sm mt-3 mb-0">
                                        No campaigns are assigned to your account yet. Ask Operations to allocate a campaign to you (user or team allocation) and ensure the campaign status is Active/Live.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Dynamic Lead Form -->
                <div id="leadDetailsSection" style="display:none;">
                    <div class="card border-0 shadow-sm mb-4 leads-panel" id="campaignFormSection" style="display:none;">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="h5"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Lead Information</h5>
                            <span class="badge bg-light text-primary border" id="campaignFormName"></span>
                        </div>
                        <div class="card-body">
                            <div class="row g-4" id="campaignFormFields">
                                <!-- Dynamic fields injected here -->
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Attachments -->
                    <div class="card border-0 shadow-sm mb-4 leads-panel">
                        <div class="card-header">
                            <h5 class="h5"><i class="bi bi-mic me-2 text-primary"></i>Call Recording & Comments</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label for="recording" class="form-label">Call Recording File(s)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light"><i class="bi bi-cloud-arrow-up"></i></span>
                                        <input type="file" class="form-control" id="recording" name="recording[]" accept="audio/*" multiple>
                                    </div>
                                    <div class="form-text mt-1">Supported: MP3, WAV, M4A (Max 50MB). You can select multiple files.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Sidebar Actions -->
            <div class="col-lg-4">
                <div class="card text-white leads-spotlight">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <h5 class="h5 text-white"><i class="bi bi-send me-2"></i>Actions</h5>
                    </div>
                    <div class="card-body pt-3">
                        <div class="d-grid gap-3" id="submitActions" style="display:none;">
                            <button type="submit" class="btn btn-light fw-bold text-primary">
                                <i class="bi bi-check2-circle me-2"></i>Submit Lead
                            </button>
                            <button type="reset" class="btn btn-outline-light btn-sm">
                                <i class="bi bi-x-circle me-2"></i>Clear All Fields
                            </button>
                        </div>
                        <div id="noCampaignWarning" class="text-white-50 small text-center py-3">
                            <i class="bi bi-info-circle fs-4 d-block mb-2"></i>
                            Select a campaign to enable lead submission.
                        </div>
                        
                        <hr class="my-4 border-white-50">
                        
                        <div class="mb-3">
                            <div class="small text-white-50 mb-1">Submission Date</div>
                            <div class="fw-bold"><?php echo date('F d, Y'); ?></div>
                        </div>
                        <div class="mb-3">
                            <div class="small text-white-50 mb-1">Logged in Agent</div>
                            <div class="fw-bold"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        </div>
                        <div class="mb-0">
                            <div class="small text-white-50 mb-1">Agent Role</div>
                            <div class="fw-bold"><span class="badge bg-white text-primary rounded-pill"><?php echo strtoupper(str_replace('_', ' ', $user['role'])); ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- Submission Guidelines -->
                <div class="card mt-4 border-0 shadow-sm leads-panel leads-note-card leads-note-info">
                    <div class="card-body py-3">
                        <h6 class="fw-bold text-info mb-2"><i class="bi bi-lightbulb me-2"></i>Quick Tips</h6>
                        <ul class="small text-muted mb-0 ps-3">
                            <li>Always check for duplicates before submission.</li>
                            <li>Ensure email and LinkedIn URLs are valid.</li>
                            <li>Wait for the upload to finish after clicking Submit.</li>
                        </ul>
                    </div>
                </div>

                <div class="card mt-4 border-0 shadow-sm leads-panel leads-note-card" id="campaignTargetingCard" style="display:none;">
                    <div class="card-body py-3">
                        <h6 class="fw-bold text-primary mb-2"><i class="bi bi-bullseye me-2"></i>Campaign Targeting</h6>
                        <div id="campaignTargetingBody" class="small text-muted"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

    <!-- Duplicate Check Modal -->
    <div class="modal fade" id="dupCheckModal" tabindex="-1" aria-labelledby="dupCheckModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dupCheckModalLabel">Check Suppression</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="dupCheckForm" onsubmit="return false;">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Open Search (Search Email, Name, Company, Lead ID)</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="dup_search" placeholder="Type to search anything...">
                                <button type="button" id="dupSearchBtn" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="dup_all_data">
                                <label class="form-check-label" for="dup_all_data">Search across all lead data (ignore current client suppression)</label>
                            </div>
                        </div>
                        <hr>
                        <h6 class="text-muted mb-3 small">Or search by specific fields:</h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control form-control-sm" id="dup_first_name" placeholder="Enter first name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control form-control-sm" id="dup_last_name" placeholder="Enter last name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control form-control-sm" id="dup_email" placeholder="Enter email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Company Name</label>
                                <input type="text" class="form-control form-control-sm" id="dup_company_name" placeholder="Enter company name">
                            </div>
                        </div>
                        <input type="hidden" id="dup_csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mt-3 d-flex justify-content-end">
                            <button type="button" id="dupCheckBtn" class="btn btn-primary">
                                <i class="bi bi-check2-circle"></i> Check
                            </button>
                        </div>
                    </form>

                    <div id="dupResults" class="mt-3" style="display:none;">
                        <div id="dupStatus" class="alert" style="display:none;"></div>
                        <h6 class="mb-2">Matches</h6>
                        <div class="table-responsive leads-table-wrap">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Company</th>
                                        <th>QA Status</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="dupResultsBody"></tbody>
                            </table>
                        </div>
                        <div id="dupNoResults" class="text-muted">No matching leads found.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        const campaignTargetingMap = <?php echo json_encode($campaignTargetingMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        // Set today's date by default
        document.addEventListener('DOMContentLoaded', function() {
            // Show loading overlay when form is submitted
            document.getElementById('leadForm').addEventListener('submit', function(e) {
                document.getElementById('loading-overlay').style.display = 'flex';
            });

            function renderCampaignTargeting(campaignId) {
                const card = document.getElementById('campaignTargetingCard');
                const body = document.getElementById('campaignTargetingBody');
                if (!card || !body) return;
                const id = parseInt(String(campaignId || ''), 10);
                const spec = id && campaignTargetingMap ? campaignTargetingMap[id] : null;
                if (!spec) {
                    card.style.display = 'none';
                    body.innerHTML = '';
                    return;
                }

                const esc = (s) => escapeHtml(String(s ?? ''));
                const asList = (v) => Array.isArray(v) ? v.map(x => String(x).trim()).filter(x => x !== '') : [];
                const chips = (arr) => {
                    const xs = asList(arr);
                    if (!xs.length) return '<span class="fw-semibold text-muted">—</span>';
                    return xs.map(x => '<span class="badge bg-light text-dark border me-1 mb-1">' + esc(x) + '</span>').join('');
                };
                const val = (s) => {
                    const t = String(s ?? '').trim();
                    return t !== '' ? '<span class="fw-semibold text-dark">' + esc(t) + '</span>' : '<span class="fw-semibold text-muted">—</span>';
                };

                const title = (String(spec.campaign_name || '').trim() || 'Selected Campaign') + ((spec.client_code || spec.campaign_code) ? (' · ' + String(spec.client_code || spec.campaign_code)) : '');
                body.innerHTML = `
                    <div class="fw-semibold text-dark mb-2">${esc(title)}</div>
                    <div class="mb-2"><span class="text-muted">Geography:</span><div class="mt-1">${chips(spec.targeted_country)}</div></div>
                    <div class="mb-2"><span class="text-muted">Job Title:</span><div class="mt-1">${val(spec.job_title)}</div></div>
                    <div class="mb-2"><span class="text-muted">Functions:</span><div class="mt-1">${chips(spec.departments)}</div></div>
                    <div class="mb-2"><span class="text-muted">Levels:</span><div class="mt-1">${chips(spec.seniority_levels)}</div></div>
                    <div class="mb-2"><span class="text-muted">Industries:</span><div class="mt-1">${chips(spec.industries)}</div></div>
                    <div class="mb-2"><span class="text-muted">Employee Size:</span><div class="mt-1">${chips(spec.employee_sizes)}</div></div>
                    <div class="mb-0"><span class="text-muted">Revenue:</span><div class="mt-1">${chips(spec.revenue_sizes)}</div></div>
                `;
                card.style.display = 'block';
            }

            async function loadCampaignForm(campaignId) {
                const section = document.getElementById('campaignFormSection');
                const fieldsWrap = document.getElementById('campaignFormFields');
                const nameEl = document.getElementById('campaignFormName');
                const details = document.getElementById('leadDetailsSection');
                const actions = document.getElementById('submitActions');
                const warning = document.getElementById('noCampaignWarning');

                fieldsWrap.innerHTML = '';
                nameEl.textContent = '';
                section.style.display = 'none';

                if (!campaignId) {
                    if (details) details.style.display = 'none';
                    if (actions) actions.style.display = 'none';
                    if (warning) warning.style.display = 'block';
                    renderCampaignTargeting(null);
                    return;
                }

                if (details) details.style.display = 'block';
                if (actions) actions.style.display = 'grid';
                if (warning) warning.style.display = 'none';
                renderCampaignTargeting(campaignId);

                try {
                    const res = await fetch('../forms/get_campaign_form?campaign_id=' + encodeURIComponent(campaignId), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin'
                    });
                    const data = await res.json().catch(() => null);
                    if (!res.ok || !data || !data.ok || !data.form) {
                        console.warn('No form data or not OK');
                        const msg = (data && (data.message || data.error)) ? String(data.message || data.error) : 'No form assigned to this campaign.';
                        fieldsWrap.innerHTML = '<div class="col-12 text-danger small">' + escapeHtml(msg) + '</div>';
                        section.style.display = 'block';
                        return;
                    }

                    nameEl.textContent = data.form.name || '';
                    const fields = (data.form.schema && data.form.schema.fields) ? data.form.schema.fields : [];
                    const norm = (s) => String(s || '')
                        .trim()
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '_')
                        .replace(/_+/g, '_')
                        .replace(/^_+|_+$/g, '');
                    
                    if (!Array.isArray(fields) || fields.length === 0) {
                        fieldsWrap.innerHTML = '<div class="col-12 text-muted small">This form has no fields.</div>';
                        section.style.display = 'block';
                        return;
                    }

                    fields.forEach((f) => {
                        if (f && Object.prototype.hasOwnProperty.call(f, 'visible') && !f.visible) return;
                        const key = f.key || '';
                        if (!key) return;
                        const type = (f.type || 'text').toLowerCase();
                        const label = f.label || key;
                        const required = !!f.required;
                        const options = Array.isArray(f.options) ? f.options : [];

                        const col = document.createElement('div');
                        col.className = f.width === 'full' ? 'col-12' : 'col-md-6';

                        if (type === 'textarea') {
                            col.innerHTML = `
                                <label class="form-label small text-muted">${label}${required ? ' *' : ''}</label>
                                <textarea class="form-control form-control-sm" name="cf[${key}]" rows="3" ${required ? 'required' : ''}></textarea>
                            `;
                        } else if (type === 'select') {
                            const opts = ['<option value="">Select</option>'].concat(options.map(o => `<option value="${escapeHtml(String(o))}">${escapeHtml(String(o))}</option>`)).join('');
                            col.innerHTML = `
                                <label class="form-label small text-muted">${label}${required ? ' *' : ''}</label>
                                <select class="form-select form-select-sm" name="cf[${key}]" ${required ? 'required' : ''}>${opts}</select>
                            `;
                        } else if (type === 'radio') {
                            const radios = options.map((o, idx) => {
                                const id = `cf_${key}_${idx}`;
                                return `
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="cf[${key}]" id="${id}" value="${escapeHtml(String(o))}" ${required && idx === 0 ? 'required' : ''}>
                                        <label class="form-check-label" for="${id}">${escapeHtml(String(o))}</label>
                                    </div>
                                `;
                            }).join('');
                            col.innerHTML = `
                                <label class="form-label small text-muted d-block">${label}${required ? ' *' : ''}</label>
                                ${radios}
                            `;
                        } else if (type === 'checkbox' && options.length > 0) {
                            const checks = options.map((o, idx) => {
                                const id = `cf_${key}_${idx}`;
                                return `
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="cf[${key}][]" id="${id}" value="${escapeHtml(String(o))}">
                                        <label class="form-check-label" for="${id}">${escapeHtml(String(o))}</label>
                                    </div>
                                `;
                            }).join('');
                            col.innerHTML = `
                                <label class="form-label small text-muted d-block">${label}${required ? ' *' : ''}</label>
                                ${checks}
                            `;
                        } else if (type === 'checkbox') {
                            const id = `cf_${key}`;
                            col.innerHTML = `
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="cf[${key}]" id="${id}" value="1">
                                    <label class="form-check-label" for="${id}">${label}</label>
                                </div>
                            `;
                        } else if (type === 'file_upload') {
                            col.innerHTML = `
                                <label class="form-label small text-muted">${label}${required ? ' *' : ''}</label>
                                <input class="form-control form-control-sm" type="file" name="cffile[${key}]" ${required ? 'required' : ''}>
                            `;
                        } else {
                            const inputType = ['email','tel','url','number','date'].includes(type) ? type : 'text';
                            col.innerHTML = `
                                <label class="form-label small text-muted">${label}${required ? ' *' : ''}</label>
                                <input class="form-control form-control-sm" type="${inputType}" name="cf[${key}]" ${required ? 'required' : ''}>
                            `;
                        }

                        fieldsWrap.appendChild(col);
                    });

                    section.style.display = 'block';
                } catch (e) {
                    console.error(e);
                    fieldsWrap.innerHTML = '<div class="col-12 text-danger small">Unable to load form fields.</div>';
                    section.style.display = 'block';
                }
            }

            function escapeHtml(s) {
                const d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            document.getElementById('campaign_id')?.addEventListener('change', function() {
                loadCampaignForm(this.value);
            });

            // Trigger once on load if already selected
            const initialCid = document.getElementById('campaign_id')?.value;
            if (initialCid) {
                loadCampaignForm(initialCid);
            }

            function getCfValue(key) {
                const name = `cf[${key}]`;
                const radio = document.querySelector(`input[type="radio"][name="${CSS.escape(name)}"]:checked`);
                if (radio) return radio.value;
                const checks = Array.from(document.querySelectorAll(`input[type="checkbox"][name="${CSS.escape(name)}[]"]:checked`)).map(x => x.value);
                if (checks.length) return checks.join(', ');
                const el = document.querySelector(`[name="${CSS.escape(name)}"]`);
                if (!el) return '';
                if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') return el.value || '';
                return '';
            }

            // Prefill duplicate check fields from main form when modal opens
            const dupModal = document.getElementById('dupCheckModal');
            dupModal.addEventListener('show.bs.modal', () => {
                const first = getCfValue('first_name') || getCfValue('firstname') || getCfValue('first');
                const last = getCfValue('last_name') || getCfValue('lastname') || getCfValue('last') || getCfValue('surname');
                const email = getCfValue('email') || getCfValue('email_address') || getCfValue('work_email');
                const company = getCfValue('company_name') || getCfValue('company') || getCfValue('organization');
                document.getElementById('dup_first_name').value = first || '';
                document.getElementById('dup_last_name').value = last || '';
                document.getElementById('dup_email').value = email || '';
                document.getElementById('dup_company_name').value = company || '';
                document.getElementById('dup_search').value = email || company || first || '';
                // Reset results view
                document.getElementById('dupResults').style.display = 'none';
                document.getElementById('dupResultsBody').innerHTML = '';
                document.getElementById('dupNoResults').style.display = 'none';
                const status = document.getElementById('dupStatus');
                if (status) status.style.display = 'none';
            });

            // Handle duplicate check (Main search or specific fields)
            const notify = (type, message, title) => {
                if (window.appNotify) return window.appNotify(type, message, title || '');
                alert(String(message || ''));
            };
            const performCheck = async (mode = 'specific') => {
                const firstName = document.getElementById('dup_first_name').value.trim();
                const lastName = document.getElementById('dup_last_name').value.trim();
                const email = document.getElementById('dup_email').value.trim();
                const companyName = document.getElementById('dup_company_name').value.trim();
                const search = document.getElementById('dup_search').value.trim();
                const allData = document.getElementById('dup_all_data').checked;
                const csrf = document.getElementById('dup_csrf').value;

                if (mode === 'search' && !search) {
                    notify('warning', 'Please enter a search term.', 'Warning');
                    return;
                }
                if (mode === 'specific' && !firstName && !lastName && !email && !companyName) {
                    notify('warning', 'Please enter at least one value to check.', 'Warning');
                    return;
                }

                // Call the endpoint
                try {
                    const res = await fetch('check_duplicates', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ 
                            campaign_id: document.getElementById('campaign_id')?.value || '', 
                            first_name: firstName, 
                            last_name: lastName, 
                            email, 
                            company_name: companyName,
                            search: search,
                            all_data: allData,
                            csrf_token: csrf 
                        })
                    });
                    const data = await res.json().catch(() => null);
                    const resultsWrap = document.getElementById('dupResults');
                    const tbody = document.getElementById('dupResultsBody');
                    const noRes = document.getElementById('dupNoResults');
                    const status = document.getElementById('dupStatus');
                    tbody.innerHTML = '';

                    if (!res.ok || !data || !data.ok) {
                        notify('danger', (data && data.error) ? data.error : 'Failed to check duplicates', 'Error');
                        return;
                    }

                    const count = Number(data.count || 0);
                    // Update status banner
                    status.style.display = 'block';
                    if (count > 0) {
                        status.className = 'alert alert-warning';
                        status.innerText = `Suppression: MATCHES FOUND (${count})`;
                        for (const m of data.matches) {
                            const fullName = ((m.first_name || '') + ' ' + (m.last_name || '')).trim();
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>${escapeHtml(String(m.id))}</td>
                                <td>
                                    ${escapeHtml(fullName)}<br>
                                    <small class="text-muted">${escapeHtml(m.campaign_name || 'N/A')}</small>
                                </td>
                                <td>${escapeHtml(m.email || '')}</td>
                                <td>${escapeHtml(m.company_name || '')}</td>
                                <td><span class="badge bg-secondary">${escapeHtml(m.qa_status || '')}</span></td>
                                <td class="small">${escapeHtml(m.created_at || '')}</td>
                                <td><a class="btn btn-sm btn-outline-primary" href="view?id=${encodeURIComponent(String(m.id))}" target="_blank"><i class="bi bi-eye"></i></a></td>
                            `;
                            tbody.appendChild(tr);
                        }
                        noRes.style.display = 'none';
                    } else {
                        status.className = 'alert alert-success';
                        status.innerText = 'Suppression: NO MATCH';
                        noRes.style.display = 'block';
                    }
                    resultsWrap.style.display = 'block';
                } catch (e) {
                    console.error(e);
                    notify('danger', 'An error occurred while checking.', 'Error');
                }
            };

            document.getElementById('dupCheckBtn').addEventListener('click', () => performCheck('specific'));
            document.getElementById('dupSearchBtn').addEventListener('click', () => performCheck('search'));
            document.getElementById('dup_search').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') performCheck('search');
            });
        });
        
        // Email validation
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        // Safe HTML escape
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.innerText = str ?? '';
            return div.innerHTML;
        }
    </script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
