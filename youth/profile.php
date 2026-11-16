<?php
$page_title = 'My profile';
require __DIR__ . '/../includes/header.php';
require_role('youth');

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $phone = trim($_POST['phone'] ?? '');
    if ($p_err = phone_error($phone)) {
        flash_set('err', $p_err);
        redirect('profile.php');
    }
    $pdo->prepare(
      "UPDATE youth_profiles
          SET full_name=?, bio=?, education=?, date_of_birth=?, location_id=?
        WHERE user_id=?"
    )->execute([
        trim($_POST['full_name']),
        trim($_POST['bio']),
        trim($_POST['education']),
        $_POST['dob'] ?: null,
        ($_POST['location_id'] ?? '') === '' ? null : (int)$_POST['location_id'],
        $uid,
    ]);
    $pdo->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone, $uid]);

    // Rewrite skills
    $pdo->prepare("DELETE FROM youth_skills WHERE youth_id=?")->execute([$uid]);
    $ins = $pdo->prepare("INSERT INTO youth_skills (youth_id, skill_id) VALUES (?, ?)");
    foreach (($_POST['skills'] ?? []) as $sid) $ins->execute([$uid, (int)$sid]);

    flash_set('ok', 'Profile saved.');
    redirect('profile.php');
}

$me = $pdo->prepare("SELECT u.phone, yp.* FROM users u JOIN youth_profiles yp ON yp.user_id=u.id WHERE u.id=?");
$me->execute([$uid]);
$me = $me->fetch();

$current_skills = $pdo->prepare("SELECT skill_id FROM youth_skills WHERE youth_id=?");
$current_skills->execute([$uid]);
$current_skills = array_map('intval', array_column($current_skills->fetchAll(), 'skill_id'));

$locations  = fetch_options($pdo, 'locations');
$all_skills = $pdo->query("SELECT id, name FROM skills ORDER BY name")->fetchAll();
?>

<?php $my_photo = current_photo($pdo); ?>
<div class="form-page">
    <div class="form-head">
        <h1><?= icon('person') ?> My profile</h1>
        <p class="muted">Keep your profile fresh so employers can find you.</p>
    </div>

<form method="post" action="/giglink/actions/upload_photo.php" enctype="multipart/form-data" class="form" style="margin-bottom:1rem">
    <?= csrf_field() ?>
    <div style="display:flex; align-items:center; gap:1rem">
        <?= avatar($me['full_name'], 'lg', $my_photo) ?>
        <div style="flex:1">
            <label>Profile photo</label>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
            <small>JPG, PNG or WebP, up to 3 MB.</small>
        </div>
        <button class="btn btn-sm"><?= icon('upload') ?> Save photo</button>
    </div>
</form>

<form method="post" class="form">
    <?= csrf_field() ?>
    <div class="row">
        <label>Full name</label>
        <input type="text" name="full_name" required value="<?= e($me['full_name']) ?>">
    </div>
    <div class="row inline">
        <div><label>Phone</label><input type="tel" name="phone" required value="<?= e($me['phone']) ?>" placeholder="+256…"></div>
        <div><label>Date of birth</label><input type="date" name="dob" value="<?= e($me['date_of_birth']) ?>"></div>
    </div>
    <div class="row inline">
        <div>
            <label>District</label>
            <select name="location_id">
                <option value="">— pick district —</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int)$l['id'] ?>" <?= (int)$me['location_id'] === (int)$l['id'] ? 'selected' : '' ?>>
                        <?= e($l['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Education</label>
            <input type="text" name="education" value="<?= e($me['education']) ?>">
        </div>
    </div>
    <div class="row">
        <label>Bio</label>
        <textarea name="bio"><?= e($me['bio']) ?></textarea>
    </div>
    <div class="row">
        <label>Skills (pick all that apply)</label>
        <div class="check-grid">
            <?php foreach ($all_skills as $s): ?>
                <label>
                    <input type="checkbox" name="skills[]" value="<?= (int)$s['id'] ?>"
                           <?= in_array((int)$s['id'], $current_skills, true) ? 'checked' : '' ?>>
                    <?= e($s['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <button class="btn btn-lg" style="width:100%"><?= icon('save') ?> Save profile</button>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
