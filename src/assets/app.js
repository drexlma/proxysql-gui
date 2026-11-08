// ProxySQL GUI – Diagramme (SVG, ohne externe Bibliotheken) und kleine UI-Helfer
(function () {
  'use strict';

  const COLORS = ['#3d6797', '#e8875b', '#5fb3a9', '#8c5a9e', '#c94f4f', '#7d9a3a'];
  const PIE_COLORS = ['#5fb3a9', '#e8875b', '#3d6797', '#a74c6b'];
  const SVGNS = 'http://www.w3.org/2000/svg';

  function svg(tag, attrs, parent) {
    const el = document.createElementNS(SVGNS, tag);
    for (const k in attrs) el.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(el);
    return el;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function fmtNum(v) {
    if (v === null || v === undefined) return '–';
    const a = Math.abs(v);
    if (a >= 1e6) return (v / 1e6).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' M';
    if (a >= 1e4) return (v / 1e3).toLocaleString('de-DE', { maximumFractionDigits: 0 }) + ' k';
    return v.toLocaleString('de-DE', { maximumFractionDigits: a >= 100 ? 0 : 2 });
  }

  function fmtTime(ts, withDate) {
    const d = new Date(ts * 1000);
    return withDate
      ? d.toLocaleString('de-DE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
      : d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  }

  // "Schöne" Achsenwerte: 0, 2, 4 … bzw. 0, 5k, 10k …
  function niceTicks(max, count) {
    if (!(max > 0)) max = 1;
    const raw = max / count;
    const mag = Math.pow(10, Math.floor(Math.log10(raw)));
    const n = raw / mag;
    const step = (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * mag;
    const ticks = [];
    for (let v = 0; v <= max + step * 0.999; v += step) ticks.push(v);
    return ticks;
  }

  // ------------------------------------------------------------ Liniendiagramm

  function lineChart(box, data) {
    box.innerHTML = '';
    const t = data.t || [];
    const names = Object.keys(data.series || {});
    if (t.length < 2 || !names.length) {
      box.innerHTML = '<div class="chart-empty">' + esc(data.empty || 'Noch keine Daten') + '</div>';
      return;
    }
    const W = Math.max(240, box.clientWidth), H = 170;
    const m = { l: 46, r: 12, t: 10, b: 22 };
    const el = svg('svg', { width: W, height: H, viewBox: `0 0 ${W} ${H}`, role: 'img' }, box);

    let max = 0;
    names.forEach(n => data.series[n].forEach(v => { if (v !== null && v > max) max = v; }));
    const ticks = niceTicks(max, 3);
    const top = ticks[ticks.length - 1];
    const x0 = t[0], x1 = t[t.length - 1], span = x1 - x0 || 1;
    const X = v => m.l + (v - x0) / span * (W - m.l - m.r);
    const Y = v => H - m.b - v / top * (H - m.t - m.b);

    ticks.forEach(v => {
      svg('line', { x1: m.l, x2: W - m.r, y1: Y(v), y2: Y(v), class: v === 0 ? 'axis' : 'gl' }, el);
      svg('text', { x: m.l - 6, y: Y(v) + 4, class: 'ylab' }, el).textContent = fmtNum(v);
    });
    const withDate = span > 86400;
    const labels = W < 360 ? 3 : 4;
    for (let i = 0; i < labels; i++) {
      const v = x0 + span * i / (labels - 1);
      const anchor = i === 0 ? 'start' : i === labels - 1 ? 'end' : 'middle';
      svg('text', { x: X(v), y: H - 5, 'text-anchor': anchor }, el).textContent = fmtTime(v, withDate);
    }

    names.forEach((n, i) => {
      let d = '', pen = false;
      data.series[n].forEach((v, j) => {
        if (v === null) { pen = false; return; }
        d += (pen ? 'L' : 'M') + X(t[j]).toFixed(1) + ' ' + Y(v).toFixed(1);
        pen = true;
      });
      svg('path', { d, class: 'line', stroke: COLORS[i % COLORS.length] }, el);
    });

    // Tooltip mit allen Werten zum nächstgelegenen Zeitpunkt
    const guide = svg('line', { y1: m.t, y2: H - m.b, class: 'guide', visibility: 'hidden' }, el);
    const dots = names.map((n, i) => svg('circle', { r: 3.5, fill: '#fff', stroke: COLORS[i % COLORS.length], 'stroke-width': 2, visibility: 'hidden' }, el));
    const tip = document.createElement('div');
    tip.className = 'chart-tip';
    box.appendChild(tip);
    const hit = svg('rect', { x: m.l, y: 0, width: W - m.l - m.r, height: H - m.b, fill: 'transparent' }, el);

    function show(clientX) {
      const px = clientX - el.getBoundingClientRect().left;
      let best = 0, bd = Infinity;
      t.forEach((v, j) => { const dd = Math.abs(X(v) - px); if (dd < bd) { bd = dd; best = j; } });
      const gx = X(t[best]);
      guide.setAttribute('x1', gx); guide.setAttribute('x2', gx); guide.setAttribute('visibility', 'visible');
      let html = '<b>' + esc(fmtTime(t[best], true)) + '</b>';
      names.forEach((n, i) => {
        const v = data.series[n][best];
        if (v === null) dots[i].setAttribute('visibility', 'hidden');
        else { dots[i].setAttribute('cx', gx); dots[i].setAttribute('cy', Y(v)); dots[i].setAttribute('visibility', 'visible'); }
        html += '<div><i style="background:' + COLORS[i % COLORS.length] + '"></i>' + esc(n) + ': <b>' + fmtNum(v) + '</b>' + (data.unit ? ' ' + esc(data.unit) : '') + '</div>';
      });
      tip.innerHTML = html;
      tip.style.display = 'block';
      const tw = tip.offsetWidth;
      tip.style.left = (gx + 14 + tw > W ? Math.max(0, gx - 14 - tw) : gx + 14) + 'px';
      tip.style.top = m.t + 'px';
    }
    function hide() {
      tip.style.display = 'none';
      guide.setAttribute('visibility', 'hidden');
      dots.forEach(d => d.setAttribute('visibility', 'hidden'));
    }
    hit.addEventListener('mousemove', e => show(e.clientX));
    hit.addEventListener('mouseleave', hide);
    hit.addEventListener('touchstart', e => show(e.touches[0].clientX), { passive: true });
    hit.addEventListener('touchend', hide);

    const lg = document.createElement('div');
    lg.className = 'legend';
    lg.innerHTML = names.map((n, i) => '<span><i style="background:' + COLORS[i % COLORS.length] + '"></i>' + esc(n) + '</span>').join('');
    box.appendChild(lg);
  }

  // ------------------------------------------------------------ Kreisdiagramm

  function pieChart(box, data) {
    box.innerHTML = '';
    const values = data.values || [], labels = data.labels || [];
    const total = values.reduce((a, b) => a + b, 0);
    if (!total) {
      box.innerHTML = '<div class="chart-empty">Noch keine Queries</div>';
      return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'pie-wrap';
    box.appendChild(wrap);

    const S = 150, c = 75, r = 60;
    const el = svg('svg', { width: S, height: S, viewBox: `0 0 ${S} ${S}`, role: 'img' }, wrap);
    const defs = svg('defs', {}, el);
    const grad = svg('radialGradient', { id: 'pie-gloss', cx: '35%', cy: '30%', r: '75%' }, defs);
    svg('stop', { offset: '0%', 'stop-color': '#fff', 'stop-opacity': '.35' }, grad);
    svg('stop', { offset: '100%', 'stop-color': '#fff', 'stop-opacity': '0' }, grad);

    let a0 = -Math.PI / 2;
    values.forEach((v, i) => {
      if (!v) return;
      const frac = v / total, a1 = a0 + frac * 2 * Math.PI, color = PIE_COLORS[i % PIE_COLORS.length];
      let shape;
      if (frac > 0.9999) {
        shape = svg('circle', { cx: c, cy: c, r, fill: color }, el);
      } else {
        const p = a => (c + r * Math.cos(a)).toFixed(2) + ',' + (c + r * Math.sin(a)).toFixed(2);
        shape = svg('path', { d: `M${c},${c} L${p(a0)} A${r},${r} 0 ${frac > 0.5 ? 1 : 0} 1 ${p(a1)} Z`, fill: color, stroke: '#fff', 'stroke-width': 1 }, el);
      }
      svg('title', {}, shape).textContent = labels[i] + ': ' + fmtNum(v);
      a0 = a1;
    });
    svg('circle', { cx: c, cy: c, r, fill: 'url(#pie-gloss)', 'pointer-events': 'none' }, el);

    const lg = document.createElement('div');
    lg.className = 'pie-legend';
    lg.innerHTML = labels.map((l, i) =>
      '<div><i style="background:' + PIE_COLORS[i % PIE_COLORS.length] + '"></i>' + esc(l) +
      ' (' + (100 * values[i] / total).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %)' +
      '<br><span class="muted">' + fmtNum(values[i]) + ' Queries</span></div>').join('');
    wrap.appendChild(lg);

    if (data.kpis) {
      const k = document.createElement('div');
      k.className = 'kpis';
      k.innerHTML = data.kpis.map(([label, value]) => '<div><span>' + esc(label) + '</span><b>' + esc(value) + '</b></div>').join('');
      box.appendChild(k);
    }
  }

  // ------------------------------------------------------------ Dashboard

  function initDashboard(root) {
    const api = root.dataset.api;
    const every = (parseInt(root.dataset.refresh, 10) || 15) * 1000;
    const rangeSel = document.querySelector('[data-range]');
    let range = 3600, last = null;
    try { range = parseInt(localStorage.getItem('proxysql-gui-range'), 10) || 3600; } catch (e) { /* kein Storage */ }
    if (rangeSel) {
      rangeSel.value = String(range);
      if (!rangeSel.value) { rangeSel.value = '3600'; range = 3600; }
      rangeSel.addEventListener('change', () => {
        range = parseInt(rangeSel.value, 10);
        try { localStorage.setItem('proxysql-gui-range', String(range)); } catch (e) { /* egal */ }
        load();
      });
    }

    function render() {
      if (!last) return;
      root.querySelectorAll('[data-chart]').forEach(box => lineChart(box, last[box.dataset.chart] || {}));
      root.querySelectorAll('[data-pie]').forEach(box => pieChart(box, last[box.dataset.pie] || {}));
    }

    async function load() {
      try {
        const res = await fetch(api + '&range=' + range, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (res.status === 401) { location.href = '?page=login'; return; }
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'HTTP ' + res.status);
        last = data;
        render();
        const stamp = document.querySelector('[data-refreshed]');
        if (stamp) stamp.textContent = new Date().toLocaleString('de-DE');
      } catch (e) {
        root.querySelectorAll('[data-chart],[data-pie]').forEach(box => {
          if (!last) box.innerHTML = '<div class="chart-empty">Fehler: ' + esc(e.message) + '</div>';
        });
      }
    }

    load();
    setInterval(() => { if (!document.hidden) load(); }, every);
    let timer;
    window.addEventListener('resize', () => { clearTimeout(timer); timer = setTimeout(render, 150); });
  }

  // ------------------------------------------------------------ UI-Helfer

  function genPassword(len) {
    // ohne Verwechsler und ohne Zeichen, die in Konfigurationsdateien stören (' " : ; \)
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.!+%';
    const buf = new Uint32Array(len);
    crypto.getRandomValues(buf);
    return Array.from(buf, n => chars[n % chars.length]).join('');
  }

  document.addEventListener('submit', e => {
    const msg = e.target.dataset && e.target.dataset.confirm;
    if (msg && !confirm(msg)) e.preventDefault();
  });

  document.addEventListener('click', e => {
    const toggle = e.target.closest('.panel-toggle');
    if (toggle) toggle.closest('.panel').classList.toggle('collapsed');

    const gen = e.target.closest('[data-genpw]');
    if (gen) {
      e.preventDefault();
      const pw = genPassword(20);
      [gen.dataset.genpw, gen.dataset.genpw2].filter(Boolean).forEach(sel => {
        const input = document.querySelector(sel);
        if (input) { input.value = pw; input.type = 'text'; }
      });
    }

    if (e.target.closest('[data-reload]')) {
      e.preventDefault();
      location.reload();
    }

    const fill = e.target.closest('[data-fill]');
    if (fill) {
      e.preventDefault();
      const ta = document.getElementById('console-sql');
      const target = document.getElementById('console-target');
      if (target && fill.dataset.fillTarget && target.value !== fill.dataset.fillTarget) {
        // Ziel wechseln: Seite mit vorbelegter Abfrage neu laden (Datenbankliste etc.)
        location.href = '?page=console&target=' + encodeURIComponent(fill.dataset.fillTarget) + '&sql=' + encodeURIComponent(fill.dataset.fill);
        return;
      }
      if (ta) { ta.value = fill.dataset.fill; ta.focus(); }
    }
  });

  document.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form.submit()));

  document.querySelectorAll('[data-filter-table]').forEach(input => {
    const table = document.querySelector(input.dataset.filterTable);
    if (!table) return;
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach(tr => { tr.hidden = q !== '' && !tr.textContent.toLowerCase().includes(q); });
    });
  });

  const incidentFilter = document.querySelector('[data-incident-filter]');
  if (incidentFilter) {
    incidentFilter.addEventListener('change', () => {
      document.querySelectorAll('.incidents tbody tr[data-cat]').forEach(tr => {
        tr.hidden = incidentFilter.value !== '' && tr.dataset.cat !== incidentFilter.value;
      });
    });
  }

  const consoleTarget = document.getElementById('console-target');
  if (consoleTarget) {
    consoleTarget.addEventListener('change', () => {
      const ta = document.getElementById('console-sql');
      location.href = '?page=console&target=' + encodeURIComponent(consoleTarget.value) + '&sql=' + encodeURIComponent(ta ? ta.value : '');
    });
  }
  const consoleSql = document.getElementById('console-sql');
  if (consoleSql) {
    consoleSql.addEventListener('keydown', e => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); consoleSql.form.requestSubmit(); }
    });
  }

  const dash = document.querySelector('[data-dashboard]');
  if (dash) initDashboard(dash);
})();
