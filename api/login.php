<?php
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST required', 405);

$in       = body_json();
$username = strtolower(trim($in['username'] ?? ''));
$password = (string)($in['password'] ?? '');
if ($username === '' || $password === '') json_err('username and password required');

$s = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1 AND deleted_at IS NULL LIMIT 1");
$s->execute([$username]);
$u = $s->fetch();
if (!$u || !password_verify($password, $u['password_hash'])) {
    json_err('invalid username or password', 401);
}

// Issue a token good for 30 days
$token = bin2hex(random_bytes(32));
$hash  = hash('sha256', $token);
$pdo->prepare(
  "INSERT INTO api_tokens (user_id, token_hash, label, expires_at)
   VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))"
)->execute([$u['id'], $hash, substr($_SERVER['HTTP_USER_AGENT'] ?? 'mobile', 0, 60)]);

json_out([
    'ok'    => true,
    'token' => $token,
    'user'  => [
        'id'       => (int)$u['id'],
        'username' => $u['username'],
        'role'     => $u['role'],
        'phone'    => $u['phone'],
        'email'    => $u['email'],
        'photo'    => $u['photo_path'],
    ],
]);
