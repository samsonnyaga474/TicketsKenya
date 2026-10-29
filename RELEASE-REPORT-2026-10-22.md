# TicketsKenya release report (2026-10-22)

## Status: development build, NOT ready for public launch
Remaining launch items need owner input (see "Before you launch" in README.md).

## What changed in this pass
- New design layer (`site.css`) and behaviour (`main.js`); old `reference.css` removed.
- Car-window stacking on the homepage, inner pages (`includes/layout.php`), event detail and events list.
- Homepage: hero, nearest-events carousel (16 events, real markup), culture strip, stats with count-up.
- Events marketplace rewritten: date-grouped poster cards, 24 per page with pagination, search, city, sort, category, quick date chips, date range, free/paid, filter dialog, styled empty state. Shared rules in `includes/events.php`.
- Footer rebuilt (CTA band, brand, Company / Learn More / Legal, divider). No invented email, phone, address or social accounts; socials appear only if set in environment.
- Header: search, accessible mobile drawer (Esc closes), collapses below 1100px.
- SEO: unique titles, canonical only with a real production URL, noindex for auth/admin/filtered pages, sitemap and robots, Event/Organization/WebSite JSON-LD.
- Root-cause fix: asset and redirect URLs were absolute (broke on any other host/port); now relative.
- Security: removed X-Powered-By. Existing protections re-verified (CSP, nosniff, frame, referrer, permissions headers, HttpOnly/SameSite cookies, HSTS on HTTPS, fail-closed payment routes).
- Tests: new `test_site_regression.py` (141 checks), updated outdated assertions in older suites, fixed a bug in the discovery test (source A was not synced before the duplicate check).

## Tests actually run (this environment: PHP 8.3 + SQLite, headless Chromium)
| Test | Result |
|---|---|
| `php -l` on every PHP file | pass |
| `php tests/test_discovery.php` | 27/27 pass |
| `python tests/run_tests.py` | 48/48 pass |
| `python tests/test_project_integrity.py` | 0 failed |
| `python tests/test_site_regression.py` (with 138-event volume server) | 141/141 pass |
| Browser: stacking progress, reverse scroll, count-up 0 to target, carousel movement, footer reachable, no console errors, no horizontal overflow at 320/375/768/1440 | pass |

## Not tested / blocked
- Live event source: none is enabled (the only bundled one forbids republication without permission) and this environment has no internet. Not tested.
- Lighthouse / Core Web Vitals: not run, no scores claimed.
- Google Fonts do not load offline here; fallback fonts were used in screenshots.
- Real-device and Safari/Firefox testing: not done (Chromium only).
- Full manual walk of all 50 security items: the automated and spot checks above were done; a complete line-by-line audit was not finished.
- Legal pages are drafts, not legal advice.

## Known limitations
- Only 6 discovery listings exist; the 100+ events in the HustleSasa screenshots were not available and were not invented.
- Homepage figures (2500+, 520k+, 350k+, 6 countries) are the requested reference figures, not verified TicketsKenya numbers.
- Payments are disabled server-side; ticket purchase on TicketsKenya-managed events is closed.
- Password reset by email is not available (no mail service configured).
