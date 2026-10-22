<?php
/**
 * Admin - Subjects Management
 * Modes: list (default), add, edit
 */
$pageTitle = 'Subjects';
$currentPage = 'subjects';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$errors = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $submitAction = $_POST['submit_action'] ?? '';

    if (empty($name)) $errors[] = 'Subject name is required.';

    if (empty($errors)) {
        if ($submitAction === 'add') {
            // Check unique code
            if (!empty($code)) {
                $stmt = db()->prepare("SELECT COUNT(*) FROM subjects WHERE code = ?");
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() > 0) $errors[] = 'Subject code already exists.';
            }
            if (empty($errors)) {
                try {
                    $stmt = db()->prepare("INSERT INTO subjects (name, code, description) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $code ?: null, $description ?: null]);
                    log_audit('create', 'subject', (int) db()->lastInsertId(), ['name' => $name, 'code' => $code]);
                    $_SESSION['_flash']['success'] = "Subject '{$name}' created successfully.";
                    redirect('/admin/subjects.php');
                } catch (\Throwable $e) {
                    error_log('SCPLS subject create: ' . $e->getMessage());
                    $errors[] = 'Could not save the subject. Please check the values and try again.';
                }
            }
        } elseif ($submitAction === 'edit' && $id > 0) {
            try {
                $stmt = db()->prepare("UPDATE subjects SET name = ?, code = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $code ?: null, $description ?: null, $id]);
                log_audit('update', 'subject', $id, ['name' => $name, 'code' => $code]);
                $_SESSION['_flash']['success'] = "Subject updated successfully.";
                redirect('/admin/subjects.php');
            } catch (\Throwable $e) {
                error_log('SCPLS subject update: ' . $e->getMessage());
                $errors[] = 'Could not update the subject. Please check the values and try again.';
            }
        }
    }
}

ob_start();

if ($action === 'add' || ($action === 'edit' && $id > 0)) {
    // Form mode
    $subject = ['name' => '', 'code' => '', 'description' => ''];
    if ($action === 'edit') {
        $stmt = db()->prepare("SELECT * FROM subjects WHERE id = ?");
        $stmt->execute([$id]);
        $subject = $stmt->fetch();
        if (!$subject) { flash('error', 'Subject not found.'); redirect('/admin/subjects.php'); }
    }
    // Repopulate the form with submitted values on failure so nothing is lost
    if (!empty($errors)) {
        $subject = [
            'name'        => $_POST['name']        ?? $subject['name'],
            'code'        => $_POST['code']        ?? $subject['code'],
            'description' => $_POST['description'] ?? $subject['description'],
        ];
    }
    $isEdit = $action === 'edit';
?>
    <div class="card form-card">
        <div class="card-header">
            <h3><?= $isEdit ? 'Edit Subject' : 'Add Subject' ?></h3>
            <a href="/admin/subjects.php" class="btn btn-outline btn-sm">Back to List</a>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger fade-in">
                    <strong>Unable to save subject:</strong>
                    <ul class="error-list">
                        <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="hidden" name="submit_action" value="<?= $isEdit ? 'edit' : 'add' ?>">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Subject Name</label>
                        <input type="text" id="name" name="name" class="form-control"
                               value="<?= h($subject['name']) ?>" required placeholder="e.g. Mathematics">
                    </div>
                    <div class="form-group">
                        <label for="code">Subject Code</label>
                        <input type="text" id="code" name="code" class="form-control"
                               value="<?= h($subject['code']) ?>" placeholder="e.g. MATH101">
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"
                              placeholder="Optional description"><?= h($subject['description']) ?></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= $isEdit ? 'Update Subject' : 'Create Subject' ?>
                    </button>
                    <a href="/admin/subjects.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php
} else {
    // List mode
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    if ($search) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM subjects WHERE name LIKE ? OR code LIKE ?");
        $stmt->execute(["%$search%", "%$search%"]);
        $total = $stmt->fetchColumn();
        $stmt = db()->prepare("SELECT * FROM subjects WHERE name LIKE ? OR code LIKE ? ORDER BY name ASC LIMIT ? OFFSET ?");
        $stmt->execute(["%$search%", "%$search%", $perPage, $offset]);
    } else {
        $total = db()->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
        $stmt = db()->prepare("SELECT * FROM subjects ORDER BY name ASC LIMIT ? OFFSET ?");
        $stmt->execute([$perPage, $offset]);
    }
    $subjects = $stmt->fetchAll();
    $totalPages = ceil($total / $perPage);

    // Coverage: which subjects actually have teachers + classes (client: every subject assigned).
    $coverage = [];
    foreach (db()->query("
        SELECT se.subject_id,
               COUNT(DISTINCT se.teacher_id) AS teachers,
               COUNT(DISTINCT se.section_id) AS sections,
               COUNT(DISTINCT st.id) AS students
        FROM schedule_entries se
        LEFT JOIN students st ON st.section_id = se.section_id AND st.status = 1
        WHERE se.status = 1
        GROUP BY se.subject_id
    ")->fetchAll() as $c) {
        $coverage[(int)$c['subject_id']] = $c;
    }

    $totalAll = (int) db()->query("SELECT COUNT(*) FROM subjects WHERE status = 1")->fetchColumn();
    $withCode = (int) db()->query("SELECT COUNT(*) FROM subjects WHERE code IS NOT NULL AND code != '' AND status = 1")->fetchColumn();
    $withDesc = (int) db()->query("SELECT COUNT(*) FROM subjects WHERE description IS NOT NULL AND description != '' AND status = 1")->fetchColumn();
    $scheduled = (int) db()->query("SELECT COUNT(DISTINCT subject_id) FROM schedule_entries WHERE status = 1")->fetchColumn();
?>

<div class="page-intro">
    <div class="page-intro-main">
        <h1>Subjects</h1>
        <p>Curriculum catalogue — codes, descriptions, and scheduling coverage. Clean names here = clean reports everywhere.</p>
    </div>
</div>

<div class="filter-pillbar">
    <form method="GET" class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="search" placeholder="Search by name or code…" value="<?= h($search) ?>" autocomplete="off">
        <?php if ($search): ?><a href="/admin/subjects.php" class="btn btn-sm btn-outline" style="border-radius:999px;">Clear</a><?php endif; ?>
    </form>
    <div class="fp-actions">
        <span class="text-xs text-muted nowrap"><?= $total ?> subject<?= $total===1?'':'s' ?></span>
        <div class="fp-divider"></div>
        <a href="/admin/subjects.php?action=add" class="btn btn-primary btn-sm">+ Add Subject</a>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($subjects)): ?>
            <div class="empty-state" style="padding: 3rem 1rem;">
                <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
                <h3>No subjects found</h3>
                <p><?= $search ? 'Try a different search term.' : 'Start by adding your first subject.' ?></p>
                <?php if (!$search): ?><a href="/admin/subjects.php?action=add" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">+ Add Subject</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Assigned</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $s): ?>
                            <tr>
                                <td><strong><a href="/admin/subject.php?id=<?= $s['id'] ?>"><?= h($s['name']) ?></a></strong></td>
                                <td><?= h($s['code']) ? '<code>'.h($s['code']).'</code>' : '<span class="text-muted">—</span>' ?></td>
                                <td style="color:var(--text-muted);font-size:0.85rem; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($s['description']) ?>"><?= h($s['description']) ?: '—' ?></td>
                                <td>
                                    <?php $cov = $coverage[(int)$s['id']] ?? null; ?>
                                    <?php if ($cov && (int)$cov['teachers'] > 0): ?>
                                        <span class="badge badge-success" title="<?= (int)$cov['teachers'] ?> teachers · <?= (int)$cov['sections'] ?> sections · <?= (int)$cov['students'] ?> students"><?= (int)$cov['teachers'] ?> teacher<?= (int)$cov['teachers']===1?'':'s' ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" title="No teacher or class assigned yet">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $s['status'] ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $s['status'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <a href="/admin/subjects.php?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="padding:1rem;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/admin/subjects.php?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                           class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
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

