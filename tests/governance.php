<?php
declare(strict_types=1);
require __DIR__ . '/../includes/corrections.php';
require __DIR__ . '/../includes/settlement.php';

function seed_phase7_payment(PDO $pdo, PaymentProvider $provider, int $actor, int $amount,
    string $currency, string $key): int
{
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Finance permission required.');
    if ($amount < 1 || $amount > 1000000000000 || !in_array($currency,['UGX','USD'],true)
        || !preg_match('/\A[a-f0-9]{32}\z/',$key)) throw new DomainException('Enter a valid amount and currency.');
    return workflow_transaction($pdo,function () use ($pdo,$provider,$actor,$amount,$currency,$key) {
        workflow_row($pdo,'SELECT id FROM users WHERE id=? FOR UPDATE',[$actor]);
        $existing = workflow_row($pdo,'SELECT * FROM provider_transactions WHERE actor_id=? AND provider=? AND request_key=?',
            [$actor,$provider->code(),$key]);
        if ($existing) {
            if ((int)$existing['amount_minor'] !== $amount || $existing['currency'] !== $currency) {
                throw new DomainException('That request key already has different payment details.');
            }
            return (int)$existing['id'];
        }
        // Exercise the legacy Phase 7 ledger without exposing unlinked initiation in application code.
        $ref = $provider->initiate($amount,$currency,$key);
        $pdo->prepare('INSERT INTO provider_transactions (provider,reference,actor_id,request_key,amount_minor,currency)
            VALUES (?,?,?,?,?,?)')->execute([$provider->code(),$ref,$actor,$key,$amount,$currency]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO settlement_ledger (transaction_id,event_type,amount_minor,currency)
            VALUES (?,'requested',?,?)")->execute([$id,$amount,$currency]);
        audit($pdo,'provider.request','provider_transaction',$id,['to'=>'requested','currency'=>$currency,'minor'=>$amount],$actor);
        return $id;
    });
}

$db = getenv('GIGLINK_TEST_DB') ?: '';
if (!preg_match('/\Agiglink_qa_[a-z0-9]+\z/',$db)) exit(1);
$port = (int)(getenv('GIGLINK_TEST_PORT') ?: 33317);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$db;charset=utf8mb4",'root','',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if (($argv[1] ?? '') === '--review') {
    try {
        decide_correction($pdo,(int)$argv[2],(int)$argv[3],$argv[4] === 'approve','Independent review.');
        echo 'saved';
    } catch (DomainException $error) { echo 'blocked'; }
    exit;
}

function ensure(bool $ok): void {
    if (!$ok) throw new RuntimeException('Assertion failed.');
}
function blocked(callable $op): void {
    try { $op(); } catch (DomainException|UnexpectedValueException $error) { return; }
    throw new RuntimeException('Expected operation to be blocked.');
}
function staff_fixture(PDO $pdo,string $username,string $role): int {
    $pdo->prepare("INSERT INTO users (username,password_hash,role) SELECT ?,password_hash,'youth' FROM users WHERE id=1")
        ->execute([$username]);
    $id = (int)$pdo->lastInsertId();
    change_staff($pdo,1,$id,$role,true);
    return $id;
}
$finance = staff_fixture($pdo,'qa_finance','finance_officer');
$second = staff_fixture($pdo,'qa_finance2','finance_officer');
$auditor = staff_fixture($pdo,'qa_auditor','auditor');
$support = staff_fixture($pdo,'qa_support','support_officer');
$disputes = staff_fixture($pdo,'qa_dispute','dispute_officer');
$original = workflow_row($pdo,"SELECT p.* FROM payments p WHERE reference='QA Phase 6 original'",[]);
$payment = (int)$original['id'];
$link = workflow_row($pdo,'SELECT application_id FROM effective_reconciliations WHERE payment_id=?',[$payment]);
$app = (int)$link['application_id'];

$secret = str_repeat('s',64);
$mock = new MockPaymentProvider($secret);
$deliver = function (string $id,string $reference,string $type,int $minor=12000,string $currency='UGX') use ($pdo,$mock,$secret): string {
    $now = time();
    $raw = json_encode(['id'=>$id,'reference'=>$reference,'type'=>$type,'amount_minor'=>$minor,'currency'=>$currency]);
    return process_provider_event($pdo,$mock->code(),$mock->verifyWebhook($raw,[
        'timestamp'=>(string)$now,'signature'=>hash_hmac('sha256',$now . '.' . $raw,$secret)],$now));
};
$tests = [];
$tests['existing Phase 6 reconciliation survives migration reruns'] = function () use ($pdo) {
    $row = workflow_row($pdo,"SELECT p.application_id,r.application_id AS original,e.application_id AS effective,
        v.application_id AS visible,e.revision FROM payments p JOIN payment_reconciliations r ON r.payment_id=p.id
        JOIN effective_reconciliations e ON e.payment_id=p.id JOIN v_application_payments v ON v.id=p.id
        WHERE p.reference='QA Phase 7 carried reconciliation'",[]);
    ensure($row && $row['application_id'] === null && $row['original'] === $row['effective']
        && $row['effective'] === $row['visible'] && (int)$row['revision'] === 0);
};
$tests['role restrictions, no escalation and last Super Admin guard'] = function () use ($pdo,$finance,$auditor,$support,$disputes) {
    ensure(staff_role($pdo,1) === 'super_admin');
    ensure(staff_can($pdo,$finance,'finance.write') && !staff_can($pdo,$finance,'staff.manage'));
    ensure(staff_can($pdo,$auditor,'audit') && staff_can($pdo,$auditor,'finance.read') && !staff_can($pdo,$auditor,'finance.write'));
    ensure(staff_can($pdo,$support,'support') && !staff_can($pdo,$support,'finance.read'));
    ensure(staff_can($pdo,$disputes,'disputes') && !staff_can($pdo,$disputes,'finance.read'));
    blocked(fn()=>change_staff($pdo,$finance,$finance,'super_admin',true));
    blocked(fn()=>change_staff($pdo,1,1,null,false));
    change_staff($pdo,1,$support,null,false);
    ensure(!staff_can($pdo,$support,'support'));
    change_staff($pdo,1,$support,'support_officer',true);
    ensure(staff_role($pdo,$support) === 'support_officer');
};
$tests['dispute assignment scopes read and decision permissions'] = function () use ($pdo,$disputes,$finance) {
    $case = (int)workflow_row($pdo,"SELECT d.id FROM disputes d JOIN applications a ON a.id=d.application_id
        JOIN jobs j ON j.id=a.job_id WHERE j.title='QA staff review'",[])['id'];
    blocked(fn()=>dispute_case($pdo,$case,$disputes));
    blocked(fn()=>assign_dispute($pdo,$case,$finance,$disputes));
    assign_dispute($pdo,$case,1,$disputes);
    ensure(can_manage_dispute($pdo,$disputes,dispute_case($pdo,$case,$disputes)));
    blocked(fn()=>dispute_case($pdo,$case,$finance));
    assign_dispute($pdo,$case,1,0);
};
$tests['correction requires independent approval and appends compensation'] = function () use ($pdo,$payment,$app,$original,$finance,$second,$auditor) {
    blocked(fn()=>request_correction($pdo,$payment,$auditor,null,'Auditor cannot modify.'));
    $request = request_correction($pdo,$payment,$finance,null,'Incorrect historical association.');
    blocked(fn()=>request_correction($pdo,$payment,$second,null,'Duplicate pending.'));
    blocked(fn()=>decide_correction($pdo,$request,$finance,true,'Self approval.'));
    decide_correction($pdo,$request,$second,true,'Verified against original records.');
    ensure(!workflow_row($pdo,'SELECT id FROM v_application_payments WHERE id=?',[$payment]));
    ensure($original === workflow_row($pdo,'SELECT * FROM payments WHERE id=?',[$payment]));
    ensure((int)workflow_row($pdo,'SELECT application_id FROM payment_reconciliations WHERE payment_id=?',[$payment])['application_id'] === $app);
    $restore = request_correction($pdo,$payment,$finance,$app,'Supporting records identify this application.');
    decide_correction($pdo,$restore,$second,true,'Verified corrected association.');
    ensure((int)workflow_row($pdo,'SELECT application_id FROM v_application_payments WHERE id=?',[$payment])['application_id'] === $app);
};
$tests['concurrent reviewers write exactly one correction decision'] = function () use ($pdo,$finance,$second,$payment,$app) {
    $request = request_correction($pdo,$payment,$finance,null,'Concurrent correction review fixture.');
    $source = workflow_row($pdo,'SELECT job_id FROM payments WHERE id=?',[$payment]);
    $pdo->beginTransaction();
    locked_job($pdo,(int)$source['job_id']);
    $children = [];
    foreach ([[$second,'approve'],[1,'reject']] as [$reviewer,$answer]) {
        $proc = proc_open([PHP_BINARY,__FILE__,'--review',(string)$request,(string)$reviewer,$answer],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start reviewer process.');
        $children[] = [$proc,$pipes];
    }
    usleep(250000);
    $pdo->commit();
    $results = [];
    foreach ($children as [$proc,$pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($proc) !== 0) throw new RuntimeException($error);
    }
    sort($results);
    ensure($results === ['blocked','saved']);
    if (!workflow_row($pdo,'SELECT id FROM v_application_payments WHERE id=?',[$payment])) {
        $restore = request_correction($pdo,$payment,$finance,$app,'Restore test association.');
        decide_correction($pdo,$restore,$second,true,'Verified association.');
    }
};
$tests['mock signature validation rejects invalid and stale delivery'] = function () use ($mock,$secret) {
    $now = time();
    blocked(fn()=>$mock->verifyWebhook('{}',['timestamp'=>(string)$now,'signature'=>str_repeat('0',64)],$now));
    $stamp = (string)($now-301);
    blocked(fn()=>$mock->verifyWebhook('{}',['timestamp'=>$stamp,'signature'=>hash_hmac('sha256',$stamp . '.{}',$secret)],$now));
};
$tests['out-of-order, duplicate, conflicting and invalid-value events'] = function () use ($pdo,$mock,$deliver,$finance) {
    $key = str_repeat('a',32);
    $id = seed_phase7_payment($pdo,$mock,$finance,12000,'UGX',$key);
    ensure(seed_phase7_payment($pdo,$mock,$finance,12000,'UGX',$key) === $id);
    $txn = workflow_row($pdo,'SELECT reference,status FROM provider_transactions WHERE id=?',[$id]);
    ensure($txn['status']==='requested');
    ensure(reconcile_provider_status($pdo,$mock,$finance,$id) === null);
    $ref = $txn['reference'];
    ensure($deliver('early_settle',$ref,'settled') === 'pending');
    ensure($deliver('wrong_value',$ref,'confirmed',14000) === 'failed');
    ensure($deliver('confirm',$ref,'confirmed') === 'applied');
    ensure($deliver('confirm',$ref,'confirmed') === 'applied');
    blocked(fn()=>$deliver('confirm',$ref,'received'));
    ensure($deliver('receive',$ref,'received') === 'applied');
    $ledger = $pdo->prepare('SELECT event_type FROM settlement_ledger WHERE transaction_id=? ORDER BY id');
    $ledger->execute([$id]);
    ensure($ledger->fetchAll(PDO::FETCH_COLUMN) === ['requested','confirmed','received','settled']);
    ensure(workflow_row($pdo,'SELECT status FROM provider_transactions WHERE id=?',[$id])['status']==='settled');
    ensure($deliver('refund_complete',$ref,'refunded') === 'pending');
    ensure($deliver('refund_start',$ref,'refund_initiated') === 'applied');
    ensure(workflow_row($pdo,'SELECT status FROM provider_transactions WHERE id=?',[$id])['status']==='refunded');
    $rejected = false;
    try { $pdo->prepare('UPDATE settlement_ledger SET amount_minor=1 WHERE transaction_id=?')->execute([$id]); }
    catch (PDOException $error) { $rejected = $error->getCode()==='45000'; }
    ensure($rejected);
};
$tests['reversal and failed transaction retain prior immutable milestones'] = function () use ($pdo,$mock,$deliver,$finance,$auditor) {
    blocked(fn()=>seed_phase7_payment($pdo,$mock,$auditor,12000,'UGX',str_repeat('c',32)));
    foreach (['reversed','failed'] as $outcome) {
        $id = seed_phase7_payment($pdo,$mock,$finance,12000,'UGX',md5($outcome));
        $ref = workflow_row($pdo,'SELECT reference FROM provider_transactions WHERE id=?',[$id])['reference'];
        if ($outcome === 'reversed') {
            $deliver('confirm_reverse',$ref,'confirmed');
            $deliver('receive_reverse',$ref,'received');
        }
        ensure($deliver('terminal_' . $outcome,$ref,$outcome)==='applied');
        ensure(workflow_row($pdo,'SELECT status FROM provider_transactions WHERE id=?',[$id])['status']===$outcome);
    }
};
$failed = 0;
foreach ($tests as $name=>$test) {
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $error) { ++$failed; echo "FAIL $name: {$error->getMessage()}\n"; }
}
echo (count($tests)-$failed) . " passed, $failed failed\n";
exit($failed ? 1 : 0);

