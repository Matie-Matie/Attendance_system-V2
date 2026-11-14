<?php
/**
 * ONE-SHOT TASK — delete unused sections requested by the school (Nov 2026).
 *
 * Requested sections:
 *   Grade 11 - Respect (HUMSS)
 *   Grade 11 - Empathy (HUMSS)
 *   Grade 12 - Honesty (HUMSS)
 *   Grade 12 - Obedience (HUMSS)
 *
 * Safe by design:
 *   - only sections with ZERO students and ZERO schedule entries are deleted
 *   - anything still in use is listed with its blocker and left untouched
 *   - the exact section rows are written to storage/backups/ before deleting
 *   - if the backup cannot be written, nothing is deleted
 *   - every deletion is written to the audit log
 *
 * DELETE THIS FILE after running it once.
 */
$pageTitle = 'Delete Unused Sections';
$currentPage = 'sections';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$keywords = ['Respect', 'Empathy', 'Honesty', 'Obedience'];

/**
 * Every Grade 11/12 section whose name contains one of the four keywords,
 * with the counts that decide whether it is safe to remove.
 */
function delete_once_candidates(): array
{
    global $keywords;

    $rows = db()->query(
        "SELECT id, name, grade_level, track_strand, status, created_at
           FROM sections
          WHERE grade_level IN ('Grade 11', 'Grade 12')
            AND (name LIKE '%HUMSS%' OR track_strand = 'HUMSS')
          ORDER BY grade_level, name"
    )->fetchAll();

    $countStudents = db()->prepare("SELECT COUNT(*) FROM students WHERE section_id = ?");
    $countActive   = db()->prepare("SELECT COUNT(*) FROM students WHERE section_id = ? AND status = 1");
    $countSched    = db()->prepare("SELECT COUNT(*) FROM schedule_entries WHERE section_id = ?");
    $countSessions = db()->prepare(
        "SELECT COUNT(*)
           FROM attendance_sessions a
           JOIN schedule_entries se ON a.schedule_entry_id = se.id
          WHERE se.section_id = ?"
    );

    $out = [];
    foreach ($rows as $row) {
        $matched = null;
        foreach ($keywords as $kw) {
            if (stripos($row['name'], $kw) !== false) {
                $matched = $kw;
                break;
            }
        }
        if ($matched === null) {
            continue;
        }

        $countStudents->execute([$row['id']]);
        $countActive->execute([$row['id']]);
        $countSched->execute([$row['id']]);
        $countSessions->execute([$row['id']]);

        $row['students'] = (int) $countStudents->fetchColumn();
        $row['active']   = (int) $countActive->fetchColumn();
        $row['scheds']   = (int) $countSched->fetchColumn();
        $row['sessions'] = (int) $countSessions->fetchColumn();
        $row['matched']  = $matched;
        $out[] = $row;
    }

    return $out;
}

function delete_once_blocker(array $c): ?string
{
    if ($c['students'] > 0) {
        $n = $c['students'];
        return $n . ' student' . ($n === 1 ? '' : 's') . ' still assigned';
    }
    if ($c['scheds'] > 0) {
        $n = $c['scheds'];
        return $n . ' class' . ($n === 1 ? '' : 'es') . ' still in the schedule';
    }
    return null;
}

$results = [];
$error = '';

// ---------------------------------------------------------------- POST: delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));

    if (($_POST['confirm'] ?? '') !== 'yes') {
        $error = 'Please tick the confirmation box.';
    } elseif (!$ids) {
        $error = 'No sections were selected.';
    } else {
        // Re-check everything at delete time — never trust the submitted form.
        $byId = [];
        foreach (delete_once_candidates() as $c) {
            $byId[(int) $c['id']] = $c;
        }

        $toDelete = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                $results[] = ['name' => "#$id", 'status' => 'skipped', 'note' => 'No longer matches the requested sections.'];
                continue;
            }
            $c = $byId[$id];
            $blocker = delete_once_blocker($c);
            if ($blocker !== null) {
                $results[] = ['name' => $c['name'], 'status' => 'blocked', 'note' => $blocker];
                continue;
            }
            $toDelete[] = $c;
        }

        if ($toDelete) {
            // Back up the exact rows first. No backup, no delete.
            $dir = BASE_PATH . '/storage/backups';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $file = $dir . '/deleted-sections-' . date('Ymd-His') . '.json';
            $payload = [
                'deleted_at' => date('c'),
                'deleted_by' => $_SESSION['user_name'] ?? ('user#' . ($_SESSION['user_id'] ?? '?')),
                'sections'   => array_map(function (array $c): array {
                    return [
                        'id'          => (int) $c['id'],
                        'name'        => $c['name'],
                        'grade_level' => $c['grade_level'],
                        'track_strand'=> $c['track_strand'],
                        'status'      => (int) $c['status'],
                        'created_at'  => $c['created_at'],
                    ];
                }, $toDelete),
                'restore_sql' => 'Re-insert these rows into `sections` to undo.',
            ];

            if (@file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
                $error = 'Could not write the backup file in storage/backups — nothing was deleted.';
                $toDelete = [];
            } else {
                $stmt = db()->prepare("DELETE FROM sections WHERE id = ?");
                foreach ($toDelete as $c) {
                    try {
                        $stmt->execute([(int) $c['id']]);
                        if ($stmt->rowCount() === 1) {
                            log_audit('delete', 'section', (int) $c['id'], [
                                'name'   => $c['name'],
                                'reason' => 'Unused section cleanup (one-shot tool)',
                            ]);
                            $results[] = ['name' => $c['name'], 'status' => 'deleted', 'note' => 'Removed'];
                        } else {
                            $results[] = ['name' => $c['name'], 'status' => 'failed', 'note' => 'Row not found — already deleted?'];
                        }
                    } catch (\Throwable $e) {
                        error_log('SCPLS delete-once: ' . $e->getMessage());
                        $results[] = ['name' => $c['name'], 'status' => 'failed', 'note' => 'Database refused: ' . $e->getMessage()];
                    }
                }
            }
        }
    }
}

$candidates = delete_once_candidates();
$safe       = array_values(array_filter($candidates, fn (array $c): bool => delete_once_blocker($c) === null));

ob_start();
?>

<div class="page-intro">
    <div class="page-intro-main">
        <h1>Delete Unused Sections</h1>
        <p>One-time cleanup requested by the school. Only sections with <strong>no students</strong> and
           <strong>no class schedule</strong> are removed — anything still in use is left alone.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><strong>Nothing deleted:</strong> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($results): ?>
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3>Result</h3></div>
        <div class="card-body p-0">
            <table class="table">
                <thead><tr><th>Section</th><th>Outcome</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><strong><?= h($r['name']) ?></strong></td>
                        <td>
                            <?php
                            $badge = $r['status'] === 'deleted' ? 'badge-success'
                                   : ($r['status'] === 'blocked' ? 'badge-warning' : 'badge-danger');
                            ?>
                            <span class="badge <?= $badge ?>"><?= h(ucfirst($r['status'])) ?></span>
                        </td>
                        <td><?= h($r['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!$candidates): ?>
    <div class="alert alert-warning">
        <strong>No matching sections found.</strong>
        Nothing on this system is named Grade 11/12 with Respect, Empathy, Honesty or Obedience.
        The section names on this PC may be spelled differently — check the Sections page.
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h3>Sections found</h3></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Section</th><th>Grade</th><th>Strand</th>
                            <th>Students</th><th>Class schedule</th><th>Attendance history</th><th>Verdict</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($candidates as $c): ?>
                        <?php $blocker = delete_once_blocker($c); ?>
                        <tr>
                            <td><strong><?= h($c['name']) ?></strong></td>
                            <td><span class="badge badge-info"><?= h($c['grade_level']) ?></span></td>
                            <td><?= h($c['track_strand'] ?: '—') ?></td>
                            <td><?= (int) $c['students'] ?></td>
                            <td><?= (int) $c['scheds'] ?></td>
                            <td><?= (int) $c['sessions'] ?></td>
                            <td>
                                <?php if ($blocker === null): ?>
                                    <span class="badge badge-success">Safe to delete</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Keep — <?= h($blocker) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($safe): ?>
        <form method="POST" onsubmit="return confirm('Delete <?= count($safe) ?> empty section(s)? This cannot be undone from the screen, but a backup is saved on this PC.');">
            <?= csrf_field() ?>
            <?php foreach ($safe as $c): ?>
                <input type="hidden" name="ids[]" value="<?= (int) $c['id'] ?>">
            <?php endforeach; ?>
            <div class="card">
                <div class="card-body">
                    <p style="margin-top:0;">
                        <strong><?= count($safe) ?></strong> empty section<?= count($safe) === 1 ? '' : 's' ?>
                        will be removed:
                        <?= h(implode(', ', array_column($safe, 'name'))) ?>.
                    </p>
                    <label style="display:flex;gap:0.5rem;align-items:flex-start;margin-bottom:1rem;">
                        <input type="checkbox" name="confirm" value="yes" required style="margin-top:0.25rem;">
                        <span>I understand these sections will be removed from the school records.</span>
                    </label>
                    <div class="form-actions" style="margin:0;">
                        <button type="submit" class="btn btn-primary">Delete these sections</button>
                        <a href="/admin/sections.php" class="btn btn-outline">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert-warning">
            <strong>Nothing can be deleted yet.</strong>
            All four sections still have students or classes attached. Remove or reassign those first,
            or archive the sections instead — the sections themselves are the link to their history.
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card" style="margin-top:1rem;border:1px solid #E8D9B0;background:#FFF9EC;">
    <div class="card-body">
        <strong>⚠️ Delete this file after use.</strong>
        <p style="margin:0.35rem 0 0;">Remove <code>public/admin/delete-sections-once.php</code> from this PC
           after the cleanup so the page no longer exists. A backup of the removed rows is saved in
           <code>storage/backups/</code>.</p>
    </div>
</div>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
