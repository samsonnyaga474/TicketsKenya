<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Config, Conversions, Ui};

$owner = tk_activity_admin_guard();
Conversions::syncPurchases();                 // turns verified paid orders into purchase events (idempotent)
$r = tk_activity_admin_range();
$o = Analytics::overview($r);
$h = Analytics::health();
$op = Analytics::orderPicture($r);
$rec = Analytics::reconcile($r);

tk_activity_admin_open('index', 'Activity & Insights', 'What visitors do on TicketsKenya. Every number comes from stored records; nothing is estimated unless it says so.');
echo Ui::rangeBar($r);
echo '<div class="sa-grid">'
  . Ui::kpi('Active now', Ui::num(Analytics::activeCount()), 'activity in the last ' . Config::int('active_window_min') . ' min')
  . Ui::kpi('Visitors', Ui::num($o['visitors']), 'browsers, not people')
  . Ui::kpi('Returning visitors', Ui::num($o['returning_visitors']), Ui::pct($o['returning_visitor_rate']) . ' of browsers in this period; prior recorded session')
  . Ui::kpi('Sessions', Ui::num($o['sessions']), 'new after ' . Config::int('session_timeout_min') . ' min idle')
  . Ui::kpi('Page views', Ui::num($o['page_views']), $o['pages_per_session'] . ' per session')
  . Ui::kpi('Avg. session', Ui::dur($o['avg_session_seconds']), 'sessions with 2+ actions')
  . Ui::kpi('Checkout starts', Ui::num($o['checkout_starts']), 'real orders created')
  . Ui::kpi('Purchases', Ui::num($o['purchases']), 'verified paid on the server')
  . Ui::kpi('Revenue', Ui::kes($o['revenue']), 'verified paid orders only')
  . Ui::kpi('Conversion rate', Ui::pct($o['conversion_rate']), 'sessions with a purchase / sessions')
  . '</div>';

echo Ui::panel('Activity over time', Ui::trendChart(Analytics::trend($r)), 'Hourly for a single day, daily for longer periods. Quiet periods are real zeros.');
echo '<div class="sa-cols">';
echo Ui::panel('Most viewed pages', Ui::bars(Analytics::topPages($r), 'label', 'views'), 'Event pages are grouped as /events/{event}; see Event interest for each event.');
echo Ui::panel('Most used buttons and links', Ui::table(['Element', 'Type', 'Clicks'], array_map(fn($x) => [($x['label'] ?: $x['element_id']) . '  (' . $x['element_id'] . ')', $x['name'], $x['clicks']], Analytics::topElements($r))));
echo Ui::panel('Traffic sources', Ui::table(['Type', 'Source', 'Sessions'], array_map(fn($x) => [$x['type'], $x['source'], $x['sessions']], Analytics::sources($r))));
echo Ui::panel('Searches', Ui::bars(Analytics::topSearches($r), 'term', 'n'), Config::bool('capture_search_terms') ? 'Typed text is stored with emails and number-like text removed.' : 'Search text capture is switched off in settings.');
echo Ui::panel('Event categories', Ui::table(['Category', 'Views', 'Card clicks', 'Filter uses'], array_map(fn($x) => [$x['label'], $x['views'], $x['clicks'], $x['filters']], Analytics::categories($r))));
echo Ui::panel('Devices', Ui::bars(Analytics::breakdown($r, 'device_category'), 'label', 'sessions'));
echo Ui::panel('Browsers', Ui::bars(Analytics::breakdown($r, 'browser'), 'label', 'sessions'));
echo Ui::panel('Operating systems', Ui::bars(Analytics::breakdown($r, 'os'), 'label', 'sessions'));
echo '</div>';

$fun = Analytics::funnel($r);
echo Ui::panel('Checkout funnel (sessions)', Ui::table(['Step', 'Sessions'], array_map(fn($x) => [$x['label'], $x['n']], $fun)), 'Tracked sessions only: a visitor who refused analytics is not in this funnel.');
echo Ui::panel('Orders in this period (from the orders table, regardless of consent)',
  Ui::table(['Orders created', 'Paid', 'Still open', 'Abandonment estimate', 'Paid revenue', 'Orders with no tracked session'],
    [[$op['orders'], $op['paid'], $op['open'], $op['abandoned_estimate'] . ($op['abandon_rate'] !== null ? ' (' . Ui::pct($op['abandon_rate']) . ')' : ''), Ui::kes($op['paid_revenue']), $op['untracked_orders']]]),
  'An order counts as an abandonment estimate only if it is older than ' . Config::int('abandonment_window_hours') . ' hours and still unpaid. We cannot know why someone left.');
echo Ui::panel('Revenue check', '<p>' . ($rec['match'] ? 'Analytics purchases match the orders table: ' : 'MISMATCH. ') . Ui::kes($rec['analytics_revenue']) . ' in ' . $rec['analytics_orders'] . ' purchase(s) here, ' . Ui::kes($rec['orders_revenue']) . ' in ' . $rec['orders_count'] . ' paid order(s) in the orders table.</p>');

$errs = array_map(fn($x) => [$x['kind'], $x['n'], Ui::local($x['last_at'])], $h['errors_24h']);
echo Ui::panel('Tracking health',
  '<p>Tracking is <strong>' . ($h['enabled'] ? 'on' : 'off') . '</strong>, consent mode <strong>' . Ui::e($h['consent_mode']) . '</strong>. Last event stored: ' . Ui::e(Ui::local($h['last_event_at'])) . '. Last 24 h: ' . $h['events_24h'] . ' events, ' . $h['sessions_24h'] . ' sessions.</p>'
  . ($errs ? Ui::table(['Collection issue (24 h)', 'Count', 'Last seen'], $errs) : '<p class="sa-muted">No collection errors in the last 24 hours.</p>')
  . '<p class="sa-muted">Consent choices (30 days): ' . Ui::e(implode(', ', array_map(fn($c) => $c['action'] . ' ' . $c['n'], $h['consent_30d'])) ?: 'none yet') . '.</p>');
tk_activity_admin_close();
