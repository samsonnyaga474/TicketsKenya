<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php';

use SiteActivity\Config;
use SiteActivity\Privacy;

$on = tk_activity_boot();
$consent = $on ? Privacy::consentFromCookie($_COOKIE[Privacy::COOKIE_CONSENT] ?? null) : ['decided' => false, 'analytics' => false, 'marketing' => false];
tk_page_open('Privacy settings | TicketsKenya', 'Choose what TicketsKenya may count about your visit.', 'Privacy settings', 'You decide what is counted. Refusing never stops you using the site.', ['noindex' => true]);
?>
<div class="prose">
  <h2>What we count if you say yes</h2>
  <ul>
    <li>Which pages you open, which links and buttons you use, which events you view, and what you type in the search box (emails and number-like text are removed).</li>
    <li>Your device type, browser and operating system, the site you came from, and campaign tags in the link (utm_ values).</li>
    <li>A shortened network address (<?= tk_e(Config::get('ip_mode') === 'full' ? 'full' : (Config::get('ip_mode') === 'none' ? 'not stored' : (Config::get('ip_mode') === 'keyed_hash' ? 'stored only as a one-way code' : 'last part removed'))) ?>), kept for <?= (int)Config::int('retention_ip_days') ?> days.</li>
  </ul>
  <h2>What we never collect</h2>
  <p>Passwords, card or M-Pesa details, what you type in login, registration or contact forms, your microphone, camera, contacts or location, or anything from other websites.</p>
  <h2>How it works</h2>
  <p>We use two first-party cookies (<code>sa_vid</code> identifies this browser, <code>sa_sid</code> groups one visit) and one choice cookie (<code>sa_consent</code>). They identify a browser, not a person. Activity records are kept for <?= (int)Config::int('retention_events_days') ?> days. We process this data because you agreed; you can withdraw at any time below.</p>
  <p>You have rights over your personal data under Kenya’s Data Protection Act, 2019, including access, correction and deletion. Write to <a href="mailto:<?= tk_e(TK_SUPPORT_EMAIL) ?>"><?= tk_e(TK_SUPPORT_EMAIL) ?></a>. You may also complain to the Office of the Data Protection Commissioner.</p>
</div>

<?php if (!$on): ?>
  <div class="callout"><p>Activity measurement is not available on this server, so nothing is being counted.</p></div>
<?php else: ?>
  <p class="sa-state">Your current choice: <strong><?= $consent['decided'] ? ($consent['analytics'] ? 'analytics on' . ($consent['marketing'] ? ', marketing groups on' : '') : 'analytics off') : 'not chosen yet' ?></strong></p>
  <form method="post" action="<?= tk_e(tk_base_path()) ?>/activity/consent.php" class="sa-choice-form">
    <?= tk_csrf_field() ?>
    <input type="hidden" name="decision" value="custom">
    <label class="opt"><input type="checkbox" name="analytics" value="1"<?= $consent['analytics'] ? ' checked' : '' ?>> Allow analytics (counting visits, clicks and searches to improve the site)</label>
    <label class="opt"><input type="checkbox" name="marketing" value="1"<?= $consent['marketing'] ? ' checked' : '' ?>> Also allow my activity to be used to build interest groups for TicketsKenya’s own marketing (needs analytics on)</label>
    <p class="sa-actions"><button class="btn btn-lime" type="submit">Save my choices</button></p>
  </form>
  <form method="post" action="<?= tk_e(tk_base_path()) ?>/activity/consent.php" class="sa-choice-form">
    <?= tk_csrf_field() ?>
    <input type="hidden" name="decision" value="erase">
    <p class="sa-actions"><button class="btn btn-outline" type="submit">Turn analytics off and delete this browser’s activity data</button></p>
    <p class="sa-muted">This removes activity records linked to this browser. Orders and tickets are not affected because we must keep them.</p>
  </form>
<?php endif; ?>
<?php tk_page_close(); ?>
