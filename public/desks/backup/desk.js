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
const SETUP_STALE = 600;          // seconds: an older plan gets a warning on the setup page
const SETUP_REPLAN = 60;          // seconds: an older plan is read again when the setup page opens (a share made meanwhile)
const KOPIA_ROOT_EXAMPLE = '/uso'; // the Kopia container path new setups are shown, while the real one isn't known

// phases of a run (status.json "phase") grouped into the steps the desk shows
const STEPS = [
  ['prepare', ['start', 'inventory']],
  ['dumps', ['vm_shutdown', 'maintenance', 'manifest', 'stopping_apps', 'dumps']],     // vm_shutdown: engine 2.22, before anything stops
  ['snapshots', ['stopping', 'vms', 'snapshots', 'starting']],
  ['partner', ['partner']],                                // engine 2.27: to the partner offices, before Kopia (shown only in a run that has partners)
  ['kopia', ['mounting', 'kopia']],
  ['finish', ['unmounting', 'cleanup', 'aborting', 'done']],
];

const KOPIA_STEP = STEPS.findIndex(([n]) => n === 'kopia');

let state = null;
let view = null;
let page = 'main';             // 'main' or 'setup' (#/backup/setup)
let liveTimer = null;

// ------------------------------------------------------------------ loading
/** His state: as kept at once, a new look following on his page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view && page === 'main') render();
  schedule();
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

const RESULT_CHIP = { ok: 'ok', warnings: 'warn', errors: 'danger', failed: 'danger', aborted: 'warn', running: 'accent', skipped: 'warn' };
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
/** A unit that goes to a partner office (engine 2.27): the backup place, share:<name>, vm:<name> */
function unitLabel(u) {
  if (u === 'place') return T('partner.unit_place');
  const m = /^(share|vm):(.*)$/.exec(u || '');
  return m ? (m[1] === 'vm' ? T('src.vm', { name: m[2] }) : m[2]) : (u || '');
}
/** A partner's name: as the run names it (status.json partner.partners), else as settings.ini does (state.partners) */
function partnerName(id, run) {
  const p = ((run && run.partners) || []).find((x) => x.id === id) || ((state && state.partners) || []).find((x) => x.id === id);
  return p ? p.name : id;
}
/** Why a unit didn't go to a partner, in a few words (the engine's code when no text knows it) */
const partnerWhyShort = (w) => (Office.has(`${ID}.partner.why_short.${w}`) ? T('partner.why_short.' + w) : w);
/** How many units a run's partner phase has done with so far (sent, skipped, failed) */
const partnerDoneCount = (p) => ((p && p.done) || []).length + ((p && p.skipped) || []).length + ((p && p.failed) || []).length;

/** The own Kopia source of an app or VM (state.items), if it has one */
const itemOf = (kind, name) => (state && state.items || []).find((i) => i.kind === kind && i.name === name) || null;
/** A chip for an app's or VM's own Kopia source: when Kopia last had it */
function itemChip(it) {
  const l = it.last;
  const text = !l ? T('item.chip_never') : l.ok ? T('item.chip', { when: fmt.relative(l.time) }) : T('item.chip_failed');
  return chip(text, l && !l.ok ? 'danger' : 'ok', T('item.chip_hint', { path: it.path || '.' + it.kind + 's/' + it.name }));
}

const lastRun = () => (state && state.history && state.history[0]) || null;
/** A run that ended because the array was being stopped (engine 2.24): stopped on purpose, nothing lost — never a failure */
const arrayStopped = (r) => !!(r && r.result === 'aborted' && r.message === 'array_stopping');
/** How many shares a run left out because their pool slept (engine 2.28, asleep_pools = skip) */
const asleepUnits = (r) => (r && r.asleep && r.asleep.units) || 0;
/**
 * The newest backup run that was skipped because the lock was busy (engine 2.20: state.skips) — as long
 * as no run finished or started after it. Skips are no runs: history, «last run» and estimates never see them.
 */
function newSkip() {
  const k = ((state && state.skips) || [])[0];
  if (!k) return null;
  const last = lastRun();
  if (last && (last.finished || last.started) >= k.time) return null;
  const s = status();
  if (live() && s && s.started > k.time) return null;
  return k;
}
/** Why a run was skipped, in words: which run, and who had the lock (holder from lock-holder.json, its phase from status.json) */
function skipText(k) {
  const h = k.holder || {};
  let why = 'skipped.why_' + (['backup', 'check', 'dryrun', 'setup', 'restore'].includes(h.kind) ? h.kind : 'other');
  if (h.kind === 'backup' && h.phase === 'kopia' && h.current) why = 'skipped.why_backup_kopia';
  if (h.kind === 'restore' && h.what) why = 'skipped.why_restore_what';
  const text = T(why, { since: h.started ? fmt.date(h.started, true) : '…', source: srcLabel(h.current || ''), what: h.what || '' });
  return T('skipped.frame_' + (['check', 'dryrun'].includes(k.mode) ? k.mode : 'backup'), { when: fmt.date(k.time, true), why: text });
}
/** The packages in the backup place (engine 2.18+), null before the first run that wrote some */
const packages = () => {
  const pk = state && state.packages;
  return pk && pk.found && (pk.apps.length || pk.vms.length || pk.server) ? pk : null;
};
const status = () => (state && state.status) || null;
const live = () => !!(state && state.running);
const canAct = () => !!(state && state.found && state.compatible && Office.agent.running);
/** Mr. Restori holds the engine's lock (a restore of his): no run, check or setup meanwhile — the agent refuses too */
const restoring = () => !!(state && state.holder && state.holder.holder === 'restore');
/**
 * The kind of run going on: backup, check (the «Tour» button) or dryrun — the lock's note first (status.json may
 * still be the last run's for a moment), then status.json; anything else is a run
 */
function runMode() {
  const h = state && state.holder;
  if (h && ['backup', 'check', 'dryrun'].includes(h.holder)) return h.holder;
  const s = status();
  return s && s.result === 'running' && ['check', 'dryrun'].includes(s.mode) ? s.mode : 'backup';
}
/** A stop text named by that kind: «Stop the tour» for a check, «Stop the dry run», a backup keeps «Stop the run» */
const stopKey = (key, mode = runMode()) => (mode === 'backup' ? key : `${key}_${mode}`);

/**
 * The Kopia source going up for the first time right now (agent: backupUpload()) — its size, what
 * Kopia read so far, the rate; null for any other source. Its seconds left as of now (measured at u.time).
 */
function uploadNow() {             // not firstUpload(): that name is the setup's reckoning below, and a second declaration wins
  const s = status();
  const u = state && state.upload;
  return u && u.first && live() && s && s.kopia && s.kopia.current === u.source ? u : null;
}
const firstLeft = (u) => (u.left === null || u.left === undefined ? null : Math.max(0, u.left - (Date.now() / 1000 - u.time)));

/**
 * A first upload in words: Mr. Backupsy's bubble (bubble.first_*) or the run card's plain line (first.*).
 * The rate is what Kopia reads (rchar); what it really sent so far (wchar) follows on its own.
 */
function firstText(u, bubble) {
  const pre = bubble ? 'bubble.first_' : 'first.';
  const sent = u.sent > 0 ? ' ' + T(pre + 'sent', { sent: fmt.size(u.sent) }) : '';
  return firstWords(u, pre) + sent;
}
function firstWords(u, pre) {
  const share = srcLabel(u.source);
  if (u.size === null || u.size === undefined) return T(pre + 'nosize', { share });
  const size = fmt.size(u.size);
  const left = firstLeft(u);
  if (!u.rate || left === null) return T(pre + 'measuring', { share, size });
  const rate = fmt.size(u.rate);
  if (left < 60) return T(pre + 'soon', { share, size, rate });
  return T(pre + 'eta', { share, size, rate, time: fmt.time(Date.now() / 1000 + left) });
}

/**
 * Where the run is and when it will be done, from the durations of earlier runs — except a source going
 * to Kopia for the first time: its own measure (size, rate), never "late"
 */
function progress() {
  const s = status();
  if (!s || !live()) return null;
  const est = state.estimates || { sources: {} };
  const now = Date.now() / 1000;
  const planned = (s.kopia && s.kopia.planned) || [];
  const done = new Map(((s.kopia && s.kopia.done) || []).map((d) => [d.name, d]));
  const phase = s.phase;
  const step = Math.max(0, STEPS.findIndex(([, phases]) => phases.includes(phase)));
  const first = uploadNow();
  let remaining = 0;
  let known = true;
  let overdue = false;

  const sourceLeft = (name) => {
    if (first && name === first.source) {
      const left = first.rate && first.size !== null && first.size !== undefined ? firstLeft(first) : null;
      if (left === null) known = false;
      return left || 0;
    }
    const e = est.sources[name];
    if (e === undefined || e === null) { known = false; return 0; }
    if (name === s.kopia.current && s.kopia.current_since) {
      const elapsed = now - s.kopia.current_since;
      if (elapsed > e) overdue = true;
      return Math.max(0, e - elapsed);
    }
    return e;
  };

  if (step < KOPIA_STEP) {
    if (est.before) remaining += Math.max(0, est.before - (now - s.started));
    else if (!planned.length && est.total) remaining += Math.max(0, est.total - (now - s.started));
    else known = false;
    planned.forEach((n) => { remaining += sourceLeft(n); });
  } else if (step === KOPIA_STEP) {
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
    first,
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
      if (p && p.first) out.push(firstText(p.first, true));
    } else if (s && s.phase === 'vm_shutdown') out.push(T('bubble.running_vm_shutdown'));
    else if (s && s.phase === 'partner' && s.partner && s.partner.current) {
      const pc = s.partner.current;
      out.push(T('partner.bubble', { unit: unitLabel(pc.unit), name: partnerName(pc.id, s.partner), n: partnerDoneCount(s.partner) + 1, total: (s.partner.planned || []).length }));
    }
    else if (s) out.push(T('bubble.running_phase', { step: T('step.' + STEPS[p ? p.step : 0][0]) }));
    else out.push(T('bubble.running_old', { step: state.step || '…' }));
    if (p && p.eta) out.push(T(p.overdue ? 'bubble.eta_late' : 'bubble.eta', { time: fmt.time(p.eta) }));
    if (newSkip()) out.push(T('bubble.skipped', { when: fmt.relative(newSkip().time) }));
    return out;
  }
  const last = lastRun();
  if (!last) out.push(T('bubble.no_runs'));
  else {
    const k = last.kopia || [];
    const ok = k.filter((x) => x.ok).length;
    const when = fmt.relative(last.finished || last.started);
    if (last.result === 'ok') out.push(k.length ? T('bubble.last_ok_kopia', { when, ok, total: k.length }) : T('bubble.last_ok', { when }));
    else if (arrayStopped(last)) out.push(T('bubble.last_array_stop', { when }));
    else out.push(T('bubble.last_' + last.result, { when, errors: last.errors, warnings: last.warnings, n: last.result === 'errors' ? last.errors : last.warnings }));
    if (asleepUnits(last)) out.push(T('bubble.asleep_left', { n: asleepUnits(last), pools: (last.asleep.pools || []).join(', ') }));
    const age = Date.now() / 1000 - (last.finished || last.started);
    if (age > 36 * 3600) out.push(T('bubble.old', { days: Math.floor(age / 86400) }));
  }
  if (newSkip()) out.push(T('bubble.skipped', { when: fmt.relative(newSkip().time) }));
  if (waitingCount()) out.push(T('bubble.waiting', { n: waitingCount() }));
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
      actions.push(button(T(stopKey('abort')), 'danger plain', abortRun));
    } else {
      actions.push(button(T('setup_open'), 'plain', () => Office.go(`#/${ID}/setup`)));
      actions.push(button(T('check'), 'plain', () => startRun('check')));
      actions.push(button(T('start'), '', chooseRun));
    }
    actions.forEach((b) => { b.disabled = !canAct() || !!(state.setup && state.setup.running) || (restoring() && !live()); });
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
    [T('help.waiting'), T('help.waiting_text')],
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
  // quiet and plain: no backup is a guarantee, the data stays the user's responsibility
  root.appendChild(el('p', 'bk-disclaimer', T('disclaimer')));
}

function missing() {
  const box = el('div', 'box');
  const p = el('div', 'empty');
  p.appendChild(el('strong', '', T('missing.title')));
  p.append(T('missing.text', { dir: state.dir }));
  box.appendChild(p);
  return box;
}

/**
 * What is new and waits for the user's decision — only local and kept running so far (engine 2.21, agent
 * backupWaiting()): new folders in shares that go to Kopia, new apps, new VMs. Decided in the setup.
 */
const waiting = () => (state && state.waiting) || { folders: [], apps: [], vms: [] };
const waitingCount = () => { const w = waiting(); return (w.folders || []).length + (w.apps || []).length + (w.vms || []).length; };
function waitingText() {
  const w = waiting();
  const short = (list) => (list.length > 4 ? [...list.slice(0, 4), '…'] : list).join(', ');
  const parts = [];
  if ((w.folders || []).length) {
    parts.push(T('waiting.folders', { n: w.folders.length, list: short(w.folders.map((f) => `${f.share}/${f.folder}${f.bytes ? ' (' + fmt.size(f.bytes) + ')' : ''}`)) }));
  }
  if ((w.apps || []).length) parts.push(T('waiting.apps', { n: w.apps.length, list: short(w.apps) }));
  if ((w.vms || []).length) parts.push(T('waiting.vms', { n: w.vms.length, list: short(w.vms) }));
  return T('waiting.callout', { n: waitingCount() }) + ' ' + parts.join(' · ');
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
  if (restoring()) {
    const drill = state.holder.mode === 'drill';          // Mr. Restori's drill: practising on copies, not bringing anything back
    const go = Office.desks.has('restore') ? button(T('notice.restoring_go'), 'small plain', () => Office.go(drill ? '#/restore/drill' : '#/restore')) : null;
    callout(drill ? T('notice.drilling') : T('notice.restoring', { what: state.holder.what || '–' }), false, go);
  }
  if (!state.settings_found) callout(T('notice.no_settings'), true, button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
  const sc = state.schedule || {};
  if (sc.script && !sc.enabled) callout(T('notice.schedule_off'), true, button(T('schedule.open'), 'small', scheduleDialog));     // no settings yet: "no settings" says it all
  const errors = (state.drift && state.drift.items || []).filter((d) => d.level === 'error').length;
  if (errors) callout(T('notice.drift_errors', { n: errors }), true);
  const last = lastRun();
  if (!live() && last && last.result === 'failed' && last.message === 'interrupted') callout(T('notice.interrupted'), true);
  if (!live() && arrayStopped(last)) {
    const left = state.left || {};
    const list = [...(left.stopped || []), ...(left.vms || []).map((name) => T('left.vm', { name })), ...(left.maintenance || []).map((name) => T('left.maintenance', { name }))];
    const n = (last.kopia_skipped || []).length;
    callout(T('notice.array_stop', { when: fmt.date(last.started, true) }) + (n ? ' ' + T('notice.array_stop_kopia', { n }) : '')
      + (list.length ? ' ' + T('notice.array_stop_left', { list: list.join(', ') }) : ''), false);
  }
  // engine 2.28: a share left out asleep night after night is backed up nowhere - the engine warned once, the page says it while it lasts
  if (last && last.asleep && (last.asleep.long || []).length) {
    const long = last.asleep.long;
    callout(T('asleep.long', { n: long.length, shares: long.join(', '), nights: Math.max(...long.map((x) => last.asleep.nights[x] || 0)) }), true,
      button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
  }
  const skip = newSkip();
  if (skip) callout(skipText(skip) + ' ' + T('skipped.next'), true);
  if (waitingCount() && state.settings_found) {
    const go = button(T('waiting.decide'), 'small', () => { setup.focus = 'waiting'; Office.go(`#/${ID}/setup`); });
    go.disabled = !canAct() || live() || restoring();
    callout(waitingText(), false, go);
  }
  if (!live() && (state.mounted || []).length && !(state.settings && state.settings.keep_mounts)) {
    const b = button(T('unmount'), 'small plain', unmount);
    b.disabled = !canAct();
    callout(T('notice.mounted', { n: state.mounted.length }), false, b);
  }
  return out;
}

/** Under the steps: the stretch in which apps and VMs are held - before, now, done */
function holdBracket(s, p, shown) {
  const from = shown.findIndex(([n]) => n === 'dumps');
  const to = shown.findIndex(([n]) => n === 'snapshots');
  const held = ((state.paused || {}).stopped || []).length;
  let cls = '';
  let text = T('hold.before');
  if (s.downtime_s) { cls = 'done'; text = T('hold.done', { duration: fmt.duration(s.downtime_s) }); }
  else if (p.step >= from) { cls = 'now'; text = held ? T('hold.now', { n: held }) : T('hold.now_none'); }
  const row = el('div', 'bk-hold');
  row.style.gridTemplateColumns = `repeat(${shown.length}, minmax(0, 1fr))`;
  const span = el('div', 'bk-hold-span ' + cls, text);
  span.style.gridColumn = `${from + 1} / ${to + 2}`;
  row.appendChild(span);
  return row;
}

/** A run is going on: steps, Kopia sources, ready at about … */
function runningCard() {
  const s = status();
  const p = progress();
  const box = section(T('now'), T('now_sub'), { place: 'now' });
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
  // the partners' step only in a run that sends to partners (engine 2.27)
  const shown = STEPS.map(([name], i) => [name, i]).filter(([name]) => name !== 'partner' || s.partner);
  shown.forEach(([name, i]) => {
    const li = el('li', i < p.step ? 'done' : i === p.step ? 'current' : '', T('step.' + name));
    if (name === 'kopia' && !p.planned.length) li.classList.add('skipped');
    steps.appendChild(li);
  });
  card.appendChild(steps);
  card.appendChild(holdBracket(s, p, shown));

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
  else line.append(T(p.first ? 'eta_first' : 'eta_unknown'));
  if (s.downtime_s) line.append(' · ', T('downtime_was', { duration: fmt.duration(s.downtime_s) }));
  if (s.packages && s.packages.written) line.append(' · ', T('pk.run_packed', { apps: s.packages.apps, vms: s.packages.vms }));
  card.appendChild(line);
  if (s.phase === 'vm_shutdown') card.appendChild(el('div', 'card-line', T('vm_shutdown_now')));
  if (s.phase === 'partner' && s.partner && s.partner.current) {
    const pc = s.partner.current;
    card.appendChild(el('div', 'card-line', T('partner.now', { name: partnerName(pc.id, s.partner), unit: unitLabel(pc.unit), size: fmt.size(pc.bytes || 0),
      n: partnerDoneCount(s.partner), total: (s.partner.planned || []).length })));
  }
  if (p.first) card.appendChild(el('div', 'card-line', firstText(p.first, false)));

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
      const first = current && p.first;
      let info = '';
      if (d) info = d.ok ? dur(d.seconds) : T('failed');
      else if (first) info = dur(Date.now() / 1000 - s.kopia.current_since) + ' · ' + T('src_first');
      else if (current) info = dur(Date.now() / 1000 - s.kopia.current_since) + (est[name] ? ' · ' + T('src_last', { d: dur(est[name]) }) : '');
      else if (est[name]) info = T('src_last', { d: dur(est[name]) });
      const inf = el('span', 'bk-source-info', info);
      if (first) inf.title = T('src_first_hint');
      else if (est[name] && !d) inf.title = T('src_last_hint');
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
  const box = section(T('overview'), T('overview_sub'), { place: 'overview' });
  const stats = el('div', 'stats');
  const last = lastRun();
  const s = status();
  if (last) {
    const k = last.kopia || [];
    const okCount = k.filter((x) => x.ok).length;
    const lastSub = arrayStopped(last) ? T('stat.last_array_stop', { when: fmt.date(last.started, true) }) : fmt.date(last.started, true);
    // engine 2.28: the shares it left out because their pool slept (the user's choice - the result stays ok)
    const st = stat(T('stat.last'), T('result.' + last.result), lastSub + (asleepUnits(last) ? ' · ' + T('asleep.left_out', { n: asleepUnits(last) }) : ''),
      last.result !== 'ok' && !arrayStopped(last));
    stats.appendChild(st);
    stats.appendChild(stat(T('stat.duration'), last.finished ? fmt.duration(last.finished - last.started) : '–',
      T('stat.downtime', { duration: fmt.duration(last.downtime || 0) })));

  } else {
    stats.appendChild(stat(T('stat.last'), '–', T('bubble.no_runs')));
  }
  const sc = state.schedule || {};
  const when = sc.enabled ? (sc.frequency === 'custom' ? fmt.cron(sc.custom) : T('freq.' + sc.frequency)) : T('stat.not_scheduled');
  const sst = stat(T('stat.schedule'), when, sc.enabled ? T('stat.by_office') : T('stat.schedule_hint'), !sc.enabled);
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
    const skipped = ((withKopia && withKopia.kopia_skipped) || []).length;     // an array stop ended that run: they wait for the next one
    const kc = c && c.kopia;
    const st = status();
    const repoNo = st && st.kopia && st.kopia.state === 'no';
    const pol = state.drift && Array.isArray(state.drift.policies) ? state.drift.policies : null;
    const bad = pol ? pol.filter((p) => !p.ok).length : 0;
    const sub = [];
    if (kc) sub.push(!kc.exists ? T('stat.kopia_missing_ct', { name: kc.name }) : kc.running ? T('stat.kopia_running', { name: kc.name }) : T('stat.kopia_stopped', { name: kc.name }));
    if (repoNo) sub.push(T('stat.kopia_repo_problem'));
    if (pol) sub.push(bad ? T('stat.kopia_policies_bad', { n: bad }) : T('stat.kopia_policies_ok'));
    const value = k.length ? `${okCount} / ${k.length + skipped}` : '–';
    const trouble = (kc && (!kc.exists || !kc.running)) || repoNo || bad > 0 || (k.length && okCount !== k.length);
    if (k.length && okCount !== k.length) sub.unshift(T('stat.kopia_missing', { n: k.length - okCount }));
    else if (withKopia) sub.unshift(T('stat.kopia_when', { when: fmt.relative(withKopia.started) }));
    if (skipped) sub.unshift(T('stat.kopia_skipped', { n: skipped }));
    const slept = ((withKopia && withKopia.kopia_asleep) || []).length;     // engine 2.28: their pool slept - left out by choice
    if (slept) sub.push(T('stat.kopia_asleep', { n: slept }));
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
  // the partner offices (engine 2.27): per partner the last run that sent to it - what went, how much, how fast,
  // what was skipped or failed and why; while a run sends to it, what it sends now
  const partners = state.partners || [];
  if (partners.length) {
    const t = el('div', 'stat bk-ptile');
    let alert = false;
    t.append(el('div', 'stat-label', T('partner.tile')), el('div', 'stat-value', partners.map((p) => p.name).join(', ')));
    partners.forEach((p) => {
      const l = p.last;
      const parts = [];
      if (p.current) parts.push(T('partner.tile_now', { name: p.name, unit: unitLabel(p.current.unit), size: fmt.size(p.current.bytes || 0) }));
      else if (!l) parts.push(T('partner.tile_never', { name: p.name, n: p.units.length }));
      else {
        parts.push(l.sent ? T('partner.tile_sent', { name: p.name, n: l.sent, size: fmt.size(l.bytes), mbit: fmt.number(l.mbit || 0, 1), when: fmt.relative(l.time) })
          : T('partner.tile_none', { name: p.name, when: fmt.relative(l.time) }));
        const whys = [...new Set(l.skipped.map((x) => x.why))];
        if (l.skipped.length) parts.push(T('partner.tile_skipped', { n: l.skipped.length, why: whys.map((w) => partnerWhyShort(w)).join(', ') }));
        if (l.failed.length) parts.push(T('partner.tile_failed', { n: l.failed.length, why: [...new Set(l.failed.map((x) => partnerWhyShort(x.why)))].join(', ') }));
        if (l.failed.length || whys.some((w) => !['array_stopping', 'signal'].includes(w))) alert = true;
      }
      t.appendChild(el('div', 'stat-sub', parts.join(' · ')));
    });
    if (alert) t.classList.add('alert');
    tiles.push(t);
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
    ...(foldable.length ? [all] : []), { place: 'protection' });
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

/** A backup run skipped because the lock was busy (engine 2.20): no log, no figures - its own chip and why */
function skipRow(k) {
  const row = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', fmt.date(k.time, true)));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('result.skipped'), RESULT_CHIP.skipped, skipText(k)));
  meta.appendChild(el('span', 'note', messageText(k.reason)));
  main.appendChild(meta);
  row.appendChild(main);
  row.appendChild(el('div', 'bar thin bk-hbar'));
  const fig = el('div', 'figures');
  fig.append(el('b', '', '–'), el('span', '', ''));
  row.appendChild(fig);
  return row;
}

function historySection() {
  const runs = state.history || [];
  const skips = state.skips || [];
  const box = section(T('history'), T('history_sub'), { place: 'history' });
  if (!runs.length && !skips.length) { box.appendChild(el('p', 'empty', T('bubble.no_runs'))); return box; }
  const list = el('div', 'box');
  const longest = Math.max(...runs.map((r) => (r.finished || r.started) - r.started), 1);
  // the runs and, between them, the runs that were skipped - newest first
  const rows = [...runs.slice(0, 20).map((r) => ({ t: r.started, r })), ...skips.map((skip) => ({ t: skip.time, skip }))]
    .sort((a, b) => b.t - a.t).slice(0, 20);
  rows.forEach(({ r, skip }) => {
    if (skip) { list.appendChild(skipRow(skip)); return; }
    const row = el('div', 'row nocheck unfolds');
    row.onclick = () => showLog(r.log);           // the whole row opens the run's log
    const main = el('div', 'row-main');
    const name = el('div', 'row-name text link', fmt.date(r.started, true));
    name.title = T('show_log');
    main.appendChild(name);
    const meta = el('div', 'row-meta');
    meta.appendChild(resultChip(r.result));
    const k = r.kopia || [];
    const ks = (r.kopia_skipped || []).length;
    if (k.length || ks) meta.appendChild(el('span', '', T('kopia_count', { ok: k.filter((x) => x.ok).length, total: k.length + ks })));
    if (ks) meta.appendChild(el('span', '', T('kopia_skipped_count', { n: ks })));
    if (asleepUnits(r)) meta.appendChild(el('span', '', T('asleep.left_out', { n: asleepUnits(r) })));
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
  if (d.code && Office.has(`${ID}.drift_code.${d.code}`)) return T('drift_code.' + d.code, { share: d.value || '', value: d.value || '' });
  if (d.code && Office.has(`${ID}.message.${d.code}`)) return T('message.' + d.code, { detail: d.value || '' });
  return d.text;
}

function driftSection() {
  const items = state.drift.items;
  const box = section(T('drift'), T('drift_sub'), el('span', 'hint', state.drift.time ? T('drift_checked', { when: fmt.relative(state.drift.time) }) : ''),
    { place: 'drift' });
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
  const box = section(T('restore'), T('restore_sub'), { place: 'restore' });
  const set = state.settings || {};
  const prefixes = set.snap_prefixes || [set.snap_prefix || 'uso-backup-'];
  const dl = el('dl', 'kv bk-restore');
  const item = (title, ...parts) => {
    dl.appendChild(el('dt', '', title));
    const dd = el('dd');
    parts.forEach((p) => dd.append(p));
    dl.appendChild(dd);
  };
  item(T('restore.local'), T('restore.local_zfs'), ' ', el('code', '', `/mnt/<pool>/<share>/.zfs/snapshot/${prefixes[0]}…/`),
    ...(prefixes.length > 1 ? [' ', T('restore.local_older', { names: prefixes.slice(1).map((p) => `${p}…`).join(', ') })] : []),
    ' ', T('restore.local_btrfs'), ' ', el('code', '', `${set.view_root || '/mnt/addons/UnraidSecretaryOffice/btrfs-snap'}/<disk>/<…>/<share>/`));
  if (set.kopia_enabled) {
    // the Container Path Kopia names its sources after, as the engine last compared it - otherwise only an example
    const known = ((state.drift && Array.isArray(state.drift.policies) && state.drift.policies.find((p) => p.kind === 'root')) || {}).path;
    const root = known || KOPIA_ROOT_EXAMPLE;
    item(T('restore.kopia'), T('restore.kopia_text', { container: set.kopia_container || 'kopia', root }),
      ...(known ? [] : [' ', T('restore.kopia_example', { root })]),
      ...((state.items || []).length ? [' ', T('restore.kopia_items', { root, list: state.items.map((i) => `.${i.kind}s/${i.name}`).join(', ') })] : []));
  }
  const pk = packages();
  if (pk) item(T('restore.pk'), T('restore.pk_text', { path: pk.base }));
  box.appendChild(dl);
  const restori = Office.desks.get('restore');
  // Mr. Restori's drill: whether restoring was proven lately (his certificate, one line)
  const dr = state.drill;
  if (dr && restori && restori.hired) {
    const line = el('p', dr.result === 'failed' ? 'callout warn' : 'role');
    line.append(dr.result === 'failed' ? T('drill.line_failed', { when: fmt.date(dr.ended), names: (dr.failed || []).join(', ') || '–' })
      : T('drill.line_passed', { when: fmt.date(dr.ended), proven: dr.proven, total: dr.total }), ' ');
    const go = el('a', '', T('drill.line_go'));
    go.href = '#/restore/drill';
    line.appendChild(go);
    box.appendChild(line);
  }
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
// what starts, stops or changes something opens only on a fresh look at his state (Office.freshState()), never a stale one
async function chooseRun() {
  if (!(await Office.freshState(ID))) return;
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
    // a full backup sends to Kopia only while it is switched on in the settings: say what really happens
    text.appendChild(el('small', '', T(m === 'backup' && !kopia ? 'mode_hint.backup_local' : 'mode_hint.' + m)));
    label.append(input, text);
    box.appendChild(label);
  });
  const est = state.estimates || {};
  if (est.total) box.appendChild(el('p', 'callout', T('start_hint', { duration: dur(est.total) })));
  Office.dialog({
    title: T('start_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('start_go'), kind: '', act: () => startRun(mode) }],
  });
}

async function startRun(mode) {
  if (!(await Office.freshState(ID))) return false;
  const j = await Office.api.post(`${ID}.start`, { mode });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
  if (j.state) state = j.state;
  // something took the lock between the agent's look and the start: the engine skipped it and says why
  if (j.skipped) Office.toast(skipText(j.skipped), true);
  else Office.toast(j.started ? T('started.' + mode) : T('started_pending'));
  render();
  schedule();
  return true;
}

async function abortRun() {
  if (!(await Office.freshState(ID))) return;
  const s = status();
  const mode = runMode();
  const box = el('div');
  box.appendChild(el('p', '', T(stopKey('abort_text', mode))));
  if (mode === 'backup') {
    if (s && s.phase === 'kopia') box.appendChild(el('p', 'callout', T('abort_kopia')));
    if (s && s.phase === 'vm_shutdown') box.appendChild(el('p', 'callout', T('abort_vm_shutdown')));
    if (s && ['stopping', 'snapshots', 'starting'].includes(s.phase)) box.appendChild(el('p', 'callout warn', T('abort_downtime')));
  }
  Office.dialog({
    title: T(stopKey('abort_title', mode)),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T(stopKey('abort', mode)), kind: 'danger', act: async () => {
        const j = await Office.api.post(`${ID}.abort`, {});
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.state) state = j.state;
        Office.toast(T(stopKey('aborting', mode)));
        render();
        schedule();
        return true;
      } },
    ],
  });
}

async function unmount() {
  if (!(await Office.freshState(ID))) return;
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
const LIST_KEY = /\|(ignore|no_stop|known|skip|kopia_ignore|kopia_known|exclude_dataset|tar_exclude|folder)$/;
let setup = { plan: null, draft: null, status: null, run: null, applied: null, open: new Set(), retire: true, asked: false, focus: null,
  model: null, levels: {}, held: {}, deps: new Set(), locks: {}, itemKeep: {},
  choice: null,              // the default chosen on this page, not applied yet: {kind: auto | local | kopia, all, items}
  newItems: new Set() };     // what is new since the last setup (setupNewItems()), as item keys
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
  // a default chosen on this page and not applied yet - Apply stores it (only an engine that knows the key, 2.31)
  if (setup.choice && setup.plan.preset_new !== undefined) setup.draft['general|preset_new'] = setup.choice.kind;
  setup.model = setupModel(setup.plan);
  setupInitLevels();
  setupDerive();
  // new folders that no app or VM owns: proposed "only local" - Kopia only when the user says so (engine 2.21)
  waitingFolders().forEach((w) => { if (!w.owner && waitChoice(w) === null) waitSet(w, 'local'); });
  // a default chosen «for everything now»: its levels for what was there when it was chosen
  const c = setup.choice;
  if (c && c.all) presetApply(c.kind, c.items);
  // the default for what is new (engine 2.31, [general] preset_new) - on what the choice above didn't cover
  setup.newItems = setupNewItems(setup.plan, setupSaved());
  const covered = (k) => !!(c && c.all && (!c.items || c.items.has(k)));
  presetApplyNew(presetNow(), new Set([...setup.newItems].filter((k) => !covered(k))));
  setup.base = clone(setup.draft);         // what the assistant proposes (with the default), before the user clicks
  setup.baseLevels = { ...setup.levels };
  setup.baseHeld = { ...setup.held };
}

/**
 * A new plan came in (a tour, measuring, the setup opened again): the proposals are read anew, but what the
 * user chose and hasn't applied stays - a key the engine still proposes as before keeps the user's value (one
 * it proposes differently now takes the new proposal), a level or hold picked stays while the app or VM is there
 */
function setupDraftKeep() {
  const old = setup.draft && setup.base ? { draft: setup.draft, base: setup.base, levels: setup.levels, held: setup.held,
    baseLevels: setup.baseLevels || {}, baseHeld: setup.baseHeld || {} } : null;
  setupDraftFromPlan();
  if (!old) return;
  const keys = new Set([...Object.keys(old.base), ...Object.keys(old.draft)]);
  let kept = 0;
  keys.forEach((k) => {
    if (same(old.base[k], old.draft[k]) || !same(old.base[k], setup.base[k])) return;
    if (old.draft[k] === undefined) delete setup.draft[k];
    else setup.draft[k] = old.draft[k];
    kept++;
  });
  Object.keys(old.levels).forEach((k) => {
    if (old.levels[k] !== old.baseLevels[k] && k in setup.levels) { setup.levels[k] = old.levels[k]; kept++; }
  });
  Object.keys(old.held).forEach((k) => {
    if (old.held[k] !== old.baseHeld[k] && k in setup.held) { setup.held[k] = old.held[k]; kept++; }
  });
  if (kept) setupDerive();
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
  // kopia_known (engine 2.21) missing means "not recorded yet" - recording it is a change Apply makes
  Object.keys(P).forEach((k) => { if (!(k in O) && have.has(section(k)) && !/\|kopia_known$/.test(k)) saved[k] = P[k]; });
  return saved;
}

/** What Apply changes against the saved settings: the differences, and a share's first record of its folders (even none) */
function setupChanges(saved, draft) {
  const keys = new Set(diffKeys(saved, draft));
  Object.keys(draft).forEach((k) => { if (/\|kopia_known$/.test(k) && saved[k] === undefined && draft[k] !== undefined) keys.add(k); });
  return [...keys].sort();
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
  Office.busy(`${ID}.setup`, !!(j.status && j.status.running) && (j.status.mode !== 'plan' || !setup.measuring));
  // a plan from before engine 2.17 doesn't know which shares a container binds: read the server again
  const stale = j.plan && ((j.plan.containers || []).some((c) => c.binds === undefined) || !('docker|skip' in (j.plan.P || {})));
  if (stale && !j.status.running && !setup.restale && canPlan()) { setup.restale = true; setupPlan(false, true); }
  if (j.plan && !stale && (!setup.plan || j.plan.time !== setup.plan.time)) {
    setup.plan = j.plan;
    setupDraftKeep();
  }
  const finishedForget = was && was.running && was.mode === 'forget' && !j.status.running;
  if (finishedForget) {
    if (j.run && j.run.result === 'ok') {
      Office.toast(T('setup.forgotten'));
      setup.plan = null;                     // the old plan went aside with the settings
      setup.draft = null;
      setup.applied = null;
      presetForget();                        // a new server again: the default's cards from the top
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
      presetForget();                        // applied: the default is in settings.ini from now on (preset_new)
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

const canPlan = () => !!(state && state.found && state.compatible && Office.agent.running && !state.running);

async function setupPlan(measure, quiet) {
  setup.asked = true;
  setup.measuring = !!measure;            // measuring sizes can take minutes: no wave then
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
    // the units' partner lists have their own group below («To partners»)
    const changes = setupChanges(saved, setup.draft).filter((k) => !/^(share\|.+\|partner|vm\|.+\|partner|general\|partner_place)$/.test(k));
    box.appendChild(el('p', '', changes.length ? T('setup.apply_text', { n: changes.length }) : T('setup.apply_none')));
    if (changes.length) {
      const ul = el('ul', 'shortlist');
      changes.slice(0, 80).forEach((k) => {
        const li = el('li', '', changeLabel(k));
        li.appendChild(el('span', '', /\|kopia_known$/.test(k) ? knownText(saved[k], setup.draft[k]) : `${valueText(saved[k], k)} → ${valueText(setup.draft[k], k)}`));
        ul.appendChild(li);
      });
      box.appendChild(ul);
    }
    // what is new on the server and how this Apply takes it in (engine 2.21: until now only local, kept running)
    const news = setupNewLines();
    if (news.length) {
      // engine 2.31: decided in advance by the default for new things - said, so nothing goes along unseen
      const kind = presetNow();
      box.appendChild(el('p', '', [T('setup.apply_group_new', { n: news.length }),
        kind !== 'auto' ? T('setup.apply_new_default', { name: T('setup.preset.' + kind) }) : ''].filter(Boolean).join(' ')));
      const ul = el('ul', 'shortlist bk-newlist');
      news.forEach(([name, how]) => {
        const li = el('li', '', name);
        li.appendChild(el('span', '', how));
        ul.appendChild(li);
      });
      box.appendChild(ul);
    }
  }
  // engine 2.27: what goes to each partner office
  const pl = (setup.plan.partners || []).length ? partnerApplyLines() : [];
  if (pl.length) {
    box.appendChild(el('p', '', T('partner.apply_group')));
    const ul = el('ul', 'shortlist');
    pl.forEach(([name, how]) => {
      const li = el('li', '', name);
      li.appendChild(el('span', '', how));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }
  box.appendChild(el('p', 'role', T(!setup.plan.have_settings ? 'setup.apply_hint_new' : 'setup.apply_hint')));
  box.appendChild(el('p', 'role', T('setup.apply_responsibility')));
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
        Office.busy(`${ID}.setup`, true);
        setupLoad();
        return true;
      } },
    ],
  });
}

/** A share's record of the folders that go to Kopia (engine 2.21): the first one counted, later what comes and goes */
function knownText(before, after) {
  const a = before || [];
  const b = after || [];
  if (b.includes('*')) return T('setup.known_all');           // a collection: every folder goes, new ones too
  if (before === undefined) return T('setup.known_first', { n: b.length });
  const plus = b.filter((x) => !a.includes(x));
  const minus = a.filter((x) => !b.includes(x));
  const list = (l) => (l.length > 8 ? `${l.slice(0, 8).join(', ')} … (${l.length})` : l.join(', '));
  return [plus.length ? '+ ' + list(plus) : '', minus.length ? '− ' + list(minus) : ''].filter(Boolean).join(' · ') || '–';
}

/** The apply dialog's group «New»: each new VM, app, container of a known app, share and folder, and how Apply takes it in */
function setupNewLines() {
  const m = setup.model;
  if (!m) return [];
  const out = [];
  const appHow = (a) => {
    const l = levelOf('app:' + a.id);
    return l === 0 ? T('setup.level.0') : `${T('setup.level.' + l)}, ${T('setup.app_hold.' + (setup.held[a.id] || 'stop'))}`;
  };
  m.vms.filter((x) => x.isNew).forEach((x) => {
    const l = levelOf('vm:' + x.name);
    out.push([T('setup.new_vm', { name: x.name }), l === 0 ? T('setup.level.0') : `${T('setup.level.' + l)}, ${T('setup.vm_prep.' + dget(`vm|${x.name}|prepare`, 'none'))}`]);
  });
  m.apps.filter((a) => a.isNew).forEach((a) => out.push([T('setup.new_app', { name: a.name }), appHow(a)]));
  m.apps.filter((a) => a.newMembers.length).forEach((a) => out.push([T('setup.new_member', { name: a.newMembers.join(', '), app: a.name }), appHow(a)]));
  setup.plan.shares.filter((sh) => sh.exists && setup.newItems.has('share:' + sh.name))
    .forEach((sh) => out.push([T('setup.new_share', { name: sh.name }), T('setup.mode.' + dget(`share|${sh.name}|mode`, 'off'))]));
  waitingFolders().forEach((w) => out.push([`${w.share}/${w.dir}`, waitHow(w)]));
  return out;
}

function changeLabel(k) {
  const p = k.split('|');
  if (p[0] === 'partner') return T('partner.ch_section', { name: partnerName(p[1], setup.plan) || p[1], key: p[2] });
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
      : p[0] === 'vm' && p[2] === 'prepare' ? `setup.vm_prep.${v}`
        : k === 'general|asleep_pools' ? `setup.asleep.short_${v}`
          : k === 'general|preset_new' ? `setup.preset.${v}` : '';
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
  if (presetNow() !== 'auto') box.appendChild(el('p', '', T('setup.forget_default', { name: T('setup.preset.' + presetNow()) })));
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

// ---- the default (Benj, 2026-10-07/08): three of them - my own proposals, everything local only, everything local +
// Kopia. Chosen «for everything now» it sets the levels and share modes the way a click on every row would; chosen
// «only for what is new» it does so for what came after the last setup. Either way it is stored at Apply as
// [general] preset_new (engine 2.31) and from then on fills in whatever is new each time the draft is made
// (setupDraftFromPlan()). setupDerive() does the rest as always, every row stays the user's to change, and
// nothing counts before Apply - the run reads settings.ini only.
const PRESETS = ['auto', 'local', 'kopia'];
/** The plan's reasons for a share the engine never backs up on its own (setup.sh share_propose()) */
const PRESET_KEEP = ['system', 'name_bad', 'kopia_workdir', 'syslog', 'timemachine', 'drift_ignore', 'domains'];
/** A Kopia container's own working folders (setup.sh kopia_workdir()) */
const KOPIA_WORKDIR = /^\/(config|cache|logs|tmp|backups|repo|repository|app\/config|app\/cache|app\/logs)(\/|$)/;
const UPLOAD_MBIT = 100;          // the upload speed a first upload to Kopia is reckoned at

/** A drift.ignore glob (bash's [[ name == $glob ]]) */
function globMatch(glob, name) {
  try {
    const re = String(glob).replace(/[.+^${}()|\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.').replace(/\[!/g, '[^');
    return new RegExp(`^${re}$`).test(name);
  } catch (e) {
    return false;
  }
}

/** The share a path is as a whole (a Kopia mapping's source): /mnt/user/<share>, /mnt/<pool or disk>/<share> */
function wholeShareOf(path, plan) {
  const m = /^\/mnt\/([^/]+)\/([^/]+)\/?$/.exec(path || '');
  if (!m) return null;
  return ['user', 'user0', ...(plan.bases || []).map((b) => b.name)].includes(m[1]) ? m[2] : null;
}

/**
 * Why a share keeps what the plan says under every start: what the engine never backs up on its own - the system
 * share (Docker image, libvirt), a name it can't use, the Kopia container's own folders, the share Unraid's syslog
 * server writes into (setup.sh syslog_share(): the plan's flag `syslog` since engine 2.32, every plan; the why code syslog of
 * a first plan), a Time Machine target, drift.ignore, VM disks without snapshots. From the plan's reason, or - for settings made earlier, «as before» -
 * the way the engine finds it (setup.sh share_propose(), timemachine_reason(), kopia_workdir())
 */
function presetKeep(sh, plan) {
  if (sh.syslog) return 'syslog';
  if (PRESET_KEEP.includes(sh.why)) return sh.why;
  const name = sh.name;
  if (name === 'system') return 'system';
  if (/[@:"|\n]/.test(name)) return 'name_bad';
  if (((plan.kopia || {}).mappings || []).some((mp) => !mp.main && KOPIA_WORKDIR.test(mp.target || '') && wholeShareOf(mp.source, plan) === name)) return 'kopia_workdir';
  if (/time[ _-]?machine/i.test(name)
      || (plan.containers || []).some((c) => /time-?machine/i.test(c.image || '') && (c.binds || []).some((b) => b.share === name))) return 'timemachine';
  if (((plan.P || {})['drift|ignore'] || []).some((g) => globMatch(g, name))) return 'drift_ignore';
  if (name === 'domains' && sh.method !== 'snap') return 'domains';
  return null;
}
/** A VM the engine can't snapshot (its disks lie where no snapshot reaches) keeps what the plan says */
const presetVmKeep = (x) => (x.v.snap && x.v.snap !== 'yes' ? 'vm_cannot' : null);

/** What a default chosen «for everything now» covers: what is on the page then - whatever comes later is new (presetApplyNew) */
function presetItemKeys() {
  const plan = setup.plan;
  return new Set([...setup.model.apps.map((a) => 'app:' + a.id), ...setup.model.vms.map((x) => 'vm:' + x.name),
    ...plan.shares.map((sh) => 'share:' + sh.name),
    ...plan.shares.flatMap((sh) => (sh.waiting || []).map((w) => `wait:${sh.name}/${w.dir}`))]);
}

/**
 * How an app is held under a start: media servers keep running (Benj's rule); an app that ran only because nothing
 * of it was backed up (not backed up at all, or its data only in shares that were off) is held as the engine
 * proposes - stopped when it now writes into what is backed up, Docker volumes too; all others as the plan says
 */
function presetHold(a, before) {
  const cts = a.members.map((n) => setup.plan.containers.find((c) => c.name === n)).filter(Boolean);
  if (cts.some((c) => c.media)) return 'run';
  const idle = before === 0 || (setup.held[a.id] === 'run' && cts.length > 0 && cts.every((c) => c.why === 'no_data'));
  if (!idle) return setup.held[a.id];
  const writes = cts.some((c) => (c.volumes || []).length > 0
    || (c.binds || []).some((b) => b.rw && b.share.toLowerCase() !== 'system' && dget(`share|${b.share}|mode`, 'off') !== 'off'));
  return writes ? 'stop' : 'run';
}

/**
 * Fills the draft from a start: «local» puts every share, app and VM at local and Kopia off; «kopia» all of them at
 * local + Kopia (and a new folder nobody owns along). What the engine never backs up on its own stays as the plan
 * says (presetKeep, VMs it can't snapshot); the Kopia container and the office's own aren't apps; the backup place,
 * the user's Kopia ignore rules and setupDerive()'s rules stay; the flash and the VMs' configurations are backed up
 * too. `items`: only these (what was there when the start was chosen), null = everything. «auto» = the plan as it is.
 */
function presetApply(kind, items) {
  if (kind !== 'local' && kind !== 'kopia') return;
  const m = setup.model;
  const plan = setup.plan;
  const has = (k) => !items || items.has(k);
  const lv = kind === 'kopia' ? 2 : 1;
  dset('kopia|enabled', kind === 'kopia' ? 'yes' : 'no');
  // Kopia off: what went there stays local (like unticking «Back up with Kopia»)
  if (kind === 'local') Object.keys(setup.draft).forEach((k) => { if (/^share\|.+\|mode$/.test(k) && setup.draft[k] === 'kopia') setup.draft[k] = 'snapshot'; });
  const before = { ...setup.levels };
  m.apps.forEach((a) => { if (has('app:' + a.id)) setup.levels['app:' + a.id] = lv; });
  m.vms.forEach((x) => {
    if (!has('vm:' + x.name) || presetVmKeep(x)) return;
    // from «not» up: freeze or pause proposed again, like a click on its row (a VM of its own kept running at «not»)
    if (!before['vm:' + x.name]) dset(`vm|${x.name}|prepare`, undefined);
    setup.levels['vm:' + x.name] = lv;
  });
  plan.shares.forEach((sh) => {
    if (sh.exists && has('share:' + sh.name) && !presetKeep(sh, plan)) dset(`share|${sh.name}|mode`, LV[lv]);
  });
  if (dget('flash|mode', 'off') === 'off') dset('flash|mode', plan.flash && plan.flash.dataset ? 'snapshot' : 'tar');
  if (dget('libvirt|mode') === 'off') dset('libvirt|mode', 'tar');
  setupDerive();
  m.apps.forEach((a) => { if (has('app:' + a.id)) setup.held[a.id] = presetHold(a, before['app:' + a.id] ?? 0); });
  setupDerive();
  if (kind === 'kopia') waitingFolders().forEach((w) => { if (!w.owner && has(`wait:${w.share}/${w.dir}`)) waitSet(w, 'kopia'); });
}

/** Under the default «local + Kopia» a new share the engine finds big (over 500 GB) or can't size waits for the user (Benj, 2026-10-08) */
const PRESET_NEW_ASK = ['big', 'size_unknown'];
const presetNewAsk = (sh, kind) => kind === 'kopia' && PRESET_NEW_ASK.includes(sh.why);

/**
 * What is new since the last setup (engine 2.21), as item keys like presetItemKeys(): an app whose containers are all
 * new (not in [docker] known - the plan's why, as setup.sh decides it), a VM without a section of its own (why new), a
 * share settings.ini has no section for (not a renamed one: its settings carry over), every folder the plan lists as
 * waiting. A new server has nothing new: everything is, and the default chosen there covers it.
 */
function setupNewItems(plan, saved) {
  const out = new Set();
  if (!plan || !plan.have_settings) return out;
  const apps = new Map();
  (plan.containers || []).forEach((c) => {
    if (c.kopia || c.why === 'office') return;
    const id = c.project ? 'stack:' + c.project : 'ct:' + c.name;
    apps.set(id, (apps.get(id) ?? true) && c.why === 'new');
  });
  apps.forEach((fresh, id) => { if (fresh) out.add('app:' + id); });
  (plan.vms || []).forEach((v) => { if (v.why === 'new') out.add('vm:' + v.name); });
  (plan.shares || []).forEach((sh) => {
    if (sh.exists && sh.why !== 'renamed_from' && (saved || {})[`share|${sh.name}|mode`] === undefined) out.add('share:' + sh.name);
    (sh.waiting || []).forEach((w) => out.add(`wait:${sh.name}/${w.dir}`));
  });
  return out;
}

/**
 * The default for new things on the new items only (`items`, from setupNewItems()): what presetApply() does to a row,
 * without its global side effects - Kopia isn't switched on or off, nothing set up changes, the flash and libvirt stay.
 * «local»: a new app local and held like any app (stopped for the snapshot when it writes into what is backed up,
 * a media server keeps running - presetHold), a new VM local and held (freeze or pause), a new share local, a new
 * folder nobody owns only local; «kopia» the same with Kopia - while Kopia is on, else local -, and a new share the
 * engine finds big or can't size left to the user. What the engine never backs up on its own stays as the plan says
 * (presetKeep, a VM it can't snapshot). «auto»: nothing - my proposals, new things wait for a decision.
 */
function presetApplyNew(kind, items) {
  if ((kind !== 'local' && kind !== 'kopia') || !items || !items.size) return;
  const m = setup.model;
  const plan = setup.plan;
  const lv = kind === 'kopia' && dget('kopia|enabled') === 'yes' ? 2 : 1;
  const apps = m.apps.filter((a) => items.has('app:' + a.id));
  apps.forEach((a) => { setup.levels['app:' + a.id] = lv; });
  m.vms.forEach((x) => {
    if (!items.has('vm:' + x.name) || presetVmKeep(x)) return;
    dset(`vm|${x.name}|prepare`, undefined);       // held like any VM: freeze or pause proposed (the engine's «none» kept it running until decided)
    setup.levels['vm:' + x.name] = lv;
  });
  plan.shares.forEach((sh) => {
    if (sh.exists && items.has('share:' + sh.name) && !presetKeep(sh, plan) && !presetNewAsk(sh, kind)) dset(`share|${sh.name}|mode`, LV[lv]);
  });
  setupDerive();
  apps.forEach((a) => { setup.held[a.id] = presetHold(a, 0); });   // kept running so far only because it was new
  setupDerive();
  waitingFolders().forEach((w) => { if (!w.owner && items.has(`wait:${w.share}/${w.dir}`)) waitSet(w, lv === 2 ? 'kopia' : 'local'); });
}

/** The default for new things: the one chosen on this page, else the draft's (settings.ini's) - auto without one */
function presetNow() {
  const v = setup.choice ? setup.choice.kind : setup.draft && setup.draft['general|preset_new'];
  return PRESETS.includes(v) ? v : 'auto';
}

/**
 * A default chosen. `newOnly` (only with settings): only what is new gets it now, the draft's other choices stay;
 * otherwise every row gets it now (what the user chose in the draft goes). Either way it is the default for whatever
 * is new from now on, stored at Apply; nothing applied changes before.
 */
function presetChoose(kind, newOnly) {
  if (!PRESETS.includes(kind)) return;
  const all = !newOnly || !(setup.plan && setup.plan.have_settings);
  setup.choice = { kind, all, items: null };
  if (!all) { setupDraftKeep(); return; }
  setupDraftFromPlan();
  setup.choice.items = presetItemKeys();
}
function presetForget() {
  setup.choice = null;
}
/** A default of the user's at work: what it changes is the user's, not proposals of mine */
const presetChosen = () => !!setup.choice || (presetNow() !== 'auto' && setup.newItems.size > 0);
/** Something changed by hand since the default (or since my proposal) */
const presetChanged = () => !!(setup.draft && setup.base) && setupEdits().length > 0;
const presetStartText = () => [setup.choice && setup.choice.all ? T('setup.preset.start', { name: T('setup.preset.' + setup.choice.kind) }) : '',
  presetNow() !== 'auto' && setup.plan && setup.plan.have_settings && !(setup.choice && setup.choice.all)
    ? T('setup.preset.new_sub', { name: T('setup.preset.' + presetNow()) }) : '',
  presetChanged() ? T('setup.preset.changed') : ''].filter(Boolean).join(' · ');

/** Whether «local + Kopia» can start here: not without a Kopia container; otherwise what isn't ready yet */
function presetKopiaState(plan) {
  const k = plan.kopia || {};
  if (!k.container && !(k.candidates || []).length) return { ok: false, why: 'none' };
  if ((plan.P || {})['kopia|enabled'] !== 'yes') return { ok: true, why: 'unchecked' };      // off so far: never looked at
  if (k.problem && !['off', 'no_shares'].includes(k.problem)) return { ok: true, why: 'problem', problem: k.problem };
  return { ok: true, why: null };
}

/**
 * What goes to Kopia for the first time: the shares going there (`modeOf`) that didn't before - on a new server all
 * of them -, with their sizes as far as the plan knows them (a share on the array only after «Measure sizes»); and
 * the VMs whose disks go along for the first time - with their share, or as a source of their own (`vmLevel` 2) -
 * counted by what Kopia really reads: a VM's first upload reads its disk files whole, a sparse vdisk's holes as
 * zeros (engine 2.26: the plan's `apparent` beside `bytes`, what the files take - a 1.6 TB vdisk holding 21 GB is
 * 1.6 TB of reading), so a share's measured size (what its files take) grows by the gap. `vms`, `vm_bytes` (what
 * their disks are) and `vm_used` (what they hold) say so in words.
 */
function firstUpload(modeOf, vmLevel) {
  const plan = setup.plan;
  const O = plan.have_settings && (plan.O || {})['kopia|enabled'] === 'yes' ? plan.O : {};
  const out = { bytes: 0, shares: [], unknown: [], vms: [], vm_bytes: 0, vm_used: 0 };
  plan.shares.forEach((sh) => {
    if (!sh.exists || modeOf(sh) !== 'kopia' || O[`share|${sh.name}|mode`] === 'kopia') return;
    out.shares.push(sh.name);
    if (sh.gb === null || sh.gb === undefined || sh.gb < 0) out.unknown.push(sh.name);
    else out.bytes += sh.gb * 1073741824;
  });
  const shareOf = (name) => plan.shares.find((sh) => sh.name === name);
  (setup.model ? setup.model.vms : []).forEach((x) => {
    const v = x.v;
    const lvl = vmLevel ? vmLevel(x) : 0;
    // with its share - unless it is below «local + Kopia» there: then setupDerive() leaves its folder out of Kopia
    const goes = lvl === 2 || x.shares.some((n) => shareOf(n) && shareOf(n).exists && modeOf(shareOf(n)) === 'kopia'
      && !(vmLevel && x.folders.some((f) => f.share === n)));
    if (!(v.apparent > 0) || !goes) return;
    // went before: with its share, or as its own source (same repository - nothing is read twice)
    if (O[`vm|${x.name}|kopia`] === 'yes' || x.shares.some((n) => O[`share|${n}|mode`] === 'kopia')) return;
    out.vms.push(x.name);
    out.vm_bytes += v.apparent;
    out.vm_used += v.bytes || 0;
    // its share's size (when counted above) holds what its disks take; the first upload reads what they are
    out.bytes += x.shares.some((n) => out.shares.includes(n)) ? Math.max(0, v.apparent - (v.bytes || 0)) : v.apparent;
  });
  return out;
}
/** «local + Kopia» before it is chosen: every share the engine doesn't keep, every VM it can snapshot */
const presetKopiaMode = (sh) => (presetKeep(sh, setup.plan) ? (setup.plan.P || {})[`share|${sh.name}|mode`] : 'kopia');
const presetKopiaVm = (x) => (presetVmKeep(x) ? 0 : 2);
const draftMode = (sh) => dget(`share|${sh.name}|mode`, 'off');
const draftVm = (x) => levelOf('vm:' + x.name);
/**
 * «local + Kopia» for what is new only, before it is chosen: the new shares it sends (not the big or unknown ones), the
 * shares new VMs and apps then take to Kopia with them (setupDerive() locks them so), the new VMs
 */
function presetNewMode(sh) {
  const n = setup.newItems;
  const m = setup.model || { vms: [], apps: [] };
  const pulled = [...m.vms.filter((x) => n.has('vm:' + x.name) && !presetVmKeep(x)).flatMap((x) => x.shares),
    ...m.apps.filter((a) => n.has('app:' + a.id)).flatMap((a) => a.folders.map((f) => f.share))];
  return (n.has('share:' + sh.name) && !presetKeep(sh, setup.plan) && !presetNewAsk(sh, 'kopia')) || pulled.includes(sh.name) ? 'kopia' : draftMode(sh);
}
const presetNewVm = (x) => (setup.newItems.has('vm:' + x.name) && !presetVmKeep(x) ? 2 : draftVm(x));

/** The first upload in words: how much, what isn't measured, how long at 100 Mbit/s, what it costs */
function uploadLines(up, measure) {
  const out = [];
  if (!up.shares.length && !up.vms.length) return out;
  out.push(el('div', 'bk-upload-size', T('setup.preset.kopia_size', { n: up.shares.length, size: fmt.size(up.bytes) })));
  if (up.vms.length) {
    out.push(el('div', '', T('setup.preset.kopia_vms', { n: up.vms.length, list: up.vms.length > 6 ? `${up.vms.slice(0, 6).join(', ')} …` : up.vms.join(', '),
      size: fmt.size(up.vm_bytes), used: fmt.size(up.vm_used) })));
  }
  if (up.unknown.length) {
    const list = up.unknown.length > 6 ? `${up.unknown.slice(0, 6).join(', ')} …` : up.unknown.join(', ');
    const line = el('div', '', T('setup.preset.kopia_unknown', { n: up.unknown.length, list }));
    if (measure && canPlan() && !(setup.status && setup.status.running)) line.append(' ', button(T('setup.measure'), 'small plain', () => setupPlan(true)));
    out.push(line);
  }
  if (up.bytes > 0) {
    const time = fmt.duration(up.bytes * 8 / (UPLOAD_MBIT * 1e6));
    out.push(el('div', '', T(up.unknown.length ? 'setup.preset.kopia_time_least' : 'setup.preset.kopia_time', { time, speed: `${UPLOAD_MBIT} Mbit/s` })));
  }
  out.push(el('div', '', T('setup.preset.kopia_cost')));
  return out;
}

/** What every start leaves as it is: the shares the engine keeps, the VMs it can't snapshot */
function presetKeptList() {
  const plan = setup.plan;
  const out = plan.shares.filter((sh) => sh.exists).map((sh) => [sh.name, presetKeep(sh, plan)]).filter(([, w]) => w)
    .map(([name, w]) => T('setup.preset.keep.' + w, { name }));
  (setup.model ? setup.model.vms : []).forEach((x) => {
    const w = presetVmKeep(x);
    if (w) out.push(T('setup.preset.keep.' + w, { name: x.name }));
  });
  return out;
}

/** Where to read up on Kopia: the Consultant (he sets a container and its repository up), else the team lead */
function kopiaHelpLink() {
  const adv = Office.desks.get('advisor');
  const a = el('a', '', T(adv && adv.hired ? 'setup.preset.kopia_advisor' : 'setup.k_caretaker'));
  a.href = adv && adv.hired ? '#/advisor' : '#/caretaker';
  return a;
}

/** A card's honest notes: what a default doesn't protect against, what it needs and costs (`newOnly`: for what is new) */
function presetNotes(kind, kst, measure, newOnly) {
  const plan = setup.plan;
  const out = [];
  const note = (text, cls) => {
    const p = el('p', 'bk-preset-note' + (cls ? ' ' + cls : ''), text);
    out.push(p);
    return p;
  };
  if (kind === 'auto') return out;
  if (kind === 'local') note(T('setup.preset.local_note'), 'warn');
  if (kind === 'kopia') {
    if (!kst.ok) {
      note(T('setup.preset.kopia_none'), 'warn').append(' ', kopiaHelpLink());
      return out;
    }
    note(T('setup.preset.kopia_repo'));
    if (kst.why === 'problem') {
      const problem = T('setup.k_problem.' + kst.problem, { name: plan.kopia.container || 'kopia', root: plan.mount_root });
      note(T('setup.preset.kopia_not_ready', { problem }), 'warn').append(' ', kopiaHelpLink());
    }
    if (newOnly && dget('kopia|enabled') !== 'yes') {
      note(T('setup.preset.kopia_off_new'), 'warn');       // a default for new things never switches Kopia on
      return out;
    }
    if (kst.why === 'unchecked') note(T('setup.kopia_recheck'));
    // the draft says it once this is chosen so; before, what it would send
    const now = setup.choice && setup.choice.kind === 'kopia' && setup.choice.all === !newOnly;
    const up = now ? firstUpload(draftMode, draftVm) : newOnly ? firstUpload(presetNewMode, presetNewVm) : firstUpload(presetKopiaMode, presetKopiaVm);
    if (newOnly && !up.shares.length && !up.vms.length) note(T('setup.preset.kopia_new_none'));
    const lines = uploadLines(up, measure);
    if (lines.length) {
      const box = el('div', 'bk-preset-note bk-upload');
      lines.forEach((l) => box.appendChild(l));
      out.push(box);
    }
  }
  return out;
}

/** Under the cards, once for every start: what stays as it is, media servers, what comes later */
function presetCommon() {
  const plan = setup.plan;
  const kept = presetKeptList();
  const text = [kept.length ? T('setup.preset.kept', { list: kept.join(', ') }) : '',
    setup.model.apps.some((a) => a.members.some((n) => (plan.containers.find((c) => c.name === n) || {}).media)) ? T('setup.preset.media') : '',
    plan.have_settings ? T('setup.preset.new_later') : ''].filter(Boolean).join(' ');
  return text ? el('p', 'role bk-preset-common', text) : null;
}

/** The three defaults as cards, a radio group: `current` marked, a click or Enter picks one (`onPick(kind, card)`); below them what holds for all */
function presetCards(current, onPick, measure, newOnly) {
  const plan = setup.plan;
  const g = el('div', 'bk-presets');
  g.setAttribute('role', 'radiogroup');
  g.setAttribute('aria-label', T('setup.preset.title'));
  const busy = !!(setup.status && setup.status.running);
  const kst = presetKopiaState(plan);
  PRESETS.forEach((kind) => {
    const on = kind === current;
    const off = busy || (kind === 'kopia' && !kst.ok);
    const c = el('div', 'bk-preset' + (on ? ' on' : '') + (off ? ' off' : ''));
    c.dataset.preset = kind;
    c.setAttribute('role', 'radio');
    c.setAttribute('aria-checked', String(on));
    if (off) c.setAttribute('aria-disabled', 'true');
    else c.tabIndex = 0;
    const head = el('div', 'bk-preset-head');
    head.appendChild(el('strong', '', T('setup.preset.' + kind)));
    if (on) head.appendChild(chip(T('setup.preset.chosen'), 'accent'));
    if (on && kind === presetNow() && presetChanged()) head.appendChild(chip(T('setup.preset.changed'), 'quiet'));
    c.appendChild(head);
    c.appendChild(el('p', '', T(kind === 'auto' ? (plan.have_settings ? 'setup.preset.auto_have' : 'setup.preset.auto_new')
      : newOnly ? `setup.preset.${kind}_new_text` : `setup.preset.${kind}_text`)));
    presetNotes(kind, kst, measure, newOnly).forEach((n) => c.appendChild(n));
    const pick = (e) => {
      if (off || (e && e.target && e.target.closest && e.target.closest('a, button'))) return;   // its links and buttons keep their click
      onPick(kind, c);
    };
    c.onclick = pick;
    c.onkeydown = (e) => {
      if ((e.key === 'Enter' || e.key === ' ') && e.target === c) { e.preventDefault(); pick(e); }
    };
    g.appendChild(c);
  });
  const wrap = el('div', 'bk-preset-wrap');
  wrap.appendChild(g);
  const common = presetCommon();
  if (common) wrap.appendChild(common);
  return wrap;
}

/** A card picked on the page (a new server: for everything): without changes it starts at once, otherwise it asks first */
function presetPick(kind, card) {
  if (kind === presetNow() && !presetChanged()) return;           // that is the default already
  const go = () => {
    presetChoose(kind, false);
    Office.toast(T('setup.preset.done', { name: T('setup.preset.' + kind) }));
    Office.keepInPlace(card, () => renderSetup());
  };
  if (!setup.plan.have_settings && !presetChanged()) { go(); return; }
  Office.dialog({
    title: T('setup.preset.confirm_title'),
    body: T(setup.plan.have_settings ? 'setup.preset.replace' : 'setup.preset.replace_new'),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('setup.preset.confirm_go'), kind: '', act: () => { go(); return true; } }],
  });
}

/** On a new server: the default's cards at the top of the page, my proposal chosen until another one is */
function presetSection() {
  const s = section(T('setup.preset.title'), T('setup.preset.sub'), { place: 'setup.preset.title' });
  s.classList.add('bk-preset-section');
  s.appendChild(presetCards(presetNow(), presetPick, true, false));
  return s;
}

/** With settings applied: a default chosen here (or one at work on new things) and what goes to Kopia for the first time */
function presetStartLine() {
  if (!setup.draft || !(setup.choice || (presetNow() !== 'auto' && setup.newItems.size > 0))) return null;
  const out = el('div', 'bk-start');
  const text = presetStartText();
  if (text) out.appendChild(el('p', 'role', text));
  if (presetNow() === 'kopia') {
    const lines = uploadLines(firstUpload(draftMode, draftVm), true);
    if (lines.length) {
      const box = el('div', 'callout bk-upload');
      lines.forEach((l) => box.appendChild(l));
      out.appendChild(box);
    }
  }
  return out.childNodes.length ? out : null;      // «only what is new» with «Automatic» and nothing changed: nothing to say
}

/** Step 0 (engine 2.31, with settings): the default for new things, «change …» opens it; Kopia off under «local + Kopia» said */
function presetLine(plan) {
  if (plan.preset_new === undefined || !plan.have_settings || !setup.draft) return null;
  const kind = presetNow();
  const p = el('p', 'role bk-preset-line', kind === 'auto' ? T('setup.preset.line_auto') : T('setup.preset.line', { name: T('setup.preset.' + kind) }));
  const b = button(T('setup.preset.change') + ' …', 'small plain', presetDialog);
  b.disabled = !!(setup.status && setup.status.running);
  p.append(' ', b);
  if (kind === 'kopia' && dget('kopia|enabled') !== 'yes') p.appendChild(el('span', 'bk-preset-warn', ' ' + T('setup.preset.kopia_off_new')));
  return p;
}

/**
 * A new row's chip (engines 2.21/2.31): «new — please decide» while there is no default, or when the default leaves it
 * to the user (`ask`: 'big' - a big new share under «local + Kopia» -, 'keep' - a share the engine never backs up on its
 * own), else «new — default: … — change?» - a click opens the default
 */
function newChip(ask) {
  const kind = presetNow();
  if (kind === 'auto' || ask) return chip(T('setup.new_chip'), 'accent', T(ask === 'big' ? 'setup.new_ask_hint' : 'setup.new_chip_hint'));
  const b = el('button', 'chip accent bk-newchip', T('setup.new_chip_preset', { name: T('setup.preset.' + kind) }));
  b.type = 'button';
  b.title = T('setup.new_chip_hint_' + kind);
  b.onclick = (e) => { e.stopPropagation(); presetDialog(); };
  return b;
}
/** A big new share's size beside «please decide» under «local + Kopia» */
const newSizeChip = (sh) => chip(sh.gb === null || sh.gb === undefined ? T('setup.new_size_unknown') : T('setup.new_big', { size: sh.gb < 0 ? '> ?' : fmt.size(sh.gb * 1073741824) }),
  'warn', T('setup.new_ask_hint'));

/** «Apply to everything now» / «Only to what is new — my settings stay» (a server with settings) */
function presetScope(newOnly, onChange) {
  const f = el('div', 'field bk-preset-scope');
  const opts = el('div', 'bk-asleep-opts');
  f.appendChild(opts);
  ['all', 'new'].forEach((o) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'bk-preset-scope';
    input.checked = (o === 'new') === newOnly;
    input.onchange = () => onChange(o === 'new');
    const span = el('span', '', T('setup.preset.scope_' + o));
    span.appendChild(el('small', '', T('setup.preset.scope_' + o + '_hint')));
    label.append(input, span);
    opts.appendChild(label);
  });
  return f;
}

/** With settings applied: the default in a dialog - for what is new only (the settings stay) or for everything now */
function presetDialog() {
  if (!setup.plan || !setup.draft) return;
  let pick = presetNow();
  let newOnly = !!setup.plan.have_settings;
  const box = el('div', 'bk-preset-dialog');
  const draw = (focus) => {
    box.innerHTML = '';
    if (setup.plan.have_settings) box.appendChild(presetScope(newOnly, (v) => { newOnly = v; draw(); }));
    if (!newOnly) box.appendChild(el('p', 'callout', T('setup.preset.replace')));
    box.appendChild(presetCards(pick, (kind) => { pick = kind; draw(kind); }, false, newOnly));
    if (focus) box.querySelector(`[data-preset="${focus}"]`).focus();
  };
  draw();
  Office.dialog({
    title: T('setup.preset.dialog_title'),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('setup.preset.go'), kind: '', act: () => {
        presetChoose(pick, newOnly);
        Office.toast(T(newOnly ? 'setup.preset.done_new' : 'setup.preset.done', { name: T('setup.preset.' + pick) }));
        renderSetup();
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
  const proposals = fresh ? 0 : setupChanges(setupSaved(), setup.base || setup.plan.P).length;
  const busy = setup.status && setup.status.running;
  if (!edits && !fresh && proposals <= 0) { Office.selbar(null); return; }      // nothing to apply: no bar that keeps offering it
  const chosen = presetChosen();               // a start of the user's: what it changes is the user's, not proposals of mine
  Office.selbar({
    title: edits ? T('setup.bar_changes', { n: edits }) : fresh ? T('setup.bar_new')
      : T(chosen ? 'setup.bar_changes' : 'setup.bar_proposals', { n: proposals }),
    sub: [chosen ? presetStartText() : '', T('setup.bar_sub', { when: fmt.relative(setup.plan.time) })].filter(Boolean).join(' · '),
    buttons: [
      { text: T('setup.discard'), kind: 'plain', disabled: !edits || busy, act: () => { setupDraftFromPlan(); renderSetup(); } },
      { text: T('setup.review'), disabled: busy || !Office.agent.running, act: setupApply },   // opens the list of changes; its dialog applies
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
  // with settings applied, the three starts are a quiet entry here (on a new server they stand at the top of the page)
  const preset = button(T('setup.preset.open') + ' …', 'plain', presetDialog);
  const busy = setup.status && setup.status.running;
  [again, measure, forget].forEach((b) => { b.disabled = busy || !canPlan(); });
  preset.disabled = busy || !setup.draft;
  measure.title = T('setup.measure_hint');
  forget.title = T('help.forget');
  preset.title = T('setup.preset.sub');
  forget.hidden = !(setup.plan && setup.plan.have_settings);
  preset.hidden = forget.hidden;
  const pageSub = setup.plan ? T(setup.plan.have_settings ? 'setup.sub_have' : 'setup.sub_new') : '';
  const { head } = Office.deskHead(Office.desks.get(ID), { page: T('setup.page'), pageSub, actions: [back, again, measure, preset, forget] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('setup.preset.title'), T('help.preset_text')],
    [T('help.draft'), T('help.draft_text')],
    [T('setup.replan'), T('help.replan')],
    [T('setup.measure'), T('setup.measure_hint')],
    [T('help.reasons'), T('help.reasons_text')],
    [T('setup.more'), T('help.details')],
    [T('help.new'), T('help.new_text')],
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
    p.append(el('span', 'spin'), ' ', T(mode === 'apply' ? 'setup.applying_long' : mode === 'forget' ? 'setup.forgetting' : 'setup.planning_long'));
    root.appendChild(p);
  }
  if (!setup.plan) { setupBar(); return; }

  const plan = setup.plan;
  const start = plan.have_settings ? presetStartLine() : presetSection();   // a new server: where to start, the three cards;
  if (start) root.appendChild(start);                                       // set up: which start the draft came from, once chosen
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
    const row = setup.focus === 'waiting' ? root.querySelector('.bk-isnew')
      : [...root.querySelectorAll('[data-share], [data-focus]')].find((r) => (r.dataset.focus || r.dataset.share) === setup.focus);
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

/**
 * «also to <partner>» (engine 2.27): a toggle per partner this office sends to (the plan's partners[], from the Team
 * Lead's pairs) for a share's, a VM's or the backup place's partner list `key` - several allowed. Only a ZFS dataset of
 * its own travels: `ok` false disables them with the engine's reason; `off` (not backed up) disables them too.
 */
function partnerChips(key, ok, why, off) {
  const partners = (setup.plan && setup.plan.partners) || [];
  if (!partners.length) return null;
  const wrap = el('div', 'bk-partners');
  const on = dget(key, []) || [];
  partners.forEach((p) => {
    const sel = on.includes(p.id) && ok && !off;
    const b = el('button', 'chip bk-pchip' + (sel ? ' accent' : ''), (sel ? '✓ ' : '+ ') + T('partner.also', { name: p.name }));
    b.type = 'button';
    b.setAttribute('aria-pressed', String(sel));
    if (!ok) { b.disabled = true; b.title = T('partner.why.' + (why || 'no_dataset')); }
    else if (off) { b.disabled = true; b.title = T('partner.off_hint'); }
    else b.title = T(sel ? 'partner.also_on_hint' : 'partner.also_hint', { name: p.name });
    b.onclick = () => {
      const now = dget(key, []) || [];
      dset(key, now.includes(p.id) ? now.filter((x) => x !== p.id) : [...now, p.id]);
      Office.keepInPlace(b, () => renderSetup());
    };
    wrap.appendChild(b);
  });
  return wrap;
}

/** The units the draft sends to a partner, as Apply will write them: ticked, a dataset of its own, backed up */
function partnerUnits(draft, id) {
  const plan = setup.plan;
  const mine = draft === setup.draft;
  const out = [];
  const place = draft['general|dumps_share'] || '';
  const placeRow = plan.shares.find((x) => x.name === place);
  if ((draft['general|partner_place'] || []).includes(id) && placeRow && placeRow.partner_ok) out.push('place');
  plan.shares.forEach((x) => {
    if (x.name !== place && x.partner_ok && (draft[`share|${x.name}|partner`] || []).includes(id) && (draft[`share|${x.name}|mode`] || 'off') !== 'off') out.push('share:' + x.name);
  });
  (plan.vms || []).forEach((v) => {
    const on = mine ? levelOf('vm:' + v.name) > 0 : (draft[`vm|${v.name}|mode`] || 'snapshot') !== 'off';
    if (v.partner_ok && on && (draft[`vm|${v.name}|partner`] || []).includes(id)) out.push('vm:' + v.name);
  });
  return out;
}

/** The apply dialog's group «To partners»: per partner what goes there - and what is new or no longer, against the saved settings */
function partnerApplyLines() {
  const partners = setup.plan.partners || [];
  const saved = setup.plan.have_settings ? setupSaved() : null;
  return partners.map((p) => {
    const now = partnerUnits(setup.draft, p.id);
    let text = now.length ? now.map(unitLabel).join(', ') : T('partner.apply_none');
    if (saved) {
      const before = partnerUnits(saved, p.id);
      const plus = now.filter((u) => !before.includes(u));
      const minus = before.filter((u) => !now.includes(u));
      const diff = [plus.length ? T('partner.apply_added', { list: plus.map(unitLabel).join(', ') }) : '',
        minus.length ? T('partner.apply_removed', { list: minus.map(unitLabel).join(', ') }) : ''].filter(Boolean).join(' · ');
      if (diff) text += ` (${diff})`;
    }
    return [T('partner.apply_to', { name: p.name }), text];
  });
}

function setupKopia(plan) {
  const k = plan.kopia;
  const s = Office.place('setup.kopia', setupSection(T('setup.kopia'), T('setup.kopia_sub')));
  const basics = el('div', 'bk-form');
  basics.appendChild(Office.place('setup.ds_label', dumpsShareField(plan)));
  const flashOpts = plan.flash.dataset ? ['snapshot', 'tar', 'off'] : ['tar', 'off'];
  basics.appendChild(Office.place('setup.g_flash', field(flashLabel(plan), selectInput('flash|mode', flashOpts, (o) => T('setup.flash.' + o)),
    plan.flash.dataset ? T('setup.g_flash_zfs', { ds: plan.flash.dataset }) : T('setup.g_flash_other', { fs: plan.flash.fs || '?' }))));
  const asleep = asleepChoice(plan);
  if (asleep) basics.appendChild(asleep);
  s.appendChild(basics);
  const pl = presetLine(plan);              // engine 2.31: the default for new things
  if (pl) s.appendChild(pl);
  // engine 2.27: the partner offices this office sends to (paired at the Team Lead) - and the backup place to them too
  if ((plan.partners || []).length) {
    const line = el('p', 'role bk-partners-line', T('partner.setup_line', { names: plan.partners.map((p) => p.name + (p.key ? '' : ` (${T('partner.no_key')})`)).join(', ') }));
    s.appendChild(line);
    const ds = dget('general|dumps_share', '');
    const row = plan.shares.find((x) => x.name === ds);
    if (row) {
      const chips = partnerChips('general|partner_place', row.partner_ok, row.partner_why, dget(`share|${ds}|mode`, 'off') === 'off');
      if (chips) {
        chips.prepend(el('span', 'role', T('partner.place')));
        s.appendChild(chips);
      }
    }
  }
  s.appendChild(placeGuide(plan));
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

/**
 * Engine 2.28: a pool (or disk) that sleeps at the run's time - woken for its snapshot as before, or left asleep: its
 * shares are left out that night. One honest sentence each; what sleeps right now from the plan's look at disks.ini.
 * Only with an engine that knows the key (the plan carries asleep_pools).
 */
function asleepChoice(plan) {
  if (plan.asleep_pools === undefined || !plan.bases.some((b) => b.fs === 'zfs' || b.fs === 'btrfs')) return null;
  const f = el('div', 'field bk-asleep');
  f.appendChild(el('span', 'field-title', T('setup.asleep.label')));
  const opts = el('div', 'bk-asleep-opts');            // the radios apart: «.field > label» is the field's title style
  f.appendChild(opts);
  const cur = dget('general|asleep_pools', 'wake') === 'skip' ? 'skip' : 'wake';
  ['wake', 'skip'].forEach((o) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'bk-asleep';
    input.checked = cur === o;
    input.onchange = () => { dset('general|asleep_pools', o); Office.keepInPlace(input, () => renderSetup()); };
    const span = el('span', '', T('setup.asleep.' + o));
    span.appendChild(el('small', '', T('setup.asleep.' + o + '_hint', { n: plan.asleep_nights || 7 })));
    label.append(input, span);
    opts.appendChild(label);
  });
  const now = [...new Set([...plan.shares, ...(plan.vms || [])].flatMap((x) => x.asleep_bases || []))].sort();
  if (now.length) f.appendChild(el('small', '', T('setup.asleep.now', { names: now.join(', ') })));
  return f;
}

/** «pool asleep now» on a share's or VM's row (engine 2.28, the plan's asleep_bases): what the night does with it, in its tip */
function asleepChip(bases) {
  if (!bases || !bases.length) return null;
  const disk = bases.every((b) => /^disk\d+$/.test(b));
  return chip(T(disk ? 'setup.asleep.chip_disk' : 'setup.asleep.chip'), '',
    T(dget('general|asleep_pools', 'wake') === 'skip' ? 'setup.asleep.chip_skip' : 'setup.asleep.chip_wake', { names: bases.join(', ') }));
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
  const s = Office.place('setup.shares', setupSection(T('setup.shares'), T('setup.shares_sub')));
  const kopiaOn = dget('kopia|enabled') === 'yes';
  const waits = waitingFolders();
  const wrap = el('div', 'box table-wrap');
  const table = el('table', 'grid bk-shares');
  const hr = el('tr');
  ['share', 'where', 'size', 'mode', 'why', ''].forEach((c) => hr.appendChild(el('th', '', c ? T('setup.col.' + c) : '')));
  const thead = el('thead'); thead.appendChild(hr); table.appendChild(thead);
  const body = el('tbody');
  plan.shares.forEach((sh) => {
    const tr = el('tr');
    tr.dataset.share = sh.name;
    const nameCell = el('th', '', sh.name);
    // new since the last setup (engine 2.31: the default decides it - a big one under «local + Kopia» waits for the user)
    if (setup.newItems.has('share:' + sh.name)) {
      const keep = !!presetKeep(sh, plan);                 // one the engine never backs up on its own: as the plan says
      const big = !keep && presetNewAsk(sh, presetNow());
      nameCell.append(' ', newChip(keep ? 'keep' : big ? 'big' : null));
      if (big) nameCell.append(' ', newSizeChip(sh));
    }
    tr.appendChild(nameCell);
    const whereCell = el('td', '', sh.where === '-' ? '' : sh.where);
    const ac = asleepChip(sh.asleep_bases);
    if (ac) whereCell.append(' ', ac);
    tr.appendChild(whereCell);
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
    if (sh.exists) {
      const isPlace = sh.name === dget('general|dumps_share', '');
      const pc = partnerChips(isPlace ? 'general|partner_place' : `share|${sh.name}|partner`, !!sh.partner_ok, sh.partner_why, dget(`share|${sh.name}|mode`, 'off') === 'off');
      if (pc) modeCell.appendChild(pc);
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
    // its new folders: only local so far, waiting for a decision (engine 2.21)
    const ws = waits.filter((w) => w.share === sh.name);
    if (ws.length) {
      const wtr = el('tr', 'bk-detail');
      const td = el('td');
      td.colSpan = 6;
      td.appendChild(waitingBox(ws));
      wtr.appendChild(td);
      body.appendChild(wtr);
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

// ---- what is new (engine 2.21): only local and kept running until the user decides here
/**
 * The new folders (the plan's shares[].waiting) of the shares that go to Kopia in the draft, each with the app or
 * VM it belongs to - then that one's level decides; the others the user decides: only local or local + Kopia
 */
function waitingFolders() {
  const m = setup.model;
  if (!m || dget('kopia|enabled') !== 'yes') return [];
  const out = [];
  (setup.plan.shares || []).forEach((sh) => {
    if (!(sh.waiting || []).length || dget(`share|${sh.name}|mode`) !== 'kopia') return;
    sh.waiting.forEach((wf) => {
      const app = m.apps.find((a) => a.folders.some((f) => f.share === sh.name && f.dir === wf.dir));
      const vm = app ? null : m.vms.find((x) => x.folders.some((f) => f.share === sh.name && f.dir === wf.dir));
      const owner = app ? { name: app.name, key: 'app:' + app.id, step: 2 } : vm ? { name: vm.name, key: 'vm:' + vm.name, step: 1 } : null;
      out.push({ share: sh.name, dir: wf.dir, bytes: wf.bytes, since: wf.first_seen, owner });
    });
  });
  return out;
}
/** A folder as a part of a Kopia rule: what Kopia reads as a pattern becomes ? (like the engine's new_rule_name) */
const ruleName = (dir) => dir.replace(/[*?[\]\\]/g, '?');
/** local | kopia | null (not decided in the draft) */
function waitChoice(w) {
  const known = dget(`share|${w.share}|kopia_known`, []) || [];
  const ign = dget(`share|${w.share}|kopia_ignore`, []) || [];
  if (known.includes(`/${w.dir}/`)) return 'kopia';
  if (ign.includes(`/${w.dir}/`) || ign.includes(`/${ruleName(w.dir)}/`)) return 'local';
  return null;
}
/** only local = a Kopia ignore rule; local + Kopia = recorded in kopia_known */
function waitSet(w, choice) {
  const kk = `share|${w.share}|kopia_known`;
  const ki = `share|${w.share}|kopia_ignore`;
  const rules = [`/${w.dir}/`, `/${ruleName(w.dir)}/`];
  const known = (dget(kk, []) || []).filter((x) => x !== rules[0]);
  const ign = (dget(ki, []) || []).filter((x) => !rules.includes(x));
  if (choice === 'kopia') known.push(rules[0]);
  else ign.push(rules[1]);
  dset(kk, known);
  dset(ki, ign);
}
const waitHow = (w) => (w.owner ? T('setup.waiting_follows', { name: w.owner.name, level: T('setup.level.' + levelOf(w.owner.key)) })
  : T('setup.waiting_' + (waitChoice(w) || 'local')));

/** Step 3, under a share: its new folders, waiting for a decision - only local so far */
function waitingBox(ws) {
  const box = el('div', 'bk-waiting bk-isnew');
  const head = el('div', 'role', T('setup.waiting_head'));
  if (presetNow() !== 'auto') head.append(' ', newChip());      // engine 2.31: the default decided them - Apply takes them in so
  box.appendChild(head);
  ws.forEach((w) => {
    const row = el('div', 'bk-waitrow');
    const name = el('span', 'mono', `/${w.dir}/`);
    const meta = [w.bytes ? fmt.size(w.bytes) : '', w.since ? T('setup.waiting_since', { when: fmt.relative(w.since) }) : ''].filter(Boolean).join(' · ');
    row.append(name);
    if (meta) row.appendChild(el('small', 'role', meta));
    if (w.owner) {
      row.appendChild(chip(waitHow(w), '', T('setup.waiting_follows_hint', { step: w.owner.step })));
    } else {
      const now = waitChoice(w) || 'local';
      const g = el('div', 'bk-seg');
      g.setAttribute('role', 'radiogroup');
      ['local', 'kopia'].forEach((c) => {
        const b = el('button', c === now ? 'on' : '', T('setup.waiting_' + c));
        b.type = 'button';
        b.setAttribute('role', 'radio');
        b.setAttribute('aria-checked', String(c === now));
        b.title = T('setup.waiting_' + c + '_hint');
        b.onclick = () => {
          if (c === now) return;
          waitSet(w, c);
          Office.keepInPlace(g, () => renderSetup());
        };
        g.appendChild(b);
      });
      row.appendChild(g);
    }
    box.appendChild(row);
  });
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
    // new since the last setup (engine 2.21: not in [docker] known): only local and keeps running until decided
    const fresh = !!plan.have_settings && a.members.every((c) => c.why === 'new');
    const newMembers = plan.have_settings && !fresh ? a.members.filter((c) => c.why === 'new').map((c) => c.name) : [];
    return {
      ...a, members, folders, deps: [...deps.values()], isNew: fresh, newMembers,
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
    return { name: v.name, v, own: v.own.length > 0, shares: [...new Set(disks.map((d) => d.share))], folders,
      isNew: !!plan.have_settings && v.why === 'new' };
  });
  // the new ones first in their step: they wait for a decision
  apps.sort((x, y) => (y.isNew - x.isNew));
  vms.sort((x, y) => (y.isNew - x.isNew));
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
    if (fresh || a.isNew) l = Math.min(l, 1);           // new: never Kopia unasked
    setup.levels['app:' + a.id] = l;      // an app's further shares are never ticked unasked (they grow fast)
    // during the snapshot: the engine's proposal - media servers and apps without changing data keep running
    setup.held[a.id] = a.members.every((n) => nostop.includes(n) || skip.includes(n)) ? 'run' : 'stop';
  });
  m.vms.forEach((x) => {
    const k = (y) => `vm|${x.name}|${y}`;
    const shareOff = x.shares.some((sh) => dget(`share|${sh}|mode`, 'off') === 'off');
    // prepare none: "not" for a VM sharing its dataset (it isn't held); in a dataset of its own it can be
    // local and keep running (engine 2.21 proposes that for a new VM) - "not" is mode off there
    const unheld = dget(k('prepare')) === 'none' && !x.own;
    let l = shareOff || dget(k('mode')) === 'off' || unheld ? 0 : onKopia(x.folders) || dget(k('kopia')) === 'yes' ? 2 : 1;
    if (fresh || x.isNew) l = Math.min(l, 1);
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
    else if (!dget(k('prepare')) || (dget(k('prepare')) === 'none' && !x.own)) dset(k('prepare'), ['yes', 'channel'].includes(x.v.agent) ? 'freeze' : 'pause');
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
      // only local: no longer among the folders that go to Kopia (engine 2.21)
      const kk = `share|${share}|kopia_known`;
      if (l < 2 && (dget(kk) || []).includes(rule)) dset(kk, dget(kk).filter((x) => x !== rule));
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
  const s = Office.place('setup.vms', setupSection(T('setup.vms'), T('setup.vms_sub')));
  if (!plan.vm_service) { s.appendChild(el('p', 'empty', T('setup.vm_service_off'))); return s; }
  const vms = setup.model.vms;
  if (!vms.length) { s.appendChild(el('p', 'empty', T('setup.vm_none'))); return s; }
  const list = el('div', 'box');
  vms.forEach((x) => {
    const v = x.v;
    const k = (y) => `vm|${v.name}|${y}`;
    const l = levelOf('vm:' + v.name);
    const row = el('div', 'row nocheck bk-vm' + (x.isNew ? ' bk-isnew' : ''));
    row.dataset.focus = 'vm:' + v.name;
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', v.name));
    const meta = el('div', 'row-meta');
    if (x.isNew) meta.appendChild(newChip());
    meta.appendChild(el('span', '', T(v.state === 'running' ? 'setup.vm_running' : 'setup.vm_off')));
    meta.appendChild(chip(T('setup.vm_agent.' + v.agent), v.agent === 'yes' ? 'ok' : '', T('setup.vm_agent_hint.' + v.agent)));
    if (v.tpm) meta.appendChild(chip(T('setup.vm_tpm'), '', T('setup.vm_tpm_hint')));
    if (v.hostdev) meta.appendChild(chip(T('setup.vm_gpu', { n: v.hostdev }), '', T('setup.vm_gpu_hint')));
    const vac = asleepChip(v.asleep_bases);
    if (vac) meta.appendChild(vac);
    if (v.snap !== 'yes') meta.appendChild(chip(T('setup.vm_cannot.' + v.snap), 'danger', T('setup.vm_cannot_hint')));
    else if (x.own) meta.appendChild(el('span', 'mono', v.own.join(', ')));
    else meta.appendChild(chip(T('setup.vm_shared'), '', T('setup.vm_shared_hint')));
    // a VM's first upload to Kopia reads its disk files whole - a sparse vdisk's holes as zeros (engine 2.26: the
    // plan's `apparent` beside `bytes`, what the files take); the chip says what, its tip why
    if (v.apparent > 0) {
      meta.appendChild(chip(T('setup.vm_upload', { size: fmt.size(v.apparent) }), '',
        T('setup.vm_upload_hint', { size: fmt.size(v.apparent), used: fmt.size(v.bytes || 0) })));
    }
    main.appendChild(meta);
    if (l === 0 && !x.own && x.shares.some((sh) => dget(`share|${sh}|mode`, 'off') !== 'off')) {
      main.appendChild(el('div', 'row-meta', T('setup.vm_off_shared', { share: x.shares.join(', ') })));
    }
    const pc = partnerChips(k('partner'), !!v.partner_ok, v.partner_why, l === 0);
    if (pc) main.appendChild(pc);
    row.appendChild(main);

    const right = el('div', 'bk-right');
    // from "not" up: freeze or pause proposed again (a VM of its own could keep running at "not")
    right.appendChild(levelPick('vm:' + v.name, (nl) => { if (l === 0 && nl > 0) dset(k('prepare'), undefined); }));
    if (l > 0) {
      // keep running (none): only in a dataset of its own - sharing one, it would be "not" (engine 2.21: a new VM's proposal)
      const prep = selectInput(k('prepare'), x.own ? ['freeze', 'pause', 'shutdown', 'none'] : ['freeze', 'pause', 'shutdown'], (o) => T('setup.vm_prep.' + o));
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
  const s = Office.place('setup.apps', setupSection(T('setup.apps'), T('setup.apps_sub')));
  const m = setup.model;
  if (!m.apps.length && !m.skipped.length) { s.appendChild(el('p', 'empty', T('setup.apps_none'))); return s; }
  const shareOf = (n) => plan.shares.find((x) => x.name === n);
  const list = el('div', 'box');
  let head = null;
  m.apps.forEach((a) => {
    const kind = a.isNew ? 'new' : a.stack ? 'stacks' : 'single';      // the new ones first: they wait for a decision
    if (head !== kind) { list.appendChild(el('div', 'bk-subhead', T('setup.apps_' + kind))); head = kind; }
    const l = levelOf('app:' + a.id);
    const row = el('div', 'row nocheck bk-app' + (a.isNew ? ' bk-isnew' : ''));
    row.dataset.focus = 'app:' + a.id;
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', a.name));
    const meta = el('div', 'row-meta');
    if (a.isNew) meta.appendChild(newChip());
    if (a.newMembers.length) meta.appendChild(chip(T('setup.new_members', { list: a.newMembers.join(', ') }), 'accent', T('setup.new_members_hint')));
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
/** The shares the backup place may be (never appdata, system, domains - like the engine's dumps_share_problem) */
const placeShares = (plan) => plan.shares.map((x) => x.name).filter((n) => !['appdata', 'system', 'domains'].includes(n.toLowerCase()));

/**
 * What speaks against the share chosen as the backup place (the agent's backupPlaceFacts(): same pool as
 * appdata, no snapshots, a secondary storage, no redundancy) - warnings only, the choice stays the user's
 */
function placeLines(sh) {
  if (!sh) return [];
  const place = sh.place || (sh.method === 'live' ? [{ code: 'no_history', where: '' }] : []);
  const where = (w) => w || T('setup.place_array');
  return place.map((p) => {
    if (p.code === 'same_pool') return { warn: true, text: T('setup.place_same_pool', { where: p.where }) };
    if (p.code === 'no_history') return { warn: true, text: p.where ? T('setup.place_no_history', { where: p.where }) : T('setup.ds_no_history') };
    if (p.code === 'secondary') return { warn: true, text: T('setup.place_secondary', { where: where(p.where) }) };
    if (p.code === 'no_redundancy') return { warn: false, text: T('setup.place_no_redundancy', { where: where(p.where) }) };
    return null;
  }).filter(Boolean);
}

/** Mr. Backupsy's word above the guide while no backup place is chosen: none to choose (a fresh server: only isos), or choose one */
function placeIntro(plan, ds) {
  if (ds) return null;
  return placeShares(plan).some((n) => n.toLowerCase() !== 'isos') ? 'setup.place_choose' : 'setup.place_none';
}

/**
 * What matters for the backup place (Benj's five points) and the way to a new share in Unraid (Shares → Add
 * Share, same tab) - the office never creates it, the user decides where it lies; open while none is chosen
 */
function placeGuide(plan) {
  const ds = dget('general|dumps_share', '');
  const box = el('div', 'bk-place');
  const intro = placeIntro(plan, ds);
  if (intro === 'setup.place_none') box.appendChild(el('p', 'callout warn', T('setup.place_none')));
  else if (intro) box.appendChild(el('p', 'callout warn', T('setup.place_choose')));
  const det = el('details', 'bk-place-guide');
  det.open = !ds;
  det.appendChild(el('summary', '', T('setup.place_title')));
  const ol = el('ol');
  [T('setup.place_1'), T('setup.place_2'), T('setup.place_3'), T('setup.place_4'), T('setup.place_5')].forEach((t) => ol.appendChild(el('li', '', t)));
  det.appendChild(ol);
  const add = el('p', 'bk-place-add');
  const a = el('a', 'btn small', T('setup.place_add'));
  a.href = Office.safeHref('/Shares/Share?name=');
  add.append(a, ' ', T('setup.place_name', { name: OFFICE_SHARE }));
  det.appendChild(add);
  det.appendChild(el('small', '', T('setup.place_back')));
  box.appendChild(det);
  return box;
}

/** The backup place: its own share for the packages of apps and VMs — never appdata; required */
function dumpsShareField(plan) {
  const shares = placeShares(plan);
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
  };
  sel.onchange = () => { dset('general|dumps_share', sel.value || undefined); setupDerive(); Office.keepInPlace(sel, () => renderSetup()); };
  update();
  const f = field(T('setup.ds_label'), sel);
  f.appendChild(hint);
  // what speaks against it (another pool than appdata, snapshots, one place, redundancy): said, never blocked
  placeLines(plan.shares.find((x) => x.name === sel.value)).forEach((l) => f.appendChild(el('small', l.warn ? 'missing' : '', l.text)));
  f.appendChild(el('small', '', T('setup.ds_why')));
  return f;
}

function setupGeneral(plan) {
  const s = Office.place('setup.general', setupSection(T('setup.general'), T('setup.general_sub')));
  const box = el('div', 'bk-form');
  const zfs = plan.bases.some((b) => b.fs === 'zfs');
  const btrfs = plan.bases.some((b) => b.fs === 'btrfs');
  // like the engine's snap_prefix_ok(): words joined by -, ends with -; never Ms. Snapshotini's uso-plan- / auto-
  if (zfs) box.appendChild(Office.place('setup.g_prefix', field(T('setup.g_prefix'), textInput('general|snap_prefix', /^(?!uso-plan-|auto-)[a-z0-9_]+(?:-[a-z0-9_]+)*-$/), T('setup.g_prefix_hint'))));
  if (btrfs) {
    box.appendChild(Office.place('setup.g_btrfs_free', field(T('setup.g_btrfs_free'), textInput('btrfs|min_free_gb', /^\d+$/), T('setup.g_btrfs_free_hint'))));
    // a snapshot needs the disk awake: with every disk, those that sleep at night are woken for it (Benj, 2026-10-07)
    const disks = plan.bases.filter((b) => b.fs === 'btrfs' && b.kind === 'disk').map((b) => b.name);
    box.appendChild(Office.place('setup.g_btrfs_all', checkbox(T('setup.g_btrfs_all'), dget('btrfs|snapshot_all') === 'yes', (v) => dset('btrfs|snapshot_all', v ? 'yes' : 'no'),
      disks.length ? T('setup.g_btrfs_all_wake', { n: disks.length, names: disks.join(', ') }) : '')));
  }
  if (plan.P['libvirt|mode'] !== undefined) {
    box.appendChild(Office.place('setup.g_libvirt', field(T('setup.g_libvirt'), selectInput('libvirt|mode', ['tar', 'off'], (o) => T('setup.libvirt.' + o)), T('setup.g_libvirt_hint'))));
  }
  box.appendChild(Office.place('setup.g_notify', checkbox(T('setup.g_notify'), dget('general|notify_success') === 'yes', (v) => dset('general|notify_success', v ? 'yes' : 'no'))));
  if (dget('kopia|enabled') === 'yes') {
    box.appendChild(Office.place('setup.p_compression', field(T('setup.p_compression'), textInput('kopia|compression', /^[a-z0-9-]+$/), T('setup.p_compression_hint'))));
    box.appendChild(Office.place('setup.p_ignore', field(T('setup.p_ignore'), listInput('kopia|ignore', 4), T('setup.p_ignore_hint'))));
  }
  s.appendChild(box);
  return s;
}

/** Step 4: two rows of numbers - how long local snapshots stay, how long Kopia keeps its states */
function setupRetention(plan) {
  const s = Office.place('setup.retention', setupSection(T('setup.retention'), T('setup.retention_sub')));
  const box = el('div', 'bk-form');
  if (plan.bases.some((b) => b.fs === 'zfs')) box.appendChild(Office.place('setup.g_zfs', field(T('setup.g_zfs'), textInput('zfs|retention', /^\d+ \d+ \d+$/), T('setup.g_zfs_hint'))));
  if (plan.bases.some((b) => b.fs === 'btrfs')) box.appendChild(Office.place('setup.g_btrfs_days', field(T('setup.g_btrfs_days'), textInput('btrfs|keep_days', /^\d+$/))));
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
  const s = Office.place('setup.sources', setupSection(T('setup.sources'), T('setup.sources_sub')));
  const ul = el('ul', 'shortlist');
  old.forEach((o) => ul.appendChild(el('li', '', o.source)));
  s.appendChild(ul);
  s.appendChild(checkbox(T('setup.sources_manual'), setup.retire, (v) => { setup.retire = v; }, T('setup.sources_manual_hint')));
  return s;
}

function setupMessages(msgs) {
  const s = section(T('setup.messages'), T('setup.messages_sub'), { place: 'setup.messages' });
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
async function scheduleDialog() {
  if (!(await Office.freshState(ID))) return;
  const sc = (state && state.schedule) || {};
  if (!sc.script) {
    Office.dialog({
      title: T('schedule.title'),
      body: T('schedule.no_settings'),
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
      ends.textContent = T('schedule.duration', { duration: dur(took), end: fmt.time(end) });
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
      // an old plan doesn't know what changed since (a share made for the backup place, moved ones): read the server again
      if (setup.plan && Date.now() / 1000 - setup.plan.time > SETUP_REPLAN && canPlan() && !(setup.status && setup.status.running)) {
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

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys): the main
// page, then the setup's steps and fixed settings (#/backup/setup) — the rows per share, app and VM are a later phase
const SETUP = { route: '#/backup/setup', crumb: 'setup.page' };
Office.places(ID, [
  { kind: 'section', key: 'now' },
  { kind: 'section', key: 'overview' },
  { kind: 'section', key: 'protection' },
  { kind: 'section', key: 'history' },
  { kind: 'section', key: 'drift' },
  { kind: 'section', key: 'restore' },
  ...['setup.preset.title', 'setup.kopia', 'setup.vms', 'setup.apps', 'setup.shares', 'setup.retention', 'setup.general', 'setup.sources',
    'setup.messages'].map((key) => ({ kind: 'step', key, ...SETUP })),
  ...['setup.ds_label', 'setup.g_flash', 'setup.g_prefix', 'setup.g_btrfs_free', 'setup.g_btrfs_all', 'setup.g_libvirt', 'setup.g_notify',
    'setup.p_compression', 'setup.p_ignore', 'setup.g_zfs', 'setup.g_btrfs_days'].map((key) => ({ kind: 'setting', key, ...SETUP })),
  ...['run', 'results', 'protection', 'rules', 'vms', 'packages', 'items', 'waiting', 'buttons'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['setup_open', 'help.setup'], ['history', 'help.history'], ['drift', 'help.drift'], ['restore', 'help.restore']]
    .map(([key, text]) => ({ kind: 'help', key, text })),
  ...[['setup.preset.title', 'help.preset_text'], ['help.draft', 'help.draft_text'], ['setup.replan', 'help.replan'], ['setup.measure', 'setup.measure_hint'],
    ['help.reasons', 'help.reasons_text'], ['setup.more', 'help.details'], ['help.new', 'help.new_text'], ['setup.apply', 'help.apply'],
    ['setup.forget_short', 'help.forget']].map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

// tests/run.php runs the setup assistant's logic under node (Unraid's own) - never set in a browser
if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.backup = {
    get setup() { return setup; },
    setState: (s) => { state = s; },
    setupDraftFromPlan, setupNewLines, setupChanges, setupSaved, waitingFolders, waitChoice, waitSet, levelOf, waitingText,
    placeLines, placeIntro, setupDraftKeep, setupDerive, dset, setupEdits,
    PRESETS, presetChoose, presetForget, presetKeep, presetChanged, presetKopiaState, firstUpload, presetKeptList, presetStartText,
    presetKopiaMode, presetKopiaVm, draftMode, draftVm, setupNewItems, presetApplyNew, presetNow, presetChosen, presetNewMode, presetNewVm,
  };
}
})();
