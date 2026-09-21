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

$owners = getSalesOwnersBasic();
$managers = getSalesManagersBasic();

$scopeUserIds = getSalesScopeUserIdsForCurrentUser($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'assign_client') {
                if ($isSdr) throw new RuntimeException('Not allowed.');

                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId <= 0) throw new RuntimeException('Invalid client.');

                $newOwner = (int)($_POST['owner_id'] ?? 0);
                $newManager = ($_POST['manager_id'] ?? '') !== '' ? (int)$_POST['manager_id'] : null;

                if ($newOwner <= 0) throw new RuntimeException('Owner is required.');

                if ($isManager && !$isDirector) {
                    $newManager = $userId;
                    $allowed = is_array($scopeUserIds) ? array_values(array_filter(array_map('intval', $scopeUserIds), fn($v) => $v > 0)) : [];
                    if (empty($allowed) || !in_array((int)$newOwner, $allowed, true)) throw new RuntimeException('Owner not allowed.');
                }

                $stmt = $conn->prepare("SELECT id FROM clients WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $clientId);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if (!$exists) throw new RuntimeException('Client not found.');

                upsertSalesClientOwnership($clientId, $newOwner, $newManager, $userId, null);
                $success = 'Client assigned.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$show = trim((string)($_GET['show'] ?? 'my'));
if (!in_array($show, ['my','unassigned','all'], true)) $show = 'my';

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $where[] = "(c.client_code LIKE ? OR c.name LIKE ? OR c.website LIKE ? OR c.industry LIKE ?)";
    $like = '%'.$q.'%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if ($scopeUserIds !== null) {
    $in = implode(',', array_fill(0, count($scopeUserIds), '?'));
    if ($show === 'unassigned') {
        $where[] = "sco.client_id IS NULL";
    } else {
        $where[] = "(sco.owner_id IN ($in) OR sco.manager_id IN ($in))";
        $params = array_merge($params, $scopeUserIds, $scopeUserIds);
        $types .= str_repeat('i', count($scopeUserIds)) . str_repeat('i', count($scopeUserIds));
    }
} else {
    if ($show === 'unassigned') $where[] = "sco.client_id IS NULL";
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "SELECT
    c.id, c.client_code, c.name, c.website, c.industry, c.created_at,
    sco.owner_id, sco.manager_id, sco.assigned_at,
    ou.full_name AS owner_name, ou.role AS owner_role, ou.job_title AS owner_job_title,
    mu.full_name AS manager_name, mu.role AS manager_role, mu.job_title AS manager_job_title,
    (SELECT COUNT(*) FROM campaign_details d WHERE d.client_id = c.id) AS campaigns_total,
    (SELECT COUNT(*) FROM campaign_details d WHERE d.client_id = c.id AND d.status = 'Live') AS campaigns_live
  FROM clients c
  LEFT JOIN sales_client_ownership sco ON sco.client_id = c.id
  LEFT JOIN users ou ON ou.id = sco.owner_id
  LEFT JOIN users mu ON mu.id = sco.manager_id
  $whereSql
  ORDER BY c.created_at DESC
  LIMIT 300";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$unassignedCount = 0;
foreach ($rows as $r) { if (empty($r['owner_id'])) $unassignedCount++; }

$pageTitle = 'Accounts';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 sales-workspace">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-building"></i>Account Ownership</div>
      <h3 class="sales-page-title">Accounts</h3>
      <div class="sales-page-subtitle">Link clients to Sales owners and managers so account coverage, campaigns, and ownership changes stay visible.</div>
    </div>
    <div class="sales-page-actions">
      <a class="btn btn-outline-secondary btn-sm" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
      <a class="btn btn-primary btn-sm" href="account-create.php"><i class="bi bi-plus-circle me-1"></i>Create Account</a>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card border-0 shadow-sm mb-3 sales-toolbar sales-panel">
    <div class="card-body">
      <form class="row g-2 align-items-end sales-filter-form" method="get">
        <div class="col-md-5">
          <label class="form-label">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Client code, name, website, industry">
        </div>
        <div class="col-md-4">
          <label class="form-label">View</label>
          <select class="form-select form-select-sm" name="show">
            <option value="my" <?php echo $show === 'my' ? 'selected' : ''; ?>>My scope</option>
            <option value="unassigned" <?php echo $show === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
            <?php if ($scopeUserIds === null): ?>
              <option value="all" <?php echo $show === 'all' ? 'selected' : ''; ?>>All</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="col-md-3 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
        </div>
      </form>
      <div class="text-muted small mt-2">
        Total: <?php echo number_format(count($rows)); ?> · Unassigned: <?php echo number_format((int)$unassignedCount); ?>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm sales-panel">
    <div class="table-responsive sales-table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Client</th>
            <th>Owner</th>
            <th>Manager</th>
            <th class="text-end">Campaigns</th>
            <th class="text-end">Live</th>
            <th class="text-muted">Assigned</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No accounts found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $cid = (int)($r['id'] ?? 0);
                $canAssign = !$isSdr && ($isDirector || $isManager);
                $assignedAt = (string)($r['assigned_at'] ?? '');
              ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></div>
                  <div class="text-muted small"><?php echo htmlspecialchars((string)($r['client_code'] ?? '')); ?><?php echo !empty($r['industry']) ? ' · '.htmlspecialchars((string)$r['industry']) : ''; ?></div>
                </td>
                <td class="text-muted small">
                  <?php echo !empty($r['owner_name']) ? htmlspecialchars(formatUserNameWithRole((string)$r['owner_name'], (string)($r['owner_role'] ?? ''), (string)($r['owner_job_title'] ?? ''))) : '<span class="text-muted">—</span>'; ?>
                </td>
                <td class="text-muted small">
                  <?php echo !empty($r['manager_name']) ? htmlspecialchars(formatUserNameWithRole((string)$r['manager_name'], (string)($r['manager_role'] ?? ''), (string)($r['manager_job_title'] ?? ''))) : '<span class="text-muted">—</span>'; ?>
                </td>
                <td class="text-end font-monospace"><?php echo number_format((int)($r['campaigns_total'] ?? 0)); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($r['campaigns_live'] ?? 0)); ?></td>
                <td class="text-muted small"><?php echo $assignedAt ? htmlspecialchars(date('d M Y, H:i', strtotime($assignedAt))) : '—'; ?></td>
                <td class="text-end">
                  <?php if ($canAssign): ?>
                    <button type="button" class="btn btn-sm btn-light border"
                      data-bs-toggle="modal"
                      data-bs-target="#assignModal"
                      data-client-id="<?php echo $cid; ?>"
                      data-client-code="<?php echo htmlspecialchars((string)($r['client_code'] ?? '')); ?>"
                      data-client-name="<?php echo htmlspecialchars((string)($r['name'] ?? '')); ?>"
                      data-owner-id="<?php echo (int)($r['owner_id'] ?? 0); ?>"
                      data-manager-id="<?php echo (int)($r['manager_id'] ?? 0); ?>">
                      <i class="bi bi-person-check"></i>
                    </button>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <div class="modal-title fw-semibold">Assign Account</div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="assign_client">
          <input type="hidden" name="client_id" id="assign_client_id" value="">
          <div class="mb-2">
            <div class="fw-semibold" id="assign_title">—</div>
            <div class="text-muted small" id="assign_subtitle">—</div>
          </div>
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label">Owner</label>
              <select class="form-select form-select-sm" name="owner_id" id="assign_owner_id" required>
                <option value="">Select owner</option>
                <?php foreach ($owners as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars(formatUserNameWithRole((string)$o['full_name'], (string)$o['role'], (string)($o['job_title'] ?? ''))); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Manager</label>
              <select class="form-select form-select-sm" name="manager_id" id="assign_manager_id" <?php echo ($isManager && !$isDirector) ? 'disabled' : ''; ?>>
                <option value="">Unassigned</option>
                <?php foreach ($managers as $m): ?>
                  <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars(formatUserNameWithRole((string)$m['full_name'], (string)$m['role'], (string)($m['job_title'] ?? ''))); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($isManager && !$isDirector): ?>
                <div class="text-muted small mt-1">Manager is set to you.</div>
                <input type="hidden" name="manager_id" value="<?php echo (int)$userId; ?>">
              <?php endif; ?>
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
document.getElementById('assignModal').addEventListener('show.bs.modal', (e) => {
  const btn = e.relatedTarget;
  if (!btn) return;
  const id = btn.getAttribute('data-client-id') || '';
  const code = btn.getAttribute('data-client-code') || '';
  const name = btn.getAttribute('data-client-name') || '';
  const ownerId = btn.getAttribute('data-owner-id') || '';
  const managerId = btn.getAttribute('data-manager-id') || '';

  document.getElementById('assign_client_id').value = id;
  document.getElementById('assign_title').textContent = name || 'Client';
  document.getElementById('assign_subtitle').textContent = code ? code : '';

  const ownerSel = document.getElementById('assign_owner_id');
  if (ownerSel) ownerSel.value = ownerId && ownerId !== '0' ? ownerId : '';

  const mgrSel = document.getElementById('assign_manager_id');
  if (mgrSel && !mgrSel.disabled) mgrSel.value = managerId && managerId !== '0' ? managerId : '';
});
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
