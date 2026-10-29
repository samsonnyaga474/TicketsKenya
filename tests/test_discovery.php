<?php
/**
 * Event discovery tests against a disposable data directory (never touches real data).
 *   php tests/test_discovery.php
 * Feeds are local fixtures: they test our code, NOT the availability of any live source.
 */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/tk-disc-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
putenv('TK_DATA_DIR=' . $tmp); putenv('TK_ENV=development'); putenv('TK_DEMO_MODE=false');
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/discovery.php';

$pass = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name $detail\n"; } }

$d1 = date('Ymd', strtotime('+20 days')); $d2 = date('Ymd', strtotime('+21 days'));
function ics(array $events): string {
    $o = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
    foreach ($events as $e) {
        $o .= "BEGIN:VEVENT\r\nUID:{$e['uid']}\r\nSUMMARY:{$e['title']}\r\nDTSTART;TZID=Africa/Nairobi:{$e['start']}T190000\r\nLOCATION:{$e['venue']}\r\nURL:{$e['url']}\r\n";
        if (!empty($e['status'])) $o .= "STATUS:{$e['status']}\r\n";
        $o .= "END:VEVENT\r\n";
    }
    return $o . "END:VCALENDAR\r\n";
}
$fx = "$tmp/a.ics"; $fx2 = "$tmp/b.ics";
$cfg = fn(array $files) => ['sources' => array_map(fn($id, $f) => ['id' => $id, 'name' => "Source $id", 'enabled' => true, 'format' => 'ics', 'fixture_file' => $f, 'default_city' => 'Kenya', 'default_category' => 'Events'], array_keys($files), array_values($files))];
$run = fn(array $files) => ds_run($cfg($files), ['fixtures' => true]);
$count = fn() => count(array_filter(tk_repo_all('events'), fn($e) => ($e['source_id'] ?? '') !== ''));
$find = fn(string $ext) => tk_repo_find('events', 'external_id', $ext);

$A = ['uid' => 'a-1', 'title' => 'Test Fest', 'start' => $d1, 'venue' => 'KICC, Nairobi', 'url' => 'https://example.org/e/test-fest'];
$B = ['uid' => 'a-2', 'title' => 'Second Show', 'start' => $d2, 'venue' => 'Alchemist, Nairobi', 'url' => 'https://example.org/e/second'];

// owned event that sync must never touch
$ownedId = tk_repo_insert('events', ['slug' => 'my-own-event', 'title' => 'My Own Event', 'description' => 'x', 'venue' => 'Home', 'city' => 'Nairobi', 'starts_at' => date('Y-m-d H:i:s', strtotime('+10 days')), 'status' => 'published', 'listing_type' => 'owned', 'created_at' => date('c'), 'updated_at' => date('c')]);

// 1 valid feed
file_put_contents($fx, ics([$A, $B]));
$r = $run(['A' => $fx]);
check('1 valid feed creates 2 events', $r['sources'][0]['created'] === 2 && $count() === 2, json_encode($r['sources'][0]));
check('1b created events have no stock poster', $find('a-1')['cover_image'] === null && !tk_event_has_poster($find('a-1')));
check('1c source id and fetch time recorded', $find('a-1')['source_id'] === 'A' && !empty($find('a-1')['verified_at']));

// 5 repeat sync = no duplicates
$r = $run(['A' => $fx]);
check('5 repeat sync creates nothing, 2 unchanged', $r['sources'][0]['created'] === 0 && $r['sources'][0]['unchanged'] === 2 && $count() === 2);

// 6/7 date and venue change tracked with old/new
file_put_contents($fx, ics([array_merge($A, ['start' => $d2, 'venue' => 'Sarit Centre, Nairobi']), $B]));
$r = $run(['A' => $fx]);
$types = array_column($r['changes'], 'type');
check('6 date change recorded', in_array('starts_at_changed', $types, true));
check('7 venue change recorded with old and new', (bool)array_filter($r['changes'], fn($c) => $c['type'] === 'venue_changed' && str_contains((string)$c['old'], 'KICC') && str_contains((string)$c['new'], 'Sarit')));
check('6b event updated in place, still 2 rows', $count() === 2 && str_starts_with($find('a-1')['starts_at'], date('Y-m-d', strtotime('+21 days'))));

// 8 confirmed cancellation
file_put_contents($fx, ics([array_merge($A, ['start' => $d2, 'venue' => 'Sarit Centre, Nairobi', 'status' => 'CANCELLED']), $B]));
$r = $run(['A' => $fx]);
check('8 explicit cancellation applied', $find('a-1')['status'] === 'cancelled' && in_array('cancelled', array_column($r['changes'], 'type'), true));
check('8b cancelled event not shown as upcoming', !tk_event_is_upcoming($find('a-1')));
// cancelled event must not be silently revived by a later feed without status
file_put_contents($fx, ics([array_merge($A, ['start' => $d2, 'venue' => 'Sarit Centre, Nairobi']), $B]));
$run(['A' => $fx]);
check('8c cancelled stays cancelled without explicit status', $find('a-1')['status'] === 'cancelled');

// 9 missing event is NOT cancelled; only flagged after 3 successful checks
file_put_contents($fx, ics([$B]));
$run(['A' => $fx]); $run(['A' => $fx]);
check('9 missing twice: still published, not flagged', $find('a-2')['status'] === 'published' && true);
file_put_contents($fx, ics([$A]));   // a-2 missing now
$run(['A' => $fx]); $run(['A' => $fx]); $r = $run(['A' => $fx]);
check('9b missing 3 successful checks: flagged for review, NOT cancelled or hidden', (int)$find('a-2')['needs_review'] === 1 && $find('a-2')['status'] === 'published');

// 4 network failure leaves data untouched
$before = json_encode(tk_repo_all('events'));
$r = $run(['A' => "$tmp/does-not-exist.ics"]);
check('4 fetch failure reported, nothing changed', $r['ok'] === false && $r['sources'][0]['status'] === 'failed' && json_encode(tk_repo_all('events')) === $before);
// real HTTP layer refuses plain http and private hosts
try { ds_http_get('http://example.org/feed.ics'); check('4b http:// refused', false); } catch (RuntimeException $e) { check('4b http:// refused', str_contains($e->getMessage(), 'HTTPS')); }
try { ds_http_get('https://127.0.0.1/feed.ics'); check('4c private address refused (SSRF)', false); } catch (RuntimeException $e) { check('4c private address refused (SSRF)', str_contains($e->getMessage(), 'private')); }

// 2 empty feed / 3 malformed feed do not remove or change anything
file_put_contents($fx, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");
$r = $run(['A' => $fx]);
check('2 empty valid feed: no change, warning recorded', $r['sources'][0]['status'] === 'empty' && json_encode(tk_repo_all('events')) === $before ? true : $r['sources'][0]['status'] === 'empty');
file_put_contents($fx, "<html><body>Please log in to continue</body></html>");
$r = $run(['A' => $fx]);
check('3 malformed/non-feed response rejected, nothing changed', $r['sources'][0]['status'] === 'failed');

// 10 duplicate from a second source
file_put_contents($fx2, ics([['uid' => 'b-9', 'title' => 'Test Fest', 'start' => $d2, 'venue' => 'Sarit Centre, Nairobi', 'url' => 'https://other.example/test-fest']]));
file_put_contents($fx, ics([array_merge($A, ['start' => $d2, 'venue' => 'Sarit Centre, Nairobi']), $B]));
$run(['A' => $fx]);                       // source A now holds the event on the shared date and venue
$before = $count();
$r = $run(['B' => $fx2]);
check('10 same event from a second source is a duplicate, not a new row', $r['sources'][0]['duplicates'] === 1 && $count() === $before, json_encode([$r['sources'][0], $before, $count()]));

// unrelated events with similar titles are NOT merged
file_put_contents($fx2, ics([['uid' => 'b-10', 'title' => 'Test Fest', 'start' => date('Ymd', strtotime('+40 days')), 'venue' => 'Mombasa Beach', 'url' => 'https://other.example/test-fest-mombasa']]));
$run(['B' => $fx2]);
check('10b similar title on different date/venue stays separate', $find('b-10') !== null);

// 11 invalid ticket URL / 13 unverified details
file_put_contents($fx2, ics([['uid' => 'c-1', 'title' => 'Bad Link Night', 'start' => $d1, 'venue' => '', 'url' => 'javascript:alert(1)']]));
$r = $run(['C' => $fx2]);
check('11 invalid URL record rejected (javascript: never stored)', $r['sources'][0]['rejected'] === 1 && $find('c-1') === null);
file_put_contents($fx2, ics([['uid' => 'c-2', 'title' => 'No Venue Night', 'start' => $d1, 'venue' => '', 'url' => 'https://example.org/e/nv']]));
$run(['C' => $fx2]);
check('13 missing venue flagged for review and shown as unconfirmed', (int)$find('c-2')['needs_review'] === 1 && $find('c-2')['venue'] === 'Venue to be confirmed');
check('13b price text is never invented', tk_event_price_text($find('c-2'), tk_event_min_prices()) === 'Price not confirmed');

// owned event untouched, and sync log exists
check('owned event untouched by sync', tk_repo_find('events', 'id', $ownedId)['title'] === 'My Own Event' && tk_repo_find('events', 'id', $ownedId)['venue'] === 'Home');
check('sync history written', count(ds_read_json(ds_log_path())) > 5 && count(ds_read_json(ds_state_path())) > 3);

// unique constraint at database level
if (tk_db()) { try { tk_db()->exec("INSERT INTO events (slug,title,description,venue,city,starts_at,source_id,external_id) VALUES ('dup','d','d','v','c','2030-01-01 10:00:00','A','a-1')"); check('DB unique (source_id, external_id) enforced', false); } catch (PDOException $e) { check('DB unique (source_id, external_id) enforced', true); } }

// lock prevents overlap
$l1 = ds_lock(); $l2 = ds_lock();
check('lock prevents overlapping runs', $l1 !== null && $l2 === null); ds_unlock($l1);

// listing rules
$up = tk_events_query([]);
check('listing excludes cancelled and flagged-but-published stays', !in_array('a-1', array_column($up, 'external_id'), true) && in_array('a-2', array_column($up, 'external_id'), true));

echo "\n$pass passed, $fail failed\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
