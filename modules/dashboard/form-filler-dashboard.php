<?php
/**
 * Form Filler Dashboard
 *
 * Focuses on form-filling KPIs, today/month/total metrics, and quick actions.
 */

// Includes and auth
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Restrict access to form filler role
requireRole(['form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);

// Current user
$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$role = (string)($currentUser['role'] ?? '');

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

$visible = function_exists('getOpsVisibleCampaignIdsForUser') ? getOpsVisibleCampaignIdsForUser($userId, $role) : null;
$visible = function_exists('getTeamVisibleCampaignIdsForUser') ? getTeamVisibleCampaignIdsForUser($userId, $visible) : $visible;
$campaignIds = $visible === null ? null : array_keys($visible);

// Get statistics
$stats = getFormFillerDashboardStats($rangeStart, $rangeEnd, $campaignIds);

$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts($userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$attendance = ['status' => '', 'work_minutes' => 0, 'late_minutes' => 0];
$todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$attendanceSnapshot = hrGetAttendanceSnapshot((int)$userId, $todayDate, true);
$attendance['status'] = (string)($attendanceSnapshot['status'] ?? '');
$attendance['work_minutes'] = (int)($attendanceSnapshot['display_work_minutes'] ?? 0);
$attendance['late_minutes'] = (int)($attendanceSnapshot['late_minutes'] ?? 0);

// Agent-wise summaries (total)
$agentsTotal = getAgentSummary($rangeStart, $rangeEnd, $campaignIds);
// Limit to 5 for dashboard
$agentsTotal = array_slice($agentsTotal, 0, 5);

// Campaign-wise summaries (total)
$campaignsTotal = getCampaignSummary($rangeStart, $rangeEnd, $campaignIds);
// Limit to 5 for dashboard
$campaignsTotal = array_slice($campaignsTotal, 0, 5);

// Recent items needing form fill (Qualified but not filled)
$recentPending = [];
$recentSql = "
    SELECT l.*, c.name AS campaign_name, u.full_name AS agent_name
    FROM leads l
    LEFT JOIN campaigns c ON l.campaign_id = c.id
    LEFT JOIN users u ON l.agent_id = u.id
    WHERE l.form_done = 'No' AND l.qa_status = 'Qualified'
";
$recentParams = [];
$recentTypes = '';
if (is_array($campaignIds)) {
    if (empty($campaignIds)) {
        $recentSql = '';
    } else {
        $in = implode(',', array_fill(0, count($campaignIds), '?'));
        $recentSql .= " AND l.campaign_id IN ($in)";
        foreach ($campaignIds as $id) { $recentParams[] = (int)$id; $recentTypes .= 'i'; }
    }
}
if ($recentSql !== '') {
    $recentSql .= " ORDER BY $leadCreatedExpr DESC LIMIT 10";
    $stmt = $conn->prepare($recentSql);
    if ($stmt) {
        if ($recentTypes) $stmt->bind_param($recentTypes, ...$recentParams);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $lead) $recentPending[] = enrichLeadRow($lead);
    }
}

// Define navbar flag
?>
<?php $pageTitle = 'Form Filler Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

    <div class="container-fluid px-0 dashboard-lite-workspace">
        <div class="dashboard-lite-header dashboard-lite-hero mb-3">
            <div>
                <h2 class="h3 mb-1">Form Filler Dashboard</h2>
                <div class="text-muted small">Welcome back, <?php echo htmlspecialchars($currentUser['full_name']); ?></div>
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
                                <i class="bi bi-file-earmark-text fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Generated (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
                        </div>
                        <h3 class="card-title mb-0"><?php echo number_format((int)$stats['total_leads']); ?></h3>
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
                        <h3 class="card-title mb-0 text-success"><?php echo number_format((int)$stats['filled_forms']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
                                <i class="bi bi-hourglass-split fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Pending Forms (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
                        </div>
                        <h3 class="card-title mb-0 text-warning"><?php echo number_format((int)$stats['pending_form_filling']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
                                <i class="bi bi-shield-check fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">QA Pending (<?php echo htmlspecialchars($rangeLabel); ?>)</h6>
                        </div>
                        <h3 class="card-title mb-0 text-info"><?php echo number_format((int)$stats['qa_pending']); ?></h3>
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
            <!-- Range Overview -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Activity (<?php echo htmlspecialchars($rangeLabel); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Total Leads</span>
                                <span class="fw-bold"><?php echo number_format($stats['total_leads']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Forms Filled</span>
                                <span class="text-success fw-bold"><?php echo number_format($stats['filled_forms']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Pending Forms</span>
                                <span class="text-warning fw-bold"><?php echo number_format($stats['pending_form_filling']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>QA Qualified</span>
                                <span class="text-success fw-bold"><?php echo number_format($stats['qualified_leads']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>QA Disqualified</span>
                                <span class="text-danger fw-bold"><?php echo number_format($stats['disqualified_leads']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Overview -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-light border-0 py-3">
                        <h5 class="mb-0">Progress (<?php echo htmlspecialchars($rangeLabel); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="small text-muted">Form Filling Progress</span>
                                <span class="small fw-bold"><?php echo $stats['total_leads'] > 0 ? round(((int)$stats['filled_forms'] / (int)$stats['total_leads']) * 100) : 0; ?>%</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $stats['total_leads'] > 0 ? (((int)$stats['filled_forms'] / max(1, (int)$stats['total_leads'])) * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Total Generated</div>
                                    <div class="h5 mb-0"><?php echo number_format((int)$stats['total_leads']); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Total Filled</div>
                                    <div class="h5 mb-0 text-success"><?php echo number_format((int)$stats['filled_forms']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Top Agents -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Top Agents (<?php echo htmlspecialchars($rangeLabel); ?>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Agent</th>
                                        <th>Total</th>
                                        <th>Qualified</th>
                                        <th>Pending Fill</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agentsTotal as $a): ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold"><?php echo htmlspecialchars($a['agent']); ?></td>
                                        <td><?php echo $a['total']; ?></td>
                                        <td class="text-success"><?php echo $a['qualified']; ?></td>
                                        <td class="text-warning"><?php echo $a['pending']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Campaigns -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Top Campaigns (<?php echo htmlspecialchars($rangeLabel); ?>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Campaign</th>
                                        <th>Total</th>
                                        <th>Qualified</th>
                                        <th>Pending Fill</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($campaignsTotal as $c): ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold"><?php echo htmlspecialchars($c['campaign']); ?></td>
                                        <td><?php echo $c['total']; ?></td>
                                        <td class="text-success"><?php echo $c['qualified']; ?></td>
                                        <td class="text-warning"><?php echo $c['pending']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Items Needing Form Fill -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Needs Form Filling (Qualified Leads)</h5>
                <a href="../leads/form-filler.php" class="btn btn-sm btn-primary">Go to Form Filling</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Date</th>
                                <th>Name</th>
                                <th>Campaign</th>
                                <th>Agent</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentPending)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No pending forms found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentPending as $lead): ?>
                                    <tr>
                                        <td class="ps-4 small text-muted">
                                            <?php echo date('M d, H:i', strtotime($lead['created_at'])); ?>
                                        </td>
                                        <td class="fw-semibold">
                                            <?php echo htmlspecialchars($lead['first_name'] . ' ' . $lead['last_name']); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-secondary border">
                                                <?php echo htmlspecialchars($lead['campaign_name'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($lead['agent_name'] ?? 'N/A'); ?></td>
                                        <td class="text-end pe-4">
                                            <a href="../leads/leads-edit.php?id=<?php echo $lead['id']; ?>&mode=audit" class="btn btn-sm btn-primary">
                                                Fill Form
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
