<?php
$page_title = 'My availability';
require __DIR__ . '/../includes/header.php';
require_role('youth');

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $start = $_POST['start_time'];
    $end   = $_POST['end_time'];
    $loc   = ($_POST['location_id'] ?? '') === '' ? null : (int)$_POST['location_id'];
    $notes = trim($_POST['notes'] ?? '');
    if (!$start || !$end || strtotime($end) <= strtotime($start)) {
        flash_set('err', 'End time must be after start time.');
    } else {
        $pdo->prepare(
          "INSERT INTO availability_windows (youth_id, start_time, end_time, location_id, notes)
           VALUES (?,?,?,?,?)"
        )->execute([$uid, $start, $end, $loc, $notes]);
        flash_set('ok', 'Availability added.');
    }
    redirect('availability.php');
}

$rows = $pdo->prepare(
  "SELECT aw.*, l.name AS location_name
     FROM availability_windows aw
     LEFT JOIN locations l ON l.id = aw.location_id
    WHERE aw.youth_id = ?
    ORDER BY aw.start_time DESC"
);
$rows->execute([$uid]);
$rows = $rows->fetchAll();

$locations = fetch_options($pdo, 'locations');
?>

<h1>My availability</h1>
<p class="muted">Post windows when you're free. Employers browsing “Find workers” see them.</p>

<form method="post" class="form">
    <?= csrf_field() ?>
    <div class="row inline">
        <div><label>From</label><input type="datetime-local" name="start_time" required></div>
        <div><label>Until</label><input type="datetime-local" name="end_time" required></div>
    </div>
    <div class="row">
        <label>Location</label>
        <select name="location_id">
            <option value="">— anywhere —</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="row">
        <label>Notes</label>
        <input type="text" name="notes" placeholder="e.g. Can do deliveries, cleaning, data entry">
    </div>
    <button class="btn">Add window</button>
</form>

<h2>Windows</h2>
<?php if (!$rows): ?>
    <p class="muted">None yet.</p>
<?php else: ?>
<table>
    <tr><th>From</th><th>To</th><th>Location</th><th>Notes</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><?= e($r['start_time']) ?></td>
        <td><?= e($r['end_time']) ?></td>
        <td><?= e($r['location_name'] ?? '—') ?></td>
        <td><?= e($r['notes'] ?? '') ?></td>
        <td>
            <form method="post" action="/giglink/actions/delete_availability.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
