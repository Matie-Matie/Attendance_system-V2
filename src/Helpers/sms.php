<?php
/**
 * Parent Notification Helpers (Email only)
 *
 * Each parent/guardian receives at most TWO emails per student per school day:
 *   1. morning_in     — student scanned in at their FIRST scheduled class of the day
 *   2. afternoon_out  — student scanned in at their LAST scheduled class of the day
 *
 * The trigger is schedule-position, not a fixed clock time: whatever the first
 * subject of the day is (ideally the ~7:30 AM class, but the earliest class if
 * there is none) fires the arrival mail; the last subject fires the dismissal
 * mail. A single-class day fires the arrival mail only.
 *
 * Delivery: real SMTP via Gmail App Password (free, no billing). Sent in the
 * background right after the scan response is flushed, so the kiosk stays fast.
 *
 * A third, per-class email — 'cutting' — goes out when a teacher closes a session:
 * a student scanned present/late in an EARLIER class today but is absent from
 * this one. It is deduped per session (sms_logs.dedup_key = session id), so it
 * can fire once for each class missed, unlike the two daily types above.
 *
 * Dedup is guaranteed at the DATABASE level via
 *   UNIQUE KEY uq_notif_student_type_day_channel (student_id, type, sms_date, channel, dedup_key)
 * (dedup_key is 0 for the daily types) so concurrent scans can never double-notify.
 *
 * NOTE: file + table are still named "sms_*" for historical reasons — the SMS
 * channel was dropped when the client chose email. Everything here is email.
 */

/**
 * Decide whether a scan at a given schedule entry should fire a parent mail,
 * and which type. Returns 'morning_in', 'afternoon_out', or null (no mail).
 *
 * Compares by start_time (not entry id) so duplicate schedule rows at the same
 * time both qualify as first/last. Single-class day => 'morning_in' only.
 *
 * @param string $entryStartTime  start_time of the resolved session's schedule entry (HH:MM:SS)
 */
function notify_type_for_scan(int $sectionId, string $entryStartTime): ?string
{
    $today = date('l'); // Monday .. Sunday

    // Restrict to the current school year when one is marked; otherwise consider all.
    $sql = "SELECT MIN(start_time) AS first_start, MAX(start_time) AS last_start
            FROM schedule_entries
            WHERE section_id = ? AND day_of_week = ? AND status = 1"
         . current_school_year_filter('schedule_entries');
    $params = [$sectionId, $today];
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row || $row['first_start'] === null) {
        return null; // no classes scheduled for this section today
    }

    $entry = substr($entryStartTime, 0, 8);
    if ($entry === substr((string) $row['first_start'], 0, 8)) {
        return 'morning_in';
    }
    if ($entry === substr((string) $row['last_start'], 0, 8)) {
        return 'afternoon_out';
    }
    return null; // a middle class — no mail
}

/**
 * Build the parent notification email body. Greeting is derived from the actual
 * scan time (a "first class" at 6 PM should not say "Good morning").
 */
function notify_build_email(string $type, array $student, string $time): string
{
    $name    = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $parent  = trim((string) ($student['parent_name'] ?? '')) ?: 'Parent/Guardian';
    $school  = config('school_name', 'the school');
    $sender  = config('mail.sender_name', config('short_name', 'SCPLS'));
    $date    = date('l, F j, Y', strtotime($time));
    $clock   = date('h:i A', strtotime($time));
    $hour    = (int) date('G', strtotime($time));
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

    if ($type === 'morning_in') {
        $line = "This is to inform you that your child {$name} arrived at school today, {$date}, at {$clock} and is marked present.";
    } elseif ($type === 'afternoon_out') {
        $line = "This is to inform you that your child {$name} attended their last class today, {$date}, and has been dismissed as of {$clock}.";
    } else { // absent
        $line = "This is to inform you that your child {$name} was NOT marked present for their first class today, {$date}. "
              . "If this is unexpected, please contact the school.";
    }

    $body = "{$greeting}, {$parent},\n\n"
        . "{$line}\n\n"
        . "Student: {$name}\n"
        . "Date: {$date}\n";
    if ($type !== 'absent') {
        $body .= "Time: {$clock}\n";
    }
    $body .= "\nThis is an automated attendance notification from {$school}. "
        . "For any questions about your child's attendance, please contact their class adviser.\n\n"
        . "- {$sender}";

    return $body;
}

/**
 * Queue a parent email. Returns the log id when freshly queued, or null when
 * already queued today for this type, or when the student has no parent email.
 */
function notify_queue(string $type, int $studentId, ?int $attendanceRecordId, ?string $emailTo, string $message): ?int
{
    $emailTo = trim((string) $emailTo);
    if ($emailTo === '' || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
        return null; // no valid recipient on file
    }

    // Fast friendly pre-check; the UNIQUE key is the real race guard.
    $check = db()->prepare(
        "SELECT id FROM sms_logs WHERE student_id = ? AND type = ? AND sms_date = CURDATE() AND channel = 'email'"
    );
    $check->execute([$studentId, $type]);
    $existing = $check->fetchColumn();
    if ($existing) {
        return null; // already queued today
    }

    $stmt = db()->prepare("
        INSERT INTO sms_logs (attendance_record_id, student_id, sms_date, type, channel, email_to, message, status)
        VALUES (?, ?, CURDATE(), ?, 'email', ?, ?, 'pending')
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt->execute([$attendanceRecordId, $studentId, $type, $emailTo, $message]);

    $check->execute([$studentId, $type]);
    return $check->fetchColumn() ?: null;
}

/**
 * Send one queued email. Returns true when delivered.
 */
function notify_send(int $logId): bool
{
    $stmt = db()->prepare("SELECT * FROM sms_logs WHERE id = ?");
    $stmt->execute([$logId]);
    $log = $stmt->fetch();

    if (!$log || $log['status'] !== 'pending' || $log['channel'] !== 'email') {
        return false;
    }

    $ok = notify_deliver_email($log);

    $upd = db()->prepare("UPDATE sms_logs SET status = ?, sent_at = NOW() WHERE id = ?");
    $upd->execute([$ok ? 'sent' : 'failed', $logId]);
    return $ok;
}

/**
 * Deliver a queued row via SMTP.
 */
function notify_deliver_email(array $log): bool
{
    $to = trim((string) ($log['email_to'] ?? ''));
    if ($to === '' || empty($log['message'])) {
        return false;
    }

    $host     = (string) config('smtp.host', 'smtp.gmail.com');
    $port     = (int)    config('smtp.port', 587);
    $username = (string) config('smtp.username', '');
    $password = (string) config('smtp.password', '');
    $from     = (string) config('smtp.from_email', $username);
    $fromName = (string) config('mail.sender_name', config('short_name', 'SCPLS'));

    if ($username === '' || $password === '') {
        return false; // SMTP not configured
    }

    return smtp_send_mail($host, $port, $username, $password, $from, $fromName, $to, (string) $log['message']);
}

/**
 * Send pending emails (admin "Send Pending" button / background drain / retry).
 * Scoped to the email channel so legacy sms rows are never touched.
 * $limit caps how many are attempted in one call (0 = no cap) so a big backlog
 * drains over several passes instead of blowing max_execution_time.
 * Returns the number sent.
 */
function notify_send_pending(int $limit = 0): int
{
    $sql = "SELECT id FROM sms_logs WHERE status = 'pending' AND channel = 'email' ORDER BY id ASC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int) $limit;
    }
    $ids = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    $sent = 0;
    foreach ($ids as $id) {
        if (notify_send((int) $id)) {
            $sent++;
        }
    }
    return $sent;
}

/** Max emails actually dispatched (vs. just queued) in one background pass. */
const NOTIFY_SEND_BATCH = 12;

/**
 * Flush the HTTP response, then deliver the given queued emails in the shutdown
 * phase so the kiosk / scanner never waits on SMTP.
 */
function notify_send_after_response(array $logIds): void
{
    $ids = array_values(array_filter(array_map('intval', $logIds)));
    if (!$ids) {
        return;
    }
    register_shutdown_function(static function () use ($ids) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        foreach ($ids as $id) {
            try {
                notify_send($id);
            } catch (\Throwable $e) {
                error_log('SCPLS notify send failed (#' . $id . '): ' . $e->getMessage());
            }
        }
    });
}

/* -------------------------------------------------------------------------
 * Settings key/value store (small, lazy-created)
 * ---------------------------------------------------------------------- */
function setting_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS settings (
        skey VARCHAR(64) NOT NULL PRIMARY KEY,
        svalue TEXT DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $done = true;
}

/**
 * One-time, self-applying schema upgrades — for hosts where phpMyAdmin isn't
 * reachable (locked-out control panel). The DB user owns its schema, so it can
 * ALTER its own tables. Idempotent: guarded by a version flag in `settings`,
 * and every statement is safe to re-run. Failures are swallowed.
 */
function notify_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        setting_ensure_table();
        if ((int) setting_get('schema_version', '1') < 2) {
            // v2: email-only notifications + absentee alerts
            db()->exec("ALTER TABLE sms_logs
                MODIFY type ENUM('morning_in','afternoon_out','absent') NOT NULL DEFAULT 'morning_in'");
            db()->exec("ALTER TABLE sms_logs
                MODIFY channel ENUM('sms','email') NOT NULL DEFAULT 'email'");
            setting_set('schema_version', '2');
            error_log('SCPLS schema upgraded to v2');
        }
    } catch (\Throwable $e) {
        error_log('SCPLS notify_ensure_schema: ' . $e->getMessage());
        return;
    }
    try {
        if ((int) setting_get('schema_version', '1') >= 3) {
            return;
        }
        // v3: per-class 'cutting' alerts. Each step is safe to re-run.
        db()->exec("ALTER TABLE sms_logs
            MODIFY type ENUM('morning_in','afternoon_out','absent','cutting') NOT NULL DEFAULT 'morning_in'");
        if (!db()->query("SHOW COLUMNS FROM sms_logs LIKE 'dedup_key'")->fetch()) {
            db()->exec("ALTER TABLE sms_logs ADD COLUMN dedup_key INT NOT NULL DEFAULT 0 AFTER channel");
        }
        $idx = db()->query("SHOW INDEX FROM sms_logs WHERE Key_name = 'uq_notif_student_type_day_channel' AND Column_name = 'dedup_key'")->fetch();
        if (!$idx) {
            db()->exec("ALTER TABLE sms_logs
                DROP INDEX uq_notif_student_type_day_channel,
                ADD UNIQUE KEY uq_notif_student_type_day_channel (student_id, type, sms_date, channel, dedup_key)");
        }
        setting_set('schema_version', '3');
        error_log('SCPLS schema upgraded to v3 (cutting alerts)');
    } catch (\Throwable $e) {
        error_log('SCPLS notify_ensure_schema: ' . $e->getMessage());
    }
}

function setting_get(string $key, ?string $default = null): ?string
{
    setting_ensure_table();
    $stmt = db()->prepare("SELECT svalue FROM settings WHERE skey = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : $val;
}

function setting_set(string $key, string $value): void
{
    setting_ensure_table();
    $stmt = db()->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    $stmt->execute([$key, $value]);
}

/* -------------------------------------------------------------------------
 * Absentee sweep — emails the parent of any student who was NOT marked
 * present for their FIRST scheduled class of the day, once that class is over.
 * Symmetric with the arrival mail; fires early enough to be useful.
 *
 * Guards:
 *   - only runs when the first class has an attendance session AND that session
 *     is closed or its end_time has passed (so we actually have a verdict)
 *   - a student present at ANY session today is never "absent"
 *   - one absent mail per student per day (UNIQUE key)
 *   - needs a valid parent email
 * Returns the number of absent mails queued.
 */
function notify_sweep_absentees(?string $forDate = null): int
{
    if (!config('mail.enabled', true)) {
        return 0;
    }
    $date  = $forDate ?: date('Y-m-d');
    $today = date('l', strtotime($date));
    $now   = date('H:i:s');

    $semFilter = current_school_year_filter('se');

    // Resolve each section's FIRST schedule entry today (one row per section, so
    // this stays ONLY_FULL_GROUP_BY-safe), attach its attendance session if any,
    // then fan out to the students in that section.
    $sql = "
        SELECT s.id AS student_id, s.first_name, s.last_name, s.parent_name, s.parent_email,
               fe.end_time, asess.id AS session_id, asess.status AS session_status
        FROM (
            SELECT se.section_id, MIN(se.id) AS entry_id
            FROM schedule_entries se
            JOIN (
                SELECT se2.section_id, MIN(se2.start_time) AS mn
                FROM schedule_entries se2
                WHERE se2.day_of_week = ? AND se2.status = 1
                  " . str_replace('se.', 'se2.', $semFilter) . "
                GROUP BY se2.section_id
            ) m ON m.section_id = se.section_id AND m.mn = se.start_time
            WHERE se.day_of_week = ? AND se.status = 1 $semFilter
            GROUP BY se.section_id
        ) firsts
        JOIN schedule_entries fe ON fe.id = firsts.entry_id
        JOIN students s ON s.section_id = firsts.section_id
        LEFT JOIN attendance_sessions asess
             ON asess.schedule_entry_id = fe.id
            AND asess.attendance_date = ?
        WHERE s.status = 1
          AND s.parent_email IS NOT NULL AND s.parent_email <> ''
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute([$today, $today, $date]);
    $rows = $stmt->fetchAll();

    $queued = 0;
    $sent = 0;
    foreach ($rows as $r) {
        // No session started for the first class -> no verdict, skip.
        if (empty($r['session_id'])) {
            continue;
        }
        // First class not over yet -> too early.
        $classOver = $r['session_status'] === 'closed'
            || (strtotime($now) > strtotime((string) $r['end_time']) && $date === date('Y-m-d'))
            || $date < date('Y-m-d');
        if (!$classOver) {
            continue;
        }
        // Actually showed up somewhere today -> not absent. (Ignores 'absent'
        // rows written at session close — those must not suppress this email.)
        $present = db()->prepare("
            SELECT 1 FROM attendance_records ar
            JOIN attendance_sessions a2 ON ar.attendance_session_id = a2.id
            WHERE ar.student_id = ? AND a2.attendance_date = ?
              AND ar.status IN ('present','late') LIMIT 1
        ");
        $present->execute([$r['student_id'], $date]);
        if ($present->fetchColumn()) {
            continue;
        }

        $body = notify_build_email('absent', $r, $date . ' ' . $now);
        $id = notify_queue_for_date('absent', (int) $r['student_id'], null, $r['parent_email'], $body, $date);
        if ($id) {
            $queued++;
            // Dispatch a capped number inline for the common small case; the rest
            // are drained by notify_send_pending() on later ticks / the cron
            // endpoint, so a big absentee day never overruns max_execution_time.
            if ($sent < NOTIFY_SEND_BATCH && notify_send($id)) {
                $sent++;
            }
        }
    }
    return $queued;
}

/**
 * Like notify_queue() but for an explicit date (used by the absentee sweep,
 * which may run for "today" or be re-run for a past date).
 */
function notify_queue_for_date(string $type, int $studentId, ?int $recordId, ?string $emailTo, string $message, string $date): ?int
{
    $emailTo = trim((string) $emailTo);
    if ($emailTo === '' || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $check = db()->prepare("SELECT id FROM sms_logs WHERE student_id = ? AND type = ? AND sms_date = ? AND channel = 'email'");
    $check->execute([$studentId, $type, $date]);
    if ($check->fetchColumn()) {
        return null;
    }
    $stmt = db()->prepare("
        INSERT INTO sms_logs (attendance_record_id, student_id, sms_date, type, channel, email_to, message, status)
        VALUES (?, ?, ?, ?, 'email', ?, ?, 'pending')
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt->execute([$recordId, $studentId, $date, $type, $emailTo, $message]);
    $check->execute([$studentId, $type, $date]);
    return $check->fetchColumn() ?: null;
}

/**
 * Build the cutting-class alert email. Same tone/format as the other parent mails.
 *
 * @param array $session  needs subject_name, section_name, start_time, end_time, attendance_date
 * @param string $firstScan  datetime of the student's earliest present/late scan today
 */
function notify_build_cutting_email(array $student, array $session, string $firstScan): string
{
    $name    = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $parent  = trim((string) ($student['parent_name'] ?? '')) ?: 'Parent/Guardian';
    $school  = config('school_name', 'the school');
    $sender  = config('mail.sender_name', config('short_name', 'SCPLS'));
    $date    = date('l, F j, Y', strtotime($session['attendance_date']));
    $arrived = date('h:i A', strtotime($firstScan));
    $class   = date('h:i A', strtotime($session['start_time'])) . ' - ' . date('h:i A', strtotime($session['end_time']));
    $hour    = (int) date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

    return "{$greeting}, {$parent},\n\n"
        . "This is to inform you that your child {$name} was present at school earlier today "
        . "(arrived at {$arrived}) but was NOT recorded in their {$session['subject_name']} class. "
        . "This may mean your child cut class.\n\n"
        . "Student: {$name}\n"
        . "Date: {$date}\n"
        . "Class: {$session['subject_name']} ({$class})\n"
        . "Section: {$session['section_name']}\n\n"
        . "This is an automated attendance notification from {$school}. "
        . "For any questions about your child's attendance, please contact their class adviser.\n\n"
        . "- {$sender}";
}

/**
 * Cutting-class check — call after a teacher CLOSES a session (absent records
 * for the un-scanned students already exist by then). A student "cut" when they
 * were scanned present/late in an EARLIER class today but are absent from this one.
 * Queues one email per student per missed class (dedup_key = session id).
 *
 * Returns [['name' => ..., 'log_id' => ...], ...] for each email freshly queued;
 * pass the ids to notify_send_after_response() to deliver them.
 */
function notify_queue_cutting_alerts(int $sessionId): array
{
    if (!config('mail.enabled', true)) {
        return [];
    }
    $stmt = db()->prepare("
        SELECT s.id, s.attendance_date, se.start_time, se.end_time,
               sub.name AS subject_name, sec.name AS section_name
        FROM attendance_sessions s
        JOIN schedule_entries se ON s.schedule_entry_id = se.id
        JOIN subjects sub ON se.subject_id = sub.id
        JOIN sections sec ON se.section_id = sec.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT st.id, st.first_name, st.last_name, st.parent_name, st.parent_email, (
            SELECT MIN(ar2.timestamp)
            FROM attendance_records ar2
            JOIN attendance_sessions s2 ON ar2.attendance_session_id = s2.id
            JOIN schedule_entries se2 ON s2.schedule_entry_id = se2.id
            WHERE ar2.student_id = st.id
              AND s2.attendance_date = ?
              AND ar2.status IN ('present','late')
              AND se2.start_time < ?
        ) AS first_scan
        FROM attendance_records ar
        JOIN students st ON ar.student_id = st.id
        WHERE ar.attendance_session_id = ? AND ar.status = 'absent' AND st.status = 1
        HAVING first_scan IS NOT NULL
    ");
    $stmt->execute([$session['attendance_date'], $session['start_time'], $sessionId]);

    $queued = [];
    foreach ($stmt->fetchAll() as $st) {
        $to = trim((string) $st['parent_email']);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            continue; // no valid recipient on file
        }
        $body = notify_build_cutting_email($st, $session, $st['first_scan']);
        $ins = db()->prepare("
            INSERT INTO sms_logs (attendance_record_id, student_id, sms_date, type, channel, dedup_key, email_to, message, status)
            VALUES (NULL, ?, ?, 'cutting', 'email', ?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE id = id
        ");
        $ins->execute([$st['id'], $session['attendance_date'], $sessionId, $to, $body]);
        if ($ins->rowCount() === 1) { // 1 = inserted, 0 = duplicate (already queued)
            $queued[] = [
                'name'   => trim($st['first_name'] . ' ' . $st['last_name']),
                'log_id' => (int) db()->lastInsertId(),
            ];
        }
    }
    return $queued;
}

/**
 * Throttled background tick — called from bootstrap on every request. Runs the
 * absentee sweep at most once every 10 minutes, and only during school hours,
 * so no cron is required. The sweep itself runs AFTER the response is flushed
 * so it never slows a page or a kiosk scan. Failures are swallowed.
 */
function notify_tick(): void
{
    try {
        $hour = (int) date('G');
        if ($hour < 6 || $hour >= 21) {
            return; // outside school hours
        }
        if (!notify_claim_slot('last_absent_sweep', 600)) {
            return; // another request swept in the last 10 min (or the slot is suppressed)
        }
    } catch (\Throwable $e) {
        error_log('SCPLS notify_tick (claim): ' . $e->getMessage());
        return;
    }

    register_shutdown_function(static function () {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            notify_sweep_absentees();
            notify_send_pending(NOTIFY_SEND_BATCH); // drain any backlog a bit each pass
        } catch (\Throwable $e) {
            error_log('SCPLS notify_tick (sweep): ' . $e->getMessage());
        }
    });
}

/**
 * Atomically claim a throttle slot. Returns true for exactly one caller per
 * $ttl-second window (race-safe via a conditional UPDATE). A slot value set in
 * the future stays unclaimable until then — used to suppress sweeps locally.
 */
function notify_claim_slot(string $key, int $ttl): bool
{
    setting_ensure_table();
    $now = time();
    // Ensure the row exists without disturbing an existing value.
    $ins = db()->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES (?, '0')");
    $ins->execute([$key]);

    $upd = db()->prepare(
        "UPDATE settings SET svalue = ? WHERE skey = ? AND CAST(svalue AS UNSIGNED) <= ?"
    );
    $upd->execute([(string) $now, $key, $now - $ttl]);
    return $upd->rowCount() > 0;
}

/* -------------------------------------------------------------------------
 * Backwards-compatible aliases (old call sites / any missed references)
 * ---------------------------------------------------------------------- */
function sms_send(int $logId): bool { return notify_send($logId); }
function sms_send_pending(): int { return notify_send_pending(); }

/**
 * Minimal SMTP client (STARTTLS + AUTH LOGIN) over PHP streams.
 */
function smtp_send_mail(string $host, int $port, string $username, string $password, string $from, string $fromName, string $to, string $body): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 8);
    if (!$fp) {
        error_log("SCPLS SMTP: connect failed {$host}:{$port} ({$errstr})");
        return false;
    }
    stream_set_timeout($fp, 8);

    $read = function () use ($fp): string {
        $line = '';
        while ($chunk = fgets($fp, 512)) {
            $line .= $chunk;
            if (isset($chunk[3]) && $chunk[3] === ' ') {
                break;
            }
        }
        return trim($line);
    };
    $cmd = function (string $command) use ($fp, $read): string {
        fwrite($fp, $command . "\r\n");
        return $read();
    };

    $read();                                             // greeting
    $cmd("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

    $resp = $cmd("STARTTLS");
    if (strpos($resp, '220') !== 0) {
        fclose($fp);
        return false;
    }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($fp);
        return false;
    }

    $cmd("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $cmd("AUTH LOGIN");
    $cmd(base64_encode($username));
    $resp = $cmd(base64_encode($password));
    if (strpos($resp, '235') !== 0) {
        error_log('SCPLS SMTP: auth rejected');
        fclose($fp);
        return false;
    }

    $cmd("MAIL FROM:<{$from}>");
    $resp = $cmd("RCPT TO:<{$to}>");
    if (strpos($resp, '250') !== 0) {
        fclose($fp);
        return false;
    }

    $cmd("DATA");
    $fromHeaderName = mb_encode_mimeheader($fromName, 'UTF-8');
    $headers = "From: {$fromHeaderName} <{$from}>\r\n"
        . "To: <{$to}>\r\n";
    $replyTo = trim((string) config('mail.reply_to', ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers .= "Reply-To: {$fromHeaderName} <{$replyTo}>\r\n";
    }
    $headers .= "Subject: " . mb_encode_mimeheader('Attendance Notification - ' . config('short_name', 'SCPLS'), 'UTF-8') . "\r\n"
        . "Date: " . date('r') . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";
    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    $resp = $read();
    $cmd("QUIT");
    fclose($fp);

    return strpos($resp, '250') === 0;
}
