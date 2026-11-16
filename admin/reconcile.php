<?php
$page_title = 'Payment reconciliation';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/staff.php';
require_staff($pdo, 'finance.read');
require_once __DIR__ . '/../includes/reconciliation.php';

$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_staff($pdo,'finance.write');
    require_csrf();
    try {
        reconcile_payment($pdo,$id,(int)($_POST['application_id'] ?? 0),current_user_id(),
            is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '');
        flash_set('ok','Payment linked. The original payment record was preserved.');
    } catch (Throwable $error) {
        flash_set('err',workflow_error($error));
    }
    redirect('/giglink/admin/reconcile.php?id=' . $id);
}
$source = ' FROM payments p JOIN jobs j ON j.id=p.job_id
    JOIN users w ON w.id=p.youth_id JOIN users em ON em.id=p.employer_id
    LEFT JOIN payment_reconciliations r ON r.payment_id=p.id
    LEFT JOIN effective_reconciliations er ON er.payment_id=p.id';
if ($id) {
    $payment = workflow_row($pdo,'SELECT p.*,j.title,j.renewed_from_id,w.username AS worker,em.username AS employer,
        er.application_id AS linked_to,r.application_id AS original_link,r.reason,r.created_at AS linked_at,staff.username AS linked_by'
        . $source . ' LEFT JOIN users staff ON staff.id=r.actor_id WHERE p.id=? AND p.idempotency_key IS NULL',[$id]);
    if (!$payment) http_response_code(404);
} else {
    $where = ' WHERE p.application_id IS NULL AND p.idempotency_key IS NULL AND er.application_id IS NULL';
    $total = (int)$pdo->query('SELECT COUNT(*)' . $source . $where)->fetchColumn();
    $page = min(max(1,(int)($_GET['p'] ?? 1)),max(1,(int)ceil($total / 25)));
    $offset = ($page - 1) * 25;
    $unmatched = $pdo->query("SELECT p.id,p.amount,p.created_at,j.title,w.username AS worker,em.username AS employer
        $source $where ORDER BY p.created_at DESC,p.id DESC LIMIT 25 OFFSET $offset")->fetchAll();
}
?>
<header class="section-header">
    <div><a href="/giglink/admin/">Admin dashboard</a><h1>Payment reconciliation</h1></div>
    <?php if ($id): ?><a class="btn btn-outline" href="/giglink/admin/reconcile.php">Unmatched payments</a><?php endif; ?>
</header>
<?php if (!$id): ?>
    <p><?= $total ?> unmatched historical payment records.</p>
    <?php if (!$total): ?><div class="card"><h2>No unmatched payments</h2></div><?php endif; ?>
    <div class="workflow-grid">
        <?php foreach ($unmatched as $row): ?>
            <article class="card workflow-card">
                <h2><?= e($row['title']) ?></h2>
                <p>Payment #<?= (int)$row['id'] ?> · <?= money($row['amount']) ?></p>
                <p class="muted">@<?= e($row['worker']) ?> · @<?= e($row['employer']) ?> · <?= e($row['created_at']) ?></p>
                <a class="btn" href="?id=<?= (int)$row['id'] ?>">Review potential matches</a>
            </article>
        <?php endforeach; ?>
    </div>
    <?= paginate($total,25,$page,'/giglink/admin/reconcile.php') ?>
<?php elseif (!$payment): ?>
    <h2>Historical payment not found</h2>
<?php else: ?>
    <article class="card workflow-card">
        <h2>Payment #<?= $id ?> · <?= money($payment['amount']) ?></h2>
        <p>Original job #<?= (int)$payment['job_id'] ?> · <?= e($payment['title']) ?></p>
        <p>@<?= e($payment['worker']) ?> · @<?= e($payment['employer']) ?></p>
        <p><?= e($payment['created_at']) ?> · <?= e($payment['method'] ?? 'Method unlisted') ?>
            · <?= e(ucfirst($payment['status'])) ?></p>
        <?php if ($payment['reference']): ?><p>Reference <?= e($payment['reference']) ?></p><?php endif; ?>
    </article>
    <?php if ($payment['original_link']): ?>
        <section class="card">
            <h2><?= $payment['linked_to'] ? 'Linked to application #' . (int)$payment['linked_to'] : 'No effective application link' ?></h2>
            <p>Original association · application #<?= (int)$payment['original_link'] ?></p>
            <a class="btn btn-outline" href="corrections.php?payment=<?= $id ?>">View or request a correction</a>
            <p>Reviewed by @<?= e($payment['linked_by']) ?> · <?= e($payment['linked_at']) ?></p>
            <p><?= nl2br(e($payment['reason'])) ?></p>
        </section>
    <?php elseif ($payment['application_id']): ?>
        <p>This payment already has an application association.</p>
    <?php else: ?>
        <h2>Suggested applications</h2>
        <p class="muted">The score reflects available matching details. Verify the supporting records before choosing a match.</p>
        <?php $candidates = reconciliation_candidates($pdo,$payment); ?>
        <?php if (!$candidates): ?><p>No completed applications with the original parties were found.</p><?php endif; ?>
        <div class="workflow-grid">
            <?php foreach ($candidates as $candidate): ?>
                <article class="card workflow-card">
                    <h3><?= e($candidate['title']) ?></h3>
                    <p>Job #<?= (int)$candidate['job_id'] ?> · Application #<?= (int)$candidate['id'] ?></p>
                    <p><?= (int)$candidate['score'] ?>/100 match score · <?= e($candidate['completed_at'] ?? 'Completion date unavailable') ?></p>
                    <?php if ($candidate['pay_amount'] !== null): ?>
                        <p>Listed pay <?= money($candidate['pay_amount']) ?> · <?= e($candidate['pay_type']) ?></p>
                    <?php endif; ?>
                    <ul class="workflow-counts">
                        <?php foreach ($candidate['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?>
                    </ul>
                    <?php if ($candidate['already_linked']): ?>
                        <p class="muted">This application already has a payment record.</p>
                    <?php elseif (staff_can($pdo,current_user_id(),'finance.write')): ?>
                        <form method="post" class="workflow-form" data-confirm="Link this historical payment to the selected application?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="application_id" value="<?= (int)$candidate['id'] ?>">
                            <label>Reason for confirming this match <textarea name="reason" required maxlength="1500"></textarea></label>
                            <button class="btn">Confirm application match</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>

