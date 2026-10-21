<?php
/**
 * Login Page
 */
require_once __DIR__ . '/../../bootstrap.php';

// If already logged in, redirect
if (is_authenticated()) {
    $role = $_SESSION['user_role'] ?? '';
    redirect($role === 'admin' ? '/admin/dashboard.php' : '/teacher/dashboard.php');
}

$error = flash('error');

// Handle login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        $role = $_SESSION['user_role'] ?? '';
        $_SESSION['_flash']['success'] = 'Welcome back, ' . ($_SESSION['user_name'] ?? '') . '!';
        redirect($role === 'admin' ? '/admin/dashboard.php' : '/teacher/dashboard.php');
    } else {
        $error = 'Invalid username or password.';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= config('name') ?> — <?= config('school_short') ?></title>
    <script>
        (function () {
            var d = document.documentElement;
            d.classList.remove('no-js'); d.classList.add('js');
            try {
                var t = localStorage.getItem('theme');
                if (t === 'dark' || t === 'light') d.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,ital,wght@9..144,0,700;9..144,1,800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261022">
</head>
<body class="login-page split">
    <div class="login-split fade-in">
        <!-- Left: Editorial Hero -->
        <div class="login-hero">
            <div class="login-hero-top">
                <div class="login-hero-logo"><img src="/assets/images/logo.jpg" alt="<?= config('short_name') ?> logo"></div>
                <div class="login-hero-brand">
                    <span class="login-hero-kicker"><?= config('school_name') ?></span>
                    <h2><?= config('name') ?></h2>
                </div>
            </div>

            <div class="login-hero-main">
                <h1>Attendance, <br><em>perfectly kept.</em></h1>
                <p class="hero-lead">QR presence for Senior High. No sheets, no queues — just tap, scan, and go. Built for teachers who want to teach.</p>

                <div class="login-hero-stats">
                    <div class="hero-stat">
                        <strong>99.2%</strong>
                        <span>Scan success</span>
                    </div>
                    <div class="hero-stat">
                        <strong>&lt; 2s</strong>
                        <span>Avg. check-in</span>
                    </div>
                    <div class="hero-stat">
                        <strong>Zero</strong>
                        <span>Paper used</span>
                    </div>
                </div>

                <div class="login-hero-foot">
                    <span>S.Y. <?= config('academic_year') ?></span>
                    <span class="dot-sep"></span>
                    <span><?= config('name') ?></span>
                    <span class="dot-sep"></span>
                    <span style="color: var(--success); font-weight: 700;">● Live</span>
                </div>
            </div>
        </div>

        <!-- Right: Form -->
        <div class="login-form-side">
            <div class="login-card architech">
                <div class="login-header">
                    <h1>Welcome back</h1>
                    <p>Sign in to the Attendance System — Admin or Teacher</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="field-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="username" name="username" class="form-control"
                                   placeholder="admin or teacher username" required autofocus autocomplete="username">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="field-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="password" name="password" class="form-control"
                                   placeholder="Your password" required autocomplete="current-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">Sign In →</button>
                </form>

                <div class="login-meta" style="justify-content:center;">
                    <span><?= config('name') ?></span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
