// Shared: show the back-to-top arrow only after scrolling down a bit.
(function () {
  'use strict';
  var btn = document.getElementById('totop');
  if (!btn) return;
  function update() { btn.classList.toggle('show', window.scrollY > 400); }
  window.addEventListener('scroll', update, { passive: true });
  update();
})();

// Reset the print-queue auto-refresh counter once nothing is queued any more.
(function () {
  try {
    if (!document.querySelector('script[src*="refresh.js"]')) sessionStorage.removeItem('inv_refresh');
  } catch (e) { /* storage blocked: nothing to reset */ }
})();
