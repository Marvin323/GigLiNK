<?php
require_once __DIR__ . '/settlement.php';

final class PaymentQueueRetry extends RuntimeException {}

function schedule_payment_job(PDO $pdo, string $kind, ?int $attempt, ?int $event = null, int $delay = 0): void
{
    $pdo->prepare('INSERT INTO payment_jobs (kind,attempt_id,event_id,run_after)
        VALUES (?,?,?,TIMESTAMPADD(SECOND,?,NOW(6))) ON DUPLICATE KEY UPDATE id=id')
        ->execute([$kind,$attempt,$event,$delay]);
}

function claim_payment_job(PDO $pdo, int $lease = 90): ?array
{
    for ($try=0;;++$try) {
        try {
            $token = bin2hex(random_bytes(16));
            return workflow_transaction($pdo, function () use ($pdo,$token,$lease) {
                workflow_row($pdo,'SELECT id FROM payment_queue_claim_lock WHERE id=1 FOR UPDATE',[]);
                $pdo->exec("UPDATE payment_jobs SET status='dead',lock_owner=NULL,lease_until=NULL,last_error='lease_exhausted'
                    WHERE status='processing' AND lease_until<=NOW(6) AND attempts>=max_attempts LIMIT 25");
                $pdo->prepare("UPDATE payment_jobs SET status='processing',attempts=attempts+1,
                    lock_owner=?,lease_until=TIMESTAMPADD(SECOND,?,NOW(6))
                    WHERE attempts<max_attempts AND ((status='queued' AND run_after<=NOW(6))
                        OR (status='processing' AND lease_until<=NOW(6))) ORDER BY run_after,id LIMIT 1")
                    ->execute([$token,$lease]);
                return workflow_row($pdo,'SELECT * FROM payment_jobs WHERE lock_owner=?',[$token]) ?: null;
            });
        } catch (PDOException $error) {
            if ($try >= 4 || !in_array((int)($error->errorInfo[1] ?? 0),[1205,1213],true)) throw $error;
            usleep(random_int(10000,50000));
        }
    }
}

function owns_payment_job(PDO $pdo, array $job): bool
{
    return (bool)workflow_row($pdo,"SELECT id FROM payment_jobs WHERE id=? AND lock_owner=?
        AND status='processing' AND lease_until>NOW(6) FOR UPDATE",[$job['id'],$job['lock_owner']]);
}

function finish_payment_job(PDO $pdo, array $job, ?string $error): void
{
    $state = !$error ? 'done' : ($job['attempts'] >= $job['max_attempts'] ? 'dead' : 'queued');
    $base = min(900,5 * (2 ** min(8,(int)$job['attempts'] - 1)));
    $delay = $state === 'queued' ? $base + random_int(0,intdiv($base,4)) : 0;
    $pdo->prepare("UPDATE payment_jobs SET status=?,last_error=?,run_after=TIMESTAMPADD(SECOND,?,NOW(6)),
        lock_owner=NULL,lease_until=NULL WHERE id=? AND lock_owner=? AND status='processing' AND lease_until>NOW(6)")
        ->execute([$state,$error,$delay,$job['id'],$job['lock_owner']]);
}

function retry_payment_job(PDO $pdo, int $actor, int $job): void
{
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Finance permission required.');
    workflow_transaction($pdo,function () use ($pdo,$actor,$job) {
        $row = workflow_row($pdo,"SELECT kind,attempt_id FROM payment_jobs WHERE id=? AND status='dead' FOR UPDATE",[$job]);
        if (!$row) throw new DomainException('This job is not in the dead-letter queue.');
        if ($row['attempt_id']) {
            $case = workflow_row($pdo,'SELECT s.operation FROM payment_attempts a
                JOIN mobile_money_sandbox_cases s ON s.id=a.sandbox_case_id WHERE a.id=?',[$row['attempt_id']]);
            if ($case && !staff_can($pdo,$actor,sandbox_permission($case['operation']))) {
                throw new DomainException('Sandbox product permission required.');
            }
        }
        if ($row['kind']==='initiate' && !workflow_row($pdo,
            "SELECT id FROM payment_attempts WHERE id=? AND state='queued' FOR UPDATE",[$row['attempt_id']])) {
            throw new DomainException('A dispatch may already have occurred. Request a status check instead.');
        }
        $pdo->prepare("UPDATE payment_jobs SET status='queued',attempts=0,run_after=NOW(6),last_error=NULL WHERE id=?")
            ->execute([$job]);
        audit($pdo,'queue.redrive','payment_job',$job,[],$actor);
    });
}

function enqueue_verified_webhook(PDO $pdo, string $provider, array $input): string
{
    return workflow_transaction($pdo,function () use ($pdo,$provider,$input) {
        $event = store_provider_event($pdo,$provider,$input);
        if ($event['status'] !== 'pending') return $event['status'];
        schedule_payment_job($pdo,'webhook',null,(int)$event['id']);
        return 'queued';
    });
}

function event_input(array $event): array
{
    return ['id'=>$event['event_key'],'reference'=>$event['reference'],'type'=>$event['event_type'],
        'amount_minor'=>(int)$event['amount_minor'],'currency'=>$event['currency']];
}

