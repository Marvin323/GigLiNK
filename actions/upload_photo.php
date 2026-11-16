<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_login();
require_csrf();

try {
    $path = save_upload($_FILES['photo'] ?? [], 'photos', current_user_id());
    if (!$path) throw new RuntimeException('No file received.');

    // Remove old photo if any
    $old = $pdo->prepare("SELECT photo_path FROM users WHERE id = ?");
    $old->execute([current_user_id()]);
    $old = (string)$old->fetchColumn();
    if ($old && is_file(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);

    $pdo->prepare("UPDATE users SET photo_path = ? WHERE id = ?")
        ->execute([$path, current_user_id()]);
    audit($pdo, 'profile.photo_upload', 'users', current_user_id());
    flash_set('ok', 'Photo updated.');
} catch (Throwable $e) {
    flash_set('err', $e->getMessage());
}

$back = current_role() === 'youth' ? '/giglink/youth/profile.php' : '/giglink/employer/profile.php';
redirect($back);
