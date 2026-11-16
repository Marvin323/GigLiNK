<?php
require_once __DIR__ . '/payments.php';

function attach_payment_history(PDO $pdo, array $applications): array
{
    if (!$applications) return [];
    $ids = array_column($applications, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare("SELECT p.*,d.id AS dispute_id,d.status AS dispute_status FROM v_application_payments p
        LEFT JOIN disputes d ON d.payment_id=p.id WHERE p.application_id IN ($marks) ORDER BY p.id DESC");
    $query->execute($ids);
    $byApplication = [];
    foreach ($query as $payment) $byApplication[$payment['application_id']][] = $payment;
    foreach ($applications as &$application) $application['payments'] = $byApplication[$application['id']] ?? [];
    return $applications;
}

function application_status(string $status): string
{
    return match ($status) {
        'pending' => 'Applied',
        'in_progress' => 'In progress',
        default => ucfirst($status),
    };
}

function work_timeline(array $app): void
{
    $steps = ['Applied' => $app['applied_at'], 'Accepted' => $app['accepted_at'],
        'In progress' => $app['started_at'], 'Completed' => $app['completed_at']];
    echo '<ol class="work-timeline" aria-label="Application timeline">';
    foreach ($steps as $label => $date) {
        echo '<li' . ($date ? ' class="reached"' : '') . '>' . e($label);
        echo $date ? '<small>' . e(substr($date, 0, 10)) . '</small>' : '<small>Pending</small>';
        echo '</li>';
    }
    echo '</ol>';
}

function application_button(array $app, string $route, string $label, array $fields = [],
    ?string $confirm = null, string $class = 'btn'): void
{
    ?>
    <form method="post" action="/giglink/actions/<?= e($route) ?>.php"
        <?php if ($confirm): ?> data-confirm="<?= e($confirm) ?>"<?php endif; ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
        <input type="hidden" name="job_id" value="<?= (int)$app['job_id'] ?>">
        <input type="hidden" name="state_version" value="<?= (int)$app['state_version'] ?>">
        <?php foreach ($fields as $key => $value): ?>
            <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
        <?php endforeach; ?>
        <button class="<?= e($class) ?>"><?= e($label) ?></button>
    </form>
    <?php
}

function payment_history(array $app, bool $worker): void
{
    if (!$app['payments']) {
        if ($app['status'] === 'completed') {
            echo '<p class="muted">' . ($app['paid_at'] ? 'Legacy payment record needs reconciliation.' : 'Payment outstanding.') . '</p>';
        }
        return;
    }
    foreach ($app['payments'] as $payment):
        $state = $payment['receipt_status'] ?? $payment['status'];
        ?>
        <div class="payment-record">
            <strong><?= money($payment['amount']) ?> · <?= e(receipt_label($payment['reconciled_at'] && $state === 'recorded' ? 'released' : $state)) ?></strong>
            <p class="muted"><?= e(ucfirst($payment['method'] ?? 'Method unlisted')) ?> · <?= e($payment['created_at']) ?>
                <?php if ($payment['reference']): ?> · Ref. <?= e($payment['reference']) ?><?php endif; ?>
                <?php if ($payment['receipt_at']): ?> · Updated <?= e($payment['receipt_at']) ?><?php endif; ?>
            </p>
            <?php if ($payment['dispute_reason']): ?><p><?= e($payment['dispute_reason']) ?></p><?php endif; ?>
            <?php if ($payment['dispute_id']): ?>
                <p><a href="/giglink/dispute.php?id=<?= (int)$payment['dispute_id'] ?>">View payment dispute · <?= e($payment['dispute_status']) ?></a></p>
            <?php endif; ?>
            <?php if ($payment['reconciled_at']): ?><p class="muted">Historical record linked by staff.</p><?php endif; ?>
            <?php if ($worker && $state === 'recorded' && !$payment['reconciled_at']): ?>
                <div class="row-actions">
                    <?php application_button($app, 'payment_receipt', 'Confirm receipt',
                        ['payment_id' => $payment['id'], 'state' => 'confirmed'], 'Confirm you received this payment?'); ?>
                    <details>
                        <summary>Dispute payment</summary>
                        <form method="post" action="/giglink/actions/payment_receipt.php" class="workflow-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                            <input type="hidden" name="state" value="disputed">
                            <label>Reason <textarea name="reason" required maxlength="500"></textarea></label>
                            <button class="btn btn-outline">Submit dispute</button>
                        </form>
                    </details>
                </div>
            <?php endif; ?>
        </div>
        <?php
    endforeach;
}

function review_form(array $app, int $ratee, bool $rated): void
{
    if ($rated) { echo '<p class="muted">Review submitted.</p>'; return; }
    ?>
    <details class="workflow-details">
        <summary>Leave a review</summary>
        <form method="post" action="/giglink/actions/rate_user.php" class="workflow-form">
            <?= csrf_field() ?>
            <input type="hidden" name="job_id" value="<?= (int)$app['job_id'] ?>">
            <input type="hidden" name="ratee_user_id" value="<?= $ratee ?>">
            <label>Rating
                <select name="score" required>
                    <option value="">Choose a rating</option>
                    <?php for ($score = 5; $score >= 1; --$score): ?>
                        <option value="<?= $score ?>"><?= $score ?> out of 5</option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Comment (optional) <input type="text" name="comment" maxlength="1000"></label>
            <button class="btn btn-outline">Submit review</button>
        </form>
    </details>
    <?php
}

