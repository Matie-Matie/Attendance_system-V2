<?php
/**
 * Admin - Teacher Schedule (weekly calendar)
 */
$pageTitle = 'Teacher Schedule';
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
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 0);
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

        // Conflict: same ROOM, same day, overlapping time. Two classes can't
        // meet in one room at once. Room names are normalized (case +
        // whitespace) so "RM 201" and "rm  201" still clash.
        $roomClashCount = 0;
        $roomKey = strtolower(preg_replace('/\s+/', ' ', trim($room)));
        if ($roomKey !== '') {
            $roomClash = db()->prepare("
                SELECT COUNT(*) FROM schedule_entries
                WHERE day_of_week = ? AND status = 1 AND id != ?
                  AND room IS NOT NULL AND TRIM(room) != ''
                  AND LOWER(REGEXP_REPLACE(TRIM(room), '[[:space:]]+', ' ')) = ?
                  AND start_time < ? AND end_time > ?
            ");
            try {
                $roomClash->execute([$dayOfWeek, $id, $roomKey, $endTime, $startTime]);
                $roomClashCount = (int) $roomClash->fetchColumn();
            } catch (\Throwable $e) {
                // Older MySQL/MariaDB without REGEXP_REPLACE: fall back to a
                // PHP-side check below instead of failing the save.
                $roomClashCount = -1;
            }
        }
        if ($roomClashCount === -1 && $roomKey !== '') {
            $siblings = db()->prepare("
                SELECT room FROM schedule_entries
                WHERE day_of_week = ? AND status = 1 AND id != ?
                  AND room IS NOT NULL AND TRIM(room) != ''
                  AND start_time < ? AND end_time > ?
            ");
            $siblings->execute([$dayOfWeek, $id, $endTime, $startTime]);
            foreach ($siblings->fetchAll() as $sib) {
                if (strtolower(preg_replace('/\s+/', ' ', trim($sib['room']))) === $roomKey) {
                    $roomClashCount = 1;
                    break;
                }
            }
            if ($roomClashCount === -1) $roomClashCount = 0;
        }

        if ($teacherClash->fetchColumn() > 0) {
            $clash = 'This teacher already has a class during this time on ' . $dayOfWeek . '.';
        } elseif ($sectionClash->fetchColumn() > 0) {
            $clash = 'This section already has a class during this time on ' . $dayOfWeek . '.';
        } elseif ($roomClashCount > 0) {
            $clash = 'Room ' . $room . ' is already in use during this time on ' . $dayOfWeek . '.';
        } else {
            $clash = null;
        }
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
                // Semesters are merged into the school year — file the entry
                // under the current school year's single term.
                $semesterId = current_term_id();
                if (!$semesterId) {
                    $_SESSION['_flash']['error'] = 'Add a school year first (School Records → School Years).';
                    preserve_input($_POST);
                    redirect('/admin/schedule.php?action=add');
                }
                $stmt = db()->prepare("INSERT INTO schedule_entries (teacher_id, subject_id, section_id, semester_id, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$teacherId, $subjectId, $sectionId, $semesterId, $dayOfWeek, $startTime, $endTime, $room ?: null]);
                log_audit('create', 'schedule_entry', (int) db()->lastInsertId(), ['day' => $dayOfWeek, 'start' => $startTime]);
                $_SESSION['_flash']['success'] = 'Class added to the schedule.';
                redirect('/admin/schedule.php?teacher_id=' . $teacherId);
            } elseif ($submitAction === 'edit' && $id > 0) {
                $stmt = db()->prepare("UPDATE schedule_entries SET teacher_id = ?, subject_id = ?, section_id = ?, day_of_week = ?, start_time = ?, end_time = ?, room = ? WHERE id = ?");
                $stmt->execute([$teacherId, $subjectId, $sectionId, $dayOfWeek, $startTime, $endTime, $room ?: null, $id]);
                log_audit('update', 'schedule_entry', $id, ['day' => $dayOfWeek, 'start' => $startTime]);
                $_SESSION['_flash']['success'] = 'Class updated.';
                redirect('/admin/schedule.php?teacher_id=' . $teacherId);
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
        'teacher_id' => (int)($_GET['teacher_id'] ?? 0), 'subject_id' => 0, 'section_id' => 0,
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
        <div class="card-header"><h3><?= $isEdit ? 'Edit Class' : 'Add Class' ?></h3><a href="/admin/schedule.php" class="btn btn-outline btn-sm">Back to Schedule</a></div>
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
            <?php if ($isEdit): ?>
                <form method="POST" class="schedule-delete" onsubmit="return confirm('Delete this class from the schedule?')">
                    <input type="hidden" name="submit_action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Delete this class</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php
} else {
    // Calendar mode — a weekly class program, like the printed schedule the
    // school hands out. Pick a teacher to see only their week.
    $teacherFilter = (int)($_GET['teacher_id'] ?? 0);
    $where = 'WHERE se.status = 1'; $params = [];
    if ($teacherFilter) { $where .= ' AND se.teacher_id = ?'; $params[] = $teacherFilter; }

    $stmt = db()->prepare("
        SELECT se.*, t.first_name as teacher_first, t.last_name as teacher_last,
               s.name as subject_name, s.code as subject_code,
               sec.name as section_name, sec.grade_level
        FROM schedule_entries se
        JOIN teachers t ON se.teacher_id = t.id
        JOIN subjects s ON se.subject_id = s.id
        JOIN sections sec ON se.section_id = sec.id
        $where
        ORDER BY FIELD(se.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), se.start_time ASC, se.end_time ASC
    ");
    $stmt->execute($params); $entries = $stmt->fetchAll();

    $selectedTeacher = null;
    foreach ($teachers as $t) { if ((int)$t['id'] === $teacherFilter) { $selectedTeacher = $t; break; } }

    $toMin = fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);

    // Visible window: whole hours covering every class, never less than 7 AM – 5 PM.
    $winStart = 7 * 60; $winEnd = 17 * 60; $weekMinutes = 0;
    foreach ($entries as $e) {
        $winStart = min($winStart, intdiv($toMin($e['start_time']), 60) * 60);
        $winEnd = max($winEnd, (int) ceil($toMin($e['end_time']) / 60) * 60);
        $weekMinutes += $toMin($e['end_time']) - $toMin($e['start_time']);
    }
    $hours = intdiv($winEnd - $winStart, 60);

    // Mon–Fri always; Saturday only when something is scheduled on it.
    $calDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    foreach ($entries as $e) { if ($e['day_of_week'] === 'Saturday') { $calDays[] = 'Saturday'; break; } }

    // Classes that overlap (only possible across teachers) sit side by side:
    // group each day into overlapping clusters, then give every class a lane.
    $byDay = array_fill_keys($calDays, []);
    foreach ($entries as $e) { $byDay[$e['day_of_week']][] = $e; }
    foreach ($byDay as &$list) {
        $clusters = []; $cluster = []; $clusterEnd = -1;
        foreach ($list as $i => $e) {
            if ($cluster && $toMin($e['start_time']) >= $clusterEnd) { $clusters[] = $cluster; $cluster = []; $clusterEnd = -1; }
            $cluster[] = $i;
            $clusterEnd = max($clusterEnd, $toMin($e['end_time']));
        }
        if ($cluster) $clusters[] = $cluster;
        foreach ($clusters as $cl) {
            $laneEnds = [];
            foreach ($cl as $i) {
                $start = $toMin($list[$i]['start_time']);
                $lane = count($laneEnds);
                foreach ($laneEnds as $l => $end) { if ($end <= $start) { $lane = $l; break; } }
                $laneEnds[$lane] = $toMin($list[$i]['end_time']);
                $list[$i]['_lane'] = $lane;
            }
            foreach ($cl as $i) { $list[$i]['_lanes'] = count($laneEnds); }
        }
    }
    unset($list);

    // Stable colour per subject so the same subject reads the same all week.
    $hues = [352, 212, 148, 32, 268, 186, 12, 300];
    $hueFor = fn(int $subjectId): int => $hues[$subjectId % count($hues)];

    $todayName = date('l');
    $nowMin = $toMin(date('H:i'));
    $hourLabel = fn(int $m): string => date('g A', mktime(intdiv($m, 60), 0));
    // "8:00–9:00 AM" (one AM/PM when both ends share it) so narrow lanes don't wrap.
    $shortRange = function (string $a, string $b): string {
        $ta = strtotime($a); $tb = strtotime($b);
        return date('A', $ta) === date('A', $tb)
            ? date('g:i', $ta) . '–' . date('g:i A', $tb)
            : date('g:i A', $ta) . '–' . date('g:i A', $tb);
    };
?>

<div class="page-intro no-print">
    <div class="page-intro-main">
        <h1>Teacher <em>Schedule</em></h1>
        <p>The weekly class program — who teaches what, to whom, when, and where. Pick a teacher to see their own week. Room, teacher & section conflicts are blocked automatically.</p>
    </div>
</div>

<div class="filter-pillbar">
    <form method="GET" class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <select name="teacher_id" class="form-control" aria-label="Teacher" style="width:auto; max-width:260px; padding:0.45rem 1.9rem 0.45rem 0.9rem; border-radius:999px; font-size:0.82rem;" onchange="this.form.submit()">
            <option value="">All teachers</option>
            <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $teacherFilter === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['first_name'] . ' ' . $t['last_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($teacherFilter): ?><a href="/admin/schedule.php" class="btn btn-sm btn-outline" style="border-radius:999px;">Show all</a><?php endif; ?>
        <noscript><button type="submit" class="btn btn-sm btn-outline">Go</button></noscript>
    </form>
    <div class="fp-actions">
        <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">Print</button>
        <a href="/admin/schedule.php?action=add<?= $teacherFilter ? '&teacher_id=' . $teacherFilter : '' ?>" class="btn btn-primary btn-sm">+ Add Class</a>
    </div>
</div>

<?php if (empty($entries) && !$teacherFilter): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state" style="padding: 3rem 1rem;">
                <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                <h3>No classes scheduled yet</h3>
                <p>Add class meetings so teachers can take attendance.</p>
                <a href="/admin/schedule.php?action=add" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">Add First Class</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="print-only cal-print-head">
        <h2>Class Schedule — <?= $selectedTeacher ? h($selectedTeacher['first_name'] . ' ' . $selectedTeacher['last_name']) : 'All Teachers' ?></h2>
        <p><?= config('school_name') ?> · S.Y. <?= config('academic_year') ?></p>
    </div>

    <div class="card cal-card">
        <div class="card-header tinted no-print">
            <h3><?= $selectedTeacher ? h($selectedTeacher['first_name'] . ' ' . $selectedTeacher['last_name']) : 'All teachers' ?></h3>
            <span class="badge badge-info"><?= count($entries) ?> <?= count($entries) === 1 ? 'class' : 'classes' ?> · <?= rtrim(rtrim(number_format($weekMinutes / 60, 1), '0'), '.') ?> hrs / week</span>
        </div>
        <?php if (empty($entries)): ?>
            <p class="cal-empty no-print">No classes for this teacher yet — use <strong>+ Add Class</strong> to build their week.</p>
        <?php endif; ?>
        <div class="cal-scroll">
            <div class="cal" style="--hours: <?= $hours ?>; --cols: <?= count($calDays) ?>;">
                <div class="cal-corner"></div>
                <?php foreach ($calDays as $d): ?>
                    <div class="cal-dayhead <?= $d === $todayName ? 'is-today' : '' ?>">
                        <?= $d ?>
                        <span><?= count($byDay[$d]) ?> <?= count($byDay[$d]) === 1 ? 'class' : 'classes' ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="cal-times" aria-hidden="true">
                    <?php for ($i = 0; $i < $hours; $i++): ?>
                        <span style="--i: <?= $i ?>;"><?= $hourLabel($winStart + $i * 60) ?></span>
                    <?php endfor; ?>
                </div>
                <?php foreach ($calDays as $d): ?>
                    <div class="cal-col <?= $d === $todayName ? 'is-today' : '' ?>">
                        <?php if ($d === $todayName && $nowMin > $winStart && $nowMin < $winEnd): ?>
                            <div class="cal-now no-print" style="--top: <?= round(($nowMin - $winStart) / 60, 4) ?>;" aria-hidden="true"></div>
                        <?php endif; ?>
                        <?php foreach ($byDay[$d] as $e):
                            $startM = $toMin($e['start_time']); $endM = $toMin($e['end_time']);
                            $teacherName = $e['teacher_first'] . ' ' . $e['teacher_last'];
                            $timeRange = $shortRange($e['start_time'], $e['end_time']);
                            $tip = $e['subject_name'] . ' · ' . $e['section_name'] . ' · ' . $timeRange . ($e['room'] ? ' · ' . $e['room'] : '') . ' · ' . $teacherName;
                        ?>
                            <a class="cal-block <?= $endM - $startM <= 45 ? 'is-short' : '' ?> <?= $endM - $startM <= 60 ? 'is-hour' : '' ?>"
                               href="/admin/schedule.php?action=edit&id=<?= (int)$e['id'] ?>"
                               title="<?= h($tip) ?> — click to edit"
                               style="--top: <?= round(($startM - $winStart) / 60, 4) ?>; --len: <?= round(($endM - $startM) / 60, 4) ?>; --lane: <?= (int)$e['_lane'] ?>; --lanes: <?= (int)$e['_lanes'] ?>; --h: <?= $hueFor((int)$e['subject_id']) ?>;">
                                <span class="cb-time"><?= h($timeRange) ?></span>
                                <strong><?= h($e['subject_name']) ?></strong>
                                <span class="cb-meta"><?= h($e['section_name']) ?><?= $e['room'] ? ' · ' . h($e['room']) : '' ?></span>
                                <?php if (!$teacherFilter): ?><span class="cb-meta cb-teacher"><?= h($teacherName) ?></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <style>@media print { @page { size: landscape; margin: 10mm; } }</style>
<?php endif; ?>

<?php
}

$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
?>
