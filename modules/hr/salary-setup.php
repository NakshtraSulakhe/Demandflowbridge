<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.salary_setup.page', ['admin', 'director', 'manager_director']);
ensureCsrfToken();
$user = getCurrentUser();

$message = '';
$messageType = 'success';

$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editRow = null;
if ($editId > 0) {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT s.*, u.full_name FROM hr_salary_structures s JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editRow = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($editRow && (int)($editRow['locked'] ?? 0) === 1) {
            $message = 'This salary structure is locked and cannot be edited.';
            $messageType = 'danger';
            $editId = 0;
            $editRow = null;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_structure') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $structureId = (int)($_POST['structure_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $effectiveDate = (string)($_POST['effective_date'] ?? '');
        $type = normalizeSalaryStructureType((string)($_POST['structure_type'] ?? 'Standard'));
        $total = (float)($_POST['total_salary'] ?? 0);
        $locked = !empty($_POST['locked']) ? 1 : 0;

        $basic = (float)($_POST['basic'] ?? 0);
        $hra = (float)($_POST['hra'] ?? 0);
        $convey = (float)($_POST['conveyance'] ?? 0);
        $medical = (float)($_POST['medical'] ?? 0);
        $special = (float)($_POST['special_allowance'] ?? 0);
        $other = (float)($_POST['other_allowance'] ?? 0);
        $pf = (float)($_POST['pf'] ?? 0);
        $pt = (float)($_POST['professional_tax'] ?? 0);
        $tds = (float)($_POST['tds'] ?? 0);

        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            $message = 'Please select employee and valid effective date.';
            $messageType = 'danger';
        } else {
            $yy = (int)substr($effectiveDate, 0, 4);
            $mo = (int)substr($effectiveDate, 5, 2);
            if (hrIsPayrollLocked($yy, $mo)) {
                $message = 'Payroll month is locked. Add changes in a future month.';
                $messageType = 'danger';
            } else {
                ensureDatabaseSchema();
                if ($total <= 0) {
                    $total = $basic + $hra + $convey + $medical + $special + $other;
                }
                $sumEarn = $basic + $hra + $convey + $medical + $special + $other;
                if (abs($sumEarn - $total) > 0.01) {
                    $special = max(0, $special + ($total - $sumEarn));
                }
                $conn = getDbConnection();
                $dup = $conn->prepare("SELECT id FROM hr_salary_structures WHERE user_id = ? AND effective_date = ? AND id <> ? LIMIT 1");
                $duplicateId = 0;
                if ($dup) {
                    $dup->bind_param('isi', $userId, $effectiveDate, $structureId);
                    $dup->execute();
                    $duplicateId = (int)(($dup->get_result()->fetch_assoc() ?: [])['id'] ?? 0);
                    $dup->close();
                }
                if ($duplicateId > 0) {
                    $message = 'A salary structure already exists for this employee and effective date.';
                    $messageType = 'danger';
                } elseif ($structureId > 0) {
                    $chk = $conn->prepare("SELECT locked FROM hr_salary_structures WHERE id = ? LIMIT 1");
                    $lockedNow = null;
                    if ($chk) {
                        $chk->bind_param('i', $structureId);
                        $chk->execute();
                        $lockedNow = $chk->get_result()->fetch_assoc();
                        $chk->close();
                    }
                    if (!$lockedNow) {
                        $message = 'Record not found.';
                        $messageType = 'danger';
                    } elseif ((int)($lockedNow['locked'] ?? 0) === 1) {
                        $message = 'This salary structure is locked and cannot be edited.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE hr_salary_structures
                            SET
                                user_id = ?,
                                effective_date = ?,
                                structure_type = ?,
                                total_salary = ?,
                                basic = ?,
                                hra = ?,
                                conveyance = ?,
                                medical = ?,
                                special_allowance = ?,
                                other_allowance = ?,
                                pf = ?,
                                professional_tax = ?,
                                tds = ?,
                                locked = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        if ($stmt) {
                            $types = 'iss' . str_repeat('d', 10) . 'ii';
                            $stmt->bind_param($types, $userId, $effectiveDate, $type, $total, $basic, $hra, $convey, $medical, $special, $other, $pf, $pt, $tds, $locked, $structureId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            $message = $ok ? 'Salary structure updated.' : 'Failed to update.';
                            $messageType = $ok ? 'success' : 'danger';
                            $editId = 0;
                            $editRow = null;
                        } else {
                            $message = 'Database error.';
                            $messageType = 'danger';
                        }
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO hr_salary_structures
                            (user_id, effective_date, structure_type, total_salary, basic, hra, conveyance, medical, special_allowance, other_allowance, pf, professional_tax, tds, locked, created_at)
                        VALUES
                            (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    if ($stmt) {
                        $types = 'iss' . str_repeat('d', 10) . 'i';
                        $stmt->bind_param($types, $userId, $effectiveDate, $type, $total, $basic, $hra, $convey, $medical, $special, $other, $pf, $pt, $tds, $locked);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Salary structure saved.' : 'Failed to save.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

$users = getInternalPayrollUsers();
$conn = getDbConnection();
$rs = $conn->query("SELECT s.*, u.full_name FROM hr_salary_structures s JOIN users u ON u.id = s.user_id ORDER BY s.effective_date DESC, u.full_name ASC, s.id DESC LIMIT 200");
$rows = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];

$form = is_array($editRow) ? $editRow : [];
$formId = $editId > 0 ? $editId : 0;
$formUserId = (int)($form['user_id'] ?? 0);
$formEffectiveDate = (string)($form['effective_date'] ?? date('Y-m-d'));
$formType = normalizeSalaryStructureType((string)($form['structure_type'] ?? 'Standard'));
$formTotal = (string)($form['total_salary'] ?? '');
$formLocked = (int)($form['locked'] ?? 0) === 1;
?>
<?php $pageTitle = 'Salary Setup'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Salary Setup'],
            ],
            'Salary Setup',
            'Auto salary structure, PF/PT/TDS and payroll integration',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
            ]
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card mb-3">
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>

                    <form method="post" id="salaryForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="save_structure">
                        <input type="hidden" name="structure_id" value="<?php echo (int)$formId; ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Employee</label>
                                <select class="form-select" name="user_id" id="user_id" required>
                                    <option value="">Select</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?php echo (int)$u['id']; ?>" <?php echo ((int)$u['id'] === $formUserId) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Effective Date</label>
                                <input type="date" class="form-control" name="effective_date" id="effective_date" value="<?php echo htmlspecialchars($formEffectiveDate); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Monthly Salary (₹)</label>
                                <input type="number" step="0.01" class="form-control" name="total_salary" id="total_salary" min="0" value="<?php echo htmlspecialchars($formTotal); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="structure_type" id="structure_type">
                                    <option value="Standard" <?php echo $formType === 'Standard' ? 'selected' : ''; ?>>Standard (Balanced)</option>
                                    <option value="High Take-Home" <?php echo $formType === 'High Take-Home' ? 'selected' : ''; ?>>High Take-Home</option>
                                    <option value="Compliance Heavy" <?php echo $formType === 'Compliance Heavy' ? 'selected' : ''; ?>>Compliance Heavy</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="locked" id="locked" value="1" <?php echo $formLocked ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="locked">Lock final structure</label>
                                </div>
                            </div>
                            <div class="col-md-9 d-flex gap-2 align-items-end">
                                <button class="btn btn-outline-primary" type="button" id="autoCalc"><i class="bi bi-magic"></i> Auto Calculate</button>
                                <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle"></i> <?php echo $formId > 0 ? 'Update Structure' : 'Save Structure'; ?></button>
                                <?php if ($formId > 0): ?>
                                    <a class="btn btn-outline-secondary" href="salary-setup"><i class="bi bi-x-circle"></i> Cancel</a>
                                <?php endif; ?>
                                <div class="text-muted small">Incentives are fetched from Productivity and shown separately in payslip.</div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-lg-6">
                                <div class="card hr-card h-100">
                                    <div class="card-header bg-light fw-semibold"><i class="bi bi-plus-circle me-1"></i> Earnings</div>
                                    <div class="card-body">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Basic</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="basic" id="basic" min="0" value="<?php echo htmlspecialchars((string)($form['basic'] ?? '')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">HRA</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="hra" id="hra" min="0" value="<?php echo htmlspecialchars((string)($form['hra'] ?? '')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Conveyance</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="conveyance" id="conveyance" min="0" value="<?php echo htmlspecialchars((string)($form['conveyance'] ?? '')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Medical</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="medical" id="medical" min="0" value="<?php echo htmlspecialchars((string)($form['medical'] ?? '')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Special Allowance</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="special_allowance" id="special_allowance" min="0" value="<?php echo htmlspecialchars((string)($form['special_allowance'] ?? '')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Other Allowance</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="other_allowance" id="other_allowance" min="0" value="<?php echo htmlspecialchars((string)($form['other_allowance'] ?? '0')); ?>">
                                            </div>
                                        </div>
                                        <div class="mt-3 d-flex justify-content-between">
                                            <div class="text-muted small">Total Earnings</div>
                                            <div class="fw-semibold" id="earnings_total">₹0.00</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card hr-card h-100">
                                    <div class="card-header bg-light fw-semibold"><i class="bi bi-dash-circle me-1"></i> Deductions</div>
                                    <div class="card-body">
                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <label class="form-label small text-muted">PF</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="pf" id="pf" min="0" value="<?php echo htmlspecialchars((string)($form['pf'] ?? '')); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small text-muted">Professional Tax</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="professional_tax" id="professional_tax" min="0" value="<?php echo htmlspecialchars((string)($form['professional_tax'] ?? '200')); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small text-muted">TDS (Estimate)</label>
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="tds" id="tds" min="0" value="<?php echo htmlspecialchars((string)($form['tds'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="mt-3 d-flex justify-content-between">
                                            <div class="text-muted small">Total Deductions</div>
                                            <div class="fw-semibold" id="deductions_total">₹0.00</div>
                                        </div>
                                        <div class="mt-3 border-top pt-3 d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="text-muted small">Net Salary (Without Incentives)</div>
                                                <div class="text-muted small">Gross = Monthly Salary + Incentives (from Productivity)</div>
                                            </div>
                                            <div class="fs-5 fw-semibold" id="net_salary">₹0.00</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card hr-card">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-clock-history me-1"></i> Recent Salary Structures</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle hr-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Effective</th>
                                    <th>Type</th>
                                    <th class="text-end">Monthly</th>
                                    <th class="text-end">Basic</th>
                                    <th class="text-end">HRA</th>
                                    <th class="text-end">PF</th>
                                    <th class="text-end">PT</th>
                                    <th class="text-end">TDS</th>
                                    <th class="text-end">Locked</th>
                                    <th class="text-end">Edit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></td>
                                        <td class="font-monospace"><?php echo htmlspecialchars((string)($r['effective_date'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['structure_type'] ?? '')); ?></td>
                                        <td class="text-end">₹<?php echo number_format((float)($r['total_salary'] ?? 0), 2); ?></td>
                                        <td class="text-end">₹<?php echo number_format((float)($r['basic'] ?? 0), 2); ?></td>
                                        <td class="text-end">₹<?php echo number_format((float)($r['hra'] ?? 0), 2); ?></td>
                                        <td class="text-end">₹<?php echo number_format((float)($r['pf'] ?? 0), 2); ?></td>
                                        <td class="text-end">₹<?php echo number_format((float)($r['professional_tax'] ?? 0), 2); ?></td>
                                        <td class="text-end">₹<?php echo number_format((float)($r['tds'] ?? 0), 2); ?></td>
                                        <td class="text-end"><?php echo ((int)($r['locked'] ?? 0) === 1) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                        <td class="text-end">
                                            <?php if ((int)($r['locked'] ?? 0) === 1): ?>
                                                <span class="text-muted small">—</span>
                                            <?php else: ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="salary-setup?edit_id=<?php echo (int)($r['id'] ?? 0); ?>"><i class="bi bi-pencil-square"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="11" class="text-center text-muted">No records</td></tr>
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
    const el = (id) => document.getElementById(id);
    const round2 = (n) => Math.round((Number(n)||0) * 100) / 100;

    function tdsEstimate(monthlySalary, pf, pt){
      const ms = Math.max(0, Number(monthlySalary)||0);
      const annual = ms * 12;
      const stdDed = 50000;
      const taxable = Math.max(0, annual - stdDed - (Number(pf)||0)*12 - (Number(pt)||0)*12);
      let tax = 0;
      const slabs = [
        [0, 300000, 0],
        [300000, 600000, 0.05],
        [600000, 900000, 0.10],
        [900000, 1200000, 0.15],
        [1200000, 1500000, 0.20],
        [1500000, 1e18, 0.30],
      ];
      for (const s of slabs){
        const from = s[0], to = s[1], rate = s[2];
        if (taxable <= from) continue;
        const amt = Math.min(taxable, to) - from;
        if (amt > 0) tax += amt * rate;
      }
      tax = tax * 1.04;
      return round2(tax / 12);
    }

    function autoCalc(){
      const total = round2(el('total_salary').value);
      const type = el('structure_type').value;
      let basicPct = 0.44;
      let hraPct = 0.50;
      if (type === 'High Take-Home'){ basicPct = 0.36; hraPct = 0.40; }
      if (type === 'Compliance Heavy'){ basicPct = 0.50; hraPct = 0.50; }

      const basic = round2(total * basicPct);
      const hra = round2(basic * hraPct);
      const convey = 1600;
      const medical = 1250;
      let special = round2(total - (basic + hra + convey + medical));
      if (special < 0){ special = 0; }

      el('basic').value = basic;
      el('hra').value = hra;
      el('conveyance').value = convey;
      el('medical').value = medical;
      el('special_allowance').value = special;
      if (!el('other_allowance').value) el('other_allowance').value = 0;

      const pf = round2(basic * 0.12);
      el('pf').value = pf;
      if (!el('professional_tax').value) el('professional_tax').value = 200;
      el('tds').value = tdsEstimate(total, pf, el('professional_tax').value);
      refreshTotals();
    }

    function refreshTotals(){
      const basic = round2(el('basic').value);
      const hra = round2(el('hra').value);
      const convey = round2(el('conveyance').value);
      const medical = round2(el('medical').value);
      const special = round2(el('special_allowance').value);
      const other = round2(el('other_allowance').value);
      const earnings = round2(basic + hra + convey + medical + special + other);
      el('earnings_total').textContent = '₹' + earnings.toFixed(2);

      const pf = round2(el('pf').value);
      const pt = round2(el('professional_tax').value);
      const tds = round2(el('tds').value);
      const ded = round2(pf + pt + tds);
      el('deductions_total').textContent = '₹' + ded.toFixed(2);

      const net = round2(earnings - ded);
      el('net_salary').textContent = '₹' + net.toFixed(2);
    }

    el('autoCalc').addEventListener('click', autoCalc);
    ['total_salary','structure_type','basic','hra','conveyance','medical','special_allowance','other_allowance','pf','professional_tax','tds'].forEach(id => {
      const i = el(id);
      if (i) i.addEventListener('input', refreshTotals);
      if (i && id === 'structure_type') i.addEventListener('change', autoCalc);
    });
    refreshTotals();
  })();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
