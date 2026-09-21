<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$user = getCurrentUser() ?: [];
$role = (string)($user['role'] ?? '');
$name = (string)($user['full_name'] ?? $user['username'] ?? 'User');
$ctx = is_array($_SESSION['ip_restricted_context'] ?? null) ? $_SESSION['ip_restricted_context'] : [];
unset($_SESSION['ip_restricted_context']);
$ip = (string)($ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
$reason = (string)($ctx['reason'] ?? 'IP is not allowed');
$allowed = $ctx['allowed_ips'] ?? [];
$allowedCount = is_array($allowed) ? count($allowed) : 0;
$trustXff = (string)($ctx['trust_xff'] ?? '');
$xffFirst = (string)($ctx['xff_first'] ?? '');

$pageTitle = 'Access Restricted';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-9">
      <div class="card border-0 shadow-sm overflow-hidden">
        <div class="row g-0">
          <div class="col-md-5 bg-light border-end">
            <div class="p-4 p-lg-5 h-100 d-flex flex-column justify-content-center">
              <div class="d-flex align-items-center gap-2 mb-3">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning border" style="width:48px;height:48px;">
                  <i class="bi bi-shield-exclamation fs-4"></i>
                </div>
                <div>
                  <div class="text-muted small">Security</div>
                  <div class="fw-semibold">IP Restricted</div>
                </div>
              </div>
              <div class="text-muted">
                Access denied. <?php echo htmlspecialchars($reason); ?>.
              </div>
              <div class="mt-4 small text-muted">
                <div class="fw-semibold text-dark mb-1"><?php echo htmlspecialchars($name); ?></div>
                <div class="d-flex flex-wrap gap-2">
                  <span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars($role !== '' ? $role : 'unknown'); ?></span>
                  <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ip !== '' ? $ip : 'unknown'); ?></span>
                </div>
                <div class="mt-2 small text-muted">
                  Allowed IPs: <span class="fw-semibold text-dark"><?php echo number_format($allowedCount); ?></span>
                  <?php if ($trustXff === '1' && $xffFirst !== ''): ?>
                    · XFF: <span class="fw-semibold text-dark"><?php echo htmlspecialchars($xffFirst); ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="mt-4 d-grid gap-2">
                <a class="btn btn-outline-danger" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
              </div>
            </div>
          </div>
          <div class="col-md-7">
            <div class="p-4 p-lg-5">
              <div class="fw-semibold mb-2">What you can do</div>
              <div class="text-muted mb-3">
                Ask your administrator to add your IP address to the allowed list in Settings.
              </div>
              <div class="border rounded p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-gear me-1"></i>Admin Path</div>
                <div class="small text-muted">
                  System → Settings → Security → User IP Access
                </div>
              </div>
              <div class="mt-3 border rounded p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Note</div>
                <div class="small text-muted">
                  If you are behind a VPN or proxy, your public IP can change.
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
