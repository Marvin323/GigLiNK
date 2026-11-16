<?php
$page_title = 'GigLink Work ID';
require __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT user_id, full_name, photo_path, skills, jobs_completed, avg_rating,
    rating_count FROM v_worker_summary WHERE user_id=?");
$stmt->execute([$id]);
$worker = $stmt->fetch();
if (!$worker) {
    http_response_code(404);
    echo '<h1>Worker not found</h1>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$work = $pdo->prepare("SELECT COALESCE(c.name, 'General work') AS category, a.completed_at
    FROM applications a JOIN jobs j ON j.id=a.job_id LEFT JOIN categories c ON c.id=j.category_id
    WHERE a.youth_id=? AND a.status='completed' ORDER BY a.completed_at DESC,a.id DESC LIMIT 5");
$work->execute([$id]);
$recent = $work->fetchAll();
$endorsements = $pdo->prepare("SELECT e.endorser_name,e.endorser_role,e.created_at FROM endorsements e
    JOIN users issuer ON issuer.id=e.endorser_user_id
    WHERE e.endorsee_user_id=? AND issuer.active=1 AND issuer.deleted_at IS NULL
    ORDER BY e.created_at DESC,e.id DESC LIMIT 5");
$endorsements->execute([$id]);
$issuers = $endorsements->fetchAll();

$publicUrl = base_url() . '/profile_qr.php?id=' . $id;
$qr = 'https://api.qrserver.com/v1/create-qr-code/?size=192x192&data=' . rawurlencode($publicUrl);
$number = 'GL-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
?>
<article class="card work-id">
    <header class="work-id-head">
        <div class="workflow-title">
            <?= avatar($worker['full_name'], 'lg', $worker['photo_path']) ?>
            <div>
                <p>GigLink Work ID · <?= e($number) ?></p>
                <h1><?= e($worker['full_name']) ?></h1>
            </div>
        </div>
        <button class="btn btn-outline no-print" type="button" data-print>Print work ID</button>
    </header>
    <div class="work-id-body">
        <div>
            <dl class="work-id-metrics">
                <div><dt>Completed gigs</dt><dd><?= (int)$worker['jobs_completed'] ?></dd></div>
                <div><dt>Employer reviews</dt><dd><?= (int)$worker['rating_count'] ?></dd></div>
                <div><dt>Average rating</dt><dd><?= $worker['rating_count'] ? e($worker['avg_rating']) . '/5' : '—' ?></dd></div>
            </dl>
            <section>
                <h2>Skills</h2>
                <p><?= e($worker['skills'] ?: 'No skills listed yet.') ?></p>
            </section>
            <section>
                <h2>Recent completed work</h2>
                <?php if ($recent): ?>
                    <ul class="work-id-list">
                        <?php foreach ($recent as $gig): ?>
                            <li><strong><?= e($gig['category']) ?></strong>
                            <?php if ($gig['completed_at']): ?><span><?= e(substr($gig['completed_at'], 0, 7)) ?></span><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?><p>No completed gigs yet.</p><?php endif; ?>
            </section>
            <section id="endorsements">
                <h2>Endorsements from GigLink accounts</h2>
                <?php if ($issuers): ?>
                    <ul class="work-id-list">
                        <?php foreach ($issuers as $issuer): ?>
                            <li><strong><?= e($issuer['endorser_name']) ?></strong>
                                <span><?= e($issuer['endorser_role']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?><p>No endorsements from active accounts yet.</p><?php endif; ?>
            </section>
        </div>
        <aside class="work-id-qr">
            <img src="<?= e($qr) ?>" alt="QR code for this public GigLink Work ID" width="192" height="192" referrerpolicy="no-referrer">
            <p>Scan to open this public Work ID.</p>
            <a href="<?= e($publicUrl) ?>">Public work ID link</a>
        </aside>
    </div>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>

