<?php
/**
 * API: live status of one attendance session (teacher's attendance page polls this)
 *
 * Students scan on the separate QR Scanner page (/scan/), so the teacher's list
 * refreshes from here every few seconds.
 *
 * GET session_id -> { success, session_status, students: { <students.id>: "present"|"late"|"absent"|null } }
 * Only the teacher who owns the session can read it.
 */
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_authenticated() || !has_role('teacher')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}

$teacher   = current_teacher();
$teacherId = (int) ($teacher['id'] ?? 0);
$sessionId = (int) ($_GET['session_id'] ?? 0);

$stmt = db()->prepare("
    SELECT as2.id, as2.status, se.section_id
    FROM attendance_sessions as2
    JOIN schedule_entries se ON as2.schedule_entry_id = se.id
    WHERE as2.id = ? AND as2.teacher_id = ?
");
$stmt->execute([$sessionId, $teacherId]);
$session = $stmt->fetch();

if (!$session) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Session not found.']);
    exit;
}

$stmt = db()->prepare("
    SELECT st.id, ar.status
    FROM students st
    LEFT JOIN attendance_records ar ON ar.student_id = st.id AND ar.attendance_session_id = ?
    WHERE st.section_id = ? AND st.status = 1
");
$stmt->execute([$sessionId, $session['section_id']]);

$students = [];
foreach ($stmt->fetchAll() as $row) {
    $students[(string) $row['id']] = $row['status'];
}

echo json_encode([
    'success'        => true,
    'session_status' => $session['status'],
    'students'       => (object) $students,
]);
