<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('youth');
require_csrf();
$workerId = current_user_id();
$input = $_POST;
require __DIR__ . '/../includes/workflow.php';

$jobId = (int)($input['job_id'] ?? 0);
$message = is_string($input['message'] ?? null) ? trim($input['message']) : '';
try {
    $job = apply_for_job($pdo, $jobId, $workerId, $message);
    flash_set('ok', $job ? 'Application sent.' : 'You already applied to this gig.');
} catch (Throwable $error) {
    flash_set('err', workflow_error($error));
}
redirect('/giglink/job_view.php?id=' . $jobId);

