<?php
/**
 * API: Scan Attendance via QR Code
 *
 * Flow:
 *   1. Decode student_id from QR code (POST student_id)
 *   2. Resolve student -> their section -> ACTIVE attendance session today
 *   3. Reject duplicates (race-condition safe via UNIQUE key + pre-check)
 *   4. Determine status: present (within on_time_window) vs late
 *   5. Insert attendance record + queue SMS notification
 *
 * POST fields:
 *   student_id   (required) raw student ID encoded in the QR code
 *   debug_time   (optional) HH:MM(:SS) to simulate a scan time (testing only)
 */
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty(trim($_POST['student_id'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. No student ID received.']);
    exit;
}

$studentIdRaw = trim($_POST['student_id']);

// Scan-time override is a debug-only aid — without this gate anyone could POST
// a student ID + a favourable time and forge an on-time attendance record.
$debugTime = debug_enabled() ? trim($_POST['debug_time'] ?? '') : '';
if ($debugTime && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $debugTime) !== 1) {
    $debugTime = '';
}

try {
    // 1. Look up student
    $stmt = db()->prepare("SELECT * FROM students WHERE student_id = ? AND status = 1 LIMIT 1");
    $stmt->execute([$studentIdRaw]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student ID not recognized. Please check your QR code.']);
        exit;
    }

    if (empty($student['section_id'])) {
        echo json_encode(['success' => false, 'message' => 'No section assigned to this student. Contact the administrator.']);
        exit;
    }

    // 2. Resolve the ACTIVE attendance session for the student's section.
    //    A section can have several active sessions in one day (morning + afternoon
    //    classes), so prefer the session whose scheduled window contains the scan
    //    time; fall back to the most recently started session.
    $scanTime = $debugTime ?: date('H:i:s');
    $graceMin = (int)config('attendance.on_time_window', 15);

    $stmt = db()->prepare("
        SELECT as2.*, se.start_time, se.end_time, se.room,
               s.name AS subject_name, s.code AS subject_code,
               sec.name AS section_name, sec.grade_level
        FROM attendance_sessions as2
        JOIN schedule_entries se ON as2.schedule_entry_id = se.id
        JOIN subjects s ON se.subject_id = s.id
        JOIN sections sec ON se.section_id = sec.id
        WHERE as2.status = 'active'
          AND as2.attendance_date = CURDATE()
          AND se.section_id = ?
        ORDER BY as2.started_at DESC
    ");
    $stmt->execute([$student['section_id']]);
    $sessions = $stmt->fetchAll();

    $session = null;
    $windowMatched = false;
    $scanTS = strtotime($scanTime);
    foreach ($sessions as $s) {
        $winStart = strtotime($s['start_time']) - $graceMin * 60;
        $winEnd = strtotime($s['end_time']);
        if ($scanTS >= $winStart && $scanTS <= $winEnd) {
            $session = $s;
            $windowMatched = true;
            break;
        }
    }
    if (!$session) {
        $session = $sessions[0] ?? null;
    }

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'No active attendance session for your section right now.']);
        exit;
    }

    // 3. Duplicate check (pre-check first; the DB UNIQUE key is the final guard)
    $stmt = db()->prepare("SELECT id, status FROM attendance_records WHERE attendance_session_id = ? AND student_id = ?");
    $stmt->execute([$session['id'], $student['id']]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo json_encode([
            'success' => false,
            'already_logged' => true,
            'message' => 'Attendance already logged for ' . $student['first_name'] . ' (' . strtoupper($existing['status']) . ').',
        ]);
        exit;
    }

    // 4. Determine status: on time = scan time <= schedule start + on_time_window
    $startTime = strtotime($session['start_time']);
    $onTimeEnd = $startTime + (int)config('attendance.on_time_window', 15) * 60;
    $status = (strtotime($scanTime) <= $onTimeEnd) ? 'present' : 'late';

    // 5. Insert (ON DUPLICATE KEY UPDATE is the race guard — never double-counts)
    $stmt = db()->prepare("
        INSERT INTO attendance_records (attendance_session_id, student_id, status, timestamp)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt->execute([$session['id'], $student['id'], $status, date('Y-m-d H:i:s', strtotime($scanTime))]);

    // Confirm the insert actually happened (not blocked by a concurrent scan)
    $stmt = db()->prepare("SELECT id FROM attendance_records WHERE attendance_session_id = ? AND student_id = ?");
    $stmt->execute([$session['id'], $student['id']]);
    $recordId = $stmt->fetchColumn();
    if (!$recordId) {
        echo json_encode(['success' => false, 'already_logged' => true, 'message' => 'Attendance already logged.']);
        exit;
    }

    // 6. Queue the parent email — only when this scan lands on the student's
    //    FIRST scheduled class of the day (arrival mail) or LAST (dismissal mail).
    //    Never on a middle class, and never on the fallback session (unmatched
    //    window) since we can't trust it's really their first/last.
    //    Dedup is DB-guaranteed (UNIQUE student_id + type + sms_date + channel).
    $emailQueued = null;
    $mailType = null;
    if ($windowMatched && config('mail.enabled', true)) {
        $mailType = notify_type_for_scan((int)$student['section_id'], (string)$session['start_time']);
        if ($mailType !== null) {
            $scanClock = date('Y-m-d H:i:s', strtotime($scanTime));
            $emailBody = notify_build_email($mailType, $student, $scanClock);
            $emailQueued = notify_queue($mailType, (int)$student['id'], $recordId, $student['parent_email'], $emailBody);
        }
    }

    echo json_encode([
        'success'        => true,
        'message'        => 'Welcome, ' . $student['first_name'] . '! Attendance logged.',
        'student_name'   => $student['first_name'] . ' ' . $student['last_name'],
        'student_id'     => $student['student_id'],
        'status'         => $status,
        'time'           => date('h:i A', strtotime($scanTime)),
        'subject'        => $session['subject_name'],
        'section'        => $session['section_name'],
        'mail'           => $emailQueued
            ? ($mailType === 'morning_in' ? 'arrival_sent' : 'dismissal_sent')
            : ($mailType && !$student['parent_email'] ? 'no_email'
               : ($mailType ? 'already_sent' : 'not_applicable')),
    ]);

    // 7. Deliver the mail in the background — kiosk never waits on SMTP.
    if ($emailQueued) {
        notify_send_after_response([$emailQueued]);
    }

} catch (\Throwable $e) {
    error_log('SCPLS scan error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not log attendance right now. Please try again.']);
    exit;
}
