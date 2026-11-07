<?php
/**
 * Offline tests for payment helpers (no provider, no network calls).
 * Run: php tests/test_payments_logic.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/payments.php';

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS: $msg\n";
        $passed++;
    } else {
        echo "FAIL: $msg\n";
        $failed++;
    }
}

echo "=== TicketsKenya payment logic tests (offline) ===\n";

// Payments are disabled by default (fail closed)
assert_true(tk_payments_available() === false, 'payments unavailable without TK_PAYMENTS_ENABLED');

putenv('TK_PAYMENTS_ENABLED=true');
assert_true(tk_payments_available() === true, 'payments available when TK_PAYMENTS_ENABLED=true');
putenv('TK_PAYMENTS_ENABLED=false');
assert_true(tk_payments_available() === false, 'payments disabled when TK_PAYMENTS_ENABLED=false');

// Ticket code uniqueness shape
$c1 = tk_generate_ticket_code();
$c2 = tk_generate_ticket_code();
assert_true(strlen($c1) === 16 && ctype_xdigit($c1), 'ticket code is 16 hex chars');
assert_true($c1 !== $c2, 'ticket codes differ');

// Issue tickets is idempotent and safe on a non-existent order
assert_true(tk_payment_issue_tickets(999999, 'no-txn', 'offline-test') === false, 'fulfilment fails closed for unknown order');

echo "\nResult: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
