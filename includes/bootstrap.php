<?php
/**
 * Application bootstrap – include this at the top of every public entry point.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/layout.php';

// Start secure session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(TK_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => TK_SESSION_LIFETIME,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Ensure schema + demo data exist
tk_migrate_and_seed();

// Security headers (safe defaults for all responses)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // CSP allows only the resources used by this build; payment providers are disabled.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    if (TK_ENV === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}



// ---------- Security helpers ----------

function tk_csrf_token(): string
{
    if (empty($_SESSION[TK_CSRF_TOKEN_NAME])) {
        $_SESSION[TK_CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[TK_CSRF_TOKEN_NAME];
}

function tk_csrf_field(): string
{
    $t = htmlspecialchars(tk_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="' . TK_CSRF_TOKEN_NAME . '" value="' . $t . '">';
}

function tk_verify_csrf(?string $token): bool
{
    if ($token === null || empty($_SESSION[TK_CSRF_TOKEN_NAME])) {
        return false;
    }
    return hash_equals($_SESSION[TK_CSRF_TOKEN_NAME], $token);
}

function tk_e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** UTF-8-safe length helper with no mbstring dependency. */
function tk_strlen(string $value): int
{
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

/** UTF-8-safe substring helper with no mbstring dependency. */
function tk_substr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) return mb_substr($value, $start, $length, 'UTF-8');
    if (preg_match_all('/./us', $value, $matches) === false) return substr($value, $start, $length);
    $chars = $matches[0];
    return implode('', array_slice($chars, $start, $length));
}

function tk_redirect(string $path): never
{
    // Internal redirects only. Never turn request data into an open redirect
    // (or permit protocol-relative / header-injection destinations).
    $path = trim($path);
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') ||
        str_contains($path, "\r") || str_contains($path, "\n") || str_contains($path, "\\")) {
        $path = '/';
    }
    header('Location: ' . tk_base_path() . '/' . ltrim($path, '/'));
    exit;
}

// ---------- Auth helpers ----------

function tk_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cached = null;
    if ($cached !== null && (int)$cached['id'] === (int)$_SESSION['user_id']) {
        return $cached;
    }
    $row = tk_repo_find('users', 'id', (int)$_SESSION['user_id']);
    $cached = $row ? ['id'=>$row['id'],'email'=>$row['email'],'full_name'=>$row['full_name'],'role'=>$row['role']] : null;
    if (!$cached) {
        unset($_SESSION['user_id']);
    }
    return $cached;
}

function tk_require_login(): array
{
    $u = tk_user();
    if (!$u) {
        $_SESSION['flash_error'] = 'Please log in to continue.';
        tk_redirect('/auth/login.php');
    }
    return $u;
}

function tk_require_role(string ...$roles): array
{
    $u = tk_require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'You do not have permission to access that page.';
        tk_redirect('/');
    }
    return $u;
}

function tk_login(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function tk_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function tk_flash(string $key): ?string
{
    if (!empty($_SESSION['flash_' . $key])) {
        $msg = $_SESSION['flash_' . $key];
        unset($_SESSION['flash_' . $key]);
        return $msg;
    }
    return null;
}

// ---------- Ticket helpers ----------

function tk_generate_ticket_code(): string
{
    // Unguessable, not sequential
    return strtoupper(bin2hex(random_bytes(8)));
}

/** Sub-folder the site is served from ('' when it lives at the web root). Relative URLs keep working on any host or port. */
function tk_base_path(): string
{
    $p = (string)(parse_url(TK_APP_URL, PHP_URL_PATH) ?? '');
    return rtrim($p, '/');
}

/** Absolute site origin for canonical URLs, sitemap and structured data. Empty until a real production URL is configured. */
function tk_canonical_base(): string
{
    $env = getenv('TK_APP_URL');
    if (!$env) return '';
    $host = (string)parse_url($env, PHP_URL_HOST);
    if ($host === '' || $host === 'localhost' || str_starts_with($host, '127.') || str_ends_with($host, '.local')) return '';
    return rtrim($env, '/');
}

function tk_asset(string $path): string
{
    return tk_base_path() . '/assets/' . ltrim($path, '/');
}

// ---------- Event helpers (used by public pages and the event manager) ----------

/** Statuses an event can have. Only "published" and "sold_out" show publicly. */
function tk_event_statuses(): array
{
    return ['draft', 'published', 'unpublished', 'cancelled', 'completed', 'archived'];
}

/** Public URL of an event picture (built-in image or an uploaded one). */
function tk_event_image(array $ev): string
{
    $img = (string)($ev['cover_image'] ?? '');
    if ($img === '') {
        return tk_asset('images/hero-bg.jpg');
    }
    if (str_starts_with($img, 'uploads/')) {
        return tk_asset($img);
    }
    return tk_asset('images/' . $img);
}

function tk_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? substr($text, 0, 80) : 'event';
}

/** Returns a slug nobody else is using. $ignoreId lets an event keep its own slug. */
function tk_unique_slug(string $base, int $ignoreId = 0): string
{
    $slug = $base;
    $n = 2;
    while (true) {
        $found = tk_repo_find('events', 'slug', $slug);
        if (!$found || (int)$found['id'] === $ignoreId) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

/**
 * Safely store an uploaded event picture.
 * Returns the stored path (e.g. "uploads/events/abc.jpg") or throws RuntimeException.
 */
function tk_save_event_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image could not be uploaded. Please try again.');
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('The image is too big. Maximum size is 3 MB.');
    }
    $tmp = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Only JPG, PNG or WEBP images are allowed.');
    }
    if ($info[0] > 6000 || $info[1] > 6000) {
        throw new RuntimeException('The image dimensions are too large (max 6000 px).');
    }
    $dir = TK_PUBLIC . '/assets/uploads/events';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the uploads folder.');
    }
    // Random name and extension chosen by us, never by the visitor.
    $name = bin2hex(random_bytes(12)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the image.');
    }
    return 'uploads/events/' . $name;
}
