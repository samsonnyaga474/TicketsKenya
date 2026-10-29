<?php
/**
 * Event discovery synchronizer (command line).
 *   php tools/sync-discovery.php            run all enabled sources
 *   php tools/sync-discovery.php --self-test parser self-test
 * Exit codes: 0 = finished with no errors, 1 = finished but a source failed or none is enabled,
 *             2 = bad configuration, 3 = another run is already in progress.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/discovery.php';

if (in_array('--self-test', $argv, true)) {
    $fixture = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:parser-test-1\r\nSUMMARY:TicketsKenya Parser Test\r\nDTSTART;TZID=Africa/Nairobi:20271201T180000\r\nDTEND;TZID=Africa/Nairobi:20271201T210000\r\nLOCATION:KICC, Nairobi, Kenya\r\nURL:https://example.org/events/parser-test\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $p = ds_parse_ics($fixture, ['default_city' => 'Kenya', 'default_category' => 'Test']);
    $ok = count($p) === 1 && $p[0]['starts_at'] === '2027-12-01 18:00:00' && $p[0]['ends_at'] === '2027-12-01 21:00:00' && $p[0]['city'] === 'Nairobi' && $p[0]['external_id'] === 'parser-test-1';
    echo ($ok ? 'PASS' : 'FAIL') . " iCalendar parser fixture\n";
    exit($ok ? 0 : 1);
}

$config = json_decode((string)@file_get_contents(dirname(__DIR__) . '/tools/discovery-sources.json'), true);
if (!is_array($config) || !is_array($config['sources'] ?? null)) { fwrite(STDERR, "Invalid tools/discovery-sources.json\n"); exit(2); }
$lock = ds_lock();
if (!$lock) { fwrite(STDERR, "Another sync is already running. Exiting.\n"); exit(3); }
set_time_limit(120);
try { $r = ds_run($config, ['fixtures' => in_array('--fixtures', $argv, true)]); } finally { ds_unlock($lock); }

echo 'TicketsKenya discovery sync finished at ' . $r['finished_at'] . "\n";
foreach ($r['sources'] as $s) printf("  %-28s %-7s found %d | new %d | updated %d | unchanged %d | rejected %d | duplicates %d\n", $s['name'], $s['status'], $s['discovered'], $s['created'], $s['updated'], $s['unchanged'], $s['rejected'], $s['duplicates']);
foreach ($r['errors'] as $e) echo "ERROR: $e\n";
foreach (array_slice($r['warnings'], 0, 10) as $w) echo "WARNING: $w\n";
echo "History: data/discovery-sync-log.json | Changes: data/discovery-changes.json\n";
exit($r['ok'] ? 0 : 1);
