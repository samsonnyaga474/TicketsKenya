# TicketsKenya master-prompt audit and implementation report

**Reviewed:** 24 October 2026  
**Source archive:** `TicketsKenya-final-2026-10-24.zip`  
**Constraint:** KSh 0 budget; no purchases, registrations, deployments, or live credentials used.

## Summary

The supplied archive is an existing PHP application, not a React project. It already contains event management, local poster assets, search and filters, legal and help pages, a PHP/CSS ticket component, security controls, and a disabled payment integration. I kept the PHP stack and existing visual direction.

This follow-up restores the exact four count-up figures and copy from your supplied orange reference section, without displaying a demo label. The figures are user-provided design claims and have not been independently verified against operating records. The order page now has a printer-style receipt with ticket lines, order and event dates, venue, subtotal, platform fee, an explicitly unconfigured tax line, total due, verified total paid, payment method, status and reference; it can be printed. A ticket is shown only after server-recorded payment and includes its ticket type. The earlier accessible category card draw remains.

## Six previously reported gaps

| Area | Finding in the archive | This pass | Status / blocker |
|---|---|---|---|
| M-Pesa and card | All payment start, callback, webhook, and status endpoints are intentionally fail-closed. Payment provider API code is absent. No payment keys or approval are present. | Kept fail-closed; clarified the checkout copy. | **Blocked** by provider approval, merchant configuration, credentials, webhook setup, HTTPS deployment, and end-to-end sandbox tests. Nothing is marked live. |
| Ticket and receipt email | No SMTP or mail API transport is configured. Contact messages are saved for admin review; there is no automated reply. | The order page now states whether payment is verified and whether email delivery is configured. | **Blocked** until a mail transport and credentials are configured and delivery is verified. No ticket/receipt email is claimed as sent. Password reset also has no email delivery. |
| Automatic event discovery | A secure RSS/Atom/iCalendar synchronizer exists. The only bundled live feed is disabled because reuse permission is not established. Seed/import is separate from live discovery. | Reviewed official and public calendar options; did not enable or scrape a feed without reuse permission. | **Partial.** The sync engine is ready for a permitted stable feed, but no suitable permission-cleared, broad Kenyan entertainment feed was confirmed. KTB publishes an events calendar reference, and the government conference portal exposes event listings, but neither source surfaced a documented open feed/API licence that authorizes republication in this audit. |
| All-page styling, including admin | Shared PHP templates load the existing dark/lime styling and responsive rules; admin pages use that same base plus tables/forms. The archive contains no full set of design reference images to compare pixel-for-pixel. | Preserved shared styling and applied the small feature to the existing system. | **Partial.** This is not a page-by-page pixel-perfect recreation of HustleSasa or supplied references. |
| Playing-card interaction | No card game/interaction was present. | Added “Deal a card”: randomly selects a category with current upcoming listings, animates a card while respecting reduced motion, reports the real listing count, and links to the internal category-filtered event list. It does not promise a prize or payment. | **Implemented** as a lightweight event-discovery aid. |
| Free domain | No domain had been registered. | Checked Name.com’s current information. Its free domain offer is tied to a Google Workspace product; normal domain pricing applies otherwise. No domain was checked out or registered. | **Not done.** A free long-term custom domain was not established. Registration or paid bundling would exceed the KSh 0 budget and requires explicit approval. A free host subdomain is different from owning a custom domain. |
| Operator anonymity from providers | No hosting or payment account has been created. Payment collection requires a provider account. | No false identity or privacy workaround was added. | **Not a promise the site can make.** Hosting and payment providers may require the contracting operator’s identity and KYC information. Do not supply false details or attempt to evade those requirements. |

The receipt shows `Tax (not configured): KES 0`; no tax rate was guessed. Confirm tax treatment with a qualified Kenyan tax adviser and configure it before collecting payments. While an order is pending, `Total paid` is KES 0 and `Paid with` is `Not paid`. A payment provider must verify a charge server-side before the receipt can show an amount paid and a ticket can be issued.

## Master-prompt checklist

### Tickets, events, and checkout

- [x] PHP project preserved; existing routes, storage model, admin controls, and assets retained.
- [x] Event create/edit/archive tools and ticket-type fields are present in the admin area.
- [x] Event list includes search, city, category, date, price, and sort filters; event detail displays date, location, description, poster when available, and ticket information.
- [x] Event cards link to internal TicketsKenya detail pages. The homepage call to action says “See All Events” without a hard-coded count.
- [x] Each card uses an uploaded image when an event poster is available; otherwise it displays an explicit neutral “Official poster not available” tile rather than silently substituting a stock image.
- [x] Owned events can create a CSRF-protected pending order on the site. Server-side status and stock checks apply.
- [x] Discovery-only events are now explicitly distinguished from TicketsKenya ticket inventory. Their detail page does not offer an external purchase or clarification link.
- [x] Ticket visual treatment exists in PHP/CSS with a stub, perforation-like separator, shadow and subtle hover motion; reduced-motion behavior is supported. It is a PHP adaptation, not the exact React/`react-parallax-tilt` component because this project has no React build.
- [ ] Payment by M-Pesa/card is **not live**. Orders remain pending; a browser action cannot mark an order paid or issue a valid ticket.
- [ ] Ticket email delivery is **not configured**. No automated delivery or reset email is claimed.
- [ ] Live discovery is **not automatic on a fresh install**: an administrator must configure an allowed source and run the synchronizer or schedule it on a suitable host.

### Design, content, and accessibility

- [x] Existing responsive site styling and shared inner-page layout are retained. Mobile navigation, reduced-motion handling, carousel pause behavior, and keyboard skip link are present.
- [x] About, contact, FAQ/help, pricing, organiser, terms, privacy, refund, cookie, 404, robots, and sitemap pages/routes are present.
- [x] Contact form validates input, uses CSRF protection, includes a honeypot and rate limit, and stores submissions for admin review. It now discloses that email replies are not automated.
- [x] The orange proof section matches the supplied wording and count-up targets (2,500+, 520k+, 350k+, 6 countries) and counts up when scrolled into view. These reference figures remain owner-provided claims; verify their substantiation before publishing them as business facts.
- [x] Order detail has a printable printer-style receipt and a perforated event ticket after verified payment, with totals, order/event details and ticket types.
- [x] Existing typography and visual system do not use the prohibited purple-blue gradient, emoji headings, generic glass cards, or cursor beam patterns in the audited shared design layer.
- [ ] The reference set was not available as files in the supplied ZIP, so a pixel-level comparison of every public and admin screen could not be verified.
- [ ] Image ownership/licences for every supplied or bundled event image cannot be independently established from the archive. Verify usage rights before launch.
- [ ] Legal text is draft product copy, not legal review. Confirm Kenyan privacy, consumer, tax, accessibility, cookie/tracking, and business-disclosure requirements with qualified local advice.
- [ ] Full WCAG, Lighthouse/Core Web Vitals, analytics/tracking, third-party embed, and cross-device browser measurements were not run in this environment.

### SEO and launch checks

- [x] Shared template emits titles, descriptions, canonical links (when the app URL is configured), Open Graph basics, favicon links, and JSON-LD where supplied.
- [x] `robots.txt`, sitemap, custom 404, HTTPS production HSTS header, security response headers, and internal route structure exist.
- [x] Event image alt text and one-H1 page patterns are present in the inspected templates. Some decorative images intentionally have empty alt text.
- [ ] HTTPS can only be verified after deployment with a real domain and host configuration.
- [ ] Analytics is not configured. No tracking consent flow was verified because no analytics vendor is configured.
- [ ] No production domain, host, database backup, alerting, or restore drill was provisioned in this pass.

### Security review against the supplied concern list

- [x] No production `.env` or real provider secret was present in the archive; `.env.example` contains development placeholders.
- [x] Session cookies request HttpOnly and SameSite=Lax; Secure is set when the request is HTTPS. Production response headers include HSTS.
- [x] CSRF helpers are used for state-changing forms; escaping helper is used in rendered text; login attempts are bounded; uploads have a dedicated `.htaccess` rule.
- [x] Admin areas use server-side role checks; customer ticket views filter by the logged-in user; payment endpoints fail closed.
- [x] Feed fetching enforces HTTPS/public hosts, size limits and no redirects; feed sync has a lock, source deduplication, logs, and a cautious missing-event policy.
- [ ] This is a source review, not an external penetration test. Do not interpret these checks as proof that the site cannot be hacked.
- [ ] Production should use a properly protected data directory outside the public document root, real HTTPS, unique strong admin credentials, least-privilege hosting/database access, tested backups, monitoring, dependency/update review, and production error logging that does not expose secrets.
- [ ] The PHP JSON fallback uses file storage and is not a substitute for a concurrency-safe production database. Validate production deployment configuration before handling money or personal data.
- [ ] Verify upload MIME/content handling, request throttling across all public endpoints, IDOR boundaries, and authorization for every host-specific configuration with a security review before launch.

## Verification run

| Check | Result |
|---|---|
| `php -l` across project PHP files | **Pass:** 47 PHP files (run in the initial integrity-suite invocation). |
| `tests/test_discovery.php` | **Pass:** 27 passed, 0 failed. The PHP session storage warning is environment permission-related; test assertions passed. Its cleanup emitted a Windows `rm` command warning. |
| `tests/test_project_integrity.py` | **Pass:** 0 failed checks, including PHP syntax across 47 files, iCalendar parser fixture, homepage targets, receipt fields, tax storage, payment/feed checks, and seed-import idempotency. Used a writable workspace temp folder; PHP emitted session-folder permission warnings but assertions passed. |
| `tests/test_site_regression.py` | **Earlier pass:** 127/127 on the preceding package. **Not rerun for this follow-up** because the current sandbox approval policy blocks the localhost permission escalation required by that suite. |
| `node --check public/assets/js/main.js` | **Pass:** JavaScript syntax check returned exit code 0. |
| Live payment/email/feed/domain | **Not run / not configured**, as detailed above. |

No passing result is claimed for an uncompleted check. The package is a development update, not a production release.

## Current online-source findings

- Name.com describes normal domain pricing and separately notes free domains bundled with a Google Workspace product: [Name.com pricing](https://www.name.com/pricing), [Name.com domain search](https://www.name.com/en).
- Kenya Tourism Board references a domestic and international tourism events calendar, but the public page reviewed did not document an open republication feed: [KTB service portal](https://www.ktb.go.ke/index.php/kenya-tourism-board-service-portal).
- Kenya’s national accreditation portal lists conference/workshop-style events; its existence does not establish a broad entertainment feed or reuse licence: [Events Indico portal](https://eventsaccreditation.go.ke/).

## Files changed in this pass

- `public/index.php` — exact requested count-up figures and the category-deal card markup/data.
- `public/tickets/order.php` — complete printable receipt and ticket details, with truthful pending/payment/email states.
- `config/database.php` and `public/events/view.php` — explicit tax amount storage (zero until tax is configured).
- `public/assets/js/main.js` — accessible category draw with reduced-motion handling.
- `public/assets/css/site.css` — responsive playing-card styling.
- `includes/events.php` — honest card labels for owned versus discovery entries.
- `public/events/view.php` — ownership-aware on-site checkout and no outbound purchase path for discovery-only entries.
- `public/contact.php` — accurate notice that automated email replies are not configured.
- `public/index.php` — restored the exact requested homepage statistics and scroll-triggered count-up.
- `tests/test_project_integrity.py` — static checks for the card feature, requested count-up values, and no outbound discovery purchase link.

## Before any public launch

1. Obtain provider approval and configure sandbox credentials; complete card and M-Pesa callbacks, signature verification, idempotency, refund handling, and test transactions before enabling payment code.
2. Configure and test a mail provider; verify ticket delivery, reset flow, bounced mail handling, and privacy-safe logs.
3. Obtain explicit feed reuse permission or a documented open licence; add only stable sources and review each sync.
4. Set up HTTPS hosting, environment secrets, protected data storage, database backups, monitoring, and test restore.
5. Replace demo accounts, verify event dates/prices/poster rights/business and support details, and have the legal policies reviewed.
6. Run the local HTTP regression, browser/mobile, accessibility, and security checks in a configured environment.
7. Keep domain/deployment spend at KSh 0 unless the owner approves a specific cost.
