<?php
// Use shared authentication helpers for session and redirects
require_once __DIR__ . '/includes/auth.php';

// If user is already logged in, redirect to appropriate dashboard
if (isLoggedIn()) {
    $user = getCurrentUser();
    $role = function_exists('normalizeRole') ? normalizeRole((string)($user['role'] ?? '')) : (string)($user['role'] ?? '');
    $permEnabled = function_exists('getAccessRolePermissionsConfig') && getAccessRolePermissionsConfig() !== null;
    if ($permEnabled && function_exists('userHasPermission')) {
        $routes = [
            'dashboard.admin' => 'modules/dashboard/admin-dashboard',
            'dashboard.sales' => 'modules/dashboard/sales-dashboard',
            'dashboard.operations' => 'modules/dashboard/operations-dashboard',
            'dashboard.marketing' => 'modules/dashboard/email-marketing-dashboard',
            'dashboard.qa' => 'modules/qa/dashboard',
            'dashboard.vendor' => 'modules/dashboard/vendor-dashboard',
            'dashboard.client' => 'modules/dashboard/client-dashboard',
            'dbms.access' => 'modules/dbms',
            'dashboard.access' => 'modules/dashboard/admin-dashboard',
        ];
        foreach ($routes as $perm => $to) {
            if (userHasPermission($perm)) {
                header('Location: ' . $to);
                exit();
            }
        }
    }
    switch ($role) {
        case 'admin':
            header('Location: modules/dashboard/admin-dashboard');
            break;
        case 'director':
        case 'manager_director':
            header('Location: modules/dashboard/admin-dashboard');
            break;
        case 'agent':
        case 'operations_agent':
        case 'operations_manager':
        case 'operations_director':
            header('Location: modules/dashboard/operations-dashboard');
            break;
        case 'qa':
        case 'qa_agent':
        case 'qa_manager':
        case 'qa_director':
            header('Location: modules/qa/dashboard');
            break;
        case 'form_filler':
        case 'email_marketing_executive':
        case 'email_marketing_agent':
        case 'email_marketing_manager':
        case 'email_marketing_director':
            header('Location: modules/dashboard/email-marketing-dashboard');
            break;
        case 'sales_director':
        case 'sales_manager':
        case 'sdr':
            header('Location: modules/dashboard/sales-dashboard');
            break;
        case 'vendor_admin':
        case 'vendor_user':
            header('Location: modules/dashboard/vendor-dashboard');
            break;
        case 'client_admin':
        case 'client_sdr':
            header('Location: modules/dashboard/client-dashboard');
            break;
        default:
            if ((int)($user['vendor_id'] ?? 0) > 0) {
                header('Location: modules/dashboard/vendor-dashboard');
                break;
            }
            if ((int)($user['client_id'] ?? 0) > 0) {
                header('Location: modules/dashboard/client-dashboard');
                break;
            }
            header('Location: modules/dashboard/admin-dashboard');
            break;
    }
    exit();
}

// If not logged in, redirect to login page
header('Location: modules/auth/login');
exit();
?>
