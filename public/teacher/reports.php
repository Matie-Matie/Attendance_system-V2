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

$whereClause = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT ar.*, st.student_id as sid, st.first_name as sfirst, st.last_name as slast,
           s.name as subject_name, sec.name as section_name,
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

$presentCount = count(array_filter($records, fn($r) => $r['status'] === 'present'));
$lateCount = count(array_filter($records, fn($r) => $r['status'] === 'late'));

// CSV Export
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-attendance-' . $dateFrom . '-to-' . $dateTo . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Student ID', 'Name', 'Subject', 'Section', 'Status', 'Time']);
    foreach ($records as $r) {
        fputcsv($output, [
            $r['attendance_date'], $r['sid'],
            $r['sfirst'] . ' ' . $r['slast'],
            $r['subject_name'], $r['section_name'],
            $r['status'], date('h:i A', strtotime($r['timestamp']))
        ]);
    }
    fclose($output);
    exit;
}

ob_start();

$totalRecords = count($records);
?>

<div class="page-intro" style="margin-bottom:1.5rem;">
    <div class="page-intro-main">
        <h1>My Reports <em><?= $totalRecords ?></em></h1>
        <p>Your classes only — filter by date or section, export CSV. Late counts as present but flagged.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div><div><strong><?= $totalRecords ?></strong><span>Total</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><div><strong><?= $presentCount ?></strong><span>Present</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--warning-light); color: #B07D16;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div><strong><?= $lateCount ?></strong><span>Late</span></div></div>
    </div>
</div>

<div class="card mb-3" style="border-radius: 22px;">
    <div class="card-header">
        <h3>Filter My Reports</h3>
        <a href="/teacher/reports.php?export=csv&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&section_id=<?=$sectionFilter?>"
           class="btn btn-sm btn-primary <?=empty($records)?'disabled':''?>">Export CSV</a>
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
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </form>
    </div>
</div>

<div class="summary-grid">
    <div class="summary-card is-present"><div class="number"><?= $presentCount ?></div><div class="label">Present</div></div>
    <div class="summary-card is-late"><div class="number"><?= $lateCount ?></div><div class="label">Late</div></div>
    <div class="summary-card"><div class="number"><?= count($records) ?></div><div class="label">Total</div></div>
</div>

<div class="card">
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
                                <td><?=h($r['section_name'])?></td>
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
