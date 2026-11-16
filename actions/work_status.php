<?php
$next = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
$actorRole = null;
$success = 'Work status updated.';
require __DIR__ . '/../includes/work_action.php';
