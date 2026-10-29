/* TicketsKenya Site Activity tracker. First-party, no libraries. Sends nothing unless data-state="granted".
 * Captures: page views, link/button clicks (stable ids, short labels), search submits, listing filter changes,
 * ticket quantity changes, account-form submits (no field values), JS errors. Never reads input values except
 * the search box and whitelisted filter fields. Failures are swallowed so the page keeps working. */
(function () {
  'use strict';
  if (window.__saTracker) return;           // never attach twice
  window.__saTracker = true;
  var tag = document.currentScript || document.querySelector('script[data-endpoint][src*="activity.js"]');
  if (!tag) return;
  var endpoint = tag.getAttribute('data-endpoint'), consentEp = tag.getAttribute('data-consent-endpoint');
  var csrf = tag.getAttribute('data-csrf') || '', state = tag.getAttribute('data-state') || 'unset';
  var excluded = (tag.getAttribute('data-excluded') || '').split(',').filter(Boolean);
  var queue = [], timer = null, errCount = 0;

  function uid() {
    var a = new Uint8Array(12);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function isExcluded(p) { return excluded.some(function (x) { return p === x || p.indexOf(x.replace(/\/$/, '') + '/') === 0; }); }
  function norm(path) { return path.replace(/[?#].*$/, ''); }
  function ctx() {
    var q = new URLSearchParams(location.search), c = { ref: document.referrer ? document.referrer.split('#')[0].split('?')[0] : '', lang: navigator.language || '' };
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) { if (q.get(k)) c[k] = q.get(k).slice(0, 60); });
    c.sc = window.innerWidth < 640 ? 'small' : (window.innerWidth < 1100 ? 'medium' : 'large');
    return c;
  }
  function flush(useBeacon) {
    if (!queue.length || state !== 'granted') return;
    var batch = queue.splice(0, 20);
    var body = JSON.stringify({ events: batch, ctx: ctx() });
    try {
      if (useBeacon && navigator.sendBeacon) { navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' })); return; }
      fetch(endpoint, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', keepalive: true }).catch(function () {});
    } catch (e) { /* tracking must never break the page */ }
    if (queue.length) schedule();
  }
  function schedule() { if (!timer) timer = setTimeout(function () { timer = null; flush(false); }, 800); }
  function track(name, extra) {
    if (state !== 'granted') return;
    var p = norm(location.pathname);
    if (isExcluded(p)) return;
    var e = { n: name, id: uid(), p: p };
    for (var k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) e[k] = extra[k];
    queue.push(e); schedule();
  }

  /* ---- page views: once per document, not for bfcache restores, not for a quick repeat of the same page ---- */
  function pageView() {
    var key = 'sa_last_pv', now = Date.now(), p = norm(location.pathname), last = null;
    try { last = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) {}
    if (last && last.p === p && now - last.t < 5000) return;
    try { sessionStorage.setItem(key, JSON.stringify({ p: p, t: now })); } catch (e) {}
    track('page_view', {});
  }
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) return; });   // bfcache restore: not a new view

  /* ---- stable element ids ---- */
  function zoneOf(el) {
    if (el.closest('footer')) return 'footer';
    if (el.closest('.mobile-nav')) return 'mobile-nav';
    if (el.closest('header, .header')) return 'header';
    if (el.closest('.tk-footer-cta')) return 'cta';
    return 'page';
  }
  function slug(s) { return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); }
  function labelOf(el) {
    var l = el.getAttribute('data-track-label') || el.getAttribute('aria-label') || el.textContent || el.value || '';
    return l.replace(/\s+/g, ' ').trim().slice(0, 80);
  }
  function linkInfo(a) {
    var u; try { u = new URL(a.getAttribute('href') || '', location.href); } catch (e) { return null; }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    return { host: u.host, path: u.pathname, same: u.host === location.host };
  }

  document.addEventListener('click', function (ev) {
    try {
      var t = ev.target; if (!t || !t.closest) return;
      var el = t.closest('a[href], button, [data-track-id], input[type=button], input[type=submit]');
      if (!el) return;
      var tid = el.getAttribute('data-track-id');
      var zone = zoneOf(el);
      if (el.tagName === 'A') {
        var li = linkInfo(el); if (!li) return;
        if (!li.same) { track('outbound_link_clicked', { e: tid || ('outbound.' + slug(li.host)), l: labelOf(el), t: li.host }); return; }
        var isEvent = /^\/events\/[a-z0-9][a-z0-9-]*\/?$/.test(li.path);
        if (isEvent) { track('event_card_clicked', { e: tid || ('event-link.' + zone), l: labelOf(el), t: li.path, m: { zone: zone } }); return; }
        var nav = zone === 'header' || zone === 'footer' || zone === 'mobile-nav';
        track(nav ? 'navigation_click' : 'link_click', { e: tid || (zone + '.' + (slug(li.path) || 'home')), l: labelOf(el), t: li.path, m: { zone: zone } });
        return;
      }
      if ((el.type === 'submit') && el.form) return;   // form submits are tracked by the submit listener
      track('button_click', { e: tid || ('button.' + zone + '.' + (slug(labelOf(el)) || 'unnamed')), l: labelOf(el), m: { zone: zone } });
    } catch (e) {}
  }, true);

  var ACCOUNT = { '/auth/login.php': 'login_attempted', '/auth/register.php': 'registration_attempted', '/contact.php': 'contact_submitted' };
  document.addEventListener('submit', function (ev) {
    try {
      var f = ev.target; if (!f || !f.getAttribute) return;
      var action = norm(new URL(f.getAttribute('action') || location.pathname, location.href).pathname);
      if (ACCOUNT[action]) { track(ACCOUNT[action], { e: f.getAttribute('data-track-id') || ('form.' + slug(action)) }); return; }
      var q = f.querySelector('input[name="q"]');
      if (q && (f.getAttribute('role') === 'search' || action === '/events/' || action === '/events')) {
        track('search_submitted', { e: f.getAttribute('data-track-id') || (f.id ? 'search.' + slug(f.id) : 'search-form'), q: (q.value || '').slice(0, 80) });
      }
    } catch (e) {}
  }, true);

  var FILTERS = ['category[]', 'category', 'city', 'when', 'price', 'sort', 'from', 'to'];
  document.addEventListener('change', function (ev) {
    try {
      var el = ev.target; if (!el || !el.name) return;
      if (/^qty\[\d+\]$/.test(el.name)) { track('ticket_quantity_changed', { e: 'ticket-quantity', m: { qty: String(parseInt(el.value, 10) || 0), ticket_type: el.name.replace(/\D/g, '') } }); return; }
      var form = el.form; if (!form) return;
      var action = norm(new URL(form.getAttribute('action') || location.pathname, location.href).pathname);
      if ((action === '/events/' || action === '/events') && FILTERS.indexOf(el.name) > -1) {
        var field = el.name.replace('[]', '');
        if (el.type === 'checkbox' && !el.checked) return;
        track('filter_changed', { e: 'filter-' + field, m: { field: field, value: String(el.value || '').slice(0, 60) } });
      }
    } catch (e) {}
  }, true);

  window.addEventListener('error', function (ev) {
    if (errCount++ >= 3) return;
    track('js_error', { e: 'window-error', m: { msg: String(ev.message || 'error').slice(0, 80) } });
  });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(true); });
  window.addEventListener('pagehide', function () { flush(true); });

  /* ---- consent banner without a page reload ---- */
  var banner = document.getElementById('saConsent');
  if (banner && window.fetch) {
    banner.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-sa-decision]'); if (!b) return;
      ev.preventDefault();
      var d = b.getAttribute('data-sa-decision');
      var fd = new URLSearchParams({ _csrf: csrf, decision: d });
      fetch(consentEp, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error('x');
          banner.parentNode.removeChild(banner);
          state = j.analytics ? 'granted' : 'denied';
          if (state === 'granted') pageView();
        })
        .catch(function () { b.form && b.form.submit(); });   // fall back to the normal form post
    });
  }

  if (state === 'granted') pageView();
})();
