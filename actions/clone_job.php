<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/workflow.php';
require __DIR__ . '/../includes/job_fields.php';
require_role('employer');
require_csrf();

$jobId = (int)($_POST['job_id'] ?? 0);
$actor = current_user_id();
try {
    $key = job_creation_key($_POST['creation_key'] ?? null);
    $newId = workflow_transaction($pdo, function () use ($pdo, $jobId, $actor, $key) {
        $job = locked_job($pdo, $jobId);
        if ((int)$job['employer_id'] !== $actor) throw new DomainException('This job is unavailable.');
        $stmt = $pdo->prepare("INSERT INTO jobs
            (employer_id, category_id, location_id, title, description, pay_amount, pay_type, workers_needed, creation_key)
            SELECT employer_id, category_id, location_id, ?, description, pay_amount, pay_type, workers_needed, ?
            FROM jobs WHERE id=? ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $stmt->execute([mb_substr($job['title'], 0, 143) . ' (copy)', $key, $jobId]);
        $id = (int)$pdo->lastInsertId();
        if ($stmt->rowCount() === 1) audit($pdo, 'job.clone', 'job', $id, ['source' => $jobId], $actor);
        return $id;
    });
    flash_set('ok', 'Listing copied.');
    redirect('/giglink/employer/edit_job.php?id=' . $newId);
} catch (Throwable $error) {
    flash_set('err', workflow_error($error));
}
redirect('/giglink/employer/dashboard.php');

