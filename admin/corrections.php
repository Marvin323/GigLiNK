<?php
$page_title = 'Reconciliation corrections';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/corrections.php';
require_staff($pdo,'finance.read');

$payment = (int)($_GET['payment'] ?? 0);
$link = workflow_row($pdo,'SELECT e.*,r.application_id AS original_application FROM effective_reconciliations e
    JOIN payment_reconciliations r ON r.payment_id=e.payment_id WHERE e.payment_id=?',[$payment]);
$write = staff_can($pdo,current_user_id(),'finance.write');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_staff($pdo,'finance.write');
    require_csrf();
    $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
    try {
        $op = $_POST['operation'] ?? '';
        if ($op === 'request' && $link) {
            $raw = $_POST['application'] ?? '';
            $target = filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($raw !== '' && $target === false) throw new DomainException('Enter a valid application ID.');
            request_correction($pdo,$payment,current_user_id(),$raw === '' ? null : $target,$reason);
        } elseif (in_array($op,['approve','reject'],true)) {
            decide_correction($pdo,(int)($_POST['id'] ?? 0),current_user_id(),$op === 'approve',$reason);
        } else {
            throw new DomainException('Choose a supported correction action.');
        }
        flash_set('ok','Correction action recorded.');
    } catch (Throwable $error) {
        flash_set('err',workflow_error($error));
    }
    redirect('/giglink/admin/corrections.php' . ($payment ? '?payment=' . $payment : ''));
}
$filter = $payment ? 'c.payment_id=?' : 'c.pending=1';
$stmt = $pdo->prepare("SELECT c.*,d.approved,d.reason AS decision_reason,d.created_at AS decided_at,
    u.username,r.username AS reviewer FROM reconciliation_corrections c
    JOIN users u ON u.id=c.requester LEFT JOIN correction_decisions d ON d.correction_id=c.id
    LEFT JOIN users r ON r.id=d.reviewer WHERE $filter ORDER BY c.id DESC LIMIT 100");
$stmt->execute($payment ? [$payment] : []);
$rows = $stmt->fetchAll();
?>
<header class="section-header"><div><a href="finance.php">Finance dashboard</a><h1>Reconciliation corrections</h1></div>
    <?php if ($payment): ?><a class="btn btn-outline" href="reconcile.php?id=<?= $payment ?>">Payment record</a><?php endif; ?></header>
<?php if ($link): ?>
    <section class="card workflow-card">
        <h2>Payment #<?= $payment ?></h2>
        <p>Original application #<?= (int)$link['original_application'] ?> · Current association
            <?= $link['application_id'] === null ? 'unlinked' : '#' . (int)$link['application_id'] ?></p>
        <?php if ($write && !array_filter($rows,fn($request)=>$request['pending'])): ?>
            <form method="post" class="workflow-form">
                <?= csrf_field() ?><input type="hidden" name="operation" value="request">
                <label>Replacement application ID <input type="number" name="application" min="1"></label>
                <p class="muted">Leave the replacement blank to remove the effective link.</p>
                <label>Reason <textarea name="reason" required maxlength="1500"></textarea></label>
                <button class="btn">Request correction</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if (!$rows): ?><p>No correction requests to display.</p><?php endif; ?>
<div class="workflow-grid">
<?php foreach ($rows as $request): ?>
    <article class="card workflow-card">
        <h2>Request #<?= (int)$request['id'] ?></h2>
        <p><a href="corrections.php?payment=<?= (int)$request['payment_id'] ?>">Payment #<?= (int)$request['payment_id'] ?></a>
            · <?= $request['pending'] ? 'Awaiting review' : ($request['approved'] ? 'Approved' : 'Rejected') ?></p>
        <p><?= $request['from_application'] ? 'Application #' . (int)$request['from_application'] : 'Unlinked' ?>
            → <?= $request['to_application'] ? 'Application #' . (int)$request['to_application'] : 'Unlinked' ?></p>
        <p class="muted">@<?= e($request['username']) ?> · <?= e($request['created_at']) ?></p>
        <p><?= nl2br(e($request['reason'])) ?></p>
        <?php if (!$request['pending']): ?>
            <p><?= nl2br(e($request['decision_reason'])) ?></p>
            <p class="muted">@<?= e($request['reviewer']) ?> · <?= e($request['decided_at']) ?></p>
        <?php elseif ($write && (int)$request['requester'] !== current_user_id()): ?>
            <form method="post" class="workflow-form" data-confirm="Record this independent decision?">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                <label>Decision
                    <select name="operation" required>
                        <option value="">Choose</option><option value="approve">Approve</option><option value="reject">Reject</option>
                    </select>
                </label>
                <label>Reason <textarea name="reason" required maxlength="1500"></textarea></label>
                <button class="btn">Record decision</button>
            </form>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

