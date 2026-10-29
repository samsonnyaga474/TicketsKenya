<?php
/**
 * Convert every event to TicketsKenya-owned inventory and ensure each has sellable ticket types.
 * Safe to run repeatedly. Does not delete orders or existing ticket types.
 *
 * Usage (from project root):
 *   php tools/ensure-owned-inventory.php
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

function tk_parse_floor_price(?string $info): int
{
    $info = (string)$info;
    if ($info === '') {
        return 1000;
    }
    if (preg_match('/\bFree\b/i', $info) && !preg_match('/KES\s*[1-9]/', $info)) {
        return 0;
    }
    if (preg_match_all('/KES\s*([0-9][0-9,]*(?:\.[0-9]+)?)/i', $info, $m)) {
        $nums = array_map(static fn($x) => (int)round((float)str_replace(',', '', $x)), $m[1]);
        if ($nums) {
            return min($nums);
        }
    }
    return 1000;
}

$uploads = glob(TK_PUBLIC . '/assets/uploads/events/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
$uploadRel = array_map(static fn($p) => 'uploads/events/' . basename($p), $uploads);

$converted = 0;
$typesAdded = 0;
$postersFixed = 0;

foreach (tk_repo_all('events') as $e) {
    $id = (int)$e['id'];
    $fields = [
        'listing_type' => 'owned',
        'official_ticket_url' => '',
        'updated_at' => date('c'),
    ];
    if (empty($e['organiser_name'])) {
        $fields['organiser_name'] = 'TicketsKenya';
    }
    $img = (string)($e['cover_image'] ?? '');
    if (($img === '' || !str_starts_with($img, 'uploads/')) && $uploadRel) {
        $fields['cover_image'] = $uploadRel[$id % count($uploadRel)];
        $postersFixed++;
    }
    tk_repo_update('events', $id, $fields);
    $converted++;

    $existing = tk_repo_where('ticket_types', static fn($t) => (int)$t['event_id'] === $id);
    if (!$existing) {
        $price = tk_parse_floor_price($e['price_info'] ?? '');
        tk_repo_insert('ticket_types', [
            'event_id' => $id,
            'name' => $price === 0 ? 'Free entry' : 'General Admission',
            'price_kes' => $price,
            'quantity_total' => 200, 'quantity_sold' => 0,
            'created_at' => date('c'),
        ]);
        $typesAdded++;
        if ($price > 0) {
            tk_repo_insert('ticket_types', [
                'event_id' => $id,
                'name' => 'VIP',
                'price_kes' => (int)round($price * 2.2),
                'quantity_total' => 50, 'quantity_sold' => 0,
                'created_at' => date('c'),
            ]);
            $typesAdded++;
        }
    }
}

echo "ensure-owned-inventory finished\n";
echo "  Events set to owned : $converted\n";
echo "  Ticket types added  : $typesAdded\n";
echo "  Posters assigned    : $postersFixed\n";
exit(0);
