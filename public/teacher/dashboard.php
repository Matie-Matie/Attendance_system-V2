<?php
/**
 * Teacher Dashboard — Today's Classes
 *
 * Shows the teacher's schedule for today with:
 * - Current class (if within class time)
 * - Next class (upcoming today)
 * - Other classes today
 */
$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('teacher');

$teacher = current_teacher();
$teacherId = $teacher['id'] ?? 0;

if (!$teacherId) {
    flash('error', 'Teacher profile not found.');
    redirect('/auth/logout.php');
}

$today = date('l'); // Monday, Tuesday, etc.
$currentTime = date('H:i:s');

// Debug: override current time via ?debug_time=HH:MM:SS (debug builds only)
$debugTime = debug_enabled() ? ($_GET['debug_time'] ?? '') : '';
if ($debugTime && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $debugTime)) {
    $currentTime = $debugTime;
    // Also adjust the "today" so schedule days match
    if (!empty($_GET['debug_day'])) {
        $today = ucfirst(strtolower($_GET['debug_day']));
    }
}

// Get today's schedule entries for this teacher
$stmt = db()->prepare("
    SELECT se.*, s.name AS subject_name, s.code AS subject_code,
           sec.name AS section_name, sec.grade_level
    FROM schedule_entries se
    JOIN subjects s ON se.subject_id = s.id
    JOIN sections sec ON se.section_id = sec.id
    WHERE se.teacher_id = ?
      AND se.day_of_week = ?
      AND se.status = 1
    ORDER BY se.start_time ASC
");
$stmt->execute([$teacherId, $today]);
$todayClasses = $stmt->fetchAll();

// Determine current and next class
$currentClass = null;
$nextClass = null;
$otherClasses = [];

$preStartBuffer = (int)(config('attendance.on_time_window') ?: 15); // minutes before start to show Start button

foreach ($todayClasses as $class) {
    $start = $class['start_time'];
    $end = $class['end_time'];

    // Allow starting attendance early (within the on_time_window)
    $adjustedStart = date('H:i:s', strtotime($start) - $preStartBuffer * 60);

    if ($currentTime >= $adjustedStart && $currentTime <= $end) {
        $currentClass = $class;
    } elseif ($currentTime < $start && !$nextClass) {
        $nextClass = $class;
    } else {
        $otherClasses[] = $class;
    }
}

// Check if there's an active session for current class
$activeSession = null;
if ($currentClass) {
    $stmt = db()->prepare("
        SELECT s.*,
               (SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_session_id = s.id AND ar.status = 'present') AS live_present,
               (SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_session_id = s.id AND ar.status = 'late')    AS live_late,
               (SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_session_id = s.id AND ar.status = 'absent')  AS live_absent
        FROM attendance_sessions s
        WHERE s.schedule_entry_id = ? AND s.attendance_date = CURDATE() AND s.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$currentClass['id']]);
    $activeSession = $stmt->fetch();
}

// Get greeting based on time of day
$hour = (int) date('H');
$greeting = match (true) {
    $hour < 12 => 'Good Morning',
    $hour < 18 => 'Good Afternoon',
    default    => 'Good Evening',
};

// Count total students in the section for the current class
$totalStudents = 0;
if ($currentClass) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM students WHERE section_id = ? AND status = 1");
    $stmt->execute([$currentClass['section_id']]);
    $totalStudents = $stmt->fetchColumn();
}

// Absences recorded today in this teacher's own classes.
// Rows are written when a session is closed (end_session marks every unscanned student absent).
$absentToday = [];
$closedToday = 0;
try {
    $stmt = db()->prepare("
        SELECT st.id AS id, st.student_id AS sid, st.first_name, st.last_name,
               sub.name AS subject_name, sec.name AS section_name, se.room, se.start_time
        FROM attendance_records ar
        JOIN attendance_sessions ses ON ar.attendance_session_id = ses.id
        JOIN schedule_entries se ON ses.schedule_entry_id = se.id
        JOIN students st ON ar.student_id = st.id
        JOIN subjects sub ON se.subject_id = sub.id
        JOIN sections sec ON se.section_id = sec.id
        WHERE se.teacher_id = ? AND ar.status = 'absent' AND ses.attendance_date = CURDATE()
        ORDER BY se.start_time ASC, st.last_name ASC, st.first_name ASC
    ");
    $stmt->execute([$teacherId]);
    $absentToday = $stmt->fetchAll();

    $stmt = db()->prepare("
        SELECT COUNT(*) FROM attendance_sessions ses
        JOIN schedule_entries se ON ses.schedule_entry_id = se.id
        WHERE se.teacher_id = ? AND ses.attendance_date = CURDATE() AND ses.status = 'closed'
    ");
    $stmt->execute([$teacherId]);
    $closedToday = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    // Absences table may be mid-migration on a fresh install — never break the dashboard.
    $absentToday = [];
    $closedToday = 0;
}

$absentGroups = [];
foreach ($absentToday as $a) {
    $key = $a['subject_name'] . ' · ' . $a['section_name'] . ' · ' . format_time($a['start_time']);
    $absentGroups[$key][] = $a;
}


ob_start();

// Day progress for architech hero (7am–5pm window)
$nowMinutes = (int)date('H') * 60 + (int)date('i');
$dayProgress = max(0, min(100, round(($nowMinutes - 7*60) / (10*60) * 100)));
?>

    <!-- Architech Hero -->
    <div class="teacher-hero-architech">
        <div>
            <div class="tha-kicker"><i></i> <?= h($today) ?> • <?= date('F j, Y') ?> • <?= h(config('short_name')) ?></div>
            <h1><?= $greeting ?>,<br><em><?= h($teacher['first_name'] ?? 'Teacher') ?></em></h1>
            <p class="tha-sub">
                You have <strong><?= count($todayClasses) ?> class<?= count($todayClasses)===1?'':'es' ?></strong> today
                <?php if ($currentClass): ?>• now: <strong><?= h($currentClass['subject_name']) ?></strong> <?= h($currentClass['section_name']) ?><?php elseif ($nextClass): ?>• next: <strong><?= h($nextClass['subject_name']) ?></strong> at <?= format_time($nextClass['start_time']) ?><?php else: ?>• enjoy the breather<?php endif; ?>
                <br><span style="color: var(--text-muted); font-size: 0.85rem;"><?= h(config('school_name')) ?> • S.Y. <?= h(config('academic_year')) ?></span>
            </p>
            <div class="tha-metrics">
                <span class="tha-metric"><span class="tha-dot" style="background: var(--primary);"></span> <b><?= count($todayClasses) ?></b> today</span>
                <span class="tha-metric"><span class="tha-dot" style="background: var(--success);"></span> <b><?= $currentClass ? '1' : '0' ?></b> now</span>
                <span class="tha-metric"><span class="tha-dot" style="background: var(--warning);"></span> <b><?= $nextClass ? '1' : '0' ?></b> next</span>
                <?php if ($currentClass): ?><span class="tha-metric"><span class="tha-dot" style="background: var(--info);"></span> <b><?= $totalStudents ?></b> students</span><?php endif; ?>
                <?php if (count($absentToday) > 0): ?><span class="tha-metric"><span class="tha-dot" style="background: var(--danger);"></span> <b><?= count($absentToday) ?></b> absent today</span><?php endif; ?>
            </div>
        </div>
        <div class="tha-clock-arch" id="system-clock">
            <div class="tha-clock-top"><span>System Time</span><span style="color: var(--success); font-weight:800;">● Live</span></div>
            <div class="tha-clock-time" id="clock-time"><?= date('h:i:s A') ?></div>
            <div class="tha-clock-date" id="clock-date"><?= date('l, M j') ?> • <?= date('Y') ?></div>
            <div class="dayline">
                <div class="dayline-head">
                    <span>School day</span>
                    <span class="dayline-pct" id="dayline-pct"><?= $dayProgress ?>%</span>
                </div>
                <div class="dayline-track">
                    <span class="dayline-fill" id="dayline-fill" style="--p: <?= $dayProgress ?>%"></span>
                </div>
                <div class="dayline-scale"><span>7 AM</span><span>12 NN</span><span>5 PM</span></div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const serverTime = new Date('<?= date('Y-m-d\TH:i:s') ?>');
        const browserTime = new Date();
        const offset = serverTime.getTime() - browserTime.getTime();
        function updateClock() {
            const now = new Date(Date.now() + offset);
            const h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
            const ampm = h >= 12 ? 'PM' : 'AM';
            const h12 = h % 12 || 12;
            const timeStr = String(h12).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0') + ' ' + ampm;
            const opts = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
            const dateStr = now.toLocaleDateString('en-US', opts);
            const elT = document.getElementById('clock-time');
            const elD = document.getElementById('clock-date');
            if (elT) elT.textContent = timeStr;
            if (elD) elD.textContent = dateStr;

            // School day timeline (7 AM – 5 PM), kept in sync with the clock
            const mins = h * 60 + m;
            const p = Math.max(0, Math.min(100, Math.round((mins - 7 * 60) / (10 * 60) * 100)));
            const elF = document.getElementById('dayline-fill');
            const elP = document.getElementById('dayline-pct');
            if (elF) elF.style.setProperty('--p', p + '%');
            if (elP) elP.textContent = p + '%';
        }
        setInterval(updateClock, 1000);
        updateClock();
    })();
    </script>

    <?php if (empty($todayClasses)): ?>
        <div class="card" style="margin-top:1.25rem;">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                    <h3>No classes scheduled for today</h3>
                    <p>Enjoy your day off! Your week is still below.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="today-bento">
            <!-- Current — featured -->
            <div>
                <?php if ($currentClass): ?>
                    <div class="bento-current-feature" data-watermark="<?= h(date('g', strtotime($currentClass['start_time']))) ?>">
                        <span class="bcf-kicker"><span class="blip" style="width:7px;height:7px;border-radius:50%;background:#fff;display:inline-block;animation: pulse 1.6s infinite;"></span> Now • <?= format_time($currentClass['start_time']) ?> — <?= format_time($currentClass['end_time']) ?></span>
                        <div class="bcf-title"><?= h($currentClass['subject_name']) ?></div>
                        <div class="bcf-meta">
                            <span><?= h($currentClass['section_name']) ?></span>
                            <span>Room <?= h($currentClass['room'] ?: 'N/A') ?></span>
                            <span><?= h($currentClass['grade_level'] ?? '') ?></span>
                        </div>
                        <div class="bcf-actions">
                            <?php if ($activeSession): ?>
                                <a href="/teacher/attendance.php?session_id=<?= $activeSession['id'] ?>" class="btn btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Resume</a>
                                <span class="bcf-stats"><?= (int)$activeSession['live_present'] ?> present • <?= (int)$activeSession['live_late'] ?> late<?php if ((int)$activeSession['live_absent'] > 0): ?> • <?= (int)$activeSession['live_absent'] ?> absent<?php endif; ?></span>
                                <a href="/teacher/reports.php?date_from=<?= date('Y-m-d') ?>&date_to=<?= date('Y-m-d') ?>" class="bcf-stats" style="text-decoration:underline;">Today in reports →</a>
                            <?php else: ?>
                                <form method="POST" action="/teacher/attendance.php" style="display:inline;">
                                    <input type="hidden" name="action" value="start_session">
                                    <input type="hidden" name="schedule_id" value="<?= $currentClass['id'] ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Start Attendance</button>
                                </form>
                                <span class="bcf-stats"><?= $totalStudents ?> students</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card" style="border-radius:22px; padding:1.25rem; height:100%; display:flex; align-items:center; justify-content:center; border-style: dashed;">
                        <div style="text-align:center; color: var(--text-muted);">
                            <div style="font-size:1.1rem; font-weight:700; color: var(--text-primary);">No class right now</div>
                            <div style="font-size:0.85rem; margin-top:0.25rem;">Next up is your focus.</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Next + Later -->
            <div class="bento-next-stack">
                <?php if ($nextClass): ?>
                    <div class="bento-next-card">
                        <div style="font-size:0.70rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color: var(--warning);">Up Next</div>
                        <h4><?= h($nextClass['subject_name']) ?></h4>
                        <div class="bnc-meta"><?= h($nextClass['section_name']) ?> • Room <?= h($nextClass['room'] ?: 'N/A') ?></div>
                        <div class="bnc-time"><?= format_time($nextClass['start_time']) ?> — <?= format_time($nextClass['end_time']) ?></div>
                    </div>
                <?php endif; ?>

                <?php
                $remaining = $todayClasses;
                if ($currentClass) $remaining = array_filter($remaining, fn($c) => $c['id'] !== $currentClass['id']);
                if ($nextClass) $remaining = array_filter($remaining, fn($c) => $c['id'] !== $nextClass['id']);
                ?>
                <?php if (!empty($remaining)): ?>
                    <div style="font-size:0.70rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color: var(--text-muted); margin-top:0.25rem;">Later today • <?= count($remaining) ?></div>
                    <div class="later-list">
                        <?php foreach ($remaining as $class): ?>
                            <div class="later-item">
                                <div><strong><?= h($class['subject_name']) ?></strong><div style="font-size:0.72rem; color: var(--text-muted);"><?= h($class['section_name']) ?></div></div>
                                <span class="li-time"><?= format_time($class['start_time']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (!$nextClass): ?>
                    <div class="card" style="border-radius:18px; padding:1rem; text-align:center; color: var(--text-muted); font-size:0.85rem;">No more classes today — you’re clear.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Absent today — this teacher's own classes only -->
    <?php if (count($absentToday) > 0 || $closedToday > 0): ?>
        <div class="card absent-today" style="margin-top:1.25rem;">
            <div class="card-header">
                <h3>
                    Absent Today
                    <?php if (count($absentToday) > 0): ?><span class="badge badge-danger" style="margin-left:0.35rem;"><?= count($absentToday) ?></span><?php else: ?><span class="badge" style="margin-left:0.35rem;">0</span><?php endif; ?>
                </h3>
                <a class="text-sm" href="/teacher/reports.php?date_from=<?= date('Y-m-d') ?>&date_to=<?= date('Y-m-d') ?>&status=absent">Open in reports →</a>
            </div>
            <div class="card-body">
                <?php if (count($absentToday) === 0): ?>
                    <div class="absent-empty">
                        <span class="absent-empty-dot"></span>
                        <div>
                            <strong>No absences today</strong>
                            <span><?= $closedToday ?> class<?= $closedToday === 1 ? '' : 'es' ?> closed — every scanned class was complete.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="absent-groups">
                        <?php foreach ($absentGroups as $groupLabel => $students): ?>
                            <div class="absent-group">
                                <div class="absent-group-head">
                                    <strong><?= h($groupLabel) ?></strong>
                                    <span><?= count($students) ?> absent</span>
                                </div>
                                <div class="absent-names">
                                    <?php foreach ($students as $st): ?>
                                        <span class="absent-name">
                                            <?= h($st['first_name'] . ' ' . $st['last_name']) ?>
                                            <code><?= h($st['sid']) ?></code>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Week — architech bento -->
    <?php
    $allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $weekSchedule = db()->prepare("
        SELECT se.day_of_week, se.start_time, se.end_time, se.room,
               s.name AS subject_name, sec.name AS section_name
        FROM schedule_entries se
        JOIN subjects s ON se.subject_id = s.id
        JOIN sections sec ON se.section_id = sec.id
        WHERE se.teacher_id = ? AND se.status = 1
        ORDER BY FIELD(se.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday'), se.start_time ASC
    ");
    $weekSchedule->execute([$teacherId]);
    $allEntries = $weekSchedule->fetchAll();
    $grouped = [];
    foreach ($allEntries as $e) { $grouped[$e['day_of_week']][] = $e; }
    ?>
    <div class="week-architech">
        <div class="week-architech-head"><h3>Week at a glance</h3><span><?= count($allEntries) ?> classes • Mon–Fri</span></div>
        <div class="week-bento-grid">
            <?php foreach ($allDays as $day): ?>
                <div class="week-bento <?= $day === date('l') ? 'today' : '' ?>">
                    <div class="week-bento-head"><span><?= $day ?></span><span style="font-weight:700;"><?= isset($grouped[$day]) ? count($grouped[$day]) : 0 ?></span></div>
                    <div class="week-bento-body">
                        <?php if (empty($grouped[$day])): ?>
                            <p class="text-xs text-muted" style="text-align:center; padding:0.6rem 0;">— no classes —</p>
                        <?php else: ?>
                            <?php foreach ($grouped[$day] as $entry): ?>
                                <div class="week-bento-slot">
                                    <strong><?= h($entry['subject_name']) ?></strong>
                                    <span class="wbs-meta"><?= h($entry['section_name']) ?> • <?= format_time($entry['start_time']) ?> • <?= h($entry['room'] ?: 'TBA') ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/teacher-layout.php';
?>
