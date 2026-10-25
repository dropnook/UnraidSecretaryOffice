/* Ms. Protocolli — reads every log out loud, understands none of it. Pick a
   source (the office's own, Unraid's, User Scripts, containers), how many
   lines, follow it live like tail -f, filter by text or "errors and warnings
   only", copy or download what is shown. Favourites (remembered in this
   browser) come first in the list and as buttons under it.
   Her tour (in the background on the server) counts and groups: how full
   /var/log is and its biggest files, the containers' logs and Docker's
   rotation, and per log the lines that sound like an error or a warning —
   similar ones as one entry; "Show in the log" opens the reader at that line.
   Unraid's syslog also by her syslog rules (from Enhanced Log, her own first): a
   category per line, her tile «Syslog» with a chip per category (without a fresh
   tour she sorts the syslog's newest lines at once), the reader colours by them.
   Her second page «Set up» (#/logs/setup): her own rules and hide words — the user
   types them (nothing taken over from another plugin's settings).
   Every list has its filter bar (core.js Office.filterBar) with a time filter where
   its rows have a time; what a bar has set can be saved under a name, per list, on
   the server (agent/desks/logs-filters.php) — applied only by a click, never by itself.
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
const SRC_CHIPS = 12;           // a chip per log in «Errors & warnings» at most (the last one then says how many more)
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
let shownKinds = [];            // the unfoldable rows of «Errors & warnings»: {open(), set(open)}
const unfolded = new Set();     // kinds unfolded on this visit
let tourKnown = false;          // her last tour asked for once this visit (until then her tour's sections say «loading»)
let src = '';                   // the chip of «Errors & warnings»: '' every log, 'src:<id>' one, 'more' the rest — a filter, never kept
let cat = '';                   // the category chip of «Syslog» (her syslog rules): '' every one, else its id — a filter, never kept
let sysBar = null;              // the bar over «Syslog» (core.js Office.filterBar, built once): the words, the category chips, the note line
/** Where her syslog rules come from (Enhanced Log's repository) — for the credit under all */
const ORIGIN = ['unraid-enhancedlog', 'https://github.com/dkaser/unraid-enhancedlog'];
let foundBar = null;            // the bar over «Errors & warnings» (core.js Office.filterBar, built once): the words, the chips, the note line
const bars = {};                // every list's bar by list (syslog, found, varlog, docker, read): built once a page, emptied at every visit
let page = 'main';              // her two pages: 'main' (the tiles) or 'setup' (her own rules)
let setupView = null;           // «Set up» while it is shown: {root, ruleForm, hideForm}
/* Her instant sort: «Syslog» without a fresh tour (none, older than FRESH_S or from before her own rules changed) — the
   syslog's newest lines sorted at once by the agent («sort», the tour's shape), for this visit */
const FRESH_S = 3600;
let instant = null;             // its answer: the tour's syslog part + time, since
let sorting = false;
let sortAsked = false;          // asked once this visit (again after her own rules changed)
const opts = {
  source: Office.store('logs.source') || '',          // nothing is read until a log is chosen
  lines: Number(Office.store('logs.lines')) || 500,
  follow: Office.store('logs.follow') !== '0',
  wrap: Office.store('logs.wrap') === '1',
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

/* Unraid's syslog on top: her syslog rules (from Enhanced Log; the agent sends them with her state, logsRules()) —
   the first that fits decides; skip is neither, error makes an error, minor/warning at least a warning
   (logsSyslogLevel(), tests/run.php checks both). The categories: the tour's (cat.<id>). */
const RULE_LEVEL = { error: 'error', warning: 'warn', minor: 'warn' };
const RULE_SOURCES = ['syslog', 'syslog.1'];
// a syslog line's message (rsyslog's msg: after its time, the server and the tag) — her hide words look there (agent: LOGS_SYSLOG_MSG)
const SYSLOG_MSG = /^[A-Z][a-z]{2}\s+\d{1,2}\s+\d\d:\d\d:\d\d\s+\S+\s+[^:]*:(.*)$/s;
// the most important first (her tile «Syslog» names the first ones); then what no rule knows and the noise
const CAT_RANK = ['error', 'warning', 'drive', 'fs', 'network', 'minor', 'array', 'login', 'system', 'limetech', 'emhttp'];
const CAT_ORDER = [...CAT_RANK, 'other', 'skip'];
let rulesFrom = null;           // the state's lists they were built from
let rules = [];                 // [[RegExp, category], …] — her own first, then the shipped ones
let hide = [];                  // her hide words
const judged = new Map();       // line => [level, category] of the syslog's lines (built anew with the rules)
function syslogRules() {
  const list = Array.isArray(state?.rules) ? state.rules : [];
  if (list !== rulesFrom) {
    rulesFrom = list;
    rules = [];
    judged.clear();
    for (const r of list) {
      if (!Array.isArray(r) || typeof r[0] !== 'string' || typeof r[1] !== 'string') continue;
      try { rules.push([new RegExp(r[0], 'i'), r[1]]); } catch (e) { /* a rule the browser can't read: left out */ }
    }
    hide = (Array.isArray(state?.hide) ? state.hide : []).filter((h) => typeof h === 'string' && h !== '');
  }
  return rules;
}
/** A syslog line: [level, category] — a hide word makes it noise, else the first rule that fits; '' no rule */
function syslogSort(line) {
  const all = syslogRules();
  const known = judged.get(line);
  if (known) return known;
  let out;
  const m = hide.length ? SYSLOG_MSG.exec(line) : null;
  if (hide.length && hide.some((h) => (m ? m[1] : line).includes(h))) out = ['', 'skip'];
  else {
    const own = level(line);
    const hit = all.find(([re]) => re.test(line));
    const c = hit ? hit[1] : '';
    const by = RULE_LEVEL[c] || '';
    out = [c === 'skip' ? '' : own === 'error' || !by ? own : by === 'error' ? 'error' : own || 'warn', c];
  }
  if (judged.size > 30000) judged.clear();
  judged.set(line, out);
  return out;
}
const syslogLevel = (line) => syslogSort(line)[0];
/** How the reader judges the lines of a log: her syslog rules for Unraid's syslog, her words for every other one */
const levelOf = (s) => (s && RULE_SOURCES.includes(s.id) ? syslogLevel : level);

// ------------------------------------------------------------------ loading
/** Her state: as kept at once, a new look following on her page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view) { renderHead(); fillSources(); openSaved(); redraw(); fillSaved('read', view.saved); wantSort(); }
  if (setupView) renderSetup();
}

/** Her last tour; while one runs, look again every 1.5 s */
async function loadTour() {
  try {
    const j = await Office.api.get({ a: 'part', desk: ID, part: 'tour' });
    if (j.ok) tour = j.part && typeof j.part === 'object' ? j.part : null;
  } catch (e) { /* keep what we have */ }
  tourKnown = true;
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
    if (view) renderTour();
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
  const got = Array.isArray(j.lines) ? j.lines : [];
  if (fresh || j.reset || j.follow === 'replace' || offset === null) lines = got;
  else lines = lines.concat(got).slice(-opts.lines);
  offset = j.follow === 'append' ? j.offset : null;
  view.meta = j;
  renderLines((fresh || atBottom) && !markScroll);
  if (markScroll) showMark();
}

function schedule() {
  clearTimeout(timer);
  if (!view || !opts.follow) return;
  timer = setTimeout(async () => {
    if (!document.hidden && !Office.dialogOpen() && view && view.readSection.isConnected) await read(false);      // the reader on the page
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
    [T('help.rules'), T('help.rules_text')],
    [T('help.grouped'), T('help.grouped_text')],
    [T('help.since'), T('help.since_text')],
    [T('help.space'), T('help.space_text')],
    [T('flood.title'), T('help.flood_text')],
    [T('help.source'), T('help.source_text')],
    [T('live'), T('help.live')],
    [T('only_problems'), T('help.only')],
    [T('help.time'), T('help.time_text')],
    [T('help.sort'), T('help.sort_text')],
    [T('help.saved'), T('help.saved_text')],
    [T('help.favorites'), T('help.favorites_text')],
    [T('help.wakes'), T('help.wakes_text')],
    [el('span', 'lg-sample error', T('help.red')), T('help.colours')],
    [T('help.safe'), T('help.safe_text')],
  ]));

  // a click outside the open list of logs closes it
  root.addEventListener('click', (e) => { if (v.pop && !v.pop.hidden && !v.picker.contains(e.target)) closePicker(); });
  root.appendChild(sw.el);          // the row of tiles, under it the chosen section — or, nothing chosen, all of them
  root.appendChild(credit());       // under all (no section)
  return v;
}

/** A quiet line under all: her syslog rules are Enhanced Log's, by dlandon and Derek Kaser */
function credit() {
  const box = el('div', 'lg-credit');
  box.append(el('b', '', T('credit_title')), ' ', T('credit'), ' ');
  const a = el('a', '', ORIGIN[0]);
  a.href = ORIGIN[1];
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  box.appendChild(a);
  return box;
}

/**
 * «Read out» — the reader: built once per visit and kept (its lines, the open list, the field being typed in); the
 * switcher puts it back in its place at every drawing, readerKeep() gives it back its scroll position and its focus
 */
function readerSection(v = view) {
  if (v.readSection) return readerKeep(v);
  const s = el('section', 'section lg-reader');
  v.readSection = s;
  v.hint = el('span', 'hint');
  s.appendChild(Office.sectionHead(T('reading'), T('reading_sub'), v.hint, { place: 'reading' }));

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
  v.lines.onchange = () => { opts.lines = Number(v.lines.value); Office.store('logs.lines', String(opts.lines)); restart(); redraw(); };
  const toggle = (key, text, onchange) => {
    const label = el('label', 'switch');
    const input = el('input');
    input.type = 'checkbox';
    input.checked = opts[key];
    input.onchange = () => { opts[key] = input.checked; Office.store('logs.' + key, input.checked ? '1' : '0'); onchange(); };
    label.append(input, el('span', '', text));
    return label;
  };
  v.followSw = toggle('follow', T('live'), () => { if (opts.follow) read(false).then(schedule); else clearTimeout(timer); redraw(); });
  const wrapSw = toggle('wrap', T('wrap'), () => view.out.classList.toggle('wrap', opts.wrap));
  const copy = el('button', 'btn small plain', Office.t('common.copy'));
  copy.type = 'button';
  copy.onclick = () => Office.copy(shown().join('\n'));
  const save = el('button', 'btn small plain', T('download'));
  save.type = 'button';
  save.onclick = download;
  bar.append(v.picker, v.star, v.lines, v.followSw, wrapSw, copy, save);
  s.appendChild(bar);
  v.favs = el('div', 'lg-favs');
  s.appendChild(v.favs);
  // its filter bar (the words, «only errors & warnings», the time by the lines' own) and her saved filters for it
  s.appendChild(readBar().el);
  v.saved = el('div', 'lg-saved');
  s.appendChild(v.saved);

  v.out = el('pre', 'code lg-out' + (opts.wrap ? ' wrap' : ''));
  v.out.setAttribute('tabindex', '0');
  v.out.addEventListener('click', (e) => {
    const sv = e.target && e.target.closest ? e.target.closest('.lg-svc-in') : null;
    if (!sv || String(window.getSelection && window.getSelection()).length) return;      // selecting text
    pickService('read', sv.dataset.svc, v.out);
  });
  v.status = el('p', 'role lg-status');
  s.append(v.out, v.status);
  readerWatch(v);
  return s;
}

/** What the reader had when a drawing took it out: where its lines stood (at the bottom: following), the field in use */
function readerWatch(v) {
  const k = { top: 0, bottom: true, focus: null, range: null };
  v.keep = k;
  v.out.addEventListener('scroll', () => {
    if (!v.out.isConnected || !v.out.clientHeight) return;
    k.top = v.out.scrollTop;
    k.bottom = v.out.scrollTop + v.out.clientHeight >= v.out.scrollHeight - 40;
  }, { passive: true });
  const range = (t) => { try { k.range = t && typeof t.selectionStart === 'number' ? [t.selectionStart, t.selectionEnd] : null; } catch (e) { k.range = null; } };
  const box = v.readSection;
  box.addEventListener('focusin', (e) => { k.focus = e.target; range(e.target); });
  ['input', 'keyup', 'mouseup', 'select'].forEach((ev) => box.addEventListener(ev, (e) => { if (e.target === k.focus) range(e.target); }));
  box.addEventListener('focusout', () => setTimeout(() => {
    const a = document.activeElement;
    if (box.isConnected && !(a && box.contains(a))) k.focus = null;
  }, 0));
}

/** The reader back in its place: once the drawing is through, its lines where they stood and the focus where it was */
function readerKeep(v) {
  const k = v.keep;
  Promise.resolve().then(() => {
    if (!v.readSection.isConnected) return;
    v.out.scrollTop = k.bottom ? v.out.scrollHeight : k.top;
    const f = k.focus;
    const a = document.activeElement;
    if (f && f.isConnected && v.readSection.contains(f) && a !== f && (!a || a === document.body)) {
      f.focus({ preventScroll: true });
      if (k.range) { try { f.setSelectionRange(k.range[0], k.range[1]); } catch (e) { /* not a text field */ } }
    }
  });
  return v.readSection;
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

// greet.4 «It says "error" a lot» only when the count her bubble says found something like «error» (her tour's, else the
// syslog's — 2026-10-18: a bubble is true right now)
const errorsCounted = () => (tour && tour.time ? tour.errors || 0 : state?.syslog?.errors || 0);
const greetOk = (key) => key !== 'greet.4' || errorsCounted() > 0;

function bubbleText() {
  if (!state && !tour) return T('bubble.loading');
  const parts = [];
  const v = varlog();
  const fc = floodCase();
  if (v && v.own && v.pct >= v.full) parts.push(fc ? T('bubble.flood', { pct: v.pct, file: fc.path }) : T('bubble.varlog_full', { pct: v.pct }));
  else if (v && v.own && v.pct >= v.warn) parts.push(T('bubble.varlog_much', { pct: v.pct }));
  if (touring()) parts.push(T('bubble.touring'));
  else if (tour && tour.time) {
    parts.push(tour.errors || tour.warnings
      ? T('bubble.tour', { when: fmt.relative(tour.time), errors: tour.errors || 0, warnings: tour.warnings || 0, logs: T('count.in_logs', { n: noisy().length }) })
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

// ------------------------------------------------------------------ the row: her tour's three tiles and «Read out»
/**
 * Her page on core's switcher (CLAUDE.md «Tiles», Office.sections): /var/log, the containers' logs, errors & warnings,
 * the syslog by her rules — what her tour counted —, who woke the disks (only with a spinning disk: wakesWanted()) and
 * «Read out», the reader. No start tile: nothing chosen shows them all in the row's order;
 * «Read out» chosen gives the reader the whole page. Kept as logs.tile; #/logs/<tile> picks one like a click.
 */
const sw = Office.sections({
  id: ID,
  key: 'logs.tile',
  grid: 'cards lg-tiles',
  default: '',
  tiles: () => [
    { id: 'varlog', key: 'varlog.title', place: 'tile.varlog', tile: (a) => varlogTile(a), section: () => varlogView() },
    { id: 'docker', key: 'docker.title', place: 'tile.docker', tile: (a) => dockerTile(a), section: () => dockerView() },
    { id: 'found', key: 'found.title', place: 'tile.found', tile: (a) => foundTile(a), section: () => foundView() },
    { id: 'syslog', key: 'syslog.title', place: 'tile.syslog', tile: (a) => syslogTile(a), section: () => syslogView() },
    wakesWanted() ? { id: 'wakes', key: 'wakes.title', place: 'tile.wakes', tile: (a) => wakesTile(a), section: () => wakesView() } : null,
    { id: 'read', key: 'tile.read', place: 'tile.read', tile: (a) => readTile(a), section: () => readerSection() },
  ],
});

/** Row and sections anew (a new state, a new tour, another log chosen) — the choice stays, so does the reader */
function redraw() {
  if (view) sw.render();
}

function renderTour() {
  if (!view) return;
  renderHead();
  redraw();
}

/** When her tour was — in the head of each of its sections */
function tourHint() {
  const h = el('span', 'hint', touring() ? T('touring') : tour && tour.time ? T('tour_at', { when: fmt.relative(tour.time) }) : '');
  if (tour && tour.time) h.title = fmt.date(tour.time);
  return h;
}

/** /var/log — how full, right now */
function varlogTile(a) {
  const v = varlog();
  if (!v) return Office.tile({ name: '/var/log', cls: 'empty-card', lines: [!tour || !tour.time ? T('tile.no_tour') : '–'], ...a });
  const bar = el('div', 'bar');
  const used = el('i', 'lg-used ' + fillLevel(v));
  used.style.width = Math.min(100, v.pct) + '%';
  bar.appendChild(used);
  const files = tour?.varlog?.count;
  return Office.tile({ name: '/var/log', chip: el('span', 'lg-pct ' + fillLevel(v), v.pct + ' %'), lines: [bar,
    [T('tile.varlog_of', { used: fmt.size(v.used), total: fmt.size(v.total) }), files !== undefined ? T('tile.varlog_files', { n: files }) : ''],
    floodCase() ? T('flood.tile', { file: floodCase().path.replace(/^\/var\/log\//, '') }) : ''], ...a });
}

/** The containers' logs: how big, how many, Docker's rotation */
function dockerTile(a) {
  const d = tour?.docker;
  if (d && d.ok) {
    const r = d.rotation || {};
    return Office.tile({ name: T('tile.docker'), lines: [T('tile.docker_size', { size: fmt.size(d.total || 0) }),
      [T('tile.docker_count', { n: (d.containers || []).length }), r.on ? T('tile.rotation_on', { size: r.size || '?', files: r.files || '?' }) : T('tile.rotation_off')]], ...a });
  }
  return Office.tile({ name: T('tile.docker'), cls: 'empty-card',
    lines: [!tour || !tour.time ? T('tile.no_tour') : d && d.enabled === false ? T('docker.disabled') : T('tile.docker_off')], ...a });
}

/** What sounded like an error or a warning, in how many logs, since when */
function foundTile(a) {
  if (!tour || !tour.time) return Office.tile({ name: T('tile.found'), cls: 'empty-card', lines: [T('tile.no_tour')], ...a });
  const line = el('div', 'card-line');
  line.appendChild(counts(tour.errors || 0, tour.warnings || 0));
  return Office.tile({ name: T('tile.found'), lines: [line,
    [T('tile.found_logs', { n: noisy().length }), tour.first ? T('tile.found_first') : T('tile.found_since', { when: fmt.date(tour.since) })]], ...a });
}

/** Unraid's syslog as her last tour sorted it by her syslog rules — null before (or a tour from before the rules) */
const syslogEntry = () => (tour?.sources || []).find((s) => s && s.id === 'syslog' && s.cats && typeof s.cats === 'object') || null;
/** Her last tour's sort is fresh enough: an hour at most, and none of her own rules changed since */
const tourFresh = () => !!(syslogEntry() && tour.time >= Date.now() / 1000 - FRESH_S && tour.time >= (Number(state?.mine?.time) || 0));
/**
 * What «Syslog» shows: her fresh tour's sort, else her instant sort (the newest lines, sorted just now), else an older
 * tour's — {s, from: 'tour'|'now'}, or null (nothing yet: she is sorting, or the messenger is away and there was no tour)
 */
function syslogNow() {
  if (tourFresh()) return { s: syslogEntry(), from: 'tour' };
  if (instant) return { s: instant, from: 'now' };
  return syslogEntry() ? { s: syslogEntry(), from: 'tour' } : null;
}
/** Her instant sort, once a visit when her tour isn't fresh enough (the wave on the tile and in the section meanwhile) */
function wantSort() {
  if (sortAsked || sorting || !tourKnown || !state || touring() || tourFresh() || !Office.agent.running) return;
  sortAsked = true;
  sortNow();
}
async function sortNow() {
  sorting = true;
  redraw();
  let j = null;
  try { j = await Office.api.post(`${ID}.sort`, {}); } catch (e) { j = null; }
  sorting = false;
  const got = j && j.ok && j.sorted && typeof j.sorted === 'object' && j.sorted.cats && typeof j.sorted.cats === 'object' ? j.sorted : null;
  if (got) instant = got;
  if (view) redraw();
}
/** A line with the wave: something runs */
const busyLine = (text) => { const d = el('div', 'card-line running lg-busy'); d.append(el('span', 'spin'), ' ', text); return d; };
/** «512 × Disks» with its colour */
function catCount(c, n) {
  const span = el('span', 'lg-cat-n lg-cat-' + c);
  span.append(el('span', 'lg-cat-dot'), el('b', '', fmt.number(n)), ' × ' + T('cat.' + c));
  return span;
}

/** «Syslog»: its lines by her syslog rules — the most important categories first (never the noise), how many lines, since when */
function syslogTile(a) {
  const d = syslogNow();
  if (!d) {
    return Office.tile({ name: T('syslog.title'), cls: 'empty-card',
      lines: [sorting || (!tourKnown && Office.agent.running) ? busyLine(T('tile.syslog_sorting')) : !tour || !tour.time ? T('tile.no_tour') : T('tile.syslog_none')], ...a });
  }
  const s = d.s;
  const line = el('div', 'card-line lg-cat-line');
  const top = CAT_RANK.filter((c) => (s.cats[c] || 0) > 0).slice(0, 3);
  top.forEach((c, i) => { if (i) line.append(' · '); line.appendChild(catCount(c, s.cats[c])); });
  if (!top.length) line.append(T('tile.syslog_quiet'));
  const read = Object.values(s.cats).reduce((x, n) => x + (Number(n) || 0), 0);
  const when = d.from === 'now' ? T('tile.syslog_now') : tour.first ? T('tile.found_first') : T('tile.found_since', { when: fmt.date(tour.since) });
  return Office.tile({ name: T('syslog.title'), lines: [line, [T('tile.syslog_lines', { n: read }), when]], ...a });
}

/** «Read out»: the log being read, live or not, how many lines — or that none is chosen */
function readTile(a) {
  const cur = source();
  if (!cur) return Office.tile({ name: T('tile.read'), cls: 'empty-card', lines: [T('tile.read_pick')], ...a });     // what it is and what to do
  return Office.tile({ name: T('tile.read'), lines: [T('source.' + cur.label, { name: cur.param }),
    [opts.follow ? T('live') : '', T('lines', { n: opts.lines })]], ...a });
}

const sourceLabel = (s) => T('source.' + s.label, { name: s.param });

// ------------------------------------------------------------------ the tour's sections
/** A section of her tour: its head, then (no tour yet) how to start one — null when there is a tour */
function tourSection(title, sub, place, ...extra) {
  const box = el('section', 'section lg-view');
  box.appendChild(Office.sectionHead(title, sub, ...extra, tourHint(), { place }));
  if (tour && tour.time) return [box, false];
  const inner = el('div', 'box');
  const e = el('div', 'empty');
  if (!tourKnown) e.append(Office.t('common.loading'));
  else {
    e.append(el('strong', '', T('no_tour_title')), touring() ? T('bubble.touring') : T('no_tour'));
    if (!touring()) {
      const b = el('button', 'btn', T('tour'));
      b.type = 'button';
      b.disabled = !Office.agent.running;
      b.onclick = startTour;
      e.append(el('br'), b);
    }
  }
  inner.appendChild(e);
  box.appendChild(inner);
  return [box, true];
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

/** A row whose one action is reading its log (the whole row, if the log is on her list — it opens the reader, it unfolds nothing) */
function readableRow(id) {
  const ok = !!(id && sourceById(id));
  const r = el('div', 'row nocheck' + (ok ? ' lg-readable' : ''));
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
  const v = (tour && tour.varlog) || varlog() || {};
  const [box, none] = tourSection(T('varlog.title'), T('varlog.sub', { total: fmt.size(v.total || 0) }), 'varlog.title');
  const flood = floodView();
  if (flood) box.insertBefore(flood, box.children[1] || null);
  if (none) return box;
  if (v.ok && !v.own) box.appendChild(el('p', 'callout', T('varlog.not_own')));
  const files = v.files || [];
  const bar = varlogBar();
  box.append(bar.el, savedRow('varlog'));
  const fb = bar.read();
  const span = timeSpan(bar.time.read());
  const fits = files.filter((f) => Office.fits(fb.words, [f.path]) && inSpan(span, f.time));
  bar.time.avail(files.some((f) => Number(f.time) > 0));
  const list = el('div', 'box');
  for (const f of fits) {
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
  if (!list.children.length) list.appendChild(el('p', 'empty', files.length ? T('found.none_fit') : T('varlog.empty')));
  box.appendChild(list);
  barDone('varlog', fits.length, files.length);
  box.appendChild(el('p', 'role lg-note', T('varlog.files_note', { n: v.count || 0, size: fmt.size(v.sum || 0), when: fmt.date(tour.time) })));
  return box;
}

// ------------------------------------------------------------------ «Who fills /var/log?» (agent/desks/logs-flood.php)
/* Her look every minute (state part «flood»): the files growing fastest; from 80 % the case — the file that fills it,
   what it writes most, nginx's lines of this server itself and who is behind them (caught or plainly not), a known
   poller by its addresses and, only where it is known for sure, what helps; at 90 % one notification. */
const floodOf = () => (state && state.flood && typeof state.flood === 'object' ? state.flood : null);
const floodCase = () => { const c = floodOf()?.case; return c && typeof c.path === 'string' ? c : null; };

function floodView() {
  const f = floodOf();
  const look = f && f.look;
  if (!look) return null;
  const box = el('div', 'lg-flood');
  const c = floodCase();
  const span = fmt.duration(look.span || 0);
  if (!c) {
    const rows = (look.rows || []).filter((r) => r.grew > 0);
    box.appendChild(el('p', 'role lg-note', T('flood.calm', { full: f.full })));
    if (look.span > 0) {
      box.appendChild(el('p', 'role lg-note', rows.length
        ? T('flood.growing', { span }) + ' ' + rows.map((r) => `${r.path.replace(/^\/var\/log\//, '')} +${fmt.size(r.grew)}`).join(' · ')
        : T('flood.none_growing', { span })));
    }
    return box;
  }
  box.appendChild(el('p', 'callout warn', c.grew > 0
    ? T('flood.head', { pct: look.pct, file: c.path, grew: fmt.size(c.grew), span, rate: fmt.size(c.rate || 0) })
    : T('flood.head_big', { pct: look.pct, file: c.path, size: fmt.size(c.size || 0) })));
  if (c.binary) box.appendChild(el('p', 'role', T('flood.binary')));
  else if ((c.kinds || []).length) {
    box.appendChild(el('p', 'role', T('flood.kinds', { n: c.lines || 0 })));
    const list = el('div', 'box');
    for (const k of c.kinds) {
      const r = el('div', 'lg-flood-kind');          // not a .row: the files' list below is the section's list (its bar counts those)
      const main = el('div', 'row-main');
      main.appendChild(el('div', 'row-name lg-flood-line', k.line));
      // its share under it (a phone keeps it in view), «×n» as her kinds say it
      const meta = el('div', 'row-meta');
      meta.append(el('b', '', k.share + ' %'), ' · ' + fmt.number(k.n) + '×');
      main.appendChild(meta);
      r.appendChild(main);
      list.appendChild(r);
    }
    box.appendChild(list);
  }
  const local = c.local;
  if (local) {
    box.appendChild(el('p', 'role', T('flood.local', { share: local.share, client: local.client })));
    if ((local.paths || []).length) box.appendChild(el('p', 'role lg-flood-paths', T('flood.paths', { paths: local.paths.join(', ') })));
    const who = f.catch && Array.isArray(f.catch.who) ? f.catch.who[0] : null;
    if (f.catching) {
      const p = el('p', 'role');
      p.append(el('span', 'spin'), ' ', T('flood.catching'));
      box.appendChild(p);
    } else if (who) {
      const p = el('p', 'role', who.kind === 'container'
        ? T('flood.who_container', { name: who.name || String(who.id || '').slice(0, 12), prog: who.prog, n: who.n })
        : T('flood.who_program', { prog: who.prog, n: who.n }));
      if (who.cmd) p.title = who.cmd;
      box.appendChild(p);
    } else if (f.catch) box.appendChild(el('p', 'role', T('flood.who_none')));
    const poller = (who && who.poller) || local.poller;
    const tool = poller && f.pollers && f.pollers[poller] ? f.pollers[poller].tool : '';
    const hint = poller === 'netdata' ? T('flood.poller.netdata') : poller === 'status' ? T('flood.poller.status') : tool ? T('flood.poller.tool', { tool }) : '';
    if (hint) box.appendChild(el('p', 'role', hint));
    if (poller === 'netdata') box.appendChild(el('p', 'callout', T('flood.todo.netdata')));
  }
  box.appendChild(el('p', 'role lg-note', f.told
    ? T('flood.told', { notify: f.notify, full: f.full, when: fmt.relative(f.told) })
    : T('flood.notify_note', { notify: f.notify, full: f.full })));
  return box;
}

function dockerView() {
  const d = (tour && tour.docker) || {};
  const [box, none] = tourSection(T('docker.title'), T('docker.sub', { root: d.root || '/var/lib/docker' }), 'docker.title');
  if (none) return box;
  const r = d.rotation || {};
  box.appendChild(el('p', 'role lg-note', r.on ? T('docker.rotation_on', { size: r.size || '?', files: r.files || '?', n: Number(r.files) }) : T('docker.rotation_off')));
  if (!d.ok) { box.appendChild(el('p', 'callout', d.enabled === false ? T('docker.disabled') : T('docker.down'))); return box; }
  const all = d.containers || [];
  const bar = dockerBar();
  box.append(bar.el, savedRow('docker'));
  const fb = bar.read();
  const span = timeSpan(bar.time.read());
  const fits = all.filter((c) => Office.fits(fb.words, [c.name, c.driver]) && inSpan(span, c.time));
  bar.time.avail(all.some((c) => Number(c.time) > 0));          // a tour from before 1.67 has no time per container
  const list = el('div', 'box');
  for (const c of fits) {
    const id = 'container:' + c.name;
    const row = readableRow(id);
    const main = el('div', 'row-main');
    const name = el('div', 'row-name text', c.name);
    if (sourceById(id)) name.title = T('read_out');
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(c.running ? T('running') : T('stopped'), c.running ? 'ok' : 'quiet'));
    if (c.max_size) meta.appendChild(chip(T('docker.limit', { size: c.max_size, files: c.max_file || '1' }), '', T('docker.limit_title', { size: c.max_size, files: c.max_file || '1', n: Number(c.max_file || 1) })));
    else if (c.driver === 'json-file') meta.appendChild(chip(T('docker.no_limit'), 'warn', T('docker.no_limit_title')));
    else if (c.driver === 'local') meta.appendChild(chip(T('docker.driver', { driver: c.driver }), '', T('docker.local_title')));
    else if (c.driver) meta.appendChild(chip(T('docker.driver', { driver: c.driver }), '', T('docker.driver_title', { driver: c.driver })));
    const g = grewChip(c.grew);
    if (g) meta.appendChild(g);
    if (Number(c.time) > 0) meta.appendChild(el('span', '', T('changed', { when: fmt.relative(c.time) })));
    main.append(name, meta);
    const size = el('div', 'figures');
    size.append(el('b', '', c.size === null || c.size === undefined ? '–' : fmt.size(c.size)), el('span', ''));
    row.append(main, size, readButton(id));
    list.appendChild(row);
  }
  if (!list.children.length) list.appendChild(el('p', 'empty', all.length ? T('found.none_fit') : T('docker.none')));
  box.appendChild(list);
  barDone('docker', fits.length, all.length);
  return box;
}

/**
 * «Errors & warnings»: every log with its kinds of lines — a chip per log under the head (the loudest; «n more» the rest)
 * shows only that one, the bar's note line says so («The list shows only: [Syslog ×]»); the bar's words go over the
 * lines and the logs' names
 */
function foundView() {
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  const label = () => { unfold.textContent = shownKinds.some((x) => !x.open()) ? T('unfold_all') : T('fold_all'); };
  unfold.onclick = () => {
    const open = shownKinds.some((x) => !x.open());
    Office.keepInPlace(unfold, () => shownKinds.forEach((x) => x.set(open)));
    label();
  };
  shownKinds = [];
  const sub = !tour || !tour.time ? T('found.sub_first') : tour.first ? T('found.sub_first') : T('found.sub', { when: fmt.date(tour.since) });
  const [box, none] = tourSection(T('found.title'), sub, 'found.title', unfold);
  unfold.hidden = true;
  if (none) return box;
  const all = noisy();
  const { loud, rest } = chipSources(all);
  if (src && !(src === 'more' ? rest.length : loud.some((s) => 'src:' + s.id === src))) src = '';      // its log went quiet
  const list = src === 'more' ? rest : src ? loud.filter((s) => 'src:' + s.id === src) : all;
  const bar = foundBarGet();
  fillChips(loud, rest);
  box.append(bar.el, savedRow('found'));
  const f = bar.read();
  const span = timeSpan(bar.time.read());
  const narrowed = f.words.length > 0 || !!span || !!bar.svc;
  const kinds = el('div', 'box');
  let n = 0;
  let total = 0;
  all.forEach((s) => { total += (s.groups || []).length; });       // «n of m fit»: of every log's kinds
  bar.time.avail(all.some((s) => (s.groups || []).some((g) => Number(g.last) > 0)));
  for (const s of list) {
    // a kind by its newest line (a «×n» in the time filter when its last one is)
    const groups = sortKinds((s.groups || []).filter((g) => Office.fits(f.words, [g.sample, sourceLabel(s), catName(g)]) && inSpan(span, g.last)
      && (!bar.svc || serviceOf(g.sample) === bar.svc)), bar.sort.value());
    n += groups.length;
    if (narrowed && !groups.length) continue;
    const head = el('div', 'lg-subhead');
    head.append(el('span', 'lg-subhead-name', sourceLabel(s)), counts(s.errors, s.warnings));
    if (s.lines) head.appendChild(el('span', 'lg-subhead-meta', T('lines_read', { n: s.lines })));
    if (s.stopped) head.appendChild(chip(T('stopped'), 'quiet', T('stopped_title')));
    if (s.partial) head.appendChild(chip(T('partial'), 'warn', s.kind === 'docker' ? T('partial_docker', { n: s.lines })
      : s.span_cut ? T('partial_span') : T('partial_file', { size: fmt.size(s.skipped || 0) })));
    if (s.rotated) head.appendChild(chip(T('rotated'), '', T('rotated_title')));
    const grew = grewChip(s.grew);              // how much the log grew since the last tour (files; a router's too)
    if (grew) head.appendChild(grew);
    head.appendChild(readButton(s.id));
    kinds.appendChild(head);
    for (const g of groups) kinds.appendChild(kindRow(g, { of: 'found' }));
    const more = (s.kinds || 0) - (s.groups || []).length;
    if (more > 0 && !narrowed) kinds.appendChild(el('div', 'lg-more', T('more_kinds', { n: more })));
  }
  if (!all.length) kinds.appendChild(el('p', 'empty', T('found.none')));
  else if (!kinds.children.length) kinds.appendChild(el('p', 'empty', T('found.none_fit')));
  box.appendChild(kinds);
  barDone('found', n, total, { tile: src === 'more' ? T('tile.more_logs', { n: rest.length }) : src ? sourceLabel(list[0]) : '', clear: () => { src = ''; } });
  if (!src) {
    const quiet = (tour.sources || []).filter((s) => !s.errors && !s.warnings && !s.unreadable);
    if (quiet.length) {
      const p = el('p', 'role lg-note');
      const names = el('span', 'lg-quiet', T('found.quiet', { n: quiet.length }));
      names.dataset.tip = quiet.map((s) => (s.grew > 0 && !tour.first ? `${sourceLabel(s)} (${T('grew.more', { size: fmt.size(s.grew) })})`
        : s.stopped ? `${sourceLabel(s)} (${T('stopped')})` : sourceLabel(s))).join(', ');
      p.appendChild(names);
      box.appendChild(p);
    }
    const unreadable = (tour.sources || []).filter((s) => s.unreadable);
    if (unreadable.length) box.appendChild(el('p', 'role lg-note', T('found.unreadable', { list: unreadable.map(sourceLabel).join(', ') })));
    // Docker didn't answer: no container's log was read — said, never counted as «nothing like that» (T28)
    if (tour.containers_read === false) box.appendChild(el('p', 'role lg-note', T('found.no_containers')));
  }
  label();
  unfold.hidden = shownKinds.length < 2;
  return box;
}

// ------------------------------------------------------------------ who woke my disks?
/*
 * «Who woke my disks?» (agent/desks/logs-diskwakes.php): every wake of a spinning disk she noticed in the last 7 days — per
 * disk how often (a click shows only its wakes: a filter, never kept, said in the note line) and per wake who she caught
 * waiting for it while it spun up, the surest first; «Not found out» when nobody was. Only with a rotating disk.
 */
let wakeDisk = '';              // the disk chosen in «By disk»: a filter, never kept
let shownWakes = [];            // the unfoldable rows of the wakes: {open(), set(open)}
const unfoldedWakes = new Set(); // wakes unfolded on this visit
const wakesOf = () => (state && state.wakes && Array.isArray(state.wakes.wakes) ? state.wakes.wakes : []);
/** A rotating disk there (the state says so): only then her tile — an older messenger's state says nothing, the tile stays */
const wakesWanted = () => !state || !state.wakes || (Array.isArray(state.wakes.hdds) && state.wakes.hdds.length > 0);
const wakesBar = () => listBar('wakes', { label: T('wakes.filter_label') });
/** Midnight of the state's day (the server looked just now; a kept state counts by its own day) */
const startOfToday = () => { const d = new Date(((state && state.time) || Date.now() / 1000) * 1000); d.setHours(0, 0, 0, 0); return d.getTime() / 1000; };
const wakeHead = (text) => { const h = el('div', 'lg-subhead'); h.appendChild(el('span', 'lg-subhead-name', text)); return h; };

/** What woke it, in words: «Plex (container)», «SMB: laptop», «Scheduled task: …», «Mr. Backupsy (the office)» */
function causeName(c) {
  if (!c) return T('wakes.cause.none');
  const name = c.name || '';
  switch (c.kind) {
    case 'container': return T('wakes.cause.container', { name: name || c.prog });
    case 'vm': return T('wakes.cause.vm', { name: name || c.prog });
    case 'smb': return name ? T('wakes.cause.smb', { name }) : T('wakes.cause.smb_anon');
    case 'nfs': return T('wakes.cause.nfs');
    case 'office': return c.desk && Office.desks.has(c.desk) ? T('wakes.cause.office', { name: Office.t(c.desk + '.name') }) : T('wakes.cause.office_anon');
    case 'mover': return T('wakes.cause.mover');
    case 'cron': return T('wakes.cause.cron', { name: name || c.prog });
    default:
      if (c.prog === 'shfs') return T('wakes.cause.shfs');
      return c.by ? T('wakes.cause.program_by', { name: c.prog, by: starterName(c) }) : T('wakes.cause.program', { name: c.prog });
  }
}

/** Who started a plain program: Unraid, its web page, a plugin, a user script, a terminal (logsWakeStarter()) */
function starterName(c) {
  switch (c.by) {
    case 'plugin': return T('wakes.by.plugin', { name: c.byname || '?' });
    case 'user_script': return T('wakes.by.user_script', { name: c.byname || '?' });
    default: return T('wakes.by.' + c.by);
  }
}

/** The tile: how many wakes today, in 7 days — or that none came */
function wakesTile(a) {
  const all = wakesOf();
  const lines = [];
  if (!state || !state.wakes) lines.push(state ? T('wakes.not_yet') : Office.t('common.loading'));
  else if (state.wakes.on === false) lines.push(T('wakes.tile_off'), all.length ? T('wakes.tile_week', { n: all.length }) : '');
  else if (!all.length) lines.push(T('wakes.tile_none'), T('wakes.tile_since', { when: fmt.date(state.wakes.since) }));
  else {
    const today = all.filter((w) => w.time >= startOfToday()).length;
    lines.push(T('wakes.tile_today', { n: today }), T('wakes.tile_week', { n: all.length }));
  }
  return Office.tile({ name: T('wakes.title'), cls: all.length && state.wakes.on !== false ? '' : 'empty-card', lines, ...a });
}

/** Her switch in the section's head: on unless switched off, kept on the server (never in the browser) */
function wakesSwitch() {
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = state.wakes.on !== false;
  cb.disabled = !Office.agent.running;
  label.append(cb, el('span', '', T('wakes.switch')));
  label.title = T('wakes.switch_title');
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.wakes_watch`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    Office.keepInPlace(label, () => redraw());
    Office.toast(T(on ? 'wakes.switched_on' : 'wakes.switched_off'));
  };
  return label;
}

/** Per disk: how often today and in 7 days, and what woke it most often */
function wakesByDisk(all) {
  const by = new Map();
  for (const w of all) {
    for (const d of w.disks) {
      if (!by.has(d)) by.set(d, { today: 0, week: 0, causes: new Map() });
      const x = by.get(d);
      x.week += 1;
      if (w.time >= startOfToday()) x.today += 1;
      const who = causeName(w.who[0]);
      x.causes.set(who, (x.causes.get(who) || 0) + 1);
    }
  }
  return [...by.entries()].sort((p, q) => q[1].week - p[1].week || p[0].localeCompare(q[0]));
}

function wakesView() {
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  const label = () => { unfold.textContent = shownWakes.some((x) => !x.open()) ? T('unfold_all') : T('fold_all'); };
  unfold.onclick = () => {
    const open = shownWakes.some((x) => !x.open());
    Office.keepInPlace(unfold, () => shownWakes.forEach((x) => x.set(open)));
    label();
  };
  shownWakes = [];
  const look = (state && state.wakes && state.wakes.look) || 4;
  const box = el('section', 'section lg-view');
  const known = !!(state && state.wakes);
  box.appendChild(Office.sectionHead(T('wakes.title'), T('wakes.sub', { s: look }), ...(known ? [wakesSwitch()] : []), unfold, { place: 'wakes.title' }));
  unfold.hidden = true;
  if (!known) {
    box.appendChild(el('p', 'empty', state ? T('wakes.not_yet') : Office.t('common.loading')));
    return box;
  }
  if (state.wakes.on === false) box.appendChild(el('p', 'callout', T('wakes.off_note')));
  const all = wakesOf().slice().sort((p, q) => q.time - p.time);
  if (wakeDisk && !all.some((w) => w.disks.includes(wakeDisk))) wakeDisk = '';
  // by disk: a row each, a click shows only its wakes
  const disks = el('div', 'box lg-wake-disks');
  disks.appendChild(wakeHead(T('wakes.by_disk')));
  for (const [disk, x] of wakesByDisk(all)) {
    const on = wakeDisk === disk;
    const r = el('div', 'row nocheck lg-readable lg-wake-disk' + (on ? ' lg-wake-on' : ''));
    const main = el('div', 'row-main');
    const name = el('div', 'row-name', disk);
    name.title = on ? T('wakes.disk_all_title') : T('wakes.disk_only_title', { disk });
    const meta = el('div', 'row-meta');
    if (x.today) meta.appendChild(el('span', '', T('wakes.n_today', { n: x.today })));
    meta.appendChild(el('span', '', T('wakes.n_week', { n: x.week })));
    const [most, n] = [...x.causes.entries()].sort((p, q) => q[1] - p[1])[0];
    meta.appendChild(el('span', 'lg-wake-most', T('wakes.most', { who: most, n })));
    main.append(name, meta);
    r.appendChild(main);
    r.onclick = () => Office.keepInPlace(box, () => { wakeDisk = on ? '' : disk; redraw(); });
    disks.appendChild(r);
  }
  if (all.length) box.appendChild(disks);
  // every wake, newest first: the bar's words over disks, causes, files, commands; the time by the wake's
  const bar = wakesBar();
  box.append(bar.el, savedRow('wakes'));
  const f = bar.read();
  const span = timeSpan(bar.time.read());
  bar.time.avail(all.length > 0);
  const fits = all.filter((w) => (!wakeDisk || w.disks.includes(wakeDisk)) && inSpan(span, w.time)
    && Office.fits(f.words, [w.disks.join(' '), ...w.who.map((c) => [causeName(c), T('wakes.sure.' + c.sure), c.prog, c.cmd, ...(c.files || [])].join(' ')), ...(w.cron || []), ...(w.started || []),
      w.who.length ? '' : T('wakes.cause.none')]));
  const list = el('div', 'box');
  list.appendChild(wakeHead(T('wakes.every')));
  for (const w of fits) list.appendChild(wakeRow(w, look));
  if (!fits.length) list.appendChild(el('p', 'empty', all.length ? T('wakes.none_fit') : T('wakes.none')));
  box.appendChild(list);
  barDone('wakes', fits.length, all.length, { tile: wakeDisk, clear: () => { wakeDisk = ''; } });
  if (state.wakes.on !== false) box.appendChild(el('p', 'role lg-note', T('wakes.note', { when: fmt.date(state.wakes.since), s: look })));
  label();
  unfold.hidden = shownWakes.length < 2;
  return box;
}

/** A cron line due then: the office's own by the desk whose job it is (office:<job>), else its command */
const JOB_DESK = { backup: 'backup', snapshots: 'snapshot', embycache: 'jack', gather: 'jack', moverelli: 'moverelli' };
function cronName(x) {
  const job = /^office:([a-z]+)$/.exec(x);
  if (!job) return x;
  const desk = JOB_DESK[job[1]];
  return T('wakes.office_job', { name: desk && Office.desks.has(desk) ? Office.t(desk + '.name') : job[1] });
}

/** A label: its words and its own tip */
function sureChip(c) {
  return chip(T('wakes.sure.' + c.sure), c.sure === 'sure' || c.sure === 'wrote' ? 'ok' : c.sure === 'maybe' ? 'quiet' : '', T('wakes.sure.' + c.sure + '_title'));
}

/**
 * One wake: when, which disks, the likeliest cause; unfolded its files (its name stands in the head line already),
 * every other cause with its name and files, what was due then, how timed, Unraid's own look said apart
 */
function wakeRow(w, look) {
  const r = el('div', 'row nocheck unfolds lg-wake');
  const main = el('div', 'row-main');
  const name = el('div', 'row-name', `${fmt.date(w.time)} · ${w.disks.join(' + ')}`);
  name.title = T('details');
  const meta = el('div', 'row-meta');
  const first = w.who[0];
  if (first) {
    meta.appendChild(el('b', 'lg-wake-who', causeName(first)));
    meta.appendChild(sureChip(first));
    if (w.who.length > 1) meta.appendChild(chip(T('wakes.more', { n: w.who.length - 1 }), '', T('wakes.more_title')));
  } else meta.appendChild(chip(T('wakes.cause.none'), 'quiet', T('wakes.cause.none_title')));
  if (w.cut) meta.appendChild(chip(T('wakes.cut'), 'warn', T('wakes.cut_title')));
  main.append(name, meta);
  r.appendChild(main);
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; unfoldedWakes.delete(w.id); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail lg-wake-detail');
      w.who.forEach((c, i) => {
        const one = el('div', 'lg-wake-cause');
        const head = el('div', 'lg-wake-cause-head');
        if (i > 0) head.append(el('b', '', causeName(c)), ' ', sureChip(c));
        if (c.kind !== 'program' && c.prog) head.append(i > 0 ? ' ' : '', el('span', 'role', T('wakes.prog', { name: c.prog })));
        if (head.childNodes.length) one.appendChild(head);
        if (c.files && c.files.length) one.appendChild(el('pre', 'code lg-wake-files', c.files.join('\n')));
        else if (c.cmd) one.appendChild(el('pre', 'code lg-wake-files', c.cmd));
        box.appendChild(one);
      });
      if (!w.who.length) box.appendChild(el('p', 'role', T('wakes.cause.none_title')));
      const facts = [];
      if (w.unraid) facts.push(T('wakes.unraid'));
      if (w.cron && w.cron.length) facts.push(T('wakes.cron_due', { list: w.cron.map(cronName).join(' · ') }));
      if (w.started && w.started.length) facts.push(T('wakes.started', { list: w.started.join(', ') }));
      facts.push(w.how === 'caught' ? T(w.who.length ? 'wakes.how.caught' : 'wakes.how.asked', { s: look }) : w.how === 'syslog' ? T('wakes.how.syslog')
        : T('wakes.how.noticed', { from: fmt.date(w.from), to: fmt.date(w.time) }));
      if (w.smart && w.how === 'caught') facts.push(T('wakes.smart', { when: fmt.date(w.smart) }));
      if (!w.named) facts.push(T('wakes.naming'));
      for (const x of facts) box.appendChild(el('p', 'role lg-wake-fact', x));
      r.appendChild(box);
      unfoldedWakes.add(w.id);
      r.classList.add('open');
    }
  };
  r.onclick = (e) => {
    if (e.target.closest('button, a, input, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;      // selecting text
    Office.keepInPlace(r, () => set(!box));
  };
  shownWakes.push({ open: () => !!box, set });
  if (unfoldedWakes.has(w.id)) set(true);
  return r;
}

/** What a category's tip adds: «Disks» catches USB and SCSI lines, too */
const catNote = (c) => (c === 'drive' ? ' ' + T('cat.drive_note') : '');

/** Her own rules changed (a state from the answer): drawn anew, her instant sort asked again on «Syslog» */
function mineChanged(st) {
  if (st && typeof st === 'object') state = st;
  instant = null;
  sortAsked = false;
  took({});
}

/** A kind's category by her syslog rules, as a word (for the filter words); '' without one */
const catName = (g) => (typeof g.cat === 'string' && CAT_ORDER.includes(g.cat) && g.cat !== 'other' ? T('cat.' + g.cat) : '');

/** «My syslog rules sorted the 23 712 lines I read: Network 2 378 · Disks 984 …» — every line read, per category */
function catsRead(cats) {
  const known = CAT_ORDER.filter((c) => (cats[c] || 0) > 0);
  const ranked = known.filter((c) => c !== 'other' && c !== 'skip').sort((a, b) => cats[b] - cats[a]).concat(known.filter((c) => c === 'other' || c === 'skip'));
  const sum = known.reduce((x, c) => x + cats[c], 0);
  const p = el('p', 'role lg-note lg-cats-read', T('cats.read', { n: sum, list: ranked.map((c) => `${T('cat.' + c)} ${fmt.number(cats[c])}`).join(' · ') }));
  p.title = T('cats.read_title');
  return p;
}

/**
 * «Syslog»: Unraid's syslog since her previous tour by her syslog rules — a chip per category in its bar (the note line
 * says which is on), then per category its newest kinds of lines, the newest first, coloured by category (noise and
 * what no rule knows only in the counts line). She sorts, she doesn't judge.
 */
function syslogView() {
  const d = syslogNow();
  const s = d ? d.s : null;
  const now = !!d && d.from === 'now';
  const sub = now ? T('syslog.sub_now', { n: Number(s.lines) || 0, when: s.since ? fmt.date(s.since) : '–' })
    : !tour || !tour.time || tour.first ? T('syslog.sub_first') : T('syslog.sub', { when: fmt.date(tour.since) });
  let hint = tourHint();
  if (sorting) { hint = el('span', 'hint running'); hint.append(el('span', 'spin'), ' ', T('sorting')); }
  else if (now) { hint = el('span', 'hint', T('sorted_at', { when: fmt.relative(s.time) })); hint.title = fmt.date(s.time); }
  const box = el('section', 'section lg-view');
  box.appendChild(Office.sectionHead(T('syslog.title'), sub, readButton('syslog'), hint, { place: 'syslog.title' }));
  Office.place('syslog.title', box);      // its rows are its own (a line can stand in «Errors & warnings», too)
  const inner = el('div', 'box');
  if (!s) {
    const e = el('div', 'empty');
    if (sorting || (!tourKnown && Office.agent.running)) e.append(busyLine(T('syslog.sorting')));
    else {
      e.append(el('strong', '', T('no_tour_title')), T('syslog.no_sort'));
      const b = el('button', 'btn', T('tour'));
      b.type = 'button';
      b.disabled = !Office.agent.running || touring();
      b.onclick = startTour;
      e.append(el('br'), b);
    }
    inner.appendChild(e);
    box.appendChild(inner);
    return box;
  }
  const sorted = (Array.isArray(s.sorted) ? s.sorted : []).filter((g) => g && CAT_RANK.includes(g.cat) && typeof g.sample === 'string');
  if (cat && !((s.cats || {})[cat] > 0)) cat = '';                      // its lines went
  const bar = sysBarGet();
  fillSysCats(s.cats);
  box.append(bar.el, savedRow('syslog'));
  box.appendChild(catsRead(s.cats));
  const f = bar.read();
  const span = timeSpan(bar.time.read());
  bar.time.avail(sorted.some((g) => Number(g.last) > 0));
  // a kind by its newest line (a «×n» in the time filter when its last one is)
  const rows = sortKinds(sorted.filter((g) => (!cat || g.cat === cat) && (!bar.svc || serviceOf(g.sample) === bar.svc)
    && Office.fits(f.words, [g.sample, catName(g)]) && inSpan(span, g.last)), bar.sort.value());
  const kinds = [];
  rows.forEach((g) => inner.appendChild(kindRow(g, { list: kinds, cats: true, of: 'syslog' })));
  if (!rows.length) inner.appendChild(el('p', 'empty', sorted.length ? T('found.none_fit') : T('syslog.none')));
  const more = cat && !f.words.length && !span && !bar.svc ? ((s.sorted_kinds || {})[cat] || 0) - rows.length : 0;
  if (more > 0) inner.appendChild(el('div', 'lg-more', T('more_kinds', { n: more })));
  box.appendChild(inner);
  barDone('syslog', rows.length, sorted.length, { tile: cat ? T('cat.' + cat) : '', clear: () => { cat = ''; } });
  return box;
}

/** The bar over «Syslog»: its words, a chip per category (an extra of the bar, filled anew at each drawing) */
function sysBarGet() {
  if (sysBar) return sysBar;
  const cats = el('div', 'lg-chips lg-cats');
  cats.setAttribute('role', 'group');
  cats.setAttribute('aria-label', T('cats.label'));
  sysBar = listBar('syslog', { label: T('syslog.filter_label'), extra: [cats], sort: 'newest' });
  sysBar.cats = cats;
  return sysBar;
}

/** A chip per category with lines (never the noise or what no rule knows), how many lines beside — pressed while the list shows only it */
function fillSysCats(counts) {
  const box = sysBarGet().cats;
  box.replaceChildren();
  const ids = CAT_RANK.filter((c) => (counts[c] || 0) > 0);
  box.hidden = !ids.length;
  for (const id of ids) {
    const c = el('button', 'chip lg-cat-chip lg-cat-' + id);
    c.type = 'button';
    c.dataset.cat = id;
    c.dataset.own = '1';          // its click filters (core.js: a chip with a title would only show its tip)
    c.setAttribute('aria-pressed', cat === id ? 'true' : 'false');
    c.append(el('span', 'lg-cat-dot'), el('span', 'lg-src-name', T('cat.' + id)), el('span', 'lg-counts', fmt.number(counts[id])));
    c.title = T('cats.chip_title', { n: counts[id], name: T('cat.' + id) }) + catNote(id);
    c.addEventListener('click', () => Office.keepInPlace(sysBarGet().el, () => {
      cat = cat === id ? '' : id;
      redraw();
      const again = sysBarGet().cats.querySelector(`[data-cat="${CSS.escape(id)}"]`);
      if (again) again.focus({ preventScroll: true });
    }));
    box.appendChild(c);
  }
}

/** The logs with a chip of their own (the loudest), and the rest («n more») */
function chipSources(all) {
  const loud = all.length > SRC_CHIPS ? all.slice(0, SRC_CHIPS - 1) : all;
  return { loud, rest: all.slice(loud.length) };
}

/** The bar over «Errors & warnings»: its words, the chips (an extra of the bar, filled anew at each drawing) */
function foundBarGet() {
  if (foundBar) return foundBar;
  const box = el('div', 'lg-chips');
  box.setAttribute('role', 'group');
  box.setAttribute('aria-label', T('found.chips_label'));
  foundBar = listBar('found', { label: T('found.filter_label'), extra: [box], sort: 'newest' });
  foundBar.chips = box;
  return foundBar;
}

/** A chip per loud log (its counts beside its name), «n more» for the rest — pressed while the list shows only it */
function fillChips(loud, rest) {
  const box = foundBarGet().chips;
  box.replaceChildren();
  const add = (id, name, errors, warnings, tip) => {
    const c = el('button', 'chip lg-src-chip');
    c.type = 'button';
    c.dataset.src = id;
    c.dataset.own = '1';          // a chip with a title is an explanation to core.js (its click shows the tip) — this one filters
    c.setAttribute('aria-pressed', src === id ? 'true' : 'false');
    c.append(el('span', 'lg-src-name', name), counts(errors, warnings));
    if (tip) c.title = tip;
    c.addEventListener('click', () => Office.keepInPlace(foundBarGet().el, () => {
      src = src === id ? '' : id;
      redraw();
      const again = foundBarGet().chips.querySelector(`[data-src="${CSS.escape(id)}"]`);
      if (again) again.focus({ preventScroll: true });
    }));
    box.appendChild(c);
  };
  box.hidden = loud.length + rest.length < 2;
  if (box.hidden) return;                             // one log: nothing to choose between
  loud.forEach((s) => add('src:' + s.id, sourceLabel(s), s.errors, s.warnings, sourceLabel(s)));
  if (rest.length) {
    add('more', T('tile.more_logs', { n: rest.length }), rest.reduce((n, s) => n + s.errors, 0), rest.reduce((n, s) => n + s.warnings, 0),
      rest.map(sourceLabel).join(', '));
  }
}

// ------------------------------------------------------------------ every list's bar, the time filter, saved filters
/*
 * Every list of hers has its bar (core.js Office.filterBar: the words, the note line): «Syslog», «Errors & warnings»,
 * /var/log, the containers' logs and the reader — and in it a time filter where its rows have a time: a kind of line
 * («×n») by its newest line, a file and a container's log by their last write, the reader's lines by their own (a line
 * without one — a stack trace's next lines — by the line before it). The time is said in the note line like any filter,
 * «Show all» takes it away. Her saved filters (the agent keeps them, agent/desks/logs-filters.php: every browser sees
 * the same) stand above the list, a button each: a click sets what it says — nothing ever sets one by itself
 * (2026-10-19, the office's rule: a page never opens with a hidden filter).
 */
const TIME_PRESETS = ['hour', 'day', 'today', 'boot'];
const NAME_MAX = 60;            // characters of a saved filter's name (the agent: LOGS_FILTER_NAME)
/* «Apply when opening» — not offered (decided 2026-10-19). Were it ever: this flag, and a saved filter's `open` (the
   agent keeps it, never sets it) sets that filter once when the page opens, shown in the note line like any other. */
const APPLY_ON_OPEN = false;
let openedWith = null;          // the saved filters set when this visit opened (APPLY_ON_OPEN only)

/** A list's bar (built once a page, held across drawings): the words, its extras, the time filter; o only when made */
function listBar(list, o = {}) {
  if (bars[list]) return bars[list];
  const draw = () => (list === 'read' ? renderLines(false) : redraw());
  const time = timeCtl(list, draw);
  const sort = o.sort ? sortCtl(list, o.sort, draw) : null;
  const bar = Office.filterBar({ key: 'logs.' + list, label: o.label, only: o.only,
    extra: [...(o.extra || []), ...(sort ? [sort.el] : []), time.el], onChange: o.onChange || (() => redraw()) });
  bar.time = time;
  bar.sort = sort;
  bar.svc = '';                 // the service filter (a click on a line's service): a filter, never kept
  bar.list = list;
  bars[list] = bar;
  return bar;
}
const varlogBar = () => listBar('varlog', { label: T('varlog.filter_label') });
const dockerBar = () => listBar('docker', { label: T('docker.filter_label') });
/** The reader's bar: its words, «only errors & warnings», the time by the lines' own */
const readBar = () => listBar('read', { label: T('read.filter_label'), only: { text: T('only_problems'), hint: T('help.only') },
  sort: 'oldest', onChange: () => renderLines(false) });

/** After the rows: «n of m fit», the note line with the time filter as one of its parts (its × takes the time away) */
function barDone(list, n, total, d = {}) {
  const bar = bars[list];
  const t = bar.time.read();
  const clear = d.clear;
  const parts = [];
  if (bar.svc) parts.push([T('svc.chip', { name: bar.svc }), () => { bar.svc = ''; }]);
  if (t) parts.push([timeName(t), () => bar.time.clear()]);
  const sorted = bar.sort && bar.sort.value() !== bar.sort.start ? bar.sort : null;
  bar.done(n, total, { ...d, clear: (what) => { if (clear) clear(what); if (what === 'all') { bar.time.clear(); bar.svc = ''; } },
    parts, order: sorted ? [T('sort.' + sorted.value() + '_note'), () => sorted.set(sorted.start)] : null });
}

/*
 * The service of a line (Enhanced Log's «Service» column): in a syslog line the program after the time and the server
 * — `emhttpd`, `sshd`, `kernel` … (the part before «[pid]:» or «:»); '' for lines without one. A click on it (a row's
 * Schildchen, the word in the reader's line) shows only that service; the reader's lines without one go with the line
 * before (a trace's next lines).
 */
const SERVICE = /^(?:[A-Z][a-z]{2}\s+\d{1,2}\s+\d\d:\d\d:\d\d|\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:?\d\d)?)\s+\S+\s+([^\s:[\]]{1,64})(?:\[\d+\])?:/d;
const serviceOf = (line) => { const m = SERVICE.exec(String(line || '')); return m ? m[1] : ''; };
/** Where a line's service stands in it: [from, to] — null without one */
const serviceAt = (line) => { const m = SERVICE.exec(line); return m && m.indices ? m.indices[1] : null; };
let svcsOf = null;              // [the lines, their services]
function lineServices(list) {
  if (svcsOf && svcsOf[0] === list) return svcsOf[1];
  let last = '';
  const out = list.map((l) => { const x = serviceOf(l); if (x) last = x; else if (!/^\s/.test(l) || !last) last = x; return last; });
  svcsOf = [list, out];
  return out;
}
/** A list's service filter: only that one, a click on another takes it, on the same one shows all again */
function pickService(list, name, anchor) {
  const bar = bars[list];
  if (!bar) return;
  bar.svc = bar.svc === name ? '' : name;
  Office.keepInPlace(anchor, () => (list === 'read' ? renderLines(false) : redraw()));
}

/*
 * The order of a list («Syslog», «Errors & warnings», the reader): newest first, oldest first (a «×n» by its newest
 * line), or by service (A–Z, the newest first in each). A choice — kept per browser — said in the note line only when it
 * isn't the list's own (the reader reads like the file: the oldest first, the newest at the bottom).
 */
const SORTS = ['newest', 'oldest', 'service'];
function sortCtl(list, start, onChange) {
  const box = el('select', 'picker lg-sort');
  box.setAttribute('aria-label', T('sort.label'));
  box.title = T('sort.label');
  box.dataset.keep = '1';
  SORTS.forEach((x) => box.appendChild(new Option(T('sort.' + x), x)));
  const kept = Office.store(`logs.${list}.sort`);
  box.value = SORTS.includes(kept) ? kept : start;
  const ctl = {
    el: box,
    start,
    value: () => (SORTS.includes(box.value) ? box.value : start),
    set(x) { box.value = SORTS.includes(x) ? x : start; Office.store(`logs.${list}.sort`, box.value === start ? null : box.value); },
  };
  box.addEventListener('change', () => { ctl.set(box.value); Office.keepInPlace(box, () => onChange()); });
  return ctl;
}
/** Kinds of lines in a list's order: by their newest line, or by service (none last), the newest first in each */
function sortKinds(rows, how) {
  const t = (g) => Number(g.last) || 0;
  const out = rows.slice();
  if (how === 'oldest') out.sort((a, b) => t(a) - t(b));
  else if (how === 'service') {
    out.sort((a, b) => { const x = serviceOf(a.sample), y = serviceOf(b.sample);
      return x === y ? t(b) - t(a) : !x ? 1 : !y ? -1 : x.localeCompare(y, undefined, { sensitivity: 'base' }); });
  } else out.sort((a, b) => t(b) - t(a));
  return out;
}

/** A time filter's span now: [from, to] in seconds (either null) — a preset counted from now (it stays relative), a range as set */
function timeSpan(t) {
  if (!t) return null;
  const now = Date.now() / 1000;
  if (t.preset === 'hour') return [now - 3600, null];
  if (t.preset === 'day') return [now - 86400, null];
  if (t.preset === 'today') { const d = new Date(); d.setHours(0, 0, 0, 0); return [d.getTime() / 1000, null]; }
  if (t.preset === 'boot') return Number(state?.boot) > 0 ? [Number(state.boot), null] : null;
  const from = Number(t.from) > 0 ? Number(t.from) : null;
  const to = Number(t.to) > 0 ? Number(t.to) : null;
  return from === null && to === null ? null : [from, to];
}
/** Does a row's time fit the span? No span: everything; a row without a time never fits one */
const inSpan = (span, time) => !span || (Number(time) > 0 && (span[0] === null || time >= span[0]) && (span[1] === null || time <= span[1]));

/** A time filter as the note line and a saved filter's tip say it: «Last hour», «08.10.2026 13:00 – 14:00» */
function timeName(t) {
  if (!t) return '';
  if (t.preset) return T('time.' + t.preset);
  const from = Number(t.from) > 0 ? fmt.date(t.from) : '';
  const to = Number(t.to) > 0 ? fmt.date(t.to) : '';
  return from && to ? T('time.chip_both', { from, to }) : from ? T('time.chip_from', { from }) : T('time.chip_to', { to });
}

/** «2026-10-08», «13:05» of a time (the browser's own: the range is typed in its time) */
const pad = (n) => String(n).padStart(2, '0');
const dayOf = (sec) => { const d = new Date(sec * 1000); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };
const clockOf = (sec) => { const d = new Date(sec * 1000); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
/** A typed 24-hour time: «7:05», «07:05», «0705» → [7, 5]; '' → null (the whole day); anything else false */
function clockRead(text) {
  const x = String(text || '').trim();
  if (!x) return null;
  const m = /^([01]?\d|2[0-3])(?::|\.|h)?([0-5]\d)$/.exec(x) || /^([01]?\d|2[0-3])$/.exec(x);
  return m ? [Number(m[1]), Number(m[2] || 0)] : false;
}
/** One end of the range in seconds: its day and time (no time: the day's start, or its end for «to») — null when no day */
function endAt(day, clock, isTo) {
  const m = /^(\d{4})-(\d\d)-(\d\d)$/.exec(String(day || ''));
  if (!m) return null;
  const c = clockRead(clock);
  const [h, mi] = c ? c : isTo ? [23, 59] : [0, 0];
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), h, mi, isTo ? 59 : 0, 0);
  return isNaN(d.getTime()) ? null : Math.floor(d.getTime() / 1000);
}

/**
 * The time filter in a bar: «Any time», the last hour, the last 24 hours, today, since the last start, «From … to …»
 * (a day from the calendar and a 24-hour time each; either end may stay empty). A filter: never kept (CLAUDE.md
 * «Tiles»). read() → null | {preset} | {from, to}; set(t); clear(); avail(yes): shown only where the rows have a time
 * (or while it filters).
 */
function timeCtl(list, onChange) {
  const box = el('div', 'lg-time');
  const pick = el('select', 'picker lg-time-pick');
  pick.setAttribute('aria-label', T('time.label'));
  pick.title = T('time.label');
  pick.dataset.keep = '1';
  const fill = () => {
    const was = pick.value;
    pick.replaceChildren(new Option(T('time.any'), ''), ...TIME_PRESETS.filter((p) => p !== 'boot' || Number(state?.boot) > 0)
      .map((p) => new Option(T('time.' + p), p)), new Option(T('time.range'), 'range'));
    pick.value = [...pick.options].some((o) => o.value === was) ? was : '';
  };
  fill();
  const range = el('div', 'lg-time-range');
  range.hidden = true;
  const end = (which) => {
    const wrap = el('label', 'lg-time-end');
    const day = el('input', 'search lg-time-day');
    day.type = 'date';
    day.dataset.keep = '1';
    day.setAttribute('aria-label', T('time.' + which + '_day'));
    const clock = el('input', 'search lg-time-clock');
    clock.type = 'text';
    clock.inputMode = 'numeric';
    clock.maxLength = 5;
    clock.autocomplete = 'off';
    clock.spellcheck = false;
    clock.placeholder = which === 'from' ? '00:00' : '23:59';
    clock.dataset.keep = '1';
    clock.setAttribute('aria-label', T('time.' + which + '_clock'));
    wrap.append(el('span', 'lg-time-word', T('time.' + which)), day, clock);
    range.appendChild(wrap);
    return { day, clock };
  };
  const from = end('from');
  const to = end('to');
  box.append(pick, range);
  const changed = () => Office.keepInPlace(box, () => onChange());
  const checkClock = (c) => {
    const bad = clockRead(c.value) === false;
    c.classList.toggle('lg-time-bad', bad);
    c.title = bad ? T('time.clock_bad') : '';
  };
  pick.addEventListener('change', () => { range.hidden = pick.value !== 'range'; changed(); });
  [from, to].forEach((e) => {
    e.day.addEventListener('change', changed);
    e.clock.addEventListener('change', () => { checkClock(e.clock); changed(); });
    e.clock.addEventListener('input', () => checkClock(e.clock));
  });
  let shown = true;
  const ctl = {
    el: box,
    read() {
      if (!pick.value) return null;
      if (pick.value !== 'range') return { preset: pick.value };
      const a = endAt(from.day.value, from.clock.value, false);
      const b = endAt(to.day.value, to.clock.value, true);
      return a === null && b === null ? null : { from: a, to: b };
    },
    set(t) {
      fill();
      const x = t && typeof t === 'object' ? t : null;
      if (x && TIME_PRESETS.includes(x.preset)) pick.value = [...pick.options].some((o) => o.value === x.preset) ? x.preset : '';
      else pick.value = x && (Number(x.from) > 0 || Number(x.to) > 0) ? 'range' : '';
      const put = (e, sec) => { e.day.value = Number(sec) > 0 ? dayOf(sec) : ''; e.clock.value = Number(sec) > 0 ? clockOf(sec) : ''; checkClock(e.clock); };
      put(from, pick.value === 'range' ? x.from : 0);
      put(to, pick.value === 'range' ? x.to : 0);
      range.hidden = pick.value !== 'range';
      box.hidden = !shown && !pick.value;
    },
    clear() { ctl.set(null); },
    avail(yes) {
      shown = !!yes;
      if (pick.options.length !== TIME_PRESETS.length + 2 - (Number(state?.boot) > 0 ? 0 : 1)) fill();
      box.hidden = !shown && !pick.value;
    },
  };
  return ctl;
}

/**
 * The time of each line read, in seconds — by its own (as the tour reads them: ISO with or without its zone, the syslog's
 * «Oct  8 13:05:31», dmesg -T's, PHP's) in the server's time (state.tz: its offset from UTC), a line without one by the
 * line before it; null before the first. Once per reading (the list of lines is new with each).
 */
const MONTHS = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };
const TIME_ISO = /^\[?(\d{4})[-/](\d\d)[-/](\d\d)[T ](\d\d):(\d\d):(\d\d)(?:[.,]\d+)?(Z|\s?[+-]\d\d:?\d\d)?/;
const TIME_SYSLOG = /^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d):(\d\d):(\d\d)\s/;
const TIME_DMESG = /^\[(?:[A-Z][a-z]{2}\s+)?([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d):(\d\d):(\d\d)\s+(\d{4})\]/;
const TIME_PHP = /^\[(\d\d)-([A-Z][a-z]{2})-(\d{4})\s+(\d\d):(\d\d):(\d\d)/;
function lineTime(line, tz, now) {
  const local = (y, mo, d, h, mi, se) => Date.UTC(y, mo, d, h, mi, se) / 1000 - tz;
  let m = TIME_ISO.exec(line);
  if (m) {
    const utc = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) / 1000;
    if (!m[7]) return utc - tz;
    if (m[7] === 'Z') return utc;
    const z = /([+-])(\d\d):?(\d\d)/.exec(m[7]);
    return utc - (z[1] === '-' ? -1 : 1) * (Number(z[2]) * 3600 + Number(z[3]) * 60);
  }
  if ((m = TIME_SYSLOG.exec(line)) && m[1] in MONTHS) {
    const y = new Date((now + tz) * 1000).getUTCFullYear();
    const t = local(y, MONTHS[m[1]], +m[2], +m[3], +m[4], +m[5]);
    return t > now + 86400 ? local(y - 1, MONTHS[m[1]], +m[2], +m[3], +m[4], +m[5]) : t;      // December's lines read in January
  }
  if ((m = TIME_DMESG.exec(line)) && m[1] in MONTHS) return local(+m[6], MONTHS[m[1]], +m[2], +m[3], +m[4], +m[5]);
  if ((m = TIME_PHP.exec(line)) && m[2] in MONTHS) return local(+m[3], MONTHS[m[2]], +m[1], +m[4], +m[5], +m[6]);
  return null;
}
let timesOf = null;             // [the lines, their times]
function lineTimes(list) {
  if (timesOf && timesOf[0] === list) return timesOf[1];
  const tz = Number(state?.tz) || 0;
  const now = Date.now() / 1000;
  let last = null;
  const out = list.map((l) => { const t = lineTime(l, tz, now); if (t !== null) last = t; return last; });
  timesOf = [list, out];
  return out;
}

// ---- saved filters
/** Her saved filters as the state has them — only in exactly their shape — of one list */
function savedOf(list) {
  const all = Array.isArray(state?.filters) ? state.filters : [];
  return all.filter((f) => f && typeof f === 'object' && f.list === list && typeof f.id === 'string' && typeof f.name === 'string'
    && typeof f.words === 'string' && typeof f.cat === 'string' && typeof f.src === 'string' && typeof f.only === 'boolean'
    && (f.time === null || (f.time && typeof f.time === 'object')) && (f.svc === undefined || typeof f.svc === 'string'))
    .map((f) => ({ ...f, svc: f.svc || '' }));          // an older agent's: no service
}
/** What a list's bar has set now: {words, cat, src, only, time} */
function current(list) {
  const bar = bars[list];
  if (!bar) return null;
  const input = bar.el.querySelector('.filter-words');
  return { words: input ? input.value.trim() : '', cat: list === 'syslog' ? cat : '', src: list === 'found' ? src : '',
    only: list === 'read' ? bar.read().only : false, svc: bar.svc || '', time: bar.time.read() };
}
const isSet = (f) => !!f && !!(f.words || f.cat || f.src || f.only || f.svc || f.time);
const timeKey = (t) => (!t ? '' : t.preset ? 'p:' + t.preset : `r:${Number(t.from) || 0}-${Number(t.to) || 0}`);
const sameFilter = (a, b) => !!a && !!b && a.words === b.words && a.cat === b.cat && a.src === b.src && !!a.only === !!b.only && (a.svc || '') === (b.svc || '') && timeKey(a.time) === timeKey(b.time);

/** What a filter sets, as words: «drive» · Disks · Last hour */
function filterWhat(list, f) {
  const parts = [];
  if (f.src) {
    const one = /^src:(.+)$/.exec(f.src);
    const s = one ? sourceById(one[1]) : null;
    parts.push(f.src === 'more' ? T('saved.more_logs') : s ? sourceLabel(s) : one ? one[1] : f.src);
  }
  if (f.cat) parts.push(T('cat.' + f.cat));
  if (f.svc) parts.push(T('svc.chip', { name: f.svc }));
  if (f.only) parts.push(T('only_problems'));
  if (f.words) parts.push('«' + f.words + '»');
  if (f.time) parts.push(timeName(f.time));
  return parts.join(' · ');
}

/** A saved filter set on its list (its click — never anything else, APPLY_ON_OPEN aside) */
function applySaved(list, f) {
  const bar = bars[list];
  if (!bar) return;
  bar.set({ text: f.words, only: f.only });
  if (list === 'syslog') cat = f.cat;
  if (list === 'found') src = f.src;
  bar.svc = f.svc || '';
  bar.time.set(f.time);
  if (list === 'read') renderLines(false); else redraw();
}

/** Above a list: her saved filters for it (a button each, «⋯» to rename or delete) and «Save filter…» while something is set */
function savedRow(list) {
  const box = el('div', 'lg-saved');
  fillSaved(list, box);
  return box;
}
function fillSaved(list, box) {
  if (!box) return;
  const mine = savedOf(list);
  const now = current(list);
  box.replaceChildren();
  box.hidden = !mine.length && !isSet(now);
  if (box.hidden) return;
  box.appendChild(el('span', 'lg-saved-label', mine.length ? T('saved.label') : T('saved.none')));
  for (const f of mine) {
    const one = el('span', 'lg-saved-one');
    const on = sameFilter(now, f);
    const b = el('button', 'btn small plain lg-saved-btn' + (on ? ' on' : ''), f.name);
    b.type = 'button';
    b.dataset.own = '1';
    b.dataset.saved = f.id;
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.title = T('saved.apply_title', { name: f.name, what: filterWhat(list, f) });
    b.addEventListener('click', () => Office.keepInPlace(box, () => applySaved(list, f)));
    const more = el('button', 'btn small plain lg-saved-more', '⋯');
    more.type = 'button';
    more.dataset.own = '1';
    more.setAttribute('aria-haspopup', 'menu');
    more.setAttribute('aria-label', T('saved.menu_title', { name: f.name }));
    more.title = T('saved.menu_title', { name: f.name });
    more.addEventListener('click', (e) => Office.menu(e, [
      { text: T('saved.rename'), act: () => savedRename(f), disabled: !Office.agent.running },
      { text: T('saved.delete'), kind: 'danger', act: () => savedDelete(f), disabled: !Office.agent.running },
    ]));
    one.append(b, more);
    box.appendChild(one);
  }
  if (isSet(now) && !mine.some((f) => sameFilter(now, f))) {
    const save = el('button', 'btn small lg-saved-save', T('saved.save'));
    save.type = 'button';
    save.disabled = !Office.agent.running;
    save.title = T('saved.save_hint');
    save.addEventListener('click', () => savedSave(list));
    box.appendChild(save);
  }
}

/** A dialog with a name field: the agent checks (and refuses with its reason, said in the dialog) */
function nameDialog({ title, text, name, go, send }) {
  const body = el('div', 'lg-saved-dialog');
  if (text) body.appendChild(el('p', '', text));
  const label = el('label', 'lg-saved-name');
  const input = el('input', 'search');
  input.type = 'text';
  input.maxLength = NAME_MAX;
  input.autocomplete = 'off';
  input.spellcheck = false;
  input.value = name || '';
  input.placeholder = T('saved.name_placeholder');
  label.append(el('span', '', T('saved.name_label')), input);
  const why = el('p', 'role lg-saved-why');
  body.append(label, why);
  const d = Office.dialog({
    title,
    body,
    buttons: [{ text: Office.t('common.cancel') }, { text: go, kind: '', act: async () => {
      if (!input.value.trim()) { why.textContent = T('errors.logs_filter_name_empty'); input.focus(); return false; }
      if (!Office.agent.running) return false;
      if (!(await Office.freshState(ID))) return false;          // it couldn't look again (said there)
      const j = await send(input.value.trim());
      if (j === null) return true;
      if (!j.ok) { why.textContent = Office.errorText(j.error, ID); input.focus(); return false; }
      return true;
    } }],
  });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); d.buttons[1].click(); } });
  setTimeout(() => input.focus(), 0);
  return d;
}

/** The answer's state drawn (her saved filters changed; nothing else) */
function savedChanged(st) {
  if (st && typeof st === 'object') state = st;
  took({});
}

/** «Save filter…»: what the bar has set now, under a name the user types, for this list */
function savedSave(list) {
  const f = current(list);
  if (!isSet(f)) return;
  nameDialog({
    title: T('saved.save_title'),
    text: T('saved.save_text', { what: filterWhat(list, f) }),
    go: T('saved.save_go'),
    send: async (name) => {
      const t = f.time;
      const j = await Office.api.post(`${ID}.filter_save`, { list, name, words: f.words, cat: f.cat, src: f.src, only: f.only, svc: f.svc,
        time: !t ? '' : t.preset ? t.preset : 'range', from: t && !t.preset ? t.from : null, to: t && !t.preset ? t.to : null });
      if (j.ok) { savedChanged(j.state); Office.toast(T('saved.saved', { name })); }
      return j;
    },
  });
}

/** «Rename…» — looked up again by its id first */
function savedRename(f) {
  nameDialog({
    title: T('saved.rename_title'),
    name: f.name,
    go: T('saved.rename_go'),
    send: async (name) => {
      if (!savedOf(f.list).some((x) => x.id === f.id)) { Office.toast(T('errors.logs_filter_gone'), true); took({}); return null; }
      const j = await Office.api.post(`${ID}.filter_rename`, { id: f.id, name });
      if (j.ok) { savedChanged(j.state); Office.toast(T('saved.renamed', { name })); }
      return j;
    },
  });
}

/** «Delete…» — asked first; only the saved filter goes */
function savedDelete(f) {
  Office.dialog({
    title: T('saved.delete_title'),
    body: el('p', '', T('saved.delete_text', { name: f.name })),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('saved.delete_go'), kind: 'danger', act: async () => {
      if (!Office.agent.running || !(await Office.freshState(ID))) return true;
      if (!savedOf(f.list).some((x) => x.id === f.id)) { Office.toast(T('errors.logs_filter_gone'), true); took({}); return true; }
      const j = await Office.api.post(`${ID}.filter_remove`, { id: f.id });
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return true; }
      savedChanged(j.state);
      Office.toast(T('saved.deleted', { name: f.name }));
      return true;
    } }],
  });
}

/** APPLY_ON_OPEN only (not offered): the saved filters marked `open` set once, when this visit has her state */
function openSaved() {
  if (!APPLY_ON_OPEN || openedWith || !state) return;
  openedWith = savedOf('syslog').concat(...['found', 'varlog', 'docker', 'read', 'wakes'].map(savedOf)).filter((f) => f.open === true);
  openedWith.forEach((f) => applySaved(f.list, f));
}

/**
 * One kind of line: how often, when, its newest line; a click unfolds the line in full and "Show in the log".
 * o.cats: «Syslog»'s rows — the category always, the row in its colour; o.list: where its fold is kept (shownKinds)
 */
function kindRow(g, o = {}) {
  const r = el('div', 'row nocheck unfolds lg-kind' + (o.cats ? ' lg-cat-row lg-cat-' + g.cat : ''));
  const main = el('div', 'row-main');
  const msg = g.sample.slice(g.skip || 0).replace(/^[\s:|\]-]+/, '') || g.sample;        // without its time: that is in the meta line
  const name = el('div', 'row-name lg-kind-line', msg + (g.cut ? ' …' : ''));
  name.title = T('details');
  const meta = el('div', 'row-meta');
  if (g.level === 'error' || g.level === 'warn') meta.appendChild(chip(T('kind.' + g.level), g.level === 'error' ? 'danger' : 'warn', T('kind.' + g.level + '_title')));
  if (catName(g) && (o.cats || (g.cat !== 'error' && g.cat !== 'warning'))) {       // «Errors & warnings»: error and warning — the chip before says it
    meta.appendChild(chip(catName(g), 'lg-cat lg-cat-' + g.cat, T('cats.row_title', { name: catName(g) }) + catNote(g.cat)));
  }
  const svc = o.of ? serviceOf(g.sample) : '';
  if (svc) {
    const on = bars[o.of] && bars[o.of].svc === svc;
    const b = el('button', 'chip lg-svc' + (on ? ' on' : ''), svc);
    b.type = 'button';
    b.dataset.own = '1';          // its click filters (core.js: a chip with a title would only show its tip)
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.title = on ? T('svc.all_title', { name: svc }) : T('svc.only_title', { name: svc });
    b.addEventListener('click', () => pickService(o.of, svc, b.closest('section') || b));
    meta.appendChild(b);
  }
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
  (o.list || shownKinds).push({ open: () => !!box, set });
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
  readBar().set({ text: '' });               // the line asked for must be seen: no words, no time
  readBar().time.clear();
  if (id !== opts.source) pick(id, true); else restart(true);
  if (sw.active() && sw.active() !== 'read') sw.pick('read', 'route');          // another section chosen: the reader in its place
  view.readSection.scrollIntoView({ block: 'start', behavior: 'smooth' });     /* reveal */ // the reader a tour line asked for
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
  redraw();                 // the tile «Read out» names it
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

/** What the reader shows of the lines read: its bar's words, «only errors & warnings», the time (by each line's own) */
function shown() {
  const f = readBar().read();
  const lv = levelOf(source());
  const span = timeSpan(readBar().time.read());
  const at = span ? lineTimes(lines) : null;
  const bar = readBar();
  const sv = bar.svc || bar.sort.value() === 'service' ? lineServices(lines) : null;
  const idx = [];
  lines.forEach((l, i) => { if ((!f.only || lv(l)) && Office.fits(f.words, l) && (!at || inSpan(span, at[i])) && (!bar.svc || sv[i] === bar.svc)) idx.push(i); });
  const how = bar.sort.value();
  if (how === 'newest') idx.reverse();
  else if (how === 'service') idx.sort((a, b) => (sv[a] === sv[b] ? a - b : !sv[a] ? 1 : !sv[b] ? -1 : sv[a].localeCompare(sv[b], undefined, { sensitivity: 'base' })));
  return idx.map((i) => lines[i]);
}

/** A piece of a line into a node: the text from a to b, the bar's words in it marked */
function putMarked(node, line, a, b, marks) {
  let i = a;
  for (const [x, y] of marks) {
    const from = Math.max(x, a), to = Math.min(y, b);
    if (from >= to) continue;
    if (from > i) node.append(line.slice(i, from));
    node.appendChild(el('mark', '', line.slice(from, to)));
    i = to;
  }
  if (i < b) node.append(line.slice(i, b));
}

/** Where the bar's words stand in a line: [[from, to], …] in order, overlaps joined (lower case, as the browser folds it) */
function wordSpans(line, words) {
  const lower = line.toLowerCase();
  const got = [];
  for (const w of words) {
    for (let at = lower.indexOf(w); w && at !== -1; at = lower.indexOf(w, at + w.length)) got.push([at, at + w.length]);
  }
  got.sort((a, b) => a[0] - b[0]);
  const out = [];
  for (const g of got) { const last = out[out.length - 1]; if (last && g[0] <= last[1]) last[1] = Math.max(last[1], g[1]); else out.push(g); }
  return out;
}

/** Lines into the page — as text nodes, coloured by level, the filter words marked, the tour's line (if any) marked */
function renderLines(toBottom) {
  if (!view) return;
  const out = view.out;
  const list = shown();
  const words = readBar().read().words;
  const svcAt = list.length <= 20000;          // the services clickable (each line's own span)
  const frag = document.createDocumentFragment();
  const lv = levelOf(source());
  // Unraid's syslog: each line its category's edge (as in «Syslog»), the noise quieter
  const sys = RULE_SOURCES.includes(source()?.id);
  const edge = (line) => {
    if (!sys) return '';
    const c = syslogSort(line)[1];
    return c === 'skip' ? ' lg-ce lg-hush' : CAT_RANK.includes(c) ? ' lg-ce lg-cat-' + c : ' lg-ce';
  };
  for (const line of list) {
    const row = el('span', 'lg-line ' + lv(line) + edge(line) + (isMark(line) ? ' spot' : ''));
    const marks = words.length ? wordSpans(line, words) : [];
    const sat = svcAt ? serviceAt(line) : null;
    if (sat) {
      // its service: a click shows only that one (data-svc; the reader's one listener)
      putMarked(row, line, 0, sat[0], marks);
      const sv = el('span', 'lg-svc-in' + (readBar().svc ? ' on' : ''));
      sv.dataset.svc = line.slice(sat[0], sat[1]);
      sv.title = readBar().svc ? T('svc.all_title', { name: sv.dataset.svc }) : T('svc.only_title', { name: sv.dataset.svc });
      putMarked(sv, line, sat[0], sat[1], marks);
      row.appendChild(sv);
      putMarked(row, line, sat[1], line.length, marks);
    } else if (marks.length) {
      putMarked(row, line, 0, line.length, marks);
    } else {
      row.textContent = line;
    }
    frag.append(row, '\n');
  }
  out.innerHTML = '';
  if (!list.length) out.appendChild(el('span', 'lg-empty', lines.length ? T('nothing_matches') : T('empty')));
  out.appendChild(frag);
  if (toBottom) out.scrollTop = readBar().sort.value() === 'newest' ? 0 : out.scrollHeight;     // newest first: they come in at the top
  const src = source();
  const m = view.meta || {};
  const parts = [];
  if (src?.path) parts.push(src.path);
  if (m.size !== undefined && m.size !== null) parts.push(fmt.size(m.size));
  if (m.time) parts.push(T('changed', { when: fmt.relative(m.time) }));
  parts.push(list.length === lines.length ? T('count', { n: lines.length }) : T('count_filtered', { n: list.length, total: lines.length }));
  view.status.textContent = parts.join(' · ');
  const problems = lines.filter((l) => lv(l) === 'error').length;
  view.hint.textContent = problems ? T('errors_seen', { n: problems }) : '';
  readBar().time.avail(lines.length > 0 && lineTimes(lines).some((x) => x !== null));
  barDone('read', list.length, lines.length);
  fillSaved('read', view.saved);
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
    readBar().time.avail(false);
    barDone('read', 0, 0);
    fillSaved('read', view.saved);
    return;
  }
  view.out.textContent = Office.t('common.loading');
  read(true).then(schedule);
}

// ------------------------------------------------------------------ «Set up»: her own rules and hide words
/** Her two pages (CLAUDE.md «Tiles»: two tabs = two pages): the tiles, and «Set up» (#/logs/setup) */
const PAGES = () => [{ id: '', text: Office.t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];
const RULE_CATS = [...CAT_RANK, 'skip'];          // what a rule of hers may say (her categories and the noise)
/** Her own rules and hide words as the state has them — only in exactly their shape */
function mine() {
  const m = state && state.mine && typeof state.mine === 'object' ? state.mine : {};
  const ok = (x) => x && typeof x === 'object' && typeof x.id === 'string';
  return {
    rules: (Array.isArray(m.rules) ? m.rules : []).filter((r) => ok(r) && typeof r.re === 'string' && RULE_CATS.includes(r.cat)),
    hide: (Array.isArray(m.hide) ? m.hide : []).filter((h) => ok(h) && typeof h.text === 'string'),
    max: Number(m.max) || 100,
  };
}

/** «Set up» anew (a new state, an answer): the head with the two tabs, her own rules, her hide words, what she didn't understand */
function renderSetup() {
  const v = setupView;
  if (!v) return;
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, T('setup.bubble')), actions: [],
    pages: { list: PAGES(), current: 'setup' } });
  const help = Office.pageHelp(ID, [
    [T('mine.title'), T('help.mine_text')],
    [T('hide.title'), T('help.hide_text')],
  ]);
  v.root.replaceChildren(head, help, mineSection(v), hideSection(v), credit());
}

/** A row of her own: what it says, its chips, «Remove» (after asking) */
function mineRow(text, chips, remove) {
  const r = el('div', 'row nocheck lg-mine-row');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name lg-mine-text', text));
  const meta = el('div', 'row-meta');
  chips.filter(Boolean).forEach((c) => meta.appendChild(c));
  main.appendChild(meta);
  const b = el('button', 'btn small plain', T('mine.remove'));
  b.type = 'button';
  b.disabled = !Office.agent.running;
  b.onclick = remove;
  r.append(main, b);
  return r;
}

/** «My rules»: hers first, then the ones I was given — the first that fits decides */
function mineSection(v) {
  const m = mine();
  const box = el('section', 'section lg-view');
  box.appendChild(Office.sectionHead(T('mine.title'), T('mine.sub'), el('span', 'hint', T('mine.count', { n: m.rules.length, max: m.max })), { place: 'mine.title' }));
  const list = el('div', 'box');
  m.rules.forEach((r, i) => {
    const row = mineRow(r.re, [el('span', 'lg-mine-nth', '#' + (i + 1)), chip(T('cat.' + r.cat), 'lg-cat lg-cat-' + r.cat, T('mine.cat_title', { name: T('cat.' + r.cat) }) + catNote(r.cat))],
      () => mineRemove('rule_remove', r.id, T('mine.remove_title'), r.re, T('mine.removed')));
    row.classList.add('lg-cat-row', 'lg-cat-' + r.cat);
    list.appendChild(row);
  });
  if (!m.rules.length) list.appendChild(el('p', 'empty', T('mine.none')));
  box.appendChild(list);
  v.ruleForm ??= ruleForm();
  v.ruleForm.sync(m.rules.length >= m.max);
  box.appendChild(v.ruleForm.el);
  return box;
}

/** «Hide in my view»: lines whose message holds one of these count as noise — they stay in the syslog, I only hide them */
function hideSection(v) {
  const m = mine();
  const box = el('section', 'section lg-view');
  box.appendChild(Office.sectionHead(T('hide.title'), T('hide.sub'), el('span', 'hint', T('mine.count', { n: m.hide.length, max: m.max })), { place: 'hide.title' }));
  const list = el('div', 'box');
  m.hide.forEach((h) => list.appendChild(mineRow(h.text, [], () => mineRemove('hide_remove', h.id, T('hide.remove_title'), h.text, T('hide.removed')))));
  if (!m.hide.length) list.appendChild(el('p', 'empty', T('hide.none')));
  box.appendChild(list);
  v.hideForm ??= hideForm();
  v.hideForm.sync(m.hide.length >= m.max);
  box.appendChild(v.hideForm.el);
  return box;
}

/** The form under «My rules»: a regex and a category — built once (what is typed survives a new look), checked by the agent */
function ruleForm() {
  const f = el('form', 'toolbar lg-mine-form');
  const re = el('input', 'search lg-mine-in');
  re.type = 'text';
  re.maxLength = 300;
  re.spellcheck = false;
  re.autocomplete = 'off';
  re.placeholder = T('mine.re_placeholder');
  re.setAttribute('aria-label', T('mine.re_label'));
  re.dataset.keep = '1';
  const cat = el('select', 'picker');
  cat.setAttribute('aria-label', T('mine.cat_label'));
  cat.dataset.keep = '1';
  const pick = new Option(T('mine.cat_pick'), '');
  pick.disabled = true;
  cat.appendChild(pick);
  RULE_CATS.forEach((c) => cat.appendChild(new Option(T('cat.' + c), c)));
  cat.value = '';
  const add = el('button', 'btn', T('mine.add'));
  add.type = 'submit';
  const note = el('p', 'role lg-mine-note');
  const check = () => {
    let broken = false;
    try { if (re.value) new RegExp(re.value, 'i'); } catch (e) { broken = true; }
    note.textContent = broken ? T('mine.re_broken') : '';
    note.classList.toggle('lg-mine-bad', broken);
  };
  re.oninput = check;
  f.onsubmit = async (e) => {
    e.preventDefault();
    if (!re.value.trim()) { re.focus(); return; }
    if (!cat.value) { Office.toast(T('mine.cat_missing'), true); cat.focus(); return; }
    add.disabled = true;
    const ok = await mineAct('rule_add', { re: re.value, cat: cat.value }, T('mine.added'));
    add.disabled = false;
    if (ok) { re.value = ''; cat.value = ''; check(); }
  };
  f.append(re, cat, add, note);
  return { el: f, sync(full) { add.disabled = !Office.agent.running || full; add.title = full ? T('errors.logs_mine_full', { n: mine().max }) : ''; } };
}

/** The form under «Hide in my view»: a text */
function hideForm() {
  const f = el('form', 'toolbar lg-mine-form');
  const text = el('input', 'search lg-mine-in');
  text.type = 'text';
  text.maxLength = 300;
  text.spellcheck = false;
  text.autocomplete = 'off';
  text.placeholder = T('hide.placeholder');
  text.setAttribute('aria-label', T('hide.label'));
  text.dataset.keep = '1';
  const add = el('button', 'btn', T('hide.add'));
  add.type = 'submit';
  f.onsubmit = async (e) => {
    e.preventDefault();
    if (!text.value.trim()) { text.focus(); return; }
    add.disabled = true;
    const ok = await mineAct('hide_add', { text: text.value }, T('hide.added'));
    add.disabled = false;
    if (ok) text.value = '';
  };
  f.append(text, add);
  return { el: f, sync(full) { add.disabled = !Office.agent.running || full; add.title = full ? T('errors.logs_mine_full', { n: mine().max }) : ''; } };
}

/** An action on her own: from a fresh look, the agent checks (and refuses with its reason); the answer's state drawn */
async function mineAct(action, data, done) {
  if (!Office.agent.running) return false;
  if (!(await Office.freshState(ID))) return false;          // it couldn't look again (said there)
  const j = await Office.api.post(`${ID}.${action}`, data);
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
  mineChanged(j.state);
  Office.toast(done);
  return true;
}

/** «Remove» one of hers — asked first */
function mineRemove(action, id, title, text, done) {
  const body = el('div');
  body.append(el('pre', 'code lg-kind-full', text));
  Office.dialog({
    title,
    body,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('mine.remove'), kind: 'danger', act: async () => {
      if (!(await Office.freshState(ID))) return true;
      const list = action === 'rule_remove' ? mine().rules : mine().hide;
      if (!list.some((x) => x.id === id)) { Office.toast(T('errors.logs_mine_gone'), true); took({}); return true; }
      await mineAct(action, { id }, done);
      return true;
    } }],
  });
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  /** sub: a tile (#/logs/varlog, …/docker, …/found, …/syslog, …/read — the search's places); «src:<log>»: «Errors & warnings» with
      that log's chip; «read/<source>» (the search's items): that log in the reader, as a pick in the list would */
  async mount(root, sub) {
    instant = null;               // a new visit: her instant sort anew (the newest lines)
    sortAsked = false;
    if (Office.pageFrom(PAGES(), sub) === 'setup') {
      page = 'setup';
      view = null;
      setupView = { root, ruleForm: null, hideForm: null };
      if (String(sub || '').includes('/')) Office.subroute('setup');
      renderSetup();
      await Promise.all([load(false), loadTour()]);
      if (setupView && setupView.root === root) renderSetup();
      return;
    }
    page = 'main';
    setupView = null;
    const s = String(sub || '');
    const one = /^src:(.+)$/.exec(s);
    src = one ? s : '';
    cat = '';
    const rd = /^read\/(.+)$/.exec(s);
    if (rd) {
      let id = '';
      try { id = decodeURIComponent(rd[1]); } catch (e) { id = ''; }
      if (id) { opts.source = id; Office.store('logs.source', id); }
    }
    view = build(root);
    readerSection(view);
    foundBarGet();
    sysBarGet();
    varlogBar();
    dockerBar();
    wakesBar();
    Object.values(bars).forEach((b) => { b.reset(); b.time.clear(); b.svc = ''; });      // a new visit: no words, no Schildchen, no time, no service
    wakeDisk = '';
    openedWith = null;
    if (!sw.mount(one ? 'found' : rd ? 'read' : s) && (one || rd)) Office.subroute('');
    renderHead();
    redraw();
    await Promise.all([load(false), loadTour()]);
    renderTour();
    restart();
    wantSort();
  },
  unmount() { clearTimeout(timer); clearTimeout(tourTimer); view = null; setupView = null; shownKinds = []; shownWakes = []; },
  async poll() {
    load(false);
    if (page === 'setup' || touring()) return;
    const before = tour && tour.time;
    await loadTour();
    if (view && (tour && tour.time) !== before) renderTour();
  },
  greetOk,
  agentChanged() { if (view) { renderHead(); wantSort(); } if (setupView) renderSetup(); },
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

// her places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys)
Office.places(ID, [
  { kind: 'section', key: 'reading', route: '#/logs/read' },
  ...sw.places(),             // her tiles: /var/log, the containers' logs, errors & warnings, the syslog, «Read out»
  { kind: 'help', key: 'tour', text: 'help.tour_text' },
  { kind: 'help', key: 'live', text: 'help.live' },
  { kind: 'help', key: 'only_problems', text: 'help.only' },
  { kind: 'help', key: 'help.red', text: 'help.colours' },
  ...['counted', 'rules', 'grouped', 'since', 'space', 'source', 'time', 'sort', 'saved', 'favorites', 'safe', 'wakes'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  { kind: 'help', key: 'flood.title', text: 'help.flood_text' },          // «Who fills /var/log?»
  // her «Set up» (#/logs/setup): her own rules, her hide words
  { kind: 'section', key: 'mine.title', text: 'help.mine_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
  { kind: 'section', key: 'hide.title', text: 'help.hide_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
]);

// what her state holds for the search (core.js «items»): every log she can read, by the name her list gives it (never a
// line of it) — chosen, it opens in her reader (#/logs/read/<source>, the section «Read a log»)
Office.placesFrom(ID, (s) => (s.sources || []).filter((x) => x && typeof x.id === 'string').map((x) => ({
  text: T('source.' + x.label, { name: x.param }), sub: `${T('reading')} · ${T('group.' + x.group)}`,
  route: `#/${ID}/read/${encodeURIComponent(x.id)}`, anchor: 'reading', words: x.id })));
})();
