<?php
/**
 * Public tracking endpoint. Accepts a small JSON batch from assets/js/activity.js.
 * It never trusts the payload: see modules/site-activity/src/Collector.php.
 * It always answers quickly with an empty body so tracking can never slow down or break a page.
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
try {
    if (!tk_activity_boot()) { http_response_code(204); exit; }
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > \SiteActivity\Collector::MAX_BODY) { http_response_code(413); exit; }
    $user = tk_user();
    session_write_close(); // do not hold the session lock while we write analytics
    $body = (string)file_get_contents('php://input', false, null, 0, \SiteActivity\Collector::MAX_BODY + 1);
    $res = \SiteActivity\Collector::handle($body, $_SERVER, $_COOKIE, $user);
    http_response_code($res['status']);
} catch (\Throwable $e) {
    error_log('[site-activity] collect failed: ' . get_class($e));
    http_response_code(204);
}
exit;
