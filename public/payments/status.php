<?php
/**
 * Optional status poll page (customer can refresh). Re-queries Pesapal if needed.
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

$tracking = (string)($order['provider_transaction_id'] ?? '');
$ref = (string)($order['payment_reference'] ?? '');

if ($tracking !== '' && tk_pesapal_configured()) {
    tk_payment_verify_and_fulfil($orderId, $tracking, $ref !== '' ? $ref : null);
}

tk_redirect('/tickets/order.php?id=' . $orderId);
