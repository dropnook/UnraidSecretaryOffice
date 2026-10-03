/* Ms. Whereabouts — knows where everything is and what is going on:
   shares, folders, containers, compose, VMs, users and access, SMB/NFS,
   user scripts and cron, backups, plugins. She only reads.
   The agent part lives in agent/desks/whereabouts.php. */
(() => {
'use strict';

const ID = 'whereabouts';
const T = Office.scope(ID);
const { el, fmt } = Office;
const SECTIONS = ['shares', 'folders', 'docker', 'vms', 'disks', 'access', 'network', 'scripts', 'backups', 'notices', 'plugins'];

let state = null;
let sizes = { sizes: {}, queue: [], running: [] };
let section = SECTIONS.includes(Office.store('whereabouts.section')) ? Office.store('whereabouts.section') : 'shares';
let query = '';
let view = null;
let expanded = new Set();
let folderSort = Office.store('whereabouts.folder_sort') || 'name';
let onlyUnused = false;
let sizeTimer = null;
let busy = false;

// ------------------------------------------------------------------ loading
async function load(refresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(refresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  await loadSizes();
  if (view) render();
  return j;
}

async function loadSizes() {
  try {
    const j = await Office.api.get({ a: 'part', desk: ID, part: 'sizes' });
    if (j.ok && j.part) sizes = { sizes: j.part.sizes || {}, queue: j.part.queue || [], running: j.part.running || [] };
  } catch (e) { /* keep what we have */ }
  clearTimeout(sizeTimer);
  if (sizes.queue.length || sizes.running.length) {
    sizeTimer = setTimeout(async () => { await loadSizes(); if (view) renderSection(); }, 3000);
  }
}

// ------------------------------------------------------------------ helpers
const words = () => query.trim().toLowerCase().split(/\s+/).filter(Boolean);
function matches(text) {
  const w = words();
  if (!w.length) return true;
  const hay = String(text).toLowerCase();
  return w.every((x) => hay.includes(x));
}
const join = (...parts) => parts.flat(Infinity).filter((x) => x !== null && x !== undefined && x !== '').join(' ');

function chip(text, cls, title) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (title) c.title = title;
  return c;
}

function tempLevel(d) {
  if (d.temp === null || d.temp === undefined) return '';
  if (d.temp >= d.max) return 'danger';
  if (d.temp >= d.hot) return 'warn';
  return '';
}
function fillLevel(d) {
  if (d.fill === null || d.fill === undefined) return '';
  if (d.fill >= d.crit) return 'danger';
  if (d.fill >= d.warn) return 'warn';
  return '';
}
const smartBad = (d) => ((d.smart && d.smart.problems) || []).filter((p) => p.level === 'bad');
const smartNotices = (d) => ((d.smart && d.smart.problems) || []).filter((p) => p.level !== 'bad');

/** Size of a path: from ZFS right away, else what du measured (or is measuring) */
function sizeInfo(path, zfs) {
  if (zfs && zfs.bytes !== undefined) return { bytes: zfs.bytes, how: 'zfs' };
  if (sizes.running.includes(path)) return { how: 'running' };
  if (sizes.queue.includes(path)) return { how: 'queued' };
  const m = sizes.sizes[path];
  if (m && m.bytes !== null && m.bytes !== undefined) return { bytes: m.bytes, how: 'du', at: m.at, partial: m.partial };
  return { how: 'none' };
}

function sizeFigures(info) {
  const box = el('div', 'figures');
  if (info.bytes !== undefined) {
    box.append(el('b', '', fmt.size(info.bytes)), el('span', '', info.how === 'zfs' ? 'ZFS' : T('measured')));
    box.title = info.how === 'du' ? T('measured_at', { when: fmt.relative(info.at) }) + (info.partial ? ' · ' + T('measured_partial') : '') : T('zfs_size');
  } else if (info.how === 'running' || info.how === 'queued') {
    box.append(el('b', '', '…'), el('span', '', info.how === 'running' ? T('measuring') : T('queued')));
  }
  return box;
}

function usedByChips(list, max = 4) {
  const names = new Map();
  for (const u of list) {
    const key = `${u.kind}:${u.name}`;
    if (!names.has(key)) names.set(key, u);
  }
  const out = [];
  [...names.values()].slice(0, max).forEach((u) => out.push(chip(`${kindIcon(u.kind)} ${u.name}`, u.kind === 'container' || u.kind === 'compose' ? 'accent' : '', T('kind.' + u.kind))));
  if (names.size > max) out.push(chip(`+${names.size - max}`, 'quiet'));
  return out;
}

function kindIcon(kind) {
  return { container: '🐳', compose: '🧩', template: '📄', vm: '🖥️', script: '📜', backup: '🛟' }[kind] || '•';
}

function kv(pairs) {
  const dl = el('dl', 'kv');
  for (const [k, v, mono] of pairs) {
    if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) continue;
    dl.appendChild(el('dt', '', k));
    const dd = el('dd', mono ? 'mono' : '');
    if (v instanceof Node) dd.appendChild(v);
    else dd.textContent = Array.isArray(v) ? v.join(', ') : String(v);
    dl.appendChild(dd);
  }
  return dl;
}

function lines(list) {
  const box = el('div', 'mono');
  box.style.whiteSpace = 'pre-line';
  box.textContent = list.join('\n');
  return box;
}

/** A list row: click the name to unfold the details */
function row({ key, name, mono, meta, figures, detail, menu, cls }) {
  const r = el('div', 'row nocheck' + (cls ? ' ' + cls : ''));
  const main = el('div', 'row-main');
  const n = el('div', 'row-name link' + (mono ? '' : ' text'), name);
  main.appendChild(n);
  const m = el('div', 'row-meta');
  (meta || []).filter(Boolean).forEach((x) => m.append(x));
  main.appendChild(m);
  r.appendChild(main);
  r.appendChild(figures || el('div'));
  const more = el('button', 'more', '⋯');
  more.type = 'button';
  more.setAttribute('aria-label', T('actions'));
  if (menu) {
    more.onclick = (e) => { e.stopPropagation(); Office.menu(e, menu()); };
    r.oncontextmenu = (e) => { e.preventDefault(); Office.menu(e, menu()); };
  } else {
    more.disabled = true;
  }
  r.appendChild(more);
  let box = null;
  const toggle = () => {
    if (box) { box.remove(); box = null; expanded.delete(key); return; }
    box = el('div', 'row-detail');
    box.appendChild(detail());
    r.appendChild(box);
    expanded.add(key);
  };
  if (detail) {
    n.onclick = toggle;
    n.title = T('details');
    if (expanded.has(key)) toggle();
  } else {
    n.classList.remove('link');
  }
  return r;
}

function group(title, meta, rows, opts = {}) {
  const box = el('div', 'group' + (opts.closed ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  const mid = el('div', 'group-mid');
  const tl = el('div', 'group-title');
  tl.append(el('span', '', title));
  (opts.chips || []).forEach((c) => tl.append(c));
  mid.append(tl, el('div', 'group-meta', meta));
  head.append(el('span', 'group-arrow', '▼'), mid);
  if (opts.button) head.appendChild(opts.button);
  const body = el('div', 'group-rows');
  rows.forEach((x) => body.appendChild(x));
  head.onclick = (e) => { if (e.target.closest('button')) return; box.classList.toggle('closed'); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); box.classList.toggle('closed'); } };
  box.append(head, body);
  return box;
}

function emptyNote(text) {
  const p = el('p', 'empty');
  p.textContent = text;
  return p;
}

function scheduleText(freq, cron) {
  if (freq === 'custom') return cron ? fmt.cron(cron) : T('sched.custom');
  return T('sched.' + freq) !== `${ID}.sched.${freq}` ? T('sched.' + freq) : freq;
}

async function measure(paths, wakes) {
  const go = async () => {
    const j = await Office.api.post(`${ID}.measure`, { paths });
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
    sizes = { sizes: j.sizes.sizes || {}, queue: j.sizes.queue || [], running: j.sizes.running || [] };
    renderSection();
    await loadSizes();
  };
  if (!wakes) { go(); return; }
  Office.dialog({
    title: T('measure.title'),
    body: T('measure.wakes'),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('measure.go'), kind: '', act: go }],
  });
}

// ------------------------------------------------------------------ findings
function findings() {
  if (!state) return [];
  const f = [];
  const appdata = (state.folders || []).find((x) => x.appdata);
  const unused = appdata ? appdata.folders.filter((x) => !x.used_by.length).length : 0;
  if (unused) f.push({ text: T('find.unused', { n: unused, share: appdata.share }), go: () => { onlyUnused = true; pick('folders'); } });
  const missingPool = state.shares.filter((s) => s.storage.missing);
  if (missingPool.length) f.push({ text: T('find.missing_pool', { n: missingPool.length, names: missingPool.map((s) => s.name).join(', ') }), go: () => pick('shares', missingPool[0].name) });
  const deadRefs = state.scripts.filter((s) => s.missing);
  if (deadRefs.length) f.push({ text: T('find.dead_refs', { n: deadRefs.length, names: deadRefs.map((s) => s.name).join(', ') }), go: () => pick('scripts', deadRefs[0].name) });
  const lonely = state.templates.filter((x) => !x.container);
  if (lonely.length) f.push({ text: T('find.lonely_templates', { n: lonely.length }), go: () => pick('docker') });
  const stopped = state.containers.filter((c) => c.autostart && c.state !== 'running');
  if (stopped.length) f.push({ text: T('find.autostart_stopped', { n: stopped.length, names: stopped.map((c) => c.name).join(', ') }), go: () => pick('docker') });
  const stale = state.scripts.filter((s) => s.stale);
  if (stale.length) f.push({ text: T('find.stale', { n: stale.length }), go: () => pick('scripts') });
  const h = state.health;
  if (h) {
    const hot = h.devices.filter((d) => tempLevel(d) !== '');
    if (hot.length) f.push({ text: T('find.hot', { n: hot.length, names: hot.map((d) => `${d.name} ${d.temp} °C`).join(', ') }), go: () => pick('disks') });
    const bad = h.devices.filter((d) => smartBad(d).length || d.errors || (d.status && d.status !== 'DISK_OK'));
    if (bad.length) f.push({ text: T('find.smart_bad', { n: bad.length, names: bad.map((d) => d.name).join(', ') }), go: () => pick('disks') });
    const full = h.devices.filter((d) => fillLevel(d) === 'danger');
    if (full.length) f.push({ text: T('find.full', { n: full.length, names: full.map((d) => `${d.name} ${fmt.number(d.fill)} %`).join(', ') }), go: () => pick('disks') });
    if (h.parity.slots && !h.parity.present) f.push({ text: T('find.no_parity'), go: () => pick('disks') });
  }
  const alerts = (state.notices || []).filter((n) => n.importance === 'alert');
  if (alerts.length) f.push({ text: T('find.alerts', { n: alerts.length }), go: () => pick('notices') });
  const lic = state.license;
  if (lic && lic.expires && lic.expires - Date.now() / 1000 < 30 * 86400) f.push({ text: T('find.license_expires', { when: fmt.relative(lic.expires) }), go: () => pick('disks') });
  if (lic && lic.check) f.push({ text: T('find.license_check', { detail: lic.check }), go: () => pick('disks') });
  return f;
}

function pick(id, search) {
  section = id;
  Office.store('whereabouts.section', id);
  if (search !== undefined) { query = search; if (view) view.search.value = search; }
  if (view) { renderTabs(); renderSection(); view.sectionBox.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}

function bubble() {
  const box = el('span');
  if (!state) { box.append(Office.agent.running ? T('bubble.no_tour') : T('bubble.no_data')); return box; }
  box.append(T('bubble.summary', { shares: state.shares.length, containers: state.containers.length, scripts: state.scripts.length }), ' ');
  const f = findings();
  if (!f.length) { box.append(T('bubble.tidy')); return box; }
  box.append(T('bubble.noticed'), ' ');
  f.forEach((x, i) => {
    const a = el('a', '', x.text);
    a.href = '#';
    a.onclick = (e) => { e.preventDefault(); x.go(); };
    box.append(a, i < f.length - 1 ? ' · ' : '.');
  });
  return box;
}

// ------------------------------------------------------------------ the desk
Office.desk({
  id: ID,

  mount(root) {
    view = build(root);
    render();
    load(false);
  },

  unmount() { view = null; clearTimeout(sizeTimer); },

  poll() { load(false); },

  agentChanged() { if (view) { view.tourBtn.disabled = !Office.agent.running || busy; } },

  menu() {
    return [{ text: T('tour'), act: tour, disabled: !Office.agent.running || busy }];
  },

  async reception() {
    if (!state) await load(false);
    if (!state) return { bubble: T('bubble.no_data'), facts: [] };
    const sys = state.system;
    const facts = [
      T('fact.docker', { running: sys.docker.running, total: sys.docker.total }),
      T('fact.vms', { running: sys.vms.running, total: sys.vms.total }),
      T('fact.smb', { n: state.smb.sessions.length }),
    ];
    if (sys.scripts.length) facts.push(T('fact.scripts_running', { n: sys.scripts.length, names: sys.scripts.join(', ') }));
    const b = el('span');
    b.append(T('bubble.summary', { shares: state.shares.length, containers: state.containers.length, scripts: state.scripts.length }));
    const f = findings();
    if (f.length) b.append(' ', T('bubble.findings', { n: f.length }));
    return { bubble: b, facts };
  },
});

// ------------------------------------------------------------------ building
function build(root) {
  const v = {};
  v.tourBtn = el('button', 'btn plain');
  v.tourBtn.type = 'button';
  v.tourBtn.append(el('span', 'spin'), T('tour'));
  v.tourBtn.title = T('tour_title');
  v.tourBtn.onclick = tour;
  v.search = el('input', 'search');
  v.search.type = 'search';
  v.search.placeholder = T('search');
  v.search.autocomplete = 'off';
  v.search.spellcheck = false;
  v.search.value = query;
  v.search.style.minWidth = '220px';
  v.search.oninput = () => { query = v.search.value; renderTabs(); renderSection(); };
  const head = Office.deskHead({ id: ID, icon: Office.desks.get(ID).icon }, { actions: [v.search, v.tourBtn] });
  v.bubble = head.bubble;
  root.appendChild(head.head);

  const now = el('section', 'section');
  const nh = el('div', 'section-head');
  v.nowHint = el('span', 'hint');
  nh.append(el('h2', '', T('now')), v.nowHint);
  v.stats = el('div', 'stats');
  now.append(nh, v.stats);
  root.appendChild(now);

  v.sectionBox = el('section', 'section');
  v.tabs = el('div', 'seg');
  v.sectionBody = el('div', 'section');
  v.sectionBox.append(v.tabs, v.sectionBody);
  root.appendChild(v.sectionBox);
  return v;
}

function render() {
  if (!view) return;
  view.bubble.innerHTML = '';
  view.bubble.appendChild(bubble());
  view.tourBtn.disabled = !Office.agent.running || busy;
  renderStats();
  renderTabs();
  renderSection();
}

async function tour() {
  if (busy) return;
  busy = true;
  if (view) { view.tourBtn.classList.add('running'); view.tourBtn.disabled = true; }
  const j = await Office.api.post(`${ID}.scan`, {});
  busy = false;
  if (view) view.tourBtn.classList.remove('running');
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); if (view) render(); return; }
  state = j.state;
  if (view) render();
  Office.toast(T('tour_done', { ms: state.duration_ms }));
}

// ------------------------------------------------------------------ what's going on
function renderStats() {
  const box = view.stats;
  box.innerHTML = '';
  if (!state) return;
  const sys = state.system;
  view.nowHint.textContent = `${sys.name} · Unraid ${sys.unraid || '?'} · ${T('uptime', { time: fmt.duration(sys.uptime) })} · ${Office.t('common.scanned_ago', { when: fmt.relative(state.time) })}`;
  const tile = (label, value, sub, opts = {}) => {
    const t = el(opts.go ? 'button' : 'div', 'stat' + (opts.alert ? ' alert' : ''));
    if (opts.go) { t.type = 'button'; t.onclick = opts.go; }
    t.append(el('div', 'stat-label', label), el('div', 'stat-value', value));
    if (sub) t.append(el('div', 'stat-sub', sub));
    box.appendChild(t);
  };
  const array = sys.array;
  tile(T('stat.array'), array.state === 'STARTED' ? T('stat.started') : (array.state || '?'),
    array.resync ? T('stat.resync', { action: array.resync.action, percent: array.resync.percent }) : (sys.mover ? T('stat.mover') : T('stat.no_parity')),
    { alert: array.state !== 'STARTED' || !!array.resync });
  tile('Docker', `${sys.docker.running} / ${sys.docker.total}`, T('stat.running'), { go: () => pick('docker') });
  tile('VMs', `${sys.vms.running} / ${sys.vms.total}`, T('stat.running'), { go: () => pick('vms') });
  const sessions = state.smb.sessions;
  tile(T('stat.smb'), fmt.number(sessions.length), sessions.map((s) => `${s.user}@${s.machine}`).join(', ') || T('stat.nobody'), { go: () => pick('network') });
  tile(T('stat.scripts'), fmt.number(sys.scripts.length), sys.scripts.join(', ') || T('stat.none_running'), { go: () => pick('scripts'), alert: sys.scripts.length > 0 });
  tile(T('stat.backup'), sys.backup.running ? T('stat.backup_running') : T('stat.backup_idle'),
    sys.backup.running ? `${T('stat.since', { time: fmt.time(sys.backup.since) })} · ${sys.backup.step || ''}` : '', { go: () => pick('backups'), alert: sys.backup.running });
  const h = state.health;
  if (h) {
    const awake = h.devices.filter((d) => d.temp !== null && d.temp !== undefined);
    const hottest = awake.reduce((a, d) => (!a || d.temp > a.temp ? d : a), null);
    const warm = awake.filter((d) => tempLevel(d) !== '');
    tile(T('stat.temp'), hottest ? `${hottest.temp} °C` : '–', hottest ? (warm.length ? T('stat.temp_warm', { n: warm.length }) : T('stat.temp_ok', { name: hottest.name })) : T('stat.all_asleep'),
      { go: () => pick('disks'), alert: warm.length > 0 });
    const bad = h.devices.filter((d) => smartBad(d).length).length;
    const notes = h.devices.filter((d) => smartNotices(d).length).length;
    tile('SMART', bad ? T('stat.smart_bad', { n: bad }) : T('stat.smart_ok'), notes ? T('stat.smart_notes', { n: notes }) : '',
      { go: () => pick('disks'), alert: bad > 0 });
  }
  const notices = state.notices || [];
  if (notices.length) {
    const alerts = notices.filter((n) => n.importance === 'alert').length;
    const warnings = notices.filter((n) => n.importance === 'warning').length;
    tile(T('stat.notices'), fmt.number(notices.length), T('stat.notices_sub', { alerts, warnings }), { go: () => pick('notices'), alert: alerts > 0 });
  }
  const lic = state.license;
  if (lic && lic.type) tile(T('stat.license'), lic.type, lic.expires ? T('stat.license_until', { when: fmt.date(lic.expires) }) : T('stat.license_since', { when: fmt.date(lic.since) }),
    { go: () => pick('disks'), alert: !!lic.check });
  if (sys.scrubs.length) tile(T('stat.scrub'), sys.scrubs.map((s) => s.pool).join(', '), sys.scrubs.map((s) => s.text).join(' · '), { alert: true });
  if (sys.load) tile(T('stat.load'), sys.load.map((x) => fmt.number(x, 1)).join(' · '), T('stat.load_sub'));
}

// ------------------------------------------------------------------ sections
function sectionCounts() {
  if (!state) return {};
  const appdata = state.appdata;
  return {
    shares: state.shares.filter(shareMatches).length,
    folders: state.folders.reduce((a, f) => a + f.folders.filter((x) => folderMatches(f, x)).length, 0),
    docker: state.containers.filter(containerMatches).length,
    vms: state.vms.filter((v) => matches(join(v.name, v.state, v.disks))).length,
    access: state.users.filter((u) => matches(join(u.name, u.description))).length,
    network: state.smb.sessions.filter((s) => matches(join(s.user, s.machine, s.shares.map((x) => x.share)))).length,
    scripts: state.scripts.filter(scriptMatches).length + state.cron.filter((c) => matches(join(c.title, c.schedule, c.command, c.source))).length,
    backups: state.backups.filter((b) => matches(join(b.kind, b.title, b.paths))).length,
    plugins: state.plugins.filter((p) => matches(join(p.name, p.version, p.author))).length,
    disks: state.health ? state.health.devices.filter(diskMatches).length : 0,
    notices: (state.notices || []).filter((n) => matches(join(n.subject, n.description, n.event, n.importance))).length,
    appdata,
  };
}

function renderTabs() {
  const seg = view.tabs;
  seg.innerHTML = '';
  const counts = sectionCounts();
  for (const id of SECTIONS) {
    const b = el('button', '', T('section.' + id));
    b.type = 'button';
    b.setAttribute('aria-pressed', String(section === id));
    if (counts[id] !== undefined) b.append(el('span', 'count', fmt.number(counts[id])));
    b.onclick = () => pick(id);
    seg.appendChild(b);
  }
}

function renderSection() {
  if (!view) return;
  const body = view.sectionBody;
  body.innerHTML = '';
  if (!state) { body.appendChild(emptyNote(Office.agent.running ? T('bubble.no_tour') : T('bubble.no_data'))); return; }
  ({ shares, folders, docker, vms, disks, access, network, scripts, backups, notices, plugins })[section](body);
}

// --------------------------------------------------------------- shares
function shareMatches(s) {
  return matches(join(s.name, s.comment, s.storage.primary, s.storage.secondary, s.storage.pools, s.storage.disks, s.storage.dataset,
    s.smb.read, s.smb.write, s.used_by.map((u) => [u.name, u.path])));
}

function storageText(st) {
  const where = st.primary === 'array' ? T('array') : st.primary;
  if (!st.secondary) return where;
  return `${where} → ${st.secondary === 'array' ? T('array') : st.secondary}`;
}

function smbChip(s) {
  if (s.smb.export === '-' || !s.smb.export) return null;
  const sec = T('security.' + s.smb.security);
  return chip(`SMB · ${sec}${s.smb.export === 'eh' ? ' · ' + T('hidden') : ''}`, '', T('smb_title', { read: s.smb.read.join(', ') || '–', write: s.smb.write.join(', ') || '–' }));
}

function shares(body) {
  const list = state.shares.filter(shareMatches);
  const box = el('div', 'box');
  if (!list.length) box.appendChild(emptyNote(T('nothing_found')));
  for (const s of list) {
    const st = s.storage;
    const info = sizeInfo(s.measure, s.size);
    const wakes = st.primary === 'array' || st.secondary === 'array';
    box.appendChild(row({
      key: 'share:' + s.name,
      name: s.name,
      meta: [
        s.comment ? el('span', 'note', s.comment) : null,
        chip(storageText(st), st.missing ? 'danger' : '', st.missing ? T('missing_pool', { pool: st.primary }) : null),
        st.exclusive ? chip(T('exclusive'), 'quiet', T('exclusive_title')) : null,
        smbChip(s),
        s.nfs.export === 'e' ? chip('NFS', '') : null,
        s.smb.timemachine_limit ? chip('Time Machine', 'quiet') : null,
        ...usedByChips(s.used_by),
        !s.has_cfg ? chip(T('no_cfg'), 'warn', T('no_cfg_title')) : null,
      ],
      figures: sizeFigures(info),
      detail: () => kv([
        [T('path'), `/mnt/user/${s.name}`, true],
        [T('real_path'), st.real, true],
        [T('dataset'), st.dataset, true],
        [T('use_cache'), T('cache.' + st.use_cache) + ` (${st.use_cache})`],
        [T('found_on'), [...st.pools, ...st.disks]],
        [T('unknown_on'), st.unknown.length ? `${st.unknown.join(', ')} — ${T('asleep_unknown')}` : null],
        [T('include'), st.include],
        [T('exclude'), st.exclude],
        ['SMB', s.smb.export === '-' ? T('not_exported') : `${T('security.' + s.smb.security)} · ${T('read')}: ${s.smb.read.join(', ') || '–'} · ${T('write')}: ${s.smb.write.join(', ') || '–'}`],
        ['NFS', s.nfs.export === 'e' ? `${T('security.' + s.nfs.security)} ${s.nfs.hosts}` : T('not_exported')],
        [T('used_by'), s.used_by.length ? lines(s.used_by.map((u) => `${kindIcon(u.kind)} ${u.name}: ${u.path}${u.detail ? ' → ' + u.detail : ''}`)) : T('nobody')],
      ]),
      menu: () => [
        { text: info.bytes !== undefined && info.how === 'zfs' ? T('size_known') : T('measure.one'), act: () => measure([s.measure], wakes), disabled: info.how === 'zfs' || !Office.agent.running },
        { text: Office.t('common.copy_path'), act: () => Office.copy(`/mnt/user/${s.name}`) },
      ],
    }));
  }
  body.appendChild(box);
}

// --------------------------------------------------------------- folders
function folderMatches(f, x) {
  if (onlyUnused && (!f.appdata || x.used_by.length)) return false;
  return matches(join(f.share, x.name, x.path, x.dataset, x.used_by.map((u) => [u.name, u.kind])));
}

function folders(body) {
  const bar = el('div', 'toolbar');
  const sort = el('div', 'seg');
  for (const id of ['name', 'size']) {
    const b = el('button', '', T('sort.' + id));
    b.type = 'button';
    b.setAttribute('aria-pressed', String(folderSort === id));
    b.onclick = () => { folderSort = id; Office.store('whereabouts.folder_sort', id); renderSection(); };
    sort.appendChild(b);
  }
  const unused = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = onlyUnused;
  cb.onchange = () => { onlyUnused = cb.checked; renderTabs(); renderSection(); };
  unused.append(cb, el('span', '', T('only_unused', { share: state.appdata || 'appdata' })));
  bar.append(sort, unused, el('span', 'hint', T('folders_hint')));
  body.appendChild(bar);

  const box = el('div', 'box');
  let any = false;
  for (const f of state.folders) {
    let list = f.folders.filter((x) => folderMatches(f, x));
    if (!list.length) continue;
    any = true;
    const sizeOf = (x) => { const i = sizeInfo(x.real, x.size); return i.bytes ?? -1; };
    list = [...list].sort(folderSort === 'size' ? (a, b) => sizeOf(b) - sizeOf(a) : (a, b) => a.name.localeCompare(b.name, Office.lang, { sensitivity: 'base' }));
    const unknown = list.filter((x) => sizeInfo(x.real, x.size).how === 'none').map((x) => x.real);
    const all = el('button', 'btn plain small', T('measure.all', { n: unknown.length }));
    all.type = 'button';
    all.disabled = !unknown.length || !Office.agent.running;
    all.onclick = (e) => { e.stopPropagation(); measure(unknown, false); };
    const rows = list.map((x) => {
      const info = sizeInfo(x.real, x.size);
      return row({
        key: 'folder:' + x.real,
        name: x.name,
        mono: true,
        meta: [
          ...(x.used_by.length ? usedByChips(x.used_by) : [f.appdata ? chip(T('unused'), 'warn', T('unused_title')) : null]),
          x.dataset ? chip('ZFS', 'quiet', x.dataset) : null,
          x.modified ? el('span', '', T('changed', { when: fmt.relative(x.modified) })) : null,
        ],
        figures: sizeFigures(info),
        detail: () => kv([
          [T('path'), x.path, true],
          [T('real_path'), x.real, true],
          [T('dataset'), x.dataset, true],
          [T('used_by'), x.used_by.length ? lines(x.used_by.map((u) => `${kindIcon(u.kind)} ${u.name}: ${u.path}${u.detail ? ' → ' + u.detail : ''}`)) : T('nobody')],
        ]),
        menu: () => [
          { text: T('measure.one'), act: () => measure([x.real], false), disabled: info.how === 'zfs' || !Office.agent.running },
          { text: Office.t('common.copy_path'), act: () => Office.copy(x.path) },
        ],
      });
    });
    const meta = [f.base, T('folder_count', { n: f.folders.length }), f.files ? T('file_count', { n: f.files }) : null, f.cut ? T('cut') : null].filter(Boolean).join(' · ');
    box.appendChild(group(f.share, meta, rows, { button: all, chips: f.appdata ? [chip('appdata', 'accent')] : [] }));
  }
  if (!any) box.appendChild(emptyNote(T('nothing_found')));
  body.appendChild(box);
  body.appendChild(el('p', 'hint', T('folders_array_note')));
}

// --------------------------------------------------------------- docker
function containerMatches(c) {
  return matches(join(c.name, c.image, c.state, c.managed, c.compose && c.compose.project, c.networks.map((n) => [n.name, n.ip]),
    c.mounts.map((m) => [m.source, m.dest])));
}

function containerRow(c) {
  const ips = c.networks.map((n) => n.ip ? `${n.name} ${n.ip}` : n.name).join(', ');
  const ports = c.ports.filter((p) => p.host).map((p) => `${p.host}→${p.container}`);
  return row({
    key: 'container:' + c.name,
    name: c.name,
    meta: [
      chip(c.state === 'running' ? T('state.running') : c.state, c.state === 'running' ? 'ok' : 'quiet'),
      el('span', 'mono', c.image),
      c.autostart ? chip(T('autostart'), 'quiet') : null,
      c.privileged ? chip(T('privileged'), 'warn', T('privileged_title')) : null,
      ips ? el('span', '', ips) : null,
      ports.length ? el('span', '', ports.join(' ')) : null,
    ],
    detail: () => kv([
      [T('image'), c.image, true],
      [T('managed'), c.managed === 'compose' ? `Compose: ${c.compose.project}` : c.managed === 'template' ? T('managed.template') : T('managed.other')],
      [T('template'), c.template, true],
      [T('compose_files'), c.compose ? lines(c.compose.files) : null],
      [T('restart'), c.restart || '–'],
      [T('network'), ips],
      [T('ports'), c.ports.map((p) => `${p.ip || ''}${p.host ? ':' + p.host : ''} → ${p.container}`)],
      [T('mounts'), c.mounts.length ? lines(c.mounts.map((m) => `${m.source} → ${m.dest}${m.rw ? '' : ' (ro)'}`)) : null],
      ['WebUI', c.webui, true],
    ]),
    menu: () => [{ text: T('copy_name'), act: () => Office.copy(c.name) }],
  });
}

function docker(body) {
  const box = el('div', 'box');
  const list = state.containers.filter(containerMatches);
  const byProject = new Map();
  const templated = [];
  const other = [];
  for (const c of list) {
    if (c.managed === 'compose') {
      if (!byProject.has(c.compose.project)) byProject.set(c.compose.project, []);
      byProject.get(c.compose.project).push(c);
    } else if (c.managed === 'template') templated.push(c);
    else other.push(c);
  }
  for (const p of state.compose) {
    const members = byProject.get(p.project) || [];
    byProject.delete(p.project);
    if (!members.length && !matches(join(p.name, p.dir, p.files))) continue;
    const meta = [p.dir, p.autostart ? T('autostart') : null, p.env ? '.env' : null, T('container_count', { n: p.containers.length })].filter(Boolean).join(' · ');
    const rows = members.map(containerRow);
    if (!members.length) rows.push(emptyNote(T('compose_down')));
    box.appendChild(group(`🧩 ${p.name}`, meta, rows, { chips: [chip('Compose', 'accent')] }));
  }
  for (const [project, members] of byProject) box.appendChild(group(`🧩 ${project}`, T('compose_unknown'), members.map(containerRow), { chips: [chip('Compose', 'quiet')] }));
  if (templated.length) box.appendChild(group(T('templates_group'), T('container_count', { n: templated.length }), templated.map(containerRow)));
  if (other.length) box.appendChild(group(T('other_group'), T('container_count', { n: other.length }), other.map(containerRow)));
  const lonely = state.templates.filter((x) => !x.container && matches(join(x.name, x.image, x.paths)));
  if (lonely.length) {
    box.appendChild(group(T('lonely_group'), T('lonely_meta'), lonely.map((x) => row({
      key: 'template:' + x.file,
      name: x.name,
      meta: [el('span', 'mono', x.image || ''), chip(T('no_container'), 'warn')],
      detail: () => kv([[T('template'), x.file, true], [T('paths'), x.paths.length ? lines(x.paths) : null]]),
    })), { closed: false }));
  }
  if (!box.childNodes.length) box.appendChild(emptyNote(T('nothing_found')));
  body.appendChild(box);
}

// --------------------------------------------------------------- VMs
function vms(body) {
  const box = el('div', 'box');
  const list = state.vms.filter((v) => matches(join(v.name, v.state, v.disks)));
  if (!list.length) box.appendChild(emptyNote(state.vms.length ? T('nothing_found') : T('no_vms')));
  for (const v of list) {
    const snapChip = v.snapshots ? chip(`📸 ${T('vm_snapshots', { n: v.snapshots })}`, 'accent') : null;
    if (snapChip) { snapChip.style.cursor = 'pointer'; snapChip.onclick = () => Office.go('#/snapshot'); }
    box.appendChild(row({
      key: 'vm:' + v.name,
      name: v.name,
      meta: [
        chip(v.running ? T('state.running') : v.state, v.running ? 'ok' : 'quiet'),
        v.autostart ? chip(T('autostart'), 'quiet') : null,
        v.cpus ? el('span', '', T('vm_cpus', { n: v.cpus })) : null,
        v.memory ? el('span', '', fmt.size(v.memory)) : null,
        snapChip,
      ],
      detail: () => kv([[T('disks'), lines(v.disks)]]),
    }));
  }
  body.appendChild(box);
}

// --------------------------------------------------------------- users & access
function access(body) {
  const exported = state.shares.filter((s) => s.smb.export && s.smb.export !== '-');
  const users = state.users.filter((u) => matches(join(u.name, u.description)));
  const level = (u, s) => {
    const sec = s.smb.security;
    if (sec === 'public') return 'rw';
    if (sec === 'secure') return s.smb.write.includes(u.name) ? 'rw' : 'r';
    return s.smb.write.includes(u.name) ? 'rw' : s.smb.read.includes(u.name) ? 'r' : '';
  };
  const wrap = el('div', 'box table-wrap');
  const table = el('table', 'grid');
  const thead = el('thead');
  const hr = el('tr');
  hr.appendChild(el('th', '', T('user')));
  exported.forEach((s) => { const th = el('th', '', s.name); th.title = T('security.' + s.smb.security); hr.appendChild(th); });
  thead.appendChild(hr);
  const tbody = el('tbody');
  for (const u of users) {
    const tr = el('tr');
    const th = el('th', '', u.name);
    th.title = `uid ${u.uid}${u.description ? ' · ' + u.description : ''}${u.smb ? '' : ' · ' + T('no_smb_password')}`;
    tr.appendChild(th);
    for (const s of exported) {
      const l = level(u, s);
      const td = el('td', 'c');
      td.appendChild(l === 'rw' ? chip(T('access.rw'), 'ok') : l === 'r' ? chip(T('access.r'), 'accent') : el('span', 'hint', '–'));
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }
  table.append(thead, tbody);
  wrap.appendChild(table);
  if (!users.length) wrap.appendChild(emptyNote(T('nothing_found')));
  body.appendChild(wrap);
  const notExported = state.shares.filter((s) => !s.smb.export || s.smb.export === '-').map((s) => s.name);
  body.appendChild(el('p', 'hint', T('access_legend')));
  if (notExported.length) body.appendChild(el('p', 'hint', T('not_exported_list', { names: notExported.join(', ') })));
}

// --------------------------------------------------------------- network shares
function network(body) {
  const smb = state.smb;
  const box = el('div', 'box');
  const sessions = smb.sessions.filter((s) => matches(join(s.user, s.machine, s.shares.map((x) => x.share))));
  const rows = sessions.map((s) => row({
    key: 'session:' + s.user + s.machine + s.since,
    name: `${s.user} @ ${s.machine}`,
    meta: [el('span', '', T('since', { when: fmt.relative(s.since) })), s.dialect ? chip(s.dialect, 'quiet') : null,
      ...s.shares.map((x) => chip(x.share, 'accent', T('since', { when: fmt.date(x.since) })))],
  }));
  if (!rows.length) rows.push(emptyNote(T('no_sessions')));
  box.appendChild(group(T('smb_sessions'), T('session_count', { n: smb.sessions.length }), rows));

  const settings = el('div', 'row-detail');
  settings.style.padding = '8px 12px';
  settings.appendChild(kv([
    ['SMB', smb.enabled ? T('enabled') : T('disabled')],
    [T('workgroup'), smb.workgroup],
    [T('security_mode'), smb.security],
    ['macOS (fruit)', smb.fruit ? T('enabled') : T('disabled')],
    ['NetBIOS', smb.netbios ? T('enabled') : T('disabled')],
    ['WSD', smb.wsd ? T('enabled') : T('disabled')],
    [T('custom_shares'), smb.custom.length ? lines(smb.custom.map((c) => typeof c === 'object' ? JSON.stringify(c) : String(c))) : null],
    ['smb-extra.conf', smb.extra ? el('pre', 'code', smb.extra) : T('empty_file')],
    ['NFS', state.nfs.enabled ? T('enabled') : T('disabled')],
    [T('nfs_exports'), state.nfs.exports.length ? lines(state.nfs.exports) : null],
  ]));
  box.appendChild(group(T('settings'), T('settings_meta'), [settings]));
  body.appendChild(box);
}

// --------------------------------------------------------------- scripts & cron
function scriptMatches(s) {
  return matches(join(s.name, s.description, s.frequency, s.cron, s.refs.map((r) => r.path)));
}

function scripts(body) {
  const box = el('div', 'box');
  const list = state.scripts.filter(scriptMatches);
  const rows = list.map((s) => row({
    key: 'script:' + s.id,
    name: s.name,
    meta: [
      chip(scheduleText(s.frequency, s.cron), s.frequency === 'disabled' ? 'quiet' : 'accent', s.cron || null),
      s.running ? chip(T('running_now'), 'ok') : null,
      s.stale ? chip(T('stale'), 'warn', T('stale_title')) : null,
      s.missing ? chip(T('dead_refs', { n: s.missing }), 'danger', T('dead_refs_title')) : null,
      s.description ? el('span', 'note', s.description) : null,
      s.last_run ? el('span', '', T('last_run', { when: fmt.relative(s.last_run) })) : null,
    ],
    detail: () => {
      const d = el('div');
      d.appendChild(kv([
        [T('file'), s.file, true],
        [T('schedule'), `${scheduleText(s.frequency, s.cron)}${s.cron ? ` (${s.cron})` : ''}`],
        [T('refs'), s.refs.length ? lines(s.refs.map((r) => `${r.exists === false ? '✗ ' : r.exists ? '✓ ' : '· '}${r.path}`)) : null],
      ]));
      const pre = el('pre', 'code', s.text + (s.cut ? '\n…' : ''));
      pre.style.maxHeight = '320px';
      pre.style.marginTop = '6px';
      d.appendChild(pre);
      return d;
    },
    menu: () => [{ text: Office.t('common.copy_path'), act: () => Office.copy(s.file) }],
  }));
  if (!rows.length) rows.push(emptyNote(T('nothing_found')));
  box.appendChild(group(T('user_scripts'), T('script_count', { n: state.scripts.length }), rows));

  const cron = state.cron.filter((c) => matches(join(c.title, c.schedule, c.command, c.source)));
  const cronRows = cron.map((c, i) => row({
    key: 'cron:' + i + c.command,
    name: c.title || c.command,
    mono: !c.title,
    meta: [chip(fmt.cron(c.schedule), 'accent', c.schedule), el('span', 'mono', c.source)],
    detail: () => kv([[T('schedule'), `${fmt.cron(c.schedule)} (${c.schedule})`], [T('command'), c.command, true], [T('source'), c.source, true]]),
  }));
  if (!cronRows.length) cronRows.push(emptyNote(T('nothing_found')));
  box.appendChild(group(T('cron_jobs'), T('cron_meta'), cronRows, { closed: !query }));
  body.appendChild(box);
}

// --------------------------------------------------------------- backups
function backups(body) {
  const box = el('div', 'box');
  const list = state.backups.filter((b) => matches(join(b.kind, b.title, b.paths)));
  if (!list.length) box.appendChild(emptyNote(T('nothing_found')));
  for (const b of list) {
    const isSnap = b.kind === 'snapshots';
    box.appendChild(row({
      key: 'backup:' + b.kind + b.title,
      name: isSnap ? T('backup.snapshots_title') : b.title,
      meta: [
        chip(T('backup.' + b.kind), b.kind === 'share' || b.kind === 'script' ? 'quiet' : 'accent'),
        b.detail && b.detail.running ? chip(T('running_now'), 'ok') : null,
        isSnap ? el('span', '', T('backup.snapshots_count', { n: b.detail.count })) : null,
        ...b.paths.slice(0, 3).map((p) => el('span', 'mono', p)),
        b.paths.length > 3 ? chip(`+${b.paths.length - 3}`, 'quiet') : null,
      ],
      detail: isSnap ? null : () => kv([
        [T('paths'), b.paths.length ? lines(b.paths) : null],
        ...Object.entries(b.detail || {}).map(([k, v]) => [T('detail.' + k) !== `${ID}.detail.${k}` ? T('detail.' + k) : k,
          typeof v === 'boolean' ? (v ? Office.t('common.yes') : Office.t('common.no')) : k === 'since' ? fmt.date(v) : String(v)]),
      ]),
      menu: isSnap ? () => [{ text: T('to_snapshot'), act: () => Office.go('#/snapshot') }] : null,
    }));
  }
  body.appendChild(box);
  body.appendChild(el('p', 'hint', T('backups_note')));
}

// --------------------------------------------------------------- disks & health
function diskMatches(d) {
  return matches(join(d.name, d.roles, d.type, d.device, d.id, d.transport, ((d.smart && d.smart.problems) || []).map((p) => p.name)));
}

function diskKind(d) {
  if (d.transport === 'nvme' || d.device.startsWith('nvme')) return 'NVMe';
  return d.rotational ? 'HDD' : 'SSD';
}

function problemText(p) {
  if (p.key === 'attribute') return T('smart.attribute', { id: p.id, name: T('attr.' + p.id) !== `${ID}.attr.${p.id}` ? T('attr.' + p.id) : p.name, raw: p.raw });
  if (p.key === 'failing') return T('smart.failing', { id: p.id, name: p.name });
  return T('smart.' + p.key, { raw: p.raw });
}

function diskRow(d) {
  const tl = tempLevel(d);
  const fl = fillLevel(d);
  const bad = smartBad(d);
  const notes = smartNotices(d);
  const hours = d.smart && d.smart.hours;
  const figures = el('div', 'figures');
  if (d.temp !== null && d.temp !== undefined) {
    figures.append(el('b', '', `${d.temp} °C`), el('span', '', T('temp_limit', { hot: d.hot })));
    figures.title = T('temp_title', { hot: d.hot, max: d.max });
  } else if (d.asleep) {
    figures.append(el('b', '', '💤'), el('span', '', T('asleep')));
  }
  return row({
    key: 'disk:' + d.device,
    name: d.name + (d.roles.length ? ` · ${d.roles.join(', ')}` : ''),
    meta: [
      chip(diskKind(d), 'quiet'),
      d.status && d.status !== 'DISK_OK' ? chip(d.status, 'danger') : null,
      tl ? chip(`🌡 ${d.temp} °C`, tl, T('temp_title', { hot: d.hot, max: d.max })) : null,
      d.errors ? chip(T('read_errors', { n: d.errors }), 'danger') : null,
      d.fill !== null && d.fill !== undefined ? chip(T('fill', { p: fmt.number(d.fill) }), fl || 'quiet', T('fill_title', { warn: d.warn, crit: d.crit })) : null,
      ...bad.map((p) => chip(problemText(p), 'danger')),
      ...notes.slice(0, 2).map((p) => chip(problemText(p), 'warn', T('smart.notice_title'))),
      notes.length > 2 ? chip(`+${notes.length - 2}`, 'quiet') : null,
      hours ? el('span', '', T('hours', { y: fmt.number(hours / 8766, 1) })) : null,
      el('span', 'mono', d.id || d.device),
    ],
    figures,
    detail: () => {
      const box = el('div');
      const nv = d.smart && d.smart.nvme;
      box.appendChild(kv([
        [T('device'), `/dev/${d.device} (${d.transport || '?'})`, true],
        [T('model'), d.id, true],
        [T('disk_role'), [d.type, ...d.roles].join(', ')],
        [T('temp'), d.temp !== null && d.temp !== undefined ? `${d.temp} °C · ${T('temp_title', { hot: d.hot, max: d.max })}` : (d.asleep ? T('asleep') : '–')],
        [T('fill_label'), d.fill !== null && d.fill !== undefined ? `${fmt.number(d.fill)} % · ${T('fill_title', { warn: d.warn, crit: d.crit })}` : null],
        [T('power_on'), hours ? `${fmt.number(hours)} h (${T('hours', { y: fmt.number(hours / 8766, 1) })})` : null],
        [T('smart_read'), d.smart && d.smart.read ? fmt.date(d.smart.read) + ' · ' + fmt.relative(d.smart.read) : T('smart_none')],
        [T('smart_findings'), d.smart && d.smart.problems.length ? lines(d.smart.problems.map(problemText)) : (d.smart ? T('smart_clean') : null)],
        ['NVMe', nv && nv.used !== undefined ? T('nvme_line', { used: nv.used, spare: nv.spare, media: nv.media_errors, unsafe: nv.unsafe_shutdowns, written: nv.written || '?' }) : null],
      ]));
      if (d.smart && d.smart.attributes.length) {
        const pre = el('pre', 'code', d.smart.attributes.map((a) => `${String(a.id).padStart(3)} ${a.name.padEnd(26)} ${String(a.value).padStart(3)} ${String(a.worst).padStart(3)} ${String(a.thresh).padStart(3)} ${a.failed || '-'}  ${a.raw}`).join('\n'));
        pre.style.marginTop = '6px';
        pre.style.maxHeight = '260px';
        box.appendChild(pre);
      }
      return box;
    },
    menu: () => [{ text: T('copy_id'), act: () => Office.copy(d.id || d.device) }],
  });
}

function disks(body) {
  const h = state.health;
  const box = el('div', 'box');
  if (!h) { box.appendChild(emptyNote(T('nothing_found'))); body.appendChild(box); return; }
  const list = h.devices.filter(diskMatches);
  const order = ['Parity', 'Data', 'Cache', 'Boot', 'Unassigned'];
  const groups = new Map();
  for (const d of list) {
    const key = order.includes(d.type) ? d.type : 'Other';
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(d);
  }
  for (const key of [...order, 'Other']) {
    const items = groups.get(key);
    if (!items) continue;
    const warm = items.filter((d) => tempLevel(d)).length;
    const meta = [T('disk_count', { n: items.length }), warm ? T('warm_count', { n: warm }) : null].filter(Boolean).join(' · ');
    box.appendChild(group(T('type.' + key), meta, items.map(diskRow)));
  }
  if (!list.length) box.appendChild(emptyNote(T('nothing_found')));
  body.appendChild(box);

  // parity and license
  const lic = state.license || {};
  const info = el('div', 'box');
  const d = el('div', 'row-detail');
  d.style.padding = '8px 12px';
  d.appendChild(kv([
    [T('parity'), h.parity.slots ? (h.parity.present ? T('parity_present', { n: h.parity.present, slots: h.parity.slots }) : T('parity_none')) : null],
    [T('parity_checked'), h.parity.checked ? `${fmt.date(h.parity.checked)} · ${T('parity_errors', { n: h.parity.errors })}` : (h.parity.present ? T('parity_never') : null)],
    [T('license'), lic.type ? `${lic.type}${lic.to ? ' · ' + lic.to : ''}` : null],
    [T('license_since'), lic.since ? fmt.date(lic.since) : null],
    [T('license_expires'), lic.expires ? `${fmt.date(lic.expires)} · ${fmt.relative(lic.expires)}` : (lic.type ? T('license_forever') : null)],
    [T('license_devices'), lic.limit ? T('devices_of', { n: lic.devices, limit: lic.limit }) : T('devices_unlimited', { n: lic.devices })],
    [T('license_guid'), lic.guid, true],
    [T('license_check'), lic.check],
    [T('limits'), T('limits_text', { hh: h.limits.hdd_hot, hm: h.limits.hdd_max, sh: h.limits.ssd_hot, sm: h.limits.ssd_max, w: h.limits.warning, c: h.limits.critical })],
  ]));
  info.appendChild(group(T('array_license'), T('array_license_meta'), [d]));
  body.appendChild(info);
  body.appendChild(el('p', 'hint', T('disks_note')));
}

// --------------------------------------------------------------- Unraid notifications
function notices(body) {
  const box = el('div', 'box');
  const list = (state.notices || []).filter((n) => matches(join(n.subject, n.description, n.event, n.importance)));
  if (!list.length) box.appendChild(emptyNote((state.notices || []).length ? T('nothing_found') : T('no_notices')));
  for (const n of list) {
    const cls = n.importance === 'alert' ? 'danger' : n.importance === 'warning' ? 'warn' : 'quiet';
    box.appendChild(row({
      key: 'notice:' + n.time + n.subject,
      name: n.subject,
      meta: [chip(T('importance.' + n.importance) !== `${ID}.importance.${n.importance}` ? T('importance.' + n.importance) : n.importance, cls),
        n.time ? el('span', '', `${fmt.date(n.time)} · ${fmt.relative(n.time)}`) : null,
        n.description ? el('span', 'note', n.description) : null],
    }));
  }
  body.appendChild(box);
  body.appendChild(el('p', 'hint', T('notices_note')));
}

// --------------------------------------------------------------- plugins
function plugins(body) {
  const box = el('div', 'box');
  const list = state.plugins.filter((p) => matches(join(p.name, p.version, p.author)));
  if (!list.length) box.appendChild(emptyNote(T('nothing_found')));
  for (const p of list) {
    box.appendChild(row({
      key: 'plugin:' + p.file,
      name: p.name,
      meta: [p.version ? chip(p.version, 'quiet') : null, p.author ? el('span', '', p.author) : null, el('span', 'mono', p.file)],
    }));
  }
  body.appendChild(box);
}
})();
