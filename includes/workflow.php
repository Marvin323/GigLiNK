<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/disputes.php';

const OCCUPIED_STATES = "'accepted','in_progress','completed','disputed'";
const ACTIVE_WORK_STATES = "'accepted','in_progress','disputed'";

function workflow_row(PDO $pdo, string $sql, array $args): array|false
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function workflow_transaction(PDO $pdo, callable $operation): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $operation();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function locked_job(PDO $pdo, int $jobId): array
{
    $job = workflow_row($pdo, "SELECT * FROM jobs WHERE id=? FOR UPDATE", [$jobId]);
    if (!$job) throw new DomainException('Job not found.');
    return $job;
}

function locked_application(PDO $pdo, int $jobId, int $applicationId): array
{
    $app = workflow_row($pdo, "SELECT * FROM applications WHERE id=? AND job_id=? FOR UPDATE",
        [$applicationId, $jobId]);
    if (!$app) throw new DomainException('Application not found for this job.');
    return $app;
}

function hiring_counts(PDO $pdo, int $jobId): array
{
    // A locking read sees the latest committed state, even under REPEATABLE READ.
    return workflow_row($pdo, "SELECT COUNT(*) AS occupied,
        COUNT(CASE WHEN status IN (" . ACTIVE_WORK_STATES . ") THEN 1 END) AS active
        FROM applications WHERE job_id=? AND status IN (" . OCCUPIED_STATES . ") FOR UPDATE", [$jobId]);
}

function refresh_job_status(PDO $pdo, array $job): void
{
    if (!in_array($job['status'], ['open', 'filled'], true)) return;
    $counts = hiring_counts($pdo, (int)$job['id']);
    $status = 'open';
    if ($counts['occupied'] >= $job['workers_needed']) {
        $status = $counts['active'] ? 'filled' : 'completed';
    }
    if ($status !== $job['status']) {
        $pdo->prepare("UPDATE jobs SET status=? WHERE id=?")->execute([$status, $job['id']]);
    }
}

function apply_for_job(PDO $pdo, int $jobId, int $workerId, string $message): ?array
{
    if ($message === '' || mb_strlen($message) > 4000) {
        throw new DomainException('Enter an application message of up to 4,000 characters.');
    }
    return workflow_transaction($pdo, function () use ($pdo, $jobId, $workerId, $message) {
        $job = locked_job($pdo, $jobId);
        if (!workflow_row($pdo, "SELECT id FROM users WHERE id=? AND active=1 AND deleted_at IS NULL",
            [$job['employer_id']])) throw new DomainException('This gig is unavailable.');
        if (workflow_row($pdo, 'SELECT id FROM applications WHERE job_id=? AND youth_id=? FOR UPDATE',
                [$jobId, $workerId])) return null;
        if ($job['status'] !== 'open' || hiring_counts($pdo, $jobId)['occupied'] >= $job['workers_needed']) {
            throw new DomainException('This gig is no longer accepting applications.');
        }
        $pdo->prepare("INSERT INTO applications (job_id,youth_id,message) VALUES (?,?,?)")
            ->execute([$jobId, $workerId, $message]);
        $appId = (int)$pdo->lastInsertId();
        notify($pdo, (int)$job['employer_id'], 'new_applicant', 'New applicant for ' . $job['title'],
            '/giglink/employer/applicants.php?job_id=' . $jobId);
        audit($pdo, 'application.create', 'application', $appId, ['job_id'=>$jobId,'to'=>'pending'], $workerId);
        return $job;
    });
}

function change_work_state(PDO $pdo, int $jobId, int $applicationId, int $actor, string $next, bool $lead = false, ?int $version = null): void
{
    workflow_transaction($pdo, function () use ($pdo, $jobId, $applicationId, $actor, $next, $lead, $version) {
        $job = locked_job($pdo, $jobId);
        $app = locked_application($pdo, $jobId, $applicationId);
        if ($version !== null && $version !== (int)$app['state_version']) {
            throw new DomainException('This application changed. Review its current status before trying again.');
        }
        $employer = $actor === (int)$job['employer_id'];
        $worker = $actor === (int)$app['youth_id'];
        $current = $app['status'];
        $valid = match ($next) {
            'accepted', 'rejected' => $employer && $current === 'pending',
            'cancelled' => $employer && $current === 'accepted' && !$app['paid_at'],
            'withdrawn' => $worker && in_array($current, ['pending','accepted'], true) && !$app['paid_at'],
            'in_progress' => ($worker || $employer) && $current === 'accepted',
            'completed' => $employer && $current === 'in_progress',
            'disputed' => ($employer || $worker) && $current === 'in_progress',
            default => false,
        };
        if (!$valid) throw new DomainException('This application cannot move to that status.');
        if ($next === 'accepted' && ($job['status'] !== 'open'
            || hiring_counts($pdo, $jobId)['occupied'] >= $job['workers_needed'])) {
            throw new DomainException('This gig has no open hiring slots.');
        }
        $detail = match ($next) {
            'accepted' => ', accepted_at=NOW(), decided_at=NOW(), is_team_lead=?',
            'in_progress' => ', started_at=COALESCE(started_at,NOW())',
            'completed' => ', completed_at=NOW(), completed_by=?',
            'rejected','withdrawn','cancelled' => ', decided_at=NOW()',
            default => '',
        };
        $args = [$next];
        if ($next === 'accepted') $args[] = (int)$lead;
        if ($next === 'completed') $args[] = $actor;
        $args[] = $applicationId;
        $pdo->prepare("UPDATE applications SET status=?, state_version=state_version+1 $detail WHERE id=?")->execute($args);
        refresh_job_status($pdo, $job);
        $recipient = $employer ? (int)$app['youth_id'] : (int)$job['employer_id'];
        $otherPage = $employer ? '/giglink/youth/dashboard.php' : '/giglink/employer/applicants.php?job_id=' . $jobId;
        if ($next === 'disputed') {
            $caseId = create_dispute($pdo,$applicationId,null,$actor);
            $otherPage = '/giglink/dispute.php?id=' . $caseId;
        }
        notify($pdo, $recipient, 'application_' . $next, $job['title'] . ': ' . str_replace('_', ' ', $next), $otherPage);
        audit($pdo, 'application.' . $next, 'application', $applicationId, ['from'=>$current,'to'=>$next], $actor);
    });
}

function close_or_renew_job(PDO $pdo, int $jobId, int $actor, bool $renew): int
{
    return workflow_transaction($pdo, function () use ($pdo, $jobId, $actor, $renew) {
        $job = locked_job($pdo, $jobId);
        if ((int)$job['employer_id'] !== $actor) throw new DomainException('This job is unavailable.');
        if (!$renew) {
            if (in_array($job['status'], ['open','filled'], true)) {
                $pdo->prepare("UPDATE jobs SET status='closed' WHERE id=?")->execute([$jobId]);
                audit($pdo, 'job.close', 'job', $jobId, [], $actor);
            } elseif ($job['status'] !== 'closed') {
                throw new DomainException('This job is already finished.');
            }
            return $jobId;
        }
        if (!in_array($job['status'], ['closed','completed','cancelled'], true)
            || hiring_counts($pdo, $jobId)['active']) {
            throw new DomainException('Close hiring and finish active gigs before renewing.');
        }
        $renewed = workflow_row($pdo, 'SELECT id FROM jobs WHERE renewed_from_id=? FOR UPDATE', [$jobId]);
        if ($renewed) return (int)$renewed['id'];
        $pdo->prepare("INSERT INTO jobs (employer_id,category_id,location_id,title,description,
            pay_amount,pay_type,workers_needed,last_renewed_at,renewed_from_id)
            SELECT employer_id,category_id,location_id,title,description,pay_amount,pay_type,
                   workers_needed,NOW(),id FROM jobs WHERE id=?")->execute([$jobId]);
        $newId = (int)$pdo->lastInsertId();
        audit($pdo, 'job.renew', 'job', $newId, ['source' => $jobId], $actor);
        return $newId;
    });
}

function workflow_error(Throwable $error): string
{
    if ($error instanceof DomainException) return $error->getMessage();
    error_log($error->getMessage());
    return 'The update could not be saved. Refresh and try again.';
}

