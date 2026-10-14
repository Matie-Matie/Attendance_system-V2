<?php
/**
 * Admin - Student Detail
 * Info, enrolled subjects (via section schedule), recent attendance.
 */
$pageTitle = 'Student';
$currentPage = 'students';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT st.*, sec.name AS section_name, sec.grade_level, sec.track_strand
    FROM students st
    LEFT JOIN sections sec ON st.section_id = sec.id
    WHERE st.id = ?
");
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) { flash('error', 'Student not found.'); redirect('/admin/students.php'); }
$pageTitle = $student['first_name'] . ' ' . $student['last_name'];

/* Enrolled subjects: distinct subjects scheduled for this student's section */
$subjects = [];
if (!empty($student['section_id'])) {
    $q = db()->prepare("
        SELECT DISTINCT s.id, s.name, s.code,
               (SELECT CONCAT(t.first_name, ' ', t.last_name) FROM schedule_entries se2
                JOIN teachers t ON se2.teacher_id = t.id
                WHERE se2.subject_id = s.id AND se2.section_id = ? AND se2.status = 1 LIMIT 1) AS teacher_name
        FROM schedule_entries se
        JOIN subjects s ON se.subject_id = s.id
        WHERE se.section_id = ? AND se.status = 1
        ORDER BY s.name
    ");
    $q->execute([$student['section_id'], $student['section_id']]);
    $subjects = $q->fetchAll();
}

/* Recent attendance */
$recent = db()->prepare("
    SELECT ar.status, ar.timestamp, s.name AS subject_name, sec.name AS section_name, sess.attendance_date
    FROM attendance_records ar
    JOIN attendance_sessions sess ON ar.attendance_session_id = sess.id
    JOIN schedule_entries se ON sess.schedule_entry_id = se.id
    JOIN subjects s ON se.subject_id = s.id
    JOIN sections sec ON se.section_id = sec.id
    WHERE ar.student_id = ?
    ORDER BY ar.timestamp DESC
    LIMIT 15
");
$recent->execute([$id]);
$recent = $recent->fetchAll();

$totals = ['present' => 0, 'late' => 0, 'absent' => 0];
foreach ($recent as $r) { if (isset($totals[$r['status']])) $totals[$r['status']]++; }

ob_start();
?>
<div class="page-intro">
    <div class="page-intro-main">
        <h1><?= h($student['first_name'] . ' ' . $student['last_name']) ?></h1>
        <p><code><?= h($student['student_id']) ?></code>
            <?= $student['section_name'] ? ' · <a href="/admin/sections.php?search=' . urlencode($student['section_name']) . '">' . h($student['section_name']) . '</a>' : ' · <span class="text-muted">No section</span>' ?>
            <?= $student['grade_level'] ? ' · ' . h($student['grade_level']) : '' ?>
            <?= $student['track_strand'] ? ' ' . h($student['track_strand']) : '' ?></p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div><strong><?= $totals['present'] ?></strong><span>Present*</span></div></div>
        <div class="mini-stat"><div><strong><?= $totals['late'] ?></strong><span>Late*</span></div></div>
        <div class="mini-stat"><div><strong><?= $totals['absent'] ?></strong><span>Absent*</span></div></div>
    </div>
</div>
<p class="text-xs text-muted" style="margin:-0.5rem 0 1rem;">*Last <?= count($recent) ?> records.</p>

<div class="dash-grid">
    <div class="card">
        <div class="card-header"><h3>Enrolled Subjects (<?= count($subjects) ?>)</h3>
            <a href="/admin/students.php?action=edit&id=<?= (int)$student['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
        </div>
        <div class="card-body">
            <?php if (empty($subjects)): ?>
                <p class="text-muted text-sm"><?= $student['section_name'] ? 'This section has no scheduled subjects yet.' : 'Assign a section first — subjects come from the section schedule.' ?></p>
            <?php else: ?>
                <div class="feed">
                    <?php foreach ($subjects as $s): ?>
                        <div class="feed-item">
                            <span class="feed-dot" style="background: var(--info)"></span>
                            <div class="feed-main">
                                <strong><a href="/admin/subject.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a></strong>
                                <span><?= $s['code'] ? h($s['code']) . ' · ' : '' ?><?= $s['teacher_name'] ? h($s['teacher_name']) : 'No teacher assigned' ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Parent Contact</h3></div>
        <div class="card-body">
            <p><strong><?= h($student['parent_name'] ?: '—') ?></strong></p>
            <p class="text-sm"><?= $student['parent_contact'] ? '<code>' . h($student['parent_contact']) . '</code>' : '<span class="text-muted">No contact no.</span>' ?></p>
            <p class="text-sm text-muted"><?= h($student['parent_email'] ?: 'No email on file — arrival/dismissal emails need one.') ?></p>
            <a href="/admin/qr-codes.php?student_id=<?= (int)$student['id'] ?>" class="btn btn-sm btn-primary" style="margin-top:0.5rem;">QR Card</a>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1.25rem;">
    <div class="card-header"><h3>Recent Attendance</h3></div>
    <div class="card-body p-0">
        <?php if (empty($recent)): ?>
            <div class="empty-state"><h3>No attendance yet</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Subject</th><th>Section</th><th>Status</th><th>Time</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?= format_date($r['attendance_date']) ?></td>
                                <td><?= h($r['subject_name']) ?></td>
                                <td><?= h($r['section_name']) ?></td>
                                <td><span class="badge <?= $r['status'] === 'present' ? 'badge-success' : ($r['status'] === 'late' ? 'badge-warning' : 'badge-danger') ?>"><?= strtoupper($r['status']) ?></span></td>
                                <td><?= date('h:i A', strtotime($r['timestamp'])) ?></td>
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
