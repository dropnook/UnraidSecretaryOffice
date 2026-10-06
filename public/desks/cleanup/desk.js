/* Ms. Dustdevil — clears away what nobody uses any more: Docker templates
   without a container, Compose stacks without containers, appdata folders
   nothing names, what deleted VMs left behind (domains folders, NVRAM, TPM
   states, unused disk images) and Docker's own leftovers (images, volumes,
   build cache), and what Mr. Restori left next to what he brought back
   (from his journals). Everything she can rename goes into her storeroom first,
   from where it can be put back or emptied for good; Docker's leftovers can
   only be removed. And she straightens what hangs crooked: containers
   without a picture get one (the old template or override file goes into
   the storeroom first). The agent part lives in agent/desks/cleanup.php. */
(() => {
'use strict';

const ID = 'cleanup';
const T = Office.scope(ID);
const { el, fmt } = Office;
const SECTIONS = ['templates', 'stacks', 'appdata', 'vms', 'scripts', 'docker', 'icons', 'leftovers', 'trash'];
const ICONS = { templates: '📄', stacks: '🧩', appdata: '🗃️', vms: '🖥️', scripts: '📜', docker: '🐳', icons: '🖼️', leftovers: '📦', trash: '🗑️' };
const GROUPS = {
  templates: ['leftover', 'unused', 'duplicate', 'noname', 'stray_only_here', 'stray_newer', 'stray_name_exists', 'stray_older', 'stray_copy', 'unknown', 'in_use'],
  stacks: ['leftover', 'broken', 'unused', 'unknown', 'in_use'],
  appdata: ['unused', 'check', 'unknown', 'used'],
  vms: ['broken', 'orphan', 'unused', 'check', 'media', 'unknown', 'used'],
  scripts: ['broken', 'dead', 'idle', 'used'],
  docker: ['dangling', 'volume', 'unused', 'cache', 'used'],
  icons: ['template', 'compose', 'none', 'ok'],
  leftovers: ['leftover', 'way_back', 'unknown'],     // shown per restore (renderLeftovers), these for the CSV
};
const CANDIDATES = {
  templates: ['leftover', 'unused', 'duplicate', 'noname', 'stray_only_here', 'stray_newer', 'stray_name_exists', 'stray_older', 'stray_copy'],
  stacks: ['leftover', 'broken', 'unused'],
  appdata: ['unused', 'check'],
  vms: ['broken', 'orphan', 'unused', 'check', 'media'],
  scripts: ['broken', 'dead', 'idle'],
  docker: ['dangling', 'volume', 'unused', 'cache'],
  icons: ['template', 'compose', 'none'],
  leftovers: ['leftover', 'way_back'],
};
const CLOSED = ['in_use', 'used', 'unknown', 'ok'];  // folded until opened
const KIND_ICONS = { container: '🐳', template: '📄', stack: '🧩', compose: '🧩', flash: '💾', vm: '🖥️' };
const ITEM_ICONS = { template: '📄', stray: '📄', vmdef: '🖥️', userscript: '📜', stack: '🧩', appdata: '🗃️', domain: '🖥️', iso: '💿', nvram: '🔐', tpm: '🔐', snapshotdb: '🔐', icon: '🖼️', leftover: '📦' };
const ROOMS = ['templates', 'stacks', 'appdata', 'vms', 'scripts', 'docker', 'icons', 'leftovers'];     // where she finds something (not the storeroom)
const POLL_MS = 3000;

let state = null;
let lookedAgain = false;      // looked again this visit because Mr. Restori finished something since her last look
let view = null;
let section = SECTIONS.includes(Office.store('cleanup.section')) ? Office.store('cleanup.section') : '';   // '' = none open
let folded = Office.storeJson('cleanup.folded') || {};
const selection = new Set();
const expanded = new Set();
const picks = new Map();    // container picture: id => the address chosen here (else her suggestion)
let shown = [];             // rows that can unfold: { open(), set(bool) }
let busy = false;
let timer = null;
let query = '';             // the filter above the rooms: every word must appear

// ------------------------------------------------------------------ loading
async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state && j.state.docker && typeof j.state.docker === 'object') setState(j.state);
  else if (view) render();
  // Mr. Restori finished a restore after her last look: she looks again by herself (once per visit) — his leftovers show
  if (view && !fresh && !lookedAgain && state && state.restore_newer && Office.agent.running) {
    lookedAgain = true;
    return load(true);
  }
  return j;
}

function setState(s) {
  state = s;
  const ok = new Set(entries(section).filter(selectable).map((e) => e.id));
  for (const id of [...selection]) if (!ok.has(id)) selection.delete(id);
  if (view) render();
  watch();
}

/** While she measures or searches in the background, look again every few seconds */
function watch() {
  clearTimeout(timer);
  if (!view || !state || !state.jobs || !state.jobs.busy) return;
  timer = setTimeout(async () => {
    if (document.hidden || Office.dialogOpen() || busy) { watch(); return; }
    await load(false);
  }, POLL_MS);
}

// ------------------------------------------------------------------ helpers
function entries(sec) {
  if (!state) return [];
  return ({
    templates: state.templates.list, stacks: state.stacks.list, appdata: state.appdata.list,
    vms: state.vms.list, scripts: state.scripts.list, docker: state.docker.list, icons: (state.icons || {}).list,
    leftovers: (state.leftovers || {}).list,
  })[sec] || [];
}
const candidates = (sec) => entries(sec).filter((e) => CANDIDATES[sec].includes(e.category));
const removable = (e) => ['image', 'volume', 'cache'].includes(e.kind);
/** The picture a container would get: the one chosen here, else the first one found that loads */
const iconChoice = (e) => picks.get(e.id) || e.suggest || null;
/** Docker's leftovers in use can't be chosen at all; everything else in use only with a warning; a container only with a picture to hang */
const selectable = (e) => !!state && e.why === null && !state.backup_running && !(removable(e) && e.category === 'used')
  && !(e.kind === 'icon' && (e.category === 'ok' || e.category === 'none' || !iconChoice(e)));
const label = (e) => (e.kind === 'template' || e.kind === 'stray' ? e.file : e.kind === 'userscript' ? e.name : e.kind === 'stack' ? e.folder : e.kind === 'cache' ? T('cache.name') : e.name);
const sum = (list) => list.reduce((a, e) => a + (e.bytes || 0), 0);
const words = () => query.trim().toLowerCase().split(/\s+/).filter(Boolean);
/** Does an entry match the filter? Its name and what it is connected to (image, containers, stack, paths) */
function matches(e) {
  const w = words();
  if (!w.length) return true;
  const hay = [label(e), e.name, e.file, e.folder, e.image, e.project, e.uuid, e.from, e.path, e.restore && e.restore.what, ...(e.refs || []),
    ...(e.used_by || []).map((u) => u.name), ...(e.containers || []).map((c) => c.name), ...(e.parts || []).map((p) => p.path)]
    .filter(Boolean).join(' ').toLowerCase();
  return w.every((x) => hay.includes(x));
}
/** VMs only with the VM service switched on, Docker's rooms (appdata too: who uses it is told by Docker) only with Docker */
const visible = (sec) => (sec === 'vms' ? state.vms.enabled : sec === 'scripts' ? state.scripts.installed
  : sec === 'leftovers' ? !!state.leftovers && state.leftovers.restores > 0
  : sec === 'icons' ? state.docker.enabled && !!state.icons : ['templates', 'stacks', 'appdata', 'docker'].includes(sec) ? state.docker.enabled : true);

function chip(text, cls, tip) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (tip) c.title = tip;
  return c;
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
  const box = el('div', 'mono cl-lines');
  box.textContent = list.join('\n');
  return box;
}

const when = (t) => (t ? `${fmt.date(t)} · ${fmt.relative(t)}` : '');

function emptyNote(text) {
  return el('p', 'empty', text);
}

function whyChip(e) {
  if (!state) return null;
  if (state.backup_running && !removable(e)) return chip('⏸ ' + T('why.backup'), 'quiet', T('why.backup_text'));
  if (!e.why) return null;
  return chip((e.why === 'measuring' || e.why === 'checking' ? '⏳ ' : '') + T('why.' + e.why), e.why === 'dataset' || e.why === 'asleep' ? '' : 'quiet', T('why.' + e.why + '_text'));
}

function usedChips(list, more) {
  const out = list.slice(0, 4).map((u) => chip(`${KIND_ICONS[u.kind] || '•'} ${u.name}`, u.weak ? 'quiet' : 'accent', T('kind.' + u.kind) + (u.weak ? ' · ' + T('weak') : '')));
  const rest = list.length - 4 + (more || 0);
  if (rest > 0) out.push(chip(`+${rest}`, 'quiet', T('more_names', { n: rest })));
  return out;
}

const noteText = (n) => ({
  same_name: () => T('note.same_name_text'),
  weak: () => T('note.weak_text', { names: n.names.join(', ') }),
  fresh: () => T('note.fresh_text', { days: 30 }),
  named_by: () => T('note.named_by_text', { names: n.names.map((x) => x.name).join(', ') }),
  stack: () => (n.exists ? T('note.stack_text', { name: n.name }) : T('note.stack_gone_text', { name: n.name })),
})[n.why]();

function noteChips(e) {
  return (e.notes || []).map((n) => {
    if (n.why === 'same_name') return chip(T('note.same_name', { icon: KIND_ICONS[n.kind] || '•', name: n.name }), 'warn', noteText(n));
    if (n.why === 'weak') return chip(T('note.weak'), 'warn', noteText(n));
    if (n.why === 'fresh') return chip(T('note.fresh'), 'warn', noteText(n));
    if (n.why === 'named_by') return chip(T('note.named_by', { n: n.names.length }), e.category === 'used' ? 'quiet' : 'warn', noteText(n));
    return chip(`🧩 ${n.name}`, n.exists ? 'warn' : 'quiet', noteText(n));
  });
}

// ------------------------------------------------------------------ the desk
Office.desk({
  id: ID,

  mount(root) {
    view = build(root);
    lookedAgain = false;
    render();
    load(false);
  },

  unmount() {
    view = null;
    clearTimeout(timer);
    selection.clear();
  },

  poll() { load(false); },

  agentChanged() { if (view) render(); },

  menu() {
    return [{ text: T('scan'), act: () => scan(false), disabled: !Office.agent.running || busy }];
  },

  async reception() {
    if (!state) await load(false);
    if (!state) return { bubble: T('bubble.no_data'), facts: [] };
    const facts = [];
    for (const sec of ROOMS) {
      const c = candidates(sec);
      if (c.length) facts.push(T('fact.' + sec, { n: c.length, size: fmt.size(sum(c)) }));
    }
    if (state.trash.runs.length) facts.push(T('fact.trash', { size: state.trash.bytes === null ? '…' : fmt.size(state.trash.bytes) }));
    return { bubble: bubbleText(), facts };
  },
});

// ------------------------------------------------------------------ building
function build(root) {
  const v = {};
  v.scanBtn = el('button', 'btn plain');
  v.scanBtn.type = 'button';
  v.scanBtn.append(el('span', 'spin'), T('scan'));
  v.scanBtn.title = T('scan_title');
  v.scanBtn.onclick = () => scan(false);
  v.search = el('input', 'search');
  v.search.type = 'search';
  v.search.placeholder = T('search');
  v.search.autocomplete = 'off';
  v.search.spellcheck = false;
  v.search.value = query;
  v.search.oninput = () => {
    query = v.search.value;
    // what the filter hides is no longer chosen: nothing is put away that isn't in sight
    for (const e of entries(section)) if (selection.has(e.id) && !matches(e)) selection.delete(e.id);
    filterMark();
    renderSection();
    updateSelbar();
  };
  const head = Office.deskHead({ id: ID, icon: Office.desks.get(ID).icon }, { actions: [v.search, v.scanBtn] });
  v.bubble = head.bubble;
  root.appendChild(head.head);
  root.appendChild(Office.pageHelp(ID, [
    [T('help.order'), T('help.order_text')],
    [T('help.trash'), T('help.trash_text')],
    [T('section.templates'), T('help.templates_text')],
    [T('section.stacks'), T('help.stacks_text')],
    [T('section.appdata'), T('help.appdata_text')],
    [T('help.check'), T('help.check_text')],
    [T('section.vms'), T('help.vms_text')],
    [T('section.scripts'), T('help.scripts_text')],
    [T('section.docker'), T('help.docker_text')],
    [T('section.icons'), T('help.icons_text')],
    [T('section.leftovers'), T('help.leftovers_text')],
    [T('help.loop'), T('help.loop_text')],
    [T('help.sizes'), T('help.sizes_text')],
    [T('help.safe'), T('help.safe_text')],
  ]));

  v.notice = el('div');
  root.appendChild(v.notice);

  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('rooms'), T('rooms_sub')));
  v.filterNote = el('p', 'callout warn cl-filter-note');
  v.filterNote.hidden = true;
  v.tiles = el('div', 'cards');
  v.body = el('div', 'section');
  s.append(v.filterNote, v.tiles, v.body);
  root.appendChild(s);
  return v;
}

/** A set filter is hard to miss: red frame on the field, a warning above the rooms */
function filterMark() {
  if (!view) return;
  const on = words().length > 0;
  view.search.classList.toggle('cl-filter-on', on);
  view.search.title = on ? T('filter_on_short') : '';
  view.filterNote.hidden = !on;
  view.filterNote.innerHTML = '';
  if (!on) return;
  view.filterNote.append(T('filter_on', { query: query.trim() }), ' ');
  const b = el('button', 'btn small plain', T('filter_clear'));
  b.type = 'button';
  b.onclick = () => { query = ''; view.search.value = ''; filterMark(); renderSection(); updateSelbar(); };
  view.filterNote.appendChild(b);
}

function render() {
  if (!view) return;
  filterMark();
  view.bubble.innerHTML = '';
  view.bubble.append(Office.withGreeting(ID, bubbleText()));
  view.scanBtn.disabled = !Office.agent.running || busy;
  renderNotice();
  renderTiles();
  renderSection();
  updateSelbar();
}

function bubbleText() {
  if (!state) return Office.agent.running ? T('bubble.loading') : T('bubble.no_data');
  const found = [];
  for (const sec of ROOMS) {
    const c = candidates(sec);
    if (c.length) found.push(T('bubble.' + sec, { n: c.length, size: fmt.size(sum(c)) }));
  }
  let text = found.length ? T('bubble.found', { list: found.join(', ') }) : T('bubble.spotless');
  if (state.docker.enabled && !state.docker.ok) text = T('bubble.docker_down') + ' ' + text;
  if (state.trash.runs.length && state.trash.bytes !== null) text += ' ' + T('bubble.trash', { size: fmt.size(state.trash.bytes) });
  if (state.jobs.busy) text += ' ' + T('bubble.busy');
  return text;
}

function renderNotice() {
  const box = view.notice;
  box.innerHTML = '';
  if (!state) return;
  if (state.backup_running) box.appendChild(el('p', 'callout', T('notice.backup')));
  if (state.docker.enabled && !state.docker.ok) box.appendChild(el('p', 'callout warn', T('notice.docker_down')));
}

// ------------------------------------------------------------------ tiles
function tileLine(sec) {
  if (sec === 'trash') {
    const runs = state.trash.runs;
    return [runs.length ? T('tile.trash', { n: runs.length }) : T('tile.trash_empty'), !runs.length ? '' : state.trash.bytes === null ? '…' : fmt.size(state.trash.bytes)];
  }
  const all = entries(sec);
  const c = candidates(sec);
  if (sec === 'vms' && !state.vms.ok) return [T('tile.vm_off'), ''];
  if (!all.length) return [T('tile.none'), ''];
  if (sec === 'icons') return [c.length ? T('tile.look', { n: c.length }) : T('tile.pictured'), T('tile.total', { n: all.length })];
  return [c.length ? T('tile.look', { n: c.length }) : T('tile.tidy'), sec === 'templates' || !c.length ? T('tile.total', { n: all.length }) : fmt.size(sum(c))];
}

function renderTiles() {
  const box = view.tiles;
  box.innerHTML = '';
  if (!state) return;
  for (const sec of SECTIONS) {
    if (!visible(sec)) continue;
    const card = el('button', 'card' + (section === sec ? ' active' : ''));
    card.type = 'button';
    card.setAttribute('aria-pressed', String(section === sec));
    const head = el('div', 'card-head');
    head.append(el('span', 'cl-tile-icon', ICONS[sec]), el('span', 'card-name', T('section.' + sec)));
    const n = sec === 'trash' ? state.trash.runs.length : candidates(sec).length;
    if (n) head.appendChild(el('span', 'cl-count', fmt.number(n)));
    const [line, right] = tileLine(sec);
    const fig = el('div', 'card-figures');
    fig.append(el('span', '', line), el('span', '', right));
    card.append(head, fig);
    card.onclick = () => pick(section === sec ? '' : sec);       // the open tile closes again
    box.appendChild(card);
  }
}

function pick(sec) {
  section = sec;
  Office.store('cleanup.section', sec || null);
  selection.clear();
  Office.keepInPlace(view.tiles, () => { renderTiles(); renderSection(); });
  updateSelbar();
}

// ------------------------------------------------------------------ sections
function renderSection() {
  if (!view) return;
  const body = view.body;
  body.innerHTML = '';
  shown = [];
  if (!state) { body.appendChild(emptyNote(Office.agent.running ? T('bubble.loading') : T('bubble.no_data'))); return; }
  if (!section || !visible(section)) return;
  const right = [];
  const csv = el('button', 'btn small plain', T('csv'));
  csv.type = 'button';
  csv.title = T('csv_title');
  csv.onclick = () => exportCsv(section);
  right.push(csv);
  if (section === 'trash') {
    const all = state.trash.runs.filter((r) => !r.purging);
    if (all.length > 1) {
      const b = el('button', 'btn small danger plain', T('purge.all'));
      b.type = 'button';
      b.disabled = !Office.agent.running || state.backup_running;
      b.onclick = () => purgeDialog(all);
      right.push(b);
    }
  }
  body.appendChild(Office.sectionHead(T('section.' + section), T('section.' + section + '_sub'), ...right));
  if (section === 'templates') body.appendChild(templatesInfo());
  if (section === 'appdata') body.appendChild(appdataInfo());
  if (section === 'vms') body.appendChild(vmsInfo());
  if (section === 'scripts') body.appendChild(scriptsInfo());
  if (section === 'docker') body.appendChild(dockerInfo());
  if (section === 'icons') body.appendChild(iconsInfo());
  if (section === 'leftovers') body.appendChild(leftoversInfo());
  if (section === 'stacks' && !state.stacks.exists) { body.appendChild(emptyNote(T('empty.no_compose', { path: state.stacks.root }))); return; }
  if (section === 'trash') renderTrash(body);
  else if (section === 'leftovers') renderLeftovers(body);
  else renderList(body, section);
  if (shown.length > 1) body.insertBefore(unfoldBar(body), body.querySelector('.box'));
}

/** "Unfold all" / "Fold all" for the rows (and groups) of the section */
function unfoldBar(body) {
  const bar = el('div', 'toolbar cl-unfold');
  const b = el('button', 'btn small plain');
  b.type = 'button';
  const set = () => { b.textContent = shown.some((x) => !x.open()) ? T('unfold_all') : T('fold_all'); };
  b.onclick = () => {
    const open = shown.some((x) => !x.open());
    Office.keepInPlace(bar, () => {
      body.querySelectorAll('.group').forEach((g) => g.classList.toggle('closed', !open));
      shown.forEach((x) => x.set(open));
    });
    set();
  };
  body.addEventListener('click', () => setTimeout(set, 0));
  set();
  bar.appendChild(b);
  return bar;
}

function asleepCallout(disks) {
  const p = el('p', 'callout');
  p.append(T('asleep', { disks: disks.join(', ') }), ' ');
  const b = el('button', 'btn small plain', T('wake'));
  b.type = 'button';
  b.disabled = !Office.agent.running || busy;
  b.onclick = () => scan(true);
  p.appendChild(b);
  return p;
}

function templatesInfo() {
  const t = state.templates;
  const box = el('div', 'cl-info');
  box.appendChild(el('p', 'role', T('strays.where', { dir: t.dir })));
  if (t.strays_searching) box.appendChild(el('p', 'role', '⏳ ' + T('strays.searching')));
  else if (t.strays_at) box.appendChild(el('p', 'role', T('strays.searched', { when: fmt.relative(t.strays_at) })));
  if (t.strays_skipped) {
    const p = el('p', 'role', T('strays.skipped', { n: t.strays_skipped }));
    p.title = t.strays_skipped_dirs.join('\n');
    box.appendChild(p);
  }
  return box;
}

function appdataInfo() {
  const a = state.appdata;
  const box = el('div', 'cl-info');
  box.appendChild(el('p', 'role', T('share_where', { share: a.share, places: a.places.map((p) => p.path).join(', ') || '–' })));
  if (a.mounters.length) box.appendChild(el('p', 'role', T('appdata.mounters', { names: a.mounters.join(', ') })));
  if (!a.complete && state.docker.ok) box.appendChild(el('p', 'role', '⏳ ' + T('appdata.checking')));
  else if (a.flash_at) box.appendChild(el('p', 'role', T('appdata.searched', { when: fmt.relative(a.flash_at) })));
  if (a.asleep.length) box.appendChild(asleepCallout(a.asleep));
  return box;
}

function vmsInfo() {
  const v = state.vms;
  const box = el('div', 'cl-info');
  if (!v.ok) { box.appendChild(el('p', 'callout', T('vms.off'))); return box; }
  box.appendChild(el('p', 'role', T('vms.count', { n: v.count })));
  box.appendChild(el('p', 'role', T('share_where', { share: v.domains.share, places: v.domains.places.map((p) => p.path).join(', ') || '–' })));
  box.appendChild(el('p', 'role', T('vms.isos', { share: v.isos.share, places: v.isos.places.map((p) => p.path).join(', ') || '–' })));
  if (!v.complete) box.appendChild(el('p', 'role', '⏳ ' + T('appdata.checking')));
  const asleep = [...new Set([...v.domains.asleep, ...v.isos.asleep])];
  if (asleep.length) box.appendChild(asleepCallout(asleep));
  return box;
}

function scriptsInfo() {
  const box = el('div', 'cl-info');
  box.appendChild(el('p', 'role', T('us.where', { dir: state.scripts.dir })));
  if (state.scripts.boot) box.appendChild(el('p', 'role', T('us.boot', { when: fmt.date(state.scripts.boot) })));
  return box;
}

function dockerInfo() {
  const box = el('div', 'cl-info');
  if (!state.docker.ok) { box.appendChild(el('p', 'callout warn', T('notice.docker_down'))); return box; }
  box.appendChild(el('p', 'role', T('docker.note')));
  if (state.docker.cache_at) box.appendChild(el('p', 'role', T('docker.cache_at', { when: fmt.relative(state.docker.cache_at) })));
  return box;
}

function renderList(body, sec) {
  const all = entries(sec);
  if (!all.length) { body.appendChild(emptyNote(T('empty.' + sec))); return; }
  const list = all.filter(matches);
  if (!list.length) { body.appendChild(emptyNote(T('no_match', { query: query.trim() }))); return; }
  const box = el('div', 'box');
  for (const cat of GROUPS[sec]) {
    const items = list.filter((e) => e.category === cat);
    if (items.length) box.appendChild(group(sec, cat, items));
  }
  body.appendChild(box);
}

function group(sec, cat, items) {
  const key = `${sec}.${cat}`;
  const closed = !words().length && (folded[key] ?? CLOSED.includes(cat));      // while filtering, what matches is shown
  const box = el('div', 'group' + (closed ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');

  // a whole group at once (as far as the filter shows it) — only where nothing is still in use
  const can = CLOSED.includes(cat) ? [] : items.filter(selectable);
  const cb = el('input');
  cb.type = 'checkbox';
  cb.disabled = !can.length;
  cb.title = T('select_group');
  const sync = () => {
    const n = can.filter((e) => selection.has(e.id)).length;
    cb.checked = n > 0 && n === can.length;
    cb.indeterminate = n > 0 && n < can.length;
  };
  sync();
  cb.onclick = (e) => {
    e.stopPropagation();
    const every = can.every((x) => selection.has(x.id));
    can.forEach((x) => (every ? selection.delete(x.id) : selection.add(x.id)));
    renderSection();
    updateSelbar();
  };

  const mid = el('div', 'group-mid');
  const title = el('div', 'group-title');
  title.append(el('span', '', T(`cat.${sec}.${cat}`)), chip(fmt.number(items.length), 'quiet'));
  const meta = [T(`cat.${sec}.${cat}_text`)];
  if (sec !== 'templates' && items.every((e) => e.bytes !== null && e.bytes !== undefined)) meta.push(fmt.size(sum(items)));     // only when all are measured
  mid.append(title, el('div', 'group-meta', meta.join(' · ')));
  head.append(cb, el('span', 'group-arrow', '▼'), mid);

  if (cat === 'used' && (sec === 'appdata' || sec === 'vms' || sec === 'docker')) {
    const missing = items.filter((f) => f.bytes === null && !f.measuring && (f.parts || f.kind === 'volume'));
    if (missing.length) {
      const b = el('button', 'btn small plain', T('measure_n', { n: missing.length }));
      b.type = 'button';
      b.title = T('measure_title');
      b.disabled = !Office.agent.running;
      b.onclick = (e) => { e.stopPropagation(); measure(missing.map((f) => f.id)); };
      head.appendChild(b);
    }
  }

  const rows = el('div', 'group-rows');
  items.forEach((e) => rows.appendChild(row(e, sync)));
  const toggle = () => {
    const now = !box.classList.contains('closed');
    box.classList.toggle('closed', now);
    folded[key] = now;
    Office.storeJson('cleanup.folded', folded);
  };
  head.onclick = (e) => { if (e.target.closest('button, input')) return; Office.keepInPlace(head, toggle); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); Office.keepInPlace(head, toggle); } };
  box.append(head, rows);
  return box;
}

const VIEWS = {
  template: () => [templateMeta, templateDetail],
  stray: () => [strayMeta, strayDetail],
  stack: () => [stackMeta, stackDetail],
  appdata: () => [folderMeta, folderDetail],
  domain: () => [folderMeta, folderDetail],
  iso: () => [folderMeta, folderDetail],
  vmdef: () => [vmdefMeta, vmdefDetail],
  userscript: () => [scriptMeta, scriptDetail],
  nvram: () => [libvirtMeta, libvirtDetail],
  tpm: () => [libvirtMeta, libvirtDetail],
  snapshotdb: () => [libvirtMeta, libvirtDetail],
  image: () => [imageMeta, imageDetail],
  volume: () => [volumeMeta, volumeDetail],
  cache: () => [cacheMeta, cacheDetail],
  icon: () => [iconMeta, iconDetail],
  leftover: () => [leftoverMeta, leftoverDetail],
};

/** A row: the checkbox selects, a click anywhere else unfolds the details */
function row(e, groupSync) {
  const [metaFn, detailFn] = VIEWS[e.kind]();
  const r = el('div', 'row unfolds' + (selection.has(e.id) ? ' selected' : ''));
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = selection.has(e.id);
  cb.disabled = !selectable(e);
  cb.setAttribute('aria-label', T('select_one', { name: label(e) }));
  cb.onchange = () => {
    if (cb.checked) selection.add(e.id); else selection.delete(e.id);
    r.classList.toggle('selected', cb.checked);
    groupSync();
    updateSelbar();
  };

  const main = el('div', 'row-main');
  const name = el('div', 'row-name link', label(e));
  name.title = T('details');
  if (e.kind === 'icon') {      // its picture in front: the one Unraid shows, or the one it would get
    name.textContent = '';
    name.classList.add('cl-pic-name');
    name.append(picture(e.category === 'ok' ? shownIcon(e) : iconChoice(e)), el('span', '', label(e)));
  }
  const meta = el('div', 'row-meta');
  const figures = el('div', 'figures');
  metaFn(e, meta, figures);
  const why = whyChip(e);
  if (why) meta.appendChild(why);
  main.append(name, meta);

  const more = el('button', 'more', '⋯');
  more.type = 'button';
  more.setAttribute('aria-label', T('actions'));
  const items = menuItems(e);
  more.disabled = !items.length;
  more.onclick = (ev) => { ev.stopPropagation(); Office.menu(ev, items); };
  r.append(cb, main, figures, more);

  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; expanded.delete(e.id); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail');
      box.appendChild(detailFn(e));
      r.appendChild(box);
      expanded.add(e.id);
      r.classList.add('open');
    }
  };
  r.onclick = (ev) => {
    if (ev.target.closest('button, a, input, select, textarea, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;      // selecting text
    Office.keepInPlace(r, () => set(!box));
  };
  shown.push({ open: () => !!box, set });
  if (expanded.has(e.id)) set(true);
  return r;
}

function menuItems(e) {
  const items = [];
  if (e.kind === 'template' || e.kind === 'stray') items.push({ text: T('show_xml'), act: () => showFile(e) });
  if (e.kind === 'stray' && ['only_here', 'newer'].includes(e.loc)) items.push({ text: T('install.button'), act: () => installDialog(e), disabled: !Office.agent.running || state.backup_running });
  if (e.kind === 'stack' && e.file) items.push({ text: T('show_compose'), act: () => showFile(e) });
  if (e.kind === 'userscript' && e.exists) items.push({ text: T('show_script'), act: () => showFile(e) });
  if (e.kind === 'icon' && e.template_id) items.push({ text: T('show_xml'), act: () => showFile({ id: e.template_id, kind: 'template', file: e.template.split('/').pop() }) });
  if (e.kind === 'icon' && e.category === 'compose' && e.project) items.push({ text: T('show_compose'), act: () => showFile({ id: 'stack:' + e.project, kind: 'stack', folder: e.project }) });
  if (e.parts && !e.parts.every((p) => p.file) || (e.kind === 'volume' && e.path)) {
    items.push({ text: T('measure_again'), act: () => measure([e.id]), disabled: !Office.agent.running || e.measuring });
  }
  const path = e.kind === 'template' || e.kind === 'stray' ? e.path : e.kind === 'stack' ? e.dir : e.parts ? (e.parts[0] || {}).path : e.path;
  if (path) items.push({ text: Office.t('common.copy_path'), act: () => Office.copy(path) });
  return items;
}

/** What the snapshots of its datasets hold of its size (ZFS counts them in; a dataset set aside may hold all in them) */
function snapsChip(e) {
  if (!e.snaps) return null;
  const size = fmt.size(e.snaps);
  return chip(T('snaps.chip', { size }), 'quiet', T('snaps.chip_text', { size }));
}
const snapsOf = (e, key = 'snaps.of') => (e.snaps ? ', ' + T(key, { size: fmt.size(e.snaps) }) : '');

function sizeFigures(e, figures, unit) {
  if (e.bytes !== null && e.bytes !== undefined) figures.append(el('b', '', fmt.size(e.bytes)), el('span', '', unit));
  else if (e.measuring) figures.append(el('b', '', '…'), el('span', '', T('measuring')));
}

// ------------------------------------------------------------------ templates
function imageChip(local, ref) {
  if (!ref) return null;
  if (local === 'yes') return chip(T('image.yes'), '', T('image.yes_text', { image: ref }));
  if (local === 'other_tag') return chip(T('image.other_tag'), 'warn', T('image.other_tag_text', { image: ref }));
  return chip(T('image.no'), 'quiet', T('image.no_text', { image: ref }));
}

function templateMeta(t, meta, figures) {
  if (t.name && `my-${t.name}.xml` !== t.file) meta.appendChild(el('span', '', t.name));
  if (t.container) meta.appendChild(chip(`🐳 ${t.container.state}`, 'accent', T('container_text', { name: t.container.name })));
  const ic = imageChip(t.image_local, t.image);
  if (ic) meta.appendChild(ic);
  if (t.newer) meta.appendChild(chip(T('newer', { file: t.newer }), 'warn', T('newer_text')));
  meta.appendChild(el('span', '', T('changed', { when: fmt.relative(t.mtime) })));
  if (t.image_local === 'yes' && t.image_bytes) figures.append(el('b', '', fmt.size(t.image_bytes)), el('span', '', T('image_size')));
}

function templateDetail(t) {
  const box = el('div');
  const c = t.container;
  const paths = t.paths.map((p) => `${p.path}  ${p.exists === true ? '✓' : p.exists === false ? '✗ ' + T('path.missing') : '? ' + T('path.unknown')}`);
  box.appendChild(kv([
    [T('d.file'), t.path, true],
    [T('d.name'), t.name || T('d.no_name')],
    [T('d.changed'), when(t.mtime)],
    [T('d.installed'), when(t.installed)],
    [T('d.container'), c ? `${c.name} · ${c.state} · ${T('origin.' + c.origin, { project: c.project || '' })}` : T('d.no_container')],
    [T('d.image'), t.image ? `${t.image} · ${T('image.' + t.image_local)}${t.image_bytes ? ' · ' + fmt.size(t.image_bytes) : ''}` : '', true],
    [T('d.image_used_by'), t.image_used_by],
    [T('d.network'), t.network],
    [T('d.webui'), t.webui, true],
    [T('d.support'), t.support, true],
    [T('d.config'), T('d.config_counts', t.counts)],
    [T('d.paths'), paths.length ? lines(paths) : null],
    [T('d.newer'), t.newer, true],
  ]));
  return box;
}

// ------------------------------------------------------------------ stray templates
function strayMeta(t, meta) {
  meta.appendChild(el('span', 'mono', t.dir));
  if (t.name && `my-${t.name}.xml` !== t.file) meta.appendChild(el('span', '', t.name));
  if (t.container) meta.appendChild(chip(T('stray.container'), 'accent', T('stray.container_text', { name: t.name })));
  meta.appendChild(el('span', '', T('changed', { when: fmt.relative(t.mtime) })));
}

function strayDetail(t) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.file'), t.path, true],
    [T('d.name'), t.name || T('d.no_name')],
    [T('d.image'), t.image, true],
    [T('d.changed'), when(t.mtime)],
    [T('d.canonical'), t.canonical || T('d.none'), !!t.canonical],
  ]));
  box.appendChild(el('p', 'role', T('stray.' + t.loc + '_text')));
  if (['only_here', 'newer'].includes(t.loc)) {
    const b = el('button', 'btn small plain', T('install.button'));
    b.type = 'button';
    b.disabled = !Office.agent.running || state.backup_running;
    b.onclick = () => installDialog(t);
    box.appendChild(b);
  }
  return box;
}

function installDialog(t) {
  const box = el('div');
  box.appendChild(el('p', '', T('install.text', { file: t.file, dir: state.templates.dir })));
  if (t.canonical && t.loc === 'newer') box.appendChild(el('p', 'callout', T('install.replace', { path: t.canonical })));
  box.appendChild(el('p', 'role', T('install.after', { path: t.path })));
  Office.dialog({
    title: T('install.title', { file: t.file }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('install.go'), kind: '', act: async () => {
        busy = true;
        const j = await Office.api.post(`${ID}.install`, { id: t.id });
        busy = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
        selection.delete(t.id);
        setState(j.state);
        Office.toast(T(j.replaced ? 'install.done_replaced' : 'install.done', { file: t.file }));
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ CSV
function csvDate(t) {
  if (!t) return '';
  const d = new Date(t * 1000);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** What the open room shows (with the filter) as a CSV file — made in the browser */
function exportCsv(sec) {
  const rows = [[T('csv.group'), T('csv.kind'), T('csv.name'), T('csv.bytes'), T('csv.changed'), T('csv.used_by'), T('csv.notes'), T('csv.path')]];
  if (sec === 'trash') {
    for (const run of state.trash.runs) {
      for (const it of run.items.filter(matches)) {
        rows.push([T('where.' + run.where), T('item.' + it.kind), it.name, '', csvDate(run.time), '', it.zfs || '', it.from || run.path]);
      }
    }
  } else {
    for (const e of entries(sec).filter(matches)) {
      const path = e.kind === 'stack' ? e.dir : e.path || (e.parts || []).map((p) => p.path).join(' ');
      rows.push([T(`cat.${sec}.${e.category}`), e.kind === 'icon' ? T('d.container') : T('item.' + e.kind), label(e), e.bytes ?? '', csvDate(e.mtime || e.newest || e.created || e.time),
        (e.used_by || []).map((u) => u.name).concat((e.containers || []).map((c) => c.name), e.container && e.container.name ? [e.container.name] : []).join(', '),
        (e.notes || []).map(noteText).join(' '), path || '']);
    }
  }
  const text = rows.map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const a = el('a');
  a.href = URL.createObjectURL(new Blob(['\ufeff' + text], { type: 'text/csv;charset=utf-8' }));      // BOM: spreadsheets read the umlauts right
  a.download = `${Office.config.host || 'unraid'}-${sec}-${csvDate(Date.now() / 1000).slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

// ------------------------------------------------------------------ stacks
function stackMeta(s, meta, figures) {
  if (s.name !== s.folder) meta.appendChild(el('span', '', s.name));
  if (s.containers.length) {
    const run = s.containers.filter((c) => c.state === 'running').length;
    meta.appendChild(chip(`🐳 ${run}/${s.containers.length}`, 'accent', T('stack.containers_text', { names: s.containers.map((c) => `${c.name} (${c.state})`).join(', ') })));
  }
  if (s.images.length) {
    const local = s.images.filter((i) => i.local !== 'no').length;
    meta.appendChild(chip(T('stack.images', { local, n: s.images.length }), local ? '' : 'quiet', T('stack.images_text')));
  }
  if (s.volumes.length) meta.appendChild(chip(T('stack.volumes', { n: s.volumes.length }), 'warn', T('stack.volumes_text', { names: s.volumes.join(', ') })));
  if (s.indirect) meta.appendChild(chip(T('stack.indirect'), 'quiet', T('stack.indirect_text', { path: s.indirect })));
  if (!s.file && s.reachable) meta.appendChild(chip(T('stack.no_file'), 'danger', T('stack.no_file_text')));
  if (s.autostart && !s.containers.length) meta.appendChild(chip(T('stack.autostart'), 'warn', T('stack.autostart_text')));
  meta.appendChild(el('span', '', T('changed', { when: fmt.relative(s.mtime) })));
  figures.append(el('b', '', fmt.size(s.bytes)), el('span', '', T('folder')));
}

function stackDetail(s) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.stack_dir'), s.dir, true],
    [T('d.project'), s.alts.length ? T('d.project_alts', { project: s.project, alts: s.alts.join(', ') }) : s.project, true],
    [T('d.compose'), s.file ? s.file + (s.indirect ? ' · ' + T('d.indirect_note') : '') : T('stack.no_file'), true],
    [T('d.override'), s.override, true],
    [T('d.env'), s.env, true],
    [T('d.autostart'), s.autostart ? Office.t('common.yes') : Office.t('common.no')],
    [T('d.changed'), when(s.mtime)],
    [T('d.containers'), s.containers.length ? s.containers.map((c) => `${c.name} (${c.state})`) : T('d.none')],
    [T('d.images'), s.images.length ? lines(s.images.map((i) => `${i.ref}  ${T('image.' + i.local)}${i.bytes ? ' · ' + fmt.size(i.bytes) : ''}`)) : T('d.none')],
    [T('d.volumes'), s.volumes],
    [T('d.paths'), s.paths.length ? lines(s.paths) : null],
  ]));
  if (!s.resolved && s.file) box.appendChild(el('p', 'role', T('stack.raw')));
  if (s.containers.length) box.appendChild(el('p', 'role', T('stack.down_note')));
  return box;
}

// ------------------------------------------------------------------ folders (appdata, domains) and disk images
function folderMeta(f, meta, figures) {
  usedChips(f.used_by, f.used_more).forEach((c) => meta.appendChild(c));
  noteChips(f).forEach((c) => meta.appendChild(c));
  if (f.parts.length > 1) meta.appendChild(chip(f.parts.map((p) => p.root).join(' + '), 'quiet', T('parts_text')));
  if (f.parts.some((p) => p.dataset)) meta.appendChild(chip(T('zfs'), 'quiet', T('zfs_text', { name: f.parts.map((p) => p.dataset).filter(Boolean).join(', ') })));
  const sc = snapsChip(f);
  if (sc) meta.appendChild(sc);
  const bc = Office.backupChip(f.parts[0] && f.parts[0].backup);
  if (bc) meta.appendChild(bc);
  if (f.newest) meta.appendChild(el('span', '', T('changed', { when: fmt.relative(f.newest) })));
  if (f.bytes !== null) {
    figures.append(el('b', '', fmt.size(f.bytes)), el('span', '', f.kind === 'iso' ? T('on_disk') : T('files', { n: f.files })));
    if (f.partial) figures.title = T('partial');
  } else if (f.measuring) {
    figures.append(el('b', '', '…'), el('span', '', T('measuring')));
  }
}

function folderDetail(f) {
  const box = el('div');
  const by = f.used_by.map((u) => `${KIND_ICONS[u.kind] || '•'} ${T('kind.' + u.kind)}: ${u.name}${u.weak ? ' (' + T('weak') + ')' : ''}`);
  if (f.used_more) by.push(T('more_names', { n: f.used_more }));
  const file = f.kind === 'iso';
  box.appendChild(kv([
    [T('d.where'), lines(f.parts.map((p) => p.path + (p.dataset ? `  (${T('d.dataset', { name: p.dataset })})` : '')))],
    [T('d.size'), f.bytes !== null ? `${fmt.size(f.bytes)}${snapsOf(f)}${file ? '' : ' · ' + T('files', { n: f.files })}${f.partial ? ' · ' + T('partial') : ''}` : (f.measuring ? T('measuring') : T('d.not_measured'))],
    [T(file ? 'd.changed' : 'd.newest'), when(f.newest)],
    [T('d.measured'), !file && f.measured_at ? fmt.relative(f.measured_at) : ''],
    [T('d.newest_files'), f.top.length ? lines(f.top.map(([t, p]) => `${fmt.date(t)}  ${p}`)) : null],
    [T('d.used_by'), by.length ? lines(by) : T(f.kind === 'appdata' ? 'd.used_by_none' : 'd.used_by_none_vm')],
  ]));
  (f.notes || []).forEach((n) => box.appendChild(el('p', 'role', noteText(n))));
  if (f.parts.some((p) => p.dataset)) box.appendChild(el('p', 'role', T('zfs_note')));
  return box;
}

// ------------------------------------------------------------------ User Scripts
function freqText(e) {
  if (e.frequency === 'custom') return e.cron ? fmt.cron(e.cron) : T('us.freq.custom');
  if (e.frequency === 'disabled' || !e.frequency) return T('us.off') + (e.cron ? ` (${e.cron})` : '');
  return Office.has(`${ID}.us.freq.${e.frequency}`) ? T('us.freq.' + e.frequency) : e.frequency;
}

function scriptMeta(e, meta) {
  if (e.name !== e.folder) meta.appendChild(el('span', 'mono', e.folder));
  meta.appendChild(chip(freqText(e), e.frequency === 'disabled' ? 'quiet' : 'accent', T('us.schedule_text')));
  if (e.running) meta.appendChild(chip('▶ ' + T('us.running'), 'accent', T('us.running_text')));
  if (e.dead) meta.appendChild(chip(T('us.dead', { n: e.dead }), 'warn', T('us.dead_text')));
  if (!e.exists) meta.appendChild(chip(T('us.no_file'), 'danger', T('us.no_file_text')));
  meta.appendChild(el('span', '', e.last_run ? T('us.last_run', { when: fmt.relative(e.last_run) }) : T('us.not_since_boot')));
  meta.appendChild(el('span', '', T('changed', { when: fmt.relative(e.mtime) })));
}

function scriptDetail(e) {
  const box = el('div');
  const paths = e.paths.map((p) => `${p.exists === true ? '✓' : p.exists === false ? '✗' : '?'} ${p.path}`);
  box.appendChild(kv([
    [T('d.file'), e.path, true],
    [T('d.description'), e.description],
    [T('d.schedule'), freqText(e)],
    [T('d.last_run'), e.last_run ? when(e.last_run) : T('us.not_since_boot')],
    [T('d.changed'), when(e.mtime)],
    [T('d.script_paths'), paths.length ? lines(paths) : null],
  ]));
  if (e.why === 'scheduled') box.appendChild(el('p', 'role', T('why.scheduled_text')));
  return box;
}

// ------------------------------------------------------------------ VMs whose disks are gone
function vmdefMeta(v, meta) {
  meta.appendChild(chip(T('vmdef.missing', { missing: v.missing, n: v.total }), 'danger', T('vmdef.missing_text')));
  if (v.state) meta.appendChild(el('span', '', v.state));
}

function vmdefDetail(v) {
  const box = el('div');
  const mark = (d) => `${d.path}  ${d.exists === true ? '✓' : d.exists === false ? '✗ ' + T('path.missing') : '? ' + T('path.unknown')}`;
  const disks = v.disks.filter((d) => d.device === 'disk').map(mark);
  const cds = v.disks.filter((d) => d.device === 'cdrom').map(mark);
  box.appendChild(kv([
    [T('d.disks'), disks.length ? lines(disks) : null],
    [T('d.cdroms'), cds.length ? lines(cds) : null],
    [T('d.uuid'), v.uuid, true],
  ]));
  box.appendChild(el('p', 'role', T('vmdef.text')));
  const a = el('a', 'btn small plain', T('vmdef.open'));
  a.href = '/VMs';
  box.appendChild(a);
  return box;
}

// ------------------------------------------------------------------ libvirt leftovers
function libvirtMeta(o, meta, figures) {
  meta.appendChild(chip(T('lv.' + o.kind), '', T('lv.' + o.kind + '_text')));
  if (o.snapshot) meta.appendChild(chip(T('lv.snapshot'), 'quiet', T('lv.snapshot_text')));
  if (o.mtime) meta.appendChild(el('span', '', T('changed', { when: fmt.relative(o.mtime) })));
  figures.append(el('b', '', fmt.size(o.bytes)), el('span', '', ''));
}

function libvirtDetail(o) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.where'), o.path, true],
    [T('d.uuid'), o.uuid, true],
    [T('d.changed'), when(o.mtime)],
    [T('d.size'), fmt.size(o.bytes)],
  ]));
  box.appendChild(el('p', 'role', T('lv.' + o.kind + '_text') + ' ' + T('lv.orphan_text')));
  return box;
}

// ------------------------------------------------------------------ Docker's leftovers
function imageMeta(i, meta, figures) {
  if (!i.refs.length) meta.appendChild(chip(T('img.dangling'), 'warn', T('img.dangling_text')));
  if (i.refs.length > 1) meta.appendChild(chip(T('img.tags', { n: i.refs.length }), 'quiet', i.refs.join('\n')));
  usedChips(i.used_by).forEach((c) => meta.appendChild(c));
  noteChips(i).forEach((c) => meta.appendChild(c));
  if (i.created) meta.appendChild(el('span', '', T('img.created', { when: fmt.relative(i.created) })));
  sizeFigures(i, figures, '');
}

function imageDetail(i) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.id'), i.image_id, true],
    [T('d.tags'), i.refs.length ? lines(i.refs) : T('img.dangling')],
    [T('d.created'), when(i.created)],
    [T('d.size'), i.bytes !== null ? fmt.size(i.bytes) : ''],
    [T('d.used_by'), i.used_by.length ? i.used_by.map((u) => u.name) : T('d.none')],
  ]));
  (i.notes || []).forEach((n) => box.appendChild(el('p', 'role', noteText(n))));
  if (i.category !== 'used') box.appendChild(el('p', 'role', T(i.refs.length ? 'img.pull_again' : 'img.dangling_text')));
  return box;
}

function volumeMeta(v, meta, figures) {
  if (v.anonymous) meta.appendChild(chip(T('vol.anonymous'), 'quiet', T('vol.anonymous_text')));
  usedChips(v.used_by).forEach((c) => meta.appendChild(c));
  noteChips(v).forEach((c) => meta.appendChild(c));
  if (v.created) meta.appendChild(el('span', '', T('img.created', { when: fmt.relative(v.created) })));
  sizeFigures(v, figures, v.files !== null && v.files !== undefined ? T('files', { n: v.files }) : '');
}

function volumeDetail(v) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.name'), v.name, true],
    [T('d.mountpoint'), v.path, true],
    [T('d.driver'), v.driver],
    [T('d.created'), when(v.created)],
    [T('d.size'), v.bytes !== null ? `${fmt.size(v.bytes)} · ${T('files', { n: v.files })}` : (v.measuring ? T('measuring') : T('d.not_measured'))],
    [T('d.newest'), when(v.newest)],
    [T('d.used_by'), v.used_by.length ? v.used_by.map((u) => u.name) : T('d.none')],
  ]));
  (v.notes || []).forEach((n) => box.appendChild(el('p', 'role', noteText(n))));
  if (v.category !== 'used') box.appendChild(el('p', 'role', T('vol.data_text')));
  return box;
}

function cacheMeta(c, meta, figures) {
  meta.appendChild(el('span', '', T('cache.meta', { size: fmt.size(c.bytes), total: fmt.size(c.total) })));
  figures.append(el('b', '', fmt.size(c.bytes)), el('span', '', ''));
}

function cacheDetail() {
  return el('p', 'role', T('cache.text'));
}

// ------------------------------------------------------------------ missing pictures
/** A picture as the browser shows it — a file on the server through the preview she sent along (else a frame with a hint) */
function picture(url) {
  const box = el('span', 'cl-pic');
  const preview = url && url.startsWith('file://') ? ((state && state.icons && state.icons.previews) || {})[url] : null;
  if (!url || (url.startsWith('file://') && !preview)) {
    box.classList.add('cl-pic-none');
    if (url) { box.textContent = '📁'; box.title = T('pic.local_preview'); }
    return box;
  }
  const img = el('img');
  img.alt = '';
  img.loading = 'lazy';
  img.decoding = 'async';
  img.referrerPolicy = 'no-referrer';
  img.onerror = () => { img.remove(); box.classList.add('cl-pic-none'); };
  img.src = preview || url;
  box.appendChild(img);
  return box;
}

/** The picture Unraid shows now: its cached copy (Unraid serves it), else the address it came from */
const shownIcon = (e) => (e.shown ? e.shown : /^https?:\/\//i.test(e.value) ? e.value : null);

function iconMeta(e, meta) {
  meta.appendChild(el('span', 'mono', e.image.replace(/@sha256:[0-9a-f]+$/i, '')));       // the digest in the details
  if (e.category === 'ok') return;
  meta.appendChild(e.status === 'missing'
    ? chip(T('pic.missing'), 'warn', T('pic.missing_text'))
    : chip(T('pic.broken'), 'warn', T(e.value.startsWith('/') ? 'pic.broken_local_text' : 'pic.broken_text', { value: e.value })));
  if (e.category === 'template') meta.appendChild(chip('📄 ' + e.template.split('/').pop(), 'accent', T('pic.via_template_text', { path: e.template })));
  if (e.category === 'compose') meta.appendChild(chip(`🧩 ${e.project} · ${e.service || '?'}`, 'accent', e.override ? T('pic.via_compose_text', { path: e.override }) : T('cat.icons.compose_text')));
  if (e.category === 'none' || e.why) return;
  const url = iconChoice(e);
  const c = url ? e.candidates.find((x) => x.url === url) : null;
  const own = url && url.startsWith('data:') ? 'src.upload' : 'src.own';
  if (url) meta.appendChild(chip(T(c ? 'src.' + c.source : own), c && c.source === 'guess' ? 'warn' : '', T(c ? `src.${c.source}_text` : own + '_text', { detail: c ? c.detail : '' })));
  else if (e.checking) meta.appendChild(chip('⏳ ' + T('pic.checking'), 'quiet', T('pic.checking_text')));
  else meta.appendChild(chip(T('pic.none_found'), 'quiet', T('pic.none_found_text')));
}

function iconDetail(e) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.container'), `${e.name} · ${e.state}`],
    [T('d.image'), e.image, true],
    [T('pic.d.now'), e.value ? `${e.value}${e.value_from ? ` (${T('pic.from.' + e.value_from)})` : ''}` : T('d.none'), !!e.value],
    [T('pic.d.where'), e.category === 'ok' ? '' : e.path, true],
  ]));
  if (e.category === 'ok') { box.appendChild(el('p', 'role', T('pic.ok_text'))); return box; }
  box.appendChild(el('p', 'role', T('pic.how_' + e.category)));
  if (e.why) box.appendChild(el('p', 'role', T(`why.${e.why}_text`)));
  if (e.category === 'none' || e.why) return box;

  // which picture: the ones found (those still being checked can't be chosen yet), or an address of her own
  const list = el('div', 'cl-pic-list');
  list.setAttribute('role', 'radiogroup');
  list.setAttribute('aria-label', T('pic.choose'));
  const current = iconChoice(e);
  e.candidates.forEach((c) => {
    const l = el('label', 'check cl-pic-opt');
    const r = el('input');
    r.type = 'radio';
    r.name = 'cl-pic-' + e.id;
    r.checked = c.url === current;
    r.disabled = c.status !== 'ok' || !Office.agent.running;
    r.onchange = () => choose(e, c.url);
    const text = el('span', '', T('src.' + c.source) + (c.status === 'pending' ? ' · ' + T('pic.checking') : ''));
    text.title = T(`src.${c.source}_text`, { detail: c.detail });
    text.appendChild(el('small', 'mono', c.url));
    l.append(r, picture(c.url), text);
    list.appendChild(l);
  });
  const own = el('div', 'cl-pic-own');
  const input = el('input', 'input');
  input.type = 'url';
  input.placeholder = T('pic.own_placeholder');
  input.setAttribute('aria-label', T('src.own'));
  input.spellcheck = false;
  const chosen = picks.get(e.id);
  if (chosen && !chosen.startsWith('data:') && !e.candidates.some((c) => c.url === chosen)) input.value = chosen;
  const use = el('button', 'btn small plain', T('pic.own_use'));
  use.type = 'button';
  use.disabled = !Office.agent.running;
  const take = () => {
    const v = input.value.trim();
    if (!/^https?:\/\/[^\s"'<>\\`]+$/i.test(v)) { Office.toast(T('pic.own_bad'), true); return; }
    choose(e, v);
  };
  use.onclick = take;
  input.onkeydown = (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); take(); } };
  own.append(input, use);

  // or a picture from this computer: the browser makes a small square PNG of it, only that goes to the server
  const upload = el('div', 'cl-pic-own');
  const file = el('input');
  file.type = 'file';
  file.accept = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml';
  file.hidden = true;
  const up = el('button', 'btn small plain', T('pic.upload'));
  up.type = 'button';
  up.disabled = !Office.agent.running;
  up.onclick = () => file.click();
  file.onchange = async () => {
    const f = file.files && file.files[0];
    file.value = '';
    if (!f) return;
    try { choose(e, await squarePng(f)); } catch { Office.toast(T('pic.upload_bad'), true); }
  };
  upload.append(file, up);
  if (chosen && chosen.startsWith('data:')) upload.append(picture(chosen), el('span', 'role', T('src.upload')));
  box.append(list, own, el('p', 'role', T('pic.own_hint')), upload, el('p', 'role', T('pic.upload_hint', { dir: (state.icons || {}).upload_dir || '' })));
  return box;
}

const UPLOAD_SIDE = 256;
const UPLOAD_MAX = 512 * 1024;         // the agent takes a PNG of at most this
/** A picture from this computer as a square PNG of at most 256 × 256 (aspect kept, transparent around it), as a data: address */
async function squarePng(f) {
  if (f.size > 20 * 1024 * 1024) throw new Error('too big');
  const src = URL.createObjectURL(f);
  try {
    const img = new Image();
    await new Promise((ok, bad) => { img.onload = ok; img.onerror = bad; img.src = src; });
    const vector = f.type === 'image/svg+xml' || /\.svg$/i.test(f.name);
    const w = img.naturalWidth || UPLOAD_SIDE, h = img.naturalHeight || UPLOAD_SIDE;
    const side = vector ? UPLOAD_SIDE : Math.min(UPLOAD_SIDE, Math.max(w, h));   // a small picture isn't blown up
    const scale = side / Math.max(w, h);
    const dw = Math.max(1, Math.round(w * scale)), dh = Math.max(1, Math.round(h * scale));
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = side;
    canvas.getContext('2d').drawImage(img, Math.floor((side - dw) / 2), Math.floor((side - dh) / 2), dw, dh);
    const png = canvas.toDataURL('image/png');
    if (!png.startsWith('data:image/png;base64,') || (png.length - 22) * 0.75 > UPLOAD_MAX) throw new Error('not a picture');
    return png;
  } finally {
    URL.revokeObjectURL(src);
  }
}

/** A picture chosen for a container: it is ticked, the row shows it */
function choose(e, url) {
  picks.set(e.id, url);
  selection.add(e.id);
  Office.keepInPlace(view.tiles, () => renderSection());
  updateSelbar();
}

function iconsInfo() {
  const s = state.icons || {};
  const loop = s.loop || {};
  const box = el('div', 'cl-info');
  if (loop.risk) {
    const p = el('p', 'callout warn');
    p.append(T('loop.text', { version: loop.version, n: loop.containers }), ' ');
    if (loop.standin) {
      const b = el('button', 'btn small plain', T('loop.button'));
      b.type = 'button';
      b.disabled = !Office.agent.running || busy;
      b.onclick = () => fallback(b);
      p.appendChild(b);
    } else {
      p.append(T('loop.no_standin'));
    }
    box.appendChild(p);
  } else if (loop.affected && !loop.fallback_missing) {
    box.appendChild(el('p', 'role', T('loop.in_place', { version: loop.version })));
  }
  box.appendChild(el('p', 'role', T('pic.where')));
  if (s.checking) box.appendChild(el('p', 'role', '⏳ ' + T('pic.checking_all')));
  box.appendChild(el('p', 'role', s.ca_at ? T('pic.ca_at', { when: fmt.relative(s.ca_at) }) : T('pic.no_ca')));
  return box;
}

async function fallback(button) {
  if (busy) return;
  busy = true;
  button.disabled = true;
  const j = await Office.api.post(`${ID}.icon_fallback`, {});
  busy = false;
  if (!j.ok) { button.disabled = false; Office.toast(Office.errorText(j.error, ID), true); return; }
  setState(j.state);
  Office.toast(T('loop.done'));
}

function iconsDialog() {
  const list = entries('icons').filter((e) => selection.has(e.id) && selectable(e));
  if (!list.length) return;
  const box = el('div');
  box.appendChild(el('p', '', T('icons.text', { n: list.length })));
  const ul = el('ul', 'cl-pic-short');
  list.forEach((e) => {
    const li = el('li');
    const name = el('span', 'cl-pic-name');
    name.append(picture(iconChoice(e)), el('span', '', e.name));
    li.append(name, el('span', 'cl-pic-when', T(e.category === 'template' ? 'icons.at_once' : 'icons.after_up')));
    ul.appendChild(li);
  });
  box.appendChild(ul);
  if (list.some((e) => e.category === 'template')) box.appendChild(el('p', 'role', T('icons.template_note')));
  if (list.some((e) => e.category === 'compose')) box.appendChild(el('p', 'callout', T('icons.compose_note')));
  box.appendChild(el('p', 'role', T('icons.undo_note')));
  Office.dialog({
    title: T('icons.title', { n: list.length }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('icons.go'), kind: '', act: async () => {
        // an uploaded picture goes as its PNG (base64), everything else as its address
        const items = list.map((e) => { const u = iconChoice(e); return u.startsWith('data:') ? { id: e.id, upload: u.slice(u.indexOf(',') + 1) } : { id: e.id, url: u }; });
        if (JSON.stringify(items).length > 900 * 1024) { Office.toast(T('icons.too_big'), true); return false; }
        busy = true;
        const j = await Office.api.post(`${ID}.icons`, { items });
        busy = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
        selection.clear();
        j.results.filter((r) => r.ok).forEach((r) => picks.delete(r.id));
        setState(j.state);
        report(j.results, 'icons');
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ Mr. Restori's leftovers
function leftoversInfo() {
  const l = state.leftovers;
  const box = el('div', 'cl-info');
  const p = el('p', 'role', T('lo.where', { n: l.restores }) + ' ');
  const a = el('a', '', T('lo.journal'));
  a.href = '#/restore';
  p.appendChild(a);
  box.appendChild(p);
  if (l.asleep.length) box.appendChild(asleepCallout(l.asleep));
  return box;
}

/** A restore as Mr. Restori names it in his journal */
const restoreName = (r) => (Office.has(`${ID}.lo.kind.${r.kind}`) ? T('lo.kind.' + r.kind, { what: r.what }) : `${r.kind}: ${r.what}`);

/** Per restore, newest first: what it left, with a checkbox for all of it that can go */
function renderLeftovers(body) {
  const all = entries('leftovers');
  if (!all.length) { body.appendChild(emptyNote(T('empty.leftovers'))); return; }
  const list = all.filter(matches);
  if (!list.length) { body.appendChild(emptyNote(T('no_match', { query: query.trim() }))); return; }
  const by = new Map();
  list.forEach((e) => { if (!by.has(e.restore.id)) by.set(e.restore.id, []); by.get(e.restore.id).push(e); });
  const box = el('div', 'box');
  by.forEach((items) => box.appendChild(restoreGroup(items)));
  body.appendChild(box);
}

function restoreGroup(items) {
  const r = items[0].restore;
  const key = 'leftovers.' + r.id;
  const closed = !words().length && (folded[key] ?? false);
  const box = el('div', 'group' + (closed ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');
  // the whole restore at once — only what isn't still his way back (that one asks on its own)
  const can = items.filter((e) => selectable(e) && !e.force);
  const cb = el('input');
  cb.type = 'checkbox';
  cb.disabled = !can.length;
  cb.title = T('select_group');
  const sync = () => {
    const n = can.filter((e) => selection.has(e.id)).length;
    cb.checked = n > 0 && n === can.length;
    cb.indeterminate = n > 0 && n < can.length;
  };
  sync();
  cb.onclick = (ev) => {
    ev.stopPropagation();
    const every = can.every((x) => selection.has(x.id));
    can.forEach((x) => (every ? selection.delete(x.id) : selection.add(x.id)));
    renderSection();
    updateSelbar();
  };
  const mid = el('div', 'group-mid');
  const title = el('div', 'group-title');
  title.append(el('span', '', restoreName(r)), chip(fmt.number(items.length), 'quiet'));
  if (r.undone) title.appendChild(chip(T('lo.undone'), 'quiet', T('lo.undone_text')));
  const meta = [r.time ? `${fmt.date(r.time)} · ${fmt.relative(r.time)}` : r.id];
  if (items.every((e) => e.bytes !== null && e.bytes !== undefined)) meta.push(fmt.size(sum(items)));
  mid.append(title, el('div', 'group-meta', meta.join(' · ')));
  head.append(cb, el('span', 'group-arrow', '▼'), mid);
  const rows = el('div', 'group-rows');
  items.forEach((e) => rows.appendChild(row(e, sync)));
  const toggle = () => {
    const now = !box.classList.contains('closed');
    box.classList.toggle('closed', now);
    folded[key] = now;
    Office.storeJson('cleanup.folded', folded);
  };
  head.onclick = (e) => { if (e.target.closest('button, input, a')) return; Office.keepInPlace(head, toggle); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); Office.keepInPlace(head, toggle); } };
  box.append(head, rows);
  return box;
}

/** What he made next to the live one (a restored copy, safety dumps) — never put aside */
const made = (e) => ['restored', 'safety'].includes(e.what);

function leftoverMeta(e, meta, figures) {
  meta.appendChild(chip(T('lo.what.' + e.what), '', T('lo.what.' + e.what + '_text')));
  if (e.category === 'way_back') meta.appendChild(chip(T('lo.way_back'), 'warn', T('lo.way_back_text')));
  if (e.category === 'unknown') meta.appendChild(chip(T('lo.unknown'), 'quiet', T('lo.unknown_text')));
  if (e.parts.length > 1) meta.appendChild(chip(e.parts.map((p) => p.root).join(' + '), 'quiet', T('parts_text')));
  if (e.parts.some((p) => p.dataset)) meta.appendChild(chip(T('zfs'), 'quiet', T('zfs_text', { name: e.parts.map((p) => p.dataset).filter(Boolean).join(', ') })));
  const sc = snapsChip(e);
  if (sc) meta.appendChild(sc);
  meta.appendChild(el('span', '', T(made(e) ? 'lo.since_made' : 'lo.since', { when: fmt.relative(e.time) })));
  sizeFigures(e, figures, '');
}

function leftoverDetail(e) {
  const box = el('div');
  const r = e.restore;
  box.appendChild(kv([
    [T('d.where'), e.parts.length ? lines(e.parts.map((p) => p.path + (p.dataset ? `  (${T('d.dataset', { name: p.dataset })})` : ''))) : e.path, true],
    [T('lo.d.restore'), `${restoreName(r)} · ${r.id}`],
    [T(made(e) ? 'lo.d.made' : 'lo.d.aside'), when(e.time)],
    [T('d.size'), e.bytes !== null && e.bytes !== undefined ? fmt.size(e.bytes) + snapsOf(e) : (e.measuring ? T('measuring') : T('d.not_measured'))],
  ]));
  box.appendChild(el('p', 'role', T('lo.what.' + e.what + '_text')));
  if (e.category === 'way_back') box.appendChild(el('p', 'role', T('lo.way_back_text')));
  if (e.asleep.length) box.appendChild(el('p', 'role', T('asleep', { disks: e.asleep.join(', ') })));
  return box;
}

// ------------------------------------------------------------------ selection
function updateSelbar() {
  if (!view || !section || section === 'trash' || !selection.size) { Office.selbar(null); return; }
  const list = entries(section).filter((e) => selection.has(e.id));
  if (!list.length) { Office.selbar(null); return; }
  const bytes = sum(list);
  const forced = list.filter((e) => e.force).length;
  const sub = [section !== 'templates' && bytes ? fmt.size(bytes) : '', forced ? T('selected_in_use', { n: forced }) : ''].filter(Boolean).join(' · ');
  const go = section === 'docker'
    ? { text: T('remove.button'), kind: 'danger', act: removeDialog, disabled: !Office.agent.running || busy }
    : section === 'icons'
      ? { text: T('icons.button'), kind: '', act: iconsDialog, disabled: !Office.agent.running || busy || !state || state.backup_running }
      : { text: T('park.button'), kind: '', act: parkDialog, disabled: !Office.agent.running || busy || !state || state.backup_running };
  Office.selbar({
    title: T('selected', { n: list.length }),
    sub,
    buttons: [{ text: T('clear'), kind: 'plain', act: () => { selection.clear(); renderSection(); updateSelbar(); } }, go],
  });
}

// ------------------------------------------------------------------ actions
async function scan(wake) {
  if (busy) return;
  busy = true;
  if (view) { view.scanBtn.classList.add('running'); view.scanBtn.disabled = true; }
  const j = await Office.api.post(`${ID}.scan`, wake ? { wake: true } : {});
  busy = false;
  if (view) view.scanBtn.classList.remove('running');
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); if (view) render(); return; }
  setState(j.state);
  Office.toast(T('scan_done', { ms: j.state.duration_ms }));
}

async function measure(ids) {
  const j = await Office.api.post(`${ID}.measure`, { ids });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  setState(j.state);
}

async function showFile(e) {
  const j = await Office.api.post(`${ID}.detail`, { id: e.id });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  const box = el('div');
  j.files.forEach((f) => {
    box.appendChild(el('p', 'role mono', f.path + (f.cut ? ' · ' + T('cut') : '')));
    box.appendChild(el('pre', 'code', f.text));
  });
  if (!j.files.length) box.appendChild(el('p', 'role', T('stack.no_file')));
  if (j.env) box.appendChild(el('p', 'role', T('env_hidden', { path: j.env })));
  Office.dialog({ title: label(e), body: box, wide: true });
}

function shortlist(list) {
  const ul = el('ul', 'shortlist');
  list.forEach((e) => {
    const li = el('li', '', label(e));
    li.appendChild(el('span', '', e.bytes !== null && e.bytes !== undefined && e.kind !== 'template' ? fmt.size(e.bytes) : ''));
    ul.appendChild(li);
  });
  return ul;
}

function parkDialog() {
  const sec = section;
  const list = entries(sec).filter((e) => selection.has(e.id));
  if (!list.length) return;
  const forced = list.filter((e) => e.force);
  const box = el('div');
  box.appendChild(el('p', '', T('park.text_' + sec, { n: list.length })));
  box.appendChild(shortlist(list));
  const running = sec === 'stacks' ? list.filter((s) => s.containers.length) : [];
  if (running.length) box.appendChild(el('p', 'callout', T('park.down', { names: running.map((s) => s.folder).join(', ') })));
  if (forced.length) box.appendChild(el('p', 'callout warn', T('park.in_use_' + sec, { names: forced.map(label).join(', ') })));
  if (list.some((e) => e.parts && e.parts.some((p) => p.dataset))) box.appendChild(el('p', 'role', T('park.zfs')));
  box.appendChild(el('p', 'role', T('park.where_' + sec)));
  Office.dialog({
    title: T('park.title', { n: list.length }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: forced.length ? T('park.go_anyway') : T('park.go'), kind: forced.length ? 'danger' : '', act: async () => {
        busy = true;
        const j = await Office.api.post(`${ID}.park`, { ids: list.map((e) => e.id), force: forced.length > 0 });
        busy = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
        selection.clear();
        setState(j.state);
        report(j.results, 'park');
        return true;
      } },
    ],
  });
}

/** Docker's leftovers: no storeroom, removed for good */
function removeDialog() {
  const list = entries('docker').filter((e) => selection.has(e.id));
  if (!list.length) return;
  const box = el('div');
  box.appendChild(el('p', '', T('remove.text', { n: list.length, size: fmt.size(sum(list)) })));
  box.appendChild(shortlist(list));
  const volumes = list.filter((e) => e.kind === 'volume');
  if (volumes.length) box.appendChild(el('p', 'callout warn', T('remove.volumes', { n: volumes.length })));
  if (list.some((e) => e.kind === 'image')) box.appendChild(el('p', 'role', T('remove.images')));
  box.appendChild(el('p', 'callout warn', T('purge.final')));
  Office.dialog({
    title: T('remove.title', { n: list.length }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('remove.go'), kind: 'danger', act: async () => {
        busy = true;
        const j = await Office.api.post(`${ID}.remove`, { ids: list.map((e) => e.id) });
        busy = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
        selection.clear();
        setState(j.state);
        report(j.results, 'remove');
        return true;
      } },
    ],
  });
}

/** One toast when all went well, the list of what failed otherwise */
function report(results, what) {
  const failed = results.filter((r) => !r.ok);
  const ok = results.length - failed.length;
  if (!failed.length) { Office.toast(T(what + '.done', { n: ok })); return; }
  Office.showErrors(T(what + '.some_failed', { n: failed.length }), failed.map((r) => r.error), ID);
}

// ------------------------------------------------------------------ trash
function renderTrash(body) {
  const runs = state.trash.runs;
  if (!runs.length) { body.appendChild(emptyNote(T('empty.trash'))); return; }
  const shownRuns = runs.filter((r) => !words().length || r.items.some(matches));
  if (!shownRuns.length) { body.appendChild(emptyNote(T('no_match', { query: query.trim() }))); return; }
  const box = el('div', 'box');
  shownRuns.forEach((run) => box.appendChild(trashGroup(run)));
  body.appendChild(box);
}

/** What a run is: when, where, how big — for a group's title bar or a single row */
function runMeta(run) {
  const meta = [T('where.' + run.where)];
  if (run.items.length !== 1) meta.push(T('items', { n: run.items.length }));
  if (run.bytes !== null) meta.push(fmt.size(run.bytes) + snapsOf(run, 'snaps.of_run'));
  else if (run.measuring) meta.push(T('measuring'));
  return meta.join(' · ');
}

function purgeButton(run) {
  const b = el('button', 'btn small danger plain', T('purge.button'));
  b.type = 'button';
  b.disabled = !Office.agent.running || state.backup_running;
  b.onclick = (e) => { e.stopPropagation(); purgeDialog([run]); };
  return b;
}

/** A run with one thing in it is one row: nothing to fold, both buttons side by side */
function trashSingle(run) {
  const it = run.items[0];
  const r = trashRow(run, it);
  // a second line: where it lies now, and when, where and how big
  const meta = el('div', 'row-meta');
  const now = it.zfs ? (it.zfs_path || it.zfs) : `${run.path}/${it.as}`;
  meta.append(el('span', 'mono', T('item.now_in', { path: now })),
    el('span', '', `${T('trash.parked_at', { when: fmt.date(run.time), ago: fmt.relative(run.time) })} · ${runMeta(run)}`));
  r.querySelector('.row-main').appendChild(meta);
  if (run.legacy) meta.appendChild(chip(T('trash.legacy'), 'quiet', T('trash.legacy_text')));
  if (run.purging) meta.appendChild(chip('⏳ ' + T('trash.purging'), 'warn', T('trash.purging_text')));
  else r.querySelector('.cl-acts').appendChild(purgeButton(run));
  r.classList.add('cl-single');
  return r;
}

function trashGroup(run) {
  if (run.items.length === 1) return trashSingle(run);
  const key = 'trash.' + run.id;
  const closed = folded[key] ?? false;
  const box = el('div', 'group' + (closed ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');
  const mid = el('div', 'group-mid');
  const title = el('div', 'group-title');
  title.append(el('span', '', T('trash.parked_at', { when: fmt.date(run.time), ago: fmt.relative(run.time) })));
  if (run.legacy) title.appendChild(chip(T('trash.legacy'), 'quiet', T('trash.legacy_text')));
  if (run.purging) title.appendChild(chip('⏳ ' + T('trash.purging'), 'warn', T('trash.purging_text')));
  mid.append(title, el('div', 'group-meta', runMeta(run)), el('div', 'group-meta mono', run.path));
  head.append(el('span', 'group-arrow', '▼'), mid);
  if (!run.purging) head.appendChild(purgeButton(run));
  const rows = el('div', 'group-rows');
  if (!run.items.length) rows.appendChild(el('div', 'row nocheck', T('trash.no_items')));
  run.items.filter(matches).forEach((it) => rows.appendChild(trashRow(run, it)));
  const toggle = () => {
    const now = !box.classList.contains('closed');
    box.classList.toggle('closed', now);
    folded[key] = now;
    Office.storeJson('cleanup.folded', folded);
  };
  head.onclick = (e) => { if (e.target.closest('button')) return; Office.keepInPlace(head, toggle); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); Office.keepInPlace(head, toggle); } };
  box.append(head, rows);
  return box;
}

function trashRow(run, it) {
  const r = el('div', 'row nocheck cl-trash-row');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name', it.name));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(`${ITEM_ICONS[it.kind] || '•'} ${T('item.' + it.kind)}`, 'quiet'));
  if (it.label && it.label !== it.name && ['template', 'stack', 'icon'].includes(it.kind)) meta.appendChild(el('span', '', it.label));
  if (it.from) meta.appendChild(el('span', 'mono', T('item.from', { path: it.from })));
  else if (!run.legacy) meta.appendChild(el('span', '', T('item.no_manifest')));
  if (it.zfs) meta.appendChild(chip(T('zfs'), 'quiet', T('item.zfs', { name: it.zfs })));
  if (!it.present) meta.appendChild(chip(T('item.gone'), 'warn', T('item.gone_text')));
  if (it.volumes.length) meta.appendChild(chip(T('stack.volumes', { n: it.volumes.length }), 'quiet', T('item.volumes_text', { names: it.volumes.join(', ') })));
  main.appendChild(meta);
  const act = el('div', 'cl-acts');
  if (!run.legacy && !run.purging && it.from && it.present) {
    const b = el('button', 'btn small plain', T('restore.button'));
    b.type = 'button';
    b.title = T('restore.title', { path: it.from });
    b.disabled = !Office.agent.running || state.backup_running;
    b.onclick = () => restore(it, b);
    act.appendChild(b);
  }
  r.append(main, act, el('div'));
  return r;
}

async function restore(it, button) {
  if (busy) return;
  busy = true;
  button.disabled = true;
  const j = await Office.api.post(`${ID}.restore`, { ids: [it.id] });
  busy = false;
  if (!j.ok) { button.disabled = false; Office.toast(Office.errorText(j.error, ID), true); return; }
  const r = j.results[0];
  if (r && !r.ok) Office.toast(Office.errorText(r.error, ID), true);
  else Office.toast(T(it.kind === 'stack' ? 'restore.done_stack' : it.kind === 'icon' ? 'restore.done_icon' : 'restore.done', { name: it.kind === 'icon' ? it.label || it.name : it.name }));
  setState(j.state);
}

function purgeDialog(runs) {
  const items = runs.flatMap((r) => r.items);
  const bytes = runs.reduce((a, r) => a + (r.bytes || 0), 0);
  const known = runs.every((r) => r.bytes !== null);
  const box = el('div');
  box.appendChild(el('p', '', T('purge.text', { n: items.length, size: known ? fmt.size(bytes) : '?' })));
  const ul = el('ul', 'shortlist');
  items.slice(0, 200).forEach((it) => {
    const li = el('li', '', it.name);
    li.appendChild(el('span', '', T('item.' + it.kind)));
    ul.appendChild(li);
  });
  box.appendChild(ul);
  const stacks = items.filter((it) => it.kind === 'stack');
  const vols = stacks.flatMap((s) => s.volumes);
  const imgs = stacks.flatMap((s) => s.images);
  const option = (text, hint) => {
    const l = el('label', 'check');
    const c = el('input');
    c.type = 'checkbox';
    const span = el('span', '', text);
    span.appendChild(el('small', '', hint));
    l.append(c, span);
    box.appendChild(l);
    return c;
  };
  const volBox = vols.length ? option(T('purge.volumes', { n: vols.length }), T('purge.volumes_hint', { names: vols.join(', ') })) : null;
  const imgBox = imgs.length ? option(T('purge.images', { n: imgs.length }), T('purge.images_hint', { names: imgs.join(', ') })) : null;
  if (items.some((it) => it.zfs)) box.appendChild(el('p', 'role', T('purge.zfs')));
  if (runs.some((r) => ['appdata', 'domains', 'isos'].includes(r.where))) box.appendChild(el('p', 'role', T('purge.snapshots')));
  box.appendChild(el('p', 'callout warn', T('purge.final')));
  Office.dialog({
    title: T('purge.title', { n: runs.length }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('purge.go'), kind: 'danger', act: async () => {
        busy = true;
        const j = await Office.api.post(`${ID}.purge`, { ids: runs.map((r) => r.id), volumes: !!(volBox && volBox.checked), images: !!(imgBox && imgBox.checked) });
        busy = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
        setState(j.state);
        const failed = j.results.filter((r) => !r.ok);
        const left = j.results.flatMap((r) => [...(r.volumes || []), ...(r.images || [])]).filter((x) => !x.ok).map((x) => x.name);
        if (failed.length) Office.showErrors(T('purge.some_failed', { n: failed.length }), failed.map((r) => r.error), ID);
        else Office.toast(left.length ? T('purge.done_left', { names: left.join(', ') }) : T('purge.done'), left.length > 0);
        return true;
      } },
    ],
  });
}
})();
