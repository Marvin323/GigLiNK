<?php
$page_title = 'Applicants';
require __DIR__ . '/../includes/header.php';
require_role('employer');
require __DIR__ . '/../includes/work_cards.php';

$jobId = (int)($_GET['job_id'] ?? 0);
$job = workflow_row($pdo, 'SELECT * FROM jobs WHERE id=? AND employer_id=?', [$jobId, current_user_id()]);
if (!$job) {
    http_response_code(404);
    echo '<h1>Job not found</h1>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}
$query = $pdo->prepare("SELECT a.*, u.email, u.phone, u.photo_path, yp.full_name,
    ws.skills, ws.jobs_completed, ws.avg_rating, ws.rating_count
    FROM applications a JOIN users u ON u.id=a.youth_id
    JOIN youth_profiles yp ON yp.user_id=a.youth_id
    LEFT JOIN v_worker_summary ws ON ws.user_id=a.youth_id
    WHERE a.job_id=? ORDER BY FIELD(a.status,'pending','in_progress','accepted','disputed',
        'completed','rejected','withdrawn','cancelled'), a.applied_at, a.id");
$query->execute([$jobId]);
$apps = attach_payment_history($pdo, $query->fetchAll());
$occupied = count(array_filter($apps, fn($app) => in_array($app['status'],
    ['accepted','in_progress','completed','disputed'], true)));
$canAccept = $job['status'] === 'open' && $occupied < (int)$job['workers_needed'];
?>
<div class="section-header">
    <div>
        <a href="dashboard.php">Employer dashboard</a>
        <h1><?= e($job['title']) ?></h1>
        <p><?= $occupied ?> of <?= (int)$job['workers_needed'] ?> positions filled · <?= e(ucfirst($job['status'])) ?></p>
    </div>
</div>

<?php if (!$apps): ?>
    <div class="card"><h2>No applicants yet</h2><a href="/giglink/job_view.php?id=<?= $jobId ?>">View your listing to share it</a></div>
<?php endif; ?>

<div class="workflow-grid">
<?php foreach ($apps as $app): ?>
    <article class="card workflow-card" id="application-<?= (int)$app['id'] ?>">
        <header class="workflow-title">
            <?= avatar($app['full_name'], '', $app['photo_path']) ?>
            <div>
                <h2><a href="/giglink/worker_view.php?id=<?= (int)$app['youth_id'] ?>"><?= e($app['full_name']) ?></a></h2>
                <p class="muted"><?= (int)$app['jobs_completed'] ?> completed gigs · <?= stars($app['avg_rating'], (int)$app['rating_count']) ?></p>
            </div>
            <span class="badge badge-primary"><?= e(application_status($app['status'])) ?></span>
        </header>
        <?php if ($app['skills']): ?><p><?= e($app['skills']) ?></p><?php endif; ?>
        <?php if ($app['message']): ?><p><?= e($app['message']) ?></p><?php endif; ?>
        <?php if ($app['is_team_lead']): ?><p>Team lead</p><?php endif; ?>
        <?php work_timeline($app); ?>
        <?php if ($app['status'] === 'pending'): ?>
            <div class="row-actions">
                <?php if ($canAccept): ?>
                    <form method="post" action="/giglink/actions/accept_applicant.php" class="row-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="job_id" value="<?= $jobId ?>">
                        <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                        <label><input type="checkbox" name="is_team_lead" value="1"> Team lead</label>
                        <button class="btn">Accept</button>
                    </form>
                <?php else: ?><p class="muted">No hiring slots available.</p><?php endif; ?>
                <?php application_button($app, 'reject_applicant', 'Decline', [], 'Decline this application?', 'btn btn-outline'); ?>
            </div>
        <?php elseif (in_array($app['status'], ['accepted','in_progress','disputed','completed'], true)): ?>
            <p class="muted"><?= e($app['phone'] ?: $app['email']) ?> ·
                <a href="/giglink/message_thread.php?application_id=<?= (int)$app['id'] ?>">Message worker</a></p>
            <div class="row-actions">
                <?php if ($app['status'] === 'accepted'): ?>
                    <?php application_button($app, 'work_status', 'Start work', ['status'=>'in_progress']); ?>
                    <?php if (!$app['paid_at']): ?>
                        <?php application_button($app, 'cancel_acceptance', 'Cancel acceptance', [],
                            'Cancel this acceptance and release the slot?', 'btn btn-outline'); ?>
                    <?php endif; ?>
                <?php elseif ($app['status'] === 'in_progress'): ?>
                    <?php application_button($app, 'work_status', 'Mark work complete', ['status'=>'completed'],
                        'Confirm the work is complete?'); ?>
                    <?php application_button($app, 'work_status', 'Raise work dispute', ['status'=>'disputed'],
                        'Flag this gig as disputed?', 'btn btn-outline'); ?>
                <?php elseif ($app['status'] === 'disputed'): ?>
                    <a class="btn" href="/giglink/disputes.php">View work dispute</a>
                <?php endif; ?>
            </div>
            <?php payment_history($app, false); ?>
            <?php if ($app['status'] === 'completed'): ?>
                <?php if (!$app['paid_at'] && !array_filter($app['payments'], fn($p) => $p['receipt_status'] !== null)): ?>
                    <details class="workflow-details">
                        <summary>Record payment</summary>
                        <form method="post" action="/giglink/actions/mark_paid.php" class="workflow-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                            <input type="hidden" name="idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
                            <label>Amount (UGX)
                                <input type="number" name="amount" min="0.01" max="99999999.99" step="0.01"
                                    value="<?= $job['pay_type'] === 'fixed' ? e($job['pay_amount']) : '' ?>" required>
                            </label>
                            <label>Method
                                <select name="method">
                                    <option value="cash">Cash</option><option value="mpesa">M-Pesa</option>
                                    <option value="airtel">Airtel Money</option><option value="mtn">MTN MoMo</option>
                                    <option value="bank">Bank transfer</option>
                                </select>
                            </label>
                            <label>Reference (optional) <input name="reference" maxlength="80"></label>
                            <button class="btn">Record payment</button>
                        </form>
                    </details>
                <?php endif; ?>
                <?php review_form($app, (int)$app['youth_id'], (bool)$app['employer_rated_at']); ?>
            <?php endif; ?>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

