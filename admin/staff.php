<?php
$page_title = 'Staff permissions';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/staff.php';
require_staff($pdo,'staff.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
        change_staff($pdo,current_user_id(),(int)($_POST['user'] ?? 0),$role === 'revoke' ? null : $role,
            ($_POST['enabled'] ?? '') === '1');
        flash_set('ok','Staff permissions saved.');
    } catch (Throwable $error) {
        flash_set('err',workflow_error($error));
    }
    redirect('/giglink/admin/staff.php');
}
$staff = $pdo->query('SELECT s.*,u.username,u.active FROM staff_roles s JOIN users u ON u.id=s.user_id ORDER BY u.username');
?>
<header class="section-header"><div><a href="index.php">Admin dashboard</a><h1>Staff permissions</h1></div></header>
<form method="post" class="card workflow-card workflow-form">
    <?= csrf_field() ?>
    <label>Account ID <input name="user" type="number" min="1" required></label>
    <label>Role <select name="role" required>
        <?php foreach (STAFF_ROLES as $key=>$label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
        <option value="revoke">Revoke staff access</option>
    </select></label>
    <label><input type="checkbox" name="enabled" value="1" checked> Enable staff access</label>
    <button class="btn">Save staff permissions</button>
</form>
<h2>Current staff</h2>
<div class="workflow-grid">
    <?php foreach ($staff as $member): ?>
        <article class="card workflow-card">
            <h3>@<?= e($member['username']) ?> · #<?= (int)$member['user_id'] ?></h3>
            <p><?= e(STAFF_ROLES[$member['role']]) ?> · <?= $member['enabled'] && $member['active'] ? 'Active' : 'Access disabled' ?></p>
        </article>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

