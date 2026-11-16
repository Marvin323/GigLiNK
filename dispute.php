<?php
$page_title = 'Dispute';
require __DIR__ . '/includes/header.php';
require_login();
require_once __DIR__ . '/includes/work_cards.php';
require_once __DIR__ . '/includes/dispute_evidence.php';

$id = (int)($_GET['id'] ?? 0);
$actor = current_user_id();

try {
    $case = dispute_case($pdo,$id,$actor);
    $staff = can_manage_dispute($pdo,$actor,$case);
    $super = staff_can($pdo,$actor,'staff.manage');
} catch (DomainException $error) {
    http_response_code(404);
    echo '<h1>Dispute not found</h1>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $input = fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    try {
        if ($input('operation') === 'assign') {
            assign_dispute($pdo,$id,$actor,(int)$input('officer'));
            flash_set('ok','Case assignment updated.');
        } elseif ($input('operation') === 'statement') {
            $evidence = uploaded_dispute_evidence($_FILES['evidence'] ?? null);
            submit_dispute_statement($pdo,$id,$actor,$staff ? (int)$input('party') : $actor,
                $input('statement'),$input('key'),$evidence);
            flash_set('ok','Statement received.');
        } elseif ($input('operation') === 'decision') {
            $revision = filter_var($input('revision'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($revision === false) throw new DomainException('Refresh the case before submitting a decision.');
            decide_dispute($pdo,$id,$actor,$revision,$input('next'),$input('reason'));
            flash_set('ok','Decision recorded.');
        } else {
            throw new DomainException('Choose a valid action.');
        }
    } catch (Throwable $error) {
        flash_set('err',workflow_error($error));
    }
    redirect('/giglink/dispute.php?id=' . $id);
}
$app = workflow_row($pdo,'SELECT * FROM applications WHERE id=?',[$case['application_id']]);
$app = attach_payment_history($pdo,[$app])[0];
$statements = $pdo->prepare('SELECT s.id,s.party_id,s.statement,s.evidence_type,s.created_at,s.actor_id,u.username
    FROM dispute_submissions s JOIN users u ON u.id=s.party_id WHERE s.dispute_id=? ORDER BY s.id');
$statements->execute([$id]);
$decisions = $pdo->prepare('SELECT d.*,u.username FROM dispute_decisions d JOIN users u ON u.id=d.actor_id
    WHERE d.dispute_id=? ORDER BY d.revision DESC');
$decisions->execute([$id]);
$events = [];
if ($staff) {
    $eventQuery = $pdo->prepare("SELECT l.action,l.created_at,u.username FROM audit_log l LEFT JOIN users u ON u.id=l.actor_id
        WHERE l.entity='application' AND l.entity_id=? ORDER BY l.id DESC LIMIT 25");
    $eventQuery->execute([$app['id']]);
    $events = $eventQuery->fetchAll();
}
$open = in_array($case['status'],['open','reviewing'],true);
$canReopen = $case['payment_id'] || ($app['status'] === 'in_progress');
?>
<header class="section-header">
    <div><a href="/giglink/disputes.php">All disputes</a>
        <h1><?= e($case['title']) ?></h1>
        <p><?= $case['payment_id'] ? 'Payment' : 'Work' ?> dispute #<?= $id ?> · opened <?= e($case['created_at']) ?></p>
        <p class="muted">Worker @<?= e($case['worker']) ?> · Employer @<?= e($case['employer']) ?></p></div>
    <span class="badge badge-primary"><?= e(ucfirst($case['status'])) ?></span>
</header>
<p class="card"><?= $open ? 'Add relevant information below. Staff will notify both parties when the case status changes.'
    : 'Read the recorded outcome below. Contact staff if new information warrants reopening.' ?></p>

<div class="workflow-grid">
    <section class="card workflow-card">
        <h2>Hiring and payment history</h2>
        <p>Work status · <?= e(application_status($app['status'])) ?></p>
        <?php work_timeline($app); payment_history($app,false); ?>
        <?php if ($events): ?>
            <details class="workflow-details">
                <summary>Application audit history</summary>
                <ul class="workflow-counts">
                    <?php foreach ($events as $event): ?>
                        <li><?= e($event['created_at']) ?> · <?= e($event['action']) ?>
                            <?php if ($event['username']): ?> · @<?= e($event['username']) ?><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </section>
    <section class="card workflow-card">
        <h2>Decision history</h2>
        <?php if ($super): ?>
            <form method="post" class="workflow-form">
                <?= csrf_field() ?><input type="hidden" name="operation" value="assign">
                <label>Assigned Dispute Officer <select name="officer">
                    <option value="0">Unassigned</option>
                    <?php foreach ($pdo->query("SELECT u.id,u.username FROM staff_roles s JOIN users u ON u.id=s.user_id
                        WHERE s.role='dispute_officer' AND s.enabled=1 AND u.active=1 AND u.deleted_at IS NULL ORDER BY u.username") as $officer): ?>
                        <option value="<?= (int)$officer['id'] ?>" <?= (int)$case['assigned_to']===(int)$officer['id']?'selected':'' ?>><?= e($officer['username']) ?></option>
                    <?php endforeach; ?>
                </select></label><button class="btn btn-outline">Save assignment</button>
            </form>
        <?php endif; ?>
        <?php $history = $decisions->fetchAll(); ?>
        <?php if (!$history): ?><p>No staff decision yet.</p><?php endif; ?>
        <?php foreach ($history as $decision): ?>
            <div class="payment-record">
                <strong><?= e(ucfirst($decision['next_status'])) ?></strong>
                <p class="muted"><?= e($decision['created_at']) ?>
                    <?php if ($staff): ?> · @<?= e($decision['username']) ?><?php endif; ?></p>
                <p><?= nl2br(e($decision['reason'])) ?></p>
            </div>
        <?php endforeach; ?>
        <?php if ($staff && ($open || $canReopen)): ?>
            <form method="post" class="workflow-form" data-confirm="Record this staff decision? Both parties will be notified.">
                <?= csrf_field() ?>
                <input type="hidden" name="operation" value="decision">
                <input type="hidden" name="revision" value="<?= (int)$case['revision'] ?>">
                <label>Decision
                    <select name="next" required>
                        <option value="">Choose a decision</option>
                        <?php if ($case['status'] === 'open'): ?><option value="reviewing">Begin review</option><?php endif; ?>
                        <?php if ($open): ?>
                            <option value="resolved">Resolve</option><option value="rejected">Reject</option>
                        <?php else: ?><option value="open">Reopen</option><?php endif; ?>
                    </select>
                </label>
                <?php if ($open && !$case['payment_id']): ?><p class="muted">Closing a work dispute returns the gig to work in progress.</p><?php endif; ?>
                <label>Reason shared with both parties <textarea name="reason" required maxlength="1500"></textarea></label>
                <button class="btn">Record staff decision</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="card workflow-card" style="margin-top:1rem">
    <h2>Statements and evidence</h2>
    <p class="muted">Visible to both parties and authorized staff.</p>
    <?php if ($case['initial_reason']): ?>
        <div class="payment-record"><strong>Opening statement</strong><p><?= nl2br(e($case['initial_reason'])) ?></p></div>
    <?php endif; ?>
    <?php foreach ($statements as $statement): ?>
        <div class="payment-record">
            <strong>@<?= e($statement['username']) ?></strong>
            <p class="muted"><?= e($statement['created_at']) ?><?= $statement['actor_id'] !== $statement['party_id'] ? ' · entered by staff' : '' ?></p>
            <p><?= nl2br(e($statement['statement'])) ?></p>
            <?php if ($statement['evidence_type']): ?>
                <a href="/giglink/dispute_evidence.php?id=<?= (int)$statement['id'] ?>">Download evidence</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($open): ?>
        <form method="post" enctype="multipart/form-data" class="workflow-form">
            <?= csrf_field() ?>
            <input type="hidden" name="operation" value="statement">
            <input type="hidden" name="key" value="<?= bin2hex(random_bytes(16)) ?>">
            <?php if ($staff): ?>
                <label>Statement from <select name="party" required>
                    <option value="">Choose a party</option>
                    <option value="<?= (int)$case['youth_id'] ?>">Worker</option>
                    <option value="<?= (int)$case['employer_id'] ?>">Employer</option>
                </select></label>
            <?php endif; ?>
            <label>Statement <textarea name="statement" maxlength="3000"></textarea></label>
            <label>Evidence image (optional, JPEG or PNG up to 2 MB)
                <input type="file" name="evidence" accept="image/jpeg,image/png">
            </label>
            <button class="btn">Submit statement</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

