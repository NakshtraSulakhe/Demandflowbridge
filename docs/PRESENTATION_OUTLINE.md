# Presentation Outline — DemandFlow Bridge (Leads)

Use this as a ready-to-present PPT script. Each slide includes key bullets + speaker notes.

## Slide 1 — Title

**DemandFlow Bridge (Leads CRM)**

- End-to-end campaign delivery, lead ops, QA, revenue & invoicing
- Role-based system for Admin / Ops / QA / Sales / Clients / Vendors

**Speaker notes**
- This platform connects campaign setup → lead generation → QA → client delivery → revenue → invoice → ROI.
- Audience: internal leadership + operations + finance + sales stakeholders.

## Slide 2 — Business Problem

- Multiple teams (Ops/QA/Sales/Client) need the same data, but with different access
- Campaign pacing and delivery must be tracked daily
- Revenue must be auditable from delivered leads + CPL
- Invoices must be reusable, consistent, and exportable

**Speaker notes**
- The system reduces coordination overhead and prevents inconsistent spreadsheets.

## Slide 3 — Role-Based Access

- Admin/Director: complete visibility + sensitive actions
- Operations: lead intake and processing, allocations, delivery
- QA: review pipeline + audit trail
- Sales: pipeline + targets + FX rate setup
- Client/Vendor: restricted views (delivered-only / assigned campaigns)

**Speaker notes**
- Access is enforced server-side using role checks across every module.

## Slide 4 — Campaign Lifecycle (High Level)

1. Campaign create & configuration (CPL, allocation, targeting)
2. Lead submission (agent + bulk upload + vendor flows)
3. QA review (status + comments + delivery status)
4. Client delivery (delivered-only visibility, SDR assignment)
5. Revenue & invoicing (generated + allocations + invoices)
6. ROI reporting (revenue INR vs payroll cost)

**Speaker notes**
- Each step is measurable and leaves a trail (audit logs, activity logs).

## Slide 5 — Campaigns Module

- Campaign list: allocation, delivered, pacing, status
- Campaign details: instructions, files, notes, progress
- Campaign notes: operational updates + attachments

**Speaker notes**
- Notes are used heavily for internal coordination (handoffs, blockers, client instructions).

## Slide 6 — Leads Module (Ops Execution)

- Single lead entry (Agent submit)
- Bulk upload with validation + duplicate checks + report output
- Lead details view with history + linked data
- Suppression checks (domain cooldown / duplicates)

**Speaker notes**
- Bulk upload produces success + rejected reports (auditable).

## Slide 7 — QA Module (Quality & Audit)

- QA audit queue: review, update status, client-visible comments
- Client Delivery Status drives delivered counts & client visibility
- QA audit logs store history per lead

**Speaker notes**
- QA status and delivery status are separated for clean reporting.

## Slide 8 — Revenue & Invoicing

- Generated revenue = Delivered leads × CPL
- Allocations: manual overrides when needed
- Invoice creation from campaign/month
- Invoice PDF: adaptive layout, bank details, signature, amount in words
- Reusable defaults + Bill To templates

**Speaker notes**
- Invoices are consistent across months/clients because we store templates and defaults.

## Slide 9 — FX Conversion + ROI

- FX USD→INR maintained in Sales Targets
- Revenue dashboard converts USD to INR and shows:
  - Total revenue (INR)
  - Payroll cost (net + incentives)
  - ROI ratio & ROI %
- Agent ROI detail page supports verification via payslips

**Speaker notes**
- ROI is computed in INR so finance comparisons are consistent.

## Slide 10 — Notifications System (CRM Feasibility)

**What we notify**
- New campaign allocated (Ops/QA/Client SDR assignment)
- New lead uploaded (campaign assignees + owner)
- Lead status updated (owner + assigned stakeholders)
- New message (chat)

**How we ensure relevance**
- Notifications are routed by:
  - campaign assignments tables (Ops + QA + SDR)
  - lead ownership (agent / assigned_to / created_by)
  - direct receiver for chat

**Speaker notes**
- Notifications are not broadcast to everyone; they follow assignment and ownership.

## Slide 11 — Example Notification Flows

- Campaign assigned → notification to assigned user → link to campaign view
- Lead uploaded → notification to ops/qa assignees → link to lead details
- QA update → notification to lead owner → link to lead details
- Chat message → notification to receiver → link to chat thread

**Speaker notes**
- Each notification carries a deep link so the user can act immediately.

## Slide 12 — Roadmap / Enhancements

- Notification preferences (per user / per type)
- Digest notifications (hourly/daily summaries)
- SLA alerts: pacing risk, backlog queues (QA pending > threshold)
- Invoice approvals workflow (Draft → Approved → Sent → Paid)
- More analytics: conversion funnels and campaign performance trends

**Speaker notes**
- Next improvements focus on proactive alerts and preference controls.

