<?php
$page_title = 'My dashboard';
require __DIR__ . '/../includes/header.php';
require_role('youth');
require __DIR__ . '/../includes/work_cards.php';

$uid = current_user_id();
$me = workflow_row($pdo, "SELECT * FROM v_worker_summary WHERE user_id=?", [$uid]);
if (!$me) { http_response_code(403); exit; }
$query = $pdo->prepare("SELECT a.*, j.title, j.status AS job_status, j.start_date, j.employer_id,
    ep.business_name, u.phone, u.email FROM applications a JOIN jobs j ON j.id=a.job_id
    JOIN users u ON u.id=j.employer_id LEFT JOIN employer_profiles ep ON ep.user_id=j.employer_id
    WHERE a.youth_id=? ORDER BY j.start_date IS NULL, j.start_date, a.id DESC");
$query->execute([$uid]);
$applications = attach_payment_history($pdo, $query->fetchAll());
$groups = [
    'Upcoming accepted gigs' => [], 'Work in progress' => [], 'Completed work awaiting payment' => [],
    'Payments awaiting confirmation' => [], 'Applications awaiting a decision' => [],
    'Completed work' => [], 'Other applications' => [],
];
foreach ($applications as $app) {
    $needsReceipt = (bool)array_filter($app['payments'], fn($p) => $p['receipt_status'] === 'recorded' && !$p['reconciled_at']);
    $group = match ($app['status']) {
        'accepted' => 'Upcoming accepted gigs',
        'in_progress', 'disputed' => 'Work in progress',
        'completed' => $needsReceipt ? 'Payments awaiting confirmation' :
            (!$app['paid_at'] && !array_filter($app['payments'], fn($p) => $p['receipt_status'] !== null)
                ? 'Completed work awaiting payment' : 'Completed work'),
        'pending' => in_array($app['job_status'], ['open','filled'], true)
            ? 'Applications awaiting a decision' : 'Other applications',
        default => 'Other applications',
    };
    $groups[$group][] = $app;
}
?>
<header class="section-header">
    <div class="workflow-title">
        <?= avatar($me['full_name'], '', $me['photo_path']) ?>
        <div>
            <h1><?= e($me['full_name']) ?></h1>
            <p class="muted"><?= (int)$me['jobs_completed'] ?> completed gigs ·
                <?= stars($me['avg_rating'], (int)$me['rating_count']) ?></p>
        </div>
    </div>
    <div class="row-actions">
        <a class="btn btn-outline" href="/giglink/trust.php">Trust and payment history</a>
        <a class="btn btn-outline" href="/giglink/disputes.php">My disputes</a>
        <a class="btn" href="/giglink/jobs.php">Find jobs</a>
        <a class="btn btn-outline" href="/giglink/profile_qr.php?id=<?= $uid ?>">My work ID</a>
        <a class="btn btn-ghost" href="availability.php">Manage availability</a>
    </div>
</header>

<?php if (!$applications): ?>
    <section class="card"><h2>Your applications will appear here</h2><a href="/giglink/jobs.php">Find a gig to apply for</a></section>
<?php endif; ?>
<?php foreach ($groups as $title => $apps): if (!$apps) continue; ?>
<section>
    <h2><?= e($title) ?></h2>
    <div class="workflow-grid">
    <?php foreach ($apps as $app):
        $jobUrl = '/giglink/job_view.php?id=' . (int)$app['job_id'];
        $hasContact = in_array($app['status'], ['accepted','in_progress','disputed'], true);
        $hasReceiptAction = (bool)array_filter($app['payments'], fn($p) => $p['receipt_status'] === 'recorded' && !$p['reconciled_at']);
        ?>
        <article class="card workflow-card" id="application-<?= (int)$app['id'] ?>">
            <header class="workflow-title">
                <div>
                    <h3><?= e($app['title']) ?></h3>
                    <p class="muted"><?= e($app['business_name'] ?? 'Employer') ?>
                        <?php if ($app['start_date']): ?> · Starts <?= e($app['start_date']) ?><?php endif; ?>
                    </p>
                </div>
                <span class="badge badge-primary"><?= e(application_status($app['status'])) ?></span>
            </header>
            <?php work_timeline($app); ?>
            <?php if ($hasContact): ?>
                <p class="muted"><?= e($app['phone'] ?: $app['email']) ?> ·
                    <a href="/giglink/message_thread.php?application_id=<?= (int)$app['id'] ?>">Message employer</a></p>
            <?php endif; ?>
            <?php if ($app['status'] === 'pending' && !in_array($app['job_status'], ['open','filled'], true)): ?>
                <p class="muted">Hiring ended before a decision was recorded.</p>
            <?php endif; ?>
            <?php payment_history($app, true); ?>
            <div class="row-actions">
                <?php if ($app['status'] === 'accepted'): ?>
                    <?php application_button($app, 'work_status', 'Start work', ['status'=>'in_progress']); ?>
                    <a href="<?= e($jobUrl) ?>">View gig</a>
                <?php elseif ($app['status'] === 'disputed'): ?>
                    <a class="btn" href="/giglink/disputes.php">View work dispute</a>
                <?php elseif (!$hasReceiptAction): ?>
                    <a class="btn" href="<?= e($jobUrl) ?>"><?= $app['status'] === 'completed' ? 'View work record' : 'View gig' ?></a>
                <?php endif; ?>
                <?php if (in_array($app['status'], ['pending','accepted'], true) && !$app['paid_at']): ?>
                    <?php application_button($app, 'withdraw_application', 'Withdraw', [],
                        'Withdraw this application?', 'btn btn-outline'); ?>
                <?php elseif ($app['status'] === 'in_progress'): ?>
                    <?php application_button($app, 'work_status', 'Raise work dispute', ['status'=>'disputed'],
                        'Flag this gig as disputed?', 'btn btn-outline'); ?>
                <?php endif; ?>
            </div>
            <?php if ($app['status'] === 'completed'): ?>
                <?php review_form($app, (int)$app['employer_id'], (bool)$app['youth_rated_at']); ?>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>

