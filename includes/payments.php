<?php
/**
 * Payment layer for TicketsKenya.
 *
 * No third-party payment provider is integrated in this build. All payment
 * initiation, callback, webhook, and status-verification endpoints fail
 * closed: they cannot mark orders paid or issue tickets by themselves.
 *
 * The only fulfilment path shared with any future provider is
 * tk_payment_issue_tickets(): a server-side, file-locked, idempotent
 * issuer. A provider integration may use it ONLY after the payment was
 * verified server-side.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

/**
 * Global payments switch. Disabled unless TK_PAYMENTS_ENABLED=true is set
 * by the owner in the environment / .env file.
 */
function tk_payments_available(): bool
{
    return strtolower(trim((string)(getenv('TK_PAYMENTS_ENABLED') ?: 'false'))) === 'true';
}

/**
 * Create ticket rows once and mark order paid. Safe under concurrent
 * notifications. Never callable from a browser request alone: callers MUST
 * verify payment server-side first.
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

function tk_payment_require_sqlite(): PDO
{
    $db = tk_db();
    if (!$db) {
        throw new RuntimeException('SQLite is required for payment fulfilment.');
    }
    return $db;
}
