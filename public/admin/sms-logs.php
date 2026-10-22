<?php
/**
 * Admin — Parent Notification Log (email)
 * Each parent gets at most 2 emails per student per school day:
 *   arrival  (first scheduled class) + dismissal (last scheduled class).
 * Emails send automatically in the background when the student scans in.
 * This page is for monitoring + retrying anything that failed.
 */
$pageTitle = 'Notifications';
$currentPage = 'sms-logs';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_pending') {
        $sent = notify_send_pending();
        flash('success', $sent === 0 ? 'No pending emails to send.' : $sent . ' email(s) sent.');
        redirect('/admin/sms-logs.php');
    }

    if ($action === 'sweep_absent') {
        $n = notify_sweep_absentees();
        flash('success', $n === 0
            ? 'No new absentees — every student with a finished first class is accounted for.'
            : $n . ' absentee email(s) sent to parents.');
        redirect('/admin/sms-logs.php');
    }

    if ($action === 'send_one' && isset($_POST['log_id'])) {
        $ok = notify_send((int) $_POST['log_id']);
        flash($ok ? 'success' : 'error', $ok ? 'Email sent.' : 'Could not send (already sent or no recipient).');
        redirect('/admin/sms-logs.php');
    }
}

$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ["sl.channel = 'email'"];
$params = [];
if ($status) { $where[] = "sl.status = ?"; $params[] = $status; }
if ($type)   { $where[] = "sl.type = ?";   $params[] = $type; }
$whereClause = 'WHERE ' . implode(' AND ', $where);

$pendingCount = (int) db()->query("SELECT COUNT(*) FROM sms_logs WHERE status = 'pending' AND channel = 'email'")->fetchColumn();
$sentToday    = (int) db()->query("SELECT COUNT(*) FROM sms_logs WHERE status = 'sent' AND channel = 'email' AND sms_date = CURDATE()")->fetchColumn();
$failedCount  = (int) db()->query("SELECT COUNT(*) FROM sms_logs WHERE status = 'failed' AND channel = 'email'")->fetchColumn();

$countStmt = db()->prepare("SELECT COUNT(*) FROM sms_logs sl $whereClause");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = db()->prepare("
    SELECT sl.*, st.first_name, st.last_name, st.student_id
    FROM sms_logs sl
    JOIN students st ON sl.student_id = st.id
    $whereClause
    ORDER BY sl.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$logs = $stmt->fetchAll();
$totalPages = (int) ceil($total / $perPage);

ob_start();
?>

<div class="page-intro">
    <div class="page-intro-main">
        <h1>Notifications</h1>
        <p>Parent emails — at most 2 per student per day: arrival (or absent) + dismissal. Queued automatically when they scan. Text-message (SMS) sending is optional and not turned on — this page sends email only.</p>
    </div>
</div>

<div class="filter-pillbar">
    <form method="GET" class="flex items-center gap-2" style="flex-wrap:wrap;">
        <select name="status" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="">All status</option>
            <option value="pending" <?=$status==='pending'?'selected':''?>>Pending</option>
            <option value="sent" <?=$status==='sent'?'selected':''?>>Sent</option>
            <option value="failed" <?=$status==='failed'?'selected':''?>>Failed</option>
        </select>
        <select name="type" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="">All types</option>
            <option value="morning_in" <?=$type==='morning_in'?'selected':''?>>Arrival</option>
            <option value="afternoon_out" <?=$type==='afternoon_out'?'selected':''?>>Dismissal</option>
            <option value="absent" <?=$type==='absent'?'selected':''?>>Absent</option>
            <option value="cutting" <?=$type==='cutting'?'selected':''?>>Cutting class</option>
        </select>
        <?php if ($status || $type): ?><a href="/admin/sms-logs.php" class="btn btn-sm btn-outline">Clear</a><?php endif; ?>
    </form>
    <?php if ($status || $type): ?>
        <div class="active-filters">
            <?php if ($status): ?><span class="fp-pill active"><?= h(ucfirst($status)) ?></span><?php endif; ?>
            <?php if ($type): ?><span class="fp-pill active"><?= h(ucwords(str_replace('_', ' ', $type))) ?></span><?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="actions flex items-center gap-2">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sweep_absent">
            <button type="submit" class="btn btn-outline" title="Email parents of students who missed their first class today (runs automatically too)">
                Check absentees now
            </button>
        </form>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_pending">
            <button type="submit" class="btn btn-primary" <?= $pendingCount === 0 ? 'disabled' : '' ?>>
                Send pending (<?= $pendingCount ?>)
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Parent notification emails</h3>
        <span class="text-muted" style="font-size:0.85rem;">
            <?= $total ?> total &middot; per student per day: arrival <em>or</em> absent, plus dismissal &middot; sent automatically
        </span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <h3>No notifications yet</h3>
                <p>Emails are queued automatically when a student scans in at their first or last class of the day. Make sure students have a parent email set.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Type</th><th>Student</th><th>Recipient</th><th>Message</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?= format_date($l['created_at'], 'M d, h:i A') ?></td>
                                <td>
                                    <?php
                                        $typeBadge = ['morning_in' => 'badge-info', 'afternoon_out' => 'badge-warning', 'absent' => 'badge-danger', 'cutting' => 'badge-danger'][$l['type']] ?? 'badge';
                                        $typeLabel = ['morning_in' => 'ARRIVAL', 'afternoon_out' => 'DISMISSAL', 'absent' => 'ABSENT', 'cutting' => 'CUTTING'][$l['type']] ?? strtoupper($l['type']);
                                    ?>
                                    <span class="badge <?= $typeBadge ?>"><?= $typeLabel ?></span>
                                </td>
                                <td><strong><?= h($l['first_name'].' '.$l['last_name']) ?></strong><br><code style="font-size:0.75rem;"><?= h($l['student_id']) ?></code></td>
                                <td style="font-size:0.8rem;"><code><?= h($l['email_to']) ?: '—' ?></code></td>
                                <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.85rem;" title="<?= h($l['message']) ?>"><?= h($l['message']) ?></td>
                                <td>
                                    <span class="badge <?= $l['status']==='sent'?'badge-success':($l['status']==='failed'?'badge-danger':'badge-warning') ?>">
                                        <?= strtoupper($l['status']) ?><?= $l['sent_at'] && $l['status'] === 'sent' ? ' ' . date('h:i A', strtotime($l['sent_at'])) : '' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($l['status'] !== 'sent'): ?>
                                        <form method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="send_one">
                                            <input type="hidden" name="log_id" value="<?= $l['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline"><?= $l['status'] === 'failed' ? 'Retry' : 'Send' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i=1;$i<=$totalPages;$i++): ?>
                        <a href="?page=<?=$i?>&status=<?=h($status)?>&type=<?=h($type)?>" class="<?=$i===$page?'active':''?>"><?=$i?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$slot = ob_get_clean();
include __DIR__ . '/../../includes/layouts/admin-layout.php';
?>
