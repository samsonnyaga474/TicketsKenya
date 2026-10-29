<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * Read-only reporting. Everything is calculated from stored rows; nothing is estimated unless a method says so.
 *
 * Definitions used across the dashboard
 *  - Visitor:  one browser (anonymous cookie id) that had at least one session starting in the period. It is not a person.
 *  - Session:  a run of activity from one browser; a new one starts after the inactivity timeout (setting).
 *  - Page view: one stored page_view event.
 *  - Active session: last activity within the "active window" (setting), which is shorter than the session timeout.
 *  - Checkout start: a real order created on the server (checkout_started).
 *  - Purchase: a real order the server verified as paid (purchase_completed). Never created by the browser.
 *  - Conversion rate: sessions with a verified purchase / sessions.
 */
final class Analytics
{
    public static bool $includeTest = false;

    private static function t(string $alias = ''): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        return self::$includeTest ? '' : " AND {$a}is_test = 0";
    }

    private static function all(string $sql, array $p = []): array
    {
        $st = Module::requirePdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function one(string $sql, array $p = [])
    {
        $st = Module::requirePdo()->prepare($sql);
        $st->execute($p);
        $v = $st->fetchColumn();
        return $v === false ? 0 : $v;
    }

    // ---------- date ranges (calendar days in the site's own timezone, stored in UTC) ----------

    public static function range(array $q): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $preset = (string)($q['range'] ?? 'today');
        $from = $today; $to = $today->modify('+1 day'); $label = 'Today';
        switch ($preset) {
            case 'yesterday': $from = $today->modify('-1 day'); $to = $today; $label = 'Yesterday'; break;
            case '7d': $from = $today->modify('-6 days'); $label = 'Last 7 days'; break;
            case '30d': $from = $today->modify('-29 days'); $label = 'Last 30 days'; break;
            case 'custom':
                $f = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($q['from'] ?? ''), $tz);
                $t = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($q['to'] ?? ''), $tz);
                if ($f && $t && $f <= $t && $f->diff($t)->days <= 366) {
                    $from = $f; $to = $t->modify('+1 day'); $label = $f->format('j M Y') . ' – ' . $t->format('j M Y');
                } else { $preset = 'today'; }
                break;
            default: $preset = 'today';
        }
        $days = max(1, (int)$from->diff($to)->days);
        return [
            'preset' => $preset, 'label' => $label, 'days' => $days,
            'from' => $from->setTimezone($utc)->format('Y-m-d H:i:s'), 'to' => $to->setTimezone($utc)->format('Y-m-d H:i:s'),
            'from_date' => $from->format('Y-m-d'), 'to_date' => $to->modify('-1 day')->format('Y-m-d'),
        ];
    }

    public static function previousRange(array $r): array
    {
        $len = strtotime($r['to'] . ' UTC') - strtotime($r['from'] . ' UTC');
        return ['from' => gmdate('Y-m-d H:i:s', strtotime($r['from'] . ' UTC') - $len), 'to' => $r['from']];
    }

    // ---------- overview ----------

    public static function overview(array $r): array
    {
        $p = [$r['from'], $r['to']];
        $sessions = (int)self::one('SELECT COUNT(*) FROM activity_sessions WHERE started_at >= ? AND started_at < ?' . self::t(), $p);
        $visitors = (int)self::one('SELECT COUNT(DISTINCT visitor_uid) FROM activity_sessions WHERE started_at >= ? AND started_at < ?' . self::t(), $p);
        // A returning visitor is a browser with a prior recorded session before
        // this reporting window and at least one session inside it. This is not
        // a person-level measure and only includes consented tracking rows.
        $returning = (int)self::one('SELECT COUNT(DISTINCT s.visitor_uid) FROM activity_sessions s
            WHERE s.started_at >= ? AND s.started_at < ?' . self::t('s') . '
            AND EXISTS (SELECT 1 FROM activity_sessions prior WHERE prior.visitor_uid = s.visitor_uid AND prior.started_at < ?' . self::t('prior') . ')',
            [$r['from'], $r['to'], $r['from']]);
        $pv = (int)self::one("SELECT COUNT(*) FROM activity_events WHERE name = 'page_view' AND occurred_at >= ? AND occurred_at < ?" . self::t(), $p);
        $dur = self::one("SELECT AVG(CAST(strftime('%s', last_seen_at) AS INTEGER) - CAST(strftime('%s', started_at) AS INTEGER)) FROM activity_sessions WHERE started_at >= ? AND started_at < ? AND event_count > 1" . self::t(), $p);
        $checkouts = (int)self::one("SELECT COUNT(*) FROM activity_events WHERE name = 'checkout_started' AND occurred_at >= ? AND occurred_at < ?" . self::t(), $p);
        $purchases = (int)self::one("SELECT COUNT(*) FROM activity_events WHERE name = 'purchase_completed' AND occurred_at >= ? AND occurred_at < ?" . self::t(), $p);
        $revenue = (int)self::one("SELECT COALESCE(SUM(value_num),0) FROM activity_events WHERE name = 'purchase_completed' AND occurred_at >= ? AND occurred_at < ?" . self::t(), $p);
        $buyers = (int)self::one("SELECT COUNT(DISTINCT session_id) FROM activity_events WHERE name = 'purchase_completed' AND session_id IS NOT NULL AND occurred_at >= ? AND occurred_at < ?" . self::t(), $p);
        return [
            'visitors' => $visitors, 'returning_visitors' => $returning,
            'returning_visitor_rate' => $visitors ? round(100 * $returning / $visitors, 2) : 0.0,
            'sessions' => $sessions, 'page_views' => $pv,
            'pages_per_session' => $sessions ? round($pv / $sessions, 2) : 0.0,
            'avg_session_seconds' => $dur ? (int)round((float)$dur) : 0,
            'checkout_starts' => $checkouts, 'purchases' => $purchases, 'revenue' => $revenue,
            'conversion_rate' => $sessions ? round(100 * $buyers / $sessions, 2) : 0.0,
        ];
    }

    // ---------- trend over time ----------

    /**
     * Sessions, page views, checkout starts and purchases per bucket: hourly for a one-day period, daily otherwise.
     * Empty buckets are returned as zeros (a quiet day is a real zero, not a gap). Buckets are fixed-length from the period
     * start, which is exact for a timezone without daylight saving such as Africa/Nairobi.
     * @return array{unit:string,buckets:array<int,array{label:string,sessions:int,page_views:int,checkouts:int,purchases:int}>}
     */
    public static function trend(array $r): array
    {
        $from = strtotime($r['from'] . ' UTC'); $to = strtotime($r['to'] . ' UTC');
        $hourly = ($to - $from) <= 90000;
        $size = $hourly ? 3600 : 86400;
        $n = max(1, (int)ceil(($to - $from) / $size));
        $b = [];
        for ($i = 0; $i < $n; $i++) {
            $b[$i] = ['label' => date($hourly ? 'H:00' : 'j M', $from + $i * $size), 'sessions' => 0, 'page_views' => 0, 'checkouts' => 0, 'purchases' => 0];
        }
        $idx = fn(string $col) => "CAST((CAST(strftime('%s', $col) AS INTEGER) - $from) / $size AS INTEGER)";
        foreach (self::all('SELECT ' . $idx('started_at') . ' AS i, COUNT(*) AS n FROM activity_sessions WHERE started_at >= ? AND started_at < ?' . self::t() . ' GROUP BY i', [$r['from'], $r['to']]) as $x) {
            if (isset($b[(int)$x['i']])) $b[(int)$x['i']]['sessions'] = (int)$x['n'];
        }
        foreach (self::all('SELECT ' . $idx('occurred_at') . " AS i, SUM(name = 'page_view') AS pv, SUM(name = 'checkout_started') AS ck, SUM(name = 'purchase_completed') AS pu
            FROM activity_events WHERE occurred_at >= ? AND occurred_at < ?" . self::t() . ' GROUP BY i', [$r['from'], $r['to']]) as $x) {
            $i = (int)$x['i'];
            if (isset($b[$i])) { $b[$i]['page_views'] = (int)$x['pv']; $b[$i]['checkouts'] = (int)$x['ck']; $b[$i]['purchases'] = (int)$x['pu']; }
        }
        return ['unit' => $hourly ? 'hour' : 'day', 'buckets' => $b];
    }

    // ---------- live ----------

    public static function activeSessions(int $limit = 50): array
    {
        $cut = gmdate('Y-m-d H:i:s', time() - Config::int('active_window_min') * 60);
        return self::all(
            'SELECT s.id, s.visitor_uid, s.session_uid, s.started_at, s.last_seen_at, s.last_path, s.referrer_type, s.referrer_host, s.utm_source, s.utm_campaign,
                    s.device_category, s.browser, s.browser_version, s.os, s.screen_cat, s.country, s.user_id, s.page_views, s.event_count, s.consent_marketing
             FROM activity_sessions s WHERE s.last_seen_at >= ?' . self::t('s') . ' ORDER BY s.last_seen_at DESC LIMIT ' . (int)$limit,
            [$cut]
        );
    }

    public static function activeCount(): int
    {
        $cut = gmdate('Y-m-d H:i:s', time() - Config::int('active_window_min') * 60);
        return (int)self::one('SELECT COUNT(*) FROM activity_sessions WHERE last_seen_at >= ?' . self::t(), [$cut]);
    }

    public static function recentEvents(int $limit = 30, int $afterId = 0): array
    {
        return self::all(
            'SELECT e.id, e.occurred_at, e.name, e.path, e.path_group, e.element_id, e.element_label, e.target_path, e.event_ref_id, e.order_id, e.value_num,
                    e.query_text, e.source, substr(e.visitor_uid, 1, 8) AS visitor_short, s.device_category
             FROM activity_events e LEFT JOIN activity_sessions s ON s.id = e.session_id
             WHERE e.id > ?' . self::t('e') . ' ORDER BY e.id DESC LIMIT ' . (int)$limit,
            [$afterId]
        );
    }

    /** Paginated, filterable event list for the activity log. */
    public static function eventsPage(array $r, int $page, int $per = 50, string $name = ''): array
    {
        $where = 'e.occurred_at >= ? AND e.occurred_at < ?' . self::t('e');
        $p = [$r['from'], $r['to']];
        if ($name !== '' && Registry::known($name)) { $where .= ' AND e.name = ?'; $p[] = $name; }
        $total = (int)self::one("SELECT COUNT(*) FROM activity_events e WHERE $where", $p);
        $rows = self::all(
            "SELECT e.id, e.occurred_at, e.name, e.path, e.element_id, e.element_label, e.target_path, e.event_ref_id, e.order_id, e.value_num, e.query_text, e.source,
                    substr(e.visitor_uid, 1, 8) AS visitor_short, s.device_category
             FROM activity_events e LEFT JOIN activity_sessions s ON s.id = e.session_id
             WHERE $where ORDER BY e.id DESC LIMIT " . (int)$per . ' OFFSET ' . (int)max(0, ($page - 1) * $per),
            $p
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int)ceil($total / $per)), 'page' => $page];
    }

    // ---------- rankings ----------

    public static function topPages(array $r, int $limit = 10): array
    {
        return self::all("SELECT path_group AS label, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions FROM activity_events
            WHERE name = 'page_view' AND occurred_at >= ? AND occurred_at < ?" . self::t() . " GROUP BY path_group ORDER BY views DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function topElements(array $r, int $limit = 10): array
    {
        return self::all("SELECT element_id, MAX(element_label) AS label, name, COUNT(*) AS clicks FROM activity_events
            WHERE name IN ('navigation_click','link_click','button_click','outbound_link_clicked','event_card_clicked') AND element_id IS NOT NULL
            AND occurred_at >= ? AND occurred_at < ?" . self::t() . " GROUP BY element_id, name ORDER BY clicks DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function topSearches(array $r, int $limit = 10): array
    {
        return self::all("SELECT LOWER(query_text) AS term, COUNT(*) AS n FROM activity_events WHERE name = 'search_submitted' AND query_text IS NOT NULL
            AND occurred_at >= ? AND occurred_at < ?" . self::t() . " GROUP BY LOWER(query_text) ORDER BY n DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function categories(array $r, int $limit = 10): array
    {
        return self::all("SELECT category_ref AS label, SUM(name = 'event_viewed') AS views, SUM(name = 'event_card_clicked') AS clicks, SUM(name = 'filter_changed') AS filters
            FROM activity_events WHERE category_ref IS NOT NULL AND name IN ('event_viewed','event_card_clicked','filter_changed')
            AND occurred_at >= ? AND occurred_at < ?" . self::t() . " GROUP BY category_ref ORDER BY (views + clicks + filters) DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function cityInterest(array $r, int $limit = 10): array
    {
        return self::all("SELECT json_extract(meta_json, '\$.value') AS label, COUNT(*) AS n FROM activity_events
            WHERE name = 'filter_changed' AND json_extract(meta_json, '\$.field') = 'city' AND json_extract(meta_json, '\$.value') <> ''
            AND occurred_at >= ? AND occurred_at < ?" . self::t() . " GROUP BY label ORDER BY n DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function sources(array $r, int $limit = 12): array
    {
        return self::all("SELECT COALESCE(referrer_type,'direct') AS type, COALESCE(utm_source, referrer_host, '(none)') AS source, COUNT(*) AS sessions
            FROM activity_sessions WHERE started_at >= ? AND started_at < ?" . self::t() . " GROUP BY type, source ORDER BY sessions DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function breakdown(array $r, string $col): array
    {
        if (!in_array($col, ['device_category', 'browser', 'os', 'screen_cat', 'language', 'country'], true)) return [];
        return self::all("SELECT COALESCE($col,'unknown') AS label, COUNT(*) AS sessions FROM activity_sessions
            WHERE started_at >= ? AND started_at < ?" . self::t() . " GROUP BY label ORDER BY sessions DESC LIMIT 12", [$r['from'], $r['to']]);
    }

    // ---------- journeys ----------

    public static function entryPages(array $r, int $limit = 10): array
    {
        return self::all("SELECT landing_path AS label, COUNT(*) AS sessions FROM activity_sessions WHERE landing_path IS NOT NULL
            AND started_at >= ? AND started_at < ?" . self::t() . " GROUP BY landing_path ORDER BY sessions DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function exitPages(array $r, int $limit = 10): array
    {
        return self::all("SELECT last_path AS label, COUNT(*) AS sessions FROM activity_sessions WHERE last_path IS NOT NULL AND page_views > 0
            AND started_at >= ? AND started_at < ?" . self::t() . " GROUP BY last_path ORDER BY sessions DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    public static function transitions(array $r, int $limit = 15): array
    {
        return self::all("SELECT prev AS from_page, cur AS to_page, COUNT(*) AS n FROM (
                SELECT path_group AS cur, LAG(path_group) OVER (PARTITION BY session_id ORDER BY occurred_at, id) AS prev
                FROM activity_events WHERE name = 'page_view' AND session_id IS NOT NULL AND occurred_at >= ? AND occurred_at < ?" . self::t() . "
            ) WHERE prev IS NOT NULL AND prev <> cur GROUP BY prev, cur ORDER BY n DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    /** Most common page sequences (first five distinct steps), with checkout/purchase markers appended. */
    public static function commonPaths(array $r, int $limit = 10): array
    {
        $rows = self::all("SELECT session_id, path_group FROM activity_events WHERE name = 'page_view' AND session_id IS NOT NULL
            AND occurred_at >= ? AND occurred_at < ?" . self::t() . " ORDER BY session_id, occurred_at, id LIMIT 20000", [$r['from'], $r['to']]);
        $seq = [];
        foreach ($rows as $x) {
            $s = (int)$x['session_id'];
            $last = !empty($seq[$s]) ? end($seq[$s]) : null;
            if ($last !== $x['path_group'] && count($seq[$s] ?? []) < 5) $seq[$s][] = $x['path_group'];
        }
        $marks = [];
        foreach (self::all("SELECT DISTINCT session_id, name FROM activity_events WHERE name IN ('checkout_started','purchase_completed') AND session_id IS NOT NULL
            AND occurred_at >= ? AND occurred_at < ?" . self::t(), [$r['from'], $r['to']]) as $m) { $marks[(int)$m['session_id']][$m['name']] = true; }
        $count = [];
        foreach ($seq as $sid => $steps) {
            if (isset($marks[$sid]['checkout_started'])) $steps[] = '[checkout started]';
            if (isset($marks[$sid]['purchase_completed'])) $steps[] = '[purchase]';
            $k = implode(' → ', $steps);
            $count[$k] = ($count[$k] ?? 0) + 1;
        }
        arsort($count);
        $out = [];
        foreach (array_slice($count, 0, $limit, true) as $k => $n) $out[] = ['path' => $k, 'sessions' => $n];
        return $out;
    }

    // ---------- events, funnel, orders ----------

    public static function eventInterest(array $r, int $limit = 20): array
    {
        $p = [$r['from'], $r['to']];
        $pv = self::previousRange($r);
        $rows = self::all("SELECT event_ref_id AS id,
                SUM(name = 'event_viewed') AS views, SUM(name = 'event_card_clicked') AS clicks,
                SUM(name = 'checkout_started') AS checkouts, SUM(name = 'purchase_completed') AS purchases,
                COALESCE(SUM(CASE WHEN name = 'purchase_completed' THEN value_num END), 0) AS revenue
            FROM activity_events WHERE event_ref_id IS NOT NULL AND name IN ('event_viewed','event_card_clicked','checkout_started','purchase_completed')
            AND occurred_at >= ? AND occurred_at < ?" . self::t() . " GROUP BY event_ref_id ORDER BY views DESC, clicks DESC LIMIT " . (int)$limit, $p);
        $prev = [];
        foreach (self::all("SELECT event_ref_id AS id, COUNT(*) AS views FROM activity_events WHERE name = 'event_viewed' AND event_ref_id IS NOT NULL
            AND occurred_at >= ? AND occurred_at < ?" . self::t() . " GROUP BY event_ref_id", [$pv['from'], $pv['to']]) as $x) $prev[(int)$x['id']] = (int)$x['views'];
        $labels = Module::host()->eventLabels(array_map(fn($x) => (int)$x['id'], $rows));
        foreach ($rows as &$x) {
            $x['label'] = $labels[(int)$x['id']] ?? ('Event #' . (int)$x['id']);
            $x['views'] = (int)$x['views']; $x['clicks'] = (int)$x['clicks']; $x['checkouts'] = (int)$x['checkouts']; $x['purchases'] = (int)$x['purchases']; $x['revenue'] = (int)$x['revenue'];
            $x['prev_views'] = $prev[(int)$x['id']] ?? 0;
            $x['view_to_checkout'] = $x['views'] ? round(100 * $x['checkouts'] / $x['views'], 1) : null;
            $x['checkout_to_purchase'] = $x['checkouts'] ? round(100 * $x['purchases'] / $x['checkouts'], 1) : null;
        }
        unset($x);
        return $rows;
    }

    public static function funnel(array $r): array
    {
        $p = [$r['from'], $r['to']];
        $dist = fn(string $name) => (int)self::one("SELECT COUNT(DISTINCT session_id) FROM activity_events WHERE name = ? AND session_id IS NOT NULL AND occurred_at >= ? AND occurred_at < ?" . self::t(), [$name, ...$p]);
        return [
            ['label' => 'Sessions', 'n' => (int)self::one('SELECT COUNT(*) FROM activity_sessions WHERE started_at >= ? AND started_at < ?' . self::t(), $p)],
            ['label' => 'Opened an event page', 'n' => $dist('event_viewed')],
            ['label' => 'Started checkout (order created)', 'n' => $dist('checkout_started')],
            ['label' => 'Paid (verified on the server)', 'n' => $dist('purchase_completed')],
        ];
    }

    /**
     * Orders table view of the period (independent of consent). An order counts as an "abandonment estimate" only when it is
     * older than the abandonment window and still not paid; younger unpaid orders are "still open".
     */
    public static function orderPicture(array $r): array
    {
        $orders = Module::host()->ordersCreatedBetween($r['from'], $r['to']);
        $cut = time() - Config::int('abandonment_window_hours') * 3600;
        $paid = $open = $abandoned = $revenue = 0;
        foreach ($orders as $o) {
            if (!empty($o['paid'])) { $paid++; $revenue += (int)$o['total']; }
            elseif (strtotime($o['created_at'] . ' UTC') <= $cut) $abandoned++;
            else $open++;
        }
        $tracked = (int)self::one("SELECT COUNT(*) FROM activity_events WHERE name = 'checkout_started' AND occurred_at >= ? AND occurred_at < ?" . self::t(), [$r['from'], $r['to']]);
        return ['orders' => count($orders), 'paid' => $paid, 'open' => $open, 'abandoned_estimate' => $abandoned, 'paid_revenue' => $revenue,
            'tracked_checkouts' => $tracked, 'untracked_orders' => max(0, count($orders) - $tracked),
            'abandon_rate' => count($orders) - $open > 0 ? round(100 * $abandoned / (count($orders) - $open), 1) : null];
    }

    /** Do the analytics purchase events add up to the host's own paid orders for the period? */
    public static function reconcile(array $r): array
    {
        $ev = (int)self::one("SELECT COALESCE(SUM(value_num),0) FROM activity_events WHERE name = 'purchase_completed' AND occurred_at >= ? AND occurred_at < ?" . self::t(), [$r['from'], $r['to']]);
        $evN = (int)self::one("SELECT COUNT(*) FROM activity_events WHERE name = 'purchase_completed' AND occurred_at >= ? AND occurred_at < ?" . self::t(), [$r['from'], $r['to']]);
        $hostTotal = 0; $hostN = 0;
        foreach (Module::host()->paidOrders(0, 100000) as $o) {
            if ($o['paid_at'] >= $r['from'] && $o['paid_at'] < $r['to']) { $hostTotal += (int)$o['total']; $hostN++; }
        }
        return ['analytics_revenue' => $ev, 'analytics_orders' => $evN, 'orders_revenue' => $hostTotal, 'orders_count' => $hostN, 'match' => ($ev === $hostTotal && $evN === $hostN)];
    }

    // ---------- campaigns ----------

    public static function campaigns(array $r): array
    {
        $p = [$r['from'], $r['to']];
        $rows = self::all("SELECT COALESCE(utm_source,'(none)') AS source, COALESCE(utm_medium,'(none)') AS medium, utm_campaign AS campaign,
                COUNT(*) AS sessions, COUNT(DISTINCT visitor_uid) AS visitors FROM activity_sessions
            WHERE utm_source IS NOT NULL AND started_at >= ? AND started_at < ?" . self::t() . " GROUP BY source, medium, campaign ORDER BY sessions DESC LIMIT 50", $p);
        $conv = self::all("SELECT COALESCE(l.utm_source,'(none)') AS source, COALESCE(l.utm_medium,'(none)') AS medium, l.utm_campaign AS campaign,
                SUM(e.name = 'checkout_started') AS checkouts, SUM(e.name = 'purchase_completed') AS purchases,
                COALESCE(SUM(CASE WHEN e.name = 'purchase_completed' THEN e.value_num END),0) AS revenue
            FROM activity_events e JOIN activity_order_links l ON l.order_id = e.order_id
            WHERE e.name IN ('checkout_started','purchase_completed') AND l.utm_source IS NOT NULL AND e.occurred_at >= ? AND e.occurred_at < ?" . self::t('e') . "
            GROUP BY source, medium, campaign", $p);
        $map = [];
        foreach ($conv as $c) $map[$c['source'] . '|' . $c['medium'] . '|' . ($c['campaign'] ?? '')] = $c;
        foreach ($rows as &$x) {
            $c = $map[$x['source'] . '|' . $x['medium'] . '|' . ($x['campaign'] ?? '')] ?? null;
            $x['checkouts'] = (int)($c['checkouts'] ?? 0); $x['purchases'] = (int)($c['purchases'] ?? 0); $x['revenue'] = (int)($c['revenue'] ?? 0);
            unset($map[$x['source'] . '|' . $x['medium'] . '|' . ($x['campaign'] ?? '')]);
        }
        unset($x);
        foreach ($map as $c) { // conversions whose landing session fell outside the period
            $rows[] = ['source' => $c['source'], 'medium' => $c['medium'], 'campaign' => $c['campaign'], 'sessions' => 0, 'visitors' => 0,
                'checkouts' => (int)$c['checkouts'], 'purchases' => (int)$c['purchases'], 'revenue' => (int)$c['revenue']];
        }
        return $rows;
    }

    public static function landingPages(array $r, int $limit = 10): array
    {
        return self::all("SELECT landing_path AS label, COUNT(*) AS sessions FROM activity_sessions WHERE utm_source IS NOT NULL AND landing_path IS NOT NULL
            AND started_at >= ? AND started_at < ?" . self::t() . " GROUP BY landing_path ORDER BY sessions DESC LIMIT " . (int)$limit, [$r['from'], $r['to']]);
    }

    // ---------- health ----------

    public static function health(): array
    {
        $day = gmdate('Y-m-d H:i:s', time() - 86400);
        $errors = self::all('SELECT kind, COUNT(*) AS n, MAX(at) AS last_at FROM activity_errors WHERE at >= ? GROUP BY kind ORDER BY n DESC', [$day]);
        $consent = self::all("SELECT action, COUNT(*) AS n FROM activity_consent WHERE decided_at >= ? GROUP BY action", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        return [
            'enabled' => Config::bool('tracking_enabled'), 'consent_mode' => Config::get('consent_mode'),
            'last_event_at' => self::one('SELECT MAX(occurred_at) FROM activity_events') ?: null,
            'events_24h' => (int)self::one('SELECT COUNT(*) FROM activity_events WHERE occurred_at >= ?' . self::t(), [$day]),
            'sessions_24h' => (int)self::one('SELECT COUNT(*) FROM activity_sessions WHERE started_at >= ?' . self::t(), [$day]),
            'errors_24h' => $errors, 'consent_30d' => $consent,
            'test_rows' => (int)self::one('SELECT COUNT(*) FROM activity_events WHERE is_test = 1'),
        ];
    }

    /** Visitors who left a marketing-eligible, non-suppressed trace in a period. Used by Audiences. */
    public static function totalsForAudit(): array
    {
        return [
            'events' => (int)self::one('SELECT COUNT(*) FROM activity_events'),
            'sessions' => (int)self::one('SELECT COUNT(*) FROM activity_sessions'),
            'oldest_event' => self::one('SELECT MIN(occurred_at) FROM activity_events') ?: null,
        ];
    }
}
