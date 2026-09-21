<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('notifications.page', []);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo 'Invalid token';
        exit;
    }
    markAllNotificationsRead($userId);
    header('Location: notifications');
    exit;
}

$view = strtolower(trim((string)($_GET['view'] ?? 'unread')));
if (!in_array($view, ['unread','all'], true)) $view = 'unread';
$typeFilter = trim((string)($_GET['type'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$filter = buildCampaignAccessSqlFilterForNotifications($userId, 'campaign_id');
$conn = getDbConnection();

$where = "user_id = ? AND " . $filter['sql'];
$bindTypes = 'i' . (string)($filter['types'] ?? '');
$bindParams = array_merge([$userId], (array)($filter['params'] ?? []));

if ($view === 'unread') {
    $where .= " AND is_read = 0";
}
if ($typeFilter !== '') {
    $where .= " AND type = ?";
    $bindTypes .= 's';
    $bindParams[] = $typeFilter;
}

$rows = [];
$sql = "SELECT id, type, title, body, link_url, importance, is_read, created_at, read_at
        FROM notifications
        WHERE {$where}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $bindTypes2 = $bindTypes . 'ii';
    $bindParams2 = array_merge($bindParams, [$perPage, $offset]);
    $stmt->bind_param($bindTypes2, ...$bindParams2);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$typeRows = [];
$stmtT = $conn->prepare("
    SELECT type,
           COUNT(*) AS total,
           SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
    FROM notifications
    WHERE user_id = ? AND " . $filter['sql'] . "
    GROUP BY type
    ORDER BY unread DESC, total DESC
    LIMIT 40
");
if ($stmtT) {
    $tTypes = 'i' . (string)($filter['types'] ?? '');
    $tParams = array_merge([$userId], (array)($filter['params'] ?? []));
    $stmtT->bind_param($tTypes, ...$tParams);
    $stmtT->execute();
    $typeRows = $stmtT->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtT->close();
}

$unreadCount = function_exists('getUnreadNotificationCount') ? (int)getUnreadNotificationCount($userId) : 0;
$pageTitle = 'Notifications';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Notifications</h3>
      <div class="text-muted small">Latest updates in one place. Unread: <?php echo number_format($unreadCount); ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="preferences.php"><i class="bi bi-sliders me-1"></i>Preferences</a>
      <form method="post" class="d-flex gap-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="mark_all_read">
        <button class="btn btn-light border btn-sm" type="submit"><i class="bi bi-check2-all me-1"></i>Mark all read</button>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div class="btn-group btn-group-sm" role="group" aria-label="View">
        <a class="btn <?php echo $view === 'unread' ? 'btn-primary' : 'btn-light border'; ?>" href="?view=unread<?php echo $typeFilter !== '' ? '&type=' . urlencode($typeFilter) : ''; ?>">Unread</a>
        <a class="btn <?php echo $view === 'all' ? 'btn-primary' : 'btn-light border'; ?>" href="?view=all<?php echo $typeFilter !== '' ? '&type=' . urlencode($typeFilter) : ''; ?>">All</a>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <select class="form-select form-select-sm" style="width: 320px; max-width: 100%;" onchange="(function(v){var qs=new URLSearchParams(window.location.search); if(v){qs.set('type',v);} else {qs.delete('type');} qs.set('page','1'); window.location.search=qs.toString();})(this.value)">
          <option value="">All types</option>
          <?php foreach ($typeRows as $tr): ?>
            <?php $t = (string)($tr['type'] ?? ''); if ($t === '') continue; ?>
            <?php $u = (int)($tr['unread'] ?? 0); $tot = (int)($tr['total'] ?? 0); ?>
            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $t === $typeFilter ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($t); ?><?php echo $u > 0 ? (' · ' . $u . ' unread') : ''; ?><?php echo $tot > 0 ? (' · ' . $tot) : ''; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="list-group list-group-flush">
      <?php if (empty($rows)): ?>
        <div class="p-4 text-center text-muted">No notifications found.</div>
      <?php else: ?>
        <?php foreach ($rows as $n): ?>
          <?php
            $id = (int)($n['id'] ?? 0);
            $type = (string)($n['type'] ?? '');
            $title = (string)($n['title'] ?? '');
            $body = (string)($n['body'] ?? '');
            $link = (string)($n['link_url'] ?? '');
            $importance = strtolower(trim((string)($n['importance'] ?? 'normal')));
            if (!in_array($importance, ['low','normal','high'], true)) $importance = 'normal';
            $isRead = (int)($n['is_read'] ?? 0) === 1;
            $createdAt = (string)($n['created_at'] ?? '');
            $when = $createdAt ? opsFormatUtc($createdAt, 'd M Y, H:i') : '';
            $href = $link !== '' ? ('mark-read.php?id=' . $id . '&csrf_token=' . urlencode($_SESSION['csrf_token']) . '&to=' . urlencode($link)) : ('mark-read.php?id=' . $id . '&csrf_token=' . urlencode($_SESSION['csrf_token']));
            $tLower = strtolower($type);
            $lLower = strtolower($link);
            $icon = 'bi-bell';
            $iconCls = $importance === 'high' ? 'text-danger' : ($importance === 'low' ? 'text-secondary' : 'text-secondary');
            if (str_contains($tLower, 'chat') || str_contains($lLower, 'chat')) { $icon = 'bi-chat-dots-fill'; $iconCls = $importance === 'high' ? 'text-danger' : 'text-primary'; }
            elseif (str_contains($tLower, 'lead') || str_contains($lLower, 'lead')) { $icon = 'bi-person-lines-fill'; $iconCls = $importance === 'high' ? 'text-danger' : 'text-info'; }
            elseif (str_contains($tLower, 'campaign') || str_contains($lLower, 'campaign')) { $icon = 'bi-megaphone'; $iconCls = $importance === 'high' ? 'text-danger' : 'text-primary'; }
            elseif (str_contains($tLower, 'invoice') || str_contains($lLower, 'invoice') || str_contains($lLower, 'revenue')) { $icon = 'bi-receipt'; $iconCls = $importance === 'high' ? 'text-danger' : 'text-success'; }
          ?>
          <a class="list-group-item list-group-item-action <?php echo $isRead ? '' : 'bg-light'; ?>" href="<?php echo htmlspecialchars($href); ?>">
            <div class="d-flex justify-content-between align-items-start gap-3">
              <div style="min-width:0;">
                <div class="fw-semibold text-truncate d-flex align-items-center gap-2">
                  <?php if (!$isRead): ?><span class="badge rounded-pill bg-primary" style="width:8px;height:8px;padding:0;"></span><?php endif; ?>
                  <i class="bi <?php echo htmlspecialchars($icon); ?> <?php echo htmlspecialchars($iconCls); ?>"></i>
                  <span class="text-truncate"><?php echo htmlspecialchars($title); ?></span>
                  <?php if ($importance === 'high'): ?><span class="badge bg-danger-subtle text-danger border">Warning</span><?php endif; ?>
                  <?php if ($importance === 'low'): ?><span class="badge bg-light text-muted border">Low</span><?php endif; ?>
                </div>
                <?php if ($body !== ''): ?>
                  <div class="text-muted small" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo htmlspecialchars($body); ?></div>
                <?php endif; ?>
                <?php if ($type !== ''): ?><div class="text-muted" style="font-size:0.72rem;"><?php echo htmlspecialchars($type); ?></div><?php endif; ?>
              </div>
              <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($when); ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($rows) && count($rows) === $perPage): ?>
    <div class="d-flex justify-content-center mt-3">
      <?php $qs = $_GET; $qs['page'] = $page + 1; ?>
      <a class="btn btn-light border btn-sm" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Load more</a>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
