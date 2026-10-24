(() => {
  'use strict';
  const base = document.querySelector('meta[name="base"]').content;
  const csrf = document.querySelector('meta[name="csrf"]').content;

  // Safe DOM builder: everything goes through textContent.
  const h = (tag, attrs = {}, ...kids) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v; else if (k === 'text') el.textContent = v; else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined) el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
    return el;
  };

  /* ---------- Filter bar ---------- */
  document.querySelectorAll('form.filters').forEach((form) => {
    const range = form.querySelector('[data-role="range"]');
    const custom = form.querySelector('[data-role="custom"]');
    const toggle = form.querySelector('[data-role="custom-toggle"]');
    toggle?.addEventListener('click', () => {
      range.value = 'custom'; custom.classList.add('show');
      form.querySelectorAll('.pill').forEach((p) => p.classList.toggle('active', p === toggle));
      custom.querySelector('input')?.focus();
    });
    form.querySelectorAll('details.ms').forEach((d) => {
      d.addEventListener('change', () => {
        const on = [...d.querySelectorAll('input:checked')];
        d.querySelector('.count').textContent = on.length === 0 ? 'All' : on.length === 1 ? on[0].parentElement.textContent.trim() : on.length + ' selected';
      });
    });
    form.querySelector('select.src')?.addEventListener('change', () => form.submit());
  });
  document.addEventListener('click', (e) => {
    document.querySelectorAll('details.ms[open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  /* ---------- Hover explanations: every value says what it is ---------- */
  const clean = (el) => { const c = el.cloneNode(true); c.querySelectorAll('.info').forEach((i) => i.remove()); return c.textContent.replace(/[\u25B2\u25BC]/g, '').replace(/\s+/g, ' ').trim(); };
  document.querySelectorAll('table.grid').forEach((t) => {
    const heads = [...t.querySelectorAll('thead tr:last-child th')].map(clean);
    t.querySelectorAll('tbody tr, tfoot tr').forEach((tr) => {
      [...tr.children].forEach((td, i) => {
        if (td.title || td.classList.contains('empty-cell') || !heads[i]) return;
        const v = clean(td);
        if (v && v !== '\u2014') td.title = heads[i] + ': ' + v;
        else if (v === '\u2014') td.title = heads[i] + ': no value';
      });
    });
  });
  document.querySelectorAll('.kpi').forEach((k) => {
    const v = k.querySelector('.kpi-value'), l = clean(k.querySelector('.kpi-label')), d = k.querySelector('.kpi-delta');
    if (v) v.title = l + ': ' + v.textContent + (d ? ' (' + d.textContent + ')' : '');
  });
  document.querySelectorAll('.tile').forEach((t) => {
    const v = t.querySelector('.tile-value');
    if (v) v.title = clean(t.querySelector('.tile-label')) + ': ' + v.textContent + ' \u2014 ' + clean(t.querySelector('.muted'));
  });

  // Instant tooltip (the browser's native one is slow): title -> data-tt on first hover.
  const tt = h('div', { id: 'tt', hidden: true });
  document.body.append(tt);
  const place = (e) => {
    const pad = 14, w = tt.offsetWidth, hgt = tt.offsetHeight;
    let x = e.clientX + pad, y = e.clientY + pad;
    if (x + w > innerWidth - 8) x = e.clientX - w - pad;
    if (y + hgt > innerHeight - 8) y = e.clientY - hgt - pad;
    tt.style.left = Math.max(8, x) + 'px'; tt.style.top = Math.max(8, y) + 'px';
  };
  document.addEventListener('mouseover', (e) => {
    const el = e.target.closest('[title], [data-tt]');
    if (!el || el.closest('#drawer-body textarea')) { tt.hidden = true; return; }
    if (el.hasAttribute('title')) { el.dataset.tt = el.getAttribute('title'); el.removeAttribute('title'); }
    if (!el.dataset.tt) return;
    tt.textContent = el.dataset.tt; tt.hidden = false; place(e);
  });
  document.addEventListener('mousemove', (e) => { if (!tt.hidden) place(e); });
  document.addEventListener('mouseout', (e) => { if (!e.relatedTarget || !e.relatedTarget.closest || !e.relatedTarget.closest('[data-tt]')) tt.hidden = true; });
  document.addEventListener('scroll', () => { tt.hidden = true; }, true);

  /* ---------- Drawer ---------- */
  const drawer = document.getElementById('drawer');
  const scrim = document.getElementById('scrim');
  const body = document.getElementById('drawer-body');
  let lastFocus = null;

  const closeDrawer = () => { drawer.hidden = true; scrim.hidden = true; lastFocus?.focus(); };
  const openShell = () => { lastFocus = document.activeElement; drawer.hidden = false; scrim.hidden = false; drawer.querySelector('#drawer-close').focus(); };
  document.getElementById('drawer-close').addEventListener('click', closeDrawer);
  scrim.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !drawer.hidden) closeDrawer(); });

  const kv = (pairs) => h('dl', { class: 'kv' }, pairs.flatMap(([k, v]) => [h('dt', { text: k }), h('dd', { text: v === null || v === undefined || v === '' ? '—' : String(v) })]));

  const commentNodes = (list) => list.length
    ? list.map((c) => h('div', { class: 'comment' }, h('div', { class: 'meta' }, h('strong', { text: c.author }), ' · ' + c.created), h('div', { class: 'txt', text: c.body })))
    : [h('p', { class: 'muted small', text: 'No comments yet.' })];

  function renderEvent(d) {
    const ev = d.event;
    body.replaceChildren();
    const title = ev.link ? h('a', { href: ev.link, target: '_blank', rel: 'noopener noreferrer', text: ev.title || '(untitled)' }) : (ev.title || '(untitled)');
    body.append(
      h('span', { class: 'badge ' + (['D1_MOVED', 'DEADLINE_REMOVED'].includes(ev.type) ? 'red' : ''), title: ev.desc, text: ev.label }),
      h('div', { class: 'd-title' }, title),
      h('div', { class: 'd-sec' }, h('h4', { text: 'This event' }), kv([
        ['Inflation date', ev.when], ['Detected by n8n', ev.detected], ['Source change time', ev.changed_at], ['Changed by', ev.changed_by], ['Change', ev.change],
        ['Days added', '+' + ev.days_added], ['Total slip', ev.total_added], ['Times moved', ev.times_moved],
        ['After deadline', ev.after_deadline ? 'Yes' : 'No'], ['Reason', ev.reason || 'No reason given'], ['Status', ev.status],
        ['Project', ev.project], ['Board', ev.board], ['Source', ev.source], ['Assignee', ev.assignee], ['BA', ev.ba],
      ])),
      h('div', { class: 'd-sec' }, h('h4', { text: 'Task history (' + d.history.length + ')' }),
        h('ul', { class: 'hist' }, d.history.map((x) => h('li', { class: x.current ? 'current' : '' },
          x.label + ' · ' + x.change + (x.days_added ? ' (+' + x.days_added + 'd)' : ''),
          h('span', { class: 'when', text: x.detected + (x.reason ? ' — ' + x.reason : '') }))))),
      h('div', { class: 'd-sec' }, h('h4', { text: 'Current task state' }),
        d.task ? kv(Object.entries(d.task)) : h('p', { class: 'muted small', text: 'Task no longer in the tasks table.' })),
    );
    body.querySelectorAll('dl.kv dd').forEach((dd) => { dd.title = dd.previousElementSibling.textContent + ': ' + dd.textContent; });
    const list = h('div', { id: 'c-list' }, commentNodes(d.comments));
    const err = h('span', { class: 'err' });
    const ta = h('textarea', { name: 'body', maxlength: '2000', placeholder: 'Add a comment…', required: true });
    const counter = h('span', { class: 'muted small', text: '0 / 2000' });
    ta.addEventListener('input', () => { counter.textContent = ta.value.length + ' / 2000'; });
    const btn = h('button', { class: 'btn primary', type: 'submit', text: 'Add comment' });
    const form = h('form', { class: 'c-form' }, ta, h('div', { class: 'row' }, counter, err, btn));
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); err.textContent = ''; btn.disabled = true;
      try {
        const fd = new FormData(); fd.set('event_id', ev.id); fd.set('body', ta.value); fd.set('csrf', csrf);
        const r = await fetch(base + '/api/comment.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!r.ok) throw new Error(j.error || 'Could not save comment');
        list.replaceChildren(...commentNodes(j.comments));
        ta.value = ''; counter.textContent = '0 / 2000';
        document.querySelectorAll('[data-comment-count="' + ev.id + '"]').forEach((c) => { c.textContent = j.count; });
      } catch (x) { err.textContent = x.message; } finally { btn.disabled = false; }
    });
    body.append(h('div', { class: 'd-sec' }, h('h4', { text: 'Comments' }), list, form));
  }

  async function openEvent(id) {
    openShell();
    body.replaceChildren(h('p', { class: 'muted', text: 'Loading…' }));
    try {
      const r = await fetch(base + '/api/event.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      if (r.status === 401) { location.href = base + '/login.php'; return; }
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || 'Failed to load');
      renderEvent(j);
    } catch (x) { body.replaceChildren(h('p', { class: 'err', text: x.message })); }
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('a, button, input, summary, .info')) return;
    const tr = e.target.closest('tr[data-event-id]');
    if (tr) openEvent(tr.dataset.eventId);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches && e.target.matches('tr[data-event-id]')) openEvent(e.target.dataset.eventId);
  });

  /* ---------- Charts ---------- */
  const holder = document.getElementById('charts');
  if (!holder || typeof Chart === 'undefined') return;

  const css = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  const f1 = (v) => (Number.isInteger(v) ? String(v) : v.toFixed(1));
  let data = null;
  const instances = {};

  // Dashed reference line (team average, 1.0x ...).
  const refLine = {
    id: 'refLine',
    afterDatasetsDraw(chart, _a, o) {
      if (!o || o.value === null || o.value === undefined) return;
      const { ctx, chartArea, scales } = chart;
      ctx.save(); ctx.setLineDash([6, 4]); ctx.lineWidth = 1.5; ctx.strokeStyle = css('--muted'); ctx.fillStyle = css('--muted'); ctx.font = '600 11px Inter, system-ui, sans-serif';
      ctx.beginPath();
      if (o.axis === 'x') {
        const x = scales.x.getPixelForValue(o.value);
        ctx.moveTo(x, chartArea.top); ctx.lineTo(x, chartArea.bottom); ctx.stroke(); ctx.setLineDash([]);
        ctx.textAlign = x > chartArea.right - 110 ? 'right' : 'left';
        ctx.fillText(o.label, x + (ctx.textAlign === 'left' ? 5 : -5), chartArea.bottom - 6);
      } else {
        const y = scales.y.getPixelForValue(o.value);
        ctx.moveTo(chartArea.left, y); ctx.lineTo(chartArea.right, y); ctx.stroke(); ctx.setLineDash([]);
        ctx.textAlign = 'left'; ctx.fillText(o.label, chartArea.left + 4, y - 5);
      }
      ctx.restore();
    },
  };
  // Numbers at the end of each bar.
  const valueLabels = {
    id: 'valueLabels',
    afterDatasetsDraw(chart, _a, o) {
      if (!o || !o.show) return;
      const { ctx } = chart;
      ctx.save(); ctx.fillStyle = css('--text'); ctx.font = '700 12px Inter, system-ui, sans-serif';
      chart.data.datasets.forEach((ds, i) => chart.getDatasetMeta(i).data.forEach((bar, j) => {
        const v = ds.data[j];
        if (v === null || v === undefined) return;
        const txt = o.format ? o.format(v) : f1(v);
        if (chart.options.indexAxis === 'y') { ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.fillText(txt, bar.x + 7, bar.y); }
        else { ctx.textAlign = 'center'; ctx.textBaseline = 'bottom'; ctx.fillText(txt, bar.x, bar.y - 4); }
      }));
      ctx.restore();
    },
  };
  Chart.register(refLine, valueLabels);

  const sum = (a) => a.reduce((x, y) => x + (y || 0), 0);
  const setInsight = (id, html) => {
    const el = holder.querySelector('[data-chart="' + id + '"] [data-role="insight"]');
    if (el) el.textContent = html || '';
  };
  const WHY = {
    weekly_days: 'No deadline moves were recorded in this period.',
    weekly_deleted: 'No tickets were deleted in this period.',
    by_type: 'No inflation events in this period.',
    pct_ext: 'No tasks match the project / BA / source filters.',
    avg_slip: 'No task has been extended yet.',
    padding: 'No task has both a plan window and an estimate.',
  };
  const UNIT = { weekly_days: ' working days added', pct_ext: '% of tasks extended', avg_slip: ' working days per extended task',
    padding: '\u00D7 (plan window / estimate)', weekly_deleted: ' tickets deleted', by_type: ' events' };

  function build() {
    const text = css('--text'), muted = css('--muted'), grid = css('--line');
    Chart.defaults.color = muted; Chart.defaults.borderColor = grid;
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif'; Chart.defaults.font.size = 12;
    Object.values(instances).forEach((c) => c.destroy());
    for (const k of Object.keys(instances)) delete instances[k];

    const bas = data.bas;
    const withTasks = bas.filter((b) => b.tasks > 0);
    const sorted = (key) => withTasks.filter((b) => b[key] !== null && b[key] > 0).sort((x, y) => y[key] - x[key]);

    const base = (extra = {}) => ({
      responsive: true, maintainAspectRatio: false, layout: { padding: { top: 6, right: 8 } },
      plugins: { legend: { display: false }, tooltip: { backgroundColor: text, titleColor: css('--bg'), bodyColor: css('--bg'), padding: 10, cornerRadius: 8, boxPadding: 4 } },
      ...extra,
    });
    const gridless = { grid: { display: false }, border: { display: false } };
    const softGrid = { grid: { color: grid }, border: { display: false } };
    const bar = (color) => ({ backgroundColor: color, borderRadius: 6, borderSkipped: false, maxBarThickness: 30 });
    const legend = (pos) => ({ display: true, position: pos, labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16, color: text } });

    const specs = {};

    // 1. Working days added per week (line, one per BA that has data)
    const dayBas = bas.filter((b) => sum(b.weekly_days) > 0);
    specs.weekly_days = {
      ok: dayBas.length > 0,
      insight: () => { const top = [...dayBas].sort((x, y) => sum(y.weekly_days) - sum(x.weekly_days))[0];
        return sum(dayBas.flatMap((b) => b.weekly_days)) + ' working days added in total. Most by ' + top.name + ' (' + sum(top.weekly_days) + ').'; },
      cfg: { type: 'line', data: { labels: data.weeks, datasets: dayBas.map((b) => ({ label: b.name, data: b.weekly_days, borderColor: b.color, backgroundColor: b.color, borderWidth: 2.5, tension: .3, pointRadius: 3, pointHoverRadius: 5 })) },
        options: base({ interaction: { mode: 'index', intersect: false }, plugins: { ...base().plugins, legend: legend('bottom') },
          scales: { x: { ...gridless }, y: { ...softGrid, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Working days added' } } } }) },
    };

    // 2. % extended (horizontal, sorted)
    const pe = sorted('pct_ext');
    specs.pct_ext = {
      ok: withTasks.length > 0 && pe.length > 0,
      insight: () => pe[0].name + ' has the most tasks extended: ' + f1(pe[0].pct_ext) + '% (team average ' + f1(data.team.pct_ext || 0) + '%).',
      cfg: { type: 'bar', data: { labels: pe.map((b) => b.name), datasets: [{ label: '% extended', data: pe.map((b) => b.pct_ext), ...bar(pe.map((b) => b.color)) }] },
        options: base({ indexAxis: 'y', layout: { padding: { right: 56, top: 6 } },
          plugins: { ...base().plugins, valueLabels: { show: true, format: (v) => f1(v) + '%' }, refLine: { axis: 'x', value: data.team.pct_ext, label: 'Team ' + f1(data.team.pct_ext || 0) + '%' } },
          scales: { x: { ...softGrid, beginAtZero: true, suggestedMax: Math.max(5, ...pe.map((b) => b.pct_ext), data.team.pct_ext || 0) * 1.1, ticks: { callback: (v) => v + '%' } }, y: { ...gridless } } }) },
    };

    // 3. Average slip per extended task
    const sl = sorted('avg_slip');
    specs.avg_slip = {
      ok: sl.length > 0,
      insight: () => sl[0].name + ' loses the most time per extended task: ' + f1(sl[0].avg_slip) + ' working days.',
      cfg: { type: 'bar', data: { labels: sl.map((b) => b.name), datasets: [{ label: 'Avg slip', data: sl.map((b) => b.avg_slip), ...bar(sl.map((b) => b.color)) }] },
        options: base({ indexAxis: 'y', layout: { padding: { right: 56, top: 6 } },
          plugins: { ...base().plugins, valueLabels: { show: true, format: (v) => f1(v) + ' d' } },
          scales: { x: { ...softGrid, beginAtZero: true, title: { display: true, text: 'Working days' } }, y: { ...gridless } } }) },
    };

    // 4. Padding ratio with a 1.0x reference
    const pd = sorted('padding');
    specs.padding = {
      ok: pd.length > 0,
      insight: () => pd[0].name + ' pads plans the most: ' + f1(pd[0].padding) + '\u00D7 the estimate. 1.0\u00D7 means the plan window equals the estimate.',
      cfg: { type: 'bar', data: { labels: pd.map((b) => b.name), datasets: [{ label: 'Padding ratio', data: pd.map((b) => b.padding), ...bar(pd.map((b) => b.color)) }] },
        options: base({ indexAxis: 'y', layout: { padding: { right: 56, top: 6 } },
          plugins: { ...base().plugins, valueLabels: { show: true, format: (v) => f1(v) + '\u00D7' }, refLine: { axis: 'x', value: 1, label: '1.0\u00D7' } },
          scales: { x: { ...softGrid, beginAtZero: true, suggestedMax: Math.max(1.5, ...pd.map((b) => b.padding)) * 1.1, ticks: { callback: (v) => v + '\u00D7' } }, y: { ...gridless } } }) },
    };

    // 5. Tickets deleted per week (grouped)
    const delBas = bas.filter((b) => sum(b.weekly_deleted) > 0);
    specs.weekly_deleted = {
      ok: delBas.length > 0,
      insight: () => { const top = [...delBas].sort((x, y) => sum(y.weekly_deleted) - sum(x.weekly_deleted))[0];
        return sum(delBas.flatMap((b) => b.weekly_deleted)) + ' tickets deleted in total. Most under ' + top.name + ' (' + sum(top.weekly_deleted) + ').'; },
      cfg: { type: 'bar', data: { labels: data.weeks, datasets: delBas.map((b) => ({ label: b.name, data: b.weekly_deleted, ...bar(b.color) })) },
        options: base({ plugins: { ...base().plugins, legend: legend('bottom') },
          scales: { x: { ...gridless }, y: { ...softGrid, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Tickets deleted' } } } }) },
    };

    // 6. Inflation events by type per BA (stacked, horizontal)
    const typeBas = bas.filter((b) => sum(Object.values(b.types)) > 0).sort((x, y) => sum(Object.values(y.types)) - sum(Object.values(x.types)));
    specs.by_type = {
      ok: typeBas.length > 0,
      insight: () => { const tot = {}; typeBas.forEach((b) => Object.entries(b.types).forEach(([k, v]) => { tot[k] = (tot[k] || 0) + v; }));
        const [k, v] = Object.entries(tot).sort((x, y) => y[1] - x[1])[0]; const t = data.types.find((x) => x.key === k);
        return 'Most common: ' + (t ? t.label : k) + ' (' + v + ' of ' + sum(Object.values(tot)) + ' events).'; },
      cfg: { type: 'bar', data: { labels: typeBas.map((b) => b.name), datasets: data.types.filter((t) => typeBas.some((b) => b.types[t.key])).map((t) => ({ label: t.label, data: typeBas.map((b) => b.types[t.key] || 0), backgroundColor: t.color, borderWidth: 0, maxBarThickness: 30 })) },
        options: base({ indexAxis: 'y', plugins: { ...base().plugins, legend: legend('bottom') },
          scales: { x: { ...softGrid, stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Events' } }, y: { ...gridless, stacked: true } } }) },
    };

    for (const [id, s] of Object.entries(specs)) {
      const card = holder.querySelector('[data-chart="' + id + '"]');
      if (!card) continue;
      const box = card.querySelector('.chart-box'), empty = card.querySelector('.empty'), png = card.querySelector('[data-png]');
      box.hidden = !s.ok; empty.hidden = s.ok; png.disabled = !s.ok;
      const why = card.querySelector('[data-role="why"]');
      if (why) why.textContent = WHY[id] || '';
      setInsight(id, s.ok ? s.insight() : '');
      if (!s.ok) continue;
      s.cfg.options.plugins.tooltip.callbacks = {
        label: (c) => { const v = c.parsed.x !== null && c.chart.options.indexAxis === 'y' ? c.parsed.x : c.parsed.y;
          return ' ' + (['pct_ext', 'avg_slip', 'padding'].includes(id) ? '' : c.dataset.label + ': ') + (v === null || v === undefined ? '\u2014' : f1(v)) + UNIT[id]; } };
      if (['pct_ext', 'avg_slip', 'padding'].includes(id)) s.cfg.options.plugins.tooltip.callbacks.title = (items) => items[0].label;
      instances[id] = new Chart(document.getElementById('c-' + id), s.cfg);
    }
  }

  holder.addEventListener('click', (e) => {
    const b = e.target.closest('[data-png]');
    if (!b || !instances[b.dataset.png]) return;
    const src = instances[b.dataset.png].canvas;
    const c = document.createElement('canvas'); c.width = src.width; c.height = src.height;
    const ctx = c.getContext('2d'); ctx.fillStyle = css('--surface'); ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(src, 0, 0);
    const a = document.createElement('a'); a.href = c.toDataURL('image/png'); a.download = 'timeline-' + b.dataset.png + '.png'; a.click();
  });

  fetch(holder.dataset.endpoint + location.search, { credentials: 'same-origin' })
    .then((r) => { if (r.status === 401) { location.href = base + '/login.php'; throw new Error('auth'); } return r.json(); })
    .then((j) => { data = j; build(); window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', build); })
    .catch((x) => { if (x.message !== 'auth') holder.prepend(h('p', { class: 'err', text: 'Could not load chart data.' })); });
})();
