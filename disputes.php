<?php
$page_title = 'Disputes';
require __DIR__ . '/includes/header.php';
require_login();
require_once __DIR__ . '/includes/disputes.php';

$actor = current_user_id();
$staff = is_trust_staff($pdo,$actor);
$filters = [];
foreach (['status','worker','employer','job','from','to'] as $field) {
    $filters[$field] = is_string($_GET[$field] ?? null) ? mb_substr(trim($_GET[$field]),0,150) : '';
}
$status = $filters['status'] ?: ($staff ? 'active' : 'all');
$where = [];
$args = [];
if (!$staff) {
    $where[] = '(a.youth_id=? OR j.employer_id=?)';
    array_push($args,$actor,$actor);
}
if ($staff && staff_role($pdo,$actor) === 'dispute_officer') {
    $where[] = 'd.assigned_to=?'; $args[] = $actor;
}
if ($status === 'active') $where[] = "d.status IN ('open','reviewing')";
if (in_array($status,['open','reviewing','resolved','rejected'],true)) {
    $where[] = 'd.status=?';
    $args[] = $status;
}
foreach (['worker'=>'w.username','employer'=>'em.username','job'=>'j.title'] as $key=>$column) {
    if ($filters[$key] === '') continue;
    $where[] = "LOCATE(?, $column)>0";
    $args[] = $filters[$key];
}
foreach (['from'=>'>=', 'to'=>'<='] as $field=>$operator) {
    $date = $filters[$field];
    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date,$parts)
        && checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])) {
        $where[] = "DATE(d.created_at)$operator?";
        $args[] = $date;
    }
}
$source = ' FROM disputes d JOIN applications a ON a.id=d.application_id
    JOIN jobs j ON j.id=a.job_id JOIN users w ON w.id=a.youth_id JOIN users em ON em.id=j.employer_id';
if ($where) $source .= ' WHERE ' . implode(' AND ',$where);
$count = workflow_row($pdo,'SELECT COUNT(*) AS total' . $source,$args);
$total = (int)$count['total'];
$page = min(max(1,(int)($_GET['p'] ?? 1)),max(1,(int)ceil($total / 25)));
$offset = ($page - 1) * 25;
$stmt = $pdo->prepare("SELECT d.id,d.status,d.payment_id,d.created_at,j.title,w.username AS worker,
    em.username AS employer $source ORDER BY d.created_at DESC,d.id DESC LIMIT 25 OFFSET $offset");
$stmt->execute($args);
?>
<header class="section-header">
    <div><h1><?= $staff ? 'Dispute queue' : 'My disputes' ?></h1><p class="muted"><?= $total ?> cases</p></div>
    <?php if ($staff): ?><a class="btn btn-outline" href="/giglink/admin/">Admin dashboard</a><?php endif; ?>
</header>
<form method="get" class="card job-filters">
    <label>Status
        <select name="status">
            <?php foreach (['active'=>'Open and reviewing','open'=>'Open','reviewing'=>'Reviewing',
                'resolved'=>'Resolved','rejected'=>'Rejected','all'=>'All statuses'] as $key=>$label): ?>
                <option value="<?= e($key) ?>" <?= $key === $status ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Worker username <input name="worker" value="<?= e($filters['worker']) ?>" maxlength="150"></label>
    <label>Employer username <input name="employer" value="<?= e($filters['employer']) ?>" maxlength="150"></label>
    <label>Job title <input name="job" value="<?= e($filters['job']) ?>" maxlength="150"></label>
    <label>Opened from <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label>Opened through <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <div class="row-actions job-search">
        <button class="btn">Filter cases</button>
        <a class="btn btn-ghost" href="/giglink/disputes.php">Clear filters</a>
    </div>
</form>
<?php if (!$total): ?><div class="card"><h2>No disputes match these filters</h2></div><?php endif; ?>
<div class="workflow-grid">
    <?php foreach ($stmt as $case): ?>
        <article class="card workflow-card">
            <header class="workflow-title"><h2><?= e($case['title']) ?></h2>
                <span class="badge badge-primary"><?= e(ucfirst($case['status'])) ?></span>
            </header>
            <p><?= $case['payment_id'] ? 'Payment' : 'Work' ?> dispute #<?= (int)$case['id'] ?>
                · <?= e(substr($case['created_at'],0,10)) ?></p>
            <p class="muted">@<?= e($case['worker']) ?> · @<?= e($case['employer']) ?></p>
            <a class="btn" href="/giglink/dispute.php?id=<?= (int)$case['id'] ?>">View case</a>
        </article>
    <?php endforeach; ?>
</div>
<?= paginate($total,25,$page,'/giglink/disputes.php?' . http_build_query(array_filter($filters) + ['status'=>$status])) ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

