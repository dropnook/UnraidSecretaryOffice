/* Ms. Snapshotini — keeps track of every snapshot on the server: ZFS, btrfs and
   VMs. Create, delete (with a space estimate), rename, hold, unmount.
   The agent part lives in agent/desks/snapshot.php. */
(() => {
'use strict';

const ID = 'snapshot';
const T = Office.scope(ID);
const { el, fmt } = Office;
const NAME_RULE = /^[A-Za-z0-9][A-Za-z0-9_.:+-]{0,79}$/;
const ROWS_AT_ONCE = 400;          // bigger groups are only drawn when opened
const OUR_HOLDS = ['unraid-secretary-office', 'snapshots-webseite'];
const FS_ORDER = { zfs: 0, btrfs: 1, vm: 2 };

let state = null;                  // last scan from the agent
let snaps = [];
let index = new Map();
let selection = new Set();
let fresh = new Set();             // highlighted for a moment
let poolFilter = null;
let grouping = Office.store('snapshot.grouping') || 'dataset';
let folded = Office.storeJson('snapshot.folded') || {};
let busy = false;
let estimateNo = 0;
let estimateTimer = null;
let groupRefs = [];
let view = null;                   // DOM of the mounted desk

// ------------------------------------------------------------------ loading
async function load(refresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(refresh ? { fresh: 1 } : {}) });
  if (j.ok) setState(j.state);
  return j;
}

function setState(s) {
  state = s;
  snaps = s ? [...(s.zfs?.snapshots || []), ...(s.btrfs?.snapshots || []), ...(s.vm?.snapshots || [])] : [];
  index = new Map(snaps.map((x) => [x.id, x]));
  for (const id of [...selection]) {
    const x = index.get(id);
    if (!x || !deletable(x)) selection.delete(id);
  }
  if (poolFilter && !filterPools().has(poolFilter)) poolFilter = null;
  if (view) render();
}

function filterPools() {
  const r = new Set();
  for (const p of state?.zfs?.pools || []) r.add(p.name);
  for (const d of state?.btrfs?.devices || []) r.add(d.name);
  if (state?.vm?.available) r.add('VMs');
  return r;
}

// ------------------------------------------------------------------ understanding snapshots
/** Where a snapshot comes from — known tools first, then the prefix of its name */
function source(s) {
  if (s.docker) return { key: 'docker', label: T('source.docker') };
  if (s.fs === 'vm') return { key: 'vm', label: T('source.vm') };
  const plan = planOf(s);
  if (plan) return { key: 'plan:' + plan.id, label: '⏱ ' + plan.label, plan };
  const bp = state?.backup?.prefix;
  if ((bp && s.name.startsWith(bp)) || (s.fs === 'btrfs' && /^\d{8}-\d{4}$/.test(s.name))) return { key: 'backup', label: T('source.backup') };
  if (/^(manual|manuell)-/i.test(s.name) || s.name.startsWith(T('default_prefix'))) return { key: 'manual', label: T('source.manual') };
  if (s.name.startsWith('autosnap_')) return { key: 'sanoid', label: 'Sanoid' };
  if (s.name.startsWith('syncoid_')) return { key: 'syncoid', label: 'Syncoid' };
  if (s.name.startsWith('zfs-auto-snap')) return { key: 'zas', label: 'zfs-auto-snapshot' };
  const m = s.name.match(/^([A-Za-z][A-Za-z0-9]*?)[-_.]?\d{4}[-_.]?\d{2}/);
  if (m) return { key: 'p:' + m[1].toLowerCase(), label: m[1].toLowerCase() };
  return { key: 'other', label: T('source.other') };
}

function sourceChip(src) {
  return src.key === 'backup' ? 'chip accent' : src.key === 'manual' ? 'chip warn' : src.plan ? 'chip ok' : 'chip';
}

/** The schedule a snapshot belongs to: auto-<plan>-YYYYMMDD-HHMM */
function planOf(s) {
  const m = /^auto-([a-z0-9][a-z0-9-]*)-\d{8}-\d{4}$/.exec(s.name || '');
  return m ? (state?.plans?.plans || []).find((p) => p.id === m[1]) || null : null;
}

/** Mounts that must go before deleting (zfs handles .zfs/snapshot automounts itself) */
function fixedMounts(s) { return (s.mounts || []).filter((m) => m.kind !== 'auto'); }
function backupRunning() { return !!state?.backup?.running; }
function usedByBackup(s) { return backupRunning() && (s.mounts || []).some((m) => m.backup && m.kind !== 'auto'); }
function ourHold(s) { return (s.holds || []).some((h) => OUR_HOLDS.includes(h)); }

function deletable(s) {
  return s.fs !== 'vm' && !s.docker && !(s.holds && s.holds.length) && !(s.clones && s.clones.length) && !usedByBackup(s);
}

function whyNot(s) {
  if (s.docker) return T('why.docker');
  if (s.fs === 'vm') return T('why.vm');
  if (usedByBackup(s)) return T('why.backup');
  if (s.holds && s.holds.length) return T('why.held');
  if (s.clones && s.clones.length) return T('why.clones', { clones: s.clones.join(', ') });
  return '';
}

function mountText(m) {
  return m.path + (m.kind === 'overlay' ? `  (${T('mount.overlay')})` : m.kind === 'auto' ? `  (${T('mount.auto')})` : '');
}

function kindText(s) {
  if (s.docker) return T('kind.docker');
  if (s.fs === 'zfs') return T('kind.zfs');
  if (s.fs === 'btrfs') return s.readonly === null || s.readonly === undefined ? T('kind.btrfs') : s.readonly ? T('kind.btrfs_ro') : T('kind.btrfs_rw');
  return T('kind.vm');
}

/** Same run: same name — or same source with the same timestamp
    (the backup script names ZFS snapshots «unraidbackup-YYYYMMDD-HHMM», btrfs ones just «YYYYMMDD-HHMM»). */
function runOf(s) {
  if (s.docker) return { key: 'docker', title: T('docker_layers') };
  const src = source(s);
  const m = s.name.match(/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})$/);
  if (m && src.key !== 'other' && src.key !== 'vm') {
    const when = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]).getTime() / 1000;
    return { key: `${src.key}|${m[0]}`, title: `${src.label} · ${fmt.date(when)}` };
  }
  return { key: 'name|' + s.name, title: s.name };
}

function targets() {
  const r = [];
  for (const v of state?.zfs?.volumes || []) r.push(v);
  for (const d of state?.btrfs?.devices || []) {
    r.push({ id: 'btrfs:' + d.mount, fs: 'btrfs', pool: d.name, name: d.mount, used: d.size - d.free, asleep: d.asleep, children: 0 });
  }
  return r;
}

function visible() { return snaps.filter((s) => !s.docker); }

function stamp() {
  const d = new Date();
  const z = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${z(d.getMonth() + 1)}${z(d.getDate())}-${z(d.getHours())}${z(d.getMinutes())}`;
}

// ------------------------------------------------------------------ what she says
function bubbleText() {
  const parts = [];
  if (!state) return [Office.agent.running ? T('bubble.no_scan') : T('bubble.no_data')];
  const real = visible();
  let space = 0;
  for (const p of state.zfs?.pools || []) space += p.snapused || 0;
  for (const s of state.btrfs?.snapshots || []) space += s.used || 0;
  parts.push(T('bubble.count', { n: real.length, size: fmt.size(space) }));
  const mounted = real.filter((s) => fixedMounts(s).length).length;
  if (backupRunning()) {
    parts.push(T('bubble.backup', { since: fmt.time(state.backup.since), step: state.backup.step || '…' }));
  }
  if (mounted) parts.push(T('bubble.mounted', { n: mounted }));
  const asleep = (state.btrfs?.devices || []).filter((d) => d.asleep).length;
  if (asleep) parts.push(T('bubble.asleep', { n: asleep }));
  if (!Office.agent.running) parts.push(T('bubble.offline', { when: fmt.date(state.time) }));
  return parts;
}

// ------------------------------------------------------------------ the desk
Office.desk({
  id: ID,

  mount(root) {
    view = build(root);
    render();
    load(false);
  },

  unmount() {
    view = null;
    Office.selbar(null);
  },

  poll() { load(false); },

  agentChanged() { if (view) { buttons(); renderHead(); } },

  menu() {
    const on = Office.agent.running && !busy;
    const asleep = (state?.btrfs?.devices || []).filter((d) => d.asleep).length;
    return [
      { text: T('scan'), act: () => scan(false), disabled: !on },
      { text: T('scan_wake', { n: asleep }), act: () => scan(true), disabled: !on || !asleep },
    ];
  },

  async reception() {
    if (!state) await load(false);
    const facts = [];
    if (state) {
      facts.push(T('fact.pools', { n: (state.zfs?.pools || []).length, d: (state.btrfs?.devices || []).length }));
      const vm = (state.vm?.snapshots || []).length;
      if (vm) facts.push(T('fact.vm', { n: vm }));
      facts.push(Office.t('common.scanned_ago', { when: fmt.relative(state.time) }));
    }
    return { bubble: bubbleText().join(' '), facts };
  },
});

// ------------------------------------------------------------------ building
function build(root) {
  const v = {};
  const scanBtn = el('button', 'btn plain');
  scanBtn.type = 'button';
  scanBtn.append(el('span', 'spin'), T('scan'));
  scanBtn.title = T('scan_title');
  scanBtn.onclick = () => scan(!!(view && view.wake.checked));
  // wake the sleeping disks for this scan — off unless switched on, never remembered (like Ms. Whereabouts' tour)
  const wakeLabel = el('label', 'switch');
  const wake = el('input');
  wake.type = 'checkbox';
  const wakeText = el('span', '', T('wake'));
  wakeLabel.append(wake, wakeText);
  wakeLabel.title = T('wake_title');
  const newBtn = el('button', 'btn', T('new'));
  newBtn.type = 'button';
  newBtn.onclick = () => createDialog([]);
  const head = Office.deskHead({ id: ID, icon: Office.desks.get(ID).icon }, { actions: [wakeLabel, scanBtn, newBtn] });
  root.appendChild(head.head);
  root.appendChild(Office.pageHelp(ID, [
    [T('help.tiles'), T('help.tiles_text')],
    [T('help.group'), T('help.group_text')],
    [T('help.select'), T('help.select_text')],
    [T('help.name'), T('help.name_text')],
    [T('help.menu'), T('help.menu_text')],
    [el('span', 'chip solid', '🔒 ' + T('held')), T('help.held')],
    [el('span', 'chip outline', '📌 ' + T('mounted')), T('help.mounted')],
    [el('span', 'chip outline', '📌 ' + T('used_by_backup')), T('help.backup')],
    [el('span', 'chip quiet', '💤 ' + T('disk_asleep')), T('help.asleep')],
    [T('plans'), T('help.plans')],
    [T('help.sources'), T('help.sources_text')],
    [T('docker_layers'), T('help.docker')],
    [T('help.scan'), T('help.scan_text')],
  ]));
  Object.assign(v, { scanBtn, newBtn, wakeLabel, wake, wakeText, bubble: head.bubble });

  // storage
  const storage = el('section', 'section');
  v.storageHint = el('span', 'hint');
  const sh = Office.sectionHead(T('storage'), T('storage.sub'), v.storageHint);
  v.pools = el('div', 'cards');
  storage.append(sh, v.pools);
  root.appendChild(storage);

  // schedules
  v.plans = el('section', 'section');
  root.appendChild(v.plans);

  // list
  const list = el('section', 'section');
  v.seg = el('div', 'seg');
  v.seg.setAttribute('role', 'group');
  for (const g of ['dataset', 'run', 'time']) {
    const b = el('button', '', T('group.' + g));
    b.type = 'button';
    b.title = T('group.' + g + '_title');
    b.dataset.g = g;
    b.onclick = () => { grouping = g; Office.store('snapshot.grouping', g); renderList(); };
    v.seg.appendChild(b);
  }
  const lh = Office.sectionHead(T('snapshots'), T('snapshots_sub'), v.seg);

  const bar = el('div', 'toolbar');
  const all = el('label', 'check-all');
  v.allBox = el('input');
  v.allBox.type = 'checkbox';
  v.allLabel = el('span', '', Office.t('common.all'));
  all.append(v.allBox, v.allLabel);
  v.allBox.onchange = () => {
    const vis = filtered().filter(deletable);
    const every = vis.length > 0 && vis.every((s) => selection.has(s.id));
    vis.forEach((s) => (every ? selection.delete(s.id) : selection.add(s.id)));
    renderList();
  };
  v.foldBtn = el('button', 'btn plain small', T('fold_all'));
  v.foldBtn.type = 'button';
  v.foldBtn.onclick = foldAll;
  v.search = el('input', 'search');
  v.search.type = 'search';
  v.search.placeholder = Office.t('common.filter');
  v.search.autocomplete = 'off';
  v.search.spellcheck = false;
  v.search.oninput = () => renderList();
  v.source = el('select', 'picker');
  v.source.setAttribute('aria-label', T('source.label'));
  v.source.onchange = () => renderList();
  v.dockerSwitch = el('label', 'switch');
  v.dockerBox = el('input');
  v.dockerBox.type = 'checkbox';
  v.dockerBox.checked = Office.store('snapshot.docker') === '1';
  v.dockerText = el('span', '', T('docker_layers'));
  v.dockerSwitch.append(v.dockerBox, v.dockerText);
  v.dockerBox.onchange = () => { Office.store('snapshot.docker', v.dockerBox.checked ? '1' : null); fillSources(); renderList(); };
  bar.append(all, v.foldBtn, v.search, v.source, v.dockerSwitch);

  v.list = el('div');
  v.empty = el('p', 'empty');
  const box = el('div', 'box');
  box.append(v.list, v.empty);
  list.append(lh, bar, box);
  root.appendChild(list);
  return v;
}

function render() {
  if (!view) return;
  renderHead();
  renderPools();
  renderPlans();
  dockerSwitch();
  fillSources();
  renderList();
  buttons();
}

function renderHead() {
  const sleeping = (state?.btrfs?.devices || []).filter((d) => d.asleep).length;
  view.wakeText.textContent = sleeping ? T('wake_n', { n: sleeping }) : T('wake');
  view.wakeLabel.hidden = !sleeping && !view.wake.checked;
  view.bubble.innerHTML = '';
  view.bubble.append(Office.withGreeting(ID, bubbleText().join(' ')));
}

function buttons() {
  if (!view) return;
  const off = !Office.agent.running || busy;
  view.scanBtn.disabled = off;
  view.newBtn.disabled = off || !state;
}

// ------------------------------------------------------------------ storage cards
function renderPools() {
  const box = view.pools;
  box.innerHTML = '';
  if (!state) return;
  for (const p of state.zfs?.pools || []) box.appendChild(zfsCard(p));
  if ((state.btrfs?.devices || []).length) box.appendChild(btrfsCard(state.btrfs));
  if (state.vm?.available) box.appendChild(vmCard(state.vm));
  const n = (state.zfs?.pools || []).length + (state.btrfs?.devices || []).length;
  view.storageHint.textContent = poolFilter ? T('storage.filtered', { name: poolFilter }) : T('storage.hint', { n });
}

function toggleFilter(name) {
  poolFilter = poolFilter === name ? null : name;
  renderPools();
  renderList();
}

function zfsCard(p) {
  const card = el('button', 'card' + (poolFilter === p.name ? ' active' : '') + (p.count ? '' : ' empty-card'));
  card.type = 'button';
  card.onclick = () => toggleFilter(p.name);
  const head = el('div', 'card-head');
  head.append(el('span', 'card-name', p.name), el('span', 'tag', 'ZFS'));
  head.append(el('span', 'status' + (p.health === 'ONLINE' ? '' : ' bad'), p.health === 'ONLINE' ? T('online') : p.health));
  card.appendChild(head);
  // usable space without parity (dataset view), else the pool view
  const total = p.used !== null ? p.used + p.avail : p.size;
  const used = p.used !== null ? p.used : p.alloc;
  card.appendChild(spaceBar(total, used, p.snapused));
  const figures = el('div', 'card-figures');
  figures.append(el('span', '', T('used_of', { used: fmt.size(used), total: fmt.size(total) })), el('span', '', `${Math.round(used / total * 100)} %`));
  card.appendChild(figures);
  const line = el('div', 'card-line');
  if (p.count) line.append(el('b', '', T('count', { n: p.count })), ` · ${fmt.size(p.snapused)}`);
  else line.append(T('none'));
  if (p.docker) line.append(el('span', 'quiet', ` · ${T('docker_count', { n: p.docker })}`));
  card.appendChild(line);
  return card;
}

function spaceBar(total, used, snapshots) {
  const bar = el('div', 'bar');
  const data = el('i', 'data');
  const s = el('i', 'snaps' + (snapshots ? '' : ' none'));
  const share = (x) => `${Math.max(0, Math.min(100, x / total * 100))}%`;
  data.style.width = share(Math.max(0, used - (snapshots || 0)));
  s.style.width = share(snapshots || 0);
  bar.title = T('bar_title', { data: fmt.size(used - (snapshots || 0)), snaps: fmt.size(snapshots || 0), free: fmt.size(total - used) });
  bar.append(data, s);
  return bar;
}

function btrfsCard(part) {
  const card = el('div', 'card' + (part.devices.length > 3 ? ' wide' : ''));
  const asleep = part.devices.filter((d) => d.asleep);
  const head = el('div', 'card-head');
  head.append(el('span', 'card-name', T('array')), el('span', 'tag', 'btrfs'));
  head.append(el('span', 'status quiet', asleep.length ? T('asleep_count', { n: asleep.length }) : T('all_awake')));
  card.appendChild(head);

  const rows = el('div', 'minirows');
  for (const d of part.devices) {
    const n = part.snapshots.filter((s) => s.ds === d.mount).length;
    const row = el('button', 'minirow' + (d.asleep ? ' asleep' : '') + (poolFilter === d.name ? ' active' : ''));
    row.type = 'button';
    row.onclick = () => toggleFilter(d.name);
    const bar = el('div', 'bar thin');
    const i = el('i', 'data');
    i.style.width = `${d.size ? (d.size - d.free) / d.size * 100 : 0}%`;
    bar.appendChild(i);
    let info = d.scanned ? T('count', { n }) : T('never_read');
    if (d.asleep) info = '💤 ' + info;
    if (d.error) info = '⚠ ' + T('error');
    row.title = [
      T('disk_used', { mount: d.mount, used: fmt.size(d.size - d.free), total: fmt.size(d.size) }),
      d.scanned ? T('last_read', { when: fmt.date(d.scanned) }) : T('never_searched'),
      d.asleep ? T('asleep_hint') : '',
      d.error ? Office.errorText(d.error, ID) : '',
    ].filter(Boolean).join('\n');
    row.append(el('span', 'minirow-name', d.name), bar, el('span', 'minirow-info', info));
    rows.appendChild(row);
  }
  card.appendChild(rows);

  const foot = el('div', 'card-foot');
  const last = Math.max(0, ...part.devices.map((d) => d.scanned || 0));
  foot.append(el('span', '', `${T('count', { n: part.snapshots.length })} · ${last ? Office.t('common.scanned_ago', { when: fmt.relative(last) }) : T('never_read')}`));
  if (asleep.length) {
    const w = el('button', 'btn plain small', T('wake_scan'));
    w.type = 'button';
    w.disabled = !Office.agent.running || busy;
    w.title = T('wake_scan_title', { disks: asleep.map((d) => d.name).join(', ') });
    w.onclick = () => scan(true);
    foot.appendChild(w);
  }
  card.appendChild(foot);
  return card;
}

function vmCard(part) {
  const card = el('button', 'card' + (poolFilter === 'VMs' ? ' active' : '') + (part.snapshots.length ? '' : ' empty-card'));
  card.type = 'button';
  card.onclick = () => toggleFilter('VMs');
  const head = el('div', 'card-head');
  head.append(el('span', 'card-name', 'VMs'), el('span', 'tag', 'libvirt'));
  head.append(el('span', 'status quiet', T('vm_count', { n: part.domains.length })));
  card.appendChild(head);
  const perVm = new Map(part.domains.map((d) => [d, 0]));
  part.snapshots.forEach((s) => perVm.set(s.ds, (perVm.get(s.ds) || 0) + 1));
  const figures = el('div', 'card-figures');
  figures.append(el('span', '', [...perVm].map(([d, n]) => (n ? `${d} (${n})` : d)
    + (part.domains.includes(d) ? '' : ` – ${T('vm_gone_short')}`)).join(' · ') || T('no_vms')));
  card.appendChild(figures);
  const line = el('div', 'card-line');
  if (part.snapshots.length) line.append(el('b', '', T('count', { n: part.snapshots.length })), ` · ${T('managed_in_unraid')}`);
  else line.append(T('no_vm_snapshots'));
  card.appendChild(line);
  return card;
}

// ------------------------------------------------------------------ filters
function dockerParent() { return state?.zfs?.docker_parent || 'master/system'; }

function dockerSwitch() {
  const n = snaps.filter((s) => s.docker).length;
  view.dockerSwitch.hidden = n === 0;
  view.dockerText.textContent = `${T('docker_layers')} (${fmt.number(n)})`;
  view.dockerSwitch.title = T('docker_title', { parent: dockerParent() });
}

function fillSources() {
  const sel = view.source;
  const now = sel.value;
  const seen = new Map();
  for (const s of snaps) {
    if (s.docker && !view.dockerBox.checked) continue;
    const src = source(s);
    const e = seen.get(src.key) || { label: src.label, n: 0 };
    e.n++;
    seen.set(src.key, e);
  }
  sel.innerHTML = '';
  sel.appendChild(new Option(T('source.all'), ''));
  for (const [key, e] of [...seen.entries()].sort((a, b) => a[1].label.localeCompare(b[1].label, Office.lang))) {
    sel.appendChild(new Option(`${e.label} (${e.n})`, key));
  }
  sel.value = seen.has(now) ? now : '';
}

function filtered() {
  const words = view.search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
  const src = view.source.value;
  const docker = view.dockerBox.checked;
  return snaps.filter((s) => {
    if (s.docker && !docker) return false;
    if (poolFilter && s.pool !== poolFilter) return false;
    if (src && source(s).key !== src) return false;
    if (words.length) {
      const text = `${s.ds}@${s.name} ${source(s).label} ${fixedMounts(s).length ? T('mounted') : ''} ${s.description || ''}`.toLowerCase();
      if (!words.every((w) => text.includes(w))) return false;
    }
    return true;
  });
}

// ------------------------------------------------------------------ groups
function makeGroups(list) {
  const map = new Map();
  const add = (key, info, s) => {
    if (!map.has(key)) map.set(key, { key, ...info, items: [] });
    map.get(key).items.push(s);
  };
  for (const s of list) {
    if (s.docker) { add('docker', { title: T('docker_layers'), kind: 'docker' }, s); continue; }
    if (grouping === 'dataset') add('vol|' + s.vol, { title: s.ds, kind: s.fs, vol: s.vol }, s);
    else if (grouping === 'run') { const r = runOf(s); add('run|' + r.key, { title: r.title, kind: 'run' }, s); }
    else add('day|' + fmt.dayKey(s.t), { title: s.t ? fmt.dayTitle(fmt.dayKey(s.t)) : T('time_unknown'), kind: 'day' }, s);
  }
  const groups = [...map.values()];
  const newest = (g) => Math.max(...g.items.map((s) => s.t));
  for (const g of groups) {
    if (grouping === 'run' && g.kind !== 'docker') g.items.sort((a, b) => a.ds.localeCompare(b.ds, Office.lang));
    else g.items.sort((a, b) => b.t - a.t);
  }
  groups.sort((a, b) => {
    if (a.kind === 'docker' || b.kind === 'docker') return a.kind === 'docker' ? 1 : -1;
    if (grouping === 'dataset') return (FS_ORDER[a.kind] - FS_ORDER[b.kind]) || a.title.localeCompare(b.title, Office.lang);
    return newest(b) - newest(a);
  });
  return groups;
}

function isFolded(g) { return g.key in folded ? folded[g.key] : g.kind === 'docker'; }
function setFolded(g, value) { folded[g.key] = value; Office.storeJson('snapshot.folded', folded); }

// ------------------------------------------------------------------ list
function renderList() {
  if (!view) return;
  const list = view.list;
  const empty = view.empty;
  list.innerHTML = '';
  groupRefs = [];
  view.seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.g === grouping)));

  if (!state) {
    empty.hidden = false;
    empty.innerHTML = '';
    if (Office.agent.running) {
      empty.append(el('strong', '', T('empty.no_scan')), T('empty.no_scan_text'));
      const b = el('button', 'btn', T('scan_now'));
      b.type = 'button';
      b.onclick = () => scan(false);
      empty.append(el('br'), b);
    } else {
      empty.append(el('strong', '', T('empty.no_data')), T('empty.no_data_text'));
    }
    selectionChanged();
    foldButton();
    return;
  }
  const shown = filtered();
  if (!shown.length) {
    empty.hidden = false;
    empty.innerHTML = '';
    const any = snaps.some((s) => !s.docker);
    empty.append(el('strong', '', any ? T('empty.filtered') : T('empty.none')), any ? T('empty.filtered_text') : T('empty.none_text'));
    selectionChanged();
    foldButton();
    return;
  }
  empty.hidden = true;
  for (const g of makeGroups(shown)) list.appendChild(buildGroup(g));
  selectionChanged();
  foldButton();
}

function foldButton() {
  const groups = [...view.list.querySelectorAll('.group')];
  const open = groups.some((g) => !g.classList.contains('closed'));
  view.foldBtn.disabled = groups.length === 0;
  view.foldBtn.textContent = open || !groups.length ? T('fold_all') : T('unfold_all');
  view.foldBtn.dataset.fold = open ? '1' : '';
}

function foldAll() {
  const fold = view.foldBtn.dataset.fold === '1';
  for (const r of groupRefs) folded[r.key] = fold;
  Office.storeJson('snapshot.folded', folded);
  Office.keepInPlace(view.foldBtn, renderList);
}

function buildGroup(g) {
  const box = el('div', 'group' + (isFolded(g) ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');

  const removable = g.items.filter(deletable);
  const cb = el('input');
  cb.type = 'checkbox';
  cb.disabled = !removable.length;
  cb.title = removable.length ? T('select_group') : T('nothing_deletable');
  cb.onclick = (e) => {
    e.stopPropagation();
    const every = removable.every((s) => selection.has(s.id));
    removable.forEach((s) => (every ? selection.delete(s.id) : selection.add(s.id)));
    renderList();
  };

  const arrow = el('span', 'group-arrow', '▼');
  const mid = el('div', 'group-mid');
  const title = el('div', 'group-title');
  title.append(el('span', '', g.title));
  if (g.kind === 'docker') title.append(el('span', 'chip quiet', T('read_only')));
  mid.appendChild(title);

  const meta = [T('count', { n: g.items.length })];
  if (g.kind === 'zfs') {
    const v = (state.zfs.volumes || []).find((x) => x.id === g.vol);
    if (v) meta.push(T('group.uses', { size: fmt.size(v.snapused) }));
  }
  if (g.kind === 'btrfs') {
    const d = (state.btrfs.devices || []).find((x) => 'btrfs:' + x.mount === g.vol);
    if (d && d.scanned) meta.push(Office.t('common.scanned_ago', { when: fmt.relative(d.scanned) }));
    if (d && d.asleep) meta.push('💤 ' + T('asleep'));
  }
  if (g.kind === 'run') {
    meta[0] = T('dataset_count', { n: new Set(g.items.map((s) => s.ds)).size });
    const zfs = g.items.filter((s) => s.fs === 'zfs');
    if (zfs.length) meta.push(T('group.uses', { size: fmt.size(zfs.reduce((a, s) => a + (s.used || 0), 0)) }));
  }
  if (g.kind === 'vm') {
    if (g.items.some((s) => s.orphaned)) meta.push(T('vm_gone'));
    else if (g.items.some((s) => s.active)) meta.push(T('vm_on_newest'));
  }
  if (g.kind === 'docker') meta.push(T('docker_meta', { parent: dockerParent() }));
  const mounted = g.items.filter((s) => fixedMounts(s).length).length;
  if (mounted) meta.push('📌 ' + T('mounted_count', { n: mounted }));
  mid.appendChild(el('div', 'group-meta', meta.join(' · ')));

  head.append(cb, arrow, mid);
  if (grouping === 'dataset' && g.kind !== 'docker' && g.kind !== 'vm') {
    head.appendChild(timeline(g.items));
    const plus = el('button', 'group-plus', '+');
    plus.type = 'button';
    plus.title = T('new_from_here');
    plus.disabled = !Office.agent.running;
    plus.onclick = (e) => { e.stopPropagation(); createDialog([g.vol]); };
    head.appendChild(plus);
  }

  const rows = el('div', 'group-rows');
  const fill = () => { rows.innerHTML = ''; g.items.forEach((s) => rows.appendChild(buildRow(s))); };
  if (!isFolded(g) || g.items.length <= ROWS_AT_ONCE) fill();

  const toggle = () => {
    const closed = !box.classList.contains('closed');
    box.classList.toggle('closed', closed);
    setFolded(g, closed);
    foldButton();
    if (!closed && !rows.firstChild) fill();
  };
  head.onclick = (e) => { Office.keepInPlace(head, toggle); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); Office.keepInPlace(head, toggle); } };

  box.append(head, rows);
  groupRefs.push({ key: g.key, cb, removable });
  return box;
}

/** Tiny time axis: when the snapshots of a group were taken */
function timeline(items) {
  const NS = 'http://www.w3.org/2000/svg';
  const W = 150, H = 20, PAD = 5;
  const now = Date.now() / 1000;
  const oldest = Math.min(...items.map((s) => s.t || now));
  const span = Math.min(Math.max(now - oldest, 7 * 86400), 120 * 86400) * 1.05;
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('class', 'timeline');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  const title = document.createElementNS(NS, 'title');
  title.textContent = T('timeline_title', { n: Math.round(span / 86400) });
  svg.appendChild(title);
  const line = (x1, y1, x2, y2, cls) => {
    const l = document.createElementNS(NS, 'line');
    l.setAttribute('x1', x1); l.setAttribute('y1', y1); l.setAttribute('x2', x2); l.setAttribute('y2', y2);
    l.setAttribute('class', cls);
    svg.appendChild(l);
  };
  line(PAD, H / 2, W - PAD, H / 2, 'axis');
  for (let d = 7; d * 86400 < span; d += 7) {
    const x = W - PAD - (d * 86400) / span * (W - 2 * PAD);
    line(x, H / 2 - 3, x, H / 2 + 3, 'tick');
  }
  line(W - PAD, H / 2 - 5, W - PAD, H / 2 + 5, 'tick');
  for (const s of [...items].filter((x) => x.t).sort((a, b) => a.t - b.t)) {
    const x = Math.max(PAD, W - PAD - (now - s.t) / span * (W - 2 * PAD));
    const c = document.createElementNS(NS, 'circle');
    c.setAttribute('cx', x.toFixed(1));
    c.setAttribute('cy', H / 2);
    c.setAttribute('r', '4');
    if (s.holds && s.holds.length) c.setAttribute('class', 'held');
    svg.appendChild(c);
  }
  return svg;
}

function buildRow(s) {
  const row = el('div', 'row' + (selection.has(s.id) ? ' selected' : '') + (fresh.has(s.id) ? ' new' : ''));
  row.dataset.id = s.id;

  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = selection.has(s.id);
  cb.disabled = !deletable(s);
  cb.title = cb.disabled ? whyNot(s) : T('select');
  cb.setAttribute('aria-label', T('select_one', { name: `${s.ds}@${s.name}` }));
  cb.onchange = () => {
    if (cb.checked) selection.add(s.id); else selection.delete(s.id);
    row.classList.toggle('selected', cb.checked);
    selectionChanged();
  };

  const main = el('div', 'row-main');
  const label = grouping === 'run' ? s.ds : s.name;
  const name = el('div', 'row-name link', label);
  name.title = Office.t('common.properties');
  name.onclick = () => properties(s);
  main.appendChild(name);

  const meta = el('div', 'row-meta');
  if (grouping === 'time') meta.appendChild(el('span', 'mono', s.ds));
  if (grouping === 'run' && s.name !== label) meta.appendChild(el('span', 'mono', s.name));
  const when = el('span', '', s.t ? `${fmt.date(s.t)} · ${fmt.relative(s.t)}` : T('time_unknown'));
  if (s.t) when.title = fmt.date(s.t, true);
  meta.appendChild(when);
  const src = source(s);
  meta.appendChild(el('span', sourceChip(src), src.label));
  if (s.holds && s.holds.length) {
    const c = el('span', 'chip solid', '🔒 ' + T('held'));
    c.title = 'Hold: ' + s.holds.join(', ');
    meta.appendChild(c);
  }
  const fixed = fixedMounts(s);
  if (fixed.length) {
    const c = el('span', 'chip outline', '📌 ' + (usedByBackup(s) ? T('used_by_backup') : T('mounted')));
    c.title = T('mounted_at') + '\n' + fixed.map(mountText).join('\n');
    meta.appendChild(c);
  } else if ((s.mounts || []).length) {
    const c = el('span', 'chip quiet', T('opened'));
    c.title = T('opened_title');
    meta.appendChild(c);
  }
  if (s.clones && s.clones.length) meta.appendChild(el('span', 'chip', T('has_clones')));
  if (s.fs === 'btrfs' && s.readonly === false) meta.appendChild(el('span', 'chip', T('writable')));
  if (s.detected === 'mount') {
    const c = el('span', 'chip quiet', '💤 ' + T('disk_asleep'));
    c.title = T('detected_title');
    meta.appendChild(c);
  }
  if (s.fs === 'vm') {
    if (s.description) meta.appendChild(el('span', 'note', `«${s.description}»`));
    if (s.active) {
      const c = el('span', 'chip accent', T('vm_current'));
      c.title = T('vm_current_title');
      meta.appendChild(c);
    }
    if (s.orphaned) {
      const c = el('span', 'chip warn', T('vm_gone'));
      c.title = T('vm_gone_title');
      meta.appendChild(c);
    }
  }
  if (s.used !== null && s.used !== undefined) meta.appendChild(el('span', 'narrow-only', T('used_short', { size: fmt.size(s.used) })));
  main.appendChild(meta);

  const figures = el('div', 'figures');
  if (s.used !== null && s.used !== undefined) {
    figures.append(el('b', '', fmt.size(s.used)), el('span', '', T('used')));
    figures.title = T('figures_title');
  }
  if (s.refer !== null && s.refer !== undefined) figures.append(el('b', '', fmt.size(s.refer)), el('span', '', T('data')));
  if (s.overlay !== null && s.overlay !== undefined) {
    figures.append(el('b', '', fmt.size(s.overlay)), el('span', '', T('overlay')));
    figures.title = T('overlay_title');
  }

  const more = el('button', 'more', '⋯');
  more.type = 'button';
  more.setAttribute('aria-label', T('actions'));
  more.onclick = (e) => { e.stopPropagation(); Office.menu(e, rowMenu(s)); };
  row.oncontextmenu = (e) => { e.preventDefault(); Office.menu(e, rowMenu(s)); };

  row.append(cb, main, figures, more);
  return row;
}

function rowMenu(s) {
  const on = Office.agent.running && !busy;
  const items = [{ text: Office.t('common.properties'), act: () => properties(s) }];
  if (deletable(s)) {
    items.push({ text: selection.has(s.id) ? T('deselect') : T('select'), act: () => {
      if (selection.has(s.id)) selection.delete(s.id); else selection.add(s.id);
      renderList();
    } });
  }
  if (s.path) items.push({ text: Office.t('common.copy_path'), act: () => Office.copy(s.path) });
  if (s.fs === 'zfs' && !s.docker) {
    if (ourHold(s)) items.push({ text: T('release'), act: () => hold(s, false), disabled: !on });
    else if (s.holds && s.holds.length) items.push({ text: T('held_by', { holds: s.holds.join(', ') }), disabled: true });
    else items.push({ text: T('hold'), act: () => askHold(s), disabled: !on });
  }
  if (fixedMounts(s).length) {
    const locked = usedByBackup(s);
    items.push({ text: locked ? T('unmount_locked') : T('unmount') + '…', act: () => askUnmount(s), disabled: !on || locked });
  }
  if ((s.fs === 'zfs' || s.fs === 'btrfs') && !s.docker) {
    const mounted = fixedMounts(s).length > 0;
    items.push({ text: mounted ? T('rename_mounted') : T('rename') + '…', act: () => renameDialog(s), disabled: !on || mounted });
  }
  if (deletable(s)) {
    items.push({ separator: true });
    items.push({ text: T('delete') + '…', kind: 'danger', act: () => askDelete([s.id]), disabled: !on });
  }
  return items;
}

// ------------------------------------------------------------------ selection
function selectionChanged() {
  if (!view) return;
  const vis = filtered().filter(deletable);
  const n = selection.size;
  view.allLabel.textContent = n === 0 ? Office.t('common.all') : T('selected', { n });
  view.allBox.disabled = !vis.length;
  view.allBox.checked = vis.length > 0 && vis.every((s) => selection.has(s.id));
  view.allBox.indeterminate = !view.allBox.checked && vis.some((s) => selection.has(s.id));
  for (const r of groupRefs) {
    const inside = r.removable.filter((s) => selection.has(s.id)).length;
    r.cb.checked = inside > 0 && inside === r.removable.length;
    r.cb.indeterminate = inside > 0 && inside < r.removable.length;
  }
  clearTimeout(estimateTimer);
  if (!n) { Office.selbar(null); return; }
  const bar = Office.selbar({
    title: T('selected_title', { n }),
    sub: Office.agent.running ? T('estimating') : '',
    buttons: [
      { text: T('clear'), kind: 'plain', act: () => { selection.clear(); renderList(); } },
      { text: T('delete'), kind: 'danger', act: () => askDelete([...selection]), disabled: !Office.agent.running || busy },
    ],
  });
  if (Office.agent.running) estimateTimer = setTimeout(() => estimateBar(bar.sub), 300);
}

async function estimateBar(sub) {
  const no = ++estimateNo;
  const j = await estimate([...selection]);
  if (no !== estimateNo) return;
  sub.textContent = j ? reclaimText(j) : '';
}

async function estimate(ids) {
  const j = await Office.api.post(`${ID}.estimate`, { ids });
  return j.ok ? j : null;
}

function reclaimText(j) {
  let s = T('frees', { size: fmt.size(j.bytes) });
  if (j.unknown) s += ' ' + T('frees_unknown', { n: j.unknown });
  return s;
}

// ------------------------------------------------------------------ actions
function setBusy(on, button) {
  busy = on;
  if (button) button.classList.toggle('running', on);
  buttons();
}

function failed(j) { Office.toast(Office.errorText(j.error, ID), true); }

async function scan(wake) {
  if (busy) return;
  setBusy(true, view && view.scanBtn);
  if (view) renderPools();
  if (wake) Office.toast(T('waking'));
  const j = await Office.api.post(`${ID}.scan`, { wake: !!wake });
  setBusy(false, view && view.scanBtn);
  if (view) view.wake.checked = false;
  if (!j.ok) { if (view) renderPools(); failed(j); return; }
  fresh = new Set(j.new || []);
  setState(j.state);
  setTimeout(() => fresh.clear(), 4000);
  const parts = [T('scan_done', { n: visible().length })];
  const n = (j.new || []).length, gone = (j.gone || []).length;
  if (n) parts.push(T('scan_new', { n }));
  if (gone) parts.push(T('scan_gone', { n: gone }));
  if (!n && !gone) parts.push(T('scan_same'));
  const asleep = (state.btrfs?.devices || []).filter((d) => d.asleep).length;
  if (asleep && !wake) parts.push(T('scan_skipped', { n: asleep }));
  Office.toast(parts.join(' · '));
}

async function hold(s, on) {
  setBusy(true);
  const j = await Office.api.post(`${ID}.${on ? 'hold' : 'release'}`, { id: s.id });
  setBusy(false);
  if (!j.ok) { failed(j); return; }
  setState(j.state);
  Office.toast(on ? T('held_done', { name: s.name }) : T('released_done', { name: s.name }));
}

/** Name under which the backup script leaves one of its snapshots alone */
function keepName(s) {
  const m = s.name.match(/(\d{8}-\d{4})$/);
  return T('keep_prefix') + (m ? m[1] : s.name.replace(/^[^A-Za-z0-9]+/, ''));
}

/**
 * A hold on a backup snapshot works, but the backup script then tries to
 * delete it every night and logs an error each time. Renaming takes it out
 * of the script's clean-up quietly.
 */
function askHold(s) {
  if (source(s).key !== 'backup') { hold(s, true); return; }
  const suggestion = keepName(s);
  const mounted = fixedMounts(s).length > 0;
  const box = el('div');
  box.appendChild(el('p', '', T('keep.text1')));
  box.appendChild(el('p', '', T('keep.text2')));
  const c = el('p', 'callout');
  c.append(T('keep.better_before'), ' ', el('code', '', suggestion), ' ', T('keep.better_after'));
  box.appendChild(c);
  if (mounted) box.appendChild(el('p', 'callout warn', usedByBackup(s) ? T('keep.after_backup') : T('keep.after_unmount')));
  const d = Office.dialog({
    title: T('keep.title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('keep.hold_anyway'), act: () => { hold(s, true); } },
      { text: T('rename') + '…', kind: '', act: () => { setTimeout(() => renameDialog(s, suggestion), 0); } },
    ],
  });
  if (mounted) d.buttons[2].disabled = true;
}

function renameDialog(s, suggestion) {
  const box = el('div');
  const field = el('div', 'field');
  const label = el('label', '', T('rename.new_name'));
  const input = el('input', 'input mono');
  input.value = suggestion || s.name;
  input.id = 'snapshot-rename';
  label.htmlFor = input.id;
  input.autocomplete = 'off';
  input.spellcheck = false;
  const hint = el('small', '', T('rename.hint', { ds: s.ds }));
  field.append(label, input, hint);
  box.appendChild(field);
  if (source(s).key === 'backup') box.appendChild(el('p', 'callout', T('rename.backup_note')));
  Office.dialog({
    title: T('rename.title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('rename'), kind: '', act: async () => {
        const name = input.value.trim();
        if (!NAME_RULE.test(name)) { hint.className = 'missing'; input.focus(); return false; }
        if (name === s.name) return true;
        setBusy(true);
        const j = await Office.api.post(`${ID}.rename`, { id: s.id, name });
        setBusy(false);
        if (!j.ok) { failed(j); return false; }
        if (j.id) fresh = new Set([j.id]);
        setState(j.state);
        setTimeout(() => fresh.clear(), 4000);
        Office.toast(T('rename.done', { name }));
        return true;
      } },
    ],
  });
}

function askDelete(ids) {
  const list = ids.map((id) => index.get(id)).filter((s) => s && deletable(s));
  if (!list.length) return;
  const n = list.length;
  const box = el('div');
  box.appendChild(el('p', '', T('delete.intro', { n })));
  const ul = el('ul', 'shortlist');
  list.slice(0, 300).forEach((s) => {
    const li = el('li', '', `${s.ds}@${s.name}`);
    li.appendChild(el('span', '', fmt.date(s.t)));
    ul.appendChild(li);
  });
  if (n > 300) ul.appendChild(el('li', '', T('delete.more', { n: n - 300 })));
  box.appendChild(ul);

  const reclaim = el('p', 'reclaim');
  reclaim.append(el('span', '', T('delete.estimating')));
  box.appendChild(reclaim);

  const mounted = list.filter((s) => fixedMounts(s).length);
  if (mounted.length) {
    const c = el('div', 'callout warn');
    c.append(el('strong', '', T('delete.mounted', { n: mounted.length })), ' ', T('delete.mounted_text'));
    const mu = el('ul', 'shortlist');
    mu.style.margin = '8px 0 0';
    mounted.forEach((s) => fixedMounts(s).forEach((m) => mu.appendChild(el('li', '', mountText(m)))));
    c.appendChild(mu);
    box.appendChild(c);
  }
  box.appendChild(el('p', 'callout', T('delete.note')));

  const d = Office.dialog({
    title: T('delete.title', { n }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: mounted.length ? T('delete.unmount_and_delete', { n }) : T('delete.confirm', { n }), kind: 'danger',
        act: () => { remove(list.map((s) => s.id), mounted.length > 0); } },
    ],
  });
  d.buttons[0].focus();   // safe default: Enter cancels

  estimate(list.map((s) => s.id)).then((j) => {
    reclaim.innerHTML = '';
    if (!j) { reclaim.append(el('span', '', T('delete.no_estimate'))); return; }
    reclaim.append(T('delete.frees_before'), ' ', el('b', '', fmt.size(j.bytes)), ' ', T('delete.frees_after'));
    if (j.unknown) reclaim.append(el('span', '', ' — ' + T('delete.btrfs_unknown', { n: j.unknown })));
  });
}

async function remove(ids, unmount) {
  setBusy(true);
  Office.toast(T('deleting', { n: ids.length }));
  const j = await Office.api.post(`${ID}.delete`, { ids, unmount: !!unmount });
  setBusy(false);
  if (!j.ok) { failed(j); return; }
  (j.deleted || []).forEach((id) => selection.delete(id));
  setState(j.state);
  const n = (j.deleted || []).length;
  if (n) Office.toast(T('deleted', { n }));
  if ((j.failures || []).length) Office.showErrors(n ? T('delete.partly') : T('delete.failed'), j.failures, ID);
}

function askUnmount(s) {
  const box = el('div');
  box.appendChild(el('p', '', T('unmount.intro', { name: `${s.ds}@${s.name}` })));
  const ul = el('ul', 'shortlist');
  fixedMounts(s).forEach((m) => {
    const li = el('li', '', m.path);
    li.appendChild(el('span', '', m.kind === 'overlay' ? T('mount.overlay') : m.backup ? T('mount.by_backup') : ''));
    ul.appendChild(li);
  });
  box.appendChild(ul);
  box.appendChild(el('p', 'callout', T('unmount.note') + (state.backup?.found ? ' ' + T('unmount.remount') : '')));
  Office.dialog({
    title: T('unmount'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('unmount'), kind: '', act: async () => {
        setBusy(true);
        const j = await Office.api.post(`${ID}.unmount`, { id: s.id });
        setBusy(false);
        if (!j.ok) { failed(j); return false; }
        setState(j.state);
        const n = (j.unmounted || []).length;
        Office.toast(n === 1 ? T('unmount.done_one', { path: j.unmounted[0] }) : T('unmount.done', { n }));
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ new snapshot
// ------------------------------------------------------------------ schedules
const WEEKDAYS = [1, 2, 3, 4, 5, 6, 0];

/** cron → what the dialog shows: hourly at :MM, daily at HH:MM, weekly on D at HH:MM, or the expression itself */
function planEvery(cron) {
  const p = (cron || '').trim().split(/\s+/);
  const num = (x) => /^\d+$/.test(x);
  if (p.length === 5 && num(p[0]) && p[1] === '*' && p[2] === '*' && p[3] === '*' && p[4] === '*') return { kind: 'hourly', minute: +p[0] };
  if (p.length === 5 && num(p[0]) && num(p[1]) && p[2] === '*' && p[3] === '*' && p[4] === '*') return { kind: 'daily', h: +p[1], m: +p[0] };
  if (p.length === 5 && num(p[0]) && num(p[1]) && p[2] === '*' && p[3] === '*' && /^[0-7]$/.test(p[4])) return { kind: 'weekly', h: +p[1], m: +p[0], dow: +p[4] % 7 };
  return { kind: 'custom', cron: cron || '' };
}

function renderPlans() {
  const box = view.plans;
  box.innerHTML = '';
  const info = state?.plans || { plans: [] };
  const add = el('button', 'btn small', T('plan.new'));
  add.type = 'button';
  add.disabled = !Office.agent.running || !info.user_scripts;
  add.onclick = () => planDialog(null);
  box.appendChild(Office.sectionHead(T('plans'), T('plans_sub'), add));
  if (!info.user_scripts) box.appendChild(el('p', 'callout warn', T('plan.no_user_scripts')));
  const plans = info.plans || [];
  if (!plans.length) {
    box.appendChild(el('p', 'empty sp-plans-empty', T('plan.none')));
    return;
  }
  const list = el('div', 'box');
  plans.forEach((p) => list.appendChild(planRow(p)));
  box.appendChild(list);
  const r = info.runner || {};
  if (plans.some((p) => p.enabled) && !(r.script && r.enabled)) box.appendChild(el('p', 'callout warn', T('plan.runner_off', { name: r.name })));
}

function planRow(p) {
  const row = el('div', 'row nocheck unfolds sp-plan' + (p.enabled ? '' : ' paused'));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', '⏱ ' + p.label));
  const meta = el('div', 'row-meta');
  if (!p.enabled) meta.appendChild(el('span', 'chip quiet', T('plan.paused')));
  meta.appendChild(el('span', '', fmt.cron(p.cron)));
  meta.appendChild(el('span', '', T('plan.keeps', { n: p.keep }) + (p.max_days ? ' · ' + T('plan.max_days', { n: p.max_days }) : '')));
  meta.appendChild(el('span', '', p.targets.map((t) => t.replace(/^(zfs|btrfs):/, '')).join(', ') + (p.recursive ? ' ' + T('plan.with_children') : '')));
  main.appendChild(meta);
  const when = el('div', 'row-meta');
  if (p.enabled && p.next) when.appendChild(el('span', '', T('plan.next', { when: fmt.relative(p.next) })));
  if (p.result) {
    const chip = el('span', 'chip ' + ({ ok: 'ok', skipped: 'quiet', partly: 'warn', failed: 'danger' }[p.result] || ''), T('plan.result.' + p.result));
    const lines = [T('plan.last', { when: fmt.date(p.last_run) }), T('plan.made', { created: p.created, deleted: p.deleted })];
    if ((p.skipped || []).length) lines.push(T('plan.skipped', { targets: p.skipped.map((t) => t.replace(/^(zfs|btrfs):/, '')).join(', ') }));
    (p.detail || []).forEach((f) => lines.push(Office.errorText(f, ID)));
    chip.title = lines.join('\n');
    when.append(chip, el('span', '', T('plan.last', { when: fmt.relative(p.last_run) })));
  }
  main.appendChild(when);
  row.appendChild(main);
  const fig = el('div', 'figures');
  fig.append(el('b', '', String(p.count)), el('span', '', T('plan.snapshots')), el('b', '', p.bytes ? fmt.size(p.bytes) : '–'), el('span', '', ''));
  row.appendChild(fig);
  const more = el('button', 'more', '⋯');
  more.type = 'button';
  more.setAttribute('aria-label', T('actions'));
  more.onclick = (e) => { e.stopPropagation(); Office.menu(e, planMenu(p)); };
  row.appendChild(more);
  row.onclick = (e) => { if (!e.target.closest('button, a, [data-own]')) planDialog(p); };
  row.oncontextmenu = (e) => { e.preventDefault(); Office.menu(e, planMenu(p)); };
  return row;
}

function planMenu(p) {
  const ok = Office.agent.running;
  return [
    { text: T('plan.edit'), act: ok ? () => planDialog(p) : null },
    { text: T('plan.run_now'), act: ok ? () => planRunNow(p) : null },
    { text: p.enabled ? T('plan.pause') : T('plan.resume'), act: ok ? () => planToggle(p) : null },
    { separator: true },
    { text: T('plan.delete'), act: ok ? () => planDelete(p) : null, kind: 'danger' },
  ];
}

async function planRunNow(p) {
  Office.toast(T('plan.running', { name: p.label }));
  const j = await Office.api.post(`${ID}.plan_run`, { id: p.id });
  if (!j.ok) { failed(j); return; }
  setState(j.state);
  const r = j.result || {};
  if ((r.detail || []).length) Office.showErrors(T('plan.result.' + r.result), r.detail, ID);
  else Office.toast(T('plan.made', { created: r.created || 0, deleted: r.deleted || 0 }));
}

async function planToggle(p) {
  const j = await Office.api.post(`${ID}.plan_toggle`, { id: p.id, enabled: !p.enabled });
  if (!j.ok) { failed(j); return; }
  setState(j.state);
  Office.toast(p.enabled ? T('plan.paused_now', { name: p.label }) : T('plan.resumed', { name: p.label }));
}

function planDelete(p) {
  Office.dialog({
    title: T('plan.delete_title', { name: p.label }),
    body: el('p', '', T('plan.delete_text', { n: p.count })),
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('plan.delete'), kind: 'danger', act: async () => {
        const j = await Office.api.post(`${ID}.plan_delete`, { id: p.id });
        if (!j.ok) { failed(j); return false; }
        setState(j.state);
        return true;
      } },
    ],
  });
}

/** New or changed schedule: what, when, how many to keep */
function planDialog(p) {
  if (!state) return;
  const chosen = new Set(p ? p.targets : []);
  const box = el('div', 'sp-plan-form');
  const field = (label, input, hint) => {
    const f = el('div', 'field');
    f.append(el('label', '', label), input);
    if (hint) f.appendChild(el('small', '', hint));
    return f;
  };
  const input = (value, type) => {
    const i = el('input', 'input');
    if (type) i.type = type;
    i.value = value;
    return i;
  };

  const label = input(p ? p.label : T('plan.default_label'));
  label.maxLength = 40;
  box.appendChild(field(T('plan.label'), label, T('plan.label_hint')));

  // when
  const every = planEvery(p ? p.cron : '0 * * * *');
  const kind = el('select', 'picker');
  ['hourly', 'daily', 'weekly', 'custom'].forEach((k) => kind.appendChild(new Option(T('plan.every.' + k), k)));
  kind.value = every.kind;
  const pad = (n) => String(n).padStart(2, '0');
  const minute = input(String(every.minute ?? 0), 'number');
  minute.min = 0;
  minute.max = 59;
  const time = input(every.h !== undefined ? `${pad(every.h)}:${pad(every.m)}` : '03:00', 'time');
  const dow = el('select', 'picker');
  WEEKDAYS.forEach((d) => dow.appendChild(new Option(T('plan.dow.' + d), String(d))));
  dow.value = String(every.dow ?? 0);
  const cron = input(every.cron || (p ? p.cron : '0 */6 * * *'));
  cron.classList.add('mono');
  cron.spellcheck = false;
  const whenRow = el('div', 'sp-when');
  const whenFields = {
    hourly: field(T('plan.at_minute'), minute),
    daily: field(T('plan.at_time'), time),
    weekly: el('div', 'sp-when'),
    custom: field(T('plan.cron'), cron, T('plan.cron_hint')),
  };
  whenFields.weekly.append(field(T('plan.on_day'), dow), field(T('plan.at_time'), time.cloneNode()));
  const weeklyTime = whenFields.weekly.querySelector('input');
  weeklyTime.value = time.value;
  const showWhen = () => {
    whenRow.innerHTML = '';
    whenRow.append(field(T('plan.every_label'), kind), whenFields[kind.value]);
  };
  kind.onchange = showWhen;
  showWhen();
  box.appendChild(whenRow);

  // keep
  const keepRow = el('div', 'sp-when');
  const keep = input(String(p ? p.keep : 24), 'number');
  keep.min = 1;
  keep.max = 1000;
  const days = input(String(p ? p.max_days : 0), 'number');
  days.min = 0;
  days.max = 3650;
  keepRow.append(field(T('plan.keep'), keep, T('plan.keep_hint')), field(T('plan.days'), days, T('plan.days_hint')));
  box.appendChild(keepRow);

  // what
  const recursive = check(T('create.recursive'), T('plan.recursive_hint'));
  recursive.input.checked = p ? !!p.recursive : true;
  const picker = targetPicker(chosen, recursive.input, () => {});
  box.appendChild(picker.field);
  const asleep = check(T('plan.skip_asleep'), T('plan.skip_asleep_hint'));
  asleep.input.checked = p ? !!p.skip_asleep : true;
  box.append(recursive.label, asleep.label);
  box.appendChild(el('p', 'callout', T('plan.note', { name: state?.plans?.runner?.name || 'unraid-secretary-office_snapshots' })));

  const cronOf = () => {
    const [h, m] = (kind.value === 'weekly' ? weeklyTime.value : time.value).split(':').map(Number);
    if (kind.value === 'hourly') return `${Math.min(59, Math.max(0, +minute.value || 0))} * * * *`;
    if (kind.value === 'daily') return `${m} ${h} * * *`;
    if (kind.value === 'weekly') return `${m} ${h} * * ${dow.value}`;
    return cron.value.trim();
  };

  Office.dialog({
    title: p ? T('plan.edit_title', { name: p.label }) : T('plan.new_title'),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('plan.save'), kind: '', act: async () => {
        if (!chosen.size) { Office.toast(T('plan.need_targets'), true); return false; }
        if (!label.value.trim()) { Office.toast(T('plan.need_label'), true); label.focus(); return false; }
        const plan = {
          id: p ? p.id : '', label: label.value.trim(), targets: [...chosen], recursive: recursive.input.checked,
          cron: cronOf(), keep: +keep.value, max_days: +days.value || 0, skip_asleep: asleep.input.checked,
        };
        const j = await Office.api.post(`${ID}.plan_save`, { plan });
        if (!j.ok) { failed(j); return false; }
        setState(j.state);
        Office.toast(T('plan.saved', { when: fmt.cron(plan.cron) }));
        return true;
      } },
    ],
  });
  picker.draw();
  label.focus();
}

/**
 * Where snapshots go: pools and their datasets, btrfs disks — with a filter,
 * whole pools at once, and what comes along when "recursive" is ticked.
 * Used by "New snapshot" and by the schedules.
 */
function targetPicker(chosen, recursive, onCount) {
  const all = targets();
  const byId = new Map(all.map((v) => [v.id, v]));
  const targetField = el('div', 'field');
  const fhead = el('div', 'field-title');
  const filter = el('input', 'input small');
  filter.type = 'search';
  filter.placeholder = Office.t('common.filter');
  filter.setAttribute('aria-label', T('create.filter'));
  fhead.append(el('span', '', T('create.from')), filter);
  const list = el('div', 'targets');
  targetField.append(fhead, list);


  const below = (v) => all.filter((w) => w.fs === 'zfs' && w.name.startsWith(v.name + '/'));

  function draw() {
    list.innerHTML = '';
    const q = filter.value.trim().toLowerCase();
    const along = new Set();
    if (recursive.checked) {
      for (const id of chosen) {
        const v = byId.get(id);
        if (v && v.fs === 'zfs') below(v).forEach((w) => along.add(w.id));
      }
    }
    const pools = new Map();
    for (const v of all) {
      if (q && !v.name.toLowerCase().includes(q)) continue;
      const key = v.fs === 'btrfs' ? T('array') + ' (btrfs)' : v.pool;
      if (!pools.has(key)) pools.set(key, []);
      pools.get(key).push(v);
    }
    for (const [pool, items] of pools) {
      const ph = el('label', 'target-pool');
      const pcb = el('input');
      pcb.type = 'checkbox';
      const inside = items.filter((v) => chosen.has(v.id)).length;
      pcb.checked = inside === items.length;
      pcb.indeterminate = inside > 0 && inside < items.length;
      pcb.onchange = () => { items.forEach((v) => (pcb.checked ? chosen.add(v.id) : chosen.delete(v.id))); draw(); };
      ph.append(pcb, pool, el('small', '', items[0].fs === 'btrfs' ? T('create.whole_disks') : T('create.pool')));
      list.appendChild(ph);
      for (const v of items) {
        const row = el('label', 'target' + (along.has(v.id) && !chosen.has(v.id) ? ' along' : ''));
        const cb = el('input');
        cb.type = 'checkbox';
        cb.checked = chosen.has(v.id);
        cb.onchange = () => { if (cb.checked) chosen.add(v.id); else chosen.delete(v.id); draw(); };
        const n = el('span', 'ds-name');
        if (v.fs === 'zfs') {
          const parts = v.name.split('/');
          n.style.paddingLeft = `${(parts.length - 1) * 14}px`;
          if (parts.length > 1) n.append(el('i', '', parts.slice(0, -1).join('/') + '/'));
          n.append(parts[parts.length - 1]);
        } else {
          n.append(v.name);
        }
        let info = fmt.size(v.used);
        if (v.asleep) info = '💤 ' + info;
        if (along.has(v.id) && !chosen.has(v.id)) info = T('create.comes_along') + ' · ' + info;
        row.append(cb, n, el('small', '', info));
        list.appendChild(row);
      }
    }
    if (!pools.size) list.appendChild(el('p', 'empty', T('create.nothing_found')));
    onCount(chosen.size + [...along].filter((id) => !chosen.has(id)).length);
  }


  filter.oninput = draw;
  recursive.addEventListener('change', draw);
  return { field: targetField, draw, byId };
}

function createDialog(preselected) {
  if (!state) return;
  const chosen = new Set(preselected || []);
  const box = el('div');

  const nameField = el('div', 'field');
  const nameLabel = el('label', '', T('create.name'));
  const name = el('input', 'input mono');
  name.id = 'snapshot-new-name';
  nameLabel.htmlFor = name.id;
  name.value = T('default_prefix') + stamp();
  name.autocomplete = 'off';
  name.spellcheck = false;
  const nameHint = el('small', '', T('create.name_hint'));
  nameField.append(nameLabel, name, nameHint);
  box.appendChild(nameField);

  const recursive = check(T('create.recursive'), T('create.recursive_hint'));
  const picker = targetPicker(chosen, recursive.input, (n) => {
    if (d) {
      d.buttons[1].textContent = n ? T('create.confirm', { n }) : T('create.create');
      d.buttons[1].disabled = n === 0;
    }
  });
  box.appendChild(picker.field);
  const keep = check(T('create.hold'), T('create.hold_hint'));
  box.append(recursive.label, keep.label);
  box.appendChild(el('p', 'callout', T('create.note')));
  let d = null;
  const draw = picker.draw;
  const byId = picker.byId;


  d = Office.dialog({
    title: T('create.title'),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('create.create'), kind: '', act: async () => {
        const n = name.value.trim();
        if (!NAME_RULE.test(n)) { nameHint.className = 'missing'; name.focus(); return false; }
        if (!chosen.size) return false;
        const sleepy = [...chosen].map((id) => byId.get(id)).filter((v) => v && v.asleep);
        if (sleepy.length) Office.toast(T('create.waking', { disks: sleepy.map((v) => v.pool).join(', ') }));
        setBusy(true, view && view.newBtn);
        const j = await Office.api.post(`${ID}.create`, { targets: [...chosen], name: n, recursive: recursive.input.checked, hold: keep.input.checked });
        setBusy(false, view && view.newBtn);
        if (!j.ok) { failed(j); return false; }
        fresh = new Set(j.created || []);
        setState(j.state);
        setTimeout(() => fresh.clear(), 4000);
        const made = (j.created || []).length;
        if (made) Office.toast(T('created', { n: made }));
        if ((j.failures || []).length) Office.showErrors(made ? T('create.partly') : T('create.failed'), j.failures, ID);
        return true;
      } },
    ],
  });
  draw();
  name.focus();
}

function check(text, small) {
  const label = el('label', 'check');
  const input = el('input');
  input.type = 'checkbox';
  const span = el('span', '', text);
  if (small) span.appendChild(el('small', '', small));
  label.append(input, span);
  return { label, input };
}

// ------------------------------------------------------------------ properties
function properties(s) {
  const box = el('div');
  const dl = el('dl', 'props');
  const line = (k, v, small, mono) => {
    dl.appendChild(el('dt', '', k));
    const dd = el('dd', mono ? 'mono' : '', v);
    if (small) dd.appendChild(el('small', '', small));
    dl.appendChild(dd);
    return dd;
  };
  line(T('p.name'), s.name, null, true);
  line(s.fs === 'btrfs' ? T('p.disk') : s.fs === 'vm' ? 'VM' : T('p.dataset'), s.ds, null, true);
  line(T('p.kind'), kindText(s));
  line(T('p.source'), source(s).label);
  if (s.t) line(T('p.created'), fmt.date(s.t, true), fmt.relative(s.t));
  if (s.detected === 'mount') line(T('p.detected'), T('p.detected_value'), T('p.detected_hint'));
  if (s.used !== null && s.used !== undefined) line(T('p.used'), fmt.size(s.used), T('p.used_hint'));
  if (s.refer !== null && s.refer !== undefined) line(T('p.data'), fmt.size(s.refer), T('p.data_hint'));
  if (s.written !== null && s.written !== undefined) line(T('p.written'), fmt.size(s.written), T('p.written_hint'));
  if (s.fs === 'zfs') line(T('p.held'), s.holds && s.holds.length ? Office.t('common.yes') + ' — ' + s.holds.join(', ') : Office.t('common.no'));
  if ((s.mounts || []).length) {
    const dd = line(T('p.mounted'), s.mounts.map(mountText).join('\n'),
      usedByBackup(s) ? T('p.mounted_backup') : fixedMounts(s).length ? T('p.mounted_fixed') : T('p.mounted_auto'), true);
    dd.style.whiteSpace = 'pre-line';
  }
  if (s.clones && s.clones.length) line(T('p.clones'), s.clones.join(', '), null, true);
  if (s.fs === 'vm') {
    if (s.description) line(T('p.description'), s.description);
    if (s.state) line(T('p.vm_state'), s.state);
    if (s.method) line(T('p.method'), s.method === 'QEMU' ? T('p.method_qemu') : s.method === 'ZFS' ? T('kind.zfs') : s.method);
    if (s.parent) line(T('p.parent'), s.parent, null, true);
    if (s.overlay !== null && s.overlay !== undefined) line(T('p.overlay'), fmt.size(s.overlay), T('p.overlay_hint'));
    if ((s.files || []).length) line(T('p.files'), s.files.join('\n'), s.active ? T('vm_current_title') : null, true).style.whiteSpace = 'pre-line';
    if (s.orphaned) line('VM', T('vm_gone'), T('vm_gone_title'));
  }
  if (s.path) {
    const dd = line(T('p.path'), s.path, T('p.path_hint'), true);
    const b = el('button', 'btn plain small', Office.t('common.copy'));
    b.type = 'button';
    b.style.marginTop = '5px';
    b.onclick = () => Office.copy(s.path);
    dd.appendChild(b);
  }
  if (s.guid) line('GUID', s.guid, null, true);
  if (!deletable(s)) line(T('delete'), T('p.not_possible'), whyNot(s));
  box.appendChild(dl);

  const buttons = [{ text: Office.t('common.close') }];
  if (deletable(s) && Office.agent.running) buttons.unshift({ text: T('delete') + '…', kind: 'danger plain', act: () => { setTimeout(() => askDelete([s.id]), 0); } });
  Office.dialog({ title: s.docker ? T('kind.docker') : T('p.title'), body: box, buttons });
}
})();
