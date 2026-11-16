<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

// Buffer the whole page so pages that process POST AFTER including this file
// can still call redirect() / header() without "headers already sent" errors.
// Nested on top of PHP's default output_buffering (4KB) which auto-flushes.
ob_start();
header('Cache-Control: no-store');

$page_title = $page_title ?? 'GigLink';
$me_name    = is_logged_in() ? display_name($pdo) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> · GigLink</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <?php
      $__fav = is_file(__DIR__ . '/../assets/logo.png')
          ? '/giglink/assets/logo.png' : '/giglink/assets/logo.svg';
      $__favtype = str_ends_with($__fav, '.png') ? 'image/png' : 'image/svg+xml';
    ?>
    <link rel="icon" type="<?= $__favtype ?>" href="<?= e($__fav) ?>">
    <link rel="apple-touch-icon" href="/giglink/assets/icon-192.svg">
    <link rel="manifest" href="/giglink/manifest.json">
    <meta name="theme-color" content="#1F3C8F">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="GigLink">
    <meta name="mobile-web-app-capable" content="yes">
    <meta property="og:title"       content="<?= e($page_title) ?> · GigLink">
    <meta property="og:description" content="GigLink — a trust and reputation layer for the informal economy. By Camtel Engineering Services.">
    <meta property="og:image"       content="<?= e($__fav) ?>">
    <meta property="og:type"        content="website">
    <link rel="stylesheet" href="/giglink/assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?: time() ?>">
</head>
<body>
<header class="topbar">
    <div class="container nav">
        <a href="/giglink/" class="brand" title="GigLink — a Camtel Engineering Services platform">
            <span class="logo-dot">
                <?php
                  $logo_png = __DIR__ . '/../assets/logo.png';
                  $logo_src = is_file($logo_png) ? '/giglink/assets/logo.png' : '/giglink/assets/logo.svg';
                ?>
                <img src="<?= e($logo_src) ?>" alt="Camtel" width="36" height="36"
                     style="width:36px;height:36px;max-width:100%;max-height:100%;object-fit:contain;display:block">
            </span>
            Gig<span>Link</span>
        </a>

        <div class="nav-search">
            <?= icon('search') ?>
            <form method="get" action="/giglink/jobs.php" style="margin:0">
                <input type="text" name="q" aria-label="Search jobs" placeholder="Search jobs…" autocomplete="off">
            </form>
        </div>

        <nav class="nav-links" aria-label="Main navigation">
            <a href="/giglink/jobs.php" aria-label="Jobs"><?= icon('work') ?><span class="lbl">Jobs</span></a>
            <a href="/giglink/workers.php" aria-label="Workers"><?= icon('groups') ?><span class="lbl">Workers</span></a>

            <?php if (is_logged_in()):
                $unread     = unread_count($pdo, current_user_id());
                $unread_msg = unread_messages_count($pdo, current_user_id());
                $my_photo   = current_photo($pdo);
            ?>
                <a href="/giglink/messages.php" class="bell" title="Messages">
                    <?= icon('chat') ?>
                    <?php if ($unread_msg): ?><span class="bell-dot"><?= $unread_msg > 99 ? '99+' : $unread_msg ?></span><?php endif; ?>
                </a>
                <a href="/giglink/notifications.php" class="bell" title="Notifications">
                    <?= icon('notifications') ?>
                    <?php if ($unread): ?><span class="bell-dot"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
                </a>
                <form method="post" action="/giglink/logout.php">
                    <?= csrf_field() ?>
                    <button class="nav-logout" aria-label="Log out"><?= icon('logout') ?><span class="lbl">Log out</span></button>
                </form>
                <div class="user-menu" id="userMenu">
                    <button class="user-menu-toggle" type="button" aria-label="Account menu" aria-expanded="false" aria-controls="userMenuDropdown">
                        <?= avatar($me_name, '', $my_photo) ?>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <div class="menu-header">
                            <?= avatar($me_name, 'lg', $my_photo) ?>
                            <div>
                                <div class="name"><?= e($me_name) ?></div>
                                <div class="email">@<?= e($_SESSION['username'] ?? '') ?></div>
                            </div>
                        </div>
                        <?php if (current_role() === 'youth'): ?>
                            <a href="/giglink/youth/dashboard.php"><?= icon('dashboard') ?> Dashboard</a>
                            <a href="/giglink/youth/profile.php"><?= icon('person') ?> Edit profile</a>
                            <a href="/giglink/youth/availability.php"><?= icon('schedule') ?> My availability</a>
                            <a href="/giglink/youth/saved.php"><?= icon('bookmark') ?> Saved jobs</a>
                            <a href="/giglink/profile_qr.php?id=<?= (int)current_user_id() ?>"><?= icon('qr_code_2') ?> My work ID</a>
                        <?php elseif (current_role() === 'employer'): ?>
                            <a href="/giglink/employer/dashboard.php"><?= icon('dashboard') ?> Dashboard</a>
                            <a href="/giglink/employer/post_job.php"><?= icon('add_circle') ?> Post a job</a>
                            <a href="/giglink/employer/find_workers.php"><?= icon('person_search') ?> Find workers</a>
                            <a href="/giglink/employer/profile.php"><?= icon('storefront') ?> Edit profile</a>
                        <?php endif; ?>
                        <?php if (current_role() === 'admin'): ?>
                            <a href="/giglink/admin/"><?= icon('admin_panel_settings') ?> Admin</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="/giglink/login.php" aria-label="Log in"><?= icon('login') ?><span class="lbl">Log in</span></a>
                <a href="/giglink/register_youth.php" class="btn btn-sm"><?= icon('person_add') ?> Join</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="container">
<?php
$ok  = flash_get('ok');
$err = flash_get('err');
?>
<div class="toast-stack" id="toastStack" aria-live="polite">
    <?php if ($ok): ?>
        <div class="toast toast-ok"><?= icon('check_circle') ?><?= e($ok) ?>
            <button class="toast-close" type="button" aria-label="Dismiss"><?= icon('close') ?></button>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="toast toast-err" role="alert"><?= icon('error') ?><?= e($err) ?>
            <button class="toast-close" type="button" aria-label="Dismiss"><?= icon('close') ?></button>
        </div>
    <?php endif; ?>
</div>
<script>
// Close user menu on outside click
const menu = document.getElementById('userMenu');
const menuButton = menu?.querySelector('.user-menu-toggle');
if (menuButton) {
    const setMenu = (open) => {
        menu.classList.toggle('open', open);
        menuButton.setAttribute('aria-expanded', String(open));
    };
    menuButton.addEventListener('click', () => setMenu(!menu.classList.contains('open')));
    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) setMenu(false);
    });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.classList.contains('open')) {
            setMenu(false);
            menuButton.focus();
        }
    });
}

// Auto-dismiss toasts after 4s; manual close via the × button
(function() {
    const stack = document.getElementById('toastStack');
    if (!stack) return;
    const dismiss = (t) => {
        if (!t || t.classList.contains('leaving')) return;
        t.classList.add('leaving');
        setTimeout(() => t.remove(), 320);
    };
    stack.querySelectorAll('.toast').forEach((t) => {
        t.querySelector('.toast-close')?.addEventListener('click', () => dismiss(t));
        setTimeout(() => dismiss(t), 4000);
    });
})();
</script>
