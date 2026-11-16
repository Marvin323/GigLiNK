<?php
$page_title = 'Admin · Users';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/staff.php';
require_staff($pdo, 'users.manage');

$q = trim($_GET['q'] ?? '');
$per = 25;
$page = max(1, (int)($_GET['p'] ?? 1));

$cond = "u.deleted_at IS NULL";
$args = [];
if ($q !== '') {
    $cond .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?
                    OR yp.full_name LIKE ? OR ep.business_name LIKE ?)";
    $like = "%$q%";
    $args = [$like, $like, $like, $like, $like];
}

$total = (int)$pdo->prepare(
  "SELECT COUNT(*) FROM users u
   LEFT JOIN youth_profiles yp ON yp.user_id=u.id
   LEFT JOIN employer_profiles ep ON ep.user_id=u.id
   WHERE $cond"
)->execute($args) ? 0 : 0;
$countStmt = $pdo->prepare(
  "SELECT COUNT(*) FROM users u
   LEFT JOIN youth_profiles yp ON yp.user_id=u.id
   LEFT JOIN employer_profiles ep ON ep.user_id=u.id
   WHERE $cond"
);
$countStmt->execute($args);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
  "SELECT u.id, u.username, u.email, u.phone, u.role, u.active, u.photo_path,
          u.created_at, u.suspended_reason,
          COALESCE(yp.full_name, ep.business_name) AS display_name
     FROM users u
     LEFT JOIN youth_profiles yp ON yp.user_id=u.id
     LEFT JOIN employer_profiles ep ON ep.user_id=u.id
     WHERE $cond
     ORDER BY u.created_at DESC
     LIMIT $per OFFSET " . (($page - 1) * $per)
);
$stmt->execute($args);
$users = $stmt->fetchAll();
?>

<h1><?= icon('manage_accounts') ?> Users</h1>
<div class="filters">
    <form method="get">
        <input type="text" name="q" placeholder="Search username, name, phone, email…" value="<?= e($q) ?>">
        <button class="btn btn-sm"><?= icon('search') ?> Search</button>
        <a href="users.php" class="btn btn-sm btn-ghost">Reset</a>
    </form>
</div>

<table>
    <tr>
        <th>User</th><th>Role</th><th>Contact</th><th>Status</th><th>Joined</th><th>Actions</th>
    </tr>
    <?php foreach ($users as $u): ?>
    <tr>
        <td style="display:flex; align-items:center; gap:0.5rem">
            <?= avatar($u['display_name'] ?? $u['username'], 'sm', $u['photo_path']) ?>
            <div>
                <strong><?= e($u['display_name'] ?? $u['username']) ?></strong>
                <div class="muted" style="font-size:0.78rem">@<?= e($u['username']) ?> · #<?= (int)$u['id'] ?></div>
            </div>
        </td>
        <td><span class="badge badge-primary"><?= e($u['role']) ?></span></td>
        <td class="muted" style="font-size:0.82rem">
            <?= e($u['phone']) ?><br>
            <?= e($u['email'] ?? '') ?>
        </td>
        <td>
            <?php if (!$u['active']): ?>
                <span class="badge badge-closed"><?= icon('block') ?> Suspended</span>
                <?php if ($u['suspended_reason']): ?><div class="muted" style="font-size:0.75rem"><?= e($u['suspended_reason']) ?></div><?php endif; ?>
            <?php else: ?>
                <span class="badge badge-ok">Active</span>
            <?php endif; ?>
        </td>
        <td class="muted"><?= e(substr($u['created_at'],0,10)) ?></td>
        <td>
            <?php if ($u['role'] === 'admin'): ?>
                <a href="staff.php">Manage staff access</a>
            <?php else: ?>
            <div class="row-actions">
                <?php if ($u['active']): ?>
                <form method="post" action="/giglink/admin/user_action.php" style="display:inline"
                      onsubmit="return confirm('Suspend @<?= e($u['username']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="op" value="suspend">
                    <input type="text" name="reason" placeholder="Reason" required style="width:120px; padding:0.3rem; border:1px solid var(--border); border-radius:4px; font-size:0.8rem">
                    <button class="btn btn-sm btn-danger"><?= icon('block') ?> Suspend</button>
                </form>
                <?php else: ?>
                <form method="post" action="/giglink/admin/user_action.php" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="op" value="activate">
                    <button class="btn btn-sm btn-success"><?= icon('check') ?> Reactivate</button>
                </form>
                <?php endif; ?>
                <?php if ($u['role'] === 'employer'): ?>
                <form method="post" action="/giglink/admin/user_action.php" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="op" value="toggle_verified">
                    <button class="btn btn-sm btn-outline"><?= icon('verified') ?> Toggle verified</button>
                </form>
                <?php endif; ?>
                <form method="post" action="/giglink/admin/user_action.php" style="display:inline"
                      onsubmit="return confirm('Issue a password reset for @<?= e($u['username']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="op" value="reset_password">
                    <button class="btn btn-sm btn-ghost"><?= icon('key') ?> Reset password</button>
                </form>
            </div>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?= paginate($total, $per, $page, '/giglink/admin/users.php' . ($q ? '?q=' . urlencode($q) : '')) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
