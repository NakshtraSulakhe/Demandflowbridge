<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
ensureCsrfToken();
$user = getCurrentUser();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token.';
    } else {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            $error = 'All fields are required.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $error = 'Password must be at least 8 characters and include letters and numbers.';
        } else {
            $conn = getDbConnection();
            // Fetch current password hash
            $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            if (!$stmt) {
                $error = 'System error. Please try again later.';
            } else {
                $uid = (int)$user['id'];
                $stmt->bind_param('i', $uid);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        $hash = $row['password'];
                        if (!password_verify($current, $hash)) {
                            $error = 'Current password is incorrect.';
                        } else {
                            $newHash = password_hash($new, PASSWORD_DEFAULT);
                            $stmtU = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                            if ($stmtU) {
                                $stmtU->bind_param('si', $newHash, $uid);
                                if ($stmtU->execute()) {
                                    $success = true;
                                } else {
                                    $error = 'Failed to update password.';
                                }
                                $stmtU->close();
                            } else {
                                $error = 'System error. Please try again later.';
                            }
                        }
                    } else {
                        $error = 'User not found.';
                    }
                } else {
                    $error = 'System error. Please try again later.';
                }
                $stmt->close();
            }
        }
    }
}
?>
<?php $pageTitle = 'Change Password'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-key me-2"></i>Change Password</div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success">Your password has been updated successfully.</div>
                    <?php endif; ?>
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" class="form-control form-control-sm" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" class="form-control form-control-sm" id="new_password" name="new_password" required>
                            <div class="form-text">Minimum 8 characters, include letters and numbers.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password" required>
                        </div>
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo isAgent() ? '../dashboard/operations-dashboard.php' : '../../index.php'; ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Update Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
