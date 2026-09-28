'use strict';

const $ = (sel, root = document) => root.querySelector(sel);
const state = { projects: [], running: [], entries: [], logDays: 14 };

// ---------- helpers ----------
async function api(action, body) {
  let res;
  if (body === undefined) {
    res = await fetch('api.php?action=' + action, { headers: { 'X-Beat': '1' } });
  } else {
    res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Beat': '1' },
      body: JSON.stringify({ action, ...body }),
    });
  }
  if (res.status === 401) { location.reload(); throw new Error('Signed out'); }
  const data = await res.json().catch(() => ({ error: 'The server sent an unexpected response.' }));
  if (!res.ok) throw new Error(data.error || 'Something went wrong.');
  return data;
}

function el(tag, attrs = {}, ...children) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') n.className = v;
    else if (k === 'style') n.style.cssText = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else if (v === true) n.setAttribute(k, '');
    else if (v !== false && v != null) n.setAttribute(k, v);
  }
  for (const c of children.flat()) if (c != null) n.append(c);
  return n;
}

let toastTimer;
function toast(msg) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

const project = (id) => state.projects.find((p) => p.id === id);
const pad = (n) => String(n).padStart(2, '0');
const seconds = (t, now = Date.now()) => Math.max(0, ((t.end_time ? Date.parse(t.end_time) : now) - Date.parse(t.start_time)) / 1000);
function clock(s) { s = Math.floor(s); return `${Math.floor(s / 3600)}:${pad(Math.floor(s / 60) % 60)}:${pad(s % 60)}`; }
function hm(s) { const m = Math.round(s / 60); return `${Math.floor(m / 60)}:${pad(m % 60)}`; }
const timeFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
const dayFmt = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'short', day: 'numeric' });
const shortDay = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
function startOfDay(d) { const x = new Date(d); x.setHours(0, 0, 0, 0); return x; }
function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
function dayKey(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }
function toLocalInput(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${dayKey(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}
function fromLocalInput(v) { return v ? new Date(v).toISOString() : null; }

function projectLabel(id) {
  const p = project(id);
  return el('span', { class: 'proj' },
    el('span', { class: 'dot', style: `background:${p ? p.color : 'var(--line)'}` }),
    el('span', {}, p ? p.name : 'No project'));
}

function fillProjectSelect(select, { selected = null, includeAll = false, includeArchived = false } = {}) {
  select.replaceChildren();
  if (includeAll) select.append(el('option', { value: '' }, 'All projects'));
  select.append(el('option', { value: '0' }, 'No project'));
  for (const p of state.projects) {
    if (p.archived && !includeArchived && p.id !== selected) continue;
    select.append(el('option', { value: p.id }, p.name + (p.archived ? ' (archived)' : '')));
  }
  select.value = selected == null ? (includeAll ? '' : '0') : String(selected);
}

// ---------- tabs ----------
function showTab(name) {
  document.querySelectorAll('.tab').forEach((b) => b.dataset.tab === name ? b.setAttribute('aria-current', 'page') : b.removeAttribute('aria-current'));
  document.querySelectorAll('.tab-panel').forEach((p) => { p.hidden = p.id !== 'tab-' + name; });
  try { localStorage.setItem('beat-tab', name); } catch {}
  if (name === 'reports') renderReport();
  if (name === 'projects') renderProjects();
}
document.querySelectorAll('.tab').forEach((b) => b.addEventListener('click', () => showTab(b.dataset.tab)));

// ---------- loading ----------
async function loadState() {
  const data = await api('state');
  state.projects = data.projects;
  state.running = data.running;
  fillProjectSelect($('#start-form select'), { selected: Number($('#start-form select').value) || null });
  renderRunning();
}

async function loadLog() {
  const from = addDays(startOfDay(new Date()), -(state.logDays - 1));
  const data = await api(`timers&from=${encodeURIComponent(from.toISOString())}`);
  state.entries = data.timers;
  renderLog();
}

async function refresh() {
  try { await Promise.all([loadState(), loadLog()]); }
  catch (e) { toast(e.message); }
}

// ---------- running timers ----------
function renderRunning() {
  const box = $('#running');
  box.replaceChildren();
  const now = Date.now();
  for (const t of state.running) {
    box.append(el('div', { class: 'run live' },
      el('div', { class: 'lightbar', 'aria-hidden': 'true' }, el('span'), el('span')),
      el('div', { class: 'run-body' },
        el('div', { class: 'run-clock', 'data-start': t.start_time }, clock(seconds(t, now))),
        el('div', { class: 'run-meta' },
          t.description ? el('span', { class: 'desc' }, t.description) : null,
          projectLabel(t.project_id),
          el('span', { class: 'muted' }, 'since ' + timeFmt.format(new Date(t.start_time)))),
        el('div', { class: 'run-actions' },
          el('button', { class: 'btn ghost', onclick: () => openEntry(t) }, 'Edit'),
          el('button', { class: 'btn halt', onclick: () => stopTimer(t.id) }, 'Stop')))));
  }
  if (state.running.length > 1) {
    box.append(el('div', { class: 'stop-all' },
      el('button', { class: 'btn ghost', onclick: stopAll }, `Stop all ${state.running.length} timers`)));
  }
  document.title = state.running.length ? `${clock(seconds(state.running[0]))} · Beat` : 'Beat';
}

function tick() {
  const now = Date.now();
  document.querySelectorAll('.run-clock').forEach((c) => { c.textContent = clock((now - Date.parse(c.dataset.start)) / 1000); });
  document.querySelectorAll('.entry.running .dur').forEach((d) => { d.textContent = hm((now - Date.parse(d.dataset.start)) / 1000); });
  if (state.running.length) document.title = `${clock(seconds(state.running[0], now))} · Beat`;
}
setInterval(tick, 1000);

$('#start-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  try {
    await api('timer_start', { description: f.description.value, project_id: Number(f.project_id.value) || null });
    f.description.value = '';
    await refresh();
  } catch (x) { toast(x.message); }
});

async function stopTimer(id) {
  try { await api('timer_stop', { id }); toast('Timer stopped'); await refresh(); }
  catch (x) { toast(x.message); }
}
async function stopAll() {
  try { await api('timer_stop_all', {}); toast('All timers stopped'); await refresh(); }
  catch (x) { toast(x.message); }
}

// ---------- log ----------
function renderLog() {
  const box = $('#log');
  box.replaceChildren();
  if (!state.entries.length) {
    box.append(el('div', { class: 'empty-state' },
      el('p', {}, `No entries in the last ${state.logDays} days.`),
      el('p', {}, 'Type what you’re working on above and press Start.')));
    return;
  }
  const groups = new Map();
  for (const t of state.entries) {
    const k = dayKey(new Date(t.start_time));
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(t);
  }
  const today = dayKey(new Date()), yesterday = dayKey(addDays(new Date(), -1));
  for (const [k, list] of groups) {
    const d = new Date(list[0].start_time);
    const label = k === today ? 'Today' : k === yesterday ? 'Yesterday' : dayFmt.format(d);
    const total = list.reduce((s, t) => s + seconds(t), 0);
    box.append(el('section', { class: 'day' },
      el('div', { class: 'day-head' }, el('h3', {}, label), el('span', { class: 'day-total' }, hm(total))),
      list.map((t) => {
        const p = project(t.project_id);
        const running = !t.end_time;
        return el('button', { class: 'entry' + (running ? ' running' : ''), onclick: () => openEntry(t) },
          el('span', { class: 'dot', style: `background:${p ? p.color : 'var(--line)'}` }),
          el('span', { class: 'what' },
            el('span', { class: 'desc' + (t.description ? '' : ' empty') }, t.description || 'No description'),
            el('span', { class: 'proj' }, p ? p.name : 'No project')),
          el('span', { class: 'when' },
            timeFmt.format(new Date(t.start_time)) + ' – ' + (running ? 'now' : timeFmt.format(new Date(t.end_time)))),
          el('span', { class: 'dur', 'data-start': t.start_time }, hm(seconds(t))));
      })));
  }
}

$('#load-more').addEventListener('click', async () => {
  state.logDays += 30;
  try { await loadLog(); } catch (x) { toast(x.message); }
});

// ---------- entry dialog ----------
let editing = null;
function openEntry(t) {
  editing = t;
  const f = $('#entry-form');
  $('#entry-title').textContent = t ? 'Edit entry' : 'Add entry';
  $('#entry-delete').hidden = !t;
  f.description.value = t ? t.description : '';
  f.notes.value = t ? t.notes : '';
  fillProjectSelect(f.project_id, { selected: t ? t.project_id : null });
  const now = new Date();
  f.start_time.value = toLocalInput(t ? t.start_time : new Date(now - 3600e3).toISOString());
  f.end_time.value = toLocalInput(t ? t.end_time : now.toISOString());
  $('.error', f).textContent = '';
  $('#entry-dialog').showModal();
}
$('#add-entry').addEventListener('click', () => openEntry(null));

$('#entry-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  try {
    await api('timer_save', {
      id: editing ? editing.id : null,
      description: f.description.value,
      notes: f.notes.value,
      project_id: Number(f.project_id.value) || null,
      start_time: fromLocalInput(f.start_time.value),
      end_time: fromLocalInput(f.end_time.value),
    });
    $('#entry-dialog').close();
    toast(editing ? 'Entry saved' : 'Entry added');
    await refresh();
  } catch (x) { $('.error', f).textContent = x.message; }
});

$('#entry-delete').addEventListener('click', async () => {
  if (!editing || !confirm('Delete this entry? This can’t be undone.')) return;
  try {
    await api('timer_delete', { id: editing.id });
    $('#entry-dialog').close();
    toast('Entry deleted');
    await refresh();
  } catch (x) { $('.error', $('#entry-form')).textContent = x.message; }
});

document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));

// ---------- reports ----------
function reportRange() {
  const f = $('#report-form');
  const today = startOfDay(new Date());
  const dow = (today.getDay() + 6) % 7; // Monday = 0
  let from, to;
  switch (f.preset.value) {
    case 'week': from = addDays(today, -dow); to = addDays(from, 7); break;
    case 'lastweek': to = addDays(today, -dow); from = addDays(to, -7); break;
    case 'month': from = new Date(today.getFullYear(), today.getMonth(), 1); to = new Date(today.getFullYear(), today.getMonth() + 1, 1); break;
    case 'lastmonth': from = new Date(today.getFullYear(), today.getMonth() - 1, 1); to = new Date(today.getFullYear(), today.getMonth(), 1); break;
    case 'year': from = new Date(today.getFullYear(), 0, 1); to = new Date(today.getFullYear() + 1, 0, 1); break;
    default:
      from = f.from.value ? new Date(f.from.value + 'T00:00') : addDays(today, -30);
      to = addDays(f.to.value ? new Date(f.to.value + 'T00:00') : today, 1);
  }
  if (f.preset.value !== 'custom') { f.from.value = dayKey(from); f.to.value = dayKey(addDays(to, -1)); }
  return { from, to, projects: f.project.value };
}

function reportQuery() {
  const { from, to, projects } = reportRange();
  return `from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}&projects=${projects}`;
}

async function renderReport() {
  const f = $('#report-form');
  const sel = f.project.value;
  fillProjectSelect(f.project, { includeAll: true, includeArchived: true, selected: sel === '' ? null : Number(sel) });
  if (sel === '') f.project.value = '';
  const { from, to } = reportRange();
  const box = $('#report');
  let timers;
  try { timers = (await api('timers&' + reportQuery())).timers; }
  catch (x) { toast(x.message); return; }

  box.replaceChildren();
  if (!timers.length) {
    box.append(el('div', { class: 'empty-state' }, el('p', {}, 'No time tracked in this period.')));
    return;
  }
  const now = Date.now();
  const total = timers.reduce((s, t) => s + seconds(t, now), 0);
  const byProject = new Map();
  for (const t of timers) byProject.set(t.project_id, (byProject.get(t.project_id) || 0) + seconds(t, now));
  const rows = [...byProject].sort((a, b) => b[1] - a[1]);
  const daysSpan = Math.round((to - from) / 86400e3);

  box.append(
    el('div', { class: 'totals' },
      el('div', {}, el('div', { class: 'big' }, hm(total)), el('div', { class: 'sub' }, 'hours tracked')),
      el('div', {}, el('div', { class: 'big' }, String(timers.length)), el('div', { class: 'sub' }, timers.length === 1 ? 'entry' : 'entries')),
      el('div', {}, el('div', { class: 'big' }, String(rows.length)), el('div', { class: 'sub' }, rows.length === 1 ? 'project' : 'projects'))),
    el('div', { class: 'bars' }, rows.map(([pid, s]) => {
      const p = project(pid);
      const color = p ? p.color : 'var(--muted)';
      return el('div', { class: 'bar-row' },
        el('div', { class: 'name' }, el('span', { class: 'dot', style: `background:${color}` }), el('span', {}, p ? p.name : 'No project')),
        el('div', { class: 'val' }, hm(s), el('small', {}, Math.round((s / total) * 100) + '%')),
        el('div', { class: 'bar-track' }, el('div', { class: 'bar-fill', style: `width:${(s / rows[0][1]) * 100}%;background:${color}` })));
    })));

  if (daysSpan > 1 && daysSpan <= 62) {
    const perDay = new Map();
    for (const t of timers) { const k = dayKey(new Date(t.start_time)); perDay.set(k, (perDay.get(k) || 0) + seconds(t, now)); }
    const days = Array.from({ length: daysSpan }, (_, i) => addDays(from, i));
    const max = Math.max(...perDay.values());
    box.append(el('div', { class: 'days-chart' },
      el('h3', {}, 'Hours per day'),
      el('div', { class: 'cols' }, days.map((d) => {
        const s = perDay.get(dayKey(d)) || 0;
        return el('div', { style: `height:${(s / max) * 100}%`, title: `${dayFmt.format(d)}: ${hm(s)}` });
      })),
      el('div', { class: 'cols-axis' }, el('span', {}, shortDay.format(days[0])), el('span', {}, shortDay.format(days[days.length - 1])))));
  }
}

$('#report-form').addEventListener('change', (e) => {
  if (e.target.type === 'date') e.currentTarget.preset.value = 'custom';
  renderReport();
});
$('#export-csv').addEventListener('click', () => {
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  location.href = `api.php?action=export&${reportQuery()}&tz=${encodeURIComponent(tz)}`;
});

// ---------- projects ----------
function renderProjects() {
  const box = $('#projects');
  box.replaceChildren();
  if (!state.projects.length) {
    box.append(el('div', { class: 'empty-state' }, el('p', {}, 'Add a project to group your time, like a client or a side project.')));
    return;
  }
  const active = state.projects.filter((p) => !p.archived);
  const archived = state.projects.filter((p) => p.archived);
  const row = (p) => el('div', { class: 'project-row' + (p.archived ? ' archived' : '') },
    el('input', { type: 'color', value: p.color, 'aria-label': 'Colour for ' + p.name, onchange: (e) => saveProject({ ...p, color: e.target.value }) }),
    el('input', { type: 'text', value: p.name, 'aria-label': 'Project name', onchange: (e) => saveProject({ ...p, name: e.target.value }) }),
    el('button', { class: 'btn link', onclick: () => saveProject({ ...p, archived: !p.archived }, p.archived ? 'Project restored' : 'Project archived') }, p.archived ? 'Restore' : 'Archive'),
    el('button', { class: 'btn danger-ghost', onclick: () => deleteProject(p) }, 'Delete'));
  box.append(...active.map(row));
  if (archived.length) box.append(el('h3', { class: 'section-label' }, 'Archived'), ...archived.map(row));
}

async function saveProject(p, msg = 'Project saved') {
  try {
    state.projects = (await api('project_save', p)).projects;
    renderProjects();
    fillProjectSelect($('#start-form select'), { selected: Number($('#start-form select').value) || null });
    toast(msg);
    renderLog();
  } catch (x) { toast(x.message); renderProjects(); }
}

async function deleteProject(p) {
  if (!confirm(`Delete “${p.name}”? Its entries are kept but will have no project.`)) return;
  try {
    state.projects = (await api('project_delete', { id: p.id })).projects;
    toast('Project deleted');
    renderProjects();
    await refresh();
  } catch (x) { toast(x.message); }
}

$('#project-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  await saveProject({ name: f.name.value, color: f.color.value }, 'Project added');
  f.name.value = '';
});

// ---------- account ----------
$('#account-btn').addEventListener('click', () => {
  const f = $('#password-form');
  f.reset();
  $('.error', f).textContent = '';
  $('#account-dialog').showModal();
});
$('#password-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  try {
    await api('change_password', { current: f.current.value, new: f.new.value });
    $('#account-dialog').close();
    toast('Password changed. Other devices are signed out.');
  } catch (x) { $('.error', f).textContent = x.message; }
});
$('#logout').addEventListener('click', async () => {
  await api('logout', {}).catch(() => {});
  location.reload();
});

// ---------- boot ----------
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
refresh().then(() => {
  let tab = 'log';
  try { tab = localStorage.getItem('beat-tab') || 'log'; } catch {}
  showTab(['log', 'reports', 'projects'].includes(tab) ? tab : 'log');
});
