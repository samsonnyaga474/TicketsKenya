# TicketKenya Flutterwave Sandbox Implementation Report

## Baseline
- Source archive: `ticketskenya-complete.zip`
- Stack: PHP, PDO-backed SQLite where available, JSON fallback for non-payment development
- Existing order flow creates pending orders; payment code was absent.

## Changes in this build
- Added `includes/payments.php` for sandbox-key enforcement, Flutterwave API requests, transaction verification, and idempotent ticket issuance.
- Added `public/payments/start.php` for card checkout and M-Pesa charge initiation.
- Added `public/payments/callback.php` for post-checkout verification.
- Added `public/payments/webhook.php` for signature-hash validation and server-side transaction verification.
- Added `public/payments/status.php` to re-check a pending M-Pesa transaction.
- Added payment choices to the existing order page without redesigning the site.
- Added SQLite migration columns for payment method/status, provider transaction ID, and paid timestamp.
- Added `.env.example` with placeholders only and updated `README.md` setup notes.

## Safety controls
- This build accepts only a Flutterwave test secret key beginning `FLWSECK_TEST-` and refuses payment processing when `TK_ENV=production`.
- No real payment credentials were added.
- The card path redirects to Flutterwave-hosted checkout; TicketKenya does not collect raw card numbers/CVV.
- Payment callbacks and webhooks do not independently prove payment. The server re-verifies transaction ID, reference, KES currency, successful status, and amount with Flutterwave.
- Ticket issuance is performed inside a SQLite transaction and is idempotent for already-paid orders.
- Payment processing fails closed if PDO SQLite is unavailable; the JSON fallback is not used for payments.

## Validation performed in this workspace
- PHP syntax lint passed for every PHP file.
- Local smoke test: homepage returned HTTP 200.
- Local smoke test: events page returned HTTP 200.
- Unauthenticated payment start endpoint redirected to login/order flow rather than initiating a payment.
- Webhook endpoint rejected a request without the configured verification hash (HTTP 401).

## Not tested / blockers
- No Flutterwave account or test keys were provided; no actual API charge was attempted.
- This runtime lacks the PDO SQLite extension, so transactional payment fulfillment could not be exercised end-to-end here. Enable PDO SQLite in the local PHP/XAMPP installation before testing payments.
- The integration uses PHP HTTPS streams, so `allow_url_fopen` must be enabled.
- Webhooks cannot reach `localhost` directly. For sandbox webhook tests, configure the Test-mode webhook URL on a temporary trusted HTTPS tunnel, then remove the tunnel after testing.
- Flutterwave account eligibility, Kenyan merchant approval, and M-Pesa activation must be confirmed in the user's dashboard.
- The demo accounts/events remain. Do not deploy publicly or use live credentials yet.
- Inventory may become insufficient after payment initiation. In that exceptional case the order is not automatically fulfilled; it requires manual reconciliation/refund handling.

## Before public launch
1. Run the site locally and confirm normal login/order pages still work.
2. Enable PDO SQLite and test with Flutterwave TEST credentials only.
3. Test card success/failure, M-Pesa prompt success/cancel, invalid webhook hash, duplicate webhook, and delayed payment verification.
4. Confirm tickets issue once only after verified payment.
5. Add production-safe handling for refunds, payment/inventory reconciliation, logging/monitoring, rate limits, and transactional stock reservation.
6. Remove/disable demo credentials and demo events; review contact/legal pages, hosting, HTTPS, and secrets configuration.
7. Do not switch to live keys until all tests and merchant approvals are complete.
