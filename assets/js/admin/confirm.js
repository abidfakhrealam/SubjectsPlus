/* Buttons / links with data-confirm="question" ask before they act (replaces inline onclick="return confirm()"). */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  }, true);
})();
