<?php
$page_title = 'Admin';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/staff.php';
require_staff($pdo);

$users         = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
$active_jobs   = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn();
$open_reports  = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status='open'")->fetchColumn();
$completed     = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='completed'")->fetchColumn();
?>

<h1><?= icon('admin_panel_settings') ?> Admin dashboard</h1>

<?php if (staff_can($pdo,current_user_id(),'staff.manage')): ?>
<div class="stat-grid">
    <div class="stat">
        <div class="stat-icon"><?= icon('groups') ?></div>
        <div><div class="n"><?= $users ?></div><div class="l">Users</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('work') ?></div>
        <div><div class="n"><?= $active_jobs ?></div><div class="l">Open jobs</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('flag') ?></div>
        <div><div class="n"><?= $open_reports ?></div><div class="l">Open reports</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('done_all') ?></div>
        <div><div class="n"><?= $completed ?></div><div class="l">Gigs completed</div></div>
    </div>
</div>

<?php endif; ?>
<div class="card-grid">
    <?php if (staff_can($pdo,current_user_id(),'staff.manage')): ?><a class="card feature" href="staff.php"><h3>Staff permissions</h3></a><?php endif; ?>
    <?php if (staff_can($pdo,current_user_id(),'finance.read')): ?><a class="card feature" href="finance.php"><h3>Finance dashboard</h3></a><?php endif; ?>
    <?php if (staff_can($pdo,current_user_id(),'disputes')): ?><a href="/giglink/disputes.php" class="card feature"><h3>Dispute queue</h3><p>Review statements, evidence and decisions.</p></a><?php endif; ?>
    <?php if (staff_can($pdo,current_user_id(),'finance.read')): ?><a href="reconcile.php" class="card feature"><h3>Payment reconciliation</h3><p>Review historical payments with no application association.</p></a><?php endif; ?>
    <?php if (staff_can($pdo,current_user_id(),'users.manage')): ?><a href="users.php"   class="card feature"><div class="feature-icon"><?= icon('manage_accounts') ?></div><h3>Users</h3><p>Suspend, verify, reset password.</p></a><?php endif; ?>
    <?php if (staff_can($pdo,current_user_id(),'support')): ?><a href="reports.php" class="card feature"><div class="feature-icon"><?= icon('flag') ?></div><h3>Reports queue</h3><p>Review user and job reports.</p></a><?php endif; ?>
    <?php if (staff_can($pdo,current_user_id(),'audit')): ?><a href="audit.php"   class="card feature"><div class="feature-icon"><?= icon('receipt_long') ?></div><h3>Audit log</h3><p>Important actions, chronologically.</p></a><?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
