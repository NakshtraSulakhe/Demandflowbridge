<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

ensureCsrfToken();
ensureDatabaseSchema();

$conn = getDbConnection();
$isLoggedIn = isLoggedIn();
$isAdmin = isAdmin();
$now = date('Y-m-d H:i:s');

$message = '';
$messageType = '';

$selector = trim((string)($_GET['selector'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$hasTokenLink = ($selector !== '' && $token !== '');

$isHex = function(string $s): bool {
    return $s !== '' && ctype_xdigit($s);
};

$baseUrl = function(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = function_exists('appBasePath') ? (string)appBasePath() : '';
    return $scheme . '://' . $host . $base;
};

if (!$isLoggedIn && $hasTokenLink) {
    if (!$isHex($selector) || strlen($selector) !== 24 || !$isHex($token) || strlen($token) < 32) {
        $message = 'Invalid password reset link.';
        $messageType = 'danger';
        $hasTokenLink = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if (!$isLoggedIn && $action === 'request_reset') {
            $identity = trim((string)($_POST['identity'] ?? ''));
            if ($identity === '') {
                $message = 'Please enter your email address.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("SELECT id, full_name, email, is_active FROM users WHERE email = ? OR username = ? LIMIT 1");
                $stmt->bind_param('ss', $identity, $identity);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();

                if (!$u || (int)($u['is_active'] ?? 0) !== 1) {
                    $message = 'If the account exists, a password reset email has been sent.';
                    $messageType = 'success';
                } else {
                    $uid = (int)($u['id'] ?? 0);
                    $toEmail = (string)($u['email'] ?? '');
                    $toName = (string)($u['full_name'] ?? '');

                    $selectorNew = bin2hex(random_bytes(12));
                    $validator = bin2hex(random_bytes(32));
                    $hash = hash('sha256', $validator);
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
                    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                    if ($ua !== '' && strlen($ua) > 255) $ua = substr($ua, 0, 255);

                    $stmt = $conn->prepare("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL");
                    if ($stmt) {
                        $stmt->bind_param('si', $now, $uid);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $ins = $conn->prepare("INSERT INTO password_resets (user_id, selector, token_hash, expires_at, requested_ip, requested_ua) VALUES (?,?,?,?,?,?)");
                    if (!$ins) {
                        $message = 'Unable to start password reset.';
                        $messageType = 'danger';
                    } else {
                        $ins->bind_param('isssss', $uid, $selectorNew, $hash, $expiresAt, $ip, $ua);
                        $ok = $ins->execute();
                        $ins->close();
                        if (!$ok) {
                            $message = 'Unable to start password reset.';
                            $messageType = 'danger';
                        } else {
                            $link = $baseUrl() . '/modules/auth/reset-password?selector=' . urlencode($selectorNew) . '&token=' . urlencode($validator);
                            $subject = 'Password Reset - DemandFlow Bridge';
                            $safeName = htmlspecialchars($toName !== '' ? $toName : 'User');
                            $safeLink = htmlspecialchars($link);
                            $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#111">'
                                . '<p>Hi ' . $safeName . ',</p>'
                                . '<p>We received a request to reset your password.</p>'
                                . '<p><a href="' . $safeLink . '" style="display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px">Reset Password</a></p>'
                                . '<p>If the button does not work, copy and paste this link into your browser:</p>'
                                . '<p><a href="' . $safeLink . '">' . $safeLink . '</a></p>'
                                . '<p>This link will expire in 60 minutes.</p>'
                                . '<p>If you did not request this, you can ignore this email.</p>'
                                . '</div>';

                            $send = sendEmailViaSmtp($toEmail, $subject, $html);
                            if (!($send['ok'] ?? false)) {
                                $message = 'Unable to send reset email. ' . htmlspecialchars((string)($send['error'] ?? ''));
                                $messageType = 'danger';
                            } else {
                                $message = 'Password reset link sent to your email.';
                                $messageType = 'success';
                            }
                        }
                    }
                }
            }
        } elseif (!$isLoggedIn && $action === 'do_reset') {
            $selectorPost = trim((string)($_POST['selector'] ?? ''));
            $tokenPost = trim((string)($_POST['token'] ?? ''));
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($newPassword === '' || $confirmPassword === '') {
                $message = 'Please fill in all password fields.';
                $messageType = 'danger';
            } elseif ($newPassword !== $confirmPassword) {
                $message = 'New passwords do not match.';
                $messageType = 'danger';
            } elseif (strlen($newPassword) < 8) {
                $message = 'Password must be at least 8 characters long.';
                $messageType = 'danger';
            } elseif (!$isHex($selectorPost) || strlen($selectorPost) !== 24 || !$isHex($tokenPost) || strlen($tokenPost) < 32) {
                $message = 'Invalid password reset link.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("SELECT pr.id, pr.user_id, pr.token_hash, pr.expires_at, pr.used_at FROM password_resets pr WHERE pr.selector = ? LIMIT 1");
                $stmt->bind_param('s', $selectorPost);
                $stmt->execute();
                $pr = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();

                $valid = false;
                if ($pr && empty($pr['used_at']) && (string)($pr['expires_at'] ?? '') > $now) {
                    $calc = hash('sha256', $tokenPost);
                    $stored = (string)($pr['token_hash'] ?? '');
                    if ($stored !== '' && hash_equals($stored, $calc)) $valid = true;
                }

                if (!$valid) {
                    $message = 'This password reset link is invalid or expired.';
                    $messageType = 'danger';
                } else {
                    $uid = (int)($pr['user_id'] ?? 0);
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->bind_param('si', $hashedPassword, $uid);
                    $ok1 = $upd->execute();
                    $upd->close();

                    $mark = $conn->prepare("UPDATE password_resets SET used_at = ? WHERE id = ?");
                    $rid = (int)($pr['id'] ?? 0);
                    if ($mark) {
                        $mark->bind_param('si', $now, $rid);
                        $mark->execute();
                        $mark->close();
                    }

                    $mark2 = $conn->prepare("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL");
                    if ($mark2) {
                        $mark2->bind_param('si', $now, $uid);
                        $mark2->execute();
                        $mark2->close();
                    }

                    if ($ok1) {
                        $message = 'Your password has been reset successfully. You can now login.';
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to update password. Please try again.';
                        $messageType = 'danger';
                    }
                }
            }
            $selector = $selectorPost;
            $token = $tokenPost;
            $hasTokenLink = true;
        } elseif ($isLoggedIn && $action === 'logged_in_reset') {
            $currentUser = getCurrentUser();
            $userId = (int)($currentUser['id'] ?? 0);
            $targetUsername = (string)($currentUser['username'] ?? '');

            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $currentPassword = (string)($_POST['current_password'] ?? '');

            if ($newPassword === '' || $confirmPassword === '' || $currentPassword === '') {
                $message = 'Please fill in all password fields.';
                $messageType = 'danger';
            } elseif ($newPassword !== $confirmPassword) {
                $message = 'New passwords do not match.';
                $messageType = 'danger';
            } elseif (strlen($newPassword) < 8) {
                $message = 'Password must be at least 8 characters long.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if (!$row || !password_verify($currentPassword, (string)($row['password'] ?? ''))) {
                    $message = 'Current password is incorrect.';
                    $messageType = 'danger';
                } else {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashedPassword, $userId);
                    if ($stmt->execute()) {
                        $message = "Password for $targetUsername has been reset successfully.";
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to update password. Please try again.';
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        } elseif ($isLoggedIn && $action === 'admin_reset') {
            if (!$isAdmin) {
                header('Location: access-denied');
                exit;
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                $message = 'User not found.';
                $messageType = 'danger';
            } else {
                $newPassword = (string)($_POST['new_password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_password'] ?? '');
                if ($newPassword === '' || $confirmPassword === '') {
                    $message = 'Please fill in all password fields.';
                    $messageType = 'danger';
                } elseif ($newPassword !== $confirmPassword) {
                    $message = 'New passwords do not match.';
                    $messageType = 'danger';
                } elseif (strlen($newPassword) < 8) {
                    $message = 'Password must be at least 8 characters long.';
                    $messageType = 'danger';
                } else {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashedPassword, $userId);
                    if ($stmt->execute()) {
                        $message = "Password has been reset successfully.";
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to update password. Please try again.';
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    }
}
?>
<?php $pageTitle = 'Reset Password'; include __DIR__ . '/../../includes/layout/' . ($isLoggedIn ? 'app_start.php' : 'auth_start.php'); ?>

    <div class="container-fluid px-3 px-lg-4 py-4">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header">
                        <?php if (!$isLoggedIn && !$hasTokenLink): ?>
                            <h4 class="mb-0">Forgot Password</h4>
                        <?php elseif (!$isLoggedIn && $hasTokenLink): ?>
                            <h4 class="mb-0">Set New Password</h4>
                        <?php elseif ($isLoggedIn && $isAdmin && isset($_GET['user_id'])): ?>
                            <h4 class="mb-0">Reset Password</h4>
                        <?php else: ?>
                            <h4 class="mb-0">Reset Your Password</h4>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!$isLoggedIn && !$hasTokenLink): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                                <input type="hidden" name="action" value="request_reset">
                                <div class="mb-3">
                                    <label for="identity" class="form-label">Email Address</label>
                                    <input type="email" class="form-control form-control-sm" id="identity" name="identity" required>
                                    <div class="form-text">We will send a password reset link to your email.</div>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">Send Reset Link</button>
                                    <a href="login" class="btn btn-secondary">Back to Login</a>
                                </div>
                            </form>
                        <?php elseif (!$isLoggedIn && $hasTokenLink): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                                <input type="hidden" name="action" value="do_reset">
                                <input type="hidden" name="selector" value="<?php echo htmlspecialchars($selector); ?>">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control form-control-sm" id="new_password" name="new_password" required>
                                    <div class="form-text">Password must be at least 8 characters long.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password" required>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">Reset Password</button>
                                    <a href="login" class="btn btn-secondary">Back to Login</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <?php
                                $currentUser = getCurrentUser();
                                $targetUserId = (int)($_GET['user_id'] ?? 0);
                                $adminTarget = ($isAdmin && $targetUserId > 0);
                            ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                                <input type="hidden" name="action" value="<?php echo $adminTarget ? 'admin_reset' : 'logged_in_reset'; ?>">
                                <?php if ($adminTarget): ?>
                                    <input type="hidden" name="user_id" value="<?php echo (int)$targetUserId; ?>">
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control form-control-sm" id="new_password" name="new_password" required>
                                    <div class="form-text">Password must be at least 8 characters long.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password" required>
                                </div>
                                <?php if (!$adminTarget): ?>
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control form-control-sm" id="current_password" name="current_password" required>
                                    </div>
                                <?php endif; ?>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">Reset Password</button>
                                    <?php if ($adminTarget): ?>
                                        <a href="../users/manage-users" class="btn btn-secondary">Back to User Management</a>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars(appBasePath()); ?>/" class="btn btn-secondary">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../../includes/layout/' . ($isLoggedIn ? 'app_end.php' : 'auth_end.php'); ?>
