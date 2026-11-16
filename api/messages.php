<?php
require __DIR__ . '/_bootstrap.php';
$u  = api_user($pdo);
$id = (int)$u['id'];

// List threads with at least one message
if (!isset($_GET['application_id'])) {
    $rows = $pdo->query(
      "SELECT a.id AS application_id, j.title AS job_title,
              CASE WHEN a.youth_id = $id THEN j.employer_id ELSE a.youth_id END AS other_id,
              (SELECT body       FROM messages m WHERE m.application_id = a.id ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
              (SELECT created_at FROM messages m WHERE m.application_id = a.id ORDER BY m.created_at DESC LIMIT 1) AS last_at,
              (SELECT COUNT(*)   FROM messages m WHERE m.application_id = a.id AND m.sender_id <> $id AND m.read_at IS NULL) AS unread
         FROM applications a
         JOIN jobs j ON j.id = a.job_id
        WHERE a.status IN ('accepted','in_progress','disputed','completed')
          AND (a.youth_id = $id OR j.employer_id = $id)
          AND EXISTS (SELECT 1 FROM messages m WHERE m.application_id = a.id)
        ORDER BY last_at DESC"
    )->fetchAll();
    json_out(['ok' => true, 'threads' => $rows]);
}

// Fetch a single thread
$app_id = (int)$_GET['application_id'];
$row = $pdo->prepare(
  "SELECT a.*, j.title AS job_title, j.employer_id
     FROM applications a JOIN jobs j ON j.id = a.job_id
    WHERE a.id = ? AND (a.youth_id = ? OR j.employer_id = ?)"
);
$row->execute([$app_id, $id, $id]);
$row = $row->fetch();
if (!$row || !in_array($row['status'], ['accepted','in_progress','disputed','completed'], true)) json_err('forbidden', 403);

// Mark messages from the other side as read
$pdo->prepare("UPDATE messages SET read_at = NOW() WHERE application_id = ? AND sender_id <> ? AND read_at IS NULL")
    ->execute([$app_id, $id]);

$msgs = $pdo->prepare("SELECT id, sender_id, body, created_at, read_at FROM messages WHERE application_id = ? ORDER BY created_at");
$msgs->execute([$app_id]);
json_out(['ok' => true, 'application_id' => $app_id, 'messages' => $msgs->fetchAll()]);
