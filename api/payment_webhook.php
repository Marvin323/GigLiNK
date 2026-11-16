<?php
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/providers.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function webhook_response(int $code, string $status): never
{
    http_response_code($code);
    echo json_encode(['status'=>$status]);
    exit;
}

try {
    $adapter = mock_provider();
} catch (DomainException $error) {
    webhook_response(404,'unavailable');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    webhook_response(405,'method_not_allowed');
}
$raw = file_get_contents('php://input',false,null,0,16385);
if ($raw === false || strlen($raw)>16384) webhook_response(413,'payload_too_large');

require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/payment_queue.php';
try {
    if (!webhook_rate_allowed($pdo,$_SERVER['REMOTE_ADDR'] ?? 'unknown',getenv('GIGLINK_MOCK_WEBHOOK_SECRET'))) {
        header('Retry-After: 60');
        webhook_response(429,'rate_limited');
    }
    $event = $adapter->verifyWebhook($raw,[
        'timestamp'=>$_SERVER['HTTP_X_MOCK_TIMESTAMP'] ?? '',
        'signature'=>$_SERVER['HTTP_X_MOCK_SIGNATURE'] ?? '',
    ],time());
    $legacy = workflow_row($pdo,'SELECT id FROM provider_transactions WHERE provider=? AND reference=? AND attempt_id IS NULL',
        [$adapter->code(),$event['reference']]);
    if ($legacy) webhook_response(200,process_provider_event($pdo,$adapter->code(),$event));
    $status = enqueue_verified_webhook($pdo,$adapter->code(),$event);
    webhook_response($status === 'queued' ? 202 : 200,$status);
} catch (UnexpectedValueException $error) {
    webhook_response(401,'authentication_failed');
} catch (DomainException $error) {
    webhook_response(400,'invalid_event');
} catch (Throwable $error) {
    error_log('Payment webhook processing failed.');
    webhook_response(503,'retry');
}

