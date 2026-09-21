<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('hr.bonus_loans.page', ['admin', 'director', 'manager_director']);
ensureCsrfToken();
$user = getCurrentUser();

$message = '';
$messageType = 'success';

$now = new DateTimeImmutable('now', hrBaseTz());
$selMonthStr = isset($_GET['month']) ? (string)$_GET['month'] : $now->format('Y-m');
if (preg_match('/^(\d{4})-(\d{2})$/', $selMonthStr, $m)) {
    $viewYear = (int)$m[1];
    $viewMonth = (int)$m[2];
} else {
    $viewYear = (int)$now->format('Y');
    $viewMonth = (int)$now->format('m');
    $selMonthStr = sprintf('%04d-%02d', $viewYear, $viewMonth);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_bonus') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $monthStr = (string)($_POST['month'] ?? $selMonthStr);
            $amount = (float)($_POST['amount'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $mm) || $userId <= 0 || $amount <= 0) {
                $message = 'Invalid bonus input.';
                $messageType = 'danger';
            } else {
                $yy = (int)$mm[1];
                $mo = (int)$mm[2];
                if (hrIsPayrollLocked($yy, $mo)) {
                    $message = 'Payroll month is locked.';
                    $messageType = 'danger';
                } else {
                    ensureDatabaseSchema();
                    $conn = getDbConnection();
                    $dup = $conn->prepare("SELECT id FROM hr_bonuses WHERE user_id = ? AND year = ? AND month = ? AND COALESCE(reason, '') = ? LIMIT 1");
                    $duplicateId = 0;
                    if ($dup) {
                        $stmtReason = $reason;
                        $dup->bind_param('iiis', $userId, $yy, $mo, $stmtReason);
                        $dup->execute();
                        $duplicateId = (int)(($dup->get_result()->fetch_assoc() ?: [])['id'] ?? 0);
                        $dup->close();
                    }
                    if ($duplicateId > 0) {
                        $message = 'A bonus already exists for this employee, month, and reason.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $conn->prepare("INSERT INTO hr_bonuses (user_id, year, month, amount, reason) VALUES (?,?,?,?,?)");
                        if ($stmt) {
                            $stmt->bind_param('iiids', $userId, $yy, $mo, $amount, $reason);
                            $ok = $stmt->execute();
                            $stmt->close();
                            $message = $ok ? 'Bonus added.' : 'Failed to add bonus.';
                            $messageType = $ok ? 'success' : 'danger';
                            $viewYear = $yy; $viewMonth = $mo; $selMonthStr = sprintf('%04d-%02d', $viewYear, $viewMonth);
                        }
                    }
                }
            }
        } elseif ($action === 'add_loan') {
            $userId = (int)($_POST['loan_user_id'] ?? 0);
            $total = (float)($_POST['total_amount'] ?? 0);
            $emi = (float)($_POST['emi_amount'] ?? 0);
            $startDate = (string)($_POST['start_date'] ?? '');
            if ($userId <= 0 || $total <= 0 || $emi <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $message = 'Invalid loan input.';
                $messageType = 'danger';
            } else {
                ensureDatabaseSchema();
                $activeLoans = getActiveLoans($userId);
                if (!empty($activeLoans)) {
                    $message = 'Close the existing active loan before creating a new one for this employee.';
                    $messageType = 'danger';
                } else {
                    $conn = getDbConnection();
                    $stmt = $conn->prepare("INSERT INTO hr_loans (user_id, total_amount, remaining_amount, emi_amount, start_date, active) VALUES (?,?,?,?,?,1)");
                    if ($stmt) {
                        $stmt->bind_param('iddds', $userId, $total, $total, $emi, $startDate);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Loan added.' : 'Failed to add loan.';
                        $messageType = $ok ? 'success' : 'danger';
                    }
                }
            }
        } elseif ($action === 'close_loan') {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            if ($loanId <= 0) {
                $message = 'Invalid loan.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $stmt = $conn->prepare("UPDATE hr_loans SET active = 0 WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $loanId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Loan closed.' : 'Failed to close loan.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        }
    }
}

$users = getInternalPayrollUsers();

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT b.*, u.full_name FROM hr_bonuses b JOIN users u ON u.id = b.user_id WHERE b.year = ? AND b.month = ? ORDER BY b.id DESC");
$bonuses = [];
if ($stmt) {
    $stmt->bind_param('ii', $viewYear, $viewMonth);
    $stmt->execute();
    $bonuses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$loans = $conn->query("SELECT l.*, u.full_name FROM hr_loans l JOIN users u ON u.id = l.user_id ORDER BY l.active DESC, l.start_date DESC, l.id DESC");
$loanRows = $loans ? ($loans->fetch_all(MYSQLI_ASSOC) ?: []) : [];

function monthName($y, $m) { return date('F Y', mktime(0,0,0,$m,1,$y)); }
?>
<?php $pageTitle = 'Bonus & Loans'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="row g-3">
        <div class="col-12">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-gift fs-5"></i>
                        <div class="fw-semibold">Bonus & Loans</div>
                    </div>
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <input type="month" name="month" class="form-control form-control-sm" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                        <button class="btn btn-light btn-sm" type="submit"><i class="bi bi-calendar3"></i> View</button>
                    </form>
                </div>
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-gift me-1"></i> Add Bonus</div>
                                <div class="card-body">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="add_bonus">
                                        <div class="col-md-5">
                                            <label class="form-label small text-muted">User</label>
                                            <select class="form-select form-select-sm" name="user_id" required>
                                                <option value="">Select</option>
                                                <?php foreach ($users as $u): ?>
                                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted">Month</label>
                                            <input type="month" class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Amount</label>
                                            <input type="number" step="0.01" class="form-control form-control-sm" name="amount" min="1" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted">Reason</label>
                                            <input class="form-control form-control-sm" name="reason" placeholder="Reason (optional)">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-circle"></i> Add Bonus</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card border-0 shadow-sm mt-3">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-list-check me-1"></i> Bonuses: <?php echo htmlspecialchars(monthName($viewYear, $viewMonth)); ?></div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Reason</th>
                                                    <th class="text-end">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($bonuses as $b): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($b['full_name'] ?? '')); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($b['reason'] ?? '')); ?></td>
                                                        <td class="text-end">Rs. <?php echo number_format((float)($b['amount'] ?? 0), 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($bonuses)): ?>
                                                    <tr><td colspan="3" class="text-center text-muted">No bonuses</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-cash-coin me-1"></i> Add Loan / Advance</div>
                                <div class="card-body">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="add_loan">
                                        <div class="col-md-5">
                                            <label class="form-label small text-muted">User</label>
                                            <select class="form-select form-select-sm" name="loan_user_id" required>
                                                <option value="">Select</option>
                                                <?php foreach ($users as $u): ?>
                                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted">Total</label>
                                            <input type="number" step="0.01" class="form-control form-control-sm" name="total_amount" min="1" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">EMI / Month</label>
                                            <input type="number" step="0.01" class="form-control form-control-sm" name="emi_amount" min="1" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Start Date</label>
                                            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-circle"></i> Add Loan</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card border-0 shadow-sm mt-3">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-journal-text me-1"></i> Loans</div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Start</th>
                                                    <th class="text-end">Total</th>
                                                    <th class="text-end">Remaining</th>
                                                    <th class="text-end">EMI</th>
                                                    <th class="text-end">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($loanRows as $l): ?>
                                                    <?php $active = (int)($l['active'] ?? 0) === 1; ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($l['full_name'] ?? '')); ?></td>
                                                        <td class="font-monospace"><?php echo htmlspecialchars((string)($l['start_date'] ?? '')); ?></td>
                                                        <td class="text-end">Rs. <?php echo number_format((float)($l['total_amount'] ?? 0), 2); ?></td>
                                                        <td class="text-end">Rs. <?php echo number_format((float)($l['remaining_amount'] ?? 0), 2); ?></td>
                                                        <td class="text-end">Rs. <?php echo number_format((float)($l['emi_amount'] ?? 0), 2); ?></td>
                                                        <td class="text-end">
                                                            <?php if ($active): ?>
                                                                <form method="post" class="m-0">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                    <input type="hidden" name="action" value="close_loan">
                                                                    <input type="hidden" name="loan_id" value="<?php echo (int)$l['id']; ?>">
                                                                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-x-circle"></i> Close</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Closed</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($loanRows)): ?>
                                                    <tr><td colspan="6" class="text-center text-muted">No loans</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
