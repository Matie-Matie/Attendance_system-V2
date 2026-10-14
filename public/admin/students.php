<?php
/**
 * Admin - Students Management
 */
$pageTitle = 'Students';
$currentPage = 'students';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$errors = [];

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = trim($_POST['student_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $sectionId = (int)($_POST['section_id'] ?? 0);
    $parentName = trim($_POST['parent_name'] ?? '');
    $parentContact = trim($_POST['parent_contact'] ?? '');
    $parentEmail = trim($_POST['parent_email'] ?? '');
    $submitAction = $_POST['submit_action'] ?? '';

    if (empty($studentId)) $errors[] = 'Student ID is required.';
    if (empty($firstName)) $errors[] = 'First name is required.';
    if (empty($lastName)) $errors[] = 'Last name is required.';

    if ($parentEmail !== '' && !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Parent email is not a valid email address.';
    }

    // Friendly duplicate check on the UNIQUE student_id (add + edit).
    if (empty($errors) && $studentId !== '') {
        $dup = db()->prepare("SELECT id FROM students WHERE student_id = ? AND id != ?");
        $dup->execute([$studentId, $submitAction === 'edit' ? $id : 0]);
        if ($dup->fetch()) $errors[] = "Student ID '{$studentId}' is already used by another student.";
    }

    if (empty($errors)) {
        try {
            if ($submitAction === 'add') {
                $stmt = db()->prepare("INSERT INTO students (student_id, first_name, last_name, grade_level, section_id, parent_name, parent_contact, parent_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$studentId, $firstName, $lastName, $gradeLevel, $sectionId ?: null, $parentName ?: null, $parentContact ?: null, $parentEmail ?: null]);
                log_audit('create', 'student', (int) db()->lastInsertId(), ['student_id' => $studentId, 'name' => "$firstName $lastName"]);
                $_SESSION['_flash']['success'] = "Student '{$firstName} {$lastName}' created.";
                redirect('/admin/students.php');
            } elseif ($submitAction === 'edit' && $id > 0) {
                $stmt = db()->prepare("UPDATE students SET student_id = ?, first_name = ?, last_name = ?, grade_level = ?, section_id = ?, parent_name = ?, parent_contact = ?, parent_email = ? WHERE id = ?");
                $stmt->execute([$studentId, $firstName, $lastName, $gradeLevel, $sectionId ?: null, $parentName ?: null, $parentContact ?: null, $parentEmail ?: null, $id]);
                log_audit('update', 'student', $id, ['student_id' => $studentId, 'name' => "$firstName $lastName"]);
                $_SESSION['_flash']['success'] = 'Student updated.';
                redirect('/admin/students.php');
            }
        } catch (\Throwable $e) {
            error_log('SCPLS student save: ' . $e->getMessage());
            $errors[] = 'Could not save the student. Check the section and values, then try again.';
        }
    }
}

// Get sections for dropdown
$sections = db()->query("SELECT id, name, grade_level FROM sections WHERE status = 1 ORDER BY grade_level, name")->fetchAll();

ob_start();

if ($action === 'add' || ($action === 'edit' && $id > 0)) {
    $student = ['student_id' => '', 'first_name' => '', 'last_name' => '', 'grade_level' => '', 'section_id' => 0, 'parent_name' => '', 'parent_contact' => '', 'parent_email' => ''];
    if ($action === 'edit') {
        $stmt = db()->prepare("SELECT * FROM students WHERE id = ?"); $stmt->execute([$id]); $student = $stmt->fetch();
        if (!$student) { flash('error', 'Student not found.'); redirect('/admin/students.php'); }
    }
    // Repopulate the form with submitted values on failure so nothing is lost
    if (!empty($errors)) {
        $student = [
            'student_id'     => $_POST['student_id']     ?? $student['student_id'],
            'first_name'     => $_POST['first_name']     ?? $student['first_name'],
            'last_name'      => $_POST['last_name']      ?? $student['last_name'],
            'grade_level'    => $_POST['grade_level']    ?? $student['grade_level'],
            'section_id'     => (int)($_POST['section_id'] ?? $student['section_id']),
            'parent_name'    => $_POST['parent_name']    ?? $student['parent_name'],
            'parent_contact' => $_POST['parent_contact'] ?? $student['parent_contact'],
            'parent_email'   => $_POST['parent_email']   ?? $student['parent_email'],
        ];
    }
    $isEdit = $action === 'edit';
    $gradeLevels = ['Grade 11', 'Grade 12'];
?>
    <div class="card form-card">
        <div class="card-header"><h3><?= $isEdit ? 'Edit Student' : 'Add Student' ?></h3><a href="/admin/students.php" class="btn btn-outline btn-sm">Back</a></div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger fade-in">
                    <strong>Unable to save student:</strong>
                    <ul class="error-list">
                        <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="submit_action" value="<?= $isEdit ? 'edit' : 'add' ?>">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="student_id">Student ID</label>
                        <input type="text" id="student_id" name="student_id" class="form-control" value="<?= h($student['student_id']) ?>" required placeholder="e.g. SHS-2026-001">
                    </div>
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" value="<?= h($student['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" value="<?= h($student['last_name']) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="grade_level">Grade Level</label>
                        <select id="grade_level" name="grade_level" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= $gl ?>" <?= $student['grade_level'] === $gl ? 'selected' : '' ?>><?= $gl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="section_id">Section</label>
                        <select id="section_id" name="section_id" class="form-control">
                            <option value="">-- No Section --</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= $sec['id'] ?>" <?= (int)$student['section_id'] === (int)$sec['id'] ? 'selected' : '' ?>>
                                    <?= h($sec['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="parent_name">Parent/Guardian Name</label>
                        <input type="text" id="parent_name" name="parent_name" class="form-control" value="<?= h($student['parent_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="parent_contact">Parent Contact No.</label>
                        <input type="text" id="parent_contact" name="parent_contact" class="form-control" value="<?= h($student['parent_contact']) ?>" placeholder="e.g. 09171234567">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="parent_email">Parent Email</label>
                        <input type="email" id="parent_email" name="parent_email" class="form-control" value="<?= h($student['parent_email']) ?>" placeholder="For email notifications (free)">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update Student' : 'Create Student' ?></button>
                    <a href="/admin/students.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php
} else {
    $search = trim($_GET['search'] ?? '');
    $sectionFilter = (int)($_GET['section_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $where = ["1=1"]; $params = [];
    if ($search) { $where[] = "(s.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]); }
    if ($sectionFilter) { $where[] = "s.section_id = ?"; $params[] = $sectionFilter; }

    $whereClause = implode(' AND ', $where);
    $countStmt = db()->prepare("SELECT COUNT(*) FROM students s WHERE $whereClause");
    $countStmt->execute($params); $total = $countStmt->fetchColumn();

    $stmt = db()->prepare("SELECT s.*, sec.name as section_name FROM students s LEFT JOIN sections sec ON s.section_id = sec.id WHERE $whereClause ORDER BY s.last_name ASC LIMIT ? OFFSET ?");
    $allParams = array_merge($params, [$perPage, $offset]);
    $stmt->execute($allParams); $students = $stmt->fetchAll();
    $totalPages = ceil($total / $perPage);

    // Intro stats (lightweight)
    $totalAll = (int) db()->query("SELECT COUNT(*) FROM students WHERE status = 1")->fetchColumn();
    $g11 = (int) db()->query("SELECT COUNT(*) FROM students WHERE grade_level = 'Grade 11' AND status = 1")->fetchColumn();
    $g12 = (int) db()->query("SELECT COUNT(*) FROM students WHERE grade_level = 'Grade 12' AND status = 1")->fetchColumn();
    $withSection = (int) db()->query("SELECT COUNT(*) FROM students WHERE section_id IS NOT NULL AND status = 1")->fetchColumn();
?>
<div class="page-intro">
    <div class="page-intro-main">
        <h1>Students <em><?= $totalAll ?></em></h1>
        <p>Every learner in one place — enrol, assign to section, attach parent contacts for email notifications, and generate QR cards.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div><strong><?= $totalAll ?></strong><span>Enrolled</span></div></div>
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c6 3 10 0 12-2v-5"/></svg></div><div><strong><?= $withSection ?></strong><span>With section</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div><div><strong><?= $g11 ?></strong><span>Grade 11</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--info-light); color: var(--info);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div><div><strong><?= $g12 ?></strong><span>Grade 12</span></div></div>
    </div>
</div>

<div class="filter-pillbar">
    <form method="GET" class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="search" placeholder="Search by ID or name…" value="<?= h($search) ?>" autocomplete="off">
        <select name="section_id" class="form-control" style="width:auto; max-width:170px; padding:0.45rem 1.9rem 0.45rem 0.9rem; border-radius: 999px; font-size:0.82rem;" onchange="this.form.submit()">
            <option value="">All sections</option>
            <?php foreach ($sections as $sec): ?>
                <option value="<?= $sec['id'] ?>" <?= $sectionFilter === (int)$sec['id'] ? 'selected' : '' ?>><?= h($sec['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($search || $sectionFilter): ?><a href="/admin/students.php" class="btn btn-sm btn-outline" style="border-radius: 999px;">Clear</a><?php endif; ?>
    </form>
    <div class="fp-actions">
        <span class="text-xs text-muted nowrap" style="font-variant-numeric: tabular-nums;"><?= $total ?> result<?= $total===1?'':'s' ?></span>
        <div class="fp-divider"></div>
        <a href="/admin/import.php?type=students" class="btn btn-outline btn-sm">Import CSV</a>
        <a href="/admin/students.php?action=add" class="btn btn-primary btn-sm">+ Add Student</a>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($students)): ?>
            <div class="empty-state" style="padding: 3rem 1rem;">
                <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
                <h3>No students found</h3>
                <p><?= $search ? 'Try a different search or clear filters.' : 'Add your first student to get started.' ?></p>
                <?php if (!$search): ?><a href="/admin/students.php?action=add" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">+ Add Student</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Student</th><th>Grade</th><th>Section</th><th>Parent</th><th>Contact → Email</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($students as $s):
                            $initials = strtoupper(substr($s['first_name'],0,1) . substr($s['last_name'],0,1));
                        ?>
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="avatar"><?= h($initials) ?></span>
                                        <div>
                                            <strong style="display:block; font-size:0.9rem; line-height:1.2;"><a href="/admin/student.php?id=<?= $s['id'] ?>"><?= h($s['first_name'] . ' ' . $s['last_name']) ?></a></strong>
                                            <code style="font-size:0.72rem; background: transparent; padding:0; color: var(--text-muted);"><?= h($s['student_id']) ?></code>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge badge-info"><?= h($s['grade_level']) ?: '—' ?></span></td>
                                <td><?= h($s['section_name']) ? '<span class="badge badge-success">'.h($s['section_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
                                <td style="font-size:0.85rem; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($s['parent_name']) ?>"><?= h($s['parent_name']) ?: '<span class="text-muted">—</span>' ?></td>
                                <td style="font-size:0.78rem; line-height:1.5;">
                                    <div><?= h($s['parent_contact']) ? '<code>'.h($s['parent_contact']).'</code>' : '<span class="text-muted">—</span>' ?></div>
                                    <div style="color: var(--text-muted);"><?= h($s['parent_email']) ?: '' ?></div>
                                </td>
                                <td style="text-align:right;">
                                    <div class="flex gap-1" style="justify-content:flex-end;">
                                        <a href="/admin/students.php?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                                        <a href="/admin/qr-codes.php?student_id=<?= $s['id'] ?>" class="btn btn-sm btn-primary" title="View / Print QR Code">QR</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="padding: 1rem;">
                    <?php for ($i=1; $i<=$totalPages; $i++): ?>
                        <a href="?page=<?=$i?>&search=<?=urlencode($search)?>&section_id=<?=$sectionFilter?>" class="<?=$i===$page?'active':''?>"><?=$i?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php
}

$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
?>
