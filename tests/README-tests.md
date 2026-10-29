# Tests

| File | What it checks | Needs server |
|---|---|---|
| `test_project_integrity.py` | PHP syntax, parser fixture, payment hard-blocks, carousel markup, seed idempotency | no |
| `php tests/test_discovery.php` | Sync engine: new/updated/duplicate/cancel/missing-event rules | no |
| `run_tests.py` | HTTP CRUD, auth, admin permissions, uploads (disposable DB only) | yes, port 8080, demo mode |
| `test_site_regression.py` | Every public page: one H1, meta, labels, alt text, internal links, headers, cookies, robots, sitemap, noindex, payments POST, listing volume | yes, port 8080 |
| `browser_check.mjs` | Real-browser check of stacking, count-up and carousel (optional, needs Node + puppeteer-core) | yes |

## Volume test
Seed a throwaway folder and serve it on 8081:

    set TK_DATA_DIR=C:\temp\tkscale
    (create 30+ upcoming events with the admin area, or a script)
    cd public && C:\xampp\php\php.exe -S localhost:8081 router.php

then `set TK_SCALE_URL=http://127.0.0.1:8081` and run `python tests\test_site_regression.py`.
