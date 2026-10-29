/* Owner Live page: polls the owner-only JSON API. Uses textContent only, so stored text can never run as HTML. */
(function () {
  'use strict';
  var box = document.getElementById('saLive'); if (!box) return;
  var api = box.getAttribute('data-api'), every = Math.max(5, parseInt(box.getAttribute('data-every'), 10) || 15) * 1000;
  var sess = document.getElementById('saSess'), feed = document.getElementById('saFeed'), kpi = document.querySelector('.sa-kpi strong');
  function row(tbody, cells) {
    var tr = document.createElement('tr');
    cells.forEach(function (c) { var td = document.createElement('td'); td.textContent = c == null || c === '' ? '—' : String(c); tr.appendChild(td); });
    tbody.appendChild(tr);
  }
  function load() {
    fetch(api, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (j) {
        if (kpi) kpi.textContent = j.active;
        sess.textContent = '';
        if (!j.sessions.length) row(sess, ['No active sessions right now.', '', '', '', '', '', '', '', '', '']);
        j.sessions.forEach(function (s) { row(sess, [s.visitor, s.started, s.last_seen, s.page, s.source, s.device, (s.browser + ' / ' + s.os), s.screen, s.logged_in, s.actions]); });
        feed.textContent = '';
        if (!j.events.length) row(feed, ['No activity recorded yet.', '', '', '', '', '']);
        j.events.forEach(function (e) { row(feed, [e.time, e.name, e.page, e.element, e.device, e.visitor]); });
      }).catch(function () { if (kpi) kpi.textContent = '?'; });
  }
  load(); setInterval(function () { if (!document.hidden) load(); }, every);
})();
