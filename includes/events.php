<?php
/**
 * Event listing helpers: eligibility, filtering, date grouping, pagination and display values.
 * Used by the homepage, /events/, the sitemap and structured data so every page agrees on
 * which events are "eligible" and what price text is allowed to appear.
 */
declare(strict_types=1);

const TK_EVENTS_PER_PAGE = 24;

/** Tickets still available for sale (total minus sold). Works with SQLite and JSON stores. */
function tk_ticket_available(array $tt): int
{
    if (isset($tt['quantity_available'])) {
        return max(0, (int)$tt['quantity_available']);
    }
    $total = (int)($tt['quantity_total'] ?? 0);
    $sold = (int)($tt['quantity_sold'] ?? 0);
    return max(0, $total - $sold);
}


/** Public categories shown in the filter dialog (matches the reference filter list). */
function tk_known_categories(): array
{
    $base = ['Arts & Crafts', 'Nightlife & Parties', 'Fashion & Pageants', 'Networking & Conferences',
        'Religion & Spirituality', 'Comedy', 'Travel & Outdoors', 'Education', 'Community', 'Charity',
        'Family', 'Sports & Gaming', 'Concerts & Live Music', 'Food & Drink', 'Film, Theatre & Performing Arts',
        'Health & Wellness'];
    // Also offer any category that real events use, so no event is ever unreachable by category.
    $extra = [];
    foreach (tk_repo_all('events') as $e) {
        $c = trim((string)($e['category'] ?? ''));
        if ($c !== '' && !in_array($c, $base, true)) $extra[$c] = true;
    }
    return array_merge($base, array_keys($extra));
}

/** Lowest verified ticket price per event id (only TicketsKenya-managed ticket types). */
function tk_event_min_prices(): array
{
    $min = [];
    foreach (tk_repo_all('ticket_types') as $t) {
        $eid = (int)$t['event_id'];
        $p = (int)$t['price_kes'];
        if (!isset($min[$eid]) || $p < $min[$eid]) $min[$eid] = $p;
    }
    return $min;
}

/** Event start as a timestamp, or null when the stored value cannot be parsed. */
function tk_event_ts(array $e): ?int
{
    $t = strtotime((string)($e['starts_at'] ?? ''));
    return $t === false ? null : $t;
}

/** Is this event allowed to appear in public "upcoming" listings right now? */
function tk_event_is_upcoming(array $e, ?int $now = null): bool
{
    $now ??= time();
    if (!in_array($e['status'] ?? '', ['published', 'sold_out'], true)) return false; // drafts, cancelled, completed, archived stay out
    $start = tk_event_ts($e);
    if ($start === null || trim((string)($e['title'] ?? '')) === '') return false;     // incomplete record
    $end = !empty($e['ends_at']) ? strtotime((string)$e['ends_at']) : false;
    $until = ($end !== false && $end > $start) ? $end : $start + 3 * 3600;               // no end time: keep 3h after start
    return $until > $now;
}

/** Date window helper for the Today / Tomorrow / This weekend filters. Returns [from, to) timestamps. */
function tk_date_window(string $key, ?int $now = null): ?array
{
    $now ??= time();
    $today = strtotime('today', $now);
    switch ($key) {
        case 'today':    return [$today, strtotime('+1 day', $today)];
        case 'tomorrow': return [strtotime('+1 day', $today), strtotime('+2 day', $today)];
        case 'weekend':
            $dow = (int)date('N', $today);               // 1=Mon .. 7=Sun
            if ($dow >= 6) $from = strtotime('-' . ($dow - 6) . ' day', $today);
            else $from = strtotime('+' . (6 - $dow) . ' day', $today);
            return [$from, strtotime('+2 day', $from)];
    }
    return null;
}

/**
 * Filtered, sorted list of upcoming events.
 * $f keys: q, city, category (string|array), when (today|tomorrow|weekend), from, to (Y-m-d), price (free|paid), sort (date|title)
 */
function tk_events_query(array $f = [], ?int $now = null): array
{
    $now ??= time();
    $min = tk_event_min_prices();
    $cats = $f['category'] ?? [];
    $cats = is_array($cats) ? array_values(array_filter(array_map('strval', $cats))) : (($cats !== '') ? [(string)$cats] : []);
    $q = strtolower(trim((string)($f['q'] ?? '')));
    $city = trim((string)($f['city'] ?? ''));
    $win = tk_date_window((string)($f['when'] ?? ''), $now);
    $from = !empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['from']) ? strtotime((string)$f['from']) : false;
    $to   = !empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['to']) ? strtotime('+1 day', strtotime((string)$f['to'])) : false;
    $price = (string)($f['price'] ?? '');

    $out = [];
    foreach (tk_repo_all('events') as $e) {
        if (!tk_event_is_upcoming($e, $now)) continue;
        $ts = tk_event_ts($e);
        if ($city !== '' && ($e['city'] ?? '') !== $city) continue;
        if ($cats && !in_array((string)($e['category'] ?? ''), $cats, true)) continue;
        if ($win && ($ts < $win[0] || $ts >= $win[1])) continue;
        if ($from !== false && $ts < $from) continue;
        if ($to !== false && $ts >= $to) continue;
        if ($q !== '') {
            $hay = strtolower(($e['title'] ?? '') . ' ' . ($e['venue'] ?? '') . ' ' . ($e['city'] ?? '') . ' ' . ($e['category'] ?? '') . ' ' . ($e['description'] ?? ''));
            if (!str_contains($hay, $q)) continue;
        }
        $p = $min[(int)$e['id']] ?? null;
        if ($price === 'free' && $p !== 0) continue;          // only verified prices can be called free
        if ($price === 'paid' && ($p === null || $p <= 0)) continue;
        $out[] = $e;
    }
    $sort = (string)($f['sort'] ?? 'date');
    if ($sort === 'title') usort($out, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    else usort($out, fn($a, $b) => (tk_event_ts($a) <=> tk_event_ts($b)) ?: strcasecmp($a['title'], $b['title']));
    return $out;
}

/** Group an already-sorted list under "Y-m-d" keys. */
function tk_events_group_by_date(array $events): array
{
    $g = [];
    foreach ($events as $e) $g[date('Y-m-d', tk_event_ts($e))][] = $e;
    ksort($g);
    return $g;
}

function tk_date_heading(string $ymd, ?int $now = null): string
{
    $now ??= time();
    $ts = strtotime($ymd);
    $label = date('j M, Y', $ts);
    if ($ymd === date('Y-m-d', $now)) return 'Today · ' . $label;
    if ($ymd === date('Y-m-d', strtotime('+1 day', $now))) return 'Tomorrow · ' . $label;
    return date('D', $ts) . ' · ' . $label;
}

/** Price text allowed on a card. Never invents a price. */
function tk_event_price_text(array $e, array $minPrices): string
{
    $id = (int)($e['id'] ?? 0);
    if (isset($minPrices[$id])) return $minPrices[$id] === 0 ? 'Free' : 'From KES ' . number_format($minPrices[$id]);
    if (!empty($e['price_info'])) return (string)$e['price_info'];
    return 'Price not confirmed';
}

/** True when the event has a real picture (an uploaded poster); bundled stock photos do not count as posters. */
function tk_event_has_poster(array $e): bool
{
    $img = (string)($e['cover_image'] ?? '');
    return $img !== '' && str_starts_with($img, 'uploads/') && is_file(TK_PUBLIC . '/assets/' . $img);
}

/** Clean public URL for an event page. */
function tk_event_url(array $e): string
{
    return tk_base_path() . '/events/' . rawurlencode((string)$e['slug']);
}

/** Only http(s) links may be rendered as outbound links (blocks javascript: and data: URLs). */
function tk_safe_url(?string $u): string
{
    $u = trim((string)$u);
    return (preg_match('#^https?://[^\s<>"\']+$#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) ? $u : '';
}

/** Query string for links, keeping current filters. */
function tk_qs(array $params, array $override = []): string
{
    $p = array_merge($params, $override);
    foreach ($p as $k => $v) if ($v === '' || $v === null || $v === [] ) unset($p[$k]);
    return $p ? '?' . http_build_query($p) : '';
}

/** Shared event card. $variant 'poster' (listing grid) or 'strip' (homepage carousel). */
function tk_render_event_card(array $ev, array $minPrices, string $variant = 'poster'): void
{
    $owned = ($ev['listing_type'] ?? '') === 'owned';
    $ts = tk_event_ts($ev);
    $url = tk_event_url($ev);
    $price = tk_event_price_text($ev, $minPrices);
    $unknown = $price === 'Price not confirmed';
    ?>
<article class="ev-card ev-v-<?= tk_e($variant) ?>">
  <a class="ev-poster" href="<?= tk_e($url) ?>" tabindex="-1" aria-hidden="true">
    <?php if (tk_event_has_poster($ev)): ?>
      <img src="<?= tk_e(tk_event_image($ev)) ?>" alt="" width="400" height="500" loading="lazy" decoding="async">
    <?php else: ?>
      <span class="ev-noposter">
        <span class="ev-nop-day"><?= tk_e(date('j', $ts)) ?></span>
        <span class="ev-nop-mon"><?= tk_e(date('M', $ts)) ?></span>
        <span class="ev-nop-cat"><?= tk_e((string)($ev['category'] ?? 'Event')) ?></span>
        <span class="ev-nop-note">Official poster not available</span>
      </span>
    <?php endif; ?>
  </a>
  <div class="ev-body">
    <h3 class="ev-title"><a href="<?= tk_e($url) ?>"><?= tk_e($ev['title']) ?></a></h3>
    <p class="ev-org"><?= $owned ? 'Tickets sold on TicketsKenya' : 'Event listing · Tickets not sold here' ?></p>
    <p class="ev-price<?= $unknown ? ' is-unknown' : '' ?>"><?= tk_e($price) ?></p>
    <p class="ev-meta"><span class="ev-ic" aria-hidden="true">&#9719;</span><span><?= tk_e(date('D, M j · g:i A', $ts)) ?></span></p>
    <p class="ev-meta"><span class="ev-ic" aria-hidden="true">&#9906;</span><span class="ev-venue"><?= tk_e(trim($ev['venue'] . ', ' . $ev['city'], ', ')) ?></span></p>
    <a class="ev-btn" href="<?= tk_e($url) ?>" aria-label="<?= $owned ? 'Get tickets' : 'View details' ?> for <?= tk_e($ev['title']) ?>"><?= $owned ? 'View tickets' : 'View event' ?></a>
  </div>
</article>
<?php
}
