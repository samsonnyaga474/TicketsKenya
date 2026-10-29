<?php
/**
 * Pesapal API 3.0 (JSON) integration for TicketsKenya.
 *
 * Secrets stay on the server (environment variables only).
 * Payment is never trusted from the browser or from an unauthenticated redirect.
 * Only a successful server-side GetTransactionStatus response with matching
 * merchant_reference, amount, currency and status_code=1 (COMPLETED) may
 * mark an order paid and issue tickets. Processing is idempotent.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

/** True when consumer key + secret are present (sandbox or live). */
function tk_pesapal_configured(): bool
{
    $key = trim((string)(getenv('PESAPAL_CONSUMER_KEY') ?: ''));
    $secret = trim((string)(getenv('PESAPAL_CONSUMER_SECRET') ?: ''));
    return $key !== '' && $secret !== '';
}

/** Sandbox unless PESAPAL_ENV=live and live credentials are used. */
function tk_pesapal_is_live(): bool
{
    return strtolower(trim((string)(getenv('PESAPAL_ENV') ?: 'sandbox'))) === 'live';
}

function tk_pesapal_base_url(): string
{
    return tk_pesapal_is_live()
        ? 'https://pay.pesapal.com/v3'
        : 'https://cybqa.pesapal.com/pesapalv3';
}

/**
 * Obtain a short-lived bearer token (cached in APCu or file for ~4 minutes).
 * Never logs the token or secret.
 */
function tk_pesapal_get_token(): string
{
    $cacheKey = 'tk_pesapal_token_' . (tk_pesapal_is_live() ? 'live' : 'sandbox');
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
    }
    $cacheFile = TK_DATA . '/.pesapal_token_cache.json';
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)
            && !empty($data['token'])
            && !empty($data['expires_at'])
            && (int)$data['expires_at'] > time() + 30
            && ($data['env'] ?? '') === (tk_pesapal_is_live() ? 'live' : 'sandbox')
        ) {
            return (string)$data['token'];
        }
    }

    $key = trim((string)(getenv('PESAPAL_CONSUMER_KEY') ?: ''));
    $secret = trim((string)(getenv('PESAPAL_CONSUMER_SECRET') ?: ''));
    if ($key === '' || $secret === '') {
        throw new RuntimeException('Pesapal credentials are not configured.');
    }

    $url = tk_pesapal_base_url() . '/api/Auth/RequestToken';
    $payload = json_encode([
        'consumer_key' => $key,
        'consumer_secret' => $secret,
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialise HTTP client for Pesapal.');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || !is_string($body)) {
        throw new RuntimeException('Pesapal authentication network error.');
    }
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['token']) || (string)($json['status'] ?? '') !== '200') {
        throw new RuntimeException('Pesapal authentication failed (HTTP ' . $http . ').');
    }
    $token = (string)$json['token'];

    $expiresAt = time() + 240;
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $token, 240);
    }
    if (!is_dir(TK_DATA)) {
        @mkdir(TK_DATA, 0750, true);
    }
    @file_put_contents($cacheFile, json_encode([
        'token' => $token,
        'expires_at' => $expiresAt,
        'env' => tk_pesapal_is_live() ? 'live' : 'sandbox',
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    @chmod($cacheFile, 0640);

    return $token;
}

/**
 * Low-level authenticated request to Pesapal.
 */
function tk_pesapal_request(string $method, string $path, ?array $payload = null): array
{
    $token = tk_pesapal_get_token();
    $url = tk_pesapal_base_url() . $path;
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialise HTTP client for Pesapal.');
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $payload !== null
            ? json_encode($payload, JSON_THROW_ON_ERROR)
            : '{}';
    } else {
        $opts[CURLOPT_HTTPGET] = true;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || !is_string($body)) {
        throw new RuntimeException('Pesapal API network error.');
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Pesapal API returned non-JSON (HTTP ' . $http . ').');
    }
    $json['_http_status'] = $http;
    return $json;
}

/**
 * Register the IPN URL. Call from a secure CLI after credentials + public HTTPS URL are ready.
 * Put the returned ipn_id into PESAPAL_NOTIFICATION_ID.
 */
function tk_pesapal_register_ipn(string $ipnUrl, string $type = 'POST'): array
{
    $type = strtoupper($type) === 'GET' ? 'GET' : 'POST';
    return tk_pesapal_request('POST', '/api/URLSetup/RegisterIPN', [
        'url' => $ipnUrl,
        'ipn_notification_type' => $type,
    ]);
}

/**
 * Unique merchant reference for an order (alphanumeric + dash, ≤50).
 */
function tk_pesapal_merchant_reference(int $orderId): string
{
    return 'TK-' . $orderId . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Submit an order to Pesapal.
 */
function tk_pesapal_submit_order(
    int $orderId,
    string $merchantReference,
    float $amount,
    string $currency,
    string $description,
    string $callbackUrl,
    string $notificationId,
    array $billing
): array {
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be positive.');
    }
    $payload = [
        'id' => $merchantReference,
        'currency' => $currency,
        'amount' => round($amount, 2),
        'description' => mb_substr($description, 0, 100),
        'callback_url' => $callbackUrl,
        'notification_id' => $notificationId,
        'redirect_mode' => 'TOP_WINDOW',
        'billing_address' => [
            'email_address' => (string)($billing['email'] ?? ''),
            'phone_number' => (string)($billing['phone'] ?? ''),
            'country_code' => (string)($billing['country_code'] ?? 'KE'),
            'first_name' => (string)($billing['first_name'] ?? ''),
            'last_name' => (string)($billing['last_name'] ?? ''),
        ],
    ];
    $resp = tk_pesapal_request('POST', '/api/Transactions/SubmitOrderRequest', $payload);
    if (empty($resp['order_tracking_id']) || empty($resp['redirect_url'])) {
        $msg = is_array($resp['error'] ?? null)
            ? ((string)($resp['error']['message'] ?? 'Submit order failed'))
            : 'Submit order failed';
        throw new RuntimeException($msg);
    }
    return [
        'order_tracking_id' => (string)$resp['order_tracking_id'],
        'merchant_reference' => (string)($resp['merchant_reference'] ?? $merchantReference),
        'redirect_url' => (string)$resp['redirect_url'],
    ];
}

/**
 * Query transaction status by Pesapal order_tracking_id.
 */
function tk_pesapal_get_status(string $orderTrackingId): array
{
    $orderTrackingId = trim($orderTrackingId);
    if ($orderTrackingId === '' || !preg_match('/^[a-f0-9\-]{20,60}$/i', $orderTrackingId)) {
        throw new InvalidArgumentException('Invalid order tracking id.');
    }
    $path = '/api/Transactions/GetTransactionStatus?orderTrackingId=' . rawurlencode($orderTrackingId);
    return tk_pesapal_request('GET', $path);
}

/**
 * Idempotent fulfilment: verify Pesapal status server-side, match order, mark paid, issue tickets once.
 */
function tk_payment_verify_and_fulfil(int $orderId, string $orderTrackingId, ?string $expectedMerchantRef = null): bool
{
    $order = tk_repo_find('orders', 'id', $orderId);
    if (!$order) {
        return false;
    }

    $status = (string)($order['status'] ?? '');
    $payStatus = (string)($order['payment_status'] ?? '');
    if ($status === 'paid' || $payStatus === 'paid') {
        return true;
    }

    $tracking = trim($orderTrackingId);
    if ($tracking === '') {
        return false;
    }

    try {
        $remote = tk_pesapal_get_status($tracking);
    } catch (Throwable $e) {
        error_log('Pesapal status query failed for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }

    $statusCode = (int)($remote['status_code'] ?? -1);
    $remoteAmount = (float)($remote['amount'] ?? 0);
    $remoteCurrency = strtoupper(trim((string)($remote['currency'] ?? '')));
    $remoteMerchant = (string)($remote['merchant_reference'] ?? '');
    $paymentMethod = (string)($remote['payment_method'] ?? 'Pesapal');
    $confirmation = (string)($remote['confirmation_code'] ?? '');

    $localRef = (string)($order['payment_reference'] ?? '');
    if ($expectedMerchantRef !== null && $expectedMerchantRef !== '') {
        if (!hash_equals($expectedMerchantRef, $remoteMerchant) && !hash_equals($localRef, $remoteMerchant)) {
            error_log('Pesapal merchant_reference mismatch for order ' . $orderId);
            return false;
        }
    } elseif ($localRef !== '' && $remoteMerchant !== '' && !hash_equals($localRef, $remoteMerchant)) {
        error_log('Pesapal merchant_reference mismatch for order ' . $orderId);
        return false;
    }

    $expectedAmount = (float)($order['total_kes'] ?? 0);
    $expectedCurrency = strtoupper((string)($order['currency'] ?? 'KES'));

    if (abs($remoteAmount - $expectedAmount) > 0.01 || $remoteCurrency !== $expectedCurrency) {
        error_log(sprintf(
            'Pesapal amount/currency mismatch order %d: expected %.2f %s got %.2f %s',
            $orderId,
            $expectedAmount,
            $expectedCurrency,
            $remoteAmount,
            $remoteCurrency
        ));
        tk_repo_update('orders', $orderId, [
            'payment_status' => 'failed',
            'provider_transaction_id' => $tracking,
            'updated_at' => date('c'),
        ]);
        return false;
    }

    if ($statusCode === 1) {
        return tk_payment_issue_tickets(
            $orderId,
            $tracking,
            $paymentMethod,
            $confirmation,
            $remoteMerchant !== '' ? $remoteMerchant : $localRef
        );
    }

    if ($statusCode === 2) {
        tk_repo_update('orders', $orderId, [
            'status' => 'failed',
            'payment_status' => 'failed',
            'provider_transaction_id' => $tracking,
            'updated_at' => date('c'),
        ]);
        return false;
    }

    if ($statusCode === 3) {
        tk_repo_update('orders', $orderId, [
            'status' => 'reversed',
            'payment_status' => 'reversed',
            'provider_transaction_id' => $tracking,
            'updated_at' => date('c'),
        ]);
        return false;
    }

    if ($tracking !== '' && (string)($order['provider_transaction_id'] ?? '') === '') {
        tk_repo_update('orders', $orderId, [
            'provider_transaction_id' => $tracking,
            'updated_at' => date('c'),
        ]);
    }
    return false;
}

/**
 * Create ticket rows once and mark order paid. Safe under concurrent IPN + callback.
 */
function tk_payment_issue_tickets(
    int $orderId,
    string $transactionId,
    string $method,
    string $confirmationCode = '',
    string $merchantReference = ''
): bool {
    $db = tk_db();
    $lock = @fopen(TK_DATA . '/order-fulfil.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return false;
    }

    try {
        $order = tk_repo_find('orders', 'id', $orderId);
        if (!$order) {
            return false;
        }
        if ((string)($order['status'] ?? '') === 'paid' || (string)($order['payment_status'] ?? '') === 'paid') {
            return true;
        }

        if ($db) {
            $db->exec('BEGIN IMMEDIATE');
        }

        $lines = tk_repo_where('order_items', fn($i) => (int)$i['order_id'] === $orderId);
        $existingTickets = tk_repo_where('tickets', fn($t) => (int)$t['order_id'] === $orderId);
        if (count($existingTickets) > 0) {
            tk_repo_update('orders', $orderId, [
                'status' => 'paid',
                'payment_status' => 'paid',
                'payment_method' => $method,
                'provider_transaction_id' => $transactionId,
                'payment_reference' => $merchantReference !== '' ? $merchantReference : ($order['payment_reference'] ?? null),
                'paid_at' => date('c'),
                'updated_at' => date('c'),
            ]);
            if ($db && $db->inTransaction()) {
                $db->commit();
            }
            return true;
        }

        foreach ($lines as $line) {
            $qty = (int)$line['quantity'];
            for ($i = 0; $i < $qty; $i++) {
                $code = tk_generate_ticket_code();
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        tk_repo_insert('tickets', [
                            'order_id' => $orderId,
                            'order_item_id' => (int)$line['id'],
                            'ticket_code' => $code,
                            'status' => 'valid',
                            'issued_at' => date('c'),
                        ]);
                        break;
                    } catch (Throwable $e) {
                        $code = tk_generate_ticket_code();
                    }
                }
            }
        }

        $fields = [
            'status' => 'paid',
            'payment_status' => 'paid',
            'payment_method' => $method,
            'provider_transaction_id' => $transactionId,
            'paid_at' => date('c'),
            'updated_at' => date('c'),
        ];
        if ($merchantReference !== '') {
            $fields['payment_reference'] = $merchantReference;
        }
        tk_repo_update('orders', $orderId, $fields);

        if ($db && $db->inTransaction()) {
            $db->commit();
        }

        if (is_file(TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php')) {
            require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php';
            if (function_exists('tk_activity_purchase_completed')) {
                @tk_activity_purchase_completed($orderId, (int)($order['event_id'] ?? 0), (int)($order['total_kes'] ?? 0));
            }
        }

        return true;
    } catch (Throwable $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Ticket issuance failed for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Legacy names kept so old callers do not break. */
function tk_flw_sandbox_ready(): bool
{
    return tk_pesapal_configured() && !tk_pesapal_is_live();
}

function tk_flw_request(string $method, string $path, ?array $payload = null): array
{
    throw new RuntimeException('Legacy Flutterwave path removed. Use Pesapal helpers.');
}

function tk_payment_require_sqlite(): PDO
{
    $db = tk_db();
    if (!$db) {
        throw new RuntimeException('SQLite is required for payment fulfilment.');
    }
    return $db;
}
