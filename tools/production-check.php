<?php
/**
 * Read-only production readiness check. Never writes, deletes or changes data.
 *
 * Usage (from the project root):
 *   php tools/production-check.php
 *   php tools/production-check.php --strict   # exit code 1 when warnings exist
 *
 * Safe to run on a live database: every query is a read.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/payments.php';

$strict = in_array('--strict', array_slice($argv, 1), true);
$warnings = [];

function pc_row(string $label, string $value): void
{
    printf("%-32s %s\n", $label . ':', $value);
}

function pc_warn(string $message): void
{
    global $warnings;
    $warnings[] = $message;
}

echo "TicketsKenya production readiness check (read-only)\n";
echo str_repeat('=', 68) . "\n\n";

echo "Environment\n";
pc_row('TK_ENV', TK_ENV);
pc_row('TK_DEMO_MODE', TK_DEMO_MODE ? 'true  <-- development only' : 'false');
pc_row('TK_APP_URL', TK_APP_URL);
pc_row('TK_TRUST_PROXY', TK_TRUST_PROXY ? 'true' : 'false');
pc_row('Payments enabled', tk_payments_available() ? 'YES  <-- unexpected' : 'false');
pc_row('.env present', is_file(TK_ROOT . '/.env') ? 'yes' : 'NO');
pc_row('PHP version', PHP_VERSION);
pc_row('display_errors', ini_get('display_errors') ? 'on' : 'off');
pc_row('expose_php', ini_get('expose_php') ? 'on' : 'off');

if (TK_ENV !== 'production') pc_warn('TK_ENV is not "production".');
if (TK_DEMO_MODE) pc_warn('TK_DEMO_MODE is on. Set false in production.');
if (!is_file(TK_ROOT . '/.env')) pc_warn('No .env file: create one on the server using the README configuration table.');
if (tk_payments_available()) pc_warn('Payments are enabled but no provider is integrated.');
if (!str_starts_with(TK_APP_URL, 'https://')) pc_warn('TK_APP_URL is not https.');
if (ini_get('display_errors')) pc_warn('display_errors is on.');
if (ini_get('expose_php')) pc_warn('expose_php is on.');

echo "\nStorage\n";
pc_row('DB backend', tk_use_sqlite() ? 'SQLite' : 'JSON fallback');
pc_row('Data directory', TK_DATA);
pc_row('Data writable', is_writable(TK_DATA) ? 'yes' : 'NO');
pc_row('Data under public/', str_starts_with(str_replace('\\', '/', TK_DATA), str_replace('\\', '/', TK_PUBLIC) . '/') ? 'YES  <-- unsafe' : 'no');
$uploads = TK_PUBLIC . '/assets/uploads';
pc_row('Uploads writable', is_writable($uploads) ? 'yes' : 'NO');
pc_row('Uploads .htaccess', is_file($uploads . '/.htaccess') ? 'present' : 'MISSING');
pc_row('Root .htaccess', is_file(TK_ROOT . '/.htaccess') ? 'present' : 'MISSING');

if (!is_writable(TK_DATA)) pc_warn('The data/ directory is not writable.');
if (!is_dir($uploads) || !is_writable($uploads)) pc_warn('public/assets/uploads is missing or not writable.');
if (!is_file($uploads . '/.htaccess')) pc_warn('public/assets/uploads/.htaccess is missing.');
if (!is_file(TK_ROOT . '/.htaccess')) pc_warn('Project-root .htaccess is missing.');

echo "\nAccounts\n";
$users = tk_repo_all('users');
$demoAccounts = array_values(array_filter($users, static function (array $u): bool {
    $email = strtolower((string)($u['email'] ?? ''));
    return str_ends_with($email, '@ticketskenya.local') || str_starts_with($email, 'demo.');
}));
$admins = array_values(array_filter($users, static fn(array $u): bool => ($u['role'] ?? '') === 'admin'));
$adminsNoTotp = array_values(array_filter($admins, static fn(array $u): bool => empty($u['totp_secret'])));
pc_row('User accounts', (string)count($users));
pc_row('Admin accounts', (string)count($admins));
pc_row('Demo/test accounts', (string)count($demoAccounts));
pc_row('Admins without TOTP', (string)count($adminsNoTotp));

foreach ($demoAccounts as $u) pc_row('  demo account', (string)$u['email']);
if ($demoAccounts) pc_warn('Demo/test accounts exist. Create a real admin, then remove them (see DEPLOYMENT.md).');
if ($adminsNoTotp) {
    foreach ($adminsNoTotp as $u) pc_row('  no TOTP', (string)$u['email']);
    pc_warn('Admin accounts without TOTP. Enrol them with: php tools/setup-2fa.php <email>');
}

echo "\nInventory\n";
$events = tk_repo_all('events');
$byType = [];
foreach ($events as $e) {
    $type = (string)($e['listing_type'] ?? 'unknown');
    $byType[$type] = ($byType[$type] ?? 0) + 1;
}
ksort($byType);
pc_row('Events total', (string)count($events));
foreach ($byType as $type => $count) pc_row('  ' . $type, (string)$count);
pc_row('Marked demo (is_demo)', (string)count(array_filter($events, static fn(array $e): bool => !empty($e['is_demo']))));
pc_row('Imported (source_id set)', (string)count(array_filter($events, static fn(array $e): bool => !empty($e['source_id']))));
$externalOwned = array_values(array_filter($events, static function (array $e): bool {
    return ($e['listing_type'] ?? '') === 'owned' && empty($e['organiser_id']) && !empty($e['source_url']);
}));
pc_row('External -> sold as owned', (string)count($externalOwned));
$orders = tk_repo_all('orders');
$pendingOrders = array_filter($orders, static fn(array $o): bool => ($o['status'] ?? '') === 'pending');
pc_row('Orders', (string)count($orders));
pc_row('  pending/unpaid', (string)count($pendingOrders));
pc_row('Contact messages', (string)count(tk_repo_all('contact_messages')));

if ($externalOwned) {
    pc_warn(count($externalOwned) . ' externally sourced listing(s) are marked "owned" with no organiser and can be sold. '
        . 'Review with: php tools/reclassify-inventory.php --to=discovery (dry run).');
}
if ($pendingOrders) {
    pc_warn(count($pendingOrders) . ' pending order(s) exist. Remove demo transactions before launch (do not delete paid orders).');
}

if (count($events) > 0 && count($byType) > 1) {
    pc_warn('Mixed owned/discovery inventory: review every listing before launch.');
}
if (count(array_filter($events, static fn(array $e): bool => !empty($e['is_demo']))) > 0) {
    pc_warn('Demo events are present. Review or remove them before launch.');
}

echo "\nResult\n";
if ($warnings) {
    echo count($warnings) . " warning(s):\n";
    foreach ($warnings as $i => $w) echo '  ' . ($i + 1) . '. ' . $w . "\n";
} else {
    echo "No warnings. Configuration looks production-ready.\n";
}

exit($strict && $warnings ? 1 : 0);
