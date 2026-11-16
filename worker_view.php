<?php
$page_title = 'Worker profile';
require __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM v_worker_summary WHERE user_id = ?");
$stmt->execute([$id]);
$w = $stmt->fetch();
if (!$w) { http_response_code(404); echo '<h1>Worker not found</h1>'; require __DIR__.'/includes/footer.php'; exit; }

$endorsements = $pdo->prepare(
  "SELECT * FROM endorsements WHERE endorsee_user_id = ? ORDER BY created_at DESC"
);
$endorsements->execute([$id]);
$endorsements = $endorsements->fetchAll();

$availability = $pdo->prepare(
  "SELECT aw.*, l.name AS location_name
     FROM availability_windows aw
     LEFT JOIN locations l ON l.id = aw.location_id
    WHERE aw.youth_id = ? AND aw.end_time >= NOW()
    ORDER BY aw.start_time"
);
$availability->execute([$id]);
$availability = $availability->fetchAll();

$recent_ratings = $pdo->prepare(
  "SELECT r.score, r.comment, r.created_at
     FROM ratings r
     JOIN jobs j ON j.id=r.job_id AND j.employer_id=r.rater_user_id
     JOIN applications a ON a.job_id=r.job_id AND a.youth_id=r.ratee_user_id AND a.status='completed'
    WHERE r.ratee_user_id=?
    ORDER BY r.created_at DESC, r.id DESC LIMIT 10"
);
$recent_ratings->execute([$id]);
$recent_ratings = $recent_ratings->fetchAll();
?>

<div style="display:flex; gap:1rem; align-items:center; margin-bottom:0.5rem">
    <?= avatar($w['full_name'], 'lg', $w['photo_path'] ?? null) ?>
    <h1 style="margin:0">
        <?= e($w['full_name']) ?>
        <?php if ($w['verified_badge']): ?><span class="badge badge-verified">Experienced</span><?php endif; ?>
    </h1>
</div>
<p class="muted">
    <?= e($w['location_name'] ?? '—') ?>
    <?php if (($age = age_from_dob($w['date_of_birth']))): ?> · age <?= (int)$age ?><?php endif; ?>
    · <?= e($w['education'] ?? '') ?>
</p>

<div class="card">
    <p><?= stars($w['avg_rating'], $w['rating_count']) ?></p>
    <p><strong>Skills:</strong> <?= e($w['skills'] ?? '—') ?></p>
    <p><?= e($w['bio'] ?? '') ?></p>
    <p class="muted">
        <?= (int)$w['jobs_completed'] ?> gigs completed ·
        <?= (int)$w['endorsement_count'] ?> endorsements ·
        <a href="profile_qr.php?id=<?= (int)$w['user_id'] ?>">Public QR profile</a>
    </p>
</div>

<?php if ($availability): ?>
<h2>Upcoming availability</h2>
<div class="card-grid">
<?php foreach ($availability as $a): ?>
    <div class="card">
        <p><strong><?= e($a['start_time']) ?></strong><br>→ <?= e($a['end_time']) ?></p>
        <p class="muted"><?= e($a['location_name'] ?? 'Any location') ?></p>
        <p><?= e($a['notes'] ?? '') ?></p>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h2>Endorsements</h2>
<?php if (!$endorsements): ?>
    <p class="muted">No endorsements yet.</p>
<?php else: ?>
<div class="card-grid">
<?php foreach ($endorsements as $en): ?>
    <div class="card">
        <p><strong><?= e($en['endorser_name']) ?></strong></p>
        <p class="muted"><?= e($en['endorser_role']) ?></p>
        <p>“<?= e($en['note']) ?>”</p>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (current_role() === 'employer'): ?>
    <h2>Endorse this worker</h2>
    <form method="post" action="actions/endorse_user.php" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="endorsee_user_id" value="<?= (int)$w['user_id'] ?>">
        <div class="row">
            <label>Short note</label>
            <textarea name="note" required placeholder="What was this worker like to work with?"></textarea>
        </div>
        <button class="btn">Add endorsement</button>
    </form>
<?php endif; ?>

<?php if ($recent_ratings): ?>
<h2>Recent ratings</h2>
<div class="card-grid">
<?php foreach ($recent_ratings as $r): ?>
    <div class="card">
        <p><?= str_repeat('★', (int)$r['score']) . str_repeat('☆', 5 - (int)$r['score']) ?></p>
        <p>“<?= e($r['comment'] ?? '') ?>”</p>
        <p class="muted"><?= e($r['created_at']) ?></p>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
