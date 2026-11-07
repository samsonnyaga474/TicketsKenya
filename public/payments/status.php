<?php
/**
 * Payment status poll endpoint — disabled in this build.
 * There is no provider to re-verify with; orders stay pending by default.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/payments.php';

$user = tk_require_login();
$orderId = (int)($_GET['order_id'] ?? 0);
$order = tk_repo_find('orders', 'id', $orderId);

if (!$order || (int)$order['user_id'] !== (int)$user['id']) {
    http_response_code(404);
    $_SESSION['flash_error'] = 'Order not found.';
    tk_redirect('/tickets/');
}

if (!tk_payments_available()) {
    $_SESSION['flash_error'] = 'Online payments are disabled on this site. Nothing has been charged.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

tk_redirect('/tickets/order.php?id=' . $orderId);
