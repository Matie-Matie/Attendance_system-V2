<?php
/**
 * Admin - Generate & Print Student QR Codes
 *
 * Each student gets a permanent QR card. The QR code encodes the
 * student's ID number, which students scan at the attendance kiosk
 * (student/scan.php) or at the teacher's QR scanner.
 *
 * Views:
 *   /admin/qr-codes.php                -> grid of ALL students (Print All)
 *   /admin/qr-codes.php?section_id=N   -> grid filtered by section
 *   /admin/qr-codes.php?student_id=N   -> single printable card
 */
$pageTitle = 'QR Codes';
$currentPage = 'qr-codes';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

// Filters
$sectionId = (int)($_GET['section_id'] ?? 0);
$singleStudentId = (int)($_GET['student_id'] ?? 0);

$sections = db()->query("SELECT id, name, grade_level FROM sections WHERE status = 1 ORDER BY grade_level, name")->fetchAll();

if ($singleStudentId > 0) {
    $stmt = db()->prepare("
        SELECT s.*, sec.name AS section_name
        FROM students s
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.id = ? AND s.status = 1
    ");
    $stmt->execute([$singleStudentId]);
    $students = $stmt->fetchAll();
} elseif ($sectionId > 0) {
    $stmt = db()->prepare("
        SELECT s.*, sec.name AS section_name
        FROM students s
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.section_id = ? AND s.status = 1
        ORDER BY s.last_name ASC
    ");
    $stmt->execute([$sectionId]);
    $students = $stmt->fetchAll();
} else {
    $students = db()->query("
        SELECT s.*, sec.name AS section_name
        FROM students s
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.status = 1
        ORDER BY s.last_name ASC
    ")->fetchAll();
}

ob_start();
?>

<link rel="stylesheet" href="/assets/css/scan.css">
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<?php if ($singleStudentId > 0 && !empty($students)): $s = $students[0]; ?>
    <!-- Single QR Card -->
    <div class="card qr-print-card" style="max-width:480px;margin:0 auto;">
        <div class="card-body" style="text-align:center;padding:2rem;">
            <div style="font-size:1.3rem;font-weight:700;margin-bottom:0.25rem;"><?= h($s['first_name'] . ' ' . $s['last_name']) ?></div>
            <div style="color:var(--text-muted);font-size:0.9rem;"><?= h($s['student_id']) ?></div>
            <div style="color:var(--text-muted);font-size:0.9rem;margin-bottom:1rem;"><?= h($s['grade_level'] ?: '') ?> <?= h($s['section_name'] ?? '') ?></div>

            <div style="display:inline-block;padding:1rem;background:#fff;border:2px solid var(--border-color);border-radius:var(--radius);margin:0 auto 1rem;">
                <div id="qrcode"></div>
            </div>

            <div class="alert alert-info" style="font-size:0.85rem;text-align:left;">
                <strong>How to use:</strong> Print this QR card and give it to the student. They scan it at the
                attendance kiosk or the teacher's QR scanner to log their attendance.
            </div>

            <div class="no-print" style="display:flex;gap:0.75rem;justify-content:center;margin-top:1.25rem;">
                <button onclick="window.print()" class="btn btn-primary">Print QR Card</button>
                <a href="/admin/qr-codes.php" class="btn btn-outline">All QR Codes</a>
                <a href="/admin/students.php" class="btn btn-outline">← Students</a>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($s['student_id']) ?>,
            width: 200,
            height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });
    });
    </script>
<?php else: ?>
    <!-- All / Filtered QR Grid -->
    <div class="page-intro no-print">
        <div class="page-intro-main">
            <h1>QR Cards <em><?= count($students) ?></em></h1>
            <p>Permanent QR per student — encodes Student ID. Print, cut, distribute. Scanned at kiosk or teacher lens.</p>
        </div>
        <div class="page-intro-stats">
            <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></div><div><strong><?= count($students) ?></strong><span>Cards</span></div></div>
            <div class="mini-stat"><div class="ms-icon" style="background: var(--info-light); color: var(--info);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c6 3 10 0 12-2v-5"/></svg></div><div><strong><?= count($sections) ?></strong><span>Sections</span></div></div>
        </div>
    </div>

    <div class="filter-pillbar no-print">
        <form method="GET" class="fp-search" style="flex:1;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            <select name="section_id" class="form-control" style="width:auto; max-width:200px; padding:0.45rem 1.9rem 0.45rem 0.9rem; border-radius:999px; font-size:0.82rem;" onchange="this.form.submit()">
                <option value="">All sections</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= $sectionId === (int)$sec['id'] ? 'selected' : '' ?>>
                        <?= h($sec['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($sectionId): ?><a href="/admin/qr-codes.php" class="btn btn-sm btn-outline" style="border-radius:999px;">Clear</a><?php endif; ?>
            <span class="text-xs text-muted nowrap" style="margin-left:0.5rem;"><?= count($students) ?> student<?= count($students)===1?'':'s' ?></span>
        </form>
        <div class="fp-actions">
            <button onclick="window.print()" class="btn btn-primary btn-sm">⎙ Print All</button>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><?= count($students) ?> Student<?= count($students) !== 1 ? 's' : '' ?></h3>
        </div>
        <div class="card-body">
            <?php if (empty($students)): ?>
                <div class="empty-state"><h3>No students found</h3><p>Add students first, then generate their QR cards.</p></div>
            <?php else: ?>
                <p class="no-print" style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem;">
                    Each QR code encodes the student's ID number. Print, cut, and distribute to students.
                    They scan this at the attendance kiosk.
                </p>
                <div class="qr-grid">
                    <?php foreach ($students as $s): ?>
                        <div class="qr-card-item">
                            <div id="qrcode-<?= $s['id'] ?>" class="qr-code-canvas" data-student-id="<?= h($s['student_id']) ?>"></div>
                            <div class="student-name"><?= h($s['first_name'] . ' ' . $s['last_name']) ?></div>
                            <div class="student-id"><?= h($s['student_id']) ?></div>
                            <div class="student-id"><?= h($s['grade_level'] ?: '') ?> <?= h($s['section_name'] ?? '') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const containers = document.querySelectorAll('.qr-code-canvas');
        containers.forEach(function(container) {
            const studentId = container.dataset.studentId;
            new QRCode(container, {
                text: studentId,
                width: 130,
                height: 130,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        });
    });
    </script>
<?php endif; ?>

<style>
    @media print {
        .sidebar, .page-header, .action-bar, .no-print, .btn { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }
        .qr-grid { grid-template-columns: repeat(4, 1fr); }
        .qr-card-item { break-inside: avoid; border: 1px solid #ccc; }
        .qr-print-card { box-shadow: none; border: none; }
        #qrcode { border: 1px solid #ccc; }
    }
</style>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
?>
