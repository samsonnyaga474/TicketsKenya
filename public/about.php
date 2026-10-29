<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
tk_page_open('About TicketsKenya | Event discovery in Kenya', 'TicketsKenya helps people in Kenya find live events and helps organisers list them. Learn how the platform works.', 'About TicketsKenya', 'A Kenyan home for finding live events.', ['jsonLd' => tk_breadcrumb_ld([['Home', '/'], ['About', '/about.php']])]);
?>
<div class="prose">
  <h2>What TicketsKenya does</h2>
  <p>TicketsKenya lists upcoming events across Kenya so you can browse by date, category and place in one spot. Each listing shows the date, venue and a price only when the price is confirmed.</p>
  <h2>Event listings and ticket sales</h2>
  <div class="cards-3" style="grid-template-columns:1fr;max-width:46rem">
    <div><h3>Event listings</h3><p>Listings help visitors discover dates, places and categories. A discovery listing is not an offer of tickets or a claim that TicketsKenya has inventory.</p></div>
    <div><h3>Tickets sold on TicketsKenya</h3><p>Only events with TicketsKenya-managed ticket inventory can offer on-site checkout. Payment is currently disabled, so orders remain pending and no ticket is issued.</p></div>
  </div>
  <div class="callout"><strong>Payments:</strong> card and mobile-money payments are not active. No charge is taken anywhere on this site.</div>
  <h2>Get in touch</h2>
  <p>Questions, corrections or an event to add? Use the <a href="/contact.php">contact form</a>, or read the <a href="/help.php">help and FAQ</a> page.</p>
</div>
<?php tk_page_close();
