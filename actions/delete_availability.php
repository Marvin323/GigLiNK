<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('youth');
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$pdo->prepare("DELETE FROM availability_windows WHERE id=? AND youth_id=?")
    ->execute([$id, current_user_id()]);

flash_set('ok', 'Deleted.');
redirect('/giglink/youth/availability.php');
