# Access Management Design Document

## Scope

This document is the Phase 1 and Phase 2 discovery output for redesigning CRM access management from an architectural perspective. It is based on the current live codebase in `c:\xampp\htdocs\leads` and focuses on the actual system structure discovered from:

- `modules/*`
- `includes/auth.php`
- `includes/layout/sidebar.php`
- `modules/admin/access-management.php`
- representative action and endpoint files such as `modules/tasks/api.php`, `modules/qa/action.php`, and `modules/files/download.php`

This document is intended to become the single source of truth for the next RBAC redesign and refactor.

## Discovery Summary

### Current Access Model

The current CRM uses a mixed access model:

- Role normalization and role helpers live in `includes/auth.php`.
- Base and custom permissions are stored as JSON in `app_settings`.
- Page access is inferred from request paths by `inferPermissionKeyForRequest()` in `includes/auth.php`.
- Menu visibility is driven by a mix of role checks and permission checks in `includes/layout/sidebar.php`.
- Hidden endpoints are protected inconsistently:
  - some use `requirePermissionOrRole()`
  - some use `requireRole()`
  - some use only `requireLogin()` and then inline business checks
- Action-level authorization is often implemented inline inside page controllers and AJAX endpoints.

### Current Permission Storage

Access settings are stored in the `app_settings` table through `getAppSetting()` and `setAppSetting()` in `includes/functions.php`.

Observed keys:

- `access.role_permissions`
- `access.custom_roles`
- `access.custom_permissions`
- `security.ip_access.enabled`
- `security.ip_access.bypass_roles`
- `security.ip_access.trust_xff`
- `security.ip_access.allow_localhost_bypass`
- `sales.fx_sync_scheduler_token`

Runtime support tables discovered from code:

- `app_settings`
- `user_ip_access`
- `user_sessions`

No standalone schema dump or migration folder was found in the repository. The application relies heavily on runtime schema helpers such as `ensureAppSettingsSchema()` and other `ensure*Schema()` functions.

## Discovered Roles

### Canonical and Active Role Families

Roles currently discovered in `getKnownRoles()` and role labeling helpers:

- `admin`
- `director`
- `manager_director`
- `operations_director`
- `operations_manager`
- `operations_agent`
- `email_marketing_director`
- `email_marketing_manager`
- `email_marketing_agent`
- `qa_director`
- `qa_manager`
- `qa_agent`
- `sales_director`
- `sales_manager`
- `sdr`
- `client_admin`
- `client_sdr`
- `vendor_admin`
- `vendor_user`

### Legacy Alias Roles Still Present In Code

The following aliases are still visible in the current codebase and create overlap risk:

- `agent` -> normalizes to `operations_agent`
- `email_marketing_executive` -> normalizes to `email_marketing_agent`
- `form_filler` -> normalizes to `email_marketing_agent`
- `qa` -> normalizes to `qa_agent`

These aliases are one of the main causes of permission overlap and inheritance confusion.

## Discovered Modules

### Admin

Pages and sub-pages discovered:

- `modules/admin/access-management.php`
- `modules/admin/settings.php`
- `modules/admin/team-management.php`
- `modules/admin/db-diagnostics.php`
- `modules/admin/data-reset.php`
- `modules/admin/setup.php`
- `modules/admin/analytics/campaigns.php`
- `modules/admin/analytics/agents.php`
- `modules/admin/analytics/sales.php`
- `modules/admin/analytics/dbms.php`

Primary operations discovered:

- save access settings
- reset defaults
- add, edit, delete custom roles
- add, delete custom permissions
- import access JSON
- analytics navigation and cross-module drilldowns

### Authentication

Pages discovered:

- `modules/auth/login.php`
- `modules/auth/logout.php`
- `modules/auth/register.php`
- `modules/auth/reset-password.php`
- `modules/auth/change-password.php`
- `modules/auth/access-denied.php`
- `modules/auth/ip-restricted.php`

Primary operations:

- login
- logout
- password reset
- access denied handling
- IP restriction enforcement

### Dashboard

Pages discovered:

- `modules/dashboard/admin-dashboard.php`
- `modules/dashboard/operations-dashboard.php`
- `modules/dashboard/agent-dashboard.php`
- `modules/dashboard/qa-dashboard.php`
- `modules/dashboard/sales-dashboard.php`
- `modules/dashboard/client-dashboard.php`
- `modules/dashboard/vendor-dashboard.php`
- `modules/dashboard/email-marketing-dashboard.php`
- `modules/dashboard/form-filler-dashboard.php`
### Campaigns

Pages discovered:

- `modules/campaigns/campaign-dashboard.php`
- `modules/campaigns/campaigns-manage.php`
- `modules/campaigns/campaign-create.php`
- `modules/campaigns/campaign-edit.php`
- `modules/campaigns/campaign-details.php`
- `modules/campaigns/campaign-leads.php`
- `modules/campaigns/campaign-allocation.php`
- `modules/campaigns/campaign-delivery.php`
- `modules/campaigns/campaign-assign-sdr.php`
- `modules/campaigns/campaign-form-options-source.php`
- `modules/campaigns/campaign-delete.php`
- `modules/campaigns/campaigns-export.php`

Observed operations:

- create campaign
- edit campaign
- delete campaign
- update campaign status
- assign vendor
- remove vendor assignment
- assign SDR
- list campaign leads
- upload delivery file
- export campaigns
- manage notes and note attachments
- manage forms

### Leads

Pages discovered:

- `modules/leads/agent.php`
- `modules/leads/my-leads.php`
- `modules/leads/form-filler.php`
- `modules/leads/email-leads.php`
- `modules/leads/bulk-upload.php`
- `modules/leads/leads-edit.php`
- `modules/leads/lead-details.php`
- `modules/leads/lead-tracking.php`
- `modules/leads/export.php`
- `modules/leads/get_lead_details.php`
- `modules/leads/check_duplicates.php`
- `modules/leads/leads-purge.php`

Observed operations:

- add lead
- update lead
- edit email lead
- bulk upload
- delete lead
- export
- play recordings
- fetch lead details
- view audit history
- purge leads

### QA

Pages discovered:

- `modules/qa/dashboard.php`
- `modules/qa/audit.php`
- `modules/qa/assignments.php`
- `modules/qa/request-assignment.php`
- `modules/qa/action.php`

Observed operations:

- update QA status
- assign QA workload
- request assignment
- trigger rework task creation

### Sales

Pages discovered:

- `modules/sales/dashboard.php`
- `modules/sales/leads.php`
- `modules/sales/lead-create.php`
- `modules/sales/lead-view.php`
- `modules/sales/activity.php`
- `modules/sales/accounts.php`
- `modules/sales/account-create.php`
- `modules/sales/manager-map.php`
- `modules/sales/targets.php`
- `modules/sales/check-duplicate.php`
- `modules/sales/fx-sync-cron.php`

Observed operations:

- create prospect
- update prospect
- convert to client
- add and edit sales activity
- assign client to account
- manage manager-SDR mapping
- save targets
- duplicate checks
- scheduled FX sync

### Revenue

Pages discovered:

- `modules/revenue/revenue-dashboard.php`
- `modules/revenue/revenue.php`
- `modules/revenue/expenses.php`
- `modules/revenue/invoices.php`
- `modules/revenue/invoice-edit.php`
- `modules/revenue/invoice-view.php`
- `modules/revenue/invoice-pdf.php`
- `modules/revenue/billto-templates.php`
- `modules/revenue/agent-roi.php`

Observed operations:

- save revenue
- add, edit, delete expense
- create invoice from campaign
- copy invoice
- update invoice status
- delete invoice
- bulk delete
- bulk copy
- bulk status update
- resync commission
- generate PDF

### HR And Payroll

Pages discovered:

- `modules/hr/hr-dashboard.php`
- `modules/hr/attendance.php`
- `modules/hr/attendance-admin.php`
- `modules/hr/attendance-export.php`
- `modules/hr/attendance-monthly-report.php`
- `modules/hr/attendance-monthly-export.php`
- `modules/hr/paid-leaves.php`
- `modules/hr/payslips.php`
- `modules/hr/payslip-view.php`
- `modules/hr/payslip-pdf.php`
- `modules/hr/shifts.php`
- `modules/hr/salary-setup.php`
- `modules/hr/bonus-loans.php`
- `modules/hr/payroll.php`
- `modules/hr/payroll-export.php`

Observed operations:

- mark attendance
- export attendance
- admin attendance override
- payroll run
- payroll export
- payslip view and PDF
- shift management
- salary setup
- bonus and loan management

### Tasks

Pages and endpoints discovered:

- `modules/tasks/tasks.php`
- `modules/tasks/task-create.php`
- `modules/tasks/task-view.php`
- `modules/tasks/reports.php`
- `modules/tasks/api.php`
- `modules/tasks/deadline-run.php`

Observed operations from `tasks/api.php` and related files:

- update status
- update progress
- toggle checklist
- add checklist item
- add comment
- time event
- reassign
- upload task files
- scheduled deadline processing

### Performance

Pages discovered:

- `modules/performance/dashboard.php`
- `modules/performance/team.php`
- `modules/performance/reports.php`
- `modules/performance/admin.php`

Observed operations:

- save config
- assign target
- update target
- add escalation
- update escalation
- deactivate escalation
- save override

### DBMS

Pages discovered:

- `modules/dbms/dashboard.php`
- `modules/dbms/upload.php`
- `modules/dbms/explorer.php`
- `modules/dbms/assignments.php`
- `modules/dbms/my.php`
- `modules/dbms/research.php`
- `modules/dbms/cdqa.php`
- `modules/dbms/view.php`
- `modules/dbms/export.php`
- `modules/dbms/batch.php`

Observed operations:

- upload DBMS batch
- export DBMS records
- assign DBMS records
- research queue actioning
- approve and reject back from CDQA
- record view
- batch file download

### Directories And External Parties

Client pages:

- `modules/clients/clients.php`
- `modules/clients/client-users.php`
- `modules/clients/client-campaigns.php`
- `modules/clients/client-campaign-details.php`
- `modules/clients/client-leads.php`
- `modules/clients/client-lead-details.php`
- `modules/clients/client-billing.php`

Vendor pages:

- `modules/vendors/vendors.php`
- `modules/vendors/vendor-users.php`
- `modules/vendors/vendor-campaigns.php`
- `modules/vendors/vendor-campaign-details.php`
- `modules/vendors/vendor-leads.php`
- `modules/vendors/vendor-revenue.php`
- `modules/vendors/vendor-billing.php`

### Forms

Pages and endpoints:

- `modules/forms/forms-manage.php`
- `modules/forms/form-edit.php`
- `modules/forms/get_campaign_form.php`

### Chat

Pages and endpoints:

- `modules/chat/chat.php`
- `modules/chat/chat-send.php`
- `modules/chat/chat-fetch.php`
- `modules/chat/chat-list.php`
- `modules/chat/chat-list-users.php`
- `modules/chat/chat-groups.php`
- `modules/chat/chat-group-members.php`
- `modules/chat/chat-presence.php`
- `modules/chat/chat-delete.php`

Observed operations:

- send message
- react to message
- fetch threads
- create group
- add member
- remove member
- set member role
- delete chat item

### Notifications

Pages and endpoints:

- `modules/notifications/notifications.php`
- `modules/notifications/preferences.php`
- `modules/notifications/poll.php`
- `modules/notifications/mark-read.php`
- `modules/notifications/mark-read-ajax.php`
- `modules/notifications/digest-run.php`

### Files

Endpoints:

- `modules/files/download.php`

Observed download kinds:

- `bulk_report`
- `dbms_batch_file`
- `task_file`
- `lead_file`
- `chat_attachment`

## Menu And Navigation Structure

The current sidebar in `includes/layout/sidebar.php` exposes the following top-level sections:

- Dashboard
- Analytics
- Vendor
- Client
- Sales
- Directories
- Campaigns
- Leads
- Tasks
- Performance
- DBMS
- QA
- HR & Payroll
- Revenue
- Communication
- System

Important architectural note:

- Menu visibility is not purely permission-driven.
- Several sections still combine direct role checks, convenience helpers, and permission checks.
- This creates a split between what the menu shows and what backend routes may still allow or deny.

## Current Permission Relationships

### Current Permission Families

Discovered base permission families in `modules/admin/access-management.php`:

- Dashboard
- Notifications
- Chat
- Users
- Leads
- Campaigns
- Forms
- QA
- Sales
- Clients
- Vendors
- HR
- Revenue
- Tasks
- Performance
- DBMS
- Admin

### Current Page Inference Model

The application currently infers required permission by route prefix, not by a formal route registry.

Examples from `inferPermissionKeyForRequest()`:

- any `modules/sales/*` route defaults to `sales.access`
- many `modules/campaigns/*` routes default to `campaigns.view`
- `create`, `edit`, `delete`, `allocation`, `assign-sdr`, and `options-source` inside campaigns are grouped under `campaigns.manage`
- all `modules/revenue/*` routes default to `revenue.access`
- all `modules/notifications/*` routes default to `notifications.access`

This makes permission behavior dependent on URL naming patterns.

## Conflict And Overlap Report

### Overlaps Discovered

1. Legacy alias roles overlap canonical roles.

- `agent`, `qa`, `form_filler`, and `email_marketing_executive` normalize into other roles.
- This causes permission duplication, ambiguous imports, and inconsistent reporting.

2. Role checks and permission checks are mixed in the same UI layer.

- `includes/layout/sidebar.php` uses both direct role checks and permission checks.
- This causes menu leakage and inconsistent visibility.

3. `requireRole()` is no longer truly role-based.

- It now derives a permission from the current request path.
- This means code that looks role-scoped is actually permission-scoped through heuristics.

4. Module-level permissions are overloaded to protect page-level and action-level behavior.

- Example: `sales.access` protects many different pages and operations.
- Example: `revenue.access` is too broad for a modern deny-by-default design.

5. Hidden endpoints do not share a single policy engine.

- `tasks/api.php` performs fine-grained checks inline.
- `files/download.php` contains resource-specific access logic.
- `qa/action.php` includes workflow-specific visibility checks.

6. Custom permissions are possible without a canonical resource model.

- `access.custom_permissions` allows arbitrary keys.
- No resource taxonomy enforces naming consistency or dependency rules.

7. Legacy helper `canAccess()` conflicts with the modern permission direction.

- It still uses direct role assumptions and ownership logic.
- It is not a clean fit for the current permission store.

## Missing Permission Report

### Missing Or Weakly Defined Control Areas

1. Button-level permissions

- Buttons are currently rendered inline in page templates.
- No authoritative registry exists for button actions such as delete, approve, assign, resync, or vendor assignment.

2. Action-level permissions

- Action authorization is scattered inside page controllers.
- There is no reusable action policy layer.

3. API and AJAX endpoint permissions

- Endpoints like `tasks/api.php`, `qa/action.php`, `notifications/mark-read-ajax.php`, and `chat-*` endpoints are not registered in a central manifest.

4. File upload and download permissions

- Upload and download rules are handled per file.
- There is no global registry of resource type, ownership rule, and required action permission.

5. Data visibility permissions

- Team-, campaign-, owner-, vendor-, and client-scoped visibility rules are implemented in business logic helpers, not in a formal policy layer.

6. Dashboard widget permissions

- Dashboard page access exists, but widget-level permissioning is not centrally defined.

7. Menu permissions

- Menu visibility is derived from mixed logic, not from an authoritative access manifest.

## Security Gap Report

### High-Risk Gaps

1. Heuristic route inference

- Access depends on URL matching inside `inferPermissionKeyForRequest()`.
- Renaming or adding a page can silently change access behavior.

2. No explicit deny-by-default registry

- A route without a formal manifest can still end up using broad module permission logic or ad hoc checks.

3. Frontend and backend are not generated from the same source

- Sidebar logic, page guards, button rendering, and endpoint checks are defined separately.

4. Business-scope access is not centralized

- Campaign visibility, ownership, task assignee checks, vendor/client access, and file access all live in different places.

5. Imports are shape-based, not dependency-aware

- The importer validates permission keys but does not enforce semantic dependencies like page/view/action relationships.

6. Custom permission sprawl

- New permissions can be added without attaching them to a resource, route, action, or data scope model.

## Target RBAC Architecture Blueprint

### Design Principles

The redesigned system should use:

- strict deny-by-default
- one authoritative permission registry
- one route and action manifest
- one UI visibility resolver
- one backend enforcement engine
- one policy layer for data visibility
- zero reliance on legacy alias roles

### Proposed Domain Model

#### 1. Principal Layer

- user
- role
- role group
- optional team
- optional department
- optional client scope
- optional vendor scope

#### 2. Resource Layer

Each protected object should be represented as a resource:

- module
- page
- action
- endpoint
- report
- export
- upload
- download
- widget
- menu item
- data resource

#### 3. Permission Layer

Permissions should follow a structured naming convention:

- `module.<module_key>.view`
- `page.<page_key>.view`
- `action.<resource_key>.<verb>`
- `endpoint.<endpoint_key>.execute`
- `report.<report_key>.view`
- `export.<export_key>.run`
- `import.<import_key>.run`
- `upload.<upload_key>.run`
- `download.<download_key>.run`
- `widget.<widget_key>.view`
- `menu.<menu_key>.view`
- `data.<resource_key>.<scope>`

#### 4. Data Scope Layer

Every sensitive data resource should support explicit scope rules:

- `own`
- `team`
- `department`
- `assigned`
- `campaign`
- `client`
- `vendor`
- `all`

#### 5. Policy Resolution Layer

A central policy engine should evaluate:

- principal roles
- granted permissions
- resource type
- requested action
- contextual scope
- ownership and assignment
- tenant constraints

## Standardized Permission Categories

The redesigned architecture must support these action classes automatically:

- view
- create
- edit
- delete
- approve
- reject
- assign
- reassign
- export
- import
- upload
- download
- manage
- configure
- run
- override
- sync

These should be generated from discovered resources instead of being manually invented per page.

## Proposed System Of Record

The redesign should introduce a generated access manifest, for example:

- `config/access/resources.php`
- `config/access/routes.php`
- `config/access/menus.php`
- `config/access/widgets.php`
- `config/access/actions.php`

Or a single normalized manifest compiled from scanner output.

That manifest should become the source for:

- access-management UI
- backend middleware
- sidebar/menu rendering
- button rendering helpers
- import/export validation
- audit tooling

## Refactor Strategy

### Phase 1

- freeze current access design into documentation and inventory
- map modules, routes, endpoints, actions, widgets, uploads, downloads, and menus

### Phase 2

- introduce a canonical access manifest
- remove alias roles from the runtime model
- formalize resources, verbs, and scopes

### Phase 3

- replace path inference with manifest-driven route enforcement
- replace sidebar conditionals with manifest-driven menu policies
- replace inline button checks with declarative action helpers

### Phase 4

- move file access, AJAX access, and data-scope checks behind the same policy engine
- add centralized audit logging for denies and privilege use

### Phase 5

- run a full direct URL, API, AJAX, download, upload, export, and widget visibility audit

## Immediate Architectural Recommendations

Before refactoring production logic, the implementation should create:

- a discovery scanner that inventories modules and pages from `modules/*`
- a route manifest builder
- an endpoint classifier for `api`, `ajax`, `export`, `upload`, `download`, `delete`, `action`, `cron`, and `run` files
- a UI action registry builder from buttons, forms, and `data-action` attributes
- a permission compiler that emits normalized resource and action keys

This prevents future modules from requiring manual redesign.

## Current-State Conclusion

The current CRM has evolved from role-driven access into a partial permission-driven system, but it is not yet enterprise-grade because:

- access is inferred instead of declared
- UI and backend checks are not generated from one source
- role aliasing still exists
- module permissions are overloaded
- data visibility is not formalized
- hidden endpoints are not fully centralized

The redesign should replace the current hybrid system with a manifest-driven deny-by-default RBAC and policy engine.
