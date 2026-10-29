# Privacy, consent and retention guide

> This is an engineering description, not legal advice. Have the Kenya Data Protection Act, 2019 obligations (lawful basis, notice, registration with the Office of the Data Protection Commissioner, data-subject rights, direct-marketing rules) reviewed by a qualified person before relying on any setting below. Items the code cannot decide are listed at the end.

## What is collected (only after the visitor accepts)
page views, link/button ids and short labels, event views, search text (emails/number-like text removed), listing filters, ticket-quantity numbers, device type, browser, OS, viewport bucket, language, referrer **host** and type, utm_ tags, a shortened IP (default), session timing. Real orders create a server-side checkout/purchase event linked to the session.
**Never collected:** passwords, card/M-Pesa details, anything typed in login/registration/contact forms, full URLs/query strings of other sites, hardware ids, fingerprints, location, microphone/camera/contacts, activity on other websites.

## Consent behaviour
* Default `opt_in`: nothing optional is stored, no analytics cookie is set, until **Accept analytics** is pressed. Accept and Reject are equal-weight buttons. Refusing never blocks the site.
* The choice is stored in the `sa_consent` cookie (180 days) and logged in `activity_consent` (policy version, time; the visitor id only if they accepted). Changing `policy_version` asks everyone again.
* Withdrawal: `/privacy-settings.php` (linked in the footer) switches analytics/marketing off, clears `sa_vid`/`sa_sid` immediately, and offers "delete this browser's activity data".
* Marketing/audiences are a separate choice, off unless ticked on the privacy-settings page and unless the owner enables audiences. The latest choice wins, so withdrawal suppresses the visitor automatically.
* `notice_only` mode (count unless refused) exists but is **not** the default and should not be used without legal advice. An explicit refusal still wins.
* Essential operational/security logging (the site's own session cookie, CSRF, server logs, orders) is separate and untouched.

## IP addresses
Observed `REMOTE_ADDR` only. `X-Forwarded-For` is believed **only** if the connecting address is in the owner's `trusted_proxies` list, read right-to-left so a client cannot invent its address; anything malformed falls back to the connecting address. Private/loopback ranges are flagged non-public. Storage modes: `none`, `truncated` (IPv4 /24, IPv6 /48; default), `keyed_hash` (HMAC with a secret; set `SITE_ACTIVITY_SECRET` in the environment to control the key), `full`. Raw addresses are visible only on a separate owner page that is off by default and audited. Country comes only from an owner-named header of a trusted proxy (e.g. Cloudflare); there is no geo-IP database lookup.

## Retention (defaults, editable)
events 90 days · IP values 30 days (then nulled, session kept) · sessions 365 days · audit log 730 days · consent log 3 years · errors 30 days. Run `tools/activity-maintenance.php retention` daily or press the button on *Exports & data*. These commands touch `activity_*` tables only; orders, tickets, users and payments are never deleted by the module.

## Erasure / access
A browser can erase its own data. The owner can erase everything linked to a logged-in account (*Exports & data*). Orders/tickets/payments are accounting records and stay. Consent rows are un-linked, not deleted. Limitation: if someone cleared their cookies, their old records cannot be matched to them.

## Security checklist before going live
- [ ] HTTPS on, so cookies get the `Secure` flag automatically
- [ ] `consent_mode` = `opt_in`; privacy notice and cookie page reviewed (cookie page already lists sa_consent/sa_vid/sa_sid)
- [ ] `TK_ACTIVITY_OWNER_EMAILS` set to the real owner email(s)
- [ ] `trusted_proxies` empty unless behind a proxy you control; if behind one, set it exactly
- [ ] `SITE_ACTIVITY_SECRET` set if using `keyed_hash`
- [ ] `ip_mode` chosen on purpose; `ip_view_enabled` off unless needed
- [ ] retention cron/Task Scheduler job in place
- [ ] `data/` is not web-readable (the project's existing rule); never expose the SQLite file
- [ ] test traffic token (`test_token`) left empty in production

## Needs a human / legal decision (not decided by code)
lawful-basis wording in the privacy policy; ODPC registration status; whether `notice_only` is acceptable; marketing e-mail/SMS rules; any cross-border transfer if the host is outside Kenya; retention periods that suit your accounting obligations.
