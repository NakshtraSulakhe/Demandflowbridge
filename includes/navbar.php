<?php
/**
 * Navigation Bar Component
 * 
 * This file contains the navigation bar that is included in all pages.
 * It displays different navigation options based on user role.
 */

// Prevent double rendering if included multiple times accidentally
if (defined('NAVBAR_RENDERED') && NAVBAR_RENDERED === true) {
    return;
}
define('NAVBAR_RENDERED', true);

// Ensure this file is included, not accessed directly
if (!defined('INCLUDED')) {
    define('INCLUDED', true);
}

// Get current user information
$navbarUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
// Company logo path (override $companyLogoPath before including this file if needed)
$navbarBase = function_exists('appBasePath') ? appBasePath() : '';
$navbarModules = $navbarBase . '/modules';
$companyLogoPath = isset($companyLogoPath) ? $companyLogoPath : ($navbarBase . '/assets/images/logos/DemandFlow-Media-logo.png');
$navbarFluid = isset($navbarFluid) ? $navbarFluid : true;
$permEnabled = function_exists('getAccessRolePermissionsConfig') && getAccessRolePermissionsConfig() !== null;
$canPerm = function(string $p) use ($permEnabled): bool { return !$permEnabled || (function_exists('userHasPermission') && userHasPermission($p)); };
$dashHref = '#';
if ($permEnabled) {
    if ($canPerm('dashboard.admin')) $dashHref = $navbarModules . '/dashboard/admin-dashboard.php';
    elseif ($canPerm('dashboard.sales')) $dashHref = $navbarModules . '/dashboard/sales-dashboard.php';
    elseif ($canPerm('dashboard.operations')) $dashHref = $navbarModules . '/dashboard/operations-dashboard.php';
    elseif ($canPerm('dashboard.marketing')) $dashHref = $navbarModules . '/dashboard/email-marketing-dashboard.php';
    elseif ($canPerm('dashboard.qa')) $dashHref = $navbarModules . '/qa/dashboard';
    elseif ($canPerm('dashboard.client')) $dashHref = $navbarModules . '/dashboard/client-dashboard.php';
    elseif ($canPerm('dashboard.vendor')) $dashHref = $navbarModules . '/dashboard/vendor-dashboard.php';
    elseif ($canPerm('dbms.access')) $dashHref = $navbarModules . '/dbms';
    elseif ($canPerm('dashboard.access')) $dashHref = $navbarModules . '/dashboard/admin-dashboard.php';
} else {
    if (isAdmin() || isDirector()) $dashHref = $navbarModules . '/dashboard/admin-dashboard.php';
    elseif (isSales()) $dashHref = $navbarModules . '/dashboard/sales-dashboard.php';
    elseif (isAgent()) $dashHref = $navbarModules . '/dashboard/operations-dashboard.php';
    elseif (isFormFiller()) $dashHref = $navbarModules . '/dashboard/email-marketing-dashboard.php';
    elseif (isQA()) $dashHref = $navbarModules . '/qa/dashboard';
    elseif (hasRole(['client_admin','client_sdr'])) $dashHref = $navbarModules . '/dashboard/client-dashboard.php';
    elseif (hasRole(['vendor_admin','vendor_user'])) $dashHref = $navbarModules . '/dashboard/vendor-dashboard.php';
    elseif (function_exists('userHasPermission') && userHasPermission('dbms.access')) $dashHref = $navbarModules . '/dbms';
}
$uriPath = strtolower((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
?>

<nav class="navbar navbar-expand-lg fixed-top <?php echo isset($navbarDark) && $navbarDark ? 'navbar-dark bg-dark' : 'navbar-dark bg-primary'; ?>">
    <div class="container<?php echo isset($navbarFluid) && $navbarFluid ? '-fluid' : ''; ?>">
        <a class="navbar-brand d-flex align-items-center" href="<?php echo htmlspecialchars($dashHref); ?>">
            <img src="<?php echo htmlspecialchars($companyLogoPath); ?>" alt="Company Logo" class="me-2" style="height:36px;">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <!-- Dashboard Links -->
                <?php if ($canPerm('dashboard.admin')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'admin-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/dashboard/admin-dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                <?php elseif ($canPerm('dashboard.sales')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'sales-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/dashboard/sales-dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                <?php elseif ($canPerm('dashboard.operations')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'operations-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/dashboard/operations-dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                <?php elseif ($canPerm('dashboard.marketing')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'email-marketing-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/dashboard/email-marketing-dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                <?php elseif ($canPerm('dashboard.qa')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'qa-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/qa/dashboard">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                <?php elseif ($canPerm('dbms.access')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo (strpos($uriPath, '/modules/dbms') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/dbms">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($canPerm('chat.access')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'chat.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/chat/chat.php">
                            <i class="bi bi-chat-dots"></i> Chat
                        </a>
                    </li>
                <?php endif; ?>
                
                <!-- Agent & Form Filler Links -->
                <?php if ($canPerm('leads.entry')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'agent.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/leads/agent.php">
                            <i class="bi bi-plus-circle"></i> Submit Lead
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($canPerm('leads.my')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'my-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/leads/my-leads.php">
                            <i class="bi bi-list-check"></i> My Leads
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($canPerm('campaigns.view') || $canPerm('campaigns.manage')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'campaigns-manage.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/campaigns/campaigns-manage.php">
                            <i class="bi bi-bar-chart"></i> Campaigns
                        </a>
                    </li>
                <?php endif; ?>
                
                <!-- Form Filler Links -->
                <?php if ($canPerm('leads.marketing')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage == 'form-filler.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/leads/form-filler.php">
                            <i class="bi bi-clipboard-check"></i> Form Filler Panel
                        </a>
                    </li>
                <?php endif; ?>
                
                <!-- QA Links -->
                <?php if ($canPerm('qa.access')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($currentPage, ['qa-dashboard.php', 'qa-assign.php', 'leads-edit.php']) ? 'active' : ''; ?>" href="#" id="qaDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-shield-check"></i> Quality Assurance
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="qaDropdown">
                            <li><a class="dropdown-item <?php echo $currentPage == 'qa-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/qa/dashboard">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a></li>
                            <?php if ($canPerm('qa.access')): ?>
                                <li><a class="dropdown-item <?php echo (strpos($uriPath, '/modules/qa/audit') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/qa/audit">
                                    <i class="bi bi-list-check"></i> Audit Queue
                                </a></li>
                            <?php endif; ?>
                            <?php if ($canPerm('qa.assignments')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'qa-assign.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/qa/assignments">
                                    <i class="bi bi-person-check"></i> Assignments
                                </a></li>
                            <?php endif; ?>
                            <?php if ($canPerm('leads.view') || $canPerm('leads.manage')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'leads-edit.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/leads/list">
                                    <i class="bi bi-clipboard-data"></i> All Leads
                                </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
                
                <!-- Admin Links -->
                <?php if ($canPerm('admin.settings') || $canPerm('users.internal.manage') || $canPerm('campaigns.manage') || $canPerm('forms.manage') || $canPerm('leads.manage')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($currentPage, ['leads-edit.php', 'export.php']) ? 'active' : ''; ?>" href="#" id="leadDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-people"></i> Lead Management
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="leadDropdown">
                            <?php if ($canPerm('leads.manage')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'leads-edit.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/leads/list">
                                    <i class="bi bi-pencil-square"></i> Manage Leads
                                </a></li>
                            <?php endif; ?>
                            <?php if ($canPerm('leads.bulk_upload')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'bulk-upload.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/leads/bulk">
                                    <i class="bi bi-upload"></i> Bulk Upload
                                </a></li>
                            <?php endif; ?>
                            <?php if ($canPerm('leads.export')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'export.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/leads/export.php">
                                    <i class="bi bi-download"></i> Export Data
                                </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($currentPage, ['campaigns-manage.php', 'manage-users.php', 'reset-password.php', 'productivity-admin.php', 'forms-manage.php']) ? 'active' : ''; ?>" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i> Operations
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                            <?php if ($canPerm('campaigns.view') || $canPerm('campaigns.manage')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'campaigns-manage.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/campaigns/list">
                                    <i class="bi bi-megaphone"></i> Campaigns
                                </a></li>
                            <?php endif; ?>
                            <?php if ($canPerm('forms.manage')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'forms-manage.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/forms/forms-manage.php">
                                    <i class="bi bi-ui-checks-grid"></i> Forms
                                </a></li>
                            <?php endif; ?>
                            <?php if ($canPerm('users.internal.manage')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'manage-users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/users/manage-users.php">
                                    <i class="bi bi-person-gear"></i> Manage Users
                                </a></li>
                            <?php endif; ?>
                            <?php if ($canPerm('admin.settings')): ?>
                                <li><a class="dropdown-item <?php echo $currentPage == 'reset-password.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($navbarModules); ?>/auth/reset-password.php">
                                    <i class="bi bi-key"></i> Reset Passwords
                                </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="navbar-nav me-3">
                <a href="<?php echo htmlspecialchars($navbarModules); ?>/users/profile.php" class="nav-link d-flex align-items-center gap-2 py-0">
                    <?php if (!empty($navbarUser['profile_pic'])): ?>
                        <img src="<?php echo htmlspecialchars($navbarBase . '/' . ltrim((string)$navbarUser['profile_pic'], '/')); ?>" style="width: 32px; height: 32px; border-radius: 8px; object-fit: cover; border: 2px solid rgba(255,255,255,0.2);">
                    <?php else: ?>
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem;">
                            <?php echo strtoupper(substr($navbarUser['full_name'] ?? 'U', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="d-none d-lg-block">
                        <div class="small fw-bold" style="line-height: 1;"><?php echo htmlspecialchars($navbarUser['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.65rem; opacity: 0.8;"><?php echo htmlspecialchars(getRoleLabelFull((string)($navbarUser['role'] ?? ''))); ?></div>
                    </div>
                </a>
            </div>
            <a href="<?php echo htmlspecialchars($navbarModules); ?>/auth/logout.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>
</nav>

