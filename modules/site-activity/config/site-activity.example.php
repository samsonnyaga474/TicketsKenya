<?php
/**
 * Optional configuration for Site Activity & Insights.
 * Copy to  config/site-activity.php  (TicketsKenya) and edit. Every key is optional; the safe defaults are in src/Config.php.
 * These are only the STARTING values: anything the owner saves on the Settings page is stored in the database and wins.
 * Never put secrets here. Set SITE_ACTIVITY_SECRET in the environment if you want a fixed key for IP hashing.
 */
return [
    'project_id'              => 'ticketskenya',
    'consent_mode'            => 'opt_in',     // opt_in (recommended) | notice_only (take legal advice first)
    'policy_version'          => '1',          // bump when your privacy notice changes: everyone is asked again
    'ip_mode'                 => 'truncated',  // none | truncated | keyed_hash | full
    'retention_events_days'   => '90',
    'retention_ip_days'       => '30',
    'retention_sessions_days' => '365',
    'session_timeout_min'     => '30',
    'active_window_min'       => '5',
    'excluded_paths'          => '/admin,/auth,/payments,/activity',
    'trusted_proxies'         => '',           // only if behind a reverse proxy YOU control, e.g. '10.0.0.0/8,203.0.113.5'
    'audiences_enabled'       => '0',
];
