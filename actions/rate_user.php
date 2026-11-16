<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/workflow.php';
require_login();
require_csrf();

$actor = current_user_id();
$ratee = (int)($_POST['ratee_user_id'] ?? 0);
$jobId = (int)($_POST['job_id'] ?? 0);
$score = (int)($_POST['score'] ?? 0);
$note = is_string($_POST['comment'] ?? null) ? trim($_POST['comment']) : '';
$destination = current_role() === 'employer' ? '/giglink/employer/applicants.php?job_id=' . $jobId
    : '/giglink/youth/dashboard.php';
try {
    $created = workflow_transaction($pdo, function () use ($pdo,$actor,$ratee,$jobId,$score,$note) {
        if ($score < 1 || $score > 5 || mb_strlen($note) > 1000) throw new DomainException('Choose a rating from 1 to 5 and keep the comment under 1,000 characters.');
        $job = locked_job($pdo,$jobId);
        $employer = (int)$job['employer_id'];
        if ($actor !== $employer && $ratee !== $employer) throw new DomainException('Choose a participant from this gig.');
        $worker = $actor === $employer ? $ratee : $actor;
        $app = workflow_row($pdo,"SELECT id,status FROM applications WHERE job_id=? AND youth_id=? FOR UPDATE",[$jobId,$worker]);
        if (!$app || $worker === $employer || $app['status'] !== 'completed') {
            throw new DomainException('You can review this gig after the work is complete.');
        }
        if (workflow_row($pdo,'SELECT id FROM ratings WHERE job_id=? AND rater_user_id=? AND ratee_user_id=?',
            [$jobId,$actor,$ratee])) return false;
        $stmt = $pdo->prepare('CALL sp_rate_user(?,?,?,?,?)');
        $stmt->execute([$actor,$ratee,$jobId,$score,$note]);
        $stmt->closeCursor();
        $stamp = $actor === $employer ? 'employer_rated_at' : 'youth_rated_at';
        $pdo->prepare("UPDATE applications SET $stamp=NOW() WHERE id=?")->execute([$app['id']]);
        notify($pdo,$ratee,'new_rating','You received a new review',
            current_role() === 'youth' ? '/giglink/employer/dashboard.php' : '/giglink/youth/dashboard.php');
        audit($pdo,'rating.create','job',$jobId,['ratee'=>$ratee,'score'=>$score]);
        return true;
    });
    flash_set('ok',$created ? 'Review saved.' : 'You already reviewed this gig.');
} catch (Throwable $error) {
    flash_set('err',workflow_error($error));
}
redirect($destination);

