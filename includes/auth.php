<?php
if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookie before start
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $params['path'] ?: '/',
        'domain'   => $params['domain'],
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    // Absolute 7-day timeout since last activity
    $now = time();
    if (isset($_SESSION['last_seen']) && $now - $_SESSION['last_seen'] > 7 * 24 * 3600) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_seen'] = $now;
}

function current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function current_role(): ?string {
    return $_SESSION['role'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    global $pdo;
    if (is_logged_in() && isset($pdo)) {
        $check = $pdo->prepare('SELECT role FROM users WHERE id=? AND active=1 AND deleted_at IS NULL');
        $check->execute([current_user_id()]);
        if ($check->fetchColumn() !== current_role()) logout_user();
    }
    if (!is_logged_in()) {
        header('Location: /giglink/login.php');
        exit;
    }
}

function require_role(string $role): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== $role) {
        http_response_code(403);
        die('Forbidden: this page is for ' . htmlspecialchars($role) . ' accounts.');
    }
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['email']    = $user['email']    ?? '';
}

/** Validate a desired username. Returns null if OK, or an error message. */
function username_error(string $username): ?string {
    if (strlen($username) < 3)  return 'Username must be at least 3 characters.';
    if (strlen($username) > 30) return 'Username must be at most 30 characters.';
    if (!preg_match('/^[a-z0-9_]+$/', $username))
        return 'Username can only contain lowercase letters, numbers, and underscores.';
    return null;
}

/** Validate a phone number. Returns null if OK, or an error message. */
function phone_error(string $phone): ?string {
    if ($phone === '') return 'Phone number is required.';
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 7 || strlen($digits) > 15)
        return 'Phone number should have 7–15 digits.';
    return null;
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// --- CSRF helpers ----------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function require_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    $sent = $_POST['csrf'] ?? null;
    $expected = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
        http_response_code(400);
        die('Invalid CSRF token. Please go back and try again.');
    }
}
