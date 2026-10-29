# TicketsKenya repair report — 21 October 2026

## Implemented in this repair

- Added a configurable CLI event-feed synchronizer for public HTTPS iCalendar, RSS and Atom feeds.
- Added date/time-aware iCalendar parsing, event source URL matching, stable source-based slugs, duplicate prevention, conservative cancellation handling, change logs and sync history.
- Added an admin-only Event Discovery Tracking page at `/admin/discovery.php` with source configuration, recent sync results, warnings and change summaries.
- Added the matching marquee markup missing from the homepage's event cards and culture strip. The loop duplicates cards for a seamless animation and pauses for hover, keyboard focus, touch, hidden tabs and reduced-motion preferences.
- Disabled all payment initiation, callback, webhook and verification paths. Removed provider API request code. No payment credentials are needed; no endpoint can mark an order paid through a payment callback.
- Fixed seed importer idempotency by matching existing discovery events by source URL and normalized title/start time instead of slug only.
- Added UTF-8 length/substring helpers so pages and forms work without PHP's optional `mbstring` extension.
- Made the development admin account seed work with existing development databases, corrected the regression-suite login credentials, and defaulted demo mode off in production.
- Flattened the documented ZIP layout and updated XAMPP, scheduler, feed configuration and testing instructions.

## Validation performed in this environment

- PHP syntax checks: 36 PHP files passed.
- Portable integrity suite: 0 failed checks.
- iCalendar parser fixture: passed, including Africa/Nairobi date/time and end-time parsing.
- Seed importer idempotency: two consecutive runs created zero duplicate records; the test store remained at six seed events.
- HTTP smoke tests using PHP's built-in server and JSON fallback: homepage, events, event detail, pricing, about, login and registration returned HTTP 200; demo admin login and admin discovery/events pages worked.
- PHP server log after smoke tests: no warning, fatal, deprecation or notice messages.
- Payment callback, webhook and status endpoints returned HTTP 410 disabled responses.

## Known external limitation

- The execution environment used for this repair could not fetch external feeds, so live network sync was not verified end to end here. Run `php tools/sync-discovery.php` on the target machine and inspect `data/discovery-sync-log.json`.
- The included SoldOutAfrica iCalendar source is disabled by default. Its published terms prohibit republishing website information without consent. Obtain written permission or configure a public feed whose terms expressly allow republication before enabling any source. The sync engine will log a warning if no authorised source is enabled; it will not scrape restricted pages or invent event data.
- The full SQL-backed HTTP regression suite was not run here because this environment's PHP CLI does not have PDO SQLite enabled. The portable integrity suite and JSON-fallback HTTP smoke tests passed. On XAMPP, enable PDO SQLite and run `python tests/run_tests.py` for the full CRUD suite.
- Continuous automatic sync on a live site requires a scheduler that can execute the script against the live database. Windows Task Scheduler updates the local project only while that PC is on and connected.
