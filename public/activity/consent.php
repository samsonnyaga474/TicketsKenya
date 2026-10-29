<?php
/**
 * Stores a visitor's privacy choice. State-changing, so POST + CSRF only.
 * decision=accept | reject | custom (analytics=1/0, marketing=1/0) | erase (also deletes the browser's analytics data)
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php';

use SiteActivity\Privacy;
use SiteActivity\Repository;
use SiteActivity\Retention;

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$fail = function (int $code, string $msg) use ($wantsJson) {
    http_response_code($code);
    if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); } else { echo $msg; }
    exit;
};
if (!tk_verify_csrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) $fail(403, 'Invalid security token.');
if (!tk_activity_boot()) $fail(503, 'Privacy choices are not available right now.');

$decision = (string)($_POST['decision'] ?? '');
$analytics = false; $marketing = false; $erase = false;
switch ($decision) {
    case 'accept': $analytics = true; break;
    case 'reject': break;
    case 'custom': $analytics = ($_POST['analytics'] ?? '0') === '1'; $marketing = $analytics && (($_POST['marketing'] ?? '0') === '1'); break;
    case 'erase': $erase = true; break;
    default: $fail(400, 'Unknown choice.');
}

$user = tk_user();
$vid = $_COOKIE[Privacy::COOKIE_VISITOR] ?? null;
$vid = Privacy::validId($vid) ? $vid : null;
$exp = time() + 86400 * 180;

if ($erase) {
    $counts = $vid ? Retention::eraseVisitor($vid, null) : [];
    $analytics = false; $marketing = false;
    Privacy::setCookie(Privacy::COOKIE_VISITOR, '', time() - 3600, true, $_SERVER);
    Privacy::setCookie(Privacy::COOKIE_SESSION, '', time() - 3600, true, $_SERVER);
    Repository::recordConsent(null, $user['id'] ?? null, false, false, 'erase_request');
} else {
    if ($analytics && !$vid) { $vid = Privacy::newId(); Privacy::setCookie(Privacy::COOKIE_VISITOR, $vid, $exp, true, $_SERVER); }
    if (!$analytics) { // withdrawal or refusal: stop identifiers immediately
        Privacy::setCookie(Privacy::COOKIE_VISITOR, '', time() - 3600, true, $_SERVER);
        Privacy::setCookie(Privacy::COOKIE_SESSION, '', time() - 3600, true, $_SERVER);
    }
    Repository::recordConsent($analytics ? $vid : $vid, $user['id'] ?? null, $analytics, $marketing, $decision === 'custom' ? 'settings' : 'banner_' . $decision);
}
Privacy::setCookie(Privacy::COOKIE_CONSENT, Privacy::consentCookieValue($analytics, $marketing), $exp, true, $_SERVER);

if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'analytics' => $analytics, 'marketing' => $marketing, 'erased' => $erase]); exit; }
$next = Privacy::cleanPath((string)($_POST['next'] ?? '/')) ?? '/';
if ($decision === 'custom' || $erase) { $_SESSION['flash_success'] = $erase ? 'Your analytics data for this browser was deleted and analytics is off.' : 'Your privacy choices were saved.'; $next = '/privacy-settings.php'; }
tk_redirect($next);
