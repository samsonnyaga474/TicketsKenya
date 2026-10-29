<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Config, Conversions, Ui};

tk_activity_admin_guard();
Conversions::syncPurchases();
$r = tk_activity_admin_range();
tk_activity_admin_open('campaigns', 'Campaigns and sources', 'Which links bring visitors and which of them end in verified purchases.');
echo Ui::rangeBar($r);
echo '<div class="callout"><p><strong>How attribution works.</strong> A session keeps the utm_ tags of the link it arrived by. An order is linked to the session that created it; if that session had no tags, to the same browser’s most recent tagged session within ' . Config::int('attribution_window_days') . ' days. This shows which campaign was seen before a sale, not that it caused the sale.</p></div>';
echo Ui::panel('Campaigns', Ui::table(['Source', 'Medium', 'Campaign', 'Sessions', 'Visitors', 'Checkouts', 'Purchases', 'Verified revenue'],
  array_map(fn($c) => [$c['source'], $c['medium'], $c['campaign'] ?? '—', $c['sessions'], $c['visitors'], $c['checkouts'], $c['purchases'], Ui::kes($c['revenue'])], Analytics::campaigns($r)),
  'No tagged visits yet. Add ?utm_source=...&utm_medium=...&utm_campaign=... to links you share.'));
echo '<div class="sa-cols">';
echo Ui::panel('Traffic sources', Ui::table(['Type', 'Source', 'Sessions'], array_map(fn($x) => [$x['type'], $x['source'], $x['sessions']], Analytics::sources($r, 30))));
echo Ui::panel('Landing pages of tagged visits', Ui::bars(Analytics::landingPages($r), 'label', 'sessions'));
echo '</div>';
tk_activity_admin_close();
