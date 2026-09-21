<?php
/**
 * Agent Dashboard
 * 
 * Provides a focused analytics dashboard for agents with their lead statistics and performance metrics
 */

// Include authentication system
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Ensure user is logged in and has appropriate role
requireRole(['agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);

// Get current user
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$opsMonth = function_exists('opsMonthRange') ? opsMonthRange() : ['start' => $opsNow->format('Y-m-01 00:00:00'), 'end' => $opsNow->format('Y-m-t 23:59:59')];

// Get statistics for this agent
$agentStats = getAgentDashboardStats($userId);

// Range filter
$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? ($opsMonth['start'] ?? '1970-01-01 00:00:00'));
$rangeEnd = (string)($range['end'] ?? ($opsMonth['end'] ?? '2999-12-31 23:59:59'));
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$leadCreatedExprBare = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('') : 'created_at';

// Range stats
$conn = getDbConnection();
$rangeStats = ['total' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0];
$stmt = $conn->prepare("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
      SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
      SUM(CASE WHEN (qa_status IS NULL OR qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending
    FROM leads
    WHERE agent_id = ? AND $leadCreatedExprBare BETWEEN ? AND ?
");
if ($stmt) {
    $stmt->bind_param('iss', $userId, $rangeStart, $rangeEnd);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $rangeStats['total'] = (int)($r['total'] ?? 0);
    $rangeStats['qualified'] = (int)($r['qualified'] ?? 0);
    $rangeStats['disqualified'] = (int)($r['disqualified'] ?? 0);
    $rangeStats['pending'] = (int)($r['pending'] ?? 0);
}

// Tasks + attendance
$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts((int)$userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$attendance = ['status' => '', 'work_minutes' => 0, 'late_minutes' => 0];
$todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$attendanceSnapshot = hrGetAttendanceSnapshot((int)$userId, $todayDate, true);
$attendance['status'] = (string)($attendanceSnapshot['status'] ?? '');
$attendance['work_minutes'] = (int)($attendanceSnapshot['display_work_minutes'] ?? 0);
$attendance['late_minutes'] = (int)($attendanceSnapshot['late_minutes'] ?? 0);

// Attendance summary for selected range
$attendanceRange = ['present_days' => 0, 'late_days' => 0, 'avg_work_minutes' => 0];
$rangeStartDateOnly = substr($rangeStart, 0, 10);
$rangeEndDateOnly = substr($rangeEnd, 0, 10);
$attendanceAggregate = hrGetAttendanceAggregate([(int)$userId], $todayDate, $rangeStartDateOnly, $rangeEndDateOnly);
$attendanceRange = array_merge($attendanceRange, (array)($attendanceAggregate['range'] ?? []));
$attendanceRange['present_days'] = (float)($attendanceRange['present_days'] ?? 0);
$attendanceRange['late_days'] = (int)($attendanceRange['late_days'] ?? 0);
$attendanceRange['avg_work_minutes'] = (int)($attendanceRange['avg_work_minutes'] ?? 0);

// Campaign performance for current user
$campaignPerformanceRows = [];
$campaignPerformanceChart = ['labels' => [], 'qualified' => [], 'disqualified' => []];
$stmt = $conn->prepare("
    SELECT
      l.campaign_id,
      COALESCE(NULLIF(TRIM(c.name), ''), CONCAT('Campaign #', l.campaign_id)) AS campaign_name,
      COUNT(*) AS total_leads,
      SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
      SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified
    FROM leads l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    WHERE l.agent_id = ? AND $leadCreatedExpr BETWEEN ? AND ?
    GROUP BY l.campaign_id, c.name
    ORDER BY total_leads DESC
    LIMIT 8
");
if ($stmt) {
    $stmt->bind_param('iss', $userId, $rangeStart, $rangeEnd);
    $stmt->execute();
    $campaignPerformanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($campaignPerformanceRows as &$row) {
        $row['total_leads'] = (int)($row['total_leads'] ?? 0);
        $row['qualified'] = (int)($row['qualified'] ?? 0);
        $row['disqualified'] = (int)($row['disqualified'] ?? 0);
        $row['scored'] = $row['qualified'] + $row['disqualified'];
        $row['qualification_rate'] = $row['total_leads'] > 0 ? round(($row['qualified'] / $row['total_leads']) * 100, 1) : 0.0;
        $row['dq_rate'] = $row['scored'] > 0 ? round(($row['disqualified'] / $row['scored']) * 100, 1) : 0.0;
        $campaignPerformanceChart['labels'][] = (string)($row['campaign_name'] ?? '');
        $campaignPerformanceChart['qualified'][] = $row['qualification_rate'];
        $campaignPerformanceChart['disqualified'][] = $row['dq_rate'];
    }
    unset($row);
}

// Earnings calculation
try {
    $curYear = (int)$opsNow->format('Y');
    $curMonth = (int)$opsNow->format('m');
    $prev = $opsNow->modify('first day of last month');
    $prevYear = (int)$prev->format('Y');
    $prevMonth = (int)$prev->format('m');

    $curStats = getAgentMonthlyStats($userId, $curYear, $curMonth);
    $prevStats = getAgentMonthlyStats($userId, $prevYear, $prevMonth);

    $curDaily = $curStats['incentives']['daily_total'] ?? 0;
    $curMonthly = $curStats['incentives']['monthly_bonus'] ?? 0;
    $curTotal = $curStats['incentives']['total'] ?? ($curDaily + $curMonthly);
    $prevTotal = $prevStats['incentives']['total'] ?? (($prevStats['incentives']['daily_total'] ?? 0) + ($prevStats['incentives']['monthly_bonus'] ?? 0));
} catch (Throwable $e) {
    $curDaily = $curMonthly = $curTotal = $prevTotal = 0;
}

// Performance calculation for current user
$perfCfg = function_exists('funneltrackGetConfig') ? funneltrackGetConfig() : [];
$myStats = ['overall_percent' => 0.0, 'range_percent' => 0.0];
$myDailyTarget = 0;
if (function_exists('funneltrackComputeAgentMonthSummary')) {
    $monthSummary = funneltrackComputeAgentMonthSummary($userId, $curYear, $curMonth, $perfCfg);
    $myStats = [
        'overall_percent' => (float)($monthSummary['productivity_pct'] ?? 0.0),
        'range_percent' => (float)($monthSummary['productivity_pct'] ?? 0.0),
    ];
    $myDailyTarget = (int)($monthSummary['targets']['daily'] ?? 0);
}

// Calculate campaign performance totals for rate calculations
$campaignPerfTotals = ['total_leads' => 0, 'qualified' => 0, 'disqualified' => 0, 'scored' => 0];
foreach ($campaignPerformanceRows as $campaignPerfRow) {
    $campaignPerfTotals['total_leads'] += (int)($campaignPerfRow['total_leads'] ?? 0);
    $campaignPerfTotals['qualified'] += (int)($campaignPerfRow['qualified'] ?? 0);
    $campaignPerfTotals['disqualified'] += (int)($campaignPerfRow['disqualified'] ?? 0);
    $campaignPerfTotals['scored'] += (int)(($campaignPerfRow['qualified'] ?? 0) + ($campaignPerfRow['disqualified'] ?? 0));
}

$formatHoursMinutes = static function(int $minutes): string {
    return number_format((int)floor($minutes / 60)) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
};
?>
<?php $pageTitle = 'Agent Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
    .agent-soft-card {
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 1rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        box-shadow: 0 0.35rem 1rem rgba(15, 23, 42, 0.06);
    }
    :root[data-theme="dark"] .agent-soft-card,
    body[data-theme="dark"] .agent-soft-card,
    [data-bs-theme="dark"] .agent-soft-card {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.03) 0%, rgba(255, 255, 255, 0.01) 100%);
        border-color: var(--app-border);
        box-shadow: 0 0.35rem 1rem rgba(0, 0, 0, 0.3);
    }
</style>

    <div class="container-fluid px-0 dashboard-lite-workspace">
        <div class="dashboard-lite-header dashboard-lite-hero mb-3">
            <div>
                <h2 class="h3 mb-1">Agent Dashboard</h2>
                <div class="text-muted small">Welcome back, <?php echo htmlspecialchars(formatUserNameWithRole(($currentUser['full_name'] ?? 'User'), ($currentUser['role'] ?? ''), ($currentUser['job_title'] ?? ''))); ?></div>
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
        
        <!-- Stats Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
                                <i class="bi bi-people fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Leads (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
                        </div>
                        <h3 class="card-title mb-0"><?php echo number_format((int)$rangeStats['total']); ?></h3>
                        <div class="text-muted small mt-1">All time: <?php echo number_format((int)($agentStats['total_leads'] ?? 0)); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
                                <i class="bi bi-check-circle fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Qualified (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
                        </div>
                        <h3 class="card-title mb-0 text-success"><?php echo number_format((int)$rangeStats['qualified']); ?></h3>
                        <div class="text-muted small mt-1">All time: <?php echo number_format((int)($agentStats['qualified_leads'] ?? 0)); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-danger-subtle text-danger rounded-3 p-2 me-3">
                                <i class="bi bi-x-circle fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Disqualified (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
                        </div>
                        <h3 class="card-title mb-0 text-danger"><?php echo number_format((int)$rangeStats['disqualified']); ?></h3>
                        <div class="text-muted small mt-1">All time: <?php echo number_format((int)($agentStats['disqualified_leads'] ?? 0)); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
                                <i class="bi bi-clock fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Pending QA (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
                        </div>
                        <h3 class="card-title mb-0 text-warning"><?php echo number_format((int)$rangeStats['pending']); ?></h3>
                        <div class="text-muted small mt-1">Backlog: <?php echo number_format((int)($agentStats['pending_leads'] ?? 0)); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
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
                            <div class="text-muted small mt-1">Due: <?php echo number_format((int)$taskWidget['due_today']); ?> · Overdue: <?php echo number_format((int)$taskWidget['overdue']); ?></div>
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

        <div class="row g-4 mb-4">
            <!-- Today's Performance -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Today's Performance</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <h4 class="mb-1"><?php echo number_format($agentStats['today_leads']); ?></h4>
                                <small class="text-muted">Total</small>
                            </div>
                            <div class="col-4">
                                <h4 class="mb-1 text-success"><?php echo number_format($agentStats['today_qualified']); ?></h4>
                                <small class="text-muted">Qualified</small>
                            </div>
                            <div class="col-4">
                                <h4 class="mb-1 text-danger"><?php echo number_format($agentStats['today_disqualified']); ?></h4>
                                <small class="text-muted">Disqualified</small>
                            </div>
                        </div>
                        <hr class="my-4">
                        <div class="d-grid">
                            <a href="../leads/agent.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>Generate New Lead
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Earnings Overview -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Earnings Overview</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Current Month Total</div>
                                    <div class="h4 mb-0 text-primary">Rs. <?php echo number_format($curTotal); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Previous Month</div>
                                    <div class="h4 mb-0">Rs. <?php echo number_format($prevTotal); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Today's Earnings</div>
                                    <div class="h5 mb-0">Rs. <?php echo number_format($curDaily); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Monthly Bonus</div>
                                    <div class="h5 mb-0 text-success">Rs. <?php echo number_format($curMonthly); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Attendance Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">Today Status</div>
                                    <div class="h5 mb-0"><?php echo htmlspecialchars($attendance['status'] !== '' ? $attendance['status'] : 'Not started'); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">Today Work Hours</div>
                                    <div class="h5 mb-0"><?php echo htmlspecialchars($formatHoursMinutes((int)$attendance['work_minutes'])); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">Late Minutes</div>
                                    <div class="h5 mb-0 text-warning"><?php echo number_format((int)$attendance['late_minutes']); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">Present Days</div>
                                    <div class="h5 mb-0"><?php echo number_format((int)$attendanceRange['present_days']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($rangeLabel); ?></div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="agent-soft-card p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small mb-1">Average Work Hours</div>
                                            <div class="h5 mb-0"><?php echo htmlspecialchars($formatHoursMinutes((int)$attendanceRange['avg_work_minutes'])); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="text-muted small mb-1">Late Days</div>
                                            <div class="h5 mb-0 text-warning"><?php echo number_format((int)$attendanceRange['late_days']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Performance Cards</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">Qualification Rate</div>
                                    <div class="h5 mb-0 text-success"><?php echo number_format($campaignPerfTotals['total_leads'] > 0 ? (($campaignPerfTotals['qualified'] / $campaignPerfTotals['total_leads']) * 100) : 0, 1); ?>%</div>
                                    <div class="small text-muted">Quality % of total generated</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">DQ Rate</div>
                                    <div class="h5 mb-0 text-danger"><?php echo number_format($campaignPerfTotals['scored'] > 0 ? (($campaignPerfTotals['disqualified'] / $campaignPerfTotals['scored']) * 100) : 0, 1); ?>%</div>
                                    <div class="small text-muted">Disqualification % of scored leads</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">Current Month Incentive</div>
                                    <div class="h5 mb-0">Rs. <?php echo number_format((int)$curTotal); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="agent-soft-card p-3 h-100">
                                    <div class="text-muted small mb-1">Current Performance</div>
                                    <div class="h5 mb-0 text-primary"><?php echo number_format((float)($myStats['overall_percent'] ?? 0), 1); ?>%</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="agent-soft-card p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small mb-1">Daily Target</div>
                                            <div class="h5 mb-0"><?php echo number_format((int)$myDailyTarget); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="text-muted small mb-1">Today's Earnings</div>
                                            <div class="h5 mb-0">Rs. <?php echo number_format((int)$curDaily); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Campaign Performance Mix</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="agentCampaignPerfChart" height="220"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">My Campaign Performance</h5>
                <div class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Campaign</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Qualified</th>
                                <th class="text-end">Disqualified</th>
                                <th class="text-end">Qual Rate %</th>
                                <th class="text-end pe-4">DQ Rate %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaignPerformanceRows)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No campaign performance data found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($campaignPerformanceRows as $row): ?>
                                    <tr>
                                        <td class="ps-4 fw-semibold"><?php echo htmlspecialchars((string)($row['campaign_name'] ?? '')); ?></td>
                                        <td class="text-end font-monospace"><?php echo number_format((int)($row['total_leads'] ?? 0)); ?></td>
                                        <td class="text-end font-monospace text-success"><?php echo number_format((int)($row['qualified'] ?? 0)); ?></td>
                                        <td class="text-end font-monospace text-danger"><?php echo number_format((int)($row['disqualified'] ?? 0)); ?></td>
                                        <td class="text-end font-monospace fw-semibold text-success"><?php echo number_format((float)($row['qualification_rate'] ?? 0), 1); ?>%</td>
                                        <td class="text-end pe-4 font-monospace fw-semibold text-danger"><?php echo number_format((float)($row['dq_rate'] ?? 0), 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
    if (!window.Chart) return;
    const perf = <?php echo json_encode($campaignPerformanceChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const el = document.getElementById('agentCampaignPerfChart');
    if (!el || !perf || !Array.isArray(perf.labels)) return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: perf.labels,
            datasets: [
                { label: 'Qualification Rate %', data: perf.qualified || [], backgroundColor: 'rgba(34, 197, 94, 0.75)' },
                { label: 'DQ Rate %', data: perf.disqualified || [], backgroundColor: 'rgba(239, 68, 68, 0.75)' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { ticks: { autoSkip: false, maxRotation: 25, minRotation: 0 } },
                y: { beginAtZero: true, max: 100, ticks: { callback: function(value) { return value + '%'; } } }
            }
        }
    });
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
