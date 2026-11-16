<?php
$page_title = 'Mobile Money sandbox';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/payment_attempts.php';
require_staff($pdo,'finance.read');

$actor = current_user_id();
$product = $_POST['product'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_staff($pdo,'finance.write');
    require_csrf();
    try {
        if (!is_string($product) || !staff_can($pdo,$actor,sandbox_permission($product))) {
            throw new DomainException('Sandbox product permission required.');
        }
        $amount = filter_var($_POST['amount'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
        if ($amount === false) throw new DomainException('Enter an amount in EUR cents.');
        $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
        $attempt = queue_mtn_case($pdo,configured_mtn_sandbox($product),$actor,$amount,$key);
        flash_set('ok','Isolated MTN sandbox case queued.');
        redirect('/giglink/admin/payment_queue.php?attempt=' . $attempt);
    } catch (Throwable $error) {
        flash_set('err',workflow_error($error));
    }
    redirect('/giglink/admin/mobile_money.php');
}

$health = array_column($pdo->query("SELECT *,opened_until>NOW(6) AS circuit_open FROM mobile_money_health")->fetchAll(),null,'provider');
$recent = $pdo->query("SELECT a.id,a.provider,a.state,a.diagnostic,s.operation,s.amount_minor,t.reference,t.status AS ledger_status,
    q.status AS check_status,q.last_error AS check_error
    FROM payment_attempts a JOIN mobile_money_sandbox_cases s ON s.id=a.sandbox_case_id
    LEFT JOIN provider_transactions t ON t.attempt_id=a.id
    LEFT JOIN payment_jobs q ON q.attempt_id=a.id AND q.kind='status'
    ORDER BY a.id DESC LIMIT 40")->fetchAll();
?>
<header class="section-header">
    <div><a href="finance.php">Finance dashboard</a><h1>Mobile Money sandbox</h1>
        <p class="muted">Isolated EUR test cases using configured test wallets.</p></div>
</header>
<div class="workflow-grid">
    <?php foreach (['collection','disbursement'] as $product):
        $enabled = false;
        try { configured_mtn_sandbox($product); $enabled = true; } catch (DomainException $error) {}
        $status = $health['mtn_' . $product];
        ?>
        <article class="card workflow-card">
            <h2>MTN <?= e($product) ?></h2>
            <p><?= $enabled ? 'Sandbox configured' : 'Sandbox disabled or incomplete' ?>
                <?php if ($status['circuit_open']): ?> · Paused by circuit breaker<?php endif; ?></p>
            <p class="muted"><?= e($status['last_category'] ?? 'No recorded failure') ?> ·
                <?= e($status['checked_at'] ?? 'No provider call recorded') ?></p>
            <?php if ($enabled && staff_can($pdo,$actor,sandbox_permission($product))): ?>
                <form method="post" class="workflow-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="product" value="<?= e($product) ?>">
                    <input type="hidden" name="key" value="<?= bin2hex(random_bytes(16)) ?>">
                    <label>Amount in EUR cents <input type="number" name="amount" min="1" max="10000" required></label>
                    <button class="btn">Queue sandbox <?= e($product) ?></button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <article class="card workflow-card"><h2>Airtel Money</h2><p>Uganda sandbox integration awaits approved API materials.</p></article>
</div>
<h2>Sandbox cases</h2>
<?php $attention = count(array_filter($recent,fn($case)=>$case['state']==='unknown' || $case['check_status']==='dead')); ?>
<?php if ($attention): ?><p class="flash flash-err" role="alert"><?= $attention ?> recent cases need finance review.</p><?php endif; ?>
<?php if (!$recent): ?><p>No sandbox cases have been queued.</p><?php endif; ?>
<div class="workflow-grid">
    <?php foreach ($recent as $case): ?>
        <article class="card workflow-card">
            <h3>MTN <?= e($case['operation']) ?> #<?= (int)$case['id'] ?></h3>
            <p><?= e(provider_amount((int)$case['amount_minor'],'EUR')) ?> · <?= e($case['state']) ?>
                <?php if ($case['ledger_status']): ?> · Ledger <?= e($case['ledger_status']) ?><?php endif; ?></p>
            <?php if ($case['reference']): ?><p class="muted"><?= e($case['reference']) ?></p><?php endif; ?>
            <p>Reconciliation <?= e($case['check_status'] ?? 'awaiting dispatch') ?>
                <?php if ($case['diagnostic'] || $case['check_error']): ?> · <?= e($case['diagnostic'] ?? $case['check_error']) ?><?php endif; ?></p>
            <a class="btn btn-outline" href="payment_queue.php?attempt=<?= (int)$case['id'] ?>">Inspect attempt</a>
        </article>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

