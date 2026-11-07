#!/usr/bin/env python3
"""
End-to-end two-factor (TOTP) login test.

Runs against a DISPOSABLE copy of the project (see DEPLOYMENT.md / tests/README-tests.md).
It temporarily sets a known TOTP secret on a test account, exercises the login flow,
then restores the previous secret. It refuses the live database unless explicitly allowed.

Usage (server already running against the copy):
  python tests/test_2fa_e2e.py --db "C:\\temp\\tk-copy\\data\\ticketskenya.sqlite"
  python tests/test_2fa_e2e.py --base http://127.0.0.1:8080 --db ... --email demo.admin@ticketskenya.local
"""
import argparse, base64, hashlib, hmac, http.cookiejar, re, sqlite3, struct, sys, time
import urllib.error, urllib.parse, urllib.request

SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'  # RFC 6238 test seed
LIVE_MARKER = 'ticketskenya' + chr(92) + 'data' + chr(92) + 'ticketskenya.sqlite'


def totp(secret, t=None):
    pad = secret.upper() + '=' * ((8 - len(secret) % 8) % 8)
    key = base64.b32decode(pad)
    counter = int((t or time.time()) // 30)
    digest = hmac.new(key, struct.pack('>Q', counter), hashlib.sha1).digest()
    offset = digest[19] & 0x0F
    value = struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF
    return str(value % 1000000).zfill(6)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def jsession(follow=True):
    jar = http.cookiejar.CookieJar()
    handlers = [urllib.request.HTTPCookieProcessor(jar)]
    if not follow:
        handlers.append(NoRedirect)
    return urllib.request.build_opener(*handlers), jar


def get(opener, base, path):
    with opener.open(base + path, timeout=10) as r:
        return r.status, r.read().decode(errors='replace')


def post(opener, base, path, data):
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(base + path, data=body, method='POST')
    try:
        with opener.open(req, timeout=10) as r:
            return r.status, r.read().decode(errors='replace'), r.getheader('Location')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(errors='replace'), e.headers.get('Location')


def csrf(html):
    m = re.search(r'name="_csrf" value="([a-f0-9]+)"', html)
    return m.group(1) if m else None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://127.0.0.1:8080')
    ap.add_argument('--db', required=True)
    ap.add_argument('--email', default='demo.admin@ticketskenya.local')
    ap.add_argument('--password', default='DemoPass123!')
    ap.add_argument('--allow-live-db', action='store_true')
    args = ap.parse_args()

    if LIVE_MARKER in args.db.lower() and not args.allow_live_db:
        print('Refusing to modify the live database. Run against a disposable copy,')
        print('or pass --allow-live-db if you really mean it.')
        return 2

    db = sqlite3.connect(args.db)
    row = db.execute('select id, totp_secret from users where email = ?', (args.email,)).fetchone()
    if not row:
        print(f'No test account {args.email} in {args.db}')
        return 2
    user_id, previous = row
    db.execute('update users set totp_secret = ? where id = ?', (SECRET, user_id))
    db.execute('delete from login_attempts')
    db.commit()

    passed = failed = 0

    def check(ok, msg):
        nonlocal passed, failed
        print(('PASS: ' if ok else 'FAIL: ') + msg)
        if ok:
            passed += 1
        else:
            failed += 1

    try:
        print('=== TOTP end-to-end (2FA) ===')

        # 2FA enabled: password alone must not authenticate.
        op, jar = jsession(follow=False)
        _, html = get(op, args.base, '/auth/login.php')
        token = csrf(html)
        check(bool(token), 'login page exposes CSRF token')
        status, html, location = post(op, args.base, '/auth/login.php',
                                      {'_csrf': token, 'email': args.email, 'password': args.password})
        check(status == 200 and 'Two-factor code' in html, 'password step shows the code prompt')
        check('/auth/logout' not in html, 'not authenticated after the password alone')

        # Valid code authenticates.
        token = csrf(html)
        status, html, location = post(op, args.base, '/auth/login.php',
                                      {'_csrf': token, 'totp_code': totp(SECRET)})
        check(status == 302 and (location or '').endswith('/tickets/'), 'valid TOTP logs in (302 -> /tickets/)')
        session = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        status, html = get(session, args.base, '/tickets/')
        check(status == 200, 'tickets page loads for the 2FA session')
        status, html = get(session, args.base, '/admin/index.php')
        check(status == 200 and 'Organiser dashboard' in html, 'admin area reachable after 2FA')

        # Wrong code is rejected and does not authenticate.
        op2, _ = jsession(follow=False)
        _, html = get(op2, args.base, '/auth/login.php')
        status, html, _ = post(op2, args.base, '/auth/login.php',
                               {'_csrf': csrf(html), 'email': args.email, 'password': args.password})
        token = csrf(html)
        status, html, _ = post(op2, args.base, '/auth/login.php', {'_csrf': token, 'totp_code': '000000'})
        check(status == 200 and 'not accepted' in html, 'wrong TOTP is rejected')
        check('/auth/logout' not in html, 'still not authenticated after a wrong code')

        # Five failures lock the pending challenge.
        op3, _ = jsession(follow=False)
        _, html = get(op3, args.base, '/auth/login.php')
        status, html, _ = post(op3, args.base, '/auth/login.php',
                               {'_csrf': csrf(html), 'email': args.email, 'password': args.password})
        token = csrf(html)
        for _ in range(6):
            status, html, _ = post(op3, args.base, '/auth/login.php', {'_csrf': token, 'totp_code': '111111'})
        check('Too many incorrect codes' in html, 'six wrong codes end the challenge')

        # Cancel link clears the pending state.
        op4, _ = jsession(follow=True)
        status, html = get(op4, args.base, '/auth/login.php?cancel=1')
        check(status == 200 and 'Log in' in html, 'cancel returns the normal login form')

        # With TOTP disabled, the old password-only flow still works.
        db.execute('update users set totp_secret = null where id = ?', (user_id,))
        db.execute('delete from login_attempts')
        db.commit()
        op5, jar5 = jsession(follow=False)
        _, html = get(op5, args.base, '/auth/login.php')
        status, html, location = post(op5, args.base, '/auth/login.php',
                                      {'_csrf': csrf(html), 'email': args.email, 'password': args.password})
        check(status == 302 and (location or '').endswith('/tickets/'), 'password-only login works when TOTP is off')
        check('Two-factor code' not in html, 'no code prompt without a secret')
    finally:
        db.execute('update users set totp_secret = ? where id = ?', (previous, user_id))
        db.execute('delete from login_attempts')
        db.commit()
        db.close()

    print(f'\n{passed} passed, {failed} failed')
    return 0 if failed == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
