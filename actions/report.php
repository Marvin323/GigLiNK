<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_login();
require_csrf();

$target_user = (int)($_POST['target_user_id'] ?? 0) ?: null;
$target_job  = (int)($_POST['target_job_id']  ?? 0) ?: null;
$reason      = trim($_POST['reason'] ?? '');
$details     = trim($_POST['details'] ?? '');

if (!$target_user && !$target_job) {
    flash_set('err', 'Nothing to report.');
    redirect($_SERVER['HTTP_REFERER'] ?? '/giglink/');
}
if ($reason === '') {
    flash_set('err', 'Please pick a reason.');
    redirect($_SERVER['HTTP_REFERER'] ?? '/giglink/');
}
if ($target_user === current_user_id()) {
    flash_set('err', 'You cannot report yourself.');
    redirect($_SERVER['HTTP_REFERER'] ?? '/giglink/');
}

$pdo->prepare(
  "INSERT INTO reports (reporter_user_id, target_user_id, target_job_id, reason, details)
   VALUES (?,?,?,?,?)"
)->execute([current_user_id(), $target_user, $target_job, $reason, $details]);

audit($pdo, 'report.create', 'reports', (int)$pdo->lastInsertId(),
      ['target_user' => $target_user, 'target_job' => $target_job, 'reason' => $reason]);

// Also notify admins so they see it quickly
foreach ($pdo->query("SELECT u.id FROM users u JOIN staff_roles s ON s.user_id=u.id WHERE u.role='admin' AND u.active=1 AND u.deleted_at IS NULL AND s.enabled=1 AND s.role IN ('super_admin','support_officer')") as $admin) {
    notify($pdo, (int)$admin['id'], 'report', 'A new user report was filed', '/giglink/admin/reports.php');
}

flash_set('ok', 'Report submitted. Camtel will review it.');
redirect($_SERVER['HTTP_REFERER'] ?? '/giglink/');
