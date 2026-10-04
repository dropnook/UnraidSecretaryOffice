/* Ms. Dustdevil — clears away what nobody uses any more: Docker templates
   without a container, Compose stacks without containers, appdata folders
   nothing names. Everything goes into her trash first (renamed on the same
   filesystem), from where it can be put back or emptied for good.
   The agent part lives in agent/desks/cleanup.php. */
(() => {
'use strict';

const ID = 'cleanup';
const T = Office.scope(ID);
const { el, fmt } = Office;
const SECTIONS = ['templates', 'stacks', 'appdata', 'trash'];
const ICONS = { templates: '📄', stacks: '🧩', appdata: '🗃️', trash: '🗑️' };
const GROUPS = {
  templates: ['leftover', 'unused', 'duplicate', 'noname', 'unknown', 'in_use'],
  stacks: ['leftover', 'broken', 'unused', 'unknown', 'in_use'],
  appdata: ['unused', 'check', 'unknown', 'used'],
};
const CANDIDATES = {
  templates: ['leftover', 'unused', 'duplicate', 'noname'],
  stacks: ['leftover', 'broken', 'unused'],
  appdata: ['unused', 'check'],
};
const CLOSED = ['in_use', 'used', 'unknown'];        // folded until opened
const KIND_ICONS = { container: '🐳', template: '📄', stack: '🧩', compose: '🧩', flash: '💾', vm: '🖥️' };
const POLL_MS = 3000;

let state = null;
let view = null;
let section = SECTIONS.includes(Office.store('cleanup.section')) ? Office.store('cleanup.section') : '';   // '' = none open
let folded = Office.storeJson('cleanup.folded') || {};
const selection = new Set();
const expanded = new Set();
let shown = [];             // rows that can unfold: { open(), set(bool) }
let busy = false;
let timer = null;

// ------------------------------------------------------------------ loading
async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) setState(j.state);
  else if (view) render();
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
  if (sec === 'templates') return state.templates.list;
  if (sec === 'stacks') return state.stacks.list;
  if (sec === 'appdata') return state.appdata.list;
  return [];
}
const candidates = (sec) => entries(sec).filter((e) => CANDIDATES[sec].includes(e.category));
const selectable = (e) => !!state && e.why === null && state.docker && !state.backup_running;
const label = (sec, e) => (sec === 'templates' ? e.file : sec === 'stacks' ? e.folder : e.name);
const sum = (list) => list.reduce((a, e) => a + (e.bytes || 0), 0);

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
  if (state.backup_running) return chip('⏸ ' + T('why.backup'), 'quiet', T('why.backup_text'));
  if (!e.why) return null;
  return chip((e.why === 'measuring' || e.why === 'checking' ? '⏳ ' : '') + T('why.' + e.why), e.why === 'dataset' || e.why === 'asleep' ? '' : 'quiet', T('why.' + e.why + '_text'));
}

function usedChips(list, more) {
  const out = list.slice(0, 4).map((u) => chip(`${KIND_ICONS[u.kind] || '•'} ${u.name}`, u.weak ? 'quiet' : 'accent', T('kind.' + u.kind) + (u.weak ? ' · ' + T('weak') : '')));
  const rest = list.length - 4 + (more || 0);
  if (rest > 0) out.push(chip(`+${rest}`, 'quiet', T('more_names', { n: rest })));
  return out;
}

function noteChips(f) {
  return f.notes.map((n) => {
    if (n.why === 'same_name') return chip(T('note.same_name', { icon: KIND_ICONS[n.kind] || '•', name: n.name }), 'warn', T('note.same_name_text'));
    if (n.why === 'weak') return chip(T('note.weak'), 'warn', T('note.weak_text', { names: n.names.join(', ') }));
    return chip(T('note.fresh', { n: n.days }), 'warn', T('note.fresh_text', { days: 30 }));
  });
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
    const t = candidates('templates').length;
    const s = candidates('stacks').length;
    const a = candidates('appdata');
    if (t) facts.push(T('fact.templates', { n: t }));
    if (s) facts.push(T('fact.stacks', { n: s }));
    if (a.length) facts.push(T('fact.folders', { n: a.length, size: fmt.size(sum(a)) }));
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
  const head = Office.deskHead({ id: ID, icon: Office.desks.get(ID).icon }, { actions: [v.scanBtn] });
  v.bubble = head.bubble;
  root.appendChild(head.head);
  root.appendChild(Office.pageHelp(ID, [
    [T('help.order'), T('help.order_text')],
    [T('help.trash'), T('help.trash_text')],
    [T('section.templates'), T('help.templates_text')],
    [T('section.stacks'), T('help.stacks_text')],
    [T('section.appdata'), T('help.appdata_text')],
    [T('help.check'), T('help.check_text')],
    [T('help.sizes'), T('help.sizes_text')],
    [T('help.safe'), T('help.safe_text')],
  ]));

  v.notice = el('div');
  root.appendChild(v.notice);

  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('rooms'), T('rooms_sub')));
  v.tiles = el('div', 'cards');
  v.body = el('div', 'section');
  s.append(v.tiles, v.body);
  root.appendChild(s);
  return v;
}

function render() {
  if (!view) return;
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
  if (!state.docker) return T('bubble.docker_down');
  const found = [];
  const t = candidates('templates').length;
  const s = candidates('stacks').length;
  const a = candidates('appdata');
  if (t) found.push(T('bubble.templates', { n: t }));
  if (s) found.push(T('bubble.stacks', { n: s }));
  if (a.length) found.push(T('bubble.folders', { n: a.length, size: fmt.size(sum(a)) }));
  let text = found.length ? T('bubble.found', { list: found.join(', ') }) : T('bubble.spotless');
  if (state.trash.runs.length && state.trash.bytes !== null) text += ' ' + T('bubble.trash', { size: fmt.size(state.trash.bytes) });
  if (state.jobs.busy) text += ' ' + T('bubble.busy');
  return text;
}

function renderNotice() {
  const box = view.notice;
  box.innerHTML = '';
  if (!state) return;
  if (state.backup_running) box.appendChild(el('p', 'callout', T('notice.backup')));
  if (!state.docker) box.appendChild(el('p', 'callout warn', T('notice.docker_down')));
}

// ------------------------------------------------------------------ tiles
function tileLine(sec) {
  if (sec === 'trash') {
    const runs = state.trash.runs;
    return [runs.length ? T('tile.trash', { n: runs.length }) : T('tile.trash_empty'), !runs.length ? '' : state.trash.bytes === null ? '…' : fmt.size(state.trash.bytes)];
  }
  const all = entries(sec);
  const c = candidates(sec);
  if (!all.length) return [T('tile.none'), ''];
  return [c.length ? T('tile.look', { n: c.length }) : T('tile.tidy'), sec === 'templates' || !c.length ? T('tile.total', { n: all.length }) : fmt.size(sum(c))];
}

function renderTiles() {
  const box = view.tiles;
  box.innerHTML = '';
  if (!state) return;
  for (const sec of SECTIONS) {
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
  if (!section) return;
  const right = [];
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
  if (section === 'appdata') body.appendChild(appdataInfo());
  if (section === 'stacks' && !state.stacks.exists) { body.appendChild(emptyNote(T('empty.no_compose', { path: state.stacks.root }))); return; }
  if (section === 'trash') renderTrash(body);
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

function appdataInfo() {
  const a = state.appdata;
  const box = el('div', 'cl-info');
  const places = a.places.map((p) => p.path).join(', ') || '–';
  box.appendChild(el('p', 'role', T('appdata.where', { share: a.share, places })));
  if (a.mounters.length) box.appendChild(el('p', 'role', T('appdata.mounters', { names: a.mounters.join(', ') })));
  if (!a.complete && state.docker) box.appendChild(el('p', 'role', '⏳ ' + T('appdata.checking')));
  else if (a.flash_at) box.appendChild(el('p', 'role', T('appdata.searched', { when: fmt.relative(a.flash_at) })));
  if (a.asleep.length) {
    const p = el('p', 'callout');
    p.append(T('appdata.asleep', { disks: a.asleep.join(', ') }), ' ');
    const b = el('button', 'btn small plain', T('appdata.wake'));
    b.type = 'button';
    b.disabled = !Office.agent.running || busy;
    b.onclick = () => scan(true);
    p.appendChild(b);
    box.appendChild(p);
  }
  return box;
}

function renderList(body, sec) {
  const list = entries(sec);
  if (!list.length) { body.appendChild(emptyNote(T('empty.' + sec))); return; }
  const box = el('div', 'box');
  for (const cat of GROUPS[sec]) {
    const items = list.filter((e) => e.category === cat);
    if (items.length) box.appendChild(group(sec, cat, items));
  }
  body.appendChild(box);
}

function group(sec, cat, items) {
  const key = `${sec}.${cat}`;
  const closed = folded[key] ?? CLOSED.includes(cat);
  const box = el('div', 'group' + (closed ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');

  // a whole group at once — only where nothing is still in use
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
  if (sec !== 'templates' && items.every((e) => e.bytes !== null)) meta.push(fmt.size(sum(items)));     // only when all are measured
  mid.append(title, el('div', 'group-meta', meta.join(' · ')));
  head.append(cb, el('span', 'group-arrow', '▼'), mid);

  if (sec === 'appdata' && cat === 'used') {
    const missing = items.filter((f) => f.bytes === null && !f.measuring);
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
  items.forEach((e) => rows.appendChild(row(sec, e, sync)));
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

/** A row: the checkbox selects, a click anywhere else unfolds the details */
function row(sec, e, groupSync) {
  const r = el('div', 'row unfolds' + (selection.has(e.id) ? ' selected' : ''));
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = selection.has(e.id);
  cb.disabled = !selectable(e);
  cb.setAttribute('aria-label', T('select_one', { name: label(sec, e) }));
  cb.onchange = () => {
    if (cb.checked) selection.add(e.id); else selection.delete(e.id);
    r.classList.toggle('selected', cb.checked);
    groupSync();
    updateSelbar();
  };

  const main = el('div', 'row-main');
  const name = el('div', 'row-name link', label(sec, e));
  name.title = T('details');
  const meta = el('div', 'row-meta');
  const figures = el('div', 'figures');
  ({ templates: templateMeta, stacks: stackMeta, appdata: folderMeta })[sec](e, meta, figures);
  const why = whyChip(e);
  if (why) meta.appendChild(why);
  main.append(name, meta);

  const more = el('button', 'more', '⋯');
  more.type = 'button';
  more.setAttribute('aria-label', T('actions'));
  const items = menuItems(sec, e);
  more.disabled = !items.length;
  more.onclick = (ev) => { ev.stopPropagation(); Office.menu(ev, items); };
  r.append(cb, main, figures, more);

  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; expanded.delete(e.id); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail');
      box.appendChild(({ templates: templateDetail, stacks: stackDetail, appdata: folderDetail })[sec](e));
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

function menuItems(sec, e) {
  const items = [];
  if (sec === 'templates') items.push({ text: T('show_xml'), act: () => showFile(sec, e) });
  if (sec === 'stacks' && e.file) items.push({ text: T('show_compose'), act: () => showFile(sec, e) });
  if (sec === 'appdata') items.push({ text: T('measure_again'), act: () => measure([e.id]), disabled: !Office.agent.running || e.measuring });
  const path = sec === 'templates' ? e.path : sec === 'stacks' ? e.dir : (e.parts[0] || {}).path;
  if (path) items.push({ text: Office.t('common.copy_path'), act: () => Office.copy(path) });
  return items;
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

// ------------------------------------------------------------------ appdata
function folderMeta(f, meta, figures) {
  usedChips(f.used_by, f.used_more).forEach((c) => meta.appendChild(c));
  noteChips(f).forEach((c) => meta.appendChild(c));
  if (f.parts.length > 1) meta.appendChild(chip(f.parts.map((p) => p.root).join(' + '), 'quiet', T('parts_text')));
  const bc = Office.backupChip(f.parts[0] && f.parts[0].backup);
  if (bc) meta.appendChild(bc);
  if (f.newest) meta.appendChild(el('span', '', T('changed', { when: fmt.relative(f.newest) })));
  if (f.bytes !== null) {
    figures.append(el('b', '', fmt.size(f.bytes)), el('span', '', T('files', { n: f.files })));
    if (f.partial) figures.title = T('partial');
  } else if (f.measuring) {
    figures.append(el('b', '', '…'), el('span', '', T('measuring')));
  }
}

function folderDetail(f) {
  const box = el('div');
  const by = f.used_by.map((u) => `${KIND_ICONS[u.kind] || '•'} ${T('kind.' + u.kind)}: ${u.name}${u.weak ? ' (' + T('weak') + ')' : ''}`);
  if (f.used_more) by.push(T('more_names', { n: f.used_more }));
  box.appendChild(kv([
    [T('d.where'), lines(f.parts.map((p) => p.path + (p.dataset ? `  (${T('d.dataset', { name: p.dataset })})` : '')))],
    [T('d.size'), f.bytes !== null ? `${fmt.size(f.bytes)} · ${T('files', { n: f.files })}${f.partial ? ' · ' + T('partial') : ''}` : (f.measuring ? T('measuring') : T('d.not_measured'))],
    [T('d.newest'), when(f.newest)],
    [T('d.measured'), f.measured_at ? fmt.relative(f.measured_at) : ''],
    [T('d.newest_files'), f.top.length ? lines(f.top.map(([t, p]) => `${fmt.date(t)}  ${p}`)) : null],
    [T('d.used_by'), by.length ? lines(by) : T('d.used_by_none')],
  ]));
  for (const n of f.notes) {
    const text = n.why === 'same_name' ? T('note.same_name_text') : n.why === 'weak' ? T('note.weak_text', { names: n.names.join(', ') }) : T('note.fresh_text', { days: 30 });
    box.appendChild(el('p', 'role', text));
  }
  return box;
}

// ------------------------------------------------------------------ selection
function updateSelbar() {
  if (!view || !section || section === 'trash' || !selection.size) { Office.selbar(null); return; }
  const list = entries(section).filter((e) => selection.has(e.id));
  const bytes = sum(list);
  const forced = list.filter((e) => e.force).length;
  const sub = [section !== 'templates' && bytes ? fmt.size(bytes) : '', forced ? T('selected_in_use', { n: forced }) : ''].filter(Boolean).join(' · ');
  Office.selbar({
    title: T('selected', { n: list.length }),
    sub,
    buttons: [
      { text: T('clear'), kind: 'plain', act: () => { selection.clear(); renderSection(); updateSelbar(); } },
      { text: T('park.button'), kind: '', act: parkDialog, disabled: !Office.agent.running || busy || !state || state.backup_running },
    ],
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

async function showFile(sec, e) {
  const j = await Office.api.post(`${ID}.detail`, { id: e.id });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  const box = el('div');
  j.files.forEach((f) => {
    box.appendChild(el('p', 'role mono', f.path + (f.cut ? ' · ' + T('cut') : '')));
    box.appendChild(el('pre', 'code', f.text));
  });
  if (!j.files.length) box.appendChild(el('p', 'role', T('stack.no_file')));
  if (j.env) box.appendChild(el('p', 'role', T('env_hidden', { path: j.env })));
  Office.dialog({ title: label(sec, e), body: box, wide: true });
}

function parkDialog() {
  const sec = section;
  const list = entries(sec).filter((e) => selection.has(e.id));
  if (!list.length) return;
  const forced = list.filter((e) => e.force);
  const box = el('div');
  box.appendChild(el('p', '', T('park.text_' + sec, { n: list.length })));
  const ul = el('ul', 'shortlist');
  list.forEach((e) => {
    const li = el('li', '', label(sec, e));
    li.appendChild(el('span', '', e.bytes !== null && e.bytes !== undefined && sec !== 'templates' ? fmt.size(e.bytes) : ''));
    ul.appendChild(li);
  });
  box.appendChild(ul);
  const running = sec === 'stacks' ? list.filter((s) => s.containers.length) : [];
  if (running.length) box.appendChild(el('p', 'callout', T('park.down', { names: running.map((s) => s.folder).join(', ') })));
  if (forced.length) box.appendChild(el('p', 'callout warn', T('park.in_use_' + sec, { names: forced.map((e) => label(sec, e)).join(', ') })));
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
  const box = el('div', 'box');
  runs.forEach((run) => box.appendChild(trashGroup(run)));
  body.appendChild(box);
}

function trashGroup(run) {
  const key = 'trash.' + run.id;
  const closed = folded[key] ?? false;
  const box = el('div', 'group' + (closed ? ' closed' : ''));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');
  const mid = el('div', 'group-mid');
  const title = el('div', 'group-title');
  title.append(el('span', '', `${fmt.date(run.time)} · ${fmt.relative(run.time)}`));
  if (run.legacy) title.appendChild(chip(T('trash.legacy'), 'quiet', T('trash.legacy_text')));
  if (run.purging) title.appendChild(chip('⏳ ' + T('trash.purging'), 'warn', T('trash.purging_text')));
  const meta = [T('where.' + run.where), T('items', { n: run.items.length })];
  if (run.bytes !== null) meta.push(fmt.size(run.bytes));
  else if (run.measuring) meta.push(T('measuring'));
  mid.append(title, el('div', 'group-meta', meta.join(' · ')), el('div', 'group-meta mono', run.path));
  head.append(el('span', 'group-arrow', '▼'), mid);
  if (!run.purging) {
    const b = el('button', 'btn small danger plain', T('purge.button'));
    b.type = 'button';
    b.disabled = !Office.agent.running || state.backup_running;
    b.onclick = (e) => { e.stopPropagation(); purgeDialog([run]); };
    head.appendChild(b);
  }
  const rows = el('div', 'group-rows');
  if (!run.items.length) rows.appendChild(el('div', 'row nocheck', T('trash.no_items')));
  run.items.forEach((it) => rows.appendChild(trashRow(run, it)));
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
  meta.appendChild(chip(`${{ template: '📄', stack: '🧩', appdata: '🗃️' }[it.kind] || '•'} ${T('item.' + it.kind)}`, 'quiet'));
  if (it.label && it.label !== it.name && it.kind !== 'appdata') meta.appendChild(el('span', '', it.label));
  if (it.from) meta.appendChild(el('span', 'mono', T('item.from', { path: it.from })));
  else if (!run.legacy) meta.appendChild(el('span', '', T('item.no_manifest')));
  if (!it.present) meta.appendChild(chip(T('item.gone'), 'warn', T('item.gone_text')));
  if (it.volumes.length) meta.appendChild(chip(T('stack.volumes', { n: it.volumes.length }), 'quiet', T('item.volumes_text', { names: it.volumes.join(', ') })));
  main.appendChild(meta);
  const act = el('div');
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
  else Office.toast(T(it.kind === 'stack' ? 'restore.done_stack' : 'restore.done', { name: it.name }));
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
  if (runs.some((r) => r.where === 'appdata')) box.appendChild(el('p', 'role', T('purge.snapshots')));
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
