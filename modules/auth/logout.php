<?php
/**
 * Logout Page
 * 
 * Handles user logout
 */

// Include authentication system
require_once __DIR__ . '/../../includes/auth.php';

if (isset($_GET['ping']) && (string)$_GET['ping'] === '1') {
    requireLogin();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
            http_response_code(403);
            echo json_encode(['ok' => false]);
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
    $_SESSION['last_user_activity'] = time();
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Log out user
logoutUser();

if (function_exists('appFlashInfo')) {
    appFlashInfo('You have been signed out.');
}

// Redirect to login page
authSendNoCacheHeaders();
header('Location: login?ts=' . rawurlencode((string)time()));
exit;
