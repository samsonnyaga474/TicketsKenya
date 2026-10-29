<?php
/** Shared opening/closing for the owner pages. Loads the site's own header/footer so the pages look native. */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';
require_once __DIR__ . '/ticketskenya.php';

use SiteActivity\Analytics;
use SiteActivity\Authorization;
use SiteActivity\Ui;

/** Boots the module and enforces owner access. Returns the owner. */
function tk_activity_admin_guard(bool $json = false): array
{
    if (!tk_activity_boot()) {
        http_response_code(503);
        exit('Activity & Insights needs the SQLite database and is not available on this server.');
    }
    $u = $json ? Authorization::ownerApi() : Authorization::ownerPage();
    session_write_close_if_get();
    return $u;
}

function session_write_close_if_get(): void { /* kept simple: pages flash via $_SESSION, so the session stays open */ }

function tk_activity_admin_open(string $active, string $title, string $lead, array $keep = []): void
{
    global $pageTitle, $noindex, $bodyClass;
    $pageTitle = $title . ' | Activity & Insights | TicketsKenya';
    $noindex = true;
    require TK_ROOT . '/templates/header.php';
    echo '<link rel="stylesheet" href="' . Ui::e(tk_asset('css/activity.css')) . '">';
    echo '<div class="section-inner"><div class="page-hero"><h1>' . Ui::e($title) . '</h1><p>' . Ui::e($lead) . '</p></div>';
    echo Ui::subnav($active, $keep);
    if (Analytics::$includeTest) echo '<p class="sa-muted"><strong>Test traffic is included in these numbers.</strong></p>';
}

function tk_activity_admin_close(): void
{
    echo '</div>';
    require TK_ROOT . '/templates/footer.php';
}

/** Range + test-traffic switch from the query string. */
function tk_activity_admin_range(): array
{
    Analytics::$includeTest = (($_GET['include_test'] ?? '') === '1');
    return Analytics::range($_GET);
}
