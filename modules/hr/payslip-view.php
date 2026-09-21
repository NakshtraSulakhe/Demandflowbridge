<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('hr.payslips.page', ['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
$user = getCurrentUser();

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];

$isAdmin = in_array((string)($user['role'] ?? ''), ['admin'], true);
$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$userId = ($isAdmin && $requestedUserId > 0) ? $requestedUserId : (int)($user['id'] ?? 0);
$isLocked = hrIsPayrollLocked($year, $month);
if (!$isAdmin && $isLocked) {
    header('Location: payslips?month=' . rawurlencode($monthStr) . '&warning=' . rawurlencode('Payslips are hidden while payroll is locked.'));
    exit;
}

$row = getPayslip($userId, $year, $month);
if (!$row) {
    http_response_code(404);
    echo 'Payslip not found';
    exit;
}

$data = json_decode((string)($row['salary_data'] ?? ''), true);
if (!is_array($data)) $data = [];

$u = is_array($data['user'] ?? null) ? $data['user'] : [];
$attendance = is_array($data['attendance'] ?? null) ? $data['attendance'] : [];
$earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
$ded = is_array($data['deductions'] ?? null) ? $data['deductions'] : [];
$net = (float)($data['net_salary'] ?? 0);
$bank = is_array($data['bank'] ?? null) ? $data['bank'] : getUserBankDetails($userId);

$jobTitle = (string)($u['job_title'] ?? '');
if ($jobTitle === '') {
    $conn = getDbConnection();
    $st = $conn->prepare("SELECT job_title FROM users WHERE id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $userId);
        $st->execute();
        $jobTitle = (string)(($st->get_result()->fetch_assoc() ?: [])['job_title'] ?? '');
        $st->close();
    }
}
$u['job_title'] = $jobTitle;

$message = '';
$messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && !$isLocked && isset($_POST['action']) && $_POST['action'] === 'override') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $bonusOvr = (float)($_POST['bonus_override'] ?? ($earn['bonus'] ?? 0));
        $incentOvr = (float)($_POST['incentives_override'] ?? ($earn['incentives'] ?? 0));
        $otherOvr = (float)($_POST['other_deductions_override'] ?? ($ded['other'] ?? 0));
        $loanOvr = (float)($_POST['loan_emi_override'] ?? ($ded['loan_emi'] ?? 0));
        $tdsOvr = (float)($_POST['tds_override'] ?? ($ded['tds'] ?? ($ded['tax'] ?? 0)));
        $ptOvr = (float)($_POST['professional_tax_override'] ?? ($ded['professional_tax'] ?? 0));

        $salaryPr = (float)($earn['salary_prorated'] ?? ($earn['base_prorated'] ?? 0));
        $gross = $salaryPr + $incentOvr + $bonusOvr;
        $pf = (float)($ded['pf'] ?? 0);
        $dedTotal = $pf + $ptOvr + $tdsOvr + $otherOvr + $loanOvr;
        $net = $gross - $dedTotal;

        $data['overrides'] = [
            'bonus' => $bonusOvr,
            'incentives' => $incentOvr,
            'other_deductions' => $otherOvr,
            'loan_emi' => $loanOvr,
            'professional_tax' => $ptOvr,
            'tds' => $tdsOvr,
        ];
        $data['earnings']['bonus'] = round($bonusOvr, 2);
        $data['earnings']['incentives'] = round($incentOvr, 2);
        $data['earnings']['gross'] = round($gross, 2);
        $data['earnings']['salary_prorated'] = round($salaryPr, 2);
        $data['deductions']['other'] = round($otherOvr, 2);
        $data['deductions']['loan_emi'] = round($loanOvr, 2);
        $data['deductions']['professional_tax'] = round($ptOvr, 2);
        $data['deductions']['tds'] = round($tdsOvr, 2);
        $data['deductions']['total'] = round($dedTotal, 2);
        $data['net_salary'] = round($net, 2);

        $conn = getDbConnection();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("UPDATE hr_payslips SET salary_data = ?, updated_at = NOW() WHERE user_id = ? AND year = ? AND month = ?");
        if ($stmt) {
            $stmt->bind_param('siii', $json, $userId, $year, $month);
            $ok = $stmt->execute();
            $stmt->close();
            $message = $ok ? 'Payslip updated.' : 'Failed to update payslip.';
            $messageType = $ok ? 'success' : 'danger';
        } else {
            $message = 'Database error.';
            $messageType = 'danger';
        }

        $row2 = getPayslip($userId, $year, $month);
        $data2 = $row2 ? json_decode((string)($row2['salary_data'] ?? ''), true) : null;
        if (is_array($data2)) {
            $data = $data2;
            $u = is_array($data['user'] ?? null) ? $data['user'] : [];
            $attendance = is_array($data['attendance'] ?? null) ? $data['attendance'] : [];
            $earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
            $ded = is_array($data['deductions'] ?? null) ? $data['deductions'] : [];
            $net = (float)($data['net_salary'] ?? 0);
        }
    }
}

function money($v): string { return 'Rs. ' . number_format(round((float)$v, 0), 0); }
$addRow = function(array &$rows, string $label, float $amount, bool $always = false) {
    if ($always || abs($amount) > 0.009) $rows[] = ['label' => $label, 'amount' => $amount];
};
?>
<?php $pageTitle = 'Payslip'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="hr-page-header d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <div class="hr-page-title">Payslip</div>
                <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($monthStr); ?></span>
                <span class="badge bg-<?php echo $isLocked ? 'danger' : 'secondary'; ?>"><?php echo $isLocked ? 'Payroll Locked' : 'Payroll Open'; ?></span>
            </div>
            <div class="hr-page-subtitle"><?php echo htmlspecialchars((string)($u['name'] ?? '')); ?><?php echo $jobTitle !== '' ? (' · ' . htmlspecialchars($jobTitle)) : ''; ?></div>
        </div>
        <div class="d-flex gap-2 hr-actions">
            <a class="btn btn-light border btn-sm" href="payslip-pdf?month=<?php echo urlencode($monthStr); ?>&user_id=<?php echo (int)$userId; ?>"><i class="bi bi-filetype-pdf me-1"></i>Download PDF</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="hr-card p-3 h-100">
                <div class="fw-semibold mb-2"><i class="bi bi-person-badge me-1"></i>Employee</div>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="text-muted small">Name</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($u['name'] ?? '—')); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Employee ID</div>
                        <div class="fw-semibold font-monospace"><?php echo htmlspecialchars((string)($u['employee_id'] ?? '—')); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Job Title</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($u['job_title'] ?? ($u['role'] ?? '—'))); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Department</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($u['department'] ?? '—')); ?></div>
                    </div>
                </div>
                <hr class="my-3">
                <div class="fw-semibold mb-2"><i class="bi bi-bank2 me-1"></i>Bank & Tax</div>
                <div class="row g-2">
                    <div class="col-12">
                        <div class="text-muted small">Bank</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($bank['bank_name'] ?? '—')); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">A/C</div>
                        <div class="fw-semibold font-monospace"><?php echo htmlspecialchars(maskAccountNumber((string)($bank['account_number'] ?? '')) ?: '—'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">IFSC</div>
                        <div class="fw-semibold font-monospace"><?php echo htmlspecialchars((string)($bank['ifsc_code'] ?? '—')); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">PAN</div>
                        <div class="fw-semibold font-monospace"><?php echo htmlspecialchars((string)($bank['pan_number'] ?? '—')); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="hr-card p-3 h-100">
                <div class="fw-semibold mb-2"><i class="bi bi-calendar2-check me-1"></i>Attendance</div>
                <div class="row g-2">
                    <div class="col-3">
                        <div class="text-muted small">Working</div>
                        <div class="fw-semibold"><?php echo number_format((float)($attendance['working_days'] ?? 0)); ?></div>
                    </div>
                    <div class="col-3">
                        <div class="text-muted small">Present</div>
                        <div class="fw-semibold"><?php echo number_format((float)($attendance['present_days'] ?? 0)); ?></div>
                    </div>
                    <div class="col-3">
                        <div class="text-muted small">Paid Leave</div>
                        <div class="fw-semibold text-success"><?php echo number_format((float)($attendance['paid_leave_days'] ?? 0)); ?></div>
                    </div>
                    <div class="col-3">
                        <div class="text-muted small">Unpaid Leave</div>
                        <div class="fw-semibold text-danger"><?php echo number_format((float)($attendance['unpaid_leave_days'] ?? 0)); ?></div>
                    </div>
                    <div class="col-3">
                        <div class="text-muted small">Absent</div>
                        <div class="fw-semibold"><?php echo number_format((float)($attendance['absent_days'] ?? 0)); ?></div>
                    </div>
                    <div class="col-3">
                        <div class="text-muted small">Half</div>
                        <div class="fw-semibold"><?php echo number_format((float)($attendance['half_days'] ?? 0)); ?></div>
                    </div>
                    <div class="col-3">
                        <div class="text-muted small">NCNS</div>
                        <div class="fw-semibold text-danger"><?php echo number_format((float)($attendance['ncns_days'] ?? 0)); ?></div>
                    </div>
                    <div class="col-3">
                        <div class="text-muted small">Paid</div>
                        <div class="fw-semibold"><?php echo number_format((float)($attendance['paid_days'] ?? 0), 1); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Salary Base Days</div>
                        <div class="fw-semibold"><?php echo number_format((float)($attendance['salary_working_days'] ?? ($attendance['working_days'] ?? 0)), 1); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Salary Payable Days</div>
                        <div class="fw-semibold"><?php echo number_format((float)($attendance['salary_payable_days'] ?? ($attendance['paid_days'] ?? 0)), 1); ?></div>
                    </div>
                </div>
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-currency-rupee me-1"></i>Net Pay</div>
                    <div class="fs-4 fw-semibold"><?php echo money($net); ?></div>
                </div>
                <div class="text-muted small mt-1">
                    <?php if (!empty($attendance['is_new_joiner_month'])): ?>
                        New joiner salary uses full-month working days and present-day payable salary.
                    <?php else: ?>
                        Net Pay = Gross − Total Deductions
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="hr-card p-3 h-100">
                <div class="fw-semibold mb-2"><i class="bi bi-clipboard-data me-1"></i>Summary</div>
                <?php
                    $salaryPr = (float)($earn['salary_prorated'] ?? ($earn['base_prorated'] ?? 0));
                    $gross = (float)($earn['gross'] ?? ($salaryPr + (float)($earn['incentives'] ?? 0) + (float)($earn['bonus'] ?? 0)));
                    $dedTotal = (float)($ded['total'] ?? ((float)($ded['pf'] ?? 0) + (float)($ded['professional_tax'] ?? 0) + (float)($ded['tds'] ?? ($ded['tax'] ?? 0)) + (float)($ded['loan_emi'] ?? 0) + (float)($ded['other'] ?? 0)));
                ?>
                <div class="table-responsive">
                    <table class="table table-sm hr-table mb-0">
                        <tbody>
                            <tr><td class="text-muted">Salary (Prorated)</td><td class="text-end fw-semibold"><?php echo money($salaryPr); ?></td></tr>
                            <tr><td class="text-muted">Daily Salary Rate</td><td class="text-end fw-semibold"><?php echo money((float)($earn['daily_salary_rate'] ?? 0)); ?></td></tr>
                            <tr><td class="text-muted">Gross</td><td class="text-end fw-semibold"><?php echo money($gross); ?></td></tr>
                            <tr><td class="text-muted">Total Deductions</td><td class="text-end fw-semibold"><?php echo money($dedTotal); ?></td></tr>
                            <tr class="table-light"><td class="fw-semibold">Net Pay</td><td class="text-end fw-semibold"><?php echo money($net); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="hr-card p-3 h-100">
                <div class="fw-semibold mb-2"><i class="bi bi-plus-circle me-1"></i>Earnings</div>
                <?php
                    $rowsE = [];
                    $addRow($rowsE, 'Salary (Prorated)', (float)($earn['salary_prorated'] ?? ($earn['base_prorated'] ?? 0)), true);
                    $addRow($rowsE, 'Basic', (float)($earn['basic'] ?? 0));
                    $addRow($rowsE, 'HRA', (float)($earn['hra'] ?? 0));
                    $addRow($rowsE, 'Conveyance', (float)($earn['conveyance'] ?? 0));
                    $addRow($rowsE, 'Medical', (float)($earn['medical'] ?? 0));
                    $addRow($rowsE, 'Other Allowance', (float)($earn['special_allowance'] ?? 0) + (float)($earn['other_allowance'] ?? 0));
                    $addRow($rowsE, 'Incentives', (float)($earn['incentives'] ?? 0));
                    $addRow($rowsE, 'Bonus', (float)($earn['bonus'] ?? 0));
                    
                    $incentiveDeductions = is_array($earn['incentive_deductions'] ?? null) ? $earn['incentive_deductions'] : [];
                    $incentivesBefore = (float)($earn['incentives_before_deductions'] ?? $earn['incentives'] ?? 0);
                ?>
                <div class="table-responsive">
                    <table class="table table-sm hr-table mb-0">
                        <tbody>
                            <?php foreach ($rowsE as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['label']); ?></td>
                                    <td class="text-end fw-semibold"><?php echo money((float)$r['amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light">
                                <td class="fw-semibold">Gross</td>
                                <td class="text-end fw-semibold"><?php echo money($gross); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($incentiveDeductions) && $incentivesBefore > (float)($earn['incentives'] ?? 0)): ?>
                <div class="mt-3">
                    <div class="small text-muted mb-2">Incentive Deductions Applied:</div>
                    <div class="table-responsive">
                        <table class="table table-sm hr-table mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted">Incentive Before Deductions</td>
                                    <td class="text-end fw-semibold"><?php echo money($incentivesBefore); ?></td>
                                </tr>
                                <?php foreach ($incentiveDeductions as $ded): ?>
                                <tr>
                                    <td class="text-danger small"><?php echo htmlspecialchars((string)($ded['label'] ?? '')); ?> (<?php echo (int)($ded['pct'] ?? 0); ?>%)</td>
                                    <td class="text-end text-danger small">-<?php echo money(round($incentivesBefore * ((float)($ded['pct'] ?? 0) / 100), 0)); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-light">
                                    <td class="fw-semibold">Incentive After Deductions</td>
                                    <td class="text-end fw-semibold"><?php echo money((float)($earn['incentives'] ?? 0)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="hr-card p-3 h-100">
                <div class="fw-semibold mb-2"><i class="bi bi-dash-circle me-1"></i>Deductions</div>
                <?php
                    $rowsD = [];
                    $addRow($rowsD, 'PF', (float)($ded['pf'] ?? 0));
                    $addRow($rowsD, 'Professional Tax', (float)($ded['professional_tax'] ?? 0));
                    $addRow($rowsD, 'TDS', (float)($ded['tds'] ?? ($ded['tax'] ?? 0)));
                    $addRow($rowsD, 'Loan EMI', (float)($ded['loan_emi'] ?? 0));
                    $addRow($rowsD, 'Other', (float)($ded['other'] ?? 0));
                    if (isset($ded['ncns_penalty_amount']) && (float)$ded['ncns_penalty_amount'] > 0) {
                        $ncnsDays = (float)($attendance['ncns_days'] ?? 0);
                        $ncnsPenaltyDays = (float)($ded['ncns_penalty_days'] ?? 0);
                        $addRow($rowsD, 'NCNS Penalty ('.$ncnsDays.' days ×2 = '.$ncnsPenaltyDays.' days)', (float)($ded['ncns_penalty_amount'] ?? 0), true);
                    }
                    $dedTotal = (float)($ded['total'] ?? ((float)($ded['pf'] ?? 0) + (float)($ded['professional_tax'] ?? 0) + (float)($ded['tds'] ?? ($ded['tax'] ?? 0)) + (float)($ded['loan_emi'] ?? 0) + (float)($ded['other'] ?? 0) + (float)($ded['ncns_penalty_amount'] ?? 0)));
                ?>
                <div class="table-responsive">
                    <table class="table table-sm hr-table mb-0">
                        <tbody>
                            <?php if (empty($rowsD)): ?>
                                <tr><td class="text-muted">—</td><td class="text-end text-muted">—</td></tr>
                            <?php else: ?>
                                <?php foreach ($rowsD as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$r['label']); ?></td>
                                        <td class="text-end fw-semibold"><?php echo money((float)$r['amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr class="table-light">
                                <td class="fw-semibold">Total Deductions</td>
                                <td class="text-end fw-semibold"><?php echo money($dedTotal); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($isAdmin && !$isLocked): ?>
        <div class="col-12">
            <div class="hr-card p-3">
                <div class="fw-semibold mb-2"><i class="bi bi-pencil-square me-1"></i> Admin Override</div>
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="override">
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Bonus</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" name="bonus_override" value="<?php echo htmlspecialchars((string)($earn['bonus'] ?? 0)); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Incentives</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" name="incentives_override" value="<?php echo htmlspecialchars((string)($earn['incentives'] ?? 0)); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Other Deductions</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" name="other_deductions_override" value="<?php echo htmlspecialchars((string)($ded['other'] ?? 0)); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Loan EMI</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" name="loan_emi_override" value="<?php echo htmlspecialchars((string)($ded['loan_emi'] ?? 0)); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Professional Tax</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" name="professional_tax_override" value="<?php echo htmlspecialchars((string)($ded['professional_tax'] ?? 0)); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">TDS</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" name="tds_override" value="<?php echo htmlspecialchars((string)($ded['tds'] ?? ($ded['tax'] ?? 0))); ?>">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Save Override</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
