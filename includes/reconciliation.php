<?php
require_once __DIR__ . '/disputes.php';

function reconciliation_candidates(PDO $pdo, array $payment, ?int $appId = null): array
{
    // Restrict candidates to the original parties. Amount and time are supporting evidence.
    $sql = "SELECT a.id,a.job_id,j.title,j.pay_amount,j.pay_type,j.renewed_from_id,a.completed_at,
        DATEDIFF(?,a.completed_at) AS days_apart,
        EXISTS(SELECT 1 FROM v_application_payments p WHERE p.application_id=a.id) AS already_linked
        FROM applications a JOIN jobs j ON j.id=a.job_id
        WHERE a.youth_id=? AND j.employer_id=? AND a.status='completed'";
    $args = [$payment['created_at'],$payment['youth_id'],$payment['employer_id']];
    if ($appId !== null) {
        $sql .= ' AND a.id=?';
        $args[] = $appId;
    }
    $sql .= ' ORDER BY (a.job_id=?) DESC, ABS(DATEDIFF(?,a.completed_at)) IS NULL,
        ABS(DATEDIFF(?,a.completed_at)), a.id DESC LIMIT 100';
    array_push($args,$payment['job_id'],$payment['created_at'],$payment['created_at']);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $candidates = $stmt->fetchAll();
    foreach ($candidates as &$candidate) {
        $score = 30;
        $reasons = ['Same worker and employer'];
        if ($candidate['job_id'] == $payment['job_id']) {
            $score += 35;
            $reasons[] = 'Same original job';
        } elseif ($candidate['renewed_from_id'] == $payment['job_id'] || $payment['renewed_from_id'] == $candidate['job_id']) {
            $score += 20;
            $reasons[] = 'Adjacent renewed listing';
        }
        if ($candidate['pay_type'] === 'fixed' && $candidate['pay_amount'] !== null
            && $candidate['pay_amount'] === $payment['amount']) {
            $score += 20;
            $reasons[] = 'Matches fixed listing amount';
        }
        if ($candidate['days_apart'] !== null && abs((int)$candidate['days_apart']) <= 14) {
            $score += 15;
            $reasons[] = abs((int)$candidate['days_apart']) . ' days from completion';
        }
        $candidate['score'] = $score;
        $candidate['reasons'] = $reasons;
    }
    unset($candidate);
    usort($candidates, fn($a,$b) => [$a['already_linked'], -$a['score'], -$a['id']]
        <=> [$b['already_linked'], -$b['score'], -$b['id']]);
    return $appId === null ? array_slice($candidates,0,15) : $candidates;
}

function reconcile_payment(PDO $pdo, int $paymentId, int $appId, int $actor, string $reason): void
{
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Staff access required.');
    if ($reason === '' || mb_strlen($reason) > 1500) throw new DomainException('Enter a matching reason of up to 1,500 characters.');
    $payment = workflow_row($pdo,'SELECT job_id FROM payments WHERE id=?',[$paymentId]);
    $app = workflow_row($pdo,'SELECT job_id FROM applications WHERE id=?',[$appId]);
    if (!$payment || !$app) throw new DomainException('Payment or application not found.');
    workflow_transaction($pdo, function () use ($pdo,$paymentId,$appId,$actor,$reason,$payment,$app) {
        $jobIds = [(int)$payment['job_id'],(int)$app['job_id']];
        sort($jobIds,SORT_NUMERIC);
        $jobs = [];
        foreach (array_unique($jobIds) as $jobId) $jobs[$jobId] = locked_job($pdo,$jobId);
        $target = locked_application($pdo,(int)$app['job_id'],$appId);
        $source = workflow_row($pdo,'SELECT * FROM payments WHERE id=? FOR UPDATE',[$paymentId]);
        if ($source['application_id'] !== null || $source['idempotency_key'] !== null
            || workflow_row($pdo,'SELECT payment_id FROM payment_reconciliations WHERE payment_id=? FOR UPDATE',[$paymentId])) {
            throw new DomainException('This payment already has an application association.');
        }
        if ((int)$target['youth_id'] !== (int)$source['youth_id']
            || (int)$jobs[$target['job_id']]['employer_id'] !== (int)$source['employer_id']
            || $target['status'] !== 'completed') {
            throw new DomainException('A match requires completed work with the original worker and employer.');
        }
        $source['renewed_from_id'] = $jobs[$source['job_id']]['renewed_from_id'];
        $candidate = reconciliation_candidates($pdo,$source,$appId)[0] ?? null;
        if (!$candidate || $candidate['already_linked']) {
            throw new DomainException('That application already has a payment record or is unavailable.');
        }
        $pdo->prepare('INSERT INTO payment_reconciliations (payment_id,application_id,actor_id,reason,match_score)
            VALUES (?,?,?,?,?)')->execute([$paymentId,$appId,$actor,$reason,$candidate['score']]);
        $pdo->prepare("INSERT INTO effective_reconciliations (payment_id,application_id) VALUES (?,?)")->execute([$paymentId,$appId]);
        audit($pdo,'payment.reconcile','payment',$paymentId,['from_application_id'=>null,
            'to_application_id'=>$appId,'original_job_id'=>(int)$source['job_id'],'match_score'=>$candidate['score']],$actor);
        if ($source['status'] === 'disputed' || $source['receipt_status'] === 'disputed') {
            create_dispute($pdo,$appId,$paymentId,$actor,$source['dispute_reason']);
        }
    });
}

