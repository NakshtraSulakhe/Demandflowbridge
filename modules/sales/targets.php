<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','sales_director']);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

$message = '';
$messageType = 'success';

$now = new DateTime();
$selMonthStr = isset($_GET['month']) ? (string)$_GET['month'] : $now->format('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $selMonthStr, $m)) {
    $selMonthStr = $now->format('Y-m');
    $m = [$selMonthStr, (int)$now->format('Y'), (int)$now->format('m')];
}
$viewYear = (int)$m[1];
$viewMonth = (int)$m[2];

$today = $now->format('Y-m-d');
$fxSync = ensureUsdInrRateForDate($today, $userId);
$rate = $fxSync['rate'] ?? null;
$rateDateLabel = $today;
$fxInfoText = 'Stored daily in `fx_rates`.';
$fxInfoClass = 'text-muted';

if (!empty($fxSync['ok'])) {
    if (($fxSync['status'] ?? '') === 'fetched' || ($fxSync['status'] ?? '') === 'refreshed') {
        $fxInfoText = 'Auto-synced from Google Finance and saved for today.';
        $fxInfoClass = 'text-success';
    } elseif (($fxSync['status'] ?? '') === 'fetched_fallback' || ($fxSync['status'] ?? '') === 'refreshed_fallback') {
        $fxInfoText = 'Auto-synced from the fallback daily FX feed and saved for today.';
        $fxInfoClass = 'text-success';
    } else {
        $fxInfoText = 'Loaded from today\'s saved FX row.';
    }
} else {
    $latestRate = getLatestUsdInrRateRecord($today);
    if ($latestRate) {
        $rate = (float)($latestRate['usd_inr'] ?? 0);
        $rateDateLabel = (string)($latestRate['rate_date'] ?? $today);
        $fxInfoText = 'Google Finance sync is unavailable right now. Showing the last stored FX rate.';
        $fxInfoClass = 'text-warning';
    } else {
        $fxInfoText = (string)($fxSync['message'] ?? 'Unable to load the USD to INR FX rate right now.');
        $fxInfoClass = 'text-danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_targets') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        try {
            $monthStr = (string)($_POST['month'] ?? $selMonthStr);
            if (preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $mm)) {
                $viewYear = (int)$mm[1];
                $viewMonth = (int)$mm[2];
                $selMonthStr = sprintf('%04d-%02d', $viewYear, $viewMonth);
            }

            $newAcc = $_POST['target_new_accounts'] ?? [];
            $revUsd = $_POST['target_revenue_usd'] ?? [];
            $commPct = $_POST['commission_percent'] ?? [];
            if (!is_array($newAcc)) $newAcc = [];
            if (!is_array($revUsd)) $revUsd = [];
            if (!is_array($commPct)) $commPct = [];

            $saved = 0;
            foreach (getSalesTargetAssignableUsers() as $u) {
                $uid = (int)($u['id'] ?? 0);
                if ($uid <= 0) continue;
                $tNew = isset($newAcc[$uid]) ? max(0, (int)$newAcc[$uid]) : 0;
                $tRev = isset($revUsd[$uid]) ? max(0.0, (float)$revUsd[$uid]) : 0.0;
                if (upsertSalesMonthlyTarget($uid, $viewYear, $viewMonth, $tNew, $tRev, $userId)) $saved++;

                if (function_exists('setSalesCommissionPercentForUser')) {
                    $pct = isset($commPct[$uid]) ? (float)$commPct[$uid] : 0.0;
                    if ($pct < 0) $pct = 0.0;
                    if ($pct > 100) $pct = 100.0;
                    setSalesCommissionPercentForUser($uid, $pct, $userId);
                }
            }

            $message = 'Targets saved for ' . $saved . ' users.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$assignable = getSalesTargetAssignableUsers();
$assignableCounts = [
    'sales_director' => 0,
    'sales_manager' => 0,
    'sdr' => 0,
];
foreach ($assignable as $u) {
    $roleKey = strtolower(trim((string)($u['role'] ?? '')));
    if (isset($assignableCounts[$roleKey])) {
        $assignableCounts[$roleKey]++;
    }
}
$targets = [];
if (!empty($assignable)) {
    $conn = getDbConnection();
    $ids = array_map(fn($r) => (int)$r['id'], $assignable);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT t.*, u.full_name AS assigned_by_name, u.role AS assigned_by_role, u.job_title AS assigned_by_job_title
        FROM sales_targets t
        LEFT JOIN users u ON u.id = t.assigned_by
        WHERE t.year = ? AND t.month = ? AND t.user_id IN ($in)";
    $stmt = $conn->prepare($sql);
    $types = 'ii' . str_repeat('i', count($ids));
    $stmt->bind_param($types, $viewYear, $viewMonth, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $targets[(int)$r['user_id']] = $r;
    }
}

$commissionPctMap = function_exists('getSalesCommissionPercentMap') ? getSalesCommissionPercentMap(array_map(fn($r) => (int)$r['id'], $assignable)) : [];

$monthStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-01 00:00:00', $viewYear, $viewMonth)) ?: new DateTimeImmutable();
$monthEnd = $monthStart->modify('first day of next month')->modify('-1 second');
$ytdStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%04d-01-01 00:00:00', $viewYear)) ?: new DateTimeImmutable();
$ytdEnd = $monthEnd;

$monthlyRevenue = [];
$monthlyCommissionPaid = [];
$ytdCommissionPaid = [];
if (!empty($assignable)) {
    $conn = getDbConnection();
    $ids = array_values(array_filter(array_map(fn($r) => (int)$r['id'], $assignable), fn($v) => $v > 0));
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $idTypes = str_repeat('i', count($ids));
        $startTs = $monthStart->format('Y-m-d H:i:s');
        $endTs = $monthEnd->format('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            SELECT
                sales_owner_id AS uid,
                COALESCE(SUM(CASE WHEN currency = 'USD' THEN total ELSE 0 END),0) AS usd_total,
                COALESCE(SUM(CASE WHEN currency = 'INR' THEN total ELSE 0 END),0) AS inr_total
            FROM revenue_invoices
            WHERE sales_owner_id IN ($in)
              AND status = 'Paid'
              AND COALESCE(paid_at, updated_at, created_at) >= ?
              AND COALESCE(paid_at, updated_at, created_at) <= ?
            GROUP BY sales_owner_id
        ");
        if ($stmt) {
            $types = $idTypes . 'ss';
            $params = array_merge($ids, [$startTs, $endTs]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $r) {
                $uid = (int)($r['uid'] ?? 0);
                if ($uid <= 0) continue;
                $monthlyRevenue[$uid] = [
                    'USD' => (float)($r['usd_total'] ?? 0.0),
                    'INR' => (float)($r['inr_total'] ?? 0.0),
                ];
            }
        }

        $stmt = $conn->prepare("
            SELECT
                sales_user_id AS uid,
                COALESCE(SUM(CASE WHEN currency = 'USD' THEN commission_amount ELSE 0 END),0) AS usd_comm,
                COALESCE(SUM(CASE WHEN currency = 'INR' THEN commission_amount ELSE 0 END),0) AS inr_comm
            FROM sales_commissions
            WHERE sales_user_id IN ($in)
              AND status = 'paid'
              AND paid_at IS NOT NULL
              AND paid_at >= ?
              AND paid_at <= ?
            GROUP BY sales_user_id
        ");
        if ($stmt) {
            $types = $idTypes . 'ss';
            $params = array_merge($ids, [$startTs, $endTs]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $r) {
                $uid = (int)($r['uid'] ?? 0);
                if ($uid <= 0) continue;
                $monthlyCommissionPaid[$uid] = [
                    'USD' => (float)($r['usd_comm'] ?? 0.0),
                    'INR' => (float)($r['inr_comm'] ?? 0.0),
                ];
            }
        }

        $ytdStartTs = $ytdStart->format('Y-m-d H:i:s');
        $ytdEndTs = $ytdEnd->format('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            SELECT
                sales_user_id AS uid,
                COALESCE(SUM(CASE WHEN currency = 'USD' THEN commission_amount ELSE 0 END),0) AS usd_comm,
                COALESCE(SUM(CASE WHEN currency = 'INR' THEN commission_amount ELSE 0 END),0) AS inr_comm
            FROM sales_commissions
            WHERE sales_user_id IN ($in)
              AND status = 'paid'
              AND paid_at IS NOT NULL
              AND paid_at >= ?
              AND paid_at <= ?
            GROUP BY sales_user_id
        ");
        if ($stmt) {
            $types = $idTypes . 'ss';
            $params = array_merge($ids, [$ytdStartTs, $ytdEndTs]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $r) {
                $uid = (int)($r['uid'] ?? 0);
                if ($uid <= 0) continue;
                $ytdCommissionPaid[$uid] = [
                    'USD' => (float)($r['usd_comm'] ?? 0.0),
                    'INR' => (float)($r['inr_comm'] ?? 0.0),
                ];
            }
        }
    }
}

$pageTitle = 'Sales Targets';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<style>
  .sales-target-stat {
    border: 1px solid rgba(37, 99, 235, 0.12);
    border-radius: 1rem;
    background: linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(248,250,252,.96) 100%);
    box-shadow: 0 16px 36px -32px rgba(37, 99, 235, 0.22);
    padding: 1rem;
    height: 100%;
  }
  .sales-target-stat-label {
    color: #64748b;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .sales-target-stat-value {
    font-size: 1.55rem;
    font-weight: 700;
    line-height: 1.15;
  }
  .sales-target-table th {
    white-space: nowrap;
  }
  .sales-target-table td {
    vertical-align: middle;
  }
  .sales-target-user {
    min-width: 190px;
  }
  .sales-target-input {
    min-width: 130px;
  }
  .sales-target-note {
    border: 1px dashed rgba(148, 163, 184, 0.45);
    border-radius: .9rem;
    background: rgba(248, 250, 252, 0.8);
    padding: .9rem 1rem;
  }
</style>

<div class="container-fluid px-0 sales-workspace">
  <div class="sales-page-header sales-hero mb-3">
    <div>
      <div class="sales-page-eyebrow mb-2"><i class="bi bi-bullseye"></i>Target Planning</div>
      <h3 class="sales-page-title">Sales Productivity Targets</h3>
      <div class="sales-page-subtitle">Set monthly new-account, revenue, and commission goals for the commercial team, including Sales Directors, Sales Managers, and SDRs.</div>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="month" class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">
      <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-calendar me-1"></i>View</button>
    </form>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="sales-target-stat">
        <div class="sales-target-stat-label">Plan Month</div>
        <div class="sales-target-stat-value"><?php echo htmlspecialchars(date('M Y', mktime(0, 0, 0, $viewMonth, 1, $viewYear))); ?></div>
        <div class="small text-muted mt-2">Planning sheet stays scoped to the selected month.</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="sales-target-stat">
        <div class="sales-target-stat-label">Assignable Users</div>
        <div class="sales-target-stat-value"><?php echo number_format(count($assignable)); ?></div>
        <div class="small text-muted mt-2">All eligible sales users included in one sheet.</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="sales-target-stat">
        <div class="sales-target-stat-label">Directors</div>
        <div class="sales-target-stat-value"><?php echo number_format((int)$assignableCounts['sales_director']); ?></div>
        <div class="small text-muted mt-2">Sales Directors</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="sales-target-stat">
        <div class="sales-target-stat-label">Managers</div>
        <div class="sales-target-stat-value"><?php echo number_format((int)$assignableCounts['sales_manager']); ?></div>
        <div class="small text-muted mt-2">Sales Managers</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="sales-target-stat">
        <div class="sales-target-stat-label">SDRs</div>
        <div class="sales-target-stat-value"><?php echo number_format((int)$assignableCounts['sdr']); ?></div>
        <div class="small text-muted mt-2">Sales Development Reps</div>
      </div>
    </div>
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="save_targets">
    <input type="hidden" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">

    <div class="card mb-3 sales-panel border-0 shadow-sm">
      <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-currency-exchange me-1"></i>FX Rate</span>
        <span class="text-muted small"><?php echo htmlspecialchars($rateDateLabel); ?></span>
      </div>
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label">USD → INR (today)</label>
            <input class="form-control form-control-sm" name="usd_inr_rate" value="<?php echo htmlspecialchars($rate !== null ? (string)$rate : ''); ?>" placeholder="e.g. 83.25" readonly>
          </div>
          <div class="col-md-8">
            <div class="<?php echo htmlspecialchars($fxInfoClass); ?> small mb-1"><?php echo htmlspecialchars($fxInfoText); ?></div>
            <div class="text-muted small">This rate is used for INR columns only. Targets remain stored in USD.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card sales-panel border-0 shadow-sm">
      <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-bullseye me-1"></i>Targets</span>
        <span class="text-muted small"><?php echo htmlspecialchars(date('F Y', mktime(0, 0, 0, $viewMonth, 1, $viewYear))); ?></span>
      </div>
      <div class="card-body">
        <div class="sales-target-note mb-3">
          <div class="fw-semibold mb-1">Planning Notes</div>
          <div class="small text-muted">Assign targets role-by-role from one screen. Revenue targets remain stored in USD, while actual earned amounts continue showing both USD and INR when available.</div>
        </div>

        <div class="table-responsive sales-table-responsive">
          <table class="table table-sm align-middle sales-target-table">
            <thead class="table-light">
              <tr>
                <th>User</th>
                <th>Role</th>
                <!-- <th>Job Title</th> -->
                <th class="text-nowrap">Commission %</th>
                <th class="text-nowrap">New Accounts Target</th>
                <th class="text-nowrap">Revenue Target (USD)</th>
                <th class="text-nowrap">Monthly Revenue</th>
                <th class="text-nowrap">Monthly Commission</th>
                <th class="text-nowrap">YTD Commission</th>
                <th class="text-muted text-nowrap">Assigned</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($assignable)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No Sales users found.</td></tr>
              <?php else: ?>
                <?php foreach ($assignable as $u): ?>
                  <?php
                    $uid = (int)($u['id'] ?? 0);
                    $t = $targets[$uid] ?? null;
                    $tNew = (int)($t['target_new_accounts'] ?? 0);
                    $tUsd = (float)($t['target_revenue_usd'] ?? 0);
                    $assignedAt = (string)($t['assigned_at'] ?? '');
                    $assignedBy = trim((string)($t['assigned_by_name'] ?? ''));
                    $assignedByRole = trim((string)($t['assigned_by_role'] ?? ''));
                    $pct = (float)($commissionPctMap[$uid] ?? 0.0);
                    $mRev = $monthlyRevenue[$uid] ?? ['USD' => 0.0, 'INR' => 0.0];
                    $mComm = $monthlyCommissionPaid[$uid] ?? ['USD' => 0.0, 'INR' => 0.0];
                    $yComm = $ytdCommissionPaid[$uid] ?? ['USD' => 0.0, 'INR' => 0.0];
                    $roleLabel = function_exists('getRoleLabelShort') ? getRoleLabelShort((string)($u['role'] ?? '')) : ucwords(str_replace('_', ' ', (string)($u['role'] ?? '')));
                    $roleBadgeClass = (string)($u['role'] ?? '') === 'sales_director'
                        ? 'bg-success-subtle text-success border'
                        : ((string)($u['role'] ?? '') === 'sales_manager'
                            ? 'bg-primary-subtle text-primary border'
                            : 'bg-secondary-subtle text-secondary border');
                  ?>
                  <tr>
                    <td class="sales-target-user">
                      <div class="fw-semibold"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></div>
                    </td>
                    <td><span class="badge <?php echo htmlspecialchars($roleBadgeClass); ?>"><?php echo htmlspecialchars($roleLabel); ?></span></td>
                    <!-- <td class="text-muted small"><?php echo htmlspecialchars((string)($u['job_title'] ?? '')); ?></td> -->
                    <td class="sales-target-input">
                      <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" name="commission_percent[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars(number_format($pct, 2, '.', '')); ?>" placeholder="e.g. 8">
                    </td>
                    <td class="sales-target-input">
                      <input type="number" min="0" class="form-control form-control-sm" name="target_new_accounts[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars((string)$tNew); ?>">
                    </td>
                    <td class="sales-target-input">
                      <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="target_revenue_usd[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars(number_format($tUsd, 2, '.', '')); ?>">
                    </td>
                    <td class="text-nowrap">
                      <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($mRev['USD'] ?? 0.0), 2)); ?></div>
                      <?php if (!empty($mRev['INR'])): ?>
                        <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($mRev['INR'] ?? 0.0), 2)); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                      <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($mComm['USD'] ?? 0.0), 2)); ?></div>
                      <?php if (!empty($mComm['INR'])): ?>
                        <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($mComm['INR'] ?? 0.0), 2)); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                      <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($yComm['USD'] ?? 0.0), 2)); ?></div>
                      <?php if (!empty($yComm['INR'])): ?>
                        <div class="text-muted small">₹<?php echo htmlspecialchars(number_format((float)($yComm['INR'] ?? 0.0), 2)); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted small">
                      <?php if ($assignedAt !== ''): ?>
                        <?php echo htmlspecialchars(date('d M, H:i', strtotime($assignedAt))); ?>
                        <?php if ($assignedBy !== ''): ?>
                          <div><?php echo htmlspecialchars(formatUserNameWithRole($assignedBy, $assignedByRole)); ?></div>
                        <?php endif; ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <a href="dashboard.php" class="btn btn-light btn-sm">Back</a>
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i>Save Targets</button>
        </div>
      </div>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
