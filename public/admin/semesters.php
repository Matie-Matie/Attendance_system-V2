<?php
/**
 * Admin - Semesters Management (linked from School Years)
 */
$pageTitle = 'Semesters';
$currentPage = 'school-years';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$schoolYearId = (int)($_GET['school_year_id'] ?? 0);
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

$sy = null;
if ($schoolYearId > 0) {
    $stmt = db()->prepare("SELECT * FROM school_years WHERE id = ?"); $stmt->execute([$schoolYearId]); $sy = $stmt->fetch();
}
if (!$sy) { flash('error', 'School year not found.'); redirect('/admin/school-years.php'); }

$pageTitle = "Semesters - {$sy['name']}";

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $isCurrent = !empty($_POST['is_current']);
    $submitAction = $_POST['submit_action'] ?? '';

    if (empty($name)) {
        $_SESSION['_flash']['error'] = 'Semester name is required.';
        redirect("/admin/semesters.php?school_year_id=$schoolYearId&action=" . ($submitAction === 'edit' ? "edit&id=$id" : 'add'));
    }
    try {
        db()->beginTransaction();
        if ($submitAction === 'add') {
            if ($isCurrent) db()->prepare("UPDATE semesters SET is_current = 0 WHERE school_year_id = ?")->execute([$schoolYearId]);
            $stmt = db()->prepare("INSERT INTO semesters (school_year_id, name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$schoolYearId, $name, $startDate ?: null, $endDate ?: null, $isCurrent ? 1 : 0]);
            $newId = (int) db()->lastInsertId();
            db()->commit();
            log_audit('create', 'semester', $newId, ['name' => $name]);
            $_SESSION['_flash']['success'] = "Semester '{$name}' created.";
        } elseif ($submitAction === 'edit' && $id > 0) {
            if ($isCurrent) db()->prepare("UPDATE semesters SET is_current = 0 WHERE school_year_id = ?")->execute([$schoolYearId]);
            $stmt = db()->prepare("UPDATE semesters SET name = ?, start_date = ?, end_date = ?, is_current = ? WHERE id = ?");
            $stmt->execute([$name, $startDate ?: null, $endDate ?: null, $isCurrent ? 1 : 0, $id]);
            db()->commit();
            log_audit('update', 'semester', $id, ['name' => $name]);
            $_SESSION['_flash']['success'] = 'Semester updated.';
        } else {
            db()->rollBack();
        }
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('SCPLS semester save: ' . $e->getMessage());
        $_SESSION['_flash']['error'] = 'Could not save the semester. Please try again.';
    }
    redirect("/admin/semesters.php?school_year_id=$schoolYearId");
}

ob_start();

if ($action === 'add' || ($action === 'edit' && $id > 0)) {
    $sem = ['name' => '', 'start_date' => '', 'end_date' => '', 'is_current' => 0];
    if ($action === 'edit') {
        $stmt = db()->prepare("SELECT * FROM semesters WHERE id = ?"); $stmt->execute([$id]); $sem = $stmt->fetch();
        if (!$sem) { flash('error', 'Not found.'); redirect("/admin/semesters.php?school_year_id=$schoolYearId"); }
    }
    $isEdit = $action === 'edit';
?>
    <div class="card form-card">
        <div class="card-header"><h3><?= $isEdit ? 'Edit' : 'Add' ?> Semester</h3><a href="/admin/semesters.php?school_year_id=<?= $schoolYearId ?>" class="btn btn-outline btn-sm">Back</a></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="submit_action" value="<?= $isEdit ? 'edit' : 'add' ?>">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Semester Name</label>
                        <input type="text" id="name" name="name" class="form-control" value="<?= h($sem['name']) ?>" required placeholder="e.g. 1st Semester">
                    </div>
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?= h($sem['start_date']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?= h($sem['end_date']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="flex items-center gap-2" style="cursor:pointer;">
                        <input type="checkbox" name="is_current" value="1" <?= $sem['is_current'] ? 'checked' : '' ?>>
                        <span>Set as current semester</span>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?></button>
                    <a href="/admin/semesters.php?school_year_id=<?= $schoolYearId ?>" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php
} else {
    $stmt = db()->prepare("SELECT * FROM semesters WHERE school_year_id = ? ORDER BY created_at ASC"); $stmt->execute([$schoolYearId]); $semesters = $stmt->fetchAll();
    $totalSems = count($semesters);
    $currentSem = null; foreach ($semesters as $ss) { if (!empty($ss['is_current'])) { $currentSem = $ss; break; } }
?>
<div class="page-intro">
    <div class="page-intro-main">
        <a href="/admin/school-years.php" class="btn btn-sm btn-outline" style="margin-bottom:0.75rem; border-radius:999px;">&larr; School Years</a>
        <h1>Semesters <em><?= h($sy['name']) ?></em></h1>
        <p>Terms inside this school year — one is current and used for the active master schedule.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div><strong><?= $totalSems ?></strong><span>Total</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><div><strong><?= $currentSem ? h($currentSem['name']) : '—' ?></strong><span>Current</span></div></div>
    </div>
</div>

<div class="filter-pillbar">
    <div class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span class="text-sm" style="color: var(--text-secondary);">Manage terms for <strong><?= h($sy['name']) ?></strong></span>
    </div>
    <div class="fp-actions">
        <a href="/admin/semesters.php?action=add&school_year_id=<?= $schoolYearId ?>" class="btn btn-primary btn-sm">+ Add Semester</a>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($semesters)): ?>
            <div class="empty-state"><h3>No semesters for this school year</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Current</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($semesters as $sem): ?>
                            <tr>
                                <td><strong><?= h($sem['name']) ?></strong></td>
                                <td><?= h($sem['start_date']) ?: '—' ?></td>
                                <td><?= h($sem['end_date']) ?: '—' ?></td>
                                <td><?php if ($sem['is_current']): ?><span class="badge badge-success">Current</span><?php endif; ?></td>
                                <td><a href="/admin/semesters.php?action=edit&id=<?= $sem['id'] ?>&school_year_id=<?= $schoolYearId ?>" class="btn btn-sm btn-outline">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
}

$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
?>
