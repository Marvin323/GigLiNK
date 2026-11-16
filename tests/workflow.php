<?php
declare(strict_types=1);
require __DIR__ . '/../includes/payments.php';
require __DIR__ . '/../includes/job_search.php';

$db = getenv('GIGLINK_TEST_DB') ?: '';
$port = getenv('GIGLINK_TEST_PORT') ?: '33317';
if (!preg_match('/\Agiglink_qa_[a-z0-9]+\z/', $db)) exit("Refusing to use a non-QA database.\n");
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$db;charset=utf8mb4",
    getenv('GIGLINK_TEST_USER') ?: 'root', getenv('GIGLINK_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
if (($argv[1] ?? '') === '--accept') {
    [, , $job, $application, $employer] = $argv;
    try {
        change_work_state($pdo, (int)$job, (int)$application, (int)$employer, 'accepted');
        echo 'accepted';
    } catch (DomainException $error) {
        echo $error->getMessage();
    }
    exit;
}

function expect(bool $condition): void {
    if (!$condition) throw new RuntimeException('Assertion failed.');
}
function denied(callable $op): void {
    try { $op(); } catch (DomainException $e) { return; }
    throw new RuntimeException('The operation should have been rejected.');
}
function make_job(PDO $pdo, int $employer, string $title, int $slots, int $amount = 10000): int {
    $pdo->prepare("INSERT INTO jobs(employer_id,category_id,location_id,title,description,workers_needed,pay_amount,
        start_date,created_at) VALUES (?,1,1,?,'Example',?,?,'2030-01-01','2026-01-01')")
        ->execute([$employer,$title,$slots,$amount]);
    return (int)$pdo->lastInsertId();
}
function make_application(PDO $pdo, int $job, int $worker): int {
    apply_for_job($pdo,$job,$worker,'Available to work.');
    return (int)workflow_row($pdo,'SELECT id FROM applications WHERE job_id=? AND youth_id=?',[$job,$worker])['id'];
}

$users = array_column($pdo->query('SELECT id,username FROM users')->fetchAll(), 'id','username');
[$alice,$brian,$mama,$other] = array_map('intval', [$users['alice'],$users['brian'],$users['mama'],$users['shopowner']]);
$prefix = 'QA' . bin2hex(random_bytes(4));
$single = make_job($pdo,$mama,$prefix . ' Single',1);
$a = make_application($pdo,$single,$alice);
$b = make_application($pdo,$single,$brian);
$wrongJob = make_job($pdo,$mama,$prefix . ' Different',1,20000);
$test = [];
$test['legacy records survive and link only to exact participant matches'] = function () use ($pdo) {
    $payment = workflow_row($pdo, "SELECT application_id, receipt_status, created_at, updated_at FROM payments WHERE reference='QA historical receipt'", []);
    expect($payment['application_id'] !== null && $payment['receipt_status'] === 'recorded');
    expect($payment['created_at'] === '2024-05-01 12:00:00' && $payment['updated_at'] === '2024-05-02 12:00:00');
    $unmatched = workflow_row($pdo, "SELECT application_id FROM payments WHERE reference='QA unmatched receipt'", []);
    expect($unmatched['application_id'] === null);
    $count = workflow_row($pdo, 'SELECT COUNT(*) AS total FROM payments WHERE application_id=?', [$payment['application_id']]);
    expect((int)$count['total'] === 2);
};
$test['application retry preserves original'] = fn() => expect(apply_for_job($pdo,$single,$alice,'Duplicate') === null);
$test['invalid application/job association'] = fn() => denied(fn() => change_work_state($pdo,$wrongJob,$a,$mama,'accepted'));
$test['unauthorized employer cannot hire'] = fn() => denied(fn() => change_work_state($pdo,$single,$a,$other,'accepted'));
$test['concurrent contenders for one slot'] = function () use ($pdo,$single,$a,$b,$mama) {
    $processes = [];
    $pdo->beginTransaction();
    locked_job($pdo,$single);
    foreach ([$a,$b] as $app) {
        $proc = proc_open([PHP_BINARY,__FILE__,'--accept',(string)$single,(string)$app,(string)$mama],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        expect(is_resource($proc));
        $processes[] = [$proc,$pipes];
    }
    usleep(250000);
    $pdo->commit();
    $outputs = [];
    foreach ($processes as [$proc,$pipes]) {
        $outputs[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($proc) !== 0) throw new RuntimeException($error);
    }
    sort($outputs);
    expect($outputs === ['This gig has no open hiring slots.','accepted']);
};
$accepted = fn() => workflow_row($pdo,"SELECT id,youth_id FROM applications WHERE job_id=? AND status IN ('accepted','in_progress','completed','disputed')",[$single]);
$test['within capacity marks job filled'] = fn() => expect($accepted() !== false
    && workflow_row($pdo,'SELECT status FROM jobs WHERE id=?',[$single])['status'] === 'filled');
$test['repeat acceptance is rejected'] = fn() => denied(fn() => change_work_state($pdo,$single,(int)$accepted()['id'],$mama,'accepted'));
$test['accepted work gives no reputation credit'] = function () use ($pdo,$accepted) {
    $worker = (int)$accepted()['youth_id'];
    $actual = workflow_row($pdo,"SELECT COUNT(*) AS total FROM applications WHERE youth_id=? AND status='completed'",[$worker]);
    $summary = workflow_row($pdo,'SELECT jobs_completed FROM v_worker_summary WHERE user_id=?',[$worker]);
    expect((int)$summary['jobs_completed'] === (int)$actual['total']);
};
$test['stale work forms cannot replay after a dispute is resumed'] = function () use ($pdo,$mama,$alice) {
    $job = make_job($pdo,$mama,'Stale form test',1);
    $app = make_application($pdo,$job,$alice);
    change_work_state($pdo,$job,$app,$mama,'accepted');
    change_work_state($pdo,$job,$app,$alice,'in_progress',false,1);
    change_work_state($pdo,$job,$app,$alice,'disputed',false,2);
    $case = workflow_row($pdo,'SELECT id FROM disputes WHERE active_work_id=?',[$app]);
    decide_dispute($pdo,(int)$case['id'],1,0,'resolved','Reviewed both parties.');

    denied(fn() => change_work_state($pdo,$job,$app,$alice,'disputed',false,2));
};
$test['invalid completion before work starts'] = fn() => denied(fn() => change_work_state($pdo,$single,(int)$accepted()['id'],$mama,'completed'));
$test['work starts, can be disputed, and resumes'] = function () use ($pdo,$single,$accepted,$mama) {
    $id = (int)$accepted()['id'];
    $worker = (int)$accepted()['youth_id'];
    change_work_state($pdo,$single,$id,$worker,'in_progress');
    change_work_state($pdo,$single,$id,$worker,'disputed');
    $case = workflow_row($pdo,'SELECT id FROM disputes WHERE active_work_id=?',[$id]);
    decide_dispute($pdo,(int)$case['id'],1,0,'resolved','Reviewed both parties.');
};
$test['employer completes without payment or rating'] = function () use ($pdo,$single,$accepted,$mama) {
    $id = (int)$accepted()['id'];
    $worker = (int)$accepted()['youth_id'];
    $before = (int)workflow_row($pdo,'SELECT jobs_completed FROM v_worker_summary WHERE user_id=?',[$worker])['jobs_completed'];
    change_work_state($pdo,$single,$id,$mama,'completed');
    $app = workflow_row($pdo,'SELECT * FROM applications WHERE id=?',[$id]);
    expect($app['completed_by'] == $mama && $app['completed_at'] !== null && $app['paid_at'] === null && $app['employer_rated_at'] === null);
    expect((int)workflow_row($pdo,'SELECT jobs_completed FROM v_worker_summary WHERE user_id=?',[$worker])['jobs_completed'] === $before + 1);
};
$test['completion cannot be repeated'] = fn() => denied(fn() => change_work_state($pdo,$single,(int)$accepted()['id'],$mama,'completed'));
$key = bin2hex(random_bytes(16));
$test['unauthorized employer cannot record payment'] = fn() => denied(fn() =>
    record_payment($pdo,(int)$accepted()['id'],$other,'10000','cash','',$key));
$test['manual payment records exact application and no provider verification'] = function () use ($pdo,$accepted,$mama,$key) {
    $id = (int)$accepted()['id'];
    expect(record_payment($pdo,$id,$mama,'10000','cash','Paid in person',$key));
    $payment = workflow_row($pdo,'SELECT * FROM payments WHERE application_id=?',[$id]);
    expect($payment['receipt_status'] === 'recorded' && (float)$payment['amount'] === 10000.0);
};
$test['retry is idempotent and changed payload is rejected'] = function () use ($pdo,$accepted,$mama,$key) {
    $id = (int)$accepted()['id'];
    expect(!record_payment($pdo,$id,$mama,'10000.00','cash','Paid in person',$key));
    denied(fn() => record_payment($pdo,$id,$mama,'10001','cash','Paid in person',$key));
    denied(fn() => record_payment($pdo,$id,$mama,'10000','cash','Paid in person',bin2hex(random_bytes(16))));
};
$payment = fn() => workflow_row($pdo,'SELECT * FROM payments WHERE application_id=?',[$accepted()['id']]);
$test['only worker can confirm and retries preserve confirmation'] = function () use ($pdo,$accepted,$mama,$payment) {
    $id = (int)$payment()['id'];
    $worker = (int)$accepted()['youth_id'];
    denied(fn() => respond_to_payment($pdo,$id,$mama,'confirmed',''));
    expect(respond_to_payment($pdo,$id,$worker,'confirmed',''));
    expect(!respond_to_payment($pdo,$id,$worker,'confirmed',''));
    expect($payment()['receipt_status'] === 'confirmed' && $payment()['receipt_at'] !== null);
};
$test['renewed gig retains separate application and payment history'] = function () use ($pdo,$single,$accepted,$mama,$payment) {
    $prior = $payment();
    $worker = (int)$accepted()['youth_id'];
    $renewed = close_or_renew_job($pdo,$single,$mama,true);
    expect($renewed !== $single && close_or_renew_job($pdo,$single,$mama,true) === $renewed);
    $next = make_application($pdo,$renewed,$worker);
    change_work_state($pdo,$renewed,$next,$mama,'accepted');
    change_work_state($pdo,$renewed,$next,$worker,'in_progress');
    change_work_state($pdo,$renewed,$next,$mama,'completed');
    record_payment($pdo,$next,$mama,'15000','bank','Round two',bin2hex(random_bytes(16)));
    $new = workflow_row($pdo,'SELECT * FROM payments WHERE application_id=?',[$next]);
    expect($prior['id'] !== $new['id'] && $prior['receipt_status'] === 'confirmed');
    expect(respond_to_payment($pdo,(int)$new['id'],$worker,'disputed','Amount received differs.'));
    expect(workflow_row($pdo,'SELECT receipt_status FROM payments WHERE id=?',[$new['id']])['receipt_status'] === 'disputed');
};
$test['review count reflects a completed employer review'] = function () use ($pdo,$single,$accepted,$mama) {
    $worker = (int)$accepted()['youth_id'];
    $before = (int)workflow_row($pdo,'SELECT rating_count FROM v_worker_summary WHERE user_id=?',[$worker])['rating_count'];
    $pdo->prepare('INSERT INTO ratings(rater_user_id,ratee_user_id,job_id,score) VALUES (?,?,?,5)')
        ->execute([$mama,$worker,$single]);
    expect((int)workflow_row($pdo,'SELECT rating_count FROM v_worker_summary WHERE user_id=?',[$worker])['rating_count'] === $before + 1);
};
$test['filters, deterministic sorting and pagination'] = function () use ($pdo,$prefix,$wrongJob,$mama) {
    $lower = make_job($pdo,$mama,$prefix . ' Other match',1,12000);
    $tie = make_job($pdo,$mama,$prefix . ' Pay tie',1,20000);
    $result = job_search($pdo,['q'=>$prefix,'category'=>1,'location'=>1,'minpay'=>11000,'sort'=>'pay','p'=>2,'per'=>1]);
    expect($result['total'] === 3 && (int)$result['jobs'][0]['id'] === $wrongJob);
    expect((int)job_search($pdo,['q'=>$prefix,'sort'=>'soonest'])['jobs'][0]['id'] === $tie);
    expect((int)job_search($pdo,['q'=>$prefix,'sort'=>'pay','per'=>1])['jobs'][0]['id'] === $tie);
    expect($lower < $tie);
};
$failed = 0;
foreach ($test as $label => $run) {
    try {
        $run();
        echo "PASS $label\n";
    } catch (Throwable $error) {
        ++$failed;
        echo "FAIL $label: {$error->getMessage()}\n";
    }
}
echo (count($test) - $failed) . " passed, $failed failed\n";
exit($failed ? 1 : 0);

