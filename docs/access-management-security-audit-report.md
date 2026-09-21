# Access Management Security Audit Report

## Current-State Audit

This report summarizes the main security and architecture risks discovered during the codebase-wide access-management scan.

## Executive Summary

The current access model is functional but structurally unsafe for long-term scale because it mixes:

- role checks
- permission checks
- inferred route permissions
- inline action checks
- ownership checks
- menu visibility logic

The result is a system where frontend and backend access logic can drift apart, hidden endpoints are not represented centrally, and new modules can inherit inconsistent protection without an explicit design step.

## Confirmed Architectural Risks

### 1. Route Heuristics Drive Access

Observed in `includes/auth.php`:

- `inferPermissionKeyForRequest()` derives permissions from URL patterns
- broad module mappings such as `sales.access`, `revenue.access`, and `campaigns.view` cover multiple resource types

Risk:

- route naming changes can change access behavior
- unmapped resources may inherit broad permissions accidentally
- view and manage semantics can become blurred

### 2. `requireRole()` Is Not Purely Role-Based

Observed in `includes/auth.php`:

- `requireRole()` now infers permission from the request path
- it contains exception logic for dashboards and lead detail access

Risk:

- developers may believe they are adding role restrictions when they are really invoking path-based permission inference
- this increases hidden dependencies and debugging difficulty

### 3. Menu Visibility Is Not Centrally Generated

Observed in `includes/layout/sidebar.php`:

- visibility uses mixed role helpers and permission checks
- top-level and child-level items use slightly different conditions

Risk:

- menu leakage
- modules appearing for users who should not see them
- page availability diverging from menu visibility

### 4. Endpoint Security Is Decentralized

Observed in:

- `modules/tasks/api.php`
- `modules/qa/action.php`
- `modules/files/download.php`
- `modules/chat/*`
- `modules/notifications/*`

Risk:

- each endpoint reimplements access rules differently
- hidden endpoints are harder to audit
- action-level permissions are not reusable

### 5. Data Visibility Is Not A First-Class Policy

Observed patterns:

- campaign visibility maps
- team visibility logic
- task assignee ownership checks
- client and vendor scoping
- file download campaign checks

Risk:

- page access may be granted while record-level access remains inconsistently enforced
- one resource type may protect team scope well while another does not

### 6. Alias Roles Cause Identity Ambiguity

Observed in role normalization:

- `agent`
- `qa`
- `form_filler`
- `email_marketing_executive`

Risk:

- duplicated access buckets
- overlapping imports
- inconsistent reporting and policy evaluation

### 7. Module Permissions Are Too Broad

Examples:

- `sales.access`
- `revenue.access`
- `campaigns.view`
- `qa.access`

Risk:

- a single permission may unlock multiple pages, actions, or endpoints
- fine-grained access management becomes difficult

### 8. Custom Permissions Lack Governance

Observed in `modules/admin/access-management.php`:

- admins can create custom permissions directly
- custom permissions are not bound to a required resource schema

Risk:

- naming drift
- nonstandard permissions
- orphan permissions not linked to routes, buttons, or actions

## High-Risk Areas By Feature

### Campaigns

Risks:

- page and action boundaries are easy to over-broaden
- vendor assignment, status change, delete, note editing, file management, and exports are separate action classes but not modeled separately today

### Leads

Risks:

- list, details, export, recordings, bulk upload, and purge are distinct privilege surfaces
- current model still relies on broad lead permissions and helper-based visibility

### Tasks

Risks:

- `tasks/api.php` contains rich action logic that should be policy-driven
- current checks are strong in places but decentralized

### Files And Downloads

Risks:

- `modules/files/download.php` mixes file-kind routing, permission checks, and ownership/data rules in one endpoint
- file categories should be first-class secured resources

### Notifications And Background Jobs

Risks:

- `digest-run.php`, `deadline-run.php`, and `fx-sync-cron.php` are operational endpoints that need explicit machine or scheduler permissions

## Missing Controls

Controls not yet formalized as first-class RBAC resources:

- widget visibility
- button visibility
- report visibility
- export permissions by report type
- upload permissions by file category
- download permissions by file category
- background job execution permissions
- explicit API permissions
- explicit AJAX permissions
- data scope policies per resource type

## Recommended Redesign Controls

### Mandatory

- explicit route manifest
- explicit endpoint manifest
- explicit action registry
- explicit menu registry
- explicit widget registry
- explicit data-scope policy layer
- canonical role model without aliases
- deny-by-default enforcement

### Recommended

- policy evaluation logging
- access simulation tool in admin
- manifest diff report for newly introduced routes
- automated test coverage for direct URL, AJAX, API, file, and export access

## Refactor Guardrails

When implementation begins, the redesign should:

- preserve current business scopes such as assigned campaigns, own leads, client-bound visibility, and vendor-bound visibility
- replace only the access architecture, not business rules themselves
- move business visibility rules behind policy adapters
- avoid any fallback that grants access when a resource is not registered

## Phase 4 Implementation Target

The final implementation should achieve:

- zero alias-role duplication
- zero inferred permission dependency for production authorization
- zero sidebar-only access assumptions
- zero hidden endpoint exposure without manifest coverage
- synchronized frontend and backend access enforcement
- explicit policy coverage for data, files, reports, widgets, buttons, uploads, and downloads
