<?php
/**
 * Flash Messages Partial
 */
$success = flash('success');
$error   = flash('error');
$warning = flash('warning');
$info    = flash('info');
?>

<?php if ($success): ?>
    <div class="alert alert-success fade-in"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger fade-in"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($warning): ?>
    <div class="alert alert-warning fade-in"><?= h($warning) ?></div>
<?php endif; ?>

<?php if ($info): ?>
    <div class="alert alert-info fade-in"><?= h($info) ?></div>
<?php endif; ?>
