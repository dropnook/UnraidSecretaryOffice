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
  ['snapshots', ['stopping', 'snapshots', 'starting']],
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

const lastRun = () => (state && state.history && state.history[0]) || null;
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
      out.push(T('bubble.running_kopia', { share: s.kopia.current, n: s.kopia.done.length + 1, total: s.kopia.planned.length }));
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
  if (!sc.script) callout(T('notice.no_user_script'), true);
  else if (!sc.enabled) callout(T('notice.schedule_off'), true, button(T('schedule.open'), 'small', scheduleDialog));
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

  if (p.percent !== null) {
    const bar = el('div', 'bar');
    const fill = el('i', 'snaps');
    fill.style.width = p.percent + '%';
    bar.appendChild(fill);
    card.appendChild(bar);
  }
  const line = el('div', 'card-line');
  if (p.eta) line.append(T(p.overdue ? 'eta_late' : 'eta', { time: fmt.time(p.eta), left: fmt.duration(p.eta - Date.now() / 1000) }));
  else line.append(T('eta_unknown'));
  if (s.downtime_s) line.append(' · ', T('downtime_was', { duration: fmt.duration(s.downtime_s) }));
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
      r.append(mark, el('span', 'bk-source-name', name));
      let info = '';
      if (d) info = d.ok ? dur(d.seconds) : T('failed');
      else if (current) info = dur(Date.now() / 1000 - s.kopia.current_since) + (est[name] ? ' / ~' + dur(est[name]) : '');
      else if (est[name]) info = '~' + dur(est[name]);
      r.appendChild(el('span', 'bk-source-info', info));
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
    if (k.length) stats.appendChild(stat(T('stat.kopia'), `${okCount} / ${k.length}`, okCount === k.length ? T('stat.kopia_all') : T('stat.kopia_missing', { n: k.length - okCount }), okCount !== k.length));
  } else {
    stats.appendChild(stat(T('stat.last'), '–', T('bubble.no_runs')));
  }
  const sc = state.schedule || {};
  const when = sc.enabled ? (sc.frequency === 'custom' ? fmt.cron(sc.custom) : T('freq.' + sc.frequency)) : T('stat.not_scheduled');
  const sst = stat(T('stat.schedule'), when, sc.enabled ? T('stat.user_scripts') : T('stat.schedule_hint'), !sc.enabled);
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
  box.appendChild(stats);
  return box;
}

/** Is everything protected? Every share with its mode and last offsite copy */
function protection() {
  const shares = state.shares || [];
  const counts = { kopia: 0, snapshot: 0, off: 0 };
  shares.forEach((s) => { counts[s.mode] = (counts[s.mode] || 0) + 1; });
  const kopiaOn = state.settings && state.settings.kopia_enabled;
  const local = counts.snapshot + (kopiaOn ? 0 : counts.kopia);
  const box = section(T('protection'), T('protection_sum', { kopia: kopiaOn ? counts.kopia : 0, snapshot: local, off: counts.off }));
  if (!shares.length) { box.appendChild(el('p', 'empty', T('no_shares'))); return box; }

  const wrap = el('div', 'box table-wrap');
  const table = el('table', 'grid');
  const thead = el('thead');
  const hr = el('tr');
  ['share', 'mode', 'last_copy', 'took', 'details'].forEach((k) => hr.appendChild(el('th', '', T('col.' + k))));
  thead.appendChild(hr);
  table.appendChild(thead);
  const body = el('tbody');
  const order = { kopia: 0, snapshot: 1, off: 2 };
  [...shares].sort((a, b) => (order[a.mode] - order[b.mode]) || a.name.localeCompare(b.name)).forEach((s) => {
    const tr = el('tr');
    tr.appendChild(el('th', '', s.flash ? T('flash') : s.name));
    const modeCell = el('td');
    // the same labels as everywhere in the office: offsite / only local / not backed up
    const level = s.mode === 'kopia' ? (kopiaOn ? 'offsite' : 'local') : s.mode === 'snapshot' ? 'local' : 'none';
    modeCell.appendChild(Office.backupChip(level));
    if (s.mode === 'kopia' && !kopiaOn) modeCell.append(' ', chip(T('mode_kopia_off'), 'warn'));
    if (s.method === 'live') modeCell.append(' ', chip(T('live'), 'warn', T('live_hint')));
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
    const d = el('td', '', details.join(' · '));
    if (s.ignores.length) d.title = s.ignores.join('\n');
    tr.appendChild(d);
    body.appendChild(tr);
  });
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
    main.append(el('div', 'row-name text', d.text), meta);
    row.appendChild(main);
    list.appendChild(row);
  });
  box.appendChild(list);
  const hint = el('p', 'role', T('drift_hint') + ' ');
  hint.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
  box.appendChild(hint);
  return box;
}

/** How to get things back: local snapshots, Kopia, database dumps */
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
  const snapLink = el('a', '', T('restore.snapshot_link'));
  snapLink.href = '#/snapshot';
  item(T('restore.local'), T('restore.local_zfs'), ' ', el('code', '', `/mnt/<pool>/<share>/.zfs/snapshot/${prefix}…/`),
    ' ', T('restore.local_btrfs'), ' ', el('code', '', `${set.view_root || '/mnt/btrfs-snap'}/<disk>/<…>/<share>/`),
    '. ', Office.desks.has('snapshot') ? snapLink : '');
  if (set.kopia_enabled) {
    item(T('restore.kopia'), T('restore.kopia_text', { container: set.kopia_container || 'kopia', root: set.mount_root || '/mnt/backup-snapshots' }));
  }
  box.appendChild(dl);
  const vmArchive = (state.dumps || []).find((d) => d.libvirt);
  if (vmArchive) box.appendChild(vmRestore(vmArchive));

  const dumps = state.dumps || [];
  const latest = dumps.find((d) => d.files.length);
  if (latest) {
    const list = el('div', 'box');
    latest.files.forEach((f) => {
      const row = el('div', 'row nocheck');
      const main = el('div', 'row-main');
      main.appendChild(el('div', 'row-name', f.name));
      const meta = el('div', 'row-meta');
      meta.append(el('span', '', fmt.size(f.bytes)), el('span', '', fmt.date(latest.time)));
      main.appendChild(meta);
      row.appendChild(main);
      const cmd = restoreCommand(latest.path, f.name);
      const b = button(T('restore.copy_command'), 'small plain', () => Office.copy(cmd));
      b.title = cmd;
      b.disabled = !cmd;
      row.appendChild(b);
      list.appendChild(row);
    });
    const head = el('p', 'role', T('restore.dumps', { n: dumps.filter((d) => d.files.length).length, path: latest.path }));
    box.append(head, list, el('p', 'role', T('restore.dumps_hint')));
  }
  return box;
}

/**
 * Getting VMs back from libvirt.tar.gz: everything at once into libvirt.img
 * (lost image, new server), or a single VM next to the others.
 */
function vmRestore(d) {
  const box = el('div', 'bk-vm-restore');
  const img = (state.settings && state.settings.libvirt_img) || '/mnt/user/system/libvirt/libvirt.img';
  const a = d.libvirt;
  box.appendChild(el('h3', '', T('restore.vms')));
  box.appendChild(el('p', 'role', T('restore.vms_text', { when: fmt.date(d.time), size: fmt.size(d.libvirt_bytes || 0), n: d.libvirt_vms.length, file: a })));

  const step = (title, text, cmd) => {
    const s = el('div', 'bk-rstep');
    s.appendChild(el('div', 'bk-rstep-title', title));
    if (text) s.appendChild(el('div', 'role', text));
    if (cmd) s.appendChild(codeBlock(cmd));
    return s;
  };
  const all = el('details', 'bk-how');
  all.appendChild(el('summary', '', T('restore.vm_all')));
  all.append(
    step(T('restore.vm_all_1'), T('restore.vm_all_1_text')),
    step(T('restore.vm_all_2'), null, `mkdir -p /tmp/libvirt-img && mount -o loop '${img}' /tmp/libvirt-img && tar -xzf '${a}' -C /tmp/libvirt-img --strip-components=1 && umount /tmp/libvirt-img`),
    step(T('restore.vm_all_3'), T('restore.vm_all_3_text')),
  );
  box.appendChild(all);

  if (d.libvirt_vms.length) {
    const one = el('details', 'bk-how');
    one.appendChild(el('summary', '', T('restore.vm_one')));
    const sel = el('select', 'picker');
    d.libvirt_vms.forEach((v) => sel.appendChild(new Option(v.name, v.name)));
    const out = el('div');
    const show = () => {
      const v = d.libvirt_vms.find((x) => x.name === sel.value);
      out.innerHTML = '';
      const t = '/tmp/libvirt-restore/libvirt/qemu';
      const cmds = [`mkdir -p /tmp/libvirt-restore && tar -xzf '${a}' -C /tmp/libvirt-restore`];
      v.nvram.filter((n) => !/S\d{14}_VARS/.test(n)).forEach((n) => cmds.push(`cp -a '${t}/nvram/${n}' /etc/libvirt/qemu/nvram/`));
      if (v.tpm) cmds.push(`mkdir -p /etc/libvirt/qemu/swtpm/tpm-states && cp -a '${t}/swtpm/tpm-states/${v.uuid}' /etc/libvirt/qemu/swtpm/tpm-states/`);
      cmds.push(`virsh define '${t}/${v.name}.xml'`);
      if (v.autostart) cmds.push(`virsh autostart '${v.name}'`);
      out.append(
        el('p', 'role', T('restore.vm_one_text', { name: v.name })),
        codeBlock(cmds.join('\n')),
        el('p', 'role', [T('restore.vm_one_after'), v.tpm ? T('restore.vm_one_tpm') : ''].filter(Boolean).join(' ')),
      );
    };
    sel.onchange = show;
    one.append(field(T('restore.vm_pick'), sel), out);
    show();
    box.appendChild(one);
  }
  return box;
}

/** A block of commands with a copy button */
function codeBlock(text) {
  const wrap = el('div', 'bk-code');
  wrap.appendChild(el('pre', 'code', text));
  const b = button(Office.t('common.copy'), 'small plain', () => Office.copy(text));
  wrap.appendChild(b);
  return wrap;
}

function copyCode(text) {
  const c = el('code', 'bk-copy', text);
  c.title = Office.t('common.copy');
  c.onclick = () => Office.copy(text);
  return c;
}

function restoreCommand(dir, file) {
  let m = file.match(/^mariadb_(.+)_[^_]+\.sql\.gz$/);
  if (m) return `zcat '${dir}/db/${file}' | docker exec -i ${m[1]} sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'`;
  m = file.match(/^postgres_(.+)\.sql\.gz$/);
  if (m) return `zcat '${dir}/db/${file}' | docker exec -i ${m[1]} psql -U postgres -d postgres`;
  m = file.match(/^mongodb_(.+)\.archive\.gz$/);
  if (m) return `docker exec -i ${m[1]} sh -c 'mongorestore --drop --archive --gzip -u "$MONGO_INITDB_ROOT_USERNAME" -p "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin' < '${dir}/db/${file}'`;
  return '';
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
const LIST_KEY = /\|(ignore|no_stop|known|kopia_ignore|exclude_dataset|tar_exclude)$/;
let setup = { plan: null, draft: null, status: null, run: null, applied: null, open: new Set(), retire: true, asked: false };
let setupTimer = null;

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const clone = (o) => JSON.parse(JSON.stringify(o || {}));
const dget = (k, d) => (setup.draft[k] !== undefined ? setup.draft[k] : d);
function dset(k, v) {
  if (v === undefined || v === null) delete setup.draft[k];
  else setup.draft[k] = v;
  setupBar();
}

/** Every key that differs between the plan and the draft */
function setupChanges() {
  const out = [];
  const keys = new Set([...Object.keys(setup.plan.P), ...Object.keys(setup.draft)]);
  keys.forEach((k) => { if (!same(setup.plan.P[k], setup.draft[k])) out.push(k); });
  return out.sort();
}

async function setupLoad() {
  const j = await Office.api.post(`${ID}.setup_get`, {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  const was = setup.status;
  setup.status = j.status;
  setup.run = j.run;
  if (j.plan && (!setup.plan || j.plan.time !== setup.plan.time)) {
    setup.plan = j.plan;
    setup.draft = clone(j.plan.P);
  }
  const finishedApply = was && was.running && was.mode === 'apply' && !j.status.running;
  if (finishedApply) {
    setup.applied = j.run;
    if (j.run && j.run.result === 'ok') {
      Office.toast(T('setup.applied'));
      load(true);
      await setupPlan(false, true);          // look again: the plan should now show no changes
      return;
    }
  }
  if (page === 'setup') renderSetup();
  clearTimeout(setupTimer);
  if (j.status.running) setupTimer = setTimeout(setupLoad, SETUP_POLL);
  else if (!setup.plan && !setup.asked && canPlan()) setupPlan(false);
}

const canPlan = () => !!(state && state.found && state.compatible && Office.agent.running && !state.running);

async function setupPlan(measure, quiet) {
  setup.asked = true;
  const j = await Office.api.post(`${ID}.setup_plan`, { measure: !!measure });
  if (!j.ok) { if (!quiet) Office.toast(Office.errorText(j.error, ID), true); return; }
  setTimeout(setupLoad, 500);
}

function setupApply() {
  const ds = dget('general|dumps_share', '');
  if (!ds) { Office.toast(T('setup.ds_missing'), true); return; }
  if (dget(`share|${ds}|mode`, 'off') === 'off') { Office.toast(T('setup.ds_off', { share: ds }), true); return; }
  const changes = setupChanges();
  const pending = setup.plan.pending || [];
  const box = el('div');
  box.appendChild(el('p', '', changes.length + pending.length ? T('setup.apply_text', { n: changes.length + pending.length }) : T('setup.apply_none')));
  if (pending.length) {
    // what my proposal changes against the saved settings.ini, line by line
    box.appendChild(el('div', 'field-title', T('setup.pending_title')));
    const ul = el('ul', 'shortlist');
    pending.forEach((x) => {
      const li = el('li', '', `${x.op === '+' ? '+' : '−'} ${x.line}`);
      li.appendChild(el('span', '', `[${x.section}]`));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }
  if (changes.length) {
    if (pending.length) box.appendChild(el('div', 'field-title', T('setup.edits_title')));
    const ul = el('ul', 'shortlist');
    changes.slice(0, 60).forEach((k) => {
      const li = el('li', '', changeLabel(k));
      li.appendChild(el('span', '', `${valueText(setup.plan.P[k])} → ${valueText(setup.draft[k])}`));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }
  box.appendChild(el('p', 'role', T('setup.apply_hint')));
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
  if (p[0] === 'dump') return T('setup.ch_dump', { name: p[1] });
  if (p[0] === 'nextcloud') return T('setup.ch_nextcloud', { name: p[1] });
  return Office.has(`${ID}.setup.key.${p.join('_')}`) ? T('setup.key.' + p.join('_')) : k;
}
function valueText(v) {
  if (v === undefined || v === null) return '–';
  if (Array.isArray(v)) return v.length ? v.join(', ') : '–';
  return v === '' ? '–' : v;
}

/** The bar at the bottom: how many changes, apply */
function setupBar() {
  if (page !== 'setup' || !setup.plan || !setup.draft) { Office.selbar(null); return; }
  const edits = setupChanges().length;
  const n = edits + (setup.plan.pending || []).length;   // your edits + what Apply changes in settings.ini anyway
  const busy = setup.status && setup.status.running;
  if (!n) { Office.selbar(null); return; }      // nothing to apply: no bar that keeps offering it
  Office.selbar({
    title: T('setup.bar_changes', { n }),
    sub: T('setup.bar_sub', { when: fmt.relative(setup.plan.time) }),
    buttons: [
      { text: T('setup.discard'), kind: 'plain', disabled: !edits || busy, act: () => { setup.draft = clone(setup.plan.P); renderSetup(); } },
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
  const busy = setup.status && setup.status.running;
  [again, measure].forEach((b) => { b.disabled = busy || !canPlan(); });
  measure.title = T('setup.measure_hint');
  let bubble = T('setup.bubble_loading');
  if (busy) bubble = T(setup.status.mode === 'apply' ? 'setup.bubble_applying' : 'setup.bubble_planning');
  else if (setup.plan && Date.now() / 1000 - setup.plan.time > SETUP_STALE) bubble = T('setup.bubble_old', { when: fmt.relative(setup.plan.time) });
  else if (setup.plan) bubble = T(setup.plan.have_settings ? 'setup.bubble_have' : 'setup.bubble_new');
  else if (state && state.running) bubble = T('setup.bubble_backup_runs');
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble, actions: [back, again, measure] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('help.draft'), T('help.draft_text')],
    [T('setup.replan'), T('help.replan')],
    [T('setup.measure'), T('setup.measure_hint')],
    [T('help.reasons'), T('help.reasons_text')],
    [T('setup.more'), T('help.details')],
    [T('setup.apply'), T('help.apply')],
  ]));

  if (setup.applied) root.appendChild(appliedCard(setup.applied));
  if (busy) {
    const p = el('p', 'callout running');
    p.append(el('span', 'spin'), ' ', T(setup.status.mode === 'apply' ? 'setup.applying_long' : 'setup.planning_long'));
    root.appendChild(p);
  }
  if (!setup.plan) { setupBar(); return; }

  const plan = setup.plan;
  root.appendChild(setupKopia(plan));
  root.appendChild(setupShares(plan));
  root.appendChild(setupContainers(plan));
  root.appendChild(setupDatabases(plan));
  root.appendChild(setupGeneral(plan));
  if (dget('kopia|enabled') === 'yes') root.appendChild(setupPolicies());
  const old = (plan.kopia.sources || []).filter((s) => s.state === 'orphan' || s.state === 'gone');
  if (old.length) root.appendChild(setupSources(old));
  root.appendChild(setupMessages(plan.messages));
  setupBar();
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
  const on = dget('kopia|enabled') === 'yes';
  s.appendChild(checkbox(T('setup.kopia_on'), on, (v) => {
    dset('kopia|enabled', v ? 'yes' : 'no');
    if (!v) Object.keys(setup.draft).forEach((key) => { if (/^share\|.+\|mode$/.test(key) && setup.draft[key] === 'kopia') setup.draft[key] = 'snapshot'; });
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
    tr.appendChild(el('th', '', sh.name));
    tr.appendChild(el('td', '', sh.where === '-' ? '' : sh.where));
    tr.appendChild(el('td', 'num', sh.gb === null ? '' : sh.gb < 0 ? '> ?' : fmt.size(sh.gb * 1073741824)));
    const modeCell = el('td');
    if (sh.exists) {
      const opts = kopiaOn ? ['kopia', 'snapshot', 'off'] : ['snapshot', 'off'];
      const sel = selectInput(`share|${sh.name}|mode`, opts, (o) => T('setup.mode.' + o));
      if (!opts.includes(sel.value)) sel.value = 'snapshot';
      sel.onchange = () => { dset(`share|${sh.name}|mode`, sel.value); };
      modeCell.appendChild(sel);
    } else {
      modeCell.appendChild(chip(T('setup.gone'), 'warn'));
    }
    tr.appendChild(modeCell);
    const why = el('td', 'bk-why', shareWhy(sh));
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
    if (sh.folders.length) {
      const sug = el('div', 'bk-chips');
      sug.appendChild(el('small', 'role', T('setup.f_folders')));
      const seen = new Set();
      sh.folders.forEach((f) => {
        if (seen.has(f.dir)) return;
        seen.add(f.dir);
        const rule = `/${f.dir}/`;
        const have = (dget(k('kopia_ignore'), []) || []).includes(rule);
        const c = button(`${rule} · ${f.container}`, 'small ' + (have ? '' : 'plain'), () => {
          const list = [...(dget(k('kopia_ignore'), []) || [])];
          const i = list.indexOf(rule);
          if (i >= 0) list.splice(i, 1); else list.push(rule);
          dset(k('kopia_ignore'), list);
          renderSetup();
        });
        c.title = T(have ? 'setup.f_folder_on' : 'setup.f_folder_off');
        sug.appendChild(c);
      });
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

function setupContainers(plan) {
  const s = setupSection(T('setup.containers'), T('setup.containers_sub'));
  const list = el('div', 'box');
  const nostop = () => dget('docker|no_stop', []) || [];
  plan.containers.forEach((c) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', c.name));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', c.running ? T('setup.ct_running') : T('setup.ct_stopped')));
    meta.appendChild(el('span', '', T('setup.ctwhy.' + (c.why || 'no_data'), { arg: c.why_arg })));
    if (c.volumes.length && !c.kopia) meta.appendChild(chip(T('setup.ct_volumes', { list: c.volumes.join(', ') }), 'danger', T('setup.ct_volumes_hint')));
    main.appendChild(meta);
    row.appendChild(main);
    if (c.kopia) {
      row.appendChild(chip(T('setup.ct_kopia'), 'quiet'));
    } else if (c.why === 'office') {
      row.appendChild(chip(T('setup.ct_office'), 'quiet'));     // the office never stops itself
    } else {
      const keep = nostop().includes(c.name);
      const sel = el('select', 'picker');
      sel.append(new Option(T('setup.ct_stop'), 'stop'), new Option(T('setup.ct_keep'), 'keep'));
      sel.value = keep ? 'keep' : 'stop';
      sel.onchange = () => {
        const now = nostop().filter((x) => x !== c.name);
        if (sel.value === 'keep') now.push(c.name);
        dset('docker|no_stop', now);
        warn.hidden = !(sel.value === 'keep' && /^(writes|volumes|binds_root|media_server)$/.test(c.why));
      };
      const warn = chip(T('setup.ct_risk'), 'warn', T('setup.ct_risk_hint'));
      warn.hidden = !(keep && /^(writes|volumes|binds_root|media_server)$/.test(c.why));
      const right = el('div', 'bk-right');
      right.append(warn, sel);
      row.appendChild(right);
    }
    list.appendChild(row);
  });
  s.appendChild(list);
  return s;
}

function setupDatabases(plan) {
  const s = setupSection(T('setup.databases'), T('setup.databases_sub'));
  if (!plan.databases.length && !plan.nextcloud.length && !plan.missing_databases.length) {
    s.appendChild(el('p', 'role', T('setup.no_databases')));
    return s;
  }
  const list = el('div', 'box');
  plan.databases.forEach((d) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', d.container));
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(T('setup.dbtype.' + d.type), d.type === 'cache' ? 'quiet' : ''));
    if (d.stack !== '-') meta.appendChild(el('span', '', d.stack));
    meta.appendChild(el('span', d.where.includes('!') ? 'bk-bad' : '', T('setup.db_where', { where: d.where.replace('im Container!', T('setup.db_inside')) })));
    main.appendChild(meta);
    row.appendChild(main);
    if (d.dumpable) {
      const key = `dump|${d.container}|type`;
      row.appendChild(checkbox(T('setup.db_dump'), dget(key) !== undefined, (v) => dset(key, v ? d.type : undefined)));
    } else {
      row.appendChild(el('span', 'role', T(d.type === 'cache' ? 'setup.db_cache' : 'setup.db_snapshot')));
    }
    list.appendChild(row);
  });
  plan.nextcloud.forEach((n) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', `Nextcloud: ${n.members.join(' + ')}`));
    main.appendChild(el('div', 'row-meta', T('setup.nc_text')));
    row.appendChild(main);
    const keys = n.members.map((m) => `nextcloud|${m}|preexisting_maintenance`);
    const on = keys.some((k) => dget(k) !== undefined);
    const right = el('div', 'bk-right');
    const pre = el('select', 'picker');
    pre.append(new Option(T('setup.nc_abort'), 'abort'), new Option(T('setup.nc_continue'), 'continue'));
    pre.value = keys.map((k) => dget(k)).find((v) => v) || n.preexisting || 'abort';
    pre.disabled = !on;
    pre.title = T('setup.nc_pre_hint');
    pre.onchange = () => keys.forEach((k) => dset(k, pre.value));
    right.append(checkbox(T('setup.nc_on'), on, (v) => { keys.forEach((k) => dset(k, v ? pre.value : undefined)); pre.disabled = !v; }), pre);
    row.appendChild(right);
    list.appendChild(row);
  });
  s.appendChild(list);
  plan.missing_databases.forEach((m) => s.appendChild(el('p', 'callout warn', T('setup.db_missing', { stack: m.stack, service: m.service, type: m.type }))));
  return s;
}

/** The backup place: its own share for dumps, archives and the manifest — never appdata; required */
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
    const kopia = dget('kopia|enabled') === 'yes';
    hint.textContent = kopia && mode !== 'kopia' ? T('setup.ds_local', { share }) : T('setup.ds_ok', { path: `/mnt/user/${share}/unraid-backup` });
    if (kopia && mode !== 'kopia') hint.className = 'missing';
  };
  sel.onchange = () => { dset('general|dumps_share', sel.value || undefined); update(); };
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
  if (zfs) {
    box.appendChild(field(T('setup.g_zfs'), textInput('zfs|retention', /^\d+ \d+ \d+$/), T('setup.g_zfs_hint')));
    box.appendChild(field(T('setup.g_prefix'), textInput('general|snap_prefix', /^[a-z0-9_]+-$/), T('setup.g_prefix_hint')));
  }
  if (btrfs) {
    box.appendChild(field(T('setup.g_btrfs_days'), textInput('btrfs|keep_days', /^\d+$/)));
    box.appendChild(field(T('setup.g_btrfs_free'), textInput('btrfs|min_free_gb', /^\d+$/), T('setup.g_btrfs_free_hint')));
    box.appendChild(checkbox(T('setup.g_btrfs_all'), dget('btrfs|snapshot_all') === 'yes', (v) => dset('btrfs|snapshot_all', v ? 'yes' : 'no')));
  }
  box.appendChild(dumpsShareField(plan));
  box.appendChild(field(T('setup.g_keep_runs'), textInput('general|keep_runs', /^\d+$/), T('setup.g_keep_runs_hint')));
  if (plan.P['libvirt|mode'] !== undefined) {
    box.appendChild(field(T('setup.g_libvirt'), selectInput('libvirt|mode', ['tar', 'off'], (o) => T('setup.libvirt.' + o)), T('setup.g_libvirt_hint')));
  }
  const flashOpts = plan.flash.dataset ? ['snapshot', 'tar', 'off'] : ['tar', 'off'];
  box.appendChild(field(T('setup.g_flash'), selectInput('flash|mode', flashOpts, (o) => T('setup.flash.' + o)),
    plan.flash.dataset ? T('setup.g_flash_zfs', { ds: plan.flash.dataset }) : T('setup.g_flash_other', { fs: plan.flash.fs || '?' })));
  box.appendChild(checkbox(T('setup.g_notify'), dget('general|notify_success') === 'yes', (v) => dset('general|notify_success', v ? 'yes' : 'no')));
  s.appendChild(box);
  return s;
}

function setupPolicies() {
  const s = setupSection(T('setup.policies'), T('setup.policies_sub'));
  const box = el('div', 'bk-form');
  const keep = el('div', 'bk-keep');
  ['latest', 'hourly', 'daily', 'weekly', 'monthly', 'annual'].forEach((x) => {
    keep.appendChild(field(T('setup.p_' + x), textInput(`kopia|keep_${x}`, /^(\d+|inherit)$/)));
  });
  box.appendChild(keep);
  box.appendChild(field(T('setup.p_compression'), textInput('kopia|compression', /^[a-z0-9-]+$/), T('setup.p_compression_hint')));
  box.appendChild(field(T('setup.p_ignore'), listInput('kopia|ignore', 4), T('setup.p_ignore_hint')));
  s.appendChild(box);
  return s;
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
      body: T('schedule.no_script'),
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
  box.appendChild(el('p', '', T('schedule.intro')));
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
        if (!j.live) Office.toast(T('schedule.not_live'), true);
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
