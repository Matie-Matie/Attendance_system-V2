<?php
/**
 * Teacher Layout — wraps all teacher pages
 * Requires: $pageTitle (string), $currentPage (string)
 */
require_once __DIR__ . '/../../bootstrap.php';
require_role('teacher');

$user = current_user();
$teacher = current_teacher();
$currentPage = $currentPage ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'Dashboard') ?> | <?= config('name') ?> — <?= config('school_short') ?></title>
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261024">
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo"><img src="/assets/images/logo.jpg" alt="<?= config('short_name') ?> logo"></div>
                <div>
                    <h3><?= config('short_name') ?></h3>
                    <div class="subtitle"><?= config('school_short') ?> · Teacher</div>
                </div>
            </div>
            <nav class="sidebar-nav">
                <span class="nav-pill" aria-hidden="true"></span>
                <a href="/teacher/dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    </span>
                    Dashboard
                </a>
                <a href="/teacher/attendance.php" class="nav-item <?= $currentPage === 'attendance' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    </span>
                    Attendance
                </a>
                <a href="/teacher/reports.php" class="nav-item <?= $currentPage === 'reports' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </span>
                    Reports
                </a>
                <a href="/account.php" class="nav-item <?= $currentPage === 'account' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    Account
                </a>
                <a href="/auth/logout.php" class="nav-item logout">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </span>
                    Logout
                </a>
            </nav>
            <div class="sidebar-footer">
                <span class="user-name"><?= h($user['full_name'] ?? 'Teacher') ?></span>
                <span class="user-role">Teacher</span>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="page-header flex items-center justify-between">
                <div>
                    <h1><?= h($pageTitle ?? 'Dashboard') ?></h1>
                    <p class="text-muted">Welcome, <?= h($user['full_name'] ?? 'Teacher') ?> | <?= config('school_name') ?></p>
                </div>
                <div class="header-actions">
                    <?= $pageActions ?? '' ?>
                    <?php include __DIR__ . '/../partials/theme-toggle.php'; ?>
                </div>
            </header>

            <?php include __DIR__ . '/../partials/alerts.php'; ?>

            <div class="reveal">
                <?= $slot ?? '' ?>
            </div>
        </main>
    </div>

    <script>
    (function () {
        var nav = document.querySelector('.sidebar-nav');
        var pill = nav && nav.querySelector('.nav-pill');
        var active = nav && nav.querySelector('.nav-item.active');
        if (!nav || !pill) return;
        function place(el, animate) {
            if (!el) { pill.classList.remove('ready'); return; }
            if (!animate) pill.style.transition = 'none';
            pill.style.setProperty('--pill-y', (el.offsetTop) + 'px');
            pill.style.height = el.offsetHeight + 'px';
            pill.classList.add('ready');
            if (!animate) requestAnimationFrame(function () { pill.style.transition = ''; });
        }
        place(active, false);
        nav.querySelectorAll('.nav-item:not(.logout)').forEach(function (item) {
            item.addEventListener('mouseenter', function () { place(item, true); });
        });
        nav.addEventListener('mouseleave', function () { place(active, true); });
        window.addEventListener('resize', function () { place(active, false); });
    })();
    </script>
    <script src="/assets/js/zoom-lock.js?v=20261021" defer></script>
</body>
</html>
