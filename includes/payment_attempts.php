<?php
require_once __DIR__ . '/mobile_money_sandbox.php';

function queue_gig_payment(PDO $pdo, QueuedPaymentProvider $provider, int $actor, int $application, string $key): int
{
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Finance permission required.');
    if ($provider->code()!=='mock') throw new DomainException('Gig obligations use the local mock only.');
    if (!preg_match('/\A[a-f0-9]{32}\z/',$key)) throw new DomainException('Refresh the payment form.');
    $ref = workflow_row($pdo,'SELECT job_id FROM applications WHERE id=?',[$application]);
    if (!$ref) throw new DomainException('Application not found.');
    return workflow_transaction($pdo,function () use ($pdo,$provider,$actor,$application,$key,$ref) {
        $job = locked_job($pdo,(int)$ref['job_id']);
        $app = locked_application($pdo,(int)$job['id'],$application);
        $existing = workflow_row($pdo,'SELECT a.id,o.application_id,a.provider FROM payment_attempts a
            JOIN payment_obligations o ON o.id=a.obligation_id WHERE a.actor_id=? AND a.request_key=?',[$actor,$key]);
        if ($existing) {
            if ((int)$existing['application_id'] !== $application || $existing['provider'] !== $provider->code()) {
                throw new DomainException('This request key belongs to another payment.');
            }
            return (int)$existing['id'];
        }
        if ($app['status'] !== 'completed' || $app['paid_at'] || workflow_row($pdo,
            'SELECT id FROM v_application_payments WHERE application_id=? AND receipt_status IS NOT NULL LIMIT 1',[$application])) {
            throw new DomainException('Choose completed work with no recorded payment.');
        }
        $obligation = workflow_row($pdo,'SELECT * FROM payment_obligations WHERE application_id=? FOR UPDATE',[$application]);
        if (!$obligation) {
            $pay = (string)$job['pay_amount'];
            if ($job['pay_type'] !== 'fixed' || !preg_match('/\A[1-9][0-9]{0,7}(?:\.00)?\z/',$pay)) {
                throw new DomainException('Queue processing requires a fixed whole-UGX amount.');
            }
            $pdo->prepare('INSERT INTO payment_obligations (application_id,amount_minor) VALUES (?,?)')
                ->execute([$application,(int)$pay]);
            $obligation = ['id'=>(int)$pdo->lastInsertId()];
        }
        if (workflow_row($pdo,"SELECT a.id FROM payment_attempts a LEFT JOIN provider_transactions t ON t.attempt_id=a.id
            WHERE a.obligation_id=? AND (a.state IN ('queued','sending','unknown')
            OR (a.state='accepted' AND COALESCE(t.status,'requested') NOT IN ('failed','cancelled','refunded','reversed')))
            LIMIT 1 FOR UPDATE",[$obligation['id']])) {
            throw new DomainException('This obligation has an active or successful provider attempt.');
        }
        $pdo->prepare('INSERT INTO payment_attempts (obligation_id,actor_id,request_key,dispatch_key,provider)
            VALUES (?,?,?,?,?)')->execute([$obligation['id'],$actor,$key,bin2hex(random_bytes(16)),$provider->code()]);
        $attempt = (int)$pdo->lastInsertId();
        schedule_payment_job($pdo,'initiate',$attempt);
        audit($pdo,'queue.payment','payment_attempt',$attempt,['obligation'=>(int)$obligation['id']],$actor);
        return $attempt;
    });
}

function request_payment_check(PDO $pdo, int $actor, int $attempt): void
{
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Finance permission required.');
    workflow_transaction($pdo,function () use ($pdo,$actor,$attempt) {
        $row = payment_attempt($pdo,$attempt);
        if ($row && $row['operation'] && !staff_can($pdo,$actor,sandbox_permission($row['operation']))) {
            throw new DomainException('Sandbox product permission required.');
        }
        if (!$row || !in_array($row['state'],['sending','unknown','accepted'],true)) {
            throw new DomainException('This attempt does not need reconciliation.');
        }
        $pdo->prepare("INSERT INTO payment_jobs (kind,attempt_id) VALUES ('status',?)
            ON DUPLICATE KEY UPDATE run_after=IF(status='processing',run_after,NOW(6)),
            attempts=IF(status IN ('dead','done'),0,attempts),
            status=IF(status='processing',status,'queued')")->execute([$attempt]);
        audit($pdo,'queue.reconcile','payment_attempt',$attempt,[],$actor);
    });
}

function payment_attempt(PDO $pdo, int $id, bool $lock = false): array|false
{
    if ($lock) {
        $relation = workflow_row($pdo,'SELECT obligation_id,sandbox_case_id FROM payment_attempts WHERE id=?',[$id]);
        if (!$relation) return false;
        if ($relation['obligation_id']) workflow_row($pdo,'SELECT id FROM payment_obligations WHERE id=? FOR UPDATE',[$relation['obligation_id']]);
        else workflow_row($pdo,'SELECT id FROM mobile_money_sandbox_cases WHERE id=? FOR UPDATE',[$relation['sandbox_case_id']]);
    }
    return workflow_row($pdo,"SELECT a.*,o.application_id,COALESCE(o.amount_minor,s.amount_minor) AS amount_minor,
        COALESCE(o.currency,'EUR') AS currency,s.operation,s.wallet_binding,t.id AS transaction_id,
        t.reference,t.status AS provider_status FROM payment_attempts a LEFT JOIN payment_obligations o ON o.id=a.obligation_id
        LEFT JOIN mobile_money_sandbox_cases s ON s.id=a.sandbox_case_id LEFT JOIN provider_transactions t ON t.attempt_id=a.id WHERE a.id=?" . ($lock?' FOR UPDATE':''),[$id]);
}

function attempt_state(PDO $pdo, int $id, string $from, string $to, ?string $diagnostic = null): void
{
    $pdo->prepare('UPDATE payment_attempts SET state=?,diagnostic=? WHERE id=?')->execute([$to,$diagnostic,$id]);
    if ($from !== $to || $diagnostic !== null) {
        $pdo->prepare("INSERT INTO audit_log (action,entity,entity_id,meta) VALUES ('queue.outcome','payment_attempt',?,?)")
            ->execute([$id,json_encode(['from'=>$from,'to'=>$to,'category'=>$diagnostic])]);
    }
}

function attach_queued_transaction(PDO $pdo, array $attempt, string $reference): void
{
    if (!preg_match('/\A[A-Za-z0-9_-]{1,80}\z/',$reference)) throw new PaymentQueueRetry('provider_conflict');
    $existing = workflow_row($pdo,'SELECT attempt_id FROM provider_transactions WHERE provider=? AND reference=? FOR UPDATE',
        [$attempt['provider'],$reference]);
    if ($existing && (int)$existing['attempt_id'] !== (int)$attempt['id']) throw new PaymentQueueRetry('provider_conflict');
    if ($attempt['reference'] !== null) {
        if ($attempt['reference'] !== $reference) throw new PaymentQueueRetry('provider_conflict');
        return;
    }
    $pdo->prepare('INSERT INTO provider_transactions (provider,reference,actor_id,request_key,amount_minor,currency,attempt_id)
        VALUES (?,?,?,?,?,?,?)')->execute([$attempt['provider'],$reference,$attempt['actor_id'],$attempt['dispatch_key'],
        $attempt['amount_minor'],$attempt['currency'],$attempt['id']]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO settlement_ledger (transaction_id,event_type,amount_minor,currency) VALUES (?,'requested',?,?)")
        ->execute([$id,$attempt['amount_minor'],$attempt['currency']]);
    $txn = workflow_row($pdo,'SELECT * FROM provider_transactions WHERE id=?',[$id]);
    apply_provider_events($pdo,$txn);
}

function run_payment_job(PDO $pdo, array $job, callable $providerFor): void
{
    $error = null;
    try {
        if ($job['kind'] === 'webhook') {
            $event = workflow_row($pdo,'SELECT * FROM provider_events WHERE id=?',[$job['event_id']]);
            try { $providerFor($event['provider']); }
            catch (DomainException $error) { throw new PaymentQueueRetry('provider_disabled'); }
            if (!workflow_row($pdo,'SELECT id FROM provider_transactions WHERE provider=? AND reference=?',
                [$event['provider'],$event['reference']])) {
                // The mock protocol binds the authenticated reference to the stable local dispatch key.
                recover_mock_webhook_reference($pdo,$job,$event);
            }
            process_provider_event($pdo,$event['provider'],event_input($event));
            $txn = workflow_row($pdo,'SELECT attempt_id,status FROM provider_transactions WHERE provider=? AND reference=?',
                [$event['provider'],$event['reference']]);
            if ($txn['attempt_id'] && in_array($txn['status'],['confirmed','received','refund_initiated'],true)) {
                schedule_payment_job($pdo,'settlement',(int)$txn['attempt_id']);
            }
        } elseif ($job['kind'] === 'initiate') {
            dispatch_payment_job($pdo,$job,$providerFor);
        } else {
            inspect_payment_job($pdo,$job,$providerFor);
        }
    } catch (PaymentQueueRetry $retry) {
        $category = $retry->getMessage();
        $error = in_array($category,['reference_pending','provider_conflict','provider_disabled','dispatch_pending',
            'status_unavailable','outcome_unknown','provider_pending','circuit_open','provider_throttle',
            'authentication_failed','invalid_provider_response','transport_unavailable','upstream_unavailable','sandbox_case_mismatch'],true) ? $category : 'internal_error';
    } catch (Throwable $failure) {
        // Never record raw provider responses, credentials, or transport exceptions.
        $error = 'internal_error';
    }
    finish_payment_job($pdo,$job,$error);
}

function recover_mock_webhook_reference(PDO $pdo, array $job, array $event): void
{
    if ($event['provider']!=='mock' || !preg_match('/\\Amock_([a-f0-9]{32})\\z/',$event['reference'],$parts)) {
        throw new PaymentQueueRetry('reference_pending');
    }
    workflow_transaction($pdo,function () use ($pdo,$job,$event,$parts) {
        if (!owns_payment_job($pdo,$job)) throw new PaymentQueueRetry('reference_pending');
        $attempt = workflow_row($pdo,"SELECT id FROM payment_attempts WHERE provider='mock' AND dispatch_key=?",[$parts[1]]);
        if (!$attempt) throw new PaymentQueueRetry('reference_pending');
        $item = payment_attempt($pdo,(int)$attempt['id'],true);
        if ($item['reference'] === $event['reference']) return;
        if (!in_array($item['state'],['sending','unknown'],true)) throw new PaymentQueueRetry('reference_pending');
        if ((int)$item['amount_minor'] !== (int)$event['amount_minor'] || $item['currency'] !== $event['currency']) {
            throw new PaymentQueueRetry('provider_conflict');
        }
        attach_queued_transaction($pdo,$item,$event['reference']);
        attempt_state($pdo,(int)$item['id'],$item['state'],'accepted');
    });
}

function dispatch_payment_job(PDO $pdo, array $job, callable $providerFor): void
{
    $ref = payment_attempt($pdo,(int)$job['attempt_id']);
    try { $provider = $providerFor($ref['provider']); }
    catch (DomainException $error) { throw new PaymentQueueRetry('provider_disabled'); }
    if ($ref['state']==='queued') reserve_sandbox_call($pdo,$ref);
    // Commit the sending fence before invoking a provider. An expired worker cannot re-send.
    $attempt = workflow_transaction($pdo,function () use ($pdo,$job) {
        if (!owns_payment_job($pdo,$job)) return null;
        $item = payment_attempt($pdo,(int)$job['attempt_id'],true);
        if ($item['state'] === 'sending') {
            attempt_state($pdo,(int)$item['id'],'sending','unknown','interrupted_dispatch');
            schedule_payment_job($pdo,'status',(int)$item['id']);
            return null;
        }
        if ($item['state'] !== 'queued') return null;
        if ($item['operation'] && !staff_can($pdo,(int)$item['actor_id'],sandbox_permission($item['operation']))) {
            attempt_state($pdo,(int)$item['id'],'queued','rejected','authorization_revoked');
            return null;
        }
        attempt_state($pdo,(int)$item['id'],'queued','sending');
        schedule_payment_job($pdo,'status',(int)$item['id'],null,90);
        return $item;
    });
    if (!$attempt) return;
    $category = null;
    try {
        $outcome = $provider->dispatchPayment($attempt);
    } catch (Throwable $failure) {
        $category = $attempt['sandbox_case_id'] ? sandbox_diagnostic($failure) : 'dispatch_unknown';
        $outcome = ['state'=>'unknown','diagnostic'=>$category];
    }
    note_sandbox_health($pdo,$attempt,$category);
    persist_provider_outcome($pdo,$job,$outcome,'sending');
}

function persist_provider_outcome(PDO $pdo, array $job, array $outcome, string $expected): void
{
    workflow_transaction($pdo,function () use ($pdo,$job,$outcome,$expected) {
        if (!owns_payment_job($pdo,$job)) return;
        $attempt = payment_attempt($pdo,(int)$job['attempt_id'],true);
        if ($attempt['state'] !== $expected) return;
        $state = $outcome['state'] ?? 'unknown';
        $id = (int)$attempt['id'];
        if (in_array($state,['accepted','pending'],true) && is_string($outcome['reference'] ?? null)) {
            attach_queued_transaction($pdo,$attempt,$outcome['reference']);
            $diagnostic = $state==='pending' ? 'provider_pending' : null;
            if ($attempt['provider_status']==='confirmed' && $attempt['sandbox_case_id']) $diagnostic = $attempt['diagnostic'];
            attempt_state($pdo,$id,$expected,'accepted',$diagnostic);
        } elseif ($state === 'rejected' && $attempt['reference'] === null) {
            attempt_state($pdo,$id,$expected,'rejected','provider_rejected');
        } elseif ($expected === 'sending') {
            attempt_state($pdo,$id,$expected,'unknown',$outcome['diagnostic'] ?? 'dispatch_unknown');
            $pdo->prepare("UPDATE payment_jobs SET run_after=NOW(6) WHERE kind='status' AND attempt_id=? AND status='queued'")
                ->execute([$id]);
        }
    });
}

function inspect_payment_job(PDO $pdo, array $job, callable $providerFor): void
{
    $attempt = payment_attempt($pdo,(int)$job['attempt_id']);
    if ($attempt['state'] === 'rejected') return;
    if (in_array($attempt['provider_status'],['failed','cancelled','refunded','reversed','settled'],true)) return;
    if ($attempt['state'] === 'queued') throw new PaymentQueueRetry('dispatch_pending');
    if ($attempt['state'] === 'sending') {
        workflow_transaction($pdo,function () use ($pdo,$job) {
            if (!owns_payment_job($pdo,$job)) return;
            $current = payment_attempt($pdo,(int)$job['attempt_id'],true);
            // A status job may start after a crashed dispatch. It never calls initiation.
            if ($current['state']==='sending' && !workflow_row($pdo,"SELECT id FROM payment_jobs
                WHERE kind='initiate' AND attempt_id=? AND status='processing' AND lease_until>NOW(6)",[$current['id']])) {
                attempt_state($pdo,(int)$current['id'],'sending','unknown','interrupted_dispatch');
            }
        });
        $attempt = payment_attempt($pdo,(int)$job['attempt_id']);
        if ($attempt['state']==='sending') throw new PaymentQueueRetry('dispatch_pending');
    }
    if ($job['kind'] === 'status' && !$attempt['sandbox_case_id'] && in_array($attempt['provider_status'],['confirmed','received','refund_initiated'],true)) {
        schedule_payment_job($pdo,'settlement',(int)$attempt['id']);
        return;
    }
    try { $provider = $providerFor($attempt['provider']); }
    catch (DomainException $error) { throw new PaymentQueueRetry('provider_disabled'); }
    reserve_sandbox_call($pdo,$attempt);
    try { $outcome = $provider->inspectPayment($attempt,$job['kind']==='settlement'); }
    catch (Throwable $error) {
        $category = $attempt['sandbox_case_id'] ? sandbox_diagnostic($error) : 'status_unavailable';
        note_sandbox_health($pdo,$attempt,$category);
        throw new PaymentQueueRetry($category);
    }
    if (($outcome['state'] ?? null)==='unsupported' && $attempt['sandbox_case_id'] && $job['kind']==='settlement') {
        workflow_transaction($pdo,function () use ($pdo,$job,$attempt) {
            if (owns_payment_job($pdo,$job)) attempt_state($pdo,(int)$attempt['id'],$attempt['state'],
                $attempt['state'],'settlement_evidence_unavailable');
        });
        return;
    }
    note_sandbox_health($pdo,$attempt,null);
    persist_provider_outcome($pdo,$job,$outcome,$attempt['state']);
    $current = payment_attempt($pdo,(int)$attempt['id']);
    foreach ($outcome['events'] ?? [] as $event) {
        if ($current['reference'] === null || $current['reference'] !== $event['reference']) {
            throw new PaymentQueueRetry('provider_conflict');
        }
        if (process_provider_event($pdo,$attempt['provider'],$event) === 'failed') throw new PaymentQueueRetry('provider_conflict');
    }
    $current = payment_attempt($pdo,(int)$attempt['id']);
    if (in_array($current['provider_status'],['failed','cancelled','refunded','reversed','settled'],true) || $current['state']==='rejected') return;
    if ($job['kind']==='status' && in_array($current['provider_status'],['confirmed','received','refund_initiated'],true)) {
        schedule_payment_job($pdo,'settlement',(int)$current['id']);
        return;
    }
    throw new PaymentQueueRetry($current['state']==='unknown'?'outcome_unknown':'provider_pending');
}

