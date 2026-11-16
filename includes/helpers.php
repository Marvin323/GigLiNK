<?php
// Output helpers — always escape anything that came from the DB or a form.

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash_set(string $key, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'][$key] = $msg;
}

function flash_get(string $key): ?string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function money($amount): string {
    if ($amount === null || $amount === '') return 'Negotiable';
    $value = (float)$amount;
    return 'UGX ' . number_format($value, floor($value) === $value ? 0 : 2);
}

function stars($avg, int $count = 0): string {
    if (!$avg) return '<span class="muted">No ratings yet</span>';
    $full = str_repeat('★', (int)round($avg));
    $empty = str_repeat('☆', 5 - (int)round($avg));
    return '<span class="stars">' . $full . $empty . '</span> '
         . '<span class="muted">' . number_format((float)$avg, 1) . ' (' . (int)$count . ')</span>';
}

function age_from_dob(?string $dob): ?int {
    if (!$dob) return null;
    try {
        $d = new DateTime($dob);
        return $d->diff(new DateTime('today'))->y;
    } catch (Exception $e) { return null; }
}

function fetch_options(PDO $pdo, string $table, string $label = 'name'): array {
    return $pdo->query("SELECT id, $label FROM $table ORDER BY $label")->fetchAll();
}

/** Material icon shortcut — returns an inline <span> element. */
function icon(string $name, string $class = ''): string {
    return '<span class="material-symbols-outlined ' . e($class) . '">' . e($name) . '</span>';
}

/** Initials for an avatar from a full name or email. */
function initials(?string $name): string {
    if (!$name) return '?';
    $parts = preg_split('/[\s@.]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '?';
    if (count($parts) === 1) return mb_strtoupper(mb_substr($parts[0], 0, 2));
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
}

/** Deterministic gradient avatar from any string (name/email/id).
 *  If a photo URL/path is given, renders that image instead of initials. */
function avatar(?string $name, string $size = '', ?string $photo = null): string {
    $cls  = 'avatar' . ($size ? ' ' . e($size) : '');
    if ($photo) {
        $src = str_starts_with($photo, 'http') ? $photo : '/giglink/' . ltrim($photo, '/');
        return '<span class="' . $cls . ' avatar-photo" style="background-image:url(\'' . e($src) . '\')"></span>';
    }
    $init = initials($name);
    $hue  = $name ? (crc32($name) % 360) : 0;
    $bg   = sprintf('linear-gradient(135deg, hsl(%d,65%%,55%%), hsl(%d,65%%,45%%))',
                    $hue, ($hue + 40) % 360);
    return '<span class="' . $cls . '" style="background:' . $bg . '">' . e($init) . '</span>';
}

/**
 * Pretty session user display name (lazy — pulls profile row if we have DB).
 */
function display_name(PDO $pdo): string {
    if (!is_logged_in()) return '';
    static $cache = null;
    if ($cache !== null) return $cache;
    $uid = current_user_id();
    $stmt = $pdo->prepare(
      "SELECT COALESCE(yp.full_name, ep.business_name, u.email, u.username) AS n
         FROM users u
         LEFT JOIN youth_profiles    yp ON yp.user_id = u.id
         LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.id = ?"
    );
    $stmt->execute([$uid]);
    return $cache = (string)$stmt->fetchColumn();
}

/** Current user's photo path (cached). */
function current_photo(PDO $pdo): ?string {
    if (!is_logged_in()) return null;
    static $cache = -1;
    if ($cache !== -1) return $cache;
    $stmt = $pdo->prepare("SELECT photo_path FROM users WHERE id = ?");
    $stmt->execute([current_user_id()]);
    $cache = $stmt->fetchColumn() ?: null;
    return $cache;
}

/* ===================================================================
 * Notifications
 * =================================================================*/
function notify(PDO $pdo, int $user_id, string $kind, string $title, ?string $url = null): void {
    $pdo->prepare(
      "INSERT INTO notifications (user_id, kind, title, url) VALUES (?,?,?,?)"
    )->execute([$user_id, $kind, $title, $url]);
}

function unread_count(PDO $pdo, int $user_id): int {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
    $s->execute([$user_id]);
    return $cache[$user_id] = (int)$s->fetchColumn();
}

/** Total unread messages the given user has waiting across all threads. */
function unread_messages_count(PDO $pdo, int $user_id): int {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];
    $u = (int)$user_id;
    $s = $pdo->query(
      "SELECT COUNT(*)
         FROM messages m
         JOIN applications a ON a.id = m.application_id
         JOIN jobs j ON j.id = a.job_id
        WHERE m.sender_id <> $u
          AND m.read_at IS NULL
          AND (a.youth_id = $u OR j.employer_id = $u)"
    );
    return $cache[$user_id] = (int)$s->fetchColumn();
}

/* ===================================================================
 * Audit log
 * =================================================================*/
function audit(PDO $pdo, string $action, ?string $entity = null, ?int $entity_id = null, array $meta = [], ?int $actorId = null): void {
    $pdo->prepare(
      "INSERT INTO audit_log (actor_id, action, entity, entity_id, meta, ip) VALUES (?,?,?,?,?,?)"
    )->execute([
        $actorId ?? current_user_id(),
        $action, $entity, $entity_id,
        $meta ? json_encode($meta) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/* ===================================================================
 * File upload (profile photos)
 * =================================================================*/
function save_upload(array $file, string $subdir, int $user_id): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('File too large (max 3 MB).');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset($allowed[$info['mime']])) {
        throw new RuntimeException('Only JPG, PNG or WebP images are allowed.');
    }
    $ext = $allowed[$info['mime']];
    $dir = __DIR__ . '/../uploads/' . trim($subdir, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $user_id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return 'uploads/' . trim($subdir, '/') . '/' . $name;
}

/* ===================================================================
 * Pagination
 * =================================================================*/
function paginate(int $total, int $per_page, int $page, string $base_url): string {
    $pages = max(1, (int)ceil($total / $per_page));
    if ($pages <= 1) return '';
    $page = max(1, min($pages, $page));
    $sep  = str_contains($base_url, '?') ? '&' : '?';
    $out  = '<nav class="pagination">';
    if ($page > 1) {
        $out .= '<a href="' . e($base_url . $sep . 'p=' . ($page - 1)) . '" class="btn btn-sm btn-ghost">← Prev</a>';
    }
    $out .= '<span class="muted" style="padding:0.5rem 0.75rem">Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        $out .= '<a href="' . e($base_url . $sep . 'p=' . ($page + 1)) . '" class="btn btn-sm btn-ghost">Next →</a>';
    }
    return $out . '</nav>';
}
