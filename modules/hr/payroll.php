<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.payroll.page', ['admin', 'director', 'manager_director']);
ensureCsrfToken();
$user = getCurrentUser();

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

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $monthStr = (string)($_POST['month'] ?? $selMonthStr);
        if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $mm)) {
            $message = 'Invalid month.';
            $messageType = 'danger';
        } else {
            $yy = (int)$mm[1];
            $mo = (int)$mm[2];
            $viewYear = $yy; $viewMonth = $mo; $selMonthStr = sprintf('%04d-%02d', $viewYear, $viewMonth);
            if ($action === 'generate') {
                if (hrIsPayrollLocked($yy, $mo)) {
                    $message = 'Payroll month is locked.';
                    $messageType = 'danger';
                } else {
                    $users = getInternalPayrollUsers();
                    $count = 0;
                    $missingSalary = 0;
                    $failed = 0;
                    foreach ($users as $u) {
                        $uid = (int)($u['id'] ?? 0);
                        if ($uid <= 0) continue;
                        $pay = computePayrollForUserMonth($uid, $yy, $mo);
                        if (!$pay) { $missingSalary++; continue; }
                        $payload = [
                            'user' => [
                                'id' => $uid,
                                'name' => (string)($u['full_name'] ?? ''),
                                'role' => (string)($u['role'] ?? ''),
                                'job_title' => (string)($u['job_title'] ?? ''),
                                'department' => (string)($u['department'] ?? ''),
                                'employee_id' => (string)($u['employee_id'] ?? ''),
                            ],
                            'period' => [
                                'year' => $yy,
                                'month' => $mo,
                            ],
                            'bank' => getUserBankDetails($uid),
                            'attendance' => $pay['attendance'] ?? [],
                            'salary_structure' => $pay['salary_structure'] ?? [],
                            'earnings' => $pay['earnings'] ?? [],
                            'deductions' => $pay['deductions'] ?? [],
                            'net_salary' => $pay['net_salary'] ?? 0,
                        ];
                        if (upsertPayslip($uid, $yy, $mo, $payload, (int)$user['id'])) {
                            finalizeLoanDeductionsForMonth($uid, $yy, $mo);
                            $count++;
                        } else {
                            $failed++;
                        }
                    }
                    $message = 'Payslips generated/updated: ' . $count . '. Missing salary setup: ' . $missingSalary . '. Failed saves: ' . $failed . '.';
                    $messageType = ($count > 0 && $failed === 0) ? 'success' : (($count > 0) ? 'warning' : 'danger');
                }
            } elseif ($action === 'lock') {
                $ok = hrLockPayrollMonth($yy, $mo, (int)$user['id']);
                $message = $ok ? 'Payroll locked.' : 'Failed to lock payroll.';
                $messageType = $ok ? 'success' : 'danger';
            } elseif ($action === 'unlock') {
                $ok = hrUnlockPayrollMonth($yy, $mo);
                $message = $ok ? 'Payroll unlocked.' : 'Failed to unlock payroll.';
                $messageType = $ok ? 'success' : 'danger';
            } elseif ($action === 'delete_payslip') {
                if (hrIsPayrollLocked($yy, $mo)) {
                    $message = 'Unlock payroll before deleting payslips.';
                    $messageType = 'danger';
                } else {
                    $deleteUserId = (int)($_POST['delete_user_id'] ?? 0);
                    $ok = deletePayslip($deleteUserId, $yy, $mo);
                    $message = $ok ? 'Payslip deleted.' : 'Failed to delete payslip.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            } elseif ($action === 'delete_month_payslips') {
                if (hrIsPayrollLocked($yy, $mo)) {
                    $message = 'Unlock payroll before deleting payslips.';
                    $messageType = 'danger';
                } else {
                    $ok = deletePayslipsForMonth($yy, $mo);
                    $message = $ok ? 'All payslips for the selected month were deleted.' : 'Failed to delete month payslips.';
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        }
    }
}

$isLocked = hrIsPayrollLocked($viewYear, $viewMonth);
$holidays = getHolidaysForMonth($viewYear, $viewMonth, 'US');
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT p.user_id, u.full_name, u.job_title, u.role, u.department, p.generated_at FROM hr_payslips p JOIN users u ON u.id = p.user_id WHERE p.year = ? AND p.month = ? ORDER BY u.full_name");
$payslips = [];
if ($stmt) {
    $stmt->bind_param('ii', $viewYear, $viewMonth);


    
    $stmt->execute();
    $payslips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

function monthName($y, $m) { return date('F Y', mktime(0,0,0,$m,1,$y)); }
?>
<?php $pageTitle = 'Payroll'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Payroll'],
            ],
            'Payroll',
            'Generate payslips, lock payroll, export accounts summary',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
                ['label' => 'Payslips', 'href' => 'payslips?month=' . urlencode($selMonthStr), 'icon' => 'bi-receipt', 'class' => 'btn-outline-secondary'],
                ['label' => 'Export Summary', 'href' => 'payroll-export?month=' . urlencode($selMonthStr), 'icon' => 'bi-download', 'class' => 'btn-outline-secondary'],
            ],
            [
                ['text' => $isLocked ? 'Payroll Locked' : 'Payroll Open', 'class' => $isLocked ? 'bg-danger' : 'bg-secondary'],
            ]
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Month</label>
                            <form method="get" class="d-flex gap-2">
                                <input type="month" name="month" class="form-control form-control-sm" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-funnel"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <?php hrKpi('Payslips Generated', (string)number_format(count($payslips)), 'bi-receipt'); ?>
                                </div>
                                <div class="col-md-4">
                                    <?php hrKpi('US Holidays (Month)', (string)number_format(count($holidays)), 'bi-calendar-event', 'Used for working-days'); ?>
                                </div>
                                <div class="col-md-4">
                                    <?php hrKpi('Accounts Export', 'CSV', 'bi-download', 'payroll_summary_' . $selMonthStr . '.csv'); ?>
                                </div>
                            </div>

                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-list-check me-1"></i> Payslips</span>
                                    <span class="badge bg-secondary"><?php echo number_format(count($payslips)); ?></span>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle mb-0 hr-table">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Job Title</th>
                                                    <th>Department</th>
                                                    <th>Generated</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($payslips as $p): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($p['full_name'] ?? '')); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($p['job_title'] ?? '')); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($p['department'] ?? '')); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($p['generated_at'] ?? '')); ?></td>
                                                        <td class="text-end">
                                                            <div class="d-inline-flex gap-2">
                                                                <a class="btn btn-outline-primary btn-sm" href="payslip-view?month=<?php echo urlencode($selMonthStr); ?>&user_id=<?php echo (int)$p['user_id']; ?>"><i class="bi bi-eye"></i></a>
                                                                <form method="post" class="m-0" onsubmit="return confirm('Delete this payslip?');">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                    <input type="hidden" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                                                                    <input type="hidden" name="action" value="delete_payslip">
                                                                    <input type="hidden" name="delete_user_id" value="<?php echo (int)$p['user_id']; ?>">
                                                                    <button class="btn btn-outline-danger btn-sm" type="submit" <?php echo $isLocked ? 'disabled' : ''; ?>><i class="bi bi-trash"></i></button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($payslips)): ?>
                                                    <tr><td colspan="5" class="text-center text-muted">No payslips generated.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card hr-card mb-3">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-lightning-charge me-1"></i> Actions</div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                                            <input type="hidden" name="action" value="generate">
                                            <button class="btn btn-success btn-sm" type="submit" <?php echo $isLocked ? 'disabled' : ''; ?>><i class="bi bi-gear"></i> Generate Payslips</button>
                                        </form>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                                            <?php if ($isLocked): ?>
                                                <input type="hidden" name="action" value="unlock">
                                                <button class="btn btn-warning btn-sm" type="submit"><i class="bi bi-unlock"></i> Unlock Payroll</button>
                                            <?php else: ?>
                                                <input type="hidden" name="action" value="lock">
                                                <button class="btn btn-danger btn-sm" type="submit"><i class="bi bi-lock"></i> Lock Payroll</button>
                                            <?php endif; ?>
                                        </form>
                                        <a class="btn btn-outline-primary btn-sm" href="payroll-export?month=<?php echo urlencode($selMonthStr); ?>"><i class="bi bi-download"></i> Export Summary</a>
                                        <a class="btn btn-outline-secondary btn-sm" href="payslips?month=<?php echo urlencode($selMonthStr); ?>"><i class="bi bi-receipt"></i> Payslips</a>
                                        <form method="post" class="m-0" onsubmit="return confirm('Delete all payslips for <?php echo htmlspecialchars($selMonthStr); ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                                            <input type="hidden" name="action" value="delete_month_payslips">
                                            <button class="btn btn-outline-danger btn-sm w-100" type="submit" <?php echo $isLocked ? 'disabled' : ''; ?>><i class="bi bi-trash3"></i> Delete Month Payslips</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="card hr-card mb-3">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <span><i class="bi bi-calculator me-1"></i> Salary Proration Rule</span>
                                    <span class="badge bg-info-subtle text-info border">New Joiners Updated</span>
                                </div>
                                <div class="card-body">
                                    <div class="small text-muted mb-3">
                                        Joining-month payroll now uses the full month's working days as the salary base. The daily rate is derived from the monthly salary, and payable salary is calculated using present days plus half-day support.
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="border rounded p-2">
                                                <div class="fw-semibold mb-1">Standard Month</div>
                                                <div class="text-muted small">Monthly salary × paid days / salary base days</div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="border rounded p-2">
                                                <div class="fw-semibold mb-1">Joining Month</div>
                                                <div class="text-muted small">Monthly salary / full-month working days × present days (+ half days as 0.5)</div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="border rounded p-2">
                                                <div class="fw-semibold mb-1">Audit Visibility</div>
                                                <div class="text-muted small">Base days, payable days, daily salary rate, and proration rule are shown in payslips and payroll export.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-calendar-event me-1"></i> US Holidays</span>
                                    <span class="badge bg-secondary"><?php echo number_format(count($holidays)); ?></span>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($holidays)): ?>
                                        <div class="text-muted">No holidays in this month.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0 hr-table">
                                                <tbody>
                                                    <?php foreach ($holidays as $h): ?>
                                                        <tr>
                                                            <td class="font-monospace"><?php echo htmlspecialchars((string)($h['holiday_date'] ?? '')); ?></td>
                                                            <td class="text-muted small"><?php echo htmlspecialchars((string)($h['name'] ?? '')); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
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
