<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$todayRange = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => $opsNow->format('Y-m-d 00:00:00'), 'end' => $opsNow->format('Y-m-d 23:59:59')];
$monthRange = function_exists('opsMonthRange') ? opsMonthRange() : ['start' => $opsNow->format('Y-m-01 00:00:00'), 'end' => $opsNow->format('Y-m-t 23:59:59')];
$todayStart = (string)($todayRange['start'] ?? '1970-01-01 00:00:00');
$todayEnd = (string)($todayRange['end'] ?? '2999-12-31 23:59:59');
$monthStart = (string)($monthRange['start'] ?? '1970-01-01 00:00:00');
$monthEnd = (string)($monthRange['end'] ?? '2999-12-31 23:59:59');

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? $monthStart);
$rangeEnd = (string)($range['end'] ?? $monthEnd);
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';

$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts($userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];

$visible = getOpsVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
$campaignIds = $visible === null ? null : array_keys($visible);
$stats = getFormFillerDashboardStats($rangeStart, $rangeEnd, $campaignIds);

$attendance = ['status' => '', 'work_minutes' => 0, 'late_minutes' => 0];
$todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$attendanceSnapshot = hrGetAttendanceSnapshot((int)$userId, $todayDate, true);
$attendance['status'] = (string)($attendanceSnapshot['status'] ?? '');
$attendance['work_minutes'] = (int)($attendanceSnapshot['display_work_minutes'] ?? 0);
$attendance['late_minutes'] = (int)($attendanceSnapshot['late_minutes'] ?? 0);

$conn = getDbConnection();
$recentPending = [];
$emailQueue = ['pending' => 0, 'sent' => 0, 'delivered' => 0, 'bounced' => 0];
if ($campaignIds === null || !empty($campaignIds)) {
  $where = ["l.qa_status = 'Qualified'"];
  $params = [];
  $types = '';
  if (is_array($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $where[] = "l.campaign_id IN ($in)";
    $params = array_merge($params, $campaignIds);
    $types .= str_repeat('i', count($campaignIds));
  }
  $whereSql = implode(' AND ', $where);

  $sqlQ = "SELECT
      SUM(CASE WHEN l.email_status IS NULL OR l.email_status = '' OR l.email_status = 'Pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN l.email_status = 'Sent' THEN 1 ELSE 0 END) AS sent,
      SUM(CASE WHEN l.email_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
      SUM(CASE WHEN l.email_status = 'Bounced' THEN 1 ELSE 0 END) AS bounced
    FROM leads l
    WHERE $whereSql";
  $stmtQ = $conn->prepare($sqlQ);
  if ($stmtQ) {
    if ($types) $stmtQ->bind_param($types, ...$params);
    $stmtQ->execute();
    $emailQueue = $stmtQ->get_result()->fetch_assoc() ?: $emailQueue;
    $stmtQ->close();
    foreach ($emailQueue as $k => $v) $emailQueue[$k] = (int)$v;
  }

  $where2 = ["l.form_done = 'No'", "l.qa_status = 'Qualified'"];
  $params2 = [];
  $types2 = '';
  if (is_array($campaignIds)) {
    $in2 = implode(',', array_fill(0, count($campaignIds), '?'));
    $where2[] = "l.campaign_id IN ($in2)";
    $params2 = array_merge($params2, $campaignIds);
    $types2 .= str_repeat('i', count($campaignIds));
  }
  $whereSql2 = implode(' AND ', $where2);
  $sqlR = "SELECT l.*, c.name AS campaign_name, u.full_name AS agent_name, u.role AS agent_role, u.job_title AS agent_job_title
      FROM leads l
      LEFT JOIN campaigns c ON l.campaign_id = c.id
      LEFT JOIN users u ON l.agent_id = u.id
      WHERE $whereSql2
      ORDER BY $leadCreatedExpr DESC
      LIMIT 10";
  $stmtR = $conn->prepare($sqlR);
  if ($stmtR) {
    if ($types2) $stmtR->bind_param($types2, ...$params2);
    $stmtR->execute();
    $rs = $stmtR->get_result();
    while ($lead = $rs->fetch_assoc()) {
      $recentPending[] = enrichLeadRow($lead);
    }
    $stmtR->close();
  }
}
?>
<?php $pageTitle = 'Email Marketing Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 dashboard-lite-workspace">
  <div class="dashboard-lite-header dashboard-lite-hero mb-3">
    <div>
      <h3 class="mb-1">Email Marketing Dashboard</h3>
      <div class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['role'] ?? ''), ($user['job_title'] ?? ''))); ?></div>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3 shared-dashboard-filter dashboard-lite-filter" method="get">
    <div class="col-md-3">
      <label class="form-label small text-muted">Range</label>
      <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
        <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
        <option value="this_month" <?php echo $preset==='this_month'?'selected':''; ?>>This Month</option>
        <option value="overall" <?php echo $preset==='overall'?'selected':''; ?>>Overall</option>
        <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom Range</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted">Start</label>
      <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($sd); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted">End</label>
      <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($ed); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
    </div>
    <div class="col-md-3 d-flex justify-content-end">
      <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
  </form>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-hourglass-split fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Pending Forms (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format((int)($stats['pending_form_filling'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
              <i class="bi bi-check-all fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Forms Filled (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0 text-success"><?php echo number_format((int)($stats['filled_forms'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
              <i class="bi bi-calendar-day fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Qualified (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['qualified_leads'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-secondary-subtle text-secondary rounded-3 p-2 me-3">
              <i class="bi bi-calendar3 fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Filled (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['filled_forms'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>">
        <div class="card stats-card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
                <i class="bi bi-kanban fs-4"></i>
              </div>
              <h6 class="card-subtitle text-muted mb-0">My Tasks</h6>
            </div>
            <h3 class="card-title mb-0"><?php echo number_format((int)$taskWidget['pending']); ?></h3>
            <div class="text-muted small mt-1">Due today: <?php echo number_format((int)$taskWidget['due_today']); ?> · Overdue: <?php echo number_format((int)$taskWidget['overdue']); ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-3">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/hr/attendance'); ?>">
        <div class="card stats-card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
                <i class="bi bi-calendar-check fs-4"></i>
              </div>
              <h6 class="card-subtitle text-muted mb-0">Attendance (Today)</h6>
            </div>
            <h3 class="card-title mb-0"><?php echo number_format((int)floor(((int)$attendance['work_minutes']) / 60)) . ':' . str_pad((string)(((int)$attendance['work_minutes']) % 60), 2, '0', STR_PAD_LEFT); ?></h3>
            <div class="text-muted small mt-1"><?php echo htmlspecialchars($attendance['status'] !== '' ? $attendance['status'] : 'Not started'); ?> · Late: <?php echo number_format((int)$attendance['late_minutes']); ?> min</div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
              <i class="bi bi-file-earmark-text fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Total Leads (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['total_leads'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
              <i class="bi bi-shield-check fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Qualified Leads (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0 text-success"><?php echo number_format((int)($stats['qualified_leads'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-clock-history fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">QA Pending (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format((int)($stats['qa_pending'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Email Queue</div>
      <a class="small text-decoration-none" href="../leads/email">Open Email Leads</a>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="text-muted small">Pending</div>
          <div class="h4 mb-0 text-warning"><?php echo number_format((int)($emailQueue['pending'] ?? 0)); ?></div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Sent</div>
          <div class="h4 mb-0"><?php echo number_format((int)($emailQueue['sent'] ?? 0)); ?></div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Delivered</div>
          <div class="h4 mb-0 text-success"><?php echo number_format((int)($emailQueue['delivered'] ?? 0)); ?></div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Bounced</div>
          <div class="h4 mb-0 text-danger"><?php echo number_format((int)($emailQueue['bounced'] ?? 0)); ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Assigned Leads – Fill Remaining Fields</div>
      <a class="small text-decoration-none" href="../leads/email">Open Email Leads</a>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Lead</th>
            <th>Company</th>
            <th>Campaign</th>
            <th>Agent</th>
            <th class="text-muted">Created</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentPending)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No pending items found.</td></tr>
          <?php else: ?>
            <?php foreach ($recentPending as $l): ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars($l['lead_id'] ?? (string)($l['id'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($l['company_name'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($l['campaign_name'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($l['agent_name'] ?? ''), ($l['agent_role'] ?? ''), ($l['agent_job_title'] ?? ''))); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$l['created_at']))); ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-light border" href="../leads/get_lead_details?id=<?php echo (int)($l['id'] ?? 0); ?>&edit=1&post_to=email-leads" target="_blank">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/email">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">Email Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-envelope-at"></i></div>
            </div>
            <div class="text-muted small mt-2">Update email status and fill missing lead fields.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/my">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">My Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-list-check"></i></div>
            </div>
            <div class="text-muted small mt-2">Your recent leads and form progress.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../chat/chat.php">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">Chat</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-chat-dots"></i></div>
            </div>
            <div class="text-muted small mt-2">Coordinate with Operations and QA.</div>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
