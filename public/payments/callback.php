<?php
/**
 * Pesapal browser callback.
 * Never trusts the query string alone. Always re-queries GetTransactionStatus
 * and only then marks the order paid / issues tickets.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/payments.php';

$user = tk_require_login();

$trackingId = trim((string)($_GET['OrderTrackingId'] ?? $_GET['orderTrackingId'] ?? ''));
$merchantRef = trim((string)($_GET['OrderMerchantReference'] ?? $_GET['orderMerchantReference'] ?? ''));

if ($trackingId === '' || $merchantRef === '') {
    $_SESSION['flash_error'] = 'Missing payment details from the payment provider.';
    tk_redirect('/tickets/');
}

// Locate order by our merchant reference (preferred) or by stored tracking id
$order = tk_repo_find('orders', 'payment_reference', $merchantRef);
if (!$order) {
    // Fallback: search by provider_transaction_id
    $order = tk_repo_find('orders', 'provider_transaction_id', $trackingId);
}
if (!$order || (int)$order['user_id'] !== (int)$user['id']) {
    http_response_code(404);
    $_SESSION['flash_error'] = 'Order not found for this payment.';
    tk_redirect('/tickets/');
}

$orderId = (int)$order['id'];

$ok = tk_payment_verify_and_fulfil($orderId, $trackingId, $merchantRef);

if ($ok) {
    $_SESSION['flash_success'] = 'Payment verified. Your ticket is ready.';
} else {
    // Still pending (common for delayed M-Pesa) or failed
    $fresh = tk_repo_find('orders', 'id', $orderId);
    $ps = (string)($fresh['payment_status'] ?? 'pending');
    if ($ps === 'failed' || (string)($fresh['status'] ?? '') === 'failed') {
        $_SESSION['flash_error'] = 'Payment was not completed. No ticket was issued.';
    } else {
        $_SESSION['flash_success'] = 'We received your payment attempt. If M-Pesa is still processing, your ticket will appear automatically when confirmed.';
    }
}

tk_redirect('/tickets/order.php?id=' . $orderId);
