/* TicketsKenya front-end behaviour. No libraries. Each feature checks that its markup exists. */
(function () {
  'use strict';
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---- Mobile menu (dialog-style drawer, Esc closes, focus returns) ---- */
  var btn = $('#menuBtn'), nav = $('#mobileNav'), closeBtn = $('#menuClose');
  function setMenu(open) {
    if (!btn || !nav) return;
    nav.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('drawer-open', open);
    if (open) { (closeBtn || nav).focus(); } else { btn.focus(); }
  }
  if (btn && nav) {
    btn.addEventListener('click', function () { setMenu(nav.hidden); });
    if (closeBtn) closeBtn.addEventListener('click', function () { setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !nav.hidden) setMenu(false); });
    window.matchMedia('(min-width: 1100px)').addEventListener('change', function (m) { if (m.matches && !nav.hidden) setMenu(false); });
  }

  /* ---- Header turns solid after the first scroll on the home page ---- */
  var ticking = false, stacks = $$('.tk-stack');
  function onScroll() {
    if (ticking) return; ticking = true;
    requestAnimationFrame(function () {
      ticking = false;
      document.body.classList.toggle('scrolled', window.scrollY > 40);
      updateStacks();
    });
  }

  /* ---- Car-window stacking: sticky positions + progress (--p) of the section being covered ---- */
  function secsOf(stack) { return $$(':scope > .tk-st', stack); }
  function layoutStacks() {
    var vh = window.innerHeight;
    stacks.forEach(function (stack) {
      secsOf(stack).forEach(function (s) {
        var h = s.offsetHeight;
        s.style.setProperty('--stick', Math.min(0, vh - h) + 'px'); // tall sections scroll, then stick by their bottom edge
      });
    });
  }
  function updateStacks() {
    if (reduce) return;
    var vh = window.innerHeight;
    stacks.forEach(function (stack) {
      var secs = secsOf(stack);
      for (var i = 0; i < secs.length - 1; i++) {
        var r = secs[i].getBoundingClientRect();
        var top = secs[i + 1].getBoundingClientRect().top;
        // how much of the (visible part of the) section underneath is already covered; 28px is the rounded-edge overlap
        var p = Math.max(0, Math.min(1, (r.bottom - top - 28) / Math.max(120, Math.min(r.height, vh))));
        secs[i].style.setProperty('--p', p.toFixed(3));
      }
    });
  }
  if (stacks.length && !reduce) {
    layoutStacks(); updateStacks();
    window.addEventListener('resize', function () { layoutStacks(); updateStacks(); }, { passive: true });
    window.addEventListener('load', function () { layoutStacks(); updateStacks(); });
    if ('ResizeObserver' in window) {
      var ro = new ResizeObserver(function () { layoutStacks(); updateStacks(); });
      stacks.forEach(function (st) { secsOf(st).forEach(function (s) { ro.observe(s); }); });
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  var printReceipt = $('[data-print-receipt]');
  if (printReceipt) printReceipt.addEventListener('click', function () { window.print(); });

  /* ---- Accessible category card draw: discovery only, no simulated ticket prize ---- */
  $$('[data-category-deck]').forEach(function (deck) {
    var cards, card = $('[data-card]', deck), label = $('[data-card-label]', deck);
    var count = $('[data-card-count]', deck), button = $('[data-deal-card]', deck), link = $('[data-card-link]', deck);
    try { cards = JSON.parse(deck.getAttribute('data-categories') || '[]'); } catch (e) { cards = []; }
    if (!cards.length || !card || !label || !count || !button || !link) return;
    button.addEventListener('click', function () {
      var choices = cards.filter(function (item) { return item.name !== label.textContent; });
      if (!choices.length) choices = cards;
      var picked = choices[Math.floor(Math.random() * choices.length)];
      label.textContent = picked.name;
      count.textContent = picked.count + (picked.count === 1 ? ' upcoming event' : ' upcoming events');
      link.href = picked.url;
      link.hidden = false;
      card.classList.remove('is-dealt');
      if (!reduce) requestAnimationFrame(function () { card.classList.add('is-dealt'); });
      else card.classList.add('is-dealt');
    });
  });

  /* ---- Continuous carousel: clones the set once, loops by exactly one set width ---- */
  $$('[data-marquee]').forEach(function (box) {
    var track = $('.tk-marquee-track', box);
    if (!track) return;
    var items = $$(':scope > *', track);
    var setW = track.scrollWidth;
    if (!items.length || setW <= box.clientWidth + 8 || reduce) { box.classList.add('is-static'); return; } // few cards: plain row, no empty track
    items.forEach(function (n) {
      var c = n.cloneNode(true);
      c.setAttribute('aria-hidden', 'true'); c.setAttribute('inert', '');
      $$('a,button', c).forEach(function (x) { x.tabIndex = -1; });
      track.appendChild(c);
    });
    var x = 0, last = 0, paused = false, speed = +(box.getAttribute('data-speed') || 40), visible = true;
    box.addEventListener('mouseenter', function () { paused = true; });
    box.addEventListener('mouseleave', function () { paused = false; });
    box.addEventListener('focusin', function () { paused = true; });
    box.addEventListener('focusout', function () { paused = false; });
    box.addEventListener('touchstart', function () { paused = true; }, { passive: true });
    box.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 1500); }, { passive: true });
    if ('IntersectionObserver' in window) new IntersectionObserver(function (en) { visible = en[0].isIntersecting; }).observe(box);
    function frame(t) {
      var dt = last ? Math.min(64, t - last) : 0; last = t;
      if (!paused && visible && !document.hidden) {
        x = (x + speed * dt / 1000) % setW;
        track.style.transform = 'translate3d(' + (-x).toFixed(2) + 'px,0,0)';
      }
      requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  });

  /* ---- Count-up: starts at zero when visible, runs once, final value is exposed to screen readers ---- */
  var counters = $$('[data-count]');
  function fmt(el, v) { el.textContent = v + (el.getAttribute('data-suffix') || ''); }
  if (counters.length) {
    if (reduce || !('IntersectionObserver' in window)) {
      counters.forEach(function (el) { fmt(el, el.getAttribute('data-count')); });
    } else {
      counters.forEach(function (el) { fmt(el, 0); });
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          var el = en.target; io.unobserve(el);
          var target = +el.getAttribute('data-count'), dur = 1600, t0 = null;
          (function step(t) {
            if (t0 === null) t0 = t;
            var k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
            fmt(el, Math.round(target * e));
            if (k < 1) requestAnimationFrame(step);
          })(performance.now());
        });
      }, { threshold: 0.4 });
      counters.forEach(function (el) { io.observe(el); });
    }
  }

  /* ---- Events filter dialog ---- */
  var dlg = $('#filtersDlg'), open = $('#openFilters');
  if (dlg && open && typeof dlg.showModal === 'function') {
    open.hidden = false;
    open.addEventListener('click', function () { dlg.showModal(); });
    $$('[data-close-dlg]', dlg).forEach(function (b) { b.addEventListener('click', function () { dlg.close(); open.focus(); }); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    var clr = $('#clearFilters', dlg);
    if (clr) clr.addEventListener('click', function () {
      $$('input[type=checkbox]', dlg).forEach(function (c) { c.checked = false; });
      $$('input[type=radio]', dlg).forEach(function (r) { r.checked = r.value === ''; });
      $$('input[type=date]', dlg).forEach(function (d) { d.value = ''; });
    });
  }

  /* Contact message character counter */
  var msg = document.getElementById('c-message');
  if (msg) {
    var counter = document.createElement('div');
    counter.className = 'char-count';
    counter.setAttribute('aria-live', 'polite');
    function updateCount() {
      var n = (msg.value || '').length;
      counter.textContent = n + ' / 3000';
    }
    if (msg.parentNode) msg.parentNode.appendChild(counter);
    msg.addEventListener('input', updateCount);
    updateCount();
  }

  /* Prevent double submit */
  $$('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.disabled) {
        setTimeout(function () { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }, 0);
      }
    });
  });

})();
