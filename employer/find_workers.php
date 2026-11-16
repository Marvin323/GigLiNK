<?php
// Reverse search — employers browse workers' POSTED availability windows,
// filtered by location and keyword. One of GigLink's differentiators.
$page_title = 'Find available workers';
require __DIR__ . '/../includes/header.php';
require_role('employer');

$loc = (int)($_GET['location'] ?? 0);
$kw  = trim($_GET['q'] ?? '');

$sql  = "SELECT * FROM v_worker_availability_active WHERE 1=1";
$args = [];
if ($loc) { $sql .= " AND location_name = (SELECT name FROM locations WHERE id = ?)"; $args[] = $loc; }
if ($kw !== '') { $sql .= " AND (skills LIKE ? OR notes LIKE ? OR full_name LIKE ?)";
                  $args[]="%$kw%"; $args[]="%$kw%"; $args[]="%$kw%"; }
$sql .= " ORDER BY verified_badge DESC, start_time";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$locations = fetch_options($pdo, 'locations');
?>

<h1>Find available workers</h1>
<p class="muted">Workers who have posted a time window when they can take gigs.</p>

<div class="filters">
    <form method="get">
        <input type="text" name="q" placeholder="Skill, name, or note…" value="<?= e($kw) ?>">
        <select name="location">
            <option value="0">Any location</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= $loc===(int)$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn">Filter</button>
        <a href="find_workers.php" class="btn btn-ghost">Reset</a>
    </form>
</div>

<?php if (!$rows): ?>
    <p class="muted">Nobody has posted availability matching that filter.</p>
<?php else: ?>
<div class="card-grid">
<?php foreach ($rows as $r): ?>
    <div class="card">
        <h3>
            <a href="/giglink/worker_view.php?id=<?= (int)$r['youth_id'] ?>"><?= e($r['full_name']) ?></a>
            <?php if ($r['verified_badge']): ?><span class="badge badge-verified">Experienced</span><?php endif; ?>
        </h3>
        <p class="muted">
            <?= e($r['location_name'] ?? '—') ?> ·
            <?= stars($r['avg_rating'], 0) ?>
        </p>
        <p><strong>Available:</strong> <?= e($r['start_time']) ?> → <?= e($r['end_time']) ?></p>
        <p><strong>Skills:</strong> <?= e($r['skills'] ?? '—') ?></p>
        <?php if ($r['notes']): ?><p><em><?= e($r['notes']) ?></em></p><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
