<?php
$page_title = 'Join as worker';
require __DIR__ . '/includes/header.php';

$errs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = strtolower(trim($_POST['username'] ?? ''));
    $email    = trim($_POST['email'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $phone    = trim($_POST['phone'] ?? '');
    $name     = trim($_POST['full_name'] ?? '');
    $bio      = trim($_POST['bio'] ?? '');
    $edu      = trim($_POST['education'] ?? '');
    $dob      = $_POST['dob'] ?: null;
    $loc_id   = (int)($_POST['location_id'] ?? 0) ?: null;
    $skills   = $_POST['skills'] ?? [];

    if ($u_err = username_error($username))         $errs[] = $u_err;
    if ($p_err = phone_error($phone))                $errs[] = $p_err;
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Invalid email.';
    if (strlen($pass) < 6)                           $errs[] = 'Password must be at least 6 characters.';
    if ($name === '')                                $errs[] = 'Full name required.';

    if (!$errs) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, role, phone) VALUES (?, ?, ?, 'youth', ?)"
            );
            $stmt->execute([$username, $email ?: null, password_hash($pass, PASSWORD_BCRYPT), $phone]);
            $uid = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO youth_profiles (user_id, full_name, bio, education, date_of_birth, location_id)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$uid, $name, $bio, $edu, $dob, $loc_id]);

            if ($skills) {
                $ins = $pdo->prepare("INSERT INTO youth_skills (youth_id, skill_id) VALUES (?, ?)");
                foreach ($skills as $sid) $ins->execute([$uid, (int)$sid]);
            }
            $pdo->commit();

            login_user(['id' => $uid, 'username' => $username, 'email' => $email, 'role' => 'youth']);
            flash_set('ok', 'Welcome to GigLink!');
            redirect('/giglink/youth/dashboard.php');
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
$all_skills = $pdo->query("SELECT id, name FROM skills ORDER BY name")->fetchAll();
$selected_skills = array_map('intval', $_POST['skills'] ?? []);
?>

<div class="form-page">
    <div class="form-head">
        <h1><?= icon('person_add') ?> Join as a worker</h1>
        <p class="muted">Build a verifiable work identity, one gig at a time.</p>
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
                       placeholder="e.g. alice_w">
                <small>3–30 chars: lowercase letters, numbers, underscores.</small>
            </div>
            <div>
                <label>Phone</label>
                <input type="tel" name="phone" required value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+256…">
                <small>Required — employers use this to reach you.</small>
            </div>
        </div>
        <div class="row inline">
            <div>
                <label>Password</label>
                <input type="password" name="password" required minlength="6">
                <small>At least 6 characters.</small>
            </div>
            <div>
                <label>Email <span class="muted">(optional)</span></label>
                <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>
        </div>
        <div class="row">
            <label>Full name</label>
            <input type="text" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">
        </div>
        <div class="row inline">
            <div><label>Date of birth</label><input type="date" name="dob" value="<?= e($_POST['dob'] ?? '') ?>"></div>
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
            <label>Education</label>
            <input type="text" name="education" placeholder="e.g. S4, Diploma, Degree" value="<?= e($_POST['education'] ?? '') ?>">
        </div>
        <div class="row">
            <label>About you</label>
            <textarea name="bio" placeholder="A short bio — reliability, hours you can work, past experience."><?= e($_POST['bio'] ?? '') ?></textarea>
        </div>
        <div class="row">
            <label>Skills (pick all that apply)</label>
            <div class="check-grid">
                <?php foreach ($all_skills as $s): ?>
                    <label>
                        <input type="checkbox" name="skills[]" value="<?= (int)$s['id'] ?>"
                               <?= in_array((int)$s['id'], $selected_skills, true) ? 'checked' : '' ?>>
                        <?= e($s['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button class="btn btn-lg" style="width:100%"><?= icon('check') ?> Create account</button>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
