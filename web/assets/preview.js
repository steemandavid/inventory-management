// Label preview on the edit page. Mirrors agent/label_render.py (same layout rules, same
// font): the Pi's PDF is what gets printed, this is a close preview (FSD §8.5).
(function () {
  'use strict';
  var host = document.getElementById('label-preview');
  if (!host) return;
  var note = document.getElementById('preview-note');
  var L = JSON.parse(host.getAttribute('data-layout'));
  var LEN = 50.8, HGT = 12.7, PT = 25.4 / 72, MIN_PT = 5, SPACING = 1.12;
  var LABELS_RATIO = 0.78, BOXBIN_RATIO = 0.18;
  var NS = 'http://www.w3.org/2000/svg';
  var canvas = document.createElement('canvas').getContext('2d');
  var inputs = document.querySelectorAll('[data-preview]');

  function num(k) { return parseFloat(L[k]); }
  function val(name) {
    var e = document.querySelector('[data-preview="' + name + '"]');
    return e ? e.value.trim().replace(/\s+/g, ' ') : '';
  }
  function width(text, bold, sizeMm) {
    canvas.font = (bold ? 'bold ' : '') + '100px "Label Sans"';
    return canvas.measureText(text).width * sizeMm / 100;
  }
  // Shrink to fit (not below MIN_PT), else clip. Returns [sizeMm, clipped, shrunk].
  function fit(text, bold, sizePt, maxW) {
    var s = sizePt * PT, w = width(text, bold, s);
    if (w <= maxW) return [s, false, false];
    var f = s * maxW / w;
    if (f >= MIN_PT * PT) return [f, false, true];
    return [MIN_PT * PT, true, true];
  }
  function svgEl(tag, attrs, text) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function draw() {
    var ml = num('margin_left_mm'), mr = num('margin_right_mm');
    var mt = num('margin_top_mm'), mb = num('margin_bottom_mm');
    var usable = LEN - ml - mr, lw = usable * LABELS_RATIO, bw = usable * BOXBIN_RATIO;
    var right = L.boxbin_side !== 'left';
    var lx = right ? ml : ml + usable - lw, bx = right ? ml + usable - bw : ml;
    var cy = mb + (HGT - mb - mt) / 2;
    var warnings = [];

    var svg = svgEl('svg', { viewBox: '0 0 ' + LEN + ' ' + HGT, role: 'img', 'aria-label': 'Label preview' });
    var defs = svgEl('defs', {});
    svg.appendChild(defs);
    svg.appendChild(svgEl('rect', { x: 0, y: 0, width: LEN, height: HGT, rx: 1.2, class: 'paper' }));
    // strip the printer cannot print on (left margin)
    svg.appendChild(svgEl('rect', { x: 0, y: 0, width: ml, height: HGT, class: 'dead' }));

    var clipN = 0;
    function text(str, bold, sizeMm, x, yUp, anchor, clipped, maxW) {
      var attrs = { x: x, y: HGT - yUp, 'font-size': sizeMm, class: bold ? 't bold' : 't' };
      if (anchor === 'middle') attrs['text-anchor'] = 'middle';
      if (clipped) {
        var id = 'clip' + (++clipN);
        var cp = svgEl('clipPath', { id: id });
        cp.appendChild(svgEl('rect', { x: anchor === 'middle' ? x - maxW / 2 : x, y: 0, width: maxW, height: HGT }));
        defs.appendChild(cp);
        attrs['clip-path'] = 'url(#' + id + ')';
      }
      svg.appendChild(svgEl('text', attrs, str));
    }

    var rows = [['label1', true, 'font_label1', 'Label row 1'], ['label2', false, 'font_label2', 'Label row 2'],
                ['label3', false, 'font_label3', 'Label row 3']]
      .map(function (r) { return [val(r[0]), r[1], num(r[2]), r[3]]; })
      .filter(function (r) { return r[0] !== ''; })
      .map(function (r) {
        var f = fit(r[0], r[1], r[2], lw);
        if (f[1]) warnings.push(r[3] + ' is too long and gets cut off');
        else if (f[2]) warnings.push(r[3] + ' shrunk to ' + (f[0] / PT).toFixed(1) + ' pt');
        return [r[0], r[1], f[0], f[1]];
      });
    var block = rows.reduce(function (a, r) { return a + r[2] * SPACING; }, 0);
    var y = cy + block / 2;
    rows.forEach(function (r) {
      y -= r[2] * SPACING;
      text(r[0], r[1], r[2], lx, y + r[2] * (SPACING - 1) + r[2] * 0.2, 'start', r[3], lw);
    });

    var pair = [[val('box'), num('font_box'), 'Box'], [val('bin'), num('font_bin'), 'Bin']].map(function (p) {
      var f = fit(p[0], true, p[1], bw);
      if (f[1]) warnings.push(p[2] + ' is too long and gets cut off');
      return [p[0], f[0], f[1]];
    });
    block = pair.reduce(function (a, p) { return a + p[1] * SPACING; }, 0);
    y = cy + block / 2;
    pair.forEach(function (p) {
      y -= p[1] * SPACING;
      text(p[0], true, p[1], bx + bw / 2, y + p[1] * (SPACING - 1) + p[1] * 0.2, 'middle', p[2], bw);
    });

    host.replaceChildren(svg);
    if (note) {
      note.textContent = warnings.length ? warnings.join('; ') + '.' : 'Everything fits at full size.';
      note.className = warnings.some(function (w) { return w.indexOf('cut off') >= 0; }) ? 'warn' : '';
    }
  }

  var timer = null;
  function later() { clearTimeout(timer); timer = setTimeout(draw, 80); }
  Array.prototype.forEach.call(inputs, function (e) { e.addEventListener('input', later); });
  Promise.all([document.fonts.load('10px "Label Sans"'), document.fonts.load('bold 10px "Label Sans"')])
    .then(draw, draw);
})();
