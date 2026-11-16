<?php
$page_title = 'Finance dashboard';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/settlement.php';
require_staff($pdo,'finance.read');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_staff($pdo,'finance.write');
    http_response_code(405);
    header('Allow: GET');
    exit;
}
$statuses = ['requested','confirmed','received','settled','refund_initiated','refunded','reversed','failed','cancelled'];
$summary = array_column($pdo->query('SELECT status,COUNT(*) AS n FROM provider_transactions GROUP BY status')->fetchAll(),'n','status');
$unmatched = (int)$pdo->query('SELECT COUNT(*) FROM payments p LEFT JOIN effective_reconciliations e ON e.payment_id=p.id
    WHERE p.application_id IS NULL AND e.application_id IS NULL')->fetchColumn();
$pending = (int)$pdo->query('SELECT COUNT(*) FROM reconciliation_corrections WHERE pending=1')->fetchColumn();
$exceptions = $pdo->query("SELECT e.id,e.provider,e.event_type,e.status,e.outcome,t.id AS transaction_id
    FROM provider_events e LEFT JOIN provider_transactions t ON t.provider=e.provider AND t.reference=e.reference
    WHERE e.status IN ('pending','failed') ORDER BY e.id DESC LIMIT 20")->fetchAll();
$id = (int)($_GET['id'] ?? 0);
$transaction = $id ? workflow_row($pdo,'SELECT * FROM provider_transactions WHERE id=?',[$id]) : null;
if ($id && !$transaction) http_response_code(404);
if ($transaction) {
    $ledger = $pdo->prepare('SELECT event_type,amount_minor,currency,created_at FROM settlement_ledger WHERE transaction_id=? ORDER BY id');
    $ledger->execute([$id]);
} elseif (!$id) {
    $status = is_string($_GET['status'] ?? null) && in_array($_GET['status'],$statuses,true) ? $_GET['status'] : '';
    $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,80) : '';
    $where = ' WHERE (?=' . "'' OR status=?) AND (?='' OR LOCATE(?,reference)>0)";
    $args = [$status,$status,$query,$query];
    $total = (int)workflow_row($pdo,'SELECT COUNT(*) AS total FROM provider_transactions' . $where,$args)['total'];
    $page = min(max(1,(int)($_GET['p'] ?? 1)),max(1,(int)ceil($total/25)));
    $offset = ($page-1)*25;
    $records = $pdo->prepare("SELECT * FROM provider_transactions $where ORDER BY id DESC LIMIT 25 OFFSET $offset");
    $records->execute($args);
}
function finance_status(string $status): string {
    return str_replace('_',' ',ucfirst($status));
}
?>
<header class="section-header"><div><a href="index.php">Admin dashboard</a><h1>Finance dashboard</h1></div>
    <div class="row-actions">
        <a class="btn btn-outline" href="mobile_money.php">Mobile Money sandbox</a>
        <a class="btn" href="payment_queue.php">Payment operations</a>
        <a class="btn btn-outline" href="reconcile.php">Historical payments</a>
        <a class="btn btn-outline" href="corrections.php">Correction queue</a>
    </div>
</header>
<?php if ($transaction): ?>
    <section class="card workflow-card">
        <h2>Transaction #<?= $id ?></h2>
        <p><?= e($transaction['provider']) ?> · <?= e($transaction['reference']) ?></p>
        <p><?= e(provider_amount((int)$transaction['amount_minor'],$transaction['currency'])) ?>
            · <?= e(finance_status($transaction['status'])) ?></p>
        <h3>Settlement ledger</h3>
        <ol class="workflow-counts">
            <?php foreach ($ledger as $entry): ?>
                <li><?= e(finance_status($entry['event_type'])) ?> · <?= e($entry['created_at']) ?></li>
            <?php endforeach; ?>
        </ol>
        <a href="finance.php">All transactions</a>
    </section>
<?php elseif ($id): ?><h2>Transaction not found</h2>
<?php else: ?>
    <div class="workflow-grid">
        <div class="card workflow-card"><h2>Provider payments</h2>
            <p><?= (int)($summary['requested'] ?? 0) ?> requested · <?= (int)($summary['confirmed'] ?? 0) ?> confirmed</p>
            <p><?= (int)($summary['received'] ?? 0) ?> received and unsettled · <?= (int)($summary['settled'] ?? 0) ?> settled</p>
            <p><?= (int)($summary['failed'] ?? 0) ?> failed · <?= (int)($summary['cancelled'] ?? 0) ?> cancelled</p>
        </div>
        <div class="card workflow-card"><h2>Refunds and exceptions</h2>
            <p><?= (int)($summary['refund_initiated'] ?? 0) ?> refund pending · <?= (int)($summary['refunded'] ?? 0) ?> refunded · <?= (int)($summary['reversed'] ?? 0) ?> reversed</p>
            <p><?= $unmatched ?> historical reconciliation exceptions · <?= $pending ?> corrections awaiting review</p>
        </div>
    </div>
    <h2>Provider transaction history</h2>
    <form method="get" class="card job-filters">
        <label>Transaction reference <input type="search" name="q" value="<?= e($query) ?>"></label>
        <label>Status <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $item): ?>
                <option value="<?= e($item) ?>" <?= $item === $status ? 'selected' : '' ?>><?= e(finance_status($item)) ?></option>
            <?php endforeach; ?>
        </select></label>
        <button class="btn">Search</button>
    </form>
    <?php if (!$total): ?><p>No provider transactions match these filters.</p><?php endif; ?>
    <div class="workflow-grid">
        <?php foreach ($records as $row): ?>
            <article class="card workflow-card">
                <h3><?= e($row['reference']) ?></h3>
                <p><?= e(provider_amount((int)$row['amount_minor'],$row['currency'])) ?> · <?= e(finance_status($row['status'])) ?></p>
                <p class="muted"><?= e($row['provider']) ?> · <?= e($row['created_at']) ?></p>
                <a class="btn btn-outline" href="?id=<?= (int)$row['id'] ?>">View transaction</a>
            </article>
        <?php endforeach; ?>
    </div>
    <?= paginate($total,25,$page,'finance.php?' . http_build_query(['q'=>$query,'status'=>$status])) ?>
    <h2 id="exceptions">Webhook exceptions</h2>
    <?php if (!$exceptions): ?><p>No webhook exceptions to review.</p><?php endif; ?>
    <div class="workflow-grid">
        <?php foreach ($exceptions as $event): ?>
            <article class="card workflow-card">
                <h3>Event #<?= (int)$event['id'] ?> · <?= e(finance_status($event['event_type'])) ?></h3>
                <p><?= e($event['outcome'] ?? 'Waiting for an earlier provider event.') ?></p>
                <?php if ($event['transaction_id']): ?><a href="?id=<?= (int)$event['transaction_id'] ?>">View transaction</a><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>

