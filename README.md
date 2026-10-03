# EcoTrack MRF Management System

EcoTrack is a PHP/XAMPP waste-management system for Brgy San Manuel. It includes login, role-based admin/staff pages, waste data tracking, route views, reports, announcements, tasks, user management, password reset email, and light/dark theme support.

## Main Features

- Admin and staff authentication with session timeout
- Admin dashboard, collection scheduling DSS, waste records, flexible file upload, heatmap, operations reports, and staff management
- Staff profile, waste data, daily tasks, route schedule, and announcements
- Password reset email using PHPMailer
- Shared EcoTrack sidebar, consistent city-hall logo, and modern light/dark theme

## Project Structure

```text
EcoTrack/
├── .htaccess
├── assets/css/ecotrack-theme.css
├── includes/sidebar.php
├── includes/theme_head.php
├── config.php
├── database_setup.sql
├── waste_data_corrected.sql
├── login.php
├── admin_dashboard.php
├── collection_schedule.php
├── staff_home.php
├── staff_waste_records.php
├── staff_daily_tasks.php
├── staff_waste_heatmap.php
├── staff_announcements.php
├── staff_settings.php
├── waste_records.php
├── waste_import.php
├── waste_heatmap.php
├── route_planning_redirect.php
├── role_landing_redirect.php
├── operations_reports.php
├── user_management.php
├── system_settings.php
├── password_reset_request.php
├── password_reset_verification.php
├── password_reset.php
└── PHPMailer/
    ├── LICENSE
    └── src/
```

## Setup

1. Start Apache and MySQL in XAMPP.
2. Import `database_setup.sql` into phpMyAdmin.
3. Import `waste_data_corrected.sql` only if you need to create an empty `waste_records` table. It is safe to import and does not delete existing records or add sample data.
4. Open `http://localhost/EcoTrack/login.php`.

For an existing installation with waste records, run the duplicate-protection backfill once from the project directory:

```powershell
C:\xampp\php\php.exe scripts\backfill_waste_record_fingerprints.php
```

To preview and then repair historical records saved as `3-Day Block: Friday – Saturday`:

```powershell
C:\xampp\php\php.exe scripts\migrate_three_day_collection_groups.php
C:\xampp\php\php.exe scripts\migrate_three_day_collection_groups.php --apply
```

After upgrading an existing installation, classify legacy detailed groups and
refresh their duplicate fingerprints once:

```powershell
C:\xampp\php\php.exe scripts\backfill_waste_group_types.php
C:\xampp\php\php.exe scripts\backfill_waste_group_types.php --apply
```

### Large waste imports

EcoTrack validates and stages imports in database chunks and displays only the
first 100 validated rows. The application accepts files up to **250 MB**. In
XAMPP's active `php.ini`, set `upload_max_filesize = 250M`, `post_max_size =
260M`, `max_input_time = 600`, and `max_execution_time = 600`, then restart
Apache. Keep `memory_limit` high enough for workbook metadata (512M is a
practical XAMPP setting); the importer does not retain all normalized rows in
the PHP session. Excel workbooks are also rejected when their uncompressed ZIP
contents exceed 1 GB, protecting the server from compressed workbook bombs.

The setup schema creates no default accounts. For a fresh installation, create
the first administrator through the deployment procedure rather than using a
published default password.

### AI dashboard explanations

The Dashboard's **Explain** buttons use the OpenAI API when `OPENAI_API_KEY` is configured in Apache (do not put the key in JavaScript or commit it to this repository). Optionally set `OPENAI_MODEL` to override the default `gpt-4o-mini` model. Without an API key, EcoTrack automatically uses a rule-based explanation generated from the same live dashboard data, so the feature still works without API cost.

## Notes

- For production deployment on Oracle Cloud Always Free, follow
  [`docs/oracle-cloud-deployment.md`](docs/oracle-cloud-deployment.md). It keeps
  source code public while moving database exports, uploads, backups, and
  credentials directly to the server.
- The shared sidebar lives in `includes/sidebar.php`; update menu labels/icons there instead of editing every page.
- The shared theme lives in `assets/css/ecotrack-theme.css`.
- Application routes use descriptive lowercase `snake_case` filenames. `.htaccess` redirects the previous route names so existing bookmarks continue to work.
- Reports is an admin Operations & Data Quality hub. It includes import audit
  outcomes, current completeness checks, and the existing staff-report review
  workflow instead of duplicating Dashboard waste charts.
- PHPMailer is intentionally trimmed to the runtime files required by `password_reset_request.php`.
