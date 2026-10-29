# Site Activity & Insights (v1.0.0)

A first-party, privacy-first analytics module for PHP + SQL websites. No paid service, no Node, no external scripts.
Installed in TicketsKenya through one small adapter; the core knows nothing about tickets.

* `src/` reusable core (collector, sessions, privacy, analytics, conversions, audiences, retention, export, UI helpers)
* `adapters/ticketskenya.php` the only TicketsKenya-specific code (events, orders, owner rule, CSRF, banner include)
* `adapters/admin-page.php` shared page frame that reuses the site header/footer
* `config/site-activity.example.php` optional starting settings
* `tests/` `test_activity.py` (HTTP + database + security), `browser_activity.py` (real Chromium), `test_resilience.py`
* `docs/` guides (see below). Public files live in `public/activity/`, `public/admin/activity/`, `public/privacy-settings.php`, `public/assets/{js,css}/activity*`.

## Windows + XAMPP quick start (TicketsKenya)
1. Extract the ZIP so the project is `C:\xampp\htdocs\TicketsKenya\` (it contains `public`, `config`, `includes`, `modules`, `tools`).
2. `cd C:\xampp\htdocs\TicketsKenya` then `C:\xampp\php\php.exe tools\activity-maintenance.php install`
   (this is optional: the tables are also created automatically the first time a page loads).
3. Start the site as before: `cd public` then `C:\xampp\php\php.exe -S localhost:8080 router.php`.
4. Log in as the owner (the first admin account, or the emails in `TK_ACTIVITY_OWNER_EMAILS`) and open **Manage events → Activity & Insights**, or `http://localhost:8080/admin/activity/`.
5. Visit the public site in another browser/profile, click **Accept analytics** on the banner, click around, then watch the dashboard fill from real activity. Nothing is invented: with no consenting visitors the dashboard shows empty states.
6. Daily (Task Scheduler): `C:\xampp\php\php.exe C:\xampp\htdocs\TicketsKenya\tools\activity-maintenance.php retention`

Requires PHP 8.1+ with `pdo_sqlite` (already required by TicketsKenya). If the site is running on its JSON fallback store (no SQLite), the module switches itself off and the site is unaffected.

## Guides
`docs/OWNER-GUIDE.md` · `docs/PRIVACY-AND-RETENTION.md` · `docs/INTEGRATION-GUIDE.md` (reuse in another project, instrumenting buttons) · `docs/EVENT-SCHEMA.md` · `docs/TESTING.md` · `docs/ROLLBACK.md`
