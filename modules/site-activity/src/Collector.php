<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * Receives a batch of browser events and stores the valid ones.
 * Every field coming from the browser is untrusted: names are checked against the Registry, text is redacted
 * and truncated, paths are re-validated, server-only events are refused, and the visitor/session ids come from
 * cookies set by this server (never from the payload).
 */
final class Collector
{
    public const MAX_BODY = 16384;
    public const MAX_BATCH = 20;

    /**
     * @return array{status:int,stored:int,rejected:int,reason:string}
     */
    public static function handle(string $body, array $server, array $cookies, ?array $user): array
    {
        $res = fn(int $status, int $stored = 0, int $rejected = 0, string $reason = '') => ['status' => $status, 'stored' => $stored, 'rejected' => $rejected, 'reason' => $reason];

        if (!Module::installed()) return $res(204, 0, 0, 'not_installed');
        if (!Config::bool('tracking_enabled')) return $res(204, 0, 0, 'disabled');
        if (strlen($body) === 0 || strlen($body) > self::MAX_BODY) { Repository::logError('payload_size', 'len=' . strlen($body)); \tk_security_log('suspicious_request', $server); return $res(413, 0, 0, 'size'); }

        // Same-origin only: browsers send Origin/Sec-Fetch-Site on fetch/beacon requests.
        $site = strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) { Repository::logError('cross_site', $site); \tk_security_log('suspicious_request', $server); return $res(403, 0, 0, 'cross_site'); }
        $origin = (string)($server['HTTP_ORIGIN'] ?? '');
        if ($origin !== '') {
            $oh = strtolower((string)parse_url($origin, PHP_URL_HOST)); $op = parse_url($origin, PHP_URL_PORT);
            $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
            $oHostPort = $op ? $oh . ':' . $op : $oh;
            if ($oHostPort !== $host && $oh !== preg_replace('/:\d+$/', '', $host)) { Repository::logError('bad_origin', substr($oHostPort, 0, 60)); \tk_security_log('suspicious_request', $server); return $res(403, 0, 0, 'origin'); }
        }

        $consent = Privacy::consentFromCookie($cookies[Privacy::COOKIE_CONSENT] ?? null);
        if (!Privacy::analyticsAllowed($consent)) return $res(204, 0, 0, 'no_consent');

        $ua = (string)($server['HTTP_USER_AGENT'] ?? '');
        $dev = DeviceClassifier::classify($ua);
        if ($dev['bot']) return $res(204, 0, 0, 'bot');

        if ($user && Config::bool('exclude_admins') && in_array($user['role'] ?? '', ['admin', 'organiser'], true)) return $res(204, 0, 0, 'staff_excluded');

        $ip = Privacy::clientIp($server);
        $rlKey = 'ip:' . substr(hash('sha256', (string)($ip['ip'] ?? 'unknown')), 0, 16);
        $limit = Config::int('rate_limit_per_min');
        if (Repository::rateHit($rlKey, 1) > $limit) { Repository::logError('rate_limited', 'limit=' . $limit); \tk_security_log('activity_rate_limited', $server); return $res(429, 0, 0, 'rate'); }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) { Repository::logError('bad_json'); return $res(400, 0, 0, 'json'); }
        $events = array_slice($data['events'], 0, self::MAX_BATCH);
        if (!$events) return $res(204, 0, 0, 'empty');
        if (count($data['events']) > self::MAX_BATCH) Repository::logError('batch_truncated', 'n=' . count($data['events']));

        $isTest = (Config::get('test_token') !== '' && hash_equals(Config::get('test_token'), (string)($server['HTTP_X_ACTIVITY_TEST'] ?? ''))) ? 1 : 0;
        $ctx = is_array($data['ctx'] ?? null) ? $data['ctx'] : [];
        $now = gmdate('Y-m-d H:i:s');
        $project = Config::get('project_id');
        $marketing = $consent['marketing'] ? 1 : 0;

        // ---- visitor and session (ids come only from our own cookies) ----
        $visitor = $cookies[Privacy::COOKIE_VISITOR] ?? null;
        $newVisitor = false;
        if (!Privacy::validId($visitor)) { $visitor = Privacy::newId(); $newVisitor = true; }
        $session = null;
        $sidCookie = $cookies[Privacy::COOKIE_SESSION] ?? null;
        if (Privacy::validId($sidCookie)) {
            $s = Repository::sessionByUid($sidCookie);
            $timeout = Config::int('session_timeout_min') * 60;
            if ($s && $s['visitor_uid'] === $visitor && (strtotime($s['last_seen_at'] . ' UTC') + $timeout) >= time()) $session = $s;
        }

        $stored = 0; $rejected = 0; $pageViews = 0; $lastPath = null;
        $cats = Config::list('enabled_categories');
        $excluded = Config::list('excluded_paths');
        $rows = [];

        foreach ($events as $ev) {
            if (!is_array($ev)) { $rejected++; continue; }
            $name = (string)($ev['n'] ?? '');
            if (!Registry::known($name)) { $rejected++; Repository::logError('unknown_event', substr(preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?? '', 0, 40)); continue; }
            if (!Registry::isClient($name)) { $rejected++; Repository::logError('forged_server_event', $name); \tk_security_log('suspicious_request', $server); continue; }
            $cat = (string)Registry::category($name);
            if (!in_array($cat, $cats, true)) continue; // category switched off by the owner: silently skipped, not an error
            $uid = (string)($ev['id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid)) { $rejected++; Repository::logError('bad_event_id'); continue; }
            $path = Privacy::cleanPath((string)($ev['p'] ?? ''));
            if ($path === null) { $rejected++; Repository::logError('bad_path'); continue; }
            $skip = false;
            foreach ($excluded as $ex) { if ($path === $ex || str_starts_with($path, rtrim($ex, '/') . '/')) { $skip = true; break; } }
            if ($skip) continue;

            $desc = Module::host()->describePath($path);
            $row = [
                'event_uid' => $project . ':' . $uid, 'project' => $project, 'occurred_at' => $now, 'name' => $name, 'category' => $cat,
                'path' => $path, 'path_group' => $desc['path_group'] ?? $path,
                'event_ref_id' => $desc['event_ref_id'] ?? null, 'category_ref' => $desc['category_ref'] ?? null,
                'source' => 'client', 'is_test' => $isTest,
            ];
            $el = (string)($ev['e'] ?? '');
            $row['element_id'] = preg_match('/^[A-Za-z0-9._:\/#-]{1,80}$/', $el) ? $el : null;
            $label = Privacy::redact((string)($ev['l'] ?? ''), 80);
            $row['element_label'] = $label !== '' ? $label : null;
            $meta = [];
            if (is_array($ev['m'] ?? null)) {
                foreach ($ev['m'] as $k => $v) {
                    if (!in_array($k, Registry::META_KEYS, true) || !is_scalar($v)) continue;
                    $meta[$k] = Privacy::redact((string)$v, 60);
                }
            }

            switch ($name) {
                case 'page_view': break;
                case 'event_card_clicked': case 'navigation_click': case 'link_click':
                    $t = Privacy::cleanPath((string)($ev['t'] ?? ''));
                    if ($t !== null) {
                        $row['target_path'] = $t;
                        if ($name === 'event_card_clicked') {
                            $td = Module::host()->describePath($t);
                            $row['event_ref_id'] = $td['event_ref_id'] ?? null;
                            $row['category_ref'] = $td['category_ref'] ?? null;
                        }
                    }
                    break;
                case 'outbound_link_clicked':
                    $h = strtolower((string)($ev['t'] ?? ''));
                    $row['target_path'] = preg_match('/^[a-z0-9.-]{1,100}$/', $h) ? $h : null;
                    break;
                case 'search_submitted':
                    if (Config::bool('capture_search_terms')) {
                        $q = Privacy::redact((string)($ev['q'] ?? ''), 80);
                        $row['query_text'] = $q !== '' ? $q : null;
                    }
                    break;
                case 'filter_changed':
                    $field = (string)($meta['field'] ?? '');
                    if (!in_array($field, ['category', 'city', 'when', 'price', 'sort', 'from', 'to'], true)) { $rejected++; continue 2; }
                    $row['element_id'] = $row['element_id'] ?: 'filter-' . $field;
                    if ($field === 'category') $row['category_ref'] = Privacy::redact((string)($meta['value'] ?? ''), 60) ?: null;
                    break;
                case 'ticket_quantity_changed':
                    $row['value_num'] = max(0, min(100, (int)($meta['qty'] ?? 0)));
                    unset($meta['value']);
                    break;
                case 'js_error':
                    $row['element_label'] = Privacy::redact((string)($meta['msg'] ?? ''), 80) ?: null;
                    break;
                case 'login_attempted': case 'registration_attempted': case 'contact_submitted':
                    $meta = []; $row['element_label'] = null; // never keep anything from account forms
                    break;
            }
            if (in_array($name, ['page_view', 'search_submitted'], true) || $meta) $row['meta_json'] = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
            $row['_ref'] = is_string($ev['r'] ?? null) ? $ev['r'] : '';
            $rows[] = $row;
            $lastPath = $path;
        }

        if (!$rows) return $res(204, 0, $rejected, 'nothing_to_store');

        // ---- create the session lazily, from the first valid event ----
        if (!$session) {
            $first = $rows[0];
            $camp = self::campaignFrom($ctx);
            $ref = self::referrer((string)($ctx['ref'] ?? ''), (string)($server['HTTP_HOST'] ?? ''), $camp);
            [$ipVal, $ipKind] = Privacy::ipForStorage($ip['public'] ? $ip['ip'] : null);
            if (!$ip['public'] && $ip['ip'] !== null && Config::get('ip_mode') !== 'none') { [$ipVal, $ipKind] = Privacy::ipForStorage($ip['ip']); }
            $country = null;
            $geoHeader = Config::get('geo_header');
            if ($geoHeader !== '' && $ip['via_proxy']) {
                $c = strtoupper((string)($server['HTTP_' . strtoupper(str_replace('-', '_', $geoHeader))] ?? ''));
                $country = preg_match('/^[A-Z]{2}$/', $c) ? $c : null;
            }
            $sid = Privacy::newId();
            $id = Repository::createSession([
                'session_uid' => $sid, 'visitor_uid' => $visitor, 'project' => $project, 'started_at' => $now, 'last_seen_at' => $now,
                'landing_path' => $first['path'], 'last_path' => $first['path'], 'referrer_host' => $ref['host'], 'referrer_type' => $ref['type'],
                'utm_source' => $camp['utm_source'], 'utm_medium' => $camp['utm_medium'], 'utm_campaign' => $camp['utm_campaign'],
                'utm_content' => $camp['utm_content'], 'utm_term' => $camp['utm_term'],
                'device_category' => $dev['category'], 'browser' => $dev['browser'], 'browser_version' => $dev['browser_version'], 'os' => $dev['os'],
                'screen_cat' => DeviceClassifier::screenCategory($ctx['sc'] ?? null), 'language' => self::lang((string)($ctx['lang'] ?? '')),
                'ip_value' => $ipVal, 'ip_kind' => $ipKind, 'ip_public' => $ip['public'] ? 1 : 0, 'country' => $country,
                'user_id' => $user['id'] ?? null, 'consent_analytics' => 1, 'consent_marketing' => $marketing,
                'policy_version' => Config::get('policy_version'), 'is_test' => $isTest,
            ]);
            $session = ['id' => $id, 'session_uid' => $sid];
        }

        foreach ($rows as $row) {
            unset($row['_ref']);
            $row['session_id'] = (int)$session['id'];
            $row['visitor_uid'] = $visitor;
            if (Repository::insertEvent($row) !== null) {
                $stored++;
                if ($row['name'] === 'page_view') $pageViews++; // only stored views count, so retries never inflate a session
                // The server itself derives "event viewed" from a page view of a known event page.
                if ($row['name'] === 'page_view' && !empty($row['event_ref_id']) && in_array('event', $cats, true)) {
                    $v = $row;
                    $v['event_uid'] = $row['event_uid'] . ':ev'; $v['name'] = 'event_viewed'; $v['category'] = 'event'; $v['source'] = 'server';
                    $v['element_id'] = null; $v['element_label'] = null; $v['meta_json'] = null;
                    Repository::insertEvent($v);
                }
            }
        }
        Repository::touchSession((int)$session['id'], $now, $lastPath, $pageViews, $stored, $user['id'] ?? null, $marketing);

        // Cookies: only now, after consent and a stored event.
        $exp = time() + 86400 * 180;
        Privacy::setCookie(Privacy::COOKIE_VISITOR, $visitor, $exp, true, $server);
        Privacy::setCookie(Privacy::COOKIE_SESSION, (string)($session['session_uid'] ?? $sidCookie), 0, true, $server);

        return $res(204, $stored, $rejected, 'ok');
    }

    // ---------- helpers ----------

    /** Validates and normalises utm_* values. Malformed input becomes null, long input is cut. */
    public static function campaignFrom(array $ctx): array
    {
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
            $v = strtolower(trim((string)($ctx[$k] ?? '')));
            $v = preg_replace('/[^a-z0-9._\- ]/', '', $v) ?? '';
            $v = substr(trim($v), 0, 40);
            $out[$k] = $v !== '' ? $v : null;
        }
        return $out;
    }

    /** Referrer reduced to a host and a coarse type. Query strings and paths never leave the browser's referrer. */
    public static function referrer(string $ref, string $ownHost, array $camp): array
    {
        $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? '';
        if (!preg_match('/^[a-z0-9.-]{1,100}$/', $host)) $host = '';
        $own = preg_replace('/^www\.|:\d+$/', '', strtolower($ownHost)) ?? '';
        if ($camp['utm_medium'] && in_array($camp['utm_medium'], ['cpc', 'ppc', 'paid', 'paidsocial', 'display'], true)) return ['host' => $host ?: null, 'type' => 'paid'];
        if ($camp['utm_medium'] === 'email') return ['host' => $host ?: null, 'type' => 'email'];
        if ($host === '' ) return ['host' => null, 'type' => $camp['utm_source'] ? 'campaign' : 'direct'];
        if ($host === $own) return ['host' => $host, 'type' => 'internal'];
        if (preg_match('/(^|\.)(google|bing|duckduckgo|yahoo|yandex|baidu|ecosia)\./', $host)) return ['host' => $host, 'type' => 'search'];
        if (preg_match('/(^|\.)(facebook|instagram|twitter|x|t|linkedin|tiktok|youtube|whatsapp|reddit|pinterest)\.(com|co|me)$|^t\.co$|^l\.facebook\.com$|^lm\.facebook\.com$/', $host)) return ['host' => $host, 'type' => 'social'];
        return ['host' => $host, 'type' => 'referral'];
    }

    private static function lang(string $l): ?string
    {
        $l = strtolower(substr($l, 0, 10));
        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,4})?$/', $l) ? $l : null;
    }
}
