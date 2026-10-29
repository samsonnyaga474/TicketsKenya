<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = tk_require_role('organiser', 'admin');
$isAdmin = $user['role'] === 'admin';

/** Can this user change this event? Admins: all. Organisers: only their own (never imported ones). */
function tk_can_manage(array $user, array $ev): bool
{
    if ($user['role'] === 'admin') return true;
    return ($ev['listing_type'] ?? '') === 'owned' && (int)($ev['organiser_id'] ?? 0) === (int)$user['id'];
}

// ---------- Actions (POST only, CSRF protected, permission checked on the server) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[TK_CSRF_TOKEN_NAME] ?? null;
    if (!tk_verify_csrf(is_string($token) ? $token : null)) {
        http_response_code(400);
        $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
        tk_redirect('/admin/events.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $ev = $id > 0 ? tk_repo_find('events', 'id', $id) : null;
    if (!$ev || !tk_can_manage($user, $ev)) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'You are not allowed to change that event.';
        tk_redirect('/admin/events.php');
    }
    $statusMap = ['publish' => 'published', 'unpublish' => 'unpublished', 'cancel' => 'cancelled', 'complete' => 'completed'];
    if (isset($statusMap[$action])) {
        tk_repo_update('events', $id, ['status' => $statusMap[$action], 'updated_at' => date('c')]);
        $_SESSION['flash_success'] = '"' . $ev['title'] . '" is now ' . $statusMap[$action] . '.';
    } elseif ($action === 'archive') {
        tk_repo_update('events', $id, ['status' => 'archived', 'archived_at' => date('c'), 'updated_at' => date('c')]);
        $_SESSION['flash_success'] = '"' . $ev['title'] . '" was archived (hidden, records kept).';
    } elseif ($action === 'delete') {
        if (!$isAdmin) {
            http_response_code(403);
            $_SESSION['flash_error'] = 'Only an admin can permanently delete an event. You can archive it instead.';
            tk_redirect('/admin/events.php');
        }
        $hasOrders = tk_repo_where('orders', fn($o) => (int)$o['event_id'] === $id) !== [];
        if ($hasOrders) {
            // Never destroy money / ticket history: archive instead.
            tk_repo_update('events', $id, ['status' => 'archived', 'archived_at' => date('c'), 'updated_at' => date('c')]);
            $_SESSION['flash_success'] = 'This event has orders, so it was archived instead of deleted. Order records are kept.';
        } else {
            foreach (tk_repo_where('ticket_types', fn($t) => (int)$t['event_id'] === $id) as $t) {
                tk_repo_delete('ticket_types', (int)$t['id']);
            }
            tk_repo_delete('events', $id);
            $_SESSION['flash_success'] = 'Event deleted.';
        }
    } else {
        $_SESSION['flash_error'] = 'Unknown action.';
    }
    tk_redirect('/admin/events.php');
}

$pageTitle = 'Manage events | TicketsKenya';
$noindex = true;
$show = (string)($_GET['show'] ?? 'active');
$all = array_values(array_filter(tk_repo_all('events'), fn($e) => tk_can_manage($user, $e) || $isAdmin));
if ($show !== 'all') {
    $all = array_values(array_filter($all, fn($e) => ($e['status'] ?? '') !== 'archived'));
}
usort($all, fn($a, $b) => strcmp($b['starts_at'], $a['starts_at']));

require dirname(__DIR__, 2) . '/templates/header.php';
?>
<div class="section-inner">
  <div class="page-hero">
    <h1>Manage events</h1>
    <p>Create, edit, publish, unpublish, archive or delete events. Imported events keep their source link.</p>
  </div>

  <p style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;">
    <a class="btn btn-lime" href="/admin/event-edit.php">+ New event</a>
    <a class="btn btn-outline" href="/admin/">Dashboard</a>
    <?php if (($user['role'] ?? '') === 'admin'): ?><a class="btn btn-outline" href="/admin/discovery.php">Event discovery tracking</a><?php endif; ?>
    <a class="btn btn-outline" href="/admin/events.php?show=<?= $show === 'all' ? 'active' : 'all' ?>"><?= $show === 'all' ? 'Hide archived' : 'Show archived' ?></a>
  </p>

  <?php if (!$all): ?>
    <div class="empty-state"><p>No events yet. Use “New event” to add one.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Event</th><th>When</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($all as $e): $can = tk_can_manage($user, $e); $st = (string)$e['status']; ?>
        <tr>
          <td><strong><?= tk_e($e['title']) ?></strong><br><span style="color:rgba(255,255,255,.5);font-size:.8rem;"><?= tk_e($e['venue']) ?>, <?= tk_e($e['city']) ?></span></td>
          <td><?= tk_e(date('j M Y H:i', strtotime($e['starts_at']))) ?></td>
          <td><?= ($e['listing_type'] ?? '') === 'owned' ? 'Own event' : 'Imported listing' ?></td>
          <td><?= tk_e($st) ?></td>
          <td>
            <div class="row-actions">
              <a class="btn btn-outline" href="/events/view.php?slug=<?= urlencode($e['slug']) ?>">View</a>
              <?php if ($can): ?>
                <a class="btn btn-outline" href="/admin/event-edit.php?id=<?= (int)$e['id'] ?>">Edit</a>
                <?php foreach ([
                  'publish' => ['Publish', !in_array($st, ['published', 'archived'], true)],
                  'unpublish' => ['Unpublish', $st === 'published'],
                  'cancel' => ['Cancel event', in_array($st, ['published', 'unpublished', 'draft'], true)],
                  'complete' => ['Mark completed', $st === 'published'],
                  'archive' => ['Archive', $st !== 'archived'],
                ] as $act => [$label, $visible]): if (!$visible) continue; ?>
                  <form method="post" action="/admin/events.php">
                    <?= tk_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="action" value="<?= $act ?>">
                    <button class="btn btn-outline" type="submit"><?= $label ?></button>
                  </form>
                <?php endforeach; ?>
                <?php if ($isAdmin): ?>
                  <form method="post" action="/admin/events.php" data-confirm="Delete “<?= tk_e($e['title']) ?>”? Events with orders are archived instead.">
                    <?= tk_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="action" value="delete">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:rgba(255,255,255,.45);font-size:.8rem;">Read only</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
