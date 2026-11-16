<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_role('youth');
require_csrf();

$job_id = (int)($_POST['job_id'] ?? 0);
$action = $_POST['action_type'] ?? 'save';

try {
    if ($action === 'unsave') {
        $pdo->prepare("DELETE FROM saved_jobs WHERE user_id=? AND job_id=?")
            ->execute([current_user_id(), $job_id]);
        flash_set('ok', 'Removed from saved.');
    } else {
        $pdo->prepare("INSERT IGNORE INTO saved_jobs (user_id, job_id) VALUES (?,?)")
            ->execute([current_user_id(), $job_id]);
        flash_set('ok', 'Saved.');
    }
} catch (PDOException $e) {
    flash_set('err', $e->getMessage());
}

redirect($_SERVER['HTTP_REFERER'] ?? '/giglink/jobs.php');
