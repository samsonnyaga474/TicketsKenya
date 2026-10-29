<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$event = $slug !== '' ? tk_repo_find('events', 'slug', $slug) : null;
if (!$event || !in_array($event['status'], ['published', 'sold_out', 'cancelled', 'completed'], true)) {
    http_response_code(404);
    require TK_PUBLIC . '/404.php';
    exit;
}
// Old query-string URLs redirect permanently to the clean URL.
if (isset($_GET['slug']) && $_SERVER['REQUEST_METHOD'] === 'GET' && !defined('TK_CLEAN_ROUTE') && str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), 'view.php')) {
    header('Location: ' . tk_event_url($event), true, 301);
    exit;
}

$ticketTypes = tk_repo_where('ticket_types', fn($t) => (int)$t['event_id'] === (int)$event['id']);
usort($ticketTypes, fn($a, $b) => $a['price_kes'] <=> $b['price_kes']);
$isDiscovery = ($event['listing_type'] ?? '') !== 'owned';
$ts = tk_event_ts($event) ?? time();
$minPrices = tk_event_min_prices();
$priceText = tk_event_price_text($event, $minPrices);

// Internal checkout only for TicketsKenya-owned inventory (not discovery listings).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isDiscovery) {
        $_SESSION['flash_error'] = 'TicketsKenya does not have ticket inventory for this discovery listing.';
        tk_redirect('/events/' . rawurlencode($slug));
    }
    if (!in_array($event['status'], ['published'], true)) {
        $_SESSION['flash_error'] = 'Tickets are not available for this event right now.';
        tk_redirect('/events/' . rawurlencode($slug));
    }
    if (!tk_verify_csrf(is_string($_POST[TK_CSRF_TOKEN_NAME] ?? null) ? $_POST[TK_CSRF_TOKEN_NAME] : null)) {
        $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
        tk_redirect('/events/' . rawurlencode($slug));
    }
    $user = tk_require_login();
    // Serialize the read/check/reserve sequence across local PHP workers. The
    // lock file also protects the JSON fallback; SQLite additionally takes an
    // immediate write transaction so inventory cannot be oversold concurrently.
    $reservationLock = @fopen(TK_DATA . '/order-reservation.lock', 'c');
    if (!$reservationLock || !flock($reservationLock, LOCK_EX)) {
        if (is_resource($reservationLock)) fclose($reservationLock);
        http_response_code(503);
        exit('Ticket reservation is temporarily unavailable. Please try again.');
    }
    $orderDb = tk_db();
    if ($orderDb) {
        $orderDb->exec('BEGIN IMMEDIATE');
        // Refresh ticket quantities only after obtaining the write lock.
        $ticketTypes = tk_repo_where('ticket_types', fn($t) => (int)$t['event_id'] === (int)$event['id']);
    }
    $qtyMap = [];
    $lines = [];
    $subtotal = 0;
    foreach ($ticketTypes as $tt) {
        $tid = (int)$tt['id'];
        $q = (int)($_POST['qty'][$tid] ?? 0);
        if ($q < 0) $q = 0;
        if ($q === 0) continue;
        $avail = tk_ticket_available($tt);
        if ($q > $avail) {
            $_SESSION['flash_error'] = 'Not enough tickets left for ' . ($tt['name'] ?? 'selected type') . '.';
            tk_redirect('/events/' . rawurlencode($slug));
        }
        $unit = (int)$tt['price_kes'];
        $lines[] = ['ticket_type_id' => $tid, 'quantity' => $q, 'unit_price_kes' => $unit];
        $subtotal += $unit * $q;
        $qtyMap[$tid] = $q;
    }
    if (!$lines) {
        $_SESSION['flash_error'] = 'Choose at least one ticket.';
        tk_redirect('/events/' . rawurlencode($slug));
    }
    $fee = (int)round($subtotal * (TK_PLATFORM_FEE_PERCENT / 100));
    $tax = 0; // No tax rules/rate have been configured; never invent a tax amount.
    $total = $subtotal + $fee + $tax;
    $orderId = tk_repo_insert('orders', [
        'user_id' => (int)$user['id'],
        'event_id' => (int)$event['id'],
        'status' => 'pending',
        'subtotal_kes' => $subtotal,
        'fee_kes' => $fee,
        'tax_kes' => $tax,
        'total_kes' => $total,
        'currency' => 'KES',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);
    foreach ($lines as $line) {
        tk_repo_insert('order_items', [
            'order_id' => $orderId,
            'ticket_type_id' => $line['ticket_type_id'],
            'quantity' => $line['quantity'],
            'unit_price_kes' => $line['unit_price_kes'],
        ]);
        // Soft-reserve inventory for pending order (released only by admin/cancel in this build).
        $tt = tk_repo_find('ticket_types', 'id', $line['ticket_type_id']);
        if ($tt) {
            $sold = (int)($tt['quantity_sold'] ?? 0) + (int)$line['quantity'];
            $total = (int)($tt['quantity_total'] ?? 0);
            if ($total > 0 && $sold > $total) $sold = $total;
            tk_repo_update('ticket_types', (int)$tt['id'], ['quantity_sold' => $sold]);
        }
    }
    if ($orderDb && $orderDb->inTransaction()) $orderDb->commit();
    flock($reservationLock, LOCK_UN);
    fclose($reservationLock);
    require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php';
    tk_activity_checkout_started((int)$orderId, (int)$event['id'], (int)$subtotal + (int)$fee + (int)$tax); // analytics only; never throws
    $_SESSION['flash_success'] = 'Order created. Complete payment when payments are enabled, or contact support.';
    tk_redirect('/tickets/order.php?id=' . $orderId);
}

$pageTitle = $event['title'] . ' | ' . date('j M Y', $ts) . ' | TicketsKenya';
$pageDescription = tk_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$event['description']))), 0, 155);
$noindex = !in_array($event['status'], ['published', 'sold_out'], true);
$canonicalPath = '/events/' . rawurlencode($slug);
$hasPoster = tk_event_has_poster($event);
$ogImage = $hasPoster ? '/assets/' . $event['cover_image'] : null;

// Structured data only from stored facts; no invented organiser, rating or availability.
$ld = ['@context' => 'https://schema.org', '@type' => 'Event', 'name' => $event['title'], 'startDate' => date('c', $ts),
    'eventStatus' => $event['status'] === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'location' => ['@type' => 'Place', 'name' => $event['venue'], 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $event['city'], 'addressCountry' => strtoupper((string)($event['country_code'] ?? 'KE'))]],
    'description' => $pageDescription];
if (!empty($event['ends_at']) && strtotime($event['ends_at'])) $ld['endDate'] = date('c', strtotime($event['ends_at']));
if ($hasPoster && tk_canonical_base()) $ld['image'] = [tk_canonical_base() . '/assets/' . $event['cover_image']];
// Sales stay on TicketsKenya; structured data uses our event page.
if (!empty($event['organiser_name'])) $ld['organizer'] = ['@type' => 'Organization', 'name' => $event['organiser_name']];
$jsonLd = [$ld] + tk_breadcrumb_ld([['Home', '/'], ['Events', '/events/'], [$event['title'], $canonicalPath]]);

require dirname(__DIR__, 2) . '/templates/header.php';
?>
<div class="tk-stack"><section class="tk-st pg-hero" aria-labelledby="evH">
  <div class="pg-hero-inner">
    <p style="margin:0 0 10px"><a href="/events/" style="color:var(--lime);text-decoration:underline">&larr; All events</a></p>
    <h1 id="evH" style="text-transform:none"><?= tk_e($event['title']) ?></h1>
    <p><?= tk_e(date('l, j F Y · g:i A', $ts)) ?> (East Africa Time) · <?= tk_e($event['venue']) ?>, <?= tk_e($event['city']) ?></p>
  </div>
</section>

<section class="tk-st tk-st-last pg-body"><div class="pg-wrap">
  <div class="dt">
    <div class="dt-poster">
      <?php if ($hasPoster): ?>
        <img src="<?= tk_e(tk_event_image($event)) ?>" alt="Event poster for <?= tk_e($event['title']) ?>" width="420" height="525" fetchpriority="high">
      <?php else: ?>
        <div class="ev-noposter" style="height:100%"><span class="ev-nop-day"><?= tk_e(date('j', $ts)) ?></span><span class="ev-nop-mon"><?= tk_e(date('M', $ts)) ?></span><span class="ev-nop-cat"><?= tk_e((string)($event['category'] ?? 'Event')) ?></span><span class="ev-nop-note">Official poster not available</span></div>
      <?php endif; ?>
    </div>
    <div>
      <p>
        <?php if ($event['status'] === 'sold_out'): ?><span class="tag tag-warn">Sold out</span><?php endif; ?>
        <?php if ($event['status'] === 'cancelled'): ?><span class="tag tag-warn">Cancelled</span><?php endif; ?>
        <?php if ($event['status'] === 'completed'): ?><span class="tag">Event has ended</span><?php endif; ?>
        <?php if (!empty($event['category'])): ?><span class="tag"><?= tk_e($event['category']) ?></span><?php endif; ?>
        <span class="tag"><?= $isDiscovery ? 'Event listing' : 'Tickets sold on TicketsKenya' ?></span>
      </p>
      <dl class="dt-facts">
        <div><dt>When</dt><dd><?= tk_e(date('D, j M Y · g:i A', $ts)) ?></dd></div>
        <div><dt>Where</dt><dd><?= tk_e($event['venue']) ?>, <?= tk_e($event['city']) ?></dd></div>
        <div><dt>Price</dt><dd><?= tk_e($priceText) ?></dd></div>
        <?php if (!empty($event['organiser_name'])): ?><div><dt>Organiser</dt><dd><?= tk_e($event['organiser_name']) ?></dd></div><?php endif; ?>
      </dl>
      <div class="prose" style="margin-top:8px"><?= nl2br(tk_e((string)$event['description'])) ?></div>
      <?php if (!empty($event['verified_at'])): ?><p style="font-size:.82rem;color:var(--mute);margin-top:14px">Last source check: <?= tk_e($event['verified_at']) ?>. Please confirm the date, venue and price on the organiser’s page.</p><?php endif; ?>

      <div class="callout" style="margin-top:22px">
        <h2 style="font-size:1.1rem;margin-bottom:8px">Tickets</h2>
        <?php if ($isDiscovery): ?>
          <p style="color:var(--mute);margin-bottom:14px">This event is listed for discovery only. TicketsKenya does not have ticket inventory for it, so tickets cannot currently be purchased here.</p>
        <?php else: ?>
          <?php if (!in_array($event['status'], ['published'], true)): ?>
            <p>Tickets are not on sale for this event right now.</p>
          <?php elseif (!$ticketTypes): ?>
            <p>No ticket types have been set up for this event yet.</p>
          <?php else: ?>
            <form method="post" action="" class="tk-ticket-form" style="display:grid;gap:12px">
              <?= tk_csrf_field() ?>
              <?php foreach ($ticketTypes as $tt):
                $avail = tk_ticket_available($tt);
                $price = (int)$tt['price_kes'];
              ?>
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.08)">
                  <div>
                    <strong><?= tk_e($tt['name']) ?></strong>
                    <div style="font-size:.9rem;color:var(--mute)"><?= $price === 0 ? 'Free' : 'KES ' . number_format($price) ?> · <?= $avail ?> left</div>
                  </div>
                  <label style="display:flex;align-items:center;gap:8px">
                    <span class="sr-only">Quantity for <?= tk_e($tt['name']) ?></span>
                    <input type="number" name="qty[<?= (int)$tt['id'] ?>]" min="0" max="<?= max(0, $avail) ?>" value="0" <?= $avail < 1 ? 'disabled' : '' ?> style="width:4.5rem;padding:.4rem .5rem;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:#111;color:#fff">
                  </label>
                </div>
              <?php endforeach; ?>
              <p style="font-size:.85rem;color:var(--mute);margin:0">A platform fee of <?= tk_e((string)TK_PLATFORM_FEE_PERCENT) ?>% is added to the pending order. Online card/M-Pesa payment is not active, so no payment is taken and no ticket is issued yet.</p>
              <button type="submit" class="btn btn-solid">Get Ticket</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div></section></div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
