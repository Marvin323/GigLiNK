<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/workflow.php';
require_role('employer');
require_csrf();

try {
    $jobId = close_or_renew_job($pdo, (int)($_POST['job_id'] ?? 0), current_user_id(), true);
    flash_set('ok', 'New hiring round is open.');
    redirect('/giglink/employer/applicants.php?job_id=' . $jobId);
} catch (Throwable $error) {
    flash_set('err', workflow_error($error));
}
redirect('/giglink/employer/dashboard.php');

