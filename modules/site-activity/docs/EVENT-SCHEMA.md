# Event schema and naming convention

Names are lowercase `snake_case`, written as *what happened*: `object_action` (`event_card_clicked`, `search_submitted`).
Names ending `_attempted` mean the form was submitted, never that it succeeded. "Server" events are created only by trusted server code from real records; a browser that sends one is refused and the refusal is logged.

| Event | Category | Origin | Meaning |
|---|---|---|---|
| page_view | navigation | browser | page opened (once per document; repeat of the same page within 5 s ignored; bfcache restores not counted) |
| navigation_click | navigation | browser | header / footer / mobile-menu link |
| link_click | navigation | browser | other internal link |
| button_click | navigation | browser | button that is not a form submit |
| outbound_link_clicked | outbound | browser | link to another site (host only stored, never the URL) |
| search_submitted | search | browser | search form submitted; text redacted |
| filter_changed | search | browser | listing filter (category, city, when, price, sort, from, to) |
| event_card_clicked | event | browser | link to an event page; server maps it to the real event id |
| event_viewed | event | **server** | derived from a page_view of an event page |
| ticket_quantity_changed | checkout | browser | quantity field changed (number only) |
| login_attempted / registration_attempted / contact_submitted | account | browser | submit only; **no labels, no values** (and /auth is excluded entirely) |
| js_error | error | browser | short message, max 3 per page |
| checkout_started | checkout | **server** | a real order row was created |
| payment_initiated / payment_failed | checkout | **server** | defined, **not wired**: payments are disabled in this build |
| purchase_completed | checkout | **server** | order verified paid by server-side status; unique per order |

Stored columns: `event_uid` (unique, makes retries idempotent), `occurred_at` (UTC), `name`, `category`, `path`, `path_group`, `element_id`, `element_label`, `target_path`, `event_ref_id` (the site's own event id), `category_ref`, `order_id`, `value_num` (KES or quantity), `query_text`, `meta_json` (allow-listed keys only), `source`, `is_test`, plus session and visitor ids taken from the server's own cookies, never from the payload.

## Instrumenting a button or component
Nothing is needed for ordinary links/buttons: one delegated listener covers static and dynamically added elements. To give an element a **stable** id (recommended for important actions) add attributes:

```html
<a href="/pricing.php" data-track-id="header-pricing" data-track-label="Pricing">Pricing</a>
<button type="button" data-track-id="checkout-continue">Continue</button>
```
Ids must match `[A-Za-z0-9._:/#-]{1,80}`. Without `data-track-id` the tracker builds `<zone>.<path-or-label>` (e.g. `header.pricing-php`, `footer.about-php`), which stays stable when visible text is translated for links. Never put personal data in either attribute.
Server-side events for new flows: call `\SiteActivity\Conversions::checkoutStarted(...)` after the real record exists (see `tk_activity_checkout_started()`), and add a paid-order query to `HostAdapter::paidOrders()`.
