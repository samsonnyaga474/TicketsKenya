"""
Site Activity & Insights - integration, database and security tests.

Run against a DISPOSABLE copy of the project (never production):
    set TK_DEMO_MODE=true
    cd public && php -S 127.0.0.1:8081 router.php
    python modules/site-activity/tests/test_activity.py            (from the project root)

Env: TK_URL (default http://127.0.0.1:8081), TK_DB (default <project>/data/ticketskenya.sqlite)
It writes synthetic visitors into that disposable database only.
"""
import datetime, http.cookiejar, json, os, re, sqlite3, sys, time, urllib.error, urllib.parse, urllib.request
from zoneinfo import ZoneInfo
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
B = os.environ.get('TK_URL', 'http://127.0.0.1:8081').rstrip('/')
DB = os.environ.get('TK_DB', str(ROOT / 'data' / 'ticketskenya.sqlite'))
UA_DESK = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
UA_MOB = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
UA_TAB = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
UA_BOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'

passed, failed = 0, []


def check(name, ok, detail=''):
    global passed
    if ok:
        passed += 1
        print('PASS ' + name + (f' - {detail}' if detail else ''))
    else:
        failed.append(name)
        print('FAIL ' + name + (f' - {detail}' if detail else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, ua=UA_DESK):
        self.cj = http.cookiejar.CookieJar()
        self.o = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj), NoRedirect)
        self.ua = ua

    def req(self, path, data=None, headers=None, method=None, raw=None):
        h = {'User-Agent': self.ua}
        h.update(headers or {})
        body = None
        if raw is not None:
            body = raw if isinstance(raw, bytes) else raw.encode()
        elif data is not None:
            body = urllib.parse.urlencode(data).encode()
        r = urllib.request.Request(B + path, data=body, headers=h, method=method)
        try:
            resp = self.o.open(r)
            return resp.status, resp.read().decode('utf-8', 'replace'), dict(resp.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)

    def get(self, path, headers=None):
        return self.req(path, headers=headers)

    def cookie(self, name):
        for c in self.cj:
            if c.name == name:
                return c.value
        return None

    def csrf(self, page='/'):
        s, body, _ = self.get(page)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body) or re.search(r'data-csrf="([a-f0-9]+)"', body)
        return m.group(1) if m else ''

    def accept(self, marketing=False):
        t = self.csrf('/')
        if marketing:
            return self.req('/activity/consent.php', {'_csrf': t, 'decision': 'custom', 'analytics': '1', 'marketing': '1'}, {'Accept': 'application/json'})
        return self.req('/activity/consent.php', {'_csrf': t, 'decision': 'accept'}, {'Accept': 'application/json'})

    def track(self, events, ctx=None, headers=None, raw=None):
        body = raw if raw is not None else json.dumps({'events': events, 'ctx': ctx or {}})
        h = {'Content-Type': 'application/json', 'Sec-Fetch-Site': 'same-origin'}
        h.update(headers or {})
        return self.req('/activity/collect.php', headers=h, raw=body)

    def login(self, email, pw='DemoPass123!'):
        t = self.csrf('/auth/login.php')
        return self.req('/auth/login.php', {'_csrf': t, 'email': email, 'password': pw})


_n = [0]


def ev(name, path='/', **kw):
    _n[0] += 1
    d = {'n': name, 'id': f't{int(time.time())}{_n[0]:06d}x', 'p': path}
    d.update(kw)
    return d


def q(sql, *p):
    c = sqlite3.connect(DB)
    c.row_factory = sqlite3.Row
    try:
        return [dict(r) for r in c.execute(sql, p).fetchall()]
    finally:
        c.close()


def x(sql, *p):
    c = sqlite3.connect(DB)
    try:
        c.execute(sql, p)
        c.commit()
    finally:
        c.close()


def count(table='activity_events', where='1=1', *p):
    return q(f'SELECT COUNT(*) n FROM {table} WHERE {where}', *p)[0]['n']


def kpi(html, label):
    m = re.search(r'<span class="sa-kpi-l">' + re.escape(label) + r'</span><strong>([^<]*)</strong>', html)
    return m.group(1) if m else None


def settings_post(admin, **vals):
    """Post the full settings form as the owner (unchecked boxes are simply not sent)."""
    base = {k: v for k, v in current_settings().items()}
    base.update({k: str(v) for k, v in vals.items()})
    form = []
    for k in ['consent_mode', 'policy_version', 'excluded_paths', 'project_id', 'session_timeout_min', 'active_window_min', 'rate_limit_per_min',
              'refresh_seconds', 'ip_mode', 'trusted_proxies', 'geo_header', 'retention_events_days', 'retention_ip_days', 'retention_sessions_days',
              'retention_audit_days', 'attribution_window_days', 'abandonment_window_hours']:
        form.append((k, base[k]))
    for k in ['tracking_enabled', 'audiences_enabled', 'ip_view_enabled', 'exclude_admins', 'exports_enabled', 'capture_search_terms']:
        if base[k] == '1':
            form.append((k, '1'))
    for c in base['enabled_categories'].split(','):
        if c:
            form.append(('enabled_categories[]', c))
    form.append(('_csrf', admin.csrf('/admin/activity/settings.php')))
    return admin.req('/admin/activity/settings.php', raw=urllib.parse.urlencode(form), headers={'Content-Type': 'application/x-www-form-urlencoded'})


DEFAULTS = {'tracking_enabled': '1', 'consent_mode': 'opt_in', 'policy_version': '1', 'enabled_categories': 'navigation,search,event,checkout,account,error,outbound',
            'audiences_enabled': '0', 'session_timeout_min': '30', 'active_window_min': '5', 'retention_events_days': '90', 'retention_ip_days': '30',
            'retention_sessions_days': '365', 'retention_audit_days': '730', 'ip_mode': 'truncated', 'ip_view_enabled': '0', 'trusted_proxies': '', 'geo_header': '',
            'excluded_paths': '/admin,/auth,/payments,/activity', 'exclude_admins': '1', 'rate_limit_per_min': '2000', 'attribution_window_days': '30',
            'abandonment_window_hours': '24', 'exports_enabled': '1', 'refresh_seconds': '15', 'capture_search_terms': '1', 'project_id': 'ticketskenya'}


def current_settings():
    s = dict(DEFAULTS)
    for r in q('SELECT k, v FROM activity_settings'):
        if r['k'] in s:
            s[r['k']] = r['v']
    return s


def main():
    # ---------------------------------------------------------------- A. install and pages
    print('--- A. install, banner, settings page')
    anon = Client()
    s, home, _ = anon.get('/')
    check('home renders with consent banner and tracker script', s == 200 and 'id="saConsent"' in home and 'js/activity.js' in home and 'data-state="unset"' in home)
    check('home still has its original hero and footer', 'tk-footer' in home and 'Explore Events' in home)
    check('banner offers Accept and Reject with equal weight', home.count('class="btn btn-outline" data-sa-decision') == 2)
    s, ps, _ = anon.get('/privacy-settings.php')
    check('privacy settings page renders with choices and rights text', s == 200 and 'Save my choices' in ps and 'Data Protection Act' in ps)
    check('footer links to privacy settings', 'href="/privacy-settings.php"' in home)
    tables = {r['name'] for r in q("SELECT name FROM sqlite_master WHERE type='table'")}
    need = {'activity_settings', 'activity_sessions', 'activity_events', 'activity_consent', 'activity_audit_logs', 'activity_audiences', 'activity_order_links', 'activity_rate', 'activity_errors'}
    check('all module tables exist', need <= tables, str(sorted(need - tables)))
    idx = {r['name'] for r in q("SELECT name FROM sqlite_master WHERE type='index'")}
    check('key indexes exist', {'idx_ae_uid', 'idx_ae_order_once', 'idx_ae_time', 'idx_ae_session'} <= idx)
    x("INSERT OR REPLACE INTO activity_settings (k,v,updated_at) VALUES ('rate_limit_per_min','2000','2026-01-01 00:00:00')")

    # ---------------------------------------------------------------- B. access control (before any data)
    print('--- B. access control')
    s, _, h = Client().get('/admin/activity/')
    check('unauthenticated dashboard redirects to login', s == 302 and 'login' in h.get('Location', ''), str(s))
    s, body, _ = Client().get('/admin/activity/api.php')
    check('unauthenticated API is 401 with no data', s == 401 and 'sessions' not in body)
    s, body, _ = Client().req('/admin/activity/export.php', {'type': 'summary'})
    check('unauthenticated export is refused', s in (401, 403) and 'metric' not in body)
    cust = Client(); cust.login('demo.customer@ticketskenya.local')
    for page in ['', 'live.php', 'journeys.php', 'events.php', 'campaigns.php', 'audiences.php', 'log.php', 'data.php', 'settings.php', 'ips.php']:
        s, body, _ = cust.get('/admin/activity/' + page)
        check(f'customer blocked from {page or "index"}', s == 403 and 'Activity' not in body)
    s, body, _ = cust.get('/admin/activity/api.php')
    check('customer blocked from API', s == 403)
    org = Client(); org.login('demo.organiser@ticketskenya.local')
    s, body, _ = org.get('/admin/activity/')
    check('organiser blocked from dashboard', s == 403)
    s, body, _ = org.req('/admin/activity/settings.php', {'tracking_enabled': '0', '_csrf': org.csrf('/admin/')})
    check('organiser cannot change settings', s == 403 and count('activity_settings', "k='tracking_enabled'") == 0)
    admin = Client(); admin.login('demo.admin@ticketskenya.local')
    s, body, _ = admin.get('/admin/')
    check('owner sees Activity & Insights link on admin home', 'href="/admin/activity/"' in body)
    s, body, _ = org.get('/admin/')
    check('organiser does not see the link', 'href="/admin/activity/"' not in body)
    s, empty, _ = admin.get('/admin/activity/')
    check('owner dashboard loads with empty state', s == 200 and 'Nothing here yet' in empty and kpi(empty, 'Page views') == '0', str(s))
    check('empty dashboard shows no invented numbers', kpi(empty, 'Visitors') == '0' and kpi(empty, 'Revenue') == 'KES 0')

    # ---------------------------------------------------------------- C. consent gating
    print('--- C. consent')
    v = Client()
    s, _, _ = v.track([ev('page_view', '/')])
    check('no consent -> nothing stored', s == 204 and count() == 0 and count('activity_sessions') == 0)
    check('no consent -> no tracking cookies set', v.cookie('sa_vid') is None and v.cookie('sa_sid') is None)
    s, _, _ = v.req('/activity/consent.php', {'decision': 'accept'}, {'Accept': 'application/json'})
    check('consent without CSRF token refused', s == 403 and count('activity_consent') == 0)
    s, _, _ = v.req('/activity/consent.php', {'decision': 'accept', '_csrf': 'deadbeef'}, {'Accept': 'application/json'})
    check('consent with wrong CSRF token refused', s == 403)
    s, body, _ = v.get('/activity/consent.php')
    check('consent endpoint rejects GET', s == 405)
    s, body, _ = v.accept()
    check('accept stores consent and sets cookies', s == 200 and json.loads(body)['analytics'] is True and v.cookie('sa_consent') and v.cookie('sa_vid') and count('activity_consent', 'analytics=1') == 1)
    s, home2, _ = v.get('/')
    check('after accepting: no banner, tracker state granted', 'id="saConsent"' not in home2 and 'data-state="granted"' in home2)

    # ---------------------------------------------------------------- D. tracking
    print('--- D. tracking and stored data')
    s, _, _ = v.track([ev('page_view', '/'), ev('navigation_click', '/', e='header.pricing-php', l='Pricing', t='/pricing.php', m={'zone': 'header'}),
                       ev('button_click', '/', e='button.page.deal-a-card', l='Deal a card', m={'zone': 'page'}),
                       ev('link_click', '/', e='page.about-php', l='About', t='/about.php')], ctx={'ref': 'https://www.google.com/', 'lang': 'en-KE', 'sc': 'large'})
    check('batch accepted', s == 204)
    ss = q('SELECT * FROM activity_sessions')
    check('one session created from the batch', len(ss) == 1)
    se = ss[0] if ss else {}
    check('session device/browser/os classified', (se.get('device_category'), se.get('browser'), se.get('os')) == ('desktop', 'Chrome', 'Windows'), str((se.get('device_category'), se.get('browser'), se.get('os'))))
    check('referrer reduced to host+type (search)', (se.get('referrer_host'), se.get('referrer_type')) == ('google.com', 'search'), str((se.get('referrer_host'), se.get('referrer_type'))))
    check('screen bucket and language stored', (se.get('screen_cat'), se.get('language')) == ('large', 'en-ke'))
    check('page_view stored once', count('activity_events', "name='page_view'") == 1 and se.get('page_views') == 1)
    nav = q("SELECT * FROM activity_events WHERE name='navigation_click'")
    check('navigation click stores stable element id, label, target', len(nav) == 1 and nav[0]['element_id'] == 'header.pricing-php' and nav[0]['target_path'] == '/pricing.php' and nav[0]['element_label'] == 'Pricing')
    check('button and link clicks stored with page context', count('activity_events', "name='button_click' AND path='/' AND element_id='button.page.deal-a-card'") == 1 and count('activity_events', "name='link_click' AND target_path='/about.php'") == 1)
    check('events carry visitor and session ids from OUR cookies', all(r['visitor_uid'] == v.cookie('sa_vid') for r in q('SELECT visitor_uid FROM activity_events')))
    ids = q("SELECT id, slug, category FROM events WHERE slug IN ('devfest-nairobi-2026','mystika-halloween-koda-nairobi-2026') ORDER BY slug")
    dev = [r for r in ids if r['slug'].startswith('devfest')][0]; mys = [r for r in ids if r['slug'].startswith('mystika')][0]
    v.track([ev('page_view', '/events/devfest-nairobi-2026'), ev('event_card_clicked', '/events/', e='event-link.page', l='Mystika', t='/events/mystika-halloween-koda-nairobi-2026')])
    pv = q("SELECT * FROM activity_events WHERE name='page_view' AND path='/events/devfest-nairobi-2026'")
    check('event page view maps to the real event id and category', len(pv) == 1 and pv[0]['event_ref_id'] == dev['id'] and pv[0]['category_ref'] == dev['category'] and pv[0]['path_group'] == '/events/{event}')
    evv = q("SELECT * FROM activity_events WHERE name='event_viewed'")
    check('server derives event_viewed for that page view', len(evv) == 1 and evv[0]['event_ref_id'] == dev['id'] and evv[0]['source'] == 'server')
    cc = q("SELECT * FROM activity_events WHERE name='event_card_clicked'")
    check('event card click maps target to the clicked event id', len(cc) == 1 and cc[0]['event_ref_id'] == mys['id'])
    v.track([ev('search_submitted', '/events/', e='search-form', q='rock concerts jane.doe@example.com 0712 345 678'),
             ev('filter_changed', '/events/', m={'field': 'category', 'value': 'Concerts & Live Music'}),
             ev('filter_changed', '/events/', m={'field': 'city', 'value': 'Mombasa'}),
             ev('ticket_quantity_changed', '/events/devfest-nairobi-2026', e='ticket-quantity', m={'qty': '3', 'ticket_type': '7'})])
    sq = q("SELECT query_text FROM activity_events WHERE name='search_submitted'")
    check('search text stored with email and phone removed', len(sq) == 1 and 'jane' not in sq[0]['query_text'] and '0712' not in sq[0]['query_text'] and '[email]' in sq[0]['query_text'], sq[0]['query_text'] if sq else '')
    check('category filter stored as category_ref', count('activity_events', "name='filter_changed' AND category_ref='Concerts & Live Music'") == 1)
    check('quantity change stored as a number', count('activity_events', "name='ticket_quantity_changed' AND value_num=3") == 1)
    v.track([ev('login_attempted', '/auth/login.php', e='form.auth-login-php', l='hunter2 secret', m={'value': 'hunter2'})])
    check('login path is excluded entirely (/auth)', count('activity_events', "name='login_attempted'") == 0)
    v.track([ev('contact_submitted', '/contact.php', e='form.contact-php', l='my private message', m={'value': 'x', 'msg': 'private'})])
    cs = q("SELECT * FROM activity_events WHERE name='contact_submitted'")
    check('account/contact form submits keep NO label or field data', len(cs) == 1 and cs[0]['element_label'] is None and cs[0]['meta_json'] is None and cs[0]['query_text'] is None)
    dup = ev('button_click', '/', e='button.page.dup', l='Dup')
    v.track([dup]); v.track([dup]); v.track([dup, dup])
    check('same event id sent 4 times is stored once', count('activity_events', "element_id='button.page.dup'") == 1)
    tot_before = count()
    s, _, _ = v.track([ev('page_view', '/admin/orders'), ev('page_view', '//evil.example/x'), ev('page_view', 'http://evil.example'), ev('page_view', '/a b'), ev('page_view', '/' + 'a' * 400)])
    check('excluded and malformed paths are not stored', count() == tot_before)
    sess_now = q('SELECT page_views, event_count FROM activity_sessions')[0]
    check('session counters equal stored events', sess_now['event_count'] == count('activity_events', "session_id=(SELECT id FROM activity_sessions)  AND source='client'") and sess_now['page_views'] == count('activity_events', "name='page_view'"), str(sess_now))

    # device classes
    m = Client(UA_MOB); m.accept(); m.track([ev('page_view', '/pricing.php')])
    t = Client(UA_TAB); t.accept(); t.track([ev('page_view', '/pricing.php')])
    devs = sorted(r['device_category'] for r in q('SELECT device_category FROM activity_sessions'))
    check('mobile and tablet classified', devs == ['desktop', 'mobile', 'tablet'], str(devs))
    b = Client(UA_BOT); b.accept(); n0 = count()
    b.track([ev('page_view', '/')])
    check('bots are not tracked', count() == n0)
    u = Client('Weird/1.0'); u.accept(); u.track([ev('page_view', '/faq.php')])
    check('unknown user agent recorded as unknown, not guessed', q("SELECT device_category, browser, os FROM activity_sessions WHERE landing_path='/faq.php'")[0] == {'device_category': 'unknown', 'browser': 'unknown', 'os': 'unknown'})

    # ---------------------------------------------------------------- E. collection security
    print('--- E. collection security')
    f = Client(); f.accept()
    n0 = count()
    s, _, _ = f.track([ev('purchase_completed', '/', order_id=1, value_num=999999), ev('checkout_started', '/'), ev('event_viewed', '/events/devfest-nairobi-2026'), ev('payment_initiated', '/')])
    check('browser cannot create purchase/checkout/server events', count('activity_events', "name IN ('purchase_completed','checkout_started','payment_initiated')") == 0 and count('activity_events', "name='event_viewed'") == 1)
    check('forgery attempts are logged as collection errors', count('activity_errors', "kind='forged_server_event'") >= 3)
    f.track([ev('totally_made_up', '/'), ev("page_view'; DROP TABLE activity_events;--", '/')])
    check('unknown event names rejected', count('activity_events', "name LIKE '%made_up%'") == 0 and count('activity_errors', "kind='unknown_event'") >= 2)
    s, _, _ = f.track([], raw='x' * 20000)
    check('oversized body rejected (413)', s == 413)
    s, _, _ = f.track([], raw='{not json')
    check('invalid JSON rejected (400)', s == 400)
    s, _, _ = f.track([ev('page_view', '/')], headers={'Origin': 'http://evil.example'})
    check('cross-origin Origin header rejected', s == 403)
    s, _, _ = f.track([ev('page_view', '/')], headers={'Sec-Fetch-Site': 'cross-site'})
    check('cross-site fetch metadata rejected', s == 403)
    s, _, _ = f.get('/activity/collect.php')
    check('collect endpoint rejects GET', s == 405)
    s, body, _ = f.track([ev('page_view', '/')], headers={'Origin': B})
    check('same-origin Origin header accepted', s == 204)
    f.track([ev('button_click', '/', e='x', l="'); DROP TABLE users;-- <script>alert(1)</script>"), ev('search_submitted', '/events/', q="' OR 1=1 --")])
    check('SQL injection text stored inertly; tables intact', count('users') >= 3 and 'DROP TABLE' in q("SELECT element_label l FROM activity_events WHERE element_label LIKE '%DROP%'")[0]['l'])
    f.track([ev('button_click', '/', e='="x"', l='=HYPERLINK("http://evil.example","click")')])
    check('invalid element id dropped', count('activity_events', "name='button_click' AND element_id IS NULL AND element_label LIKE '=HYPER%'") == 1)
    # spoofed forwarding headers
    sp = Client(); sp.accept()
    sp.track([ev('page_view', '/spoof-a')], headers={'X-Forwarded-For': '6.6.6.6', 'X-Real-IP': '7.7.7.7'})
    ip1 = q("SELECT ip_value, ip_kind, ip_public FROM activity_sessions WHERE landing_path='/spoof-a'")[0]
    check('spoofed X-Forwarded-For ignored without trusted proxy', ip1['ip_value'] == '127.0.0.0/24' and ip1['ip_kind'] == 'truncated', str(ip1))
    check('private/loopback address flagged non-public', ip1['ip_public'] == 0)
    settings_post(admin, trusted_proxies='127.0.0.1')
    sp2 = Client(); sp2.accept()
    sp2.track([ev('page_view', '/spoof-b')], headers={'X-Forwarded-For': '6.6.6.6, 203.0.113.9'})
    ip2 = q("SELECT ip_value, ip_public FROM activity_sessions WHERE landing_path='/spoof-b'")[0]
    check('trusted proxy: rightmost untrusted hop is used (client cannot prepend)', ip2['ip_value'] == '203.0.113.0/24' and ip2['ip_public'] == 1, str(ip2))
    sp3 = Client(); sp3.accept()
    sp3.track([ev('page_view', '/spoof-c')], headers={'X-Forwarded-For': 'not-an-ip, <script>'})
    ip3 = q("SELECT ip_value FROM activity_sessions WHERE landing_path='/spoof-c'")[0]
    check('garbage forwarding header falls back to the connecting address', ip3['ip_value'] == '127.0.0.0/24', str(ip3))
    settings_post(admin, trusted_proxies='', ip_mode='keyed_hash')
    sp4 = Client(); sp4.accept(); sp4.track([ev('page_view', '/ip-hash')])
    h4 = q("SELECT ip_value, ip_kind FROM activity_sessions WHERE landing_path='/ip-hash'")[0]
    check('keyed_hash mode stores a code, never the address', h4['ip_kind'] == 'hash' and re.fullmatch(r'[a-f0-9]{24}', h4['ip_value'] or '') and '127' not in h4['ip_value'])
    settings_post(admin, ip_mode='none')
    sp5 = Client(); sp5.accept(); sp5.track([ev('page_view', '/ip-none')])
    check('ip_mode none stores no address', q("SELECT ip_value, ip_kind FROM activity_sessions WHERE landing_path='/ip-none'")[0] == {'ip_value': None, 'ip_kind': 'none'})
    settings_post(admin, ip_mode='truncated')
    # rate limit
    settings_post(admin, rate_limit_per_min=10)
    rl = Client(); rl.accept()
    codes = [rl.track([ev('button_click', '/', e='rl')])[0] for _ in range(14)]
    check('rate limit returns 429 after the configured limit', 429 in codes and codes.count(429) >= 3, str(codes))
    settings_post(admin, rate_limit_per_min=2000)

    # ---------------------------------------------------------------- F. dashboard reads real data
    print('--- F. dashboard numbers match the database')
    s, dash, _ = admin.get('/admin/activity/?range=today')
    db_pv = count('activity_events', "name='page_view' AND is_test=0")
    db_sess = count('activity_sessions', 'is_test=0')
    db_vis = q('SELECT COUNT(DISTINCT visitor_uid) n FROM activity_sessions')[0]['n']
    check('page views KPI equals database count', kpi(dash, 'Page views') == str(db_pv), f"{kpi(dash, 'Page views')} vs {db_pv}")
    check('sessions KPI equals database count', kpi(dash, 'Sessions') == str(db_sess), f"{kpi(dash, 'Sessions')} vs {db_sess}")
    check('visitors KPI = distinct browsers', kpi(dash, 'Visitors') == str(db_vis), f"{kpi(dash, 'Visitors')} vs {db_vis}")
    check('dashboard shows device split consistent with sessions', 'desktop' in dash and 'mobile' in dash and 'tablet' in dash)
    check('top pages list shows event pages grouped', '/events/{event}' in dash)
    check('stored XSS text is escaped on the dashboard', '<script>alert(1)</script>' not in dash and ('&lt;script&gt;' in dash or 'DROP TABLE' not in dash))
    s, logp, _ = admin.get('/admin/activity/log.php?range=today')
    check('activity log escapes stored markup', '<script>alert(1)' not in logp and s == 200)
    s, yest, _ = admin.get('/admin/activity/?range=yesterday')
    check('yesterday filter excludes today\'s data', kpi(yest, 'Page views') == '0' and 'Nothing here yet' in yest)
    s, bad, _ = admin.get('/admin/activity/?range=custom&from=2026-99-99&to=nonsense')
    check('invalid custom range falls back safely', s == 200 and 'Today' in bad)
    today = datetime.datetime.now(ZoneInfo('Africa/Nairobi')).strftime('%Y-%m-%d')  # the site's own calendar day
    s, cus, _ = admin.get(f'/admin/activity/?range=custom&from={today}&to={today}')
    check('custom range for today equals today preset', kpi(cus, 'Page views') == kpi(dash, 'Page views'))
    s, ev1, _ = admin.get('/admin/activity/events.php?range=today')
    check('event interest lists real event titles with views', 'DevFest Nairobi 2026' in ev1 and s == 200)
    s, jr, _ = admin.get('/admin/activity/journeys.php?range=today')
    check('journeys page renders entry/exit/paths', s == 200 and 'Entry pages' in jr and 'Most common paths' in jr)
    s, lv, _ = admin.get('/admin/activity/live.php')
    check('live page renders and explains active vs expired', s == 200 and 'counts as <strong>active</strong>' in lv)
    s, api, _ = admin.get('/admin/activity/api.php')
    j = json.loads(api)
    check('live API returns active sessions and feed (owner)', j['ok'] and j['active'] >= 5 and len(j['events']) > 0, f"active={j.get('active')} events={len(j.get('events', []))}")
    check('live API exposes no IP or full visitor id', all(set(sx) .isdisjoint({'ip', 'ip_value', 'visitor_uid'}) for sx in j['sessions']) and all(len(sx['visitor']) == 8 for sx in j['sessions']))
    s, p2, _ = admin.get('/admin/activity/log.php?range=today&page=2')
    s1, p1, _ = admin.get('/admin/activity/log.php?range=today&page=1')
    check('log pagination works', s == 200 and s1 == 200 and 'Page 2 of' in p2 and 'Page 1 of' in p1)
    s, p1f, _ = admin.get('/admin/activity/log.php?range=today&name=page_view')
    check('log filter by action', s == 200 and 'button_click' not in re.sub(r'<option[^>]*>[^<]*</option>', '', p1f))

    # ---------------------------------------------------------------- G. conversions
    print('--- G. checkout, purchase, attribution')
    c = Client(); c.accept()
    c.track([ev('page_view', '/events/devfest-nairobi-2026')], ctx={'utm_source': 'Newsletter', 'utm_medium': 'EMAIL', 'utm_campaign': 'Oct Promo!!', 'utm_content': '<script>' + 'z' * 100, 'ref': ''})
    camp = q("SELECT utm_source, utm_medium, utm_campaign, utm_content FROM activity_sessions WHERE utm_source IS NOT NULL")
    check('utm values validated, lowercased and length-capped', len(camp) == 1 and camp[0]['utm_source'] == 'newsletter' and camp[0]['utm_medium'] == 'email' and camp[0]['utm_campaign'] == 'oct promo' and len(camp[0]['utm_content']) <= 40 and '<' not in camp[0]['utm_content'], str(camp))
    check('email medium classifies session as email traffic', q("SELECT referrer_type FROM activity_sessions WHERE utm_source='newsletter'")[0]['referrer_type'] == 'email')
    c.login('demo.customer@ticketskenya.local')
    page_s, page, _ = c.get('/events/devfest-nairobi-2026')
    tt = q('SELECT id, price_kes FROM ticket_types WHERE event_id=? ORDER BY price_kes', dev['id'])
    tok = re.search(r'name="_csrf" value="([a-f0-9]+)"', page).group(1)
    orders0 = count('orders')
    s, _, h = c.req('/events/devfest-nairobi-2026', raw=urllib.parse.urlencode({'_csrf': tok, f'qty[{tt[0]["id"]}]': '2'}), headers={'Content-Type': 'application/x-www-form-urlencoded'})
    check('real order creation still works (302 to order page)', s == 302 and '/tickets/order.php' in h.get('Location', ''), f'{s} {h.get("Location")}')
    order = q('SELECT * FROM orders ORDER BY id DESC LIMIT 1')[0]
    cs_ev = q("SELECT * FROM activity_events WHERE name='checkout_started'")
    check('checkout_started recorded from the real order (server-side)', count('orders') == orders0 + 1 and len(cs_ev) == 1 and cs_ev[0]['order_id'] == order['id'] and cs_ev[0]['value_num'] == order['total_kes'] and cs_ev[0]['source'] == 'server', str(cs_ev))
    lnk = q('SELECT * FROM activity_order_links WHERE order_id=?', order['id'])
    check('order linked to the tracked session with campaign', len(lnk) == 1 and lnk[0]['utm_campaign'] == 'oct promo' and lnk[0]['attribution'] == 'session')
    check('checkout event linked to the event id', cs_ev[0]['event_ref_id'] == dev['id'])
    check('pending order is NOT a purchase', count('activity_events', "name='purchase_completed'") == 0)
    s, d1, _ = admin.get('/admin/activity/?range=today')
    check('dashboard: 1 checkout start, 0 purchases, 0 revenue', kpi(d1, 'Checkout starts') == '1' and kpi(d1, 'Purchases') == '0' and kpi(d1, 'Revenue') == 'KES 0')
    check('dashboard orders panel shows 1 open order', 'Still open' in d1)
    # an untracked order (no consent) must still appear in the orders picture
    nc = Client(); nc.login('demo.customer@ticketskenya.local')
    tok2 = re.search(r'name="_csrf" value="([a-f0-9]+)"', nc.get('/events/devfest-nairobi-2026')[1]).group(1)
    nc.req('/events/devfest-nairobi-2026', raw=urllib.parse.urlencode({'_csrf': tok2, f'qty[{tt[0]["id"]}]': '1'}), headers={'Content-Type': 'application/x-www-form-urlencoded'})
    check('order without consent creates NO activity rows', count('activity_events', "name='checkout_started'") == 1 and count('orders') == orders0 + 2)
    s, d2, _ = admin.get('/admin/activity/?range=today')
    check('orders panel still counts the untracked order', re.search(r'<td>2</td><td>0</td><td>2</td>', d2) is not None, 'orders=2 paid=0 open=2')
    # now verify payment server-side (simulated: set the order fields the payment layer would set)
    x("UPDATE orders SET status='paid', payment_status='paid', paid_at=datetime('now') WHERE id=?", order['id'])
    s, d3, _ = admin.get('/admin/activity/?range=today')
    check('verified paid order becomes exactly one purchase event', count('activity_events', "name='purchase_completed'") == 1)
    check('purchase value and revenue equal the order total', kpi(d3, 'Revenue') == f"KES {order['total_kes']:,}" and kpi(d3, 'Purchases') == '1', f"{kpi(d3, 'Revenue')} vs {order['total_kes']}")
    admin.get('/admin/activity/?range=today'); admin.get('/admin/activity/events.php'); admin.get('/admin/activity/campaigns.php')
    check('repeated dashboard loads do not duplicate purchases (idempotent)', count('activity_events', "name='purchase_completed'") == 1)
    check('revenue reconciles with the orders table', 'Analytics purchases match the orders table' in d3)
    check('conversion rate uses sessions with a verified purchase', kpi(d3, 'Conversion rate') not in (None, '0%'), str(kpi(d3, 'Conversion rate')))
    s, cp, _ = admin.get('/admin/activity/campaigns.php?range=today')
    check('campaign report attributes checkout, purchase and revenue', 'oct promo' in cp and f"KES {order['total_kes']:,}" in cp and s == 200)
    s, evi, _ = admin.get('/admin/activity/events.php?range=today')
    check('event interest shows checkout and purchase for that event', 'DevFest Nairobi 2026' in evi and f"KES {order['total_kes']:,}" in evi)
    # forged purchase after a real one changes nothing
    c.track([ev('purchase_completed', '/', order_id=order['id'], value_num=1)])
    check('forged purchase for a real order id is ignored', count('activity_events', "name='purchase_completed'") == 1)

    # ---------------------------------------------------------------- H. exports
    print('--- H. exports')
    tk = admin.csrf('/admin/activity/data.php')
    s, csv, h = admin.req('/admin/activity/export.php', {'_csrf': tk, 'type': 'summary', 'range': 'today'})
    check('owner can export summary CSV', s == 200 and 'text/csv' in h.get('Content-Type', '') and 'metric,value' in csv and f'page_views,{kpi(d3, "Page views")}' in csv.replace('\r', ''))
    s, _, _ = admin.req('/admin/activity/export.php', {'type': 'summary'})
    check('export without CSRF token refused', s == 403)
    s, _, _ = cust.req('/admin/activity/export.php', {'_csrf': cust.csrf('/'), 'type': 'summary'})
    check('customer cannot export', s == 403)
    s, _, _ = admin.req('/admin/activity/export.php', {'_csrf': tk, 'type': 'nope'})
    check('unknown export type rejected', s == 400)
    s, ecsv, _ = admin.req('/admin/activity/export.php', {'_csrf': tk, 'type': 'events', 'range': 'today'})
    lines = ecsv.replace('\r', '').split('\n')
    check('activity-log export has expected rows', len([l for l in lines[1:] if l]) >= count('activity_events', "is_test=0") - 1)
    check('CSV formula injection neutralised', "'=HYPERLINK" in ecsv and ',=HYPERLINK' not in ecsv)
    check('export contains no visitor ids, IP values or search text by default', v.cookie('sa_vid') not in ecsv and '127.0.0.0' not in ecsv and 'rock concerts' not in ecsv)
    s, ecsv2, _ = admin.req('/admin/activity/export.php', {'_csrf': tk, 'type': 'events', 'range': 'today', 'include_queries': '1'})
    check('search text appears only when explicitly included', 'rock concerts' in ecsv2 and 'search_text' in ecsv2)
    s, eo, _ = admin.req('/admin/activity/export.php', {'_csrf': tk, 'type': 'events', 'range': 'yesterday'})
    check('export respects the selected period', len([l for l in eo.replace('\r', '').split('\n') if l]) == 1)
    check('exports are audited', count('activity_audit_logs', "action='export'") >= 4)
    settings_post(admin, exports_enabled=0)
    sx, _, _ = admin.req('/admin/activity/export.php', {'_csrf': tk, 'type': 'summary'})
    check('exports can be switched off', sx == 403)
    settings_post(admin, exports_enabled=1)
    check('exports can be switched back on', current_settings()['exports_enabled'] == '1')

    # ---------------------------------------------------------------- I. settings
    print('--- I. settings, audit, switches')
    s, body, _ = settings_post(admin, retention_events_days=3)
    check('invalid setting rejected with a message and nothing saved', 'whole number from 7' in body and current_settings()['retention_events_days'] == '90')
    a0 = count('activity_audit_logs', "action='settings_changed'")
    s, body, _ = settings_post(admin, retention_events_days=120)
    check('valid setting saved and audited', current_settings()['retention_events_days'] == '120' and count('activity_audit_logs', "action='settings_changed'") == a0 + 1)
    settings_post(admin, retention_events_days=90)
    s, _, _ = admin.req('/admin/activity/settings.php', {'tracking_enabled': '0'})
    check('settings POST without CSRF refused', s == 403)
    s, body, _ = settings_post(admin, trusted_proxies='999.1.1.1')
    check('bad proxy address rejected', 'Not an IP address' in body)
    s, body, _ = settings_post(admin, excluded_paths='admin')
    check('bad excluded path rejected', 'Bad path' in body)
    # category switch
    settings_post(admin, enabled_categories='navigation,event,checkout,account,error,outbound')
    sc = Client(); sc.accept(); n0 = count('activity_events', "name='search_submitted'")
    sc.track([ev('search_submitted', '/events/', q='hidden')])
    check('disabled category (search) is not collected', count('activity_events', "name='search_submitted'") == n0)
    settings_post(admin, enabled_categories='navigation,search,event,checkout,account,error,outbound')
    sc.track([ev('search_submitted', '/events/', q='visible')])
    check('re-enabled category collects again', count('activity_events', "name='search_submitted' AND query_text='visible'") == 1)
    settings_post(admin, capture_search_terms=0)
    sc.track([ev('search_submitted', '/events/', q='private term')])
    check('search-term capture can be switched off (event kept, text dropped)', count('activity_events', "name='search_submitted' AND query_text IS NULL") == 1)
    settings_post(admin, capture_search_terms=1)
    # master switch
    settings_post(admin, tracking_enabled=0)
    n0 = count()
    sc.track([ev('page_view', '/off')])
    sh, offhome, _ = Client().get('/')
    check('tracking off: nothing stored and no banner/script; site still works', count() == n0 and sh == 200 and 'activity.js' not in offhome and 'saConsent' not in offhome)
    s, _, _ = Client().get('/events/')
    check('events listing still works with tracking off', s == 200)
    settings_post(admin, tracking_enabled=1)
    # test traffic token
    x("INSERT OR REPLACE INTO activity_settings (k,v,updated_at) VALUES ('test_token','tok-123','2026-01-01 00:00:00')")
    tt_c = Client(); tt_c.accept(); tt_c.track([ev('page_view', '/synthetic')], headers={'X-Activity-Test': 'tok-123'})
    check('test traffic is flagged is_test', count('activity_events', "path='/synthetic' AND is_test=1") == 1)
    s, d4, _ = admin.get('/admin/activity/?range=today')
    s, d5, _ = admin.get('/admin/activity/?range=today&include_test=1')
    check('test traffic excluded by default, includable on request', int(kpi(d5, 'Page views')) == int(kpi(d4, 'Page views')) + 1)
    x("UPDATE activity_settings SET v='' WHERE k='test_token'")
    # staff exclusion
    st_c = Client(); st_c.accept(); st_c.login('demo.admin@ticketskenya.local'); n0 = count()
    st_c.track([ev('page_view', '/staff-page')])
    check('logged-in admins are not tracked (exclude_admins)', count() == n0)
    s, adminhome, _ = admin.get('/')
    check('no banner shown to staff', 'saConsent' not in adminhome)

    # ---------------------------------------------------------------- J. audiences
    print('--- J. audiences')
    s, au, _ = admin.get('/admin/activity/audiences.php')
    check('audiences page renders with the consent explanation', s == 200 and 'latest privacy choice includes marketing' in au)
    mk = Client(); mk.accept(marketing=True)
    mk.track([ev('page_view', '/events/devfest-nairobi-2026')])
    ta = admin.csrf('/admin/activity/audiences.php')
    admin.req('/admin/activity/audiences.php', {'_csrf': ta, 'name': 'Tech fans', 'type': 'category_interest', 'category': dev['category'], 'days': '30'})
    check('audience saved as a definition only', count('activity_audiences') == 1 and count('activity_audit_logs', "action='audience_created'") == 1)
    s, au, _ = admin.get('/admin/activity/audiences.php')
    check('audience size is 0 while collection is off', 'Audience collection is switched off' in au)
    settings_post(admin, audiences_enabled=1)
    s, au, _ = admin.get('/admin/activity/audiences.php')
    size = lambda html: (re.search(r'<td>Tech fans</td><td>[^<]*</td><td>[^<]*</td><td>([^<]*)</td>', html) or [None, None])[1]
    check('audience counts only marketing-consented browsers, small groups masked', size(au) == 'fewer than 5', str(size(au)))
    mk_vid = mk.cookie('sa_vid')
    mk.req('/activity/consent.php', {'_csrf': mk.csrf('/'), 'decision': 'custom', 'analytics': '1', 'marketing': '0'}, {'Accept': 'application/json'})
    from_est = q("SELECT COUNT(DISTINCT visitor_uid) n FROM activity_events WHERE visitor_uid=?", mk_vid)[0]['n']
    s, au, _ = admin.get('/admin/activity/audiences.php')
    check('withdrawing marketing consent suppresses the visitor from the audience', size(au) == '0' and from_est == 1, str(size(au)))
    s, _, _ = admin.req('/admin/activity/audiences.php', {'name': 'x', 'type': 'campaign', 'campaign': 'y'})
    check('audience creation without CSRF refused', s == 403 and count('activity_audiences') == 1)
    settings_post(admin, audiences_enabled=0)

    # ---------------------------------------------------------------- K. withdrawal, erasure, retention
    print('--- K. withdrawal, erasure, retention')
    w = Client(); w.accept(); w.track([ev('page_view', '/withdraw-1')])
    n_w = count('activity_events', "path='/withdraw-1'")
    w.req('/activity/consent.php', {'_csrf': w.csrf('/'), 'decision': 'reject'}, {'Accept': 'application/json'})
    n0 = count()
    st_, _, _ = w.track([ev('page_view', '/withdraw-2')])
    check('after withdrawal nothing more is stored', n_w == 1 and count() == n0 and count('activity_events', "path='/withdraw-2'") == 0)
    check('withdrawal clears the tracking cookies', not w.cookie('sa_vid') and not w.cookie('sa_sid'))
    check('withdrawal recorded in the consent log', count('activity_consent', "analytics=0 AND action='banner_reject'") >= 1)
    check('banner is not shown again after a choice', 'saConsent' not in w.get('/')[1])
    e = Client(); e.accept(); e.track([ev('page_view', '/erase-me'), ev('button_click', '/erase-me', e='b')])
    orders_n = count('orders'); paid_n = count('orders', "status='paid'")
    evid = e.cookie('sa_vid'); other = count('activity_events', 'visitor_uid<>?', evid)
    pe, pbody, _ = e.get('/privacy-settings.php')
    s, _, _ = e.req('/activity/consent.php', {'_csrf': e.csrf('/privacy-settings.php'), 'decision': 'erase'})
    check('visitor can erase their own analytics data', count('activity_events', 'visitor_uid=?', evid) == 0 and count('activity_sessions', 'visitor_uid=?', evid) == 0 and count('activity_events', 'visitor_uid<>?', evid) == other)
    check('erasure leaves orders and payments untouched', count('orders') == orders_n and count('orders', "status='paid'") == paid_n)
    # retention
    x("INSERT INTO activity_sessions (session_uid, visitor_uid, project, started_at, last_seen_at, landing_path, ip_value, ip_kind, consent_analytics) VALUES ('old1','%s','ticketskenya',datetime('now','-200 days'),datetime('now','-200 days'),'/old','203.0.113.0/24','truncated',1)" % ('a' * 32))
    old_sid = q("SELECT id FROM activity_sessions WHERE session_uid='old1'")[0]['id']
    x("INSERT INTO activity_events (event_uid,project,session_id,visitor_uid,occurred_at,name,category,path,source) VALUES ('old-e1','ticketskenya',?,?,datetime('now','-200 days'),'page_view','navigation','/old','client')", old_sid, 'a' * 32)
    x("INSERT INTO activity_events (event_uid,project,session_id,visitor_uid,occurred_at,name,category,path,source) VALUES ('mid-e1','ticketskenya',?,?,datetime('now','-45 days'),'page_view','navigation','/mid','client')", old_sid, 'a' * 32)
    x("INSERT INTO activity_sessions (session_uid, visitor_uid, project, started_at, last_seen_at, landing_path, consent_analytics) VALUES ('old2','%s','ticketskenya',datetime('now','-400 days'),datetime('now','-400 days'),'/older',1)" % ('b' * 32))
    recent = count('activity_events', "occurred_at > datetime('now','-1 day')")
    users_n, tickets_n, orders_n2 = count('users'), count('tickets'), count('orders')
    td = admin.csrf('/admin/activity/data.php')
    s, rb, _ = admin.req('/admin/activity/data.php', {'_csrf': td, 'do': 'retention'})
    check('retention run reports success and says financial data untouched', s == 200 and 'Retention applied' in rb and 'not touched' in rb)
    check('events past retention deleted, newer kept', count('activity_events', "event_uid='old-e1'") == 0 and count('activity_events', "event_uid='mid-e1'") == 1 and count('activity_events', "occurred_at > datetime('now','-1 day')") == recent)
    o1 = q("SELECT ip_value, ip_kind, anonymised_at FROM activity_sessions WHERE session_uid='old1'")
    check('IP value past its retention is anonymised (session itself kept)', len(o1) == 1 and o1[0]['ip_value'] is None and o1[0]['ip_kind'] == 'none' and o1[0]['anonymised_at'])
    ipv = q("SELECT ip_value FROM activity_sessions WHERE ip_value IS NOT NULL AND started_at < datetime('now','-31 days')")
    check('no stored IP value older than the IP retention period remains', ipv == [])
    check('sessions past their retention are deleted', count('activity_sessions', "session_uid='old2'") == 0 and count('activity_sessions', "session_uid='old1'") == 1)
    check('retention never touches orders, tickets, users', (count('users'), count('tickets'), count('orders')) == (users_n, tickets_n, orders_n2))
    s, rb, _ = admin.req('/admin/activity/data.php', {'do': 'retention'})
    check('retention endpoint refuses POST without CSRF', s == 403)
    s, rb, _ = cust.req('/admin/activity/data.php', {'_csrf': cust.csrf('/'), 'do': 'retention'})
    check('customer cannot run retention or erase', s == 403)
    uid_row = q("SELECT user_id, visitor_uid FROM activity_sessions WHERE user_id IS NOT NULL LIMIT 1")
    if uid_row:
        s, rb, _ = admin.req('/admin/activity/data.php', {'_csrf': td, 'do': 'erase_user', 'user_id': str(uid_row[0]['user_id'])})
        check('owner can erase analytics linked to an account; orders kept', 'erased' in rb and count('orders') == orders_n2)
    else:
        check('account erasure path (no logged-in tracked session in this run)', True, 'skipped: none linked')
    check('audit log has settings, export, retention, erasure actions', all(count('activity_audit_logs', f"action='{a}'") >= 1 for a in ['settings_changed', 'export', 'retention_run', 'visitor_erased']))
    s, ad, _ = admin.get('/admin/activity/data.php')
    check('audit log visible to owner only', s == 200 and 'Restricted audit log' in ad)
    # IP page gating
    s, ipb, _ = admin.get('/admin/activity/ips.php')
    check('IP view is off by default (403)', s == 403)
    settings_post(admin, ip_view_enabled=1)
    s, ipb, _ = admin.get('/admin/activity/ips.php')
    check('IP view works for the owner when enabled and is audited', s == 200 and count('activity_audit_logs', "action='ip_view'") >= 1)
    s, ipb, _ = cust.get('/admin/activity/ips.php')
    check('customer still cannot see IP page', s == 403)
    settings_post(admin, ip_view_enabled=0)
    for page in ['/', '/events/', '/events/devfest-nairobi-2026', '/about.php', '/contact.php', '/privacy-settings.php', '/cookies.php']:
        s, body, _ = Client().get(page)
        if s != 200 or 'Fatal error' in body or 'Warning:' in body:
            check('public page clean: ' + page, False, str(s))
            break
    else:
        check('public pages render without PHP warnings', True)
    check('cookie policy now describes the optional cookies', 'sa_vid' in Client().get('/cookies.php')[1])

    print('--- L. extra security checks')
    r1 = Client(); tok = r1.csrf('/')
    s, _, h = r1.req('/activity/consent.php', {'_csrf': tok, 'decision': 'reject', 'next': '//evil.example/phish'})
    check('consent redirect cannot be pointed at another site', s == 302 and h.get('Location', '') in ('/', '') or h.get('Location', '').startswith('/') and not h.get('Location', '').startswith('//'), h.get('Location', ''))
    s, _, h = r1.req('/activity/consent.php', {'_csrf': tok, 'decision': 'accept', 'next': 'https://evil.example'}, {'Accept': 'application/json'})
    cookies = [v for k, v in h.items() if k.lower() == 'set-cookie']
    s2, _, h2 = Client().req('/activity/consent.php', {'_csrf': Client().csrf('/'), 'decision': 'accept'}, {'Accept': 'application/json'})
    check('consent decision rejects unknown values', Client().req('/activity/consent.php', {'_csrf': r1.csrf('/'), 'decision': 'hack'})[0] in (400, 403))
    jar = ' '.join(str(c) for c in r1.cj)
    raw_sc = []
    import http.client
    conn = http.client.HTTPConnection(B.split('//')[1]); t0 = Client(); t0tok = t0.csrf('/')
    conn.request('POST', '/activity/consent.php', body=urllib.parse.urlencode({'_csrf': t0tok, 'decision': 'accept'}), headers={'Cookie': 'tk_session=' + (t0.cookie('tk_session') or ''), 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'User-Agent': UA_DESK})
    resp = conn.getresponse(); resp.read()
    sc = [v for k, v in resp.getheaders() if k.lower() == 'set-cookie']
    vid = [c for c in sc if c.startswith('sa_vid=')]
    check('tracking cookies are HttpOnly and SameSite=Lax', bool(vid) and all('httponly' in c.lower() and 'samesite=lax' in c.lower() for c in sc if c.startswith(('sa_vid', 'sa_consent'))), str(sc)[:200])
    check('consent cookie carries no personal data', all('@' not in c for c in sc))
    payload = '"><img src=x onerror=alert(1)>'
    for url in ['/admin/activity/?range=' + urllib.parse.quote(payload), '/admin/activity/?range=custom&from=' + urllib.parse.quote(payload) + '&to=' + urllib.parse.quote(payload),
                '/admin/activity/log.php?name=' + urllib.parse.quote(payload) + '&page=' + urllib.parse.quote(payload), '/admin/activity/events.php?include_test=' + urllib.parse.quote(payload)]:
        s, body, _ = admin.get(url)
        check('reflected XSS payload not echoed raw: ' + url[:40], s == 200 and payload not in body and '<img src=x onerror' not in body)
    s, body, _ = admin.get('/admin/activity/log.php?page=999999&range=7d')
    check('absurd page number handled without error', s == 200 and 'Fatal' not in body)
    s, body, _ = admin.get('/admin/activity/log.php?page=-5&range=7d')
    check('negative page number handled without error', s == 200 and 'Fatal' not in body)
    s, body, _ = admin.get('/admin/activity/log.php?range=custom&from=1900-01-01&to=2999-12-31')
    check('huge custom range falls back safely', s == 200 and 'Fatal' not in body)
    dmp = str(q('SELECT * FROM activity_events')) + str(q('SELECT * FROM activity_sessions')) + str(q('SELECT * FROM activity_audit_logs'))
    check('no cookie values, session tokens or passwords found in analytics tables', 'DemoPass123' not in dmp and 'tk_session' not in dmp and 'hunter2' not in dmp)

    print('--- M. activity-over-time chart')
    def chart_rows(html):
        blk = html.split('<details class="sa-details">')[1].split('</details>')[0] if '<details class="sa-details">' in html else ''
        return [[c for c in re.findall(r'<td>([^<]*)</td>', r)] for r in re.findall(r'<tr>(.*?)</tr>', blk) if '<td>' in r]
    s, t1, _ = admin.get('/admin/activity/?range=today')
    rows1 = chart_rows(t1)
    check('single-day chart is hourly (24 buckets) and drawn as SVG', len(rows1) == 24 and '<svg class="sa-chart"' in t1 and 'role="img"' in t1, str(len(rows1)))
    check('hourly chart totals equal the Sessions and Page views KPIs', sum(int(r[1]) for r in rows1) == int(kpi(t1, 'Sessions')) and sum(int(r[2]) for r in rows1) == int(kpi(t1, 'Page views')), f"{sum(int(r[1]) for r in rows1)}/{kpi(t1, 'Sessions')} {sum(int(r[2]) for r in rows1)}/{kpi(t1, 'Page views')}")
    check('chart checkout/purchase columns equal the KPIs', sum(int(r[3]) for r in rows1) == int(kpi(t1, 'Checkout starts')) and sum(int(r[4]) for r in rows1) == int(kpi(t1, 'Purchases')))
    s, t7, _ = admin.get('/admin/activity/?range=7d')
    rows7 = chart_rows(t7)
    check('7-day chart has 7 daily buckets that add up to the KPIs', len(rows7) == 7 and sum(int(r[2]) for r in rows7) == int(kpi(t7, 'Page views')) and sum(int(r[1]) for r in rows7) == int(kpi(t7, 'Sessions')), str(len(rows7)))
    # known rows three days ago at 10:30 Nairobi (= 07:30 UTC) and 23:30 Nairobi the same day (= 20:30 UTC)
    d3 = datetime.datetime.now(ZoneInfo('Africa/Nairobi')) - datetime.timedelta(days=3)
    day_utc = d3.astimezone(ZoneInfo('UTC')).strftime('%Y-%m-%d')
    lab = d3.strftime('%-d %b')
    x("INSERT INTO activity_sessions (session_uid, visitor_uid, project, started_at, last_seen_at, landing_path, consent_analytics) VALUES ('chart1','%s','ticketskenya','%s 07:30:00','%s 07:30:00','/c',1)" % ('c' * 32, day_utc, day_utc))
    sid = q("SELECT id FROM activity_sessions WHERE session_uid='chart1'")[0]['id']
    for i, hm in enumerate(['07:30:00', '07:31:00', '20:30:00']):
        x("INSERT INTO activity_events (event_uid,project,session_id,visitor_uid,occurred_at,name,category,path,path_group,source) VALUES (?, 'ticketskenya', ?, ?, ?, 'page_view','navigation','/c','/c','client')", f'chart-e{i}', sid, 'c' * 32, f'{day_utc} {hm}')
    s, t7b, _ = admin.get('/admin/activity/?range=7d')
    rb = {r[0]: r for r in chart_rows(t7b)}
    check('known rows land in the correct local day bucket (incl. 23:30 Nairobi evening)', lab in rb and rb[lab][1] == '1' and rb[lab][2] == '3', str(rb.get(lab)))
    check('days with no activity show real zeros, not gaps', sum(1 for r in chart_rows(t7b) if r[1] == '0' and r[2] == '0') >= 3)
    s, t30, _ = admin.get('/admin/activity/?range=30d')
    check('30-day chart has 30 buckets', len(chart_rows(t30)) == 30)
    s, ty, _ = admin.get('/admin/activity/?range=yesterday')
    check('empty period shows the empty state instead of a blank chart', '<svg class="sa-chart"' not in ty and 'Nothing here yet' in ty)
    check('chart labels are escaped and no external script/library is used', '<script' not in t7b.split('Activity over time')[1].split('Most viewed pages')[0])

    print(f'\nRESULT: {passed} passed, {len(failed)} failed')
    if failed:
        print('FAILED:', *failed, sep='\n  - ')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
