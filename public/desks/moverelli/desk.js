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
function stat(box, label, value, sub, alert, onclick) {
  const s = el(onclick ? 'button' : 'div', 'stat' + (alert ? ' alert' : ''));
  if (onclick) { s.type = 'button'; s.onclick = onclick; }
  s.append(el('div', 'stat-label', label), el('div', 'stat-value', value));
  if (sub) s.appendChild(el('div', 'stat-sub', sub));
  box.appendChild(s);
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
  box.scrollIntoView({ block: 'nearest' });
}

const running = () => !!(state && state.job && state.job.running);
const realRun = () => running() && state.job.mode === 'run';
const unraidOk = () => !!(state && state.unraid && state.unraid.ok);
const mover = () => (state && state.mover) || { off: true, schedule: null, running: false };
const jack = () => (state && state.jack) || { busy: false, list: null, uncovered: [] };
/** Other movers on a schedule of their own: Mover Tuning's (refuses real runs), scripts that call a mover (named) */
const foreign = () => (state && state.foreign) || { tuning: { installed: false, active: false, lines: [] }, scripts: [] };
/** May real runs go: Unraid's schedule off, Mover Tuning without a schedule of its own */
const othersOff = () => mover().off && !foreign().tuning.active;
/** «cache», «array» in words: the array is named in the page's language */
const where = (x) => (x === 'array' ? T('array') : x || '–');
function schedText(sc) {
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
  if (!state.schedule.enabled) return T('bubble.no_schedule');
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

// ------------------------------------------------------------------ main view
function render() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  const actions = [];
  if (state && state.configured) {
    actions.push(button(T('list'), 'plain', () => startRun('list')));
    actions.push(button(T('start'), '', chooseRun));
    actions.push(button(T('setup_open'), 'plain', () => Office.go(`#/${ID}/setup`)));
  } else if (state) {
    actions.push(button(T('setup_open'), '', () => Office.go(`#/${ID}/setup`)));
  }
  actions.forEach((b) => { b.disabled = !Office.agent.running || running() || !unraidOk(); });
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions });
  root.appendChild(head);
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

  if (!unraidOk()) root.appendChild(el('p', 'callout warn', T('notice.unraid_old', { version: state.unraid.version || '?', needed: state.unraid.needed })));
  if (state.configured) {
    root.appendChild(moverNotice());
    foreignNotices().forEach((n) => root.appendChild(n));
  }
  const j = jack();
  if (j.busy && !running()) root.appendChild(el('p', 'callout', T('notice.jack_busy')));
  if ((j.uncovered || []).length) {
    const p = el('p', 'callout warn', T('notice.emby_list', { shares: j.uncovered.join(', '), file: j.list || '' }) + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    root.appendChild(p);
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
    root.appendChild(p);
  }
  if (!state.configured) {
    const p = el('p', 'callout', T('notice.setup') + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    root.appendChild(p);
    root.appendChild(shareSection());
    root.appendChild(historySection());
    root.appendChild(toolSection());
    return;
  }
  root.appendChild(overview());
  root.appendChild(shareSection());
  root.appendChild(historySection());
  root.appendChild(toolSection());
}

/** Unraid's schedule and hers, the rules in short, the last run */
function overview() {
  const s = section(T('overview'), T('overview_sub'), { place: 'overview' });
  const stats = el('div', 'stats');
  const m = mover();
  stat(stats, T('unraid_schedule'), m.off ? T('unraid_off') : T('unraid_on'), m.off ? T('unraid_off_sub') : (m.schedule ? fmt.cron(m.schedule) : ''), !m.off);
  const sc = state.schedule;
  stat(stats, T('schedule'), schedText(sc), sc.enabled ? T('by_office') : T('schedule_set'), !sc.enabled, () => scheduleDialog());
  const g = state.settings.global;
  stat(stats, T('rules'), T('rule_age', { n: g.min_age, stat: g.age_stat }),
    g.move_when_used_above ? T('rule_threshold', { pct: g.move_when_used_above }) : T('rule_always'));
  const last = state.last;
  stat(stats, T('last_run'), last && last.finished ? fmt.relative(last.finished) : T('never'),
    last ? `${T('mode.' + (last.mode || 'dry'))} · ${T('result.' + (last.result || 'failed'))}` : '',
    !!(last && (last.errors || !['ok', 'stopped'].includes(last.result))));
  s.appendChild(stats);
  if (last && last.result) {
    const box = el('div', 'box mo-results');
    const h = (state.history || []).find((r) => !['refused', 'skipped'].includes(r.result));
    box.appendChild(el('div', '', runSummary({ mode: last.mode, status: last, result: last.result, why: h && h.result === last.result ? h.why : null })));
    (last.shares || []).filter((x) => x.files || x.moved || x.left).forEach((x) => {
      box.appendChild(el('div', 'mo-share-line', T(last.mode === 'run' ? 'share_moved' : 'share_would', { share: x.share,
        moved: x.moved || 0, files: nOf('files', x.files), size: fmt.size(x.bytes || 0), left: nOf('left', x.left) })));
    });
    s.appendChild(box);
  }
  const bar = el('div', 'toolbar');
  bar.appendChild(button(T('show_log'), 'small plain', showLog));
  if (state.job.mode) bar.appendChild(link(T('show_output', { mode: T('mode.' + state.job.mode) }), () => showOutput(false)));
  s.appendChild(bar);
  return s;
}

const STATE_CLASS = { moves: 'ok', skip: 'quiet', prefer_off: 'quiet', none: 'quiet' };
/** Every share as Smart Mover sees it: what moves where, under which rules */
function shareSection() {
  const shares = state.shares || [];
  const moving = shares.filter((x) => x.state === 'moves').length;
  const s = section(T('shares'), T('shares_sub'), chip(T('shares_moving', { n: moving }), moving ? 'ok' : 'quiet'), { place: 'shares' });
  const box = el('div', 'box');
  shares.forEach((x) => {
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
  s.appendChild(box);
  return s;
}

function historySection() {
  const runs = state.history || [];
  const s = section(T('history'), T('history_sub'), { place: 'history' });
  const box = el('div', 'box');
  if (!runs.length) box.appendChild(el('p', 'empty', T('history_none')));
  runs.slice(0, HISTORY_SHOWN).forEach((r) => {
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
  s.appendChild(box);
  return s;
}

/** A quiet line under the tool's tile: the Smart Mover is helmi1987's */
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
function toolSection() {
  const s = section(T('tools'), T('tools_sub'), { place: 'tools' });
  const stats = el('div', 'stats');
  stat(stats, 'Smart Mover', state.engine || '?', T('tool_sub'));
  stat(stats, 'Unraid', (state.unraid && state.unraid.version) || '?', T('unraid_sub', { needed: (state.unraid && state.unraid.needed) || '' }), !unraidOk());
  s.appendChild(stats);
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
  let mode = !sc.enabled ? 'daily' : m2 ? 'daily' : 'custom';
  const time = el('input', 'input');
  time.type = 'time';
  time.value = m2 ? `${pad(m2[2])}:${pad(m2[1])}` : '05:15';
  const cron = el('input', 'input mono');
  cron.value = cur || '15 5 * * *';
  cron.spellcheck = false;
  const box = el('div', 'mo-schedule');
  box.appendChild(el('p', '', T('schedule.intro')));
  if (!othersOff()) box.appendChild(el('p', 'callout warn', T('schedule.unraid_on')));
  const option = (id, text, hint, extra) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'mo-schedule';
    input.checked = mode === id;
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
        const j = await Office.api.post(`${ID}.schedule`, { cron: expr });
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
  const back = button(T('back'), 'plain', () => Office.go(`#/${ID}`));
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: T('setup.bubble'), actions: [back] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('setup.global'), T('setup.help_global')],
    [T('setup.files'), T('setup.help_files')],
    [T('setup.shares'), T('setup.help_shares')],
    [T('setup.save'), T('setup.help_save')],
  ]));
  if (!state) return;
  if (!form) initForm();
  const g = form.global;

  // 1. the rules for all shares
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
  root.appendChild(s1);

  // 2. per share
  const s2 = section(T('setup.shares'), T('setup.shares_sub'), { place: 'setup.shares' });
  const shares = state.shares || [];
  shares.forEach((x) => {
    const sv = form.shares[x.share];
    if (!sv) return;
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
    s2.appendChild(det);
  });
  if (!shares.length) s2.appendChild(el('p', 'empty', T('shares_none')));
  root.appendChild(s2);

  const bar = el('div', 'toolbar');
  const save = button(T('setup.save'), '', saveSetup);
  save.disabled = !Office.agent.running || running();
  bar.appendChild(save);
  bar.appendChild(button(T('back'), 'plain', () => Office.go(`#/${ID}`)));
  root.appendChild(bar);
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
  const j = await act('save', { settings });
  if (!j) return;
  Office.toast(T('setup.saved'));
  form = null;
  Office.go(`#/${ID}`);
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root, sub) {
    view = root;
    page = sub === 'setup' ? 'setup' : 'main';
    if (page === 'setup') {
      if (!state) await load(false);
      await Office.freshState(ID);      // the form starts from her settings: a fresh look, never a stale one
      if (view !== root || page !== 'setup') return;
      form = null;
      renderSetup();
      return;
    }
    render();
    await load(false);
    if (sub === 'schedule') { Office.subroute(''); scheduleDialog(); }     // the Team Lead's «Open» for her schedule
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

// what her state holds for the search: the shares, the runs her list shows
Office.placesFrom(ID, (s, part) => {
  if (part) return [];
  const out = [];
  (s.shares || []).forEach((x) => out.push({ text: x.share, sub: T('shares'), anchor: `share:${x.share}` }));
  (s.history || []).slice(0, HISTORY_SHOWN).forEach((r) => out.push({ text: `${T('mode.' + r.mode)} · ${fmt.date(r.started)}`,
    sub: `${T('history')} · ${T('result.' + (r.result || 'failed'))}`, anchor: `run:${r.started}` }));
  return out;
});

// her places for the search (places.json lists the same keys)
const SETUP = { route: '#/moverelli/setup', crumb: 'setup_open' };
const configured = () => !state || !!state.configured;
Office.places(ID, [
  { kind: 'section', key: 'overview', shown: configured },
  { kind: 'section', key: 'shares' },
  { kind: 'section', key: 'history' },
  { kind: 'section', key: 'tools' },
  ...['setup.global', 'setup.shares'].map((key) => ({ kind: 'step', key, ...SETUP })),
  ...[['list', 'help.list'], ['mode.dry', 'help.dry'], ['mode.run', 'help.run']].map(([key, text]) => ({ kind: 'help', key, text })),
  ...['what', 'rules', 'unraid', 'emby', 'schedule'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['setup.global', 'setup.help_global'], ['setup.files', 'setup.help_files'], ['setup.shares', 'setup.help_shares'], ['setup.save', 'setup.help_save']]
    .map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.moverelli = { setState: (s) => { state = s; }, runSummary, moverNotice, foreignNotices, shareSection, linesOf, filesOf };
}
})();
