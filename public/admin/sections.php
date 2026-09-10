<?php
/**
 * Admin - Sections Management
 */
$pageTitle = 'Sections';
$currentPage = 'sections';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$errors = [];

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $trackStrand = trim($_POST['track_strand'] ?? '');
    $submitAction = $_POST['submit_action'] ?? '';

    if (empty($name)) $errors[] = 'Section name is required.';
    if (empty($gradeLevel)) $errors[] = 'Grade level is required.';

    if (empty($errors)) {
        try {
            if ($submitAction === 'add') {
                $stmt = db()->prepare("INSERT INTO sections (name, grade_level, track_strand) VALUES (?, ?, ?)");
                $stmt->execute([$name, $gradeLevel, $trackStrand ?: null]);
                log_audit('create', 'section', (int) db()->lastInsertId(), ['name' => $name]);
                $_SESSION['_flash']['success'] = "Section '{$name}' created.";
                redirect('/admin/sections.php');
            } elseif ($submitAction === 'edit' && $id > 0) {
                $stmt = db()->prepare("UPDATE sections SET name = ?, grade_level = ?, track_strand = ? WHERE id = ?");
                $stmt->execute([$name, $gradeLevel, $trackStrand ?: null, $id]);
                log_audit('update', 'section', $id, ['name' => $name]);
                $_SESSION['_flash']['success'] = 'Section updated.';
                redirect('/admin/sections.php');
            }
        } catch (\Throwable $e) {
            error_log('SCPLS section save: ' . $e->getMessage());
            $errors[] = 'Could not save the section. Please try again.';
        }
    }
}

ob_start();

if ($action === 'add' || ($action === 'edit' && $id > 0)) {
    $section = ['name' => '', 'grade_level' => 'Grade 11', 'track_strand' => ''];
    if ($action === 'edit') {
        $stmt = db()->prepare("SELECT * FROM sections WHERE id = ?");
        $stmt->execute([$id]);
        $section = $stmt->fetch();
        if (!$section) { flash('error', 'Section not found.'); redirect('/admin/sections.php'); }
    }
    // Repopulate the form with submitted values on failure so nothing is lost
    if (!empty($errors)) {
        $section = [
            'name'         => $_POST['name']         ?? $section['name'],
            'grade_level'  => $_POST['grade_level']  ?? $section['grade_level'],
            'track_strand' => $_POST['track_strand'] ?? $section['track_strand'],
        ];
    }
    $isEdit = $action === 'edit';
    $gradeLevels = ['Grade 11', 'Grade 12'];
    $trackOptions = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL', 'Sports', 'Arts & Design'];
?>
    <div class="card form-card">
        <div class="card-header">
            <h3><?= $isEdit ? 'Edit Section' : 'Add Section' ?></h3>
            <a href="/admin/sections.php" class="btn btn-outline btn-sm">Back</a>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger fade-in">
                    <strong>Unable to save section:</strong>
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
                        <label for="name">Section Name</label>
                        <input type="text" id="name" name="name" class="form-control"
                               value="<?= h($section['name']) ?>" required placeholder="e.g. Grade 11 - STEM">
                    </div>
                    <div class="form-group">
                        <label for="grade_level">Grade Level</label>
                        <select id="grade_level" name="grade_level" class="form-control" required>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= $gl ?>" <?= $section['grade_level'] === $gl ? 'selected' : '' ?>><?= $gl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="track_strand">Track/Strand</label>
                        <select id="track_strand" name="track_strand" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach ($trackOptions as $t): ?>
                                <option value="<?= $t ?>" <?= $section['track_strand'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Section</button>
                    <a href="/admin/sections.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php
} else {
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = '';
    $params = [];
    if ($search) { $where = "WHERE name LIKE ? OR grade_level LIKE ? OR track_strand LIKE ?"; $params = ["%$search%", "%$search%", "%$search%"]; }

    $total = db()->prepare("SELECT COUNT(*) FROM sections $where"); $total->execute($params); $total = $total->fetchColumn();
    $stmt = db()->prepare("SELECT s.*, (SELECT COUNT(*) FROM students WHERE section_id = s.id AND status = 1) as student_count FROM sections s $where ORDER BY s.grade_level, s.name ASC LIMIT ? OFFSET ?");
    $allParams = array_merge($params, [$perPage, $offset]);
    $stmt->execute($allParams);
    $sections = $stmt->fetchAll();
    $totalPages = ceil($total / $perPage);

    $totalAll = (int) db()->query("SELECT COUNT(*) FROM sections WHERE status = 1")->fetchColumn();
    $g11 = (int) db()->query("SELECT COUNT(*) FROM sections WHERE grade_level = 'Grade 11' AND status = 1")->fetchColumn();
    $g12 = (int) db()->query("SELECT COUNT(*) FROM sections WHERE grade_level = 'Grade 12' AND status = 1")->fetchColumn();
    $totalEnrolled = (int) db()->query("SELECT COUNT(*) FROM students WHERE status = 1 AND section_id IS NOT NULL")->fetchColumn();
?>

<div class="page-intro">
    <div class="page-intro-main">
        <h1>Sections <em><?= $totalAll ?></em></h1>
        <p>Grade 11 & 12 homerooms — each tied to a track/strand. Students inherit their section for scheduling and reports.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c6 3 10 0 12-2v-5"/></svg></div><div><strong><?= $totalAll ?></strong><span>Total</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div><strong><?= $totalEnrolled ?></strong><span>Enrolled</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--info-light); color: var(--info);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div><strong><?= $g11 ?></strong><span>Grade 11</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--warning-light); color: #B07D16;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div><strong><?= $g12 ?></strong><span>Grade 12</span></div></div>
    </div>
</div>

<div class="filter-pillbar">
    <form method="GET" class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="search" placeholder="Search sections or strands…" value="<?= h($search) ?>" autocomplete="off">
        <?php if ($search): ?><a href="/admin/sections.php" class="btn btn-sm btn-outline" style="border-radius:999px;">Clear</a><?php endif; ?>
    </form>
    <div class="fp-actions">
        <span class="text-xs text-muted nowrap"><?= $total ?> section<?= $total===1?'':'s' ?></span>
        <div class="fp-divider"></div>
        <a href="/admin/sections.php?action=add" class="btn btn-primary btn-sm">+ Add Section</a>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($sections)): ?>
            <div class="empty-state" style="padding: 3rem 1rem;">
                <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c6 3 10 0 12-2v-5"/></svg></div>
                <h3>No sections found</h3>
                <p><?= $search ? 'Try a different search.' : 'Create your first homeroom.' ?></p>
                <?php if (!$search): ?><a href="/admin/sections.php?action=add" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">+ Add Section</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Section</th><th>Grade</th><th>Track / Strand</th><th>Enrolled</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($sections as $s): ?>
                            <tr>
                                <td><strong><?= h($s['name']) ?></strong></td>
                                <td><span class="badge badge-info"><?= h($s['grade_level']) ?></span></td>
                                <td><?= h($s['track_strand']) ? '<span class="badge badge-warning">'.h($s['track_strand']).'</span>' : '<span class="text-muted">—</span>' ?></td>
                                <td><span class="badge <?= (int)$s['student_count'] > 0 ? 'badge-success' : 'badge-danger' ?>"><?= (int)$s['student_count'] ?> student<?= (int)$s['student_count']===1?'':'s' ?></span></td>
                                <td><span class="badge <?= $s['status'] ? 'badge-success' : 'badge-danger' ?>"><?= $s['status'] ? 'Active' : 'Inactive' ?></span></td>
                                <td style="text-align:right;"><a href="/admin/sections.php?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?><div class="pagination" style="padding:1rem;"><?php for ($i=1;$i<=$totalPages;$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i===$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
}

$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
?>

