# Permission Matrix Blueprint

## Purpose

This document defines the target permission matrix structure for the CRM redesign. It is derived from the discovered modules, pages, endpoints, actions, and hidden workflows in the current codebase.

This is a blueprint, not a hardcoded static list. The final system should generate this matrix from a resource manifest.

## Matrix Model

Each permission is defined by:

- resource type
- module
- resource key
- action verb
- scope requirement
- menu visibility behavior
- backend enforcement requirement

## Core Verbs

Standard verbs to generate when applicable:

- `view`
- `create`
- `edit`
- `delete`
- `approve`
- `reject`
- `assign`
- `reassign`
- `manage`
- `configure`
- `export`
- `import`
- `upload`
- `download`
- `run`
- `override`
- `sync`

## Scope Values

Scope keys to support:

- `own`
- `assigned`
- `team`
- `department`
- `campaign`
- `client`
- `vendor`
- `all`

## Resource Matrix

| Module | Resource Types | Core Resources Discovered | Required Verbs |
|---|---|---|---|
| Admin | module, page, action, widget | access management, settings, team management, diagnostics, analytics | view, manage, configure, import, export |
| Auth | page, action | login, logout, password reset, change password, IP restriction | view, run |
| Dashboard | module, page, widget, endpoint | admin, operations, QA, sales, client, vendor, marketing dashboards, banners AJAX | view |
| Campaigns | module, page, action, endpoint, export, upload | dashboard, list, create, edit, allocation, files, details, leads, assign SDR, delete, export | view, create, edit, delete, assign, export, upload, manage |
| Leads | module, page, action, endpoint, export, upload, download | entry, my leads, marketing entry, email leads, bulk upload, manage leads, details, tracking, export, purge | view, create, edit, delete, export, upload, download, manage |
| QA | module, page, action | dashboard, audit queue, assignment request, assignments, QA action | view, update, approve, reject, assign |
| Sales | module, page, action, endpoint | dashboard, prospects, create prospect, accounts, activity, manager map, targets, duplicate check, FX sync | view, create, edit, assign, manage, sync |
| Revenue | module, page, action, export, download | dashboard, campaign revenue, expenses, invoices, invoice edit, invoice PDF, bill-to templates | view, create, edit, delete, export, download, sync, manage |
| HR | module, page, action, export, download | dashboard, attendance, attendance admin, leaves, payslips, shifts, payroll, salary setup, bonus/loans | view, create, edit, export, download, manage, override |
| Tasks | module, page, action, API, upload, download, job | dashboard, create, view, reports, API actions, deadline runner | view, create, edit, assign, reassign, override, upload, download, run |
| Performance | module, page, action | dashboard, team, reports, admin, targets, escalations, overrides | view, configure, assign, edit, manage |
| DBMS | module, page, action, export, upload, download | dashboard, upload, explorer, assignments, my records, research, CDQA, record view, export, batches | view, upload, assign, approve, reject, export, download, manage |
| Clients | module, page | directory, users, campaigns, leads, billing | view, manage |
| Vendors | module, page | directory, users, campaigns, leads, revenue, billing | view, manage |
| Forms | module, page, endpoint | forms manage, form edit, campaign form endpoint | view, create, edit, manage |
| Chat | module, page, endpoint, download | chat, send, fetch, groups, members, delete, presence | view, create, edit, delete, download |
| Notifications | module, page, endpoint, job | inbox, preferences, poll, mark read, digest runner | view, edit, run |
| Files | endpoint, download | bulk report, DBMS batch file, task file, lead file, chat attachment | download |

## Recommended Permission Taxonomy

### Module Permissions

Examples:

- `module.campaigns.view`
- `module.leads.view`
- `module.tasks.view`

### Page Permissions

Examples:

- `page.campaigns.list.view`
- `page.campaigns.create.view`
- `page.leads.email.view`
- `page.dbms.explorer.view`
- `page.admin.access_management.view`

### Action Permissions

Examples:

- `action.campaign.create`
- `action.campaign.edit`
- `action.campaign.delete`
- `action.campaign.assign_vendor`
- `action.lead.export`
- `action.qa.approve`
- `action.task.reassign`
- `action.dbms.cdqa_approve`

### Endpoint Permissions

Examples:

- `endpoint.tasks.api.execute`
- `endpoint.qa.action.execute`
- `endpoint.files.download.execute`
- `endpoint.notifications.mark_read_ajax.execute`
- `endpoint.sales.fx_sync_cron.execute`

### Upload And Download Permissions

Examples:

- `upload.leads.bulk.run`
- `upload.campaign.delivery.run`
- `upload.dbms.batch.run`
- `download.lead.file.run`
- `download.task.file.run`
- `download.chat.attachment.run`
- `download.dbms.batch.run`

### Widget Permissions

Examples:

- `widget.dashboard.admin.pipeline.view`
- `widget.dashboard.sales.targets.view`
- `widget.dashboard.dbms.queue_health.view`

### Menu Permissions

Examples:

- `menu.sales.view`
- `menu.campaigns.view`
- `menu.dbms.view`
- `menu.system.view`

### Data Visibility Permissions

Examples:

- `data.lead.own`
- `data.lead.team`
- `data.lead.campaign`
- `data.lead.all`
- `data.task.assigned`
- `data.task.all`
- `data.campaign.assigned`
- `data.dbms.department`
- `data.invoice.client`

## Resource-To-Permission Examples

### Campaigns

| Resource | Required Permissions |
|---|---|
| View campaign list | `module.campaigns.view`, `page.campaigns.list.view` |
| View campaign details | `page.campaigns.details.view`, `data.campaign.assigned` or `data.campaign.all` |
| Create campaign | `page.campaigns.create.view`, `action.campaign.create` |
| Edit campaign | `page.campaigns.edit.view`, `action.campaign.edit` |
| Delete campaign | `action.campaign.delete` |
| Assign vendors | `action.campaign.assign_vendor` |
| Export campaigns | `action.campaign.export` |
| Upload delivery file | `upload.campaign.delivery.run` |

### Leads

| Resource | Required Permissions |
|---|---|
| Lead entry page | `page.leads.entry.view`, `action.lead.create` |
| My leads page | `page.leads.my.view`, `data.lead.own` |
| Manage leads page | `page.leads.manage.view`, `action.lead.manage`, `data.lead.team` or `data.lead.all` |
| View lead details | `page.leads.details.view`, `data.lead.own` or `data.lead.team` or `data.lead.all` |
| Export leads | `action.lead.export` |
| Bulk upload | `page.leads.bulk.view`, `upload.leads.bulk.run` |
| Play recordings | `action.lead.recording_view`, plus data-scope permission |

### Tasks

| Resource | Required Permissions |
|---|---|
| Task dashboard | `page.tasks.dashboard.view` |
| Create task | `page.tasks.create.view`, `action.task.create` |
| View task | `page.tasks.details.view`, `data.task.assigned` or `data.task.all` |
| Update task status | `action.task.update_status` |
| Reassign task | `action.task.reassign` |
| Override task closure | `action.task.override` |
| Upload task file | `upload.task.file.run` |
| Download task file | `download.task.file.run` |
| Deadline job | `endpoint.tasks.deadline_run.execute` |

### DBMS

| Resource | Required Permissions |
|---|---|
| DBMS dashboard | `page.dbms.dashboard.view` |
| Explorer | `page.dbms.explorer.view`, `data.dbms.department` or `data.dbms.all` |
| Assignment page | `page.dbms.assignments.view`, `action.dbms.assign` |
| Research queue | `page.dbms.research.view`, `action.dbms.research_update` |
| CDQA queue | `page.dbms.cdqa.view`, `action.dbms.cdqa_approve`, `action.dbms.cdqa_reject` |
| Upload batch | `upload.dbms.batch.run` |
| Export DBMS data | `action.dbms.export` |
| Download batch file | `download.dbms.batch.run` |

## Automatic Matrix Generation Rules

The final implementation should generate matrix rows automatically by scanning:

- file path and module path
- page guard type
- form actions
- `data-action` attributes
- explicit endpoint files
- export, upload, download, API, AJAX, cron, and runner files
- dashboard widget blocks
- sidebar menu definitions

Each discovered resource should be normalized into:

- module
- resource key
- route or file
- UI visibility key
- backend permission key
- supported verbs
- required scope policy

## Deny-By-Default Enforcement Rule

No module, page, endpoint, button, widget, upload, export, or download should be accessible unless:

- the resource exists in the manifest
- the current principal has the required permission
- the current principal also satisfies the required data scope policy

If a route or action is not registered, access must be denied automatically.

## Required Outputs For Implementation Phase

The implementation phase should compile this matrix into:

- manifest files
- database seed or config seed
- access-management UI sections
- backend middleware lookups
- sidebar and UI visibility checks
- audit and testing fixtures
