<?php
/** Owner-only JSON for the Live page. Light queries only (active sessions + latest events). */
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Config, Ui};

tk_activity_admin_guard(true);
Analytics::$includeTest = (($_GET['include_test'] ?? '') === '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$after = max(0, (int)($_GET['after'] ?? 0));
$sessions = array_map(fn($s) => [
    'visitor' => substr($s['visitor_uid'], 0, 8), 'started' => Ui::local($s['started_at'], 'H:i'), 'last_seen' => Ui::local($s['last_seen_at'], 'H:i:s'),
    'page' => $s['last_path'], 'source' => $s['utm_source'] ?: ($s['referrer_host'] ?: $s['referrer_type']), 'device' => $s['device_category'],
    'browser' => trim($s['browser'] . ' ' . $s['browser_version']), 'os' => $s['os'], 'screen' => $s['screen_cat'], 'country' => $s['country'],
    'logged_in' => $s['user_id'] ? 'yes' : 'no', 'actions' => (int)$s['event_count'],
], Analytics::activeSessions(50));
$events = array_map(fn($e) => [
    'id' => (int)$e['id'], 'time' => Ui::local($e['occurred_at'], 'H:i:s'), 'name' => $e['name'], 'page' => $e['path'] ?: $e['path_group'],
    'element' => $e['element_id'], 'device' => $e['device_category'], 'visitor' => $e['visitor_short'],
], Analytics::recentEvents(30, $after));
echo json_encode(['ok' => true, 'active' => Analytics::activeCount(), 'window_min' => Config::int('active_window_min'), 'sessions' => $sessions, 'events' => $events]);
