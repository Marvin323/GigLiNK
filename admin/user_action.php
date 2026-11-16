<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/staff.php';
require_staff($pdo, 'users.manage');
require_csrf();

$uid    = (int)($_POST['user_id'] ?? 0);
$op     = $_POST['op'] ?? '';
$reason = trim($_POST['reason'] ?? '');

$target = workflow_row($pdo,'SELECT role FROM users WHERE id=?',[$uid]);
if (!$target || $target['role'] === 'admin') {
    flash_set('err','Use Staff permissions to manage staff access.');
    redirect('/giglink/admin/users.php');
}
switch ($op) {
    case 'suspend':
        $pdo->prepare("UPDATE users SET active=0, suspended_reason=? WHERE id=? AND role<>'admin'")
            ->execute([$reason ?: 'Policy violation', $uid]);
        notify($pdo, $uid, 'account', 'Your account has been suspended by an admin. Reason: ' . ($reason ?: 'Policy violation'), '/giglink/help.php');
        audit($pdo, 'admin.suspend', 'users', $uid, ['reason' => $reason]);
        flash_set('ok', 'User suspended.');
        break;
    case 'activate':
        $pdo->prepare("UPDATE users SET active=1, suspended_reason=NULL WHERE id=? AND role<>'admin'")->execute([$uid]);
        notify($pdo, $uid, 'account', 'Your account has been reactivated.', '/giglink/');
        audit($pdo, 'admin.activate', 'users', $uid);
        flash_set('ok', 'User reactivated.');
        break;
    case 'toggle_verified':
        $pdo->prepare("UPDATE employer_profiles SET verified = NOT verified WHERE user_id=?")->execute([$uid]);
        audit($pdo, 'admin.toggle_verified', 'employer_profiles', $uid);
        flash_set('ok', 'Verified flag toggled.');
        break;
    case 'reset_password':
        $token  = bin2hex(random_bytes(32));
        $hash   = hash('sha256', $token);
        $exp    = date('Y-m-d H:i:s', time() + 24 * 3600);
        $pdo->prepare(
          "INSERT INTO password_resets (user_id, token_hash, expires_at, issued_by) VALUES (?,?,?,?)"
        )->execute([$uid, $hash, $exp, current_user_id()]);
        audit($pdo, 'admin.reset_password', 'users', $uid);
        // Build the link admins can share with the user offline
        $url = base_url() . '/reset_password.php?token=' . $token;
        flash_set('ok', 'Reset link (share with the user, valid 24h): ' . $url);
        break;
    default:
        flash_set('err', 'Unknown action.');
}

redirect('/giglink/admin/users.php');
