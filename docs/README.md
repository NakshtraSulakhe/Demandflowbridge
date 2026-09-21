# DemandFlow Bridge

A PHP/MySQL-based CRM for managing leads, with role-based access control and quality assurance features.

## Features

- **Role-based Authentication**: Admin, QA, Agent, and Form Filler roles
- **Lead Submission**: Form for agents to submit leads with recording uploads
- **QA Dashboard**: Review and rate lead quality with recording playback
- **Agent View**: Agents can view their own leads and quality ratings
- **Admin Interface**: Full lead management and editing capabilities
- **Bulk Upload**: Import leads via CSV files

## Installation

1. **Database Setup**:
   - Import the `database_schema.sql` file into your MySQL database
   - This will create the necessary tables and default admin user

2. **Configuration**:
   - Update the database connection settings in `config/database.php`
   - Ensure the web server has write permissions to the `uploads/recordings` directory

3. **Default Login**:
   - Username: `admin`
   - Password: `admin123`
   - **Important**: Change the default password after first login

## Directory Structure

- `/config`: Database configuration
- `/includes`: Core functionality and helper functions
- `/uploads/recordings`: Storage for lead recordings

## User Roles

1. **Admin**:
   - Access to all features
   - Can manage leads, view QA dashboard, and perform bulk uploads

2. **QA**:
   - Access to QA dashboard
   - Can review and rate lead quality

3. **Agent**:
   - Can submit leads
   - Can view their own leads and quality ratings

4. **Form Filler**:
   - Can only submit leads

## Bulk Upload Format

Recommended CSV columns (canonical field names):
- `lead_id` (optional; auto-generated if omitted)
- `campaign_name` or `campaign_id` (one required)
- `agent_name` or `agent_id` (one required)
- `first_name` (required)
- `last_name` (required)
- `job_title` (optional)
- `email` (optional)
- `linkedin_link` (optional)
- `contact_phone` (optional; legacy alias `phone` accepted)
- `industry` (optional)
- `company_linkedin` (optional)
- `company_name` (optional)
- `company_size` (optional)
- `country` (optional)
- `software_implementation_timeline` (optional)
- `recording_path` (optional; absolute or site-relative path to audio)
- `qa_status` (optional; one of `Qualified`, `Disqualified`, `Pending`, `Rework Needed`, `Duplicate`, `Rectified`)
- `qa_comment` (optional)
- `form_done` (optional; `Yes` or `No`)
- `ip_address` (optional)
- `form_filled_time` (optional; `YYYY-MM-DD HH:MM:SS` or HTML5 `datetime-local`)

Notes:
- If `campaign_name` is provided, it will be resolved to `campaign_id`; similarly for `agent_name`.
- `qa_status` and `form_done` values are normalized automatically.
- Success and failure CSV reports are generated after bulk upload in `/uploads/bulk_reports/`.

## Security Notes

- Role-based access control with session protections
- Passwords stored using `password_hash()`
- CSRF protection for lead submission forms
- Strict input validation across forms
- Recording file uploads are restricted to audio types and allowed extensions

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- Browser with HTML5 audio support for recording playback
