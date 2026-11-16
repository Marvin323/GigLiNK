<?php
/**
 * API bootstrap — JSON responses, CORS, bearer-token auth.
 * All endpoints under /giglink/api/ include this first.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');
header('Cache-Control: no-store');

// CORS — reflect any allow-listed origin from includes/config.php.
// (dev defaults to Capacitor's three WebView origins; add your web origin via env.)
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_filter(array_map('trim', explode(',', defined('GIGLINK_CORS_ORIGINS') ? GIGLINK_CORS_ORIGINS : '')));
if ($origin && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} elseif (GIGLINK_ENV !== 'prod') {
    // Dev convenience: allow any origin when GIGLINK_ENV=dev
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function body_json(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function json_out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, int $status = 400): void {
    json_out(['ok' => false, 'error' => $msg], $status);
}

function bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $h = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+([A-Za-z0-9_-]+)$/', $h, $m)) return $m[1];
    return $_GET['token'] ?? null;
}

/** Returns the authenticated user row, or dies 401. */
function api_user(PDO $pdo): array {
    $tok = bearer_token();
    if (!$tok) json_err('missing bearer token', 401);
    $hash = hash('sha256', $tok);
    $s = $pdo->prepare(
      "SELECT u.*
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.revoked = 0
          AND (t.expires_at IS NULL OR t.expires_at > NOW())
          AND u.active = 1 AND u.deleted_at IS NULL"
    );
    $s->execute([$hash]);
    $u = $s->fetch();
    if (!$u) json_err('invalid or expired token', 401);
    // Touch last_used (best-effort)
    $pdo->prepare("UPDATE api_tokens SET last_used = NOW() WHERE token_hash = ?")->execute([$hash]);
    return $u;
}

function api_require_role(PDO $pdo, string $role): array {
    $u = api_user($pdo);
    if ($u['role'] !== $role) json_err('forbidden', 403);
    return $u;
}
