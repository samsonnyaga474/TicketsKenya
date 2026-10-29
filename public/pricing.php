<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
tk_page_open('Pricing for organisers | TicketsKenya', 'Planned TicketsKenya platform fees for organisers who sell tickets on the platform.', 'Pricing', 'Planned fees for organisers. Listing is free; selling starts when payments are enabled.');
?>
<div class="prose">
  <div class="callout"><strong>Not charged yet.</strong> Payments are off, so no fee is being collected today.</div>
  <div class="cards-3">
    <div><h3>Listing an event</h3><p>No fee to list or publish an event.</p></div>
    <div><h3>Platform fee</h3><p><?= tk_e(number_format(TK_PLATFORM_FEE_PERCENT, 1)) ?>% of the ticket total on tickets sold through TicketsKenya, once payments are enabled.</p></div>
    <div><h3>Withdrawals</h3><p>KES <?= (int)TK_WITHDRAWAL_FEE_KES ?> per withdrawal of organiser balances, once payments are enabled.</p></div>
  </div>
  <p>Payment-provider charges and taxes may apply in addition. Listings from other sources link to the organiser’s own page and carry no TicketsKenya fee.</p>
</div>
<?php tk_page_close();
