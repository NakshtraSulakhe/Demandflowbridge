<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','client_admin','client_sdr']);
ensureCsrfToken();

$user = getCurrentUser();
$clientId = (int)($user['client_id'] ?? 0);
if (isAdmin()) $clientId = (int)($_GET['client_id'] ?? $clientId);
if ($clientId <= 0 && !isAdmin()) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    $_SESSION['access_denied_context'] = [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'required_roles' => ['admin','client_admin','client_sdr'],
        'time' => time(),
    ];
    header("Location: " . appBasePath() . "/modules/auth/access-denied");
    exit;
}

$canEdit = isAdmin() || isClientAdmin();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_client_billing') {
    if (!$canEdit) {
        $err = 'Not allowed.';
    } elseif (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $err = 'Invalid token.';
    } else {
        $ok = upsertClientBillingProfile($clientId, $_POST, (int)($user['id'] ?? 0));
        $msg = $ok ? 'Saved.' : 'Unable to save.';
    }
}

$profile = getClientBillingProfile($clientId);
$pageTitle = 'Client Billing';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Billing Details</div>
      <div class="text-muted small">Used for invoicing and operations</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="../dashboard/client-dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <a class="btn btn-light border btn-sm" href="client-campaigns.php"><i class="bi bi-megaphone me-1"></i>Campaigns</a>
    </div>
  </div>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($msg !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light fw-semibold">Profile</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save_client_billing">

        <div class="col-md-6">
          <label class="form-label">Billing Name</label>
          <input class="form-control" name="billing_name" value="<?php echo htmlspecialchars((string)($profile['billing_name'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>
        <div class="col-md-3">
          <label class="form-label">Billing Email</label>
          <input class="form-control" name="billing_email" value="<?php echo htmlspecialchars((string)($profile['billing_email'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>
        <div class="col-md-3">
          <label class="form-label">Billing Phone</label>
          <input class="form-control" name="billing_phone" value="<?php echo htmlspecialchars((string)($profile['billing_phone'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>

        <div class="col-12">
          <label class="form-label">Billing Address</label>
          <textarea class="form-control" name="billing_address" rows="3" <?php echo !$canEdit ? 'readonly' : ''; ?>><?php echo htmlspecialchars((string)($profile['billing_address'] ?? '')); ?></textarea>
        </div>

        <div class="col-md-4">
          <label class="form-label">Tax ID / GST / VAT</label>
          <input class="form-control" name="tax_id" value="<?php echo htmlspecialchars((string)($profile['tax_id'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>

        <div class="col-md-4">
          <label class="form-label">Bank Name</label>
          <input class="form-control" name="bank_name" value="<?php echo htmlspecialchars((string)($profile['bank_name'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">Account Name</label>
          <input class="form-control" name="bank_account_name" value="<?php echo htmlspecialchars((string)($profile['bank_account_name'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">Account Number</label>
          <input class="form-control" name="bank_account_number" value="<?php echo htmlspecialchars((string)($profile['bank_account_number'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">IFSC / SWIFT</label>
          <input class="form-control" name="bank_ifsc_swift" value="<?php echo htmlspecialchars((string)($profile['bank_ifsc_swift'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">IBAN</label>
          <input class="form-control" name="bank_iban" value="<?php echo htmlspecialchars((string)($profile['bank_iban'] ?? '')); ?>" <?php echo !$canEdit ? 'readonly' : ''; ?>>
        </div>

        <div class="col-12">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="3" <?php echo !$canEdit ? 'readonly' : ''; ?>><?php echo htmlspecialchars((string)($profile['notes'] ?? '')); ?></textarea>
        </div>

        <?php if ($canEdit): ?>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save</button>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
