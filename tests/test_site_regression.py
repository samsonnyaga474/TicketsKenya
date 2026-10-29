"""
Regression tests for the redesign. Run with the dev server up:  php -S 127.0.0.1:8080 router.php  (from public/)
Optional: set TK_SCALE_URL to a second server that uses a disposable data dir with >24 upcoming events
(see tests/README-tests.md) to exercise pagination and filters at volume.
"""
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]  # was undefined: the script crashed before this line
import os, re, sys, urllib.request, urllib.parse, http.cookiejar
B = os.environ.get('TK_URL', 'http://127.0.0.1:8080'); S = os.environ.get('TK_SCALE_URL', '')
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def get(path, base=B, data=None, method=None, opener=None):
    o = opener or urllib.request.build_opener(NoRedir)
    d = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        r = o.open(urllib.request.Request(base + path, data=d, method=method)); return r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
R = []
def chk(name, ok, info=''): R.append(bool(ok)); print(('PASS ' if ok else 'FAIL ') + name, info if not ok else '')

PUBLIC = ['/', '/events/', '/about.php', '/help.php', '/contact.php', '/organisers.php', '/pricing.php', '/privacy.php', '/terms.php', '/refunds.php', '/cookies.php', '/auth/login.php', '/auth/register.php', '/auth/forgot.php']
# 1 every page family: one h1, lang, viewport, title, description, skip link, stacking markup
for p in PUBLIC:
    s, h, hd = get(p)
    chk(f'200 {p}', s == 200, s)
    chk(f'one h1 {p}', len(re.findall(r'<h1\b', h)) == 1, len(re.findall(r'<h1\b', h)))
    chk(f'meta basics {p}', '<html lang="en">' in h and 'name="viewport"' in h and re.search(r'<title>[^<]{8,}</title>', h) and 'name="description"' in h)
    chk(f'skip link + main landmark {p}', 'class="skip-link"' in h and '<main id="main">' in h)
    chk(f'car-window stack markup {p}', 'class="tk-stack"' in h or p.startswith('/auth/'), 'missing tk-stack')
# 2 unique titles on public pages
titles = {}
for p in PUBLIC:
    t = re.search(r'<title>([^<]*)</title>', get(p)[1]).group(1); titles.setdefault(t, []).append(p)
chk('page titles are unique', all(len(v) == 1 for v in titles.values()), [v for v in titles.values() if len(v) > 1])
# 3 every <img> has an alt attribute; every form input has a label or aria-label
for p in PUBLIC:
    h = get(p)[1]
    chk(f'img alt present {p}', all('alt=' in m for m in re.findall(r'<img\b[^>]*>', h)))
    ids = set(re.findall(r'<label[^>]*\bfor="([^"]+)"', h))
    bad = [m for m in re.findall(r'<(?:input|select|textarea)\b[^>]*>', h) if not re.search(r'type="(hidden|submit|checkbox|radio)"', m) and 'aria-label' not in m and 'tabindex="-1"' not in m and not (re.search(r'\bid="([^"]+)"', m) and re.search(r'\bid="([^"]+)"', m).group(1) in ids)]
    # inputs wrapped by <label> (events toolbar) are labelled implicitly
    bad = [m for m in bad if p != '/events/']
    chk(f'inputs labelled {p}', not bad, bad[:2])
# 4 design rules from the brief
for p in ['/', '/events/', '/about.php']:
    h = get(p)[1]; css = get('/assets/css/site.css')[1] + get('/assets/css/main.css')[1]
    chk(f'no emoji in headings {p}', not re.search(r'<h[1-6][^>]*>[^<]*[\U0001F300-\U0001FAFF\u2600-\u27BF]', h))
chk('no purple/blue gradient or backdrop glass cards in CSS', not re.search(r'linear-gradient\([^)]*(#7c3aed|#8b5cf6|#6366f1|purple|violet)', css, re.I) and 'glass' not in css.lower())
# 5 homepage: stats and carousel markup are real
s, h, _ = get('/')
chk('reference stats have exact values + scroll count-up hooks', all(f'data-count="{n}"' in h for n in ('2500','520','350','6')) and h.count('data-count=') == 4 and 'data-count' in get('/assets/js/main.js')[1])
chk('stats expose final values to screen readers', all(x in h for x in ('class="sr-only">2500+', 'class="sr-only">520k+', 'class="sr-only">350k+', 'class="sr-only">6 countries')))
chk('stats section has no "demo" label', not re.search(r'demo|sample figures|placeholder', h.split('tk-stats')[1] if 'tk-stats' in h else '', re.I))
order_source = (ROOT/'public/tickets/order.php').read_text(encoding='utf-8')
chk('receipt contains payment totals, ticket details and email blocker', all(x in order_source for x in ('Total paid', 'Subtotal', 'Tax (not configured)', 'Location', 'Paid with', 'Ticket type(s)', 'email delivery is not configured')))
chk('carousel markup matches JS hooks', 'data-marquee' in h and 'tk-marquee-track' in h and 'data-marquee' in get('/assets/js/main.js')[1])
chk('footer present with legal links', all(x in h for x in ('/terms.php', '/privacy.php', '/refunds.php', '/cookies.php')))
chk('footer shows only the confirmed support email and no phone number', 'ticketskenyasupport@gmail.com' in h and not re.search(r'\+254|tel:', h) and 'mailto:ticketskenyasupport@gmail.com' in h)
# 6 internal link crawl: every same-site href on every page resolves (no dead links)
seen, bad = set(), []
for p in PUBLIC:
    for href in re.findall(r'href="(/[^"#]*)"', get(p)[1]):
        href = href.replace('&amp;', '&')
        if href in seen or href.startswith('/assets/'): continue
        seen.add(href); st = get(href)[0]
        if st >= 400: bad.append((p, href, st))
chk(f'internal links resolve ({len(seen)} checked)', not bad, bad[:5])
# 7 headers + cookies
s, h, hd = get('/')
chk('security headers', all(hd.get(k) for k in ('Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy')))
chk('no X-Powered-By leak', hd.get('X-Powered-By') is None, hd.get('X-Powered-By'))
sc = hd.get('Set-Cookie', ''); chk('session cookie HttpOnly + SameSite', 'HttpOnly' in sc and 'SameSite' in sc, sc)
# 8 robots + sitemap
s, rb, _ = get('/robots.txt'); chk('robots blocks private areas, allows site', 'Disallow: /admin/' in rb and 'Disallow: /payments/' in rb and 'Disallow: /\n' not in rb + '\n' and 'Sitemap:' in rb)
s, sm, hd = get('/sitemap.xml'); chk('sitemap is XML and excludes private routes', s == 200 and 'xml' in hd.get('Content-Type', '') and not re.search(r'/admin|/auth/|/tickets/|/payments/|\?', sm.split('?>', 1)[-1]))
# 9 noindex on private/duplicate pages, 404 page
for p in ['/auth/login.php', '/events/?q=a']:
    chk(f'noindex {p}', 'noindex' in get(p)[1])
s, h, _ = get('/definitely-missing'); chk('custom 404', s == 404 and '<h1' in h and 'TicketsKenya' in h, s)
# 10 payments fail closed, including direct POSTs
for p in ['/payments/start.php', '/payments/callback.php', '/payments/webhook.php', '/payments/status.php']:
    for m in ('GET', 'POST'):
        s, h, _ = get(p, data={'order_id': '1', 'status': 'paid'} if m == 'POST' else None, method=m)
        chk(f'payment route {m} {p} gives no success', s in (302, 303, 401, 403, 404, 405, 410) and not re.search(r'"status"\s*:\s*"paid"|payment (was )?successful|order (is |was )?paid', h, re.I), s)
s, h, _ = get('/events/devfest-nairobi-2026', data={'ticket': '1'}, method='POST'); chk('direct ticket purchase POST creates nothing', s in (302, 303), s)
# 11 volume tests (needs TK_SCALE_URL)
if S:
    s, h, _ = get('/events/', S); m = re.search(r'of (\d+) events', h); total = int(m.group(1)) if m else 0
    chk('listing shows more than the old 6-event cap', total > 24, total)
    chk('page 1 shows 24 cards', h.count('class="ev-card') == 24, h.count('class="ev-card'))
    chk('pagination links present', 'aria-label="Pagination"' in h and 'rel="next"' in h)
    s2, h2, _ = get('/events/?page=2', S); chk('page 2 differs from page 1', re.findall(r'/events/[a-z0-9-]+"', h) != re.findall(r'/events/[a-z0-9-]+"', h2))
    chk('out-of-range page clamps', 'Showing' in get('/events/?page=999', S)[1])
    ids = re.findall(r'id="d-(\d{4}-\d{2}-\d{2})"', h); chk('date groups chronological', ids == sorted(ids) and len(ids) > 1, ids)
    chk('cancelled/draft/past/bad-date records never listed', not re.search(r'PAST EVENT|BAD DATE|TEST EVENT 17<|TEST EVENT 23<', ''.join(get(f'/events/?page={i}', S)[1] for i in range(1, 7))))
    for q, name in [('price=free', 'free filter'), ('price=paid&city=Nairobi', 'paid+city filters combine'), ('when=weekend', 'weekend filter'), ('category%5B%5D=Comedy', 'category filter'), ('from=2026-10-25&to=2026-10-27', 'date range filter')]:
        h = get('/events/?' + q, S)[1]; chk(name + ' returns results', 'Showing' in h, q)
    chk('free filter only shows verified Free', set(re.findall(r'class="ev-price[^"]*">([^<]+)<', get('/events/?price=free', S)[1])) == {'Free'})
    chk('empty state styled', 'empty-state' in get('/events/?q=zzzzqqq', S)[1])
print(f'\nSUMMARY: {sum(R)}/{len(R)} passed'); sys.exit(0 if all(R) else 1)
