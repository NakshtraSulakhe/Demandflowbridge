<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr','admin']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$isSdr = isSDR();
$isManager = isSalesManager();
$isDirector = isSalesDirector() || isAdmin();

$error = '';
$success = '';
$duplicates = [];

$statuses = ['New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];
$priorities = ['Low','Normal','High','Urgent'];
$sources = ['Manual Outreach','LinkedIn','Referral','Event','Website','Other'];

$managers = [];
$mgrRs = $conn->query("SELECT id, full_name FROM users WHERE role IN ('sales_manager','sales_director') ORDER BY full_name");
if ($mgrRs) $managers = $mgrRs->fetch_all(MYSQLI_ASSOC) ?: [];
$lockedManagerId = null;
if ($isSdr) {
    $lockedManagerId = getSalesManagerForSdr($userId);
} elseif ($isManager && !$isDirector) {
    $lockedManagerId = $userId;
}

$form = [
    'company_name' => '',
    'website' => '',
    'industry' => '',
    'company_size' => '',
    'country' => '',
    'contact_name' => '',
    'contact_job_title' => '',
    'contact_email' => '',
    'contact_phone' => '',
    'linkedin_url' => '',
    'sales_manager_id' => '',
    'lead_source' => 'Manual Outreach',
    'status' => 'New',
    'priority' => 'Normal',
    'next_follow_up_at' => '',
    'expected_opportunity_size' => '',
    'notes' => '',
];
if ($lockedManagerId !== null) {
    $form['sales_manager_id'] = (string)$lockedManagerId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        foreach ($form as $k => $_) {
            $form[$k] = trim((string)($_POST[$k] ?? $form[$k]));
        }
        if (!in_array($form['status'], $statuses, true)) $form['status'] = 'New';
        if (!in_array($form['priority'], $priorities, true)) $form['priority'] = 'Normal';
        if (!in_array($form['lead_source'], $sources, true)) $form['lead_source'] = 'Manual Outreach';

        try {
            $dupData = [
                'company_name' => $form['company_name'],
                'website' => $form['website'],
                'contact_email' => $form['contact_email'],
                'linkedin_url' => $form['linkedin_url'],
            ];
            $duplicates = findDuplicateSalesLeads($dupData, 0, 10);
            if (!empty($duplicates)) {
                throw new RuntimeException('Possible duplicate prospect found. Open the existing prospect and add a new comment instead of creating a duplicate.');
            }

            $payload = $form;
            $payload['owner_id'] = $userId;
            $id = createSalesLead($payload, $userId);
            $nfRaw = trim((string)($form['next_follow_up_at'] ?? ''));
            $nf = null;
            if ($nfRaw !== '') {
                $nfRaw = str_replace('T', ' ', $nfRaw);
                if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $nfRaw)) $nfRaw .= ':00';
                $ts = strtotime($nfRaw);
                if ($ts !== false) $nf = date('Y-m-d H:i:s', $ts);
            }
            addSalesLeadActivity($id, $form['status'], 'Created prospect', $userId, $nf);
            header('Location: lead-view?id='.$id);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<?php $pageTitle = 'New Prospect'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 sales-workspace">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-person-plus"></i>Prospect Intake</div>
      <h3 class="sales-page-title">New Prospect</h3>
      <div class="sales-page-subtitle">Create a clean outbound prospect record, validate duplicates early, and prepare the first follow-up before it enters the pipeline.</div>
    </div>
    <a href="leads.php" class="btn btn-outline-primary btn-sm">Back to Pipeline</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <?php if (!empty($duplicates)): ?>
      <div class="card mb-3 sales-panel border-0 shadow-sm">
      <div class="card-header fw-semibold">Possible Duplicate Prospects</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Company</th>
              <th>Status</th>
              <th>Website</th>
              <th>Email</th>
              <th>LinkedIn</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($duplicates as $d): ?>
              <tr>
                <td class="fw-semibold"><a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['company_name'] ?? ''); ?></a></td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($d['status'] ?? ''); ?></span></td>
                <td class="text-muted small"><?php echo htmlspecialchars($d['website'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($d['contact_email'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($d['linkedin_url'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="row g-3" id="salesLeadForm">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

    <div class="col-lg-8">
      <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav" id="createProspectTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active py-2" id="tab-company-btn" data-bs-toggle="pill" data-bs-target="#tab-company" type="button" role="tab">Company</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-2" id="tab-contact-btn" data-bs-toggle="pill" data-bs-target="#tab-contact" type="button" role="tab">Contact</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-2" id="tab-pipeline-btn" data-bs-toggle="pill" data-bs-target="#tab-pipeline" type="button" role="tab">Pipeline</button>
        </li>
      </ul>

      <div class="tab-content border rounded mt-2 p-3 bg-white sales-panel">
        <div class="tab-pane fade show active" id="tab-company" role="tabpanel" aria-labelledby="tab-company-btn">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Company Name</label>
              <input class="form-control form-control-sm" name="company_name" value="<?php echo htmlspecialchars($form['company_name']); ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Company Website / Domain</label>
              <input class="form-control form-control-sm" name="website" value="<?php echo htmlspecialchars($form['website']); ?>" placeholder="example.com">
            </div>
            <div class="col-md-4">
              <label class="form-label">Industry</label>
              <input class="form-control form-control-sm" name="industry" value="<?php echo htmlspecialchars($form['industry']); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Company Size</label>
              <input class="form-control form-control-sm" name="company_size" value="<?php echo htmlspecialchars($form['company_size']); ?>" placeholder="1-10, 11-50, 51-200...">
            </div>
            <div class="col-md-4">
              <label class="form-label">Country</label>
              <input class="form-control form-control-sm" name="country" value="<?php echo htmlspecialchars($form['country']); ?>">
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button type="button" class="btn btn-outline-primary btn-sm" data-next-tab="#tab-contact-btn">Next</button>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="tab-contact" role="tabpanel" aria-labelledby="tab-contact-btn">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Contact Person Name</label>
              <input class="form-control form-control-sm" name="contact_name" value="<?php echo htmlspecialchars($form['contact_name']); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Job Title</label>
              <input class="form-control form-control-sm" name="contact_job_title" value="<?php echo htmlspecialchars($form['contact_job_title']); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">LinkedIn Profile</label>
              <input class="form-control form-control-sm" name="linkedin_url" value="<?php echo htmlspecialchars($form['linkedin_url']); ?>" placeholder="https://linkedin.com/in/...">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input class="form-control form-control-sm" type="email" name="contact_email" value="<?php echo htmlspecialchars($form['contact_email']); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input class="form-control form-control-sm" name="contact_phone" value="<?php echo htmlspecialchars($form['contact_phone']); ?>">
            </div>
            <div class="col-12 d-flex justify-content-between">
              <button type="button" class="btn btn-light btn-sm" data-next-tab="#tab-company-btn">Back</button>
              <button type="button" class="btn btn-outline-primary btn-sm" data-next-tab="#tab-pipeline-btn">Next</button>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="tab-pipeline" role="tabpanel" aria-labelledby="tab-pipeline-btn">
          <div class="row g-3">
            <div class="col-12">
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-create-preset="called"><i class="bi bi-telephone me-1"></i>Called</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-create-preset="emailed"><i class="bi bi-envelope me-1"></i>Emailed</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-create-preset="linkedin"><i class="bi bi-linkedin me-1"></i>LinkedIn</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-create-preset="voicemail"><i class="bi bi-voicemail me-1"></i>No Answer</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-create-preset="meeting"><i class="bi bi-calendar-event me-1"></i>Meeting</button>
              </div>
              <div class="text-muted small mt-1">Applies status, follow-up time, and a notes template.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Lead Owner</label>
              <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" disabled>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sales Manager</label>
              <?php if ($isSdr || ($isManager && !$isDirector)): ?>
                <?php
                  $lockedManagerName = 'Unassigned';
                  foreach ($managers as $m) {
                      if ((int)$m['id'] === (int)$lockedManagerId) {
                          $lockedManagerName = (string)($m['full_name'] ?? 'Unassigned');
                          break;
                      }
                  }
                ?>
                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($lockedManagerName); ?>" disabled>
                <input type="hidden" name="sales_manager_id" value="<?php echo $lockedManagerId !== null ? (int)$lockedManagerId : ''; ?>">
              <?php else: ?>
                <select class="form-select form-select-sm" name="sales_manager_id">
                  <option value="">Unassigned</option>
                  <?php foreach ($managers as $m): ?>
                    <option value="<?php echo (int)$m['id']; ?>" <?php echo ((string)$form['sales_manager_id'] === (string)$m['id']) ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($m['full_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label">Lead Source</label>
              <select class="form-select form-select-sm" name="lead_source">
                <?php foreach ($sources as $s): ?>
                  <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $form['lead_source'] === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">Current Status</label>
              <select class="form-select form-select-sm" name="status">
                <?php foreach ($statuses as $s): ?>
                  <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $form['status'] === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Priority</label>
              <select class="form-select form-select-sm" name="priority">
                <?php foreach ($priorities as $p): ?>
                  <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $form['priority'] === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Next Follow-up (optional)</label>
              <input class="form-control form-control-sm" type="datetime-local" name="next_follow_up_at" value="<?php echo htmlspecialchars((string)($form['next_follow_up_at'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Expected Opportunity Size (optional)</label>
              <input class="form-control form-control-sm" name="expected_opportunity_size" value="<?php echo htmlspecialchars($form['expected_opportunity_size']); ?>" placeholder="10000">
            </div>
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <textarea class="form-control form-control-sm" name="notes" rows="3"><?php echo htmlspecialchars($form['notes']); ?></textarea>
            </div>
            <div class="col-12 d-flex justify-content-between">
              <button type="button" class="btn btn-light btn-sm" data-next-tab="#tab-contact-btn">Back</button>
              <div class="d-flex gap-2">
                <a href="leads.php" class="btn btn-light btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">Create Prospect</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card border-0 shadow-sm sales-panel sales-summary-card">
        <div class="card-header bg-light fw-semibold">Summary</div>
        <div class="card-body">
          <div class="text-muted small">Company</div>
          <div class="fw-semibold" id="sumCompany">—</div>
          <div class="text-muted small mt-2">Website</div>
          <div class="small" id="sumWebsite">—</div>
          <div class="text-muted small mt-2">Contact</div>
          <div class="small" id="sumContact">—</div>
          <div class="text-muted small mt-2">Status</div>
          <div class="small" id="sumStatus">—</div>
          <div class="text-muted small mt-2">Next Follow-up</div>
          <div class="small" id="sumFollow">—</div>
        </div>
      </div>

      <div class="card mt-3 d-none sales-panel" id="dupCard">
        <div class="card-header fw-semibold">Duplicate Check</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Company</th>
                <th>Status</th>
                <th>Website</th>
                <th>Email</th>
                <th>LinkedIn</th>
              </tr>
            </thead>
            <tbody id="dupBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
const form = document.getElementById('salesLeadForm');
const dupCard = document.getElementById('dupCard');
const dupBody = document.getElementById('dupBody');
const sumCompany = document.getElementById('sumCompany');
const sumWebsite = document.getElementById('sumWebsite');
const sumContact = document.getElementById('sumContact');
const sumStatus = document.getElementById('sumStatus');
const sumFollow = document.getElementById('sumFollow');

function toLocalInputFromDate(d) {
  if (!(d instanceof Date) || isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function applyCreatePreset(presetKey) {
  const presets = {
    called: { status: 'Contacted', followUpMinutes: 24 * 60, note: 'Called prospect. ' },
    emailed: { status: 'Follow-up Required', followUpMinutes: 2 * 24 * 60, note: 'Sent email. ' },
    linkedin: { status: 'Contacted', followUpMinutes: 2 * 24 * 60, note: 'Sent LinkedIn message. ' },
    voicemail: { status: 'Follow-up Required', followUpMinutes: 24 * 60, note: 'No answer. Left voicemail. ' },
    meeting: { status: 'Meeting Scheduled', followUpMinutes: null, note: 'Meeting scheduled. ' },
  };
  const p = presets[presetKey];
  if (!p) return;

  if (form.status && p.status) form.status.value = p.status;
  if (form.next_follow_up_at && p.followUpMinutes !== null) {
    const d = new Date();
    d.setMinutes(d.getMinutes() + Number(p.followUpMinutes || 0));
    form.next_follow_up_at.value = toLocalInputFromDate(d);
  }
  if (form.notes) {
    const existing = (form.notes.value || '').trim();
    form.notes.value = existing ? (p.note + existing) : p.note;
  }
  refreshSummary();
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-create-preset]');
  if (!btn) return;
  applyCreatePreset(btn.getAttribute('data-create-preset'));
});

function normalizeDomainInput(raw) {
  let s = String(raw || '').trim();
  if (!s) return '';
  s = s.replace(/^https?:\/\//i, '');
  s = s.replace(/^www\./i, '');
  s = s.replace(/[\/?#].*$/, '');
  s = s.replace(/\.$/, '');
  return s;
}

async function checkDup(){
  const params = new URLSearchParams({
    company_name: form.company_name.value || '',
    website: form.website.value || '',
    contact_email: form.contact_email.value || '',
    linkedin_url: form.linkedin_url.value || ''
  });
  const res = await fetch('check-duplicate?' + params.toString(), {headers: {'X-Requested-With':'XMLHttpRequest'}, credentials: 'same-origin'});
  const data = await res.json().catch(() => null);
  if(!res.ok || !data || !data.ok) return;
  const matches = data.matches || [];
  if(matches.length === 0){
    dupCard.classList.add('d-none');
    dupBody.innerHTML = '';
    return;
  }
  dupBody.innerHTML = matches.map(m => `
    <tr>
      <td class="fw-semibold"><a class="text-decoration-none" href="lead-view?id=${m.id}">${escapeHtml(m.company_name || '')}</a></td>
      <td><span class="badge bg-secondary">${escapeHtml(m.status || '')}</span></td>
      <td class="text-muted small">${escapeHtml(m.website || '')}</td>
      <td class="text-muted small">${escapeHtml(m.contact_email || '')}</td>
      <td class="text-muted small">${escapeHtml(m.linkedin_url || '')}</td>
    </tr>
  `).join('');
  dupCard.classList.remove('d-none');
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

['company_name','website','contact_email','linkedin_url'].forEach(name => {
  form[name]?.addEventListener('blur', () => checkDup());
});

function refreshSummary(){
  const company = (form.company_name?.value || '').trim();
  const website = (form.website?.value || '').trim();
  const contact = ((form.contact_name?.value || '').trim()) || ((form.contact_email?.value || '').trim());
  const status = (form.status?.value || '').trim();
  const follow = (form.next_follow_up_at?.value || '').trim();

  sumCompany.textContent = company || '—';
  sumWebsite.textContent = website || '—';
  sumContact.textContent = contact || '—';
  sumStatus.textContent = status || '—';
  sumFollow.textContent = follow ? follow.replace('T',' ') : '—';
}

form.addEventListener('input', refreshSummary);
refreshSummary();

form.website?.addEventListener('blur', () => {
  const cleaned = normalizeDomainInput(form.website.value);
  if (cleaned && cleaned !== form.website.value) {
    form.website.value = cleaned;
    refreshSummary();
  }
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-next-tab]');
  if (!btn) return;
  const target = btn.getAttribute('data-next-tab');
  const t = document.querySelector(target);
  if (t) t.click();
});
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
