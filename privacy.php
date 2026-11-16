<?php
$page_title = 'Privacy';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('privacy_tip') ?> Privacy Policy</h1>
<p class="muted">Last updated: <?= date('F Y') ?></p>

<div class="card">
    <h3>What we collect</h3>
    <ul>
        <li>Account info: username, password hash, phone, optional email.</li>
        <li>Profile info: your name or business name, bio, location, skills, availability, optional photo.</li>
        <li>Activity: your applications, jobs, messages, payments, ratings, endorsements.</li>
        <li>Technical logs: IP, browser, and times of key actions (for safety and troubleshooting).</li>
    </ul>
</div>
<div class="card">
    <h3>How we use it</h3>
    <ul>
        <li>To match workers with employers.</li>
        <li>To build a verifiable reputation visible to the other party.</li>
        <li>To contact you about your account or report violations of our terms.</li>
        <li>To improve the platform.</li>
    </ul>
</div>
<div class="card">
    <h3>What we never do</h3>
    <ul>
        <li>Sell your personal data to third parties.</li>
        <li>Reveal your contact details to employers before they accept you.</li>
        <li>Share your messages with other users outside the private thread.</li>
    </ul>
</div>
<div class="card">
    <h3>Your rights</h3>
    <p>You can request deletion of your account at any time by writing to <a href="mailto:camtelengineeringservices@gmail.com">camtelengineeringservices@gmail.com</a>. Ratings and endorsements you gave or received may be retained in anonymized form to protect the reputation of the counter-party.</p>
</div>
<div class="card">
    <h3>Contact</h3>
    <p>Camtel Engineering Services ltd — <?= icon('call') ?> +256 708 461 356 — <?= icon('mail') ?> <a href="mailto:camtelengineeringservices@gmail.com">camtelengineeringservices@gmail.com</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
