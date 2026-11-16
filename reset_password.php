<?php
$page_title = 'Reset password';
require __DIR__ . '/includes/header.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$hash  = $token ? hash('sha256', $token) : '';
$err = null;
$done = false;

$row = null;
if ($hash) {
    $stmt = $pdo->prepare(
      "SELECT pr.*, u.username
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.role<>'admin' AND u.active=1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    require_csrf();
    $pass = $_POST['password'] ?? '';
    if (strlen($pass) < 6) {
        $err = 'Password must be at least 6 characters.';
    } else {
        require_once __DIR__ . '/includes/workflow.php';
        try {
            workflow_transaction($pdo,function () use ($pdo,$row,$pass) {
                $account = workflow_row($pdo,"SELECT role,active FROM users WHERE id=? FOR UPDATE",[$row['user_id']]);
                $reset = workflow_row($pdo,'SELECT id FROM password_resets WHERE id=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[$row['id']]);
                if (!$account || $account['role'] === 'admin' || !$account['active'] || !$reset) {
                    throw new DomainException('This reset link is no longer available.');
                }
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                    ->execute([password_hash($pass,PASSWORD_BCRYPT),$row['user_id']]);
                $pdo->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?")->execute([$row['id']]);
                $pdo->prepare("UPDATE api_tokens SET revoked=1 WHERE user_id=?")->execute([$row['user_id']]);
                audit($pdo,'password.reset','users',(int)$row['user_id']);
            });
            $done = true;
        } catch (Throwable $error) {
            $err = workflow_error($error);
        }
    }
}
?>

<div style="max-width:420px; margin: 2rem auto 0">
    <div class="center" style="margin-bottom:1.5rem">
        <h1><?= icon('key') ?> Reset password</h1>
    </div>

    <?php if (!$token || !$row): ?>
        <div class="flash flash-err"><?= icon('error') ?> This reset link is invalid or has expired. Contact Camtel to issue a new one.</div>
        <p class="center"><a href="login.php" class="btn btn-outline">Back to login</a></p>
    <?php elseif ($done): ?>
        <div class="flash flash-ok"><?= icon('check_circle') ?> Password updated. You can log in now with your new password.</div>
        <p class="center"><a href="login.php" class="btn">Log in</a></p>
    <?php else: ?>
        <?php if ($err): ?><div class="flash flash-err"><?= icon('error') ?> <?= e($err) ?></div><?php endif; ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <p class="muted">Resetting password for <strong>@<?= e($row['username']) ?></strong>.</p>
            <div class="row">
                <label>New password</label>
                <input type="password" name="password" required minlength="6" autofocus>
                <small>At least 6 characters.</small>
            </div>
            <button class="btn btn-lg" style="width:100%"><?= icon('check') ?> Set new password</button>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
