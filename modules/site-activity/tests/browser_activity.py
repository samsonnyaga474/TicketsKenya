"""
Real-browser tests (Chromium via Playwright) for Site Activity & Insights.
Needs: pip install playwright  (+ a Chromium build)   and a DISPOSABLE copy of the site served on TK_URL.
Optional: TK_ORIGINAL_URL = the untouched original site, for the homepage visual comparison.
    python modules/site-activity/tests/browser_activity.py
"""
import os, re, sqlite3, sys, time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[3]
B = os.environ.get('TK_URL', 'http://127.0.0.1:8081').rstrip('/')
ORIG = os.environ.get('TK_ORIGINAL_URL', '').rstrip('/')
DB = os.environ.get('TK_DB', str(ROOT / 'data' / 'ticketskenya.sqlite'))
SHOTS = Path(os.environ.get('TK_SHOTS', '/tmp/shots')); SHOTS.mkdir(parents=True, exist_ok=True)
passed, failed = 0, []
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'  # headless UAs are (rightly) treated as bots


def check(name, ok, detail=''):
    global passed
    if ok: passed += 1
    else: failed.append(name)
    print(('PASS ' if ok else 'FAIL ') + name + (f' - {detail}' if detail else ''))


def q(sql, *p):
    c = sqlite3.connect(DB); c.row_factory = sqlite3.Row
    try: return [dict(r) for r in c.execute(sql, p).fetchall()]
    finally: c.close()


def n(where='1=1', *p):
    return q(f'SELECT COUNT(*) n FROM activity_events WHERE {where}', *p)[0]['n']


def settle(page, ms=1800):
    page.wait_for_timeout(ms)  # tracker batches for 800 ms


NOISE = re.compile(r'fonts\.(googleapis|gstatic)\.com|ERR_(INTERNET_DISCONNECTED|NAME_NOT_RESOLVED|CONNECTION|BLOCKED|FAILED)|Failed to load resource', re.I)


def watch(page, bucket):
    page.on('console', lambda m: bucket['console'].append(m.text) if m.type == 'error' and not NOISE.search(m.text) else None)
    page.on('pageerror', lambda e: bucket['js'].append(str(e)))
    # a keepalive tracking request that the browser reports as ERR_ABORTED when navigation starts is expected (the 204 still arrives)
    page.on('requestfailed', lambda r: bucket['failed'].append(r.url) if not NOISE.search(r.url) and not (r.url.endswith('/activity/collect.php') and 'ERR_ABORTED' in str(r.failure)) else None)


def login(page, email):
    page.goto(B + '/auth/login.php'); page.fill('#email', email); page.fill('#password', 'DemoPass123!')
    page.click('form[action="/auth/login.php"] button[type=submit]'); page.wait_for_load_state('networkidle')


def main():
    with sync_playwright() as p:
        br = p.chromium.launch()
        log = {'console': [], 'js': [], 'failed': []}

        # ------------------------------------------------ 1. banner, consent, page view (desktop)
        ctx = br.new_context(viewport={'width': 1280, 'height': 800}, user_agent=UA); pg = ctx.new_page(); watch(pg, log)
        pg.goto(B + '/'); pg.wait_for_load_state('networkidle')
        banner = pg.locator('#saConsent')
        check('desktop: consent banner is visible on first visit', banner.is_visible())
        pg.screenshot(path=str(SHOTS / 'home_desktop_with_banner.png'))
        check('before consent: nothing stored', n() == 0 and not [c for c in ctx.cookies() if c['name'] in ('sa_vid', 'sa_sid')])
        pg.click('[data-sa-decision=accept]'); settle(pg)
        check('banner disappears without a page reload', not pg.locator('#saConsent').count())
        names = {c['name'] for c in ctx.cookies()}
        check('accept sets consent and visitor cookies (first-party)', {'sa_consent', 'sa_vid'} <= names, str(sorted(names)))
        check('accept immediately records the current page view once', n("name='page_view' AND path='/'") == 1, str(n("name='page_view'")))

        # ------------------------------------------------ 2. clicks, one click -> one event
        pg.click('header nav a[href="/pricing.php"]'); pg.wait_for_load_state('networkidle'); settle(pg)
        nav = q("SELECT * FROM activity_events WHERE name='navigation_click'")
        check('header nav click -> exactly one navigation_click with stable id', len(nav) == 1 and nav[0]['element_id'] == 'header.pricing-php' and nav[0]['target_path'] == '/pricing.php' and nav[0]['path'] == '/', str([r['element_id'] for r in nav]))
        check('the next page records its own page view', n("name='page_view' AND path='/pricing.php'") == 1)
        pg.fill('#hdrQ', 'jazz night maria@example.com'); pg.press('#hdrQ', 'Enter'); pg.wait_for_load_state('networkidle'); settle(pg)
        sq = q("SELECT query_text, path FROM activity_events WHERE name='search_submitted'")
        check('header search -> one search_submitted, text redacted', len(sq) == 1 and 'maria' not in sq[0]['query_text'] and 'jazz night' in sq[0]['query_text'], str(sq))
        check('search results page recorded (/events/)', n("name='page_view' AND path='/events/'") == 1)
        pg.goto(B + '/events/'); pg.wait_for_load_state('networkidle'); settle(pg)  # the search above may legitimately match nothing
        pg.evaluate("sessionStorage.removeItem('sa_last_pv')")
        card = pg.locator('a[href^="/events/"]:not([href="/events/"]):not([href*="?"])').first
        href = card.get_attribute('href'); card.click(); pg.wait_for_load_state('networkidle'); settle(pg)
        slug = href.strip('/').split('/')[-1]
        ecid = q('SELECT id FROM events WHERE slug=?', slug)[0]['id']
        cc = q("SELECT * FROM activity_events WHERE name='event_card_clicked'")
        check('event card click -> one event_card_clicked mapped to the real event id', len(cc) == 1 and cc[0]['event_ref_id'] == ecid, str(cc[:1]))
        check('event page -> page_view + server-derived event_viewed with same event id', n("name='page_view' AND event_ref_id=?", ecid) == 1 and n("name='event_viewed' AND event_ref_id=?", ecid) == 1)
        qty = pg.locator('input[name^="qty["]').first
        if qty.count():
            qty.fill('2'); qty.dispatch_event('change'); settle(pg)
            check('ticket quantity change tracked with the quantity only', n("name='ticket_quantity_changed' AND value_num=2") == 1)
        else:
            check('ticket quantity field present on an owned event', False, 'no qty input on ' + href)
        # filters on /events/
        pg.goto(B + '/events/'); pg.wait_for_load_state('networkidle')
        sel = pg.locator('#evForm select[name=city]')
        if sel.count():
            opts = sel.locator('option').all_inner_texts()
            if len(opts) > 1:
                sel.select_option(index=1); settle(pg, 1500)
                check('city filter change tracked as filter_changed', n("name='filter_changed' AND element_id='filter-city'") >= 1)
        # outbound / dynamic content / duplicate listeners
        pg.evaluate("""() => { const a = document.createElement('a'); a.href = 'https://example.org/x?token=SECRET'; a.id = 'dyn'; a.textContent = 'Partner'; a.target = '_blank'; document.body.appendChild(a); }""")
        pg.evaluate("document.getElementById('dyn').addEventListener('click', e => e.preventDefault())")
        pg.click('#dyn'); settle(pg)
        ob = q("SELECT * FROM activity_events WHERE name='outbound_link_clicked'")
        check('dynamically added outbound link tracked once (event delegation)', len(ob) == 1 and ob[0]['target_path'] == 'example.org' and 'SECRET' not in str(ob[0]), str(ob))
        pg.evaluate("""() => { const s = document.createElement('script'); s.src = '/assets/js/activity.js'; s.setAttribute('data-endpoint', '/activity/collect.php'); s.setAttribute('data-state', 'granted'); document.body.appendChild(s); }""")
        settle(pg, 600)
        before = n("name='navigation_click'")
        pg.click('footer a[href="/about.php"]'); pg.wait_for_load_state('networkidle'); settle(pg)
        check('re-injecting the tracker script does not create duplicate click events', n("name='navigation_click'") == before + 1)
        fc = q("SELECT element_id FROM activity_events WHERE name='navigation_click' ORDER BY id DESC LIMIT 1")[0]['element_id']
        check('footer link gets a footer-zone id', fc == 'footer.about-php', fc)
        # back/forward
        pv_before = n("name='page_view'")
        pg.go_back(); pg.wait_for_load_state('networkidle'); settle(pg)
        pg.go_forward(); pg.wait_for_load_state('networkidle'); settle(pg)
        print(f'INFO back+forward added {n("name=\'page_view\'") - pv_before} page_view row(s) (a real network reload counts as a view; bfcache restores do not)')
        check('back/forward never adds more than one view per page shown', n("name='page_view'") - pv_before <= 2)
        sess = q('SELECT COUNT(*) n FROM activity_sessions')[0]['n']
        check('whole journey stayed in a single session', sess == 1, str(sess))
        check('no JS errors, console errors or failed same-site requests during the journey', not log['js'] and not log['console'] and not log['failed'], str(log))
        ctx.close()

        # ------------------------------------------------ 3. reject flow
        ctx2 = br.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True,
                              user_agent='Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1')
        m = ctx2.new_page(); watch(m, log); n0 = n()
        m.goto(B + '/'); m.wait_for_load_state('networkidle')
        check('mobile: banner visible and does not cause sideways scrolling', m.locator('#saConsent').is_visible() and m.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'))
        m.screenshot(path=str(SHOTS / 'home_mobile_with_banner.png'))
        m.click('[data-sa-decision=reject]'); settle(m)
        m.click('footer a[href="/pricing.php"]'); m.wait_for_load_state('networkidle'); settle(m)
        check('reject: no events stored and no tracking cookies', n() == n0 and not [c for c in ctx2.cookies() if c['name'] in ('sa_vid', 'sa_sid')])
        check('reject: choice remembered (banner not shown again)', not m.locator('#saConsent').count() and m.locator('#saConsent').count() == 0)
        m.goto(B + '/privacy-settings.php'); m.check('input[name=analytics]'); m.click('.sa-choice-form button.btn-lime'); m.wait_for_load_state('networkidle')
        m.goto(B + '/'); m.click('footer a[href="/about.php"]'); m.wait_for_load_state('networkidle'); settle(m)
        mob = q("SELECT device_category, screen_cat FROM activity_sessions ORDER BY id DESC LIMIT 1")[0]
        check('settings page can re-enable analytics; mobile device + small screen recorded', n("name='navigation_click' AND element_id='footer.about-php'") >= 1 and mob['device_category'] == 'mobile' and mob['screen_cat'] == 'small', str(mob))
        # withdraw again
        m.goto(B + '/privacy-settings.php'); m.uncheck('input[name=analytics]'); m.click('.sa-choice-form button.btn-lime'); m.wait_for_load_state('networkidle')
        n1 = n(); m.goto(B + '/pricing.php'); settle(m)
        check('withdrawal in the browser stops collection', n() == n1)
        ctx2.close()

        # ------------------------------------------------ 4. tracking failure must not break the site
        ctx3 = br.new_context(viewport={'width': 1280, 'height': 800}, user_agent=UA); f = ctx3.new_page(); fl = {'console': [], 'js': [], 'failed': []}; watch(f, fl)
        f.goto(B + '/'); f.click('[data-sa-decision=accept]'); settle(f)
        f.route('**/activity/collect.php', lambda r: r.abort())
        f.click('header nav a[href="/events/"]'); f.wait_for_load_state('networkidle'); settle(f)
        check('collection endpoint down: navigation still works', f.url.rstrip('/').endswith('/events'), f.url)
        f.unroute('**/activity/collect.php'); f.route('**/activity/collect.php', lambda r: r.fulfill(status=500, body='boom'))
        f.click('footer a[href="/contact.php"]'); f.wait_for_load_state('networkidle'); settle(f)
        check('collection endpoint returning 500: navigation still works, no page errors', f.url.endswith('/contact.php') and not fl['js'], str(fl['js']))
        f.unroute('**/activity/collect.php')
        # sensitive form values never leave the page
        f.goto(B + '/contact.php'); f.fill('#c-name', 'Secret Person'); f.fill('#c-email', 'secret@example.com'); f.fill('#c-message', 'my private message 123456789'); f.click('form.contact-wrap button[type=submit]'); f.wait_for_load_state('networkidle'); settle(f)
        dump = str(q('SELECT * FROM activity_events')) + str(q('SELECT * FROM activity_sessions'))
        check('contact form values never appear in analytics tables', 'Secret Person' not in dump and 'secret@example.com' not in dump and 'private message' not in dump)
        ctx3.close()

        # ------------------------------------------------ 5. real checkout as a customer
        ctx4 = br.new_context(viewport={'width': 1280, 'height': 800}, user_agent=UA); c = ctx4.new_page(); watch(c, log)
        c.goto(B + '/'); c.click('[data-sa-decision=accept]'); settle(c)
        login(c, 'demo.customer@ticketskenya.local')
        c.goto(B + '/events/devfest-nairobi-2026'); c.wait_for_load_state('networkidle')
        c.locator('input[name^="qty["]').first.fill('1'); o0 = q('SELECT COUNT(*) n FROM orders')[0]['n']
        c.click('.tk-ticket-form button[type=submit]'); c.wait_for_load_state('networkidle'); settle(c)
        check('browser checkout creates the order and shows the order page', '/tickets/order.php' in c.url and q('SELECT COUNT(*) n FROM orders')[0]['n'] == o0 + 1, c.url)
        last = q('SELECT id, total_kes FROM orders ORDER BY id DESC LIMIT 1')[0]
        ck = q("SELECT * FROM activity_events WHERE name='checkout_started'")
        check('browser checkout -> checkout_started tied to that order (server-side)', len(ck) == 1 and ck[0]['order_id'] == last['id'] and ck[0]['value_num'] == last['total_kes'])
        check('order page shows pending order, no purchase recorded', n("name='purchase_completed'") == 0 and 'Waiting for payment' in c.content())
        ctx4.close()

        # ------------------------------------------------ 6. dashboard in the browser
        for label, vp in [('desktop', {'width': 1280, 'height': 900}), ('tablet', {'width': 820, 'height': 1100}), ('mobile', {'width': 390, 'height': 844})]:
            cx = br.new_context(viewport=vp, user_agent=UA); a = cx.new_page(); dl = {'console': [], 'js': [], 'failed': []}; watch(a, dl)
            login(a, 'demo.admin@ticketskenya.local')
            a.goto(B + '/admin/'); a.wait_for_load_state('networkidle')
            if label == 'desktop':
                check('admin home has the Activity & Insights entry', a.locator('a[href="/admin/activity/"]').count() == 1)
                a.screenshot(path=str(SHOTS / 'admin_home.png'))
            a.click('a[href="/admin/activity/"]'); a.wait_for_load_state('networkidle')
            check(f'{label}: dashboard opens, no sideways page scroll', 'Activity &amp; Insights' in a.content() or 'Activity & Insights' in a.content() and a.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), str(a.evaluate('[document.documentElement.scrollWidth, window.innerWidth]')))
            a.screenshot(path=str(SHOTS / f'dashboard_{label}.png'), full_page=True)
            pvk = a.locator('.sa-kpi', has_text='Page views').locator('strong').inner_text()
            check(f'{label}: Page views KPI equals database', pvk == str(n("name='page_view'")), f'{pvk} vs {n("name=\'page_view\'")}')
            check(f'{label}: activity chart is drawn with bars, a line and fits the screen', a.locator('svg.sa-chart rect.bar').count() >= 1 and a.locator('svg.sa-chart polyline.line').count() == 1 and a.locator('.sa-chart-wrap').evaluate('e => e.getBoundingClientRect().right <= window.innerWidth + 1'))
            if label == 'desktop':
                a.select_option('#saRange', 'yesterday'); a.click('.sa-range button'); a.wait_for_load_state('networkidle')
                check('yesterday filter shows empty state in the browser', a.locator('.empty-state').count() > 0 and a.locator('.sa-kpi', has_text='Page views').locator('strong').inner_text() == '0')
                a.goto(B + '/admin/activity/live.php'); [a.wait_for_timeout(500) for _ in range(16) if 'Loading' in a.locator('#saSess').inner_text()]  # page CSP forbids eval, so poll from Python
                check('live page fills from the JSON API', a.locator('#saSess tr').count() >= 1 and 'Loading' not in a.locator('#saSess').inner_text())
                a.screenshot(path=str(SHOTS / 'live_desktop.png'), full_page=True)
                for pth in ['journeys', 'events', 'campaigns', 'audiences', 'log', 'data', 'settings']:
                    a.goto(B + f'/admin/activity/{pth}.php'); a.wait_for_load_state('networkidle')
                    ok = a.locator('.sa-subnav').count() == 1 and a.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1')
                    check(f'{label}: {pth} page renders in the site style without overflow', ok)
                a.screenshot(path=str(SHOTS / 'settings_desktop.png'), full_page=True)
            check(f'{label}: dashboard produced no JS/console errors', not dl['js'] and not dl['console'], str(dl))
            cx.close()
        cx = br.new_context(user_agent=UA); cu = cx.new_page(); login(cu, 'demo.customer@ticketskenya.local')
        r = cu.goto(B + '/admin/activity/')
        check('customer in a real browser gets 403 and no dashboard content', r.status == 403 and 'Page views' not in cu.content())
        cx.close()

        # ------------------------------------------------ 7. visual preservation of the homepage
        if ORIG:
            from PIL import Image, ImageChops
            def shot(url, name, dismiss):
                cx = br.new_context(viewport={'width': 1280, 'height': 800}, user_agent=UA); pp = cx.new_page(); pp.goto(url + '/'); pp.wait_for_load_state('networkidle')
                if dismiss and pp.locator('[data-sa-decision=reject]').count(): pp.click('[data-sa-decision=reject]'); pp.wait_for_timeout(500)
                pp.add_style_tag(content='*{animation:none!important;transition:none!important}'); pp.wait_for_timeout(300)
                pp.screenshot(path=str(SHOTS / name)); cx.close()
            shot(ORIG, 'home_original.png', False); shot(B, 'home_updated.png', True)
            a_, b_ = Image.open(SHOTS / 'home_original.png').convert('RGB'), Image.open(SHOTS / 'home_updated.png').convert('RGB')
            diff = ImageChops.difference(a_, b_); bbox = diff.getbbox()
            px = sum(1 for v in diff.convert('L').getdata() if v > 12); ratio = px / (a_.size[0] * a_.size[1])
            check('homepage first screen is visually identical to the original (banner dismissed)', a_.size == b_.size and ratio < 0.005, f'differing pixels {px} ({ratio:.4%}), bbox={bbox}')
        br.close()
    print(f'\nBROWSER RESULT: {passed} passed, {len(failed)} failed')
    if failed: print('FAILED:', *failed, sep='\n  - ')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
