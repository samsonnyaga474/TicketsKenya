<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * Cleanup and erasure. Every statement here touches only activity_* tables, so orders, tickets, users and
 * payment records can never be deleted by this class.
 */
final class Retention
{
    /** Applies the configured retention periods. @return array<string,int> rows affected per step */
    public static function run(?array $actor = null): array
    {
        $pdo = Module::requirePdo();
        $now = gmdate('Y-m-d H:i:s');
        $ago = fn(int $days) => gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $out = [];
        $do = function (string $key, string $sql, array $p) use ($pdo, &$out) { $st = $pdo->prepare($sql); $st->execute($p); $out[$key] = $st->rowCount(); };

        $do('ip_anonymised', "UPDATE activity_sessions SET ip_value = NULL, ip_kind = 'none', anonymised_at = ? WHERE ip_value IS NOT NULL AND started_at < ?", [$now, $ago(Config::int('retention_ip_days'))]);
        $do('events_deleted', 'DELETE FROM activity_events WHERE occurred_at < ?', [$ago(Config::int('retention_events_days'))]);
        $do('sessions_deleted', 'DELETE FROM activity_sessions WHERE started_at < ?', [$ago(Config::int('retention_sessions_days'))]);
        $do('order_links_deleted', 'DELETE FROM activity_order_links WHERE linked_at < ?', [$ago(Config::int('retention_sessions_days'))]);
        $do('audit_deleted', 'DELETE FROM activity_audit_logs WHERE at < ?', [$ago(Config::int('retention_audit_days'))]);
        $do('errors_deleted', 'DELETE FROM activity_errors WHERE at < ?', [$ago(30)]);
        $do('consent_log_deleted', 'DELETE FROM activity_consent WHERE decided_at < ?', [$ago(1095)]); // consent proof kept 3 years
        $pdo->exec('DELETE FROM activity_rate WHERE w < ' . (intdiv(time(), 60) - 10));
        Repository::audit('retention_run', $out, $actor);
        return $out;
    }

    /** Erases the analytics trace of one browser. Orders and tickets are untouched. Consent rows are kept but unlinked. */
    public static function eraseVisitor(string $visitorUid, ?array $actor = null): array
    {
        if (!Privacy::validId($visitorUid)) return ['events' => 0, 'sessions' => 0, 'order_links' => 0];
        $pdo = Module::requirePdo();
        $out = [];
        $do = function (string $key, string $sql) use ($pdo, $visitorUid, &$out) { $st = $pdo->prepare($sql); $st->execute([$visitorUid]); $out[$key] = $st->rowCount(); };
        $do('events', 'DELETE FROM activity_events WHERE visitor_uid = ?');
        $do('order_links', 'DELETE FROM activity_order_links WHERE visitor_uid = ?');
        $do('sessions', 'DELETE FROM activity_sessions WHERE visitor_uid = ?');
        $do('consent_unlinked', 'UPDATE activity_consent SET visitor_uid = NULL WHERE visitor_uid = ?');
        Repository::audit('visitor_erased', ['counts' => $out, 'by' => $actor ? 'owner' : 'visitor'], $actor);
        return $out;
    }

    /** Owner tool for an access/erasure request from a logged-in customer: removes analytics linked to that account. */
    public static function eraseUser(int $userId, ?array $actor): array
    {
        $pdo = Module::requirePdo();
        $st = $pdo->prepare('SELECT DISTINCT visitor_uid FROM activity_sessions WHERE user_id = ?');
        $st->execute([$userId]);
        $sum = ['events' => 0, 'sessions' => 0, 'order_links' => 0, 'consent_unlinked' => 0];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $v) {
            foreach (self::eraseVisitor((string)$v, $actor) as $k => $n) $sum[$k] += $n;
        }
        $pdo->prepare('UPDATE activity_consent SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE activity_sessions SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        Repository::audit('user_analytics_erased', ['user_id' => $userId, 'counts' => $sum], $actor);
        return $sum;
    }
}
