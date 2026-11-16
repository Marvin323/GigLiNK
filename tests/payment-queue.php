<?php
declare(strict_types=1);
require __DIR__ . '/../includes/payment_attempts.php';

$db = getenv('GIGLINK_TEST_DB') ?: '';
if (!preg_match('/\Agiglink_qa_[a-z0-9]+\z/',$db)) exit(1);
$port = (int)(getenv('GIGLINK_TEST_PORT') ?: 33317);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$db;charset=utf8mb4",'root','',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if (($argv[1] ?? '') === '--claim') {
    echo json_encode(claim_payment_job($pdo));
    exit;
}

function check_queue(bool $ok): void {
    if (!$ok) throw new RuntimeException('Assertion failed.');
}
function denied_queue(callable $operation): void {
    try { $operation(); } catch (DomainException $error) { return; }
    throw new RuntimeException('Expected refusal.');
}
function queue_fixture(PDO $pdo, string $title): int {
    $pdo->prepare("INSERT INTO jobs (employer_id,title,description,pay_amount,pay_type,status)
        VALUES (4,?,'QA fixture',49000,'fixed','completed')")->execute([$title]);
    $job = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO applications (job_id,youth_id,status,completed_at) VALUES (?,2,'completed',NOW())")
        ->execute([$job]);
    return (int)$pdo->lastInsertId();
}
function get_queue_job(PDO $pdo, int $attempt, string $kind): array {
    return workflow_row($pdo,'SELECT * FROM payment_jobs WHERE attempt_id=? AND kind=?',[$attempt,$kind]);
}
function take_queue_job(PDO $pdo, int $id): array {
    $pdo->exec("UPDATE payment_jobs SET run_after=NOW()+INTERVAL 1 DAY WHERE status='queued'");
    $pdo->prepare('UPDATE payment_jobs SET run_after=NOW(6) WHERE id=?')->execute([$id]);
    $job = claim_payment_job($pdo);
    check_queue($job && (int)$job['id']===$id);
    return $job;
}
function queue_event(string $id, string $reference, string $type): array {
    return ['id'=>$id,'reference'=>$reference,'type'=>$type,'currency'=>'UGX','amount_minor'=>49000];
}

final class ScriptedQueueProvider implements QueuedPaymentProvider
{
    public int $calls = 0;
    public bool $timeout = false;
    public array $lookup = ['state'=>'unknown'];
    public function code(): string { return 'mock'; }
    public function initiate(int $amount, string $currency, string $requestKey): string { throw new LogicException(); }
    public function checkStatus(string $reference): ?array { return null; }
    public function verifyWebhook(string $raw, array $headers, int $now): array { throw new LogicException(); }
    public function dispatchPayment(array $attempt): array {
        ++$this->calls;
        if ($this->timeout) throw new RuntimeException('Simulated transport timeout containing private data.');
        return ['state'=>'accepted','reference'=>'mock_' . $attempt['dispatch_key']];
    }
    public function inspectPayment(array $attempt, bool $settlement): array { return $this->lookup; }
}
$mock = new ScriptedQueueProvider();
$get = fn($code) => $mock;
$fixture = queue_fixture($pdo,'QA queue main');
$extra = queue_fixture($pdo,'QA queue second');
queue_fixture($pdo,'QA queue browser');
$first = queue_gig_payment($pdo,$mock,1,$fixture,str_repeat('a',32));
$second = queue_gig_payment($pdo,$mock,1,$extra,str_repeat('b',32));

$checks = [];
$checks['duplicate submission returns the original attempt and roles are enforced'] = function () use ($pdo,$mock,$fixture,$first) {
    check_queue(queue_gig_payment($pdo,$mock,1,$fixture,str_repeat('a',32))===$first);
    denied_queue(fn()=>queue_gig_payment($pdo,$mock,1,$fixture,str_repeat('c',32)));
    denied_queue(fn()=>queue_gig_payment($pdo,$mock,2,$fixture,str_repeat('d',32)));
    denied_queue(fn()=>request_payment_check($pdo,2,$first));
};
$checks['independent workers claim distinct jobs and an expired owner is fenced'] = function () use ($pdo,$get) {
    $children = [];
    for ($i=0;$i<2;++$i) {
        $child = proc_open([PHP_BINARY,__FILE__,'--claim'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($child)) throw new RuntimeException('Unable to start worker.');
        $children[] = [$child,$pipes];
    }
    $claims = [];
    foreach ($children as [$child,$pipes]) {
        $claims[] = json_decode(stream_get_contents($pipes[1]),true);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($child)) throw new RuntimeException($err);
    }
    check_queue($claims[0] && $claims[1] && $claims[0]['id'] !== $claims[1]['id']);
    $old = $claims[0];
    $pdo->prepare('UPDATE payment_jobs SET lease_until=NOW()-INTERVAL 1 SECOND WHERE id=?')->execute([$old['id']]);
    $reclaimed = claim_payment_job($pdo);
    check_queue($reclaimed['id']===$old['id'] && $reclaimed['lock_owner']!==$old['lock_owner'] && (int)$reclaimed['attempts']===2);
    finish_payment_job($pdo,$old,null);
    check_queue(workflow_row($pdo,'SELECT status FROM payment_jobs WHERE id=?',[$old['id']])['status']==='processing');
    run_payment_job($pdo,$reclaimed,$get);
    run_payment_job($pdo,$claims[1],$get);
};
$checks['lost dispatch reply never resends and lookup records delayed confirmation'] = function () use ($pdo,$mock,$get) {
    $app = queue_fixture($pdo,'QA queue timeout');
    $attempt = queue_gig_payment($pdo,$mock,1,$app,str_repeat('e',32));
    $before = $mock->calls;
    $mock->timeout = true;
    run_payment_job($pdo,take_queue_job($pdo,(int)get_queue_job($pdo,$attempt,'initiate')['id']),$get);
    $row = payment_attempt($pdo,$attempt);
    check_queue($row['state']==='unknown' && $row['diagnostic']==='dispatch_unknown');
    denied_queue(fn()=>queue_gig_payment($pdo,$mock,1,$app,str_repeat('f',32)));
    $ref = 'mock_' . $row['dispatch_key'];
    $mock->lookup = ['state'=>'accepted','reference'=>$ref,'events'=>[queue_event('lookup-confirm',$ref,'confirmed')]];
    run_payment_job($pdo,take_queue_job($pdo,(int)get_queue_job($pdo,$attempt,'status')['id']),$get);
    $res = payment_attempt($pdo,$attempt);
    check_queue($res['provider_status']==='confirmed' && $mock->calls===$before+1);
    $mock->lookup = ['state'=>'accepted','reference'=>$ref,'events'=>[queue_event('lookup-failed',$ref,'cancelled')]];
    run_payment_job($pdo,take_queue_job($pdo,(int)get_queue_job($pdo,$attempt,'settlement')['id']),$get);
    check_queue(payment_attempt($pdo,$attempt)['provider_status']==='cancelled');
    $mock->timeout = false;
    $mock->lookup = ['state'=>'unknown'];
};
$checks['dispatch fenced before crash is reconciled without a second send'] = function () use ($pdo,$mock,$get) {
    $app = queue_fixture($pdo,'QA queue crash');
    $attempt = queue_gig_payment($pdo,$mock,1,$app,str_repeat('1',32));
    $job = take_queue_job($pdo,(int)get_queue_job($pdo,$attempt,'initiate')['id']);
    $pdo->prepare("UPDATE payment_attempts SET state='sending' WHERE id=?")->execute([$attempt]);
    $pdo->prepare("UPDATE payment_jobs SET lease_until=NOW()-INTERVAL 1 SECOND WHERE id=?")->execute([$job['id']]);
    $before = $mock->calls;
    run_payment_job($pdo,claim_payment_job($pdo),$get);
    check_queue($mock->calls===$before && payment_attempt($pdo,$attempt)['state']==='unknown');
};
$checks['verified delayed callback recovers an interrupted mock reference'] = function () use ($pdo,$mock,$get) {
    $app = queue_fixture($pdo,'QA delayed callback');
    $attempt = queue_gig_payment($pdo,$mock,1,$app,str_repeat('2',32));
    $mock->timeout = true;
    run_payment_job($pdo,take_queue_job($pdo,(int)get_queue_job($pdo,$attempt,'initiate')['id']),$get);
    $mock->timeout = false;
    $item = payment_attempt($pdo,$attempt);
    $reference = 'mock_' . $item['dispatch_key'];
    check_queue($item['state']==='unknown' && $item['reference']===null);
    enqueue_verified_webhook($pdo,'mock',queue_event('delayed-confirm',$reference,'confirmed'));
    $job = workflow_row($pdo,"SELECT q.id FROM payment_jobs q JOIN provider_events e ON e.id=q.event_id WHERE e.event_key='delayed-confirm'",[]);
    run_payment_job($pdo,take_queue_job($pdo,(int)$job['id']),$get);
    $item = payment_attempt($pdo,$attempt);
    check_queue($item['reference']===$reference && $item['provider_status']==='confirmed');
};
$checks['asynchronous webhook duplicate and out-of-order settlement preserve ledger'] = function () use ($pdo,$first,$get) {
    $ref = payment_attempt($pdo,$first)['reference'];
    foreach (['settled','confirmed','received'] as $type) {
        $event = queue_event('async-' . $type,$ref,$type);
        check_queue(enqueue_verified_webhook($pdo,'mock',$event)==='queued');
        check_queue(enqueue_verified_webhook($pdo,'mock',$event)==='queued');
        $job = workflow_row($pdo,'SELECT q.id FROM payment_jobs q JOIN provider_events e ON e.id=q.event_id WHERE e.event_key=?',[$event['id']]);
        run_payment_job($pdo,take_queue_job($pdo,(int)$job['id']),$get);
    }
    check_queue(payment_attempt($pdo,$first)['provider_status']==='settled');
    check_queue(enqueue_verified_webhook($pdo,'mock',queue_event('async-settled',$ref,'settled'))==='applied');
    $ledger = $pdo->prepare('SELECT event_type FROM settlement_ledger WHERE transaction_id=? ORDER BY id');
    $ledger->execute([payment_attempt($pdo,$first)['transaction_id']]);
    check_queue($ledger->fetchAll(PDO::FETCH_COLUMN) === ['requested','confirmed','received','settled']);
};
$checks['conflicting lookup cannot replace a reference and dead-letter recovery is safe'] = function () use ($pdo,$second,$mock,$get) {
    $id = (int)get_queue_job($pdo,$second,'status')['id'];
    $pdo->prepare('UPDATE payment_jobs SET max_attempts=1 WHERE id=?')->execute([$id]);
    $mock->lookup = ['state'=>'accepted','reference'=>'mock_' . str_repeat('9',32)];
    run_payment_job($pdo,take_queue_job($pdo,$id),$get);
    $dead = get_queue_job($pdo,$second,'status');
    check_queue($dead['status']==='dead' && $dead['last_error']==='provider_conflict');
    denied_queue(fn()=>retry_payment_job($pdo,2,$id));
    retry_payment_job($pdo,1,$id);
    check_queue(get_queue_job($pdo,$second,'status')['status']==='queued');
    $mock->lookup = ['state'=>'unknown'];
    $pdo->prepare('UPDATE payment_jobs SET run_after=NOW()+INTERVAL 1 DAY WHERE id=?')->execute([$id]);
};
$checks['dead-letter initialization can restart only before dispatch'] = function () use ($pdo,$mock,$get) {
    $app = queue_fixture($pdo,'QA safe recovery');
    $attempt = queue_gig_payment($pdo,$mock,1,$app,str_repeat('3',32));
    $id = (int)get_queue_job($pdo,$attempt,'initiate')['id'];
    $pdo->prepare('UPDATE payment_jobs SET max_attempts=1 WHERE id=?')->execute([$id]);
    $disabled = fn() => throw new DomainException('Unavailable.');
    run_payment_job($pdo,take_queue_job($pdo,$id),$disabled);
    check_queue(get_queue_job($pdo,$attempt,'initiate')['status']==='dead' && payment_attempt($pdo,$attempt)['state']==='queued');
    retry_payment_job($pdo,1,$id);
    $mock->timeout = true;
    run_payment_job($pdo,take_queue_job($pdo,$id),$get);
    $mock->timeout = false;
    check_queue(payment_attempt($pdo,$attempt)['state']==='unknown');
    $pdo->prepare("UPDATE payment_jobs SET status='dead' WHERE id=?")->execute([$id]);
    denied_queue(fn()=>retry_payment_job($pdo,1,$id));
    $pdo->exec("UPDATE payment_jobs SET run_after=NOW()+INTERVAL 1 DAY WHERE status='queued'");
};
$failed = 0;
foreach ($checks as $test=>$run) {
    try { $run(); echo "PASS $test\n"; }
    catch (Throwable $error) { ++$failed; echo "FAIL $test: {$error->getMessage()}\n"; }
}
echo (count($checks)-$failed) . " passed, $failed failed\n";
exit($failed?1:0);

