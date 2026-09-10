<?php
/**
 * Account — change your own password (admin or teacher).
 */
require_once __DIR__ . '/../bootstrap.php';
require_auth();

$user = current_user();
if (!$user) { redirect('/auth/logout.php'); }

$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }
    if ($current !== '' && $new === $current) {
        $errors[] = 'New password must be different from the current one.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            log_audit('password_change', 'user', (int) $user['id']);
            $done = true;
        } catch (\Throwable $e) {
            error_log('SCPLS password change: ' . $e->getMessage());
            $errors[] = 'Could not update your password. Please try again.';
        }
    }
}

$isAdmin = ($user['role_name'] ?? '') === 'admin';
$pageTitle = 'Account';
$currentPage = 'account';
ob_start();
?>
<div class="card form-card" style="max-width:480px;">
    <div class="card-header"><h3>Change Password</h3></div>
    <div class="card-body">
        <p class="text-muted text-sm" style="margin-bottom:1rem;">
            Signed in as <strong><?= h($user['full_name']) ?></strong>
            (<?= h($user['username']) ?> &middot; <?= h(ucfirst($user['role_name'] ?? '')) ?>)
        </p>

        <?php if ($done): ?>
            <div class="alert alert-success fade-in">Password updated. Use it next time you sign in.</div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger fade-in">
                <strong>Could not change password:</strong>
                <ul class="error-list"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Update Password</button>
                <a href="<?= $isAdmin ? '/admin/dashboard.php' : '/teacher/dashboard.php' ?>" class="btn btn-outline">Back</a>
            </div>
        </form>
    </div>
</div>
<?php
$slot = ob_get_clean();
include __DIR__ . '/../includes/layouts/' . ($isAdmin ? 'admin' : 'teacher') . '-layout.php';
