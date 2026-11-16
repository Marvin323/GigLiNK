<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/payments.php';
require_role('employer');
require_csrf();

$appId = (int)($_POST['application_id'] ?? 0);
$ref = workflow_row($pdo, 'SELECT a.job_id FROM applications a
    JOIN jobs j ON j.id=a.job_id WHERE a.id=? AND j.employer_id=?', [$appId,current_user_id()]);
if (!$ref) { http_response_code(404); exit('Application not found.'); }
try {
    $input = fn($name) => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
    record_payment($pdo,$appId,current_user_id(),$input('amount'),$input('method'),
        $input('reference'),$input('idempotency_key'));
    flash_set('ok','Payment recorded.');
} catch (Throwable $error) {
    flash_set('err',workflow_error($error));
}
redirect('/giglink/employer/applicants.php?job_id=' . (int)$ref['job_id'] . '#application-' . $appId);

