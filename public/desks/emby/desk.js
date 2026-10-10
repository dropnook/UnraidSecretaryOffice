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
let poolLib = Office.store('emby.pool_lib') || '';    // the library chosen there ('' = all), kept in this browser
let poolWords = '';                                    // its filter words (not kept: a new visit starts with everything)
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
let startKicks = 0;           // a real run just started from here: look again until it shows as running

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
  if (typeof s.time === 'number' && s.time >= progressAt) { progress = s.progress || null; stopping = s.stopping || null; progressAt = s.time; }
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

/** While a real run goes: the part «progress» every 10 s (the first ask soon after the page shows the run); nothing otherwise */
function scheduleProgress() {
  clearTimeout(progTimer);
  progTimer = null;
  if (!view || page !== 'main' || !realRun()) return;
  progTimer = setTimeout(askProgress, Math.max(300, PROGRESS_POLL - (Date.now() - progAsked)));
}
async function askProgress() {
  if (!view || page !== 'main' || !realRun()) return;
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
  if (!p.real) { progressAt = p.time; load(true); return; }        // the run ended: his state anew — the panel goes
  progress = p.progress || null;
  stopping = p.stopping || null;
  progressAt = p.time;
  redrawProgress();
  scheduleProgress();
}
/** The panel drawn again in place */
function redrawProgress() {
  if (progBox && progBox.isConnected) {
    const n = progressPanel();
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
function stat(box, label, value, sub, alert, onclick) {
  const s = el(onclick ? 'button' : 'div', 'stat' + (alert ? ' alert' : ''));
  if (onclick) { s.type = 'button'; s.onclick = onclick; }
  s.append(el('div', 'stat-label', label), el('div', 'stat-value', value));
  if (sub) s.appendChild(el('div', 'stat-sub', sub));
  box.appendChild(s);
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
  box.scrollIntoView({ block: 'nearest' });
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
  const t = m.tuning || {};
  return Office.errorText({ key: 'emby_mover_' + m.why, params: { file: t.file || '', detail: t.error || '', shares: (t.overrides || []).join(', ') } }, ID);
}

/**
 * Unraid's mover and EmbyCache's files on the pool: real runs only while Unraid's own mover schedule is «Disabled»
 * (2026-10-09). Not met: why; for the schedule «Switch the mover schedule off…» (moverOffDialog) and «set it
 * yourself» (Unraid's ⟦Mover Settings⟧). Met: what still moves them (⟦Move now⟧ by hand — his run stops after the
 * current file then); Mover Tuning, if installed, keeps his list (he enters it there himself). Mover Tuning's «Move
 * All» threshold, the mover at work right now.
 */
function moverNotice() {
  const m = mover();
  if (!m) return null;
  const t = m.tuning || {};
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
    }
  } else {
    box.appendChild(el('p', 'role', T('mover.ok.disabled')));
    if (t.installed && t.listed) box.appendChild(el('p', 'role', T('mover.ok.tuning', { file: t.file || '' })));
  }
  if (t.changed) box.appendChild(el('p', 'role', T('mover.changed', { file: t.file || '' })));
  if (t.installed && t.move_all) box.appendChild(el('p', 'role', T('mover.move_all', { pct: String(t.move_all) })));
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
  if (!state.schedules.embycache.enabled) parts.push(T('bubble.no_schedule'));
  return parts.join(' ');
}

// ------------------------------------------------------------------ main view
function render() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  const actions = [];
  if (state && state.configured) {
    actions.push(button(T('report'), 'plain', () => startRun('embycache', 'report')));
    actions.push(button(T('start'), '', chooseRun));
    actions.push(button(T('setup_open'), 'plain', () => Office.go(`#/${ID}/setup`)));
  } else if (state && state.emby.length && state.python) {
    actions.push(button(T('setup_open'), '', () => Office.go(`#/${ID}/setup`)));
  }
  actions.forEach((b) => { b.disabled = !Office.agent.running || running(); });
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions });
  root.appendChild(head);
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

  if (!state.emby.length) root.appendChild(el('p', 'callout warn', T('notice.no_emby')));
  if (!state.python) root.appendChild(el('p', 'callout warn', T('notice.no_python')));
  const foreign = state.foreign.filter((f) => f.enabled);
  if (foreign.length) root.appendChild(el('p', 'callout warn', T('notice.foreign', { where: foreign.map((f) => f.where).join(', ') })));
  const back = letGoNotice();
  if (back) root.appendChild(back);
  const mv = state.configured ? moverNotice() : null;
  if (mv) root.appendChild(mv);
  if (running()) {
    const p = el('p', 'callout', T(state.jobs.gather.running ? 'notice.gathering' : 'notice.running') + ' ');
    // a real EmbyCache run: its panel in the overview has the raw output
    if (!realRun()) p.appendChild(button(T('show_output_now'), 'small', () => showOutput(state.jobs.gather.running ? 'gather' : 'embycache', true))).disabled = !Office.agent.running;
    root.appendChild(p);
  }
  if (!state.configured) {
    const p = el('p', 'callout', T('notice.setup') + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    p.appendChild(document.createTextNode(' '));
    p.appendChild(button(T('import.open'), 'small plain', importDialog)).disabled = !Office.agent.running;
    root.appendChild(p);
    root.appendChild(gatherSection());
    root.appendChild(historySection());
    root.appendChild(toolSection());
    return;
  }
  root.appendChild(overview());
  root.appendChild(shareSection());
  root.appendChild(gatherSection());
  root.appendChild(historySection());
  root.appendChild(poolSection());
  root.appendChild(toolSection());
}

/** A quiet line under the tools' tiles: both tools are helmi1987's, and a share of the tips goes to him */
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

/** The settings in short, the last run, the schedule */
function overview() {
  const s = section(T('overview'), T('overview_sub'), { place: 'overview' });
  const stats = el('div', 'stats');
  const set = state.settings || {};
  const inst = set.instances || [];
  stat(stats, 'Emby', inst.map((i) => i.servername).join(', ') || '–', inst.map((i) => i.url).join(', '));
  // by number: films and series each their own (only what's chosen; older settings don't know the kinds)
  const kinds = Object.values(set.library_types || {});
  const amount = [];
  if (!kinds.length || kinds.includes('movies')) amount.push(T('count_films', { n: set.max_resume_movies ?? set.max_resume_items ?? 0 }));
  if (!kinds.length || kinds.includes('tvshows')) amount.push(T('count', { n: set.number_episodes || 0 }));
  stat(stats, T('mode'), set.cache_budget ? T('budget', { size: set.cache_budget }) : amount.join(' · '),
    T('libraries_people', { libs: (set.libraries || []).join(', ') || T('all'), people: peopleText(set.valid_users) }));
  const pool = state.pool;
  if (pool) {
    stat(stats, T('pool'), `${fmt.number(pool.free_percent, 1)} %`, T('pool_sub', { pool: pool.path, free: fmt.size(pool.free), min: pool.min_free ?? '?' }),
      pool.min_free != null && pool.free_percent < pool.min_free);
  }
  const last = state.last;
  stat(stats, T('last_run'), last && last.finished ? fmt.relative(last.finished) : T('never'),
    last ? `${T('mode.' + (last.mode || 'dry'))} · ${T('counts', { errors: nOf('errors', last.errors), warnings: nOf('warnings', last.warnings) })}` : '',
    !!(last && (last.errors || (last.result && last.result !== 'ok'))));
  const sc = state.schedules.embycache;
  stat(stats, T('schedule'), schedText(sc), sc.enabled ? T('by_office') : T('schedule_set'),
    !sc.enabled, () => scheduleDialog('embycache'));
  s.appendChild(stats);

  const wait = (state.waiting || {}).embycache;
  if (wait) s.appendChild(moverWaitLine(wait));
  if (last && last.result) {
    const box = el('div', 'box jo-results');
    // why it stopped lies in his list of runs (the run's own entry: the newest EmbyCache run that started)
    const h = (state.history || []).find((r) => r.tool === 'embycache' && !['refused', 'skipped'].includes(r.result));
    box.appendChild(el('div', '', runSummary({ tool: 'embycache', mode: last.mode, status: last, result: last.result, why: h && h.result === last.result ? h.why : null })));
    if (last.incomplete) box.appendChild(el('div', 'warn-text', T('incomplete')));
    s.appendChild(box);
  }
  // a real run going: the live panel where the output button was (its raw output a link inside); else the last run's
  // output stays behind a small link beside the log
  progBox = realRun() ? progressPanel() : null;
  if (progBox) s.appendChild(progBox);
  const bar = el('div', 'toolbar');
  bar.appendChild(button(T('show_log'), 'small plain', () => showLog('embycache')));
  if (state.jobs.embycache.mode && !progBox) bar.appendChild(link(T('show_output', { mode: T('mode.' + state.jobs.embycache.mode) }), () => showOutput('embycache', false)));
  s.appendChild(bar);
  return s;
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
  const p = progress;
  const box = el('div', 'box jo-prog running');
  const head = el('div', 'jo-prog-head');
  head.append(el('span', 'spin'), el('span', '', T('progress.title')));
  box.appendChild(head);
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
  const foot = el('div', 'jo-prog-foot');
  if (stopping) foot.appendChild(el('span', 'jo-prog-stopping', T('progress.stopping')));
  foot.appendChild(link(T('progress.raw'), () => showOutput('embycache', true)));
  const stop = button(T('progress.stop'), 'small plain', () => stopAsk(stop));
  stop.disabled = !!stopping || !Office.agent.running;
  foot.appendChild(stop);
  box.appendChild(foot);
  return box;
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
  if (cur.source) {
    const src = T('progress.source.' + cur.source);
    line.appendChild(el('span', 'jo-prog-why', cur.title ? T('progress.why', { source: src, title: cur.title }) : src));
  }
  return line;
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
  return line;
}

/** Do the shares of the chosen libraries suit EmbyCache and its pool? And what lies where */
function shareSection() {
  const shares = state.shares || [];
  const bad = shares.filter((x) => !['ok', 'array_only'].includes(x.fit) || x.root === false).length;
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
  const stats = el('div', 'stats');
  const last = g.last;
  stat(stats, T('gather_last'), last ? fmt.relative(last.finished) : T('never'),
    last ? gatherSummary(last) : T('gather_never'),
    !!(last && (last.conflicts || last.errors || last.full)));
  stat(stats, T('shares'), set.shares.join(', '), T('gather_settings', { gb: set.min_free_gb, dup: T('dup.' + set.dup_check) }) + gatherCacheWords(set));
  const sc = state.schedules.gather;
  stat(stats, T('schedule'), schedText(sc), sc.enabled ? T('by_office') : T('schedule_set'),
    false, () => scheduleDialog('gather'));
  s.appendChild(stats);
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

/** The last runs of both tools */
const HISTORY_SHOWN = 15;
function historySection() {
  const runs = state.history || [];
  const s = section(T('history'), T('history_sub'), { place: 'history' });
  const box = el('div', 'box');
  if (!runs.length) box.appendChild(el('p', 'empty', T('history_none')));
  runs.slice(0, HISTORY_SHOWN).forEach((r) => {
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
  s.appendChild(box);
  return s;
}

/** Words as the filter compares them: lower case, without accents */
const plainWords = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
/** A film or series folder's name as the page shows it (dots as spaces) */
const poolName = (g) => String(g.title || '').replace(/\./g, ' ');
/** Its words for the filter: the name (as shown and as on disk), the library, the disks it goes back to */
const poolHay = (g) => plainWords([poolName(g), g.title, g.share, ...(Array.isArray(g.origin) ? g.origin : [])].join(' '));

/**
 * «Ready on the pool» as his page lists it — no DOM (the tests run it under node). groups: what his state holds
 * (state.cache.groups); o: {lib ('' = every library), words (plainWords, [] = none), shown}. It is the current state,
 * nothing is dropped: the library and the words only narrow what is drawn.
 * Returns {libs: [[library, n], …] by name (the words not counted), rows: what the library and the words leave — newest
 * first where a time is known (`since`), else by name —, shown: rows drawn}
 */
function poolView(groups, o) {
  const list = (Array.isArray(groups) ? groups : []).filter((g) => g && typeof g.title === 'string');
  const counts = new Map();
  list.forEach((g) => counts.set(g.share, (counts.get(g.share) || 0) + 1));
  const libs = [...counts.entries()].sort((a, b) => String(a[0]).localeCompare(String(b[0])));
  const words = o.words || [];
  const rows = list.filter((g) => (!o.lib || g.share === o.lib) && (!words.length || words.every((w) => poolHay(g).includes(w))))
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
 * so readable like the watch book: filter words (name, library, disk), a library select with counts, the first
 * POOL_PAGE rows, then «Show n more».
 */
function poolSection() {
  const c = state.cache;
  const s = section(T('on_pool'), c.listed_at ? T('on_pool_sub', { when: fmt.relative(c.listed_at) }) : T('on_pool_none'),
    el('span', 'hint', c.files ? T('pool_sum', { n: c.files, size: fmt.size(c.bytes) }) : ''), { place: 'on_pool' });
  if (!c.files) return s;
  const groups = Array.isArray(c.groups) ? c.groups : [];
  const bar = el('div', 'toolbar jo-poolbar');
  const search = el('input', 'search');
  search.type = 'search';
  search.placeholder = Office.t('common.filter');
  search.setAttribute('aria-label', T('pool.filter_label'));
  search.autocomplete = 'off';
  search.spellcheck = false;
  search.value = poolWords;
  const pick = el('select', 'picker');
  pick.setAttribute('aria-label', T('pool.lib_label'));
  bar.append(search, pick);
  s.appendChild(bar);
  const box = el('div', 'box');
  const most = Math.max(...groups.map((g) => Number(g.bytes) || 0), 1);
  const fill = () => {
    box.innerHTML = '';
    const v = poolView(groups, { lib: poolLib, words: plainWords(poolWords).split(/\s+/).filter(Boolean), shown: poolShown });
    pick.innerHTML = '';
    pick.appendChild(new Option(T('pool.lib_all'), ''));
    const libs = v.libs.slice();
    if (poolLib && !libs.some(([l]) => l === poolLib)) libs.push([poolLib, 0]);     // chosen, none left now: it stays
    libs.forEach(([l, n]) => pick.appendChild(new Option(T('pool.lib_n', { lib: l, n }), l)));
    pick.value = poolLib;
    v.rows.slice(0, v.shown).forEach((g) => box.appendChild(poolRow(g, most)));
    if (!v.rows.length) box.appendChild(el('p', 'empty', T('pool.empty_filter')));
    if (v.rows.length > v.shown) {
      const more = button(T('pool.more', { n: Math.min(POOL_PAGE, v.rows.length - v.shown) }), 'small plain jo-more', () => {
        poolShown += POOL_PAGE;
        Office.keepInPlace(null, fill);
      });
      box.appendChild(more);
    }
  };
  search.oninput = () => {
    poolWords = search.value;
    poolShown = POOL_PAGE;
    fill();
  };
  pick.onchange = () => {
    poolLib = pick.value;
    Office.store('emby.pool_lib', poolLib || null);
    poolShown = POOL_PAGE;
    Office.keepInPlace(pick, fill);
  };
  fill();
  s.appendChild(box);
  return s;
}

/** The tools themselves: they ship with the office */
function toolSection() {
  const s = section(T('tools'), T('tools_sub'), { place: 'tools' });
  const stats = el('div', 'stats');
  stat(stats, 'EmbyCache', (state.versions.embycache || '?').replace(/\s*\(.*\)$/, ''), T('tool_embycache_sub'));
  stat(stats, T('gather'), state.versions.gather || '?', T('tool_gather_sub'));
  stat(stats, 'Python', state.python || T('none'), T('python_sub'), !state.python);
  s.appendChild(stats);
  if (state.old_clone) s.appendChild(el('p', 'callout', T('notice.old_clone')));
  s.appendChild(credit());
  return s;
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
  Office.dialog({
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
 * choices it leaves to its settings file:
 *   1 servers (one or more Emby instances)   2 libraries and their folders
 *   3 people (and their own budgets)          4 pool and amount
 *   5 more (way back, tools, sources)
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
      create_share_root: !!set.create_share_root,
    },
  };
}

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
  const fitting = media.filter((lib) => shares(lib).every((sh) => ['ok', 'array_only'].includes(shareFit(state.share_info[sh], pool))));
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
  root.innerHTML = '';
  const back = button(T('back'), 'plain', () => Office.go(`#/${ID}`));
  const bubble = T('setup.bubble') + (state && !state.configured ? ' ' + T('import.bubble') : '');
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble, actions: [back] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('import.title'), T('import.help')],
    [T('setup.key'), T('setup.help_key')],
    [T('setup.mapping'), T('setup.help_mapping')],
    [T('setup.users'), T('setup.help_users')],
    [T('setup.scope'), T('setup.help_scope')],
    [T('setup.more'), T('setup.help_more')],
    [T('setup.save'), T('setup.help_save')],
  ]));
  if (!state) return;
  if (!form) initForm();
  const connected = form.instances.some((i) => i.server);
  if (!state.configured) root.appendChild(importSection());     // first thing for someone who ran the tools before

  // 1. servers
  const s1 = section(T('setup.server'), T('setup.server_sub'), { place: 'setup.server' });
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
      const j = await Office.api.post(`${ID}.connect`, { url: inst.url, api_key: inst.api_key });
      connect.disabled = false;
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
      inst.server = j.server;
      inst.libraries = j.libraries;
      inst.users = j.users;
      for (const lib of j.libraries) for (const p of lib.locations) if (!inst.mappings[p]) inst.mappings[p] = suggestMapping(p);
      if (!state.configured && !form.chosenLibs.size) suggestChoice(inst);
      renderSetup();
    });
    bar.appendChild(connect);
    if (inst.server) bar.appendChild(chip(T('setup.connected', { name: inst.server.name, version: inst.server.version }), 'ok'));
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
  root.appendChild(s1);
  if (!connected) { root.appendChild(el('p', 'callout', T('setup.connect_first'))); return; }

  // 2. libraries and their folders
  const v = form.values;
  const pool = v.cache_path.replace('/mnt/', '');
  const s2 = section(T('setup.libraries'), T('setup.libraries_sub'), { place: 'setup.libraries' });
  const box2 = el('div', 'box');
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
        const on = !inst.skip.has(p);
        const label = form.instances.length > 1 ? T('setup.map_on', { server: inst.servername, path: p }) : T('setup.map', { path: p });
        if (lib.at.length > 1 || !on) {
          const c = check(T('setup.cache_folder', { path: p }), on, (x) => { if (x) inst.skip.delete(p); else inst.skip.add(p); renderSetup(); },
            on ? '' : T('setup.cache_folder_off'));
          c.classList.add('jo-folder');
          main.appendChild(c);
        }
        if (!on) return;
        const inp = input(inst.mappings[p] || '', (x) => { inst.mappings[p] = x.trim(); });
        inp.classList.add('mono');
        const f = field(label, inp);
        const share = shareOf(inst.mappings[p]);
        if (share) {
          const fit = shareFit(state.share_info[share], pool);
          const meta = el('div', 'row-meta');
          meta.appendChild(fitChip(fit, { share, pool, primary: (state.share_info[share] || {}).primary || '–', secondary: (state.share_info[share] || {}).secondary || T('array') }));
          if (!(state.pool_dirs[v.cache_path] || []).includes(share)) meta.appendChild(chip(T('root_missing', { pool: v.cache_path }), v.create_share_root ? 'warn' : 'danger', T('root_missing_tip', { share, pool: v.cache_path })));
          f.appendChild(meta);
        }
        main.appendChild(f);
      });
    }
    row.appendChild(main);
    box2.appendChild(row);
  });
  s2.appendChild(box2);
  root.appendChild(s2);

  // 3. people
  const everyone = [...new Set(form.instances.flatMap((inst) => (inst.users || []).map((u) => u.id)))];
  const all = button(T('setup.users_all'), 'small plain', () => { everyone.forEach((id) => form.chosenUsers.add(id)); renderSetup(); });
  const none = button(T('setup.users_none'), 'small plain', () => { form.chosenUsers.clear(); renderSetup(); });
  const pick = el('div', 'toolbar');
  pick.append(all, none);
  const s3 = section(T('setup.users'), T('setup.users_sub'), everyone.length ? pick : null, { place: 'setup.users' });
  const f3 = el('div', 'jo-users');
  const seen = new Set();
  form.instances.forEach((inst) => (inst.users || []).forEach((u) => {
    if (seen.has(u.id)) return;
    seen.add(u.id);
    const wrap = el('div');
    const extra = [u.admin ? T('setup.admin') : '', form.instances.length > 1 ? inst.servername : ''].filter(Boolean).join(' · ');
    wrap.appendChild(check(u.name, form.chosenUsers.has(u.id), (on) => { if (on) form.chosenUsers.add(u.id); else form.chosenUsers.delete(u.id); renderSetup(); }, extra));
    if (v.budget_mode && form.chosenUsers.has(u.id)) {
      const b = input(form.budgets[u.id] || '', (x) => { form.budgets[u.id] = x.trim(); });
      b.placeholder = T('setup.user_budget_placeholder');
      wrap.appendChild(field(T('setup.user_budget'), b));
    }
    f3.appendChild(wrap);
  }));
  s3.appendChild(f3);
  root.appendChild(s3);

  // 4. pool and amount: what applies to both, then films and series apart
  const s4 = section(T('setup.scope'), T('setup.scope_sub'), { place: 'setup.scope' });
  const f4 = el('div', 'jo-form');
  const poolSel = el('select', 'picker');
  state.pools.forEach((p) => poolSel.appendChild(new Option(p, p)));
  poolSel.value = v.cache_path;
  poolSel.onchange = () => { v.cache_path = poolSel.value; renderSetup(); };
  f4.appendChild(field(T('setup.pool'), poolSel, T('setup.pool_hint')));
  f4.appendChild(field(T('setup.min_free'), number(v, 'min_free_percent', 0, 95), T('setup.min_free_hint')));
  const mode = el('select', 'picker');
  mode.append(new Option(T('setup.mode_count'), 'count'), new Option(T('setup.mode_budget'), 'budget'));
  mode.value = v.budget_mode ? 'budget' : 'count';
  mode.onchange = () => { v.budget_mode = mode.value === 'budget'; renderSetup(); };
  f4.appendChild(field(T('setup.mode'), mode, T(v.budget_mode ? 'setup.mode_budget_hint' : 'setup.mode_count_hint')));
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
  root.appendChild(s4);

  // 5. more: the way back and where to read from (both ways always rsync: Unraid's move binary is never Jack's choice)
  const s5 = section(T('setup.more'), T('setup.more_sub'), { place: 'setup.more' });
  const f5 = el('div', 'jo-form');
  f5.appendChild(check(T('setup.origin'), v.return_to_origin, (x) => { v.return_to_origin = x; }, T('setup.origin_hint')));
  f5.appendChild(field(T('setup.array_source'), select(v, 'array_source', [['user0', T('setup.source_user0')], ['disk', T('setup.source_disk')]]), T('setup.array_source_hint')));
  // only needed when a chosen share has no folder on the pool yet
  const shares = [...new Set(libraryList().filter((l) => form.chosenLibs.has(l.name))
    .flatMap((l) => l.at.filter(([i, p]) => !form.instances[i].skip.has(p)).map(([i, p]) => shareOf(form.instances[i].mappings[p]))).filter(Boolean))].sort();
  const missing = shares.filter((sh) => !(state.pool_dirs[v.cache_path] || []).includes(sh));
  const rootOpt = check(T('setup.create_root'), v.create_share_root, (x) => { v.create_share_root = x; renderSetup(); },
    missing.length ? T('setup.create_root_missing', { shares: missing.join(', '), pool: v.cache_path }) : T('setup.create_root_fine', { shares: shares.join(', ') || '–', pool: v.cache_path }));
  if (!missing.length && !v.create_share_root) { rootOpt.querySelector('input').disabled = true; rootOpt.classList.add('jo-muted'); }
  f5.appendChild(rootOpt);
  f5.appendChild(el('p', 'role', T('setup.fixed_paths')));
  s5.appendChild(f5);
  root.appendChild(s5);

  const save = button(T('setup.save'), '', saveSetup);
  save.disabled = running();
  const foot = el('div', 'toolbar');
  foot.append(save, el('span', 'role', T(running() ? 'setup.save_running' : 'setup.save_hint')));
  root.appendChild(foot);
  if (state.configured) root.appendChild(importSection());
}

// ------------------------------------------------------------------ taking over an earlier install
/*
 * Someone who ran helmi1987's EmbyCache or «Consolidate folders» before (from a User Script)
 * types the folder of the old files; the agent reads them on the server (the API key never comes
 * here), the preview says what would change, the import needs its token. Afterwards: switch the
 * old User Script off yourself, start with a dry run, the copies of Jack's files are named.
 */
const importFolders = { embycache: '', gather: '' };

function importSection() {
  return section(T('import.title'), T('import.sub'), button(T('import.open'), 'small', importDialog), { place: 'import.title' });
}

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
    buttons: [{ text: Office.t('common.close'), kind: '' }], onClose: () => Office.go(`#/${ID}`) });
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
      instances[i].path_mappings[p] = to;
    }
  }
  if (v.budget_mode && !/^\d+(\.\d+)?\s*[KMGTP]?B?$/i.test(v.cache_budget)) { Office.toast(T('setup.budget_missing'), true); return; }
  const settings = {
    instances,
    libraries: libs.map((l) => l.name),
    library_types: Object.fromEntries(libs.map((l) => [l.name, l.type])),
    valid_users: [...form.chosenUsers],
    user_budgets: v.budget_mode ? form.budgets : {},
    cache_path: v.cache_path,
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
  };
  const j = await act('save', { settings });
  if (!j) return;
  Office.toast(T('setup.saved'));
  form = null;
  Office.go(`#/${ID}`);
}

// ------------------------------------------------------------------ desk
// ------------------------------------------------------------------ let go
/**
 * His part of the let-go dialog (core.js Office.fireDialog → the desk's letGo; agent/desks/emby-letgo.php). He looks
 * first what letting him go does here (emby.letgo_look): both schedules go off — always, no tick; a run that is going
 * finishes; one tick «Also bring the prepared films back to the array», off by default and only when that can be done
 * now (something on the pool, no run going, nobody watching Emby); without it the films stay on the pool; when Mover
 * Tuning ignores his list, a second tick, on by default: «Also take my list out of Mover Tuning again» (then Mover
 * Tuning moves what is left back on its own). «Let go» — while he is still hired — sends emby.letgo {confirm, release,
 * unlist} once; he is let go whatever came of it, then a dialog says what he did.
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
  // his list out of Mover Tuning again — on by default, only when its cfg names it
  const label2 = el('label', 'check');
  const cb2 = el('input');
  cb2.type = 'checkbox';
  cb2.checked = true;
  const text2 = el('span', '', T('letgo.unlist'));
  const hint2 = el('small', '', '');
  text2.appendChild(hint2);
  label2.append(cb2, text2);
  label2.hidden = true;
  wrap.append(lines, label, more, label2);
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
    label2.hidden = !v.unlist;
    if (v.unlist) hint2.textContent = T('letgo.unlist_hint', { file: v.file });
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
        const unlist = !label2.hidden && cb2.checked;
        cb.disabled = cb2.disabled = true;
        sent = Office.api.post(`${ID}.letgo`, { confirm: true, release, unlist });
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
 * What the look says: lines above the tick, whether there is one and may be ticked, its hint, lines below it, whether
 * Mover Tuning names his list (the second tick) and its file (pure)
 */
function letGoView(j) {
  if (!j.ok) return { lines: [['callout warn', Office.errorText(j.error, ID)], ['role', T('letgo.agent_away')]], tick: false, tickable: false, hint: '', more: [], unlist: false, file: '' };
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
  const mt = j.mover_tuning || {};
  more.push(['role', T('letgo.stays')]);
  if (j.mover_off) more.push(['role', T('letgo.mover_stays')]);       // Unraid's mover schedule stays off — where to switch it on
  return { lines, tick, tickable: tick && rel.ok === true, hint: tick ? T('letgo.tick_hint', { files: nOf('files', pool.files), size: fmt.size(pool.bytes || 0) }) : '', more,
    unlist: !!mt.listed, file: mt.listed ? mt.file || '' : '' };
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
  const un = r.unlist;
  if (un && un.done) out.push(['', T('letgo.done_unlisted')]);
  else if (un) out.push(['callout warn', T('letgo.done_unlist_not', { error: Office.errorText(un.error || { key: 'internal' }, ID) })]);
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

Office.desk({
  id: ID,
  async mount(root, sub) {
    view = root;
    page = sub === 'setup' ? 'setup' : 'main';
    if (page === 'setup') {
      if (!state) await load(false);
      await Office.freshState(ID);      // the form starts from his settings: from a fresh look, never a stale one
      if (view !== root || page !== 'setup') return;
      // … and anew on every visit: a form left without «Save» kept its old values and a later save wrote them back
      // (2026-10-10: 12 episodes ahead became 7)
      form = null;
      renderSetup();
      return;
    }
    render();
    await load(false);
    // the caretaker's "Open" for a schedule
    if (sub === 'schedule' || sub === 'gather-schedule') { Office.subroute(''); scheduleDialog(sub === 'schedule' ? 'embycache' : 'gather'); }
  },
  unmount() { view = null; clearTimeout(outTimer); clearTimeout(progTimer); progTimer = null; progBox = null; startKicks = 0; letGoNote = null; },
  letGo: letGoPart,
  poll() { if (page === 'main') load(false); },
  agentChanged() { if (view) (page === 'setup' ? renderSetup() : render()); },
  menu() {
    const items = [{ text: T('menu.refresh'), act: () => load(true) }, { text: T('import.title') + '…', act: importDialog }];
    if (state && state.configured) {
      items.push({ text: T('schedule_title.embycache'), act: () => scheduleDialog('embycache') });
      items.push({ text: T('schedule_title.gather'), act: () => scheduleDialog('gather') });
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
      if (state.last && state.last.finished) facts.push(T('fact.last', { when: fmt.relative(state.last.finished) }));
      if (!state.gather.ready) facts.push(T('fact.gather_first'));
    } else {
      facts.push(T('fact.setup'));
    }
    return { bubble: T('bubble.hello_short'), facts };
  },
});

// what his state holds for the search (core.js «items»): his Emby servers (the overview names them), the shares of the
// chosen libraries, the runs his history shows
Office.placesFrom(ID, (s, part) => {
  if (part) return [];                       // the part «progress» (the live panel) holds nothing to find
  const out = [];
  if (s.configured) {
    ((s.settings && s.settings.instances) || []).forEach((i) => {
      if (i && i.servername) out.push({ text: i.servername, sub: `${T('overview')} · Emby`, anchor: 'overview' });
    });
    (s.shares || []).forEach((x) => out.push({ text: x.share, sub: T('shares'), anchor: `share:${x.share}` }));
  }
  (s.history || []).slice(0, HISTORY_SHOWN).forEach((r) => out.push({ text: `${T('tool.' + r.tool)} · ${T('mode.' + r.mode)} · ${fmt.date(r.started)}`,
    sub: `${T('history')} · ${T('result.' + (r.result || 'failed'))}`, anchor: `run:${r.tool}:${r.started}` }));
  return out;
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys): the
// main page, then the setup's (#/emby/setup)
const SETUP = { route: '#/emby/setup', crumb: 'setup_open' };
// (the overview, the shares and what lies on the pool only once he is set up — core.js shown; not known yet: listed)
const configured = () => !state || !!state.configured;
Office.places(ID, [
  { kind: 'section', key: 'overview', shown: configured },
  { kind: 'section', key: 'shares', shown: configured },
  { kind: 'section', key: 'gather' },
  { kind: 'section', key: 'history' },
  { kind: 'section', key: 'on_pool', shown: configured },
  { kind: 'section', key: 'tools' },
  ...['import.title', 'setup.server', 'setup.libraries', 'setup.users', 'setup.scope', 'setup.more'].map((key) => ({ kind: 'step', key, ...SETUP })),
  ...[['report', 'help.report'], ['mode.dry', 'help.dry'], ['mode.run', 'help.run'], ['gather', 'help.gather_text']]
    .map(([key, text]) => ({ kind: 'help', key, text })),
  ...['what', 'tools', 'progress', 'origin', 'watch', 'shares', 'sizes', 'pool', 'schedule', 'letgo'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['import.title', 'import.help'], ['setup.key', 'setup.help_key'], ['setup.mapping', 'setup.help_mapping'], ['setup.users', 'setup.help_users'],
    ['setup.scope', 'setup.help_scope'], ['setup.more', 'setup.help_more'], ['setup.save', 'setup.help_save']]
    .map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.emby = { setState: (s) => { state = s; }, sizesLine, shareSection, poolView, poolHay, plainWords, poolSection,
    letGoPart, letGoView, letGoDoneLines, letGoNotice, runSummary, moverNotice, moverOk, moverOffDialog, gatherSettingsDialog, gatherRunDialog, gatherCacheWords,
    progressPanel, setProgress: (p, why) => { progress = p; stopping = why || null; }, etaText, tookText };
}
})();
