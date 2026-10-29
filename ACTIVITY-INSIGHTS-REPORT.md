# TicketsKenya Activity Insights — implementation and security audit

## Interface update

The contact page now uses a centered, responsive two-panel layout with the site's dark/lime palette, restrained motion, a display-font pairing, accessible field labels and errors, and the existing server-side validation and spam honeypot.

Analytics behavior was not changed: tracking remains opt-in and the privacy settings route remains available for withdrawal and deletion. Removing those controls while keeping visitor-level behavioral tracking enabled would remove the visitor's choice and withdrawal path.

## Security activity addition

Added an admin-only `/admin/security.php` view for authentication and suspicious-request signals. The log stores event category, a shortened IP prefix, and timestamp; it does not store the attempted account email or request URL. Repeated same-kind signals are capped at five per minute per prefix, and security rows are pruned after 30 days. The existing `login_attempts` table remains the login throttle source and is pruned after 24 hours. Signals cover failed/successful login, login rate limiting, invalid login CSRF, activity-endpoint rate limiting, and malformed/cross-site/forged activity requests. Logging is best-effort and must not interrupt site requests. The scheduled `activity-maintenance.php retention` command applies both security retention windows daily; new security events and opening the security page also prune old rows. This is basic monitoring, not intrusion detection.

**Review date:** 28 October 2026  
**Scope:** supplied `TicketsKenya-ActivityInsights.zip` and `ACTIVITY-INSIGHTS-REPORT.md`  
**Assessment:** targeted changes made; not a penetration test or a guarantee against compromise.

## Baseline and handling

- Preserved the supplied ZIP and report under `work/` before editing. The ZIP's SHA-256 was `762226C1097754FCBD7A375A5F8B3BD8EA2BA3D016537CB84611A24C06A27E39`; the copied baseline hash matched the attachment.
- The archive already contained the privacy-first first-party tracker, owner dashboard, consent and deletion controls, retention, event/purchase analytics, export, and test suites described in the supplied report.
- The supplied archive also contained `data/ticketskenya.sqlite`. It is omitted from the updated ZIP so account, order, contact, or other runtime records are not redistributed. Existing site data is not modified by this work.
- No deployment, provider call, payment, or live destructive operation was performed.

## Changes made

1. **Repeat-visitor reporting:** the owner dashboard now reports returning browsers and their share of browsers in the selected period. A browser qualifies only if it has a recorded session before the period and one within it. It is a browser measure, not a person measure; only consented tracked sessions can be counted.
2. **Aggregate audience export:** the owner CSV options now include audience definitions and threshold-masked sizes. Groups under five display as “fewer than 5”. No user email, account ID, visitor ID, raw IP, or advertising-platform identifier is exported. No external ad-platform integration or upload is enabled.
3. **Privacy wording:** corrected the policy's false claim that no analytics tracker runs, described opt-in tracking, account association while signed in, marketing-audience controls, default retention periods, and browser-level withdrawal/deletion. Cookie wording no longer claims a persistent browser identifier cannot be personal data.
4. **Open redirect:** `tk_redirect()` now permits only local absolute-path destinations and rejects protocol-relative, backslash, and CR/LF inputs. Existing callers use internal paths.
5. **Concurrent inventory reservation:** checkout now serializes the read/check/reserve flow using a local lock file; SQLite additionally uses an immediate write transaction and refreshes inventory after acquiring the lock. A failed order flow does not commit a partial SQLite transaction. This requires verification under the target Windows/Apache filesystem and concurrent workload.
6. **Apache exposure:** added a project-root deny rule so Apache pointed at the wrong directory fails closed; public directory access is explicitly granted in its own `.htaccess`. Updated README deployment directions to use `public/` as the Apache `DocumentRoot`, enable overrides, and set production environment values.
7. **Package hygiene:** the delivered ZIP omits the runtime SQLite database and stale internal work reports containing demo credentials. The demo password was removed from the packaged README. No `.git` directory, source map, `.env`, `vendor`, or `node_modules` directory was found in the supplied project tree.

## Security checklist findings

| Area | Finding / disposition |
|---|---|
| Next.js headers | Not applicable: this is PHP. PHP responses set CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and remove `X-Powered-By`. HSTS is enabled only for production; verify TLS and headers on the actual host. |
| `.git`, source maps, dependencies | No `.git`, source map, Composer vendor tree, or Node dependency tree was found in the supplied archive. Recheck the deployment artifact and web server after installation. |
| Database exposure | SQLite data is outside `public/`; the shipped runtime database was removed. Apache must serve only `public/`. Root `.htaccess` denies requests when pointed at the wrong directory. Verify the host honors `.htaccess` and `AllowOverride`; otherwise configure equivalent server rules. |
| Storage / uploads | Upload directory disables PHP handlers. Do not place backups, logs, SQLite files, or environment files under `public/`. The updated package does not include runtime database contents. |
| Authentication and sessions | Password verification uses `password_verify`; session strict mode, cookie-only mode, HttpOnly, SameSite=Lax, HTTPS-dependent Secure flag, and session ID regeneration on login are present. Login responses are generic and login attempts are rate limited. Forgot-password is not active; reset flow is unavailable rather than issuing unverified reset links. |
| Rate limiting | Login and activity collection have limits. The login-attempt policy is application/database-backed and has not been load-tested or assessed across multiple hosts; deploy edge/WAF limits for credential stuffing and SMS abuse if those features are added. No SMS flow currently exists. |
| Security monitoring | New admin-only feed records failed/successful login, login lockouts, invalid login CSRF, activity rate limits and suspicious activity requests. Only `/24` or `/48` address prefixes are shown; repeated same-kind records are capped at five per minute per prefix; security records expire after 30 days. Raw login throttle rows expire after 24 hours. This does not replace host logs, alerting, MFA, or incident response. |
| SQL injection / XSS | Activity queries use prepared statements for values; output helpers escape HTML and CSV export neutralizes spreadsheet formulas. Static/project tests passed, but the HTTP injection tests could not connect to the local server in this environment. This source review is not a proof of absence. |
| CSRF | Activity state changes and owner exports require POST plus CSRF validation. Core forms also use CSRF helpers. Runtime regression verification unavailable in this environment. |
| Open redirect | Fixed in `tk_redirect()` as described above and PHP lint passed. Hostile `next` values still need an HTTP test. |
| Path traversal / SSRF | Built-in router rejects dot segments and dotfiles. Feed sync has source/HTTPS validation, but its network behavior and DNS/private-address edge cases were not tested in this environment. No generic user-controlled URL fetch feature is in the activity module. |
| Clickjacking / headers | Same-origin frame policy and CSP `frame-ancestors 'self'` are configured. Confirm proxy/CDN does not strip or weaken headers. |
| Price tampering | Order totals and unit prices are derived server-side from stored ticket types; posted quantities are checked against server inventory. Payments remain disabled and no browser event can mark an order paid. |
| Race conditions | Checkout reservation now uses a lock and SQLite immediate transaction. The JSON store fallback is only suitable for local/single-host use; it is not a MySQL backend and is not verified for distributed storage. Concurrency load test not run. |
| User enumeration / pre-hijacking | Login error is generic. No automatic reset email or verified-email handoff exists. Registration/account pre-hijacking behavior still needs a live workflow review before accounts are used for real purchases. |
| JWT / Supabase / polyfill | Not present in this PHP build. No JWT signing secret, Supabase storage, or Polyfill.io integration was found in the reviewed project tree. |
| Geo/IP privacy | No IP geolocation lookup is implemented. Analytics IP mode defaults to truncation; trusted proxy handling is configurable. Geo-country is only accepted through an explicitly trusted proxy header. Do not enable full IP viewing or `notice_only` without a documented legal/operational decision. |
| Payment initiation/failure | Event names exist, but payment integration is intentionally disabled. No payment initiation/failure events are claimed or synthesized. Implement only with provider approval, verified server callbacks, idempotency, and sandbox tests. |
| Repeat visitors | Implemented as a consented browser-level measure; cookie clearing, shared devices, and multiple browsers mean it does not identify people reliably. |
| Audience export / advertising | Aggregate, small-group-masked CSV only. No individual contact export or advertising integration is included. Provider credentials/approval, consent/legal review, and data minimization would be required before a future integration. |
| MySQL portability | Unresolved. The application and activity schema are SQLite-specific; the existing JSON fallback is not MySQL. A MySQL driver/schema/transaction implementation needs a separate migration and test pass. |
| Privacy policy | Contradiction corrected, but entity/contact/legal basis, ODPC obligations, direct-marketing rules, cross-border hosting, and final retention policy require qualified legal/privacy review. |
| XAMPP / hosting | Instructions now require the Apache document root to be `public/`, HTTPS, production environment settings, and working `.htaccess`. This Windows/XAMPP Apache setup was not available for verification. |

## Tests and checks performed

- Baseline archive SHA-256 checked before edits; original and backup hashes matched.
- Confirmed no `.git`, source maps, `.env`, Composer `vendor`, or `node_modules` in the extracted baseline.
- Ran `tests/test_project_integrity.py`: **0 failed checks**; it linted 83 PHP files and passed the iCalendar fixture, project integrity checks and seed-import idempotency check. The runner emitted session-directory permission warnings from XAMPP's default `C:\xampp\tmp`, but the checks passed.
- Ran an isolated PHP/SQLite security-monitor check: **passed** IP prefix truncation, five-per-minute cap, 30-day security-log pruning, 24-hour login-attempt pruning, and retention of recent rate-limit rows.
- Started the PHP development server on a disposable project copy, then attempted the activity HTTP suite. The client could not connect (`WinError 10013`, local socket access denied); the suite therefore did not run to completion. No production or user database was used.
- Ran `node --check` for both activity JavaScript files: passed.
- Compiled the available Python test scripts with `compileall`: passed.
- Executed a disposable SQLite fixture for the new returning-visitor query: returning browser counted, first-time browser excluded, test-only browser excluded.
- Did not complete the activity HTTP/security suite, resilience suite, Chromium suite, Apache/XAMPP check, or concurrency load test. The supplied report's historical test counts describe the prior artifact and are not claimed as rerun against this updated ZIP.

## Unresolved risks and required production checks

- Run the activity HTTP/security and resilience suites on a disposable copy in an environment that allows local client connections; then run the real Chromium checks.
- On Windows/XAMPP Apache, test `DocumentRoot=.../TicketsKenya/public`, `.htaccess` authorization inheritance, HTTPS cookie flags, HSTS, privacy settings, owner permissions, and checkout locking.
- Run a concurrent inventory reservation test against the intended filesystem/database. Do not use the JSON fallback for production checkout traffic.
- Keep payments disabled until a provider is approved and server-side initiation, signed callbacks, replay/idempotency protection, failure tracking, and ticket issuance are tested in sandbox and reviewed.
- Decide whether/when a MySQL backend is required. No MySQL compatibility is represented by this delivery.
- Have the privacy notice, lawful basis, retention, consent/marketing language, and any future ad integration reviewed by qualified counsel/privacy staff.
- Set production-only values in the hosting environment: `TK_ENV=production`, a real HTTPS `TK_APP_URL`, `TK_DEMO_MODE=false`, exact trusted proxy configuration if applicable, and a strong `SITE_ACTIVITY_SECRET` if keyed-hash IP storage is selected. Keep secrets out of source and public directories.

## Deployment steps

1. Back up the current project and database yourself before replacing files; this package deliberately contains no runtime database.
2. Extract the ZIP outside the public web root and point the Apache VirtualHost `DocumentRoot` at the extracted `TicketsKenya/public` directory. Enable required `.htaccess` overrides or reproduce those rules in Apache configuration.
3. Configure PHP 8.1+ with `pdo_sqlite`, set the production environment variables above, configure HTTPS, and confirm the service account can write only to the required `data/` directory.
4. Run the activity installer and retention task using the documented CLI commands. Confirm the dashboard is owner-only and that consent rejection stores no optional analytics.
5. Complete the pending test and legal/provider checks before accepting real traffic or enabling payments/advertising integrations.
