<nav class="sidebar-nav">
  <?php $sidebarUser = function_exists('getCurrentUser') ? getCurrentUser() : []; ?>
  <?php $sidebarVendorId = (int)($sidebarUser['vendor_id'] ?? 0); ?>
  <?php $sidebarClientId = (int)($sidebarUser['client_id'] ?? 0); ?>
  <?php $layoutPage = isset($layoutPage) && is_string($layoutPage) && $layoutPage !== '' ? $layoutPage : basename($_SERVER['PHP_SELF'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')); ?>
  <?php $m = isset($layoutModulesBase) ? (string)$layoutModulesBase : (appBasePath() . '/modules'); ?>
  <?php $isInternal = !(function_exists('isVendor') && isVendor()) && !(function_exists('isClient') && isClient()); ?>
  <?php $dash = isset($layoutDashUrl) ? (string)$layoutDashUrl : ($m . '/dashboard/admin-dashboard'); ?>
  <?php $permEnabled = function_exists('getAccessRolePermissionsConfig') && getAccessRolePermissionsConfig() !== null; ?>
  <?php $canPerm = function(string $p) use ($permEnabled): bool { return !$permEnabled || (function_exists('userHasPermission') && userHasPermission($p)); }; ?>
  <?php $canAny = function(array $ps) use ($canPerm): bool { foreach ($ps as $p) { if ($canPerm($p)) return true; } return false; }; ?>
  <?php $canPage = function(string $pagePerm, array $fallback = []) use ($canAny): bool { return $canAny(array_merge([$pagePerm], $fallback)); }; ?>
  <?php $canNav = function(string $menuKey): bool { return function_exists('userCanAccessNavigationMenu') ? userCanAccessNavigationMenu($menuKey) : false; }; ?>
  <?php $canNavItem = function(string $menuKey, string $itemKey) use ($canAny): bool { return function_exists('userCanAccessNavigationItem') ? userCanAccessNavigationItem($menuKey, $itemKey) : false; }; ?>
  <?php $uriPath = strtolower((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)); ?>
  <?php $pathHas = function(string $needle) use ($uriPath): bool { return $needle !== '' && strpos($uriPath, strtolower($needle)) !== false; }; ?>

  <div class="sidebar-section">Dashboard</div>
  <?php if ($canNav('dashboard')): ?>
    <a class="sidebar-link <?php echo in_array($layoutPage, ['admin-dashboard.php','operations-dashboard.php','qa-dashboard.php','email-marketing-dashboard.php','sales-dashboard.php','vendor-dashboard.php','client-dashboard.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($dash); ?>">
      <i class="bi bi-speedometer2"></i><span>Dashboard</span>
    </a>
  <?php endif; ?>
  <?php if ($canNav('analytics')): ?>
    <?php $analyticsActive = in_array($layoutPage, ['campaigns.php','agents.php','sales.php','dbms.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $analyticsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navAnalytics" role="button" aria-expanded="<?php echo $analyticsActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-graph-up-arrow"></i><span>Analytics</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $analyticsActive ? 'show' : ''; ?>" id="navAnalytics">
      <?php if ($canNavItem('analytics', 'campaigns')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'campaigns.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/analytics/campaigns"><i class="bi bi-megaphone"></i><span>Campaigns</span></a><?php endif; ?>
      <?php if ($canNavItem('analytics', 'agents')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'agents.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/analytics/agents"><i class="bi bi-people"></i><span>Agents</span></a><?php endif; ?>
      <?php if ($canNavItem('analytics', 'sales')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'sales.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/analytics/sales"><i class="bi bi-funnel"></i><span>Sales</span></a><?php endif; ?>
      <?php if ($canNavItem('analytics', 'dbms')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'dbms.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/analytics/dbms"><i class="bi bi-database-check"></i><span>DBMS</span></a><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('vendor_portal')): ?>
    
    <?php if ($canNavItem('vendor_portal', 'add_lead')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'agent.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/entry"><i class="bi bi-plus-circle"></i><span>Add Lead</span></a>
    <?php endif; ?>
    <?php if ($canNavItem('vendor_portal', 'my_leads')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'my-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/my"><i class="bi bi-list-check"></i><span>My Leads</span></a>
    <?php endif; ?>
    <?php if ($canNavItem('vendor_portal', 'campaigns')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-campaigns.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-campaigns"><i class="bi bi-megaphone"></i><span>Campaigns</span></a>
    <?php endif; ?>
    <?php if ($canNavItem('vendor_portal', 'leads')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-leads"><i class="bi bi-list-task"></i><span>Leads</span></a>
    <?php endif; ?>
    <?php if ($canNavItem('vendor_portal', 'revenue')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-revenue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-revenue"><i class="bi bi-coin"></i><span>Revenue</span></a>
    <?php endif; ?>
    <?php if ($canNavItem('vendor_portal', 'billing')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-billing.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-billing"><i class="bi bi-receipt"></i><span>Billing</span></a>
    <?php endif; ?>
  <?php elseif ($canNav('client_portal') || (function_exists('isClient') && isClient() && $sidebarClientId > 0)): ?>
    <div class="sidebar-section">Client</div>
    <?php
      $clientCoreAccess = (function_exists('isClient') && isClient() && $sidebarClientId > 0);
      $clientCanDashboard = $clientCoreAccess || $canNavItem('client_portal', 'dashboard') || $canAny(['dashboard.client.page', 'dashboard.client', 'campaigns.dashboard', 'campaigns.dashboard.page']);
      $clientCanCampaigns = $clientCoreAccess || $canNavItem('client_portal', 'campaigns') || $canAny(['campaigns.view', 'clients.campaigns.page']);
      $clientCanLeads = $clientCoreAccess || $canNavItem('client_portal', 'leads') || $canAny(['leads.view', 'clients.leads.page']);
      $clientCanBilling = $canNavItem('client_portal', 'billing') || $canAny(['clients.access', 'clients.billing.page']);
      $clientCanUsers = ($sidebarClientId > 0) && ($canNavItem('client_portal', 'users') || $canNavItem('system', 'client_users') || $canAny(['clients.users.manage', 'clients.users.page']));
      $clientDashboardActive = $layoutPage === 'client-dashboard.php' || $pathHas('/modules/dashboard/client-dashboard');
      $clientCampaignListActive = in_array($layoutPage, ['client-campaigns.php', 'client-campaign-details.php'], true)
        || $pathHas('/modules/clients/client-campaigns')
        || $pathHas('/modules/clients/client-campaign-details');
      $clientLeadsListActive = in_array($layoutPage, ['client-leads.php', 'client-lead-details.php'], true)
        || $pathHas('/modules/clients/client-leads')
        || $pathHas('/modules/clients/client-lead-details');
      $clientUsersActive = $layoutPage === 'client-users.php' || $pathHas('/modules/clients/client-users');
      $clientBillingActive = $layoutPage === 'client-billing.php' || $pathHas('/modules/clients/client-billing');
      $clientCampaignActive = $clientDashboardActive || $clientCampaignListActive;
      $clientLeadsActive = $clientLeadsListActive;
      $clientSystemActive = $clientUsersActive || $clientBillingActive;
      $clientCampaignOpen = $clientCampaignActive;
      $clientLeadsOpen = $clientLeadsActive;
      $clientSystemOpen = $clientSystemActive;
    ?>
    <?php if ($clientCanDashboard || $clientCanCampaigns): ?>
      <a class="sidebar-link sidebar-toggle <?php echo $clientCampaignActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navClientCampaigns" role="button" aria-expanded="<?php echo $clientCampaignOpen ? 'true' : 'false'; ?>">
        <span class="sidebar-title"><i class="bi bi-megaphone"></i><span>Campaigns</span></span>
        <i class="bi bi-chevron-down sidebar-chevron"></i>
      </a>
      <div class="collapse sidebar-submenu <?php echo $clientCampaignOpen ? 'show' : ''; ?>" id="navClientCampaigns">
        <?php if ($clientCanDashboard): ?>
          <a class="sidebar-sublink <?php echo $clientDashboardActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dashboard/client-dashboard"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <?php endif; ?>
        <?php if ($clientCanCampaigns): ?>
          <a class="sidebar-sublink <?php echo $clientCampaignListActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-campaigns"><i class="bi bi-list-ul"></i><span>View Campaigns</span></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($clientCanLeads): ?>
      <a class="sidebar-link sidebar-toggle <?php echo $clientLeadsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navClientLeads" role="button" aria-expanded="<?php echo $clientLeadsOpen ? 'true' : 'false'; ?>">
        <span class="sidebar-title"><i class="bi bi-list-task"></i><span>Leads</span></span>
        <i class="bi bi-chevron-down sidebar-chevron"></i>
      </a>
      <div class="collapse sidebar-submenu <?php echo $clientLeadsOpen ? 'show' : ''; ?>" id="navClientLeads">
        <a class="sidebar-sublink <?php echo $clientLeadsListActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-leads"><i class="bi bi-journal-text"></i><span>View Leads</span></a>
      </div>
    <?php endif; ?>
    <?php if ($clientCanUsers || $clientCanBilling): ?>
      <a class="sidebar-link sidebar-toggle <?php echo $clientSystemActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navClientSystem" role="button" aria-expanded="<?php echo $clientSystemOpen ? 'true' : 'false'; ?>">
        <span class="sidebar-title"><i class="bi bi-gear"></i><span>System</span></span>
        <i class="bi bi-chevron-down sidebar-chevron"></i>
      </a>
      <div class="collapse sidebar-submenu <?php echo $clientSystemOpen ? 'show' : ''; ?>" id="navClientSystem">
        <?php if ($clientCanUsers): ?>
          <a class="sidebar-sublink <?php echo $clientUsersActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-users?client_id=<?php echo (int)$sidebarClientId; ?>"><i class="bi bi-people"></i><span>Client Users</span></a>
        <?php endif; ?>
        <?php if ($clientCanBilling): ?>
          <a class="sidebar-sublink <?php echo $clientBillingActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-billing"><i class="bi bi-receipt"></i><span>Billing</span></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($canNav('sales')): ?>
    <?php $salesActive = in_array($layoutPage, ['sales-dashboard.php','dashboard.php','targets.php','leads.php','lead-create.php','accounts.php','account-create.php','manager-map.php','activity.php','lead-view.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $salesActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navSales" role="button" aria-expanded="<?php echo $salesActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-graph-up"></i><span>Sales</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $salesActive ? 'show' : ''; ?>" id="navSales">
      <?php if ($canNavItem('sales', 'dashboard')): ?><a class="sidebar-sublink <?php echo in_array($layoutPage, ['sales-dashboard.php','dashboard.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dashboard/sales-dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a><?php endif; ?>
      <?php if ((isAdmin() || isSalesDirector()) && $canNavItem('sales', 'targets')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'targets.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/targets"><i class="bi bi-bullseye"></i><span>Targets</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('sales', 'prospects')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/leads"><i class="bi bi-funnel"></i><span>Prospects</span></a><?php endif; ?>
      <?php if ($canNavItem('sales', 'create')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'lead-create.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/lead-create"><i class="bi bi-plus-circle"></i><span>Add Prospect</span></a><?php endif; ?>
      <?php if ($canNavItem('sales', 'accounts')): ?><a class="sidebar-sublink <?php echo in_array($layoutPage, ['accounts.php','account-create.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/accounts"><i class="bi bi-building"></i><span>Accounts</span></a><?php endif; ?>
      <?php if (isSalesDirector() && $canNavItem('sales', 'map')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'manager-map.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/manager-map"><i class="bi bi-diagram-3"></i><span>Manager ↔ SDR</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('directories')): ?>
    <?php $dirsActive = in_array($layoutPage, ['clients.php','vendors.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $dirsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navDirectories" role="button" aria-expanded="<?php echo $dirsActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-building"></i><span>Directories</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $dirsActive ? 'show' : ''; ?>" id="navDirectories">
      <?php if ($canNavItem('directories', 'clients')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'clients.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/clients"><i class="bi bi-building-check"></i><span>Clients</span></a><?php endif; ?>
      <?php if ($canNavItem('directories', 'vendors')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'vendors.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendors"><i class="bi bi-truck"></i><span>Vendors</span></a><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('campaigns')): ?>
    <?php $campActive = in_array($layoutPage, ['campaign-dashboard.php','campaigns-manage.php','campaign-create.php','campaign-delivery.php','campaign-edit.php','campaign-details.php','campaign-leads.php','forms-manage.php','form-edit.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $campActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navCampaigns" role="button" aria-expanded="<?php echo $campActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-megaphone"></i><span>Campaigns</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $campActive ? 'show' : ''; ?>" id="navCampaigns">
      <?php if ($canNavItem('campaigns', 'dashboard')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage  === 'campaign-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('campaigns', 'list')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaigns-manage.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/list?status=Live"><i class="bi bi-list-ul"></i><span>View Campaigns</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('campaigns', 'create')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaign-create.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/create"><i class="bi bi-plus-square"></i><span>Create Campaign</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('campaigns', 'allocation')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaign-allocation.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/allocation"><i class="bi bi-bullseye"></i><span>Campaign Allocation</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('campaigns', 'files')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaign-delivery.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/files"><i class="bi bi-folder2-open"></i><span>Campaign Files</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('campaigns', 'forms')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'forms-manage.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/forms/forms-manage"><i class="bi bi-ui-checks-grid"></i><span>Lead Forms</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('leads')): ?>
    <?php
      $leadsMode = (string)($_GET['mode'] ?? '');
      $manageLeadsActive = ($layoutPage === 'leads-edit.php' && $leadsMode !== 'vendor');
      $vendorLeadsActive = ($layoutPage === 'leads-edit.php' && $leadsMode === 'vendor') || $pathHas('/modules/leads/vendors');
      $leadsActive = in_array($layoutPage, ['operations-assign.php','agent.php','my-leads.php','form-filler.php','email-leads.php','bulk-upload.php','leads-edit.php','lead-details.php','get_lead_details.php', 'export.php'], true)
        || $vendorLeadsActive;
    ?>
    <a class="sidebar-link sidebar-toggle <?php echo $leadsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navLeads" role="button" aria-expanded="<?php echo $leadsActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-journal-text"></i><span>Leads</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $leadsActive ? 'show' : ''; ?>" id="navLeads">
      <?php if ($canNavItem('leads', 'entry')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'agent.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/entry"><i class="bi bi-keyboard"></i><span>Lead Entry</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('leads', 'my')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'my-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/my"><i class="bi bi-list-check"></i><span>My Leads</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('leads', 'marketing')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'form-filler.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/marketing"><i class="bi bi-pencil-square"></i><span>Marketing Entry</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('leads', 'email')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'email-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/email"><i class="bi bi-envelope-at"></i><span>Email Leads</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('leads', 'bulk')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'bulk-upload.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/bulk"><i class="bi bi-upload"></i><span>Bulk Upload</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('leads', 'manage')): ?>
        <a class="sidebar-sublink <?php echo $manageLeadsActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/list"><i class="bi bi-clipboard-data"></i><span>Manage Leads</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('leads', 'vendor')): ?>
        <a class="sidebar-sublink <?php echo $vendorLeadsActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/vendors"><i class="bi bi-truck"></i><span>Vendor Leads</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('leads', 'export')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'export.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/export"><i class="bi bi-file-earmark-arrow-down"></i><span>Export</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('tasks')): ?>
    <?php $tasksActive = (strpos($uriPath, '/modules/tasks') !== false); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $tasksActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navTasks" role="button" aria-expanded="<?php echo $tasksActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-kanban"></i><span>Tasks</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $tasksActive ? 'show' : ''; ?>" id="navTasks">
      <?php if ($canNavItem('tasks', 'dashboard')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/tasks') !== false && !str_contains($uriPath, '/modules/tasks/create') && !str_contains($uriPath, '/modules/tasks/reports') && !str_contains($uriPath, '/modules/tasks/view')) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/tasks"><i class="bi bi-list-task"></i><span>Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('tasks', 'create')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/tasks/create') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/tasks/create"><i class="bi bi-plus-circle"></i><span>Create Task</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('tasks', 'reports')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/tasks/reports') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/tasks/reports"><i class="bi bi-bar-chart"></i><span>Reports</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('performance')): ?>
    <?php $ftActive = (strpos($uriPath, '/modules/performance') !== false) || (strpos($uriPath, '/modules/funneltrack360') !== false); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $ftActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navFunneltrack" role="button" aria-expanded="<?php echo $ftActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-graph-up"></i><span>Performance</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $ftActive ? 'show' : ''; ?>" id="navFunneltrack">
      <?php if ($canNavItem('performance', 'dashboard')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/performance') !== false && !str_contains($uriPath, '/modules/performance/detail') && !str_contains($uriPath, '/modules/performance/team') && !str_contains($uriPath, '/modules/performance/reports') && !str_contains($uriPath, '/modules/performance/admin')) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/performance"><i class="bi bi-speedometer2"></i><span>My Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('performance', 'detail')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/performance/detail') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/performance/detail"><i class="bi bi-table"></i><span>Daily Detail</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('performance', 'team')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/performance/team') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/performance/team"><i class="bi bi-people"></i><span>Team View</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('performance', 'reports')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/performance/reports') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/performance/reports"><i class="bi bi-file-earmark-bar-graph"></i><span>Reports</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('performance', 'admin')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/performance/admin') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/performance/admin"><i class="bi bi-gear"></i><span>Admin</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('dbms')): ?>
    <?php $dbmsActive = (strpos($uriPath, '/modules/dbms') !== false); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $dbmsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navDbms" role="button" aria-expanded="<?php echo $dbmsActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-database"></i><span>DBMS</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $dbmsActive ? 'show' : ''; ?>" id="navDbms">
      <?php if ($canNavItem('dbms', 'dashboard')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms') !== false && !str_contains($uriPath, '/modules/dbms/upload') && !str_contains($uriPath, '/modules/dbms/explorer') && !str_contains($uriPath, '/modules/dbms/assignments') && !str_contains($uriPath, '/modules/dbms/my') && !str_contains($uriPath, '/modules/dbms/research') && !str_contains($uriPath, '/modules/dbms/cdqa') && !str_contains($uriPath, '/modules/dbms/view') && !str_contains($uriPath, '/modules/dbms/batch') && !str_contains($uriPath, '/modules/dbms/export')) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('dbms', 'upload')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms/upload') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms/upload"><i class="bi bi-cloud-arrow-up"></i><span>Upload</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('dbms', 'explorer')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms/explorer') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms/explorer"><i class="bi bi-search"></i><span>Explorer</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('dbms', 'assignments')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms/assignments') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms/assignments"><i class="bi bi-person-check"></i><span>Assignments</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('dbms', 'my')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms/my') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms/my"><i class="bi bi-person-lines-fill"></i><span>My Records</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('dbms', 'research')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms/research') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms/research"><i class="bi bi-journal-text"></i><span>Research Queue</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('dbms', 'cdqa')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms/cdqa') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms/cdqa"><i class="bi bi-shield-check"></i><span>CDQA Queue</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('dbms', 'batches')): ?>
        <a class="sidebar-sublink <?php echo (strpos($uriPath, '/modules/dbms/batch') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dbms/batch"><i class="bi bi-bar-chart"></i><span>Batches</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('qa')): ?>
    <?php $qaActive = in_array($layoutPage, ['dashboard.php','audit.php','request-assignment.php','assignments.php','qa-dashboard.php','qa-request-assignment.php','qa-assign.php','dashboard','audit','request','assignments'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $qaActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navQA" role="button" aria-expanded="<?php echo $qaActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-shield-check"></i><span>QA</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $qaActive ? 'show' : ''; ?>" id="navQA">
      <?php if ($canNavItem('qa', 'dashboard')): ?><a class="sidebar-sublink <?php echo in_array($layoutPage, ['qa-dashboard.php','dashboard'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a><?php endif; ?>
      <?php if ($canNavItem('qa', 'audit')): ?><a class="sidebar-sublink <?php echo in_array($layoutPage, ['audit.php','audit'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/audit"><i class="bi bi-list-check"></i><span>Audit Queue</span></a><?php endif; ?>
      <?php if (isQA() && !isAdmin() && $canNavItem('qa', 'request')): ?>
        <a class="sidebar-sublink <?php echo in_array($layoutPage, ['qa-request-assignment.php','request'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/request"><i class="bi bi-send"></i><span>Request Assignment</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('qa', 'assignments')): ?>
        <a class="sidebar-sublink <?php echo in_array($layoutPage, ['qa-assign.php','assignments'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/assignments"><i class="bi bi-person-check"></i><span>Assignments</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('qa', 'export')): ?>
        <a class="sidebar-sublink" href="<?php echo htmlspecialchars($m); ?>/leads/export"><i class="bi bi-file-earmark-arrow-down"></i><span>Export</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('hr')): ?>
    <?php $hrActive = in_array($layoutPage, ['hr-dashboard.php','attendance.php','paid-leaves.php','payslips.php','attendance-admin.php','attendance-export.php','attendance-monthly-report.php','attendance-monthly-export.php','shifts.php','salary-setup.php','bonus-loans.php','payroll.php','payroll-export.php','payslip-view.php','payslip-pdf.php','break-automation.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $hrActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navHR" role="button" aria-expanded="<?php echo $hrActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-person-badge"></i><span>HR & Payroll</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $hrActive ? 'show' : ''; ?>" id="navHR">
      <?php if ($canNavItem('hr', 'dashboard')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'hr-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/hr-dashboard"><i class="bi bi-columns-gap"></i><span>Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'attendance')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'attendance.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/attendance"><i class="bi bi-fingerprint"></i><span>Attendance</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'leaves')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'paid-leaves.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/paid-leaves"><i class="bi bi-calendar2-week"></i><span>Paid Leaves</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'payslips')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'payslips.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/payslips"><i class="bi bi-receipt"></i><span>Payslips</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'attendance_admin')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'attendance-admin.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/attendance-admin"><i class="bi bi-calendar2-check"></i><span>Attendance Admin</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'shifts')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'shifts.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/shifts"><i class="bi bi-clock-history"></i><span>Shifts</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'salary_setup')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'salary-setup.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/salary-setup"><i class="bi bi-wallet2"></i><span>Salary Setup</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'bonus_loans')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'bonus-loans.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/bonus-loans"><i class="bi bi-gift"></i><span>Bonus & Loans</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'payroll')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'payroll.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/payroll"><i class="bi bi-calculator"></i><span>Payroll</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('hr', 'break_automation')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'break-automation.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/break-automation"><i class="bi bi-clock-history"></i><span>Break Automation</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('revenue')) : ?>
    <?php $revActive = in_array($layoutPage, ['revenue-dashboard.php','revenue.php','expenses.php','invoices.php','invoice-edit.php','billto-templates.php','agent-roi.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $revActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navRevenue" role="button" aria-expanded="<?php echo $revActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-cash-coin"></i><span>Revenue</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $revActive ? 'show' : ''; ?>" id="navRevenue">
      <?php if ($canNavItem('revenue', 'dashboard')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'revenue-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/revenue-dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a><?php endif; ?>
      <?php if ($canNavItem('revenue', 'campaign')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'revenue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/revenue"><i class="bi bi-currency-dollar"></i><span>Campaign Revenue</span></a><?php endif; ?>
      <?php if ($canNavItem('revenue', 'expenses')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'expenses.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/expenses"><i class="bi bi-receipt-cutoff"></i><span>Expenses</span></a><?php endif; ?>
      <?php if ($canNavItem('revenue', 'invoices')): ?><a class="sidebar-sublink <?php echo in_array($layoutPage, ['invoices.php','invoice-edit.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/invoices"><i class="bi bi-file-earmark-text"></i><span>Invoices</span></a><?php endif; ?>
      <?php if ($canNavItem('revenue', 'billto')): ?><a class="sidebar-sublink <?php echo $layoutPage === 'billto-templates.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/billto-templates"><i class="bi bi-journal-bookmark"></i><span>Bill To Templates</span></a><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($canNav('communication')): ?>
    <div class="sidebar-section">Communication</div>
    <a class="sidebar-link <?php echo $layoutPage === 'chat.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/chat/chat"><i class="bi bi-chat-dots"></i><span>Chat</span></a>
  <?php endif; ?>

  <?php if ($canNav('system')) : ?>
    <?php $sysActive = in_array($layoutPage, ['manage-users.php','vendor-users.php','client-users.php','db-diagnostics.php','team-management.php','settings.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $sysActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navSystem" role="button" aria-expanded="<?php echo $sysActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-gear"></i><span>System</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $sysActive ? 'show' : ''; ?>" id="navSystem">
      <?php if ($canNavItem('system', 'internal_users')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'manage-users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/users/manage-users"><i class="bi bi-people"></i><span>Internal Users</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('system', 'teams')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'team-management.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/team-management"><i class="bi bi-diagram-3"></i><span>Teams</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('system', 'settings')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'settings.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/settings"><i class="bi bi-sliders"></i><span>Settings</span></a>
      <?php endif; ?>
      <?php if ($sidebarVendorId > 0 && $canNavItem('system', 'vendor_users')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'vendor-users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-users?vendor_id=<?php echo (int)$sidebarVendorId; ?>"><i class="bi bi-people"></i><span>Vendor Users</span></a>
      <?php endif; ?>
      <?php if ($sidebarClientId > 0 && $canNavItem('system', 'client_users')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'client-users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-users?client_id=<?php echo (int)$sidebarClientId; ?>"><i class="bi bi-people"></i><span>Client Users</span></a>
      <?php endif; ?>
      <?php if ($canNavItem('system', 'diagnostics')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'db-diagnostics.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/db-diagnostics"><i class="bi bi-clipboard-pulse"></i><span>Diagnostics</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</nav>
