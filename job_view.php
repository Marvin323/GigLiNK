<?php
$page_title = 'Job';
require __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM v_jobs_public WHERE id = ?");
$stmt->execute([$id]);
$job = $stmt->fetch();
if (!$job) { http_response_code(404); echo '<h1>Not found</h1>'; require __DIR__.'/includes/footer.php'; exit; }

$es = $pdo->prepare("SELECT * FROM v_employer_summary WHERE user_id = ?");
$es->execute([$job['employer_id']]);
$employer = $es->fetch();

$applied = false;
if (current_role() === 'youth') {
    $q = $pdo->prepare("SELECT status FROM applications WHERE job_id=? AND youth_id=?");
    $q->execute([$id, current_user_id()]);
    $applied = $q->fetch();
}

$is_owner = (current_role() === 'employer') && (current_user_id() === (int)$job['employer_id']);

// Track recent view (for logged-in users, non-owner)
if (is_logged_in() && !$is_owner) {
    $pdo->prepare(
      "INSERT INTO recent_views (user_id, job_id, viewed_at) VALUES (?, ?, NOW())
       ON DUPLICATE KEY UPDATE viewed_at = NOW()"
    )->execute([current_user_id(), $id]);
}

// Is the job saved?
$is_saved = false;
if (current_role() === 'youth') {
    $s = $pdo->prepare("SELECT 1 FROM saved_jobs WHERE user_id=? AND job_id=?");
    $s->execute([current_user_id(), $id]);
    $is_saved = (bool)$s->fetchColumn();
}

// For the owner, load the applicant list inline so they don't have to leave the page
$my_applicants = [];
if ($is_owner) {
    $as = $pdo->prepare(
      "SELECT a.id AS application_id, a.status, a.is_team_lead, a.message, a.applied_at,
              ws.user_id, ws.full_name, ws.skills, ws.avg_rating, ws.rating_count, ws.verified_badge, ws.photo_path
         FROM applications a
         JOIN v_worker_summary ws ON ws.user_id = a.youth_id
        WHERE a.job_id = ?
        ORDER BY FIELD(a.status,'pending','accepted','in_progress','disputed','completed','rejected','withdrawn','cancelled'),
                 a.applied_at"
    );
    $as->execute([$id]);
    $my_applicants = $as->fetchAll();
}
?>

<div class="card">
    <div style="display:flex; align-items:flex-start; gap:1rem; margin-bottom:0.5rem">
        <?= avatar($employer['business_name'], 'lg') ?>
        <div style="flex:1">
            <h1 style="margin:0 0 0.25rem"><?= e($job['title']) ?></h1>
            <div class="muted" style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap">
                <span><?= icon('storefront') ?>
                    <a href="employer_view.php?id=<?= (int)$employer['user_id'] ?>"><?= e($employer['business_name']) ?></a>
                    <?php if ($employer['verified']): ?><span class="badge badge-verified"><?= icon('verified') ?></span><?php endif; ?>
                </span>
                <span><?= icon('location_on') ?> <?= e($job['location_name'] ?? '—') ?></span>
                <span><?= icon('category') ?> <?= e($job['category_name'] ?? '—') ?></span>
            </div>
        </div>
        <span class="badge badge-<?= $job['status']==='open'?'ok':'closed' ?>">
            <?= icon($job['status']==='open'?'bolt':'lock') ?> <?= e($job['status']) ?>
        </span>
    </div>

    <?php if (current_role() === 'youth' || is_logged_in() && !$is_owner): ?>
    <div class="row-actions" style="margin-top:0.75rem">
        <?php if (current_role() === 'youth'): ?>
        <form method="post" action="actions/save_job.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
            <input type="hidden" name="action_type" value="<?= $is_saved ? 'unsave' : 'save' ?>">
            <button class="btn btn-sm btn-outline">
                <?= icon($is_saved ? 'bookmark' : 'bookmark_border') ?>
                <?= $is_saved ? 'Saved' : 'Save' ?>
            </button>
        </form>
        <?php endif; ?>
        <?php if (is_logged_in() && !$is_owner): ?>
        <button class="btn btn-sm btn-ghost" type="button"
                onclick="document.getElementById('reportBox').style.display = (document.getElementById('reportBox').style.display==='block'?'none':'block')">
            <?= icon('flag') ?> Report this gig
        </button>
        <?php endif; ?>
    </div>
    <?php if (is_logged_in() && !$is_owner): ?>
    <div id="reportBox" class="inline-form" style="display:none">
        <form method="post" action="actions/report.php">
            <?= csrf_field() ?>
            <input type="hidden" name="target_job_id"  value="<?= (int)$job['id'] ?>">
            <input type="hidden" name="target_user_id" value="<?= (int)$job['employer_id'] ?>">
            <div class="row">
                <label>Reason</label>
                <select name="reason" required>
                    <option value="">— pick one —</option>
                    <option>Scam or fake listing</option>
                    <option>Doesn't pay</option>
                    <option>Harassment or abuse</option>
                    <option>Illegal or unsafe work</option>
                    <option>Spam / duplicate</option>
                    <option>Other</option>
                </select>
            </div>
            <div class="row">
                <label>Details (optional)</label>
                <textarea name="details" maxlength="1000" placeholder="Anything we should know?"></textarea>
            </div>
            <button class="btn btn-sm btn-danger"><?= icon('flag') ?> Submit report</button>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div style="display:flex; gap:1.5rem; margin:1rem 0; flex-wrap:wrap">
        <div><div class="muted">Pay</div><strong style="font-size:1.1rem"><?= money($job['pay_amount']) ?>
            <?= $job['pay_type'] === 'daily' ? '/day' : ($job['pay_type'] === 'hourly' ? '/hour' : '') ?></strong></div>
        <div><div class="muted">Workers needed</div><strong style="font-size:1.1rem"><?= (int)$job['workers_needed'] ?></strong></div>
        <?php if ($job['start_date']): ?>
          <div><div class="muted">Starts</div><strong><?= e($job['start_date']) ?></strong></div>
        <?php endif; ?>
        <?php if ($job['end_date']): ?>
          <div><div class="muted">Ends</div><strong><?= e($job['end_date']) ?></strong></div>
        <?php endif; ?>
    </div>

    <hr>
    <h3>Description</h3>
    <p style="white-space: pre-wrap"><?= e($job['description']) ?></p>
</div>

<div class="card">
    <h3><?= icon('info') ?> About the employer</h3>
    <p><?= stars($employer['avg_rating'], $employer['rating_count']) ?></p>
    <p class="muted"><?= e($employer['bio'] ?? '') ?></p>
    <p><a href="employer_view.php?id=<?= (int)$employer['user_id'] ?>"><?= icon('open_in_new') ?> View full profile and reviews</a></p>
</div>

<?php
// Build ONE coherent status banner based on job state + whether the viewer applied.
$job_closed = in_array($job['status'], ['completed','closed','cancelled'], true);
$banner = null;

if ($job_closed && $applied) {
    $s = $applied['status'];
    if ($s === 'completed') {
        $banner = ['ok',    'done_all',    'You completed this gig for them. See it in <a href="/giglink/youth/dashboard.php">your dashboard</a>.'];
    } elseif (in_array($s, ['accepted','in_progress','disputed'], true)) {
        $banner = ['pending','handshake',  'Your work status is <strong>'.e(str_replace('_', ' ', $s)).'</strong>. See <a href="/giglink/youth/dashboard.php">your dashboard</a> for next steps.'];
    } elseif ($s === 'pending') {
        $banner = ['info',   'done_all',   'This gig is <strong>'.e($job['status']).'</strong>. Your pending application wasn\'t decided — this hiring round has ended.'];
    } else { // rejected / withdrawn / cancelled
        $banner = ['info',   'done_all',   'This gig is <strong>'.e($job['status']).'</strong>. Your application was <strong>'.e($s).'</strong>.'];
    }
} elseif ($job_closed) {
    $banner = ['info', 'done_all', 'This gig is <strong>'.e($job['status']).'</strong>. New applications aren\'t being accepted — the employer can open a new hiring round.'];
} elseif ($applied) {
    $s = $applied['status'];
    $banner = ['ok', 'check_circle', 'You already applied. Status: <strong>'.e($s).'</strong>'];
}

if ($banner):
    [$kind, $ic, $html] = $banner;
    $cls = $kind === 'info' ? 'style="background:var(--primary-soft); color:var(--primary); border-left-color:var(--primary)"' : '';
?>
<div class="flash flash-ok" <?= $cls ?>>
    <?= icon($ic) ?> <?= $html ?>
</div>
<?php endif; ?>

<?php if ($is_owner): ?>
    <!-- Owner view: inline applicants panel -->
    <div class="section-header">
        <h2>Applicants <span class="muted" style="font-size:0.8em; font-weight:400">(<?= count($my_applicants) ?>)</span></h2>
        <a class="btn btn-sm btn-outline" href="employer/applicants.php?job_id=<?= (int)$job['id'] ?>">
            <?= icon('groups') ?> Manage all
        </a>
    </div>

    <?php if (!$my_applicants): ?>
        <div class="card center" style="padding:2rem">
            <?= icon('inbox') ?>
            <p class="muted">No one has applied yet. Share the link to this gig to reach workers.</p>
        </div>
    <?php else: ?>
        <?php foreach ($my_applicants as $a): ?>
        <div class="card" style="padding:1rem">
            <div style="display:flex; align-items:center; gap:0.75rem">
                <?= avatar($a['full_name'], '', $a['photo_path']) ?>
                <div style="flex:1">
                    <strong>
                        <a href="worker_view.php?id=<?= (int)$a['user_id'] ?>"><?= e($a['full_name']) ?></a>
                        <?php if ($a['verified_badge']): ?><span class="badge badge-verified"><?= icon('verified') ?> Experienced</span><?php endif; ?>
                        <?php if ($a['is_team_lead']): ?><span class="badge badge-primary"><?= icon('star') ?> Lead</span><?php endif; ?>
                    </strong>
                    <div class="muted" style="font-size:0.82rem">
                        <?= stars($a['avg_rating'], $a['rating_count']) ?> · Applied <?= e(substr($a['applied_at'], 0, 10)) ?>
                    </div>
                </div>
                <span class="badge badge-<?= $a['status']==='accepted'||$a['status']==='completed'?'ok':($a['status']==='pending'?'pending':'closed') ?>">
                    <?= e($a['status']) ?>
                </span>
            </div>
            <?php if ($a['skills']): ?>
                <p style="margin:0.5rem 0 0; font-size:0.88rem"><strong>Skills:</strong> <?= e($a['skills']) ?></p>
            <?php endif; ?>
            <?php if ($a['message']): ?>
                <p style="margin:0.4rem 0 0; font-size:0.88rem"><em>“<?= e($a['message']) ?>”</em></p>
            <?php endif; ?>
            <?php if ($a['status'] === 'pending'): ?>
                <div class="row-actions" style="margin-top:0.6rem">
                    <form method="post" action="actions/accept_applicant.php" style="display:inline-flex; align-items:center; gap:0.5rem">
                        <?= csrf_field() ?>
                        <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                        <label style="font-size:0.8rem; text-transform:none; letter-spacing:0; display:inline-flex; align-items:center; gap:0.3rem">
                            <input type="checkbox" name="is_team_lead" value="1"> as team lead
                        </label>
                        <button class="btn btn-sm btn-success"><?= icon('check') ?> Accept</button>
                    </form>
                    <form method="post" action="actions/reject_applicant.php" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
                        <button class="btn btn-sm btn-ghost"><?= icon('close') ?> Reject</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif (!$applied && current_role() === 'youth' && $job['status'] === 'open'): ?>
    <h2 id="apply">Apply</h2>
    <form method="post" action="actions/apply_job.php" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
        <div class="row">
            <label>Why you? (a short message to the employer)</label>
            <textarea name="message" required placeholder="e.g. I'm available on the day, I've done similar jobs, I live nearby…"></textarea>
        </div>
        <button class="btn btn-lg"><?= icon('send') ?> Send application</button>
    </form>
<?php elseif (!is_logged_in()): ?>
    <div class="card center" style="padding:2rem">
        <p>Want to apply for this gig?</p>
        <a href="register_youth.php" class="btn btn-lg"><?= icon('person_add') ?> Sign up as a worker</a>
    </div>
<?php elseif (current_role() === 'employer' && !$is_owner): ?>
    <p class="muted">You are logged in as an employer, so you cannot apply.</p>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
