<?php
require_once __DIR__ . '/payment_queue.php';
require_once __DIR__ . '/mtn_sandbox.php';

function sandbox_permission(string $operation): string
{
    return match ($operation) {
        'collection'=>'sandbox.collection',
        'disbursement'=>'sandbox.disbursement',
        default=>throw new DomainException('Unsupported sandbox operation.'),
    };
}

function queue_mtn_case(PDO $pdo, MtnSandbox $provider, int $actor, int $amount, string $key): int
{
    $operation = $provider->operation();
    if (!staff_can($pdo,$actor,sandbox_permission($operation))) throw new DomainException('Sandbox product permission required.');
    if ($amount<1 || $amount>10000 || !preg_match('/\A[a-f0-9]{32}\z/',$key)) {
        throw new DomainException('Choose an amount between 1 and 10,000 EUR cents.');
    }
    $code = $provider->code();
    $binding = $provider->binding();
    return workflow_transaction($pdo,function () use ($pdo,$operation,$code,$binding,$actor,$amount,$key) {
        workflow_row($pdo,'SELECT id FROM users WHERE id=? FOR UPDATE',[$actor]);
        workflow_row($pdo,'SELECT provider FROM mobile_money_health WHERE provider=? FOR UPDATE',[$code]);
        $existing = workflow_row($pdo,'SELECT a.id,a.provider,s.amount_minor,s.wallet_binding FROM payment_attempts a
            LEFT JOIN mobile_money_sandbox_cases s ON s.id=a.sandbox_case_id WHERE a.actor_id=? AND a.request_key=?',[$actor,$key]);
        if ($existing) {
            if ($existing['provider']!==$code || (int)$existing['amount_minor']!==$amount || $existing['wallet_binding']!==$binding) {
                throw new DomainException('That request key has different details.');
            }
            return (int)$existing['id'];
        }
        $recent = workflow_row($pdo,'SELECT COUNT(*) AS n FROM payment_attempts WHERE provider=? AND created_at>=NOW()-INTERVAL 1 HOUR',[$code]);
        if ((int)$recent['n'] >= 20) throw new DomainException('This sandbox product has reached its hourly queue limit.');
        $unresolved = workflow_row($pdo,"SELECT a.id FROM payment_attempts a
            JOIN mobile_money_sandbox_cases s ON s.id=a.sandbox_case_id LEFT JOIN provider_transactions t ON t.attempt_id=a.id
            WHERE a.provider=? AND s.wallet_binding=? AND (a.state IN ('queued','sending','unknown')
            OR (a.state='accepted' AND COALESCE(t.status,'requested')='requested')) LIMIT 1",[$code,$binding]);
        if ($unresolved) throw new DomainException('Reconcile the existing sandbox attempt for this test wallet first.');
        $pdo->prepare('INSERT INTO mobile_money_sandbox_cases (operation,amount_minor,wallet_binding) VALUES (?,?,?)')
            ->execute([$operation,$amount,$binding]);
        $case = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payment_attempts (sandbox_case_id,actor_id,request_key,dispatch_key,provider)
            VALUES (?,?,?,?,?)')->execute([$case,$actor,$key,bin2hex(random_bytes(16)),$code]);
        $id = (int)$pdo->lastInsertId();
        schedule_payment_job($pdo,'initiate',$id);
        audit($pdo,'sandbox.request','payment_attempt',$id,['mode'=>'sandbox','operation'=>$operation],$actor);
        return $id;
    });
}

function reserve_sandbox_call(PDO $pdo, array $attempt): void
{
    if (!$attempt['sandbox_case_id']) return;
    workflow_transaction($pdo,function () use ($pdo,$attempt) {
        $state = workflow_row($pdo,'SELECT opened_until>NOW(6) AS open,next_call_at>NOW(6) AS busy
            FROM mobile_money_health WHERE provider=? FOR UPDATE',[$attempt['provider']]);
        if ($state['open']) throw new PaymentQueueRetry('circuit_open');
        if ($state['busy']) throw new PaymentQueueRetry('provider_throttle');
        $pdo->prepare('UPDATE mobile_money_health SET next_call_at=NOW(6)+INTERVAL 1 SECOND WHERE provider=?')
            ->execute([$attempt['provider']]);
    });
}

function sandbox_diagnostic(Throwable $error): string
{
    if (!$error instanceof SandboxProviderFailure) return 'transport_unavailable';
    return in_array($error->getMessage(),['authentication_failed','transport_unavailable','upstream_unavailable',
        'invalid_provider_response','beneficiary_unverified','sandbox_case_mismatch','provider_conflict','dispatch_unknown'],true)
        ? $error->getMessage() : 'transport_unavailable';
}

function note_sandbox_health(PDO $pdo, array $attempt, ?string $category): void
{
    if (!$attempt['sandbox_case_id']) return;
    workflow_transaction($pdo,function () use ($pdo,$attempt,$category) {
        $row = workflow_row($pdo,'SELECT failures FROM mobile_money_health WHERE provider=? FOR UPDATE',[$attempt['provider']]);
        $counts = in_array($category,['authentication_failed','transport_unavailable','upstream_unavailable','invalid_provider_response'],true);
        $failures = $counts ? (int)$row['failures']+1 : 0;
        $pdo->prepare('UPDATE mobile_money_health SET failures=?,opened_until=IF(? >= 3,NOW(6)+INTERVAL 5 MINUTE,NULL),
            last_category=?,checked_at=NOW(6) WHERE provider=?')->execute([$failures,$failures,$category,$attempt['provider']]);
    });
}

