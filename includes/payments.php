<?php
require_once __DIR__ . '/workflow.php';

function record_payment(PDO $pdo, int $appId, int $actor, string $amount, string $method,
    string $reference, string $key): bool
{
    if (!preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $amount) || (float)$amount <= 0
        || !in_array($method, ['cash','mpesa','airtel','mtn','bank'], true)
        || mb_strlen($reference) > 80 || !preg_match('/\A[a-f0-9]{32}\z/', $key)) {
        throw new DomainException('Enter a positive amount, a valid payment method and a reference of up to 80 characters.');
    }
    $appRef = workflow_row($pdo, 'SELECT job_id FROM applications WHERE id=?', [$appId]);
    return workflow_transaction($pdo, function () use ($pdo, $appRef, $appId, $actor, $amount, $method, $reference, $key) {
        $jobId = (int)($appRef['job_id'] ?? 0);
        $job = locked_job($pdo, $jobId);
        if ($actor !== (int)$job['employer_id']) throw new DomainException('You cannot record this payment.');
        $app = locked_application($pdo, $jobId, $appId);
        $existing = workflow_row($pdo, "SELECT * FROM payments WHERE employer_id=? AND idempotency_key=?",
            [$actor, $key]);
        if ($existing) {
            if ((int)$existing['application_id'] !== $appId || (float)$existing['amount'] !== (float)$amount
                || $existing['method'] !== $method || $existing['reference'] !== $reference) {
                throw new DomainException('That payment request was already used with different details.');
            }
            return false;
        }
        if ($app['status'] !== 'completed') throw new DomainException('Mark the work complete before recording payment.');
        if ($app['paid_at'] || workflow_row($pdo, "SELECT id FROM v_application_payments WHERE application_id=?
            AND receipt_status IS NOT NULL LIMIT 1", [$appId])) {
            throw new DomainException('A payment was already recorded for this application.');
        }
        $pdo->prepare("INSERT INTO payments
            (application_id,job_id,employer_id,youth_id,amount,method,reference,status,receipt_status,idempotency_key)
            VALUES (?,?,?,?,?,?,?,'released','recorded',?)")
            ->execute([$appId,$jobId,$actor,$app['youth_id'],$amount,$method,$reference,$key]);
        $paymentId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE applications SET paid_at=NOW() WHERE id=?')->execute([$appId]);
        notify($pdo, (int)$app['youth_id'], 'payment_recorded', 'Payment recorded for ' . $job['title'],
            '/giglink/youth/dashboard.php');
        audit($pdo, 'payment.record', 'payment', $paymentId, ['application_id'=>$appId,'to'=>'recorded','amount'=>$amount], $actor);
        return true;
    });
}

function respond_to_payment(PDO $pdo, int $paymentId, int $worker, string $state, string $reason): bool
{
    if (!in_array($state, ['confirmed','disputed'], true)
        || ($state === 'disputed' && ($reason === '' || mb_strlen($reason) > 500))) {
        throw new DomainException('Enter a dispute reason of up to 500 characters.');
    }
    $ref = workflow_row($pdo, 'SELECT job_id, application_id FROM payments WHERE id=?', [$paymentId]);
    return workflow_transaction($pdo, function () use ($pdo, $ref, $paymentId, $worker, $state, $reason) {
        $jobId = (int)($ref['job_id'] ?? 0);
        $job = locked_job($pdo, $jobId);
        $app = locked_application($pdo, $jobId, (int)$ref['application_id']);
        if ($worker !== (int)$app['youth_id']) throw new DomainException('You cannot respond to this payment.');
        $payment = workflow_row($pdo, 'SELECT receipt_status, dispute_reason FROM payments WHERE id=? FOR UPDATE', [$paymentId]);
        if ($payment['receipt_status'] === $state &&
            ($state === 'confirmed' || $payment['dispute_reason'] === $reason)) return false;
        if ($payment['receipt_status'] !== 'recorded') throw new DomainException('This payment has already been reviewed.');
        $pdo->prepare('UPDATE payments SET receipt_status=?, receipt_at=NOW(), dispute_reason=? WHERE id=?')
            ->execute([$state, $state === 'disputed' ? $reason : null, $paymentId]);
        $url = '/giglink/employer/applicants.php?job_id=' . $jobId;
        if ($state === 'disputed') {
            $caseId = create_dispute($pdo,(int)$app['id'],$paymentId,$worker,$reason);
            $url = '/giglink/dispute.php?id=' . $caseId;
        }
        notify($pdo, (int)$job['employer_id'], 'payment_' . $state, receipt_label($state), $url);
        audit($pdo, 'payment.' . $state, 'payment', $paymentId, ['from'=>'recorded','to'=>$state], $worker);
        return true;
    });
}

function receipt_label(?string $state): string
{
    return match ($state) {
        'recorded' => 'Employer recorded payment · awaiting worker confirmation',
        'confirmed' => 'Worker confirmed receipt',
        'provider_confirmed' => 'Provider confirmed payment',
        'disputed' => 'Payment disputed',
        'released' => 'Legacy payment recorded',
        'pending' => 'Legacy payment pending',
        'held' => 'Legacy payment marked held',
        'refunded' => 'Legacy payment marked refunded',
        default => 'Payment outstanding',
    };
}

