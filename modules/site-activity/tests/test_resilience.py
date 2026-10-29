"""Migration safety, failure handling and performance. Run on a DISPOSABLE copy (see test_activity.py header).
Needs the TicketsKenya project root as cwd's parent; uses `php` on the PATH."""
import os, shutil, sqlite3, statistics, subprocess, sys, tempfile, time
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import test_activity as T
from test_activity import Client, ev, q, x, count, check

ROOT = Path(__file__).resolve().parents[3]


def rows(db):
    c = sqlite3.connect(db)
    out = {t: c.execute(f'SELECT COUNT(*) FROM "{t}"').fetchone()[0] for (t,) in c.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'activity_%' AND name NOT LIKE 'sqlite_%'")}
    c.close(); return out


def main():
    print('--- migration on a copy of the shipped database')
    tmp = Path(tempfile.mkdtemp()); shutil.copytree(os.environ.get('TK_SHIPPED_ROOT', ROOT), tmp / 'p', ignore=shutil.ignore_patterns('node_modules', '.git'))
    db = tmp / 'p' / 'data' / 'ticketskenya.sqlite'
    c = sqlite3.connect(db); [c.execute(f'DROP TABLE IF EXISTS {t}') for t in ['activity_settings','activity_sessions','activity_events','activity_consent','activity_audit_logs','activity_audiences','activity_order_links','activity_rate','activity_errors']]; c.commit(); c.close()
    before = rows(db)
    env = dict(os.environ, TK_DEMO_MODE='true')
    for i in (1, 2):
        r = subprocess.run(['php', 'tools/activity-maintenance.php', 'install'], cwd=tmp / 'p', env=env, capture_output=True, text=True)
        check(f'install run {i} succeeds (idempotent)', r.returncode == 0 and 'Installed' in r.stdout, r.stdout + r.stderr)
    after = rows(db)
    check('every existing table keeps exactly its rows after migration', before == after, f'{len(before)} tables')
    c = sqlite3.connect(db); tabs = {r[0] for r in c.execute("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'activity_%'")}; c.close()
    check('9 activity tables created', len(tabs) == 9, str(len(tabs)))
    r = subprocess.run(['php', 'tools/activity-maintenance.php', 'uninstall', '--yes'], cwd=tmp / 'p', env=env, capture_output=True, text=True)
    check('rollback removes only activity tables; site data unchanged', rows(db) == before and r.returncode == 0)
    c = sqlite3.connect(db); left = c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'activity_%'").fetchone()[0]; c.close()
    check('no activity objects remain after rollback', left == 0, str(left))
    shutil.rmtree(tmp, ignore_errors=True)

    print('--- failure handling: broken analytics tables must not break the website')
    v = Client(); v.accept(); v.track([ev('page_view', '/')])
    cust = Client(); cust.login('demo.customer@ticketskenya.local')
    x('ALTER TABLE activity_events RENAME COLUMN name TO name_broken')  # table exists but every insert/query now fails
    try:
        codes = {p: Client().get(p)[0] for p in ['/', '/events/', '/events/devfest-nairobi-2026', '/about.php', '/privacy-settings.php']}
        check('public pages still return 200 with the events table broken', all(c == 200 for c in codes.values()), str(codes))
        s, body, _ = v.track([ev('page_view', '/x')])
        check('tracking endpoint fails quietly (204) with no error text', s == 204 and body == '', f'{s} {body[:60]}')
        page = cust.get('/events/devfest-nairobi-2026')[1]
        import re, urllib.parse
        tok = re.search(r'name="_csrf" value="([a-f0-9]+)"', page).group(1)
        tt = q('SELECT id FROM ticket_types WHERE event_id=(SELECT id FROM events WHERE slug=?) ORDER BY price_kes', 'devfest-nairobi-2026')[0]['id']
        n0 = count('orders')
        s, _, h = cust.req('/events/devfest-nairobi-2026', raw=urllib.parse.urlencode({'_csrf': tok, f'qty[{tt}]': '1'}), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check('checkout still creates the order when analytics storage is broken', s == 302 and '/tickets/order.php' in h.get('Location', '') and count('orders') == n0 + 1, f'{s}')
        s, body, _ = Client().get('/admin/activity/')
        check('dashboard route still refuses anonymous users while broken', s == 302)
    finally:
        x('ALTER TABLE activity_events RENAME COLUMN name_broken TO name')
    check('events table repaired and tracking works again', Client().get('/')[0] == 200 and v.track([ev('page_view', '/back')])[0] == 204 and count('activity_events', "path='/back'") == 1)

    print('--- performance (median of repeated requests, same machine, PHP built-in server)')
    def med(fn, n=40):
        t = []
        for _ in range(n):
            a = time.perf_counter(); fn(); t.append((time.perf_counter() - a) * 1000)
        return statistics.median(t), max(t)
    orig = os.environ.get('TK_ORIGINAL_URL', '')
    anon = Client(); anon.get('/')
    m_new = med(lambda: anon.get('/'))
    g = Client(); g.accept(); g.get('/')
    m_granted = med(lambda: g.get('/'))
    m_collect = med(lambda: g.track([ev('button_click', '/', e='perf.test')]), 60)
    print(f'INFO home page, updated site, visitor undecided: median {m_new[0]:.1f} ms (max {m_new[1]:.0f})')
    print(f'INFO home page, updated site, analytics accepted: median {m_granted[0]:.1f} ms (max {m_granted[1]:.0f})')
    print(f'INFO tracking endpoint POST (1 event): median {m_collect[0]:.1f} ms (max {m_collect[1]:.0f})')
    if orig:
        T.B = orig
        o = Client(); o.get('/'); m_orig = med(lambda: o.get('/'))
        print(f'INFO home page, ORIGINAL site: median {m_orig[0]:.1f} ms (max {m_orig[1]:.0f})')
        check('home page overhead vs original is small (< 25 ms median)', m_new[0] - m_orig[0] < 25, f'{m_new[0] - m_orig[0]:.1f} ms')
    # The site's own bootstrap (schema check + seed) runs on every request and dominates cost, so judge the endpoint
    # against an ordinary page of the ORIGINAL site rather than an arbitrary number.
    base = m_orig[0] if orig else m_new[0]
    check('tracking endpoint costs no more than 1.5x an ordinary page request', m_collect[0] <= base * 1.5, f'{m_collect[0]:.1f} ms vs page {base:.1f} ms')
    plan = q("EXPLAIN QUERY PLAN SELECT path_group, COUNT(*) FROM activity_events WHERE name='page_view' AND occurred_at >= '2026-01-01' AND occurred_at < '2027-01-01' AND is_test=0 GROUP BY path_group")
    check('dashboard top-pages query uses an index', any('INDEX' in str(r) for r in plan), str([r['detail'] for r in plan]))
    plan2 = q("EXPLAIN QUERY PLAN SELECT COUNT(*) FROM activity_sessions WHERE started_at >= '2026-01-01' AND started_at < '2027-01-01' AND is_test=0")
    check('sessions-in-period query uses an index', any('INDEX' in str(r) for r in plan2), str([r['detail'] for r in plan2]))
    print(f'\nRESILIENCE RESULT: {T.passed} passed, {len(T.failed)} failed')
    if T.failed: print('FAILED:', *T.failed, sep='\n  - ')
    sys.exit(1 if T.failed else 0)


if __name__ == '__main__':
    main()
