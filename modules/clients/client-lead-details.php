<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','client_admin','client_sdr']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$clientId = (int)($user['client_id'] ?? 0);
if (isAdmin()) $clientId = (int)($_GET['client_id'] ?? $clientId);
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

$leadId = (int)($_GET['id'] ?? 0);
if ($leadId <= 0) { header('Location: client-leads'); exit; }

$conn = getDbConnection();
$stmt = $conn->prepare("
  SELECT l.*, c.name AS campaign_name, d.client_id
  FROM leads l
  JOIN campaign_details d ON d.campaign_id = l.campaign_id
  LEFT JOIN campaigns c ON c.id = l.campaign_id
  WHERE l.id = ?
  LIMIT 1
");
$stmt->bind_param('i', $leadId);
$stmt->execute();
$lead = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$lead || !canUserAccessLead($lead, ['allow_client_pending' => false])) { header('Location: client-leads'); exit; }

$cds = normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Pending'));
if (!isAdmin() && $cds !== 'Delivered') {
  http_response_code(403);
  echo 'Access denied';
  exit;
}

$fullName = trim((string)($lead['first_name'] ?? '') . ' ' . (string)($lead['last_name'] ?? ''));
$leadCode = (string)($lead['lead_id'] ?? (string)$leadId);
$createdAt = !empty($lead['created_at']) ? date('Y-m-d H:i', strtotime((string)$lead['created_at'])) : '—';
$formDone = normalizeFormDone((string)($lead['form_done'] ?? 'No'));
$formClass = ($formDone === 'Yes') ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
$formFilledAt = !empty($lead['form_filled_time']) ? date('Y-m-d H:i', strtotime((string)$lead['form_filled_time'])) : '—';
$qaUpdatedAt = !empty($lead['qa_updated_at']) ? date('Y-m-d H:i', strtotime((string)$lead['qa_updated_at'])) : '—';
$jobTitle = trim((string)($lead['job_title'] ?? ''));
$email = trim((string)($lead['email'] ?? ''));
$phone = trim((string)($lead['contact_phone'] ?? ''));
$linkedin = '';
$company = trim((string)($lead['company_name'] ?? ''));
$companySize = trim((string)($lead['company_size'] ?? ''));
$country = trim((string)($lead['country'] ?? ''));
$industry = trim((string)($lead['industry'] ?? ''));
$companyLinkedin = '';
$clientComment = trim((string)($lead['qa_client_comment'] ?? ''));
$comment = trim((string)($lead['lead_comment'] ?? ''));
$recording = trim((string)($lead['recording_path'] ?? ''));
$canViewRecording = (isAdmin() || (function_exists('isQA') && isQA()) || (function_exists('isOperationsManager') && isOperationsManager()) || (function_exists('isOperationsDirector') && isOperationsDirector()) || (function_exists('isSalesManager') && isSalesManager()) || (function_exists('isSalesDirector') && isSalesDirector()));

$campaignId = (int)($lead['campaign_id'] ?? 0);
$form = $campaignId > 0 ? getFormForCampaign($campaignId) : null;
$schemaFields = (array)($form['schema']['fields'] ?? []);
$submission = ($campaignId > 0 && $leadId > 0) ? getLatestFormSubmissionForLead($leadId, $campaignId) : null;
$submissionData = is_array($submission['data'] ?? null) ? $submission['data'] : [];
$companyWebsite = trim((string)(extractSubmissionValue($submissionData, ['company_website','website','domain','company_domain']) ?? ''));
if ($companyWebsite === '') $companyWebsite = trim((string)($lead['company_domain'] ?? ''));
$linkedin = trim((string)(extractSubmissionValue($submissionData, ['linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_url','prospect_linkedin_profile']) ?? ''));
$companyLinkedin = trim((string)(extractSubmissionValue($submissionData, ['company_linkedin','company_linkedin_url','company_linkedin_link','companylinkedin','companylinkedinurl']) ?? ''));
$timeline = trim((string)(extractSubmissionValue($submissionData, [
  'software_implementation_timeline',
  'implementation_timeline',
  'decision_timeline',
  'timeline',
  'when_is_your_company_planning_to_implement_new_software',
  'when_is_your_company_planning_to_implement_this_solution',
  'when_is_your_company_planning_to_implement_new_software_solution',
]) ?? ''));

$extraFields = [];
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
  'company_website','website','domain','company_domain',
  'company_linkedin','company_linkedin_link','company_linkedin_url','companylinkedin','companylinkedinurl',
  'company_size','employee_size','employee_sizes','employees','headcount',
  'country','country_name','location',
  'industry',
  'implementation_timeline','software_implementation_timeline','timeline',
  'lead_comment','comment','notes',
  'qa_status','qa_comment','qa_client_comment','client_comment','client_comments',
  'form_done','form_filled_time','submitted_at',
  'recording','recording_path','recording_file_path',
]), true);
$seen = [];
foreach ($schemaFields as $f) {
  if (!is_array($f)) continue;
  if (array_key_exists('visible', $f) && empty($f['visible'])) continue;
  $key = (string)($f['key'] ?? '');
  if ($key === '') continue;
  $label = (string)($f['label'] ?? $key);
  $val = array_key_exists($key, $submissionData) ? $submissionData[$key] : null;
  $nk = $norm($key);
  $nl = $norm($label);
  if (($nk !== '' && isset($skipNorms[$nk])) || ($nl !== '' && isset($skipNorms[$nl]))) continue;

  $dedupeKey = $nk !== '' ? $nk : $nl;
  if ($dedupeKey !== '' && isset($seen[$dedupeKey])) continue;
  if ($dedupeKey !== '') $seen[$dedupeKey] = true;

  $valStr = '';
  if (is_array($val)) {
    $valStr = implode(', ', array_filter(array_map('strval', $val), fn($x) => trim($x) !== ''));
  } else {
    $valStr = trim((string)($val ?? ''));
  }
  if ($valStr === '') continue;
  $extraFields[] = ['label' => $label, 'value' => $val];
}

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

$pageTitle = 'Lead Details';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h5 mb-1"><?php echo htmlspecialchars($fullName !== '' ? $fullName : 'Lead'); ?></div>
      <div class="text-muted small">
        Lead ID: <?php echo htmlspecialchars($leadCode); ?>
        <span class="mx-1">•</span>
        Created: <?php echo htmlspecialchars($createdAt); ?>
      </div>
    </div>
    <div class="text-end">
      <div class="d-flex flex-wrap gap-2 justify-content-end">
        <span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars((string)($lead['campaign_name'] ?? '')); ?></span>
        <span class="badge <?php echo $formClass; ?> border">Submitted: <?php echo htmlspecialchars(($formDone === 'Yes') ? 'Submitted' : 'Not Submitted'); ?></span>
        <?php 
          $st = normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Delivered'));
          $cls = 'bg-success-subtle text-success';
          if ($st === 'Rejected') $cls = 'bg-danger-subtle text-danger';
          if ($st === 'TBD(To be discussed)') $cls = 'bg-warning-subtle text-warning';
          if ($st === 'In Progress') $cls = 'bg-info-subtle text-info';
        ?>
        <span class="badge <?php echo $cls; ?> border">Delivery: <?php echo htmlspecialchars($st); ?></span>
      </div>
      <div class="mt-2 d-flex gap-2 justify-content-end">
        <a class="btn btn-light border btn-sm" href="client-leads.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold">Contact</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="text-muted small">Job Title</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($jobTitle !== '' ? $jobTitle : '—'); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Email</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($email !== '' ? $email : '—'); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Phone</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($phone !== '' ? $phone : '—'); ?></div>
            </div>
            <div class="col-12">
              <div class="text-muted small">LinkedIn</div>
              <?php if ($linkedin !== ''): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($linkedin); ?>" target="_blank" rel="noopener noreferrer">
                  <i class="bi bi-linkedin me-1"></i>Open Profile
                </a>
              <?php else: ?>
                <div class="fw-semibold">—</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold">Company</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="text-muted small">Company Name</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($company !== '' ? $company : '—'); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Company Size</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($companySize !== '' ? $companySize : '—'); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Country</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($country !== '' ? $country : '—'); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Industry</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($industry !== '' ? $industry : '—'); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Implementation Timeline</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($timeline !== '' ? $timeline : '—'); ?></div>
            </div>
            <div class="col-12">
              <div class="text-muted small">Company LinkedIn</div>
              <?php if ($companyLinkedin !== ''): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($companyLinkedin); ?>" target="_blank" rel="noopener noreferrer">
                  <i class="bi bi-linkedin me-1"></i>Open Page
                </a>
              <?php else: ?>
                <div class="fw-semibold">—</div>
              <?php endif; ?>
            </div>
            <div class="col-12">
              <div class="text-muted small">Company Website</div>
              <?php if ($companyWebsite !== ''): ?>
                <?php echo renderUrlPreviewCard($companyWebsite, 'Company Website', 'Open Website'); ?>
              <?php else: ?>
                <div class="fw-semibold">—</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold">Delivery</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6">
              <div class="text-muted small">Submitted At</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($formFilledAt); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Delivered At</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($qaUpdatedAt); ?></div>
            </div>
            <div class="col-12">
              <div class="text-muted small">Delivered By</div>
              <div class="fw-semibold"><?php echo htmlspecialchars('TaRaj Global Solutions'); ?></div>
            </div>
            <div class="col-12">
              <div class="text-muted small">Client Comment</div>
              <div class="fw-semibold"><?php echo nl2br(htmlspecialchars($clientComment !== '' ? $clientComment : '—')); ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold">Notes & Media</div>
        <div class="card-body">
          <div class="mb-3">
            <div class="text-muted small">Comment</div>
            <div class="fw-semibold"><?php echo nl2br(htmlspecialchars($comment !== '' ? $comment : '—')); ?></div>
          </div>
          <!-- <div class="text-muted small mb-1">Recording</div> -->
          <?php if (!$canViewRecording): ?>
            <!-- <div class="text-muted small">Hidden for client users.</div> -->
          <?php elseif ($recording !== ''): ?>
            <audio controls class="w-100" src="<?php echo htmlspecialchars($recording); ?>"></audio>
            <div class="mt-2">
              <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($recording); ?>" download><i class="bi bi-download me-1"></i>Download</a>
            </div>
          <?php else: ?>
            <div class="fw-semibold">—</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light fw-semibold">Lead Activity Timeline</div>
        <div class="card-body p-0">
          <?php if (empty($activity)): ?>
            <div class="p-3 text-muted small text-center">No activity recorded yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                  <tr>
                    <th class="ps-3">When</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th class="pe-3">User</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($activity as $a): ?>
                    <?php
                      $act = (string)($a['action'] ?? '');
                      if (!in_array($act, ['qa_updated','lead_tag_added','lead_tag_removed','lead_tag_edited'], true)) continue;
                      $meta = !empty($a['meta_json']) ? json_decode((string)$a['meta_json'], true) : [];
                      if (!is_array($meta)) $meta = [];
                      $details = '';
                      if ($act === 'qa_updated') {
                        $prev = (string)($meta['client_delivery_prev_status'] ?? '');
                        $cur = (string)($meta['client_delivery_status'] ?? '');
                        if ($cur !== '') {
                          $details = 'Delivery: ' . $cur;
                          if ($prev !== '') $details .= ' (from ' . $prev . ')';
                        } else {
                          $details = 'Delivery updated';
                        }
                        $cmt2 = trim((string)($meta['qa_client_comment'] ?? ''));
                        if ($cmt2 !== '') $details .= ' · ' . $cmt2;
                      } elseif ($act === 'lead_tag_added' || $act === 'lead_tag_removed' || $act === 'lead_tag_edited') {
                        $tag = (string)($meta['tag'] ?? ($meta['tag_name'] ?? ''));
                        $stage = (string)($meta['stage'] ?? '');
                        $note = (string)($meta['note'] ?? ($meta['comment'] ?? ''));
                        if ($tag !== '') $details .= 'Tag: ' . $tag;
                        if ($stage !== '') $details .= ($details !== '' ? ' · ' : '') . 'Stage: ' . $stage;
                        $note = trim($note);
                        if ($note !== '') $details .= ($details !== '' ? ' · ' : '') . $note;
                      }

                      $actorId = (int)($a['actor_id'] ?? 0);
                      $role = (string)($a['user_role'] ?? '');
                      $who = 'System';
                      if ($actorId > 0) {
                        if ($role !== '' && str_starts_with($role, 'client_')) $who = (string)($a['user_name'] ?? 'Client');
                        else $who = 'TaRaj Global Solutions';
                      }
                    ?>
                    <tr>
                      <td class="ps-3 small text-muted"><?php echo !empty($a['created_at']) ? htmlspecialchars(date('M j, Y H:i', strtotime((string)$a['created_at']))) : '—'; ?></td>
                      <td class="small fw-semibold"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($act))); ?></td>
                      <td class="small text-muted"><?php echo htmlspecialchars($details); ?></td>
                      <td class="pe-3 small text-muted"><?php echo htmlspecialchars($who); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!empty($extraFields)): ?>
      <div class="col-12">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-light fw-semibold">Campaign Questions</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Field</th>
                    <th>Value</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($extraFields as $f): ?>
                    <?php
                      $v = $f['value'];
                      $vs = '';
                      if (is_array($v)) $vs = implode(', ', array_map('strval', $v));
                      else $vs = (string)($v ?? '');
                      $vs = trim($vs);
                    ?>
                    <tr>
                      <td class="fw-semibold"><?php echo htmlspecialchars((string)($f['label'] ?? '')); ?></td>
                      <td class="text-muted small"><?php echo htmlspecialchars($vs !== '' ? $vs : '—'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
