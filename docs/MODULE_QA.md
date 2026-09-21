# QA Module (Wiring + Flow Reference)

This document captures the Quality (QA) module wiring: pages, role access, campaign visibility rules, and the lead QA lifecycle.

## 1) Purpose

- QA reviews submitted leads and sets:
  - `leads.qa_status` (Pending/Reopened/Qualified/Disqualified/Rework Needed)
  - `leads.qa_comment` (internal)
  - `leads.qa_client_comment` (client-visible)
  - `leads.client_delivery_status` (Pending/Delivered)
- QA actions are logged to `lead_activity` via backend helpers.

## 2) Primary Pages (Dashboard Module)

- QA dashboard: [qa/dashboard](file:///c:/xampp/htdocs/leads/modules/qa/dashboard)
- QA audit queue: [qa/audit](file:///c:/xampp/htdocs/leads/modules/qa/audit)
- QA action endpoint (updates lead QA fields): [qa/action](file:///c:/xampp/htdocs/leads/modules/qa/action)
- QA assignments (admin/qa manager/director): [qa/assignments](file:///c:/xampp/htdocs/leads/modules/qa/assignments)
- QA assignment request (QA users): [qa/request](file:///c:/xampp/htdocs/leads/modules/qa/request)

Legacy/redirect:
- [qa.php](file:///c:/xampp/htdocs/leads/modules/dashboard/qa.php) redirects to the audit queue.

## 3) Supporting QA Tools

- Recording streaming (internal playback endpoint): [stream-recording.php](file:///c:/xampp/htdocs/leads/modules/qa/stream-recording.php)

## 4) Visibility Rules (Campaign Scoping)

- Admin: sees all campaigns and all QA leads.
- QA roles: campaigns must be assigned via `qa_campaign_assignments` (plus team campaign visibility if the user is in allocated teams).

Implementation:
- Campaign assignment map: [getQaVisibleCampaignIdsForUser](file:///c:/xampp/htdocs/leads/includes/functions.php#L2650)
- Team campaign union: [getTeamVisibleCampaignIdsForUser](file:///c:/xampp/htdocs/leads/includes/functions.php#L7667)

## 5) QA Flow (Lead Lifecycle)

1. Lead is created (Agent submit/Bulk/Vendor import) in `leads`.
2. QA queue reads from `leads` scoped by campaign visibility:
   - Pending queue = `qa_status` is NULL or Pending/Reopened
3. QA updates are applied via:
   - [qa-action.php](file:///c:/xampp/htdocs/leads/modules/dashboard/qa-action.php) → `updateLeadQuality(...)`
4. Lead mirrors into campaign lead table `leads_{campaign_code}` via:
   - [syncLeadToCampaignTable](file:///c:/xampp/htdocs/leads/includes/functions.php#L2922)

## 6) Tables Used

- `leads`
- `qa_campaign_assignments`
- `qa_assignment_requests`
- `lead_activity`
