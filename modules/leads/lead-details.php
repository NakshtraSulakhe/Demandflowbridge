<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('leads.details.page', []);
ensureCsrfToken();

$user = getCurrentUser();

$leadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$leadCode = isset($_GET['lead_id']) ? trim((string)$_GET['lead_id']) : '';
if ($leadId <= 0 && $leadCode === '') {
    header('Location: my');
    exit;
}

$lead = $leadId > 0 ? getLeadById($leadId) : getLeadByCode($leadCode);
if (!$lead) {
    header('Location: my');
    exit;
}

if (!canUserAccessLead($user, $lead)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$lead = enrichLeadRow($lead);
$campaignId = (int)($lead['campaign_id'] ?? 0);
$get = function(string $k) use ($lead) {
    if (array_key_exists($k, $lead) && $lead[$k] !== null && (string)$lead[$k] !== '') return $lead[$k];
    return null;
};

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

$fullName = trim((string)($get('first_name') ?? '') . ' ' . (string)($get('last_name') ?? ''));
$qaStatus = normalizeQaStatus((string)($lead['qa_status'] ?? 'Pending'));
$qaClass = qaStatusBadgeClass($qaStatus);
$qaStatusOptions = function_exists('getQaStatusOptions') ? getQaStatusOptions() : [];
$clientDeliveryOptions = function_exists('getClientDeliveryStatuses') ? getClientDeliveryStatuses() : [];
$createdAtRaw = (string)($lead['created_at'] ?? '');
$createdAt = $createdAtRaw !== '' ? date('Y-m-d H:i', strtotime($createdAtRaw)) : '—';
$qaUpdatedAtRaw = (string)($lead['qa_updated_at'] ?? '');
$qaUpdatedAt = $qaUpdatedAtRaw !== '' ? date('Y-m-d H:i', strtotime($qaUpdatedAtRaw)) : '—';
$qa_comment = (string)($lead['qa_comment'] ?? '');
$recording = (string)($lead['recording_path'] ?? '');
$ipAddress = (string)($lead['ip_address'] ?? '');
$campaignName = (string)($lead['campaign_name'] ?? '');

$conn = getDbConnection();
$lid = (int)($lead['id'] ?? 0);
$activityRows = [];
$canSeeTimeline = !isClient() && (isAdmin() || isDirector() || isManagerDirector() || isOperationsDirector() || isOperationsManager() || isQA());
if ($canSeeTimeline && $lid > 0) {
    $stmt = $conn->prepare("
        SELECT la.*, u.full_name AS user_name, u.role AS user_role, u.job_title AS user_job_title
        FROM lead_activity la
        LEFT JOIN users u ON la.actor_id = u.id
        WHERE la.lead_id = ?
        ORDER BY la.created_at DESC
        LIMIT 50
    ");
    if ($stmt) {
        $stmt->bind_param('i', $lid);
        $stmt->execute();
        $activityRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($activityRows as &$ar) {
            $ar['meta'] = !empty($ar['meta_json']) ? json_decode((string)$ar['meta_json'], true) : [];
            if (!is_array($ar['meta'])) $ar['meta'] = [];
        }
        unset($ar);
    }
}

$recordingFileRows = [];
if ($lid > 0) {
    $stmt = $conn->prepare("SELECT id, file_path, uploaded_at FROM lead_files WHERE lead_id = ? AND field_id = 'call_recording' ORDER BY uploaded_at ASC, id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $lid);
        $stmt->execute();
        $recordingFileRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$qaHistory = [];
if (isQA() || isAdmin()) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT q.prev_status, q.qa_status, q.qa_comment, q.reviewed_at, u.full_name AS reviewer_name, u.role AS reviewer_role, u.job_title AS reviewer_job_title
        FROM qa_audit_logs q
        LEFT JOIN users u ON u.id = q.qa_reviewed_by
        WHERE q.lead_id = ?
        ORDER BY q.reviewed_at DESC
        LIMIT 10
    ");
    if ($stmt) {
        $idToUse = (int)($lead['id'] ?? 0);
        $stmt->bind_param('i', $idToUse);
        $stmt->execute();
        $qaHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$form = $campaignId > 0 ? getFormForCampaign($campaignId) : null;
$schemaFields = (array)($form['schema']['fields'] ?? []);
$submission = ($campaignId > 0 && (int)($lead['id'] ?? 0) > 0) ? getLatestFormSubmissionForLead((int)$lead['id'], $campaignId) : null;
$submissionData = is_array($submission['data'] ?? null) ? $submission['data'] : [];
$companyWebsite = '';
if (isset($submissionData['company_website'])) $companyWebsite = trim((string)$submissionData['company_website']);
elseif (isset($submissionData['website'])) $companyWebsite = trim((string)$submissionData['website']);
elseif (isset($submissionData['domain'])) $companyWebsite = trim((string)$submissionData['domain']);
if ($companyWebsite === '') {
    $cd = trim((string)($lead['company_domain'] ?? ''));
    if ($cd !== '') $companyWebsite = $cd;
}
$ensureProtocol = function($url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('~^(?:f|ht)tps?://~i', $url)) return 'https://' . $url;
    return $url;
};
$companyWebsite = $ensureProtocol($companyWebsite);

$pickFromSubmission = function(array $aliases) use ($submissionData): ?string {
    foreach ($aliases as $k) {
        if (array_key_exists($k, $submissionData)) {
            $v = $submissionData[$k];
            if (is_array($v)) continue;
            $s = trim((string)$v);
            if ($s !== '') return $s;
        }
    }
    return null;
};

$norm = function(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
};
$submissionIndex = [];
foreach ($submissionData as $k => $v) {
    $nk = $norm((string)$k);
    if ($nk === '') continue;
    $submissionIndex[$nk] = $v;
}
$getSubmissionValue = function(string $key, string $label) use ($submissionData, $submissionIndex, $norm) {
    if (array_key_exists($key, $submissionData)) return $submissionData[$key];
    $nk = $norm($key);
    if ($nk !== '' && array_key_exists($nk, $submissionIndex)) return $submissionIndex[$nk];

    $nl = $norm($label);
    $candidates = [];

    $timeline = [
        'software_implementation_timeline',
        'implementation_timeline',
        'decision_timeline',
        'timeline',
        'when_is_your_company_planning_to_implement_new_software',
        'when_is_your_company_planning_to_implement_this_solution',
        'when_is_your_company_planning_to_implement_new_software_solution',
    ];
    if (in_array($nk, $timeline, true) || in_array($nl, $timeline, true)) {
        $candidates = array_merge($candidates, $timeline);
    }

    $prospectLi = [
        'linkedin_link',
        'linkedin_url',
        'linkedin_profile',
        'prospect_linkedin',
        'prospect_linkedin_link',
        'prospect_linkedin_url',
        'prospect_linkedin_profile',
    ];
    if (str_contains($nk, 'linkedin') && !str_contains($nk, 'company') && !str_contains($nk, 'org')) {
        $candidates = array_merge($candidates, $prospectLi);
    }

    $companyLi = [
        'company_linkedin',
        'company_linkedin_url',
        'company_linkedin_link',
        'companylinkedin',
        'companylinkedinurl',
    ];
    if (str_contains($nk, 'company') && str_contains($nk, 'linkedin')) {
        $candidates = array_merge($candidates, $companyLi);
    }

    $website = ['company_website', 'website', 'domain', 'company_domain'];
    if (str_contains($nk, 'website') || str_contains($nk, 'domain')) {
        $candidates = array_merge($candidates, $website);
    }

    $candidates = array_values(array_unique(array_filter($candidates, fn($x) => trim((string)$x) !== '')));
    foreach ($candidates as $cand) {
        $cn = $norm((string)$cand);
        if ($cn !== '' && array_key_exists($cn, $submissionIndex)) return $submissionIndex[$cn];
    }
    return null;
};
$skipNorms = array_fill_keys(array_map($norm, [
    'first_name','firstname','first','given_name',
    'last_name','lastname','last','surname','family_name',
    'job_title','jobtitle','title','designation',
    'email','email_address','work_email','emailaddress',
    'linkedin','linkedin_link','linkedin_url','linkedin_profile','linkedinprofile',
    'prospect_linkedin','prospect_linkedin_link','prospect_linkedin_profile','prospect_linkedin_url',
    'linked_in','linked_in_profile','linked_in_url','linked_in_link',
    'phone','contact_phone','phone_number','mobile','mobile_number','contact_number',
    'company','company_name','companyname','organization','organisation','account_name',
    'company_linkedin','company_linkedin_link','company_linkedin_url','companylinkedin','companylinkedinurl',
    'company_size','employee_size','employee_sizes','employees','headcount',
    'country','country_name','location','location_country',
    'industry',
    'company_website','website','company_site','domain',
    'ip','ip_address',
    'recording','recording_path','recording_file_path',
    'lead_comment','comment','notes',
]), true);

$prospectNorms = array_fill_keys(array_map($norm, [
    'first_name','last_name','job_title','email','contact_phone','phone',
    'linkedin_link','linkedin_profile','linkedin_url','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_profile','prospect_linkedin_url',
]), true);
$companyNorms = array_fill_keys(array_map($norm, [
    'company_name','company_website','industry','employee_size','company_size','country','company_linkedin',
]), true);

$fieldGroups = [
    'Prospect Details' => [],
    'Company Details' => [],
    'Campaign Questions' => [],
];
$seen = [];

foreach ($schemaFields as $f) {
    if (!is_array($f)) continue;
    if (array_key_exists('visible', $f) && empty($f['visible'])) continue;
    $key = (string)($f['key'] ?? '');
    if ($key === '') continue;
    $label = (string)($f['label'] ?? $key);
    $type = (string)($f['type'] ?? 'text');

    $nk = $norm($key);
    $nl = $norm($label);
    if (($nk !== '' && isset($skipNorms[$nk])) || ($nl !== '' && isset($skipNorms[$nl]))) {
        continue;
    }

    $dedupeKey = $nk !== '' ? $nk : $nl;
    if ($dedupeKey !== '' && isset($seen[$dedupeKey])) {
        continue;
    }
    if ($dedupeKey !== '') $seen[$dedupeKey] = true;

    $col = normalizeFieldKey($key);
    $val = null;
    $val = $getSubmissionValue($key, $label);

    $group = 'Campaign Questions';
    if (($nk !== '' && isset($prospectNorms[$nk])) || ($nl !== '' && isset($prospectNorms[$nl]))) $group = 'Prospect Details';
    if (($nk !== '' && isset($companyNorms[$nk])) || ($nl !== '' && isset($companyNorms[$nl]))) $group = 'Company Details';

    $fieldGroups[$group][] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => $val];
}

?>
<?php $pageTitle = 'Lead Details'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 leads-workspace">
    <?php if (isset($_GET['qa_updated']) && (string)$_GET['qa_updated'] === '1'): ?>
        <div class="alert alert-success border-0 shadow-sm mb-3">QA status updated.</div>
    <?php endif; ?>
    <div class="leads-page-header leads-hero mb-3">
        <div>
            <div class="leads-eyebrow mb-2"><i class="bi bi-person-vcard"></i>Lead Profile</div>
            <div class="leads-page-title"><?php echo htmlspecialchars($fullName !== '' ? $fullName : 'Lead'); ?></div>
            <div class="leads-page-subtitle">
                Lead ID: <?php echo htmlspecialchars((string)($lead['lead_id'] ?? (string)($lead['id'] ?? ''))); ?>
                <span class="mx-1">•</span>
                Created: <?php echo htmlspecialchars($createdAt); ?>
            </div>
        </div>
        <div class="text-end">
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars($campaignName); ?></span>
                <span class="badge <?php echo $qaClass; ?> border">QA: <?php echo htmlspecialchars($qaStatus ?: 'Pending'); ?></span>
            </div>
            <div class="mt-2 d-flex gap-2 justify-content-end">
                <a class="btn btn-light border btn-sm" href="javascript:history.back()"><i class="bi bi-arrow-left me-1"></i>Back</a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm leads-panel h-100">
                <div class="card-header bg-light fw-semibold">Prospect</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="text-muted small">Job Title</div>
                            <?php $jt = $pickFromSubmission(['job_title','title','designation']) ?? (string)($get('job_title') ?? ''); ?>
                            <div class="fw-semibold"><?php echo htmlspecialchars($jt !== '' ? $jt : '—'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Email</div>
                            <?php $em = $pickFromSubmission(['email','work_email','email_address']) ?? (string)($get('email') ?? ''); ?>
                            <div class="fw-semibold"><?php echo htmlspecialchars($em !== '' ? $em : '—'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Phone</div>
                            <?php $ph = $pickFromSubmission(['contact_phone','phone','phone_number','mobile','mobile_number','contact_number']) ?? (string)($lead['contact_phone'] ?? ''); ?>
                            <div class="fw-semibold"><?php echo htmlspecialchars($ph !== '' ? $ph : '—'); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Prospect LinkedIn</div>
                            <?php $li = trim((string)($pickFromSubmission(['linkedin_link','linkedin_profile','linkedin_url','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_url']) ?? ($lead['linkedin_link'] ?? ''))); ?>
                            <?php if ($li !== ''): ?>
                                <a class="btn leads-linkedin-btn btn-sm rounded-pill px-3 shadow-sm" href="<?php echo htmlspecialchars($li); ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-linkedin me-1"></i>Open Profile
                                </a>
                            <?php else: ?>
                                <div class="fw-semibold">—</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">IP Address</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($ipAddress !== '' ? $ipAddress : '—'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm leads-panel h-100">
                <div class="card-header bg-light fw-semibold">Company</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="text-muted small">Company Name</div>
                            <?php $cn = $pickFromSubmission(['company_name','company','organization','organisation','account_name']) ?? (string)($get('company_name') ?? ''); ?>
                            <div class="fw-semibold"><?php echo htmlspecialchars($cn !== '' ? $cn : '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Industry</div>
                            <?php $ind = $pickFromSubmission(['industry']) ?? (string)($get('industry') ?? ''); ?>
                            <div class="fw-semibold"><?php echo htmlspecialchars($ind !== '' ? $ind : '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Employee Size</div>
                            <?php $esz = $pickFromSubmission(['employee_size','company_size','employees','headcount']) ?? (string)($lead['company_size'] ?? ''); ?>
                            <div class="fw-semibold"><?php echo htmlspecialchars($esz !== '' ? $esz : '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Country</div>
                            <?php $cty = $pickFromSubmission(['country','country_name','location_country']) ?? (string)($get('country') ?? ''); ?>
                            <div class="fw-semibold"><?php echo htmlspecialchars($cty !== '' ? $cty : '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Company LinkedIn</div>
                            <?php $cli = trim((string)($pickFromSubmission(['company_linkedin','company_linkedin_url','company_linkedin_link','companylinkedin','companylinkedinurl']) ?? ($lead['company_linkedin'] ?? ''))); ?>
                            <?php $cli = $ensureProtocol($cli); ?>
                            <?php if ($cli !== ''): ?>
                                <a class="btn leads-linkedin-btn btn-sm rounded-pill px-3 shadow-sm" href="<?php echo htmlspecialchars($cli); ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-linkedin me-1"></i>Open Page
                                </a>
                            <?php else: ?>
                                <div class="fw-semibold">—</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small mb-2">Company Website</div>
                            <?php if ($companyWebsite !== ''): ?>
                                <div class="leads-website-pill">
                                    <div class="leads-website-icon">
                                        <i class="bi bi-globe"></i>
                                    </div>
                                    <div class="flex-grow-1 min-width-0">
                                        <div class="fw-bold small text-dark text-truncate">Official Website</div>
                                        <a href="<?php echo htmlspecialchars($companyWebsite); ?>" target="_blank" class="leads-website-link text-primary text-decoration-none d-block text-truncate">
                                            <?php echo htmlspecialchars($companyWebsite); ?> <i class="bi bi-box-arrow-up-right ms-1"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="fw-semibold">—</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm leads-panel">
                <div class="card-header bg-light fw-semibold">Qualifying Questions</div>
                <div class="card-body">
                    <?php $formName = (string)($form['name'] ?? ''); ?>
                    <?php if ($formName !== ''): ?>
                        <div class="text-muted small mb-2"><?php echo htmlspecialchars($formName); ?></div>
                    <?php endif; ?>
                    <?php
                        $hasAny = false;
                        $qItems = $fieldGroups['Campaign Questions'] ?? [];
                        if (!empty($qItems)) $hasAny = true;
                    ?>
                    <?php if (!$hasAny): ?>
                        <div class="text-muted">No campaign-specific fields found for this lead.</div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($qItems as $fr): ?>
                                <?php
                                    $v = $fr['value'];
                                    $vs = '';
                                    if (is_array($v)) $vs = implode(', ', array_map('strval', $v));
                                    else $vs = (string)($v ?? '');
                                    $vs = trim($vs);
                                    $isFile = $vs !== '' && (preg_match('/^uploads\\//i', $vs) || preg_match('/\\/uploads\\//i', $vs) || preg_match('/^\\/[^\\s]*uploads\\//i', $vs));
                                    $isUrl = $vs !== '' && preg_match('/^https?:\\/\\//i', $vs);
                                    $t = strtolower((string)($fr['type'] ?? ''));
                                    $colClass = ($t === 'textarea' || $t === 'file_upload' || strlen($vs) > 60) ? 'col-12' : 'col-md-6';
                                    $open = $toUrl($vs);
                                ?>
                                <div class="<?php echo $colClass; ?>">
                                    <div class="text-muted small"><?php echo htmlspecialchars($fr['label']); ?></div>
                                    <?php if ($isFile): ?>
                                    <a class="link-primary fw-semibold text-decoration-none" href="<?php echo htmlspecialchars($open); ?>" target="_blank"><i class="bi bi-file-earmark-arrow-down me-1"></i>Open File</a>
                                <?php elseif ($isUrl): ?>
                                    <a class="link-primary fw-semibold text-decoration-none" href="<?php echo htmlspecialchars($open); ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Open Link</a>
                                <?php else: ?>
                                    <div class="fw-semibold"><?php echo nl2br(htmlspecialchars($vs !== '' ? $vs : '—')); ?></div>
                                <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm leads-panel">
                <div class="card-header bg-light fw-semibold">Tracking</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Agent</div>
                            <div class="fw-semibold">
                                <?php echo htmlspecialchars((isClient() && !isAdmin()) ? 'TaRaj Global Solutions' : (string)($lead['agent_name'] ?? '—')); ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">QA Updated</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($qaUpdatedAt); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">QA Comments</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($qa_comment); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Reviewed By</div>
                            <div class="fw-semibold">
                                <?php echo htmlspecialchars((isClient() && !isAdmin()) ? 'TaRaj Global Solutions' : (string)($lead['reviewer_name'] ?? '—')); ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div id="recordings"></div>
                            <?php
                                $recordingItems = [];
                                $seenRel = [];
                                foreach ($recordingFileRows as $rf) {
                                    $fid = (int)($rf['id'] ?? 0);
                                    if ($fid <= 0) continue;
                                    $fp = (string)($rf['file_path'] ?? '');
                                    $rel = function_exists('appNormalizeUploadsRelPath') ? appNormalizeUploadsRelPath($fp) : '';
                                    if ($rel !== '') $seenRel[$rel] = true;
                                    $recordingItems[] = [
                                        'url' => function_exists('appBuildLeadFileDownloadUrl') ? appBuildLeadFileDownloadUrl($fid, true) : (appBasePath() . '/modules/files/download?kind=lead_file&id=' . $fid . '&inline=1'),
                                        'dl' => function_exists('appBuildLeadFileDownloadUrl') ? appBuildLeadFileDownloadUrl($fid, false) : (appBasePath() . '/modules/files/download?kind=lead_file&id=' . $fid),
                                    ];
                                }

                                $primaryRaw = trim((string)$recording);
                                if ($primaryRaw !== '') {
                                    $rel = function_exists('appNormalizeUploadsRelPath') ? appNormalizeUploadsRelPath($primaryRaw) : '';
                                    if ($rel !== '') {
                                        if (!isset($seenRel[$rel])) {
                                            $recordingItems[] = [
                                                'url' => function_exists('appBuildLeadPathDownloadUrl') ? appBuildLeadPathDownloadUrl($lid, $rel, true) : $toUrl($rel),
                                                'dl' => function_exists('appBuildLeadPathDownloadUrl') ? appBuildLeadPathDownloadUrl($lid, $rel, false) : $toUrl($rel),
                                            ];
                                        }
                                    } else {
                                        $primaryRel = $toUrl($primaryRaw);
                                    $recordingItems[] = [
                                        'url' => $primaryRel,
                                        'dl' => $primaryRel,
                                    ];
                                    }
                                }
                            ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted small">Recordings</div>
                                <span class="badge text-bg-light border"><?php echo number_format(count($recordingItems)); ?></span>
                            </div>
                            <?php if (!empty($recordingItems)): ?>
                                <div class="mt-2 d-flex flex-column gap-2">
                                    <?php foreach ($recordingItems as $idxR => $itR): ?>
                                        <div class="border rounded p-2 bg-white shadow-sm">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="small fw-semibold text-muted">Recording <?php echo (int)$idxR + 1; ?></div>
                                                <div class="d-flex gap-2">
                                                    <a class="btn btn-xs btn-outline-primary py-0 px-2 small" href="<?php echo htmlspecialchars((string)($itR['dl'] ?? '')); ?>" download title="Download"><i class="bi bi-download"></i></a>
                                                </div>
                                            </div>
                                            <audio controls class="w-100" style="height: 34px;">
                                                <source src="<?php echo htmlspecialchars((string)($itR['url'] ?? '')); ?>">
                                            </audio>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="fw-semibold text-muted italic small">No recordings attached</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Comment</div>
                            <?php $cmt = (string)($get('lead_comment') ?? ($lead['lead_comment'] ?? '')); ?>
                            <div class="fw-semibold"><?php echo nl2br(htmlspecialchars(trim($cmt) !== '' ? $cmt : '—')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isQA() || isAdmin()): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm leads-panel">
                <div class="card-header bg-light fw-semibold">QA Review</div>
                <div class="card-body">
                    <form method="post" action="../qa/action" class="mb-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="lead_id" value="<?php echo (int)($lead['id'] ?? 0); ?>">
                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] . (str_contains($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'qa_updated=1'); ?>">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">QA Status</label>
                                <select class="form-select form-select-sm" name="qa_status">
                                    <?php
                                        $cur = normalizeQaStatus((string)($lead['qa_status'] ?? 'Pending'));
                                    ?>
                                    <?php foreach ($qaStatusOptions as $v => $lbl): ?>
                                        <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $cur === normalizeQaStatus($v) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($lbl); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Client Delivery</label>
                                <?php $cds = normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Pending')); ?>
                                <select class="form-select form-select-sm" name="client_delivery_status">
                                    <?php foreach ($clientDeliveryOptions as $v): ?>
                                        <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $cds === normalizeClientDeliveryStatus($v) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(clientDeliveryStatusDisplayLabel($v)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted mb-1">Internal Comment</label>
                                <textarea class="form-control form-control-sm" name="qa_comment_internal" rows="2" placeholder="Internal QA note..."><?php echo htmlspecialchars((string)($lead['qa_comment'] ?? '')); ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted mb-1">Client Comment (visible to client)</label>
                                <textarea class="form-control form-control-sm" name="qa_comment_client" rows="2" placeholder="Client-facing comment..."><?php echo htmlspecialchars((string)($lead['qa_client_comment'] ?? '')); ?></textarea>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-primary btn-sm" type="submit">Save QA</button>
                            </div>
                        </div>
                    </form>

                    <div class="fw-semibold mb-2">History</div>
                    <div class="table-responsive leads-table-wrap">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Change</th>
                                    <th>Comment</th>
                                    <th>Reviewer</th>
                                    <th class="text-muted">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($qaHistory)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">No QA history yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($qaHistory as $h): ?>
                                        <tr>
                                            <?php
                                                $from = normalizeQaStatus((string)($h['prev_status'] ?? ''));
                                                $to = normalizeQaStatus((string)($h['qa_status'] ?? ''));
                                                $chg = ($from !== '' && $from !== $to) ? ($from.' → '.$to) : $to;
                                            ?>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($chg); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars((string)($h['qa_comment'] ?? '')); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole((string)($h['reviewer_name'] ?? ''), (string)($h['reviewer_role'] ?? ''), (string)($h['reviewer_job_title'] ?? ''))); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)($h['reviewed_at'] ?? 'now')))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canSeeTimeline): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm leads-panel">
                <div class="card-header bg-light fw-semibold">Lead Activity Timeline</div>
                <div class="card-body">
                    <?php if (empty($activityRows)): ?>
                        <div class="text-muted">No activity found.</div>
                    <?php else: ?>
                        <div class="table-responsive leads-table-wrap">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 160px;">When</th>
                                        <th>Action</th>
                                        <th>Stage</th>
                                        <th>Tag</th>
                                        <th>Note</th>
                                        <th style="width: 220px;">By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activityRows as $ar): ?>
                                        <?php
                                            $when = (string)($ar['created_at'] ?? '');
                                            $action = (string)($ar['action'] ?? '');
                                            $by = (string)($ar['user_name'] ?? '');
                                            $role = (string)($ar['user_role'] ?? '');
                                            $meta = (array)($ar['meta'] ?? []);
                                            
                                            $stage = (string)($meta['stage'] ?? '');
                                            $tag = (string)($meta['tag'] ?? ($meta['tag_name'] ?? ''));
                                            $note = (string)($meta['note'] ?? ($meta['comment'] ?? ''));
                                            
                                            $label = $action;
                                            if ($action === 'lead_tag_added') $label = 'Tag Added';
                                            elseif ($action === 'lead_tag_removed') $label = 'Tag Removed';
                                            elseif ($action === 'lead_tag_edited') $label = 'Tag Edited';
                                            elseif ($action === 'lead_created') $label = 'Lead Created';
                                            elseif ($action === 'qa_updated') $label = 'QA Updated';
                                            elseif ($action === 'form_submission_saved') $label = 'Form Saved';
                                            elseif ($action === 'lead_assigned') $label = 'Lead Assigned';
                                            else $label = str_replace('_', ' ', ucfirst($action));
                                        ?>
                                        <tr>
                                            <td class="text-muted small"><?php echo htmlspecialchars($when !== '' ? date('d M Y H:i', strtotime($when)) : ''); ?></td>
                                            <td class="fw-semibold small"><?php echo htmlspecialchars($label); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($stage ?: '—'); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($tag ?: '—'); ?></td>
                                            <td class="text-muted small">
                                                <?php if ($note !== ''): ?>
                                                    <span class="d-inline-block text-truncate" style="max-width: 250px;" title="<?php echo htmlspecialchars($note); ?>">
                                                        <?php echo htmlspecialchars($note); ?>
                                                    </span>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted small"><?php echo htmlspecialchars(trim($by . ($role !== '' ? (' · ' . $role) : ''))); ?></td>
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
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
