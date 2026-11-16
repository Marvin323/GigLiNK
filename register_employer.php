<?php
$page_title = 'Join as employer';
require __DIR__ . '/includes/header.php';

$errs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = strtolower(trim($_POST['username'] ?? ''));
    $email    = trim($_POST['email'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $phone    = trim($_POST['phone'] ?? '');
    $business = trim($_POST['business_name'] ?? '');
    $type     = trim($_POST['business_type'] ?? '');
    $bio      = trim($_POST['bio'] ?? '');
    $loc_id   = (int)($_POST['location_id'] ?? 0) ?: null;

    if ($u_err = username_error($username))          $errs[] = $u_err;
    if ($p_err = phone_error($phone))                 $errs[] = $p_err;
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Invalid email.';
    if (strlen($pass) < 6)                            $errs[] = 'Password must be at least 6 characters.';
    if ($business === '')                              $errs[] = 'Business or household name required.';

    if (!$errs) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
              "INSERT INTO users (username, email, password_hash, role, phone) VALUES (?,?,?, 'employer', ?)"
            )->execute([$username, $email ?: null, password_hash($pass, PASSWORD_BCRYPT), $phone]);
            $uid = (int)$pdo->lastInsertId();
            $pdo->prepare(
              "INSERT INTO employer_profiles (user_id, business_name, business_type, bio, location_id)
               VALUES (?,?,?,?,?)"
            )->execute([$uid, $business, $type, $bio, $loc_id]);
            $pdo->commit();

            login_user(['id' => $uid, 'username' => $username, 'email' => $email, 'role' => 'employer']);
            flash_set('ok', 'Welcome to GigLink!');
            redirect('/giglink/employer/dashboard.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $m = $e->getMessage();
            if (str_contains($m, "uq_username")) $errs[] = 'That username is already taken.';
            elseif (str_contains($m, 'Duplicate') && str_contains($m, 'email')) $errs[] = 'That email is already registered.';
            elseif (str_contains($m, 'Duplicate')) $errs[] = 'That username or email is already taken.';
            else $errs[] = $m;
        }
    }
}

$locations = fetch_options($pdo, 'locations');
?>

<div class="form-page">
    <div class="form-head">
        <h1><?= icon('storefront') ?> Join as an employer</h1>
        <p class="muted">Find trustworthy local workers for your business or household.</p>
    </div>

    <?php foreach ($errs as $e): ?><div class="flash flash-err"><?= icon('error') ?> <?= e($e) ?></div><?php endforeach; ?>

    <form method="post" class="form">
        <?= csrf_field() ?>
        <div class="row inline">
            <div>
                <label>Username</label>
                <input type="text" name="username" required
                       value="<?= e($_POST['username'] ?? '') ?>"
                       pattern="[a-zA-Z0-9_]{3,30}" minlength="3" maxlength="30"
                       autocapitalize="none" autocomplete="username"
                       placeholder="e.g. jamii_shop">
                <small>3–30 chars: lowercase letters, numbers, underscores.</small>
            </div>
            <div>
                <label>Phone</label>
                <input type="tel" name="phone" required value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+256…">
                <small>Required — workers reach you here after acceptance.</small>
            </div>
        </div>
        <div class="row inline">
            <div>
                <label>Password</label>
                <input type="password" name="password" required minlength="6">
            </div>
            <div>
                <label>Email <span class="muted">(optional)</span></label>
                <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
        </div>
        <div class="row">
            <label>Business / household name</label>
            <input type="text" name="business_name" required value="<?= e($_POST['business_name'] ?? '') ?>">
        </div>
        <div class="row inline">
            <div>
                <label>Business type</label>
                <input type="text" name="business_type" placeholder="e.g. Household, Shop, SACCO" value="<?= e($_POST['business_type'] ?? '') ?>">
            </div>
            <div>
                <label>District</label>
                <select name="location_id">
                    <option value="">— pick district —</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= (int)($_POST['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>>
                            <?= e($l['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <label>About</label>
            <textarea name="bio" placeholder="Short description of your business or household."><?= e($_POST['bio'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-lg" style="width:100%"><?= icon('check') ?> Create account</button>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
