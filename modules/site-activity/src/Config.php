<?php
declare(strict_types=1);
namespace SiteActivity;

/** Settings: secure defaults, validated values, stored in activity_settings. */
final class Config
{
    public const DEFAULTS = [
        'project_id' => 'site',
        'tracking_enabled' => '1',
        'consent_mode' => 'opt_in',          // opt_in = nothing optional is stored until the visitor accepts
        'policy_version' => '1',
        'enabled_categories' => 'navigation,search,event,checkout,account,error,outbound',
        'audiences_enabled' => '0',
        'session_timeout_min' => '30',       // inactivity after which the next event starts a new session
        'active_window_min' => '5',          // a session is "active" only if it had activity this recently
        'retention_events_days' => '90',
        'retention_ip_days' => '30',
        'retention_sessions_days' => '365',
        'retention_audit_days' => '730',
        'ip_mode' => 'truncated',            // none | truncated | keyed_hash | full
        'ip_view_enabled' => '0',
        'trusted_proxies' => '',             // comma separated IPs / CIDRs of reverse proxies you control
        'geo_header' => '',                  // e.g. CF-IPCountry (only read when the request came through a trusted proxy)
        'excluded_paths' => '/admin,/auth,/payments,/activity',
        'exclude_admins' => '1',
        'rate_limit_per_min' => '120',
        'attribution_window_days' => '30',
        'abandonment_window_hours' => '24',
        'exports_enabled' => '1',
        'refresh_seconds' => '15',
        'capture_search_terms' => '1',
        'test_token' => '',
        'secret' => '',
    ];

    /** Keys the owner may edit on the settings page. */
    public const EDITABLE = [
        'tracking_enabled', 'consent_mode', 'policy_version', 'enabled_categories', 'audiences_enabled',
        'session_timeout_min', 'active_window_min', 'retention_events_days', 'retention_ip_days',
        'retention_sessions_days', 'retention_audit_days', 'ip_mode', 'ip_view_enabled', 'trusted_proxies',
        'geo_header', 'excluded_paths', 'exclude_admins', 'rate_limit_per_min', 'attribution_window_days',
        'abandonment_window_hours', 'exports_enabled', 'refresh_seconds', 'capture_search_terms', 'project_id',
    ];

    public const CATEGORIES = ['navigation', 'search', 'event', 'checkout', 'account', 'error', 'outbound'];

    private static ?array $cache = null;
    private static array $file = [];

    public static function loadFile(array $file): void { self::$file = $file; self::$cache = null; }

    public static function reset(): void { self::$cache = null; }

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $vals = self::DEFAULTS;
        foreach (self::$file as $k => $v) {
            if (array_key_exists($k, $vals) && is_scalar($v)) $vals[$k] = (string)$v;
        }
        $pdo = Module::pdo();
        if ($pdo) {
            try {
                foreach ($pdo->query('SELECT k, v FROM activity_settings')->fetchAll() as $r) {
                    if (array_key_exists($r['k'], $vals)) $vals[$r['k']] = (string)$r['v'];
                }
            } catch (\Throwable $e) { /* tables not installed yet: defaults apply */ }
        }
        return self::$cache = $vals;
    }

    public static function get(string $key): string { return self::all()[$key] ?? (self::DEFAULTS[$key] ?? ''); }
    public static function int(string $key): int { return (int)self::get($key); }
    public static function bool(string $key): bool { return self::get($key) === '1'; }

    public static function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::get($key))), fn($x) => $x !== ''));
    }

    /** Returns the cleaned value or throws InvalidArgumentException with a plain-language message. */
    public static function validate(string $key, string $value): string
    {
        $value = trim($value);
        $int = function (int $min, int $max) use ($key, $value): string {
            if (!preg_match('/^\d{1,6}$/', $value) || (int)$value < $min || (int)$value > $max) {
                throw new \InvalidArgumentException("$key must be a whole number from $min to $max.");
            }
            return (string)(int)$value;
        };
        switch ($key) {
            case 'tracking_enabled': case 'audiences_enabled': case 'ip_view_enabled': case 'exclude_admins':
            case 'exports_enabled': case 'capture_search_terms':
                if (!in_array($value, ['0', '1'], true)) throw new \InvalidArgumentException("$key must be on or off.");
                return $value;
            case 'consent_mode':
                if (!in_array($value, ['opt_in', 'notice_only'], true)) throw new \InvalidArgumentException('Unknown consent mode.');
                return $value;
            case 'ip_mode':
                if (!in_array($value, ['none', 'truncated', 'keyed_hash', 'full'], true)) throw new \InvalidArgumentException('Unknown IP storage mode.');
                return $value;
            case 'session_timeout_min': return $int(5, 240);
            case 'active_window_min': return $int(1, 60);
            case 'retention_events_days': return $int(7, 1825);
            case 'retention_ip_days': return $int(1, 365);
            case 'retention_sessions_days': return $int(30, 1825);
            case 'retention_audit_days': return $int(90, 3650);
            case 'rate_limit_per_min': return $int(10, 2000);
            case 'attribution_window_days': return $int(1, 180);
            case 'abandonment_window_hours': return $int(1, 720);
            case 'refresh_seconds': return $int(5, 300);
            case 'policy_version':
                if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $value)) throw new \InvalidArgumentException('Policy version may only contain letters, numbers, dots and dashes (max 20).');
                return $value;
            case 'project_id':
                if (!preg_match('/^[a-z0-9_-]{1,40}$/', $value)) throw new \InvalidArgumentException('Project id: lowercase letters, numbers, dash or underscore (max 40).');
                return $value;
            case 'enabled_categories':
                $parts = array_values(array_filter(array_map('trim', explode(',', $value))));
                foreach ($parts as $p) {
                    if (!in_array($p, self::CATEGORIES, true)) throw new \InvalidArgumentException("Unknown category: $p");
                }
                return implode(',', array_unique($parts));
            case 'excluded_paths':
                $parts = array_values(array_filter(array_map('trim', explode(',', $value))));
                foreach ($parts as $p) {
                    if (!preg_match('#^/[A-Za-z0-9/_.-]{0,100}$#', $p)) throw new \InvalidArgumentException("Bad path: $p");
                }
                return implode(',', $parts);
            case 'trusted_proxies':
                $parts = array_values(array_filter(array_map('trim', explode(',', $value))));
                foreach ($parts as $p) {
                    if (!Privacy::validCidrOrIp($p)) throw new \InvalidArgumentException("Not an IP address or CIDR range: $p");
                }
                return implode(',', $parts);
            case 'geo_header':
                if ($value !== '' && !preg_match('/^[A-Za-z0-9-]{1,40}$/', $value)) throw new \InvalidArgumentException('Header name looks invalid.');
                return $value;
        }
        throw new \InvalidArgumentException("Unknown setting: $key");
    }

    /** Writes validated settings; returns the settings that actually changed as [key => [old, new]]. */
    public static function save(array $input, ?array $actor): array
    {
        $pdo = Module::requirePdo();
        $clean = [];
        foreach (self::EDITABLE as $k) {
            if (array_key_exists($k, $input)) $clean[$k] = self::validate($k, (string)$input[$k]);
        }
        $changes = [];
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare('INSERT INTO activity_settings (k, v, updated_at, updated_by) VALUES (?,?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at, updated_by=excluded.updated_by');
        foreach ($clean as $k => $v) {
            $old = self::get($k);
            if ($old === $v) continue;
            $st->execute([$k, $v, $now, $actor['id'] ?? null]);
            $changes[$k] = [$old, $v];
        }
        self::reset();
        return $changes;
    }

    /** Secret for keyed IP hashing. The environment variable wins; otherwise one is generated and stored once. */
    public static function secret(): string
    {
        $env = getenv('SITE_ACTIVITY_SECRET');
        if ($env) return (string)$env;
        $s = self::get('secret');
        if ($s !== '') return $s;
        $s = bin2hex(random_bytes(32));
        $pdo = Module::pdo();
        if ($pdo) {
            $pdo->prepare('INSERT OR IGNORE INTO activity_settings (k, v, updated_at) VALUES (?,?,?)')->execute(['secret', $s, gmdate('Y-m-d H:i:s')]);
            self::reset();
            $s = self::get('secret') ?: $s;
        }
        return $s;
    }
}
