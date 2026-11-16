<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/payments.php';
require_role('youth');
require_csrf();

$id = (int)($_POST['payment_id'] ?? 0);
$state = is_string($_POST['state'] ?? null) ? $_POST['state'] : '';
$reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
try {
    respond_to_payment($pdo,$id,current_user_id(),$state,$reason);
    flash_set('ok',receipt_label($state) . '.');
} catch (Throwable $error) {
    flash_set('err',workflow_error($error));
}
redirect('/giglink/youth/dashboard.php');

