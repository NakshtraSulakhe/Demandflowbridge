<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requirePermissionOrRole('hr.leaves.page', ['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();

$user = getCurrentUser();
$viewerId = (int)($user['id'] ?? 0);
$isHrAdmin = isAdmin() || (function_exists('userHasPermission') && (userHasPermission('hr.attendance_admin.page') || userHasPermission('hr.payroll.page')));
$targetId = $viewerId;
if ($isHrAdmin && isset($_GET['user_id'])) {
    $targetId = (int)$_GET['user_id'];
}
if ($targetId <= 0) $targetId = $viewerId;

$message = '';
$messageType = 'success';

$nowBase = new DateTimeImmutable('now', hrBaseTz());
$asOf = isset($_GET['as_of']) ? (string)$_GET['as_of'] : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
}

$selectedLeaveDate = isset($_GET['leave_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['leave_date'])
    ? (string)$_GET['leave_date']
    : $nowBase->format('Y-m-d');

$selectedLeaveType = isset($_POST['leave_type']) ? trim((string)$_POST['leave_type']) : 'Paid Leave';

$conn = getDbConnection();
$targetUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'request_leave') {
            $requestUserId = $isHrAdmin ? (int)($_POST['user_id'] ?? $targetId) : $viewerId;
            $leaveDate = (string)($_POST['leave_date'] ?? '');
            $leaveType = trim((string)($_POST['leave_type'] ?? 'Paid Leave'));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $selectedLeaveDate = $leaveDate;
            $selectedLeaveType = $leaveType;
            $result = hrCreateLeaveRequest($requestUserId, $leaveDate, $leaveType, $reason, $viewerId);
            $message = !empty($result['ok']) ? 'Leave request submitted.' : (string)($result['error'] ?? 'Failed to submit leave request.');
            $messageType = !empty($result['ok']) ? 'success' : 'danger';
            $targetId = $requestUserId > 0 ? $requestUserId : $targetId;
        } elseif ($action === 'review_request' && $isHrAdmin) {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
            $result = hrReviewLeaveRequest($requestId, $decision, $viewerId, $reviewNotes);
            $message = !empty($result['ok']) ? 'Leave request updated.' : (string)($result['error'] ?? 'Failed to update leave request.');
            $messageType = !empty($result['ok']) ? 'success' : 'danger';
        } elseif ($action === 'cancel_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $result = hrCancelLeaveRequest($requestId, $viewerId, $isHrAdmin);
            $message = !empty($result['ok']) ? 'Leave request cancelled.' : (string)($result['error'] ?? 'Failed to cancel leave request.');
            $messageType = !empty($result['ok']) ? 'success' : 'danger';
        }
    }
}

$stmtU = $conn->prepare("SELECT id, full_name, role FROM users WHERE id = ? LIMIT 1");
if ($stmtU) {
    $stmtU->bind_param('i', $targetId);
    $stmtU->execute();
    $targetUser = $stmtU->get_result()->fetch_assoc() ?: null;
    $stmtU->close();
}
if (!$targetUser) {
    header('Location: hr-dashboard');
    exit;
}

$sum = getPaidLeaveKittyForUser((int)$targetId, $asOf);
$entitled = (int)($sum['entitled'] ?? 0);
$taken = (int)($sum['taken'] ?? 0);
$available = (int)($sum['available'] ?? 0);
$pendingRequestsCount = (int)($sum['pending_requests'] ?? 0);
$joinDate = (string)($sum['join_date'] ?? '');
$periodStart = (string)($sum['period_start'] ?? '');
$joinMonthWorkingDays = (int)($sum['working_days_from_join_in_month'] ?? 0);
$requiredWorkingDays = (int)($sum['required_working_days'] ?? 10);
$eligibleInJoiningMonth = !empty($sum['eligible_in_joining_month']);
$leaveRuleText = (string)($sum['rule_text'] ?? '');
$takenDates = is_array($sum['taken_dates'] ?? null) ? $sum['taken_dates'] : [];
$upcoming = is_array($sum['upcoming_holidays'] ?? null) ? $sum['upcoming_holidays'] : [];
$userRequests = hrGetLeaveRequests((int)$targetId, null, $periodStart !== '' ? $periodStart : null, null);
$pendingApprovalRows = $isHrAdmin ? hrGetLeaveRequests(null, 'Pending', null, null) : [];

$pageTitle = 'Paid Leaves';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <?php
    $badges = [
      ['text' => 'As of ' . $asOf, 'class' => 'bg-light text-dark font-monospace'],
    ];
    hrRenderHeader(
      [
        ['label' => 'HR', 'href' => 'hr-dashboard'],
        ['label' => 'Paid Leaves'],
      ],
      'Paid Leave Workflow',
      'Request, approve, and audit paid leave with attendance sync',
      [
        ['label' => 'Attendance', 'href' => 'attendance', 'icon' => 'bi-fingerprint', 'class' => 'btn-outline-primary'],
        ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-secondary'],
        ['label' => 'Rules', 'href' => '#leaveRulesModal', 'icon' => 'bi-info-circle', 'class' => 'btn-outline-info', 'attrs' => 'data-bs-toggle="modal"'],
      ],
      $badges
    );
  ?>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mb-3"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-12">
      <div class="card hr-card">
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <?php if ($isHrAdmin): ?>
              <div class="col-md-5">
                <label class="form-label small text-muted">User</label>
                <select class="form-select form-select-sm" name="user_id">
                  <?php
                    $users = getInternalPayrollUsers();
                    foreach ($users as $u) {
                      $uid = (int)($u['id'] ?? 0);
                      $nm = (string)($u['full_name'] ?? '');
                      if ($uid <= 0) continue;
                      $sel = $uid === (int)$targetId ? 'selected' : '';
                      echo '<option value="' . (int)$uid . '" ' . $sel . '>' . htmlspecialchars($nm) . '</option>';
                    }
                  ?>
                </select>
              </div>
            <?php endif; ?>
            <div class="col-md-3">
              <label class="form-label small text-muted">As Of</label>
              <input type="date" class="form-control form-control-sm" name="as_of" value="<?php echo htmlspecialchars($asOf); ?>">
            </div>
            <div class="col-md-2">
              <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Refresh</button>
            </div>
            <div class="col-md-2">
              <div class="small text-muted">Leave Start</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($periodStart !== '' ? $periodStart : '—'); ?></div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <?php hrKpi('Entitled (Months)', number_format($entitled), 'bi-calendar3'); ?>
    </div>
    <div class="col-md-4">
      <?php hrKpi('Paid Leaves Taken', number_format($taken), 'bi-calendar2-check', '', 'text-success'); ?>
    </div>
    <div class="col-md-4">
      <?php hrKpi('Available Balance', number_format($available), 'bi-inbox', $pendingRequestsCount > 0 ? ('Pending requests: ' . number_format($pendingRequestsCount)) : '', $available > 0 ? 'text-primary' : 'text-muted'); ?>
    </div>
    <div class="col-12">
      <div class="card hr-card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div>
            <div class="fw-semibold">Joining-Month Leave Credit Rule</div>
            <div class="small text-muted"><?php echo htmlspecialchars($leaveRuleText !== '' ? $leaveRuleText : 'A joining-month paid leave credit is granted only when more than 10 working days remain from the employee joining date.'); ?></div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-light text-dark border">Join Date: <?php echo htmlspecialchars($joinDate !== '' ? $joinDate : '—'); ?></span>
            <span class="badge bg-light text-dark border">Working Days In Joining Month: <?php echo number_format($joinMonthWorkingDays); ?></span>
            <span class="badge <?php echo $eligibleInJoiningMonth ? 'bg-success-subtle text-success border' : 'bg-warning-subtle text-warning border'; ?>">
              <?php echo $eligibleInJoiningMonth ? 'Joining Month Credit Eligible' : 'Credit Starts Next Month'; ?>
            </span>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card hr-card h-100">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-send me-1"></i> Request Leave</div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
            <input type="hidden" name="action" value="request_leave">
            <input type="hidden" name="user_id" value="<?php echo (int)$targetId; ?>">
            <div class="col-md-4">
              <label class="form-label small text-muted">Leave Date</label>
              <input type="date" class="form-control form-control-sm" name="leave_date" value="<?php echo htmlspecialchars($selectedLeaveDate); ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Leave Type</label>
              <select class="form-select form-select-sm" name="leave_type">
                <option value="Paid Leave" <?php echo $selectedLeaveType === 'Paid Leave' ? 'selected' : ''; ?>>Paid Leave</option>
                <option value="Unpaid Leave" <?php echo $selectedLeaveType === 'Unpaid Leave' ? 'selected' : ''; ?>>Unpaid Leave</option>
              </select>
            </div>
            <div class="col-md-4">
              <div class="small text-muted">Eligible From</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($periodStart !== '' ? $periodStart : '—'); ?></div>
            </div>
            <div class="col-12">
              <label class="form-label small text-muted">Reason</label>
              <textarea class="form-control form-control-sm" name="reason" rows="3" placeholder="Optional reason for audit history"></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send"></i> Submit Request</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card hr-card h-100">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-list-check me-1"></i> Paid Leave History</span>
          <span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)($targetUser['full_name'] ?? '')); ?></span>
        </div>
        <div class="card-body">
          <?php if (empty($takenDates)): ?>
            <div class="text-muted">No paid leaves recorded.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Day</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($takenDates as $d): ?>
                    <?php
                      $dayName = '';
                      try {
                        $dt = new DateTimeImmutable($d . ' 00:00:00', hrBaseTz());
                        $dayName = $dt->format('l');
                      } catch (Throwable $e) {
                        $dayName = '';
                      }
                    ?>
                    <tr>
                      <td class="font-monospace"><?php echo htmlspecialchars($d); ?></td>
                      <td class="text-muted"><?php echo htmlspecialchars($dayName); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card hr-card h-100">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-journal-text me-1"></i> Leave Requests</span>
          <span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)($targetUser['full_name'] ?? '')); ?></span>
        </div>
        <div class="card-body">
          <?php if (empty($userRequests)): ?>
            <div class="text-muted">No leave requests found.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Reviewed By</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($userRequests as $req): ?>
                    <?php
                      $reqId = (int)($req['id'] ?? 0);
                      $reqStatus = (string)($req['status'] ?? 'Pending');
                      $canCancel = $reqStatus === 'Pending' && ($isHrAdmin || (int)($req['requested_by'] ?? 0) === $viewerId);
                    ?>
                    <tr>
                      <td class="font-monospace"><?php echo htmlspecialchars((string)($req['leave_date'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($req['leave_type'] ?? '')); ?></td>
                      <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($reqStatus); ?></span></td>
                      <td class="small text-muted"><?php echo htmlspecialchars((string)($req['reason'] ?? '')); ?></td>
                      <td class="small text-muted"><?php echo htmlspecialchars((string)($req['reviewed_by_name'] ?? '—')); ?></td>
                      <td class="text-end">
                        <?php if ($canCancel): ?>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                            <input type="hidden" name="action" value="cancel_request">
                            <input type="hidden" name="request_id" value="<?php echo $reqId; ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit">Cancel</button>
                          </form>
                        <?php else: ?>
                          <span class="text-muted small">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card hr-card h-100">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-megaphone me-1"></i> Upcoming Holidays</div>
        <div class="card-body">
          <?php if (empty($upcoming)): ?>
            <div class="text-muted">No upcoming holidays found.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Holiday</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($upcoming as $h): ?>
                    <?php
                      $hd = (string)($h['holiday_date'] ?? '');
                      $hn = (string)($h['name'] ?? ($h['holiday_name'] ?? ''));
                    ?>
                    <tr>
                      <td class="font-monospace"><?php echo htmlspecialchars($hd); ?></td>
                      <td><?php echo htmlspecialchars($hn); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($isHrAdmin): ?>
      <div class="col-12">
        <div class="card hr-card">
          <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-shield-check me-1"></i> Pending Approval Queue</span>
            <span class="badge bg-secondary"><?php echo number_format(count($pendingApprovalRows)); ?></span>
          </div>
          <div class="card-body">
            <?php if (empty($pendingApprovalRows)): ?>
              <div class="text-muted">No pending leave requests.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>User</th>
                      <th>Date</th>
                      <th>Type</th>
                      <th>Reason</th>
                      <th class="text-end">Approve</th>
                      <th class="text-end">Reject</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pendingApprovalRows as $req): ?>
                      <?php $reqId = (int)($req['id'] ?? 0); ?>
                      <tr>
                        <td>
                          <div class="fw-semibold"><?php echo htmlspecialchars((string)($req['full_name'] ?? '')); ?></div>
                          <div class="small text-muted"><?php echo htmlspecialchars((string)($req['role'] ?? '')); ?></div>
                        </td>
                        <td class="font-monospace"><?php echo htmlspecialchars((string)($req['leave_date'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($req['leave_type'] ?? '')); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars((string)($req['reason'] ?? '')); ?></td>
                        <td class="text-end">
                          <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                            <input type="hidden" name="action" value="review_request">
                            <input type="hidden" name="request_id" value="<?php echo $reqId; ?>">
                            <input type="hidden" name="decision" value="approve">
                            <button class="btn btn-success btn-sm" type="submit">Approve</button>
                          </form>
                        </td>
                        <td class="text-end">
                          <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                            <input type="hidden" name="action" value="review_request">
                            <input type="hidden" name="request_id" value="<?php echo $reqId; ?>">
                            <input type="hidden" name="decision" value="reject">
                            <button class="btn btn-outline-danger btn-sm" type="submit">Reject</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="leaveRulesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-info-circle me-1"></i> Paid Leave Rules</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <div class="fw-semibold mb-2">Eligibility</div>
              <div class="small text-muted mb-2">Paid leave credit for the joining month is granted only when the employee has more than <?php echo number_format($requiredWorkingDays); ?> working days remaining from the joining date in that month.</div>
              <div class="small text-muted">Weekends and US holidays are excluded from the working-day count.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <div class="fw-semibold mb-2">Current Employee Check</div>
              <div class="small text-muted mb-2">Join date: <span class="fw-semibold text-body"><?php echo htmlspecialchars($joinDate !== '' ? $joinDate : '—'); ?></span></div>
              <div class="small text-muted mb-2">Working days remaining in joining month: <span class="fw-semibold text-body"><?php echo number_format($joinMonthWorkingDays); ?></span></div>
              <div class="small text-muted">Credit start date: <span class="fw-semibold text-body"><?php echo htmlspecialchars($periodStart !== '' ? $periodStart : '—'); ?></span></div>
            </div>
          </div>
          <div class="col-12">
            <div class="border rounded p-3">
              <div class="fw-semibold mb-2">Operational Rules</div>
              <ul class="small text-muted mb-0 ps-3">
                <li>Paid leave requests cannot be created before the employee joining date.</li>
                <li>Paid leave is not allowed on weekends or US holidays.</li>
                <li>Approved leave syncs back into attendance so payroll reads the same status.</li>
                <li>If the employee punches attendance on a leave day, the leave workflow reconciles with attendance activity.</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
