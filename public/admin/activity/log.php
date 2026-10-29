<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Registry, Ui};

tk_activity_admin_guard();
$r = tk_activity_admin_range();
$name = (string)($_GET['name'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$log = Analytics::eventsPage($r, $page, 50, $name);
tk_activity_admin_open('log', 'Activity log', 'Individual stored actions, newest first. Visitor ids are shortened; no IP addresses are shown here.');
echo Ui::rangeBar($r, $name !== '' ? ['name' => $name] : []);
echo '<form class="sa-range" method="get" action=""><label class="sr-only" for="saName">Action</label><select id="saName" name="name"><option value="">All actions</option>';
foreach (array_keys(Registry::EVENTS) as $n) echo '<option value="' . Ui::e($n) . '"' . ($n === $name ? ' selected' : '') . '>' . Ui::e($n) . '</option>';
echo '</select>';
foreach (['range' => $r['preset'], 'from' => $r['from_date'], 'to' => $r['to_date']] as $k => $v) echo '<input type="hidden" name="' . $k . '" value="' . Ui::e($v) . '">';
echo '<button class="btn btn-outline" type="submit">Filter</button></form>';
echo Ui::table(['Time', 'Action', 'Page', 'Element', 'Target', 'Event id', 'Order', 'Device', 'Browser id', 'Origin'],
  array_map(fn($e) => [Ui::local($e['occurred_at'], 'j M H:i:s'), $e['name'], $e['path'], $e['element_id'], $e['target_path'], $e['event_ref_id'], $e['order_id'], $e['device_category'], $e['visitor_short'], $e['source']], $log['rows']));
$q = ['range' => $r['preset'], 'from' => $r['from_date'], 'to' => $r['to_date'], 'name' => $name];
echo '<p class="sa-pager">' . ($page > 1 ? '<a class="btn btn-outline" href="' . Ui::e(Ui::url('log', $q + ['page' => $page - 1])) . '">← Newer</a>' : '')
   . '<span>Page ' . $log['page'] . ' of ' . $log['pages'] . ' (' . Ui::num($log['total']) . ' actions)</span>'
   . ($page < $log['pages'] ? '<a class="btn btn-outline" href="' . Ui::e(Ui::url('log', $q + ['page' => $page + 1])) . '">Older →</a>' : '') . '</p>';
tk_activity_admin_close();
