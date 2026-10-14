<?php
/**
 * Admin - Teachers Management
 */
$pageTitle = 'Teachers';
$currentPage = 'teachers';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$errors = [];

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $employeeId = trim($_POST['employee_id'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $submitAction = $_POST['submit_action'] ?? '';

    if (empty($firstName)) $errors[] = 'First name is required.';
    if (empty($lastName)) $errors[] = 'Last name is required.';
    if (empty($username)) $errors[] = 'Username is required.';
    if ($submitAction === 'add' && empty($password)) $errors[] = 'Password is required.';
    if ($password !== '' && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not a valid email address.';

    // Friendly UNIQUE checks (username on users, employee_id on teachers).
    if (empty($errors)) {
        $editUserId = 0;
        if ($submitAction === 'edit' && $id > 0) {
            $q = db()->prepare("SELECT user_id FROM teachers WHERE id = ?");
            $q->execute([$id]);
            $editUserId = (int) $q->fetchColumn();
        }
        if ($submitAction === 'add' || $username !== '') {
            $u = db()->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $u->execute([$username, $editUserId]);
            if ($u->fetch()) $errors[] = "Username '{$username}' is already taken.";
        }
        if ($employeeId !== '') {
            $e = db()->prepare("SELECT id FROM teachers WHERE employee_id = ? AND id != ?");
            $e->execute([$employeeId, $submitAction === 'edit' ? $id : 0]);
            if ($e->fetch()) $errors[] = "Employee ID '{$employeeId}' is already used.";
        }
    }

    if (empty($errors)) {
        $db = db();
        try {
            if ($submitAction === 'add') {
                $db->beginTransaction();
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, role_id) VALUES (?, ?, ?, ?, 2)");
                $stmt->execute([$username, $hashed, "$firstName $lastName", $email ?: null]);
                $userId = $db->lastInsertId();
                $stmt = $db->prepare("INSERT INTO teachers (user_id, employee_id, first_name, last_name) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $employeeId ?: null, $firstName, $lastName]);
                $db->commit();
                log_audit('create', 'teacher', (int) $userId, ['username' => $username, 'name' => "$firstName $lastName"]);
                $_SESSION['_flash']['success'] = "Teacher '{$firstName} {$lastName}' created.";
                redirect('/admin/teachers.php');
            } elseif ($submitAction === 'edit' && $id > 0) {
                $db->beginTransaction();
                $stmt = $db->prepare("UPDATE teachers SET first_name = ?, last_name = ?, employee_id = ? WHERE id = ?");
                $stmt->execute([$firstName, $lastName, $employeeId ?: null, $id]);
                // Get user_id for this teacher
                $stmt = $db->prepare("SELECT user_id FROM teachers WHERE id = ?"); $stmt->execute([$id]); $teacher = $stmt->fetch();
                if ($teacher) {
                    $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
                    $stmt->execute(["$firstName $lastName", $email ?: null, $teacher['user_id']]);
                    if ($password !== '') {
                        $pw = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $pw->execute([password_hash($password, PASSWORD_DEFAULT), $teacher['user_id']]);
                    }
                }
                $db->commit();
                log_audit('update', 'teacher', (int) ($teacher['user_id'] ?? 0), ['name' => "$firstName $lastName", 'password_changed' => $password !== '']);
                $_SESSION['_flash']['success'] = $password !== '' ? 'Teacher updated (password changed).' : 'Teacher updated.';
                redirect('/admin/teachers.php');
            }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('SCPLS teacher save: ' . $e->getMessage());
            $errors[] = 'Could not save the teacher. Please check the details and try again.';
        }
    }
}

ob_start();

if ($action === 'add' || ($action === 'edit' && $id > 0)) {
    $teacher = ['first_name' => '', 'last_name' => '', 'employee_id' => '', 'username' => '', 'email' => ''];
    if ($action === 'edit') {
        $stmt = db()->prepare("SELECT t.*, u.username, u.email FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
        $stmt->execute([$id]); $teacher = $stmt->fetch();
        if (!$teacher) { flash('error', 'Teacher not found.'); redirect('/admin/teachers.php'); }
    }
    // Repopulate the form with submitted values on failure so nothing is lost
    if (!empty($errors)) {
        $teacher = [
            'first_name'  => $_POST['first_name']  ?? $teacher['first_name'],
            'last_name'   => $_POST['last_name']   ?? $teacher['last_name'],
            'employee_id' => $_POST['employee_id'] ?? $teacher['employee_id'],
            'username'    => $_POST['username']    ?? $teacher['username'],
            'email'       => $_POST['email']       ?? $teacher['email'],
        ];
    }
    $isEdit = $action === 'edit';
?>
    <div class="card form-card">
        <div class="card-header"><h3><?= $isEdit ? 'Edit Teacher' : 'Add Teacher' ?></h3><a href="/admin/teachers.php" class="btn btn-outline btn-sm">Back</a></div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger fade-in">
                    <strong>Unable to save teacher:</strong>
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
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" value="<?= h($teacher['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" value="<?= h($teacher['last_name']) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="employee_id">Employee ID</label>
                        <input type="text" id="employee_id" name="employee_id" class="form-control" value="<?= h($teacher['employee_id']) ?>" placeholder="e.g. TCH-2026-001">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= h($teacher['email']) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" value="<?= h($teacher['username']) ?>" <?= $isEdit ? 'readonly style="background:var(--text-muted);opacity:0.6;"' : 'required' ?>>
                    </div>
                    <div class="form-group">
                        <label for="password"><?= $isEdit ? 'New Password (leave blank to keep)' : 'Password' ?></label>
                        <input type="password" id="password" name="password" class="form-control" <?= $isEdit ? 'placeholder="Leave blank to keep current"' : 'required' ?>>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update Teacher' : 'Create Teacher' ?></button>
                    <a href="/admin/teachers.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php
} else {
    $search = trim($_GET['search'] ?? '');
    $where = ''; $params = [];
    if ($search) { $where = "WHERE t.first_name LIKE ? OR t.last_name LIKE ? OR t.employee_id LIKE ?"; $params = ["%$search%", "%$search%", "%$search%"]; }

    $stmt = db()->prepare("SELECT t.*, u.username, u.email, u.status as user_status FROM teachers t JOIN users u ON t.user_id = u.id $where ORDER BY t.first_name ASC");
    $stmt->execute($params); $teachers = $stmt->fetchAll();

    $totalAll = (int) db()->query("SELECT COUNT(*) FROM teachers WHERE status = 1")->fetchColumn();
    $withSchedule = (int) db()->query("SELECT COUNT(DISTINCT teacher_id) FROM schedule_entries WHERE status = 1")->fetchColumn();
    $activeUsers = (int) db()->query("SELECT COUNT(*) FROM users WHERE role_id = 2 AND status = 1")->fetchColumn();
    $totalEntries = (int) db()->query("SELECT COUNT(*) FROM schedule_entries WHERE status = 1")->fetchColumn();
?>
<div class="page-intro">
    <div class="page-intro-main">
        <h1>Teachers <em><?= $totalAll ?></em></h1>
        <p>Faculty directory — credentials, assignments, and load. Each teacher gets a login to run biometric attendance.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div><strong><?= $totalAll ?></strong><span>Faculty</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg></div><div><strong><?= $withSchedule ?></strong><span>With load</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--info-light); color: var(--info);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg></div><div><strong><?= $totalEntries ?></strong><span title="Total class meetings assigned across all teachers">Classes assigned</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--warning-light); color: #B07D16;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div><div><strong><?= $activeUsers ?></strong><span>Active logins</span></div></div>
    </div>
</div>

<div class="filter-pillbar">
    <form method="GET" class="fp-search" style="flex:1;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="search" placeholder="Search by name or employee ID…" value="<?= h($search) ?>" autocomplete="off">
        <?php if ($search): ?><a href="/admin/teachers.php" class="btn btn-sm btn-outline" style="border-radius: 999px;">Clear</a><?php endif; ?>
    </form>
    <div class="fp-actions">
        <span class="text-xs text-muted nowrap" style="font-variant-numeric: tabular-nums;"><?= count($teachers) ?> teacher<?= count($teachers)===1?'':'s' ?></span>
        <div class="fp-divider"></div>
        <a href="/admin/import.php?type=teachers" class="btn btn-outline btn-sm">Import CSV</a>
        <a href="/admin/teachers.php?action=add" class="btn btn-primary btn-sm">+ Add Teacher</a>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($teachers)): ?>
            <div class="empty-state" style="padding: 3rem 1rem;">
                <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg></div>
                <h3>No teachers found</h3>
                <p><?= $search ? 'Try a different search.' : 'Add your first faculty member.' ?></p>
                <?php if (!$search): ?><a href="/admin/teachers.php?action=add" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">+ Add Teacher</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Teacher</th><th>Username</th><th>Email</th><th>Status</th><th title="Classes assigned this semester">Load</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($teachers as $t): ?>
                            <?php $scheduleCount = db()->prepare("SELECT COUNT(*) FROM schedule_entries WHERE teacher_id = ? AND status = 1"); $scheduleCount->execute([$t['id']]); $sc = $scheduleCount->fetchColumn();
                                  $initials = strtoupper(substr($t['first_name'],0,1).substr($t['last_name'],0,1));
                            ?>
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="avatar" style="background: var(--nav-active); color: var(--primary); border-color: transparent;"><?= h($initials) ?></span>
                                        <div>
                                            <strong style="display:block; font-size:0.9rem; line-height:1.2;"><?= h($t['first_name'] . ' ' . $t['last_name']) ?></strong>
                                            <code style="font-size:0.70rem; background: transparent; padding:0; color: var(--text-muted);"><?= h($t['employee_id']) ?: 'No ID' ?></code>
                                        </div>
                                    </div>
                                </td>
                                <td><code><?= h($t['username']) ?></code></td>
                                <td style="font-size:0.85rem; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($t['email']) ?>"><?= h($t['email']) ?: '<span class="text-muted">—</span>' ?></td>
                                <td><span class="badge <?= $t['user_status'] ? 'badge-success' : 'badge-danger' ?>"><?= $t['user_status'] ? 'Active' : 'Inactive' ?></span></td>
                                <td><span class="badge <?= $sc > 0 ? 'badge-info' : 'badge-warning' ?>" title="Classes assigned this semester"><?= $sc ?> <?= $sc===1?'class':'classes' ?></span></td>
                                <td style="text-align:right;"><a href="/admin/teachers.php?action=edit&id=<?= $t['id'] ?>" class="btn btn-sm btn-outline">Edit</a></td>
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

