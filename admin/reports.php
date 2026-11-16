<?php
$page_title = 'Admin · Reports';
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/staff.php';
require_staff($pdo, 'support');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id   = (int)$_POST['id'];
    $op   = $_POST['op'] ?? '';
    $note = trim($_POST['resolution_note'] ?? '');
    if (in_array($op, ['actioned','dismissed','reviewing'], true)) {
        $pdo->prepare("UPDATE reports SET status=?, resolution_note=?, resolved_at=IF(?='reviewing', NULL, NOW()) WHERE id=?")
            ->execute([$op, $note, $op, $id]);
        audit($pdo, 'report.' . $op, 'reports', $id);
        flash_set('ok', 'Report updated.');
    }
    redirect('/giglink/admin/reports.php');
}

$rows = $pdo->query(
  "SELECT r.*,
          ru.username AS reporter_username,
          tu.username AS target_username,
          tj.title    AS target_job_title
     FROM reports r
     JOIN users ru ON ru.id = r.reporter_user_id
     LEFT JOIN users tu ON tu.id = r.target_user_id
     LEFT JOIN jobs  tj ON tj.id = r.target_job_id
    ORDER BY FIELD(r.status,'open','reviewing','actioned','dismissed'), r.created_at DESC
    LIMIT 100"
)->fetchAll();
?>

<h1><?= icon('flag') ?> Reports queue</h1>

<?php if (!$rows): ?>
    <div class="card center" style="padding:3rem">
        <p class="muted">No reports.</p>
    </div>
<?php else: ?>
    <?php foreach ($rows as $r): ?>
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap">
            <div>
                <strong><?= e($r['reason']) ?></strong>
                <div class="muted" style="font-size:0.85rem">
                    Reported by @<?= e($r['reporter_username']) ?> ·
                    <?= $r['target_username'] ? 'Against @' . e($r['target_username']) : '' ?>
                    <?= $r['target_job_title'] ? ' · Job: ' . e($r['target_job_title']) : '' ?>
                    · <?= e($r['created_at']) ?>
                </div>
            </div>
            <span class="badge badge-<?= $r['status']==='open'?'pending':($r['status']==='dismissed'?'closed':'ok') ?>">
                <?= e($r['status']) ?>
            </span>
        </div>
        <?php if ($r['details']): ?>
            <p style="margin:0.6rem 0">“<?= e($r['details']) ?>”</p>
        <?php endif; ?>
        <?php if ($r['resolution_note']): ?>
            <p class="muted" style="font-size:0.8rem">Resolution: <?= e($r['resolution_note']) ?></p>
        <?php endif; ?>

        <?php if (in_array($r['status'], ['open','reviewing'], true)): ?>
        <form method="post" style="display:flex; gap:0.4rem; margin-top:0.5rem; align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="resolution_note" placeholder="Note…" style="flex:1; padding:0.4rem; border:1px solid var(--border); border-radius:4px">
            <button name="op" value="reviewing" class="btn btn-sm btn-outline">Mark reviewing</button>
            <button name="op" value="actioned"  class="btn btn-sm btn-success"><?= icon('check') ?> Actioned</button>
            <button name="op" value="dismissed" class="btn btn-sm btn-ghost">Dismiss</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
