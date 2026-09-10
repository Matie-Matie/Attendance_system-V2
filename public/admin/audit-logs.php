<?php
/**
 * Admin - Audit Logs
 */
$pageTitle = 'Audit Logs';
$currentPage = 'audit-logs';
$slot = '';

require_once __DIR__ . '/../../bootstrap.php';
require_role('admin');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$total = db()->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

$stmt = db()->prepare("
    SELECT al.*, u.full_name, u.username
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$perPage, $offset]);
$logs = $stmt->fetchAll();
$totalPages = ceil($total / $perPage);

ob_start();

$todayLogs = (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$weekLogs = (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
?>

<div class="page-intro">
    <div class="page-intro-main">
        <h1>Audit <em>Trail</em></h1>
        <p>Every create, update, and delete — who did what, when, and from where. Filter by page, not required here — it's all here.</p>
    </div>
    <div class="page-intro-stats">
        <div class="mini-stat"><div class="ms-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></div><div><strong><?= $total ?></strong><span>Total</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--success-light); color: var(--success);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div><strong><?= $todayLogs ?></strong><span>Today</span></div></div>
        <div class="mini-stat"><div class="ms-icon" style="background: var(--info-light); color: var(--info);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div><strong><?= $weekLogs ?></strong><span>7 days</span></div></div>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-header">
        <h3>Audit Trail</h3>
        <span class="text-muted" style="font-size:0.85rem;"><?=$total?> entries</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="empty-state"><h3>No audit logs yet</h3></div>
        <?php else: ?>
            <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
                <table class="table">
                    <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td style="white-space:nowrap;font-size:0.8rem;"><?= format_date($l['created_at'], 'M d, h:i A') ?></td>
                                <td><strong><?=h($l['full_name']?:'System')?></strong></td>
                                <td><span class="badge badge-info"><?=h($l['action'])?></span></td>
                                <td style="font-size:0.85rem;"><?=h($l['entity_type'])?: '—'?> #<?=$l['entity_id']?:''?></td>
                                <td style="font-size:0.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php if ($l['new_values']): $nv = json_decode($l['new_values'], true); echo h(json_encode($nv)); endif; ?>
                                </td>
                                <td><code style="font-size:0.75rem;"><?=h($l['ip_address'])?: '—'?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i=1;$i<=$totalPages;$i++): ?>
                        <a href="?page=<?=$i?>" class="<?=$i===$page?'active':''?>"><?=$i?></a>
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
