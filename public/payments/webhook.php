<?php
/**
 * Payment notification (webhook) endpoint — disabled in this build.
 * payments_enabled is intentionally false: there is no provider integrated,
 * so nothing arriving here can mark an order paid or issue tickets.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/payments.php';

header('Content-Type: application/json; charset=utf-8');

$payments_enabled = false; // no provider integrated in this build

if (!$payments_enabled) {
    // 410 Gone (fail-closed): no provider notifications are ever accepted here.
    http_response_code(410);
    echo json_encode(['status' => 'disabled'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Unreachable in this build: no provider notifications are processed.
http_response_code(410);
echo json_encode(['status' => 'disabled'], JSON_UNESCAPED_SLASHES);
exit;
