<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('leads.purge.page', []);
ensureCsrfToken();

$conn = getDbConnection();

$counts = [
    'leads' => 0,
    'form_submissions' => 0,
    'lead_files' => 0,
    'lead_activity' => 0,
    'campaign_tables' => 0,
];
foreach (array_keys($counts) as $t) {
    if ($t === 'campaign_tables') continue;
    $rs = $conn->query("SELECT COUNT(*) AS c FROM `$t`");
    if ($rs) {
        $row = $rs->fetch_assoc() ?: ['c' => 0];
        $counts[$t] = (int)($row['c'] ?? 0);
    }
}
$campaignTables = [];
$rsT = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'leads\\_%'");
if ($rsT) {
    while ($r = $rsT->fetch_assoc()) {
        $tn = (string)($r['TABLE_NAME'] ?? '');
        if ($tn !== '' && preg_match('/^leads_[A-Za-z0-9_]+$/', $tn)) $campaignTables[] = $tn;
    }
}
foreach ($campaignTables as $tn) {
    $rs = $conn->query("SELECT COUNT(*) AS c FROM `$tn`");
    if ($rs) {
        $row = $rs->fetch_assoc() ?: ['c' => 0];
        $counts['campaign_tables'] += (int)($row['c'] ?? 0);
    }
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
        if ($confirm !== 'DELETE ALL') {
            $message = 'Type DELETE ALL to confirm.';
            $messageType = 'danger';
        } else {
            $deleteFiles = !empty($_POST['delete_files']);
            $paths = [];
            if ($deleteFiles) {
                $rs = $conn->query("SELECT recording_path FROM leads WHERE recording_path IS NOT NULL AND recording_path <> ''");
                if ($rs) {
                    while ($r = $rs->fetch_assoc()) {
                        $p = (string)($r['recording_path'] ?? '');
                        if ($p !== '') $paths[] = $p;
                    }
                }
                $rs = $conn->query("SELECT file_path FROM lead_files WHERE file_path IS NOT NULL AND file_path <> ''");
                if ($rs) {
                    while ($r = $rs->fetch_assoc()) {
                        $p = (string)($r['file_path'] ?? '');
                        if ($p !== '') $paths[] = $p;
                    }
                }
            }

            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM lead_activity");
                $conn->query("DELETE FROM lead_files");
                $conn->query("DELETE FROM form_submissions");
                $conn->query("DELETE FROM leads");
                foreach ($campaignTables as $tn) {
                    $conn->query("DELETE FROM `$tn`");
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                $message = 'Failed to delete leads: ' . $e->getMessage();
                $messageType = 'danger';
                $paths = [];
            }

            if ($messageType !== 'danger') {
                if ($deleteFiles && !empty($paths)) {
                    $root = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
                    foreach ($paths as $rel) {
                        $rel = str_replace(['\\', "\0"], ['/', ''], $rel);
                        if (str_starts_with($rel, '/')) $rel = ltrim($rel, '/');
                        $full = realpath($root . '/' . $rel);
                        if ($full && str_starts_with($full, $root) && is_file($full)) {
                            @unlink($full);
                        }
                    }
                }
                $message = 'All leads deleted successfully.';
                $messageType = 'success';
                header('Location: leads-purge?done=1');
                exit;
            }
        }
    }
}

if (isset($_GET['done'])) {
    $message = 'All leads deleted successfully.';
    $messageType = 'success';
    $counts = ['leads' => 0, 'form_submissions' => 0, 'lead_files' => 0, 'lead_activity' => 0, 'campaign_tables' => 0];
}

?>
<?php $pageTitle = 'Delete All Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <div class="h3 mb-1">Delete All Leads</div>
            <div class="text-muted small">Deletes leads + related submissions/files/activity</div>
        </div>
        <a class="btn btn-light border" href="javascript:history.back()">Back</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Current Counts</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between"><span class="text-muted">Leads</span><span class="fw-semibold"><?php echo (int)$counts['leads']; ?></span></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Form submissions</span><span class="fw-semibold"><?php echo (int)$counts['form_submissions']; ?></span></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Lead files</span><span class="fw-semibold"><?php echo (int)$counts['lead_files']; ?></span></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Lead activity</span><span class="fw-semibold"><?php echo (int)$counts['lead_activity']; ?></span></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Campaign lead tables</span><span class="fw-semibold"><?php echo (int)$counts['campaign_tables']; ?></span></div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Confirm Delete</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="alert alert-warning">
                            This action is irreversible. It removes all leads from the database.
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="delete_files" name="delete_files" value="1">
                            <label class="form-check-label" for="delete_files">Also delete uploaded recording and lead files</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Type DELETE ALL to confirm</label>
                            <input class="form-control" name="confirm" placeholder="DELETE ALL" autocomplete="off" required>
                        </div>
                        <button class="btn btn-danger w-100" type="submit">Delete All Leads</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

