<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();

$campaigns = [];
$rsCamp = $conn->query("SELECT c.id, c.name, d.code, d.client_code FROM campaigns c LEFT JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
if ($rsCamp) $campaigns = $rsCamp->fetch_all(MYSQLI_ASSOC) ?: [];

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];
$start = sprintf('%04d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_expense') {
            $expenseDate = trim((string)($_POST['expense_date'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $amount = (float)($_POST['amount'] ?? 0);
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'INR')));
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            $campaignId = $campaignId > 0 ? $campaignId : null;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
                $message = 'Invalid date.';
                $messageType = 'danger';
            } elseif ($category === '' || $amount <= 0) {
                $message = 'Category and Amount are required.';
                $messageType = 'danger';
            } else {
                $createdBy = (int)($user['id'] ?? 0);
                $stmt = $conn->prepare("INSERT INTO revenue_manual_expenses (expense_date, category, description, amount, currency, campaign_id, created_by) VALUES (?,?,?,?,?,?,?)");
                if ($stmt) {
                    $stmt->bind_param('sssdsii', $expenseDate, $category, $desc, $amount, $currency, $campaignId, $createdBy);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Expense added.' : 'Failed to add expense.';
                    $messageType = $ok ? 'success' : 'danger';
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        } elseif ($action === 'update_expense') {
            $id = (int)($_POST['id'] ?? 0);
            $expenseDate = trim((string)($_POST['expense_date'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $amount = (float)($_POST['amount'] ?? 0);
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'INR')));
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            $campaignId = $campaignId > 0 ? $campaignId : null;
            if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
                $message = 'Invalid expense update.';
                $messageType = 'danger';
            } elseif ($category === '' || $amount <= 0) {
                $message = 'Category and Amount are required.';
                $messageType = 'danger';
            } else {
                $updatedBy = (int)($user['id'] ?? 0);
                $stmt = $conn->prepare("
                    UPDATE revenue_manual_expenses
                    SET expense_date = ?, category = ?, description = ?, amount = ?, currency = ?, campaign_id = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param('sssdsiii', $expenseDate, $category, $desc, $amount, $currency, $campaignId, $updatedBy, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Expense updated.' : 'Failed to update expense.';
                    $messageType = $ok ? 'success' : 'danger';
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        } elseif ($action === 'delete_expense') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $message = 'Invalid expense.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("DELETE FROM revenue_manual_expenses WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Expense deleted.' : 'Failed to delete expense.';
                    $messageType = $ok ? 'success' : 'danger';
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

$rows = [];
$stmt = $conn->prepare("
    SELECT e.*,
           c.name AS campaign_name,
           d.code AS campaign_code,
           d.client_code AS campaign_client_code,
           creator.full_name AS created_by_name,
           updater.full_name AS updated_by_name
    FROM revenue_manual_expenses e
    LEFT JOIN campaigns c ON c.id = e.campaign_id
    LEFT JOIN campaign_details d ON d.campaign_id = e.campaign_id
    LEFT JOIN users creator ON creator.id = e.created_by
    LEFT JOIN users updater ON updater.id = e.updated_by
    WHERE e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC
");
if ($stmt) {
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$total = 0.0;
foreach ($rows as $r) $total += (float)($r['amount'] ?? 0);
$totalsByCurrency = [];
foreach ($rows as $r) {
    $cur = strtoupper(trim((string)($r['currency'] ?? 'INR')));
    if ($cur === '') $cur = 'INR';
    if (!isset($totalsByCurrency[$cur])) $totalsByCurrency[$cur] = 0.0;
    $totalsByCurrency[$cur] += (float)($r['amount'] ?? 0);
}
$totalLabel = empty($totalsByCurrency) ? 'INR 0.00' : implode(' · ', array_map(
    static fn($cur, $val) => $cur . ' ' . number_format((float)$val, 2),
    array_keys($totalsByCurrency),
    array_values($totalsByCurrency)
));

$pageTitle = 'Revenue Expenses';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace revenue-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-receipt-cutoff"></i>Expense Control</div>
            <h3 class="sales-page-title">Manual Expenses</h3>
            <div class="sales-page-subtitle">Track manual revenue-side costs by month so dashboard profitability and ROI views include real operational expenses.</div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="revenue.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-currency-dollar me-1"></i>Campaign Revenue</a>
            <a class="btn btn-light border btn-sm" href="invoices.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-file-earmark-text me-1"></i>Invoices</a>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav revenue-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="revenue.php?month=<?php echo urlencode($monthStr); ?>">Campaign Revenue</a></li>
        <li class="nav-item"><a class="nav-link active" href="expenses.php?month=<?php echo urlencode($monthStr); ?>">Expenses</a></li>
        <li class="nav-item"><a class="nav-link" href="invoices.php?month=<?php echo urlencode($monthStr); ?>">Invoices</a></li>
        <li class="nav-item"><a class="nav-link" href="billto-templates.php">Bill To Templates</a></li>
        <li class="nav-item"><a class="nav-link" href="agent-roi.php?month=<?php echo urlencode($monthStr); ?>">Agent ROI</a></li>
    </ul>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3"><div class="revenue-callout h-100"><div class="revenue-callout-title">Expense Rows</div><div class="revenue-callout-value"><?php echo number_format(count($rows)); ?></div><div class="small text-muted mt-1"><?php echo htmlspecialchars($monthStr); ?></div></div></div>
        <div class="col-md-6 col-xl-4"><div class="revenue-callout h-100"><div class="revenue-callout-title">Expense Total</div><div class="revenue-callout-value"><?php echo htmlspecialchars($totalLabel); ?></div><div class="small text-muted mt-1">Grouped by entered currency for the selected month</div></div></div>
    </div>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel revenue-panel mb-3">
        <div class="card-body">
            <div class="revenue-filter-shell">
                <div class="revenue-filter-meta">
                    <div>
                        <div class="revenue-section-title">Expense Filters</div>
                        <div class="revenue-section-text">Keep manual cost review aligned with the selected revenue month before updating dashboard profitability and ROI.</div>
                    </div>
                    <div class="revenue-chip-row">
                        <span class="revenue-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
                        <span class="revenue-chip"><i class="bi bi-receipt-cutoff"></i><?php echo number_format(count($rows)); ?> entries</span>
                    </div>
                </div>
                <form method="get" class="row g-3 align-items-end sales-filter-form revenue-inline-form">
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1">Month</label>
                    <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthStr); ?>">
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm sales-panel revenue-panel mb-3">
        <div class="card-header fw-semibold">Add Extra Expense</div>
        <div class="card-body">
            <div class="revenue-grid-2">
                <div class="revenue-section-shell">
                    <div class="revenue-section-title">Expense Capture Workspace</div>
                    <div class="revenue-section-text mt-1">Log tools, travel, vendor, and campaign-linked costs with enough detail so the dashboard and profitability reports remain dependable.</div>
                    <div class="revenue-chip-row mt-3">
                        <span class="revenue-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($monthStr); ?></span>
                        <span class="revenue-chip"><i class="bi bi-diagram-3"></i><?php echo number_format(count($campaigns)); ?> campaigns available</span>
                    </div>
                </div>
                <form method="post" class="row g-3 align-items-end revenue-form-card">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add_expense">
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Date</label>
                        <input class="form-control" type="date" name="expense_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Category</label>
                        <input class="form-control" name="category" placeholder="Office / Tools / Travel" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small text-muted">Campaign</label>
                        <select class="form-select" name="campaign_id">
                            <option value="">General / Not linked</option>
                            <?php foreach ($campaigns as $campaign): ?>
                                <option value="<?php echo (int)($campaign['id'] ?? 0); ?>"><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($campaign)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small text-muted">Description</label>
                        <input class="form-control" name="description" placeholder="Optional">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Amount</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="amount" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Currency</label>
                        <input class="form-control" name="currency" value="INR">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle me-1"></i>Add Expense</button>
                        <a class="btn btn-light border" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel revenue-panel">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold">Expenses List</div>
            <div class="text-muted small">Total: <?php echo htmlspecialchars($totalLabel); ?></div>
        </div>
        <div class="table-responsive sales-table-responsive revenue-table-wrap">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Campaign</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                        <th>Audit</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No expenses in this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php $expenseCollapseId = 'expense-' . (int)($r['id'] ?? 0); ?>
                            <tr>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($r['expense_date'] ?? '')); ?></td>
                                <td>
                                    <div class="revenue-row-title"><?php echo htmlspecialchars((string)($r['category'] ?? '')); ?></div>
                                    <div class="revenue-row-subtitle">Manual expense entry</div>
                                </td>
                                <td>
                                    <?php if ((int)($r['campaign_id'] ?? 0) > 0): ?>
                                        <div class="revenue-entity-block">
                                            <div class="revenue-entity-title"><?php echo htmlspecialchars((string)($r['campaign_name'] ?? 'Campaign')); ?></div>
                                            <div class="revenue-entity-subline">
                                                <span class="revenue-entity-chip">Campaign</span>
                                                <span><?php echo htmlspecialchars(trim((string)($r['campaign_code'] ?? ''))); ?><?php echo !empty($r['campaign_client_code']) ? (' · ' . htmlspecialchars((string)$r['campaign_client_code'])) : ''; ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">General</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['description'] ?? '')); ?></td>
                                <td class="text-end"><?php echo htmlspecialchars(((string)($r['currency'] ?? 'INR')) . ' ' . number_format((float)($r['amount'] ?? 0), 2)); ?></td>
                                <td class="text-muted small">
                                    <div>Created: <?php echo htmlspecialchars((string)($r['created_by_name'] ?? 'System')); ?></div>
                                    <div><?php echo htmlspecialchars((string)($r['created_at'] ?? '')); ?></div>
                                    <?php if (!empty($r['updated_at'])): ?>
                                        <div class="performance-audit-note mt-1">Updated: <?php echo htmlspecialchars((string)($r['updated_by_name'] ?? 'System')); ?> · <?php echo htmlspecialchars((string)$r['updated_at']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="revenue-pill-actions">
                                        <button class="revenue-pill-btn" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($expenseCollapseId); ?>" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($expenseCollapseId); ?>"><i class="bi bi-pencil-square"></i><span>Edit</span></button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this expense?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="delete_expense">
                                            <input type="hidden" name="id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                            <button class="revenue-pill-btn revenue-pill-btn-danger" type="submit"><i class="bi bi-trash"></i><span>Delete</span></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr class="collapse performance-drilldown-row" id="<?php echo htmlspecialchars($expenseCollapseId); ?>">
                                <td colspan="7">
                                    <form method="post" class="row g-3 align-items-end sales-filter-form revenue-inline-form revenue-form-card">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="update_expense">
                                        <input type="hidden" name="id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted">Date</label>
                                            <input class="form-control form-control-sm" type="date" name="expense_date" value="<?php echo htmlspecialchars((string)($r['expense_date'] ?? '')); ?>" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted">Category</label>
                                            <input class="form-control form-control-sm" name="category" value="<?php echo htmlspecialchars((string)($r['category'] ?? '')); ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted">Campaign</label>
                                            <select class="form-select form-select-sm" name="campaign_id">
                                                <option value="">General / Not linked</option>
                                                <?php foreach ($campaigns as $campaign): ?>
                                                    <?php $campaignId = (int)($campaign['id'] ?? 0); ?>
                                                    <option value="<?php echo $campaignId; ?>" <?php echo $campaignId === (int)($r['campaign_id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($campaign)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted">Description</label>
                                            <input class="form-control form-control-sm" name="description" value="<?php echo htmlspecialchars((string)($r['description'] ?? '')); ?>">
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small text-muted">Amount</label>
                                            <input class="form-control form-control-sm" type="number" step="0.01" min="0" name="amount" value="<?php echo htmlspecialchars((string)($r['amount'] ?? 0)); ?>" required>
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small text-muted">Currency</label>
                                            <input class="form-control form-control-sm" name="currency" value="<?php echo htmlspecialchars((string)($r['currency'] ?? 'INR')); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-save me-1"></i>Update</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="revenue-stat-note">These manual expenses now support campaign linking and inline editing, so dashboard profitability, campaign analytics, and ROI views stay better aligned with actual operating spend.</div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
