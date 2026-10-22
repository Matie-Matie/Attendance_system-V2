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
$filter = $_GET['filter'] ?? '';
$filterWhere = '';
$filterParams = [];
if ($filter === 'signins') {
    $filterWhere = "WHERE al.action IN ('login', 'logout', 'login_failed')";
}

$totalStmt = db()->prepare("SELECT COUNT(*) FROM audit_logs al $filterWhere");
$totalStmt->execute($filterParams);
$total = $totalStmt->fetchColumn();

$stmt = db()->prepare("
    SELECT al.*, u.full_name, u.username
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $filterWhere
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($filterParams, [$perPage, $offset]));
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
</div>

<div class="card overflow-hidden">
    <div class="card-header">
        <h3>Audit Trail</h3>
        <div class="flex gap-1" style="align-items:center;">
            <a href="/admin/audit-logs.php" class="fp-pill <?= $filter === '' ? 'active' : '' ?>">All</a>
            <a href="/admin/audit-logs.php?filter=signins" class="fp-pill <?= $filter === 'signins' ? 'active' : '' ?>">Sign-ins</a>
            <span class="text-muted" style="font-size:0.85rem;"><?=$total?> entries</span>
        </div>
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
                        <a href="?page=<?=$i?><?= $filter ? '&filter=' . urlencode($filter) : '' ?>" class="<?=$i===$page?'active':''?>"><?=$i?></a>
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
