<?php
/**
 * Admin Layout — wraps all admin pages
 * Requires: $pageTitle (string), $currentPage (string) for active nav
 */
require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$user = current_user();
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
    <link rel="stylesheet" href="/assets/css/style.css?v=20261023">
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo"><img src="/assets/images/logo.jpg" alt="<?= config('short_name') ?> logo"></div>
                <div>
                    <h3><?= config('short_name') ?></h3>
                    <div class="subtitle"><?= config('school_short') ?> · Admin</div>
                </div>
            </div>
            <nav class="sidebar-nav">
                <span class="nav-pill" aria-hidden="true"></span>
                <a href="/admin/dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    </span>
                    Dashboard
                </a>
                <div class="nav-group">People</div>
                <a href="/admin/teachers.php" class="nav-item <?= $currentPage === 'teachers' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </span>
                    Teachers
                </a>
                <a href="/admin/students.php" class="nav-item <?= $currentPage === 'students' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </span>
                    Students
                </a>
                <div class="nav-group">Academics</div>
                <a href="/admin/subjects.php" class="nav-item <?= $currentPage === 'subjects' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    </span>
                    Subjects
                </a>
                <a href="/admin/sections.php" class="nav-item <?= $currentPage === 'sections' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c6 3 10 0 12-2v-5"/></svg>
                    </span>
                    Sections
                </a>
                <a href="/admin/school-years.php" class="nav-item <?= $currentPage === 'school-years' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                    School Years
                </a>
                <a href="/admin/schedule.php" class="nav-item <?= $currentPage === 'schedule' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    </span>
                    Teachers Master Schedule
                </a>
                <div class="nav-group">System</div>
                <a href="/admin/qr-codes.php" class="nav-item <?= $currentPage === 'qr-codes' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM3 21v-3M21 3v3M21 14h-3M14 21h3"/></svg>
                    </span>
                    QR Codes
                </a>
                <a href="/admin/reports.php" class="nav-item <?= $currentPage === 'reports' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </span>
                    Reports
                </a>
                <a href="/admin/sms-logs.php" class="nav-item <?= $currentPage === 'sms-logs' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    </span>
                    Notifications
                </a>
                <a href="/admin/audit-logs.php" class="nav-item <?= $currentPage === 'audit-logs' ? 'active' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    </span>
                    Audit Logs
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
                <span class="user-name"><?= h($user['full_name'] ?? 'Admin') ?></span>
                <span class="user-role">Administrator</span>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="page-header flex items-center justify-between">
                <div>
                    <h1><?= h($pageTitle ?? 'Dashboard') ?></h1>
                    <p class="text-muted"><?= config('school_name') ?> | S.Y. <?= config('academic_year') ?></p>
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
