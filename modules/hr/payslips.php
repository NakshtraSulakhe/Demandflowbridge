<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('hr.payslips.page', ['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
$user = getCurrentUser();

$isAdmin = in_array((string)($user['role'] ?? ''), ['admin'], true);
$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$userId = ($isAdmin && $requestedUserId > 0) ? $requestedUserId : (int)($user['id'] ?? 0);

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

$conn = getDbConnection();
if ($isAdmin && $requestedUserId <= 0) {
    $stmt = $conn->prepare("SELECT p.user_id, u.full_name, u.job_title, u.role, u.department, p.year, p.month, p.generated_at FROM hr_payslips p JOIN users u ON u.id = p.user_id WHERE p.year = ? AND p.month = ? ORDER BY u.full_name");
    $rows = [];
    if ($stmt) {
        $stmt->bind_param('ii', $viewYear, $viewMonth);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT p.user_id, u.full_name, u.job_title, u.role, u.department, p.year, p.month, p.generated_at FROM hr_payslips p JOIN users u ON u.id = p.user_id WHERE p.user_id = ? ORDER BY p.year DESC, p.month DESC LIMIT 24");
    $rows = [];
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    $rows = array_values(array_filter($rows, static function(array $row): bool {
        return !hrIsPayrollLocked((int)($row['year'] ?? 0), (int)($row['month'] ?? 0));
    }));
}

$selectedMonthLocked = hrIsPayrollLocked($viewYear, $viewMonth);

function monthName($y, $m) { return date('F Y', mktime(0,0,0,$m,1,$y)); }
?>
<?php $pageTitle = 'Payslips'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="row g-3">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-receipt fs-5"></i>
                        <div class="fw-semibold">Payslips</div>
                    </div>
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <input type="month" name="month" class="form-control form-control-sm" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                        <?php if ($isAdmin): ?>
                            <input class="form-control form-control-sm" name="user_id" value="<?php echo $requestedUserId > 0 ? (int)$requestedUserId : ''; ?>" placeholder="User ID (optional)">
                        <?php endif; ?>
                        <button class="btn btn-light btn-sm" type="submit"><i class="bi bi-funnel"></i> View</button>
                    </form>
                </div>
                <div class="card-body">
                    <?php if (!$isAdmin && $selectedMonthLocked): ?>
                        <div class="alert alert-warning border-0 shadow-sm">
                            Payslips are hidden from employees while payroll is locked. They become visible again after payroll is unlocked.
                        </div>
                    <?php endif; ?>
                    <?php if ($isAdmin && $requestedUserId <= 0): ?>
                        <div class="fw-semibold mb-2"><?php echo htmlspecialchars(monthName($viewYear, $viewMonth)); ?></div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Job Title</th>
                                    <th>Department</th>
                                    <th>Month</th>
                                    <th>Generated</th>
                                    <th class="text-end">View</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php $mStr = sprintf('%04d-%02d', (int)$r['year'], (int)$r['month']); ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['job_title'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['department'] ?? '')); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars($mStr); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['generated_at'] ?? '')); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-outline-primary btn-sm" href="payslip-view?month=<?php echo urlencode($mStr); ?>&user_id=<?php echo (int)$r['user_id']; ?>"><i class="bi bi-eye"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">No payslips</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
