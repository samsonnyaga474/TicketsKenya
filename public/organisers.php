<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$u = tk_user();
tk_page_open('For event organisers | TicketsKenya', 'List and manage your events on TicketsKenya. Create events, set ticket types and reach people looking for what is on in Kenya.', 'For organisers', 'List your event where people are looking for something to do.');
?>
<div class="prose">
  <div class="cards-3" style="grid-template-columns:1fr">
    <div><h3>Create and publish</h3><p>Add the title, date, venue, category and poster. Publish when ready, or keep it as a draft.</p></div>
    <div><h3>Set ticket types</h3><p>Define ticket names, prices and quantities for your own events.</p></div>
    <div><h3>Stay in control</h3><p>You only see and edit your own events. Admins can help if something needs fixing.</p></div>
  </div>
  <div class="callout"><strong>Payments are not available yet.</strong> Organisers can list events now. Selling tickets online comes after payments are set up. Fees are on the <a href="/pricing.php">pricing page</a>.</div>
  <?php if ($u && in_array($u['role'], ['organiser', 'admin'], true)): ?>
    <a class="btn btn-solid" href="/admin/">Go to dashboard</a>
  <?php elseif ($u): ?>
    <p>Your account is a customer account. <a href="/contact.php">Ask for organiser access</a>.</p>
  <?php else: ?>
    <a class="btn btn-solid" href="/auth/register.php">Create an account</a> <a class="btn btn-outline" href="/auth/login.php">Log in</a>
  <?php endif; ?>
</div>
<?php tk_page_close();
