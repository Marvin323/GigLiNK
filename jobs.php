<?php
$page_title = 'Browse jobs';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/job_search.php';

$result = job_search($pdo, $_GET);
$filters = $result['filters'];
$categories = array_column(fetch_options($pdo, 'categories'), 'name', 'id');
$locations = array_column(fetch_options($pdo, 'locations'), 'name', 'id');
$applied = [];
if (current_role() === 'youth' && $result['jobs']) {
    $ids = array_column($result['jobs'], 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT job_id,id FROM applications WHERE youth_id=? AND job_id IN ($marks)");
    $stmt->execute([current_user_id(), ...$ids]);
    $applied = array_column($stmt->fetchAll(), 'id', 'job_id');
}
$sorts = ['soonest'=>'Soonest start', 'pay'=>'Highest listed pay'];
$pagingFilters = $filters + ($result['per'] !== 20 ? ['per' => $result['per']] : []);
$base = 'jobs.php?' . http_build_query($pagingFilters);
?>
<header class="section-header">
    <h1>Browse jobs</h1>
    <p class="muted"><?= $result['total'] ?> <?= $result['total'] === 1 ? 'listing' : 'listings' ?></p>
</header>

<form method="get" class="card job-filters" role="search">
    <label class="job-search">Search jobs
        <input type="search" name="q" maxlength="120" placeholder="Job title, employer or keyword" value="<?= e($filters['q'] ?? '') ?>">
    </label>
    <label>Category
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($filters['category'] ?? 0) === $id ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Location
        <select name="location">
            <option value="">All locations</option>
            <?php foreach ($locations as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($filters['location'] ?? 0) === $id ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Minimum pay (UGX)
        <input type="number" name="minpay" min="0" max="99999999" step="1" value="<?= e($filters['minpay'] ?? '') ?>">
    </label>
    <label>Sort by
        <select name="sort">
            <option value="">Newest</option>
            <?php foreach ($sorts as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($filters['sort'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="row-actions job-search">
        <label><input type="checkbox" name="today" value="1" <?= isset($filters['today']) ? 'checked' : '' ?>> Starts today</label>
        <button class="btn">Search jobs</button>
        <?php if ($filters): ?><a class="btn btn-ghost" href="jobs.php">Clear all filters</a><?php endif; ?>
    </div>
</form>

<?php if ($filters): ?>
    <ul class="filter-chips" aria-label="Active filters">
        <?php foreach ($filters as $key => $value):
            $label = match ($key) {
                'category' => $categories[$value] ?? 'Category',
                'location' => $locations[$value] ?? 'Location',
                'minpay' => money($value) . '+',
                'today' => 'Starts today',
                'sort' => $sorts[$value],
                default => $value,
            };
            $query = http_build_query(array_diff_key($pagingFilters, [$key => true]));
            ?>
            <li><a href="jobs.php<?= $query ? '?' . e($query) : '' ?>" aria-label="Remove <?= e($label) ?>"><?= e($label) ?> <span aria-hidden="true">×</span></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (!$result['jobs']): ?>
    <section class="card">
        <h2><?= $filters ? 'No jobs match these filters' : 'No current listings' ?></h2>
        <?php if ($filters): ?><a class="btn btn-outline" href="jobs.php">Browse all jobs</a><?php endif; ?>
    </section>
<?php endif; ?>

<div class="discovery-grid">
<?php foreach ($result['jobs'] as $job):
    $id = (int)$job['id'];
    $remaining = (int)$job['remaining_slots'];
    $mine = $applied[$id] ?? null;
    $url = $mine ? 'youth/dashboard.php#application-' . (int)$mine : 'job_view.php?id=' . $id;
    $cta = $mine ? 'View application' : 'View details';
    if (!$mine && $remaining && current_role() === 'youth') {
        $url .= '#apply';
        $cta = 'Apply now';
    }
    ?>
    <article class="card discovery-card">
        <div class="discovery-meta">
            <span class="badge badge-primary"><?= e($job['category_name'] ?? 'Uncategorized') ?></span>
            <span class="badge <?= $remaining ? 'badge-ok' : 'badge-closed' ?>"><?= $remaining ? 'Open' : 'Filled' ?></span>
        </div>
        <h2><?= e($job['title']) ?></h2>
        <p><?= e($job['business_name'] ?? 'Employer') ?></p>
        <p class="discovery-pay"><?= e(job_pay($job)) ?></p>
        <dl>
            <div><dt>Location</dt><dd><?= e($job['location_name'] ?? 'To be arranged') ?></dd></div>
            <div><dt>Starts</dt><dd><?= e($job['start_date'] ?? 'To be arranged') ?></dd></div>
            <div><dt>Slots open</dt><dd><?= $remaining ?> of <?= (int)$job['workers_needed'] ?></dd></div>
        </dl>
        <a class="btn <?= $remaining || $mine ? '' : 'btn-outline' ?>" href="<?= e($url) ?>"><?= e($cta) ?></a>
    </article>
<?php endforeach; ?>
</div>
<?= paginate($result['total'], $result['per'], $result['page'], rtrim($base, '?')) ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

