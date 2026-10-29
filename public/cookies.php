<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
tk_page_open('Cookie Policy | TicketsKenya', 'The cookies TicketsKenya uses and why.', 'Cookie Policy', 'What is stored in your browser.');
?>
<div class="prose">
  <h2>Cookies we set</h2>
  <ul><li><strong>tk_session</strong> (essential): keeps you logged in and carries the security token that protects forms. It is removed when it expires or you log out.</li></ul>
  <h2>Optional analytics cookies (only with your consent)</h2>
  <ul>
    <li><strong>sa_consent</strong>: remembers your privacy choice (up to 180 days).</li>
    <li><strong>sa_vid</strong>: a random pseudonymous identifier that can distinguish this browser during analytics. It may be treated as personal data. Set only if you accept analytics (up to 180 days).</li>
    <li><strong>sa_sid</strong>: a random code that groups one visit. Removed when you close the browser.</li>
  </ul>
  <p>These are first-party cookies and are not used to follow you on other websites. Change or withdraw your choice any time on the <a href="/privacy-settings.php">Privacy settings</a> page.</p>
  <h2>What we do not set</h2>
  <p>No advertising-network or cross-site tracking cookies.</p>
  <h2>Third-party content</h2>
  <p>Fonts load from Google Fonts. Your browser sends a request to Google to fetch them, which can include your network address. Event links lead to other sites with their own cookie rules.</p>
</div>
<?php tk_page_close();
