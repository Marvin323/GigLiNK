<?php
declare(strict_types=1);
require __DIR__ . '/../includes/reconciliation.php';
require __DIR__ . '/../includes/payments.php';
require __DIR__ . '/../includes/dispute_evidence.php';

$db = getenv('GIGLINK_TEST_DB') ?: '';
if (!preg_match('/\Agiglink_qa_[a-z0-9]+\z/',$db)) exit(1);
$port = (int)(getenv('GIGLINK_TEST_PORT') ?: 33317);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$db;charset=utf8mb4",'root','',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
if (($argv[1] ?? '') === '--decide') {
    try {
        decide_dispute($pdo,(int)$argv[2],1,0,$argv[3],'Concurrent reviewer decision.');
        echo 'saved';
    } catch (DomainException $error) {
        echo 'rejected';
    }
    exit;
}
function check(bool $actual): void {
    if (!$actual) throw new RuntimeException('Assertion failed.');
}
function refused(callable $call): void {
    try { $call(); } catch (DomainException) { return; }
    throw new RuntimeException('Expected refusal.');
}
function fixture(PDO $pdo, string $title): array {
    $pdo->prepare("INSERT INTO jobs (employer_id,title,description,pay_type,pay_amount,workers_needed)
        VALUES (4,?,'QA fixture','fixed',18000,1)")->execute([$title]);
    $job = (int)$pdo->lastInsertId();
    apply_for_job($pdo,$job,2,'Available to work.');
    $app = (int)workflow_row($pdo,'SELECT id FROM applications WHERE job_id=? AND youth_id=2',[$job])['id'];
    change_work_state($pdo,$job,$app,4,'accepted');
    change_work_state($pdo,$job,$app,2,'in_progress');
    return [$job,$app];
}

[$job,$app] = fixture($pdo,'QA staff review');
change_work_state($pdo,$job,$app,2,'disputed');
$case = (int)workflow_row($pdo,'SELECT id FROM disputes WHERE active_work_id=?',[$app])['id'];
[$otherJob,$otherApp] = fixture($pdo,'QA race review');
change_work_state($pdo,$otherJob,$otherApp,4,'disputed');
$race = (int)workflow_row($pdo,'SELECT id FROM disputes WHERE active_work_id=?',[$otherApp])['id'];
[$targetJob,$targetApp] = fixture($pdo,'QA historical match');
change_work_state($pdo,$targetJob,$targetApp,4,'completed');
$pdo->prepare("INSERT INTO payments (job_id,employer_id,youth_id,amount,method,reference,status)
    VALUES (?,4,2,18000,'cash','QA Phase 6 original','released'),(?,4,2,18000,'cash','QA duplicate','released')")
    ->execute([$job,$job]);
$origin = (int)workflow_row($pdo,"SELECT id FROM payments WHERE reference='QA Phase 6 original'",[])['id'];
$duplicate = (int)workflow_row($pdo,"SELECT id FROM payments WHERE reference='QA duplicate'",[])['id'];

[$browserJob,$browserApp] = fixture($pdo,'QA browser reconciliation');
change_work_state($pdo,$browserJob,$browserApp,4,'completed');
$pdo->exec("INSERT INTO jobs(employer_id,title,description) VALUES (4,'QA browser source','Fixture')");
$browserSource = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO payments (job_id,employer_id,youth_id,amount,method,reference,status)
    VALUES (?,4,2,18000,'cash','QA browser historical payment','released')")->execute([$browserSource]);

$tests = [];
$tests['legacy open cases are backfilled once across migration reruns'] = function () use ($pdo) {
    $rows = $pdo->query("SELECT d.status,d.opened_by,d.initial_reason,d.payment_id FROM disputes d
        JOIN applications a ON a.id=d.application_id JOIN jobs j ON j.id=a.job_id
        WHERE j.title IN ('QA migrated work dispute','QA migrated payment dispute') ORDER BY d.id")->fetchAll();
    check(count($rows) === 2 && $rows[0]['status'] === 'open' && $rows[0]['opened_by'] === null
        && $rows[1]['status'] === 'open' && $rows[1]['initial_reason'] === 'Legacy dispute reason.'
        && $rows[1]['payment_id'] !== null);
};
$tests['case access limited to participants and active staff'] = function () use ($pdo,$case) {
    foreach ([1,2,4] as $actor) check((int)dispute_case($pdo,$case,$actor)['id'] === $case);
    foreach ([3,5] as $actor) refused(fn() => dispute_case($pdo,$case,$actor));
    refused(fn() => decide_dispute($pdo,$case,4,0,'resolved','Employer attempt.'));
    refused(fn() => submit_dispute_statement($pdo,$case,2,4,'Wrong party.',str_repeat('a',32),null));
};
$tests['image validation and private statement deduplication'] = function () use ($pdo,$case) {
    refused(fn() => normalize_dispute_evidence('<svg onload="alert(1)"></svg>'));
    refused(fn() => normalize_dispute_evidence(str_repeat('x',2 * 1024 * 1024 + 1)));
    $image = imagecreatetruecolor(8,8);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $evidence = normalize_dispute_evidence($png . '<?php echo "trailer";');
    check($evidence['type'] === 'image/png' && !str_contains($evidence['bytes'],'trailer'));
    $key = str_repeat('b',32);
    submit_dispute_statement($pdo,$case,2,2,'Worker evidence.',$key,$evidence);
    submit_dispute_statement($pdo,$case,2,2,'Worker evidence.',$key,$evidence);
    submit_dispute_statement($pdo,$case,1,4,'Staff entered employer statement.',str_repeat('c',32),null);
    check((int)workflow_row($pdo,'SELECT COUNT(*) AS n FROM dispute_submissions WHERE dispute_id=?',[$case])['n'] === 2);
};
$tests['valid decision history, invalid transitions and reopening'] = function () use ($pdo,$case,$app,$job) {
    refused(fn() => decide_dispute($pdo,$case,1,0,'open','Invalid.'));
    refused(fn() => change_work_state($pdo,$job,$app,4,'resume'));
    decide_dispute($pdo,$case,1,0,'reviewing','Review started.');
    refused(fn() => decide_dispute($pdo,$case,1,0,'rejected','Stale form.'));
    decide_dispute($pdo,$case,1,1,'rejected','Rejected on the evidence.');
    refused(fn() => submit_dispute_statement($pdo,$case,2,2,'Closed statement.',str_repeat('d',32),null));
    decide_dispute($pdo,$case,1,2,'open','New information received.');
    check(dispute_case($pdo,$case,2)['status'] === 'open');
    check((int)workflow_row($pdo,'SELECT COUNT(*) AS n FROM dispute_decisions WHERE dispute_id=?',[$case])['n'] === 3);
};
$tests['concurrent staff attempts commit only one decision'] = function () use ($pdo,$otherJob,$race) {
    $commands = [];
    $pdo->beginTransaction();
    locked_job($pdo,$otherJob);
    foreach (['resolved','rejected'] as $next) {
        $child = proc_open([PHP_BINARY,__FILE__,'--decide',(string)$race,$next],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($child)) throw new RuntimeException('Could not launch competing reviewer.');
        $commands[] = [$child,$pipes];
    }
    usleep(250000);
    $pdo->commit();
    $out = [];
    foreach ($commands as [$child,$pipes]) {
        $out[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($child) !== 0) throw new RuntimeException($error);
    }
    sort($out);
    check($out === ['rejected','saved']);
    check((int)workflow_row($pdo,'SELECT COUNT(*) AS n FROM dispute_decisions WHERE dispute_id=?',[$race])['n'] === 1);
};
$tests['staff-only reconciliation preserves original and blocks duplicates'] = function () use ($pdo,$origin,$duplicate,$targetApp,$app) {
    $before = workflow_row($pdo,'SELECT p.*,j.renewed_from_id FROM payments p JOIN jobs j ON j.id=p.job_id WHERE p.id=?',[$origin]);
    $candidate = reconciliation_candidates($pdo,$before,$targetApp)[0];
    check($candidate['score'] >= 65 && count($candidate['reasons']) >= 3);
    refused(fn() => reconcile_payment($pdo,$origin,$targetApp,4,'Not staff.'));
    refused(fn() => reconcile_payment($pdo,$origin,$app,1,'Work not completed.'));
    reconcile_payment($pdo,$origin,$targetApp,1,'Matched ledger and completion details.');
    check($before === workflow_row($pdo,'SELECT p.*,j.renewed_from_id FROM payments p JOIN jobs j ON j.id=p.job_id WHERE p.id=?',[$origin]));
    $linked = workflow_row($pdo,'SELECT application_id,receipt_status FROM v_application_payments WHERE id=?',[$origin]);
    check((int)$linked['application_id'] === $targetApp && $linked['receipt_status'] === 'recorded');
    refused(fn() => record_payment($pdo,$targetApp,4,'18000','cash','',str_repeat('0',32)));
    refused(fn() => reconcile_payment($pdo,$origin,$targetApp,1,'Repeated.'));
    refused(fn() => reconcile_payment($pdo,$duplicate,$targetApp,1,'Application already linked.'));
};
$tests['audit records carry actor and state and reject rewrites'] = function () use ($pdo,$targetApp,$origin,$case) {
    $record = workflow_row($pdo,"SELECT * FROM audit_log WHERE action='payment.reconcile' AND entity_id=?",[$origin]);
    check((int)$record['actor_id'] === 1 && json_decode($record['meta'],true)['to_application_id'] === $targetApp);
    $decision = workflow_row($pdo,"SELECT meta FROM audit_log WHERE action='dispute.rejected' AND entity_id=?",[$case]);
    check(json_decode($decision['meta'],true)['from'] === 'reviewing');
    foreach (["UPDATE audit_log SET action='modified' WHERE id=?", 'DELETE FROM audit_log WHERE id=?'] as $sql) {
        $failed = false;
        try { $pdo->prepare($sql)->execute([$record['id']]); } catch (PDOException $error) { $failed = $error->getCode() === '45000'; }
        check($failed);
    }
};
$fail = 0;
foreach ($tests as $name=>$test) {
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $error) { ++$fail; echo "FAIL $name: {$error->getMessage()}\n"; }
}
echo (count($tests)-$fail) . " passed, $fail failed\n";
exit($fail ? 1 : 0);

