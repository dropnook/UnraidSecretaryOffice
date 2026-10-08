/* Ms. Dustdevil — knows every corner of the server, and clears away what nobody uses any more.
   Her page has two parts. «Where is what» (the block `Where` at the end, up to 1.30 Ms. Whereabouts'
   own desk): what is going on, her advice, where things are and every corner in detail — read only.
   «Tidying up»: Docker templates
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
const SECTIONS = ['templates', 'stacks', 'appdata', 'vms', 'scripts', 'docker', 'icons', 'leftovers', 'partners', 'trash'];
const ICONS = { templates: '📄', stacks: '🧩', appdata: '🗃️', vms: '🖥️', scripts: '📜', docker: '🐳', icons: '🖼️', leftovers: '📦', partners: '🤝', trash: '🗑️' };
const GROUPS = {
  templates: ['leftover', 'unused', 'duplicate', 'noname', 'stray_only_here', 'stray_newer', 'stray_name_exists', 'stray_older', 'stray_copy', 'unknown', 'in_use'],
  stacks: ['leftover', 'broken', 'unused', 'unknown', 'in_use'],
  appdata: ['unused', 'check', 'unknown', 'used'],
  vms: ['broken', 'orphan', 'unused', 'check', 'media', 'unknown', 'used'],
  scripts: ['broken', 'dead', 'idle', 'used'],
  docker: ['dangling', 'volume', 'unused', 'cache', 'used'],
  icons: ['template', 'compose', 'none', 'ok'],
  leftovers: ['leftover', 'way_back', 'unknown'],     // shown per restore (renderLeftovers), these for the CSV
  partners: ['leftover', 'dropped'],
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
  partners: ['leftover', 'dropped'],
};
const CLOSED = ['in_use', 'used', 'unknown', 'ok'];  // folded until opened
const KIND_ICONS = { container: '🐳', template: '📄', stack: '🧩', compose: '🧩', flash: '💾', vm: '🖥️' };
const ITEM_ICONS = { template: '📄', stray: '📄', vmdef: '🖥️', userscript: '📜', stack: '🧩', appdata: '🗃️', domain: '🖥️', iso: '💿', nvram: '🔐', tpm: '🔐', snapshotdb: '🔐', icon: '🖼️', leftover: '📦', partner: '🤝' };
const ROOMS = ['templates', 'stacks', 'appdata', 'vms', 'scripts', 'docker', 'icons', 'leftovers', 'partners'];     // where she finds something (not the storeroom)
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
let query = '';             // the page's filter (her «Where is what» searches with it, her rooms filter by it): every word must appear

// ------------------------------------------------------------------ loading
/** Her rooms: as kept at once, a new look following on her page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state && j.state.docker && typeof j.state.docker === 'object') setState(j.state);
  else if (view) render();
  // Mr. Restori finished a restore after her last look: she looks again by herself (once per visit) — his leftovers show
  if (view && !lookedAgain && j.ok && !j.refreshing && state && state.restore_newer && Office.agent.running) {
    lookedAgain = true;
    load(true);
  }
}

/*
 * What she puts aside, removes or empties for good starts from a fresh look at her rooms (Office.freshState(), never a
 * stale list): the selection is what is still there (setState() drops the rest), single things are looked up again.
 */
const fresh = () => Office.freshState(ID);
const trashRun = (id) => ((state && state.trash.runs) || []).find((r) => r.id === id) || null;

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
    leftovers: (state.leftovers || {}).list, partners: (state.partners || {}).list,
  })[sec] || [];
}
const candidates = (sec) => entries(sec).filter((e) => CANDIDATES[sec].includes(e.category));
const removable = (e) => ['image', 'volume', 'cache'].includes(e.kind);
/** The picture a container would get: the one chosen here, else the first one found that loads */
const iconChoice = (e) => picks.get(e.id) || e.suggest || null;
/** Docker's leftovers in use can't be chosen at all; everything else in use only with a warning; a container only with a picture to hang */
const selectable = (e) => !!state && e.why === null && !state.backup_running && !(removable(e) && e.category === 'used')
  && !(e.kind === 'icon' && (e.category === 'ok' || e.category === 'none' || !iconChoice(e)));
const label = (e) => (e.kind === 'template' || e.kind === 'stray' ? e.file : e.kind === 'userscript' ? e.name : e.kind === 'stack' ? e.folder : e.kind === 'cache' ? T('cache.name')
  : e.kind === 'partner' && e.unit ? T('pa.dropped_name', { unit: e.unit, name: e.pair_name || e.name }) : e.name);
const sum = (list) => list.reduce((a, e) => a + (e.bytes || 0), 0);
const words = () => query.trim().toLowerCase().split(/\s+/).filter(Boolean);
/** Does an entry match the filter? Its name and what it is connected to (image, containers, stack, paths) */
function matches(e) {
  const w = words();
  if (!w.length) return true;
  const hay = [label(e), e.name, e.file, e.folder, e.image, e.project, e.uuid, e.from, e.path, e.restore && e.restore.what, ...(e.refs || []),
    ...(e.used_by || []).map((u) => u.name), ...(e.containers || []).map((c) => c.name), ...(e.parts || []).map((p) => p.path),
    e.dataset, e.pair_name, ...(e.units || [])]
    .filter(Boolean).join(' ').toLowerCase();
  return w.every((x) => hay.includes(x));
}
/** VMs only with the VM service switched on, Docker's rooms (appdata too: who uses it is told by Docker) only with Docker */
const visible = (sec) => (sec === 'vms' ? state.vms.enabled : sec === 'scripts' ? state.scripts.installed
  : sec === 'leftovers' ? !!state.leftovers && state.leftovers.restores > 0
  : sec === 'partners' ? !!state.partners && state.partners.list.length > 0
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

  /** sub: «where» or «tidy» (#/cleanup/where — also where Ms. Whereabouts' old addresses lead) — that part in view */
  mount(root, sub) {
    view = build(root);
    lookedAgain = false;
    render();
    Where.render();          // what she knew from before at once; her parts render themselves when they load (never each other)
    const part = { where: view.partWhere, tidy: view.partTidy }[sub];
    const v = view;
    Promise.all([load(false), Where.load(false)]).then(() => {      // once both are there, the part asked for in view
      if (part && view === v && window.scrollY < 40) part.scrollIntoView({ block: 'start' });
    });
  },

  unmount() {
    view = null;
    clearTimeout(timer);
    selection.clear();
    Where.unmount();
  },

  poll() { load(false); Where.load(false); },

  agentChanged() { if (view) render(); },

  menu() {
    return [{ text: T('scan'), act: () => tour(), disabled: !Office.agent.running || busy }];
  },

  async reception() {
    if (!state) await load(false);
    if (!Where.has()) await Where.load(false);
    if (!state) return { bubble: T('bubble.no_data'), facts: [] };
    const facts = [];
    for (const sec of ROOMS) {
      const c = candidates(sec);
      if (c.length) facts.push(T('fact.' + sec, { n: c.length, size: fmt.size(sum(c)) }));
    }
    if (state.trash.runs.length) facts.push(T('fact.trash', { size: state.trash.bytes === null ? '…' : fmt.size(state.trash.bytes) }));
    const w = Where.reception();
    const bubble = [w.summary, bubbleText(), w.findings ? T('where.bubble.findings', { n: w.findings }) : ''].filter(Boolean).join(' ');
    return { bubble, facts: [...facts, ...w.facts] };
  },
});

// ------------------------------------------------------------------ building
function build(root) {
  const v = {};
  v.scanBtn = el('button', 'btn plain');
  v.scanBtn.type = 'button';
  v.scanBtn.append(el('span', 'spin'), T('scan'));
  v.scanBtn.title = T('scan_title');
  v.scanBtn.onclick = () => tour();
  v.search = el('input', 'search');
  v.search.type = 'search';
  v.search.placeholder = T('search');
  v.search.autocomplete = 'off';
  v.search.spellcheck = false;
  v.search.value = query;
  v.search.style.minWidth = '220px';
  v.search.dataset.keep = '1';          // built once: typing here never holds up a new look (core.js calm())
  v.search.oninput = () => setQuery(v.search.value);
  // wake the sleeping disks for this tour — off unless switched on, never remembered
  v.wakeLabel = el('label', 'switch clw-wake');
  v.wake = el('input');
  v.wake.type = 'checkbox';
  v.wakeText = el('span', '', T('where.wake'));
  v.wakeLabel.append(v.wake, v.wakeText);
  v.wakeLabel.title = T('where.wake_title');
  const head = Office.deskHead({ id: ID, icon: Office.desks.get(ID).icon }, { actions: [v.search, v.wakeLabel, v.scanBtn] });
  v.bubble = head.bubble;
  root.appendChild(head.head);
  root.appendChild(Office.pageHelp(ID, [
    [T('part.where'), T('part.where_sub')],
    ...Where.helpItems(),
    [T('part.tidy'), T('part.tidy_sub')],
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
    [T('section.partners'), T('help.partners_text')],
    [T('help.loop'), T('help.loop_text')],
    [T('help.sizes'), T('help.sizes_text')],
    [T('help.safe'), T('help.safe_text')],
  ]));

  // «Where is what» — her knowledge of the server (Where, at the end)
  v.partWhere = part(T('part.where'), T('part.where_sub'));
  root.appendChild(v.partWhere);
  Where.setHooks({
    changed: () => { if (view) renderBubble(); },
    search: (text) => setQuery(text),
  });
  Where.build(v.partWhere);

  // «Tidying up» — her rooms and the storeroom
  v.partTidy = part(T('part.tidy'), T('part.tidy_sub'));
  root.appendChild(v.partTidy);
  v.notice = el('div');
  v.partTidy.appendChild(v.notice);
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('rooms'), T('rooms_sub')));
  v.filterNote = el('p', 'callout warn cl-filter-note');
  v.filterNote.hidden = true;
  v.tiles = el('div', 'cards');
  v.body = el('div', 'section');
  s.append(v.filterNote, v.tiles, v.body);
  v.partTidy.appendChild(s);
  return v;
}

/** One of the page's two parts: its title and a line under it, its sections below */
function part(title, sub) {
  const box = el('div', 'cl-part');
  const head = el('div', 'cl-part-head');
  head.append(el('div', 'cl-part-title', title), el('p', 'cl-part-sub', sub));
  box.appendChild(head);
  return box;
}

/** The page's filter: her «Where is what» searches with it, her rooms show only what matches */
function setQuery(text) {
  query = text;
  if (!view) return;
  if (view.search.value !== text) view.search.value = text;
  // what the filter hides is no longer chosen: nothing is put away that isn't in sight
  for (const e of entries(section)) if (selection.has(e.id) && !matches(e)) selection.delete(e.id);
  filterMark();
  Where.filtered();
  renderSection();
  updateSelbar();
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
  b.onclick = () => setQuery('');
  view.filterNote.appendChild(b);
}

function render() {
  if (!view) return;
  filterMark();
  renderBubble();
  view.scanBtn.disabled = !Office.agent.running || busy;
  renderNotice();
  renderTiles();
  renderSection();
  updateSelbar();
}

/** Greeting, every corner she knows (Where), what lies around (her rooms), then what she noticed and her advice (Where) */
function renderBubble() {
  view.bubble.innerHTML = '';
  const w = Where.bubble();
  view.bubble.append(Office.withGreeting(ID, w ? `${w.summary} ${bubbleText()}` : bubbleText()));
  if (w && w.more) view.bubble.append(' ', w.more);
  // the wake switch says how many disks sleep (array and pools — what the tour would wake)
  const sleeping = Where.sleeping();
  view.wakeText.textContent = sleeping ? T('where.wake_n', { n: sleeping }) : T('where.wake');
  view.wakeLabel.hidden = !sleeping && !view.wake.checked;
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
  if (section === 'partners') body.appendChild(partnersInfo());
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
  partner: () => [partnerMeta, partnerDetail],
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
  const path = e.kind === 'template' || e.kind === 'stray' ? e.path : e.kind === 'stack' ? e.dir : e.kind === 'partner' ? e.dataset : e.parts ? (e.parts[0] || {}).path : e.path;
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

async function installDialog(t) {
  if (!(await fresh())) return;
  t = entries('templates').find((x) => x.id === t.id);
  if (!t) return;
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
        rows.push([T('stored.' + run.where), T('item.' + it.kind), it.name, '', csvDate(run.time), '', it.zfs || '', it.from || run.path]);
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
  if (busy || !(await fresh())) return;
  busy = true;
  button.disabled = true;
  const j = await Office.api.post(`${ID}.icon_fallback`, {});
  busy = false;
  if (!j.ok) { button.disabled = false; Office.toast(Office.errorText(j.error, ID), true); return; }
  setState(j.state);
  Office.toast(T('loop.done'));
}

async function iconsDialog() {
  if (!(await fresh())) return;
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

// ------------------------------------------------------------------ what ended partnerships left
function partnersInfo() {
  const box = el('div', 'cl-info');
  const p = el('p', 'role', T('pa.where') + ' ');
  const a = el('a', '', T('pa.lead'));
  a.href = '#/caretaker';
  p.appendChild(a);
  box.appendChild(p);
  if ((state.partners.asleep || []).length) box.appendChild(asleepCallout(state.partners.asleep));
  return box;
}

function partnerMeta(e, meta, figures) {
  meta.appendChild(el('span', 'mono', e.dataset));
  if ((e.units || []).length) meta.appendChild(chip(T('pa.units', { n: e.units.length }), 'quiet', e.units.join(', ')));
  const sc = snapsChip(e);
  if (sc) meta.appendChild(sc);
  sizeFigures(e, figures, '');
}

function partnerDetail(e) {
  const box = el('div');
  box.appendChild(kv([
    [T('pa.d.dataset'), e.dataset, true],
    [T('pa.d.pair'), e.pair_name ? `${e.pair_name} (${e.name})` : e.name, true],
    [T('pa.d.units'), (e.units || []).length ? lines(e.units) : T('d.none')],
    [T('d.size'), e.bytes !== null && e.bytes !== undefined ? fmt.size(e.bytes) + snapsOf(e) : T('d.not_measured')],
  ]));
  box.appendChild(el('p', 'role', e.unit ? T('pa.dropped_text', { name: e.pair_name || e.name }) : T('pa.text')));
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
/** «Tour»: where everything is (with «wake sleeping disks» all of them first), then what lies around */
async function tour() {
  if (busy) return;
  const wake = !!(view && view.wake.checked);
  busy = true;
  if (view) { view.scanBtn.classList.add('running'); view.scanBtn.disabled = true; }
  const w = await Where.tour(wake);
  busy = false;
  if (view) view.wake.checked = false;
  if (!w.ok) {
    Office.toast(Office.errorText(w.error, ID), true);
    if (view) { view.scanBtn.classList.remove('running'); render(); }
    return;
  }
  if (wake && (w.state.wake_failed || []).length) Office.toast(T('where.wake_failed', { disks: w.state.wake_failed.join(', ') }), true);
  await scan(wake, { ms: w.state.duration_ms, woken: wake ? (w.state.woken || []).length : null });
}

/** Her rooms looked at anew (wake: the disks of her rooms' shares first); after the tour of «Where is what» one toast for both */
async function scan(wake, where) {
  if (busy) return;
  busy = true;
  if (view) { view.scanBtn.classList.add('running'); view.scanBtn.disabled = true; }
  const j = await Office.api.post(`${ID}.scan`, wake ? { wake: true } : {});
  busy = false;
  if (view) view.scanBtn.classList.remove('running');
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); if (view) render(); return; }
  setState(j.state);
  const ms = j.state.duration_ms + (where ? where.ms : 0);
  Office.toast(where && where.woken !== null ? T('where.tour_done_woken', { ms, n: where.woken }) : T('scan_done', { ms }));
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

async function parkDialog() {
  if (!(await fresh())) return;
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
async function removeDialog() {
  if (!(await fresh())) return;
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
  const meta = [T('stored.' + run.where)];
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
  if (busy || !(await fresh())) return;
  it = ((state.trash.runs || []).flatMap((r) => r.items)).find((x) => x.id === it.id && x.present) || null;
  if (!it) return;
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

async function purgeDialog(runs) {
  if (!(await fresh())) return;
  runs = runs.map((r) => trashRun(r.id)).filter((r) => r && !r.purging);
  if (!runs.length) return;
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

// ================================================================== «Where is what»
/* Her knowledge of the server — up to 1.30 the desk of its own of Ms. Whereabouts, kept as one block with its
   own names (state, view, row …; the page's filter `query` is shared): what is going on, her advice «If I were
   you …», «where things are» with their backup protection, and every corner in detail — shares, folders,
   containers and compose, VMs, disks, users and access, SMB/NFS, user scripts and cron, backups, Unraid's
   notifications, plugins. Read only. The agent part: agent/lib/where.php — the state is the part «where»
   (data/cleanup-where.json), what du measured the part «where-sizes». Her texts: lang keys where.* */
const Where = (() => {
const SECTIONS = ['shares', 'folders', 'docker', 'vms', 'disks', 'access', 'network', 'scripts', 'backups', 'notices', 'plugins'];
const STORE = 'cleanup.where.';

// what Ms. Whereabouts remembered in this browser (up to 1.30) is hers now — taken over once
for (const k of ['section', 'folder_sort', 'place', 'advice_hidden']) {
  const old = Office.store('whereabouts.' + k);
  if (old === null || old === undefined) continue;
  if (Office.store(STORE + k) === null) Office.store(STORE + k, old);
  Office.store('whereabouts.' + k, null);
}
Office.store('whereabouts.help', null);

let state = null;
let sizes = { sizes: {}, queue: [], running: [] };
let section = SECTIONS.includes(Office.store(STORE + 'section')) ? Office.store(STORE + 'section') : '';   // '' = none open
let view = null;
let expanded = new Set();
let folderSort = Office.store(STORE + 'folder_sort') || 'name';
let onlyUnused = false;
let sizeTimer = null;
let place = Office.store(STORE + 'place') || '';      // open tile in "where things are"
let shown = [];          // rows of the current section that can unfold: { open(), set(bool) }
let hooks = { changed: () => {}, search: () => {} };   // the page's: the bubble and the wake switch anew; set the filter

// ------------------------------------------------------------------ loading
/**
 * Her last look (the part «where»), as kept at once (core.js Office.loadState(), src/api.php apiLook()). The server
 * looks again when it is older than desk.json's `parts.where.refresh_after` (600 s — its own clock, never the
 * browser's; the short wait, so a long job of the messenger never parks this request for minutes) — in the background
 * while her page is shown, the new look following —, or when asked (`fresh`); the agent's start looks too.
 */
async function load(fresh) {
  const j = await Office.loadState(ID, { part: 'where', fresh }, took);
  await loadSizes();
  if (view) render();
  hooks.changed();
  return j;
}
function took(j, later) {
  if (j.ok && j.part && Array.isArray(j.part.shares)) state = j.part;
  if (later) {            // the new look that followed: drawn here (load() draws the first one after the sizes)
    if (view) render();
    hooks.changed();
  }
}

async function loadSizes() {
  try {
    const j = await Office.api.get({ a: 'part', desk: ID, part: 'where-sizes' });
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
    box.append(el('b', '', fmt.size(info.bytes)), el('span', '', info.how === 'zfs' ? 'ZFS' : T('where.measured')));
    box.title = info.how === 'du' ? T('where.measured_at', { when: fmt.relative(info.at) }) + (info.partial ? ' · ' + T('where.measured_partial') : '') : T('where.zfs_size');
  } else if (info.how === 'running' || info.how === 'queued') {
    box.append(el('b', '', '…'), el('span', '', info.how === 'running' ? T('where.measuring') : T('where.queued')));
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
  [...names.values()].slice(0, max).forEach((u) => out.push(chip(`${kindIcon(u.kind)} ${u.name}`, u.kind === 'container' || u.kind === 'compose' ? 'accent' : '', T('where.kind.' + u.kind))));
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
  more.setAttribute('aria-label', T('where.actions'));
  if (menu) {
    more.onclick = (e) => { e.stopPropagation(); Office.menu(e, menu()); };
    r.oncontextmenu = (e) => { e.preventDefault(); Office.menu(e, menu()); };
  } else {
    more.disabled = true;
  }
  r.appendChild(more);
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; expanded.delete(key); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail');
      box.appendChild(detail());
      r.appendChild(box);
      expanded.add(key);
      r.classList.add('open');
    }
  };
  if (detail) {
    // the whole row unfolds — except clicks on its own buttons, links and fields, or inside the details
    r.classList.add('unfolds');
    n.title = T('where.details');            // only the name: chips explain themselves
    r.onclick = (e) => {
      if (e.target.closest('button, a, input, select, textarea, .row-detail, [data-own]')) return;
      if (String(window.getSelection && window.getSelection()).length) return;     // selecting text
      Office.keepInPlace(r, () => set(!box));
    };
    shown.push({ open: () => !!box, set });
    if (expanded.has(key)) set(true);
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
  const toggle = () => Office.keepInPlace(head, () => box.classList.toggle('closed'));
  head.onclick = (e) => { if (e.target.closest('button')) return; toggle(); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggle(); } };
  box.append(head, body);
  return box;
}

function emptyNote(text) {
  const p = el('p', 'empty');
  p.textContent = text;
  return p;
}

function scheduleText(freq, cron) {
  if (freq === 'custom') return cron ? fmt.cron(cron) : T('where.sched.custom');
  return T('where.sched.' + freq) !== `${ID}.where.sched.${freq}` ? T('where.sched.' + freq) : freq;
}

async function measure(paths, wakes) {
  const go = async () => {
    const j = await Office.api.post(`${ID}.where_measure`, { paths });
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
    sizes = { sizes: j.sizes.sizes || {}, queue: j.sizes.queue || [], running: j.sizes.running || [] };
    renderSection();
    await loadSizes();
  };
  if (!wakes) { go(); return; }
  Office.dialog({
    title: T('where.measure.title'),
    body: T('where.measure.wakes'),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('where.measure.go'), kind: '', act: go }],
  });
}

// ------------------------------------------------------------------ findings
function findings() {
  if (!state) return [];
  const f = [];
  // folders nobody uses and templates without a container: her rooms of «Tidying up» tell those (with their sizes)
  const missingPool = state.shares.filter((s) => s.storage.missing);
  if (missingPool.length) f.push({ text: T('where.find.missing_pool', { n: missingPool.length, names: missingPool.map((s) => s.name).join(', ') }), go: () => pick('shares', missingPool[0].name) });
  const deadRefs = state.scripts.filter((s) => s.missing);
  if (deadRefs.length) f.push({ text: T('where.find.dead_refs', { n: deadRefs.length, names: deadRefs.map((s) => s.name).join(', ') }), go: () => pick('scripts', deadRefs[0].name) });
  const stopped = state.containers.filter((c) => c.autostart && c.state !== 'running');
  if (stopped.length) f.push({ text: T('where.find.autostart_stopped', { n: stopped.length, names: stopped.map((c) => c.name).join(', ') }), go: () => pick('docker') });
  const stale = state.scripts.filter((s) => s.stale);
  if (stale.length) f.push({ text: T('where.find.stale', { n: stale.length }), go: () => pick('scripts') });
  const h = state.health;
  if (h) {
    const hot = h.devices.filter((d) => tempLevel(d) !== '');
    if (hot.length) f.push({ text: T('where.find.hot', { n: hot.length, names: hot.map((d) => `${d.name} ${d.temp} °C`).join(', ') }), go: () => pick('disks') });
    const bad = h.devices.filter((d) => smartBad(d).length || d.errors || (d.status && d.status !== 'DISK_OK'));
    if (bad.length) f.push({ text: T('where.find.smart_bad', { n: bad.length, names: bad.map((d) => d.name).join(', ') }), go: () => pick('disks') });
    const full = h.devices.filter((d) => fillLevel(d) === 'danger');
    if (full.length) f.push({ text: T('where.find.full', { n: full.length, names: full.map((d) => `${d.name} ${fmt.number(d.fill)} %`).join(', ') }), go: () => pick('disks') });
    if (h.parity.slots && !h.parity.present) f.push({ text: T('where.find.no_parity'), go: () => pick('disks') });
  const bare = (h.pools || []).filter((p) => p.tolerates === 0);
  if (bare.length) f.push({ text: T('where.find.pool_unprotected', { n: bare.length, names: bare.map((p) => p.name).join(', ') }), go: () => pick('disks') });
  }
  const alerts = (state.notices || []).filter((n) => n.importance === 'alert');
  if (alerts.length) f.push({ text: T('where.find.alerts', { n: alerts.length }), go: () => pick('notices') });
  const lic = state.license;
  if (lic && lic.expires && lic.expires - Date.now() / 1000 < 30 * 86400) f.push({ text: T('where.find.license_expires', { when: fmt.relative(lic.expires) }), go: () => pick('disks') });
  if (lic && lic.check) f.push({ text: T('where.find.license_check', { detail: lic.check }), go: () => pick('disks') });
  return f;
}

// ------------------------------------------------------------------ advice ("If I were you …")
const ADVICE_HIDDEN = STORE + 'advice_hidden';     // {id: signature} — "I know, thanks" per browser
const DAY = 86400;
const CRON_SAVE = '/boot/config/crontab-root-before-cleanup.txt';     // a copy of root's crontab before a line goes
let showHiddenAdvice = false;

const listNames = (names, max) => names.length > (max || 5) ? `${names.slice(0, max || 5).join(', ')} +${names.length - (max || 5)}` : names.join(', ');
const shareLink = (name) => `/Shares/Share?name=${encodeURIComponent(name)}`;
/** Security is the night watchman's: does he work here? (the office's staff list, not his code) */
const watchmanHired = () => !!(Office.desks.get('watchman') || {}).hired;

/**
 * Her tips: what she would do differently — each with why, where in Unraid
 * to change it, and a signature (what it is about), so a tip she was thanked
 * for comes back once the situation changes. Operational only: security
 * advice is the night watchman's (while he isn't hired, one tip says so).
 * Things Fix Common Problems checks are left to the consultant.
 */
function advice() {
  const a = state && state.advice;
  if (!a) return [];
  const out = [];
  const add = (id, level, params, link, sig, cmds) => out.push({ id, level, params, link, sig: `${id}|${sig ?? ''}`, cmds });
  const shares = state.shares || [];

  const onArray = shares.filter((s) => (a.system_shares || []).includes(s.name) && (s.storage.primary === 'array' || s.storage.secondary === 'array'));
  if (onArray.length) add('system_array', 'advice', { names: listNames(onArray.map((s) => s.name)) }, { path: shareLink(onArray[0].name), text: T('where.adv.to_share', { name: onArray[0].name }) }, onArray.map((s) => s.name).join(','));

  const moved = shares.filter((s) => ['yes', 'prefer'].includes(s.storage.use_cache));
  if (moved.length && !a.mover_schedule) add('mover', 'advice', { names: listNames(moved.map((s) => s.name)), n: moved.length }, { path: '/Settings/Scheduler', text: T('where.adv.to_scheduler') }, moved.map((s) => s.name).join(','));

  // exclusive shares: /mnt/user/<share> a link straight to its pool, past Unraid's FUSE layer (waExclusive())
  const ex = a.exclusive;
  if (ex) {
    const also = (x) => (x.where.length ? `${x.name} (${T('where.adv.exclusive_also', { where: x.where.join(', ') })})` : x.name);
    if (ex.ready.length) {
      add('exclusive_off', 'advice', { names: listNames(ex.ready), n: ex.ready.length },
        { path: '/Settings/ShareSettings', text: T('where.adv.to_share_settings') }, ex.ready.join(','));
    }
    if (ex.elsewhere.length) {
      const f = ex.elsewhere[0];
      const stray = `/mnt/${f.where[0]}/${f.name}`;
      add('exclusive_elsewhere', 'advice', { names: listNames(ex.elsewhere.map(also), 3), n: ex.elsewhere.length },
        { path: `/Main/Browse?dir=${encodeURIComponent(stray)}`, text: T('where.adv.to_browse', { path: stray }) },
        ex.elsewhere.map((x) => `${x.name}:${x.where.join('+')}`).join(','));
    }
    if (ex.unclear.length) {
      const maybe = ex.asleep.length ? T('where.adv.exclusive_unclear.asleep', { disks: listNames(ex.asleep) }) : T('where.adv.exclusive_unclear.awake');
      add('exclusive_unclear', 'info', { names: listNames(ex.unclear), n: ex.unclear.length, maybe },
        { path: '/Main', text: T('where.adv.to_main') }, ex.unclear.join(','));
    }
    // a system share with the array as secondary storage has its own tip above (system_array)
    const over = ex.overflow.filter((x) => !onArray.some((s) => s.name === x.name));
    if (over.length) {
      add('exclusive_overflow', 'info', { names: listNames(over.map(also), 3), n: over.length },
        { path: shareLink(over[0].name), text: T('where.adv.to_share', { name: over[0].name }) }, over.map((x) => x.name).join(','));
    }
  }

  // cron lines whose program went with its plugin: they only fail, quietly (order — not the watchman's)
  const dead = (state.cron || []).filter((c) => c.gone && /^\/[A-Za-z0-9_.\/+@-]+$/.test(c.program || ''));
  if (dead.length) {
    const programs = [...new Set(dead.map((c) => c.program))];
    const own = [...new Set(dead.filter((c) => c.source === 'crontab').map((c) => c.program))];       // root's own crontab (crontab -l)
    const how = [own.length ? T('where.adv.cron_dead.own') : '', dead.some((c) => c.source !== 'crontab') ? T('where.adv.cron_dead.system') : ''].filter(Boolean).join(' ');
    add('cron_dead', 'advice', { names: listNames(programs, 3), n: programs.length, plugins: listNames([...new Set(dead.map((c) => c.gone))], 3), how },
      null, programs.join(','), own.length ? [cronCleanup(own)] : null);
  }

  // stacks that build their own image: Compose Manager's (Force) Update only pulls — a rebuild in a terminal instead
  const built = (state.compose || []).filter((p) => p.builds && p.folder);
  if (built.length) {
    const label = (p) => {
      const images = [...new Set((state.containers || []).filter((c) => c.compose && c.compose.project === p.project
        && (!p.build.length || p.build.includes(c.compose.service))).map((c) => c.image))];
      return images.length ? `${p.name} (${images.join(', ')})` : p.name;
    };
    add('compose_build', 'info', { names: listNames(built.map(label), 3), n: built.length }, { path: '/Docker', text: T('where.adv.to_docker') },
      built.map((p) => `${p.project}:${p.build.join('+')}`).join(','), built.map(rebuildCommand));
  }

  const sp = a.spindown || {};
  if (sp.default === '0') add('spindown_default', 'advice', {}, { path: '/Settings/DiskSettings', text: T('where.adv.to_disks') });
  else if (sp.never) add('spindown_some', 'info', { n: sp.never }, { path: '/Settings/DiskSettings', text: T('where.adv.to_disks') }, sp.never);

  const h = state.health;
  if (h) {
    const old = h.devices.filter((d) => d.smart && d.smart.hours > 50000);
    if (old.length) add('old_disks', 'info', { names: listNames(old.map((d) => `${d.name} (${fmt.number(d.smart.hours / 8760, 1)} ${T('where.adv.years')})`)), n: old.length }, { path: '/Main', text: T('where.adv.to_main') }, old.map((d) => d.name).join(','));
    const p = h.parity || {};
    // no parity at all: one failed array disk and what was on it is gone (Benj, 2026-10-07: she should say so here too)
    if (p.slots && !p.present) {
      const data = h.devices.filter((d) => d.type === 'Data').length;
      add('no_parity', 'advice', { n: data }, { path: '/Main', text: T('where.adv.to_main') }, 'none');
    }
    if (p.present && (!p.checked || Date.now() / 1000 - p.checked > 90 * DAY)) {
      add('parity', 'advice', { when: p.checked ? fmt.relative(p.checked) : T('where.adv.never') }, { path: '/Settings/Scheduler', text: T('where.adv.to_scheduler') }, p.checked ? fmt.dayKey(p.checked) : 'never');
    }
  }
  if (!a.ups) add('ups', 'info', {}, { path: '/Settings/UPSsettings', text: T('where.adv.to_ups') });
  if (!a.syslog_kept) add('syslog', 'advice', {}, { path: '/Settings/SyslogSettings', text: T('where.adv.to_syslog') });

  // Windows VMs at the array stop: Unraid asks (the guest agent, else the ACPI power button), waits the VM
  // shutdown time-out, then switches off hard — an idle Windows with its display off ignores the button (waVmStop())
  const deaf = (state.vms || []).filter((v) => v.os === 'windows' && v.running && v.agent !== 'connected');
  if (a.vm_stop && deaf.length) {
    const names = deaf.map((v) => v.name).sort();
    add('vm_windows', 'advice', { names: listNames(names, 3), n: names.length, timeout: a.vm_stop.timeout, disk: a.vm_stop.disk_timeout },
      { path: '/Settings/VMSettings', text: T('where.adv.to_vm_settings') }, names.join(','));
  }

  // VM disk files far bigger than what they hold (waSparseDisk(): apparent ≥ 4× allocated and ≥ 200 GB more — a 1.6 TB
  // vdisk holding 21 GB): Kopia reads such a file whole at its first upload, the holes as zeros, hours for nothing. Advice
  // while one of them goes to Kopia (its disk's protection), otherwise good to know; the signature is the file and its
  // virtual size (a disk made smaller or replaced brings the tip back, the used part growing doesn't)
  const sparse = [];
  (state.vms || []).forEach((v) => (v.disks || []).forEach((d) => {
    [d, ...(d.chain || [])].forEach((f) => {
      if (f.sparse) sparse.push({ vm: v.name, path: f.source || f.path || '', bytes: f.bytes, used: f.allocated || 0, offsite: f.backup === 'offsite' });
    });
  }));
  if (sparse.length) {
    const files = sparse.map((f) => T('where.adv.vm_sparse.disk', { vm: f.vm, file: f.path.split('/').pop(), virtual: fmt.size(f.bytes), used: fmt.size(f.used) }));
    add('vm_sparse', sparse.some((f) => f.offsite) ? 'advice' : 'info', { names: listNames(files, 3), n: sparse.length, example: files[0] },
      { path: '/VMs', text: T('where.adv.to_vms') }, sparse.map((f) => `${f.vm}:${f.path}:${f.bytes}`).join(','));
  }

  // VMs with a NIC of Unraid's «virtio-net» model (waVmNetModel()): no vhost, the network runs through the QEMU process —
  // on nostromo one stream capped at 2.2 Gbit/s, 93 with «virtio». A performance hint, not a risk; the signature is the
  // VMs' names (one more, or one changed, brings the tip back)
  const slowNet = (a.vm_netmodel || []).filter((n) => typeof n === 'string' && n !== '');
  if (slowNet.length) {
    add('vm_netmodel', 'info', { names: listNames(slowNet, 3), n: slowNet.length }, { path: '/VMs', text: T('where.adv.to_vms') }, slowNet.join(','));
  }

  // security advice is the night watchman's — while he doesn't work here, she says where it went
  if (Office.desks.has('watchman') && !watchmanHired()) add('security', 'info', {}, { path: '#/caretaker', text: T('where.adv.to_team_lead') });
  return out;
}

/** Removes root's own crontab lines that start these programs — a copy of it goes to the flash first (shown, never run by the office) */
function cronCleanup(programs) {
  return `crontab -l > ${CRON_SAVE}; crontab -l | grep -v -F${programs.map((x) => ` -e '${x}'`).join('')} | crontab -`;
}

/** The rebuild of a stack that builds its own image, run in its folder: build --pull for those services, then up -d */
function rebuildCommand(p) {
  const sh = (x) => (/^[A-Za-z0-9_\/.,:@%+=-]+$/.test(x) ? x : `'${x.replace(/'/g, `'\\''`)}'`);
  const dc = `docker compose -p ${sh(p.project)}${p.env_file ? ` --env-file ${sh(p.env_file)}` : ''}`;
  return `cd ${sh(p.folder)} && ${dc} build --pull${p.build.map((x) => ' ' + sh(x)).join('')} && ${dc} up -d`;
}

function adviceHidden() {
  const hidden = Office.storeJson(ADVICE_HIDDEN);
  return hidden && typeof hidden === 'object' ? hidden : {};
}

function renderAdvice() {
  const box = view.advice;
  box.innerHTML = '';
  if (!state) return;
  const all = advice();
  const hidden = adviceHidden();
  const isHidden = (x) => hidden[x.id] === x.sig;
  const shown = all.filter((x) => !isHidden(x));
  const gone = all.filter(isHidden);
  const extra = [];
  if (gone.length) {
    const b = el('button', 'btn small plain', T(showHiddenAdvice ? 'where.adv.hide_known' : 'where.adv.show_known', { n: gone.length }));
    b.type = 'button';
    b.onclick = () => { showHiddenAdvice = !showHiddenAdvice; Office.keepInPlace(b, renderAdvice); };
    extra.push(b);
  }
  box.appendChild(Office.sectionHead(T('where.adv.title'), T('where.adv.sub'), ...extra));
  const list = [...shown, ...(showHiddenAdvice ? gone : [])];
  if (!list.length) {
    box.appendChild(el('p', 'role clw-advice-none', T(all.length ? 'where.adv.all_known' : 'where.adv.none')));
    return;
  }
  const rows = el('div', 'box');
  list.forEach((x) => rows.appendChild(adviceRow(x, isHidden(x))));
  box.appendChild(rows);
}

function adviceRow(x, known) {
  const r = el('div', 'row nocheck clw-advice' + (known ? ' clw-advice-known' : ''));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', T(`where.adv.${x.id}.title`, x.params)));
  const meta = el('div', 'row-meta');
  const chip = el('span', 'chip ' + (x.level === 'advice' ? 'accent' : 'quiet'), T(`where.adv.level_${x.level}`));
  chip.title = T(`where.adv.level_${x.level}_text`);
  meta.appendChild(chip);
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T(`where.adv.${x.id}.why`, x.params)));
  if (x.cmds && x.cmds.length) main.appendChild(el('div', 'mono clw-advice-cmd', x.cmds.join('\n')));
  r.appendChild(main);
  const acts = el('div', 'clw-advice-acts');
  if (x.link) {
    const a = el('a', 'btn small plain', x.link.text);
    const href = Office.safeHref(x.link.path);
    if (href) a.href = href;
    acts.appendChild(a);
  }
  const b = el('button', 'btn small plain', T(known ? 'where.adv.show_again' : 'where.adv.known'));
  b.type = 'button';
  b.onclick = () => {
    const hidden = adviceHidden();
    if (known) delete hidden[x.id]; else hidden[x.id] = x.sig;
    Office.storeJson(ADVICE_HIDDEN, hidden);
    Office.keepInPlace(view.advice, () => { renderAdvice(); hooks.changed(); });
  };
  acts.appendChild(b);
  r.appendChild(acts);
  return r;
}

function pick(id, search) {
  section = id;
  Office.store(STORE + 'section', id);
  if (search !== undefined) hooks.search(search);
  if (view) { renderTabs(); renderSection(); view.sectionBox.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}

function closeSection() {
  section = '';
  Office.store(STORE + 'section', null);
  if (view) Office.keepInPlace(view.tabs, () => { renderTabs(); renderSection(); });
}

/** Her part of the bubble: every corner she knows (summary), what she noticed (links) and her advice (more) — null before her first look */
function bubble() {
  if (!state) return null;
  const summary = T('where.bubble.summary', { shares: state.shares.length, containers: state.containers.length, scripts: state.scripts.length });
  const f = findings();
  const hidden = adviceHidden();
  const tips = advice().filter((x) => hidden[x.id] !== x.sig).length;
  if (!f.length && !tips) return { summary, more: null };
  const box = el('span');
  if (f.length) {
    box.append(T('where.bubble.noticed'), ' ');
    f.forEach((x, i) => {
      const a = el('a', '', x.text);
      a.href = '#';
      a.onclick = (e) => { e.preventDefault(); x.go(); };
      box.append(a, i < f.length - 1 ? ' · ' : '.');
    });
  }
  if (tips) {
    const a = el('a', '', T('where.bubble.advice', { n: tips }));
    a.href = '#';
    a.onclick = (e) => { e.preventDefault(); if (view) view.advice.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    box.append(f.length ? ' ' : '', a);
  }
  return { summary, more: box };
}

/** For the reception: every corner she knows, how many things she noticed, and the facts Ms. Whereabouts gave there */
function reception() {
  if (!state) return { summary: '', findings: 0, facts: [] };
  const sys = state.system;
  const facts = [
    T('where.fact.docker', { running: sys.docker.running, total: sys.docker.total }),
    T('where.fact.vms', { running: sys.vms.running, total: sys.vms.total }),
    T('where.fact.smb', { n: state.smb.sessions.length }),
  ];
  if (sys.scripts.length) facts.push(T('where.fact.scripts_running', { n: sys.scripts.length, names: sys.scripts.join(', ') }));
  const summary = T('where.bubble.summary', { shares: state.shares.length, containers: state.containers.length, scripts: state.scripts.length });
  return { summary, findings: findings().length, facts };
}

// ------------------------------------------------------------------ building
/** Her sections, into the part «Where is what» */
function build(root) {
  const v = {};
  const now = el('section', 'section');
  v.nowHint = el('span', 'hint');
  const nh = Office.sectionHead(T('where.now'), T('where.now_sub'), v.nowHint);
  v.stats = el('div', 'stats');
  now.append(nh, v.stats);
  root.appendChild(now);

  v.advice = el('section', 'section');         // "If I were you …" — renderAdvice()
  root.appendChild(v.advice);

  v.places = el('section', 'section');
  const ph = Office.sectionHead(T('where.places'), T('where.places_hint'));
  v.placeTiles = el('div', 'cards clw-places');
  v.placeDetail = el('div');
  v.places.append(ph, v.placeTiles, v.placeDetail);
  root.appendChild(v.places);

  v.sectionBox = el('section', 'section');
  const dh = Office.sectionHead(T('where.details_title'), T('where.details_hint'));
  v.tabs = el('div', 'cards clw-sections');
  v.sectionBody = el('div', 'section');
  v.sectionBox.append(dh, v.tabs, v.sectionBody);
  root.appendChild(v.sectionBox);
  view = v;
}

function render() {
  if (!view) return;
  renderStats();
  renderAdvice();
  renderPlaces();
  renderTabs();
  renderSection();
}

/** Only what the tour wakes: array and pool disks (an unassigned one, e.g. a spare parity disk, stays out) */
const sleeping = () => (state && state.health ? state.health.devices.filter((d) => d.asleep && d.origin !== 'unassigned').length : 0);

/** The tour of «Where is what»: her look at the whole server — with «wake» every sleeping disk first */
async function tour(wake) {
  const j = await Office.api.post(`${ID}.where_scan`, wake ? { wake: true } : {});
  if (j.ok && j.state) {
    state = j.state;
    if (view) render();
    hooks.changed();
  }
  return j;
}


// ------------------------------------------------------------------ what's going on
function renderStats() {
  const box = view.stats;
  box.innerHTML = '';
  if (!state) return;
  const sys = state.system;
  view.nowHint.textContent = `${sys.name} · Unraid ${sys.unraid || '?'} · ${T('where.uptime', { time: fmt.duration(sys.uptime) })} · ${Office.t('common.scanned_ago', { when: fmt.relative(state.time) })}`;
  const tile = (label, value, sub, opts = {}) => {
    const t = el(opts.go ? 'button' : 'div', 'stat' + (opts.alert ? ' alert' : ''));
    if (opts.go) { t.type = 'button'; t.onclick = opts.go; }
    t.append(el('div', 'stat-label', label), el('div', 'stat-value', value));
    if (sub) t.append(el('div', 'stat-sub', sub));
    box.appendChild(t);
  };
  const array = sys.array;
  tile(T('where.stat.array'), array.state === 'STARTED' ? T('where.stat.started') : (array.state || '?'),
    array.resync ? T('where.stat.resync', { action: array.resync.action, percent: array.resync.percent }) : (sys.mover ? T('where.stat.mover') : T('where.stat.no_parity')),
    { alert: array.state !== 'STARTED' || !!array.resync });
  tile('Docker', `${sys.docker.running} / ${sys.docker.total}`, T('where.stat.running'), { go: () => pick('docker') });
  tile('VMs', `${sys.vms.running} / ${sys.vms.total}`, T('where.stat.running'), { go: () => pick('vms') });
  const sessions = state.smb.sessions;
  tile(T('where.stat.smb'), fmt.number(sessions.length), sessions.map((s) => `${s.user}@${s.machine}`).join(', ') || T('where.stat.nobody'), { go: () => pick('network') });
  tile(T('where.stat.scripts'), fmt.number(sys.scripts.length), sys.scripts.join(', ') || T('where.stat.none_running'), { go: () => pick('scripts'), alert: sys.scripts.length > 0 });
  tile(T('where.stat.backup'), sys.backup.running ? T('where.stat.backup_running') : T('where.stat.backup_idle'),
    sys.backup.running ? `${T('where.stat.since', { time: fmt.time(sys.backup.since) })} · ${sys.backup.step || ''}` : '', { go: () => pick('backups'), alert: sys.backup.running });
  const h = state.health;
  if (h) {
    const awake = h.devices.filter((d) => d.temp !== null && d.temp !== undefined);
    const hottest = awake.reduce((a, d) => (!a || d.temp > a.temp ? d : a), null);
    const warm = awake.filter((d) => tempLevel(d) !== '');
    tile(T('where.stat.temp'), hottest ? `${hottest.temp} °C` : '–', hottest ? (warm.length ? T('where.stat.temp_warm', { n: warm.length }) : T('where.stat.temp_ok', { name: hottest.name })) : T('where.stat.all_asleep'),
      { go: () => pick('disks'), alert: warm.length > 0 });
    const bad = h.devices.filter((d) => smartBad(d).length).length;
    const notes = h.devices.filter((d) => smartNotices(d).length).length;
    tile('SMART', bad ? T('where.stat.smart_bad', { n: bad }) : T('where.stat.smart_ok'), notes ? T('where.stat.smart_notes', { n: notes }) : '',
      { go: () => pick('disks'), alert: bad > 0 });
  }
  const notices = state.notices || [];
  if (notices.length) {
    const alerts = notices.filter((n) => n.importance === 'alert').length;
    const warnings = notices.filter((n) => n.importance === 'warning').length;
    tile(T('where.stat.notices'), fmt.number(notices.length), T('where.stat.notices_sub', { alerts, warnings }), { go: () => pick('notices'), alert: alerts > 0 });
  }
  const lic = state.license;
  if (lic && lic.type) tile(T('where.stat.license'), lic.type, lic.expires ? T('where.stat.license_until', { when: fmt.date(lic.expires) }) : T('where.stat.license_since', { when: fmt.date(lic.since) }),
    { go: () => pick('disks'), alert: !!lic.check });
  if (sys.scrubs.length) tile(T('where.stat.scrub'), sys.scrubs.map((s) => s.pool).join(', '), sys.scrubs.map((s) => s.text).join(' · '), { alert: true });
  if (sys.load) tile(T('where.stat.load'), sys.load.map((x) => fmt.number(x, 1)).join(' · '), T('where.stat.load_sub'));
}

// ------------------------------------------------------------------ where things are
const PLACE_ICONS = { unraid: '⚙️', docker: '🐳', compose: '🧩', vms: '🖥️', cron: '⏰', scripts: '📜', office: '🗂️' };
const backupChip = Office.backupChip;     // offsite / only local / not backed up — same on every desk

function copyButton(text) {
  const b = el('button', 'btn small plain clw-copy', Office.t('common.copy'));
  b.type = 'button';
  b.title = text;
  b.onclick = (e) => { e.stopPropagation(); Office.copy(text); };
  return b;
}

/** One path: label, path, figures, protection, copy — used in tiles and VM details */
function pathLine(label, path, opts = {}) {
  const li = el('div', 'clw-path' + (opts.missing ? ' missing' : ''));
  const text = el('div', 'clw-path-text');
  text.append(el('div', 'clw-path-label', label), el('div', 'mono', path));
  if (opts.note) text.appendChild(el('div', 'clw-path-note', opts.note));
  const fig = el('div', 'clw-col-fig', opts.figure || '');
  const bk = el('div', 'clw-col-bk');
  const c = backupChip(opts.backup);
  if (c) bk.appendChild(c);
  li.append(text, fig, bk, copyButton(path));
  return li;
}


/** "How to read this page": her items, under the page's own */
function helpItems() {
  return [
    [T('where.help.labels'), T('where.help.labels_text')],
    ...['offsite', 'local', 'none'].map((l) => [Office.backupChip(l), Office.t('protect.' + l + '_text')]),
    [T('where.help.tiles'), T('where.help.tiles_text')],
    [T('where.help.copy'), T('where.help.copy_text')],
    [T('where.help.rows'), T('where.help.rows_text')],
    [T('where.help.search'), T('where.help.search_text')],
    [T('where.help.tour'), T('where.help.tour_text')],
    [T('where.help.asleep'), T('where.help.asleep_text')],
  ];
}

function placeSummary(g) {
  const items = g.items || [];
  const main = items[0];
  // what lives in RAM (system log, generated crontabs) and Docker's image data (rebuilt by pulling) don't count for the tile
  const levels = items.filter((i) => !i.ram && i.id !== 'docker_image').map((i) => i.backup).filter(Boolean);
  const worst = levels.includes('none') ? 'none' : levels.includes('local') ? 'local' : levels.length ? 'offsite' : null;
  let sub = '';
  if (g.id === 'docker') sub = T('where.place.docker_sub', { n: (items.find((i) => i.id === 'docker_templates') || {}).count || 0 });
  else if (g.id === 'compose') sub = T('where.place.compose_sub', { n: (g.stacks || []).length });
  else if (g.id === 'vms') sub = T('where.place.vms_sub', { n: g.vms || 0 });
  else if (g.id === 'scripts') sub = T('where.place.scripts_sub', { n: (items[0] || {}).count || 0 });
  else if (g.id === 'cron') sub = T('where.place.cron_sub', { n: g.jobs || 0, files: (items[0] || {}).count || 0 });
  else if (g.id === 'unraid') sub = bootShort(g.boot);
  else if (g.id === 'office') sub = T('where.place.office_sub');
  return { main, worst, sub };
}

/** What /boot is on this server: USB stick, internal disk or a boot pool */
function bootShort(b) {
  if (!b) return '';
  if (b.kind === 'pool') return T('where.boot.pool_short', { pool: b.pool, layout: T('where.boot.layout.' + (b.layout || 'single')), n: b.devices.length });
  if (b.kind === 'usb') return T('where.boot.usb_short', { name: b.vendor || (b.devices[0] || {}).model || 'USB' });
  if (b.kind === 'internal') return T('where.boot.internal_short', { dev: (b.devices[0] || {}).dev || '?' });
  return T('where.boot.other_short', { fs: b.fs || '?' });
}

function bootIntro(b) {
  const box = el('div', 'clw-place-intro');
  if (!b) { box.textContent = T('where.place.unraid_intro'); return box; }
  const devs = b.devices.map((d) => `${d.dev}${d.model ? ' (' + d.model.replace(/\s+/g, ' ') + ')' : ''}`).join(', ');
  let text;
  if (b.kind === 'pool') text = T('where.boot.pool', { pool: b.pool, layout: T('where.boot.layout.' + (b.layout || 'single')), devices: devs, efi: b.efi.join(', ') || '–' });
  else if (b.kind === 'usb') text = T('where.boot.usb', { name: b.vendor || devs || 'USB', guid: b.guid || '?' });
  else if (b.kind === 'internal') text = T('where.boot.internal', { devices: devs });
  else text = T('where.boot.other', { fs: b.fs || '?' });
  box.appendChild(el('p', '', text));
  box.appendChild(el('p', '', T('where.boot.restore')));
  if (b.license) box.appendChild(el('p', '', T('where.boot.license_' + b.license, { file: b.license_file || 'config/*.key', guid: b.guid || '?' })));
  if (b.kind === 'pool' && b.state && b.state !== 'ONLINE') box.appendChild(el('p', 'callout warn', T('where.boot.degraded', { pool: b.pool, state: b.state })));
  if (b.kind === 'pool' && b.layout === 'single') box.appendChild(el('p', 'callout', T('where.boot.single')));
  return box;
}

function renderPlaces() {
  const tiles = view.placeTiles;
  const detail = view.placeDetail;
  tiles.innerHTML = '';
  detail.innerHTML = '';
  view.places.hidden = !state || !state.locations;
  if (!state || !state.locations) return;
  for (const g of state.locations) {
    const { main, worst, sub } = placeSummary(g);
    const card = el('button', 'card' + (place === g.id ? ' active' : ''));
    card.type = 'button';
    const head = el('div', 'card-head');
    head.append(el('span', 'clw-place-icon', PLACE_ICONS[g.id] || '•'), el('span', 'card-name', T('where.place.' + g.id)));
    const c = backupChip(worst);
    if (c) { c.classList.add('status'); head.appendChild(c); }
    card.appendChild(head);
    if (main) card.appendChild(el('div', 'card-line mono clw-ellipsis', main.mounted ? `${main.path}` : main.path));
    card.appendChild(el('div', 'card-figures', sub));
    card.onclick = () => {
      place = place === g.id ? '' : g.id;
      Office.store(STORE + 'place', place);
      Office.keepInPlace(view.placeTiles, renderPlaces);
    };
    tiles.appendChild(card);
  }
  const g = state.locations.find((x) => x.id === place);
  if (g) detail.appendChild(placeDetail(g));
}

function placeDetail(g) {
  const box = el('div', 'box clw-place');
  if (g.id === 'unraid') box.appendChild(bootIntro(g.boot));
  else box.appendChild(el('p', 'clw-place-intro', T('where.place.' + g.id + '_intro')));
  for (const it of g.items) {
    let figure = '';
    if (it.count !== null && it.count !== undefined) figure = T('where.loc_count', { n: it.count });
    else if (it.bytes) figure = fmt.size(it.bytes);
    const note = [Office.has(`${ID}.where.loc.${it.id}_note`) ? T('where.loc.' + it.id + '_note', { mounted: it.mounted || '' }) : '',
      it.note ? T('where.loc.docker_' + it.note) : '', it.ram ? T('where.loc.ram') : '', !it.exists ? T('where.loc.missing') : ''].filter(Boolean).join(' ');
    box.appendChild(pathLine(T('where.loc.' + it.id), it.path, { figure, backup: it.backup, note, missing: !it.exists }));
    if (it.files && it.files.length) {
      const det = el('details', 'clw-files');
      det.appendChild(el('summary', '', T('where.loc.show_files', { n: it.files.length })));
      const ul = el('div', 'mono clw-file-list');
      ul.textContent = it.files.join('\n');
      det.appendChild(ul);
      box.appendChild(det);
    }
  }
  if (g.id === 'compose') {
    for (const st of g.stacks || []) {
      const head = el('div', 'clw-stack');
      head.append(el('strong', '', `🧩 ${st.name}`), el('span', 'role', st.indirect ? T('where.loc.indirect', { path: st.indirect }) : ''));
      box.appendChild(head);
      if (!st.files.length) box.appendChild(el('p', 'role clw-indent', T('where.loc.no_compose_files')));
      st.files.forEach((f) => {
        const line = pathLine(f.path.split('/').pop(), f.path, { backup: f.backup });
        line.classList.add('clw-in-stack');          // under its stack's title bar
        box.appendChild(line);
      });
    }
  }
  if (g.id === 'vms' && (state.vms || []).length) {
    const a = el('a', '', T('where.place.vms_more'));
    a.href = '#';
    a.onclick = (e) => { e.preventDefault(); pick('vms'); };
    const p = el('p', 'role clw-indent');
    p.appendChild(a);
    box.appendChild(p);
  }
  return box;
}

// ------------------------------------------------------------------ sections
function sectionCounts() {
  if (!state) return {};
  const appdata = state.appdata;
  return {
    shares: state.shares.filter(shareMatches).length,
    folders: state.folders.reduce((a, f) => a + f.folders.filter((x) => folderMatches(f, x)).length, 0),
    docker: state.containers.filter(containerMatches).length,
    vms: state.vms.filter(vmMatches).length,
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

const SECTION_ICONS = { shares: '📁', folders: '🗃️', docker: '🐳', vms: '🖥️', disks: '💽', access: '👥', network: '🔌', scripts: '📜', backups: '🛟', notices: '🔔', plugins: '🧩' };

/** One line per section for its tile, and whether something needs a look */
function sectionSummary(id) {
  const s = state;
  const n = (x) => fmt.number(x);
  switch (id) {
    case 'shares': {
      const b = { offsite: 0, local: 0, none: 0 };
      s.shares.forEach((x) => { if (b[x.backup] !== undefined) b[x.backup]++; });
      const known = b.offsite + b.local + b.none;
      return { sub: known ? T('where.sum.shares_backup', b) : T('where.sum.shares', { n: s.shares.length }), alert: s.shares.some((x) => x.storage.missing) };
    }
    case 'folders': {
      const appdata = s.folders.find((x) => x.appdata);
      const unused = appdata ? appdata.folders.filter((x) => !x.used_by.length).length : 0;
      return { sub: unused ? T('where.sum.folders_unused', { n: unused, share: appdata.share }) : T('where.sum.folders') };
    }
    case 'docker': {
      const run = s.containers.filter((c) => c.state === 'running').length;
      const stopped = s.containers.filter((c) => c.autostart && c.state !== 'running').length;
      return { sub: T('where.sum.docker', { run: n(run), total: n(s.containers.length), stacks: s.compose.length }), alert: stopped > 0 };
    }
    case 'vms': return { sub: T('where.sum.vms', { run: n(s.vms.filter((v) => v.running).length), total: n(s.vms.length) }) };
    case 'disks': {
      const h = s.health;
      if (!h) return { sub: '' };
      const hot = h.devices.filter((d) => tempLevel(d) !== '').length;
      const bad = h.devices.filter((d) => smartBad(d).length || d.errors).length;
      const asleep = h.devices.filter((d) => d.asleep).length;
      return { sub: T('where.sum.disks', { n: asleep }), alert: hot > 0 || bad > 0 || h.devices.some((d) => fillLevel(d) === 'danger') };
    }
    case 'access': return { sub: T('where.sum.access', { n: s.users.length }) };
    case 'network': return { sub: T('where.sum.network', { smb: s.smb.sessions.length, nfs: (s.nfs.exports || []).length }) };
    case 'scripts': {
      const running = s.scripts.filter((x) => x.running).length;
      return { sub: T('where.sum.scripts', { scripts: s.scripts.length, cron: s.cron.length, running }), alert: s.scripts.some((x) => x.missing || x.stale) };
    }
    case 'backups': return { sub: T('where.sum.backups', { n: s.backups.length }) };
    case 'notices': {
      const alerts = (s.notices || []).filter((x) => x.importance === 'alert').length;
      return { sub: T('where.sum.notices', { n: (s.notices || []).length, alerts }), alert: alerts > 0 };
    }
    case 'plugins': return { sub: T('where.sum.plugins', { n: s.plugins.length }) };
  }
  return { sub: '' };
}

function renderTabs() {
  const box = view.tabs;
  box.innerHTML = '';
  if (!state) return;
  const counts = sectionCounts();
  for (const id of SECTIONS) {
    const card = el('button', 'card clw-section' + (section === id ? ' active' : ''));
    card.type = 'button';
    card.setAttribute('aria-pressed', String(section === id));
    const head = el('div', 'card-head');
    head.append(el('span', 'clw-place-icon', SECTION_ICONS[id] || '•'), el('span', 'card-name', T('where.section.' + id)));
    const { sub, alert } = sectionSummary(id);
    if (alert) head.appendChild(chip(T('where.look'), 'warn clw-look'));
    if (counts[id] !== undefined) head.appendChild(el('span', 'clw-count', fmt.number(counts[id])));
    card.appendChild(head);
    card.appendChild(el('div', 'card-figures', sub));
    card.onclick = () => (section === id ? closeSection() : pick(id));     // the open tile closes again
    box.appendChild(card);
  }
}

function renderSection() {
  if (!view) return;
  const body = view.sectionBody;
  body.innerHTML = '';
  shown = [];
  if (!state) { body.appendChild(emptyNote(Office.agent.running ? T('where.bubble.no_tour') : T('where.bubble.no_data'))); return; }
  if (!section) return;                          // no tile open
  ({ shares, folders, docker, vms, disks, access, network, scripts, backups, notices, plugins })[section](body);
  if (shown.length > 1) body.prepend(unfoldBar(body));
}

/** "Unfold all" / "Fold all" for the rows (and groups) of the current section */
function unfoldBar(body) {
  const bar = el('div', 'toolbar clw-unfold');
  const b = el('button', 'btn small plain');
  b.type = 'button';
  const label = () => { b.textContent = shown.some((x) => !x.open()) ? T('where.unfold_all') : T('where.fold_all'); };
  b.onclick = () => {
    const open = shown.some((x) => !x.open());
    Office.keepInPlace(bar, () => {
      body.querySelectorAll('.group').forEach((g) => g.classList.toggle('closed', !open));
      shown.forEach((x) => x.set(open));
    });
    label();
  };
  body.addEventListener('click', () => setTimeout(label, 0));
  label();
  bar.appendChild(b);
  return bar;
}

// --------------------------------------------------------------- shares
function shareMatches(s) {
  return matches(join(s.name, s.comment, s.storage.primary, s.storage.secondary, s.storage.pools, s.storage.disks, s.storage.dataset,
    s.smb.read, s.smb.write, s.used_by.map((u) => [u.name, u.path])));
}

function storageText(st) {
  const where = st.primary === 'array' ? T('where.array') : st.primary;
  if (!st.secondary) return where;
  return `${where} → ${st.secondary === 'array' ? T('where.array') : st.secondary}`;
}

function smbChip(s) {
  if (s.smb.export === '-' || !s.smb.export) return null;
  const sec = T('where.security.' + s.smb.security);
  return chip(`SMB · ${sec}${s.smb.export === 'eh' ? ' · ' + T('where.hidden') : ''}`, '', T('where.smb_title', { read: s.smb.read.join(', ') || '–', write: s.smb.write.join(', ') || '–' }));
}

function shares(body) {
  const list = state.shares.filter(shareMatches);
  const box = el('div', 'box');
  if (!list.length) box.appendChild(emptyNote(T('where.nothing_found')));
  for (const s of list) {
    const st = s.storage;
    const info = sizeInfo(s.measure, s.size);
    const wakes = st.primary === 'array' || st.secondary === 'array';
    box.appendChild(row({
      key: 'share:' + s.name,
      name: s.name,
      meta: [
        s.comment ? el('span', 'note', s.comment) : null,
        chip(storageText(st), st.missing ? 'danger' : '', st.missing ? T('where.missing_pool', { pool: st.primary }) : null),
        st.exclusive ? chip(T('where.exclusive'), 'quiet', T('where.exclusive_title')) : null,
        smbChip(s),
        s.nfs.export === 'e' ? chip('NFS', '') : null,
        s.smb.timemachine_limit ? chip('Time Machine', 'quiet') : null,
        ...usedByChips(s.used_by),
        !s.has_cfg ? chip(T('where.no_cfg'), 'warn', T('where.no_cfg_title')) : null,
      ],
      figures: sizeFigures(info),
      detail: () => kv([
        [T('where.path'), `/mnt/user/${s.name}`, true],
        [T('where.real_path'), st.real, true],
        [T('where.dataset'), st.dataset, true],
        [T('where.use_cache'), T('where.cache.' + st.use_cache) + ` (${st.use_cache})`],
        [T('where.found_on'), [...st.pools, ...st.disks]],
        [T('where.unknown_on'), st.unknown.length ? `${st.unknown.join(', ')} — ${T('where.asleep_unknown')}` : null],
        [T('where.include'), st.include],
        [T('where.exclude'), st.exclude],
        ['SMB', s.smb.export === '-' ? T('where.not_exported') : `${T('where.security.' + s.smb.security)} · ${T('where.read')}: ${s.smb.read.join(', ') || '–'} · ${T('where.write')}: ${s.smb.write.join(', ') || '–'}`],
        ['NFS', s.nfs.export === 'e' ? `${T('where.security.' + s.nfs.security)} ${s.nfs.hosts}` : T('where.not_exported')],
        [T('where.used_by'), s.used_by.length ? lines(s.used_by.map((u) => `${kindIcon(u.kind)} ${u.name}: ${u.path}${u.detail ? ' → ' + u.detail : ''}`)) : T('where.nobody')],
      ]),
      menu: () => [
        { text: info.bytes !== undefined && info.how === 'zfs' ? T('where.size_known') : T('where.measure.one'), act: () => measure([s.measure], wakes), disabled: info.how === 'zfs' || !Office.agent.running },
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
    const b = el('button', '', T('where.sort.' + id));
    b.type = 'button';
    b.setAttribute('aria-pressed', String(folderSort === id));
    b.onclick = () => { folderSort = id; Office.store(STORE + 'folder_sort', id); renderSection(); };
    sort.appendChild(b);
  }
  const unused = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = onlyUnused;
  cb.onchange = () => { onlyUnused = cb.checked; renderTabs(); renderSection(); };
  unused.append(cb, el('span', '', T('where.only_unused', { share: state.appdata || 'appdata' })));
  bar.append(sort, unused, el('span', 'hint', T('where.folders_hint')));
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
    const all = el('button', 'btn plain small', T('where.measure.all', { n: unknown.length }));
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
          ...(x.used_by.length ? usedByChips(x.used_by) : [f.appdata ? chip(T('where.unused'), 'warn', T('where.unused_title')) : null]),
          x.dataset ? chip('ZFS', 'quiet', x.dataset) : null,
          x.modified ? el('span', '', T('where.changed', { when: fmt.relative(x.modified) })) : null,
        ],
        figures: sizeFigures(info),
        detail: () => kv([
          [T('where.path'), x.path, true],
          [T('where.real_path'), x.real, true],
          [T('where.dataset'), x.dataset, true],
          [T('where.used_by'), x.used_by.length ? lines(x.used_by.map((u) => `${kindIcon(u.kind)} ${u.name}: ${u.path}${u.detail ? ' → ' + u.detail : ''}`)) : T('where.nobody')],
        ]),
        menu: () => [
          { text: T('where.measure.one'), act: () => measure([x.real], false), disabled: info.how === 'zfs' || !Office.agent.running },
          { text: Office.t('common.copy_path'), act: () => Office.copy(x.path) },
        ],
      });
    });
    const meta = [f.base, T('where.folder_count', { n: f.folders.length }), f.files ? T('where.file_count', { n: f.files }) : null, f.cut ? T('where.cut') : null].filter(Boolean).join(' · ');
    box.appendChild(group(f.share, meta, rows, { button: all, chips: f.appdata ? [chip('appdata', 'accent')] : [] }));
  }
  if (!any) box.appendChild(emptyNote(T('where.nothing_found')));
  body.appendChild(box);
  body.appendChild(el('p', 'hint', T('where.folders_array_note')));
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
      chip(c.state === 'running' ? T('where.state.running') : c.state, c.state === 'running' ? 'ok' : 'quiet'),
      el('span', 'mono', c.image),
      c.autostart ? chip(T('where.autostart'), 'quiet') : null,
      c.privileged ? chip(T('where.privileged'), 'warn', T('where.privileged_title')) : null,
      ips ? el('span', '', ips) : null,
      ports.length ? el('span', '', ports.join(' ')) : null,
    ],
    detail: () => kv([
      [T('where.image'), c.image, true],
      [T('where.managed'), c.managed === 'compose' ? `Compose: ${c.compose.project}` : c.managed === 'template' ? T('where.managed.template') : T('where.managed.other')],
      [T('where.template'), c.template, true],
      [T('where.compose_files'), c.compose ? lines(c.compose.files) : null],
      [T('where.restart'), c.restart || '–'],
      [T('where.network'), ips],
      [T('where.ports'), c.ports.map((p) => `${p.ip || ''}${p.host ? ':' + p.host : ''} → ${p.container}`)],
      [T('where.mounts'), c.mounts.length ? lines(c.mounts.map((m) => `${m.source} → ${m.dest}${m.rw ? '' : ' (ro)'}`)) : null],
      ['WebUI', c.webui, true],
    ]),
    menu: () => [{ text: T('where.copy_name'), act: () => Office.copy(c.name) }],
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
    const meta = [p.dir, p.autostart ? T('where.autostart') : null, p.env ? '.env' : null, T('where.container_count', { n: p.containers.length })].filter(Boolean).join(' · ');
    const rows = members.map(containerRow);
    if (!members.length) rows.push(emptyNote(T('where.compose_down')));
    box.appendChild(group(`🧩 ${p.name}`, meta, rows, { chips: [chip('Compose', 'accent')] }));
  }
  for (const [project, members] of byProject) box.appendChild(group(`🧩 ${project}`, T('where.compose_unknown'), members.map(containerRow), { chips: [chip('Compose', 'quiet')] }));
  if (templated.length) box.appendChild(group(T('where.templates_group'), T('where.container_count', { n: templated.length }), templated.map(containerRow)));
  if (other.length) box.appendChild(group(T('where.other_group'), T('where.container_count', { n: other.length }), other.map(containerRow)));
  const lonely = state.templates.filter((x) => !x.container && matches(join(x.name, x.image, x.paths)));
  if (lonely.length) {
    box.appendChild(group(T('where.lonely_group'), T('where.lonely_meta'), lonely.map((x) => row({
      key: 'template:' + x.file,
      name: x.name,
      meta: [el('span', 'mono', x.image || ''), chip(T('where.no_container'), 'warn')],
      detail: () => kv([[T('where.template'), x.file, true], [T('where.paths'), x.paths.length ? lines(x.paths) : null]]),
    })), { closed: false }));
  }
  if (!box.childNodes.length) box.appendChild(emptyNote(T('where.nothing_found')));
  body.appendChild(box);
}

// --------------------------------------------------------------- VMs
const vmMatches = (v) => matches(join(v.name, v.state, v.os, v.template, v.uuid, (v.disks || []).map((d) => d.source)));

function vms(body) {
  const box = el('div', 'box');
  const list = state.vms.filter(vmMatches);
  if (!list.length) box.appendChild(emptyNote(state.vms.length ? T('where.nothing_found') : T('where.no_vms')));
  for (const v of list) {
    const snapChip = v.snapshots ? chip(`📸 ${T('where.vm_snapshots', { n: v.snapshots })}`, 'accent') : null;
    if (snapChip) { snapChip.style.cursor = 'pointer'; snapChip.dataset.own = '1'; snapChip.title = T('where.tip.vm_snapshot'); snapChip.onclick = () => Office.go('#/snapshot'); }
    const os = v.os === 'windows' ? '🪟 Windows' : v.os === 'linux' ? '🐧 Linux' : null;
    box.appendChild(row({
      key: 'vm:' + v.name,
      name: v.name,
      meta: [
        chip(v.running ? T('where.state.running') : v.state, v.running ? 'ok' : 'quiet'),
        os ? chip(v.template || os, 'quiet', T('where.tip.os', { os: v.template || os })) : null,
        v.firmware === 'uefi' ? chip('UEFI', 'quiet', T('where.tip.uefi', { nvram: v.nvram || '?' })) : null,
        v.tpm ? chip('TPM ' + (v.tpm.version || ''), 'quiet', T('where.tip.tpm', { version: v.tpm.version || '', state: v.tpm.state || T('where.vm.tpm_none') })) : null,
        v.passthrough ? chip(T('where.vm.passthrough_n', { n: v.passthrough }), 'quiet', T('where.tip.passthrough')) : null,
        v.autostart ? chip(T('where.autostart'), 'quiet', T('where.tip.autostart_vm')) : null,
        v.cpus ? el('span', '', T('where.vm_cpus', { n: v.cpus })) : null,
        v.memory ? el('span', '', fmt.size(v.memory)) : null,
        snapChip,
      ],
      detail: () => vmDetail(v),
    }));
  }
  body.appendChild(box);
}

function vmDetail(v) {
  const box = el('div');
  const fw = v.firmware === 'uefi' ? `UEFI (OVMF)${v.secure_boot ? ' · Secure Boot' : ''}` : v.firmware === 'bios' ? 'BIOS (SeaBIOS)' : v.loader;
  box.appendChild(kv([
    [T('where.vm.os'), v.template || (v.os === 'windows' ? 'Windows' : v.os === 'linux' ? 'Linux' : v.os)],
    [T('where.vm.firmware'), fw],
    [T('where.vm.tpm'), v.tpm ? `${v.tpm.model || 'TPM'} ${v.tpm.version || ''}` : T('where.vm.no_tpm')],
    [T('where.vm.machine'), v.machine, true],
    ['UUID', v.uuid, true],
    [T('where.vm.cpu_mem'), [v.cpus ? T('where.vm_cpus', { n: v.cpus }) : null, v.memory ? fmt.size(v.memory) : null].filter(Boolean).join(' · ')],
    [T('where.vm.networks'), (v.networks || []).length ? lines(v.networks.map((n) => `${n.source || '?'} · ${n.model || ''} · ${n.mac || ''}`)) : null],
    [T('where.vm.passthrough'), v.passthrough ? T('where.vm.passthrough_n', { n: v.passthrough }) : null],
    [T('where.vm.graphics'), v.graphics],
    [T('where.vm.description'), v.description],
  ]));
  box.appendChild(el('div', 'clw-sub', T('where.vm.files')));
  const files = el('div', 'clw-paths');
  if (v.xml) files.appendChild(pathLine(T('where.vm.xml'), v.xml, { backup: v.config_backup }));
  if (v.nvram) files.appendChild(pathLine(T('where.vm.nvram'), v.nvram, { backup: v.config_backup, note: v.nvram_copies ? T('where.vm.nvram_copies', { n: v.nvram_copies }) : '' }));
  if (v.tpm) files.appendChild(pathLine(T('where.vm.tpm_state'), v.tpm.state || T('where.vm.tpm_none'), { backup: v.tpm.state ? v.config_backup : null, missing: !v.tpm.state }));
  // a sparse disk file far bigger than what it holds says so (waFileSizes(): bytes = what it is, allocated = what it takes)
  const sparseNote = (f) => (f.sparse ? T('where.vm.sparse', { used: fmt.size(f.allocated || 0), virtual: fmt.size(f.bytes) }) : '');
  (v.disks || []).forEach((d) => {
    const chain = d.chain || [];
    files.appendChild(pathLine(d.device === 'cdrom' ? T('where.vm.iso', { target: d.target || '' }) : T('where.vm.disk', { target: d.target || '', bus: d.bus || '', format: d.format || '' }),
      d.source, { backup: d.backup, figure: d.bytes ? fmt.size(d.bytes) : '',
        note: [chain.length ? T('where.vm.overlay', { n: chain.length }) : '', sparseNote(d)].filter(Boolean).join(' ') }));
    chain.forEach((c, i) => files.appendChild(pathLine(T('where.vm.base', { n: i + 1 }), c.path,
      { backup: c.backup, figure: c.bytes ? fmt.size(c.bytes) : '', missing: !c.exists, note: sparseNote(c) })));
  });
  box.appendChild(files);
  const advice = [T('where.vm.move_hint')];
  if (v.tpm) advice.push(T('where.vm.tpm_hint'));
  if (v.config_backup && v.config_backup !== 'offsite') advice.push(T('where.vm.config_' + v.config_backup));
  box.appendChild(el('p', 'callout' + (v.config_backup && v.config_backup !== 'offsite' ? ' warn' : ''), advice.join(' ')));
  return box;
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
  hr.appendChild(el('th', '', T('where.user')));
  exported.forEach((s) => { const th = el('th', '', s.name); th.title = T('where.security.' + s.smb.security); hr.appendChild(th); });
  thead.appendChild(hr);
  const tbody = el('tbody');
  for (const u of users) {
    const tr = el('tr');
    const th = el('th', '', u.name);
    th.title = `uid ${u.uid}${u.description ? ' · ' + u.description : ''}${u.smb ? '' : ' · ' + T('where.no_smb_password')}`;
    tr.appendChild(th);
    for (const s of exported) {
      const l = level(u, s);
      const td = el('td', 'c');
      td.appendChild(l === 'rw' ? chip(T('where.access.rw'), 'ok') : l === 'r' ? chip(T('where.access.r'), 'accent') : el('span', 'hint', '–'));
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }
  table.append(thead, tbody);
  wrap.appendChild(table);
  if (!users.length) wrap.appendChild(emptyNote(T('where.nothing_found')));
  body.appendChild(wrap);
  const notExported = state.shares.filter((s) => !s.smb.export || s.smb.export === '-').map((s) => s.name);
  body.appendChild(el('p', 'hint', T('where.access_legend')));
  if (notExported.length) body.appendChild(el('p', 'hint', T('where.not_exported_list', { names: notExported.join(', ') })));
  watchmanSees(body);
}

/** A plain directory here; what changes in it (new users, shares opened, who pulls how much) the night watchman sees — when he works here */
function watchmanSees(body) {
  if (!watchmanHired()) return;
  const p = el('p', 'hint');
  const a = el('a', '', T('where.watchman_sees_link'));
  a.href = '#/watchman';
  p.append(T('where.watchman_sees'), ' ', a);
  body.appendChild(p);
}

// --------------------------------------------------------------- network shares
function network(body) {
  const smb = state.smb;
  const box = el('div', 'box');
  const sessions = smb.sessions.filter((s) => matches(join(s.user, s.machine, s.shares.map((x) => x.share))));
  const rows = sessions.map((s) => row({
    key: 'session:' + s.user + s.machine + s.since,
    name: `${s.user} @ ${s.machine}`,
    meta: [el('span', '', T('where.since', { when: fmt.relative(s.since) })), s.dialect ? chip(s.dialect, 'quiet') : null,
      ...s.shares.map((x) => chip(x.share, 'accent', T('where.since', { when: fmt.date(x.since) })))],
  }));
  if (!rows.length) rows.push(emptyNote(T('where.no_sessions')));
  box.appendChild(group(T('where.smb_sessions'), T('where.session_count', { n: smb.sessions.length }), rows));

  const settings = el('div', 'row-detail');
  settings.style.padding = '8px 12px';
  settings.appendChild(kv([
    ['SMB', smb.enabled ? T('where.enabled') : T('where.disabled')],
    [T('where.workgroup'), smb.workgroup],
    [T('where.security_mode'), smb.security],
    ['macOS (fruit)', smb.fruit ? T('where.enabled') : T('where.disabled')],
    ['NetBIOS', smb.netbios ? T('where.enabled') : T('where.disabled')],
    ['WSD', smb.wsd ? T('where.enabled') : T('where.disabled')],
    [T('where.custom_shares'), smb.custom.length ? lines(smb.custom.map((c) => typeof c === 'object' ? JSON.stringify(c) : String(c))) : null],
    ['smb-extra.conf', smb.extra ? el('pre', 'code', smb.extra) : T('where.empty_file')],
    ['NFS', state.nfs.enabled ? T('where.enabled') : T('where.disabled')],
    [T('where.nfs_exports'), state.nfs.exports.length ? lines(state.nfs.exports) : null],
  ]));
  box.appendChild(group(T('where.settings'), T('where.settings_meta'), [settings]));
  body.appendChild(box);
  watchmanSees(body);
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
      s.running ? chip(T('where.running_now'), 'ok') : null,
      s.stale ? chip(T('where.stale'), 'warn', T('where.stale_title')) : null,
      s.missing ? chip(T('where.dead_refs', { n: s.missing }), 'danger', T('where.dead_refs_title')) : null,
      s.description ? el('span', 'note', s.description) : null,
      s.last_run ? el('span', '', T('where.last_run', { when: fmt.relative(s.last_run) })) : null,
    ],
    detail: () => {
      const d = el('div');
      d.appendChild(kv([
        [T('where.file'), s.file, true],
        [T('where.schedule'), `${scheduleText(s.frequency, s.cron)}${s.cron ? ` (${s.cron})` : ''}`],
        [T('where.refs'), s.refs.length ? lines(s.refs.map((r) => `${r.exists === false ? '✗ ' : r.exists ? '✓ ' : '· '}${r.path}`)) : null],
      ]));
      const pre = el('pre', 'code', s.text + (s.cut ? '\n…' : ''));
      pre.style.maxHeight = '320px';
      pre.style.marginTop = '6px';
      d.appendChild(pre);
      return d;
    },
    menu: () => [{ text: Office.t('common.copy_path'), act: () => Office.copy(s.file) }],
  }));
  if (!rows.length) rows.push(emptyNote(T('where.nothing_found')));
  box.appendChild(group(T('where.user_scripts'), T('where.script_count', { n: state.scripts.length }), rows));

  const cron = state.cron.filter((c) => matches(join(c.title, c.schedule, c.command, c.source)));
  const cronRows = cron.map((c, i) => row({
    key: 'cron:' + i + c.command,
    name: c.title || c.command,
    mono: !c.title,
    meta: [chip(fmt.cron(c.schedule), 'accent', c.schedule), c.gone ? chip(T('where.cron_gone'), 'danger', T('where.cron_gone_title', { plugin: c.gone })) : null,
      el('span', 'mono', c.source)],
    detail: () => kv([[T('where.schedule'), `${fmt.cron(c.schedule)} (${c.schedule})`], [T('where.command'), c.command, true], [T('where.source'), c.source, true]]),
  }));
  if (!cronRows.length) cronRows.push(emptyNote(T('where.nothing_found')));
  box.appendChild(group(T('where.cron_jobs'), T('where.cron_meta'), cronRows, { closed: !query }));
  body.appendChild(box);
}

// --------------------------------------------------------------- backups
function backups(body) {
  const box = el('div', 'box');
  const list = state.backups.filter((b) => matches(join(b.kind, b.title, b.paths)));
  if (!list.length) box.appendChild(emptyNote(T('where.nothing_found')));
  for (const b of list) {
    const isSnap = b.kind === 'snapshots';
    box.appendChild(row({
      key: 'backup:' + b.kind + b.title,
      name: isSnap ? T('where.backup.snapshots_title') : b.title,
      meta: [
        chip(T('where.backup.' + b.kind), b.kind === 'share' || b.kind === 'script' ? 'quiet' : 'accent'),
        b.detail && b.detail.running ? chip(T('where.running_now'), 'ok') : null,
        isSnap ? el('span', '', T('where.backup.snapshots_count', { n: b.detail.count })) : null,
        ...b.paths.slice(0, 3).map((p) => el('span', 'mono', p)),
        b.paths.length > 3 ? chip(`+${b.paths.length - 3}`, 'quiet') : null,
      ],
      detail: isSnap ? null : () => kv([
        [T('where.paths'), b.paths.length ? lines(b.paths) : null],
        ...Object.entries(b.detail || {}).map(([k, v]) => [T('where.detail.' + k) !== `${ID}.where.detail.${k}` ? T('where.detail.' + k) : k,
          typeof v === 'boolean' ? (v ? Office.t('common.yes') : Office.t('common.no')) : k === 'since' ? fmt.date(v) : String(v)]),
      ]),
      menu: isSnap ? () => [{ text: T('where.to_snapshot'), act: () => Office.go('#/snapshot') }] : null,
    }));
  }
  body.appendChild(box);
  const pa = state.partners;
  if (pa && (pa.places || []).length) body.appendChild(partnerPlaces(pa));
  body.appendChild(el('p', 'hint', T('where.backups_note')));
}

/** The partners' places: per pool what each partner's copies take there (a partnership ended: a leftover of «Tidying up») */
function partnerPlaces(pa) {
  const rows = pa.places.filter((x) => matches(join(x.dataset, x.pairs.map((p) => [p.name, p.id, p.units])))).map((x) => row({
    key: 'partners:' + x.pool,
    name: x.dataset,
    mono: true,
    meta: [
      ...x.pairs.slice(0, 4).map((p) => chip(`${p.name || p.id} · ${fmt.size(p.used)}`, p.gone ? 'warn' : 'quiet',
        p.gone ? T('where.partners.gone_text', { id: p.id }) : T('where.partners.pair_text', { name: p.name || p.id, n: p.units.length }))),
      x.pairs.length > 4 ? chip(`+${x.pairs.length - 4}`, 'quiet') : null,
      x.stored ? chip(T('where.partners.stored', { n: x.stored }), 'quiet', T('where.partners.stored_text')) : null,
    ],
    figures: sizeFigures({ bytes: x.used, how: 'zfs' }),
    detail: () => kv(x.pairs.map((p) => [p.name || p.id, `${fmt.size(p.used)} · ${p.units.join(', ') || '–'}${p.gone ? ' · ' + T('where.partners.gone') : ''}`])),
    menu: () => [{ text: T('where.partners.to_lead'), act: () => Office.go('#/caretaker') }],
  }));
  const meta = T('where.partners.sum', { n: pa.places.reduce((a, x) => a + x.pairs.length, 0) })
    + ((pa.asleep || []).length ? ' · ' + T('where.partners.asleep', { pools: pa.asleep.join(', ') }) : '');
  const box = el('div', 'box');
  box.appendChild(group(T('where.partners.title'), meta, rows));
  return box;
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
  if (p.key === 'attribute') return T('where.smart.attribute', { id: p.id, name: T('where.attr.' + p.id) !== `${ID}.where.attr.${p.id}` ? T('where.attr.' + p.id) : p.name, raw: p.raw });
  if (p.key === 'failing') return T('where.smart.failing', { id: p.id, name: p.name });
  return T('where.smart.' + p.key, { raw: p.raw });
}

function diskRow(d) {
  const tl = tempLevel(d);
  const fl = fillLevel(d);
  const bad = smartBad(d);
  const notes = smartNotices(d);
  const hours = d.smart && d.smart.hours;
  const figures = el('div', 'figures');
  if (d.temp !== null && d.temp !== undefined) {
    figures.append(el('b', '', `${d.temp} °C`), el('span', '', T('where.temp_limit', { hot: d.hot })));
    figures.title = T('where.temp_title', { hot: d.hot, max: d.max });
  } else if (d.asleep) {
    figures.append(el('b', '', '💤'), el('span', '', T('where.asleep')));
  }
  return row({
    key: 'disk:' + d.device,
    name: d.name + (d.roles.length ? ` · ${d.roles.join(', ')}` : ''),
    meta: [
      chip(diskKind(d), 'quiet'),
      d.status && d.status !== 'DISK_OK' ? chip(d.status, 'danger') : null,
      tl ? chip(`🌡 ${d.temp} °C`, tl, T('where.temp_title', { hot: d.hot, max: d.max })) : null,
      d.errors ? chip(T('where.read_errors', { n: d.errors }), 'danger') : null,
      d.fill !== null && d.fill !== undefined ? chip(T('where.fill', { p: fmt.number(d.fill) }), fl || 'quiet', T('where.fill_title', { warn: d.warn, crit: d.crit })) : null,
      ...bad.map((p) => chip(problemText(p), 'danger')),
      ...notes.slice(0, 2).map((p) => chip(problemText(p), 'warn', T('where.smart.notice_title'))),
      notes.length > 2 ? chip(`+${notes.length - 2}`, 'quiet') : null,
      hours ? el('span', '', T('where.hours', { y: fmt.number(hours / 8766, 1) })) : null,
      el('span', 'mono', d.id || d.device),
    ],
    figures,
    detail: () => {
      const box = el('div');
      const nv = d.smart && d.smart.nvme;
      box.appendChild(kv([
        [T('where.device'), `/dev/${d.device} (${d.transport || '?'})`, true],
        [T('where.model'), d.id, true],
        [T('where.disk_role'), [d.type, ...d.roles].join(', ')],
        [T('where.temp'), d.temp !== null && d.temp !== undefined ? `${d.temp} °C · ${T('where.temp_title', { hot: d.hot, max: d.max })}` : (d.asleep ? T('where.asleep') : '–')],
        [T('where.fill_label'), d.fill !== null && d.fill !== undefined ? `${fmt.number(d.fill)} % · ${T('where.fill_title', { warn: d.warn, crit: d.crit })}` : null],
        [T('where.power_on'), hours ? `${fmt.number(hours)} h (${T('where.hours', { y: fmt.number(hours / 8766, 1) })})` : null],
        [T('where.smart_read'), d.smart && d.smart.read ? fmt.date(d.smart.read) + ' · ' + fmt.relative(d.smart.read) : T('where.smart_none')],
        [T('where.smart_findings'), d.smart && d.smart.problems.length ? lines(d.smart.problems.map(problemText)) : (d.smart ? T('where.smart_clean') : null)],
        ['NVMe', nv && nv.used !== undefined ? T('where.nvme_line', { used: nv.used, spare: nv.spare, media: nv.media_errors, unsafe: nv.unsafe_shutdowns, written: nv.written || '?' }) : null],
      ]));
      if (d.smart && d.smart.attributes.length) {
        const pre = el('pre', 'code', d.smart.attributes.map((a) => `${String(a.id).padStart(3)} ${a.name.padEnd(26)} ${String(a.value).padStart(3)} ${String(a.worst).padStart(3)} ${String(a.thresh).padStart(3)} ${a.failed || '-'}  ${a.raw}`).join('\n'));
        pre.style.marginTop = '6px';
        pre.style.maxHeight = '260px';
        box.appendChild(pre);
      }
      return box;
    },
    menu: () => [{ text: T('where.copy_id'), act: () => Office.copy(d.id || d.device) }],
  });
}

function disks(body) {
  const h = state.health;
  const box = el('div', 'box');
  if (!h) { box.appendChild(emptyNote(T('where.nothing_found'))); body.appendChild(box); return; }
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
    const meta = [T('where.disk_count', { n: items.length }), warm ? T('where.warm_count', { n: warm }) : null].filter(Boolean).join(' · ');
    box.appendChild(group(T('where.type.' + key), meta, items.map(diskRow)));
  }
  if (!list.length) box.appendChild(emptyNote(T('where.nothing_found')));
  body.appendChild(box);

  // parity and license
  const lic = state.license || {};
  const info = el('div', 'box');
  const d = el('div', 'row-detail');
  d.style.padding = '8px 12px';
  d.appendChild(kv([
    [T('where.parity'), h.parity.slots ? (h.parity.present ? T('where.parity_present', { n: h.parity.present, slots: h.parity.slots }) : el('span', 'clw-bad', T('where.parity_none'))) : null],
    [T('where.parity_checked'), h.parity.checked ? `${fmt.date(h.parity.checked)} · ${T('where.parity_errors', { n: h.parity.errors })}` : (h.parity.present ? T('where.parity_never') : null)],
    ...(h.pools || []).map((p) => [T('where.pool_label', { name: p.name }), poolProtection(p)]),
    [T('where.license'), lic.type ? `${lic.type}${lic.to ? ' · ' + lic.to : ''}` : null],
    [T('where.license_since'), lic.since ? fmt.date(lic.since) : null],
    [T('where.license_expires'), lic.expires ? `${fmt.date(lic.expires)} · ${fmt.relative(lic.expires)}` : (lic.type ? T('where.license_forever') : null)],
    [T('where.license_devices'), lic.limit ? T('where.devices_of', { n: lic.devices, limit: lic.limit }) : T('where.devices_unlimited', { n: lic.devices })],
    [T('where.license_guid.' + (lic.bound || 'flash')), lic.guid, true],
    [T('where.license_check'), lic.check],
    [T('where.limits'), T('where.limits_text', { hh: h.limits.hdd_hot, hm: h.limits.hdd_max, sh: h.limits.ssd_hot, sm: h.limits.ssd_max, w: h.limits.warning, c: h.limits.critical })],
  ]));
  info.appendChild(group(T('where.array_license'), T('where.array_license_meta'), [d]));
  body.appendChild(info);
  body.appendChild(el('p', 'hint', T('where.disks_note')));
}

/** "mirror · 2 devices — 1 may fail", or red: no redundancy */
function poolProtection(p) {
  const what = `${p.fs === 'btrfs' ? 'btrfs ' : ''}${p.layout} · ${T('where.devices_n', { n: p.devices })}`;
  if (p.tolerates > 0) return `${what} — ${T('where.pool_tolerates', { n: p.tolerates })}`;
  return el('span', 'clw-bad', `${what} — ${T('where.pool_unprotected')}`);
}

// --------------------------------------------------------------- Unraid notifications
function notices(body) {
  const box = el('div', 'box');
  const list = (state.notices || []).filter((n) => matches(join(n.subject, n.description, n.event, n.importance)));
  if (!list.length) box.appendChild(emptyNote((state.notices || []).length ? T('where.nothing_found') : T('where.no_notices')));
  for (const n of list) {
    const cls = n.importance === 'alert' ? 'danger' : n.importance === 'warning' ? 'warn' : 'quiet';
    box.appendChild(row({
      key: 'notice:' + n.time + n.subject,
      name: n.subject,
      meta: [chip(T('where.importance.' + n.importance) !== `${ID}.where.importance.${n.importance}` ? T('where.importance.' + n.importance) : n.importance, cls),
        n.time ? el('span', '', `${fmt.date(n.time)} · ${fmt.relative(n.time)}`) : null,
        n.description ? el('span', 'note', n.description) : null],
    }));
  }
  body.appendChild(box);
  body.appendChild(el('p', 'hint', T('where.notices_note')));
}

// --------------------------------------------------------------- plugins
function plugins(body) {
  const box = el('div', 'box');
  const list = state.plugins.filter((p) => matches(join(p.name, p.version, p.author)));
  if (!list.length) box.appendChild(emptyNote(T('where.nothing_found')));
  for (const p of list) {
    box.appendChild(row({
      key: 'plugin:' + p.file,
      name: p.name,
      meta: [p.version ? chip(p.version, 'quiet') : null, p.author ? el('span', '', p.author) : null, el('span', 'mono', p.file)],
    }));
  }
  body.appendChild(box);
}

return {
  build, load, render, tour, bubble, reception, helpItems, sleeping,
  /** the filter changed: her tiles' counts and the open tile anew */
  filtered() { if (view) { renderTabs(); renderSection(); } },
  has: () => !!state,
  setHooks(h) { hooks = { ...hooks, ...h }; },
  unmount() { view = null; clearTimeout(sizeTimer); },
};
})();
})();
