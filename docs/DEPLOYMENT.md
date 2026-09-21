# Deployment Guide

This project is a PHP/MySQL CRM intended to run behind Apache (XAMPP/LAMP) with `mod_rewrite` enabled.

## 1) Choose a Deployment Mode

### A) Local “Live” (LAN / same PC)

- URL: `http://localhost/leads`
- Use XAMPP Apache + MySQL.
- Best for testing before going public.

### B) Production (Public Domain)

- URL: `https://yourdomain.com`
- Use a VPS (recommended) or cPanel hosting with PHP + MySQL.

### C) Hostinger cPanel (your plan)

- URL: `https://app.tarajglobal.com/demandflowbridge`
- Hosting: Hostinger cPanel / hPanel
- Code path: subdomain document root + `/demandflowbridge` folder

## 2) Server Requirements

- PHP 8.0+ (7.4 works but newer PHP is recommended)
- Extensions: `mysqli`, `mbstring`, `fileinfo`, `openssl`
- MySQL 5.7+ / MariaDB 10+
- Apache `mod_rewrite` enabled and `AllowOverride All` for the project directory

## 3) Upload the Code

Place the project so the web root points to the `leads/` folder:

- XAMPP: `C:\xampp\htdocs\leads`
- VPS: `/var/www/leads` (and configure a vhost)
- cPanel: `/public_html/leads`

### Hostinger specific path (recommended)

1. Create subdomain: `app.tarajglobal.com`
2. Confirm the subdomain document root (Hostinger will show it)
3. Inside that document root, create folder: `demandflowbridge`
4. Upload the project files into that folder so this works:
   - `https://app.tarajglobal.com/demandflowbridge/index.php`

Confirm these exist and are writable by the web server:

- `uploads/`
- `tmp/sessions/`
- `tmp/uploads/`
- `storage/`

## 4) Database Setup (Tables + Data)

### Recommended (fastest): Migrate your existing database

If you already have a working local database with all tables:

1. Export the database from local phpMyAdmin:
   - Export format: SQL
   - Include structure + data
2. Create a new database and user on the server (avoid using `root`).
3. Import the SQL on the server phpMyAdmin.

If import fails with `DEFINER` / `SET USER` / procedure errors on shared hosting, re-export without server-side objects:

- In phpMyAdmin export options: disable `Routines`, `Triggers`, `Events`
- Or sanitize the dump locally:
  - `php tools/sanitize_sql_dump.php input.sql output.sql`
  - Import `output.sql` on Hostinger

If you want a clean structure-only schema (no data), you can use:

- `docs/database_schema_clean.sql`

### Optional: Run schema bootstrap / upgrades

After import, visit (admin only):

- `/modules/admin/setup.php`

This runs the built-in schema “ensure” functions and creates any missing supporting tables used by newer features.

### Hostinger note

This project uses many tables beyond the core ones. The most reliable production setup is:

- Export your working local `leads` database (structure + data)
- Import it into the live MySQL database
- Then visit `/modules/admin/setup.php` once to apply any missing “ensure” upgrades

## 5) Configure Database Credentials

Update:

- `config/database.php`

Set:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

Production note: do not use `root` and do not keep empty password.

### Hostinger cPanel credentials

In Hostinger, your MySQL username is usually prefixed (example: `u1234567_leadsuser`). Use exactly what Hostinger shows.

## 6) Enable Clean URLs (No .php)

This repo includes an `.htaccess` that enables extensionless routing:

- `/modules/leads/export` → `/modules/leads/export.php`

Requirements:

- Apache `mod_rewrite` enabled
- `AllowOverride All` for this directory

### Hostinger note

Hostinger often runs LiteSpeed, which honors `.htaccess`. If clean URLs do not work:

- Confirm Rewrite is enabled for the subdomain
- Confirm `.htaccess` exists inside `/demandflowbridge/`

## 7) First Login + Smoke Test

1. Open: `/` → redirects to login
2. Login with an existing admin user (from your imported DB)
3. Smoke-test core flows:
   - Campaigns → Manage, Details, Delivery upload/download
   - Leads → Bulk upload template, upload small CSV, My Leads
   - QA → QA dashboard + QA action
   - Notifications → toast popup + notification list
   - Chat → send message + attachment
   - HR → attendance + payroll pages
   - Revenue → invoice view + PDF

### Hostinger PHP settings (recommended)

In cPanel, set these via MultiPHP INI Editor / PHP options:

- `upload_max_filesize`: at least `20M`
- `post_max_size`: at least `25M`
- `max_execution_time`: `120`
- `memory_limit`: `256M`

## 8) Production Hardening Checklist

- Use HTTPS (valid certificate)
- Confirm `session.cookie_secure` is enabled under HTTPS
- Disable public registration if not required (`/modules/auth/register.php`)
- Restrict admin access by IP if needed
- Set correct file permissions and prevent listing in `uploads/` if your server allows it

