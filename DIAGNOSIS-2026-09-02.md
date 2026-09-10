# SCPLS — Full System Diagnosis (2026-09-02)

Scope: whole codebase (~5,500 LOC), the 2026-09-02 email-notification rewrite, and the
architech UI redesign. Reviewed every PHP file, schema, seed, CSS, and JS.

Legend: ✅ fixed this pass · 🔶 needs Mat's decision · ⬜ deferred (low value vs. ship bar)

## Status after this pass

**Fixed & verified on the running localhost:** C1 C2 C3 · H1 H2 H3 H4 H6 · M1 M2 M3 M4 M5 M7 M8
· plus **B1 (below)**. Every mutation was exercised end-to-end (create/edit/dup each entity,
delete a schedule entry, start & end a session, kiosk scan, teacher scan, CSRF-less POST,
absentee sweep, bad-DB-creds error path). All admin + teacher + public pages render clean,
no PHP warnings in the log.

**Still on the table:** H5, M6, M9, all L*, and the four 🔶 decisions at the bottom.

### B1. Schedule-entry delete never worked  ✅ (found during verification)
`schedule.php`'s delete POST fell through the add/edit field validation (`Teacher is
required`, …), so `submit_action=delete` never reached the `DELETE`. Deleting a class from
the master schedule silently did nothing. **Fix:** delete is now its own branch, no
add/edit validation.

### B2. "Change teacher password" on edit was a no-op  ✅ (found during read)
The teacher edit form has a "New Password (leave blank to keep)" field; the handler
updated name + email but never the password. An admin could never reset a teacher's
password from the UI. **Fix:** edit now re-hashes and stores the password when provided.

### B3. A teacher could open/close another teacher's session  ✅
`start_session` accepted any `schedule_id`; `end_session` any `session_id`. **Fix:** both
now verify the schedule entry / session belongs to the logged-in teacher.


---

## CRITICAL — ship blockers

### C0. `verify_csrf('')` returned true on a token-less session  ✅ (found while fixing C1)
`hash_equals('', '')` is `true`, so before the central gate existed an attacker could
bypass any future CSRF check by simply omitting the field on a fresh session — and login
CSRF stayed fully open. `verify_csrf()` now rejects when either side is empty.

### C1. CSRF protection is completely absent  ✅
(Includes `login.php`, which now verifies its own token — the central gate only covers
*authenticated* POSTs.)
`verify_csrf()` exists in `functions.php` and is **never called**. Every form renders
`csrf_field()` but no POST handler validates it. Every authenticated mutation is
forgeable from a malicious page: create/edit students & teachers, delete schedule
entries, start/end attendance, **teacher "reset all attendance data"**, send emails.
**Fix:** central check in `bootstrap.php` — any POST from an authenticated session must
carry a valid `_csrf_token`; `/api/` paths exempt (the kiosk is deliberately public).

### C2. `debug_time` lets anyone forge attendance  ✅
`/api/scan.php` accepts `debug_time` from POST with **no auth and no debug gate**, then
uses it for the present/late decision *and* the stored timestamp. A student who can read
a classmate's printed QR can POST `student_id=X&debug_time=07:00` and mark them present
and on-time. `config('debug')` was defined but never checked anywhere.
**Fix:** gate every `debug_time` / `debug_day` path (`scan.php`, `teacher/dashboard.php`,
`teacher/attendance.php`) behind `config('debug') === true`.

### C3. Raw exception messages leak to the public kiosk  ✅
`/api/scan.php` catches `Exception` and echoes `'System error: ' . $e->getMessage()` to
an unauthenticated endpoint (SQL fragments, schema names). It also does not catch
`\Throwable`, so a `TypeError` → blank 500.
**Fix:** catch `\Throwable`, `error_log()` the detail, return a generic message.

---

## HIGH

### H1. No global exception handler → white-screen of death  ✅
`PDO::ERRMODE_EXCEPTION` + `display_errors=0` + several CRUD writes with **no try/catch**
(`sections.php`, `semesters.php`, `school-years.php`, parts of `schedule.php`) = any DB
error renders a blank page with nothing in the UI. This is the same failure class as the
2026-08-15 "blank admin pages" incident, still latent.
**Fix:** `set_exception_handler` + fatal `register_shutdown_function` in `bootstrap.php`
→ log + render a plain friendly error page.

### H2. Live session counters always show 0  ✅
`attendance_sessions.total_present` / `total_late` are only written on **session close**,
but admin `dashboard.php` ("Sessions In Progress") and `teacher/dashboard.php` (active
class card) display them while the session is still open → "0 in, 0 late" during the
actual class.
**Fix:** count live from `attendance_records` in those queries.

### H3. "Absent" is never recorded anywhere  ✅
Nothing ever writes `status = 'absent'`. Admin dashboard breakdown, per-section bars, and
`reports.php` (summary + the "Absent" status filter + CSV) therefore always show 0 / empty.
The schema and UI both imply absentee tracking that doesn't exist.
**Fix:** on `end_session`, insert `absent` rows for every enrolled section student with no
record for that session. Update `notify_sweep_absentees()`'s "present today" guard to
`status IN ('present','late')` so the absent **email** still fires (otherwise the new
absent rows would suppress it).

### H4. Flash success/error messages swallowed on `subjects.php`  ✅
`subjects.php` calls `flash('success')` / `flash('error')` at the top into unused vars,
which **consumes and clears them** before the layout's `alerts.php` can render them. After
"Subject created" you get redirected to a list with no confirmation.
**Fix:** delete the two dead lines.

### H5. QR libraries load from unpinned public CDNs  🔶
`student/scan.php` → `unpkg.com/html5-qrcode`, `qr-codes.php` → `cdn.jsdelivr.net/qrcodejs`.
No SRI, no version lock on the URL host. The **core feature** (scanning + card generation)
breaks entirely if the school's connection can't reach that CDN.
**Decision for Mat:** vendor both libraries into `/assets/vendor/` (recommended) or accept
the dependency.

### H6. DB connection failure leaks details  ✅
`Database::__construct` → `die('Database connection failed: ' . $e->getMessage())` prints
host/credential context to the browser regardless of debug mode.
**Fix:** log, show generic message.

---

## MEDIUM

### M1. Stale `old()` input bleeds into later forms  ✅
`$_SESSION['_old']` (set by `preserve_input()`) is never cleared. `schedule.php` reads it
via `old()`; values from a failed submit reappear in an unrelated "Add Entry" form opened
minutes later.
**Fix:** make it single-use — snapshot into a request local at the top of `bootstrap.php`
and clear the session copy.

### M2. Raw duplicate-key errors shown to admin  ✅
Editing a student to a taken ID, or adding a teacher with a taken username/employee ID,
surfaces `SQLSTATE[23000]… Duplicate entry…`. **Fix:** friendly pre-checks
(students edit, teachers username + employee_id).

### M3. Schedule conflict check ignores section double-booking  ✅
Only teacher-vs-teacher overlap is checked. A section can be booked into two classes at
the same time — which also corrupts the arrival/dismissal-mail "first/last class" logic
(the seed data already has Grade-11 HUMSS with two Tuesday 11:40 entries).
**Fix:** add a section-overlap check alongside the teacher one.

### M4. Reflected XSS in `reports.php` CSV-export link  ✅
`href="…?date_from=<?= $dateFrom ?>…"` interpolates `$_GET` values unescaped. `date_from`
= `"><script>…` → script execution on the admin reports page.
**Fix:** `h()` + `urlencode()` on every interpolated filter value.

### M5. `new Date('YYYY-MM-DD HH:MM:SS')` → Invalid Date on Safari/iOS  ✅
`teacher/dashboard.php` clock and `teacher/attendance.php` session timer. Space-separated
datetime strings are not valid ES5 and Safari returns `Invalid Date` → `NaN:NaN`.
**Fix:** `.replace(' ', 'T')`.

### M6. `audit_logs` is written by exactly one action  🔶
Only `subjects.php` "create" logs. The Audit Logs page is effectively always empty.
**Decision for Mat:** wire a `log_audit()` helper into every mutation, or remove the page.

### M7. "Set current" school-year/semester is not transactional  ✅
`UPDATE … SET is_current = 0` runs before the insert/update; a failure there leaves the
system with **no** current year/semester (which several queries assume exists).
**Fix:** wrap in a transaction.

### M8. Landing page + `index.php` still advertise "SMS Notifications"  ✅
SMS was removed. **Fix:** "Email Notifications".

### M9. QR scanner re-render loop  ⬜
`student/scan.php` + `teacher/attendance.php` call `Html5QrcodeScanner.render()` again
after `.clear()` on every cycle. The widget is built to render once; repeated cycles can
leak camera handles / stack DOM. This is the family of the 2026-08-15 "scan loops
forever" bug. **Deferred:** works in testing; a proper fix means moving to the lower-level
`Html5Qrcode` API. Flag for a follow-up hardening pass.

---

## LOW / polish  ⬜

- **L1.** `validate()` in `validation.php` is dead code (no caller); its `exists` rule has
  an unreachable `=== 0` bug. Remove `validate()` or leave it.
- **L2.** CSV injection — a student named `=cmd|…` executes when the export is opened in
  Excel. Prefix `'` to fields starting with `= + - @`.
- **L3.** `teachers.php` list runs one schedule-count query per teacher (N+1).
- **L4.** No way to deactivate/delete a teacher or student (only add/edit).
- **L5.** `prefers-reduced-motion` zeroes animation *duration* but not *delay* — items 9+
  in a `.reveal` flash invisible for 0.44s.
- **L6.** Kiosk `showResult()` builds the details table with string concatenation
  (`innerHTML`), so a student name containing markup renders. Kiosk context, admin-entered.
- **L7.** `notify_tick()` issues one throttle query on every request, including the public
  kiosk and every `/api/scan.php` call. Cheap, but could be skipped for `/api/`.

---

## Mat's calls — RESOLVED 2026-09-02

1. **Teacher "Reset Attendance Data"** → ✅ **removed entirely** from `teacher/dashboard.php`
   (handler + UI + the `$hasSessions` query).
2. **Open kiosk endpoint** → ✅ **accepted as a known limitation** (see below). In practice
   the QR scanner is operated by the teacher in class, so a student marking a friend
   present is on them. No code change.
3. **Vendor QR CDN libs** → 🔶 Mat chose **leave on CDN**. No change. (Risk still stands:
   scanning + card printing need `unpkg.com` / `cdn.jsdelivr.net` reachable.)
4. **Audit logging** → ✅ **keep** — `log_audit()` wired into every create/update/delete.

---

## Known limitations (accepted — document in client handover)

- **Attendance relies on the student physically scanning.** `/api/scan.php` is a public,
  login-less endpoint; it cannot verify who submitted a scan or from where. A student who
  has another student's QR code could mark them present remotely. Accepted: the QR scanner
  is operated by the teacher during class, so this is a trust/honour matter, not a system
  hole to plug.
- **QR scanning + card printing require internet** to reach `unpkg.com` (html5-qrcode) and
  `cdn.jsdelivr.net` (qrcodejs). If the school connection can't reach those CDNs, both stop
  working. (Vendoring the two files locally would remove this — deferred by choice.)
- **Absent emails / dashboards depend on teachers closing their sessions.** An open session
  is never auto-closed, so its students aren't marked absent until the teacher ends it.
- **Gmail free tier caps at 500 emails/day** (~2× enrolment with arrival + dismissal +
  absent). A large school needs a dedicated sender or a transactional provider.
