<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/workflow.php';

if ($actorRole) require_role($actorRole);
else require_login();
require_csrf();

$appId = (int)($_POST['application_id'] ?? 0);
$jobId = (int)($_POST['job_id'] ?? 0);
if (!$jobId) {
    $ref = workflow_row($pdo, 'SELECT job_id FROM applications WHERE id=?', [$appId]);
    $jobId = (int)($ref['job_id'] ?? 0);
}
$isEmployer = current_role() === 'employer';
$destination = $isEmployer ? '/giglink/employer/applicants.php?job_id=' . $jobId : '/giglink/youth/dashboard.php';
try {
    if (!$actorRole && !in_array($next, ['in_progress','completed','disputed'], true)) {
        throw new DomainException('Choose a valid work status.');
    }
    $version = filter_var($_POST['state_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (!$actorRole && $version === false) throw new DomainException('Refresh this page before updating work.');
    change_work_state($pdo, $jobId, $appId, current_user_id(), $next,
        isset($_POST['is_team_lead']), $version === false ? null : $version);
    flash_set('ok', $success);
} catch (Throwable $error) {
    flash_set('err', workflow_error($error));
}
redirect($destination);

