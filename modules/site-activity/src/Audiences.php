<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * First-party marketing segments. A segment is only a saved definition plus a size estimate: nothing is sent anywhere.
 * People are counted only when (a) the owner has switched audience collection on, (b) the visitor's LATEST consent choice
 * includes marketing, and therefore (c) anyone who withdrew is automatically suppressed.
 */
final class Audiences
{
    public const TYPES = [
        'category_interest' => 'Viewed or clicked events in a category',
        'event_viewed'      => 'Viewed a specific event',
        'checkout_abandoned' => 'Started checkout but did not pay (older than the abandonment window)',
        'campaign'          => 'Arrived through a campaign',
        'customers'         => 'Existing customers (verified paid orders) who agreed to marketing',
    ];
    public const MIN_SIZE = 5; // smaller groups are shown as "fewer than 5" so individuals cannot be picked out

    private const ELIGIBLE = "visitor_uid IN (SELECT c.visitor_uid FROM activity_consent c WHERE c.visitor_uid IS NOT NULL AND c.marketing = 1
        AND c.id = (SELECT MAX(c2.id) FROM activity_consent c2 WHERE c2.visitor_uid = c.visitor_uid))";

    public static function validate(array $def): array
    {
        $type = (string)($def['type'] ?? '');
        if (!isset(self::TYPES[$type])) throw new \InvalidArgumentException('Unknown audience type.');
        $days = (int)($def['days'] ?? 30);
        if ($days < 1 || $days > 365) throw new \InvalidArgumentException('Days must be between 1 and 365.');
        $out = ['type' => $type, 'days' => $days];
        if ($type === 'category_interest') {
            $c = Privacy::redact((string)($def['category'] ?? ''), 60);
            if ($c === '') throw new \InvalidArgumentException('Choose a category.');
            $out['category'] = $c;
        } elseif ($type === 'event_viewed') {
            $id = (int)($def['event_id'] ?? 0);
            if ($id < 1) throw new \InvalidArgumentException('Choose an event.');
            $out['event_id'] = $id;
        } elseif ($type === 'campaign') {
            $c = strtolower(preg_replace('/[^a-z0-9._\- ]/', '', (string)($def['campaign'] ?? '')) ?? '');
            if ($c === '') throw new \InvalidArgumentException('Enter a campaign name.');
            $out['campaign'] = substr($c, 0, 40);
        }
        return $out;
    }

    public static function create(string $name, array $def, ?array $actor): int
    {
        $name = Privacy::redact($name, 60);
        if ($name === '') throw new \InvalidArgumentException('Give the audience a name.');
        $def = self::validate($def);
        $pdo = Module::requirePdo();
        $pdo->prepare('INSERT INTO activity_audiences (name, definition_json, created_at, created_by) VALUES (?,?,?,?)')
            ->execute([$name, json_encode($def), gmdate('Y-m-d H:i:s'), $actor['id'] ?? null]);
        $id = (int)$pdo->lastInsertId();
        Repository::audit('audience_created', ['id' => $id, 'name' => $name, 'definition' => $def], $actor);
        return $id;
    }

    public static function delete(int $id, ?array $actor): void
    {
        Module::requirePdo()->prepare('DELETE FROM activity_audiences WHERE id = ?')->execute([$id]);
        Repository::audit('audience_deleted', ['id' => $id], $actor);
    }

    public static function all(): array
    {
        $rows = Module::requirePdo()->query('SELECT * FROM activity_audiences ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['definition'] = json_decode((string)$r['definition_json'], true) ?: []; $r['estimate'] = self::estimate($r['definition']); }
        return $rows;
    }

    /** @return array{size:?int,display:string,from:string,note:string} */
    public static function estimate(array $def): array
    {
        $days = (int)($def['days'] ?? 30);
        $from = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $pdo = Module::requirePdo();
        $note = 'Counts browsers (not people) whose latest choice includes marketing.';
        if (!Config::bool('audiences_enabled')) return ['size' => 0, 'display' => '0', 'from' => $from, 'note' => 'Audience collection is switched off in settings, so nobody is eligible.'];
        $n = 0;
        switch ($def['type'] ?? '') {
            case 'category_interest':
                $st = $pdo->prepare("SELECT COUNT(DISTINCT visitor_uid) FROM activity_events WHERE name IN ('event_viewed','event_card_clicked') AND category_ref = ? AND occurred_at >= ? AND is_test = 0 AND " . self::ELIGIBLE);
                $st->execute([$def['category'] ?? '', $from]); $n = (int)$st->fetchColumn(); break;
            case 'event_viewed':
                $st = $pdo->prepare("SELECT COUNT(DISTINCT visitor_uid) FROM activity_events WHERE name = 'event_viewed' AND event_ref_id = ? AND occurred_at >= ? AND is_test = 0 AND " . self::ELIGIBLE);
                $st->execute([(int)($def['event_id'] ?? 0), $from]); $n = (int)$st->fetchColumn(); break;
            case 'checkout_abandoned':
                $cut = gmdate('Y-m-d H:i:s', time() - Config::int('abandonment_window_hours') * 3600);
                $st = $pdo->prepare("SELECT COUNT(DISTINCT visitor_uid) FROM activity_events e WHERE name = 'checkout_started' AND occurred_at >= ? AND occurred_at <= ? AND is_test = 0
                    AND NOT EXISTS (SELECT 1 FROM activity_events p WHERE p.name = 'purchase_completed' AND p.order_id = e.order_id) AND " . self::ELIGIBLE);
                $st->execute([$from, $cut]); $n = (int)$st->fetchColumn(); break;
            case 'campaign':
                $st = $pdo->prepare("SELECT COUNT(DISTINCT visitor_uid) FROM activity_sessions WHERE utm_campaign = ? AND started_at >= ? AND is_test = 0 AND " . self::ELIGIBLE);
                $st->execute([$def['campaign'] ?? '', $from]); $n = (int)$st->fetchColumn(); break;
            case 'customers':
                $paid = Module::host()->paidCustomerUserIds();
                if ($paid) {
                    $in = implode(',', array_map('intval', $paid));
                    $n = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT c.user_id FROM activity_consent c WHERE c.user_id IN ($in) AND c.marketing = 1
                        AND c.id = (SELECT MAX(c2.id) FROM activity_consent c2 WHERE c2.user_id = c.user_id) GROUP BY c.user_id)")->fetchColumn();
                }
                $note = 'Counts customer accounts with a verified paid order whose latest recorded choice includes marketing.'; break;
        }
        $first = (string)$pdo->query('SELECT MIN(occurred_at) FROM activity_events')->fetchColumn();
        return ['size' => $n, 'display' => ($n > 0 && $n < self::MIN_SIZE) ? 'fewer than ' . self::MIN_SIZE : (string)$n, 'from' => $from,
            'note' => $note . ($first ? ' Data available since ' . $first . ' UTC.' : ' No activity data yet.')];
    }
}
