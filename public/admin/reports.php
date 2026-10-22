<?php
/**
 * Admin - Attendance Reports
 */
$pageTitle = 'Reports';
$currentPage = 'reports';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

// Default range = the whole school year, so past attendance is never "missing".
$validDate = fn($d, $fallback) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : $fallback;
$dateFrom = $validDate($_GET['date_from'] ?? null, report_default_from());
$dateTo   = $validDate($_GET['date_to'] ?? null, today());
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }
$statusFilterAllowed = ['present', 'late', 'absent'];
$teacherFilter = (int)($_GET['teacher_id'] ?? 0);
$sectionFilter = (int)($_GET['section_id'] ?? 0);
$subjectFilter = (int)($_GET['subject_id'] ?? 0);
$statusFilter = in_array($_GET['status'] ?? '', $statusFilterAllowed, true) ? $_GET['status'] : '';
$export = $_GET['export'] ?? '';

// Reference data for filters
$teachers = db()->query("SELECT id, first_name, last_name FROM teachers WHERE status = 1 ORDER BY first_name")->fetchAll();
$sections = db()->query("SELECT id, name FROM sections WHERE status = 1 ORDER BY name")->fetchAll();
$subjects = db()->query("SELECT id, name FROM subjects WHERE status = 1 ORDER BY name")->fetchAll();

// Build query
$where = ["DATE(ar.timestamp) BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if ($teacherFilter) { $where[] = "se.teacher_id = ?"; $params[] = $teacherFilter; }
if ($sectionFilter) { $where[] = "se.section_id = ?"; $params[] = $sectionFilter; }
if ($subjectFilter) { $where[] = "se.subject_id = ?"; $params[] = $subjectFilter; }
if ($statusFilter) { $where[] = "ar.status = ?"; $params[] = $statusFilter; }

$whereClause = implode(' AND ', $where);

// Get records
$stmt = db()->prepare("
    SELECT ar.*, st.id AS student_pk, st.student_id as sid, st.first_name as sfirst, st.last_name as slast,
           s.name as subject_name, se.subject_id as subject_id, sec.name as section_name,
           sec.grade_level, sec.track_strand, se.room,
           t.first_name as tfirst, t.last_name as tlast,
           as2.attendance_date
    FROM attendance_records ar
    JOIN attendance_sessions as2 ON ar.attendance_session_id = as2.id
    JOIN schedule_entries se ON as2.schedule_entry_id = se.id
    JOIN students st ON ar.student_id = st.id
    JOIN subjects s ON se.subject_id = s.id
    JOIN sections sec ON se.section_id = sec.id
    JOIN teachers t ON se.teacher_id = t.id
    WHERE $whereClause
    ORDER BY ar.timestamp DESC
    " . ($export === 'csv' ? '' : 'LIMIT 1000') . "
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// "G11 HUMSS" style class label from grade level + strand.
$classLabel = function (array $r): string {
    $grade   = preg_replace('/[^0-9]/', '', (string) ($r['grade_level'] ?? ''));
    $strand  = trim((string) ($r['track_strand'] ?? ''));
    $section = trim((string) ($r['section_name'] ?? ''));

    // "Grade 11 - ABM" -> "ABM" so the grade is never printed twice.
    $stripped = preg_replace('/^grade\s*\d+\s*[-–—:]?\s*/i', '', $section);
    $short    = ($stripped !== null && $stripped !== '') ? $stripped : $section;
    $head     = trim(($grade !== '' ? 'G' . $grade : '') . ' ' . $strand);

    $label = $head !== '' ? $head : $short;
    if ($head !== '' && $short !== '' && mb_stripos($head, $short) === false) {
        $label .= ' · ' . $short;
    }

    if (!empty($r['room'])) {
        $room   = trim((string) $r['room']);
        $label .= ' · ' . (preg_match('/^(room|rm\.?)\s/i', $room) ? $room : 'Room ' . $room);
    }

    return $label;
};


// True totals for the whole range (the table below is capped, these are not).
$countStmt = db()->prepare("
    SELECT ar.status, COUNT(*) AS n
    FROM attendance_records ar
    JOIN attendance_sessions as2 ON ar.attendance_session_id = as2.id
    JOIN schedule_entries se ON as2.schedule_entry_id = se.id
    WHERE $whereClause
    GROUP BY ar.status
");
$countStmt->execute($params);
$statusCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$presentCount = (int) ($statusCounts['present'] ?? 0);
$lateCount    = (int) ($statusCounts['late'] ?? 0);
$absentCount  = (int) ($statusCounts['absent'] ?? 0);
$totalRecords = $presentCount + $lateCount + $absentCount;

// CSV Export
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-report-' . $dateFrom . '-to-' . $dateTo . '.csv"');
    $output = fopen('php://output', 'w');
    // Neutralise spreadsheet formula injection (a name like "=cmd|...").
    $safe = fn($v) => (is_string($v) && $v !== '' && str_contains('=+-@', $v[0])) ? "'" . $v : $v;
    fputcsv($output, ['Date', 'Student ID', 'Student Name', 'Subject', 'Section', 'Teacher', 'Status', 'Time'], ',', '"', '\\');

    foreach ($records as $r) {
        fputcsv($output, array_map($safe, [
            $r['attendance_date'],
            $r['sid'],
            $r['sfirst'] . ' ' . $r['slast'],
            $r['subject_name'],
            $r['section_name'],
            $r['tfirst'] . ' ' . $r['tlast'],
            $r['status'],
            date('h:i A', strtotime($r['timestamp']))
        ]), ',', '"', '\\');
    }
    fclose($output);
    exit;
}

ob_start();
?>

<div class="page-intro no-print" style="margin-bottom:1.5rem;">
    <div class="page-intro-main">
        <h1>Reports</h1>
        <p>Filter by date, teacher, section, subject, or status. Export clean CSV for records — formula-injection safe.</p>
    </div>
</div>

<div class="card mb-3 no-print" style="border-radius: 22px;">
    <div class="card-header">
        <h3>Filter Reports</h3>
        <div class="flex gap-1">
            <button type="button" class="btn btn-sm btn-outline" onclick="window.print()">Print</button>
            <a href="/admin/reports.php?<?= http_build_query([
                'export' => 'csv', 'date_from' => $dateFrom, 'date_to' => $dateTo,
                'teacher_id' => $teacherFilter, 'section_id' => $sectionFilter,
                'subject_id' => $subjectFilter, 'status' => $statusFilter,
           ]) ?>"
           class="btn btn-sm btn-primary <?= empty($records) ? 'disabled' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="range-pills" role="group" aria-label="Quick date range">
            <?php foreach (report_ranges() as $label => [$rf, $rt]): ?>
                <a href="<?= h('/admin/reports.php?' . http_build_query(array_filter(['date_from' => $rf, 'date_to' => $rt, 'teacher_id' => $teacherFilter ?: null, 'section_id' => $sectionFilter ?: null, 'subject_id' => $subjectFilter ?: null, 'status' => $statusFilter ?: null]))) ?>" class="fp-pill <?= $dateFrom === $rf && $dateTo === $rt ? 'active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="" class="filter-bar">
            <div class="form-group">
                <label>From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="form-group">
                <label>To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="form-group">
                <label>Teacher</label>
                <select name="teacher_id" class="form-control">
                    <option value="">All Teachers</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $teacherFilter === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['first_name'] . ' ' . $t['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Section</label>
                <select name="section_id" class="form-control">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionFilter === (int)$sec['id'] ? 'selected' : '' ?>><?= h($sec['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Subject</label>
                <select name="subject_id" class="form-control">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= $subjectFilter === (int)$sub['id'] ? 'selected' : '' ?>><?= h($sub['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
                    <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
                    <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>Absent</option>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
        </form>
        <?php
        $activeFilters = [];
        if ($teacherFilter) $activeFilters[] = 'Teacher';
        if ($sectionFilter) $activeFilters[] = 'Section';
        if ($subjectFilter) $activeFilters[] = 'Subject';
        if ($statusFilter) $activeFilters[] = 'Status: ' . $statusFilter;
        if ($dateFrom !== report_default_from() || $dateTo !== today()) $activeFilters[] = $dateFrom . ' → ' . $dateTo;
        ?>
        <?php if (!empty($activeFilters)): ?>
            <div class="active-filters no-print">
                <?php foreach ($activeFilters as $f): ?><span class="fp-pill active"><?= h($f) ?></span><?php endforeach; ?>
                <a href="/admin/reports.php" class="fp-pill">Clear all</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary -->
<div class="summary-grid no-print">
    <div class="summary-card"><div class="number"><?= $totalRecords ?></div><div class="label">Total Records</div></div>
    <div class="summary-card is-present"><div class="number"><?= $presentCount ?></div><div class="label">Present</div></div>
    <div class="summary-card is-late"><div class="number"><?= $lateCount ?></div><div class="label">Late</div></div>
    <div class="summary-card is-absent"><div class="number"><?= $absentCount ?></div><div class="label">Absent</div></div>
</div>

<!-- Records Table -->
<div class="card report-table-card">
    <div class="card-header no-print">
        <h3>Attendance Records</h3>
        <span class="text-muted" style="font-size:0.85rem;">
            Showing <?= count($records) ?> of <?= $totalRecords ?> records<?= count($records) < $totalRecords ? ' — Export CSV for the full list' : '' ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
            <div class="empty-state"><h3>No records for selected filters</h3></div>
        <?php else: ?>
            <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th><th>Student</th><th>Subject</th><th>Section</th><th>Teacher</th><th>Status</th><th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td><?= format_date($r['attendance_date']) ?></td>
                                <td><strong><a href="/admin/student.php?id=<?= (int)$r['student_pk'] ?>"><?= h($r['sfirst'] . ' ' . $r['slast']) ?></a></strong><br><code style="font-size:0.75rem;"><?= h($r['sid']) ?></code></td>
                                <td><a href="/admin/subject.php?id=<?= (int)$r['subject_id'] ?? 0 ?>"><?= h($r['subject_name']) ?></a></td>
                                <td><?= h($classLabel($r)) ?></td>
                                <td><a href="/admin/teachers.php?search=<?= urlencode($r['tfirst'] . ' ' . $r['tlast']) ?>"><?= h($r['tfirst'] . ' ' . $r['tlast']) ?></a></td>
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
?>
