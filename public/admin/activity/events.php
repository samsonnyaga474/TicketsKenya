<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Config, Conversions, Ui};

tk_activity_admin_guard();
Conversions::syncPurchases();
$r = tk_activity_admin_range();
$rows = Analytics::eventInterest($r, 50);
tk_activity_admin_open('events', 'Event interest', 'Which events people look at and what happens next. Viewing an event is interest, not a purchase.');
echo Ui::rangeBar($r);
echo Ui::panel('Events', Ui::table(['Event', 'Views', 'Previous period', 'Change', 'Card clicks', 'Checkouts', 'Purchases', 'View → checkout', 'Checkout → purchase', 'Verified revenue', 'Note'],
  array_map(function ($x) {
      $d = $x['views'] - $x['prev_views'];
      $note = ($x['views'] >= 5 && $x['checkouts'] === 0) ? 'Often viewed, no checkouts' : '';
      return [$x['label'], $x['views'], $x['prev_views'], ($d > 0 ? '+' : '') . $d, $x['clicks'], $x['checkouts'], $x['purchases'], Ui::pct($x['view_to_checkout']), Ui::pct($x['checkout_to_purchase']), Ui::kes($x['revenue']), $note];
  }, $rows)), 'Previous period = the same number of days immediately before this one.');
echo '<div class="sa-cols">';
echo Ui::panel('Categories', Ui::table(['Category', 'Views', 'Card clicks', 'Filter uses'], array_map(fn($x) => [$x['label'], $x['views'], $x['clicks'], $x['filters']], Analytics::categories($r, 15))));
echo Ui::panel('Cities chosen in filters', Ui::bars(Analytics::cityInterest($r), 'label', 'n'));
echo Ui::panel('Searches', Ui::bars(Analytics::topSearches($r, 15), 'term', 'n'));
echo '</div>';
tk_activity_admin_close();
