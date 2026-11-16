<?php
/**
 * Admin — Backup & Restore
 *
 * Moves a whole school between machines: export here, import there.
 * Used to carry the data the office already built on the localhost PC up to
 * the online site (or bring online data back down).
 */
$pageTitle = 'Backup & Restore';
$currentPage = 'backup';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$backupDir = backup_dir();
$staged    = $_SESSION['backup_staged'] ?? null;

// How much data this site holds right now — shown next to the incoming file.
$currentCounts = [];
foreach (backup_tables() as $table) {
    try {
        $currentCounts[$table] = (int) db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (\Throwable $e) {
        $currentCounts[$table] = 0;
    }
}

$labels = [
    'roles'              => 'Roles',
    'users'              => 'Login accounts',
    'teachers'           => 'Teachers',
    'subjects'           => 'Subjects',
    'sections'           => 'Sections',
    'school_years'       => 'School years',
    'semesters'          => 'Terms',
    'students'           => 'Students',
    'schedule_entries'   => 'Class schedule',
    'attendance_sessions'=> 'Attendance sessions',
    'attendance_records' => 'Attendance records',
    'sms_logs'           => 'Parent notifications',
    'audit_logs'         => 'Audit log entries',
];

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
};

/* ---------------------------------------------------------------- download
 * Serve a backup that already sits on this server (safety copies included).
 */
if (isset($_GET['download'])) {
    $name = basename((string) $_GET['download']);
    $path = $backupDir . '/' . $name;
    if (!preg_match('/^[A-Za-z0-9._-]+\.json(\.gz)?$/', $name) || !is_file($path)) {
        http_response_code(404);
        exit('Backup not found.');
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ------------------------------------------------------------------- POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitAction = $_POST['submit_action'] ?? '';

    if ($submitAction === 'export') {
        set_time_limit(0);
        try {
            $result = backup_export();
            log_audit('export', 'backup', null, ['file' => $result['name'], 'counts' => $result['counts']]);
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $result['name'] . '"');
            header('Content-Length: ' . filesize($result['path']));
            readfile($result['path']);
            exit;
        } catch (\Throwable $e) {
            error_log('SCPLS backup export: ' . $e->getMessage());
            $_SESSION['_flash']['error'] = 'Could not create the backup: ' . $e->getMessage();
            redirect('/admin/backup.php');
        }
    }

    if ($submitAction === 'stage') {
        $file = $_FILES['backup'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $why = [
                UPLOAD_ERR_INI_SIZE   => 'the file is bigger than this server accepts',
                UPLOAD_ERR_FORM_SIZE  => 'the file is too big',
                UPLOAD_ERR_PARTIAL    => 'the upload was cut off — try again',
                UPLOAD_ERR_NO_FILE    => 'no file was chosen',
                UPLOAD_ERR_NO_TMP_DIR => 'the server has no temp folder',
                UPLOAD_ERR_CANT_WRITE => 'the server could not write the file',
            ][$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'the upload failed';
            $_SESSION['_flash']['error'] = 'Could not read that file — ' . $why . '.';
            redirect('/admin/backup.php');
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['json', 'gz'], true)) {
            $_SESSION['_flash']['error'] = 'Pick the .json.gz file made by Export.';
            redirect('/admin/backup.php');
        }

        $token = bin2hex(random_bytes(6));
        $path  = $backupDir . '/import-' . $token . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            $_SESSION['_flash']['error'] = 'The upload could not be saved on this server.';
            redirect('/admin/backup.php');
        }

        try {
            $payload = backup_read_payload($path);
        } catch (\Throwable $e) {
            @unlink($path);
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/admin/backup.php');
        }

        $_SESSION['backup_staged'] = [
            'path'   => $path,
            'name'   => (string) $file['name'],
            'meta'   => $payload['meta'] ?? [],
            'counts' => backup_payload_counts($payload),
        ];
        redirect('/admin/backup.php');
    }

    if ($submitAction === 'cancel') {
        if ($staged && is_file($staged['path'] ?? '')) @unlink($staged['path']);
        unset($_SESSION['backup_staged']);
        $_SESSION['_flash']['info'] = 'Import cancelled — nothing was changed.';
        redirect('/admin/backup.php');
    }

    if ($submitAction === 'import') {
        if (!$staged || !is_file($staged['path'] ?? '')) {
            $_SESSION['_flash']['error'] = 'That import has expired — upload the file again.';
            redirect('/admin/backup.php');
        }
        if (($_POST['confirm'] ?? '') !== 'yes') {
            $_SESSION['_flash']['error'] = 'Tick the confirmation box first.';
            redirect('/admin/backup.php');
        }

        set_time_limit(0);
        try {
            $payload = backup_read_payload($staged['path']);
            $result  = backup_import($payload);

            log_audit('import', 'backup', null, [
                'file'   => $staged['name'],
                'counts' => $result['counts'],
                'safety' => $result['safety'] ? basename($result['safety']) : null,
            ]);

            @unlink($staged['path']);
            unset($_SESSION['backup_staged']);

            // The rows were replaced — make sure this session still points at
            // an admin account from the imported data, otherwise sign out.
            $me = current_user();
            if (!$me || $me['role_name'] !== 'admin') {
                $admin = db()->query(
                    "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                      WHERE r.name = 'admin' AND u.status = 1 ORDER BY u.id LIMIT 1"
                )->fetch();
                if ($admin) {
                    $_SESSION['user_id'] = (int) $admin['id'];
                } else {
                    session_destroy();
                    header('Location: /auth/login.php');
                    exit;
                }
            }

            $total = array_sum($result['counts']);
            $_SESSION['_flash']['success'] = 'Import finished — ' . number_format($total)
                . ' rows restored. Please check Students and Teacher Schedule.'
                . ($result['safety'] ? ' A safety copy of the old data was saved as ' . basename($result['safety']) . '.' : '');
            redirect('/admin/dashboard.php');
        } catch (\Throwable $e) {
            error_log('SCPLS backup import: ' . $e->getMessage());
            $_SESSION['_flash']['error'] = 'Import failed: ' . $e->getMessage() . ' Nothing was changed.';
            redirect('/admin/backup.php');
        }
    }
}

// Recent files sitting on this server (safety copies + your exports).
$backups = [];
foreach (glob($backupDir . '/*.json.gz') ?: [] as $file) {
    $backups[] = ['name' => basename($file), 'bytes' => (int) filesize($file), 'time' => (int) filemtime($file)];
}
usort($backups, static fn($a, $b) => $b['time'] <=> $a['time']);
$backups = array_slice($backups, 0, 10);

ob_start();
?>
<div class="page-intro">
    <div class="page-intro-main">
        <h1>Backup &amp; Restore</h1>
        <p>Move the whole school between the office PC and this site. Export on the machine that has the data, then import the file here — students, sections, schedules, attendance and parent-notification history all travel together.</p>
    </div>
</div>

<div class="dash-grid">
    <div class="card">
        <div class="card-header"><h3>Export from this site</h3></div>
        <div class="card-body">
            <p class="text-sm text-muted" style="margin-top:0;">Downloads one file with everything this site knows. Do this on the <strong>localhost</strong> copy when it holds the work you want to keep.</p>
            <form method="POST">
                <input type="hidden" name="submit_action" value="export">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Download backup (.json.gz)</button>
            </form>
            <p class="text-xs text-muted" style="margin:0.9rem 0 0;">
                <?= number_format(array_sum($currentCounts)) ?> rows on this site now —
                <?= (int) $currentCounts['students'] ?> students, <?= (int) $currentCounts['teachers'] ?> teachers,
                <?= (int) $currentCounts['schedule_entries'] ?> class blocks,
                <?= (int) $currentCounts['attendance_records'] ?> attendance records.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Import into this site</h3></div>
        <div class="card-body">
            <?php if ($staged): ?>
                <?php $meta = $staged['meta'] ?? []; ?>
                <div class="alert alert-warning" style="margin-top:0;">
                    This replaces <strong>all</strong> students, teachers, sections, schedules and attendance on this site.
                    A safety copy of what is here now is saved automatically before anything changes.
                </div>
                <p class="text-sm" style="margin:0 0 0.4rem;">
                    <strong><?= h($staged['name']) ?></strong>
                    <?php if (!empty($meta['generated'])): ?>
                        · made <?= h(date('M j, Y g:i A', strtotime((string) $meta['generated']))) ?>
                    <?php endif; ?>
                    <?php if (!empty($meta['version'])): ?> · from SCPLS v<?= h((string) $meta['version']) ?><?php endif; ?>
                </p>
                <?php if (!empty($meta['school'])): ?>
                    <p class="text-xs text-muted" style="margin:0 0 0.75rem;">Source school: <?= h((string) $meta['school']) ?></p>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Data</th><th style="text-align:right;">In the file</th><th style="text-align:right;">On this site now</th></tr></thead>
                        <tbody>
                            <?php foreach ($staged['counts'] as $table => $incoming): ?>
                                <tr>
                                    <td><?= h($labels[$table] ?? $table) ?></td>
                                    <td style="text-align:right;"><strong><?= number_format((int) $incoming) ?></strong></td>
                                    <td style="text-align:right;" class="text-muted"><?= number_format((int) ($currentCounts[$table] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" style="margin-top:1rem;">
                    <input type="hidden" name="submit_action" value="import">
                    <?= csrf_field() ?>
                    <label class="flex items-center gap-2" style="font-size:0.85rem; margin-bottom:0.9rem;">
                        <input type="checkbox" name="confirm" value="yes" required>
                        I understand this replaces the data on this site.
                    </label>
                    <div class="form-actions" style="margin:0;">
                        <button type="submit" class="btn btn-danger">Import and replace</button>
                    </div>
                </form>
                <form method="POST" style="margin-top:0.6rem;">
                    <input type="hidden" name="submit_action" value="cancel">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline btn-sm">Cancel</button>
                </form>
            <?php else: ?>
                <p class="text-sm text-muted" style="margin-top:0;">Pick the file made by <strong>Export</strong> on the other machine. Nothing changes until you review the counts and confirm.</p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="submit_action" value="stage">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="backup">Backup file (.json.gz)</label>
                        <input type="file" id="backup" name="backup" class="form-control" accept=".gz,.json,application/gzip,application/json" required>
                    </div>
                    <button type="submit" class="btn btn-outline">Upload &amp; preview</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1.25rem;">
    <div class="card-header"><h3>Files on this server</h3></div>
    <div class="card-body p-0">
        <?php if (!$backups): ?>
            <div class="empty-state" style="padding:2rem 1rem;"><h3>No backups yet</h3><p>Your first export, or the safety copy made before an import, will appear here.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>File</th><th>Made</th><th>Size</th><th style="text-align:right;">Download</th></tr></thead>
                    <tbody>
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td><code style="font-size:0.78rem;"><?= h($b['name']) ?></code></td>
                                <td class="text-sm"><?= h(date('M j, Y g:i A', $b['time'])) ?></td>
                                <td class="text-sm text-muted"><?= h($formatBytes($b['bytes'])) ?></td>
                                <td style="text-align:right;"><a class="btn btn-sm btn-outline" href="/admin/backup.php?download=<?= urlencode($b['name']) ?>">Download</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
