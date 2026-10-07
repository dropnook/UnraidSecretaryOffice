/* Ms. Protocolli — reads every log out loud, understands none of it. Pick a
   source (the office's own, Unraid's, User Scripts, containers), how many
   lines, follow it live like tail -f, filter by text or "errors and warnings
   only", copy or download what is shown. Favourites (remembered in this
   browser) come first in the list and as buttons under it.
   Her tour (in the background on the server) counts and groups: how full
   /var/log is and its biggest files, the containers' logs and Docker's
   rotation, and per log the lines that sound like an error or a warning —
   similar ones as one entry; "Show in the log" opens the reader at that line.
   The agent part lives in agent/desks/logs.php; lines always go into the page
   as text, never as HTML. */
(() => {
'use strict';

const ID = 'logs';
const T = Office.scope(ID);
const { el, fmt } = Office;
const FOLLOW_MS = 2000;
const TOUR_POLL_MS = 1500;
const LINE_CHOICES = [100, 500, 2000, 10000];
const SRC_TILES = 12;           // small tiles per log at most (the last one then says how many more)
const GROUPS = ['office', 'unraid', 'userscripts', 'containers'];
// favourites until someone stars or unstars one: Unraid's main logs and a few of the office's (missing ones drop out)
const DEFAULT_FAVS = ['syslog', 'dmesg', 'docker', 'agent', 'backup:latest', 'container:kopia', 'embycache'];

let state = null;
let tour = null;                // her last tour (data/logs-tour.json, the part "tour")
let view = null;
let lines = [];                 // what was read, all of it (up to the line count)
let offset = null;              // follow position in a file
let timer = null;
let tourTimer = null;
let tourSeenRunning = false;    // this page saw the tour running: say when it is done
let loading = false;
let mark = null;                // a line from the tour to show in the reader: {sample, cut}
let markScroll = false;         // scroll to it after the next reading
let shownKinds = [];            // the unfoldable rows of the open list: {open(), set(open)}
const unfolded = new Set();     // kinds unfolded on this visit
let tile = Office.store('logs.tile') || '';     // the open tile: varlog, docker, found, src:<id> — '' none
const opts = {
  source: Office.store('logs.source') || '',          // nothing is read until a log is chosen
  lines: Number(Office.store('logs.lines')) || 500,
  follow: Office.store('logs.follow') !== '0',
  wrap: Office.store('logs.wrap') === '1',
  only: Office.store('logs.only') === '1',
  query: '',
};
let favs = Office.storeJson('logs.favorites') || DEFAULT_FAVS.slice();    // source ids, in the order they were starred

/* What sounds like an error or a warning — the same rules as her tour (agent/desks/logs.php,
   LOGS_OWN_LEVEL …; tests/run.php checks that the patterns are the same): a line that names
   its own level goes by it, every other line by its words. */
const OWN_LEVEL = /\b(?:level|lvl|severity)"?\s*[=:]\s*"?([a-z]+)|\[([a-z]+)\]|\|\s*([a-z]+)\s*\||:\s([a-z]+)\s:\s|^\[\d\d:\d\d:\d\d\s([a-z]+)\s|\bPHP (fatal error|parse error|warning|notice|deprecated)\b/gi;
const OWN_UPPER = /(?:^|\s)(INF|WRN|ERR|DBG|FTL|TRC|LOG|INFO|WARN|WARNING|ERROR|DEBUG|FATAL|VERBOSE|NOTICE|CRITICAL)(?=[\s:\]])/g;
const LEVEL_NAMES = {
  error: 'error', err: 'error', eror: 'error', fatal: 'error', ftl: 'error', crit: 'error', critical: 'error', alert: 'error',
  emerg: 'error', panic: 'error', 'fatal error': 'error', 'parse error': 'error', warn: 'warn', warning: 'warn', wrn: 'warn',
  info: '', inf: '', debug: '', dbug: '', dbg: '', notice: '', trace: '', trc: '', log: '', verbose: '', deprecated: '',
};
const NOT_COUNTED = /^[<>ch.*][fdlsp][.+?a-z]{9,10}\s.*|\b(?:0|no|zero|without|keine?)\s+(?:errors?|warnings?|failures?|failed|fehler|warnungen)\b|\b(?:errors?|warnings?|failures?|failed|fehler|err|warn)\s*[=:]\s*(?:0|none|nil|null|false)\b|\berror\.log\b|\berrors=remount-ro\b/gi;
const ERROR = /\b(?:error|err|eror|fail(?:s|ed|ure)?|fatal|panic|crit(?:ical)?|emerg|segfault|denied|oops|call trace|i\/o error|traceback|exception|fehler)\b|\bexit(?:ed)?(?: with)? (?:code|status)[ =:]*[1-9]/i;
const WARN = /\b(?:warn(?:ing)?|wrn|timeout|timed out|retry|retrying|warnung)\b/i;
function level(line) {
  const head = line.slice(0, 300);
  for (const re of [OWN_LEVEL, OWN_UPPER]) {
    for (const m of head.matchAll(re)) {
      const name = (m.slice(1).filter((x) => x !== undefined).pop() || '').toLowerCase();
      if (Object.hasOwn(LEVEL_NAMES, name)) return LEVEL_NAMES[name];
    }
  }
  const text = line.replace(NOT_COUNTED, ' ');
  return ERROR.test(text) ? 'error' : WARN.test(text) ? 'warn' : '';
}

// ------------------------------------------------------------------ loading
/** Her state: as kept at once, a new look following on her page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view) { renderHead(); fillSources(); renderTiles(); }
}

/** Her last tour; while one runs, look again every 1.5 s */
async function loadTour() {
  try {
    const j = await Office.api.get({ a: 'part', desk: ID, part: 'tour' });
    if (j.ok) tour = j.part && typeof j.part === 'object' ? j.part : null;
  } catch (e) { /* keep what we have */ }
  watchTour();
}

const touring = () => !!(tour && tour.job && tour.job.running);

function watchTour() {
  clearTimeout(tourTimer);
  if (!touring()) return;
  tourSeenRunning = true;
  tourTimer = setTimeout(async () => {
    await loadTour();
    if (touring()) return;
    if (tourSeenRunning && view) {
      tourSeenRunning = false;
      if (tour && tour.job && tour.job.failed) Office.toast(T('tour_failed'), true);
      else if (tour) Office.toast(T('tour_done', { ms: tour.duration_ms || 0 }));
    }
    if (view) Office.keepInPlace(view.tiles, renderTour);
  }, TOUR_POLL_MS);
}

async function startTour() {
  if (touring() || !Office.agent.running) return;
  const j = await Office.api.post(`${ID}.tour`, {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  if (j.tour) tour = j.tour;
  if (view) renderTour();
  watchTour();
}

function source() { return (state?.sources || []).find((s) => s.id === opts.source) || null; }
const sourceById = (id) => (state?.sources || []).find((s) => s.id === id) || null;

/** Read the source anew (all of it) or — following a file — only what was added */
async function read(fresh) {
  if (loading || !view || !opts.source) return;
  loading = true;
  const req = { source: opts.source, lines: opts.lines };
  if (!fresh && offset !== null) req.offset = offset;
  const j = await Office.api.post(`${ID}.read`, req);
  loading = false;
  if (!view) return;
  if (!j.ok) {
    lines = [];
    offset = null;
    view.status.textContent = Office.errorText(j.error, ID);
    renderLines(true);
    return;
  }
  const atBottom = view.out.scrollTop + view.out.clientHeight >= view.out.scrollHeight - 40;
  if (fresh || j.reset || j.follow === 'replace' || offset === null) lines = j.lines;
  else lines = lines.concat(j.lines).slice(-opts.lines);
  offset = j.follow === 'append' ? j.offset : null;
  view.meta = j;
  renderLines((fresh || atBottom) && !markScroll);
  if (markScroll) showMark();
}

function schedule() {
  clearTimeout(timer);
  if (!view || !opts.follow) return;
  timer = setTimeout(async () => {
    if (!document.hidden && !Office.dialogOpen()) await read(false);
    schedule();
  }, FOLLOW_MS);
}

// ------------------------------------------------------------------ rendering
function build(root) {
  const v = {};
  v.tourBtn = el('button', 'btn plain');
  v.tourBtn.type = 'button';
  v.tourBtn.append(el('span', 'spin'), T('tour'));
  v.tourBtn.title = T('tour_title');
  v.tourBtn.onclick = startTour;
  const { head, bubble } = Office.deskHead(Office.desks.get(ID), { bubble: '', actions: [v.tourBtn] });
  v.bubble = bubble;
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID, [
    [T('tour'), T('help.tour_text')],
    [T('help.counted'), T('help.counted_text')],
    [T('help.grouped'), T('help.grouped_text')],
    [T('help.since'), T('help.since_text')],
    [T('help.space'), T('help.space_text')],
    [T('help.source'), T('help.source_text')],
    [T('live'), T('help.live')],
    [T('only_problems'), T('help.only')],
    [T('help.favorites'), T('help.favorites_text')],
    [el('span', 'lg-sample error', T('help.red')), T('help.colours')],
    [T('help.safe'), T('help.safe_text')],
  ]));

  // her tour: tiles, and under them the list of the open one
  const t = el('section', 'section lg-tour');
  v.tourHint = el('span', 'hint');
  t.appendChild(Office.sectionHead(T('tour_section'), T('tour_section_sub'), v.tourHint));
  v.tiles = el('div', 'cards');
  v.srcTiles = el('div', 'lg-srcs');
  v.tourBody = el('div', 'lg-tour-body');
  t.append(v.tiles, v.srcTiles, v.tourBody);
  root.appendChild(t);

  const s = el('section', 'section');
  v.readSection = s;
  v.hint = el('span', 'hint');
  s.appendChild(Office.sectionHead(T('reading'), T('reading_sub'), v.hint));

  const bar = el('div', 'toolbar lg-bar');
  // the log picker: a button that opens a list with a search field (the lists are long)
  v.picker = el('div', 'lg-picker');
  v.source = el('button', 'picker lg-source');
  v.source.type = 'button';
  v.source.setAttribute('aria-haspopup', 'listbox');
  v.source.onclick = () => (v.pop.hidden ? openPicker() : closePicker());
  v.pop = el('div', 'lg-pop');
  v.pop.hidden = true;
  v.find = el('input', 'search lg-find');
  v.find.type = 'search';
  v.find.placeholder = T('find_log');
  v.find.spellcheck = false;
  v.find.dataset.keep = '1';          // built once: typing here never holds up a new look (core.js calm())
  v.find.oninput = renderList;
  v.find.onkeydown = (e) => {
    if (e.key === 'Escape') { closePicker(); v.source.focus(); }
    if (e.key === 'Enter') { const first = v.list.querySelector('.lg-item'); if (first) first.click(); }
    if (e.key === 'ArrowDown') { const first = v.list.querySelector('.lg-item'); if (first) { e.preventDefault(); first.focus(); } }
  };
  v.list = el('div', 'lg-list');
  v.list.setAttribute('role', 'listbox');
  v.pop.append(v.find, v.list);
  v.picker.append(v.source, v.pop);
  v.star = el('button', 'btn small plain lg-star');
  v.star.type = 'button';
  v.star.onclick = toggleFav;
  v.lines = el('select', 'picker');
  v.lines.setAttribute('aria-label', T('lines_label'));
  v.lines.dataset.keep = '1';
  LINE_CHOICES.forEach((n) => v.lines.appendChild(new Option(T('lines', { n }), String(n))));
  v.lines.value = String(opts.lines);
  v.lines.onchange = () => { opts.lines = Number(v.lines.value); Office.store('logs.lines', String(opts.lines)); restart(); };
  v.query = el('input', 'search');
  v.query.type = 'search';
  v.query.placeholder = T('filter');
  v.query.spellcheck = false;
  v.query.dataset.keep = '1';
  v.query.oninput = () => { opts.query = v.query.value; renderLines(false); };
  const sw = (key, text, onchange) => {
    const label = el('label', 'switch');
    const input = el('input');
    input.type = 'checkbox';
    input.checked = opts[key];
    input.onchange = () => { opts[key] = input.checked; Office.store('logs.' + key, input.checked ? '1' : '0'); onchange(); };
    label.append(input, el('span', '', text));
    return label;
  };
  v.followSw = sw('follow', T('live'), () => { if (opts.follow) read(false).then(schedule); else clearTimeout(timer); });
  const onlySw = sw('only', T('only_problems'), () => renderLines(false));
  const wrapSw = sw('wrap', T('wrap'), () => view.out.classList.toggle('wrap', opts.wrap));
  const copy = el('button', 'btn small plain', Office.t('common.copy'));
  copy.type = 'button';
  copy.onclick = () => Office.copy(shown().join('\n'));
  const save = el('button', 'btn small plain', T('download'));
  save.type = 'button';
  save.onclick = download;
  bar.append(v.picker, v.star, v.lines, v.query, v.followSw, onlySw, wrapSw, copy, save);
  s.appendChild(bar);
  v.favs = el('div', 'lg-favs');
  s.appendChild(v.favs);
  // a click outside the open list closes it
  root.addEventListener('click', (e) => { if (!v.pop.hidden && !v.picker.contains(e.target)) closePicker(); });

  v.out = el('pre', 'code lg-out' + (opts.wrap ? ' wrap' : ''));
  v.out.setAttribute('tabindex', '0');
  v.status = el('p', 'role lg-status');
  s.append(v.out, v.status);
  root.appendChild(s);
  return v;
}

function renderHead() {
  if (!view) return;
  view.bubble.innerHTML = '';
  view.bubble.append(Office.withGreeting(ID, bubbleText()));
  view.tourBtn.disabled = !Office.agent.running || touring();
  view.tourBtn.classList.toggle('running', touring());
}

/** /var/log's fill level: now (state), else from the tour */
function varlog() {
  const v = state?.varlog?.ok ? state.varlog : tour?.varlog?.ok ? tour.varlog : null;
  return v ? { warn: 60, full: 80, ...v } : null;
}
const fillLevel = (v) => (v.pct >= v.full ? 'danger' : v.pct >= v.warn ? 'warn' : '');
const noisy = () => (tour?.sources || []).filter((s) => s.errors || s.warnings);

function bubbleText() {
  if (!state && !tour) return T('bubble.loading');
  const parts = [];
  const v = varlog();
  if (v && v.own && v.pct >= v.full) parts.push(T('bubble.varlog_full', { pct: v.pct }));
  else if (v && v.own && v.pct >= v.warn) parts.push(T('bubble.varlog_much', { pct: v.pct }));
  if (touring()) parts.push(T('bubble.touring'));
  else if (tour && tour.time) {
    parts.push(tour.errors || tour.warnings
      ? T('bubble.tour', { when: fmt.relative(tour.time), errors: tour.errors || 0, warnings: tour.warnings || 0, logs: noisy().length })
      : T('bubble.tour_calm', { when: fmt.relative(tour.time) }));
  } else if (state) {
    const e = state.syslog?.errors || 0;
    const w = state.syslog?.warnings || 0;
    parts.push(!e && !w ? T('bubble.calm') : T('bubble.counted', { errors: e, warnings: w }), T('bubble.no_tour'));
  }
  return parts.join(' ');
}

// ------------------------------------------------------------------ the tour: tiles
function chip(text, cls, tip) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (tip) c.title = tip;
  return c;
}

/** "12 × error · 3 × warning" */
function counts(errors, warnings) {
  const box = el('span', 'lg-counts');
  box.append(el('b', 'e', fmt.number(errors)), ' × ' + T('kind.error') + ' · ', el('b', 'w', fmt.number(warnings)), ' × ' + T('kind.warn'));
  return box;
}

function card(id, cls) {
  const c = el('button', 'card' + (cls ? ' ' + cls : '') + (tile === id ? ' active' : ''));
  c.type = 'button';
  c.setAttribute('aria-pressed', String(tile === id));
  c.onclick = () => openTile(tile === id ? '' : id);         // the open tile closes again
  return c;
}

function figures(left, right) {
  const f = el('div', 'card-figures');
  f.append(el('span', '', left), el('span', '', right || ''));
  return f;
}

function renderTour() {
  if (!view) return;
  renderHead();
  renderTiles();
  renderTourBody();
}

function renderTiles() {
  if (!view) return;
  view.tourHint.textContent = touring() ? T('touring') : tour && tour.time ? T('tour_at', { when: fmt.relative(tour.time) }) : '';
  view.tourHint.title = tour && tour.time ? fmt.date(tour.time) : '';
  const tiles = view.tiles;
  tiles.innerHTML = '';

  // /var/log — how full, right now
  const v = varlog();
  if (v) {
    const c = card('varlog');
    const head = el('div', 'card-head');
    head.append(el('span', 'card-name', '/var/log'), el('span', 'lg-pct ' + fillLevel(v), v.pct + ' %'));
    const bar = el('div', 'bar');
    const used = el('i', 'lg-used ' + fillLevel(v));
    used.style.width = Math.min(100, v.pct) + '%';
    bar.appendChild(used);
    const files = tour?.varlog?.count;
    c.append(head, bar, figures(T('tile.varlog_of', { used: fmt.size(v.used), total: fmt.size(v.total) }),
      files !== undefined ? T('tile.varlog_files', { n: files }) : ''));
    tiles.appendChild(c);
  }

  // the containers' logs
  const d = tour?.docker;
  const dc = card('docker');
  const dh = el('div', 'card-head');
  dh.appendChild(el('span', 'card-name', T('tile.docker')));
  if (d && d.ok) {
    const r = d.rotation || {};
    dc.append(dh, el('div', 'card-line', T('tile.docker_size', { size: fmt.size(d.total || 0) })),
      figures(T('tile.docker_count', { n: (d.containers || []).length }), r.on ? T('tile.rotation_on', { size: r.size || '?', files: r.files || '?' }) : T('tile.rotation_off')));
  } else {
    dc.classList.add('empty-card');
    dc.append(dh, el('div', 'card-line', !tour || !tour.time ? T('tile.no_tour') : d && d.enabled === false ? T('docker.disabled') : T('tile.docker_off')));
  }
  tiles.appendChild(dc);

  // what sounded like an error or a warning
  const fc = card('found');
  const fh = el('div', 'card-head');
  fh.appendChild(el('span', 'card-name', T('tile.found')));
  if (tour && tour.time) {
    const line = el('div', 'card-line');
    line.appendChild(counts(tour.errors || 0, tour.warnings || 0));
    fc.append(fh, line, figures(T('tile.found_logs', { n: noisy().length }), tour.first ? T('tile.found_first') : T('tile.found_since', { when: fmt.date(tour.since) })));
  } else {
    fc.classList.add('empty-card');
    fc.append(fh, el('div', 'card-line', T('tile.no_tour')));
  }
  tiles.appendChild(fc);

  // a small tile per log that had something (the loudest; the rest are in «Errors & warnings»)
  view.srcTiles.innerHTML = '';
  const loud = noisy();
  const shownTiles = loud.length > SRC_TILES ? loud.slice(0, SRC_TILES - 1) : loud;
  for (const s of shownTiles) {
    const c = card('src:' + s.id, 'lg-src-tile');
    c.title = sourceLabel(s);
    c.append(el('span', 'card-name', sourceLabel(s)), counts(s.errors, s.warnings));
    view.srcTiles.appendChild(c);
  }
  if (loud.length > shownTiles.length) {
    const rest = loud.slice(shownTiles.length);
    const c = el('button', 'card lg-src-tile lg-src-more');
    c.type = 'button';
    c.onclick = () => openTile('found');
    c.append(el('span', 'card-name', T('tile.more_logs', { n: rest.length })),
      counts(rest.reduce((n, s) => n + s.errors, 0), rest.reduce((n, s) => n + s.warnings, 0)));
    view.srcTiles.appendChild(c);
  }
  view.srcTiles.hidden = !view.srcTiles.children.length;
}

function openTile(id) {
  tile = id;
  Office.store('logs.tile', id || null);
  Office.keepInPlace(view.tiles, () => { renderTiles(); renderTourBody(); });
}

const sourceLabel = (s) => T('source.' + s.label, { name: s.param });

// ------------------------------------------------------------------ the tour: lists
function renderTourBody() {
  const body = view.tourBody;
  body.innerHTML = '';
  shownKinds = [];
  if (!tour || !tour.time) {
    const box = el('div', 'box');
    const e = el('div', 'empty');
    e.append(el('strong', '', T('no_tour_title')), touring() ? T('bubble.touring') : T('no_tour'));
    if (!touring()) {
      const b = el('button', 'btn', T('tour'));
      b.type = 'button';
      b.disabled = !Office.agent.running;
      b.onclick = startTour;
      e.append(el('br'), b);
    }
    box.appendChild(e);
    body.appendChild(box);
    return;
  }
  if (tile === 'varlog') body.appendChild(varlogView());
  else if (tile === 'docker') body.appendChild(dockerView());
  else if (tile === 'found') body.appendChild(foundView(null));
  else if (tile.startsWith('src:')) {
    const s = noisy().find((x) => 'src:' + x.id === tile);
    if (s) body.appendChild(foundView(s));
    else { tile = ''; Office.store('logs.tile', null); }
  }
}

/** "+1.2 MB since the last tour" — growth as a fact, how fast in its bubble */
function grewChip(grew) {
  if (grew === null || grew === undefined || tour.first) return null;
  const span = Math.max(1, tour.time - tour.since);
  if (grew === 0) return null;                                  // unchanged: nothing to say
  if (grew < 0) return chip(T('grew.less'), '', T('grew.less_title'));
  return chip(T('grew.more', { size: fmt.size(grew) }), 'accent',
    T('grew.rate', { size: fmt.size(grew), span: fmt.duration(span), rate: fmt.size(Math.round(grew * 3600 / span)) }));
}

function readButton(id) {
  const b = el('button', 'btn small plain', T('read_out'));
  b.type = 'button';
  b.disabled = !sourceById(id);
  if (b.disabled) b.title = T('gone');
  b.onclick = () => openLog(id, null);
  return b;
}

/** A row whose one action is reading its log (the whole row, if the log is on her list) */
function readableRow(id) {
  const ok = !!(id && sourceById(id));
  const r = el('div', 'row nocheck' + (ok ? ' unfolds' : ''));
  if (ok) {
    r.onclick = (e) => {
      if (e.target.closest('button, a, input, [data-own]')) return;
      if (String(window.getSelection && window.getSelection()).length) return;      // selecting text
      openLog(id, null);
    };
  }
  return r;
}

function varlogView() {
  const v = tour.varlog || {};
  const box = el('div', 'lg-view');
  box.appendChild(Office.sectionHead(T('varlog.title'), T('varlog.sub', { total: fmt.size(v.total || 0) })));
  if (v.ok && !v.own) box.appendChild(el('p', 'callout', T('varlog.not_own')));
  const list = el('div', 'box');
  for (const f of v.files || []) {
    const r = readableRow(f.source);
    const main = el('div', 'row-main');
    const name = el('div', 'row-name', f.path);
    if (f.source && sourceById(f.source)) name.title = T('read_out');
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('changed', { when: fmt.relative(f.time) })));
    const g = grewChip(f.grew);
    if (g) meta.appendChild(g);
    main.append(name, meta);
    const size = el('div', 'figures');
    size.append(el('b', '', fmt.size(f.size)), el('span', ''));
    r.append(main, size, f.source ? readButton(f.source) : el('span'));
    list.appendChild(r);
  }
  if (!list.children.length) list.appendChild(el('p', 'empty', T('varlog.empty')));
  box.appendChild(list);
  box.appendChild(el('p', 'role lg-note', T('varlog.files_note', { n: v.count || 0, size: fmt.size(v.sum || 0), when: fmt.date(tour.time) })));
  return box;
}

function dockerView() {
  const d = tour.docker || {};
  const box = el('div', 'lg-view');
  box.appendChild(Office.sectionHead(T('docker.title'), T('docker.sub', { root: d.root || '/var/lib/docker' })));
  const r = d.rotation || {};
  box.appendChild(el('p', 'role lg-note', r.on ? T('docker.rotation_on', { size: r.size || '?', files: r.files || '?' }) : T('docker.rotation_off')));
  if (!d.ok) { box.appendChild(el('p', 'callout', d.enabled === false ? T('docker.disabled') : T('docker.down'))); return box; }
  const list = el('div', 'box');
  for (const c of d.containers || []) {
    const id = 'container:' + c.name;
    const row = readableRow(id);
    const main = el('div', 'row-main');
    const name = el('div', 'row-name text', c.name);
    if (sourceById(id)) name.title = T('read_out');
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(c.running ? T('running') : T('stopped'), c.running ? 'ok' : 'quiet'));
    if (c.max_size) meta.appendChild(chip(T('docker.limit', { size: c.max_size, files: c.max_file || '1' }), '', T('docker.limit_title', { size: c.max_size, files: c.max_file || '1' })));
    else if (c.driver === 'json-file') meta.appendChild(chip(T('docker.no_limit'), 'warn', T('docker.no_limit_title')));
    else if (c.driver === 'local') meta.appendChild(chip(T('docker.driver', { driver: c.driver }), '', T('docker.local_title')));
    else if (c.driver) meta.appendChild(chip(T('docker.driver', { driver: c.driver }), '', T('docker.driver_title', { driver: c.driver })));
    const g = grewChip(c.grew);
    if (g) meta.appendChild(g);
    main.append(name, meta);
    const size = el('div', 'figures');
    size.append(el('b', '', c.size === null || c.size === undefined ? '–' : fmt.size(c.size)), el('span', ''));
    row.append(main, size, readButton(id));
    list.appendChild(row);
  }
  if (!list.children.length) list.appendChild(el('p', 'empty', T('docker.none')));
  box.appendChild(list);
  return box;
}

/** The lines that sounded like an error or a warning: every log (or just one) with its kinds of lines */
function foundView(only) {
  const box = el('div', 'lg-view');
  const all = noisy();
  const list = only ? [only] : all;
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  const label = () => { unfold.textContent = shownKinds.some((x) => !x.open()) ? T('unfold_all') : T('fold_all'); };
  unfold.onclick = () => {
    const open = shownKinds.some((x) => !x.open());
    Office.keepInPlace(unfold, () => shownKinds.forEach((x) => x.set(open)));
    label();
  };
  const sub = tour.first ? T('found.sub_first') : T('found.sub', { when: fmt.date(tour.since) });
  box.appendChild(Office.sectionHead(only ? T('found.title_one', { name: sourceLabel(only) }) : T('found.title'), sub, unfold));
  const kinds = el('div', 'box');
  for (const s of list) {
    const head = el('div', 'lg-subhead');
    head.append(el('span', 'lg-subhead-name', sourceLabel(s)), counts(s.errors, s.warnings));
    if (s.lines) head.appendChild(el('span', 'lg-subhead-meta', T('lines_read', { n: s.lines })));
    if (s.partial) head.appendChild(chip(T('partial'), 'warn', s.kind === 'docker' ? T('partial_docker', { n: s.lines }) : T('partial_file', { size: fmt.size(s.skipped || 0) })));
    if (s.rotated) head.appendChild(chip(T('rotated'), '', T('rotated_title')));
    head.appendChild(readButton(s.id));
    kinds.appendChild(head);
    for (const g of s.groups || []) kinds.appendChild(kindRow(g));
    const more = (s.kinds || 0) - (s.groups || []).length;
    if (more > 0) kinds.appendChild(el('div', 'lg-more', T('more_kinds', { n: more })));
  }
  if (!list.length) kinds.appendChild(el('p', 'empty', T('found.none')));
  box.appendChild(kinds);
  if (!only) {
    const quiet = (tour.sources || []).filter((s) => !s.errors && !s.warnings && !s.unreadable);
    if (quiet.length) {
      const p = el('p', 'role lg-note');
      const names = el('span', 'lg-quiet', T('found.quiet', { n: quiet.length }));
      names.dataset.tip = quiet.map(sourceLabel).join(', ');
      p.appendChild(names);
      box.appendChild(p);
    }
    const unreadable = (tour.sources || []).filter((s) => s.unreadable);
    if (unreadable.length) box.appendChild(el('p', 'role lg-note', T('found.unreadable', { list: unreadable.map(sourceLabel).join(', ') })));
  }
  label();
  unfold.hidden = shownKinds.length < 2;
  return box;
}

/** One kind of line: how often, when, its newest line; a click unfolds the line in full and "Show in the log" */
function kindRow(g) {
  const r = el('div', 'row nocheck unfolds lg-kind');
  const main = el('div', 'row-main');
  const msg = g.sample.slice(g.skip || 0).replace(/^[\s:|\]-]+/, '') || g.sample;        // without its time: that is in the meta line
  const name = el('div', 'row-name lg-kind-line', msg + (g.cut ? ' …' : ''));
  name.title = T('details');
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('kind.' + g.level), g.level === 'error' ? 'danger' : 'warn', T('kind.' + g.level + '_title')));
  if (g.first && g.last) meta.appendChild(el('span', '', g.first === g.last ? fmt.date(g.last) : T('seen', { first: fmt.date(g.first), last: fmt.date(g.last) })));
  main.append(name, meta);
  const n = el('div', 'figures');
  n.append(el('b', '', '× ' + fmt.number(g.count)), el('span', ''));
  r.append(main, n);
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; unfolded.delete(g.key); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail');
      box.appendChild(el('pre', 'code lg-kind-full', g.sample + (g.cut ? ' …' : '')));
      const facts = el('div', 'lg-kind-facts');
      facts.append(g.first ? T('seen_first', { when: fmt.date(g.first) }) + ' · ' + T('seen_last', { when: fmt.date(g.last) }) : T('no_time'),
        ' · ', T('times', { n: g.count }), ' ');
      const show = el('button', 'btn small plain', T('show_in_log'));
      show.type = 'button';
      show.disabled = !sourceById(g.src);
      show.title = show.disabled ? T('gone') : T('show_in_log_title');
      show.onclick = () => openLog(g.src, g);
      facts.appendChild(show);
      box.appendChild(facts);
      r.appendChild(box);
      unfolded.add(g.key);
      r.classList.add('open');
    }
  };
  r.onclick = (e) => {
    if (e.target.closest('button, a, input, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;      // selecting text
    Office.keepInPlace(r, () => set(!box));
  };
  shownKinds.push({ open: () => !!box, set });
  if (unfolded.has(g.key)) set(true);
  return r;
}

/** Read a log below — with a line from the tour: enough lines to reach it, marked and scrolled to */
function openLog(id, kind) {
  if (!sourceById(id)) { Office.toast(T('gone'), true); return; }
  mark = kind ? { sample: kind.sample, cut: !!kind.cut } : null;
  markScroll = !!kind;
  if (kind) {
    const need = (kind.back || 0) + 50;
    const n = LINE_CHOICES.find((c) => c >= need) || LINE_CHOICES[LINE_CHOICES.length - 1];
    if (n > opts.lines) { opts.lines = n; view.lines.value = String(n); }      // for this reading — not remembered
  }
  if (opts.query) { opts.query = ''; view.query.value = ''; }
  view.readSection.scrollIntoView({ block: 'start', behavior: 'smooth' });
  if (id !== opts.source) pick(id, true); else restart(true);
}

const isMark = (line) => !!mark && (line === mark.sample || (mark.cut && line.startsWith(mark.sample)));

/** After reading: the marked line in view — or say that it is no longer among the lines read */
function showMark() {
  markScroll = false;
  const rows = view.out.querySelectorAll('.lg-line.spot');
  const row = rows[rows.length - 1];
  if (!row) { Office.toast(T('spot_missing', { n: opts.lines })); view.out.scrollTop = view.out.scrollHeight; return; }
  view.out.scrollTop = Math.max(0, row.offsetTop - view.out.clientHeight / 3);
}

// ------------------------------------------------------------------ the reader
function pick(id, keepMark) {
  opts.source = id;
  Office.store('logs.source', id);
  fillSources();
  restart(keepMark);
}

/** Star or unstar a source (default: the one being read) */
function toggleFav(id) {
  id = typeof id === 'string' ? id : opts.source;
  if (!id) return;
  favs = favs.includes(id) ? favs.filter((f) => f !== id) : favs.concat(id);
  Office.storeJson('logs.favorites', favs);
  fillSources();
  if (!view.pop.hidden) renderList();
}

/** Back to the favourites out of the box (after asking) */
function resetFavs() {
  Office.dialog({
    title: T('menu.reset_favs'),
    body: el('p', '', T('reset_favs_text')),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('reset_favs_go'), kind: '', act: () => {
      favs = DEFAULT_FAVS.slice();
      Office.store('logs.favorites', null);         // no list of your own: the defaults count again
      if (view) fillSources();
      Office.toast(T('reset_favs_done'));
      return true;
    } }],
  });
}

/** The picker's button, the star and the favourite buttons; a source that is gone means: none chosen */
function fillSources() {
  const all = state?.sources || [];
  if (opts.source && state && !all.some((s) => s.id === opts.source)) opts.source = '';
  const cur = source();
  view.source.textContent = cur ? sourceName(cur) : T('choose_log');
  view.source.classList.toggle('unset', !cur);
  const on = favs.includes(opts.source);
  view.star.textContent = on ? '★' : '☆';
  view.star.title = T(on ? 'fav_remove' : 'fav_add');
  view.star.setAttribute('aria-label', view.star.title);
  view.star.classList.toggle('on', on);
  view.star.disabled = !cur;
  // the favourites as buttons: one click to read them; and getting back the ones out of the box
  const starred = favs.map((id) => all.find((s) => s.id === id)).filter(Boolean);
  view.favs.innerHTML = '';
  view.favs.appendChild(el('span', 'lg-favs-label', starred.length ? T('group.favorites') : T('no_favs')));
  starred.forEach((s) => {
    const b = el('button', 'btn small plain lg-fav' + (s.id === opts.source ? ' active' : ''), T('source.' + s.label, { name: s.param }));
    b.type = 'button';
    b.onclick = () => { if (s.id !== opts.source) pick(s.id); };
    view.favs.appendChild(b);
  });
  const reset = el('button', 'btn small plain lg-reset', T('reset_favs_short'));
  reset.type = 'button';
  reset.title = T('menu.reset_favs');
  reset.onclick = resetFavs;
  view.favs.appendChild(reset);
}

function openPicker() {
  view.pop.hidden = false;
  view.source.setAttribute('aria-expanded', 'true');
  view.find.value = '';
  renderList();
  view.find.focus();
}
function closePicker() {
  view.pop.hidden = true;
  view.source.setAttribute('aria-expanded', 'false');
}

/** The open list: favourites first, then the groups; the search field filters by name */
function renderList() {
  const all = state?.sources || [];
  const q = view.find.value.trim().toLowerCase();
  const match = (s) => !q || sourceName(s).toLowerCase().includes(q) || s.id.toLowerCase().includes(q);
  const list = view.list;
  list.innerHTML = '';
  const group = (title, items) => {
    if (!items.length) return;
    list.appendChild(el('div', 'lg-group', title));
    items.forEach((s) => {
      const row = el('div', 'lg-row' + (s.id === opts.source ? ' active' : ''));
      const item = el('button', 'lg-item', sourceName(s));
      item.type = 'button';
      item.setAttribute('role', 'option');
      item.onclick = () => { closePicker(); if (s.id !== opts.source) pick(s.id); };
      item.onkeydown = (e) => {
        const items = [...list.querySelectorAll('.lg-item')];
        const i = items.indexOf(item);
        if (e.key === 'ArrowDown' && items[i + 1]) { e.preventDefault(); items[i + 1].focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || view.find).focus(); }
        if (e.key === 'Escape') { closePicker(); view.source.focus(); }
      };
      const on = favs.includes(s.id);
      const star = el('button', 'lg-row-star' + (on ? ' on' : ''), on ? '★' : '☆');
      star.type = 'button';
      star.title = T(on ? 'fav_remove' : 'fav_add');
      star.onclick = (e) => { e.stopPropagation(); toggleFav(s.id); };
      row.append(item, star);
      list.appendChild(row);
    });
  };
  group(T('group.favorites'), favs.map((id) => all.find((s) => s.id === id)).filter((s) => s && match(s)));
  for (const g of GROUPS) group(T('group.' + g), all.filter((s) => s.group === g && !favs.includes(s.id) && match(s)));
  if (!list.children.length) list.appendChild(el('p', 'lg-none', T('nothing_found')));
}

function sourceName(s) {
  const name = T('source.' + s.label, { name: s.param });
  return s.size !== null && s.size !== undefined ? `${name} · ${fmt.size(s.size)}` : name;
}

function shown() {
  const q = opts.query.trim().toLowerCase();
  return lines.filter((l) => (!opts.only || level(l)) && (!q || l.toLowerCase().includes(q)));
}

/** Lines into the page — as text nodes, coloured by level, the filter word marked, the tour's line (if any) marked */
function renderLines(toBottom) {
  if (!view) return;
  const out = view.out;
  const list = shown();
  const q = opts.query.trim();
  const frag = document.createDocumentFragment();
  for (const line of list) {
    const row = el('span', 'lg-line ' + level(line) + (isMark(line) ? ' spot' : ''));
    if (q) {
      const lower = line.toLowerCase();
      const ql = q.toLowerCase();
      let i = 0;
      for (let at = lower.indexOf(ql); at !== -1; at = lower.indexOf(ql, i)) {
        row.append(line.slice(i, at), el('mark', '', line.slice(at, at + q.length)));
        i = at + q.length;
      }
      row.append(line.slice(i));
    } else {
      row.textContent = line;
    }
    frag.append(row, '\n');
  }
  out.innerHTML = '';
  if (!list.length) out.appendChild(el('span', 'lg-empty', lines.length ? T('nothing_matches') : T('empty')));
  out.appendChild(frag);
  if (toBottom) out.scrollTop = out.scrollHeight;
  const src = source();
  const m = view.meta || {};
  const parts = [];
  if (src?.path) parts.push(src.path);
  if (m.size !== undefined && m.size !== null) parts.push(fmt.size(m.size));
  if (m.time) parts.push(T('changed', { when: fmt.relative(m.time) }));
  parts.push(list.length === lines.length ? T('count', { n: lines.length }) : T('count_filtered', { n: list.length, total: lines.length }));
  view.status.textContent = parts.join(' · ');
  const problems = lines.filter((l) => level(l) === 'error').length;
  view.hint.textContent = problems ? T('errors_seen', { n: problems }) : '';
}

function download() {
  const text = shown().join('\n') + '\n';
  const a = el('a');
  a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
  a.download = `${CONFIG_HOST()}-${opts.source.replace(/[^A-Za-z0-9._-]+/g, '_')}.log`;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}
const CONFIG_HOST = () => (Office.config && Office.config.host) || 'unraid';

function restart(keepMark) {
  clearTimeout(timer);
  lines = [];
  offset = null;
  view.meta = null;
  if (!keepMark) { mark = null; markScroll = false; }
  if (!opts.source) {                       // nothing chosen yet: say how, read nothing
    view.out.innerHTML = '';
    view.out.appendChild(el('span', 'lg-empty', T('choose_first')));
    view.status.textContent = '';
    view.hint.textContent = '';
    return;
  }
  view.out.textContent = Office.t('common.loading');
  read(true).then(schedule);
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root) {
    view = build(root);
    renderHead();
    await Promise.all([load(false), loadTour()]);
    renderTour();
    restart();
  },
  unmount() { clearTimeout(timer); clearTimeout(tourTimer); view = null; shownKinds = []; },
  async poll() {
    load(false);
    if (touring()) return;
    const before = tour && tour.time;
    await loadTour();
    if (view && (tour && tour.time) !== before) Office.keepInPlace(view.tiles, renderTour);
  },
  agentChanged() { if (view) renderHead(); },
  menu() {
    return [
      { text: T('tour'), act: startTour, disabled: !Office.agent.running || touring() },
      { text: T('menu.reload'), act: () => { load(true); if (view) restart(); } },
      { text: T('menu.reset_favs'), act: resetFavs },
    ];
  },
  async reception() {
    if (!state) await load(false);
    if (!tour) await loadTour();
    const facts = [];
    if (state) facts.push(T('fact.sources', { n: state.sources.length }));
    const v = varlog();
    if (v) facts.push(T('fact.varlog', { pct: v.pct }));
    if (tour && tour.time) facts.push(T('fact.tour', { when: fmt.relative(tour.time), errors: tour.errors || 0, warnings: tour.warnings || 0 }));
    else if (state) facts.push(T('fact.syslog', { errors: state.syslog?.errors || 0, warnings: state.syslog?.warnings || 0 }));
    return { bubble: bubbleText(), facts };
  },
});
})();
