<?php
$page_title = 'How it works';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('route') ?> How it works</h1>

<div class="card-grid">
    <div class="feature">
        <div class="feature-icon"><?= icon('person_add') ?></div>
        <h3>1. Join</h3>
        <p>Create an account as a <strong>worker</strong> or an <strong>employer</strong>. Workers add their skills, location, and times they're free. Employers add their business or household info.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('search') ?></div>
        <h3>2. Browse or post</h3>
        <p>Workers browse open gigs or post availability windows. Employers post jobs with pay, location, and how many workers are needed.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('send') ?></div>
        <h3>3. Apply</h3>
        <p>Workers apply with a short message. Employers see every applicant with their skills, reputation, and prior work.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('check_circle') ?></div>
        <h3>4. Accept</h3>
        <p>Employer accepts the right worker(s). Contact info is revealed <em>only</em> after acceptance — keeping both sides safe.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('payments') ?></div>
        <h3>5. Pay</h3>
        <p>The employer records payment (cash, M-Pesa, Airtel, MTN, or bank). The worker gets a notification and their earnings count toward their total.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= icon('star') ?></div>
        <h3>6. Rate each other</h3>
        <p>Both sides rate the other. Good workers build a verifiable work identity; bad employers lose visibility. Reputation is portable — printable with a QR code.</p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
