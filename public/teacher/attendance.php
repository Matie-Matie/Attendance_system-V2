<?php
/**
 * Teacher - Attendance Session
 * Start/Resume/End attendance with QR code scanning
 */
$pageTitle = 'Attendance';
$currentPage = 'attendance';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('teacher');

$teacher = current_teacher();
$teacherId = $teacher['id'] ?? 0;

if (!$teacherId) { flash('error', 'Teacher profile not found.'); redirect('/auth/logout.php'); }

$scheduleId = (int)($_GET['schedule_id'] ?? 0);
$sessionId = (int)($_GET['session_id'] ?? 0);

// Debug: override time via ?debug_time=HH:MM:SS (debug builds only)
$debugTime = '';
if (debug_enabled()) {
    $q = $_GET['debug_time'] ?? '';
    if ($q === 'off') {
        unset($_SESSION['_debug_time']);
    } elseif ($q && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $q)) {
        $_SESSION['_debug_time'] = $q; // persist across redirects
    }
    if (!empty($_SESSION['_debug_time']) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $_SESSION['_debug_time'])) {
        $debugTime = $_SESSION['_debug_time'];
    }
}
$debugDate = $debugTime ? date('Y-m-d', strtotime($debugTime)) : null;

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_session') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if (!$scheduleId) { flash('error', 'Invalid schedule entry.'); redirect('/teacher/dashboard.php'); }

        // Only this teacher may open a session for a schedule entry that is theirs.
        $own = db()->prepare("SELECT id FROM schedule_entries WHERE id = ? AND teacher_id = ? AND status = 1");
        $own->execute([$scheduleId, $teacherId]);
        if (!$own->fetchColumn()) { flash('error', 'That class is not on your schedule.'); redirect('/teacher/dashboard.php'); }

        // Prevent creating a NEW session if one already exists for today (active or closed)
        $check = db()->prepare("SELECT id, status FROM attendance_sessions WHERE schedule_entry_id = ? AND attendance_date = ? LIMIT 1");
        $check->execute([$scheduleId, $debugDate ?? date('Y-m-d')]);
        $existing = $check->fetch();
        if ($existing) {
            if ($existing['status'] === 'active') {
                flash('error', 'An active session already exists for this class. Resume it from the dashboard.');
            } else {
                flash('info', 'A session was already completed for this class today. View it in reports.');
            }
            redirect('/teacher/dashboard.php');
        }

        $sessionDate = $debugDate ?? date('Y-m-d');
        $sessionTime = $debugTime ?: date('H:i:s');
        $startedAt = $sessionDate . ' ' . $sessionTime;
        $stmt = db()->prepare("INSERT INTO attendance_sessions (schedule_entry_id, teacher_id, attendance_date, started_at, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute([$scheduleId, $teacherId, $sessionDate, $startedAt]);
        $newSessionId = db()->lastInsertId();

        // No pre-marking — students start neutral (0 0 0). Records are created on first QR scan.
        $_SESSION['_flash']['success'] = 'Attendance session started!';
        redirect("/teacher/attendance.php?session_id=$newSessionId");
    }

    if ($action === 'end_session') {
        $sessionId = (int)($_POST['session_id'] ?? 0);

        // Must be this teacher's own active session.
        $sInfo = db()->prepare("
            SELECT s.id, se.section_id
            FROM attendance_sessions s
            JOIN schedule_entries se ON s.schedule_entry_id = se.id
            WHERE s.id = ? AND s.teacher_id = ? AND s.status = 'active'
        ");
        $sInfo->execute([$sessionId, $teacherId]);
        $sRow = $sInfo->fetch();
        if (!$sRow) { flash('error', 'No active session to close.'); redirect('/teacher/dashboard.php'); }

        db()->beginTransaction();
        try {
            // Mark every un-scanned student in the section absent for this session,
            // so reports and dashboards reflect real absences (not just blanks).
            $markAbsent = db()->prepare("
                INSERT INTO attendance_records (attendance_session_id, student_id, status, timestamp)
                SELECT ?, st.id, 'absent', NOW()
                FROM students st
                WHERE st.section_id = ? AND st.status = 1
                  AND st.id NOT IN (
                      SELECT student_id FROM attendance_records WHERE attendance_session_id = ?
                  )
                ON DUPLICATE KEY UPDATE attendance_records.id = attendance_records.id
            ");
            $markAbsent->execute([$sessionId, $sRow['section_id'], $sessionId]);

            $counts = db()->prepare("
                SELECT
                    SUM(status = 'present') AS p,
                    SUM(status = 'late')    AS l,
                    SUM(status = 'absent')  AS a
                FROM attendance_records WHERE attendance_session_id = ?
            ");
            $counts->execute([$sessionId]);
            $c = $counts->fetch();
            $pCount = (int) ($c['p'] ?? 0);
            $lCount = (int) ($c['l'] ?? 0);
            $aCount = (int) ($c['a'] ?? 0);

            $endedAt = ($debugDate ?? date('Y-m-d')) . ' ' . ($debugTime ?: date('H:i:s'));
            $upd = db()->prepare("UPDATE attendance_sessions SET status = 'closed', ended_at = ?, total_present = ?, total_late = ?, total_absent = ? WHERE id = ?");
            $upd->execute([$endedAt, $pCount, $lCount, $aCount, $sessionId]);
            db()->commit();
        } catch (\Throwable $e) {
            db()->rollBack();
            error_log('SCPLS end_session failed: ' . $e->getMessage());
            flash('error', 'Could not close the session. Please try again.');
            redirect('/teacher/dashboard.php');
        }

        // Email parents of students who attended an earlier class today but cut this one.
        // Never let an alert problem undo or block a successful close.
        $cutters = [];
        try {
            $cutters = notify_queue_cutting_alerts($sessionId);
            notify_send_after_response(array_column($cutters, 'log_id'));
        } catch (\Throwable $e) {
            error_log('SCPLS cutting alerts failed: ' . $e->getMessage());
        }

        $msg = "Session closed. {$pCount} present, {$lCount} late, {$aCount} absent.";
        if ($cutters) {
            $msg .= ' Cutting alert emailed for ' . count($cutters) . ' student(s): ' . implode(', ', array_column($cutters, 'name')) . '.';
        }
        $_SESSION['_flash']['success'] = $msg;
        redirect('/teacher/dashboard.php');
    }
}

// Get active or completed session
$session = null;
$scheduleEntry = null;
$students = [];
$sectionStudents = [];

if ($sessionId) {
    $stmt = db()->prepare("
        SELECT as2.*, se.subject_id, se.section_id, se.start_time, se.end_time, se.room,
               s.name as subject_name, s.code as subject_code,
               sec.name as section_name, sec.grade_level
        FROM attendance_sessions as2
        JOIN schedule_entries se ON as2.schedule_entry_id = se.id
        JOIN subjects s ON se.subject_id = s.id
        JOIN sections sec ON se.section_id = sec.id
        WHERE as2.id = ?
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if ($session) {
        // Get schedule entry
        $scheduleEntry = $session;

        // Get all students for this section with their attendance status
        $stmt = db()->prepare("
            SELECT st.id, st.student_id, st.first_name, st.last_name,
                   ar.status as attendance_status, ar.timestamp
            FROM students st
            LEFT JOIN attendance_records ar ON ar.student_id = st.id AND ar.attendance_session_id = ?
            WHERE st.section_id = ? AND st.status = 1
            ORDER BY st.last_name ASC
        ");
        $stmt->execute([$sessionId, $session['section_id']]);
        $sectionStudents = $stmt->fetchAll();
    }
}

if (!$session && $scheduleId) {
    // No session - redirect to start
    flash('info', 'No active session. Start attendance first.');
    redirect('/teacher/dashboard.php');
}

if (!$session && !$scheduleId && !$sessionId) {
    // Direct access without params - redirect to dashboard
    flash('info', 'Select a class from your dashboard to start or resume attendance.');
    redirect('/teacher/dashboard.php');
}

if (!$session) {
    // Old/deleted session link (e.g. a stale bookmark or tab) - don't render a broken page
    flash('info', 'That attendance session was not found. Start or resume your class from the dashboard.');
    redirect('/teacher/dashboard.php');
}

ob_start();
?>

<style>
    .attendance-station { max-width: 900px; margin: 0 auto; }
    .scanner-section { max-width: 480px; margin: 0 auto 1.5rem; }
    .student-list { max-height: 400px; overflow-y: auto; }
    .student-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.6rem 0.8rem;
        border-radius: var(--radius-sm);
        transition: var(--transition);
        border: 1px solid var(--border-color);
        margin-bottom: 0.35rem;
    }
    .student-item.present { border-color: var(--success); background: var(--success-light); }
    .student-item.late { border-color: var(--warning); background: var(--warning-light); }
    .student-item.pending { border-color: transparent; opacity: 0.6; }
    [data-theme="dark"] .student-item.present { background: rgba(16,185,129,0.15); }
    [data-theme="dark"] .student-item.late { background: rgba(245,158,11,0.15); }

    .scan-status {
        text-align: center;
        padding: 0.75rem;
        margin: 0.75rem 0;
        border-radius: var(--radius-sm);
        font-weight: 500;
    }
    .scan-status.scanning { background: var(--nav-active); color: var(--primary); }
    .scan-status.success { background: var(--success-light); color: #3D6B23; }
    .scan-status.error { background: var(--danger-light); color: #B23434; }

    .attendance-success {
        position: fixed;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 2rem 3rem;
        text-align: center;
        box-shadow: var(--shadow-lg);
        z-index: 100;
        animation: fadeIn 0.3s ease-out;
    }
    .attendance-success h2 { color: var(--success); margin-bottom: 0.5rem; }

    .session-timer {
        font-size: 0.85rem;
        color: var(--text-muted);
        font-family: monospace;
    }

    /* ---------- Scanning happens on the separate QR Scanner page ---------- */
    .scanner-callout {
        display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        padding: 1.1rem 1.25rem; margin-bottom: 1.25rem;
        border-radius: var(--radius-lg); background: #120E0C; color: #F6F1EC;
    }
    .scanner-callout .sc-icon { width: 46px; height: 46px; border-radius: 12px; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; flex: none; }
    .scanner-callout .sc-text { flex: 1; min-width: 220px; }
    .scanner-callout strong { display: block; font-size: 1.02rem; }
    .scanner-callout span { font-size: 0.85rem; opacity: 0.75; }
    .scanner-callout .btn { white-space: nowrap; }
    .live-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #4B7E2E; margin-right: 0.35rem; animation: livePulse 1.6s infinite; }
    @keyframes livePulse { 50% { opacity: 0.25; } }
    .student-item.just-scanned { animation: justScanned 2.4s ease-out; }
    @keyframes justScanned { 0% { box-shadow: 0 0 0 3px var(--success); } 100% { box-shadow: 0 0 0 0 transparent; } }
</style>

<div class="attendance-station">
    <?php if ($session): ?>
        <!-- Active Session Header -->
        <div class="card" style="border-left: 4px solid <?= $session['status'] === 'active' ? 'var(--success)' : 'var(--text-muted)' ?>;">
            <div class="card-body">
                <div class="flex items-center justify-between flex-wrap" style="gap:0.5rem;">
                    <div>
                        <h3 style="font-size:1.2rem;font-weight:700;"><?= h($session['subject_name']) ?></h3>
                        <p style="color:var(--text-secondary);font-size:0.9rem;">
                            <?= h($session['section_name']) ?> | Room <?= h($session['room'] ?? 'N/A') ?>
                            | <?= format_time($session['start_time']) ?> - <?= format_time($session['end_time']) ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge <?= $session['status'] === 'active' ? 'badge-success' : 'badge-pending' ?>">
                            <?= strtoupper($session['status']) ?>
                        </span>
                        <span class="session-timer" id="session-timer"></span>
                        <?php if ($session['status'] === 'active'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('End this attendance session?')">
                                <input type="hidden" name="action" value="end_session">
                                <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm">End Session</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($session['status'] === 'active'): ?>
            <!-- Scanning is separate from the dashboard: students use the QR Scanner page -->
            <div class="scanner-callout">
                <div class="sc-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>
                </div>
                <div class="sc-text">
                    <strong>Students scan at the QR Scanner</strong>
                    <span>Open it on the classroom PC or projector. Scans for this class appear in the list below by themselves.</span>
                </div>
                <a href="/scan/" target="scpls-scanner" rel="noopener" class="btn btn-primary">Open QR Scanner &#8599;</a>
            </div>
        <?php endif; ?>

        <!-- Student List -->
        <div class="card">
            <div class="card-header">
                <h3>Students<?php if ($session['status'] === 'active'): ?> <span style="font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-left:0.4rem;"><span class="live-dot"></span>Live</span><?php endif; ?></h3>
                <div class="flex items-center gap-2">
                    <span class="badge badge-success" id="present-count">
                        <?= count(array_filter($sectionStudents, fn($s) => $s['attendance_status'] === 'present')) ?>
                    </span>
                    <span class="badge badge-warning" id="late-count">
                        <?= count(array_filter($sectionStudents, fn($s) => $s['attendance_status'] === 'late')) ?>
                    </span>
                    <span class="badge badge-pending" id="pending-count">
                        <?= count(array_filter($sectionStudents, fn($s) => !$s['attendance_status'])) ?>
                    </span>
                    <span style="font-size:0.75rem;color:var(--text-muted);">
                        / <?= count($sectionStudents) ?> total
                    </span>
                </div>
            </div>
            <div class="card-body student-list" style="padding:0.75rem;">
                <?php if (empty($sectionStudents)): ?>
                    <div class="empty-state">
                        <h3>No students enrolled</h3>
                        <p style="font-size:0.85rem;color:var(--text-muted);">This section (<?= h($scheduleEntry['section_name'] ?? '') ?>) has no students yet. Add students in the admin panel.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sectionStudents as $st): ?>
                        <?php $status = $st['attendance_status'] ?: 'pending'; ?>
                        <div class="student-item <?= $status ?>" data-student-id="<?= $st['id'] ?>" id="student-row-<?= $st['id'] ?>">
                            <div>
                                <strong><?= h($st['first_name'] . ' ' . $st['last_name']) ?></strong>
                                <code style="font-size:0.75rem;margin-left:0.5rem;"><?= h($st['student_id']) ?></code>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="badge <?= $status === 'present' ? 'badge-success' : ($status === 'late' ? 'badge-warning' : 'badge-pending') ?>">
                                    <?= $status === 'pending' ? '—' : strtoupper($status) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const SESSION_ID = <?= (int) $sessionId ?>;

// ---------- Live list: scans arrive from the separate QR Scanner page ----------
function setCount(id, n) { const el = document.getElementById(id); if (el) el.textContent = n; }

async function refreshLive() {
    try {
        const r = await fetch('/api/session-status.php?session_id=' + SESSION_ID, { cache: 'no-store' });
        if (!r.ok) return;
        const d = await r.json();
        if (!d.success) return;
        if (d.session_status !== 'active') { location.reload(); return; } // ended elsewhere
        let present = 0, late = 0, pending = 0;
        for (const [id, status] of Object.entries(d.students)) {
            const st = status || 'pending';
            if (st === 'present') present++; else if (st === 'late') late++; else pending++;
            const row = document.getElementById('student-row-' + id);
            if (!row || row.dataset.status === st) continue;
            const wasPending = !row.dataset.status || row.dataset.status === 'pending';
            row.dataset.status = st;
            row.className = 'student-item ' + st + (wasPending && st !== 'pending' ? ' just-scanned' : '');
            const badge = row.querySelector('.badge');
            if (badge) {
                badge.className = 'badge ' + (st === 'present' ? 'badge-success' : st === 'late' ? 'badge-warning' : 'badge-pending');
                badge.textContent = st === 'pending' ? '\u2014' : st.toUpperCase();
            }
        }
        setCount('present-count', present); setCount('late-count', late); setCount('pending-count', pending);
    } catch (e) { /* offline blip - try again next tick */ }
}

// Session timer
function updateTimer() {
    const started = new Date('<?= str_replace(' ', 'T', (string) $session['started_at']) ?>').getTime();
    const now = new Date().getTime();
    const diff = Math.floor((now - started) / 1000);
    const m = Math.floor(diff / 60);
    const s = diff % 60;
    const el = document.getElementById('session-timer');
    if (el) el.textContent = 'Session: ' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}
setInterval(updateTimer, 1000);
updateTimer();

// Init
document.addEventListener('DOMContentLoaded', function() {
    if ('<?= $session['status'] ?>' === 'active') {
        document.querySelectorAll('.student-item').forEach(r => {
            r.dataset.status = ['present', 'late'].find(c => r.classList.contains(c)) || 'pending';
        });
        refreshLive();
        setInterval(refreshLive, 3000);
    }
});
</script>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/teacher-layout.php';
?>
