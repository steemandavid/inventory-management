// Shared: show the back-to-top arrow only after scrolling down a bit.
(function () {
  'use strict';
  var btn = document.getElementById('totop');
  if (!btn) return;
  function update() { btn.classList.toggle('show', window.scrollY > 400); }
  window.addEventListener('scroll', update, { passive: true });
  update();
})();
