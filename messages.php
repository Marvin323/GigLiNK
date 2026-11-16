<?php
$page_title = 'Messages';
require __DIR__ . '/includes/header.php';
require_login();

$uid  = current_user_id();
$role = current_role();

// Build the list of threads: one per application the viewer is a party to
// (either as the applied youth or as the job's employer).
// $uid is a trusted session int; cast and inline so the server-side prepare
// doesn't struggle with placeholder-in-subquery.
$u = (int)$uid;
$threads = $pdo->query(
  "SELECT a.id AS application_id, a.youth_id, a.status, a.job_id,
          j.title AS job_title, j.employer_id,
          CASE WHEN a.youth_id = $u THEN j.employer_id ELSE a.youth_id END AS other_id,
          (SELECT body FROM messages m WHERE m.application_id = a.id ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
          (SELECT MAX(created_at) FROM messages m WHERE m.application_id = a.id) AS last_at,
          (SELECT COUNT(*) FROM messages m
             WHERE m.application_id = a.id AND m.sender_id <> $u AND m.read_at IS NULL) AS unread
     FROM applications a
     JOIN jobs j ON j.id = a.job_id
    WHERE a.status IN ('accepted','in_progress','disputed','completed')
      AND (a.youth_id = $u OR j.employer_id = $u)
      AND EXISTS (SELECT 1 FROM messages m WHERE m.application_id = a.id)
    ORDER BY COALESCE(
        (SELECT MAX(created_at) FROM messages m WHERE m.application_id = a.id),
        a.decided_at,
        a.applied_at) DESC"
)->fetchAll();

// Resolve other-party display info
$other_ids = array_values(array_unique(array_map('intval', array_column($threads, 'other_id'))));
$others = [];
if ($other_ids) {
    $in = implode(',', $other_ids); // all ints, safe to inline
    foreach ($pdo->query(
      "SELECT u.id, u.photo_path,
              COALESCE(yp.full_name, ep.business_name, u.username) AS display_name
         FROM users u
         LEFT JOIN youth_profiles    yp ON yp.user_id = u.id
         LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.id IN ($in)"
    ) as $r) {
        $others[(int)$r['id']] = $r;
    }
}
?>

<h1><?= icon('chat') ?> Messages</h1>
<p class="muted">Threads open between you and the other party once a gig is accepted. Keep job coordination here — locations, times, changes.</p>

<?php if (!$threads): ?>
    <div class="card center" style="padding:3rem">
        <?= icon('forum') ?>
        <p class="muted">No message threads yet. Threads appear automatically once a gig is accepted.</p>
    </div>
<?php else: ?>
    <?php foreach ($threads as $t):
        $other = $others[(int)$t['other_id']] ?? ['display_name' => 'Unknown', 'photo_path' => null];
    ?>
    <a href="message_thread.php?application_id=<?= (int)$t['application_id'] ?>"
       class="card" style="display:flex; gap:0.75rem; align-items:center; text-decoration:none; color:inherit">
        <?= avatar($other['display_name'], '', $other['photo_path']) ?>
        <div style="flex:1; min-width:0">
            <strong><?= e($other['display_name']) ?></strong>
            <div class="muted" style="font-size:0.78rem"><?= icon('work') ?> <?= e($t['job_title']) ?></div>
            <div style="font-size:0.88rem; color: var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
                <?= e($t['last_msg'] ?? 'No messages yet.') ?>
            </div>
        </div>
        <?php if ($t['unread'] > 0): ?>
            <span class="badge badge-primary"><?= (int)$t['unread'] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
