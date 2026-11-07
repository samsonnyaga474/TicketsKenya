<?php
/**
 * Payment callback endpoint — disabled in this build.
 * Browser redirects can never mark an order paid here.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/payments.php';

$user = tk_require_login();

$iid = trim((string)($_GET['order_id'] ?? ''));
$order = tk_repo_find('orders', 'id', (int)$iid);

if (!$order || (int)$order['user_id'] !== (int)$user['id']) {
    http_response_code(404);
    $_SESSION['flash_error'] = 'Order not found.';
    tk_redirect('/tickets/');
}

$_SESSION['flash_error'] = 'Online payments are disabled on this site. No payment was processed and no ticket was issued.';
tk_redirect('/tickets/order.php?id=' . (int)$order['id']);
