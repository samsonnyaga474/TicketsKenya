<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = tk_require_login();

$pageTitle = 'My tickets & orders | TicketsKenya';
$noindex = true;

$orders = tk_repo_where('orders', fn($o) => (int)$o['user_id'] === (int)$user['id']);
usort($orders, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$eventsById = [];
foreach (tk_repo_all('events') as $e) $eventsById[(int)$e['id']] = $e;

require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="section-inner">
  <div class="page-hero">
    <h1>My tickets &amp; orders</h1>
    <p>Payments are not available yet, so orders stay pending and no tickets are issued.</p>
  </div>

  <?php if (!$orders): ?>
    <div class="empty-state">
      <p>You have no orders yet.</p>
      <p><a href="/events/">Browse events</a></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Order</th>
            <th>Event</th>
            <th>Date</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o):
            $ev = $eventsById[(int)$o['event_id']] ?? null;
          ?>
            <tr>
              <td>#<?= (int)$o['id'] ?></td>
              <td><?= tk_e($ev['title'] ?? '—') ?></td>
              <td><?= $ev ? tk_e(date('j M Y', strtotime($ev['starts_at']))) : '—' ?></td>
              <td>KES <?= number_format((int)$o['total_kes']) ?></td>
              <td class="status-<?= tk_e($o['status']) ?>"><?= tk_e(ucfirst($o['status'])) ?></td>
              <td><a href="/tickets/order.php?id=<?= (int)$o['id'] ?>" class="btn btn-outline" style="padding:0.35rem 0.75rem;font-size:0.8rem;">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
