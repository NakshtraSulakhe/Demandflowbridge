<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','admin']);
ensureCsrfToken();

$conn = getDbConnection();
$message = '';
$messageType = '';

$managers = [];
$rs = $conn->query("SELECT id, full_name, role, job_title FROM users WHERE role = 'sales_manager' ORDER BY full_name");
if ($rs) $managers = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$sdrs = [];
$rs = $conn->query("SELECT id, full_name, role, job_title FROM users WHERE role = 'sdr' ORDER BY full_name");
if ($rs) $sdrs = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$loadMap = function() use ($conn): array {
    $map = [];
    $rs = $conn->query("SELECT sm.manager_user_id, sm.sdr_user_id
        FROM sales_manager_sdr_map sm
        JOIN (
            SELECT sdr_user_id, MAX(id) AS max_id
            FROM sales_manager_sdr_map
            GROUP BY sdr_user_id
        ) latest ON latest.max_id = sm.id");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $mId = (int)$r['manager_user_id'];
            $sId = (int)$r['sdr_user_id'];
            if (!isset($map[$mId])) $map[$mId] = [];
            $map[$mId][$sId] = true;
        }
    }
    return $map;
};

$map = $loadMap();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $managerId = (int)($_POST['manager_user_id'] ?? 0);
        $selected = $_POST['sdr_user_ids'] ?? [];
        if ($managerId <= 0) {
            $message = 'Select a Sales Manager.';
            $messageType = 'danger';
        } else {
            $selectedIds = [];
            if (is_array($selected)) {
                foreach ($selected as $sid) {
                    $sdrId = (int)$sid;
                    if ($sdrId > 0) $selectedIds[$sdrId] = $sdrId;
                }
            }

            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare("DELETE FROM sales_manager_sdr_map WHERE manager_user_id = ?");
                if (!$stmt) throw new RuntimeException('Failed to update mapping.');
                $stmt->bind_param('i', $managerId);
                $stmt->execute();
                $stmt->close();

                if (!empty($selectedIds)) {
                    $in = implode(',', array_fill(0, count($selectedIds), '?'));
                    $stmt = $conn->prepare("DELETE FROM sales_manager_sdr_map WHERE sdr_user_id IN ($in) AND manager_user_id <> ?");
                    if (!$stmt) throw new RuntimeException('Failed to update mapping.');
                    mysqliBindParams($stmt, str_repeat('i', count($selectedIds)) . 'i', array_merge(array_values($selectedIds), [$managerId]));
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("INSERT INTO sales_manager_sdr_map (manager_user_id, sdr_user_id, created_at) VALUES (?,?,NOW())");
                    if (!$stmt) throw new RuntimeException('Failed to update mapping.');
                    foreach ($selectedIds as $sdrId) {
                        $stmt->bind_param('ii', $managerId, $sdrId);
                        $stmt->execute();
                    }
                    $stmt->close();
                }

                $conn->commit();
                $message = 'Mapping updated.';
                $messageType = 'success';
                $map = $loadMap();
            } catch (Throwable $e) {
                $conn->rollback();
                $message = 'Failed to update mapping.';
                $messageType = 'danger';
            }
        }
    }
}
?>

<?php $pageTitle = 'Manager ↔ SDR Mapping'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 sales-workspace">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-diagram-3"></i>Team Structure</div>
      <h3 class="sales-page-title">Manager ↔ SDR Mapping</h3>
      <div class="sales-page-subtitle">Maintain clean reporting lines so dashboards, ownership scope, and productivity views reflect the correct team structure.</div>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType ?: 'info'); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <?php if (empty($managers)): ?>
      <div class="col-12">
        <div class="alert alert-warning mb-0">No Sales Managers found. Create Sales Manager users first.</div>
      </div>
    <?php else: ?>
      <?php foreach ($managers as $m): ?>
        <?php $mId = (int)$m['id']; ?>
        <div class="col-lg-6">
          <div class="card h-100 sales-panel border-0 shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="fw-semibold"><?php echo htmlspecialchars(formatUserNameWithRole(($m['full_name'] ?? ''), ($m['role'] ?? 'sales_manager'), ($m['job_title'] ?? ''))); ?></div>
              <span class="badge bg-secondary"><?php echo isset($map[$mId]) ? count($map[$mId]) : 0; ?> SDRs</span>
            </div>
            <div class="card-body">
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="manager_user_id" value="<?php echo $mId; ?>">
                <div class="row g-2">
                  <?php if (empty($sdrs)): ?>
                    <div class="col-12 text-muted">No SDR users found.</div>
                  <?php else: ?>
                    <?php foreach ($sdrs as $s): ?>
                      <?php $sId = (int)$s['id']; ?>
                      <div class="col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="sdr_user_ids[]" value="<?php echo $sId; ?>" id="m<?php echo $mId; ?>_s<?php echo $sId; ?>" <?php echo (!empty($map[$mId][$sId])) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="m<?php echo $mId; ?>_s<?php echo $sId; ?>">
                            <?php echo htmlspecialchars(formatUserNameWithRole(($s['full_name'] ?? ''), ($s['role'] ?? 'sdr'), ($s['job_title'] ?? ''))); ?>
                          </label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="mt-3 d-flex justify-content-end">
                  <button class="btn btn-primary btn-sm" type="submit">Save Mapping</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
