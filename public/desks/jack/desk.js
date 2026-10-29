/* Jack Emby — the intern and Emby fan ("check Emby"). He looks after two
   tools that ship with the office: EmbyCache (what people are about to watch
   onto the fast pool, back to its disk once watched) and the media gather
   (the files of a film or series folder together on one array disk). He sets
   them up (#/jack/setup), runs and schedules them and shows what they do.
   The agent part lives in agent/desks/jack.php. */
(() => {
'use strict';

const ID = 'jack';
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
// «Ready on the pool» (words, the library select kept as jack.pool.pick, «only new») and «Recent runs» (words; the tile
// «Last run» filters it — the switcher's, never kept)
let poolBar = null;
let runsBar = null;
let dom = {};                 // the lists of the last drawing their bars redraw in place (runs, poolBox)
let page = 'main';            // main | setup
let outTimer = null;
// the live panel while EmbyCache runs for real (agent/desks/jack-progress.php): the part «progress», asked every 10 s
// while such a run goes (never otherwise); his state carries the same as of its look
const PROGRESS_POLL = 10000;
let progress = null;          // the panel's numbers (bars, bracket, the file being copied) — null: still planning
let progressAt = 0;           // their time (the server's clock): the newer of the part and his state wins
let stopping = null;          // «Stop after this file» asked (why: user, mover) — the button grey, «Stopping after this file …»
let progTimer = null;
let progAsked = 0;            // when the part was last asked for (this browser's clock — never compared with the server's)
let progBox = null;           // the panel on the page, redrawn in place
let nightNow = null;          // the night run with Ms. Moverelli going for real (agent: jackNightNow()) — her card's phases on his page
let watching = [];            // who is watching what while a real run goes (the job's look once a minute): [{name, items}] per person
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
  if (typeof s.time === 'number' && s.time >= progressAt) {
    progress = s.progress || null;
    stopping = s.stopping || null;
    watching = Array.isArray(s.watching) ? s.watching : [];
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
/**
 * EmbyCache only looks (2026-10-22: «I'm laying things out» during a 5-s «What's next»): a report or a dry run going —
 * its mode, else null. Nothing is laid out: the bubble, the notice and «Last run» say what it is.
 */
const lookRun = () => {
  const j = state && state.jobs && state.jobs.embycache;
  return j && j.running && ['report', 'dry'].includes(j.mode) ? j.mode : null;
};
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
/**
 * While no run shows: once a minute (his poll) the cheap part «progress» — a run or the joint run started meanwhile loads
 * his state anew at once, so its panel shows and «Start…» goes off within a minute, not after his state's five (M18)
 */
function peekRun() {
  if (!view || page !== 'main' || !state || liveRun() || !Office.agent.running) return;
  if (typeof document !== 'undefined' && document.hidden) return;
  Office.loadState(ID, { part: 'progress' }, (j) => {
    const p = j && j.ok ? j.part : null;
    if (p && (p.running || p.night) && typeof p.time === 'number' && p.time > (state.time || 0) && !liveRun()) load(true);
  }).catch(() => {});
}
function tookProgress(j) {
  const p = j && j.ok ? j.part : null;
  if (!p || typeof p.time !== 'number' || p.time < progressAt) { scheduleProgress(); return; }
  if (!p.real && !p.night) { progressAt = p.time; nightNow = null; load(true); return; }     // the run ended: his state anew — the panel goes
  // the night's steps change who works: her step over, his starting (or the other way) — his state anew for the rest
  // (who and phase only: inside the night his part is real by the night's word, his job's marker may lag — 2026-10-15)
  const was = nightNow ? `${nightNow.who}|${nightNow.phase}` : '';
  progress = p.progress || null;
  stopping = p.stopping || null;
  watching = Array.isArray(p.watching) ? p.watching : [];
  nightNow = p.night || null;
  if (nightNow && (`${nightNow.who}|${nightNow.phase}` !== was)) { progressAt = p.time; load(true); return; }
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

/** How a share suits EmbyCache with this pool (same rules as jackShareFit() in the agent) */
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
const jackSched = () => (night() ? night().schedule : (state.schedules || {}).embycache) || {};
/** Tonight's plan in his words, pure: what he gives back, what of the downloads goes, what he fetches, what waits */
function tonightText(p) {
  // her limit X 0: the joint run measures no pool — she moves only by age (never «the pool isn't measured», M12)
  const mo = herRules();
  if ((!p || !p.measured) && mo && !(mo.x > 0)) return T('night.no_limit');
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
  if (error && error.key === 'jack_watching') {
    box.append(watchList((error.params || {}).who), el('p', '', T('watch.no_override')), el('p', '', endSessionText('watch.end_then_start')));
  }
  box.style.display = '';
  box.scrollIntoView({ block: 'nearest' });     /* reveal */ // the error line inside its dialog
}

/** One line about how a run went, from the tool's status */
function runSummary(r) {
  const s = r.status || r;
  if (r.result === 'refused') {
    return T('refused', { why: Office.errorText({ key: r.why, params: { detail: r.detail || '', shares: r.shares || '', file: r.file || '', schedule: r.schedule || '', name: r.name || '' } }, ID) }) + whoText(r.who)
      + (Number(r.times) > 1 ? ' · ' + T('refused_times', { n: Number(r.times) }) : '');
  }
  if (r.result === 'skipped') {
    return T(r.why === 'jack_mover_running' ? 'skipped_mover_summary' : 'gather_skipped_summary', { min: Math.round((r.waited || 0) / 60) }) + whoText(r.who);
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
  // stopped after the file it was on: Unraid's mover started (jack-mover.php), or asked on his panel («Stop after this file»)
  const stopped = s.result === 'stopped' ? T(r.why === 'user' ? 'stopped_user_summary' : 'stopped_mover_summary') + ' · ' : '';
  // everything back to the array when he was let go (jack-letgo.php)
  if ((s.mode || r.mode) === 'release') return stopped + T('release_summary', { back: c.done || 0, planned: c.planned || 0, left: s.protected || 0 });
  const key = (s.mode || r.mode) === 'run' ? 'run_summary' : 'dry_summary';
  const files = (n) => (key === 'dry_summary' ? nOf('files', n) : n || 0);     // «would bring 1 file back»; the run's «1 of 3» stays a number
  const lost = failedText(s);
  return stopped + T(key, { back: c.done || 0, back_planned: files(c.planned),
    fill: f.done || 0, fill_planned: files(f.planned), fill_size: fmt.size(f.bytes_planned || 0) }) + (lost ? ' · ' + lost : '') + budgetNote(s);
}
/**
 * The files a run could not lay out or bring back (MediaCache's `failed`, per file — its log has two error lines for one;
 * an older status has none: nothing said), pure: «2 files not laid out · 1 not brought back», why when another program
 * deleted their half copies; '' when none
 */
function failedText(s) {
  const f = s && s.failed;
  if (!f || typeof f !== 'object') return '';
  const n = (v) => (Number.isInteger(v) && v > 0 ? v : 0);
  const lost = [n(f.fill) ? T('failed.fill', { n: n(f.fill) }) : '', n(f.back) ? T('failed.back', { n: n(f.back) }) : ''].filter(Boolean).join(' · ');
  // someone else deleted their half copies (a sync with --delete into the share): said why, in plain words
  return lost && n(f.vanished) ? `${lost} — ${T('failed.vanished', { n: n(f.vanished) })}` : lost;
}
/**
 * What waits beyond the budget (MediaCache's `not_in_budget`, USO 2026-10-16; an older status has none: nothing said), pure:
 * nothing new planned — «Budget full — nothing new: 12 films and 63 episodes (212 GB) still wait; more budget fetches more»;
 * some fetched — the same as «more waits». '' when nothing waits.
 */
function budgetNote(s) {
  const b = s && s.not_in_budget;
  if (!b || !(Number(b.items) > 0)) return '';
  const films = Number(b.films) || 0;
  const eps = Number(b.episodes) || 0;
  const what = films && eps ? T('budget.both', { films: nOf('films', films), episodes: nOf('episodes', eps) })
    : films ? nOf('films', films) : eps ? nOf('episodes', eps) : nOf('items', b.items);
  const none = !(Number((s.fill || {}).planned) > 0);
  return ' · ' + T(none ? 'budget.full' : 'budget.more', { n: films + eps || Number(b.items), what, size: fmt.size(Number(b.bytes) || 0) });
}

/** Unraid's mover as the agent sees it (jack-mover.php) — an older agent's state has none: nothing refused, nothing said */
const mover = () => (state && state.mover) || null;
const moverOk = () => { const m = mover(); return !m || !!m.ok; };
/** Why real runs don't go now, in words (the agent's refusal) */
function moverWhy(m) {
  // Ms. Moverelli's ini without his list: her shares named (jack-mover.php jackMoverelliUncovered())
  const mo = m.why === 'moverelli_list' ? (m.moverelli || {}) : null;
  const schedule = m.why === 'tuning_schedule' ? (m.tuning || {}).cron : m.schedule;
  const t = m.tuning || {};
  const name = t.name || 'Mover Tuning';      // the other mover found (an older agent: Mover Tuning)
  return Office.errorText({ key: 'jack_mover_' + m.why, params: { file: (mo && mo.file) || '', shares: ((mo && mo.uncovered) || []).join(', '), schedule: schedule || '', ...(m.why === 'tuning_schedule' ? { name } : {}) } }, ID);
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
 * ⟦Disabled⟧, ⟦Move now⟧ by hand stays possible, a run of his stops when the mover starts — then jack.mover_off
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

/** The scheduled joint run waiting (state.waiting.night): for Mr. Backupsy's local part, or for Unraid's mover */
function nightWaitLine(w) {
  return el('div', 'callout', w.why === 'backup' ? T('night.wait_backup', { since: fmt.time(w.since) })
    : T('night.wait_mover', { since: fmt.time(w.since), next: fmt.time(w.next), until: fmt.time(w.until) }));
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

// greet.5 «Ms. Moverelli says I may touch my libraries now» only once his media servers and libraries are set up
// (2026-10-18: a bubble is true right now — not on a server without a media server, e.g. with Docker off)
const greetOk = (key) => key !== 'greet.5' || !!(state && state.configured && state.emby && state.emby.length);

/** Docker didn't answer (his look: houseDockerState()): his media servers can't be seen — never «no media server» (T36) */
const dockerDown = () => !!state && (state.docker === 'off' || state.docker === 'silent');

function bubbleText() {
  if (!state) return T('bubble.loading');
  const intro = T('bubble.intro');
  if (dockerDown()) return `${intro} ${T('bubble.docker_down', { docker: Office.t(`common.docker_${state.docker}`) })}`;
  // set up, his servers are in his settings (one may live on another machine): «no media server» only before that
  if (!state.emby.length && !state.configured) return `${intro} ${T('bubble.no_emby')}`;
  if (!state.python) return `${intro} ${T('bubble.no_python')}`;
  if (!state.configured) return `${intro} ${T('bubble.setup')}`;
  if (state.jobs.gather.running) return T('bubble.gathering');
  if (state.jobs.embycache.running) return T(lookRun() ? `bubble.running_${lookRun()}` : 'bubble.running');
  if (state.gather.waiting) return T(state.gather.waiting.why === 'mover' ? 'bubble.mover_waiting' : 'bubble.gather_waiting');
  if (!moverOk()) return T('bubble.mover_dry');
  const c = state.cache;
  const parts = [c.files ? T('bubble.on_pool', { n: c.files, size: fmt.size(c.bytes) }) : T('bubble.nothing_yet')];
  if (!jackSched().enabled) parts.push(T('bubble.no_schedule'));
  return parts.join(' ');
}

// ------------------------------------------------------------------ main view
/*
 * His page (tiles v2, CLAUDE.md «Tiles»): a tile is a section. The row — «Last run», «Schedules», «Pool», «Shares»,
 * «Consolidate folders» — is the page's table of contents; under it stands the chosen tile's section alone, or, nothing
 * chosen, every section in the row's order. The start tile is «Pool» (what one comes to him for: what lies ready).
 * «Last run» is a filter tile: «Recent runs» with only EmbyCache's (never kept). Hints stay above the row at every
 * click (the callouts, the run's callout, the live panel, the line «How I work» with the scope), the tools' versions and
 * the credit under all. Two pages as tabs: «Overview» and «Set up» (#/jack/setup).
 */
const PAGES = () => [{ id: '', text: Office.t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];
/** Set up (or not known yet — the search's places): the pool and the shares have tiles only then */
const configured = () => !state || !!state.configured;
let hintsBox = null;                // the hints under the row (render())
const sw = Office.sections({
  id: ID,                          // kept as jack.tile, routes #/jack/<tile>
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
    [T('gather'), T('help.gather_text')],
    [T('help.watch'), T('help.watch_text')],
    [T('help.shares'), T('help.shares_text')],
    [T('help.sizes'), T('help.sizes_text')],
    [T('help.pool'), T('help.pool_text')],
    [T('help.schedule'), T('help.schedule_text')],
    [T('help.letgo'), T('help.letgo_text')],
  ]));
  if (!state) return;

  // hints, no sections: they stay at every click — under the row (the house rule: tiles right under the head)
  const hints = [];
  if (dockerDown()) hints.push(el('p', 'callout warn', T('notice.docker_down', { docker: Office.t(`common.docker_${state.docker}`) })));
  else if (!state.emby.length && !state.configured) hints.push(el('p', 'callout warn', T('notice.no_emby')));
  if (!state.python) hints.push(el('p', 'callout warn', T('notice.no_python')));
  const foreign = state.foreign.filter((f) => f.enabled);
  if (foreign.length) hints.push(el('p', 'callout warn', T('notice.foreign', { where: foreign.map((f) => f.where).join(', ') })));
  const back = letGoNotice();
  if (back) hints.push(back);
  const mv = state.configured ? moverNotice() : null;
  if (mv) hints.push(mv);
  const wait = (state.waiting || {}).embycache;
  if (wait) hints.push(moverWaitLine(wait));
  // the joint run with Ms. Moverelli waiting on its schedule (an older agent: no such key) — truth review M17
  const nw = night() ? (state.waiting || {}).night : null;
  if (nw) hints.push(nightWaitLine(nw));
  if (state.old_clone) hints.push(el('p', 'callout', T('notice.old_clone')));
  // his list not written yet (2026-10-29): never made empty by a look — his first real run writes it, she waits meanwhile
  if (state.configured && state.list === 'pending') hints.push(el('p', 'callout', T('notice.list_pending')));
  if (running()) {
    const look = state.jobs.gather.running ? null : lookRun();
    const p = el('p', 'callout', T(state.jobs.gather.running ? 'notice.gathering' : look ? `notice.running_${look}` : 'notice.running') + ' ');
    // a real EmbyCache run: its panel right under this has the raw output; a look: its output, nothing to watch
    if (!realRun()) {
      p.appendChild(button(look ? T('show_output', { mode: T('mode.' + look) }) : T('show_output_now'), 'small',
        () => showOutput(state.jobs.gather.running ? 'gather' : 'embycache', true))).disabled = !Office.agent.running;
    }
    hints.push(p);
  }
  if (!state.configured) {
    const p = el('p', 'callout', T(noServer() ? 'notice.no_server' : 'notice.setup') + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    p.appendChild(document.createTextNode(' '));
    p.appendChild(button(T('import.open'), 'small plain', importDialog)).disabled = !Office.agent.running;
    hints.push(p);
  }
  // a real run going: the live panel under the row (a hint — it stays at every click)
  progBox = liveRun() ? livePanel() : null;
  if (progBox) hints.push(progBox);
  if (state.configured) hints.push(scopeLine());
  root.appendChild(sw.el);
  // one box in the switcher (it lives as long as the page; core.js redraws only the row and the body), filled anew
  if (!hintsBox) hintsBox = sw.el.insertBefore(el('div', 'jo-hints'), sw.body);
  hintsBox.innerHTML = '';
  hints.forEach((n) => hintsBox.appendChild(n));
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
    el('span', 'jo-scope-text', `${set.cache_budget ? T('budget', { size: fmt.size(budgetGb(set.cache_budget) * 1073741824) }) : amount.join(' · ')} — ${scope}`));
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
  line.append(el('span', '', `MediaCache ${ver}`), el('span', '', `${T('gather')} ${state.versions.gather || '?'}`));
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
/**
 * The night with Ms. Moverelli (1.65.0): his status file holds only his last step (the fetch) — his give-back of the first
 * step was gone from the summary. Summed over his steps of the last night (night.json keeps each step's counts), when his
 * last run was that night's; else the status as it is.
 */
function nightStatus(st) {
  const last = state.night && state.night.last;
  const h = (state.history || []).find((r) => r.tool === 'embycache' && !['refused', 'skipped'].includes(r.result));
  const mine = last && Array.isArray(last.steps) ? last.steps.filter((x) => x.who === 'jack' && (x.cleanup || x.fill)) : [];
  if (!st || st.mode !== 'run' || !h || h.by !== 'night' || mine.length < 2) return st;
  const sum = (k) => mine.reduce((acc, x) => {
    const c = x[k] || {};
    ['planned', 'done', 'bytes_planned', 'bytes_done'].forEach((f) => { acc[f] = (acc[f] || 0) + (Number(c[f]) || 0); });
    return acc;
  }, {});
  return { ...st, cleanup: sum('cleanup'), fill: sum('fill') };
}

function resultBox() {
  const st = nightStatus('last_status' in state ? state.last_status : state.last);
  if (!st || !st.result) return null;
  const box = el('div', 'box jo-results');
  // why it stopped lies in his list of runs (the run's own entry: the newest EmbyCache run that started)
  const h = (state.history || []).find((r) => r.tool === 'embycache' && !['refused', 'skipped'].includes(r.result));
  box.appendChild(el('div', '', runSummary({ tool: 'embycache', mode: st.mode, status: st, result: st.result, why: h && h.result === st.result ? h.why : null })));
  if (st.incomplete) box.appendChild(el('div', 'warn-text', T('incomplete')));
  // a trial (What's next, a dry run) writes nothing, his list neither — it comes from a real run (2026-10-29)
  if (['report', 'dry'].includes(st.mode)) box.appendChild(el('div', 'role', T('trial_no_list')));
  return box;
}

/**
 * «Last run» from his list of runs (2026-10-11 — never from status.json, which every start removes and a report writes
 * over): the newest real run (run, release) is the value, else the newest of any mode; a newer report or dry run only
 * named in the sub line. Refused and skipped starts didn't run. The agent's jackLastRun() picks the same. Pure (no DOM).
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
  const look = lookRun();
  if (look) sub.push(T('last.looking', { mode: T('mode.' + look), time: fmt.time(job.started) }));   // a look: the last run stays
  if (job.running && !look) {
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
    const lost = failedText(st);
    if (lost) sub.push(lost);
    if (newer) sub.push(T('last.newer', { mode: T('mode.' + (newer.mode || 'dry')), when: fmt.relative(newer.finished || newer.started) }));
    alert = !!(st.errors || (main.result && main.result !== 'ok'));
  }
  return Office.stat({ label: T('last_run'), value, sub, alert, tip: sub.join('\n'), ...a });
}

/** The two schedules in one tile: «2 active · next 04:30» / «off»; its section: their list */
function plansTile(a) {
  const sc = { ...(state.schedules || {}), embycache: jackSched() };
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
  const out = nightOutLine();
  if (out) s.appendChild(out);
  return s;
}
/**
 * I wasn't in the last night run (my step refused: not set up, folders not consolidated yet …): Ms. Moverelli did her part
 * alone — a callout with my reason (my refusal's text), null when I was in it
 */
function nightOutLine() {
  const l = night() && night().last;
  if (!l || !l.jack_out || !l.started) return null;
  return el('p', 'callout warn', T('night_out', { when: fmt.relative(l.finished || l.started), why: Office.errorText({ key: l.jack_out, params: {} }, ID) }));
}
function plansList() {
  const box = el('div', 'box jo-plans');
  ['embycache', 'gather'].forEach((job) => {
    const sc = job === 'embycache' ? jackSched() : (state.schedules || {})[job] || {};
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
  // what he holds against his GB budget (2026-10-14: his budget comes first — no «half» of the pool any more)
  const held = pool && pool.held != null;
  const gb = state.configured ? budgetGb((state.settings || {}).cache_budget) : 0;
  const pct = pool ? fmt.number(pool.free_percent, 1) : '';
  const sub = !pool ? '' : !held ? T('pool_tile.free', { pct, min: pool.min_free ?? '?' })
    : gb > 0 ? T('pool_tile.held', { used: fmt.size(pool.held), budget: fmt.size(gb * 1073741824), pct }) : T('pool_tile.held_plain', { used: fmt.size(pool.held), pct });
  const tip = [pool ? T('pool_sub', { pool: pool.path, free: fmt.size(pool.free), min: pool.min_free ?? '?' }) : '', ...savedRule(), ...roomWords(),
    c.listed_at ? T('pool_tile.as_of', { when: fmt.relative(c.listed_at) }) : ''].filter(Boolean).join('\n');
  // never a break between a number and its unit («671 GB» stays on one line)
  const nb = (t) => String(t).replace(/(\d) /g, '$1\u00a0');
  return Office.stat({ label: T('tile.pool'), value: c.files ? T('pool_tile.value', { files: nb(nOf('files', c.files)), size: nb(fmt.size(c.bytes)) }) : T('pool_tile.none'),
    sub, tip, alert: !!(pool && pool.min_free != null && pool.free_percent < pool.min_free), ...a });
}

/** His rule as saved (ruleTexts() on his settings' budget; her «not set up» is roomWords()') — the pool tile's tip and the pool's section */
function savedRule() {
  const gb = state && state.configured ? budgetGb((state.settings || {}).cache_budget) : 0;
  return gb > 0 ? ruleTexts(gb).filter((t) => t !== T('room.none')) : [];
}

/**
 * What Ms. Moverelli gives him (2026-10-13, the pool simply — agent/lib/poollevel.php): she keeps the pool below X, he may
 * fill up to Y — what he holds, the films that wait for room, what he gives back; without her
 * limit: what he keeps free, or that she isn't set up. [line, …] in his words (pure on state.pool).
 */
function roomWords() {
  const pool = state && state.pool;
  if (!pool) return [];
  const mo = pool.moverelli;
  if (!mo) return [T('room.none')];
  if (!(mo.x > 0)) return [T('room.own', { min: pool.min_free ?? '?' })];
  if (pool.held == null) return [T('room.plain', { x: mo.x, y: mo.y })];
  const out = [T('room.text', { x: mo.x, y: mo.y, used: fmt.size(pool.held) })];
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
const badShares = () => (state.shares || []).filter((x) => !['ok', 'array_only'].includes(x.fit) || (x.root === false && !x.auto) || x.dataset === false || (x.plain_disks || []).length > 0).length;
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
 * The live panel (agent/desks/jack-progress.php): the bracket over everything (done of planned, speed, time left), under
 * it «back to the array» (when the run moves files back) and a bar per Emby user with the time their part still takes;
 * the file being copied small under its bar, with its reason. Before the first file: «working out what goes where».
 */
function progressPanel() {
  const box = el('div', 'box jo-prog running');
  const head = el('div', 'jo-prog-head');
  head.append(el('span', 'spin'), el('span', '', T('progress.title')));
  box.appendChild(head);
  progressRows(box);
  const who = watchingBox();
  if (who) box.appendChild(who);
  const foot = el('div', 'jo-prog-foot');
  if (stopping) foot.appendChild(el('span', 'jo-prog-stopping', T('progress.stopping')));
  foot.appendChild(link(T('progress.raw'), () => showOutput('embycache', true)));
  const stop = button(T('progress.stop'), 'small plain', () => stopAsk(stop));
  stop.disabled = !!stopping || !Office.agent.running;
  foot.appendChild(stop);
  box.appendChild(foot);
  return box;
}

/**
 * The panel's bars into $box: the bracket, «back to the array», one per person, the files being copied — from $p (his live
 * view, jack-progress.php; default: the one on his page). Lent to Ms. Moverelli's page (core.js Office.jackRows): her
 * night card shows the same while his step runs (o.noFile: without the files in flight — her card has one line for it).
 * Several copies at once (2026-10-29): each bar has its files in flight under it (`in_flight`), each with the wave; an
 * older agent's view has the one `current` under the bar marked `here`.
 */
function progressRows(box, p = progress, o = {}) {
  if (o.noFile && p) p = { ...p, current: null, files: [], back: p.back && { ...p.back, in_flight: [] }, users: (p.users || []).map((u) => ({ ...u, in_flight: [] })) };
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
    // a file in flight no bar holds (an older agent: the one `current` no bar is marked for)
    const held = [p.back, ...(p.users || [])].filter(Boolean);
    const placed = new Set(held.flatMap((c) => inFlight(c, p)));
    const loose = Array.isArray(p.files) ? p.files.filter((f) => !held.some((c) => (c.in_flight || []).some((g) => g.rel === f.rel && g.phase === f.phase)))
      : p.current && !placed.size ? [p.current] : [];
    loose.forEach((f) => box.appendChild(progFile(f)));
  }
}

Office.jackRows = (box, p, o) => progressRows(box, p || null, o || {});

/** His step of the night at work (give back, fetch) — by the night's word, from the moment it starts (2026-10-15) */
const jackStep = (n) => !!(n && n.who === 'jack' && ['free', 'fill'].includes(n.phase));

/** What follows live over his row: the night run's card while it goes, else EmbyCache's panel */
function livePanel() {
  return nightGoing() ? nightPanel() : progressPanel();
}

/**
 * The night run with Ms. Moverelli on his page (2026-10-13): her run card's head and phases (core.js Office.nightCard —
 * «Joint run», since when, the phase going), under it what is at work — EmbyCache giving back or fetching: his bars per
 * person; she moving: «Ms. Moverelli is moving <share> right now — 5 of 12 files», her fill «… the pool's oldest files
 * (now from <share>)» (her status, no look of its own);
 * between their steps: she measures the pool. «Stop after this file» stops the whole night (jack.stop → nightStopAsk()).
 */
function nightPanel(now) {
  const n = nightNow;
  const asked = stopping || n.stopping || null;
  const card = Office.nightCard(n, { T: MT, now: now ?? Date.now() / 1000, stopping: asked, cls: 'jo-night' });
  const jack = jackStep(n);
  if (jack) {
    const rows = el('div', 'jo-prog jo-night-prog');
    progressRows(rows);
    const who = watchingBox();
    if (who) rows.appendChild(who);
    card.appendChild(rows);
  } else {
    const her = n.who === 'her' ? n.her : null;
    // her fill: the pool's oldest files, no counts of a share (T31); her own step: that share — never «downloads» (M16)
    const line = el('div', 'card-line jo-night-her', (her ? her.fill ? T('night.her_oldest', { share: her.share })
      : T('night.her_moving', { share: her.share, done: Number(her.done) || 0, files: nOf('files', her.files) })
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

/**
 * Who is watching what (2026-10-29): per person what plays now on which server, since when (as the job's look once a
 * minute saw it — what plays stays where it is); read only. Nobody watching: nothing (null).
 */
function watchingBox(list = watching) {
  const people = (Array.isArray(list) ? list : []).filter((p) => p && Array.isArray(p.items) && p.items.length);
  if (!people.length) return null;
  const box = el('div', 'jo-watch');
  box.appendChild(el('div', 'jo-watch-head', T('watching.title')));
  const ul = el('ul', 'jo-watch-list');
  people.forEach((p) => {
    const li = el('li', 'jo-watch-person');
    li.appendChild(el('span', 'jo-watch-name', p.name || T('watch.someone')));
    p.items.forEach((x) => {
      const what = [T('watching.on', { server: x.server || '?' })];
      if (typeof x.since === 'number') what.push(T('watching.since', { time: fmt.time(x.since) }));
      if (x.paused) what.push(T('watching.paused'));
      const item = el('span', 'jo-watch-item');
      item.append(el('span', 'jo-watch-title', x.title || '?'), el('span', 'jo-watch-facts', what.join(' · ')));
      li.appendChild(item);
    });
    ul.appendChild(li);
  });
  box.appendChild(ul);
  return box;
}

/** «Stop after this file»: EmbyCache ends after the file it is on (jack.stop — the mover guard's stop request, why user) */
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
  inFlight(c, p).forEach((f) => row.appendChild(progFile(f)));
  return row;
}

/** The files in flight under a bar: its own list (2026-10-29), or an older agent's one `current` under the bar marked `here` */
function inFlight(c, p) {
  if (Array.isArray(c.in_flight)) return c.in_flight;
  return c.here && p.current ? [p.current] : [];
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

/** A file being copied (the wave on each): its path, how much of it is there, why it comes («Continue watching «X – S01E02»») */
function progFile(cur) {
  const line = el('div', 'jo-prog-file running');
  line.append(el('span', 'spin'), el('span', 'jo-prog-rel', cur.rel || ''), el('span', '', T('progress.bytes', { done: fmt.size(cur.done || 0), size: fmt.size(cur.size || 0) })));
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
    if (x.root === false && x.auto) meta.appendChild(chip(T('root_auto', { pool }), 'quiet', T('root_auto_tip', { share: x.share, pool })));
    else if (x.root === false) meta.appendChild(chip(T('root_missing', { pool }), 'danger', T('root_missing_tip', { share: x.share, pool })));
    if (x.dataset === false) meta.appendChild(chip(T('root_plain'), 'warn', T('root_plain_tip', { share: x.share, pool })));
    const plainDisks = Array.isArray(x.plain_disks) ? x.plain_disks : [];
    if (plainDisks.length) meta.appendChild(chip(T('disk_plain', { disks: plainDisks.join(', ') }), 'warn', T('disk_plain_tip', { share: x.share, disks: plainDisks.join(', ') })));
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

/** The gather: an optional helper (2026-10-16) — fewer disks wake when films go back and forth; no run waits for it */
function gatherSection() {
  const g = state.gather;
  const s = section(T('gather'), T('gather_sub'), g.ready ? chip(T('gather_ready'), 'ok') : chip(T('gather_not_ready'), 'quiet', T('gather_not_ready_tip')),
    { place: 'gather' });
  const set = g.settings;
  if (!set || !set.shares.length) {
    const p = el('p', 'role', T('gather_unset') + ' ');
    p.appendChild(button(T('gather_cfg_open'), 'small', gatherSettingsDialog)).disabled = !Office.agent.running;
    s.appendChild(p);
    return s;
  }
  if (g.waiting && g.waiting.why === 'mover') {
    s.appendChild(moverWaitLine(g.waiting));
  } else if (g.waiting) {
    const c = el('div', 'callout');
    c.append(el('div', '', T('gather_waiting', { next: fmt.time(g.waiting.next), until: fmt.time(g.waiting.until) })), watchList(g.waiting.who),
      el('div', '', endSessionText('watch.end_then_wait')));
    s.appendChild(c);
  } else {
    const lastRun = (state.history || []).find((r) => r.tool === 'gather' && r.mode === 'run');
    if (lastRun && lastRun.result === 'skipped' && lastRun.why === 'jack_mover_running') {
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
    bar.appendChild(button(T('show_log') + ' · MediaCache', 'small plain', () => showLog('embycache')));
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
/** Who started a run; inside the joint run its record's `from` (Ms. Moverelli's schedule or a page) and «in the joint run» */
const runBy = (r) => r.by === 'night' && ['office', 'schedule'].includes(r.from) ? `${T('by.' + r.from)} · ${T('by.night')}` : T('by.' + r.by);
const runHay = (r) => [T('tool.' + r.tool), T('mode.' + r.mode), T('result.' + (r.result || 'failed')), fmt.date(r.started), runBy(r), runSummary(r)];
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
    meta.append(chip(T('result.' + (r.result || 'failed')), cls, r.exit === 0 ? T('exit_ok') : r.exit != null ? T('exit_code', { n: r.exit }) : ''),
      el('span', '', fmt.date(r.started)), el('span', '', runBy(r)));
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
const poolHay = (g) => [poolName(g), g.title, g.share];

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
  // his rule in plain sentences (as saved — the same as on «Amount» in his setup), then what Ms. Moverelli gives him
  savedRule().forEach((line) => s.appendChild(el('p', 'role jo-slice', line)));
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
  const free = moverOk();
  const box = radioList('jo-mode', [
    ['dry', T('mode.dry'), T('mode_hint.dry')],
    // with Ms. Moverelli his real run is the joint run — said as such (truth review T38)
    ['run', T('mode.run'), !free ? T('mode_hint.run_mover') : T(night() ? 'mode_hint.run_joint' : 'mode_hint.run'), !free],
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

// ------------------------------------------------------------------ setup (#/jack/setup)
/*
 * Everything EmbyCache's own setup asks (embycache_setup.py), plus the choices it leaves to its settings file — on the
 * «Set up» tab as the office's tiles (2026-10-14, the owner: the wizard and the pool simulator went; back to the standard,
 * clear sentences instead of a simulator): a row with a tile per part — the server, the libraries and their shares, the
 * amount (a budget in GB, the hard cap), the people (a budget each, at least MIN_PERSON_GB), «More» (the way back,
 * reading from the array, «Set up from scratch») and, once he is set up, taking over an earlier install. A click shows
 * that part alone under the row, nothing chosen = every part in order; kept as jack.setup.tile, a part's route
 * #/jack/setup/<tile> (the wizard's step routes lead to the tile that holds them now). «Save» under all: it connects a
 * server first when its address or key is new, and stays grey with the reason beside it while the budget doesn't hold.
 * Saving always writes budget mode. The form lives across every drawing (form): a click draws the page anew from it —
 * the typed values stay, the focus comes back to the control clicked (focusSpot()/focusBack()).
 * The gather has its own small settings (gatherSettingsDialog).
 */
let form = null;
/** The media servers MediaCache speaks to (1.65.0): their names as they write themselves */
const SERVER_NAMES = { emby: 'Emby', jellyfin: 'Jellyfin', plex: 'Plex' };
const serverType = (t) => (SERVER_NAMES[t] ? t : 'emby');
/**
 * A new server's row: a container found that isn't in the form yet (its kind, name, address), else an empty Emby. With a
 * kind asked for (a click on its card): only a container of that kind — none here: an empty row of that kind that says so
 * (`noContainer`: it may run on another machine).
 */
function newInstance(taken, want) {
  const c = (state.emby || []).find((x) => !taken.some((i) => i.url === x.url) && (!want || x.type === want)) || null;
  const type = serverType(want || (c && c.type));
  // the kind's own name; a number only for a second one of the same kind («Plex», then «Plex 2»)
  const same = taken.filter((i) => i.type === type).length;
  return { type, servername: c ? c.name : SERVER_NAMES[type] + (same ? ` ${same + 1}` : ''), url: c ? c.url : 'http://', has_key: false, path_mappings: {},
           ...(want && !c ? { noContainer: true } : {}) };
}

function initForm() {
  const set = state.settings || {};
  const inst = (set.instances || []).length ? set.instances : [newInstance([])];
  const vu = set.valid_users || [];
  const budgets = {};
  if (!Array.isArray(vu)) for (const [id, o] of Object.entries(vu)) if (o && o.budget) budgets[id] = o.budget;
  form = {
    instances: inst.map((i) => {
      const maps = { ...(i.path_mappings || {}) };
      const skip = new Set(Object.keys(maps).filter((p) => maps[p] === ''));     // '' = deliberately not cached
      skip.forEach((p) => delete maps[p]);
      return { type: serverType(i.type), servername: i.servername, url: i.url, api_key: '', has_key: !!i.has_key, mappings: maps, skip, server: null, libraries: [], users: [] };
    }),
    chosenLibs: new Set(set.libraries || []),
    chosenUsers: new Set(Array.isArray(vu) ? vu : Object.keys(vu)),
    forgotten: new Set(),          // the people of a server removed here: never saved again
    budgets,
    values: {
      cache_path: set.cache_path && state.pools.includes(set.cache_path) ? set.cache_path : (state.pools[0] || ''),
      cache_budget: budgetGb(set.cache_budget) > 0 ? String(budgetGb(set.cache_budget)) : '',
      movie_share_percent: set.movie_share_percent ?? 50,
      // episodes ahead per series: inside the budget (an old count-mode setting gives its number — the update moves it over too)
      max_episodes_per_series: set.max_episodes_per_series || (set.cache_budget ? 0 : (set.number_episodes ?? 3)),
      max_resume_movies: set.max_resume_movies ?? set.max_resume_items ?? 10,
      max_resume_series: set.max_resume_series ?? set.max_resume_items ?? 10,
      max_favorite_series: set.max_favorite_series ?? 10,
      use_next_up: set.use_next_up ?? true,
      array_source: set.array_source || 'user0',
      parallel_per_disk: Number.isInteger(set.parallel_per_disk) ? set.parallel_per_disk : 1,
    },
  };
  formStart = formKey();
}

/** Docker path -> host path: from the server's container's own mounts (longest match), else /data|/media -> /mnt/user */
function suggestMapping(path, inst) {
  const c = (inst && (state.emby || []).find((x) => x.url === inst.url)) || (state.emby || []).find((x) => x.type === (inst && inst.type)) || state.emby[0] || {};
  const mounts = c.mounts || {};
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

/** «Save» under all: grey with the reason beside it while the setup can't be saved (setupBlocked()), live */
let saveGate = null;

function renderSetup() {
  if (!view) return;
  const root = view;
  const spot = focusSpot();         // the control a click redraws the page from keeps the focus (focusBack())
  root.innerHTML = '';
  // the tabs say where one is (no «← Back», no head button)
  root.appendChild(deskHead());
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('import.title'), T('import.help')],
    [T('setup.key'), T('setup.help_key')],
    [T('setup.mapping'), T('setup.help_mapping')],
    [T('setup.users'), T('setup.help_users')],
    [T('setup.scope'), T('setup.help_scope')],
    [T('setup.more'), T('setup.help_more')],
    [T('share.title'), T('share.help')],
    [T('setup.save'), T('setup.help_save')],
  ]));
  saveGate = null;
  if (!state) return;
  if (!form) initForm();
  // every change is said by «Save» at the bottom (setupBar() → saveGate) — the page tells it at each keystroke and click
  if (!root.dataset.joBar) {
    root.dataset.joBar = '1';
    const bump = () => { if (page === 'setup') setTimeout(setupBar, 0); };
    root.addEventListener('input', bump);
    root.addEventListener('change', bump);
    root.addEventListener('click', bump);
  }
  setTimeout(setupBar, 0);
  // the row of his setup's parts and under it the part chosen (none: every part in order)
  // «Save» right under his own parts: on the whole page between them and the optional «Take over an earlier install»
  // (drawn with that part — importStep()), under one part of his own after it (the slot under the switcher), none under
  // the import alone (it has its own button)
  const sw = setupSwitcher();
  root.appendChild(sw.el);
  saveSlot = el('div', 'jo-save-slot');
  root.appendChild(saveSlot);
  fillSaveSlot(sw.active());
  sw.render();
  focusBack(spot);
}

let saveSlot = null;
/** The slot under the switcher (onPick: before the part is drawn): «Save» under a part of his own; on the whole page with the import part the bar is that part's */
function fillSaveSlot(cur) {
  if (!saveSlot) return;
  saveSlot.innerHTML = '';
  const withImport = setupTileList().some((x) => x.id === 'import');
  if (cur ? cur !== 'import' : !withImport) saveSlot.appendChild(saveBar());
}
/** The import part; on the whole page «Save» over it — the bar ends his own parts, the optional part comes after it */
function importStep() {
  const part = setupStep('import');
  if (!setupSw || setupSw.active()) return part;
  const box = el('div', 'jo-import-part');
  box.append(saveBar(), part);
  return box;
}

/** The save bar under his own parts: «Save», why it is grey (a run going, the budget), else what saving does */
function saveBar() {
  const foot = el('div', 'toolbar jo-save');
  const save = button(T('setup.save'), '', saveSetup);
  const discard = button(T('setup.discard'), 'plain', () => { form = null; renderSetup(); connectSaved(); });
  const why = el('span', 'role jo-save-why');
  const changes = el('span', 'role jo-save-changes');
  foot.append(save, discard, why, changes);
  saveGate = () => {
    const r = running() ? T('setup.save_running') : setupBlocked();
    why.textContent = r || poolChangeWarning() || T('setup.save_hint');
    why.classList.toggle('jo-save-no', !!r);
    save.disabled = !!r || !Office.agent.running;
    const n = formChangeCount();
    discard.hidden = !n;
    changes.hidden = !n;
    changes.textContent = n ? `${T('setup.bar_changes', { n })} (${changedParts().map((k) => T(k)).join(', ')}) ${T('setup.bar_sub')}` : '';
  };
  saveGate();
  return foot;
}

// ---- the setup's parts as tiles (CLAUDE.md «Tiles»)
/** [tile id, lang key (the part's title: the tile's label, its place for the search)] in the parts' order */
const SETUP_TILES = [['servers', 'setup.server'], ['libraries', 'setup.shares'], ['scope', 'setup.scope'], ['users', 'setup.users'],
  ['more', 'setup.more'], ['import', 'import.title']];
/** The wizard's step routes (bookmarks, an old search, 2026-10-14) → the tile that holds that part now ('' = the whole page) */
const OLD_STEPS = { settings: 'scope', simulator: 'scope', summary: '' };
const connected = () => !!form && form.instances.some((i) => i.server);
let setupSw = null;
/** The setup's row and its parts (core.js's section switcher) — one per page life */
function setupSwitcher() {
  if (setupSw) return setupSw;
  setupSw = Office.sections({
    id: `${ID}/setup`,           // the routes #/jack/setup/<tile>
    key: 'jack.setup.tile',
    bodyId: 'jo-setup-steps',
    grid: 'stats jo-setup-tiles',
    default: '',                 // the start: every part
    tiles: () => setupTileList(),
    wants: (sub) => SETUP_TILES.some(([id]) => id === sub),      // a route before his state is in
    onPick: (cur) => fillSaveSlot(cur),                            // «Save» where it belongs for the part chosen
  });
  return setupSw;
}
/** A route's part (#/jack/setup/<part>): a tile's id as it is, a wizard step's id as the tile that holds it now */
const tileOf = (sub) => (Object.prototype.hasOwnProperty.call(OLD_STEPS, sub) ? OLD_STEPS[sub] : sub);

/** The tiles for the switcher: setupTileData() as it stands, each with its part as its section (none before his state) */
function setupTileList() {
  if (!state || !form) return [];
  return setupTileData().map((d) => ({ id: d.id, key: d.key, place: `setup.tile.${d.id}`,
    tile: (a) => Office.stat({ label: T(d.key), value: d.value, sub: d.sub.filter(Boolean), alert: d.alert, ...a }),
    section: () => (d.id === 'import' ? importStep() : setupStep(d.id)) }));
}
/** The parts' heads (the tile's name — its place — and a line under it) */
const SETUP_HEADS = {
  servers: () => section(T('setup.server'), T('setup.server_sub'), { place: 'setup.server' }),
  libraries: () => section(T('setup.shares'), T('setup.shares_sub'), { place: 'setup.shares' }),
  scope: () => section(T('setup.scope'), T('setup.scope_sub'), { place: 'setup.scope' }),
  users: () => section(T('setup.users'), T('setup.users_sub'), { place: 'setup.users' }),
  more: () => section(T('setup.more'), T('setup.more_sub'), { place: 'setup.more' }),
  import: () => section(T('import.title'), T('import.title_sub'), { place: 'import.title' }),
};
/** One part's section: its head and its body */
function setupStep(id) {
  const s = SETUP_HEADS[id]();
  s.dataset.section = id;                // focusSpot()/focusBack() find the control again inside it
  s.appendChild(id === 'servers' ? setupServers() : id === 'libraries' ? setupLibraries() : id === 'scope' ? setupScope()
    : id === 'users' ? setupUsers() : id === 'more' ? setupMore() : importSection());
  return s;
}

/** The people of all connected servers, each once */
const everyone = () => [...new Set(form.instances.flatMap((inst) => (inst.users || []).map((u) => u.id)))];
/** One person over several servers (1.65.0, as MediaCache counts them): the same name ignoring case, spaces and accents */
function personKey(name) {
  return (Office.fold || ((x) => String(x).toLowerCase()))(name || '').replace(/\s+/g, '');   // the office's folding (ß → ss, like Python's casefold)
}
/** The people as persons: [{key, name, ids, servers, admin}] in the servers' order — a person's ids on every server */
function persons() {
  const out = new Map();
  form.instances.forEach((inst) => (inst.users || []).forEach((u) => {
    const k = personKey(u.name) || u.id;
    const p = out.get(k) || { key: k, name: u.name, ids: [], servers: [], admin: false };
    if (!p.ids.includes(u.id)) p.ids.push(u.id);
    if (!p.servers.includes(inst.servername)) p.servers.push(inst.servername);
    p.admin = p.admin || !!u.admin;
    out.set(k, p);
  }));
  return [...out.values()];
}
/** Chosen: any of their ids chosen (settings from before chose per server id) */
const personChosen = (p) => p.ids.some((id) => form.chosenUsers.has(id));
const chosenPersons = () => persons().filter(personChosen);
/** A person's own budget: kept under one of their ids (any — older settings kept it per server id) */
const personBudget = (p) => { const id = p.ids.find((x) => budgetGb(form.budgets[x]) > 0); return id ? form.budgets[id] : ''; };
function setPersonBudget(p, value) {
  p.ids.forEach((id) => delete form.budgets[id]);
  if (value) form.budgets[p.ids[0]] = value;
}
/** Reading from the array as it comes (MediaCache's default), one copy per disk; the way back is always Unraid's move binary (1.65.0) */
const MORE_DEFAULTS = { array_source: 'user0', parallel_per_disk: 1 };

/**
 * His setup's tiles, pure (no DOM): [{id, key, value, sub: [text], alert}] in the parts' order — the servers (how many,
 * how many connected), the libraries (chosen of all, their folders on the pool; red while a chosen one's share isn't set
 * up right), the amount (the budget; red without one), the people (chosen of all; red while someone would get under
 * MIN_PERSON_GB), «More» (as it comes or changed), taking over an earlier install (once he is set up). Before a server is
 * connected (every visit starts so) the libraries and people as saved.
 */
function setupTileData() {
  const v = form.values;
  const on = connected();
  const out = [];
  out.push({ id: 'servers', key: 'setup.server', value: String(form.instances.length),
    sub: [form.instances.some((i) => i.connecting) ? T('setup.connecting') : T('setup.tile.connected', { n: form.instances.filter((i) => i.server).length })], alert: false });
  const libs = libraryList();
  const chosenLibs = libs.filter((l) => form.chosenLibs.has(l.name));
  const folders = on ? chosenFolders() : [];
  const cached = folders.filter((f) => f.share && cacheable(f.share)).length;
  const wrong = folders.filter((f) => f.share && !cacheable(f.share)).length;
  out.push({ id: 'libraries', key: 'setup.shares', value: on ? T('setup.tile.of', { n: chosenLibs.length, total: libs.length }) : String(form.chosenLibs.size),
    sub: [on ? T('setup.tile.folders', { n: cached }) : T('setup.tile.saved'), wrong ? T('setup.tile.wrong', { n: wrong }) : ''], alert: wrong > 0 });
  const total = budgetGb(v.cache_budget);
  out.push({ id: 'scope', key: 'setup.scope', value: total > 0 ? gbSize(total) : '–',
    sub: [total > 0 ? T('setup.tile.budget_line', { pool: String(derivedPool() || v.cache_path || '').replace('/mnt/', '') || '–' }) : T('setup.tile.no_budget')], alert: on && !(total > 0) });
  const people = persons();
  const chosen = on ? people.filter(personChosen).length : form.chosenUsers.size;
  const sp = on ? budgetState() : null;
  out.push({ id: 'users', key: 'setup.users', value: !chosen ? T('setup.tile.everyone') : on ? T('setup.tile.of', { n: chosen, total: people.length }) : String(chosen),
    sub: [on ? '' : T('setup.tile.saved'), sp && sp.total > 0 && sp.bad ? T('setup.tile.too_little', { min: gbSize(MIN_PERSON_GB) }) : ''], alert: !!(sp && sp.total > 0 && sp.bad) });
  const changed = Object.keys(MORE_DEFAULTS).some((k) => v[k] !== MORE_DEFAULTS[k]);
  out.push({ id: 'more', key: 'setup.more', value: T(changed ? 'setup.tile.changed' : 'setup.tile.default'), sub: [], alert: false });
  if (state.configured) out.push({ id: 'import', key: 'import.title', value: T('setup.tile.optional'), sub: [], alert: false });
  return out;
}

/** A part that needs a connected server, before one is: one line saying so (the wave while a saved one connects) */
function notYet() {
  if (form && form.instances.some((i) => i.connecting)) {
    const p = el('p', 'role jo-not-yet', T('setup.connecting') + ' ');
    p.appendChild(el('span', 'spin'));
    return p;
  }
  return el('p', 'role jo-not-yet', T('setup.after_connect', { where: T('setup.server') }));
}

/**
 * Opening «Set up» once he is set up: his saved servers connected by themselves (address and the key kept on the server),
 * so every part shows what it holds at once — a failure says why in the server's box, as «Connect» would
 */
async function connectSaved() {
  if (!state || !state.configured || !form || !Office.agent.running) return;
  const f = form;
  const todo = f.instances.filter((i) => !i.server && i.has_key && needsConnect(i));
  if (!todo.length) return;
  const clean = formKey() === formStart;
  todo.forEach((i) => { i.connecting = true; });
  renderSetup();
  await Promise.all(todo.map((i) => connectInst(i).finally(() => { i.connecting = false; })));
  if (form !== f || !view || page !== 'setup') return;
  if (clean) formStart = formKey();          // what connecting filled in is no change of the user's
  renderSetup();
}

/** The control that has the focus inside a part: its part and its place among the part's controls (the page is drawn anew) */
function focusSpot() {
  const a = typeof document !== 'undefined' ? document.activeElement : null;
  const sec = a && view && view.contains(a) && a.closest ? a.closest('[data-section]') : null;
  if (!sec) return null;
  return { section: sec.dataset.section, i: [...sec.querySelectorAll('input, select, textarea, button')].indexOf(a) };
}
/** … and back to the same control once drawn anew (no scroll: the page stays where it was) */
function focusBack(spot) {
  if (!spot || spot.i < 0 || !view) return;
  const sec = view.querySelector(`[data-section="${spot.section}"]`);
  const x = sec ? sec.querySelectorAll('input, select, textarea, button')[spot.i] : null;
  if (x && document.activeElement !== x) x.focus({ preventScroll: true });
}

// ---- the pool (2026-10-14, the owner): never chosen here — Unraid's share settings decide it: the primary storage of the
// chosen libraries' shares (shareCachePool, the array as secondary). EmbyCache has ONE cache_path: shares on several
// pools → the user picks the pool among them, the folders on the others are left out; a share without a pool as its
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

/** The server: the media server and its connection — and, before he is set up, the offer to take over an earlier install */
function setupServers() {
  const s1 = el('div', 'jo-part-body');
  s1.appendChild(mediaChoice());
  form.instances.forEach((inst, idx) => {
    const box = el('div', 'box jo-instance');
    // the kind is fixed (its card above made the row): its logo and name as the row's head
    const head = el('div', 'jo-instance-head');
    head.append(mediaLogo(SERVER_NAMES[inst.type]), el('b', '', SERVER_NAMES[inst.type]));
    box.appendChild(head);
    const f1 = el('div', 'jo-form');
    const name = input(inst.servername, (v) => { inst.servername = v; });
    const url = input(inst.url, (v) => { inst.url = v.trim(); });
    const key = input('', (v) => { inst.api_key = v.trim(); });
    key.type = 'password';
    key.autocomplete = 'off';
    key.placeholder = inst.has_key ? T('setup.key_kept') : T('setup.key_placeholder');
    f1.append(field(T('setup.name'), name), field(T('setup.url'), url, T('setup.url_hint', { port: inst.type === 'plex' ? 32400 : 8096 })),
      field(T('setup.key'), key, T(inst.type === 'emby' ? 'setup.key_hint' : 'setup.key_hint_' + inst.type)));
    box.appendChild(f1);
    if (inst.noContainer && !inst.server) box.appendChild(el('p', 'callout jo-no-container', T('setup.no_container', { kind: SERVER_NAMES[inst.type] })));
    const bar = el('div', 'toolbar');
    const connect = button(T('setup.connect'), inst.server ? 'small plain' : 'small', async () => {
      connect.disabled = true;
      await connectInst(inst);
      connect.disabled = false;
      renderSetup();
    });
    bar.appendChild(connect);
    if (inst.connecting) {           // a saved one connecting by itself (a visit, after «Save»): the wave, not «not connected»
      connect.disabled = true;
      bar.appendChild(el('span', 'spin'));
    }
    if (inst.server) bar.appendChild(chip(T('setup.connected', { name: inst.server.name, type: SERVER_NAMES[inst.type], version: inst.server.version }), 'ok'));
    if (inst.server && inst.type === 'plex') box.appendChild(el('p', 'role', T('setup.plex_users')));
    if (inst.connectError) box.appendChild(el('p', 'callout danger jo-connect-error', inst.connectError));
    // every server can go — the only one too, and one that doesn't answer (2026-10-21: an Emby left for Jellyfin stayed for good)
    bar.appendChild(button(T('setup.remove_server'), 'small plain jo-remove', () => removeServerDialog(inst)));
    // a second server of the same kind (rare): from its last row
    if (form.instances.findLastIndex((i) => i.type === inst.type) === idx) {
      bar.appendChild(button(T('setup.add_same', { kind: SERVER_NAMES[inst.type] }), 'small plain', () => {
        form.instances.splice(idx + 1, 0, { ...newInstance(form.instances, inst.type), api_key: '', mappings: {}, skip: new Set(), server: null, libraries: [], users: [] });
        renderSetup();
      }));
    }
    box.appendChild(bar);
    s1.appendChild(box);
  });
  if (!connected()) s1.appendChild(el('p', 'callout', T('setup.connect_first')));
  // someone who ran helmi1987's tools before: the offer (only while he isn't set up — afterwards its own tile)
  if (!state.configured) {
    const offer = el('div', 'jo-offer');                 // an offer, not a warning: plain text (orange only for warnings)
    offer.append(el('p', 'role', T('import.sub')), button(T('import.open'), 'small plain', importDialog));
    s1.appendChild(offer);
  }
  return s1;
}

/**
 * «Remove…» on a server: what it means first. Only the form changes; «Save» makes him forget it — its address, its key,
 * and in his settings its libraries and its people. The click moves nothing on the pool: with servers left his next run
 * plans with them and gives back what none of them asks for (a server that doesn't answer: an incomplete plan, nothing
 * given back — mediacache/README.md); the last one: no run at all until one is connected, what he laid out stays on the
 * pool, protected by his list.
 */
function removeServerDialog(inst) {
  const name = inst.servername || SERVER_NAMES[inst.type];
  const box = el('div');
  box.appendChild(el('p', '', T('setup.remove_text', { kind: SERVER_NAMES[inst.type] })));
  box.appendChild(el('p', '', T(form.instances.length > 1 ? 'setup.remove_others' : 'setup.remove_last')));
  Office.dialog({ title: T('setup.remove_title', { server: name }), body: box, buttons: [
    { text: Office.t('common.cancel') },
    { text: T('setup.remove_go'), kind: 'danger', act: () => { removeServer(inst); return true; } },
  ] });
}
/** The server out of the form, looked up again (the form may be new by now): its people with it — a person also on another server keeps their choice and budget there */
function removeServer(inst) {
  const at = form ? form.instances.indexOf(inst) : -1;
  if (at < 0) return;
  const gone = new Set((inst.users || []).map((u) => u.id));
  for (const p of persons()) {
    const left = p.ids.filter((id) => !gone.has(id));
    if (!left.length || left.length === p.ids.length) continue;
    if (personChosen(p)) left.forEach((id) => form.chosenUsers.add(id));
    const b = personBudget(p);
    if (b && !left.some((id) => budgetGb(form.budgets[id]) > 0)) form.budgets[left[0]] = b;
  }
  gone.forEach((id) => { form.chosenUsers.delete(id); delete form.budgets[id]; (form.forgotten ||= new Set()).add(id); });
  form.instances.splice(at, 1);
  if (!form.instances.length) {          // none left: nothing chosen any more (his budget, pool and «More» stay)
    form.chosenLibs.clear();
    form.chosenUsers.clear();
    form.budgets = {};
  }
  renderSetup();
}
/** His saved settings have a server (saving with none left then makes him forget them) */
const savedServers = () => !!(state && state.settings && (state.settings.instances || []).length);
/** Settings saved, but no server in them: every one removed */
const noServer = () => !!(state && state.settings && !(state.settings.instances || []).length);

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

/** What a connection was made with (address and key): «Save» connects again only when these changed */
const connectKey = (inst) => `${inst.type}|${String(inst.url || '').trim()}|${inst.api_key || ''}`;
/** A server to connect before saving: an address and a key (typed, or kept on the server), not connected with them yet */
const needsConnect = (inst) => !!String(inst.url || '').trim().replace(/^https?:\/\/$/, '') && !!(inst.api_key || inst.has_key)
  && (!inst.server || inst.connectedAs !== connectKey(inst));
/** Connect one server (agent: jack.connect): its libraries and people in the form — or the error said in its box */
async function connectInst(inst) {
  const j = await Office.api.post(`${ID}.connect`, { url: inst.url, api_key: inst.api_key, type: inst.type });
  if (!j || !j.ok) {
    inst.connectError = Office.errorText(j && j.error, ID);
    return false;
  }
  inst.connectError = '';
  inst.server = j.server || { name: '', version: '' };
  inst.libraries = Array.isArray(j.libraries) ? j.libraries : [];
  inst.users = Array.isArray(j.users) ? j.users : [];
  inst.connectedAs = connectKey(inst);
  for (const lib of inst.libraries) for (const p of lib.locations || []) if (!inst.mappings[p]) inst.mappings[p] = suggestMapping(p, inst);
  if (!state.configured && !form.chosenLibs.size) suggestChoice(inst);
  return true;
}

/**
 * A click on a media server's card: set up — to its row (the first of that kind); not yet — a new row of that kind (from
 * its container if one runs here, else empty with the note that none runs here). Returns the row's index.
 */
function mediaPick(type) {
  let idx = form.instances.findIndex((i) => i.type === type);   // a row of that kind already (set up or just added)
  if (idx < 0) {
    // an untouched empty row (the first Emby of a fresh setup) becomes this kind instead of a second row
    const blank = form.instances.findIndex((i) => !i.has_key && !i.api_key && !i.server && /^https?:\/\/$/.test(String(i.url || '').trim() || 'http://'));
    const row = { ...newInstance(form.instances.filter((_, n) => n !== blank), type), api_key: '', mappings: {}, skip: new Set(), server: null, libraries: [], users: [] };
    if (blank >= 0) form.instances[blank] = row; else form.instances.push(row);
    idx = blank >= 0 ? blank : form.instances.length - 1;
    renderSetup();
  }
  const boxes = typeof document !== 'undefined' && document.querySelectorAll ? document.querySelectorAll('.jo-instance') : [];
  const box = boxes[idx];
  if (box) {
    if (box.scrollIntoView) box.scrollIntoView({ block: 'center', behavior: 'smooth' });   /* reveal */ // the card asked for its server's row
    const f = box.querySelector('input:not([type=password])');
    if (f) f.focus({ preventScroll: true });
  }
  return idx;
}

/**
 * The media servers he can work with: Emby, Jellyfin and Plex. A kind with a server below (its key kept, or connected) says «set up» (green); the
 * others «supported» (grey — what he can do, not that one is installed here). The names are the products' own.
 */
function mediaChoice() {
  const box = el('div', 'jo-media');
  box.appendChild(el('div', 'jo-media-head', T('setup.media')));
  const row = el('div', 'jo-media-row');
  [['Emby', 'emby'], ['Jellyfin', 'jellyfin'], ['Plex', 'plex']].forEach(([name, type]) => {
    const set = (form && form.instances || []).some((i) => i.type === type && (i.has_key || !!i.server));   // a key kept or connected
    const card = el('button', 'box jo-media-card' + (set ? ' on' : ''));   // the orange frame only around what is set up
    card.type = 'button';
    card.setAttribute('aria-disabled', 'false');
    card.title = T(set ? 'setup.media_show' : 'setup.media_add', { kind: name });
    card.addEventListener('click', () => mediaPick(type));
    const who = el('span', 'jo-media-name');
    who.append(mediaLogo(name), el('b', '', name));
    card.append(who, chip(T(set ? 'setup.media_set' : 'setup.media_ok'), set ? 'ok' : ''));
    row.appendChild(card);
  });
  box.append(row, el('p', 'role', T('setup.media_sub')));
  return box;
}

/** The people: who, and a budget in GB each (empty: an even share of what the others' budgets leave) */
function setupUsers() {
  if (!connected()) return notYet();
  const s3 = el('div', 'jo-part-body');
  const people = persons();
  if (people.length) {
    const pick = el('div', 'toolbar');
    pick.append(button(T('setup.users_all'), 'small plain', () => { people.forEach((p) => p.ids.forEach((id) => form.chosenUsers.add(id))); renderSetup(); }),
      button(T('setup.users_none'), 'small plain', () => { form.chosenUsers.clear(); renderSetup(); }));
    s3.appendChild(pick);
  }
  const f3 = el('div', 'jo-users');
  let line = null;
  const several = form.instances.length > 1;
  people.forEach((p) => {
    const wrap = el('div');
    const on = personChosen(p);
    // one person, all their servers (1.65.0): one choice, one budget — the servers as small chips beside the name
    wrap.appendChild(check(p.name, on, (x) => { p.ids.forEach((id) => (x ? form.chosenUsers.add(id) : form.chosenUsers.delete(id))); renderSetup(); },
      p.admin ? T('setup.admin') : ''));
    if (several) {
      const chips = el('div', 'row-meta');
      p.servers.forEach((name) => chips.appendChild(chip(name, 'quiet', T('setup.person_server', { server: name }))));
      wrap.appendChild(chips);
    }
    // a budget of their own in GB (MediaCache's valid_users {id: {budget: "300G"}}, under their first id)
    if (on) {
      const b = el('input', 'input');
      b.type = 'number';
      b.min = MIN_PERSON_GB;
      b.step = 1;
      const now = budgetGb(personBudget(p));
      b.value = now > 0 ? String(now) : '';
      b.placeholder = T('setup.user_budget_gb_hint');
      b.oninput = () => {
        const n = Number(b.value);
        setPersonBudget(p, b.value.trim() === '' || !(n > 0) ? '' : `${n}G`);
        if (line) line.replaceWith(line = budgetLine());
        if (saveGate) saveGate();          // «Save» blocked or free again, with its reason
        setupSwitcher().row();             // the people's tile red or not (the row only: the field keeps the focus)
      };
      wrap.appendChild(field(T('setup.user_budget_gb'), b));
    }
    f3.appendChild(wrap);
  });
  s3.appendChild(f3);
  s3.appendChild(line = budgetLine());
  return s3;
}

/** Every chosen person gets at least this much (2026-10-14, the owner): a budget of their own, or the even share of the rest */
const MIN_PERSON_GB = 20;
/**
 * How the budget splits (pure): budgets of the chosen people first, the rest evenly on the others — what someone doesn't
 * need flows to the others (EmbyCache's apply_budget). total: GB; fixed: [GB …] of the people with one; others: how many
 * go without one. Red (bad) when the budgets exceed the whole (over), one is under MIN_PERSON_GB (low: how many), or the
 * rest gives the others under MIN_PERSON_GB each (short; each = their even share).
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
/** The split of the form now: the chosen people (none: everyone), their budgets, the low ones' names */
function budgetState() {
  if (!form) return null;
  const chosen = form.chosenUsers.size ? chosenPersons() : persons();       // a person once, over all their servers
  const own = chosen.filter((p) => budgetGb(personBudget(p)) > 0);
  const sp = budgetSplit(budgetGb(form.values.cache_budget), own.map((p) => budgetGb(personBudget(p))), chosen.length - own.length);
  sp.lowNames = own.filter((p) => budgetGb(personBudget(p)) < MIN_PERSON_GB).map((p) => p.name);
  return sp;
}
/** Why «Save» can't go now, '' when it can: no server to connect, no budget, or a person would get under MIN_PERSON_GB */
function setupBlocked() {
  if (!form) return '';
  if (!form.instances.length) {            // every server removed: saving makes him forget them — when there was one saved
    if (!savedServers()) return T('setup.connect_first');
  } else if (!connected()) return form.instances.some(needsConnect) ? '' : T('setup.connect_first');      // «Save» connects what is typed
  if (!(budgetGb(form.values.cache_budget) > 0)) return T('setup.budget_missing');
  // never more than the pool holds (the lab, 2026-10-16)
  const size = (state.pool_sizes || {})[derivedPool()];
  if (size > 0 && budgetGb(form.values.cache_budget) * 1024 ** 3 > size) {
    return T('setup.budget_pool', { pool: derivedPool(), size: fmt.size(size) });
  }
  const sp = budgetState();
  return sp && sp.bad ? T('setup.users_budget_block', { step: T('setup.users'), min: gbSize(MIN_PERSON_GB) }) : '';
}
const gbSize = (gb) => fmt.size(gb * 1024 * 1024 * 1024);
/** The people's line, live: red with the reason and what to do while someone would get too little, else the split */
function budgetLine() {
  const sp = budgetState();
  const box = el('div', 'jo-budget');
  const red = (text) => box.appendChild(el('p', 'callout warn jo-slice', text));
  const min = gbSize(MIN_PERSON_GB);
  if (!(sp.total > 0)) { box.appendChild(el('p', 'callout jo-slice', T('setup.users_budget_first', { where: T('setup.scope') }))); return box; }
  if (sp.over) red(T('setup.users_budget_over', { fixed: gbSize(sp.fixed), total: gbSize(sp.total) }));
  if (sp.low) red(T('setup.users_budget_low', { names: sp.lowNames.join(', '), min }));
  if (sp.short && !sp.over) red(T('setup.users_budget_short', { n: sp.others, rest: gbSize(sp.rest), min, need: gbSize(MIN_PERSON_GB * sp.others) }));
  // everyone chosen has a budget of their own: EmbyCache's apply_budget shares the rest only among people without one —
  // nobody gets more than their own budget, the rest stays unused (2026-10-14)
  if (!sp.bad && !sp.others) box.appendChild(el('p', 'callout jo-slice', sp.rest > 0 ? T('setup.users_budget_all_fixed_rest', { fixed: gbSize(sp.fixed), rest: gbSize(sp.rest) })
    : T('setup.users_budget_all_fixed', { fixed: gbSize(sp.fixed) })));
  else if (!sp.bad) box.appendChild(el('p', 'callout jo-slice', sp.fixed > 0 ? T('setup.users_budget_split', { fixed: gbSize(sp.fixed), rest: gbSize(sp.rest), n: sp.others })
    : T('setup.users_budget_even', { rest: gbSize(sp.rest), n: sp.others })));
  return box;
}

/** The libraries and their folders on the pool — each share with its primary pool (read only, Unraid's share settings) */
/**
 * The pool would change while files of his lie on the old one (the lab, 2026-10-16): MediaCache looks only on its pool —
 * what lies on the old one would stay there, found by nobody. Said before «Save», with the way out. '' when not so.
 */
function poolChangeWarning() {
  const old = (state.settings && state.settings.cache_path) || '';
  const now = derivedPool();
  const n = (state.cache && state.cache.files) || 0;
  return state.configured && old && now && old !== now && n > 0 ? T('setup.pool_change', { old, new: now, n, tick: T('letgo.tick') }) : '';
}

function setupLibraries() {
  if (!connected()) return notYet();
  const s2 = el('div', 'jo-part-body');
  const moving = poolChangeWarning();
  if (moving) s2.appendChild(el('p', 'callout warn', moving));
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
            meta.appendChild(chip(T('setup.share.' + fit), 'danger', T('setup.share_tip')));
            f.appendChild(meta);
            f.appendChild(el('p', 'role jo-fix', T('setup.fix.' + fit, params)));
          } else {
            const other = cache && '/mnt/' + info.primary !== cache;
            meta.appendChild(chip(T('setup.pool_chip', { pool: info.primary }), other ? 'warn' : 'ok', other ? T('setup.pool_other', { pool: cache.replace('/mnt/', '') }) : T('setup.share_ok_tip', { share, pool: info.primary })));
            // the share is right, its folder isn't on the pool right now: made at the first laying out (later), no fault
            if (!other && !(state.pool_dirs['/mnt/' + info.primary] || []).includes(share)) {
              meta.appendChild(chip(T('setup.share_dir_later'), 'accent', T('setup.share_dir_later_tip', { share, pool: '/mnt/' + info.primary })));
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
    const pick = el('div', 'callout warn jo-pools');
    pick.appendChild(el('p', '', T('setup.pools_many', { n: pools.size })));
    for (const [pool, shares] of pools) {
      const l = el('label', 'check');
      const r = el('input');
      r.type = 'radio';
      r.name = 'jo-pool-pick';
      r.checked = cache === '/mnt/' + pool;
      r.onchange = () => { form.values.cache_path = '/mnt/' + pool; renderSetup(); };
      l.append(r, el('span', '', T('setup.pool_of', { pool, shares: shares.join(', ') })));
      pick.appendChild(l);
    }
    s2.appendChild(pick);
  } else if (!pools.size && chosenFolders().length) s2.appendChild(el('p', 'callout warn', T('setup.pool_none')));
  return s2;
}

/**
 * The amount: the pool (read only — from the shares), the budget in GB (required, the hard cap), then films and series —
 * how many of each kind, the defaults inside the budget; the rule in plain sentences and Ms. Moverelli's limit
 */
function setupScope() {
  if (!connected()) return notYet();
  const v = form.values;
  const s4 = el('div', 'jo-part-body');
  const f4 = el('div', 'jo-form');
  const cache = derivedPool();
  const pools = poolsOfChoice();
  const kv = el('dl', 'kv jo-share-kv');
  kv.append(el('dt', '', T('setup.pool')), el('dd', '', cache ? T('setup.pool_from', { pool: cache.replace('/mnt/', ''), shares: (pools.get(cache.replace('/mnt/', '')) || []).join(', ') })
    : T('setup.pool_none')));
  f4.appendChild(kv);
  const b = el('input', 'input');
  b.type = 'number';
  b.min = MIN_PERSON_GB;
  b.step = 1;
  b.value = v.cache_budget;
  b.placeholder = T('setup.budget_gb_hint');
  let words = null;
  b.oninput = () => {
    v.cache_budget = b.value.trim();
    if (words) words.replaceWith(words = ruleWords());
    if (saveGate) saveGate();
    setupSwitcher().row();               // the amount's and the people's tiles say it at once
  };
  f4.appendChild(field(T('setup.budget_gb'), b, T('setup.budget_gb_help')));
  s4.appendChild(f4);
  s4.appendChild(words = ruleWords());

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
  // the films share counts over all chosen libraries together, by kind (every film a film, every episode series —
  // EmbyCache's select()); only one kind chosen: the field greyed, the whole room goes to that kind
  const both = chosenTypes.has('movies') && chosenTypes.has('tvshows');
  const share = number(v, 'movie_share_percent', 0, 100);
  share.disabled = !both;
  part(T('type.movies'), 'movies',
    field(T('setup.movie_share'), share, both || !chosenTypes.size ? T('setup.movie_share_hint')
      : T(chosenTypes.has('movies') ? 'setup.movie_share_only_movies' : 'setup.movie_share_only_series')),
    field(T('setup.resume_movies'), number(v, 'max_resume_movies', 0, 999), T('setup.resume_movies_hint')),
    el('p', 'role', T('setup.movies_folder')));
  part(T('type.tvshows'), 'tvshows',
    field(T('setup.max_per_series'), number(v, 'max_episodes_per_series', 0, 999), T('setup.zero_budget')),
    field(T('setup.resume_series'), number(v, 'max_resume_series', 0, 999), T('setup.resume_series_hint')),
    field(T('setup.favorites'), number(v, 'max_favorite_series', 0, 999), T('setup.zero_off')),
    check(T('setup.next_up'), v.use_next_up, (x) => { v.use_next_up = x; }, T('setup.next_up_hint')));
  s4.appendChild(sharePart());
  return s4;
}

/**
 * The rule in plain sentences (2026-10-14, the owner: instead of the simulator) — what he fetches, the budget as the cap,
 * what goes back, Ms. Moverelli's limit X. Explanations, not warnings: plain help text (orange is for warnings only).
 * The words: ruleTexts().
 */
function ruleWords() {
  const box = el('div', 'jo-rule');
  ruleTexts(budgetGb(form.values.cache_budget)).forEach((t) => box.appendChild(el('p', 'role jo-slice', t)));
  return box;
}
/** [sentence, …]: his rule with the budget (GB; 0: what is missing), her limit X and his priority (or that she isn't set up) — pure */
function ruleTexts(total) {
  const mo = herRules();
  const out = [total > 0 ? T('setup.rule', { budget: gbSize(total) }) : T('setup.rule_no_budget')];
  // her limit X in plain words (2026-10-14, the owner): he fills only up to X, the day's downloads land above it and she
  // clears them at night (so X needs room up to 100 %); his budget comes first — she moves the others' oldest for it
  if (mo && mo.x > 0) out.push(T('setup.rule_x', { x: mo.x }), T('setup.rule_first', { x: mo.x }));
  else if (mo) out.push(noLimitText());           // she is set up, without a limit X: his real runs go (truth review T37)
  else out.push(T('room.none'));
  return out;
}
/** Ms. Moverelli set up without a limit X (pure on state): what he keeps free on the pool then, as his pool says it */
function noLimitText() {
  const min = state && state.pool && state.pool.min_free != null ? state.pool.min_free : null;
  return min !== null ? T('room.own', { min }) : T('room.own_plain');
}

/**
 * Sharing the pool: Ms. Moverelli's limit X and her age rule as she has them (global per pool, her desk sets them — shown,
 * not asked here), the link to her setup
 */
function sharePart() {
  const box = el('div', 'box jo-part');
  box.appendChild(el('div', 'jo-part-head', T('share.title')));
  const f = el('div', 'jo-form');
  const mo = herRules();
  if (mo && mo.x > 0) {
    const kv = el('dl', 'kv jo-share-kv');
    const add = (key, value) => kv.append(el('dt', '', T(key)), el('dd', '', value));
    add('share.x', `${mo.x} %`);
    add('share.age', mo.min_age == null ? '–' : nOf('days', mo.min_age));
    f.appendChild(kv);
    const her = el('p', 'role');
    // her desk sets these (global per pool); what is typed here and not saved yet is lost on the way — said there
    her.appendChild(link(T('share.her_link'), () => {
      const lost = formChanged();
      form = null;
      Office.go('#/moverelli/setup/global');
      if (lost) Office.toast(T('setup.left_unsaved'));
    }));
    f.appendChild(her);
  } else {
    // set up without a limit X: said as it is, never «not set up yet» (T37)
    f.appendChild(el('p', mo ? 'role jo-slice' : 'callout jo-slice', mo ? noLimitText() : T('room.none')));
  }
  box.appendChild(f);
  return box;
}
/** Ms. Moverelli's rules: her own (state.moverelli — there without his settings too), else as his pool reported them */
const herRules = () => (state && (state.moverelli || (state.pool && state.pool.moverelli))) || null;
/** The form as a string, as built from his settings at the visit's start — changed since? (leaving for her setup says so) */
let formStart = '';
/** The form in parts, each as a string: a server, a library or person chosen, a person's budget, a value */
function formParts() {
  if (!form) return {};
  const out = {};
  form.instances.forEach((x, i) => { out['i' + i] = JSON.stringify([x.type, x.servername, x.url, x.api_key, countedMappings(x), [...x.skip].sort()]); });
  form.chosenLibs.forEach((n) => { out['l|' + n] = '1'; });
  form.chosenUsers.forEach((n) => { out['u|' + n] = '1'; });
  Object.entries(form.budgets).forEach(([k, v]) => { out['b|' + k] = JSON.stringify(v); });
  Object.entries(form.values).forEach(([k, v]) => { out['v|' + k] = JSON.stringify(v); });
  return out;
}
/**
 * A server's folders as a change counts them (2026-10-21, the owner: every «Connect» on a saved server gave «1 change»
 * nobody could see): connecting suggests a folder for every library folder — a folder deliberately not cached (skip) or
 * of a library not chosen is saved as '' whatever it suggests, so only the others count, sorted (a connect adds keys)
 */
function countedMappings(inst) {
  const chosen = inst.server ? new Set(libraryList().filter((l) => form.chosenLibs.has(l.name)).flatMap((l) => l.at.filter(([i]) => form.instances[i] === inst).map(([, p]) => p))) : null;
  return Object.keys(inst.mappings).sort().filter((p) => !inst.skip.has(p) && (!chosen || chosen.has(p))).map((p) => [p, inst.mappings[p]]);
}
/** The parts of the setup a change is in (formParts' keys → SETUP_TILES ids), in the parts' order */
function changedParts() {
  if (!formChanged()) return [];
  const was = new Map(JSON.parse(formStart || '[]'));
  const now = formParts();
  const keys = [...new Set([...was.keys(), ...Object.keys(now)])].filter((k) => !(k in now && was.has(k) && now[k] === was.get(k)));
  // a server's row: its address, key or name — the servers' part; only its folders — the libraries' part (they are shown there)
  const row = (k) => { const a = JSON.parse(was.get(k) || 'null'); const b = JSON.parse(now[k] || 'null');
    return a && b && JSON.stringify(a.slice(0, 4)) === JSON.stringify(b.slice(0, 4)) ? 'libraries' : 'servers'; };
  const part = (k) => (k.startsWith('i') ? row(k) : k.startsWith('l|') || k === 'v|cache_path' ? 'libraries' : k.startsWith('u|') || k.startsWith('b|') ? 'users'
    : Object.prototype.hasOwnProperty.call(MORE_DEFAULTS, k.slice(2)) ? 'more' : 'scope');
  const ids = new Set(keys.map(part));
  return SETUP_TILES.filter(([id]) => ids.has(id)).map(([, key]) => key);
}
function formKey() {
  if (!form) return '';
  const parts = formParts();
  return JSON.stringify(Object.keys(parts).sort().map((k) => [k, parts[k]]));
}
const formChanged = () => !!form && formKey() !== formStart;
/** How many parts differ from the visit's start (the bar's «n changes»): one per server, library, person, budget, value */
function formChangeCount() {
  if (!formChanged()) return 0;
  const was = new Map(JSON.parse(formStart || '[]'));
  const now = formParts();
  return new Set([...was.keys(), ...Object.keys(now)]).size - [...was.keys()].filter((k) => k in now && now[k] === was.get(k)).length;
}

/**
 * What isn't saved yet, said by «Save» at the bottom (2026-10-21, the owner: no floating change bar on his page — his own
 * «Save» is enough): «n changes (the parts) against what is saved — not saved yet» and «Discard» beside it, both only
 * while something differs (2026-10-17, silent controls: a field changed said nothing until «Save» was found)
 */
function setupBar() {
  if (page === 'setup' && saveGate) saveGate();
}

/** «More»: the way back and where to read from (both ways always rsync: Unraid's move binary is never his choice), from scratch */
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
function setupMore() {
  const v = form.values;
  const s5 = el('div', 'jo-part-body');
  const f5 = el('div', 'jo-form');
  f5.appendChild(field(T('setup.array_source'), select(v, 'array_source', [['user0', T('setup.source_user0')], ['disk', T('setup.source_disk')]]), T('setup.array_source_hint')));
  f5.appendChild(parallelField(v));
  f5.appendChild(el('p', 'role', T('setup.fixed_paths')));
  s5.appendChild(f5);
  // quiet, at the bottom: from scratch (his settings deleted, no copy; his list emptied — ordinary cache afterwards)
  if (state.configured) {
    const foot = el('div', 'toolbar jo-reset');
    const again = button(T('reset.button'), 'small plain danger', resetDialog);
    again.disabled = running();
    if (running()) foot.appendChild(el('span', 'role', T('errors.jack_running')));        // why it is grey
    foot.appendChild(again);
    s5.appendChild(foot);
  }
  return s5;
}
/**
 * «Copies at once per disk» (2026-10-29, MediaCache's parallel_per_disk): 1 to the agent's most; under it what that makes
 * in all — the array's data disks × it, at most the cap (the disks are the limit) — and why more per disk rarely helps
 */
function parallelField(v) {
  const p = state.parallel || {};
  const max = Number(p.max) || 4;
  const sel = select(v, 'parallel_per_disk', Array.from({ length: max }, (_, i) => [String(i + 1), String(i + 1)]));
  sel.value = String(v.parallel_per_disk);
  const f = field(T('setup.parallel'), sel, '');
  const hint = el('small', '', '');
  const say = () => {
    const disks = Math.max(1, Number(p.disks) || 1);
    const total = Math.max(1, Math.min(Number(p.total_max) || 8, v.parallel_per_disk * disks));
    hint.textContent = T('setup.parallel_hint', { disks: nOf('array_disks', disks), total: nOf('copies', total) });
  };
  sel.onchange = () => { v.parallel_per_disk = Number(sel.value) || 1; say(); };
  say();
  f.appendChild(hint);
  return f;
}
/**
 * «Set up from scratch …» (the owner: really zero, no copy): what goes and what becomes of the files he placed on the pool
 * (his list's count and size — ordinary cache for Ms. Moverelli afterwards), then jack.reset and the tab from the start
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
      setupSwitcher().pick('servers', 'click');      // from the start: the server
      renderSetup();
      return true;
    } },
  ] });
}

/**
 * Taking over an earlier install, as a part of its own (once he is set up; before, the server's part offers it) — never
 * named importPart: that one (the preview's box per tool) won the hoisting and drew «undefined» here (live 2026-10-14)
 */
function importSection() {
  const s = el('div', 'jo-part-body');
  s.append(el('p', 'role', T('import.sub')), button(T('import.open'), 'small', importDialog));
  return s;
}

/** His budget («500G», «1.5T», «800 GB», a bare number = GB) in GB — 0 when none can be read */
function budgetGb(text) {
  const m = /^(\d+(?:\.\d+)?)\s*([KMGTP])?B?$/i.exec(String(text || '').trim());
  if (!m) return 0;
  const f = { K: 1 / (1024 * 1024), M: 1 / 1024, G: 1, T: 1024, P: 1024 * 1024 }[(m[2] || 'G').toUpperCase()];
  return Math.round(Number(m[1]) * f * 10) / 10;
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
  const files = Object.entries(p.found).filter(([, on]) => on).map(([k]) => ({ settings: 'mediacache_settings.json', exclude: 'mediacache_exclude.txt' })[k]);
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
  if (p.exclude) {
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
  if (p.embycache) box.appendChild(importPart('MediaCache', p.embycache.folder, importEmbyCache(p.embycache)));
  if (p.gather) box.appendChild(importPart(T('gather'), p.gather.folder, importGather(p.gather)));
  if (p.running) box.appendChild(el('p', 'callout warn', T('errors.jack_running')));
  else if (!p.ready) box.appendChild(el('p', 'callout warn', T('errors.jack_import_blocked')));
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
    buttons: [{ text: Office.t('common.close'), kind: '' }], onClose: () => { if (view && page === 'setup') renderSetup(); else Office.go(`#/${ID}`); } });
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

/** The setup's part shown when «Save» finds something to fix there (nothing chosen: the whole page stays) */
function showPart(id) {
  const sw = setupSwitcher();
  if (sw.active() && sw.active() !== id) sw.pick(id, 'click');
}

async function saveSetup(e) {
  const btn = e && e.currentTarget;
  // a server with a new address or key: connected first (the button busy); a failure says why in its box
  const todo = form.instances.filter(needsConnect);
  if (todo.length) {
    if (btn) { btn.disabled = true; btn.appendChild(el('span', 'spin')); }
    let ok = true;
    for (const inst of todo) ok = (await connectInst(inst)) && ok;
    if (view && page !== 'setup') return;           // another page meanwhile: nothing more
    if (!ok) { showPart('servers'); renderSetup(); return; }
    renderSetup();
  }
  // every server removed (savedServers(): there was one to forget): saved without — his budget, pool and «More» stay
  const none = !form.instances.length;
  if (!none && !connected()) { Office.toast(T('setup.connect_first'), true); showPart('servers'); return; }
  if (none && !savedServers()) { Office.toast(T('setup.connect_first'), true); showPart('servers'); return; }
  const v = form.values;
  const libs = libraryList().filter((l) => form.chosenLibs.has(l.name));
  if (!none && !libs.length) { Office.toast(T('setup.no_library'), true); showPart('libraries'); return; }
  // the pool from Unraid's share settings: one, or the one picked among several — never chosen freely (none left: as saved)
  const cache = none ? v.cache_path : derivedPool();
  if (!cache) { Office.toast(T(poolsOfChoice().size > 1 ? 'setup.pools_pick' : 'setup.pool_none'), true); showPart('libraries'); return; }
  const instances = form.instances.map((inst) => ({ type: inst.type, servername: inst.servername, url: inst.url, api_key: inst.api_key, path_mappings: {} }));
  // folders of libraries that aren't chosen: deliberately not cached (EmbyCache then skips them quietly)
  for (const lib of libraryList()) {
    if (!form.chosenLibs.has(lib.name)) for (const [i, p] of lib.at) instances[i].path_mappings[p] = '';
  }
  for (const lib of libs) {
    for (const [i, p] of lib.at) {
      if (form.instances[i].skip.has(p)) { instances[i].path_mappings[p] = ''; continue; }
      const to = form.instances[i].mappings[p];
      if (!to || !/^\/mnt\/user\/[^/]+/.test(to)) { Office.toast(T('setup.map_missing', { path: p }), true); showPart('libraries'); return; }
      // a share without a pool as its primary, or on another pool than his: left out (not cached), as its part says
      const sh = shareOf(to);
      instances[i].path_mappings[p] = cacheable(sh) && '/mnt/' + state.share_info[sh].primary === cache ? to : '';
    }
  }
  // budget mode always (2026-10-14): the budget in GB is the hard cap, everyone chosen at least MIN_PERSON_GB
  const blocked = setupBlocked();
  if (blocked) { Office.toast(blocked, true); showPart(budgetGb(v.cache_budget) > 0 ? 'users' : 'scope'); return; }
  // a person over all their servers: every id of theirs chosen, their budget once (under their first id)
  const people = chosenPersons();
  // every server connected: only the ids they know (a server removed takes its people along, also one that didn't answer)
  const known = form.instances.every((i) => i.server) ? new Set(everyone()) : null;
  const keep = (id) => !(form.forgotten || new Set()).has(id) && (!known || known.has(id));
  const userBudgets = {};
  for (const p of people) if (budgetGb(personBudget(p)) > 0) userBudgets[p.ids[0]] = `${budgetGb(personBudget(p))}G`;
  const settings = {
    instances,
    libraries: libs.map((l) => l.name),
    library_types: Object.fromEntries(libs.map((l) => [l.name, l.type])),
    valid_users: none ? [] : [...new Set([...form.chosenUsers, ...people.flatMap((p) => p.ids)])].filter(keep),     // (a server not connected keeps its ids)
    user_budgets: userBudgets,
    people: chosenPersons().map((p) => p.ids),
    cache_path: cache,
    cache_budget: `${budgetGb(v.cache_budget)}G`,
    movie_share_percent: v.movie_share_percent,
    max_episodes_per_series: v.max_episodes_per_series,
    max_resume_movies: v.max_resume_movies,
    max_resume_series: v.max_resume_series,
    max_resume_items: Math.max(v.max_resume_movies, v.max_resume_series),     // for older EmbyCache versions
    max_favorite_series: v.max_favorite_series,
    use_next_up: v.use_next_up,
    movie_mode: 'folder',          // always the whole folder: subtitles, preview images, nfo, extras
    array_source: v.array_source,
    parallel_per_disk: v.parallel_per_disk,
  };
  const j = await act('save', { settings });
  if (!j) return;
  Office.toast(T('setup.saved'));
  // saved: the tab shows his setup as saved now — from its top, not left at the bottom where «Save» was — and connects his
  // servers again as a visit does (2026-10-21, the owner: «0 connected», no people, no libraries until a reload); the
  // first drawing and the wave come in the same tick (connectSaved() marks them before its first await)
  form = null;
  renderSetup();
  connectSaved();
  if (typeof window !== 'undefined') window.scrollTo(0, 0);
}

// ------------------------------------------------------------------ desk
// ------------------------------------------------------------------ let go
/**
 * His part of the let-go dialog (core.js Office.fireDialog → the desk's letGo; agent/desks/jack-letgo.php). He looks
 * first what letting him go does here (jack.letgo_look): both schedules go off — always, no tick; a run that is going
 * finishes; one tick «Also bring the prepared films back to the array», off by default and only when that can be done
 * now (something on the pool, no run going, nobody watching Emby); without it the films stay on the pool. «Let go» —
 * while he is still hired — sends jack.letgo {confirm, release} once; he is let go whatever came of it, then a dialog
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
  const tick = !['jack_not_configured', 'jack_letgo_nothing'].includes(rel.why);
  if (rel.why === 'jack_letgo_nothing') lines.push(['role', T('letgo.nothing')]);
  const more = [];
  if (tick) {
    if (rel.why === 'jack_running') more.push(['callout warn', T('letgo.release_busy')]);
    else if (rel.why === 'jack_release_watching') more.push(['callout warn', T('letgo.watching')], ['who', (rel.params || {}).who || []], ['role', endSessionText('letgo.watch_then')]);
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
    if (rel.error && rel.error.key === 'jack_release_watching') out.push(['who', rel.error.params.who || []]);
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
 * Hired again: what he switched off when he was let go — said once (the agent's note, jack.letgo_seen as soon as it is
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
 * visit starts with everything; the pool's library is a choice, kept as jack.pool.pick (until 2026-10-11 jack.pool_lib:
 * taken over once).
 */
function bars() {
  if (!poolBar) {
    const old = Office.store('jack.pool_lib');
    if (old) {
      if (!Office.store('jack.pool.pick')) Office.store('jack.pool.pick', old);
      Office.store('jack.pool_lib', null);
    }
    poolBar = Office.filterBar({ key: 'jack.pool', label: T('pool.filter_label'), only: { text: T('pool.only_new'), hint: T('pool.only_new_hint') },
      select: { label: T('pool.lib_label'), all: T('pool.lib_all') },
      onChange: (f, what) => {
        if (what !== 'gone') poolShown = POOL_PAGE;
        if (dom.poolBox && dom.poolBox.isConnected) fillPool(dom.poolBox);
      } });
  }
  if (!runsBar) runsBar = Office.filterBar({ key: 'jack.runs', label: T('history.filter_label'), onChange: () => { if (dom.runs && dom.runs.isConnected) fillRuns(dom.runs); } });
  poolBar.reset();
  runsBar.reset();
  poolShown = POOL_PAGE;
}

Office.desk({
  id: ID,
  runs: (s) => !!((s.jobs && Object.values(s.jobs).some((x) => x && x.running)) || (s.night && s.night.now)),       // core.js: an old kept state saying so isn't shown
  async mount(root, sub) {
    // his setup page left with changes not saved (a visit there starts from his settings again): said, never silent
    if (page === 'setup' && formChanged()) Office.toast(T('setup.left_unsaved'));
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : 'main';
    if (page === 'setup') {
      // #/jack/setup/<tile> (the search, a part's place; a wizard step's old route: the tile that holds it now) picks that
      // part exactly like a click, the address keeps its page; else the part chosen last (jack.setup.tile), none = all
      if (setupSwitcher().mount(tileOf(String(sub || '').split('/').slice(1).join('/')))) Office.subroute('setup');
      else if (String(sub || '').includes('/')) Office.subroute('setup');
      if (!state) await load(false);
      await Office.freshState(ID);      // the form starts from his settings: from a fresh look, never a stale one
      if (view !== root || page !== 'setup') return;
      // … and anew on every visit: a form left without «Save» kept its old values and a later save wrote them back
      // (2026-10-10: 12 episodes ahead became 7)
      form = null;
      renderSetup();
      connectSaved();
      return;
    }
    bars();
    // his tiles as places (CLAUDE.md «Tiles»): #/jack/<tile> (plans, pool, runs, shares, gather) picks it exactly like a
    // click and the address is his page's again; the Team Lead's findings #/jack/schedule, #/jack/gather-schedule land
    // in «Schedules» with the row revealed; #/jack/history (a gather run in the search) shows the whole page — every run.
    // The whole page is picked once his state is there: a pick draws the sections, and before his first look they had
    // nothing to draw from (2026-10-16: a click on a run in the search broke his page)
    const plan = { schedule: 'embycache', 'gather-schedule': 'gather' }[sub];
    const whole = sub === 'history';
    if (plan) sw.mount('plans');
    else sw.mount(whole ? '' : sub);
    if (whole) Office.subroute('');
    render();
    if (plan) Office.reveal(`plan:${plan}`);
    await load(false);
    if (whole && view === root && state && sw.active()) sw.pick('', 'route');
  },
  unmount() { if (page === 'setup' && formChanged()) Office.toast(T('setup.left_unsaved')); view = null; saveGate = null; clearTimeout(outTimer); clearTimeout(progTimer); progTimer = null; progBox = null; startKicks = 0; letGoNote = null; },
  letGo: letGoPart,
  poll() { if (page === 'main') { load(false); peekRun(); } },
  greetOk,
  agentChanged() { if (view) (page === 'setup' ? renderSetup() : render()); },
  menu() {
    const items = [{ text: T('menu.refresh'), act: () => load(true) }, { text: T('import.title') + '…', act: importDialog }];
    // the schedules also before he is set up: the gather's may be set without EmbyCache (its dialog says what is missing)
    if (state) {
      items.push({ text: T('schedule_title.embycache'), act: () => scheduleDialog('embycache') });
      items.push({ text: T('schedule_title.gather'), act: () => scheduleDialog('gather') });
    }
    if (state && state.configured) {
      items.push({ text: T('show_log') + ' · MediaCache', act: () => showLog('embycache') });
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
    } else {
      facts.push(T('fact.setup'));
    }
    return { bubble: T('bubble.hello_short'), facts };
  },
});

// what his state holds for the search (core.js «items»): his Emby servers (the line «How I work» names them), the shares
// of the chosen libraries (#/jack/shares), the runs his history shows (MediaCache's in the tile «Last run» #/jack/runs, a
// gather's on the whole page #/jack/history, where every run is listed)
Office.placesFrom(ID, (s, part) => {
  if (part) return [];                       // the part «progress» (the live panel) holds nothing to find
  const out = [];
  if (s.configured) {
    ((s.settings && s.settings.instances) || []).forEach((i) => {
      if (i && i.servername) out.push({ text: i.servername, sub: `${T('overview')} · ${SERVER_NAMES[i.type] || SERVER_NAMES.emby}`, anchor: 'overview' });
    });
    (s.shares || []).forEach((x) => out.push({ text: x.share, sub: T('shares'), route: `#/${ID}/shares`, anchor: `share:${x.share}` }));
  }
  (s.history || []).slice(0, HISTORY_SHOWN).forEach((r) => out.push({ text: `${T('tool.' + r.tool)} · ${T('mode.' + r.mode)} · ${fmt.date(r.started)}`,
    sub: `${T('history')} · ${T('result.' + (r.result || 'failed'))}`, route: r.tool === 'embycache' ? `#/${ID}/runs` : `#/${ID}/history`, anchor: `run:${r.tool}:${r.started}` }));
  return out;
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys): the
// main page, then the setup's (#/jack/setup)
const SETUP = { route: '#/jack/setup', crumb: 'setup_open' };
// (the line «How I work», the shares and what lies on the pool only once he is set up — core.js shown; not known yet:
// listed); each section by the route of its tile (a section stands under the row only while its tile is chosen)
Office.places(ID, [
  { kind: 'section', key: 'overview', shown: configured },
  { kind: 'section', key: 'shares', route: '#/jack/shares', shown: configured },
  { kind: 'section', key: 'gather', route: '#/jack/gather' },
  { kind: 'section', key: 'history', route: '#/jack/runs' },
  { kind: 'section', key: 'on_pool', route: '#/jack/pool', shown: configured },
  // his tiles (CLAUDE.md «Tiles», the switcher's places): each sets its choice as a click would
  ...sw.places().map((p) => (['tile.pool', 'tile.shares'].includes(p.anchor) ? { ...p, shown: configured } : p)),
  { kind: 'section', key: 'tools' },
  // his setup's parts by their route (#/jack/setup/<tile> picks it; its section head is the place) and their tiles
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'step', key, ...SETUP, route: `#/${ID}/setup/${id}` })),
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `setup.tile.${id}`, ...SETUP, route: `#/${ID}/setup/${id}` })),
  ...[['report', 'help.report'], ['mode.dry', 'help.dry'], ['mode.run', 'help.run'], ['gather', 'help.gather_text']]
    .map(([key, text]) => ({ kind: 'help', key, text })),
  ...['what', 'tools', 'progress', 'watch', 'shares', 'sizes', 'pool', 'schedule', 'letgo'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['import.title', 'import.help'], ['setup.key', 'setup.help_key'], ['setup.mapping', 'setup.help_mapping'], ['setup.users', 'setup.help_users'],
    ['setup.scope', 'setup.help_scope'], ['setup.more', 'setup.help_more'], ['share.title', 'share.help'], ['setup.save', 'setup.help_save']]
    .map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.jack = { setState: (s) => { state = s; }, greetOk, bubbleText, lookRun, failedText, roomWords, tonightText, rankItem, progFile, sizesLine, readyOf, poolTile, shareSection, poolView, poolHay, poolSection, bars, lastRuns, sw,
    historySection, plansSection, plansList, gatherSection, footer, scopeLine, lastRunTile, plansTile, poolTile, sharesTile, gatherTile, render,
    renderSetup, page: () => page, SETUP_TILES, setupTileData, setupTileList, setupStep, importSection, setupSwitcher, tileOf, setForm: (f) => { form = f; }, initForm, form: () => form,
    herRules, sharePart, ruleTexts, needsConnect, connectInst, resetDialog, budgetGb, budgetSplit, budgetState, budgetLine, setupBlocked, MIN_PERSON_GB, shareCase,
    setupServers, mediaPick, newInstance, removeServerDialog, removeServer, noServer, persons, libraryList, setView: (v, p) => { view = v; page = p; }, setupLibraries, setupUsers, setupScope, setupMore, saveSetup, saveBar, formKey, formChanged, formChangeCount, changedParts, setupBar, derivedPool, poolsOfChoice, cacheable,
    letGoPart, letGoView, letGoDoneLines, letGoNotice, runSummary, moverNotice, moverOk, moverOffDialog, gatherSettingsDialog, gatherRunDialog, gatherCacheWords,
    nightWaitLine, noLimitText, progressPanel, setProgress: (p, why) => { progress = p; stopping = why || null; }, etaText, tookText, nightPanel, livePanel, chooseRun, headActions,
    setNight: (n) => { nightNow = n; }, nightGoing };
}
})();
