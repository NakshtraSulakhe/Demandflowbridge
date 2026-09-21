<?php
header('Location: ../qa/dashboard');
exit;
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','qa','qa_agent','qa_manager','qa_director']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

try {
  $now = new DateTime();
  $todayStart = (clone $now)->setTime(0, 0, 0)->format('Y-m-d H:i:s');
  $todayEnd = (clone $now)->setTime(23, 59, 59)->format('Y-m-d H:i:s');
  $monthStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
  $monthEnd = (clone $now)->modify('last day of this month')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
} catch (Throwable $e) {
  $todayStart = date('Y-m-d 00:00:00');
  $todayEnd = date('Y-m-d 23:59:59');
  $monthStart = date('Y-m-01 00:00:00');
  $monthEnd = date('Y-m-t 23:59:59');
}

$visible = getQaVisibleCampaignIdsForUser($userId, $role);
$visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
$stats = getQaStatsScoped($visible, $todayStart, $todayEnd);
$conn = getDbConnection();

$month = ['reviewed' => 0, 'pass' => 0, 'fail' => 0];
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT
      SUM(CASE WHEN qa_updated_at BETWEEN ? AND ? AND qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS reviewed,
      SUM(CASE WHEN qa_updated_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS pass,
      SUM(CASE WHEN qa_updated_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS fail
    FROM leads
  ");
  if ($stmt) {
    $stmt->bind_param('ssssss', $monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  } else {
    $row = null;
  }
} else {
  $ids = array_keys($visible);
  $row = null;
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT
        SUM(CASE WHEN qa_updated_at BETWEEN ? AND ? AND qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS reviewed,
        SUM(CASE WHEN qa_updated_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS pass,
        SUM(CASE WHEN qa_updated_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS fail
      FROM leads
      WHERE campaign_id IN ($in)
    ");
    $types = 'ssssss' . str_repeat('i', count($ids));
    $params = [$monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd, ...$ids];
    if ($stmt) {
      mysqliBindParams($stmt, $types, $params);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
    }
  }
}
if ($row) {
  $month['reviewed'] = (int)($row['reviewed'] ?? 0);
  $month['pass'] = (int)($row['pass'] ?? 0);
  $month['fail'] = (int)($row['fail'] ?? 0);
}

$recentReviewed = [];
if ($visible === null) {
  $rs = $conn->query("
    SELECT l.id, l.lead_id, l.company_name, l.qa_status, l.qa_updated_at,
           u.full_name AS reviewer_name, u.role AS reviewer_role, u.job_title AS reviewer_job_title,
           c.name AS campaign_name
    FROM leads l
    LEFT JOIN users u ON u.id = l.qa_reviewed_by
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    WHERE l.qa_status IN ('Qualified','Disqualified') AND l.qa_updated_at IS NOT NULL
    ORDER BY l.qa_updated_at DESC
    LIMIT 10
  ");
  if ($rs) $recentReviewed = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT l.id, l.lead_id, l.company_name, l.qa_status, l.qa_updated_at,
             u.full_name AS reviewer_name, u.role AS reviewer_role, u.job_title AS reviewer_job_title,
             c.name AS campaign_name
      FROM leads l
      LEFT JOIN users u ON u.id = l.qa_reviewed_by
      LEFT JOIN campaigns c ON c.id = l.campaign_id
      WHERE l.qa_status IN ('Qualified','Disqualified') AND l.qa_updated_at IS NOT NULL
        AND l.campaign_id IN ($in)
      ORDER BY l.qa_updated_at DESC
      LIMIT 10
    ");
    if ($stmt) {
      $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
      $stmt->execute();
      $recentReviewed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
    }
  }
}

$pending = [];
if ($visible === null) {
  $rs = $conn->query("
    SELECT l.id, l.lead_id, l.company_name, l.created_at,
           c.name AS campaign_name,
           u.full_name AS agent_name, u.role AS agent_role, u.job_title AS agent_job_title
    FROM leads l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN users u ON u.id = l.agent_id
    WHERE l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened')
    ORDER BY l.created_at DESC
    LIMIT 10
  ");
  if ($rs) $pending = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT l.id, l.lead_id, l.company_name, l.created_at,
             c.name AS campaign_name,
             u.full_name AS agent_name, u.role AS agent_role, u.job_title AS agent_job_title
      FROM leads l
      LEFT JOIN campaigns c ON c.id = l.campaign_id
      LEFT JOIN users u ON u.id = l.agent_id
      WHERE (l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened'))
        AND l.campaign_id IN ($in)
      ORDER BY l.created_at DESC
      LIMIT 10
    ");
    if ($stmt) {
      $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
      $stmt->execute();
      $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
    }
  }
}

$campaignWorkload = [];
$reviewerPerf = [];
$approvalRatio = ['qualified' => 0, 'disqualified' => 0];

if ($visible === null) {
  $rs = $conn->query("
    SELECT c.id, c.name, d.code,
           SUM(CASE WHEN l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) AS pending,
           SUM(CASE WHEN l.qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS reviewed,
           SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
           SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
    FROM campaigns c
    JOIN campaign_details d ON d.campaign_id = c.id
    LEFT JOIN leads l ON l.campaign_id = c.id
    GROUP BY c.id, c.name, d.code
    ORDER BY pending DESC, reviewed DESC
    LIMIT 20
  ");
  if ($rs) $campaignWorkload = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT c.id, c.name, d.code,
             SUM(CASE WHEN l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) AS pending,
             SUM(CASE WHEN l.qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS reviewed,
             SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
             SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
      FROM campaigns c
      JOIN campaign_details d ON d.campaign_id = c.id
      LEFT JOIN leads l ON l.campaign_id = c.id
      WHERE c.id IN ($in)
      GROUP BY c.id, c.name, d.code
      ORDER BY pending DESC, reviewed DESC
      LIMIT 20
    ");
    if ($stmt) {
      $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
      $stmt->execute();
      $campaignWorkload = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
    }
  }
}

$qaRoles = ['qa_director','qa_manager','qa_agent','qa'];
$inQa = implode(',', array_fill(0, count($qaRoles), '?'));
if ($visible === null) {
  $stmt = $conn->prepare("
    SELECT u.id, u.full_name,
           SUM(CASE WHEN l.qa_updated_at BETWEEN ? AND ? AND l.qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS reviewed,
           SUM(CASE WHEN l.qa_updated_at BETWEEN ? AND ? AND l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
           SUM(CASE WHEN l.qa_updated_at BETWEEN ? AND ? AND l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
    FROM users u
    LEFT JOIN leads l ON l.qa_reviewed_by = u.id
    WHERE u.is_active = 1 AND u.role IN ($inQa)
    GROUP BY u.id, u.full_name
    ORDER BY reviewed DESC
    LIMIT 15
  ");
  $types = 'ssssss' . str_repeat('s', count($qaRoles));
  $params = [$monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd, ...$qaRoles];
  if ($stmt) {
    mysqliBindParams($stmt, $types, $params);
  }
} else {
  $ids = array_keys($visible);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
      SELECT u.id, u.full_name,
             SUM(CASE WHEN l.qa_updated_at BETWEEN ? AND ? AND l.qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS reviewed,
             SUM(CASE WHEN l.qa_updated_at BETWEEN ? AND ? AND l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
             SUM(CASE WHEN l.qa_updated_at BETWEEN ? AND ? AND l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
      FROM users u
      LEFT JOIN leads l ON l.qa_reviewed_by = u.id AND l.campaign_id IN ($in)
      WHERE u.is_active = 1 AND u.role IN ($inQa)
      GROUP BY u.id, u.full_name
      ORDER BY reviewed DESC
      LIMIT 15
    ");
    $types = 'ssssss' . str_repeat('i', count($ids)) . str_repeat('s', count($qaRoles));
    $params = [$monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd, ...$ids, ...$qaRoles];
    if ($stmt) {
      mysqliBindParams($stmt, $types, $params);
    }
  } else {
    $stmt = null;
  }
}
if (isset($stmt) && $stmt) {
  $stmt->execute();
  $reviewerPerf = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();
}

foreach ($campaignWorkload as $r) {
  $approvalRatio['qualified'] += (int)($r['qualified'] ?? 0);
  $approvalRatio['disqualified'] += (int)($r['disqualified'] ?? 0);
}
?>
<?php $pageTitle = 'Quality Assurance Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Quality Assurance Dashboard</h3>
      <div class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['role'] ?? ''), ($user['job_title'] ?? ''))); ?></div>
    </div>
  </div>

  <?php if ($visible !== null && empty($visible)): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body d-flex align-items-start justify-content-between gap-3">
        <div>
          <div class="fw-semibold mb-1">No Campaigns Assigned</div>
          <div class="text-muted small">You currently have no campaigns allocated for QA review.</div>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="qa-request-assignment.php">
          <i class="bi bi-send me-1"></i> Request Assignment
        </a>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-hourglass-split fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Pending QA</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format((int)($stats['total_pending'] ?? 0)); ?></h3>
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
            <h6 class="card-subtitle text-muted mb-0">Reviewed Today</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['today_reviewed'] ?? 0)); ?></h3>
          <div class="text-muted small mt-1">
            Pass: <?php echo number_format((int)($stats['today_pass'] ?? 0)); ?> · Fail: <?php echo number_format((int)($stats['today_fail'] ?? 0)); ?>
          </div>
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
            <h6 class="card-subtitle text-muted mb-0">Reviewed This Month</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($month['reviewed']); ?></h3>
          <div class="text-muted small mt-1">
            Pass: <?php echo number_format($month['pass']); ?> · Fail: <?php echo number_format($month['fail']); ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
              <i class="bi bi-check2-square fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Reviewed Total</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['total_reviewed'] ?? 0)); ?></h3>
          <div class="text-muted small mt-1">
            Pass: <?php echo number_format((int)($stats['total_pass'] ?? 0)); ?> · Fail: <?php echo number_format((int)($stats['total_fail'] ?? 0)); ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header fw-semibold">Campaign QA Workload</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Campaign</th>
                <th>Code</th>
                <th class="text-center">Pending</th>
                <th class="text-center">Qualified</th>
                <th class="text-center">Disqualified</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($campaignWorkload)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No campaigns available.</td></tr>
              <?php else: ?>
                <?php foreach ($campaignWorkload as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars((string)($r['code'] ?? '')); ?></td>
                    <td class="text-center"><?php echo (int)($r['pending'] ?? 0); ?></td>
                    <td class="text-center text-success"><?php echo (int)($r['qualified'] ?? 0); ?></td>
                    <td class="text-center text-danger"><?php echo (int)($r['disqualified'] ?? 0); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100">
        <?php
          $tq = (int)($approvalRatio['qualified'] ?? 0);
          $td = (int)($approvalRatio['disqualified'] ?? 0);
          $tr = $tq + $td;
          $qPct = $tr > 0 ? round(($tq / $tr) * 100) : 0;
          $dPct = $tr > 0 ? (100 - $qPct) : 0;
        ?>
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">QA Productivity (This Month)</div>
          <div class="small text-muted"><?php echo $qPct; ?>% / <?php echo $dPct; ?>%</div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Reviewer</th>
                <th class="text-center">Reviewed</th>
                <th class="text-center">Qualified</th>
                <th class="text-center">Disqualified</th> 
              </tr>
            </thead>
            <tbody>
              <?php if (empty($reviewerPerf)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No QA activity yet.</td></tr>
              <?php else: ?>
                <?php foreach ($reviewerPerf as $r): ?>
                  <tr>
                    <td class="text-muted small"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></td>
                    <td class="text-center"><?php echo (int)($r['reviewed'] ?? 0); ?></td>
                    <td class="text-center text-success"><?php echo (int)($r['qualified'] ?? 0); ?></td>
                    <td class="text-center text-danger"><?php echo (int)($r['disqualified'] ?? 0); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Recent Reviewed</div>
          <a class="small text-decoration-none" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/qa/audit">Open QA Processing</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Lead</th>
                <th>Company</th>
                <th>Status</th>
                <th>Campaign</th>
                <th>Reviewer</th>
                <th class="text-muted">When</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentReviewed)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No reviewed leads yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recentReviewed as $l): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars($l['lead_id'] ?? (string)($l['id'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($l['company_name'] ?? ''); ?></td>
                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($l['qa_status'] ?? ''); ?></span></td>
                    <td class="text-muted small"><?php echo htmlspecialchars($l['campaign_name'] ?? ''); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($l['reviewer_name'] ?? ''), ($l['reviewer_role'] ?? ''), ($l['reviewer_job_title'] ?? ''))); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$l['qa_updated_at']))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header fw-semibold">Newest Pending QA</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Lead</th>
                <th>Company</th>
                <th>Agent</th>
                <th class="text-muted">Created</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($pending)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No pending leads.</td></tr>
              <?php else: ?>
                <?php foreach ($pending as $l): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars($l['lead_id'] ?? (string)($l['id'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($l['company_name'] ?? ''); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($l['agent_name'] ?? ''), ($l['agent_role'] ?? ''), ($l['agent_job_title'] ?? ''))); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$l['created_at']))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/qa/audit">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">QA Processing</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-shield-check"></i></div>
            </div>
            <div class="text-muted small mt-2">Review and qualify/disqualify leads.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/list">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">All Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-clipboard-data"></i></div>
            </div>
            <div class="text-muted small mt-2">Search, filter, and audit lead records.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/export.php">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">Export Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-download"></i></div>
            </div>
            <div class="text-muted small mt-2">Export filtered lead data.</div>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
