<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
tk_page_open('Privacy Policy | TicketsKenya', 'What personal data TicketsKenya collects, why, and how it is protected.', 'Privacy Policy', 'Plain-language summary of how this site handles personal data.');
?>
<div class="prose">
  <div class="callout"><strong>Draft for the site owner to complete.</strong> This text describes what the software actually does today. The owner must add the legal entity name, a privacy contact and have it reviewed against the Kenya Data Protection Act, 2019 before launch.</div>
  <h2>What we collect</h2>
  <ul>
    <li><strong>Account details:</strong> name, email address and a password, which is stored only as a one-way hash.</li>
    <li><strong>Contact form:</strong> the name, email, subject and message you send, plus the network address you sent it from (to limit abuse).</li>
    <li><strong>Orders:</strong> order records for accounts that start one. No payment details are collected, because payments are not available.</li>
    <li><strong>Log-in attempts:</strong> email and network address, kept to slow down password guessing.</li>
    <li><strong>Optional site activity:</strong> only after you accept analytics, this site records page views, selected link/button use, event interest, search/filter activity, broad device/browser details and session timing. When you are signed in, activity may be linked to your account. It does not record passwords, payment details, or contact-form contents. Search text is optional and can be switched off; emails and number-like text are removed.</li>
    <li><strong>Network address for analytics:</strong> the default setting stores a shortened address; other privacy-preserving storage modes are available to the owner. The web host may separately keep operational access logs.</li>
    <li><strong>Optional marketing audiences:</strong> if enabled by the owner and separately selected by you, your browser may be counted in a first-party audience definition. No audience is automatically sent to an advertising platform.</li>
  </ul>
  <h2>Your choices and use</h2>
  <p>Analytics is off until you accept. Rejecting does not block the site. You can change or withdraw your choice and request deletion of activity linked to this browser from <a href="/privacy-settings.php">Privacy settings</a>. Activity reports count browsers, not people. Current default retention periods are 90 days for activity events, 30 days for stored IP values, 365 days for sessions, 730 days for administrative audit records and 3 years for consent records; the owner can configure some periods.</p>
  <p>We do not sell personal data. The site does not currently connect to advertising-platform uploads. This notice is an implementation summary, not a statement that the site's legal obligations have been reviewed.</p>
  <h2>Cookies and third parties</h2>
  <p>One essential session cookie keeps you signed in and protects forms. Page fonts are loaded from Google Fonts, so your browser contacts Google when a page loads. See the <a href="/cookies.php">Cookie Policy</a>.</p>
  <h2>Your choices</h2>
  <p>Use <a href="/privacy-settings.php">Privacy settings</a> for this browser's activity choices and deletion. For account/order access, correction or other requests, use the <a href="/contact.php">contact form</a>.</p>
</div>
<?php tk_page_close();
