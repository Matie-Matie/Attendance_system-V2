<?php
/**
 * Admin - School Years & Semesters Management
 */
$pageTitle = 'School Years';
$currentPage = 'school-years';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$errors = [];

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $isCurrent = !empty($_POST['is_current']);
    $submitAction = $_POST['submit_action'] ?? '';

    if (empty($name)) $errors[] = 'School year name is required.';

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            if ($submitAction === 'add') {
                if ($isCurrent) db()->exec("UPDATE school_years SET is_current = 0");
                $stmt = db()->prepare("INSERT INTO school_years (name, start_date, end_date, is_current) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $startDate ?: null, $endDate ?: null, $isCurrent ? 1 : 0]);
                $newId = (int) db()->lastInsertId();
                db()->commit();
                log_audit('create', 'school_year', $newId, ['name' => $name]);
                $_SESSION['_flash']['success'] = "School year '{$name}' created.";
                redirect('/admin/school-years.php');
            } elseif ($submitAction === 'edit' && $id > 0) {
                if ($isCurrent) db()->exec("UPDATE school_years SET is_current = 0");
                $stmt = db()->prepare("UPDATE school_years SET name = ?, start_date = ?, end_date = ?, is_current = ? WHERE id = ?");
                $stmt->execute([$name, $startDate ?: null, $endDate ?: null, $isCurrent ? 1 : 0, $id]);
                db()->commit();
                log_audit('update', 'school_year', $id, ['name' => $name]);
                $_SESSION['_flash']['success'] = 'School year updated.';
                redirect('/admin/school-years.php');
            } else {
                db()->rollBack();
            }
        } catch (\Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('SCPLS school-year save: ' . $e->getMessage());
            $errors[] = 'Could not save the school year. Please try again.';
        }
    }
}

ob_start();

if ($action === 'add' || ($action === 'edit' && $id > 0)) {
    $sy = ['name' => '', 'start_date' => '', 'end_date' => '', 'is_current' => 0];
    if ($action === 'edit') {
        $stmt = db()->prepare("SELECT * FROM school_years WHERE id = ?"); $stmt->execute([$id]); $sy = $stmt->fetch();
        if (!$sy) { flash('error', 'Not found.'); redirect('/admin/school-years.php'); }
    }
    // Repopulate the form with submitted values on failure so nothing is lost
    if (!empty($errors)) {
        $sy = [
            'name'       => $_POST['name']       ?? $sy['name'],
            'start_date' => $_POST['start_date'] ?? $sy['start_date'],
            'end_date'   => $_POST['end_date']   ?? $sy['end_date'],
            'is_current' => !empty($_POST['is_current']) ? 1 : 0,
        ];
    }
    $isEdit = $action === 'edit';
?>
    <div class="card form-card">
        <div class="card-header"><h3><?= $isEdit ? 'Edit' : 'Add' ?> School Year</h3><a href="/admin/school-years.php" class="btn btn-outline btn-sm">Back</a></div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger fade-in">
                    <strong>Unable to save school year:</strong>
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
                        <label for="name">School Year</label>
                        <input type="text" id="name" name="name" class="form-control" value="<?= h($sy['name']) ?>" required placeholder="e.g. 2026-2027">
                    </div>
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?= h($sy['start_date']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?= h($sy['end_date']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="flex items-center gap-2" style="cursor:pointer;">
                        <input type="checkbox" name="is_current" value="1" <?= $sy['is_current'] ? 'checked' : '' ?>>
                        <span>Set as current school year</span>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?></button>
                    <a href="/admin/school-years.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php
} else {
    $schoolYears = db()->query("SELECT sy.*, (SELECT COUNT(*) FROM semesters WHERE school_year_id = sy.id) as semester_count FROM school_years sy ORDER BY sy.created_at DESC")->fetchAll();
    $totalAll = count($schoolYears);
    $current = null; foreach ($schoolYears as $sy_) { if (!empty($sy_['is_current'])) { $current = $sy_; break; } }
    $totalSems = (int) db()->query("SELECT COUNT(*) FROM semesters")->fetchColumn();
?>
<div class="page-intro">
    <div class="page-intro-main">
        <h1>School Years <em><?= $totalAll ?></em></h1>
        <p>Academic years — each holds semesters. Only one can be <em>current</em> at a time; it drives the active schedule.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div><strong><?= $totalAll ?></strong><span>Total</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><div><strong><?= $current ? h($current['name']) : '—' ?></strong><span>Current</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--info-light); color: var(--info);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div><strong><?= $totalSems ?></strong><span>Semesters</span></div></div>
    </div>
</div>

<div class="filter-pillbar">
    <div class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span class="text-sm" style="color: var(--text-secondary);">Manage the academic calendar — set the current year to drive scheduling.</span>
    </div>
    <div class="fp-actions">
        <a href="/admin/school-years.php?action=add" class="btn btn-primary btn-sm">+ Add School Year</a>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($schoolYears)): ?>
            <div class="empty-state"><h3>No school years</h3></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Semesters</th><th>Current</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($schoolYears as $sy): ?>
                            <tr>
                                <td><strong><?= h($sy['name']) ?></strong></td>
                                <td><?= h($sy['start_date']) ?: '—' ?></td>
                                <td><?= h($sy['end_date']) ?: '—' ?></td>
                                <td><a href="/admin/semesters.php?school_year_id=<?= $sy['id'] ?>" class="badge badge-info"><?= (int)$sy['semester_count'] ?> semesters</a></td>
                                <td><?php if ($sy['is_current']): ?><span class="badge badge-success">Current</span><?php else: ?><span style="color:var(--text-muted);font-size:0.85rem;">—</span><?php endif; ?></td>
                                <td><a href="/admin/school-years.php?action=edit&id=<?= $sy['id'] ?>" class="btn btn-sm btn-outline">Edit</a></td>
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

