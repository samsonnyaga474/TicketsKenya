<?php
/**
 * TicketsKenya configuration
 * Never commit real secrets. Use environment variables or a local .env outside public.
 */

declare(strict_types=1);

/**
 * Load a private .env file from the project root (never from public/).
 * Lines are KEY=VALUE. Comments (#) and blank lines are ignored.
 * Does not override variables already set in the real environment.
 */
function tk_load_dotenv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Strip optional surrounding quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($name === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
            continue;
        }
        // Do not override real OS / Apache environment
        if (getenv($name) !== false) {
            continue;
        }
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}

// Base path detection
define('TK_ROOT', dirname(__DIR__));
// Load private .env from project root only (outside public web root)
tk_load_dotenv(TK_ROOT . '/.env');
define('TK_PUBLIC', TK_ROOT . '/public');
define('TK_DATA', getenv('TK_DATA_DIR') ?: TK_ROOT . '/data');

// Application settings
define('TK_APP_NAME', 'TicketsKenya');
define('TK_APP_URL', getenv('TK_APP_URL') ?: 'http://localhost:8080');
define('TK_ENV', getenv('TK_ENV') ?: 'development');

// Behind a reverse proxy/tunnel (e.g. Cloudflare) set TK_TRUST_PROXY=true so
// the Secure cookie flag and HTTPS detection work. The proxy MUST overwrite
// the X-Forwarded-Proto header. Leave false unless HTTPS termination is external.
define('TK_TRUST_PROXY', strtolower(trim((string)(getenv('TK_TRUST_PROXY') ?: 'false'))) === 'true');

// Session
define('TK_SESSION_NAME', 'tk_session');
define('TK_SESSION_LIFETIME', 60 * 60 * 24 * 7); // 7 days

// Security
define('TK_CSRF_TOKEN_NAME', '_csrf');
define('TK_PASSWORD_ALGO', PASSWORD_ARGON2ID); // falls back if unavailable
define('TK_MAX_LOGIN_ATTEMPTS', 8);
define('TK_LOGIN_WINDOW_SECONDS', 900); // 15 minutes

// Database (SQLite for local simplicity; swap DSN for MySQL/Postgres later)
define('TK_DB_PATH', TK_DATA . '/ticketskenya.sqlite');

// Payment provider credentials (never hardcode; set via environment / .env)
// No provider is integrated in this build; TK_PAYMENTS_ENABLED=false by default.


// Service fee (TicketKenya platform fee on own inventory sales)
// Reference (HustleSasa Terms of Service, verified 2026-10-20):
//   https://support.hustlesasa.com/docs/knowledgebase/hustlesasa-policies/terms-of-service/
//   "Hustle charges a 5% transaction fee for all online transactions."
//   "Hustle charges a fee of 100KES for every withdrawal."
// TicketKenya proposed equivalent (configurable; not an affiliation claim):
define('TK_PLATFORM_FEE_PERCENT', (float)(getenv('TK_PLATFORM_FEE_PERCENT') ?: '5.0'));
define('TK_WITHDRAWAL_FEE_KES', (int)(getenv('TK_WITHDRAWAL_FEE_KES') ?: '100'));

// Email (placeholder – not configured by default)

// Public support contact (confirmed by owner)
define('TK_SUPPORT_EMAIL', getenv('TK_SUPPORT_EMAIL') ?: 'ticketskenyasupport@gmail.com');
define('TK_MAIL_FROM', getenv('TK_MAIL_FROM') ?: TK_SUPPORT_EMAIL);
define('TK_MAIL_FROM_NAME', 'TicketsKenya');

// Demo mode: when true, development test accounts may be seeded.
// NEVER enable demo mode on a public production site.
define('TK_DEMO_MODE', (getenv('TK_DEMO_MODE') ?: (TK_ENV === 'production' ? 'false' : 'true')) === 'true');

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Error display: off in production
if (TK_ENV === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

