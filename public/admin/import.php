<?php
/**
 * Admin - CSV Import (students + teachers)
 *
 * Flow: pick type -> upload CSV -> preview (dry run) -> confirm import.
 * Excel users: File > Save As > CSV UTF-8 (.csv), then upload here.
 */
$pageTitle = 'Import';
$currentPage = 'students';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$type = ($_GET['type'] ?? $_POST['type'] ?? 'students') === 'teachers' ? 'teachers' : 'students';

/* ---------- CSV templates ---------- */
if (($_GET['template'] ?? '') === $type) {
    $headers = $type === 'students'
        ? ['student_id', 'first_name', 'last_name', 'grade_level', 'section', 'parent_name', 'parent_contact', 'parent_email']
        : ['first_name', 'last_name', 'employee_id', 'username', 'password', 'email'];
    $example = $type === 'students'
        ? ['SHS-2026-099', 'Juan', 'Dela Cruz', 'Grade 11', 'Grade 11 - ABM', 'Maria Dela Cruz', '09171234567', 'parent@example.com']
        : ['Jose', 'Rizal', 'EMP-099', 'jrizal', 'changeme123', 'jose.rizal@mnrhs.edu.ph'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import-' . $type . '-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens it correctly
    fputcsv($out, $headers, ',', '"', '\\');
    fputcsv($out, $example, ',', '"', '\\');
    fclose($out);
    exit;
}

/* ---------- Helpers ---------- */
function import_sections_map(): array
{
    $map = [];
    foreach (db()->query("SELECT id, name FROM sections WHERE status = 1")->fetchAll() as $s) {
        $map[mb_strtolower(trim($s['name']))] = (int) $s['id'];
    }
    return $map;
}

/** Parse + validate. Returns [rows, errors]. Each row: ['data'=>..., 'ok'=>bool, 'problems'=>[...]]. */
function import_dry_run(string $type, string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) return [[], ['Could not read the uploaded file.']];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // strip BOM
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
    if (count($lines) < 2) return [[], ['The file has no data rows. Keep the header row and add at least one row below it.']];
    if (count($lines) > 2001) return [[], ['Too many rows (max 2000 plus the header). Split the file and import in parts.']];

    // Delimiter sniff: comma vs semicolon (Excel-PH often exports semicolons).
    $delim = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
    $header = array_map(fn($h) => mb_strtolower(trim($h)), str_getcsv(array_shift($lines), $delim, '"', '\\'));

    $expected = $type === 'students'
        ? ['student_id', 'first_name', 'last_name', 'grade_level', 'section', 'parent_name', 'parent_contact', 'parent_email']
        : ['first_name', 'last_name', 'employee_id', 'username', 'password', 'email'];
    if ($header !== $expected) {
        return [[], ['Wrong columns. Expected: ' . implode(', ', $expected) . '. Tip: download the template below and paste your data into it.']];
    }

    $sections = $type === 'students' ? import_sections_map() : [];
    $existingSids = [];
    if ($type === 'students') {
        foreach (db()->query("SELECT student_id FROM students")->fetchAll() as $r) {
            $existingSids[mb_strtolower(trim($r['student_id']))] = true;
        }
    }
    $existingUsers = [];
    if ($type === 'teachers') {
        foreach (db()->query("SELECT username FROM users")->fetchAll() as $r) {
            $existingUsers[mb_strtolower(trim($r['username']))] = true;
        }
        foreach (db()->query("SELECT employee_id FROM teachers WHERE employee_id IS NOT NULL")->fetchAll() as $r) {
            $existingUsers['emp:' . mb_strtolower(trim($r['employee_id']))] = true;
        }
    }

    $rows = [];
    $seenSids = [];
    $seenUsers = [];
    foreach ($lines as $i => $line) {
        $lineNo = $i + 2; // +header
        $cols = str_getcsv($line, $delim, '"', '\\');
        $cols = array_pad($cols, count($expected), '');
        $data = array_combine($expected, array_map('trim', $cols));
        $problems = [];

        if ($type === 'students') {
            if ($data['student_id'] === '') $problems[] = 'Student ID is missing.';
            if ($data['first_name'] === '') $problems[] = 'First name is missing.';
            if ($data['last_name'] === '') $problems[] = 'Last name is missing.';
            $key = mb_strtolower($data['student_id']);
            if ($key !== '' && (isset($existingSids[$key]) || isset($seenSids[$key]))) {
                $problems[] = "Student ID '{$data['student_id']}' is already used.";
            }
            $seenSids[$key] = true;
            if ($data['grade_level'] !== '' && !in_array($data['grade_level'], ['Grade 11', 'Grade 12'], true)) {
                $problems[] = "Grade must be 'Grade 11' or 'Grade 12'.";
            }
            if ($data['section'] !== '' && !isset($sections[mb_strtolower($data['section'])])) {
                $problems[] = "Section '{$data['section']}' was not found — leave blank or use an exact section name.";
            }
            if ($data['parent_email'] !== '' && !filter_var($data['parent_email'], FILTER_VALIDATE_EMAIL)) {
                $problems[] = 'Parent email is not valid.';
            }
        } else {
            if ($data['first_name'] === '') $problems[] = 'First name is missing.';
            if ($data['last_name'] === '') $problems[] = 'Last name is missing.';
            if ($data['username'] === '') $problems[] = 'Username is missing.';
            if (strlen($data['password']) < 6) $problems[] = 'Password must be at least 6 characters.';
            $ukey = mb_strtolower($data['username']);
            if ($ukey !== '' && (isset($existingUsers[$ukey]) || isset($seenUsers[$ukey]))) {
                $problems[] = "Username '{$data['username']}' is already taken.";
            }
            $seenUsers[$ukey] = true;
            if ($data['employee_id'] !== '' && isset($existingUsers['emp:' . mb_strtolower($data['employee_id'])])) {
                $problems[] = "Employee ID '{$data['employee_id']}' is already used.";
            }
            if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $problems[] = 'Email is not valid.';
            }
        }

        $rows[] = ['line' => $lineNo, 'data' => $data, 'ok' => empty($problems), 'problems' => $problems];
    }

    return [$rows, []];
}

/* ---------- Upload -> stash in session, show preview ---------- */
$preview = $_SESSION['_import_preview'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload') {
    unset($_SESSION['_import_preview']);
    $preview = null;
    $file = $_FILES['csv'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $_SESSION['_flash']['error'] = 'Choose a .csv file first.';
        redirect('/admin/import.php?type=' . $type);
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        $_SESSION['_flash']['error'] = 'File is too big (max 2MB). Split it and try again.';
        redirect('/admin/import.php?type=' . $type);
    }
    $ext = mb_strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $_SESSION['_flash']['error'] = 'Only .csv files are accepted. In Excel: File > Save As > CSV UTF-8.';
        redirect('/admin/import.php?type=' . $type);
    }
    [$rows, $errors] = import_dry_run($type, $file['tmp_name']);
    if (!empty($errors)) {
        $_SESSION['_flash']['error'] = implode(' ', $errors);
        redirect('/admin/import.php?type=' . $type);
    }
    $_SESSION['_import_preview'] = ['type' => $type, 'rows' => $rows, 'name' => $file['name']];
    redirect('/admin/import.php?type=' . $type);
}

/* ---------- Confirm -> insert valid rows in one transaction ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    $stashed = $_SESSION['_import_preview'] ?? null;
    unset($_SESSION['_import_preview']);
    if (!$stashed || ($stashed['type'] ?? '') !== $type || empty($stashed['rows'])) {
        $_SESSION['_flash']['error'] = 'Nothing to import — upload the file again.';
        redirect('/admin/import.php?type=' . $type);
    }
    $sections = $type === 'students' ? import_sections_map() : [];
    $imported = 0;
    $skipped = 0;
    $db = db();
    try {
        $db->beginTransaction();
        foreach ($stashed['rows'] as $row) {
            if (!$row['ok']) { $skipped++; continue; }
            $d = $row['data'];
            if ($type === 'students') {
                $secId = $d['section'] !== '' ? ($sections[mb_strtolower($d['section'])] ?? null) : null;
                $stmt = $db->prepare("INSERT INTO students (student_id, first_name, last_name, grade_level, section_id, parent_name, parent_contact, parent_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $d['student_id'], $d['first_name'], $d['last_name'],
                    $d['grade_level'] !== '' ? $d['grade_level'] : null, $secId,
                    $d['parent_name'] !== '' ? $d['parent_name'] : null,
                    $d['parent_contact'] !== '' ? $d['parent_contact'] : null,
                    $d['parent_email'] !== '' ? $d['parent_email'] : null,
                ]);
            } else {
                $hashed = password_hash($d['password'], PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, role_id) VALUES (?, ?, ?, ?, 2)");
                $stmt->execute([$d['username'], $hashed, $d['first_name'] . ' ' . $d['last_name'], $d['email'] !== '' ? $d['email'] : null]);
                $userId = (int) $db->lastInsertId();
                $stmt = $db->prepare("INSERT INTO teachers (user_id, employee_id, first_name, last_name) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $d['employee_id'] !== '' ? $d['employee_id'] : null, $d['first_name'], $d['last_name']]);
            }
            $imported++;
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('SCPLS csv import: ' . $e->getMessage());
        $_SESSION['_flash']['error'] = 'Import failed halfway — nothing was saved. Fix the file and try again.';
        redirect('/admin/import.php?type=' . $type);
    }
    log_audit('csv_import', $type, null, ['file' => $stashed['name'] ?? '', 'imported' => $imported, 'skipped' => $skipped]);
    $_SESSION['_flash']['success'] = "Imported {$imported} " . ($type === 'students' ? 'student' : 'teacher') . ($imported === 1 ? '' : 's') . ($skipped > 0 ? " ({$skipped} bad rows skipped — see preview rules)." : '.');
    redirect($type === 'students' ? '/admin/students.php' : '/admin/teachers.php');
}

ob_start();
$previewRows = $preview && ($preview['type'] ?? '') === $type ? $preview['rows'] : [];
$okCount = count(array_filter($previewRows, fn($r) => $r['ok']));
$badCount = count($previewRows) - $okCount;
?>
<div class="page-intro">
    <div class="page-intro-main">
        <h1>Import <em><?= $type === 'students' ? 'Students' : 'Teachers' ?></em></h1>
        <p>Upload a CSV, check the preview, then confirm. Using Excel? File &gt; Save As &gt; CSV UTF-8 first.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div><strong><?= count($previewRows) ?></strong><span>Rows in file</span></div></div>
        <div class="mini-stat"><div><strong><?= $okCount ?></strong><span>Ready</span></div></div>
        <div class="mini-stat"><div><strong><?= $badCount ?></strong><span>With problems</span></div></div>
    </div>
</div>

<div class="filter-pillbar">
    <div class="fp-group">
        <a href="/admin/import.php?type=students" class="fp-pill <?= $type === 'students' ? 'active' : '' ?>">Students</a>
        <a href="/admin/import.php?type=teachers" class="fp-pill <?= $type === 'teachers' ? 'active' : '' ?>">Teachers</a>
    </div>
    <div class="fp-actions">
        <a href="/admin/import.php?type=<?= $type ?>&template=<?= $type ?>" class="btn btn-sm btn-outline">Download template</a>
    </div>
</div>

<div class="card form-card">
    <div class="card-header"><h3>1 — Upload CSV</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="step" value="upload">
            <input type="hidden" name="type" value="<?= $type ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label for="csv">CSV file (max 2MB, 2000 rows)</label>
                    <input type="file" id="csv" name="csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="form-group" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-primary">Check file</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($previewRows)): ?>
<div class="card">
    <div class="card-header">
        <h3>2 — Preview<?= $badCount > 0 ? " ({$badCount} rows have problems and will be skipped)" : '' ?></h3>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="step" value="confirm">
            <input type="hidden" name="type" value="<?= $type ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-primary" <?= $okCount === 0 ? 'disabled' : '' ?>
                onclick="return confirm('Import <?= $okCount ?> <?= $type ?> now?<?= $badCount > 0 ? " {$badCount} bad rows will be skipped." : '' ?>')">
                Import <?= $okCount ?> now
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:480px;overflow-y:auto;">
            <table class="table">
                <thead><tr><th>Row</th><th>Details</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($previewRows, 0, 60) as $r): ?>
                        <tr>
                            <td><?= $r['line'] ?></td>
                            <td style="font-size:0.85rem;"><?= h(implode(' · ', array_filter($r['data']))) ?></td>
                            <td><?= $r['ok'] ? '<span class="badge badge-success">Ready</span>' : '<span class="badge badge-danger">' . h(implode(' ', $r['problems'])) . '</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($previewRows) > 60): ?>
                <p class="text-sm text-muted" style="padding:0.75rem 1rem;">Showing first 60 of <?= count($previewRows) ?> rows.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
