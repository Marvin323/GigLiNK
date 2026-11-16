<?php
$page_title = 'Find workers';
require __DIR__ . '/includes/header.php';

$kw  = trim($_GET['q'] ?? '');
$loc = (int)($_GET['location'] ?? 0);

$sql = "SELECT * FROM v_worker_summary WHERE 1=1";
$args = [];
if ($kw !== '') { $sql .= " AND (full_name LIKE ? OR skills LIKE ? OR bio LIKE ?)"; $args[]="%$kw%"; $args[]="%$kw%"; $args[]="%$kw%"; }
if ($loc)       { $sql .= " AND location_name = (SELECT name FROM locations WHERE id = ?)"; $args[] = $loc; }
$sql .= " ORDER BY verified_badge DESC, avg_rating DESC, jobs_completed DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$workers = $stmt->fetchAll();

$locs = fetch_options($pdo, 'locations');
?>

<div class="section-header">
    <h1>Find workers</h1>
    <span class="muted"><?= count($workers) ?> result<?= count($workers)===1?'':'s' ?></span>
</div>

<p class="muted"><?= icon('verified') ?> Experienced means at least five completed gigs and an average employer rating of at least 4 out of 5.</p>

<div class="filters">
    <form method="get">
        <input type="text" name="q" placeholder="Search skill, name, or bio…" value="<?= e($kw) ?>">
        <select name="location">
            <option value="0">Any location</option>
            <?php foreach ($locs as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= $loc===(int)$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm"><?= icon('filter_alt') ?> Filter</button>
        <a href="workers.php" class="btn btn-sm btn-ghost">Reset</a>
    </form>
</div>

<?php if (!$workers): ?>
    <div class="card center" style="padding:3rem">
        <p class="muted"><?= icon('search_off') ?> No workers match that filter.</p>
    </div>
<?php else: ?>
<div class="card-grid">
    <?php foreach ($workers as $w): ?>
        <article class="card">
            <div style="display:flex; gap:0.75rem; align-items:center; margin-bottom:0.75rem">
                <?= avatar($w['full_name'], '', $w['photo_path'] ?? null) ?>
                <div style="flex:1">
                    <h3 style="margin:0">
                        <a href="worker_view.php?id=<?= (int)$w['user_id'] ?>"><?= e($w['full_name']) ?></a>
                    </h3>
                    <div class="muted" style="font-size:0.8rem"><?= icon('location_on') ?> <?= e($w['location_name'] ?? '—') ?></div>
                </div>
                <?php if ($w['verified_badge']): ?>
                    <span class="badge badge-verified"><?= icon('verified') ?> Experienced</span>
                <?php endif; ?>
            </div>
            <p><?= stars($w['avg_rating'], $w['rating_count']) ?></p>
            <p style="font-size:0.88rem"><strong>Skills:</strong> <?= e($w['skills'] ?? '—') ?></p>
            <p class="muted"><?= e(mb_strimwidth($w['bio'] ?? '', 0, 120, '…')) ?></p>
            <div class="muted" style="display:flex; gap:0.75rem; padding-top:0.5rem; border-top:1px solid var(--border); margin-top:0.75rem; font-size:0.8rem">
                <span><?= icon('check_circle') ?> <?= (int)$w['jobs_completed'] ?> gigs</span>
                <span><?= icon('thumb_up') ?> <?= (int)$w['endorsement_count'] ?> endorsements</span>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
