<?php
/**
 * Start Pesapal payment for an existing pending order.
 * Creates a unique merchant reference, submits the order server-side,
 * stores the tracking id, then redirects the customer to Pesapal.
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

$status = (string)($order['status'] ?? 'pending');
$payStatus = (string)($order['payment_status'] ?? 'unpaid');
if ($status === 'paid' || $payStatus === 'paid') {
    $_SESSION['flash_success'] = 'This order is already paid.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

if (!tk_pesapal_configured()) {
    $_SESSION['flash_error'] = 'Online payments are not configured yet. Contact support or try again later.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

$notificationId = trim((string)(getenv('PESAPAL_NOTIFICATION_ID') ?: ''));
if ($notificationId === '') {
    $_SESSION['flash_error'] = 'Payment notifications are not configured (missing IPN notification ID). Contact support.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

$amount = (float)($order['total_kes'] ?? 0);
$currency = strtoupper((string)($order['currency'] ?? 'KES'));
if ($amount <= 0 || $currency !== 'KES') {
    $_SESSION['flash_error'] = 'Invalid order total. Cannot start payment.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

$event = tk_repo_find('events', 'id', (int)$order['event_id']);
$eventTitle = $event['title'] ?? 'Event ticket';
$description = 'TicketsKenya order #' . $orderId . ' – ' . $eventTitle;

// Prefer existing reference if we already started once; otherwise create new
$merchantRef = (string)($order['payment_reference'] ?? '');
if ($merchantRef === '' || !preg_match('/^TK-\d+-[A-F0-9]+$/i', $merchantRef)) {
    $merchantRef = tk_pesapal_merchant_reference($orderId);
}

$base = rtrim(tk_canonical_base() ?: TK_APP_URL, '/');
$callbackUrl = $base . '/payments/callback.php';

// Split name for billing
$fullName = trim((string)($user['full_name'] ?? 'Customer'));
$parts = preg_split('/\s+/', $fullName, 2) ?: ['Customer'];
$first = $parts[0] ?? 'Customer';
$last = $parts[1] ?? '';

try {
    $result = tk_pesapal_submit_order(
        $orderId,
        $merchantRef,
        $amount,
        $currency,
        $description,
        $callbackUrl,
        $notificationId,
        [
            'email' => (string)($user['email'] ?? ''),
            'phone' => '', // optional; collected later if needed
            'country_code' => 'KE',
            'first_name' => $first,
            'last_name' => $last,
        ]
    );
} catch (Throwable $e) {
    error_log('Pesapal submit failed for order ' . $orderId . ': ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Could not start payment right now. Please try again in a moment.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

// Persist reference + tracking before redirect
tk_repo_update('orders', $orderId, [
    'payment_reference' => $result['merchant_reference'],
    'provider_transaction_id' => $result['order_tracking_id'],
    'payment_method' => 'Pesapal',
    'payment_status' => 'pending',
    'updated_at' => date('c'),
]);

// Full-page redirect to Pesapal hosted checkout
header('Location: ' . $result['redirect_url'], true, 302);
exit;
