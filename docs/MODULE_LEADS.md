# Leads Module (Wiring + Flow Reference)

This document captures the current Leads module wiring: pages/routes, role access rules, backend tables, and where each flow writes data.

## 1) Goal (Required Workflow)

1. Campaign is created and a Lead Form is assigned to it.
2. Leads arrive via:
   - Agent submit (internal)
   - Bulk upload (internal)
   - Vendor submission/import (vendor users)
3. Every lead is stored in the master `leads` table, and also synced into a campaign-specific lead table `leads_{campaign_code}` (for schema expansion / mirror).
4. Audit trail is kept internally (created_by/updated_by + lead_activity). Client users see only client-safe fields.

## 2) Pages (Clean Routes)

Routing is defined in: [leads/.htaccess](file:///c:/xampp/htdocs/leads/modules/leads/.htaccess)

- Leads list (manage): [list](file:///c:/xampp/htdocs/leads/modules/leads/list) → [leads-edit.php](file:///c:/xampp/htdocs/leads/modules/leads/leads-edit.php)
- Lead view: [view](file:///c:/xampp/htdocs/leads/modules/leads/view) → [lead-details.php](file:///c:/xampp/htdocs/leads/modules/leads/lead-details.php)
- Lead details partial (modal/edit HTML): [details](file:///c:/xampp/htdocs/leads/modules/leads/details) → [get_lead_details.php](file:///c:/xampp/htdocs/leads/modules/leads/get_lead_details.php)
- Lead entry (agent): [entry](file:///c:/xampp/htdocs/leads/modules/leads/entry) → [agent.php](file:///c:/xampp/htdocs/leads/modules/leads/agent.php)
- My leads (agent): [my](file:///c:/xampp/htdocs/leads/modules/leads/my) → [my-leads.php](file:///c:/xampp/htdocs/leads/modules/leads/my-leads.php)
- Bulk upload: [bulk](file:///c:/xampp/htdocs/leads/modules/leads/bulk) → [bulk-upload.php](file:///c:/xampp/htdocs/leads/modules/leads/bulk-upload.php)
- Marketing entry: [marketing](file:///c:/xampp/htdocs/leads/modules/leads/marketing) → [form-filler.php](file:///c:/xampp/htdocs/leads/modules/leads/form-filler.php)
- Email leads: [email](file:///c:/xampp/htdocs/leads/modules/leads/email) → [email-leads.php](file:///c:/xampp/htdocs/leads/modules/leads/email-leads.php)

Admin-only utilities:
- Delete all leads: [leads-purge.php](file:///c:/xampp/htdocs/leads/modules/leads/leads-purge.php)
- Tracking notes: [tracking](file:///c:/xampp/htdocs/leads/modules/leads/tracking) → [lead-tracking.php](file:///c:/xampp/htdocs/leads/modules/leads/lead-tracking.php)

## 3) Role Access Rules (Current)

### Leads list (Manage Leads)

File: [leads-edit.php](file:///c:/xampp/htdocs/leads/modules/leads/leads-edit.php)

- Internal privileged (see all leads):
  - admin, director, manager_director, operations_director, operations_manager, QA roles
- Internal non-privileged (see only own leads):
  - agent, form_filler, operations_agent (filtered by `leads.agent_id = current user id`)
- Vendor:
  - vendor_admin: vendor scoped by `leads.vendor_id`
  - vendor_user: vendor scoped + assigned campaigns scoped
- Client:
  - client_admin: client scoped by campaign client_id
  - client_sdr: client scoped + assigned campaigns scoped

Delete permission:
- admin/director/manager_director/operations_director/operations_manager can delete a lead (double confirmation).

### Lead view

File: [lead-details.php](file:///c:/xampp/htdocs/leads/modules/leads/lead-details.php)

- Agent can only view own leads.
- Vendor can only view own vendor leads.
- Client can only view delivered leads and only for campaigns owned by that client.

## 4) Data Flow (Backend)

### Source of truth

- Master table: `leads`
- Campaign mirror tables: `leads_{campaign_code}` (one per campaign code)

Sync behavior:
- After insert/update/QA update, the lead is synced into the campaign table:
  - [syncLeadToCampaignTable](file:///c:/xampp/htdocs/leads/includes/functions.php#L2878)
  - Campaign table schema is ensured/expanded via:
    - [ensureCampaignLeadTable](file:///c:/xampp/htdocs/leads/includes/functions.php#L2430)

### Insert paths

- Agent submit: [agent.php](file:///c:/xampp/htdocs/leads/modules/leads/agent.php)
  - Inserts into `leads` (created_by/updated_by/vendor_id)
  - Saves form submission (form_submissions)
  - Calls `syncLeadToCampaignTable`

- Bulk upload: [bulk-upload.php](file:///c:/xampp/htdocs/leads/modules/leads/bulk-upload.php)
  - Inserts into `leads`
  - Saves form submission (optional)
  - Calls `syncLeadToCampaignTable`

### Audit trail

- `leads.created_by`, `leads.updated_by`
- `lead_activity` rows via:
  - [logLeadActivity](file:///c:/xampp/htdocs/leads/includes/functions.php#L3028)

## 5) Tables (Lead Layer)

- `leads` (master record)
- `form_submissions` (campaign/form schema JSON submissions)
- `lead_files` (files uploaded for dynamic fields)
- `lead_activity` (audit trail: lead_updated, qa_updated, form_submission_saved, etc.)
- `leads_{campaign_code}` (campaign mirror + expanded columns for form fields)

## 6) Notes / Open Design Items

- Vendor-side audit tracking is stored internally; client-facing pages should continue hiding vendor-only metadata.
- If required, add a dedicated “client notes” action stream in `lead_activity` and display it only to internal management roles.

