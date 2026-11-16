<?php
$page_title = 'About';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('info') ?> About GigLink</h1>

<div class="card">
    <h2 style="margin-top:0">What we're solving</h2>
    <p>
        Youth unemployment is a serious community problem — young people have real skills
        (cleaning, delivery, tutoring, farm work, phone repair, hairdressing, data entry)
        but no reliable way to reach the people who would pay for that help, while employers
        struggle to find trustworthy local workers.
    </p>
    <p>
        <strong>GigLink</strong> isn't just a job board — it's a <em>trust and reputation layer</em>
        for the informal economy. Every completed gig builds a verifiable work identity you can
        show anywhere, not locked inside the platform.
    </p>
</div>

<div class="card">
    <h2 style="margin-top:0">Built by Camtel Engineering Services ltd</h2>
    <p>GigLink is designed and operated by Camtel Engineering Services ltd — a Uganda-based engineering and software company dedicated to building tools that solve real community problems.</p>
    <p>
        <strong>Contact us:</strong><br>
        <?= icon('call') ?> +256 708 461 356 · +256 787 495 770<br>
        <?= icon('mail') ?> <a href="mailto:camtelengineeringservices@gmail.com">camtelengineeringservices@gmail.com</a>
    </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
