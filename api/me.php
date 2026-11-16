<?php
require __DIR__ . '/_bootstrap.php';
$u = api_user($pdo);
$profile = null;
if ($u['role'] === 'youth') {
    $s = $pdo->prepare("SELECT * FROM v_worker_summary WHERE user_id = ?");
    $s->execute([$u['id']]);
    $profile = $s->fetch();
} elseif ($u['role'] === 'employer') {
    $s = $pdo->prepare("SELECT * FROM v_employer_summary WHERE user_id = ?");
    $s->execute([$u['id']]);
    $profile = $s->fetch();
}
$unread_notifications = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL")->execute([$u['id']]) ?
    (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=" . (int)$u['id'] . " AND read_at IS NULL")->fetchColumn() : 0;

json_out([
    'ok'   => true,
    'user' => [
        'id'       => (int)$u['id'],
        'username' => $u['username'],
        'role'     => $u['role'],
        'phone'    => $u['phone'],
        'email'    => $u['email'],
        'photo'    => $u['photo_path'],
    ],
    'profile' => $profile ?: null,
    'unread'  => [
        'notifications' => $unread_notifications,
        'messages'      => unread_messages_count($pdo, (int)$u['id']),
    ],
]);
