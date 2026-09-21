<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('notifications.digest.endpoint', []);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();
$nowUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $minAgeMin = (int)($_POST['min_age_min'] ?? 60);
        if ($minAgeMin < 5) $minAgeMin = 5;
        if ($minAgeMin > 10080) $minAgeMin = 10080;

        $rows = [];
        $thresholdUtc = (new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')))->modify('-' . $minAgeMin . ' minutes')->format('Y-m-d H:i:s');
        $stmt = $conn->prepare("SELECT id, user_id, type, campaign_id FROM notification_digest_queue WHERE processed_at IS NULL AND created_at <= ? ORDER BY user_id, created_at");
        if ($stmt) {
            $stmt->bind_param('s', $thresholdUtc);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }

        $byUser = [];
        $ids = [];
        $accessCache = [];
        foreach ($rows as $r) {
            $qid = (int)($r['id'] ?? 0);
            $uid = (int)($r['user_id'] ?? 0);
            $type = (string)($r['type'] ?? '');
            $cid = (int)($r['campaign_id'] ?? 0);
            if ($qid <= 0 || $uid <= 0 || $type === '') continue;
            $ids[] = $qid;
            if ($cid > 0) {
                if (!array_key_exists($uid, $accessCache)) {
                    $accessCache[$uid] = getUserCampaignAccessMapForNotifications($uid);
                }
                $map = $accessCache[$uid];
                if ($map !== null && empty($map[$cid])) {
                    continue;
                }
            }
            if (!isset($byUser[$uid])) $byUser[$uid] = [];
            if (!isset($byUser[$uid][$type])) $byUser[$uid][$type] = 0;
            $byUser[$uid][$type] += 1;
        }

        $created = 0;
        foreach ($byUser as $uid => $counts) {
            $parts = [];
            foreach ($counts as $type => $cnt) {
                $parts[] = $type . ': ' . (int)$cnt;
            }
            if (empty($parts)) continue;
            $title = 'Notifications digest';
            $body = implode(' · ', $parts);
            $link = '../notifications/notifications.php';
            $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, type, title, body, link_url, importance, show_toast) VALUES (?,?,?,?,?,?,0)");
            if ($stmt2) {
                $type = 'digest';
                $imp = 'low';
                $stmt2->bind_param('isssss', $uid, $type, $title, $body, $link, $imp);
                if ($stmt2->execute()) $created++;
                $stmt2->close();
            }
        }

        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $types = 's' . str_repeat('i', count($ids));
            $stmt3 = $conn->prepare("UPDATE notification_digest_queue SET processed_at = ? WHERE id IN ($in)");
            if ($stmt3) {
                $bind = array_merge([$nowUtc], $ids);
                $stmt3->bind_param($types, ...$bind);
                $stmt3->execute();
                $stmt3->close();
            }
        }

        $message = 'Digest created: ' . $created;
        $messageType = 'success';
    }
}

$pending = 0;
$rs = $conn->query("SELECT COUNT(*) AS cnt FROM notification_digest_queue WHERE processed_at IS NULL");
if ($rs) $pending = (int)(($rs->fetch_assoc() ?: [])['cnt'] ?? 0);

$pageTitle = 'Digest Runner';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h3 class="mb-1">Notification Digest Runner</h3>
            <div class="text-muted small">Pending digest queue: <?php echo number_format($pending); ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="notifications.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">Run Digest</div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="col-md-4">
                    <label class="form-label small text-muted">Minimum age (minutes)</label>
                    <input class="form-control form-control-sm" name="min_age_min" value="60">
                </div>
                <div class="col-md-4 d-grid">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-play-circle me-1"></i>Generate Digest</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
