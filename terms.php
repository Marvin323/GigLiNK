<?php
$page_title = 'Terms of Service';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('gavel') ?> Terms of Service</h1>
<p class="muted">Last updated: <?= date('F Y') ?></p>

<div class="card">
    <h3>1. Who we are</h3>
    <p>GigLink is operated by Camtel Engineering Services ltd ("Camtel", "we", "us"). By using GigLink you agree to these terms.</p>
</div>
<div class="card">
    <h3>2. Who can use GigLink</h3>
    <p>You must be at least 16 years old to register. You must provide accurate information about yourself or your business. One account per person.</p>
</div>
<div class="card">
    <h3>3. Acceptable use</h3>
    <ul>
        <li>Do not post, apply to, or offer any work that is illegal, exploitative, or unsafe.</li>
        <li>Do not harass, threaten, or discriminate against other users.</li>
        <li>Do not misrepresent your identity, skills, or experience.</li>
        <li>Do not attempt to circumvent payment, rating, or verification systems.</li>
    </ul>
</div>
<div class="card">
    <h3>4. Payments</h3>
    <p>GigLink records payments between employers and workers. Agreements, pay amounts, and the actual transfer of funds happen directly between the two parties. Camtel is not responsible for private payment disputes outside the platform's recorded flows.</p>
</div>
<div class="card">
    <h3>5. Suspension &amp; termination</h3>
    <p>We may suspend or terminate accounts that violate these terms, that are the subject of repeated verified reports, or that abuse the platform. Suspended accounts cannot be used until an admin reviews the account.</p>
</div>
<div class="card">
    <h3>6. Content &amp; reputation</h3>
    <p>Ratings, endorsements and reviews are the opinions of their authors. Camtel is not responsible for content posted by users, but we remove content that violates our rules.</p>
</div>
<div class="card">
    <h3>7. Changes</h3>
    <p>We may update these terms. Continued use after an update means acceptance.</p>
</div>
<div class="card">
    <h3>8. Contact</h3>
    <p>Questions? Reach us on <?= icon('call') ?> +256 708 461 356 or <?= icon('mail') ?> <a href="mailto:camtelengineeringservices@gmail.com">camtelengineeringservices@gmail.com</a>.</p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
