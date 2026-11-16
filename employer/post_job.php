<?php
$page_title = 'Post a job';
require __DIR__ . '/../includes/header.php';
require_role('employer');
require __DIR__ . '/../includes/workflow.php';
require __DIR__ . '/../includes/job_fields.php';
$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $fields = job_fields($_POST);
        $key = job_creation_key($_POST['creation_key'] ?? null);
        workflow_transaction($pdo, function () use ($pdo, $uid, $fields, $key) {
            $stmt = $pdo->prepare("INSERT INTO jobs (title, description, category_id, location_id, pay_amount,
                pay_type, workers_needed, start_date, end_date, employer_id, creation_key)
                VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $stmt->execute([...$fields, $uid, $key]);
            if ($stmt->rowCount() === 1) {
                audit($pdo, 'job.create', 'job', (int)$pdo->lastInsertId(), [], $uid);
            }
        });
        flash_set('ok', 'Job posted.');
        redirect('dashboard.php');
    } catch (Throwable $error) {
        flash_set('err', workflow_error($error));
        redirect('post_job.php');
    }
}

$cats = fetch_options($pdo, 'categories');
$locs = fetch_options($pdo, 'locations');
?>

<h1>Post a job</h1>
<form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="creation_key" value="<?= bin2hex(random_bytes(16)) ?>">
    <div class="row">
        <label>Title</label>
        <input type="text" name="title" required placeholder="e.g. Deep-clean 3-bedroom house">
    </div>
    <div class="row">
        <label>Description</label>
        <textarea name="description" required placeholder="What exactly needs doing? When? How long?"></textarea>
    </div>
    <div class="row inline">
        <div>
            <label>Category</label>
            <select name="category_id">
                <option value="0">— pick one —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Location</label>
            <select name="location_id">
                <option value="0">— pick town —</option>
                <?php foreach ($locs as $l): ?>
                    <option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="row inline">
        <div>
            <label>Pay (UGX)</label>
            <input type="number" step="0.01" min="0" max="99999999.99" name="pay_amount" placeholder="e.g. 2000">
        </div>
        <div>
            <label>Pay type</label>
            <select name="pay_type">
                <option value="fixed">Fixed total</option>
                <option value="hourly">Per hour</option>
                <option value="daily">Per day</option>
                <option value="negotiable">Negotiable</option>
            </select>
        </div>
        <div>
            <label>Workers needed</label>
            <input type="number" min="1" value="1" name="workers_needed">
            <small>Set &gt;1 for a group gig.</small>
        </div>
    </div>
    <div class="row inline">
        <div><label>Start date</label><input type="date" name="start_date"></div>
        <div><label>End date</label>  <input type="date" name="end_date"></div>
    </div>
    <button class="btn">Post job</button>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
