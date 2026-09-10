<?php
/**
 * Admin - Master Schedule Management
 */
$pageTitle = 'Master Schedule';
$currentPage = 'schedule';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$dayFilter = $_GET['day'] ?? '';

// Get reference data for dropdowns
$teachers = db()->query("SELECT id, first_name, last_name, employee_id FROM teachers WHERE status = 1 ORDER BY first_name")->fetchAll();
$subjects = db()->query("SELECT id, name, code FROM subjects WHERE status = 1 ORDER BY name")->fetchAll();
$sections = db()->query("SELECT id, name, grade_level FROM sections WHERE status = 1 ORDER BY name")->fetchAll();
$semesters = db()->query("SELECT s.id, s.name, sy.name as sy_name FROM semesters s JOIN school_years sy ON s.school_year_id = sy.id WHERE s.is_current = 1 ORDER BY s.created_at DESC")->fetchAll();
// If no current semester, get latest
if (empty($semesters)) {
    $semesters = db()->query("SELECT s.id, s.name, sy.name as sy_name FROM semesters s JOIN school_years sy ON s.school_year_id = sy.id ORDER BY s.created_at DESC LIMIT 1")->fetchAll();
} else {
    // Also include non-current for dropdown
    $otherSemesters = db()->query("SELECT s.id, s.name, sy.name as sy_name FROM semesters s JOIN school_years sy ON s.school_year_id = sy.id WHERE s.is_current = 0 ORDER BY s.created_at DESC")->fetchAll();
    $semesters = array_merge($semesters, $otherSemesters);
}
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 0);
    $semesterId = (int)($_POST['semester_id'] ?? 0);
    $dayOfWeek = $_POST['day_of_week'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $room = trim($_POST['room'] ?? '');
    $submitAction = $_POST['submit_action'] ?? '';

    // --- Delete is its own path: no add/edit field validation ---
    if ($submitAction === 'delete') {
        if ($id > 0) {
            try {
                db()->prepare("DELETE FROM schedule_entries WHERE id = ?")->execute([$id]);
                log_audit('delete', 'schedule_entry', $id);
                $_SESSION['_flash']['success'] = 'Schedule entry deleted.';
            } catch (\Throwable $e) {
                error_log('SCPLS schedule delete: ' . $e->getMessage());
                $_SESSION['_flash']['error'] = 'Could not delete that entry — it may have attendance sessions attached.';
            }
        }
        redirect('/admin/schedule.php');
    }

    $errors = [];
    if (!$teacherId) $errors[] = 'Teacher is required.';
    if (!$subjectId) $errors[] = 'Subject is required.';
    if (!$sectionId) $errors[] = 'Section is required.';
    if (!$semesterId) $errors[] = 'Semester is required.';
    if (!$dayOfWeek) $errors[] = 'Day is required.';
    if (!$startTime) $errors[] = 'Start time is required.';
    if (!$endTime) $errors[] = 'End time is required.';
    if ($startTime >= $endTime) $errors[] = 'End time must be after start time.';

    if (empty($errors)) {
        // Conflict: same TEACHER, same day, overlapping time.
        $teacherClash = db()->prepare("
            SELECT COUNT(*) FROM schedule_entries
            WHERE teacher_id = ? AND day_of_week = ? AND status = 1 AND id != ?
              AND start_time < ? AND end_time > ?
        ");
        $teacherClash->execute([$teacherId, $dayOfWeek, $id, $endTime, $startTime]);

        // Conflict: same SECTION, same day, overlapping time. A section can't be
        // in two classes at once — and a double-booked section breaks the
        // first/last-class logic the parent emails depend on.
        $sectionClash = db()->prepare("
            SELECT COUNT(*) FROM schedule_entries
            WHERE section_id = ? AND day_of_week = ? AND status = 1 AND id != ?
              AND start_time < ? AND end_time > ?
        ");
        $sectionClash->execute([$sectionId, $dayOfWeek, $id, $endTime, $startTime]);

        $clash = $teacherClash->fetchColumn() > 0
            ? 'This teacher already has a class during this time on ' . $dayOfWeek . '.'
            : ($sectionClash->fetchColumn() > 0
                ? 'This section already has a class during this time on ' . $dayOfWeek . '.'
                : null);
        if ($clash) {
            $_SESSION['_flash']['error'] = 'Schedule conflict! ' . $clash;
            preserve_input($_POST);
            $redirectUrl = $submitAction === 'edit' ? "/admin/schedule.php?action=edit&id=$id" : "/admin/schedule.php?action=add";
            redirect($redirectUrl);
        }
    }

    if (empty($errors)) {
        try {
            if ($submitAction === 'add') {
                $stmt = db()->prepare("INSERT INTO schedule_entries (teacher_id, subject_id, section_id, semester_id, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$teacherId, $subjectId, $sectionId, $semesterId, $dayOfWeek, $startTime, $endTime, $room ?: null]);
                log_audit('create', 'schedule_entry', (int) db()->lastInsertId(), ['day' => $dayOfWeek, 'start' => $startTime]);
                $_SESSION['_flash']['success'] = 'Schedule entry created.';
                redirect('/admin/schedule.php');
            } elseif ($submitAction === 'edit' && $id > 0) {
                $stmt = db()->prepare("UPDATE schedule_entries SET teacher_id = ?, subject_id = ?, section_id = ?, semester_id = ?, day_of_week = ?, start_time = ?, end_time = ?, room = ? WHERE id = ?");
                $stmt->execute([$teacherId, $subjectId, $sectionId, $semesterId, $dayOfWeek, $startTime, $endTime, $room ?: null, $id]);
                log_audit('update', 'schedule_entry', $id, ['day' => $dayOfWeek, 'start' => $startTime]);
                $_SESSION['_flash']['success'] = 'Schedule entry updated.';
                redirect('/admin/schedule.php');
            }
        } catch (\Throwable $e) {
            error_log('SCPLS schedule save: ' . $e->getMessage());
            $_SESSION['_flash']['error'] = 'Could not save the schedule entry. Please check the values and try again.';
            redirect('/admin/schedule.php');
        }
    } else {
        $_SESSION['_flash']['error'] = implode(' ', $errors);
        preserve_input($_POST);
        $redirectUrl = $submitAction === 'edit' ? "/admin/schedule.php?action=edit&id=$id" : "/admin/schedule.php?action=add";
        redirect($redirectUrl);
    }
}

ob_start();

if ($action === 'add' || ($action === 'edit' && $id > 0)) {
    $entry = [
        'teacher_id' => 0, 'subject_id' => 0, 'section_id' => 0, 'semester_id' => 0,
        'day_of_week' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'room' => ''
    ];
    if ($action === 'edit') {
        $stmt = db()->prepare("SELECT * FROM schedule_entries WHERE id = ?"); $stmt->execute([$id]); $entry = $stmt->fetch();
        if (!$entry) { flash('error', 'Entry not found.'); redirect('/admin/schedule.php'); }
    }
    // Use old input if available
    foreach ($entry as $k => $v) {
        $old = old($k);
        if ($old) $entry[$k] = $old;
    }
    $isEdit = $action === 'edit';
?>
    <div class="card form-card">
        <div class="card-header"><h3><?= $isEdit ? 'Edit Schedule Entry' : 'New Schedule Entry' ?></h3><a href="/admin/schedule.php" class="btn btn-outline btn-sm">Back to Schedule</a></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="submit_action" value="<?= $isEdit ? 'edit' : 'add' ?>">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="teacher_id">Teacher</label>
                        <select id="teacher_id" name="teacher_id" class="form-control" required>
                            <option value="">-- Select Teacher --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= (int)$entry['teacher_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= h($t['first_name'] . ' ' . $t['last_name']) ?> (<?= h($t['employee_id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="subject_id">Subject</label>
                        <select id="subject_id" name="subject_id" class="form-control" required>
                            <option value="">-- Select Subject --</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>" <?= (int)$entry['subject_id'] === (int)$sub['id'] ? 'selected' : '' ?>>
                                    <?= h($sub['name']) ?> (<?= h($sub['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="section_id">Section</label>
                        <select id="section_id" name="section_id" class="form-control" required>
                            <option value="">-- Select Section --</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= $sec['id'] ?>" <?= (int)$entry['section_id'] === (int)$sec['id'] ? 'selected' : '' ?>>
                                    <?= h($sec['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="semester_id">Semester</label>
                        <select id="semester_id" name="semester_id" class="form-control" required>
                            <option value="">-- Select Semester --</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= $sem['id'] ?>" <?= (int)$entry['semester_id'] === (int)$sem['id'] ? 'selected' : '' ?>>
                                    <?= h($sem['name']) ?> (<?= h($sem['sy_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="day_of_week">Day</label>
                        <select id="day_of_week" name="day_of_week" class="form-control" required>
                            <?php foreach ($days as $d): ?>
                                <option value="<?= $d ?>" <?= $entry['day_of_week'] === $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="start_time">Start Time</label>
                        <input type="time" id="start_time" name="start_time" class="form-control"
                               value="<?= h(substr($entry['start_time'], 0, 5)) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="end_time">End Time</label>
                        <input type="time" id="end_time" name="end_time" class="form-control"
                               value="<?= h(substr($entry['end_time'], 0, 5)) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="room">Room</label>
                        <input type="text" id="room" name="room" class="form-control" value="<?= h($entry['room']) ?>" placeholder="e.g. RM 201">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update Entry' : 'Create Entry' ?></button>
                    <a href="/admin/schedule.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php
} else {
    // List mode — grouped by day
    $where = ''; $params = [];
    if ($dayFilter && in_array($dayFilter, $days)) { $where = "WHERE se.day_of_week = ?"; $params[] = $dayFilter; }

    $stmt = db()->prepare("
        SELECT se.*, t.first_name as teacher_first, t.last_name as teacher_last,
               s.name as subject_name, s.code as subject_code,
               sec.name as section_name, sec.grade_level
        FROM schedule_entries se
        JOIN teachers t ON se.teacher_id = t.id
        JOIN subjects s ON se.subject_id = s.id
        JOIN sections sec ON se.section_id = sec.id
        $where
        ORDER BY FIELD(se.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), se.start_time ASC
    ");
    $stmt->execute($params); $entries = $stmt->fetchAll();

    // Group by day
    $grouped = [];
    foreach ($entries as $e) {
        $grouped[$e['day_of_week']][] = $e;
    }

    $totalAll = (int) db()->query("SELECT COUNT(*) FROM schedule_entries WHERE status = 1")->fetchColumn();
    $daysCovered = (int) db()->query("SELECT COUNT(DISTINCT day_of_week) FROM schedule_entries WHERE status = 1")->fetchColumn();
    $teachersWithLoad = (int) db()->query("SELECT COUNT(DISTINCT teacher_id) FROM schedule_entries WHERE status = 1")->fetchColumn();
    $sectionsCovered = (int) db()->query("SELECT COUNT(DISTINCT section_id) FROM schedule_entries WHERE status = 1")->fetchColumn();
?>

<div class="page-intro">
    <div class="page-intro-main">
        <h1>Master <em>Schedule</em></h1>
        <p>Every class meeting — who teaches what, to whom, when, and where. Conflicts are blocked automatically for teachers & sections.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div><strong><?= $totalAll ?></strong><span>Slots</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div><strong><?= $teachersWithLoad ?></strong><span>Teachers</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--info-light); color: var(--info);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c6 3 10 0 12-2v-5"/></svg></div><div><strong><?= $sectionsCovered ?></strong><span>Sections</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--warning-light); color: #B07D16;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg></div><div><strong><?= $daysCovered ?>/6</strong><span>Days used</span></div></div>
    </div>
</div>

<div class="filter-pillbar">
    <form method="GET" class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <select name="day" class="form-control" style="width:auto; max-width:170px; padding:0.45rem 1.9rem 0.45rem 0.9rem; border-radius:999px; font-size:0.82rem;" onchange="this.form.submit()">
            <option value="">All days</option>
            <?php foreach ($days as $d): ?>
                <option value="<?= $d ?>" <?= $dayFilter === $d ? 'selected' : '' ?>><?= $d ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($dayFilter): ?><a href="/admin/schedule.php" class="btn btn-sm btn-outline" style="border-radius:999px;">Clear</a><?php endif; ?>
        <span class="text-xs text-muted nowrap" style="margin-left:0.5rem;"><?= count($entries) ?> <?= count($entries)===1?'slot':'slots' ?></span>
    </form>
    <div class="fp-actions">
        <a href="/admin/schedule.php?action=add" class="btn btn-primary btn-sm">+ Add Entry</a>
    </div>
</div>

<?php if (empty($entries)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state" style="padding: 3rem 1rem;">
                <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                <h3>No schedule entries</h3>
                <p>The master schedule is empty. Define class meetings so teachers can take attendance.</p>
                <a href="/admin/schedule.php?action=add" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">Create First Entry</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($grouped as $day => $dayEntries): ?>
        <div class="card overflow-hidden" style="margin-bottom:1.25rem;">
            <div class="card-header tinted">
                <h3 style="font-family: var(--font-display); font-size:1.05rem; font-weight: 800; letter-spacing: -0.02em;"><?= $day ?></h3>
                <span class="badge badge-info"><?= count($dayEntries) ?> <?= count($dayEntries)===1?'class':'classes' ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th><th>Teacher</th><th>Subject</th><th>Section</th><th>Room</th><th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dayEntries as $e): ?>
                                <tr>
                                    <td><span class="badge badge-warning" style="font-variant-numeric: tabular-nums;"><?= format_time($e['start_time']) ?> — <?= format_time($e['end_time']) ?></span></td>
                                    <td><div class="flex items-center gap-2"><span class="avatar" style="width:28px;height:28px;font-size:0.68rem;"><?= strtoupper(substr($e['teacher_first'],0,1).substr($e['teacher_last'],0,1)) ?></span><?= h($e['teacher_first'] . ' ' . $e['teacher_last']) ?></div></td>
                                    <td><strong><?= h($e['subject_name']) ?></strong> <code style="font-size:0.70rem; margin-left:0.35rem;"><?= h($e['subject_code']) ?></code></td>
                                    <td><span class="badge badge-success"><?= h($e['section_name']) ?></span></td>
                                    <td><?= h($e['room']) ? '<code>'.h($e['room']).'</code>' : '<span class="text-muted">—</span>' ?></td>
                                    <td style="text-align:right;">
                                        <div class="flex gap-1" style="justify-content:flex-end;">
                                            <a href="/admin/schedule.php?action=edit&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this entry?')">
                                                <input type="hidden" name="submit_action" value="delete">
                                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<style>
    .table td { vertical-align: middle; }
</style>

<?php
}

$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
?>
