<?php
/**
 * Event discovery library: permitted-source adapters (iCalendar, RSS, Atom), validation, de-duplication,
 * change tracking and a cautious missing-event policy. Used by tools/sync-discovery.php (CLI and Task Scheduler)
 * and by the admin "Sync now" button. No scraping, no paid services.
 */
declare(strict_types=1);

const DS_MISSED_RUNS_BEFORE_REVIEW = 3;   // successful checks without the record before it is flagged for human review
const DS_MAX_FEED_BYTES = 5 * 1024 * 1024;

/** Refuse non-HTTPS URLs and hosts that resolve to private or reserved addresses (SSRF guard). */
function ds_assert_public_https(string $url): void {
    $parts = parse_url($url);
    if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) throw new RuntimeException('Only HTTPS feed URLs are permitted.');
    $host = (string)$parts['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) throw new RuntimeException('Feed host could not be resolved.');
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Feed host resolves to a private or reserved address and was refused.');
}

function ds_http_get(string $url): string {
    ds_assert_public_https($url);
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) !== true) throw new RuntimeException('PHP allow_url_fopen is disabled; enable it in php.ini and restart.');
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "User-Agent: TicketsKenya-EventFeed/1.0\r\nAccept: application/rss+xml, application/atom+xml, text/calendar, application/xml, text/xml\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $ctx, 0, DS_MAX_FEED_BYTES + 1);
    if ($body === false) throw new RuntimeException('Could not download feed (network, DNS or TLS problem, or the feed is temporarily unavailable).');
    $status = 0;
    foreach (($http_response_header ?? []) as $h) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $m)) $status = (int)$m[1];
    if ($status < 200 || $status >= 300) throw new RuntimeException('Feed returned HTTP ' . $status . '.');
    if (strlen($body) > DS_MAX_FEED_BYTES) throw new RuntimeException('Feed exceeds the 5 MB safety limit.');
    return $body;
}

/** Test hook: a source may read a local file only when the caller passes $opts['fixtures'] (never from config alone). */
function ds_fetch(array $source, array $opts): string {
    if (!empty($opts['fixtures']) && !empty($source['fixture_file'])) {
        $body = @file_get_contents((string)$source['fixture_file']);
        if ($body === false) throw new RuntimeException('Could not download feed (fixture missing).');
        return $body;
    }
    return ds_http_get((string)$source['url']);
}

function ds_tag(string $xml, string $tag): string {
    if (preg_match('~<(?:[\w.-]+:)?'.preg_quote($tag,'~').'\b[^>]*>(.*?)</(?:[\w.-]+:)?'.preg_quote($tag,'~').'\s*>~is', $xml, $m)) {
        $v = preg_replace('~<!\[CDATA\[(.*?)\]\]>~s', '$1', $m[1]) ?? $m[1];
        return trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($tag === 'content' && preg_match('~<content:encoded\b[^>]*>(.*?)</content:encoded\s*>~is', $xml, $m)) {
        $v = preg_replace('~<!\[CDATA\[(.*?)\]\]>~s', '$1', $m[1]) ?? $m[1];
        return trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}
function ds_parse_date(string $text): ?string {
    $text = trim(str_replace(['·', '|', '—'], ' ', $text));
    if ($text === '') return null;
    // Common event-feed date formats; don't substitute feed publication dates for event dates.
    $patterns = [
        '/\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+\d{4}(?:\s+\d{1,2}:\d{2}\s*(?:am|pm)?(?:\s+[A-Z]{2,5})?)?/i',
        '/\b\d{1,2}(?:st|nd|rd|th)?\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*(?:\s+\d{4})?(?:\s+\d{1,2}(?::\d{2})?\s*(?:am|pm))?/i',
        '/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?(?:\s+\d{1,2}(?::\d{2})?\s*(?:am|pm))?/i',
        '/\b\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?/'
    ];
    foreach ($patterns as $p) if (preg_match($p, $text, $m)) { $candidate = $m[0]; if (!preg_match('/\b20\d{2}\b/', $candidate) && preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\b/i', $candidate)) $candidate .= ' ' . date('Y'); $ts = strtotime($candidate); if ($ts !== false) return date('Y-m-d H:i:s', $ts); }
    return null;
}
function ds_parse_ics(string $body, array $source): array {
    // Unfold iCalendar continuation lines and parse each VEVENT independently.
    $body = preg_replace("/\r?\n[ \t]/", '', $body) ?? $body;
    $body = str_replace("\r", '', $body);
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $body, $matches);
    $out = [];
    foreach ($matches[1] as $event) {
        $get = function(string $key) use ($event): string {
            if (preg_match('/^'.$key.'(?:;[^:]*)?:(.*)$/mi', $event, $m)) return trim(str_replace('\\n', ' ', str_replace(['\\,','\\;','\\\\'], [',',';','\\'], $m[1])));
            return '';
        };
        $dateValue = function(string $key) use ($event): ?string {
            if (!preg_match('/^'.$key.'((?:;[^:]*)?):([^\r\n]+)$/mi', $event, $m)) return null;
            $params = $m[1]; $raw = trim($m[2]);
            $tz = new DateTimeZone(date_default_timezone_get());
            if (preg_match('/TZID=([^;:]+)/i', $params, $tzm)) { try { $tz = new DateTimeZone(trim($tzm[1], '"')); } catch (Throwable $e) {} }
            if (preg_match('/^\d{8}$/', $raw)) $format = 'Ymd';
            elseif (preg_match('/^\d{8}T\d{6}Z$/', $raw)) { $format = 'Ymd\\THis\\Z'; $tz = new DateTimeZone('UTC'); }
            elseif (preg_match('/^\d{8}T\d{6}$/', $raw)) $format = 'Ymd\\THis';
            else return null;
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $raw, $tz);
            return $dt ? $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s') : null;
        };
        $title = $get('SUMMARY'); $url = $get('URL'); $dt = $dateValue('DTSTART'); $end = $dateValue('DTEND');
        if ($title === '' || !$dt || strtotime($dt) < strtotime('today')) continue;
        $venue = $get('LOCATION') ?: 'Venue to be confirmed';
        $city = (string)($source['default_city'] ?? 'Kenya');
        foreach (['Nairobi','Mombasa','Kisumu','Nakuru','Eldoret','Naivasha','Thika','Malindi','Kisii','Nyeri','Diani','Karen','Westlands','Kakamega','Meru','Nanyuki'] as $candidateCity) {
            if (stripos($venue, $candidateCity) !== false) { $city = $candidateCity; break; }
        }
        $status = stripos($event, 'STATUS:CANCELLED') !== false ? 'cancelled' : 'published';
        $out[] = ['title'=>$title,'description'=>$get('DESCRIPTION'),'venue'=>$venue,'city'=>$city,'starts_at'=>$dt,'ends_at'=>$end,'source_url'=>$url ?: ($source['terms_url'] ?? $source['url']),'official_ticket_url'=>$url ?: ($source['terms_url'] ?? $source['url']),'category'=>$get('CATEGORIES') ?: ($source['default_category'] ?? 'Events'),'status'=>$status,'status_explicit'=>preg_match('/^STATUS:/mi',$event)===1,'external_id'=>$get('UID')];
    }
    return $out;
}
function ds_parse_feed(string $body, array $source): array {
    if (($source['format'] ?? '') === 'ics' || stripos($body, 'BEGIN:VCALENDAR') !== false) return ds_parse_ics($body, $source);
    preg_match_all('~<(item|entry)\b[^>]*>(.*?)</\1\s*>~is', $body, $items);
    $out = [];
    foreach ($items[2] as $item) {
        $title = ds_tag($item, 'title');
        $link = ds_tag($item, 'link');
        if ($link === '' && preg_match('~<link\b[^>]*href=["\']([^"\']+)~i', $item, $lm)) $link = html_entity_decode($lm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = ds_tag($item, 'description');
        if ($description === '') $description = ds_tag($item, 'summary');
        if ($description === '') $description = ds_tag($item, 'content');
        if ($title === '' || $link === '' || !preg_match('~^https://~i', $link)) continue;
        $date = ds_parse_date($title . ' ' . $description);
        if (!$date || strtotime($date) < strtotime('today')) continue;
        $venue = 'Venue to be confirmed';
        if (preg_match('/\b(?:at|venue:)\s+([^\n,.;]{3,90})/i', $description, $vm)) $venue = trim($vm[1]);
        $sourceStatus = ds_tag($item, 'status');
        $statusExplicit = $sourceStatus !== '' || preg_match('/\b(cancelled|canceled)\b/i', $title) === 1;
        $status = (preg_match('/\b(cancelled|canceled)\b/i', $sourceStatus) || preg_match('/\b(cancelled|canceled)\b/i', $title)) ? 'cancelled' : 'published';
        $out[] = ['status_explicit'=>$statusExplicit,'title'=>$title,'description'=>function_exists('mb_substr') ? mb_substr($description,0,2000) : substr($description,0,2000),'venue'=>$venue,'city'=>$source['default_city'] ?? 'Kenya','starts_at'=>$date,'ends_at'=>null,'source_url'=>$link,'official_ticket_url'=>$link,'category'=>$source['default_category'] ?? 'Events & Entertainment','status'=>$status,'external_id'=>$link];
    }
    return $out;
}

function ds_norm(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)) ?? ''); }
function ds_fuzzy_key(array $e): string { return sha1(ds_norm((string)$e['title']) . '|' . ds_norm((string)$e['venue']) . '|' . substr((string)$e['starts_at'], 0, 10)); }
function ds_slug(string $title, string $key): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
    return substr($slug !== '' ? $slug : 'event', 0, 65) . '-' . substr(hash('sha256', $key), 0, 8);
}

/** Validate and clean one parsed record. Returns [record|null, warnings[]]. Null = rejected. */
function ds_validate(array $e): array {
    $warn = [];
    $title = trim((string)($e['title'] ?? ''));
    if ($title === '' || strlen($title) > 200) return [null, ['missing or oversized title']];
    if (empty($e['starts_at']) || strtotime((string)$e['starts_at']) === false) return [null, ['missing or invalid date']];
    $url = trim((string)($e['official_ticket_url'] ?? ''));
    $src = trim((string)($e['source_url'] ?? ''));
    $okUrl = fn($u) => $u !== '' && preg_match('#^https://[^\s<>"\']+$#i', $u) && filter_var($u, FILTER_VALIDATE_URL);
    if (!$okUrl($src)) return [null, ['missing or invalid source URL']];
    if (!$okUrl($url)) { $url = ''; $warn[] = 'invalid ticket URL dropped'; }
    $venue = trim((string)($e['venue'] ?? ''));
    $unverified = false;
    if ($venue === '' || $venue === 'Venue to be confirmed') { $venue = 'Venue to be confirmed'; $unverified = true; $warn[] = 'venue not confirmed'; }
    $e['title'] = $title; $e['venue'] = substr($venue, 0, 200); $e['source_url'] = $src; $e['official_ticket_url'] = $url;
    $e['description'] = substr(trim((string)($e['description'] ?? '')), 0, 2000);
    $e['needs_review'] = ($unverified || $url === '') ? 1 : 0;
    $e['review_reason'] = implode('; ', $warn);
    return [$e, $warn];
}

function ds_state_path(): string { return TK_DATA . '/discovery-changes.json'; }
function ds_log_path(): string { return TK_DATA . '/discovery-sync-log.json'; }
function ds_read_json(string $p): array { $a = json_decode((string)@file_get_contents($p), true); return is_array($a) ? $a : []; }
function ds_write_json(string $p, array $a): void { if (!is_dir(dirname($p))) mkdir(dirname($p), 0750, true); file_put_contents($p, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX); }

/**
 * Run one synchronisation over all enabled sources.
 * $opts: fixtures(bool, tests only), now(timestamp)
 * Returns a result array (also appended to the sync log); changes are appended to the change log.
 */
function ds_run(array $config, array $opts = []): array {
    $started = date('c');
    $res = ['started_at' => $started, 'sources' => [], 'errors' => [], 'warnings' => [], 'changes' => [], 'ok' => true];
    $enabled = array_values(array_filter($config['sources'] ?? [], fn($s) => !empty($s['enabled'])));
    if (!$enabled) { $res['errors'][] = 'No event feed is enabled. Enable a public feed whose terms permit republication, or get written permission first.'; $res['ok'] = false; }

    foreach ($enabled as $source) {
        $sid = (string)($source['id'] ?? $source['name'] ?? 'source');
        $st = ['id' => $sid, 'name' => $source['name'] ?? $sid, 'status' => 'ok', 'discovered' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'rejected' => 0, 'duplicates' => 0, 'checked_at' => date('c'), 'attribution' => $source['attribution'] ?? ($source['name'] ?? $sid)];
        try {
            $body = ds_fetch($source, $opts);
            $parsed = ds_parse_feed($body, $source);
        } catch (Throwable $e) {
            $st['status'] = 'failed'; $st['error'] = $e->getMessage();
            $res['errors'][] = $st['name'] . ': ' . $e->getMessage(); $res['ok'] = false; $res['sources'][] = $st; continue; // a failed fetch never changes or removes any event
        }
        $isFeed = stripos($body, 'BEGIN:VCALENDAR') !== false || preg_match('~<(rss|feed|rdf:RDF)\b~i', $body) === 1;
        if (!$isFeed) { $st['status'] = 'failed'; $st['error'] = 'Response is not a valid iCalendar, RSS or Atom document.'; $res['errors'][] = $st['name'] . ': ' . $st['error']; $res['ok'] = false; $res['sources'][] = $st; continue; }
        if (!$parsed) { $st['status'] = 'empty'; $res['warnings'][] = $st['name'] . ': feed is valid but has no upcoming events with a parseable date; nothing was invented and nothing was removed.'; $res['sources'][] = $st; continue; }

        $all = tk_repo_all('events');
        $seenIds = [];
        foreach ($parsed as $raw) {
            $st['discovered']++;
            [$ev, $warn] = ds_validate($raw);
            if (!$ev) { $st['rejected']++; $res['warnings'][] = $st['name'] . ': record rejected (' . implode(', ', $warn) . ')'; continue; }
            $ext = trim((string)($raw['external_id'] ?? '')) ?: $ev['source_url'];
            $fuzzy = ds_fuzzy_key($ev);
            $match = null; $foreign = null;
            foreach ($all as $c) {
                if (($c['listing_type'] ?? '') === 'owned') { if (ds_fuzzy_key($c) === $fuzzy) $foreign = $c; continue; }
                $mine = (string)($c['source_id'] ?? '') === $sid;
                if ($mine && (string)($c['external_id'] ?? '') === $ext) { $match = $c; break; }
                if ($mine && rtrim((string)($c['source_url'] ?? ''), '/') === rtrim($ev['source_url'], '/') && (string)($c['external_id'] ?? '') === '') { $match = $c; break; }
                if (!$mine && (rtrim((string)($c['source_url'] ?? ''), '/') === rtrim($ev['source_url'], '/') || ds_fuzzy_key($c) === $fuzzy)) $foreign = $c;
            }
            if (!$match && $foreign) { $st['duplicates']++; continue; }  // same event already held by another source or by a manual/owned listing: never overwrite it
            $fields = ['title' => $ev['title'], 'description' => $ev['description'], 'venue' => $ev['venue'], 'city' => trim((string)$ev['city']) ?: 'Kenya', 'starts_at' => $ev['starts_at'], 'ends_at' => $ev['ends_at'] ?: null,
                'status' => $ev['status'], 'listing_type' => 'discovery', 'source_url' => $ev['source_url'], 'official_ticket_url' => $ev['official_ticket_url'] ?: null, 'category' => $ev['category'],
                'source_id' => $sid, 'external_id' => $ext, 'missed_runs' => 0, 'needs_review' => $ev['needs_review'], 'review_reason' => $ev['review_reason'] ?: null,
                'verified_at' => date('Y-m-d'), 'updated_at' => date('c')];
            if ($match) {
                $seenIds[(int)$match['id']] = true;
                if (($match['status'] ?? '') === 'cancelled' && empty($ev['status_explicit'])) $fields['status'] = 'cancelled'; // never silently revive
                $diff = [];
                foreach (['title', 'starts_at', 'ends_at', 'venue', 'city', 'status', 'official_ticket_url'] as $k) {
                    if ((string)($match[$k] ?? '') !== (string)($fields[$k] ?? '')) $diff[$k] = ['old' => $match[$k] ?? null, 'new' => $fields[$k] ?? null];
                }
                if ($diff) {
                    tk_repo_update('events', (int)$match['id'], $fields); $st['updated']++;
                    foreach ($diff as $k => $v) $res['changes'][] = ['at' => date('c'), 'event_id' => (int)$match['id'], 'title' => $fields['title'], 'type' => ($k === 'status' && $v['new'] === 'cancelled') ? 'cancelled' : $k . '_changed', 'old' => $v['old'], 'new' => $v['new'], 'source' => $st['name']];
                } else { tk_repo_update('events', (int)$match['id'], ['verified_at' => date('Y-m-d'), 'missed_runs' => 0, 'updated_at' => date('c')]); $st['unchanged']++; }
            } else {
                $slug = tk_unique_slug(ds_slug($ev['title'], $sid . '|' . $ext));
                $fields += ['slug' => $slug, 'cover_image' => null, 'is_demo' => 0, 'organiser_id' => null, 'created_at' => date('c')];
                $id = tk_repo_insert('events', $fields); $seenIds[$id] = true; $st['created']++;
                $res['changes'][] = ['at' => date('c'), 'event_id' => $id, 'title' => $fields['title'], 'type' => 'new', 'old' => null, 'new' => $fields['starts_at'], 'source' => $st['name']];
                $all[] = $fields + ['id' => $id];
            }
        }
        // Cautious missing-event policy: only after a successful, non-empty check, and only ever flag for review.
        foreach (tk_repo_all('events') as $c) {
            if ((string)($c['source_id'] ?? '') !== $sid || isset($seenIds[(int)$c['id']]) || !tk_event_is_upcoming($c)) continue;
            $n = (int)($c['missed_runs'] ?? 0) + 1;
            $upd = ['missed_runs' => $n];
            if ($n === DS_MISSED_RUNS_BEFORE_REVIEW) {
                $upd += ['needs_review' => 1, 'review_reason' => 'No longer returned by the source for ' . $n . ' successful checks'];
                $res['changes'][] = ['at' => date('c'), 'event_id' => (int)$c['id'], 'title' => $c['title'], 'type' => 'removed_from_source', 'old' => 'listed', 'new' => 'flagged for review (still published)', 'source' => $st['name']];
            }
            tk_repo_update('events', (int)$c['id'], $upd);
        }
        $res['sources'][] = $st;
    }
    $res['finished_at'] = date('c');
    $log = ds_read_json(ds_log_path()); array_unshift($log, $res); ds_write_json(ds_log_path(), array_slice($log, 0, 100));
    if ($res['changes']) { $ch = array_merge($res['changes'], ds_read_json(ds_state_path())); ds_write_json(ds_state_path(), array_slice($ch, 0, 1000)); }
    return $res;
}

/** Exclusive non-blocking lock so two runs (Task Scheduler + "Sync now") never overlap. Returns a handle or null. */
function ds_lock() {
    if (!is_dir(TK_DATA)) mkdir(TK_DATA, 0750, true);
    $h = fopen(TK_DATA . '/discovery.lock', 'c');
    if ($h && flock($h, LOCK_EX | LOCK_NB)) return $h;
    if ($h) fclose($h);
    return null;
}
function ds_unlock($h): void { if ($h) { flock($h, LOCK_UN); fclose($h); } }
