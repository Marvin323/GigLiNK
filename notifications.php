<?php
$page_title = 'Notifications';
require __DIR__ . '/includes/header.php';
require_login();
$uid = current_user_id();

// Mark all read on view
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")
        ->execute([$uid]);
    redirect('/giglink/notifications.php');
}

$per = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$total = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?")->execute([$uid]) ?
         (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=$uid")->fetchColumn() : 0;

$rows = $pdo->prepare(
  "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$rows->bindValue(1, $uid, PDO::PARAM_INT);
$rows->bindValue(2, $per,  PDO::PARAM_INT);
$rows->bindValue(3, ($page - 1) * $per, PDO::PARAM_INT);
$rows->execute();
$rows = $rows->fetchAll();
?>

<div class="section-header">
    <h1><?= icon('notifications') ?> Notifications</h1>
    <?php if ($rows): ?>
    <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-outline"><?= icon('done_all') ?> Mark all read</button>
    </form>
    <?php endif; ?>
</div>

<?php if (!$rows): ?>
    <div class="card center" style="padding:3rem">
        <?= icon('notifications_off') ?>
        <p class="muted">You have no notifications yet.</p>
    </div>
<?php else: ?>
    <?php foreach ($rows as $n): ?>
        <?php $unread = empty($n['read_at']); ?>
        <div class="card <?= $unread ? '' : 'flat' ?>" style="display:flex; align-items:center; gap:0.75rem; padding:0.9rem 1.1rem">
            <div class="feature-icon" style="width:36px; height:36px; <?= $unread ? '' : 'opacity:0.5' ?>">
                <?= icon(match($n['kind']) {
                    'application_accepted' => 'check_circle',
                    'application_rejected' => 'cancel',
                    'application_withdrawn'=> 'reply',
                    'new_applicant'        => 'person_add',
                    'payment_received'     => 'payments',
                    'new_rating'           => 'star',
                    'new_message'          => 'chat',
                    'report'               => 'flag',
                    'account'              => 'security',
                    default                => 'notifications',
                }) ?>
            </div>
            <div style="flex:1">
                <?php if ($n['url']): ?>
                    <a href="<?= e($n['url']) ?>" style="color:var(--text); font-weight:<?= $unread ? 600 : 400 ?>"><?= e($n['title']) ?></a>
                <?php else: ?>
                    <span style="font-weight:<?= $unread ? 600 : 400 ?>"><?= e($n['title']) ?></span>
                <?php endif; ?>
                <div class="muted" style="font-size:0.78rem"><?= e($n['created_at']) ?></div>
            </div>
            <?php if ($unread): ?><span class="badge badge-primary">New</span><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?= paginate($total, $per, $page, '/giglink/notifications.php') ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
