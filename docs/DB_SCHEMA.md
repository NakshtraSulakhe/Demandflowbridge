# Database Schema Snapshot

- Database: `leads`
- Generated at: `2026-03-16T19:39:23+01:00`

## `campaigns`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `name` | `varchar(255)` | NO | `NULL` | `` | `` |
| `active` | `tinyint(1)` | NO | `0` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `campaign_delivery_files`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `campaign_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `uploader_id` | `int(11)` | NO | `NULL` | `` | `` |
| `format` | `varchar(40)` | YES | `NULL` | `` | `` |
| `notes` | `text` | YES | `NULL` | `` | `` |
| `file_path` | `varchar(255)` | NO | `NULL` | `` | `` |
| `created_at` | `datetime` | NO | `current_timestamp()` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `idx_campaign` | NO | `campaign_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `campaign_details`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `campaign_id` | `int(11)` | NO | `NULL` | `` | `PRI` |
| `code` | `varchar(32)` | NO | `NULL` | `` | `UNI` |
| `status` | `varchar(20)` | NO | `'Draft'` | `` | `MUL` |
| `start_date` | `date` | YES | `NULL` | `` | `` |
| `end_date` | `date` | YES | `NULL` | `` | `` |
| `total_leads` | `int(11)` | YES | `NULL` | `` | `` |
| `pacing_type` | `varchar(20)` | YES | `NULL` | `` | `` |
| `pacing_count` | `int(11)` | YES | `NULL` | `` | `` |
| `cpc` | `decimal(10,2)` | YES | `NULL` | `` | `` |
| `cpl` | `decimal(10,2)` | YES | `NULL` | `` | `` |
| `cpl_currency` | `varchar(8)` | YES | `NULL` | `` | `` |
| `campaign_type` | `varchar(40)` | YES | `NULL` | `` | `MUL` |
| `delivery_format` | `varchar(40)` | YES | `NULL` | `` | `` |
| `targeted_country` | `text` | YES | `NULL` | `` | `` |
| `job_title` | `varchar(255)` | YES | `NULL` | `` | `` |
| `departments` | `text` | YES | `NULL` | `` | `` |
| `seniority_levels` | `text` | YES | `NULL` | `` | `` |
| `industries` | `text` | YES | `NULL` | `` | `` |
| `employee_sizes` | `text` | YES | `NULL` | `` | `` |
| `revenue_sizes` | `text` | YES | `NULL` | `` | `` |
| `instruction` | `text` | YES | `NULL` | `` | `` |
| `script_path` | `varchar(255)` | YES | `NULL` | `` | `` |
| `tal_path` | `varchar(255)` | YES | `NULL` | `` | `` |
| `suppression_path` | `varchar(255)` | YES | `NULL` | `` | `` |
| `recording_path` | `varchar(255)` | YES | `NULL` | `` | `` |
| `custom_fields_json` | `text` | YES | `NULL` | `` | `` |
| `created_at` | `datetime` | NO | `current_timestamp()` | `` | `` |
| `updated_at` | `datetime` | YES | `NULL` | `` | `` |
| `client_code` | `varchar(50)` | YES | `NULL` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `code` | YES | `code` | 1 | `BTREE` |
| `idx_campaign_type` | NO | `campaign_type` | 1 | `BTREE` |
| `idx_code` | YES | `code` | 1 | `BTREE` |
| `idx_status` | NO | `status` | 1 | `BTREE` |
| `PRIMARY` | YES | `campaign_id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `campaign_forms`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `campaign_id` | `int(11)` | NO | `NULL` | `` | `PRI` |
| `form_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `assigned_at` | `datetime` | NO | `current_timestamp()` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `campaign_id` | YES | `campaign_id` | 1 | `BTREE` |
| `idx_form` | NO | `form_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `campaign_id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `campaign_metrics`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `campaign_id` | `int(11)` | NO | `NULL` | `` | `PRI` |
| `delivered` | `int(11)` | YES | `NULL` | `` | `` |
| `generated` | `int(11)` | YES | `NULL` | `` | `` |
| `qualified` | `int(11)` | YES | `NULL` | `` | `` |
| `disqualified` | `int(11)` | YES | `NULL` | `` | `` |
| `pending` | `int(11)` | YES | `NULL` | `` | `` |
| `rejected` | `int(11)` | YES | `NULL` | `` | `` |
| `updated_by` | `int(11)` | YES | `NULL` | `` | `` |
| `updated_at` | `datetime` | NO | `current_timestamp()` | `on update current_timestamp()` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `PRIMARY` | YES | `campaign_id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `campaign_revenue`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `campaign_id` | `int(11)` | NO | `NULL` | `` | `PRI` |
| `revenue` | `decimal(12,2)` | YES | `NULL` | `` | `` |
| `currency` | `varchar(8)` | YES | `NULL` | `` | `` |
| `updated_by` | `int(11)` | YES | `NULL` | `` | `` |
| `updated_at` | `datetime` | NO | `current_timestamp()` | `on update current_timestamp()` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `PRIMARY` | YES | `campaign_id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `chat_messages`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `sender_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `receiver_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `message` | `text` | YES | `NULL` | `` | `` |
| `attachment_path` | `varchar(255)` | YES | `NULL` | `` | `` |
| `delivered_at` | `datetime` | YES | `NULL` | `` | `` |
| `read_at` | `datetime` | YES | `NULL` | `` | `` |
| `created_at` | `datetime` | YES | `current_timestamp()` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `idx_receiver` | NO | `receiver_id` | 1 | `BTREE` |
| `idx_sender` | NO | `sender_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `clients`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `client_code` | `varchar(50)` | NO | `NULL` | `` | `UNI` |
| `name` | `varchar(200)` | NO | `NULL` | `` | `` |
| `website` | `varchar(255)` | YES | `NULL` | `` | `` |
| `industry` | `varchar(120)` | YES | `NULL` | `` | `` |
| `notes` | `text` | YES | `NULL` | `` | `` |
| `created_by` | `int(11)` | YES | `NULL` | `` | `` |
| `created_at` | `datetime` | NO | `current_timestamp()` | `` | `` |
| `updated_at` | `datetime` | YES | `NULL` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `client_code` | YES | `client_code` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `client_contacts`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `client_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `name` | `varchar(180)` | NO | `NULL` | `` | `` |
| `email` | `varchar(255)` | YES | `NULL` | `` | `` |
| `phone` | `varchar(50)` | YES | `NULL` | `` | `` |
| `title` | `varchar(120)` | YES | `NULL` | `` | `` |
| `created_at` | `datetime` | NO | `current_timestamp()` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `idx_client` | NO | `client_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `client_tags`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `client_id` | `int(11)` | NO | `NULL` | `` | `PRI` |
| `tag_id` | `int(11)` | NO | `NULL` | `` | `PRI` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `idx_tag` | NO | `tag_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `client_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `tag_id` | 2 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `forms`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `name` | `varchar(150)` | NO | `NULL` | `` | `` |
| `fingerprint` | `char(64)` | NO | `NULL` | `` | `UNI` |
| `schema_json` | `mediumtext` | NO | `NULL` | `` | `` |
| `created_by` | `int(11)` | YES | `NULL` | `` | `` |
| `created_at` | `datetime` | NO | `current_timestamp()` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `fingerprint` | YES | `fingerprint` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `form_submissions`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `form_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `campaign_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `lead_id` | `int(11)` | YES | `NULL` | `` | `MUL` |
| `submitted_by` | `int(11)` | NO | `NULL` | `` | `` |
| `submitted_at` | `datetime` | NO | `current_timestamp()` | `` | `` |
| `data_json` | `mediumtext` | NO | `NULL` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `idx_campaign` | NO | `campaign_id` | 1 | `BTREE` |
| `idx_form` | NO | `form_id` | 1 | `BTREE` |
| `idx_lead` | NO | `lead_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `leads`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `lead_id` | `varchar(20)` | NO | `NULL` | `` | `` |
| `campaign_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `campaign_name` | `varchar(100)` | NO | `NULL` | `` | `` |
| `agent_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `agent_name` | `varchar(100)` | NO | `NULL` | `` | `` |
| `first_name` | `varchar(50)` | NO | `NULL` | `` | `` |
| `last_name` | `varchar(50)` | NO | `NULL` | `` | `` |
| `job_title` | `varchar(150)` | YES | `NULL` | `` | `` |
| `email` | `varchar(150)` | YES | `NULL` | `` | `` |
| `linkedin_link` | `text` | YES | `NULL` | `` | `` |
| `contact_phone` | `varchar(20)` | YES | `NULL` | `` | `` |
| `industry` | `varchar(100)` | YES | `NULL` | `` | `` |
| `company_linkedin` | `text` | YES | `NULL` | `` | `` |
| `company_name` | `varchar(150)` | YES | `NULL` | `` | `` |
| `company_size` | `varchar(50)` | YES | `NULL` | `` | `` |
| `country` | `varchar(100)` | YES | `NULL` | `` | `` |
| `software_implementation_timeline` | `varchar(20)` | YES | `NULL` | `` | `` |
| `recording_path` | `varchar(255)` | YES | `NULL` | `` | `` |
| `qa_status` | `enum('Qualified','Disqualified','Rework Needed','Duplicate','Pending')` | YES | `'Pending'` | `` | `MUL` |
| `qa_comment` | `text` | YES | `NULL` | `` | `` |
| `qa_updated_at` | `timestamp` | YES | `NULL` | `` | `` |
| `qa_reviewed_by` | `int(11)` | YES | `NULL` | `` | `` |
| `form_done` | `enum('Yes','No')` | YES | `'No'` | `` | `MUL` |
| `ip_address` | `varchar(50)` | YES | `NULL` | `` | `` |
| `form_filled_time` | `timestamp` | YES | `NULL` | `` | `` |
| `created_at` | `timestamp` | NO | `current_timestamp()` | `` | `MUL` |
| `updated_at` | `timestamp` | NO | `current_timestamp()` | `on update current_timestamp()` | `` |
| `lead_comment` | `text` | YES | `NULL` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `idx_agent_id` | NO | `agent_id` | 1 | `BTREE` |
| `idx_campaign_id` | NO | `campaign_id` | 1 | `BTREE` |
| `idx_created_at` | NO | `created_at` | 1 | `BTREE` |
| `idx_form_done` | NO | `form_done` | 1 | `BTREE` |
| `idx_qa_status` | NO | `qa_status` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `login_attempts`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `ip_address` | `varchar(45)` | NO | `NULL` | `` | `` |
| `username` | `varchar(50)` | YES | `NULL` | `` | `` |
| `attempt_time` | `timestamp` | NO | `current_timestamp()` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `productivity_targets`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `agent_id` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `year` | `int(11)` | NO | `NULL` | `` | `MUL` |
| `month` | `int(11)` | NO | `NULL` | `` | `` |
| `working_days` | `int(11)` | NO | `NULL` | `` | `` |
| `daily_target` | `int(11)` | NO | `NULL` | `` | `` |
| `monthly_target` | `int(11)` | NO | `NULL` | `` | `` |
| `minimum_target` | `int(11)` | YES | `NULL` | `` | `` |
| `assigned_by` | `int(11)` | NO | `NULL` | `` | `` |
| `created_at` | `datetime` | NO | `current_timestamp()` | `` | `` |
| `updated_at` | `datetime` | YES | `NULL` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `idx_year_month` | NO | `year` | 1 | `BTREE` |
| `idx_year_month` | NO | `month` | 2 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |
| `uniq_agent_month` | YES | `agent_id` | 1 | `BTREE` |
| `uniq_agent_month` | YES | `year` | 2 | `BTREE` |
| `uniq_agent_month` | YES | `month` | 3 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `tags`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `name` | `varchar(80)` | NO | `NULL` | `` | `UNI` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `name` | YES | `name` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `users`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `username` | `varchar(50)` | NO | `NULL` | `` | `UNI` |
| `password` | `varchar(255)` | NO | `NULL` | `` | `` |
| `full_name` | `varchar(100)` | NO | `NULL` | `` | `` |
| `email` | `varchar(100)` | NO | `NULL` | `` | `` |
| `role` | `enum('admin','agent','qa','form_filler')` | NO | `NULL` | `` | `` |
| `is_active` | `tinyint(1)` | YES | `1` | `` | `` |
| `is_locked` | `tinyint(1)` | YES | `0` | `` | `` |
| `locked_until` | `timestamp` | YES | `NULL` | `` | `` |
| `created_at` | `timestamp` | NO | `current_timestamp()` | `` | `` |
| `updated_at` | `timestamp` | NO | `current_timestamp()` | `on update current_timestamp()` | `` |
| `profile_pic` | `varchar(255)` | YES | `NULL` | `` | `` |
| `employee_id` | `varchar(50)` | YES | `NULL` | `` | `UNI` |
| `date_of_joining` | `date` | YES | `NULL` | `` | `` |
| `job_title` | `varchar(100)` | YES | `NULL` | `` | `` |
| `phone_number` | `varchar(20)` | YES | `NULL` | `` | `` |
| `department` | `varchar(100)` | YES | `NULL` | `` | `` |
| `address` | `text` | YES | `NULL` | `` | `` |
| `emergency_contact` | `varchar(100)` | YES | `NULL` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `employee_id` | YES | `employee_id` | 1 | `BTREE` |
| `PRIMARY` | YES | `id` | 1 | `BTREE` |
| `username` | YES | `username` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `user_presence`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `user_id` | `int(11)` | NO | `NULL` | `` | `PRI` |
| `last_seen` | `datetime` | NO | `current_timestamp()` | `` | `` |
| `is_online` | `tinyint(1)` | NO | `0` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `PRIMARY` | YES | `user_id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)

## `user_sessions`

### Columns

| Column | Type | Nullable | Default | Extra | Key |
|---|---|---:|---|---|---|
| `id` | `int(11)` | NO | `NULL` | `auto_increment` | `PRI` |
| `user_id` | `int(11)` | NO | `NULL` | `` | `` |
| `ip_address` | `varchar(45)` | NO | `NULL` | `` | `` |
| `user_agent` | `text` | YES | `NULL` | `` | `` |
| `login_time` | `timestamp` | NO | `current_timestamp()` | `` | `` |
| `logout_time` | `timestamp` | YES | `NULL` | `` | `` |

### Indexes

| Index | Unique | Column | Seq | Type |
|---|---:|---|---:|---|
| `PRIMARY` | YES | `id` | 1 | `BTREE` |

### Foreign Keys

- (none detected in INFORMATION_SCHEMA)
