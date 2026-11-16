<?php
$page_title = 'Conversation';
require __DIR__ . '/includes/header.php';
require_login();
$uid = current_user_id();
$app_id = (int)($_GET['application_id'] ?? $_POST['application_id'] ?? 0);

// Verify the viewer is a party to the application
$row = $pdo->prepare(
  "SELECT a.*, j.title AS job_title, j.employer_id
     FROM applications a JOIN jobs j ON j.id = a.job_id
    WHERE a.id = ? AND (a.youth_id = ? OR j.employer_id = ?)"
);
$row->execute([$app_id, $uid, $uid]);
$row = $row->fetch();
if (!$row || !in_array($row['status'], ['accepted','in_progress','disputed','completed'], true)) {
    http_response_code(403);
    echo '<h1>Conversation not available</h1>';
    echo '<p class="muted">You can only message the other party of an accepted or completed gig.</p>';
    require __DIR__ . '/includes/footer.php'; exit;
}

$other_id = $uid === (int)$row['youth_id'] ? (int)$row['employer_id'] : (int)$row['youth_id'];

// Send
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $body = trim($_POST['body'] ?? '');
    if ($body !== '' && strlen($body) <= 2000) {
        $pdo->prepare("INSERT INTO messages (application_id, sender_id, body) VALUES (?,?,?)")
            ->execute([$app_id, $uid, $body]);
        notify($pdo, $other_id, 'new_message',
            'New message about "' . $row['job_title'] . '"',
            '/giglink/message_thread.php?application_id=' . $app_id);
        audit($pdo, 'message.send', 'application', $app_id);
    }
    redirect('/giglink/message_thread.php?application_id=' . $app_id);
}

// Mark unread from the other party as read
$pdo->prepare("UPDATE messages SET read_at = NOW() WHERE application_id = ? AND sender_id <> ? AND read_at IS NULL")
    ->execute([$app_id, $uid]);

$msgs = $pdo->prepare(
  "SELECT * FROM messages WHERE application_id = ? ORDER BY created_at"
);
$msgs->execute([$app_id]);
$msgs = $msgs->fetchAll();

$other = $pdo->prepare(
  "SELECT u.id, u.photo_path, u.phone,
          COALESCE(yp.full_name, ep.business_name, u.username) AS display_name
     FROM users u
     LEFT JOIN youth_profiles    yp ON yp.user_id = u.id
     LEFT JOIN employer_profiles ep ON ep.user_id = u.id
    WHERE u.id = ?"
);
$other->execute([$other_id]);
$other = $other->fetch();
?>

<div class="profile-header">
    <?= avatar($other['display_name'], '', $other['photo_path']) ?>
    <div style="flex:1">
        <h1 style="margin:0; font-size:1.3rem"><?= e($other['display_name']) ?></h1>
        <div class="muted" style="font-size:0.82rem"><?= icon('work') ?> <?= e($row['job_title']) ?> · <?= icon('call') ?> <?= e($other['phone'] ?: '—') ?></div>
    </div>
    <a class="btn btn-sm btn-ghost" href="messages.php">← All threads</a>
</div>

<div class="msg-thread" id="thread">
    <?php if (!$msgs): ?>
        <p class="muted center" style="padding:1rem">No messages yet. Say hi.</p>
    <?php endif; ?>
    <?php foreach ($msgs as $m): ?>
        <?php $mine = (int)$m['sender_id'] === $uid; ?>
        <div class="msg <?= $mine ? 'msg-me' : 'msg-them' ?>">
            <div style="white-space:pre-wrap"><?= e($m['body']) ?></div>
            <div class="meta"><?= e(substr($m['created_at'], 11, 5)) ?> · <?= e(substr($m['created_at'], 0, 10)) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<form method="post" class="form" style="margin-top:1rem; max-width:none">
    <?= csrf_field() ?>
    <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
    <div class="row" style="margin-bottom:0.5rem">
        <textarea name="body" required maxlength="2000" placeholder="Write a message… (location pin, timing, changes, etc.)"></textarea>
    </div>
    <button class="btn"><?= icon('send') ?> Send</button>
</form>

<script>
// Scroll to the latest message on load
(function() {
    const t = document.getElementById('thread');
    if (t) t.scrollTop = t.scrollHeight;
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
