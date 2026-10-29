<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$pageTitle = 'Password help | TicketsKenya';
$noindex = true;
require dirname(__DIR__, 2) . '/templates/header.php';
?>
<div class="section-inner">
  <div class="form-card">
    <h1>Password help</h1>
    <p class="form-note" style="margin-bottom:14px">Automatic reset emails are not set up on this site yet, so a reset link cannot be sent.</p>
    <p class="form-note" style="margin-bottom:14px">Send a message through the <a href="/contact.php">contact form</a> from the email address on your account. The site operator will verify it is you and set a new password.</p>
    <p class="form-note">Operators: run <code>php tools/reset-password.php your@email.com</code> on the server.</p>
    <div class="form-actions" style="margin-top:18px"><a class="btn btn-solid" href="/auth/login.php">Back to log in</a></div>
  </div>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
