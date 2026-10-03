/* Mr. Backup — runs the unraid-backup script: what it is doing right now and
   when it will be done, how the last nights went, which shares are protected
   how, what changed since the setup, and how to get things back.
   The agent part lives in agent/desks/backup.php, the engine in backup/. */
(() => {
'use strict';

const ID = 'backup';
const T = Office.scope(ID);
const { el, fmt } = Office;
const LIVE_POLL = 5000;

// phases of a run (status.json "phase") grouped into the steps the desk shows
const STEPS = [
  ['prepare', ['start', 'inventory']],
  ['dumps', ['maintenance', 'dumps', 'manifest']],
  ['snapshots', ['stopping', 'snapshots', 'starting']],
  ['kopia', ['mounting', 'kopia']],
  ['finish', ['unmounting', 'cleanup', 'aborting', 'done']],
];

let state = null;
let view = null;
let liveTimer = null;

// ------------------------------------------------------------------ loading
async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  if (view) render();
  schedule();
  return j;
}

/** While a run is going on, look every few seconds */
function schedule() {
  clearTimeout(liveTimer);
  if (view && state && state.running) {
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

function section(title, ...extra) {
  const s = el('section', 'section');
  const head = el('div', 'section-head');
  head.appendChild(el('h2', '', title));
  extra.filter(Boolean).forEach((x) => head.appendChild(x));
  s.appendChild(head);
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
    else out.push(T('bubble.last_' + last.result, { when, errors: last.errors, warnings: last.warnings }));
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
      actions.push(button(T('check'), 'plain', () => startRun('check')));
      actions.push(button(T('start'), '', chooseRun));
    }
    actions.forEach((b) => { b.disabled = !canAct(); });
  }
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: bubbleText().join(' '), actions });
  root.appendChild(head);

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
  if (!state.settings_found) callout(T('notice.no_settings'), true, el('code', '', `${state.dir}/setup.sh`));
  const sc = state.schedule || {};
  if (!sc.script) callout(T('notice.no_user_script'), true);
  else if (!sc.enabled) callout(T('notice.schedule_off'), true);
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
  const box = section(T('now'));
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
  const box = section(T('overview'));
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
  stats.appendChild(stat(T('stat.schedule'), when, sc.enabled ? T('stat.user_scripts') : T('stat.schedule_hint'), !sc.enabled));
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
  const box = section(T('protection'));
  box.appendChild(el('p', 'role', T('protection_sum', { kopia: counts.kopia, snapshot: counts.snapshot, off: counts.off })));
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
    if (s.mode === 'kopia') modeCell.appendChild(chip(kopiaOn ? T('mode_kopia') : T('mode_kopia_off'), kopiaOn ? 'solid' : 'warn'));
    else if (s.mode === 'snapshot') modeCell.appendChild(chip(T('mode_snapshot'), 'outline'));
    else modeCell.appendChild(chip(T('mode_off'), 'quiet'));
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
  const box = section(T('history'));
  if (!runs.length) { box.appendChild(el('p', 'empty', T('bubble.no_runs'))); return box; }
  const list = el('div', 'box');
  const longest = Math.max(...runs.map((r) => (r.finished || r.started) - r.started), 1);
  runs.slice(0, 20).forEach((r) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    const name = el('div', 'row-name text link', fmt.date(r.started, true));
    name.onclick = () => showLog(r.log);
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
  const box = section(T('drift'), el('span', 'role', state.drift.time ? T('drift_checked', { when: fmt.relative(state.drift.time) }) : ''));
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
  hint.appendChild(el('code', '', `${state.dir}/setup.sh`));
  box.appendChild(hint);
  return box;
}

/** How to get things back: local snapshots, Kopia, database dumps */
function restoreSection() {
  const box = section(T('restore'));
  const set = state.settings || {};
  const prefix = set.snap_prefix || 'ub-';
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

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root) {
    view = root;
    render();
    await load(false);
  },
  unmount() {
    view = null;
    clearTimeout(liveTimer);
  },
  poll() { load(false); },
  agentChanged() { if (view) render(); },
  menu() {
    const items = [{ text: T('menu.refresh'), act: () => load(true) }];
    if (state && state.found) {
      const ready = canAct() && !live();
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
