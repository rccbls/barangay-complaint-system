# Barangay Complaint Prioritization and Routing System

Status: **Phases 0-4 built (setup, database, authentication, resident profiles, staff and category management).** Follow `ROADMAP.md` one phase at a time.
Operational rules (categories, priority criteria, transitions, SLAs, resident-visible fields) are
**placeholders pending client approval** and are not official barangay rules.

## Requirements
- XAMPP (or equivalent) with Apache, PHP 8.x, MySQL/MariaDB, and the `pdo_mysql` PHP extension
- Git

## Setup (XAMPP, Windows example)
1. Copy this folder to `C:\xampp\htdocs\barangay-complaint-system`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Copy `.env.example` to `.env`. For the htdocs path above, keep
   `APP_BASE_PATH=/barangay-complaint-system/public`. Adjust `DB_*` if your MySQL differs.
4. Open <http://localhost/barangay-complaint-system/public/setup-check.php> (developer page, only when `APP_ENV=local`).
   It should show PHP running and MySQL connected. The site root (`public/`) sends you to the sign-in page.
5. Optional CLI check: `C:\xampp\php\php.exe tests\phase0_check.php`

Preferred (more secure): point an Apache VirtualHost `DocumentRoot` at the `public/` folder and leave
`APP_BASE_PATH` empty. The root `.htaccess` is only a safety net for the htdocs method.

## Quick start for group members

1. Install XAMPP with **Apache**, **MySQL**, PHP 8.x, and the `pdo_mysql` extension.
2. Clone or copy this project into:
   `C:\xampp\htdocs\barangay-complaint-system`
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Copy `.env.example` to `.env`. For the default XAMPP folder, use:

   ```dotenv
   APP_ENV=local
   APP_BASE_PATH=/barangay-complaint-system/public
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=barangay_complaints
   DB_USER=root
   DB_PASS=
   ```

5. Open PowerShell in the project folder and install the database:

   ```powershell
   C:\xampp\php\php.exe database\install.php
   C:\xampp\php\php.exe database\create_admin.php
   ```

   `create_admin.php` asks for the first administrator username, name, and password.

6. If using an existing database, run the priority-model migration:

   ```powershell
   C:\xampp\php\php.exe database\migrate_priority_model.php
   ```

7. Optional: load demo accounts, categories, complaints, and synthetic historical priority records:

   ```powershell
   C:\xampp\php\php.exe database\seed_sample_data.php
   C:\xampp\php\php.exe database\seed_historical_priority_data.php
   ```

   Synthetic historical data is development-only and requires `APP_ENV=local`.

8. Open the application:
   <http://localhost/barangay-complaint-system/public/>

   Demo accounts created by the sample seeder use the password `Password123!`:
   `admin`, `officer1`, `personnel1`, `mgmt1`, and `resident1`.

For a quick health check, open:
<http://localhost/barangay-complaint-system/public/setup-check.php>

## Database setup (Phase 1)
Run from the project folder (XAMPP on Windows: use `C:\xampp\php\php.exe` in place of `php`):
```
php database/install.php        # creates DB_NAME, runs schema.sql, seeds the 5 roles
php database/create_admin.php   # creates the first administrator (password stored hashed only)
php database/migrate_priority_model.php # adds ML priority assessment fields to an existing database
php database/seed_historical_priority_data.php # adds repeatable synthetic training records (local only)
php tests/phase1_check.php      # verifies tables, foreign keys, roles, one admin
```
- `install.php` is for a **new** database only. It refuses to run if the schema already exists and never drops anything.
- Run `migrate_priority_model.php` once after updating an existing installation. New installations include these fields in `schema.sql`.
- Run `seed_historical_priority_data.php` only with `APP_ENV=local`; it inserts clearly marked synthetic Barangay Manggahan development records and skips references already present.
- Manual alternative: import `database/schema.sql` then `database/seed.sql` in phpMyAdmin (the schema file creates `barangay_complaints`).
- `create_admin.php` asks for username, full name and password. Use a **synthetic** name for development. The password
  is hidden while typing on Linux/macOS; on Windows PHP cannot hide it, so clear the screen afterwards.
  It refuses to run if an Administrator already exists. Minimum length 12 is a **placeholder**, not a client-approved policy.
- `schema.sql` and `seed.sql` are copied exactly from SCHEMA.md §3 and §4. No categories or other data are seeded: those need client approval.

## Authentication (Phase 2)
- Sign in at `login.php` (residents and staff use the same form and land on their own area). Residents can register at `register.php`.
- Staff accounts cannot self-register. Until Phase 4, the only staff account is the administrator from `create_admin.php`.
- **Protecting a page:** call `require_role([...])` (or `require_login()`) right after `require_once .../app/init.php`. It redirects signed-out
  visitors to sign-in and answers 403 to the wrong role. The user is re-read from the database on every request, so deactivation takes effect immediately.
- **Forms:** put `<?= csrf_field() ?>` in every POST form and call `require_csrf()` first when handling it. Escape every output with `e()`.
- **Residents and complaints (reusable rule):** use `resident_owns_complaint()` / `require_resident_owns_complaint($id)`, or filter list queries with
  `submitted_by_user_id = <session user id>`. The user ID must always come from the session, never from the request.
- **Placeholders pending client approval:** registration fields (full name, username, password only), immediate activation of new resident accounts,
  the 12-character password minimum, and which roles see which navigation items.
- `public/dashboard.php` and `public/resident/index.php` are temporary landing pages so each role has a protected page; real dashboards come later.

## Resident profile and account management (Phase 3)
- A resident's profile is the `complainants` row whose `user_id` is the resident's account (UNIQUE, so one profile per account). Registration creates the
  account and profile in one transaction. Complaints (Phase 5) must use `resident_profile_ensure($pdo, <session user id>)` for `complainant_id`.
- Residents edit their own profile at `resident/profile/index.php`. There is no ID in the URL or form; the user always comes from the session.
  Editing the name also updates the account display name.
- Administrators manage resident accounts at `residents/index.php` (search, status filter, pagination) and confirm on `residents/status.php`.
  Deactivating keeps the profile and complaints, signs the resident out on their next request, and blocks sign-in. The list never shows contact numbers or addresses.
  Staff accounts cannot be changed there; staff account management is Phase 4.
- Accounts registered before Phase 3 get a profile on first profile-page visit, or all at once with `php database/backfill_resident_profiles.php` (safe to repeat).
- **Placeholders pending client approval:** profile fields (name, optional contact number, optional address) and who may manage resident accounts (Administrator only).
- **Known gap:** RULES §13 asks for consequential account actions to be recorded with actor and timestamp, but SCHEMA.md has no table for it.
  Until the client approves a schema addition, status changes are logged as IDs only to the PHP/Apache error log (`AUDIT account_status ...`).

## Staff and category management (Phase 4)
Administrator only (every page calls `require_role([ROLE_ADMIN])`; the services re-check the actor's role).
- **Staff accounts** (`personnel/`): list with search, role and status filters, pagination; add, edit (name, role, optional password reset), activate/deactivate with a
  confirmation page. Queries are limited to the four staff roles, so Resident accounts answer 404 and cannot be changed here. New staff cannot be created as Residents.
- **Categories** (`categories/`): add, edit, activate/deactivate (never deleted). Suggested priority and suggested assignee are **suggestions only**; nothing is
  assigned or prioritised automatically. The suggested assignee must be an active Personnel account.
- **"Valid handling path" guards** (PLACEHOLDER rules pending client approval): you cannot deactivate your own account or change your own role; the last active
  Administrator cannot be removed; the last active Complaint Officer cannot be removed while open complaints exist; Personnel with open assignments, or who are the
  suggested assignee of an active category, cannot be deactivated or moved off the Personnel role; a category cannot be deactivated while open complaints use it or
  when it is the only active category. "Open" means any status except Closed and Rejected / Invalid (`OPEN_COMPLAINT_CONDITION` in `config/app.php`).
  The refusal reason is shown to the administrator.
- Priority levels are the placeholders in `PRIORITY_LEVELS` (`config/app.php`); the client must define them (Phase 10).
- Account and category changes are logged as IDs only to the server error log (`AUDIT ...`) until the client approves an audit table (see Phase 3 note).
- Staff passwords are set by the administrator (create or reset). There is still no self-service password change; that needs a client decision.

## Tests
```
php tests/phase0_check.php
php tests/phase1_check.php
php tests/phase2_check.php http://localhost/barangay-complaint-system/public/
php tests/phase3_check.php http://localhost/barangay-complaint-system/public/
php tests/phase4_check.php http://localhost/barangay-complaint-system/public/
```

`phase2_check.php`, `phase3_check.php` and `phase4_check.php` drive the real pages over HTTP and need `APP_ENV=local`. They create synthetic users (`p2test_*`, `p3test_*`, `p4test_*`) and delete them afterwards.

## Complaint priority model

New complaints receive an explainable predicted priority (`Low`, `Medium`, `High`, or
`Critical`) from seven factors: category/type, severity, affected residents, immediate
danger, community impact, location, and similar recent complaints. The prediction and
score are stored separately from the official priority. Immediate-danger complaints are
flagged for urgent human review, and authorized personnel confirm or override the
priority with a required reason.

`app/Services/PriorityScoringService.php` is the model boundary. It currently provides a
deterministic baseline while historical Barangay Manggahan records and official labels
are assembled; a trained model can replace the scorer without changing the complaint
workflow or database contract.

## Git
```
git init
git add .
git commit -m "Phase 0: project setup"
```
`.env` is git-ignored; only `.env.example` is committed. Never commit real credentials.

## Timezone convention (confirm with client)
- **Stored:** UTC. PHP runs in UTC and each DB connection runs `SET time_zone = '+00:00'`.
- **Displayed:** Philippine time (`Asia/Manila`) via `format_display_datetime()` in `app/bootstrap.php`.

## Structure
See ARCHITECTURE.md §6. Empty folders hold `.gitkeep` until their phase.

| Path | Phase 0 contents |
|---|---|
| `public/index.php` | Redirects to the user's home page or to sign-in |
| `public/login.php`, `register.php`, `logout.php` | Phase 2 authentication pages |
| `public/dashboard.php`, `public/resident/index.php` | Placeholder landing pages (staff / resident) |
| `public/resident/profile/` | Resident profile page (Phase 3) |
| `public/personnel/`, `public/categories/` | Administrator staff-account and category management (Phase 4) |
| `public/residents/` | Administrator resident-account list and activate/deactivate (Phase 3) |
| `public/setup-check.php` | Phase 0 developer page (404 unless `APP_ENV=local`) |
| `public/assets/` | `css/app.css`, vendored Bootstrap 5.3.8 (MIT) in `vendor/bootstrap/` |
| `app/bootstrap.php` | `.env` loading, timezone, error policy, `app_url()`, `e()` escaping helper, `redirect()` |
| `app/init.php` | Include for every session-using page (loads everything below, starts the session) |
| `app/Services/` | `Session.php` (session, CSRF, flash), `Authorization.php` (roles, resident ownership rule), `AuthService.php`, `ResidentService.php`, `StaffService.php`, `CategoryService.php`, `Validation.php` |
| `app/Repositories/` | `UserRepository.php`, `ResidentRepository.php` (profiles, resident accounts), `StaffRepository.php`, `CategoryRepository.php`; prepared statements only |
| `app/Middleware/auth.php` | `require_login()`, `require_role()`, `require_csrf()`, `require_guest()` |
| `app/Views/layouts/` | Shared `header.php` / `footer.php` (public, resident, staff areas) |
| `app/Views/partials/nav_items.php` | Planned navigation per area (disabled placeholders) |
| `config/` | `app.php` constants, `database.php` (PDO) |
| `tests/phase0_check.php` | CLI check of the Phase 0 "Done when" conditions |
| `database/` | `schema.sql`, `seed.sql`, `install.php`, `create_admin.php` (Phase 1); `backfill_resident_profiles.php` (Phase 3) |
| `tests/phase1_check.php` | CLI check of the Phase 1 "Done when" conditions |
| `tests/phase2_check.php`, `phase3_check.php`, `phase4_check.php` | HTTP-driven checks of the Phase 2-4 "Done when" conditions (`tests/support/http.php` is the shared client) |

## Deliberately not built yet
Complaint pages, assignment, status workflow and everything after Phase 4. There is also no password change/reset flow: it is not in the roadmap and needs a client decision.
The layout preview links on the setup page (`?layout=resident|staff`) only work when `APP_ENV=local`.
