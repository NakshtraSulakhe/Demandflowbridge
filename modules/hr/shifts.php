<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.shifts.page', ['admin', 'director', 'manager_director']);
ensureCsrfToken();
$user = getCurrentUser();

ensureDatabaseSchema();
ensureDefaultShiftExists();

$message = '';
$messageType = 'success';

$istTz = new DateTimeZone('Asia/Kolkata');
$usTz = new DateTimeZone('America/New_York');
$todayIst = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$refDate = isset($_GET['ref_date']) ? (string)$_GET['ref_date'] : $todayIst;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate)) $refDate = $todayIst;

function shiftWindowConvert(string $date, string $startTime, string $endTime, DateTimeZone $fromTz, DateTimeZone $toTz): array {
    if (strlen($startTime) === 5) $startTime .= ':00';
    if (strlen($endTime) === 5) $endTime .= ':00';
    $start = new DateTimeImmutable($date . ' ' . $startTime, $fromTz);
    $end = new DateTimeImmutable($date . ' ' . $endTime, $fromTz);
    if ($end <= $start) $end = $end->modify('+1 day');
    $s2 = $start->setTimezone($toTz);
    $e2 = $end->setTimezone($toTz);
    return [
        'start_date' => $s2->format('Y-m-d'),
        'start_time' => $s2->format('H:i'),
        'end_date' => $e2->format('Y-m-d'),
        'end_time' => $e2->format('H:i'),
    ];
}

function shiftWindowLabel(array $w): string {
    $sd = (string)($w['start_date'] ?? '');
    $ed = (string)($w['end_date'] ?? '');
    $st = (string)($w['start_time'] ?? '');
    $et = (string)($w['end_time'] ?? '');
    if ($sd !== '' && $ed !== '' && $sd !== $ed) {
        return $st . ' (' . $sd . ') → ' . $et . ' (' . $ed . ')';
    }
    return $st . ' → ' . $et;
}

function shiftDurationMinutes(string $date, string $startTime, string $endTime, DateTimeZone $tz): int {
    if (strlen($startTime) === 5) $startTime .= ':00';
    if (strlen($endTime) === 5) $endTime .= ':00';
    $start = new DateTimeImmutable($date . ' ' . $startTime, $tz);
    $end = new DateTimeImmutable($date . ' ' . $endTime, $tz);
    if ($end <= $start) $end = $end->modify('+1 day');
    $sec = (int)($end->getTimestamp() - $start->getTimestamp());
    if ($sec < 0) $sec = 0;
    return (int)floor($sec / 60);
}

function shiftDurationLabel(int $minutes): string {
    $m = max(0, (int)$minutes);
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    if ($mm === 0) return $h . 'h';
    return $h . 'h ' . str_pad((string)$mm, 2, '0', STR_PAD_LEFT) . 'm';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $conn = getDbConnection();
        if ($action === 'save_shift') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $start = trim((string)($_POST['start_time'] ?? ''));
            $end = trim((string)($_POST['end_time'] ?? ''));
            $grace = (int)($_POST['grace_minutes'] ?? 15);
            $active = !empty($_POST['active']) ? 1 : 0;
            $timeBase = (string)($_POST['time_base'] ?? 'IST');
            $postRef = (string)($_POST['ref_date'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postRef)) $postRef = date('Y-m-d');
            if ($name === '' || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
                $message = 'Please enter valid shift values.';
                $messageType = 'danger';
            } else {
                if (strlen($start) === 5) $start .= ':00';
                if (strlen($end) === 5) $end .= ':00';
                if ($timeBase === 'US') {
                    $w = shiftWindowConvert($postRef, $start, $end, $usTz, $istTz);
                    $start = (string)($w['start_time'] ?? '00:00') . ':00';
                    $end = (string)($w['end_time'] ?? '00:00') . ':00';
                }
                if ($shiftId > 0) {
                    $stmt = $conn->prepare("UPDATE hr_shifts SET name = ?, start_time = ?, end_time = ?, grace_minutes = ?, active = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param('sssiii', $name, $start, $end, $grace, $active, $shiftId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Shift updated.' : 'Failed to update shift.';
                        $messageType = $ok ? 'success' : 'danger';
                    }
                } else {
                    $stmt = $conn->prepare("INSERT INTO hr_shifts (name, start_time, end_time, grace_minutes, active) VALUES (?,?,?,?,?)");
                    if ($stmt) {
                        $stmt->bind_param('sssii', $name, $start, $end, $grace, $active);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Shift created.' : 'Failed to create shift.';
                        $messageType = $ok ? 'success' : 'danger';
                    }
                }
            }
        } elseif ($action === 'deactivate_shift') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            if ($shiftId > 0) {
                $stmt = $conn->prepare("UPDATE hr_shifts SET active = 0, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $shiftId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Shift deactivated.' : 'Failed to update shift.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'assign_shift') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $shiftId = (int)($_POST['assign_shift_id'] ?? 0);
            $eff = (string)($_POST['effective_date'] ?? '');
            if ($userId <= 0 || $shiftId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff)) {
                $message = 'Please select user, shift and effective date.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("INSERT INTO hr_user_shift_assignments (user_id, shift_id, effective_date) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)");
                if ($stmt) {
                    $stmt->bind_param('iis', $userId, $shiftId, $eff);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Shift assigned.' : 'Failed to assign shift.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'clear_assignment') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $eff = (string)($_POST['effective_date'] ?? '');
            if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff)) {
                $message = 'Invalid assignment.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("DELETE FROM hr_user_shift_assignments WHERE user_id = ? AND effective_date = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('is', $userId, $eff);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Assignment cleared.' : 'Failed to clear assignment.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'delete_shift') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            if ($shiftId <= 0) {
                $message = 'Invalid shift.';
                $messageType = 'danger';
            } else {
                $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM hr_user_shift_assignments WHERE shift_id = ?");
                $cnt = 0;
                if ($chk) {
                    $chk->bind_param('i', $shiftId);
                    $chk->execute();
                    $row = $chk->get_result()->fetch_assoc() ?: [];
                    $cnt = (int)($row['cnt'] ?? 0);
                    $chk->close();
                }
                if ($cnt > 0) {
                    $message = 'This shift is assigned to users. Please reassign users first, then delete.';
                    $messageType = 'danger';
                } else {
                    $stmt = $conn->prepare("DELETE FROM hr_shifts WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('i', $shiftId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Shift deleted.' : 'Failed to delete shift.';
                        $messageType = $ok ? 'success' : 'danger';
                    }
                }
            }
        }
    }
}

$conn = getDbConnection();
$shiftsRs = $conn->query("SELECT * FROM hr_shifts ORDER BY active DESC, start_time ASC, id ASC");
$shifts = $shiftsRs ? ($shiftsRs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
$users = getInternalPayrollUsers();

$today = date('Y-m-d');
$assignments = [];
$stmtAssign = $conn->prepare("
    SELECT u.id AS user_id, u.full_name, a.shift_id AS shift_id, s.name AS shift_name, s.start_time, s.end_time, s.grace_minutes, a.effective_date
    FROM users u
    LEFT JOIN hr_user_shift_assignments a ON a.user_id = u.id AND a.effective_date = (
        SELECT MAX(a2.effective_date) FROM hr_user_shift_assignments a2 WHERE a2.user_id = u.id AND a2.effective_date <= ?
    )
    LEFT JOIN hr_shifts s ON s.id = a.shift_id
    WHERE u.is_active = 1 AND (u.client_id IS NULL OR u.client_id = 0) AND (u.vendor_id IS NULL OR u.vendor_id = 0)
    ORDER BY u.full_name
");
if ($stmtAssign) {
    $stmtAssign->bind_param('s', $today);
    $stmtAssign->execute();
    $assignments = $stmtAssign->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtAssign->close();
}
?>
<?php $pageTitle = 'Shifts'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Shifts'],
            ],
            'Shift Management',
            'Manage shift timings and late buffer (grace minutes)',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
            ]
        );
    ?>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Reference Date (for US↔India conversion)</label>
                            <input type="date" class="form-control form-control-sm" name="ref_date" value="<?php echo htmlspecialchars($refDate); ?>">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-arrow-repeat"></i> Apply</button>
                        </div>
                        <div class="col-md-7">
                            <div class="text-muted small">Stored shift times are India (IST). This page also shows the equivalent US Eastern (ET) time based on the reference date (DST-aware).</div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card mb-3">
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-clock me-1"></i> Create / Update Shift</div>
                                <div class="card-body">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="save_shift">
                                        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($refDate); ?>">
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Shift</label>
                                            <select class="form-select form-select-sm" name="shift_id">
                                                <option value="">New Shift</option>
                                                <?php foreach ($shifts as $s): ?>
                                                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars((string)($s['name'] ?? '')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label small text-muted">Name</label>
                                            <input class="form-control form-control-sm" name="name" id="shift_name" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Input Timezone</label>
                                            <select class="form-select form-select-sm" name="time_base" id="time_base">
                                                <option value="IST">India (IST)</option>
                                                <option value="US">US Eastern (ET)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Start Time</label>
                                            <input class="form-control form-control-sm font-monospace" name="start_time" id="start_time" placeholder="18:00" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">End Time</label>
                                            <input class="form-control form-control-sm font-monospace" name="end_time" id="end_time" placeholder="03:00" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Grace (min)</label>
                                            <input type="number" class="form-control form-control-sm" name="grace_minutes" id="grace_minutes" min="0" value="15" required>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mt-4">
                                                <input class="form-check-input" type="checkbox" name="active" id="active" value="1" checked>
                                                <label class="form-check-label">Active</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-person-check me-1"></i> Assign Shift to User</div>
                                <div class="card-body">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="assign_shift">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">User</label>
                                            <select class="form-select form-select-sm" name="user_id" required>
                                                <option value="">Select</option>
                                                <?php foreach ($users as $u): ?>
                                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Shift</label>
                                            <select class="form-select form-select-sm" name="assign_shift_id" required>
                                                <option value="">Select</option>
                                                <?php foreach ($shifts as $s): ?>
                                                    <?php if ((int)($s['active'] ?? 0) !== 1) continue; ?>
                                                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars((string)($s['name'] ?? '')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted">Effective</label>
                                            <input type="date" class="form-control form-control-sm" name="effective_date" value="<?php echo htmlspecialchars($today); ?>" required>
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Assign</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card hr-card mb-3">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-list-check me-1"></i> Shifts</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle hr-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th class="font-monospace">IST</th>
                                    <th class="font-monospace">US (ET)</th>
                                    <th class="text-end">Hours</th>
                                    <th class="text-end">Grace</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts as $s): ?>
                                    <?php $us = shiftWindowConvert($refDate, (string)($s['start_time'] ?? '00:00:00'), (string)($s['end_time'] ?? '00:00:00'), $istTz, $usTz); ?>
                                    <?php $durMin = shiftDurationMinutes($refDate, (string)($s['start_time'] ?? '00:00:00'), (string)($s['end_time'] ?? '00:00:00'), $istTz); ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($s['name'] ?? '')); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars(substr((string)($s['start_time'] ?? ''), 0, 5)); ?> → <?php echo htmlspecialchars(substr((string)($s['end_time'] ?? ''), 0, 5)); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars(shiftWindowLabel($us)); ?></td>
                                        <td class="text-end font-monospace"><?php echo htmlspecialchars(shiftDurationLabel($durMin)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($s['grace_minutes'] ?? 15)); ?>m</td>
                                        <td><?php echo ((int)($s['active'] ?? 0) === 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                        <td class="text-end">
                                            <?php if ((int)($s['active'] ?? 0) === 1): ?>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="deactivate_shift">
                                                    <input type="hidden" name="shift_id" value="<?php echo (int)$s['id']; ?>">
                                                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-x-circle"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="delete_shift">
                                                    <input type="hidden" name="shift_id" value="<?php echo (int)$s['id']; ?>">
                                                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($shifts)): ?>
                                    <tr><td colspan="7" class="text-center text-muted">No shifts</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card hr-card">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-people me-1"></i> Current User Assignments</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle hr-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Shift</th>
                                    <th class="font-monospace">IST</th>
                                    <th class="font-monospace">US (ET)</th>
                                    <th class="text-end">Hours</th>
                                    <th class="text-end">Grace</th>
                                    <th>Effective</th>
                                    <th class="text-end">Clear</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $a): ?>
                                    <?php $us2 = shiftWindowConvert($refDate, (string)($a['start_time'] ?? '00:00:00'), (string)($a['end_time'] ?? '00:00:00'), $istTz, $usTz); ?>
                                    <?php $durMin2 = shiftDurationMinutes($refDate, (string)($a['start_time'] ?? '00:00:00'), (string)($a['end_time'] ?? '00:00:00'), $istTz); ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($a['full_name'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($a['shift_name'] ?? 'General')); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars(substr((string)($a['start_time'] ?? ''), 0, 5)); ?> → <?php echo htmlspecialchars(substr((string)($a['end_time'] ?? ''), 0, 5)); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars(shiftWindowLabel($us2)); ?></td>
                                        <td class="text-end font-monospace"><?php echo htmlspecialchars(shiftDurationLabel($durMin2)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($a['grace_minutes'] ?? 15)); ?>m</td>
                                        <td class="font-monospace"><?php echo htmlspecialchars((string)($a['effective_date'] ?? '')); ?></td>
                                        <td class="text-end">
                                            <?php if ((int)($a['shift_id'] ?? 0) > 0 && (string)($a['effective_date'] ?? '') !== ''): ?>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="clear_assignment">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)($a['user_id'] ?? 0); ?>">
                                                    <input type="hidden" name="effective_date" value="<?php echo htmlspecialchars((string)($a['effective_date'] ?? '')); ?>">
                                                    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-x-lg"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($assignments)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">No users</td></tr>
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
  (function(){
    const shiftMap = <?php echo json_encode(array_map(function($s){
        return [
            'id' => (int)($s['id'] ?? 0),
            'name' => (string)($s['name'] ?? ''),
            'start_time' => substr((string)($s['start_time'] ?? ''), 0, 5),
            'end_time' => substr((string)($s['end_time'] ?? ''), 0, 5),
            'grace_minutes' => (int)($s['grace_minutes'] ?? 15),
            'active' => (int)($s['active'] ?? 0),
        ];
    }, $shifts), JSON_UNESCAPED_UNICODE); ?>;
    const byId = {};
    for (const s of shiftMap) byId[String(s.id)] = s;
    const sel = document.querySelector('select[name="shift_id"]');
    if (!sel) return;
    const name = document.getElementById('shift_name');
    const st = document.getElementById('start_time');
    const et = document.getElementById('end_time');
    const grace = document.getElementById('grace_minutes');
    const active = document.getElementById('active');
    const tz = document.getElementById('time_base');
    sel.addEventListener('change', function(){
      const v = String(sel.value || '');
      if (!v || !byId[v]) return;
      const s = byId[v];
      if (name) name.value = s.name || '';
      if (st) st.value = s.start_time || '';
      if (et) et.value = s.end_time || '';
      if (grace) grace.value = String(s.grace_minutes || 15);
      if (active) active.checked = (Number(s.active) === 1);
      if (tz) tz.value = 'IST';
    });
  })();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
