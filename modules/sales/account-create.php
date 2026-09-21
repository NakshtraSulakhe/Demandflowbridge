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

$owners = getSalesOwnersBasic();
$managers = getSalesManagersBasic();

$error = '';

$form = [
    'name' => '',
    'website' => '',
    'industry' => '',
    'notes' => '',
    'owner_id' => $userId,
    'manager_id' => $isManager && !$isDirector ? $userId : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name'] = trim((string)($_POST['name'] ?? ''));
    $form['website'] = trim((string)($_POST['website'] ?? ''));
    $form['industry'] = trim((string)($_POST['industry'] ?? ''));
    $form['notes'] = trim((string)($_POST['notes'] ?? ''));
    $form['owner_id'] = (int)($_POST['owner_id'] ?? $userId);
    $form['manager_id'] = trim((string)($_POST['manager_id'] ?? ''));

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        try {
            if ($form['name'] === '') throw new RuntimeException('Client name is required.');
            $dups = findDuplicateClientsByNameOrDomain($form['name'], $form['website']);
            if (!empty($dups)) throw new RuntimeException('Duplicate client found. Please open existing client.');

            $clientCode = generateClientCode($form['name']);
            $websiteDomain = extractDomain($form['website']);
            $createdBy = $userId;

            $stmt = $conn->prepare("INSERT INTO clients (client_code, name, website, website_domain, industry, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
            $stmt->bind_param('ssssssi', $clientCode, $form['name'], $form['website'], $websiteDomain, $form['industry'], $form['notes'], $createdBy);
            if (!$stmt->execute()) throw new RuntimeException('Failed to create client.');
            $clientId = (int)$conn->insert_id;
            $stmt->close();

            $ownerId = (int)$form['owner_id'];
            $managerId = $form['manager_id'] !== '' ? (int)$form['manager_id'] : null;

            if ($isSdr) {
                $ownerId = $userId;
                $managerId = null;
            } elseif ($isManager && !$isDirector) {
                $managerId = $userId;
                $allowedOwners = [$userId => true];
                foreach (getCurrentSalesSdrIdsForManager($userId) as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) $allowedOwners[$sid] = true;
                }
                if (!isset($allowedOwners[$ownerId])) $ownerId = $userId;
            }

            upsertSalesClientOwnership($clientId, $ownerId, $managerId, $userId, null);

            header('Location: accounts');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Account';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 sales-workspace">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-building-add"></i>Account Setup</div>
      <h3 class="sales-page-title">Create Account</h3>
      <div class="sales-page-subtitle">Create a client record and assign the right commercial ownership from day one.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="accounts.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <div class="card border-0 shadow-sm sales-panel">
    <div class="card-body">
      <form method="post" class="row g-3 sales-filter-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="col-md-6">
          <label class="form-label">Client Name</label>
          <input class="form-control form-control-sm" name="name" value="<?php echo htmlspecialchars($form['name']); ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Website</label>
          <input class="form-control form-control-sm" name="website" value="<?php echo htmlspecialchars($form['website']); ?>" placeholder="example.com">
        </div>
        <div class="col-md-6">
          <label class="form-label">Industry</label>
          <input class="form-control form-control-sm" name="industry" value="<?php echo htmlspecialchars($form['industry']); ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Notes</label>
          <textarea class="form-control form-control-sm" name="notes" rows="2"><?php echo htmlspecialchars($form['notes']); ?></textarea>
        </div>

        <div class="col-12"><hr class="my-1"></div>

        <div class="col-md-6">
          <label class="form-label">Owner</label>
          <select class="form-select form-select-sm" name="owner_id" <?php echo $isSdr ? 'disabled' : ''; ?>>
            <?php foreach ($owners as $o): ?>
              <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$form['owner_id'] === (int)$o['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(formatUserNameWithRole((string)$o['full_name'], (string)$o['role'], (string)($o['job_title'] ?? ''))); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($isSdr): ?>
            <input type="hidden" name="owner_id" value="<?php echo (int)$userId; ?>">
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">Manager</label>
          <select class="form-select form-select-sm" name="manager_id" <?php echo ($isManager && !$isDirector) ? 'disabled' : ''; ?>>
            <option value="">Unassigned</option>
            <?php foreach ($managers as $m): ?>
              <option value="<?php echo (int)$m['id']; ?>" <?php echo ((string)$form['manager_id'] === (string)$m['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(formatUserNameWithRole((string)$m['full_name'], (string)$m['role'], (string)($m['job_title'] ?? ''))); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($isManager && !$isDirector): ?>
            <div class="text-muted small mt-1">Manager is set to you.</div>
            <input type="hidden" name="manager_id" value="<?php echo (int)$userId; ?>">
          <?php endif; ?>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2">
          <a class="btn btn-light btn-sm" href="accounts.php">Cancel</a>
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i>Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
