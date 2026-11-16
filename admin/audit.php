<?php
$page_title = 'Admin · Audit log';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/staff.php';
require_staff($pdo, 'audit');

$per = 50;
$page = max(1, (int)($_GET['p'] ?? 1));
$total = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
$rows = $pdo->query(
  "SELECT a.*, u.username
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.actor_id
    ORDER BY a.created_at DESC,a.id DESC
    LIMIT $per OFFSET " . (($page - 1) * $per)
)->fetchAll();
?>

<h1><?= icon('receipt_long') ?> Audit log</h1>

<table>
    <tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Meta</th><th>IP</th></tr>
    <?php foreach ($rows as $a): ?>
    <tr>
        <td class="muted" style="font-size:0.8rem; white-space:nowrap"><?= e($a['created_at']) ?></td>
        <td><?= $a['username'] ? '@' . e($a['username']) : '<span class="muted">—</span>' ?></td>
        <td><span class="badge badge-primary"><?= e($a['action']) ?></span></td>
        <td class="muted" style="font-size:0.82rem">
            <?= e($a['entity'] ?? '') ?><?= $a['entity_id'] ? '#'.(int)$a['entity_id'] : '' ?>
        </td>
        <td class="muted" style="font-size:0.78rem"><?= e(mb_strimwidth($a['meta'] ?? '', 0, 60, '…')) ?></td>
        <td class="muted" style="font-size:0.78rem"><?= e($a['ip'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?= paginate($total, $per, $page, '/giglink/admin/audit.php') ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
