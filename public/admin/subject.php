<?php
/**
 * Admin - Subject Detail
 * Assigned teachers, sections, enrolled students, recent sessions.
 */
$pageTitle = 'Subject';
$currentPage = 'subjects';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM subjects WHERE id = ?");
$stmt->execute([$id]);
$subject = $stmt->fetch();
if (!$subject) { flash('error', 'Subject not found.'); redirect('/admin/subjects.php'); }
$pageTitle = $subject['name'];

/* Teachers handling this subject (via the master schedule) */
$teachers = db()->prepare("
    SELECT DISTINCT t.id, t.first_name, t.last_name, t.employee_id,
           COUNT(DISTINCT se.id) AS classes
    FROM schedule_entries se
    JOIN teachers t ON se.teacher_id = t.id
    WHERE se.subject_id = ? AND se.status = 1
    GROUP BY t.id, t.first_name, t.last_name, t.employee_id
    ORDER BY t.first_name, t.last_name
");
$teachers->execute([$id]);
$teachers = $teachers->fetchAll();

/* Sections taking this subject + enrolled student counts */
$sections = db()->prepare("
    SELECT sec.id, sec.name, sec.grade_level, sec.track_strand,
           se.day_of_week, se.start_time, se.end_time, se.room,
           t.first_name AS tf, t.last_name AS tl,
           (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status = 1) AS students
    FROM schedule_entries se
    JOIN sections sec ON se.section_id = sec.id
    JOIN teachers t ON se.teacher_id = t.id
    WHERE se.subject_id = ? AND se.status = 1
    ORDER BY FIELD(se.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), se.start_time
");
$sections->execute([$id]);
$sections = $sections->fetchAll();

/* Every enrolled student across those sections */
$sectionIds = array_values(array_unique(array_column($sections, 'id')));
$students = [];
if (!empty($sectionIds)) {
    $in = implode(',', array_fill(0, count($sectionIds), '?'));
    $q = db()->prepare("
        SELECT st.id, st.student_id, st.first_name, st.last_name, sec.name AS section_name
        FROM students st
        JOIN sections sec ON st.section_id = sec.id
        WHERE st.section_id IN ($in) AND st.status = 1
        ORDER BY st.last_name, st.first_name
    ");
    $q->execute($sectionIds);
    $students = $q->fetchAll();
}

/* Recent sessions */
$sessions = db()->prepare("
    SELECT s.attendance_date, s.status,
           sec.name AS section_name,
           (SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_session_id = s.id AND ar.status IN ('present','late')) AS inside,
           (SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_session_id = s.id) AS total
    FROM attendance_sessions s
    JOIN schedule_entries se ON s.schedule_entry_id = se.id
    JOIN sections sec ON se.section_id = sec.id
    WHERE se.subject_id = ?
    ORDER BY s.attendance_date DESC, s.id DESC
    LIMIT 10
");
$sessions->execute([$id]);
$sessions = $sessions->fetchAll();

ob_start();
?>
<div class="page-intro">
    <div class="page-intro-main">
        <h1><?= h($subject['name']) ?> <?= $subject['code'] ? '<em>' . h($subject['code']) . '</em>' : '' ?></h1>
        <p><?= h($subject['description'] ?: 'No description.') ?></p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div><strong><?= count($teachers) ?></strong><span>Teachers</span></div></div>
        <div class="mini-stat"><div><strong><?= count($sectionIds) ?></strong><span>Sections</span></div></div>
        <div class="mini-stat"><div><strong><?= count($students) ?></strong><span>Students</span></div></div>
    </div>
</div>

<?php if (empty($teachers)): ?>
    <div class="card"><div class="card-body">
        <div class="empty-state"><h3>Not assigned yet</h3><p class="text-sm">No teacher or class handles this subject. Add it to the master schedule.</p>
        <a href="/admin/schedule.php?action=add" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">Add to Schedule</a></div>
    </div></div>
<?php else: ?>
<div class="dash-grid">
    <div class="card">
        <div class="card-header"><h3>Teachers (<?= count($teachers) ?>)</h3></div>
        <div class="card-body">
            <div class="feed">
                <?php foreach ($teachers as $t): ?>
                    <div class="feed-item">
                        <span class="avatar"><?= h(strtoupper(substr($t['first_name'],0,1).substr($t['last_name'],0,1))) ?></span>
                        <div class="feed-main">
                            <strong><a href="/admin/teachers.php?search=<?= urlencode($t['first_name'] . ' ' . $t['last_name']) ?>"><?= h($t['first_name'] . ' ' . $t['last_name']) ?></a></strong>
                            <span><?= (int)$t['classes'] ?> class<?= (int)$t['classes']===1?'':'es' ?> of this subject</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Recent Sessions</h3></div>
        <div class="card-body">
            <?php if (empty($sessions)): ?>
                <p class="text-muted text-sm">No attendance taken yet for this subject.</p>
            <?php else: ?>
                <div class="feed">
                    <?php foreach ($sessions as $s): ?>
                        <div class="feed-item">
                            <span class="feed-dot" style="background: <?= $s['status'] === 'closed' ? 'var(--success)' : 'var(--warning)' ?>"></span>
                            <div class="feed-main">
                                <strong><?= format_date($s['attendance_date']) ?></strong>
                                <span><?= h($s['section_name']) ?> · <?= (int)$s['inside'] ?>/<?= (int)$s['total'] ?> in</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:1.25rem;">
    <div class="card-header"><h3>Enrolled Students (<?= count($students) ?>)</h3><span class="text-muted text-sm">All sections taking this subject</span></div>
    <div class="card-body p-0">
        <?php if (empty($students)): ?>
            <div class="empty-state"><h3>No students enrolled</h3></div>
        <?php else: ?>
            <div class="table-responsive" style="max-height:480px;overflow-y:auto;">
                <table class="table">
                    <thead><tr><th>Student</th><th>ID</th><th>Section</th></tr></thead>
                    <tbody>
                        <?php foreach ($students as $st): ?>
                            <tr>
                                <td><strong><a href="/admin/student.php?id=<?= (int)$st['id'] ?>"><?= h($st['first_name'] . ' ' . $st['last_name']) ?></a></strong></td>
                                <td><code style="font-size:0.75rem;"><?= h($st['student_id']) ?></code></td>
                                <td><a href="/admin/sections.php?search=<?= urlencode($st['section_name']) ?>"><?= h($st['section_name']) ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:1.25rem;">
    <div class="card-header"><h3>Class Meetings</h3></div>
    <div class="card-body p-0">
        <?php if (empty($sections)): ?>
            <div class="empty-state"><h3>No class meetings</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Day</th><th>Time</th><th>Section</th><th>Teacher</th><th>Room</th></tr></thead>
                    <tbody>
                        <?php foreach ($sections as $m): ?>
                            <tr>
                                <td><?= h($m['day_of_week']) ?></td>
                                <td><?= format_time($m['start_time']) ?> — <?= format_time($m['end_time']) ?></td>
                                <td><a href="/admin/sections.php?search=<?= urlencode($m['name']) ?>"><?= h($m['name']) ?></a></td>
                                <td><a href="/admin/teachers.php?search=<?= urlencode($m['tf'] . ' ' . $m['tl']) ?>"><?= h($m['tf'] . ' ' . $m['tl']) ?></a></td>
                                <td><?= $m['room'] ? '<code>'.h($m['room']).'</code>' : '<span class="text-muted">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
