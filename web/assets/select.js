// Admin overview: "Select all" / "Remove selection" for the label checkboxes, with a count.
(function () {
  'use strict';
  var all = document.getElementById('select-all');
  var none = document.getElementById('select-none');
  var count = document.getElementById('selected-count');
  var boxes = document.querySelectorAll('input[name="ids[]"]');
  if (!all || !none || !boxes.length) return;
  function update() {
    var n = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
    count.textContent = n ? n + ' selected' : '';
  }
  function set(v) { Array.prototype.forEach.call(boxes, function (b) { b.checked = v; }); update(); }
  all.hidden = false;
  none.hidden = false;
  all.addEventListener('click', function () { set(true); });
  none.addEventListener('click', function () { set(false); });
  Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', update); });
  update();
})();
