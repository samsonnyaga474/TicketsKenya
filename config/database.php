<?php
/**
 * Data layer: prefers SQLite (PDO), falls back to JSON file store
 * when the SQLite driver is not available.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function tk_use_sqlite(): bool {
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function tk_json_path(): string {
    return TK_DATA . '/store.json';
}

function tk_json_load(): array {
    $path = tk_json_path();
    if (!is_file($path)) {
        return [
            'users' => [], 'events' => [], 'ticket_types' => [], 'orders' => [],
            'order_items' => [], 'tickets' => [], 'login_attempts' => [], 'security_events' => [],
            'seq' => ['users'=>0,'events'=>0,'ticket_types'=>0,'orders'=>0,'order_items'=>0,'tickets'=>0,'login_attempts'=>0,'security_events'=>0],
        ];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function tk_json_save(array $data): void {
    if (!is_dir(TK_DATA)) mkdir(TK_DATA, 0750, true);
    file_put_contents(tk_json_path(), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function tk_db(): ?PDO {
    if (!tk_use_sqlite()) return null;
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!is_dir(TK_DATA)) mkdir(TK_DATA, 0750, true);
    $pdo = new PDO('sqlite:' . TK_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function tk_repo_all(string $table): array {
    $db = tk_db();
    if ($db) return $db->query("SELECT * FROM {$table}")->fetchAll();
    $store = tk_json_load();
    return $store[$table] ?? [];
}

function tk_repo_find(string $table, string $field, $value): ?array {
    foreach (tk_repo_all($table) as $row) {
        if ((string)($row[$field] ?? '') === (string)$value) return $row;
    }
    return null;
}

function tk_repo_where(string $table, callable $fn): array {
    return array_values(array_filter(tk_repo_all($table), $fn));
}

function tk_repo_insert(string $table, array $row): int {
    $db = tk_db();
    if ($db) {
        $cols = array_keys($row);
        $ph = array_map(fn($c) => ':' . $c, $cols);
        $stmt = $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')');
        foreach ($row as $k => $v) $stmt->bindValue(':' . $k, $v);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }
    $store = tk_json_load();
    $store['seq'][$table] = ($store['seq'][$table] ?? 0) + 1;
    $id = $store['seq'][$table];
    $row['id'] = $id;
    $store[$table][] = $row;
    tk_json_save($store);
    return $id;
}

function tk_repo_update(string $table, int $id, array $fields): void {
    $db = tk_db();
    if ($db) {
        $sets = [];
        foreach (array_keys($fields) as $k) $sets[] = "$k = :$k";
        $stmt = $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');
        foreach ($fields as $k => $v) $stmt->bindValue(':' . $k, $v);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return;
    }
    $store = tk_json_load();
    foreach ($store[$table] as &$row) {
        if ((int)$row['id'] === $id) {
            foreach ($fields as $k => $v) $row[$k] = $v;
            break;
        }
    }
    unset($row);
    tk_json_save($store);
}

function tk_repo_delete(string $table, int $id): void {
    $db = tk_db();
    if ($db) {
        $stmt = $db->prepare('DELETE FROM ' . $table . ' WHERE id = :id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return;
    }
    $store = tk_json_load();
    $store[$table] = array_values(array_filter($store[$table] ?? [], fn($r) => (int)$r['id'] !== $id));
    tk_json_save($store);
}

/** Shorten a network address to an IPv4 /24 or IPv6 /48 prefix; null when invalid. */
function tk_ip_prefix(?string $ip): ?string {
    $ip = trim((string)$ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = @inet_pton($ip);
        if ($packed !== false) return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }
    return null;
}

/** Store a small security signal without the submitted email, full URL, or raw IP. */
function tk_security_log(string $kind, ?array $server = null): void {
    $allowed = ['login_failed', 'login_succeeded', 'login_rate_limited', 'login_csrf_rejected', 'activity_rate_limited', 'suspicious_request'];
    if (!in_array($kind, $allowed, true)) return;
    $server ??= $_SERVER;
    $prefix = tk_ip_prefix((string)($server['REMOTE_ADDR'] ?? ''));
    $at = gmdate('Y-m-d H:i:s');
    try {
        $db = tk_db();
        if ($db) {
            $recent = $db->prepare("SELECT COUNT(*) FROM security_events WHERE event_kind = ? AND ip_prefix IS ? AND julianday(occurred_at) >= julianday('now','-1 minute')");
            $recent->execute([$kind, $prefix]);
            if ((int)$recent->fetchColumn() >= 5) return; // Bound log growth during request floods.
            $db->prepare('INSERT INTO security_events (event_kind, ip_prefix, occurred_at) VALUES (?,?,?)')->execute([$kind, $prefix, $at]);
            tk_security_prune();
            return;
        }
        $store = tk_json_load();
        $store['seq'] ??= [];
        $rows = array_values(array_filter($store['security_events'] ?? [], fn($r) => strtotime((string)($r['occurred_at'] ?? '')) >= time() - 30 * 86400));
        $recentCount = count(array_filter($rows, fn($r) => ($r['event_kind'] ?? '') === $kind && ($r['ip_prefix'] ?? null) === $prefix && strtotime((string)($r['occurred_at'] ?? '')) >= time() - 60));
        if ($recentCount >= 5) return;
        $store['seq']['security_events'] = (int)($store['seq']['security_events'] ?? 0) + 1;
        $rows[] = ['id' => $store['seq']['security_events'], 'event_kind' => $kind, 'ip_prefix' => $prefix, 'occurred_at' => $at];
        $store['security_events'] = array_slice($rows, -5000);
        tk_json_save($store);
    } catch (\Throwable $e) {
        // Security logging is best-effort and must not break the protected action.
    }
}

/** Keep the security-event feed within its 30-day retention window. */
function tk_security_prune(): void {
    try {
        $db = tk_db();
        if ($db) { $db->exec("DELETE FROM security_events WHERE julianday(occurred_at) < julianday('now','-30 days')"); return; }
        $store = tk_json_load();
        $store['security_events'] = array_values(array_filter($store['security_events'] ?? [], fn($r) => strtotime((string)($r['occurred_at'] ?? '')) >= time() - 30 * 86400));
        tk_json_save($store);
    } catch (\Throwable $e) {
        // Retention failures are surfaced operationally; do not break site requests.
    }
}

/** Purge rate-limit keys after their 24-hour utility window. */
function tk_security_prune_login_attempts(): void {
    try {
        $db = tk_db();
        if ($db) { $db->exec("DELETE FROM login_attempts WHERE julianday(attempted_at) < julianday('now','-1 day')"); return; }
        $store = tk_json_load();
        $store['login_attempts'] = array_values(array_filter($store['login_attempts'] ?? [], fn($r) => strtotime((string)($r['attempted_at'] ?? '')) >= time() - 86400));
        tk_json_save($store);
    } catch (\Throwable $e) {
        // Preserve login availability if an optional cleanup fails.
    }
}

function tk_migrate_and_seed(): void {
    if (tk_use_sqlite()) { tk_migrate_sqlite(); return; }
    $store = tk_json_load();
    if (!empty($store['events'])) {
        if (defined('TK_DEMO_MODE') && TK_DEMO_MODE) {
            $users = $store['users'] ?? [];
            $emails = array_map(fn($u) => strtolower((string)($u['email'] ?? '')), $users);
            if (!in_array('demo.admin@ticketskenya.local', $emails, true)) {
                $id = (int)($store['seq']['users'] ?? 0) + 1;
                $store['seq']['users'] = $id;
                $users[] = ['id'=>$id,'email'=>'demo.admin@ticketskenya.local','password_hash'=>password_hash('DemoPass123!', PASSWORD_DEFAULT),'full_name'=>'Demo Admin','role'=>'admin','created_at'=>date('c'),'updated_at'=>date('c')];
                $store['users'] = $users; tk_json_save($store);
            }
        }
        return;
    }

    $verified = '2026-10-20';
    if (defined('TK_DEMO_MODE') && TK_DEMO_MODE) {
        $hash = password_hash('DemoPass123!', PASSWORD_DEFAULT);
        $store['seq']['users'] = 3;
        $store['users'] = [
            ['id'=>1,'email'=>'demo.organiser@ticketskenya.local','password_hash'=>$hash,'full_name'=>'Demo Organiser','role'=>'organiser','created_at'=>date('c'),'updated_at'=>date('c')],
            ['id'=>2,'email'=>'demo.customer@ticketskenya.local','password_hash'=>$hash,'full_name'=>'Demo Customer','role'=>'customer','created_at'=>date('c'),'updated_at'=>date('c')],
            ['id'=>3,'email'=>'demo.admin@ticketskenya.local','password_hash'=>$hash,'full_name'=>'Demo Admin','role'=>'admin','created_at'=>date('c'),'updated_at'=>date('c')],
        ];
    } else {
        $store['users'] = $store['users'] ?? [];
        $store['seq']['users'] = $store['seq']['users'] ?? 0;
    }

    $discovery = [
        ['id'=>1,'slug'=>'oktoberfest-2026-nairobi-street-kitchen','title'=>'Oktoberfest 2026','description'=>'Two nights of beer, German music, food and DJs at Nairobi Street Kitchen. Discovery listing; tickets via official organiser channel.','venue'=>'Nairobi Street Kitchen','city'=>'Nairobi','starts_at'=>'2026-10-23 18:00:00','ends_at'=>'2026-10-24 23:59:00','cover_image'=>'culture-1.jpg','status'=>'published','is_demo'=>0,'organiser_id'=>null,'listing_type'=>'discovery','source_url'=>'https://nairobieventsguide.com/event/oktoberfest-2026/','official_ticket_url'=>'https://nairobieventsguide.com/event/oktoberfest-2026/','category'=>'Food & Drinks','verified_at'=>$verified,'created_at'=>date('c'),'updated_at'=>date('c')],
        ['id'=>2,'slug'=>'mystika-halloween-koda-nairobi-2026','title'=>'MYSTIKA Halloween with Oscar Mbo','description'=>'Halloween edition at KODA Nairobi. Discovery listing only.','venue'=>'KODA Nairobi','city'=>'Nairobi','starts_at'=>'2026-10-31 21:00:00','ends_at'=>null,'cover_image'=>'culture-2.jpg','status'=>'published','is_demo'=>0,'organiser_id'=>null,'listing_type'=>'discovery','source_url'=>'https://nairobieventsguide.com/upcoming-events/','official_ticket_url'=>'https://nairobieventsguide.com/upcoming-events/','category'=>'Concerts & Live Music','verified_at'=>$verified,'created_at'=>date('c'),'updated_at'=>date('c')],
        ['id'=>3,'slug'=>'turbo-nights-halloween-junction-2026','title'=>'Turbo Nights — Halloween Edition','description'=>'Turbo Nights Halloween edition at The Junction Mall rooftop. Discovery listing only.','venue'=>'The Junction Mall','city'=>'Nairobi','starts_at'=>'2026-10-31 19:00:00','ends_at'=>null,'cover_image'=>'culture-3.jpg','status'=>'published','is_demo'=>0,'organiser_id'=>null,'listing_type'=>'discovery','source_url'=>'https://nairobieventsguide.com/upcoming-events/','official_ticket_url'=>'https://nairobieventsguide.com/upcoming-events/','category'=>'Concerts & Live Music','verified_at'=>$verified,'created_at'=>date('c'),'updated_at'=>date('c')],
        ['id'=>4,'slug'=>'international-mediation-conference-2026','title'=>'International Mediation Conference 2026','description'=>'ICMC conference at Golden Tulip Westlands. Discovery listing.','venue'=>'Golden Tulip Westlands','city'=>'Nairobi','starts_at'=>'2026-10-29 08:00:00','ends_at'=>'2026-10-30 17:00:00','cover_image'=>'culture-4.jpg','status'=>'published','is_demo'=>0,'organiser_id'=>null,'listing_type'=>'discovery','source_url'=>'https://nairobieventsguide.com/event/international-mediation-conference-2026/','official_ticket_url'=>'https://icmc.or.ke/conference#register','category'=>'Networking & Conferences','verified_at'=>$verified,'created_at'=>date('c'),'updated_at'=>date('c')],
        ['id'=>5,'slug'=>'devfest-nairobi-2026','title'=>'DevFest Nairobi 2026','description'=>'GDG Nairobi DevFest at Sarit Expo Centre. Discovery listing.','venue'=>'Sarit Expo Centre','city'=>'Nairobi','starts_at'=>'2026-11-14 08:00:00','ends_at'=>'2026-11-14 18:00:00','cover_image'=>'culture-5.jpg','status'=>'published','is_demo'=>0,'organiser_id'=>null,'listing_type'=>'discovery','source_url'=>'https://nairobieventsguide.com/event/devfest-nairobi-2026/','official_ticket_url'=>'https://nairobieventsguide.com/event/devfest-nairobi-2026/','category'=>'Networking & Conferences','verified_at'=>$verified,'created_at'=>date('c'),'updated_at'=>date('c')],
        ['id'=>6,'slug'=>'nation-digital-summit-2026-grand-summit','title'=>'Nation Digital Summit 2026: Grand Summit','description'=>'Nation Media Group digital summit. Discovery listing.','venue'=>'KOFISI Kaskazi','city'=>'Nairobi','starts_at'=>'2026-10-27 09:00:00','ends_at'=>'2026-10-28 18:00:00','cover_image'=>'culture-1.jpg','status'=>'published','is_demo'=>0,'organiser_id'=>null,'listing_type'=>'discovery','source_url'=>'https://nairobieventsguide.com/event/nation-digital-summit-2026-grand-summit/','official_ticket_url'=>'https://nairobieventsguide.com/event/nation-digital-summit-2026-grand-summit/','category'=>'Networking & Conferences','verified_at'=>$verified,'created_at'=>date('c'),'updated_at'=>date('c')],
    ];
    $store['seq']['events'] = count($discovery);
    $store['events'] = $discovery;
    // Discovery listings have no TicketKenya-owned inventory.
    $store['ticket_types'] = [];
    $store['seq']['ticket_types'] = 0;
    $store['orders']=[]; $store['order_items']=[]; $store['tickets']=[]; $store['login_attempts']=[];
    tk_json_save($store);
}

function tk_migrate_sqlite(): void {
    $db = tk_db();
    if (!$db) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL, full_name TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'customer', created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT NOT NULL DEFAULT (datetime('now')));
        CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, title TEXT NOT NULL, description TEXT NOT NULL, venue TEXT NOT NULL, city TEXT NOT NULL DEFAULT 'Nairobi', starts_at TEXT NOT NULL, ends_at TEXT, cover_image TEXT, status TEXT NOT NULL DEFAULT 'published', is_demo INTEGER NOT NULL DEFAULT 0, organiser_id INTEGER, listing_type TEXT NOT NULL DEFAULT 'owned', source_url TEXT, official_ticket_url TEXT, category TEXT, verified_at TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT NOT NULL DEFAULT (datetime('now')));
        CREATE TABLE IF NOT EXISTS ticket_types (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER NOT NULL, name TEXT NOT NULL, price_kes INTEGER NOT NULL, quantity_total INTEGER NOT NULL, quantity_sold INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')));
        CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, event_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', total_kes INTEGER NOT NULL DEFAULT 0, payment_reference TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT NOT NULL DEFAULT (datetime('now')));
        CREATE TABLE IF NOT EXISTS order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, ticket_type_id INTEGER NOT NULL, quantity INTEGER NOT NULL, unit_price_kes INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, order_item_id INTEGER NOT NULL, ticket_code TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'valid', issued_at TEXT, used_at TEXT);
        CREATE TABLE IF NOT EXISTS contact_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, subject TEXT NOT NULL, message TEXT NOT NULL, ip_address TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')), handled INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, ip_address TEXT NOT NULL, attempted_at TEXT NOT NULL DEFAULT (datetime('now')));
        CREATE TABLE IF NOT EXISTS security_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_kind TEXT NOT NULL, ip_prefix TEXT, occurred_at TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_security_events_at ON security_events(occurred_at);
    ");
    // Additive migration for payment tracking. Existing databases are preserved.
    $orderColumns = array_column($db->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    $paymentColumns = [
        'payment_method' => "TEXT",
        'payment_status' => "TEXT NOT NULL DEFAULT 'unpaid'",
        'provider_transaction_id' => "TEXT",
        'paid_at' => "TEXT",
        'subtotal_kes' => "INTEGER NOT NULL DEFAULT 0",
        'fee_kes' => "INTEGER NOT NULL DEFAULT 0",
        'tax_kes' => "INTEGER NOT NULL DEFAULT 0",
        'currency' => "TEXT NOT NULL DEFAULT 'KES'",
    ];
    foreach ($paymentColumns as $column => $definition) {
        if (!in_array($column, $orderColumns, true)) {
            $db->exec("ALTER TABLE orders ADD COLUMN {$column} {$definition}");
        }
    }
    // Ensure ticket_types stock columns exist on older databases
    $ttColumns = array_column($db->query('PRAGMA table_info(ticket_types)')->fetchAll(), 'name');
    $ttExtras = [
        'quantity_total' => "INTEGER NOT NULL DEFAULT 0",
        'quantity_sold' => "INTEGER NOT NULL DEFAULT 0",
    ];
    foreach ($ttExtras as $column => $definition) {
        if (!in_array($column, $ttColumns, true)) {
            $db->exec("ALTER TABLE ticket_types ADD COLUMN {$column} {$definition}");
        }
    }

    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_payment_reference ON orders(payment_reference) WHERE payment_reference IS NOT NULL AND payment_reference <> ''");

    // Discovery-listing columns (safe additive migration)
    $eventColumns = array_column($db->query('PRAGMA table_info(events)')->fetchAll(), 'name');
    $eventExtras = [
        'listing_type' => "TEXT NOT NULL DEFAULT 'discovery'",
        'source_url' => "TEXT",
        'official_ticket_url' => "TEXT",
        'category' => "TEXT",
        'verified_at' => "TEXT",
        'organiser_name' => "TEXT",
        'price_info' => "TEXT",
        'country_code' => "TEXT NOT NULL DEFAULT 'KE'",
        'archived_at' => "TEXT",
        'source_id' => "TEXT",
        'external_id' => "TEXT",
        'missed_runs' => "INTEGER NOT NULL DEFAULT 0",
        'needs_review' => "INTEGER NOT NULL DEFAULT 0",
        'review_reason' => "TEXT",
    ];
    foreach ($eventExtras as $column => $definition) {
        if (!in_array($column, $eventColumns, true)) {
            $db->exec("ALTER TABLE events ADD COLUMN {$column} {$definition}");
        }
    }

    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_events_source_external ON events(source_id, external_id) WHERE source_id IS NOT NULL AND external_id IS NOT NULL");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_events_starts ON events(starts_at)");

    // Optional admin two-factor authentication (additive; existing accounts stay password-only until enrolled).
    $userColumns = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('totp_secret', $userColumns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT');
    }

    // Ensure the local demo accounts exist even when upgrading an already-seeded database.
    // This block is never active in production unless explicitly misconfigured.
    if (defined('TK_DEMO_MODE') && TK_DEMO_MODE) {
        $hash = password_hash('DemoPass123!', PASSWORD_DEFAULT);
        $db->prepare('INSERT OR IGNORE INTO users (email, password_hash, full_name, role) VALUES (?,?,?,?)')->execute(['demo.organiser@ticketskenya.local', $hash, 'Demo Organiser', 'organiser']);
        $db->prepare('INSERT OR IGNORE INTO users (email, password_hash, full_name, role) VALUES (?,?,?,?)')->execute(['demo.customer@ticketskenya.local', $hash, 'Demo Customer', 'customer']);
        $db->prepare('INSERT OR IGNORE INTO users (email, password_hash, full_name, role) VALUES (?,?,?,?)')->execute(['demo.admin@ticketskenya.local', $hash, 'Demo Admin', 'admin']);
    }

    $count = (int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();
    if ($count > 0) return;

    // Real upcoming Kenyan events for DISCOVERY only.
    // TicketKenya does not sell tickets for these unless an authorised inventory agreement exists.
    // Each row includes public source URL and verification date (Africa/Nairobi).
    $verified = '2026-10-20';
    $discovery = [
        [
            'slug' => 'oktoberfest-2026-nairobi-street-kitchen',
            'title' => 'Oktoberfest 2026',
            'description' => 'Two nights of beer, German music, food and DJs at Nairobi Street Kitchen. Gates open 6 PM. Public listing for discovery; tickets via the official organiser channel.',
            'venue' => 'Nairobi Street Kitchen',
            'city' => 'Nairobi',
            'starts_at' => '2026-10-23 18:00:00',
            'ends_at' => '2026-10-24 23:59:00',
            'cover_image' => 'culture-1.jpg',
            'category' => 'Food & Drinks',
            'source_url' => 'https://nairobieventsguide.com/event/oktoberfest-2026/',
            'official_ticket_url' => 'https://nairobieventsguide.com/event/oktoberfest-2026/',
        ],
        [
            'slug' => 'mystika-halloween-koda-nairobi-2026',
            'title' => 'MYSTIKA Halloween with Oscar Mbo',
            'description' => 'Halloween edition at KODA Nairobi. Public discovery listing; purchase tickets through the official organiser page.',
            'venue' => 'KODA Nairobi',
            'city' => 'Nairobi',
            'starts_at' => '2026-10-31 21:00:00',
            'ends_at' => null,
            'cover_image' => 'culture-2.jpg',
            'category' => 'Concerts & Live Music',
            'source_url' => 'https://nairobieventsguide.com/upcoming-events/',
            'official_ticket_url' => 'https://nairobieventsguide.com/upcoming-events/',
        ],
        [
            'slug' => 'turbo-nights-halloween-junction-2026',
            'title' => 'Turbo Nights — Halloween Edition',
            'description' => 'Turbo Nights returns for a Halloween edition at The Junction Mall rooftop. Discovery listing only.',
            'venue' => 'The Junction Mall',
            'city' => 'Nairobi',
            'starts_at' => '2026-10-31 19:00:00',
            'ends_at' => null,
            'cover_image' => 'culture-3.jpg',
            'category' => 'Concerts & Live Music',
            'source_url' => 'https://nairobieventsguide.com/upcoming-events/',
            'official_ticket_url' => 'https://nairobieventsguide.com/upcoming-events/',
        ],
        [
            'slug' => 'international-mediation-conference-2026',
            'title' => 'International Mediation Conference 2026',
            'description' => 'Two-day conference hosted by ICMC with the Africa Mediation Association at Golden Tulip Westlands. Registration fees published by the organiser. Discovery listing.',
            'venue' => 'Golden Tulip Westlands',
            'city' => 'Nairobi',
            'starts_at' => '2026-10-29 08:00:00',
            'ends_at' => '2026-10-30 17:00:00',
            'cover_image' => 'culture-4.jpg',
            'category' => 'Networking & Conferences',
            'source_url' => 'https://nairobieventsguide.com/event/international-mediation-conference-2026/',
            'official_ticket_url' => 'https://icmc.or.ke/conference#register',
        ],
        [
            'slug' => 'devfest-nairobi-2026',
            'title' => 'DevFest Nairobi 2026',
            'description' => 'GDG Nairobi DevFest: Build. Secure. Scale. At Sarit Expo Centre. Discovery listing; tickets via official organiser channels.',
            'venue' => 'Sarit Expo Centre',
            'city' => 'Nairobi',
            'starts_at' => '2026-11-14 08:00:00',
            'ends_at' => '2026-11-14 18:00:00',
            'cover_image' => 'culture-5.jpg',
            'category' => 'Networking & Conferences',
            'source_url' => 'https://nairobieventsguide.com/event/devfest-nairobi-2026/',
            'official_ticket_url' => 'https://nairobieventsguide.com/event/devfest-nairobi-2026/',
        ],
        [
            'slug' => 'nation-digital-summit-2026-grand-summit',
            'title' => 'Nation Digital Summit 2026: Grand Summit',
            'description' => 'Nation Media Group summit on culture, platforms and digital leadership in Africa. Discovery listing.',
            'venue' => 'KOFISI Kaskazi',
            'city' => 'Nairobi',
            'starts_at' => '2026-10-27 09:00:00',
            'ends_at' => '2026-10-28 18:00:00',
            'cover_image' => 'culture-1.jpg',
            'category' => 'Networking & Conferences',
            'source_url' => 'https://nairobieventsguide.com/event/nation-digital-summit-2026-grand-summit/',
            'official_ticket_url' => 'https://nairobieventsguide.com/event/nation-digital-summit-2026-grand-summit/',
        ],
    ];

    $es = $db->prepare('INSERT INTO events (slug,title,description,venue,city,starts_at,ends_at,cover_image,status,is_demo,organiser_id,listing_type,source_url,official_ticket_url,category,verified_at) VALUES (?,?,?,?,?,?,?,?,\'published\',0,NULL,\'discovery\',?,?,?,?)');
    foreach ($discovery as $e) {
        $es->execute([
            $e['slug'], $e['title'], $e['description'], $e['venue'], $e['city'],
            $e['starts_at'], $e['ends_at'], $e['cover_image'],
            $e['source_url'], $e['official_ticket_url'], $e['category'], $verified,
        ]);
    }
}
