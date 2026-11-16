<?php
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST required', 405);
$worker = api_require_role($pdo, 'youth');
$workerId = (int)$worker['id'];
$input = body_json();
require __DIR__ . '/../includes/workflow.php';

$jobId = (int)($input['job_id'] ?? 0);
$message = is_string($input['message'] ?? null) ? trim($input['message']) : '';
try {
    apply_for_job($pdo, $jobId, $workerId, $message);
    json_out(['ok' => true]);
} catch (Throwable $error) {
    json_err(workflow_error($error), $error instanceof DomainException ? 409 : 500);
}


