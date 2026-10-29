<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * Creates the module's own tables (all prefixed "activity_"). Safe to run repeatedly.
 * It never alters or drops any table that does not start with "activity_".
 */
final class Schema
{
    public const TABLES = [
        'activity_settings', 'activity_sessions', 'activity_events', 'activity_consent', 'activity_audit_logs',
        'activity_audiences', 'activity_order_links', 'activity_rate', 'activity_errors',
    ];

    public static function install(\PDO $pdo): void
    {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_settings (
            k TEXT PRIMARY KEY, v TEXT NOT NULL, updated_at TEXT NOT NULL, updated_by INTEGER
        );
        CREATE TABLE IF NOT EXISTS activity_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_uid TEXT NOT NULL UNIQUE,
            visitor_uid TEXT NOT NULL,
            project TEXT NOT NULL,
            started_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            landing_path TEXT, last_path TEXT,
            referrer_host TEXT, referrer_type TEXT,
            utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT,
            device_category TEXT NOT NULL DEFAULT 'unknown',
            browser TEXT, browser_version TEXT, os TEXT, screen_cat TEXT, language TEXT,
            ip_value TEXT, ip_kind TEXT, ip_public INTEGER, country TEXT,
            user_id INTEGER,
            consent_analytics INTEGER NOT NULL DEFAULT 0,
            consent_marketing INTEGER NOT NULL DEFAULT 0,
            policy_version TEXT,
            page_views INTEGER NOT NULL DEFAULT 0,
            event_count INTEGER NOT NULL DEFAULT 0,
            is_test INTEGER NOT NULL DEFAULT 0,
            anonymised_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_as_started ON activity_sessions(started_at);
        CREATE INDEX IF NOT EXISTS idx_as_seen ON activity_sessions(last_seen_at);
        CREATE INDEX IF NOT EXISTS idx_as_visitor ON activity_sessions(visitor_uid, started_at);
        CREATE INDEX IF NOT EXISTS idx_as_campaign ON activity_sessions(utm_campaign);

        CREATE TABLE IF NOT EXISTS activity_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_uid TEXT NOT NULL,
            project TEXT NOT NULL,
            session_id INTEGER,
            visitor_uid TEXT,
            occurred_at TEXT NOT NULL,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            path TEXT, path_group TEXT,
            element_id TEXT, element_label TEXT, target_path TEXT,
            event_ref_id INTEGER, category_ref TEXT,
            order_id INTEGER, value_num INTEGER,
            query_text TEXT, meta_json TEXT,
            source TEXT NOT NULL DEFAULT 'client',
            is_test INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_ae_uid ON activity_events(event_uid);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_ae_order_once ON activity_events(name, order_id) WHERE order_id IS NOT NULL AND source = 'server';
        CREATE INDEX IF NOT EXISTS idx_ae_time ON activity_events(occurred_at);
        CREATE INDEX IF NOT EXISTS idx_ae_name_time ON activity_events(name, occurred_at);
        CREATE INDEX IF NOT EXISTS idx_ae_session ON activity_events(session_id, occurred_at);
        CREATE INDEX IF NOT EXISTS idx_ae_ref ON activity_events(event_ref_id, name);
        CREATE INDEX IF NOT EXISTS idx_ae_visitor ON activity_events(visitor_uid, occurred_at);

        CREATE TABLE IF NOT EXISTS activity_consent (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_uid TEXT, user_id INTEGER,
            analytics INTEGER NOT NULL, marketing INTEGER NOT NULL,
            policy_version TEXT NOT NULL, action TEXT NOT NULL, decided_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ac_visitor ON activity_consent(visitor_uid, id);
        CREATE INDEX IF NOT EXISTS idx_ac_user ON activity_consent(user_id, id);

        CREATE TABLE IF NOT EXISTS activity_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            at TEXT NOT NULL, actor_user_id INTEGER, actor_email TEXT,
            action TEXT NOT NULL, detail TEXT, ip_value TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_aal_at ON activity_audit_logs(at);

        CREATE TABLE IF NOT EXISTS activity_audiences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, definition_json TEXT NOT NULL,
            created_at TEXT NOT NULL, created_by INTEGER
        );

        CREATE TABLE IF NOT EXISTS activity_order_links (
            order_id INTEGER PRIMARY KEY,
            session_id INTEGER, visitor_uid TEXT,
            utm_source TEXT, utm_medium TEXT, utm_campaign TEXT,
            attribution TEXT NOT NULL DEFAULT 'none',
            linked_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS activity_rate (k TEXT PRIMARY KEY, w INTEGER NOT NULL, c INTEGER NOT NULL);

        CREATE TABLE IF NOT EXISTS activity_errors (
            id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, kind TEXT NOT NULL, detail TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_aerr_at ON activity_errors(at);
        ");
        $pdo->prepare('INSERT OR IGNORE INTO activity_settings (k, v, updated_at) VALUES (?,?,?)')
            ->execute(['schema_version', (string)Module::SCHEMA_VERSION, gmdate('Y-m-d H:i:s')]);
    }

    /** Rollback: removes only the module's own tables. Orders, tickets, users and payments are never touched. */
    public static function uninstall(\PDO $pdo): void
    {
        foreach (self::TABLES as $t) {
            if (!str_starts_with($t, 'activity_')) continue;
            $pdo->exec('DROP TABLE IF EXISTS ' . $t);
        }
    }
}
