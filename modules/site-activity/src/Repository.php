<?php
declare(strict_types=1);
namespace SiteActivity;

/** The only place that writes to the activity_ tables (apart from Retention and Config). All queries are prepared. */
final class Repository
{
    private const EVENT_COLS = ['event_uid', 'project', 'session_id', 'visitor_uid', 'occurred_at', 'name', 'category', 'path', 'path_group',
        'element_id', 'element_label', 'target_path', 'event_ref_id', 'category_ref', 'order_id', 'value_num', 'query_text', 'meta_json', 'source', 'is_test'];

    /** Inserts one event. Returns the new id, or null when it was a duplicate (same event_uid, or same server event for the same order). */
    public static function insertEvent(array $e): ?int
    {
        $pdo = Module::requirePdo();
        $vals = [];
        foreach (self::EVENT_COLS as $c) $vals[$c] = $e[$c] ?? null;
        $vals['source'] ??= 'client';
        $vals['is_test'] = (int)($e['is_test'] ?? 0);
        $sql = 'INSERT OR IGNORE INTO activity_events (' . implode(',', self::EVENT_COLS) . ') VALUES (' . implode(',', array_fill(0, count(self::EVENT_COLS), '?')) . ')';
        $st = $pdo->prepare($sql);
        $st->execute(array_values($vals));
        return $st->rowCount() > 0 ? (int)$pdo->lastInsertId() : null;
    }

    public static function sessionByUid(string $uid): ?array
    {
        $st = Module::requirePdo()->prepare('SELECT * FROM activity_sessions WHERE session_uid = ?');
        $st->execute([$uid]);
        return $st->fetch() ?: null;
    }

    public static function createSession(array $s): int
    {
        $cols = ['session_uid', 'visitor_uid', 'project', 'started_at', 'last_seen_at', 'landing_path', 'last_path', 'referrer_host', 'referrer_type',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'device_category', 'browser', 'browser_version', 'os', 'screen_cat',
            'language', 'ip_value', 'ip_kind', 'ip_public', 'country', 'user_id', 'consent_analytics', 'consent_marketing', 'policy_version', 'is_test'];
        $pdo = Module::requirePdo();
        $vals = [];
        foreach ($cols as $c) $vals[] = $s[$c] ?? null;
        $st = $pdo->prepare('INSERT INTO activity_sessions (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $st->execute($vals);
        return (int)$pdo->lastInsertId();
    }

    /** Bumps counters and the "last seen" time of a session. */
    public static function touchSession(int $id, string $nowUtc, ?string $lastPath, int $pageViews, int $events, ?int $userId, int $marketing): void
    {
        Module::requirePdo()->prepare(
            'UPDATE activity_sessions SET last_seen_at = ?, last_path = COALESCE(?, last_path), page_views = page_views + ?, event_count = event_count + ?,
             user_id = COALESCE(?, user_id), consent_marketing = MAX(consent_marketing, ?) WHERE id = ?'
        )->execute([$nowUtc, $lastPath, $pageViews, $events, $userId, $marketing, $id]);
    }

    /** Counts $n hits in the current minute for $key and returns the running total. */
    public static function rateHit(string $key, int $n = 1): int
    {
        $pdo = Module::requirePdo();
        $w = intdiv(time(), 60);
        $pdo->prepare('INSERT INTO activity_rate (k, w, c) VALUES (?,?,?) ON CONFLICT(k) DO UPDATE SET c = CASE WHEN w = excluded.w THEN c + excluded.c ELSE excluded.c END, w = excluded.w')
            ->execute([$key, $w, $n]);
        if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM activity_rate WHERE w < ?')->execute([$w - 5]);
        $st = $pdo->prepare('SELECT c FROM activity_rate WHERE k = ?');
        $st->execute([$key]);
        return (int)$st->fetchColumn();
    }

    /** Internal diagnostics for the "tracking health" panel. Never put personal data or secrets in $detail. */
    public static function logError(string $kind, string $detail = ''): void
    {
        try {
            Module::requirePdo()->prepare('INSERT INTO activity_errors (at, kind, detail) VALUES (?,?,?)')
                ->execute([gmdate('Y-m-d H:i:s'), substr($kind, 0, 40), substr($detail, 0, 200)]);
        } catch (\Throwable $e) { /* diagnostics must never break the page */ }
    }

    /** Restricted administrative audit log (settings changes, exports, deletions, IP views). */
    public static function audit(string $action, array $detail, ?array $actor): void
    {
        $ip = Privacy::clientIp($_SERVER);
        [$ipv] = Privacy::ipForStorage($ip['ip'], 'truncated');
        Module::requirePdo()->prepare('INSERT INTO activity_audit_logs (at, actor_user_id, actor_email, action, detail, ip_value) VALUES (?,?,?,?,?,?)')
            ->execute([gmdate('Y-m-d H:i:s'), $actor['id'] ?? null, $actor['email'] ?? null, substr($action, 0, 60),
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ipv]);
    }

    public static function recordConsent(?string $visitor, ?int $userId, bool $analytics, bool $marketing, string $action): void
    {
        Module::requirePdo()->prepare('INSERT INTO activity_consent (visitor_uid, user_id, analytics, marketing, policy_version, action, decided_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$visitor, $userId, (int)$analytics, (int)($analytics && $marketing), Config::get('policy_version'), $action, gmdate('Y-m-d H:i:s')]);
    }
}
