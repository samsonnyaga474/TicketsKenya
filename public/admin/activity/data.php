<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Authorization, Config, Export, Repository, Retention, Ui};

$owner = tk_activity_admin_guard();
$msg = null; $err = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Authorization::postWithCsrf();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'retention') {
        $res = Retention::run($owner);
        $msg = 'Retention applied: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($res), $res)) . '. Orders, tickets and payments were not touched.';
    } elseif ($do === 'erase_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id < 1) $err = 'Enter a valid account id.';
        else { $res = Retention::eraseUser($id, $owner); $msg = 'Analytics linked to account #' . $id . ' erased (' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($res), $res)) . '). Orders and tickets are kept because they are accounting records.'; }
    }
}
$t = Analytics::totalsForAudit();
tk_activity_admin_open('data', 'Exports & data', 'Download reports, apply retention and answer erasure requests.');
echo Ui::flash($msg) . Ui::flash($err, 'error');
$r = Analytics::range($_GET);
echo Ui::panel('Stored data', '<p>' . Ui::num($t['events']) . ' events and ' . Ui::num($t['sessions']) . ' sessions. Oldest event: ' . Ui::e(Ui::local($t['oldest_event'], 'j M Y H:i')) . '. Retention: events ' . Config::int('retention_events_days') . ' days, IP values ' . Config::int('retention_ip_days') . ' days, sessions ' . Config::int('retention_sessions_days') . ' days (change in Settings).</p>');

if (Config::bool('exports_enabled')) {
    $opts = ''; foreach (Export::TYPES as $k => $l) $opts .= '<option value="' . $k . '">' . Ui::e($l) . '</option>';
    echo Ui::panel('CSV export', '<form class="sa-form" method="post" action="' . Ui::e(Ui::url('export')) . '"><input type="hidden" name="_csrf" value="' . Ui::e(tk_csrf_token()) . '">'
      . '<label>Report<select name="type">' . $opts . '</select></label>'
      . '<label>Period<select name="range"><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="7d" selected>Last 7 days</option><option value="30d">Last 30 days</option><option value="custom">Custom (use dates below)</option></select></label>'
      . '<label>From<input type="date" name="from"></label><label>To<input type="date" name="to"></label>'
      . '<label><span><input type="checkbox" name="include_queries" value="1"> Include typed search text in the activity log</span><small>Off by default. Visitor ids and IP values are never exported.</small></label>'
      . '<button class="btn btn-lime" type="submit">Download CSV</button></form>', 'Cells that could run as spreadsheet formulas are neutralised. Each export is written to the audit log.');
} else echo Ui::panel('CSV export', '<p>Exports are switched off in Settings.</p>');

echo Ui::panel('Apply retention now', '<form method="post" action=""><input type="hidden" name="_csrf" value="' . Ui::e(tk_csrf_token()) . '"><input type="hidden" name="do" value="retention"><button class="btn btn-outline" type="submit">Delete / anonymise data past its retention period</button></form>',
  'Only activity tables are touched. Orders, tickets, users and payment records are never deleted here.');
echo Ui::panel('Erase analytics for a customer account', '<form class="sa-form" method="post" action=""><input type="hidden" name="_csrf" value="' . Ui::e(tk_csrf_token()) . '"><input type="hidden" name="do" value="erase_user"><label>Account id<input type="number" name="user_id" min="1" required></label><button class="btn btn-outline" type="submit">Erase their analytics</button></form>',
  'For an erasure request. Order, ticket and payment records are legal and accounting records and stay; browsers can also erase their own data on the Privacy settings page.');
$al = Module_audit();
echo Ui::panel('Restricted audit log (latest 50)', Ui::table(['When', 'Who', 'Action', 'Detail'], array_map(fn($a) => [Ui::local($a['at'], 'j M H:i'), $a['actor_email'] ?: 'system/visitor', $a['action'], $a['detail']], $al)));
function Module_audit(): array { return SiteActivity\Module::requirePdo()->query('SELECT at, actor_email, action, detail FROM activity_audit_logs ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC); }
tk_activity_admin_close();
