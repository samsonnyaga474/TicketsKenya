<?php
declare(strict_types=1);
namespace SiteActivity;

/** Raw, already-safe HTML that Ui::table() must not escape again. */
final class Raw { public function __construct(public string $html) {} }

/**
 * Small rendering helpers for the owner pages. They reuse the host site's own classes
 * (section-inner, page-hero, table-wrap, btn, empty-state, callout, tag) and add only a few "sa-" layout classes.
 */
final class Ui
{
    public const PAGES = [
        'index' => 'Overview', 'live' => 'Live', 'journeys' => 'Journeys', 'events' => 'Event interest',
        'campaigns' => 'Campaigns', 'audiences' => 'Audiences', 'log' => 'Activity log', 'data' => 'Exports & data',
        'settings' => 'Settings',
    ];

    public static function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function raw(string $html): Raw { return new Raw($html); }
    public static function num($n): string { return number_format((float)$n, 0, '.', ','); }
    public static function kes($n): string { return 'KES ' . number_format((float)$n, 0, '.', ','); }
    public static function pct($v): string { return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') . '%'; }

    public static function dur(int $sec): string
    {
        if ($sec <= 0) return '—';
        return $sec >= 60 ? intdiv($sec, 60) . ' min ' . ($sec % 60) . ' s' : $sec . ' s';
    }

    /** UTC database time shown in the site's own timezone. */
    public static function local(?string $utc, string $fmt = 'j M, H:i'): string
    {
        if (!$utc) return '—';
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        return $d ? $d->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($fmt) : '—';
    }

    public static function url(string $page, array $q = []): string
    {
        $base = Module::host()->basePath() . '/admin/activity/' . $page . '.php';
        return $base . ($q ? '?' . http_build_query($q) : '');
    }

    public static function subnav(string $active, array $keep = []): string
    {
        $h = '<nav class="sa-subnav" aria-label="Activity & Insights sections">';
        foreach (self::PAGES as $k => $label) {
            $cur = $k === $active ? ' aria-current="page"' : '';
            $h .= '<a href="' . self::e(self::url($k, $keep)) . '"' . $cur . '>' . self::e($label) . '</a>';
        }
        return $h . '</nav>';
    }

    public static function rangeBar(array $r, array $hidden = []): string
    {
        $opts = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'custom' => 'Custom range'];
        $h = '<form class="sa-range" method="get" action=""><label class="sr-only" for="saRange">Period</label><select id="saRange" name="range">';
        foreach ($opts as $k => $l) $h .= '<option value="' . $k . '"' . ($r['preset'] === $k ? ' selected' : '') . '>' . self::e($l) . '</option>';
        $h .= '</select><label class="sr-only" for="saFrom">From</label><input id="saFrom" type="date" name="from" value="' . self::e($r['from_date']) . '">'
            . '<label class="sr-only" for="saTo">To</label><input id="saTo" type="date" name="to" value="' . self::e($r['to_date']) . '">';
        foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
        return $h . '<button class="btn btn-outline" type="submit">Apply</button></form>'
            . '<p class="sa-muted">Showing <strong>' . self::e($r['label']) . '</strong> (' . self::e($r['from_date']) . ' to ' . self::e($r['to_date']) . ', ' . self::e(date_default_timezone_get()) . ' days). Pick “Custom range” to use the two dates.</p>';
    }

    public static function kpi(string $label, string $value, string $hint = ''): string
    {
        return '<div class="sa-kpi"><span class="sa-kpi-l">' . self::e($label) . '</span><strong>' . self::e($value) . '</strong>'
            . ($hint !== '' ? '<small>' . self::e($hint) . '</small>' : '') . '</div>';
    }

    public static function panel(string $title, string $body, string $note = ''): string
    {
        return '<section class="sa-panel"><h2>' . self::e($title) . '</h2>' . ($note !== '' ? '<p class="sa-muted">' . self::e($note) . '</p>' : '') . $body . '</section>';
    }

    public static function emptyState(string $msg = 'No activity has been recorded for this period yet.'): string
    {
        return '<div class="empty-state"><strong>Nothing here yet</strong>' . self::e($msg) . ' Numbers appear after real visitors accept analytics and use the site.</div>';
    }

    /** @param array<int,string> $headers @param array<int,array<int,mixed>> $rows */
    public static function table(array $headers, array $rows, string $empty = ''): string
    {
        if (!$rows) return self::emptyState($empty ?: 'No activity has been recorded for this period yet.');
        $h = '<div class="table-wrap sa-table"><table><thead><tr>';
        foreach ($headers as $x) $h .= '<th scope="col">' . self::e($x) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $h .= '<tr>';
            foreach ($row as $cell) $h .= '<td>' . ($cell instanceof Raw ? $cell->html : self::e($cell)) . '</td>';
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    }

    /** Ranked list with proportional bars (pure CSS, no chart library). */
    public static function bars(array $rows, string $labelKey, string $valueKey, string $empty = ''): string
    {
        if (!$rows) return self::emptyState($empty ?: 'No activity has been recorded for this period yet.');
        $max = max(1, ...array_map(fn($r) => (int)$r[$valueKey], $rows));
        $h = '<ul class="sa-bars">';
        foreach ($rows as $r) {
            $w = max(2, (int)round(100 * (int)$r[$valueKey] / $max));
            $h .= '<li><span class="sa-bar-l">' . self::e($r[$labelKey]) . '</span><span class="sa-bar"><i style="width:' . $w . '%"></i></span><span class="sa-bar-n">' . self::num($r[$valueKey]) . '</span></li>';
        }
        return $h . '</ul>';
    }

    /** Inline-SVG chart: sessions as bars, page views as a line. Followed by a table with the exact numbers. */
    public static function trendChart(array $trend): string
    {
        $bk = $trend['buckets'];
        $tot = array_sum(array_column($bk, 'sessions')) + array_sum(array_column($bk, 'page_views'));
        if ($tot === 0) return self::emptyState('No activity has been recorded for this period yet.');
        $n = count($bk);
        $W = 720; $H = 240; $L = 44; $R = 12; $T = 14; $Bm = 30;
        $pw = $W - $L - $R; $ph = $H - $T - $Bm;
        $max = max(1, ...array_column($bk, 'sessions'), ...array_column($bk, 'page_views'));
        $step = $max <= 5 ? 1 : (int)ceil($max / 4);
        $top = (int)(ceil($max / $step) * $step);
        $slot = $pw / $n; $bw = max(1.0, min(40.0, $slot * 0.6));
        $svg = '<svg class="sa-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Sessions (bars) and page views (line) over time. Exact numbers are in the table below.">';
        for ($v = 0; $v <= $top; $v += $step) {
            $y = $T + $ph - ($v / $top) * $ph;
            $svg .= '<line class="grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/><text class="axis" x="' . ($L - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . $v . '</text>';
        }
        $pts = []; $every = max(1, (int)ceil($n / 12));
        foreach ($bk as $i => $x) {
            $cx = $L + $slot * ($i + 0.5);
            $h = ($x['sessions'] / $top) * $ph;
            $svg .= '<rect class="bar" x="' . round($cx - $bw / 2, 1) . '" y="' . round($T + $ph - $h, 1) . '" width="' . round($bw, 1) . '" height="' . round($h, 1) . '"><title>' . self::e($x['label'] . ': ' . $x['sessions'] . ' sessions, ' . $x['page_views'] . ' page views') . '</title></rect>';
            $pts[] = round($cx, 1) . ',' . round($T + $ph - ($x['page_views'] / $top) * $ph, 1);
            if ($i % $every === 0) $svg .= '<text class="axis" x="' . round($cx, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . self::e($x['label']) . '</text>';
        }
        $svg .= '<polyline class="line" points="' . implode(' ', $pts) . '"/>';
        foreach ($pts as $p) { [$px, $py] = explode(',', $p); $svg .= '<circle class="dot" cx="' . $px . '" cy="' . $py . '" r="2.5"/>'; }
        $svg .= '</svg>';
        $legend = '<p class="sa-legend"><span class="k-bar"></span> Sessions <span class="k-line"></span> Page views (per ' . ($trend['unit'] === 'hour' ? 'hour' : 'day') . ')</p>';
        $rows = array_map(fn($x) => [$x['label'], $x['sessions'], $x['page_views'], $x['checkouts'], $x['purchases']], $bk);
        $tbl = '<details class="sa-details"><summary>Show the numbers</summary>' . self::table([$trend['unit'] === 'hour' ? 'Hour' : 'Day', 'Sessions', 'Page views', 'Checkout starts', 'Purchases'], $rows) . '</details>';
        return $legend . '<div class="sa-chart-wrap">' . $svg . '</div>' . $tbl;
    }

    public static function flash(?string $msg, string $kind = 'success'): string
    {
        if (!$msg) return '';
        return '<div class="flash flash-' . ($kind === 'error' ? 'error' : 'success') . '" role="' . ($kind === 'error' ? 'alert' : 'status') . '">' . self::e($msg) . '</div>';
    }
}
