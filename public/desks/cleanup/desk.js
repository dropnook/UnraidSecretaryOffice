/* Ms. Dustdevil — knows every corner of the server, and clears away what nobody uses any more.
   Her desk has two pages, two tabs under the bubble (tiles v2): «Where is what» (the block `Where` at the end, up to
   1.30 Ms. Whereabouts' own desk): her advice, where things are and every corner in detail, the live numbers on the
   corners' tiles — read only. «Tidying up»: her rooms, Docker templates
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
/** «{n} <noun>» from the lang key count.<what> — its one/other forms (several counts in one text are composed of these) */
const nOf = (what, n) => T('count.' + what, { n: Number(n) || 0 });
const { el, fmt } = Office;
const SECTIONS = ['templates', 'stacks', 'appdata', 'vms', 'scripts', 'flash', 'docker', 'icons', 'leftovers', 'partners', 'trash'];
const ICONS = { templates: '📄', stacks: '🧩', appdata: '🗃️', vms: '🖥️', scripts: '📜', flash: '💾', docker: '🐳', icons: '🖼️', leftovers: '📦', partners: '🤝', trash: '🗑️' };
const GROUPS = {
  templates: ['leftover', 'unused', 'duplicate', 'noname', 'stray_only_here', 'stray_newer', 'stray_name_exists', 'stray_older', 'stray_copy', 'unknown', 'in_use'],
  stacks: ['leftover', 'broken', 'unused', 'unknown', 'in_use'],
  appdata: ['unused', 'check', 'unknown', 'used'],
  vms: ['broken', 'orphan', 'unused', 'check', 'media', 'unknown', 'used'],
  scripts: ['broken', 'dead', 'idle', 'used'],
  flash: ['stale', 'unknown', 'used'],     // share settings: whose share is gone, can't tell (array stopped), Unraid knows the share
  docker: ['drill', 'dangling', 'volume', 'unused', 'cache', 'used'],
  icons: ['template', 'compose', 'stack', 'none', 'ok'],
  leftovers: ['leftover', 'way_back', 'pulled', 'unknown'],     // shown per restore (renderLeftovers), these for the CSV; pulled: his to remove
  partners: ['leftover', 'dropped'],
};
const CANDIDATES = {
  templates: ['leftover', 'unused', 'duplicate', 'noname', 'stray_only_here', 'stray_newer', 'stray_name_exists', 'stray_older', 'stray_copy'],
  stacks: ['leftover', 'broken', 'unused'],
  appdata: ['unused', 'check'],
  vms: ['broken', 'orphan', 'unused', 'check', 'media'],
  scripts: ['broken', 'dead', 'idle'],
  flash: ['stale'],
  docker: ['drill', 'dangling', 'volume', 'unused', 'cache'],
  icons: ['template', 'compose', 'stack', 'none'],
  leftovers: ['leftover', 'way_back'],
  partners: ['leftover', 'dropped'],
};
const CLOSED = ['in_use', 'used', 'unknown', 'ok'];  // folded until opened
const KIND_ICONS = { container: '🐳', template: '📄', stack: '🧩', compose: '🧩', flash: '💾', vm: '🖥️' };
// her rooms whose datasets go into the storeroom only after the preview (the agent's CL_ZFS_CONFIRM)
const ZFS_CONFIRM = ['appdata', 'domain'];
const ITEM_ICONS = { template: '📄', stray: '📄', vmdef: '🖥️', userscript: '📜', stack: '🧩', appdata: '🗃️', domain: '🖥️', iso: '💿', nvram: '🔐', tpm: '🔐', snapshotdb: '🔐', icon: '🖼️', leftover: '📦', partner: '🤝', package: '💾', drill: '🧪', volume: '🗄️', sharecfg: '⚙️' };
const ROOMS = ['templates', 'stacks', 'appdata', 'vms', 'scripts', 'flash', 'docker', 'icons', 'leftovers', 'partners'];     // where she finds something (not the storeroom)
const POLL_MS = 3000;
const ROOM_ITEMS = 120;     // the search's items from her rooms (WHERE_ITEMS come from «Where is what», a part of its own)
const WHERE_ITEMS = 200;    // «Where is what» is a part of its own: its own 200 in the search (2026-10-10: her field went)

let state = null;
let lookedAgain = false;      // looked again this visit because Mr. Restori finished something since her last look
let view = null;
let folded = Office.storeJson('cleanup.folded') || {};
const selection = new Set();
let selRoom = '';           // the room the selection lies in: she puts away one room's things at a time
const expanded = new Set();
const picks = new Map();    // container picture: id => the address chosen here (else her suggestion)
let shown = [];             // rows that can unfold: { open(), set(bool) }
let busy = false;
let timer = null;
// the bar over her rooms (core.js Office.filterBar, built once): its words and «only what she may clear away» filter the
// open room — never kept (unmount() empties them); her findings' links fill its words as they fill those of «Where is what»
let tidyBar = null;

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
  const ok = new Set(entries(selRoom).filter(selectable).map((e) => e.id));
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
function entries(sec, s = state) {
  if (!s) return [];
  return ({
    templates: s.templates.list, stacks: s.stacks.list, appdata: s.appdata.list,
    vms: s.vms.list, scripts: s.scripts.list, docker: s.docker.list, icons: (s.icons || {}).list,
    leftovers: (s.leftovers || {}).list, partners: (s.partners || {}).list, flash: (s.flash || {}).list,
  })[sec] || [];
}
const candidates = (sec) => entries(sec).filter((e) => CANDIDATES[sec].includes(e.category));
const removable = (e) => ['image', 'volume', 'cache', 'drill'].includes(e.kind);
/** The picture a container would get: the one chosen here, else the first one found that loads */
const iconChoice = (e) => picks.get(e.id) || e.suggest || null;
/**
 * What something still needs can't be chosen at all (2026-10-12; the agent's CL_IN_USE_LOCKED): a template whose
 * container exists, an appdata folder, a VM's folder or disk image in use — and Docker's leftovers in use. Only a stack
 * in use («let go») and Mr. Restori's way back keep the warning and «anyway».
 */
const IN_USE_LOCKED = ['template', 'appdata', 'domain', 'iso'];
const inUseLocked = (e) => !!e.force && IN_USE_LOCKED.includes(e.kind);
/** Why a row in use can't be chosen, and what to do: delete its user first — then it lies in the group that can go */
const lockedText = (e) => T('in_use_locked.' + e.kind, e.kind === 'template' ? { unused: T('cat.templates.unused'), leftover: T('cat.templates.leftover') }
  : { group: T(e.kind === 'appdata' ? 'cat.appdata.unused' : e.kind === 'iso' ? 'cat.vms.media' : 'cat.vms.unused') });
/** Docker's leftovers in use and what IN_USE_LOCKED names can't be chosen at all; a container only with a picture to hang */
const selectable = (e) => !!state && e.why === null && !state.backup_running && !(removable(e) && e.category === 'used') && !inUseLocked(e)
  && !(e.kind === 'icon' && (e.category === 'ok' || e.category === 'none' || !iconChoice(e)));
const isStackIcon = (e) => e.kind === 'icon' && e.target === 'stack';
const label = (e) => (e.kind === 'template' || e.kind === 'stray' || e.kind === 'sharecfg' ? e.file : e.kind === 'userscript' ? e.name : e.kind === 'stack' ? e.folder : e.kind === 'cache' ? T('cache.name')
  : isStackIcon(e) ? T('pic.stack_name', { name: e.name })
  : e.kind === 'partner' && e.unit ? T('pa.dropped_name', { unit: e.unit, name: e.pair_name || e.name })
  : e.kind === 'drill' && e.what !== 'container' ? T('dl.name_' + (e.what === 'kopia_tmp' ? 'kopia_tmp' : 'kopia_dump'), { name: e.name.split('/').pop(), container: e.container || 'Kopia' }) : e.name);
const sum = (list) => list.reduce((a, e) => a + (e.bytes || 0), 0);
/** The bar's words (folded) */
const words = () => (tidyBar ? tidyBar.read().words : []);
/** Does an entry match the filter's words? Its name and what it is connected to (image, containers, stack, paths) */
function matches(e) {
  const w = words();
  if (!w.length) return true;
  return Office.fits(w, [label(e), e.name, e.file, e.folder, e.image, e.project, e.uuid, e.from, e.path, e.restore && e.restore.what, ...(e.refs || []),
    ...(e.used_by || []).map((u) => u.name), ...(e.containers || []).map((c) => c.name), ...(e.parts || []).map((p) => p.path),
    e.dataset, e.pair_name, ...(e.units || [])].filter((x) => typeof x === 'string' || typeof x === 'number').join(' '));
}
/** …and «only what she may clear away» */
const fitsRoom = (e) => matches(e) && (!(tidyBar && tidyBar.read().only) || selectable(e));
/** VMs only with the VM service switched on, Docker's rooms (appdata too: who uses it is told by Docker) only with Docker */
const visible = (sec, s = state) => (sec === 'vms' ? s.vms.enabled : sec === 'scripts' ? s.scripts.installed
  : sec === 'leftovers' ? !!s.leftovers && s.leftovers.restores > 0
  : sec === 'partners' ? !!s.partners && s.partners.list.length > 0
  : sec === 'flash' ? !!s.flash && s.flash.list.length > 0      // an older agent's state has no room «flash»
  : sec === 'icons' ? s.docker.enabled && !!s.icons : ['templates', 'stacks', 'appdata', 'docker'].includes(sec) ? s.docker.enabled : true);

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
/* Her two pages (tiles v2, CLAUDE.md «Tiles»: two tabs = two pages) — «Where is what» (#/cleanup/where, the block
   `Where` at the end) and «Tidying up» (#/cleanup/tidy): tabs under the bubble, #/cleanup opens the one seen last
   (`cleanup.page`, the first visit «Where is what»). One bubble for both, the head's «Tour» and «wake sleeping disks»
   on both; each page has its own row of tiles (the section switcher), its own state, its own bar. */
const PAGES = () => [{ id: 'where', text: T('part.where') }, { id: 'tidy', text: T('part.tidy') }];
const PAGE_KEY = 'cleanup.page';
let pendingWords = null;      // a finding's words, carried over to the other page (Where.pick from «Tidying up»)

Office.desk({
  id: ID,

  /** sub: «where» or «tidy» (#/cleanup/where — also where Ms. Whereabouts' old addresses lead), none: the page seen
      last; «tidy/<room>», «where/<corner>» (the search's places, her findings' links; «where/stat:<tile>» from before
      tiles v2 is that tile's corner): that tile open, as a click opens it */
  mount(root, sub) {
    const s = String(sub || '');
    const page = Office.pageFrom(PAGES(), s, PAGE_KEY);
    const tile = s.split('/')[0] === page ? s.split('/').slice(1).join('/') : '';
    // A tile's sub-route is the search's way in (her tiles, her rooms' items, «Where is what»'s — every hit on her page
    // comes through one; Office.goToPlace() mounts anew even on the same address): the page's filter could hide the row
    // it goes to («not there»), so it starts empty — here and not in a hook of Office.reveal(), which knows no desk's
    // filter. A tile clicked on the page never comes this way: its filter stays.
    if (tile) { roomBar().set({ text: '', only: false }); Where.setWords(''); }
    if (pendingWords !== null) { roomBar().set({ text: pendingWords }); Where.setWords(pendingWords); pendingWords = null; }
    view = build(root, page);
    lookedAgain = false;
    if (page === 'tidy' ? tidySw.mount(tile) : Where.mount(tile)) Office.subroute(page);   // the address keeps its page
    render();
    Where.render();          // what she knew from before at once; her parts render themselves when they load (never each other)
    load(false);
    Where.load(false);       // both: the bubble speaks of both
  },

  unmount() {
    view = null;
    clearTimeout(timer);
    selection.clear();
    if (tidyBar) tidyBar.reset();        // the visit ends: the filter's words and its switch go (never kept)
    Where.unmount();
  },

  poll() { load(false); Where.load(false); },

  agentChanged() { if (view) render(); },
  greetOk: (key) => greetOk(key),      // defined further down (her bubble's facts)

  menu() {
    return [{ text: T('scan'), act: () => tour(), disabled: !Office.agent.running || busy }];
  },

  async reception() {
    if (!state) await load(false);
    if (!Where.has()) await Where.load(false);
    if (!state) return { bubble: T('bubble.no_data'), facts: [] };
    const facts = [];
    for (const [key, c] of roomCounts()) facts.push(T('fact.' + key, { n: c.length, size: fmt.size(sum(c)) }));
    if (state.trash.runs.length) facts.push(T('fact.trash', { size: state.trash.bytes === null ? '…' : fmt.size(state.trash.bytes) }));
    const w = Where.reception();
    const bubble = [w.summary, bubbleText(), w.findings ? T('where.bubble.findings', { n: w.findings }) : ''].filter(Boolean).join(' ');
    return { bubble, facts: [...facts, ...w.facts] };
  },
});

// ------------------------------------------------------------------ building
function build(root, page) {
  const v = { page };
  v.scanBtn = el('button', 'btn plain');
  v.scanBtn.type = 'button';
  v.scanBtn.append(el('span', 'spin'), T('scan'));
  v.scanBtn.title = T('scan_title');
  v.scanBtn.onclick = () => tour();
  // the office's search finds her rooms' items and «Where is what»; each page has a filter bar of its own (the rooms',
  // the corners' — her findings' links fill both)
  // wake the sleeping disks for this tour — off unless switched on, never remembered
  v.wakeLabel = el('label', 'switch clw-wake');
  v.wake = el('input');
  v.wake.type = 'checkbox';
  v.wakeText = el('span', '', T('where.wake'));
  v.wakeLabel.append(v.wake, v.wakeText);
  v.wakeLabel.title = T('where.wake_title');
  // switched on: said right under it that it holds for the next tour only, with that tour one click away (2026-10-17,
  // silent controls: a switch that changed nothing until «Tour» and was off again afterwards, said only in its tip)
  const wakeNote = el('span', 'clw-wake-note');
  const wakeGo = el('button', 'btn small', T('where.scan_now'));
  wakeGo.type = 'button';
  wakeGo.onclick = () => tour();
  wakeNote.append(el('span', '', T('where.wake_once')), wakeGo);
  const wakeBox = el('span', 'clw-wake-box');
  wakeBox.append(v.wakeLabel, wakeNote);
  v.wakeSync = () => { wakeNote.hidden = !v.wake.checked || v.wakeLabel.hidden; wakeGo.disabled = v.scanBtn.disabled; };
  v.wake.onchange = v.wakeSync;
  const head = Office.deskHead({ id: ID, icon: Office.desks.get(ID).icon },
    { actions: [wakeBox, v.scanBtn], pages: { list: PAGES(), current: page, store: PAGE_KEY } });
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
    [T('section.flash'), T('help.flash_text')],
    [T('section.docker'), T('help.docker_text')],
    [T('section.icons'), T('help.icons_text')],
    [T('section.leftovers'), T('help.leftovers_text')],
    [T('section.partners'), T('help.partners_text')],
    [T('help.loop'), T('help.loop_text')],
    [T('help.sizes'), T('help.sizes_text')],
    [T('help.safe'), T('help.safe_text')],
  ]));
  Where.setHooks({
    changed: () => { if (view) renderBubble(); },
    search: (text) => setQuery(text),
    // a finding's link while «Tidying up» is shown: «Where is what» with that corner open, the link's words in its bar
    away: (route, words, anchor) => { pendingWords = words === undefined ? null : words; Office.go(route); Office.reveal(anchor); },
  });

  if (page === 'where') {
    // «Where is what» — her knowledge of the server (Where, at the end): the page's head line, its tiles, its sections
    v.partWhere = Office.place('part.where', part(T('part.where'), T('part.where_sub')));
    root.appendChild(v.partWhere);
    Where.build(v.partWhere);
    return v;
  }
  // «Tidying up» — her rooms and the storeroom: hints over the row (what she can't do now, the bar over the rooms), then
  // the rooms' row and the room chosen (none: every room, in the row's order)
  v.partTidy = Office.place('part.tidy', part(T('part.tidy'), T('part.tidy_sub')));
  v.partTidy.head.appendChild(Office.place('rooms', el('p', 'cl-part-sub', T('rooms_sub'))));
  root.appendChild(v.partTidy);
  v.notice = el('div', 'cl-notice');
  v.partTidy.append(v.notice, roomBar().el, tidySw.el);
  return v;
}

/** A page's head under the tabs: its title and a line under it, its tiles and sections below */
function part(title, sub) {
  const box = el('div', 'cl-part');
  const head = el('div', 'cl-part-head');
  head.append(el('div', 'cl-part-title', title), el('p', 'cl-part-sub', sub));
  box.appendChild(head);
  box.head = head;
  return box;
}

/** The bar over her rooms, built once: its words and its switch filter the rooms shown — every room, or the one chosen */
function roomBar() {
  if (tidyBar) return tidyBar;
  tidyBar = Office.filterBar({
    key: 'cleanup.tidy',
    label: T('tidy.filter_label'),
    only: { text: T('tidy.only_selectable'), hint: T('tidy.only_selectable_hint') },
    onChange: () => filterChanged(),
  });
  return tidyBar;
}

/** What the filter hides is no longer chosen: nothing is put away that isn't in sight */
function filterChanged() {
  for (const e of entries(selRoom)) if (selection.has(e.id) && !fitsRoom(e)) selection.delete(e.id);
  if (!view) return;
  renderSection();
  updateSelbar();
}

/** A link's words (her findings) into both bars — the rooms' and the corners' —, or '' to clear them */
function setQuery(text) {
  roomBar().set(text ? { text } : { text: '', only: false });
  Where.setWords(text);
  Where.filtered();
  filterChanged();
}

function render() {
  if (!view) return;
  renderBubble();
  view.scanBtn.disabled = !Office.agent.running || busy;
  view.wakeSync();
  if (view.page !== 'tidy') return;
  renderNotice();
  tidySw.render();
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
  view.wakeSync();
}

/**
 * What she found per room for her bubble and facts: [key, entries] — pictures missing for containers and for stacks apart;
 * only the rooms her page shows (Docker's with Docker on, the VMs' with the VM service on — 2026-10-18: never a count of
 * templates or pictures on a server whose Docker is off)
 */
function roomCounts() {
  const out = [];
  for (const sec of ROOMS) {
    if (!state || !visible(sec)) continue;
    const c = candidates(sec);
    if (sec !== 'icons') { if (c.length) out.push([sec, c]); continue; }
    const stacks = c.filter(isStackIcon);
    if (c.length > stacks.length) out.push(['icons', c.filter((e) => !isStackIcon(e))]);
    if (stacks.length) out.push(['icon_stacks', stacks]);
  }
  return out;
}

/**
 * Her greetings that say what lies around (2026-10-18, the owner: a bubble is true right now): greet.6 «A container without
 * a picture?» only while a container of hers has none, greet.2 «who left all this lying around?» only while she found
 * something; the others are her character
 */
const GREET_FACTS = {
  'greet.2': () => roomCounts().length > 0,
  'greet.6': () => roomCounts().some(([key]) => key === 'icons'),
};
const greetOk = (key) => !GREET_FACTS[key] || (!!state && GREET_FACTS[key]());

function bubbleText() {
  if (!state) return Office.agent.running ? T('bubble.loading') : T('bubble.no_data');
  const found = [];
  for (const [key, c] of roomCounts()) found.push(T('bubble.' + key, { n: c.length, size: fmt.size(sum(c)) }));
  let text = found.length ? T('bubble.found', { list: found.join(', ') }) : T('bubble.spotless');
  if (state.docker.enabled && !state.docker.ok) text = T('bubble.docker_down') + ' ' + text;
  if (state.trash.runs.length && state.trash.bytes !== null) text += ' ' + T('bubble.trash', { size: fmt.size(state.trash.bytes) });
  if (state.jobs.busy) text += ' ' + T('bubble.busy');
  return text;
}

/** Hints over the rooms' row (never a section: they stay at every click) — what she can't do now, or nothing known yet */
function renderNotice() {
  const box = view.notice;
  box.innerHTML = '';
  if (!state) { box.appendChild(emptyNote(Office.agent.running ? T('bubble.loading') : T('bubble.no_data'))); return; }
  if (state.backup_running) box.appendChild(el('p', 'callout', T('notice.backup')));
  if (state.docker.enabled && !state.docker.ok) box.appendChild(el('p', 'callout warn', T('notice.docker_down')));
}

// ------------------------------------------------------------------ the rooms' tiles (the section switcher)
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

/*
 * Her rooms: a tile is a room (core.js Office.sections — the room chosen alone under the row, none chosen = every room in the
 * row's order — they are short, mostly «all in use»); the choice kept as `cleanup.section`, a room's route
 * #/cleanup/tidy/<room>. The bar over the rooms filters what is shown.
 */
const tidySw = Office.sections({
  id: `${ID}/tidy`,                  // the routes #/cleanup/tidy/<room>
  key: 'cleanup.section',
  bodyId: 'cl-rooms-body',
  grid: 'cards cl-rooms',
  default: '',
  tiles: () => (state ? SECTIONS.filter((sec) => visible(sec)) : []).map((sec) => ({
    id: sec, key: `section.${sec}`, place: `room.${sec}`,
    tile: (a) => Office.tile({ name: T('section.' + sec), icon: ICONS[sec], chip: roomCount(sec), lines: [tileLine(sec)], ...a }),
    section: () => roomSection(sec),
  })),
  wants: (sub) => SECTIONS.includes(sub),
  // another room chosen: what was ticked in the room before is no longer chosen (she puts away one room's things at a time)
  onPick: (sec) => { if (sec && sec !== selRoom && selection.size) selection.clear(); },
});

/** The number on a room's tile: what she would clear away there (the storeroom: its runs) */
function roomCount(sec) {
  const n = sec === 'trash' ? state.trash.runs.length : candidates(sec).length;
  return n ? el('span', 'cl-count', fmt.number(n)) : null;
}

// ------------------------------------------------------------------ the rooms
function renderSection() {
  if (view && view.page === 'tidy') tidySw.render();
}

/** The bar's «n of m fit»: the chosen room's entries (the storeroom's runs) — with none chosen, every room's */
function roomDone() {
  const b = roomBar();
  if (!state) { b.done(0, 0, { onlyAvail: false }); return; }
  const open = tidySw.active();
  if (open === 'trash') {
    const runs = state.trash.runs;
    b.done(runs.filter((r) => !words().length || r.items.some(matches)).length, runs.length, { onlyAvail: false });
    return;
  }
  const list = open ? entries(open) : ROOMS.filter((x) => visible(x)).flatMap((x) => entries(x));
  b.done(list.filter(fitsRoom).length, list.length, { onlyAvail: list.some(selectable) });
}

/** One room: its head (CSV, «empty all»), what she knows about it, its list in groups — a section of the switcher */
function roomSection(sec) {
  const s = Office.place(`room-section.${sec}`, el('section', 'section'));    // its rows' and groups' place: theirs, not another room's
  s.id = 'cl-room-' + sec;
  shown = [];
  const right = [];
  const csv = el('button', 'btn small plain', T('csv'));
  csv.type = 'button';
  csv.title = T('csv_title');
  csv.onclick = () => exportCsv(sec);
  right.push(csv);
  if (sec === 'trash') {
    const all = state.trash.runs.filter((r) => !r.purging);
    if (all.length > 1) {
      const b = el('button', 'btn small danger plain', T('purge.all'));
      b.type = 'button';
      b.disabled = !Office.agent.running || state.backup_running;
      b.onclick = () => purgeDialog(all);
      right.push(b);
    }
  }
  s.appendChild(Office.sectionHead(T('section.' + sec), T('section.' + sec + '_sub'), ...right));
  const info = { templates: templatesInfo, appdata: appdataInfo, vms: vmsInfo, scripts: scriptsInfo, flash: flashInfo, docker: dockerInfo, icons: iconsInfo,
    leftovers: leftoversInfo, partners: partnersInfo, trash: trashInfo }[sec];
  const told = info ? info() : null;
  if (told) s.appendChild(told);
  const list = el('div', 'cl-room-list');
  if (sec === 'stacks' && !state.stacks.exists) list.appendChild(emptyNote(T('empty.no_compose', { path: state.stacks.root })));
  else if (sec === 'trash') renderTrash(list);
  else if (sec === 'leftovers') renderLeftovers(list);
  else renderList(list, sec);
  const mine = shown;
  if (mine.length > 1) s.appendChild(unfoldBar(list, mine));
  s.appendChild(list);
  roomDone();               // the bar over the rooms: what the rooms drawn hold (the last room drawn says it for all)
  return s;
}

/**
 * "Unfold all" / "Fold all" for the rows (and groups) of one room — and of one corner of «Where is what» (its own class
 * and keys: `clw-unfold`, `where.*`). One function for both pages (2026-10-12: a copy of it inside Where shadowed this one).
 */
function unfoldBar(body, rows, { cls = 'cl-unfold', keys = '' } = {}) {
  const bar = el('div', 'toolbar ' + cls);
  const b = el('button', 'btn small plain');
  b.type = 'button';
  const set = () => { b.textContent = rows.some((x) => !x.open()) ? T(keys + 'unfold_all') : T(keys + 'fold_all'); };
  b.onclick = () => {
    const open = rows.some((x) => !x.open());
    Office.keepInPlace(bar, () => {
      body.querySelectorAll('.group').forEach((g) => g.classList.toggle('closed', !open));
      rows.forEach((x) => x.set(open));
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

function flashInfo() {
  const box = el('div', 'cl-info');
  box.appendChild(el('p', 'role', T('flash.where', { dir: state.flash.dir })));
  if (!state.flash.known) box.appendChild(el('p', 'callout', T('flash.unknown')));
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
  const list = all.filter(fitsRoom);
  if (!list.length) { body.appendChild(emptyNote(T('no_match', { query: words().join(' ') }))); return; }
  const box = el('div', 'box');
  for (const cat of GROUPS[sec]) {
    const items = list.filter((e) => e.category === cat);
    if (items.length) box.appendChild(group(sec, cat, items));
  }
  body.appendChild(box);
}

function group(sec, cat, items) {
  const key = `${sec}.${cat}`;
  // while filtering, what matches is shown; a share setting on a pool that is gone opens its group (it needs the user)
  const closed = !words().length && (folded[key] ?? (CLOSED.includes(cat) && !items.some((e) => (e.pool_gone || []).length)));
  const box = el('div', 'group' + (closed ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');

  // a whole group at once (as far as the filter shows it) — only where nothing is still in use
  const can = CLOSED.includes(cat) ? [] : items.filter(selectable);
  const cb = el('input');
  cb.type = 'checkbox';
  cb.disabled = !can.length;
  cb.title = !can.length && items.some(inUseLocked) ? T('in_use_locked.group') : T('select_group');
  const sync = () => {
    const n = can.filter((e) => selection.has(e.id)).length;
    cb.checked = n > 0 && n === can.length;
    cb.indeterminate = n > 0 && n < can.length;
  };
  sync();
  cb.onclick = (e) => {
    e.stopPropagation();
    pickRoom(sec);
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
  items.forEach((e) => rows.appendChild(row(e, sync, sec)));
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
  sharecfg: () => [shareCfgMeta, shareCfgDetail],
  nvram: () => [libvirtMeta, libvirtDetail],
  tpm: () => [libvirtMeta, libvirtDetail],
  snapshotdb: () => [libvirtMeta, libvirtDetail],
  image: () => [imageMeta, imageDetail],
  volume: () => [volumeMeta, volumeDetail],
  cache: () => [cacheMeta, cacheDetail],
  drill: () => [drillMeta, drillDetail],
  icon: () => [iconMeta, iconDetail],
  leftover: () => [leftoverMeta, leftoverDetail],
  partner: () => [partnerMeta, partnerDetail],
};

/** The selection moves to another room: what was ticked in the room before is no longer chosen (drawn anew) */
function pickRoom(sec) {
  if (selRoom === sec) return;
  const had = selection.size > 0;
  selection.clear();
  selRoom = sec;
  if (had) renderSection();
}

/** A row of room sec: the checkbox selects, a click anywhere else unfolds the details */
function row(e, groupSync, sec) {
  const [metaFn, detailFn] = VIEWS[e.kind]();
  const r = Office.place(`item:${e.id}`, el('div', 'row unfolds' + (selection.has(e.id) ? ' selected' : '')));
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = selection.has(e.id);
  cb.disabled = !selectable(e);
  cb.setAttribute('aria-label', T('select_one', { name: label(e) }));
  if (inUseLocked(e)) {         // still needed: no choosing it, the row says why and what to do
    cb.title = lockedText(e);
    r.title = cb.title;
  }
  cb.onchange = () => {
    if (cb.checked && selRoom !== sec && selection.size) { pickRoom(sec); selection.add(e.id); renderSection(); updateSelbar(); return; }
    selRoom = sec;
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
  if (e.kind === 'sharecfg') items.push({ text: T('flash.show'), act: () => showFile(e) });
  if (e.kind === 'icon' && e.template_id) items.push({ text: T('show_xml'), act: () => showFile({ id: e.template_id, kind: 'template', file: e.template.split('/').pop() }) });
  if (e.kind === 'icon' && (e.category === 'compose' || isStackIcon(e)) && e.project) items.push({ text: T('show_compose'), act: () => showFile({ id: 'stack:' + e.project, kind: 'stack', folder: e.project }) });
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
    [T('d.config'), T('d.config_counts', { paths: nOf('paths', (t.counts || {}).paths), ports: nOf('ports', (t.counts || {}).ports), vars: nOf('vars', (t.counts || {}).vars) })],
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
      rows.push([T(`cat.${sec}.${e.category}`), isStackIcon(e) ? T('d.stack') : e.kind === 'icon' ? T('d.container') : T('item.' + e.kind), label(e), e.bytes ?? '', csvDate(e.mtime || e.newest || e.created || e.time),
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

// ------------------------------------------------------------------ share settings without their share (room «flash»)
/** Its storage lines as the file has them (shareUseCache, the pools) — what the share was set to */
const shareCfgLines = (e) => [['shareUseCache', e.use_cache], ['shareCachePool', e.pool], ['shareCachePool2', e.pool2]]
  .filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => `${k}="${v}"`);

function shareCfgMeta(e, meta) {
  // a pool its settings name that Unraid has no more (an older agent's state has no pool_gone)
  for (const pool of e.pool_gone || []) meta.appendChild(chip('⚠ ' + T('flash.pool_gone', { pool }), 'danger', T('flash.pool_gone_text', { pool, name: e.name })));
  const use = shareCfgLines(e);
  if (use.length) meta.appendChild(el('span', 'mono', use.join(' · ')));
  if (e.mtime) meta.appendChild(el('span', '', T('changed', { when: fmt.relative(e.mtime) })));
}

function shareCfgDetail(e) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.file'), e.path, true],
    [T('flash.d.share'), e.name, true],
    [T('d.description'), e.comment],
    [T('flash.d.storage'), shareCfgLines(e).length ? lines(shareCfgLines(e)) : null],
    [T('d.changed'), when(e.mtime)],
  ]));
  for (const pool of e.pool_gone || []) box.appendChild(el('p', 'callout warn', T('flash.pool_gone_text', { pool, name: e.name })));
  box.appendChild(el('p', 'role', T('flash.' + e.category + '_text', { name: e.name })));
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
  if (o.kind === 'snapshotdb' && o.entries) meta.appendChild(el('span', '', T('lv.entries', { n: o.entries })));
  if (o.kind === 'snapshotdb' && o.why === null && state && state.vms.ok) meta.appendChild(chip(T('lv.nothing_left'), 'quiet', T('lv.nothing_left_text')));
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
    [T('lv.d.entries'), o.kind === 'snapshotdb' ? o.names || [] : null, true],
    [T('lv.d.left'), o.kind === 'snapshotdb' ? (o.left || []).map((x) => x.zfs || x.file) : null, true],
  ]));
  box.appendChild(el('p', 'role', T('lv.' + o.kind + '_text') + ' ' + T('lv.orphan_text')));
  if (o.kind === 'snapshotdb' && (o.why === 'vm_left' || o.why === 'vm_unsure')) box.appendChild(el('p', 'role', snapshotDbWhy(o)));
  return box;
}

/**
 * A VM's snapshot list that can't go yet (issue #3): in Ms. Snapshotini's words, who judges it the same way — what is
 * still on disk (a ZFS snapshot is a ZFS matter, deleted in her list first), or why it can't be told
 */
function snapshotDbWhy(o) {
  const left = o.left || [];
  if (left.length) {
    return Office.t('snapshot.why.vm_left', { left: left.map((x) => x.zfs || x.file).join(', ') }) + ' '
      + (left.some((x) => x.zfs) ? T('lv.left_zfs') : T('lv.left_files'));
  }
  const u = (o.unsure || [])[0] || {};
  const what = u.what || '?';
  switch (u.why) {
    case 'method': return Office.t('snapshot.why.vm_unsure_method', { what: u.what || Office.t('snapshot.vm_method_none') });
    case 'asleep': return Office.t('snapshot.why.vm_unsure_asleep', { what });
    case 'no_dataset': return Office.t('snapshot.why.vm_unsure_no_dataset', { what });
    case 'no_zfs': return Office.t('snapshot.why.vm_unsure_no_zfs', { what });
    case 'unreadable': return Office.t('snapshot.why.vm_unsure_unreadable', { what });
    default: return Office.t('snapshot.why.vm_unsure_no_path');
  }
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
  // another driver or mounted from elsewhere: no copy in the storeroom — removing it is for good
  if (v.category !== 'used' && !v.keep) meta.appendChild(chip(T('vol.for_good'), 'warn', T('vol.for_good_text', { driver: v.driver })));
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
  if (v.category !== 'used') box.appendChild(el('p', 'role', v.keep ? T('vol.data_text') : T('vol.for_good_text', { driver: v.driver })));
  return box;
}

function cacheMeta(c, meta, figures) {
  meta.appendChild(el('span', '', T('cache.meta', { size: fmt.size(c.bytes), total: fmt.size(c.total) })));
  figures.append(el('b', '', fmt.size(c.bytes)), el('span', '', ''));
}

function cacheDetail() {
  return el('p', 'role', T('cache.text'));
}

/** What a crashed drill of Mr. Restori's left: his drill's id is its time (<YYYYMMDD>-<HHMMSS>-<random>) */
const drillTime = (id) => {
  const m = /^(\d{4})(\d\d)(\d\d)-(\d\d)(\d\d)(\d\d)-/.exec(id || '');
  return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]).getTime() / 1000 : null;
};

function drillMeta(e, meta) {
  meta.appendChild(chip(T('dl.what_' + e.what), 'quiet', T('dl.what_' + e.what + '_text')));
  const t = drillTime(e.drill);
  if (t) meta.appendChild(el('span', '', T('dl.of', { when: fmt.relative(t) })));
}

function drillDetail(e) {
  const box = el('div');
  box.appendChild(kv([
    [T('d.name'), e.name, true],
    [T('dl.drill'), `${e.drill}${drillTime(e.drill) ? ' · ' + fmt.date(drillTime(e.drill)) : ''}`],
    [T('d.container'), e.what === 'kopia_tmp' ? e.container : ''],
    [T('d.created'), when(e.created)],
  ]));
  box.appendChild(el('p', 'role', T('dl.text')));
  return box;
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
  const stack = isStackIcon(e);
  if (stack) meta.appendChild(chip(`🧩 ${e.folder}`, 'quiet', T('pic.stack_containers', { names: e.containers.map((c) => c.name).join(', ') })));
  else meta.appendChild(el('span', 'mono', e.image.replace(/@sha256:[0-9a-f]+$/i, '')));       // the digest in the details
  if (e.category === 'ok') return;
  if (stack) {
    meta.appendChild(e.status === 'missing'
      ? chip(T('pic.missing'), 'warn', T('pic.stack_missing_text'))
      : chip(T('pic.broken'), 'warn', T('pic.stack_broken_text', { value: e.value })));
  } else {
    meta.appendChild(e.status === 'missing'
      ? chip(T('pic.missing'), 'warn', T('pic.missing_text'))
      : chip(T('pic.broken'), 'warn', T(e.value.startsWith('/') ? 'pic.broken_local_text' : 'pic.broken_text', { value: e.value })));
  }
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
  const stack = isStackIcon(e);
  box.appendChild(kv(stack ? [
    [T('d.stack'), e.name === e.folder ? e.name : `${e.name} · ${e.folder}`],
    [T('d.containers'), e.containers.map((c) => c.name).join(', ')],
    [T('pic.d.main'), e.main || T('d.none')],
    [T('pic.d.now'), e.value ? (e.value.startsWith('data:') ? T('pic.data_address') : e.value) : T('d.none'), !!e.value && !e.value.startsWith('data:')],
    [T('pic.d.where'), e.path, true],
  ] : [
    [T('d.container'), `${e.name} · ${e.state}`],
    [T('d.image'), e.image, true],
    [T('pic.d.now'), e.value ? `${e.value}${e.value_from ? ` (${T('pic.from.' + e.value_from)})` : ''}` : T('d.none'), !!e.value],
    [T('pic.d.where'), e.category === 'ok' ? '' : e.path, true],
  ]));
  if (e.category === 'ok') { box.appendChild(el('p', 'role', T(stack ? 'pic.stack_ok_text' : 'pic.ok_text'))); return box; }
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

  if (stack && !e.copy_ok) {      // a file can't go into its folder: addresses only
    box.append(list, own, el('p', 'role', T('pic.own_hint_address')), el('p', 'role', T('pic.stack_far', { dir: e.dir })));
    return box;
  }
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
  box.append(list, own, el('p', 'role', T('pic.own_hint')), upload,
    el('p', 'role', stack ? T('pic.upload_hint_stack', { dir: e.dir }) : T('pic.upload_hint', { dir: (state.icons || {}).upload_dir || '' })));
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
  pickRoom('icons');
  selection.add(e.id);
  renderSection();
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
    name.append(picture(iconChoice(e)), el('span', '', label(e)));
    li.append(name, el('span', 'cl-pic-when', T(e.category === 'compose' ? 'icons.after_up' : 'icons.at_once')));
    ul.appendChild(li);
  });
  box.appendChild(ul);
  if (list.some((e) => e.category === 'template')) box.appendChild(el('p', 'role', T('icons.template_note')));
  if (list.some((e) => e.category === 'compose')) box.appendChild(el('p', 'callout', T('icons.compose_note')));
  if (list.some(isStackIcon)) box.appendChild(el('p', 'role', T('icons.stack_note')));
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
  const list = all.filter(fitsRoom);
  if (!list.length) { body.appendChild(emptyNote(T('no_match', { query: words().join(' ') }))); return; }
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
    pickRoom('leftovers');
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
  items.forEach((e) => rows.appendChild(row(e, sync, 'leftovers')));
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

/** What he made next to the live one (a restored copy, safety dumps, a dataset pulled from a partner) — never put aside */
const made = (e) => ['restored', 'safety', 'pulled'].includes(e.what);

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
  if (e.what === 'pulled') {      // read only here: his «Remove what was pulled…» destroys exactly it (BG2)
    const a = el('a', '', T('lo.pulled_link'));
    a.href = '#/restore/journal';
    const p = el('p', 'role');
    p.appendChild(a);
    box.appendChild(p);
  }
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
  if (!view || view.page !== 'tidy' || !selRoom || selRoom === 'trash' || !selection.size) { Office.selbar(null); return; }
  const list = entries(selRoom).filter((e) => selection.has(e.id));
  if (!list.length) { Office.selbar(null); return; }
  const bytes = sum(list);
  const forced = list.filter((e) => e.force).length;
  const sub = [selRoom !== 'templates' && bytes ? fmt.size(bytes) : '', forced ? T('selected_in_use', { n: forced }) : ''].filter(Boolean).join(' · ');
  const go = selRoom === 'docker'
    ? { text: T('remove.button'), kind: 'danger', act: removeDialog, disabled: !Office.agent.running || busy }
    : selRoom === 'icons'
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
  if (view) { view.wake.checked = false; view.wakeSync(); }
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
  const sec = selRoom;
  const list = entries(sec).filter((e) => selection.has(e.id));
  if (!list.length) return;
  const forced = list.filter((e) => e.force);
  const box = el('div');
  box.appendChild(el('p', '', T('park.text_' + sec, { n: list.length })));
  box.appendChild(shortlist(list));
  const running = sec === 'stacks' ? list.filter((s) => s.containers.length) : [];
  if (running.length) box.appendChild(el('p', 'callout', T('park.down', { names: running.map((s) => s.folder).join(', ') })));
  if (forced.length) box.appendChild(el('p', 'callout warn', T('park.in_use_' + sec, { names: forced.map(label).join(', ') })));
  const zfsList = list.filter((e) => ZFS_CONFIRM.includes(e.kind) && e.parts && e.parts.some((p) => p.dataset));
  if (list.some((e) => e.parts && e.parts.some((p) => p.dataset)) && !zfsList.length) box.appendChild(el('p', 'role', T('park.zfs')));
  const zfsOk = zfsList.length ? zfsPreview(box, zfsList) : null;
  box.appendChild(el('p', 'role', T('park.where_' + sec)));
  Office.dialog({
    title: T('park.title', { n: list.length }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: forced.length ? T('park.go_anyway') : T('park.go'), kind: forced.length ? 'danger' : '', act: async () => {
        busy = true;
        const j = await Office.api.post(`${ID}.park`, { ids: list.map((e) => e.id), force: forced.length > 0, zfs_ok: !!(zfsOk && zfsOk.checked) });
        busy = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
        selection.clear();
        setState(j.state);
        report(j.results, 'park');
        return true;
      } },
    ],
  });
  if (zfsOk) zfsOk.gate(document.querySelector('#sso-dialog-foot button:last-child'));
}

/**
 * Before datasets of appdata or domains go into the storeroom: exactly what zfs rename does with each — its new name,
 * what it holds, the datasets inside it and every snapshot with whose it is (her look again, read only). The box to
 * tick comes back; the button waits for it (the agent refuses those datasets without it, too).
 */
function zfsPreview(box, list) {
  const wrap = el('div', 'cl-zfs');
  wrap.appendChild(el('p', '', T('zp.head', { n: list.length })));
  const wait = el('p', 'role');
  wait.append(el('span', 'spin'), document.createTextNode(' ' + T('zp.looking')));
  wrap.appendChild(wait);
  box.appendChild(wrap);
  const l = el('label', 'check');
  const c = el('input');
  c.type = 'checkbox';
  c.disabled = true;
  l.append(c, el('span', '', T('zp.confirm')));
  box.appendChild(l);
  let button = null;
  c.gate = (b) => { button = b; if (button) button.disabled = !c.checked; };
  c.onchange = () => { if (button) button.disabled = !c.checked; };
  Office.api.post(`${ID}.zfs_preview`, { ids: list.map((e) => e.id) }).then((j) => {
    wait.remove();
    if (!j.ok) { wrap.appendChild(el('p', 'callout warn', Office.errorText(j.error, ID))); return; }
    j.plans.forEach((plan) => plan.datasets.forEach((d) => wrap.appendChild(zfsPlanBox(d))));
    c.disabled = false;
  });
  return c;
}

const ZFS_OWNERS = ['plan', 'backup', 'partner', 'manual'];

function zfsPlanBox(d) {
  const b = el('div', 'cl-zfs-one');
  b.appendChild(el('p', 'mono cl-zfs-cmd', `zfs rename ${d.dataset} ${d.to}`));
  const facts = [];
  if (d.used !== null) facts.push(d.snaps_bytes ? T('zp.size_snaps', { size: fmt.size(d.used), snaps: fmt.size(d.snaps_bytes) }) : T('zp.size', { size: fmt.size(d.used) }));
  facts.push(d.count ? T('zp.snaps', { n: d.count }) : T('zp.no_snaps'));
  if (d.inside.length) facts.push(T('zp.inside', { n: d.inside.length }));
  b.appendChild(el('p', 'role', facts.join(' · ')));
  if (d.inside.length) b.appendChild(el('p', 'mono cl-lines', d.inside.join('\n')));
  const owners = el('div', 'row-meta');
  ZFS_OWNERS.filter((o) => d.owners[o]).forEach((o) => owners.appendChild(chip(T('zp.owner.' + o) + ' · ' + d.owners[o], o === 'manual' ? 'quiet' : 'accent', T('zp.owner.' + o + '_text'))));
  if (owners.childNodes.length) b.appendChild(owners);
  if (d.snapshots.length) {
    const det = el('details');
    det.appendChild(el('summary', '', T('zp.list', { n: d.count })));
    const ul = el('ul', 'cl-zfs-snaps');
    d.snapshots.forEach((x) => {
      const li = el('li');
      li.append(el('span', 'mono', x.name), el('small', '', `${T('zp.owner.' + x.owner)} · ${fmt.size(x.used)}${x.time ? ' · ' + fmt.date(x.time) : ''}`));
      ul.appendChild(li);
    });
    if (d.count > d.snapshots.length) ul.appendChild(el('li', 'role', T('zp.more', { n: d.count - d.snapshots.length })));
    det.appendChild(ul);
    b.appendChild(det);
  }
  if (d.owners.plan || d.owners.backup) b.appendChild(el('p', 'role', T('zp.retention')));
  b.appendChild(el('p', 'role', T('zp.time')));
  return b;
}

/** Docker's leftovers: volumes into the storeroom first (copied), images, the cache and volumes she can't copy for good */
async function removeDialog() {
  if (!(await fresh())) return;
  const list = entries('docker').filter((e) => selection.has(e.id));
  if (!list.length) return;
  const kept = list.filter((e) => e.kind === 'volume' && e.keep);
  const gone = list.length - kept.length;
  const box = el('div');
  const kind = !gone ? '_kept' : kept.length ? '_mixed' : '';      // into the storeroom, partly, or all for good
  box.appendChild(el('p', '', T('remove.text' + kind, { n: list.length, size: fmt.size(sum(list)) })));
  box.appendChild(shortlist(list));
  if (kept.length) box.appendChild(el('p', 'callout', T('remove.volumes_kept', { n: kept.length })));
  const lost = list.filter((e) => e.kind === 'volume' && !e.keep);
  if (lost.length) box.appendChild(el('p', 'callout warn', T('remove.volumes', { n: lost.length, names: lost.map((e) => e.name).join(', ') })));
  if (list.some((e) => e.kind === 'image')) box.appendChild(el('p', 'role', T('remove.images')));
  if (list.some((e) => e.kind === 'drill')) box.appendChild(el('p', 'role', T('remove.drill')));
  if (gone) box.appendChild(el('p', 'callout warn', T('purge.final')));
  Office.dialog({
    title: T('remove.title' + kind, { n: list.length }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T(gone ? 'remove.go' : 'remove.go_kept'), kind: gone ? 'danger' : '', act: async () => {
        busy = true;
        const j = await Office.api.post(`${ID}.remove`, { ids: list.map((e) => e.id) });
        busy = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
        selection.clear();
        setState(j.state);
        const stored = j.results.filter((r) => r.ok && r.stored).length;
        if (stored && j.results.every((r) => r.ok)) Office.toast(T('remove.done_kept', { n: stored }));
        else report(j.results, 'remove');
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
/** Her storerooms on sleeping pools or array disks: not looked at — woken only on the user's click (C-M9) */
function trashInfo() {
  const asleep = state.trash.asleep || [];      // an older agent's state has none
  if (!asleep.length) return null;
  const box = el('div', 'cl-info');
  box.appendChild(asleepCallout(asleep));
  return box;
}

function renderTrash(body) {
  const runs = state.trash.runs;
  if (!runs.length) { body.appendChild(emptyNote(T('empty.trash'))); return; }
  const shownRuns = runs.filter((r) => !words().length || r.items.some(matches));
  if (!shownRuns.length) { body.appendChild(emptyNote(T('no_match', { query: words().join(' ') }))); return; }
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
  // Mr. Restori's drill copy (2026-10-21): a verified one empties itself after a week, an older drill's only by hand
  // (an older agent's state has no drill_copy; a changed one has none either: plain storeroom content)
  const copy = it.present ? it.drill_copy : null;
  if (copy && copy.until) {
    const date = fmt.date(copy.until);
    const due = copy.until <= Date.now() / 1000;
    meta.appendChild(chip(`🧪 ${T(due ? 'item.drill_due' : 'item.drill_until', { date })}`, 'quiet', T('item.drill_until_text', { date })));
  } else if (copy) meta.appendChild(chip(`🧪 ${T('item.drill_old')}`, 'quiet', T('item.drill_old_text')));
  if (it.volumes.length) meta.appendChild(chip(T('stack.volumes', { n: it.volumes.length }), 'quiet', T('item.volumes_text', { names: it.volumes.join(', ') })));
  main.appendChild(meta);
  const act = el('div', 'cl-acts');
  if (!run.legacy && !run.purging && it.from && it.present) {
    const b = el('button', 'btn small plain', T('restore.button'));
    b.type = 'button';
    b.title = it.kind === 'volume' ? T('restore.title_volume', { name: it.name }) : T('restore.title', { path: it.from });
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
  else Office.toast(T(it.kind === 'stack' ? 'restore.done_stack' : it.kind === 'icon' && it.name === 'icon_url' ? 'restore.done_stack_icon' : it.kind === 'icon' ? 'restore.done_icon' : it.kind === 'volume' ? 'restore.done_volume' : 'restore.done', { name: it.kind === 'icon' ? it.label || it.name : it.name }));
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
  if (runs.some((r) => ['appdata', 'domains', 'isos', 'docker'].includes(r.where))) box.appendChild(el('p', 'role', T('purge.snapshots')));
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
   own names (state, view, row …; a filter bar of its own over the open corner): what is going on, her advice «If I were
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
// the corner chosen up to tiles v2 (`cleanup.where.section`, '' = none open) is the tile chosen now (`cleanup.where.tile`);
// none open then is no choice now — the page starts at «Where things are»
{
  const old = Office.store(STORE + 'section');
  if (old !== null && old !== undefined) {
    if (SECTIONS.includes(old) && Office.store(STORE + 'tile') === null) Office.store(STORE + 'tile', old);
    Office.store(STORE + 'section', null);
  }
}

let state = null;
let sizes = { sizes: {}, queue: [], running: [] };
let view = null;
let expanded = new Set();
let folderSort = Office.store(STORE + 'folder_sort') || 'name';
let onlyUnused = false;
let sizeTimer = null;
// the places unfolded in «Where things are» (`cleanup.where.place`: up to tiles v2 the one place tile open, now its rows)
const placesOpen = new Set(String(Office.store(STORE + 'place') || '').split(',').filter(Boolean));
let shown = [];          // rows of the corner being drawn that can unfold: { open(), set(bool) }
// the page's: the bubble and the wake switch anew; set the filter; go to «Where is what» from her other page
let hooks = { changed: () => {}, search: () => {}, away: () => {} };
// the bar over the corners (core.js Office.filterBar, built once): its words (and «only what needs a look») filter the
// corners shown — the chosen one, or every corner while no tile is chosen; never kept
let bar = null;
let drawnRows = 0;       // rows the corner drew (an empty filter says so)
let counting = null;     // {total, odd} while a corner is drawn once more, only counting its rows
let suspend = false;     // the bar's filter left out (the counts without it)
/** The number tiles of «What's going on» up to tiles v2 → the corner tile that carries their numbers now (an old address
    #/cleanup/where/stat:<tile> opens that corner) */
const STAT_CORNERS = { array: 'disks', docker: 'docker', vms: 'vms', smb: 'network', scripts: 'scripts', backup: 'backups', temp: 'disks', smart: 'disks', notices: 'notices' };

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
    sizeTimer = setTimeout(async () => { await loadSizes(); if (view) sw.render(); }, 3000);
  }
}

// ------------------------------------------------------------------ helpers
/** The bar's words (folded) — none while counting without the filter */
const words = () => (suspend || !bar ? [] : bar.read().words);
/** «only what needs a look»: rows with a warning chip (what makes a corner say «look») */
const onlyOdd = () => !suspend && !!bar && bar.read().only;
const matches = (text) => Office.fits(words(), String(text));
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
  // needs a look: one of its chips warns (warn, danger) — «only what needs a look» draws only those
  const odd = (meta || []).some((x) => x && x.classList && (x.classList.contains('warn') || x.classList.contains('danger')));
  if (counting) { counting.total++; if (odd) counting.odd++; return document.createDocumentFragment(); }
  if (onlyOdd() && !odd) return document.createDocumentFragment();
  drawnRows++;
  const r = Office.place(key, el('div', 'row nocheck' + (cls ? ' ' + cls : '')));     // its key is its place (the search's items)
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
  const left = rows.filter((x) => !(x instanceof DocumentFragment && !x.childNodes.length));
  if (rows.length && !left.length) return document.createDocumentFragment();       // every row left out by the filter
  rows = left;
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
    if (view) sw.render();
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
    const bad = h.devices.filter((d) => smartBad(d).length || d.errors || (d.status && d.status !== 'DISK_OK' && !d.building));   // a disk being built is no problem
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
    // no parity at all: one failed array disk and what was on it is gone (2026-10-07: she should say so here too)
    if (p.slots && !p.present) {
      const data = h.devices.filter((d) => d.type === 'Data').length;
      add('no_parity', 'advice', { n: data }, { path: '/Main', text: T('where.adv.to_main') }, 'none');
    }
    if (p.present && (!p.checked || Date.now() / 1000 - p.checked > 90 * DAY)) {
      add('parity', 'advice', { when: p.checked ? fmt.relative(p.checked) : T('where.adv.never') }, { path: '/Settings/Scheduler', text: T('where.adv.to_scheduler') }, p.checked ? fmt.dayKey(p.checked) : 'never');
    }
  }
  if (!a.ups) add('ups', 'info', {}, { path: '/Settings/UPSsettings', text: T('where.adv.to_ups') });
  if (!a.syslog_kept) add('syslog', 'advice', { wear: a.boot_internal ? '' : ' ' + T('where.adv.syslog.wear') }, { path: '/Settings/SyslogSettings', text: T('where.adv.to_syslog') });

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
  // on a large server one stream capped at 2.2 Gbit/s, 93 with «virtio». A performance hint, not a risk; the signature is the
  // VMs' names (one more, or one changed, brings the tip back)
  const slowNet = (a.vm_netmodel || []).filter((n) => typeof n === 'string' && n !== '');
  if (slowNet.length) {
    add('vm_netmodel', 'info', { names: listNames(slowNet, 3), n: slowNet.length }, { path: '/VMs', text: T('where.adv.to_vms') }, slowNet.join(','));
  }

  // VMs with Host Passthrough and «Migratable» On (waVmMigratable(), Unraid's default for new VMs): libvirt hides the CPU
  // features a live migration couldn't take along — no RAM or CPU cost, but the guest sees less of the CPU; Unraid never
  // moves a VM to another server. Good to know; the signature is the VMs' names (one more brings the tip back)
  const migratable = (a.vm_migratable || []).filter((n) => typeof n === 'string' && n !== '');
  if (migratable.length) {
    add('vm_migratable', 'info', { names: listNames(migratable, 3), n: migratable.length }, { path: '/VMs', text: T('where.adv.to_vms') }, migratable.join(','));
  }

  // the array's write method (waWriteMethod()): «Auto» is read/modify/write — only the target disk and parity spin, turbo
  // write reads every other data disk. Nothing writes straight to the array: keep it (good to know, 2026-10-09);
  // shares that do: turbo write for big copies, or Squid's plugin. The signature: the method, those shares, the disks'
  // sleep and the plugin — one more share, a pool in front, a spin-down delay set bring the tip back
  const wm = a.write_method;
  const toDisks = { path: '/Settings/DiskSettings', text: T('where.adv.to_disks') };
  if (wm && ['auto', 'rmw'].includes(wm.method)) {
    const method = T(`where.adv.write_method.${wm.method}`);
    if (wm.tip === 'keep') {
      const moved = wm.pooled > 0 ? T('where.adv.write_method_keep.moved', { n: Number(wm.pooled) }) : T('where.adv.write_method_keep.no_moved');
      add('write_method_keep', 'info', { method, moved, disks: T(wm.sleep ? 'where.adv.write_method_keep.sleep' : 'where.adv.write_method_keep.awake') },
        toDisks, wm.method);
    } else if (wm.tip === 'turbo') {
      const direct = (wm.direct || []).filter((x) => typeof x === 'string');
      const how = wm.sleep ? T(wm.plugin === 'off' ? 'where.adv.write_method_turbo.plugin_off' : 'where.adv.write_method_turbo.plugin') : '';
      add('write_method_turbo', 'info', { method, names: listNames(direct, 3), n: direct.length,
        disks: T(wm.sleep ? 'where.adv.write_method_turbo.sleep' : 'where.adv.write_method_turbo.awake'), how },
        toDisks, `${wm.method}:${direct.join(',')}:${wm.sleep ? 'sleep' : 'awake'}:${wm.plugin || ''}`);
    }
  }

  // ZFS's read cache and a VM with PCI passthrough (waZfsArc(); 2026-10-09): «Unlimited (Dynamic)» is right as a
  // rule — the ARC gives RAM back under pressure. Only a passthrough VM pins all its RAM at once at its start: when the RAM
  // is that tight she names the VM and suggests a cap. The signature: the VM, its RAM and the ARC's limit (the containers'
  // memory moving a little doesn't bring the tip back)
  const arc = a.zfs_arc;
  if (arc && arc.tip === 'passthrough' && typeof arc.vm === 'string') {
    const gib = 2 ** 30;
    add('zfs_arc_passthrough', 'advice', {
      vm: arc.vm, vm_memory: fmt.size(arc.vm_memory), others: fmt.size(arc.others), containers: fmt.size(arc.containers),
      ram: fmt.size(arc.ram), left: fmt.size(Math.max(arc.left, 0)), arc_max: fmt.size(arc.c_max), percent: Math.round(arc.c_max / arc.ram * 100),
      setting: arc.unlimited ? T('where.adv.zfs_arc_passthrough.unlimited') : T('where.adv.zfs_arc_passthrough.limit', { size: fmt.size(arc.c_max) }),
      cap: Number(arc.cap), sys_free: String(Math.ceil(arc.vm_memory / gib) * gib),
    }, toDisks, `${arc.vm}:${Math.round(arc.vm_memory / gib)}:${Math.round(arc.c_max / gib)}`);
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

/** «If I were you …» — a section of the switcher (the tile «Advice») */
function whereAdvice() {
  const box = el('section', 'section');
  box.id = 'clw-advice';
  if (!state) return box;
  const all = advice();
  const hidden = adviceHidden();
  const isHidden = (x) => hidden[x.id] === x.sig;
  const shown = all.filter((x) => !isHidden(x));
  const gone = all.filter(isHidden);
  const extra = [];
  if (gone.length) {
    const b = el('button', 'btn small plain', T(showHiddenAdvice ? 'where.adv.hide_known' : 'where.adv.show_known', { n: gone.length }));
    b.type = 'button';
    b.onclick = () => { showHiddenAdvice = !showHiddenAdvice; sw.render(); };
    extra.push(b);
  }
  box.appendChild(Office.sectionHead(T('where.adv.title'), T('where.adv.sub'), ...extra, { place: 'where.adv.title' }));
  const list = [...shown, ...(showHiddenAdvice ? gone : [])];
  if (!list.length) {
    box.appendChild(el('p', 'role clw-advice-none', T(all.length ? 'where.adv.all_known' : 'where.adv.none')));
    return box;
  }
  const rows = el('div', 'box');
  list.forEach((x) => rows.appendChild(adviceRow(x, isHidden(x))));
  box.appendChild(rows);
  return box;
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
    sw.render();              // the tile's count too
    hooks.changed();
  };
  acts.appendChild(b);
  r.appendChild(acts);
  return r;
}

/** Where a tile's section begins: its head (her advice's is the search's place «If I were you …») */
const headOf = (id) => (id === 'advice' ? 'where.adv.title' : `where.head.${id}`);

/**
 * A link to a tile's section (what she noticed, a place's «more», her advice in the bubble): that tile open as a link
 * opens it (no toggle) — the link's words in the corners' bar — and its head brought into view; from «Tidying up» her
 * page changes first (#/cleanup/where/<tile>)
 */
function pick(id, search) {
  if (!view) { hooks.away(`#/${ID}/where/${id}`, search, headOf(id)); return; }
  if (search !== undefined) hooks.search(search);
  sw.pick(id, 'route');
  Office.reveal(headOf(id));
}

/** «I know every corner: …» — Docker not answering: its containers can't be counted, said instead of «0 containers» (O8) */
function whereSummary() {
  const dk = state.system && state.system.docker;
  const containers = dk && dk.up === false ? Office.t('common.docker_off') : dk && dk.silent ? Office.t('common.docker_silent')
    : nOf('containers', state.containers.length);
  return T('where.bubble.summary', { shares: nOf('shares', state.shares.length), containers, scripts: nOf('user_scripts', state.scripts.length) });
}

/** Her part of the bubble: every corner she knows (summary), what she noticed (links) and her advice (more) — null before her first look */
function bubble() {
  if (!state) return null;
  const summary = whereSummary();
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
    a.onclick = (e) => { e.preventDefault(); pick('advice'); };
    box.append(f.length ? ' ' : '', a);
  }
  return { summary, more: box };
}

/** For the reception: every corner she knows, how many things she noticed, and the facts Ms. Whereabouts gave there */
function reception() {
  if (!state) return { summary: '', findings: 0, facts: [] };
  const sys = state.system;
  const facts = [
    sys.docker.up === false ? Office.t('common.docker_off') : sys.docker.silent ? Office.t('common.docker_silent')
      : T('where.fact.docker', { running: sys.docker.running, total: sys.docker.total }),
    sys.vms.total ? T('where.fact.vms', { running: sys.vms.running, total: sys.vms.total }) : sys.vms.up === false ? T('tile.vm_off') : T('where.fact.vms_none'),
    T('where.fact.smb', { n: state.smb.sessions.length }),
  ];
  if (sys.scripts.length) facts.push(T('where.fact.scripts_running', { n: sys.scripts.length, names: sys.scripts.join(', ') }));
  const summary = whereSummary();
  return { summary, findings: findings().length, facts };
}

// ------------------------------------------------------------------ building
/** «Where is what» into its page under the tabs: the head line (what is going on), the hints over the row, the tiles */
function build(root) {
  const v = {};
  // the server, Unraid, up since, the licence, the load, when she looked — the head line of the row (wraps, never cut)
  v.now = Office.place('where.now', el('p', 'cl-part-sub clw-now'));
  (root.head || root).appendChild(v.now);
  v.hints = el('div', 'clw-hints');           // hints, no section: a ZFS scrub going on; nothing known yet
  root.append(v.hints, sw.el);
  view = v;
}

function render() {
  if (!view) return;
  renderNow();
  sw.render();
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

// ------------------------------------------------------------------ what's going on: the head line and the hints
function renderNow() {
  const line = view.now;
  const hints = view.hints;
  hints.innerHTML = '';
  if (!state) {
    line.textContent = '';
    hints.appendChild(emptyNote(Office.agent.running ? T('where.bubble.no_tour') : T('where.bubble.no_data')));
    return;
  }
  const sys = state.system;
  const lic = state.license;
  line.textContent = [sys.name, `Unraid ${sys.unraid || '?'}`, T('where.uptime', { time: fmt.duration(sys.uptime) }),
    lic && lic.type ? `${T('where.stat.license')} ${lic.type} ${lic.expires ? T('where.stat.license_until', { when: fmt.date(lic.expires) }) : T('where.stat.license_since', { when: fmt.date(lic.since) })}` : '',
    sys.load ? `${T('where.stat.load')} ${sys.load.map((x) => fmt.number(x, 1)).join(' · ')}` : '',
    Office.t('common.scanned_ago', { when: fmt.relative(state.time) })].filter(Boolean).join(' · ');
  line.title = [line.textContent, sys.load ? `${T('where.stat.load')}: ${T('where.stat.load_sub')}` : '', lic && lic.check ? lic.check : ''].filter(Boolean).join('\n');
  line.classList.toggle('clw-bad', !!(lic && lic.check));
  // a ZFS scrub only while it runs: a callout over the row (no tile — nothing to choose)
  if ((sys.scrubs || []).length) hints.appendChild(el('p', 'callout warn', T('where.scrub_running', { list: sys.scrubs.map((x) => `${x.pool}: ${x.text}`).join(' · ') })));
}

// ------------------------------------------------------------------ the tiles (the section switcher)
/*
 * One row of 13 tiles (tiles v2, CLAUDE.md «Tiles»): her advice, «Where things are» and the eleven corners — each the
 * summary of its section under the row, the live numbers of the old row «What's going on» on the corners they belong
 * to (STAT_CORNERS). A click shows that section alone, nothing chosen = the whole page; the start «Where things are»;
 * the choice kept as `cleanup.where.tile`, a tile's route #/cleanup/where/<tile>.
 */
const TILES = ['advice', 'places', ...SECTIONS];
const TILE_ICONS = { advice: '💡', places: '🗺️', shares: '📁', folders: '🗃️', docker: '🐳', vms: '🖥️', disks: '💽', access: '👥', network: '🔌', scripts: '📜', backups: '🛟', notices: '🔔', plugins: '🧩' };
const tileKey = (id) => (id === 'advice' ? 'where.tile.advice' : id === 'places' ? 'where.places' : `where.section.${id}`);
const sw = Office.sections({
  id: `${ID}/where`,                 // the routes #/cleanup/where/<tile>
  key: STORE + 'tile',
  bodyId: 'clw-body',
  grid: 'stats clw-tiles',
  default: 'places',
  tiles: () => (state ? TILES.filter((id) => id !== 'places' || state.locations) : []).map((id) => ({
    id, key: tileKey(id), place: `where.${id}`,
    tile: (a) => Office.stat({ ...tileFace(id), ...a }),
    section: () => whereSection(id),
  })),
  wants: (sub) => TILES.includes(sub),
});

/** A tile's section: her advice, «Where things are», or a corner */
function whereSection(id) {
  if (id === 'advice') return whereAdvice();
  if (id === 'places') return wherePlaces();
  return whereCorner(id);
}

/** What a tile says: {label, value, sub, alert, tip} — its section's summary, the live numbers on the corners' tiles */
function tileFace(id) {
  const s = state;
  const sys = s.system;
  const n = (x) => fmt.number(x);
  const label = `${TILE_ICONS[id]} ${T(tileKey(id))}`;
  const face = (value, sub, more = {}) => ({ label, value, sub, ...more });
  switch (id) {
    case 'advice': {
      const hidden = adviceHidden();
      const all = advice();
      const open = all.filter((x) => hidden[x.id] !== x.sig).length;
      const known = all.length - open;
      return face(open ? nOf('advice', open) : T('where.tile.advice_none'), known ? T('where.tile.advice_known', { n: known }) : '');
    }
    case 'places': {
      const b = { offsite: 0, local: 0, none: 0 };
      (s.locations || []).forEach((g) => { const w = placeSummary(g).worst; if (b[w] !== undefined) b[w]++; });
      return face(nOf('places', (s.locations || []).length), T('where.sum.shares_backup', b));
    }
    case 'docker': {
      const stopped = s.containers.filter((c) => c.autostart && c.state !== 'running').length;
      if (sys.docker.up === false) return face('–', Office.t('common.docker_off'));
      if (sys.docker.silent) return face('–', Office.t('common.docker_silent'), { alert: true });      // B15: never «0 / 0»
      return face(`${sys.docker.running} / ${sys.docker.total}`, T('where.tile.docker_sub', { stacks: nOf('stacks', s.compose.length) }), { alert: stopped > 0 });
    }
    case 'vms':
      return face(sys.vms.total ? `${sys.vms.running} / ${sys.vms.total}` : '–', sys.vms.total ? T('where.stat.running') : sys.vms.up === false ? T('tile.vm_off') : T('where.fact.vms_none'));
    case 'disks': {
      const array = sys.array;
      const more = array.resync ? T('where.stat.resync', { action: array.resync.action, percent: array.resync.percent }) : sys.mover ? T('where.stat.mover') : T('where.stat.no_parity');
      const arrayLine = T('where.tile.array', { state: array.state === 'STARTED' ? T('where.stat.started') : (array.state || '?'), more });
      const h = s.health;
      if (!h) return face('–', arrayLine, { alert: array.state !== 'STARTED' || !!array.resync });
      const awake = h.devices.filter((d) => d.temp !== null && d.temp !== undefined);
      const hottest = awake.reduce((a, d) => (!a || d.temp > a.temp ? d : a), null);
      const warm = awake.filter((d) => tempLevel(d) !== '');
      const bad = h.devices.filter((d) => smartBad(d).length).length;
      const smart = T('where.tile.smart', { state: bad ? T('where.stat.smart_bad', { n: bad }) : T('where.stat.smart_ok') });
      const temp = hottest ? `${hottest.temp} °C` : '–';
      const tip = [hottest ? (warm.length ? T('where.stat.temp_warm', { n: warm.length }) : T('where.stat.temp_ok', { name: hottest.name })) : T('where.stat.all_asleep'), smart, arrayLine].join('\n');
      return face(temp, [smart, arrayLine], { tip, alert: array.state !== 'STARTED' || !!array.resync || bad > 0 || warm.length > 0
        || h.devices.some((d) => d.errors || fillLevel(d) === 'danger') });
    }
    case 'network': {
      const who = s.smb.sessions.map((x) => `${x.user}@${x.machine}`).join(', ');
      return face(nOf('sessions', s.smb.sessions.length), who || T('where.stat.nobody'), { tip: who });
    }
    case 'scripts': {
      const running = sys.scripts.length;
      return face(T('where.tile.scripts_running', { n: running }), T('where.tile.scripts_sub', { scripts: nOf('user_scripts', s.scripts.length), cron: nOf('cron_jobs', s.cron.length) }),
        { alert: running > 0 || s.scripts.some((x) => x.missing || x.stale), tip: sys.scripts.join(', ') });
    }
    case 'backups':
      return face(sys.backup.running ? T('where.tile.backup_running', { time: fmt.time(sys.backup.since) }) : T('where.tile.backup_idle'),
        [sys.backup.running ? sys.backup.step || '' : '', T('where.sum.backups', { n: s.backups.length })], { alert: !!sys.backup.running });
    case 'notices': {
      const list = s.notices || [];
      const alerts = list.filter((x) => x.importance === 'alert').length;
      return face(n(list.length), T('where.sum.notices', { unread: nOf('unread', list.length), alerts: nOf('alerts', alerts) }), { alert: alerts > 0 });
    }
    case 'shares': {
      const { sub, alert } = sectionSummary(id);
      return face(n(s.shares.length), sub, { alert: !!alert });
    }
    case 'folders': {
      const { sub } = sectionSummary(id);
      return face(n(s.folders.reduce((a, f) => a + f.folders.length, 0)), sub);
    }
    case 'access': return face(n(s.users.length), sectionSummary(id).sub);
    case 'plugins': return face(n(s.plugins.length), '');
  }
  return face('', '');
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
    [T('where.help.tour'), T('where.help.tour_text')],
    [T('where.help.asleep'), T('where.help.asleep_text')],
    [T('where.help.tunables'), T('where.help.tunables_text')],
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

/** «Where things are» — a section of the switcher: the places as rows (name, path, protection, what is in it), each
    unfolding to its paths (`cleanup.where.place` keeps which are open) */
function wherePlaces() {
  const s = el('section', 'section');
  s.id = 'clw-places';
  s.appendChild(Office.sectionHead(T('where.places'), T('where.places_hint'), { place: 'where.head.places' }));
  const box = el('div', 'box clw-place-rows');
  for (const g of (state && state.locations) || []) box.appendChild(placeRow(g));
  s.appendChild(box);
  return s;
}

/** One place: a click anywhere on the row (but its own controls and the details) unfolds its paths */
function placeRow(g) {
  const { main, worst, sub } = placeSummary(g);
  const r = Office.place(`place:${g.id}`, el('div', 'row nocheck unfolds clw-place-row'));
  const m = el('div', 'row-main');
  const name = el('div', 'row-name link text');
  name.append(el('span', 'clw-place-icon', PLACE_ICONS[g.id] || '•'), el('span', '', T('where.place.' + g.id)));
  name.title = T('where.details');
  const meta = el('div', 'row-meta');
  if (main) meta.appendChild(el('span', 'mono clw-place-path', main.path));
  if (sub) meta.appendChild(el('span', '', sub));
  m.append(name, meta);
  const bk = el('div', 'clw-place-bk');
  const c = backupChip(worst);
  if (c) bk.appendChild(c);
  r.append(m, bk);
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; r.classList.remove('open'); placesOpen.delete(g.id); }
    if (open && !box) {
      box = el('div', 'row-detail');
      box.appendChild(placeDetail(g));
      r.appendChild(box);
      r.classList.add('open');
      placesOpen.add(g.id);
    }
    Office.store(STORE + 'place', [...placesOpen].join(',') || null);
  };
  r.onclick = (e) => {
    if (e.target.closest('button, a, input, select, textarea, summary, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;     // selecting text
    Office.keepInPlace(r, () => set(!box));
  };
  if (placesOpen.has(g.id)) set(true);
  return r;
}

/**
 * A file list the user opened or closed stays so when her page is drawn anew (a new look, the messenger's word with
 * every answer of the agent) — kept per place and item for this page's life, closed until touched
 */
const whereFolds = new Map();
function keepFold(det, key) {
  det.open = whereFolds.get(key) === true;
  let shown = det.open;
  det.addEventListener('toggle', () => {
    if (det.open === shown) return;
    shown = det.open;
    whereFolds.set(key, det.open);
  });
  return det;
}

function placeDetail(g) {
  const box = el('div', 'clw-place');
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
      const det = keepFold(el('details', 'clw-files'), `${g.id}:${it.id}`);
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

// ------------------------------------------------------------------ the corners
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
      if (s.system.docker.up === false) return { sub: Office.t('common.docker_off') };
      if (s.system.docker.silent) return { sub: Office.t('common.docker_silent'), alert: true };
      const run = s.containers.filter((c) => c.state === 'running').length;
      const stopped = s.containers.filter((c) => c.autostart && c.state !== 'running').length;
      return { sub: T('where.sum.docker', { run: n(run), total: n(s.containers.length), stacks: nOf('stacks', s.compose.length) }), alert: stopped > 0 };
    }
    case 'vms': return { sub: s.vms.length ? T('where.sum.vms', { run: n(s.vms.filter((v) => v.running).length), total: n(s.vms.length) })
      : s.system.vms.up === false ? T('tile.vm_off') : T('where.fact.vms_none') };
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
      return { sub: T('where.sum.scripts', { scripts: nOf('user_scripts', s.scripts.length), cron: nOf('cron_jobs', s.cron.length), running }), alert: s.scripts.some((x) => x.missing || x.stale) };
    }
    case 'backups': return { sub: T('where.sum.backups', { n: s.backups.length }) };
    case 'notices': {
      const alerts = (s.notices || []).filter((x) => x.importance === 'alert').length;
      return { sub: T('where.sum.notices', { unread: nOf('unread', (s.notices || []).length), alerts: nOf('alerts', alerts) }), alert: alerts > 0 };
    }
    case 'plugins': return { sub: T('where.sum.plugins', { n: s.plugins.length }) };
  }
  return { sub: '' };
}

const CORNERS = () => ({ shares, folders, docker, vms, disks, access, network, scripts, backups, notices, plugins });

/** The bar over the corners, built once (its words and switch are never kept: unmount() empties them) */
function filterBar() {
  if (bar) return bar;
  bar = Office.filterBar({
    key: 'cleanup.where',
    label: T('where.filter_label'),
    only: { text: T('where.only_odd'), hint: T('where.only_odd_hint') },
    onChange: () => { if (view) sw.render(); },
  });
  return bar;
}

/** A corner drawn once more, only counting: its rows that fit the bar's words (all: every row) and those that need a look */
function countRows(id, all) {
  const keep = shown;
  counting = { total: 0, odd: 0 };
  suspend = !!all;
  try { CORNERS()[id](el('div')); } finally { suspend = false; }
  const c = counting;
  counting = null;
  shown = keep;
  return c;
}

/** The corner that holds the bar: the one chosen, or — nothing chosen, every corner shown — the first; another tile: none */
function barCorner() {
  const a = sw.active();
  return SECTIONS.includes(a) ? a : a ? '' : SECTIONS[0];
}

/** A corner — a section of the switcher: its head (the tile's summary under it), the bar (see barCorner), its rows */
function whereCorner(id) {
  const s = Office.place(`where-corner.${id}`, el('section', 'section'));   // its rows' and groups' place: theirs, not another corner's
  s.id = 'clw-c-' + id;
  s.appendChild(Office.sectionHead(T('where.section.' + id), sectionSummary(id).sub, { place: `where.head.${id}` }));
  if (barCorner() === id) {
    // what the bar filters: this corner, or every corner while no tile is chosen — «n of m fit» over all of them
    const b = filterBar();
    const ids = sw.active() ? [id] : SECTIONS;
    const add = (list, k) => list.reduce((x, c) => x + c[k], 0);
    const all = ids.map((x) => countRows(x, true));
    const fit = ids.map((x) => countRows(x, false));
    s.appendChild(b.el);
    b.done(add(fit, onlyOdd() ? 'odd' : 'total'), add(all, 'total'), { onlyAvail: add(all, 'odd') > 0 });
  }
  shown = [];
  drawnRows = 0;
  const body = el('div', 'clw-corner');
  CORNERS()[id](body);
  if ((words().length || onlyOdd()) && !drawnRows && !body.querySelector('.empty')) body.appendChild(emptyNote(T('where.filter_none')));
  const mine = shown;
  if (mine.length > 1) s.appendChild(unfoldBar(body, mine, { cls: 'clw-unfold', keys: 'where.' }));
  s.appendChild(body);
  return s;
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
    b.onclick = () => { folderSort = id; Office.store(STORE + 'folder_sort', id); sw.render(); };
    sort.appendChild(b);
  }
  const unused = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = onlyUnused;
  cb.onchange = () => { onlyUnused = cb.checked; sw.render(); };
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
  if (counting) { counting.total += users.length; return; }
  drawnRows += users.length;
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
  box.appendChild(group(T('where.cron_jobs'), T('where.cron_meta'), cronRows, { closed: !words().length }));
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
  if (p.key === 'failed_past') return T('where.smart.failed_past', { id: p.id, name: p.name });
  return T('where.smart.' + p.key, { raw: p.raw, n: Number(p.raw) || 0 });     // n: the plural of a count (nvme_media)
}

/**
 * The chip for an array disk a resync is building (where.php waBuilding(): parity being built, a disk rebuilt, a new one
 * cleared) — accent, never a problem: Unraid calls the disk invalid until it is through
 */
function buildingChip(b) {
  const p = b.percent === null || b.percent === undefined ? '?' : fmt.number(b.percent, 1);
  const tip = [b.paused ? T('where.building.paused') : (b.eta ? T('where.building.eta', { time: fmt.duration(b.eta) }) : ''), T('where.building.hint')]
    .filter(Boolean).join(' — ');
  return chip(T('where.building.' + b.what, { p }), 'accent', tip);
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
      d.building ? buildingChip(d.building) : (d.status && d.status !== 'DISK_OK' ? chip(d.status, 'danger') : null),
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
        ['NVMe', nv && nv.used !== undefined ? T('where.nvme_line', { used: nv.used, spare: nv.spare, media: nOf('media_errors', nv.media_errors), unsafe: nOf('unsafe_shutdowns', nv.unsafe_shutdowns), written: nv.written || '?' }) : null],
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

/** What a look holds for the search (core.js «items», the part «where»): the containers, the disks, the appdata folders */
function items(s) {
  if (!s || !Array.isArray(s.shares)) return [];
  const out = [];
  s.shares.forEach((x) => out.push({ text: x.name, sub: x.storage ? `${T('where.section.shares')} · ${storageText(x.storage)}` : T('where.section.shares'),
    route: `#/${ID}/where/shares`, anchor: 'share:' + x.name,
    words: [x.comment, x.storage && x.storage.exclusive ? 'exclusive ' + T('where.exclusive') : '', x.smb && x.smb.timemachine_limit ? 'Time Machine' : ''].filter(Boolean).join(' ') }));
  (s.containers || []).forEach((c) => out.push({ text: c.name, sub: `${T('where.section.docker')} · ${c.state === 'running' ? T('where.state.running') : c.state}`,
    route: `#/${ID}/where/docker`, anchor: 'container:' + c.name, words: c.image }));
  const order = ['Parity', 'Data', 'Cache', 'Boot', 'Unassigned'];
  ((s.health && s.health.devices) || []).forEach((d) => out.push({ text: d.name + (d.roles.length ? ` · ${d.roles.join(', ')}` : ''),
    sub: `${T('where.section.disks')} · ${T('where.type.' + (order.includes(d.type) ? d.type : 'Other'))}`, route: `#/${ID}/where/disks`, anchor: 'disk:' + d.device }));
  const appdata = (s.folders || []).find((f) => f.appdata);
  if (appdata) appdata.folders.forEach((x) => out.push({ text: x.name, sub: `${T('where.section.folders')} · ${appdata.share}`,
    route: `#/${ID}/where/folders`, anchor: 'folder:' + x.real }));
  return out.slice(0, WHERE_ITEMS);
}

return {
  build, load, render, tour, bubble, reception, helpItems, sleeping, items, findings,
  /** her tiles as places of the search (#/cleanup/where/<tile>; «Where things are» only while she knows places) */
  places: () => TILES.map((id) => ({ kind: 'tile', key: tileKey(id), route: `#/${ID}/where/${id}`, anchor: `where.${id}`, part: 'part.where',
    ...(id === 'places' ? { shown: () => !state || !!state.locations } : {}) })),
  /** from the desk's mount(): a tile's sub-route (#/cleanup/where/<tile>; an old #/cleanup/where/stat:<tile> is that
      tile's corner) opens it as a click does — true; else the tile kept (the start: «Where things are») */
  mount(sub) {
    const m = /^stat:(\w+)$/.exec(String(sub || ''));
    return sw.mount(m && STAT_CORNERS[m[1]] ? STAT_CORNERS[m[1]] : sub);
  },
  /** the bar's words: a link fills them (no onChange — the page draws anew), '' clears them and its switch */
  setWords(text) { filterBar().set(text ? { text } : { text: '', only: false }); },
  words: () => words().join(' '),
  /** the filter changed: her tiles and sections anew */
  filtered() { if (view) sw.render(); },
  has: () => !!state,
  setHooks(h) { hooks = { ...hooks, ...h }; },
  /** the visit ends: the bar's words and its switch go (never kept) */
  unmount() { view = null; clearTimeout(sizeTimer); if (bar) bar.reset(); },
  /** tests/run.php (testCleanupTiles): the switcher and what each tile says */
  test: { sw, faces: () => (state ? Object.fromEntries(TILES.map((id) => [id, tileFace(id)])) : null) },
};
})();

// her places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys): her two
// parts, each tile through its sub-route (#/cleanup/tidy/<room>, #/cleanup/where/<corner>)
Office.places(ID, [
  { kind: 'section', key: 'part.where', route: '#/cleanup/where' },
  { kind: 'section', key: 'where.now', route: '#/cleanup/where' },
  { kind: 'section', key: 'where.adv.title', route: '#/cleanup/where/advice' },
  ...Where.places(),
  { kind: 'section', key: 'part.tidy', route: '#/cleanup/tidy' },
  { kind: 'section', key: 'rooms', route: '#/cleanup/tidy' },
  // a room only while she shows its tile (visible(): VMs with the VM service, Docker's rooms with Docker, what partners
  // left and what restores left only when there is some); her state not known yet: listed
  ...SECTIONS.map((sec) => ({ kind: 'tile', key: `section.${sec}`, route: `#/cleanup/tidy/${sec}`, anchor: `room.${sec}`, text: `section.${sec}_sub`,
    shown: () => !state || visible(sec), part: 'part.tidy' })),
  { kind: 'help', key: 'part.where', text: 'part.where_sub' },
  { kind: 'help', key: 'part.tidy', text: 'part.tidy_sub' },
  ...['labels', 'tiles', 'copy', 'rows', 'tour', 'asleep'].map((x) => ({ kind: 'help', key: `where.help.${x}`, text: `where.help.${x}_text` })),
  ...['order', 'trash', 'check', 'loop', 'sizes', 'safe'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...['templates', 'stacks', 'appdata', 'vms', 'scripts', 'flash', 'docker', 'icons', 'leftovers', 'partners']
    .map((x) => ({ kind: 'help', key: `section.${x}`, text: `help.${x}_text` })),
]);

// what her states hold for the search (core.js «items»): in her rooms what she would clear away (by its name, its room's
// tile opened through its sub-route); from «Where is what» (the part «where») the containers, the disks, the appdata folders
// — together at most 200 (120 + 80)
Office.placesFrom(ID, (s, part) => {
  if (part === 'where') return Where.items(s);
  if (!s.docker || typeof s.docker !== 'object') return [];
  const out = [];
  for (const sec of ROOMS) {
    if (!visible(sec, s)) continue;
    entries(sec, s).filter((e) => CANDIDATES[sec].includes(e.category)).forEach((e) => out.push({ text: label(e),
      sub: `${T('section.' + sec)} · ${T(`cat.${sec}.${e.category}`)}`, route: `#/${ID}/tidy/${sec}`, anchor: `item:${e.id}` }));
  }
  return out.slice(0, ROOM_ITEMS);
});

// tests/run.php runs this under node (Unraid's own) - never set in a browser
if (globalThis.OFFICE_DESK_TESTS) {
  // query(): the rooms' words, and the corner's too where they differ (they never should)
  const both = () => { const a = words().join(' '), b = Where.words(); return a === b ? a : `${a}|${b}`; };
  globalThis.OFFICE_DESK_TESTS.cleanup = { query: both, setQuery: (q) => { roomBar().set({ text: q }); Where.setWords(q); }, setState: (s) => { state = s; },
    findings: () => Where.findings(), where: Where.test, tidy: tidySw, page: () => (view ? view.page : null), greetOk, bubbleText };
}
})();
