<?php
/**
 * Pesapal IPN (Instant Payment Notification) endpoint.
 * Accepts GET or POST. Responds with the exact JSON format Pesapal expects.
 * IPN is only a signal — we always re-query GetTransactionStatus before fulfilling.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/payments.php';

header('Content-Type: application/json; charset=utf-8');

// Collect parameters from GET or POST body
$trackingId = '';
$merchantRef = '';
$notificationType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($json)) {
        $trackingId = trim((string)($json['OrderTrackingId'] ?? $json['orderTrackingId'] ?? ''));
        $merchantRef = trim((string)($json['OrderMerchantReference'] ?? $json['orderMerchantReference'] ?? ''));
        $notificationType = trim((string)($json['OrderNotificationType'] ?? $json['orderNotificationType'] ?? ''));
    }
    // Also accept form-encoded
    if ($trackingId === '') {
        $trackingId = trim((string)($_POST['OrderTrackingId'] ?? $_POST['orderTrackingId'] ?? ''));
        $merchantRef = trim((string)($_POST['OrderMerchantReference'] ?? $_POST['orderMerchantReference'] ?? ''));
        $notificationType = trim((string)($_POST['OrderNotificationType'] ?? $_POST['orderNotificationType'] ?? ''));
    }
} else {
    $trackingId = trim((string)($_GET['OrderTrackingId'] ?? $_GET['orderTrackingId'] ?? ''));
    $merchantRef = trim((string)($_GET['OrderMerchantReference'] ?? $_GET['orderMerchantReference'] ?? ''));
    $notificationType = trim((string)($_GET['OrderNotificationType'] ?? $_GET['orderNotificationType'] ?? ''));
}

$respond = function (int $status, string $tracking, string $merchant) {
    echo json_encode([
        'orderNotificationType' => 'IPNCHANGE',
        'orderTrackingId' => $tracking,
        'orderMerchantReference' => $merchant,
        'status' => $status,
    ], JSON_UNESCAPED_SLASHES);
    exit;
};

if ($trackingId === '' || $merchantRef === '') {
    http_response_code(400);
    $respond(500, $trackingId, $merchantRef);
}

if (!tk_pesapal_configured()) {
    http_response_code(503);
    $respond(500, $trackingId, $merchantRef);
}

$order = tk_repo_find('orders', 'payment_reference', $merchantRef);
if (!$order) {
    $order = tk_repo_find('orders', 'provider_transaction_id', $trackingId);
}

if (!$order) {
    // Unknown order — acknowledge so Pesapal does not retry forever, but do not create anything
    error_log('Pesapal IPN for unknown merchant_reference: ' . substr($merchantRef, 0, 40));
    $respond(200, $trackingId, $merchantRef);
}

$orderId = (int)$order['id'];

try {
    $ok = tk_payment_verify_and_fulfil($orderId, $trackingId, $merchantRef);
    // Always 200 if we processed the signal (even if still pending)
    $respond(200, $trackingId, $merchantRef);
} catch (Throwable $e) {
    error_log('Pesapal IPN processing error for order ' . $orderId . ': ' . $e->getMessage());
    http_response_code(500);
    $respond(500, $trackingId, $merchantRef);
}
