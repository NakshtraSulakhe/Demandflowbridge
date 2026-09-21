<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();

$flagDir = __DIR__ . '/../../storage';
$flagFile = $flagDir . '/setup_done.flag';
$done = file_exists($flagFile);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (string)$_POST['action'] === 'run_setup') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } elseif ($done) {
        $message = 'Setup already completed.';
        $messageType = 'info';
    } elseif (trim((string)($_POST['confirm_text'] ?? '')) !== 'INIT') {
        $message = 'Confirmation text mismatch.';
        $messageType = 'danger';
    } else {
        try {
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0775, true);
            }

            ensureDatabaseSchema();
            ensureLeadsTrackingColumns();
            if (function_exists('ensureCampaignDetailsSchema')) ensureCampaignDetailsSchema();
            if (function_exists('ensureCampaignDeliverySchema')) ensureCampaignDeliverySchema();
            if (function_exists('ensureCampaignMetricsSchema')) ensureCampaignMetricsSchema();
            if (function_exists('ensureLeadTagsSchema')) ensureLeadTagsSchema();
            if (function_exists('ensureBillingProfilesSchema')) ensureBillingProfilesSchema();
            if (function_exists('ensureTeamSchema')) ensureTeamSchema();
            if (function_exists('ensureAppSettingsSchema')) ensureAppSettingsSchema();
            if (function_exists('ensureUserIpAccessSchema')) ensureUserIpAccessSchema();
            if (function_exists('ensureChatSchema')) ensureChatSchema();

            $payload = [
                'completed_at' => date('c'),
                'by_user_id' => (int)(getCurrentUser()['id'] ?? 0),
            ];
            @file_put_contents($flagFile, json_encode($payload, JSON_PRETTY_PRINT));
            $done = true;
            $message = 'Setup completed.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Setup failed.';
            $messageType = 'danger';
        }
    }
}

$pageTitle = 'Setup';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">System Setup</div>
            <div class="text-muted small">Initialize database schema and required tables</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../dashboard/admin-dashboard.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-database-check me-1"></i>Initialize</div>
        <div class="card-body">
            <div class="mb-3">
                <div class="text-muted small">Status</div>
                <div class="fw-semibold"><?php echo $done ? 'Completed' : 'Not yet run'; ?></div>
                <?php if ($done): ?>
                    <div class="text-muted small mt-1">Flag: <?php echo htmlspecialchars($flagFile); ?></div>
                <?php endif; ?>
            </div>

            <?php if (!$done): ?>
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="run_setup">
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Type INIT to confirm</label>
                        <input class="form-control form-control-sm" name="confirm_text" placeholder="INIT" required>
                    </div>
                    <div class="col-md-3 d-grid">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-play-fill me-1"></i>Run Setup</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="text-muted small">Setup has already been executed. If you need to re-run, delete the flag file on the server.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
