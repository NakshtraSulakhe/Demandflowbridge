<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

$permEnabled = function_exists('getAccessRolePermissionsConfig') && getAccessRolePermissionsConfig() !== null;
if ($permEnabled) {
    if (!(userHasAnyPermission(['hr.break_automation.page', 'hr.break_automation']))) {
        header('Location: ' . appBasePath() . '/modules/auth/access-denied');
        exit;
    }
} else {
    requirePermissionOrRole('hr.break_automation.page', ['admin', 'director', 'manager_director']);
}

ensureCsrfToken();
$user = getCurrentUser();

function breakAutomationFormatSummary(array $setting): string {
    $parts = [];
    if (!empty($setting['team_break_1_time'])) $parts[] = 'TB1 ' . substr((string)$setting['team_break_1_time'], 0, 5) . ' / ' . (int)($setting['team_break_1_duration'] ?? 15) . 'm';
    if (!empty($setting['dinner_time'])) $parts[] = 'Dinner ' . substr((string)$setting['dinner_time'], 0, 5) . ' / ' . (int)($setting['dinner_duration'] ?? 30) . 'm';
    if (!empty($setting['team_break_2_time'])) $parts[] = 'TB2 ' . substr((string)$setting['team_break_2_time'], 0, 5) . ' / ' . (int)($setting['team_break_2_duration'] ?? 15) . 'm';
    return empty($parts) ? 'Manual breaks' : implode(' · ', $parts);
}

function breakAutomationTimeValue($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    return substr($value, 0, 5);
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $tb1Time = trim((string)($_POST['team_break_1_time'] ?? ''));
            $dinnerTime = trim((string)($_POST['dinner_time'] ?? ''));
            $tb2Time = trim((string)($_POST['team_break_2_time'] ?? ''));
            $tb1Duration = (int)($_POST['team_break_1_duration'] ?? 15);
            $dinnerDuration = (int)($_POST['dinner_duration'] ?? 30);
            $tb2Duration = (int)($_POST['team_break_2_duration'] ?? 15);

            if ($userId <= 0) {
                $message = 'Select a valid user.';
                $messageType = 'danger';
            } elseif ($isEnabled === 1 && $tb1Time === '' && $dinnerTime === '' && $tb2Time === '') {
                $message = 'Set at least one break time before enabling automation.';
                $messageType = 'danger';
            } else {
                if ($isEnabled === 0) {
                    $tb1Time = '';
                    $dinnerTime = '';
                    $tb2Time = '';
                }

                $settings = [
                    'is_enabled' => $isEnabled,
                    'team_break_1_time' => $tb1Time,
                    'dinner_time' => $dinnerTime,
                    'team_break_2_time' => $tb2Time,
                    'team_break_1_duration' => $tb1Duration,
                    'dinner_duration' => $dinnerDuration,
                    'team_break_2_duration' => $tb2Duration,
                ];

                $ok = saveBreakAutomationSettings($userId, $settings);
                if ($ok) {
                    $message = $isEnabled === 1
                        ? 'Break automation saved. The next eligible login/punched-in session for this user will follow the configured schedule.'
                        : 'Break automation disabled. The user will manage breaks manually from the attendance flow.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to save break automation settings.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

$allSettings = getAllBreakAutomationSettings();
$users = getInternalPayrollUsers();
$userMap = [];
$settingsByUser = [];
foreach ($users as $u) {
    $userMap[(int)($u['id'] ?? 0)] = $u;
}
foreach ($allSettings as $s) {
    $settingsByUser[(int)($s['user_id'] ?? 0)] = $s;
}

$selectedUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
if ($selectedUserId <= 0 || !isset($userMap[$selectedUserId])) {
    $firstUser = $users[0] ?? null;
    $selectedUserId = (int)($firstUser['id'] ?? 0);
}
$selectedUser = $userMap[$selectedUserId] ?? [];
$selectedSetting = $settingsByUser[$selectedUserId] ?? [
    'is_enabled' => 0,
    'team_break_1_time' => '',
    'dinner_time' => '',
    'team_break_2_time' => '',
    'team_break_1_duration' => 15,
    'dinner_duration' => 30,
    'team_break_2_duration' => 15,
];

$automatedUsers = array_values(array_filter($settingsByUser, static fn(array $row): bool => (int)($row['is_enabled'] ?? 0) === 1));
$automatedCount = count($automatedUsers);
$manualCount = max(0, count($users) - $automatedCount);

$settingsJson = [];
foreach ($users as $u) {
    $uid = (int)($u['id'] ?? 0);
    $row = $settingsByUser[$uid] ?? [];
    $settingsJson[$uid] = [
        'user_id' => $uid,
        'full_name' => (string)($u['full_name'] ?? ''),
        'job_title' => (string)($u['job_title'] ?? ''),
        'department' => (string)($u['department'] ?? ''),
        'is_enabled' => (int)($row['is_enabled'] ?? 0),
        'team_break_1_time' => breakAutomationTimeValue($row['team_break_1_time'] ?? ''),
        'dinner_time' => breakAutomationTimeValue($row['dinner_time'] ?? ''),
        'team_break_2_time' => breakAutomationTimeValue($row['team_break_2_time'] ?? ''),
        'team_break_1_duration' => (int)($row['team_break_1_duration'] ?? 15),
        'dinner_duration' => (int)($row['dinner_duration'] ?? 30),
        'team_break_2_duration' => (int)($row['team_break_2_duration'] ?? 15),
        'summary' => breakAutomationFormatSummary($row),
    ];
}
?>
<?php $pageTitle = 'Break Automation'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 break-automation-page">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Break Automation'],
            ],
            'Break Automation',
            'Assign automatic break schedules per user and fall back to manual breaks when automation is disabled',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
                ['label' => 'Attendance', 'href' => 'attendance', 'icon' => 'bi-calendar-check', 'class' => 'btn-outline-secondary'],
            ],
            [
                ['text' => 'Automated: ' . $automatedCount, 'class' => 'bg-success-subtle text-success border'],
                ['text' => 'Manual: ' . $manualCount, 'class' => 'bg-light text-dark border'],
            ]
        );
    ?>

    <style>
        .break-automation-page .schedule-pill{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .6rem;border-radius:999px;border:1px solid rgba(148,163,184,.35);background:rgba(248,250,252,.95);font-size:.78rem}
        [data-bs-theme="dark"] .break-automation-page .schedule-pill{background:rgba(15,23,42,.78);border-color:rgba(148,163,184,.18)}
        .break-automation-page .schedule-row{border:1px solid rgba(148,163,184,.18);border-radius:14px;padding:1rem;background:rgba(248,250,252,.65)}
        [data-bs-theme="dark"] .break-automation-page .schedule-row{background:rgba(15,23,42,.48);border-color:rgba(148,163,184,.12)}
        .break-automation-page .user-summary-card{border:1px dashed rgba(14,165,233,.32);border-radius:14px;padding:1rem;background:rgba(14,165,233,.05)}
        [data-bs-theme="dark"] .break-automation-page .user-summary-card{background:rgba(14,165,233,.10)}
    </style>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <?php hrKpi('Internal Users', (string)number_format(count($users)), 'bi-people'); ?>
        </div>
        <div class="col-md-4">
            <?php hrKpi('Automated Break Users', (string)number_format($automatedCount), 'bi-magic', 'Breaks start from schedule when user is punched in', $automatedCount > 0 ? 'text-success' : 'text-muted'); ?>
        </div>
        <div class="col-md-4">
            <?php hrKpi('Manual Break Users', (string)number_format($manualCount), 'bi-hand-index', 'Unchecked users manage breaks from their own attendance page', $manualCount > 0 ? 'text-warning' : 'text-muted'); ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card hr-card h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-person-gear me-1"></i> Select User</div>
                <div class="card-body d-flex flex-column gap-3">
                    <div>
                        <label class="form-label small text-muted">User</label>
                        <select id="break-user-picker" class="form-select">
                            <?php foreach ($users as $u): ?>
                                <?php $uid = (int)($u['id'] ?? 0); ?>
                                <option value="<?php echo $uid; ?>" <?php echo $uid === $selectedUserId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?><?php echo !empty($u['department']) ? (' · ' . htmlspecialchars((string)$u['department'])) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="user-summary-card">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold" id="selected-user-name"><?php echo htmlspecialchars((string)($selectedUser['full_name'] ?? 'Select a user')); ?></div>
                                <div class="small text-muted" id="selected-user-role"><?php echo htmlspecialchars(trim((string)($selectedUser['job_title'] ?? '')) !== '' ? (string)$selectedUser['job_title'] : (string)($selectedUser['department'] ?? '')); ?></div>
                            </div>
                            <span class="badge <?php echo !empty($selectedSetting['is_enabled']) ? 'bg-success-subtle text-success border' : 'bg-light text-dark border'; ?>" id="selected-user-mode">
                                <?php echo !empty($selectedSetting['is_enabled']) ? 'Automation Enabled' : 'Manual Breaks'; ?>
                            </span>
                        </div>
                        <div class="small text-muted mt-3">Current Schedule</div>
                        <div class="fw-semibold mt-1" id="selected-user-summary"><?php echo htmlspecialchars(breakAutomationFormatSummary($selectedSetting)); ?></div>
                    </div>

                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i> How It Works</div>
                        <ul class="small text-muted mb-0 ps-3">
                            <li>Select a user and turn automation on to schedule breaks.</li>
                            <li>The header timer becomes visible to that user as soon as the scheduled automated break starts.</li>
                            <li>If automation is unchecked, the user handles breaks manually from attendance.</li>
                            <li>Access can now be granted from Access Management using the break automation permission.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card hr-card">
                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-1"></i> Break Schedule Setup</span>
                    <span class="small text-muted">Changes apply on the next eligible attendance session for the selected user.</span>
                </div>
                <div class="card-body">
                    <form id="break-settings-form" method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                        <input type="hidden" name="action" value="save_settings">
                        <input type="hidden" name="user_id" id="form-user-id" value="<?php echo (int)$selectedUserId; ?>">

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="is-enabled-toggle" name="is_enabled" <?php echo !empty($selectedSetting['is_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="is-enabled-toggle">Enable automated breaks for this user</label>
                            </div>
                            <div class="small text-muted mt-1" id="automation-toggle-help">
                                <?php echo !empty($selectedSetting['is_enabled']) ? 'The user will receive automated break starts based on the times below.' : 'Unchecked means the user manages breaks manually from their own attendance flow.'; ?>
                            </div>
                        </div>

                        <div class="col-12" id="automation-fields">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="schedule-row h-100">
                                        <div class="fw-semibold mb-2">Team Break 1</div>
                                        <label class="form-label small text-muted">Start Time</label>
                                        <input type="time" class="form-control" name="team_break_1_time" id="team-break-1-time" value="<?php echo htmlspecialchars(breakAutomationTimeValue($selectedSetting['team_break_1_time'] ?? '')); ?>">
                                        <label class="form-label small text-muted mt-3">Duration</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="team_break_1_duration" id="team-break-1-duration" value="<?php echo (int)($selectedSetting['team_break_1_duration'] ?? 15); ?>" min="5" max="60" step="5">
                                            <span class="input-group-text">min</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="schedule-row h-100">
                                        <div class="fw-semibold mb-2">Dinner</div>
                                        <label class="form-label small text-muted">Start Time</label>
                                        <input type="time" class="form-control" name="dinner_time" id="dinner-time" value="<?php echo htmlspecialchars(breakAutomationTimeValue($selectedSetting['dinner_time'] ?? '')); ?>">
                                        <label class="form-label small text-muted mt-3">Duration</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="dinner_duration" id="dinner-duration" value="<?php echo (int)($selectedSetting['dinner_duration'] ?? 30); ?>" min="15" max="90" step="5">
                                            <span class="input-group-text">min</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="schedule-row h-100">
                                        <div class="fw-semibold mb-2">Team Break 2</div>
                                        <label class="form-label small text-muted">Start Time</label>
                                        <input type="time" class="form-control" name="team_break_2_time" id="team-break-2-time" value="<?php echo htmlspecialchars(breakAutomationTimeValue($selectedSetting['team_break_2_time'] ?? '')); ?>">
                                        <label class="form-label small text-muted mt-3">Duration</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="team_break_2_duration" id="team-break-2-duration" value="<?php echo (int)($selectedSetting['team_break_2_duration'] ?? 15); ?>" min="5" max="60" step="5">
                                            <span class="input-group-text">min</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i> Save Break Setup</button>
                                <span class="schedule-pill" id="schedule-preview-pill"><?php echo htmlspecialchars(breakAutomationFormatSummary($selectedSetting)); ?></span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card hr-card mt-3">
                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-1"></i> Automation Coverage</span>
                    <span class="badge bg-secondary"><?php echo number_format(count($users)); ?> users</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Schedule</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <?php
                                        $uid = (int)($u['id'] ?? 0);
                                        $setting = $settingsByUser[$uid] ?? [];
                                        $enabled = (int)($setting['is_enabled'] ?? 0) === 1;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars((string)($u['job_title'] ?? '')); ?></div>
                                        </td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($u['department'] ?? '')); ?></td>
                                        <td>
                                            <span class="badge <?php echo $enabled ? 'bg-success-subtle text-success border' : 'bg-light text-dark border'; ?>">
                                                <?php echo $enabled ? 'Automated' : 'Manual'; ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?php echo htmlspecialchars(breakAutomationFormatSummary($setting)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">No internal users found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const settingsMap = <?php echo json_encode($settingsJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const picker = document.getElementById('break-user-picker');
    const form = document.getElementById('break-settings-form');
    const hiddenUserId = document.getElementById('form-user-id');
    const toggle = document.getElementById('is-enabled-toggle');
    const fieldsWrap = document.getElementById('automation-fields');
    const helper = document.getElementById('automation-toggle-help');
    const summaryName = document.getElementById('selected-user-name');
    const summaryRole = document.getElementById('selected-user-role');
    const summaryMode = document.getElementById('selected-user-mode');
    const summaryText = document.getElementById('selected-user-summary');
    const preview = document.getElementById('schedule-preview-pill');
    const inputs = {
        tb1Time: document.getElementById('team-break-1-time'),
        tb1Duration: document.getElementById('team-break-1-duration'),
        dinnerTime: document.getElementById('dinner-time'),
        dinnerDuration: document.getElementById('dinner-duration'),
        tb2Time: document.getElementById('team-break-2-time'),
        tb2Duration: document.getElementById('team-break-2-duration')
    };

    function scheduleSummary(row) {
        const parts = [];
        if (row.team_break_1_time) parts.push('TB1 ' + row.team_break_1_time.slice(0, 5) + ' / ' + row.team_break_1_duration + 'm');
        if (row.dinner_time) parts.push('Dinner ' + row.dinner_time.slice(0, 5) + ' / ' + row.dinner_duration + 'm');
        if (row.team_break_2_time) parts.push('TB2 ' + row.team_break_2_time.slice(0, 5) + ' / ' + row.team_break_2_duration + 'm');
        return parts.length ? parts.join(' · ') : 'Manual breaks';
    }

    function renderSelectedUser(userId) {
        const row = settingsMap[userId];
        if (!row) return;
        hiddenUserId.value = userId;
        summaryName.textContent = row.full_name || 'Selected user';
        summaryRole.textContent = row.job_title || row.department || '';
        toggle.checked = Number(row.is_enabled || 0) === 1;
        inputs.tb1Time.value = row.team_break_1_time || '';
        inputs.tb1Duration.value = row.team_break_1_duration || 15;
        inputs.dinnerTime.value = row.dinner_time || '';
        inputs.dinnerDuration.value = row.dinner_duration || 30;
        inputs.tb2Time.value = row.team_break_2_time || '';
        inputs.tb2Duration.value = row.team_break_2_duration || 15;
        syncVisibility();
        updatePreview();
    }

    function syncVisibility() {
        const enabled = toggle.checked;
        fieldsWrap.classList.toggle('d-none', !enabled);
        helper.textContent = enabled
            ? 'The user will see the active break in the main header once the automated break starts.'
            : 'Unchecked means the user manages breaks manually from the attendance flow.';
        summaryMode.textContent = enabled ? 'Automation Enabled' : 'Manual Breaks';
        summaryMode.className = 'badge ' + (enabled ? 'bg-success-subtle text-success border' : 'bg-light text-dark border');
    }

    function updatePreview() {
        const row = {
            team_break_1_time: inputs.tb1Time.value,
            team_break_1_duration: inputs.tb1Duration.value || 15,
            dinner_time: inputs.dinnerTime.value,
            dinner_duration: inputs.dinnerDuration.value || 30,
            team_break_2_time: inputs.tb2Time.value,
            team_break_2_duration: inputs.tb2Duration.value || 15
        };
        const text = toggle.checked ? scheduleSummary(row) : 'Manual breaks';
        summaryText.textContent = text;
        preview.textContent = text;
    }

    picker.addEventListener('change', function () {
        renderSelectedUser(this.value);
    });

    toggle.addEventListener('change', function () {
        syncVisibility();
        updatePreview();
        if (!this.checked) {
            if (window.confirm('Disable automation for this user and return break control to manual attendance actions?')) {
                form.submit();
            } else {
                this.checked = true;
                syncVisibility();
                updatePreview();
            }
        }
    });

    Object.values(inputs).forEach(function (input) {
        input.addEventListener('input', updatePreview);
        input.addEventListener('change', updatePreview);
    });

    renderSelectedUser(picker.value);
});
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
