<?php
declare(strict_types=1);
namespace SiteActivity;

/** IP handling, redaction and consent state. Pure helpers, no database access except through Config. */
final class Privacy
{
    public const COOKIE_CONSENT = 'sa_consent';
    public const COOKIE_VISITOR = 'sa_vid';
    public const COOKIE_SESSION = 'sa_sid';

    // ---------- IP addresses ----------

    public static function normaliseIp(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return null;
        $bin = @inet_pton($ip);
        if ($bin === false) return null;
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is the same client as the IPv4 address.
        if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return inet_ntop(substr($bin, 12)) ?: null;
        }
        return inet_ntop($bin) ?: null;
    }

    public static function validCidrOrIp(string $v): bool
    {
        if (str_contains($v, '/')) {
            [$ip, $bits] = explode('/', $v, 2);
            $n = self::normaliseIp($ip);
            if ($n === null || !ctype_digit($bits)) return false;
            return (int)$bits <= (str_contains($n, ':') ? 128 : 32);
        }
        return self::normaliseIp($v) !== null;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        $bits = null;
        if (str_contains($cidr, '/')) { [$net, $bits] = explode('/', $cidr, 2); $bits = (int)$bits; } else { $net = $cidr; }
        $a = @inet_pton($ip); $b = @inet_pton((string)self::normaliseIp($net));
        if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
        $bits ??= strlen($a) * 8;
        $full = intdiv($bits, 8); $rem = $bits % 8;
        if ($full > 0 && substr($a, 0, $full) !== substr($b, 0, $full)) return false;
        if ($rem === 0) return true;
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return ((ord($a[$full]) & $mask) === (ord($b[$full]) & $mask));
    }

    /**
     * The visitor's address as the web server saw it. Forwarding headers are believed ONLY when the direct
     * peer (REMOTE_ADDR) is one of the owner-configured trusted proxies; the header is read right-to-left
     * so a client cannot make up the address by sending its own X-Forwarded-For.
     * @return array{ip:?string,public:bool,via_proxy:bool}
     */
    public static function clientIp(array $server, ?array $trusted = null): array
    {
        $trusted ??= Config::list('trusted_proxies');
        $remote = isset($server['REMOTE_ADDR']) ? self::normaliseIp((string)$server['REMOTE_ADDR']) : null;
        $ip = $remote; $via = false;
        if ($remote !== null && $trusted) {
            $isTrusted = fn(string $x): bool => array_reduce($trusted, fn($c, $t) => $c || self::inCidr($x, $t), false);
            if ($isTrusted($remote) && !empty($server['HTTP_X_FORWARDED_FOR'])) {
                $parts = array_map('trim', explode(',', (string)$server['HTTP_X_FORWARDED_FOR']));
                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    $cand = self::normaliseIp($parts[$i]);
                    if ($cand === null) break;                // garbage in the chain: stop and keep the proxy address
                    if ($isTrusted($cand)) continue;          // another of our own proxies: keep walking left
                    $ip = $cand; $via = true; break;
                }
            }
        }
        $public = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        return ['ip' => $ip, 'public' => $public, 'via_proxy' => $via];
    }

    /** Applies the owner's storage mode. @return array{0:?string,1:string} [value, kind] */
    public static function ipForStorage(?string $ip, ?string $mode = null): array
    {
        $mode ??= Config::get('ip_mode');
        if ($ip === null || $mode === 'none') return [null, 'none'];
        $bin = inet_pton($ip);
        if ($bin === false) return [null, 'none'];
        switch ($mode) {
            case 'full': return [$ip, 'full'];
            case 'keyed_hash': return [substr(hash_hmac('sha256', $ip, Config::secret()), 0, 24), 'hash'];
            default: // truncated: IPv4 /24, IPv6 /48
                if (strlen($bin) === 4) return [inet_ntop(substr($bin, 0, 3) . "\0") . '/24', 'truncated'];
                return [inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48', 'truncated'];
        }
    }

    // ---------- Text redaction ----------

    /** Removes things that look personal (emails, phone/ID-like numbers, long tokens), control characters and extra spaces. */
    public static function redact(string $text, int $max = 80): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? '';
        $text = preg_replace('/\+?\d[\d\s().\-]{6,}\d/u', '[number]', $text) ?? '';
        $text = preg_replace('/\b[A-Za-z0-9_\-]{24,}\b/u', '[token]', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
        return substr($text, 0, $max);
    }

    /** Local path only: strips query string/fragment, enforces a leading slash and a safe character set. */
    public static function cleanPath(string $p, int $max = 200): ?string
    {
        $p = trim($p);
        $p = preg_replace('/[?#].*$/', '', $p) ?? '';
        if ($p === '' || $p[0] !== '/' || str_starts_with($p, '//')) return null;
        if (strlen($p) > $max || preg_match('/[^A-Za-z0-9\/_.~%+,:@=-]/', $p)) return null;
        return $p;
    }

    // ---------- Consent state ----------

    /** @return array{decided:bool,analytics:bool,marketing:bool,policy:string} */
    public static function consentFromCookie(?string $raw): array
    {
        $none = ['decided' => false, 'analytics' => false, 'marketing' => false, 'policy' => ''];
        if (!$raw || strlen($raw) > 100) return $none;
        parse_str($raw, $c);
        if (!isset($c['v'], $c['a'], $c['m'])) return $none;
        if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', (string)$c['v'])) return $none;
        if ((string)$c['v'] !== Config::get('policy_version')) return $none; // policy changed: ask again
        return ['decided' => true, 'analytics' => (string)$c['a'] === '1', 'marketing' => (string)$c['m'] === '1' && (string)$c['a'] === '1', 'policy' => (string)$c['v']];
    }

    public static function consentCookieValue(bool $analytics, bool $marketing): string
    {
        return http_build_query(['v' => Config::get('policy_version'), 'a' => $analytics ? 1 : 0, 'm' => ($analytics && $marketing) ? 1 : 0, 't' => time()]);
    }

    /** May optional analytics run for this request? Honours the owner's consent mode. */
    public static function analyticsAllowed(array $consent): bool
    {
        if (Config::get('consent_mode') === 'notice_only') {
            return !$consent['decided'] || $consent['analytics']; // explicit refusal always wins
        }
        return $consent['decided'] && $consent['analytics'];
    }

    public static function isHttps(array $server): bool
    {
        return !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
    }

    public static function setCookie(string $name, string $value, int $expires, bool $httpOnly, array $server): void
    {
        if (headers_sent()) return;
        setcookie($name, $value, [
            'expires' => $expires, 'path' => '/', 'secure' => self::isHttps($server),
            'httponly' => $httpOnly, 'samesite' => 'Lax',
        ]);
    }

    public static function newId(): string { return bin2hex(random_bytes(16)); }
    public static function validId(?string $v): bool { return is_string($v) && (bool)preg_match('/^[a-f0-9]{32}$/', $v); }
}
