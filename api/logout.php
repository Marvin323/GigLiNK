<?php
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST required', 405);
$tok = bearer_token();
if ($tok) {
    $pdo->prepare("UPDATE api_tokens SET revoked = 1 WHERE token_hash = ?")
        ->execute([hash('sha256', $tok)]);
}
json_out(['ok' => true]);
