# Deploying TicketsKenya (production checklist)

This is the deployment runbook for the current tree. It does **not** cover payments: no payment
provider is integrated, all payment endpoints fail closed, and a payment decision is still pending.

## 1. Requirements

| Item | Requirement |
|---|---|
| PHP | 8.1+ (tested on 8.2.12, XAMPP build) |
| Extensions | `pdo_sqlite`, `fileinfo`, `openssl`, `session`, `json` (all standard; `mbstring` optional) |
| Web server | Apache with `mod_rewrite` **or** nginx with PHP-FPM |
| Document root | The project's `public/` directory — never the project root |
| Writable paths | `data/`, `public/assets/uploads/` |
| HTTPS | Required (Let's Encrypt or host certificate) |

## 2. Environment file (create on the server only)

Create `.env` **on the server**, outside `public/`, and set real values there using the configuration
table in `README.md`. The repository does not publish an environment template.
Never commit, screenshot or share `.env`. Restrict it to the web-server user (e.g. `chmod 600`).

Required production values:

| Key | Production value |
|---|---|
| `TK_ENV` | `production` |
| `TK_APP_URL` | `https://your-domain.example` |
| `TK_DEMO_MODE` | `false` |
| `TK_PAYMENTS_ENABLED` | `false` (until a provider is built) |
| `TK_SUPPORT_EMAIL` | a monitored business address |
| `TK_TRUST_PROXY` | `true` only behind a proxy that overwrites `X-Forwarded-Proto` |

Verify with `php tools/production-check.php` (read-only).

## 3. Apache

```apache
<VirtualHost *:443>
    ServerName your-domain.example
    DocumentRoot /var/www/ticketskenya/public
    <Directory /var/www/ticketskenya/public>
        AllowOverride All
        Require all granted
    </Directory>
    # Project files stay outside the web root.
</VirtualHost>
```

`public/.htaccess` handles clean `/events/<slug>` URLs, 404s, and blocking dotfiles/`*.sqlite`/`*.json`.
The project-root `.htaccess` (`Require all denied`) is a backstop if the host is ever pointed at the
wrong folder.

## 4. nginx (no `.htaccess`)

```nginx
server {
    listen 443 ssl;
    server_name your-domain.example;
    root /var/www/ticketskenya/public;
    index index.php;

    location ~ ^/(events/[a-z0-9-]+)/?$ { try_files $uri /events/view.php?slug=$1&$args; }
    location /sitemap.xml { try_files $uri /sitemap.php; }
    location /robots.txt  { try_files $uri /robots.php; }
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(env|sqlite|sqlite3|json|log|sql|bak)$ { deny all; }
    location ~ ^/assets/uploads/.*\.(php|phtml|phar)$ { deny all; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location / { try_files $uri $uri/ =404; }
}
```

Also disable PHP execution in the uploads folder at the PHP-FPM pool level if the host allows it.

## 5. Permissions

- `data/` — writable by the web-server user; not readable by other site users on shared hosting where possible.
- `public/assets/uploads/` — writable; `public/assets/uploads/.htaccess` **must** stay in place.
- Everything else read-only for the web server.

## 6. Database

- SQLite file: `data/ticketskenya.sqlite` (or `TK_DATA_DIR` if set). If `pdo_sqlite` is unavailable the
  app falls back to `data/store.json`.
- **Migrations are additive and automatic** on the first request: `CREATE TABLE IF NOT EXISTS`,
  `ALTER TABLE ... ADD COLUMN` for orders/ticket types/events/users (`totp_secret`, `listing_type`, etc.).
  No manual SQL is required and no destructive migration exists.
- Back up `data/` (and the uploads folder) before the first request on a new host.

## 7. Demo data cleanup (inspect first, never delete blindly)

The current local database contains development data. Run the read-only checker:

```bat
C:\xampp\php\php.exe tools\production-check.php
```

It reports, without changing anything:

1. **3 demo accounts** (`demo.organiser@`, `demo.customer@`, `demo.admin@…ticketskenya.local`).
2. **An admin without TOTP**.
3. **56 externally sourced listings marked `owned`** (no `organiser_id`, with `source_url`), each with
   auto-generated ticket types; these can currently be sold.
4. **2 pending/unpaid orders** created by development testing.

Recommended production cleanup (in this order):

```bat
:: 1. Create the real admin account. Pass a long unique password as the third
::    argument; it is not echoed by the tool, but it may be kept in shell history.
C:\xampp\php\php.exe tools\create-admin.php you@your-domain.example "Your Name" "replace-with-a-long-unique-password"

:: 2. Add two-factor to the real admin
C:\xampp\php\php.exe tools\setup-2fa.php you@your-domain.example

:: 3. Preview reclassifying externally sourced listings back to discovery
C:\xampp\php\php.exe tools\reclassify-inventory.php --to=discovery

:: 4. Only if the preview is correct, apply it (changes listing_type only;
::    events, ticket types and orders are kept)
C:\xampp\php\php.exe tools\reclassify-inventory.php --to=discovery --apply
```

Then decide, with the organiser relationship in hand, whether any listing should remain `owned`
(with a real `organiser_id` and real ticket types). Remove demo accounts and test orders only after
confirming no real payments/tickets are attached (payments never worked in this build, so pending
unpaid orders are safe to delete — but verify first).

Re-run `tools/production-check.php --strict` until it reports no warnings.

## 8. Backups

- Nightly copy of `data/` and `public/assets/uploads/` (SQLite: copy while idle or use `sqlite3 .backup`).
- Keep at least one off-server copy; test a restore.
- The `work/` folder is local working data and must not be deployed.

## 9. Deploy and verify

1. Upload the project **without** `.env`, `data/`, `work/`, uploads and old test artifacts; create
   `.env` and the writable folders on the server.
2. Run `php tools/production-check.php --strict`.
3. Run the read-only suite: `php tests/test_payments_logic.php`, `php tests/test_totp.php`,
   `php tools/sync-discovery.php --self-test`.
4. With the site served over HTTPS: `python tests/test_site_regression.py` (expects
   `http://127.0.0.1:8080` locally; adjust the URL for the host).
5. On a **disposable copy** of the database: `python tests/run_tests.py` and
   `python tests/test_2fa_e2e.py --db <copy>/data/ticketskenya.sqlite`.
6. Confirm: HTTP redirects to HTTPS, `Set-Cookie` has `Secure; HttpOnly`, `/payments/*` refuses to
   charge, `/.env` and `/.git/config` return 404.
7. Keep `TK_ENV=production` (errors off, HSTS on).

## 10. Outstanding before launch

- Legal review of Privacy/Terms/Refund/Cookie text (Kenya Data Protection Act).
- Real inventory decision for the 56 imported listings (see section 7).
- Email delivery (ticket emails and password reset are not configured).
- Payment decision and integration (BTCPay and/or a business M-Pesa Till) — stays disabled until built.
- Scheduled backups and log/error monitoring.
