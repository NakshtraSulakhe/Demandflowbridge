<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('funneltrack360.admin.page', []);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser();
$viewerId = (int)($user['id'] ?? 0);

$canConfig = function_exists('userHasPermission') ? userHasPermission('funneltrack360.admin.page') : true;
$canTargets = function_exists('userHasPermission') ? userHasPermission('funneltrack360.admin.page') : true;
$canReports = function_exists('userHasPermission') ? userHasPermission('funneltrack360.reports.page') : true;
$canTeam = function_exists('userHasPermission') ? userHasPermission('funneltrack360.team.page') : true;

$cfg = funneltrackGetConfig();
$tz = hrBaseTz();
$now = new DateTimeImmutable('now', $tz);

$message = '';
$messageType = 'success';
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_config' && $canConfig) {
            $activeTab = 'config';
            $weights = [
                'productivity' => (int)($_POST['w_productivity'] ?? 60),
                'adherence' => (int)($_POST['w_adherence'] ?? 10),
                'absenteeism' => (int)($_POST['w_absenteeism'] ?? 10),
                'quality' => (int)($_POST['w_quality'] ?? 20),
            ];
            $weightTotal = array_sum($weights);
            if ($weightTotal !== 100) {
                $message = 'Weights must total exactly 100.';
                $messageType = 'danger';
            } else {
            $thr = [
                'min_expected_productivity_pct' => (int)($_POST['t_min_prod'] ?? 90),
                'required_login_minutes' => (int)($_POST['t_login_min'] ?? 465),
                'min_attendance_pct' => (int)($_POST['t_att_min'] ?? 90),
                'max_disqualification_ratio_pct' => (int)($_POST['t_disq_max'] ?? 10),
            ];

            $cpTypes = $_POST['cp_type'] ?? [];
            $cpVals = $_POST['cp_value'] ?? [];
            $campaignPoints = [];
            if (is_array($cpTypes) && is_array($cpVals)) {
                $n = min(count($cpTypes), count($cpVals));
                for ($i = 0; $i < $n; $i++) {
                    $k = trim((string)$cpTypes[$i]);
                    if ($k === '') continue;
                    $campaignPoints[$k] = (float)$cpVals[$i];
                }
            }

            $dailyBasePct = (int)($_POST['daily_base_pct'] ?? 100);
            $dailyBaseAmt = (int)($_POST['daily_base_amt'] ?? 500);
            $dFrom = $_POST['d_from_pct'] ?? [];
            $dTo = $_POST['d_to_pct'] ?? [];
            $dPer = $_POST['d_per_point'] ?? [];
            $dailySlabs = [];
            if (is_array($dFrom) && is_array($dPer) && is_array($dTo)) {
                $n = min(count($dFrom), count($dTo), count($dPer));
                for ($i = 0; $i < $n; $i++) {
                    $fp = (int)$dFrom[$i];
                    $tpRaw = trim((string)$dTo[$i]);
                    $tp = $tpRaw === '' ? null : (int)$tpRaw;
                    $pp = (int)$dPer[$i];
                    if ($pp <= 0) continue;
                    $dailySlabs[] = ['from_pct' => $fp, 'to_pct' => $tp, 'per_point' => $pp];
                }
            }

            $mMin = $_POST['m_min_pct'] ?? [];
            $mAmt = $_POST['m_amount'] ?? [];
            $monthlySlabs = [];
            if (is_array($mMin) && is_array($mAmt)) {
                $n = min(count($mMin), count($mAmt));
                for ($i = 0; $i < $n; $i++) {
                    $mp = (int)$mMin[$i];
                    $am = (int)$mAmt[$i];
                    if ($mp <= 0 || $am <= 0) continue;
                    $monthlySlabs[] = ['min_pct' => $mp, 'amount' => $am];
                }
            }
            $mAbove = (int)($_POST['m_above_pct'] ?? 300);
            $mStepPct = (int)($_POST['m_step_pct'] ?? 25);
            $mStepAmt = (int)($_POST['m_step_amt'] ?? 5000);

            $rLbl = $_POST['r_label'] ?? [];
            $rMin = $_POST['r_min'] ?? [];
            $rMax = $_POST['r_max'] ?? [];
            $rankRanges = [];
            if (is_array($rLbl) && is_array($rMin) && is_array($rMax)) {
                $n = min(count($rLbl), count($rMin), count($rMax));
                for ($i = 0; $i < $n; $i++) {
                    $lbl = trim((string)$rLbl[$i]);
                    if ($lbl === '') continue;
                    $minV = (float)$rMin[$i];
                    $maxV = (float)$rMax[$i];
                    $rankRanges[] = ['label' => $lbl, 'min' => $minV, 'max' => $maxV];
                }
            }

            $mode = (string)($_POST['esc_mode'] ?? 'disqualify');
            $cfg['weights'] = $weights;
            $cfg['thresholds'] = $thr;
            if (!empty($campaignPoints)) $cfg['campaign_points'] = $campaignPoints;
            $cfg['daily_incentive'] = ['base_threshold_pct' => $dailyBasePct, 'base_amount' => $dailyBaseAmt, 'slabs' => $dailySlabs];
            $cfg['monthly_incentive'] = ['slabs' => $monthlySlabs, 'above_pct' => $mAbove, 'step_pct' => $mStepPct, 'step_amount' => $mStepAmt];
            $cfg['rank_ranges'] = $rankRanges;
            $cfg['escalation_rule']['mode'] = $mode;

            funneltrackSaveConfig($cfg);
            $message = 'Configuration saved.';
            $messageType = 'success';
            $cfg = funneltrackGetConfig();
            }
        } elseif ($action === 'assign_target' && $canTargets) {
            $activeTab = 'targets';
            $monthStr = trim((string)($_POST['month'] ?? $now->format('Y-m')));
            if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) $monthStr = $now->format('Y-m');
            $yy = (int)substr($monthStr, 0, 4);
            $mm = (int)substr($monthStr, 5, 2);
            $agentId = (int)($_POST['agent_id'] ?? 0);
            $daily = (int)($_POST['daily_target'] ?? 0);
            if ($agentId <= 0 || $daily <= 0) {
                $message = 'Please select an agent and enter a valid daily target.';
                $messageType = 'danger';
            } else {
                ensureProductivitySchema();
                $ok = setAgentMonthlyTarget($agentId, $yy, $mm, $daily, $viewerId);
                $message = $ok ? 'Target saved.' : 'Failed to save target.';
                $messageType = $ok ? 'success' : 'danger';
            }
        } elseif ($action === 'update_target' && $canTargets) {
            $activeTab = 'targets';
            $agentId = (int)($_POST['update_agent_id'] ?? 0);
            $yy = (int)($_POST['update_yy'] ?? 0);
            $mm = (int)($_POST['update_mm'] ?? 0);
            $daily = (int)($_POST['update_daily'] ?? 0);
            $workingDays = (int)($_POST['update_working_days'] ?? 0);
            
            if ($agentId <= 0 || $yy <= 0 || $mm <= 0 || $daily <= 0 || $workingDays <= 0) {
                $message = 'Invalid target data.';
                $messageType = 'danger';
            } else {
                ensureProductivitySchema();
                $conn = getDbConnection();
                $stmt = $conn->prepare("
                    UPDATE productivity_targets 
                    SET daily_target = ?, working_days = ?, updated_by = ?, updated_at = NOW() 
                    WHERE agent_id = ? AND year = ? AND month = ?
                ");
                if ($stmt) {
                    $stmt->bind_param("iiiiii", $daily, $workingDays, $viewerId, $agentId, $yy, $mm);
                    $stmt->execute();
                    $stmt->close();
                    $message = 'Target updated successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update target.';
                    $messageType = 'danger';
                }
            }
        } elseif ($action === 'add_escalation' && $canConfig) {
            $activeTab = 'escalations';
            $uid = (int)($_POST['user_id'] ?? 0);
            $cat = trim((string)($_POST['category'] ?? ''));
            $start = trim((string)($_POST['start_date'] ?? ''));
            $end = trim((string)($_POST['end_date'] ?? ''));
            $active = (int)($_POST['active'] ?? 1) ? 1 : 0;
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($uid <= 0 || $cat === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                $message = 'Invalid escalation input.';
                $messageType = 'danger';
            } else {
                if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = '';
                if ($end !== '' && $end < $start) {
                    $tmp = $start;
                    $start = $end;
                    $end = $tmp;
                }
                ensureFunnelTrack360Schema();
                $conn = getDbConnection();
                $stmt = $conn->prepare("INSERT INTO funneltrack_escalations (user_id, category, start_date, end_date, active, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
                if ($stmt) {
                    $endVal = $end !== '' ? $end : null;
                    $stmt->bind_param('isssisi', $uid, $cat, $start, $endVal, $active, $notes, $viewerId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Escalation added.' : 'Failed to add escalation.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'update_escalation' && $canConfig) {
            $activeTab = 'escalations';
            $escId = (int)($_POST['escalation_id'] ?? 0);
            $uid = (int)($_POST['edit_user_id'] ?? 0);
            $cat = trim((string)($_POST['edit_category'] ?? ''));
            $start = trim((string)($_POST['edit_start_date'] ?? ''));
            $end = trim((string)($_POST['edit_end_date'] ?? ''));
            $active = (int)($_POST['edit_active'] ?? 1) ? 1 : 0;
            $notes = trim((string)($_POST['edit_notes'] ?? ''));
            if ($escId <= 0 || $uid <= 0 || $cat === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                $message = 'Invalid escalation update.';
                $messageType = 'danger';
            } else {
                if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = '';
                if ($end !== '' && $end < $start) {
                    $tmp = $start;
                    $start = $end;
                    $end = $tmp;
                }
                ensureFunnelTrack360Schema();
                $conn = getDbConnection();
                $stmt = $conn->prepare("
                    UPDATE funneltrack_escalations
                    SET user_id = ?, category = ?, start_date = ?, end_date = ?, active = ?, notes = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                if ($stmt) {
                    $endVal = $end !== '' ? $end : null;
                    $stmt->bind_param('isssisii', $uid, $cat, $start, $endVal, $active, $notes, $viewerId, $escId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Escalation updated.' : 'Failed to update escalation.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'deactivate_escalation' && $canConfig) {
            $activeTab = 'escalations';
            $escId = (int)($_POST['escalation_id'] ?? 0);
            if ($escId <= 0) {
                $message = 'Invalid escalation selected.';
                $messageType = 'danger';
            } else {
                ensureFunnelTrack360Schema();
                $conn = getDbConnection();
                $stmt = $conn->prepare("UPDATE funneltrack_escalations SET active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $viewerId, $escId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Escalation deactivated.' : 'Failed to deactivate escalation.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'save_override' && $canConfig) {
            $activeTab = 'overrides';
            $uid = (int)($_POST['ov_user_id'] ?? 0);
            $monthStr = trim((string)($_POST['ov_month'] ?? $now->format('Y-m')));
            $key = trim((string)($_POST['ov_key'] ?? ''));
            $val = trim((string)($_POST['ov_value'] ?? ''));
            $reason = trim((string)($_POST['ov_reason'] ?? ''));
            if ($uid <= 0 || !preg_match('/^\d{4}-\d{2}$/', $monthStr) || $key === '' || $val === '') {
                $message = 'Invalid override input.';
                $messageType = 'danger';
            } else {
                $yy = (int)substr($monthStr, 0, 4);
                $mm = (int)substr($monthStr, 5, 2);
                ensureFunnelTrack360Schema();
                $conn = getDbConnection();
                $stmt = $conn->prepare("
                    INSERT INTO funneltrack_overrides (user_id, year, month, override_key, override_value, reason, created_by, created_at, updated_by, updated_at)
                    VALUES (?,?,?,?,?,?,?,NOW(),NULL,NULL)
                    ON DUPLICATE KEY UPDATE override_value = VALUES(override_value), reason = VALUES(reason), updated_by = VALUES(created_by), updated_at = NOW()
                ");
                if ($stmt) {
                    $stmt->bind_param('iiisssi', $uid, $yy, $mm, $key, $val, $reason, $viewerId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Override saved.' : 'Failed to save override.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($activeTab === '') $activeTab = 'config';
}
$activeTab = strtolower(trim($activeTab));
if (!in_array($activeTab, ['config','targets','escalations','overrides'], true)) $activeTab = 'config';

$users = getInternalPayrollUsers();
$monthPicker = isset($_GET['month']) ? trim((string)$_GET['month']) : $now->format('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthPicker)) $monthPicker = $now->format('Y-m');
$yy = (int)substr($monthPicker, 0, 4);
$mm = (int)substr($monthPicker, 5, 2);
$targets = $canTargets ? getMonthlyTargets($yy, $mm) : [];

ensureFunnelTrack360Schema();
$conn = getDbConnection();
$escRows = [];
$rs = $conn->query("
    SELECT e.*,
           u.full_name,
           creator.full_name AS created_by_name,
           updater.full_name AS updated_by_name
    FROM funneltrack_escalations e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN users creator ON creator.id = e.created_by
    LEFT JOIN users updater ON updater.id = e.updated_by
    ORDER BY e.active DESC, e.start_date DESC, e.id DESC
    LIMIT 200
");
if ($rs) $escRows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
$ovRows = [];
$rs2 = $conn->query("
    SELECT o.*,
           u.full_name,
           creator.full_name AS created_by_name,
           updater.full_name AS updated_by_name
    FROM funneltrack_overrides o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN users creator ON creator.id = o.created_by
    LEFT JOIN users updater ON updater.id = o.updated_by
    ORDER BY COALESCE(o.updated_at, o.created_at) DESC, o.id DESC
    LIMIT 200
");
if ($rs2) $ovRows = $rs2->fetch_all(MYSQLI_ASSOC) ?: [];

$configBlocks = count((array)($cfg['campaign_points'] ?? []))
    + count((array)($cfg['daily_incentive']['slabs'] ?? []))
    + count((array)($cfg['monthly_incentive']['slabs'] ?? []))
    + count((array)($cfg['rank_ranges'] ?? []));
$activeEscCount = 0;
foreach ($escRows as $e) {
    if ((int)($e['active'] ?? 0) === 1) $activeEscCount++;
}

$pageTitle = 'DemandFlow Bridge – Performance Admin';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace performance-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-sliders2"></i>Performance Control Center</div>
            <h3 class="sales-page-title">Performance & Incentives Admin</h3>
            <div class="sales-page-subtitle">Configure score logic, assign targets, track escalations, and apply overrides with the same CRM design language used across the rest of the platform.</div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=all'); ?>"><i class="bi bi-table me-1"></i>Daily Detail</a>
            <?php if ($canTeam): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>"><i class="bi bi-people me-1"></i>Team View</a><?php endif; ?>
            <?php if ($canReports): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Reports</a><?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav performance-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>">My Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=all'); ?>">Daily Detail</a></li>
        <?php if ($canTeam): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>">Team View</a></li><?php endif; ?>
        <?php if ($canReports): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>">Reports</a></li><?php endif; ?>
        <li class="nav-item"><a class="nav-link active" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>">Admin</a></li>
    </ul>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Config Blocks</div><div class="performance-callout-value"><?php echo number_format($configBlocks); ?></div><div class="small text-muted mt-1">Points, slabs, and rank ranges in active config</div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Monthly Targets</div><div class="performance-callout-value"><?php echo number_format(count($targets)); ?></div><div class="small text-muted mt-1"><?php echo htmlspecialchars($monthPicker); ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Active Escalations</div><div class="performance-callout-value"><?php echo number_format($activeEscCount); ?></div><div class="small text-muted mt-1"><?php echo number_format(count($escRows)); ?> escalation records visible</div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Overrides</div><div class="performance-callout-value"><?php echo number_format(count($ovRows)); ?></div><div class="small text-muted mt-1">Manual audit adjustments on file</div></div></div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel mb-3">
        <div class="card-body">
            <div class="performance-toolbar-actions">
                <div>
                    <div class="fw-semibold">Admin Workflow</div>
                    <div class="text-muted small">Start with configuration, then assign monthly targets, and only use escalations or overrides for controlled exceptions.</div>
                </div>
                <div class="text-muted small">Active tab: <?php echo htmlspecialchars(ucfirst($activeTab)); ?></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel performance-tab-shell mb-3">
        <div class="card-body">
            <div class="performance-toolbar-actions mb-3">
                <div>
                    <div class="fw-semibold">Admin Sections</div>
                    <div class="text-muted small">Use each tab as a focused workspace for configuration, targets, exceptions, and audit-safe manual controls.</div>
                </div>
                <div class="performance-range-readout">
                    <span class="performance-status-badge performance-status-pending">Current month: <?php echo htmlspecialchars($monthPicker); ?></span>
                    <span class="performance-status-badge performance-status-eligible">Updated workspace</span>
                </div>
            </div>
            <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav performance-nav performance-admin-tab-nav" role="tablist">
                <?php if ($canConfig): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $activeTab==='config'?'active':''; ?>" id="tab-config" data-bs-toggle="tab" data-bs-target="#pane-config" type="button" role="tab"><i class="bi bi-sliders me-1"></i>Config</button>
                    </li>
                <?php endif; ?>
                <?php if ($canTargets): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $activeTab==='targets'?'active':''; ?>" id="tab-targets" data-bs-toggle="tab" data-bs-target="#pane-targets" type="button" role="tab"><i class="bi bi-bullseye me-1"></i>Targets</button>
                    </li>
                <?php endif; ?>
                <?php if ($canConfig): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $activeTab==='escalations'?'active':''; ?>" id="tab-escalations" data-bs-toggle="tab" data-bs-target="#pane-escalations" type="button" role="tab"><i class="bi bi-exclamation-triangle me-1"></i>Escalations</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $activeTab==='overrides'?'active':''; ?>" id="tab-overrides" data-bs-toggle="tab" data-bs-target="#pane-overrides" type="button" role="tab"><i class="bi bi-pencil-square me-1"></i>Overrides</button>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="tab-content performance-admin-tab-content">
        <?php if ($canConfig): ?>
            <div class="tab-pane fade <?php echo $activeTab==='config'?'show active':''; ?>" id="pane-config" role="tabpanel" aria-labelledby="tab-config">
                <div class="card border-0 shadow-sm sales-panel performance-tab-card mb-3">
                    <div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i>Score & Incentive Configuration</div>
                    <div class="card-body">
                        <div class="performance-stacked-note mb-3">Keep weights at a combined 100, use campaign type points carefully, and treat incentive slabs as the single source of truth for monthly payouts.</div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="save_config">
                            <input type="hidden" name="tab" value="config">
                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <div class="performance-admin-section h-100">
                                        <div class="performance-admin-section-head">
                                            <div>
                                                <div class="performance-admin-section-title">Weights & Eligibility Rules</div>
                                                <div class="performance-admin-section-note">Balance score drivers first, then keep the threshold rules aligned with the incentive policy.</div>
                                            </div>
                                            <span class="performance-admin-chip">Total 100</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6"><label class="form-label small text-muted">Productivity</label><input class="form-control form-control-sm" type="number" name="w_productivity" value="<?php echo (int)($cfg['weights']['productivity'] ?? 60); ?>"></div>
                                            <div class="col-6"><label class="form-label small text-muted">Quality</label><input class="form-control form-control-sm" type="number" name="w_quality" value="<?php echo (int)($cfg['weights']['quality'] ?? 20); ?>"></div>
                                            <div class="col-6"><label class="form-label small text-muted">Adherence</label><input class="form-control form-control-sm" type="number" name="w_adherence" value="<?php echo (int)($cfg['weights']['adherence'] ?? 10); ?>"></div>
                                            <div class="col-6"><label class="form-label small text-muted">Absenteeism</label><input class="form-control form-control-sm" type="number" name="w_absenteeism" value="<?php echo (int)($cfg['weights']['absenteeism'] ?? 10); ?>"></div>
                                        </div>
                                        <div class="performance-admin-subhead">Thresholds</div>
                                        <div class="row g-2">
                                            <div class="col-6"><label class="form-label small text-muted">Min Productivity % (Eligibility)</label><input class="form-control form-control-sm" type="number" name="t_min_prod" value="<?php echo (int)($cfg['thresholds']['min_expected_productivity_pct'] ?? 90); ?>"></div>
                                            <div class="col-6"><label class="form-label small text-muted">Required Login Minutes</label><input class="form-control form-control-sm" type="number" name="t_login_min" value="<?php echo (int)($cfg['thresholds']['required_login_minutes'] ?? 465); ?>"></div>
                                            <div class="col-6"><label class="form-label small text-muted">Min Attendance %</label><input class="form-control form-control-sm" type="number" name="t_att_min" value="<?php echo (int)($cfg['thresholds']['min_attendance_pct'] ?? 90); ?>"></div>
                                            <div class="col-6"><label class="form-label small text-muted">Max Disqualification Ratio %</label><input class="form-control form-control-sm" type="number" name="t_disq_max" value="<?php echo (int)($cfg['thresholds']['max_disqualification_ratio_pct'] ?? 10); ?>"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <div class="performance-admin-section h-100">
                                        <div class="performance-admin-section-head">
                                            <div>
                                                <div class="performance-admin-section-title">Campaign Points & Daily Incentive</div>
                                                <div class="performance-admin-section-note">Map campaign types to points, then define the daily payout ladder that converts productivity into incentive amounts.</div>
                                            </div>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="addCp"><i class="bi bi-plus"></i></button>
                                        </div>
                                        <div class="table-responsive performance-admin-table-block">
                                            <table class="table table-sm align-middle" id="cpTable">
                                                <thead class="table-light">
                                                    <tr><th>Type</th><th class="text-end">Points</th><th class="text-end">Remove</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (($cfg['campaign_points'] ?? []) as $k => $v): ?>
                                                        <tr>
                                                            <td><input class="form-control form-control-sm" name="cp_type[]" value="<?php echo htmlspecialchars((string)$k); ?>"></td>
                                                            <td><input class="form-control form-control-sm text-end" name="cp_value[]" value="<?php echo htmlspecialchars((string)$v); ?>"></td>
                                                            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="performance-admin-subhead">Daily Incentive</div>
                                        <div class="row g-2">
                                            <div class="col-6"><label class="form-label small text-muted">Base Threshold %</label><input class="form-control form-control-sm" type="number" name="daily_base_pct" value="<?php echo (int)($cfg['daily_incentive']['base_threshold_pct'] ?? 100); ?>"></div>
                                            <div class="col-6"><label class="form-label small text-muted">Base Amount</label><input class="form-control form-control-sm" type="number" name="daily_base_amt" value="<?php echo (int)($cfg['daily_incentive']['base_amount'] ?? 500); ?>"></div>
                                        </div>
                                        <div class="table-responsive mt-2 performance-admin-table-block">
                                            <table class="table table-sm align-middle" id="dailySlabs">
                                                <thead class="table-light">
                                                    <tr><th class="text-end">From %</th><th class="text-end">To %</th><th class="text-end">Per Point ₹</th><th class="text-end">Remove</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (($cfg['daily_incentive']['slabs'] ?? []) as $s): ?>
                                                        <tr>
                                                            <td><input class="form-control form-control-sm text-end" name="d_from_pct[]" value="<?php echo htmlspecialchars((string)($s['from_pct'] ?? '')); ?>"></td>
                                                            <td><input class="form-control form-control-sm text-end" name="d_to_pct[]" value="<?php echo htmlspecialchars((string)($s['to_pct'] ?? '')); ?>"></td>
                                                            <td><input class="form-control form-control-sm text-end" name="d_per_point[]" value="<?php echo htmlspecialchars((string)($s['per_point'] ?? '')); ?>"></td>
                                                            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="performance-admin-actions">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="addDaily"><i class="bi bi-plus"></i> Add Slab</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="row g-3">
                                        <div class="col-lg-6">
                                            <div class="performance-admin-section h-100">
                                                <div class="performance-admin-section-head">
                                                    <div>
                                                        <div class="performance-admin-section-title">Monthly Incentive Slabs</div>
                                                        <div class="performance-admin-section-note">These ranges control the monthly payout curve after the score is finalized.</div>
                                                    </div>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addMonthly"><i class="bi bi-plus"></i></button>
                                                </div>
                                                <div class="table-responsive performance-admin-table-block">
                                                    <table class="table table-sm align-middle" id="monthlySlabs">
                                                        <thead class="table-light">
                                                            <tr><th class="text-end">Min %</th><th class="text-end">Amount ₹</th><th class="text-end">Remove</th></tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (($cfg['monthly_incentive']['slabs'] ?? []) as $s): ?>
                                                                <tr>
                                                                    <td><input class="form-control form-control-sm text-end" name="m_min_pct[]" value="<?php echo htmlspecialchars((string)($s['min_pct'] ?? '')); ?>"></td>
                                                                    <td><input class="form-control form-control-sm text-end" name="m_amount[]" value="<?php echo htmlspecialchars((string)($s['amount'] ?? '')); ?>"></td>
                                                                    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col-4"><label class="form-label small text-muted">Above %</label><input class="form-control form-control-sm text-end" name="m_above_pct" value="<?php echo (int)($cfg['monthly_incentive']['above_pct'] ?? 300); ?>"></div>
                                                    <div class="col-4"><label class="form-label small text-muted">Step %</label><input class="form-control form-control-sm text-end" name="m_step_pct" value="<?php echo (int)($cfg['monthly_incentive']['step_pct'] ?? 25); ?>"></div>
                                                    <div class="col-4"><label class="form-label small text-muted">Step Amount ₹</label><input class="form-control form-control-sm text-end" name="m_step_amt" value="<?php echo (int)($cfg['monthly_incentive']['step_amount'] ?? 5000); ?>"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="performance-admin-section h-100">
                                                <div class="performance-admin-section-head">
                                                    <div>
                                                        <div class="performance-admin-section-title">Ranking Ranges & Escalation Rule</div>
                                                        <div class="performance-admin-section-note">Keep the score labels meaningful and align the escalation behavior with the current incentive policy.</div>
                                                    </div>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addRank"><i class="bi bi-plus"></i></button>
                                                </div>
                                                <div class="table-responsive performance-admin-table-block">
                                                    <table class="table table-sm align-middle" id="rankTable">
                                                        <thead class="table-light">
                                                            <tr><th>Label</th><th class="text-end">Min</th><th class="text-end">Max</th><th class="text-end">Remove</th></tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (($cfg['rank_ranges'] ?? []) as $r): ?>
                                                                <tr>
                                                                    <td><input class="form-control form-control-sm" name="r_label[]" value="<?php echo htmlspecialchars((string)($r['label'] ?? '')); ?>"></td>
                                                                    <td><input class="form-control form-control-sm text-end" name="r_min[]" value="<?php echo htmlspecialchars((string)($r['min'] ?? '')); ?>"></td>
                                                                    <td><input class="form-control form-control-sm text-end" name="r_max[]" value="<?php echo htmlspecialchars((string)($r['max'] ?? '')); ?>"></td>
                                                                    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="performance-admin-subhead">Escalation Rule</div>
                                                <?php $mode = (string)($cfg['escalation_rule']['mode'] ?? 'disqualify'); ?>
                                                <select class="form-select form-select-sm" name="esc_mode">
                                                    <option value="disqualify" <?php echo $mode==='disqualify'?'selected':''; ?>>Complete Incentive Disqualification</option>
                                                    <option value="no_impact" <?php echo $mode==='no_impact'?'selected':''; ?>>No Impact</option>
                                                    <option value="partial" <?php echo $mode==='partial'?'selected':''; ?>>Partial Deduction</option>
                                                    <option value="fixed" <?php echo $mode==='fixed'?'selected':''; ?>>Fixed Deduction</option>
                                                </select>
                                                <div class="small text-muted mt-1">Default: Any active escalation = no incentive eligibility.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="performance-admin-footer">
                                        <div class="text-muted small">Review all slabs and score labels before saving, because these values affect dashboards, reports, and payout logic together.</div>
                                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save me-1"></i>Save Configuration</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canTargets): ?>
            <div class="tab-pane fade <?php echo $activeTab==='targets'?'show active':''; ?>" id="pane-targets" role="tabpanel" aria-labelledby="tab-targets">
                <div class="card border-0 shadow-sm sales-panel performance-tab-card mb-3">
                    <div class="card-header fw-semibold"><i class="bi bi-bullseye me-1"></i>Target Assignment</div>
                    <div class="card-body">
                        <div class="performance-stacked-note mb-3">Assign monthly point targets before the month starts so productivity percentages, reports, and payouts use the right baseline from day one.</div>
                        <div class="performance-admin-section mb-3">
                            <div class="performance-admin-section-head">
                                <div>
                                    <div class="performance-admin-section-title">Assign New Target</div>
                                    <div class="performance-admin-section-note">Set the month, choose the agent, and assign the daily point target used to calculate the full monthly baseline.</div>
                                </div>
                                <span class="performance-admin-chip"><?php echo number_format(count($targets)); ?> saved</span>
                            </div>
                            <form method="post" class="row g-2 align-items-end sales-filter-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="assign_target">
                                <input type="hidden" name="tab" value="targets">
                                <div class="col-md-3">
                                    <label class="form-label small text-muted">Month</label>
                                    <input class="form-control form-control-sm" type="month" name="month" value="<?php echo htmlspecialchars($monthPicker); ?>">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small text-muted">Agent</label>
                                    <select class="form-select form-select-sm" name="agent_id">
                                        <option value="">Select agent</option>
                                        <?php foreach ($users as $u): ?>
                                            <?php $uid = (int)($u['id'] ?? 0); ?>
                                            <?php $r = strtolower((string)($u['role'] ?? '')); ?>
                                            <?php if ($uid<=0 || !in_array($r, ['agent','operations_agent','email_marketing_agent','email_marketing_executive','form_filler'], true)) continue; ?>
                                            <option value="<?php echo $uid; ?>"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted">Daily Target (Points)</label>
                                    <input class="form-control form-control-sm" type="number" name="daily_target" min="0" step="1" value="">
                                </div>
                                <div class="col-md-2">
                                    <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>Assign</button>
                                </div>
                            </form>
                        </div>

                        <div class="performance-admin-section">
                            <div class="performance-admin-section-head">
                                <div>
                                    <div class="performance-admin-section-title">Target Register</div>
                                    <div class="performance-admin-section-note">Review working days, daily targets, monthly totals, and the full audit trail from one clean table.</div>
                                </div>
                                <span class="performance-admin-chip"><?php echo htmlspecialchars($monthPicker); ?></span>
                            </div>
                        <div class="table-responsive sales-table-responsive performance-admin-table-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Agent</th>
                                        <th class="text-end">Working Days</th>
                                        <th class="text-end">Daily Target</th>
                                        <th class="text-end">Monthly Target</th>
                                        <th class="text-muted">Audit</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($targets)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No targets for this month.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($targets as $t): ?>
                                            <tr class="performance-inline-edit-row">
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="update_target">
                                                    <input type="hidden" name="tab" value="targets">
                                                    <input type="hidden" name="update_agent_id" value="<?php echo (int)($t['user_id'] ?? 0); ?>">
                                                    <input type="hidden" name="update_yy" value="<?php echo $yy; ?>">
                                                    <input type="hidden" name="update_mm" value="<?php echo $mm; ?>">
                                                    <td>
                                                        <div class="performance-inline-primary"><?php echo htmlspecialchars((string)($t['agent_name'] ?? '')); ?></div>
                                                        <div class="performance-inline-secondary">Monthly target owner</div>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="performance-inline-input-wrap">
                                                            <label class="performance-inline-label">Working days</label>
                                                            <input type="number" class="form-control form-control-sm text-end performance-inline-input" name="update_working_days" min="1" value="<?php echo (int)($t['working_days'] ?? 0); ?>">
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="performance-inline-input-wrap">
                                                            <label class="performance-inline-label">Daily target</label>
                                                            <input type="number" class="form-control form-control-sm text-end performance-inline-input" name="update_daily" min="0" value="<?php echo (int)($t['daily_target'] ?? 0); ?>">
                                                        </div>
                                                    </td>
                                                    <td class="text-end font-monospace">
                                                        <div class="performance-inline-primary"><?php 
                                                            $wd = (int)($t['working_days'] ?? 0);
                                                            $dt = (int)($t['daily_target'] ?? 0);
                                                            echo number_format($wd * $dt);
                                                        ?></div>
                                                        <div class="performance-inline-secondary">Current month total</div>
                                                    </td>
                                                    <td class="text-muted small">
                                                        <div class="performance-inline-primary text-muted">Assigned: <?php echo htmlspecialchars((string)($t['assigned_by_name'] ?? 'System')); ?></div>
                                                        <div><?php echo htmlspecialchars((string)($t['created_at'] ?? '')); ?></div>
                                                        <?php if (!empty($t['updated_at'])): ?>
                                                            <div class="performance-audit-note mt-1">Updated: <?php echo htmlspecialchars((string)($t['updated_by_name'] ?? 'System')); ?> · <?php echo htmlspecialchars((string)$t['updated_at']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end performance-inline-actions">
                                                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save</button>
                                                    </td>
                                                </form>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canConfig): ?>
            <div class="tab-pane fade <?php echo $activeTab==='escalations'?'show active':''; ?>" id="pane-escalations" role="tabpanel" aria-labelledby="tab-escalations">
                <div class="card border-0 shadow-sm sales-panel performance-tab-card mb-3">
                    <div class="card-header fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i>Escalations</div>
                    <div class="card-body">
                        <div class="performance-stacked-note mb-3">Use escalations only for formal exception states because they directly impact daily and monthly incentive eligibility depending on the configured rule.</div>
                        <div class="performance-admin-section mb-3">
                            <div class="performance-admin-section-head">
                                <div>
                                    <div class="performance-admin-section-title">Add Escalation</div>
                                    <div class="performance-admin-section-note">Capture formal exception records with dates, active state, and notes before they affect eligibility.</div>
                                </div>
                                <span class="performance-admin-chip"><?php echo number_format($activeEscCount); ?> active</span>
                            </div>
                            <form method="post" class="row g-2 align-items-end sales-filter-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="add_escalation">
                                <input type="hidden" name="tab" value="escalations">
                                <div class="col-md-5">
                                    <label class="form-label small text-muted">User</label>
                                    <select class="form-select form-select-sm" name="user_id">
                                        <option value="">Select user</option>
                                        <?php foreach ($users as $u): ?>
                                            <?php $uid = (int)($u['id'] ?? 0); if ($uid<=0) continue; ?>
                                            <option value="<?php echo $uid; ?>"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Category</label>
                                    <input class="form-control form-control-sm" name="category" value="">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted">Active</label>
                                    <select class="form-select form-select-sm" name="active">
                                        <option value="1" selected>Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Start</label>
                                    <input class="form-control form-control-sm" type="date" name="start_date" value="<?php echo htmlspecialchars($now->format('Y-m-d')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">End (optional)</label>
                                    <input class="form-control form-control-sm" type="date" name="end_date" value="">
                                </div>
                                <div class="col-md-4">
                                    <button class="btn btn-outline-danger btn-sm w-100" type="submit"><i class="bi bi-plus-circle me-1"></i>Add</button>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted">Notes</label>
                                    <input class="form-control form-control-sm" name="notes" value="">
                                </div>
                            </form>
                        </div>

                        <div class="performance-admin-section">
                            <div class="performance-admin-section-head">
                                <div>
                                    <div class="performance-admin-section-title">Escalation Register</div>
                                    <div class="performance-admin-section-note">Review status, audit ownership, and inline editing controls without the layout breaking across rows.</div>
                                </div>
                                <span class="performance-admin-chip"><?php echo number_format(count($escRows)); ?> total</span>
                            </div>
                        <div class="table-responsive sales-table-responsive performance-admin-table-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>User</th>
                                        <th>Category</th>
                                        <th class="text-muted">Start</th>
                                        <th class="text-muted">End</th>
                                        <th>Status</th>
                                        <th class="text-muted">Audit</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($escRows)): ?>
                                        <tr><td colspan="7" class="text-center text-muted">No escalations.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($escRows as $e): ?>
                                            <?php $escCollapseId = 'esc-' . (int)($e['id'] ?? 0); ?>
                                            <tr class="performance-inline-edit-row">
                                                <td>
                                                    <div class="performance-inline-primary"><?php echo htmlspecialchars((string)($e['full_name'] ?? '')); ?></div>
                                                    <div class="performance-inline-secondary">Escalation owner</div>
                                                </td>
                                                <td>
                                                    <div class="performance-inline-primary"><?php echo htmlspecialchars((string)($e['category'] ?? '')); ?></div>
                                                    <div class="performance-inline-secondary">Exception category</div>
                                                </td>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)($e['start_date'] ?? '')); ?></td>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)($e['end_date'] ?? '')); ?></td>
                                                <td><?php echo ((int)($e['active'] ?? 0) === 1) ? '<span class="badge bg-danger">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                                <td class="text-muted small">
                                                    <div>Created: <?php echo htmlspecialchars((string)($e['created_by_name'] ?? 'System')); ?></div>
                                                    <div><?php echo htmlspecialchars((string)($e['created_at'] ?? '')); ?></div>
                                                    <?php if (!empty($e['updated_at'])): ?>
                                                        <div class="performance-audit-note mt-1">Updated: <?php echo htmlspecialchars((string)($e['updated_by_name'] ?? 'System')); ?> · <?php echo htmlspecialchars((string)$e['updated_at']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end performance-inline-actions">
                                                    <div class="performance-inline-action-group">
                                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($escCollapseId); ?>" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($escCollapseId); ?>"><i class="bi bi-pencil-square me-1"></i>Edit</button>
                                                        <?php if ((int)($e['active'] ?? 0) === 1): ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                <input type="hidden" name="action" value="deactivate_escalation">
                                                                <input type="hidden" name="tab" value="escalations">
                                                                <input type="hidden" name="escalation_id" value="<?php echo (int)($e['id'] ?? 0); ?>">
                                                                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-pause-circle me-1"></i>Deactivate</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr class="collapse performance-drilldown-row" id="<?php echo htmlspecialchars($escCollapseId); ?>">
                                                <td colspan="7">
                                                    <form method="post" class="row g-3 align-items-end sales-filter-form performance-inline-editor">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="action" value="update_escalation">
                                                        <input type="hidden" name="tab" value="escalations">
                                                        <input type="hidden" name="escalation_id" value="<?php echo (int)($e['id'] ?? 0); ?>">
                                                        <div class="col-md-4">
                                                            <label class="form-label small text-muted">User</label>
                                                            <select class="form-select form-select-sm" name="edit_user_id">
                                                                <?php foreach ($users as $u): ?>
                                                                    <?php $uid = (int)($u['id'] ?? 0); if ($uid <= 0) continue; ?>
                                                                    <option value="<?php echo $uid; ?>" <?php echo $uid === (int)($e['user_id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small text-muted">Category</label>
                                                            <input class="form-control form-control-sm" name="edit_category" value="<?php echo htmlspecialchars((string)($e['category'] ?? '')); ?>">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label small text-muted">Active</label>
                                                            <select class="form-select form-select-sm" name="edit_active">
                                                                <option value="1" <?php echo ((int)($e['active'] ?? 0) === 1) ? 'selected' : ''; ?>>Yes</option>
                                                                <option value="0" <?php echo ((int)($e['active'] ?? 0) === 0) ? 'selected' : ''; ?>>No</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small text-muted">Start</label>
                                                            <input class="form-control form-control-sm" type="date" name="edit_start_date" value="<?php echo htmlspecialchars((string)($e['start_date'] ?? '')); ?>">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small text-muted">End</label>
                                                            <input class="form-control form-control-sm" type="date" name="edit_end_date" value="<?php echo htmlspecialchars((string)($e['end_date'] ?? '')); ?>">
                                                        </div>
                                                        <div class="col-md-7">
                                                            <label class="form-label small text-muted">Notes</label>
                                                            <input class="form-control form-control-sm" name="edit_notes" value="<?php echo htmlspecialchars((string)($e['notes'] ?? '')); ?>">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-save me-1"></i>Update</button>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="performance-table-note">Escalations now support inline editing and deactivation, and every row shows who created or last changed the record.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab==='overrides'?'show active':''; ?>" id="pane-overrides" role="tabpanel" aria-labelledby="tab-overrides">
                <div class="card border-0 shadow-sm sales-panel performance-tab-card mb-3">
                    <div class="card-header fw-semibold"><i class="bi bi-pencil-square me-1"></i>Overrides</div>
                    <div class="card-body">
                        <div class="performance-stacked-note mb-3">Overrides should remain exceptional and always include a reason, because they bypass the calculated score or incentive outcome for audit purposes.</div>
                        <div class="performance-admin-section mb-3">
                            <div class="performance-admin-section-head">
                                <div>
                                    <div class="performance-admin-section-title">Create Override</div>
                                    <div class="performance-admin-section-note">Use manual overrides only when the computed score or incentive needs a documented correction.</div>
                                </div>
                                <span class="performance-admin-chip"><?php echo number_format(count($ovRows)); ?> records</span>
                            </div>
                            <form method="post" class="row g-2 align-items-end sales-filter-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_override">
                                <input type="hidden" name="tab" value="overrides">
                                <div class="col-md-5">
                                    <label class="form-label small text-muted">User</label>
                                    <select class="form-select form-select-sm" name="ov_user_id">
                                        <option value="">Select user</option>
                                        <?php foreach ($users as $u): ?>
                                            <?php $uid = (int)($u['id'] ?? 0); if ($uid<=0) continue; ?>
                                            <option value="<?php echo $uid; ?>"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted">Month</label>
                                    <input class="form-control form-control-sm" type="month" name="ov_month" value="<?php echo htmlspecialchars($monthPicker); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Key</label>
                                    <select class="form-select form-select-sm" name="ov_key">
                                        <option value="score.total">score.total</option>
                                        <option value="incentive.monthly">incentive.monthly</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Value</label>
                                    <input class="form-control form-control-sm" name="ov_value" value="">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small text-muted">Reason</label>
                                    <input class="form-control form-control-sm" name="ov_reason" value="">
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save me-1"></i>Save Override</button>
                                </div>
                            </form>
                        </div>

                        <div class="performance-admin-section">
                            <div class="performance-admin-section-head">
                                <div>
                                    <div class="performance-admin-section-title">Override History</div>
                                    <div class="performance-admin-section-note">This history view keeps the corrected values and the full audit reason readable without oversized empty gaps.</div>
                                </div>
                                <span class="performance-admin-chip">Audit preserved</span>
                            </div>
                        <div class="table-responsive sales-table-responsive performance-admin-table-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>User</th>
                                        <th>Month</th>
                                        <th>Key</th>
                                        <th>Value</th>
                                        <th class="text-muted">Reason</th>
                                        <th class="text-muted">Audit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ovRows)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No overrides.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($ovRows as $o): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($o['full_name'] ?? '')); ?></td>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)($o['year'] ?? '')); ?>-<?php echo str_pad((string)($o['month'] ?? ''), 2, '0', STR_PAD_LEFT); ?></td>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)($o['override_key'] ?? '')); ?></td>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)($o['override_value'] ?? '')); ?></td>
                                                <td class="text-muted small"><?php echo htmlspecialchars((string)($o['reason'] ?? '')); ?></td>
                                                <td class="text-muted small">
                                                    <div>Created: <?php echo htmlspecialchars((string)($o['created_by_name'] ?? 'System')); ?></div>
                                                    <div><?php echo htmlspecialchars((string)($o['created_at'] ?? '')); ?></div>
                                                    <?php if (!empty($o['updated_at'])): ?>
                                                        <div class="performance-audit-note mt-1">Updated: <?php echo htmlspecialchars((string)($o['updated_by_name'] ?? 'System')); ?> · <?php echo htmlspecialchars((string)$o['updated_at']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="performance-table-note">Override history now preserves who created the original record and who last changed it.</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
  function bindDel(root) {
    root.querySelectorAll('.delRow').forEach(btn => {
      btn.addEventListener('click', () => {
        const tr = btn.closest('tr');
        if (tr) tr.remove();
      });
    });
  }
  bindDel(document);
  const addCp = document.getElementById('addCp');
  if (addCp) addCp.addEventListener('click', () => {
    const tbody = document.querySelector('#cpTable tbody');
    if (!tbody) return;
    tbody.insertAdjacentHTML('beforeend',
      '<tr>' +
        '<td><input class="form-control form-control-sm" name="cp_type[]" value=""></td>' +
        '<td><input class="form-control form-control-sm text-end" name="cp_value[]" value=""></td>' +
        '<td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>' +
      '</tr>'
    );
    bindDel(tbody);
  });
  const addDaily = document.getElementById('addDaily');
  if (addDaily) addDaily.addEventListener('click', () => {
    const tbody = document.querySelector('#dailySlabs tbody');
    if (!tbody) return;
    tbody.insertAdjacentHTML('beforeend',
      '<tr>' +
        '<td><input class="form-control form-control-sm text-end" name="d_from_pct[]" value=""></td>' +
        '<td><input class="form-control form-control-sm text-end" name="d_to_pct[]" value=""></td>' +
        '<td><input class="form-control form-control-sm text-end" name="d_per_point[]" value=""></td>' +
        '<td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>' +
      '</tr>'
    );
    bindDel(tbody);
  });
  const addMonthly = document.getElementById('addMonthly');
  if (addMonthly) addMonthly.addEventListener('click', () => {
    const tbody = document.querySelector('#monthlySlabs tbody');
    if (!tbody) return;
    tbody.insertAdjacentHTML('beforeend',
      '<tr>' +
        '<td><input class="form-control form-control-sm text-end" name="m_min_pct[]" value=""></td>' +
        '<td><input class="form-control form-control-sm text-end" name="m_amount[]" value=""></td>' +
        '<td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>' +
      '</tr>'
    );
    bindDel(tbody);
  });
  const addRank = document.getElementById('addRank');
  if (addRank) addRank.addEventListener('click', () => {
    const tbody = document.querySelector('#rankTable tbody');
    if (!tbody) return;
    tbody.insertAdjacentHTML('beforeend',
      '<tr>' +
        '<td><input class="form-control form-control-sm" name="r_label[]" value=""></td>' +
        '<td><input class="form-control form-control-sm text-end" name="r_min[]" value=""></td>' +
        '<td><input class="form-control form-control-sm text-end" name="r_max[]" value=""></td>' +
        '<td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm delRow"><i class="bi bi-trash"></i></button></td>' +
      '</tr>'
    );
    bindDel(tbody);
  });
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
