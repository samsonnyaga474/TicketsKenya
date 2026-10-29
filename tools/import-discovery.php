<?php
/**
 * Free local discovery importer (KSh 0)
 *
 * This script does NOT scrape restricted sites.
 * It reads a local JSON seed file (tools/discovery-seed.json)
 * and upserts discovery listings into the existing database.
 *
 * Usage (from project root):
 *   php tools/import-discovery.php
 *
 * Safe to run repeatedly — uses slug as unique key.
 * Does not touch owned events or order history.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$seedFile = dirname(__DIR__) . '/tools/discovery-seed.json';
if (!is_file($seedFile)) {
    fwrite(STDERR, "Seed file not found: $seedFile\n");
    exit(1);
}

$raw = file_get_contents($seedFile);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
    fwrite(STDERR, "Invalid seed JSON\n");
    exit(1);
}

$created = 0;
$updated = 0;
$skipped = 0;
$errors = [];

foreach ($data['events'] as $row) {
    $title = trim((string)($row['title'] ?? ''));
    $slug  = trim((string)($row['slug'] ?? ''));
    if ($title === '' || $slug === '') {
        $errors[] = 'Missing title or slug';
        $skipped++;
        continue;
    }

    $existing = tk_repo_find('events', 'slug', $slug);
    // Match title + start time before source URL. Several screenshot-derived
    // listings share a generic marketplace URL, so URL alone is not an identity.
    if (!$existing) {
        $sourceUrl = rtrim(trim((string)($row['source_url'] ?? '')), '/');
        $normalizedTitle = strtolower(preg_replace('/\s+/', ' ', $title) ?? $title);
        $urlMatches = [];
        foreach (tk_repo_all('events') as $candidate) {
            // match any listing for seed identity
            $candidateUrl = rtrim(trim((string)($candidate['source_url'] ?? '')), '/');
            $candidateTitle = strtolower(preg_replace('/\s+/', ' ', trim((string)($candidate['title'] ?? ''))) ?? '');
            $sameIdentity = $candidateTitle === $normalizedTitle && (string)($candidate['starts_at'] ?? '') === trim((string)($row['starts_at'] ?? ''));
            if ($sameIdentity) { $existing = $candidate; break; }
            if ($sourceUrl !== '' && $sourceUrl !== 'https://www.hustle.events/discover' && $candidateUrl === $sourceUrl) $urlMatches[] = $candidate;
        }
        if (!$existing && count($urlMatches) === 1) $existing = $urlMatches[0];
    }
    $fields = [
        'slug'                => $slug,
        'title'               => $title,
        'description'         => trim((string)($row['description'] ?? '')),
        'venue'               => trim((string)($row['venue'] ?? '')),
        'city'                => trim((string)($row['city'] ?? 'Nairobi')),
        'starts_at'           => trim((string)($row['starts_at'] ?? '')),
        'ends_at'             => !empty($row['ends_at']) ? trim((string)$row['ends_at']) : null,
        'cover_image'         => trim((string)($row['cover_image'] ?? 'culture-1.jpg')),
        'status'              => in_array($row['status'] ?? '', ['published','draft','cancelled'], true) ? $row['status'] : 'published',
        'listing_type'        => 'owned',
        'source_url'          => trim((string)($row['source_url'] ?? '')),
        'official_ticket_url' => '', // sales stay on TicketsKenya
        'category'            => trim((string)($row['category'] ?? '')),
        'verified_at'         => !empty($row['verified_at']) ? trim((string)$row['verified_at']) : null,
        'organiser_name'      => trim((string)($row['organiser_name'] ?? '')),
        'price_info'          => trim((string)($row['price_info'] ?? '')),
        'country_code'        => strtoupper(trim((string)($row['country_code'] ?? 'KE'))),
        'updated_at'          => date('c'),
        'is_demo'             => 0,
        'organiser_id'        => null,
    ];

    if ($fields['starts_at'] === '') {
        $errors[] = "Missing starts_at for $slug";
        $skipped++;
        continue;
    }

    if ($existing) {
        // Owner inventory: seed may refresh public fields but never clears ticket types or orders.
        unset($fields['organiser_id']);
        tk_repo_update('events', (int)$existing['id'], $fields);
        $updated++;
        $eid = (int)$existing['id'];
    } else {
        $fields['created_at'] = date('c');
        $eid = tk_repo_insert('events', $fields);
        $created++;
    }

    // Ensure every imported event has at least one sellable ticket type on TicketsKenya.
    $hasType = tk_repo_where('ticket_types', fn($tt) => (int)$tt['event_id'] === (int)$eid);
    if (!$hasType) {
        $info = (string)($row['price_info'] ?? '');
        $price = 1000;
        if (preg_match('/\bFree\b/i', $info) && !preg_match('/KES\s*[1-9]/', $info)) $price = 0;
        elseif (preg_match_all('/KES\s*([0-9][0-9,]*(?:\.[0-9]+)?)/i', $info, $m)) {
            $nums = array_map(fn($x) => (int)round((float)str_replace(',', '', $x)), $m[1]);
            if ($nums) $price = min($nums);
        }
        tk_repo_insert('ticket_types', [
            'event_id' => (int)$eid,
            'name' => $price === 0 ? 'Free entry' : 'General Admission',
            'price_kes' => $price,
            'quantity_total' => 200, 'quantity_sold' => 0,
            'created_at' => date('c'),
        ]);
        if ($price > 0) {
            tk_repo_insert('ticket_types', [
                'event_id' => (int)$eid,
                'name' => 'VIP',
                'price_kes' => (int)round($price * 2.2),
                'quantity_total' => 50, 'quantity_sold' => 0,
                'created_at' => date('c'),
            ]);
        }
    }
}

echo "Import finished\n";
echo "  Created : $created\n";
echo "  Updated : $updated\n";
echo "  Skipped : $skipped\n";
if ($errors) {
    echo "  Errors  :\n";
    foreach ($errors as $e) echo "    - $e\n";
}
exit(0);
