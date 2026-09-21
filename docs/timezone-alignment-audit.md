# Time Zone Alignment Audit

Date: 2026-06-17

## Business Rule

- Attendance, HR, payroll, leave, NCNS, login/logout, break tracking, and working-hours reporting must stay on `Asia/Kolkata` (IST).
- Lead management, campaign reporting, dashboard lead widgets, agent productivity, client delivery, revenue reporting, weekly/monthly performance reporting, and target reset logic must follow `America/New_York` (ET).
- Preferred storage architecture: store timestamps in UTC, convert to IST for HR flows, and convert to ET for operational/business reporting.

## Executive Summary

The CRM currently mixes three different time assumptions:

1. Explicit IST logic in HR helper functions.
2. Server-local PHP time via `new DateTime()`, `date()`, and `strtotime()`.
3. Database-session time via MySQL `NOW()`, `CURDATE()`, `CURRENT_TIMESTAMP`, and `DATE(column)`.

This creates reporting drift around midnight and month boundaries, especially on lead dashboards, campaign dashboards, QA, sales follow-ups, task due widgets, revenue dashboards, and performance reports.

HR is closest to the target state because it already uses `hrBaseTz()` and `hrDisplayTz()`. Operational reporting is not centralized and is the main source of incorrect numbers.

## Root Causes

### 1. No controlled application or DB session timezone

- `config/config.php` does not define a central application timezone.
- `config/database.php` does not pin the MySQL session timezone.
- Lead table defaults and many write paths still depend on `CURRENT_TIMESTAMP` and `NOW()`.

### 2. Shared dashboard ranges use server-local time

- `includes/functions.php::dfDashboardRange()` builds date windows with generic `DateTime`.
- Any dashboard using this helper inherits whichever timezone the web server is running in.

### 3. Productivity logic leaks HR timezone into operational metrics

- `includes/functions.php::funneltrackRangeFromPreset()` uses `hrBaseTz()`.
- `modules/performance/*` therefore calculate productivity windows using IST instead of ET.

### 4. SQL date truncation is not ET-safe

- Patterns like `DATE(created_at) = CURDATE()` and `DATE(COALESCE(...)) BETWEEN ? AND ?` evaluate in the DB session timezone, not ET.

### 5. Browser and PHP display formatting are inconsistent

- Many pages format timestamps with raw `date()` / `strtotime()` and no explicit timezone conversion.

## Current vs Expected by Scope

### Infrastructure

- Current:
  - DB session timezone is implicit.
  - PHP default timezone is implicit.
  - Lead timestamps rely on MySQL defaults and `NOW()`.
- Expected:
  - MySQL session pinned to UTC.
  - PHP default timezone pinned to UTC.
  - Storage timestamps written in UTC only.
  - Display and reporting converted through dedicated IST/ET helpers.

### Attendance / HR

- Current:
  - Mostly uses `hrBaseTz()` and `hrDisplayTz()`.
  - Several writes still use `NOW()`.
  - A few entry pages still seed date/month from generic `DateTime()` or `date()`.
- Expected:
  - All attendance and payroll reads, grouping, monthly selection, and display stay in IST.
  - HR writes should also be UTC-safe even if rendered in IST.

### Leads / Campaigns / Dashboards / Productivity / Revenue

- Current:
  - Daily and monthly windows are mostly built with server-local PHP time.
  - SQL uses DB-session date functions.
  - Performance uses IST in productivity helpers.
- Expected:
  - All business-day and reporting boundaries use ET.
  - Daily target resets happen at ET midnight.
  - Weekly and monthly grouping follows ET date buckets.

## Affected Modules and Files

### Foundational files

- `config/database.php`
- `config/config.php`
- `includes/functions.php`

### Dashboards and KPI pages

- `modules/dashboard/admin-dashboard.php`
- `modules/dashboard/agent-dashboard.php`
- `modules/dashboard/client-dashboard.php`
- `modules/dashboard/email-marketing-dashboard.php`
- `modules/dashboard/form-filler-dashboard.php`
- `modules/dashboard/operations-dashboard.php`
- `modules/dashboard/vendor-dashboard.php`
- `modules/qa/dashboard.php`
- `modules/sales/dashboard.php`
- `modules/campaigns/campaign-dashboard.php`
- `modules/revenue/revenue-dashboard.php`
- `modules/performance/dashboard.php`
- `modules/performance/team.php`
- `modules/performance/reports.php`
- `modules/hr/hr-dashboard.php`
- `modules/dbms/dashboard.php`
- `modules/admin/analytics/agents.php`
- `modules/admin/analytics/campaigns.php`
- `modules/admin/analytics/dbms.php`
- `modules/admin/analytics/sales.php`

### Reports and exports

- `modules/tasks/reports.php`
- `modules/hr/attendance-export.php`
- `modules/hr/attendance-monthly-report.php`
- `modules/hr/payroll.php`
- `modules/leads/export.php`
- `modules/campaigns/campaigns-export.php`
- `modules/revenue/revenue.php`
- `modules/revenue/invoices.php`

### Operational and supporting pages

- `modules/tasks/tasks.php`
- `modules/tasks/api.php`
- `modules/tasks/deadline-run.php`
- `modules/sales/activity.php`
- `modules/campaigns/campaign-details.php`
- `modules/campaigns/campaign-leads.php`
- `modules/campaigns/campaigns-manage.php`
- `modules/leads/agent.php`
- `modules/qa/action.php`
- `modules/leads/bulk-upload.php`
- `modules/dbms/batch.php`
- `modules/notifications/digest-run.php`

## Confirmed Incorrect Calculations

### Shared range helper

- File: `includes/functions.php`
- Problem:
  - `dfDashboardRange()` uses `new DateTime()` and `DateTime::createFromFormat(...)` without a timezone.
- Impact:
  - Admin, operations, sales, revenue, client, vendor, QA, email marketing, form filler, DBMS, and analytics dashboards inherit server-local dates instead of ET.

### Agent dashboard helper

- File: `includes/functions.php`
- Problem:
  - `getAgentDashboardStats()` uses `DATE(created_at) = CURDATE()`.
- Impact:
  - Today lead counts on agent-style dashboards are wrong when server or DB timezone is not ET.

### Lead count helper

- File: `includes/functions.php`
- Problem:
  - `getLeadsCount()` filters with `DATE(created_at) >= ?` and `DATE(created_at) <= ?`.
- Impact:
  - Export, list, and summary counters can assign leads to the wrong day around ET/IST crossover.

### QA review timestamp write

- File: `includes/functions.php`
- Problem:
  - `updateLeadQuality()` writes `qa_updated_at = NOW()`.
- Impact:
  - QA reviewed counts, daily QA totals, and productivity reports depend on DB session timezone.

### Performance range helper

- File: `includes/functions.php`
- Problem:
  - `funneltrackRangeFromPreset()` uses `hrBaseTz()`.
- Impact:
  - Productivity and incentive reporting uses IST windows even though business reporting should use ET.

### Performance daily trend and qualified grouping

- Files:
  - `modules/performance/dashboard.php`
  - `modules/performance/team.php`
  - `modules/performance/reports.php`
- Problems:
  - Productivity pages initialize with `hrBaseTz()`.
  - Queries use `DATE(COALESCE(qa_updated_at, created_at))`.
- Impact:
  - Qualified lead counts and score windows are grouped by IST/DB session time instead of ET.

### Admin dashboard

- File: `modules/dashboard/admin-dashboard.php`
- Problems:
  - Uses server-local `DateTime()`, `strtotime()`, and `time()`.
  - Uses DB `NOW()` for overdue sales follow-ups.
- Impact:
  - Mixed dashboard with ET-sensitive operational counts and IST attendance data on the same page.

### Operations dashboard

- File: `modules/dashboard/operations-dashboard.php`
- Problems:
  - Uses server-local `DateTime()` and `date()` for today/month windows.
- Impact:
  - Today/month lead and productivity cards are not aligned to ET.

### QA dashboard

- File: `modules/qa/dashboard.php`
- Problems:
  - Builds month/today windows with server-local `DateTime()` / `date()`.
  - Uses `qa_updated_at BETWEEN ? AND ?` but with wrong window boundaries.
- Impact:
  - Daily and monthly QA reviewed/pass/fail cards drift around midnight.

### Email marketing and form filler dashboards

- Files:
  - `modules/dashboard/email-marketing-dashboard.php`
  - `modules/dashboard/form-filler-dashboard.php`
- Problems:
  - Today/month windows built from server-local PHP time.
- Impact:
  - Daily productivity and monthly totals are not ET-safe even though count scope fixes already exist.

### Sales dashboard and activity

- Files:
  - `modules/sales/dashboard.php`
  - `modules/sales/activity.php`
- Problems:
  - Dashboard range comes from `dfDashboardRange()`.
  - Overdue follow-up logic uses `NOW()`.
  - Activity write path uses `NOW()` for `last_activity_at`, `created_at`, and `updated_at`.
  - Reminder windows use `NOW() +/- INTERVAL`.
- Impact:
  - Overdue counts, due-soon reminders, follow-up freshness, and sales activity ordering are not ET-safe.

### Revenue module

- Files:
  - `modules/revenue/revenue-dashboard.php`
  - `modules/revenue/revenue.php`
  - `modules/revenue/invoices.php`
  - `modules/revenue/invoice-edit.php`
- Problems:
  - Revenue dashboard inherits `dfDashboardRange()`.
  - Revenue listing uses a month string with server-local `date()` / `strtotime()`.
  - Accepted lead revenue uses `created_at BETWEEN ? AND ?`.
  - Invoice issue/due dates and paid timestamps use `date()` / `NOW()`.
- Impact:
  - Accepted lead revenue and month-based billing windows can differ from ET business day expectations.

### Campaign dashboards and campaign management

- Files:
  - `modules/campaigns/campaign-dashboard.php`
  - `modules/campaigns/campaigns-manage.php`
  - `modules/campaigns/campaign-details.php`
  - `modules/campaigns/campaign-leads.php`
- Problems:
  - Dashboard presets use `date()` / `strtotime()`.
  - Risk windows use server-local `strtotime()`.
  - Campaign notes and status updates use `NOW()`.
  - Campaign lead tables display raw `created_at`.
- Impact:
  - Campaign daily, weekly, monthly, and risk views can disagree with ET reporting.

### Client and vendor dashboards

- Files:
  - `modules/dashboard/client-dashboard.php`
  - `modules/dashboard/vendor-dashboard.php`
- Problems:
  - Both depend on `dfDashboardRange()`.
  - Both count leads in `created_at BETWEEN ? AND ?`.
- Impact:
  - External-facing campaign totals can differ from internal ET reporting.

### Task widgets, task reports, and task deadlines

- Files:
  - `includes/functions.php`
  - `modules/tasks/tasks.php`
  - `modules/tasks/reports.php`
  - `modules/tasks/api.php`
  - `modules/tasks/deadline-run.php`
- Problems:
  - Widget counts use `DATE(due_at) = CURDATE()` and `due_at < NOW()`.
  - Task page uses `time()`, `new DateTime()`, and server-local `date()`.
  - Reports export filenames and summaries use server-local `date()`.
  - Deadline escalations and state transitions use `NOW()`.
- Impact:
  - Due today, overdue, and escalation timing can change based on server or DB timezone.

### DBMS dashboards and batch reporting

- Files:
  - `modules/dbms/dashboard.php`
  - `modules/dbms/batch.php`
- Problems:
  - Dashboard inherits `dfDashboardRange()` and separate server-local daily/month windows.
  - Aging logic uses `NOW()` and `TIMESTAMPDIFF`.
  - Batch filters use `DATE(uploaded_at)`.
- Impact:
  - Daily/monthly ingestion and stale-queue aging are not stable under timezone changes.

### Notifications digest

- File: `modules/notifications/digest-run.php`
- Problems:
  - Queue selection and processed marker use `NOW()`.
- Impact:
  - Scheduler-style batching is dependent on DB session timezone.

## HR / Attendance Findings

### Mostly aligned with IST

- `includes/functions.php::hrBaseTz()`
- `includes/functions.php::hrDisplayTz()`
- `includes/functions.php::hrNow()`
- `modules/hr/attendance.php`
- `modules/hr/attendance-admin.php`
- `modules/hr/hr-dashboard.php`
- `modules/hr/paid-leaves.php`

### Still needs cleanup

- `modules/hr/attendance-admin.php`
  - Many updates use `NOW()`.
- `modules/hr/attendance-monthly-report.php`
  - Seeds month with generic `DateTime()`.
- `modules/hr/payroll.php`
  - Seeds selected month with generic `DateTime()`.
- `modules/hr/payslip-view.php`
  - Updates use `NOW()`.

### HR conclusion

HR is directionally correct and should remain IST, but it still needs storage normalization and a final pass to remove implicit PHP/DB timezone dependencies.

## Database and Storage Audit

### Current behavior

- Lead tables and several other tables define timestamps using `CURRENT_TIMESTAMP`.
- Application insert/update paths often rely on `NOW()`.
- DB session timezone is not explicitly controlled.

### Risks

- Same code can behave differently between developer, staging, and production environments.
- ET business-day assignment cannot be guaranteed if timestamps are written in local server time.

### Required DB changes

1. Set PHP default timezone to UTC at bootstrap.
2. Set MySQL session timezone to `+00:00` in `getDbConnection()`.
3. Standardize new timestamp writes to UTC-aware helpers or `UTC_TIMESTAMP()`.
4. Audit schema defaults that still rely on `CURRENT_TIMESTAMP` and confirm UTC semantics.
5. Avoid using `DATE(timestamp_column)` for ET business grouping; instead use ET window boundaries in UTC or `CONVERT_TZ` with a controlled session.

## Recommended Architecture

### Single source of truth

- Storage: UTC
- HR business timezone: IST
- Operational/reporting business timezone: ET

### Required helper layer

Add centralized helpers in `includes/functions.php`, for example:

- `appUtcTz(): DateTimeZone`
- `appNowUtc(): DateTimeImmutable`
- `hrBusinessTz(): DateTimeZone`
- `opsBusinessTz(): DateTimeZone` returning `America/New_York`
- `opsRangeFromPreset(...)`
- `opsBusinessDayBoundsUtc('today' | custom date)`
- `formatUtcForHrDisplay(...)`
- `formatUtcForOpsDisplay(...)`

### Query strategy

- Never compare `DATE(timestamp_column)` to a date string for ET-sensitive reporting.
- Build ET day/week/month boundaries in PHP using `America/New_York`.
- Convert those boundaries to UTC.
- Query raw UTC timestamps with `BETWEEN utc_start AND utc_end`.

### Display strategy

- Attendance timestamps shown in IST.
- Lead and reporting timestamps shown in ET where the page represents business reporting.
- If a page mixes HR and operational data, label the timezone in the UI or split sections clearly.

## Backend Change Plan

### Phase 1. Infrastructure

- Update `config/config.php` to define UTC as the app default.
- Update `config/database.php` to execute `SET time_zone = '+00:00'`.
- Add centralized UTC, IST, and ET helper functions.

### Phase 2. Shared helpers

- Replace `dfDashboardRange()` for operational/reporting pages with an ET-based helper.
- Keep a separate IST helper for HR-only pages if needed.
- Replace `funneltrackRangeFromPreset()` to use ET instead of `hrBaseTz()`.

### Phase 3. High-risk operational metrics

- Fix:
  - `getAgentDashboardStats()`
  - `getLeadsCount()`
  - task widget helpers
  - sales follow-up overdue/reminder helpers
  - QA reviewed counters
  - performance qualified-lead grouping

### Phase 4. Page rewiring

- Rewire dashboard entry points to use ET windows:
  - admin
  - operations
  - sales
  - campaign
  - revenue
  - QA
  - email marketing
  - form filler
  - client
  - vendor
  - DBMS
  - analytics

### Phase 5. Write-path normalization

- Replace direct `NOW()` writes on ET-sensitive entities with UTC writes or PHP-generated UTC timestamps.
- Keep HR display in IST but store underlying timestamps in UTC.

### Phase 6. Frontend cleanup

- Replace raw `date()` / `strtotime()` display on ET-sensitive pages with formatting helpers.
- Review any JS date rendering after backend normalization.

## Frontend Display Changes Required

- Label timezone on pages that show business-day reporting.
- Use ET formatting for lead created/reviewed/accepted timestamps on:
  - dashboards
  - campaign reporting
  - QA queues
  - revenue reporting
  - sales operational pages
- Keep attendance and payroll display in IST.

## Validation Matrix

### Current behavior

- A lead created during IST morning can appear in the wrong operational day if ET is still on the previous date.
- QA reviewed counts can move between days depending on DB session timezone.
- Task due today / overdue can flip at server midnight instead of ET midnight.
- Performance score windows follow IST in some pages.

### Expected behavior

- All lead-based reporting uses ET business-day boundaries.
- All attendance/payroll pages remain IST.
- UTC storage prevents environment-specific drift.

### Root cause

- Mixed use of server-local PHP time, DB-session time, and isolated IST helpers.

### Fix applied

- No code fix applied in this audit document. This report captures the complete impact map and implementation order for the next phase.

## Required Test Cases

### 1. IST to ET day crossover

- Input timestamp: `2026-06-18 04:30:00 IST`
- Equivalent ET: `2026-06-17 19:00:00 ET`
- Expected:
  - Lead reporting counts it under `2026-06-17`.
  - Attendance remains under IST work date if it belongs to HR attendance.

### 2. Same-day but different local clock

- Input timestamp: `2026-06-18 10:30:00 IST`
- Equivalent ET: `2026-06-18 01:00:00 ET`
- Expected:
  - Operational lead counts stay on `2026-06-18 ET`.

### 3. Daily reset logic

- Create leads just before and after ET midnight.
- Verify:
  - Today / yesterday widgets
  - QA reviewed today
  - agent daily target counters
  - campaign dashboard today cards

### 4. Weekly reporting

- Test a week spanning month-end.
- Verify ET Monday-Sunday grouping on:
  - campaign dashboard
  - performance reports
  - revenue dashboard
  - admin analytics

### 5. Monthly reporting

- Create records near ET month-end but next-day IST.
- Verify they stay in the correct ET month for:
  - revenue
  - lead totals
  - campaign performance
  - productivity

### 6. Attendance calculations

- Validate punch in/out, late minutes, break time, NCNS, payroll proration, and attendance reports using IST only.

### 7. Agent productivity

- Validate that:
  - qualified lead counts use ET day boundaries
  - attendance contribution still uses IST dates
  - daily target resets at ET midnight
  - no duplicate or missing counts appear during crossover

### 8. Scheduler and batch logic

- Validate task deadline jobs, notification digest, and sales follow-up reminders after moving to UTC storage and explicit ET/IST conversions.

## Priority Order

1. Infrastructure timezone control in PHP and DB.
2. Shared operational helper replacement.
3. Agent, admin, operations, QA, sales, campaign, revenue, and performance dashboard rewiring.
4. Task widgets and scheduler-like jobs.
5. Client/vendor dashboards and exports.
6. Remaining display-format cleanup.

## Audit Outcome

The CRM is not currently timezone-safe for ET-based operational reporting. HR is partially aligned to IST, but operational reporting, lead counting, and productivity logic are inconsistent and can produce incorrect values around day and month boundaries. The next phase should implement centralized UTC storage with explicit IST and ET conversion helpers, then rewire high-risk reporting flows in the priority order above.
