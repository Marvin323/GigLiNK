<?php
$page_title = 'Log in';
require __DIR__ . '/includes/header.php';

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = strtolower(trim($_POST['username'] ?? ''));
    $pass     = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($pass, $u['password_hash'])) {
        login_user($u);
        flash_set('ok', 'Welcome back!');
        if ($u['role'] === 'youth')    redirect('/giglink/youth/dashboard.php');
        if ($u['role'] === 'employer') redirect('/giglink/employer/dashboard.php');
        redirect('/giglink/');
    }
    $err = 'Invalid username or password.';
}
?>

<div style="max-width:420px; margin: 2rem auto 0;">
    <div class="center" style="margin-bottom: 1.5rem">
        <h1 style="margin-bottom: 0.25rem"><?= icon('login') ?> Welcome back</h1>
        <p class="muted">Log in to continue to GigLink</p>
    </div>

    <?php if ($err): ?><div class="flash flash-err"><?= icon('error') ?> <?= e($err) ?></div><?php endif; ?>

    <form method="post" class="form">
        <?= csrf_field() ?>
        <div class="row">
            <label>Username</label>
            <input type="text" name="username" required autofocus
                   value="<?= e($_POST['username'] ?? '') ?>"
                   placeholder="e.g. alice" autocapitalize="none" autocomplete="username">
        </div>
        <div class="row">
            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-lg" style="width:100%"><?= icon('login') ?> Log in</button>
        <p class="muted center" style="margin-top:1rem">
            No account?
            <a href="register_youth.php">Join as worker</a> ·
            <a href="register_employer.php">Join as employer</a>
        </p>
    </form>

    <div class="card flat" style="margin-top:1.25rem">
        <h3 style="font-size:0.9rem"><?= icon('science') ?> Demo accounts</h3>
        <p class="muted" style="font-size:0.85rem">
            <code>alice</code> · <code>brian</code> (workers)<br>
            <code>mama</code> · <code>shopowner</code> (employers)<br>
            Password: <code>password</code>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
