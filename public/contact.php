<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$errors = []; $sent = false; $old = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? ''));
    if (!tk_verify_csrf(is_string($_POST[TK_CSRF_TOKEN_NAME] ?? null) ? $_POST[TK_CSRF_TOKEN_NAME] : null)) $errors[] = 'Your session expired. Please try again.';
    if (($_POST['website'] ?? '') !== '') { $sent = true; }                      // honeypot: pretend success, store nothing
    elseif (!$errors) {
        if ($old['name'] === '' || tk_strlen($old['name']) > 80) $errors['name'] = 'Enter your name (up to 80 characters).';
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || tk_strlen($old['email']) > 120) $errors['email'] = 'Enter a valid email address.';
        if ($old['subject'] === '' || tk_strlen($old['subject']) > 120) $errors['subject'] = 'Enter a subject (up to 120 characters).';
        if (tk_strlen($old['message']) < 10 || tk_strlen($old['message']) > 3000) $errors['message'] = 'Write a message between 10 and 3000 characters.';
        if (!$errors && time() - (int)($_SESSION['last_contact'] ?? 0) < 30) $errors[] = 'Please wait a few seconds before sending another message.';
        if (!$errors) {
            tk_repo_insert('contact_messages', ['name' => $old['name'], 'email' => $old['email'], 'subject' => $old['subject'], 'message' => $old['message'], 'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'created_at' => date('c'), 'handled' => 0]);
            $_SESSION['last_contact'] = time();
            $sent = true; $old = array_map(fn() => '', $old);
        }
    }
}
tk_page_open('Contact TicketsKenya', 'Send a message to the TicketsKenya team: corrections, organiser access, or questions about an event listing.', 'Contact us', 'Tell us what you need and we will read your message.', ['bodyClass' => 'inner contact-page']);
?>
<div class="contact-shell">
  <aside class="contact-aside" aria-label="Contact information">
    <div class="contact-signal"><span class="contact-signal-dot" aria-hidden="true"></span> TICKETS KENYA <span class="contact-signal-slash">/</span> SUPPORT</div>
    <div class="contact-orbit" aria-hidden="true"><span></span><i></i><b></b></div>
    <p class="contact-kicker">A HUMAN IS ON THE OTHER SIDE</p>
    <h2>Let’s make<br><em>it happen.</em></h2>
    <p class="contact-aside-copy">Questions about an event, your order, or bringing your own event to TicketsKenya? Send us a note and our team will take it from here.</p>
    <div class="contact-direct">
      <span class="contact-mono">PREFER EMAIL?</span>
      <a href="mailto:<?= tk_e(TK_SUPPORT_EMAIL) ?>"><?= tk_e(TK_SUPPORT_EMAIL) ?><span aria-hidden="true"> ↗</span></a>
    </div>
    <div class="contact-aside-foot"><span>01</span><span>Tell us what you need</span><span class="contact-aside-line"></span></div>
  </aside>

  <section class="contact-card" aria-labelledby="contact-form-title">
    <div class="contact-card-head"><div><p class="contact-mono">MESSAGE / 01</p><h2 id="contact-form-title">Send a message</h2></div><span class="contact-card-mark" aria-hidden="true">TK<span>↗</span></span></div>
    <?php if ($sent): ?><div class="flash flash-success contact-flash" role="status"><strong>Message received.</strong><span>It is saved for our team to review. Automatic email replies are not configured.</span></div><?php endif; ?>
    <?php if ($errors): ?><div class="flash flash-error contact-flash" role="alert"><strong>Please check your message.</strong><ul><?php foreach ($errors as $m): ?><li><?= tk_e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="/contact.php" class="form contact-wrap">
      <?= tk_csrf_field() ?>
      <p class="contact-honeypot" aria-hidden="true"><label>Leave empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
      <div class="contact-fields">
        <?php foreach ([['name', 'Your name', 'text', 'name', 80], ['email', 'Email address', 'email', 'email', 120]] as [$k, $label, $type, $ac, $max]): ?>
          <div class="field"><label for="c-<?= $k ?>"><span class="contact-field-index">0<?= $k === 'name' ? '1' : '2' ?></span><?= tk_e($label) ?></label>
            <input id="c-<?= $k ?>" name="<?= $k ?>" type="<?= $type ?>" value="<?= tk_e($old[$k]) ?>" autocomplete="<?= $ac ?>" maxlength="<?= $max ?>" required<?= isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '' ?>>
            <?php if (isset($errors[$k])): ?><small id="e-<?= $k ?>" class="contact-error"><?= tk_e($errors[$k]) ?></small><?php endif; ?></div>
        <?php endforeach; ?>
        <div class="field contact-subject"><label for="c-subject"><span class="contact-field-index">03</span>Subject</label>
          <input id="c-subject" name="subject" type="text" value="<?= tk_e($old['subject']) ?>" autocomplete="off" maxlength="120" required<?= isset($errors['subject']) ? ' aria-invalid="true" aria-describedby="e-subject"' : '' ?>>
          <?php if (isset($errors['subject'])): ?><small id="e-subject" class="contact-error"><?= tk_e($errors['subject']) ?></small><?php endif; ?></div>
        <div class="field contact-message"><label for="c-message"><span class="contact-field-index">04</span>Your message</label>
          <textarea id="c-message" name="message" rows="6" minlength="10" required maxlength="3000" placeholder="Give us a little context so we can help…"<?= isset($errors['message']) ? ' aria-invalid="true" aria-describedby="e-message"' : '' ?>><?= tk_e($old['message']) ?></textarea>
          <?php if (isset($errors['message'])): ?><small id="e-message" class="contact-error"><?= tk_e($errors['message']) ?></small><?php endif; ?></div>
      </div>
      <p class="contact-privacy-note">We use your message details to respond and help prevent abuse. <a href="/privacy.php">Read our Privacy Policy</a>.</p>
      <div class="contact-submit-row"><button class="btn btn-solid contact-submit" type="submit"><span>Send message</span><span class="contact-submit-arrow" aria-hidden="true">↗</span></button><span class="contact-submit-note">Usually answered by a real person</span></div>
    </form>
  </section>
</div>
<?php tk_page_close();
