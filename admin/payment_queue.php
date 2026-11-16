<?php
$page_title = 'Payment operations';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/payment_attempts.php';
require_staff($pdo,'finance.read');
$actor = current_user_id();
$writer = staff_can($pdo,$actor,'finance.write');
$mock = false;
try { $mock = mock_provider(); } catch (DomainException $error) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_staff($pdo,'finance.write');
    require_csrf();
    $destination = '/giglink/admin/payment_queue.php';
    try {
        $operation = $_POST['operation'] ?? '';
        if ($operation === 'queue' && $mock) {
            $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
            $id = queue_gig_payment($pdo,$mock,$actor,(int)($_POST['application'] ?? 0),$key);
            $destination .= '?attempt=' . $id;
            flash_set('ok','Sandbox payment queued.');
        } elseif ($operation === 'check') {
            $id = (int)($_POST['attempt'] ?? 0);
            request_payment_check($pdo,$actor,$id);
            $destination .= '?attempt=' . $id;
            flash_set('ok','Status check queued.');
        } elseif ($operation === 'retry') {
            retry_payment_job($pdo,$actor,(int)($_POST['job'] ?? 0));
            flash_set('ok','Job queued for safe recovery.');
        } else {
            throw new DomainException('Payment operation unavailable.');
        }
    } catch (Throwable $error) {
        flash_set('err',workflow_error($error));
    }
    redirect($destination);
}
$id = (int)($_GET['attempt'] ?? 0);
$detail = $id ? payment_attempt($pdo,$id) : null;
if ($id && !$detail) http_response_code(404);
$jobs = $pdo->prepare("SELECT q.id,q.kind,q.status,q.attempts,q.max_attempts,q.last_error,q.run_after,
    q.attempt_id,a.state AS attempt_state FROM payment_jobs q LEFT JOIN payment_attempts a ON a.id=q.attempt_id WHERE " . ($detail ? 'q.attempt_id=?' : "status='dead'") . ' ORDER BY q.id DESC LIMIT 50');
$jobs->execute($detail?[$id]:[]);
$counts = array_column($pdo->query('SELECT status,COUNT(*) AS n FROM payment_jobs GROUP BY status')->fetchAll(),'n','status');
$attempts = array_column($pdo->query("SELECT CASE WHEN t.status IN ('failed','cancelled','refunded','reversed','confirmed','received','settled','refund_initiated')
    THEN t.status ELSE a.state END AS stage,COUNT(*) AS n FROM payment_attempts a
    LEFT JOIN provider_transactions t ON t.attempt_id=a.id GROUP BY stage")->fetchAll(),'n','stage');
$duplicates = $pdo->query("SELECT o.id,o.application_id FROM payment_obligations o JOIN payment_attempts a ON a.obligation_id=o.id
    JOIN provider_transactions t ON t.attempt_id=a.id WHERE t.status IN ('confirmed','received','settled','refund_initiated')
    GROUP BY o.id,o.application_id HAVING COUNT(*)>1 LIMIT 50")->fetchAll();
$rows = $id ? [] : $pdo->query("SELECT a.id,a.state,a.diagnostic,a.provider,o.application_id,s.operation,t.status AS provider_status
    FROM payment_attempts a LEFT JOIN payment_obligations o ON o.id=a.obligation_id
    LEFT JOIN mobile_money_sandbox_cases s ON s.id=a.sandbox_case_id LEFT JOIN provider_transactions t ON t.attempt_id=a.id ORDER BY a.id DESC LIMIT 50")->fetchAll();
$eligible = $mock && $writer && !$id ? $pdo->query("SELECT a.id,j.title,j.pay_amount FROM applications a
    JOIN jobs j ON j.id=a.job_id WHERE a.status='completed' AND a.paid_at IS NULL
    AND j.pay_type='fixed' AND j.pay_amount>=1 AND j.pay_amount=FLOOR(j.pay_amount)
    AND NOT EXISTS (SELECT 1 FROM v_application_payments p WHERE p.application_id=a.id AND p.receipt_status IS NOT NULL)
    ORDER BY a.id DESC LIMIT 100")->fetchAll() : [];
?>
<header class="section-header"><div><a href="finance.php">Finance dashboard</a><h1>Payment operations</h1></div>
    <?php if ($id): ?><a class="btn btn-outline" href="payment_queue.php">All attempts</a><?php else: ?><a class="btn btn-outline" href="mobile_money.php">Mobile Money sandbox</a><?php endif; ?>
</header>
<?php if ($detail): ?>
    <section class="card workflow-card">
        <h2>Attempt #<?= $id ?></h2>
        <p><?= $detail['operation'] ? 'EUR sandbox ' . e($detail['operation']) : 'Application #' . (int)$detail['application_id'] ?> · <?= e(provider_amount((int)$detail['amount_minor'],$detail['currency'])) ?></p>
        <p><?= e($detail['provider']) ?> · <?= e($detail['state']) ?> · <?= e($detail['diagnostic'] ?? 'No dispatch exception') ?></p>
        <?php if ($detail['transaction_id']): ?><p><a href="finance.php?id=<?= (int)$detail['transaction_id'] ?>">Verified event ledger</a>
            · <?= e($detail['provider_status']) ?></p><?php endif; ?>
        <?php if ($writer && (!$detail['operation'] || staff_can($pdo,$actor,sandbox_permission($detail['operation']))) && in_array($detail['state'],['sending','unknown','accepted'],true)): ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="operation" value="check">
                <input type="hidden" name="attempt" value="<?= $id ?>">
                <button class="btn">Request status reconciliation</button>
            </form>
        <?php endif; ?>
    </section>
<?php elseif ($id): ?>
    <h2>Payment attempt not found</h2>
<?php else: ?>
    <div class="workflow-grid">
        <section class="card workflow-card"><h2>Queue</h2>
            <p><?= (int)($counts['queued'] ?? 0) ?> queued · <?= (int)($counts['processing'] ?? 0) ?> processing
                · <?= (int)($counts['dead'] ?? 0) ?> dead-letter jobs</p></section>
        <section class="card workflow-card"><h2>Provider outcomes</h2>
            <p><?= (int)($attempts['accepted'] ?? 0) ?> pending provider confirmation
                · <?= (int)($attempts['unknown'] ?? 0) ?> unknown
                · <?= (int)(($attempts['failed'] ?? 0)+($attempts['rejected'] ?? 0)) ?> failed</p>
            <p><?= (int)(($attempts['confirmed'] ?? 0)+($attempts['received'] ?? 0)+($attempts['settled'] ?? 0)) ?> confirmed or later
                · <?= (int)($attempts['settled'] ?? 0) ?> settled</p>
            <a href="finance.php#exceptions">Settlement exceptions</a></section>
    </div>
    <?php foreach ($duplicates as $duplicate): ?>
        <p class="flash flash-err">Application #<?= (int)$duplicate['application_id'] ?> has multiple successful attempts. Investigate before recording another payment.</p>
    <?php endforeach; ?>
    <?php if ($mock && $writer): ?>
        <form method="post" class="card workflow-card workflow-form">
            <h2>Queue a local mock payment</h2>
            <?= csrf_field() ?><input type="hidden" name="operation" value="queue">
            <input type="hidden" name="key" value="<?= bin2hex(random_bytes(16)) ?>">
            <label>Completed application
                <select name="application" required>
                    <option value="">Choose an application</option>
                    <?php foreach ($eligible as $application): ?>
                        <option value="<?= (int)$application['id'] ?>"><?= e($application['title']) ?> · #<?= (int)$application['id'] ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="muted">The amount comes from the application’s fixed gig pay in whole UGX.</p>
            <button class="btn">Queue mock payment</button>
        </form>
    <?php endif; ?>
    <h2>Recent attempts</h2>
    <?php if (!$rows): ?><p>No obligation-linked attempts yet.</p><?php endif; ?>
    <div class="workflow-grid">
    <?php foreach ($rows as $attempt): ?>
        <article class="card workflow-card"><h3>Attempt #<?= (int)$attempt['id'] ?></h3>
            <p><?= $attempt['operation'] ? 'EUR sandbox ' . e($attempt['operation']) : 'Application #' . (int)$attempt['application_id'] ?> · <?= e($attempt['provider_status'] ?? $attempt['state']) ?></p>
            <p class="muted"><?= e($attempt['diagnostic'] ?? '') ?></p>
            <a class="btn btn-outline" href="?attempt=<?= (int)$attempt['id'] ?>">Inspect attempt</a>
        </article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2><?= $detail ? 'Jobs for this attempt' : 'Dead-letter jobs' ?></h2>
<div class="workflow-grid">
    <?php foreach ($jobs as $job): ?>
        <article class="card workflow-card">
            <h3>Job #<?= (int)$job['id'] ?> · <?= e($job['kind']) ?></h3>
            <p><?= e($job['status']) ?> · <?= (int)$job['attempts'] ?> of <?= (int)$job['max_attempts'] ?> attempts</p>
            <p class="muted"><?= e($job['last_error'] ?? '') ?></p>
            <?php if (!$detail && $job['attempt_id']): ?><a href="?attempt=<?= (int)$job['attempt_id'] ?>">Inspect attempt</a><?php endif; ?>
            <?php if ($writer && $job['status'] === 'dead' && ($job['kind'] !== 'initiate' || $job['attempt_state'] === 'queued')): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="operation" value="retry">
                    <input type="hidden" name="job" value="<?= (int)$job['id'] ?>">
                    <button class="btn btn-outline">Recover eligible job</button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

