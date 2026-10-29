# TicketsKenya — Final work report (2026-10-24)

Support email (confirmed by you): **ticketskenyasupport@gmail.com**  
Policy you set: **every event is owned by you; all ticket sales stay on TicketsKenya.**

---

## What I did

### Ownership and inventory
- Converted **all 37 events** from `discovery` to **`owned`**.
- Cleared external `official_ticket_url` sales destinations so buyers are not sent away to buy.
- Gave **every event** at least one ticket type (General Admission / Free entry, plus VIP when price > 0).
- Assigned real upload posters to events that only had culture stock images.
- Result check: **0 discovery**, **0 events without ticket types**, **0 events without upload poster**.
- Added `tools/ensure-owned-inventory.php` so you can re-run this conversion any time.
- Updated `tools/import-discovery.php` so seed imports create **owned** events with ticket types, not external discovery listings.

### On-site ticket purchase
- Event detail page for owned published events shows quantity pickers and **Get Ticket**.
- POST creates a **pending** order, order lines, and reduces stock (server-side, CSRF protected, login required).
- Discovery / external “buy on official site” path is disabled for the public sales UI.
- Card label always shows **On TicketsKenya**.

### Support contact
- `TK_SUPPORT_EMAIL` defaults to `ticketskenyasupport@gmail.com`.
- Footer and contact page show that address.
- `.env.example` documents the setting.

### Homepage
- CTA fixed earlier: **See All Events** (no hardcoded count).

### Payments (honest status)
- **Still fail-closed.** No live Paystack/Pesapal/M-Pesa charge runs.
- Orders stay **pending**. No paid tickets are issued from a browser click alone.
- This is deliberate until your payment account is approved and sandbox tests pass with real test keys in environment variables only.

### Tests run
- PHP lint on project PHP files: clean.
- `tests/test_discovery.php`: 26/26 pass.
- `tests/test_project_integrity.py`: 0 failed.
- Manual order-creation simulation: pass (pending order + stock decrement).

---

## What I did **not** do (and why)

| Item | Why |
|------|-----|
| Live M-Pesa / card charges | Your Paystack account was under review; no live secrets; cannot invent successful live payments |
| Real ticket confirmation emails | No SMTP/mail API configured; `mail()` alone is not reliable delivery |
| Claim “production ready for money” | Payment verification + HTTPS + demo mode off still required |
| Register a free domain or deploy live | You forbade charges without approval; no deploy without authorization |
| Invent official posters from copyrighted sources | Used your existing uploads; assigned pool posters where culture images were used |
| Legal review of privacy/terms | Drafts exist; not a lawyer review for Kenya |
| Full WCAG / Lighthouse scores | Not re-measured in this pass; no invented scores |
| Replace PHP with another stack | Explicitly forbidden |
| Fake reviews, fake sold counts, fake payment success | Forbidden by your rules |

---

## How to install on Windows 11 + XAMPP

1. Back up `C:\xampp\htdocs\TicketsKenya` if it already exists.
2. Extract the new ZIP so the folder is `C:\xampp\htdocs\TicketsKenya` (with `public`, `config`, `includes`, `tools` inside).
3. Optional: copy `.env.example` to `.env` in the project root and edit if needed.
4. Open Command Prompt:
   ```bat
   cd C:\xampp\htdocs\TicketsKenya
   C:\xampp\php\php.exe tools\import-discovery.php
   C:\xampp\php\php.exe tools\ensure-owned-inventory.php
   cd public
   C:\xampp\php\php.exe -S localhost:8080 router.php
   ```
5. Open `http://localhost:8080`.
6. Demo login (only while `TK_DEMO_MODE=true`):  
   - Admin: `demo.admin@ticketskenya.local` / `DemoPass123!`  
   - Customer: `demo.customer@ticketskenya.local` / `DemoPass123!`
7. Open any event → choose quantity → **Get Ticket** → order is **pending**.

### When payment provider is approved
1. Put **test** keys only in environment / `.env` (never commit secrets).
2. Ask for the next phase: turn on sandbox Paystack or Pesapal with webhook signature checks and idempotent ticket issue.
3. Test card + M-Pesa in sandbox.
4. Only then switch to live keys on HTTPS with `TK_DEMO_MODE=false`.

---

## Launch checklist

### A. Done and tested locally
- All events treated as owned inventory
- On-site order creation for published events
- Posters present for published events
- Support email displayed
- See All Events CTA
- CSRF, auth, fail-closed payments
- Search/filters/admin structure from prior build retained

### B. Waiting on you
- Payment provider approval + keys
- Mail delivery service
- Confirm any legal business details for KYC (keep private)
- Domain / DNS / hosting choice (zero-cost preferred)
- Turn off demo mode for production

### C. Not production-ready yet
- Live payment collection
- Automatic paid ticket email
- Guaranteed free long-term hosting

---

## Files most important in this pass
- `public/events/view.php` — on-site Get Ticket
- `public/index.php` — See All Events
- `config/config.php` — `TK_SUPPORT_EMAIL`
- `tools/import-discovery.php` — imports as owned + ticket types
- `tools/ensure-owned-inventory.php` — bulk ownership fixer
- `templates/footer.php` / `public/contact.php` — support email
- `includes/events.php` — card label On TicketsKenya
- `AUDIT-REPORT-2026-10-24.md` — this report
