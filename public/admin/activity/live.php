<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Config, Ui};

tk_activity_admin_guard();
Analytics::$includeTest = (($_GET['include_test'] ?? '') === '1');
tk_activity_admin_open('live', 'Live activity', 'Browsers that did something recently, and the latest actions.');
echo '<p class="sa-muted"><span class="sa-live" aria-hidden="true"></span>A session counts as <strong>active</strong> if it did something in the last ' . Config::int('active_window_min')
   . ' minutes. It stays a session (not yet “expired”) for ' . Config::int('session_timeout_min') . ' minutes of silence. We only know a browser, never who is using it. Refreshes every '
   . Config::int('refresh_seconds') . ' seconds.</p>';
echo '<div class="sa-grid">' . Ui::kpi('Active sessions', '…', 'updates automatically') . '</div>';
echo '<div id="saLive" data-api="' . Ui::e(Ui::url('api', Analytics::$includeTest ? ['include_test' => 1] : [])) . '" data-every="' . Config::int('refresh_seconds') . '">';
echo Ui::panel('Active sessions', '<div class="table-wrap sa-table"><table><thead><tr><th>Browser id</th><th>Started</th><th>Last seen</th><th>Page</th><th>Source</th><th>Device</th><th>Browser / OS</th><th>Screen</th><th>Logged in</th><th>Actions</th></tr></thead><tbody id="saSess"><tr><td colspan="10">Loading…</td></tr></tbody></table></div>');
echo Ui::panel('Latest actions', '<div class="table-wrap sa-table"><table><thead><tr><th>Time</th><th>Action</th><th>Page</th><th>Element</th><th>Device</th><th>Browser id</th></tr></thead><tbody id="saFeed"><tr><td colspan="6">Loading…</td></tr></tbody></table></div>');
echo '</div><script src="' . Ui::e(tk_asset('js/activity-live.js')) . '" defer></script>';
tk_activity_admin_close();
