<?php
require_once __DIR__ . '/reconciliation.php';

function correction_reason(string $reason): void
{
    if ($reason === '' || mb_strlen($reason)>1500) throw new DomainException('Enter a reason of up to 1,500 characters.');
}

function correction_scope(PDO $pdo, int $payment, ?int $from, ?int $to): array
{
    $source = workflow_row($pdo,'SELECT job_id FROM payments WHERE id=?',[$payment]);
    if (!$source) throw new DomainException('Historical payment not found.');
    $jobs = [(int)$source['job_id']];
    $applications = [];
    foreach (array_unique(array_filter([$from,$to])) as $id) {
        $app = workflow_row($pdo,'SELECT job_id FROM applications WHERE id=?',[$id]);
        if (!$app) throw new DomainException('Application not found.');
        $applications[$id] = (int)$app['job_id'];
        $jobs[] = (int)$app['job_id'];
    }
    sort($jobs,SORT_NUMERIC);
    ksort($applications,SORT_NUMERIC);
    return [array_unique($jobs),$applications];
}

function locked_correction_context(PDO $pdo, array $scope, int $payment): array
{
    [$jobs,$applications] = $scope;
    $lockedJobs = $lockedApps = [];
    foreach ($jobs as $id) $lockedJobs[$id] = locked_job($pdo,$id);
    foreach ($applications as $id=>$job) $lockedApps[$id] = locked_application($pdo,$job,$id);
    $source = workflow_row($pdo,'SELECT * FROM payments WHERE id=? FOR UPDATE',[$payment]);
    $link = workflow_row($pdo,'SELECT * FROM effective_reconciliations WHERE payment_id=? FOR UPDATE',[$payment]);
    if (!$link) throw new DomainException('No original reconciliation exists.');
    return [$lockedJobs,$lockedApps,$source,$link];
}

function validate_correction_target(PDO $pdo, array $context, ?int $to): void
{
    [$jobs,$apps,$payment] = $context;
    if (workflow_row($pdo,"SELECT id FROM disputes WHERE payment_id=? AND status IN ('open','reviewing') FOR UPDATE",[$payment['id']])) {
        throw new DomainException('The linked payment dispute must close before correcting the association.');
    }
    if ($to === null) return;
    $app = $apps[$to];
    if ($app['status'] !== 'completed' || (int)$app['youth_id'] !== (int)$payment['youth_id']
        || (int)$jobs[$app['job_id']]['employer_id'] !== (int)$payment['employer_id']
        || workflow_row($pdo,'SELECT id FROM v_application_payments WHERE application_id=? LIMIT 1',[$to])) {
        throw new DomainException('Choose unpaid completed work involving the original parties.');
    }
}

function request_correction(PDO $pdo, int $payment, int $actor, ?int $to, string $reason): int
{
    correction_reason($reason);
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Finance permission required.');
    $ref = workflow_row($pdo,'SELECT * FROM effective_reconciliations WHERE payment_id=?',[$payment]);
    if (!$ref) throw new DomainException('No original reconciliation exists.');
    $from = $ref['application_id'] === null ? null : (int)$ref['application_id'];
    if ($from === $to) throw new DomainException('Select a different effective association.');
    $scope = correction_scope($pdo,$payment,$from,$to);
    return workflow_transaction($pdo,function () use ($pdo,$payment,$actor,$to,$reason,$ref,$from,$scope) {
        $context = locked_correction_context($pdo,$scope,$payment);
        $link = $context[3];
        if ((int)$link['revision'] !== (int)$ref['revision']) throw new DomainException('The association changed. Refresh this page.');
        if (workflow_row($pdo,'SELECT id FROM reconciliation_corrections WHERE open_payment=? FOR UPDATE',[$payment])) {
            throw new DomainException('A correction is already awaiting review.');
        }
        validate_correction_target($pdo,$context,$to);
        $pdo->prepare('INSERT INTO reconciliation_corrections
            (payment_id,original_revision,from_application,to_application,requester,reason) VALUES (?,?,?,?,?,?)')
            ->execute([$payment,$link['revision'],$from,$to,$actor,$reason]);
        $id = (int)$pdo->lastInsertId();
        audit($pdo,'correction.request','correction',$id,['payment'=>$payment,'from'=>$from,'to'=>$to],$actor);
        return $id;
    });
}

function decide_correction(PDO $pdo, int $id, int $actor, bool $approve, string $reason): void
{
    correction_reason($reason);
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Finance permission required.');
    $request = workflow_row($pdo,'SELECT * FROM reconciliation_corrections WHERE id=?',[$id]);
    if (!$request || (int)$request['requester'] === $actor) throw new DomainException('A different Finance Officer must review this request.');
    $payment = (int)$request['payment_id'];
    $from = $request['from_application'] === null ? null : (int)$request['from_application'];
    $to = $request['to_application'] === null ? null : (int)$request['to_application'];
    $scope = correction_scope($pdo,$payment,$from,$to);
    workflow_transaction($pdo,function () use ($pdo,$id,$actor,$approve,$reason,$scope,$payment,$to,$from) {
        $context = locked_correction_context($pdo,$scope,$payment);
        $request = workflow_row($pdo,'SELECT * FROM reconciliation_corrections WHERE id=? FOR UPDATE',[$id]);
        if (!$request['pending'] || $request['original_revision'] != $context[3]['revision']) {
            throw new DomainException('This request is closed or its association has changed.');
        }
        if ($approve) {
            validate_correction_target($pdo,$context,$to);
            $pdo->prepare('UPDATE effective_reconciliations SET application_id=?,revision=revision+1 WHERE payment_id=?')
                ->execute([$to,$payment]);
        }
        $pdo->prepare('INSERT INTO correction_decisions (correction_id,requester,reviewer,approved,reason) VALUES (?,?,?,?,?)')
            ->execute([$id,$request['requester'],$actor,(int)$approve,$reason]);
        $pdo->prepare('UPDATE reconciliation_corrections SET pending=0 WHERE id=?')->execute([$id]);
        audit($pdo,$approve?'correction.approve':'correction.reject','correction',$id,
            ['payment'=>$payment,'from'=>$from,'to'=>$approve?$to:$from],$actor);
    });
}

