<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * Entry point of the reusable "Site Activity & Insights" module.
 *
 *   require 'modules/site-activity/src/Module.php';
 *   \SiteActivity\Module::boot($adapter, $optionalConfigArray);
 *
 * Nothing here knows about tickets or events: project specifics live in the HostAdapter.
 */
final class Module
{
    public const VERSION = '1.0.0';
    public const SCHEMA_VERSION = 1;
    private static ?HostAdapter $host = null;
    private static bool $booted = false;

    public static function boot(HostAdapter $host, array $config = []): void
    {
        foreach (['Config', 'Privacy', 'DeviceClassifier', 'Registry', 'Repository', 'Schema', 'Collector', 'Analytics',
                  'Conversions', 'Audiences', 'Retention', 'Export', 'Authorization', 'Ui'] as $cls) {
            require_once __DIR__ . '/' . $cls . '.php';
        }
        self::$host = $host;
        self::$booted = true;
        Config::loadFile($config);
    }

    public static function booted(): bool { return self::$booted; }

    public static function host(): HostAdapter
    {
        if (!self::$host) throw new \RuntimeException('Site Activity module is not booted.');
        return self::$host;
    }

    public static function pdo(): ?\PDO
    {
        try { return self::$host ? self::$host->pdo() : null; } catch (\Throwable $e) { return null; }
    }

    public static function requirePdo(): \PDO
    {
        $p = self::pdo();
        if (!$p) throw new \RuntimeException('The module needs an SQL database.');
        return $p;
    }

    /** True once the activity tables exist. Only a positive answer is cached, so a fresh install is noticed straight away. */
    public static function installed(): bool
    {
        static $ok = false;
        if ($ok) return true;
        $pdo = self::pdo();
        if (!$pdo) return false;
        try {
            $ok = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='activity_events'")->fetchColumn();
        } catch (\Throwable $e) { $ok = false; }
        return $ok;
    }

    /** Idempotent: creates missing tables/indexes. Never touches the host's own tables. */
    public static function install(): void
    {
        Schema::install(self::requirePdo());
        Config::reset();
    }
}
