# DemandFlow Bridge (Leads) — Modules & Functionality

This document summarizes the application modules, key screens, and primary workflows so it can be used for internal demos/presentations.

## 1) High-Level Overview

DemandFlow Bridge is a role-based internal web application to manage:

- Campaign setup and delivery tracking
- Lead capture, QA, and operational processing
- Sales lead management
- Revenue/invoicing (with FX conversion and reporting)
- HR (attendance, payroll, payslips)
- Notifications + team chat

The app is organized by modules under `modules/` and shares common layout and utilities under `includes/`.

## 2) Roles & Access Model

Pages are protected using `requireRole(...)` / role helpers. Access is primarily based on:

- Admin / Director / Manager roles (full visibility)
- Operations roles (processing / allocation)
- QA roles (review, audit)
- Sales roles (sales pipeline and targets)
- Client/Vendor admin roles (restricted portal views)
- Agents / Form Fillers (task execution views)

Common patterns:

- Dashboards are role-specific (Admin, Operations, QA, Sales, Client, Vendor, Email Marketing).
- Sensitive actions (delete/override/payroll lock) are limited to Admin or designated director roles.
- CSRF tokens are used for POST actions (forms and AJAX).

## 3) Core Modules

### A) Dashboard Module (`modules/dashboard`)

Purpose: role-based landing pages with KPIs and quick actions.

Key screens:

- Admin Dashboard: operational overview, pending queues, recent activity.
- Operations Dashboard: allocations, delivery progress, quick actions.
- QA Dashboard: pending QA load, audit tools, assignment actions.
- Sales Dashboard: sales pipeline & lead activity summary.
- Client/Vendor dashboards: restricted reporting per account.

Common elements:

- KPI cards, charts, quick links to related work queues.
- Back navigation support via global topbar back button.

### B) Campaigns Module (`modules/campaigns`)

Purpose: create/manage campaigns; monitor lead flow; store notes and attachments; connect campaign forms; track pacing.

Key screens:

- Campaigns Manage: campaign list with allocation, generated, pending QA, delivered, pacing.
- Campaign Details (Campaign View): campaign metadata, delivery stats, associated files, and notes.
- Campaign Leads: campaign-scoped lead listing and filters.
- Campaign Dashboard: broader campaign KPIs and lead scoring.
- Campaign Create/Edit: campaign setup fields and CPL/currency settings.

Campaign Notes (important):

- Notes are stored in a separate campaign notes table.
- Supports attachments.
- Notes are created/edited/deleted with secure AJAX actions.

Workflow:

1. Create campaign
2. Configure details (client code, CPL, allocations, fields/forms)
3. Operations/Agents generate and process leads
4. QA approves/disqualifies leads
5. Delivered leads are tracked and revenue is calculated from CPL
6. Notes capture operational updates (with attachments)

### C) Leads Module (`modules/leads`)

Purpose: lead intake, assignment, editing, validation, exports, and work queues for agents/QA/form-fillers.

Key screens:

- Leads Edit / Lead Details: edit lead data, view history/details.
- My Leads: personal queue.
- Agent view: agent operational tools and modals.
- Form Filler: form completion workflow for qualified leads.
- Bulk Upload: mass lead import with reporting.
- Export: campaign/lead data exports.

Key capabilities:

- Lead status changes and QA status management.
- Duplicate checking/suppression tools.
- Campaign delivery status tracking (Delivered / etc).

### D) Sales Module (`modules/sales`)

Purpose: manage sales leads/pipeline and targets, including FX rates.

Key screens:

- Sales Leads list + lead view / create
- Accounts management (account create/list)
- Activity logging
- Targets: productivity targets and FX rate (USD→INR) maintenance

FX Rate:

- FX rate is used by Revenue dashboards to convert USD revenue into INR for reporting (and ROI calculations).

### E) Productivity Module (`modules/productivity`)

Purpose: performance tracking and incentives view.

Key screens:

- Productivity (agent-focused)
- Productivity Admin (manager view)
- Incentives (admin/agent view)
- Exports for analysis

### F) Revenue Module (`modules/revenue`)

Purpose: revenue reporting, invoicing, expenses, FX conversion, and ROI reporting.

Key screens:

- Revenue Dashboard: summary KPIs, charts, payroll summary, ROI, agent ROI snapshot
- Campaign Revenue: delivered-leads-based “generated” revenue and manual allocations
- Expenses: manual expense entry and totals
- Invoices: create invoices from campaigns, list, copy, delete (restricted), bulk actions, quick status updates
- Invoice Edit: invoice data entry, items, Bill To templates, saved defaults, signature upload
- Invoice PDF: generated multi-page PDF with adaptive layout, FX-aware formatting, and banking/signature sections
- Agent ROI (detail): per-agent ROI computed from revenue (INR) vs cost (net + incentives)

Invoice improvements supported:

- “Billed By” (company), Bank Details, and Signature can be saved as user defaults.
- “Bill To” details can be saved as reusable templates; templates can be managed (create/edit/delete).
- Invoice creation from campaign can auto-prefill Bill To from templates or client contacts.

ROI definition (current):

- Revenue (converted to INR using FX rate) ÷ Payroll Cost (Net + Incentives from Payslips)

### G) HR Module (`modules/hr`)

Purpose: attendance tracking, payroll generation, payslip view/PDF, salary setup, bonus/loans, shift management.

Key screens:

- HR Dashboard: attendance snapshot, payroll status, quick links
- Attendance (user) and Attendance Admin
- Payroll: generate/lock/unlock, month-wide views
- Payslips: list and individual payslip view
- Payslip PDF: downloadable payslip
- Salary Setup: salary structures and effective dates
- Bonus & Loans: manage bonus/loan deductions
- Shifts: shift definitions and assignments

Payroll → Payslip:

1. Configure salary structures (admin)
2. Generate payslips for month (admin)
3. View payslip details (per user/month)
4. Export payroll CSV

### H) Clients & Vendors (`modules/clients`, `modules/vendors`)

Purpose: restricted portals for client/vendor users to view campaigns, leads, billing, and related reporting.

Key screens:

- Campaigns, Leads, Billing, Users (client/vendor variants)
- Vendor revenue views (as applicable)

### I) Notifications (`modules/notifications`)

Purpose: in-app notifications, unread counts, and “mark read” actions.

Key screens:

- Notifications list
- Mark read endpoints (with secure token + redirect)

### J) Chat (`modules/chat`)

Purpose: internal team chat with presence and user list.

Key screens/endpoints:

- Chat UI
- Fetch/send presence endpoints

### K) Users (`modules/users`)

Purpose: manage internal users and profiles.

Key screens:

- Manage Users (admin)
- Profile view

### L) Admin Tools (`modules/admin`)

Purpose: diagnostics and system visibility.

Key screens:

- DB diagnostics/system logs

## 4) Navigation & UX Conventions

- Common layout: `includes/layout/app_start.php`, sidebar, topbar, `app_end.php`.
- Global theme toggle supports dark/light mode.
- Global topbar “Back” button appears automatically when a safe internal referrer/back parameter exists.

## 5) Data Model (high-level)

Core tables (not exhaustive):

- `campaigns`, `campaign_details`, `campaign_notes`, `campaign_additional_files`
- `leads`
- `clients`, `client_contacts`
- `revenue_invoices`, `revenue_invoice_items`, `revenue_invoice_settings`, `revenue_invoice_billto_profiles`, `campaign_revenue`, `revenue_manual_expenses`
- `fx_rates`
- `users`, `user_bank_details`, `user_documents`, `user_personal_details`
- `hr_payslips`, `hr_salary_structures`, `hr_payroll_month_locks`, attendance and shift tables

## 6) Common Workflows (presentation-ready)

### Campaign → Leads → QA → Delivery

1. Create campaign + set CPL and allocation
2. Agents/Form Fillers process leads for the campaign
3. QA reviews (Qualified/Disqualified/Duplicate/Rework flows)
4. Delivered leads are tracked, pacing is updated
5. Notes capture ongoing updates and attachments

### Revenue & Invoicing

1. Delivered leads produce Generated Revenue (Delivered × CPL)
2. Finance/ops can allocate revenue manually where required
3. Create invoice from campaign/month
4. Reuse Bill To templates and defaults (company/bank/signature)
5. Export PDF invoice and track invoice status (Draft/Sent/Paid/Cancelled)

### Payroll → ROI

1. Generate payslips (Net + Incentives) for the month
2. Revenue dashboard converts revenue to INR using FX rate
3. ROI card shows overall revenue vs payroll
4. Agent ROI shows per-agent ROI and links to payslips for verification

