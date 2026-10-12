/* Ms. Moverelli — she completes Unraid's mover. She looks after the Smart Mover that ships with the office: per
   share it picks the files by age, exclude lists and the pool's fill and hands them to Unraid's own move binary.
   She sets it up (#/moverelli/setup), runs and schedules it and shows what it does. Real runs only while Unraid's
   own mover schedule is «Disabled». The agent part lives in agent/desks/moverelli.php. */
(() => {
'use strict';

const ID = 'moverelli';
const T = Office.scope(ID);
/** «{n} <noun>» from the lang key count.<what> — its one/other forms */
const nOf = (what, n) => T('count.' + what, { n: Number(n) || 0 });
const { el, fmt } = Office;
const POLL = 3000;
/** Where the Smart Mover comes from (helmi1987's repository) — for the credit under the tool's tile */
const ORIGIN = ['custom-mover-for-unraid', 'https://github.com/helmi1987/custom-mover-for-unraid'];
const HISTORY_SHOWN = 15;

let state = null;
let view = null;
let page = 'main';            // main | setup
let outTimer = null;
let form = null;              // the setup page's values (from her settings, anew on every visit)
const folds = new Map();      // the setup's share rows: unfolded or not, while the page lives

// ------------------------------------------------------------------ loading
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view && page === 'main') render();
}
async function act(action, data, okText) {
  const j = await Office.api.post(`${ID}.${action}`, data || {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return null; }
  if (j.state) state = j.state;
  if (okText) Office.toast(okText);
  if (view && page === 'main') render();
  return j;
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
/** A button that reads as a quiet link */
function link(text, onclick) {
  const b = el('button', 'mo-link', text);
  b.type = 'button';
  b.onclick = onclick;
  return b;
}
/** An error line inside a dialog: a refusal stays readable there (the dialog stays open) */
function errorLine() {
  const box = el('div', 'callout warn');
  box.setAttribute('role', 'alert');
  box.style.display = 'none';
  return box;
}
function showError(box, error) {
  box.innerHTML = '';
  box.appendChild(el('div', '', Office.errorText(error, ID)));
  box.style.display = '';
  box.scrollIntoView({ block: 'nearest' });     /* reveal */ // the error line inside its dialog
}

const running = () => !!(state && state.job && state.job.running);
const realRun = () => running() && state.job.mode === 'run';
const unraidOk = () => !!(state && state.unraid && state.unraid.ok);
const mover = () => (state && state.mover) || { off: true, schedule: null, running: false };
const jack = () => (state && state.jack) || { busy: false, list: null, uncovered: [] };
/**
 * Jack Emby's shares a setup form doesn't keep EmbyCache's list away from — the agent's rule (embyMoverelliUncovered())
 * on the form not yet saved: a share of his is covered when it is left out, or when one form of his list's path stands
 * in the files for all shares or in its own. None while he isn't hired (no list).
 */
function jackUncoveredIn(f) {
  const j = jack();
  const lists = (j.lists && j.lists.length ? j.lists : [j.list]).filter(Boolean);
  if (!lists.length || !f) return [];
  const global = (f.global && f.global.files) || [];
  return (j.shares || []).filter((share) => {
    const own = (f.shares || {})[share];
    if (own && own.skip) return false;
    return !lists.some((l) => global.includes(l) || (own && (own.files || []).includes(l)));
  });
}
/** Other movers on a schedule of their own: Mover Tuning's (refuses real runs), scripts that call a mover (named) */
const foreign = () => (state && state.foreign) || { tuning: { installed: false, active: false, lines: [] }, scripts: [] };
/** May real runs go: Unraid's schedule off, Mover Tuning without a schedule of its own */
const othersOff = () => mover().off && !foreign().tuning.active;
/** Her schedule «right after Jack Emby's scheduled EmbyCache runs»: chosen, Jack hired, his EmbyCache on a schedule; idle = nothing starts her */
const after = () => (state && state.after) || { on: false, jack: false, jack_scheduled: false, idle: false };
/** «cache», «array» in words: the array is named in the page's language */
const where = (x) => (x === 'array' ? T('array') : x || '–');
function schedText(sc) {
  if (after().on) return T('schedule.after_short');
  if (!sc || !sc.enabled) return T('not_scheduled');
  return sc.frequency === 'custom' ? fmt.cron(sc.custom) : sc.frequency;
}

/** One line about how a run went (her list of runs, the last run) */
function runSummary(r) {
  if (r.result === 'refused') {
    return T('refused', { why: Office.errorText({ key: r.why, params: { schedule: r.schedule || '', shares: r.shares || '', version: r.version || '', needed: (state && state.unraid.needed) || '' } }, ID) })
      + (Number(r.times) > 1 ? ' · ' + T('refused_times', { n: Number(r.times) }) : '');
  }
  if (r.result === 'skipped') return T('skipped_mover', { min: Math.round((r.waited || 0) / 60) });
  const s = r.status || r;
  const mode = r.mode || s.mode;
  if (s.result === 'busy') return T('result.busy');
  if (s.result === 'config') return s.message || T('result.config');
  if (mode === 'list') return nOf('shares', (s.shares || []).length);
  const notes = [];
  if (s.result === 'stopped') notes.push(T(r.why === 'mover' ? 'stopped_mover' : 'stopped_user', { share: s.stop_share || '?' }));
  if (mode === 'dry') notes.push(T('dry_summary', { files: nOf('files', s.files), size: fmt.size(s.bytes || 0) }));
  else notes.push(T('run_summary', { moved: s.moved || 0, files: s.files || 0, size: fmt.size(s.bytes || 0), left: nOf('left', s.left) }));
  if (s.errors) notes.push(nOf('errors', s.errors));
  if (r.waited) notes.push(T('waited', { min: Math.round(r.waited / 60) }));
  return notes.join(' · ');
}

function bubbleText() {
  if (!state) return T('bubble.loading');
  const intro = T('bubble.intro');
  if (!unraidOk()) return `${intro} ${T('bubble.unraid_old', { version: state.unraid.version || '?', needed: state.unraid.needed })}`;
  if (!state.configured) return `${intro} ${T('bubble.setup')}`;
  if (running()) return T('bubble.running');
  if (!othersOff()) return T('bubble.dry');
  if (!state.schedule.enabled && !after().on) return T('bubble.no_schedule');
  return T('bubble.ready');
}

// ------------------------------------------------------------------ Unraid's mover
/**
 * Unraid's own mover schedule: on — why only lists and dry runs go, «Switch Unraid's mover schedule off…» and «Set it
 * yourself» (Unraid's ⟦Mover Settings⟧); off — that ⟦Move now⟧ by hand still runs Unraid's mover. At work right now.
 */
function moverNotice() {
  const m = mover();
  const box = el('div', m.off ? 'mo-mover' : 'callout warn mo-mover');
  if (!m.off) {
    box.appendChild(el('p', '', T('mover.on', { schedule: m.schedule ? fmt.cron(m.schedule) : '?' })));
    const bar = el('div', 'toolbar');
    const b = button(T('mover.off'), 'small', moverOffDialog);
    b.disabled = !Office.agent.running;
    const a = el('a', 'btn small plain', T('mover.self'));
    a.href = '/Settings/MoverSettings';
    bar.append(b, a);
    box.appendChild(bar);
  } else {
    box.appendChild(el('p', 'role', T('mover.ok')));
  }
  if (m.running) box.appendChild(el('p', 'role', T('mover.running')));
  return box;
}

/**
 * The other movers that run on a schedule of their own besides Unraid's: Mover Tuning's own cron line (real runs refused
 * until it is off — in Mover Tuning's settings, never changed by her), User Scripts and cron lines that call a mover
 * (named); and always the hint that such scripts belong switched off while she is in charge.
 */
function foreignNotices() {
  const f = foreign();
  const out = [];
  if (f.tuning.active) {
    const l = f.tuning.lines[0] || {};
    out.push(el('p', 'callout warn', T('notice.tuning', { schedule: l.cron ? fmt.cron(l.cron) : '?', file: l.file || '' })));
  }
  if ((f.scripts || []).length) out.push(el('p', 'callout warn', T('notice.scripts', { where: f.scripts.join(', ') })));
  else out.push(el('p', 'role mo-hint', T('notice.scripts_hint')));
  return out;
}

/** «Switch Unraid's mover schedule off…»: what it does, then moverelli.mover_off {confirm: true} */
async function moverOffDialog() {
  if (!(await Office.freshState(ID))) return;
  if (mover().off) { render(); return; }      // off meanwhile: the notice says so
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

/**
 * Her setup's consent while Unraid's own mover schedule is on: she takes Unraid's mover by the hand (its own schedule
 * goes, the one it had named), ⟦Move now⟧ stays, her let-go gives it back — no Settings path here (that is in her help). → true (agreed), false (nothing to switch off), null (cancelled)
 */
function moverConsent() {
  const m = mover();
  if (m.off) return Promise.resolve(false);
  return new Promise((resolve) => {
    let agreed = false;
    const body = el('div', 'mo-consent');
    body.append(el('p', '', T('consent.text', { schedule: m.schedule ? fmt.cron(m.schedule) : '?' })),
      el('p', 'role', T(jack().hired ? 'consent.move_now' : 'consent.move_now_solo')), el('p', 'role', T('consent.back')));
    Office.dialog({
      title: T('consent.title'),
      body,
      buttons: [{ text: Office.t('common.cancel') }, { text: T('consent.go'), kind: '', act: () => { agreed = true; resolve(true); return true; } }],
      onClose: () => { if (!agreed) resolve(null); },
    });
  });
}

// ------------------------------------------------------------------ main view
/*
 * Her page (tiles v2, CLAUDE.md «Tiles», 2026-10-12): a tile is a section. The row — «Schedule» (hers and Unraid's
 * mover's), «Shares» (what moves where, the rules for all), «Last run» (her runs, the last one's result on top) — is the
 * page's table of contents; under it stands the chosen tile's section alone, or, nothing chosen (the start), every section
 * in the row's order — her page as it was. Hints stay above the row at every click (Unraid's mover, other movers, Jack
 * Emby, a run going, not set up), the tool's version and the credit to helmi1987 under all. Two pages as tabs: «Overview»
 * and «Set up» (#/moverelli/setup).
 */
const PAGES = () => [{ id: '', text: Office.t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];
/** Set up (or not known yet — the search's places): her schedule has a tile only then */
const configured = () => !state || !!state.configured;
/** [tile id, lang key] of her row, in its order (the switcher's tiles, her places) */
const TILES = [['schedule', 'schedule'], ['shares', 'shares'], ['runs', 'last_run']];
let sw = null;
/** Her row and its sections (core.js's section switcher) — made at the first drawing, one per page life */
function switcher() {
  if (sw) return sw;
  sw = Office.sections({
    id: ID,                          // kept as moverelli.tile, routes #/moverelli/<tile>
    grid: 'stats mo-tiles',
    default: '',                     // the start: every section (her page as it always was)
    tiles: () => tileList(),
  });
  return sw;
}
/** The tiles for the switcher, each with its section (the schedule only once she is set up) */
function tileList() {
  const make = { schedule: scheduleTile, shares: sharesTile, runs: lastRunTile };
  const section = { schedule: scheduleSection, shares: shareSection, runs: historySection };
  return TILES.filter(([id]) => id !== 'schedule' || configured())
    .map(([id, key]) => ({ id, key, place: `tile.${id}`, tile: (a) => (state ? make[id](a) : Office.stat({ label: T(key), value: '–', ...a })),
      section: () => (state ? section[id]() : null) }));
}

/** The desk's own actions — on both tabs (the other page is the tab, no head button for it) */
function headActions() {
  const actions = [];
  if (state && state.configured) {
    actions.push(button(T('list'), 'plain', () => startRun('list')));
    actions.push(button(T('start'), '', chooseRun));
  }
  actions.forEach((b) => { b.disabled = !Office.agent.running || running() || !unraidOk(); });
  return actions;
}
/** Her head with the two tabs (Office.pages()) */
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
    [T('list'), T('help.list')],
    [T('mode.dry'), T('help.dry')],
    [T('mode.run'), T('help.run')],
    [T('help.rules'), T('help.rules_text')],
    [T('help.unraid'), T('help.unraid_text')],
    [T('help.emby'), T('help.emby_text')],
    [T('help.schedule'), T('help.schedule_text')],
  ]));
  if (!state) return;

  // hints, no sections: they stay at every click
  hints().forEach((n) => root.appendChild(n));
  const s = switcher();
  root.appendChild(s.el);
  s.render();
  root.appendChild(footer());
}

/** What stands over the row: Unraid's mover, other movers, «after Jack Emby» idle, Jack Emby, a run going, not set up */
function hints() {
  const out = [];
  if (!unraidOk()) out.push(el('p', 'callout warn', T('notice.unraid_old', { version: state.unraid.version || '?', needed: state.unraid.needed })));
  if (state.configured) {
    out.push(moverNotice());
    out.push(...foreignNotices());
  }
  const idle = afterNotice();
  if (idle) out.push(idle);
  const j = jack();
  if (j.busy && !running()) out.push(el('p', 'callout', T('notice.jack_busy')));
  // EmbyCache's list in her rules before EmbyCache's first run: her real runs wait for it (said here, never notified)
  if (j.pending) out.push(el('p', 'callout', T('notice.jack_pending')));
  if ((j.uncovered || []).length) {
    const p = el('p', 'callout warn', T('notice.emby_list', { shares: j.uncovered.join(', '), file: j.list || '' }) + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    out.push(p);
  }
  if (running()) {
    const p = el('p', 'callout', T('notice.running', { mode: T('mode.' + state.job.mode) }) + ' ');
    p.appendChild(button(T('show_output_now'), 'small', () => showOutput(true))).disabled = !Office.agent.running;
    if (realRun()) {
      p.appendChild(document.createTextNode(' '));
      const stop = button(state.stopping ? T('stopping') : T('stop'), 'small plain', async () => { stop.disabled = true; await act('stop'); });
      stop.disabled = !!state.stopping || !Office.agent.running;
      p.appendChild(stop);
    }
    out.push(p);
  }
  if (!state.configured) {
    const p = el('p', 'callout', T('notice.setup') + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    out.push(p);
  }
  return out;
}

// ---- the tiles: each the summary of its section
/** «Schedule»: hers (a time, «after Jack Emby», none), Unraid's mover's as its line — alert while nothing starts her or Unraid's is on */
function scheduleTile(a) {
  const sc = state.schedule || {};
  const af = after();
  const m = mover();
  const sub = [af.on ? T(af.idle ? 'schedule.after_idle_sub' : 'schedule.after_sub') : (sc.enabled ? T('by_office') : T('tile.no_schedule')),
    `${T('unraid_schedule')}: ${m.off ? T('unraid_off') : T('unraid_on')}`];
  return Office.stat({ label: T('schedule'), value: schedText(sc), sub, tip: sub.join('\n'), alert: !m.off || (af.on ? af.idle : !sc.enabled), ...a });
}
/** «Shares»: how many move, the rules for all as its line — alert while Jack Emby's list isn't kept out for a share of his */
function sharesTile(a) {
  const shares = state.shares || [];
  const g = (state.settings || {}).global || {};
  const rule = [T('rule_age', { n: g.min_age ?? 0, stat: g.age_stat || 'ctime' }),
    g.move_when_used_above ? T('rule_threshold', { pct: g.move_when_used_above }) : T('rule_always')].join(' · ');
  const sub = [T('shares_moving', { n: shares.filter((x) => x.state === 'moves').length }), rule];
  return Office.stat({ label: T('shares'), value: `${shares.filter((x) => x.state === 'moves').length} / ${shares.length}`, sub, tip: sub.join('\n'),
    alert: !!(jack().uncovered || []).length, ...a });
}
/** «Last run»: when, which mode and how it went — «running» while one goes */
function lastRunTile(a) {
  const last = state.last;
  let value = T('never');
  const sub = [];
  let alert = false;
  if (running()) {
    value = T('output_running');
    sub.push(T('mode.' + state.job.mode));
  } else if (last && last.finished) {
    value = fmt.relative(last.finished);
    sub.push(`${T('mode.' + (last.mode || 'dry'))} · ${T('result.' + (last.result || 'failed'))}`);
    alert = !!(last.errors || !['ok', 'stopped'].includes(last.result));
  }
  return Office.stat({ label: T('last_run'), value, sub, tip: sub.join('\n'), alert, ...a });
}

// ---- the sections
/** «Schedule»: when her real runs go on their own («Change…»), and Unraid's own mover schedule (off: as it should be) */
function scheduleSection() {
  const s = section(T('schedule'), T('schedule_sub'), { place: 'schedule' });
  const box = el('div', 'box');
  const sc = state.schedule || {};
  const af = after();
  const mine = Office.place('sched:mine', el('div', 'row nocheck'));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name', T('sched.mine')));
  const meta = el('div', 'row-meta');
  meta.appendChild(sc.enabled || af.on ? el('span', '', schedText(sc)) : chip(T('not_scheduled'), 'warn'));
  meta.appendChild(el('span', '', af.on ? T(af.idle ? 'schedule.after_idle_sub' : 'schedule.after_sub') : sc.enabled ? T('by_office') : T('schedule.off_hint')));
  main.appendChild(meta);
  const change = button(T('sched.change'), 'small plain', () => scheduleDialog());
  change.disabled = !Office.agent.running;
  mine.append(main, change);
  box.appendChild(mine);
  const m = mover();
  const own = Office.place('sched:unraid', el('div', 'row nocheck'));
  const main2 = el('div', 'row-main');
  main2.appendChild(el('div', 'row-name', T('unraid_schedule')));
  const meta2 = el('div', 'row-meta');
  meta2.appendChild(chip(m.off ? T('unraid_off') : T('unraid_on'), m.off ? 'ok' : 'warn'));
  meta2.appendChild(el('span', '', m.off ? T('unraid_off_sub') : (m.schedule ? fmt.cron(m.schedule) : '')));
  main2.appendChild(meta2);
  own.appendChild(main2);
  box.appendChild(own);
  s.appendChild(box);
  return s;
}

const STATE_CLASS = { moves: 'ok', skip: 'quiet', prefer_off: 'quiet', none: 'quiet' };
// the lists' filter bars (Office.filterBar — built once per visit, never kept): her shares, her runs, the setup's shares
let sharesBar = null;
let runsBar = null;
let setupBar = null;
const dom = {};
/** Do the bar's words fit this hay (no bar, no words: yes) */
const fitsBar = (bar, hay) => { const f = bar ? bar.read() : null; return !f || !f.words.length || Office.fits(f.words, hay); };
function bars() {
  if (!sharesBar) sharesBar = Office.filterBar({ key: 'moverelli.shares', label: T('filter.shares'), onChange: () => { if (dom.shares && dom.shares.isConnected) fillShares(dom.shares); } });
  if (!runsBar) runsBar = Office.filterBar({ key: 'moverelli.runs', label: T('filter.runs'), onChange: () => { if (dom.runs && dom.runs.isConnected) fillRuns(dom.runs); } });
  if (!setupBar) setupBar = Office.filterBar({ key: 'moverelli.setup', label: T('filter.shares'), onChange: () => { if (dom.setup && dom.setup.isConnected) fillSetupShares(dom.setup); } });
  sharesBar.reset();
  runsBar.reset();
  setupBar.reset();
}

/** Every share as Smart Mover sees it: what moves where, under which rules */
function shareSection() {
  const shares = state.shares || [];
  const moving = shares.filter((x) => x.state === 'moves').length;
  const s = section(T('shares'), T('shares_sub'), chip(T('shares_moving', { n: moving }), moving ? 'ok' : 'quiet'), { place: 'shares' });
  if (sharesBar) s.appendChild(sharesBar.el);
  const box = el('div', 'box');
  dom.shares = box;
  fillShares(box);
  s.appendChild(box);
  return s;
}
/** A share's words for the bar: its name, what it does, where its files go */
const shareHay = (x) => [x.share, T('state.' + x.state), where(x.from), where(x.to), x.primary || ''];
function fillShares(box) {
  box.innerHTML = '';
  const shares = state.shares || [];
  const fit = shares.filter((x) => fitsBar(sharesBar, shareHay(x)));
  fit.forEach((x) => {
    const row = Office.place(`share:${x.share}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', x.share));
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(T('state.' + x.state), STATE_CLASS[x.state] || 'quiet', T('state_tip.' + x.state)));
    if (x.mode !== 'none') meta.appendChild(el('span', '', T('direction', { from: where(x.from), to: where(x.to) })));
    else meta.appendChild(el('span', '', T('share_where', { primary: x.use === 'no' || !x.use ? T('array') : (x.primary || '–') })));
    if (x.own) meta.appendChild(chip(T('own_rules'), 'quiet'));
    main.appendChild(meta);
    if (x.state === 'moves') {
      const rules = el('div', 'row-meta');
      rules.appendChild(el('span', '', T('rule_age', { n: x.min_age, stat: x.age_stat })));
      if (x.threshold) rules.appendChild(el('span', '', T('rule_threshold', { pct: x.threshold })));
      if (x.excludes) rules.appendChild(el('span', '', nOf('lists', x.excludes)));
      main.appendChild(rules);
    }
    row.appendChild(main);
    box.appendChild(row);
  });
  if (!shares.length) box.appendChild(el('p', 'empty', T('shares_none')));
  if (sharesBar) sharesBar.done(fit.length, shares.length);
}

/** «My runs»: the last run's result on top (its shares, the log, its output), then every run with how it went */
function historySection() {
  const s = section(T('history'), T('history_sub'), { place: 'history' });
  const rb = resultBox();
  if (rb) s.appendChild(rb);
  const bar = el('div', 'toolbar');
  if (state.configured) bar.appendChild(button(T('show_log'), 'small plain', showLog));
  if (state.job && state.job.mode) bar.appendChild(link(T('show_output', { mode: T('mode.' + state.job.mode) }), () => showOutput(false)));
  if (bar.children.length) s.appendChild(bar);
  if (runsBar) s.appendChild(runsBar.el);
  const box = el('div', 'box');
  dom.runs = box;
  fillRuns(box);
  s.appendChild(box);
  return s;
}
/** The last run: how it went, a line per share that had something */
function resultBox() {
  const last = state.last;
  if (!last || !last.result) return null;
  const box = el('div', 'box mo-results');
  const h = (state.history || []).find((r) => !['refused', 'skipped'].includes(r.result));
  box.appendChild(el('div', '', runSummary({ mode: last.mode, status: last, result: last.result, why: h && h.result === last.result ? h.why : null })));
  (last.shares || []).filter((x) => x.files || x.moved || x.left).forEach((x) => {
    box.appendChild(el('div', 'mo-share-line', T(last.mode === 'run' ? 'share_moved' : 'share_would', { share: x.share,
      moved: x.moved || 0, files: nOf('files', x.files), size: fmt.size(x.bytes || 0), left: nOf('left', x.left) })));
  });
  return box;
}
/** A run's words for the bar: its mode, result, who started it, the summary */
const runHay = (r) => [T('mode.' + r.mode), T('result.' + (r.result || 'failed')), T('by.' + r.by), runSummary(r)];
function fillRuns(box) {
  box.innerHTML = '';
  const runs = state.history || [];
  const fit = runs.filter((r) => fitsBar(runsBar, runHay(r)));
  if (!runs.length) box.appendChild(el('p', 'empty', T('history_none')));
  fit.forEach((r) => {
    const row = Office.place(`run:${r.started}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', T('mode.' + r.mode)));
    const meta = el('div', 'row-meta');
    const ok = r.result === 'ok';
    const cls = ['refused', 'skipped', 'stopped'].includes(r.result) ? 'quiet' : ok ? 'ok' : 'warn';
    meta.append(chip(T('result.' + (r.result || 'failed')), cls, r.exit != null ? T('exit_code', { n: r.exit }) : ''),
      el('span', '', fmt.date(r.started)), el('span', '', T('by.' + r.by)));
    if (r.finished && r.finished - r.started >= 60) meta.appendChild(el('span', '', fmt.duration(r.finished - r.started)));
    main.append(meta, el('div', 'row-meta', runSummary(r)));
    row.appendChild(main);
    box.appendChild(row);
  });
  if (runsBar) runsBar.done(fit.length, runs.length);
}

/** A quiet line under the tool's versions: the Smart Mover is helmi1987's */
function credit() {
  const box = el('div', 'mo-credit');
  box.append(el('b', '', T('credit_title')), ' ', T('credit'), ' ');
  const a = el('a', '', ORIGIN[0]);
  a.href = ORIGIN[1];
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  box.appendChild(a);
  return box;
}
/** Under all (no section): Smart Mover's and Unraid's versions, then the credit to helmi1987 */
function footer() {
  const box = Office.place('tools', el('div', 'mo-foot'));
  const line = el('div', 'row-meta mo-tools');
  line.append(el('span', '', `Smart Mover ${state.engine || '?'} · ${T('tool_sub')}`),
    el('span', unraidOk() ? '' : 'mo-old', `Unraid ${(state.unraid && state.unraid.version) || '?'} · ${T('unraid_sub', { needed: (state.unraid && state.unraid.needed) || '' })}`));
  box.append(line, credit());
  return box;
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

/** «Start…»: a dry run, or a real one — only while Unraid's schedule is off and Jack Emby's list is kept out */
async function chooseRun() {
  if (!(await Office.freshState(ID))) return;
  let mode = 'dry';
  const off = mover().off;
  const tuning = foreign().tuning.active;
  const covered = !(jack().uncovered || []).length;
  const box = radioList('mo-mode', [
    ['dry', T('mode.dry'), T('mode_hint.dry')],
    ['run', T('mode.run'), !off ? T('mode_hint.run_mover') : tuning ? T('mode_hint.run_tuning') : !covered ? T('mode_hint.run_emby') : T('mode_hint.run'), !off || tuning || !covered],
  ], mode, (m) => { mode = m; });
  const err = errorLine();
  box.appendChild(err);
  Office.dialog({
    title: T('start_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('start_go'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.start_run`, { mode });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      if (view && page === 'main') render();
      showOutput(true);
      return true;
    } }],
  });
}

async function startRun(mode) {
  const j = await act('start_run', { mode });
  if (j) showOutput(true);
}

/** The output of her last run, following it while it runs */
async function showOutput(follow) {
  clearTimeout(outTimer);
  const pre = el('pre', 'code', Office.t('common.loading'));
  const status = el('p', 'role');
  const box = el('div');
  box.append(status, pre);
  const dlg = Office.dialog({ title: T('output_title'), body: box, wide: 'log', onClose: () => clearTimeout(outTimer) });
  let wasRunning = false;
  const tick = async () => {
    const j = await Office.api.post(`${ID}.output`, {});
    if (!j.ok) { pre.textContent = Office.errorText(j.error, ID); return; }
    const atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
    pre.textContent = j.text || T('output_empty');
    const info = j.info || {};
    status.textContent = info.mode ? `${T('mode.' + info.mode)} · ${info.running ? T('output_running') : T('output_done')} · ${fmt.date(info.started)}` : '';
    if (atEnd || follow) pre.scrollTop = pre.scrollHeight;
    const young = info.started && Date.now() / 1000 - info.started < 10;      // its first line may not be written yet
    if (info.running || (follow && young && !wasRunning)) { wasRunning = wasRunning || info.running; outTimer = setTimeout(tick, POLL); }
    else if (follow) { follow = false; load(true); }
  };
  tick();
  return dlg;
}

async function showLog() {
  const pre = el('pre', 'code', Office.t('common.loading'));
  Office.dialog({ title: 'smart_mover.log', body: pre, wide: 'log' });
  const j = await Office.api.post(`${ID}.log`, {});
  pre.textContent = j.ok ? (j.text || T('output_empty')) : Office.errorText(j.error, ID);
  pre.scrollTop = pre.scrollHeight;
}

/** «Right after Jack Emby» chosen, but nothing starts her by herself: Jack let go, or his EmbyCache without a schedule */
function afterNotice() {
  if (!state || !state.configured || !after().idle) return null;
  const p = el('p', 'callout warn', T(after().jack ? 'notice.after_idle_off' : 'notice.after_idle_letgo') + ' ');
  p.appendChild(button(T('schedule_title'), 'small', scheduleDialog)).disabled = !Office.agent.running;
  return p;
}

// ------------------------------------------------------------------ her schedule
/** When Smart Mover runs for real on its own: once a night (default 05:15), a cron expression, or not at all */
async function scheduleDialog() {
  if (!(await Office.freshState(ID))) return;
  if (!state.configured) {
    Office.dialog({
      title: T('schedule_title'),
      body: T('schedule.need_setup'),
      buttons: [{ text: Office.t('common.close') }, { text: T('setup_open'), kind: '', act: () => { Office.go(`#/${ID}/setup`); } }],
    });
    return;
  }
  const sc = state.schedule || {};
  const cur = (sc.enabled && sc.custom) || '';
  const pad = (n) => String(n).padStart(2, '0');
  const m2 = /^(\d{1,2}) (\d{1,2}) \* \* \*$/.exec(cur);
  const af = after();
  let mode = af.on ? 'after' : !sc.enabled ? 'daily' : m2 ? 'daily' : 'custom';
  const time = el('input', 'input');
  time.type = 'time';
  time.value = m2 ? `${pad(m2[2])}:${pad(m2[1])}` : '05:15';
  const cron = el('input', 'input mono');
  cron.value = cur || '15 5 * * *';
  cron.spellcheck = false;
  const box = el('div', 'mo-schedule');
  box.appendChild(el('p', '', T('schedule.intro')));
  if (!othersOff()) box.appendChild(el('p', 'callout warn', T('schedule.unraid_on')));
  const option = (id, text, hint, extra, off) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'mo-schedule';
    input.checked = mode === id;
    input.disabled = !!off;
    input.onchange = () => { mode = id; update(); };
    const span = el('span', '', text);
    if (hint) span.appendChild(el('small', '', hint));
    label.append(input, span);
    box.appendChild(label);
    if (extra) {
      const f = el('div', 'mo-schedule-field');
      f.appendChild(extra);
      box.appendChild(f);
    }
  };
  option('daily', T('schedule.nightly'), T('schedule.nightly_hint'), time);
  option('custom', T('schedule.custom'), T('schedule.custom_hint'), cron);
  // the third way (2026-10-11): right after Jack Emby's scheduled EmbyCache runs — only while he is with us
  option('after', T('schedule.after'), af.jack ? T('schedule.after_hint') : T('schedule.after_no_jack'), null, !af.jack && mode !== 'after');
  option('off', T('schedule.off'), T('schedule.off_hint'));
  const update = () => { time.disabled = mode !== 'daily'; cron.disabled = mode !== 'custom'; };
  update();
  Office.dialog({
    title: T('schedule_title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('schedule.save'), kind: '', act: async () => {
        let expr = '';
        if (mode === 'daily') {
          if (!/^\d\d:\d\d$/.test(time.value)) { Office.toast(T('schedule.need_time'), true); return false; }
          const [h, m] = time.value.split(':').map(Number);
          expr = `${m} ${h} * * *`;
        } else if (mode === 'custom') expr = cron.value.trim();
        const j = await Office.api.post(`${ID}.schedule`, mode === 'after' ? { cron: null, after: 'emby' } : { cron: expr, after: '' });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.state) state = j.state;
        if (!j.live) Office.toast(T('schedule.not_live'), true);
        else Office.toast(mode === 'after' ? T('schedule.saved_after') : expr ? T('schedule.saved_on', { when: fmt.cron(expr) }) : T('schedule.saved_off'));
        if (view && page === 'main') render();
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ setup (#/moverelli/setup)
/*
 * Everything smart_mover.ini holds: the rules for all shares (age, which time counts, the pool's fill, prefer shares,
 * the move binary's debug level, lines in a dry run's output, files to leave alone), then per share its own (left
 * out, its own age and fill, more files to leave alone). Files to leave alone: exclude lists elsewhere (one path per
 * line) and her own lines (paths, globs, words — Smart Mover's exclude format).
 */
const linesOf = (text) => String(text).split('\n').map((x) => x.replace(/\r$/, ''));
const filesOf = (text) => linesOf(text).map((x) => x.trim()).filter(Boolean);

function initForm() {
  const set = JSON.parse(JSON.stringify(state.settings));
  const shares = {};
  (state.shares || []).forEach((x) => {
    const s = set.shares[x.share] || {};
    shares[x.share] = { skip: !!s.skip, min_age: s.min_age ?? null, age_stat: s.age_stat ?? null, move_when_used_above: s.move_when_used_above ?? null,
      files: s.files || [], patterns: s.patterns || [] };
  });
  form = { global: set.global, shares };
  // Jack Emby hired and his list not kept out for every share of his: proposed in the files for all shares — in the form
  // only, nothing is written before «Save»; what was there stays, «Remove it again» takes exactly this line out
  const list = jack().list;
  if (list && jackUncoveredIn(form).length) {
    form.global.files = [...form.global.files, list];
    form.jackAdded = list;
  }
}

/**
 * Over the setup's row (a hint, it stays at every click): Jack Emby's list added for you — why, save to keep it, «Remove
 * it again»; or, taken out (or never added), the shares of his still not covered
 */
function jackNotice() {
  const j = jack();
  if (!j.list || !form) return null;
  if (form.jackAdded && form.global.files.includes(form.jackAdded)) {
    const p = el('p', 'callout mo-suggest', T('setup.jack_added', { file: form.jackAdded }) + ' ');
    p.appendChild(button(T('setup.jack_remove'), 'small plain', () => {
      form.global.files = form.global.files.filter((x) => x !== form.jackAdded);
      form.jackAdded = null;
      renderSetup();
    }));
    return p;
  }
  const open = jackUncoveredIn(form);
  return open.length ? el('p', 'callout warn', T('errors.moverelli_emby_list', { shares: open.join(', ') })) : null;
}

function input(value, onchange, cls) {
  const i = el('input', 'input' + (cls ? ' ' + cls : ''));
  i.value = value ?? '';
  i.oninput = () => onchange(i.value);
  return i;
}
/** A whole number; optional: empty = null (the rule for all shares counts) */
function number(obj, key, min, max, optional) {
  const i = el('input', 'input');
  i.type = 'number';
  i.min = min;
  i.max = max;
  i.value = obj[key] ?? '';
  if (optional) i.placeholder = T('setup.as_global');
  i.oninput = () => { const v = i.value.trim(); obj[key] = v === '' && optional ? null : Number(v); };
  return i;
}
function select(obj, key, options) {
  const s = el('select', 'picker');
  options.forEach(([value, text]) => s.appendChild(new Option(text, value)));
  s.value = obj[key] ?? '';
  s.onchange = () => { obj[key] = s.value === '' ? null : (/^\d+$/.test(s.value) ? Number(s.value) : s.value); };
  return s;
}
function textarea(lines, onchange, rows) {
  const ta = el('textarea', 'input mono');
  ta.rows = rows || 3;
  ta.spellcheck = false;
  ta.value = (lines || []).join('\n');
  ta.oninput = () => onchange(ta.value);
  return ta;
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
function keepFold(det, key, open) {
  det.open = folds.has(key) ? folds.get(key) : open;
  let shown = det.open;
  det.addEventListener('toggle', () => {
    if (det.open === shown) return;
    shown = det.open;
    folds.set(key, det.open);
  });
  return det;
}

/** «Files to leave alone» of a scope: other lists (paths) and her own lines; EmbyCache's list one click away */
function excludeFields(obj, scope) {
  const box = el('div', 'mo-form');
  const files = textarea(obj.files, (v) => { obj.files = filesOf(v); });
  box.append(field(T('setup.files'), files, T('setup.files_hint')),
    field(T('setup.patterns'), textarea(obj.patterns, (v) => { obj.patterns = linesOf(v); }, 4), T('setup.patterns_hint')));
  const list = jack().list;
  if (list && !obj.files.includes(list)) {
    const b = button(T('setup.add_emby'), 'small plain', () => { obj.files = [...obj.files, list]; files.value = obj.files.join('\n'); b.remove(); });
    b.title = list;
    b.dataset.scope = scope;
    box.appendChild(b);
  }
  return box;
}

function renderSetup() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  // the tabs say where one is (no «← Back», no head button): under them the page's head line, her setup words its sub line
  root.appendChild(deskHead());
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('setup.global'), T('setup.help_global')],
    [T('setup.files'), T('setup.help_files')],
    [T('setup.shares'), T('setup.help_shares')],
    [T('setup.save'), T('setup.help_save')],
    [T('import.title'), T('import.help')],
  ]));
  const ph = el('div', 'mo-pagehead');
  ph.append(el('div', 'mo-pagehead-title', T('page.setup')), el('p', 'mo-pagehead-sub', T('setup.bubble')));
  root.appendChild(ph);
  if (!state) return;
  if (!form) initForm();
  // hints over the row (they stay at every click): Jack Emby's list — added for you, or still missing for a share of his
  const jn = jackNotice();
  if (jn) root.appendChild(jn);
  if (!state.configured) root.appendChild(importSection());     // first thing for someone who ran the Smart Mover before
  // the row of her setup's steps and under it the step chosen (none: every step in order — the page as before the tiles)
  const s = setupSwitcher();
  root.appendChild(s.el);
  s.render();
  // the save bar under all
  const bar = el('div', 'toolbar');
  const save = button(T('setup.save'), '', saveSetup);
  save.disabled = !Office.agent.running || running();
  bar.appendChild(save);
  root.appendChild(bar);
}

// ---- the setup's steps as tiles (tiles v2, 2026-10-12, like Jack Emby's): a number tile per step — the rules for all
// shares, the shares' own, and (once she is set up) taking over an earlier install; a click shows that step alone, nothing
// chosen = every step in order; kept as moverelli.setup.tile, a step's route #/moverelli/setup/<tile>. The form lives
// across every drawing (form): a step drawn anew shows what was typed.
/** [tile id, lang key (the step's title: the tile's label, its place for the search)] in the steps' order */
const SETUP_TILES = [['global', 'setup.global'], ['shares', 'setup.shares'], ['import', 'import.title']];
let setupSw = null;
function setupSwitcher() {
  if (setupSw) return setupSw;
  setupSw = Office.sections({
    id: `${ID}/setup`,           // the routes #/moverelli/setup/<tile>
    key: 'moverelli.setup.tile',
    bodyId: 'mo-setup-steps',
    grid: 'stats mo-setup-tiles',
    default: '',                 // the start: every step
    tiles: () => setupTileList(),
    wants: (sub) => SETUP_TILES.some(([id]) => id === sub),      // a route before her state is in
  });
  return setupSw;
}
/** The tiles for the switcher: setupTileData() as it stands, each with its step as its section (none before her state) */
function setupTileList() {
  if (!state || !form) return [];
  return setupTileData().map((d) => ({ id: d.id, key: d.key, place: `setup.tile.${d.id}`,
    tile: (a) => Office.stat({ label: T(d.key), value: d.value, sub: d.sub.filter(Boolean), alert: d.alert, ...a }), section: () => setupStep(d.id) }));
}
function setupStep(id) {
  if (id === 'global') return setupGlobal();
  if (id === 'shares') return setupShares();
  return importSection();
}

/**
 * Her setup's tiles, pure (no DOM): [{id, key, value, sub: [text], alert}] in the steps' order — for all shares (the age,
 * the fill, how many lists and lines of what stays; alert while a share of Jack Emby's isn't covered), per share (how many
 * have rules of their own, how many are left out), taking over an earlier install (once she is set up).
 */
function setupTileData() {
  const g = form.global;
  const own = Object.values(form.shares).filter((s) => s.skip || s.min_age !== null || s.age_stat !== null || s.move_when_used_above !== null
    || s.files.length || s.patterns.join('\n').trim() !== '');
  const lines = g.patterns.filter((x) => x.trim() !== '' && !x.trim().startsWith('#')).length;
  const out = [];
  out.push({ id: 'global', key: 'setup.global', value: T('setup.tile.days', { n: Number(g.min_age) || 0 }),
    sub: [g.move_when_used_above ? T('rule_threshold', { pct: g.move_when_used_above }) : T('rule_always'),
      T('setup.tile.stays', { lists: nOf('lists', g.files.length), lines: nOf('lines', lines) })], alert: jackUncoveredIn(form).length > 0 });
  out.push({ id: 'shares', key: 'setup.shares', value: String(own.length),
    sub: [T('setup.tile.own', { n: own.length }), T('setup.tile.skipped', { n: Object.values(form.shares).filter((s) => s.skip).length })], alert: false });
  if (state.configured) out.push({ id: 'import', key: 'import.title', value: T('setup.tile.optional'), sub: [], alert: false });
  return out;
}

/** 1. the rules for all shares */
function setupGlobal() {
  const g = form.global;
  const s1 = section(T('setup.global'), T('setup.global_sub'), { place: 'setup.global' });
  const f1 = el('div', 'mo-form');
  f1.append(
    field(T('setup.min_age'), number(g, 'min_age', 0, 3650), T('setup.min_age_hint')),
    field(T('setup.age_stat'), select(g, 'age_stat', [['ctime', T('age_stat.ctime')], ['mtime', T('age_stat.mtime')]]), T('setup.age_stat_hint')),
    field(T('setup.threshold'), number(g, 'move_when_used_above', 0, 100), T('setup.threshold_hint')),
    field(T('setup.debug'), select(g, 'mover_debug', [0, 1, 2, 3].map((n) => [String(n), String(n)])), T('setup.debug_hint')),
    field(T('setup.max_list'), number(g, 'max_list', 1, 100000), T('setup.max_list_hint')),
    check(T('setup.prefer'), g.move_prefer_shares, (on) => { g.move_prefer_shares = on; }, T('setup.prefer_hint')),
  );
  s1.append(f1, excludeFields(g, 'global'));
  return s1;
}

/** 2. per share: a fold each (the bar's words: name, where its files go) */
function setupShares() {
  const s2 = section(T('setup.shares'), T('setup.shares_sub'), { place: 'setup.shares' });
  if (setupBar) s2.appendChild(setupBar.el);
  const box = el('div');
  dom.setup = box;
  fillSetupShares(box);
  s2.appendChild(box);
  return s2;
}
function fillSetupShares(box) {
  box.innerHTML = '';
  const shares = (state.shares || []).filter((x) => form.shares[x.share]);
  const fit = shares.filter((x) => fitsBar(setupBar, shareHay(x)));
  fit.forEach((x) => {
    const sv = form.shares[x.share];
    const det = keepFold(el('details', 'box mo-share'), x.share, false);
    const sum = el('summary', '', x.share);
    sum.appendChild(chip(x.mode === 'none' ? T('state.none') : T('direction', { from: where(x.from), to: where(x.to) }), 'quiet'));
    if (x.own) sum.appendChild(chip(T('own_rules'), 'quiet'));
    det.appendChild(sum);
    if (x.mode === 'none') {
      det.appendChild(el('p', 'role', T('state_tip.none')));
    } else {
      const f = el('div', 'mo-form');
      f.append(check(T('setup.skip'), sv.skip, (on) => { sv.skip = on; }, T('setup.skip_hint')),
        field(T('setup.min_age'), number(sv, 'min_age', 0, 3650, true), x.mode === 'prefer' ? T('setup.min_age_prefer') : ''),
        field(T('setup.age_stat'), select(sv, 'age_stat', [['', T('setup.as_global')], ['ctime', T('age_stat.ctime')], ['mtime', T('age_stat.mtime')]])));
      if (x.mode === 'yes') f.appendChild(field(T('setup.threshold'), number(sv, 'move_when_used_above', 0, 100, true)));
      det.append(f, excludeFields(sv, x.share));
    }
    box.appendChild(det);
  });
  if (!shares.length) box.appendChild(el('p', 'empty', T('shares_none')));
  if (setupBar) setupBar.done(fit.length, shares.length);
}

async function saveSetup() {
  const shares = {};
  Object.entries(form.shares).forEach(([name, s]) => {
    const patterns = s.patterns.join('\n').trim() === '' ? [] : s.patterns;
    if (s.skip || s.min_age !== null || s.age_stat !== null || s.move_when_used_above !== null || s.files.length || patterns.length) {
      shares[name] = { ...s, patterns };
    }
  });
  const g = form.global;
  const settings = { global: { ...g, patterns: g.patterns.join('\n').trim() === '' ? [] : g.patterns }, shares };
  // Unraid's own mover goes off with her setup — asked first while it is on (agent/desks/moverelli-unraid.php)
  const consent = await moverConsent();
  if (consent === null) return;
  const j = await act('save', consent ? { settings, mover_off: true } : { settings });
  if (!j) return;
  Office.toast(T(j.mover_off ? 'setup.saved_mover_off' : 'setup.saved'));
  form = null;
  Office.go(`#/${ID}`);
}

// ------------------------------------------------------------------ taking over an earlier install
/*
 * Someone who ran helmi1987's Smart Mover before (usually from a User Script) types the folder of its smart_mover.ini;
 * the agent reads it and the lists it names on the server (agent/desks/moverelli-import.php), the preview says what
 * would come over and what is left out, the import needs its token. It replaces her settings as a whole. Afterwards:
 * switch the old User Script off yourself, start with a dry run, the copies of her files are named.
 */
let importFolder = '';
/** The setup's label of a key of the ini */
const IMPORT_LABEL = { min_age: 'setup.min_age', age_stat: 'setup.age_stat', move_when_used_above: 'setup.threshold', move_prefer_shares: 'setup.prefer',
  mover_debug: 'setup.debug', max_list: 'setup.max_list', skip: 'setup.skip' };

function importSection() {
  const b = button(T('import.open'), 'small', importDialog);
  b.disabled = !Office.agent.running;
  return section(T('import.title'), T('import.sub'), b, { place: 'import.title' });
}

function importDialog() {
  const box = el('div');
  box.appendChild(el('p', '', T('import.intro')));
  const f = el('div', 'mo-form');
  const i = input(importFolder, (x) => { importFolder = x; });
  i.placeholder = '/mnt/user/system/scripts/custommover';
  f.appendChild(field(T('import.folder'), i, T('import.folder_hint')));
  const err = errorLine();
  box.append(f, el('p', 'role', T('import.read_only')), err);
  Office.dialog({ title: T('import.title'), body: box, wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('import.look'), kind: '', act: () => importLook(err) }] });
}

async function importLook(err) {
  const folder = importFolder.trim();
  const j = await Office.api.post(`${ID}.import_preview`, { folder });
  if (!j.ok) { showError(err, j.error); return false; }
  importPreview(j.preview, folder);
  return true;
}

/** A value as her setup page says it */
function importValue(key, v) {
  if (typeof v === 'boolean') return T(v ? 'import.yes' : 'import.no');
  if (key === 'age_stat') return T('age_stat.' + v);
  return String(v);
}

/** A short list: the label (mono) and a text beside it */
function importList(items) {
  const ul = el('ul', 'shortlist');
  items.forEach(([k, text, cls]) => { const li = el('li', cls || '', k); if (text !== undefined) li.appendChild(el('span', '', text)); ul.appendChild(li); });
  return ul;
}
function importHead(text) { return el('p', 'mo-imp-head', text); }
const importScope = (scope) => (scope === 'GLOBAL' ? T('import.scope_global') : scope);

/** What a list file of the old ini became: copied (how many entries), Jack Emby's own named, or not read and why */
function importFileText(f) {
  if (f.how === 'jack') return T('import.how.jack');
  if (f.how === 'copied') return T('import.how.copied', { entries: nOf('entries', f.lines) });
  return T('import.how.not_read.' + (f.why || 'not_allowed'));
}

/** What stops the import, with what to do: a list not read, lines her lists can't take, a bad age or fill, too much */
function importBlockText(b) {
  const scope = importScope(b.scope);
  if (b.why === 'not_read') return T('import.block.not_read.' + (b.reason || 'not_allowed'), { scope, file: b.file });
  if (b.why === 'bad_lines') return T('import.block.bad_lines', { scope, n: Number(b.n) || 0 });
  if (b.why === 'value') {
    return T('import.block.value', { scope, key: T(IMPORT_LABEL[b.key] || 'setup.min_age'), reason: T('import.why.bad.' + b.reason, { value: b.value }) });
  }
  if (b.why === 'files') return T('import.block.files', { scope, n: b.n, max: b.max });
  return T('import.block.lines', { scope, max: b.max });
}

function importPreview(p, folder) {
  const box = el('div');
  box.appendChild(el('p', '', T('import.preview_bubble')));
  const kv = el('dl', 'kv');
  kv.append(el('dt', '', T('import.folder')), el('dd', 'mono', p.folder));
  box.appendChild(kv);
  (p.blocked || []).forEach((b) => box.appendChild(el('p', 'callout warn', importBlockText(b))));
  if ((p.jack_uncovered || []).length) box.appendChild(el('p', 'callout warn', T('errors.moverelli_emby_list', { shares: p.jack_uncovered.join(', ') })));
  if (p.configured) {
    box.appendChild(el('p', 'callout', T('import.replaces') + (p.gone.length ? ' ' + T('import.gone', { shares: p.gone.join(', ') }) : '')));
  }

  // the values: for all shares, then per share
  box.appendChild(importHead(T('setup.global')));
  box.appendChild(importList(p.global.map((x) => [T(IMPORT_LABEL[x.key]),
    importValue(x.key, x.value) + (x.old ? '' : ' · ' + T('import.default'))])));
  box.appendChild(importHead(T('setup.shares')));
  if (p.shares.length) {
    box.appendChild(importList(p.shares.map((s) => [s.share,
      s.values.length ? s.values.map((x) => `${T(IMPORT_LABEL[x.key])}: ${importValue(x.key, x.value)}`).join(' · ') : T('import.only_lists')])));
  } else {
    box.appendChild(el('p', 'role', T('import.shares_none')));
  }

  // the lists: what comes into hers, file by file
  if (p.lists.length) {
    box.appendChild(importHead(T('setup.files')));
    p.lists.forEach((l) => {
      box.appendChild(el('p', 'mo-imp-scope', `${importScope(l.scope)} — ${T('import.taken', { entries: nOf('entries', l.taken) })}`));
      box.appendChild(importList(l.files.map((f) => [f.file, importFileText(f), f.how === 'not_read' ? 'mo-imp-warn' : ''])));
      l.files.filter((f) => f.frozen).forEach(() => box.appendChild(el('p', 'role', T('import.how.frozen'))));
      if (l.sample.length) box.appendChild(el('p', 'role', T('import.sample', { lines: l.sample.join(' · ') })));
      if (l.bad) box.appendChild(el('p', 'role', T('import.bad_lines', { n: l.bad })));
    });
  }
  if (p.jack_lists) box.appendChild(el('p', 'role', T('import.jack_note')));

  // what is left out, and why
  if (p.left.length) {
    box.appendChild(importHead(T('import.left')));
    box.appendChild(importList(p.left.map((x) => [`[${x.section}] ${x.key}`,
      x.why === 'bad' ? T('import.why.bad.' + x.reason, { value: x.value }) : T('import.why.' + x.why, { value: x.value || '' })])));
  }
  if (p.skipped.length) {
    box.appendChild(importHead(T('import.skipped')));
    box.appendChild(importList(p.skipped.map((x) => [`[${x.section}]`, T('import.skip_why.' + x.why)])));
  }

  if (p.running) box.appendChild(el('p', 'callout warn', T('errors.moverelli_running')));
  else if (!p.ready) box.appendChild(el('p', 'callout warn', T('errors.moverelli_import_blocked')));
  else box.appendChild(el('p', 'role', T('import.backup_note')));
  // Unraid's own mover goes off with it, like her save: said here, the button says it too (its click is the consent)
  const off = !mover().off;
  if (off && p.ready && !p.running) {
    box.appendChild(el('p', 'callout', `${T('consent.text', { schedule: mover().schedule ? fmt.cron(mover().schedule) : '?' })} ${T('consent.back')}`));
  }
  const err = errorLine();
  box.appendChild(err);
  const buttons = [{ text: Office.t('common.cancel') }];
  if (p.ready && !p.running && Office.agent.running) buttons.push({ text: T(off ? 'import.go_mover_off' : 'import.go'), kind: '', act: () => importGo(folder, p, err, off) });
  Office.dialog({ title: T('import.preview_title'), body: box, wide: true, buttons });
}

async function importGo(folder, p, err, moverOff) {
  const j = await Office.api.post(`${ID}.import_apply`, moverOff ? { folder, token: p.token, mover_off: true } : { folder, token: p.token });
  if (!j.ok) { showError(err, j.error); return false; }
  if (j.state) state = j.state;
  form = null;
  importDone(j.done);
  return true;
}

function importDone(d) {
  const box = el('div');
  box.appendChild(el('p', '', T('import.done_bubble')));
  const what = [T('import.done_rules'), T('import.done_shares', { n: d.shares }), T('import.done_lines', { n: d.lines })];
  if (d.jack) what.push(T('import.done_jack'));
  box.appendChild(importList(what.map((w) => [w])));
  const still = (state && state.foreign && state.foreign.scripts) || [];
  box.appendChild(el('p', 'callout warn', T('import.done_switch_off') + (still.length ? ' ' + T('import.done_still', { where: still.join(', ') }) : '')));
  box.appendChild(el('p', 'callout', T('import.done_dry')));
  if (d.backups.length) {
    box.append(importHead(T('import.done_copies')), importList(d.backups.map((f) => [f])), el('p', 'role', T('import.done_undo', { stamp: d.stamp })));
  } else {
    box.appendChild(el('p', 'role', T('import.done_nothing_before')));
  }
  Office.dialog({ title: T('import.done_title'), body: box, wide: true,
    buttons: [{ text: Office.t('common.close'), kind: '' }], onClose: () => Office.go(`#/${ID}`) });
}

// ------------------------------------------------------------------ let go
/**
 * Her part of the let-go dialog (core.js Office.fireDialog → letGo; agent/desks/moverelli-unraid.php). She looks first
 * (moverelli.letgo_look): Jack Emby still hired — she can't go, the intern never works unsupervised (the button stays
 * off; src/staff.php refuses too); else her schedule goes off and Unraid's own mover schedule comes back as it was before
 * her. «Let go» — while she is still hired — sends moverelli.letgo {confirm} once; afterwards a dialog says what she did.
 */
function letGoPart(box) {
  const lines = el('div', 'mo-letgo');
  box.appendChild(lines);
  let button = null;
  let blocked = false;
  let sent = null;
  const show = (j) => {
    lines.replaceChildren(...letGoView(j).map(([cls, text]) => el('p', cls, text)));
    blocked = !!(j && j.ok && j.jack);
    if (button) button.disabled = blocked;
  };
  let looked = null;
  if (!Office.agent.running) lines.replaceChildren(el('p', 'callout warn', T('letgo.agent_away')));
  else {
    lines.replaceChildren(el('p', 'role', T('letgo.looking')));
    looked = Office.api.post(`${ID}.letgo_look`, {}).then(show, (e) => show({ ok: false, error: { key: 'internal', params: { detail: String((e && e.message) || e) } } }));
  }
  return {
    looked,                      // the look under way (the tests wait for it)
    bind(b) { button = b; if (blocked) b.disabled = true; },
    before() {                   // once, even if «Let go» is pressed again
      if (!sent) sent = Office.api.post(`${ID}.letgo`, { confirm: true });
      return sent;
    },
    done(r) {
      const out = letGoDoneLines(r);
      if (!out.length) return;
      const body = el('div');
      out.forEach(([cls, text]) => body.appendChild(el('p', cls, text)));
      Office.dialog({ title: T('letgo.done_title'), body, buttons: [{ text: Office.t('common.close'), kind: '' }] });
    },
  };
}
/** What her look says, as lines [class, text] (pure) */
function letGoView(j) {
  if (!j || !j.ok) return [['callout warn', Office.errorText((j && j.error) || { key: 'internal' }, ID)]];
  if (j.jack) return [['callout warn', T('letgo.jack')]];
  const out = [['', T(j.schedule || j.after ? 'letgo.sched_off' : 'letgo.sched_none')]];
  const u = j.unraid || {};
  if (!u.off) out.push(['role', T('letgo.unraid_on')]);
  else if (u.back) out.push(['', T('letgo.unraid_back', { schedule: fmt.cron(u.back) })]);
  else out.push(['role', T('letgo.unraid_stays')]);
  return out;
}
/** After she was let go: what she did (pure) */
function letGoDoneLines(r) {
  if (!r || !r.ok) return [['callout warn', T('letgo.done_error', { error: Office.errorText((r && r.error) || { key: 'internal' }, ID) })]];
  const out = [];
  const u = r.unraid || {};
  if (u.back) out.push(['', T('letgo.done_back', { schedule: fmt.cron(u.was) })]);
  else if (u.kept) out.push(['role', T('letgo.done_kept', { schedule: fmt.cron(u.schedule) })]);
  else if (u.error) out.push(['callout warn', T('letgo.done_back_not', { error: Office.errorText(u.error, ID) })]);
  return out;
}

// ------------------------------------------------------------------ desk
Office.desk({
  letGo: letGoPart,
  id: ID,
  async mount(root, sub) {
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : 'main';
    bars();
    if (page === 'setup') {
      // #/moverelli/setup/<tile> (the search, a step's place) picks that step exactly like a click, the address keeps its
      // page; else the step chosen last (moverelli.setup.tile), none = every step
      if (setupSwitcher().mount(String(sub || '').split('/').slice(1).join('/'))) Office.subroute('setup');
      if (!state) await load(false);
      await Office.freshState(ID);      // the form starts from her settings: a fresh look, never a stale one
      if (view !== root || page !== 'setup') return;
      form = null;
      renderSetup();
      return;
    }
    // her tiles as places: #/moverelli/<tile> picks it exactly like a click (the address is her page's again); the Team
    // Lead's «Open» for her schedule (#/moverelli/schedule) picks «Schedule» and opens its dialog as before
    switcher().mount(sub);
    render();
    await load(false);
    if (sub === 'schedule') scheduleDialog();
  },
  unmount() { view = null; clearTimeout(outTimer); },
  poll() { if (page === 'main') load(false); },
  agentChanged() { if (view) (page === 'setup' ? renderSetup() : render()); },
  menu() {
    const items = [{ text: T('menu.refresh'), act: () => load(true) }];
    if (state && state.configured) {
      items.push({ text: T('schedule_title'), act: scheduleDialog });
      items.push({ text: T('show_log'), act: showLog });
    }
    return items;
  },
  async reception() {
    if (!state) await load(false);
    const facts = [];
    if (state && state.configured) {
      facts.push(T('fact.shares', { n: (state.shares || []).filter((x) => x.state === 'moves').length }));
      if (state.last && state.last.finished) facts.push(T('fact.last', { when: fmt.relative(state.last.finished) }));
      if (!othersOff()) facts.push(T('fact.dry'));
    } else {
      facts.push(T('fact.setup'));
    }
    return { bubble: T('bubble.hello_short'), facts };
  },
});

// what her state holds for the search: the shares (#/moverelli/shares), the runs her list shows (#/moverelli/runs)
Office.placesFrom(ID, (s, part) => {
  if (part) return [];
  const out = [];
  (s.shares || []).forEach((x) => out.push({ text: x.share, sub: T('shares'), route: `#/${ID}/shares`, anchor: `share:${x.share}` }));
  (s.history || []).slice(0, HISTORY_SHOWN).forEach((r) => out.push({ text: `${T('mode.' + r.mode)} · ${fmt.date(r.started)}`,
    sub: `${T('history')} · ${T('result.' + (r.result || 'failed'))}`, route: `#/${ID}/runs`, anchor: `run:${r.started}` }));
  return out;
});

// her places for the search (places.json lists the same keys): each section by the route of its tile (a section stands
// under the row only while its tile is chosen — or none is), her tiles (place tile.<id>), then the setup's steps and tiles
const SETUP = { route: '#/moverelli/setup', crumb: 'setup_open' };
Office.places(ID, [
  { kind: 'section', key: 'schedule', route: `#/${ID}/schedule`, shown: configured },
  { kind: 'section', key: 'shares', route: `#/${ID}/shares` },
  { kind: 'section', key: 'history', route: `#/${ID}/runs` },
  ...TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `tile.${id}`, route: `#/${ID}/${id}`, ...(id === 'schedule' ? { shown: configured } : {}) })),
  { kind: 'section', key: 'tools' },
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'step', key, ...SETUP, route: `#/${ID}/setup/${id}` })),
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `setup.tile.${id}`, ...SETUP, route: `#/${ID}/setup/${id}` })),
  ...[['list', 'help.list'], ['mode.dry', 'help.dry'], ['mode.run', 'help.run']].map(([key, text]) => ({ kind: 'help', key, text })),
  ...['what', 'rules', 'unraid', 'emby', 'schedule'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['setup.global', 'setup.help_global'], ['setup.files', 'setup.help_files'], ['setup.shares', 'setup.help_shares'], ['setup.save', 'setup.help_save'],
    ['import.title', 'import.help']]
    .map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.moverelli = { setState: (s) => { state = s; }, runSummary, moverNotice, foreignNotices, shareSection, linesOf, filesOf,
    importPreview, importDone, importFileText, scheduleDialog, letGoView, letGoDoneLines, moverConsent, bubbleText, afterNotice, schedText, scheduleSection, historySection, footer, hints,
    tileList, switcher, scheduleTile, sharesTile, lastRunTile, setupTileData, setupTileList, SETUP_TILES, TILES, renderSetup,
    setForm: (f) => { form = f; }, initForm, jackUncoveredIn, jackNotice, form: () => form, setView: (v, p) => { view = v; page = p || 'main'; }, bars, render };
}
})();
