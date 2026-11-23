// Admin overview while labels are in the print queue: reload every 4 s (max ~2 min) so
// "in print queue" turns into "printed" without manual reloads. Stops when a field has focus.
(function () {
  'use strict';
  var n = parseInt(sessionStorage.getItem('inv_refresh') || '0', 10);
  if (n >= 30) { sessionStorage.removeItem('inv_refresh'); return; }
  setTimeout(function () {
    var a = document.activeElement;
    if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA') && a.value) return;
    if (document.querySelector('input[type=checkbox]:checked')) return;
    try { sessionStorage.setItem('inv_refresh', String(n + 1)); } catch (e) { /* ignore */ }
    window.location.reload();
  }, 4000);
})();
