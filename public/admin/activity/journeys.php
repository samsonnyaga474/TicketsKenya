<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Config, Ui};

tk_activity_admin_guard();
$r = tk_activity_admin_range();
tk_activity_admin_open('journeys', 'Visitor journeys', 'How visitors move through the site. These are observed facts; we do not claim to know why anyone left.');
echo Ui::rangeBar($r);
echo '<div class="sa-cols">';
echo Ui::panel('Entry pages', Ui::table(['First page of the session', 'Sessions'], array_map(fn($x) => [$x['label'], $x['sessions']], Analytics::entryPages($r))));
echo Ui::panel('Exit pages', Ui::table(['Last page of the session', 'Sessions'], array_map(fn($x) => [$x['label'], $x['sessions']], Analytics::exitPages($r))), 'The last page seen in a session. It shows where tracking stopped, not why.');
echo '</div>';
echo Ui::panel('Page to page', Ui::table(['From', 'To', 'Times'], array_map(fn($x) => [$x['from_page'], $x['to_page'], $x['n']], Analytics::transitions($r))), 'Reloads of the same page are left out.');
echo Ui::panel('Most common paths', Ui::table(['Path (first 5 distinct pages)', 'Sessions'], array_map(fn($x) => [$x['path'], $x['sessions']], Analytics::commonPaths($r))));
echo Ui::panel('Funnel', Ui::table(['Step', 'Sessions'], array_map(fn($x) => [$x['label'], $x['n']], Analytics::funnel($r))), 'Each step counts distinct tracked sessions. Paid means the server verified payment; payments are switched off in this build, so this stays at 0 until they are enabled.');
$o = Analytics::overview($r);
echo Ui::panel('Session size', '<p>' . Ui::num($o['sessions']) . ' sessions, ' . $o['pages_per_session'] . ' pages per session, average length ' . Ui::e(Ui::dur($o['avg_session_seconds'])) . ' (sessions with at least two actions; the last page’s reading time is not known).</p>');
tk_activity_admin_close();
