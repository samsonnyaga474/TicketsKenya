# TicketsKenya — Pesapal API 3.0 Integration & Security Audit

**Date:** 2026-10-28  
**Scope:** Integrate Pesapal API 3.0 JSON into the existing TicketsKenya PHP application without redesigning the site.

## 1. What was implemented

### Payment flow (server-side only)
1. Customer creates a pending order (existing `events/view.php` logic — server-calculated totals, inventory lock, CSRF).
2. On the order page, if Pesapal is configured, a **Pay with Pesapal** button posts to `/payments/start.php`.
3. `start.php` creates a unique merchant reference (`TK-{orderId}-{hex}`), calls Pesapal **SubmitOrderRequest**, stores `payment_reference` + `provider_transaction_id`, then redirects the browser to Pesapal’s hosted checkout.
4. After payment, Pesapal redirects to `/payments/callback.php` and also calls the registered IPN at `/payments/webhook.php`.
5. Both endpoints **only** treat the signal as a hint. They call **GetTransactionStatus** with the server-side token, then validate:
   - merchant_reference matches the order
   - amount and currency match the stored order total
   - status_code === 1 (COMPLETED)
6. Only then does `tk_payment_issue_tickets()` run under a file lock + SQLite immediate transaction, create unique ticket codes once, and mark the order `paid`.
7. Duplicate IPN/callback is safe: already-paid orders return success without creating extra tickets.

### Files changed / added
| File | Purpose |
|------|---------|
| `includes/payments.php` | Full Pesapal client: auth token (cached), submit order, get status, idempotent fulfilment, ticket issuance |
| `public/payments/start.php` | Start payment, submit to Pesapal, redirect |
| `public/payments/callback.php` | Browser return URL — re-verify then show order |
| `public/payments/webhook.php` | IPN endpoint (GET/POST) — re-verify, respond with Pesapal JSON format |
| `public/payments/status.php` | Optional refresh/poll that re-queries status |
| `public/tickets/order.php` | Pay button when unpaid + configured; clearer copy |
| `config/config.php` | Safe `.env` loader (project root only) + env var comments |
| `.env.example` | Placeholders for Pesapal keys, notification id, env |
| `tools/register-pesapal-ipn.php` | CLI to register public HTTPS IPN URL and print `ipn_id` |
| `tests/test_payments_logic.php` | Offline unit checks (no network) |
| `PESAPAL-INTEGRATION-REPORT.md` | This report |
| `SETUP-PESAPAL.md` | Setup & IPN steps |

### Preserved
- Existing design, event catalogue, auth, order creation, inventory soft-reserve, activity module, discovery listings.
- No hardcoded secrets.
- Prepared statements / repo layer, CSRF on state-changing POSTs, Argon2id, secure session cookies, security headers.

## 2. Security audit (summary)

| Control | Status |
|---------|--------|
| Credentials only from environment | Yes — `PESAPAL_CONSUMER_*`, never in source |
| Server-side amount/currency check before fulfil | Yes |
| Merchant reference + tracking id validation | Yes |
| Callback alone never marks paid | Yes — always GetTransactionStatus |
| Idempotent ticket issuance | Yes — lock + existing-ticket check |
| IDOR: customer can only act on own orders | Yes — user_id checks |
| CSRF on payment start | Yes |
| Secrets not logged | Yes — errors redacted |
| Public web root cannot serve `.env` / data / config | Existing `.htaccess` + structure kept |
| Reversal handled as separate status | Yes (`reversed`), not silently deleted |

**Residual risks (honest):**
- Local XAMPP / `localhost` is not reachable by Pesapal IPN. You need a public HTTPS URL (or a temporary tunnel for sandbox testing) before IPN works.
- Without real sandbox credentials, live API calls cannot be executed here.
- Email delivery of tickets is still not configured (existing limitation).
- Soft inventory reservation on order create is not auto-expired; admin/cancel remains the release path in this architecture.
- Application is not “hacker-proof”; standard residual risk of any public PHP site remains.

## 3. Test report

### Passed (ran successfully)
- PHP syntax on all new/changed payment files
- Offline logic tests (`tests/test_payments_logic.php`): 9/9  
  - configured detection  
  - merchant reference format & length  
  - ticket code generation  
  - sandbox/live base URL selection  
  - invalid tracking id rejection  

### Blocked (requires your credentials / public URL)
- Real Pesapal sandbox authentication
- SubmitOrderRequest against sandbox
- RegisterIPN against sandbox
- End-to-end M-Pesa sandbox payment + IPN + callback
- Live environment (explicitly disabled until you set `PESAPAL_ENV=live` and authorize)

### Not tested in this environment
- Concurrent duplicate IPN under high load (logic is idempotent; load test not run)
- Email after payment (mail not configured)
- Full browser UI regression of every admin page (no design changes)

## 4. Sandbox-ready vs live-ready

| Feature | Sandbox-ready | Live-ready |
|---------|---------------|------------|
| Code paths for auth / submit / status / IPN / fulfil | Yes | Yes (same code) |
| Credentials | You must paste sandbox key/secret | Separate live key/secret from Pesapal |
| IPN URL | Public HTTPS required | Public HTTPS required |
| `PESAPAL_ENV` | `sandbox` (default) | Set `live` only after your explicit approval |
| Real money | Never used in this work | Disabled until you enable live |

## 5. Rollback
1. Restore previous `includes/payments.php` and the four `public/payments/*.php` files from your last backup ZIP.
2. Remove `PESAPAL_*` environment variables.
3. No database migration is required that cannot be left in place; new columns were already present from earlier additive migrations.
4. Orders that remained `pending` stay pending; already-paid orders keep their tickets.

## 6. Next actions for you
1. Obtain sandbox consumer key + secret from Pesapal.
2. Deploy (or tunnel) a public HTTPS URL pointing at `/payments/webhook.php`.
3. Run `php tools/register-pesapal-ipn.php https://your-domain/payments/webhook.php` and set `PESAPAL_NOTIFICATION_ID`.
4. Set env vars, restart PHP, create a small sandbox order, pay with Pesapal sandbox methods.
5. Only after successful sandbox end-to-end, request live credentials and set `PESAPAL_ENV=live` when you are ready.
