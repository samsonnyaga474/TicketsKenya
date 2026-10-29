# TicketsKenya — Project Audit, Payment Status, Security & Pre-Launch Report
Date: 2026-10-20 (Africa/Nairobi)

## 1. Authoritative source

| Archive | Role |
|---------|------|
| **ticketskenya-flutterwave-sandbox.zip** | Baseline continued in this build |
| ticketkenyahttracks.zip | Visual/reference only (HustleSasa HTTrack) |
| Older complete/homepage ZIPs | Superseded |

Working project root: `ticketskenya/`

## 2. What was implemented in this pass

- Continued from Flutterwave sandbox project (not a rewrite).
- Replaced sellable **fake demo events** with **verified discovery listings** of real upcoming Kenyan events (public sources, verified 2026-10-20).
- Discovery listings **cannot** create TicketsKenya orders; they link to official ticket URLs.
- Added central fee config (`TK_PLATFORM_FEE_PERCENT`, `TK_WITHDRAWAL_FEE_KES`) with documented HustleSasa reference (5% + KES 100 withdrawal).
- Added `/pricing.php` and `/organisers.php`.
- Expanded header/mobile navigation (all links resolve).
- Added security response headers (CSP, nosniff, frame options, referrer, permissions, HSTS in production).
- Schema supports `listing_type`, `source_url`, `official_ticket_url`, `category`, `verified_at`.
- Demo accounts seed only when `TK_DEMO_MODE=true` (development).

## 3. Verified real events (discovery only)

| Event | When | Venue | Source verified |
|-------|------|-------|-----------------|
| Oktoberfest 2026 | 23–24 Oct 2026 | Nairobi Street Kitchen | nairobieventsguide.com 2026-10-20 |
| MYSTIKA Halloween | 31 Oct 2026 | KODA Nairobi | nairobieventsguide.com 2026-10-20 |
| Turbo Nights Halloween | 31 Oct 2026 | The Junction Mall | nairobieventsguide.com 2026-10-20 |
| International Mediation Conference | 29–30 Oct 2026 | Golden Tulip Westlands | nairobieventsguide.com + icmc.or.ke 2026-10-20 |
| Nation Digital Summit | 27–28 Oct 2026 | KOFISI Kaskazi | nairobieventsguide.com 2026-10-20 |
| DevFest Nairobi 2026 | 14 Nov 2026 | Sarit Expo Centre | nairobieventsguide.com 2026-10-20 |

TicketsKenya does **not** sell tickets for these unless a formal inventory agreement exists.

## 4. Pricing comparison

| Item | HustleSasa (public ToS) | TicketsKenya config |
|------|-------------------------|---------------------|
| Transaction fee | 5% online transactions | 5% (`TK_PLATFORM_FEE_PERCENT`) |
| Withdrawal fee | KES 100 | KES 100 (`TK_WITHDRAWAL_FEE_KES`) |
| Source URL | support.hustlesasa.com Terms of Service | Verified 2026-10-20 |
| Affiliation | — | **None claimed** |

Payment-provider fees are additional.

## 5. Flutterwave payment status

| Capability | Status |
|------------|--------|
| Sandbox-only key enforcement (`FLWSECK_TEST-`) | Implemented |
| Hosted card checkout start | Implemented (code) |
| M-Pesa charge via Flutterwave | Implemented (code) |
| Server-side transaction verify | Implemented (code) |
| Webhook hash check | Implemented (code) |
| Idempotent ticket issuance (SQLite) | Implemented (code) |
| Browser redirect cannot mark paid alone | Enforced in design |
| End-to-end sandbox charge with real test keys | **Not tested here** (no credentials) |
| Live/production mode | **Blocked** by design |

## 6. Security audit (50 areas — summary)

| # | Risk | Status |
|---|------|--------|
| 1–3 | Exposed DB/API secrets / .env | No real secrets in tree; `.env.example` placeholders only |
| 4–5 | Auth / server-side roles | Login + role checks present |
| 6, 33–34 | IDOR / user data isolation | Orders filtered by user_id; admin by role |
| 9 | Admin routes | Require organiser/admin |
| 12 | Verbose errors | Off in production |
| 16–17 | Validation / SQL injection | Prepared statements when SQLite; validated inputs |
| 19–20 | XSS / CSRF | `tk_e()` escaping; CSRF tokens on state-changing forms |
| 25, 47 | Sessions / cookies | HttpOnly, SameSite; Secure recommended on HTTPS |
| 28 | Rate limits | Login attempt window present; expand for production |
| 31–32 | Webhooks / frontend payment trust | Signature hash + server re-verify designed |
| 46 | Security headers | Added CSP and related headers |
| Others | See remaining limitations | Monitoring, full backup runbooks, dependency scanner CI not automated here |

**Not production-ready** until: live keys never committed, HTTPS, demo mode off, merchant KYC complete, full payment tests pass, backups verified.

## 7. Tests executed in this environment

| Test | Result |
|------|--------|
| PHP syntax lint (all .php files) | Pass |
| Homepage HTTP 200 | Pass |
| Events list 200 + real titles present | Pass |
| Event detail (Oktoberfest) 200 | Pass |
| Pricing / Organisers / Auth / Legal pages 200 | Pass |
| Flutterwave live API charge | **Not run** (no test keys) |
| Webhook with real Flutterwave signature | **Not run** |
| PDO SQLite payment transaction | **Depends on local PHP** |

## 8. Remaining blockers before launch

1. Your Flutterwave **TEST** keys in local `.env` (never commit).
2. Successful sandbox card + M-Pesa tests on your machine.
3. Merchant approval / M-Pesa activation confirmation.
4. Set `TK_DEMO_MODE=false` and remove demo accounts for production.
5. Real support email / business identity on Contact (no home address).
6. HTTPS hosting + env secrets on the server.
7. Email delivery for tickets (not configured).
8. Your explicit approval to go live.

## 9. Monero

Not implemented. Requires separate approval and a documented settlement design before any button is shown.

## Repair pass (2026-10-21)

- Added `tools/sync-discovery.php` and `tools/discovery-sources.json` for zero-cost sync from explicitly configured public HTTPS RSS/Atom/iCalendar feeds; a sample iCalendar URL is `https://soldoutafrica.com/events.ics`, disabled by default because the provider terms prohibit republishing without consent.
- Sync records creation/update/unchanged/skipped counts and changed fields in `data/discovery-sync-log.json`; it does not delete stale items or infer cancellations from feed disappearance.
- Added matching marquee markup to the homepage event cards and culture strip, plus touch/focus/visibility pause handling.
- Disabled callback, webhook and status-verification endpoints as well as payment initiation so old credentials cannot fulfil orders in this phase.
- Added a development-only demo admin account to align the regression suite; demo accounts must not be enabled for public production.
- The feed parser deliberately skips entries without a clearly parseable upcoming event date. A live feed can change its format or terms, so sync output and logs must be reviewed; this does not promise exhaustive discovery across Kenya.
