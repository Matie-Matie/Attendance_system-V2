# Attendance System V2 (SCPLS v2)

**Smart Class Presence Log-in System** — QR-code based student attendance tracking for **Monico Rarama National High School**, Academic Year 2026–2027.

**Runs on your own PC:** install XAMPP (PHP + MySQL), then double-click **`start.bat`** — first run prepares the database itself and opens http://127.0.0.1:8000. Logins are below.

> **Updates:** download the latest copy of this repo and run `start.bat` again. Your students, teachers and attendance stay in MySQL — updating the files never deletes them.
>
> (Older web demo: https://scpls.xo.je — 2026-10-14 build. The school now runs it locally.)

## October 2026 revisions (teacher feedback, round 2 — 2026-10-21)

- **Full name on the front page** — the login page, browser tab and sidebars now say *Smart Class Presence Log-in System · Monico Rarama National High School* instead of just "SCPLS".
- **Bigger, readable scale** — base type is now 18px (was 17px). The old admin-dashboard "compact" layer used to shrink text to 7–10px to fit one screen; it no longer shrinks, so the dashboard is taller and readable instead of squeezed.
- **No more zooming** — layouts reflow at 125% / 150% browser zoom (≈1093px and ≈911px wide) instead of clipping. Nothing is hidden horizontally; wide tables scroll inside their own card.
- **Absences are visible** — teachers get an **Absent Today** panel on the dashboard (their own classes only, grouped per class) plus an **Absent** stat card, an absent filter and absent rows in print/CSV on their reports page.
- **"Teachers Master Schedule"** — the menu item and page title now say the full name.
- **Progress bars redesigned** — the teacher "Day progress" line is now a school-day timeline (7 AM → 5 PM with a live "now" marker; hover it for the current time and time left), and the admin "By Section" bars are colour-coded meters (green ≥90%, amber ≥70%, red below) that show the present/late/absent breakdown on hover and open that section's report when clicked.
- **Dashboard layout fixed** — cards now sit in two independent columns, so short cards no longer leave holes and tall lists no longer stretch their neighbours; long lists scroll inside their own card.
- **Class labels** — reports print one clean label (`G11 HUMSS · RM 201`); the grade was previously printed twice and rooms read "Room RM 201".

Previous round (2026-10-14): bigger readable layout, absent-today dashboards, room + teacher + section schedule-conflict blocking, Teachers Master Schedule view, printable reports with class labels, CSV import for students/teachers, kiosk auto-clear, login/logout audit trail, subject/student detail pages, deep-linked records.

Students scan their QR code at a kiosk (or teachers scan in-class). The system resolves the student → their section → the active class session, records present/late, and queues parent email notifications for arrival + dismissal.

## Features

- **QR kiosk scanning** (`public/student/scan.php` + `public/api/scan.php`) — duplicate-safe (UNIQUE key + pre-check), on-time vs. late resolution
- **Admin dashboard** — students, teachers, subjects, sections, school years, semesters, master schedule, QR code generation, reports, audit logs
- **Teacher flow** — dashboard, start/end attendance sessions (scoped to own classes only), take attendance, reports
- **Parent notifications** — max 2 emails per school day per parent (arrival + dismissal), sent via Gmail SMTP; cron/task endpoint at `public/tasks/notifications.php`
- **Reports** — per-class / per-student attendance summaries
- **Audit logs + SMS logs** tables for traceability
- **Auth** — role-based (admin / teacher), bcrypt passwords, sessions with HttpOnly + SameSite=Strict cookies, CSRF protection

## Tech Stack

| Layer | Choice |
|---|---|
| Language | PHP 8.x (no framework — vanilla PHP + PSR-4-style autoloader in `bootstrap.php`) |
| Database | MySQL 8 / MariaDB (`utf8mb4`) |
| Frontend | Server-rendered PHP + vanilla CSS/JS (`public/assets/`) |
| Mail | Gmail SMTP + App Password (587/TLS) |
| Entry point | `public/` is the web root — `/` redirects to `/auth/login.php` |

## Project Structure

```
attendance-system-v2/
├── bootstrap.php          # autoloader, session, config, fatal-error safety net
├── setup.php              # one-shot DB setup (drop → create → schema → seed)
├── config/
│   ├── app.php            # app name, timezone, attendance windows, mail/SMTP
│   └── database.php       # DB host/port/name/credentials
├── database/
│   ├── schema.sql         # full schema (14 tables)
│   ├── seed.sql           # demo data (roles, users, subjects, sections, SY/sem)
│   └── migrations/        # incremental migrations
├── includes/              # shared layouts, components, partials
├── public/                # 🌐 WEB ROOT (point your vhost here)
│   ├── index.php          # redirects to login
│   ├── auth/              # login / logout
│   ├── admin/             # admin pages
│   ├── teacher/           # teacher pages
│   ├── student/           # QR kiosk scan page
│   ├── api/scan.php       # scan endpoint (JSON)
│   ├── tasks/             # cron endpoint (notifications)
│   └── assets/            # css / js / images
├── src/
│   ├── Core/Database.php
│   └── Helpers/           # auth, validation, sms, functions
└── storage/
```

### Database tables

`roles`, `users`, `teachers`, `subjects`, `sections`, `school_years`, `semesters`, `students`, `schedule_entries`, `attendance_sessions`, `attendance_records`, `sms_logs`, `settings`, `audit_logs`

## Getting Started

### Requirements

- PHP 8.1+ with `pdo_mysql`
- MySQL 8 / MariaDB
- A Gmail account with 2-Step Verification + App Password (for parent emails)

### 1. Clone

```bash
git clone https://github.com/Matie-Matie/Attendance_system-V2.git
cd Attendance_system-V2
```

### 2. Configure

Copy-edit the two config files (values are local-dev defaults — **change credentials before deploying**):

- `config/database.php` — host, port, dbname (`scpls_v2`), username, password
- `config/app.php` — timezone, attendance windows, `mail` + `smtp` settings, `task_key`

> ⚠️ Never commit real SMTP passwords / app passwords to a public repo. Use environment variables or a non-tracked local config in production.

### 3. Set up the database

```bash
php setup.php
```

This drops (if exists) → creates `scpls_v2` → runs `database/schema.sql` → loads `database/seed.sql`.

To apply later changes only: run the files in `database/migrations/`.

### 4. Serve

Point your web server's document root at `public/`, e.g.:

```bash
php -S localhost:8000 -t public
```

Then open http://localhost:8000 → redirects to the login page.

### 5. Demo logins (seed data — change immediately)

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `admin123` |
| Teacher | `teacher1` | `teacher123` |
| Teacher | `teacher2` | `teacher123` |

## How Attendance Works

1. Admin builds the master schedule (`schedule_entries`: teacher + subject + section + day + time).
2. Teacher starts a session for a class → creates an `attendance_sessions` row.
3. Student QR scan → `POST /api/scan.php` with `student_id` → system finds today's active session for the student's section → inserts `attendance_records` row.
4. Status logic (`config/app.php` → `attendance`):
   - Scan within `on_time_window` (15 min before start) → **present**
   - Within `late_window` (30 min after start) → **late**
   - Past `absent_threshold` (60 min) → **absent** (via absentee sweep)
5. Teacher ends the session. Parent arrival/dismissal emails are queued and flushed by the task endpoint.

### Parent notification cron

Hit periodically (cron job / scheduler) with the shared key from `config/app.php`:

```
GET /tasks/notifications.php?key=YOUR_TASK_KEY
```

## Security Notes

- Passwords hashed with bcrypt; teacher session scoping enforced server-side (teachers can only manage their own sessions).
- `debug_time` scan override is gated behind debug mode so attendance can't be forged in production.
- `config/` holds secrets — keep it out of public repos or inject via env in production. Rotate any credential that was ever committed.

## Status

Active development — v2.0.0. See `DIAGNOSIS-2026-09-02.md` (history) for the last full-system audit notes.
