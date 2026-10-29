# Testing guide

Always use a **disposable copy** of the project and a throwaway database. The tests write synthetic visitors and orders.

```
cd C:\xampp\htdocs\TicketsKenya-testcopy
set TK_DEMO_MODE=true
C:\xampp\php\php.exe tools\import-discovery.php
C:\xampp\php\php.exe tools\ensure-owned-inventory.php
cd public & C:\xampp\php\php.exe -S 127.0.0.1:8081 router.php          (leave running)
:: second window, from the project root
set TK_URL=http://127.0.0.1:8081
python modules\site-activity\tests\test_activity.py       HTTP + database + security (needs only Python)
python modules\site-activity\tests\test_resilience.py     migration, broken-table behaviour, timings
pip install playwright & python -m playwright install chromium
python modules\site-activity\tests\browser_activity.py    real browser (set TK_ORIGINAL_URL to compare the homepage with an untouched copy)
```
Environment: `TK_URL`, `TK_DB` (path to the test SQLite file, default `data\ticketskenya.sqlite`), `TK_ORIGINAL_URL`, `TK_SHOTS`, `TK_SHIPPED_ROOT`.
Existing project tests are unchanged: `python tests\run_tests.py`, `python tests\test_site_regression.py`, `python tests\test_project_integrity.py`, `php tests\test_discovery.php`.

Known pre-existing failures in the original project tests (they fail identically on the untouched ZIP): `run_tests.py` "listed in /events/" (new test event is not on the first of several listing pages); `test_site_regression.py` "receipt contains payment totals…" (checks for lower-case "email delivery…" while the page says "Email delivery…"), and that file needs `ROOT` defined to run at all.
