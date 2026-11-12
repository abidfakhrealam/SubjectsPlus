/* Records → Find & bulk edit: select all, show the inputs for the chosen action, confirm. */
(function () {
  'use strict';
  var form = document.getElementById('bulk-form');
  if (!form) { return; }
  var all = document.getElementById('bulk-all');
  var boxes = function () { return form.querySelectorAll('input.bulk-id'); };
  if (all) {
    all.addEventListener('change', function () {
      Array.prototype.forEach.call(boxes(), function (b) { b.checked = all.checked; });
    });
  }
  var sel = document.getElementById('bulk_action');
  var show = function () {
    Array.prototype.forEach.call(form.querySelectorAll('.bulk-arg'), function (s) {
      s.classList.toggle('on', s.getAttribute('data-for') === sel.value);
    });
  };
  sel.addEventListener('change', show);
  show();
  form.addEventListener('submit', function (e) {
    var n = Array.prototype.filter.call(boxes(), function (b) { return b.checked; }).length;
    if (!n) { e.preventDefault(); window.alert('Tick at least one record.'); return; }
    if (!window.confirm('Apply "' + sel.options[sel.selectedIndex].text + '" to ' + n + ' record(s)?')) { e.preventDefault(); }
  });
})();
