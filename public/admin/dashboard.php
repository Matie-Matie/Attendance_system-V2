<?php
/**
 * Admin Dashboard — live attendance overview
 */
require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

/* ---------- Directory counts ---------- */
$totalTeachers = (int) db()->query("SELECT COUNT(*) FROM teachers WHERE status = 1")->fetchColumn();
$totalStudents = (int) db()->query("SELECT COUNT(*) FROM students WHERE status = 1")->fetchColumn();
$totalSections = (int) db()->query("SELECT COUNT(*) FROM sections WHERE status = 1")->fetchColumn();
$totalSubjects = (int) db()->query("SELECT COUNT(*) FROM subjects WHERE status = 1")->fetchColumn();

/* ---------- Today ---------- */
$todaySessions = (int) db()->query("SELECT COUNT(*) FROM attendance_sessions WHERE attendance_date = CURDATE()")->fetchColumn();
$activeCount   = (int) db()->query("SELECT COUNT(*) FROM attendance_sessions WHERE attendance_date = CURDATE() AND status = 'active'")->fetchColumn();

$statusRow = db()->query("
    SELECT
        SUM(ar.status = 'present') AS present,
        SUM(ar.status = 'late')    AS late,
        SUM(ar.status = 'absent')  AS absent,
        COUNT(*)                    AS total
    FROM attendance_records ar
    JOIN attendance_sessions s ON ar.attendance_session_id = s.id
    WHERE s.attendance_date = CURDATE()
")->fetch();

$present = (int) ($statusRow['present'] ?? 0);
$late    = (int) ($statusRow['late'] ?? 0);
$absent  = (int) ($statusRow['absent'] ?? 0);
$logged  = $present + $late + $absent;
$todayISO = date('Y-m-d');
$todayReportsUrl = '/admin/reports.php?date_from=' . $todayISO . '&date_to=' . $todayISO;
$absentReportsUrl = $todayReportsUrl . '&status=absent';
$onTimeRate = $logged > 0 ? round($present / $logged * 100) : 0;
$segP = $logged > 0 ? round($present / $logged * 100, 1) : 0;
$segL = $logged > 0 ? round($late / $logged * 100, 1) : 0;
$segA = $logged > 0 ? max(0, 100 - $segP - $segL) : 0;

/* ---------- Per-section breakdown (today) ---------- */
$sectionRows = db()->query("
    SELECT sec.id AS section_id, sec.name AS section_name,
           SUM(ar.status = 'present') AS present,
           SUM(ar.status = 'late')    AS late,
           SUM(ar.status = 'absent')  AS absent,
           COUNT(ar.id)               AS total
    FROM attendance_records ar
    JOIN attendance_sessions s  ON ar.attendance_session_id = s.id
    JOIN schedule_entries    se ON s.schedule_entry_id = se.id
    JOIN sections            sec ON se.section_id = sec.id
    WHERE s.attendance_date = CURDATE()
    GROUP BY sec.id, sec.name
    ORDER BY total DESC, sec.name ASC
    LIMIT 8
")->fetchAll();

/* ---------- Absent today (who + where) ---------- */
$absentToday = db()->query("
    SELECT st.id AS student_id, st.first_name, st.last_name,
           sec.name AS section_name, subj.name AS subject_name,
           se.room, ar.timestamp
    FROM attendance_records ar
    JOIN attendance_sessions s ON ar.attendance_session_id = s.id
    JOIN schedule_entries se ON s.schedule_entry_id = se.id
    JOIN students st ON ar.student_id = st.id
    JOIN sections sec ON se.section_id = sec.id
    JOIN subjects subj ON se.subject_id = subj.id
    WHERE s.attendance_date = CURDATE() AND ar.status = 'absent'
    ORDER BY ar.timestamp DESC
    LIMIT 20
")->fetchAll();$activeSessions = db()->query("
    SELECT s.id, s.started_at,
           (SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_session_id = s.id AND ar.status = 'present') AS live_present,
           (SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_session_id = s.id AND ar.status = 'late')    AS live_late,
           t.first_name AS tf, t.last_name AS tl,
           subj.name AS subject_name, sec.name AS section_name,
           se.start_time, se.end_time, se.room
    FROM attendance_sessions s
    JOIN schedule_entries se ON s.schedule_entry_id = se.id
    JOIN teachers  t    ON s.teacher_id = t.id
    JOIN subjects  subj ON se.subject_id = subj.id
    JOIN sections  sec  ON se.section_id = sec.id
    WHERE s.attendance_date = CURDATE() AND s.status = 'active'
    ORDER BY s.started_at DESC
")->fetchAll();

/* ---------- Recent scans feed ---------- */
$recentScans = db()->query("
    SELECT ar.status, ar.timestamp, st.id AS student_id,
           st.first_name, st.last_name,
           subj.name AS subject_name, sec.name AS section_name
    FROM attendance_records ar
    JOIN students         st  ON ar.student_id = st.id
    JOIN attendance_sessions s ON ar.attendance_session_id = s.id
    JOIN schedule_entries  se ON s.schedule_entry_id = se.id
    JOIN subjects          subj ON se.subject_id = subj.id
    JOIN sections          sec  ON se.section_id = sec.id
    ORDER BY ar.timestamp DESC
    LIMIT 12
")->fetchAll();

/* ---------- Parent email notification delivery (today) ---------- */
$mail = ['sent' => 0, 'pending' => 0, 'failed' => 0];
try {
    $mailRow = db()->query("
        SELECT
            SUM(status = 'sent')    AS sent,
            SUM(status = 'pending') AS pending,
            SUM(status = 'failed')  AS failed
        FROM sms_logs
        WHERE channel = 'email' AND sms_date = CURDATE()
    ")->fetch();
    $mail = [
        'sent'    => (int) ($mailRow['sent'] ?? 0),
        'pending' => (int) ($mailRow['pending'] ?? 0),
        'failed'  => (int) ($mailRow['failed'] ?? 0),
    ];
} catch (Throwable $e) { /* table may be mid-migration */ }
$mailTotal = $mail['sent'] + $mail['pending'] + $mail['failed'];

$statusClass = fn($s) => $s === 'present' ? 'success' : ($s === 'late' ? 'warning' : 'danger');
$dotColor    = fn($s) => $s === 'present' ? 'var(--success)' : ($s === 'late' ? 'var(--warning)' : 'var(--danger)');

ob_start();
?>
<div class="dashboard-compact">

<!-- Directory stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg></div>
        <div class="stat-info"><span class="stat-number"><?= $totalTeachers ?></span><span class="stat-label">Active Teachers</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
        <div class="stat-info"><span class="stat-number"><?= $totalStudents ?></span><span class="stat-label">Enrolled Students</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c6 3 10 0 12-2v-5"/></svg></div>
        <div class="stat-info"><span class="stat-number"><?= $totalSections ?></span><span class="stat-label">Sections</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
        <div class="stat-info"><span class="stat-number"><?= $totalSubjects ?></span><span class="stat-label">Subjects</span></div>
    </div>
</div>

<!-- Bento — two independent columns so cards never leave holes -->
<div class="dash-cols">

    <!-- ============ MAIN COLUMN ============ -->
    <div class="dash-col dash-col-main">

        <!-- Today's attendance -->
        <div class="card">
            <div class="card-header">
                <h3>Today&rsquo;s Attendance</h3>
                <a href="<?= $todayReportsUrl ?>" class="text-sm">Open in reports &rarr;</a>
            </div>
            <div class="card-body">
                <div class="rate-ring">
                    <div class="ring" style="--val: <?= $onTimeRate ?>;">
                        <span class="ring-num"><?= $onTimeRate ?>%</span>
                        <span class="ring-cap">On&nbsp;time</span>
                    </div>
                    <div class="flex-1">
                        <?php if ($logged === 0): ?>
                            <p class="text-muted text-sm">No attendance logged yet today. Numbers appear as teachers scan students in.</p>
                        <?php else: ?>
                            <div class="breakdown">
                                <span class="seg-present" style="width: <?= $segP ?>%"></span>
                                <span class="seg-late" style="width: <?= $segL ?>%"></span>
                                <span class="seg-absent" style="width: <?= $segA ?>%"></span>
                            </div>
                            <div class="breakdown-legend">
                                <span><i class="dot-present"></i><?= $present ?> Present</span>
                                <span><i class="dot-late"></i><?= $late ?> Late</span>
                                <span><i class="dot-absent"></i><?= $absent ?> Absent</span>
                            </div>
                            <p class="text-muted text-sm" style="margin:0.65rem 0 0;"><?= $logged ?> scan<?= $logged === 1 ? '' : 's' ?> across <?= $todaySessions ?> session<?= $todaySessions === 1 ? '' : 's' ?> today &middot; <?= date('l, M j') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- By section: hover a bar for the breakdown, click to open that section's report -->
        <div class="card">
            <div class="card-header">
                <h3>By Section &mdash; Today</h3>
                <span class="text-muted text-sm"><?= count($sectionRows) ?> section<?= count($sectionRows) === 1 ? '' : 's' ?> logged</span>
            </div>
            <div class="card-body">
                <?php if (empty($sectionRows)): ?>
                    <div class="empty-state" style="padding:1.5rem 1rem;"><h3>Nothing logged yet</h3><p class="text-sm">Per-section rates build up during the day.</p></div>
                <?php else: ?>
                    <?php foreach ($sectionRows as $r):
                        $t   = (int) $r['total'];
                        $p   = (int) $r['present'];
                        $l   = (int) $r['late'];
                        $a   = (int) $r['absent'];
                        $ok  = $p + $l;
                        $pct = $t > 0 ? (int) round($ok / $t * 100) : 0;
                        $tone = $pct >= 90 ? 'good' : ($pct >= 70 ? 'warn' : 'low');
                        $detail = $p . ' present · ' . $l . ' late · ' . $a . ' absent';
                        $secUrl = '/admin/reports.php?date_from=' . $todayISO . '&date_to=' . $todayISO . '&section_id=' . (int) $r['section_id'];
                    ?>
                        <a class="sec-meter" href="<?= h($secUrl) ?>" data-tone="<?= $tone ?>"
                           aria-label="<?= h($r['section_name']) ?>: <?= $pct ?>% in, <?= h($detail) ?>">
                            <span class="sec-meter-head">
                                <span class="sec-meter-name"><?= h($r['section_name']) ?></span>
                                <span class="sec-meter-val"><b><?= $pct ?>%</b><span><?= $ok ?>/<?= $t ?> in</span></span>
                            </span>
                            <span class="sec-meter-track">
                                <span class="sec-meter-fill" style="--v: <?= $pct ?>%"></span>
                            </span>
                            <span class="meter-tip"><?= h($detail) ?> &middot; open report &rarr;</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent scans -->
        <div class="card">
            <div class="card-header">
                <h3>Recent Scans</h3>
                <a href="/admin/reports.php" class="text-sm">View reports &rarr;</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentScans)): ?>
                    <div class="empty-state" style="padding:1.5rem 1rem;"><h3>No scans yet</h3><p class="text-sm">The newest check-ins show up here.</p></div>
                <?php else: ?>
                    <div class="feed feed-scroll">
                        <?php foreach ($recentScans as $r): ?>
                            <div class="feed-item">
                                <span class="feed-dot" style="background: <?= $dotColor($r['status']) ?>"></span>
                                <div class="feed-main">
                                    <strong><a href="/admin/student.php?id=<?= (int) $r['student_id'] ?>"><?= h($r['first_name'] . ' ' . $r['last_name']) ?></a></strong>
                                    <span><?= h($r['subject_name']) ?> &bull; <?= h($r['section_name']) ?></span>
                                </div>
                                <span class="badge badge-<?= $statusClass($r['status']) ?>"><?= h($r['status']) ?></span>
                                <span class="feed-time"><?= date('g:i A', strtotime($r['timestamp'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ============ SIDE COLUMN ============ -->
    <div class="dash-col dash-col-side">

        <!-- Sessions in progress -->
        <div class="card">
            <div class="card-header">
                <h3>Sessions In Progress</h3>
                <?php if ($activeCount > 0): ?>
                    <span class="live-pill"><span class="blip"></span>Live &bull; <?= $activeCount ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($activeSessions)): ?>
                    <div class="empty-state" style="padding:1.5rem 1rem;">
                        <h3>No classes taking attendance</h3>
                        <p class="text-sm">Active sessions show here in real time.</p>
                    </div>
                <?php else: ?>
                    <div class="feed">
                        <?php foreach ($activeSessions as $s): ?>
                            <div class="feed-item">
                                <span class="feed-dot" style="background: var(--success)"></span>
                                <div class="feed-main">
                                    <strong><?= h($s['subject_name']) ?> &bull; <?= h($s['section_name']) ?></strong>
                                    <span><?= h($s['tf'] . ' ' . $s['tl']) ?> &bull; Room <?= h($s['room'] ?: 'N/A') ?> &bull; <?= (int) $s['live_present'] ?> in, <?= (int) $s['live_late'] ?> late</span>
                                </div>
                                <span class="feed-time"><?= format_time($s['start_time']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Absent today -->
        <div class="card">
            <div class="card-header">
                <h3>Absent Today</h3>
                <a href="<?= $absentReportsUrl ?>" class="text-sm">All <?= $absent ?> &rarr;</a>
            </div>
            <div class="card-body">
                <?php if (empty($absentToday)): ?>
                    <div class="empty-state" style="padding:1.5rem 1rem;"><h3>No absences recorded</h3><p class="text-sm">Absences appear here when a session closes.</p></div>
                <?php else: ?>
                    <div class="feed feed-scroll">
                        <?php foreach ($absentToday as $a): ?>
                            <div class="feed-item">
                                <span class="feed-dot" style="background: var(--danger)"></span>
                                <div class="feed-main">
                                    <strong><a href="/admin/student.php?id=<?= (int) $a['student_id'] ?>"><?= h($a['first_name'] . ' ' . $a['last_name']) ?></a></strong>
                                    <span><?= h($a['subject_name']) ?> &bull; <?= h($a['section_name']) ?><?= $a['room'] ? ' &bull; Room ' . h($a['room']) : '' ?></span>
                                </div>
                                <span class="badge badge-danger">absent</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Parent email notifications -->
        <div class="card">
            <div class="card-header">
                <h3>Parent Email</h3>
                <span class="text-muted text-sm">Today</span>
            </div>
            <div class="card-body">
                <?php if ($mailTotal === 0): ?>
                    <p class="text-muted text-sm" style="margin:0;">No arrival / dismissal emails queued yet today.</p>
                <?php else: ?>
                    <div class="summary-grid" style="margin-bottom:0;">
                        <div class="summary-card is-present"><div class="number"><?= $mail['sent'] ?></div><div class="label">Sent</div></div>
                        <div class="summary-card is-late"><div class="number"><?= $mail['pending'] ?></div><div class="label">Pending</div></div>
                        <div class="summary-card is-absent"><div class="number"><?= $mail['failed'] ?></div><div class="label">Failed</div></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="card">
            <div class="card-header"><h3>Quick Actions</h3></div>
            <div class="card-body">
                <div class="quick-links">
                    <a class="quick-link" href="/admin/students.php?action=add"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>Add Student</a>
                    <a class="quick-link" href="/admin/schedule.php?action=add"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>New Schedule</a>
                    <a class="quick-link" href="/admin/qr-codes.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM21 14h-3M14 21h3"/></svg>Print QR Cards</a>
                    <a class="quick-link" href="/admin/reports.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Attendance Report</a>
                    <a class="quick-link" href="/admin/teachers.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Manage Teachers</a>
                    <a class="quick-link" href="/admin/audit-logs.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Audit Logs</a>
                </div>
            </div>
        </div>

    </div>
</div>
</div>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
