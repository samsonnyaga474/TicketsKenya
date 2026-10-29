<?php
declare(strict_types=1);
namespace SiteActivity;

/** CSV exports. Sensitive fields (visitor ids, IP values, search text) are excluded unless the owner explicitly includes search text. */
final class Export
{
    public const TYPES = [
        'summary' => 'Summary numbers', 'events' => 'Activity log (selected period)', 'event_interest' => 'Event interest',
        'campaigns' => 'Campaigns', 'sources' => 'Traffic sources', 'devices' => 'Devices and browsers', 'pages' => 'Top pages',
        'audiences' => 'Audience summary (aggregate only)',
    ];

    /** Neutralises spreadsheet formulas: a cell that starts with = + - @ tab or CR is prefixed with an apostrophe. */
    public static function cell($v): string
    {
        if ($v === null) return '';
        $s = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        if (is_int($v) || is_float($v)) return $s;
        $s = str_replace(["\r\n", "\n", "\r"], ' ', $s);
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) return "'" . $s;
        return $s;
    }

    public static function line(array $cells): string
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, array_map([self::class, 'cell'], $cells), ',', '"', '');
        rewind($fh);
        $s = (string)stream_get_contents($fh);
        fclose($fh);
        return $s;
    }

    /** @return array{filename:string,header:array,rows:array} */
    public static function build(string $type, array $r, bool $includeQueries = false): array
    {
        $stamp = $r['from_date'] . '_to_' . $r['to_date'];
        switch ($type) {
            case 'summary':
                $o = Analytics::overview($r);
                $rows = [];
                foreach ($o as $k => $v) $rows[] = [$k, $v];
                return ['filename' => "activity-summary-$stamp.csv", 'header' => ['metric', 'value'], 'rows' => $rows];
            case 'events':
                $log = Analytics::eventsPage($r, 1, 50000);
                $head = ['occurred_at_utc', 'event', 'page', 'element_id', 'element_label', 'target', 'site_event_id', 'order_id', 'value', 'source'];
                if ($includeQueries) $head[] = 'search_text';
                $rows = [];
                foreach ($log['rows'] as $e) {
                    $row = [$e['occurred_at'], $e['name'], $e['path'], $e['element_id'], $e['element_label'], $e['target_path'], $e['event_ref_id'], $e['order_id'], $e['value_num'], $e['source']];
                    if ($includeQueries) $row[] = $e['query_text'];
                    $rows[] = $row;
                }
                return ['filename' => "activity-log-$stamp.csv", 'header' => $head, 'rows' => $rows];
            case 'event_interest':
                $rows = [];
                foreach (Analytics::eventInterest($r, 500) as $e) $rows[] = [$e['label'], $e['views'], $e['prev_views'], $e['clicks'], $e['checkouts'], $e['purchases'], $e['revenue']];
                return ['filename' => "event-interest-$stamp.csv", 'header' => ['event', 'views', 'views_previous_period', 'card_clicks', 'checkouts', 'purchases', 'verified_revenue'], 'rows' => $rows];
            case 'campaigns':
                $rows = [];
                foreach (Analytics::campaigns($r) as $c) $rows[] = [$c['source'], $c['medium'], $c['campaign'], $c['sessions'], $c['visitors'], $c['checkouts'], $c['purchases'], $c['revenue']];
                return ['filename' => "campaigns-$stamp.csv", 'header' => ['source', 'medium', 'campaign', 'sessions', 'visitors', 'checkouts', 'purchases', 'verified_revenue'], 'rows' => $rows];
            case 'sources':
                $rows = [];
                foreach (Analytics::sources($r, 200) as $s) $rows[] = [$s['type'], $s['source'], $s['sessions']];
                return ['filename' => "sources-$stamp.csv", 'header' => ['type', 'source', 'sessions'], 'rows' => $rows];
            case 'devices':
                $rows = [];
                foreach (['device_category', 'browser', 'os'] as $col) foreach (Analytics::breakdown($r, $col) as $b) $rows[] = [$col, $b['label'], $b['sessions']];
                return ['filename' => "devices-$stamp.csv", 'header' => ['dimension', 'value', 'sessions'], 'rows' => $rows];
            case 'pages':
                $rows = [];
                foreach (Analytics::topPages($r, 500) as $p) $rows[] = [$p['label'], $p['views'], $p['sessions']];
                return ['filename' => "pages-$stamp.csv", 'header' => ['page', 'views', 'sessions'], 'rows' => $rows];
            case 'audiences':
                // Deliberately export only definitions and threshold-masked sizes.
                // No emails, user ids, visitor ids or ad-platform identifiers.
                $rows = [];
                foreach (Audiences::all() as $a) {
                    $d = $a['definition'];
                    $definition = Audiences::TYPES[$d['type'] ?? ''] ?? 'unknown';
                    $qualifier = $d['category'] ?? ($d['event_id'] ?? ($d['campaign'] ?? ''));
                    if ($qualifier !== '') $definition .= ': ' . $qualifier;
                    $rows[] = [$a['name'], $definition,
                        $d['days'] ?? '', $a['estimate']['display'], $a['estimate']['note']];
                }
                return ['filename' => "audience-summary-$stamp.csv", 'header' => ['audience', 'definition', 'lookback_days', 'eligible_browser_count', 'note'], 'rows' => $rows];
        }
        throw new \InvalidArgumentException('Unknown export type.');
    }
}
