# Notifications System — Specification & Current Implementation

This document defines notification event types, routing rules, and where the triggers are implemented in the codebase.

## Goals

- Deliver relevant notifications to the right user(s) at the right time.
- Avoid notifying everyone for everything (reduce noise).
- Provide a deep link to the actionable screen (campaign, lead, chat, etc.).
- Keep implementation simple and reliable (DB-backed + in-app bell icon).

## Data Model

Table: `notifications`

- `user_id`: receiver
- `type`: event type (string)
- `title`: short title
- `message`: preview text
- `link`: internal link (relative path)
- `is_read`, `read_at`, `created_at`

## Event Types & Routing

### 1) Campaign Assigned (`campaign.assigned`)

**When**
- Campaign is assigned to a user in:
  - Operations assignments
  - QA assignments
  - Client SDR assignment

**Recipients**
- Only the users newly assigned.

**Link**
- Campaign view: `../campaigns/campaign-details.php?id={campaignId}`

**Triggers**
- Operations: [operations-assign.php](file:///c:/xampp/htdocs/leads/modules/dashboard/operations-assign.php)
- QA: [qa-assign.php](file:///c:/xampp/htdocs/leads/modules/dashboard/qa-assign.php)
- Client SDR: [client-campaigns.php](file:///c:/xampp/htdocs/leads/modules/clients/client-campaigns.php)

### 2) Lead Created/Uploaded (`lead.created`)

**When**
- Lead is inserted via agent submit or bulk upload.

**Recipients**
- Campaign assignees (Ops + QA + campaign user assignments)
- Lead owner/agent (if present)
- Assigned SDR (if present)
- Excludes the actor who created the lead (to avoid self-notifications)

**Link**
- Lead details: `../leads/lead-details.php?id={leadDbId}`

**Triggers**
- Agent submit: [agent.php](file:///c:/xampp/htdocs/leads/modules/leads/agent.php)
- Bulk upload: [bulk-upload.php](file:///c:/xampp/htdocs/leads/modules/leads/bulk-upload.php)

### 3) Lead Status Updated (`lead.status_updated`)

**When**
- QA updates QA Status and/or Client Delivery Status.

**Recipients**
- Agent/owner (lead.agent_id)
- Assigned SDR (lead.assigned_to_user)
- Creator (lead.created_by)
- Excludes the reviewer performing the update

**Link**
- Lead details: `../leads/lead-details.php?id={leadDbId}`

**Trigger**
- Core function: [updateLeadQuality](file:///c:/xampp/htdocs/leads/includes/functions.php#L6510-L6548)

### 4) Chat Message (`chat.message`)

**When**
- A new chat message is sent to a receiver.

**Recipients**
- Receiver only

**Link**
- Chat thread: `../chat/chat.php?user_id={senderId}`

**Trigger**
- [chat-send.php](file:///c:/xampp/htdocs/leads/modules/chat/chat-send.php)

## Helper Functions

- `notifyUsers(...)`: send notifications to multiple users safely
- `getCampaignAssignedUserIds(...)`: fetch recipients from assignment tables
- `notifyLeadCreated(...)`: routing logic for lead created events

Locations:
- [functions.php](file:///c:/xampp/htdocs/leads/includes/functions.php)

## Next Enhancements (Recommended)

- User notification preferences table:
  - enable/disable per event type
  - digest vs instant
- Deduplication window (avoid repeated notifications for same lead within N minutes)
- “Pacing risk” alerts:
  - low delivered vs pacing target
  - QA backlog above threshold
- Invoice events:
  - invoice created, status changed, invoice paid

