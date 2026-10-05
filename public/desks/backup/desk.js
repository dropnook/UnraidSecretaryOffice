/* Mr. Backupsy — runs the unraid-backup script: what it is doing right now and
   when it will be done, how the last nights went, which shares are protected
   how, what changed since the setup, and how to get things back.
   The agent part lives in agent/desks/backup.php, the engine in backup/. */
(() => {
'use strict';

const ID = 'backup';
const T = Office.scope(ID);
const { el, fmt } = Office;
const LIVE_POLL = 5000;
const SETUP_STALE = 600;          // seconds: an older plan is read again when the setup page opens

// phases of a run (status.json "phase") grouped into the steps the desk shows
const STEPS = [
  ['prepare', ['start', 'inventory']],
  ['dumps', ['maintenance', 'manifest', 'stopping_apps', 'dumps']],
  ['snapshots', ['stopping', 'vms', 'snapshots', 'starting']],
  ['kopia', ['mounting', 'kopia']],
  ['finish', ['unmounting', 'cleanup', 'aborting', 'done']],
];

let state = null;
let view = null;
let page = 'main';             // 'main' or 'setup' (#/backup/setup)
let liveTimer = null;

// ------------------------------------------------------------------ loading
async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  if (view && page === 'main') render();
  schedule();
  return j;
}

/** While a run is going on, look every few seconds */
function schedule() {
  clearTimeout(liveTimer);
  if (view && page === 'main' && state && (state.running || (state.setup && state.setup.running))) {
    liveTimer = setTimeout(() => {
      if (!document.hidden && !Office.dialogOpen() && !Office.menuOpen()) load(true);
      else schedule();
    }, LIVE_POLL);
  }
}

// ------------------------------------------------------------------ helpers
/** Durations, with seconds when it was quick */
const dur = (seconds) => (seconds < 60 ? `${Math.max(0, Math.round(seconds))} s` : fmt.duration(seconds));

function chip(text, cls, title) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (title) c.title = title;
  return c;
}

const RESULT_CHIP = { ok: 'ok', warnings: 'warn', errors: 'danger', failed: 'danger', aborted: 'warn', running: 'accent' };
function resultChip(result) {
  return chip(T('result.' + result), RESULT_CHIP[result] || '');
}

/** A section with its heading, the explanation under it, and extras on the right */
function section(title, sub, ...extra) {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(title, sub, ...extra));
  return s;
}

function button(text, kind, act) {
  const b = el('button', 'btn' + (kind ? ' ' + kind : ''), text);
  b.type = 'button';
  b.onclick = act;
  return b;
}

function stat(label, value, sub, alert) {
  const s = el('div', 'stat' + (alert ? ' alert' : ''));
  s.append(el('div', 'stat-label', label), el('div', 'stat-value', value));
  if (sub) s.appendChild(el('div', 'stat-sub', sub));
  return s;
}

/** Why a run ended: known codes translated, the script's own words otherwise */
function messageText(m) {
  const code = String(m).replace(/^exit \d+$/, 'exit');
  return Office.has(`${ID}.message.${code}`) ? T('message.' + code, { detail: m }) : m;
}

/** A Kopia source as the run names it: a share, "flash", or app:<name> / vm:<name> (a source of its own, engine 2.19) */
function srcLabel(name) {
  const m = /^(app|vm):(.*)$/.exec(name || '');
  if (m) return T('src.' + m[1], { name: m[2] });
  return name === 'flash' ? T('flash') : name;
}
/** The own Kopia source of an app or VM (state.items), if it has one */
const itemOf = (kind, name) => (state && state.items || []).find((i) => i.kind === kind && i.name === name) || null;
/** A chip for an app's or VM's own Kopia source: when Kopia last had it */
function itemChip(it) {
  const l = it.last;
  const text = !l ? T('item.chip_never') : l.ok ? T('item.chip', { when: fmt.relative(l.time) }) : T('item.chip_failed');
  return chip(text, l && !l.ok ? 'danger' : 'ok', T('item.chip_hint', { path: it.path || '.' + it.kind + 's/' + it.name }));
}

const lastRun = () => (state && state.history && state.history[0]) || null;
/** The packages in the backup place (engine 2.18+), null before the first run that wrote some */
const packages = () => {
  const pk = state && state.packages;
  return pk && pk.found && (pk.apps.length || pk.vms.length || pk.server) ? pk : null;
};
const status = () => (state && state.status) || null;
const live = () => !!(state && state.running);
const canAct = () => !!(state && state.found && state.compatible && Office.agent.running);

/** Where the run is and when it will be done, from the durations of earlier runs */
function progress() {
  const s = status();
  if (!s || !live()) return null;
  const est = state.estimates || { sources: {} };
  const now = Date.now() / 1000;
  const planned = (s.kopia && s.kopia.planned) || [];
  const done = new Map(((s.kopia && s.kopia.done) || []).map((d) => [d.name, d]));
  const phase = s.phase;
  const step = Math.max(0, STEPS.findIndex(([, phases]) => phases.includes(phase)));
  let remaining = 0;
  let known = true;
  let overdue = false;

  const sourceLeft = (name) => {
    const e = est.sources[name];
    if (e === undefined || e === null) { known = false; return 0; }
    if (name === s.kopia.current && s.kopia.current_since) {
      const elapsed = now - s.kopia.current_since;
      if (elapsed > e) overdue = true;
      return Math.max(0, e - elapsed);
    }
    return e;
  };

  if (step < 3) {
    if (est.before) remaining += Math.max(0, est.before - (now - s.started));
    else if (!planned.length && est.total) remaining += Math.max(0, est.total - (now - s.started));
    else known = false;
    planned.forEach((n) => { remaining += sourceLeft(n); });
  } else if (step === 3) {
    planned.filter((n) => !done.has(n)).forEach((n) => { remaining += sourceLeft(n); });
  }
  const elapsed = now - s.started;
  return {
    step,
    planned,
    done,
    eta: known ? now + remaining : null,
    percent: known && elapsed + remaining > 0 ? Math.min(99, Math.round(elapsed / (elapsed + remaining) * 100)) : null,
    overdue,
  };
}

function bubbleText() {
  if (!state) return [T('bubble.loading')];
  if (!state.found) return [T('bubble.missing')];
  if (state.asleep) return [T('bubble.asleep')];
  const out = [];
  const s = status();
  if (live()) {
    const p = progress();
    if (s && s.mode !== 'backup') out.push(T('bubble.running_' + s.mode));
    else if (s && s.kopia.current) {
      out.push(T('bubble.running_kopia', { share: srcLabel(s.kopia.current), n: s.kopia.done.length + 1, total: s.kopia.planned.length }));
    } else if (s) out.push(T('bubble.running_phase', { step: T('step.' + STEPS[p ? p.step : 0][0]) }));
    else out.push(T('bubble.running_old', { step: state.step || '…' }));
    if (p && p.eta) out.push(T(p.overdue ? 'bubble.eta_late' : 'bubble.eta', { time: fmt.time(p.eta) }));
    return out;
  }
  const last = lastRun();
  if (!last) out.push(T('bubble.no_runs'));
  else {
    const k = last.kopia || [];
    const ok = k.filter((x) => x.ok).length;
    const when = fmt.relative(last.finished || last.started);
    if (last.result === 'ok') out.push(k.length ? T('bubble.last_ok_kopia', { when, ok, total: k.length }) : T('bubble.last_ok', { when }));
    else out.push(T('bubble.last_' + last.result, { when, errors: last.errors, warnings: last.warnings, n: last.result === 'errors' ? last.errors : last.warnings }));
    const age = Date.now() / 1000 - (last.finished || last.started);
    if (age > 36 * 3600) out.push(T('bubble.old', { days: Math.floor(age / 86400) }));
  }
  if (state.schedule && state.schedule.script && !state.schedule.enabled) out.push(T('bubble.no_schedule'));
  const drift = (state.drift && state.drift.items || []).filter((d) => d.level !== 'info').length;
  if (drift) out.push(T('bubble.drift', { n: drift }));
  return out;
}

// ------------------------------------------------------------------ rendering
function render() {
  const root = view;
  root.innerHTML = '';
  const actions = [];
  if (state && state.found) {
    if (live()) {
      actions.push(button(T('abort'), 'danger plain', abortRun));
    } else {
      actions.push(button(T('setup_open'), 'plain', () => Office.go(`#/${ID}/setup`)));
      actions.push(button(T('check'), 'plain', () => startRun('check')));
      actions.push(button(T('start'), '', chooseRun));
    }
    actions.forEach((b) => { b.disabled = !canAct() || !!(state.setup && state.setup.running); });
  }
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText().join(' ')), actions });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID, [
    [T('help.run'), T('help.run_text')],
    [T('help.results'), T('help.results_text')],
    [T('help.protection'), T('help.protection_text')],
    ...['offsite', 'local', 'none'].map((l) => [Office.backupChip(l), Office.t('protect.' + l + '_text')]),
    [T('help.rules'), T('help.rules_text')],
    [T('help.vms'), T('help.vms_text')],
    [T('help.packages'), T('help.packages_text')],
    [T('help.items'), T('help.items_text')],
    [T('help.buttons'), T('help.buttons_text')],
    [T('setup_open'), T('help.setup')],
    [T('history'), T('help.history')],
    [T('drift'), T('help.drift')],
    [T('restore'), T('help.restore')],
  ]));

  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  if (!state.found) { root.appendChild(missing()); return; }

  notices().forEach((n) => root.appendChild(n));
  root.appendChild(live() ? runningCard() : summary());
  root.appendChild(protection());
  root.appendChild(historySection());
  if ((state.drift && state.drift.items || []).length) root.appendChild(driftSection());
  root.appendChild(restoreSection());
}

function missing() {
  const box = el('div', 'box');
  const p = el('div', 'empty');
  p.appendChild(el('strong', '', T('missing.title')));
  p.append(T('missing.text', { dir: state.dir }));
  box.appendChild(p);
  return box;
}

function notices() {
  const out = [];
  const callout = (text, warn, more) => {
    const c = el('p', 'callout' + (warn ? ' warn' : ''), text);
    if (more) c.append(' ', more);
    out.push(c);
  };
  if (state.asleep) callout(T('notice.asleep'), false);
  if (!state.compatible) callout(T('notice.too_old', { version: state.version || '?' }), true);
  if (state.setup && state.setup.running) {
    callout(T('notice.setup_running'), false, button(T('setup_open'), 'small plain', () => Office.go(`#/${ID}/setup`)));
  }
  if (!state.settings_found) callout(T('notice.no_settings'), true, button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
  const sc = state.schedule || {};
  if (!sc.script) {
    if (sc.via !== 'office') callout(T('notice.no_user_script'), true);     // the plugin: "no settings" says it all
  } else if (!sc.enabled) callout(T('notice.schedule_off'), true, button(T('schedule.open'), 'small', scheduleDialog));
  const errors = (state.drift && state.drift.items || []).filter((d) => d.level === 'error').length;
  if (errors) callout(T('notice.drift_errors', { n: errors }), true);
  const last = lastRun();
  if (!live() && last && last.result === 'failed' && last.message === 'interrupted') callout(T('notice.interrupted'), true);
  if (!live() && (state.mounted || []).length && !(state.settings && state.settings.keep_mounts)) {
    const b = button(T('unmount'), 'small plain', unmount);
    b.disabled = !canAct();
    callout(T('notice.mounted', { n: state.mounted.length }), false, b);
  }
  return out;
}

/** Under the steps: the stretch in which apps and VMs are held - before, now, done */
function holdBracket(s, p) {
  const from = STEPS.findIndex(([n]) => n === 'dumps');
  const to = STEPS.findIndex(([n]) => n === 'snapshots');
  const held = ((state.paused || {}).stopped || []).length;
  let cls = '';
  let text = T('hold.before');
  if (s.downtime_s) { cls = 'done'; text = T('hold.done', { duration: fmt.duration(s.downtime_s) }); }
  else if (p.step >= from) { cls = 'now'; text = held ? T('hold.now', { n: held }) : T('hold.now_none'); }
  const row = el('div', 'bk-hold');
  const span = el('div', 'bk-hold-span ' + cls, text);
  span.style.gridColumn = `${from + 1} / ${to + 2}`;
  row.appendChild(span);
  return row;
}

/** A run is going on: steps, Kopia sources, ready at about … */
function runningCard() {
  const s = status();
  const p = progress();
  const box = section(T('now'), T('now_sub'));
  const card = el('div', 'card bk-run');

  const top = el('div', 'card-head');
  top.append(el('span', 'card-name', s ? T('mode.' + s.mode) : T('mode.backup')), chip(T('result.running'), 'accent'));
  const since = s ? s.started : state.since;
  if (since) top.appendChild(el('span', 'status quiet', T('since', { time: fmt.time(since), duration: fmt.duration(Date.now() / 1000 - since) })));
  card.appendChild(top);

  if (!s) {
    card.appendChild(el('div', 'card-line', T('old_step', { step: state.step || '…' })));
    box.appendChild(card);
    return box;
  }

  const steps = el('ol', 'bk-steps');
  STEPS.forEach(([name], i) => {
    const li = el('li', i < p.step ? 'done' : i === p.step ? 'current' : '', T('step.' + name));
    if (name === 'kopia' && !p.planned.length) li.classList.add('skipped');
    steps.appendChild(li);
  });
  card.appendChild(steps);
  card.appendChild(holdBracket(s, p));

  if (p.percent !== null) {
    // the bar says itself how far and how long still
    const bar = el('div', 'bar bk-bigbar');
    const fill = el('i', 'snaps');
    fill.style.width = p.percent + '%';
    const left = p.eta && !p.overdue ? ' · ' + T('eta_left', { left: fmt.duration(p.eta - Date.now() / 1000) }) : '';
    bar.append(fill, el('span', 'bk-bar-label', `${Math.round(p.percent)} %${left}`));
    card.appendChild(bar);
  }
  const line = el('div', 'card-line');
  if (p.eta) line.append(T(p.overdue ? 'eta_late' : 'eta_at', { time: fmt.time(p.eta) }));
  else line.append(T('eta_unknown'));
  if (s.downtime_s) line.append(' · ', T('downtime_was', { duration: fmt.duration(s.downtime_s) }));
  if (s.packages && s.packages.written) line.append(' · ', T('pk.run_packed', { apps: s.packages.apps, vms: s.packages.vms }));
  card.appendChild(line);

  // what is paused right now: stopped containers, Nextcloud in maintenance mode
  const pz = state.paused || {};
  if ((pz.stopped || []).length || (pz.maintenance || []).length) {
    const paused = el('div', 'bk-paused');
    if ((pz.stopped || []).length) {
      const row = el('div', 'bk-paused-row');
      row.append(el('span', 'bk-paused-label', T('paused.stopped', { n: pz.stopped.length })));
      pz.stopped.forEach((n) => row.appendChild(chip(n, 'warn')));
      paused.appendChild(row);
    }
    if ((pz.maintenance || []).length) {
      const row = el('div', 'bk-paused-row');
      row.append(el('span', 'bk-paused-label', T('paused.maintenance')));      // no time: the engine rewrites that file now and then
      pz.maintenance.forEach((n) => row.appendChild(chip(n, 'warn')));
      paused.appendChild(row);
    }
    paused.appendChild(el('div', 'bk-paused-note', T('paused.note')));
    card.appendChild(paused);
  }

  if (p.planned.length) {
    const list = el('div', 'bk-sources');
    const est = state.estimates.sources || {};
    p.planned.forEach((name) => {
      const d = p.done.get(name);
      const current = s.kopia.current === name;
      const r = el('div', 'bk-source' + (d ? (d.ok ? ' ok' : ' failed') : current ? ' current' : ''));
      const icon = d ? (d.ok ? '✓' : '✕') : current ? '' : '·';
      const mark = el('span', 'bk-mark', icon);
      if (current) mark.appendChild(el('span', 'spin'));
      r.append(mark, el('span', 'bk-source-name', srcLabel(name)));
      let info = '';
      if (d) info = d.ok ? dur(d.seconds) : T('failed');
      else if (current) info = dur(Date.now() / 1000 - s.kopia.current_since) + (est[name] ? ' · ' + T('src_last', { d: dur(est[name]) }) : '');
      else if (est[name]) info = T('src_last', { d: dur(est[name]) });
      const inf = el('span', 'bk-source-info', info);
      if (est[name] && !d) inf.title = T('src_last_hint');
      r.appendChild(inf);
      list.appendChild(r);
    });
    card.classList.add('running');
    card.appendChild(list);
  }

  const foot = el('div', 'card-foot');
  foot.appendChild(el('span', '', T('counts', { errors: s.errors, warnings: s.warnings })));
  if (s.log) foot.appendChild(button(T('show_log'), 'small plain', () => showLog(s.log, true)));
  card.appendChild(foot);
  box.appendChild(card);
  return box;
}

/** No run going on: how the last one went, and what comes next */
function summary() {
  const box = section(T('overview'), T('overview_sub'));
  const stats = el('div', 'stats');
  const last = lastRun();
  const s = status();
  if (last) {
    const k = last.kopia || [];
    const okCount = k.filter((x) => x.ok).length;
    const st = stat(T('stat.last'), T('result.' + last.result), fmt.date(last.started, true), last.result !== 'ok');
    stats.appendChild(st);
    stats.appendChild(stat(T('stat.duration'), last.finished ? fmt.duration(last.finished - last.started) : '–',
      T('stat.downtime', { duration: fmt.duration(last.downtime || 0) })));

  } else {
    stats.appendChild(stat(T('stat.last'), '–', T('bubble.no_runs')));
  }
  const sc = state.schedule || {};
  const when = sc.enabled ? (sc.frequency === 'custom' ? fmt.cron(sc.custom) : T('freq.' + sc.frequency)) : T('stat.not_scheduled');
  const sst = stat(T('stat.schedule'), when, sc.enabled ? T(sc.via === 'office' ? 'stat.by_office' : 'stat.user_scripts') : T('stat.schedule_hint'), !sc.enabled);
  if (sc.script) {
    sst.classList.add('bk-clickable');
    sst.tabIndex = 0;
    sst.setAttribute('role', 'button');
    sst.onclick = scheduleDialog;
    sst.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); scheduleDialog(); } };
  }
  stats.appendChild(sst);
  if (s && s.mode !== 'backup' && s.finished) {
    stats.appendChild(stat(T('stat.last_' + s.mode), T('result.' + s.result), fmt.relative(s.finished), s.result !== 'ok'));
  }
  overviewTiles().forEach((t) => stats.appendChild(t));
  box.appendChild(stats);
  return box;
}

/** What is backed up besides the shares: the containers, the VMs, the flash */
function overviewTiles() {
  const set = state.settings || {};
  const tiles = [];
  const clickable = (t, act) => {
    t.classList.add('bk-clickable');
    t.tabIndex = 0;
    t.setAttribute('role', 'button');
    t.onclick = act;
    t.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); } };
    return t;
  };
  const c = state.containers;
  // Kopia: the last run's sources, the container, the repository and whether its policies match
  if (set.kopia_enabled) {
    // the newest run that got as far as Kopia (a run that stopped before it says nothing about Kopia)
    const withKopia = (state.history || []).find((r) => (r.kopia || []).length);
    const k = (withKopia && withKopia.kopia) || [];
    const okCount = k.filter((x) => x.ok).length;
    const kc = c && c.kopia;
    const st = status();
    const repoNo = st && st.kopia && st.kopia.state === 'no';
    const pol = state.drift && Array.isArray(state.drift.policies) ? state.drift.policies : null;
    const bad = pol ? pol.filter((p) => !p.ok).length : 0;
    const sub = [];
    if (kc) sub.push(!kc.exists ? T('stat.kopia_missing_ct', { name: kc.name }) : kc.running ? T('stat.kopia_running', { name: kc.name }) : T('stat.kopia_stopped', { name: kc.name }));
    if (repoNo) sub.push(T('stat.kopia_repo_problem'));
    if (pol) sub.push(bad ? T('stat.kopia_policies_bad', { n: bad }) : T('stat.kopia_policies_ok'));
    const value = k.length ? `${okCount} / ${k.length}` : '–';
    const trouble = (kc && (!kc.exists || !kc.running)) || repoNo || bad > 0 || (k.length && okCount !== k.length);
    if (k.length && okCount !== k.length) sub.unshift(T('stat.kopia_missing', { n: k.length - okCount }));
    else if (withKopia) sub.unshift(T('stat.kopia_when', { when: fmt.relative(withKopia.started) }));
    tiles.push(stat(T('stat.kopia'), value, sub.join(' · '), !!trouble));
  }
  if (c && c.total) {
    tiles.push(stat(T('stat.containers'), String(c.total), [T('stat.ct_stopped', { n: c.stopped }), T('stat.ct_kept', { n: c.kept })].join(' · ')));
  }
  // databases: dumped before every snapshot, and the newest dumps
  const dbs = set.dumps || [];
  const pk = packages();
  if (dbs.length || (set.nextcloud || []).length) {
    const latest = (state.dumps || []).find((d) => d.files.length);
    const pkDumps = pk ? pk.apps.filter((a) => !a.stale).flatMap((a) => a.files.filter((f) => f.what === 'dump')) : [];
    const sub = [];
    if (pkDumps.length) sub.push(T('stat.db_last', { when: fmt.relative(Math.max(...pkDumps.map((f) => f.time))), size: fmt.size(pkDumps.reduce((a, f) => a + f.bytes, 0)) }));
    else if (latest) sub.push(T('stat.db_last', { when: fmt.relative(latest.time), size: fmt.size(latest.files.reduce((a, f) => a + f.bytes, 0)) }));
    else if (dbs.length) sub.push(T('never'));
    if ((set.nextcloud || []).length) sub.push(T('stat.db_nextcloud'));
    tiles.push(stat(T('stat.dbs'), String(dbs.length), sub.join(' · ')));
  }
  // the packages: per app and VM its small files, written by every run
  if (pk) {
    const cur = [...pk.apps, ...pk.vms].filter((x) => !x.stale);
    const bad = cur.filter((x) => x.result === 'errors').length;
    const stale = pk.apps.length + pk.vms.length - cur.length;
    const sub = [pk.time ? T('stat.pk_when', { when: fmt.relative(pk.time) }) : T('never')];
    if (bad) sub.push(T('stat.pk_errors', { n: bad }));
    if (stale) sub.push(T('stat.pk_stale', { n: stale }));
    if (pk.old_runs) sub.push(T('stat.pk_old', { n: pk.old_runs }));
    if (pk.asleep) sub.push(T('stat.pk_asleep'));
    const t = stat(T('stat.packages'), T('stat.pk_value', { apps: pk.apps.length - pk.apps.filter((a) => a.stale).length, vms: pk.vms.length - pk.vms.filter((v) => v.stale).length }), sub.join(' · '), bad > 0);
    tiles.push(clickable(t, () => {
      const g = view && view.querySelector('.bk-protect tr.bk-group.bk-apps');
      if (g) g.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }));
  } else if (state.packages && state.packages.old_runs) {
    tiles.push(stat(T('stat.packages'), '–', T('stat.pk_first')));
  }
  const vms = state.vms || [];
  if (vms.length) {
    const count = {};
    vms.forEach((v) => {
      const k = !v.configured ? 'unset' : v.mode === 'off' && v.own.length ? 'off' : v.prepare;
      count[k] = (count[k] || 0) + 1;
    });
    const sub = ['freeze', 'pause', 'shutdown', 'none', 'off', 'unset'].filter((k) => count[k]).map((k) => T('stat.vm_' + k, { n: count[k] }));
    const t = stat(T('stat.vms'), String(vms.length), sub.join(' · '), !!count.unset);
    tiles.push(clickable(t, () => {
      const g = view && view.querySelector('.bk-protect tr.bk-group');
      if (g) g.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }));
  }
  const flash = set.flash || 'off';
  const fl = (state.shares || []).find((x) => x.flash);
  const tarRun = (state.dumps || []).find((d) => d.flash);
  let value; let sub = ''; let alert = false;
  if (flash === 'snapshot') {
    value = set.kopia_enabled ? T('stat.flash_offsite') : T('stat.flash_local');
    if (fl && fl.last) { sub = fl.last.ok ? T('stat.flash_last', { when: fmt.relative(fl.last.time) }) : T('failed'); alert = !fl.last.ok; }
    else if (set.kopia_enabled) sub = T('never');
  } else if (flash === 'tar') {
    value = T('stat.flash_tar');
    const t = pk && pk.flash ? pk.flash.time : tarRun ? tarRun.time : null;
    sub = t ? T('stat.flash_last', { when: fmt.relative(t) }) : T('never');
  } else { value = T('stat.flash_off'); alert = true; }
  tiles.push(stat(T('stat.flash'), value, sub, alert));
  return tiles;
}

/** Is everything protected? Every share with its mode and last offsite copy; a row unfolds to its rules */
const protOpen = new Set();      // unfolded rows (share names), kept across re-renders
function protection() {
  const shares = state.shares || [];
  const counts = { kopia: 0, snapshot: 0, off: 0 };
  shares.forEach((s) => { counts[s.mode] = (counts[s.mode] || 0) + 1; });
  const kopiaOn = state.settings && state.settings.kopia_enabled;
  const local = counts.snapshot + (kopiaOn ? 0 : counts.kopia);
  const order = { kopia: 0, snapshot: 1, off: 2 };
  const rows = [...shares].sort((a, b) => (order[a.mode] - order[b.mode]) || a.name.localeCompare(b.name));
  const key = (s) => (s.flash ? '\u0000flash' : s.name);
  const foldable = rows.filter((s) => s.mode !== 'off');
  const shown = [];                                        // {open(), set(open)} per unfoldable row
  const all = button('', 'small plain', () => {
    const open = shown.some((x) => !x.open());
    Office.keepInPlace(all, () => shown.forEach((x) => x.set(open)));
    label();
  });
  const label = () => { all.textContent = shown.some((x) => !x.open()) ? T('unfold_all') : T('fold_all'); };
  const box = section(T('protection'), T('protection_sum', { kopia: kopiaOn ? counts.kopia : 0, snapshot: local, off: counts.off }),
    ...(foldable.length ? [all] : []));
  if (!shares.length) { box.appendChild(el('p', 'empty', T('no_shares'))); return box; }

  const wrap = el('div', 'box table-wrap');
  const table = el('table', 'grid bk-protect');
  const thead = el('thead');
  const hr = el('tr');
  ['share', 'mode', 'last_copy', 'took', 'details'].forEach((k) => hr.appendChild(el('th', '', T('col.' + k))));
  thead.appendChild(hr);
  table.appendChild(thead);
  const body = el('tbody');
  rows.forEach((s) => {
    const tr = el('tr');
    const name = el('th', '', s.flash ? T('flash') : s.name);
    tr.appendChild(name);
    const modeCell = el('td');
    // the same labels as everywhere in the office: offsite / only local / not backed up
    const level = s.mode === 'kopia' ? (kopiaOn ? 'offsite' : 'local') : s.mode === 'snapshot' ? 'local' : 'none';
    modeCell.appendChild(Office.backupChip(level));
    if (s.mode === 'kopia' && !kopiaOn) modeCell.append(' ', chip(T('mode_kopia_off'), 'warn'));
    if (s.method === 'live') modeCell.append(' ', chip(T('live'), 'warn', T('live_hint')));
    const pol = s.mode === 'kopia' && kopiaOn ? policyOf(s) : null;
    if (pol && pol.skipped) modeCell.append(' ', chip(T('policy.skipped_short'), 'danger', T('policy.skipped')));
    else if (pol && !pol.ok) modeCell.append(' ', chip(T('policy.differs_short'), 'warn', T('policy.differs')));
    tr.appendChild(modeCell);
    const lastCell = el('td');
    const took = el('td');
    if (s.mode === 'kopia' && s.last) {
      if (s.last.ok) lastCell.append(fmt.relative(s.last.time));
      else {
        lastCell.appendChild(chip(T('failed'), 'danger'));
        lastCell.append(' ', s.last.good ? T('good_before', { when: fmt.relative(s.last.good) }) : '');
      }
      lastCell.title = fmt.date(s.last.time);
      took.textContent = s.last.seconds ? dur(s.last.seconds) : '';
    } else if (s.mode === 'kopia') {
      lastCell.appendChild(el('span', 'role', T('never')));
    }
    tr.append(lastCell, took);
    const details = [];
    if (s.ignores.length) details.push(T('ignores', { n: s.ignores.length }));
    if (s.excluded.length) details.push(T('excluded', { n: s.excluded.length }));
    if (s.retention) details.push(T('retention_own', { r: s.retention }));
    if (s.locations.length) details.push(s.locations.join(', '));
    tr.appendChild(el('td', '', details.join(' · ')));
    body.appendChild(tr);
    if (s.mode === 'off') return;
    // the whole row unfolds (chips explain themselves on a click, see initTips)
    tr.classList.add('unfolds');
    name.classList.add('link');
    name.title = T('details');
    let dtr = null;
    const set = (open) => {
      if (!open && dtr) { dtr.remove(); dtr = null; protOpen.delete(key(s)); tr.classList.remove('open'); }
      if (open && !dtr) {
        dtr = el('tr', 'bk-detail');
        const td = el('td');
        td.colSpan = 5;
        td.appendChild(protectionDetail(s, kopiaOn));
        dtr.appendChild(td);
        tr.after(dtr);
        protOpen.add(key(s));
        tr.classList.add('open');
      }
    };
    tr.onclick = (e) => {
      if (e.target.closest('button, a, input, select, [data-own], .chip')) return;
      if (String(window.getSelection && window.getSelection()).length) return;
      Office.keepInPlace(tr, () => set(!dtr));
      label();
    };
    shown.push({ open: () => !!dtr, set });
    if (protOpen.has(key(s))) set(true);
  });

  // the VMs: their disks are in their share's snapshot - here how each one is treated for it
  const vms = state.vms || [];
  if (vms.length) {
    const g = el('tr', 'bk-group');
    const gth = el('th', '', T('vm.group'));
    gth.colSpan = 5;
    g.appendChild(gth);
    body.appendChild(g);
    vms.forEach((v) => {
      const tr = el('tr', 'unfolds');
      const name = el('th', 'link', v.name);
      name.title = T('details');
      tr.appendChild(name);
      const modeCell = el('td');
      modeCell.appendChild(Office.backupChip(vmLevel(v, kopiaOn)));
      if (v.configured && v.mode !== 'off') modeCell.append(' ', chip(T('vm.prep.' + v.prepare), v.prepare === 'none' ? 'warn' : '', T('vm.prep_text.' + v.prepare)));
      if (!v.configured) modeCell.append(' ', chip(T('vm.not_set_up'), 'warn', T('vm.not_set_up_hint')));
      const vp = vmPackage(v.name);
      if (vp && (vp.stale || vp.result === 'errors')) modeCell.append(' ', pkChip(vp));
      const vi = kopiaOn ? itemOf('vm', v.name) : null;
      if (vi) modeCell.append(' ', itemChip(vi));
      tr.appendChild(modeCell);
      const last = v.last;
      const lastCell = el('td', '', last && last.snapshot && last.time ? fmt.relative(last.time) : '');
      if (last && last.time) lastCell.title = fmt.date(last.time);
      const took = el('td', '', last && ['frozen', 'paused', 'shutdown'].includes(last.done) ? dur(last.seconds) : '');
      tr.append(lastCell, took);
      tr.appendChild(el('td', '', last ? T('vm.done.' + last.done) : ''));
      body.appendChild(tr);
      let dtr = null;
      const id = 'vm:' + v.name;
      const set = (open) => {
        if (!open && dtr) { dtr.remove(); dtr = null; protOpen.delete(id); tr.classList.remove('open'); }
        if (open && !dtr) {
          dtr = el('tr', 'bk-detail');
          const td = el('td');
          td.colSpan = 5;
          td.appendChild(vmDetail(v));
          dtr.appendChild(td);
          tr.after(dtr);
          protOpen.add(id);
          tr.classList.add('open');
        }
      };
      tr.onclick = (e) => {
        if (e.target.closest('button, a, input, select, [data-own], .chip')) return;
        if (String(window.getSelection && window.getSelection()).length) return;
        Office.keepInPlace(tr, () => set(!dtr));
        label();
      };
      shown.push({ open: () => !!dtr, set });
      if (protOpen.has(id)) set(true);
    });
  }

  // the apps: what their package in the backup place holds (the data itself is in their shares above)
  const pk = packages();
  if (pk && pk.apps.length) {
    const g = el('tr', 'bk-group bk-apps');
    const gth = el('th', '', T('pk.group') + ' ');
    gth.colSpan = 5;
    gth.appendChild(Office.backupChip(placeLevel(kopiaOn)));
    gth.title = T('pk.group_hint', { path: pk.base + '/apps' });
    g.appendChild(gth);
    body.appendChild(g);
    pk.apps.forEach((a) => {
      const tr = el('tr', 'unfolds');
      const name = el('th', 'link', a.name);
      name.title = T('details');
      tr.appendChild(name);
      const st = el('td');
      st.appendChild(pkChip(a));
      if (a.files.some((f) => f.what === 'dump' && f.run && f.run !== a.run)) st.append(' ', chip(T('pk.kept'), 'warn', T('pk.kept_hint')));
      if (a.files.some((f) => f.what === 'sqlite' && f.run && f.run !== a.run)) st.append(' ', chip(T('pk.sq_kept'), 'warn', T('pk.sq_kept_hint')));
      const ai = kopiaOn ? itemOf('app', a.name) : null;
      if (ai) st.append(' ', itemChip(ai));
      tr.appendChild(st);
      const last = el('td', '', a.time ? fmt.relative(a.time) : '');
      if (a.time) last.title = fmt.date(a.time);
      tr.append(last, el('td'));
      const dumps = a.files.filter((f) => f.what === 'dump').length;
      tr.appendChild(el('td', '', [T('pk.type.' + (a.type || 'container')), dumps ? T('pk.dumps', { n: dumps }) : '', fmt.size(a.bytes)].filter(Boolean).join(' · ')));
      body.appendChild(tr);
      let dtr = null;
      const id = 'app:' + a.folder;
      const set = (open) => {
        if (!open && dtr) { dtr.remove(); dtr = null; protOpen.delete(id); tr.classList.remove('open'); }
        if (open && !dtr) {
          dtr = el('tr', 'bk-detail');
          const td = el('td');
          td.colSpan = 5;
          td.appendChild(pkDetail(a));
          dtr.appendChild(td);
          tr.after(dtr);
          protOpen.add(id);
          tr.classList.add('open');
        }
      };
      tr.onclick = (e) => {
        if (e.target.closest('button, a, input, select, [data-own], .chip')) return;
        if (String(window.getSelection && window.getSelection()).length) return;
        Office.keepInPlace(tr, () => set(!dtr));
        label();
      };
      shown.push({ open: () => !!dtr, set });
      if (protOpen.has(id)) set(true);
    });
  }
  label();
  table.appendChild(body);
  wrap.appendChild(table);
  box.appendChild(wrap);

  const set = state.settings || {};
  const facts = [];
  if (set.zfs_retention) facts.push(T('fact.zfs', { r: set.zfs_retention }));
  if (set.btrfs_days) facts.push(T('fact.btrfs', { n: Number(set.btrfs_days) }));
  if (kopiaOn) {
    const k = set.kopia_keep || {};
    facts.push(T('fact.kopia', { d: k.daily ?? '–', w: k.weekly ?? '–', m: k.monthly ?? '–', y: k.annual ?? '–' }));
  } else facts.push(T('fact.kopia_off'));
  box.appendChild(el('p', 'role', facts.join(' · ')));
  return box;
}

/** The backup place's own protection: its share's mode (the packages' history lies in its snapshots) */
function placeLevel(kopiaOn) {
  const set = state.settings || {};
  const sh = (state.shares || []).find((x) => x.name === set.dumps_share);
  if (!sh || sh.mode === 'off') return 'none';
  return sh.mode === 'kopia' && kopiaOn ? 'offsite' : 'local';
}

const vmPackage = (name) => { const pk = packages(); return pk ? pk.vms.find((x) => x.name === name) || null : null; };

/** A package's state: written by the last run (ok / with warnings / with errors), or stale (an earlier run's) */
function pkChip(p) {
  if (p.stale) return chip(T('pk.stale'), 'warn', T('pk.stale_hint', { when: p.time ? fmt.date(p.time) : '?' }));
  if (p.result === 'errors') return chip(T('pk.errors'), 'danger', T('pk.errors_hint'));
  if (p.result === 'warnings') return chip(T('pk.warnings'), 'warn', T('pk.warnings_hint'));
  return chip(T('pk.ok'), 'ok', T('pk.ok_hint'));
}

/** An unfolded package: where it lies, its files (and from which run a kept one is), the images it names */
function pkDetail(p) {
  const box = el('div', 'bk-pdetail');
  const dl = el('dl', 'kv');
  const add = (term, ...content) => {
    const dd = el('dd');
    content.forEach((c) => dd.append(c));
    dl.append(el('dt', '', term), dd);
  };
  add(T('pk.d_where'), copyCode(p.path));
  const files = el('div', 'bk-rules');
  p.files.filter((f) => f.what !== 'error').forEach((f) => {
    files.append(el('code', '', f.path), ' ', el('span', 'role', fmt.size(f.bytes)));
    if (f.run && f.run !== p.run) files.append(' ', chip(T('pk.kept_from', { when: f.time ? fmt.date(f.time) : f.run }), 'warn', T('pk.kept_hint')));
    files.appendChild(el('br'));
  });
  add(T('pk.d_files'), p.files.length ? files : el('span', 'role', T('pk.no_files')));
  if (p.containers && p.containers.length) {
    const imgs = el('div', 'bk-rules');
    p.containers.forEach((c) => imgs.append(el('span', '', c.name + ': '), el('code', '', c.image), el('br')));
    add(T('pk.d_images'), imgs, el('span', 'role', T('pk.d_images_hint')));
  }
  if ((p.sqlite || []).length) add(T('pk.d_sqlite'), T('pk.d_sqlite_text', { n: p.sqlite.filter((q) => q.present).length }));
  if ((p.own_backups || []).length) {
    const ob = el('div', 'bk-rules');
    p.own_backups.forEach((o) => ob.append(el('span', '', T('own.kind.' + o.kind) + ': '), el('code', '', o.path), el('br')));
    add(T('pk.d_own'), ob);
  }
  const it = itemOf('app', p.name);
  if (it && state.settings && state.settings.kopia_enabled) add(T('item.d_source'), copyCode(it.path || '.apps/' + p.name), ' ', el('span', 'role', it.retention ? T('item.keeps_own', { r: it.retention }) : T('item.keeps_all')));
  box.appendChild(dl);
  box.appendChild(el('p', 'role bk-pfoot', T('pk.d_restore')));
  return box;
}

/** A VM's protection: its share's, unless it is left out or no snapshot can hold its disks */
function vmLevel(v, kopiaOn) {
  if ((v.snap && v.snap !== 'yes') || (v.mode === 'off' && v.own.length) || !v.share_mode || v.share_mode === 'off') return 'none';
  return (v.share_mode === 'kopia' || itemOf('vm', v.name)) && kopiaOn ? 'offsite' : 'local';
}

/** An unfolded VM row: how it is treated, what the last run did, where its disks lie, how long snapshots stay */
function vmDetail(v) {
  const set = state.settings || {};
  const box = el('div', 'bk-pdetail');
  const dl = el('dl', 'kv');
  const add = (term, ...content) => {
    const dd = el('dd');
    content.forEach((c) => dd.append(c));
    dl.append(el('dt', '', term), dd);
  };
  if (v.mode === 'off' && v.own.length) add(T('vm.d_snapshot'), T('vm.left_out'));
  else add(T('vm.d_snapshot'), v.configured ? T('vm.prep_text.' + v.prepare) : T('vm.not_set_up_hint'));
  if (v.last) add(T('vm.d_last'), T('vm.done_text.' + v.last.done, { s: dur(v.last.seconds) }), ' ', el('span', 'role', v.last.time ? fmt.relative(v.last.time) : ''));
  if (v.disks.length) {
    const ul = el('div', 'bk-rules');
    v.disks.forEach((d) => ul.append(el('code', '', d.source), ' ', el('span', 'role', d.dataset || d.fs || '?'), el('br')));
    add(T('vm.d_disks'), ul);
  }
  if (v.snap && v.snap !== 'yes') add(T('vm.d_problem'), T('setup.vm_cannot.' + v.snap));
  else if (v.own.length) {
    const sh = (state.shares || []).find((x) => x.name === v.share);
    add(T('vm.d_keeps'), v.retention ? T('vm.keeps_own', { r: v.retention }) : T('vm.keeps_share', { r: (sh && sh.retention) || set.zfs_retention || '?' }));
  }
  else if (v.disks.length) add(T('vm.d_keeps'), T('vm.keeps_shared'));
  const notes = [];
  if (v.tpm) notes.push(T('vm.note_tpm'));
  if (v.hostdev) notes.push(T('vm.note_gpu', { n: v.hostdev }));
  if (notes.length) add(T('vm.d_notes'), notes.join(' '));
  // its package: XML, UEFI variables, TPM state - written while it was held
  const vi = set.kopia_enabled ? itemOf('vm', v.name) : null;
  if (vi) add(T('item.d_source'), copyCode(vi.path || '.vms/' + v.name), ' ', el('span', 'role', vi.retention ? T('item.keeps_own', { r: vi.retention }) : T('item.keeps_all')));
  const vp = vmPackage(v.name);
  if (vp) add(T('pk.d_package'), pkChip(vp), ' ', copyCode(vp.path), ' ', el('span', 'role', vp.time ? fmt.relative(vp.time) : ''));
  else if (packages()) add(T('pk.d_package'), el('span', 'role', T(v.mode === 'off' && v.own.length ? 'pk.vm_left_out' : 'pk.vm_none')));
  box.appendChild(dl);
  const foot = el('p', 'role bk-pfoot', T('vm.where_to_change') + ' ');
  const b = button(T('pd.change'), 'small', () => { setup.focus = 'vm:' + v.name; Office.go(`#/${ID}/setup`); });
  b.disabled = !(state.found && Office.agent.running);
  foot.appendChild(b);
  box.appendChild(foot);
  return box;
}

/** What the last check found for a share's Kopia policy: undefined = not compared */
function policyOf(s) {
  const list = state.drift && state.drift.policies;
  if (!Array.isArray(list)) return undefined;
  return list.find((p) => (s.flash ? p.kind === 'flash' : p.kind === 'share' && p.share === s.name));
}

const KEEP_FIELDS = ['latest', 'hourly', 'daily', 'weekly', 'monthly', 'annual'];

/** An unfolded row: what Kopia leaves out and keeps, what stays local, and whether Kopia is really set like that */
function protectionDetail(s, kopiaOn) {
  const set = state.settings || {};
  const box = el('div', 'bk-pdetail');
  const dl = el('dl', 'kv');
  const add = (term, ...content) => {
    const dd = el('dd');
    content.forEach((c) => dd.append(c));
    dl.append(el('dt', '', term), dd);
  };
  const rules = (list) => {
    const span = el('span', 'bk-rules');
    list.forEach((r) => span.append(el('code', '', r), ' '));
    return span;
  };

  if (s.mode === 'kopia' && kopiaOn) {
    // left out: the share's own rules, then the ones every share inherits
    add(T('pd.skips'), s.ignores.length ? rules(s.ignores) : el('span', 'role', T(s.flash ? 'pd.no_flash_rules' : 'pd.no_own_rules')));
    const inherited = set.kopia_ignore || [];
    if (inherited.length) add(T('pd.inherited'), rules(inherited));
    // apps and VMs whose folders (or packages, in the backup place) go to Kopia as sources of their own
    const ownSrc = (state.items || []).filter((i) => i.folders.some((f) => f.split('/')[0] === s.name) || (!s.flash && s.name === set.dumps_share));
    if (ownSrc.length) add(T('pd.own_sources'), ownSrc.map((i) => srcLabel(i.kind + ':' + i.name)).join(', '), ' ', el('span', 'role', T('pd.own_sources_hint')));
    // kept: the share's own retention where it has one, the shared one otherwise
    const own = (s.kopia_retention || '').trim().split(/\s+/);
    const keep = set.kopia_keep || {};
    const parts = [];
    KEEP_FIELDS.forEach((f, i) => {
      const v = own[i] && own[i] !== 'inherit' ? own[i] : keep[f];
      if (v && v !== 'inherit' && Number(v) > 0) parts.push(T('keep.' + f, { n: Number(v) }));
    });
    add(T('pd.keeps'), parts.join(' · ') || T('pd.keeps_kopia'), ' ', el('span', 'role', s.kopia_retention ? T('pd.keeps_own') : T('pd.keeps_all')));
    if (set.kopia_compression && set.kopia_compression !== 'inherit') add(T('pd.compression'), set.kopia_compression);
  }

  // local snapshots, by the file systems the share lies on
  const fs = s.fs || [];
  const localParts = [];
  if (s.method !== 'live' && fs.includes('zfs')) {
    const [d, w, m] = (s.retention || set.zfs_retention || '').split(/\s+/).map(Number);
    if (!isNaN(d)) {
      const z = [T('local.zfs_d', { n: d })];
      if (w > 0) z.push(T('local.zfs_w', { n: w }));
      if (m > 0) z.push(T('local.zfs_m', { n: m }));
      localParts.push(T('local.zfs', { list: z.join(', ') }));
    }
  }
  if (s.method !== 'live' && fs.includes('btrfs') && set.btrfs_days) localParts.push(T('local.btrfs', { n: Number(set.btrfs_days) }));
  add(T('pd.local'), localParts.length ? localParts.join(' · ') : el('span', 'role', T('local.none')));
  if (s.excluded.length) add(T('pd.excluded'), rules(s.excluded));

  if (s.mode === 'kopia' && kopiaOn) {
    const pol = policyOf(s);
    const root = Array.isArray(state.drift && state.drift.policies) ? state.drift.policies.find((p) => p.kind === 'root') : undefined;
    const st = el('div', 'bk-polstate');
    if (pol === undefined) st.appendChild(el('span', 'role', T('policy.unknown')));
    else {
      st.appendChild(pol.skipped ? chip(T('policy.skipped_short'), 'danger') : pol.ok ? chip(T('policy.ok_short'), 'ok') : chip(T('policy.differs_short'), 'warn'));
      st.append(' ', pol.skipped ? T('policy.skipped') : pol.ok ? T('policy.ok') : T('policy.differs'));
      if (state.drift.time) st.append(' ', el('span', 'role', T('drift_checked', { when: fmt.relative(state.drift.time) })));
      const diffs = [...pol.differences];
      if (diffs.length) {
        const ul = el('ul', 'bk-diffs');
        diffs.forEach((d) => ul.appendChild(el('li', '', T('diff.' + d.what, d))));
        st.appendChild(ul);
      }
      if (root && !root.ok) {
        st.appendChild(el('p', 'role', T('policy.root_differs')));
        const ul = el('ul', 'bk-diffs');
        root.differences.forEach((d) => ul.appendChild(el('li', '', T('diff.' + d.what, d))));
        st.appendChild(ul);
      }
    }
    add(T('pd.state'), st);
  }
  box.appendChild(dl);

  const foot = el('p', 'role bk-pfoot', T('pd.where_to_change') + ' ');
  const canSetup = state.found && Office.agent.running;
  const b = button(T('pd.change'), 'small', () => {
    if (!s.flash) { setup.open.add(s.name); setup.focus = s.name; }
    Office.go(`#/${ID}/setup`);
  });
  b.disabled = !canSetup;
  foot.appendChild(b);
  box.appendChild(foot);
  return box;
}

function historySection() {
  const runs = state.history || [];
  const box = section(T('history'), T('history_sub'));
  if (!runs.length) { box.appendChild(el('p', 'empty', T('bubble.no_runs'))); return box; }
  const list = el('div', 'box');
  const longest = Math.max(...runs.map((r) => (r.finished || r.started) - r.started), 1);
  runs.slice(0, 20).forEach((r) => {
    const row = el('div', 'row nocheck unfolds');
    row.onclick = () => showLog(r.log);           // the whole row opens the run's log
    const main = el('div', 'row-main');
    const name = el('div', 'row-name text link', fmt.date(r.started, true));
    name.title = T('show_log');
    main.appendChild(name);
    const meta = el('div', 'row-meta');
    meta.appendChild(resultChip(r.result));
    const k = r.kopia || [];
    if (k.length) meta.appendChild(el('span', '', T('kopia_count', { ok: k.filter((x) => x.ok).length, total: k.length })));
    if (r.downtime) meta.appendChild(el('span', '', T('downtime_short', { duration: fmt.duration(r.downtime) })));
    if (r.packages && (r.packages.apps || r.packages.vms)) meta.appendChild(el('span', '', T('pk.history', { apps: r.packages.apps, vms: r.packages.vms })));
    if (r.errors || r.warnings) meta.appendChild(el('span', '', T('counts', { errors: r.errors, warnings: r.warnings })));
    if (r.message && r.result !== 'ok') meta.append(el('span', 'note', messageText(r.message)));
    main.appendChild(meta);
    row.appendChild(main);
    const took = (r.finished || r.started) - r.started;
    const bar = el('div', 'bar thin bk-hbar');
    const fill = el('i', r.result === 'ok' || r.result === 'warnings' ? 'snaps' : 'data');
    fill.style.width = Math.max(2, Math.round(took / longest * 100)) + '%';
    bar.appendChild(fill);
    row.appendChild(bar);
    const fig = el('div', 'figures');
    fig.append(el('b', '', took ? dur(took) : '–'), el('span', '', ''));
    row.appendChild(fig);
    list.appendChild(row);
  });
  box.appendChild(list);
  return box;
}

/** A difference in the office's words where the engine gave a code (2.18+), its own words otherwise */
function driftText(d) {
  if (d.code && Office.has(`${ID}.drift_code.${d.code}`)) return T('drift_code.' + d.code, { share: d.value || '' });
  if (d.code && Office.has(`${ID}.message.${d.code}`)) return T('message.' + d.code, { detail: d.value || '' });
  return d.text;
}

function driftSection() {
  const items = state.drift.items;
  const box = section(T('drift'), T('drift_sub'), el('span', 'hint', state.drift.time ? T('drift_checked', { when: fmt.relative(state.drift.time) }) : ''));
  const list = el('div', 'box');
  const order = { error: 0, warn: 1, info: 2 };
  [...items].sort((a, b) => order[a.level] - order[b.level]).forEach((d) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(T('level.' + d.level), d.level === 'error' ? 'danger' : d.level === 'warn' ? 'warn' : ''));
    main.append(el('div', 'row-name text', driftText(d)), meta);
    row.appendChild(main);
    list.appendChild(row);
  });
  box.appendChild(list);
  const hint = el('p', 'role', T('drift_hint') + ' ');
  hint.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
  box.appendChild(hint);
  return box;
}

/**
 * Getting things back is Mr. Restori's job (his own desk, per app and VM). Here only where my backups
 * lie and the way to him - each desk works on its own: without him, this still says where everything is.
 */
function restoreSection() {
  const box = section(T('restore'), T('restore_sub'));
  const set = state.settings || {};
  const prefix = set.snap_prefix || 'unraidbackup-';
  const dl = el('dl', 'kv bk-restore');
  const item = (title, ...parts) => {
    dl.appendChild(el('dt', '', title));
    const dd = el('dd');
    parts.forEach((p) => dd.append(p));
    dl.appendChild(dd);
  };
  item(T('restore.local'), T('restore.local_zfs'), ' ', el('code', '', `/mnt/<pool>/<share>/.zfs/snapshot/${prefix}…/`),
    ' ', T('restore.local_btrfs'), ' ', el('code', '', `${set.view_root || '/mnt/addons/UnraidSecretaryOffice/btrfs-snap'}/<disk>/<…>/<share>/`));
  if (set.kopia_enabled) {
    const root = ((state.drift && Array.isArray(state.drift.policies) && state.drift.policies.find((p) => p.kind === 'root')) || {}).path || set.mount_root || '/mnt/addons/UnraidSecretaryOffice/snapshots';
    item(T('restore.kopia'), T('restore.kopia_text', { container: set.kopia_container || 'kopia', root }),
      ...((state.items || []).length ? [' ', T('restore.kopia_items', { root, list: state.items.map((i) => `.${i.kind}s/${i.name}`).join(', ') })] : []));
  }
  const pk = packages();
  if (pk) item(T('restore.pk'), T('restore.pk_text', { path: pk.base }));
  box.appendChild(dl);
  const restori = Office.desks.get('restore');
  const p = el('p', 'callout');
  if (restori && restori.hired) {
    p.append(T('restore.restori'), ' ');
    p.appendChild(button(T('restore.restori_go'), 'small', () => Office.go('#/restore')));
  } else {
    p.append(T('restore.restori_hire'), ' ');
    const a = el('a', '', T('restore.restori_hire_link'));
    a.href = '#/caretaker';
    p.appendChild(a);
  }
  box.appendChild(p);
  return box;
}

function copyCode(text) {
  const c = el('code', 'bk-copy', text);
  c.title = Office.t('common.copy');
  c.dataset.own = '1';                 // a click copies - it doesn't fold the row it sits in
  c.onclick = () => Office.copy(text);
  return c;
}

// ------------------------------------------------------------------ actions
function chooseRun() {
  const box = el('div');
  let mode = 'backup';
  const kopia = state.settings && state.settings.kopia_enabled;
  const options = ['backup', ...(kopia ? ['nokopia'] : []), 'dryrun', 'check'];
  options.forEach((m) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'bk-mode';
    input.checked = m === mode;
    input.onchange = () => { mode = m; };
    const text = el('span', '', T('mode.' + m));
    text.appendChild(el('small', '', T('mode_hint.' + m)));
    label.append(input, text);
    box.appendChild(label);
  });
  const est = state.estimates || {};
  if (est.total) box.appendChild(el('p', 'callout', T('start_hint', { duration: fmt.duration(est.total) })));
  Office.dialog({
    title: T('start_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('start_go'), kind: '', act: () => startRun(mode) }],
  });
}

async function startRun(mode) {
  const j = await Office.api.post(`${ID}.start`, { mode });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
  if (j.state) state = j.state;
  Office.toast(j.started ? T('started.' + mode) : T('started_pending'));
  render();
  schedule();
  return true;
}

function abortRun() {
  const s = status();
  const box = el('div');
  box.appendChild(el('p', '', T('abort_text')));
  if (s && s.phase === 'kopia') box.appendChild(el('p', 'callout', T('abort_kopia')));
  if (s && ['stopping', 'snapshots', 'starting'].includes(s.phase)) box.appendChild(el('p', 'callout warn', T('abort_downtime')));
  Office.dialog({
    title: T('abort_title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('abort'), kind: 'danger', act: async () => {
        const j = await Office.api.post(`${ID}.abort`, {});
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.state) state = j.state;
        Office.toast(T('aborting'));
        render();
        schedule();
        return true;
      } },
    ],
  });
}

async function unmount() {
  const j = await Office.api.post(`${ID}.unmount`, {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  if (j.state) state = j.state;
  Office.toast(T('unmounted'));
  render();
}

async function showLog(name, follow) {
  if (!name) return;
  const pre = el('pre', 'code', Office.t('common.loading'));
  const box = el('div');
  box.appendChild(pre);
  let timer = null;
  const fetchLog = async (first) => {
    const atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
    const j = await Office.api.post(`${ID}.log`, { log: name });
    if (!j.ok) { pre.textContent = Office.errorText(j.error, ID); return; }
    pre.textContent = (j.cut ? T('log_cut') + '\n' : '') + j.text;
    if (first || atEnd) pre.scrollTop = pre.scrollHeight;
    if (follow && live()) timer = setTimeout(() => fetchLog(false), LIVE_POLL);
  };
  Office.dialog({ title: name, body: box, wide: true, onClose: () => clearTimeout(timer) });
  fetchLog(true);
}

// ------------------------------------------------------------------ setup assistant (#/backup/setup)
// setup.sh --plan looks at the server and writes its proposals (P = the
// settings.ini keys) with reasons; the user changes what he wants here, and
// setup.sh --apply checks and writes it — the same engine as in a terminal.
const SETUP_POLL = 2000;
const LIST_KEY = /\|(ignore|no_stop|known|skip|kopia_ignore|exclude_dataset|tar_exclude|folder)$/;
let setup = { plan: null, draft: null, status: null, run: null, applied: null, open: new Set(), retire: true, asked: false, focus: null,
  model: null, levels: {}, held: {}, deps: new Set(), locks: {}, itemKeep: {} };
let setupTimer = null;

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const clone = (o) => JSON.parse(JSON.stringify(o || {}));
const dget = (k, d) => (setup.draft[k] !== undefined ? setup.draft[k] : d);
function dset(k, v) {
  if (v === undefined || v === null) delete setup.draft[k];
  else setup.draft[k] = v;
  setupBar();
}

/** A fresh draft from the plan, with the apps' and VMs' levels read from it */
function setupDraftFromPlan() {
  setup.draft = clone(setup.plan.P);
  setup.itemKeep = {};
  if (!setup.plan.have_settings) {
    // a new setup sends nothing to Kopia unasked: every share starts local, the user picks what goes to Kopia
    Object.keys(setup.draft).forEach((k) => { if (/^share\|.+\|mode$/.test(k) && setup.draft[k] === 'kopia') setup.draft[k] = 'snapshot'; });
  }
  setup.model = setupModel(setup.plan);
  setupInitLevels();
  setupDerive();
  setup.base = clone(setup.draft);         // what the assistant proposes, before the user clicks
}

/** Keys whose values differ between two sets of settings (nothing and empty count the same) */
const empty = (v) => v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length);
function diffKeys(a, b) {
  return [...new Set([...Object.keys(a), ...Object.keys(b)])].filter((k) => !same(a[k], b[k]) && !(empty(a[k]) && empty(b[k]))).sort();
}

/**
 * What settings.ini says today, key by key. It leaves out empty lists and defaults: a key
 * missing in a section it has means the plan's value; a whole section it lacks is new.
 */
function setupSaved() {
  const O = setup.plan.O || {};
  const P = setup.plan.P || {};
  const section = (k) => k.slice(0, k.lastIndexOf('|'));
  const have = new Set(Object.keys(O).map(section));
  const saved = {};
  // a share's id and locations the engine writes itself from what it finds - not a decision of the user's
  Object.keys(O).forEach((k) => { if (!/^share\|.+\|(id|locations)$/.test(k)) saved[k] = O[k]; });
  Object.keys(P).forEach((k) => { if (!(k in O) && have.has(section(k))) saved[k] = P[k]; });
  return saved;
}

/** What the server boots from, as it really is: a USB stick or a boot pool (Unraid 7.3+, licence on the TPM) */
const flashLabel = (plan) => (plan.flash && plan.flash.dataset
  ? T('setup.g_flash_pool', { pool: plan.flash.dataset.split('/')[0] }) : T('setup.g_flash'));

/** In words: what is backed up how (for a new setup instead of a list of differences) */
function setupSummary() {
  const m = setup.model;
  const groups = [0, 1, 2].map(() => ({ vms: [], apps: [], shares: [] }));     // not, local, local + Kopia
  m.vms.forEach((x) => groups[levelOf('vm:' + x.name)].vms.push(x.name));
  m.apps.forEach((a) => {
    const l = levelOf('app:' + a.id);
    groups[l].apps.push(l > 0 && setup.held[a.id] === 'run' ? `${a.name} ${T('setup.sum_running')}` : a.name);
  });
  setup.plan.shares.filter((x) => x.exists).forEach((x) => {
    groups[Math.max(0, LV.indexOf(dget(`share|${x.name}|mode`, 'off')))].shares.push(x.name);
  });
  const ul = el('ul', 'shortlist');
  [2, 1, 0].forEach((l) => {
    if (l === 2 && dget('kopia|enabled') !== 'yes') return;
    ['vms', 'apps', 'shares'].forEach((kind) => {
      if (!groups[l][kind].length) return;
      const li = el('li', '', `${T('setup.sum_level.' + l)} · ${T('setup.sum_' + kind)}`);
      li.appendChild(el('span', '', groups[l][kind].join(', ')));
      ul.appendChild(li);
    });
  });
  const li = el('li', '', flashLabel(setup.plan));
  li.appendChild(el('span', '', T('setup.flash.' + dget('flash|mode', 'off'))));
  ul.appendChild(li);
  return ul;
}

/** What the user changed against the assistant's proposal */
function setupEdits() {
  const out = [];
  const base = setup.base || setup.plan.P;
  const keys = new Set([...Object.keys(base), ...Object.keys(setup.draft)]);
  keys.forEach((k) => { if (!same(base[k], setup.draft[k])) out.push(k); });
  return out;
}

async function setupLoad() {
  const j = await Office.api.post(`${ID}.setup_get`, {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  const was = setup.status;
  setup.status = j.status;
  setup.run = j.run;
  // a plan from before engine 2.17 doesn't know which shares a container binds: read the server again
  const stale = j.plan && ((j.plan.containers || []).some((c) => c.binds === undefined) || !('docker|skip' in (j.plan.P || {})));
  if (stale && !j.status.running && !setup.restale && canPlan()) { setup.restale = true; setupPlan(false, true); }
  if (j.plan && !stale && (!setup.plan || j.plan.time !== setup.plan.time)) {
    setup.plan = j.plan;
    setupDraftFromPlan();
  }
  const finishedForget = was && was.running && was.mode === 'forget' && !j.status.running;
  if (finishedForget) {
    if (j.run && j.run.result === 'ok') {
      Office.toast(T('setup.forgotten'));
      setup.plan = null;                     // the old plan went aside with the settings
      setup.draft = null;
      setup.applied = null;
      if (page === 'setup') renderSetup();
      load(true);
      setup.asked = false;                   // look at the server as if it were new
      if (!(await setupPlan(false, true))) { setup.asked = false; setupTimer = setTimeout(setupLoad, SETUP_POLL); }
      return;
    }
    Office.toast(T('setup.forget_failed'), true);
  }
  if (was && was.running && was.mode === 'plan' && !j.status.running) setupAfterPlan();
  const finishedApply = was && was.running && was.mode === 'apply' && !j.status.running;
  if (finishedApply) {
    setup.applied = j.run;
    if (j.run && j.run.result === 'ok') {
      Office.toast(T('setup.applied'));
      setup.plan = { ...setup.plan, P: clone(setup.draft), pending: [] };   // until the new plan is in
      setupBar();
      // look again (the plan should now show no changes), then check against the new settings
      // (seconds, changes nothing) - one after the other, both need the engine's lock
      load(true);
      setup.checkNext = true;
      if (!(await setupPlan(false, true))) setupAfterPlan();
      return;
    }
  }
  if (page === 'setup') renderSetup();
  clearTimeout(setupTimer);
  if (j.status.running) setupTimer = setTimeout(setupLoad, SETUP_POLL);
  else if (!setup.plan && !setup.asked && canPlan()) setupPlan(false);
}

// the plugin schedules through its own cron file (schedule.via), the stack through User Scripts
const asPlugin = () => !!(state && state.schedule && state.schedule.via === 'office');
const canPlan = () => !!(state && state.found && state.compatible && Office.agent.running && !state.running);

async function setupPlan(measure, quiet) {
  setup.asked = true;
  const j = await Office.api.post(`${ID}.setup_plan`, { measure: !!measure });
  if (!j.ok) { if (!quiet) Office.toast(Office.errorText(j.error, ID), true); return false; }
  setTimeout(setupLoad, 500);
  return true;
}

/** After an apply: once the new plan is in, check against the new settings */
function setupAfterPlan() {
  if (!setup.checkNext) return;
  setup.checkNext = false;
  Office.api.post(`${ID}.start`, { mode: 'check' }).then(() => load(true));
}

function setupApply() {
  const ds = dget('general|dumps_share', '');
  if (!ds) { Office.toast(T('setup.ds_missing'), true); return; }
  if (dget(`share|${ds}|mode`, 'off') === 'off') { Office.toast(T('setup.ds_off', { share: ds }), true); return; }
  const box = el('div');
  if (!setup.plan.have_settings) {
    // nothing saved yet: no list of differences, but what will be backed up how
    box.appendChild(el('p', '', T('setup.apply_new')));
    box.appendChild(setupSummary());
  } else {
    // what really changes in settings.ini - against the saved file, not against proposals
    const saved = setupSaved();
    const changes = diffKeys(saved, setup.draft);
    box.appendChild(el('p', '', changes.length ? T('setup.apply_text', { n: changes.length }) : T('setup.apply_none')));
    if (changes.length) {
      const ul = el('ul', 'shortlist');
      changes.slice(0, 80).forEach((k) => {
        const li = el('li', '', changeLabel(k));
        li.appendChild(el('span', '', `${valueText(saved[k], k)} → ${valueText(setup.draft[k], k)}`));
        ul.appendChild(li);
      });
      box.appendChild(ul);
    }
  }
  box.appendChild(el('p', 'role', T(!setup.plan.have_settings ? 'setup.apply_hint_new' : asPlugin() ? 'setup.apply_hint_plugin' : 'setup.apply_hint')));
  Office.dialog({
    title: T('setup.apply_title'),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('setup.apply_go'), kind: '', act: async () => {
        const decisions = { ...setup.draft, _retire_sources: setup.retire ? 'yes' : 'no' };
        const j = await Office.api.post(`${ID}.setup_apply`, { decisions });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        setup.applied = null;
        Office.toast(T('setup.applying'));
        setupLoad();
        return true;
      } },
    ],
  });
}

function changeLabel(k) {
  const p = k.split('|');
  if (p[0] === 'share') return T('setup.ch_share', { share: p[1], key: T('setup.key.' + p[2]) });
  if (p[0] === 'app') return T('setup.ch_app', { name: p[1], key: Office.has(`${ID}.setup.key.item_${p[2]}`) ? T('setup.key.item_' + p[2]) : p[2] });
  if (p[0] === 'vm' && Office.has(`${ID}.setup.key.item_${p[2]}`)) return T('setup.ch_vm', { name: p[1], key: T('setup.key.item_' + p[2]) });
  if (p[0] === 'dump') return T('setup.ch_dump', { name: p[1] });
  if (p[0] === 'nextcloud') return T('setup.ch_nextcloud', { name: p[1] });
  if (p[0] === 'vm') return T('setup.ch_vm', { name: p[1], key: Office.has(`${ID}.setup.key.vm_${p[2]}`) ? T('setup.key.vm_' + p[2]) : p[2] });
  return Office.has(`${ID}.setup.key.${p.join('_')}`) ? T('setup.key.' + p.join('_')) : k;
}
function valueText(v, k = '') {
  if (v === undefined || v === null) return '–';
  if (Array.isArray(v)) return v.length ? v.join(', ') : '–';
  if (v === '') return '–';
  // the words the page uses, not settings.ini's values
  const p = k.split('|');
  const word = p[0] === 'share' && p[2] === 'mode' ? `setup.mode.${v}`
    : p[0] === 'vm' && p[2] === 'mode' ? `setup.vm_mode.${v}`
      : p[0] === 'vm' && p[2] === 'prepare' ? `setup.vm_prep.${v}` : '';
  if (word && Office.has(`${ID}.${word}`)) return T(word);
  if (v === 'yes' || v === 'no') return Office.t(`common.${v}`);
  return v;
}

/** Forget the settings and start the setup anew — nothing backed up is touched */
function setupForget() {
  const box = el('div');
  box.appendChild(el('p', '', T('setup.forget_text')));
  box.appendChild(el('p', 'callout warn', T('setup.forget_warn')));
  box.appendChild(el('p', '', T('setup.forget_keeps')));
  box.appendChild(el('p', 'role', T('setup.forget_snapshots')));
  Office.dialog({
    title: T('setup.forget_title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('setup.forget_go'), kind: 'danger', act: async () => {
        const j = await Office.api.post(`${ID}.setup_forget`, {});
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.state) state = j.state;
        setup.status = { ...(setup.status || {}), running: true, mode: 'forget' };
        renderSetup();
        setTimeout(setupLoad, 500);
        return true;
      } },
    ],
  });
}

/** The bar at the bottom: how many changes, apply */
function setupBar() {
  if (page !== 'setup' || !setup.plan || !setup.draft) { Office.selbar(null); return; }
  const edits = setupEdits().length;
  // proposals: what Apply would change in the saved settings.ini although the user clicked nothing
  const fresh = !setup.plan.have_settings;     // nothing set up yet: Apply is how it starts
  const proposals = fresh ? 0 : diffKeys(setupSaved(), setup.base || setup.plan.P).length;
  const busy = setup.status && setup.status.running;
  if (!edits && !fresh && proposals <= 0) { Office.selbar(null); return; }      // nothing to apply: no bar that keeps offering it
  Office.selbar({
    title: edits ? T('setup.bar_changes', { n: edits }) : fresh ? T('setup.bar_new') : T('setup.bar_proposals', { n: proposals }),
    sub: T('setup.bar_sub', { when: fmt.relative(setup.plan.time) }),
    buttons: [
      { text: T('setup.discard'), kind: 'plain', disabled: !edits || busy, act: () => { setupDraftFromPlan(); renderSetup(); } },
      { text: T('setup.apply'), disabled: busy || !Office.agent.running, act: setupApply },
    ],
  });
}

// ---- small form helpers
function field(label, input, hint) {
  const f = el('div', 'field');
  const l = el('label', '', label);
  f.append(l, input);
  if (hint) f.appendChild(el('small', '', hint));
  return f;
}
function textInput(key, pattern, placeholder) {
  const i = el('input', 'input small');
  i.value = dget(key, '') ?? '';
  if (placeholder) i.placeholder = placeholder;
  i.oninput = () => {
    const v = i.value.trim();
    const ok = !pattern || v === '' || pattern.test(v);
    i.classList.toggle('bad', !ok);
    if (ok) dset(key, v === '' && setup.plan.P[key] === undefined ? undefined : v);
  };
  return i;
}
function selectInput(key, options, labels) {
  const sel = el('select', 'picker');
  options.forEach((o) => sel.appendChild(new Option(labels ? labels(o) : o, o)));
  sel.value = dget(key, options[0]);
  sel.onchange = () => dset(key, sel.value);
  return sel;
}
function listInput(key, rows) {
  const ta = el('textarea', 'input mono');
  ta.rows = rows || 4;
  ta.value = (dget(key, []) || []).join('\n');
  ta.oninput = () => dset(key, ta.value.split('\n').map((x) => x.trim()).filter(Boolean));
  return ta;
}
function checkbox(text, checked, onchange, small) {
  const label = el('label', 'check');
  const input = el('input');
  input.type = 'checkbox';
  input.checked = !!checked;
  input.onchange = () => onchange(input.checked);
  const span = el('span', '', text);
  if (small) span.appendChild(el('small', '', small));
  label.append(input, span);
  return label;
}
const setupSection = (title, sub) => section(title, sub);

// ---- rendering
function renderSetup() {
  const root = view;
  if (!root) return;
  root.innerHTML = '';
  const back = button(T('setup.back'), 'plain', () => Office.go(`#/${ID}`));
  const again = button(T('setup.replan'), 'plain', () => setupPlan(false));
  const measure = button(T('setup.measure'), 'plain', () => setupPlan(true));
  const forget = button(T('setup.forget_short') + ' …', 'plain', setupForget);
  const busy = setup.status && setup.status.running;
  [again, measure, forget].forEach((b) => { b.disabled = busy || !canPlan(); });
  measure.title = T('setup.measure_hint');
  forget.title = T('help.forget');
  forget.hidden = !(setup.plan && setup.plan.have_settings);
  const pageSub = setup.plan ? T(setup.plan.have_settings ? 'setup.sub_have' : 'setup.sub_new') : '';
  const { head } = Office.deskHead(Office.desks.get(ID), { page: T('setup.page'), pageSub, actions: [back, again, measure, forget] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('help.draft'), T('help.draft_text')],
    [T('setup.replan'), T('help.replan')],
    [T('setup.measure'), T('setup.measure_hint')],
    [T('help.reasons'), T('help.reasons_text')],
    [T('setup.more'), T('help.details')],
    [T('setup.apply'), T('help.apply')],
    [T('setup.forget_short'), T('help.forget')],
  ]));

  if (setup.applied) root.appendChild(appliedCard(setup.applied));
  if (!busy && setup.plan && Date.now() / 1000 - setup.plan.time > SETUP_STALE) root.appendChild(el('p', 'callout warn', T('setup.bubble_old', { when: fmt.relative(setup.plan.time) })));
  if (!busy && !setup.plan && state && state.running) root.appendChild(el('p', 'callout', T('setup.bubble_backup_runs')));
  if (!busy && !setup.plan && !(state && state.running)) root.appendChild(el('p', 'callout running', T('setup.bubble_loading')));
  if (busy) {
    const p = el('p', 'callout running');
    const mode = setup.status.mode;
    p.append(el('span', 'spin'), ' ', T(mode === 'apply' ? (asPlugin() ? 'setup.applying_long_plugin' : 'setup.applying_long') : mode === 'forget' ? 'setup.forgetting' : 'setup.planning_long'));
    root.appendChild(p);
  }
  if (!setup.plan) { setupBar(); return; }

  const plan = setup.plan;
  root.appendChild(setupKopia(plan));          // 0 basics: where backups go, Kopia, the flash
  root.appendChild(setupVms(plan));            // 1
  root.appendChild(setupApps(plan));           // 2
  root.appendChild(setupShares(plan));         // 3 what is left
  root.appendChild(setupRetention(plan));      // 4
  root.appendChild(setupGeneral(plan));        // everything else, rarely changed
  const old = (plan.kopia.sources || []).filter((s) => s.state === 'orphan' || s.state === 'gone');
  if (old.length) root.appendChild(setupSources(old));
  root.appendChild(setupMessages(plan.messages));
  setupBar();
  // came from "Change…" on the main page: show that share
  if (setup.focus) {
    const row = [...root.querySelectorAll('[data-share], [data-focus]')].find((r) => (r.dataset.focus || r.dataset.share) === setup.focus);
    setup.focus = null;
    if (row) requestAnimationFrame(() => row.scrollIntoView({ block: 'start', behavior: 'smooth' }));
  }
}

function appliedCard(run) {
  const ok = run.result === 'ok' && run.written;
  const box = el('div', 'callout' + (ok ? '' : ' warn'));
  box.appendChild(el('strong', '', ok ? T('setup.applied_ok') : T('setup.applied_failed')));
  if (ok && state && state.schedule && state.schedule.script && !state.schedule.enabled) {
    box.append(' ', T('schedule.after_setup'), ' ', button(T('schedule.open'), 'small', scheduleDialog));
  }
  const bad = (run.messages || []).filter((m) => m.level === 'error' || m.level === 'warn');
  if (bad.length) {
    const ul = el('ul', 'bk-msgs');
    bad.forEach((m) => ul.appendChild(el('li', m.level, m.text)));
    box.appendChild(ul);
  }
  return box;
}

function setupKopia(plan) {
  const k = plan.kopia;
  const s = setupSection(T('setup.kopia'), T('setup.kopia_sub'));
  const basics = el('div', 'bk-form');
  basics.appendChild(dumpsShareField(plan));
  const flashOpts = plan.flash.dataset ? ['snapshot', 'tar', 'off'] : ['tar', 'off'];
  basics.appendChild(field(flashLabel(plan), selectInput('flash|mode', flashOpts, (o) => T('setup.flash.' + o)),
    plan.flash.dataset ? T('setup.g_flash_zfs', { ds: plan.flash.dataset }) : T('setup.g_flash_other', { fs: plan.flash.fs || '?' })));
  s.appendChild(basics);
  const on = dget('kopia|enabled') === 'yes';
  s.appendChild(checkbox(T('setup.kopia_on'), on, (v) => {
    dset('kopia|enabled', v ? 'yes' : 'no');
    if (!v) Object.keys(setup.draft).forEach((key) => { if (/^share\|.+\|mode$/.test(key) && setup.draft[key] === 'kopia') setup.draft[key] = 'snapshot'; });
    setupDerive();
    renderSetup();
  }, T('setup.kopia_on_hint')));
  if (!on) return s;
  if (!plan.P['kopia|enabled'] || plan.P['kopia|enabled'] !== 'yes') {
    s.appendChild(el('p', 'callout', T('setup.kopia_recheck')));
    return s;
  }
  const facts = el('dl', 'kv');
  const fact = (label, value, bad) => {
    facts.appendChild(el('dt', '', label));
    facts.appendChild(el('dd', bad ? 'bk-bad' : '', value));
  };
  if (k.candidates.length > 1) {
    const sel = selectInput('kopia|container', k.candidates);
    facts.appendChild(el('dt', '', T('setup.k_container')));
    const dd = el('dd'); dd.appendChild(sel); facts.appendChild(dd);
  } else {
    fact(T('setup.k_container'), k.container ? `${k.container} (${k.image || '?'}) — ${T(k.running ? 'setup.k_running' : 'setup.k_stopped')}` : T('setup.k_none'), !k.container || !k.running);
  }
  const main = (k.mappings || []).find((m) => m.main);
  if (main) fact(T('setup.k_mapping'), `${main.source} → ${main.target} (${main.rw ? 'rw' : 'ro'}, ${main.propagation || 'private'})`, main.rw || !/slave|shared/.test(main.propagation));
  if (k.probe !== null) fact(T('setup.k_probe'), T('setup.k_probe_' + k.probe), k.probe !== 0);
  if (k.connected) fact(T('setup.k_repo'), `${k.identity} · ${k.storage || '?'}${k.version ? ' · Kopia ' + k.version : ''}`);
  if (k.server_uid !== '') fact(T('setup.k_uid'), k.server_uid === '0' ? 'root' : `UID ${k.server_uid}`, k.server_uid !== '0');
  s.appendChild(facts);
  if (k.problem && k.problem !== 'off') {
    const p = el('p', 'callout warn', T('setup.k_problem.' + k.problem, { name: k.container || 'kopia', root: plan.mount_root }));
    if (Office.desks.has('caretaker')) {
      const a = el('a', '', T('setup.k_caretaker'));
      a.href = '#/caretaker';
      p.append(' ', a);
    }
    s.appendChild(p);
  }
  if (k.host_is_container_id) s.appendChild(el('p', 'callout warn', T('setup.k_hostid', { name: k.container })));
  return s;
}

function shareWhy(sh) {
  const code = sh.why || '';
  if (!code) return sh.why_text || '';
  const arg = sh.why_arg;
  const gb = Number(arg);
  if (code === 'timemachine' && arg === 'name') return T('setup.why.timemachine_name');   // only a guess from the name
  return T('setup.why.' + code, { arg, size: isNaN(gb) || arg === '' ? arg : gb < 0 ? '> ?' : fmt.size(gb * 1073741824) });
}

function setupShares(plan) {
  const s = setupSection(T('setup.shares'), T('setup.shares_sub'));
  const kopiaOn = dget('kopia|enabled') === 'yes';
  const wrap = el('div', 'box table-wrap');
  const table = el('table', 'grid bk-shares');
  const hr = el('tr');
  ['share', 'where', 'size', 'mode', 'why', ''].forEach((c) => hr.appendChild(el('th', '', c ? T('setup.col.' + c) : '')));
  const thead = el('thead'); thead.appendChild(hr); table.appendChild(thead);
  const body = el('tbody');
  plan.shares.forEach((sh) => {
    const tr = el('tr');
    tr.dataset.share = sh.name;
    tr.appendChild(el('th', '', sh.name));
    tr.appendChild(el('td', '', sh.where === '-' ? '' : sh.where));
    tr.appendChild(el('td', 'num', sh.gb === null ? '' : sh.gb < 0 ? '> ?' : fmt.size(sh.gb * 1073741824)));
    const modeCell = el('td');
    const lock = setup.locks[sh.name];
    // the backup place: at least local, Kopia on top is the user's choice
    const atLeast = lock && lock.min ? LV.slice(lock.lv).filter((o) => kopiaOn || o !== 'kopia').reverse() : null;
    if (sh.exists && atLeast && atLeast.length > 1) {
      const sel = selectInput(`share|${sh.name}|mode`, atLeast, (o) => T('setup.mode.' + o));
      if (!atLeast.includes(sel.value)) sel.value = atLeast[atLeast.length - 1];
      sel.title = T('setup.lock_place_hint');
      sel.onchange = () => { dset(`share|${sh.name}|mode`, sel.value); setupDerive(); Office.keepInPlace(sel, () => renderSetup()); };
      modeCell.appendChild(sel);
    } else if (sh.exists && lock) {
      // fixed by the VMs and apps above (or the backup place)
      const sel = selectInput(`share|${sh.name}|mode`, [LV[lock.lv]], (o) => T('setup.mode.' + o));
      sel.disabled = true;
      sel.title = T(lock.min ? 'setup.lock_place_hint' : 'setup.lock_hint');
      modeCell.appendChild(sel);
    } else if (sh.exists) {
      const opts = kopiaOn ? ['kopia', 'snapshot', 'off'] : ['snapshot', 'off'];
      const sel = selectInput(`share|${sh.name}|mode`, opts, (o) => T('setup.mode.' + o));
      if (!opts.includes(sel.value)) sel.value = 'snapshot';
      sel.onchange = () => { dset(`share|${sh.name}|mode`, sel.value); setupDerive(); Office.keepInPlace(sel, () => renderSetup()); };
      modeCell.appendChild(sel);
    } else {
      modeCell.appendChild(chip(T('setup.gone'), 'warn'));
    }
    tr.appendChild(modeCell);
    const why = el('td', 'bk-why', lock ? T('setup.lock', { list: lock.why.join(', ') }) : shareWhy(sh));
    if (sh.notes.length) why.title = sh.notes.join('\n');
    tr.appendChild(why);
    const more = el('td');
    if (sh.exists) {
      const b = button(setup.open.has(sh.name) ? T('setup.less') : T('setup.more'), 'small plain', () => {
        if (setup.open.has(sh.name)) setup.open.delete(sh.name); else setup.open.add(sh.name);
        renderSetup();
      });
      more.appendChild(b);
    }
    tr.appendChild(more);
    body.appendChild(tr);
    if (setup.open.has(sh.name)) {
      const dtr = el('tr', 'bk-detail');
      const td = el('td');
      td.colSpan = 6;
      td.appendChild(shareDetails(sh, plan));
      dtr.appendChild(td);
      body.appendChild(dtr);
    }
  });
  table.appendChild(body);
  wrap.appendChild(table);
  s.appendChild(wrap);
  return s;
}

function shareDetails(sh, plan) {
  const k = (x) => `share|${sh.name}|${x}`;
  const box = el('div', 'bk-form');
  if (sh.notes.length) box.appendChild(el('p', 'callout', sh.notes.join(' · ')));
  box.appendChild(field(T('setup.f_retention'), textInput(k('retention'), /^\d+ \d+ \d+$/, dget('zfs|retention', '')), T('setup.f_retention_hint')));
  if (dget('kopia|enabled') === 'yes') {
    box.appendChild(field(T('setup.f_kopia_retention'), textInput(k('kopia_retention'), /^(\d+|inherit)( (\d+|inherit)){5}$/, T('setup.f_like_all')), T('setup.f_kopia_retention_hint')));
    const ign = listInput(k('kopia_ignore'), 3);
    box.appendChild(field(T('setup.f_ignore'), ign, T('setup.f_ignore_hint')));
    // every folder at the top of the share, with the containers that use it
    const users = new Map();
    (sh.top || []).forEach((d) => users.set(d, []));
    sh.folders.forEach((f) => {
      if (!users.has(f.dir)) users.set(f.dir, []);
      if (!users.get(f.dir).includes(f.container)) users.get(f.dir).push(f.container);
    });
    const asleep = sh.top_asleep || [];
    if (users.size || sh.top_many || asleep.length) {
      const sug = el('div', 'bk-chips');
      sug.appendChild(el('small', 'role', T(users.size ? 'setup.f_folders' : 'setup.f_folders_none')));
      users.forEach((cts, dir) => {
        const rule = `/${dir}/`;
        const have = (dget(k('kopia_ignore'), []) || []).includes(rule);
        const c = button(cts.length ? `${rule} · ${cts.join(', ')}` : rule, 'small ' + (have ? '' : 'plain'), () => {
          const list = [...(dget(k('kopia_ignore'), []) || [])];
          const i = list.indexOf(rule);
          if (i >= 0) list.splice(i, 1); else list.push(rule);
          dset(k('kopia_ignore'), list);
          renderSetup();
        });
        c.title = T(have ? 'setup.f_folder_on' : 'setup.f_folder_off');
        sug.appendChild(c);
      });
      if (sh.top_many) sug.appendChild(el('small', 'role', T('setup.f_folders_many')));
      if (asleep.length) sug.appendChild(el('small', 'role', T('setup.f_folders_asleep', { disks: asleep.join(', ') })));
      box.appendChild(sug);
    }
  }
  box.appendChild(field(T('setup.f_method'), selectInput(k('method'), ['auto', 'live'], (o) => T('setup.method.' + o)), T('setup.f_method_hint')));
  if (sh.children.length) {
    const kids = el('div');
    sh.children.forEach((ds) => {
      const list = dget(k('exclude_dataset'), []) || [];
      kids.appendChild(checkbox(ds, !list.includes(ds), (v) => {
        const now = [...(dget(k('exclude_dataset'), []) || [])].filter((x) => x !== ds);
        if (!v) now.push(ds);
        dset(k('exclude_dataset'), now);
      }));
    });
    box.appendChild(field(T('setup.f_children'), kids, T('setup.f_children_hint')));
  }
  return box;
}

// ---- steps 1 and 2: the VMs and apps the user wants back decide the shares they live in
// A level per VM / app: 0 not backed up, 1 local snapshot, 2 local + Kopia. Shares,
// running containers, dumps and Kopia's exceptions follow from them (setupDerive).
const LV = ['off', 'snapshot', 'kopia'];

/** Apps (a compose stack or a single container) and VMs with the shares and folders they use */
function setupModel(plan) {
  const names = new Set(plan.shares.map((x) => x.name));
  // container shares (appdata & co.): folders of three or more containers - an app's home
  const homes = new Set(plan.shares.filter((x) => new Set((x.folders || []).map((f) => f.container)).size >= 3).map((x) => x.name));
  const groups = new Map();
  const skipped = [];
  plan.containers.forEach((c) => {
    if (c.kopia || c.why === 'office') { skipped.push(c); return; }
    const id = c.project ? 'stack:' + c.project : 'ct:' + c.name;
    if (!groups.has(id)) groups.set(id, { id, name: c.project || c.name, stack: !!c.project, members: [] });
    groups.get(id).members.push(c);
  });
  const apps = [...groups.values()].map((a) => {
    const members = a.members.map((c) => c.name);
    const folders = [];
    const deps = new Map();
    a.members.forEach((c) => (c.binds || []).forEach((b) => {
      if (!names.has(b.share) || b.share.toLowerCase() === 'system') return;
      if (homes.has(b.share)) {
        const dir = b.path.split('/')[0];
        if (dir && !folders.some((f) => f.share === b.share && f.dir === dir)) folders.push({ share: b.share, dir });
        return;
      }
      const d = deps.get(b.share) || { share: b.share, paths: [] };
      if (b.path && !d.paths.includes(b.path)) d.paths.push(b.path);
      deps.set(b.share, d);
    }));
    return {
      ...a, members, folders, deps: [...deps.values()],
      dbs: plan.databases.filter((d) => members.includes(d.container)),
      ncs: plan.nextcloud.filter((n) => n.members.some((m) => members.includes(m))),
      volumes: a.members.flatMap((c) => c.volumes.map((v) => (a.stack ? `${c.name}: ${v}` : v))),
    };
  }).sort((x, y) => (y.stack - x.stack) || x.name.localeCompare(y.name, undefined, { sensitivity: 'base' }));
  const vms = (plan.vms || []).map((v) => {
    const disks = (v.disks || []).filter((d) => d.share && names.has(d.share));
    const folders = [];
    disks.forEach((d) => {
      const m = (d.source || '').match(/^\/mnt\/[^/]+\/[^/]+\/([^/]+)\//);
      if (m && !folders.some((f) => f.share === d.share && f.dir === m[1])) folders.push({ share: d.share, dir: m[1] });
    });
    return { name: v.name, v, own: v.own.length > 0, shares: [...new Set(disks.map((d) => d.share))], folders };
  });
  return { apps, vms, skipped, homes };
}

const levelOf = (key) => {
  const max = dget('kopia|enabled') === 'yes' ? 2 : 1;
  return Math.min(setup.levels[key] ?? 0, max);
};

/** The levels as the draft says (settings.ini or the plan's proposals) */
function setupInitLevels() {
  const m = setup.model;
  const ignored = (share, dir) => (dget(`share|${share}|kopia_ignore`, []) || []).includes(`/${dir}/`);
  const onKopia = (folders) => folders.length > 0 && folders.every((f) => dget(`share|${f.share}|mode`) === 'kopia' && !ignored(f.share, f.dir));
  const fresh = !setup.plan.have_settings;          // a new setup sends nothing to Kopia unasked
  const nostop = dget('docker|no_stop', []) || [];
  const skip = dget('docker|skip', []) || [];
  setup.levels = {};
  setup.held = {};
  setup.deps = new Set();
  m.apps.forEach((a) => {
    // every app has at least its template or compose file: "not" only when the user said so (docker|skip)
    // a Kopia source of its own (engine 2.19) says "local + Kopia" also for an app without folders of its own
    let l = a.members.every((n) => skip.includes(n)) ? 0 : onKopia(a.folders) || dget(`app|${a.name}|kopia`) === 'yes' ? 2 : 1;
    if (fresh) l = Math.min(l, 1);
    setup.levels['app:' + a.id] = l;      // an app's further shares are never ticked unasked (they grow fast)
    // during the snapshot: the engine's proposal - media servers and apps without changing data keep running
    setup.held[a.id] = a.members.every((n) => nostop.includes(n) || skip.includes(n)) ? 'run' : 'stop';
  });
  m.vms.forEach((x) => {
    const k = (y) => `vm|${x.name}|${y}`;
    const shareOff = x.shares.some((sh) => dget(`share|${sh}|mode`, 'off') === 'off');
    let l = shareOff || dget(k('mode')) === 'off' || dget(k('prepare')) === 'none' ? 0 : onKopia(x.folders) || dget(k('kopia')) === 'yes' ? 2 : 1;
    if (fresh) l = Math.min(l, 1);
    setup.levels['vm:' + x.name] = l;
  });
}

/** Writes what the levels mean into the draft; remembers which shares they fix (setup.locks) */
function setupDerive() {
  const m = setup.model;
  if (!m) return;
  const need = new Map();
  const want = (share, l, who) => {
    if (l < 1) return;
    const n = need.get(share) || { lv: 0, why: [] };
    n.lv = Math.max(n.lv, l);
    if (!n.why.includes(who)) n.why.push(who);
    need.set(share, n);
  };
  // apps: not backed up = keeps running; backed up = stopped, its databases dumped
  const inApps = new Set(m.apps.flatMap((a) => a.members));
  const was = dget('docker|no_stop', []) || [];
  const wasSkip = dget('docker|skip', []) || [];
  const keep = was.filter((n) => !inApps.has(n));
  const skip = [];
  m.apps.forEach((a) => {
    const l = levelOf('app:' + a.id);
    if (l === 0) { skip.push(...a.members); keep.push(...a.members); }
    else if (setup.held[a.id] === 'run') keep.push(...a.members);
    else keep.push(...a.dbs.filter((d) => d.type === 'cache').map((d) => d.container));   // a cache (redis) need not stop
    a.dbs.filter((d) => d.dumpable).forEach((d) => {
      const k = `dump|${d.container}|type`;
      dset(k, l > 0 ? (dget(k) ?? d.type) : undefined);
    });
    a.ncs.forEach((n) => n.members.forEach((mb) => {
      const k = `nextcloud|${mb}|preexisting_maintenance`;
      dset(k, l > 0 ? (dget(k) || n.preexisting || 'abort') : undefined);
    }));
    a.folders.forEach((f) => want(f.share, l, a.name));
    a.deps.forEach((d) => { if (setup.deps.has(`${a.id}|${d.share}`)) want(d.share, l, a.name); });
  });
  const nostop = [...was.filter((n) => keep.includes(n)), ...keep.filter((n) => !was.includes(n))];
  dset('docker|no_stop', [...new Set(nostop)]);
  dset('docker|skip', [...new Set([...wasSkip.filter((n) => skip.includes(n)), ...skip.filter((n) => !wasSkip.includes(n))])]);
  // VMs: not backed up = not held (in a shared dataset it stays in the share's snapshot anyway)
  m.vms.forEach((x) => {
    const l = levelOf('vm:' + x.name);
    const k = (y) => `vm|${x.name}|${y}`;
    dset(k('mode'), x.own && l === 0 ? 'off' : 'snapshot');
    if (l === 0) dset(k('prepare'), 'none');
    else if (!dget(k('prepare')) || dget(k('prepare')) === 'none') dset(k('prepare'), ['yes', 'channel'].includes(x.v.agent) ? 'freeze' : 'pause');
    x.shares.forEach((sh) => want(sh, l, x.name));
  });
  setup.locks = {};
  need.forEach((n, share) => {
    setup.locks[share] = n;
    dset(`share|${share}|mode`, LV[n.lv]);
  });
  // whatever goes to Kopia needs its templates, dumps and VM configurations there too: the backup share follows
  const ds = dget('general|dumps_share', '');
  if (ds && dget('kopia|enabled') === 'yes'
      && Object.keys(setup.draft).some((k) => /^share\|.+\|mode$/.test(k) && k !== `share|${ds}|mode` && setup.draft[k] === 'kopia')) {
    setup.locks[ds] = { lv: 2, why: [T('setup.lock_pkg')] };
    dset(`share|${ds}|mode`, 'kopia');
  }
  // the packages keep their history in the backup share's snapshots: at least local (Kopia may be chosen on top)
  if (ds) {
    const lock = setup.locks[ds];
    if (lock) lock.why.push(T('setup.lock_place'));
    else {
      setup.locks[ds] = { lv: 1, why: [T('setup.lock_place')], min: true };
      if (LV.indexOf(dget(`share|${ds}|mode`, 'off')) < 1) dset(`share|${ds}|mode`, 'snapshot');
    }
  }
  // Kopia leaves out the folders of apps and VMs that are only local
  if (dget('kopia|enabled') === 'yes') {
    const dirs = new Map();
    const note = (f, l) => {
      const key = `${f.share}|${f.dir}`;
      dirs.set(key, Math.max(dirs.get(key) ?? 0, l));
    };
    m.apps.forEach((a) => a.folders.forEach((f) => note(f, levelOf('app:' + a.id))));
    m.vms.forEach((x) => x.folders.forEach((f) => note(f, levelOf('vm:' + x.name))));
    dirs.forEach((l, key) => {
      const [share, dir] = key.split('|');
      if (dget(`share|${share}|mode`) !== 'kopia') return;
      const k = `share|${share}|kopia_ignore`;
      const list = [...(dget(k, []) || [])];
      const rule = `/${dir}/`;
      const at = list.indexOf(rule);
      if (l < 2 && at < 0) list.push(rule);
      if (l >= 2 && at >= 0) list.splice(at, 1);
      dset(k, list);
    });
  }
  setupDeriveItems();
}

/**
 * Apps and VMs at "local + Kopia" are Kopia sources of their own (engine 2.19): [app|vm "<name>"] kopia = yes
 * with the folders they keep (an app's folders in container shares, a VM's folder in domains); their package
 * comes along by itself. A folder several apps use goes to one of them: the one named like it, else the first.
 * Lower the level and the section goes (its own retention and rules are remembered for this page).
 */
function itemFolders() {
  const m = setup.model;
  const owner = new Map();
  m.apps.forEach((a) => {
    if (levelOf('app:' + a.id) < 2) return;
    a.folders.forEach((f) => {
      const key = `${f.share}|${f.dir}`;
      const named = [a.name, ...a.members].some((n) => n.toLowerCase() === f.dir.toLowerCase());
      const now = owner.get(key);
      if (!now || (named && !now.named)) owner.set(key, { id: a.id, named });
    });
  });
  return owner;
}
const ITEM_KEYS = ['kopia', 'folder', 'kopia_retention', 'kopia_ignore'];
function setupDeriveItems() {
  const m = setup.model;
  const on = dget('kopia|enabled') === 'yes';
  const owner = itemFolders();
  const set = (kind, name, yes, folders) => {
    const pre = `${kind}|${name}|`;
    if (yes) {
      const kept = setup.itemKeep[pre] || {};
      dset(pre + 'kopia', 'yes');
      dset(pre + 'folder', folders);
      ['kopia_retention', 'kopia_ignore'].forEach((x) => { if (dget(pre + x) === undefined && kept[x] !== undefined) dset(pre + x, kept[x]); });
    } else {
      if (dget(pre + 'kopia') === 'yes') setup.itemKeep[pre] = { kopia_retention: dget(pre + 'kopia_retention'), kopia_ignore: dget(pre + 'kopia_ignore') };
      ITEM_KEYS.forEach((x) => dset(pre + x, undefined));
    }
  };
  const names = new Set();
  m.apps.forEach((a) => {
    const yes = on && levelOf('app:' + a.id) === 2 && !names.has(a.name);
    names.add(a.name);
    set('app', a.name, yes, yes ? a.folders.filter((f) => (owner.get(`${f.share}|${f.dir}`) || {}).id === a.id).map((f) => `${f.share}/${f.dir}`) : []);
  });
  m.vms.forEach((x) => {
    const yes = on && levelOf('vm:' + x.name) === 2;
    set('vm', x.name, yes, yes ? x.folders.map((f) => `${f.share}/${f.dir}`) : []);
  });
}

/** nicht | lokal | lokal + Kopia */
function levelPick(key, onPick) {
  const max = dget('kopia|enabled') === 'yes' ? 2 : 1;
  const now = levelOf(key);
  const g = el('div', 'bk-seg');
  g.setAttribute('role', 'radiogroup');
  for (let l = 0; l <= max; l++) {
    const b = el('button', l === now ? 'on' : '', T('setup.level.' + l));
    b.type = 'button';
    b.setAttribute('role', 'radio');
    b.setAttribute('aria-checked', String(l === now));
    b.title = T('setup.level_hint.' + l);
    b.onclick = () => {
      if (l === now) return;
      setup.levels[key] = l;
      if (onPick) onPick(l);
      setupDerive();
      Office.keepInPlace(g, () => renderSetup());
    };
    g.appendChild(b);
  }
  return g;
}

/** Step 1: which VMs are wanted back, and how each one is held for the snapshot */
function setupVms(plan) {
  const s = setupSection(T('setup.vms'), T('setup.vms_sub'));
  if (!plan.vm_service) { s.appendChild(el('p', 'empty', T('setup.vm_service_off'))); return s; }
  const vms = setup.model.vms;
  if (!vms.length) { s.appendChild(el('p', 'empty', T('setup.vm_none'))); return s; }
  const list = el('div', 'box');
  vms.forEach((x) => {
    const v = x.v;
    const k = (y) => `vm|${v.name}|${y}`;
    const l = levelOf('vm:' + v.name);
    const row = el('div', 'row nocheck bk-vm');
    row.dataset.focus = 'vm:' + v.name;
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', v.name));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T(v.state === 'running' ? 'setup.vm_running' : 'setup.vm_off')));
    meta.appendChild(chip(T('setup.vm_agent.' + v.agent), v.agent === 'yes' ? 'ok' : '', T('setup.vm_agent_hint.' + v.agent)));
    if (v.tpm) meta.appendChild(chip(T('setup.vm_tpm'), '', T('setup.vm_tpm_hint')));
    if (v.hostdev) meta.appendChild(chip(T('setup.vm_gpu', { n: v.hostdev }), '', T('setup.vm_gpu_hint')));
    if (v.snap !== 'yes') meta.appendChild(chip(T('setup.vm_cannot.' + v.snap), 'danger', T('setup.vm_cannot_hint')));
    else if (x.own) meta.appendChild(el('span', 'mono', v.own.join(', ')));
    else meta.appendChild(chip(T('setup.vm_shared'), '', T('setup.vm_shared_hint')));
    main.appendChild(meta);
    if (l === 0 && !x.own && x.shares.some((sh) => dget(`share|${sh}|mode`, 'off') !== 'off')) {
      main.appendChild(el('div', 'row-meta', T('setup.vm_off_shared', { share: x.shares.join(', ') })));
    }
    row.appendChild(main);

    const right = el('div', 'bk-right');
    right.appendChild(levelPick('vm:' + v.name));
    if (l > 0) {
      const prep = selectInput(k('prepare'), ['freeze', 'pause', 'shutdown'], (o) => T('setup.vm_prep.' + o));
      prep.title = T('setup.vm_prep_hint');
      const warn = chip(T('setup.vm_noagent'), 'warn', T('setup.vm_noagent_hint'));
      const sync = () => { warn.hidden = !(prep.value === 'freeze' && (v.agent === 'no' || v.agent === 'none')); };
      prep.addEventListener('change', sync);
      sync();
      right.append(warn, prep);
      if (x.own) {
        // own retention: labelled, the placeholder shows what applies when it stays empty (the share's)
        const share = x.shares[0] || '';
        const input = textInput(k('retention'), /^\d+ \d+ \d+$/, dget(`share|${share}|retention`, '') || dget('zfs|retention', ''));
        input.classList.add('bk-ret');
        const ret = el('label', 'bk-ret-field');
        ret.append(el('span', 'role', T('setup.vm_ret_label')), input);
        ret.title = T('setup.vm_ret_hint', { share: share || '?' });
        right.appendChild(ret);
      }
    }
    row.appendChild(right);
    list.appendChild(row);
  });
  s.appendChild(list);
  return s;
}

/** Step 2: apps - a compose stack or a single container; databases and the shares they need come along */
function setupApps(plan) {
  const s = setupSection(T('setup.apps'), T('setup.apps_sub'));
  const m = setup.model;
  if (!m.apps.length && !m.skipped.length) { s.appendChild(el('p', 'empty', T('setup.apps_none'))); return s; }
  const shareOf = (n) => plan.shares.find((x) => x.name === n);
  const list = el('div', 'box');
  let head = null;
  m.apps.forEach((a) => {
    const kind = a.stack ? 'stacks' : 'single';
    if (head !== kind) { list.appendChild(el('div', 'bk-subhead', T('setup.apps_' + kind))); head = kind; }
    const l = levelOf('app:' + a.id);
    const row = el('div', 'row nocheck bk-app');
    row.dataset.focus = 'app:' + a.id;
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', a.name));
    const meta = el('div', 'row-meta');
    if (a.stack) meta.appendChild(el('span', '', a.members.join(', ')));
    else {
      const c = plan.containers.find((x) => x.name === a.name);
      if (c) meta.appendChild(el('span', '', c.running ? T('setup.ct_running') : T('setup.ct_stopped')));
    }
    a.dbs.forEach((d) => {
      if (d.dumpable) meta.appendChild(chip(T('setup.app_dump', { type: T('setup.dbtype.' + d.type) }), l > 0 ? 'ok' : '', T('setup.app_dump_hint')));
    });
    if (a.ncs.length) meta.appendChild(chip(T('setup.app_nc'), l > 0 ? 'ok' : '', T('setup.nc_text')));
    if (a.volumes.length) meta.appendChild(chip(T('setup.ct_volumes', { list: a.volumes.join(', ') }), 'danger', T('setup.ct_volumes_hint')));
    if (!a.folders.length && !a.deps.length) {
      const flashOff = dget('flash|mode', 'off') === 'off';
      meta.appendChild(chip(T('setup.app_nodata'), flashOff ? 'warn' : '', T(flashOff ? 'setup.app_nodata_noflash' : 'setup.app_nodata_hint')));
    }
    main.appendChild(meta);
    appDataWarnings(a, l, plan).forEach((w) => main.appendChild(w));
    row.appendChild(main);
    const right = el('div', 'bk-right');
    right.appendChild(levelPick('app:' + a.id));
    if (l > 0) {
      const hold = el('select', 'picker');
      hold.append(new Option(T('setup.app_hold.stop'), 'stop'), new Option(T('setup.app_hold.run'), 'run'));
      hold.value = setup.held[a.id] || 'stop';
      hold.title = T('setup.app_hold_hint');
      hold.onchange = () => { setup.held[a.id] = hold.value; setupDerive(); };
      right.appendChild(hold);
    }
    row.appendChild(right);
    list.appendChild(row);
    // the shares it uses besides its own folder: never ticked unasked - they can grow fast
    if (l > 0) {
      a.deps.forEach((d) => {
        const sh = shareOf(d.share);
        const key = `${a.id}|${d.share}`;
        const sub = el('div', 'row nocheck bk-dep');
        const size = sh && sh.gb !== null ? (sh.gb < 0 ? '> ?' : fmt.size(sh.gb * 1073741824)) : T('setup.app_dep_unknown');
        const where = d.paths.length ? T('setup.app_dep_paths', { paths: d.paths.join(', ') }) : '';
        const mode = dget(`share|${d.share}|mode`, 'off');
        const step3 = !setup.deps.has(key) && mode !== 'off' ? T('setup.app_dep_step3', { mode: T('setup.mode.' + mode) }) : '';
        sub.title = step3 ? T('setup.app_dep_step3_hint') : '';
        sub.appendChild(checkbox(T('setup.app_dep', { share: d.share }), setup.deps.has(key), (v) => {
          if (v) setup.deps.add(key); else setup.deps.delete(key);
          setupDerive();
          Office.keepInPlace(sub, () => renderSetup());
        }, [step3, size, where, T('setup.app_dep_hint')].filter(Boolean).join(' · ')));
        list.appendChild(sub);
      });
    }
  });
  m.skipped.forEach((c) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', c.name));
    // Kopia itself: only its template and the user's access data matter for a restore
    if (c.kopia) main.appendChild(el('div', 'row-meta', T('setup.app_kopia_text')));
    row.appendChild(main);
    row.appendChild(chip(T(c.kopia ? 'setup.ct_kopia' : 'setup.ct_office'), 'quiet'));
    list.appendChild(row);
  });
  s.appendChild(list);
  plan.missing_databases.forEach((md) => s.appendChild(el('p', 'callout warn', T('setup.db_missing', { stack: md.stack, service: md.service, type: md.type }))));
  return s;
}

/**
 * Nextcloud's data folder and Immich's UPLOAD_LOCATION: when their share is backed up less than the app,
 * database and files don't match after a restore. A visible warning - the share is never ticked unasked.
 */
function appDataWarnings(a, l, plan) {
  if (l < 1) return [];
  const max = dget('kopia|enabled') === 'yes' ? 2 : 1;
  const seen = new Set();
  const out = [];
  a.members.forEach((n) => {
    const c = plan.containers.find((x) => x.name === n);
    const d = c && c.data;
    if (!d || seen.has(`${d.share}|${d.path}`)) return;
    seen.add(`${d.share}|${d.path}`);
    const own = a.folders.some((f) => f.share === d.share && (d.path === f.dir || d.path.startsWith(f.dir + '/')));
    const cov = own ? l : Math.min(Math.max(0, LV.indexOf(dget(`share|${d.share}|mode`, 'off'))), max);
    if (cov >= l) return;
    const w = el('div', 'bk-warnline');
    w.append(chip(T('setup.data_chip.' + d.kind), 'warn'), ' ',
      T(cov === 0 ? 'setup.data_off' : 'setup.data_local', { what: T('setup.data_what.' + d.kind), share: d.share, path: d.path ? `${d.share}/${d.path}` : d.share }));
    out.push(w);
  });
  return out;
}

/** The backup place in a share — the engine's rule: the office's share has one folder per desk */
const OFFICE_SHARE = 'UnraidSecretaryOffice';
const dumpsPath = (share) => (share === OFFICE_SHARE ? `/mnt/user/${share}/backup` : `/mnt/user/${share}/unraid-backup`);

/** The backup place: its own share for the packages of apps and VMs — never appdata; required */
function dumpsShareField(plan) {
  const banned = ['appdata', 'system', 'domains'];
  const shares = plan.shares.map((x) => x.name).filter((n) => !banned.includes(n.toLowerCase()));
  const sel = el('select', 'picker');
  sel.appendChild(new Option(T('setup.ds_choose'), ''));
  shares.forEach((n) => sel.appendChild(new Option(n, n)));
  sel.value = dget('general|dumps_share', '') || '';
  const hint = el('small');
  const update = () => {
    const share = sel.value;
    hint.className = '';
    if (!share) { hint.textContent = T('setup.ds_missing'); hint.className = 'missing'; return; }
    const mode = dget(`share|${share}|mode`, 'off');
    if (mode === 'off') { hint.textContent = T('setup.ds_off', { share }); hint.className = 'missing'; return; }
    hint.textContent = T('setup.ds_ok', { path: dumpsPath(share) });
    if (setup.locks[share] && setup.locks[share].lv === 2) hint.textContent += ' · ' + T('setup.ds_kopia');
    // without snapshots the packages keep no history
    const sh = plan.shares.find((x) => x.name === share);
    if (sh && sh.method === 'live') { hint.textContent += ' · ' + T('setup.ds_no_history'); hint.className = 'missing'; }
  };
  sel.onchange = () => { dset('general|dumps_share', sel.value || undefined); setupDerive(); Office.keepInPlace(sel, () => renderSetup()); };
  update();
  const f = field(T('setup.ds_label'), sel);
  f.appendChild(hint);
  f.appendChild(el('small', '', T('setup.ds_why')));
  return f;
}

function setupGeneral(plan) {
  const s = setupSection(T('setup.general'), T('setup.general_sub'));
  const box = el('div', 'bk-form');
  const zfs = plan.bases.some((b) => b.fs === 'zfs');
  const btrfs = plan.bases.some((b) => b.fs === 'btrfs');
  if (zfs) box.appendChild(field(T('setup.g_prefix'), textInput('general|snap_prefix', /^[a-z0-9_]+-$/), T('setup.g_prefix_hint')));
  if (btrfs) {
    box.appendChild(field(T('setup.g_btrfs_free'), textInput('btrfs|min_free_gb', /^\d+$/), T('setup.g_btrfs_free_hint')));
    box.appendChild(checkbox(T('setup.g_btrfs_all'), dget('btrfs|snapshot_all') === 'yes', (v) => dset('btrfs|snapshot_all', v ? 'yes' : 'no')));
  }
  if (plan.P['libvirt|mode'] !== undefined) {
    box.appendChild(field(T('setup.g_libvirt'), selectInput('libvirt|mode', ['tar', 'off'], (o) => T('setup.libvirt.' + o)), T('setup.g_libvirt_hint')));
  }
  box.appendChild(checkbox(T('setup.g_notify'), dget('general|notify_success') === 'yes', (v) => dset('general|notify_success', v ? 'yes' : 'no')));
  if (dget('kopia|enabled') === 'yes') {
    box.appendChild(field(T('setup.p_compression'), textInput('kopia|compression', /^[a-z0-9-]+$/), T('setup.p_compression_hint')));
    box.appendChild(field(T('setup.p_ignore'), listInput('kopia|ignore', 4), T('setup.p_ignore_hint')));
  }
  s.appendChild(box);
  return s;
}

/** Step 4: two rows of numbers - how long local snapshots stay, how long Kopia keeps its states */
function setupRetention(plan) {
  const s = setupSection(T('setup.retention'), T('setup.retention_sub'));
  const box = el('div', 'bk-form');
  if (plan.bases.some((b) => b.fs === 'zfs')) box.appendChild(field(T('setup.g_zfs'), textInput('zfs|retention', /^\d+ \d+ \d+$/), T('setup.g_zfs_hint')));
  if (plan.bases.some((b) => b.fs === 'btrfs')) box.appendChild(field(T('setup.g_btrfs_days'), textInput('btrfs|keep_days', /^\d+$/)));
  s.appendChild(box);
  if (dget('kopia|enabled') === 'yes') {
    const keep = el('div', 'bk-keep');
    ['latest', 'hourly', 'daily', 'weekly', 'monthly', 'annual'].forEach((x) => {
      keep.appendChild(field(T('setup.p_' + x), textInput(`kopia|keep_${x}`, /^(\d+|inherit)$/)));
    });
    const f = field(T('setup.r_kopia'), keep, T('setup.r_kopia_hint'));
    const kb = el('div', 'bk-form');
    kb.appendChild(f);
    s.appendChild(kb);
    const items = setupItems(plan);
    if (items) s.appendChild(items);
  }
  s.appendChild(el('p', 'role', T('setup.r_exceptions')));
  return s;
}

/**
 * Apps and VMs at "local + Kopia": each a Kopia source of its own - how long Kopia keeps it, and what it
 * leaves out of it. The rules the office offers (caches, transcodes, logs, Immich's thumbnails) are never set unasked.
 */
function setupItems(plan) {
  const m = setup.model;
  const apps = m.apps.filter((a) => dget(`app|${a.name}|kopia`) === 'yes');
  const vms = m.vms.filter((x) => dget(`vm|${x.name}|kopia`) === 'yes');
  if (!apps.length && !vms.length) return null;
  const wrap = el('div', 'bk-items');
  wrap.appendChild(el('p', 'role bk-items-sub', T('setup.items_sub')));
  const list = el('div', 'box');
  list.appendChild(el('div', 'bk-subhead', T('setup.items')));
  const row = (kind, name, offers) => {
    const pre = `${kind}|${name}|`;
    const r = el('div', 'row nocheck bk-item');
    r.dataset.focus = `${kind}:${name}`;
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', name));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('setup.item_kind.' + kind)));
    const folders = dget(pre + 'folder', []) || [];
    meta.appendChild(el('span', 'mono', folders.length ? folders.join(', ') : T('setup.item_pkg_only')));
    main.appendChild(meta);
    // offered rules: in its own folders -> its own rule; in a share that goes to Kopia by itself -> that share's rule
    if (offers.length) {
      const sug = el('div', 'bk-chips');
      offers.forEach((o) => {
        const mine = folders.some((f) => `${o.share}/${o.path}` === f || `${o.share}/${o.path}`.startsWith(f + '/'));
        const shareKopia = dget(`share|${o.share}|mode`) === 'kopia';
        if (!mine && !shareKopia) return;
        const key = mine ? pre + 'kopia_ignore' : `share|${o.share}|kopia_ignore`;
        const rule = mine ? `/${o.share}/${o.path}/` : `/${o.path}/`;
        const have = (dget(key, []) || []).includes(rule);
        const b = button(`${o.share}/${o.path}/ · ${T('setup.offer.' + o.kind)}`, 'small ' + (have ? '' : 'plain'), () => {
          const now = [...(dget(key, []) || [])];
          const i = now.indexOf(rule);
          if (i >= 0) now.splice(i, 1); else now.push(rule);
          dset(key, now);
          Office.keepInPlace(b, () => renderSetup());
        });
        b.title = T(have ? 'setup.offer_on' : 'setup.offer_off') + ' ' + T('setup.offer_hint.' + (['thumbs', 'video'].includes(o.kind) ? 'immich' : 'cache'));
        sug.appendChild(b);
      });
      if (sug.childNodes.length) {
        sug.prepend(el('small', 'role', T('setup.offers')));
        main.appendChild(sug);
      }
    }
    r.appendChild(main);
    const right = el('div', 'bk-right');
    const ret = textInput(pre + 'kopia_retention', /^(\d+|inherit)( (\d+|inherit)){5}$/, T('setup.item_like_all'));
    ret.classList.add('bk-kret');
    ret.title = T('setup.item_ret_hint');
    const lab = el('label', 'bk-ret-field');
    lab.append(el('span', 'role', T('setup.item_ret')), ret);
    right.appendChild(lab);
    const open = setup.open.has(pre);
    right.appendChild(button(open ? T('setup.less') : T('setup.more'), 'small plain', () => {
      if (open) setup.open.delete(pre); else setup.open.add(pre);
      renderSetup();
    }));
    r.appendChild(right);
    list.appendChild(r);
    if (open) {
      const d = el('div', 'row nocheck bk-dep');
      const box = el('div', 'bk-form');
      box.appendChild(field(T('setup.item_ignore'), listInput(pre + 'kopia_ignore', 3), T('setup.item_ignore_hint', { example: `/${(folders[0] || 'appdata/' + name)}/cache/` })));
      d.appendChild(box);
      list.appendChild(d);
    }
  };
  apps.forEach((a) => row('app', a.name, a.members.flatMap((n) => ((plan.containers.find((c) => c.name === n) || {}).offers || []))));
  vms.forEach((x) => row('vm', x.name, []));
  wrap.appendChild(list);
  return wrap;
}

function setupSources(old) {
  const s = setupSection(T('setup.sources'), T('setup.sources_sub'));
  const ul = el('ul', 'shortlist');
  old.forEach((o) => ul.appendChild(el('li', '', o.source)));
  s.appendChild(ul);
  s.appendChild(checkbox(T('setup.sources_manual'), setup.retire, (v) => { setup.retire = v; }, T('setup.sources_manual_hint')));
  return s;
}

function setupMessages(msgs) {
  const s = section(T('setup.messages'), T('setup.messages_sub'));
  const det = el('details', 'bk-log');
  const counts = { error: 0, warn: 0 };
  (msgs || []).forEach((m) => { if (counts[m.level] !== undefined) counts[m.level]++; });
  det.appendChild(el('summary', '', T('setup.messages_sum', { n: (msgs || []).length, errors: counts.error, warnings: counts.warn })));
  const ul = el('ul', 'bk-msgs');
  (msgs || []).forEach((m) => {
    const li = el('li', m.level);
    li.append(el('span', 'bk-step', T('setup.step.' + (m.step || 'other'))), m.text);
    ul.appendChild(li);
  });
  det.appendChild(ul);
  if (counts.error || counts.warn) det.open = true;
  s.appendChild(det);
  return s;
}

// ------------------------------------------------------------------ desk
// ------------------------------------------------------------------ schedule
/** How long a run took lately (median of the last good runs), in seconds */
function typicalDuration() {
  const d = (state && state.history || []).filter((r) => r.finished && ['ok', 'warnings'].includes(r.result))
    .slice(0, 7).map((r) => r.finished - r.started).sort((a, b) => a - b);
  return d.length ? d[Math.floor(d.length / 2)] : 0;
}

/** When the nightly run starts: every night at a time, a cron expression of your own, or not at all */
function scheduleDialog() {
  const sc = (state && state.schedule) || {};
  if (!sc.script) {
    Office.dialog({
      title: T('schedule.title'),
      body: T(sc.via === 'office' ? 'schedule.no_settings' : 'schedule.no_script'),
      buttons: [{ text: Office.t('common.close') }, { text: T('setup_open'), kind: '', act: () => { Office.go(`#/${ID}/setup`); } }],
    });
    return;
  }
  const daily = /^(\d{1,2}) (\d{1,2}) \* \* \*$/.exec((sc.custom || '').trim());
  let mode = sc.enabled ? (daily ? 'daily' : 'custom') : 'daily';
  const pad = (n) => String(n).padStart(2, '0');
  const time = el('input', 'input');
  time.type = 'time';
  time.value = daily ? `${pad(daily[2])}:${pad(daily[1])}` : '03:00';
  const cron = el('input', 'input mono');
  cron.value = sc.custom || '0 3 * * *';
  cron.spellcheck = false;
  const ends = el('small');
  const box = el('div', 'bk-schedule');
  box.appendChild(el('p', '', T(sc.via === 'office' ? 'schedule.intro_plugin' : 'schedule.intro')));
  const option = (id, text, hint, extra) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'bk-schedule';
    input.checked = mode === id;
    input.onchange = () => { mode = id; update(); };
    const span = el('span', '', text);
    if (hint) span.appendChild(el('small', '', hint));
    label.append(input, span);
    box.appendChild(label);
    if (extra) { extra.classList.add('bk-schedule-field'); box.appendChild(extra); }
  };
  const took = typicalDuration();
  const dailyField = el('div');
  dailyField.append(time, ends);
  option('daily', T('schedule.daily'), null, dailyField);
  option('custom', T('schedule.custom'), T('schedule.custom_hint'), cron);
  option('off', T('schedule.off'), T('schedule.off_hint'));
  const update = () => {
    time.disabled = mode !== 'daily';
    cron.disabled = mode !== 'custom';
    ends.textContent = '';
    if (took && mode === 'daily' && /^\d\d:\d\d$/.test(time.value)) {
      const [h, m] = time.value.split(':').map(Number);
      const end = new Date(2000, 0, 1, h, m).getTime() / 1000 + took;
      ends.textContent = T('schedule.duration', { duration: fmt.duration(took), end: fmt.time(end) });
    }
  };
  time.oninput = update;
  update();
  Office.dialog({
    title: T('schedule.title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('schedule.save'), kind: '', act: async () => {
        let expr = '';
        if (mode === 'daily') {
          if (!/^\d\d:\d\d$/.test(time.value)) { Office.toast(T('schedule.need_time'), true); return false; }
          const [h, m] = time.value.split(':').map(Number);
          expr = `${m} ${h} * * *`;
        } else if (mode === 'custom') {
          expr = cron.value.trim();
        }
        const j = await Office.api.post(`${ID}.schedule`, { cron: expr });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.state) state = j.state;
        if (!j.live) Office.toast(T(sc.via === 'office' ? 'schedule.not_live_plugin' : 'schedule.not_live'), true);
        else Office.toast(expr ? T('schedule.saved_on', { when: fmt.cron(expr) }) : T('schedule.saved_off'));
        if (view && page === 'main') render();
        return true;
      } },
    ],
  });
}

Office.desk({
  id: ID,
  async mount(root, sub) {
    view = root;
    clearTimeout(setupTimer);
    page = sub === 'setup' ? 'setup' : 'main';
    if (page === 'setup') {
      renderSetup();
      if (!state) await load(false);
      await setupLoad();
      // an old plan doesn't know what changed since (new shares, moved ones): read the server again
      if (setup.plan && Date.now() / 1000 - setup.plan.time > SETUP_STALE && canPlan() && !(setup.status && setup.status.running)) {
        setupPlan(false, true);
      }
    } else {
      Office.selbar(null);
      render();
      await load(false);
      if (sub === 'schedule') { Office.subroute(''); scheduleDialog(); }    // the caretaker's "Open"
    }
  },
  unmount() {
    view = null;
    clearTimeout(liveTimer);
    clearTimeout(setupTimer);
  },
  poll() { if (page === 'main') load(false); },
  agentChanged() { if (view) (page === 'setup' ? renderSetup() : render()); },
  menu() {
    if (page === 'setup') {
      // writes the same settings again and aligns Kopia's policies — rarely needed, so not in the bar
      const ok = setup.plan && setup.draft && !(setup.status && setup.status.running) && Office.agent.running;
      return [{ text: T('setup.apply_again'), act: ok ? setupApply : null }];
    }
    const items = [{ text: T('menu.refresh'), act: () => load(true) }];
    if (state && state.found) {
      const ready = canAct() && !live();
      items.push({ text: T('schedule.open'), act: state.schedule && state.schedule.script && Office.agent.running ? scheduleDialog : null });
      items.push({ text: T('mode.check'), act: ready ? () => startRun('check') : null });
      items.push({ text: T('mode.dryrun'), act: ready ? () => startRun('dryrun') : null });
      if ((state.mounted || []).length) items.push({ text: T('unmount'), act: ready ? unmount : null });
      const latest = (state.logs || [])[0];
      if (latest) items.push({ separator: true }, { text: T('menu.latest_log'), act: () => showLog(latest.name, live()) });
    }
    return items;
  },
  async reception() {
    if (!state) await load(false);
    const facts = [];
    if (state && state.found) {
      const last = lastRun();
      if (last) facts.push(T('fact.last', { when: fmt.date(last.started, true), result: T('result.' + last.result) }));
      const shares = state.shares || [];
      const off = shares.filter((s) => s.mode === 'off').length;
      facts.push(T('fact.shares', { kopia: shares.filter((s) => s.mode === 'kopia').length, local: shares.filter((s) => s.mode === 'snapshot').length, off }));
      if (state.version) facts.push(T('fact.version', { version: state.version }));
    }
    return { bubble: bubbleText().join(' '), facts };
  },
});
})();
