<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr', 'admin']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$isSdr = isSDR();
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$todayRange = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => $opsNow->format('Y-m-d 00:00:00'), 'end' => $opsNow->format('Y-m-d 23:59:59'), 'start_date' => $opsNow->format('Y-m-d')];
$due7Range = function_exists('opsDateRangeUtc')
    ? opsDateRangeUtc((string)($todayRange['start_date'] ?? $opsNow->format('Y-m-d')), $opsNow->modify('+7 days')->format('Y-m-d'))
    : ['start' => $opsNow->format('Y-m-d 00:00:00'), 'end' => $opsNow->modify('+7 days')->format('Y-m-d 23:59:59')];
$opsTodayDate = (string)($todayRange['start_date'] ?? $opsNow->format('Y-m-d'));
$nowUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');
$nowUtcDt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$scopeUserIds = function_exists('getSalesScopeUserIdsForCurrentUser') ? getSalesScopeUserIdsForCurrentUser($user) : [$userId];
$scopeIds = is_array($scopeUserIds) ? array_values(array_filter(array_map('intval', $scopeUserIds), fn($v) => $v > 0)) : null;
$isSelfOnly = ($scopeIds !== null && count($scopeIds) === 1 && (int)$scopeIds[0] === (int)$userId);
$message = '';
$messageType = 'success';

$statuses = ['All','New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];
$priorities = ['All','Low','Normal','High','Urgent'];
$dueFilters = ['All','Overdue','Due Today','Due 7 Days','No Follow-up'];

$canManageLead = function(int $leadOwnerId) use ($userId): bool {
    if (isAdmin() || isSalesDirector()) return true;
    if (isSDR()) return $leadOwnerId === $userId;
    if (!isSalesManager()) return false;
    if ($leadOwnerId === $userId) return true;
    return getSalesManagerForSdr($leadOwnerId) === $userId;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_lead') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $lead = $leadId > 0 ? getSalesLeadById($leadId) : null;
        if (!$lead) {
            $message = 'Prospect not found.';
            $messageType = 'danger';
        } elseif (!$canManageLead((int)($lead['owner_id'] ?? 0))) {
            $message = 'You are not allowed to delete this prospect.';
            $messageType = 'danger';
        } elseif (!deleteSalesLead($leadId, $userId)) {
            $message = 'Unable to delete the prospect right now.';
            $messageType = 'danger';
        } else {
            $companyName = trim((string)($lead['company_name'] ?? ''));
            $message = ($companyName !== '' ? $companyName : 'Prospect') . ' deleted successfully.';
            $messageType = 'success';
        }
    }
}

$status = trim((string)($_GET['status'] ?? 'All'));
if (!in_array($status, $statuses, true)) $status = 'All';
$priority = trim((string)($_GET['priority'] ?? 'All'));
if (!in_array($priority, $priorities, true)) $priority = 'All';
$due = trim((string)($_GET['due'] ?? 'All'));
if (!in_array($due, $dueFilters, true)) $due = 'All';

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
$types = '';

if ($scopeIds === null) {
} elseif (!empty($scopeIds)) {
    $in = implode(',', array_fill(0, count($scopeIds), '?'));
    $where[] = "sl.owner_id IN ($in)";
    $params = array_merge($params, $scopeIds);
    $types .= str_repeat('i', count($scopeIds));
} else {
    $where[] = "0=1";
}

if ($status !== 'All') {
    $where[] = "sl.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($priority !== 'All') {
    $where[] = "sl.priority = ?";
    $params[] = $priority;
    $types .= 's';
}

if ($q !== '') {
    $where[] = "(sl.company_name LIKE ? OR sl.website LIKE ? OR sl.contact_name LIKE ? OR sl.contact_email LIKE ? OR sl.linkedin_url LIKE ?)";
    $like = '%'.$q.'%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$closedSql = "(sl.status = 'Closed Won' OR sl.status = 'Closed Lost')";
if ($due === 'Overdue') {
    $where[] = "sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at < ? AND NOT $closedSql";
    $params[] = $nowUtc;
    $types .= 's';
} elseif ($due === 'Due Today') {
    $where[] = "sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at >= ? AND sl.next_follow_up_at <= ? AND NOT $closedSql";
    $params[] = (string)($todayRange['start'] ?? '1970-01-01 00:00:00');
    $params[] = (string)($todayRange['end'] ?? '2999-12-31 23:59:59');
    $types .= 'ss';
} elseif ($due === 'Due 7 Days') {
    $where[] = "sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at >= ? AND sl.next_follow_up_at <= ? AND NOT $closedSql";
    $params[] = (string)($due7Range['start'] ?? '1970-01-01 00:00:00');
    $params[] = (string)($due7Range['end'] ?? '2999-12-31 23:59:59');
    $types .= 'ss';
} elseif ($due === 'No Follow-up') {
    $where[] = "sl.next_follow_up_at IS NULL AND NOT $closedSql";
}

$whereSql = implode(' AND ', $where);

$cntStmt = $conn->prepare("SELECT COUNT(*) FROM sales_leads sl WHERE $whereSql");
if ($types) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

$sql = "SELECT sl.*,
            o.full_name AS owner_name, o.role AS owner_role, o.job_title AS owner_job_title,
            m.full_name AS manager_name,
            la.id AS last_activity_id, la.comment AS last_comment, la.created_at AS last_comment_at,
            ub.full_name AS updated_by_name, ub.role AS updated_by_role, ub.job_title AS updated_by_job_title
        FROM sales_leads sl
        LEFT JOIN users o ON o.id = sl.owner_id
        LEFT JOIN users m ON m.id = sl.sales_manager_id
        LEFT JOIN (
            SELECT a.*
            FROM sales_lead_activities a
            INNER JOIN (
                SELECT sales_lead_id, MAX(id) AS max_id
                FROM sales_lead_activities
                GROUP BY sales_lead_id
            ) x ON x.max_id = a.id
        ) la ON la.sales_lead_id = sl.id
        LEFT JOIN users ub ON ub.id = sl.updated_by
        WHERE $whereSql
        ORDER BY sl.updated_at DESC, sl.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$p = $params;
$p[] = $perPage;
$p[] = $offset;
$stmt->bind_param($types.'ii', ...$p);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$statusOptions = array_values(array_filter($statuses, fn($s) => $s !== 'All'));
$dupOwnerOptions = [];
if ($isSelfOnly) {
    $dupOwnerOptions[] = ['id' => (int)$userId, 'full_name' => (string)($user['full_name'] ?? 'You'), 'role' => (string)($user['role'] ?? '')];
} else {
    if ($scopeIds === null) {
        $rsOwners = $conn->query("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role IN ('sdr','sales_manager','sales_director') ORDER BY full_name");
        if ($rsOwners) $dupOwnerOptions = $rsOwners->fetch_all(MYSQLI_ASSOC) ?: [];
    } elseif (!empty($scopeIds)) {
        $in = implode(',', array_fill(0, count($scopeIds), '?'));
        $t = str_repeat('i', count($scopeIds));
        $stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1 AND id IN ($in) ORDER BY full_name");
        if ($stmt) {
            $stmt->bind_param($t, ...$scopeIds);
            $stmt->execute();
            $dupOwnerOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }
    }
}
?>

<?php $pageTitle = 'Sales Pipeline'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
  .sales-filter-submit {
    min-height: calc(1.5em + 0.5rem + 2px);
  }
</style>
<div class="container-fluid px-0 sales-workspace">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-funnel"></i>Pipeline Control</div>
      <h3 class="sales-page-title">Sales Pipeline</h3>
      <div class="sales-page-subtitle"><?php echo $isSelfOnly ? 'Your prospects only' : ($scopeIds === null ? 'All prospects' : 'Team prospects'); ?> · Review ownership, due follow-ups, and recent touchpoints in one queue.</div>
    </div>
    <div class="sales-page-actions">
      <button type="button" class="btn btn-outline-primary btn-sm" id="openSalesDupModal"><i class="bi bi-search me-1"></i>Check Duplicate</button>
      <a href="lead-create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Prospect</a>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="modal fade" id="salesDupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Sales Duplicate Check</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="sales_dup_csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small text-muted">Company</label>
              <input type="text" class="form-control form-control-sm" id="sales_dup_company">
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted">Country</label>
              <input type="text" class="form-control form-control-sm" id="sales_dup_country" placeholder="e.g., India">
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted">Lead Owner</label>
              <select class="form-select form-select-sm" id="sales_dup_owner">
                <option value="">Any</option>
                <?php foreach ($dupOwnerOptions as $o): ?>
                  <option value="<?php echo (int)($o['id'] ?? 0); ?>"><?php echo htmlspecialchars((string)($o['full_name'] ?? '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="sales_dup_audit_all">
                <label class="form-check-label small text-muted" for="sales_dup_audit_all">All departments audit</label>
              </div>
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button type="button" class="btn btn-primary btn-sm" id="runSalesDupCheck"><i class="bi bi-search me-1"></i>Check</button>
            </div>
          </div>

          <div id="salesDupResult" class="mt-3" style="display:none;">
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Company</th>
                    <th>Country</th>
                    <th>Lead Owner</th>
                  </tr>
                </thead>
                <tbody id="salesDupBody"></tbody>
              </table>
            </div>
          </div>
          <div id="salesDupEmpty" class="text-muted small mt-3" style="display:none;">No duplicates found.</div>
          <div id="salesDupError" class="text-danger small mt-3" style="display:none;">Unable to check duplicates.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3 border-0 shadow-sm sales-toolbar sales-panel">
    <div class="card-body">
      <form class="row g-2 sales-filter-form" method="get">
        <div class="col-12">
          <div class="small fw-semibold text-uppercase text-muted">Filters</div>
        </div>
        <div class="col-lg-4">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search company, website, contact, email, LinkedIn">
        </div>
        <div class="col-lg-3">
          <label class="form-label small text-muted">Status</label>
          <select class="form-select form-select-sm" name="status">
            <?php foreach ($statuses as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Priority</label>
          <select class="form-select form-select-sm" name="priority">
            <?php foreach ($priorities as $p): ?>
              <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $priority === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Follow-up</label>
          <select class="form-select form-select-sm" name="due">
            <?php foreach ($dueFilters as $df): ?>
              <option value="<?php echo htmlspecialchars($df); ?>" <?php echo $due === $df ? 'selected' : ''; ?>><?php echo htmlspecialchars($df); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-auto d-flex align-items-end">
          <button class="btn btn-outline-primary btn-sm sales-filter-submit" type="submit"><i class="bi bi-filter me-1"></i>Filter</button>
        </div>
      </form>
    </div>
    <div class="table-responsive sales-table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Company</th>
            <th>Status</th>
            <th>Priority</th>
            <th>Owner</th>
            <th>Manager / Director</th>
            <th>Next Follow-up</th>
            <th class="text-muted">Last Touch</th>
            <th>Last Note</th>
            <th class="text-muted">Updated</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No prospects found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $st = (string)($r['status'] ?? '');
                $badge = 'bg-secondary';
                if ($st === 'Closed Won') $badge = 'bg-success';
                elseif ($st === 'Closed Lost') $badge = 'bg-danger';
                elseif ($st === 'Negotiation') $badge = 'bg-warning text-dark';
                elseif ($st === 'Proposal Sent') $badge = 'bg-primary';
                elseif ($st === 'Meeting Scheduled') $badge = 'bg-info text-dark';
                elseif ($st === 'Follow-up Required') $badge = 'bg-warning text-dark';
                $nfa = (string)($r['next_follow_up_at'] ?? '');
                $lta = (string)($r['last_activity_at'] ?? '');
                $lastActId = (int)($r['last_activity_id'] ?? 0);
                $lastComment = trim((string)($r['last_comment'] ?? ''));
                $isClosed = in_array($st, ['Closed Won','Closed Lost'], true);
                $nfaDt = $nfa !== '' ? appParseUtc($nfa) : null;
                $ltaDt = $lta !== '' ? appParseUtc($lta) : null;
                $isOverdue = (!$isClosed && $nfaDt instanceof DateTimeImmutable && $nfaDt < $nowUtcDt);
                $isToday = (!$isClosed && $nfaDt instanceof DateTimeImmutable && $nfaDt->setTimezone(opsBusinessTz())->format('Y-m-d') === $opsTodayDate);
                $updatedByLabel = trim((string)($r['updated_by_name'] ?? ''));
                $updatedByRole = trim((string)($r['updated_by_role'] ?? ''));
              ?>
              <tr data-lead-id="<?php echo (int)$r['id']; ?>"
                  data-status="<?php echo htmlspecialchars($st); ?>"
                  data-priority="<?php echo htmlspecialchars((string)($r['priority'] ?? '')); ?>"
                  data-next-follow-up-at="<?php echo htmlspecialchars($nfa); ?>"
                  data-last-activity-at="<?php echo htmlspecialchars($lta); ?>"
                  data-last-activity-id="<?php echo (int)$lastActId; ?>"
                  data-last-comment="<?php echo htmlspecialchars($lastComment); ?>">
                <td>
                  <div class="fw-semibold">
                    <a class="text-decoration-none" href="lead-view?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['company_name'] ?? ''); ?></a>
                  </div>
                  <div class="text-muted small">
                    <?php echo htmlspecialchars($r['website_domain'] ?? ($r['website'] ?? '')); ?>
                    <?php if (!empty($r['contact_name'])): ?> · <?php echo htmlspecialchars($r['contact_name']); ?><?php endif; ?>
                  </div>
                </td>
                <td><span class="badge <?php echo htmlspecialchars($badge); ?>"><?php echo htmlspecialchars($st); ?></span></td>
                <td><?php echo htmlspecialchars($r['priority'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['owner_name'] ?? '')); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['manager_name'] ?? ''); ?></td>
                <td class="text-muted small">
                  <?php if ($nfa !== ''): ?>
                    <span class="badge border <?php echo $isOverdue ? 'bg-danger-subtle text-danger' : ($isToday ? 'bg-warning-subtle text-warning' : 'bg-light text-dark'); ?>">
                      <?php echo htmlspecialchars($nfaDt ? $nfaDt->setTimezone(opsBusinessTz())->format('d M, H:i') : ''); ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted small"><?php echo $ltaDt ? htmlspecialchars($ltaDt->setTimezone(opsBusinessTz())->format('d M, H:i')) : '<span class="text-muted">—</span>'; ?></td>
                <td class="small">
                  <?php if ($lastComment !== ''): ?>
                    <span class="d-inline-block text-truncate" style="max-width: 260px;" title="<?php echo htmlspecialchars($lastComment); ?>">
                      <?php echo htmlspecialchars($lastComment); ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted small">
                  <?php
                    $ts = $r['updated_at'] ?: $r['created_at'];
                    $tsDt = $ts ? appParseUtc((string)$ts) : null;
                    $label = $tsDt ? $tsDt->setTimezone(opsBusinessTz())->format('d M, H:i') : '';
                    $by = $updatedByLabel !== '' ? formatUserNameWithRole($updatedByLabel, $updatedByRole, (string)($r['updated_by_job_title'] ?? '')) : '';
                    if ($by !== '') {
                      echo '<span title="'.htmlspecialchars('Updated by: '.$by).'">'.htmlspecialchars($label).'</span>';
                    } else {
                      echo $label ? htmlspecialchars($label) : '';
                    }
                  ?>
                </td>
                <td class="text-end text-nowrap">
                  <div class="d-inline-flex align-items-center gap-1">
                    <div class="btn-group btn-group-sm" role="group">
                      <a class="btn btn-light border" href="lead-view?id=<?php echo (int)$r['id']; ?>" title="View prospect">
                        <i class="bi bi-eye"></i>
                      </a>
                      <a class="btn btn-light border" href="lead-view?id=<?php echo (int)$r['id']; ?>&amp;edit=1#prospectDetails" title="Edit prospect">
                        <i class="bi bi-pencil-square"></i>
                      </a>
                      <button type="button" class="btn btn-light border" data-quick="add" title="Tag / Quick log">
                        <i class="bi bi-tag"></i>
                      </button>
                    </div>
                    <form method="post" class="m-0" onsubmit="return window.confirm('Delete this prospect? This will also remove its activity history.');">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                      <input type="hidden" name="action" value="delete_lead">
                      <input type="hidden" name="lead_id" value="<?php echo (int)$r['id']; ?>">
                      <button type="submit" class="btn btn-sm btn-light border text-danger" title="Delete prospect">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="modal fade" id="quickLogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title fw-semibold" id="quickLogTitle">Quick Update</div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex flex-wrap gap-2 mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="called"><i class="bi bi-telephone me-1"></i>Called</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="emailed"><i class="bi bi-envelope me-1"></i>Emailed</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="linkedin"><i class="bi bi-linkedin me-1"></i>LinkedIn</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="voicemail"><i class="bi bi-voicemail me-1"></i>No Answer</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="meeting"><i class="bi bi-calendar-event me-1"></i>Meeting</button>
          </div>
          <form id="quickLogForm" class="row g-2">
            <input type="hidden" name="mode" value="add">
            <input type="hidden" name="lead_id" value="">
            <input type="hidden" name="activity_id" value="">
            <div class="col-12">
              <label class="form-label">Status</label>
              <select class="form-select form-select-sm" name="status" required>
                <?php foreach ($statusOptions as $s): ?>
                  <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Next Follow-up (optional)</label>
              <input class="form-control form-control-sm" type="datetime-local" name="next_follow_up_at">
            </div>
            <div class="col-12">
              <label class="form-label">Comment</label>
              <textarea class="form-control form-control-sm" rows="4" name="comment" required></textarea>
            </div>
          </form>
          <div class="alert alert-danger d-none mt-2" id="quickLogError"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary btn-sm" id="quickLogSave">Save</button>
        </div>
      </div>
    </div>
  </div>

  <div class="sales-footer-bar">
    <div class="text-muted small">Showing <?php echo count($rows); ?> of <?php echo (int)$total; ?></div>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php
          $base = 'leads.php?'.http_build_query(['q'=>$q,'status'=>$status,'priority'=>$priority,'due'=>$due]);
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
        ?>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo $base.'&page='.$prev; ?>">Prev</a>
        </li>
        <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo $base.'&page='.$next; ?>">Next</a>
        </li>
      </ul>
    </nav>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

<script>
function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, function(s){
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[s] || s;
  });
}

const salesDupModalEl = document.getElementById('salesDupModal');
const openSalesDupModalBtn = document.getElementById('openSalesDupModal');
const salesDupBody = document.getElementById('salesDupBody');
const salesDupWrap = document.getElementById('salesDupResult');
const salesDupEmpty = document.getElementById('salesDupEmpty');
const salesDupError = document.getElementById('salesDupError');
const runSalesDupBtn = document.getElementById('runSalesDupCheck');

function resetSalesDupUi() {
  if (salesDupBody) salesDupBody.innerHTML = '';
  if (salesDupWrap) salesDupWrap.style.display = 'none';
  if (salesDupEmpty) salesDupEmpty.style.display = 'none';
  if (salesDupError) salesDupError.style.display = 'none';
}

openSalesDupModalBtn?.addEventListener('click', function(){
  resetSalesDupUi();
  bootstrap.Modal.getOrCreateInstance(salesDupModalEl).show();
  setTimeout(() => document.getElementById('sales_dup_company')?.focus(), 150);
});

runSalesDupBtn?.addEventListener('click', async function(){
  const company = (document.getElementById('sales_dup_company')?.value || '').trim();
  const country = (document.getElementById('sales_dup_country')?.value || '').trim();
  const ownerId = (document.getElementById('sales_dup_owner')?.value || '').trim();
  const csrf = (document.getElementById('sales_dup_csrf')?.value || '').trim();
  const auditAll = !!document.getElementById('sales_dup_audit_all')?.checked;

  resetSalesDupUi();
  if (!company) { salesDupError.style.display = 'block'; salesDupError.textContent = 'Enter company name.'; return; }

  const qs = new URLSearchParams({ company_name: company, country: country, owner_id: ownerId }).toString();
  const resSales = await fetch('check-duplicate?type=sales&' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(r=>r.json()).catch(()=>null);
  let resClients = null;
  let resLeads = null;
  if (auditAll) {
    resClients = await fetch('check-duplicate?type=client&' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(r=>r.json()).catch(()=>null);
    const payload = { csrf_token: csrf, first_name: '', last_name: '', email: '', company_name: company, campaign_id: 0 };
    resLeads = await fetch('../leads/check_duplicates', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials: 'same-origin', body: JSON.stringify(payload) }).then(r=>r.json()).catch(()=>null);
  }

  const rowsSales = (resSales && resSales.ok && Array.isArray(resSales.matches)) ? resSales.matches : [];
  const rowsClients = (resClients && resClients.ok && Array.isArray(resClients.matches)) ? resClients.matches : [];
  const rowsLeads = (resLeads && resLeads.ok && Array.isArray(resLeads.matches)) ? resLeads.matches : [];
  const totalRows = rowsSales.length + rowsClients.length + rowsLeads.length;
  if (!totalRows) {
    if (!(resSales && resSales.ok) && auditAll && (!resClients || !resLeads)) { salesDupError.style.display = 'block'; return; }
    salesDupEmpty.style.display = 'block'; return;
  }

  function addRow(src, d){
    const comp = d.company_name || d.name || '';
    const ctry = d.country || d.prospect_country || d.company_country || '';
    const owner = d.owner_name || d.lead_owner || d.agent_name || d.owner || '';
    const tr = document.createElement('tr');
    tr.innerHTML = '<td class="fw-semibold">'+escapeHtml(comp)+'</td>'
      + '<td class="small text-muted">'+escapeHtml(ctry)+'</td>'
      + '<td class="small text-muted">'+escapeHtml(owner)+'</td>';
    salesDupBody.appendChild(tr);
  }
  rowsSales.forEach(d => addRow('Sales', d));
  rowsClients.forEach(d => addRow('Clients', d));
  rowsLeads.forEach(d => addRow('Leads', d));
  salesDupWrap.style.display = 'block';
});

const quickLogToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
const quickLogModalEl = document.getElementById('quickLogModal');
const quickLogForm = document.getElementById('quickLogForm');
const quickLogTitle = document.getElementById('quickLogTitle');
const quickLogError = document.getElementById('quickLogError');
const quickLogSave = document.getElementById('quickLogSave');

const presetButtons = Array.from(document.querySelectorAll('[data-preset]'));

function parseIsoToLocalInput(value) {
  if (!value) return '';
  const d = new Date(value.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toLocalInputFromDate(d) {
  if (!(d instanceof Date) || isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function statusBadgeClass(st) {
  if (st === 'Closed Won') return 'bg-success';
  if (st === 'Closed Lost') return 'bg-danger';
  if (st === 'Negotiation') return 'bg-warning text-dark';
  if (st === 'Proposal Sent') return 'bg-primary';
  if (st === 'Meeting Scheduled') return 'bg-info text-dark';
  if (st === 'Follow-up Required') return 'bg-warning text-dark';
  return 'bg-secondary';
}

function formatDt(ts) {
  if (!ts) return '';
  const d = new Date(ts.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '';
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getDate())} ${months[d.getMonth()]}, ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function openQuickModal(mode, tr) {
  quickLogError.classList.add('d-none');
  quickLogError.textContent = '';
  quickLogForm.mode.value = mode;
  quickLogForm.lead_id.value = tr.dataset.leadId || '';
  quickLogForm.activity_id.value = '';
  quickLogForm.status.value = tr.dataset.status || 'New';
  quickLogForm.next_follow_up_at.value = parseIsoToLocalInput(tr.dataset.nextFollowUpAt || '');
  quickLogForm.comment.value = '';
  quickLogTitle.textContent = 'Tag / Quick Log';
  bootstrap.Modal.getOrCreateInstance(quickLogModalEl).show();
}

function applyPreset(presetKey) {
  const presets = {
    called: {
      status: 'Contacted',
      followUpMinutes: 24 * 60,
      comment: 'Called prospect. '
    },
    emailed: {
      status: 'Follow-up Required',
      followUpMinutes: 2 * 24 * 60,
      comment: 'Sent email. '
    },
    linkedin: {
      status: 'Contacted',
      followUpMinutes: 2 * 24 * 60,
      comment: 'Sent LinkedIn message. '
    },
    voicemail: {
      status: 'Follow-up Required',
      followUpMinutes: 24 * 60,
      comment: 'No answer. Left voicemail. '
    },
    meeting: {
      status: 'Meeting Scheduled',
      followUpMinutes: null,
      comment: 'Meeting scheduled. '
    }
  };
  const p = presets[presetKey];
  if (!p) return;

  if (p.status) quickLogForm.status.value = p.status;

  if (p.followUpMinutes !== null) {
    const d = new Date();
    d.setMinutes(d.getMinutes() + Number(p.followUpMinutes || 0));
    quickLogForm.next_follow_up_at.value = toLocalInputFromDate(d);
  }

  const existing = quickLogForm.comment.value.trim();
  if (existing === '' || quickLogForm.mode.value === 'add') {
    quickLogForm.comment.value = p.comment;
  } else {
    quickLogForm.comment.value = p.comment + existing;
  }
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-preset]');
  if (!btn) return;
  applyPreset(btn.getAttribute('data-preset'));
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-quick]');
  if (!btn) return;
  const tr = btn.closest('tr[data-lead-id]');
  if (!tr) return;
  const mode = btn.getAttribute('data-quick');
  openQuickModal(mode, tr);
});

quickLogSave.addEventListener('click', async () => {
  quickLogError.classList.add('d-none');
  const mode = quickLogForm.mode.value;
  const leadId = quickLogForm.lead_id.value;
  const activityId = quickLogForm.activity_id.value;
  const status = quickLogForm.status.value;
  let comment = quickLogForm.comment.value.trim();
  const nextFollowUpAt = quickLogForm.next_follow_up_at.value;

  if (!leadId) return;
  if (!status) return;
  if (!comment) comment = `Status updated to ${status}.`;

  const payload = new URLSearchParams();
  payload.set('csrf_token', quickLogToken);
  payload.set('action', mode === 'edit' ? 'edit_activity' : 'add_activity');
  payload.set('lead_id', leadId);
  payload.set('status', status);
  payload.set('comment', comment);
  if (nextFollowUpAt) payload.set('next_follow_up_at', nextFollowUpAt);
  if (mode === 'edit') payload.set('activity_id', activityId);

  quickLogSave.disabled = true;
  try {
    const res = await fetch('activity', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: payload.toString()
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) {
      throw new Error((data && data.error) ? data.error : 'Failed to save');
    }
    const row = document.querySelector(`tr[data-lead-id="${data.data.lead_id}"]`);
    if (row) {
      row.dataset.status = data.data.status || '';
      row.dataset.nextFollowUpAt = data.data.next_follow_up_at || '';
      row.dataset.lastActivityAt = data.data.last_activity_at || '';
      row.dataset.lastActivityId = data.data.last_activity_id || '';
      row.dataset.lastComment = data.data.last_comment || '';

      const badge = row.querySelector('td:nth-child(2) .badge');
      if (badge) {
        badge.className = 'badge ' + statusBadgeClass(data.data.status || '');
        badge.textContent = data.data.status || '';
      }

      const nextTd = row.querySelector('td:nth-child(6)');
      if (nextTd) {
        if (data.data.next_follow_up_at) {
          const nfa = data.data.next_follow_up_at;
          const d = new Date(nfa.replace(' ', 'T'));
          const isClosed = (data.data.status === 'Closed Won' || data.data.status === 'Closed Lost');
          const isOverdue = !isClosed && !isNaN(d.getTime()) && d.getTime() < Date.now();
          const isToday = !isClosed && !isNaN(d.getTime()) && d.toDateString() === (new Date()).toDateString();
          const cls = isOverdue ? 'bg-danger-subtle text-danger' : (isToday ? 'bg-warning-subtle text-warning' : 'bg-light text-dark');
          nextTd.innerHTML = `<span class="badge border ${cls}">${formatDt(nfa)}</span>`;
        } else {
          nextTd.innerHTML = '<span class="text-muted">—</span>';
        }
      }

      const touchTd = row.querySelector('td:nth-child(7)');
      if (touchTd) {
        touchTd.innerHTML = data.data.last_activity_at ? formatDt(data.data.last_activity_at) : '<span class="text-muted">—</span>';
      }

      const noteTd = row.querySelector('td:nth-child(8)');
      if (noteTd) {
        const lc = (data.data.last_comment || '').trim();
        if (lc) {
          const esc = lc.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
          noteTd.innerHTML = `<span class="d-inline-block text-truncate" style="max-width: 260px;" title="${esc}">${esc}</span>`;
        } else {
          noteTd.innerHTML = '<span class="text-muted">—</span>';
        }
      }

      const updTd = row.querySelector('td:nth-child(9)');
      if (updTd) {
        updTd.textContent = data.data.updated_at ? formatDt(data.data.updated_at) : '';
      }

    }
    bootstrap.Modal.getOrCreateInstance(quickLogModalEl).hide();
  } catch (err) {
    quickLogError.textContent = err.message || 'Failed to save';
    quickLogError.classList.remove('d-none');
  } finally {
    quickLogSave.disabled = false;
  }
});
</script>
