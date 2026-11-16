<?php
$page_title = 'Employer dashboard';
require __DIR__ . '/../includes/header.php';
require_role('employer');
require __DIR__ . '/../includes/workflow.php';

$uid = current_user_id();
$me = workflow_row($pdo, 'SELECT * FROM v_employer_summary WHERE user_id=?', [$uid]);
if (!$me) { http_response_code(403); exit; }
$query = $pdo->prepare("SELECT j.*,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id AND a.status IN (" . OCCUPIED_STATES . ")) AS occupied,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id AND a.status='accepted') AS upcoming,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id AND a.status='in_progress') AS in_progress,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id AND a.status='disputed') AS disputed,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id AND a.status='pending') AS pending,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id AND a.status='completed'
        AND a.paid_at IS NULL AND NOT EXISTS (SELECT 1 FROM v_application_payments p
            WHERE p.application_id=a.id AND p.receipt_status IS NOT NULL)) AS due,
    (SELECT COUNT(*) FROM v_application_payments p WHERE p.job_id=j.id AND p.receipt_status='recorded' AND p.reconciled_at IS NULL) AS awaiting_receipt,
    (SELECT COUNT(*) FROM disputes d JOIN applications a ON a.id=d.application_id WHERE a.job_id=j.id AND d.payment_id IS NOT NULL AND d.status IN ('open','reviewing')) AS payment_disputes,
    (SELECT id FROM jobs renewal WHERE renewal.renewed_from_id=j.id) AS renewed_job
    FROM jobs j WHERE j.employer_id=? ORDER BY j.created_at DESC, j.id DESC");
$query->execute([$uid]);
$jobs = $query->fetchAll();
$groups = ['Needs attention' => [], 'Active jobs' => [], 'Recently closed or completed' => []];
foreach ($jobs as $job) {
    $open = $job['status'] === 'open' && $job['occupied'] < $job['workers_needed'];
    $job['can_review'] = $open && $job['pending'];
    $job['action'] = match (true) {
        (bool)$job['disputed'] => 'Review work dispute',
        (bool)$job['payment_disputes'] => 'Review payment dispute',
        (bool)$job['due'] => 'Record payment',
        (bool)$job['in_progress'] => 'Manage work in progress',
        (bool)$job['can_review'] => 'Review applicants',
        (bool)$job['upcoming'] => 'View upcoming gigs',
        (bool)$job['awaiting_receipt'] => 'View payment status',
        default => 'View hiring record',
    };
    $group = match (true) {
        $job['action'] !== 'View hiring record' => 'Needs attention',
        in_array($job['status'], ['open','filled'], true) => 'Active jobs',
        default => 'Recently closed or completed',
    };
    $groups[$group][] = $job;
}
?>
<header class="section-header">
    <div class="workflow-title">
        <?= avatar($me['business_name'], '', $me['photo_path']) ?>
        <div><h1><?= e($me['business_name']) ?></h1><p class="muted">Employer dashboard</p></div>
    </div>
    <div class="row-actions">
        <a class="btn btn-outline" href="/giglink/trust.php">Trust and payment history</a>
        <a class="btn btn-outline" href="/giglink/disputes.php">My disputes</a>
        <a class="btn" href="post_job.php">Post a job</a>
        <a class="btn btn-outline" href="find_workers.php">Find workers</a>
    </div>
</header>
<?php if (!$jobs): ?>
    <section class="card"><h2>Your jobs will appear here</h2><a href="post_job.php">Post your first job</a></section>
<?php endif; ?>
<?php foreach ($groups as $title => $items): if (!$items) continue; ?>
<section>
    <h2><?= e($title) ?></h2>
    <div class="workflow-grid">
    <?php foreach ($items as $job):
        $id = (int)$job['id'];
        $active = in_array($job['status'], ['open','filled'], true);
        ?>
        <article class="card workflow-card">
            <header class="workflow-title">
                <div>
                    <h3><?= e($job['title']) ?></h3>
                    <p><?= (int)$job['occupied'] ?> of <?= (int)$job['workers_needed'] ?> positions filled</p>
                </div>
                <span class="badge badge-primary"><?= e(ucfirst($job['status'])) ?></span>
            </header>
            <ul class="workflow-counts">
                <?php if ($job['pending'] && $active): ?><li><?= (int)$job['pending'] ?> applications awaiting a decision</li><?php endif; ?>
                <?php if ($job['upcoming']): ?><li><?= (int)$job['upcoming'] ?> upcoming gigs<?= $job['start_date'] ? ' · Starts ' . e($job['start_date']) : '' ?></li><?php endif; ?>
                <?php if ($job['in_progress']): ?><li><?= (int)$job['in_progress'] ?> in progress</li><?php endif; ?>
                <?php if ($job['disputed']): ?><li><?= (int)$job['disputed'] ?> work disputes</li><?php endif; ?>
                <?php if ($job['due']): ?><li><?= (int)$job['due'] ?> completed gigs awaiting payment</li><?php endif; ?>
                <?php if ($job['awaiting_receipt']): ?><li><?= (int)$job['awaiting_receipt'] ?> payments awaiting worker confirmation</li><?php endif; ?>
                <?php if ($job['payment_disputes']): ?><li><?= (int)$job['payment_disputes'] ?> payment disputes</li><?php endif; ?>
            </ul>
            <div class="row-actions">
                <a class="btn" href="<?= $job['disputed'] || $job['payment_disputes'] ? '/giglink/disputes.php?job=' . rawurlencode($job['title']) . '&amp;status=active' : 'applicants.php?job_id=' . $id ?>"><?= e($job['action']) ?></a>
                <a href="edit_job.php?id=<?= $id ?>">Edit job</a>
                <a href="/giglink/job_view.php?id=<?= $id ?>">View listing</a>
            </div>
            <details class="workflow-details">
                <summary>Job options</summary>
                <div class="row-actions">
                    <?php if ($active): ?>
                        <form method="post" action="/giglink/actions/close_job.php"
                            data-confirm="Close this listing to new applications? Accepted gigs will continue.">
                            <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= $id ?>">
                            <button class="btn btn-outline">Close hiring</button>
                        </form>
                    <?php elseif ($job['renewed_job']): ?>
                        <a href="applicants.php?job_id=<?= (int)$job['renewed_job'] ?>">View renewed listing</a>
                    <?php elseif (!$job['upcoming'] && !$job['in_progress'] && !$job['disputed']): ?>
                        <form method="post" action="/giglink/actions/renew_job.php">
                            <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= $id ?>">
                            <button class="btn btn-outline">Open new hiring round</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="/giglink/actions/clone_job.php">
                        <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= $id ?>">
                        <input type="hidden" name="creation_key" value="<?= bin2hex(random_bytes(16)) ?>">
                        <button class="btn btn-ghost">Copy listing</button>
                    </form>
                </div>
            </details>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>

