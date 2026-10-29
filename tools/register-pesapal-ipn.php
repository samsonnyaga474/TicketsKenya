<?php
/**
 * One-time helper: register the public HTTPS IPN URL with Pesapal and print the notification_id.
 * Usage (from project root, with env vars set):
 *   php tools/register-pesapal-ipn.php https://your-domain.example/payments/webhook.php
 *
 * Requires PESAPAL_CONSUMER_KEY and PESAPAL_CONSUMER_SECRET in the environment.
 * Put the printed ipn_id into PESAPAL_NOTIFICATION_ID.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/payments.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line only.\n");
    exit(1);
}

$url = $argv[1] ?? '';
if ($url === '' || !preg_match('#^https://#i', $url)) {
    fwrite(STDERR, "Usage: php tools/register-pesapal-ipn.php https://your-public-domain/payments/webhook.php\n");
    exit(1);
}

if (!tk_pesapal_configured()) {
    fwrite(STDERR, "Set PESAPAL_CONSUMER_KEY and PESAPAL_CONSUMER_SECRET first.\n");
    exit(1);
}

try {
    $resp = tk_pesapal_register_ipn($url, 'POST');
    echo "Response:\n";
    echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (!empty($resp['ipn_id'])) {
        echo "SUCCESS. Set this in your environment:\n";
        echo "PESAPAL_NOTIFICATION_ID=" . $resp['ipn_id'] . "\n";
    } else {
        echo "No ipn_id returned. Check credentials and that the URL is publicly reachable over HTTPS.\n";
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
