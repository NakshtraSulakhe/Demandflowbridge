# Campaigns Module (Wiring + Flow Reference)

This document captures the current Campaigns module wiring end-to-end: entry pages, redirects, permissions, database tables, and the lead-flow connections.

## 1) Purpose (What this module controls)

- Campaign lifecycle: create → configure → assign form → allocate → generate leads → QA → delivery tracking.
- Campaign metadata: client, code, dates, allocation targets, targeting criteria, instructions, custom questions.
- Campaign files: setup files (script/TAL/suppression/recording), additional files, delivery files.
- Campaign visibility: which users can see which campaigns (role + assignments).

## 2) Primary Entry Points (Pages)

- Campaign list (main): [list](file:///c:/xampp/htdocs/leads/modules/campaigns/list) → [campaigns-manage.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaigns-manage.php)
- Campaign create: [create](file:///c:/xampp/htdocs/leads/modules/campaigns/create) → [campaign-create.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-create.php)
- Campaign edit: [edit](file:///c:/xampp/htdocs/leads/modules/campaigns/edit) → [campaign-edit.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-edit.php)
- Campaign details (view + notes): [view](file:///c:/xampp/htdocs/leads/modules/campaigns/view) → [campaign-details.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-details.php)
- Campaign allocation (teams/users): [allocation](file:///c:/xampp/htdocs/leads/modules/campaigns/allocation) → [campaign-allocation.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-allocation.php)
- Campaign files (delivery uploads): [files](file:///c:/xampp/htdocs/leads/modules/campaigns/files) → [campaign-delivery.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-delivery.php)
- Campaign leads list: [leads](file:///c:/xampp/htdocs/leads/modules/campaigns/leads) → [campaign-leads.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-leads.php)
- Campaign dashboard (KPIs): [dashboard](file:///c:/xampp/htdocs/leads/modules/campaigns/dashboard) → [campaign-dashboard.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-dashboard.php)

Redirect/bridge pages:
- Assign SDR shortcut: [assign-sdr](file:///c:/xampp/htdocs/leads/modules/campaigns/assign-sdr) → [campaign-assign-sdr.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-assign-sdr.php) → redirects to [operations-assign.php](file:///c:/xampp/htdocs/leads/modules/dashboard/operations-assign.php)
- Export endpoint: [export](file:///c:/xampp/htdocs/leads/modules/campaigns/export) → [campaigns-export.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaigns-export.php)
- Delete endpoint (AJAX): [delete](file:///c:/xampp/htdocs/leads/modules/campaigns/delete) → [campaign-delete.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-delete.php)

Routing:
- URL aliases are defined in [campaigns/.htaccess](file:///c:/xampp/htdocs/leads/modules/campaigns/.htaccess)

## 3) Sidebar Wiring (Navigation)

- Campaigns menu: [sidebar.php](file:///c:/xampp/htdocs/leads/includes/layout/sidebar.php#L71-L89)
  - View Campaigns → campaigns-manage.php
  - Create Campaign → campaign-create.php
  - Campaign Allocation → campaign-allocation.php
  - Campaign Files → campaign-delivery.php
  - Lead Forms → forms-manage.php

## 4) Permissions / Visibility Rules (Current)

### A) Campaign list (campaigns-manage.php)

- If user is a Client → redirects to client view.
- Internal users:
  - Admin/sales/director roles can manage campaigns and see broader data.
  - Non-privileged users get scoped visibility via `getScopedVisibleCampaignIdsForUser`.

Key code:
- `getScopedVisibleCampaignIdsForUser(...)`: [functions.php](file:///c:/xampp/htdocs/leads/includes/functions.php#L2221-L2249)

### B) Campaign details (campaign-details.php)

- Admin/sales/directors: full access.
- SDR: must be assigned via `campaign_user_assignments`.
- QA: scoped by `qa_campaign_assignments` via `getQaVisibleCampaignIdsForUser`.
- Other internal roles: allowed (page is used for operational visibility).

## 5) Core Workflow (Expected Flow)

### Step 1: Create campaign

- UI: [create](file:///c:/xampp/htdocs/leads/modules/campaigns/create) → [campaign-create.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-create.php)
- Backend: `createCampaignWithDetails(array $basic, array $criteria, array $customFields, array $files)`: [createCampaignWithDetails](file:///c:/xampp/htdocs/leads/includes/functions.php#L6296)

Notes:
- Campaign code is auto-generated (`campaign_details.code`), and used for campaign-specific lead table naming.
- Status cannot be set to `Live` at creation time (enforced).

### Step 2: Assign lead form (required before Live)

- Forms module pages:
  - [forms-manage.php](file:///c:/xampp/htdocs/leads/modules/forms/forms-manage.php)
- Enforced rule:
  - `setCampaignStatus(..., 'Live')` requires an assigned form: [setCampaignStatus](file:///c:/xampp/htdocs/leads/includes/functions.php#L5623-L5661)

### Step 3: Allocation to teams/users (operations)

- UI: [campaign-allocation.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-allocation.php)
- Tables used:
  - `operations_campaign_assignments` (individual users)
  - `team_campaigns` (teams)

### Step 4: Lead generation / submission

- Agents submit leads in Leads module:
  - [agent.php](file:///c:/xampp/htdocs/leads/modules/leads/agent.php)
- Lead is stored in `leads` and also synced into a campaign-specific table:
  - `syncLeadToCampaignTable(...)` → `upsertCampaignLeadRow(...)`: [functions.php](file:///c:/xampp/htdocs/leads/includes/functions.php#L2838-L2898)
  - Campaign table name = `leads_{campaign_code_sanitized}`: [getCampaignLeadTableName](file:///c:/xampp/htdocs/leads/includes/functions.php#L2398-L2408)

### Step 5: QA review + delivery tracking

- QA updates happen in QA module.
- Delivery tracking uses:
  - `leads.client_delivery_status = Delivered` to count delivered
  - Campaign pages compute delivered/pending from leads + allocation target.

### Step 6: Delivery files (optional operational upload)

- UI: [campaign-delivery.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-delivery.php)
- Table: `campaign_delivery_files`

## 6) Data Model (Campaign Tables)

Core tables (directly used by Campaigns pages):
- `campaigns` (campaign entity)
- `campaign_details` (code, status, dates, allocation, CPL, criteria, custom_fields_json, file paths)
- `campaign_forms` (campaign ↔ form mapping)
- `campaign_user_assignments` (campaign ↔ user assignment, used for SDR scoping and general assignments)
- `vendor_campaign_map` (campaign ↔ vendor assignment with vendor CPL + uploads_enabled)
- `campaign_notes` (notes + optional attachment)
- `campaign_additional_files` (additional attachments)
- `campaign_delivery_files` (delivery uploads)

Support/legacy table:
- `campaign_metrics`
  - Table exists, but campaign list/overview no longer depends on it for live stats. Live stats are computed directly from `leads`.

Campaign lead tables:
- One per campaign code: `leads_{code}` (created/expanded on demand by `ensureCampaignLeadTable`).
- This is used as a secondary “campaign-specific storage” and remains connected to the lead submission workflow.

## 7) Known “Old/Unwanted” Candidates (Not removed yet)

- Duplicate `requireRole(...)` calls in:
  - [campaign-create.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-create.php#L4-L6)
  - [campaign-edit.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-edit.php#L4-L6)
- `campaign_metrics` currently appears unused for real-time stats (most pages compute stats directly from `leads`); can be removed later only if nothing else depends on it.
- Static option source file:
  - [campaign-form-options-source.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-form-options-source.php)
  - Used by `getCampaignCreateFormOptionValues()` to build dropdown options (works, but is “data in code” and can be refactored later).

## 8) Quick “How it connects” (Pages → Tables)

- campaigns-manage.php
  - reads: campaigns, campaign_details, campaign_forms, vendor_campaign_map
  - computes: lead stats from `leads` via `getCampaignLeadTableStats`
- campaign-details.php
  - reads: campaign_details (+ campaigns), campaign_notes, campaign_additional_files, leads stats
- campaign-edit.php / campaign-create.php
  - writes: campaigns, campaign_details, campaign_additional_files
- campaign-allocation.php
  - writes: team_campaigns, operations_campaign_assignments
- campaign-delivery.php
  - writes: campaign_delivery_files

