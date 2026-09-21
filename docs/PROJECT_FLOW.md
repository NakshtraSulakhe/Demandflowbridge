# DemandFlow Bridge - Project Reference

## Entry Points

- Web entry: [index.php](file:///c:/xampp/htdocs/leads/index.php)
- Auth helpers: [auth.php](file:///c:/xampp/htdocs/leads/includes/auth.php)
- Shared constants + filters: [constants.php](file:///c:/xampp/htdocs/leads/includes/constants.php)
- Shared DB helpers + schema bootstrap: [functions.php](file:///c:/xampp/htdocs/leads/includes/functions.php)

## Roles (High Level)

- Admin / Director / Operations: campaign creation, assignment, delivery, reporting
- QA: QA review + QA audit queue + QA dashboard
- Agent / Form filler: lead creation + form submission
- Client Admin / Client SDR: client campaigns, delivered leads, lead tagging and follow-up
- Vendor: vendor campaigns + vendor leads (delivery/funnel side)

## Core Process Flow

### 1) Campaign Setup

1. Create campaign (Campaigns → Create Campaign)
2. Configure campaign details (allocation, dates, pacing, CPL, targeting)
3. Configure form schema per campaign (Lead Forms)
4. Assign vendors / SDRs / QA scope (where enabled)

### 2) Lead Generation + Submission

1. Lead created by agent or bulk upload
2. Form submission saved for the lead (campaign-specific schema)
3. Lead is visible internally in Leads list / QA queue

### 3) QA Review + Client Delivery

Two separate statuses exist:

- QA Status: internal QA outcome (Pending/Qualified/Disqualified/etc.)
- Client Delivery Status: what counts as delivered to client (Pending/Delivered)

QA updates can set both fields, and delivery counts are based on Client Delivery Status.

### 4) Client Visibility + Follow-up

- Client sees only leads with Client Delivery Status = Delivered.
- Client can assign campaign to SDR, which assigns all leads of that campaign to that SDR.
- Client can tag and manage delivered leads.

## VOIP / Glocom Integration (Current State)

This project contains a VOIP integration layer designed for PBXware/Glocom “CRM Integration Service” style callbacks and for internal CRM call history + recording linkage.

### Goal (User Workflow)

1. PBXware/Glocom calls CRM Integration Service:
   - Create call log (CDR) in CRM
   - Attach recording URL to the created call log after completion
2. Agents/Admin open Call History, filter by dialed number, then link the correct call log to the lead.
3. When linked, the CRM can (optionally) download the recording and store it locally for long-term access.

### Integration Service Endpoints

Router: [api/index.php](file:///c:/xampp/htdocs/leads/api/index.php) with rewrite: [api/.htaccess](file:///c:/xampp/htdocs/leads/api/.htaccess)

- `GET /api/crm` → service metadata (deployment sanity check)
- `GET /api/token` → Basic Auth against CRM users, returns integration token
- `GET /api/customers/search?phonenumber=...` → lead lookup by phone (returns deep-link via `webpage`)
- `POST /api/calllog` → creates call log, returns async Status id
- `GET /api/status/{id}` → returns READY + `resourceid` (calllog id / recording id)
- `POST /api/callrecord` (or `/api/recording`) → attaches recording URL to call log, returns async Status id

Auth model:
- `/api/token`: Basic Auth
- All other `/api/*`: `X-CrmIService-Token` header

### Settings (Configuration)

Admin UI: [settings.php](file:///c:/xampp/htdocs/leads/modules/admin/settings.php)

- CRM Integration Credentials (for `/api/token`):
  - Stored in `voip_settings.crm_username` / `voip_settings.crm_password`
- PBXware / Glocom API Credentials:
  - Stored in `voip_settings.username` / `voip_settings.password` / `voip_settings.api_token`
  - Used to download recordings when recording URLs are protected/private (depends on vendor auth scheme)
- Auto-download recordings on lead link:
  - Controlled by `voip_settings.upload_recordings`

### Call History + Lead Linking

- Call History (filter-first UI): [call-history.php](file:///c:/xampp/htdocs/leads/modules/admin/call-history.php)
  - Default view shows no results until user applies filters (dialed number / status / direction / agent)
  - Audio playback is allowed, download is blocked in the UI for normal users
  - Linking call log to lead triggers optional recording download to local storage

- Lead Submit “link existing VOIP call”:
  - Agent submit: [agent.php](file:///c:/xampp/htdocs/leads/modules/leads/agent.php)
  - Call log search endpoint: [search-calllogs.php](file:///c:/xampp/htdocs/leads/modules/voip/search-calllogs.php)

### Recording Storage Behavior

If enabled, after a call log is linked to a lead, CRM downloads recordings and stores them under:

- `uploads/call_recordings/` (stored file path is saved in `voip_call_recordings.file_path`)

Streaming / download endpoint (role gated):
- [stream-recording.php](file:///c:/xampp/htdocs/leads/modules/qa/stream-recording.php)

### Database Tables (VOIP Layer)

Schema bootstrap lives in: [functions.php](file:///c:/xampp/htdocs/leads/includes/functions.php)

- `call_logs` (CDR/call activity)
- `voip_call_recordings` (recording URLs + optional downloaded file_path)
- `voip_service_tokens` (integration tokens for `X-CrmIService-Token`)
- `voip_async_status` (PENDING → READY polling support)
- `sip_accounts` (SIP pool + user assignment)
- `voip_settings` (integration + PBX config)

### Deployment Notes (Live)

The correct public Integration Service base URL is whichever responds to:

- `https://<your-domain>/api/crm` or
- `https://<your-domain>/<subfolder>/api/crm`

For the production CRM domain `https://crm.tarajglobal.com/`, the intended base is typically:
- `https://crm.tarajglobal.com/api`

### Pending / Vendor Confirmations (Before Go-Live)

- Vendor payload must include an agent identifier (`extension` / `agentnumber`) for correct user assignment.
- Vendor must provide a stable unique call id (`asteriskcallid1/2`) if dedupe is required.
- Recording URL accessibility must be confirmed (public vs authenticated; exact auth scheme).

## Important Rules Implemented

- Client Delivered counts/pacing use `client_delivery_status` (not QA status).
- Client access is restricted to delivered leads only.
- QA comments are split:
  - Internal comment (internal-only)
  - Client comment (client-visible)

## Pages / Modules Map

### Campaigns

- Campaign list/manage: [campaigns-manage.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaigns-manage.php)
- Campaign details: [campaign-details.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-details.php)
- Campaign dashboard: [campaign-dashboard.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-dashboard.php)
- Campaign leads: [campaign-leads.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-leads.php)
- Campaign edit: [campaign-edit.php](file:///c:/xampp/htdocs/leads/modules/campaigns/campaign-edit.php)

### QA

- QA dashboard: [qa/dashboard](file:///c:/xampp/htdocs/leads/modules/qa/dashboard)
- QA audit queue: [qa/audit](file:///c:/xampp/htdocs/leads/modules/qa/audit)
- QA action handler: [qa/action](file:///c:/xampp/htdocs/leads/modules/qa/action)

### Leads

- Leads list (internal): [leads-edit.php](file:///c:/xampp/htdocs/leads/modules/leads/leads-edit.php)
- Lead details (internal): [lead-details.php](file:///c:/xampp/htdocs/leads/modules/leads/lead-details.php)
- Lead info modal endpoint: [get_lead_details.php](file:///c:/xampp/htdocs/leads/modules/leads/get_lead_details.php)

### Clients

- Client campaigns: [client-campaigns.php](file:///c:/xampp/htdocs/leads/modules/clients/client-campaigns.php)
- Client leads (delivered): [client-leads.php](file:///c:/xampp/htdocs/leads/modules/clients/client-leads.php)
- Client lead details: [client-lead-details.php](file:///c:/xampp/htdocs/leads/modules/clients/client-lead-details.php)

## Database (High-Level)

This project uses a mix of:

- Core tables (static): users, clients, vendors, campaigns, campaign_details, leads, tags, audit logs
- Campaign lead tables (dynamic): one per campaign, created as `campaign_leads_{campaignId}` (used for stats/reporting)

### Key Tables (Typical Usage)

- `users`: authentication + role + client/vendor ownership
- `campaigns`: base campaign entity
- `campaign_details`: allocation, pacing, CPL, targeting, client link
- `leads`: primary lead record used by most modules
  - `qa_status`: internal QA state
  - `qa_comment`: internal QA comment
  - `qa_client_comment`: client-visible comment
  - `client_delivery_status`: Pending/Delivered (counts + client visibility)
- `lead_activity`: audit trail of lead actions (QA updates, tagging, etc.)
- `qa_audit_logs`: QA history records
- `campaign_user_assignments`: assign campaign to users (including client SDR)

## Flow Chart (Mermaid)

```mermaid
flowchart TD
  A[Campaign Create/Edit] --> B[Form Schema per Campaign]
  B --> C[Lead Submission]
  C --> D[Lead Record (leads)]
  D --> E[QA Review]
  E --> F[QA Status Updated]
  E --> G[Client Delivery Status Updated]
  G --> H{Delivery = Delivered?}
  H -- No --> I[Client cannot see lead]
  H -- Yes --> J[Client Leads + Client Lead Details]
  J --> K[Assign Campaign to Client SDR]
  K --> L[All Campaign Leads assigned to SDR]
```

## Known Generated Schema Source

- DB snapshot reference: [schema_introspect.json](file:///c:/xampp/htdocs/leads/docs/schema_introspect.json)
