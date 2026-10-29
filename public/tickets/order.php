<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/payments.php';
$user = tk_require_login();

$id = (int)($_GET['id'] ?? 0);
$order = tk_repo_find('orders', 'id', $id);
if (!$order || (int)$order['user_id'] !== (int)$user['id']) {
    http_response_code(404);
    $_SESSION['flash_error'] = 'Order not found.';
    tk_redirect('/tickets/');
}

$event = tk_repo_find('events', 'id', (int)$order['event_id']);
$lines = tk_repo_where('order_items', fn($i) => (int)$i['order_id'] === $id);
$typesById = [];
foreach (tk_repo_all('ticket_types') as $t) {
    $typesById[(int)$t['id']] = $t;
}
$issued = tk_repo_where('tickets', fn($t) => (int)$t['order_id'] === $id);

$status = (string)($order['status'] ?? 'pending');
$paymentStatus = (string)($order['payment_status'] ?? 'unpaid');
$isPaid = $status === 'paid' || $paymentStatus === 'paid';

// Receipt stage is derived only from real server fields — never from the browser alone.
if ($status === 'paid' || $paymentStatus === 'paid') {
    $stage = $issued ? 'complete' : 'printing';
} elseif (in_array($status, ['failed', 'cancelled'], true) || $paymentStatus === 'failed') {
    $stage = 'failed';
} else {
    $stage = 'processing'; // pending / unpaid — payment not verified
}

$stageCopy = [
    'processing' => [
        'label' => 'Waiting for payment',
        'description' => tk_pesapal_configured()
            ? 'Your order is saved. Complete payment below to receive your ticket. Nothing is charged until payment is confirmed by Pesapal.'
            : 'Your order is saved. Online payment is not configured yet, so nothing has been charged and no ticket is issued.',
        'icon' => '…',
        'iconClass' => 'is-wait',
    ],
    'printing' => [
        'label' => 'Preparing your ticket',
        'description' => 'Payment was verified on the server. Ticket records are being created.',
        'icon' => '⌁',
        'iconClass' => 'is-wait',
    ],
    'complete' => [
        'label' => 'Ticket ready',
        'description' => 'Payment verified and ticket issued.',
        'icon' => '✓',
        'iconClass' => 'is-ok',
    ],
    'failed' => [
        'label' => 'Payment not completed',
        'description' => 'This order is not paid. No ticket was issued.',
        'icon' => '!',
        'iconClass' => 'is-fail',
    ],
];
$copy = $stageCopy[$stage];

$eventName = $event['title'] ?? 'Event ticket';
$eventDate = $event ? date('j M Y · g:i A', strtotime((string)$event['starts_at'])) : '—';
$location = $event ? trim(($event['venue'] ?? '') . ', ' . ($event['city'] ?? ''), ', ') : '—';
$subtotal = array_sum(array_map(fn($line) => (int)$line['quantity'] * (int)$line['unit_price_kes'], $lines));
$platformFee = (int)($order['fee_kes'] ?? max(0, (int)($order['total_kes'] ?? 0) - $subtotal));
$tax = (int)($order['tax_kes'] ?? 0);
$amountDue = (int)($order['total_kes'] ?? ($subtotal + $platformFee + $tax));
$amountPaid = $isPaid ? $amountDue : 0;
$orderDate = !empty($order['created_at']) ? date('j M Y · g:i A', strtotime((string)$order['created_at'])) : '—';
$ref = (string)($order['payment_reference'] ?? $order['provider_transaction_id'] ?? '—');
$method = (string)($order['payment_method'] ?? 'Not selected');
$ticketCode = $issued ? (string)($issued[0]['ticket_code'] ?? '') : '';
$ticketLabels = [];
foreach ($lines as $line) {
    $type = $typesById[(int)$line['ticket_type_id']]['name'] ?? 'Ticket';
    $ticketLabels[] = (int)$line['quantity'] . ' × ' . $type;
}
$emailNotice = $isPaid
    ? 'Payment and ticket status are shown here. Email delivery is not configured, so this receipt and ticket have not been emailed.'
    : 'No payment has been taken. Receipt and ticket emails are sent only after verified payment and mail delivery are configured.';

$pageTitle = 'Order #' . $id . ' | TicketsKenya';
$noindex = true;
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="section-inner" style="padding-bottom:3rem">
  <div class="page-hero" style="margin-bottom:1.5rem">
    <h1>Order #<?= (int)$order['id'] ?></h1>
    <p><?= tk_e($eventName) ?> · <?= tk_e($eventDate) ?></p>
  </div>

  <section class="tk-receipt" data-stage="<?= tk_e($stage) ?>" aria-live="polite" aria-atomic="true">
    <div class="tk-receipt-head">
      <div class="tk-receipt-icon <?= tk_e($copy['iconClass']) ?>" aria-hidden="true"><?= tk_e($copy['icon']) ?></div>
      <div>
        <h2><?= tk_e($copy['label']) ?></h2>
        <p><?= tk_e($copy['description']) ?></p>
      </div>
    </div>
    <div class="tk-receipt-body">
      <div class="tk-receipt-printer" aria-label="Receipt printer">
      <div class="tk-receipt-paper" id="orderReceipt">
        <p class="brand">TICKETSKENYA</p>
        <p class="event"><?= tk_e($eventName) ?></p>
        <ul class="tk-receipt-items" aria-label="Tickets in this order">
          <?php foreach ($lines as $line): $ticketName = $typesById[(int)$line['ticket_type_id']]['name'] ?? 'Ticket'; ?>
            <li><span><?= (int)$line['quantity'] ?> × <?= tk_e($ticketName) ?></span><span>KES <?= number_format((int)$line['quantity'] * (int)$line['unit_price_kes']) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <dl>
          <div><dt>Order</dt><dd>#<?= (int)$order['id'] ?></dd></div>
          <div><dt>Order date</dt><dd><?= tk_e($orderDate) ?></dd></div>
          <div><dt>Event date</dt><dd><?= tk_e($eventDate) ?></dd></div>
          <div><dt>Location</dt><dd><?= tk_e($location) ?></dd></div>
          <div><dt>Ticket type(s)</dt><dd><?= tk_e(implode(', ', $ticketLabels) ?: '—') ?></dd></div>
          <div><dt>Subtotal</dt><dd>KES <?= number_format($subtotal) ?></dd></div>
          <div><dt>Platform fee</dt><dd>KES <?= number_format($platformFee) ?></dd></div>
          <div><dt>Tax (not configured)</dt><dd>KES <?= number_format($tax) ?></dd></div>
          <div><dt>Total due</dt><dd>KES <?= number_format($amountDue) ?></dd></div>
          <div><dt>Total paid</dt><dd>KES <?= number_format($amountPaid) ?></dd></div>
          <div><dt>Paid with</dt><dd><?= tk_e($isPaid ? $method : 'Not paid') ?></dd></div>
          <div><dt>Reference</dt><dd><?= tk_e($ref) ?></dd></div>
          <div><dt>Status</dt><dd><?= tk_e(ucfirst($status)) ?></dd></div>
        </dl>
        <?php if ($stage === 'complete' && $ticketCode !== ''): ?>
          <div class="tk-receipt-issued">
            TICKET ISSUED<br>
            <span style="letter-spacing:normal;font-weight:600;margin-top:.35rem;display:inline-block"><?= tk_e($ticketCode) ?></span>
          </div>
        <?php endif; ?>
      </div>
      </div>
      <p class="tk-email-notice" role="note"><?= tk_e($emailNotice) ?></p>
      <button class="btn btn-outline tk-print-button" type="button" data-print-receipt>Print receipt</button>
    </div>
  </section>

  <h2 style="font-size:1.1rem;margin:2rem 0 .75rem">Items</h2>
  <div class="table-wrap" style="padding-top:0">
    <table>
      <thead>
        <tr><th>Ticket type</th><th>Qty</th><th>Unit price</th><th>Subtotal</th></tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $l):
          $tt = $typesById[(int)$l['ticket_type_id']] ?? null;
        ?>
          <tr>
            <td><?= tk_e($tt['name'] ?? '—') ?></td>
            <td><?= (int)$l['quantity'] ?></td>
            <td>KES <?= number_format((int)$l['unit_price_kes']) ?></td>
            <td>KES <?= number_format((int)$l['quantity'] * (int)$l['unit_price_kes']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($issued): ?>
    <h2 style="font-size:1.1rem;margin:1.75rem 0 .85rem">Your tickets</h2>
    <div style="display:grid;gap:1rem;max-width:36rem">
      <?php foreach ($issued as $t): ?>
        <?php $ticketLine = null; foreach ($lines as $line) if ((int)$line['id'] === (int)($t['order_item_id'] ?? 0)) { $ticketLine = $line; break; }
          $ticketTypeName = $ticketLine ? ($typesById[(int)$ticketLine['ticket_type_id']]['name'] ?? 'Ticket') : 'Ticket'; ?>
        <article class="tk-ticket">
          <div class="tk-ticket-body">
            <p style="margin:0 0 .35rem;font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;color:#aaa">TicketsKenya</p>
            <h3 style="margin:0 0 .5rem;font-size:1.05rem"><?= tk_e($eventName) ?></h3>
            <p style="margin:0;color:#ccc;font-size:.9rem">Ticket type: <?= tk_e($ticketTypeName) ?></p>
            <p style="margin:0;color:#ccc;font-size:.9rem"><?= tk_e($eventDate) ?></p>
            <p style="margin:.35rem 0 0;color:#ccc;font-size:.9rem"><?= tk_e($location) ?></p>
            <p style="margin:.75rem 0 0;font-family:ui-monospace,monospace;font-size:.95rem"><?= tk_e($t['ticket_code']) ?></p>
            <p style="margin:.35rem 0 0;font-size:.8rem;color:#9f9"><?= tk_e(ucfirst((string)$t['status'])) ?></p>
          </div>
          <aside class="tk-ticket-stub" aria-label="Ticket stub">TICKETSKENYA</aside>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>


  <?php if (!$isPaid && $stage === 'processing' && tk_pesapal_configured()): ?>
  <section style="margin-top:2rem;max-width:28rem" aria-label="Pay for this order">
    <h2 style="font-size:1.1rem;margin:0 0 .75rem">Pay securely</h2>
    <p style="margin:0 0 1rem;color:#ccc;font-size:.95rem">You will be redirected to Pesapal to complete payment with M-Pesa or card. Your ticket is issued only after Pesapal confirms the payment on our server.</p>
    <form method="post" action="/payments/start.php">
      <?= tk_csrf_field() ?>
      <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
      <button type="submit" class="btn btn-solid">Pay KES <?= number_format($amountDue) ?> with Pesapal</button>
    </form>
    <p style="margin:.75rem 0 0;font-size:.85rem;color:#999">After paying you will return here. If the page still shows pending, wait a few seconds and refresh — delayed M-Pesa confirmations are handled automatically.</p>
  </section>
  <?php elseif (!$isPaid && $stage === 'processing'): ?>
  <p style="margin-top:1.5rem;color:#ccc">Online payments are not available on this site yet. Your order is saved and nothing has been charged.</p>
  <?php endif; ?>

  <p style="margin-top:2rem">
    <a href="/tickets/" class="btn btn-outline">← Back to my orders</a>
    <a href="/events/" class="btn btn-solid" style="margin-left:.5rem">See All Events <span class="btn-arrow" aria-hidden="true">→</span></a>
  </p>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
