<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('admin.recognition_runner.page', ['admin']);
ensureCsrfToken();
ensureDatabaseSchema();
ensureAppSettingsSchema();

$message = '';
$messageType = 'success';
$summary = function_exists('dfRecognitionLastRunSummary') ? dfRecognitionLastRunSummary() : [];
$recognitionSchedulerToken = function_exists('getRecognitionSchedulerToken') ? (getRecognitionSchedulerToken(true) ?? '') : '';
$recognitionSchedulerPath = '/modules/admin/recognition-automation-cron.php';
$recognitionSchedulerUrl = rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . $recognitionSchedulerPath;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $summary = function_exists('dfRecognitionAutomationRun') ? dfRecognitionAutomationRun() : [];
        if (!empty($summary)) {
            $message = 'Recognition automation completed successfully.';
            $messageType = 'success';
        } else {
            $message = 'Recognition automation did not return a summary.';
            $messageType = 'danger';
        }
    }
}

$pageTitle = 'Recognition Runner';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h3 class="mb-1">Recognition Automation Runner</h3>
            <div class="text-muted small">Runs the centralized recognition feed summary manually and exposes the protected scheduler endpoint for cron execution.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="settings.php?tab=recognition"><i class="bi bi-arrow-left me-1"></i>Back To Settings</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header fw-semibold">Run Engine</div>
                <div class="card-body">
                    <div class="text-muted small mb-3">Use this page for manual execution, cron verification, and post-deployment checks.</div>
                    <div class="border rounded p-3 bg-light mb-3">
                        <div class="small text-muted">Scheduler endpoint</div>
                        <div class="fw-semibold small text-break"><?php echo htmlspecialchars($recognitionSchedulerUrl !== '' ? $recognitionSchedulerUrl : $recognitionSchedulerPath); ?></div>
                        <div class="small text-muted mt-2">Scheduler token</div>
                        <div class="font-monospace small text-break"><?php echo htmlspecialchars($recognitionSchedulerToken !== '' ? $recognitionSchedulerToken : 'Not available'); ?></div>
                    </div>
                    <form method="post" class="d-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-play-circle me-1"></i>Run Recognition Automation</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header fw-semibold">Last Summary</div>
                <div class="card-body">
                    <?php if (!empty($summary)): ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">Ran At</div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($summary['ran_at'] ?? '')); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">Users Scanned</div>
                                    <div class="fw-semibold"><?php echo number_format((int)($summary['users_scanned'] ?? 0)); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small">Cards Visible</div>
                                    <div class="fw-semibold"><?php echo number_format((int)($summary['cards_visible'] ?? 0)); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="fw-semibold mb-2">Card Distribution</div>
                        <?php if (!empty($summary['types']) && is_array($summary['types'])): ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($summary['types'] as $type => $count): ?>
                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$type); ?>: <?php echo number_format((int)$count); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small">No card types were recorded in the last run.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-muted small">No recognition summary has been generated yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
