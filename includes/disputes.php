<?php
require_once __DIR__ . '/staff.php';

function is_trust_staff(PDO $pdo, int $actor): bool
{
    return staff_can($pdo,$actor,'disputes');
}

function dispute_case(PDO $pdo, int $id, int $actor): array
{
    $case = workflow_row($pdo, "SELECT d.*,a.job_id,a.youth_id,j.employer_id,j.title,w.username AS worker,em.username AS employer
        FROM disputes d JOIN applications a ON a.id=d.application_id JOIN jobs j ON j.id=a.job_id
        JOIN users w ON w.id=a.youth_id JOIN users em ON em.id=j.employer_id
        JOIN users viewer ON viewer.id=? AND viewer.active=1 AND viewer.deleted_at IS NULL
        WHERE d.id=?", [$actor,$id]);
    if (!$case || (!in_array($actor, [(int)$case['youth_id'],(int)$case['employer_id']], true)
        && !can_manage_dispute($pdo,$actor,$case))) {
        throw new DomainException('Dispute not found.');
    }
    return $case;
}

// Called inside the existing job-first work/payment transaction.
function create_dispute(PDO $pdo, int $app, ?int $payment, ?int $actor, ?string $reason = null): int
{
    $pdo->prepare('INSERT INTO disputes (application_id,payment_id,opened_by,initial_reason) VALUES (?,?,?,?)')
        ->execute([$app,$payment,$actor,$reason]);
    $id = (int)$pdo->lastInsertId();
    audit($pdo,'dispute.create','dispute',$id,['application_id'=>$app,'payment_id'=>$payment,'to'=>'open'], $actor);
    return $id;
}

function decide_dispute(PDO $pdo, int $id, int $actor, int $revision, string $next, string $reason): void
{
    if (!staff_can($pdo,$actor,'disputes')) throw new DomainException('Dispute officer permission required.');
    if ($reason === '' || mb_strlen($reason) > 1500) throw new DomainException('Enter a decision reason of up to 1,500 characters.');
    $reference = dispute_case($pdo,$id,$actor);
    workflow_transaction($pdo, function () use ($pdo,$id,$actor,$revision,$next,$reason,$reference) {
        $job = locked_job($pdo,(int)$reference['job_id']);
        $app = locked_application($pdo,(int)$job['id'],(int)$reference['application_id']);
        $case = workflow_row($pdo,'SELECT * FROM disputes WHERE id=? FOR UPDATE',[$id]);
        if (!can_manage_dispute($pdo,$actor,$case)) throw new DomainException('This case is assigned to another officer.');
        $from = $case['status'];
        $allowed = match ($from) {
            'open' => ['reviewing','resolved','rejected'],
            'reviewing' => ['resolved','rejected'],
            default => ['open'],
        };
        if ((int)$case['revision'] !== $revision || !in_array($next,$allowed,true)) {
            throw new DomainException('The case changed or that decision is unavailable.');
        }
        if ($case['payment_id'] === null && $next !== 'reviewing') {
            $expected = $next === 'open' ? 'in_progress' : 'disputed';
            if ($app['status'] !== $expected) throw new DomainException('The work status no longer allows this decision.');
            $work = $next === 'open' ? 'disputed' : 'in_progress';
            $pdo->prepare('UPDATE applications SET status=?,state_version=state_version+1 WHERE id=?')
                ->execute([$work,$app['id']]);
            refresh_job_status($pdo,$job);
            audit($pdo,'application.' . $work,'application',(int)$app['id'],
                ['from'=>$expected,'to'=>$work,'dispute_id'=>$id],$actor);
        }
        $pdo->prepare('UPDATE disputes SET status=?,revision=revision+1 WHERE id=?')->execute([$next,$id]);
        $pdo->prepare('INSERT INTO dispute_decisions (dispute_id,revision,actor_id,previous_status,next_status,reason)
            VALUES (?,?,?,?,?,?)')->execute([$id,$revision+1,$actor,$from,$next,$reason]);
        audit($pdo,'dispute.' . $next,'dispute',$id,['from'=>$from,'to'=>$next,'revision'=>$revision+1],$actor);
        foreach ([$app['youth_id'],$job['employer_id']] as $recipient) {
            notify($pdo,(int)$recipient,'dispute_update','A dispute on your gig was updated.',
                '/giglink/dispute.php?id=' . $id);
        }
    });
}

function assign_dispute(PDO $pdo, int $id, int $actor, int $officer): void
{
    workflow_transaction($pdo,function () use ($pdo,$id,$actor,$officer) {
        if (!staff_can($pdo,$actor,'staff.manage') || ($officer && staff_role($pdo,$officer) !== 'dispute_officer')) {
            throw new DomainException('Choose an active Dispute Officer.');
        }
        $case = workflow_row($pdo,'SELECT assigned_to FROM disputes WHERE id=? FOR UPDATE',[$id]);
        if (!$case) throw new DomainException('Dispute not found.');
        if ((int)$case['assigned_to'] === $officer) return;
        $pdo->prepare('UPDATE disputes SET assigned_to=?,revision=revision+1 WHERE id=?')->execute([$officer ?: null,$id]);
        audit($pdo,'dispute.assign','dispute',$id,['from'=>$case['assigned_to'],'to'=>$officer ?: null],$actor);
    });
}

function submit_dispute_statement(PDO $pdo, int $id, int $actor, int $party, string $statement,
    string $key, ?array $evidence): void
{
    if (!preg_match('/\A[a-f0-9]{32}\z/', $key) || mb_strlen($statement) > 3000 || ($statement === '' && !$evidence)) {
        throw new DomainException('Enter a statement of up to 3,000 characters or attach evidence.');
    }
    $reference = dispute_case($pdo,$id,$actor);
    $parties = [(int)$reference['youth_id'],(int)$reference['employer_id']];
    if (!in_array($party,$parties,true) || ($party !== $actor && !can_manage_dispute($pdo,$actor,$reference))) {
        throw new DomainException('Choose an authorized party.');
    }
    workflow_transaction($pdo, function () use ($pdo,$id,$actor,$party,$statement,$key,$evidence) {
        $case = workflow_row($pdo,'SELECT status,assigned_to FROM disputes WHERE id=? FOR UPDATE',[$id]);
        if ($party !== $actor && !can_manage_dispute($pdo,$actor,$case)) throw new DomainException('This case is assigned to another officer.');
        if (workflow_row($pdo,'SELECT id FROM dispute_submissions WHERE dispute_id=? AND actor_id=? AND request_key=?',
            [$id,$actor,$key])) return;
        if (!in_array($case['status'],['open','reviewing'],true)) {
            throw new DomainException('Staff must reopen the case before it can receive more statements.');
        }
        $count = workflow_row($pdo,'SELECT COUNT(*) AS total FROM dispute_submissions WHERE dispute_id=? AND actor_id=?',
            [$id,$actor]);
        if ((int)$count['total'] >= 30) throw new DomainException('This account has reached 30 submissions on this case.');
        $stmt = $pdo->prepare('INSERT INTO dispute_submissions
            (dispute_id,actor_id,party_id,request_key,statement,evidence_type,evidence) VALUES (?,?,?,?,?,?,?)');
        $stmt->bindValue(1,$id,PDO::PARAM_INT);
        $stmt->bindValue(2,$actor,PDO::PARAM_INT);
        $stmt->bindValue(3,$party,PDO::PARAM_INT);
        $stmt->bindValue(4,$key);
        $stmt->bindValue(5,$statement);
        $stmt->bindValue(6,$evidence['type'] ?? null);
        $stmt->bindValue(7,$evidence['bytes'] ?? null,$evidence ? PDO::PARAM_LOB : PDO::PARAM_NULL);
        $stmt->execute();
        audit($pdo,'dispute.statement','dispute',$id,['party_id'=>$party,'evidence'=>$evidence !== null],$actor);
    });
}

