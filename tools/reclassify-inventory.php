<?php
/**
 * Safely reclassify events between TicketsKenya-owned and discovery listings.
 *
 * Dry-run by default: nothing changes unless --apply is passed.
 * Only the events.listing_type field is written. Events, ticket types and orders
 * are never deleted; discovery listings simply lose the purchase path.
 *
 * Usage (from the project root):
 *   php tools/reclassify-inventory.php --to=discovery              # preview
 *   php tools/reclassify-inventory.php --to=discovery --apply
 *   php tools/reclassify-inventory.php --to=owned --apply
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$to = null;
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--to=')) $to = substr($arg, 5);
    if ($arg === '--apply') $apply = true;
}

if (!in_array($to, ['owned', 'discovery'], true)) {
    fwrite(STDERR, "Usage: php tools/reclassify-inventory.php --to=discovery|owned [--apply]\n");
    exit(2);
}

$targets = [];
foreach (tk_repo_all('events') as $e) {
    $type = (string)($e['listing_type'] ?? '');
    if ($type === $to) continue;
    if ($to === 'discovery') {
        // Only externally sourced rows with no organiser are flipped, so real owned inventory is untouched.
        if (empty($e['source_url']) || !empty($e['organiser_id'])) continue;
    }
    $targets[] = $e;
}

echo ($apply ? 'Applying' : 'DRY RUN: would apply') . " listing_type={$to} to " . count($targets) . " event(s).\n";
foreach ($targets as $e) {
    echo '  #' . $e['id'] . '  ' . $e['title'] . '  [' . ($e['listing_type'] ?? '') . ' -> ' . $to . "]\n";
}

if (!$apply) {
    echo "\nNothing was changed. Re-run with --apply to write these changes.\n";
    exit(0);
}

foreach ($targets as $e) {
    tk_repo_update('events', (int)$e['id'], ['listing_type' => $to, 'updated_at' => date('c')]);
}
echo "\nUpdated " . count($targets) . " event(s). Ticket types and orders were left untouched.\n";
