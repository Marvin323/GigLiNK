</main>
<footer class="footer">
    <div class="container footer-cols">
        <div>
            <a href="/giglink/" class="brand">
                <span class="logo-dot">
                    <?php
                      $__logo = is_file(__DIR__ . '/../assets/logo.png')
                          ? '/giglink/assets/logo.png' : '/giglink/assets/logo.svg';
                    ?>
                    <img src="<?= e($__logo) ?>" alt="Camtel" width="36" height="36"
                         style="width:36px;height:36px;max-width:100%;max-height:100%;object-fit:contain;display:block">
                </span>
                Gig<span>Link</span>
            </a>
            <p class="muted" style="margin-top:0.5rem">A trust &amp; reputation layer for the informal economy.</p>
            <p class="muted" style="font-size:0.8rem">A <strong>Camtel Engineering Services ltd</strong> product.</p>
        </div>
        <div>
            <h4>Learn more</h4>
            <ul class="footer-links">
                <li><a href="/giglink/about.php">About</a></li>
                <li><a href="/giglink/how.php">How it works</a></li>
                <li><a href="/giglink/help.php">Help</a></li>
                <li><a href="/giglink/terms.php">Terms</a></li>
                <li><a href="/giglink/privacy.php">Privacy</a></li>
            </ul>
        </div>
        <div>
            <h4>Contact Camtel</h4>
            <ul class="footer-links">
                <li><?= icon('call') ?> <a href="tel:+256708461356">+256 708 461 356</a></li>
                <li><?= icon('call') ?> <a href="tel:+256787495770">+256 787 495 770</a></li>
                <li><?= icon('mail') ?> <a href="mailto:camtelengineeringservices@gmail.com">camtelengineeringservices@gmail.com</a></li>
            </ul>
        </div>
    </div>
    <div class="container footer-bottom muted">
        &copy; <?= date('Y') ?> Camtel Engineering Services ltd. All rights reserved.
    </div>
</footer>
<!-- Install-app CTA (Android/Chrome). Appears when the browser signals installability. -->
<div id="installBar" style="display:none; position:fixed; left:0; right:0; bottom:0; z-index:60;
        padding:0.75rem 1rem; background: var(--primary); color:#fff;
        text-align:center; box-shadow:0 -4px 12px rgba(0,0,0,0.15); font-size:0.9rem">
    <span style="margin-right:0.75rem">Install GigLink for a faster, app-like experience.</span>
    <button id="installBtn"  style="background:#fff; color:var(--primary); border:none; padding:0.4rem 1rem; border-radius:999px; font-weight:600; cursor:pointer">Install</button>
    <button id="installSkip" style="background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.5); padding:0.4rem 0.8rem; margin-left:0.5rem; border-radius:999px; cursor:pointer">Not now</button>
</div>
<script src="/giglink/assets/workflows.js" defer></script>
<script>
// Detect Capacitor WebView — the native shell has its own caching + nav model,
// so the SW and web install prompt would just get in the way.
const IS_CAPACITOR = !!(window.Capacitor && window.Capacitor.isNativePlatform
    ? window.Capacitor.isNativePlatform()
    : window.Capacitor);

// Register service worker on the web only (localhost or https — browser rule)
if (!IS_CAPACITOR && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/giglink/sw.js', { scope: '/giglink/' }).catch(() => {});
    });
}

// Install prompt (web only — Capacitor already runs natively)
(function() {
    if (IS_CAPACITOR) return;
    let deferred = null;
    const bar  = document.getElementById('installBar');
    const btn  = document.getElementById('installBtn');
    const skip = document.getElementById('installSkip');
    if (!bar) return;

    const hidden = () => { try { return localStorage.getItem('giglink_install_hidden'); } catch { return null; } };
    const hide   = () => { try { localStorage.setItem('giglink_install_hidden', '1'); } catch {} };

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred = e;
        if (!hidden()) bar.style.display = 'block';
    });
    btn.addEventListener('click', async () => {
        if (!deferred) { bar.style.display = 'none'; return; }
        deferred.prompt();
        await deferred.userChoice;
        deferred = null;
        bar.style.display = 'none';
        hide();
    });
    skip.addEventListener('click', () => { bar.style.display = 'none'; hide(); });
    window.addEventListener('appinstalled', () => { bar.style.display = 'none'; hide(); });
})();
</script>
</body>
</html>
