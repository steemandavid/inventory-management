// Live search on the public page. Builds results with textContent only (no innerHTML).
(function () {
  'use strict';
  var input = document.getElementById('q');
  var list = document.getElementById('results');
  var count = document.getElementById('count');
  var pager = document.getElementById('pager');
  if (!input || !list) return;
  var api = list.getAttribute('data-api');
  var timer = null, seq = 0;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function card(it) {
    var li = el('li', 'item');
    var loc = el('div', 'loc');
    loc.appendChild(el('span', 'box', it.box));
    loc.appendChild(el('span', 'bin', it.bin));
    li.appendChild(loc);
    var txt = el('div', 'txt');
    txt.appendChild(el('div', 'l1', it.label1));
    if (it.label2) txt.appendChild(el('div', 'l2', it.label2));
    if (it.label3) txt.appendChild(el('div', 'l2', it.label3));
    if (it.keywords) txt.appendChild(el('div', 'meta', it.keywords));
    if (it.comments) txt.appendChild(el('div', 'meta comments', it.comments));
    li.appendChild(txt);
    return li;
  }

  function run() {
    var q = input.value.trim();
    var my = ++seq;
    fetch(api + '?q=' + encodeURIComponent(q), { credentials: 'omit' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (my !== seq) return;              // a newer search is already under way
        if (data.error) { count.textContent = data.error; return; }
        list.replaceChildren.apply(list, data.items.map(card));
        count.textContent = data.total + ' item' + (data.total === 1 ? '' : 's') + (q ? ' found' : '') +
          (data.pages > 1 ? ' (showing the first ' + data.items.length + ', press Enter for all pages)' : '');
        pager.replaceChildren();
        var url = new URL(window.location.href);
        if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
        url.searchParams.delete('page');
        history.replaceState(null, '', url);
      })
      .catch(function () { /* keep the current results; the form still works */ });
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(run, 250);
  });
})();
