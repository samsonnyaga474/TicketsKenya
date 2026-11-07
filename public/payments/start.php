<?php
/**
 * Payment start endpoint — disabled in this build.
 * Online payments are not configured; this endpoint cannot charge anyone.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/payments.php';

$user = tk_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!tk_verify_csrf(is_string($_POST[TK_CSRF_TOKEN_NAME] ?? null) ? $_POST[TK_CSRF_TOKEN_NAME] : null)) {
    http_response_code(403);
    exit('Invalid security token.');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$order = tk_repo_find('orders', 'id', $orderId);

if (!$order || (int)$order['user_id'] !== (int)$user['id']) {
    http_response_code(404);
    $_SESSION['flash_error'] = 'Order not found.';
    tk_redirect('/tickets/');
}

if (!tk_payments_available()) {
    $_SESSION['flash_error'] = 'Online payments are disabled on this site. Your order is saved; nothing has been charged.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

// No provider integration exists yet, so this point is unreachable.
http_response_code(503);
exit('Payments are disabled.');
