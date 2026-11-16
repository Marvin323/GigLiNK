<?php
$page_title = 'Edit job';
require __DIR__ . '/../includes/header.php';
require_role('employer');
require __DIR__ . '/../includes/workflow.php';
require __DIR__ . '/../includes/job_fields.php';
$uid = current_user_id();
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM jobs WHERE id=? AND employer_id=?");
$stmt->execute([$id, $uid]);
$job = $stmt->fetch();
if (!$job) { http_response_code(404); echo '<h1>Job not found</h1>'; require __DIR__.'/../includes/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $fields = job_fields($_POST);
        $capacity = $fields[6];
        workflow_transaction($pdo, function () use ($pdo, $id, $uid, $fields, $capacity) {
            $job = locked_job($pdo, $id);
            if ((int)$job['employer_id'] !== $uid) throw new DomainException('This job is unavailable.');
            if ($capacity < hiring_counts($pdo, $id)['occupied']) {
                throw new DomainException('Capacity cannot be lower than the number of occupied slots.');
            }
            $statement = $pdo->prepare("UPDATE jobs SET title=?, description=?, category_id=?, location_id=?,
                pay_amount=?, pay_type=?, workers_needed=?, start_date=?, end_date=? WHERE id=? AND employer_id=?");
            $statement->execute([...$fields, $id, $uid]);
            $job['workers_needed'] = $capacity;
            refresh_job_status($pdo, $job);
            if ($statement->rowCount()) audit($pdo, 'job.edit', 'job', $id);
        });
        flash_set('ok', 'Job updated.');
    } catch (Throwable $error) {
        flash_set('err', workflow_error($error));
        redirect('edit_job.php?id=' . $id);
    }
    redirect('dashboard.php');
}

$cats = fetch_options($pdo, 'categories');
$locs = fetch_options($pdo, 'locations');
?>

<h1>Edit job</h1>
<form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
    <div class="row"><label>Title</label>
        <input type="text" name="title" required value="<?= e($job['title']) ?>">
    </div>
    <div class="row"><label>Description</label>
        <textarea name="description" required><?= e($job['description']) ?></textarea>
    </div>
    <div class="row inline">
        <div>
            <label>Category</label>
            <select name="category_id">
                <option value="0">—</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$job['category_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Location</label>
            <select name="location_id">
                <option value="0">—</option>
                <?php foreach ($locs as $l): ?>
                    <option value="<?= (int)$l['id'] ?>" <?= (int)$job['location_id']===(int)$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="row inline">
        <div><label>Pay</label><input type="number" min="0" step="0.01" name="pay_amount" value="<?= e($job['pay_amount']) ?>"></div>
        <div>
            <label>Pay type</label>
            <select name="pay_type">
                <?php foreach (['fixed','hourly','daily','negotiable'] as $pt): ?>
                  <option value="<?= $pt ?>" <?= $job['pay_type']===$pt?'selected':'' ?>><?= $pt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Workers</label><input type="number" min="1" name="workers_needed" value="<?= (int)$job['workers_needed'] ?>"></div>
    </div>
    <div class="row inline">
        <div><label>Start</label><input type="date" name="start_date" value="<?= e($job['start_date']) ?>"></div>
        <div><label>End</label>  <input type="date" name="end_date"   value="<?= e($job['end_date']) ?>"></div>
    </div>
    <button class="btn">Save</button>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
