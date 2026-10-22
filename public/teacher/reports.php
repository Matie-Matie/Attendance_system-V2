<?php
/**
 * Teacher - Attendance Reports
 * Teachers only see their own classes
 */
$pageTitle = 'Reports';
$currentPage = 'reports';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('teacher');

$teacher = current_teacher();
$teacherId = $teacher['id'] ?? 0;

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? today();
$sectionFilter = (int)($_GET['section_id'] ?? 0);
$export = $_GET['export'] ?? '';

// Status filter — whitelisted, never interpolated raw into SQL.
$statusFilter = (string)($_GET['status'] ?? '');
$statusAllowed = ['present', 'late', 'absent'];
if (!in_array($statusFilter, $statusAllowed, true)) {
    $statusFilter = '';
}

// Get teacher's sections
$stmt = db()->prepare("
    SELECT DISTINCT sec.id, sec.name FROM schedule_entries se
    JOIN sections sec ON se.section_id = sec.id
    WHERE se.teacher_id = ? AND se.status = 1
    ORDER BY sec.name
");
$stmt->execute([$teacherId]);
$mySections = $stmt->fetchAll();

// Build query - teacher's records only
$where = ["se.teacher_id = ?", "DATE(ar.timestamp) BETWEEN ? AND ?"];
$params = [$teacherId, $dateFrom, $dateTo];

if ($sectionFilter) { $where[] = "se.section_id = ?"; $params[] = $sectionFilter; }
if ($statusFilter)  { $where[] = "ar.status = ?";     $params[] = $statusFilter; }

$whereClause = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT ar.*, st.student_id as sid, st.first_name as sfirst, st.last_name as slast,
           s.name as subject_name, sec.name as section_name,
           sec.grade_level, sec.track_strand, se.room,
           as2.attendance_date
    FROM attendance_records ar
    JOIN attendance_sessions as2 ON ar.attendance_session_id = as2.id
    JOIN schedule_entries se ON as2.schedule_entry_id = se.id
    JOIN students st ON ar.student_id = st.id
    JOIN subjects s ON se.subject_id = s.id
    JOIN sections sec ON se.section_id = sec.id
    WHERE $whereClause
    ORDER BY ar.timestamp DESC
    LIMIT 500
");
$stmt->execute($params);
$records = $stmt->fetchAll();

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

$presentCount = count(array_filter($records, fn($r) => $r['status'] === 'present'));
$lateCount = count(array_filter($records, fn($r) => $r['status'] === 'late'));
$absentCount = count(array_filter($records, fn($r) => $r['status'] === 'absent'));

// Build a URL that keeps the current filters, optionally overriding one key.
$reportUrl = function (array $overrides = []) use ($dateFrom, $dateTo, $sectionFilter, $statusFilter): string {
    $q = array_filter(array_merge([
        'date_from'  => $dateFrom,
        'date_to'    => $dateTo,
        'section_id' => $sectionFilter ?: null,
        'status'     => $statusFilter ?: null,
    ], $overrides), fn($v) => $v !== null && $v !== '' && $v !== 0);
    return '/teacher/reports.php' . ($q ? '?' . http_build_query($q) : '');
};

// CSV Export
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-attendance-' . $dateFrom . '-to-' . $dateTo . '.csv"');
    $output = fopen('php://output', 'w');
    // Neutralise spreadsheet formula injection (same guard as admin export).
    $safe = fn($v) => (is_string($v) && $v !== '' && str_contains('=+-@', $v[0])) ? "'" . $v : $v;
    fputcsv($output, ['Date', 'Student ID', 'Name', 'Subject', 'Section', 'Status', 'Time']);
    foreach ($records as $r) {
        fputcsv($output, array_map($safe, [
            $r['attendance_date'], $r['sid'],
            $r['sfirst'] . ' ' . $r['slast'],
            $r['subject_name'], $r['section_name'],
            $r['status'], date('h:i A', strtotime($r['timestamp']))
        ]));
    }
    fclose($output);
    exit;
}

ob_start();

$totalRecords = count($records);
?>

<div class="page-intro no-print" style="margin-bottom:1.5rem;">
    <div class="page-intro-main">
        <h1>My Reports</h1>
        <p>Your classes only — filter by date or section, export CSV. Late counts as present but flagged.</p>
    </div>
</div>

<div class="card mb-3 no-print" style="border-radius: 22px;">
    <div class="card-header">
        <h3>Filter My Reports</h3>
        <div class="flex gap-1">
            <button type="submit" class="btn btn-sm btn-outline" onclick="window.print()">Print</button>
            <a href="/teacher/reports.php?export=csv&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&section_id=<?=$sectionFilter?>&status=<?=$statusFilter?>"
               class="btn btn-sm btn-primary <?=empty($records)?'disabled':''?>">Export CSV</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <div class="form-group">
                <label>From</label>
                <input type="date" name="date_from" class="form-control" value="<?=h($dateFrom)?>">
            </div>
            <div class="form-group">
                <label>To</label>
                <input type="date" name="date_to" class="form-control" value="<?=h($dateTo)?>">
            </div>
            <div class="form-group">
                <label>Section</label>
                <select name="section_id" class="form-control">
                    <option value="">All My Sections</option>
                    <?php foreach ($mySections as $sec): ?>
                        <option value="<?=$sec['id']?>" <?=$sectionFilter===(int)$sec['id']?'selected':''?>><?=h($sec['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="">All statuses</option>
                    <option value="present" <?=$statusFilter==='present'?'selected':''?>>Present only</option>
                    <option value="late" <?=$statusFilter==='late'?'selected':''?>>Late only</option>
                    <option value="absent" <?=$statusFilter==='absent'?'selected':''?>>Absent only</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </form>
        <?php if ($sectionFilter || $statusFilter): ?>
            <div class="active-filters no-print">
                <?php if ($sectionFilter): ?><span class="fp-pill active">Section filtered</span><?php endif; ?>
                <?php if ($statusFilter): ?><span class="fp-pill active"><?= ucfirst(h($statusFilter)) ?> only</span><?php endif; ?>
                <a href="<?= h($reportUrl(['section_id' => null, 'status' => null])) ?>" class="fp-pill">Clear all</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="summary-grid no-print">
    <a class="summary-card is-present" style="text-decoration:none;" href="<?= h($reportUrl(['status' => 'present'])) ?>"><div class="number"><?= $presentCount ?></div><div class="label">Present</div></a>
    <a class="summary-card is-late" style="text-decoration:none;" href="<?= h($reportUrl(['status' => 'late'])) ?>"><div class="number"><?= $lateCount ?></div><div class="label">Late</div></a>
    <a class="summary-card is-absent" style="text-decoration:none;" href="<?= h($reportUrl(['status' => 'absent'])) ?>"><div class="number"><?= $absentCount ?></div><div class="label">Absent</div></a>
    <div class="summary-card"><div class="number"><?= count($records) ?></div><div class="label">Total</div></div>
</div>

<div class="card report-table-card">
    <div class="card-body" style="padding:0;">
        <?php if (empty($records)): ?>
            <div class="empty-state"><h3>No attendance records</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Student</th><th>Subject</th><th>Section</th><th>Status</th><th>Time</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td><?= format_date($r['attendance_date']) ?></td>
                                <td><strong><?=h($r['sfirst'].' '.$r['slast'])?></strong><br><code style="font-size:0.75rem;"><?=h($r['sid'])?></code></td>
                                <td><?=h($r['subject_name'])?></td>
                                <td><?= h($classLabel($r)) ?></td>
                                <td><span class="badge <?=$r['status']==='present'?'badge-success':($r['status']==='late'?'badge-warning':'badge-danger')?>"><?=strtoupper($r['status'])?></span></td>
                                <td><?=date('h:i A', strtotime($r['timestamp']))?></td>
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
include __DIR__ . '/../../includes/layouts/teacher-layout.php';
?>
