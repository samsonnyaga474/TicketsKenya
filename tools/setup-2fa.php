<?php
/**
 * Enrol an account in TOTP two-factor authentication (or turn it off).
 *
 * Usage (from project root):
 *   php tools/setup-2fa.php user@example.com          # generate a new secret, confirm one code
 *   php tools/setup-2fa.php user@example.com --disable
 *
 * The secret is printed once; add it to any RFC 6238 authenticator app (Aegis, Google Authenticator,
 * 1Password, Raivo, ...). The code must be confirmed before the secret is saved so a mis-typed
 * secret can never lock the account out.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$email = strtolower(trim((string)($argv[1] ?? '')));
$disable = in_array('--disable', array_slice($argv, 2), true);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/setup-2fa.php user@example.com [--disable]\n");
    exit(1);
}

$user = tk_repo_find('users', 'email', $email);
if (!$user) {
    fwrite(STDERR, "No account found for {$email}\n");
    exit(1);
}

if ($disable) {
    if (!array_key_exists('totp_secret', $user) || $user['totp_secret'] === null || $user['totp_secret'] === '') {
        fwrite(STDOUT, "Two-factor authentication is already off for {$email}.\n");
        exit(0);
    }
    tk_repo_update('users', (int)$user['id'], ['totp_secret' => null, 'updated_at' => date('c')]);
    fwrite(STDOUT, "Two-factor authentication disabled for {$email}.\n");
    exit(0);
}

if (!empty($user['totp_secret'])) {
    fwrite(STDERR, "{$email} is already enrolled. Use --disable first to replace the secret.\n");
    exit(1);
}

$secret = tk_totp_new_secret();
fwrite(STDOUT, "Account:   {$email}\n");
fwrite(STDOUT, "Secret:    {$secret}\n");
fwrite(STDOUT, "Setup URI: " . tk_totp_uri($secret, $email) . "\n\n");
fwrite(STDOUT, "Add the secret to your authenticator app, then type the current 6-digit code: ");

$entered = trim((string)fgets(STDIN));
if (!tk_totp_verify($secret, $entered, null, 2)) {
    fwrite(STDERR, "\nCode did not match. Nothing was saved; run the command again.\n");
    exit(1);
}

tk_repo_update('users', (int)$user['id'], ['totp_secret' => $secret, 'updated_at' => date('c')]);
fwrite(STDOUT, "\nTwo-factor authentication enabled for {$email}. Keep the secret somewhere safe (password manager).\n");
