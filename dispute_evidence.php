<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/disputes.php';
require_login();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$id = (int)($_GET['id'] ?? 0);
$ref = workflow_row($pdo,'SELECT dispute_id FROM dispute_submissions WHERE id=? AND evidence_type IS NOT NULL',[$id]);
try {
    if (!$ref) throw new DomainException('Evidence not found.');
    dispute_case($pdo,(int)$ref['dispute_id'],current_user_id());
} catch (DomainException $error) {
    http_response_code(404);
    exit('Evidence not found.');
}
$file = workflow_row($pdo,'SELECT evidence_type,evidence FROM dispute_submissions WHERE id=?',[$id]);
$type = $file['evidence_type'];
$extension = $type === 'image/png' ? 'png' : 'jpg';
header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="dispute-evidence-' . $id . '.' . $extension . '"');
header('Content-Length: ' . strlen($file['evidence']));
echo $file['evidence'];

