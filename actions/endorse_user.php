<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_login();
require_csrf();

$endorsee = (int)($_POST['endorsee_user_id'] ?? 0);
$note     = trim($_POST['note'] ?? '');
if ($endorsee === current_user_id()) { flash_set('err', 'You cannot endorse yourself.'); redirect($_SERVER['HTTP_REFERER'] ?? '/giglink/'); }

// use the current user's own display name + role
$me = $pdo->prepare(
  "SELECT COALESCE(yp.full_name, ep.business_name) AS name,
          u.role
     FROM users u
     LEFT JOIN youth_profiles yp    ON yp.user_id = u.id
     LEFT JOIN employer_profiles ep ON ep.user_id = u.id
    WHERE u.id = ?"
);
$me->execute([current_user_id()]);
$me = $me->fetch();

try {
    $pdo->prepare("CALL sp_endorse_user(?, ?, ?, ?, ?)")
        ->execute([$endorsee, current_user_id(), $me['name'], ucfirst($me['role']) . ' on GigLink', $note]);
    flash_set('ok', 'Endorsement added.');
} catch (PDOException $e) {
    flash_set('err', $e->getMessage());
}
redirect($_SERVER['HTTP_REFERER'] ?? '/giglink/');
