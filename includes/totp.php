<?php
declare(strict_types=1);

/**
 * Native RFC 6238 TOTP (HMAC-SHA1, 6 digits, 30-second steps) with no external dependencies.
 * Used for optional admin two-factor authentication.
 */

/**
 * Decodes an RFC 4648 base32 string (spaces and padding tolerated, case-insensitive).
 */
function tk_totp_base32_decode(string $b32): ?string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
    if ($b32 === '') {
        return null;
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($b32) as $char) {
        $value = strpos($alphabet, $char);
        if ($value === false) {
            return null;
        }
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }
    $raw = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) < 8) {
            break;
        }
        $raw .= chr(bindec($chunk));
    }
    return $raw === '' ? null : $raw;
}

/**
 * Encodes raw bytes as RFC 4648 base32 (no padding).
 */
function tk_totp_base32_encode(string $raw): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($raw) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/**
 * Returns the TOTP code for a base32 secret at a unix timestamp, or null for an invalid secret.
 */
function tk_totp_code(string $secret, ?int $time = null, int $digits = 6, int $period = 30): ?string
{
    $key = tk_totp_base32_decode($secret);
    if ($key === null) {
        return null;
    }
    $counter = intdiv($time ?? time(), $period);
    $binary = pack('NN', 0, $counter & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $binary, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    $code = (string)($value % (10 ** $digits));
    return str_pad($code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Constant-time check of a submitted 6-digit code, allowing clock drift of +/- window steps.
 */
function tk_totp_verify(string $secret, string $code, ?int $time = null, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $time ??= time();
    for ($offset = -$window; $offset <= $window; $offset++) {
        $expected = tk_totp_code($secret, $time + ($offset * 30));
        if ($expected !== null && hash_equals($expected, $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Generates a fresh 160-bit base32 secret (compatible with standard authenticator apps).
 */
function tk_totp_new_secret(int $bytes = 20): string
{
    return tk_totp_base32_encode(random_bytes($bytes));
}

/**
 * Builds an otpauth:// provisioning URI for manual entry or QR generation.
 */
function tk_totp_uri(string $secret, string $account, string $issuer = 'TicketsKenya'): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    $query = http_build_query([
        'secret' => str_replace(' ', '', strtoupper($secret)),
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ], '', '&', PHP_QUERY_RFC3986);
    return 'otpauth://totp/' . $label . '?' . $query;
}
