<?php
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Audiences, Authorization, Config, Ui};

$owner = tk_activity_admin_guard();
$msg = null; $err = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Authorization::postWithCsrf();
    try {
        if (($_POST['do'] ?? '') === 'delete') { Audiences::delete((int)($_POST['id'] ?? 0), $owner); $msg = 'Audience deleted.'; }
        else {
            Audiences::create((string)($_POST['name'] ?? ''), ['type' => $_POST['type'] ?? '', 'days' => $_POST['days'] ?? 30, 'category' => $_POST['category'] ?? '', 'event_id' => $_POST['event_id'] ?? 0, 'campaign' => $_POST['campaign'] ?? ''], $owner);
            $msg = 'Audience saved. It is only a definition; nothing is sent anywhere.';
        }
    } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
}
tk_activity_admin_open('audiences', 'Audiences', 'Saved groups for TicketsKenya’s own marketing decisions. Nothing is sent automatically.');
echo Ui::flash($msg) . Ui::flash($err, 'error');
echo '<div class="callout"><p><strong>Who can be counted:</strong> only browsers whose latest privacy choice includes marketing, and only while “Advertising audiences” is on in Settings (currently <strong>' . (Config::bool('audiences_enabled') ? 'on' : 'off') . '</strong>). Anyone who withdraws is removed automatically. Groups smaller than ' . Audiences::MIN_SIZE . ' are shown as “fewer than ' . Audiences::MIN_SIZE . '”. External ad-platform uploads are not connected (they need provider credentials, approval and a consent review).</p></div>';
$rows = [];
foreach (Audiences::all() as $a) {
    $d = $a['definition'];
    $desc = Audiences::TYPES[$d['type'] ?? ''] ?? '?';
    foreach (['category' => 'category', 'event_id' => 'event #', 'campaign' => 'campaign'] as $k => $l) if (!empty($d[$k])) $desc .= ' — ' . $l . ': ' . $d[$k];
    $del = '<form method="post" action=""><input type="hidden" name="_csrf" value="' . Ui::e(tk_csrf_token()) . '"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><button class="btn btn-outline" type="submit">Delete</button></form>';
    $rows[] = [$a['name'], $desc, ($d['days'] ?? 30) . ' days', $a['estimate']['display'], $a['estimate']['note'], Ui::raw($del)];
}
echo Ui::panel('Saved audiences', Ui::table(['Name', 'Definition', 'Period', 'Estimated size', 'Note', ''], $rows, 'No audiences yet.'));
$opts = ''; foreach (Audiences::TYPES as $k => $l) $opts .= '<option value="' . $k . '">' . Ui::e($l) . '</option>';
echo Ui::panel('New audience', '<form class="sa-form" method="post" action=""><input type="hidden" name="_csrf" value="' . Ui::e(tk_csrf_token()) . '">'
  . '<label>Name<input name="name" maxlength="60" required></label>'
  . '<label>Type<select name="type">' . $opts . '</select></label>'
  . '<label>Category (for category interest)<input name="category" maxlength="60" list="saCats"></label><datalist id="saCats">' . implode('', array_map(fn($c) => '<option value="' . Ui::e($c) . '">', function_exists('tk_known_categories') ? tk_known_categories() : [])) . '</datalist>'
  . '<label>Event id (for “viewed a specific event”)<input name="event_id" type="number" min="1"></label>'
  . '<label>Campaign name (for campaign)<input name="campaign" maxlength="40"></label>'
  . '<label>Look back (days)<input name="days" type="number" min="1" max="365" value="30"></label>'
  . '<button class="btn btn-lime" type="submit">Save audience</button></form>');
tk_activity_admin_close();
