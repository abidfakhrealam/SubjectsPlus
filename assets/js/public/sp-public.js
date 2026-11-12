/*
 * SubjectsPlus public pages: small accessibility and behaviour helpers (no inline event handlers, so
 * the pages work under a stricter Content-Security-Policy).
 */
(function () {
  'use strict';
  var me = document.currentScript;
  var txt = function (name, dflt) { return (me && me.getAttribute('data-' + name)) || dflt; };

  // "Databases by subject / format / vendor" dropdowns: go to the chosen list
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches('select.sp-nav-select') && t.value) {
      window.location.href = t.value;
    }
  });

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    // skip link (2.4.1) to the main heading / content area
    var main = document.getElementById('sp-main') || document.querySelector('main, h1, #maincontent, .wrapper-full');
    if (main) {
      if (!main.id) { main.id = 'sp-main'; }
      if (!main.hasAttribute('tabindex')) { main.setAttribute('tabindex', '-1'); }
      if (!document.querySelector('.sp-skip-link')) {
        var skip = document.createElement('a');
        skip.className = 'sp-skip-link';
        skip.href = '#' + main.id;
        skip.textContent = txt('skip', 'Skip to main content');
        document.body.insertBefore(skip, document.body.firstChild);
      }
    }

    // links that open a new window say so to screen-reader users
    var label = txt('newwin', '(opens in a new window)');
    Array.prototype.forEach.call(document.querySelectorAll('a[target="_blank"]'), function (a) {
      if (!a.querySelector('.sp-sr-only')) {
        var s = document.createElement('span');
        s.className = 'sp-sr-only';
        s.textContent = ' ' + label;
        a.appendChild(s);
      }
      var rel = (a.getAttribute('rel') || '').split(/\s+/);
      if (rel.indexOf('noopener') < 0) { rel.push('noopener'); a.setAttribute('rel', rel.join(' ').trim()); }
    });

    // icon-only "more information" toggles: make them keyboard operable buttons
    Array.prototype.forEach.call(document.querySelectorAll('.toggleLink'), function (el) {
      if (!el.hasAttribute('role')) { el.setAttribute('role', 'button'); }
      if (!el.hasAttribute('tabindex')) { el.setAttribute('tabindex', '0'); }
      if (!el.hasAttribute('aria-label')) {
        var i = el.querySelector('[title]');
        el.setAttribute('aria-label', i ? i.getAttribute('title') : 'More information');
      }
      el.setAttribute('aria-expanded', 'false');
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); el.click(); }
      });
      el.addEventListener('click', function () {
        el.setAttribute('aria-expanded', el.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
      });
    });

    // decorative icon fonts are hidden from screen readers
    Array.prototype.forEach.call(document.querySelectorAll('i.fa, i.fas, i.far, i.fab'), function (i) {
      if (!i.hasAttribute('aria-hidden')) { i.setAttribute('aria-hidden', 'true'); }
    });

    // data tables without headers are layout tables
    Array.prototype.forEach.call(document.querySelectorAll('table.item_listing'), function (t) {
      if (!t.querySelector('th') && !t.hasAttribute('role')) { t.setAttribute('role', 'presentation'); }
    });
  });
})();
