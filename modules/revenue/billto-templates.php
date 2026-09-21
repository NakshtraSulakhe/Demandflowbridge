<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();
$uid = (int)($user['id'] ?? 0);

$message = '';
$messageType = 'success';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$q = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $editId = (int)($_POST['id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            $clientCode = trim((string)($_POST['client_code'] ?? ''));
            $billToName = trim((string)($_POST['bill_to_name'] ?? ''));
            $billToAddress = trim((string)($_POST['bill_to_address'] ?? ''));
            $billToContacts = trim((string)($_POST['bill_to_contacts'] ?? ''));

            if ($label === '') {
                $message = 'Template label is required.';
                $messageType = 'danger';
            } elseif ($uid <= 0) {
                $message = 'Invalid user.';
                $messageType = 'danger';
            } else {
                $clientId = null;
                if ($clientCode !== '') {
                    $st = $conn->prepare("SELECT id FROM clients WHERE client_code = ? LIMIT 1");
                    if ($st) {
                        $st->bind_param('s', $clientCode);
                        $st->execute();
                        $clientId = (int)(($st->get_result()->fetch_assoc() ?: [])['id'] ?? 0);
                        $st->close();
                        if ($clientId <= 0) $clientId = null;
                    }
                }

                if ($editId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE revenue_invoice_billto_profiles
                        SET label = ?, client_id = ?, client_code = ?, bill_to_name = ?, bill_to_address = ?, bill_to_contacts = ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                        LIMIT 1
                    ");
                    if ($stmt) {
                        $clientIdVal = $clientId ? (int)$clientId : null;
                        $stmt->bind_param('sissssii', $label, $clientIdVal, $clientCode, $billToName, $billToAddress, $billToContacts, $editId, $uid);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Template updated.' : 'Unable to update template.';
                        $messageType = $ok ? 'success' : 'danger';
                        $id = $ok ? $editId : $id;
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO revenue_invoice_billto_profiles (user_id, label, client_id, client_code, bill_to_name, bill_to_address, bill_to_contacts)
                        VALUES (?,?,?,?,?,?,?)
                    ");
                    if ($stmt) {
                        $clientIdVal = $clientId ? (int)$clientId : null;
                        $stmt->bind_param('isissss', $uid, $label, $clientIdVal, $clientCode, $billToName, $billToAddress, $billToContacts);
                        $ok = $stmt->execute();
                        $newId = (int)$conn->insert_id;
                        $stmt->close();
                        $message = $ok ? 'Template created.' : 'Unable to create template.';
                        $messageType = $ok ? 'success' : 'danger';
                        if ($ok && $newId > 0) $id = $newId;
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $delId = (int)($_POST['id'] ?? 0);
            if ($delId <= 0) {
                $message = 'Invalid template.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("DELETE FROM revenue_invoice_billto_profiles WHERE id = ? AND user_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $delId, $uid);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Template deleted.' : 'Unable to delete template.';
                    $messageType = $ok ? 'success' : 'danger';
                    if ($ok && $id === $delId) $id = 0;
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

$edit = null;
if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM revenue_invoice_billto_profiles WHERE id = ? AND user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $edit = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

$where = "WHERE user_id = ?";
$params = [$uid];
$types = 'i';
if ($q !== '') {
    $where .= " AND (label LIKE ? OR client_code LIKE ? OR bill_to_name LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$rows = [];
$stmt = $conn->prepare("SELECT id, label, client_code, bill_to_name, updated_at FROM revenue_invoice_billto_profiles {$where} ORDER BY updated_at DESC, id DESC");
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$pageTitle = 'Bill To Templates';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace revenue-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-journal-bookmark"></i>Billing Templates</div>
            <h3 class="sales-page-title">Bill To Templates</h3>
            <div class="sales-page-subtitle">Save reusable client billing blocks for invoices so recurring billing details stay accurate and fast to apply across the revenue workflow.</div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="invoices"><i class="bi bi-file-earmark-text me-1"></i>Invoices</a>
            <a class="btn btn-light border btn-sm" href="billto-templates"><i class="bi bi-plus-circle me-1"></i>New</a>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav revenue-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="revenue-dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="revenue.php">Campaign Revenue</a></li>
        <li class="nav-item"><a class="nav-link" href="expenses.php">Expenses</a></li>
        <li class="nav-item"><a class="nav-link" href="invoices.php">Invoices</a></li>
        <li class="nav-item"><a class="nav-link active" href="billto-templates.php">Bill To Templates</a></li>
        <li class="nav-item"><a class="nav-link" href="agent-roi.php">Agent ROI</a></li>
    </ul>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm sales-panel revenue-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><?php echo $edit ? 'Edit Template' : 'Create Template'; ?></div>
                    <?php if ($edit): ?>
                        <span class="badge bg-primary-subtle text-primary border">#<?php echo (int)$edit['id']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="revenue-section-shell mb-3">
                        <div class="revenue-section-title">Reusable Billing Profiles</div>
                        <div class="revenue-section-text mt-1">Keep invoice billing details standardized with saved templates, so recurring clients can be billed faster and with fewer manual corrections.</div>
                    </div>
                    <form method="post" class="revenue-form-card">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo $edit ? (int)$edit['id'] : 0; ?>">

                        <div class="mb-2">
                            <label class="form-label small text-muted">Template Label</label>
                            <input class="form-control form-control-sm" name="label" value="<?php echo htmlspecialchars((string)($edit['label'] ?? '')); ?>" placeholder="e.g. Prezent Finance Team" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Client Code (optional)</label>
                            <input class="form-control form-control-sm" name="client_code" value="<?php echo htmlspecialchars((string)($edit['client_code'] ?? '')); ?>" placeholder="e.g. PREZENT">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Bill To Name</label>
                            <input class="form-control form-control-sm" name="bill_to_name" value="<?php echo htmlspecialchars((string)($edit['bill_to_name'] ?? '')); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Bill To Address</label>
                            <textarea class="form-control form-control-sm" name="bill_to_address" rows="4"><?php echo htmlspecialchars((string)($edit['bill_to_address'] ?? '')); ?></textarea>
                        </div>
                        <div>
                            <label class="form-label small text-muted">Contact Details</label>
                            <textarea class="form-control form-control-sm" name="bill_to_contacts" rows="4" placeholder="Email: a@x.com / b@y.com&#10;Phone: +91... / +1...&#10;Name: Person 1 / Person 2"><?php echo htmlspecialchars((string)($edit['bill_to_contacts'] ?? '')); ?></textarea>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2 me-1"></i>Save</button>
                            <?php if ($edit): ?>
                                <a class="btn btn-light border btn-sm" href="billto-templates"><i class="bi bi-x-circle me-1"></i>Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm sales-panel revenue-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Templates List</div>
                </div>
                <div class="card-body pb-0">
                    <div class="revenue-filter-shell mb-3">
                        <div class="revenue-filter-meta">
                            <div>
                                <div class="revenue-section-title">Template Search</div>
                                <div class="revenue-section-text">Find existing billing templates quickly by label, client code, or billing name before editing or deleting them.</div>
                            </div>
                            <div class="revenue-chip-row">
                                <span class="revenue-chip"><i class="bi bi-journal-richtext"></i><?php echo number_format(count($rows)); ?> template<?php echo count($rows) === 1 ? '' : 's'; ?></span>
                            </div>
                        </div>
                        <form method="get" class="row g-3 align-items-end sales-filter-form revenue-inline-form">
                            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                            <div class="col-md-9">
                                <label class="form-label small text-muted mb-1">Search</label>
                                <input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Label / Client Code / Name">
                            </div>
                            <div class="col-md-3 d-grid">
                                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="table-responsive sales-table-responsive revenue-table-wrap">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Label</th>
                                <th>Client</th>
                                <th>Bill To</th>
                                <th>Updated</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No templates yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php $rid = (int)($r['id'] ?? 0); ?>
                                    <tr>
                                        <td>
                                            <div class="revenue-entity-block">
                                                <a class="revenue-invoice-link" href="billto-templates?id=<?php echo $rid; ?>&q=<?php echo urlencode($q); ?>">
                                                    <?php echo htmlspecialchars((string)($r['label'] ?? '')); ?>
                                                </a>
                                                <div class="revenue-entity-subline">
                                                    <span class="revenue-entity-chip">Template</span>
                                                    <span>ID #<?php echo $rid; ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['client_code'] ?? '')); ?></td>
                                        <td>
                                            <div class="revenue-row-title"><?php echo htmlspecialchars((string)($r['bill_to_name'] ?? '')); ?></div>
                                            <div class="revenue-row-subtitle">Saved bill-to contact block</div>
                                        </td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['updated_at'] ?? '')); ?></td>
                                        <td class="text-end">
                                            <div class="revenue-pill-actions" role="group">
                                                <a class="revenue-pill-btn" href="billto-templates?id=<?php echo $rid; ?>&q=<?php echo urlencode($q); ?>"><i class="bi bi-pencil"></i><span>Edit</span></a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this template?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                    <button class="revenue-pill-btn revenue-pill-btn-danger" type="submit"><i class="bi bi-trash"></i><span>Delete</span></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="revenue-stat-note">Templates connect directly into `invoice-edit.php`, where they can be loaded into active invoice drafts without retyping bill-to details.</div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
