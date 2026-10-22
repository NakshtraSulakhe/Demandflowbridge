# DemandFlow Bridge CRM — Daily Agent Incentive API Documentation

**Version:** 1.0.0  
**Base URL:** `http://localhost/demandflowbridge/api/v1/incentives/`  
**Protocol:** HTTP/1.1 & HTTPS  
**Format:** JSON (`Content-Type: application/json; charset=utf-8`)

---

## 1. Overview & Architecture

The **Daily Agent Incentive API** powers the automated calculation, settlement, manager approval, and disbursement pipeline for agent performance incentives in DemandFlow Bridge CRM.

### Core Business Rules:
1. **Target Threshold**: Agents must reach 100% of their daily target points (`target_points`) to unlock the base daily incentive (₹500 default).
2. **Tiered Incentive Slabs**: Performance beyond 100% earns additional incentive per point across configured tiers (e.g. 100–150%, 150–200%, >200%).
3. **Attendance Requirement**: Agent must be present (`Present`, `Full Day`, `Half Day`, or `In Progress`) on the work date. Absent agents are disqualified.
4. **QA Escalation Blocking**: Active QA escalations automatically zero out incentives or apply configured deduction penalties.
5. **Idempotency & Monotonicity**: Daily settlements and disbursements enforce unique constraints and irreversible monotonic status transitions (`unpaid` &rarr; `processing` &rarr; `paid`).
6. **Payroll Deductions**: Daily incentives already disbursed are tracked to deduct accurately during monthly salary payroll runs.

---

## 2. Authentication & Authorization

All API endpoints require authentication using one of the following mechanisms:

### Authentication Methods:
| Method | Syntax | Use Case |
| :--- | :--- | :--- |
| **Bearer Token** | `Authorization: Bearer <TOKEN>` | Server-to-Server, Frontend apps |
| **API Key Header** | `X-API-Key: <TOKEN>` | External integrations, automated scripts |
| **URL Query Token** | `?token=<TOKEN>` | Quick browser/cURL testing, Webhook configs |
| **Session Cookie** | `PHPSESSID=<COOKIE>` | Internal CRM web application UI |
| **CLI / Cron** | `PHP_SAPI === 'cli'` | Scheduled system cron jobs |

### Allowed Default Tokens:
- `df_admin_secret_token_2026`
- `demandflow_secret_token_2026`
- Environment variables: `CRM_API_TOKEN`, `ADMIN_AUTH_TOKEN`

---

## 3. Standard Response Formats

### Success Response (`200 OK` / `201 Created`):
```json
{
  "success": true,
  "status": 200,
  "message": "Human-readable confirmation message",
  "timestamp": "2026-10-22T13:12:38Z",
  "data": { ... },
  "meta": { ... }
}
```

### Error Response (`400`, `401`, `403`, `404`, `405`, `500`):
```json
{
  "success": false,
  "status": 400,
  "error": "Error description",
  "error_code": "INVALID_PARAMETERS",
  "timestamp": "2026-10-22T13:12:38Z"
}
```

---

## 4. API Endpoints Reference

### 4.1 Preview Daily Incentives (Provisional / Read-Only)
Calculates real-time provisional incentive points, target achievement %, attendance eligibility, and tiered payout amounts based on the day's QA audits without creating permanent database records.

- **Endpoint:** `GET /preview.php`
- **Access:** Any authenticated user

#### Query Parameters:
| Parameter | Type | Required | Default | Description |
| :--- | :--- | :--- | :--- | :--- |
| `date` | `string` | No | Current business date | Date in `YYYY-MM-DD` format |
| `token` | `string` | No | — | Auth token if not passed in header |

#### Request Example (cURL):
```bash
curl -X GET "http://localhost/demandflowbridge/api/v1/incentives/preview.php?date=2026-10-22" \
  -H "Authorization: Bearer df_admin_secret_token_2026"
```

#### Response Example:
```json
{
  "success": true,
  "status": 200,
  "message": "Daily incentive preview for 2026-10-22 generated successfully.",
  "timestamp": "2026-10-22T13:12:38Z",
  "data": {
    "work_date": "2026-10-22",
    "total_agents": 29,
    "eligible_agents": 4,
    "total_incentive": 3200.0,
    "agents": [
      {
        "agent_id": 14,
        "agent_name": "Rahul Sharma",
        "role": "agent",
        "work_date": "2026-10-22",
        "target_points": 10.0,
        "achieved_points": 14.0,
        "completion_pct": 140.0,
        "points_breakdown": {
          "MQL": 4.0,
          "BANT": 10.0
        },
        "qualified_leads_count": 6,
        "disqualified_leads_count": 1,
        "total_scored_leads": 7,
        "base_incentive": 500.0,
        "slab_incentive": 400.0,
        "total_incentive": 900.0,
        "attendance_status": "Present",
        "is_present": true,
        "has_escalation": false,
        "eligibility_status": "eligible",
        "eligibility_reason": "Target achieved and compliance verified"
      }
    ]
  },
  "meta": {
    "mode": "preview",
    "is_provisional": true,
    "notice": "Provisional QA results: Scores and amounts may change until settlement cutoff."
  }
}
```

---

### 4.2 Settle Daily Incentives (Finalize & Dry-Run)
Executes end-of-day settlement. Runs calculation and creates finalized records in `daily_incentive_payouts` awaiting manager approval. In `dry_run` mode, it returns calculated records without writing changes.

- **Endpoint:** `POST /settle.php`
- **Access:** Admin, Operations Director, Manager

#### Request Body (JSON):
| Field | Type | Required | Default | Description |
| :--- | :--- | :--- | :--- | :--- |
| `work_date` | `string` | No | Current business date | Date in `YYYY-MM-DD` |
| `mode` | `string` | No | `finalize` | `finalize`, `dry_run`, or `recalculate_unpaid` |

#### Request Example (cURL):
```bash
curl -X POST "http://localhost/demandflowbridge/api/v1/incentives/settle.php" \
  -H "Authorization: Bearer df_admin_secret_token_2026" \
  -H "Content-Type: application/json" \
  -d '{
    "work_date": "2026-10-22",
    "mode": "dry_run"
  }'
```

---

### 4.3 Get Settled Daily Records
Retrieves settled records, approval states, and payment statuses for a specific date with filtering and pagination.

- **Endpoint:** `GET /daily.php`
- **Access:** Authenticated Manager / Admin (SDR sees only their own)

#### Query Parameters:
| Parameter | Type | Required | Default | Description |
| :--- | :--- | :--- | :--- | :--- |
| `date` | `string` | No | Current business date | Date in `YYYY-MM-DD` |
| `approval_status` | `string` | No | `""` | `pending_approval`, `approved`, `rejected` |
| `payment_status` | `string` | No | `""` | `unpaid`, `processing`, `paid`, `failed` |
| `agent_id` | `integer` | No | `0` | Filter to a specific agent |
| `page` | `integer` | No | `1` | Page number |
| `limit` | `integer\|string`| No | `50` | Items per page or `all` |

#### Request Example (cURL):
```bash
curl -X GET "http://localhost/demandflowbridge/api/v1/incentives/daily.php?date=2026-10-22&approval_status=pending_approval" \
  -H "Authorization: Bearer df_admin_secret_token_2026"
```

---

### 4.4 Manager Approval & Rejection API
Allows authorized managers to approve or reject daily incentive payouts individually or in bulk.

- **Endpoint:** `POST /approve.php`
- **Access:** Manager, Admin, Director

#### Request Body (JSON):
```json
{
  "payout_ids": [101, 102, 103],
  "action": "approve",
  "remarks": "Verified targets against QA scorecards"
}
```

#### Fields:
| Field | Type | Required | Values |
| :--- | :--- | :--- | :--- |
| `payout_ids` | `array<int>` | Yes | Array of payout IDs |
| `action` | `string` | Yes | `approve` or `reject` |
| `remarks` | `string` | No | Optional approval/rejection note |

#### Request Example (cURL):
```bash
curl -X POST "http://localhost/demandflowbridge/api/v1/incentives/approve.php" \
  -H "Authorization: Bearer df_admin_secret_token_2026" \
  -H "Content-Type: application/json" \
  -d '{
    "payout_ids": [14, 15],
    "action": "approve",
    "remarks": "Approved by Operations Lead"
  }'
```

---

### 4.5 Payout Disbursement API
Initiates payment disbursement through the configured payment gateway (Bank Transfer or UPI). Requires payout to be in `approved` state.

- **Endpoint:** `POST /disburse.php`
- **Access:** Admin, Finance Manager

#### Request Body (JSON):
```json
{
  "payout_id": 14,
  "payment_method": "upi"
}
```

#### Fields:
| Field | Type | Required | Values |
| :--- | :--- | :--- | :--- |
| `payout_id` | `integer` | Yes | ID of the approved payout record |
| `payment_method` | `string` | No | `bank_transfer` or `upi` (default configured per agent) |

#### Request Example (cURL):
```bash
curl -X POST "http://localhost/demandflowbridge/api/v1/incentives/disburse.php" \
  -H "Authorization: Bearer df_admin_secret_token_2026" \
  -H "Content-Type: application/json" \
  -d '{
    "payout_id": 14,
    "payment_method": "upi"
  }'
```

---

### 4.6 Payout Status & Audit Trail API
Fetches the complete lifecycle audit log, manager actions, payment provider reference, and error history for a payout.

- **Endpoint:** `GET /status.php`
- **Query Parameter:** `payout_id=<ID>`

#### Request Example (cURL):
```bash
curl -X GET "http://localhost/demandflowbridge/api/v1/incentives/status.php?payout_id=14" \
  -H "Authorization: Bearer df_admin_secret_token_2026"
```

---

### 4.7 Beneficiary Bank & UPI Management API
Retrieves or updates payout preferences (Bank Account, IFSC, UPI ID) for an agent.

- **Endpoint:** `GET /beneficiaries.php?agent_id=14`  
- **Endpoint:** `POST /beneficiaries.php`

#### Save Beneficiary Request Body:
```json
{
  "agent_id": 14,
  "payment_method": "upi",
  "upi_id": "agent@okaxis",
  "beneficiary_name": "Rahul Sharma"
}
```

---

### 4.8 Historical Incentive Range API
Returns daily incentive summaries and detailed breakdowns over date ranges.

- **Endpoint:** `GET /history.php?from=2026-10-01&to=2026-10-22`
- **Query Parameters:** `from`, `to`, `agent_id`, `approval_status`, `payment_status`, `page`, `limit`

---

### 4.9 Monthly Payroll Reconciliation & Export API
Aggregates settled and disbursed daily incentives for payroll deduction matching. Supports JSON format or direct CSV export.

- **Endpoint:** `GET /report.php?month=2026-10&export=csv`
- **Query Parameters:**
  - `month`: `YYYY-MM`
  - `export`: `json` | `csv`

---

## 5. Background Automation Jobs (CLI)

These scripts run as scheduled tasks or cron jobs to automate end-of-day operations:

### Daily Settlement Cron:
Calculates and settles the previous business day's payouts at 01:00 AM.
```powershell
C:\xampp\php\php.exe c:\xampp\htdocs\demandflowbridge\api\v1\incentives\jobs\daily-settlement.php
```

### Payment Reconciliation Cron:
Queries payment gateway for any pending transfers and syncs status every 30 minutes.
```powershell
C:\xampp\php\php.exe c:\xampp\htdocs\demandflowbridge\api\v1\incentives\jobs\payment-reconciliation.php
```

---

## 6. Automated Test Suite (21 Scenarios)

The test suite validates financial calculation accuracy, edge cases, and safety barriers:

```powershell
C:\xampp\php\php.exe c:\xampp\htdocs\demandflowbridge\api\v1\incentives\tests\run_tests.php
```

### Scenarios Covered:
1. Target achieved exactly (100%) &rarr; Base ₹500 awarded.
2. Below target (<100%) &rarr; ₹0 incentive.
3. Exceeding target (140%) &rarr; Base ₹500 + slab ₹400 = ₹900.
4. Absent agent marked ineligible regardless of points.
5. Active QA escalation with disqualify mode zeroes out incentive.
6. Disqualified lead contributes 0 points.
7. Multiple QA review passes resolve to latest canonical QA status.
8. Idempotent settlement prevents duplicate payout records.
9. Manager approves payout &rarr; status becomes `approved`.
10. Manager rejects payout &rarr; status becomes `rejected`.
11. Bulk approval transitions multiple records.
12. Duplicate disbursement request prevention.
13. Payment gateway pending state handling.
14. Payment failure simulation and error capture.
15. Webhook event deduplication.
16. Monotonic state machine protects against out-of-order webhook reversals.
17. Transfer success confirms `payment_status = 'paid'`.
18. Incomplete beneficiary details halt transfer with descriptive error.
19. Settled/paid records are immutable to subsequent QA audits.
20. Monthly payroll reconciliation correctly aggregates daily disbursed deductions.
21. Final settlement status verification.
