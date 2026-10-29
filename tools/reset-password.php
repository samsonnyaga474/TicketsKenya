<?php
/**
 * Operator tool: set a new password for an existing account (command line only).
 * Usage:  php tools/reset-password.php user@example.com
 * The new password is typed when prompted (input is shown on screen) and is never stored in a log.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') { fwrite(STDERR, "Usage: php tools/reset-password.php user@example.com\n"); exit(2); }
$user = tk_repo_find('users', 'email', $email);
if (!$user) { fwrite(STDERR, "No account with that email.\n"); exit(1); }
echo "New password (at least 10 characters): ";
$pw = trim((string)fgets(STDIN));
if (strlen($pw) < 10 || strlen($pw) > 200) { fwrite(STDERR, "Password must be 10 to 200 characters.\n"); exit(2); }
tk_repo_update('users', (int)$user['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'updated_at' => date('c')]);
echo "Password updated for {$email}.\n";
