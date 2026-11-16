<?php
$page_title = 'Trust and payment history';
require __DIR__ . '/includes/header.php';
require_login();
require_once __DIR__ . '/includes/payments.php';

$actor = current_user_id();
$worker = current_role() === 'youth';
if (!$worker && current_role() !== 'employer') { http_response_code(403); exit; }
$scope = $worker ? 'p.youth_id' : 'p.employer_id';
$stats = workflow_row($pdo,"SELECT COUNT(*) AS total,
    COUNT(CASE WHEN p.receipt_status='confirmed' THEN 1 END) AS confirmed
    FROM v_application_payments p WHERE $scope=?",[$actor]);
if ($worker) {
    $profile = workflow_row($pdo,'SELECT jobs_completed,rating_count,avg_rating FROM v_worker_summary WHERE user_id=?',[$actor]);
} else {
    $rounds = workflow_row($pdo,"SELECT COUNT(*) AS total FROM jobs j WHERE j.employer_id=?
        AND j.status IN ('closed','completed') AND EXISTS
            (SELECT 1 FROM applications a WHERE a.job_id=j.id AND a.status='completed')
        AND NOT EXISTS (SELECT 1 FROM applications a WHERE a.job_id=j.id AND a.status IN (" . ACTIVE_WORK_STATES . "))",[$actor]);
    $unresolved = workflow_row($pdo,"SELECT COUNT(*) AS total FROM disputes d
        JOIN applications a ON a.id=d.application_id JOIN jobs j ON j.id=a.job_id
        WHERE j.employer_id=? AND d.payment_id IS NOT NULL AND d.status IN ('open','reviewing')",[$actor]);
    $due = workflow_row($pdo,"SELECT COUNT(*) AS total FROM applications a JOIN jobs j ON j.id=a.job_id
        WHERE j.employer_id=? AND a.status='completed' AND a.paid_at IS NULL AND NOT EXISTS
          (SELECT 1 FROM v_application_payments p WHERE p.application_id=a.id AND p.receipt_status IS NOT NULL)",[$actor]);
}
$page = min(max(1,(int)($_GET['p'] ?? 1)),max(1,(int)ceil($stats['total'] / 25)));
$offset = ($page - 1) * 25;
$history = $pdo->prepare("SELECT p.*,j.title,d.id AS dispute_id,d.status AS dispute_status
    FROM v_application_payments p JOIN jobs j ON j.id=p.job_id LEFT JOIN disputes d ON d.payment_id=p.id
    WHERE $scope=? ORDER BY p.created_at DESC,p.id DESC LIMIT 25 OFFSET $offset");
$history->execute([$actor]);
?>
<header class="section-header">
    <div><h1>Trust and payment history</h1><p class="muted">Private account history</p></div>
    <div class="row-actions">
        <a class="btn btn-outline" href="/giglink/<?= $worker ? 'youth' : 'employer' ?>/dashboard.php">Dashboard</a>
        <a class="btn btn-outline" href="/giglink/disputes.php">My disputes</a>
    </div>
</header>
<section class="card workflow-card">
    <?php if ($worker): ?>
        <h2>Work reputation</h2>
        <p><?= (int)$profile['jobs_completed'] ?> completed gigs · <?= (int)$profile['rating_count'] ?> eligible employer reviews</p>
        <p>Average eligible rating <?= $profile['rating_count'] ? e($profile['avg_rating']) . '/5' : 'Unavailable' ?></p>
        <p><a href="/giglink/profile_qr.php?id=<?= $actor ?>#endorsements">View public endorsements on your Work ID</a></p>
    <?php else: ?>
        <h2>Hiring and obligations</h2>
        <p><?= (int)$rounds['total'] ?> completed hiring rounds · <?= (int)$due['total'] ?> completed gigs with no payment recorded</p>
        <p><?= (int)$unresolved['total'] ?> open or reviewing payment disputes</p>
    <?php endif; ?>
    <p><?= (int)$stats['total'] ?> payment records · <?= (int)$stats['confirmed'] ?> confirmed by workers</p>
</section>
<h2>Payment records</h2>
<?php if (!$stats['total']): ?><p>No payment records are available yet.</p><?php endif; ?>
<div class="workflow-grid">
    <?php foreach ($history as $payment):
        $state = $payment['receipt_status'] ?? $payment['status'];
        if ($payment['reconciled_at'] && $state === 'recorded') $state = 'released';
    ?>
        <article class="card workflow-card">
            <h3><?= e($payment['title']) ?></h3>
            <p><?= money($payment['amount']) ?> · <?= e(receipt_label($state)) ?></p>
            <p class="muted"><?= e($payment['created_at']) ?>
                <?php if ($payment['receipt_at']): ?> · Receipt updated <?= e($payment['receipt_at']) ?><?php endif; ?>
                <?php if ($payment['reconciled_at']): ?> · Historical association reviewed by staff<?php endif; ?></p>
            <?php if ($payment['dispute_id']): ?>
                <a href="/giglink/dispute.php?id=<?= (int)$payment['dispute_id'] ?>">View dispute · <?= e($payment['dispute_status']) ?></a>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
<?= paginate((int)$stats['total'],25,$page,'/giglink/trust.php') ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

