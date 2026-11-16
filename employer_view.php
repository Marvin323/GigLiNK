<?php
$page_title = 'Employer profile';
require __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM v_employer_summary WHERE user_id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) {
    http_response_code(404);
    echo '<h1>Employer not found</h1>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$jobs_open = $pdo->prepare(
  "SELECT * FROM v_jobs_public WHERE employer_id = ? AND status='open' ORDER BY created_at DESC"
);
$jobs_open->execute([$id]);
$jobs_open = $jobs_open->fetchAll();

$jobs_done = $pdo->prepare(
  "SELECT * FROM v_jobs_public WHERE employer_id = ? AND status='completed' ORDER BY created_at DESC LIMIT 10"
);
$jobs_done->execute([$id]);
$jobs_done = $jobs_done->fetchAll();

$reviews = $pdo->prepare(
  "SELECT r.score, r.comment, r.created_at,
          yp.full_name AS rater_name, u.id AS rater_id
     FROM ratings r
     JOIN users u            ON u.id = r.rater_user_id
     LEFT JOIN youth_profiles yp ON yp.user_id = u.id
    WHERE r.ratee_user_id = ?
    ORDER BY r.created_at DESC LIMIT 20"
);
$reviews->execute([$id]);
$reviews = $reviews->fetchAll();

$paid_stmt = $pdo->prepare(
  "SELECT IFNULL(SUM(amount),0) FROM payments WHERE employer_id = ? AND status IN ('released','held')"
);
$paid_stmt->execute([$id]);
$total_paid = (float)$paid_stmt->fetchColumn();
?>

<div class="profile-header">
    <?= avatar($emp['business_name'], 'lg', $emp['photo_path'] ?? null) ?>
    <div style="flex:1">
        <h1>
            <?= e($emp['business_name']) ?>
            <?php if ($emp['verified']): ?><span class="badge badge-verified"><?= icon('verified') ?> Verified</span><?php endif; ?>
        </h1>
        <div class="muted" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap">
            <span><?= icon('storefront') ?> <?= e($emp['business_type'] ?? '—') ?></span>
            <span><?= icon('location_on') ?> <?= e($emp['location_name'] ?? '—') ?></span>
            <?= stars($emp['avg_rating'], $emp['rating_count']) ?>
        </div>
    </div>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-icon"><?= icon('work') ?></div>
        <div><div class="n"><?= (int)$emp['jobs_posted'] ?></div><div class="l">Jobs posted</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('star') ?></div>
        <div>
            <div class="n"><?= $emp['avg_rating'] ? number_format((float)$emp['avg_rating'], 1) : '—' ?></div>
            <div class="l">Rating (<?= (int)$emp['rating_count'] ?> reviews)</div>
        </div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('bolt') ?></div>
        <div><div class="n"><?= count($jobs_open) ?></div><div class="l">Open gigs now</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('payments') ?></div>
        <div><div class="n" style="font-size:1.2rem"><?= money($total_paid) ?></div><div class="l">Paid to workers</div></div>
    </div>
</div>

<?php if ($emp['bio']): ?>
<div class="card">
    <h3><?= icon('info') ?> About</h3>
    <p><?= e($emp['bio']) ?></p>
</div>
<?php endif; ?>

<?php if ($jobs_open): ?>
<div class="section-header"><h2>Open gigs</h2></div>
<div class="card-grid">
    <?php foreach ($jobs_open as $j): ?>
        <article class="card">
            <h3><a href="job_view.php?id=<?= (int)$j['id'] ?>"><?= e($j['title']) ?></a></h3>
            <p class="muted">
                <?= icon('category') ?> <?= e($j['category_name'] ?? '—') ?> ·
                <?= icon('payments') ?> <?= money($j['pay_amount']) ?>
            </p>
            <p><?= e(mb_strimwidth($j['description'], 0, 140, '…')) ?></p>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-header"><h2>Reviews</h2></div>
<?php if (!$reviews): ?>
    <div class="card center" style="padding:2rem">
        <p class="muted"><?= icon('rate_review') ?> No reviews yet.</p>
    </div>
<?php else: ?>
<div class="card-grid">
<?php foreach ($reviews as $r): ?>
    <div class="card">
        <div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:0.4rem">
            <?= avatar($r['rater_name'] ?? '?', 'sm') ?>
            <div style="flex:1">
                <strong>
                    <?php if ($r['rater_name']): ?>
                        <a href="worker_view.php?id=<?= (int)$r['rater_id'] ?>"><?= e($r['rater_name']) ?></a>
                    <?php else: ?>
                        Worker
                    <?php endif; ?>
                </strong>
                <div class="muted" style="font-size:0.75rem"><?= e(substr($r['created_at'], 0, 10)) ?></div>
            </div>
            <span class="stars"><?= str_repeat('★', (int)$r['score']) . str_repeat('☆', 5 - (int)$r['score']) ?></span>
        </div>
        <?php if ($r['comment']): ?>
            <p style="margin:0">“<?= e($r['comment']) ?>”</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
