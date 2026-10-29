<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = tk_require_role('organiser', 'admin');
$isAdmin = $user['role'] === 'admin';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$event = $id > 0 ? tk_repo_find('events', 'id', $id) : null;
if ($id > 0 && !$event) {
    http_response_code(404);
    $_SESSION['flash_error'] = 'Event not found.';
    tk_redirect('/admin/events.php');
}
if ($event) {
    $mine = ($event['listing_type'] ?? '') === 'owned' && (int)($event['organiser_id'] ?? 0) === (int)$user['id'];
    if (!$isAdmin && !$mine) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'You are not allowed to edit that event.';
        tk_redirect('/admin/events.php');
    }
}

$categories = ['Concerts & Live Music', 'Food & Drinks', 'Nightlife & Parties', 'Networking & Conferences', 'Arts & Culture', 'Sports & Fitness', 'Comedy', 'Other'];
$types = $event ? tk_repo_where('ticket_types', fn($t) => (int)$t['event_id'] === (int)$event['id']) : [];
usort($types, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);

$errors = [];
$f = [
    'title' => $event['title'] ?? '',
    'description' => $event['description'] ?? '',
    'category' => $event['category'] ?? 'Other',
    'city' => $event['city'] ?? 'Nairobi',
    'venue' => $event['venue'] ?? '',
    'starts_at' => isset($event['starts_at']) ? date('Y-m-d\TH:i', strtotime($event['starts_at'])) : '',
    'ends_at' => !empty($event['ends_at']) ? date('Y-m-d\TH:i', strtotime($event['ends_at'])) : '',
    'status' => $event['status'] ?? 'draft',
    'organiser_name' => $event['organiser_name'] ?? '',
    'listing_type' => $event['listing_type'] ?? 'owned',
    'source_url' => $event['source_url'] ?? '',
    'official_ticket_url' => $event['official_ticket_url'] ?? '',
    'price_info' => $event['price_info'] ?? '',
];
$rows = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'price' => (int)$t['price_kes'], 'qty' => (int)$t['quantity_total'], 'sold' => (int)$t['quantity_sold']], $types);

function tk_clean_url(string $u): ?string
{
    $u = trim($u);
    if ($u === '') return '';
    return (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u)) ? $u : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[TK_CSRF_TOKEN_NAME] ?? null;
    if (!tk_verify_csrf(is_string($token) ? $token : null)) {
        http_response_code(400);
        $errors[] = 'Invalid security token. Please reload the page and try again.';
    } else {
        foreach (array_keys($f) as $k) {
            if (isset($_POST[$k]) && is_string($_POST[$k])) $f[$k] = trim($_POST[$k]);
        }
        if (!$isAdmin) { // organisers can only make their own events
            $f['listing_type'] = 'owned';
        }
        $owned = $f['listing_type'] === 'owned';

        if (tk_strlen($f['title']) < 3 || tk_strlen($f['title']) > 120) $errors[] = 'Title must be 3 to 120 characters.';
        if (tk_strlen($f['description']) < 10 || tk_strlen($f['description']) > 5000) $errors[] = 'Description must be 10 to 5000 characters.';
        if (!in_array($f['category'], $categories, true)) $errors[] = 'Please choose a category.';
        if ($f['venue'] === '' || tk_strlen($f['venue']) > 120) $errors[] = 'Venue is required (max 120 characters).';
        if ($f['city'] === '' || tk_strlen($f['city']) > 60) $errors[] = 'City is required (max 60 characters).';
        if (!in_array($f['status'], tk_event_statuses(), true)) $errors[] = 'Invalid status.';
        if (tk_strlen($f['organiser_name']) > 120) $errors[] = 'Organiser name is too long.';
        if (tk_strlen($f['price_info']) > 200) $errors[] = 'Price note is too long.';

        $startTs = strtotime($f['starts_at']);
        $endTs = $f['ends_at'] !== '' ? strtotime($f['ends_at']) : null;
        if (!$startTs) $errors[] = 'Please enter a valid start date and time.';
        if ($f['ends_at'] !== '' && !$endTs) $errors[] = 'End date is not valid.';
        if ($startTs && $endTs && $endTs <= $startTs) $errors[] = 'The end must be after the start.';

        $src = tk_clean_url($f['source_url']);
        $off = tk_clean_url($f['official_ticket_url']);
        if ($src === null) $errors[] = 'Source URL must start with http:// or https://';
        if ($off === null) $errors[] = 'Official ticket URL must start with http:// or https://';
        if (!$owned && ($src === '' || $src === null)) $errors[] = 'An imported listing needs a source URL so people can verify it.';

        // Ticket categories (only for events sold on TicketsKenya)
        $ids = $_POST['tt_id'] ?? []; $names = $_POST['tt_name'] ?? []; $prices = $_POST['tt_price'] ?? []; $qtys = $_POST['tt_qty'] ?? [];
        $newRows = []; $deleteIds = [];
        if ($owned && is_array($names)) {
            foreach ($names as $i => $nm) {
                $nm = trim((string)$nm);
                $tid = (int)($ids[$i] ?? 0);
                $pr = trim((string)($prices[$i] ?? ''));
                $qt = trim((string)($qtys[$i] ?? ''));
                if ($nm === '' && $pr === '' && $qt === '') {
                    if ($tid > 0) $deleteIds[] = $tid;
                    continue;
                }
                if ($nm === '' || tk_strlen($nm) > 60) { $errors[] = 'Each ticket category needs a name (max 60 characters).'; continue; }
                if (!ctype_digit($pr) || (int)$pr > 1000000) { $errors[] = "Price for “{$nm}” must be a whole number of KES (0 to 1,000,000)."; continue; }
                if (!ctype_digit($qt) || (int)$qt < 1 || (int)$qt > 100000) { $errors[] = "Capacity for “{$nm}” must be between 1 and 100,000."; continue; }
                $newRows[] = ['id' => $tid, 'name' => $nm, 'price' => (int)$pr, 'qty' => (int)$qt];
            }
            $existing = [];
            foreach ($types as $t) $existing[(int)$t['id']] = $t;
            foreach ($newRows as $r) {
                if ($r['id'] > 0 && !isset($existing[$r['id']])) $errors[] = 'Unknown ticket category.';
                if ($r['id'] > 0 && isset($existing[$r['id']]) && $r['qty'] < (int)$existing[$r['id']]['quantity_sold']) {
                    $errors[] = "Capacity for “{$r['name']}” cannot be lower than the " . (int)$existing[$r['id']]['quantity_sold'] . ' tickets already sold.';
                }
            }
            foreach ($deleteIds as $d) {
                if (!isset($existing[$d])) continue;
                $used = (int)$existing[$d]['quantity_sold'] > 0 || tk_repo_where('order_items', fn($oi) => (int)$oi['ticket_type_id'] === $d) !== [];
                if ($used) $errors[] = "“{$existing[$d]['name']}” already has orders and cannot be removed.";
            }
            if ($f['status'] === 'published' && !$newRows) $errors[] = 'Add at least one ticket category before publishing an event that sells tickets here.';
        }
        $rows = $owned ? array_map(fn($r) => $r + ['sold' => 0], $newRows) : $rows;

        $cover = $event['cover_image'] ?? null;
        $hasUpload = isset($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$errors && $hasUpload) {
            try { $cover = tk_save_event_image($_FILES['cover']); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
        }

        if (!$errors) {
            $data = [
                'title' => $f['title'], 'description' => $f['description'], 'category' => $f['category'],
                'city' => $f['city'], 'venue' => $f['venue'],
                'starts_at' => date('Y-m-d H:i:s', $startTs),
                'ends_at' => $endTs ? date('Y-m-d H:i:s', $endTs) : null,
                'cover_image' => $cover, 'status' => $f['status'],
                'organiser_name' => $f['organiser_name'] !== '' ? $f['organiser_name'] : null,
                'source_url' => $src !== '' ? $src : null,
                'official_ticket_url' => $off !== '' ? $off : null,
                'price_info' => $f['price_info'] !== '' ? $f['price_info'] : null,
                'updated_at' => date('c'),
            ];
            if ($f['status'] === 'archived') $data['archived_at'] = date('c');
            if ($event) {
                if ($isAdmin) $data['listing_type'] = $f['listing_type'];
                tk_repo_update('events', (int)$event['id'], $data);
                $eventId = (int)$event['id'];
            } else {
                $data += [
                    'slug' => tk_unique_slug(tk_slugify($f['title'])),
                    'is_demo' => 0,
                    'organiser_id' => (int)$user['id'],
                    'listing_type' => $f['listing_type'],
                    'verified_at' => $owned ? null : date('Y-m-d'),
                    'created_at' => date('c'),
                ];
                $eventId = tk_repo_insert('events', $data);
            }
            if ($owned) {
                foreach ($deleteIds as $d) tk_repo_delete('ticket_types', $d);
                foreach ($newRows as $r) {
                    if ($r['id'] > 0) {
                        tk_repo_update('ticket_types', $r['id'], ['name' => $r['name'], 'price_kes' => $r['price'], 'quantity_total' => $r['qty']]);
                    } else {
                        tk_repo_insert('ticket_types', ['event_id' => $eventId, 'name' => $r['name'], 'price_kes' => $r['price'], 'quantity_total' => $r['qty'], 'quantity_sold' => 0, 'created_at' => date('c')]);
                    }
                }
            }
            $_SESSION['flash_success'] = $event ? 'Event saved.' : 'Event created.';
            tk_redirect('/admin/events.php');
        }
    }
}

$rows = $rows ?: [['id' => 0, 'name' => '', 'price' => '', 'qty' => '', 'sold' => 0]];
while (count($rows) < 4) $rows[] = ['id' => 0, 'name' => '', 'price' => '', 'qty' => '', 'sold' => 0];

$pageTitle = ($event ? 'Edit event' : 'New event') . ' | TicketsKenya';
$noindex = true;
require dirname(__DIR__, 2) . '/templates/header.php';
$owned = $f['listing_type'] === 'owned';
?>
<div class="section-inner">
  <div class="page-hero"><h1><?= $event ? 'Edit event' : 'New event' ?></h1><p>Fields marked * are required. Prices are in Kenyan shillings (KES).</p></div>

  <?php if ($errors): ?>
    <div class="flash flash-error" role="alert"><strong>Please fix these:</strong><ul style="margin:.5rem 0 0 1.2rem;"><?php foreach ($errors as $er): ?><li><?= tk_e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="form-card wide-form" action="/admin/event-edit.php<?= $event ? '?id=' . (int)$event['id'] : '' ?>">
    <?= tk_csrf_field() ?>
    <?php if ($event): ?><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><?php endif; ?>

    <div class="form-group"><label for="title">Title *</label><input id="title" name="title" maxlength="120" required value="<?= tk_e($f['title']) ?>"></div>
    <div class="form-group"><label for="description">Description *</label><textarea id="description" name="description" rows="6" maxlength="5000" required><?= tk_e($f['description']) ?></textarea></div>

    <div class="form-grid">
      <div class="form-group"><label for="category">Category *</label>
        <select id="category" name="category"><?php foreach ($categories as $c): ?><option <?= $f['category'] === $c ? 'selected' : '' ?>><?= tk_e($c) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="status">Status *</label>
        <select id="status" name="status"><?php foreach (tk_event_statuses() as $s): ?><option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="venue">Venue *</label><input id="venue" name="venue" maxlength="120" required value="<?= tk_e($f['venue']) ?>"></div>
      <div class="form-group"><label for="city">City *</label><input id="city" name="city" maxlength="60" required value="<?= tk_e($f['city']) ?>"></div>
      <div class="form-group"><label for="starts_at">Starts (Nairobi time) *</label><input id="starts_at" type="datetime-local" name="starts_at" required value="<?= tk_e($f['starts_at']) ?>"></div>
      <div class="form-group"><label for="ends_at">Ends (optional)</label><input id="ends_at" type="datetime-local" name="ends_at" value="<?= tk_e($f['ends_at']) ?>"></div>
      <div class="form-group"><label for="organiser_name">Organiser name</label><input id="organiser_name" name="organiser_name" maxlength="120" value="<?= tk_e($f['organiser_name']) ?>"></div>
      <div class="form-group"><label for="cover">Event image (JPG, PNG or WEBP, max 3 MB)</label><input id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp"></div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="form-group"><label for="listing_type">Kind of listing</label>
      <select id="listing_type" name="listing_type">
        <option value="owned" <?= $owned ? 'selected' : '' ?>>Own event (tickets sold on TicketsKenya)</option>
        <option value="discovery" <?= !$owned ? 'selected' : '' ?>>Discovery listing (no TicketsKenya ticket inventory)</option>
      </select></div>
    <?php endif; ?>

    <fieldset class="fieldset-box">
      <legend>Source and links</legend>
      <div class="form-group"><label for="source_url">Source URL (where the details were verified)</label><input id="source_url" name="source_url" type="url" value="<?= tk_e($f['source_url']) ?>" placeholder="https://"></div>
      <div class="form-group"><label for="official_ticket_url">Official ticket URL (imported listings)</label><input id="official_ticket_url" name="official_ticket_url" type="url" value="<?= tk_e($f['official_ticket_url']) ?>" placeholder="https://"></div>
      <div class="form-group"><label for="price_info">Published price note (imported listings, exactly as the source shows it)</label><input id="price_info" name="price_info" maxlength="200" value="<?= tk_e($f['price_info']) ?>" placeholder="Leave blank if the price is not confirmed"></div>
    </fieldset>

    <?php if ($owned): ?>
    <fieldset class="fieldset-box">
      <legend>Ticket categories (own events)</legend>
      <p class="form-note" style="text-align:left;margin:0 0 .75rem;">Leave a row empty to ignore it. Emptying a saved row removes it, unless it already has orders.</p>
      <?php foreach ($rows as $r): ?>
        <div class="ticket-edit-row">
          <input type="hidden" name="tt_id[]" value="<?= (int)$r['id'] ?>">
          <input name="tt_name[]" placeholder="Name e.g. Regular" maxlength="60" value="<?= tk_e((string)$r['name']) ?>" aria-label="Ticket name">
          <input name="tt_price[]" inputmode="numeric" placeholder="Price (KES)" value="<?= tk_e((string)$r['price']) ?>" aria-label="Price in KES">
          <input name="tt_qty[]" inputmode="numeric" placeholder="Capacity" value="<?= tk_e((string)$r['qty']) ?>" aria-label="Capacity">
          <?php if ((int)$r['sold'] > 0): ?><span class="sold-note"><?= (int)$r['sold'] ?> sold</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>

    <div class="form-actions" style="flex-direction:row;flex-wrap:wrap;">
      <button class="btn btn-lime" type="submit">Save event</button>
      <a class="btn btn-outline" href="/admin/events.php">Cancel</a>
    </div>
  </form>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
