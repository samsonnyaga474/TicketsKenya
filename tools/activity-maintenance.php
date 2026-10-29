<?php
/**
 * Command-line maintenance for Site Activity & Insights.
 *   php tools/activity-maintenance.php install      create/upgrade the activity_ tables (safe to repeat)
 *   php tools/activity-maintenance.php sync         turn verified paid orders into purchase events
 *   php tools/activity-maintenance.php retention    apply the retention periods (run daily)
 *   php tools/activity-maintenance.php status       row counts
 *   php tools/activity-maintenance.php uninstall --yes   drop ONLY the activity_ tables (orders/tickets/users are never touched)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php';

$cmd = $argv[1] ?? 'status';
if (!tk_activity_boot() && $cmd !== 'uninstall') { fwrite(STDERR, "SQLite database not available; module cannot run.\n"); exit(1); }
switch ($cmd) {
    case 'install': \SiteActivity\Module::install(); echo "Installed (schema v" . \SiteActivity\Module::SCHEMA_VERSION . ").\n"; break;
    case 'sync': echo 'New purchase events: ' . \SiteActivity\Conversions::syncPurchases() . "\n"; break;
    case 'retention':
        foreach (\SiteActivity\Retention::run(null) as $k => $v) echo "$k: $v\n";
        tk_security_prune();
        tk_security_prune_login_attempts();
        echo "security_events: 30-day retention applied\nlogin_attempts: 24-hour retention applied\n";
        break;
    case 'status': foreach (\SiteActivity\Analytics::totalsForAudit() as $k => $v) echo "$k: " . ($v ?? '-') . "\n"; break;
    case 'uninstall':
        if (($argv[2] ?? '') !== '--yes') { echo "Add --yes to confirm. This deletes all analytics data.\n"; exit(1); }
        $pdo = (new TicketsKenyaActivityAdapter())->pdo();
        if ($pdo) { require_once TK_ROOT . '/modules/site-activity/src/Schema.php'; \SiteActivity\Schema::uninstall($pdo); }
        echo "Activity tables removed.\n"; break;
    default: fwrite(STDERR, "Unknown command.\n"); exit(1);
}
