<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Authorization, Config, Repository, Ui};

$owner = tk_activity_admin_guard();
$msg = null; $err = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Authorization::postWithCsrf();
    $in = $_POST;
    // checkbox-style switches: unchecked boxes are not sent, so read them explicitly
    foreach (['tracking_enabled', 'audiences_enabled', 'ip_view_enabled', 'exclude_admins', 'exports_enabled', 'capture_search_terms'] as $k) $in[$k] = isset($_POST[$k]) ? '1' : '0';
    $in['enabled_categories'] = implode(',', array_map('strval', (array)($_POST['enabled_categories'] ?? [])));
    try {
        $changes = Config::save($in, $owner);
        if ($changes) { Repository::audit('settings_changed', $changes, $owner); $msg = 'Settings saved (' . count($changes) . ' changed).'; }
        else $msg = 'Nothing changed.';
    } catch (InvalidArgumentException $e) { $err = $e->getMessage() . ' Nothing was saved.'; }
}
tk_activity_admin_open('settings', 'Settings', 'Owner-only. Every change is written to the audit log. Defaults are the privacy-safe choices.');
echo Ui::flash($msg) . Ui::flash($err, 'error');
$v = fn(string $k) => Ui::e(Config::get($k));
$chk = fn(string $k) => Config::bool($k) ? ' checked' : '';
$sel = fn(string $k, string $val) => Config::get($k) === $val ? ' selected' : '';
$cats = ''; foreach (Config::CATEGORIES as $c) $cats .= '<label><span><input type="checkbox" name="enabled_categories[]" value="' . $c . '"' . (in_array($c, Config::list('enabled_categories'), true) ? ' checked' : '') . '> ' . $c . '</span></label>';
echo '<form class="sa-form" method="post" action=""><input type="hidden" name="_csrf" value="' . Ui::e(tk_csrf_token()) . '">';
echo Ui::panel('Collection', ''
 . '<label><span><input type="checkbox" name="tracking_enabled" value="1"' . $chk('tracking_enabled') . '> Tracking on</span><small>Turn off to stop all optional collection. The website keeps working.</small></label>'
 . '<label>Consent mode<select name="consent_mode"><option value="opt_in"' . $sel('consent_mode', 'opt_in') . '>Opt-in: nothing is stored until the visitor accepts (recommended)</option><option value="notice_only"' . $sel('consent_mode', 'notice_only') . '>Notice only: counts unless the visitor refuses (get legal advice before using)</option></select></label>'
 . '<label>Consent policy version<input name="policy_version" value="' . $v('policy_version') . '" maxlength="20"><small>Change this when your notice changes; everyone is asked again.</small></label>'
 . '<fieldset><legend>Event categories to collect</legend>' . $cats . '</fieldset>'
 . '<label><span><input type="checkbox" name="capture_search_terms" value="1"' . $chk('capture_search_terms') . '> Store what visitors type in search (emails and number-like text removed)</span></label>'
 . '<label><span><input type="checkbox" name="exclude_admins" value="1"' . $chk('exclude_admins') . '> Do not track logged-in admins and organisers</span></label>'
 . '<label>Excluded paths (comma separated)<input name="excluded_paths" value="' . $v('excluded_paths') . '"></label>'
 . '<label>Project id<input name="project_id" value="' . $v('project_id') . '"></label>');
echo Ui::panel('Sessions and limits', ''
 . '<label>Session inactivity timeout (minutes)<input type="number" name="session_timeout_min" value="' . $v('session_timeout_min') . '" min="5" max="240"></label>'
 . '<label>“Active now” window (minutes)<input type="number" name="active_window_min" value="' . $v('active_window_min') . '" min="1" max="60"></label>'
 . '<label>Tracking requests allowed per minute per address<input type="number" name="rate_limit_per_min" value="' . $v('rate_limit_per_min') . '" min="10" max="2000"></label>'
 . '<label>Live page refresh (seconds)<input type="number" name="refresh_seconds" value="' . $v('refresh_seconds') . '" min="5" max="300"></label>');
echo Ui::panel('Network addresses', ''
 . '<label>Storage mode<select name="ip_mode"><option value="none"' . $sel('ip_mode', 'none') . '>Do not store</option><option value="truncated"' . $sel('ip_mode', 'truncated') . '>Shortened (IPv4 /24, IPv6 /48) — default</option><option value="keyed_hash"' . $sel('ip_mode', 'keyed_hash') . '>One-way code (cannot be reversed)</option><option value="full"' . $sel('ip_mode', 'full') . '>Full address (needs a clear reason)</option></select></label>'
 . '<label><span><input type="checkbox" name="ip_view_enabled" value="1"' . $chk('ip_view_enabled') . '> Allow the owner to view stored address values</span><small>Viewing is on a separate page and every view is audited.</small></label>'
 . '<label>Trusted proxies (IPs or CIDR, comma separated)<input name="trusted_proxies" value="' . $v('trusted_proxies') . '"><small>Leave empty unless the site sits behind a proxy you control. Forwarding headers are ignored otherwise.</small></label>'
 . '<label>Country header from a trusted proxy (optional, e.g. CF-IPCountry)<input name="geo_header" value="' . $v('geo_header') . '"></label>');
echo Ui::panel('Retention and conversions', ''
 . '<label>Keep activity events (days)<input type="number" name="retention_events_days" value="' . $v('retention_events_days') . '" min="7" max="1825"></label>'
 . '<label>Keep network address values (days)<input type="number" name="retention_ip_days" value="' . $v('retention_ip_days') . '" min="1" max="365"></label>'
 . '<label>Keep sessions (days)<input type="number" name="retention_sessions_days" value="' . $v('retention_sessions_days') . '" min="30" max="1825"></label>'
 . '<label>Keep audit log (days)<input type="number" name="retention_audit_days" value="' . $v('retention_audit_days') . '" min="90" max="3650"></label>'
 . '<label>Campaign attribution window (days)<input type="number" name="attribution_window_days" value="' . $v('attribution_window_days') . '" min="1" max="180"></label>'
 . '<label>Abandonment window (hours)<input type="number" name="abandonment_window_hours" value="' . $v('abandonment_window_hours') . '" min="1" max="720"></label>'
 . '<label><span><input type="checkbox" name="audiences_enabled" value="1"' . $chk('audiences_enabled') . '> Advertising audiences (marketing-consented browsers only)</span></label>'
 . '<label><span><input type="checkbox" name="exports_enabled" value="1"' . $chk('exports_enabled') . '> Allow CSV exports</span></label>');
echo '<button class="btn btn-lime" type="submit">Save settings</button></form>';
echo '<p class="sa-muted">Stored address values page: <a href="' . Ui::e(Ui::url('ips')) . '">Network addresses</a> (only when enabled above).</p>';
tk_activity_admin_close();
