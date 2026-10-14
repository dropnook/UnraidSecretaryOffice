/* Jack Emby — the intern and Emby fan ("check Emby"). He looks after two
   tools that ship with the office: EmbyCache (what people are about to watch
   onto the fast pool, back to its disk once watched) and the media gather
   (the files of a film or series folder together on one array disk). He sets
   them up (#/emby/setup), runs and schedules them and shows what they do.
   The agent part lives in agent/desks/emby.php. */
(() => {
'use strict';

const ID = 'emby';
const T = Office.scope(ID);
/** Ms. Moverelli's texts: the night run's card is hers and reads the same on his page (core.js Office.nightCard) */
const MT = Office.scope('moverelli');
/** «{n} <noun>» from the lang key count.<what> — its one/other forms (several counts in one text are composed of these) */
const nOf = (what, n) => T('count.' + what, { n: Number(n) || 0 });
const { el, fmt } = Office;
const POLL = 3000;
/** Where the two tools come from (helmi1987's repositories) — for the credit under the tools' tiles */
const ORIGINS = [
  ['embycache-for-unraid', 'https://github.com/helmi1987/embycache-for-unraid'],
  ['media-disk-gather-for-unraid', 'https://github.com/helmi1987/media-disk-gather-for-unraid'],
];

let state = null;
let view = null;
const POOL_PAGE = 30;                                  // rows of «Ready on the pool» shown at first (and per «Show more»)
let poolShown = POOL_PAGE;
// the filter bars (core.js Office.filterBar, CLAUDE.md «Tiles»): built once per visit (mount()), held across renders —
// «Ready on the pool» (words, the library select kept as emby.pool.pick, «only new») and «Recent runs» (words; the tile
// «Last run» filters it — the switcher's, never kept)
let poolBar = null;
let runsBar = null;
let dom = {};                 // the lists of the last drawing their bars redraw in place (runs, poolBox)
let page = 'main';            // main | setup
let outTimer = null;
// the live panel while EmbyCache runs for real (agent/desks/emby-progress.php): the part «progress», asked every 10 s
// while such a run goes (never otherwise); his state carries the same as of its look
const PROGRESS_POLL = 10000;
let progress = null;          // the panel's numbers (bars, bracket, the file being copied) — null: still planning
let progressAt = 0;           // their time (the server's clock): the newer of the part and his state wins
let stopping = null;          // «Stop after this file» asked (why: user, mover) — the button grey, «Stopping after this file …»
let progTimer = null;
let progAsked = 0;            // when the part was last asked for (this browser's clock — never compared with the server's)
let progBox = null;           // the panel on the page, redrawn in place
let nightNow = null;          // the night run with Ms. Moverelli going for real (agent: embyNightNow()) — her card's phases on his page
let startKicks = 0;           // a real run just started from here: look again until it shows as running
// the setup's pool simulator lives in streamer.js beside this file (loaded once, when its step is first drawn — like Mr.
// Restori's drill.js); it says when it is there (Office.streamerLoaded)
const SELF = typeof document !== 'undefined' && document.currentScript ? document.currentScript.src : null;

// ------------------------------------------------------------------ loading
/** His state: as kept at once, a new look following on his page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) stateIn(j.state);
  if (view && page === 'main') render();
  scheduleProgress();
}

/** His state arrives: the panel's numbers with it, unless the part brought newer ones */
function stateIn(s) {
  state = s;
  if (typeof s.time === 'number' && s.time >= progressAt) {
    progress = s.progress || null;
    stopping = s.stopping || null;
    nightNow = (s.night && s.night.now) || null;
    progressAt = s.time;
  }
}

async function act(action, data, okText) {
  const j = await Office.api.post(`${ID}.${action}`, data || {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return null; }
  if (j.state) stateIn(j.state);
  if (okText) Office.toast(okText);
  if (view && page === 'main') render();
  scheduleProgress();
  return j;
}

/** EmbyCache runs for real (run, or the let-go's release): the live panel instead of the output button */
const realRun = () => !!(state && state.jobs && state.jobs.embycache.running && ['run', 'release'].includes(state.jobs.embycache.mode));
/** The night run with Ms. Moverelli going for real (she or he at work in it, or between their steps) */
const nightGoing = () => !!nightNow;
/** Something on his page to follow live: EmbyCache's real run, or the night run */
const liveRun = () => realRun() || nightGoing();

/** While a real run goes: the part «progress» every 10 s (the first ask soon after the page shows the run); nothing otherwise */
function scheduleProgress() {
  clearTimeout(progTimer);
  progTimer = null;
  if (!view || page !== 'main' || !liveRun()) return;
  progTimer = setTimeout(askProgress, Math.max(300, PROGRESS_POLL - (Date.now() - progAsked)));
}
async function askProgress() {
  if (!view || page !== 'main' || !liveRun()) return;
  progAsked = Date.now();
  if (document.hidden || Office.dialogOpen() || Office.menuOpen()) { scheduleProgress(); return; }
  try {
    await Office.loadState(ID, { part: 'progress', fresh: true }, tookProgress);
  } catch (e) {
    scheduleProgress();
    throw e;
  }
}
function tookProgress(j) {
  const p = j && j.ok ? j.part : null;
  if (!p || typeof p.time !== 'number' || p.time < progressAt) { scheduleProgress(); return; }
  if (!p.real && !p.night) { progressAt = p.time; nightNow = null; load(true); return; }     // the run ended: his state anew — the panel goes
  // the night's steps change who works: her step over, his starting (or the other way) — his state anew for the rest
  const was = nightNow ? `${nightNow.who}|${nightNow.phase}|${realRun()}` : '';
  progress = p.progress || null;
  stopping = p.stopping || null;
  nightNow = p.night || null;
  if (nightNow && (`${nightNow.who}|${nightNow.phase}|${!!p.real}` !== was)) { progressAt = p.time; load(true); return; }
  progressAt = p.time;
  redrawProgress();
  scheduleProgress();
}
/** The panel drawn again in place */
function redrawProgress() {
  if (progBox && progBox.isConnected) {
    const n = livePanel();
    progBox.replaceWith(n);
    progBox = n;
  }
}

// ------------------------------------------------------------------ helpers
function button(text, kind, onclick) {
  const b = el('button', 'btn' + (kind ? ' ' + kind : ''), text);
  b.type = 'button';
  b.onclick = onclick;
  return b;
}
function chip(text, cls, title) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (title) c.title = title;
  return c;
}
function section(title, sub, ...extra) {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(title, sub, ...extra));
  return s;
}
const running = () => !!(state && (state.jobs.embycache.running || state.jobs.gather.running));

/** How a share suits EmbyCache with this pool (same rules as embyShareFit() in the agent) */
function shareFit(info, pool) {
  if (!info) return 'unknown';
  const use = (info.use || '').toLowerCase();
  if (use === 'no' || use === '') return 'array_only';
  if (use === 'yes' && info.secondary) return 'no_array';
  if (use === 'yes' && info.primary !== pool) return 'other_pool';
  if (use === 'yes') return 'ok';
  return 'pool_only';
}
const FIT_CLASS = { ok: 'ok', array_only: 'warn', other_pool: 'warn', no_array: 'danger', pool_only: 'danger', unknown: 'warn' };
function fitChip(fit, params) {
  return chip(T('fit_chip.' + fit), FIT_CLASS[fit], T('fit_tip.' + fit, params));
}
/** /mnt/user/Filme/… -> Filme */
const shareOf = (path) => { const m = /^\/mnt\/user\/([^/]+)/.exec(path || ''); return m ? m[1] : null; };

function schedText(sc) {
  if (!sc || !sc.enabled) return T('not_scheduled');
  return sc.frequency === 'custom' ? fmt.cron(sc.custom) : sc.frequency;
}
/** The night run with Ms. Moverelli (agent/lib/nightrun.php): one schedule for both — hers; null while she isn't hired */
const night = () => (state && state.night) || null;
/** EmbyCache's schedule as it runs: the night run's while Ms. Moverelli is hired, else its own line */
const embySched = () => (night() ? night().schedule : (state.schedules || {}).embycache) || {};
/** Tonight's plan in his words, pure: what he gives back, what of the downloads goes, what he fetches, what waits */
function tonightText(p) {
  if (!p || !p.measured) return T('night.unmeasured');
  const parts = [Number(p.back) > 0 ? T('night.back', { size: fmt.size(p.back) }) : T('night.back_none'),
    Number(p.goes) > 0 ? T('night.goes', { size: fmt.size(p.goes) }) : T('night.goes_none'),
    Number(p.fill) > 0 ? T('night.fill', { size: fmt.size(p.fill) }) : T('night.fill_none')];
  if (Number(p.wait) > 0) parts.push(T('night.wait', { size: fmt.size(p.wait) }));
  return T('night.tonight', { what: parts.join(' · ') });
}

/**
 * «anna watches … on iPad · last activity 10 hours ago» — who watches what on which device (Emby's
 * sessions, as the agent judged them) and when Emby last heard from it (`seen`): a device that went to
 * sleep with a film paused stays «watching» for hours. Now: how long ago; in the list of runs (`past`): when.
 */
function watcherText(w, past) {
  const text = T(w.paused ? 'watch.who_paused' : 'watch.who', { user: w.user || T('watch.someone'), title: w.title || '?', device: w.device || w.client || '?' });
  if (!w.seen) return text;
  return `${text} · ${past ? T('watch.seen_at', { date: fmt.date(w.seen) }) : T('watch.seen', { ago: fmt.relative(w.seen) })}`;
}
function watchList(who, past) {
  const ul = el('ul', 'shortlist');
  (who || []).forEach((w) => ul.appendChild(el('li', '', watcherText(w, past))));
  return ul;
}
const whoText = (who) => (who && who.length ? ' — ' + who.map((w) => watcherText(w, true)).join('; ') : '');
/** The gentler way than stopping Emby: end the session left behind in Emby's dashboard, or on the device; `then` what follows */
const endSessionText = (then) => T('watch.end_session') + (then ? ' ' + T(then) : '');

/** An error line inside a dialog: a refusal stays readable there (the dialog stays open) instead of a passing toast */
function errorLine() {
  const box = el('div', 'callout warn');
  box.setAttribute('role', 'alert');
  box.style.display = 'none';
  return box;
}
function showError(box, error) {
  box.innerHTML = '';
  box.appendChild(el('div', '', Office.errorText(error, ID)));
  if (error && error.key === 'emby_watching') {
    box.append(watchList((error.params || {}).who), el('p', '', T('watch.no_override')), el('p', '', endSessionText('watch.end_then_start')));
  }
  box.style.display = '';
  box.scrollIntoView({ block: 'nearest' });     /* reveal */ // the error line inside its dialog
}

/** One line about how a run went, from the tool's status */
function runSummary(r) {
  const s = r.status || r;
  if (r.result === 'refused') {
    return T('refused', { why: Office.errorText({ key: r.why, params: { detail: r.detail || '', shares: r.shares || '', file: r.file || '' } }, ID) }) + whoText(r.who)
      + (Number(r.times) > 1 ? ' · ' + T('refused_times', { n: Number(r.times) }) : '');
  }
  if (r.result === 'skipped') {
    return T(r.why === 'emby_mover_running' ? 'skipped_mover_summary' : 'gather_skipped_summary', { min: Math.round((r.waited || 0) / 60) }) + whoText(r.who);
  }
  if (r.tool === 'gather') {
    if (s.result === 'failed') return s.message || T('result.failed');
    if (r.mode === 'measure') return T('measure_summary', { n: Number(s.measured) || 0 });
    const notes = [];
    if (s.result === 'stopped') {
      const n = { done: s.folders_done || 0, total: s.folders || 0 };
      notes.push(r.why === 'mover' ? T('gather_stopped_mover', n) : r.why === 'user' ? T('gather_stopped_user', n) : T('gather_stopped', n) + whoText(r.who));
    }
    notes.push(gatherSummary(s));
    if (r.waited) notes.push(T('watch_note.waited', { min: Math.round(r.waited / 60) }));
    if (r.emby) notes.push(T('watch_note.' + r.emby));
    return notes.join(' · ');
  }
  if (s.result === 'busy') return T('result.busy');
  if (s.result === 'config') return s.message || T('result.config');
  if (s.mode === 'report' || r.mode === 'report') return s.on_deck ? T('report_summary', { n: s.on_deck.files, size: fmt.size(s.on_deck.bytes) }) : '';
  const c = s.cleanup || {}; const f = s.fill || {};
  // stopped after the file it was on: Unraid's mover started (emby-mover.php), or asked on his panel («Stop after this file»)
  const stopped = s.result === 'stopped' ? T(r.why === 'user' ? 'stopped_user_summary' : 'stopped_mover_summary') + ' · ' : '';
  // everything back to the array when he was let go (emby-letgo.php)
  if ((s.mode || r.mode) === 'release') return stopped + T('release_summary', { back: c.done || 0, planned: c.planned || 0, origin: c.to_origin || 0, left: s.protected || 0 });
  const key = (s.mode || r.mode) === 'run' ? 'run_summary' : 'dry_summary';
  const files = (n) => (key === 'dry_summary' ? nOf('files', n) : n || 0);     // «would bring 1 file back»; the run's «1 of 3» stays a number
  return stopped + T(key, { back: c.done || 0, back_planned: files(c.planned), origin: c.to_origin || 0,
    fill: f.done || 0, fill_planned: files(f.planned), fill_size: fmt.size(f.bytes_planned || 0) });
}

/** Unraid's mover as the agent sees it (emby-mover.php) — an older agent's state has none: nothing refused, nothing said */
const mover = () => (state && state.mover) || null;
const moverOk = () => { const m = mover(); return !m || !!m.ok; };
/** Why real runs don't go now, in words (the agent's refusal) */
function moverWhy(m) {
  // Ms. Moverelli's ini without his list: her shares named (emby-mover.php embyMoverelliUncovered())
  const mo = m.why === 'moverelli_list' ? (m.moverelli || {}) : null;
  const schedule = m.why === 'tuning_schedule' ? (m.tuning || {}).cron : m.schedule;
  const t = m.tuning || {};
  const name = t.name || 'Mover Tuning';      // the other mover found (an older agent: Mover Tuning)
  return Office.errorText({ key: 'emby_mover_' + m.why, params: { file: (mo && mo.file) || '', shares: ((mo && mo.uncovered) || []).join(', '), schedule: schedule || '', ...(m.why === 'tuning_schedule' ? { name } : {}) } }, ID);
}

/**
 * Unraid's mover and EmbyCache's files on the pool: real runs only under Ms. Moverelli — she is set up and keeps his
 * list out, Unraid's own mover schedule «Disabled» (2026-10-12; her setup switches it off). Not met: why; for the
 * schedule «Switch the mover schedule off…» (moverOffDialog) and «set it yourself» (Unraid's ⟦Mover Settings⟧), for her
 * a link to her setup. Met: what still moves them (⟦Move now⟧ by hand — his run stops after the current file then), the
 * mover at work right now.
 */
function moverNotice() {
  const m = mover();
  if (!m) return null;
  const box = el('div', m.ok ? 'jo-mover' : 'callout warn jo-mover');
  if (!m.ok) {
    box.appendChild(el('p', '', moverWhy(m)));
    if (m.why === 'schedule') {
      const bar = el('div', 'toolbar');
      const b = button(T('mover.off'), 'small', moverOffDialog);
      b.disabled = !Office.agent.running;
      const a = el('a', 'btn small plain', T('mover.self'));
      a.href = '/Settings/MoverSettings';
      bar.append(b, a);
      box.appendChild(bar);
    } else if (m.why === 'moverelli' || m.why === 'moverelli_list') {
      const bar = el('div', 'toolbar');
      const a = el('a', 'btn small', T('mover.to_moverelli'));
      a.href = '#/moverelli/setup';
      bar.appendChild(a);
      box.appendChild(bar);
    }
  } else {
    box.appendChild(el('p', 'role', T('mover.ok.disabled')));
    box.appendChild(el('p', 'role', T('mover.ok.moverelli')));
  }
  if (m.running) box.appendChild(el('p', 'role', T('mover.running')));
  return box;
}

/**
 * «Switch the mover schedule off…»: what it does — Unraid's own setting ⟦Settings⟧ → ⟦Scheduler⟧ → ⟦Mover Settings⟧ →
 * ⟦Disabled⟧, ⟦Move now⟧ by hand stays possible, a run of his stops when the mover starts — then emby.mover_off
 * {confirm: true}; a refusal stays readable in the dialog.
 */
async function moverOffDialog() {
  if (!(await Office.freshState(ID))) return;
  const m = mover();
  if (!m || m.why !== 'schedule') { render(); return; }      // off meanwhile: the notice says so
  const body = el('div');
  const err = errorLine();
  body.append(el('p', '', T('mover.off_text')), el('p', 'role', T('mover.off_move_now')), el('p', 'role', T('mover.off_back')), err);
  Office.dialog({
    title: T('mover.off_title'),
    body,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('mover.off_go'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.mover_off`, { confirm: true });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      Office.toast(T('mover.off_done'));
      if (view && page === 'main') render();
      return true;
    } }],
  });
}

/** A scheduled run that waits for Unraid's mover to finish (state.waiting / the gather's wait with why «mover») */
function moverWaitLine(w) {
  return el('div', 'callout', T('waiting_mover', { next: fmt.time(w.next), until: fmt.time(w.until) }));
}

/** « · also from the cache …» when the gather's cache switch is on (#14), else nothing */
function gatherCacheWords(set) {
  if (!set || set.move_cache !== true) return '';
  return ' · ' + T(set.cache_only_target === 'most-free' ? 'gather_cache_on.most_free' : 'gather_cache_on.skip');
}

/** What a gather did, each count in its own words (count.*) */
function gatherSummary(s) {
  return T('gather_summary', { moved: nOf('moved', s.moved), dups: nOf('dups', s.duplicates), conflicts: nOf('conflicts', s.conflicts),
    dirs: nOf('empty_dirs', s.dirs_deleted), kept: nOf('kept', s.dirs_kept) });
}

function bubbleText() {
  if (!state) return T('bubble.loading');
  const intro = T('bubble.intro');
  if (!state.emby.length) return `${intro} ${T('bubble.no_emby')}`;
  if (!state.python) return `${intro} ${T('bubble.no_python')}`;
  if (!state.configured) return `${intro} ${T('bubble.setup')}`;
  if (state.jobs.gather.running) return T('bubble.gathering');
  if (state.jobs.embycache.running) return T('bubble.running');
  if (state.gather.waiting) return T(state.gather.waiting.why === 'mover' ? 'bubble.mover_waiting' : 'bubble.gather_waiting');
  if (!moverOk()) return T('bubble.mover_dry');
  if (!state.gather.ready) return T('bubble.gather_first');
  const c = state.cache;
  const parts = [c.files ? T('bubble.on_pool', { n: c.files, size: fmt.size(c.bytes) }) : T('bubble.nothing_yet')];
  if (!embySched().enabled) parts.push(T('bubble.no_schedule'));
  return parts.join(' ');
}

// ------------------------------------------------------------------ main view
/*
 * His page (tiles v2, CLAUDE.md «Tiles»): a tile is a section. The row — «Last run», «Schedules», «Pool», «Shares»,
 * «Consolidate folders» — is the page's table of contents; under it stands the chosen tile's section alone, or, nothing
 * chosen, every section in the row's order. The start tile is «Pool» (what one comes to him for: what lies ready).
 * «Last run» is a filter tile: «Recent runs» with only EmbyCache's (never kept). Hints stay above the row at every
 * click (the callouts, the run's callout, the live panel, the line «How I work» with the scope), the tools' versions and
 * the credit under all. Two pages as tabs: «Overview» and «Set up» (#/emby/setup).
 */
const PAGES = () => [{ id: '', text: Office.t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];
/** Set up (or not known yet — the search's places): the pool and the shares have tiles only then */
const configured = () => !state || !!state.configured;
const sw = Office.sections({
  id: ID,                          // kept as emby.tile, routes #/emby/<tile>
  grid: 'stats jo-tiles',
  default: 'pool',
  tiles: () => [
    { id: 'runs', key: 'last_run', place: 'tile.runs', section: 'runs', filters: T('tool.embycache'), tile: (a) => lastRunTile(a) },
    { id: 'plans', key: 'schedules', place: 'tile.plans', section: () => plansSection(), tile: (a) => plansTile(a) },
    ...(configured() ? [
      { id: 'pool', key: 'tile.pool', place: 'tile.pool', section: () => poolSection(), tile: (a) => poolTile(a) },
      { id: 'shares', key: 'shares', place: 'tile.shares', section: () => shareSection(), tile: (a) => sharesTile(a) },
    ] : []),
    { id: 'gather', key: 'gather', place: 'tile.gather', section: () => gatherSection(), tile: (a) => gatherTile(a) },
  ],
  sections: { runs: () => historySection() },
});

/** The desk's own actions — on both tabs (the other page is the tab, no head button for it) */
function headActions() {
  const actions = [];
  if (state && state.configured) {
    actions.push(button(T('report'), 'plain', () => startRun('embycache', 'report')));
    actions.push(button(T('start'), '', chooseRun));
  }
  actions.forEach((b) => { b.disabled = !Office.agent.running || running() || nightGoing(); });
  return actions;
}
/** His head with the two tabs (Office.pages()): the bubble is always his own */
function deskHead() {
  return Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions: headActions(),
    pages: { list: PAGES(), current: page === 'setup' ? 'setup' : '' } }).head;
}

function render() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  root.appendChild(deskHead());
  root.appendChild(Office.pageHelp(ID, [
    [T('help.what'), T('help.what_text')],
    [T('help.tools'), T('help.tools_text')],
    [T('report'), T('help.report')],
    [T('mode.dry'), T('help.dry')],
    [T('mode.run'), T('help.run')],
    [T('help.progress'), T('help.progress_text')],
    [T('help.origin'), T('help.origin_text')],
    [T('gather'), T('help.gather_text')],
    [T('help.watch'), T('help.watch_text')],
    [T('help.shares'), T('help.shares_text')],
    [T('help.sizes'), T('help.sizes_text')],
    [T('help.pool'), T('help.pool_text')],
    [T('help.schedule'), T('help.schedule_text')],
    [T('help.letgo'), T('help.letgo_text')],
  ]));
  if (!state) return;

  // hints, no sections: they stay at every click
  if (!state.emby.length) root.appendChild(el('p', 'callout warn', T('notice.no_emby')));
  if (!state.python) root.appendChild(el('p', 'callout warn', T('notice.no_python')));
  const foreign = state.foreign.filter((f) => f.enabled);
  if (foreign.length) root.appendChild(el('p', 'callout warn', T('notice.foreign', { where: foreign.map((f) => f.where).join(', ') })));
  const back = letGoNotice();
  if (back) root.appendChild(back);
  const mv = state.configured ? moverNotice() : null;
  if (mv) root.appendChild(mv);
  const wait = (state.waiting || {}).embycache;
  if (wait) root.appendChild(moverWaitLine(wait));
  if (state.old_clone) root.appendChild(el('p', 'callout', T('notice.old_clone')));
  if (running()) {
    const p = el('p', 'callout', T(state.jobs.gather.running ? 'notice.gathering' : 'notice.running') + ' ');
    // a real EmbyCache run: its panel right under this has the raw output
    if (!realRun()) p.appendChild(button(T('show_output_now'), 'small', () => showOutput(state.jobs.gather.running ? 'gather' : 'embycache', true))).disabled = !Office.agent.running;
    root.appendChild(p);
  }
  if (!state.configured) {
    const p = el('p', 'callout', T('notice.setup') + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    p.appendChild(document.createTextNode(' '));
    p.appendChild(button(T('import.open'), 'small plain', importDialog)).disabled = !Office.agent.running;
    root.appendChild(p);
  }
  // a real run going: the live panel over the row (a hint — it stays at every click)
  progBox = liveRun() ? livePanel() : null;
  if (progBox) root.appendChild(progBox);
  if (state.configured) root.appendChild(scopeLine());
  root.appendChild(sw.el);
  sw.render();
  root.appendChild(footer());
}

/**
 * «How I work» over the row: the scope in short (films and episodes ahead or the budget, the libraries, the people) —
 * the tile «Amount» went (2026-10-12, tiles v2: a fact without a section is a head's line)
 */
function scopeLine() {
  const set = state.settings || {};
  // by number: films and series each their own (only what's chosen; older settings don't know the kinds)
  const kinds = Object.values(set.library_types || {});
  const amount = [];
  if (!kinds.length || kinds.includes('movies')) amount.push(T('count_films', { n: set.max_resume_movies ?? set.max_resume_items ?? 0 }));
  if (!kinds.length || kinds.includes('tvshows')) amount.push(T('count', { n: set.number_episodes || 0 }));
  const scope = T('libraries_people', { libs: (set.libraries || []).join(', ') || T('all'), people: peopleText(set.valid_users) });
  const line = Office.place('overview', el('div', 'jo-scope'));
  line.append(el('span', 'jo-scope-title', T('overview')),
    el('span', 'jo-scope-text', `${set.cache_budget ? T('budget', { size: set.cache_budget }) : amount.join(' · ')} — ${scope}`));
  return line;
}

/**
 * Under all (no section): the tools' versions (Python missing is a callout above) and the quiet credit — both tools
 * are helmi1987's, and a share of the tips goes to him
 */
function footer() {
  const box = Office.place('tools', el('div', 'jo-foot'));
  const line = el('div', 'row-meta jo-tools');
  const ver = (state.versions.embycache || '?').replace(/\s*\(.*\)$/, '');
  line.append(el('span', '', `EmbyCache ${ver}`), el('span', '', `${T('gather')} ${state.versions.gather || '?'}`));
  if (state.python) line.appendChild(el('span', '', `Python ${state.python}`));
  box.append(line, credit());
  return box;
}

function credit() {
  const box = el('div', 'jo-credit');
  box.append(el('b', '', T('credit_title')), ' ', T('credit', { gather: T('gather') }), ' ');
  const links = el('span', 'jo-credit-links');
  ORIGINS.forEach(([name, url], i) => {
    const a = el('a', '', name);
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    if (i) links.append(' · ');
    links.appendChild(a);
  });
  box.appendChild(links);
  return box;
}

/** The result box: what the last run of any mode did (EmbyCache's status file — an older agent sent it as «last») */
function resultBox() {
  const st = 'last_status' in state ? state.last_status : state.last;
  if (!st || !st.result) return null;
  const box = el('div', 'box jo-results');
  // why it stopped lies in his list of runs (the run's own entry: the newest EmbyCache run that started)
  const h = (state.history || []).find((r) => r.tool === 'embycache' && !['refused', 'skipped'].includes(r.result));
  box.appendChild(el('div', '', runSummary({ tool: 'embycache', mode: st.mode, status: st, result: st.result, why: h && h.result === st.result ? h.why : null })));
  if (st.incomplete) box.appendChild(el('div', 'warn-text', T('incomplete')));
  return box;
}

/**
 * «Last run» from his list of runs (2026-10-11 — never from status.json, which every start removes and a report writes
 * over): the newest real run (run, release) is the value, else the newest of any mode; a newer report or dry run only
 * named in the sub line. Refused and skipped starts didn't run. The agent's embyLastRun() picks the same. Pure (no DOM).
 * Returns {main, newer}: null each when there is none.
 */
function lastRuns(history) {
  const ran = (Array.isArray(history) ? history : []).filter((r) => r && r.tool === 'embycache' && !['refused', 'skipped'].includes(r.result));
  const main = ran.find((r) => ['run', 'release'].includes(r.mode)) || ran[0] || null;
  return { main, newer: main && ran[0] !== main ? ran[0] : null };
}

/** «04:30» within a day, else the date with it */
const nextText = (t) => (t - Date.now() / 1000 < 86400 ? fmt.time(t) : fmt.date(t));

/**
 * «Last run»: when the newest real run ended — «Real run · took 12 min · 0 errors · 0 warnings», a newer report or dry
 * run named under it; while a run goes «running since 14:05» (his job). Its section: «Recent runs» with only EmbyCache's.
 */
function lastRunTile(a) {
  const job = state.jobs.embycache || {};
  const { main, newer } = lastRuns(state.history);
  const sub = [];
  let value = T('never');
  let alert = false;
  if (job.running) {
    value = T('last.running', { time: fmt.time(job.started) });
    sub.push(T('mode.' + (job.mode || 'dry')));
  } else if (main) {
    const st = main.status || {};
    value = fmt.relative(main.finished || main.started);
    const parts = [T('mode.' + (main.mode || 'dry'))];
    if (main.finished && main.started && main.finished >= main.started) parts.push(T('last.took', { time: tookText(main.finished - main.started) }));
    if (main.result && !['ok', 'errors'].includes(main.result)) parts.push(T('result.' + main.result));
    parts.push(T('counts', { errors: nOf('errors', st.errors), warnings: nOf('warnings', st.warnings) }));
    sub.push(parts.join(' · '));
    if (newer) sub.push(T('last.newer', { mode: T('mode.' + (newer.mode || 'dry')), when: fmt.relative(newer.finished || newer.started) }));
    alert = !!(st.errors || (main.result && main.result !== 'ok'));
  }
  return Office.stat({ label: T('last_run'), value, sub, alert, tip: sub.join('\n'), ...a });
}

/** The two schedules in one tile: «2 active · next 04:30» / «off»; its section: their list */
function plansTile(a) {
  const sc = { ...(state.schedules || {}), embycache: embySched() };
  const jobs = ['embycache', 'gather'];
  const on = jobs.filter((j) => sc[j] && sc[j].enabled);
  const nexts = on.map((j) => sc[j].next).filter((n) => typeof n === 'number' && n > 0);
  const value = !on.length ? T('plans.off') : nexts.length ? T('plans.active', { n: on.length, when: nextText(Math.min(...nexts)) }) : T('plans.on', { n: on.length });
  const sub = jobs.map((j) => T('plans.one', { tool: T('tool.' + j), when: schedText(sc[j]) })).join(' · ');
  return Office.stat({ label: T('schedules'), value, sub, tip: sub, alert: !on.length, ...a });
}

/** «Schedules»: EmbyCache and consolidating — how often, the next run, «Change…» (the dialogs) */
function plansSection() {
  const s = section(T('schedules'), T('plans.sub'), { place: 'plans' });
  s.id = 'jo-plans';
  s.appendChild(plansList());
  // the night run with Ms. Moverelli: tonight's plan — one quiet line
  if (night() && night().jack) s.appendChild(el('p', 'role', tonightText(night().plan)));
  return s;
}
function plansList() {
  const box = el('div', 'box jo-plans');
  ['embycache', 'gather'].forEach((job) => {
    const sc = job === 'embycache' ? embySched() : (state.schedules || {})[job] || {};
    // EmbyCache runs in the night run with Ms. Moverelli: one time for both, set on her page
    const together = job === 'embycache' && !!night();
    const row = Office.place(`plan:${job}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', together ? T('plans.night_name') : T('tool.' + job)));
    const meta = el('div', 'row-meta');
    meta.appendChild(sc.enabled ? el('span', '', together ? T('plans.night', { when: schedText(sc) }) : schedText(sc)) : chip(T('not_scheduled'), job === 'embycache' ? 'warn' : 'quiet'));
    if (sc.enabled && typeof sc.next === 'number' && sc.next > 0) meta.appendChild(el('span', '', T('plans.next', { when: fmt.relative(sc.next) })));
    main.appendChild(meta);
    const change = together ? button(T('plans.night_open'), 'small plain', () => Office.go('#/moverelli/schedule'))
      : button(T('plans.change'), 'small plain', () => scheduleDialog(job));
    change.disabled = !together && !Office.agent.running;
    row.append(main, change);
    box.appendChild(row);
  });
  return box;
}

/**
 * «Pool» (2026-10-12: «Pool free» and «Ready on the pool» are one tile): what lies ready — files and size —, how free
 * the pool is and what EmbyCache keeps free (alert under it); its section: «Ready on the pool»
 */
function poolTile(a) {
  const c = state.cache || {};
  const pool = state.pool;
  const held = pool && pool.keep != null;
  const sub = !pool ? '' : held ? T('pool_tile.held', { used: fmt.size(pool.held), h: fmt.size(pool.keep), pct: fmt.number(pool.free_percent, 1) })
    : T('pool_tile.free', { pct: fmt.number(pool.free_percent, 1), min: pool.min_free ?? '?' });
  const tip = [pool ? T('pool_sub', { pool: pool.path, free: fmt.size(pool.free), min: pool.min_free ?? '?' }) : '', ...roomWords(),
    c.listed_at ? T('pool_tile.as_of', { when: fmt.relative(c.listed_at) }) : ''].filter(Boolean).join('\n');
  // never a break between a number and its unit («671 GB» stays on one line)
  const nb = (t) => String(t).replace(/(\d) /g, '$1\u00a0');
  return Office.stat({ label: T('tile.pool'), value: c.files ? T('pool_tile.value', { files: nb(nOf('files', c.files)), size: nb(fmt.size(c.bytes)) }) : T('pool_tile.none'),
    sub, tip, alert: !!(pool && pool.min_free != null && pool.free_percent < pool.min_free), ...a });
}

/**
 * What Ms. Moverelli gives him (2026-10-13, the pool simply — agent/lib/poollevel.php): she keeps the pool below X, he may
 * fill up to Y, his half is his for sure — what he holds, the films that wait for room, what he gives back; without her
 * limit: what he keeps free, or that she isn't set up. [line, …] in his words (pure on state.pool).
 */
function roomWords() {
  const pool = state && state.pool;
  if (!pool) return [];
  const mo = pool.moverelli;
  if (!mo) return [T('room.none')];
  if (!(mo.x > 0)) return [T('room.own', { min: pool.min_free ?? '?' })];
  if (pool.keep == null) return [T('room.plain', { x: mo.x, y: mo.y })];
  const out = [T('room.text', { x: mo.x, y: mo.y, h: fmt.size(pool.keep), used: fmt.size(pool.held) })];
  if (pool.never) out.push(T('room.never', { y: mo.y }));
  else if (pool.back > 0) out.push(T('room.back', { y: mo.y, back: fmt.size(pool.back) }));
  if (pool.waiting > 0) out.push(T('room.waiting', { n: pool.waiting }));
  const gb = pool.given_back && Array.isArray(pool.given_back.items) ? pool.given_back.items : [];
  if (gb.length) {
    const shown = gb.slice(0, 3).map(rankItem).join(' · ');
    out.push(gb.length > 3 ? T('room.gave_back_more', { list: shown, n: gb.length - 3 }) : T('room.gave_back', { list: shown }));
  }
  return out;
}

/** The shares that don't suit EmbyCache and its pool (or lack their folder on it) */
const badShares = () => (state.shares || []).filter((x) => !['ok', 'array_only'].includes(x.fit) || x.root === false).length;
/** «Shares»: how many, all suitable or how many need a look (alert); its section: «Shares» */
function sharesTile(a) {
  const shares = state.shares || [];
  const bad = badShares();
  return Office.stat({ label: T('shares'), value: nOf('shares', shares.length), sub: !shares.length ? T('shares_none') : bad ? T('shares_bad', { n: bad }) : T('shares_good'),
    tip: shares.map((x) => x.share).join(', '), alert: bad > 0, ...a });
}

/** «Consolidate folders»: when it last ran for real, what it moved and the conflicts (alert on problems); its section */
function gatherTile(a) {
  const g = state.gather || {};
  const last = g.last;
  const sub = last ? T('gather_tile.sub', { moved: nOf('moved', last.moved), conflicts: nOf('conflicts', last.conflicts) }) : g.ready ? '' : T('gather_not_ready');
  return Office.stat({ label: T('gather'), value: last ? fmt.relative(last.finished) : T('never'), sub, tip: last ? gatherSummary(last) : T('gather_never'),
    alert: !!(last && (last.conflicts || last.errors || last.full)), ...a });
}

/** A button that reads as a quiet link */
function link(text, onclick) {
  const b = el('button', 'jo-link', text);
  b.type = 'button';
  b.onclick = onclick;
  return b;
}

/**
 * The live panel (agent/desks/emby-progress.php): the bracket over everything (done of planned, speed, time left), under
 * it «back to the array» (when the run moves files back) and a bar per Emby user with the time their part still takes;
 * the file being copied small under its bar, with its reason. Before the first file: «working out what goes where».
 */
function progressPanel() {
  const box = el('div', 'box jo-prog running');
  const head = el('div', 'jo-prog-head');
  head.append(el('span', 'spin'), el('span', '', T('progress.title')));
  box.appendChild(head);
  progressRows(box);
  const foot = el('div', 'jo-prog-foot');
  if (stopping) foot.appendChild(el('span', 'jo-prog-stopping', T('progress.stopping')));
  foot.appendChild(link(T('progress.raw'), () => showOutput('embycache', true)));
  const stop = button(T('progress.stop'), 'small plain', () => stopAsk(stop));
  stop.disabled = !!stopping || !Office.agent.running;
  foot.appendChild(stop);
  box.appendChild(foot);
  return box;
}

/** The panel's bars into $box: the bracket, «back to the array», one per person, the file being copied */
function progressRows(box) {
  const p = progress;
  if (!p || p.phase === 'plan') {
    box.appendChild(el('div', 'jo-prog-line', T('progress.plan')));
  } else if (!p.total || !p.total.files) {
    box.appendChild(el('div', 'jo-prog-line', T('progress.nothing')));
  } else {
    box.appendChild(progRow(T('progress.total'), p.total, p, true));
    const rows = el('div', 'jo-prog-rows');
    if (p.back) rows.appendChild(progRow(T('progress.back'), p.back, p, false));
    const servers = new Set((p.users || []).map((u) => u.server));
    (p.users || []).forEach((u) => rows.appendChild(progRow(servers.size > 1 ? T('progress.server', { name: u.name, server: u.server }) : u.name, u, p, false)));
    box.appendChild(rows);
    const placed = (p.back && p.back.here) || (p.users || []).some((u) => u.here);
    if (p.current && !placed) box.appendChild(progFile(p.current));
  }
}

/** What follows live over his row: the night run's card while it goes, else EmbyCache's panel */
function livePanel() {
  return nightGoing() ? nightPanel() : progressPanel();
}

/**
 * The night run with Ms. Moverelli on his page (2026-10-13): her run card's head and phases (core.js Office.nightCard —
 * «Joint run», since when, the phase going), under it what is at work — EmbyCache giving back or fetching: his bars per
 * person; she moving: «Ms. Moverelli is moving downloads — <share>, 5 of 12 files» (her status, no look of its own);
 * between their steps: she measures the pool. «Stop after this file» stops the whole night (emby.stop → nightStopAsk()).
 */
function nightPanel(now) {
  const n = nightNow;
  const asked = stopping || n.stopping || null;
  const card = Office.nightCard(n, { T: MT, now: now ?? Date.now() / 1000, stopping: asked, cls: 'jo-night' });
  const jack = n.who === 'jack' && realRun();
  if (jack) {
    const rows = el('div', 'jo-prog jo-night-prog');
    progressRows(rows);
    card.appendChild(rows);
  } else {
    const her = n.who === 'her' ? n.her : null;
    const line = el('div', 'card-line jo-night-her', (her ? T('night.her_moving', { share: her.share, done: Number(her.done) || 0, files: nOf('files', her.files) })
      : T(n.who === 'her' ? 'night.her_busy' : n.who === 'jack' ? 'night.jack_soon' : 'night.her_check')) + ' ');
    line.appendChild(link(T('night.her_open'), () => Office.go('#/moverelli')));
    card.appendChild(line);
  }
  const foot = el('div', 'card-foot jo-prog-foot');
  if (jack) foot.appendChild(link(T('progress.raw'), () => showOutput('embycache', true)));
  const stop = button(T('progress.stop'), 'small plain', () => stopAsk(stop));
  stop.disabled = !!asked || !Office.agent.running;
  foot.appendChild(stop);
  card.appendChild(foot);
  return card;
}

/** «Stop after this file»: EmbyCache ends after the file it is on (emby.stop — the mover guard's stop request, why user) */
async function stopAsk(b) {
  b.disabled = true;
  const j = await act('stop');
  if (!j) { b.disabled = !Office.agent.running; return; }
  if (!stopping) { stopping = 'user'; redrawProgress(); }        // asked: grey until the run ends, whatever the answer carried
}

/** One bar: its name, done of planned (files, bytes), the total's speed, the time left (per user: their remaining bytes / the speed) */
function progRow(label, c, p, total) {
  const done = !!c.ended || (c.files > 0 && c.done_files >= c.files);
  const row = el('div', 'jo-prog-row' + (total ? ' total' : '') + (done ? ' done' : ''));
  const top = el('div', 'jo-prog-top');
  const facts = [T('progress.files', { done: Number(c.done_files) || 0, n: Number(c.files) || 0 }),
    T('progress.bytes', { done: fmt.size(c.done_bytes || 0), size: fmt.size(c.bytes || 0) })];
  if (total && p.speed) facts.push(T('progress.speed', { rate: fmt.size(p.speed) }));
  const left = done ? (typeof c.took === 'number' ? T('progress.took', { time: tookText(c.took) }) : T('progress.done'))
    : c.halted ? T('progress.halted') : etaText(c.eta, total);      // asked to stop: not in this run any more
  if (left) facts.push(left);
  if (total && typeof c.running === 'number') facts.push(T('progress.running', { time: c.running < 60 ? T('progress.secs', { s: c.running }) : fmt.duration(c.running) }));
  top.append(el('span', 'jo-prog-name', label), el('span', 'jo-prog-facts', facts.join(' · ')));
  row.appendChild(top);
  const bar = el('div', total ? 'bar jo-prog-big' : 'bar thin');
  const fill = el('i', 'snaps');
  const share = c.bytes > 0 ? c.done_bytes / c.bytes : c.files > 0 ? c.done_files / c.files : 1;
  fill.style.width = `${Math.max(0, Math.min(100, share * 100))}%`;
  bar.appendChild(fill);
  row.appendChild(bar);
  if (c.here && p.current) row.appendChild(progFile(p.current));
  return row;
}

/** How long a finished bar took: «45 s», «1 min 20 s», from an hour on «1 h 5 min» */
function tookText(s) {
  s = Math.max(0, Math.round(Number(s) || 0));
  if (s < 60) return T('progress.secs', { s });
  if (s < 3600) return T('progress.min_secs', { m: Math.floor(s / 60), s: s % 60 });
  return fmt.duration(s);
}

/** «about 4 min left»; before the first speed (two samples, 10 s) only the total says it follows */
function etaText(eta, total) {
  if (typeof eta !== 'number') return total ? T('progress.eta_first') : '';
  if (eta < 60) return T('progress.eta_soon');
  return T('progress.eta', { time: fmt.duration(Math.ceil(eta / 60) * 60) });
}

/** The file being copied: its path, how much of it is there, why it comes («Continue watching «X – S01E02»») */
function progFile(cur) {
  const line = el('div', 'jo-prog-file');
  line.append(el('span', 'jo-prog-rel', cur.rel || ''), el('span', '', T('progress.bytes', { done: fmt.size(cur.done || 0), size: fmt.size(cur.size || 0) })));
  if (cur.why) {
    line.appendChild(el('span', 'jo-prog-why', T('progress.giveback', { what: rankItem(cur.why) })));
  } else if (cur.source) {
    const src = T('progress.source.' + cur.source);
    line.appendChild(el('span', 'jo-prog-why', cur.title ? T('progress.why', { source: src, title: cur.title }) : src));
  }
  return line;
}

/**
 * What goes back over his limit, in words (jack-phase2): «Breaking Bad – S01E10 (episode 10 ahead, Mother)» — how far
 * ahead it lies for whoever watches it soonest (0 started, 1 next up, n the n-th episode ahead) and who queues it.
 */
function rankItem(w) {
  const n = Math.max(0, Number(w.ahead) || 0);
  const what = n === 0 ? T('rank.started') : n === 1 ? T('rank.next') : T('rank.ahead', { n });
  return T('rank.item', { title: w.title || '', why: w.who ? T('rank.who', { what, who: w.who }) : what });
}

function peopleText(vu) {
  const n = Array.isArray(vu) ? vu.length : Object.keys(vu || {}).length;
  return n ? T('users_n', { n }) : T('users_all');
}

/**
 * What lies where (#8): «disk2 1.8 TB · disk5 0.4 TB · maple 120 GB · measured …» from the gather's last numbers for
 * this share (its index — any run that covered it, or «Measure sizes…»), and ZFS's own count of the share's dataset
 * right now. An older agent's state has neither: nothing shown but «not measured yet».
 */
function sizesLine(x) {
  const line = el('div', 'row-meta jo-sizes');
  const sz = x.sizes;
  if (sz && typeof sz.at === 'number') {
    const roots = Array.isArray(sz.roots) ? sz.roots : [];
    const text = el('span', 'jo-sizes-sum', roots.length ? roots.map((r) => `${r.name} ${fmt.size(r.bytes)}`).join(' · ') : T('sizes_empty'));
    text.title = T('sizes_tip');
    line.append(text, el('span', '', T('sizes_at', { date: fmt.date(sz.at) })));
  } else {
    line.appendChild(el('span', '', T('sizes_never')));
  }
  (Array.isArray(x.live) ? x.live : []).forEach((l) => line.appendChild(chip(T('sizes_live', { pool: l.pool, size: fmt.size(l.used) }), 'quiet',
    T('sizes_live_tip', { share: x.share, pool: l.pool }))));
  // of that, what EmbyCache keeps ready on the pool for this share (the same list the «Ready to watch» tile counts)
  const r = readyOf(x.share);
  if (r) line.appendChild(chip(T('sizes_ready', { files: nOf('files', r.files), size: fmt.size(r.bytes) }), 'quiet', T('sizes_ready_tip', { share: x.share })));
  return line;
}

/** EmbyCache's files on the pool for a share {files, bytes} — the agent's per-share count (an older agent: from the groups), or null */
function readyOf(share) {
  const c = (state && state.cache) || {};
  const by = c.shares && typeof c.shares === 'object' ? c.shares[share] : null;
  if (by) return by.files ? by : null;
  if (c.shares) return null;
  const g = (c.groups || []).filter((x) => x.share === share);
  return g.length ? { files: g.reduce((n, x) => n + (x.files || 0), 0), bytes: g.reduce((n, x) => n + (x.bytes || 0), 0) } : null;
}

/** Do the shares of the chosen libraries suit EmbyCache and its pool? And what lies where */
function shareSection() {
  const shares = state.shares || [];
  const bad = badShares();
  const measure = button(T('measure'), 'small plain', measureDialog);
  measure.disabled = !Office.agent.running || running() || !shares.length;
  const s = section(T('shares'), T('shares_sub'), bad ? chip(T('shares_bad', { n: bad }), 'warn') : chip(T('shares_good'), 'ok'), measure, { place: 'shares' });
  const box = el('div', 'box');
  const pool = (state.settings || {}).cache_path || '';
  shares.forEach((x) => {
    const row = Office.place(`share:${x.share}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', x.share));
    const meta = el('div', 'row-meta');
    meta.append(fitChip(x.fit, { share: x.share, pool: pool.replace('/mnt/', ''), primary: x.primary || '–', secondary: x.secondary || T('array') }));
    if (x.root === false) meta.appendChild(chip(T('root_missing', { pool }), 'danger', T('root_missing_tip', { share: x.share, pool })));
    meta.appendChild(el('span', '', T('share_where', { primary: x.use === 'no' ? T('array') : (x.primary || '–'), secondary: x.use === 'no' ? '–' : (x.secondary || T('array')) })));
    if (x.include) meta.appendChild(el('span', '', T('share_disks', { disks: x.include })));
    main.append(meta, sizesLine(x));
    row.appendChild(main);
    box.appendChild(row);
  });
  if (!shares.length) box.appendChild(el('p', 'empty', T('shares_none')));
  s.appendChild(box);
  return s;
}

/** The gather: once before the first real run, then on its own schedule */
function gatherSection() {
  const g = state.gather;
  const s = section(T('gather'), T('gather_sub'), g.ready ? chip(T('gather_ready'), 'ok') : chip(T('gather_not_ready'), 'warn', T('gather_not_ready_tip')),
    { place: 'gather' });
  const set = g.settings;
  if (!set || !set.shares.length) {
    const p = el('p', 'callout', T('gather_unset') + ' ');
    p.appendChild(button(T('gather_cfg_open'), 'small', gatherSettingsDialog)).disabled = !Office.agent.running;
    s.appendChild(p);
    return s;
  }
  if (!g.ready) s.appendChild(el('p', 'callout', T('gather_first')));
  if (g.waiting && g.waiting.why === 'mover') {
    s.appendChild(moverWaitLine(g.waiting));
  } else if (g.waiting) {
    const c = el('div', 'callout');
    c.append(el('div', '', T('gather_waiting', { next: fmt.time(g.waiting.next), until: fmt.time(g.waiting.until) })), watchList(g.waiting.who),
      el('div', '', endSessionText('watch.end_then_wait')));
    s.appendChild(c);
  } else {
    const lastRun = (state.history || []).find((r) => r.tool === 'gather' && r.mode === 'run');
    if (lastRun && lastRun.result === 'skipped' && lastRun.why === 'emby_mover_running') {
      s.appendChild(el('div', 'callout', T('skipped_mover', { date: fmt.date(lastRun.started) })));
    } else if (lastRun && lastRun.result === 'skipped') {
      const c = el('div', 'callout');
      c.append(el('div', '', T('gather_skipped', { date: fmt.date(lastRun.started) })), watchList(lastRun.who, true),
        el('div', '', T('gather_skipped_again') + ' ' + endSessionText()));
      s.appendChild(c);
    }
  }
  // its schedule lies in «Schedules»; its two facts as rows (2026-10-12 — tiles in a section were too many tiles)
  const last = g.last;
  const facts = el('div', 'box jo-facts');
  facts.append(factRow(T('gather_last'), last ? fmt.relative(last.finished) : T('never'), last ? gatherSummary(last) : T('gather_never')),
    factRow(T('shares'), set.shares.join(', '), T('gather_settings', { gb: set.min_free_gb, dup: T('dup.' + set.dup_check) }) + gatherCacheWords(set)));
  s.appendChild(facts);
  if (last && (last.conflicts || last.errors || last.full)) s.appendChild(el('p', 'callout warn', T('gather_problems', { conflicts: nOf('conflicts', last.conflicts), errors: nOf('errors', last.errors), full: nOf('not_moved', last.full) })));
  const bar = el('div', 'toolbar');
  const dis = !Office.agent.running || running();
  const b1 = button(T('mode.dry'), 'small plain', () => startRun('gather', 'dry'));
  const b2 = button(T('gather_run'), 'small', gatherRunDialog);
  b1.disabled = b2.disabled = dis;
  if (!moverOk()) {
    b2.disabled = true;                    // only dry runs while the mover could take EmbyCache's files (said above)
    b2.dataset.tip = moverWhy(mover());
  }
  const b3 = button(T('gather_cfg_open'), 'small plain', gatherSettingsDialog);
  b3.disabled = dis;
  bar.append(b1, b2, b3);
  if (state.jobs.gather.mode) bar.appendChild(button(T('show_output', { mode: T('mode.' + state.jobs.gather.mode) }), 'small plain', () => showOutput('gather', false)));
  bar.appendChild(button(T('show_log'), 'small plain', () => showLog('gather')));
  s.appendChild(bar);
  return s;
}

/** A fact as a row: its name, the value, what it means */
function factRow(name, value, what) {
  const row = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.append(el('div', 'row-name', `${name}: ${value}`), el('div', 'row-meta', what));
  row.appendChild(main);
  return row;
}

/**
 * The last runs of both tools (20 in his state, 15 drawn): the last run's result box first, EmbyCache's log beside it,
 * then the filter bar's words over each run's words; the tile «Last run» shows only EmbyCache's.
 */
const HISTORY_SHOWN = 15;
function historySection() {
  const s = section(T('history'), T('history_sub'), { place: 'history' });
  s.id = 'jo-runs';
  const rb = resultBox();
  if (rb) s.appendChild(rb);
  if (state.configured) {
    // the last run's output stays behind a small link beside the log (while a real run goes, the live panel has it)
    const bar = el('div', 'toolbar jo-runs-tools');
    bar.appendChild(button(T('show_log') + ' · EmbyCache', 'small plain', () => showLog('embycache')));
    if (state.jobs.embycache.mode && !realRun()) bar.appendChild(link(T('show_output', { mode: T('mode.' + state.jobs.embycache.mode) }), () => showOutput('embycache', false)));
    s.appendChild(bar);
  }
  s.appendChild(runsBar.el);
  const box = el('div', 'box');
  s.appendChild(box);
  dom.runs = box;
  fillRuns(box);
  return s;
}
/** A run's words for the filter: the tool, the kind, the result, the date, who started it, what it did */
const runHay = (r) => [T('tool.' + r.tool), T('mode.' + r.mode), T('result.' + (r.result || 'failed')), fmt.date(r.started), T('by.' + r.by), runSummary(r)];
function fillRuns(box) {
  box.innerHTML = '';
  const runs = state.history || [];
  const f = runsBar.read();
  const only = sw.active() === 'runs' ? 'embycache' : '';            // the tile «Last run»: only EmbyCache's
  const fit = runs.filter((r) => (!only || r.tool === only) && (!f.words.length || Office.fits(f.words, runHay(r))));
  if (!runs.length) box.appendChild(el('p', 'empty', T('history_none')));
  else if (!fit.length) box.appendChild(el('p', 'empty', T('history_none_fit')));
  fit.slice(0, HISTORY_SHOWN).forEach((r) => {
    const row = Office.place(`run:${r.tool}:${r.started}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', `${T('tool.' + r.tool)} · ${T('mode.' + r.mode)}`));
    const meta = el('div', 'row-meta');
    const ok = ['ok'].includes(r.result) && !((r.status || {}).errors);
    const cls = ['refused', 'skipped', 'stopped'].includes(r.result) ? 'quiet' : ok ? 'ok' : 'warn';
    meta.append(chip(T('result.' + (r.result || 'failed')), cls, r.exit != null ? T('exit_code', { n: r.exit }) : ''),
      el('span', '', fmt.date(r.started)), el('span', '', T('by.' + r.by)));
    if (r.finished && r.finished - r.started >= 60) meta.appendChild(el('span', '', fmt.duration(r.finished - r.started)));
    main.append(meta, el('div', 'row-meta', runSummary(r)));
    row.appendChild(main);
    box.appendChild(row);
  });
  runsBar.done(fit.length, runs.length, sw.note('runs'));
}

/** A film or series folder's name as the page shows it (dots as spaces) */
const poolName = (g) => String(g.title || '').replace(/\./g, ' ');
/** Its words for the filter: the name (as shown and as on disk), the library, the disks it goes back to */
const poolHay = (g) => [poolName(g), g.title, g.share, ...(Array.isArray(g.origin) ? g.origin : [])];

/**
 * «Ready on the pool» as his page lists it — no DOM (the tests run it under node). groups: what his state holds
 * (state.cache.groups); o: {lib ('' = every library), words (Office.filterWords, [] = none), since (only what came onto
 * the pool from then on — «only new»: the last real run's start; 0 = all), shown}. It is the current state, nothing is
 * dropped: the library, the words and «only new» only narrow what is drawn.
 * Returns {libs: [[library, n], …] by name (the words not counted), rows: what they leave — newest first where a time is
 * known (`since`), else by name —, shown: rows drawn}
 */
function poolView(groups, o) {
  const list = (Array.isArray(groups) ? groups : []).filter((g) => g && typeof g.title === 'string');
  const counts = new Map();
  list.forEach((g) => counts.set(g.share, (counts.get(g.share) || 0) + 1));
  const libs = [...counts.entries()].sort((a, b) => String(a[0]).localeCompare(String(b[0])));
  const words = o.words || [];
  const rows = list.filter((g) => (!o.lib || g.share === o.lib) && (!o.since || (Number(g.since) || 0) >= o.since) && Office.fits(words, poolHay(g)))
    .sort((a, b) => (Number(b.since) || 0) - (Number(a.since) || 0) || poolName(a).localeCompare(poolName(b)));
  return { libs, rows, shown: Math.min(o.shown || POOL_PAGE, rows.length) };
}

/** One film or series on the pool: its name, library, files and size, the disk it goes back to, since when */
function poolRow(g, most) {
  const row = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', poolName(g)));
  const meta = el('div', 'row-meta');
  meta.append(chip(g.share, 'quiet'), el('span', '', T('files_n', { n: Number(g.files) || 0 })));
  if (typeof g.bytes === 'number') meta.appendChild(el('span', '', fmt.size(g.bytes)));
  if (Array.isArray(g.origin) && g.origin.length) meta.appendChild(chip(T('origin', { disks: g.origin.join(', ') }), 'quiet', T('origin_tip')));
  else meta.appendChild(chip(T('origin_unknown'), 'quiet', T('origin_unknown_tip')));
  if (g.since) meta.appendChild(el('span', '', T('pool.since', { date: fmt.date(g.since) })));
  main.appendChild(meta);
  const bar = el('div', 'bar thin jo-bar');
  const fill = el('i', 'snaps');
  fill.style.width = Math.max(2, Math.round((Number(g.bytes) || 0) / most * 100)) + '%';
  bar.appendChild(fill);
  row.append(main, bar);
  return row;
}

/**
 * What EmbyCache keeps on the pool right now, and where each thing goes back to — hundreds of rows on a big library,
 * so readable like the watch book: the filter bar (words: name, library, disk; the library select, kept; «only new»:
 * what came with the last real run — hidden while there was none), the first POOL_PAGE rows, then «Show n more». The
 * tile «Pool» shows it (his start tile).
 */
function poolSection() {
  const c = state.cache;
  const s = section(T('on_pool'), c.listed_at ? T('on_pool_sub', { when: fmt.relative(c.listed_at) }) : T('on_pool_none'),
    el('span', 'hint', c.files ? T('pool_sum', { n: c.files, size: fmt.size(c.bytes) }) : ''), { place: 'on_pool' });
  s.id = 'jo-pool';
  // what Ms. Moverelli gives him on the pool, in his words — over the list
  roomWords().forEach((line, i) => s.appendChild(el('p', i ? 'callout warn jo-slice' : 'callout jo-slice', line)));
  // the night run with Ms. Moverelli: what tonight brings — a plain line, no warning
  if (night() && night().jack && night().plan) s.appendChild(el('p', 'callout jo-slice', tonightText(night().plan)));
  if (!c.files) return s;
  s.appendChild(poolBar.el);
  const box = el('div', 'box');
  s.appendChild(box);
  dom.poolBox = box;
  fillPool(box);
  return s;
}
/** «only new» = from the last real run's start on (what it brought); 0: no real run yet — the switch goes */
function poolSince() {
  const r = (state.history || []).find((x) => x.tool === 'embycache' && x.mode === 'run' && !['refused', 'skipped'].includes(x.result));
  return r ? Number(r.started) || 0 : 0;
}
function fillPool(box) {
  box.innerHTML = '';
  const groups = Array.isArray(state.cache.groups) ? state.cache.groups : [];
  const most = Math.max(...groups.map((g) => Number(g.bytes) || 0), 1);
  const since = poolSince();
  const f = poolBar.read();
  const v = poolView(groups, { lib: f.pick, words: f.words, since: f.only ? since : 0, shown: poolShown });
  poolBar.options(v.libs.map(([l, n]) => [l, l, n]));
  v.rows.slice(0, v.shown).forEach((g) => box.appendChild(poolRow(g, most)));
  if (!v.rows.length) box.appendChild(el('p', 'empty', T('pool.empty_filter')));
  if (v.rows.length > v.shown) {
    const more = button(T('pool.more', { n: Math.min(POOL_PAGE, v.rows.length - v.shown) }), 'small plain jo-more', () => {
      poolShown += POOL_PAGE;
      Office.keepInPlace(more, () => fillPool(box));
    });
    box.appendChild(more);
  }
  poolBar.done(v.rows.length, groups.length, { onlyAvail: since > 0 });
}

// ------------------------------------------------------------------ runs
function radioList(name, options, current, onchange) {
  const box = el('div');
  options.forEach(([value, text, hint, disabled]) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = name;
    input.checked = value === current;
    input.disabled = !!disabled;
    input.onchange = () => onchange(value);
    const span = el('span', '', text);
    if (hint) span.appendChild(el('small', '', hint));
    label.append(input, span);
    box.appendChild(label);
  });
  return box;
}

// the dialogs that act on his state (a run, the gather's settings, a schedule) open only on a fresh look (Office.freshState())
async function chooseRun() {
  if (!(await Office.freshState(ID))) return;
  let mode = 'dry';
  const ready = state.gather.ready;
  const free = moverOk();
  const box = radioList('jo-mode', [
    ['dry', T('mode.dry'), T('mode_hint.dry')],
    ['run', T('mode.run'), !free ? T('mode_hint.run_mover') : ready ? T('mode_hint.run') : T('mode_hint.run_gather'), !ready || !free],
  ], mode, (m) => { mode = m; });
  // with Ms. Moverelli: the real run is the night run for both; the dry run for both lives on her schedule's row
  let dlg = null;
  if (night()) {
    const p = el('p', 'role jo-night-dry', T('night.dry_both') + ' ');
    p.appendChild(link(T('night.dry_both_open'), () => { if (dlg) dlg.close(); Office.go('#/moverelli/schedule'); }));
    box.appendChild(p);
  }
  dlg = Office.dialog({
    title: T('start_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('start_go'), kind: '', act: () => startRun('embycache', mode) }],
  });
}

async function gatherRunDialog() {
  if (!(await Office.freshState(ID))) return;
  const body = el('div');
  const err = errorLine();
  const set = state.gather.settings;
  body.append(el('p', '', T('gather_run_text', { shares: set.shares.join(', ') })));
  if (set.move_cache === true) body.appendChild(el('p', '', T(set.cache_only_target === 'most-free' ? 'gather_run_cache.most_free' : 'gather_run_cache.skip')));
  body.append(el('p', 'callout', T('gather_layout')), el('p', 'callout', T('gather_run_wake')), el('p', 'role', T('gather_run_watch')), err);
  Office.dialog({
    title: T('gather_run'),
    body,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('gather_run_go'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.gather_start`, { mode: 'run' });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      if (view && page === 'main') render();
      showOutput('gather', true);
      return true;
    } }],
  });
}

/**
 * «Measure sizes…»: a dry run of consolidating over the shares of the chosen libraries — the measurement, it changes
 * nothing; it reads every disk of those shares (sleeping ones wake), never beside EmbyCache, not while someone watches.
 */
async function measureDialog() {
  if (!(await Office.freshState(ID))) return;
  const shares = (state.shares || []).map((x) => x.share);
  const body = el('div');
  const err = errorLine();
  body.append(el('p', '', T('measure_text', { shares: shares.join(', ') })), el('p', 'callout warn', T('measure_wake')),
    el('p', 'role', T('measure_rules')), err);
  Office.dialog({
    title: T('measure').replace(/…$/, ''),
    body,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('measure_go'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.gather_start`, { mode: 'measure' });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      if (view && page === 'main') render();
      showOutput('gather', true);
      return true;
    } }],
  });
}

/** Which shares to consolidate (only those on the array), free space per disk, what counts as a duplicate, the cache (#14) */
async function gatherSettingsDialog() {
  if (!(await Office.freshState(ID))) return;
  const cur = state.gather.settings;
  const onArray = Object.entries(state.share_info).filter(([, i]) => i.use === 'no' || (i.use === 'yes' && !i.secondary));
  const chosen = new Set(cur ? cur.shares : (state.shares || []).filter((x) => ['ok', 'array_only'].includes(x.fit)).map((x) => x.share));
  const v = { min_free_gb: cur ? cur.min_free_gb : 256, dup_check: cur ? cur.dup_check : 'size',
    move_cache: !!(cur && cur.move_cache === true), cache_only_target: cur && cur.cache_only_target === 'most-free' ? 'most-free' : 'skip' };
  const box = el('div');
  box.appendChild(el('p', '', T('gather_cfg_intro')));
  box.appendChild(el('p', 'callout', T('gather_layout')));
  const list = el('div', 'jo-users');
  onArray.forEach(([share, i]) => list.appendChild(check(share, chosen.has(share), (on) => { if (on) chosen.add(share); else chosen.delete(share); },
    T('gather_cfg_share', { primary: i.use === 'no' ? T('array') : i.primary, secondary: i.use === 'no' ? '–' : T('array') }))));
  if (!onArray.length) list.appendChild(el('p', 'role', T('gather_cfg_none')));
  box.appendChild(list);
  const f = el('div', 'jo-form');
  f.append(field(T('gather_cfg_min_free'), number(v, 'min_free_gb', 0, 100000), T('gather_cfg_min_free_hint')),
    field(T('gather_cfg_dup'), select(v, 'dup_check', [['size', T('dup.size')], ['cmp', T('dup.cmp')]]), T('gather_cfg_dup_hint')));
  box.appendChild(f);
  // the cache (off = the cache left alone); folders only there: only asked while it is on
  const only = field(T('gather_cfg_cache_only'), radioList('gather_cache_only', [['skip', T('gather_cfg_cache_only.skip')],
    ['most-free', T('gather_cfg_cache_only.most_free')]], v.cache_only_target, (x) => { v.cache_only_target = x; }));
  only.hidden = !v.move_cache;
  box.append(check(T('gather_cfg_cache'), v.move_cache, (on) => { v.move_cache = on; only.hidden = !on; }, T('gather_cfg_cache_hint')), only);
  Office.dialog({
    title: T('gather_cfg_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('setup.save'), kind: '', act: async () =>
      !!(await act('gather_save', { gather: { shares: [...chosen], min_free_gb: v.min_free_gb, dup_check: v.dup_check,
        move_cache: v.move_cache, cache_only_target: v.cache_only_target } }, T('gather_cfg_saved'))) }],
  });
}

async function startRun(tool, mode) {
  const j = await act(tool === 'gather' ? 'gather_start' : 'start_run', { mode });
  if (!j) return false;
  if (tool === 'embycache' && mode === 'run') {
    // a real run: its live panel on the page (no output dialog) — look again until the run shows as running
    startKicks = 3;
    kickStart();
    return true;
  }
  showOutput(tool, true);
  return true;
}
function kickStart() {
  if (realRun() || startKicks <= 0 || !view) { startKicks = 0; return; }
  startKicks -= 1;
  setTimeout(() => { if (view && !realRun()) load(true).then(kickStart); }, 2500);
}

/** The output of the last run of a tool, following it while it runs */
async function showOutput(tool, follow) {
  clearTimeout(outTimer);
  const pre = el('pre', 'code', Office.t('common.loading'));
  const status = el('p', 'role');
  const box = el('div');
  box.append(status, pre);
  const dlg = Office.dialog({ title: T('output_title.' + tool), body: box, wide: 'log', onClose: () => clearTimeout(outTimer) });
  let wasRunning = false;
  const tick = async () => {
    const j = await Office.api.post(`${ID}.output`, { tool });
    if (!j.ok) { pre.textContent = Office.errorText(j.error, ID); return; }
    const atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
    pre.textContent = j.text || T('output_empty');
    const info = j.info || {};
    status.textContent = info.mode ? `${T('mode.' + info.mode)} · ${info.running ? T('output_running') : T('output_done')} · ${fmt.date(info.started)}` : '';
    if (atEnd || follow) pre.scrollTop = pre.scrollHeight;
    // a run started a moment ago may not have written its first line yet
    const young = info.started && Date.now() / 1000 - info.started < 10;
    if (info.running || (follow && young && !wasRunning)) { wasRunning = wasRunning || info.running; outTimer = setTimeout(tick, POLL); }
    else if (follow) { follow = false; load(true); }
  };
  tick();
  return dlg;
}

async function showLog(tool) {
  const pre = el('pre', 'code', Office.t('common.loading'));
  Office.dialog({ title: tool === 'gather' ? 'consolidate.log' : 'embycache.log', body: pre, wide: 'log' });
  const j = await Office.api.post(`${ID}.log`, { tool });
  pre.textContent = j.ok ? (j.text || T('output_empty')) : Office.errorText(j.error, ID);
  pre.scrollTop = pre.scrollHeight;
}

// ------------------------------------------------------------------ schedules
/**
 * When a tool runs on its own. EmbyCache: once a night (2026-10-09; default 03:00);
 * the gather: once a week or every night. Or a cron expression, or not at all.
 */
async function scheduleDialog(job) {
  if (!(await Office.freshState(ID))) return;
  const sc = state.schedules[job] || {};
  const ready = job === 'gather' ? !!(state.gather.settings && state.gather.settings.shares.length) : state.configured;
  if (!ready) {
    Office.dialog({
      title: T('schedule_title.' + job),
      body: T('schedule.need_setup'),
      buttons: [{ text: Office.t('common.close') }, { text: T('setup_open'), kind: '', act: () => { Office.go(`#/${ID}/setup`); } }],
    });
    return;
  }
  const cur = (sc.enabled && sc.custom) || '';
  const pad = (n) => String(n).padStart(2, '0');
  const m2 = /^(\d{1,2}) (\d{1,2}) \* \* (\*|[0-7])$/.exec(cur);           // daily / weekly
  // EmbyCache once deep in the night, when nobody watches (2026-10-09 — no more hourly / every few hours;
  // a schedule of those kinds saved earlier shows as «own schedule»)
  let mode;
  if (!sc.enabled) mode = job === 'gather' ? 'weekly' : 'daily';
  else if (m2 && job === 'gather' && m2[3] !== '*') mode = 'weekly';      // every night would wake every disk: not offered
  else if (m2 && job === 'embycache' && m2[3] === '*') mode = 'daily';
  else mode = 'custom';

  const time = el('input', 'input');
  time.type = 'time';
  time.value = m2 ? `${pad(m2[2])}:${pad(m2[1])}` : (job === 'gather' ? '04:00' : '03:00');
  const day = el('select', 'picker');
  [1, 2, 3, 4, 5, 6, 0].forEach((d) => day.appendChild(new Option(T('weekday.' + d), String(d))));
  day.value = m2 && m2[3] !== '*' ? String(Number(m2[3]) % 7) : '0';
  const cron = el('input', 'input mono');
  cron.value = cur || (job === 'gather' ? '0 4 * * 0' : '0 3 * * *');
  cron.spellcheck = false;

  const box = el('div', 'jo-schedule');
  box.appendChild(el('p', '', T('schedule.intro', { what: T('tool.' + job) })));
  const option = (id, text, hint, ...extra) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'jo-schedule';
    input.checked = mode === id;
    input.onchange = () => { mode = id; update(); };
    const span = el('span', '', text);
    if (hint) span.appendChild(el('small', '', hint));
    label.append(input, span);
    box.appendChild(label);
    if (extra.length) {
      const f = el('div', 'jo-schedule-field');
      f.append(...extra);
      box.appendChild(f);
    }
  };
  if (job === 'embycache') {
    option('daily', T('schedule.nightly'), T('schedule.nightly_hint'), time);
  } else {
    option('weekly', T('schedule.weekly'), T('schedule.weekly_hint'), day, time);
  }
  option('custom', T('schedule.custom'), T(job === 'gather' ? 'schedule.custom_hint_gather' : 'schedule.custom_hint'), cron);
  option('off', T('schedule.off'), T('schedule_off_hint.' + job));
  const update = () => {
    time.disabled = !['weekly', 'daily'].includes(mode);
    day.disabled = mode !== 'weekly';
    cron.disabled = mode !== 'custom';
  };
  update();
  Office.dialog({
    title: T('schedule_title.' + job),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('schedule.save'), kind: '', act: async () => {
        let expr = '';
        if (mode === 'weekly' || mode === 'daily') {
          if (!/^\d\d:\d\d$/.test(time.value)) { Office.toast(T('schedule.need_time'), true); return false; }
          const [h, m] = time.value.split(':').map(Number);
          expr = `${m} ${h} * * ${mode === 'weekly' ? day.value : '*'}`;
        } else if (mode === 'custom') expr = cron.value.trim();
        const j = await Office.api.post(`${ID}.schedule`, { job, cron: expr });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.state) state = j.state;
        if (!j.live) Office.toast(T('schedule.not_live'), true);
        else Office.toast(expr ? T('schedule.saved_on', { when: fmt.cron(expr) }) : T('schedule.saved_off'));
        if (view && page === 'main') render();
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ setup (#/emby/setup)
/*
 * Everything EmbyCache's own setup asks (embycache_setup.py), plus the
 * choices it leaves to its settings file — as a wizard (2026-10-14, Jack Streamer): step chips (the row of tiles),
 * «Back» / «Next» under each step, saving in the summary:
 *   1 server (the media server, then one or more Emby instances)   2 libraries and their folders (the shares' pool)
 *   3 settings (amount; pool and sharing; «not watched for N days»; Advanced)   4 people (by budget: their own budgets)
 *   5 the pool simulator (streamer.js)   6 summary and «Save» (the import: offered in step 1 before he is set up)
 * Saving writes the same settings as before, plus give_back_unwatched_days (read by nothing yet).
 * The gather has its own small settings (gatherSettingsDialog).
 */
let form = null;

function initForm() {
  const set = state.settings || {};
  const container = state.emby[0] || {};
  const inst = (set.instances || []).length ? set.instances : [{ servername: container.name || 'Emby', url: container.url || '', has_key: false, path_mappings: {} }];
  const vu = set.valid_users || [];
  const budgets = {};
  if (!Array.isArray(vu)) for (const [id, o] of Object.entries(vu)) if (o && o.budget) budgets[id] = o.budget;
  form = {
    instances: inst.map((i) => {
      const maps = { ...(i.path_mappings || {}) };
      const skip = new Set(Object.keys(maps).filter((p) => maps[p] === ''));     // '' = deliberately not cached
      skip.forEach((p) => delete maps[p]);
      return { servername: i.servername, url: i.url, api_key: '', has_key: !!i.has_key, mappings: maps, skip, server: null, libraries: [], users: [] };
    }),
    chosenLibs: new Set(set.libraries || []),
    chosenUsers: new Set(Array.isArray(vu) ? vu : Object.keys(vu)),
    budgets,
    values: {
      cache_path: set.cache_path && state.pools.includes(set.cache_path) ? set.cache_path : (state.pools[0] || ''),
      budget_mode: !!set.cache_budget,
      cache_budget: set.cache_budget || '',
      number_episodes: set.number_episodes ?? 3,
      movie_share_percent: set.movie_share_percent ?? 50,
      max_episodes_per_series: set.max_episodes_per_series ?? 0,
      max_resume_movies: set.max_resume_movies ?? set.max_resume_items ?? 10,
      max_resume_series: set.max_resume_series ?? set.max_resume_items ?? 10,
      max_favorite_series: set.max_favorite_series ?? 10,
      use_next_up: set.use_next_up ?? true,
      min_free_percent: set.min_free_percent ?? 20,
      return_to_origin: set.return_to_origin ?? true,
      array_source: set.array_source || 'user0',
      create_share_root: !!set.create_share_root,      // no field: kept as his settings have it, written on unchanged
      give_back_unwatched_days: Number.isInteger(set.give_back_unwatched_days) ? set.give_back_unwatched_days : UNWATCHED_DAYS,
    },
  };
}
/** «Not watched for … days» when his settings have none (agent: EMBY_UNWATCHED_DAYS) */
const UNWATCHED_DAYS = 30;

/** Docker path -> host path: from the Emby container's own mounts (longest match), else /data|/media -> /mnt/user */
function suggestMapping(path) {
  const mounts = (state.emby[0] || {}).mounts || {};
  let best = null;
  for (const [dest, src] of Object.entries(mounts)) {
    if ((path === dest || path.startsWith(dest + '/')) && (!best || dest.length > best[0].length)) best = [dest, src];
  }
  if (best) return best[1] + path.slice(best[0].length);
  for (const prefix of ['/data', '/media', '/mnt/user', '/mnt']) {
    if (path === prefix || path.startsWith(prefix + '/')) return ('/mnt/user/' + path.slice(prefix.length).replace(/^\//, '')).replace(/\/$/, '');
  }
  return '/mnt/user' + path;
}

/**
 * A first setup: the pool is the primary pool of the film and series shares
 * that also lie on the array; chosen are the film and series libraries whose
 * share suits that pool.
 */
function suggestChoice(inst) {
  const media = inst.libraries.filter((l) => ['movies', 'tvshows'].includes(l.type));
  const shares = (lib) => lib.locations.filter((p) => !inst.skip.has(p)).map((p) => shareOf(inst.mappings[p])).filter(Boolean);
  const primaries = media.flatMap(shares).map((sh) => state.share_info[sh])
    .filter((info) => info && info.use === 'yes' && !info.secondary && state.pools.includes('/mnt/' + info.primary));
  if (primaries.length) form.values.cache_path = '/mnt/' + primaries[0].primary;
  const pool = form.values.cache_path.replace('/mnt/', '');
  const fitting = media.filter((lib) => shares(lib).every((sh) => cacheable(sh) && state.share_info[sh].primary === pool));
  (fitting.length ? fitting : media).forEach((l) => form.chosenLibs.add(l.name));
}

/** Libraries of all connected servers by name: [{name, type, at: [[instance index, location], …]}] */
function libraryList() {
  const byName = new Map();
  form.instances.forEach((inst, i) => (inst.libraries || []).forEach((lib) => {
    if (!byName.has(lib.name)) byName.set(lib.name, { name: lib.name, type: lib.type, at: [] });
    lib.locations.forEach((p) => byName.get(lib.name).at.push([i, p]));
  }));
  return [...byName.values()];
}

function renderSetup() {
  if (!view) return;
  const root = view;
  const spot = wiz ? null : focusSpot();      // the control a click redraws the page from keeps the focus (focusBack())
  root.innerHTML = '';
  // the tabs say where one is (no «← Back», no head button); under them his setup as it stands — the wizard is a dialog
  root.appendChild(deskHead());
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('import.title'), T('import.help')],
    [T('setup.key'), T('setup.help_key')],
    [T('setup.mapping'), T('setup.help_mapping')],
    [T('setup.users'), T('setup.help_users')],
    [T('setup.scope'), T('setup.help_scope')],
    [T('setup.more'), T('setup.help_more')],
    [T('share.title'), T('share.help')],
    [T('sim.title'), T('sim.help')],
    [T('setup.save'), T('setup.help_save')],
  ]));
  if (!state) return;
  if (!form) initForm();
  loadStreamer();                    // the simulator's math (the sharing words use it too): once per page life
  root.appendChild(startStep() === 'summary' ? summaryCard() : introCard());
  if (wiz) drawWizard();
  else focusBack(spot);
}

// ---- the wizard (2026-10-14, the owner: a guided wizard like Unraid's own welcome setup) — an exclusive dialog over the
// page (Office.dialog({modal}): near the top, the page dimmed, blurred and inert, a click outside does nothing, Escape =
// «Cancel»). One step at a time: the step's title, a short intro, the step, a foot with «Cancel», «Back», where one is,
// «Skip» where it makes sense, «Next ›» — «Save» on the summary. The «Set up» tab shows the summary (each row's «Change»
// opens the wizard at that step), the first time an intro card with «Start the setup». A step's route
// #/emby/setup/<step> (bookmarks, the search) opens the wizard there; closing it is #/emby/setup again. «Cancel» with
// changes asks inside the dialog («Discard changes?»), without just closes. The form lives across every drawing (form).
/** The steps in the wizard's order: {id, place (the lang key of its title — its place for the search)} */
// (2026-10-14, the owner: the people after the settings — what is set per person depends on the amount's mode)
const SETUP_STEPS = [{ id: 'servers', place: 'wizard.title.servers' }, { id: 'libraries', place: 'wizard.title.libraries' },
  { id: 'settings', place: 'wizard.title.settings' }, { id: 'users', place: 'wizard.title.users' },
  { id: 'simulator', place: 'sim.title' }, { id: 'summary', place: 'wizard.summary' }];
/** Steps one may skip (what they hold stays as it is) */
const SKIPPABLE = new Set(['users', 'settings', 'simulator']);
/** The routes of the setup's tiles before the wizard (bookmarks, an old search): the step that holds them now */
const OLD_STEPS = { scope: 'settings', more: 'settings', import: 'servers' };
/** The wizard while it is open: {step, dlg (Office.dialog's answer), box (its body), snap (the form when opened), confirm} */
let wiz = null;
const connected = () => !!form && form.instances.some((i) => i.server);
/** What the «Set up» tab shows: the summary once he is set up, the intro card before */
const startStep = () => (state && state.configured ? 'summary' : 'servers');
/** A step's id from a route's part (old tile ids map to their step), '' when none */
function stepOf(sub) {
  const s = OLD_STEPS[sub] || sub;
  return SETUP_STEPS.some((x) => x.id === s) ? s : '';
}
/** The form as a string — changed since the wizard opened? («Discard changes?») */
function formKey() {
  if (!form) return '';
  return JSON.stringify({ i: form.instances.map((x) => [x.servername, x.url, x.api_key, x.mappings, [...x.skip].sort()]),
    l: [...form.chosenLibs].sort(), u: [...form.chosenUsers].sort(), b: form.budgets, v: form.values });
}
const changed = () => !!wiz && formKey() !== wiz.snap;

/** The wizard opened at a step (the intro card's «Start», a summary row's «Change», a step's route) */
function openWizard(id) {
  if (!form) initForm();
  if (wiz) { goStep(id); return; }
  const box = el('div', 'jo-wiz');
  const footBox = el('div', 'jo-wiz-foot');              // in the dialog's own foot: always in view, the body scrolls
  const me = { step: id, box, footBox, snap: formKey(), confirm: false, dlg: null };
  wiz = me;
  me.dlg = Office.dialog({ title: '', body: box, foot: footBox, wide: true, modal: true, cls: 'jo-wiz-dialog', onEscape: cancelWizard,
    onClose: () => { if (wiz === me) wiz = null; if (view && page === 'setup') Office.subroute('setup'); } });
  Office.subroute(`setup/${id}`);
  drawWizard();
}
/** To a step inside the wizard: drawn anew, the dialog's content back at its top */
function goStep(id) {
  if (!wiz) { openWizard(id); return; }
  wiz.step = id;
  wiz.confirm = false;
  Office.subroute(`setup/${id}`);
  drawWizard();
  const scroller = wiz.box.closest('.dialog-body');
  if (scroller) scroller.scrollTop = 0;
}
/** «Cancel» (and Escape): with changes ask first, inside the dialog; without close */
function cancelWizard() {
  if (!wiz) return;
  if (changed() && !wiz.confirm) { wiz.confirm = true; drawWizard(); return; }
  closeWizard();
}
/** The wizard closed without saving: the form back to his settings, the tab drawn anew */
function closeWizard() {
  const w = wiz;
  wiz = null;
  form = null;
  if (w) w.dlg.close();
  renderSetup();
}

/** The open wizard's content anew: intro, the step, the foot — or «Discard changes?» */
function drawWizard() {
  if (!wiz) return;
  const spot = focusSpot();
  const id = wiz.step;
  const i = SETUP_STEPS.findIndex((x) => x.id === id);
  const { place: key } = SETUP_STEPS[i];
  wiz.dlg.setTitle(T(key));
  const box = wiz.box;
  box.replaceChildren();
  box.dataset.section = id;                // focusSpot()/focusBack() find the control again inside it
  const foot = wiz.footBox;
  foot.replaceChildren();
  foot.style.display = wiz.confirm ? 'none' : '';
  if (wiz.confirm) {
    const c = el('div', 'jo-wiz-confirm');
    c.append(el('p', 'jo-wiz-confirm-q', T('wizard.discard_q')), el('p', 'role', T('wizard.discard_text')));
    const bar = el('div', 'toolbar');
    const keep = button(T('wizard.keep_editing'), '', () => { wiz.confirm = false; drawWizard(); });
    bar.append(button(T('wizard.discard'), 'danger', closeWizard), keep);
    c.appendChild(bar);
    box.appendChild(c);
    keep.focus();
    return;
  }
  const title = el('span', 'jo-wiz-place');
  Office.place(key, title);
  box.append(title, el('p', 'jo-wiz-intro', T('wizard.intro.' + id)));
  const body = el('div', 'jo-wiz-body');
  body.appendChild(stepBody(id));
  box.appendChild(body);
  const left = el('div', 'jo-wiz-left');
  left.appendChild(button(T('wizard.cancel'), 'plain', cancelWizard));
  if (i > 0) left.appendChild(button(T('wizard.back'), 'plain', () => goStep(SETUP_STEPS[i - 1].id)));
  const where = el('div', 'jo-wiz-where');
  where.appendChild(el('span', '', T('wizard.of', { n: i + 1, total: SETUP_STEPS.length })));
  const dots = el('span', 'jo-wiz-dots');
  dots.setAttribute('aria-hidden', 'true');
  SETUP_STEPS.forEach((_, k) => dots.appendChild(el('i', k === i ? 'on' : k < i ? 'done' : '')));
  where.appendChild(dots);
  const right = el('div', 'jo-wiz-right');
  // a step that can't go on says why, next to its button (the people by budget: someone would get too little)
  const why = el('span', 'role jo-wiz-why');
  right.appendChild(why);
  let go;
  if (id === 'summary') {
    const save = button(T('setup.save'), '', saveSetup);
    right.appendChild(go = save);
  } else {
    if (SKIPPABLE.has(id)) right.appendChild(button(T('wizard.skip'), 'plain', () => goStep(SETUP_STEPS[i + 1].id)));
    const next = button(T('wizard.next'), '', () => (id === 'servers' ? nextFromServers(next, SETUP_STEPS[i + 1].id) : goStep(SETUP_STEPS[i + 1].id)));
    right.appendChild(go = next);
  }
  wiz.gate = () => {
    const r = stepBlocked(id);
    why.textContent = r;
    why.hidden = !r;
    go.disabled = !!r || (id === 'summary' && (running() || !connected()));
  };
  wiz.gate();
  foot.append(left, where, right);
  if (wiz.dlg.fit) wiz.dlg.fit();          // a new step: the dialog inside the window again
  focusBack(spot);
}

/** The «Set up» tab once he is set up: the summary as saved, each row's «Change» opens the wizard at that step */
function summaryCard() {
  const card = el('div', 'box jo-wiz jo-setup-card');
  const title = el('h2', 'jo-wiz-title', T('wizard.summary'));
  Office.place('wizard.summary', title);
  card.append(title, el('p', 'jo-wiz-intro', T('wizard.inline_intro')), setupSummary(true));
  // quiet, at the bottom: from scratch (his settings aside, a copy kept; the pool and his list stay)
  const foot = el('div', 'toolbar jo-reset');
  const again = button(T('reset.button'), 'small plain danger', resetDialog);
  again.disabled = running();
  if (running()) foot.appendChild(el('span', 'role', T('errors.emby_running')));        // why it is grey
  foot.appendChild(again);
  card.appendChild(foot);
  return card;
}
/**
 * «Set up from scratch …» (the owner: really zero, no copy): what goes and what becomes of the files he placed on the pool
 * (his list's count and size — ordinary cache for Ms. Moverelli afterwards), then emby.reset and the wizard at step 1
 */
function resetDialog() {
  const c = state.cache || {};
  const box = el('div');
  box.appendChild(el('p', '', T('reset.text', { n: Number(c.files) || 0, size: fmt.size(Number(c.bytes) || 0) })));
  box.appendChild(el('p', '', T('reset.text_night')));
  Office.dialog({ title: T('reset.title'), body: box, buttons: [
    { text: Office.t('common.cancel') },
    { text: T('reset.go'), kind: 'danger', act: async () => {
      const j = await act('reset');
      if (!j) return false;
      form = null;
      renderSetup();
      setTimeout(() => openWizard('servers'), 0);      // after this dialog has closed (one dialog at a time)
      return true;
    } },
  ] });
}
/** … before: a short intro and «Start the setup» (the wizard at step 1) */
function introCard() {
  const card = el('div', 'box jo-wiz jo-setup-card');
  const go = button(T('wizard.start'), '', () => openWizard('servers'));
  const bar = el('div', 'toolbar');
  bar.appendChild(go);
  card.append(el('h2', 'jo-wiz-title', T('wizard.start_title')), el('p', 'jo-wiz-intro', T('wizard.start_text')), bar);
  return card;
}

/** One step's body */
function stepBody(id) {
  if (id === 'servers') return setupServers();
  if (id === 'users') return setupUsers();
  if (id === 'libraries') return setupLibraries();
  if (id === 'settings') return setupSettings();
  if (id === 'simulator') return setupSimulator();
  return setupSummary();
}

/** The people of all connected servers, each once */
const everyone = () => [...new Set(form.instances.flatMap((inst) => (inst.users || []).map((u) => u.id)))];
/** The way back and reading from the array as they come (EmbyCache's defaults) */
// (create_share_root has no field any more, 2026-10-14: saving writes it on as his settings have it — EmbyCache's default false)
const MORE_DEFAULTS = { return_to_origin: true, array_source: 'user0' };

/** A step that needs a connected server, before one is: one line saying so */
function notYet() {
  return el('p', 'role jo-not-yet', T('setup.after_connect', { where: T('wizard.title.servers') }));
}

/** The control that has the focus inside the card: its step and its place among the step's controls (drawn anew) */
function focusSpot() {
  const a = typeof document !== 'undefined' ? document.activeElement : null;
  const root = wiz ? wiz.box : view;       // inside the wizard's dialog while it is open
  const sec = a && root && root.contains(a) && a.closest ? a.closest('[data-section]') : null;
  if (!sec) return null;
  return { section: sec.dataset.section, i: [...sec.querySelectorAll('input, select, textarea, button')].indexOf(a) };
}
/** … and back to the same control once drawn anew (no scroll: the page stays where it was) */
function focusBack(spot) {
  const root = wiz ? wiz.box : view;
  if (!spot || spot.i < 0 || !root) return;
  const sec = root.dataset.section === spot.section ? root : root.querySelector(`[data-section="${spot.section}"]`);
  const x = sec ? sec.querySelectorAll('input, select, textarea, button')[spot.i] : null;
  if (x && document.activeElement !== x) x.focus({ preventScroll: true });
}

// ---- the pool (2026-10-14, the owner): never chosen here — Unraid's share settings decide it: the primary storage of the
// chosen libraries' shares (shareCachePool, the array as secondary). EmbyCache has ONE cache_path: shares on several
// pools → the user picks the pool in step 3, the folders on the others are left out; a share without a pool as its
// primary (array only, a pool as secondary) can't be cached and is left out.
// (2026-10-14, the owner: either the share is set up right, or the user fixes it in Unraid — the office says what and how)
/**
 * How a share is set up for him, from its cfg (shareUseCache, shareCachePool, shareCachePool2): 'ok' = a pool as primary,
 * the array as secondary, the mover from the pool to the array («yes»); else what is wrong — 'unknown' (no such share),
 * 'no_pool' (the array as primary), 'no_array' (another pool as secondary), 'wrong_way' (the mover from the array to the
 * pool: «prefer»), 'pool_only' (a pool without a secondary: «only»)
 */
function shareCase(share) {
  const info = state.share_info[share];
  if (!info) return 'unknown';
  const use = String(info.use || '').toLowerCase();
  if (!['yes', 'prefer', 'only'].includes(use) || !state.pools.includes('/mnt/' + info.primary)) return 'no_pool';
  if (info.secondary) return 'no_array';
  if (use === 'prefer') return 'wrong_way';
  if (use === 'only') return 'pool_only';
  return 'ok';
}
/** A share EmbyCache can cache: set up right (shareCase) */
const cacheable = (share) => shareCase(share) === 'ok';
/** The chosen libraries' folders that are ticked: [{lib, i, p, share}] */
function chosenFolders() {
  return libraryList().filter((l) => form.chosenLibs.has(l.name))
    .flatMap((l) => l.at.filter(([i, p]) => !form.instances[i].skip.has(p)).map(([i, p]) => ({ lib: l.name, i, p, share: shareOf(form.instances[i].mappings[p]) })));
}
/** The pools the chosen folders' shares have as primary: Map pool name → [shares] (only cacheable shares) */
function poolsOfChoice() {
  const out = new Map();
  for (const f of chosenFolders()) {
    if (!f.share || !cacheable(f.share)) continue;
    const pool = state.share_info[f.share].primary;
    if (!out.has(pool)) out.set(pool, []);
    if (!out.get(pool).includes(f.share)) out.get(pool).push(f.share);
  }
  return out;
}
/** The pool EmbyCache gets (/mnt/<pool>): the one the shares point to, or the one picked among several; '' none yet */
function derivedPool() {
  const pools = poolsOfChoice();
  if (pools.size === 1) return '/mnt/' + [...pools.keys()][0];
  const picked = form.values.cache_path.replace('/mnt/', '');
  return pools.size > 1 && pools.has(picked) ? '/mnt/' + picked : '';
}

/** 1. the media server and its connection — and, before he is set up, the offer to take over an earlier install */
function setupServers() {
  const s1 = el('div', 'jo-wiz-part');
  s1.appendChild(mediaChoice());
  form.instances.forEach((inst, idx) => {
    const box = el('div', 'box jo-instance');
    const f1 = el('div', 'jo-form');
    const name = input(inst.servername, (v) => { inst.servername = v; });
    const url = input(inst.url, (v) => { inst.url = v.trim(); });
    const key = input('', (v) => { inst.api_key = v.trim(); });
    key.type = 'password';
    key.autocomplete = 'off';
    key.placeholder = inst.has_key ? T('setup.key_kept') : T('setup.key_placeholder');
    f1.append(field(T('setup.name'), name), field(T('setup.url'), url, T('setup.url_hint')), field(T('setup.key'), key, T('setup.key_hint')));
    box.appendChild(f1);
    const bar = el('div', 'toolbar');
    const connect = button(T('setup.connect'), inst.server ? 'small plain' : 'small', async () => {
      connect.disabled = true;
      await connectInst(inst);
      connect.disabled = false;
      renderSetup();
    });
    bar.appendChild(connect);
    if (inst.server) bar.appendChild(chip(T('setup.connected', { name: inst.server.name, version: inst.server.version }), 'ok'));
    if (inst.connectError) box.appendChild(el('p', 'callout danger jo-connect-error', inst.connectError));
    if (form.instances.length > 1) bar.appendChild(button(T('setup.remove_server'), 'small plain', () => { form.instances.splice(idx, 1); renderSetup(); }));
    box.appendChild(bar);
    s1.appendChild(box);
  });
  const add = button(T('setup.add_server'), 'small plain', () => {
    form.instances.push({ servername: `Emby${form.instances.length + 1}`, url: 'http://', api_key: '', has_key: false, mappings: {}, skip: new Set(), server: null, libraries: [], users: [] });
    renderSetup();
  });
  const bar1 = el('div', 'toolbar');
  bar1.appendChild(add);
  s1.appendChild(bar1);
  if (!connected()) s1.appendChild(el('p', 'callout', T('setup.connect_first')));
  // someone who ran helmi1987's tools before: the offer (only while he isn't set up — afterwards it's in the ⋯ menu)
  if (!state.configured) {
    const offer = el('div', 'callout jo-wiz-offer');
    offer.append(el('p', '', T('import.sub')), button(T('import.open'), 'small plain', importDialog));
    s1.appendChild(offer);
  }
  return s1;
}

/** Where the media servers' logos come from: the same icon set as Ms. Dustdevil's container pictures (agent/desks/cleanup.php
 * CL_ICON_CDN, homarr-labs/dashboard-icons) — loaded by the browser at runtime, never shipped with the office */
const MEDIA_LOGO_CDN = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/';
/** A media server's logo, its round monogram underneath (it shows when the picture can't load — offline) */
function mediaLogo(name) {
  const box = el('span', 'jo-media-logo m-' + name.toLowerCase(), name.charAt(0));
  box.setAttribute('aria-hidden', 'true');
  const img = el('img');
  img.alt = '';
  img.decoding = 'async';
  img.referrerPolicy = 'no-referrer';
  img.onload = () => box.classList.add('has-img');
  img.onerror = () => img.remove();
  img.src = MEDIA_LOGO_CDN + name.toLowerCase() + '.png';
  box.appendChild(img);
  return box;
}

/** What a connection was made with (address and key): «Next ›» connects again only when these changed */
const connectKey = (inst) => `${String(inst.url || '').trim()}|${inst.api_key || ''}`;
/** A server to connect before going on: an address and a key (typed, or kept on the server), not connected with them yet */
const needsConnect = (inst) => !!String(inst.url || '').trim().replace(/^https?:\/\/$/, '') && !!(inst.api_key || inst.has_key)
  && (!inst.server || inst.connectedAs !== connectKey(inst));
/** Connect one server (agent: emby.connect): its libraries and people in the form — or the error said in its box */
async function connectInst(inst) {
  const j = await Office.api.post(`${ID}.connect`, { url: inst.url, api_key: inst.api_key });
  if (!j || !j.ok) {
    inst.connectError = Office.errorText(j && j.error, ID);
    return false;
  }
  inst.connectError = '';
  inst.server = j.server;
  inst.libraries = j.libraries;
  inst.users = j.users;
  inst.connectedAs = connectKey(inst);
  for (const lib of j.libraries) for (const p of lib.locations) if (!inst.mappings[p]) inst.mappings[p] = suggestMapping(p);
  if (!state.configured && !form.chosenLibs.size) suggestChoice(inst);
  return true;
}
/**
 * «Next ›» on step 1: an address and a key filled in but not connected (or changed since) — it connects first, the button
 * busy; on success on to step 2, on a failure it stays and says why in the server's box
 */
async function nextFromServers(btn, nextId) {
  const todo = form.instances.filter(needsConnect);
  if (!todo.length) { goStep(nextId); return; }
  btn.disabled = true;
  btn.appendChild(el('span', 'spin'));
  let ok = true;
  for (const inst of todo) ok = (await connectInst(inst)) && ok;
  if (!wiz) return;
  if (ok) goStep(nextId);
  else drawWizard();
}

/**
 * The media server: Emby (what he works with), Plex and Jellyfin coming soon (greyed, not choosable). The names are the
 * products' own (not translated).
 */
function mediaChoice() {
  const box = el('div', 'jo-media');
  box.appendChild(el('div', 'jo-media-head', T('wizard.media')));
  const row = el('div', 'jo-media-row');
  [['Emby', true], ['Plex', false], ['Jellyfin', false]].forEach(([name, works]) => {
    const card = el('div', 'box jo-media-card' + (works ? ' on' : ' off'));
    card.setAttribute('aria-disabled', works ? 'false' : 'true');
    const who = el('span', 'jo-media-name');
    who.append(mediaLogo(name), el('b', '', name));
    card.append(who, chip(T(works ? 'wizard.media_ok' : 'wizard.soon'), works ? 'ok' : ''));
    row.appendChild(card);
  });
  box.append(row, el('p', 'role', T('wizard.media_sub')));
  return box;
}

/** 4. people — by count only who (the numbers of step 3 apply to everyone), by budget also a fixed budget per person */
function setupUsers() {
  if (!connected()) return notYet();
  const v = form.values;
  const s3 = el('div', 'jo-wiz-part');
  const people = everyone();
  if (people.length) {
    const pick = el('div', 'toolbar');
    pick.append(button(T('setup.users_all'), 'small plain', () => { people.forEach((id) => form.chosenUsers.add(id)); renderSetup(); }),
      button(T('setup.users_none'), 'small plain', () => { form.chosenUsers.clear(); renderSetup(); }));
    s3.appendChild(pick);
  }
  s3.appendChild(el('p', 'role', T('setup.users_sub')));
  const f3 = el('div', 'jo-users');
  const seen = new Set();
  let line = null;
  form.instances.forEach((inst) => (inst.users || []).forEach((u) => {
    if (seen.has(u.id)) return;
    seen.add(u.id);
    const wrap = el('div');
    const extra = [u.admin ? T('setup.admin') : '', form.instances.length > 1 ? inst.servername : ''].filter(Boolean).join(' · ');
    wrap.appendChild(check(u.name, form.chosenUsers.has(u.id), (on) => { if (on) form.chosenUsers.add(u.id); else form.chosenUsers.delete(u.id); renderSetup(); }, extra));
    // by budget: an optional fixed budget per chosen person in GB (EmbyCache's valid_users {id: {budget: "300G"}})
    if (v.budget_mode && form.chosenUsers.has(u.id)) {
      const b = el('input', 'input');
      b.type = 'number';
      b.min = 0;
      b.step = 1;
      const now = budgetGb(form.budgets[u.id]);
      b.value = now > 0 ? String(now) : '';
      b.placeholder = T('setup.user_budget_gb_hint');
      b.oninput = () => {
        const n = Number(b.value);
        if (b.value.trim() === '' || !(n > 0)) delete form.budgets[u.id]; else form.budgets[u.id] = `${n}G`;
        if (line) line.replaceWith(line = budgetLine());
        if (wiz && wiz.gate) wiz.gate();          // «Next ›» blocked or free again, with its reason
      };
      wrap.appendChild(field(T('setup.user_budget_gb'), b));
    }
    f3.appendChild(wrap);
  }));
  s3.appendChild(f3);
  // by count the numbers of step 3 apply to everyone (EmbyCache has none per person); by budget the split, live
  if (!v.budget_mode) s3.appendChild(el('p', 'callout jo-slice', T('setup.users_count_all', { n: Number(v.number_episodes) || 0 })));
  else s3.appendChild(line = budgetLine());
  return s3;
}

/** By budget every chosen person can get at least this much (2026-10-14, the owner): a fixed budget, and the even share of the rest */
const MIN_PERSON_GB = 20;
/**
 * How the budget splits (pure): fixed budgets of the chosen people first, the rest evenly on the others — what someone
 * doesn't need flows to the others (EmbyCache's apply_budget). total: GB; fixed: [GB …] of the people with one; others: how
 * many go without one. Red (bad) when the fixed ones exceed the whole (over), a fixed one is under MIN_PERSON_GB (low: how
 * many), or the rest gives the others under MIN_PERSON_GB each (short; each = their even share).
 */
function budgetSplit(total, fixed, others) {
  const sumFixed = fixed.reduce((a, b) => a + b, 0);
  const rest = Math.max(0, total - sumFixed);
  const each = others > 0 ? rest / others : 0;
  const over = sumFixed > total;
  const low = fixed.filter((x) => x < MIN_PERSON_GB).length;
  const short = others > 0 && each < MIN_PERSON_GB;
  return { total, fixed: sumFixed, rest, others, over, each, low, short, bad: over || low > 0 || short };
}
/** The split of the form now (by budget; null by count): the chosen people (none: everyone), their fixed budgets, the low ones' names */
function budgetState() {
  if (!form || !form.values.budget_mode) return null;
  const chosen = form.chosenUsers.size ? everyone().filter((id) => form.chosenUsers.has(id)) : everyone();
  const own = chosen.filter((id) => budgetGb(form.budgets[id]) > 0);
  const sp = budgetSplit(budgetGb(form.values.cache_budget), own.map((id) => budgetGb(form.budgets[id])), chosen.length - own.length);
  const names = new Map(form.instances.flatMap((inst) => (inst.users || []).map((u) => [u.id, u.name])));
  sp.lowNames = own.filter((id) => budgetGb(form.budgets[id]) < MIN_PERSON_GB).map((id) => names.get(id) || id);
  return sp;
}
/** Why «Next ›» (the people) or «Save» (the summary) can't go on now, '' when they can: by budget a person would get under MIN_PERSON_GB */
function stepBlocked(id) {
  if (!['users', 'summary'].includes(id) || !connected()) return '';
  const sp = budgetState();
  return sp && sp.bad ? T('setup.users_budget_block', { step: T('wizard.title.users'), min: gbSize(MIN_PERSON_GB) }) : '';
}
const gbSize = (gb) => fmt.size(gb * 1024 * 1024 * 1024);
/** The people's line by budget, live: red with the reason and what to do while someone would get too little, else the split */
function budgetLine() {
  const sp = budgetState();
  const box = el('div', 'jo-budget');
  const red = (text) => box.appendChild(el('p', 'callout warn jo-slice', text));
  const min = gbSize(MIN_PERSON_GB);
  if (sp.over) red(T('setup.users_budget_over', { fixed: gbSize(sp.fixed), total: gbSize(sp.total) }));
  if (sp.low) red(T('setup.users_budget_low', { names: sp.lowNames.join(', '), min }));
  if (sp.short && !sp.over) red(T('setup.users_budget_short', { n: sp.others, rest: gbSize(sp.rest), min, need: gbSize(MIN_PERSON_GB * sp.others) }));
  // everyone chosen has a budget of their own: EmbyCache's apply_budget shares the rest only among people without one —
  // nobody gets more than their own budget, the rest stays unused (2026-10-14)
  if (!sp.bad && !sp.others) box.appendChild(el('p', 'callout jo-slice', sp.rest > 0 ? T('setup.users_budget_all_fixed_rest', { fixed: gbSize(sp.fixed), rest: gbSize(sp.rest) })
    : T('setup.users_budget_all_fixed', { fixed: gbSize(sp.fixed) })));
  else if (!sp.bad) box.appendChild(el('p', 'callout jo-slice', T('setup.users_budget_split', { fixed: gbSize(sp.fixed), rest: gbSize(sp.rest), n: sp.others })));
  return box;
}

/** 2. the libraries and their folders on the pool — each share with its primary pool (read only, Unraid's share settings) */
function setupLibraries() {
  if (!connected()) return notYet();
  const s2 = el('div', 'jo-wiz-part');
  s2.appendChild(el('p', 'role', T('setup.libraries_sub')));
  const box2 = el('div', 'box');
  const cache = derivedPool();
  libraryList().forEach((lib) => {
    const row = el('div', 'row nocheck jo-lib');
    const main = el('div', 'row-main');
    main.appendChild(check(lib.name, form.chosenLibs.has(lib.name), (on) => {
      if (on) { form.chosenLibs.add(lib.name); lib.at.forEach(([i, p]) => form.instances[i].skip.delete(p)); } else form.chosenLibs.delete(lib.name);
      renderSetup();
    },
      T('type.' + (lib.type || 'other'))));
    if (form.chosenLibs.has(lib.name)) {
      lib.at.forEach(([i, p]) => {
        const inst = form.instances[i];
        const share = shareOf(inst.mappings[p]);
        const fit = share ? shareCase(share) : 'ok';            // no share yet: the path field says what's missing
        const on = !inst.skip.has(p) && fit === 'ok';
        const label = form.instances.length > 1 ? T('setup.map_on', { server: inst.servername, path: p }) : T('setup.map', { path: p });
        if (fit !== 'ok') {
          // a share that isn't set up right can't be cached: unticked and greyed, why below — fixed in Unraid, not here
          const c = check(T('setup.cache_folder', { path: p }), false, () => {}, T(fit === 'unknown' ? 'setup.cache_folder_noshare' : 'setup.cache_folder_unfit', { share }));
          c.querySelector('input').disabled = true;
          c.classList.add('jo-folder', 'jo-muted');
          main.appendChild(c);
        } else if (lib.at.length > 1 || !on) {
          const c = check(T('setup.cache_folder', { path: p }), on, (x) => { if (x) inst.skip.delete(p); else inst.skip.add(p); renderSetup(); },
            on ? '' : T('setup.cache_folder_off'));
          c.classList.add('jo-folder');
          main.appendChild(c);
        }
        if (!on && fit === 'ok') return;
        const inp = input(inst.mappings[p] || '', (x) => { inst.mappings[p] = x.trim(); });
        inp.classList.add('mono');
        inp.addEventListener('change', () => renderSetup());       // a new share: its pool anew
        const f = field(label, inp);
        if (share) {
          const info = state.share_info[share] || {};
          const meta = el('div', 'row-meta');
          if (fit !== 'ok') {
            const params = { share, pool: info.primary || '–', secondary: info.secondary || '–' };
            meta.appendChild(chip(T('wizard.share.' + fit), 'danger', T('wizard.share_tip')));
            f.appendChild(meta);
            f.appendChild(el('p', 'role jo-fix', T('wizard.fix.' + fit, params)));
          } else {
            const other = cache && '/mnt/' + info.primary !== cache;
            meta.appendChild(chip(T('wizard.pool_chip', { pool: info.primary }), other ? 'warn' : 'ok', other ? T('wizard.pool_other', { pool: cache.replace('/mnt/', '') }) : T('wizard.share_ok_tip', { share, pool: info.primary })));
            // the share is right, its folder isn't on the pool right now: made at the first laying out (later), no fault
            if (!other && !(state.pool_dirs['/mnt/' + info.primary] || []).includes(share)) {
              meta.appendChild(chip(T('wizard.share_dir_later'), 'accent', T('wizard.share_dir_later_tip', { share, pool: '/mnt/' + info.primary })));
            }
            f.appendChild(meta);
          }
        }
        main.appendChild(f);
      });
    }
    row.appendChild(main);
    box2.appendChild(row);
  });
  s2.appendChild(box2);
  // EmbyCache has one pool: shares on several — the user picks which pool; the folders on the others are left out
  const pools = poolsOfChoice();
  if (pools.size > 1) {
    const pick = el('div', 'callout warn jo-wiz-pools');
    pick.appendChild(el('p', '', T('wizard.pools_many', { n: pools.size })));
    for (const [pool, shares] of pools) {
      const l = el('label', 'check');
      const r = el('input');
      r.type = 'radio';
      r.name = 'jo-wiz-pool';
      r.checked = cache === '/mnt/' + pool;
      r.onchange = () => { form.values.cache_path = '/mnt/' + pool; renderSetup(); };
      l.append(r, el('span', '', T('wizard.pool_of', { pool, shares: shares.join(', ') })));
      pick.appendChild(l);
    }
    s2.appendChild(pick);
  } else if (!pools.size && chosenFolders().length) s2.appendChild(el('p', 'callout warn', T('wizard.pool_none')));
  return s2;
}

/**
 * 3. Settings: the pool (read only — from step 2), the amount (films and series), sharing the pool (Ms. Moverelli's values
 * read only, «not watched for N days», the rule in words) and, folded, «Advanced» (the way back, reading from the array)
 */
function setupSettings() {
  if (!connected()) return notYet();
  const v = form.values;
  const s4 = el('div', 'jo-wiz-part');
  const f4 = el('div', 'jo-form');
  const cache = derivedPool();
  const pools = poolsOfChoice();
  const kv = el('dl', 'kv jo-share-kv');
  kv.append(el('dt', '', T('setup.pool')), el('dd', '', cache ? T('wizard.pool_from', { pool: cache.replace('/mnt/', ''), shares: (pools.get(cache.replace('/mnt/', '')) || []).join(', ') })
    : T('wizard.pool_none')));
  f4.appendChild(kv);
  // how much room he has on the pool is Ms. Moverelli's to say (her limit): his own «leave N % free» field is gone
  roomWords().forEach((line, i) => f4.appendChild(el('p', i ? 'callout warn jo-slice' : 'callout jo-slice', line)));
  const mode = el('select', 'picker');
  mode.append(new Option(T('setup.mode_count'), 'count'), new Option(T('setup.mode_budget'), 'budget'));
  mode.value = v.budget_mode ? 'budget' : 'count';
  mode.onchange = () => { v.budget_mode = mode.value === 'budget'; renderSetup(); };
  // the two ways in the owner's words (2026-10-14), each mode's name bold — also the options' tooltips
  const ways = [['setup.mode_count', T('setup.mode_help_count', { n: Number(v.number_episodes) || 0 })], ['setup.mode_budget', T('setup.mode_help_budget')]];
  [...mode.options].forEach((o, i) => { o.title = ways[i][1]; });
  const modeField = field(T('setup.mode'), mode);
  const help = el('small', 'jo-mode-help');
  ways.forEach(([key, text]) => {
    const line = el('span', 'jo-mode-line');
    line.append(el('b', '', T(key) + ':'), ' ' + text);
    help.appendChild(line);
  });
  modeField.appendChild(help);
  f4.appendChild(modeField);
  if (v.budget_mode) f4.appendChild(field(T('setup.budget'), input(v.cache_budget, (x) => { v.cache_budget = x.trim(); }), T('setup.budget_hint')));
  s4.appendChild(f4);

  const chosenTypes = new Set(libraryList().filter((l) => form.chosenLibs.has(l.name)).map((l) => l.type));
  // a part whose kind of library isn't chosen is greyed out, with the reason (its values stay as they are)
  const part = (title, type, ...fields) => {
    const used = chosenTypes.has(type);
    const box = el('div', 'box jo-part' + (used ? '' : ' off'));
    const head = el('div', 'jo-part-head', title);
    if (!used) head.appendChild(el('span', 'jo-part-why', T('setup.part_unused', { kind: title })));
    box.appendChild(head);
    const f = el('div', 'jo-form');
    f.append(...fields);
    if (!used) f.querySelectorAll('input, select').forEach((x) => { x.disabled = true; });
    box.appendChild(f);
    s4.appendChild(box);
  };
  part(T('type.movies'), 'movies',
    ...(v.budget_mode ? [field(T('setup.movie_share'), number(v, 'movie_share_percent', 0, 100), T('setup.movie_share_hint'))] : []),
    field(T('setup.resume_movies'), number(v, 'max_resume_movies', 0, 999), T('setup.resume_movies_hint')),
    el('p', 'role', T('setup.movies_folder')));
  part(T('type.tvshows'), 'tvshows',
    v.budget_mode ? field(T('setup.max_per_series'), number(v, 'max_episodes_per_series', 0, 999), T('setup.zero_budget'))
                  : field(T('setup.episodes'), number(v, 'number_episodes', 0, 99), T('setup.episodes_hint')),
    field(T('setup.resume_series'), number(v, 'max_resume_series', 0, 999), T('setup.resume_series_hint')),
    field(T('setup.favorites'), number(v, 'max_favorite_series', 0, 999), T('setup.zero_off')),
    check(T('setup.next_up'), v.use_next_up, (x) => { v.use_next_up = x; }, T('setup.next_up_hint')));
  s4.appendChild(sharePart());
  s4.appendChild(advancedPart());
  return s4;
}

/**
 * Sharing the pool (2026-10-14): Ms. Moverelli's limits as she has them (global per pool, her desk sets them — shown, not
 * asked here), the new «not watched for … days», the rule in words — with numbers once the pool is measured (streamer.js's
 * math, the same as the simulator's)
 */
function sharePart() {
  const v = form.values;
  const box = el('div', 'box jo-part');
  box.appendChild(el('div', 'jo-part-head', T('share.title')));
  const f = el('div', 'jo-form');
  const pool = state.pool || {};
  const mo = herRules();
  if (mo && mo.x > 0) {
    const kv = el('dl', 'kv jo-share-kv');
    const add = (key, value) => kv.append(el('dt', '', T(key)), el('dd', '', value));
    add('share.x', `${mo.x} %`);
    add('share.offset', `${mo.x - mo.y} % (Y = ${mo.y} %)`);
    add('share.k', `${mo.k ?? 50} %`);
    add('share.age', mo.min_age == null ? '–' : nOf('days', mo.min_age));
    f.appendChild(kv);
    const her = el('p', 'role');
    // her desk sets these (global per pool): the wizard ends without asking (the owner) — said on her page when it held changes
    her.appendChild(link(T('share.her_link'), () => {
      const lost = changed();
      const w = wiz;
      wiz = null;
      form = null;
      if (w) w.dlg.close();
      Office.go('#/moverelli/setup/global');
      if (lost) Office.toast(T('wizard.left_unsaved'));
    }));
    f.appendChild(her);
  } else {
    f.appendChild(el('p', 'callout jo-slice', T(mo ? 'room.own' : 'room.none', { min: pool.min_free ?? '?' })));
  }
  f.appendChild(field(T('share.days'), number(v, 'give_back_unwatched_days', 1, 365), T('share.days_hint')));
  shareWords().forEach((line) => f.appendChild(el('p', 'role jo-slice', line)));
  box.appendChild(f);
  return box;
}
/** Ms. Moverelli's rules: her own (state.moverelli — there without his settings too), else as his pool reported them */
const herRules = () => (state && (state.moverelli || (state.pool && state.pool.moverelli))) || null;
/** The borrowing rule in words: always the rule, and the numbers when the pool is measured and the simulator's math is here */
function shareWords() {
  const v = form.values;
  const pool = state.pool || {};
  const mo = herRules();
  const out = [T('share.rule_back', { n: Number(v.give_back_unwatched_days) || 0 })];
  if (!mo || !(mo.x > 0)) return out;
  const lv = pool.level;
  const m = lv && Office.streamerMath ? Office.streamerMath.streamerPoolMath({ size: lv.size, nm: lv.nm, jack: lv.jack, others: lv.others,
    x: mo.x, offset: mo.x - mo.y, k: mo.k ?? 50 }) : null;
  if (!m) {
    out.unshift(T('share.rule', { y: mo.y }));
    if (!lv) out.push(T('share.unmeasured'));
    return out;
  }
  out.unshift(T('share.rule_now', { y: mo.y, room: fmt.size(m.room), h: fmt.size(m.half), d: fmt.size(m.halfD),
    limit: fmt.size(m.jackLimit), held: fmt.size(m.jack), others: fmt.size(m.others) }));
  return out;
}

/** «Advanced», folded: the way back and where to read from (both ways always rsync: Unraid's move binary is never his choice) */
const folds = new Map();
function keepFold(det, key, open) {
  det.open = folds.has(key) ? folds.get(key) : open;
  let shown = det.open;
  det.addEventListener('toggle', () => {
    if (det.open === shown) return;          // the page setting it (toggle comes later, for the open it was built with)
    shown = det.open;
    folds.set(key, det.open);
  });
  return det;
}
function advancedPart() {
  const v = form.values;
  const det = keepFold(el('details', 'box jo-part jo-adv'), 'advanced', false);
  det.appendChild(el('summary', 'jo-part-head', T('wizard.advanced')));
  const f5 = el('div', 'jo-form');
  f5.appendChild(el('p', 'role', T('setup.more_sub')));
  f5.appendChild(check(T('setup.origin'), v.return_to_origin, (x) => { v.return_to_origin = x; }, T('setup.origin_hint')));
  f5.appendChild(field(T('setup.array_source'), select(v, 'array_source', [['user0', T('setup.source_user0')], ['disk', T('setup.source_disk')]]), T('setup.array_source_hint')));
  f5.appendChild(el('p', 'role', T('setup.fixed_paths')));
  det.appendChild(f5);
  return det;
}

/** 5. the pool simulator (streamer.js, loaded once on first need; until then the wave) */
function setupSimulator() {
  if (Office.streamerSim) {
    return Office.streamerSim.view({ T, el, fmt, nOf, button, section: (title, sub) => { const d = el('div', 'jo-wiz-part'); d.appendChild(el('p', 'role', sub)); return d; },
      base: simBase,
      measure: async () => {
        const j = await Office.api.post(`${ID}.pool_measure`, { pool: form && connected() ? derivedPool() : '' });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return null; }
        if (!j.level) { Office.toast(T('sim.unmeasured'), true); return false; }      // measured, but no number: asleep or no ZFS
        return j.level;
      } });
  }
  loadStreamer();
  const s = el('div', 'jo-wiz-part');
  s.appendChild(el('span', 'spin'));
  return s;
}
/** What the simulator starts from: his form's N days, Ms. Moverelli's limits, the pool as measured (bytes) — or less */
function simBase() {
  const pool = (state && state.pool) || {};
  const mo = herRules() || {};
  const v = form ? form.values : {};
  const chosen = form && connected() ? (form.chosenUsers.size ? everyone().filter((id) => form.chosenUsers.has(id)) : everyone()) : [];
  const userBudgets = v.budget_mode ? chosen.map((id) => budgetGb(form.budgets[id]) || 0) : [];
  return { days: form ? v.give_back_unwatched_days : UNWATCHED_DAYS, x: mo.x || 0, y: mo.y || 0, k: mo.k, age: mo.min_age, level: pool.level || null,
    budget: v.budget_mode ? budgetGb(v.cache_budget) : 0, userBudgets: userBudgets.some((x) => x > 0) ? userBudgets : null };
}
/** His budget («500G», «1.5T», «800 GB») in GB — 0 when none can be read */
function budgetGb(text) {
  const m = /^(\d+(?:\.\d+)?)\s*([KMGTP])?B?$/i.exec(String(text || '').trim());
  if (!m) return 0;
  const f = { K: 1 / (1024 * 1024), M: 1 / 1024, G: 1, T: 1024, P: 1024 * 1024 }[(m[2] || 'G').toUpperCase()];
  return Math.round(Number(m[1]) * f * 10) / 10;
}
function loadStreamer() {
  if (Office.streamerSim || !SELF || Office.streamerLoaded) return;
  Office.streamerLoaded = () => { if (view && page === 'setup') renderSetup(); };
  const sc = document.createElement('script');
  sc.src = SELF.replace(/desk\.js(\?[^#]*)?$/, 'streamer.js$1');
  (document.head || document.body).appendChild(sc);
}

/**
 * The summary, pure (no DOM): [{id, key, value}] per step before it — what saving writes, said short (the summary's rows,
 * each with «Change»)
 */
function summaryData() {
  const v = form.values;
  const on = connected();
  const people = everyone().filter((id) => form.chosenUsers.has(id));
  const names = new Map(form.instances.flatMap((inst) => (inst.users || []).map((u) => [u.id, u.name])));
  const libs = on ? libraryList().filter((l) => form.chosenLibs.has(l.name)).map((l) => l.name) : [...form.chosenLibs];
  const cache = on ? derivedPool() : v.cache_path;
  const changed = Object.keys(MORE_DEFAULTS).some((k) => v[k] !== MORE_DEFAULTS[k]);
  return [
    { id: 'servers', key: 'wizard.title.servers', value: form.instances.map((i) => (i.server ? `${i.servername} (${i.server.name})` : i.servername)).join(', ') },
    { id: 'libraries', key: 'wizard.title.libraries', value: libs.join(', ') },
    { id: 'settings', key: 'wizard.title.settings', value: [cache ? cache.replace('/mnt/', '') : '–',
      v.budget_mode ? `${T('setup.mode_budget')} ${v.cache_budget || '–'}` : T('setup.tile.count_line', { n: Number(v.number_episodes) || 0 }),
      T('wizard.sum_days', { days: nOf('days', v.give_back_unwatched_days) }), T(changed ? 'setup.tile.changed' : 'setup.tile.default')].join(' · ') },
    { id: 'users', key: 'wizard.title.users', value: peopleWords(on ? people : [...form.chosenUsers], names, v.budget_mode ? form.budgets : null) },
    { id: 'simulator', key: 'sim.title', value: T('setup.tile.optional') },
  ];
}

/**
 * The people chosen, in words — never their ids (Emby's are 32 hex digits): the names when they are known (a server is
 * connected), else how many; none chosen: everyone
 */
function peopleWords(ids, names, budgets) {
  if (!ids.length) return T('setup.tile.everyone');
  // by budget a fixed budget beside the name (when there is one)
  const gb = (id) => (budgets && budgetGb(budgets[id]) > 0 ? ` (${fmt.size(budgetGb(budgets[id]) * 1024 * 1024 * 1024)})` : '');
  return ids.every((id) => names.has(id)) ? ids.map((id) => names.get(id) + gb(id)).join(', ') : nOf('people', ids.length);
}

/** 6. the summary: a row per step with «Change» (jumps into that step), then «Save» (in the card's foot) */
function setupSummary(inline) {
  const s = el('div', 'jo-wiz-part');
  const list = el('div', 'jo-sum');
  summaryData().forEach((d) => {
    const row = el('div', 'jo-sum-row');
    const text = el('div', 'jo-sum-text');
    text.append(el('b', '', T(d.key)), el('span', '', d.value || '–'));
    row.append(text, button(T(d.id === 'simulator' ? 'wizard.test' : 'wizard.change'), 'small plain', () => (wiz ? goStep(d.id) : openWizard(d.id))));
    list.appendChild(row);
  });
  s.appendChild(list);
  if (inline) return s;                  // the tab: his setup as saved — saving is the wizard's
  if (!connected()) s.appendChild(notYet());
  else s.appendChild(el('p', 'role', T(running() ? 'setup.save_running' : 'setup.save_hint')));
  return s;
}

// ------------------------------------------------------------------ taking over an earlier install
/*
 * Someone who ran helmi1987's EmbyCache or «Consolidate folders» before (from a User Script)
 * types the folder of the old files; the agent reads them on the server (the API key never comes
 * here), the preview says what would change, the import needs its token. Afterwards: switch the
 * old User Script off yourself, start with a dry run, the copies of Jack's files are named.
 */
const importFolders = { embycache: '', gather: '' };

function importDialog() {
  const box = el('div');
  box.appendChild(el('p', '', T('import.intro')));
  const f = el('div', 'jo-form');
  const a = input(importFolders.embycache, (x) => { importFolders.embycache = x; });
  a.placeholder = '/mnt/user/system/scripts/embycache';
  const b = input(importFolders.gather, (x) => { importFolders.gather = x; });
  b.placeholder = '/mnt/user/system/scripts/consolidate';
  f.append(field(T('import.folder_embycache'), a, T('import.folder_embycache_hint')), field(T('import.folder_gather'), b, T('import.folder_gather_hint')));
  const err = errorLine();
  box.append(f, el('p', 'role', T('import.read_only')), err);
  Office.dialog({ title: T('import.title'), body: box, wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('import.look'), kind: '', act: () => importLook(err) }] });
}

async function importLook(err) {
  const folders = { embycache: importFolders.embycache.trim(), gather: importFolders.gather.trim() };
  const j = await Office.api.post(`${ID}.import_preview`, folders);
  if (!j.ok) { showError(err, j.error); return false; }
  importPreview(j.preview, folders);
  return true;
}

/** A value of the old or new settings, plainly */
function importValue(v) {
  if (v === null || v === undefined) return '–';
  if (v === '') return T('import.empty');
  if (Array.isArray(v)) return v.length ? v.join(' ') : T('import.empty');
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

/** A short list: the key (mono) and a text beside it */
function importList(items) {
  const ul = el('ul', 'shortlist');
  items.forEach(([k, text, cls]) => { const li = el('li', cls || '', k); if (text !== undefined) li.appendChild(el('span', '', text)); ul.appendChild(li); });
  return ul;
}

function importHead(text) { return el('p', 'jo-imp-head', text); }

/** What changes, what gets defaults, what Jack sets himself, what's left out — the same for both tools */
function importLists(box, part, sameText) {
  if (part.changes.length) {
    box.append(importHead(T('import.changes')), importList(part.changes.map((c) => [c.key, T('import.old_new', { old: importValue(c.old), new: importValue(c.new) })])));
  }
  if (sameText) box.appendChild(el('p', 'role', sameText));
  if (part.defaults && part.defaults.length) {
    box.append(importHead(T('import.defaults')), importList(part.defaults.map((d) => [d.key,
      T(d.kept ? 'import.kept' : 'import.jack_default', { value: importValue(d.value) })])));
  }
  if (part.jack.length) {
    box.append(importHead(T('import.jack')), el('p', 'role', T('import.jack_sub')),
      importList(part.jack.map((c) => [c.key, T('import.old_new', { old: importValue(c.old), new: importValue(c.new) })])));
  }
  if (part.dropped.length) box.appendChild(el('p', 'role', T('import.dropped', { keys: part.dropped.join(', ') })));
}

function importEmbyCache(p) {
  const box = el('div', 'jo-imp');
  const files = Object.entries(p.found).filter(([, on]) => on).map(([k]) => ({ settings: 'embycache_settings.json', exclude: 'embycache_exclude.txt', origin: 'embycache_origin.json' })[k]);
  box.appendChild(el('p', 'role', files.length ? T('import.found', { files: files.join(', ') }) : T('import.found_none')));
  if (p.blockers.length) box.appendChild(importList(p.blockers.map((b) => [T('import.block.' + b.key, b.params || {}), undefined, 'error'])));
  p.instances.forEach((inst) => {
    const kv = el('dl', 'kv');
    kv.append(el('dt', '', T('import.server')), el('dd', '', `${inst.servername} · ${inst.url}`),
      el('dt', '', T('import.key')), el('dd', inst.key === 'missing' ? 'warn-text' : '', T('import.key_' + inst.key)));
    box.appendChild(kv);
    if (inst.mappings.length) {
      box.appendChild(importList(inst.mappings.map((m) => [m.from, m.to === '' ? T('import.map_skip')
        : m.to + (m.there === false ? ' · ' + T('import.map_missing') : m.there === null ? ' · ' + T('import.map_asleep') : ''), m.there === false ? 'jo-imp-warn' : ''])));
    }
    if (inst.bad_mappings.length) box.appendChild(el('p', 'role', T('import.map_bad', { paths: inst.bad_mappings.join(', ') })));
  });
  if (p.users) {
    const kv = el('dl', 'kv');
    kv.append(el('dt', '', T('import.people')), el('dd', '', p.users.n ? T('import.people_n', { n: p.users.n }) : T('import.people_all')));
    if (p.users.bad) kv.append(el('dt', '', ''), el('dd', '', T('import.people_bad', { n: p.users.bad })));
    if (p.libraries) kv.append(el('dt', '', T('import.libraries')), el('dd', '', p.libraries.join(', ') || '–'));
    box.appendChild(kv);
  }
  importLists(box, p, p.same ? T('import.same', { n: p.same }) : '');
  if (p.exclude || p.origin) {
    box.appendChild(importHead(T('import.on_pool')));
    if (p.exclude) {
      const kv = el('dl', 'kv');
      kv.append(el('dt', '', T('import.list_old')), el('dd', '', String(p.exclude.ok)),
        el('dt', '', T('import.list_new')), el('dd', '', String(p.exclude.ok - p.exclude.already)),
        el('dt', '', T('import.list_after')), el('dd', '', String(p.exclude.total)));
      if (p.exclude.bad) kv.append(el('dt', '', T('import.list_bad')), el('dd', 'warn-text', String(p.exclude.bad)));
      box.appendChild(kv);
      if (p.exclude.bad_sample.length) box.appendChild(importList(p.exclude.bad_sample.map((l) => [l, undefined, 'jo-imp-warn'])));
    }
    if (p.origin) {
      const kv = el('dl', 'kv');
      kv.append(el('dt', '', T('import.origin_old')), el('dd', '', String(p.origin.ok)),
        el('dt', '', T('import.origin_after')), el('dd', '', String(p.origin.total)));
      if (p.origin.bad) kv.append(el('dt', '', T('import.list_bad')), el('dd', 'warn-text', String(p.origin.bad)));
      box.appendChild(kv);
    }
  }
  (p.warnings || []).forEach((w) => box.appendChild(el('p', 'callout warn', T('import.warn.' + w.key, w.params || {}))));
  return box;
}

function importGather(p) {
  const box = el('div', 'jo-imp');
  box.appendChild(el('p', 'role', p.found ? T('import.found', { files: 'consolidate.ini' }) : T('import.found_none_ini')));
  if (!p.found) return box;
  if (p.blockers.length) box.appendChild(importList(p.blockers.map((b) => [T('import.block.' + b.key, b.params || {}), undefined, 'error'])));
  const kv = el('dl', 'kv');
  kv.append(el('dt', '', T('import.shares')), el('dd', '', p.shares.join(', ') || '–'));
  box.appendChild(kv);
  if (p.dropped_shares.length) box.appendChild(importList(p.dropped_shares.map((d) => [d.path, T('import.share_why.' + d.why)])));
  importLists(box, p, '');
  if (p.strange) box.appendChild(el('p', 'role', T('import.strange', { n: p.strange })));
  (p.warnings || []).forEach((w) => box.appendChild(el('p', 'callout warn', T('import.warn.' + w.key, w.params || {}))));
  return box;
}

function importPart(title, folder, body) {
  const part = el('div', 'box jo-part');
  const head = el('div', 'jo-part-head', title);
  head.appendChild(el('span', 'jo-part-why', folder));
  part.append(head, body);
  return part;
}

function importPreview(p, folders) {
  const box = el('div');
  box.appendChild(el('p', '', T('import.preview_bubble')));
  if (p.embycache) box.appendChild(importPart('EmbyCache', p.embycache.folder, importEmbyCache(p.embycache)));
  if (p.gather) box.appendChild(importPart(T('gather'), p.gather.folder, importGather(p.gather)));
  if (p.running) box.appendChild(el('p', 'callout warn', T('errors.emby_running')));
  else if (!p.ready) box.appendChild(el('p', 'callout warn', T('errors.emby_import_blocked')));
  else box.appendChild(el('p', 'role', T('import.backup_note')));
  const err = errorLine();
  box.appendChild(err);
  const buttons = [{ text: Office.t('common.cancel') }];
  if (p.ready && !p.running) buttons.push({ text: T('import.go'), kind: '', act: () => importGo(folders, p, err) });
  Office.dialog({ title: T('import.preview_title'), body: box, wide: true, buttons });
}

async function importGo(folders, p, err) {
  const j = await Office.api.post(`${ID}.import_apply`, { ...folders, token: p.token });
  if (!j.ok) { showError(err, j.error); return false; }
  if (j.state) state = j.state;
  form = null;
  // taken over from inside the wizard: it goes on with what came over (saved already — nothing to discard)
  if (wiz) { initForm(); wiz.snap = formKey(); }
  importDone(j.done);
  return true;
}

function importDone(d) {
  const box = el('div');
  box.appendChild(el('p', '', T('import.done_bubble')));
  const what = [];
  if (d.settings) what.push(T('import.done_settings'));
  if (d.exclude !== null) what.push(T('import.done_list', { n: d.exclude }));
  if (d.origin !== null) what.push(T('import.done_origin', { n: d.origin }));
  if (d.gather) what.push(T('import.done_gather'));
  if (what.length) box.appendChild(importList(what.map((w) => [w])));
  const still = (state.foreign || []).filter((f) => f.enabled);
  box.appendChild(el('p', 'callout warn', T('import.done_switch_off') + (still.length ? ' ' + T('import.done_still', { where: still.map((f) => f.where).join(', ') }) : '')));
  if (d.embycache) box.appendChild(el('p', 'callout', T('import.done_dry')));
  if (d.backups.length) {
    box.append(importHead(T('import.done_copies')), importList(d.backups.map((f) => [f])), el('p', 'role', T('import.done_undo', { stamp: d.stamp })));
  } else {
    box.appendChild(el('p', 'role', T('import.done_nothing_before')));
  }
  Office.dialog({ title: T('import.done_title'), body: box, wide: true,
    buttons: [{ text: Office.t('common.close'), kind: '' }], onClose: () => { if (wiz) drawWizard(); else Office.go(`#/${ID}`); } });
}

function input(value, onchange) {
  const i = el('input', 'input');
  i.value = value ?? '';
  i.oninput = () => onchange(i.value);
  return i;
}
function number(obj, key, min, max) {
  const i = el('input', 'input');
  i.type = 'number';
  i.min = min;
  i.max = max;
  i.value = obj[key];
  i.oninput = () => { obj[key] = Number(i.value); };
  return i;
}
function select(obj, key, options) {
  const s = el('select', 'picker');
  options.forEach(([value, text]) => s.appendChild(new Option(text, value)));
  s.value = obj[key];
  s.onchange = () => { obj[key] = s.value; };
  return s;
}
function field(label, inputEl, hint) {
  const f = el('div', 'field');
  f.append(el('label', '', label), inputEl);
  if (hint) f.appendChild(el('small', '', hint));
  return f;
}
function check(text, checked, onchange, small) {
  const label = el('label', 'check');
  const i = el('input');
  i.type = 'checkbox';
  i.checked = !!checked;
  i.onchange = () => onchange(i.checked);
  const span = el('span', '', text);
  if (small) span.appendChild(el('small', '', small));
  label.append(i, span);
  return label;
}

async function saveSetup() {
  const v = form.values;
  const libs = libraryList().filter((l) => form.chosenLibs.has(l.name));
  if (!libs.length) { Office.toast(T('setup.no_library'), true); return; }
  // the pool from Unraid's share settings (step 3): one, or the one picked among several — never chosen freely
  const cache = derivedPool();
  if (!cache) { Office.toast(T(poolsOfChoice().size > 1 ? 'wizard.pools_pick' : 'wizard.pool_none'), true); goStep('libraries'); return; }
  const instances = form.instances.map((inst) => ({ servername: inst.servername, url: inst.url, api_key: inst.api_key, path_mappings: {} }));
  // folders of libraries that aren't chosen: deliberately not cached (EmbyCache then skips them quietly)
  for (const lib of libraryList()) {
    if (!form.chosenLibs.has(lib.name)) for (const [i, p] of lib.at) instances[i].path_mappings[p] = '';
  }
  for (const lib of libs) {
    for (const [i, p] of lib.at) {
      if (form.instances[i].skip.has(p)) { instances[i].path_mappings[p] = ''; continue; }
      const to = form.instances[i].mappings[p];
      if (!to || !/^\/mnt\/user\/[^/]+/.test(to)) { Office.toast(T('setup.map_missing', { path: p }), true); return; }
      // a share without a pool as its primary, or on another pool than his: left out (not cached), as step 3 says
      const sh = shareOf(to);
      instances[i].path_mappings[p] = cacheable(sh) && '/mnt/' + state.share_info[sh].primary === cache ? to : '';
    }
  }
  if (v.budget_mode && !/^\d+(\.\d+)?\s*[KMGTP]?B?$/i.test(v.cache_budget)) { Office.toast(T('setup.budget_missing'), true); return; }
  if (stepBlocked('summary')) { Office.toast(stepBlocked('summary'), true); goStep('users'); return; }
  const settings = {
    instances,
    libraries: libs.map((l) => l.name),
    library_types: Object.fromEntries(libs.map((l) => [l.name, l.type])),
    valid_users: [...form.chosenUsers],
    user_budgets: v.budget_mode ? form.budgets : {},
    cache_path: cache,
    cache_budget: v.budget_mode ? v.cache_budget : '',
    number_episodes: v.number_episodes,
    movie_share_percent: v.movie_share_percent,
    max_episodes_per_series: v.max_episodes_per_series,
    max_resume_movies: v.max_resume_movies,
    max_resume_series: v.max_resume_series,
    max_resume_items: Math.max(v.max_resume_movies, v.max_resume_series),     // for older EmbyCache versions
    max_favorite_series: v.max_favorite_series,
    use_next_up: v.use_next_up,
    min_free_percent: v.min_free_percent,
    movie_mode: 'folder',          // always the whole folder: subtitles, preview images, nfo, extras
    return_to_origin: v.return_to_origin,
    array_source: v.array_source,
    create_share_root: v.create_share_root,
    give_back_unwatched_days: Math.round(Number(v.give_back_unwatched_days)),
  };
  if (!Number.isInteger(settings.give_back_unwatched_days) || settings.give_back_unwatched_days < 1 || settings.give_back_unwatched_days > 365) {
    Office.toast(Office.errorText({ key: 'emby_bad_number', params: { field: T('share.days') } }, ID), true);
    return;
  }
  const j = await act('save', { settings });
  if (!j) return;
  Office.toast(T('setup.saved'));
  // saved: the wizard closes, the tab shows his setup as saved now
  const w = wiz;
  wiz = null;
  form = null;
  if (w) w.dlg.close();
  renderSetup();
}

// ------------------------------------------------------------------ desk
// ------------------------------------------------------------------ let go
/**
 * His part of the let-go dialog (core.js Office.fireDialog → the desk's letGo; agent/desks/emby-letgo.php). He looks
 * first what letting him go does here (emby.letgo_look): both schedules go off — always, no tick; a run that is going
 * finishes; one tick «Also bring the prepared films back to the array», off by default and only when that can be done
 * now (something on the pool, no run going, nobody watching Emby); without it the films stay on the pool. «Let go» —
 * while he is still hired — sends emby.letgo {confirm, release} once; he is let go whatever came of it, then a dialog
 * says what he did.
 */
function letGoPart(box) {
  const wrap = el('div', 'jo-letgo');
  const lines = el('div', 'jo-letgo-lines');
  const label = el('label', 'check');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.disabled = true;
  const text = el('span', '', T('letgo.tick'));
  const hint = el('small', '', '');
  text.appendChild(hint);
  label.append(cb, text);
  label.hidden = true;
  const more = el('div', 'jo-letgo-more');
  wrap.append(lines, label, more);
  box.appendChild(wrap);
  let button = null;
  let sent = null;
  const sync = () => { if (button) button.textContent = cb.checked && !cb.disabled ? T('letgo.button') : Office.t('office.fire'); };
  cb.onchange = sync;
  const looked = (j) => {
    const v = letGoView(j);
    lines.replaceChildren(...v.lines.map(letGoLine));
    label.hidden = !v.tick;
    cb.disabled = !v.tickable || !!sent;
    if (cb.disabled) cb.checked = false;
    hint.textContent = v.hint;
    more.replaceChildren(...v.more.map(letGoLine));
    sync();
  };
  let asked = null;
  if (!Office.agent.running) lines.replaceChildren(letGoLine(['callout warn', T('letgo.agent_away')]));
  else {
    lines.replaceChildren(letGoLine(['role', T('letgo.looking')]));
    asked = Office.api.post(`${ID}.letgo_look`, {}).then(looked, (e) => looked({ ok: false, error: { key: 'internal', params: { detail: String(e && e.message || e) } } }));
  }
  return {
    looked: asked,               // the look under way (the tests wait for it)
    bind(b) { button = b; sync(); },
    before() {                   // once, even if «Let go» is pressed again
      if (!sent) {
        const release = cb.checked && !cb.disabled;
        cb.disabled = true;
        sent = Office.api.post(`${ID}.letgo`, { confirm: true, release });
      }
      return sent;
    },
    done: letGoDone,
  };
}

/** A line of the let-go dialogs: [class, text], or ['who', watchers] (a list) */
function letGoLine([cls, text]) {
  return cls === 'who' ? watchList(text, false) : el('p', cls, text);
}

/** «EmbyCache (every hour), Consolidate (Sundays at 03:00)» — his jobs with their schedules */
function letGoList(jobs, cron) {
  return (jobs || []).map((job) => (cron && cron[job] ? T('letgo.sched_item', { tool: T('tool.' + job), when: fmt.cron(cron[job]) }) : T('tool.' + job))).join(', ');
}

/**
 * What the look says: lines above the tick, whether there is one and may be ticked, its hint, lines below it (pure)
 */
function letGoView(j) {
  if (!j.ok) return { lines: [['callout warn', Office.errorText(j.error, ID)], ['role', T('letgo.agent_away')]], tick: false, tickable: false, hint: '', more: [] };
  const lines = [];
  const sch = j.schedules || {};
  const on = Object.keys(sch).filter((k) => sch[k]);
  lines.push(on.length ? ['', T('letgo.sched_off', { list: letGoList(on, sch) })] : ['', T('letgo.sched_none')]);
  if (j.running) lines.push(['callout', T('letgo.running.' + (j.running === 'gather' ? 'gather' : 'embycache'))]);
  if (j.waiting && sch.gather) lines.push(['role', T('letgo.waiting')]);
  const rel = j.release || {};
  const pool = j.pool || {};
  const tick = !['emby_not_configured', 'emby_letgo_nothing'].includes(rel.why);
  if (rel.why === 'emby_letgo_nothing') lines.push(['role', T('letgo.nothing')]);
  const more = [];
  if (tick) {
    if (rel.why === 'emby_running') more.push(['callout warn', T('letgo.release_busy')]);
    else if (rel.why === 'emby_release_watching') more.push(['callout warn', T('letgo.watching')], ['who', (rel.params || {}).who || []], ['role', endSessionText('letgo.watch_then')]);
    else if (rel.why) more.push(['callout warn', Office.errorText({ key: rel.why, params: rel.params || {} }, ID)]);
    more.push(['role', T('letgo.tick_off')]);
  }
  more.push(['role', T('letgo.stays')]);
  if (j.mover_off) more.push(['role', T('letgo.mover_stays')]);       // Unraid's mover schedule stays off — Ms. Moverelli moves
  return { lines, tick, tickable: tick && rel.ok === true, hint: tick ? T('letgo.tick_hint', { files: nOf('files', pool.files), size: fmt.size(pool.bytes || 0) }) : '', more };
}

/** After he was let go: what he did — the schedules switched off (or not), the release started (or why not) */
function letGoDoneLines(r) {
  if (!r || !r.ok) return [['callout warn', T('letgo.done_error', { error: Office.errorText((r && r.error) || { key: 'internal' }, ID) })], ['role', T('letgo.done_hire')]];
  const out = [];
  if ((r.off || []).length) out.push(['', T('letgo.done_off', { list: letGoList(r.off, r.was) })]);
  if (r.failed) out.push(['callout warn', T('letgo.done_error', { error: Office.errorText(r.failed, ID) })]);
  if ((r.left || []).length) out.push(['callout warn', T('letgo.done_still', { list: letGoList(r.left, r.was) })], ['role', T('letgo.done_hire')]);
  if (r.running) out.push(['role', T('letgo.running.' + (r.running === 'gather' ? 'gather' : 'embycache'))]);
  const rel = r.release;
  if (rel && rel.started) out.push(['', T('letgo.done_release', { files: nOf('files', rel.files), size: fmt.size(rel.bytes || 0) })]);
  else if (rel) {
    out.push(['callout warn', T('letgo.done_release_not', { error: Office.errorText(rel.error || { key: 'internal' }, ID) })]);
    if (rel.error && rel.error.key === 'emby_release_watching') out.push(['who', rel.error.params.who || []]);
  }
  return out;
}
function letGoDone(r) {
  const lines = letGoDoneLines(r);
  if (!lines.length) return;               // nothing was on, nothing asked: nothing to say
  const box = el('div');
  lines.forEach((l) => box.appendChild(letGoLine(l)));
  Office.dialog({ title: T('letgo.done_title'), body: box });
}

/**
 * Hired again: what he switched off when he was let go — said once (the agent's note, emby.letgo_seen as soon as it is
 * shown; it stays for this visit), until those schedules are on again; a button for each.
 */
let letGoNote = null;
function letGoNotice() {
  const n = letGoNote || (state && state.letgo);
  if (!n) return null;
  if (!letGoNote && Office.agent.running) {
    letGoNote = n;
    Office.api.post(`${ID}.letgo_seen`, {}).catch(() => {});
  }
  const off = (n.off || []).filter((job) => !(state.schedules && state.schedules[job] && state.schedules[job].enabled));
  if (!off.length) return null;
  const p = el('p', 'callout', T('letgo.note', { date: fmt.date(n.time), list: letGoList(n.off, n.was) }) + ' ');
  off.forEach((job) => {
    p.appendChild(button(T('schedule_title.' + job), 'small plain', () => scheduleDialog(job))).disabled = !Office.agent.running;
    p.appendChild(document.createTextNode(' '));
  });
  return p;
}

/**
 * The two filter bars, built once per visit (CLAUDE.md «Tiles»): the words, «only new» and the tile never kept — a new
 * visit starts with everything; the pool's library is a choice, kept as emby.pool.pick (until 2026-10-11 emby.pool_lib:
 * taken over once).
 */
function bars() {
  if (!poolBar) {
    const old = Office.store('emby.pool_lib');
    if (old) {
      if (!Office.store('emby.pool.pick')) Office.store('emby.pool.pick', old);
      Office.store('emby.pool_lib', null);
    }
    poolBar = Office.filterBar({ key: 'emby.pool', label: T('pool.filter_label'), only: { text: T('pool.only_new'), hint: T('pool.only_new_hint') },
      select: { label: T('pool.lib_label'), all: T('pool.lib_all') },
      onChange: (f, what) => {
        if (what !== 'gone') poolShown = POOL_PAGE;
        if (dom.poolBox && dom.poolBox.isConnected) fillPool(dom.poolBox);
      } });
  }
  if (!runsBar) runsBar = Office.filterBar({ key: 'emby.runs', label: T('history.filter_label'), onChange: () => { if (dom.runs && dom.runs.isConnected) fillRuns(dom.runs); } });
  poolBar.reset();
  runsBar.reset();
  poolShown = POOL_PAGE;
}

Office.desk({
  id: ID,
  async mount(root, sub) {
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : 'main';
    if (page === 'setup') {
      // #/emby/setup/<step> (the search, a step's place, an old tile's route) opens that step, the address keeps its page;
      // else the start: the summary once he is set up, step 1 before
      const want = stepOf(String(sub || '').split('/')[1] || '');
      if (String(sub || '').includes('/')) Office.subroute('setup');
      if (!state) await load(false);
      await Office.freshState(ID);      // the form starts from his settings: from a fresh look, never a stale one
      if (view !== root || page !== 'setup') return;
      // … and anew on every visit: a form left without «Save» kept its old values and a later save wrote them back
      // (2026-10-10: 12 episodes ahead became 7)
      form = null;
      renderSetup();
      if (want) openWizard(want);       // #/emby/setup/<step>: the wizard at that step
      return;
    }
    bars();
    // his tiles as places (CLAUDE.md «Tiles»): #/emby/<tile> (plans, pool, runs, shares, gather) picks it exactly like a
    // click and the address is his page's again; the Team Lead's findings #/emby/schedule, #/emby/gather-schedule land
    // in «Schedules» with the row revealed; #/emby/history (the search's runs) shows the whole page — every run
    const plan = { schedule: 'embycache', 'gather-schedule': 'gather' }[sub];
    if (plan) sw.mount('plans');
    else if (sub === 'history') {
      sw.mount('');
      sw.pick('', 'route');
      Office.subroute('');
    } else sw.mount(sub);
    render();
    if (plan) Office.reveal(`plan:${plan}`);
    await load(false);
  },
  unmount() { if (wiz) { const w = wiz; wiz = null; w.dlg.close(); } view = null; clearTimeout(outTimer); clearTimeout(progTimer); progTimer = null; progBox = null; startKicks = 0; letGoNote = null; },
  letGo: letGoPart,
  poll() { if (page === 'main') load(false); },
  agentChanged() { if (view) (page === 'setup' ? renderSetup() : render()); },
  menu() {
    const items = [{ text: T('menu.refresh'), act: () => load(true) }, { text: T('import.title') + '…', act: importDialog }];
    // the schedules also before he is set up: the gather's may be set without EmbyCache (its dialog says what is missing)
    if (state) {
      items.push({ text: T('schedule_title.embycache'), act: () => scheduleDialog('embycache') });
      items.push({ text: T('schedule_title.gather'), act: () => scheduleDialog('gather') });
    }
    if (state && state.configured) {
      items.push({ text: T('show_log') + ' · EmbyCache', act: () => showLog('embycache') });
      items.push({ text: T('show_log') + ' · ' + T('gather'), act: () => showLog('gather') });
    }
    return items;
  },
  async reception() {
    if (!state) await load(false);
    const facts = [];
    if (state && state.configured) {
      facts.push(T('fact.pool', { n: state.cache.files, size: fmt.size(state.cache.bytes) }));
      const { main } = lastRuns(state.history);          // as «Last run» on his page says it
      if (main && main.finished) facts.push(T('fact.last', { when: fmt.relative(main.finished) }));
      if (!state.gather.ready) facts.push(T('fact.gather_first'));
    } else {
      facts.push(T('fact.setup'));
    }
    return { bubble: T('bubble.hello_short'), facts };
  },
});

// what his state holds for the search (core.js «items»): his Emby servers (the line «How I work» names them), the shares
// of the chosen libraries (#/emby/shares), the runs his history shows (#/emby/history: the whole page, every run)
Office.placesFrom(ID, (s, part) => {
  if (part) return [];                       // the part «progress» (the live panel) holds nothing to find
  const out = [];
  if (s.configured) {
    ((s.settings && s.settings.instances) || []).forEach((i) => {
      if (i && i.servername) out.push({ text: i.servername, sub: `${T('overview')} · Emby`, anchor: 'overview' });
    });
    (s.shares || []).forEach((x) => out.push({ text: x.share, sub: T('shares'), route: `#/${ID}/shares`, anchor: `share:${x.share}` }));
  }
  (s.history || []).slice(0, HISTORY_SHOWN).forEach((r) => out.push({ text: `${T('tool.' + r.tool)} · ${T('mode.' + r.mode)} · ${fmt.date(r.started)}`,
    sub: `${T('history')} · ${T('result.' + (r.result || 'failed'))}`, route: `#/${ID}/history`, anchor: `run:${r.tool}:${r.started}` }));
  return out;
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys): the
// main page, then the setup's (#/emby/setup)
const SETUP = { route: '#/emby/setup', crumb: 'setup_open' };
// (the line «How I work», the shares and what lies on the pool only once he is set up — core.js shown; not known yet:
// listed); each section by the route of its tile (a section stands under the row only while its tile is chosen)
Office.places(ID, [
  { kind: 'section', key: 'overview', shown: configured },
  { kind: 'section', key: 'shares', route: '#/emby/shares', shown: configured },
  { kind: 'section', key: 'gather', route: '#/emby/gather' },
  { kind: 'section', key: 'history', route: '#/emby/history' },
  { kind: 'section', key: 'on_pool', route: '#/emby/pool', shown: configured },
  // his tiles (CLAUDE.md «Tiles», the switcher's places): each sets its choice as a click would
  ...sw.places().map((p) => (['tile.pool', 'tile.shares'].includes(p.anchor) ? { ...p, shown: configured } : p)),
  { kind: 'section', key: 'tools' },
  // the wizard's steps by their route (#/emby/setup/<step> opens that step; its title is marked as the place)
  ...SETUP_STEPS.map((x) => ({ kind: 'step', key: x.place, ...SETUP, route: `#/${ID}/setup/${x.id}` })),
  ...[['report', 'help.report'], ['mode.dry', 'help.dry'], ['mode.run', 'help.run'], ['gather', 'help.gather_text']]
    .map(([key, text]) => ({ kind: 'help', key, text })),
  ...['what', 'tools', 'progress', 'origin', 'watch', 'shares', 'sizes', 'pool', 'schedule', 'letgo'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['import.title', 'import.help'], ['setup.key', 'setup.help_key'], ['setup.mapping', 'setup.help_mapping'], ['setup.users', 'setup.help_users'],
    ['setup.scope', 'setup.help_scope'], ['setup.more', 'setup.help_more'], ['share.title', 'share.help'], ['sim.title', 'sim.help'], ['setup.save', 'setup.help_save']]
    .map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.emby = { setState: (s) => { state = s; }, roomWords, tonightText, rankItem, progFile, sizesLine, readyOf, poolTile, shareSection, poolView, poolHay, poolSection, bars, lastRuns, sw,
    historySection, plansSection, plansList, gatherSection, footer, scopeLine, lastRunTile, plansTile, poolTile, sharesTile, gatherTile, render,
    renderSetup, page: () => page, SETUP_STEPS, setForm: (f) => { form = f; }, initForm, form: () => form, shareWords, setupSummary, summaryData, peopleWords, herRules, sharePart, simBase, needsConnect, connectInst, nextFromServers, resetDialog, summaryCard, introCard, budgetGb, budgetSplit, budgetState, budgetLine, stepBlocked, MIN_PERSON_GB, shareCase, setupLibraries, setupUsers, drawWizard, goStep, saveSetup,
    stepOf, startStep, openWizard, cancelWizard, wiz: () => wiz, formKey, derivedPool, poolsOfChoice, cacheable,
    letGoPart, letGoView, letGoDoneLines, letGoNotice, runSummary, moverNotice, moverOk, moverOffDialog, gatherSettingsDialog, gatherRunDialog, gatherCacheWords,
    progressPanel, setProgress: (p, why) => { progress = p; stopping = why || null; }, etaText, tookText, nightPanel, livePanel, chooseRun, headActions,
    setNight: (n) => { nightNow = n; }, nightGoing };
}
})();
