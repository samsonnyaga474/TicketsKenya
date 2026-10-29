<?php
/**
 * TicketsKenya adapter for the reusable Site Activity & Insights module.
 * Everything TicketsKenya-specific (events, orders, owner rule, CSRF, session) lives in this file.
 * Include it AFTER includes/bootstrap.php (it uses the site's own helpers).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/HostAdapter.php';
require_once dirname(__DIR__) . '/src/Module.php';

use SiteActivity\HostAdapter;
use SiteActivity\Module;

/** Database timestamps are mixed in this project (SQLite datetime('now') = UTC, PHP date('c') = with offset). Normalise to UTC. */
function tk_activity_to_utc(?string $s): string
{
    $s = trim((string)$s);
    if ($s === '') return gmdate('Y-m-d H:i:s');
    if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $s)) return $s;       // SQLite default: already UTC
    $t = strtotime($s);                                                         // ISO 8601 with offset
    return $t ? gmdate('Y-m-d H:i:s', $t) : gmdate('Y-m-d H:i:s');
}

final class TicketsKenyaActivityAdapter implements HostAdapter
{
    private array $pathCache = [];

    public function pdo(): ?\PDO { return function_exists('tk_db') ? tk_db() : null; }

    public function currentUser(): ?array { return function_exists('tk_user') ? tk_user() : null; }

    /**
     * Owner = an admin account. If TK_ACTIVITY_OWNER_EMAILS is set (comma separated), only those admin emails qualify.
     * If it is not set, the first admin account ever created (lowest id) is the owner. Organisers and customers never qualify.
     */
    public function isOwner(?array $user): bool
    {
        if (!$user || ($user['role'] ?? '') !== 'admin') return false;
        $list = array_filter(array_map('strtolower', array_map('trim', explode(',', (string)(getenv('TK_ACTIVITY_OWNER_EMAILS') ?: '')))));
        if ($list) return in_array(strtolower((string)$user['email']), $list, true);
        $pdo = $this->pdo();
        if (!$pdo) return false;
        $first = (int)$pdo->query("SELECT MIN(id) FROM users WHERE role = 'admin'")->fetchColumn();
        return $first > 0 && (int)$user['id'] === $first;
    }

    public function verifyCsrf(?string $token): bool { return tk_verify_csrf($token); }
    public function csrfToken(): string { return tk_csrf_token(); }
    public function basePath(): string { return tk_base_path(); }
    public function loginUrl(): string { return tk_base_path() . '/auth/login.php'; }

    public function describePath(string $path): array
    {
        if (isset($this->pathCache[$path])) return $this->pathCache[$path];
        $out = ['path_group' => $path, 'event_ref_id' => null, 'category_ref' => null];
        if (preg_match('#^/events/([a-z0-9][a-z0-9-]{0,120})/?$#', $path, $m)) {
            $pdo = $this->pdo();
            $row = null;
            if ($pdo) {
                $st = $pdo->prepare('SELECT id, category FROM events WHERE slug = ?');
                $st->execute([$m[1]]);
                $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            $out = ['path_group' => '/events/{event}', 'event_ref_id' => $row ? (int)$row['id'] : null, 'category_ref' => $row && $row['category'] ? (string)$row['category'] : null];
            if (!$row) $out['path_group'] = '/events/{unknown}';
        } elseif ($path === '/events' || $path === '/events/') {
            $out['path_group'] = '/events/';
        } elseif ($path === '/index.php') {
            $out['path_group'] = '/';
        }
        return $this->pathCache[$path] = $out;
    }

    public function eventLabels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $pdo = $this->pdo();
        if (!$ids || !$pdo) return [];
        $st = $pdo->prepare('SELECT id, title FROM events WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['title'];
        return $out;
    }

    /** "Verified paid" = the order's own status fields say paid. In this build only server-side payment verification can set them. */
    public function paidOrders(int $sinceId, int $limit): array
    {
        $pdo = $this->pdo();
        if (!$pdo) return [];
        $st = $pdo->prepare("SELECT id, event_id, user_id, total_kes, paid_at, updated_at, created_at FROM orders
            WHERE (status = 'paid' OR payment_status = 'paid') AND id > ? ORDER BY id LIMIT " . (int)$limit);
        $st->execute([$sinceId]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $o) {
            $out[] = ['order_id' => (int)$o['id'], 'total' => (int)$o['total_kes'], 'event_id' => (int)$o['event_id'], 'user_id' => (int)$o['user_id'],
                'paid_at' => tk_activity_to_utc($o['paid_at'] ?: ($o['updated_at'] ?: $o['created_at']))];
        }
        return $out;
    }

    public function ordersCreatedBetween(string $fromUtc, string $toUtc): array
    {
        $pdo = $this->pdo();
        if (!$pdo) return [];
        $out = [];
        foreach ($pdo->query('SELECT id, event_id, status, payment_status, total_kes, created_at FROM orders')->fetchAll(\PDO::FETCH_ASSOC) as $o) {
            $c = tk_activity_to_utc($o['created_at']);
            if ($c < $fromUtc || $c >= $toUtc) continue;
            $out[] = ['id' => (int)$o['id'], 'paid' => ($o['status'] === 'paid' || $o['payment_status'] === 'paid'), 'status' => (string)$o['status'],
                'total' => (int)$o['total_kes'], 'created_at' => $c, 'event_id' => (int)$o['event_id']];
        }
        return $out;
    }

    public function paidCustomerUserIds(): array
    {
        $pdo = $this->pdo();
        if (!$pdo) return [];
        return array_map('intval', $pdo->query("SELECT DISTINCT user_id FROM orders WHERE status = 'paid' OR payment_status = 'paid'")->fetchAll(\PDO::FETCH_COLUMN));
    }
}

// ---------------------------------------------------------------------------------------------------
// Integration helpers used by the website (all of them fail silently: tracking must never break a page).
// ---------------------------------------------------------------------------------------------------

/** Boots the module for this request. Returns false when no SQL database is available (JSON fallback store). */
function tk_activity_boot(): bool
{
    static $state = null;
    if ($state !== null) return $state;
    try {
        $adapter = new TicketsKenyaActivityAdapter();
        if (!$adapter->pdo()) return $state = false;
        $cfg = [];
        $file = TK_ROOT . '/config/site-activity.php';
        if (is_file($file)) { $loaded = require $file; if (is_array($loaded)) $cfg = $loaded; }
        $cfg += ['project_id' => 'ticketskenya'];
        Module::boot($adapter, $cfg);
        if (!Module::installed()) Module::install();
        return $state = Module::installed();
    } catch (\Throwable $e) {
        error_log('[site-activity] boot failed: ' . get_class($e));
        return $state = false;
    }
}

/** Call from a page right after a real order has been created. */
function tk_activity_checkout_started(int $orderId, int $eventId, int $totalKes): void
{
    try {
        if (!tk_activity_boot()) return;
        \SiteActivity\Conversions::checkoutStarted($orderId, $eventId, $totalKes, $_SERVER, $_COOKIE, tk_user());
    } catch (\Throwable $e) { /* never break checkout */ }
}

/** Prints the consent banner and loads the tracker. Called once from templates/footer.php. */
function tk_activity_footer(): void
{
    try {
        if (!tk_activity_boot()) return;
        if (!\SiteActivity\Config::bool('tracking_enabled')) return;
        $user = tk_user();
        $staff = $user && in_array($user['role'], ['admin', 'organiser'], true) && \SiteActivity\Config::bool('exclude_admins');
        $reqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($staff || str_starts_with($reqPath, '/admin')) return;

        $consent = \SiteActivity\Privacy::consentFromCookie($_COOKIE[\SiteActivity\Privacy::COOKIE_CONSENT] ?? null);
        $allowed = \SiteActivity\Privacy::analyticsAllowed($consent);
        $state = $allowed ? 'granted' : ($consent['decided'] ? 'denied' : 'unset');
        $base = tk_base_path();
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<link rel="stylesheet" href="' . $e(tk_asset('css/activity.css')) . '">' . "\n";
        if (!$consent['decided']) {
            $notice = \SiteActivity\Config::get('consent_mode') === 'notice_only';
            echo '<div class="sa-consent" id="saConsent" role="region" aria-label="Privacy choices">'
               . '<form method="post" action="' . $e($base . '/activity/consent.php') . '">'
               . '<input type="hidden" name="_csrf" value="' . $e(tk_csrf_token()) . '">'
               . '<input type="hidden" name="next" value="' . $e($reqPath) . '">'
               . '<p><strong>Your privacy choices.</strong> ' . ($notice ? 'We count visits and clicks to improve TicketsKenya unless you say no. ' : 'With your permission we count visits, clicks and searches to improve TicketsKenya. ')
               . 'This uses two first-party cookies and never your payment details. <a href="' . $e($base . '/privacy-settings.php') . '">See what we collect and change your choice at any time</a>.</p>'
               . '<div class="sa-consent-actions">'
               . '<button type="submit" name="decision" value="accept" class="btn btn-outline" data-sa-decision="accept">Accept analytics</button>'
               . '<button type="submit" name="decision" value="reject" class="btn btn-outline" data-sa-decision="reject">Reject optional</button>'
               . '</div></form></div>' . "\n";
        }
        echo '<script src="' . $e(tk_asset('js/activity.js')) . '" defer'
           . ' data-endpoint="' . $e($base . '/activity/collect.php') . '"'
           . ' data-consent-endpoint="' . $e($base . '/activity/consent.php') . '"'
           . ' data-csrf="' . $e(tk_csrf_token()) . '"'
           . ' data-state="' . $e($state) . '"'
           . ' data-excluded="' . $e(implode(',', \SiteActivity\Config::list('excluded_paths'))) . '"></script>' . "\n";
    } catch (\Throwable $e) {
        error_log('[site-activity] footer failed: ' . get_class($e));
    }
}
