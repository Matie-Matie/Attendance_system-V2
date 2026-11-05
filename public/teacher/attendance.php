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
    #qr-reader { width: 100%; }
    #qr-reader img, #qr-reader video { max-width: 100%; border-radius: var(--radius-sm); }
    #qr-reader__scan_region { border-radius: var(--radius-sm); overflow: hidden; }
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

    /* ---------- Scan mode: camera fills the whole screen ---------- */
    .scan-mode-btn { display: flex; align-items: center; gap: 0.5rem; margin: -0.5rem auto 0.75rem; }
    .sm-only { display: none !important; }
    body.scan-mode .sm-only { display: inline-flex !important; }
    body.scan-mode { overflow: hidden; }
    /* The page-entry animation leaves a transform on this wrapper, which would
       trap the fixed full-screen camera inside the content area. */
    body.scan-mode .attendance-station { transform: none !important; animation: none !important; }
    body.scan-mode .camera-shell {
        position: fixed; inset: 0; z-index: 1000;
        max-width: none !important; margin: 0 !important;
        border-radius: 0; border: 0;
        display: flex; align-items: center; justify-content: center;
    }
    body.scan-mode .camera-shell #qr-reader {
        width: min(100vw, calc((100vh - 9rem) * 4 / 3)) !important;
        padding: 4.5rem 0 4.5rem; border: 0 !important;
    }
    body.scan-mode .camera-shell video { width: 100% !important; max-height: calc(100vh - 9rem); object-fit: contain; }
    body.scan-mode .camera-topbar { font-size: 1rem; padding: 1rem 1.25rem; }
    body.scan-mode .camera-bottombar { padding: 1rem 1.25rem; }
    body.scan-mode .camera-bottombar > span:first-child { font-size: 1.15rem !important; }
    .sm-counts { gap: 0.4rem; font-weight: 700; }
    .sm-counts span { padding: 0.25rem 0.65rem; border-radius: 999px; background: rgba(255,255,255,0.16); }
    .sm-exit {
        position: absolute; top: 0.85rem; right: 1rem; z-index: 6;
        pointer-events: auto; cursor: pointer;
        padding: 0.55rem 1rem; border-radius: 999px; border: 1px solid rgba(255,255,255,0.35);
        background: rgba(0,0,0,0.55); color: #fff; font-weight: 700; font-size: 0.9rem;
    }
    body.scan-mode .camera-topbar { padding-right: 11rem; }
    body.scan-mode .attendance-success { z-index: 1100; padding: 2.5rem 4rem; min-width: min(90vw, 560px); }
    body.scan-mode .attendance-success h2 { font-size: 3rem; line-height: 1.1; }
    body.scan-mode .attendance-success p:first-of-type { font-size: 2rem; font-weight: 700; color: var(--text-primary); }
    .scan-error-overlay {
        position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
        z-index: 1100; min-width: min(90vw, 560px); max-width: 90vw;
        padding: 2.5rem 3rem; border-radius: var(--radius-lg); text-align: center;
        background: var(--bg-secondary); box-shadow: var(--shadow-lg);
        border-top: 8px solid var(--danger); animation: fadeIn 0.3s ease-out;
    }
    .scan-error-overlay h2 { color: var(--danger); font-size: 2.5rem; margin-bottom: 0.5rem; }
    .scan-error-overlay p { font-size: 1.4rem; color: var(--text-primary); }
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
            <!-- QR Scanner — Architech Camera Shell -->
            <div class="camera-shell" style="max-width: 540px; margin: 0 auto 1.5rem;">
                <div class="camera-topbar">
                    <span class="ct-live"><span class="blip"></span> Live</span>
                    <span style="font-weight:700; letter-spacing:0.02em;"><?= h($session['subject_name']) ?> • <?= h($session['section_name']) ?></span>
                    <span class="sm-only sm-counts" aria-live="polite">
                        <span>Present <b id="sm-present">0</b></span>
                        <span>Late <b id="sm-late">0</b></span>
                        <span>Waiting <b id="sm-pending">0</b></span>
                    </span>
                </div>
                <button type="button" class="sm-only sm-exit" onclick="exitScanMode()">&#10005; Exit scan mode</button>
                <div id="qr-reader"></div>
                <div class="camera-bottombar">
                    <span style="font-size:0.78rem; opacity:0.9;">Align QR in frame • Auto-capture • <span id="scan-status-text" style="font-weight:700;">Waiting for QR…</span></span>
                    <span class="badge badge-success" style="background: rgba(255,255,255,0.16); color:#fff; backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,0.2);">● Ready</span>
                </div>
                <!-- hidden status mirror for JS compatibility -->
                <div id="scan-status" class="scan-status scanning" style="display:none;">Waiting for student QR...</div>
            </div>
            <button type="button" class="btn btn-primary scan-mode-btn" onclick="enterScanMode()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/></svg>
                Scan mode (full screen)
            </button>
            <p class="text-xs text-muted" style="text-align:center; margin: 0 0 1.25rem;">Have the student hold their QR card up to the camera. The system marks present/late automatically. Scan mode shows only the camera so students can see it from afar (Esc to exit).</p>
        <?php endif; ?>

        <!-- Student List -->
        <div class="card">
            <div class="card-header">
                <h3>Students</h3>
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

<script src="/assets/vendor/html5-qrcode-2.3.8.min.js"></script>
<script>
const SESSION_ID = <?= $sessionId ?>;
const STUDENTS = <?= json_encode($sectionStudents) ?>;
const DEBUG_TIME = '<?= $debugTime ?>'; // empty string = real time
let html5QrCode = null;
let isScanning = true;

// Mirror hidden scan-status → visible camera bottombar text (architech)
document.addEventListener('DOMContentLoaded', function() {
    const hidden = document.getElementById('scan-status');
    const visible = document.getElementById('scan-status-text');
    if (!hidden || !visible) return;
    const sync = () => { visible.textContent = hidden.textContent; };
    sync();
    new MutationObserver(sync).observe(hidden, { childList: true, characterData: true, subtree: true });
    // also watch class changes to swap badge color
    const badge = document.querySelector('.camera-bottombar .badge');
    if (badge) {
        new MutationObserver(() => {
            const cls = hidden.className;
            if (cls.includes('error')) { badge.textContent = '● Error'; badge.style.background = 'rgba(220,74,74,0.85)'; showScanError(hidden.textContent); }
            else if (cls.includes('success')) { badge.textContent = '● Success'; badge.style.background = 'rgba(75,126,46,0.85)'; }
            else { badge.textContent = '● Ready'; badge.style.background = 'rgba(255,255,255,0.16)'; }
        }).observe(hidden, { attributes: true, attributeFilter: ['class'] });
    }
});

function startQrScanner() {
    const statusEl = document.getElementById('scan-status');

    html5QrCode = new Html5QrcodeScanner(
        'qr-reader',
        {
            fps: 10,
            qrbox: (w, h) => { const s = Math.max(200, Math.floor(Math.min(w, h) * 0.6)); return { width: s, height: s }; },
            rememberLastUsedCamera: true,
        },
        false
    );

    html5QrCode.render(async function(decodedText) {
        if (!isScanning) return;
        isScanning = false;
        try { html5QrCode.clear(); } catch (e) {}

        statusEl.textContent = 'QR detected! Verifying student...';
        statusEl.className = 'scan-status scanning';

        const formData = new FormData();
        formData.append('student_id', decodedText.trim());
        if (DEBUG_TIME) formData.append('debug_time', DEBUG_TIME);

        try {
            const resp = await fetch('/api/scan.php', { method: 'POST', body: formData });
            const result = await resp.json();

            // 🛡️ RACE CONDITION GUARD: another scan may have marked this
            // student while we were awaiting the AJAX call.
            if (!result.success && !result.already_logged) {
                statusEl.textContent = result.message;
                statusEl.className = 'scan-status error';
                isScanning = true;
                restartScanner();
                return;
            }

            const student = STUDENTS.find(s => s.student_id === decodedText.trim());
            if (student) {
                const alreadyHandled = student.attendance_status === 'present' || student.attendance_status === 'late';
                student.attendance_status = result.status || student.attendance_status;

                // Update the UI (only if not already marked on screen)
                if (!alreadyHandled) {
                    const row = document.getElementById('student-row-' + student.id);
                    if (row) {
                        row.className = 'student-item ' + student.attendance_status;
                        const badge = row.querySelector('.badge');
                        if (badge) {
                            badge.className = 'badge ' + (student.attendance_status === 'present' ? 'badge-success' : 'badge-warning');
                            badge.textContent = student.attendance_status.toUpperCase();
                        }
                    }
                    document.getElementById('present-count').textContent =
                        parseInt(document.getElementById('present-count').textContent) + (student.attendance_status === 'present' ? 1 : 0);
                    document.getElementById('late-count').textContent =
                        parseInt(document.getElementById('late-count').textContent) + (student.attendance_status === 'late' ? 1 : 0);
                    document.getElementById('pending-count').textContent =
                        Math.max(0, parseInt(document.getElementById('pending-count').textContent) - 1);
                }

                if (result.success) {
                    showSuccess(student.first_name + ' ' + student.last_name, student.attendance_status);
                } else {
                    statusEl.textContent = result.message;
                    statusEl.className = 'scan-status error';
                }
            }

            // Re-enable scanning after delay
            setTimeout(() => {
                isScanning = true;
                statusEl.textContent = 'Waiting for student QR...';
                statusEl.className = 'scan-status scanning';
                restartScanner();
            }, 2000);
        } catch (err) {
            statusEl.textContent = 'Scan error: ' + err.message;
            statusEl.className = 'scan-status error';
            isScanning = true;
            setTimeout(() => { restartScanner(); }, 2000);
        }
    }, function(err) {
        if (err && !String(err).includes('No MultiFormat Readers')) {
            console.warn('QR scan warning:', err);
        }
    });
}

function restartScanner() {
    try {
        if (html5QrCode) html5QrCode.clear();
    } catch (e) {}
    startQrScanner();
}

function showSuccess(name, status) {
    const overlay = document.createElement('div');
    overlay.className = 'attendance-success';
    overlay.id = 'success-overlay';
    overlay.innerHTML = `
        <div style="width:64px;height:64px;margin:0 auto 1rem;background:var(--success-light);border-radius:50%;display:flex;align-items:center;justify-content:center;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#4B7E2E" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
        </div>
        <h2>${status.toUpperCase()}</h2>
        <p style="color:var(--text-secondary);">${name}</p>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.5rem;">Time: ${new Date().toLocaleTimeString()}</p>
    `;
    document.body.appendChild(overlay);
    setTimeout(() => {
        const el = document.getElementById('success-overlay');
        if (el) el.remove();
    }, 2000);
}

// ---------- Scan mode ----------
function inScanMode() { return document.body.classList.contains('scan-mode'); }

function restartIfIdle() {
    // The scanner measures its box size when it starts, so restart it after the
    // camera area changes size (skipped while a scan is being processed).
    if (!isScanning || !html5QrCode) return;
    setTimeout(async () => {
        try { await html5QrCode.clear(); } catch (e) {} // clear() is async - wait for it
        startQrScanner();
    }, 150);
}

function enterScanMode() {
    if (inScanMode()) return;
    document.body.classList.add('scan-mode');
    syncScanCounts();
    const el = document.documentElement;
    if (el.requestFullscreen) el.requestFullscreen().catch(() => {}); // hides the browser bars too
    restartIfIdle();
}

function exitScanMode() {
    if (!inScanMode()) return;
    document.body.classList.remove('scan-mode');
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
    restartIfIdle();
}

// Esc leaves browser full screen first; follow it out of scan mode too.
document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) exitScanMode(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') exitScanMode(); });

function syncScanCounts() {
    [['present', 'sm-present'], ['late', 'sm-late'], ['pending', 'sm-pending']].forEach(([k, id]) => {
        const src = document.getElementById(k + '-count'), dst = document.getElementById(id);
        if (src && dst) dst.textContent = src.textContent.trim();
    });
}
setInterval(syncScanCounts, 1000);

function showScanError(message) {
    if (!inScanMode()) return;
    const old = document.getElementById('scan-error-overlay');
    if (old) old.remove();
    const box = document.createElement('div');
    box.className = 'scan-error-overlay';
    box.id = 'scan-error-overlay';
    box.setAttribute('role', 'alert');
    const h = document.createElement('h2'); h.textContent = 'Not recorded';
    const p = document.createElement('p'); p.textContent = message || 'Please try again.';
    box.append(h, p);
    document.body.appendChild(box);
    setTimeout(() => { if (box.isConnected) box.remove(); }, 2500);
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
        startQrScanner();
    }
});
</script>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/teacher-layout.php';
?>
