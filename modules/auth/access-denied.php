<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$user = getCurrentUser() ?: [];
$role = (string)($user['role'] ?? '');
$name = (string)($user['full_name'] ?? $user['username'] ?? 'User');
$ctx = is_array($_SESSION['access_denied_context'] ?? null) ? $_SESSION['access_denied_context'] : [];
unset($_SESSION['access_denied_context']);
$reqUri = (string)($ctx['uri'] ?? '');
$requiredRoles = $ctx['required_roles'] ?? [];
if (!is_array($requiredRoles)) $requiredRoles = [];
$requiredPermission = (string)($ctx['required_permission'] ?? '');

$dash = '../dashboard/admin-dashboard.php';
if (isQA()) $dash = '../qa/dashboard';
elseif (isAgent() || isOperationsAgent() || isOperationsManager() || isOperationsDirector()) $dash = '../dashboard/operations-dashboard.php';
elseif (isFormFiller()) $dash = '../dashboard/form-filler-dashboard.php';
elseif (hasRole(['email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive'])) $dash = '../dashboard/email-marketing-dashboard.php';
elseif (hasRole(['sales_director','sales_manager','sdr'])) $dash = '../dashboard/sales-dashboard.php';
elseif (hasRole(['client_admin'])) $dash = '../dashboard/client-dashboard.php';
elseif (hasRole(['vendor_admin'])) $dash = '../dashboard/vendor-dashboard.php';

$pageTitle = 'Access Denied';
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
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger border" style="width:48px;height:48px;">
                  <i class="bi bi-shield-lock fs-4"></i>
                </div>
                <div>
                  <div class="text-muted small">403</div>
                  <div class="fw-semibold">Access Denied</div>
                </div>
              </div>
              <div class="text-muted">
                You don’t have permission to access this page with your current account.
              </div>
              <div class="mt-4 small text-muted">
                <div class="fw-semibold text-dark mb-1"><?php echo htmlspecialchars($name); ?></div>
                <div>
                  <span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars(getRoleLabelFull($role !== '' ? $role : 'unknown')); ?></span>
                </div>
              </div>
              <div class="mt-4 d-grid gap-2">
                <a class="btn btn-primary" href="<?php echo htmlspecialchars($dash); ?>"><i class="bi bi-house-door me-1"></i>Go to Dashboard</a>
                <button class="btn btn-light border" type="button" onclick="history.back()"><i class="bi bi-arrow-left me-1"></i>Go Back</button>
                <a class="btn btn-outline-danger" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
              </div>
            </div>
          </div>
          <div class="col-md-7">
            <div class="p-4 p-lg-5">
              <div class="fw-semibold mb-2">What you can do</div>
              <div class="text-muted mb-3">
                If you think you should have access, contact your administrator or request access for the required role.
              </div>

              <div class="row g-3">
                <div class="col-12">
                  <div class="border rounded p-3">
                    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Request Details</div>
                    <div class="small text-muted">
                      <div class="d-flex justify-content-between">
                        <span>Requested URL</span>
                        <span class="font-monospace text-dark text-truncate" style="max-width: 55%;"><?php echo htmlspecialchars($reqUri !== '' ? $reqUri : '—'); ?></span>
                      </div>
                      <div class="d-flex justify-content-between mt-1">
                        <span>Required Roles</span>
                        <span class="text-dark">
                          <?php
                            if (empty($requiredRoles)) echo '—';
                            else echo htmlspecialchars(implode(', ', array_map('strval', $requiredRoles)));
                          ?>
                        </span>
                      </div>
                      <div class="d-flex justify-content-between mt-1">
                        <span>Required Permission</span>
                        <span class="text-dark"><?php echo htmlspecialchars($requiredPermission !== '' ? $requiredPermission : '—'); ?></span>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="border rounded p-3">
                    <div class="fw-semibold mb-2"><i class="bi bi-envelope me-1"></i>Contact</div>
                    <div class="text-muted small mb-2">Send a quick message to the admin with the link you tried to open.</div>
                    <?php
                      $subject = 'Access request';
                      $body = "Hi Admin,\n\nI got Access Denied while trying to open:\n" . ($reqUri !== '' ? $reqUri : '—') . "\n\nMy role: " . ($role !== '' ? getRoleLabelFull($role) : '—') . "\nRequired permission: " . ($requiredPermission !== '' ? $requiredPermission : '—') . "\nRequired roles: " . (!empty($requiredRoles) ? implode(', ', array_map('strval', $requiredRoles)) : '—') . "\n\nThanks.";
                      $mailto = 'mailto:hr@tarajglobal.com?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
                    ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($mailto); ?>"><i class="bi bi-send me-1"></i>Email HR</a>
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
