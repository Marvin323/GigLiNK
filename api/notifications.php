<?php
require __DIR__ . '/_bootstrap.php';
$u = api_user($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mark all read
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")
        ->execute([$u['id']]);
    json_out(['ok' => true]);
}

$per  = min(50, max(1, (int)($_GET['per'] ?? 25)));
$page = max(1, (int)($_GET['p'] ?? 1));
$s = $pdo->prepare(
  "SELECT id, kind, title, url, read_at, created_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT $per OFFSET " . (($page - 1) * $per)
);
$s->execute([$u['id']]);
json_out(['ok' => true, 'notifications' => $s->fetchAll()]);
