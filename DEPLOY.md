# GigLink deployment guide

A **Camtel Engineering Services ltd** product.

This guide covers two deployments:

1. **Backend (API + web)** — put the PHP code on a server reachable by the mobile app.
2. **Mobile app (Capacitor)** — wrap the PWA as native Android + iOS projects.

Do them in that order.

---

## 1. Backend deployment

### 1.1 Prerequisites

- PHP 8.1+ with `pdo_mysql`, `gd` (for images), `mbstring`, `openssl`.
- MariaDB 10.4+. This upgrade was tested on MariaDB 10.4.32.
- Apache 2.4+ with `mod_rewrite`, `mod_headers`, `mod_mime`.
- HTTPS strongly recommended (Let's Encrypt is free). Service workers only
  install on `https://` or `http://localhost`.

### 1.2 Load a fresh database

Run from the project directory with a database administrator account. The schema creates the database and seeds development accounts. For an existing database, see [Phase 9 deployment and recovery](MOBILE_MONEY_SANDBOX.md).

```bash
mysql -u root -p < sql/schema.sql
mysql -u root -p < sql/02_lifecycle.sql
mysql -u root -p < sql/03_usernames.sql
mysql -u root -p < sql/04_platform.sql
mysql -u root -p < sql/05_reliability.sql
mysql -u root -p < sql/06_trust_reconciliation.sql
mysql -u root -p < sql/07_financial_governance.sql
mysql -u root -p < sql/08_payment_queue.sql
mysql -u root -p < sql/09_mobile_money_sandbox.sql
```

### 1.3 Create the runtime account

```sql
CREATE USER 'giglink'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON giglink.* TO 'giglink'@'localhost';
```

### 1.4 Copy the code

Upload the whole `giglink/` folder to your server's web root
(e.g. `/var/www/html/giglink`). Make sure `uploads/photos/` is writable
by the web user:

```bash
sudo chown -R www-data:www-data /var/www/html/giglink
sudo chmod -R 755 /var/www/html/giglink
sudo chmod -R 775 /var/www/html/giglink/uploads
```

### 1.5 Configure environment

In Apache `httpd.conf` (or your vhost):

```apache
SetEnv GIGLINK_ENV          prod
SetEnv GIGLINK_DB_HOST      127.0.0.1
SetEnv GIGLINK_DB_PORT      3306
SetEnv GIGLINK_DB_NAME      giglink
SetEnv GIGLINK_DB_USER      giglink
SetEnv GIGLINK_DB_PASS      a-strong-password
SetEnv GIGLINK_BASE_URL     https://api.your-domain.com/giglink
SetEnv GIGLINK_CORS_ORIGINS capacitor://localhost,http://localhost,https://localhost,https://your-domain.com
```

Restart Apache. All the defaults in `includes/config.php` are overridden by
these env vars.

### 1.6 Change the demo passwords

The seed accounts (`admin`, `alice`, `brian`, `mama`, `shopowner` — all
password `password`) are for development only. Log in as `admin` and reset
or disable them from `/admin/users.php` before going live.

### 1.7 Smoke test

Visit `https://your-domain.com/giglink/`. You should see the Camtel-branded
landing. Then from the terminal:

```bash
curl -X POST https://your-domain.com/giglink/api/login.php \
     -H "Content-Type: application/json" \
     -d '{"username":"admin","password":"password"}'
```

A `200` with `{ok:true, token:"…"}` means the API is live.

---

## 2. Capacitor native shell (Android + iOS)

The backend is now live. Capacitor wraps the mobile website into real native
projects. Everything you built keeps working — the native shell just points
at your server.

### 2.1 Create the Capacitor project

On your dev machine (needs Node 18+ and either Android Studio or Xcode):

```bash
mkdir giglink-mobile && cd giglink-mobile
npm init -y
npm install @capacitor/core @capacitor/cli
npx cap init GigLink com.camtel.giglink
```

When it asks for a webDir, point it at a dummy folder for now (the real
content comes from the server):

```bash
mkdir www
echo "<meta http-equiv='refresh' content='0;url=https://your-domain.com/giglink/'>" > www/index.html
```

### 2.2 Point Capacitor at your live server

Edit `capacitor.config.ts` (or `.json`):

```json
{
  "appId":   "com.camtel.giglink",
  "appName": "GigLink",
  "webDir":  "www",
  "server": {
    "url":                "https://your-domain.com/giglink/",
    "androidScheme":      "https",
    "cleartext":          false
  },
  "backgroundColor": "#1F3C8F"
}
```

### 2.3 Add platforms

```bash
npm install @capacitor/android @capacitor/ios
npx cap add android
npx cap add ios
```

### 2.4 Drop in the real icons

Capacitor expects PNG icons at specific sizes. Export your logo once at
1024×1024 (PNG), then use `@capacitor/assets` to generate every size:

```bash
npm install -D @capacitor/assets
# put icon-only.png + splash.png at ./assets/
npx capacitor-assets generate
```

### 2.5 Build the Android app

```bash
npx cap sync android
npx cap open android
```

Android Studio opens. Hit **Run** → the app installs on your device and
loads `https://your-domain.com/giglink/` in a native WebView, branded with
the Camtel icon and `#1F3C8F` splash. All the GigLink features work: login,
jobs, photo upload, notifications, messaging.

### 2.6 Build the iOS app (Mac only)

```bash
npx cap sync ios
npx cap open ios
```

Xcode opens. Pick your signing team and hit **Run** on a device or
simulator.

### 2.7 Native camera / push notifications (optional next step)

Install Capacitor plugins and swap the web file inputs / notifications for
native equivalents when you're ready:

```bash
npm install @capacitor/camera @capacitor/push-notifications
npx cap sync
```

---

## 3. Pre-flight checklist (go/no-go for testing)

- [x] Config centralised (`includes/config.php` reads env vars; defaults safe for local XAMPP)
- [x] Error reporting switches to log-to-file when `GIGLINK_ENV=prod`
- [x] `.htaccess` blocks access to `includes/`, `sql/`, and PHP execution in `uploads/`
- [x] Security headers (`X-Frame-Options`, `Referrer-Policy`, `nosniff`, `Permissions-Policy`)
- [x] CORS reflects an allow-list from `GIGLINK_CORS_ORIGINS` in prod
- [x] Service worker + install prompt skipped inside Capacitor WebView
- [x] All HTTPS/host detection goes through `base_url()`
- [x] JSON API with bearer-token auth is live
- [x] PWA manifest + icons + offline fallback
- [ ] **You still need to**: swap the SVG icons for real PNGs, change demo
      passwords, and point `GIGLINK_BASE_URL` at your real host.

## 4. Known gaps (post-testing roadmap)

- **Payment provider integration** — reconcile legacy records, then add independently verified provider callbacks. Current payment records are manual.
- **SMS / WhatsApp outbound** — notifications live in the DB; a cron that
  reads unsent rows and ships them via Africa's Talking / Twilio is TODO.
- **Rate limiting** — login / apply / report have no per-IP throttle.
- **Password reset email** — tokens exist in `password_resets`; admin
  generates the link manually. Wire PHPMailer to automate.
- **Native camera + push notifications** — see 2.7.
