<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('notifications.preferences.page', []);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$eventTypes = [
    'campaign.created' => 'New campaign created',
    'campaign.assigned' => 'Campaign allocated',
    'campaign.end_warning' => 'Campaign end date warning',
    'campaign.pacing_risk' => 'Low leads pacing alert',
    'campaign.updated' => 'Campaign updated',
    'lead.created' => 'New lead uploaded',
    'lead.updated' => 'Lead updated',
    'lead.status_updated' => 'Lead status updated',
    'client.lead.delivered' => 'Client: Lead delivered',
    'chat.message' => 'New chat message',
    'chat.group_message' => 'New group message',
    'chat.added_to_group' => 'Chat: added to group',
    'sales.followup_reminder' => 'Sales follow-up reminder',
    'sales.followup.added' => 'Sales: follow-up added',
    'sales.followup.updated' => 'Sales: follow-up updated',
    'invoice.created' => 'Invoice created',
    'invoice.status_changed' => 'Invoice status updated',
    'invoice.paid' => 'Invoice marked paid',
    'task.assigned' => 'Task: assigned',
    'task.status_changed' => 'Task: status changed',
    'task.progress' => 'Task: progress updated',
    'task.comment' => 'Task: comment added',
    'task.mention' => 'Task: mentioned',
    'task.file_uploaded' => 'Task: file uploaded',
    'task.completed' => 'Task: completed',
    'task.rejected' => 'Task: rejected',
    'task.overdue' => 'Task: overdue',
    'task.escalated' => 'Task: escalated',
    'task.deadline.warning.24h' => 'Task: deadline 24h warning',
    'task.deadline.warning.12h' => 'Task: deadline 12h warning',
    'task.deadline.warning.6h' => 'Task: deadline 6h warning',
    'task.deadline.warning.1h' => 'Task: deadline 1h warning',
];

$defaultPrefsRaw = (string)(getAppSetting('notifications.default_prefs', '') ?? '');
$defaultPrefs = $defaultPrefsRaw !== '' ? json_decode($defaultPrefsRaw, true) : null;
if (!is_array($defaultPrefs)) $defaultPrefs = [];

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        foreach ($eventTypes as $type => $_label) {
            $enabled = !empty($_POST['enabled'][$type]) ? 1 : 0;
            $mode = (string)($_POST['mode'][$type] ?? 'instant');
            if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
            $toast = !empty($_POST['toast'][$type]) ? 1 : 0;

            $stmt = $conn->prepare("
                INSERT INTO notification_preferences (user_id, type, delivery_mode, is_enabled, show_toast)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    delivery_mode=VALUES(delivery_mode),
                    is_enabled=VALUES(is_enabled),
                    show_toast=VALUES(show_toast),
                    updated_at=NOW()
            ");
            if ($stmt) {
                $stmt->bind_param('issii', $userId, $type, $mode, $enabled, $toast);
                $stmt->execute();
                $stmt->close();
            }
        }
        $message = 'Preferences saved.';
        $messageType = 'success';
    }
}

$prefs = [];
$stmt = $conn->prepare("SELECT type, delivery_mode, is_enabled, show_toast FROM notification_preferences WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $t = (string)($r['type'] ?? '');
        if ($t === '') continue;
        $prefs[$t] = $r;
    }
}

$pageTitle = 'Notification Preferences';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h3 class="mb-1">Notification Preferences</h3>
            <div class="text-muted small">Control which alerts you receive and how they are delivered.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="notifications.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="alert alert-info border-0 shadow-sm">
            <div class="fw-semibold mb-1">How it works</div>
            <div class="small">Enabled events create notifications for you. Instant means it appears immediately in the bell menu. Digest means it is queued and later grouped into a single “digest” notification when an admin runs the digest generator.</div>
            <div class="small mt-1">Popup shows the centered notification popup and blurs the background so it is clearly visible.</div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">Events</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Event</th>
                            <th style="width:110px;" class="text-center">Enabled</th>
                            <th style="width:160px;">Delivery</th>
                            <th style="width:140px;" class="text-center">Popup (Toast)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventTypes as $type => $label): ?>
                            <?php
                                $row = $prefs[$type] ?? null;
                                $def = !$row && is_array($defaultPrefs[$type] ?? null) ? $defaultPrefs[$type] : null;
                                $enabled = $row ? ((int)($row['is_enabled'] ?? 1) === 1) : ($def ? ((int)($def['enabled'] ?? 1) === 1) : true);
                                $mode = $row ? (string)($row['delivery_mode'] ?? 'instant') : ($def ? (string)($def['mode'] ?? 'instant') : 'instant');
                                if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                                $toast = $row ? ((int)($row['show_toast'] ?? 0) === 1) : ($def ? ((int)($def['toast'] ?? 0) === 1) : in_array($type, ['campaign.end_warning','campaign.pacing_risk','sales.followup_reminder','chat.message','chat.group_message','lead.created','lead.updated'], true));
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($label); ?><div class="text-muted small"><?php echo htmlspecialchars($type); ?></div></td>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox" name="enabled[<?php echo htmlspecialchars($type); ?>]" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" name="mode[<?php echo htmlspecialchars($type); ?>]">
                                        <option value="instant" <?php echo $mode === 'instant' ? 'selected' : ''; ?>>Instant</option>
                                        <option value="digest" <?php echo $mode === 'digest' ? 'selected' : ''; ?>>Digest</option>
                                    </select>
                                </td>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox" name="toast[<?php echo htmlspecialchars($type); ?>]" value="1" <?php echo $toast ? 'checked' : ''; ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2 me-1"></i>Save</button>
            </div>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
