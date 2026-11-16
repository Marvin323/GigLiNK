<?php
$page_title = 'Saved jobs';
require __DIR__ . '/../includes/header.php';
require_role('youth');

$rows = $pdo->prepare(
  "SELECT vp.*, sj.created_at AS saved_at
     FROM saved_jobs sj
     JOIN v_jobs_public vp ON vp.id = sj.job_id
    WHERE sj.user_id = ?
    ORDER BY sj.created_at DESC"
);
$rows->execute([current_user_id()]);
$rows = $rows->fetchAll();
?>

<h1><?= icon('bookmark') ?> Saved jobs</h1>

<?php if (!$rows): ?>
    <div class="card center" style="padding:3rem">
        <?= icon('bookmark_border') ?>
        <p class="muted">You haven't saved any jobs yet. Save interesting ones for later from <a href="/giglink/jobs.php">Browse jobs</a>.</p>
    </div>
<?php else: ?>
<div class="card-grid">
<?php foreach ($rows as $j): ?>
    <article class="card">
        <h3><a href="/giglink/job_view.php?id=<?= (int)$j['id'] ?>"><?= e($j['title']) ?></a></h3>
        <p class="muted">
            <?= icon('location_on') ?> <?= e($j['location_name'] ?? '—') ?> ·
            <?= icon('payments') ?> <?= money($j['pay_amount']) ?>
        </p>
        <p><?= e(mb_strimwidth($j['description'], 0, 140, '…')) ?></p>
        <p class="muted" style="font-size:0.78rem">Saved <?= e(substr($j['saved_at'],0,10)) ?></p>
    </article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
