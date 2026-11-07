"""Portable static + idempotency checks. Run: python tests/test_project_integrity.py"""
from pathlib import Path
import json, os, re, shutil, subprocess, tempfile

ROOT = Path(__file__).resolve().parents[1]
failures = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (f' — {detail}' if detail else ''))
    if not ok: failures.append(name)

php = shutil.which('php')
if not php:
    # Local XAMPP installs do not usually put php on PATH; look in common locations.
    for candidate in (r'C:\xampp\php\php.exe', Path(ROOT).drive + r'\xampp\php\php.exe', '/usr/bin/php', '/usr/local/bin/php'):
        if candidate and Path(candidate).is_file():
            php = candidate
            break
check('PHP CLI available', bool(php), php or 'php not found on PATH or in common install paths')
if php:
    php_files = list(ROOT.rglob('*.php'))
    syntax_ok = True
    for file in php_files:
        r = subprocess.run([php, '-l', str(file)], capture_output=True, text=True)
        if r.returncode:
            syntax_ok = False
            print(r.stdout, r.stderr)
    check(f'PHP syntax ({len(php_files)} files)', syntax_ok)
    self_test = subprocess.run([php, str(ROOT/'tools/sync-discovery.php'), '--self-test'], cwd=ROOT, capture_output=True, text=True, timeout=30)
    check('iCalendar feed parser fixture', self_test.returncode == 0, (self_test.stdout+self_test.stderr).replace('\n',' ')[:240])

home = (ROOT/'public/index.php').read_text(encoding='utf-8')
js = (ROOT/'public/assets/js/main.js').read_text(encoding='utf-8')
css = (ROOT/'public/assets/css/site.css').read_text(encoding='utf-8')
check('homepage has marquee viewport and matching track', 'class="tk-marquee"' in home and 'class="tk-marquee-track"' in home and 'data-marquee' in home)
check('culture carousel has matching track', home.count('tk-marquee-track') >= 2 and home.count('data-marquee') >= 2)
check('carousel handles reduced motion and interaction', 'prefers-reduced-motion: reduce' in js and 'touchstart' in js and 'focusin' in js)
check('marquee styles present', '.tk-marquee-track' in css and '.tk-marquee.is-static' in css)
check('playing card draws only current event categories', 'data-category-deck' in home and 'data-deal-card' in home and 'category' in js and 'tk-playing-card' in css)
check('reference statistics count up to the supplied exact values', all(n in home for n in ('2500', '520', '350', '6', 'Live Events Powered', 'Tickets Sold', 'Unique Fans', 'Active Markets')) and 'data-count="<?= (int)$s[\'value\'] ?>"' in home)
receipt = (ROOT/'public/tickets/order.php').read_text(encoding='utf-8')
check('order receipt includes complete totals, ticket data and email delivery status', all(x in receipt for x in ('Total paid', 'Subtotal', 'Tax (not configured)', 'Total due', 'Location', 'Paid with', 'Ticket type(s)', 'data-print-receipt', 'Email delivery is not configured')) and 'data-stage=' in receipt)
check('tax is stored explicitly and never guessed', "'tax_kes' => $tax" in (ROOT/'public/events/view.php').read_text(encoding='utf-8') and "'tax_kes' => \"INTEGER NOT NULL DEFAULT 0\"" in (ROOT/'config/database.php').read_text(encoding='utf-8'))
event_detail = (ROOT/'public/events/view.php').read_text(encoding='utf-8')
check('discovery detail pages cannot send visitors to external purchase links', "($event['listing_type'] ?? '') !== 'owned'" in event_detail and 'target="_blank"' not in event_detail and 'Get tickets on the official site' not in event_detail)

payments = '\n'.join(p.read_text(encoding='utf-8') for p in [ROOT/'includes/payments.php', *sorted((ROOT/'public/payments').glob('*.php'))])
check('payment endpoints are fail-closed', all('payments_enabled' in (ROOT/'public/payments/webhook.php').read_text(encoding='utf-8') or 'disabled' in p.read_text(encoding='utf-8').lower() for p in (ROOT/'public/payments').glob('*.php')))
check('provider API code removed', 'api.flutterwave.com' not in payments and 'curl_init' not in payments)
check('feed sync source config valid', bool(json.loads((ROOT/'tools/discovery-sources.json').read_text(encoding='utf-8')).get('sources')))
sources_config = json.loads((ROOT/'tools/discovery-sources.json').read_text(encoding='utf-8'))
check('feed with republication restriction disabled by default', all(not s.get('enabled', False) for s in sources_config.get('sources', []) if 'prohibit' in s.get('disabled_reason', '').lower()))
check('sync log + source-based dedupe code present', 'discovery-sync-log.json' in (ROOT/'tools/sync-discovery.php').read_text(encoding='utf-8') and 'source_url' in (ROOT/'tools/import-discovery.php').read_text(encoding='utf-8'))
discovery_admin = (ROOT/'public/admin/discovery.php').read_text(encoding='utf-8')
check('sync history dashboard is admin-only', "tk_require_role('admin')" in discovery_admin and 'discovery-sync-log.json' in discovery_admin)

# No obsolete payment-provider code anywhere in executable PHP.
active_php = [p for p in ROOT.rglob('*.php') if '.git' not in p.parts]
provider_hits = []
for p in active_php:
    text = p.read_text(encoding='utf-8', errors='ignore').lower()
    if 'pesapal' in text or 'flutterwave' in text or 'paystack' in text:
        provider_hits.append(str(p.relative_to(ROOT)))
check('no obsolete payment-provider references in PHP', not provider_hits, ', '.join(provider_hits[:5]))

uploads_htaccess = ROOT/'public/assets/uploads/.htaccess'
check('upload folder denies PHP execution', uploads_htaccess.is_file() and 'php_flag engine off' in uploads_htaccess.read_text(encoding='utf-8') and 'Require all denied' in uploads_htaccess.read_text(encoding='utf-8'))
check('uploads protection is not git-ignored', '!public/assets/uploads/.htaccess' in (ROOT/'.gitignore').read_text(encoding='utf-8'))

ignore = (ROOT/'.gitignore').read_text(encoding='utf-8')
check('gitignore protects env, databases and local work', all(x in ignore for x in ('.env', '*.sqlite', '/work/')))

totp = ROOT/'includes/totp.php'
login_page = (ROOT/'public/auth/login.php').read_text(encoding='utf-8')
check('TOTP helper and two-step login present', totp.is_file() and 'tk_totp_verify' in login_page and 'pending_2fa' in login_page)
check('2FA setup tool is CLI-only', "PHP_SAPI !== 'cli'" in (ROOT/'tools/setup-2fa.php').read_text(encoding='utf-8'))
check('production/reclassify tools are CLI-only', all("PHP_SAPI !== 'cli'" in (ROOT/'tools'/name).read_text(encoding='utf-8') for name in ('production-check.php', 'reclassify-inventory.php')))

if php:
    # Sandboxed Windows environments may deny Python's default AppData temp path.
    # TK_TEST_TMP_DIR lets CI/local runners point disposable fixtures at a writable directory.
    with tempfile.TemporaryDirectory(prefix='ticketskenya-test-', dir=os.environ.get('TK_TEST_TMP_DIR') or None) as td:
        work = Path(td)/'TicketsKenya'
        shutil.copytree(ROOT, work, ignore=shutil.ignore_patterns('*.sqlite', 'store.json', 'discovery-sync-log.json', '__pycache__', '*.pyc'))
        data = work/'data'
        data.mkdir(exist_ok=True)
        for name in ('ticketskenya.sqlite', 'store.json', 'discovery-sync-log.json'):
            try: (data/name).unlink()
            except FileNotFoundError: pass
        runs=[]
        for i in range(2):
            r=subprocess.run([php, str(work/'tools/import-discovery.php')], cwd=work, capture_output=True, text=True, timeout=30)
            runs.append(r.stdout+r.stderr)
            check(f'seed importer run {i+1}', r.returncode == 0, runs[-1].replace('\n',' ')[:220])
        second_created = re.search(r'Created\s*:\s*(\d+)', runs[1])
        check('seed importer idempotent (second run creates zero)', bool(second_created and int(second_created.group(1)) == 0), runs[1].replace('\n',' ')[:220])

print(f'\nResult: {len(failures)} failed check(s).')
raise SystemExit(1 if failures else 0)
