<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = tk_require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tk_verify_csrf(is_string($_POST[TK_CSRF_TOKEN_NAME] ?? null) ? $_POST[TK_CSRF_TOKEN_NAME] : null)) { http_response_code(403); exit('Invalid security token.'); }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && tk_repo_find('contact_messages', 'id', $id)) tk_repo_update('contact_messages', $id, ['handled' => (int)($_POST['handled'] ?? 0) === 1 ? 1 : 0]);
    tk_redirect('/admin/messages.php');
}
$pageTitle = 'Contact messages | TicketsKenya';
$noindex = true;
$rows = tk_repo_all('contact_messages');
usort($rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
require dirname(__DIR__, 2) . '/templates/header.php';
?>
<section class="pg-hero"><div class="pg-hero-inner"><h1>Contact messages</h1><p>Messages sent through the public contact form. Only admins can see this page.</p></div></section>
<div class="pg-wrap">
  <?php if (!$rows): ?>
    <div class="empty-state"><h2>No messages yet</h2><p>New messages from the contact form appear here.</p></div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Received</th><th>From</th><th>Subject and message</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= tk_e(date('j M Y H:i', strtotime((string)$r['created_at']))) ?></td>
          <td><?= tk_e($r['name']) ?><br><a href="mailto:<?= tk_e($r['email']) ?>"><?= tk_e($r['email']) ?></a></td>
          <td><strong><?= tk_e($r['subject']) ?></strong><br><?= nl2br(tk_e($r['message'])) ?></td>
          <td><form method="post"><?= tk_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="handled" value="<?= empty($r['handled']) ? 1 : 0 ?>"><button class="btn btn-outline" type="submit"><?= empty($r['handled']) ? 'Mark handled' : 'Reopen' ?></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
