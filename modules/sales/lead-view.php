<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr','admin']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$isAdmin = isAdmin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: leads'); exit; }

$lead = getSalesLeadById($id);
if (!$lead) { header('Location: leads'); exit; }

$isSdr = isSDR();
$isManager = isSalesManager();
$isDirector = isSalesDirector() || $isAdmin;
$lockedManagerId = null;
if ($isSdr) {
    $lockedManagerId = getSalesManagerForSdr($userId);
} elseif ($isManager && !$isDirector) {
    $lockedManagerId = $userId;
}

$canView = false;
if ($isDirector) {
    $canView = true;
} elseif ($isSdr) {
    $canView = ((int)$lead['owner_id'] === $userId);
} elseif ($isManager) {
    if ((int)$lead['owner_id'] === $userId) {
        $canView = true;
    } else {
        $ownerId = (int)$lead['owner_id'];
        $canView = (getSalesManagerForSdr($ownerId) === $userId);
    }
}
if (!$canView) { header('Location: leads'); exit; }

$canEdit = $isDirector || ($isManager && $canView) || ($isSdr && ((int)$lead['owner_id'] === $userId));
$editMode = $canEdit && ((string)($_GET['edit'] ?? '') === '1');

$error = '';
$success = '';
$dupMatches = [];
$clientDupMatches = [];

$statuses = ['New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];
$priorities = ['Low','Normal','High','Urgent'];
$sources = ['Manual Outreach','LinkedIn','Referral','Event','Website','Other'];

$managers = [];
$mgrRs = $conn->query("SELECT id, full_name FROM users WHERE role IN ('sales_manager','sales_director') ORDER BY full_name");
if ($mgrRs) $managers = $mgrRs->fetch_all(MYSQLI_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'convert_to_client') {
                if (!($isDirector || $isManager)) {
                    throw new RuntimeException('Not allowed.');
                }
                if (($lead['status'] ?? '') !== 'Closed Won') {
                    throw new RuntimeException('Prospect must be Closed Won before conversion.');
                }
                if (!empty($lead['client_id'])) {
                    $success = 'Already converted.';
                } else {
                    try {
                        $clientId = convertSalesLeadToClient($id, $userId);
                        header('Location: ../clients/clients?client_id='.$clientId);
                        exit;
                    } catch (Throwable $e) {
                        $clientDupMatches = findDuplicateClientsByNameOrDomain((string)($lead['company_name'] ?? ''), (string)($lead['website'] ?? ''));
                        throw $e;
                    }
                }
            }

            if (!$canEdit) {
                throw new RuntimeException('Not allowed.');
            }

            if ($action === 'update_lead') {
                $payload = [
                    'company_name' => trim((string)($_POST['company_name'] ?? '')),
                    'website' => trim((string)($_POST['website'] ?? '')),
                    'industry' => trim((string)($_POST['industry'] ?? '')),
                    'company_size' => trim((string)($_POST['company_size'] ?? '')),
                    'country' => trim((string)($_POST['country'] ?? '')),
                    'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
                    'contact_job_title' => trim((string)($_POST['contact_job_title'] ?? '')),
                    'contact_email' => trim((string)($_POST['contact_email'] ?? '')),
                    'contact_phone' => trim((string)($_POST['contact_phone'] ?? '')),
                    'linkedin_url' => trim((string)($_POST['linkedin_url'] ?? '')),
                    'sales_manager_id' => trim((string)($_POST['sales_manager_id'] ?? '')),
                    'lead_source' => trim((string)($_POST['lead_source'] ?? 'Manual Outreach')),
                    'priority' => trim((string)($_POST['priority'] ?? 'Normal')),
                    'expected_opportunity_size' => trim((string)($_POST['expected_opportunity_size'] ?? '')),
                    'notes' => trim((string)($_POST['notes'] ?? '')),
                ];
                if ($payload['company_name'] === '') throw new RuntimeException('Company Name is required');
                if (!in_array($payload['lead_source'], $sources, true)) $payload['lead_source'] = 'Manual Outreach';
                if (!in_array($payload['priority'], $priorities, true)) $payload['priority'] = 'Normal';

                $dupMatches = findDuplicateSalesLeads(
                    [
                        'company_name' => $payload['company_name'],
                        'website' => $payload['website'],
                        'contact_email' => $payload['contact_email'],
                        'linkedin_url' => $payload['linkedin_url'],
                    ],
                    $id,
                    10
                );
                if (!empty($dupMatches)) {
                    throw new RuntimeException('Possible duplicate prospect found. Resolve duplicates before saving changes.');
                }

                updateSalesLead($id, $payload, $userId);
                $success = 'Prospect updated.';
            } elseif ($action === 'add_activity') {
                $newStatus = trim((string)($_POST['status'] ?? ''));
                if (!in_array($newStatus, $statuses, true)) $newStatus = (string)($lead['status'] ?? 'New');
                $comment = trim((string)($_POST['comment'] ?? ''));
                if ($comment === '') throw new RuntimeException('Comment is required');

                $nfRaw = trim((string)($_POST['next_follow_up_at'] ?? ''));
                $nf = null;
                if ($nfRaw !== '') {
                    $nfRaw = str_replace('T', ' ', $nfRaw);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $nfRaw)) $nfRaw .= ':00';
                    $ts = strtotime($nfRaw);
                    if ($ts !== false) $nf = date('Y-m-d H:i:s', $ts);
                }

                addSalesLeadActivity($id, $newStatus, $comment, $userId, $nf);
                updateSalesLead($id, ['status' => $newStatus], $userId);
                $success = 'Activity added.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $lead = getSalesLeadById($id) ?: $lead;
    }
}

$activities = getSalesLeadActivities($id, 200);
?>

<?php $pageTitle = 'Prospect'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 sales-workspace">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-buildings"></i>Prospect Record</div>
      <h3 class="sales-page-title"><?php echo htmlspecialchars($lead['company_name'] ?? 'Prospect'); ?></h3>
      <div class="sales-page-subtitle">
        <span class="badge bg-secondary"><?php echo htmlspecialchars($lead['status'] ?? ''); ?></span>
        <span class="ms-2">Owner: <?php echo htmlspecialchars(formatUserNameWithRole(($lead['owner_name'] ?? ''), ($lead['owner_role'] ?? ''), ($lead['owner_job_title'] ?? ''))); ?></span>
        <?php
          $nfa = (string)($lead['next_follow_up_at'] ?? '');
          $lta = (string)($lead['last_activity_at'] ?? '');
          $isClosed = in_array((string)($lead['status'] ?? ''), ['Closed Won','Closed Lost'], true);
          $overdue = (!$isClosed && $nfa !== '' && strtotime($nfa) !== false && strtotime($nfa) < time());
        ?>
        <?php if ($nfa !== ''): ?>
          <span class="ms-2">
            Next follow-up:
            <span class="badge border <?php echo $overdue ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning'; ?>">
              <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($nfa))); ?>
            </span>
          </span>
        <?php endif; ?>
        <?php if ($lta !== ''): ?>
          <span class="ms-2">Last touch: <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($lta))); ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="sales-page-actions">
      <a href="leads.php" class="btn btn-outline-primary btn-sm">Back to Pipeline</a>
      <?php if (!empty($lead['client_id'])): ?>
        <a href="../clients/clients.php?client_id=<?php echo (int)$lead['client_id']; ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-building me-1"></i>View Client</a>
      <?php elseif (($lead['status'] ?? '') === 'Closed Won' && ($isDirector || $isManager)): ?>
        <form method="post" class="m-0">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="convert_to_client">
          <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Convert to Client</button>
        </form>
      <?php endif; ?>
      <a href="lead-create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Prospect</a>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <?php if (!empty($clientDupMatches)): ?>
    <div class="card mb-3 sales-panel border-0 shadow-sm">
      <div class="card-header fw-semibold">Possible Duplicate Clients</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Client</th>
              <th>Code</th>
              <th>Website</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($clientDupMatches as $c): ?>
              <tr>
                <td class="fw-semibold"><a class="text-decoration-none" href="../clients/clients.php?client_id=<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name'] ?? ''); ?></a></td>
                <td class="text-muted small"><?php echo htmlspecialchars($c['client_code'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($c['website'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($dupMatches)): ?>
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
            <?php foreach ($dupMatches as $d): ?>
              <tr>
                <td class="fw-semibold"><a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['company_name'] ?? ''); ?></a></td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($d['status'] ?? ''); ?></span></td>
                <td class="text-muted small"><?php echo htmlspecialchars($d['website'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($d['contact_email'] ?? ''); ?></td>
                <td class="text-muted small">
                  <?php $li = trim((string)($d['linkedin_url'] ?? '')); ?>
                  <?php if ($li !== ''): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($li); ?>" target="_blank" rel="noopener noreferrer">
                      <i class="bi bi-linkedin me-1"></i>Open
                    </a>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card sales-panel border-0 shadow-sm" id="prospectDetails">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span>Prospect Details</span>
          <?php if ($canEdit): ?>
            <?php if ($editMode): ?>
              <a class="btn btn-outline-secondary btn-sm" href="lead-view.php?id=<?php echo (int)$id; ?>#prospectDetails"><i class="bi bi-x-lg me-1"></i>Cancel</a>
            <?php else: ?>
              <a class="btn btn-outline-primary btn-sm" href="lead-view.php?id=<?php echo (int)$id; ?>&edit=1#prospectDetails"><i class="bi bi-pencil-square me-1"></i>Edit</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($editMode): ?>
            <form method="post" class="row g-3 sales-filter-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="update_lead">

              <div class="col-12"><div class="fw-semibold">Company Information</div></div>
              <div class="col-md-6">
                <label class="form-label">Company Name</label>
                <input class="form-control form-control-sm" name="company_name" value="<?php echo htmlspecialchars($lead['company_name'] ?? ''); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Company Website / Domain</label>
                <input class="form-control form-control-sm" name="website" value="<?php echo htmlspecialchars($lead['website'] ?? ''); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Industry</label>
                <input class="form-control form-control-sm" name="industry" value="<?php echo htmlspecialchars($lead['industry'] ?? ''); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Company Size</label>
                <input class="form-control form-control-sm" name="company_size" value="<?php echo htmlspecialchars($lead['company_size'] ?? ''); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Country</label>
                <input class="form-control form-control-sm" name="country" value="<?php echo htmlspecialchars($lead['country'] ?? ''); ?>">
              </div>

              <div class="col-12 mt-2"><div class="fw-semibold">Primary Contact</div></div>
              <div class="col-md-4">
                <label class="form-label">Contact Person Name</label>
                <input class="form-control form-control-sm" name="contact_name" value="<?php echo htmlspecialchars($lead['contact_name'] ?? ''); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Job Title</label>
                <input class="form-control form-control-sm" name="contact_job_title" value="<?php echo htmlspecialchars($lead['contact_job_title'] ?? ''); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">LinkedIn Profile</label>
                <input class="form-control form-control-sm" name="linkedin_url" value="<?php echo htmlspecialchars($lead['linkedin_url'] ?? ''); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Email</label>
                <input class="form-control form-control-sm" name="contact_email" value="<?php echo htmlspecialchars($lead['contact_email'] ?? ''); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input class="form-control form-control-sm" name="contact_phone" value="<?php echo htmlspecialchars($lead['contact_phone'] ?? ''); ?>">
              </div>

              <div class="col-12 mt-2"><div class="fw-semibold">Sales Assignment</div></div>
              <div class="col-md-4">
                <label class="form-label">Lead Owner</label>
                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($lead['owner_name'] ?? ''); ?>" disabled>
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
                      <option value="<?php echo (int)$m['id']; ?>" <?php echo ((string)($lead['sales_manager_id'] ?? '') === (string)$m['id']) ? 'selected' : ''; ?>>
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
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo (($lead['lead_source'] ?? '') === $s) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 mt-2"><div class="fw-semibold">Pipeline Information</div></div>
              <div class="col-md-4">
                <label class="form-label">Priority</label>
                <select class="form-select form-select-sm" name="priority">
                  <?php foreach ($priorities as $p): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>" <?php echo (($lead['priority'] ?? '') === $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Expected Opportunity Size</label>
                <input class="form-control form-control-sm" name="expected_opportunity_size" value="<?php echo htmlspecialchars((string)($lead['expected_opportunity_size'] ?? '')); ?>">
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control form-control-sm" name="notes" rows="3"><?php echo htmlspecialchars((string)($lead['notes'] ?? '')); ?></textarea>
              </div>

              <div class="col-12 d-flex justify-content-end">
                <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
              </div>
            </form>
          <?php else: ?>
            <div class="row g-2">
              <div class="col-12"><div class="fw-semibold">Company Information</div></div>
              <div class="col-md-6">
                <div class="text-muted small">Company Name</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['company_name'] ?? '')); ?></div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">Company Website / Domain</div>
                <?php $w = trim((string)($lead['website'] ?? '')); ?>
                <?php if ($w !== ''): ?>
                  <a class="text-decoration-none fw-semibold" href="<?php echo htmlspecialchars(str_starts_with($w, 'http') ? $w : ('https://' . ltrim($w, '/'))); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($w); ?></a>
                <?php else: ?>
                  <div class="text-muted">—</div>
                <?php endif; ?>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">Industry</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['industry'] ?? '—')); ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">Company Size</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['company_size'] ?? '—')); ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">Country</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['country'] ?? '—')); ?></div>
              </div>

              <div class="col-12 mt-2"><div class="fw-semibold">Primary Contact</div></div>
              <div class="col-md-4">
                <div class="text-muted small">Contact Person Name</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['contact_name'] ?? '—')); ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">Job Title</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['contact_job_title'] ?? '—')); ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">LinkedIn Profile</div>
                <?php $li = trim((string)($lead['linkedin_url'] ?? '')); ?>
                <?php if ($li !== ''): ?>
                  <a class="text-decoration-none fw-semibold" href="<?php echo htmlspecialchars($li); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($li); ?></a>
                <?php else: ?>
                  <div class="text-muted">—</div>
                <?php endif; ?>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">Email</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['contact_email'] ?? '—')); ?></div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">Phone</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['contact_phone'] ?? '—')); ?></div>
              </div>

              <div class="col-12 mt-2"><div class="fw-semibold">Sales Assignment</div></div>
              <div class="col-md-4">
                <div class="text-muted small">Lead Owner</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['owner_name'] ?? '—')); ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">Sales Manager</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['manager_name'] ?? '—')); ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">Lead Source</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['lead_source'] ?? '—')); ?></div>
              </div>

              <div class="col-12 mt-2"><div class="fw-semibold">Pipeline Information</div></div>
              <div class="col-md-4">
                <div class="text-muted small">Priority</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['priority'] ?? '—')); ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted small">Expected Opportunity Size</div>
                <div class="fw-semibold"><?php echo htmlspecialchars((string)($lead['expected_opportunity_size'] ?? '—')); ?></div>
              </div>
              <div class="col-12">
                <div class="text-muted small">Notes</div>
                <div class="fw-semibold" style="white-space:pre-wrap;"><?php echo htmlspecialchars((string)($lead['notes'] ?? '—')); ?></div>
              </div>
            </div>
          <?php endif; ?>

          <div class="mt-3">
            <div class="text-muted small">
              Created: <?php echo htmlspecialchars($lead['created_at'] ?? ''); ?> · Updated: <?php echo htmlspecialchars($lead['updated_at'] ?? ''); ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-3 sales-panel border-0 shadow-sm">
        <div class="card-header fw-semibold">Add Communication Note</div>
        <div class="card-body">
          <?php if (!$canEdit): ?>
            <div class="text-muted">You can view the timeline, but you cannot update this prospect.</div>
          <?php else: ?>
            <form method="post" class="row g-2">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="add_activity">
              <div class="col-12">
                <label class="form-label">Current Status</label>
                <select class="form-select form-select-sm" name="status">
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo (($lead['status'] ?? '') === $s) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Next Follow-up (optional)</label>
                <input class="form-control form-control-sm" type="datetime-local" name="next_follow_up_at" value="<?php echo !empty($lead['next_follow_up_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$lead['next_follow_up_at']))) : ''; ?>">
              </div>
              <div class="col-12">
                <label class="form-label">Comment</label>
                <textarea class="form-control form-control-sm" name="comment" rows="4" placeholder="E.g., Contacted via LinkedIn, sent email, meeting scheduled..." required></textarea>
              </div>
              <div class="col-12 d-grid">
                <button class="btn btn-primary btn-sm" type="submit">Add to Timeline</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="card sales-panel border-0 shadow-sm">
        <div class="card-header fw-semibold">Communication Timeline</div>
        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>When</th>
                <th>Status</th>
                <th>Comment</th>
                <th>By</th>
                <?php if ($canEdit): ?><th class="text-end">Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($activities)): ?>
                <tr><td colspan="<?php echo $canEdit ? 5 : 4; ?>" class="text-center text-muted py-3">No notes yet.</td></tr>
              <?php else: ?>
                <?php foreach ($activities as $a): ?>
                  <tr>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($a['created_at']))); ?></td>
                    <td><?php echo $a['status'] ? '<span class="badge bg-secondary">'.htmlspecialchars($a['status']).'</span>' : '<span class="text-muted">—</span>'; ?></td>
                    <td class="small">
                      <?php echo nl2br(htmlspecialchars((string)($a['comment'] ?? ''))); ?>
                      <?php if (!empty($a['updated_at'])): ?>
                        <div class="text-muted small mt-1">
                          Edited <?php echo htmlspecialchars(date('d M Y, H:i', strtotime((string)$a['updated_at']))); ?>
                          <?php if (!empty($a['updated_by_name'])): ?>
                            · <?php echo htmlspecialchars(formatUserNameWithRole(($a['updated_by_name'] ?? ''), ($a['updated_by_role'] ?? ''), ($a['updated_by_job_title'] ?? ''))); ?>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($a['created_by_name'] ?? ''), ($a['created_by_role'] ?? ''), ($a['created_by_job_title'] ?? ''))); ?></td>
                    <?php if ($canEdit): ?>
                      <td class="text-end">
                        <button type="button"
                            class="btn btn-sm btn-light border"
                            data-edit-activity="1"
                            data-activity-id="<?php echo (int)$a['id']; ?>"
                            data-status="<?php echo htmlspecialchars((string)($a['status'] ?? '')); ?>"
                            data-comment="<?php echo htmlspecialchars((string)($a['comment'] ?? '')); ?>"
                            title="Edit note">
                          <i class="bi bi-pencil"></i>
                        </button>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="editActivityModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title fw-semibold">Edit Note</div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="editActivityForm" class="row g-2">
          <input type="hidden" name="activity_id" value="">
          <div class="col-12">
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" name="status" required>
              <?php foreach ($statuses as $s): ?>
                <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Next Follow-up (optional)</label>
            <input class="form-control form-control-sm" type="datetime-local" name="next_follow_up_at" value="<?php echo !empty($lead['next_follow_up_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$lead['next_follow_up_at']))) : ''; ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Comment</label>
            <textarea class="form-control form-control-sm" rows="4" name="comment" required></textarea>
          </div>
        </form>
        <div class="alert alert-danger d-none mt-2" id="editActivityError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="editActivitySave">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
const editToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
const editModalEl = document.getElementById('editActivityModal');
const editForm = document.getElementById('editActivityForm');
const editError = document.getElementById('editActivityError');
const editSave = document.getElementById('editActivitySave');

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-edit-activity]');
  if (!btn) return;
  editError.classList.add('d-none');
  editError.textContent = '';
  editForm.activity_id.value = btn.getAttribute('data-activity-id') || '';
  editForm.status.value = btn.getAttribute('data-status') || 'New';
  editForm.comment.value = btn.getAttribute('data-comment') || '';
  bootstrap.Modal.getOrCreateInstance(editModalEl).show();
});

editSave.addEventListener('click', async () => {
  editError.classList.add('d-none');
  const activityId = editForm.activity_id.value;
  const status = editForm.status.value;
  const comment = editForm.comment.value.trim();
  const nextFollowUpAt = editForm.next_follow_up_at.value;
  if (!activityId) return;
  if (!comment) {
    editError.textContent = 'Comment is required.';
    editError.classList.remove('d-none');
    return;
  }

  const payload = new URLSearchParams();
  payload.set('csrf_token', editToken);
  payload.set('action', 'edit_activity');
  payload.set('activity_id', activityId);
  payload.set('status', status);
  payload.set('comment', comment);
  if (nextFollowUpAt) payload.set('next_follow_up_at', nextFollowUpAt);

  editSave.disabled = true;
  try {
    const res = await fetch('activity', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: payload.toString()
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) throw new Error((data && data.error) ? data.error : 'Failed to save');
    window.location.reload();
  } catch (err) {
    editError.textContent = err.message || 'Failed to save';
    editError.classList.remove('d-none');
  } finally {
    editSave.disabled = false;
  }
});
</script>
<?php endif; ?>
<?php if (!empty($_GET['edit'])): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('prospectDetails');
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  const first = document.querySelector('#prospectDetails [name="company_name"]');
  if (first && !first.disabled) first.focus();
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
