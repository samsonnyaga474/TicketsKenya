# TicketsKenya

Kenyan event discovery and ticketing platform (development build).

## Quick start (Windows + XAMPP)

1. Extract the ZIP into `C:\xampp\htdocs\` so the project folder is `C:\xampp\htdocs\TicketsKenya\` and contains `public`, `config`, `includes`, `tools`, and `README.md` directly.
2. Import the 50 event listings from the supplied event screenshots. Open Command Prompt or PowerShell and run:
   ```bat
   cd C:\xampp\htdocs\TicketsKenya
   C:\xampp\php\php.exe tools\import-discovery.php
   ```
   The importer is safe to run again; it updates matching listings without creating duplicates. These are discovery listings only. TicketsKenya does not sell these tickets, and online payments stay disabled.
3. For local development, open Command Prompt and run:
   ```bat
   cd C:\xampp\htdocs\TicketsKenya\public
   C:\xampp\php\php.exe -S localhost:8080 router.php
   ```
4. Open `http://localhost:8080`.

### Apache / XAMPP hosting

Configure an Apache VirtualHost whose `DocumentRoot` is `C:/xampp/htdocs/TicketsKenya/public` (not the project folder). Enable `AllowOverride All` for that directory so `public/.htaccess` is applied, and keep `config/`, `data/`, `includes/`, `modules/`, `templates/`, `tests/`, and `tools/` outside the web root. The project-root `.htaccess` denies requests if Apache is pointed at the wrong directory. Set `TK_ENV=production`, `TK_APP_URL=https://your-domain.example`, and `TK_DEMO_MODE=false` in the Apache service environment; configure HTTPS at the host and verify secure session cookies after restart. Do not use the PHP built-in server for public hosting.

The PHP built-in server is for local development only. It does not need a paid hosting account. The event feed sync requires an internet connection while it runs. The app uses SQLite when the PDO SQLite driver is enabled and otherwise falls back to a local JSON store.

Administrators can review recent, minimized authentication and suspicious-request signals at **Manage events → Security activity**. It records no submitted email addresses or full URLs, stores only shortened network prefixes, rate-caps repeated signals, and expires security-event rows after 30 days. The existing analytics dashboard remains opt-in and separate from these operational security signals.

## Development demo accounts

Demo accounts are generated only when `TK_DEMO_MODE=true`. Use only on an isolated local copy, never on a public site. The production environment must set `TK_DEMO_MODE=false`; do not rely on demo credentials for a real owner account.

## Zero-cost event discovery and tracking

TicketsKenya has two separate discovery commands:

### 1. Live public-feed synchronizer

`tools/sync-discovery.php` reads explicitly configured public RSS/Atom/iCalendar feeds from `tools/discovery-sources.json`. A sample iCalendar adapter entry is included for `https://soldoutafrica.com/events.ics`, but it is **disabled by default** because [the provider's terms, section 7](https://soldoutafrica.com/terms) prohibit republishing website information without consent. Do not enable it unless written permission has been obtained. Add and enable feeds from organisers or providers whose terms explicitly permit republication. The synchronizer uses PHP only; no paid API key, subscription, or scraping service is used.

Run from the project root:

```bat
C:\xampp\php\php.exe tools\sync-discovery.php
```

It validates HTTPS feed URLs, rejects non-HTTPS sources, skips entries without a clearly parseable future event date, uses a stable source-based slug, avoids duplicating matching source URLs, updates changed dates/details/statuses, and writes a bounded history to `data/discovery-sync-log.json`. Admin users can review this history at `/admin/discovery.php`. With no authorised feed enabled, the sync reports that limitation rather than publishing from a restricted source. It never deletes a listing just because it disappears from a feed, and never marks a listing cancelled unless the source entry explicitly says it is cancelled. Discovery listings cannot create TicketsKenya-owned paid orders and are not linked to external purchase pages.

Feed formats are source-dependent. RSS/Atom feeds often omit event dates or cancellation notices; those entries are skipped or retained rather than guessed. The system cannot guarantee every Kenyan event, and the configured source does not represent every event organiser. Add another feed only when it is public, stable, and its terms permit the intended reuse. A listing must always link back to its source.

### 2. Local seed importer (fallback/testing)

Edit `tools/discovery-seed.json`, then run:

```bat
C:\xampp\php\php.exe tools\import-discovery.php
```

This importer is separate from live feed sync. Repeated runs update matching slugs rather than adding duplicates.

### Schedule automatic sync on Windows (free)

1. Open **Task Scheduler** → **Create Basic Task**.
2. Name it `TicketsKenya Event Feed Sync` and choose a daily trigger (or a shorter interval if appropriate).
3. Set **Program/script** to `C:\xampp\php\php.exe`.
4. Set **Add arguments** to `tools\sync-discovery.php`.
5. Set **Start in** to `C:\xampp\htdocs\TicketsKenya`.
6. Save, then use **Run** on the task and check `data\discovery-sync-log.json` for the result.

Or create the same task in one line from Command Prompt (runs every 6 hours; adjust the paths if your project is elsewhere):

```bat
schtasks /Create /TN "TicketsKenya Event Feed Sync" /SC HOURLY /MO 6 /TR "cmd /c cd /d C:\xampp\htdocs\TicketsKenya && C:\xampp\php\php.exe tools\sync-discovery.php >> data\sync-task.log 2>&1"
```

The sync takes a lock, so two runs can never overlap, and it exits with code 0 on success, 1 if a source failed or none is enabled, 2 for bad configuration and 3 if another run is in progress. A failed source never cancels or removes events by itself. The bundled source is disabled until written permission from its owner is obtained; no broad Kenyan events feed with documented reuse permission is bundled, so a fresh install has no live source and the sync will report that.

This costs KSh 0, but the PC must be on and connected to the internet for a local scheduled task to run. A public hosted website will need a scheduler on its host; always check that the chosen host's free tier supports it.

## Payments

All payment initiation, callback, webhook, and status-verification endpoints are disabled in this build. They return a disabled response and cannot mark orders paid or issue tickets. No payment-provider credentials are needed. Payment integration is intentionally deferred.

## Design system and scrolling effect

`public/assets/css/site.css` is the design layer and `public/assets/js/main.js` the behaviour. Pages are built from `.tk-stack` containers holding `.tk-st` sections: each section is sticky, the next one slides over it with a rounded edge, and the section underneath shrinks and darkens slightly. Inner pages use `includes/layout.php`, which builds the same structure. With `prefers-reduced-motion` the sections are laid out normally.

## Event marketplace

`/events/` lists every eligible upcoming event (published or sold out, with a valid date, not yet finished), grouped by day, 24 per page. Filters: search, city, sort, category, today/tomorrow/this weekend, date range, free/paid (only prices confirmed by a TicketsKenya ticket type count). Cancelled, draft, archived, past and invalid-date records are never listed. A card shows a poster only when a real uploaded image exists; otherwise it shows a neutral "Official poster not available" tile.

## Before you launch (owner actions)

1. **Homepage figures.** Homepage counts are now derived from upcoming listings; confirm the underlying event records before relying on them.
2. **Real events.** The project has 6 baseline discovery listings and 50 event listings transcribed from the supplied screenshots. Confirm their dates, venues, prices, and official ticket links with each organiser before relying on them. Add organisers' events in the admin area, or enable a feed whose owner permits republication (`tools/discovery-sources.json`).
3. **Contact details and socials.** None are invented. Add social links by setting `TK_SOCIAL_INSTAGRAM`, `TK_SOCIAL_X`, `TK_SOCIAL_YOUTUBE`, `TK_SOCIAL_LINKEDIN`, `TK_SOCIAL_FACEBOOK`, `TK_SOCIAL_TIKTOK` (https URLs) in your environment; the footer shows only those that are set.
4. **Production URL.** Set `TK_APP_URL` to your real https address so canonical tags, sitemap and `robots.txt` use it. Set `TK_ENV=production` and `TK_DEMO_MODE=false`.
5. **Legal copy.** The Privacy, Terms, Refund and Cookie pages are plain-language drafts. Have them reviewed against Kenya's Data Protection Act and consumer rules before launch.
6. **Password reset.** Email sending is not configured, so the reset page explains how an administrator resets a password.
7. **Backups.** Back up the `data/` folder (copy it while the site is idle).

## Carousels and design

The homepage's upcoming-event cards and culture strip use matching `.tk-marquee` / `.tk-marquee-track` markup and a continuous, seamless, zero-cost JavaScript loop. Motion pauses on hover, keyboard focus and touch interaction; it also pauses when the tab is hidden and respects `prefers-reduced-motion`. The existing dark visual direction and sticky/overlapping hero sections are retained.

## Tests and validation

From the project root, run the portable integrity and parser tests first:

```bat
python tests\test_project_integrity.py
C:\xampp\php\php.exe tools\sync-discovery.php --self-test
```

Site regression suite (pages, headings, links, headers, SEO, payment lockdown):

```bat
python tests\test_site_regression.py
```

To also test pagination and filters at volume, run a second server on a disposable data folder with more than 24 upcoming events and set `TK_SCALE_URL=http://127.0.0.1:8081` (see `tests/README-tests.md`).

The portable test checks PHP syntax, the iCalendar parser fixture, payment hard-blocks, carousel markup, and seed-import idempotency. For the full HTTP CRUD suite, enable PHP's PDO SQLite extension in XAMPP, start the local server at port 8080, then run `python tests\run_tests.py`.

## Suggested local check before adding payments

1. Visit the home page and `/events/`; check that posters, dates, locations, and displayed prices look right.
2. Open several event detail pages and confirm discovery listings are clearly marked as listings only, with no external purchase link.
3. Sign in with the local demo accounts above and review customer, organiser, and admin pages.
4. Confirm a ticket purchase attempt reports that sales are unavailable and creates no paid order or ticket.
5. Run the automated tests above. Keep payments disabled until the payment provider flow, callbacks, refunds, and ticket issuance have been implemented and verified locally.
6. Deploy only after local checks pass, production settings are configured, demo accounts are disabled, and real payment flows (if added) have passed end-to-end tests.

From the project root, these commands are also available:

```bat
C:\xampp\php\php.exe -l public\index.php
C:\xampp\php\php.exe -l tools\sync-discovery.php
C:\xampp\php\php.exe tools\sync-discovery.php
C:\xampp\php\php.exe tools\import-discovery.php
```

The full HTTP regression suite in `tests/run_tests.py` expects the local PHP server at `http://127.0.0.1:8080`, demo mode enabled, and the demo database to be initialized. Run it only against a disposable development database because it creates, edits, archives and uploads a test event.

## Important limitations

- KSh 0 means no paid API, paid feed, or paid scheduler. The live sync relies on public feeds, a working internet connection, and a local/host scheduler.
- Automatic event coverage is only as broad as the configured feeds and their terms. This is not a guarantee to find every event in Kenya.
- Event posters use local images unless an authorised feed image is supported and explicitly added later; no fake official poster is created.
- Discovery-only listings are for finding events and are not sold by TicketsKenya. Their detail pages do not send visitors to an external purchase page. Only TicketsKenya-owned inventory can appear in the internal checkout flow.

## Internal ticket checkout (owned events only)

Discovery listings are not purchasable on this site and do not send visitors to an external ticket URL.

For events with `listing_type = owned` and status published, visitors who are logged in can select ticket quantities and create a **pending** order on TicketsKenya. Inventory is reduced when the order is created.

**Payments remain disabled.** No card or M-Pesa charge is made. No paid tickets are issued until a payment provider is approved, credentials are set in environment variables, and payment verification code is turned on with tests.

A sample owned event (TicketsKenya Culture Night 2026) is seeded for local testing when demo mode is on.

See `AUDIT-REPORT-2026-10-23.md` for the full status matrix.

See `AUDIT-REPORT-2026-10-24-MASTER-PROMPT.md` for the current master-prompt checklist, audit findings, test results, and launch blockers.
