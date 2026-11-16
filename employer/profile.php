<?php
$page_title = 'My profile';
require __DIR__ . '/../includes/header.php';
require_role('employer');
$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $phone = trim($_POST['phone'] ?? '');
    if ($p_err = phone_error($phone)) {
        flash_set('err', $p_err);
        redirect('profile.php');
    }
    $pdo->prepare(
      "UPDATE employer_profiles
          SET business_name=?, business_type=?, bio=?, location_id=?
        WHERE user_id=?"
    )->execute([
        trim($_POST['business_name']),
        trim($_POST['business_type']),
        trim($_POST['bio']),
        ($_POST['location_id'] ?? '') === '' ? null : (int)$_POST['location_id'],
        $uid,
    ]);
    $pdo->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone, $uid]);
    flash_set('ok', 'Profile saved.');
    redirect('profile.php');
}

$me = $pdo->prepare("SELECT u.phone, ep.* FROM users u JOIN employer_profiles ep ON ep.user_id=u.id WHERE u.id=?");
$me->execute([$uid]);
$me = $me->fetch();

$locations = fetch_options($pdo, 'locations');
?>

<?php $my_photo = current_photo($pdo); ?>
<div class="form-page">
    <div class="form-head">
        <h1><?= icon('storefront') ?> My profile</h1>
        <p class="muted">Keep your business info current so workers trust you.</p>
    </div>

<form method="post" action="/giglink/actions/upload_photo.php" enctype="multipart/form-data" class="form" style="margin-bottom:1rem">
    <?= csrf_field() ?>
    <div style="display:flex; align-items:center; gap:1rem">
        <?= avatar($me['business_name'], 'lg', $my_photo) ?>
        <div style="flex:1">
            <label>Logo / photo</label>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
            <small>JPG, PNG or WebP, up to 3 MB.</small>
        </div>
        <button class="btn btn-sm"><?= icon('upload') ?> Save photo</button>
    </div>
</form>

<form method="post" class="form">
    <?= csrf_field() ?>
    <div class="row">
        <label>Business / household name</label>
        <input type="text" name="business_name" required value="<?= e($me['business_name']) ?>">
    </div>
    <div class="row inline">
        <div><label>Business type</label><input type="text" name="business_type" value="<?= e($me['business_type']) ?>"></div>
        <div><label>Phone</label><input type="tel" name="phone" required value="<?= e($me['phone']) ?>" placeholder="+256…"></div>
    </div>
    <div class="row">
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
    <div class="row">
        <label>Bio</label>
        <textarea name="bio"><?= e($me['bio']) ?></textarea>
    </div>
    <button class="btn btn-lg" style="width:100%"><?= icon('save') ?> Save profile</button>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
