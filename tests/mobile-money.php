<?php
declare(strict_types=1);
require __DIR__ . '/../includes/payment_attempts.php';
require __DIR__ . '/../includes/config.php';

$db = getenv('GIGLINK_TEST_DB') ?: '';
if (!preg_match('/\Agiglink_qa_[a-z0-9]+\z/',$db)) exit(1);
$port = (int)(getenv('GIGLINK_TEST_PORT') ?: 33317);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$db;charset=utf8mb4",'root','',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function expect_mtn(bool $condition): void {
    if (!$condition) throw new RuntimeException('Assertion failed.');
}
function blocks_mtn(callable $callback, ?string $category=null): void {
    try { $callback(); } catch (DomainException|SandboxProviderFailure|PaymentQueueRetry|UnexpectedValueException $error) {
        if ($category!==null) expect_mtn($error->getMessage()===$category);
        return;
    }
    throw new RuntimeException('Expected operation to fail.');
}
function fake_product_config(string $product): array {
    // Contract fixtures. The fake transport never sends these paths or values over a network.
    return ['user'=>'test-user','api_key'=>'test-secret','subscription'=>'test-product-key',
        'binding_key'=>str_repeat('b',32),'wallet'=>'12345678901',
        'token_path'=>"/$product/test-token",'status_path'=>"/$product/test-status/{reference}",
        'active_path'=>"/$product/test-account/{msisdn}",'active_field'=>'result'];
}
function fake_attempt(MtnSandbox $provider): array {
    return ['operation'=>$provider->operation(),'wallet_binding'=>$provider->binding(),'currency'=>'EUR',
        'amount_minor'=>1900,'dispatch_key'=>str_repeat('a',32),'reference'=>null];
}
function fake_token(): array { return [200,'{"access_token":"test-token","token_type":"Bearer","expires_in":60}']; }
function fake_status(array $attempt,string $status): array {
    $field = $attempt['operation']==='collection'?'payer':'payee';
    return [200,json_encode(['amount'=>'19.00','currency'=>'EUR','externalId'=>$attempt['dispatch_key'],
        $field=>['partyIdType'=>'MSISDN','partyId'=>'12345678901'],'status'=>$status])];
}
function run_sandbox_job(PDO $pdo,int $attempt,string $kind,MtnSandbox $provider): void {
    $pdo->exec("UPDATE payment_jobs SET run_after=NOW()+INTERVAL 1 DAY WHERE status='queued'");
    $pdo->prepare('UPDATE payment_jobs SET run_after=NOW(6) WHERE attempt_id=? AND kind=?')->execute([$attempt,$kind]);
    $pdo->prepare('UPDATE mobile_money_health SET next_call_at=NULL WHERE provider=?')->execute([$provider->code()]);
    $job = claim_payment_job($pdo);
    expect_mtn($job && (int)$job['attempt_id']===$attempt && $job['kind']===$kind);
    run_payment_job($pdo,$job,fn()=> $provider);
}

$checks = [];
$checks['MTN typed paths fail closed and Airtel stays disabled'] = function () {
    $bad = fake_product_config('collection');
    $bad['status_path'] = 'https://provider.example/{reference}';
    blocks_mtn(fn()=>new MtnSandbox('collection',$bad,fn()=>[],time(...)));
    blocks_mtn(fn()=>queued_payment_provider('airtel_disbursement'));
};
$checks['collection token expiry refreshes credentials and request acceptance adds no evidence'] = function () {
    $now = 100;
    $auth = $sends = 0;
    $provider = new MtnSandbox('collection',fake_product_config('collection'),
        function ($method,$path,$headers,$raw) use (&$auth,&$sends) {
            if (str_ends_with($path,'test-token')) {
                ++$auth;
                expect_mtn(in_array('Authorization: Basic ' . base64_encode('test-user:test-secret'),$headers,true));
                return fake_token();
            }
            ++$sends;
            $body = json_decode($raw,true);
            expect_mtn($method==='POST' && $path==='/collection/v1_0/requesttopay'
                && $body['amount']==='19.00' && isset($body['payer']) && !isset($body['payee']));
            return [202,''];
        },function () use (&$now) { return $now; });
    $case = fake_attempt($provider);
    $result = $provider->dispatchPayment($case);
    expect_mtn($result === ['state'=>'accepted','reference'=>MtnSandbox::reference($case['dispatch_key'])]);
    $now+=10; $provider->dispatchPayment($case);
    $now+=100; $provider->dispatchPayment($case);
    expect_mtn($auth===2 && $sends===3);
};
$checks['authentication rejection, malformed token and unknown status are safe'] = function () {
    $auth = new MtnSandbox('collection',fake_product_config('collection'),fn()=>[401,''],time(...));
    blocks_mtn(fn()=> $auth->inspectPayment(fake_attempt($auth),false),'authentication_failed');
    $bad = new MtnSandbox('collection',fake_product_config('collection'),fn()=>[200,'{"access_token":[]}'],time(...));
    blocks_mtn(fn()=> $bad->dispatchPayment(fake_attempt($bad)),'invalid_provider_response');
    $missing = new MtnSandbox('collection',fake_product_config('collection'),
        fn($m,$path)=>str_ends_with($path,'test-token')?fake_token():[404,''],time(...));
    expect_mtn($missing->inspectPayment(fake_attempt($missing),false)===['state'=>'unknown']);
    blocks_mtn(fn()=> $missing->verifyWebhook('{}',[],0));
};
$checks['beneficiary eligibility and immutable configured wallet precede transfer'] = function () {
    $calls = 0;
    $provider = new MtnSandbox('disbursement',fake_product_config('disbursement'),function ($m,$path) use (&$calls) {
        ++$calls;
        return str_ends_with($path,'test-token')?fake_token():[200,'{"result":false}'];
    },time(...));
    $case = fake_attempt($provider);
    blocks_mtn(fn()=> $provider->dispatchPayment($case),'beneficiary_unverified');
    expect_mtn($calls===2);
    $case['wallet_binding'] = str_repeat('3',64);
    blocks_mtn(fn()=> $provider->dispatchPayment($case),'sandbox_case_mismatch');
    expect_mtn($calls===2);
};
$checks['authenticated polling refreshes safely and validates type, amount and correlation'] = function () {
    $responses = 0;
    $case = null;
    $provider = new MtnSandbox('collection',fake_product_config('collection'),function ($m,$path) use (&$responses,&$case) {
        if (str_ends_with($path,'test-token')) return fake_token();
        ++$responses;
        if ($responses===1) return [401,''];
        if ($responses===2) return fake_status($case,'PENDING');
        if ($responses===3) return fake_status($case,'SUCCESSFUL');
        if ($responses===4) return fake_status($case,'FAILED');
        return [200,'{"status":"SUCCESSFUL"}'];
    },time(...));
    $case = fake_attempt($provider);
    expect_mtn($provider->inspectPayment($case,false)['state']==='pending');
    expect_mtn($provider->inspectPayment($case,false)['events'][0]['type']==='confirmed');
    expect_mtn($provider->inspectPayment($case,false)['events'][0]['type']==='failed');
    blocks_mtn(fn()=> $provider->inspectPayment($case,false),'invalid_provider_response');
    expect_mtn($provider->inspectPayment($case,true)===['state'=>'unsupported']);
};
$checks['queue separates collection permissions and disbursement remains single-send after timeout'] = function () use ($pdo) {
    $officer = (int)workflow_row($pdo,"SELECT id FROM users WHERE username='qa_finance'",[])['id'];
    $callbackCase = null;
    $response = 'SUCCESSFUL';
    $sends = 0;
    $provider = new MtnSandbox('disbursement',fake_product_config('disbursement'),
        function ($method,$path,$headers,$raw) use (&$sends,&$callbackCase,&$response) {
            if (str_ends_with($path,'test-token')) return fake_token();
            if ($method==='GET' && str_contains($path,'test-account')) return [200,'{"result":true}'];
            if ($method==='POST') {
                ++$sends;
                expect_mtn(isset(json_decode($raw,true)['payee']));
                throw new SandboxProviderFailure('transport_unavailable');
            }
            return fake_status($callbackCase,$response);
        },time(...));
    blocks_mtn(fn()=>queue_mtn_case($pdo,$provider,$officer,1900,str_repeat('7',32)));
    $id = queue_mtn_case($pdo,$provider,1,1900,str_repeat('7',32));
    expect_mtn(queue_mtn_case($pdo,$provider,1,1900,str_repeat('7',32))===$id);
    run_sandbox_job($pdo,$id,'initiate',$provider);
    $callbackCase = payment_attempt($pdo,$id);
    expect_mtn($callbackCase['state']==='unknown' && $callbackCase['obligation_id']===null && $sends===1);
    blocks_mtn(fn()=>queue_mtn_case($pdo,$provider,1,1900,str_repeat('8',32)));
    blocks_mtn(fn()=>request_payment_check($pdo,$officer,$id));
    run_sandbox_job($pdo,$id,'status',$provider);
    run_sandbox_job($pdo,$id,'settlement',$provider);
    $row = payment_attempt($pdo,$id);
    expect_mtn($row['provider_status']==='confirmed' && $row['diagnostic']==='settlement_evidence_unavailable' && $sends===1);
    $response = 'FAILED';
    request_payment_check($pdo,1,$id);
    run_sandbox_job($pdo,$id,'status',$provider);
    $failed = workflow_row($pdo,"SELECT outcome FROM provider_events WHERE provider=? AND reference=? AND event_type='failed'",
        [$provider->code(),$row['reference']]);
    expect_mtn(payment_attempt($pdo,$id)['provider_status']==='confirmed'
        && $failed['outcome']==='Incompatible with transaction state' && $sends===1);
};
$checks['collection permissions are distinct and revoked access stops queued dispatch'] = function () use ($pdo) {
    $officer = (int)workflow_row($pdo,"SELECT id FROM users WHERE username='qa_finance'",[])['id'];
    $calls = 0;
    $provider = new MtnSandbox('collection',fake_product_config('collection'),function () use (&$calls) {
        ++$calls;
        return [500,''];
    },time(...));
    blocks_mtn(fn()=>queue_mtn_case($pdo,$provider,2,1900,str_repeat('4',32)));
    $attempt = queue_mtn_case($pdo,$provider,$officer,1900,str_repeat('4',32));
    change_staff($pdo,1,$officer,'auditor',true);
    try {
        run_sandbox_job($pdo,$attempt,'initiate',$provider);
        $row = payment_attempt($pdo,$attempt);
        expect_mtn($calls===0 && $row['state']==='rejected' && $row['diagnostic']==='authorization_revoked');
    } finally {
        change_staff($pdo,1,$officer,'finance_officer',true);
    }
};
$checks['provider references and circuit limits are isolated between products'] = function () use ($pdo) {
    $reference = 'contract-cross-product-reference';
    $stmt = $pdo->prepare("INSERT INTO provider_transactions (provider,reference,actor_id,request_key,amount_minor,currency)
        VALUES (?,?,1,?,1900,'EUR')");
    foreach (['mtn_collection','mtn_disbursement'] as $code) {
        $stmt->execute([$code,$reference,md5($code)]);
    }
    expect_mtn((int)workflow_row($pdo,'SELECT COUNT(*) AS n FROM provider_transactions WHERE reference=?',[$reference])['n']===2);
    $id = (int)workflow_row($pdo,"SELECT id FROM payment_attempts WHERE provider='mtn_disbursement' ORDER BY id DESC LIMIT 1",[])['id'];
    $attempt = payment_attempt($pdo,$id);
    for ($i=0;$i<3;++$i) note_sandbox_health($pdo,$attempt,'authentication_failed');
    blocks_mtn(fn()=>reserve_sandbox_call($pdo,$attempt),'circuit_open');
    $pdo->exec("UPDATE mobile_money_health SET next_call_at=NULL WHERE provider='mtn_collection'");
    $collection = ['sandbox_case_id'=>1,'provider'=>'mtn_collection'];
    reserve_sandbox_call($pdo,$collection);
    blocks_mtn(fn()=>reserve_sandbox_call($pdo,$collection),'provider_throttle');
    $pdo->exec("UPDATE payment_jobs SET run_after=NOW()+INTERVAL 1 DAY WHERE status='queued'");
};
$failed = 0;
foreach ($checks as $name=>$test) {
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $error) { ++$failed; echo "FAIL $name: {$error->getMessage()}\n"; }
}
echo (count($checks)-$failed) . " passed, $failed failed\n";
exit($failed?1:0);

