<?php
/**
 * Offline tests for the native TOTP implementation, using the RFC 6238 reference vectors.
 * Run: php tests/test_totp.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/totp.php';

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS: $msg\n";
        $passed++;
    } else {
        echo "FAIL: $msg\n";
        $failed++;
    }
}

echo "=== TicketsKenya TOTP tests (RFC 6238) ===\n";

// RFC 6238 Appendix B: SHA1 seed is the ASCII string "12345678901234567890".
$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

assert_true(tk_totp_base32_encode('12345678901234567890') === $rfcSecret, 'base32 encode matches RFC seed');
assert_true(tk_totp_base32_decode($rfcSecret) === '12345678901234567890', 'base32 decode matches RFC seed');
assert_true(tk_totp_base32_decode('gezd gnbv-gy3t qojq gezd gnbv gy3t-qojq') === tk_totp_base32_decode($rfcSecret), 'base32 tolerates spaces, dashes and case');

$vectors = [
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
    [20000000000, '353130'],
];
foreach ($vectors as [$time, $expected]) {
    assert_true(tk_totp_code($rfcSecret, $time) === $expected, "6-digit code at T={$time} is {$expected}");
}

assert_true(tk_totp_verify($rfcSecret, '287082', 59), 'verifies current-step code');
assert_true(tk_totp_verify($rfcSecret, '287082', 59 + 30), 'accepts one step of forward clock drift');
assert_true(tk_totp_verify($rfcSecret, '287082', 59 - 30), 'accepts one step of backward clock drift');
assert_true(!tk_totp_verify($rfcSecret, '287082', 59 + 120), 'rejects a code two steps out of window');
assert_true(!tk_totp_verify($rfcSecret, '28708', 59), 'rejects five-digit input');
assert_true(!tk_totp_verify($rfcSecret, 'abcdef', 59), 'rejects non-numeric input');
assert_true(!tk_totp_verify('not base32!', '287082', 59), 'rejects invalid secret');

$fresh = tk_totp_new_secret();
assert_true(strlen($fresh) === 32 && preg_match('/^[A-Z2-7]{32}$/', $fresh) === 1, 'generated secret is 160-bit base32');
assert_true(tk_totp_verify($fresh, (string)tk_totp_code($fresh)), 'generated secret round-trips');
assert_true(str_contains(tk_totp_uri($fresh, 'admin@example.com'), 'otpauth://totp/'), 'provisioning URI is otpauth');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
