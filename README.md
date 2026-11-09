# Attendance System V2 (SCPLS v2)

**Smart Class Presence Log-in System** — QR-code based student attendance tracking for **Monico Rarama National High School**, Academic Year 2026–2027.

**Runs on your own PC:** install XAMPP (PHP + MySQL), then double-click **`start.bat`** — it starts XAMPP's MySQL by itself (Apache is not needed), prepares the database on first run, and opens http://127.0.0.1:8000 in **Google Chrome** (the default browser if Chrome is not installed). You never have to touch the XAMPP Control Panel. Logins are below.

> **Updates:** download the latest copy of this repo and run `start.bat` again. Your students, teachers and attendance stay in MySQL — updating the files never deletes them.
>
> (Older web demo: https://scpls.xo.je — 2026-10-14 build. The school now runs it locally.)

## November 2026 — one double-click, readable schedule (2026-11-09)

- **`start.bat` starts XAMPP's MySQL by itself** when it is not running (XAMPP on `C:`, `D:` or `E:`, or a MySQL/MariaDB service), waits up to a minute for it, then continues. Apache is not needed — the site runs on PHP's built-in server. The error screen now explains what to check if MySQL still refuses to start.
- **Other MySQL servers no longer confuse it** — a PC with a separate MySQL installed (e.g. `MySQL84` on port 3307) used to be picked up as "our" database, so `start.bat` stopped with *access denied for root*. It now always follows XAMPP's own settings, starts XAMPP's MySQL on its configured port, and only falls back to another server when XAMPP is not installed at all.
- **The site opens in Chrome, ready to use** — `bin\open-browser.ps1` waits until the port really answers, then opens Chrome (default browser if Chrome is missing), so the tab never lands on "site can't be reached" while PHP is still booting. `scan.bat` uses the same opener for the QR scanner page.
- **`scan.bat` starts everything too** — on a kiosk PC it starts MySQL + the system (first-run setup included), waits, then opens the QR scanner directly. It no longer asks you to run `start.bat` first, and it does not open an extra dashboard tab.
- **Teacher Schedule readability** — subject names wrap on two lines instead of being cut ("Araling Panli…"), class times read short (`8–9 AM`), sections read `G12 HUMSS · RM 102`, and the teacher line is kept for long blocks while 1-hour blocks show it in the tooltip. Hour rows are taller and day columns wider.
- **Week board instead of a time grid** — each day is a column of full-size subject cards in time order (time, subject, section · room, teacher). Every card looks the same, and two classes at the same hour are simply two cards instead of being squeezed into half-width lanes. The hour grid is gone; phones stack the five days vertically.

## November 2026 — separate QR Scanner (2026-11-05)

- **Scanning is separate from the dashboard.** Open the **QR Scanner** at http://127.0.0.1:8000/scan/ (also a button on the login page and in the teacher menu). No login needed: big camera, full-screen button, large PRESENT / LATE + name after each scan.
- **How it knows the class:** the teacher clicks **Start** on their dashboard first. The student's QR gives their section; the system logs the scan to that section's class running right now (from 15 min before start to the end). If no class is running it says so — nothing is recorded.
- **A class left open by mistake** no longer catches the next class's scans. (A class opened off-schedule on purpose — make-up class or demo — still accepts scans, marked LATE.)
- **Teacher attendance page** no longer has a camera: it shows the student list, which updates by itself every few seconds as students scan.
- **Faster:** parent emails are sent in the background, so "Present" appears right away. The QR scanner library is bundled, so it works without internet.

## October 2026 revisions (sir Vic, round 3 — 2026-10-22)

- **Teacher Schedule is a calendar** — the old day-by-day tables are now a weekly class program (Mon–Fri, Saturday when used; 7 AM–5 PM grid). Pick a teacher to see only their week; click any class to edit or delete it. Prints on one landscape page.
- **"Teacher Schedule"** — renamed from "Teachers Master Schedule".
- **Menu order** — Dashboard → Teacher Schedule → Students, then **School Records** (Subjects, Sections, School Years, Teachers), then System.
- **Stat cards only on the dashboard** — list pages no longer show the count beside the title or the stat cards at the top.
- **Semesters merged into the school year** — no more semester screen or semester field; each school year is one term. (Database unchanged: a hidden "Whole Year" term is created automatically.)
- **Report printing** — Print now outputs the attendance table only (teacher and admin).
- **Sidebar scrolls** — on short screens the menu scrolls on its own, so Logout is always reachable.

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
