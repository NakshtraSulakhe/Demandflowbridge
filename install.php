<?php
/**
 * Database Installer
 * 
 * Automatically creates required tables and initial setup.
 */

// Load database connection
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (defined('APP_DEBUG') && !APP_DEBUG) {
    http_response_code(404);
    exit;
}

// Start session for CSRF and messages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Simple CSRF protection for installer
if (!isset($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'info';
$isInstalled = file_exists(__DIR__ . '/storage/setup_done.flag');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['install_token']) {
        $message = "Invalid session token.";
        $messageType = "danger";
    } elseif ($isInstalled && (!isset($_POST['force']) || $_POST['force'] !== '1')) {
        $message = "System is already installed. Use force option to re-run.";
        $messageType = "warning";
    } else {
        try {
            // Ensure directories exist
            $dirs = [
                __DIR__ . '/storage',
                __DIR__ . '/tmp',
                __DIR__ . '/tmp/sessions',
                __DIR__ . '/tmp/uploads',
                __DIR__ . '/uploads',
                __DIR__ . '/uploads/profiles',
                __DIR__ . '/uploads/logo',
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
            }

            // Run database schema creation
            ensureDatabaseSchema();
            
            // Create default admin user if not exists
            $conn = getDbConnection();
            $checkAdmin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
            if ($checkAdmin && $checkAdmin->num_rows === 0) {
                $username = 'admin';
                $password = password_hash('admin123', PASSWORD_DEFAULT);
                $email = 'admin@example.com';
                $stmt = $conn->prepare("INSERT INTO users (username, password, email, role, full_name, is_active) VALUES (?, ?, ?, 'admin', 'System Administrator', 1)");
                $stmt->bind_param('sss', $username, $password, $email);
                $stmt->execute();
                $message = "Installation successful! Default admin created (admin/admin123). ";
            } else {
                $message = "Database schema updated successfully. ";
            }

            // Create flag file
            @file_put_contents(__DIR__ . '/storage/setup_done.flag', json_encode([
                'installed_at' => date('c'),
                'version' => '1.0.0'
            ]));
            
            $message .= "Please delete this file (install.php) after installation for security.";
            $messageType = "success";
            $isInstalled = true;

        } catch (Throwable $e) {
            $message = "Installation failed: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DemandFlow Bridge - Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .install-card { width: 100%; max-width: 500px; padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.05); background: white; }
        .logo { font-size: 1.5rem; font-weight: 700; color: #0d6efd; text-align: center; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <div class="install-card">
        <div class="logo">DemandFlow Bridge <span class="text-muted small">Installer</span></div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> mb-4"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!$isInstalled): ?>
            <p class="text-muted mb-4">Click the button below to initialize the database and create the default administrator account.</p>
            <form method="POST">
                <input type="hidden" name="action" value="install">
                <input type="hidden" name="token" value="<?php echo $_SESSION['install_token']; ?>">
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">Run Installation</button>
                </div>
            </form>
        <?php else: ?>
            <div class="text-center">
                <p class="text-success mb-4"><i class="bi bi-check-circle-fill"></i> System is already initialized.</p>
                <div class="d-grid gap-2">
                    <a href="index.php" class="btn btn-outline-primary">Go to Login</a>
                    <form method="POST">
                        <input type="hidden" name="action" value="install">
                        <input type="hidden" name="token" value="<?php echo $_SESSION['install_token']; ?>">
                        <input type="hidden" name="force" value="1">
                        <button type="submit" class="btn btn-link btn-sm text-muted">Re-run Installer (Force)</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
