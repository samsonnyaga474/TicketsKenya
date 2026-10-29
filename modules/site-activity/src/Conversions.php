<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * Checkout and purchase events are created ONLY here, on the server, from real order records.
 * The browser can never create them (Collector refuses those names).
 */
final class Conversions
{
    /**
     * Call right after the host has created a real order. Safe to call when tracking is off or consent is missing
     * (it then does nothing). Never throws: tracking must not break checkout.
     */
    public static function checkoutStarted(int $orderId, ?int $hostEventId, int $totalMinor, array $server, array $cookies, ?array $user): void
    {
        try {
            if (!Module::installed() || !Config::bool('tracking_enabled')) return;
            $consent = Privacy::consentFromCookie($cookies[Privacy::COOKIE_CONSENT] ?? null);
            if (!Privacy::analyticsAllowed($consent)) return;
            if (!in_array('checkout', Config::list('enabled_categories'), true)) return;
            if ($user && Config::bool('exclude_admins') && in_array($user['role'] ?? '', ['admin', 'organiser'], true)) return;
            if (DeviceClassifier::classify((string)($server['HTTP_USER_AGENT'] ?? ''))['bot']) return;

            $visitor = $cookies[Privacy::COOKIE_VISITOR] ?? null;
            if (!Privacy::validId($visitor)) return;
            $session = null;
            $sid = $cookies[Privacy::COOKIE_SESSION] ?? null;
            if (Privacy::validId($sid)) {
                $s = Repository::sessionByUid($sid);
                if ($s && $s['visitor_uid'] === $visitor) $session = $s;
            }
            if (!$session) return; // no tracked session: the order still exists in the orders table and is reported as "untracked"

            $now = gmdate('Y-m-d H:i:s');
            $project = Config::get('project_id');
            $camp = ['utm_source' => $session['utm_source'], 'utm_medium' => $session['utm_medium'], 'utm_campaign' => $session['utm_campaign']];
            $how = $camp['utm_source'] ? 'session' : 'none';
            if (!$camp['utm_source']) {
                // Last campaign touch by the same browser inside the attribution window.
                $cut = gmdate('Y-m-d H:i:s', time() - Config::int('attribution_window_days') * 86400);
                $st = Module::requirePdo()->prepare('SELECT utm_source, utm_medium, utm_campaign FROM activity_sessions WHERE visitor_uid = ? AND utm_source IS NOT NULL AND started_at >= ? ORDER BY started_at DESC LIMIT 1');
                $st->execute([$visitor, $cut]);
                if ($prior = $st->fetch()) { $camp = $prior; $how = 'prior_session'; }
            }
            Module::requirePdo()->prepare('INSERT OR IGNORE INTO activity_order_links (order_id, session_id, visitor_uid, utm_source, utm_medium, utm_campaign, attribution, linked_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$orderId, $session['id'], $visitor, $camp['utm_source'], $camp['utm_medium'], $camp['utm_campaign'], $how, $now]);

            Repository::insertEvent([
                'event_uid' => $project . ':checkout:' . $orderId, 'project' => $project, 'session_id' => (int)$session['id'], 'visitor_uid' => $visitor,
                'occurred_at' => $now, 'name' => 'checkout_started', 'category' => 'checkout', 'path' => null, 'path_group' => null,
                'event_ref_id' => $hostEventId, 'order_id' => $orderId, 'value_num' => $totalMinor, 'source' => 'server', 'is_test' => (int)$session['is_test'],
            ]);
            Repository::touchSession((int)$session['id'], $now, null, 0, 1, $user['id'] ?? null, 0);
        } catch (\Throwable $e) {
            Repository::logError('checkout_hook', get_class($e));
        }
    }

    /**
     * Turns orders the host has verified as paid into purchase_completed events. Idempotent: the unique index
     * on (name, order_id) for server events means running this twice never counts a sale twice.
     * @return int number of new purchase events created
     */
    public static function syncPurchases(int $limit = 1000): int
    {
        if (!Module::installed()) return 0;
        $pdo = Module::requirePdo();
        $n = 0;
        $project = Config::get('project_id');
        foreach (Module::host()->paidOrders(0, $limit) as $o) {
            $orderId = (int)$o['order_id'];
            $link = $pdo->prepare('SELECT session_id, visitor_uid FROM activity_order_links WHERE order_id = ?');
            $link->execute([$orderId]);
            $l = $link->fetch() ?: ['session_id' => null, 'visitor_uid' => null];
            $isTest = 0;
            if ($l['session_id']) {
                $t = $pdo->prepare('SELECT is_test FROM activity_sessions WHERE id = ?'); $t->execute([$l['session_id']]);
                $isTest = (int)$t->fetchColumn();
            }
            $id = Repository::insertEvent([
                'event_uid' => $project . ':purchase:' . $orderId, 'project' => $project, 'session_id' => $l['session_id'], 'visitor_uid' => $l['visitor_uid'],
                'occurred_at' => (string)$o['paid_at'], 'name' => 'purchase_completed', 'category' => 'checkout',
                'event_ref_id' => $o['event_id'] ?? null, 'order_id' => $orderId, 'value_num' => (int)$o['total'], 'source' => 'server', 'is_test' => $isTest,
            ]);
            if ($id !== null) $n++;
        }
        return $n;
    }
}
