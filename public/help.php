<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$faqs = [
  ['Can I buy tickets on TicketsKenya?', 'Not yet. Online payment is not switched on, so no ticket can be bought or paid for here. Listings from other sources link to the organiser’s own page, where you can buy tickets.'],
  ['Why does an event say “Price not confirmed”?', 'We only show a price when it is confirmed. If it is not, check the organiser’s page for the current price.'],
  ['Why is there no poster on some events?', 'We only show an official poster when we have one we are allowed to use. Otherwise you see a plain card with the date and category.'],
  ['How do I find events on a certain day?', 'Open Explore Events and use Today, Tomorrow or This weekend, or open Filters to pick a date range, category, price and city.'],
  ['An event detail is wrong. What do I do?', 'Please tell us through the contact form with the event name and the correction. Always confirm the date, venue and price on the organiser’s page before you travel.'],
  ['How do I list my event?', 'Create an account and ask for organiser access through the contact form. Organisers can create, edit and publish their own events.'],
  ['I forgot my password.', 'Open the password help page from the log-in screen. Automatic reset emails are not set up yet, so the site operator resets it for you.'],
];
$ld = [['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faqs)]];
tk_page_open('Help and FAQ | TicketsKenya', 'Answers about finding events, tickets, prices, posters and listing an event on TicketsKenya.', 'Help and FAQ', 'Quick answers to common questions.', ['jsonLd' => $ld]);
?>
<div class="faq" id="faq">
  <?php foreach ($faqs as $f): ?>
    <details><summary><?= tk_e($f[0]) ?></summary><p><?= tk_e($f[1]) ?></p></details>
  <?php endforeach; ?>
</div>
<p style="margin-top:28px"><a class="btn btn-solid" href="/contact.php">Contact us</a></p>
<?php tk_page_close();
