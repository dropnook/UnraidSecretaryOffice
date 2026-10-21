/* Mr. Backupsy — runs the unraid-backup script: what it is doing right now and
   when it will be done, how the last nights went, which shares are protected
   how, what changed since the setup, and how to get things back.
   The agent part lives in agent/desks/backup.php, the engine in backup/. */
(() => {
'use strict';

const ID = 'backup';
const T = Office.scope(ID);
/** «{n} <noun>» from the lang key count.<what> — its one/other forms (several counts in one text are composed of these) */
const nOf = (what, n) => T('count.' + what, { n: Number(n) || 0 });
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
  const was = !!(state && state.running);
  if (j.ok && j.state) state = j.state;
  if (view && page === 'main') render();
  // 1.60.1 (a user: the tour «hung» on the setup page - its buttons grey, the bubble «still checking»): the setup page
  // follows the run too and is drawn anew once it ends
  else if (view && page === 'setup' && was !== !!(state && state.running)) renderSetup();
  schedule();
}

/** While a run is going on, look every few seconds - on both pages (the setup's buttons wait for it too) */
function schedule() {
  clearTimeout(liveTimer);
  if (view && (page === 'main' || page === 'setup') && state && (state.running || (state.setup && state.setup.running))) {
    liveTimer = setTimeout(() => {
      if (!document.hidden && !Office.dialogOpen() && !Office.menuOpen()) load(true);
      else schedule();
    }, LIVE_POLL);
  }
}

// ------------------------------------------------------------------ helpers
/** Durations, with seconds when it was quick */
const dur = (seconds) => (seconds < 60 ? `${Math.max(0, Math.round(seconds))}\u00a0s` : fmt.duration(seconds));   // «13 s» never broken apart

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

/** Why a run ended: known codes translated (engine 2.65: with the run's message_params - sizes as sizes - when the
 *  words need them, else the code's words without them: message.<code>_plain), the script's own words otherwise */
function messageText(m, params) {
  const code = String(m).replace(/^exit \d+$/, 'exit');
  if (!Office.has(`${ID}.message.${code}`)) return m;
  const p = { detail: m };
  Object.entries(params && typeof params === 'object' ? params : {}).forEach(([k, v]) => {
    p[k] = (k === 'free' || k === 'need') && /^\d+$/.test(String(v)) ? fmt.size(Number(v)) : String(v);
  });
  const raw = Office.strings[`${ID}.message.${code}`];
  const need = typeof raw === 'string' ? [...raw.matchAll(/\{(\w+)\}/g)].map((x) => x[1]) : [];
  if (need.every((k) => p[k] !== undefined)) return T('message.' + code, p);
  return Office.has(`${ID}.message.${code}_plain`) ? T(`message.${code}_plain`) : m;
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
  const m = /^(share|vm|app):(.*)$/.exec(u || '');
  return m ? (m[1] === 'vm' ? T('src.vm', { name: m[2] }) : m[1] === 'app' ? T('partner.unit_app', { name: m[2] }) : m[2]) : (u || '');
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
/** A run's mode as the card titles it: a --no-kopia run (engine 2.42: status.json skip_kopia) is «Backup without the offsite upload» */
const runModeOf = (s) => (s && s.mode === 'backup' && s.skip_kopia ? 'nokopia' : (s && s.mode) || 'backup');
/**
 * The transfer going on, as the agent reckons it (state.partners[].current: rate = the median of the last 10 minutes,
 * estimate from zfs send -nP, eta): «To vault: appdata — 1.2 of ~4.3 GB · 85 MB/s · about 1 min left (2 of 3 done)»
 */
function partnerRateOf(s) {
  const pc = s && s.partner && s.partner.current;
  if (!pc) return null;
  const mine = ((state && state.partners) || []).find((x) => x.id === pc.id);
  const c = mine && mine.current && mine.current.unit === pc.unit ? mine.current : null;
  return { bytes: pc.bytes || 0, estimate: (c && c.estimate) || pc.estimate || null, rate: c && typeof c.rate === 'number' ? c.rate : null,
    eta: c && typeof c.eta === 'number' ? c.eta : null };
}
function partnerNowText(s) {
  const pc = s.partner.current;
  const r = partnerRateOf(s) || { bytes: pc.bytes || 0, estimate: null, rate: null, eta: null };
  const base = { name: partnerName(pc.id, s.partner), unit: unitLabel(pc.unit), n: partnerDoneCount(s.partner), total: (s.partner.planned || []).length };
  const parts = [r.estimate ? T('partner.now_of', { ...base, done: fmt.size(r.bytes), size: fmt.size(r.estimate) }) : T('partner.now', { ...base, size: fmt.size(r.bytes) })];
  if (r.rate) parts.push(T('partner.rate', { rate: fmt.size(r.rate) }));
  if (r.eta !== null && r.rate) parts.push(r.eta < 60 ? T('partner.left_soon') : T('partner.left', { left: fmt.duration(Math.ceil(r.eta / 60) * 60) }));
  return parts.join(' · ');
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
/**
 * One run a minute (the agent refuses a second one in the minute the last one started: backupMinuteTaken() — the run's
 * name and its snapshots carry the minute). After an apply the agent starts its own plan + tour, and a click on «Tour»
 * right after met that refusal (Benj, 2026-10-12): the page knows the last run's start (status.json, any mode) and
 * says so before the click — {left: seconds until the minute changes, mode, started} while the last run started in this
 * minute and nothing runs now, else null. The agent's refusal stays the last guard (the clocks may differ by seconds).
 */
function minuteTaken(now = Date.now() / 1000) {
  const s = status();
  // 1.60.1 (a user: a tour, a plan or an apply made him wait): only a real backup holds its minute - its snapshots carry it
  if (!s || !(s.started > 0) || live() || ['check', 'dryrun'].includes(s.mode)) return null;
  const left = Math.ceil((Math.floor(s.started / 60) + 1) * 60 - now);
  // a browser behind the server's clock sees a start «ahead»: still this minute (up to two, anything further is no clock)
  return left > 0 && left <= 120 ? { left, mode: 'backup', started: s.started, at: (Math.floor(s.started / 60) + 1) * 60 } : null;
}
let minuteTimer = null;
/**
 * The buttons that start a run (the head's «Tour» and «Start a run…», the start dialog's button) grey while the minute
 * is taken, their tooltip counting the seconds down; at the minute's change they come back and the page is drawn again
 * (the bubble's «The tour just ran» goes). blocked() = every other reason they are grey.
 */
function minuteGuard(buttons, blocked = () => false, onFree = null) {
  clearInterval(minuteTimer);
  const tick = () => {
    const m = minuteTaken();
    // never grey for the minute (1.60.1): a backup asked for now starts at the next minute (the agent queues it)
    buttons.forEach((b) => { b.disabled = blocked(); b.title = m ? T('minute.queue_hint', { time: fmt.time(m.at) }) : ''; });
    return m;
  };
  if (!tick()) return;
  minuteTimer = setInterval(() => {
    if (tick()) return;
    clearInterval(minuteTimer);
    if (onFree) onFree();
  }, 1000);
}
/** The page drawn again at the minute's change (not under an open dialog: it keeps its own buttons) */
function minuteFree() {
  if (!view || Office.dialogOpen()) return;
  if (page === 'setup') renderSetup(); else render();
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
 * The rate of this run's offsite upload so far, for the very first backup's rough estimate (2026-10-15): the sources done
 * (their sizes, agent: state.rough, in their real seconds) and the one going up (what Kopia read since it started) —
 * before a minute of that, the rate measured on the one going up. Bytes/s, null while nothing was measured.
 */
function roughRate(s, u) {
  const sizes = (state.rough && state.rough.sizes) || {};
  let bytes = 0;
  let secs = 0;
  ((s.kopia && s.kopia.done) || []).forEach((d) => {
    if (d.ok && typeof sizes[d.name] === 'number' && d.seconds > 0) { bytes += sizes[d.name]; secs += d.seconds; }
  });
  if (u && typeof u.read === 'number' && u.time > u.since) { bytes += u.read; secs += u.time - u.since; }
  return secs >= 60 && bytes > 0 ? bytes / secs : (u && u.rate) || null;
}

/**
 * Where the run is and when it will be done, from the durations of earlier runs — except a source going
 * to Kopia for the first time: its own measure (size, rate), never "late". The very first backup (no good run
 * to go by: estimates.total null) gets a rough one in the Kopia step (`rough`): a source not started yet at its
 * size at this run's rate (roughRate()), the flash (never measured, a few MB) as nothing
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
  const firstRun = est.total === null || est.total === undefined;
  const sizes = (firstRun && state.rough && state.rough.sizes) || null;
  const rate = sizes ? roughRate(s, first) : null;
  let remaining = 0;
  let known = true;
  let overdue = false;

  // the very first backup: what is left of a source at its size and this run's rate (null: can't be told)
  const roughLeft = (name) => {
    if (!sizes) return null;
    if (name === 'flash' && typeof sizes[name] !== 'number') return 0;
    if (typeof sizes[name] !== 'number' || !rate) return null;
    const gone = name === s.kopia.current && s.kopia.current_since ? now - s.kopia.current_since : 0;
    return Math.max(0, sizes[name] / rate - gone);
  };
  const sourceLeft = (name) => {
    if (first && name === first.source) {
      const left = first.rate && first.size !== null && first.size !== undefined ? firstLeft(first) : roughLeft(name);
      if (left === null) known = false;
      return left || 0;
    }
    const e = est.sources[name];
    if (e === undefined || e === null) {
      const left = roughLeft(name);
      if (left === null) known = false;
      return left || 0;
    }
    if (name === s.kopia.current && s.kopia.current_since) {
      const elapsed = now - s.kopia.current_since;
      if (elapsed > e) overdue = true;
      return Math.max(0, e - elapsed);
    }
    return e;
  };

  // the partner step (engine 2.27; status.json partner.planned once the inventory is done): every planned transfer not made
  // yet at the seconds of its last one (agent: estimates.partner) — a unit never sent makes that step unknown, never 99 %
  // (2026-10-12); the rest still has its time (partnerUnknown, 2026-10-17). Before the plan (the inventory) the units the
  // partners got last time (status.json partner.partners from the run's first line, engine 2.45)
  const sp = s.partner && Array.isArray(s.partner.planned) ? s.partner : null;
  const pest = est.partner || {};
  const unitKey = (x) => `${x.id}|${x.unit}`;
  let partnerUnknown = false;
  const partnerLeft = () => {
    if (!sp) return 0;
    if (!sp.planned.length) {
      return (sp.partners || []).reduce((sum, x) => {
        const last = Object.keys(pest).filter((k) => k.startsWith(x.id + '|'));
        if (!last.length) partnerUnknown = true;
        return last.reduce((a, k) => a + (typeof pest[k] === 'number' ? pest[k] : 0), sum);
      }, 0);
    }
    const over = new Set([...(sp.done || []), ...(sp.skipped || []), ...(sp.failed || []), ...(sp.current ? [sp.current] : [])].map(unitKey));
    return sp.planned.reduce((sum, x) => {
      if (over.has(unitKey(x))) return sum;
      const e = pest[unitKey(x)];
      if (typeof e !== 'number') { partnerUnknown = true; return sum; }
      return sum + e;
    }, 0);
  };

  if (s.phase === 'partner') {
    // a unit on its way to a partner (engine 2.42): what is left of this transfer at the measured rate — before the rate,
    // its last transfer's seconds less the time it is on its way —, then the partner units after it, then Kopia's sources
    // — past that time without a rate: «longer than usual», never a 99 % that sits (2026-10-17)
    const pr = partnerRateOf(s);
    if (pr && pr.eta !== null && pr.rate) remaining += pr.eta;
    else if (sp && sp.current && typeof pest[unitKey(sp.current)] === 'number') {
      const left = pest[unitKey(sp.current)] - (now - (sp.current.since || now));
      if (left < 0) overdue = true;
      remaining += Math.max(0, left);
    } else if (sp && sp.current) partnerUnknown = true;
    remaining += partnerLeft();
    planned.forEach((n) => { remaining += sourceLeft(n); });
  } else if (step < KOPIA_STEP) {
    // the part before the partner step (an agent before 2026-10-12: before Kopia, the partners' last time in it)
    const pre = typeof est.prepare === 'number' ? est.prepare : est.before;
    if (typeof pre === 'number') remaining += Math.max(0, pre - (now - s.started));
    else if (!planned.length && !(sp && sp.planned.length) && est.total) remaining += Math.max(0, est.total - (now - s.started));
    else known = false;
    if (typeof est.prepare === 'number') remaining += partnerLeft();
    planned.forEach((n) => { remaining += sourceLeft(n); });
  } else if (step === KOPIA_STEP) {
    planned.filter((n) => !done.has(n)).forEach((n) => { remaining += sourceLeft(n); });
  }
  const elapsed = now - s.started;
  const whole = known && !partnerUnknown;
  return {
    step,
    planned,
    done,
    eta: whole ? now + remaining : null,
    percent: whole && elapsed + remaining > 0 ? Math.min(99, Math.round(elapsed / (elapsed + remaining) * 100)) : null,
    // the rest known, the partner step not (a unit never sent): the rest's end — «plus the partner step»
    restEta: known && partnerUnknown ? now + remaining : null,
    overdue,
    first,
    rough: firstRun && known,
    firstRun,
  };
}

/** His words in the head; pg 'setup': the setup page (no backup place yet: that first — bubblePlace()) */
function bubbleText(pg) {
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
    if (p && p.eta) out.push(T(p.overdue ? 'bubble.eta_late' : p.rough ? 'bubble.eta_rough' : 'bubble.eta', { time: fmt.time(p.eta) }));
    else if (p && p.restEta) out.push(T('bubble.eta_partner_unknown', { time: fmt.time(p.restEta) }));
    if (newSkip()) out.push(T('bubble.skipped', { when: fmt.relative(newSkip().time) }));
    return out;
  }
  const last = lastRun();
  const place = pg === 'setup' ? bubblePlace() : '';
  if (place) out.push(place);
  const taken = minuteTaken();
  if (taken) out.push(T('minute.ran_' + taken.mode, { time: fmt.time(taken.started) }));
  if (state.queued) out.push(T('minute.queued', { time: fmt.time(state.queued.at) }));     // 1.60.1: it starts at the next minute
  if (!last) { if (!place) out.push(T('bubble.no_runs')); }        // no place, no backup: the place says it, once
  else {
    const k = last.kopia || [];
    const ok = k.filter((x) => x.ok).length;
    const when = fmt.relative(last.finished || last.started);
    if (last.result === 'ok') out.push(k.length ? T('bubble.last_ok_kopia', { when, ok, total: k.length }) : T('bubble.last_ok', { when }));
    else if (arrayStopped(last)) out.push(T('bubble.last_array_stop', { when }));
    else out.push(T('bubble.last_' + last.result, { when, errors: last.errors, warnings: last.warnings, n: last.result === 'errors' ? last.errors : last.warnings }));
    out.push(...warnLines(last, true));
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
// his two pages as tabs in the head (CLAUDE.md «Tiles», two tabs = two pages): the overview (#/backup, always) and the
// setup (#/backup/setup) — no crumb, no «← Back», no head button «Set up…» (the callouts' buttons that lead there stay)
const PAGES = () => [{ id: '', text: Office.t('common.page_overview') }, { id: 'setup', text: T('setup.page') }];

/** The desk's own actions, in the head on both pages: a tour and «Start a run…» — while a run goes on, stopping it */
function deskActions() {
  const actions = [];
  if (state && state.found) {
    if (live()) {
      actions.push(button(T(stopKey('abort')), 'danger plain', abortRun));      // grey once asked (the card says what it waits for)
    } else {
      actions.push(button(T('check'), 'plain', () => startRun('check')));
      actions.push(button(T('start'), '', chooseRun));
      if (state.queued) {                  // 1.60.1: a backup waiting for the next minute - it can be called off
        actions.push(button(T('minute.cancel'), 'plain', async () => {
          const j = await Office.api.post(`${ID}.start_cancel`, {});
          if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
          if (j.state) state = j.state;
          render();
        }));
      }
    }
    const blocked = () => !canAct() || !!(state.setup && state.setup.running) || (restoring() && !live()) || (live() && !!state.abort_asked);
    actions.forEach((b) => { b.disabled = blocked(); });
    if (!live()) minuteGuard(actions, blocked, minuteFree);     // one run a minute: grey until the minute changes
    else clearInterval(minuteTimer);
  }
  return actions;
}

/** The desk head of either page: the bubble, the desk's actions, the two tabs */
function deskHeadOf(current) {
  return Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText(current).join(' ')), actions: deskActions(),
    pages: { list: PAGES(), current } }).head;
}

function render() {
  const root = view;
  root.innerHTML = '';
  root.appendChild(deskHeadOf(''));
  root.appendChild(Office.pageHelp(ID, [
    [T('help.run'), T('help.run_text')],
    [T('help.results'), T('help.results_text')],
    [T('help.tiles'), T('help.tiles_text')],
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

  // hints over the row: they stay at every click
  notices().forEach((n) => root.appendChild(n));
  const s = switcher();
  // a run going on: its card in the row's place, and the whole page under it (nothing to pick without the row)
  // a run going on (1.60.1): its card above the tiles, whatever tile is open - the tiles stay
  if (live()) root.appendChild(runningCard());
  Office.place('overview', s.grid);
  root.appendChild(s.el);
  s.render();
  // the footer, quiet and plain, under every section: no backup is a guarantee, the data stays the user's responsibility
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

/** Engine 2.49: its folder or settings.ini isn't root's alone - it refuses to work, the agent starts nothing (the command shown) */
function untrustedCallout() {
  const t = state && state.engine_trust;
  if (!t || t.code !== 'engine_untrusted' || typeof t.path !== 'string') return null;
  const p = el('p', 'callout warn', T('notice.untrusted', { path: t.path }) + ' ');
  p.appendChild(el('code', '', String(t.cmd || '')));
  return p;
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
  const untrusted = untrustedCallout();
  if (untrusted) out.push(untrusted);
  if (!state.settings_found) callout(T('notice.no_settings'), true, button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
  // engine 2.56: the repository suddenly empty - the Kopia step held until the snapshots are back or the user says it is new
  const held = state.kopia_held;
  if (held && typeof held.repo === 'string') {
    const more = el('span', 'bk-held-acts');
    const adv = Office.desks.get('advisor');
    if (adv && adv.hired) {
      more.appendChild(button(T('held.guide'), 'small plain', () => { Office.go('#/advisor'); Office.reveal('lock.8', { name: T('held.guide'), part: 'ext.kopia' }); }));
    }
    const ok = button(T('held.accept'), 'small plain', heldAccept);
    ok.disabled = !canAct() || live() || restoring();
    more.appendChild(ok);
    callout(T('held.callout', { when: fmt.date(held.when), before: String(held.before), now: String(held.now), since: fmt.date(held.time) }), true, more);
  }
  // engine 2.62 (nc_datadir_readable): Nextcloud's data folder readable by others, still so now (agent backupNcOpen()) -
  // one click closes it after a dialog that shows what changes (2026-10-19: instead of a command to type)
  ncOpen().forEach((o) => {
    const b = button(T('nc_open.close'), 'small', () => ncOpenClose(o.name));
    b.disabled = !canAct();
    callout(T('nc_open.callout', { name: o.name, path: o.path }), true, b);
  });
  const sc = state.schedule || {};
  if (sc.script && !sc.enabled) {     // no settings yet: "no settings" says it all
    const b = button(T('schedule.open'), 'small', scheduleDialog);
    b.disabled = !Office.agent.running;  // the messenger away: nobody would take the request
    callout(T('notice.schedule_off'), true, b);
  }
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
/**
 * The current step's highlight slides (1.60.1, a user: it jumped): one element behind the step list, moved over the current
 * step with an ease-in-out of 0.7 s, its width the step's. No move on the first drawing or while the step stays - a
 * redraw at every poll starts from where it stood (bkHlLast); under prefers-reduced-motion it jumps (office.css)
 */
let bkHlLast = { key: null, at: -1, box: null };
function bk_slideMark(wrap, ol, key, at) {
  if (at < 0 || typeof requestAnimationFrame !== 'function') return;
  const hl = el('div', 'bk-steps-hl');
  hl.setAttribute('aria-hidden', 'true');
  wrap.insertBefore(hl, ol);
  const place = (b) => { hl.style.left = b.left + 'px'; hl.style.top = b.top + 'px'; hl.style.width = b.width + 'px'; hl.style.height = b.height + 'px'; };
  requestAnimationFrame(() => {
    const li = ol.children[at];
    if (!li || !li.offsetWidth) return;
    const box = { left: ol.offsetLeft + li.offsetLeft, top: ol.offsetTop + li.offsetTop, width: li.offsetWidth, height: li.offsetHeight };   // li sits in the positioned ol
    const from = bkHlLast.key === key && bkHlLast.at !== at && bkHlLast.box ? bkHlLast.box : null;
    hl.style.transition = 'none';
    place(from || box);
    if (from) {
      void hl.offsetWidth;                // the start position laid out before the move
      hl.style.transition = '';
      place(box);
    }
    bkHlLast = { key, at, box };
  });
}

function runningCard() {
  const s = status();
  const p = progress();
  const box = section(T('now'), T('now_sub'), { place: 'now' });
  // the whole card is .running: the wave beside «running», on the current step, the current Kopia source, the abort
  // note (CLAUDE.md: the wave) — until 1.60.1 only a card with a Kopia list got the class, so the step's wave never showed
  const card = el('div', 'card bk-run running');

  const top = el('div', 'card-head');
  top.append(el('span', 'spin'), el('span', 'card-name', s ? T('mode.' + runModeOf(s)) : T('mode.backup')), chip(T('result.running'), 'accent'));
  const since = s ? s.started : state.since;
  if (since) top.appendChild(el('span', 'status quiet', T('since', { time: fmt.time(since), duration: fmt.duration(Date.now() / 1000 - since) })));
  card.appendChild(top);
  // «Abort» was sent: what the engine still waits for before it cleans up (it hears the signal after its current step)
  if (state.abort_asked) {
    const ph = s && s.phase;
    const wait = ph === 'stopping_apps' || ph === 'stopping' ? 'apps' : ph === 'vm_shutdown' ? 'vm' : ph === 'kopia' ? 'kopia' : 'step';
    const note = el('p', 'callout warn bk-aborting');
    note.append(el('span', 'spin'), ' ', T('aborting_wait.' + wait, { when: fmt.time(state.abort_asked) }));
    card.appendChild(note);
  }

  if (!s) {
    card.appendChild(el('div', 'card-line', T('old_step', { step: state.step || '…' })));
    box.appendChild(card);
    return box;
  }

  const steps = el('ol', 'bk-steps');
  // the partners' step only in a run that sends to partners (engine 2.27)
  const shown = STEPS.map(([name], i) => [name, i]).filter(([name]) => name !== 'partner' || s.partner);
  shown.forEach(([name, i]) => {
    const li = el('li', i < p.step ? 'done' : i === p.step ? 'current' : '', T('step.' + name));     // the highlight says it runs: no wave here
    // struck through - and why: offsite switched off, or on but nothing goes this time. Not while the run still looks
    // around (step 0 has no plan yet — 2026-10-11 on a test server the step stood struck through at 0 % and Kopia then sent 31)
    if (name === 'kopia' && (s.skip_kopia || (!p.planned.length && (p.step > 0 || (state.settings && state.settings.kopia_enabled === false))))) {
      li.classList.add('skipped');
      li.dataset.tip = s.skip_kopia ? T('step.kopia_skipped_nokopia') : state.settings && state.settings.kopia_enabled === false ? T('step.kopia_skipped_off') : T('step.kopia_skipped_none');
    }
    if (!li.dataset.tip) li.title = T('step.' + name);     // a narrow card ends a long name in «…» (desk.css)
    steps.appendChild(li);
  });
  const sw = el('div', 'bk-steps-wrap');
  sw.appendChild(steps);
  card.appendChild(sw);
  bk_slideMark(sw, steps, s.run || String(s.started || ''), shown.findIndex(([, i]) => i === p.step));
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
  if (p.eta) line.append(T(p.overdue ? 'eta_late' : p.rough ? 'eta_rough' : 'eta_at', { time: fmt.time(p.eta) }));
  else if (p.restEta) line.append(T('eta_partner_unknown', { time: fmt.time(p.restEta) }));
  // the very first backup before its upload: a rough estimate comes once that is measured (2026-10-15)
  else line.append(T(p.first ? 'eta_first' : p.firstRun && p.planned.length && p.step < KOPIA_STEP ? 'eta_first_wait' : 'eta_unknown'));
  if (s.downtime_s) line.append(' · ', T('downtime_was', { duration: fmt.duration(s.downtime_s) }));
  if (s.packages && s.packages.written) line.append(' · ', T('pk.run_packed', { apps: nOf('apps', s.packages.apps), vms: nOf('vms', s.packages.vms) }));
  card.appendChild(line);
  if (s.phase === 'vm_shutdown') card.appendChild(el('div', 'card-line', T('vm_shutdown_now')));
  if (s.phase === 'partner' && s.partner && s.partner.current) card.appendChild(el('div', 'card-line', partnerNowText(s)));
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
    card.appendChild(list);
  }

  const foot = el('div', 'card-foot');
  foot.appendChild(el('span', '', T('counts', { errors: nOf('errors', s.errors), warnings: nOf('warnings', s.warnings) })));
  if (s.log) foot.appendChild(button(T('show_log'), 'small plain', () => showLog(s.log, true)));
  card.appendChild(foot);
  box.appendChild(card);
  return box;
}

// ---- the overview's tiles (tiles v2, 2026-10-11 — CLAUDE.md «Tiles»: a tile is a section). The row is the page's table
// of contents, the section switcher holds it: a click shows that tile's section alone under the row, the second click (or
// «◂ All», «Show all») the whole page again in the row's order; nothing active at the start. «Last backup» → the backups,
// «Schedule» → a row section with «Change…», «Offsite» / «Databases» / «Packages» / «VMs» / «Flash» → «Is everything
// protected?» filtered (filter tiles: never stored), «Protection» → the table whole, «Partners» → a row per partner,
// «Changed» → what changed since the setup, «Restore» → where the backups lie. Hints over the row (the callouts) and
// the footer stay at every click; a run going on puts its card in the row's place and shows the whole page.
// overviewTiles() is pure (no DOM: tests/backuptiles.js runs it under node), tileList() hands it to the switcher.
const PROT_ID = 'bk-protection';                  // the section holding the table and its bar: aria-controls, data-filters
const PROT_TILES = ['kopia', 'dumps', 'apps', 'vms', 'flash'];
// every tile in the row's order with its lang key (its place and route #/backup/<id>) and the section it shows
const TILES = [['last', 'stat.last', 'history'], ['schedule', 'stat.schedule', 'schedule'], ['kopia', 'tile.kopia', 'prot'],
  ['dumps', 'tile.dumps', 'prot'], ['apps', 'tile.apps', 'prot'], ['vms', 'tile.vms', 'prot'], ['flash', 'tile.flash', 'prot'],
  ['prot', 'tile.prot', 'prot'], ['partners', 'partner.tile', 'partners'], ['drift', 'tile.drift', 'drift'], ['restore', 'tile.restore', 'restore']];
const TILE_KEY = Object.fromEntries(TILES.map(([id, key]) => [id, key]));
const TILE_SECTION = Object.fromEntries(TILES.map(([id, , s]) => [id, s]));
const tileRoute = (id) => `#/${ID}/${id}`;
let protBar = null;                            // the table's filter bar, built once per visit (mount() drops it)
let histBar = null;                            // the history's
let protShown = [];                            // the rows drawn now that unfold: {open(), set(open)} — «Unfold all»
let sw = null;                                 // the switcher, made at the first draw (switcher())

/** The filter tile that is on ('' = none): the table shows only its rows — never stored (the switcher keeps no filter) */
const protFilter = () => { const a = sw ? sw.active() : ''; return PROT_TILES.includes(a) ? a : ''; };

/** The row and the sections under it (core.js's section switcher) — one per page life, drawn anew with every state */
function switcher() {
  if (sw) return sw;
  sw = Office.sections({
    id: ID,                      // kept as backup.tile, routes #/backup/<tile>, places tile.<tile>
    grid: 'stats bk-stats',
    default: '',                 // the start: no tile — the whole page (his page is a report, the most important on top)
    tiles: () => tileList(),
    sections: { prot: () => protection() },
  });
  return sw;
}

/**
 * The tiles for the switcher: overviewTiles() as they stand (without a state yet every tile of TILES, so a route or the
 * search can name any of them), each with the section it shows — the table shared by the filter tiles and «Protection»
 */
function tileList() {
  const own = { history: () => historySection(), schedule: () => scheduleSection(), partners: () => partnersSection(),
    drift: () => driftSection(), restore: () => restoreSection() };
  const shows = (id) => (TILE_SECTION[id] === 'prot' ? 'prot' : own[TILE_SECTION[id]]);
  const filters = (id) => (PROT_TILES.includes(id) ? T('tile.' + id) : undefined);      // a filter tile: its name in the note line
  if (!state || !state.found) return TILES.map(([id, key]) => ({ id, key, section: shows(id), filters: filters(id), tile: () => null }));
  return overviewTiles().map((d) => ({ id: d.key, key: TILE_KEY[d.key], section: shows(d.key), filters: filters(d.filters),
    tile: (a) => tileNode(d, a) }));
}

/** One tile of overviewTiles() as Office.stat — the switcher's attributes (a: the click, aria-pressed, the mark …) spread in;
 *  its place for the search is tile.<id> (the switcher's own, named here too) */
function tileNode(d, a) {
  return Office.stat({ label: d.running ? waveLabel(d.label) : d.label, value: d.value, sub: d.sub, alert: d.alert, tip: d.tip, cls: d.cls, ...a, place: `tile.${d.key}` });
}
/** A label with the wave left of it (a run going on: the last backup's tile; CLAUDE.md: the wave) */
function waveLabel(text) {
  const w = el('span', 'running');
  w.append(el('span', 'spin'), ' ', text);
  return w;
}

/** «2 hours ago» etc.: durations with seconds when quick (dur), the containers' part of the last backup in one line */
function containersLine(last) {
  const c = state.containers;
  if (c && c.silent) return T('stat.docker_silent');                // switched on but silent: said as such (B15)
  if (c && c.up === false) return Office.t('common.docker_off');      // Docker stopped: said, not left out (1.43)
  const parts = [];
  if (last && last.downtime) parts.push(T('stat.downtime', { duration: dur(last.downtime) }));
  if (c && c.stopped) parts.push(T('stat.ct_stopped', { n: c.stopped }));
  if (c && c.kept) parts.push(T('stat.ct_kept', { n: c.kept }));
  return parts.join(' · ');
}

/** A partner's line on the tile, short (one line each): how the last sending went; the long one goes into the tip */
function partnerShort(p) {
  const l = p.last;
  if (p.current) return T('partner.short_now', { name: p.name });
  if (p.apply) return partnerApplyText(p);
  if (!l) return T('partner.short_never', { name: p.name });
  const parts = [l.sent ? T('partner.short_sent', { name: p.name, when: fmt.relative(l.time) }) : T('partner.tile_none', { name: p.name, when: fmt.relative(l.time) })];
  if (l.failed.length) parts.push(T('partner.short_failed', { n: l.failed.length }));
  if (l.skipped.length) parts.push(T('partner.short_skipped', { n: l.skipped.length }));
  return parts.join(' · ');
}
/** … and in full: what went, how much, how fast, what was skipped or failed and why; while a run sends to it, what now */
const partnerLong = (p) => partnerParts(p).join(' · ');
function partnerParts(p) {
  const l = p.last;
  const parts = [];
  if (p.apply && !p.current) parts.push(partnerApplyText(p));
  if (p.current) parts.push(T('partner.tile_now', { name: p.name, unit: unitLabel(p.current.unit), size: fmt.size(p.current.bytes || 0) }));
  else if (!l) { if (!p.apply) parts.push(T('partner.tile_never', { name: p.name, n: p.units.length })); } else {
    parts.push(l.sent ? T('partner.tile_sent', { name: p.name, n: l.sent, size: fmt.size(l.bytes), rate: fmt.bitrate(l.mbit || 0), when: fmt.relative(l.time) })
      : T('partner.tile_none', { name: p.name, when: fmt.relative(l.time) }));
    if (l.skipped.length) parts.push(T('partner.tile_skipped', { n: l.skipped.length, why: [...new Set(l.skipped.map((x) => partnerWhyShort(x.why)))].join(', ') }));
    if (l.failed.length) parts.push(T('partner.tile_failed', { n: l.failed.length, why: [...new Set(l.failed.map((x) => partnerWhyShort(x.why)))].join(', ') }));
  }
  return parts;
}
const partnerTrouble = (p) => !!(p.ended || (p.from || []).length
  || (p.last && !p.current && (p.last.failed.length || p.last.skipped.some((x) => !['array_stopping', 'signal'].includes(x.why)))));
/**
 * 1.60.1: what waits for «Apply» (the agent's partners[].apply): a partner ended at the Team Lead — paired anew as
 * `anew` (its units go there at the next Apply) or not (they go) — or a pair that settings.ini gives nothing yet
 */
function partnerApplyText(p) {
  if (p.ended && p.anew) return T('partner.short_anew', { name: p.name, new: p.anew.name, n: (p.units || []).length });
  if (p.ended) return T('partner.short_ended', { name: p.name });
  if ((p.from || []).length) return T('partner.short_from', { name: p.name, from: p.from.map((f) => f.name).join(', '), n: p.from.reduce((a, f) => a + f.n, 0) });
  return T('partner.short_apply', { name: p.name });
}

/** A sub line long enough to be clamped on a phone gets the whole text as its tip too */
const tipOf = (subs) => { const t = subs.filter(Boolean).join('\n'); return t.length > 48 ? t : ''; };

/** Mr. Restori's desk, when the office has it (his drill's certificate counts only while he works here) */
const restoriHired = () => { const r = Office.desks && Office.desks.get ? Office.desks.get('restore') : null; return !!(r && r.hired); };

/**
 * The overview's tiles, pure (no DOM): [{key (the tile's id: TILES), label, value, sub: [text], alert, tip, cls,
 * section (the section it shows: history | schedule | prot | partners | drift | restore), filters (a PROT_TILES key: the
 * rows it leaves in «Is everything protected?»; null: the section whole)}], in the row's order. The last backup (how
 * long it took, the containers' part, and a tour or dry run when newer), the schedule, offsite (Kopia), databases,
 * packages, VMs, the flash, the protection in all, the partners (when there are), what changed since the setup (when
 * something did), getting things back.
 */
function overviewTiles() {
  const set = state.settings || {};
  const out = [];
  const add = (d) => out.push({ sub: [], alert: false, filters: null, ...d, section: TILE_SECTION[d.key], tip: d.tip || tipOf(d.sub || []) });
  const last = lastRun();
  const s = status();
  // a tour or a dry run after the last backup: the last backup's third line (an older one is noise beside it)
  const tour = s && s.mode !== 'backup' && s.finished && (!last || s.finished > (last.finished || last.started))
    ? T('stat.tour_line', { what: T('stat.last_' + s.mode), result: T('result.' + s.result), when: fmt.relative(s.finished) }) : '';
  const tourBad = !!tour && s.result !== 'ok';
  if (last) {
    const when = [arrayStopped(last) ? T('stat.last_array_stop', { when: fmt.date(last.started, true) }) : fmt.date(last.started, true)];
    if (last.finished) when.push(dur(last.finished - last.started));
    // engine 2.28: the shares it left out because their pool slept (the user's choice - the result stays ok)
    if (asleepUnits(last)) when.push(T('asleep.left_out', { n: asleepUnits(last) }));
    add({ key: 'last', label: T('stat.last'), running: live(), value: T('result.' + last.result), sub: [when.join(' · '), containersLine(last), tour].filter(Boolean),
      alert: (last.result !== 'ok' && !arrayStopped(last)) || tourBad });
  } else {
    add({ key: 'last', label: T('stat.last'), running: live(), value: '–', sub: [T('bubble.no_runs'), tour].filter(Boolean), alert: tourBad });
  }
  const sc = state.schedule || {};
  const value = sc.enabled ? (sc.frequency === 'custom' ? fmt.cron(sc.custom) : T('freq.' + sc.frequency)) : T('stat.not_scheduled');
  const next = sc.enabled && sc.next ? T('stat.next', { when: fmt.relative(sc.next) }) : '';
  add({ key: 'schedule', label: T('stat.schedule'), value, sub: [sc.enabled ? next : T('stat.schedule_hint')],
    alert: !sc.enabled });
  const c = state.containers;
  // Kopia: the last run's sources, the container, the repository and whether its policies match
  if (set.kopia_enabled) {
    // the newest run that got as far as Kopia (a run that stopped before it says nothing about Kopia)
    const withKopia = (state.history || []).find((r) => (r.kopia || []).length);
    const k = (withKopia && withKopia.kopia) || [];
    const okCount = k.filter((x) => x.ok).length;
    const skipped = ((withKopia && withKopia.kopia_skipped) || []).length;     // an array stop ended that run: they wait for the next one
    const kc = c && c.kopia;
    const repoNo = s && s.kopia && s.kopia.state === 'no';
    const pol = state.drift && Array.isArray(state.drift.policies) ? state.drift.policies : null;
    const bad = pol ? pol.filter((p) => !p.ok).length : 0;
    const sub = [];
    if (c && c.silent) sub.push(T('stat.docker_silent'));         // B15: Kopia's container can't be looked at
    else if (kc) sub.push(c.up === false ? Office.t('common.docker_off')
      : !kc.exists ? T('stat.kopia_missing_ct', { name: kc.name }) : kc.running ? T('stat.kopia_running', { name: kc.name }) : T('stat.kopia_stopped', { name: kc.name }));
    // engine 2.66: the repository unreachable from run to run - since the first run that couldn't reach it (B16)
    const away = state.offsite && state.offsite.unreachable_since ? state.offsite : null;
    if (away) sub.push(T('stat.offsite_unreachable_since', { when: fmt.date(away.unreachable_since) }));
    else if (repoNo) sub.push(T('stat.kopia_repo_problem'));
    if (pol) sub.push(bad ? T('stat.kopia_policies_bad', { n: bad }) : T('stat.kopia_policies_ok'));
    const trouble = (kc && (!kc.exists || !kc.running)) || !!(c && c.silent) || repoNo || !!away || bad > 0 || (k.length && okCount !== k.length);
    if (k.length && okCount !== k.length) sub.unshift(T('stat.kopia_missing', { n: k.length - okCount }));
    else if (withKopia) sub.unshift(T('stat.kopia_when', { when: fmt.relative(withKopia.started) }));
    if (skipped) sub.unshift(T('stat.kopia_skipped', { n: skipped }));
    const slept = ((withKopia && withKopia.kopia_asleep) || []).length;     // engine 2.28: their pool slept - left out by choice
    if (slept) sub.push(T('stat.kopia_asleep', { n: slept }));
    add({ key: 'kopia', label: T('stat.kopia'), value: k.length ? `${okCount} / ${k.length + skipped}` : '–', sub: [sub.join(' · ')], alert: !!trouble, filters: 'kopia' });
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
    add({ key: 'dumps', label: T('stat.dbs'), value: String(dbs.length), sub: [sub.join(' · ')], filters: 'dumps' });
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
    add({ key: 'apps', label: T('stat.packages'), value: T('stat.pk_value', { apps: nOf('apps', pk.apps.length - pk.apps.filter((a) => a.stale).length),
      vms: nOf('vms', pk.vms.length - pk.vms.filter((v) => v.stale).length) }), sub: [sub.join(' · ')], alert: bad > 0, filters: 'apps' });
  } else if (state.packages && state.packages.old_runs) {
    add({ key: 'apps', label: T('stat.packages'), value: '–', sub: [T('stat.pk_first')], filters: 'apps' });
  }
  const vms = state.vms || [];
  if (vms.length) {
    const count = {};
    vms.forEach((v) => {
      const k = !v.configured ? 'unset' : v.mode === 'off' && v.own.length ? 'off' : v.prepare;
      count[k] = (count[k] || 0) + 1;
    });
    const sub = ['freeze', 'pause', 'shutdown', 'none', 'off', 'unset'].filter((k) => count[k]).map((k) => T('stat.vm_' + k, { n: count[k] }));
    add({ key: 'vms', label: T('stat.vms'), value: String(vms.length), sub: [sub.join(' · ')], alert: !!count.unset, filters: 'vms' });
  }
  const flash = set.flash || 'off';
  const fl = (state.shares || []).find((x) => x.flash);
  const tarRun = (state.dumps || []).find((d) => d.flash);
  let fv; let fsub = ''; let falert = false;
  if (flash === 'snapshot') {
    fv = set.kopia_enabled ? T('stat.flash_offsite') : T('stat.flash_local');
    if (fl && fl.last) { fsub = fl.last.ok ? T('stat.flash_last', { when: fmt.relative(fl.last.time) }) : T('failed'); falert = !fl.last.ok; }
    else if (set.kopia_enabled) fsub = T('never');
  } else if (flash === 'tar') {
    fv = T('stat.flash_tar');
    const t = pk && pk.flash ? pk.flash.time : tarRun ? tarRun.time : null;
    fsub = t ? T('stat.flash_last', { when: fmt.relative(t) }) : T('never');
  } else { fv = T('stat.flash_off'); falert = true; }
  add({ key: 'flash', label: T('stat.flash'), value: fv, sub: fsub ? [fsub] : [], alert: falert, filters: 'flash' });
  // the protection in all: the table whole — how many rows lie offsite, only local, nowhere
  const rows = protRows();
  const lv = (l) => rows.filter((r) => r.level === l).length;
  const off = rows.filter((r) => r.off).length;
  add({ key: 'prot', label: T('tile.prot'), value: off ? T('stat.prot_off', { n: off }) : T('stat.prot_ok'),
    sub: [T('protection_sum', { kopia: lv('offsite'), snapshot: lv('local'), off: lv('none') })], alert: off > 0 });
  // the partner offices (engine 2.27): one short line per partner, the whole text in the tip — the section has a row each
  const partners = state.partners || [];
  if (partners.length) {
    add({ key: 'partners', label: T('partner.tile'), value: partners.map((p) => p.name).join(', '), cls: 'bk-ptile',
      sub: partners.map(partnerShort), tip: partners.map(partnerLong).join('\n'), alert: partners.some(partnerTrouble) });
  }
  // what changed on the server since the setup (the engine's drift) — only while something did
  const drift = (state.drift && state.drift.items) || [];
  if (drift.length) {
    add({ key: 'drift', label: T('tile.drift'), value: T('stat.drift_n', { n: drift.length }),
      sub: state.drift.time ? [T('drift_checked', { when: fmt.relative(state.drift.time) })] : [], alert: drift.some((d) => d.level !== 'info') });
  }
  // getting things back: where the backups lie, Mr. Restori's way — and his drill's certificate while he works here
  const dr = restoriHired() ? state.drill : null;
  add({ key: 'restore', label: T('tile.restore'), value: dr ? T(dr.result === 'failed' ? 'stat.drill_failed' : 'stat.drill_passed') : Office.t('restore.name'),
    sub: [dr ? fmt.date(dr.ended) : T('stat.restore_where')], alert: !!dr && dr.result === 'failed' });
  return out;
}

// ---- «Is everything protected?»: the rows as data (pure: tests/backuptiles.js), the filter on them, then the table
/** Every row of the table: {kind share|vm|app, id, name, hay (the words the bar meets), off (unprotected), tiles (the
 *  PROT_TILES that count it)} in the table's order: shares (offsite, local, off; the flash among them), VMs, apps */
function protRows() {
  const kopiaOn = !!(state.settings && state.settings.kopia_enabled);
  const order = { kopia: 0, snapshot: 1, off: 2 };
  const rows = [];
  const word = (level) => Office.t('protect.' + level);
  [...(state.shares || [])].sort((a, b) => (order[a.mode] - order[b.mode]) || a.name.localeCompare(b.name)).forEach((s) => {
    const level = shareLevel(s, kopiaOn);
    const tiles = [];
    if (s.mode === 'kopia' && kopiaOn) tiles.push('kopia');
    if (s.flash) tiles.push('flash');
    rows.push({ kind: 'share', id: s.flash ? '\u0000flash' : s.name, name: s.flash ? T('flash') : s.name, x: s, level, off: level === 'none', tiles,
      hay: [s.flash ? T('flash') : s.name, word(level), s.mode === 'kopia' && !kopiaOn ? T('mode_kopia_off') : '', s.method === 'live' ? T('live') : '',
        ...(s.locations || [])] });
  });
  // the flash kept as an archive in its package (or not at all) is no share of the engine's — still a row of its own here,
  // so «Flash» always has its line: the archive as protected as the backup place, none at all = not backed up
  if (!rows.some((r) => r.x.flash)) {
    const flash = (state.settings && state.settings.flash) || 'off';
    const level = flash === 'tar' ? placeLevel(kopiaOn) : 'none';
    const rank = { offsite: 0, local: 1, none: 2 };
    rows.splice(rows.filter((r) => rank[r.level] <= rank[level]).length, 0, { kind: 'share', id: '\u0000flash', name: T('flash'), level, off: level === 'none',
      tiles: ['flash', ...(level === 'offsite' ? ['kopia'] : [])],
      x: { name: 'flash', flash: true, mode: flash, archive: true }, hay: [T('flash'), word(level), flash === 'tar' ? T('stat.flash_tar') : ''] });
  }
  (state.vms || []).forEach((v) => {
    const level = vmLevel(v, kopiaOn);
    const pools = [...new Set((v.own || []).map((d) => String(d).split('/')[0]))];
    rows.push({ kind: 'vm', id: 'vm:' + v.name, name: v.name, x: v, level, off: !v.configured || level === 'none', tiles: ['vms', ...(level === 'offsite' ? ['kopia'] : [])],
      hay: [v.name, word(level), v.configured && v.mode !== 'off' ? T('vm.prep.' + v.prepare) : '', !v.configured ? T('vm.not_set_up') : '', v.share, ...pools] });
  });
  const pk = packages();
  if (pk) {
    const place = placeLevel(kopiaOn);
    pk.apps.forEach((a) => {
      const own = kopiaOn && !!itemOf('app', a.name);
      const level = own && place !== 'offsite' ? 'offsite' : place;
      const tiles = ['apps'];
      if (a.files.some((f) => f.what === 'dump')) tiles.push('dumps');
      if (level === 'offsite') tiles.push('kopia');
      rows.push({ kind: 'app', id: 'app:' + a.folder, name: a.name, x: a, level, off: a.result === 'errors', tiles,
        hay: [a.name, a.folder !== a.name ? a.folder : '', word(level), T('pk.type.' + (a.type || 'container')), pkWord(a)] });
    });
  }
  return rows;
}
const shareLevel = (s, kopiaOn) => (s.mode === 'kopia' ? (kopiaOn ? 'offsite' : 'local') : s.mode === 'snapshot' ? 'local' : 'none');
/** A package's state in a word, as its chip says it */
const pkWord = (p) => T(p.stale ? 'pk.stale' : p.result === 'errors' ? 'pk.errors' : p.result === 'warnings' ? 'pk.warnings' : 'pk.ok');
/** How many rows each filtering tile counts */
function protTileCounts() {
  const n = {};
  protRows().forEach((r) => r.tiles.forEach((t) => { n[t] = (n[t] || 0) + 1; }));
  return n;
}
/**
 * The table as the filter leaves it (pure): f = {words, only, tile}. A group the tile rules out as a whole isn't drawn; a
 * group drawn with no row fitting keeps its head and one «nothing fits» line. → {groups: {share|vm|app: null | [rows]},
 * n (rows fitting), total (rows in all), off (unprotected rows in all)}
 */
function protView(rows, f) {
  const groups = {};
  let n = 0;
  ['share', 'vm', 'app'].forEach((kind) => {
    const all = rows.filter((r) => r.kind === kind);
    const byTile = f.tile ? all.filter((r) => r.tiles.includes(f.tile)) : all;
    if (!byTile.length) { groups[kind] = null; return; }
    const fit = byTile.filter((r) => (!f.only || r.off) && Office.fits(f.words, r.hay));
    n += fit.length;
    groups[kind] = fit;
  });
  return { groups, n, total: rows.length, off: rows.filter((r) => r.off).length };
}

/** The table's bar: words (names, the protection word, the pool), «only what is not protected», «Unfold all» */
function protFilterBar() {
  if (protBar) return protBar;
  const all = button(T('unfold_all'), 'small plain', () => {
    const open = protShown.some((x) => !x.open());
    Office.keepInPlace(all, () => protShown.forEach((x) => x.set(open)));
    protAllLabel();
  });
  all.classList.add('bk-unfold-all');
  protBar = Office.filterBar({ key: 'backup.protect', label: T('protect.filter_label'), only: { text: T('protect.only_off'), hint: T('protect.only_off_hint') },
    extra: [all], onChange: () => switcher().render() });
  protBar.all = all;
  return protBar;
}
function protAllLabel() {
  if (!protBar) return;
  protBar.all.textContent = protShown.some((x) => !x.open()) ? T('unfold_all') : T('fold_all');
  protBar.all.hidden = !protShown.length;
}

/** Is everything protected? Every share with its mode and last offsite copy; a row unfolds to its rules */
const protOpen = new Set();      // unfolded rows (share names), kept across re-renders
function protection() {
  const shares = state.shares || [];
  const counts = { kopia: 0, snapshot: 0, off: 0 };
  shares.forEach((s) => { counts[s.mode] = (counts[s.mode] || 0) + 1; });
  const kopiaOn = state.settings && state.settings.kopia_enabled;
  const local = counts.snapshot + (kopiaOn ? 0 : counts.kopia);
  const box = section(T('protection'), T('protection_sum', { kopia: kopiaOn ? counts.kopia : 0, snapshot: local, off: counts.off }), { place: 'protection' });
  box.id = PROT_ID;
  protShown = [];
  if (!shares.length) { box.appendChild(el('p', 'empty', T('no_shares'))); return box; }
  const gone = goneCallout();
  if (gone) box.appendChild(gone);

  const bar = protFilterBar();
  box.appendChild(bar.el);
  const rows = protRows();
  const f = { ...bar.read(), tile: protFilter() };
  const v = protView(rows, f);

  const wrap = el('div', 'box table-wrap');
  const table = el('table', 'grid bk-protect');
  const thead = el('thead');
  const hr = el('tr');
  ['share', 'mode', 'last_copy', 'took', 'details'].forEach((k) => hr.appendChild(el('th', '', T('col.' + k))));
  thead.appendChild(hr);
  table.appendChild(thead);
  const body = el('tbody');
  const none = () => {
    const tr = el('tr', 'bk-none');
    const td = el('td', 'bk-filter-none', T('filter_none'));
    td.colSpan = 5;
    tr.appendChild(td);
    body.appendChild(tr);
  };
  const head = (cls, text, chipNode, title) => {
    const g = el('tr', 'bk-group' + (cls ? ' ' + cls : ''));
    const gth = el('th', '', text);
    gth.colSpan = 5;
    if (chipNode) gth.append(' ', chipNode);
    if (title) gth.title = title;
    g.appendChild(gth);
    body.appendChild(g);
  };
  /** a row that unfolds to its details, kept open across drawings (protOpen) */
  const unfolding = (tr, name, id, detail) => {
    tr.classList.add('unfolds');
    name.classList.add('link');
    name.title = T('details');
    let dtr = null;
    const set = (open) => {
      if (!open && dtr) { dtr.remove(); dtr = null; protOpen.delete(id); tr.classList.remove('open'); }
      if (open && !dtr) {
        dtr = el('tr', 'bk-detail');
        const td = el('td');
        td.colSpan = 5;
        td.appendChild(detail());
        dtr.appendChild(td);
        tr.after(dtr);
        protOpen.add(id);
        tr.classList.add('open');
      }
    };
    // the whole row unfolds (chips explain themselves on a click, see initTips)
    tr.onclick = (e) => {
      if (e.target.closest('button, a, input, select, [data-own], .chip')) return;
      if (String(window.getSelection && window.getSelection()).length) return;
      Office.keepInPlace(tr, () => set(!dtr));
      protAllLabel();
    };
    protShown.push({ open: () => !!dtr, set });
    if (protOpen.has(id)) set(true);
  };

  if (v.groups.share) {
    if (!v.groups.share.length) none();
    v.groups.share.forEach((r) => {
      const s = r.x;
      if (s.archive) { body.appendChild(flashArchiveRow(r)); return; }
      const tr = Office.place(s.flash ? 'row:flash' : `row:share:${s.name}`, el('tr'));
      const name = el('th', '', r.name);
      tr.appendChild(name);
      const modeCell = el('td');
      // the same labels as everywhere in the office: offsite / only local / not backed up
      modeCell.appendChild(Office.backupChip(r.level));
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
      if (s.mode !== 'off') unfolding(tr, name, r.id, () => protectionDetail(s, kopiaOn));
    });
  }

  // the VMs: their disks are in their share's snapshot - here how each one is treated for it
  if (v.groups.vm) {
    head('', T('vm.group'));
    if (!v.groups.vm.length) none();
    v.groups.vm.forEach((r) => {
      const vm = r.x;
      const tr = Office.place(`row:vm:${vm.name}`, el('tr'));
      const name = el('th', '', vm.name);
      tr.appendChild(name);
      const modeCell = el('td');
      modeCell.appendChild(Office.backupChip(r.level));
      if (vm.configured && vm.mode !== 'off') modeCell.append(' ', chip(T('vm.prep.' + vm.prepare), vm.prepare === 'none' ? 'warn' : '', T('vm.prep_text.' + vm.prepare)));
      if (!vm.configured) modeCell.append(' ', chip(T('vm.not_set_up'), 'warn', T('vm.not_set_up_hint')));
      const vp = vmPackage(vm.name);
      if (vp && (vp.stale || vp.result === 'errors')) modeCell.append(' ', pkChip(vp));
      const vi = kopiaOn ? itemOf('vm', vm.name) : null;
      if (vi) modeCell.append(' ', itemChip(vi));
      tr.appendChild(modeCell);
      const last = vm.last;
      const lastCell = el('td', '', last && last.snapshot && last.time ? fmt.relative(last.time) : '');
      if (last && last.time) lastCell.title = fmt.date(last.time);
      const took = el('td', '', last && ['frozen', 'paused', 'shutdown'].includes(last.done) ? dur(last.seconds) : '');
      tr.append(lastCell, took);
      tr.appendChild(el('td', '', last ? T('vm.done.' + last.done) : ''));
      body.appendChild(tr);
      unfolding(tr, name, r.id, () => vmDetail(vm));
    });
  }

  // the apps: what their package in the backup place holds (the data itself is in their shares above)
  const pk = packages();
  if (v.groups.app && pk) {
    head('bk-apps', T('pk.group') + ' ', Office.backupChip(placeLevel(kopiaOn)), T('pk.group_hint', { path: pk.base + '/apps' }));
    if (!v.groups.app.length) none();
    v.groups.app.forEach((r) => {
      const a = r.x;
      const tr = Office.place(`row:app:${a.folder}`, el('tr'));
      const name = el('th', '', a.name);
      tr.appendChild(name);
      const st = el('td');
      st.appendChild(pkChip(a));
      const ga = goneOf('app', a.folder);
      if (ga && ga.moved_to) st.append(' ', chip(T('pk.moved'), 'warn', T('pk.moved_hint', { other: ga.moved_to })));
      else if (ga) st.append(' ', chip(T('pk.gone'), 'warn', T('pk.gone_hint.app', { name: a.name })));
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
      unfolding(tr, name, r.id, () => pkDetail(a));
    });
  }
  if (!v.groups.share && !v.groups.vm && !(v.groups.app && pk)) none();      // a filter tile with no row (nothing goes offsite)
  table.appendChild(body);
  wrap.appendChild(table);
  box.appendChild(wrap);
  protAllLabel();
  // the note line names the filter tile that is on (its × and «Show all»: nothing active — the whole page again)
  bar.done(v.n, v.total, { ...switcher().note('prot'), onlyAvail: v.off > 0 });

  const set = state.settings || {};
  const facts = [];
  if (set.zfs_retention) facts.push(T('fact.zfs', { r: set.zfs_retention }));
  if (set.btrfs_days) facts.push(T('fact.btrfs', { n: Number(set.btrfs_days) }));
  if (kopiaOn) {
    const k = set.kopia_keep || {};
    facts.push(T('fact.kopia', { d: k.daily ?? '–', w: k.weekly ?? '–', m: k.monthly ?? '–', y: k.annual ?? '–' }));
  } else facts.push(T('fact.kopia_off'));
  box.appendChild(el('p', 'role', facts.join(' · ')));
  brakeGaps().forEach((g) => box.appendChild(el('p', 'callout warn', g)));       // engine 2.63: the btrfs retention not held, the brake out of snapshots
  return box;
}

/** The flash's row when it is kept as an archive in its package (or not at all): no share of the engine's, no rules */
function flashArchiveRow(r) {
  const tr = Office.place('row:flash', el('tr'));
  tr.appendChild(el('th', '', r.name));
  const modeCell = el('td');
  modeCell.appendChild(Office.backupChip(r.level));
  if (r.x.mode === 'tar') modeCell.append(' ', chip(T('stat.flash_tar')));
  const pk = packages();
  const tarRun = (state.dumps || []).find((d) => d.flash);
  const t = r.x.mode !== 'tar' ? null : pk && pk.flash ? pk.flash.time : tarRun ? tarRun.time : null;
  const lastCell = el('td', '', t ? fmt.relative(t) : '');
  if (t) lastCell.title = fmt.date(t);
  tr.append(modeCell, lastCell, el('td'), el('td', '', r.x.mode === 'tar' && pk ? `${pk.base}/flash` : ''));
  return tr;
}

/** The backup place's own protection: its share's mode (the packages' history lies in its snapshots) */
function placeLevel(kopiaOn) {
  const set = state.settings || {};
  const sh = (state.shares || []).find((x) => x.name === set.dumps_share);
  if (!sh || sh.mode === 'off') return 'none';
  return sh.mode === 'kopia' && kopiaOn ? 'offsite' : 'local';
}

const vmPackage = (name) => { const pk = packages(); return pk ? pk.vms.find((x) => x.name === name) || null : null; };

/** A package whose app or VM is gone (the agent's packages_gone, agent/desks/backup-gone.php) - or null */
const goneOf = (kind, folder) => ((state && state.packages_gone) || []).find((g) => g.kind === kind && g.folder === folder) || null;

/**
 * The packages whose app or VM no longer exists (2026-10-17: Mr. Restori's drill said «image missing» for them on every
 * run): a line each with «Into the storeroom» - Ms. Dustdevil's, put back or emptied there, never deleted at once
 */
function goneCallout() {
  const all = (state && state.packages_gone) || [];
  if (!all.length) return null;
  const box = el('div', 'callout bk-gone');
  const list = (gs, line) => {
    const ul = el('ul', 'shortlist');
    gs.forEach((g) => {
      const li = el('li', '', line(g));
      const b = button(T('pk.gone_aside'), 'small', () => packageAside(g));
      b.title = T('pk.gone_aside_tip');
      b.disabled = !Office.agent.running || !!state.running;
      li.appendChild(b);
      ul.appendChild(li);
    });
    box.appendChild(ul);
  };
  // gone, and (owner 2026-10-17) moved: a stale package whose app another app's current package backs up now - by the
  // same containers or the same own folder, said which
  const gone = all.filter((g) => !g.moved_to);
  const moved = all.filter((g) => g.moved_to && (g.signal === 'containers' || g.signal === 'folders'));
  if (gone.length) {
    box.appendChild(el('p', '', T('pk.gone_intro', { n: gone.length })));
    list(gone, (g) => T('pk.gone_line.' + g.kind, { name: g.name }));
  }
  if (moved.length) {
    box.appendChild(el('p', '', T('pk.moved_intro', { n: moved.length })));
    list(moved, (g) => T('pk.moved_line.' + g.signal, { name: g.name, other: g.moved_to }));
  }
  return box;
}

/** «Into the storeroom»: on a fresh look, the package looked up again; a dialog says where it goes and how it comes back */
async function packageAside(g0) {
  if (!(await Office.freshState(ID))) return;
  const g = goneOf(g0.kind, g0.folder);
  if (!g) { Office.toast(Office.errorText({ key: 'package_aside_gone', params: { name: g0.name } }, ID), true); render(); return; }
  const box = el('div');
  box.appendChild(el('p', '', g.moved_to ? T('pk.moved_text', { name: g.name, other: g.moved_to, signal: T('pk.signal.' + (g.signal === 'folders' ? 'folders' : 'containers')) })
    : T('pk.gone_text.' + g.kind, { name: g.name })));
  box.appendChild(el('p')).appendChild(copyCode(g.path));
  box.appendChild(el('p', 'role', T('pk.gone_back')));
  Office.dialog({
    title: T('pk.gone_title', { name: g.name }),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('pk.gone_aside'), act: async () => {
      const j = await Office.api.post(`${ID}.package_aside`, { kind: g.kind, folder: g.folder });
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
      if (j.state) state = j.state;
      Office.toast(T('pk.gone_done', { name: g.name }));
      render();
      return true;
    } }],
  });
}

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

const HIST_ID = 'bk-history';                 // the history's section (it holds the list and its bar)
/** The runs his history lists (and, between them, the runs that were skipped): newest first, at most 20 — with the
 *  words its bar meets (the date as the row shows it, the result, what the row says beside it). Pure. */
function historyRows() {
  const runs = state.history || [];
  const skips = state.skips || [];
  return [...runs.slice(0, 20).map((r) => ({ t: r.started, r })), ...skips.map((skip) => ({ t: skip.time, skip }))]
    .sort((a, b) => b.t - a.t).slice(0, 20).map((x) => ({ ...x, hay: x.skip
      ? [fmt.date(x.skip.time, true), T('result.skipped'), messageText(x.skip.reason)]
      : [fmt.date(x.r.started, true), T('result.' + x.r.result), x.r.message && x.r.result !== 'ok' ? messageText(x.r.message, x.r.message_params) : '',
        asleepUnits(x.r) ? T('asleep.left_out', { n: asleepUnits(x.r) }) : ''] }));
}
/** The history's bar: words only (no «only …», no tile) — not kept */
function histFilterBar() {
  if (!histBar) {
    histBar = Office.filterBar({ key: 'backup.history', label: T('history_filter_label'), onChange: () => switcher().render() });
  }
  return histBar;
}

function historySection() {
  const runs = state.history || [];
  const box = section(T('history'), T('history_sub'), { place: 'history' });
  box.id = HIST_ID;
  const all = historyRows();
  if (!all.length) { box.appendChild(el('p', 'empty', T('bubble.no_runs'))); return box; }
  const bar = histFilterBar();
  box.appendChild(bar.el);
  const words = bar.read().words;
  const rows = all.filter((x) => Office.fits(words, x.hay));
  const list = el('div', 'box');
  const longest = Math.max(...runs.map((r) => (r.finished || r.started) - r.started), 1);
  if (!rows.length) list.appendChild(el('p', 'bk-filter-none', T('filter_none')));
  rows.forEach(({ r, skip }) => {
    if (skip) { list.appendChild(skipRow(skip)); return; }
    const row = Office.place(`run:${r.started}`, el('div', 'row nocheck unfolds'));
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
    if (r.packages && (r.packages.apps || r.packages.vms)) meta.appendChild(el('span', '', T('pk.history', { apps: nOf('apps', r.packages.apps), vms: nOf('vms', r.packages.vms) })));
    if (r.errors || r.warnings) meta.appendChild(el('span', '', T('counts', { errors: nOf('errors', r.errors), warnings: nOf('warnings', r.warnings) })));
    if (r.message && r.result !== 'ok') meta.append(el('span', 'note', messageText(r.message, r.message_params)));
    warnLines(r).forEach((w) => meta.append(el('span', 'note', w)));
    main.appendChild(meta);
    row.appendChild(main);
    const took = (r.finished || r.started) - r.started;
    const hbar = el('div', 'bar thin bk-hbar');
    const fill = el('i', r.result === 'ok' || r.result === 'warnings' ? 'snaps' : 'data');
    fill.style.width = Math.max(2, Math.round(took / longest * 100)) + '%';
    hbar.appendChild(fill);
    row.appendChild(hbar);
    const fig = el('div', 'figures');
    fig.append(el('b', '', took ? dur(took) : '–'), el('span', '', ''));
    row.appendChild(fig);
    list.appendChild(row);
  });
  box.appendChild(list);
  bar.done(rows.length, all.length);
  return box;
}

/**
 * A drift note's parameters: the engine's params (2.41+, strings), over the value of the codes from before 2.41 (as
 * share and value); derived: where (a new share's places and its size)
 */
function driftParams(d) {
  const v = typeof d.value === 'string' ? d.value : '';
  const own = d.params && typeof d.params === 'object' && !Array.isArray(d.params) ? d.params : {};
  const p = { share: v, value: v };
  Object.keys(own).forEach((k) => { if (typeof own[k] === 'string' || typeof own[k] === 'number') p[k] = String(own[k]); });
  if (p.locations !== undefined) {
    const b = Number(p.bytes);
    p.where = [p.locations, p.bytes && b > 0 ? fmt.size(b) : ''].filter(Boolean).join(', ');
  }
  return p;
}

/**
 * A difference in the office's words: drift_code.<code> with its parameters (2.41+; before: the value) — the engine's
 * English text when the code is unknown here or a placeholder of the sentence has no parameter (an older engine)
 */
function driftText(d) {
  const p = driftParams(d);
  const key = `${ID}.drift_code.${d.code}`;
  if (d.code && Office.has(key)) {
    const raw = Office.strings[key];
    const need = typeof raw === 'string' ? [...raw.matchAll(/\{(\w+)\}/g)].map((m) => m[1]) : [];
    if (need.every((k) => p[k] !== undefined)) return T('drift_code.' + d.code, p);
  }
  if (d.code && Office.has(`${ID}.message.${d.code}`)) return T('message.' + d.code, { detail: p.value });
  return d.text;
}

/**
 * A run's coded warnings in the office's words (engine 2.42, warning_items: a VM paused instead of shut down or frozen,
 * one that couldn't be paused; 2.46 partner_missed: the copy to a partner that did not arrive, its why in
 * partner.why_short) - warn_code.<code>; a code unknown here or a sentence missing a parameter is left out
 * (the count stays in the run's line, the log has the engine's English)
 */
function warnLines(r, now) {
  const items = (r && r.warning_items) || [];
  return items.map((w) => {
    const key = `${ID}.warn_code.${w && w.code}`;
    if (!w || typeof w.code !== 'string' || !Office.has(key)) return '';
    if (w.code === 'btrfs_brake') return brakeLine(r, w, items);
    // now (the bubble): Nextcloud's open data folder is said by its callout with «Close the folder…» while open, and not
    // at all once closed - the run's row keeps it (an agent before 1.66.8 lists no nc_open: said as before)
    if (now && w.code === 'nc_datadir_readable' && state && Array.isArray(state.nc_open)) return '';
    const p = {};
    Object.entries(w.params && typeof w.params === 'object' ? w.params : {}).forEach(([k, v]) => { if (typeof v === 'string') p[k] = v; });
    if (p.why !== undefined) p.why = partnerWhyShort(p.why);      // engine 2.46 partner_missed: the reason in the office's words
    const raw = Office.strings[key];
    const need = typeof raw === 'string' ? [...raw.matchAll(/\{(\w+)\}/g)].map((m) => m[1]) : [];
    return need.every((k) => p[k] !== undefined) ? T('warn_code.' + w.code, p) : '';
  }).filter(Boolean);
}

/** A snapshot's name (YYYYMMDD-HHMM, the engine's btrfs folders) as seconds, local time - 0 for anything else */
function snapSeconds(name) {
  const m = /^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})$/.exec(String(name || ''));
  return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]).getTime() / 1000 : 0;
}

/**
 * The btrfs emergency brake's early deletes (engine 2.63, warning_items btrfs_brake: base, free_gb, floor_gb, snap) -
 * one line per disk, at its first item: how much was free, where the brake stands, how many went (the run's btrfs facts
 * count them all when the items were cut short) and the oldest of them
 */
function brakeLine(r, w, items) {
  const p = w.params && typeof w.params === 'object' ? w.params : {};
  const same = items.filter((x) => x && x.code === 'btrfs_brake' && x.params && x.params.base === p.base);
  if (same[0] !== w) return '';
  const when = snapSeconds(p.snap);
  if (typeof p.base !== 'string' || typeof p.free_gb !== 'string' || typeof p.floor_gb !== 'string' || !when) return '';
  const facts = ((r && r.btrfs) || []).find((b) => b && b.base === p.base && !b.asleep);
  const n = Math.max(same.length, (facts && Number(facts.released)) || 0);
  return T('warn_code.btrfs_brake', { n, base: p.base, free_gb: p.free_gb, floor_gb: p.floor_gb, date: fmt.date(when) });
}

/**
 * What the btrfs emergency brake left behind, per disk or pool (engine 2.63, a run's btrfs facts), from the newest backup
 * run that looked at it (one asleep then: an earlier run's word); a disk the newest run with facts no longer lists is not
 * said. The retention not held (held false while the newest snapshot the brake released is younger than keep_days): how
 * many of his are left and the oldest. The brake reached with none of his left to release (none_left - never a warning,
 * engine 2.23: only space made outside the backup helps): said here, beside it
 */
function brakeGaps() {
  const runs = (state && state.history) || [];
  const newest = runs.find((r) => Array.isArray(r.btrfs));
  if (!newest) return [];
  return newest.btrfs.flatMap((b) => {
    const facts = b.asleep ? runs.map((r) => (Array.isArray(r.btrfs) ? r.btrfs.find((x) => x.base === b.base && !x.asleep) : null)).find(Boolean) : b;
    if (!facts) return [];
    const out = [];
    if (facts.held === false) {
      const keep = nOf('days', facts.keep_days);
      const oldest = snapSeconds(facts.oldest);
      out.push(!facts.kept || !oldest ? T('brake.gap_none', { base: facts.base, keep })
        : T('brake.gap', { n: facts.kept, base: facts.base, keep, date: fmt.date(oldest) }));
    }
    if (facts.none_left === true) out.push(T('brake.none_left', { base: facts.base, free_gb: String(facts.free_gb), floor_gb: String(facts.floor_gb) }));
    return out;
  });
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

/** «Schedule» as a section of one row: how often, the next backup and when it should be done — «Change…» opens the dialog */
function scheduleSection() {
  const sc = state.schedule || {};
  const change = button(T('schedule.change'), 'small', scheduleDialog);
  change.disabled = !Office.agent.running;           // the messenger away: nobody would take the request
  const box = section(T('schedule.title'), T('schedule.section_sub'), change, { place: 'schedule.title' });
  const list = el('div', 'box');
  const row = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  const meta = el('div', 'row-meta');
  if (!sc.script) {
    main.appendChild(el('div', 'row-name text', T('stat.not_scheduled')));
    meta.appendChild(el('span', 'note', T('schedule.no_settings')));
  } else if (!sc.enabled) {
    main.appendChild(el('div', 'row-name text', T('schedule.off')));
    meta.append(chip(T('stat.not_scheduled'), 'warn'), el('span', 'note', T('schedule.off_hint')));
  } else {
    main.appendChild(el('div', 'row-name text', sc.frequency === 'custom' ? fmt.cron(sc.custom) : T('freq.' + sc.frequency)));
    if (sc.next) {
      const next = el('span', '', T('stat.next', { when: fmt.relative(sc.next) }));
      next.title = fmt.date(sc.next);
      meta.appendChild(next);
      const took = typicalDuration();
      if (took) meta.appendChild(el('span', '', T('schedule.duration', { duration: fmt.duration(took), end: fmt.time(sc.next + took) })));
    }
  }
  main.appendChild(meta);
  row.appendChild(main);
  list.appendChild(row);
  box.appendChild(list);
  return box;
}

/** «Partners» as a section: a row per partner office — what the last backup sent there, how fast, what was left out and why */
function partnersSection() {
  const box = section(T('partner.section'), T('partner.section_sub'), { place: 'partner.section' });
  const list = el('div', 'box');
  (state.partners || []).forEach((p) => {
    const row = Office.place(`partner:${p.id}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    const [first, ...rest] = partnerParts(p);
    main.appendChild(el('div', 'row-name text', first));
    const meta = el('div', 'row-meta');
    if (p.current) meta.appendChild(chip(T('result.running'), 'accent'));
    else if (p.last && p.last.failed.length) meta.appendChild(chip(T('failed'), 'danger'));
    else if (partnerTrouble(p)) meta.appendChild(chip(T('result.skipped'), 'warn'));
    rest.forEach((x) => meta.appendChild(el('span', '', x)));
    if ((p.units || []).length) meta.appendChild(el('span', 'note', T('partner.units_line', { list: p.units.map(unitLabel).join(', ') })));
    if (p.apply) { const go = el('a', '', T('partner.open_setup')); go.href = '#/backup/setup'; meta.appendChild(go); }
    main.appendChild(meta);
    row.appendChild(main);
    list.appendChild(row);
  });
  box.appendChild(list);
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
    // a full backup sends to Kopia only while it is switched on in the settings: say what really happens; a run without
    // the offsite upload still sends to the partners whose units have a partner level (engine 2.42)
    const to = m === 'nokopia' ? ((state.partners || []).filter((p) => (p.units || []).length).map((p) => p.name)) : [];
    text.appendChild(el('small', '', to.length ? T('mode_hint.nokopia_partners', { partners: to.join(', ') })
      : T(m === 'backup' && !kopia ? 'mode_hint.backup_local' : 'mode_hint.' + m)));
    label.append(input, text);
    box.appendChild(label);
  });
  const est = state.estimates || {};
  if (est.total) box.appendChild(el('p', 'callout', T('start_hint', { duration: dur(est.total) })));
  const taken = minuteTaken();
  const note = taken ? el('p', 'callout', T('minute.queue_note', { time: fmt.time(taken.at) })) : null;
  if (note) box.appendChild(note);
  const d = Office.dialog({
    title: T('start_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('start_go'), kind: '', act: () => startRun(mode) }],
    onClose: () => clearInterval(minuteTimer),
  });
  // one run a minute: its button grey until the minute changes (the head's buttons come back with the next drawing)
  if (d && d.buttons) minuteGuard([d.buttons[1]], () => false, () => { if (note) note.remove(); });
}

async function startRun(mode) {
  if (!(await Office.freshState(ID))) return false;
  const j = await Office.api.post(`${ID}.start`, { mode });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
  if (j.state) state = j.state;
  // something took the lock between the agent's look and the start: the engine skipped it and says why
  if (j.skipped) Office.toast(skipText(j.skipped), true);
  else if (j.queued) Office.toast(T('minute.queued', { time: fmt.time(j.queued.at) }));
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

/** «The repository is new…» (engine 2.56): asked once more, the safe default «Cancel»; only the hold the page showed ends */
async function heldAccept() {
  if (!(await Office.freshState(ID))) return;
  const held = state.kopia_held;
  if (!held) { render(); return; }
  const d = Office.dialog({
    title: T('held.accept_title'),
    body: el('p', '', T('held.accept_text')),
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('held.accept_go'), kind: 'danger', act: async () => {
        const j = await Office.api.post(`${ID}.kopia_accept`, { repo: held.repo });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); if (j.error && j.error.key === 'kopia_held_stale') load(true); return true; }
        if (j.state) state = j.state;
        Office.toast(T('held.accepted'));
        render();
        return true;
      } },
    ],
  });
  d.buttons[0].focus();           // safe default: Enter cancels
}

/** The Nextcloud data folders the last run found readable by others and still open now (agent backupNcOpen()); null: an older agent */
const ncOpen = () => (state && Array.isArray(state.nc_open) ? state.nc_open : []);
const ncOwner = (x) => `${x.user}:${x.group}`;

/** «Close the folder…»: on a fresh look, what changes - the folder's root only, owner and permissions before → after */
async function ncOpenClose(name) {
  if (!(await Office.freshState(ID))) return;
  const o = ncOpen().find((x) => x.name === name);
  if (!o) { Office.toast(Office.errorText({ key: 'nc_open_gone', params: { name } }, ID), true); render(); return; }
  const box = el('div');
  box.appendChild(el('p', '', T('nc_open.text', { name: o.name })));
  const ul = el('ul', 'shortlist');
  [[T('nc_open.folder'), o.path], [T('nc_open.owner'), `${ncOwner(o.now)} → ${ncOwner(o.after)}`], [T('nc_open.mode'), `${o.now.mode} → ${o.after.mode}`]].forEach(([k, v]) => {
    const li = el('li', '', k);
    li.appendChild(el('span', '', v));
    ul.appendChild(li);
  });
  box.appendChild(ul);
  // Unraid 7.4 leaves a share's root alone only when it belongs to the group users and to someone other than nobody
  box.appendChild(o.comes_back ? el('p', 'callout warn', T('nc_open.comes_back', { owner: ncOwner(o.after) })) : el('p', 'role', T('nc_open.stays')));
  const d = Office.dialog({
    title: T('nc_open.title', { name: o.name }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('nc_open.go'), kind: '', act: async () => {
        const j = await Office.api.post(`${ID}.nc_open_close`, { name: o.name });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); load(true); return true; }
        if (j.state) state = j.state;
        Office.toast(T('nc_open.done', { path: j.path, owner: ncOwner(j.after), mode: j.after.mode }));
        render();
        return true;
      } },
    ],
  });
  d.buttons[0].focus();           // safe default: Enter cancels
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
  Office.dialog({ title: name, body: box, wide: 'log', onClose: () => clearTimeout(timer) });
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
  depsOff: new Set(), depNote: new Map(),   // «… mitsichern» unticked by the user, and the word a click left on its row
  choice: null,              // the default chosen on this page, not applied yet: {kind: auto | local | kopia, all, items}
  newItems: new Set() };     // what is new since the last setup (setupNewItems()), as item keys
let setupTimer = null;

/**
 * A <details> of the setup the user opened or closed stays so: the setup page is drawn anew often (each poll while a
 * plan or an apply runs, the messenger's word with every answer of the agent) — kept per key for this page's life,
 * its default (open while no backup place is chosen, the messages open with errors or warnings) only until touched
 */
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

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const clone = (o) => JSON.parse(JSON.stringify(o || {}));
const dget = (k, d) => (setup.draft[k] !== undefined ? setup.draft[k] : d);
function dset(k, v) {
  if (v === undefined || v === null) delete setup.draft[k];
  else setup.draft[k] = v;
  setupBar();
}

/**
 * A share where no snapshot is possible (the plan's method live: XFS or btrfs-less disks, a share over XFS and ZFS) is
 * backed up by nothing at «local snapshot» (engine 2.55: the setup proposes «off» there and warns share_no_snapshots).
 * shareLocal(): what «local» means for a share — the snapshot, or «not backed up» where none is possible.
 */
const shareNoSnap = (sh) => !!sh && sh.exists !== false && sh.method === 'live';
const shareLocal = (name) => (shareNoSnap(((setup.plan && setup.plan.shares) || []).find((sh) => sh.name === name)) ? 'off' : 'snapshot');

/** A fresh draft from the plan, with the apps' and VMs' levels read from it */
function setupDraftFromPlan() {
  setup.draft = clone(setup.plan.P);
  setup.itemKeep = {};
  if (!setup.plan.have_settings) {
    // a new setup sends nothing to Kopia unasked: every share starts local, the user picks what goes to Kopia
    Object.keys(setup.draft).forEach((k) => { if (/^share\|.+\|mode$/.test(k) && setup.draft[k] === 'kopia') setup.draft[k] = shareLocal(k.slice(6, -5)); });
  } else {
    // a share new since the last setup goes to Kopia only when asked, like a new app or VM (the engine proposes «new»
    // as kopia): unasked, the backup place followed it to Kopia too (setupDerive()) - two changes nobody chose
    // (2026-10-19, a share added in Unraid). The default for new things («local + Kopia») still sends it (presetApplyNew())
    setupNewItems(setup.plan, setupSaved()).forEach((it) => {
      const k = /^share:(.+)$/.test(it) ? `share|${it.slice(6)}|mode` : null;
      if (k && setup.draft[k] === 'kopia') setup.draft[k] = shareLocal(it.slice(6));
    });
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
  setupMigrated();
  setupDeclinedApply();                    // 1.60.1: what the user declined stays declined (the same value)
  setup.base = clone(setup.draft);         // what the assistant proposes (with the default), before the user clicks
  setup.baseLevels = { ...setup.levels };
  setup.baseHeld = { ...setup.held };
}

/**
 * The units the pairs sent up to 1.59 (the migration step partner-simple, plan.migrated {id: [unit…]} — only those
 * settings.ini lacks the partner line for): proposed at «local + partner» as the draft's proposal, the user applies.
 * Only a unit that can travel and is backed up; the place through general|partner_place
 */
function setupMigrated() {
  const plan = setup.plan;
  const mig = plan.migrated && typeof plan.migrated === 'object' ? plan.migrated : {};
  const ids = setupPartners().map((p) => p.id);
  const place = dget('general|dumps_share', '');
  Object.entries(mig).forEach(([id, units]) => {
    if (!ids.includes(id) || !Array.isArray(units)) return;
    units.forEach((u) => {
      let key = null;
      let ok = false;
      const m = /^(share|vm):(.+)$/.exec(String(u));
      if (u === 'place') { const row = plan.shares.find((x) => x.name === place); key = row ? 'general|partner_place' : null; ok = !!(row && row.partner_ok && dget(`share|${place}|mode`, 'off') !== 'off'); }
      else if (m && m[1] === 'share') { const row = plan.shares.find((x) => x.name === m[2]); key = row && row.name !== place ? `share|${m[2]}|partner` : null; ok = !!(row && row.partner_ok && dget(`share|${m[2]}|mode`, 'off') !== 'off'); }
      else if (m && m[1] === 'vm') { const v = (plan.vms || []).find((x) => x.name === m[2]); key = v ? `vm|${m[2]}|partner` : null; ok = !!(v && v.partner_ok && levelOf('vm:' + m[2]) > 0); }
      if (!key || !ok) return;
      const now = dget(key, []) || [];
      if (!now.includes(id)) dset(key, [...now, id]);
    });
  });
}

/**
 * A new plan came in (a tour, measuring, the setup opened again): the proposals are read anew, but what the
 * user chose and hasn't applied stays - a key the engine still proposes as before keeps the user's value (one
 * it proposes differently now takes the new proposal), a level or hold picked stays while the app or VM is there
 */
function setupDraftKeep() {
  const old = setup.draft && setup.base ? { draft: setup.draft, base: setup.base, levels: setup.levels, held: setup.held,
    baseLevels: setup.baseLevels || {}, baseHeld: setup.baseHeld || {}, deps: setup.deps, depsOff: setup.depsOff, depNote: setup.depNote } : null;
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
  // the «… mitsichern» boxes clicked: kept while that app still uses that share
  const there = new Set(setup.model.apps.flatMap((a) => a.deps.map((d) => `${a.id}|${d.share}`)));
  old.deps.forEach((k) => { if (there.has(k)) { setup.deps.add(k); kept++; } });
  old.depsOff.forEach((k) => { if (there.has(k)) setup.depsOff.add(k); });
  old.depNote.forEach((v, k) => { if (there.has(k)) setup.depNote.set(k, v); });
  if (kept) setupDerive();
}

/** Keys whose values differ between two sets of settings (nothing and empty count the same) */
const empty = (v) => v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length);
// lists that are sets: their order is Docker's of the moment (it changes when a container is made anew) — never a change
// in itself (2026-10-09: «2 Änderungen» every time he opened the setup, the same containers in another order)
const SET_KEYS = /^docker\|(no_stop|known|skip)$/;
const asSet = (k, v) => (SET_KEYS.test(k) && Array.isArray(v) ? [...v].map(String).sort() : v);
function diffKeys(a, b) {
  return [...new Set([...Object.keys(a), ...Object.keys(b)])]
    .filter((k) => !same(asSet(k, a[k]), asSet(k, b[k])) && !(empty(a[k]) && empty(b[k]))).sort();
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
  // a key with a default of the engine's own: missing means that default, whatever the plan proposes (asleep_pools
  // missing = wake - a plan's «skip» must show as a change, not as what is saved)
  Object.entries(ENGINE_DEFAULTS).forEach(([k, v]) => { if (!(k in O) && (k in P || k in saved)) saved[k] = v; });
  // a share's method missing is auto (the engine writes only live): a new share's «auto» is nothing Apply writes
  Object.keys(P).forEach((k) => { if (/^share\|.+\|method$/.test(k) && !(k in O) && !(k in saved)) saved[k] = 'auto'; });
  return saved;
}

/** What the engine takes when settings.ini leaves a key out (setup.sh / lib/common.sh apply_settings()) */
const ENGINE_DEFAULTS = { 'general|asleep_pools': 'wake', 'general|preset_new': 'auto' };

/**
 * Keys Apply sends only when the user chose them on this page (2026-10-08 on a large server: its «skip» since the day
 * before went back to «wake» at an Apply that never listed it). The draft carries every key of the plan it was made
 * from; a page whose plan is older than settings.ini - a second tab, the same tab left open - would otherwise send the
 * old plan's value back, and the dialog, comparing with that plan's settings, could not see it. Left out, setup.sh
 * keeps what settings.ini says now (decisions_load() lays only what is sent over it).
 */
const ONLY_CHOSEN = ['general|asleep_pools'];

/** The [app] sections of settings.ini whose app is gone (engine 2.40, the plan's apps[] marked gone): Apply removes them */
const goneApps = (plan) => ((plan && Array.isArray(plan.apps)) ? plan.apps : []).filter((a) => a && a.gone && typeof a.name === 'string' && a.name !== '');
/** Is this key one of a gone app's section? */
const goneAppKey = (k, plan) => goneApps(plan).some((a) => k.startsWith(`app|${a.name}|`));
/** The [share] sections Apply removes: a share that no longer exists, or one renamed (its settings went to the new name) -
 *  setup.sh writes no section for them, whatever is sent (2026-10-19: removed without a word in the dialog) */
const goneShares = (plan) => ((plan && Array.isArray(plan.shares)) ? plan.shares : [])
  .filter((sh) => sh && (sh.why === 'gone' || sh.why === 'renamed_to') && typeof sh.name === 'string' && sh.name !== '');
/** Is this key one of a gone share's section? */
const goneShareKey = (k, plan) => goneShares(plan).some((sh) => k.startsWith(`share|${sh.name}|`));

/** The decisions Apply sends: the draft, without the keys of ONLY_CHOSEN the user didn't choose here, never a gone app's */
function setupDecisions() {
  const out = { ...setup.draft, _retire_sources: setup.retire ? 'yes' : 'no' };
  const base = setup.base || setup.plan.P || {};
  ONLY_CHOSEN.forEach((k) => { if (same(base[k], setup.draft[k])) delete out[k]; });
  Object.keys(out).forEach((k) => { if (goneAppKey(k, setup.plan)) delete out[k]; });
  return out;
}

/**
 * What the apply dialog lists: against the saved settings, the values Apply really sends (a key it leaves out
 * stays as saved) - every `general|…` among them in words (changeLabel(), valueText())
 */
function setupApplyChanges(saved) {
  const sent = setupDecisions();
  const view = { ...setup.draft };
  ONLY_CHOSEN.forEach((k) => { if (!(k in sent)) { if (saved[k] === undefined) delete view[k]; else view[k] = saved[k]; } });
  Object.keys(view).forEach((k) => { if (goneAppKey(k, setup.plan) || goneShareKey(k, setup.plan)) delete view[k]; });
  return { view, keys: setupChanges(saved, view) };
}

/**
 * The apply dialog's mark (1.60.1, a user: one look throughout the list): + something comes, − something goes - a gone
 * app or partnership, a list's members, what is new on the server. Its word for a screen reader (setup.mark_*); a value
 * that changes stays «old → new» without one
 */
function diffMark(kind) {
  const b = el('b', `bk-diff ${kind}`, kind === 'add' ? '+' : '−');
  b.setAttribute('role', 'img');
  b.setAttribute('aria-label', T(kind === 'add' ? 'setup.mark_add' : 'setup.mark_gone'));
  b.title = T(kind === 'add' ? 'setup.mark_add' : 'setup.mark_gone');
  return b;
}

/** A list's change in the apply dialog: only what comes and what goes («+ drop · − Grafana», marked), not both lists
 * whole (2026-10-09: «wenn ich immer alles sehe, sehe ich nicht, welche 2 Apps geändert wurden»); order alone is none.
 * Nodes and text for append() */
function listChangeText(was, is) {
  const a = was.map(String);
  const b = is.map(String);
  const add = b.filter((x) => !a.includes(x));
  const gone = a.filter((x) => !b.includes(x));
  const parts = [];
  if (add.length) parts.push([diffMark('add'), ' ' + add.join(', ')]);
  if (gone.length) parts.push([diffMark('gone'), ' ' + gone.join(', ')]);
  return parts.length ? parts.flatMap((x, i) => (i ? [' · ', ...x] : x)) : [T('setup.list_order')];
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

/** What Apply really changes in settings.ini (as its dialog lists it), split: `yours` the keys the user touched here,
    `mine` the rest — proposals of Mr. Backupsy's, each one the user can leave out in the dialog (setupDecline()) */
const UNIT_PARTNER_KEY = /^(share\|.+\|partner|vm\|.+\|partner|app\|.+\|partner|general\|partner_place)$/;

/**
 * What Apply changes, as the bar and the dialog both count it (1.60.1, a user: the bar said «9 changes», the dialog showed
 * two partnership rows and a unit): a key each, but a gone app's section once (engine 2.40), a [partner] section once
 * per partner (partnerSectionRows()), a unit's partner line once per unit (the dialog's «To <partner>» group).
 * {saved, view, keys, items: [{id, kind: key|app_gone|share_gone|partner|unit, keys, yours}]} - yours: the user touched one of its keys
 */
function setupChangeItems() {
  const saved = setupSaved();
  const { view, keys } = setupApplyChanges(saved);
  const touched = new Set(setupEdits());
  const groups = new Map();
  keys.forEach((k) => {
    const p = k.split('|');
    const [id, kind] = goneAppKey(k, setup.plan) ? [`app|${p[1]}|`, 'app_gone'] : goneShareKey(k, setup.plan) ? [`share|${p[1]}|`, 'share_gone'] : p[0] === 'partner' ? [`partner|${p[1]}|`, 'partner']
      : UNIT_PARTNER_KEY.test(k) ? [k, 'unit'] : [k, 'key'];
    if (!groups.has(id)) groups.set(id, { id, kind, keys: [] });
    groups.get(id).keys.push(k);
  });
  const items = [...groups.values()].map((g) => ({ ...g, yours: g.keys.some((k) => touched.has(k)) }));
  return { saved, view, keys, items };
}

function setupSplit() {
  const { items } = setupChangeItems();
  return { yours: items.filter((x) => x.yours).map((x) => x.id), mine: items.filter((x) => !x.yours).map((x) => x.id) };
}

/** «Don't take it»: a proposal of mine back to what settings.ini says now (2026-10-10: no way to apply his change
    without my proposal) — in the draft only; Apply then sends the saved value, the next plan may propose it again */
function setupDecline(k) {
  if (k.startsWith('partner|')) return;          // a [partner] section mirrors the Team Lead's pairs - never left out
  const proposed = setup.draft[k];
  const saved = setupSaved();
  if (saved[k] === undefined) delete setup.draft[k]; else setup.draft[k] = Array.isArray(saved[k]) ? [...saved[k]] : saved[k];
  // 1.60.1 (a user: declined proposals came back at every plan): remembered with the value proposed - the same proposal
  // isn't made again; another value, or a re-proposal asked for («Propose again»), shows it once more
  setup.plan.declined = { ...(setup.plan.declined || {}), [k]: { value: proposed === undefined ? null : proposed, time: Math.floor(Date.now() / 1000) } };
  if (setup.base) setup.base[k] = setup.draft[k];
  if (Office.api) {                         // (the tests run the page without the messenger)
    Office.api.post(`${ID}.setup_decline`, { key: k, value: proposed === undefined ? null : proposed }).then((j) => {
      if (!j.ok) Office.toast(Office.errorText(j.error, ID), true);
    });
  }
}

/** The declines (plan.declined: key → {value, time}) laid over a fresh draft: a key whose proposal is still the value
 *  declined keeps what settings.ini says; a proposal that changed since is shown again */
function setupDeclinedApply() {
  const dec = (setup.plan && setup.plan.declined) || {};
  const saved = setupSaved();
  Object.entries(dec).forEach(([k, d]) => {
    if (k.startsWith('partner|') || !d || !same(setup.draft[k] === undefined ? null : setup.draft[k], d.value)) return;
    if (saved[k] === undefined) delete setup.draft[k]; else setup.draft[k] = Array.isArray(saved[k]) ? [...saved[k]] : saved[k];
  });
}

/** «Declined proposals» under the setup's general settings: each with «Propose again» */
function declinedList() {
  const dec = Object.entries((setup.plan && setup.plan.declined) || {}).filter(([k]) => !k.startsWith('partner|'));
  if (!dec.length) return null;
  const f = el('div', 'field bk-declined');
  f.appendChild(el('div', 'field-title', T('setup.declined', { n: dec.length })));
  const ul = el('ul', 'shortlist');
  dec.forEach(([k, d]) => {
    const li = el('li', '', changeLabel(k));
    const what = el('span', '', valueText(d.value === null ? undefined : d.value, k));
    const again = el('button', 'btn small plain', T('setup.decline_undo'));
    again.type = 'button';
    again.onclick = async () => {
      again.disabled = true;
      const j = await Office.api.post(`${ID}.setup_decline_undo`, { key: k });
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); again.disabled = false; return; }
      const next = { ...(setup.plan.declined || {}) };
      delete next[k];
      setup.plan.declined = next;
      setupDraftKeep();
      renderSetup();
    };
    what.append(' ', again);
    li.appendChild(what);
    ul.appendChild(li);
  });
  f.appendChild(ul);
  return f;
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
  // a plan of an older engine (an update since) is made anew by the agent on this look, once (backupSetupReplan()):
  // no plan comes while it runs, the new one says so in its messages (setup.msg.replanned)
  if (j.plan && (!setup.plan || j.plan.time !== setup.plan.time)) {
    setup.plan = j.plan;
    setupDraftKeep();
    Office.placesTook(ID, j.plan, 'setup');     // its rows for the office's search (setupSearchItems()) - the plan as it came
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

/**
 * Engine 2.50: «0 0 0» local snapshots only for what goes to no partner office (a partner needs one snapshot both sides
 * still have). The draft's first unit that goes to a partner and would keep none, else null - the rule of the agent's
 * backupZeroPartner() and the engine's cfg_validate: a VM's own retention, else its share's (an app's first folder's,
 * the backup place's), else the shared one («7 4 6» unset).
 */
function setupZeroPartner() {
  const str = (k) => String(dget(k, '') || '').trim();
  const ret = (share) => (share && str(`share|${share}|retention`)) || str('zfs|retention') || '7 4 6';
  const zero = (r) => /^\d+ \d+ \d+$/.test(r) && r.split(' ').every((x) => Number(x) === 0);
  for (const k of Object.keys(setup.draft)) {
    const v = setup.draft[k];
    if (!Array.isArray(v) || !v.some((id) => typeof id === 'string' && /^[0-9a-f]{8}$/.test(id))) continue;
    let m;
    if (k === 'general|partner_place') { if (zero(ret(str('general|dumps_share')))) return str('general|dumps_share'); }
    else if ((m = /^share\|(.+)\|partner$/.exec(k))) { if (zero(ret(m[1]))) return m[1]; }
    else if ((m = /^vm\|(.+)\|partner$/.exec(k))) { if (zero(str(`vm|${m[1]}|retention`) || ret(''))) return m[1]; }
    else if ((m = /^app\|(.+)\|partner$/.exec(k))) {
      const f = dget(`app|${m[1]}|folder`, []);
      const first = Array.isArray(f) ? String(f.find((x) => typeof x === 'string') || '') : String(f || '');
      if (zero(ret(first.split('/')[0]))) return m[1];
    }
  }
  return null;
}

function setupApply() {
  const ds = dget('general|dumps_share', '');
  if (!ds) { Office.toast(T('setup.ds_missing'), true); return; }
  if (dget(`share|${ds}|mode`, 'off') === 'off') { Office.toast(T('setup.ds_off', { share: ds }), true); return; }
  const zero = setupZeroPartner();
  if (zero !== null) { Office.toast(T('errors.setup_zero_partner', { name: zero }), true); return; }
  const box = el('div');
  if (!setup.plan.have_settings) {
    // nothing saved yet: no list of differences, but what will be backed up how
    box.appendChild(el('p', '', T('setup.apply_new')));
    box.appendChild(setupSummary());
  } else {
    // what really changes in settings.ini - against the saved file, not against proposals
    const saved = setupSaved();
    // what Apply really sends (setupApplyChanges()); the units' partner lists have their own group below («To partners»)
    // counted as the bar counts (setupChangeItems()): a key each, a gone app and a [partner] section once, a unit's
    // partner line once per unit (listed in «To partners» below)
    const { view, keys, items } = setupChangeItems();
    // a gone app's section (engine 2.40): one line «gone — entry will be removed», never key by key, never declined
    const gone = goneApps(setup.plan).filter((a) => keys.some((k) => k.startsWith(`app|${a.name}|`)));
    // a gone share's section the same way, said with why: gone, or renamed (its settings went along) - never declined
    const goneSh = goneShares(setup.plan).filter((sh) => keys.some((k) => k.startsWith(`share|${sh.name}|`)));
    // the [partner] sections mirror the Team Lead's pairs: one row per partner (partnerSectionRows()), never key by key
    // what is new on the server stands once, in its own group below with its proposal (Benj 2026-10-12: «Share Schule»
    // was in the changes AND under «New on the server») - its keys (own: `share|<name>|` …) leave the changes list;
    // still counted in `total`, the bar's count (setupChangeItems())
    const news = setupNewRows();
    const ownOf = (k) => news.find((r) => r.own && k.startsWith(r.own));
    const changes = items.filter((x) => x.kind === 'key' && !ownOf(x.id)).map((x) => x.id);
    const prows = partnerSectionRows(keys, saved, view);
    const total = items.length;
    box.appendChild(el('p', '', total ? T('setup.apply_text', { n: total }) : T('setup.apply_none')));
    if (total) {
      const ul = el('ul', 'shortlist');
      // the settings for the whole backup first - never lost among 80 share lines
      const general = (k) => (k.startsWith('general|') ? 0 : 1);
      const touched = new Set(setupEdits());
      [...changes].sort((a, b) => general(a) - general(b)).slice(0, 80).forEach((k) => {
        const li = el('li', '', changeLabel(k));
        if (unknownKey(k)) li.title = T('setup.key_unknown_tip');
        const what = el('span');
        what.append(...[].concat(/\|kopia_known$/.test(k) ? knownText(saved[k], view[k])
          : Array.isArray(saved[k]) && Array.isArray(view[k]) ? listChangeText(saved[k], view[k])
          : valueChange(saved[k], view[k], k)));
        li.appendChild(what);
        // a proposal of mine (the user didn't touch it): said so, and it can stay out of this Apply
        // (a line the setup doesn't know is no proposal: declined or not, Apply can't keep it)
        if (!touched.has(k) && !/\|kopia_known$/.test(k) && !droppedKey(k)) {
          what.appendChild(document.createTextNode(' '));
          what.appendChild(chip(T('setup.proposal'), 'quiet', T('setup.proposal_tip')));
          const no = el('button', 'btn small plain', T('setup.decline'));
          no.type = 'button';
          no.onclick = () => { setupDecline(k); renderSetup(); setupApply(); };      // the list anew, without it
          what.appendChild(no);
        }
        ul.appendChild(li);
      });
      // a partnership gone or come (the Team Lead's pairs are the truth): a − / + row each, never declined
      prows.forEach((r) => ul.appendChild(r));
      gone.forEach((a) => {
        const li = el('li', 'bk-marked');
        li.append(diffMark('gone'), T('setup.ch_app_gone', { name: a.name }));
        li.appendChild(el('span', '', T('setup.why.gone')));
        ul.appendChild(li);
      });
      goneSh.forEach((sh) => {
        const li = el('li', 'bk-marked');
        li.append(diffMark('gone'), T('setup.ch_share_gone', { name: sh.name }));
        li.appendChild(el('span', '', sh.why === 'renamed_to' && sh.why_arg ? T('setup.ch_share_renamed', { name: sh.why_arg }) : T('setup.ch_share_gone_why')));
        ul.appendChild(li);
      });
      if (ul.children.length) box.appendChild(ul);      // units only: they stand in «To partners» below
    }
    // what is new on the server and how this Apply takes it in (engine 2.21: until now only local, kept running)
    if (news.length) {
      const touched = new Set(setupEdits());
      // engine 2.31: decided in advance by the default for new things - said, so nothing goes along unseen; only while
      // no new row was changed by hand (such a row shows the hand's value and says so)
      const kind = presetNow();
      const hand = news.some((r) => r.hand);
      box.appendChild(el('p', '', [T('setup.apply_group_new', { n: news.length }),
        kind !== 'auto' && !hand ? T('setup.apply_new_default', { name: T('setup.preset.' + kind) }) : ''].filter(Boolean).join(' ')));
      const ul = el('ul', 'shortlist bk-newlist');
      news.forEach(({ name, how, hand: mine, own }) => {
        const li = el('li', 'bk-marked');
        li.append(diffMark('add'), name);
        const what = el('span', '', how);
        if (mine) what.append(' ', chip(T('setup.preset.changed'), 'quiet'));
        // a proposal of mine for it (its keys changed, none touched by the user): said here, and it can stay out
        const mineKeys = own ? items.filter((x) => x.kind === 'key' && x.id.startsWith(own) && !/\|kopia_known$/.test(x.id)).map((x) => x.id) : [];
        if (!mine && mineKeys.length && !mineKeys.some((k) => touched.has(k))) {
          what.append(' ', chip(T('setup.proposal'), 'quiet', T('setup.proposal_tip')));
          const no = el('button', 'btn small plain', T('setup.decline'));
          no.type = 'button';
          no.onclick = () => { mineKeys.forEach((k) => setupDecline(k)); renderSetup(); setupApply(); };
          what.appendChild(no);
        }
        li.appendChild(what);
        ul.appendChild(li);
      });
      box.appendChild(ul);
    }
  }
  // engine 2.27: what goes to each partner office
  const pl = (setup.plan.partners || []).length ? partnerApplyLines() : [];
  if (pl.length) {
    box.appendChild(el('p', '', T('partner.apply_group')));
    // engine 2.45: a partner ended at the Team Lead and paired anew - its units go to the new partnership with this Apply
    (setup.plan.messages || []).filter((m) => m && m.code === 'partner_anew')
      .forEach((m) => box.appendChild(el('p', 'callout', T('setup.msg.partner_anew', setupMsgParams(m.params)))));
    const ul = el('ul', 'shortlist');
    pl.forEach(([name, how]) => {
      const li = el('li', '', name);
      li.appendChild(el('span', '', how));
      ul.appendChild(li);
    });
    box.appendChild(ul);
    partnerShareLines().forEach((t) => box.appendChild(el('p', 'role', t)));     // engine 2.45: what a share carries along
  }
  // the backup place in one word (placeVerdict()): yellow while its packages stay only here, grey when a copy goes off-site
  placeLines(placeRow()).forEach((l) => box.appendChild(el('p', l.warn ? 'callout warn' : 'role', l.text)));
  box.appendChild(el('p', 'role', T(!setup.plan.have_settings ? 'setup.apply_hint_new' : 'setup.apply_hint')));
  box.appendChild(el('p', 'role', T('setup.apply_responsibility')));
  Office.dialog({
    title: T('setup.apply_title'),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('setup.apply_go'), kind: '', act: async () => {
        const j = await Office.api.post(`${ID}.setup_apply`, { decisions: setupDecisions(), plan_time: setup.plan.time });
        if (!j.ok) {
          Office.toast(Office.errorText(j.error, ID), true);
          // settings.ini changed after this plan was made (another tab, a terminal): look again - what the user
          // chose here stays (setupDraftKeep()), the list is made anew against what is saved now
          if (j.error && j.error.key === 'setup_plan_old') { setupPlan(false, true); return true; }
          return false;
        }
        setup.applied = null;
        Office.toast(T('setup.applying'));
        Office.busy(`${ID}.setup`, true);
        setupLoad();
        if (typeof window !== 'undefined') window.scrollTo(0, 0);     // applying: the tab from its top, where «Applying…» stands — not left at the bottom
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
  const parts = [plus.length ? [diffMark('add'), ' ' + list(plus)] : null, minus.length ? [diffMark('gone'), ' ' + list(minus)] : null].filter(Boolean);
  return parts.length ? parts.flatMap((x, i) => (i ? [' · ', ...x] : x)) : '–';
}

/** The apply dialog's group «New»: each new VM, app, container of a known app, share and folder, and how Apply takes it in */
function setupNewLines() {
  return setupNewRows().map(({ name, how }) => [name, how]);
}
/**
 * The same rows as {name, how, hand}: hand = the user changed that row against the proposal (the default for new things
 * decided it, then a click) — the dialog says «Your default for new things decided this» only while no row is (2026-10-11
 * on a test server: the sentence stood over a row changed by hand)
 */
function setupNewRows() {
  const m = setup.model;
  if (!m) return [];
  const out = [];
  const edited = new Set(setup.base ? setupEdits() : []);
  const baseLevels = setup.baseLevels || setup.levels;
  const baseHeld = setup.baseHeld || setup.held;
  const levelHand = (key) => (setup.levels[key] ?? 0) !== (baseLevels[key] ?? 0);
  const appHand = (a) => levelHand('app:' + a.id) || (setup.held[a.id] || 'stop') !== (baseHeld[a.id] || 'stop');
  const appHow = (a) => {
    const l = levelOf('app:' + a.id);
    return l === 0 ? T('setup.level.0') : `${levelWord('app:' + a.id, `app|${a.name}|partner`)}, ${T('setup.app_hold.' + (setup.held[a.id] || 'stop'))}`;
  };
  m.vms.filter((x) => x.isNew).forEach((x) => {
    const l = levelOf('vm:' + x.name);
    out.push({ name: T('setup.new_vm', { name: x.name }), own: `vm|${x.name}|`,
      how: l === 0 ? T('setup.level.0') : `${levelWord('vm:' + x.name, `vm|${x.name}|partner`)}, ${T('setup.vm_prep.' + dget(`vm|${x.name}|prepare`, 'none'))}`,
      hand: levelHand('vm:' + x.name) || edited.has(`vm|${x.name}|prepare`) || edited.has(`vm|${x.name}|mode`) });
  });
  m.apps.filter((a) => a.isNew).forEach((a) => out.push({ name: T('setup.new_app', { name: a.name }), own: `app|${a.name}|`, how: appHow(a), hand: appHand(a) }));
  m.apps.filter((a) => a.newMembers.length).forEach((a) => out.push({ name: T('setup.new_member', { name: a.newMembers.join(', '), app: a.name }),
    how: appHow(a), hand: appHand(a) }));
  setup.plan.shares.filter((sh) => sh.exists && setup.newItems.has('share:' + sh.name))
    .forEach((sh) => out.push({ name: T('setup.new_share', { name: sh.name }), own: `share|${sh.name}|`, how: T('setup.mode.' + dget(`share|${sh.name}|mode`, 'off')),
      hand: edited.has(`share|${sh.name}|mode`) }));
  waitingFolders().forEach((w) => out.push({ name: `${w.share}/${w.dir}`, how: waitHow(w),
    hand: w.owner ? levelHand(w.owner.key) : !!setup.base && waitChoice(w) !== waitChoice(w, setup.base) }));
  return out;
}

/** A settings.ini line Apply drops: in the saved settings but in no proposal - setup.sh's settings_render() writes only what
 *  the plan carries (2026-10-17). No proposal of mine: declined or not, Apply can't keep it */
const droppedKey = (k) => !!setup.plan && k in (setup.plan.O || {}) && !(k in (setup.plan.P || {})) && !goneAppKey(k, setup.plan) && !goneShareKey(k, setup.plan);
/** Some part of the engine knows this key (engine 2.60: the plan's known_keys, per section type - lib/common.sh UB_SCHEMA).
 *  An older engine's plan names none: then nothing counts as known here */
const engineKnows = (k, plan) => {
  const kk = plan && plan.known_keys;
  if (!kk || typeof kk !== 'object') return false;
  const p = k.split('|');
  return Array.isArray(kk[p[0]]) && kk[p[0]].includes(p[p.length - 1]);
};
/** A settings.ini line the setup doesn't know (written by hand, e.g. `colour = …` in a section): dropped by Apply and no key
 *  any part of the engine knows. A known key is never «doesn't know» (2026-10-18: a stopped Nextcloud's
 *  preexisting_maintenance was said so) */
const unknownKey = (k) => droppedKey(k) && !engineKnows(k, setup.plan);

function changeLabel(k) {
  const p = k.split('|');
  // the word for a key; one unknown to the setup said so, a known one without a word of its own by its settings.ini name
  const word = (w, key = p[2]) => (Office.has(`${ID}.${w}`) ? T(w) : unknownKey(k) ? T('setup.key_unknown', { key }) : key);
  if (p[0] === 'partner') return T('partner.ch_section', { name: partnerName(p[1], setup.plan) || p[1], key: p[2] });
  if (p[0] === 'share') return T('setup.ch_share', { share: p[1], key: word('setup.key.' + p[2]) });
  if (p[0] === 'app') return T('setup.ch_app', { name: p[1], key: word('setup.key.item_' + p[2]) });
  if (p[0] === 'vm' && Office.has(`${ID}.setup.key.item_${p[2]}`)) return T('setup.ch_vm', { name: p[1], key: T('setup.key.item_' + p[2]) });
  if (p[0] === 'dump' && !unknownKey(k)) return T('setup.ch_dump', { name: p[1] });
  if (p[0] === 'nextcloud' && !unknownKey(k)) return T('setup.ch_nextcloud', { name: p[1] });
  if (p[0] === 'vm') return T('setup.ch_vm', { name: p[1], key: word('setup.key.vm_' + p[2]) });
  if (Office.has(`${ID}.setup.key.${p.join('_')}`)) return T('setup.key.' + p.join('_'));
  return T('setup.ch_section', { section: p.slice(0, -1).join(' '), key: unknownKey(k) ? T('setup.key_unknown', { key: p[p.length - 1] }) : p[p.length - 1] });
}
/** A value's change in the apply dialog, «old → new»; a side without a value says so in a word, coloured like the marks
 * (owner 2026-10-21: a grey «–» read like a minus): «ja → entfällt» when it goes, «keine → 3» when it comes */
function valueChange(a, b, k) {
  const none = (v) => valueText(v, k) === '–';
  if (none(b) && !none(a)) return [`${valueText(a, k)} `, el('b', 'bk-diff gone', `→ ${T('setup.value_gone')}`)];
  if (none(a) && !none(b)) return [el('b', 'bk-diff add', `${T('setup.value_none')} →`), ` ${valueText(b, k)}`];
  return [`${valueText(a, k)} → ${valueText(b, k)}`];
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

// ---- the default (2026-10-07/08): three of them - my own proposals, everything local only, everything local +
// Kopia. Chosen «for everything now» it sets the levels and share modes the way a click on every row would; chosen
// «only for what is new» it does so for what came after the last setup. Either way it is stored at Apply as
// [general] preset_new (engine 2.31) and from then on fills in whatever is new each time the draft is made
// (setupDraftFromPlan()). setupDerive() does the rest as always, every row stays the user's to change, and
// nothing counts before Apply - the run reads settings.ini only.
const PRESETS = ['auto', 'local', 'partner', 'kopia', 'kopia_partner'];       // the engine's [general] preset_new values (2.31; 2.42 the two with a partner)
/** The cards: «local + partner» only with a pair; «local + offsite» carries the tick «also to the partner» (kopia_partner) */
const presetCardsOf = () => (setupPartners().length ? ['auto', 'local', 'partner', 'kopia'] : ['auto', 'local', 'kopia']);
const presetCardOf = (kind) => (kind === 'kopia_partner' ? 'kopia' : kind);
/** The partners this office sends to, as the plan has them (paired at the Team Lead; engine 2.27 partners[]) */
const setupPartners = () => ((setup.plan && setup.plan.partners) || []);
const presetToPartner = (kind) => kind === 'partner' || kind === 'kopia_partner';
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
 * How an app is held under a start: media servers keep running (the rule); an app that ran only because nothing
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
  if (!PRESETS.includes(kind) || kind === 'auto') return;
  const m = setup.model;
  const plan = setup.plan;
  const has = (k) => !items || items.has(k);
  const offsite = presetCardOf(kind) === 'kopia';
  const lv = offsite ? 2 : 1;
  dset('kopia|enabled', offsite ? 'yes' : 'no');
  // Kopia off: what went there stays local (like unticking «Back up with Kopia»)
  if (!offsite) Object.keys(setup.draft).forEach((k) => { if (/^share\|.+\|mode$/.test(k) && setup.draft[k] === 'kopia') setup.draft[k] = shareLocal(k.slice(6, -5)); });
  // the partner levels (2.42): every unit that can travel to every partner — or to none
  presetPartners(presetToPartner(kind), has);
  const before = { ...setup.levels };
  m.apps.forEach((a) => { if (has('app:' + a.id)) setup.levels['app:' + a.id] = lv; });
  m.vms.forEach((x) => {
    if (!has('vm:' + x.name) || presetVmKeep(x)) return;
    // from «not» up: freeze or pause proposed again, like a click on its row (a VM of its own kept running at «not»)
    if (!before['vm:' + x.name]) dset(`vm|${x.name}|prepare`, undefined);
    setup.levels['vm:' + x.name] = lv;
  });
  plan.shares.forEach((sh) => {
    if (sh.exists && has('share:' + sh.name) && !presetKeep(sh, plan)) dset(`share|${sh.name}|mode`, lv === 1 ? shareLocal(sh.name) : LV[lv]);
  });
  if (dget('flash|mode', 'off') === 'off') dset('flash|mode', plan.flash && plan.flash.dataset ? 'snapshot' : 'tar');
  if (dget('libvirt|mode') === 'off') dset('libvirt|mode', 'tar');
  setupDerive();
  m.apps.forEach((a) => { if (has('app:' + a.id)) setup.held[a.id] = presetHold(a, before['app:' + a.id] ?? 0); });
  setupDerive();
  if (offsite) waitingFolders().forEach((w) => { if (!w.owner && has(`wait:${w.share}/${w.dir}`)) waitSet(w, 'kopia'); });
}

/**
 * The partner lists of a default: on — every share, VM and the backup place that is a dataset of its own (partner_ok)
 * goes to every partner; off — to none. Only the items `has` names (a default for new things: the new ones)
 */
function presetPartners(on, has) {
  const plan = setup.plan;
  const ids = setupPartners().map((p) => p.id);
  if (!ids.length) return;
  const set = (key, ok) => { if (on && ok) dset(key, [...ids]); else if (!on) dset(key, []); };
  const place = dget('general|dumps_share', '');
  plan.shares.forEach((sh) => {
    if (!sh.exists || !has('share:' + sh.name)) return;
    if (sh.name === place) set('general|partner_place', !!sh.partner_ok);
    else set(`share|${sh.name}|partner`, !!sh.partner_ok);
  });
  (plan.vms || []).forEach((v) => { if (has('vm:' + v.name)) set(`vm|${v.name}|partner`, !!v.partner_ok); });
  (setup.model ? setup.model.apps : []).forEach((a) => { if (has('app:' + a.id)) { const ap = appPartner(a, plan); set(ap.key, ap.own); } });
}

/** Under the default «local + Kopia» a new share the engine finds big (over 500 GB) or can't size waits for the user (2026-10-08) */
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
  if (!PRESETS.includes(kind) || kind === 'auto' || !items || !items.size) return;
  const m = setup.model;
  const plan = setup.plan;
  const lv = presetCardOf(kind) === 'kopia' && dget('kopia|enabled') === 'yes' ? 2 : 1;
  if (presetToPartner(kind)) presetPartners(true, (k) => items.has(k));       // new units that can travel go to the partners too
  const apps = m.apps.filter((a) => items.has('app:' + a.id));
  apps.forEach((a) => { setup.levels['app:' + a.id] = lv; });
  m.vms.forEach((x) => {
    if (!items.has('vm:' + x.name) || presetVmKeep(x)) return;
    dset(`vm|${x.name}|prepare`, undefined);       // held like any VM: freeze or pause proposed (the engine's «none» kept it running until decided)
    setup.levels['vm:' + x.name] = lv;
  });
  plan.shares.forEach((sh) => {
    if (sh.exists && items.has('share:' + sh.name) && !presetKeep(sh, plan) && !presetNewAsk(sh, presetCardOf(kind))) dset(`share|${sh.name}|mode`, lv === 1 ? shareLocal(sh.name) : LV[lv]);
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
  if (kind === 'partner') note(T('setup.preset.partner_note'));
  if (kind === 'kopia') {
    if (!kst.ok) {
      // engine 2.54: Docker switched off - no Kopia container can run, the reason and where to switch it on
      if (plan.docker_service === false) note(T('setup.k_problem.docker_off'), 'warn');
      else note(T('setup.preset.kopia_none'), 'warn').append(' ', kopiaHelpLink());
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

/** The shares Unraid names as the place for app configs and VMs (engine 2.37, the plan's app_share) - joined as words */
function appSharesText() {
  const names = (setup.plan.shares || []).filter((x) => Array.isArray(x.app_share) && x.app_share.length).map((x) => x.name);
  if (!names.length) return '';
  try { return new Intl.ListFormat(Office.lang, { type: 'conjunction' }).format(names); } catch { return names.join(', '); }
}

/**
 * Under the cards, once for every start: what stays as it is, media servers, new folders (engine 2.37: they go with a
 * share that goes offsite; in the app/VM shares their app or VM decides), what comes later
 */
function presetCommon() {
  const plan = setup.plan;
  const kept = presetKeptList();
  const apps = appSharesText();
  const text = [kept.length ? T('setup.preset.kept', { list: kept.join(', ') }) : '',
    setup.model.apps.some((a) => a.members.some((n) => (plan.containers.find((c) => c.name === n) || {}).media)) ? T('setup.preset.media') : '',
    apps ? T('setup.preset.folders_follow', { list: apps }) : T('setup.preset.folders_follow_none'),
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
  const cards = presetCardsOf();
  const partnerNames = setupPartners().map((p) => p.name).join(', ');
  presetCards.partnerTick = presetCards.partnerTick === undefined ? true : presetCards.partnerTick;      // the offsite card's «also to the partner» (on until unticked)
  if (current === 'kopia_partner') presetCards.partnerTick = true;
  else if (current === 'kopia') presetCards.partnerTick = false;
  cards.forEach((kind) => {
    const on = kind === presetCardOf(current);
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
      : newOnly ? `setup.preset.${kind}_new_text` : `setup.preset.${kind}_text`, { partners: partnerNames })));
    presetNotes(kind, kst, measure, newOnly).forEach((n) => c.appendChild(n));
    // the offsite card with a pair: one line «also to {partner}» (on) — the default is then kopia_partner (2.42)
    const kindOf = () => (kind === 'kopia' && partnerNames && presetCards.partnerTick ? 'kopia_partner' : kind);
    if (kind === 'kopia' && partnerNames) {
      const line = el('label', 'check bk-preset-tick');
      const cb = el('input');
      cb.type = 'checkbox';
      cb.checked = presetCards.partnerTick;
      cb.disabled = off;
      cb.onchange = () => { presetCards.partnerTick = cb.checked; if (on) onPick(kindOf(), c); };
      const tickText = el('span', '', T('setup.preset.kopia_partner_tick', { partners: partnerNames }));
      // on a card not chosen the tick only waits for the card (2026-10-17, silent controls: a click that did nothing visible)
      if (!on && !off) tickText.appendChild(el('small', '', T('setup.preset.kopia_partner_tick_wait')));
      line.append(cb, tickText);
      c.appendChild(line);
    }
    const pick = (e) => {
      if (off || (e && e.target && e.target.closest && e.target.closest('a, button, input, label'))) return;   // its links, buttons and the tick keep their click
      onPick(kindOf(), c);
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
  const spec = page === 'setup' ? setupBarSpec() : null;
  const bar = Office.selbar(spec);
  // a button's title (core.js's bar has none): «Prüfen…» grey says why
  const box = bar && bar.sub && bar.sub.parentNode ? bar.sub.parentNode.parentNode : null;
  const btns = box && box.querySelectorAll ? box.querySelectorAll('.selbar-buttons button') : [];
  (spec ? spec.buttons : []).forEach((b, i) => { if (b.title && btns[i]) btns[i].title = b.title; });
}

/** The bar's spec for the setup page as it stands (null: no bar) — pure, tests/run.php testSetupFirstPlace */
function setupBarSpec() {
  if (!setup.plan || !setup.draft) return null;
  const fresh = !setup.plan.have_settings;     // nothing set up yet: Apply is how it starts
  // what Apply really changes in settings.ini, split (2026-10-10: «1 Vorschlag von mir und 1 Änderung von dir»):
  // the user's (a key they touched here) and mine (what changes although they clicked nothing — a proposal)
  const split = fresh ? { mine: [], yours: [] } : setupSplit();
  const proposals = split.mine.length;
  const edits = fresh ? setupEdits().length : split.yours.length;
  const busy = setup.status && setup.status.running;
  const discard = { text: T('setup.discard'), kind: 'plain', disabled: (!edits && (fresh || !proposals)) || busy, act: () => {
    if (!edits) Office.store('backup.proposals_dismissed', String(setup.plan.time || ''));   // proposals only: not now
    setupDraftFromPlan();
    renderSetup();
  } };
  // first place (2026-10-12): no backup place yet — the bar says that first and leads there; «Prüfen…» grey with the
  // reason (setupApply()'s toast stays the last guard, for a page drawn before)
  if (setupNoPlace()) {
    const none = placeIntro(setup.plan, '') === 'setup.place_none';
    return {
      title: T('setup.bar_place'),
      sub: T(none ? 'setup.bar_place_sub' : 'setup.bar_place_choose', { tile: T('setup.kopia') }),
      buttons: [discard, { text: T('setup.bar_place_go'), act: setupToPlace },
        { text: T('setup.review'), kind: 'plain', disabled: true, title: T('setup.ds_missing'), act: setupApply }],
    };
  }
  if (!edits && !fresh && proposals <= 0) return null;      // nothing to apply: no bar that keeps offering it
  // proposals only, set aside with «Discard» for this plan (2026-10-09: the button was grey — no way to say no);
  // a new plan (another «Look at the server again», an update) offers again; the list of changes still shows them
  // 1.63.0 (a re-pairing on the test servers: the bar stayed away after an earlier «Discard»): a partnership that changed
  // at the Team Lead ([partner] sections) is never set aside — until applied, nothing goes to the new partner
  const dismissed = Office.store('backup.proposals_dismissed') === String(setup.plan.time || '')
    && !split.mine.some((id) => id.startsWith('partner|'));
  if (!edits && !fresh && dismissed) return null;
  const chosen = presetChosen();               // a start of the user's: what it changes is the user's, not proposals of mine
  return {
    title: fresh ? (edits ? T('setup.bar_changes', { n: edits }) : T('setup.bar_new'))
      : chosen ? T('setup.bar_changes', { n: edits + proposals })
      : [proposals ? T('setup.bar_proposals', { n: proposals }) : '', edits ? T('setup.bar_changes_yours', { n: edits }) : ''].filter(Boolean).join(' · '),
    sub: [chosen ? presetStartText() : '', T('setup.bar_sub', { when: fmt.relative(setup.plan.time) })].filter(Boolean).join(' · '),
    buttons: [
      discard,
      { text: T('setup.review'), disabled: busy || !Office.agent.running, act: setupApply },   // opens the list of changes; its dialog applies
    ],
  };
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

// ---- the setup's filter (2026-10-10; on Office.filterBar since the tiles rebuild, 2026-10-11): words (an app's, a VM's,
// a share's or a folder's name; accents folded; not kept: a new visit starts with every row) and «only what's new» (the
// rows marked new or waiting; not kept either). Rows that don't fit aren't drawn, a step with none says so in one line;
// «n of m fit» is the bar's. It only hides: no decision changes, nothing the user unfolded folds (setup.open stays).
let sfBar = null;
/** The bar: built once per visit (mount() drops it), so typing in it goes on while the page is drawn anew */
function setupFilter() {
  if (!sfBar) {
    sfBar = Office.filterBar({ key: 'backup.setup', label: T('setup.filter.label'),
      only: { text: T('setup.filter.only_new'), hint: T('setup.filter.only_new_hint') }, onChange: () => setupSwitcher().render() });
  }
  return sfBar;
}
/** Does a row fit? flt {words, onlyNew}; hay: its names (a string or a list); isNew: marked new / waiting */
function setupFits(flt, hay, isNew) {
  if (flt.onlyNew && !isNew) return false;
  return Office.fits(flt.words, [].concat(hay));
}
/** The filter of this drawing and what it met: rows in all (total), fitting (n), new ones (fresh), fitting in the step (step) */
let fitting = { flt: { words: [], onlyNew: false }, on: false, n: 0, total: 0, fresh: 0, step: 0 };
function fitStart() {
  const f = setupFilter().read();
  const flt = { words: f.words, onlyNew: f.only };
  fitting = { flt, on: !!(flt.words.length || flt.onlyNew), n: 0, total: 0, fresh: 0, step: 0 };
}
/** A row of a step: counted, true when it is drawn */
function fitRow(hay, isNew) {
  fitting.total++;
  if (isNew) fitting.fresh++;
  if (!setupFits(fitting.flt, hay, isNew)) return false;
  fitting.n++;
  fitting.step++;
  return true;
}
/** A step built with the filter: no row of it fits — its head and one line instead of its content */
function fitStep(build) {
  fitting.step = 0;
  const s = build();
  if (fitting.on && !fitting.step) {
    [...s.children].slice(1).forEach((c) => c.remove());
    s.appendChild(el('p', 'bk-filter-none', T('setup.filter.none')));
  }
  return s;
}
/** What is new on its row (the same rules as its chip): a VM, an app (or new containers in it), a share or its waiting folders */
const appIsNew = (a) => !!a.isNew || (a.newMembers || []).length > 0;
const shareIsNew = (sh, ws) => setup.newItems.has('share:' + sh.name) || ws.length > 0;

/**
 * After the steps drawn: «n of m fit» (of the steps under the row — the one chosen, or all), the switch «only what's new»
 * only while some step has something new (counted over every step, not only the one shown — setupNewCounts()), and the
 * chosen step as the note line's chip: «The list shows only: [2 · Your apps ×] · 8 of 8 fit», its × and «Show all» = every
 * step again — for a step with rows (VMs, apps, shares, old sources) or while words or «only what's new» filter (a step
 * of fields alone would read «0 of 0 fit»). The switcher's sw.note() names only a filter tile; a step's tile is a section
 * tile (kept as backup.setup.tile) — so the chip comes from here, through the bar's own `tile`.
 */
function setupFilterDone() {
  if (!sfBar) return;
  const id = setupSw ? setupSw.active() : '';
  const key = id && (fitting.total > 0 || fitting.on) ? (SETUP_TILES.find(([t]) => t === id) || [])[1] : '';
  const c = setup.plan && setup.model && setup.draft ? setupNewCounts(setup.plan) : { vms: 0, apps: 0, shares: 0 };
  sfBar.done(fitting.n, fitting.total, { tile: key ? T(key) : '', clear: () => setupSwitcher().clear(), onlyAvail: c.vms + c.apps + c.shares > 0 });
}
/** A new visit, or a deep link that must show its target: the whole setup again (words and «only what's new» off) */
function setupFilterReset() {
  setupFilter().reset();
}

/**
 * Where «Decide…» (the callout of what waits) or a row's «Change…» on the main page wants to go — the setup is made to
 * show it: the filter cleared (it would hide it); «waiting»: the first new thing in the order of the steps (a new VM, a
 * new app, a new share or a folder waiting in one) and the filter set to «only what's new»; the row unfolded where rows
 * unfold (a share's; a waiting folder: its share's row, the waiting folders are under it). Returns {anchor, part} for
 * Office.reveal() once drawn — scrolled into view and marked like the search's hit — or null.
 */
function setupFocus() {
  const f = setup.focus;
  setup.focus = null;
  setupFilterReset();
  if (!f) return null;
  if (f !== 'waiting') {
    if (f.startsWith('vm:')) return { anchor: 'setup:' + f, part: 'setup.vms', name: f.slice(3) };
    if (f.startsWith('app:')) return { anchor: 'setup:' + f, part: 'setup.apps', name: f.replace(/^app:(stack|ct):/, '') };
    return { anchor: 'setup:share:' + f, part: 'setup.shares', name: f };    // its row unfolded by the one who asked (protectionDetail())
  }
  const first = setupFirstNew();
  if (!first) return null;
  setupFilter().set({ only: true });
  if (first.open) setup.open.add(first.open);
  return { anchor: first.anchor, part: first.part, name: first.name };
}
/** The first new thing as the steps draw it: {anchor, part, open (a share's row to unfold)} or null */
function setupFirstNew() {
  const plan = setup.plan;
  const m = setup.model;
  if (!plan || !m || !setup.draft) return null;
  const vm = plan.vm_service ? m.vms.find((x) => x.isNew) : null;
  if (vm) return { anchor: 'setup:vm:' + vm.name, part: 'setup.vms', name: vm.name };
  const app = m.apps.find((a) => a.isNew) || m.apps.find(appIsNew);
  if (app) return { anchor: 'setup:app:' + app.id, part: 'setup.apps', name: app.name };
  const waits = waitingFolders();
  for (const sh of plan.shares || []) {
    const ws = waits.filter((w) => w.share === sh.name);
    const open = sh.exists ? sh.name : null;
    if (ws.length) return { anchor: `setup:wait:${ws[0].share}/${ws[0].dir}`, part: 'setup.shares', open, name: `${ws[0].share}/${ws[0].dir}` };
    if (shareIsNew(sh, ws)) return { anchor: 'setup:share:' + sh.name, part: 'setup.shares', open, name: sh.name };
  }
  return null;
}

// ---- rendering
function renderSetup() {
  const root = view;
  if (!root) return;
  root.innerHTML = '';
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
  root.appendChild(deskHeadOf('setup'));
  // the page's own head line under the tabs: its name, what it is, and the page's actions on the right
  const ph = el('div', 'bk-pagehead');
  const pt = el('div', 'bk-pagehead-text');
  pt.appendChild(el('div', 'bk-pagehead-title', T('setup.page')));
  if (pageSub) pt.appendChild(el('p', 'bk-pagehead-sub', pageSub));
  const pa = el('div', 'bk-pagehead-actions');
  pa.append(again, measure, preset, forget);
  ph.append(pt, pa);
  root.appendChild(ph);
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('setup.preset.title'), T('help.preset_text')],
    [T('help.never'), T('help.never_text')],
    [T('help.partners'), T('help.partners_text')],
    [T('help.draft'), T('help.draft_text')],
    [T('setup.replan'), T('help.replan')],
    [T('setup.measure'), T('setup.measure_hint')],
    [T('help.reasons'), T('help.reasons_text')],
    [T('setup.more'), T('help.details')],
    [T('help.new'), T('help.new_text')],
    [T('help.filter'), T('help.filter_text')],
    [T('setup.apply'), T('help.apply')],
    [T('setup.forget_short'), T('help.forget')],
  ]));

  const untrusted = untrustedCallout();
  if (untrusted) root.appendChild(untrusted);
  if (setup.applied) root.appendChild(appliedCard(setup.applied));
  if (!busy && setup.plan && Date.now() / 1000 - setup.plan.time > SETUP_STALE) root.appendChild(el('p', 'callout warn', T('setup.bubble_old', { when: fmt.relative(setup.plan.time) })));
  if (!busy && !setup.plan && state && state.running) root.appendChild(el('p', 'callout', T('setup.bubble_backup_runs')));
  if (!busy && !setup.plan && !(state && state.running) && setup.status && setup.status.result === 'failed') root.appendChild(setupFailedCallout(setup.status));
  else if (!busy && !setup.plan && !(state && state.running)) root.appendChild(el('p', 'callout running', T('setup.bubble_loading')));
  if (busy) {
    const p = el('p', 'callout running');
    const mode = setup.status.mode;
    p.append(el('span', 'spin'), ' ', T(mode === 'apply' ? 'setup.applying_long' : mode === 'forget' ? 'setup.forgetting' : 'setup.planning_long'));
    root.appendChild(p);
  }
  if (!setup.plan) { setupBar(); return; }

  const plan = setup.plan;
  // came from «Decide…» or «Change…» on the main page: the filter and the rows set so that it shows (setupFocus()) —
  // not while the plan is being made anew (SETUP_REPLAN, 2026-10-11 on a large server): the plan shown is the old one,
  // the new thing isn't in it yet, and the bar would switch «only what's new» off again (nothing new → onlyAvail false)
  const target = setup.focus && setup.model && setup.draft && !busy ? setupFocus() : null;
  const start = plan.have_settings ? presetStartLine() : presetSection();   // a new server: where to start, the three cards;
  if (start) root.appendChild(start);                                       // set up: which start the draft came from, once chosen
  // the row of the steps and under it the step chosen (none: every step in order — the page as before the tiles); the ONE
  // filter bar stands between the row and the steps (typing in it while the page is drawn anew: the bar keeps its text,
  // focus and selection — setupFilterDone() gives them back after the steps)
  const sw = setupSwitcher();
  const bar = setupFilter().el;
  if (bar.parentNode !== sw.el) {
    [...sw.el.children].forEach((c) => { if (c !== sw.grid && c !== sw.body) c.remove(); });     // a bar of an earlier visit
    sw.el.insertBefore(bar, sw.body);
  }
  root.appendChild(sw.el);
  // «Decide…» / «Change…»: the target's step shows alone (its tile picked like a link), then its row is revealed
  const tile = target ? STEP_TILE[target.part] : '';
  const first = setup.placeStart && !!setup.draft && !!setup.model;    // the visit's first page with the plan: its start
  if (first) setup.placeStart = false;
  if (tile) sw.pick(tile, 'route'); else if (first && setupNoPlace()) setupPlaceStart(sw); else sw.render();
  setupBar();
  // first place: the guide's callout and «Add Share» the first thing in view — unless the user has scrolled meanwhile
  if (!tile && first && setupNoPlace() && window.scrollY < 40) setupShowPlace(false);
  if (target) Office.reveal(target.anchor, { part: target.part, name: target.name });
}

// ---- the steps as tiles (tiles v2, CLAUDE.md «Tiles», 2026-10-12): a row with a number tile per step; a click shows that
// step alone under the row, nothing chosen = every step in order, the second click = all again; the choice kept as
// backup.setup.tile, a step's route #/backup/setup/<tile>. Hints over the row stay at every click (the page's head line,
// the help, the applied card, the callouts, the start cards / start line); the filter bar is one for the whole page.
/** [tile id, lang key (the step's title: the tile's label, its place for the search)] in the steps' order */
const SETUP_TILES = [['basics', 'setup.kopia'], ['vms', 'setup.vms'], ['apps', 'setup.apps'], ['shares', 'setup.shares'],
  ['retention', 'setup.retention'], ['more', 'setup.general'], ['sources', 'setup.sources'], ['messages', 'setup.messages']];
/** The step (its place) a row of «Decide…» / «Change…» lies in → its tile */
const STEP_TILE = { 'setup.kopia': 'basics', 'setup.vms': 'vms', 'setup.apps': 'apps', 'setup.shares': 'shares', 'setup.retention': 'retention',
  'setup.general': 'more', 'setup.sources': 'sources', 'setup.messages': 'messages' };
const setupRoute = (id) => `#/${ID}/setup/${id}`;
let setupSw = null;
/** First place (2026-10-12): the plan is in and no backup place is chosen on it — the page leads to «Grundlagen» first */
const setupNoPlace = () => !!(setup.plan && setup.draft && setup.model) && !dget('general|dumps_share', '');
/**
 * First place (2026-10-12, a fresh server: «Prüfen…» was answered with a red toast — nothing before had said that a share
 * is needed, the guide with «Add Share» stood out of view inside «Grundlagen»): while no backup place is chosen, a visit
 * opens on «Grundlagen» (the guide's callout and «Add Share» first, the tile in alert), and the bar's «Zu den Grundlagen»
 * picks it too. Never kept: backup.setup.tile stays what the user chose (none = every step), so once the place stands
 * the usual start comes back.
 */
function setupPlaceStart(sw) {
  if (sw.active() !== 'basics') sw.pick('basics', 'start');     // 'start': shown, never kept (core's pick)
}
/** The bar's «Zu den Grundlagen»: the step chosen, the guide in view, its «Add Share» focused */
function setupToPlace() {
  setupPlaceStart(setupSwitcher());
  setupShowPlace(true);
}
/**
 * The guide in view: «Grundlagen»'s head at the top under Unraid's menu (on a phone the head stays there, sticky — the
 * guide stands first in the step while no place is chosen, so its callout and «Add Share» are right under it), marked
 * a moment like a place of the search
 */
function setupShowPlace(focus) {
  const g = view && view.querySelector('.bk-place');
  const sec = g && g.closest('[data-section]');
  const head = sec && sec.querySelector('.section-head');
  if (!g || !head) return;
  const det = g.querySelector('details');
  if (det && !det.open) det.open = true;
  const menu = document.getElementById('menu');
  const above = menu && /^(sticky|fixed)$/.test(getComputedStyle(menu).position) ? menu.offsetHeight : 0;
  window.scrollTo({ top: Math.max(0, Math.round(head.getBoundingClientRect().top + window.scrollY - above - 12)), behavior: 'auto' });
  g.classList.add('place-hit');
  setTimeout(() => g.classList.remove('place-hit'), 1800);
  const a = focus ? g.querySelector('.bk-place-add a') : null;
  if (a) a.focus({ preventScroll: true });
}
/** His word on the setup page while no backup place is chosen: none to choose (create one in Unraid) or choose one; '' else */
function bubblePlace() {
  if (!setupNoPlace()) return '';
  return placeIntro(setup.plan, '') === 'setup.place_none' ? T('setup.bubble_place') : T('setup.bubble_place_choose', { tile: T('setup.kopia') });
}
/** The setup's row and its steps (core.js's section switcher) — one per page life, drawn anew with every plan and click */
function setupSwitcher() {
  if (setupSw) return setupSw;
  setupSw = Office.sections({
    id: `${ID}/setup`,           // the routes #/backup/setup/<tile>
    key: 'backup.setup.tile',
    bodyId: 'bk-setup-steps',
    grid: 'stats bk-setup-tiles',
    default: '',                 // the start: every step (the page as it always was)
    tiles: () => setupTileList(),
    wants: (sub) => SETUP_TILES.some(([id]) => id === sub),      // a route before the plan is in
  });
  return setupSw;
}
const oldSources = (plan) => ((plan.kopia && plan.kopia.sources) || []).filter((s) => s.state === 'orphan' || s.state === 'gone');

/** The tiles for the switcher: setupTileData() as it stands, each with its step as its section (none before the plan) */
function setupTileList() {
  const plan = setup.plan;
  if (!plan || !setup.draft || !setup.model) return [];
  const list = setupTileData(plan);
  const ids = list.map((d) => d.id);
  return list.map((d) => ({ id: d.id, key: d.key, place: `setup.tile.${d.id}`, tile: (a) => setupTileNode(d, a), section: () => setupStep(d.id, plan, ids) }));
}

/** One tile as Office.stat — the switcher's attributes (the click, aria-pressed, the mark, its place) spread in */
function setupTileNode(d, a) {
  const sub = d.sub.filter(Boolean);
  if (d.fresh) sub.push(chip(T('setup.tile.new', { n: d.fresh }), 'accent bk-tile-new'));
  return Office.stat({ label: T(d.key), value: d.value, sub, alert: d.alert, tip: d.tip, ...a });
}

/**
 * One step as a section of the switcher — through the filter (fitStep: a step with no fitting row keeps its head and one
 * line); the first step of this drawing starts the count, the last tells the bar «n of m» (ids: the row's order)
 */
function setupStep(id, plan, ids) {
  const shown = setupSw && setupSw.active() ? [setupSw.active()] : ids;
  if (id === shown[0]) fitStart();
  const s = id === 'messages' ? setupMessages(placeNotes(setupNotes(plan))) : fitStep(() => setupStepNode(id, plan));
  if (id === shown[shown.length - 1]) setupFilterDone();
  return s;
}
/** The step's own section */
function setupStepNode(id, plan) {
  if (id === 'basics') return setupKopia(plan);          // 0 basics: where backups go, Kopia, the flash
  if (id === 'vms') return setupVms(plan);               // 1
  if (id === 'apps') return setupApps(plan);             // 2
  if (id === 'shares') return setupShares(plan);         // 3 what is left
  if (id === 'retention') return setupRetention(plan);   // 4
  if (id === 'more') return setupGeneral(plan);          // everything else, rarely changed
  return setupSources(oldSources(plan));                 // old Kopia sources (a tile only when there are)
}

/** What is new per step (the same rules as the rows' chips): VMs, apps (or new containers in one), shares with new folders */
function setupNewCounts(plan) {
  const m = setup.model;
  const waits = waitingFolders();
  return {
    vms: plan.vm_service ? m.vms.filter((x) => x.isNew).length : 0,
    apps: m.apps.filter(appIsNew).length,
    shares: (plan.shares || []).filter((sh) => shareIsNew(sh, waits.filter((w) => w.share === sh.name))).length,
  };
}

/** A retention as short figures: «7 d · 4 w · 6 m» (the parts that are numbers; none → '') */
function retFigure(parts) {
  return parts.filter(([, n]) => /^\d+$/.test(String(n ?? '').trim())).map(([u, n]) => T('setup.tile.u_' + u, { n: Number(n) })).join(' · ');
}
/** The settings «More settings» shows (as setupGeneral() draws them) */
function moreKeys(plan) {
  const zfs = (plan.bases || []).some((b) => b.fs === 'zfs');
  const btrfs = (plan.bases || []).some((b) => b.fs === 'btrfs');
  return [...(zfs ? ['general|snap_prefix'] : []), ...(btrfs ? ['btrfs|min_free_gb', 'btrfs|snapshot_all'] : []),
    ...(plan.P && plan.P['libvirt|mode'] !== undefined ? ['libvirt|mode'] : []), 'general|notify_success',
    ...(dget('kopia|enabled') === 'yes' ? ['kopia|compression', 'kopia|ignore'] : [])];
}

/**
 * The setup's tiles, pure (no DOM): [{id, key, value, sub: [text], alert, fresh (rows marked new in its step), tip}] in the
 * steps' order — Basics (how many places the backups go to: local, offsite, partners), VMs, apps, shares (how many the plan
 * has, how many are backed up), retention (what the local snapshots keep, offsite Kopia's), more settings (changed here or
 * not), old Kopia sources (only when there are), what the setup checked (its messages). Needs setup.plan, draft and model.
 */
function setupTileData(plan) {
  const m = setup.model;
  const kopiaOn = dget('kopia|enabled') === 'yes';
  const fresh = setupNewCounts(plan);
  const out = [];
  // 0 basics: where the backups go — the backup place (local), Kopia (offsite), the partners something is sent to
  const ds = dget('general|dumps_share', '');
  const goes = [...(ds ? [T('setup.tile.local')] : []), ...(kopiaOn ? [T('setup.tile.offsite')] : []),
    ...(plan.partners || []).filter((p) => partnerUnits(setup.draft, p.id).length).map((p) => p.name)];
  const kProblem = kopiaOn && plan.kopia && plan.kopia.problem && plan.kopia.problem !== 'off';
  out.push({ id: 'basics', key: 'setup.kopia', value: String(goes.length), sub: [ds ? goes.join(' + ') : T('setup.tile.no_place'),
    kProblem ? T('setup.tile.kopia_problem') : ''], alert: !ds || !!kProblem, fresh: 0, tip: ds ? T('setup.tile.place', { share: ds }) : '' });
  // 1 VMs, 2 apps: how many the plan has, how many are backed up (at least local)
  const vmsOn = m.vms.filter((x) => levelOf('vm:' + x.name) > 0).length;
  out.push(plan.vm_service
    ? { id: 'vms', key: 'setup.vms', value: String(m.vms.length), sub: [T('setup.tile.on', { n: vmsOn })], alert: false, fresh: fresh.vms }
    : { id: 'vms', key: 'setup.vms', value: '–', sub: [T('setup.tile.vm_off')], alert: false, fresh: 0 });
  out.push(plan.docker_service === false && !m.apps.length
    ? { id: 'apps', key: 'setup.apps', value: '–', sub: [T('setup.tile.docker_off')], alert: false, fresh: 0 }
    : { id: 'apps', key: 'setup.apps', value: String(m.apps.length), sub: [T('setup.tile.on', { n: m.apps.filter((a) => levelOf('app:' + a.id) > 0).length })],
      alert: false, fresh: fresh.apps });
  // 3 shares: the table's rows, those backed up
  const shares = plan.shares || [];
  out.push({ id: 'shares', key: 'setup.shares', value: String(shares.length),
    sub: [T('setup.tile.on', { n: shares.filter((sh) => sh.exists !== false && dget(`share|${sh.name}|mode`, 'off') !== 'off').length })], alert: false, fresh: fresh.shares });
  // 4 retention: the local snapshots' (ZFS «d w m», else btrfs days), offsite Kopia's own
  const zfs = (plan.bases || []).some((b) => b.fs === 'zfs');
  const btrfs = (plan.bases || []).some((b) => b.fs === 'btrfs');
  const z = String(dget('zfs|retention', '') || '').trim().split(/\s+/);
  const local = zfs ? retFigure([['d', z[0]], ['w', z[1]], ['m', z[2]]]) : btrfs ? retFigure([['d', dget('btrfs|keep_days', '')]]) : '';
  const off = kopiaOn ? retFigure([['d', dget('kopia|keep_daily')], ['w', dget('kopia|keep_weekly')], ['m', dget('kopia|keep_monthly')], ['y', dget('kopia|keep_annual')]]) : '';
  out.push({ id: 'retention', key: 'setup.retention', value: local || '–',
    sub: [kopiaOn ? T('setup.tile.ret_offsite', { what: off || T('setup.tile.ret_kopia_own') }) : ''], alert: false, fresh: 0 });
  // more settings: changed here (not applied yet) or as they are
  const keys = moreKeys(plan);
  const base = setup.base || plan.P || {};
  const changed = keys.filter((k) => !same(asSet(k, base[k]), asSet(k, setup.draft[k])) && !(empty(base[k]) && empty(setup.draft[k]))).length;
  out.push({ id: 'more', key: 'setup.general', value: T(changed ? 'setup.tile.changed' : 'setup.tile.unchanged'),
    sub: [T('setup.tile.settings', { n: keys.length })], alert: false, fresh: 0 });
  // old Kopia sources: only when there are
  const old = oldSources(plan);
  if (old.length) out.push({ id: 'sources', key: 'setup.sources', value: String(old.length), sub: [T('setup.tile.sources_sub')], alert: false, fresh: 0 });
  // what the setup checked: its messages, errors and warnings
  const counts = { all: 0, error: 0, warn: 0 };
  placeNotes(setupNotes(plan)).forEach((g) => {
    const n = (g.items || []).length;
    counts.all += n;
    if (counts[g.level] !== undefined) counts[g.level] += n;
  });
  out.push({ id: 'messages', key: 'setup.messages', value: String(counts.all),
    sub: [counts.error || counts.warn ? [counts.error ? nOf('errors', counts.error) : '', counts.warn ? nOf('warnings', counts.warn) : ''].filter(Boolean).join(' · ') : ''],
    alert: counts.error > 0, fresh: 0 });
  return out;
}

function appliedCard(run) {
  const ok = run.result === 'ok' && run.written;
  const box = el('div', 'callout' + (ok ? '' : ' warn'));
  box.appendChild(el('strong', '', ok ? T('setup.applied_ok') : T('setup.applied_failed')));
  if (ok && state && state.schedule && state.schedule.script && !state.schedule.enabled) {
    const b = button(T('schedule.open'), 'small', scheduleDialog);
    b.disabled = !Office.agent.running;
    box.append(' ', T('schedule.after_setup'), ' ', b);
  }
  const bad = placeNotes(setupNotes(run)).filter((g) => g.level === 'error' || g.level === 'warn');
  if (bad.length) {
    const ul = el('ul', 'bk-msgs');
    bad.forEach((g) => setupNoteItems(g, false).forEach((li) => ul.appendChild(li)));
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
  // engine 2.45: an app whose folder is a dataset of its own
  (setup.model ? setup.model.apps : []).forEach((a) => {
    const ap = appPartner(a, plan);
    const on = mine ? levelOf('app:' + a.id) > 0 : true;
    if (ap.own && on && (draft[ap.key] || []).includes(id) && !out.includes('app:' + a.name)) out.push('app:' + a.name);
  });
  return out;
}

/**
 * The apply dialog's [partner] sections, one row per partner (2026-10-12, a user: eight field rows «address 192.168.1.50
 * → –» didn't show what goes and what comes): a section gone «− Partnership X (address) goes», one new «+ Partnership Y
 * (address) comes», one changed lists only its changed fields. They mirror the Team Lead's pairs - no «Leave out»
 */
function partnerSectionRows(keys, saved, view) {
  const ids = [...new Set(keys.filter((k) => k.startsWith('partner|')).map((k) => k.split('|')[1]))].sort();
  const has = (o, id) => Object.keys(o).some((k) => k.startsWith(`partner|${id}|`) && !empty(o[k]));
  return ids.map((id) => {
    const was = has(saved, id);
    const is = has(view, id);
    const li = el('li', 'bk-marked');
    if (was && !is) {
      li.append(diffMark('gone'), T('partner.ch_gone', { name: saved[`partner|${id}|name`] || id, address: saved[`partner|${id}|address`] || '–' }));
    } else if (!was && is) {
      li.append(diffMark('add'), T('partner.ch_new', { name: view[`partner|${id}|name`] || id, address: view[`partner|${id}|address`] || '–' }));
    } else {
      li.append(T('partner.ch_changed', { name: view[`partner|${id}|name`] || saved[`partner|${id}|name`] || id }));
      li.appendChild(el('span', '', keys.filter((k) => k.startsWith(`partner|${id}|`))
        .map((k) => `${k.split('|')[2]}: ${valueText(saved[k], k)} → ${valueText(view[k], k)}`).join(' · ')));
    }
    return li;
  });
}

/**
 * What a share's partner level carries along (engine 2.45, a user: the way back as clear as the way there): a share put
 * onto or taken off a partner names the apps that go with it (their folder inside the share's dataset) — and, taken
 * off, that the partner keeps its old copy until its retention ages it out. Apps with a dataset of their own keep
 * their own level either way (a dataset is never part of its share's transfer).
 */
function partnerShareLines() {
  if (!setup.plan.have_settings || !setup.model) return [];
  const saved = setupSaved();
  const out = [];
  // 1.60.1: an app going to a partner without a share it uses
  setup.model.apps.forEach((a) => appDepGaps(a, setup.plan).forEach((g) => out.push(T('partner.dep_gap', { app: a.name, partners: g.partners.map((x) => x.name).join(', '), share: g.share }))));
  (setup.plan.partners || []).forEach((p) => {
    (setup.plan.shares || []).forEach((sh) => {
      const k = `share|${sh.name}|partner`;
      const was = (saved[k] || []).includes(p.id);
      const is = (setup.draft[k] || []).includes(p.id);
      if (was === is) return;
      const apps = [...new Set(setup.model.apps.filter((a) => {
        const f = (a.folders || [])[0];
        return f && f.share === sh.name && !ownDataset(setup.plan, f.share, f.dir);
      }).map((a) => a.name))];
      if (!apps.length) return;
      const list = apps.length > 8 ? `${apps.slice(0, 8).join(', ')} … (+${apps.length - 8})` : apps.join(', ');
      out.push(T(is ? 'partner.apply_share_on' : 'partner.apply_share_off', { n: apps.length, partner: p.name, share: sh.name, list })
        + (is ? '' : ' ' + T('partner.apply_keeps', { partner: p.name, share: sh.name })));
    });
  });
  return out;
}

/**
 * The apply dialog's group «To partners»: per partner what goes there - a unit new to it «(new)», one taken over from
 * a partnership ended at the Team Lead (engine 2.45, the hint partner_anew) «(taken over from X)», and what no longer goes
 */
function partnerApplyLines() {
  const partners = setup.plan.partners || [];
  const saved = setup.plan.have_settings ? setupSaved() : null;
  // the sections settings.ini has that the plan no longer has: ended partners, by id -> name
  const goneIds = saved ? [...new Set(Object.keys(saved).filter((k) => /^partner\|[0-9a-f]{8}\|name$/.test(k)).map((k) => k.split('|')[1]))]
    .filter((id) => !partners.some((p) => p.id === id)) : [];
  const anew = (setup.plan.messages || []).filter((m) => m && m.code === 'partner_anew' && m.params);
  return partners.map((p) => {
    const now = partnerUnits(setup.draft, p.id);
    if (!saved) return [T('partner.apply_to', { name: p.name }), now.length ? now.map(unitLabel).join(', ') : T('partner.apply_none')];
    const before = partnerUnits(saved, p.id);
    const from = goneIds.filter((id) => anew.some((m) => m.params.partner === saved[`partner|${id}|name`] && m.params.new === p.name));
    const fromOf = (u) => from.find((id) => partnerUnits(saved, id).includes(u));
    const label = (u) => {
      if (before.includes(u)) return unitLabel(u);
      const o = fromOf(u);
      return `${unitLabel(u)} (${o ? T('partner.apply_from', { name: saved[`partner|${o}|name`] }) : T('partner.apply_new')})`;
    };
    const minus = before.filter((u) => !now.includes(u));
    let text = now.length ? now.map(label).join(', ') : T('partner.apply_none');
    if (minus.length) text += ` (${T('partner.apply_removed', { list: minus.map(unitLabel).join(', ') })})`;
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
  // first place (2026-10-12): while no backup place is chosen the guide stands first — its callout and «Add Share» right
  // under the step's head; once chosen, after the settings as before
  const guideFirst = !dget('general|dumps_share', '');
  if (guideFirst) s.appendChild(placeGuide(plan));
  s.appendChild(basics);
  const pl = presetLine(plan);              // engine 2.31: the default for new things
  if (pl) s.appendChild(pl);
  // engine 2.27/2.42: the partner offices this office sends to (paired at the Team Lead) - and the backup place to them as a level
  if ((plan.partners || []).length) {
    const line = el('p', 'role bk-partners-line', T('partner.setup_line', { names: plan.partners.map((p) => p.name + (p.key ? '' : ` (${T('partner.no_key')})`)).join(', ') }));
    s.appendChild(line);
    const pl = placeLevelPick(plan);
    if (pl) s.appendChild(Office.place('partner.place', pl));
  }
  if (!guideFirst) s.appendChild(placeGuide(plan));
  const on = dget('kopia|enabled') === 'yes';
  // engine 2.54: Docker switched off - Kopia runs in a container: greyed with the reason (switched on earlier: it can be unticked)
  const dockerOff = plan.docker_service === false;
  const kbox = checkbox(T('setup.kopia_on'), on, (v) => {
    dset('kopia|enabled', v ? 'yes' : 'no');
    if (!v) Object.keys(setup.draft).forEach((key) => { if (/^share\|.+\|mode$/.test(key) && setup.draft[key] === 'kopia') setup.draft[key] = 'snapshot'; });
    setupDerive();
    renderSetup();
  }, dockerOff ? T('setup.k_problem.docker_off') : T('setup.kopia_on_hint'));
  if (dockerOff && !on) { kbox.querySelector('input').disabled = true; kbox.classList.add('bk-off'); }
  s.appendChild(kbox);
  if (!on || dockerOff) return s;
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

/**
 * A setup row with «Details» / «Less» unfolds on a click of the row itself too (2026-10-08: «clicking the bar
 * doesn't open or close any more»), like the protection table's rows: `.unfolds` (cursor, hover), the name a button
 * for the keyboard (Enter / Space) with aria-expanded, the «Details» button beside it in step. A click on a field, a
 * select, a label, a chip, a link or another button inside keeps its own job; selecting text folds nothing.
 * `toggle()` folds or unfolds (the page is drawn anew: setupToggleOpen()).
 */
const UNFOLD_OWN = 'button, a, input, select, textarea, label, [data-own], .chip';
function setupUnfold(row, name, more, open, toggle) {
  row.classList.add('unfolds');
  if (open) row.classList.add('open');
  name.classList.add('bk-unfold-name');
  name.tabIndex = 0;
  name.setAttribute('role', 'button');
  name.setAttribute('aria-expanded', String(!!open));
  name.title = T('details');
  if (more) more.setAttribute('aria-expanded', String(!!open));
  row.onclick = (e) => {
    if (e.target && e.target.closest && e.target.closest(UNFOLD_OWN)) return;
    if (typeof window !== 'undefined' && window.getSelection && String(window.getSelection()).length) return;   // selecting text
    toggle();
  };
  name.onkeydown = (e) => {
    if (e.target !== name || (e.key !== 'Enter' && e.key !== ' ')) return;
    e.preventDefault();
    toggle();
  };
  return row;
}

/** Fold or unfold a setup row (its key in setup.open): drawn anew, the row stays where it was on screen, the focus too */
function setupToggleOpen(key, row) {
  const before = row && row.isConnected ? row.getBoundingClientRect().top : null;
  const focused = !!(row && document.activeElement && row.contains(document.activeElement));
  Office.keepInPlace(row, () => {
    if (setup.open.has(key)) setup.open.delete(key); else setup.open.add(key);
    renderSetup();
    const now = view && [...view.querySelectorAll('[data-unfold]')].find((r) => r.dataset.unfold === key);
    if (!now) return;
    if (before !== null) {
      const diff = now.getBoundingClientRect().top - before;
      if (Math.abs(diff) > 1) window.scrollBy(0, diff);
    }
    const name = focused && now.querySelector('.bk-unfold-name');
    if (name) name.focus({ preventScroll: true });
  });
}

/**
 * A share's mode select (step 3): off | local snapshot | snapshot + offsite — with a pair also «snapshot + partner» and
 * «snapshot + partner + offsite» (2.42; the value is the mode, the partner part the draft's list `pKey`); the partner
 * options are greyed with the engine's reason when the share isn't a ZFS dataset of its own
 */
function modeSelect(sh, modes, pKey) {
  const key = `share|${sh.name}|mode`;
  const withPartner = !!(pKey && setupPartners().length);
  const pOn = withPartner && partnerListOf(pKey).length > 0;
  const sel = el('select', 'picker');
  const options = [];
  modes.forEach((o) => {
    options.push({ value: o, mode: o, partner: false, text: T('setup.mode.' + o) });
    if (withPartner && o !== 'off') options.push({ value: o + '+partner', mode: o, partner: true, text: T('setup.mode.' + o + '_partner') });
  });
  const mode = dget(key, modes[modes.length - 1]);
  options.forEach((o) => {
    const opt = new Option(o.text, o.value);
    if (o.partner && !sh.partner_ok) { opt.disabled = true; opt.title = T('partner.why.' + (sh.partner_why || 'no_dataset')); }
    else if (o.mode === 'snapshot' && shareNoSnap(sh)) { opt.disabled = mode !== 'snapshot'; opt.title = T('setup.no_snap_opt'); }
    sel.appendChild(opt);
  });
  const now = options.find((o) => o.mode === mode && o.partner === (pOn && mode !== 'off')) || options.find((o) => o.mode === mode) || options[options.length - 1];
  sel.value = now.value;
  sel.onchange = () => {
    const o = options.find((x) => x.value === sel.value) || now;
    dset(key, o.mode);
    if (withPartner) partnerListSet(pKey, o.partner);
    setupDerive();
    Office.keepInPlace(sel, () => renderSetup());
  };
  return sel;
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
    // the filter: its name, its folders at the top, its new folders; «only what's new»: a new share or one with new folders
    const ws = waits.filter((w) => w.share === sh.name);
    if (!fitRow([sh.name, ...(sh.top || []), ...ws.map((w) => w.dir)], shareIsNew(sh, ws))) return;
    const wordsFit = !fitting.flt.words.length || setupFits({ words: fitting.flt.words, onlyNew: false }, sh.name, false);
    const wsShown = wordsFit ? ws : ws.filter((w) => setupFits({ words: fitting.flt.words, onlyNew: false }, w.dir, true));
    const tr = Office.place('setup:share:' + sh.name, el('tr'));
    tr.dataset.share = sh.name;
    const nameCell = el('th');
    const shareName = el('span', '', sh.name);
    nameCell.appendChild(shareName);
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
    const isPlace = sh.name === dget('general|dumps_share', '');
    // the share's partner list (2.42): the select's partner options set it — the backup place has its own switch in «Basics»
    const pKey = isPlace ? null : `share|${sh.name}|partner`;
    // the backup place: at least local, Kopia on top is the user's choice
    const atLeast = lock && lock.min ? LV.slice(lock.lv).filter((o) => kopiaOn || o !== 'kopia').reverse() : null;
    if (sh.exists && atLeast && atLeast.length > 1) {
      const sel = modeSelect(sh, atLeast, pKey);
      sel.title = T('setup.lock_place_hint');
      modeCell.appendChild(sel);
    } else if (sh.exists && lock) {
      // fixed by the VMs and apps above (or the backup place) — the partner part stays the user's
      const sel = modeSelect(sh, [LV[lock.lv]], pKey);
      if (!pKey || !setupPartners().length) sel.disabled = true;
      sel.title = T(lock.min ? 'setup.lock_place_hint' : 'setup.lock_hint');
      modeCell.appendChild(sel);
    } else if (sh.exists) {
      const opts = kopiaOn ? ['kopia', 'snapshot', 'off'] : ['snapshot', 'off'];
      modeCell.appendChild(modeSelect(sh, opts, pKey));
    } else {
      modeCell.appendChild(chip(T('setup.gone'), 'warn'));
    }
    if (sh.exists && pKey) {
      const pc = partnerChoice(pKey, !!sh.partner_ok, sh.partner_why, dget(`share|${sh.name}|mode`, 'off') === 'off');
      if (pc) modeCell.appendChild(pc);
      // 1.60.1: explained only - bringing it onto one pool, or making it a dataset, is the user's
      if (setupPartners().length && !sh.partner_ok && ['not_dataset', 'split'].includes(sh.partner_why)) {
        modeCell.appendChild(whyFold('share:' + sh.name, T('dsw.share_' + sh.partner_why), null));
      }
    }
    // engine 2.55: said on the row — at «local snapshot» nothing backs it up, at «offsite» Kopia reads it live
    if (sh.exists && shareNoSnap(sh)) {
      const m = dget(`share|${sh.name}|mode`, 'off');
      if (m === 'snapshot') modeCell.append(' ', chip(T('setup.no_snap'), 'warn', T(kopiaOn ? 'setup.no_snap_hint' : 'setup.no_snap_hint_nokopia')));
      else if (m === 'kopia') modeCell.append(' ', chip(T('live'), '', T('live_hint')));
    }
    tr.appendChild(modeCell);
    const why = el('td', 'bk-why', lock ? T('setup.lock', { list: lock.why.join(', ') }) : shareWhy(sh));
    if (sh.notes.length) why.title = sh.notes.join('\n');
    tr.appendChild(why);
    const more = el('td');
    if (sh.exists) {
      // the whole row unfolds to its details, the button beside it says so (setupUnfold())
      const toggle = () => setupToggleOpen(sh.name, tr);
      const b = button(setup.open.has(sh.name) ? T('setup.less') : T('setup.more'), 'small plain', toggle);
      more.appendChild(b);
      tr.dataset.unfold = sh.name;
      setupUnfold(tr, shareName, b, setup.open.has(sh.name), toggle);
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
    // its new folders: only local so far, waiting for a decision (engine 2.21) - with filter words those they meet
    if (wsShown.length) {
      const wtr = el('tr', 'bk-detail');
      const td = el('td');
      td.colSpan = 6;
      td.appendChild(waitingBox(wsShown));
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
 * VM it belongs to - then that one's level decides; the others the user decides: only local or local + Kopia.
 * Engine 2.37: only the app/VM shares (app_share) have such folders - in every other share a new folder goes with it.
 */
function waitingFolders() {
  const m = setup.model;
  if (!m || dget('kopia|enabled') !== 'yes') return [];
  const out = [];
  (setup.plan.shares || []).forEach((sh) => {
    if (!(sh.waiting || []).length || dget(`share|${sh.name}|mode`) !== 'kopia') return;
    if ('app_share' in sh && !(sh.app_share || []).length) return;
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
function waitChoice(w, from = null) {          // from: other settings than the draft (the proposal: setup.base)
  const get = (k) => (from ? from[k] : dget(k, [])) || [];
  const known = get(`share|${w.share}|kopia_known`);
  const ign = get(`share|${w.share}|kopia_ignore`);
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
const waitHow = (w) => (w.owner ? T('setup.waiting_follows', { name: w.owner.name, level: levelWord(w.owner.key, unitPartnerKey(w.owner.key)) })
  : T('setup.waiting_' + (waitChoice(w) || 'local')));

/** Step 3, under a share: its new folders, waiting for a decision - only local so far */
function waitingBox(ws) {
  const box = el('div', 'bk-waiting bk-isnew');
  const head = el('div', 'role', T('setup.waiting_head'));
  if (presetNow() !== 'auto') head.append(' ', newChip());      // engine 2.31: the default decided them - Apply takes them in so
  box.appendChild(head);
  ws.forEach((w) => {
    const row = Office.place(`setup:wait:${w.share}/${w.dir}`, el('div', 'bk-waitrow'));
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

/** Each app's own folders (ownFolders) and the ones it only uses (otherFolders, with their owner's name or null) */
function ownFoldersOf(apps) {
  const users = new Map();
  apps.forEach((a) => a.folders.forEach((f) => {
    const k = `${f.share}|${f.dir}`;
    if (!users.has(k)) users.set(k, []);
    users.get(k).push(a);
  }));
  const ownerOf = new Map();
  users.forEach((list, k) => {
    const dir = k.slice(k.indexOf('|') + 1).toLowerCase();
    const named = list.find((a) => [a.name, ...a.members].some((n) => String(n).toLowerCase() === dir));
    const writers = list.filter((a) => a.rwDirs && a.rwDirs.has(k));
    ownerOf.set(k, named || (writers.length === 1 ? writers[0] : null));
  });
  apps.forEach((a) => {
    a.ownFolders = a.folders.filter((f) => ownerOf.get(`${f.share}|${f.dir}`) === a);
    a.otherFolders = a.folders.filter((f) => ownerOf.get(`${f.share}|${f.dir}`) !== a)
      .map((f) => ({ ...f, owner: (ownerOf.get(`${f.share}|${f.dir}`) || {}).name || null }));
    delete a.rwDirs;
  });
}

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
    a.rwDirs = new Set(a.members.flatMap((c) => (c.binds || []).filter((b) => b.rw !== false && b.rw !== 'ro').map((b) => `${b.share}|${b.path.split('/')[0]}`)));
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
      // a cache that fills itself again apart from real data (the agent's volumes_cache, backupVolumeIsCache(); an older
      // agent's plan has none: all count as data)
      volumes: a.members.flatMap((c) => c.volumes.filter((v) => !(c.volumes_cache || []).includes(v)).map((v) => (a.stack ? `${c.name}: ${v}` : v))),
      volumesCache: a.members.flatMap((c) => c.volumes.filter((v) => (c.volumes_cache || []).includes(v)).map((v) => (a.stack ? `${c.name}: ${v}` : v))),
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
  // 1.60.1 (a user: dropnook got «Make it a dataset…» for Nginx-Proxy-Manager-Official, a folder it only mounts read-only
  // for the certificates): a folder belongs to ONE app - the one named like it, else the only one writing to it; any other
  // app using it gets a note («also uses …»), never that folder's actions or partner level
  ownFoldersOf(apps);
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
  setup.depsOff = new Set();
  setup.depNote = new Map();
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
    // a share ticked to follow the app is raised to it, never lowered (it may go further than the app on its own)
    a.deps.forEach((d) => { if (setup.deps.has(`${a.id}|${d.share}`)) want(d.share, Math.max(l, LV.indexOf(dget(`share|${d.share}|mode`, 'off'))), a.name); });
  });
  const nostop = [...was.filter((n) => keep.includes(n)), ...keep.filter((n) => !was.includes(n))];
  dset('docker|no_stop', [...new Set(nostop)]);
  // a container in no app here (its Compose stack brought down, engine 2.60) keeps its place, as stored
  dset('docker|skip', [...new Set([...wasSkip.filter((n) => skip.includes(n) || !inApps.has(n)), ...skip.filter((n) => !wasSkip.includes(n))])]);
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
      // engine 2.45: an app with a partner level keeps its folder in its section (the unit is its folder's dataset)
      if (kind === 'app' && (dget(pre + 'partner', []) || []).length && folders.length) dset(pre + 'folder', folders);
    }
  };
  const names = new Set();
  m.apps.forEach((a) => {
    const yes = on && levelOf('app:' + a.id) === 2 && !names.has(a.name);
    names.add(a.name);
    const mine = a.folders.filter((f) => (owner.get(`${f.share}|${f.dir}`) || {}).id === a.id).map((f) => `${f.share}/${f.dir}`);
    // a partner level: its own folders (ownFoldersOf()), the first being the unit's dataset
    const own = (a.ownFolders || []).map((f) => `${f.share}/${f.dir}`);
    set('app', a.name, yes, yes ? mine : (dget(`app|${a.name}|partner`, []) || []).length ? own : []);
  });
  m.vms.forEach((x) => {
    const yes = on && levelOf('vm:' + x.name) === 2;
    set('vm', x.name, yes, yes ? x.folders.map((f) => `${f.share}/${f.dir}`) : []);
  });
}

/**
 * An app and the partners (engine 2.45): its folder (the first) a dataset of its own → a unit of its own
 * (`app|<name>|partner`); a folder inside its share's dataset travels with the share when the share goes to a partner
 * (`withShare`, said as a chip, no choice). A dataset is never part of its share's transfer (zfs send per dataset), so
 * an app with its own dataset keeps its own level also when the share goes.
 */
function appPartner(a, plan) {
  const f = (a.ownFolders || a.folders || [])[0] || null;
  const own = !!(f && ownDataset(plan, f.share, f.dir));
  const withShare = f && !own ? setupPartners().filter((p) => (dget(`share|${f.share}|partner`, []) || []).includes(p.id)) : [];
  return { f, own, withShare, key: `app|${a.name}|partner` };
}

/**
 * An app's further share (a «… mitsichern» row) backed up at least as far as the app — the rule of the Team Lead's hint
 * app_share_* (backupAppShareGaps() in agent/desks/backup.php) on the draft: not «off» (unless it goes to a partner), at
 * Kopia when the app is, at every partner the app goes to (its own and, for a folder that is no dataset of its own, its
 * share's). testBackupAppShare holds both to the same answers. (2026-10-17, the owner: the boxes started unticked on
 * every visit although the share was backed up like the app)
 */
function depCovered(a, share, plan) {
  const l = levelOf('app:' + a.id);
  if (l === 0) return true;
  const mode = dget(`share|${share}|mode`, 'off');
  const has = dget(`share|${share}|partner`, []) || [];
  if (mode === 'off' && !has.length) return false;
  if (l === 2 && mode !== 'kopia') return false;
  const partners = [...(dget(`app|${a.name}|partner`, []) || [])];
  (a.folders || []).forEach((f) => { if (!ownDataset(plan, f.share, f.dir)) partners.push(...(dget(`share|${f.share}|partner`, []) || [])); });
  return partners.every((p) => has.includes(p));
}
/** The box as shown: ticked by the user, or the share already as far as the app and not unticked here */
const depTicked = (a, share, plan) => {
  const key = `${a.id}|${share}`;
  return setup.deps.has(key) || (!setup.depsOff.has(key) && depCovered(a, share, plan));
};

/**
 * A «… mitsichern» box clicked. Ticked: a share already as far as the app only says so (`same`, nothing changes, so no
 * bar); one that goes less far follows the app from now (raised, the bar counts it; 1.60.1: to the app's partners too
 * when it can). Unticked: the share stays where it is (`kept`, never lowered) - step 3 is where it changes.
 */
function depClick(a, share, v, sh, plan, row) {
  const key = `${a.id}|${share}`;
  setup.depNote.delete(key);
  if (v) {
    setup.depsOff.delete(key);
    if (depCovered(a, share, plan)) setup.depNote.set(key, 'same');
    else {
      setup.deps.add(key);
      // 1.60.1 (a user, dropnook): ticked under an app that goes to partners, the share goes there too (when it can)
      const ap = dget(`app|${a.name}|partner`, []) || [];
      if (ap.length && sh && sh.partner_ok) dset(`share|${share}|partner`, [...new Set([...(dget(`share|${share}|partner`, []) || []), ...ap])]);
    }
  } else {
    setup.deps.delete(key);
    setup.depsOff.add(key);
    setup.depNote.set(key, 'kept');
  }
  setupDerive();
  Office.keepInPlace(row, () => renderSetup());
}

/**
 * An app going to a partner whose further shares (its binds outside its own folder - the «… mitsichern» rows) don't
 * go there (a user, dropnook to Tower without its share drop: at the partner its data would be missing) → [{share,
 * partners [{id, name}], ok (the share can go: a dataset of its own), why}]
 */
function appDepGaps(a, plan) {
  const mine = dget(`app|${a.name}|partner`, []) || [];
  if (!mine.length || levelOf('app:' + a.id) === 0) return [];
  return (a.deps || []).map((d) => {
    const sh = (plan.shares || []).find((x) => x.name === d.share) || {};
    const has = dget(`share|${d.share}|partner`, []) || [];
    const partners = setupPartners().filter((p) => mine.includes(p.id) && !has.includes(p.id));
    return { share: d.share, partners, ok: !!sh.partner_ok, why: sh.partner_why || 'no_dataset' };
  }).filter((g) => g.partners.length);
}

/**
 * «Why?» at a unit that can't take a partner level (1.60.1, a user): snapshots and zfs send work per dataset, so only a
 * dataset of its own travels alone — said in plain words (dsw.<kind>), folded, kept open as the user left it. For an app's
 * or a VM's folder on a ZFS pool «Make it a dataset of its own…» (dsw.make): a job of Mr. Restori's (setupMakeDataset()).
 * `target` {kind, name, share, dir} or null (explain only: a share on several pools, a share in a pool's folder)
 */
function whyFold(key, text, target) {
  const det = keepFold(el('details', 'bk-why'), 'why:' + key, false);
  det.addEventListener('click', (e) => e.stopPropagation());     // inside a share's row, which unfolds on a click itself
  det.appendChild(el('summary', '', T('dsw.why')));
  det.appendChild(el('p', 'role', text));
  if (target) {
    const b = el('button', 'btn small', T('dsw.make'));
    b.type = 'button';
    b.disabled = !Office.agent.running || live();
    b.onclick = () => setupMakeDataset(target);
    det.appendChild(b);
  }
  return det;
}
/** A unit's folder in a share from a path (/mnt/<user|pool>/<share>/<dir>/…) */
function folderOf(path) {
  const m = /^\/mnt\/[^/]+\/([^/]+)\/([^/]+)\//.exec(String(path || ''));
  return m ? { share: m[1], dir: m[2] } : null;
}
/** Is the folder a dataset of its own already (the plan's share children: datasets inside the share)? */
const ownDataset = (plan, share, dir) => ((plan.shares || []).find((x) => x.name === share) || { children: [] }).children
  .some((c) => String(c).split('/').pop() === dir);

/**
 * The job going on (a user: «no progress while it ran»): a dialog with the wave left of the step (stopping · moving aside ·
 * creating · copying n % · comparing · starting · storeroom), Mr. Restori's job file read every two seconds (api part
 * restore/job); at the end the result (done / rolled back / failed) and what to do next - and the setup planned anew so
 * the unit shows its partner levels (as «Look at the server again» does)
 */
function datasetProgress(target, id, stack) {
  const box = el('div', 'bk-dsp');
  const line = el('p', 'running');
  const bar = el('div', 'bar thin');
  const fill = el('i', 'data');
  bar.appendChild(fill);
  bar.hidden = true;
  const after = el('p', 'role');
  box.append(line, bar, after);
  line.append(el('span', 'spin'), ' ', T('dsw.queued'));
  Office.dialog({ title: T('dsw.title', { name: target.dir }), body: box, buttons: [{ text: Office.t('common.close') }] });
  let fails = 0;
  const look = async () => {
    const j = await Office.api.get({ a: 'part', desk: 'restore', part: 'job' });
    fails = j.ok ? 0 : fails + 1;
    const job = j.ok && j.part && j.part.id === id ? j.part : null;
    if (!job || ['queued', 'running'].includes(job.result)) {
      const st = job ? (job.steps || []).find((x) => x.state === 'running') : null;
      const pct = st && st.progress && st.progress.percent !== null && st.progress.percent !== undefined ? Math.min(100, Number(st.progress.percent)) : null;
      line.textContent = '';
      line.append(el('span', 'spin'), ' ', st ? T('dsw.step.' + st.do) : T('dsw.queued'), pct !== null ? ` · ${pct} %` : '');
      bar.hidden = pct === null;
      fill.style.width = (pct || 0) + '%';
      setTimeout(look, Office.pollDelay(2000, fails));
      return;
    }
    line.classList.remove('running');
    bar.hidden = true;
    const ok = job.result === 'ok' || job.result === 'warnings';
    line.textContent = T(ok ? 'dsw.done' : job.reason === 'md_rolled_back' ? 'dsw.rolled_back' : 'dsw.failed', { path: `${target.share}/${target.dir}` });
    after.textContent = stack ? T('dsw.done_stack', { stack }) : '';
    if (ok) Office.toast(T('dsw.done', { path: `${target.share}/${target.dir}` }));
    await setupPlan(false);           // the unit a dataset of its own now: its partner levels show
  };
  look();
}

/**
 * «Make it a dataset of its own…»: the dialog says plainly that this step is delicate, when the last backup ran (none
 * fresh — «Make it» stays grey until the user says he knows, or «Back up first»), what stops and for about how long;
 * then Mr. Restori's job (backup.dataset_make) — its journal and run card on his page
 */
async function setupMakeDataset(target) {
  const j = await Office.api.post(`${ID}.dataset_look`, target);
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  const box = el('div');
  box.appendChild(el('p', 'callout warn', T('dsw.delicate', { path: (j.target && j.target.path) || `${target.share}/${target.dir}` })));
  // a Compose stack still running is no refusal: the dialog says to stop it in its own tool and looks again on asking
  const stackRunning = (j.blockers || []).find((b) => b.key === 'md_stack_running');
  const blockers = (j.blockers || []).filter((b) => b.key !== 'md_stack_running');
  if (blockers.length) {
    blockers.forEach((b) => box.appendChild(el('p', 'callout', T('dsw.no.' + b.key, b.params || {}))));
    Office.dialog({ title: T('dsw.title', { name: target.dir }), body: box, buttons: [{ text: Office.t('common.close') }] });
    return;
  }
  const last = j.last;
  const fresh = !!(last && last.fresh);
  // whether *this* app or VM is in that backup (agent: its package in the run's list; null = the run doesn't say)
  box.appendChild(el('p', '', !last || !last.time ? T('dsw.last_none')
    : last.unit === false ? T('dsw.last_without', { when: fmt.relative(last.time), name: target.name })
      : T(fresh ? (last.unit ? 'dsw.last_fresh_in' : 'dsw.last_fresh') : last.result === 'failed' ? 'dsw.last_failed' : 'dsw.last_old',
        { when: fmt.relative(last.time), name: target.name })));
  const st = j.stops || {};
  const what = [...(st.containers || []), ...(st.vm ? [st.vm] : [])];
  if (st.stack) {
    // a stack: its containers' order (depends_on, the database first) is its own tool's - the user stops and starts it
    box.appendChild(el('p', '', T('dsw.stack', { stack: st.stack })));
    // every container of the stack, the running ones marked (a user: the dialog named only «drop», not «drop-share»)
    const runs = new Set(String((stackRunning && stackRunning.params && stackRunning.params.names) || '').split(', ').filter(Boolean));
    const all = [...new Set([...(target.members || []), ...runs])];
    if (all.length) box.appendChild(el('p', '', T('dsw.stack_members', { list: all.map((n) => (runs.has(n) ? T('dsw.runs', { name: n }) : n)).join(', ') })));
    if (stackRunning) box.appendChild(el('p', 'callout warn', T('dsw.stack_running', { stack: st.stack, names: (stackRunning.params || {}).names || '' })));
  } else {
    box.appendChild(el('p', '', what.length ? T('dsw.stops', { list: what.join(', '), n: what.length }) : T('dsw.stops_none')));
  }
  if (j.downtime) box.appendChild(el('p', '', T('dsw.downtime', { duration: fmt.duration(j.downtime), size: fmt.size(j.bytes || 0) })));
  box.appendChild(el('p', 'role', T('dsw.how', { dataset: j.target.dataset })));
  let ok = fresh && !stackRunning;
  const tick = el('label', 'check');
  const cb = el('input');
  cb.type = 'checkbox';
  if (!fresh) {
    tick.append(cb, ' ', T('dsw.no_fresh_ok'));
    box.appendChild(tick);
  }
  const d = Office.dialog({
    title: T('dsw.title', { name: target.dir }),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      ...(fresh ? [] : [{ text: T('dsw.backup_first'), kind: 'plain', act: async () => { await startRun('backup'); } }]),
      ...(stackRunning ? [{ text: T('dsw.look_again'), kind: 'plain', act: () => { setTimeout(() => setupMakeDataset(target), 0); } }] : []),
      { text: T('dsw.go'), kind: '', act: async () => {
        const { members, ...req } = target;
        const r = await Office.api.post(`${ID}.dataset_make`, { ...req, no_fresh_ok: !fresh && cb.checked });
        if (!r.ok) { Office.toast(Office.errorText(r.error, ID), true); return false; }
        setTimeout(() => datasetProgress(target, r.id, st.stack || null), 0);
        return true;
      } },
    ],
  });
  // without a fresh backup «Make it» stays grey until the user says he knows
  const go = d && d.buttons ? d.buttons[d.buttons.length - 1] : null;
  if (go) go.disabled = !ok;
  cb.onchange = () => { ok = cb.checked && !stackRunning; if (go) go.disabled = !ok; };
}

/**
 * The level switch: off | local | local + partner | local + offsite | local + partner + offsite (2.42: the two partner
 * fields only with a pair — `partner` {key, ok, why} names the unit's partner list in the draft; grey with the engine's
 * reason when the unit isn't a ZFS dataset of its own). The kopia level (0, 1, 2) stays in setup.levels; the partner
 * part is the draft's list (every partner, or the chips' choice with two and more). Five fields wrap at 390 px (desk.css).
 */
const LEVEL_FIELDS = [{ id: 0, lv: 0, partner: false, key: '0' }, { id: 1, lv: 1, partner: false, key: '1' }, { id: 'p', lv: 1, partner: true, key: 'partner' },
  { id: 2, lv: 2, partner: false, key: '2' }, { id: 'pk', lv: 2, partner: true, key: 'partner_kopia' }];
/**
 * A unit's level in the words of its level picker (LEVEL_FIELDS), the partner part included: «local + partner +
 * offsite» when its partner list (`pKey`) names a partner of the plan — never «local + offsite» for what also goes to
 * a partner (a user, 2026-10-16: «Apply the settings?» said «App Jellyfin: local + offsite» for local + partner + offsite)
 */
function levelWord(key, pKey) {
  const l = levelOf(key);
  if (l > 0 && pKey && partnerListOf(pKey).length) return T(l === 1 ? 'setup.level.partner' : 'setup.level.partner_kopia');
  return T('setup.level.' + l);
}
/** The partner list key of a unit key (app:<id> → app|<name>|partner, vm:<name> → vm|<name>|partner) */
function unitPartnerKey(key) {
  if (key.startsWith('vm:')) return `vm|${key.slice(3)}|partner`;
  const a = key.startsWith('app:') && setup.model ? setup.model.apps.find((x) => 'app:' + x.id === key) : null;
  return a ? `app|${a.name}|partner` : null;
}
/** The draft's partner list of a unit, only partners of the plan */
function partnerListOf(key) {
  const ids = setupPartners().map((p) => p.id);
  return (dget(key, []) || []).filter((id) => ids.includes(id));
}
/** The partner level on: the list every partner (the first time), the chips' choice after */
function partnerListSet(key, on) {
  if (!on) { dset(key, []); return; }
  if (!partnerListOf(key).length) dset(key, setupPartners().map((p) => p.id));
}
function levelPick(key, onPick, partner) {
  const max = dget('kopia|enabled') === 'yes' ? 2 : 1;
  const now = levelOf(key);
  const withPartner = !!(partner && setupPartners().length);
  const pOn = withPartner && partnerListOf(partner.key).length > 0;
  const fields = LEVEL_FIELDS.filter((f) => f.lv <= max && (withPartner || !f.partner));
  const nowId = (fields.find((f) => f.lv === now && f.partner === (now > 0 && pOn)) || fields[0]).id;
  const g = el('div', 'bk-seg' + (fields.length > 3 ? ' bk-seg-five' : ''));
  g.setAttribute('role', 'radiogroup');
  fields.forEach((f) => {
    const b = el('button', f.id === nowId ? 'on' : '', T('setup.level.' + f.key));
    b.type = 'button';
    b.setAttribute('role', 'radio');
    b.setAttribute('aria-checked', String(f.id === nowId));
    b.title = T('setup.level_hint.' + (f.key === 'partner' && key.startsWith('vm:') ? 'partner_vm' : f.key), { partners: setupPartners().map((p) => p.name).join(', ') });
    if (f.partner && !partner.ok) { b.disabled = true; b.title = T('partner.why.' + (partner.why || 'no_dataset')); }
    b.onclick = () => {
      if (f.id === nowId) return;
      setup.levels[key] = f.lv;
      if (withPartner) partnerListSet(partner.key, f.partner);
      if (onPick) onPick(f.lv);
      setupDerive();
      Office.keepInPlace(g, () => renderSetup());
    };
    g.appendChild(b);
  });
  return g;
}

/** Two and more partners: a chip per partner under the switch while a partner level is on (unticking the last one = «local») */
function partnerChoice(key, ok, why, off) {
  if (setupPartners().length < 2 || !partnerListOf(key).length) return null;
  return partnerChips(key, ok, why, off);
}

/** The backup place in «Basics» (2.42): local · local + partner — its offsite part is its share's mode in step 3 */
function placeLevelPick(plan) {
  const ds = dget('general|dumps_share', '');
  const row = plan.shares.find((x) => x.name === ds);
  if (!row || !setupPartners().length) return null;
  const key = 'general|partner_place';
  const on = partnerListOf(key).length > 0;
  const off = dget(`share|${ds}|mode`, 'off') === 'off';
  const wrap = el('div', 'bk-place-level');
  const g = el('div', 'bk-seg');
  g.setAttribute('role', 'radiogroup');
  [{ id: 'l', partner: false, key: '1' }, { id: 'p', partner: true, key: 'partner' }].forEach((f) => {
    const now = f.partner === on;
    const b = el('button', now ? 'on' : '', T('setup.level.' + f.key));
    b.type = 'button';
    b.setAttribute('role', 'radio');
    b.setAttribute('aria-checked', String(now));
    b.title = T(f.partner ? 'partner.place_hint' : 'setup.level_hint.1', { partners: setupPartners().map((p) => p.name).join(', ') });
    if (f.partner && !row.partner_ok) { b.disabled = true; b.title = T('partner.why.' + (row.partner_why || 'no_dataset')); }
    else if (f.partner && off) { b.disabled = true; b.title = T('partner.off_hint'); }
    b.onclick = () => {
      if (now) return;
      partnerListSet(key, f.partner);
      setupDerive();
      Office.keepInPlace(g, () => renderSetup());
    };
    g.appendChild(b);
  });
  wrap.append(el('span', 'role', T('partner.place')), g);
  const chips = partnerChoice(key, !!row.partner_ok, row.partner_why, off);
  if (chips) wrap.appendChild(chips);
  return wrap;
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
    if (!fitRow([v.name, ...x.folders.map((f) => f.dir)], x.isNew)) return;
    const k = (y) => `vm|${v.name}|${y}`;
    const l = levelOf('vm:' + v.name);
    const row = Office.place('setup:vm:' + v.name, el('div', 'row nocheck bk-vm' + (x.isNew ? ' bk-isnew' : '')));
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
    const pc = partnerChoice(k('partner'), !!v.partner_ok, v.partner_why, l === 0);
    if (pc) main.appendChild(pc);
    // 1.60.1: why it can't go to a partner alone - and «Make it a dataset of its own…» for its folder
    if (setupPartners().length && !v.partner_ok && ['no_dataset', 'not_dataset', 'split'].includes(v.partner_why)) {
      const f = folderOf(((v.disks || [])[0] || {}).source);
      main.appendChild(whyFold('vm:' + v.name, T(v.partner_why === 'split' ? 'dsw.vm_split' : 'dsw.vm'),
        v.partner_why !== 'split' && f ? { kind: 'vm', name: v.name, share: f.share, dir: f.dir } : null));
    }
    row.appendChild(main);

    const right = el('div', 'bk-right');
    // from "off" up: freeze or pause proposed again (a VM of its own could keep running at "off")
    right.appendChild(levelPick('vm:' + v.name, (nl) => { if (l === 0 && nl > 0) dset(k('prepare'), undefined); }, { key: k('partner'), ok: !!v.partner_ok, why: v.partner_why }));
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
  const gone = goneApps(plan);
  if (!m.apps.length && !m.skipped.length && !gone.length) { s.appendChild(el('p', 'empty', T(plan.docker_service === false ? 'setup.apps_docker_off' : 'setup.apps_none'))); return s; }
  const shareOf = (n) => plan.shares.find((x) => x.name === n);
  const list = el('div', 'box');
  let head = null;
  m.apps.forEach((a) => {
    if (!fitRow([a.name, ...a.members, ...a.folders.map((f) => f.dir), ...a.deps.map((d) => d.share)], appIsNew(a))) return;
    const kind = a.isNew ? 'new' : a.stack ? 'stacks' : 'single';      // the new ones first: they wait for a decision
    if (head !== kind) { list.appendChild(el('div', 'bk-subhead', T('setup.apps_' + kind))); head = kind; }
    const l = levelOf('app:' + a.id);
    const row = Office.place('setup:app:' + a.id, el('div', 'row nocheck bk-app' + (a.isNew ? ' bk-isnew' : '')));
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
    // engine 2.60: a Nextcloud stopped right now keeps its setting - its tip says so (occ is asked when it runs)
    if (a.ncs.length) {
      meta.appendChild(chip(T('setup.app_nc'), l > 0 ? 'ok' : '', [T('setup.nc_text'),
        ...a.ncs.filter((n) => n.running === false).map((n) => T('setup.msg.stopped_kept', { name: n.members.join(' + ') }))].join(' ')));
    }
    if (a.volumes.length) meta.appendChild(chip(T('setup.ct_volumes', { list: a.volumes.join(', ') }), 'danger', T('setup.ct_volumes_hint')));
    if (a.volumesCache.length) meta.appendChild(chip(T('setup.ct_volumes_cache', { list: a.volumesCache.join(', ') }), '', T('setup.ct_volumes_cache_hint')));
    if (!a.folders.length && !a.deps.length) {
      const flashOff = dget('flash|mode', 'off') === 'off';
      meta.appendChild(chip(T('setup.app_nodata'), flashOff ? 'warn' : '', T(flashOff ? 'setup.app_nodata_noflash' : 'setup.app_nodata_hint')));
    }
    main.appendChild(meta);
    appDataWarnings(a, l, plan).forEach((w) => main.appendChild(w));
    // 1.60.1: an app travels with its share - alone only as a dataset of its own («Make it a dataset of its own…»)
    const ap = appPartner(a, plan);
    if (setupPartners().length) {
      if (ap.withShare.length) {
        const pm = el('div', 'row-meta');
        pm.appendChild(chip(T('partner.with_share', { partners: ap.withShare.map((p) => p.name).join(', '), share: ap.f.share }), 'ok', T('partner.with_share_hint')));
        main.appendChild(pm);
      } else if (ap.own && ap.f && (dget(`share|${ap.f.share}|partner`, []) || []).length) {
        main.appendChild(el('div', 'row-meta role', T('partner.own_too', { share: ap.f.share })));
      } else if (!ap.own) {
        (a.ownFolders || a.folders).filter((f) => !ownDataset(plan, f.share, f.dir)).slice(0, 3)
          .forEach((f) => main.appendChild(whyFold(`app:${a.id}:${f.share}/${f.dir}`, T('dsw.app', { share: f.share, dir: f.dir }),
            { kind: 'app', name: a.name, share: f.share, dir: f.dir, stack: a.stack ? a.name : '', members: a.members })));
      }
      // another app's folder it only uses: a note, never its actions
      (a.otherFolders || []).slice(0, 3).forEach((f) => main.appendChild(el('div', 'row-meta role',
        f.owner ? T('partner.uses_other', { folder: `${f.share}/${f.dir}`, owner: f.owner }) : T('partner.uses_folder', { folder: `${f.share}/${f.dir}` }))));
    }
    row.appendChild(main);
    const right = el('div', 'bk-right');
    // engine 2.45: «local + partner» for an app whose folder is a dataset of its own; none while it goes with its share
    right.appendChild(levelPick('app:' + a.id, null, setupPartners().length && !ap.withShare.length ? { key: ap.key, ok: ap.own, why: ap.own ? null : 'not_dataset' } : null));
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
    // 1.60.1: it goes to a partner, a share it uses doesn't - said, with the way to send that share too
    appDepGaps(a, plan).forEach((g) => {
      const w = el('div', 'row nocheck bk-dep');
      const p = el('p', 'callout warn', T('partner.dep_gap', { app: a.name, partners: g.partners.map((x) => x.name).join(', '), share: g.share }) + ' ');
      if (g.ok) {
        const b = el('button', 'btn small', T('partner.dep_send', { share: g.share }));
        b.type = 'button';
        b.onclick = () => {
          setup.deps.add(`${a.id}|${g.share}`);
          dset(`share|${g.share}|partner`, [...new Set([...(dget(`share|${g.share}|partner`, []) || []), ...g.partners.map((x) => x.id)])]);
          setupDerive();
          Office.keepInPlace(w, () => renderSetup());
        };
        p.appendChild(b);
      } else {
        p.append(T('partner.dep_cannot', { share: g.share, why: T('partner.why_short.' + g.why) }));
      }
      w.appendChild(p);
      list.appendChild(w);
    });
    // the shares it uses besides its own folder: never ticked unasked - they can grow fast
    if (l > 0) {
      a.deps.forEach((d) => {
        const sh = shareOf(d.share);
        const key = `${a.id}|${d.share}`;
        const sub = el('div', 'row nocheck bk-dep');
        const size = sh && sh.gb !== null ? (sh.gb < 0 ? '> ?' : fmt.size(sh.gb * 1073741824)) : T('setup.app_dep_unknown');
        const where = d.paths.length ? T('setup.app_dep_paths', { paths: d.paths.join(', ') }) : '';
        const mode = dget(`share|${d.share}|mode`, 'off');
        // its step-3 level in words, with its partners (as the level's own name says it)
        const modeText = T('setup.mode.' + mode + (mode !== 'off' && (dget(`share|${d.share}|partner`, []) || []).length ? '_partner' : ''));
        const ticked = depTicked(a, d.share, plan);
        // a click's word while the box still shows what it did (the app's level changed since: the box speaks)
        const note = [setup.depNote.get(key)].find((n) => n && (n === 'same') === ticked);
        const step3 = !ticked && !note && mode !== 'off' ? T('setup.app_dep_step3', { mode: modeText }) : '';
        sub.title = step3 ? T('setup.app_dep_step3_hint') : '';
        const box = checkbox(T('setup.app_dep', { share: d.share }), ticked, (v) => depClick(a, d.share, v, sh, plan, sub),
          [step3, size, where, T('setup.app_dep_hint')].filter(Boolean).join(' · '));
        // what the click did, said on the row at once (2026-10-17, the owner: three ticks, «nothing happened»)
        if (note) box.lastChild.appendChild(el('small', 'bk-dep-note', T('setup.app_dep_' + note, { share: d.share, mode: modeText })));
        sub.appendChild(box);
        list.appendChild(sub);
      });
    }
  });
  m.skipped.forEach((c) => {
    if (!fitRow(c.name, false)) return;
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', c.name));
    // Kopia itself: only its template and the user's access data matter for a restore
    if (c.kopia) main.appendChild(el('div', 'row-meta', T('setup.app_kopia_text')));
    row.appendChild(main);
    // in .bk-right like the other rows: at phone width it goes under the description instead of squeezing it
    const right = el('div', 'bk-right');
    right.appendChild(chip(T(c.kopia ? 'setup.ct_kopia' : 'setup.ct_office'), 'quiet'));
    row.appendChild(right);
    list.appendChild(row);
  });
  // in settings.ini, gone from the server (engine 2.40): like a gone share — no level, Apply removes the entry
  let goneHead = false;
  gone.forEach((a) => {
    const folders = a.keys && Array.isArray(a.keys.folder) ? a.keys.folder : [];
    if (!fitRow([a.name, ...folders], false)) return;
    if (!goneHead) { list.appendChild(el('div', 'bk-subhead', T('setup.apps_gone'))); goneHead = true; }
    const row = el('div', 'row nocheck bk-app bk-gone');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', a.name));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('setup.why.gone')));
    if (a.keys && a.keys.kopia === 'yes' && folders.length) meta.appendChild(el('span', '', T('setup.app_gone_had', { list: folders.join(', ') })));
    main.appendChild(meta);
    row.appendChild(main);
    const right = el('div', 'bk-right');
    right.appendChild(chip(T('setup.gone'), 'warn'));
    row.appendChild(right);
    list.appendChild(row);
  });
  s.appendChild(list);
  plan.missing_databases.filter((md) => !fitting.on || setupFits(fitting.flt, md.stack, false)).forEach((md) => s.appendChild(el('p', 'callout warn', T('setup.db_missing', { stack: md.stack, service: md.service, type: md.type }))));
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
 * Where the backup place's copies go besides this server, as the DRAFT says (ticking «local + offsite» or a partner for its
 * share changes it at once): 'kopia' (its share to Kopia, Kopia on), a partner's name (general|partner_place, a share
 * that can travel), or null - only local
 */
function placeOffsite(sh) {
  if (!sh || !setup.draft) return null;
  if (dget('kopia|enabled') === 'yes' && dget(`share|${sh.name}|mode`, 'off') === 'kopia') return 'kopia';
  const ids = (sh.partner_ok && dget(`share|${sh.name}|mode`, 'off') !== 'off' && dget('general|partner_place', [])) || [];
  if (!ids.length) return null;
  const p = setupPartners().find((x) => x.id === ids[0]);
  return { partner: (p && p.name) || ids[0] };
}

/**
 * What speaks against the share chosen as the backup place (the agent's backupPlaceFacts(): same pool as appdata,
 * no snapshots, a secondary storage, no redundancy) - ONE question decides (owner 2026-10-17): does a copy of the
 * packages go off-site? Yes - the risks are a grey line «just so you know»; no - one yellow line that adds up the
 * reasons that apply and what helps (none: a short «only local»). A secondary storage stays a warning either way (the
 * mover moves the packages - a practical problem). Never red, never blocked: the choice stays the user's.
 * Pure but for the draft: sh the plan's share row, offsite placeOffsite()'s answer. → [{warn, text}]
 */
function placeVerdict(sh, offsite) {
  if (!sh) return [];
  const facts = sh.place || (sh.method === 'live' ? [{ code: 'no_history', where: '' }] : []);
  const out = [];
  const reasons = [];
  let risk = '';
  facts.forEach((p) => {
    if (p.code === 'secondary') out.push({ warn: true, text: T('setup.place_secondary', { where: p.where || T('setup.place_array') }) });
    else if (p.code === 'same_pool') { reasons.push(T('setup.place_why.same_pool', { where: p.where })); risk = 'same_pool'; }
    else if (p.code === 'no_redundancy') {
      reasons.push(p.where ? T('setup.place_why.no_redundancy', { where: p.where }) : T('setup.place_why.no_parity'));
      risk = risk || 'no_redundancy';
    } else if (p.code === 'no_history') reasons.push(p.where ? T('setup.place_why.no_history', { where: p.where }) : T('setup.place_why.no_snapshots'));
  });
  if (offsite) {
    if (reasons.length) {
      const head = offsite === 'kopia' ? T('setup.place_info_kopia') : T('setup.place_info_partner', { partner: offsite.partner });
      out.unshift({ warn: false, text: `${head} ${T('setup.place_info_reasons', { reasons: reasons.join(', ') })}` });
    }
  } else if (reasons.length) {
    out.unshift({ warn: true, text: [T('setup.place_only_here', { reasons: reasons.join(', ') }), risk ? T('setup.place_risk.' + risk) : '', T('setup.place_help')].filter(Boolean).join(' ') });
  } else {
    out.unshift({ warn: true, text: T('setup.place_local_only') });
  }
  return out;
}
/** The chosen backup place's lines as the draft has it now */
const placeLines = (sh) => placeVerdict(sh, placeOffsite(sh));
/** The plan's row of the backup place the draft chose (or null) */
const placeRow = () => (setup.plan && setup.plan.shares.find((x) => x.name === dget('general|dumps_share', ''))) || null;

/**
 * The engine's two words about the place (place_not_kopia, place_no_history - of the settings saved) give way to the
 * place's lines as the draft has them (placeVerdict()), at the place of the first: said once, the same as on the card
 */
const PLACE_CODES = ['place_not_kopia', 'place_no_history'];
function placeNotes(notes) {
  const at = notes.findIndex((g) => PLACE_CODES.includes(g.code));
  if (at < 0) return notes;
  const step = notes[at].step;
  const lines = placeLines(placeRow()).map((l) => ({ level: l.warn ? 'warn' : 'hint', step, code: 'place_verdict', items: [{ text: l.text }] }));
  const rest = notes.filter((g) => !PLACE_CODES.includes(g.code));
  rest.splice(Math.min(at, rest.length), 0, ...lines);
  return rest;
}

/** Mr. Backupsy's word above the guide while no backup place is chosen: none to choose (a fresh server: only isos), or choose one */
function placeIntro(plan, ds) {
  if (ds) return null;
  return placeShares(plan).some((n) => n.toLowerCase() !== 'isos') ? 'setup.place_choose' : 'setup.place_none';
}

/**
 * What matters for the backup place (the five points) and the way to a new share in Unraid (Shares → Add
 * Share, same tab) - the office never creates it, the user decides where it lies; open while none is chosen
 */
function placeGuide(plan) {
  const ds = dget('general|dumps_share', '');
  const box = el('div', 'bk-place');
  const intro = placeIntro(plan, ds);
  if (intro === 'setup.place_none') box.appendChild(el('p', 'callout warn', T('setup.place_none')));
  else if (intro) box.appendChild(el('p', 'callout warn', T('setup.place_choose')));
  const det = keepFold(el('details', 'bk-place-guide'), 'place_guide', !ds);
  det.appendChild(el('summary', '', T('setup.place_title')));
  const ol = el('ol');
  [T('setup.place_1'), T('setup.place_2'), T('setup.place_3'), T('setup.place_4'), T('setup.place_5')].forEach((t) => ol.appendChild(el('li', '', t)));
  det.appendChild(ol);
  if (!ds) {                      // the way to a new share only while none is chosen - with one chosen it's just the five points
    const add = el('p', 'bk-place-add');
    const a = el('a', 'btn small', T('setup.place_add'));
    a.href = Office.safeHref('/Shares/Share?name=');
    add.append(a, ' ', T('setup.place_name', { name: OFFICE_SHARE }));
    det.appendChild(add);
    det.appendChild(el('small', '', T('setup.place_back')));
  }
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
  placeLines(plan.shares.find((x) => x.name === sel.value)).forEach((l) => f.appendChild(el('small', l.warn ? 'bk-place-warn' : 'bk-place-info', l.text)));
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
    // a snapshot needs the disk awake: with every disk, those that sleep at night are woken for it (2026-10-07)
    const disks = plan.bases.filter((b) => b.fs === 'btrfs' && b.kind === 'disk').map((b) => b.name);
    box.appendChild(Office.place('setup.g_btrfs_all', checkbox(T('setup.g_btrfs_all'), dget('btrfs|snapshot_all') === 'yes', (v) => dset('btrfs|snapshot_all', v ? 'yes' : 'no'),
      disks.length ? T('setup.g_btrfs_all_wake', { n: disks.length, names: disks.join(', ') }) : '')));
  }
  if (plan.P['libvirt|mode'] !== undefined) {
    box.appendChild(Office.place('setup.g_libvirt', field(T('setup.g_libvirt'), selectInput('libvirt|mode', ['tar', 'off'], (o) => T('setup.libvirt.' + o)), T('setup.g_libvirt_hint'))));
  }
  // since 1.68 the Team Lead's notification centre decides whether a successful backup is told (his «Reports» tile);
  // general|notify_success stays in settings.ini as it is (an older office reads it)
  const toCentre = el('a', 'btn small plain', Office.t('notifycenter.where_link'));
  toCentre.href = '#/caretaker/notify';
  const centre = el('div', 'field');
  const centreLink = el('div');
  centreLink.appendChild(toCentre);
  centre.append(el('div', 'field-title', T('setup.g_notify')), el('small', '', Office.t('notifycenter.where')), centreLink);
  box.appendChild(Office.place('setup.g_notify', centre));
  if (dget('kopia|enabled') === 'yes') {
    box.appendChild(Office.place('setup.p_compression', field(T('setup.p_compression'), textInput('kopia|compression', /^[a-z0-9-]+$/), T('setup.p_compression_hint'))));
    box.appendChild(Office.place('setup.p_ignore', field(T('setup.p_ignore'), listInput('kopia|ignore', 4), T('setup.p_ignore_hint'))));
  }
  s.appendChild(box);
  const dl = declinedList();          // 1.60.1: what the user declined, with «Propose again»
  if (dl) s.appendChild(dl);
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
  const row = (kind, name, offers, isNew) => {
    const pre = `${kind}|${name}|`;
    const folders = dget(pre + 'folder', []) || [];
    if (!fitRow([name, ...folders.map((f) => f.split('/').slice(1).join('/'))], isNew)) return;
    const r = el('div', 'row nocheck bk-item');
    r.dataset.focus = `${kind}:${name}`;
    const main = el('div', 'row-main');
    const nameEl = el('div', 'row-name', name);
    main.appendChild(nameEl);
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('setup.item_kind.' + kind)));
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
    // the whole row unfolds to what Kopia leaves out («otherwise you can't see the Kopia excludes»)
    const toggle = () => setupToggleOpen(pre, r);
    const more = button(open ? T('setup.less') : T('setup.more'), 'small plain', toggle);
    right.appendChild(more);
    r.appendChild(right);
    r.dataset.unfold = pre;
    setupUnfold(r, nameEl, more, open, toggle);
    list.appendChild(r);
    if (open) {
      const d = el('div', 'row nocheck bk-dep');
      const box = el('div', 'bk-form');
      box.appendChild(field(T('setup.item_ignore'), listInput(pre + 'kopia_ignore', 3), T('setup.item_ignore_hint', { example: `/${(folders[0] || 'appdata/' + name)}/cache/` })));
      d.appendChild(box);
      list.appendChild(d);
    }
  };
  apps.forEach((a) => row('app', a.name, a.members.flatMap((n) => ((plan.containers.find((c) => c.name === n) || {}).offers || [])), appIsNew(a)));
  vms.forEach((x) => row('vm', x.name, [], x.isNew));
  wrap.appendChild(list);
  return wrap;
}

/** Kopia's start time (nanoseconds) as the page's date, or «–» */
function kopiaWhen(t) {
  const at = Date.parse(String(t || '').replace(/(\.\d{3})\d+/, '$1'));      // Kopia's nanoseconds: milliseconds for every browser
  return isNaN(at) ? '–' : fmt.date(Math.round(at / 1000));
}
const OLD_KINDS = ['share', 'app', 'vm', 'flash'];
/** «App X», «VM Y», «Flash» — an old source's unit as the engine named it (kind, name), '' when it can't be said */
const oldUnit = (o) => (OLD_KINDS.includes(o.kind) && typeof o.name === 'string' && o.name ? T('setup.orphans.kind_' + o.kind, { name: o.name }) : '');
/**
 * What an old Kopia source is, in words (engine 2.59: the plan's kind, name, why, snapshots, newest): its unit gone, there
 * but no longer offsite (only older snapshots left), the flash as an archive in the backup place now, or not the office's
 * (another path, an old script's). An older engine's entry without why: the source as it is.
 */
function oldSourceWords(o) {
  if (o.state === 'gone') return T('setup.sources.row_other', { what: o.source });
  if (o.state !== 'orphan' || typeof o.why !== 'string') return o.source;
  const what = oldUnit(o);
  if (!what || o.why === 'other') return T('setup.sources.row_other', { what: o.source });
  if (o.why === 'gone') return T('setup.sources.row_gone', { what });
  const n = Number.isInteger(o.snapshots) ? o.snapshots : 0;
  const older = n > 0 ? T('setup.sources.older', { snapshots: T('setup.orphans.snapshots', { n }), when: kopiaWhen(o.newest) }) : T('setup.sources.older_bare');
  if (o.kind === 'flash' && dget('flash|mode') === 'tar') return T('setup.sources.row_flash_tar', { older });
  return T(o.why === 'not_offsite' ? 'setup.sources.row_not_offsite' : 'setup.sources.row_unknown', { what, older });
}

function setupSources(old) {
  const s = Office.place('setup.sources', setupSection(T('setup.sources'), T('setup.sources_sub')));
  const ul = el('ul', 'shortlist bk-oldsrc');         // words, not paths: the page's own type (the path is each row's title)
  old.forEach((o) => {
    const words = oldSourceWords(o);
    if (!fitRow(`${words} ${o.source}`, false)) return;
    const li = el('li', '', words);
    li.title = o.source;
    ul.appendChild(li);
  });
  s.appendChild(ul);
  // a one-time order for the next Apply (setup.sh --apply _retire_sources), no setting of settings.ini: said so on the row
  // (2026-10-17, silent controls: unticked, a reload ticked it again without a word)
  const once = checkbox(T('setup.sources_manual'), setup.retire, (v) => { setup.retire = v; }, T('setup.sources_manual_hint'));
  once.lastChild.appendChild(el('small', 'bk-once', T('setup.sources_manual_once')));
  s.appendChild(once);
  // engine 2.51: the office's own sources under its mount root («orphan»; never «gone» - not the office's) can go in Kopia
  if (old.some((o) => o.state === 'orphan') && dget('kopia|enabled') === 'yes') {
    const b = button(T('setup.orphans.button'), 'small', orphansDialog);
    b.title = T('setup.orphans.button_hint');
    b.disabled = !canAct() || !!(setup.status && setup.status.running);
    const p = el('p', 'bk-orphans-open');
    p.appendChild(b);
    s.appendChild(p);
  }
  return s;
}

// ---- old Kopia sources (engine 2.51): the office's own sources of shares, apps and VMs that are gone — and (2.59) of
// units still there that no longer go offsite (the flash too). The engine lists them (setup.sh --orphans: snapshots, the
// newest one's size and date, why), the user ticks, it lists anew and deletes for good only what it offers then
// (--orphans-delete). Never another identity's, a path of the user's own, a unit at a Kopia level.
let orphansTurn = 0;               // the dialog's turn: a closed or newer one stops the older one's polling
const ORPHANS_POLL = 1000;
const orphansTitle = () => T('setup.orphans.title');
/** The engine's word for why not (`busy_<holder>`: one text) */
function orphansCode(code) {
  const c = /^busy_/.test(code || '') ? 'busy' : String(code || '');
  return c && Office.has(`${ID}.setup.orphans.code.${c}`) ? T('setup.orphans.code.' + c) : T('setup.orphans.code.other');
}
/** The wave and what runs, in the dialog's body */
function orphansWaitBox(box, key) {
  const p = el('p', 'role');
  p.append(el('span', 'spin'), ' ', T(key));
  box.replaceChildren(p);
}
/** Until the engine's list (or deletion) that started at `started` has ended — null when the dialog went or it took too long */
async function orphansWait(turn, started) {
  for (let i = 0; i < 900 && turn === orphansTurn; i++) {
    const j = await Office.api.post(`${ID}.orphans_get`, {});
    if (!j.ok) return { error: j.error };
    const o = j.orphans;
    if (o && o.started >= started && !o.running) return { o };
    await new Promise((r) => setTimeout(r, ORPHANS_POLL));
  }
  return null;
}
/** One answer of the engine as a line of the dialog, or what went wrong */
async function orphansAfter(turn, j, box) {
  if (!j.ok) { box.replaceChildren(el('p', 'callout warn', Office.errorText(j.error, ID))); return null; }
  const got = j.started && j.orphans ? await orphansWait(turn, j.orphans.started) : null;
  if (turn !== orphansTurn) return null;
  if (!got || got.error) { box.replaceChildren(el('p', 'callout warn', got && got.error ? Office.errorText(got.error, ID) : T('setup.orphans.code.other'))); return null; }
  return got.o;
}

/** «Delete in Kopia…»: the engine looks, then the list to tick */
async function orphansDialog() {
  const turn = ++orphansTurn;
  const box = el('div', 'bk-orphans');
  orphansWaitBox(box, 'setup.orphans.looking');
  Office.dialog({ title: orphansTitle(), body: box, buttons: [{ text: Office.t('common.cancel') }],
    onClose: () => { if (turn === orphansTurn) orphansTurn++; } });
  const o = await orphansAfter(turn, await Office.api.post(`${ID}.orphans_look`, {}), box);
  if (!o || turn !== orphansTurn) return;
  if (o.result !== 'ok') { box.replaceChildren(el('p', 'callout warn', orphansCode(o.code))); return; }
  orphansPick(o);
}

/** The office's old sources with their snapshots, each with a tick (none ticked), in two sections — gone, no longer
 *  offsite (its own warning: the thing stays, no copy offsite afterwards) — and what deleting means */
function orphansPick(o) {
  const turn = ++orphansTurn;
  const box = el('div', 'bk-orphans');
  if (!o.sources.length) {
    box.appendChild(el('p', '', T('setup.orphans.none')));
    Office.dialog({ title: orphansTitle(), body: box, buttons: [{ text: Office.t('common.close') }] });
    return;
  }
  box.appendChild(el('p', '', T('setup.orphans.intro')));
  const ticked = new Set();
  let d = null;
  const sync = () => {
    if (!d) return;
    d.buttons[1].textContent = T('setup.orphans.go', { n: ticked.size });
    d.buttons[1].disabled = !ticked.size || !Office.agent.running;
  };
  const part = (why, head, warn) => {
    const xs = o.sources.filter((x) => (x.why || 'gone') === why);
    if (!xs.length) return;
    box.appendChild(el('p', 'bk-orphans-head', T(head)));
    if (warn) box.appendChild(el('p', 'callout warn', T(warn)));
    const list = el('div', 'bk-orphans-list');
    xs.forEach((x) => {
      const line = T('setup.orphans.line', { snapshots: T('setup.orphans.snapshots', { n: x.snapshots }), when: kopiaWhen(x.newest), size: fmt.size(x.bytes) });
      const label = checkbox(oldUnit(x) || x.source, false,
        (v) => { if (v) ticked.add(x.source); else ticked.delete(x.source); sync(); }, line);
      label.title = x.source;
      list.appendChild(label);
    });
    box.appendChild(list);
  };
  part('gone', 'setup.orphans.gone_head', '');
  part('not_offsite', 'setup.orphans.offsite_head', 'setup.orphans.offsite_warn');
  box.appendChild(el('p', 'callout warn', T('setup.orphans.warn')));
  d = Office.dialog({
    title: orphansTitle(),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('setup.orphans.go', { n: 0 }), kind: 'danger', act: () => {
        if (!ticked.size || turn !== orphansTurn) return false;
        orphansDelete(o, [...ticked]);
        return true;
      } },
    ],
  });
  sync();
  d.buttons[0].focus();           // safe default: Enter cancels
}

/** Deletes the ticked ones for good, then says what went, what failed, what was no longer offered */
async function orphansDelete(o, sources) {
  const turn = ++orphansTurn;
  const box = el('div', 'bk-orphans');
  orphansWaitBox(box, 'setup.orphans.deleting');
  Office.dialog({ title: orphansTitle(), body: box, buttons: [{ text: Office.t('common.close') }],
    onClose: () => { if (turn === orphansTurn) orphansTurn++; } });
  const j = await Office.api.post(`${ID}.orphans_delete`, { sources, listed: String(o.started) });
  if (!j.ok && j.error && j.error.key === 'orphans_stale') {
    Office.toast(Office.errorText(j.error, ID));
    if (turn === orphansTurn) orphansDialog();
    return;
  }
  const r = await orphansAfter(turn, j, box);
  if (!r) return;
  if (r.deleted.length && setup.plan && setup.plan.kopia && Array.isArray(setup.plan.kopia.sources)) {
    // the engine took them out of the plan too (setup-plan.json): the page's copy the same way
    setup.plan.kopia.sources = setup.plan.kopia.sources.filter((x) => !r.deleted.includes(x.source));
  }
  const out = [];
  if (r.deleted.length) out.push(el('p', 'callout', T('setup.orphans.deleted', { n: r.deleted.length })));
  if (r.failed.length) out.push(el('p', 'callout warn', T('setup.orphans.failed', { n: r.failed.length })));
  if (r.refused.length) out.push(el('p', 'role', T('setup.orphans.refused', { n: r.refused.length })));
  if (!out.length) out.push(el('p', 'callout warn', orphansCode(r.code)));
  box.replaceChildren(...out);
  if (r.deleted.length) Office.toast(T('setup.orphans.deleted', { n: r.deleted.length }));
  if (page === 'setup' && setup.plan) renderSetup();
}

/** A setup message's level as a class of the desk's own: office.css styles `.hint` (one line, cut) — never the level bare */
const msgClass = (m) => 'bk-msg-' + String(m.level || 'info').replace(/[^a-z]/g, '');

/**
 * The setup's messages grouped (setup_get's `notes` beside `messages`, backupSetupNotes(): one entry per level and code
 * with an item per message; engine 2.39 codes every hint and warning); an answer without them: one entry per message
 */
const setupNotes = (o) => (o && Array.isArray(o.notes) ? o.notes
  : ((o && o.messages) || []).map((m) => ({ level: m.level, step: m.step, code: m.code, items: [{ text: m.text, params: m.params }] })));

/**
 * A coded message's params for T(): a count as a number (plurals), a partner's reason (`pwhy`) in the office's words,
 * the server named as the Team Lead's menu does (`host`, engine 2.34)
 */
function setupMsgParams(params) {
  const p = { ...(params && typeof params === 'object' && !Array.isArray(params) ? params : {}) };
  Object.keys(p).forEach((k) => { if (typeof p[k] === 'string' && /^\d{1,9}$/.test(p[k])) p[k] = Number(p[k]); });
  if (p.pwhy !== undefined) p.pwhy = Office.has(`${ID}.partner.why_short.${p.pwhy}`) ? T('partner.why_short.' + p.pwhy) : String(p.pwhy);
  p.host = Office.config.host || p.host || '';
  return p;
}

/**
 * The list items of one grouped setup message. A code the office knows: `setup.msg.<code>` per item; several items
 * under `setup.msg.<code>_group` ({n}) as one entry listing them (`<code>_item` each, else the full line) when the
 * group has such a heading, else a line each; `<code>_why` (what it means, what to do) once under the last line. A code
 * it doesn't know, or none: the engine's English text with its second lines — never lost.
 */
function setupNoteItems(g, withStep) {
  const head = (li) => { if (withStep) li.appendChild(el('span', 'bk-step', T('setup.step.' + (g.step || 'other')))); return li; };
  const items = Array.isArray(g.items) ? g.items : [];
  const key = 'setup.msg.' + g.code;
  if (!g.code || !Office.has(`${ID}.${key}`)) {
    return items.map((it) => {
      const li = head(el('li', msgClass(g)));
      li.appendChild(el('div', 'bk-msg-body', [it.text, ...(it.more || [])].filter(Boolean).join(' ')));
      return li;
    });
  }
  const lis = [];
  if (items.length > 1 && Office.has(`${ID}.${key}_group`)) {
    const li = head(el('li', msgClass(g)));
    const body = li.appendChild(el('div', 'bk-msg-body', T(key + '_group', { ...setupMsgParams(items[0].params), n: items.length })));
    const ul = body.appendChild(el('ul', 'bk-msg-items'));
    const itemKey = Office.has(`${ID}.${key}_item`) ? key + '_item' : key;
    items.forEach((it) => ul.appendChild(el('li', '', T(itemKey, setupMsgParams(it.params)))));
    lis.push(li);
  } else {
    items.forEach((it) => {
      const li = head(el('li', msgClass(g)));
      li.appendChild(el('div', 'bk-msg-body', T(key, setupMsgParams(it.params))));
      lis.push(li);
    });
  }
  if (lis.length && Office.has(`${ID}.${key}_why`)) {
    lis[lis.length - 1].lastChild.appendChild(el('div', 'bk-msg-why', T(key + '_why', setupMsgParams(items[0].params))));
  }
  return lis;
}

/** The plan failed before it could write one (engine messages, English): what went wrong and that «Neu planen» tries again */
function setupFailedCallout(st) {
  const p = el('p', 'callout warn', T('setup.bubble_failed', { n: Number(st.errors || 0) }));
  const errs = (st.messages || []).filter((m) => m && m.level === 'error' && m.text);
  if (errs.length) {
    const ul = el('ul', 'bk-msgs');
    // engine 2.54: an error with a code the office knows in its own words, else the engine's English text
    errs.forEach((m) => ul.appendChild(el('li', '', m.code && Office.has(`${ID}.setup.msg.${m.code}`) ? T('setup.msg.' + m.code, setupMsgParams(m.params)) : m.text)));
    p.appendChild(ul);
  }
  return p;
}

function setupMessages(notes) {
  const s = section(T('setup.messages'), T('setup.messages_sub'), { place: 'setup.messages' });
  const counts = { all: 0, error: 0, warn: 0 };
  notes.forEach((g) => {
    const n = (g.items || []).length;
    counts.all += n;
    if (counts[g.level] !== undefined) counts[g.level] += n;
  });
  const det = keepFold(el('details', 'bk-log'), 'messages', !!(counts.error || counts.warn));
  det.appendChild(el('summary', '', T('setup.messages_sum', { messages: nOf('messages', counts.all), errors: nOf('errors', counts.error), warnings: nOf('warnings', counts.warn) })));
  const ul = el('ul', 'bk-msgs');
  notes.forEach((g) => setupNoteItems(g, true).forEach((li) => ul.appendChild(li)));
  det.appendChild(ul);
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
  const cronField = el('div');
  const examples = el('span', 'chip', T('schedule.examples'));
  examples.dataset.tip = T('schedule.examples_tip');
  cronField.append(cron, examples);
  option('custom', T('schedule.custom'), T('schedule.custom_hint'), cronField);
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

// ------------------------------------------------------------------ let go
/**
 * His part of the let-go dialog (core.js Office.fireDialog → the desk's letGo): one tick «Also clear away what he kept
 * here», off by default. Ticked, he looks first (backup.letgo_look: which packages go to Ms. Dustdevil's storeroom, how
 * many of his own snapshots go and what that frees, what stays) and «Let go» becomes «Let go and clear away» — only
 * once that look is shown, never while the engine is busy. The clearing runs before he is let go, as a job of the
 * host's atd (backup.letgo_clear {confirm: true} starts it; the dialog follows its job file, api part «letgo-job», and
 * says how far it got); he is let go when it ends, whatever came of it, and then a dialog says what was done.
 */
const LETGO_POLL = 2000;            // ms between looks at the job file
const LETGO_QUIET = 60000;          // ms without a new heartbeat: the agent is asked whether the job still lives
const LETGO_ENDED = ['done', 'refused', 'failed', 'interrupted'];
let letGoClock = { wait: (ms) => new Promise((r) => setTimeout(r, ms)), now: () => Date.now() };     // the tests' own
function letGoPart(box) {
  const wrap = el('div', 'bk-letgo');
  const label = el('label', 'check');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.disabled = !Office.agent.running;
  const text = el('span', '', T('letgo.tick'));
  text.appendChild(el('small', '', T('letgo.tick_hint')));
  label.append(cb, text);
  const more = el('div', 'bk-letgo-more');
  more.hidden = true;
  wrap.append(label, more);
  box.appendChild(wrap);
  let look = null;
  let button = null;
  let asked = 0;
  let cleared = null;
  const sync = () => {
    if (!button) return;
    button.textContent = cb.checked ? T('letgo.button') : Office.t('office.fire');
    button.disabled = cb.checked && !(look && !look.busy);      // the look first: he clears only what he showed
  };
  cb.onchange = async () => {
    const mine = ++asked;
    look = null;
    more.hidden = !cb.checked;
    sync();
    if (!cb.checked) return;
    more.replaceChildren(el('p', 'role', T('letgo.looking')));
    const j = await Office.api.post(`${ID}.letgo_look`, {});
    if (mine !== asked || !cb.checked) return;
    more.replaceChildren(...letGoLines(j).map(([cls, line]) => el('p', cls, line)));
    if (j.ok && !j.busy) look = j;
    else { cb.checked = false; cb.disabled = !!(j.ok && j.busy); }    // busy: said, the tick waits for another time
    sync();
  };
  const progress = (v) => more.replaceChildren(el('p', 'callout running', T('letgo.clearing', { moved: Number(v.moved) || 0, deleted: Number(v.deleted) || 0 })));
  const clearAway = async () => {
    cb.disabled = true;
    progress({});
    const j = await Office.api.post(`${ID}.letgo_clear`, { confirm: true });
    return j.ok ? letGoWait(j.id, j.job, progress) : j;
  };
  return {
    bind(b) { button = b; sync(); },
    async before() {
      if (!cb.checked || !look) return null;
      cleared = cleared || clearAway();      // once, even if «Let go» is pressed again
      return cleared;
    },
    done: letGoDone,
  };
}

/**
 * Follows the clearing's job until it ends: the job file every LETGO_POLL (slower while asks fail, Office.pollDelay); when
 * its heartbeat stood still for LETGO_QUIET the agent is asked whether the job still lives (backup.letgo_job — a gone one
 * comes back interrupted). The answer in the shape letGoDone() takes.
 */
async function letGoWait(id, job, show) {
  let fails = 0;
  let beat = null;
  let since = letGoClock.now();
  for (;;) {
    if (job && job.id === id) {
      if (LETGO_ENDED.includes(job.result)) return letGoResult(job);
      if (job.heartbeat !== beat) { beat = job.heartbeat; since = letGoClock.now(); }
      show(job);
    }
    await letGoClock.wait(Office.pollDelay(LETGO_POLL, fails));
    let j = await Office.api.get({ a: 'part', desk: ID, part: 'letgo-job' });
    fails = j.ok ? 0 : fails + 1;
    if (j.ok && j.part && j.part.id === id && !LETGO_ENDED.includes(j.part.result) && j.part.heartbeat === beat
        && letGoClock.now() - since >= LETGO_QUIET) {
      since = letGoClock.now();
      const a = await Office.api.post(`${ID}.letgo_job`, { id });
      if (a.ok && a.job) j = { ok: true, part: a.job };
    }
    if (j.ok && j.part) job = j.part;
  }
}

/** The ended job as letGoDone() takes it: done — what was done; refused — why; failed or interrupted — what was done until then */
function letGoResult(job) {
  if (job.result === 'refused') return { ok: false, error: { key: job.reason || 'internal', params: job.reason_params || {} } };
  return { ok: true, moved: job.moved, deleted: job.deleted, failed: job.failed || [], failed_n: job.failed_n, kept: job.kept, place_asleep: job.place_asleep,
    journal: job.journal, broken: job.result !== 'done' ? job.result : null };
}

/** What the look says, line by line ([class, text]) */
function letGoLines(j) {
  if (!j.ok) return [['callout warn', Office.errorText(j.error, ID)]];
  if (j.busy) return [['callout warn', T('letgo.busy', { why: Office.errorText({ key: j.busy }, ID) })]];
  const out = [];
  const pk = j.packages || {};
  const names = pk.names || [];
  if (pk.asleep) out.push(['', T('letgo.packages_asleep')]);
  else if (pk.n) out.push(['', T('letgo.packages', { n: Number(pk.n), names: names.join(', ') + (pk.n > names.length ? ', …' : '') })]);
  else out.push(['', T('letgo.packages_none')]);
  const sn = j.snapshots || {};
  if (sn.n) {
    out.push(['', T('letgo.snaps', { n: Number(sn.n), size: fmt.size(sn.bytes || 0) })]);
    if (sn.unknown) out.push(['role', T('letgo.snaps_unknown', { n: Number(sn.unknown) })]);
  } else out.push(['', T('letgo.snaps_none')]);
  if (sn.held || sn.asleep) out.push(['role', T('letgo.snaps_kept', { n: (sn.held || 0) + (sn.asleep || 0) })]);
  out.push(['role', T('letgo.not_others')], ['role', T('letgo.stays')], ['callout', T('letgo.remote')]);
  if (j.schedule) out.push(['callout warn', T('letgo.schedule', { cron: j.schedule })]);
  return out;
}

/** After he was let go: what the clearing did (or why it didn't) */
function letGoDone(j) {
  const box = el('div');
  letGoDoneLines(j).forEach(([cls, line]) => box.appendChild(el('p', cls, line)));
  if (j.ok && (j.failed || []).length) {
    const ul = el('ul', 'shortlist');
    j.failed.forEach((f) => ul.appendChild(el('li', 'error', `${f.what}: ${Office.errorText({ key: f.key, params: f.params || {} }, f.desk || ID)}`)));
    box.appendChild(ul);
  }
  Office.dialog({ title: T('letgo.done_title'), body: box });
}

function letGoDoneLines(j) {
  if (!j.ok) return [['callout warn', T('letgo.failed', { error: Office.errorText(j.error, ID) })]];
  const out = [];
  if (j.moved) out.push(['', T('letgo.done_moved', { n: Number(j.moved) })]);
  if (j.deleted) out.push(['', T('letgo.done_deleted', { n: Number(j.deleted) })]);
  if (j.broken) out.push(['callout warn', T('letgo.done_broken')]);
  if (!j.moved && !j.deleted && !j.failed_n && !j.broken) out.push(['', T('letgo.done_none')]);
  if (j.place_asleep) out.push(['role', T('letgo.packages_asleep')]);
  if (j.failed_n) out.push(['callout warn', T('letgo.done_failed', { n: Number(j.failed_n) })]);
  out.push(['role', T('letgo.done_journal', { file: j.journal })]);
  return out;
}

Office.desk({
  id: ID,
  runs: (s) => !!(s.running || (s.setup && s.setup.running)),       // core.js: an old kept state saying so isn't shown
  async mount(root, sub) {
    view = root;
    clearTimeout(setupTimer);
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : 'main';     // #/backup and every tile's route: the overview
    if (page === 'setup') {
      sfBar = null;                        // its bar built anew in the language of now
      setupFilterReset();                  // a new visit: every row again (words and «only …» are not kept)
      // #/backup/setup/<tile> (the search, a step's place) picks that step exactly like a click, the address keeps its page;
      // else the step chosen last (backup.setup.tile), none = every step
      // (first place: a visit without a step's route opens on «Grundlagen» while no backup place is chosen — setupPlaceStart())
      setup.placeStart = false;
      if (setupSwitcher().mount(String(sub || '').split('/').slice(1).join('/'))) Office.subroute('setup');
      else setup.placeStart = true;
      renderSetup();
      if (!state) await load(false);
      await setupLoad();
      // an old plan doesn't know what changed since (a share made for the backup place, moved ones): read the server again
      if (setup.plan && Date.now() / 1000 - setup.plan.time > SETUP_REPLAN && canPlan() && !(setup.status && setup.status.running)) {
        setupPlan(false, true);
      }
    } else {
      Office.selbar(null);
      // a new visit: the table and the history unfiltered (words, «only …» and a filter tile are never kept), the bars
      // built anew in the language of now; #/backup/<tile> (the search, its rows, the team lead's «Open» of the schedule)
      // picks that tile exactly like a click and the address goes back to #/backup — else the last section chosen
      protBar = null;
      histBar = null;
      switcher().mount(sub);
      render();
      await load(false);
    }
  },
  unmount() {
    view = null;
    clearInterval(minuteTimer);
    clearTimeout(liveTimer);
    clearTimeout(setupTimer);
  },
  letGo: letGoPart,
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
      const go = ready;                            // a tour or a dry run never waits for the minute (1.60.1)
      items.push({ text: T('mode.check'), act: go ? () => startRun('check') : null });
      items.push({ text: T('mode.dryrun'), act: go ? () => startRun('dryrun') : null });
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
      facts.push(T('fact.shares', { kopia: nOf('shares', shares.filter((s) => s.mode === 'kopia').length), local: shares.filter((s) => s.mode === 'snapshot').length, off }));
      if (state.version) facts.push(T('fact.version', { version: state.version }));
    }
    return { bubble: bubbleText().join(' '), facts };
  },
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys): the main
// page, then the setup's steps and fixed settings (#/backup/setup) — the rows per share, app and VM are a later phase
const SETUP = { route: '#/backup/setup', crumb: 'setup.page' };
// what his pages draw only in some states (core.js shown, asked when the search looks; not known yet: listed): the main
// page's sections once the engine is found (a run going on: «now» instead of the row; drift and partners only with
// something drifted, a partner), the tiles the row has now, the setup's start cards for a new server, the old Kopia
// sources, the settings its plan has (ZFS, btrfs, libvirt, Kopia switched on)
const mainShown = (f) => () => !state || (!!state.found && f());
const planShown = (f) => () => !setup.plan || !setup.draft || !!f(setup.plan);
const hasFs = (fs) => (p) => (p.bases || []).some((b) => b.fs === fs);
const kopiaOn = () => dget('kopia|enabled') === 'yes';
const SHOWN = {
  now: mainShown(() => live()), overview: mainShown(() => !live()), protection: mainShown(() => true), history: mainShown(() => true),
  drift: mainShown(() => ((state.drift && state.drift.items) || []).length > 0), restore: mainShown(() => true),
  'schedule.title': mainShown(() => true), 'partner.section': mainShown(() => (state.partners || []).length > 0),
  'setup.preset.title': planShown((p) => !p.have_settings),
  'setup.sources': planShown((p) => ((p.kopia && p.kopia.sources) || []).some((x) => x.state === 'orphan' || x.state === 'gone')),
  'setup.g_prefix': planShown(hasFs('zfs')), 'setup.g_zfs': planShown(hasFs('zfs')),
  'setup.g_btrfs_free': planShown(hasFs('btrfs')), 'setup.g_btrfs_all': planShown(hasFs('btrfs')), 'setup.g_btrfs_days': planShown(hasFs('btrfs')),
  'setup.g_libvirt': planShown((p) => !!p.P && p.P['libvirt|mode'] !== undefined),
  'setup.p_compression': planShown(kopiaOn), 'setup.p_ignore': planShown(kopiaOn),
};
const PART = { 'setup.ds_label': 'setup.kopia', 'setup.g_flash': 'setup.kopia', 'setup.g_zfs': 'setup.retention', 'setup.g_btrfs_days': 'setup.retention' };
const when = (key) => ({ ...(SHOWN[key] ? { shown: SHOWN[key] } : {}), ...(PART[key] || key.startsWith('setup.g_') || key.startsWith('setup.p_')
  ? { part: PART[key] || 'setup.general' } : {}) });
// a section's route is its tile's: the switcher shows it alone, whatever was chosen before (the row and «now» are always there)
const SECTION_TILE = { protection: 'prot', history: 'last', 'schedule.title': 'schedule', 'partner.section': 'partners', drift: 'drift', restore: 'restore' };
Office.places(ID, [
  ...['now', 'overview', 'protection', 'history', 'schedule.title', 'partner.section', 'drift', 'restore']
    .map((key) => ({ kind: 'section', key, ...(SECTION_TILE[key] ? { route: tileRoute(SECTION_TILE[key]) } : {}), ...when(key) })),
  // every tile of the row (the switcher gives each its place tile.<id>, #/backup/<tile> picks it like a click — mount()), listed
  // while the row has it (a run going on: no row; partners, drift: only when there are)
  ...TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `tile.${id}`, route: tileRoute(id),
    shown: mainShown(() => !live() && overviewTiles().some((d) => d.key === id)) })),
  // the setup's steps and settings by the route of their step's tile (a step stands under the row only while its tile is
  // chosen — or none is); the start cards over the row by the page's
  ...['setup.preset.title', 'setup.kopia', 'setup.vms', 'setup.apps', 'setup.shares', 'setup.retention', 'setup.general', 'setup.sources',
    'setup.messages'].map((key) => ({ kind: 'step', key, ...SETUP, ...(STEP_TILE[key] ? { route: setupRoute(STEP_TILE[key]) } : {}), ...when(key) })),
  ...['setup.ds_label', 'setup.g_flash', 'setup.g_prefix', 'setup.g_btrfs_free', 'setup.g_btrfs_all', 'setup.g_libvirt', 'setup.g_notify',
    'setup.p_compression', 'setup.p_ignore', 'setup.g_zfs', 'setup.g_btrfs_days']
    .map((key) => ({ kind: 'setting', key, ...SETUP, ...when(key), route: setupRoute(STEP_TILE[PART[key] || 'setup.general']) })),
  // the steps' tiles (the switcher gives each its place setup.tile.<id>, #/backup/setup/<tile> picks it like a click — mount());
  // their words are the steps' own, so the search keeps the step (above) as the one result
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `setup.tile.${id}`, ...SETUP, route: setupRoute(id), ...when(key) })),
  ...['run', 'results', 'tiles', 'protection', 'rules', 'vms', 'packages', 'items', 'waiting', 'buttons'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['setup_open', 'help.setup'], ['history', 'help.history'], ['drift', 'help.drift'], ['restore', 'help.restore']]
    .map(([key, text]) => ({ kind: 'help', key, text })),
  ...[['setup.preset.title', 'help.preset_text'], ['help.never', 'help.never_text'], ['help.partners', 'help.partners_text'],
    ['help.draft', 'help.draft_text'], ['setup.replan', 'help.replan'], ['setup.measure', 'setup.measure_hint'],
    ['help.reasons', 'help.reasons_text'], ['setup.more', 'help.details'], ['help.new', 'help.new_text'], ['help.filter', 'help.filter_text'], ['setup.apply', 'help.apply'],
    ['setup.forget_short', 'help.forget']].map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

/**
 * The setup's rows for the office's search (2026-10-17: «immich», «Mediathek» should find the row to change): every share,
 * VM and app of the plan the page loaded (setupLoad() hands it in, Office.placesTook(ID, plan, 'setup')) - never a request
 * of its own, never per keystroke. Each leads to its step's tile and its row (setup:share|vm|app:…), which the search
 * scrolls to and marks; an app is found by its containers' names too
 */
function setupSearchItems(plan) {
  if (!plan || !Array.isArray(plan.shares)) return [];
  const m = setup.plan === plan && setup.model ? setup.model : setupModel(plan);
  const sub = (step) => `${T('setup.page')} · ${T(step).replace(/^\d+\s*·\s*/, '')}`;     // the step's name without its number («2 · »)
  const route = (step) => setupRoute(STEP_TILE[step]);
  return [
    ...plan.shares.filter((x) => x.exists).map((x) => ({ text: x.name, sub: sub('setup.shares'), anchor: `setup:share:${x.name}`, route: route('setup.shares') })),
    ...m.vms.map((v) => ({ text: v.name, sub: sub('setup.vms'), anchor: `setup:vm:${v.name}`, route: route('setup.vms') })),
    ...m.apps.map((a) => ({ text: a.name, sub: sub('setup.apps'), anchor: `setup:app:${a.id}`, route: route('setup.apps'), words: a.members.join(' ') })),
  ];
}

// what his state holds for the search (core.js «items»): the main page's rows — every share, VM and app with how it is
// protected (its row's «Change…» leads into the setup), the runs his history shows, the partner offices he sends to;
// the setup's own rows from its plan as the page loaded it (part «setup», setupSearchItems())
Office.placesFrom(ID, (s, part) => {
  if (part === 'setup') return setupSearchItems(s);
  const out = [];
  if (!s.found) return out;
  const kopiaOn = !!(s.settings && s.settings.kopia_enabled);
  const level = (mode) => (mode === 'kopia' ? (kopiaOn ? 'offsite' : 'local') : mode === 'snapshot' ? 'local' : 'none');
  // a row's way is the tile showing its section alone (the table whole, the backups, the partners) — like a click
  const prot = tileRoute('prot');
  (s.shares || []).forEach((x) => out.push({ text: x.flash ? T('flash') : x.name, sub: `${T('protection')} · ${Office.t('protect.' + level(x.mode))}`,
    anchor: x.flash ? 'row:flash' : `row:share:${x.name}`, route: prot }));
  (s.vms || []).forEach((v) => out.push({ text: v.name, sub: `${T('protection')} · ${T('vm.group')}`, anchor: `row:vm:${v.name}`, route: prot }));
  const pk = s.packages;
  if (pk && pk.found) (pk.apps || []).forEach((a) => out.push({ text: a.name, sub: `${T('protection')} · ${T('pk.group')}`, anchor: `row:app:${a.folder}`, route: prot }));
  // the runs as his history lists them: the newest 20, the skipped ones between them
  [...(s.history || []).slice(0, 20).map((r) => ({ t: r.started, r })), ...(s.skips || []).map((k) => ({ t: k.time }))]
    .sort((a, b) => b.t - a.t).slice(0, 20).filter((x) => x.r)
    .forEach(({ r }) => out.push({ text: fmt.date(r.started, true), sub: `${T('history')} · ${T('result.' + r.result)}`, anchor: `run:${r.started}`, route: tileRoute('last') }));
  (s.partners || []).forEach((p) => out.push({ text: p.name, sub: T('partner.tile'), anchor: `partner:${p.id}`, route: tileRoute('partners') }));
  return out;
});

// tests/run.php runs the setup assistant's logic under node (Unraid's own) - never set in a browser
if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.backup = {
    get setup() { return setup; },
    get sf() { const f = setupFilter().read(); return { text: f.words.join(' '), onlyNew: f.only }; },
    setupFilter,
    setupFits, setupFirstNew, setupFocus, setupTileData, setupTileList, setupNewCounts, setupSwitcher, SETUP_TILES, STEP_TILE,
    driftText, driftParams, messageText,
    overviewTiles, tileNode, tileList, protRows, protView, protTileCounts, historyRows, PROT_TILES, PROT_ID, TILES,
    setState: (s) => { state = s; },
    setupDraftFromPlan, setupNewLines, setupNewRows, setupChanges, setupSaved, waitingFolders, waitChoice, waitSet, levelOf, waitingText, appSharesText,
    placeLines, placeVerdict, placeOffsite, placeNotes, placeIntro, setupDraftKeep, setupDerive, dset, setupEdits,
    PRESETS, presetChoose, presetForget, presetKeep, presetChanged, presetKopiaState, firstUpload, presetKeptList, presetStartText,
    presetCardsOf, presetCardOf, presetApply, presetPartners, levelPick, modeSelect, placeLevelPick, partnerListOf, partnerListSet, setupMigrated, LEVEL_FIELDS,
    partnerNowText, partnerRateOf, runModeOf, progress, partnerUnits, partnerSectionRows, partnerApplyLines, setupChangeItems, listChangeText, knownText, diffMark, whyFold, folderOf, ownDataset, setupMakeDataset, appPartner, partnerShareLines, appDepGaps, depCovered, depTicked, depClick, setupInitLevels, setupDeclinedApply, declinedList, ownFoldersOf, setupModel, partnerApplyText, setupDecline, setupApplyChanges, minuteTaken, bubbleText, deskActions,
    presetKopiaMode, presetKopiaVm, draftMode, draftVm, setupNewItems, presetApplyNew, presetNow, presetChosen, presetNewMode, presetNewVm,
    letGoPart, letGoLines, letGoDoneLines, letGoResult, setLetGoClock: (c) => { letGoClock = c; }, setupUnfold, UNFOLD_OWN, setupDecisions, setupApplyChanges, changeLabel, valueText, valueChange, setupSplit, setupDecline,
    warnLines, bubbleText, setupBarSpec, setupNoPlace, setupPlaceStart, bubblePlace, setupSearchItems, brakeLine, brakeGaps, snapSeconds,
    oldSourceWords, oldUnit, orphansPick, goneCallout, unknownKey, droppedKey, engineKnows, goneShares, goneShareKey,
  };
}
})();
