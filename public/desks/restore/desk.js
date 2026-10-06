/* Mr. Restori — brings back what is gone, from what Mr. Backupsy's engine keeps:
   the packages per app and VM in the backup place (and earlier nights' packages in
   that share's snapshots), the local snapshots of their folders, and Kopia.
   Organised by app and VM: what can come back from where, with dates — and he brings it back
   himself: every restore is shown as a preview first (what happens, what goes aside where, how
   long what stops), confirmed, then runs as a job whose journal this page follows; «Put back»
   undoes it. The agent part lives in agent/desks/restore.php. */
(() => {
'use strict';

const ID = 'restore';
const T = Office.scope(ID);
const { el, fmt } = Office;
const SECTIONS = ['apps', 'vms', 'kopia', 'journal', 'move'];
const ICONS = { apps: '📦', vms: '🖥️', kopia: '☁️', journal: '📓', move: '🚚' };
const HOLDERS = ['backup', 'check', 'dryrun', 'setup', 'restore', 'other'];
const JOB_POLL = 2000;
const TPL = '/boot/config/plugins/dockerMan/templates-user';

let state = null;
let view = null;
let section = Office.store('restore.section');
if (section === null) section = 'apps';       // nothing chosen yet: the apps
const expanded = new Set();                   // rows unfolded on this page
const versions = new Map();                   // "app:<id>" -> {loading, list, error}
let shown = [];                               // the rows of the open section, for "Unfold all"
let job = null;                               // the restore going on (or the last one): data/restore-job.json
let jobTimer = null;
const journals = new Map();                   // id -> {loading, journal, log, error} — a journal unfolded on the page
const journalShown = new Map();               // id -> {box, fill, fail}: the journal's box drawn last (the page re-renders while one loads)

// ------------------------------------------------------------------ loading
async function load(fresh) {
  if (fresh) journals.forEach((v, id) => { if (v.error) journals.delete(id); });   // a journal that failed to load is asked again
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  if (view) render();
  if (state && state.running && !jobTimer) pollJob();
  return j;
}

/** While a restore runs: its notice, the tiles and the open section anew — the page stays where it is */
function refreshLive() {
  if (!view || !view.tiles || !state) { if (view) render(); return; }
  view.notes.innerHTML = '';
  notices().forEach((n) => view.notes.appendChild(n));
  const keep = view.body.querySelectorAll('details[open]').length;
  Office.keepInPlace(view.tiles, () => {
    renderTiles();
    if (section === 'journal' || !keep) renderSection();
  });
}

/** Follows a restore while it runs: its journal straight from disk every two seconds (api part "job") */
async function pollJob() {
  clearTimeout(jobTimer);
  jobTimer = null;
  const j = await Office.api.get({ a: 'part', desk: ID, part: 'job' });
  const was = job && job.result;
  const sig = (x) => (x ? JSON.stringify([x.id, x.result, (x.steps || []).map((s) => [s.state, s.progress || null])]) : '');
  const before = sig(job);
  if (j.ok && j.part) job = j.part;
  const live = job && ['queued', 'running'].includes(job.result);
  if (view) {
    if (sig(job) !== before && (live || was !== (job && job.result))) {
      journals.delete(job && job.id);
      refreshLive();
    }
    if (live) jobTimer = setTimeout(pollJob, JOB_POLL);
    else if (was && ['queued', 'running'].includes(was)) {
      Office.toast(T('job.done.' + (job.result === 'ok' ? 'ok' : job.result === 'warnings' ? 'warnings' : 'failed'), { what: job.what }), job.result !== 'ok');
      await load(true);
    }
  }
}

// ------------------------------------------------------------------ helpers
function chip(text, cls, title) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (title) c.title = title;
  return c;
}

function button(text, kind, act) {
  const b = el('button', 'btn' + (kind ? ' ' + kind : ''), text);
  b.type = 'button';
  b.onclick = act;
  return b;
}

function sectionBox(title, sub, ...extra) {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(title, sub, ...extra));
  return s;
}

/** A block of commands with a copy button */
function codeBlock(text) {
  const wrap = el('div', 'rs-code');
  wrap.appendChild(el('pre', 'code', text));
  wrap.appendChild(button(Office.t('common.copy'), 'small plain', () => Office.copy(text)));
  return wrap;
}

/** A path you can copy with a click (it doesn't fold the row it sits in) */
function copyCode(text) {
  const c = el('code', 'rs-copy', text);
  c.title = Office.t('common.copy');
  c.dataset.own = '1';
  c.onclick = () => Office.copy(text);
  return c;
}

/** A numbered step of a guide: title, text, commands to copy */
function rstep(title, text, cmd) {
  const s = el('div', 'rs-step');
  s.appendChild(el('div', 'rs-step-title', title));
  if (text) s.appendChild(el('div', 'role', text));
  if (cmd) s.appendChild(codeBlock(cmd));
  return s;
}

/** A folded part of a detail: a summary to click, the rest under it */
function fold(title, ...kids) {
  const d = el('details', 'rs-how');
  d.appendChild(el('summary', '', title));
  kids.filter(Boolean).forEach((k) => d.appendChild(k));
  return d;
}

/** A block inside a row's details: a small title, then what comes back from there */
function block(title, sub) {
  const b = el('div', 'rs-block');
  b.appendChild(el('div', 'rs-block-title', title));
  if (sub) b.appendChild(el('div', 'role', sub));
  return b;
}

function dl(items) {
  const d = el('dl', 'kv');
  items.filter(Boolean).forEach(([term, ...parts]) => {
    d.appendChild(el('dt', '', term));
    const dd = el('dd');
    parts.filter((p) => p !== null && p !== undefined && p !== '').forEach((p) => dd.append(p));
    d.appendChild(dd);
  });
  return d;
}

const q = (s) => `'${String(s).replace(/'/g, "'\\''")}'`;
const date = (t) => (t ? fmt.date(t) : '?');

/** Names as the language lists them: "a", "a and b", "a, b and c" (the browser's Intl, commas where it has none) */
function andList(names) {
  try { return new Intl.ListFormat(Office.locale, { style: 'long', type: 'conjunction' }).format(names); } catch (e) { return names.join(', '); }
}
const apps = () => (state && state.apps) || [];
const vms = () => (state && state.vms) || [];
const engine = () => (state && state.engine) || {};
const kopia = () => (state && state.kopia) || {};
// the Container Path Kopia names its sources after: the real one (the engine's last compare, or the container's
// mapping of mount_root) - /uso only as an example while it isn't known
const KOPIA_ROOT_EXAMPLE = '/uso';
const kopiaRoot = () => kopia().root || KOPIA_ROOT_EXAMPLE;
const kopiaRootNote = () => (kopia().root ? null : el('p', 'role', T('kg.root_example', { root: KOPIA_ROOT_EXAMPLE, path: engine().mount_root || '' })));
const snapshotLink = (text) => Object.assign(el('a', '', text), { href: '#/snapshot' });

/** All local snapshots of a set of folders: how many, the newest */
function snapSummary(folders) {
  const all = folders.flatMap((f) => f.places || []);
  const n = all.reduce((s, p) => s + (p.count || 0), 0);
  const newest = Math.max(0, ...folders.map((f) => f.newest || 0));
  const asleep = folders.some((f) => f.asleep);
  return { n, newest, asleep };
}

/** The newest Kopia copy among the sources of an app or VM */
function kopiaNewest(sources) {
  return Math.max(0, ...(sources || []).map((s) => s.last || 0));
}

function snapsChip(folders) {
  if (!folders.length) return null;
  const s = snapSummary(folders);
  if (s.asleep && !s.n) return chip(T('chip.snaps_asleep'), 'quiet', T('chip.snaps_asleep_hint'));
  return s.n ? chip(T('chip.snaps', { n: s.n, when: fmt.relative(s.newest) }), 'ok', T('chip.snaps_hint'))
    : chip(T('chip.no_snaps'), 'warn', T('chip.no_snaps_hint'));
}

/** When Kopia last copied it: the sources that hold that part (covers: all — its own source —, data, package) */
function kopiaChip(sources, covers) {
  const list = (sources || []).filter((s) => covers.includes(s.covers || 'data'));
  if (!kopia().enabled || !list.length) return null;
  const t = kopiaNewest(list);
  const hint = T('chip.kopia_hint_' + (list.some((s) => s.covers === 'all') ? 'all' : covers[0] === 'package' ? 'package' : 'data'),
    { source: list.map((s) => `${kopiaRoot()}/${s.source}`).join(', ') });
  return t ? chip(T('chip.kopia', { when: fmt.relative(t) }), 'ok', hint) : chip(T('chip.kopia_never'), 'warn', T('chip.kopia_never_hint') + ' ' + hint);
}

/** Chips about one thing, with a small label in front: "Package: …", "Data: …" */
function chipGroup(label, chips) {
  const list = chips.filter(Boolean);
  if (!list.length) return null;
  const g = el('span', 'rs-chipgroup');
  g.appendChild(el('span', 'rs-chiplabel', label));
  list.forEach((c) => g.appendChild(c));
  return g;
}

/** The restore going on now: the job file while it says so, else what the agent saw */
function runningJob() {
  if (job && ['queued', 'running'].includes(job.result)) {
    const at = (job.steps || []).filter((x) => ['running', 'ok', 'warning', 'failed'].includes(x.state)).length;
    return { id: job.id, kind: job.kind, what: job.what, step: at, steps: (job.steps || []).length };
  }
  return state && state.running ? state.running : null;
}

/** Who holds the engine's lock, unless it is a restore of his own */
function otherHolder() {
  const h = engine().holder;
  if (!h || runningJob()) return null;
  return HOLDERS.includes(h.holder) ? h.holder : 'other';
}

/** May he start a restore now? (the agent checks again) */
const canRestore = () => !!(Office.agent.running && state && !runningJob() && !otherHolder());

/** A small button that opens a restore's preview */
function restoreButton(text, req, title) {
  const b = button(text, 'small', () => restoreDialog(req, title || text));
  b.dataset.own = '1';
  b.disabled = !canRestore();
  if (b.disabled) b.title = runningJob() ? T('rd.wait_restore') : otherHolder() ? T('notice.busy_' + otherHolder()) : '';
  return b;
}

/** The weakest protection among the folders of an app or VM, as on every desk */
function protectionOf(folders) {
  const order = ['none', 'local', 'offsite'];
  const levels = folders.map((f) => f.protection).filter(Boolean);
  if (!levels.length) return null;
  return levels.sort((a, b) => order.indexOf(a) - order.indexOf(b))[0];
}

// ------------------------------------------------------------------ bubble
function bubbleText() {
  if (!state) return T('bubble.loading');
  const e = engine();
  if (!e.found) return T('bubble.no_engine');
  if (state.place.asleep && !apps().length) return T('bubble.asleep');
  if (!apps().length && !vms().length) return T('bubble.no_packages');
  const run = runningJob();
  if (run) return T('bubble.restoring', { what: run.what, n: run.step, total: run.steps });
  const out = [T('bubble.ready', { apps: apps().length, vms: vms().length, when: date(state.run_time) })];
  if (otherHolder()) out.push(T('bubble.busy_' + otherHolder()));
  const gone = apps().filter((a) => !a.present).length + vms().filter((v) => v.state === 'missing').length;
  if (gone) out.push(T('bubble.gone', { n: gone }));
  return out.join(' ');
}

// ------------------------------------------------------------------ rendering
function render() {
  const root = view;
  root.innerHTML = '';
  shown = [];
  const again = button(T('look_again'), 'plain', async () => {
    again.disabled = true;
    await load(true);
  });
  again.disabled = !Office.agent.running;
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions: [again] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID, [
    [T('help.tiles'), T('help.tiles_text')],
    [T('help.row'), T('help.row_text')],
    [T('help.package'), T('help.package_text')],
    [T('help.snapshots'), T('help.snapshots_text')],
    [T('help.kopia'), T('help.kopia_text')],
    ...['offsite', 'local', 'none'].map((l) => [Office.backupChip(l), Office.t('protect.' + l + '_text')]),
    [T('help.commands'), T('help.commands_text')],
    [T('help.chips'), T('help.chips_text')],
    [T('help.restoring'), T('help.restoring_text')],
    [T('help.journal'), T('help.journal_text')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  const notes = el('div', 'rs-notices');
  notices().forEach((n) => notes.appendChild(n));
  root.appendChild(notes);
  view.notes = notes;
  const tiles = el('div', 'cards rs-tiles');
  root.appendChild(tiles);
  const body = el('div', 'rs-body');
  root.appendChild(body);
  view.tiles = tiles;
  view.body = body;
  renderTiles();
  renderSection();
}

function notices() {
  const out = [];
  const callout = (text, warn) => out.push(el('p', 'callout' + (warn ? ' warn' : ''), text));
  const e = engine();
  if (!e.found) callout(T('notice.no_engine'), true);
  else if (!state.place.base) callout(T('notice.no_place'), true);
  else if (state.place.asleep) callout(T('notice.asleep', { path: state.place.base }), false);
  else if (!state.place.found) callout(T('notice.no_packages', { path: state.place.base }), false);
  const run = runningJob();
  if (run) {
    const c = el('p', 'callout', T('notice.restoring', { what: run.what, n: run.step, total: run.steps }) + ' ');
    const a = el('a', '', T('notice.to_journal'));
    a.href = '#/restore';
    a.onclick = (ev) => { ev.preventDefault(); pick('journal'); };
    c.appendChild(a);
    out.push(c);
  } else if (otherHolder()) callout(T('notice.busy_' + otherHolder()), true);
  return out;
}

function tileLine(sec) {
  if (sec === 'apps') {
    const gone = apps().filter((a) => !a.present).length;
    return [T('tile.apps_line', { n: apps().length }), gone ? T('tile.gone', { n: gone }) : state.run_time ? fmt.relative(state.run_time) : ''];
  }
  if (sec === 'vms') {
    const gone = vms().filter((v) => v.state === 'missing').length;
    return [T('tile.vms_line', { n: vms().length }), gone ? T('tile.gone', { n: gone }) : ''];
  }
  if (sec === 'journal') {
    const run = runningJob();
    if (run) return [T('tile.journal_running', { what: run.what }), T('tile.journal_step', { n: run.step, total: run.steps })];
    const list = state.restores || [];
    return [T('tile.journal_line', { n: list.length }), list.length ? fmt.relative(list[0].created) : ''];
  }
  if (sec === 'kopia') {
    if (!kopia().enabled) return [T('tile.kopia_off'), ''];
    const t = Math.max(0, ...(state.shares || []).map((s) => s.last || 0), ...apps().map((a) => kopiaNewest(a.kopia)), ...vms().map((v) => kopiaNewest(v.kopia)));
    return [kopia().container ? T('tile.kopia_on', { name: kopia().container }) : T('tile.kopia_missing'), t ? fmt.relative(t) : ''];
  }
  return [T('tile.move_line'), ''];
}

function renderTiles() {
  const box = view.tiles;
  box.innerHTML = '';
  SECTIONS.forEach((sec) => {
    const card = el('button', 'card' + (section === sec ? ' active' : ''));
    card.type = 'button';
    card.setAttribute('aria-pressed', String(section === sec));
    const head = el('div', 'card-head');
    head.append(el('span', 'rs-tile-icon', ICONS[sec]), el('span', 'card-name', T('tile.' + sec)));
    const [line, right] = tileLine(sec);
    const fig = el('div', 'card-figures');
    fig.append(el('span', '', line), el('span', '', right));
    card.append(head, fig);
    card.onclick = () => pick(section === sec ? '' : sec);       // the open tile closes again
    box.appendChild(card);
  });
}

function pick(sec) {
  section = sec;
  Office.store('restore.section', sec);
  Office.keepInPlace(view.tiles, () => { renderTiles(); renderSection(); });
}

function renderSection() {
  const body = view.body;
  body.innerHTML = '';
  shown = [];
  if (section === 'apps') body.appendChild(appsSection());
  else if (section === 'vms') body.appendChild(vmsSection());
  else if (section === 'kopia') body.appendChild(kopiaSection());
  else if (section === 'journal') body.appendChild(journalSection());
  else if (section === 'move') body.appendChild(moveSection());
}

/** "Unfold all" / "Fold all" for the rows of a list */
function unfoldAll() {
  const b = button(T('unfold_all'), 'small plain', () => {
    const open = !shown.every((r) => r.open());
    Office.keepInPlace(b, () => shown.forEach((r) => r.set(open)));
    b.textContent = T(open ? 'fold_all' : 'unfold_all');
  });
  return b;
}

/** A row that unfolds to its details when clicked anywhere but its own buttons, links and fields */
function unfoldingRow(key, name, meta, detail) {
  const r = el('div', 'row nocheck unfolds rs-row');
  const main = el('div', 'row-main');
  const n = el('div', 'row-name text', name);
  n.title = T('details');
  main.append(n, meta);
  r.appendChild(main);
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; expanded.delete(key); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail rs-detail');
      box.appendChild(detail());
      r.appendChild(box);
      expanded.add(key);
      r.classList.add('open');
    }
  };
  r.onclick = (e) => {
    if (e.target.closest('button, a, input, select, textarea, summary, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;     // selecting text
    Office.keepInPlace(r, () => set(!box));
  };
  shown.push({ open: () => !!box, set });
  if (expanded.has(key)) set(true);
  return r;
}

// ------------------------------------------------------------------ apps
function appsSection() {
  const list = apps();
  const s = sectionBox(T('apps'), T('apps_sub'), list.length > 1 ? unfoldAll() : null);
  if (!list.length) { s.appendChild(el('p', 'empty', state.place.found ? T('apps_none') : T('apps_no_packages'))); return s; }
  const box = el('div', 'box');
  list.forEach((a) => box.appendChild(appRow(a)));
  s.appendChild(box);
  const files = el('p', 'role rs-foot');
  files.append(T('apps_files'), ' ');
  if (Office.desks.has('snapshot') && Office.desks.get('snapshot').hired) files.appendChild(snapshotLink(T('snapshot_link')));
  s.appendChild(files);
  return s;
}

/**
 * The chips of an app or VM in two labelled groups, so it is clear what each refers to: its package
 * (the small files in the backup place — when written, and when Kopia took it along with the backup
 * place's share) and its data (its folders: local snapshots, Kopia, the protection as on every desk).
 */
function rowChips(meta, x, extraPackage, extraData) {
  const own = (x.kopia || []).some((s) => s.covers === 'all');
  const pkg = chipGroup(T('chip.label_package'), [
    chip(T('chip.package', { when: date(x.time) }), x.stale ? 'warn' : '', T(x.stale ? 'chip.stale_hint' : 'chip.package_hint', { path: x.path, when: date(x.time) })),
    ...extraPackage,
    own ? null : kopiaChip(x.kopia, ['package']),
  ]);
  const data = chipGroup(T('chip.label_data'), [...extraData, snapsChip(x.folders), kopiaChip(x.kopia, ['data', 'all']), Office.backupChip(protectionOf(x.folders))]);
  [pkg, data].forEach((g) => { if (g) meta.appendChild(g); });
}

function appRow(a) {
  const meta = el('div', 'row-meta');
  meta.appendChild(el('span', '', T('type.' + (a.type || 'container'))));
  if (!a.present) meta.appendChild(chip(T('chip.gone'), 'danger', T('chip.gone_hint')));
  rowChips(meta, a, a.dumps.length ? [chip(T('chip.dumps', { n: a.dumps.length }), '', T('chip.dumps_hint'))] : [], []);
  return unfoldingRow('app:' + a.id, a.name, meta, () => appDetail(a));
}

function containerChips(list) {
  const span = el('span', 'rs-chips');
  list.forEach((c) => {
    const cls = c.now === 'running' ? 'ok' : c.now === 'stopped' ? 'warn' : 'danger';
    span.appendChild(chip(c.name, cls, T('ct.' + c.now, { image: c.image, digest: c.digest || '–' })));
  });
  return span;
}

function appDetail(a) {
  const box = el('div');
  box.appendChild(dl([
    [T('d.package'), copyCode(a.path), ' · ', date(a.time), a.result && a.result !== 'ok' ? ` · ${T('result.' + a.result)}` : ''],
    [T('d.containers'), containerChips(a.containers)],
  ]));
  const needs = needsBlock(a);
  if (needs) box.appendChild(needs);
  box.appendChild(packageBlock(a));
  box.appendChild(snapshotsBlock(a.folders, a.shares, a));
  if (kopia().enabled) box.appendChild(kopiaBlock(a.kopia));
  if ((a.own_backups || []).length) box.appendChild(ownBlock(a));
  return box;
}

/** What the package holds and how it goes back: dumps, database copies, templates, compose files; earlier nights */
function packageBlock(a) {
  const b = block(T('from_package', { when: date(a.time) }), a.stale ? T('chip.stale_hint', { when: date(a.time) }) : null);
  if (a.dumps.length) b.appendChild(dumpsPart(a));
  if ((a.sqlite || []).length) b.appendChild(sqlitePart(a));
  if (a.templates.length) b.appendChild(templatesPart(a));
  if (a.compose && a.compose.files.length) b.appendChild(composePart(a));
  if (a.templates.length || (a.compose && a.compose.files.length)) {
    const p = el('div', 'rs-part rs-act');
    p.append(restoreButton(T('cfg.button'), { kind: 'config', app: a.id }, T('rd.title.config', { what: a.name })), ' ', el('span', 'role', T('cfg.button_hint')));
    b.appendChild(p);
  }
  if (!a.dumps.length && !(a.sqlite || []).length && !a.templates.length && !(a.compose && a.compose.files.length)) {
    b.appendChild(el('p', 'role', T('pk_nothing')));
  }
  b.appendChild(earlierPart('app', a));
  return b;
}

/**
 * The command that plays a dump back into its running container, with the credentials the dump
 * used (the package names the variables) - for Immich through its documented sed first.
 */
function dumpCommand(file, d, immich) {
  const c = d.container;
  if (d.type === 'mariadb') {
    const bin = d.client === 'mysql' ? 'mysql' : 'mariadb';
    const who = d.login === 'user' && d.user_var ? `-u"$${d.user_var}" -p"$${d.password_var}"` : `-uroot -p"$${d.password_var || 'MARIADB_ROOT_PASSWORD'}"`;
    return `zcat ${q(file)} | docker exec -i ${c} sh -c 'exec ${bin} ${who}'`;
  }
  if (d.type === 'postgres') {
    // pg_dumpall --clean: connect to the database postgres, as the user the dump was made with
    const fix = immich ? ` | sed "s/SELECT pg_catalog.set_config('search_path', '', false);/SELECT pg_catalog.set_config('search_path', 'public, pg_catalog', true);/g"` : '';
    return `zcat ${q(file)}${fix} | docker exec -i ${c} sh -c 'PGPASSWORD="\${POSTGRES_PASSWORD:-}" exec psql -X -U "\${POSTGRES_USER:-postgres}" -d postgres'`;
  }
  if (d.type === 'mongodb') {
    const who = d.login === 'none' ? '' : ` -u "$${d.user_var || 'MONGO_INITDB_ROOT_USERNAME'}" -p "$${d.password_var || 'MONGO_INITDB_ROOT_PASSWORD'}" --authenticationDatabase admin`;
    return `docker exec -i ${c} sh -c 'exec mongorestore --drop --archive --gzip${who}' < ${q(file)}`;
  }
  return '';
}

function dumpsPart(a) {
  const part = el('div', 'rs-part');
  part.appendChild(el('div', 'rs-part-title', T('db.title')));
  part.appendChild(el('p', 'role', T('db.text')));
  const list = el('div', 'box');
  a.dumps.forEach((d) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', d.file.split('/').pop()));
    const meta = el('div', 'row-meta');
    meta.append(el('span', '', fmt.size(d.bytes)), el('span', '', date(d.time)));
    if (d.kept) meta.appendChild(chip(T('db.kept'), 'warn', T('db.kept_hint')));
    const ct = a.containers.find((x) => x.name === d.container);
    if (ct) meta.appendChild(chip(ct.image, 'quiet rs-img', T('db.image_hint', { digest: ct.digest || '–' })));
    main.appendChild(meta);
    row.appendChild(main);
    const cmd = dumpCommand(`${a.path}/${d.file}`, d, a.immich);
    const right = el('div', 'rs-right');
    const b = button(T('copy_command'), 'small plain', () => Office.copy(cmd));
    b.title = cmd;
    b.disabled = !cmd;
    right.append(b, restoreButton(T('db.button'), { kind: 'db', app: a.id, file: d.file }, T('rd.title.db', { what: a.name, file: d.file.split('/').pop() })));
    row.appendChild(right);
    list.appendChild(row);
  });
  part.appendChild(list);
  part.appendChild(el('p', 'role', T('db.version')));
  if (a.dumps.some((d) => d.type === 'postgres')) part.appendChild(el('p', 'role', T('db.pg_role')));
  if (a.db_only) part.appendChild(el('p', 'callout', T('db.alone')));
  if (a.immich) part.appendChild(immichSteps(a));
  if (a.nextcloud.length) part.appendChild(nextcloudSteps(a));
  return part;
}

/** Immich wants its dump in a fresh database, through its sed (otherwise the vector extensions break) */
function immichSteps(a) {
  const pg = a.dumps.find((d) => d.type === 'postgres');
  const others = a.containers.map((c) => c.name).filter((n) => !pg || n !== pg.container);
  const how = fold(T('immich.title', { name: a.name }));
  if (!pg) { how.appendChild(el('p', 'role', T('immich.web'))); return how; }
  how.append(
    rstep(T('immich.s1'), T('immich.s1_text'), others.length ? `docker stop ${others.join(' ')}` : null),
    rstep(T('immich.s2'), T('immich.s2_text', { env: `${a.path}/compose/.env` }), `docker stop ${pg.container}`),
    rstep(T('immich.s3'), T('immich.s3_text'), `docker start ${pg.container}`),
    rstep(T('immich.s4'), null, dumpCommand(`${a.path}/${pg.file}`, pg, true)),
    rstep(T('immich.s5'), null, others.length ? `docker start ${others.join(' ')}` : null),
    el('p', 'role', T('immich.web')),
  );
  return how;
}

/** Nextcloud: maintenance mode around the restore; clients told about an older state afterwards */
function nextcloudSteps(a) {
  const n = a.nextcloud.find((x) => !x.same_as) || a.nextcloud[0];
  const occ = (cmd) => `docker exec -u ${n.user} ${n.container} php ${n.occ} ${cmd}`;
  const d = a.dumps[0];
  return fold(T('nc.title', { name: a.name }),
    rstep(T('nc.s1'), null, occ('maintenance:mode --on')),
    rstep(T('nc.s2'), T('nc.s2_text'), d ? dumpCommand(`${a.path}/${d.file}`, d, false) : null),
    rstep(T('nc.s3'), null, occ('maintenance:mode --off')),
    rstep(T('nc.s4'), T('nc.s4_text'), `${occ('maintenance:data-fingerprint')}\n${occ('files:scan --all')}`));
}

/**
 * The media servers' databases (engine 2.19): consistent copies made while the server kept running - back over
 * the database with the server stopped; its -wal and -shm belong to the state being replaced and go.
 */
function sqlitePart(a) {
  const part = el('div', 'rs-part');
  part.appendChild(el('div', 'rs-part-title', T('sq.title')));
  part.appendChild(el('p', 'role', T('sq.text')));
  const list = el('div', 'box');
  a.sqlite.forEach((x) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', x.source.split('/').pop()));
    const meta = el('div', 'row-meta');
    meta.append(el('span', '', fmt.size(x.bytes || 0)), el('span', '', date(x.time)));
    meta.appendChild(x.check === 'ok' ? chip(T('sq.checked'), 'ok', T('sq.checked_hint')) : chip(T('sq.unchecked'), '', T('sq.unchecked_hint')));
    if (x.kept) meta.appendChild(chip(T('sq.kept'), 'warn', T('sq.kept_hint')));
    main.appendChild(meta);
    row.appendChild(main);
    const dir = x.source.slice(0, x.source.lastIndexOf('/'));
    const cmd = `docker stop ${x.container} && cp ${q(a.path + '/' + x.file)} ${q(x.source)} && chown --reference=${q(dir)} ${q(x.source)} && chmod 0644 ${q(x.source)} && rm -f ${q(x.source + '-wal')} ${q(x.source + '-shm')} && docker start ${x.container}`;
    const right = el('div', 'rs-right');
    const b = button(T('copy_command'), 'small plain', () => Office.copy(cmd));
    b.title = cmd;
    right.appendChild(b);
    row.appendChild(right);
    list.appendChild(row);
  });
  part.appendChild(list);
  [...new Set(a.sqlite.map((x) => x.container))].forEach((c) => {
    const p = el('div', 'rs-act');
    p.appendChild(restoreButton(T('sq.button', { name: c }), { kind: 'sqlite', app: a.id, container: c }, T('rd.title.sqlite', { what: c })));
    part.appendChild(p);
  });
  part.appendChild(el('p', 'role', T('sq.after')));
  return part;
}

/** A file of the package next to its place on the server: the same, different, or missing there */
function nowChip(now) {
  return chip(T('now.' + now), now === 'same' ? 'ok' : now === 'differs' ? 'warn' : 'danger', T('now.' + now + '_hint'));
}

function templatesPart(a) {
  const part = el('div', 'rs-part');
  part.appendChild(el('div', 'rs-part-title', T('tpl.title')));
  part.appendChild(el('p', 'role', T('tpl.text', { dir: TPL })));
  const rows = el('div', 'rs-files');
  a.templates.forEach((t) => {
    const line = el('div', 'rs-file');
    line.append(el('code', '', t.file), nowChip(t.now));
    rows.appendChild(line);
  });
  part.appendChild(rows);
  const missing = a.templates.filter((t) => t.now === 'missing');
  if (missing.length) part.appendChild(codeBlock(missing.map((t) => `cp -n ${q(`${a.path}/${t.file}`)} ${TPL}/`).join('\n')));
  part.appendChild(el('p', 'role', T('tpl.after')));
  return part;
}

function composePart(a) {
  const c = a.compose;
  const root = state.compose_root;
  const part = el('div', 'rs-part');
  part.appendChild(el('div', 'rs-part-title', T('cmp.title')));
  part.appendChild(el('p', 'role', c.dir ? T('cmp.text', { dir: `${root}/${c.dir}` }) : T('cmp.no_dir')));
  const rows = el('div', 'rs-files');
  (c.now || []).forEach((f) => {
    const line = el('div', 'rs-file');
    line.append(el('code', '', f.file), nowChip(f.now));
    rows.appendChild(line);
  });
  part.appendChild(rows);
  if (c.dir && (c.now || []).some((f) => f.now === 'missing')) {
    part.appendChild(codeBlock(`mkdir -p ${q(`${root}/${c.dir}`)} && cp -rn ${q(`${a.path}/compose/.`)} ${q(`${root}/${c.dir}/`)}`));
  }
  if (c.indirect) part.appendChild(el('p', 'role', T('cmp.indirect')));
  part.appendChild(el('p', 'role', T('cmp.after')));
  return part;
}

/** Earlier nights' packages, from the snapshots of the backup place's share - read when opened */
function earlierPart(kind, p) {
  const key = `${kind}:${p.id}`;
  const det = el('details', 'rs-how');
  det.appendChild(el('summary', '', T('earlier.title', { n: state.place.snaps || 0 })));
  const out = el('div');
  det.appendChild(out);
  const show = () => {
    out.innerHTML = '';
    const v = versions.get(key);
    if (!v || v.loading) { out.appendChild(el('p', 'role', Office.t('common.loading'))); return; }
    if (v.error) { out.appendChild(el('p', 'role', Office.errorText(v.error, ID))); return; }
    if (!v.list.length) { out.appendChild(el('p', 'role', T('earlier.none'))); return; }
    out.appendChild(el('p', 'role', T('earlier.text')));
    const box = el('div', 'box');
    v.list.forEach((x) => {
      const row = el('div', 'row nocheck');
      const main = el('div', 'row-main');
      main.appendChild(el('div', 'row-name text', T('earlier.from', { when: date(x.run_time) })));
      const meta = el('div', 'row-meta');
      meta.appendChild(el('span', '', T('earlier.snap', { when: date(x.time) })));
      if (x.dumps.length) meta.appendChild(el('span', '', x.dumps.map((d) => `${d.file.split('/').pop()} (${fmt.size(d.bytes)}, ${date(d.time)})`).join(', ')));
      meta.appendChild(copyCode(x.path));
      main.appendChild(meta);
      row.appendChild(main);
      const items = kind === 'app'
        ? [...x.dumps.map((d) => ({ text: T('earlier.restore_dump', { file: d.file.split('/').pop() }),
                                     act: () => restoreDialog({ kind: 'db', app: p.id, file: d.file, version: x.snap }, T('rd.title.db', { what: p.name, file: d.file.split('/').pop() })) })),
           { text: T('earlier.restore_config'), act: () => restoreDialog({ kind: 'config', app: p.id, version: x.snap }, T('rd.title.config', { what: p.name })) }]
        : [{ text: T('earlier.restore_vm'), act: () => restoreDialog({ kind: 'vm', vm: p.id, version: x.snap }, T('rd.title.vm', { what: p.name })) }];
      const right = el('div', 'rs-right');
      const mb = button(T('earlier.restore'), 'small plain', (ev) => Office.menu(ev, items.map((it) => ({ ...it, disabled: !canRestore() }))));
      mb.dataset.own = '1';
      right.appendChild(mb);
      row.appendChild(right);
      box.appendChild(row);
    });
    out.appendChild(box);
  };
  det.ontoggle = async () => {
    if (!det.open) return;
    if (!versions.has(key) || versions.get(key).error) {
      versions.set(key, { loading: true });
      show();
      const j = await Office.api.post(`${ID}.versions`, { kind, id: p.id });
      versions.set(key, j.ok ? { list: j.versions } : { error: j.error });
    }
    show();
  };
  return det;
}

/** The local snapshots of the folders an app or VM keeps its data in, and of whole shares it binds */
function snapshotsBlock(folders, shares, owner) {
  const all = [...folders, ...(shares || [])];
  const b = block(T('from_snapshots'), all.length ? T('snaps.text') : T('snaps.none'));
  all.forEach((f) => b.appendChild(unitPart(f, owner)));
  return b;
}

/** The share of a folder, when it is missing on this server: data can't come back before the user creates it */
function missingShare(owner, share) {
  const n = ((owner && owner.needs) || []).find((x) => x.share === share);
  return n && n.state === 'missing' ? n : null;
}

/** A folder (or a whole share) an app or VM keeps its data in: where it lies, its snapshots, «Restore…» */
function unitPart(f, owner) {
  const part = el('div', 'rs-part');
  const title = el('div', 'rs-part-title rs-folder');
  title.append(copyCode(f.path));
  if (f.whole) title.append(' ', chip(T('snaps.whole_chip'), 'quiet', T('snaps.whole_hint')));
  const pc = Office.backupChip(f.protection);
  if (pc) title.append(' ', pc);
  if (!f.whole && !f.exists && !f.asleep) title.append(' ', chip(T('snaps.missing'), 'danger', T('snaps.missing_hint')));
  // from a moment of its snapshots, or from what Kopia brought back into its restore folder
  const fromKopia = (state.restores || []).some((r) => r.kind === 'kopia' && ['ok', 'warnings'].includes(r.result));
  if (owner && (f.snaps || f.asleep || fromKopia)) {
    const b = restoreButton(T('files.button'), { kind: 'files', path: f.path }, f.whole ? T('rd.title.share', { share: f.share }) : T('rd.title.files', { what: f.path }));
    if (missingShare(owner, f.share)) {
      b.disabled = true;
      b.title = T('needs.missing_button', { share: f.share });
    }
    title.append(' ', b);
  }
  part.appendChild(title);
  if (f.whole) part.appendChild(el('div', 'role', T('snaps.whole', { share: f.share, names: andList(f.containers || []), n: (f.containers || []).length })));
  else if (f.containers && f.containers.length) part.appendChild(el('div', 'role', T('snaps.used_by', { names: f.containers.join(', ') })));
  (f.places || []).forEach((p) => {
    const line = el('div', 'rs-place');
    const where = p.dataset ? T('snaps.zfs', { base: p.base, dataset: p.dataset }) : T('snaps.base', { base: p.base, fs: p.fs || '?' });
    line.appendChild(el('span', 'rs-place-where', where));
    if (p.asleep) line.appendChild(chip(T('snaps.asleep'), 'quiet', T('snaps.asleep_hint')));
    else if (!p.count) line.appendChild(chip(T('snaps.zero'), 'warn', T('snaps.zero_hint', { fs: p.fs || '?' })));
    else line.appendChild(el('span', '', T('snaps.count', { n: p.count, when: date(p.latest && p.latest.time) })));
    if (p.own_dataset && !f.whole) line.appendChild(chip(T('snaps.own_ds'), 'quiet', T('snaps.own_ds_hint')));
    if ((p.inner || []).length) line.appendChild(chip(T('snaps.inner', { n: p.inner.length }), 'warn', T('snaps.inner_hint', { list: p.inner.join(', ') })));
    part.appendChild(line);
    if (p.latest) {
      const look = el('div', 'role rs-look');
      look.append(T('snaps.look'), ' ', copyCode(p.latest.path));
      part.appendChild(look);
    }
  });
  return part;
}

/** The shares an app or VM keeps its data in, as they are here — before data comes back they must be there */
function needsBlock(x) {
  const list = x.needs || [];
  if (!list.length) return null;
  const b = block(T('needs.title'), T('needs.text'));
  list.forEach((n) => b.appendChild(needLine(n)));
  return b;
}

function needLine(n) {
  const line = el('div', 'rs-need');
  const head = el('div', 'rs-place');
  const cls = { data: 'ok', empty: 'quiet', missing: 'danger', unknown: 'quiet' }[n.state] || 'quiet';
  head.append(el('code', '', n.share), ' ', chip(T('needs.' + n.state), cls, T('needs.' + n.state + '_hint')));
  line.appendChild(head);
  if (n.state === 'missing') {
    line.appendChild(el('div', 'role', T('needs.create', { share: n.share })));
    oldSettingsLines(n).forEach((x) => line.appendChild(x));
  }
  return line;
}

/** A missing share's settings on the old server, from the package — information only: this server may have other pools */
function oldSettingsLines(n) {
  if (!n.old) return [el('div', 'role', T('needs.old_none'))];
  return [el('div', 'role', T('needs.old', { settings: oldSettingsText(n.old) })),
    el('div', 'role', (n.old.missing_pools || []).length ? T('needs.pools_missing', { names: n.old.missing_pools.join(', ') }) : T('needs.old_info'))];
}

function oldSettingsText(o) {
  const store = (s) => (s === 'array' ? T('needs.array') : s);
  const parts = [T('needs.o_primary', { name: store(o.primary) })];
  if (o.secondary) parts.push(T('needs.o_secondary', { name: store(o.secondary) }));
  if (o.mover) parts.push(T('needs.o_mover', o.mover === 'to_primary' ? { from: store(o.secondary), to: store(o.primary) } : { from: store(o.primary), to: store(o.secondary) }));
  parts.push(T('needs.o_allocator', { name: ['highwater', 'mostfree', 'fillup'].includes(o.allocator) ? T('needs.alloc_' + o.allocator) : o.allocator }));
  parts.push(['any', 'manual'].includes(o.split) ? T('needs.o_split_' + o.split) : T('needs.o_split', { level: o.split }));
  if (o.floor) parts.push(T('needs.o_floor', { size: fmt.size(o.floor) }));
  if ((o.include || []).length) parts.push(T('needs.o_include', { list: o.include.join(', ') }));
  if ((o.exclude || []).length) parts.push(T('needs.o_exclude', { list: o.exclude.join(', ') }));
  parts.push(T('needs.o_smb_' + (['yes', 'hidden'].includes(o.smb) ? o.smb : 'no')));
  if (o.smb === 'yes' || o.smb === 'hidden') parts.push(T('needs.o_security_' + (['secure', 'private'].includes(o.security) ? o.security : 'public')));
  parts.push(T('needs.o_nfs_' + (o.nfs === 'yes' ? 'yes' : 'no')));
  return parts.join(', ');
}

/** Where Kopia has an app or VM: its own source, or its share's */
function kopiaBlock(sources) {
  const b = block(T('from_kopia'), sources.length ? T('kp.text') : T('kp.none'));
  sources.forEach((s) => {
    const line = el('div', 'rs-place');
    line.append(copyCode(`${kopiaRoot()}/${s.source}`), ' ');
    line.appendChild(el('span', '', T('kp.covers_' + (s.covers || (s.own ? 'all' : 'data')))));
    line.appendChild(s.last ? chip(T('chip.kopia', { when: fmt.relative(s.last) }), 'ok', fmt.date(s.last)) : chip(T('chip.kopia_never'), 'warn', T('chip.kopia_never_hint')));
    b.appendChild(line);
  });
  if (sources.length) {
    const go = el('a', '', T('kp.guide'));
    go.href = '#/restore';
    go.onclick = (e) => { e.preventDefault(); pick('kopia'); window.scrollTo(0, 0); };
    const p = el('p', 'role');
    p.appendChild(go);
    b.appendChild(p);
  }
  return b;
}

/** What the apps keep themselves (Emby's plugin, Jellyfin, Plex, Immich) - a second way back */
function ownBlock(a) {
  const b = block(T('own.title'), T('own.text'));
  const items = a.own_backups.map((o) => [T('own.kind.' + o.kind), copyCode(o.path), ' ',
    el('span', 'role', o.asleep ? T('own.asleep') : o.files ? T('own.files', { n: o.files, when: fmt.relative(o.newest) }) : T('own.empty')),
    el('div', 'role', T('own.how.' + o.kind))]);
  b.appendChild(dl(items));
  return b;
}

// ------------------------------------------------------------------ VMs
function vmsSection() {
  const list = vms();
  const s = sectionBox(T('vms'), T('vms_sub'), list.length > 1 ? unfoldAll() : null);
  if (!state.vm_service) s.appendChild(el('p', 'callout', T('vm.service_off')));
  const lv = state.server && state.server.libvirt;
  if (lv) s.appendChild(vmAllFold(lv));
  if (!list.length) { s.appendChild(el('p', 'empty', T('vms_none'))); return s; }
  const box = el('div', 'box');
  list.forEach((v) => box.appendChild(vmRow(v)));
  s.appendChild(box);
  return s;
}

/** All of libvirt.img back from its archive: VM service off, unpack into the image, VM service on */
function vmAllFold(a) {
  const img = state.libvirt_img || '/mnt/user/system/libvirt/libvirt.img';
  return fold(T('vm.all', { when: date(state.server.libvirt_time), size: fmt.size(state.server.libvirt_bytes || 0) }),
    el('p', 'role', T('vm.all_text', { file: a })),
    rstep(T('vm.all_1'), T('vm.all_1_text')),
    rstep(T('vm.all_2'), null, `mkdir -p /tmp/libvirt-img && mount -o loop ${q(img)} /tmp/libvirt-img && tar -xzf ${q(a)} -C /tmp/libvirt-img --strip-components=1 && umount /tmp/libvirt-img`),
    rstep(T('vm.all_3'), T('vm.all_3_text')));
}

function vmStateChip(v) {
  if (v.state === null || v.state === undefined) return chip(T('vm.unknown'), 'quiet', T('vm.unknown_hint'));
  if (v.state === 'missing') return chip(T('vm.missing'), 'danger', T('vm.missing_hint'));
  return chip(v.state === 'running' ? T('vm.running') : v.state === 'shut off' ? T('vm.off') : v.state, v.state === 'running' ? 'ok' : '', T('vm.state_hint'));
}

function vmRow(v) {
  const meta = el('div', 'row-meta');
  meta.appendChild(vmStateChip(v));
  rowChips(meta, v, v.tpm ? [chip(T('vm.tpm'), 'quiet', T('vm.tpm_hint'))] : [], []);
  return unfoldingRow('vm:' + v.id, v.name, meta, () => vmDetail(v));
}

function vmDetail(v) {
  const box = el('div');
  box.appendChild(dl([
    [T('d.package'), copyCode(v.path), ' · ', date(v.time)],
    v.disks.length ? [T('d.disks'), v.disks.map((d) => (d.snapshot ? `${d.source} (${d.snapshot})` : d.source)).join(', ')] : null,
    v.hostdev ? [T('d.hostdev'), T('vm.hostdev', { n: v.hostdev })] : null,
  ]));
  const b = block(T('from_package', { when: date(v.time) }), v.stale ? T('chip.stale_hint', { when: date(v.time) }) : null);
  const files = el('div', 'rs-files');
  const add = (text, hint) => {
    const line = el('div', 'rs-file');
    line.append(el('code', '', text));
    if (hint) line.append(el('span', 'role', hint));
    files.appendChild(line);
  };
  if (v.xml) add(v.xml, T('vm.f_xml'));
  v.nvram.forEach((n) => add(`nvram/${n}`, /S\d{14}_VARS/.test(n) ? T('vm.f_nvram_snap') : T('vm.f_nvram')));
  if (v.tpm) add(`tpm/${v.uuid}/`, T('vm.f_tpm'));
  if (v.snapshotdb) add('snapshotdb/snapshots.db', T('vm.f_snapdb'));
  b.appendChild(files);
  if (v.xml) {
    const p = el('div', 'rs-part rs-act');
    p.append(restoreButton(T('vm.button'), { kind: 'vm', vm: v.id }, T('rd.title.vm', { what: v.name })), ' ', el('span', 'role', T('vm.button_hint')));
    b.appendChild(p);
  }
  b.appendChild(vmCommands(v));
  b.appendChild(earlierPart('vm', v));
  box.appendChild(b);
  const needs = needsBlock(v);
  if (needs) box.appendChild(needs);
  box.appendChild(snapshotsBlock(v.folders, [], v));
  if (kopia().enabled) box.appendChild(kopiaBlock(v.kopia));
  return box;
}

/** A single VM back from its package: NVRAM and TPM state into libvirt, then define it - also one that is gone */
function vmCommands(v) {
  const p = v.path;
  const snapVars = v.nvram.filter((n) => /S\d{14}_VARS/.test(n));
  const cmds = [];
  v.nvram.filter((n) => !/S\d{14}_VARS/.test(n)).forEach((n) => cmds.push(`cp -a ${q(`${p}/nvram/${n}`)} /etc/libvirt/qemu/nvram/`));
  if (v.tpm && v.uuid) cmds.push(`mkdir -p /etc/libvirt/qemu/swtpm/tpm-states && cp -a ${q(`${p}/tpm/${v.uuid}`)} /etc/libvirt/qemu/swtpm/tpm-states/`);
  if (v.xml) cmds.push(`virsh define ${q(`${p}/${v.xml}`)}`);
  if (v.autostart) cmds.push(`virsh autostart ${q(v.name)}`);
  const how = fold(T('vm.one', { name: v.name }),
    el('p', 'role', T('vm.one_text', { name: v.name, when: date(v.time) })),
    cmds.length ? codeBlock(cmds.join('\n')) : null,
    el('p', 'role', [v.state !== 'missing' ? T('vm.one_after') : '', v.tpm ? T('vm.one_tpm') : ''].filter(Boolean).join(' ')));
  if (v.disks.length) how.appendChild(el('p', 'role', T('vm.disks', { list: v.disks.map((d) => (d.snapshot ? `${d.source} (${d.snapshot})` : d.source)).join(', ') })));
  // Unraid's own VM snapshots (a chain of disk files): their list and UEFI variables, only if that chain comes back too
  if (v.snapshotdb || snapVars.length) {
    const more = [];
    if (v.snapshotdb) more.push(`mkdir -p ${q(`/etc/libvirt/qemu/snapshotdb/${v.name}`)} && cp -a ${q(`${p}/snapshotdb/.`)} ${q(`/etc/libvirt/qemu/snapshotdb/${v.name}/`)}`);
    snapVars.forEach((n) => more.push(`cp -a ${q(`${p}/nvram/${n}`)} /etc/libvirt/qemu/nvram/`));
    how.append(el('p', 'role', T('vm.snaps')), codeBlock(more.join('\n')));
  }
  return how;
}

// ------------------------------------------------------------------ Kopia
function kopiaSection() {
  const k = kopia();
  const s = sectionBox(T('kopia'), T('kopia_sub'));
  if (!k.enabled) {
    s.appendChild(el('p', 'callout', T('kg.off')));
    s.appendChild(adviserLink());
    return s;
  }
  const box = el('div', 'box rs-guide');
  if (!k.container) box.appendChild(el('p', 'callout warn', T('kg.no_container')));
  else box.appendChild(el('p', 'role', T(k.running ? 'kg.container' : 'kg.container_stopped', { name: k.container, root: kopiaRoot() })));
  box.append(
    rstep(T('kg.s1'), T('kg.s1_text', { name: k.container || 'kopia' })),
    rstep(T('kg.s2'), T('kg.s2_text', { root: kopiaRoot() })),
    rstep(T('kg.s3'), k.restore ? T('kg.s3_mapped', { dest: k.restore.dest, source: k.restore.source }) : T('kg.s3_text')),
    rstep(T('kg.s4'), T('kg.s4_text')),
  );
  const note = kopiaRootNote();
  if (note) box.insertBefore(note, box.children[1] || null);
  box.appendChild(el('p', 'callout', T('kg.password')));
  s.appendChild(box);
  // restoring with the office: into a writable folder of the Kopia container
  const w = el('div', 'box rs-guide');
  w.appendChild(el('div', 'rs-step-title', T('kr.title')));
  if (k.restore) w.appendChild(el('p', 'role', T('kr.mapped', { dest: k.restore.dest, source: k.restore.source })));
  else {
    w.appendChild(el('p', 'callout warn', T('kr.none')));
    w.appendChild(el('p', 'role', T('kr.how', { name: k.container || 'kopia' })));
  }
  s.appendChild(w);
  // the sources: apps and VMs with a source of their own first, then the shares
  const src = el('div', 'box rs-sources');
  const head = (text) => src.appendChild(el('div', 'rs-subhead', text));
  const row = (path, last, note, source) => {
    const r = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', path));
    const meta = el('div', 'row-meta');
    if (note) meta.appendChild(el('span', '', note));
    main.appendChild(meta);
    r.appendChild(main);
    const right = el('div', 'rs-right');
    right.appendChild(last ? chip(T('chip.kopia', { when: fmt.relative(last) }), 'ok', fmt.date(last)) : chip(T('chip.kopia_never'), 'warn', T('chip.kopia_never_hint')));
    right.appendChild(button(Office.t('common.copy'), 'small plain', () => Office.copy(path)));
    if (source && k.restore) {
      const b = button(T('kr.button'), 'small', () => kopiaDialog(source, path));
      b.disabled = !canRestore() || !k.running;
      right.appendChild(b);
    }
    r.appendChild(right);
    src.appendChild(r);
  };
  const own = [...apps().flatMap((a) => a.kopia.filter((x) => x.own).map((x) => ({ ...x, name: a.name }))),
    ...vms().flatMap((v) => v.kopia.filter((x) => x.own).map((x) => ({ ...x, name: v.name })))];
  if (own.length) {
    head(T('kg.own'));
    own.forEach((x) => row(`${kopiaRoot()}/${x.source}`, x.last, T('kg.own_note', { name: x.name }), x.source));
  }
  const shares = (state.shares || []).filter((x) => x.mode === 'kopia');
  if (shares.length) {
    head(T('kg.shares'));
    shares.forEach((x) => row(`${kopiaRoot()}/${x.name}`, x.last, x.flash ? T('kg.flash_note') : '', x.flash ? null : x.name));
  }
  s.appendChild(el('div', 'section-sub', T('kg.sources')));
  s.appendChild(src);
  return s;
}

function adviserLink() {
  if (!Office.desks.has('advisor')) return null;
  const a = el('a', '', T('move.advisor'));
  a.href = '#/advisor';
  const p = el('p', 'role');
  p.appendChild(a);
  return p;
}

// ------------------------------------------------------------------ onto a new server
/**
 * Everything onto another Unraid server (the old one burnt, was stolen or retired):
 * what may come back, what must not, and the stumbling blocks - from what is really here.
 */
function moveSection() {
  const s = sectionBox(T('move.title'), T('move.sub'));
  const e = engine();
  const box = el('div', 'box rs-guide');
  const holder = (cls, ...kids) => { const d = el('div', cls); kids.forEach((k) => d.append(k)); return d; };
  const step = (title, text, ...extra) => {
    const st = rstep(title, text);
    extra.filter(Boolean).forEach((x) => st.appendChild(x));
    box.appendChild(st);
  };
  const base = state.place.base || `<${T('move.place')}>`;
  const CM = state.compose_root || '/boot/config/plugins/compose.manager/projects';
  const lines = [];
  apps().forEach((a) => a.templates.forEach((t) => lines.push(`cp -n ${q(`${a.path}/${t.file}`)} ${TPL}/`)));
  if (state.server && state.server.templates) lines.push(`cp -n '${state.server.path}/docker-templates/'my-*.xml ${TPL}/`);
  apps().filter((a) => a.compose && a.compose.dir && a.compose.files.length).forEach((a) => {
    lines.push(`mkdir -p ${q(`${CM}/${a.compose.dir}`)} && cp -rn ${q(`${a.path}/compose/.`)} ${q(`${CM}/${a.compose.dir}/`)}`);
  });
  ((state.server && state.server.compose) || []).forEach((d) => lines.push(`mkdir -p ${q(`${CM}/${d}`)} && cp -rn ${q(`${state.server.path}/compose/${d}/.`)} ${q(`${CM}/${d}/`)}`));
  const shares = (state.shares || []).filter((x) => !x.flash).map((x) => x.name);
  const gpu = vms().filter((v) => v.hostdev).map((v) => v.name);
  const tpm = vms().filter((v) => v.tpm).map((v) => v.name);
  const vmShares = [...new Set(vms().flatMap((v) => v.disks.map((d) => d.share)).filter(Boolean))];
  const vmOffsite = e.kopia && vmShares.length && vmShares.every((sh) => (state.shares || []).some((x) => x.name === sh && x.mode === 'kopia'));
  const flash = state.flash ? state.flash.path : `${base}/flash/flash.tar.gz`;

  // the shares the apps and VMs keep their data in, as they are here: a missing one the user creates himself
  const needs = new Map();
  [...apps(), ...vms()].forEach((x) => (x.needs || []).forEach((n) => { if (!needs.has(n.share)) needs.set(n.share, n); }));
  const needList = needs.size ? holder('rs-needs', el('div', 'role', T('move.s2_needs')), ...[...needs.values()].map(needLine)) : null;

  step(T('move.s1'), T('move.s1_text'));
  step(T('move.s2'), T('move.s2_text', { n: shares.length }), shares.length ? holder('rs-rules', ...shares.flatMap((n) => [el('code', '', n), ' '])) : null,
    el('div', 'role', T('move.s2_cfg', { path: (state.server && state.server.shares) || `${base}/server/shares/` })), needList);
  step(T('move.s3'), e.kopia ? T('move.s3_text', { root: kopiaRoot() }) : T('move.s3_nokopia'), e.kopia ? kopiaRootNote() : null, adviserLink());
  step(T('move.s4'), T('move.s4_text'), lines.length ? codeBlock(lines.join('\n')) : null,
    apps().some((a) => a.compose && a.compose.indirect) ? el('div', 'role', T('move.s4_indirect')) : null, el('div', 'role', T('move.s4_after')));
  step(T('move.s5'), T('move.s5_text'), apps().some((a) => a.nextcloud.length) ? el('div', 'role', T('move.s5_nextcloud')) : null);
  if (vms().length) {
    step(T('move.s6'), vmOffsite ? T('move.s6_offsite') : T('move.s6_local', { shares: vmShares.join(', ') || 'domains' }),
      el('div', 'role', T('move.s6_xml')),
      gpu.length ? el('div', 'role', T('move.s6_gpu', { names: gpu.join(', ') })) : null,
      tpm.length ? el('div', 'role', T('move.s6_tpm', { names: tpm.join(', ') })) : null,
      el('div', 'role', T('move.s6_overlay')));
  }
  step(T('move.s7'), e.flash === 'snapshot' ? T('move.s7_snapshot') : T('move.s7_tar', { path: flash }), el('div', 'role', T('move.s7_never')),
    el('div', 'role', T('move.s7_official')));
  step(T('move.s8'), T('move.s8_text'));
  s.appendChild(box);
  return s;
}

// ------------------------------------------------------------------ restoring: preview and start
/** Params from the agent made readable: sizes, dates, lists, the reasons behind a "why" */
function nice(params) {
  const out = {};
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v === null || v === undefined) out[k] = '';
    else if (Array.isArray(v)) out[k] = v.join(', ');
    else if (typeof v === 'object') return;
    else if (['need', 'free', 'bytes'].includes(k) && typeof v === 'number') out[k] = fmt.size(v);
    else if (['when', 'time'].includes(k) && typeof v === 'number') out[k] = fmt.date(v);
    else if (k === 'why') out[k] = T('why.' + v);
    else out[k] = v;
  });
  return out;
}

/** A blocker or a refusal in words */
const problemText = (p) => Office.errorText({ key: p.key, params: nice(p.params) }, ID);

/** One step of a plan or a journal in words */
function stepText(s) {
  let key = s.do;
  if (s.do === 'start' && s.only_stopped) key = 'start_again';
  if (s.do === 'play' && s.immich) key = 'play_immich';
  if (s.do === 'aside' && s.optional) key = 'aside_optional';
  return T('step.' + key, nice(s));
}

function listBlock(title, items) {
  const box = el('div', 'rs-pv-part');
  box.appendChild(el('div', 'rs-part-title', title));
  const ul = el('ul', 'rs-pv-list');
  items.forEach((it) => ul.appendChild(typeof it === 'string' ? el('li', '', it) : (() => { const li = el('li'); li.append(it); return li; })()));
  box.appendChild(ul);
  return box;
}

/** What a restore will do: where from, the steps, what goes aside where, what stops how long, sizes, what to know */
function previewView(p, sizes) {
  const box = el('div', 'rs-preview');
  (p.blockers || []).forEach((b) => box.appendChild(el('p', 'callout warn', problemText(b))));
  if (p.share_now && p.share_now.state === 'missing') oldSettingsLines(p.share_now).forEach((x) => box.appendChild(x));
  if (p.moment) box.appendChild(momentView(p.moment));
  if (p.source) {
    const src = el('p', 'role rs-pv-src');
    src.append(T('rd.from'), ' ', el('code', '', p.source.path || ''));
    const bits = [p.source.time ? date(p.source.time) : '', p.source.bytes ? fmt.size(p.source.bytes) : ''].filter(Boolean);
    if (bits.length) src.append(' · ', bits.join(' · '));
    box.appendChild(src);
  }
  (p.notes || []).forEach((n) => box.appendChild(el('p', n.warn ? 'callout warn' : n.key.startsWith('note.method_') ? 'callout' : 'role', T(n.key, nice(n.params)))));
  if ((p.steps || []).length) {
    const ol = el('ol', 'rs-pv-steps');
    p.steps.forEach((s) => ol.appendChild(el('li', '', stepText(s))));
    const part = el('div', 'rs-pv-part');
    part.append(el('div', 'rs-part-title', T('rd.steps')), ol);
    box.appendChild(part);
  }
  box.appendChild((p.aside || []).length ? listBlock(T('rd.aside'), p.aside.map((a) => T('aside.' + a.what, nice(a))))
    : el('p', 'role', T('rd.aside_none')));
  box.appendChild(el('p', 'role', (p.stops || []).length ? T('rd.stops', { names: p.stops.join(', '), time: fmt.duration(Math.max(60, p.downtime || 0)) }) : T('rd.no_stops')));
  const sz = sizes || p.sizes;
  if (sz) {
    if (sz.measuring) box.appendChild(el('p', 'role rs-measuring', T('rd.measuring')));
    else if (sz.need !== null && sz.need !== undefined) box.appendChild(el('p', 'role', T(sz.free !== null && sz.free !== undefined ? 'rd.sizes' : 'rd.size', { need: fmt.size(sz.need), free: fmt.size(sz.free || 0) })));
  }
  if ((p.after || []).length) box.appendChild(listBlock(T('rd.after'), p.after.map((a) => T(a.key, nice(a.params)))));
  if (p.kind !== 'config' && p.kind !== 'kopia') box.appendChild(el('p', 'role', T('rd.failstop')));
  return box;
}

/** Step 4's source: the moment, and per part of the share (pool or disk) what it holds of it */
function momentView(m) {
  const wrap = el('div', 'rs-pv-part');
  const src = el('p', 'role rs-pv-src');
  src.append(T('rd.from'), ' ', el('strong', '', m.kopia ? T('rd.snap_kopia', { name: m.name }) : m.name), ' · ', date(m.time));
  wrap.appendChild(src);
  const ul = el('ul', 'rs-pv-list');
  (m.parts || []).forEach((x) => {
    const li = el('li');
    li.append(el('strong', '', x.base === 'kopia' ? 'Kopia' : x.base), ': ');
    if (x.asleep) li.append(T('rd.part_asleep'));
    else if (!x.covered) li.append(T(x.content ? 'rd.part_uncovered_content' : 'rd.part_uncovered'));
    else if (!x.holds) li.append(T('rd.part_empty'));
    else li.append(copyCode(x.path));
    ul.appendChild(li);
  });
  wrap.appendChild(ul);
  return wrap;
}

/**
 * A restore's dialog: options (files: which moment, a whole share's entries, copy or swap, wake), the preview from the agent,
 * an explicit «I have read it», then the start with the preview's token — the agent builds the plan
 * again and runs it only when it is still the same.
 */
async function restoreDialog(req, title) {
  const body = el('div', 'rs-dlg');
  const opts = el('div', 'rs-opts');
  const pv = el('div');
  const ok = el('label', 'check rs-confirm');
  const okBox = el('input');
  okBox.type = 'checkbox';
  ok.append(okBox, el('span', '', T('rd.confirm')));
  body.append(opts, pv, ok);
  let plan = null;
  let sizes = null;
  let timer = null;
  let ask = { ...req };
  let seq = 0;                   // the newest preview asked for: an older answer arriving late is dropped
  let sizeMap = null;            // path -> {bytes}: what the agent measured in the background
  let entrySig = '';
  const d = Office.dialog({
    title,
    body,
    wide: true,
    onClose: () => clearTimeout(timer),
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T(req.kind === 'putback' ? 'rd.go_putback' : 'rd.go'), kind: 'danger', act: start },
    ],
  });
  const go = d.buttons[1];
  const ready = () => !!plan && !(plan.blockers || []).length && (plan.steps || []).length && okBox.checked && !(sizes || plan.sizes || {}).measuring;
  const update = () => { go.disabled = !ready(); };
  okBox.onchange = update;

  // a choice changes only its own field of what is asked — the others (the entries ticked, copy or swap, wake) stay,
  // also when it comes from options drawn before the last preview arrived; a function gets what is asked now
  const onChange = (patch) => { ask = { ...ask, ...(typeof patch === 'function' ? patch(ask) : patch) }; preview(); };
  let sleepers = [];             // the parts seen asleep: named on «wake» also once they are awake
  const options = () => {
    entrySig = JSON.stringify(((plan && plan.options && plan.options.entries) || []).map((e) => entryBytes(e, sizeMap)));
    filesOptions(opts, plan, ask, onChange, sizeMap, sleepers);
  };

  async function preview() {
    clearTimeout(timer);
    const mine = ++seq;
    go.disabled = true;
    pv.innerHTML = '';
    // «wake» ticked while a part sleeps: the agent wakes it and waits until it answers (seconds, up to a minute or so)
    const waking = ask.wake && plan && plan.options ? (plan.options.asleep || []) : [];
    if (waking.length) {
      const w = el('p', 'callout running rs-waking');
      w.append(el('span', 'spin'), ' ', T('rd.waking', { base: waking.join(', ') }));
      pv.appendChild(w);
    } else pv.appendChild(el('p', 'role', T('rd.loading')));
    const j = await Office.api.post(`${ID}.preview`, { ...ask, ...(plan ? { stamp: plan.stamp } : {}) });
    if (mine !== seq) return;
    pv.innerHTML = '';
    if (!j.ok) { plan = null; pv.appendChild(el('p', 'callout warn', Office.errorText(j.error, ID))); update(); return; }
    plan = j.preview;
    sizes = plan.sizes;
    if (plan.options) sleepers = [...new Set([...sleepers, ...(plan.options.asleep || []), ...(plan.options.woken || [])])];
    if (plan.options && req.kind === 'files') options();
    pv.appendChild(previewView(plan, sizes));
    update();
    if ((sizes && sizes.measuring) || entriesPending()) watchSize();
  }

  // the entries of a whole share whose size isn't known yet (measured in the background, for the choice)
  const entriesPending = () => ((plan && plan.options && plan.options.entries) || []).some((e) => entryBytes(e, sizeMap) === null);

  // sizes are measured in the background: the page asks until they are there
  async function watchSize() {
    const mine = seq;
    const j = await Office.api.get({ a: 'part', desk: ID, part: 'sizes' });
    if (mine !== seq || !plan || !Office.dialogOpen()) return;
    sizeMap = (j.ok && j.part && j.part.sizes) || {};
    if (req.kind === 'files' && plan.options && JSON.stringify((plan.options.entries || []).map((e) => entryBytes(e, sizeMap))) !== entrySig) options();
    const paths = sizes ? (sizes.paths || (sizes.path ? [sizes.path] : [])) : [];
    const got = paths.map((x) => sizeMap[x]);
    if (sizes && sizes.measuring && got.length && got.every((g) => g && g.bytes !== null && g.bytes !== undefined)) {
      sizes = { ...sizes, need: got.reduce((sum, g) => sum + g.bytes, 0), measuring: false };
      if (sizes.free !== null && sizes.free !== undefined && sizes.need > sizes.free * 0.95) { await preview(); return; }    // the agent says it won't fit
      pv.innerHTML = '';
      pv.appendChild(previewView(plan, sizes));
      update();
    }
    if ((sizes && sizes.measuring) || entriesPending()) timer = setTimeout(watchSize, JOB_POLL);
  }

  async function start() {
    if (!ready()) return false;
    const j = await Office.api.post(`${ID}.start`, { ...ask, stamp: plan.stamp, token: plan.token });
    if (!j.ok) {
      if (j.error && j.error.key === 'restore_changed') { Office.toast(Office.errorText(j.error, ID), true); await preview(); okBox.checked = false; update(); return false; }
      Office.toast(j.error ? problemText(j.error) : Office.errorText(j.error, ID), true);
      return false;
    }
    if (j.state) state = j.state;
    if (j.journal) job = j.journal;
    Office.toast(T('rd.started', { what: plan.what }));
    section = 'journal';
    Office.store('restore.section', section);
    expanded.add('j:' + j.id);
    render();
    pollJob();
    return true;
  }

  preview();
}

/**
 * Step 4's options: which moment (newest first, with the parts of the share it covers), a whole share's entries
 * (with their sizes), copy next to it or swap it in (put it in place, when nothing is there), wake a sleeping disk
 */
function filesOptions(box, plan, ask, change, sizeMap, sleepers) {
  box.innerHTML = '';
  const o = plan.options || {};
  if ((o.moments || []).length) {
    const f = el('div', 'field');
    const label = el('label', '', T('rd.snap'));
    const sel = el('select', 'input');
    sel.id = 'rs-snap';
    label.htmlFor = sel.id;
    if (!plan.target.snap) {
      const op = el('option', '', T('rd.m_choose'));
      op.value = '';
      op.selected = true;
      sel.appendChild(op);
    }
    o.moments.forEach((m) => {
      const op = el('option', '', momentLabel(m, o));
      op.value = m.id;
      op.selected = m.id === plan.target.snap;
      sel.appendChild(op);
    });
    sel.onchange = () => change({ snap: sel.value });
    f.append(label, sel);
    box.appendChild(f);
  }
  if (o.whole && (o.entries || []).length) box.appendChild(entriesField(o.entries, plan, change, sizeMap));
  const way = el('div', 'field');
  way.appendChild(el('div', 'field-title', T('rd.way')));
  ['copy', 'swap'].forEach((m) => {
    const l = el('label', 'check');
    const r = el('input');
    r.type = 'radio';
    r.name = 'rs-way';
    r.checked = (ask.mode || plan.target.mode || 'copy') === m;
    r.onchange = () => change((now) => ({ mode: m, snap: now.snap || plan.target.snap || '' }));
    const key = m === 'swap' && o.nothing_live ? 'rd.way_place' : 'rd.way_' + m;
    const text = el('span', '', T(key));
    text.appendChild(el('small', '', T(key + '_hint')));
    l.append(r, text);
    way.appendChild(l);
  });
  box.appendChild(way);
  if ((o.asleep || []).length || ask.wake) {
    const l = el('label', 'check');
    const c = el('input');
    c.type = 'checkbox';
    c.checked = !!ask.wake;
    c.onchange = () => change({ wake: c.checked });
    const names = (o.asleep || []).length ? o.asleep : (o.woken || []).length ? o.woken : (sleepers || []);
    const text = el('span', '', T('rd.wake', { base: names.join(', ') || '–' }));
    text.appendChild(el('small', '', T(ask.wake && !(o.asleep || []).length ? 'rd.woke' : 'rd.wake_hint', { base: names.join(', ') || '–' })));
    l.append(c, text);
    box.appendChild(l);
  }
}

/** A moment in the list: when, its name, whose, which parts of the share it covers, whether it holds anything of it */
function momentLabel(m, o) {
  if (m.kopia) return `${date(m.time)} — ${T('rd.snap_kopia', { name: m.name })}`;
  const bits = [`${date(m.time)} — ${m.name}`];
  if (m.ours) bits.push(T('rd.snap_ours'));
  if ((o.parts || []).length > 1) bits.push(m.bases.length >= o.parts.length ? T('rd.m_all') : T('rd.m_some', { bases: m.bases.join(' + ') }));
  if (m.holds && !m.holds.length) bits.push(T('rd.m_empty'));
  return bits.join(' · ');
}

/**
 * A whole share's entries at its top: all of them, or the ones ticked. What is ticked stays across re-plans (another
 * moment, copy or swap, wake); an entry the chosen moment doesn't hold drops out.
 */
function entriesField(entries, plan, change, sizeMap) {
  const f = el('div', 'field rs-entries');
  f.appendChild(el('div', 'field-title', T('rd.items')));
  const chosen = new Set(plan.target.items || entries.map((e) => e.name));
  const send = () => change((now) => ({ snap: now.snap || plan.target.snap, items: entries.map((e) => e.name).filter((n) => chosen.has(n)) }));
  const all = el('label', 'check');
  const allBox = el('input');
  allBox.type = 'checkbox';
  allBox.checked = entries.every((e) => chosen.has(e.name));
  allBox.onchange = () => { entries.forEach((e) => (allBox.checked ? chosen.add(e.name) : chosen.delete(e.name))); send(); };
  all.append(allBox, el('span', '', T('rd.items_all', { share: (plan.options || {}).share || '' })));
  f.appendChild(all);
  const multi = ((plan.options || {}).parts || []).length > 1;
  entries.forEach((e) => {
    const l = el('label', 'check rs-entry');
    const c = el('input');
    c.type = 'checkbox';
    c.checked = chosen.has(e.name);
    c.onchange = () => { if (c.checked) chosen.add(e.name); else chosen.delete(e.name); send(); };
    const text = el('span', '', e.kind === 'file' ? e.name : `${e.name}/`);
    const bytes = entryBytes(e, sizeMap);
    const meta = [bytes !== null ? fmt.size(bytes) : T('rd.item_measuring'), T(e.live ? 'rd.item_there' : 'rd.item_not_there')];
    if (multi && (e.bases || []).length) meta.push(e.bases.join(' + '));
    text.appendChild(el('small', '', meta.join(' · ')));
    l.append(c, text);
    f.appendChild(l);
  });
  return f;
}

/** An entry's size: from the plan, or from what was measured since (all of its parts), null while not known */
function entryBytes(e, sizeMap) {
  if (e.bytes !== null && e.bytes !== undefined) return e.bytes;
  const paths = e.paths || [];
  if (!sizeMap || !paths.length) return null;
  let sum = 0;
  for (const x of paths) {
    const got = sizeMap[x];
    if (!got || got.bytes === null || got.bytes === undefined) return null;
    sum += got.bytes;
  }
  return sum;
}

/** Step 6: which Kopia snapshot of a source — Kopia is asked first (seconds) — then the usual preview */
async function kopiaDialog(source, path) {
  const body = el('div');
  body.appendChild(el('p', 'role', T('kr.loading', { source: path })));
  const d = Office.dialog({ title: T('rd.title.kopia', { what: path }), body, buttons: [{ text: Office.t('common.cancel') }] });
  const j = await Office.api.post(`${ID}.kopia_list`, { source });
  if (!Office.dialogOpen()) return;
  body.innerHTML = '';
  if (!j.ok) { body.appendChild(el('p', 'callout warn', Office.errorText(j.error, ID))); return; }
  if (!j.snapshots.length) { body.appendChild(el('p', 'role', T('kr.none_snaps'))); return; }
  body.appendChild(el('p', 'role', T('kr.choose')));
  const list = el('div', 'box');
  j.snapshots.slice(0, 60).forEach((s) => {
    const r = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', date(s.time)));
    const meta = el('div', 'row-meta');
    meta.append(el('span', '', fmt.size(s.bytes)), el('span', '', T('kr.files', { n: s.files })));
    if (s.description) meta.appendChild(el('span', '', s.description));
    if (s.incomplete) meta.appendChild(chip(T('kr.incomplete'), 'warn', s.incomplete));
    main.appendChild(meta);
    r.appendChild(main);
    const right = el('div', 'rs-right');
    right.appendChild(button(T('kr.pick'), 'small', () => { d.close(); restoreDialog({ kind: 'kopia', source, snapshot: s.id }, T('rd.title.kopia', { what: path })); }));
    r.appendChild(right);
    list.appendChild(r);
  });
  body.appendChild(list);
}

// ------------------------------------------------------------------ the journal
function journalSection() {
  const list = state.restores || [];
  const s = sectionBox(T('journal'), T('journal_sub'), list.length > 1 ? unfoldAll() : null);
  if (!list.length && !runningJob()) { s.appendChild(el('p', 'empty', T('journal_none'))); return s; }
  const box = el('div', 'box');
  // the running one from the job file (fresher than the agent's list)
  const rows = list.slice();
  if (job && !rows.some((r) => r.id === job.id)) rows.unshift({ ...job, can_putback: false, failed: null });
  rows.forEach((r) => box.appendChild(journalRow(job && job.id === r.id ? { ...r, result: job.result, finished: job.finished } : r)));
  s.appendChild(box);
  return s;
}

const resultClass = (r) => ({ ok: 'ok', warnings: 'warn', failed: 'danger', refused: 'warn', interrupted: 'danger', running: 'accent', queued: 'quiet' }[r] || '');

function journalTitle(r) {
  return r.kind === 'putback' ? T('jk.putback', { what: r.what }) : T('jk.' + r.kind, { what: r.what });
}

function journalRow(r) {
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('jr.' + r.result), resultClass(r.result), r.reason ? problemText({ key: r.reason, params: r.reason_params }) : null));
  meta.appendChild(el('span', '', date(r.started || r.created)));
  if (r.finished && r.started) meta.appendChild(el('span', '', fmt.duration(Math.max(60, r.finished - r.started))));
  if (r.putback) meta.appendChild(chip(T('j.put_back', { result: T('jr.' + r.putback.result) }), 'quiet', T('j.put_back_hint')));
  if (r.failed) meta.appendChild(el('span', 'role', T('j.failed_at', { n: r.failed.n })));
  return unfoldingRow('j:' + r.id, journalTitle(r), meta, () => journalDetail(r));
}

/** A journal unfolded: its steps (with what each found), what went aside where, what to do afterwards, the log; «Put back» */
function journalDetail(r) {
  const box = el('div');
  const live = job && job.id === r.id && ['queued', 'running'].includes(job.result) ? job : null;
  const have = journals.get(r.id);
  const fill = (j, log) => {
    box.innerHTML = '';
    if (r.putback_of) box.appendChild(el('p', 'role', T('j.putback_of')));
    if (r.reason) box.appendChild(el('p', 'callout warn', problemText({ key: r.reason, params: r.reason_params })));
    const ol = el('ol', 'rs-pv-steps rs-j-steps');
    (j.steps || []).forEach((s) => {
      const li = el('li', 'rs-j-' + (s.state || 'pending'));
      li.append(chip(T('state.' + (s.state || 'pending')), resultClass({ ok: 'ok', warning: 'warnings', failed: 'failed', running: 'running' }[s.state] || 'queued')), ' ', stepText(s));
      const pr = s.progress;
      if (pr && s.state === 'running') {
        const pct = pr.percent !== null && pr.percent !== undefined ? pr.percent : pr.total ? Math.floor(100 * pr.done / pr.total) : null;
        const line = pr.line || (pct !== null ? `${pct} %` : pr.done ? fmt.size(pr.done) : '');
        if (line) li.append(' · ', line);
        if (pct !== null) { const bar = el('div', 'bar thin rs-bar'); const i = el('i', 'data'); i.style.width = Math.min(100, pct) + '%'; bar.appendChild(i); li.appendChild(bar); }
      }
      if (s.note && s.state !== 'running') li.appendChild(el('div', 'role', T('sn.' + s.note, nice(s.params))));
      if (s.detail) li.appendChild(el('pre', 'code rs-j-detail', String(s.detail)));
      ol.appendChild(li);
    });
    box.appendChild(ol);
    const aside = (j.aside || []);
    if (aside.length) box.appendChild(listBlock(T('rd.aside'), aside.map((a) => { const x = el('span'); x.append(copyCode(a.to), ' ← ', a.from.replace(/^(db|vm):/, '')); return x; })));
    if ((j.after || r.after || []).length && ['ok', 'warnings'].includes(j.result || r.result)) box.appendChild(listBlock(T('rd.after'), (j.after || r.after).map((a) => T(a.key, nice(a.params)))));
    if (log && log.length) box.appendChild(fold(T('j.log'), el('pre', 'code rs-j-log', log.join('\n'))));
    if (r.can_putback) {
      const p = el('div', 'rs-act');
      p.append(restoreButton(T('j.putback'), { kind: 'putback', id: r.id }, T('rd.title.putback', { what: journalTitle(r) })), ' ', el('span', 'role', T('j.putback_hint')));
      box.appendChild(p);
    } else if (!live && ['ok', 'warnings'].includes(r.result) && !r.putback && r.kind !== 'putback') box.appendChild(el('p', 'role', T(r.kind === 'kopia' ? 'j.no_putback_kopia' : 'j.no_putback')));
  };
  const fail = (error) => { box.innerHTML = ''; box.appendChild(el('p', 'callout warn', Office.errorText(error, ID))); };
  // the page may draw this row anew while the journal is on its way: the answer goes to the box shown then
  journalShown.set(r.id, { box, fill, fail });
  if (live) fill(live, null);
  else if (have && have.journal) fill(have.journal, have.log);
  else if (have && have.error) fail(have.error);      // «Look again» asks again
  else {
    box.appendChild(el('p', 'role', Office.t('common.loading')));
    if (!have) {
      journals.set(r.id, { loading: true });
      Office.api.post(`${ID}.journal`, { id: r.id }).then((j) => {
        journals.set(r.id, j.ok ? { journal: j.journal, log: j.log } : { error: j.error });
        const to = journalShown.get(r.id);
        if (!to || !to.box.isConnected) return;  // folded meanwhile: unfolding shows it
        if (j.ok) to.fill(j.journal, j.log);
        else to.fail(j.error);
      });
    }
  }
  return box;
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root) {
    view = root;
    render();
    await load(false);
    pollJob();             // a restore started elsewhere shows at once
  },
  unmount() { view = null; clearTimeout(jobTimer); jobTimer = null; },
  poll() { load(false); },
  agentChanged() { if (view) render(); },
  async reception() {
    if (!state) await load(false);
    if (!state) return null;
    const facts = [];
    if (apps().length) facts.push(T('fact.apps', { n: apps().length, when: date(state.run_time) }));
    if (vms().length) facts.push(T('fact.vms', { n: vms().length }));
    if (kopia().enabled) facts.push(T('fact.kopia'));
    return { bubble: bubbleText(), facts };
  },
});
})();
