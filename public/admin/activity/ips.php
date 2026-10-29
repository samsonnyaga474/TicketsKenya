<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Config, Module, Repository, Ui};

$owner = tk_activity_admin_guard();
if (!Config::bool('ip_view_enabled')) { http_response_code(403); tk_activity_admin_open('settings', 'Network addresses', 'Viewing is switched off.'); echo Ui::emptyState('Viewing stored address values is switched off in Settings.'); tk_activity_admin_close(); exit; }
Repository::audit('ip_view', ['page' => 1], $owner);
$rows = Module::requirePdo()->query('SELECT started_at, substr(visitor_uid,1,8) AS v, ip_value, ip_kind, ip_public, country, device_category FROM activity_sessions WHERE ip_value IS NOT NULL ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
tk_activity_admin_open('settings', 'Network addresses', 'Restricted view. A shared network, VPN or mobile carrier can make many people look like one address, and an address is not a home location.');
echo Ui::table(['Started', 'Browser id', 'Stored value', 'Kind', 'Public address', 'Country (from trusted proxy)', 'Device'],
  array_map(fn($r) => [Ui::local($r['started_at'], 'j M H:i'), $r['v'], $r['ip_value'], $r['ip_kind'], $r['ip_public'] ? 'yes' : 'no (private/reserved)', $r['country'], $r['device_category']], $rows));
tk_activity_admin_close();
