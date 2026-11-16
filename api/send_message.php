<?php
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST required', 405);
$u = api_user($pdo);

$in     = body_json();
$app_id = (int)($in['application_id'] ?? 0);
$body   = trim($in['body'] ?? '');
if (!$app_id || $body === '') json_err('application_id and body required');
if (mb_strlen($body) > 2000)  json_err('message too long (max 2000 chars)');

$row = $pdo->prepare(
  "SELECT a.*, j.employer_id, j.title AS job_title
     FROM applications a JOIN jobs j ON j.id = a.job_id
    WHERE a.id = ? AND a.status IN ('accepted','in_progress','disputed','completed')
      AND (a.youth_id = ? OR j.employer_id = ?)"
);
$row->execute([$app_id, $u['id'], $u['id']]);
$row = $row->fetch();
if (!$row) json_err('forbidden', 403);

$other_id = (int)$u['id'] === (int)$row['youth_id'] ? (int)$row['employer_id'] : (int)$row['youth_id'];
$pdo->prepare("INSERT INTO messages (application_id, sender_id, body) VALUES (?,?,?)")
    ->execute([$app_id, $u['id'], $body]);
notify($pdo, $other_id, 'new_message', 'New message about "' . $row['job_title'] . '"',
    '/giglink/message_thread.php?application_id=' . $app_id);

json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
