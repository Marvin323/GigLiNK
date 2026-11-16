<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
require_csrf();
logout_user();
flash_set('ok', 'Logged out.');
redirect('/giglink/');
