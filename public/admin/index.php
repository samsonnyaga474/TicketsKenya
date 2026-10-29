<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = tk_require_role('organiser', 'admin');

$pageTitle = 'Organiser dashboard | TicketsKenya';
$noindex = true;

$allEvents = tk_repo_all('events');
$list = array_values(array_filter($allEvents, function ($e) use ($user) {
    return $user['role'] === 'admin' || (int)($e['organiser_id'] ?? 0) === (int)$user['id'];
}));
usort($list, fn($a, $b) => strcmp($b['starts_at'], $a['starts_at']));

$orders = tk_repo_all('orders');
$orderCount = [];
$paidTotal = [];
foreach ($orders as $o) {
    $eid = (int)$o['event_id'];
    $orderCount[$eid] = ($orderCount[$eid] ?? 0) + 1;
    if (($o['status'] ?? '') === 'paid') {
        $paidTotal[$eid] = ($paidTotal[$eid] ?? 0) + (int)$o['total_kes'];
    }
}

require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="section-inner">
  <div class="page-hero">
    <h1>Organiser dashboard</h1>
    <p>Manage your events. Only you (or an admin) can see this area. Server-side role checks are enforced.</p>
  </div>

  <p style="margin-bottom:1rem;"><a class="btn btn-lime" href="/admin/events.php">Manage events</a></p>
  <?php if (($user['role'] ?? '') === 'admin'): ?><p style="margin-bottom:1rem;"><a class="btn btn-outline" href="/admin/discovery.php">Event discovery tracking</a></p><?php endif; ?>
  <?php if (($user['role'] ?? '') === 'admin'): ?><p style="margin-bottom:1rem;"><a class="btn btn-outline" href="/admin/messages.php">Contact messages</a></p><?php endif; ?>
  <?php if (($user['role'] ?? '') === 'admin'): ?><p style="margin-bottom:1rem;"><a class="btn btn-outline" href="/admin/security.php">Security activity</a></p><?php endif; ?>
  <?php require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php'; if (tk_activity_boot() && \SiteActivity\Module::host()->isOwner($user)): ?><p style="margin-bottom:1rem;"><a class="btn btn-outline" href="/admin/activity/">Activity &amp; Insights</a></p><?php endif; ?>

  <?php if (!$list): ?>
    <div class="empty-state"><p>No events yet.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Event</th>
            <th>Starts</th>
            <th>Status</th>
            <th>Orders</th>
            <th>Paid total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $e): ?>
            <tr>
              <td>
                <?= tk_e($e['title']) ?>
                <?php if (!empty($e['is_demo'])): ?><span class="badge-demo" style="margin-left:0.4rem;">Demo</span><?php endif; ?>
              </td>
              <td><?= tk_e(date('j M Y H:i', strtotime($e['starts_at']))) ?></td>
              <td><?= tk_e($e['status']) ?></td>
              <td><?= (int)($orderCount[(int)$e['id']] ?? 0) ?></td>
              <td>KES <?= number_format((int)($paidTotal[(int)$e['id']] ?? 0)) ?></td>
              <td><a href="/events/view.php?slug=<?= urlencode($e['slug']) ?>" class="btn btn-outline" style="padding:0.3rem 0.7rem;font-size:0.8rem;">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
