<?php
/**
 * Create (or promote) an admin account. Command line only.
 * Usage:  php tools/create-admin.php you@example.com "Your Name" "YourStrongPassword"
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
tk_migrate_and_seed();

[$script, $email, $name, $password] = array_pad($argv, 4, '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 10) {
    fwrite(STDERR, "Usage: php tools/create-admin.php email \"Full Name\" \"Password (10+ characters)\"\n");
    exit(1);
}
$existing = tk_repo_find('users', 'email', strtolower($email));
if ($existing) {
    tk_repo_update('users', (int)$existing['id'], [
        'role' => 'admin',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'updated_at' => date('c'),
    ]);
    echo "Existing account promoted to admin and password reset: {$email}\n";
} else {
    tk_repo_insert('users', [
        'email' => strtolower($email),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name' => $name,
        'role' => 'admin',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);
    echo "Admin account created: {$email}\n";
}
