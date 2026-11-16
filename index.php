<?php
$page_title = 'Home';
require __DIR__ . '/includes/header.php';

$jobs = $pdo->query(
  "SELECT * FROM v_jobs_public WHERE status='open' ORDER BY created_at DESC LIMIT 6"
)->fetchAll();

$worker_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='youth' AND active=1")->fetchColumn();
$job_count    = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn();
?>

<section class="hero">
    <div class="container">
        <h1>Real work for real people, nearby.</h1>
        <p>
            Youth unemployment is a serious community problem — young people have real skills
            but no reliable way to reach the people who would pay for that help. GigLink is not
            just a job board — it's a <strong>trust &amp; reputation layer</strong> for the
            informal economy. Build a verifiable work identity, one gig at a time.
        </p>
        <div class="btns">
            <a href="register_youth.php"   class="btn btn-lg"><?= icon('work') ?> I'm looking for work</a>
            <a href="register_employer.php" class="btn btn-lg btn-outline"><?= icon('storefront') ?> I want to hire</a>
        </div>
    </div>
</section>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-icon"><?= icon('groups') ?></div>
        <div><div class="n"><?= number_format($worker_count) ?></div><div class="l">Workers available</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('work') ?></div>
        <div><div class="n"><?= number_format($job_count) ?></div><div class="l">Open gigs</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('verified') ?></div>
        <div><div class="n"><?= (int)$pdo->query("SELECT COUNT(*) FROM endorsements")->fetchColumn() ?></div><div class="l">Endorsements given</div></div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= icon('star') ?></div>
        <div><div class="n"><?= (int)$pdo->query("SELECT COUNT(*) FROM ratings")->fetchColumn() ?></div><div class="l">Ratings exchanged</div></div>
    </div>
</div>

<div class="section-header">
    <h2>Latest open gigs</h2>
    <a href="jobs.php">View all →</a>
</div>

<?php if (!$jobs): ?>
    <p class="muted">No jobs posted yet. Be the first — <a href="register_employer.php">post one</a>.</p>
<?php else: ?>
<div class="card-grid">
    <?php foreach ($jobs as $j): ?>
        <article class="card">
            <h3><a href="job_view.php?id=<?= (int)$j['id'] ?>"><?= e($j['title']) ?></a></h3>
            <p class="muted" style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap">
                <span><?= icon('category') ?> <?= e($j['category_name'] ?? '—') ?></span>
                <span><?= icon('location_on') ?> <?= e($j['location_name'] ?? 'Any') ?></span>
                <span><?= icon('payments') ?> <?= money($j['pay_amount']) ?></span>
            </p>
            <p><?= e(mb_strimwidth($j['description'], 0, 140, '…')) ?></p>
            <p class="muted" style="display:flex; align-items:center; gap:0.4rem">
                <?= avatar($j['business_name'], 'sm') ?>
                <?= e($j['business_name']) ?>
                <span style="margin-left:auto"><?= icon('person') ?> <?= (int)$j['applicant_count'] ?> applied</span>
            </p>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-header">
    <h2>What makes GigLink different</h2>
</div>

<div class="card-grid">
    <div class="feature">
        <div class="feature-icon"><?= icon('qr_code_2') ?></div>
        <h3>Portable reputation</h3>
        <p>Every completed gig builds a verifiable work ID you can show anywhere — not locked inside the platform.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('handshake') ?></div>
        <h3>Community-vouched trust</h3>
        <p>Endorsements from chiefs, schools, churches, SACCOs — no slow paperwork, no exclusion.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('swap_horiz') ?></div>
        <h3>Two-way ratings</h3>
        <p>Workers rate employers too. Bad employers lose visibility — your side matters.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('person_search') ?></div>
        <h3>Reverse search</h3>
        <p>Post your availability window; nearby employers find <em>you</em> instead of the other way round.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('groups_2') ?></div>
        <h3>Group gigs</h3>
        <p>Need a crew of 3 for a day's work? Apply as a team, with a lead who earns a bonus.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('shield_locked') ?></div>
        <h3>Payment records</h3>
        <p>Employers record a payment for completed work. Workers can confirm receipt or raise a dispute.</p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
