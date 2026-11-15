/* Fanboy — the office's building services and his fans' biggest fan (docs/DEVELOPMENT.md «The desks»).
   Part 3, the page a person sets him up and lives with — two pages, each a row of tiles (core.js Office.sections()):
   · the overview: the server as a picture (fan-art.js FanArt.server(): the zones as pieces of a case, the fans turning
     with their air, the heat sources between them, the warmest framed, sleeping disks marked — fed by every look, the
     speeds gliding), the fans and outputs, the heat sources, the curves with «now» and what happened lately, the hints
     (why it's tricky, drivers, the report for the makers);
   · the setup (the wizard), each step a tile in the order of part 2's steps: detect → test each output upward and assign it (round 8: roles on its card)
     (the catalogue picker, name, place, «give back to») → measure → zones (his proposal, the curve to
     drag) → dry run → «Take over». Nothing changes for good before «Take over»; a step that writes (test, measure) runs
     only while this page keeps it alive (step_alive): a closed tab or a reload gives every output back after 45 s, and
     the page says so when it comes back. Any step can be opened alone later — a curve, a zone, a fan's model change
     without the whole wizard again.
   Warnings and the alarm stay plain; his bubbles may cheer. No internal name (outputs' file names, the chip's number this
   boot) outside the technical detail a row unfolds. His pictures come from fan-art.js beside this file (loaded once;
   it brings its own fan-art.css: only this page needs it, desk.css stays small for every office page).
   Hidden until the owner lets him out (desk.json "switch"): without the switch file the office has no such desk. */
(() => {
'use strict';

const ID = 'fanboy';
const T = Office.scope(ID);
const { el, fmt } = Office;
const LOOK_MS = 5000;           // a new look while a page of his is shown (the agent's look is a few file reads)
const LOOK_STEP_MS = 2000;      // … while a setup step runs: the table grows, the wheel under test speeds up
const ALIVE_MS = 15000;         // the step's lease (part 2: 45 s without a sign of life and the step gives back)
// the curve view's period (round 3, the owner): one choice for every zone, remembered per browser (a choice, not a
// filter); up to a day from the part `history` (a day of it), 3 days asked for on its own (history {hours: 72})
const PERIODS = [1, 3, 6, 12, 24, 72];
const PERIOD_DEFAULT = 6;
let period = (() => { const v = Number(Office.store('fanboy.period')); return PERIODS.includes(v) ? v : PERIOD_DEFAULT; })();
let history72 = null;           // the 3 days, when that period is chosen
const PIC_PX = 84;              // the server picture's wheels (fan-art's default 46: owner, round 2 — the picture is the hero)
const PIC_PX_PHONE = 64;
const PAGES = () => [{ id: '', text: t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];
const t = Office.t;
const ROLES_OURS = ['case', 'disks', 'ram', 'nvme', 'other'];
const ROLES_BIOS = ['cpu', 'pump', 'unknown'];
/** A fan that is his: a role of his own, or a CPU cooler the owner gave him on his own risk (round 4) */
const isOurs = (f) => !!f && (ROLES_OURS.includes(f.role) || (f.role === 'cpu' && !!f.cpu_own));
const cpuGiven = () => (setup().fans || []).some((f) => f.role === 'cpu' && f.cpu_own);
const cpuAsk = new Map();       // fan id → {tick} while the owner is choosing «Fanboy regelt ihn» (kept over drawings)
const PLACES = ['front', 'rear', 'top', 'bottom', 'side'];
const COLOURS = ['auto', 'black', 'graphite', 'grey', 'white', 'slate', 'accent'];
// round 8 (the owner): «Testen und zuordnen» — the test and the roles in ONE step (the old route …/setup/roles opens it)
const STEPS = ['detect', 'test', 'measure', 'zones', 'dry', 'take'];

let state = null;
let history = null;             // the part `history`: {every, zones: {id: [[time, °C, %, rpm]…]}}
let view = null;
let page = '';                  // '' overview · 'setup'
let timer = null;
let alive = null;               // the step's lease while a step runs and a page of his is shown
let banner = null;
let busy = false;
let drawn = '';                 // the setup page's last drawing (its signature): a look that changes nothing of it draws nothing
let sensorBar = null;
let cat = null;                 // the fan catalogue (fans-catalog.json), once
let dry = null;                 // the last dry run's answer (the page's own: not kept)
let zdraft = null;              // the zones as changed on the page, not saved yet (null: as saved)
const opened = new Set();       // rows unfolded (kept while the page lives)
const folds = new Map();        // folds the user opened or closed
const pickers = new Map();      // fan id → its catalogue picker (kept: a drawing never resets a choice being made)
const curves = new Map();       // zone id → its curve editor (kept while its shape stays)
let pic = null;                 // the server picture (kept: its wheels go on turning, new speeds glide)

// ------------------------------------------------------------------ fan-art.js beside this file, once
const ME = document.currentScript && document.currentScript.src;
if (ME && !window.FanArt) {
  const s = document.createElement('script');
  s.src = ME.replace(/desk\.js(\?.*)?$/, (m, q) => `fan-art.js${q || '?'}-${encodeURIComponent(String(Office.config.stamp || ''))}`);
  s.onload = () => {
    if (!window.FanArt) return;
    window.FanArt.styles();
    window.FanArt.catalog().then((c) => { cat = c; if (view) redraw(); }).catch(() => { cat = { fans: [] }; });
    if (view) redraw();
  };
  (document.currentScript.parentNode || document.head).appendChild(s);
} else if (window.FanArt) {
  window.FanArt.catalog().then((c) => { cat = c; }).catch(() => { cat = { fans: [] }; });
}
const art = () => window.FanArt || null;

// ------------------------------------------------------------------ the state, read
const S = () => state || {};
const chips = () => (Array.isArray(S().chips) ? S().chips : []);
const setup = () => S().setup || { fans: [], zones: [], outputs: [], sources: [], curves: {}, before: {} };
const control = () => S().control || {};
const who = () => S().who || {};
const step = () => S().step || null;
const situation = () => (S().situation && S().situation.code) || '';
const counts = () => S().counts || {};
const tools = () => (Array.isArray(S().tools) ? S().tools : []);
const running = (s) => !!s && (s.status === 'starting' || s.status === 'running');
const stepRuns = () => running(step());
const offered = (c) => c.outputs.length > 0 && c.kind !== 'gpu';
const has = (k) => Office.has(`${ID}.${k}`);
const myWhy = (why) => (why && has(`why.${why}`) ? T(`why.${why}`) : '');
const num = (v) => (typeof v === 'number' && isFinite(v) ? v : null);
const steers = () => who().who === 'fanboy';
const nobody = () => who().who === 'nobody';
/** Round 6: the fans he doesn't steer, watched (the agent's fanboy-watch.php) — a tach input's state by chip, address, number */
const idle = () => S().idle || { on: true, tachs: {} };
const idleOf = (chip, address, n) => (idle().tachs || {})[`${chip}@${address}#${n}`] || null;
/**
 * Round 8 (G1, the owner): «stop the other fans meanwhile» — the quiet test: while one output is tested (or shown while
 * he doesn't steer) the others stand, so only that fan is heard. On by default, remembered per browser (a choice).
 */
const quietOn = () => Office.store('fanboy.quiet') !== '0';
/** Round 6: «show me this fan» — running or just ended (fanboy-show.php) */
const showNow = () => S().show || null;
const showRuns = () => { const x = showNow(); return !!x && (x.status === 'starting' || x.status === 'running'); };
/**
 * The button «Show me this fan» for an output: 100 % for 5 seconds, then back exactly as it was. Off (with the reason in
 * its tip) while another controller steers, a setup step or another show runs, an alarm holds, and for an output that
 * doesn't obey or works the other way round — the agent refuses those too.
 */
function showButton(id, f) {
  const x = showNow();
  const mine = showRuns() && x.fan === id;
  const b = el('button', 'btn small plain fb-show');
  b.type = 'button';
  b.dataset.own = '1';
  // 4b: while it runs, its own button ends it («Found it — stop»): the same give-back as after the time
  // round 7 (F2): both labels in one cell, the one not shown hidden — the button keeps its width when it turns into «stop»
  const on = el('span', mine ? 'fb-show-label' : 'fb-show-label fb-show-off');
  on.append(wave(), el('span', '', T('show.stop')));
  b.append(el('span', mine ? 'fb-show-label fb-show-off' : 'fb-show-label', T('show.button')), on);
  const why = f && ['ignored', 'inverted'].includes(f.found) ? T(`show.off.${f.found}`)
    : situation() === 'other' ? T('show.off.other') : stepRuns() ? T('show.off.step') : showRuns() && !mine ? T('show.off.one') : control().running && control().alarm ? T('show.off.alarm') : '';
  b.title = mine ? T('show.stop_tip') : why || T('show.tip');
  b.disabled = (!mine && !!why) || !state || !Office.agent.running;
  // round 8: the quiet switch counts for a show too — only while he doesn't steer (his control never stops another fan)
  b.onclick = () => (mine ? act('show_stop', { fan: id }, b)
    : act('show', { fan: id, quiet: quietOn() && !control().running }, b, () => Office.toast(T('show.toast', { fan: fanName(id) }))));
  return b;
}
/** A tach input's name as the page says it: the setup's fan on it, else the input's own label, else «Fan input 4» */
function tachName(tid) {
  const m = /^(.*)@([^@#]+)#(\d{1,2})$/.exec(String(tid || ''));
  if (!m) return '';
  const n = +m[3];
  const f = (setup().fans || []).find((x) => x.chip === m[1] && (x.tachs || []).includes(n) && idParts(x.id) && idParts(x.id).address === m[2]);
  if (f) return fanName(f.id);
  const c = chips().find((x) => x.name === m[1] && x.address === m[2]);
  const input = c && (c.fans || []).find((x) => x.n === n);
  return (input && input.label) || T('fans.row', { n });
}

/** An output's id parts: chip, address, output number */
const ID_PARTS = /^(.*):([^:]+):pwm(\d{1,2})$/;
function idParts(id) {
  const m = ID_PARTS.exec(String(id || ''));
  return m ? { chip: m[1], address: m[2], output: +m[3] } : null;
}
/** The chip of an output id as the look has it now (by address, else the only chip of that name) */
function chipOf(id) {
  const p = idParts(id);
  if (!p) return null;
  const same = chips().filter((c) => c.name === p.chip);
  return same.find((c) => c.address === p.address) || (same.length === 1 ? same[0] : null);
}
const setupFan = (id) => (setup().fans || []).find((f) => f.id === id) || null;
const controlFan = (id) => {
  for (const z of control().zones || []) for (const f of z.fans || []) if (f.id === id) return f;
  return null;
};
/** The output as the chip has it now: {n, value, pct, enable, mode, auto} */
function outputNow(id) {
  const c = chipOf(id), p = idParts(id);
  return c && p ? (c.outputs || []).find((o) => o.n === p.output) || null : null;
}
/** The speed its tach inputs report now (the highest of a hub's), null without one */
function rpmNow(id, tachs) {
  const c = chipOf(id);
  if (!c) return null;
  const list = (tachs || []).map((n) => ((c.fans || []).find((f) => f.n === n) || {}).rpm).filter((r) => typeof r === 'number');
  return list.length ? Math.max(...list) : null;
}
/** A catalogue entry by id */
const catFan = (mid) => (mid && cat && Array.isArray(cat.fans) ? cat.fans.find((f) => f.id === mid) || null : null);
/** A measured fan's rpm at a percentage (its table), else null */
function tableRpm(table, pct) {
  if (!Array.isArray(table) || !table.length) return null;
  const pts = table.slice().sort((a, b) => a[0] - b[0]);
  if (pct <= pts[0][0]) return pts[0][1];
  for (let i = 1; i < pts.length; i++) {
    if (pct <= pts[i][0]) {
      const [p0, r0] = pts[i - 1], [p1, r1] = pts[i];
      return r0 + (r1 - r0) * (pct - p0) / ((p1 - p0) || 1);
    }
  }
  return pts[pts.length - 1][1];
}
/**
 * How a fan looks in the pictures: its size and blades (the catalogue's model or «other fan» by hand), its range (what
 * Fanboy measured counts before the catalogue), its colour
 */
function fanLook(f) {
  const m = (f && f.model) || {};
  const c = catFan(m.id);
  const measured = f && f.measured;
  const max = measured && num(f.max_rpm) ? f.max_rpm : c && num(c.rpm_max) ? c.rpm_max : null;
  const min = measured && num(f.min_pct) ? tableRpm(f.table, f.min_pct) : c && num(c.rpm_min) ? c.rpm_min : null;
  // the model's own look (fan-art.js draws its colours, blades and frame); a model whose maker doesn't say how many blades
  // gets its blade style's own number there (blades null)
  return { mm: (c && c.mm) || m.mm || 120, blades: (c && c.blades) || (c && c.look ? null : m.blades || 7), min, max,
    colour: (f && f.colour) || 'auto', look: (c && c.look) || null };
}
/** A fan's name for the page: the one given, else «Output 4» */
function fanName(id) {
  const f = setupFan(id);
  const p = idParts(id);
  return f && f.name ? f.name : T('out.name', { n: p ? p.output : '?' });
}
/** A heat source's name: Unraid's disks as Unraid names them (Disk 1, Parity), a sensor by its label */
function srcLabel(s) {
  if (!s) return '';
  if (s.kind === 'disk' || s.kind === 'ssd') {
    const m = /^disk(\d+)$/.exec(s.id);
    if (m) return T('src.disk', { n: m[1] });
    const p = /^parity(\d*)$/.exec(s.id);
    if (p) return p[1] ? T('src.parity_n', { n: p[1] }) : T('src.parity');
    return s.id;
  }
  const named = labels().get(s.id);
  return named || s.label || T(`kind.${has(`kind.${s.kind}`) ? s.kind : 'other'}`);
}
/**
 * Every sensor's name for the page, once per look: its own label, numbered when several of a kind share it («Composite ·
 * 2» for the second NVMe); one without a label of its own (the agent names it by its chip, «spd5118 #1») by its kind and
 * a running number of that kind — never the chip's driver name
 */
let labelsOf = null, labelsMap = new Map();
function labels() {
  const list = setup().sources || [];
  if (labelsOf === list) return labelsMap;
  labelsOf = list;
  labelsMap = new Map();
  const kindN = {}, seen = {}, count = {};
  const own = (x) => x.kind !== 'disk' && x.label && !/^[A-Za-z0-9_.-]+ #\d+$/.test(x.label);
  list.forEach((x) => { if (own(x)) count[`${x.kind}|${x.label}`] = (count[`${x.kind}|${x.label}`] || 0) + 1; });
  list.forEach((x) => {
    if (x.kind === 'disk') return;
    const kind = T(`kind.${has(`kind.${x.kind}`) ? x.kind : 'other'}`);
    if (own(x)) {
      const k = `${x.kind}|${x.label}`;
      seen[k] = (seen[k] || 0) + 1;
      // two of one label: the pool Unraid shows for that NVMe («Composite (cache)»), else a number — as his texts do
      labelsMap.set(x.id, count[k] > 1 ? (x.pool ? T('src.of', { label: x.label, pool: x.pool }) : T('src.nth', { label: x.label, n: seen[k] })) : x.label);
    } else {
      kindN[x.kind] = (kindN[x.kind] || 0) + 1;
      labelsMap.set(x.id, T('src.sensor', { kind, n: kindN[x.kind] }));
    }
  });
  return labelsMap;
}
const srcLimit = (s) => {
  const d = (setup().scales || {})[s.kind] || (setup().scales || {}).other;
  return num(s.limit) ?? (d ? d.limit : null);
};

/** The step at hand: the first not done (detect, test and assign, measure, zones, dry, take) */
function stepAtHand() {
  const su = setup();
  if (!(su.outputs || []).length) return 'detect';
  const s = step();
  if (running(s)) return s.kind === 'measure' ? 'measure' : 'test';
  const fans = su.fans || [];
  // round 8: every output tested or deliberately left with the BIOS, and each with a fan given its role — one step
  const tested = (id) => { const f = fans.find((x) => x.id === id); return f && (['tach', 'silent', 'empty', 'ignored', 'inverted'].includes(f.found) || leftAlone(f)); };
  if (!(su.outputs || []).every(tested)) return 'test';
  const found = fans.filter((f) => ['tach', 'silent', 'unclear'].includes(f.found));
  if (found.some((f) => !f.role)) return 'test';
  const toMeasure = found.filter((f) => isOurs(f) && f.found === 'tach');
  if (toMeasure.some((f) => !f.measured && !f.no_turn)) return 'measure';
  if (!(su.zones || []).length) return 'zones';
  if (su.armed) return 'take';
  return dry ? 'take' : 'dry';
}

// ------------------------------------------------------------------ loading
function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  // his picture with a red mark while something is wrong at his fans (its alarm variant, as the Night Watchman has one)
  if (state) Office.setDeskMood(ID, (control().running && control().alarm) || ['chip_missing', 'chip_gone', 'crashed'].includes(who().why)
    || (idle().on && Object.values(idle().tachs || {}).some((x) => x && x.state === 'alarm')) ? 'alarm' : '');
  if (view && page === 'setup') settleHeard();
  if (view) redraw();
  plan();
}
/** The next look, and the step's lease while one runs */
function plan() {
  clearTimeout(timer);
  timer = null;
  if (!view) { stopAlive(); return; }
  timer = setTimeout(() => load(false), stepRuns() || showRuns() ? LOOK_STEP_MS : LOOK_MS);
  if (page === '' && Date.now() - histAt > 60000) loadHistory();        // the history: a look a minute (the part's refresh_after)
  if (stepRuns()) startAlive(); else stopAlive();
}
function startAlive() {
  if (alive) return;
  const beat = async () => {
    if (!view || !stepRuns() || !Office.agent.running) return;
    const j = await Office.api.post(`${ID}.step_alive`, {});
    // the step as the agent has it now (null: none any more); an answer without one changes nothing
    if (j.ok && state && j.step !== undefined) { state.step = j.step; if (view) redraw(); }
  };
  beat();
  alive = setInterval(beat, ALIVE_MS);
}
function stopAlive() {
  clearInterval(alive);
  alive = null;
}
let histAt = 0;
let historyTried = false;
function loadHistory() {
  if (!Office.agent.running && history) return;
  histAt = Date.now();
  Office.loadState(ID, { part: 'history' }, (j) => {
    historyTried = true;
    if (j.ok && j.part) history = j.part;
    if (view && page === '' && sw.active() !== 'hints') redraw();
  });
  if (period > 24) loadLong();
}
/** The 3 days: only when chosen, a look a minute while the overview is shown (read only) */
let longAt = 0;
async function loadLong() {
  if (!Office.agent.running || Date.now() - longAt < 55000) return;
  longAt = Date.now();
  const j = await Office.api.post(`${ID}.history`, { hours: 72 });
  if (j.ok && j.state) history72 = j.state;
  if (view && page === '') redraw();
}
/** The history of the chosen period: the part's day, or the 3 days */
const historyNow = () => (period > 24 ? history72 : history);

/**
 * Everything the first drawing needs: his pictures (fan-art.js), the fan catalogue, the history (the overview) — or 4 s
 * gone by. Until then the sections wait (the wave): each of them arriving later would draw the lists anew under a hand
 * that is unfolding a row (the release's click run met one, 2026-11-14).
 */
let warmFrom = 0;
const WARM_MS = 4000;
const ready = () => (!!art() && !!cat && (page === 'setup' || historyTried)) || Date.now() - warmFrom > WARM_MS;
/**
 * The page again — only when what it shows changed (a look that brings nothing new draws nothing: a row being clicked,
 * a field being typed in stays as it is; the picture's speeds glide in its own update). Time stamps alone are no change.
 */
function redraw() {
  if (!view) return;
  if (page === 'setup') {
    // the frame (head, tiles, which steps show) anew only when it changed; step 2's list and panel are patched in place —
    // a look every 2 s while a test runs replaces no node there (cards «jumped», a click on a node just replaced was lost)
    const f = frameSig();
    if (f !== drawn) { drawn = f; tilesDrawn = tilesSig(); bannerDrawn = bannerSig(); paint(); return; }
    if (tilesSig() !== tilesDrawn) { tilesDrawn = tilesSig(); if (setupSw) setupSw.row(); }
    if (bannerSig() !== bannerDrawn) { bannerDrawn = bannerSig(); Office.keepInPlace(bench ? bench.el : null, paintBanner); }
    if (bench && bench.el.isConnected) bench.update();
    return;
  }
  const sig = overviewSig();
  if (sig === drawn) return;
  drawn = sig;
  paint();
}
/** The page drawn anew; a field he is typing in (a fan's name while its test runs) keeps its focus and caret through it */
function paint() {
  const a = document.activeElement;
  const keep = a && a.dataset && a.dataset.fbKeep ? [a, a.selectionStart, a.selectionEnd] : null;
  Office.keepInPlace(null, render);
  if (keep && keep[0].isConnected && document.activeElement !== keep[0]) {
    keep[0].focus({ preventScroll: true });
    try { keep[0].setSelectionRange(keep[1], keep[2]); } catch (e) { /* not a text field */ }
  }
}
let tilesDrawn = '', bannerDrawn = '';
const VOLATILE = new Set(['time', 'at', 'since', 'begun', 'mono', 'now', 'tested_at']);
const quiet = (v) => JSON.stringify(v, (k, x) => (VOLATILE.has(k) ? undefined : x));
/** What the setup page's frame shows: the head, the banner's place, the steps shown — and, for every step but step 2 (it patches itself), their content */
function frameSig() {
  const su = setup();
  const active = setupSw ? setupSw.active() : '';
  const rest = active === 'test' ? null : [busy, su.fans, su.zones, su.curves, step(), showNow(), dry, zdraft];
  return quiet([ready(), !!art(), !!cat, !!state, Office.agent.running, bubbleText(), plainNow(), situation(), control().running, su.armed, su.outputs, active, rest]);
}
/** What the step tiles say */
function tilesSig() {
  const su = setup(), s = step();
  return quiet([(su.fans || []).map((f) => [f.id, f.found, f.role, f.measured, f.no_turn, f.cpu_own]), (su.zones || []).length, s && [s.kind, s.status], !!dry, who(), su.armed, (control().alarm || {}).code,
    setupSw ? setupSw.active() : '']);
}
/** What the banner above the steps says */
const bannerSig = () => quiet([stepRuns(), showRuns(), ((S().now || {}).safety || []).length, who(), S().situation, control().running, control().alarm, control().armed, control().why, idle(), S().twins, S().vm, setup().zones && setup().zones.length, cpuGiven()]);
function overviewSig() {
  return quiet([ready(), !!art(), !!cat, Office.agent.running, busy, state, history && history.zones, period, period > 24 && history72 ? history72.zones : null, sw.active(), (sensorBar && sensorBar.read().words) || []]);
}

// ------------------------------------------------------------------ the bubble
function bubbleText() {
  if (!state) return T('bubble.loading');
  const w = who();
  const c = control();
  if (w.who === 'fanboy') return w.why === 'alarm' ? T('bubble.alarm') : T('bubble.steers', { n: w.zones || 0 });
  if (stepRuns()) return step().kind === 'measure' ? T('bubble.measuring') : T('bubble.testing');
  if (w.why === 'chip_missing' || w.why === 'chip_gone') return T(`bubble.${w.why}`);
  if (w.why === 'array_stopped') return T('bubble.array_stopped');
  if (w.why === 'fixed') return T('bubble.fixed');           // round 7: nobody steers — a fixed value someone left
  const s = situation();
  if (s === 'other') return T('bubble.other', { who: otherNames() });
  if (s === 'driver_missing' || s === 'no_output' || s === 'ipmi') return T(`bubble.${s}`);
  if (s === 'ok' && c.armed === false && (setup().zones || []).length) return T('bubble.ready');
  if (s === 'ok') return T('bubble.ok', { n: counts().outputs || 0 });
  return T('bubble.loading');
}
/** Round 7 (F3): the outputs on a fixed value someone left, as the page names them — «Output 1 (50 %), Output 4 (100 %)» */
const fixedOut = (id) => (who().fixed || []).some((x) => x.id === id);
const fixedList = (list) => (list || []).map((x) => T('fixed.item', { name: fanName(x.id), pct: x.pct })).join(', ');
const otherNames = () => ((S().situation || {}).names || []).join(', ') || (who().name || '');
const plainNow = () => ['alarm', 'chip_missing', 'chip_gone', 'crashed', 'array_stopped'].includes(who().why) || (control().running && control().alarm);

// ------------------------------------------------------------------ the report for the makers' wish form
/**
 * «Send to the makers…»: the agent writes his report (fanboy.report, read only); the office's report form opens as a wish
 * of Fanboy's desk with it in the text — the user sees everything before anything goes (core.js Office.reportDialog()).
 */
async function sendReport(b) {
  if (!Office.agent.running) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.report`, {});
  b.disabled = false;
  if (!j.ok || typeof j.report !== 'string') { Office.toast(Office.errorText(j.error, ID), true); return; }
  const was = Office.storeJson('report.draft') || {};
  const mine = typeof was.text === 'string' && !was.text.includes('Fanboy detection report') ? was.text.trim() : '';
  Office.storeJson('report.draft', {
    kind: 'wish',
    desk: ID,
    title: typeof was.title === 'string' && was.title.trim() && mine ? was.title : T('report_title'),
    text: `${mine || T('report_wish')}\n\n\n${j.report}`,
  });
  Office.reportDialog(ID);
}
function reportButton(small) {
  const b = el('button', small ? 'btn small' : 'btn', T('report'));
  b.type = 'button';
  b.title = T('report_tip');
  b.disabled = !Office.agent.running || !state;
  b.onclick = () => sendReport(b);
  return b;
}

// ------------------------------------------------------------------ small parts
function chipOfText(cls, text, tip) {
  const c = el('span', `chip ${cls}`, text);
  c.title = tip;
  return c;
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
/** A row that unfolds its technical detail: the whole row clicks (its own controls not), the name takes Enter and Space */
function unfoldRow(key, at, name, meta, value, detail) {
  const row = el('div', 'row nocheck unfolds fb-row' + (opened.has(key) ? ' open' : ''));
  const r = at.place ? Office.place(at.place, row) : row;     // a row shown twice (a source in two zones) marks its place once
  const main = el('div', 'row-main');
  const n = el('div', 'row-name link', name);
  n.tabIndex = 0;
  n.setAttribute('role', 'button');
  n.setAttribute('aria-expanded', opened.has(key) ? 'true' : 'false');
  n.title = T('row.details');
  main.append(n, meta);
  const box = el('div', 'row-detail fb-tech');
  box.hidden = !opened.has(key);
  detail.forEach(([k, v]) => { const p = el('div', 'fb-tech-line'); p.append(el('span', 'fb-tech-k', k), el('span', 'mono', v)); box.appendChild(p); });
  main.appendChild(box);
  r.append(main);
  if (value) r.appendChild(value);
  const toggle = () => Office.keepInPlace(r, () => {
    const on = !opened.has(key);
    if (on) opened.add(key); else opened.delete(key);
    r.classList.toggle('open', on);
    n.setAttribute('aria-expanded', on ? 'true' : 'false');
    box.hidden = !on;
  });
  r.addEventListener('click', (e) => {
    if (e.target.closest('button, a, input, select, label, textarea, [data-own]') || e.target.closest('.row-detail')) return;
    toggle();
  });
  n.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
  return r;
}
function button(text, tip, onClick, cls) {
  const b = el('button', cls || 'btn small', text);
  b.type = 'button';
  if (tip) b.title = tip;
  b.disabled = busy || !Office.agent.running || noRun;
  b.onclick = () => onClick(b);
  return b;
}
/** An action of his from the page: the button waits, the new state drawn, a problem said plainly */
async function act(action, body, b, done) {
  if (busy || !Office.agent.running) return null;
  busy = true;
  if (b) b.disabled = true;
  await Office.freshState(ID);
  const j = await Office.api.post(`${ID}.${action}`, body || {});
  busy = false;
  if (b) b.disabled = false;
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); drawn = ''; if (view) redraw(); return null; }
  if (j.state) state = j.state;
  if (done) await done(j);
  drawn = '';
  if (!j.state && action !== 'step_alive') await load(true);
  else if (view) redraw();
  plan();
  return j;
}
/** The wave wherever something runs (never a circle) */
const wave = () => el('span', 'spin');
const waiting = () => { const p = el('p', 'fb-wait'); p.append(wave(), el('span', '', T('pictures_loading'))); return p; };

// ------------------------------------------------------------------ the picture of the server
/** A wheel's words and state for a fan of his or the BIOS's, as it is now */
function wheelOf(id, opts) {
  const f = setupFan(id) || { id, found: null, tachs: [] };
  const look = fanLook(f);
  const cf = controlFan(id);
  const live = rpmNow(id, f.tachs);
  const rpm = cf && num(cf.rpm) !== null ? cf.rpm : live;
  const held = !!(cf && cf.held && control().running);
  const bios = !held || ROLES_BIOS.includes(f.role);
  const stuck = nobody() && isOurs(f) && (setup().zones || []).some((z) => (z.fans || []).includes(id));
  const silent = f.found === 'silent';
  const pct = held && num(cf.pct) !== null ? cf.pct : null;
  let st = bios ? 'bios' : 'run';
  if (silent) st = bios ? 'bios' : 'unknown';
  if (held && !silent && rpm === 0 && (pct === null || pct > 0)) st = 'stopped';
  const sub = st === 'stopped' ? T('pic.should_turn')
    : silent ? (held && pct !== null ? T('pic.pct_no_tach', { pct }) : T('pic.no_tach'))
      : rpm === null ? T('pic.no_value')
        : held && pct !== null ? T('pic.rpm_pct', { rpm: fmt.number(rpm), pct }) : T('pic.rpm', { rpm: fmt.number(rpm) });
  // a silent fan of his: no tach to show its speed — the percentage he gives it hints at it
  const shown = silent && held && pct !== null && look.max ? look.max * pct / 100 : silent && held && pct !== null ? 2000 * pct / 100 : rpm;
  return {
    id, label: fanName(id), sub, mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: look.colour, look: look.look,
    rpm: st === 'unknown' ? null : shown, state: silent && held ? 'run' : st, place: f.place || (opts && opts.place) || 'front',
    // round 5: the chip went while he steered — his fans sit where he left them (nobody's), the BIOS's stay the BIOS's
    // round 7 (F3): its output on a fixed value someone left — nobody's, not the BIOS's
    badge: st === 'bios' ? (stuck || fixedOut(id) ? T('pic.stuck') : T('pic.bios')) : '',
    title: st === 'bios' ? (stuck ? T('pic.stuck_title') : fixedOut(id) ? T('pic.fixed_title') : ROLES_BIOS.includes(f.role) ? T('pic.watched_title') : T('pic.bios_title')) : T('pic.his_title'),
  };
}
/**
 * The wheels of one connector: as many as the user said hang on it (a Y-cable, a hub — round 3), one speed for all of
 * them (one connector, one tach), the same model; the first carries the words, the others say they share its connector
 */
function wheelsOf(id, opts) {
  const w = wheelOf(id, opts);
  const n = Math.max(1, Math.min(8, (setupFan(id) || {}).count || 1));
  if (n === 1) return [w];
  const first = { ...w, sub: `${w.sub} · ${T('pic.on_one', { n })}`, title: `${w.title} · ${T('pic.on_one_title', { n })}` };
  return [first, ...Array.from({ length: n - 1 }, (x, k) => ({ ...w, id: `${id}#${k + 2}`, label: '', sub: T('pic.same_connector'), badge: '', title: T('pic.on_one_title', { n }) }))];
}
/** A zone's line under its name: its curve, the warmest spot and what it asks for */
function zoneNote(z, cz) {
  const parts = [];
  const tgt = z.target || {};
  parts.push(tgt.points ? T('curve.own') : T(`preset.${has(`preset.${tgt.preset}`) ? tgt.preset : 'normal'}`));
  if (cz && cz.full && control().running) {
    parts.push(T('pic.full'));                // round 4: an alarm here — this zone at 100 %, the others steer on
  } else if (cz && control().running && steers()) {
    if (cz.warmest && num(cz.warmest.c) !== null) {
      const lvl = num(cz.level) === null ? null : cz.unit === 'rpm' ? T('pic.rpm', { rpm: fmt.number(Math.round(cz.level)) }) : T('value', { pct: Math.round(cz.level) });
      parts.push(lvl ? T('pic.asks', { where: srcLabel({ ...cz.warmest, id: cz.warmest.id }), c: fmt.number(cz.warmest.c), level: lvl }) : T('pic.warmest', { where: srcLabel(cz.warmest), c: fmt.number(cz.warmest.c) }));
    } else parts.push(T('pic.quiet'));
  } else parts.push(T(nobody() ? 'pic.nobody_now' : 'pic.bios_now'));
  return parts.join(' · ');
}
/** The server as the picture shows it: his zones (or, before the setup, what the board shows), the BIOS's fans watched */
function picture() {
  const su = setup();
  const src = new Map((su.sources || []).map((s) => [s.id, s]));
  const asSource = (s) => ({ id: s.id, label: srcLabel(s), kind: s.kind === 'ssd' ? 'nvme' : s.kind, temp: num(s.c), asleep: !!s.asleep, limit: srcLimit(s),
    title: s.asleep ? T('pic.asleep_title') : '' });
  const zones = [];
  const zonesNow = new Map((control().zones || []).map((z) => [z.id, z]));
  (su.zones || []).forEach((z) => {
    const cz = zonesNow.get(z.id);
    zones.push({
      id: z.id, name: z.name || z.id, note: zoneNote(z, cz),
      fans: (z.fans || []).flatMap((id) => wheelsOf(id)),
      sources: (z.sources || []).map((s) => src.get(s.id)).filter(Boolean).map((s) => asSource({ ...s, limit: s.limit ?? (z.sources.find((x) => x.id === s.id) || {}).limit })),
      warmest: cz && cz.warmest ? cz.warmest.id : null,
    });
  });
  // the BIOS's own: the CPU cooler and pumps he only watches (and, before any zone, every fan the board reports)
  const watched = (su.fans || []).filter((f) => ['cpu', 'pump'].includes(f.role) && !isOurs(f) && f.found === 'tach');
  if (zones.length) {
    if (watched.length) {
      zones.push({ id: '_bios', name: T('pic.bios_zone'), note: T('pic.bios_zone_note'), fans: watched.flatMap((f) => wheelsOf(f.id).map((w) => ({ ...w, place: 'top' }))),      // a narrow column: the wheel on top, the chips under it
        sources: (su.sources || []).filter((s) => s.kind === 'cpu').slice(0, 2).map(asSource) });
    }
    return { zones };
  }
  const fans = [];
  chips().filter(offered).forEach((c) => (c.fans || []).forEach((f) => {
    if (typeof f.rpm !== 'number' || f.rpm <= 0) return;
    const id = `${c.name}:${c.address}:fan${f.n}`;
    const out = (su.fans || []).find((x) => x.chip === c.name && (x.tachs || []).includes(f.n));
    const look = fanLook(out);
    fans.push({ id, label: out && out.name ? out.name : T('fans.row', { n: f.n }), sub: T('pic.rpm', { rpm: fmt.number(f.rpm) }), mm: look.mm, blades: look.blades,
      min: look.min, max: look.max, colour: look.colour, look: look.look, rpm: f.rpm, state: 'bios',
      badge: who().why === 'fixed' ? T('pic.stuck') : T('pic.bios'), title: who().why === 'fixed' ? T('pic.fixed_title') : T('pic.bios_title'),
      place: (out && out.place) || (fans.length % 2 ? 'rear' : 'front') });
  }));
  const kinds = ['cpu', 'nvme', 'disk', 'ram', 'board'];
  const sources = (su.sources || []).filter((s) => kinds.includes(s.kind))
    .sort((a, b) => kinds.indexOf(a.kind) - kinds.indexOf(b.kind)).filter((s, i, all) => s.kind === 'disk' || all.findIndex((x) => x.kind === s.kind && x.label === s.label) === i)
    .slice(0, 14).map(asSource);
  return { zones: [{ id: '_all', name: T('pic.all'), note: steers() ? '' : who().why === 'fixed' ? T('pic.all_note_fixed') : T('pic.all_note'), fans, sources }] };
}
function pictureEl() {
  const A = art();
  if (!A) return waiting();
  const p = picture();
  const texts = { temp: (c) => T('temp', { c: fmt.number(c) }), asleep: T('pic.asleep'), warmest: T('pic.warmest_mark'), none: T('pic.none') };
  // the hero of the page: big wheels (their colour and blades seen), his zones filling the row two by two, the BIOS's
  // own group narrow beside them (desk.css .fb-server); a phone: one column, the wheels a little smaller
  if (!pic) pic = A.server(p, { texts, px: window.matchMedia && window.matchMedia('(max-width:640px)').matches ? PIC_PX_PHONE : PIC_PX });
  else pic.update(p);
  const mine = p.zones.filter((z) => z.id !== '_bios').length;
  pic.el.classList.add('fb-server');
  pic.el.classList.toggle('fb-with-bios', p.zones.some((z) => z.id === '_bios'));
  pic.el.style.setProperty('--fb-cols', String(Math.max(1, Math.min(2, mine))));
  pic.el.style.setProperty('--fb-rows', String(Math.max(1, Math.ceil(mine / 2))));
  return pic.el;
}

// ------------------------------------------------------------------ the overview's tiles
function whoTile(a) {
  const w = who();
  const value = !state ? '…' : w.who === 'fanboy' ? T('who.fanboy', { n: w.zones || 0 }) : w.who === 'bios' ? T('who.bios') : w.who === 'bmc' ? T('who.bmc') : w.who === 'nobody' ? T('who.nobody') : (w.name || w.who || '…');
  const alert = ['alarm', 'crashed', 'chip_missing', 'chip_gone'].includes(w.why);
  return Office.stat({ label: T('tile.who'), value, sub: state ? myWhy(w.why) : '', alert, ...a });
}
function fansTile(a) {
  const c = counts();
  const all = Number(c.fans) || 0;
  const turning = Number(c.turning) || 0;
  const zero = Number(c.zero) || 0;
  const sub = [];
  if (turning) sub.push(T('fans.range', { min: fmt.number(c.rpm_min), max: fmt.number(c.rpm_max) }));
  const stands = control().running && control().alarm && ['stands', 'bios_stands'].includes(control().alarm.code);
  if (stands) sub.push(T('fans.stands'));
  else if (zero) sub.push(T('fans.zero', { n: zero }));
  return Office.stat({ label: T('tile.fans'), value: !state ? '…' : all ? T('fans.reporting', { n: turning }) : T('fans.none'), sub, alert: !!stands, ...a });
}
/** Whose a place is, in words: «HDD · Disk 3», «SSD/NVMe · Composite · 2» */
function hotWhose(x) {
  if (!x) return '';
  if (x.disk) return srcLabel({ kind: 'disk', id: x.disk });
  const src = (setup().sources || []).find((y) => y.chip === x.chip && y.n === x.n && (y.label === x.label || !x.label));
  return src ? srcLabel(src) : x.label || '';
}
const LEAD_NEAR = 5;            // °C under its limit from which a place leads the tile (over it: in colour)
/**
 * Which place leads: the one nearest to (or over) its limit — Unraid's for disks (the agent's `near`), the user's own
 * limit for a heat source of a zone; places without a limit are shown, never judged. null: nothing near a limit.
 */
function hottestLead() {
  const cands = [];
  (S().hottest_kinds || []).forEach((k) => { if (k.near) cands.push({ kind: k.kind, c: k.near.c, limit: k.near.warn, crit: k.near.crit, whose: hotWhose(k.near), unraid: true }); });
  const src = new Map((setup().sources || []).map((x) => [x.id, x]));
  (setup().zones || []).forEach((z) => (z.sources || []).forEach((zs) => {
    const x = src.get(zs.id);
    if (x && num(zs.limit) !== null && num(x.c) !== null) cands.push({ kind: hotKind(x), c: x.c, limit: zs.limit, crit: null, whose: srcLabel(x), unraid: false });
  }));
  let lead = null;
  cands.forEach((c) => { if (!lead || c.c - c.limit > lead.c - lead.limit) lead = c; });
  return lead && lead.c - lead.limit >= -LEAD_NEAR ? lead : null;
}
/** A heat source's kind for the tile: hdd, ssd (SSD, NVMe), cpu, board, ram, other */
function hotKind(x) {
  if (x.kind === 'disk') { const d = (S().disks || []).find((y) => y.name === x.id); return d && d.rotational === false ? 'ssd' : 'hdd'; }
  return { nvme: 'ssd', ssd: 'ssd', cpu: 'cpu', board: 'board', ram: 'ram' }[x.kind] || 'other';
}
/**
 * «Warmest spots»: the warmest of each kind (a hard disk's 44 °C and an NVMe's are not the same thing); the place nearest
 * to its limit leads, in colour once over it; nothing near a limit: the kinds side by side, calmly
 */
function hottestTile(a) {
  const kinds = (S().hottest_kinds || []).filter((k) => k.warmest || k.asleep);
  if (!state || !kinds.length) {
    const h = S().hottest;
    return Office.stat({ label: T('tile.hottest'), value: !state ? '…' : h ? T('temp', { c: fmt.number(h.c) }) : T('hottest.none'), ...a });
  }
  // «HDD · Disk 3 · 41 °C»; a name that says its kind already («Mainboard-Fühler 2») without the kind in front
  const line = (k) => {
    if (!k.warmest) return T('hottest.kind_asleep', { kind: T(`hk.${k.kind}`) });
    const whose = hotWhose(k.warmest);
    const c = fmt.number(k.warmest.c);
    return whose.toLowerCase().startsWith(T(`hk.${k.kind}`).toLowerCase()) ? T('hottest.whose_line', { whose, c }) : T('hottest.kind_line', { kind: T(`hk.${k.kind}`), whose, c });
  };
  const lead = hottestLead();
  if (!lead) return Office.stat({ label: T('tile.hottest'), value: T('hottest.calm'), sub: kinds.slice(0, 4).map(line), ...a });
  const over = lead.c >= lead.limit;
  const how = over ? (lead.unraid ? T('hottest.over_unraid', { limit: fmt.number(lead.limit) }) : T('hottest.over_own', { limit: fmt.number(lead.limit) }))
    : (lead.unraid ? T('hottest.near_unraid', { d: fmt.number(lead.limit - lead.c), limit: fmt.number(lead.limit) }) : T('hottest.near_own', { d: fmt.number(lead.limit - lead.c), limit: fmt.number(lead.limit) }));
  return Office.stat({ label: T('tile.hottest'), value: T('hottest.lead', { kind: T(`hk.${lead.kind}`), whose: lead.whose, c: fmt.number(lead.c) }),
    sub: [how, ...kinds.filter((k) => k.kind !== lead.kind).slice(0, 3).map(line)], alert: over, ...a });
}
/** «Today»: the highest temperature of the day and how long his fans ran above their quiet level */
function todayTile(a) {
  const d = today();
  const value = !state ? '…' : d ? T('temp', { c: fmt.number(d.max) }) : (setup().zones || []).length ? T('today.none') : T('today.not_set_up');
  const sub = d ? [T('today.above', { time: durationText(d.above) })] : [];
  return Office.stat({ label: T('tile.today'), value, sub, ...a });
}
function hintsTile(a) {
  const n = Array.isArray(S().suggest) ? S().suggest.length : 0;
  const there = tools().filter((x) => x.installed).length;
  const value = !state ? '…' : n ? T('hints.fits', { n }) : S().hints_open ? T('hints.read') : T('hints.why');
  return Office.stat({ label: T('tile.hints'), value, sub: state ? T('hints.there', { n: there }) : '', alert: !!S().hints_open, ...a });
}
function today() {
  const zs = history && history.zones ? history.zones : null;
  if (!zs) return null;
  const start = new Date(histNow() * 1000);
  start.setHours(0, 0, 0, 0);
  const from = start.getTime() / 1000;
  let max = null, above = 0;
  const every = num(history.every) || 60;
  Object.keys(zs).forEach((zid) => {
    const pts = (zs[zid] || []).filter((p) => p[0] >= from);
    const quiet = quietLevel(zid);
    pts.forEach((p) => {
      if (num(p[1]) !== null) max = max === null ? p[1] : Math.max(max, p[1]);
      if (num(p[2]) !== null && quiet !== null && p[2] > quiet + 1) above += every;
    });
  });
  return max === null ? null : { max, above };
}
/** The moment the history was read at (the agent's clock), else now */
const histNow = (h = history) => (h && num(h.now) !== null ? h.now : Date.now() / 1000);
/** A zone's quiet level: its curve's lowest value */
function quietLevel(zid) {
  const c = (setup().curves || {})[zid];
  return c && Array.isArray(c.points) && c.points.length ? Math.min(...c.points.map((p) => p[1])) : null;
}
function durationText(s) {
  const m = Math.round(s / 60);
  if (m < 60) return T('dur.min', { n: m });
  if (m >= 1440) return T('dur.d_h', { d: Math.floor(m / 1440), h: Math.floor((m % 1440) / 60) });
  return T('dur.h_min', { h: Math.floor(m / 60), m: m % 60 });
}

const sw = Office.sections({
  id: ID,
  grid: 'stats',
  default: '',
  tiles: () => [
    // the order reads as the page does: the picture, what happened today (the curves), the fans, the heat sources, hints
    { id: 'who', key: 'tile.who', place: 'tile.who', tile: whoTile, section: () => serverSection() },
    { id: 'today', key: 'tile.today', place: 'tile.today', tile: todayTile, section: () => curvesSection() },
    { id: 'fans', key: 'tile.fans', place: 'tile.fans', tile: fansTile, section: () => fansSection() },
    { id: 'hottest', key: 'tile.hottest', place: 'tile.hottest', tile: hottestTile, section: () => sensorsSection() },
    { id: 'hints', key: 'tile.hints', place: 'tile.hints', tile: hintsTile, section: () => hintsSection() },
  ],
});

// ------------------------------------------------------------------ the overview's sections
function serverSection() {
  const s = el('section', 'section');
  const go = el('a', 'btn small', steers() || (setup().zones || []).length ? T('server.change') : T('server.set_up'));
  go.href = `#/${ID}/setup`;
  s.appendChild(Office.sectionHead(T('server.title'), steers() ? T('server.sub_steers') : who().why === 'fixed' ? T('server.sub_fixed') : T('server.sub'), go, { place: 'server.title' }));
  s.appendChild(pictureEl());
  const notes = notesBox();
  if (notes) s.append(Office.place('notes.title', el('h3', 'fb-head', T('notes.title'))), notes);
  s.appendChild(dashChoice());
  return s;
}

/**
 * «Show on the Dashboard»: his own tile on Unraid's Dashboard (src/dashboard-fanboy.php) — off (the default), speeds,
 * speeds with history. Office-wide (the agent keeps it: fanboy.dashboard), never per browser.
 */
const DASH_SHOWS = ['off', 'speeds', 'history'];
function dashChoice() {
  const box = Office.place('dash.setting', el('div', 'fb-dash-choice'));
  const now = DASH_SHOWS.includes(S().dashboard) ? S().dashboard : 'off';
  const head = el('div', 'fb-dash-text');
  head.append(el('h3', 'fb-head', T('dash.setting')), el('p', 'fb-hint-text', T('dash.setting_sub')));
  const seg = el('div', 'seg');
  seg.setAttribute('role', 'group');
  seg.setAttribute('aria-label', T('dash.setting'));
  DASH_SHOWS.forEach((k) => {
    const b = el('button', '', T(`dash.show.${k}`));
    b.type = 'button';
    b.title = T(`dash.show.${k}_title`);
    b.setAttribute('aria-pressed', String(k === now));
    b.disabled = !state || !Office.agent.running;
    b.onclick = () => { if (k !== now) act('dashboard', { show: k }, b); };
    seg.appendChild(b);
  });
  box.append(head, seg);
  return box;
}

/** What happened lately at his fans (the control's notes, newest first, known kinds only) */
function notesBox() {
  const notes = (control().notes || []).filter((n) => n && has(`note.${n.code}`)).slice(-6).reverse();
  if (!notes.length) return null;
  const box = el('div', 'box');
  notes.forEach((n) => {
    const r = el('div', 'row nocheck fb-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T(`note.${n.code}`, { fan: '', source: '', c: '', zone: '', to: '', from: '', rpm: '', want: '', ...(n.params || {}) })));
    if (n.at) main.appendChild(el('div', 'row-meta', fmt.relative(n.at)));
    r.appendChild(main);
    box.appendChild(r);
  });
  return box;
}

/**
 * Round 6: what the watch says about a fan he doesn't steer — it stands though something is warm (told after 2 minutes),
 * it stops only while everything is cool (learned: the BIOS's «fan stop»); nothing for a fan that turns as it should
 */
function watchChip(x) {
  if (!x || !idle().on) return null;
  const w = idle().warm || {};
  if (x.state === 'alarm') return chipOfText('warn', T('watch.alarm'), T('watch.alarm_title'));
  if (x.state === 'stands_warm') return chipOfText('warn', T('watch.stands_warm'), T('watch.stands_warm_title', { source: warmName(w), c: num(w.c) ?? '' }));
  if (x.state === 'stops_cool' || (x.quiet_stop && x.state !== 'steered')) return chipOfText('quiet', T('watch.stops_cool'), T('watch.stops_cool_title'));
  return null;
}
/** The warm place the watch names, as the page names heat sources (a disk as Unraid does, a sensor by its label) */
const warmName = (w) => (w && w.id ? srcLabel((setup().sources || []).find((x) => x.id === w.id) || { kind: w.kind, id: w.id }) || w.name || '' : '');
/** The watch's word for an output's fan: the first of its tach inputs that has one */
function watchChipOf(c, f) {
  for (const n of (f && f.tachs) || []) {
    const chip = watchChip(idleOf(c.name, c.address, n));
    if (chip) return chip;
  }
  return null;
}

/** What an output is to him: his (zone), the BIOS's watched, empty, not tested — a label */
function outputRole(id) {
  const f = setupFan(id);
  const cf = controlFan(id);
  if (cf && cf.foreign) return chipOfText('warn', T('out.foreign'), T('out.foreign_title'));
  if (cf && cf.held && control().running) return chipOfText('accent', T('out.held', { pct: num(cf.pct) === null ? '–' : cf.pct }), T('out.held_title', { zone: zoneName(cf.zone || zoneOfFan(id)) }));
  if (!f || !f.found) return chipOfText('quiet', T('out.untested'), T('out.untested_title'));
  if (f.found === 'empty') return chipOfText('quiet', T('out.empty'), T('out.empty_title'));
  if (f.found === 'ignored' || f.found === 'inverted') return chipOfText('warn', T(`out.${f.found}`), T(`out.${f.found}_title`));
  if (f.role && ROLES_BIOS.includes(f.role)) return chipOfText('quiet', T(`role.${f.role}`) + ' · ' + T('out.watched'), T('out.watched_title'));
  if (f.role) return chipOfText('quiet', T(`role.${f.role}`), T('out.his_title'));
  return chipOfText('quiet', T('out.no_role'), T('out.no_role_title'));
}
const zoneOfFan = (id) => ((setup().zones || []).find((z) => (z.fans || []).includes(id)) || {}).id || '';
const zoneName = (zid) => ((setup().zones || []).find((z) => z.id === zid) || {}).name || zid;

/** A subheading in a list: a tinted bar, its rows indented under it (the house's list groups) */
function group(text, sub) {
  const g = el('div', 'fb-group');
  g.appendChild(el('span', 'fb-group-name', text));
  if (sub) g.appendChild(el('span', 'fb-group-sub', sub));
  return g;
}
/**
 * The rest of a list behind one fold: «{n} more …» — kept open or shut while the page lives (keepFold, never per browser);
 * while the filter has words, the rows that fit in it show and it stands open (a fold of its own: the user's stays)
 */
function moreFold(key, rows, label, filtering) {
  if (!rows.length) return null;
  const det = keepFold(el('details', 'fb-more'), filtering ? `${key}:filtered` : key, !!filtering);
  det.appendChild(el('summary', '', label));
  const box = el('div', 'box');
  rows.forEach((r) => box.appendChild(r));
  det.appendChild(box);
  return det;
}

/** One output's row: its name, what it is to him, its mode, the speed, the percent; the technical detail unfolds */
function outputRow(c, o) {
  const id = `${c.name}:${c.address}:pwm${o.n}`;
  const f = setupFan(id);
  const meta = el('div', 'row-meta');
  if (!offered(c)) meta.appendChild(el('span', 'note', T('outputs.gpu')));
  else meta.appendChild(outputRole(id));
  const mode = o.mode || 'unknown';
  meta.appendChild(chipOfText(mode === 'auto' ? 'ok' : mode === 'unknown' ? 'quiet' : 'accent', T(`mode.${mode}`), T(`mode.${mode}_title`)));
  const rpm = f ? rpmNow(id, f.tachs) : null;
  if (rpm !== null) meta.appendChild(el('span', '', T('pic.rpm', { rpm: fmt.number(rpm) })));
  const wc = watchChipOf(c, f);
  if (wc) meta.appendChild(wc);
  if (offered(c)) meta.appendChild(showButton(id, f));
  const v = el('div', 'fb-value', num(o.pct) === null ? '–' : T('value', { pct: o.pct }));
  v.title = T('value_title', { value: o.value ?? '–' });
  const b = (setup().before || {})[id];
  return unfoldRow(`out:${id}`, { place: `out:${c.address}:${o.n}` }, f && f.name ? f.name : T('out.name', { n: o.n }), meta, v, [
    [T('tech.chip'), `${c.name} · ${c.address}${c.module ? ' · ' + c.module : ''}`],
    [T('tech.output'), `pwm${o.n} · ${c.hwmon}`],
    [T('tech.mode'), `pwm${o.n}_enable = ${o.enable ?? '–'} · ${o.value ?? '–'} / 255`],
    [T('tech.tachs'), f && (f.tachs || []).length ? f.tachs.map((n) => `fan${n}_input`).join(', ') : '–'],
    [T('tech.before'), b ? `${b.enable ?? '–'} · ${b.value ?? '–'} / 255` : '–'],
  ]);
}
/**
 * Fans and outputs: first the outputs he steers, then those the BIOS keeps with a fan on them (and those not tested yet:
 * a fan may be on them); empty outputs, a graphics card's and the bare tach inputs behind one fold
 */
function fansSection() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('fans.title'), steers() ? T('fans.sub_steers') : T('fans.sub'), { place: 'fans.title' }));
  const his = [], bios = [], rest = [];
  chips().filter((c) => c.outputs.length).forEach((c) => c.outputs.forEach((o) => {
    const id = `${c.name}:${c.address}:pwm${o.n}`;
    const f = setupFan(id);
    const cf = controlFan(id);
    const row = outputRow(c, o);
    if (!offered(c) || (f && f.found === 'empty')) rest.push(row);
    else if ((cf && cf.held && control().running) || (isOurs(f) && (setup().zones || []).some((z) => (z.fans || []).includes(id)))) his.push(row);
    else bios.push(row);
  }));
  // tach inputs no output of his has: what the board reports (the CPU cooler on its own header, an empty one)
  const known = new Set();
  (setup().fans || []).forEach((f) => (f.tachs || []).forEach((n) => known.add(`${f.chip}#${n}`)));
  chips().forEach((c) => (c.fans || []).forEach((f) => {
    if (known.has(`${c.name}#${f.n}`)) return;
    const meta = el('div', 'row-meta');
    meta.appendChild(chipOfText('quiet', T('fans.input'), T('fans.input_title')));
    if (f.rpm === null || f.rpm === undefined) meta.appendChild(chipOfText('quiet', T('fans.no_value'), T('fans.no_value_title')));
    const wc = watchChip(idleOf(c.name, c.address, f.n));
    if (wc) meta.appendChild(wc);
    rest.push(unfoldRow(`tach:${c.address}:${f.n}`, { place: `fan:${c.address}:${f.n}` }, f.label || T('fans.row', { n: f.n }), meta,
      el('div', 'fb-value', f.rpm === null || f.rpm === undefined ? '–' : T('pic.rpm', { rpm: fmt.number(f.rpm) })),
      [[T('tech.chip'), `${c.name} · ${c.address}`], [T('tech.input'), `fan${f.n}_input · ${c.hwmon}`]]));
  }));
  const list = el('div', 'box');
  if (his.length) { list.appendChild(group(T('fans.group_his'), T('fans.group_his_sub'))); his.forEach((r) => list.appendChild(r)); }
  if (bios.length) { list.appendChild(group(T('fans.group_bios'), T('fans.group_bios_sub'))); bios.forEach((r) => list.appendChild(r)); }
  if (!list.children.length) list.appendChild(el('p', 'empty', rest.length ? T('fans.none_first') : T('outputs.none')));
  // round 4: a CPU cooler given to him on the owner's risk — said where his fans are, with the date
  (setup().fans || []).filter((f) => f.role === 'cpu' && f.cpu_own).forEach((f) => s.appendChild(el('p', 'fb-cpu-own', T('cpu.own_since', { date: Office.fmt.date(f.cpu_own) }))));
  s.appendChild(list);
  const more = moreFold('fans-more', rest, T('fans.more', { n: rest.length }), false);
  if (more) s.appendChild(more);
  s.appendChild(watchChoice());
  return s;
}

/**
 * Round 6: «Watch fans I don't steer» — on (the default) or off, office-wide (the agent keeps it: fanboy.watch), never
 * per browser
 */
function watchChoice() {
  const box = Office.place('watch.setting', el('div', 'fb-dash-choice'));
  const on = idle().on !== false;
  const head = el('div', 'fb-dash-text');
  head.append(el('h3', 'fb-head', T('watch.setting')), el('p', 'fb-hint-text', T('watch.setting_sub')));
  const seg = el('div', 'seg');
  seg.setAttribute('role', 'group');
  seg.setAttribute('aria-label', T('watch.setting'));
  [['on', true], ['off', false]].forEach(([k, v]) => {
    const b = el('button', '', T(`watch.${k}`));
    b.type = 'button';
    b.title = T(`watch.${k}_title`);
    b.setAttribute('aria-pressed', String(v === on));
    b.disabled = !state || !Office.agent.running;
    b.onclick = () => { if (v !== on) act('watch', { on: v }, b); };
    seg.appendChild(b);
  });
  box.append(head, seg);
  return box;
}

/**
 * Every temperature: the chips' and Unraid's disks' (a sleeping disk: none, said) — each with its heat source's id where
 * it is one (the picture's and the zones' name for it), and the zones it counts in
 */
function sensorRows() {
  const out = [];
  const src = setup().sources || [];
  chips().forEach((c) => c.temps.forEach((x) => {
    const sid = (src.find((y) => y.chip === c.name && y.hwmon === c.hwmon && y.n === x.n) || {}).id || null;
    out.push({ id: sid, name: sid ? srcLabel(src.find((y) => y.id === sid)) : x.label || T('sensors.row', { n: x.n }), label: x.label, chip: c, kind: c.kind, c: x.c,
      key: `${c.address}:${x.n}`, feature: `temp${x.n}` });
  }));
  (S().disks || []).forEach((d) => out.push({ id: d.name, name: srcLabel({ kind: 'disk', id: d.name }), chip: null, kind: 'disk', c: d.temp, asleep: d.asleep, key: `disk:${d.name}`, disk: d.name,
    rot: d.rotational !== false, warn: num(d.warn), crit: num(d.crit) }));
  out.forEach((x) => { x.zones = x.id ? (setup().zones || []).filter((z) => (z.sources || []).some((y) => y.id === x.id)) : []; });
  return out;
}
const nameOf = (c, feature) => (S().names || []).find((x) => x.address === c.address && x.chip === c.name && x.feature === feature) || null;
function sensorsBar() {
  sensorBar = sensorBar || Office.filterBar({ key: 'fanboy.sensors', label: T('sensors.filter_label'), onChange: () => sw.render() });
  return sensorBar;
}
/** A sensor row's kind as the tile «Warmest spots» counts it */
function rowKind(x) {
  if (x.kind === 'disk') return x.rot === false ? 'ssd' : 'hdd';
  return { nvme: 'ssd', cpu: 'cpu', board: 'board', ram: 'ram' }[x.kind] || 'other';
}
/** A sensor in alarm: over its limit (the zone's or its kind's), or the one the control's alarm names */
function inAlarm(x) {
  const a = control().running && control().alarm ? control().alarm : null;
  if (a && ['hot', 'sensor'].includes(a.code) && a.params && a.params.source && [x.name, x.label, x.disk].includes(a.params.source)) return true;
  // a limit the user set for it in a zone, or Unraid's own for a disk — never one made up
  const own = x.zones.map((z) => ((z.sources || []).find((y) => y.id === x.id) || {}).limit).find((l) => num(l) !== null);
  const lim = num(own) ?? null;
  if (num(x.c) !== null && num(x.warn) !== null && x.c >= x.warn) return true;        // a disk over Unraid's own warning
  return num(x.c) !== null && num(lim) !== null && x.c >= lim;
}
function sensorRow(x, place) {
  const meta = el('div', 'row-meta');
  meta.appendChild(chipOfText('quiet', T(`kind.${x.kind}`), T('kind_title')));
  const n = x.chip ? nameOf(x.chip, x.feature) : null;
  if (n && n.label) meta.appendChild(chipOfText('quiet', `«${n.label}»`, T('hints.names')));
  if (n && n.ignore) meta.appendChild(chipOfText('quiet', T('hints.names_hidden'), T('hints.names')));
  if (x.asleep) meta.appendChild(chipOfText('quiet', T('sensors.asleep'), T('sensors.asleep_title')));
  else if (x.c === null || x.c === undefined) meta.appendChild(chipOfText('quiet', T('sensors.no_value'), T('sensors.no_value_title')));
  if (inAlarm(x)) meta.appendChild(chipOfText('warn', T('sensors.too_warm'), T('sensors.too_warm_title')));
  x.zones.forEach((z) => meta.appendChild(chipOfText('accent', z.name, T('sensors.in_zone'))));
  const v = el('div', 'fb-value', x.c === null || x.c === undefined ? '–' : T('temp', { c: fmt.number(x.c) }));
  return unfoldRow(`temp:${x.key}`, place ? { place: `temp:${x.key}` } : {}, x.name, meta, v,
    x.chip ? [[T('tech.chip'), `${x.chip.name} · ${x.chip.address}`], [T('tech.sensor'), `${x.feature}_input · ${x.chip.hwmon}`]]
      : [[T('tech.disk'), x.disk], [T('tech.from'), 'disks.ini']]);
}
/**
 * The heat sources: first those that count — grouped by zone, the warmest of each first (before the setup: Unraid's disks,
 * the CPU and the NVMe) — and any in alarm; every other sensor behind one fold. The filter bar goes over both.
 */
function sensorsSection() {
  const s = el('section', 'section');
  const bar = sensorsBar();
  s.append(Office.sectionHead(T('sensors.title'), T('sensors.sub'), { place: 'sensors.title' }), bar.el);
  const f = bar.read();
  const all = sensorRows();
  const fit = (x) => Office.fits(f.words, [x.name, T(`kind.${x.kind}`), ...x.zones.map((z) => z.name), x.chip ? ((nameOf(x.chip, x.feature) || {}).label || '') : '']);
  const fits = all.filter(fit);
  const zones = setup().zones || [];
  const warmFirst = (a, b) => (num(b.c) ?? -1e9) - (num(a.c) ?? -1e9);
  const first = new Set();
  const list = el('div', 'box');
  const placed = new Set();
  const put = (title, sub, rows) => {
    if (!rows.length) return;
    list.appendChild(group(title, sub));
    rows.slice().sort(warmFirst).forEach((x) => {
      first.add(x);
      list.appendChild(sensorRow(x, !placed.has(x.key)));
      placed.add(x.key);
    });
  };
  put(T('sensors.group_alarm'), '', fits.filter(inAlarm));
  // each sensor once, in its first group (too warm first; a source counting in two zones under the first): one row, one
  // fold state — its zone labels say where else it counts
  if (zones.length) zones.forEach((z) => put(z.name, T('sensors.group_zone'), fits.filter((x) => x.zones.includes(z) && !first.has(x))));
  else {
    // before the setup: grouped by kind — hard disks, SSD/NVMe, CPU, RAM (the board's and the rest's many sensors folded)
    ['hdd', 'ssd', 'cpu', 'ram'].forEach((k) => put(T(`hk.${k}`), '', fits.filter((x) => rowKind(x) === k && !first.has(x))));
  }
  const rest = fits.filter((x) => !first.has(x)).map((x) => sensorRow(x, !placed.has(x.key)));
  if (!list.children.length) list.appendChild(el('p', 'empty', !all.length ? T('sensors.none') : fits.length ? T('sensors.none_first') : T('sensors.none_fit')));
  s.appendChild(list);
  const more = moreFold('sensors-more', rest, T('sensors.more', { n: rest.length }), f.words.length > 0);
  if (more) s.appendChild(more);
  bar.done(fits.length, all.length, sw.note());
  return s;
}

// ------------------------------------------------------------------ the curves and what happened lately
const SVGNS = 'http://www.w3.org/2000/svg';
function svg(tag, attrs, text) {
  const n = document.createElementNS(SVGNS, tag);
  Object.entries(attrs || {}).forEach(([k, v]) => n.setAttribute(k, v));
  if (text !== undefined) n.textContent = text;
  return n;
}
/**
 * A zone's curve, still: temperature → level, «now» marked, the lowest steady level dashed — drawn here (cheap, no
 * dragging: that is the setup's zones step)
 */
function curveStill(zid, unit, nowC) {
  const c = (setup().curves || {})[zid];
  if (!c || !Array.isArray(c.points) || c.points.length < 2) return null;
  const pts = c.points.map((p) => [p[0], p[1]]);
  const [lo, hi] = c.scale || [pts[0][0], pts[pts.length - 1][0]];
  const tMin = Math.floor(Math.min(lo - 10, pts[0][0] - 2) / 5) * 5, tMax = Math.ceil(Math.max(hi + 10, pts[pts.length - 1][0] + 2) / 5) * 5;
  const vMax = unit === 'rpm' ? Math.max(...pts.map((p) => p[1]), 1) * 1.1 : 100;
  const W = 320, H = 150, L = 34, R = 8, Tp = 8, B = 22;
  const x = (tc) => L + (Math.min(tMax, Math.max(tMin, tc)) - tMin) / (tMax - tMin) * (W - L - R);
  const y = (v) => Tp + (1 - v / vMax) * (H - Tp - B);
  const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'fb-chart', role: 'img', 'aria-label': T('curves.curve_label') });
  for (let v = 0; v <= vMax; v += unit === 'rpm' ? Math.ceil(vMax / 4 / 100) * 100 : 25) {
    s.appendChild(svg('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), class: 'fb-grid' }));
    s.appendChild(svg('text', { x: L - 4, y: y(v) + 3, class: 'fb-tick', 'text-anchor': 'end' }, unit === 'rpm' ? fmt.number(v) : `${v}`));
  }
  for (let tc = tMin; tc <= tMax; tc += 10) s.appendChild(svg('text', { x: x(tc), y: H - 6, class: 'fb-tick', 'text-anchor': 'middle' }, `${tc}°`));
  const line = [[tMin, pts[0][1]], ...pts, [tMax, pts[pts.length - 1][1]]].map((p) => `${x(p[0]).toFixed(1)},${y(p[1]).toFixed(1)}`).join(' ');
  s.appendChild(svg('polyline', { points: line, class: 'fb-curve-line' }));
  if (num(nowC) !== null) {
    s.appendChild(svg('line', { x1: x(nowC), x2: x(nowC), y1: Tp, y2: H - B, class: 'fb-now' }));
    s.appendChild(svg('text', { x: Math.min(W - R - 2, x(nowC) + 4), y: Tp + 10, class: 'fb-tick fb-now-text', 'text-anchor': x(nowC) > W * 0.7 ? 'end' : 'start' },
      T('curves.now', { c: fmt.number(nowC) })));
  }
  return s;
}
/** A time axis's ticks for the period: every so long, at round local hours; labelled «−2 h», «14:00» or «Mo 12:00» */
function timeTicks(from, now) {
  const step = { 1: 900, 3: 3600, 6: 7200, 12: 10800, 24: 21600, 72: 43200 }[period] || 7200;
  if (period <= 12) {
    const out = [];
    for (let t = now; t >= from - 1; t -= step) out.push([t, t === now ? T('curves.now_short') : period === 1 ? T('curves.ago_min', { n: Math.round((now - t) / 60) }) : T('curves.ago_h', { n: Math.round((now - t) / 3600) })]);
    return out;
  }
  const out = [];
  const d = new Date(from * 1000);
  d.setMinutes(0, 0, 0);
  const hours = step / 3600;
  d.setHours(Math.ceil(d.getHours() / hours) * hours);
  let wd = null;
  try { wd = new Intl.DateTimeFormat(Office.locale || Office.lang || 'en', { weekday: 'short' }); } catch (e) { wd = null; }
  for (let t = d.getTime() / 1000; t <= now; t += step) {
    out.push([t, period > 24 && wd ? `${wd.format(new Date(t * 1000))} ${fmt.time(t)}` : fmt.time(t)]);
  }
  return out;
}
/** The zone's chosen period: the warmest temperature and the level — a point a minute, older ones a 5-minute span each */
function historyChart(zid) {
  const h = historyNow();
  const pts = ((h && h.zones && h.zones[zid]) || []).filter((p) => Array.isArray(p) && p.length === 4);
  const now = histNow(h);
  const want = now - period * 3600;
  const shown = pts.filter((p) => p[0] >= want);
  if (!h) { const p = el('p', 'fb-wait'); p.append(wave(), el('span', '', T('curves.history_loading'))); return p; }
  if (shown.length < 2) return el('p', 'fb-hint-text', T('curves.history_none'));
  // less than the period: the chart shows what there is, the caption says since when
  const from = Math.max(want, shown[0][0] - 60);
  const W = 320, H = 150, L = 34, R = 34, Tp = 8, B = 22;
  const temps = shown.map((p) => p[1]).filter((v) => num(v) !== null);
  const tLo = Math.floor((Math.min(...temps, 30) - 2) / 5) * 5, tHi = Math.ceil((Math.max(...temps, 40) + 2) / 5) * 5;
  const x = (ts) => L + (ts - from) / ((now - from) || 1) * (W - L - R);
  const yT = (c) => Tp + (1 - (c - tLo) / (tHi - tLo)) * (H - Tp - B);
  const yL = (v) => Tp + (1 - v / 100) * (H - Tp - B);
  const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'fb-chart', role: 'img', 'aria-label': T('curves.history_label', { period: T(`curves.p${period}`) }) });
  for (let c = tLo; c <= tHi; c += Math.max(5, Math.round((tHi - tLo) / 4 / 5) * 5)) {
    s.appendChild(svg('line', { x1: L, x2: W - R, y1: yT(c), y2: yT(c), class: 'fb-grid' }));
    s.appendChild(svg('text', { x: L - 4, y: yT(c) + 3, class: 'fb-tick fb-t', 'text-anchor': 'end' }, `${c}°`));
  }
  [0, 50, 100].forEach((v) => s.appendChild(svg('text', { x: W - R + 4, y: yL(v) + 3, class: 'fb-tick fb-l' }, `${v} %`)));
  const ticks = timeTicks(from, now).filter(([t]) => t >= from - 1);
  const room = (W - L - R) / Math.max(1, ticks.length);
  ticks.forEach(([t, label], i) => {
    if (room < 34 && i % 2) return;                 // narrow: every other label
    s.appendChild(svg('line', { x1: x(t), x2: x(t), y1: H - B, y2: H - B + 3, class: 'fb-grid' }));
    s.appendChild(svg('text', { x: Math.min(W - R, Math.max(L, x(t))), y: H - 6, class: 'fb-tick', 'text-anchor': 'middle' }, label));
  });
  const full = num(h.full) || 43200;
  const path = (ys) => {
    let d = '', pen = false, last = null;
    shown.forEach((p) => {
      const v = ys(p);
      // a gap in the record breaks the line: more than 3 minutes among the minutes, 15 among the 5-minute spans
      if (v === null || (last !== null && p[0] - last > (p[0] < now - full ? 900 : 180))) { pen = false; }
      if (v !== null) { d += `${pen ? 'L' : 'M'}${x(p[0]).toFixed(1)} ${v.toFixed(1)}`; pen = true; }
      last = p[0];
    });
    return d;
  };
  s.appendChild(svg('path', { d: path((p) => (num(p[2]) === null ? null : yL(p[2]))), class: 'fb-level-line' }));
  s.appendChild(svg('path', { d: path((p) => (num(p[1]) === null ? null : yT(p[1]))), class: 'fb-temp-line' }));
  if (shown[0][0] > want + 600) {
    const box = el('div', 'fb-history');
    box.append(s, el('p', 'fb-since', T('curves.since', { time: durationText(now - shown[0][0]) })));
    return box;
  }
  return s;
}
/** The period's choice: one for every zone (office.css .seg) */
function periodChoice() {
  const box = el('div', 'seg fb-period');
  box.setAttribute('role', 'group');
  box.setAttribute('aria-label', T('curves.period'));
  PERIODS.forEach((p) => {
    const b = el('button', '', T(`curves.p${p}`));
    b.type = 'button';
    b.setAttribute('aria-pressed', p === period ? 'true' : 'false');
    b.dataset.own = '1';
    b.onclick = () => {
      if (p === period) return;
      period = p;
      Office.store('fanboy.period', p === PERIOD_DEFAULT ? null : String(p));
      if (p > 24) { longAt = 0; loadLong(); }
      drawn = '';
      redraw();
    };
    box.appendChild(b);
  });
  return box;
}
function curvesSection() {
  const s = el('section', 'section');
  const go = el('a', 'btn small', T('curves.change'));
  go.href = `#/${ID}/setup/zones`;
  s.appendChild(Office.sectionHead(T('curves.title'), T('curves.sub'), (setup().zones || []).length ? go : null, { place: 'curves.title' }));
  const zones = setup().zones || [];
  if (!zones.length) {
    s.appendChild(el('p', 'empty', T('curves.none')));
    return s;
  }
  const pick = el('div', 'fb-period-row');
  pick.append(el('span', 'fb-field-label', T('curves.period')), periodChoice());
  s.appendChild(pick);
  const grid = el('div', 'fb-curves');
  const now = new Map((control().zones || []).map((z) => [z.id, z]));
  zones.forEach((z) => {
    const cz = now.get(z.id);
    const card = Office.place(`curve:${z.id}`, el('div', 'fb-curve-card'));
    card.appendChild(el('h3', 'fb-head', z.name || z.id));
    const warm = cz && cz.warmest && num(cz.warmest.c) !== null ? cz.warmest : null;
    card.appendChild(el('p', 'fb-hint-text', warm && control().running
      ? T('curves.line_now', { where: srcLabel(warm), c: fmt.number(warm.c), level: num(cz.level) === null ? '–' : (cz.unit === 'rpm' ? T('pic.rpm', { rpm: fmt.number(Math.round(cz.level)) }) : T('value', { pct: Math.round(cz.level) })) })
      : T(nobody() ? 'curves.line_nobody' : 'curves.line_bios')));
    const two = el('div', 'fb-curve-two');
    const a = el('figure', 'fb-fig');
    const still = curveStill(z.id, z.unit, warm ? (num(warm.mapped) ?? warm.c) : null);
    if (still) a.appendChild(still);
    a.appendChild(el('figcaption', '', T('curves.curve_caption')));
    const b = el('figure', 'fb-fig');
    b.append(historyChart(z.id), el('figcaption', 'fb-legend', ''));
    b.lastChild.append(el('span', 'fb-key fb-key-t', T('curves.key_temp')), el('span', 'fb-key fb-key-l', T('curves.key_level')));
    two.append(a, b);
    card.appendChild(two);
    grid.appendChild(card);
  });
  s.appendChild(grid);
  return s;
}

// ------------------------------------------------------------------ hints, drivers, the report
function toolChip(x) {
  if (x.kind === 'controller' && x.id === 'md12xx.fancontrol') return x.active ? chipOfText('quiet', T('hints.st_other_fans'), T('hints.st_other_fans_title')) : chipOfText('quiet', T('hints.st_idle'), T('hints.st_idle_title'));
  if (x.kind === 'controller' || x.kind === 'container') return x.active ? chipOfText('warn', T('hints.st_steering'), T('hints.st_steering_title')) : chipOfText('quiet', T('hints.st_idle'), T('hints.st_idle_title'));
  if (x.kind === 'driver') return x.loaded ? chipOfText('ok', T('hints.st_loaded'), T('hints.st_loaded_title')) : chipOfText('warn', T('hints.st_not_loaded'), T('hints.st_not_loaded_title'));
  return chipOfText('ok', T('hints.st_installed'), T('hints.st_installed_title'));
}
const hintHead = (text) => el('h3', 'fb-head', text);
function hintParas(box, prefix) {
  for (let i = 1; has(`${prefix}.${i}`); i++) box.appendChild(el('p', 'fb-hint-text', T(`${prefix}.${i}`)));
}
function hintsSection() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('hints.title'), T('hints.sub'), { place: 'hints.title' }));
  const det = keepFold(el('details', 'fb-hints'), 'hints', !!S().hints_open);
  det.appendChild(el('summary', '', T('hints.fold')));
  const body = el('div', 'fb-hints-body');
  body.appendChild(hintHead(T('hints.there_title')));
  const list = el('div', 'box');
  tools().filter((x) => x.installed || x.active).forEach((x) => {
    const r = Office.place(`plugin:${x.id}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', x.name));
    const meta = el('div', 'row-meta');
    meta.append(el('span', '', T('hints.by', { author: x.author })), toolChip(x));
    main.appendChild(meta);
    if (x.id === 'md12xx.fancontrol') main.appendChild(el('div', 'row-detail', T('hints.waz')));
    r.append(main);
    list.appendChild(r);
  });
  if (!list.children.length) list.appendChild(el('p', 'empty', T('hints.none_there')));
  if (state) {
    const r = el('div', 'row nocheck fb-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('hints.acpi')));
    const meta = el('div', 'row-meta');
    meta.append(el('span', 'mono', 'acpi_enforce_resources=lax'),
      S().acpi_lax ? chipOfText('ok', T('hints.acpi_set'), T('hints.acpi_set_title')) : chipOfText('quiet', T('hints.acpi_unset'), T('hints.acpi_unset_title')));
    main.appendChild(meta);
    r.appendChild(main);
    list.appendChild(r);
  }
  // round 5: how fresh Unraid keeps the disks' temperatures (poll_attributes) — the user changes it, never Fanboy
  const poll = num(S().poll);
  if (poll !== null && poll > 300) {
    const r = el('div', 'row nocheck fb-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('hints.poll', { min: Math.round(poll / 60) })));
    main.appendChild(el('div', 'row-detail', T('hints.poll_how')));
    // round 6: such a value is a lower bound — his zones count it warmer by what it may have risen since
    if (num(S().disk_allow) > 0) main.appendChild(el('div', 'row-detail fb-strong', T('hints.poll_add', { min: Math.round(poll / 60), add: S().disk_allow })));
    r.appendChild(main);
    list.appendChild(r);
  }
  if ((S().twins || []).length) {
    const r = el('div', 'row nocheck fb-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('hints.twins', { chips: S().twins.join(', ') })));
    r.appendChild(main);
    list.appendChild(r);
  }
  const names = S().names || [];
  if (names.length) {
    const r = el('div', 'row nocheck fb-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('hints.names')));
    main.appendChild(el('div', 'row-meta', T('hints.names_count', { named: names.filter((x) => x.label).length, hidden: names.filter((x) => x.ignore).length })));
    r.appendChild(main);
    list.appendChild(r);
  }
  body.appendChild(list);
  const fits = (S().suggest || []).map((id) => tools().find((x) => x.id === id)).filter(Boolean);
  if (fits.length) {
    body.appendChild(hintHead(T('hints.fits_title')));
    const box = el('div', 'box');
    fits.forEach((x) => {
      const r = Office.place(`fits:${x.id}`, el('div', 'row nocheck fb-row'));
      const main = el('div', 'row-main');
      main.appendChild(el('div', 'row-name text', x.name));
      const meta = el('div', 'row-meta');
      meta.appendChild(el('span', '', T('hints.by', { author: x.author })));
      main.appendChild(meta);
      if (has(`plugin.${x.id}`)) main.appendChild(el('div', 'row-detail', T(`plugin.${x.id}`)));
      const chip = { 'it87-driver': 'ite', 'nct6687-driver': 'nuvoton' }[x.id];
      if (chip && S().board_hint === chip) main.appendChild(el('div', 'row-detail', T(`hints.board_${chip}`, { maker: S().board_maker })));
      main.appendChild(el('div', 'row-detail', T('hints.apps_how', { name: x.name, author: x.author })));
      const go = el('a', 'btn small', T('hints.apps'));
      const href = Office.safeHref('/Apps');
      if (href) go.href = href;
      go.title = T('hints.apps_title');
      r.append(main, go);
      box.appendChild(r);
    });
    body.appendChild(box);
  }
  const drivers = driversBlock();
  if (drivers) body.append(Office.place('drivers.title', hintHead(T('drivers.title'))), drivers);
  body.appendChild(hintHead(T('hints.why_title')));
  const why = el('ol', 'fb-hint-list');
  for (let i = 1; has(`hints.why.${i}`); i++) why.appendChild(el('li', '', T(`hints.why.${i}`)));
  body.appendChild(why);
  body.appendChild(hintHead(T('hints.bios_title')));
  hintParas(body, 'hints.bios');
  body.appendChild(hintHead(T('hints.hub_title')));
  hintParas(body, 'hints.hub');
  body.appendChild(hintHead(T('hints.clash_title')));
  hintParas(body, 'hints.clash');
  body.appendChild(hintHead(T('hints.ipmi_title')));
  hintParas(body, 'hints.ipmi');
  const bar = el('div', 'toolbar');
  bar.appendChild(reportButton(true));
  body.appendChild(bar);
  det.appendChild(body);
  s.appendChild(det);
  return s;
}
function driversBlock() {
  const d = S().drivers || {};
  const mine = d.mine || [];
  const s = situation();
  if (!mine.length && !['no_output', 'driver_missing'].includes(s)) return null;
  const box = el('div', 'fb-drivers');
  box.appendChild(el('p', 'fb-hint-text', T('drivers.text')));
  box.appendChild(el('p', 'fb-hint-text', T('drivers.self')));
  const last = d.last && Array.isArray(d.last.steps) ? d.last : null;
  if (last) {
    const list = el('div', 'box');
    last.steps.filter((x) => has(`drivers.r.${x.result}`)).forEach((x) => {
      const r = el('div', 'row nocheck fb-row');
      const main = el('div', 'row-main');
      main.appendChild(el('div', 'row-name text', T(`drivers.r.${x.result}`, { module: x.module, n: x.outputs || 0 })));
      r.appendChild(main);
      list.appendChild(r);
    });
    box.appendChild(list);
    if (last.acpi) box.appendChild(el('p', 'callout warn', T('drivers.acpi')));
    else if (!last.kept) box.appendChild(el('p', 'fb-hint-text', T('drivers.none')));
  }
  if (mine.length) box.appendChild(el('p', 'fb-hint-text', T('drivers.mine', { modules: mine.join(', ') })));
  const bar = el('div', 'toolbar');
  if (!mine.length && d.can !== false) {
    bar.appendChild(button(T('drivers.load'), T('drivers.load_tip'), (b) => act('drivers_load', {}, b, (j) => Office.toast(j.kept ? T('drivers.kept_toast', { module: j.kept }) : T('drivers.none_toast'), !j.kept))));
  }
  if (mine.length) bar.appendChild(button(T('drivers.undo'), T('drivers.undo_tip'), (b) => act('drivers_undo', {}, b, () => Office.toast(T('drivers.undone_toast')))));
  if (bar.children.length) box.appendChild(bar);
  return box;
}

// ------------------------------------------------------------------ above the tiles: what holds now, plainly
/** The situation and the control's word: the alarm, the chip missing (with «Look now»), another controller, array stopped */
function paintBanner() {
  if (!banner) return;
  banner.replaceChildren();
  if (!state) return;
  const c = control();
  const w = who();
  const s = situation();
  const st = S().situation || {};
  const box = (cls, text, data) => { const p = el('div', `callout ${cls} fb-callout`); p.appendChild(el('p', '', text)); Object.entries(data || {}).forEach(([k, v]) => { p.dataset[k] = v; }); banner.appendChild(p); return p; };
  if (c.running && c.alarm && has(`alarm.${c.alarm.code}`)) {
    // a standing fan alone in its zone: «… sets it to 100 %» (round 4: the alarm acts per zone)
    const code = c.alarm.code === 'stands' && c.alarm.params && c.alarm.params.alone ? 'stands_alone' : c.alarm.code;
    box('warn', T(`alarm.${code}`, { fan: '', source: '', c: '', zone: '', ...(c.alarm.params || {}) }), { alarm: c.alarm.code });
  }
  // round 6: a fan he doesn't steer stands — told (after 2 minutes while something was warm; a CPU cooler or pump at once)
  if (idle().on) {
    Object.entries(idle().tachs || {}).filter(([, x]) => x && x.state === 'alarm').forEach(([tid, x]) => {
      const warm = idle().warm;
      const fan = tachName(tid) || x.name;
      box('warn', x.strict ? T('alarm.bios_stands', { fan }) : warm ? T('watch.banner', { fan, source: warmName(warm), c: num(warm.c) ?? '' }) : T('watch.banner_cool', { fan }), { watch: 'stands' });
    });
  }
  if (['chip_missing', 'chip_gone', 'crashed'].includes(w.why)) {
    const p = box('warn', T(`control.${w.why}`), { why: w.why });
    p.appendChild(button(T('control.look_now'), T('control.look_now_tip'), (b) => act('look_now', {}, b, () => Office.toast(T('control.looked')))));
  }
  if (w.why === 'array_stopped' || (c.armed && c.why === 'array_stopped')) box('warn', T('control.array_stopped'), { why: 'array_stopped' });
  if ((S().twins || []).length) box('warn', T('situation.twins', { chips: S().twins.join(', ') }), { situation: 'twins' });
  if (s === 'other') {
    box('warn', T('situation.other', { who: otherNames() }), { situation: s });
  } else if (s === 'driver_missing' && w.why !== 'chip_gone') {      // round 5: «until then the BIOS» isn't true then — the banner above says it
    box('warn', T('situation.driver_missing', { plugins: (st.plugins || []).join(', '), modules: (st.modules || []).join(', ') }), { situation: s });
  } else if (s === 'ipmi') {
    const p = box('warn', T('situation.ipmi'), { situation: s });
    p.appendChild(reportButton(true));
  } else if (s === 'no_output') {
    // round 5: in a virtual machine the fans are the host's (a hint only — a VM with a chip passed through steers on)
    box('warn', T('situation.no_output') + (S().vm ? ' ' + T('situation.vm') : ''), { situation: s });
  } else if (s === 'ok' && w.why === 'fixed') {
    // round 7 (F3): nobody steers — not the BIOS: the outputs stand on a fixed value someone left (an uninstalled plugin)
    box('warn', T('situation.fixed', { list: fixedList(w.fixed) }), { situation: 'fixed' });
  } else if (s === 'ok' && (w.fixed || []).length && !banner.children.length) {
    box('warn', T('situation.fixed_some', { list: fixedList(w.fixed) }), { situation: 'fixed_some' });
  } else if (s === 'ok' && !banner.children.length && !(page === 'setup' && (stepRuns() || showRuns() || ((S().now || {}).safety || []).length))) {
    // (while the setup holds outputs, «the BIOS steers every fan» would be untrue — the setup's strip says what runs)
    box('', steers() ? T(cpuGiven() ? 'situation.steers_cpu' : 'situation.steers', { n: w.zones || 0 }) : c.armed === false && (setup().zones || []).length ? T('situation.not_armed') : T('situation.ok'), { situation: s });
  }
  // round 6: a show whose give-back didn't read back as before (the fan may sit at 100 %)
  const sh = page === 'setup' ? null : showNow();        // the setup page's strip says how a show or a step ended
  if (sh && sh.status !== 'running' && sh.status !== 'starting' && sh.why === 'give_back') box('warn', T('show.give_back_failed', { fan: fanName(sh.fan) }), { show: 'give_back' });
  if (sh && sh.status !== 'running' && sh.status !== 'starting' && ['quiet_warm', 'no_restart'].includes(sh.why) && sh.quiet_stop) {
    box('warn', T(`show.${sh.why}`, { what: sh.quiet_stop.what || '', c: num(sh.quiet_stop.c) === null ? '' : fmt.number(sh.quiet_stop.c) }), { show: sh.why });
  }
  const s2 = step();
  if (s2 && s2.status === 'stopped' && ['page_gone', 'interrupted'].includes(s2.why) && page === '') {
    box('warn', T(`step.ended.${s2.why}`), { step: s2.why });
  }
}

// ------------------------------------------------------------------ the setup page (the wizard)
let setupSw = null;
function setupSwitcher() {
  if (setupSw) return setupSw;
  setupSw = Office.sections({
    id: `${ID}/setup`,
    key: 'fanboy.setup.tile',
    bodyId: 'fb-setup-steps',
    grid: 'stats fb-steps',
    default: '',
    tiles: () => (state ? STEPS.map((k, i) => ({ id: k, key: `step.${k}`, place: `setup.tile.${k}`, tile: (a) => stepTile(k, i, a), section: () => stepSection(k) })) : []),
    wants: (sub) => STEPS.includes(sub),
    onPick: () => { drawn = ''; },
  });
  return setupSw;
}
/** A step's tile: its number and name, what stands (done, open, running) */
function stepTile(k, i, a) {
  const su = setup();
  const here = stepAtHand() === k;
  const fans = su.fans || [];
  const found = fans.filter((f) => ['tach', 'silent', 'unclear'].includes(f.found));
  const ours = found.filter((f) => isOurs(f) && f.found === 'tach');
  const s = step();
  let value = '';
  const sub = [];
  if (k === 'detect') { value = T('step.v.outputs', { n: (su.outputs || []).length }); }
  if (k === 'test') {
    // round 8: «4 von 6 getestet · 3 zugeordnet»
    const done = (su.outputs || []).filter((id) => { const f = fans.find((x) => x.id === id); return f && (f.found || leftAlone(f)); }).length;
    value = running(s) && s.kind === 'test_up' ? T('step.v.running') : T('step.v.tested', { n: done, m: (su.outputs || []).length });
    sub.push(T('step.v.assigned', { n: (su.outputs || []).filter((id) => { const f = fans.find((x) => x.id === id); return f && (f.role || f.found === 'empty'); }).length }));
  }
  if (k === 'measure') value = running(s) && s.kind === 'measure' ? T('step.v.running') : ours.length ? T('step.v.of', { n: ours.filter((f) => f.measured || f.no_turn).length, m: ours.length }) : '–';
  if (k === 'zones') value = (su.zones || []).length ? T('step.v.zones', { n: su.zones.length }) : '–';
  if (k === 'dry') value = dry ? T('step.v.seen') : '–';
  if (k === 'take') value = steers() ? T('who.fanboy', { n: who().zones || 0 }) : su.armed ? T('step.v.armed') : T('who.bios');
  if (here && !(k === 'take' && (steers() || su.armed))) sub.push(T('step.here'));
  return Office.stat({ label: T('step.n', { n: i + 1, name: T(`step.${k}`) }), value, sub, alert: k === 'take' && control().running && !!control().alarm, ...a });
}
function stepSection(k) {
  const s = el('section', 'section fb-step');
  s.dataset.step = k;
  s.appendChild(Office.sectionHead(T(`step.${k}`), T(`step.${k}_sub`), { place: `step.${k}` }));
  noRun = (situation() === 'other' || !!control().running) && ['test', 'measure'].includes(k);      // nothing that writes beside another
  const body = ({ detect: detectStep, test: testStep, measure: measureStep, zones: zonesStep, dry: dryStep, take: takeStep })[k]();
  noRun = false;
  s.appendChild(body);
  return s;
}
/** «Next»: the next step alone */
function nextButton(k) {
  const i = STEPS.indexOf(k);
  if (i < 0 || i >= STEPS.length - 1) return null;
  const b = el('button', 'btn small plain', T('step.next', { name: T(`step.${STEPS[i + 1]}`) }));
  b.type = 'button';
  b.onclick = () => { setupSwitcher().pick(STEPS[i + 1]); drawn = ''; redraw(); };
  return b;
}
const bar = (...nodes) => { const b = el('div', 'toolbar fb-bar'); nodes.filter(Boolean).forEach((n) => b.appendChild(n)); return b; };
const para = (text, cls) => el('p', cls || 'fb-hint-text', text);
/** A small wheel for a card (the setup's): turning as the output turns now */
function cardWheel(w) {
  const A = art();
  if (!A) return el('span', 'fb-wheel-wait');
  return A.wheel({ px: 64, air: false, ...w }).el;
}

// 1 — detect
function detectStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const s = situation();
  if (!(su.outputs || []).length) {
    // the banner above says why (never the same text twice on a page); here only that there is nothing to set up
    box.appendChild(para(T('step.nothing')));
    const go = el('a', 'btn small', T('detect.hints'));
    go.href = `#/${ID}/hints`;
    box.appendChild(bar(go, reportButton(true)));
    return box;
  }
  const list = el('div', 'fb-cards');
  (su.outputs || []).forEach((id) => {
    const o = outputNow(id);
    const b = (su.before || {})[id] || {};
    const card = Office.place(`detect:${id}`, el('div', 'fb-card'));
    card.appendChild(cardWheel({ label: '', rpm: o && num(o.pct) ? 2000 * o.pct / 100 : 0, state: 'bios', mm: 120 }));
    const txt = el('div', 'fb-card-text');
    txt.appendChild(el('div', 'fb-card-name', T('out.name', { n: (idParts(id) || {}).output })));
    txt.appendChild(el('div', 'fb-card-line', o ? T(`detect.mode.${o.mode || 'unknown'}`, { pct: num(o.pct) ?? '–' }) : T('detect.gone')));
    if (b.fixed) txt.appendChild(el('div', 'fb-card-line fb-strong', T('detect.fixed', { pct: b.pct ?? '–' })));
    card.appendChild(txt);
    list.appendChild(card);
  });
  box.appendChild(list);
  const fixed = Object.values(su.before || {}).filter((b) => b && b.fixed).length;
  if (fixed) box.appendChild(para(T('detect.fixed_note', { n: fixed })));
  const tachs = chips().filter(offered).reduce((n, c) => n + (c.fans || []).length, 0);
  box.appendChild(para(T('detect.summary', { outputs: (su.outputs || []).length, inputs: tachs, sensors: (su.sources || []).length })));
  box.appendChild(bar(nextButton('detect')));
  return box;
}

// ------------------------------------------------------------------ what runs now, said in one place
/**
 * What the setup holds right now — ONE reading for the strip, the list and the panel (the owner, 2026-11-15: «the GUI
 * doesn't show which one is running»). Round 9's own word for it is `state.now` (which output is at full, which stand,
 * the pause and its seconds, an output set to full for safety); without it (an older agent's first state) the same is
 * read from the step and the show as round 8 has them.
 *   kind  test | show | measure | null     id  the output at hand     quiet  the others stand meanwhile
 *   stopped [ids]   pause {left, next} | null   queue [ids still to come]   safety [{id, why}]   since (page clock, ms)
 */
function runNow() {
  const n = S().now || {};
  const s = step(), x = showNow();
  const r = { kind: null, id: null, quiet: false, stopped: [], pause: null, queue: [], safety: [], since: null };
  if (showRuns()) { r.kind = 'show'; r.id = x.fan; r.quiet = !!x.quiet; }
  else if (running(s)) {
    r.kind = s.kind === 'measure' ? 'measure' : 'test';
    r.id = s.current || null;
    r.quiet = !!s.quiet && r.kind === 'test';
    r.queue = (s.fans || []).filter((id) => id !== s.current && !(s.done || []).includes(id));
  }
  const p = n.pause || (s && running(s) && s.pause) || null;
  if (p && r.kind) r.pause = { left: num(p.left), next: p.next || null };
  if (Array.isArray(n.stopped)) r.stopped = n.stopped.slice();
  else if (r.quiet && r.id && !r.pause) {
    r.stopped = (setup().outputs || []).filter((id) => { const o = outputNow(id); return id !== r.id && o && o.pct === 0 && o.mode === 'manual'; });
  }
  r.safety = (Array.isArray(n.safety) ? n.safety : []).filter((y) => y && y.id);
  // since when this output is at full: the page's own clock from the look that first showed it (a hint, not a promise)
  const key = r.kind && r.id && !r.pause ? `${r.kind}:${(s && s.id) || (x && x.id) || ''}:${r.id}` : '';
  if (key !== fullKey) { fullKey = key; fullFrom = key ? Date.now() : 0; }
  r.since = key ? fullFrom : null;
  return r;
}
let fullKey = '', fullFrom = 0;
const FULL_MAX = 60;            // seconds an output stays at full at most (the agent's FANBOY_UP_FOR / FANBOY_SHOW_FOR)
const safetyOf = (run, id) => run.safety.find((y) => y.id === id) || null;
/** What the page said last that must not get lost: a refusal, a click that came too early — until the next action */
let said = null;                // {text, warn}
function say(text, warn) {
  said = text ? { text, warn: warn !== false } : null;
  if (bench) bench.update();
}
/**
 * An action from the setup's list-and-panel: never dropped, never silent (the owner: a click «was swallowed»). Clicks
 * queue up behind each other; a refusal is said in the strip and stays there (and as the toast); the new state is drawn
 * in place. `quietly`: a field's save — it says nothing when it worked and doesn't clear what the strip says.
 */
let sending = Promise.resolve();
let sends = 0;
function send(action, body, opts) {
  const o = opts || {};
  sends++;
  if (!o.quietly) said = null;
  if (bench) bench.update();
  const job = sending.then(async () => {
    if (!Office.agent.running) { say(T('strip.away')); return null; }
    if (o.fresh) await Office.freshState(ID);
    const j = await Office.api.post(`${ID}.${action}`, body || {});
    if (!j.ok) {
      const text = Office.errorText(j.error, ID);
      Office.toast(text, true);
      said = { text: T('strip.refused', { why: text }), warn: true };
      return null;
    }
    if (j.state) state = j.state;
    if (o.done) await o.done(j);
    if (!j.state) await load(true);
    return j;
  }).catch(() => null).then((j) => {
    sends--;
    if (view) redraw();
    plan();
    return j;
  });
  sending = job;
  return job;
}
/** «Alles stoppen»: whatever the setup holds goes back — a step, a show, the fans standing for a quiet test */
function stopAll() {
  if (S().now) return send('stop_all', {}, { done: () => Office.toast(T('strip.stopped_toast')) });
  if (stepRuns()) return send('step_stop', {}, { done: () => Office.toast(T('step.stopped_toast')) });
  if (showRuns()) return send('show_stop', { fan: showNow().fan });
  return null;
}

// ------------------------------------------------------------------ small parts of the list-and-panel
/** A button of the bench: its click queued (send), off only with its reason said beside it — never by a silent gate */
function btn(text, cls, on, tip) {
  const b = el('button', cls === undefined ? 'btn small plain' : cls, text);
  b.type = 'button';
  b.dataset.own = '1';
  if (tip) b.title = tip;
  b.onclick = () => on(b);
  return b;
}
/** Draws a part anew only when what it shows changed (its signature) — buttons and fields under a hand stay the same nodes */
const sigs = new WeakMap();
function swap(node, sig, build) {
  const k = typeof sig === 'string' ? sig : JSON.stringify(sig);
  if (sigs.get(node) === k) return false;
  sigs.set(node, k);
  node.replaceChildren(...[].concat(build()).filter(Boolean));
  return true;
}
const setText = (node, text) => { if (node.textContent !== text) node.textContent = text; };
/** A select or text field in the office's field look (office.css .input — never the browser's white box) */
function inputEl(tag, type) {
  const n = el(tag, 'input small fb-input');
  if (type) n.type = type;
  n.dataset.own = '1';
  return n;
}
/** A wheel for an output as it is right now: fast = at full, standing = stopped by Fanboy, else as its fan turns */
function wheelNow(id, run) {
  const f = setupFan(id);
  const look = fanLook(f);
  const o = outputNow(id);
  const max = look.max || 2000;
  const full = (run.id === id && !run.pause && run.kind !== 'measure') || !!safetyOf(run, id);
  const plain = !look.look && look.colour === 'auto';
  const base = { mm: look.mm, blades: look.blades, min: look.min, max, look: look.look, colour: full && plain ? 'accent' : look.colour, badge: '' };
  if (full) return { ...base, rpm: max, state: 'run' };
  if (run.stopped.includes(id)) return { ...base, rpm: 0, state: 'run', badge: T('wheel.stands') };
  const live = f && f.found === 'tach' ? rpmNow(id, f.tachs) : null;
  return { ...base, rpm: live !== null ? live : o && num(o.pct) !== null ? max * o.pct / 100 : 0, state: 'bios' };
}
/** An output the user left with the BIOS without testing it («don't test — leave it with the BIOS»: role «unknown», no verdict) */
const leftAlone = (f) => !!f && !f.found && f.role === 'unknown';
/** What an output's test found, in a sentence (the list's second line, the panel's state) */
function foundLine(f) {
  const found = f ? f.found : null;
  if (found === 'tach') return (f.tachs || []).length > 1 ? T('test.hub', { list: f.tachs.join(', ') }) : T('test.tach', { n: (f.tachs || [])[0] });
  if (found === 'silent') return T('test.silent');
  if (found === 'empty') return T('test.empty');
  if (found === 'unclear') return T('test.unclear');
  if (found === 'ask') return T('test.ask');
  if (found === 'ignored' || found === 'inverted') return T(`test.${found}`);
  return leftAlone(f) ? T('test.left') : T('test.not_yet');
}
/** An output still open in step 2: not tested (and not left with the BIOS), a question unanswered, or found without a role */
function openWhy(id) {
  const f = setupFan(id);
  const found = f ? f.found : null;
  if (!found) return leftAlone(f) ? '' : 'test';
  if (['ask', 'unclear'].includes(found)) return 'ask';
  if (['tach', 'silent'].includes(found) && !f.role) return 'role';
  return '';
}
/** The ONE label of a row: what is with this output right now — [chip class, text, tip] */
function rowLabel(id, run) {
  const f = setupFan(id);
  const found = f ? f.found : null;
  if (safetyOf(run, id)) return ['warn', T('label.safety'), T('label.safety_tip')];
  if (run.id === id && !run.pause && run.kind) return ['solid', T('label.now'), T('label.now_tip')];
  if (run.stopped.includes(id)) return ['quiet', T('label.stands'), T('label.stands_tip')];
  if (run.kind === 'test' && run.queue.includes(id)) return ['quiet', T('label.waits'), T('label.waits_tip')];
  if (found === 'ignored' || found === 'inverted') return ['warn', T(`out.${found}`), T(`out.${found}_title`)];
  if (found === 'empty') return ['quiet', T('label.empty'), T('label.empty_tip')];
  if (found === 'ask' || found === 'unclear') return ['warn', T('label.ask'), T('label.ask_tip')];
  if (f && f.role && found) return [ROLES_BIOS.includes(f.role) && !f.cpu_own ? 'quiet' : 'accent', T(`role.${f.role}`), isOurs(f) ? T('label.his_tip') : T('label.bios_tip')];
  if (found) return ['outline', T('label.what'), T('label.what_tip')];
  if (leftAlone(f)) return ['quiet', T('label.left'), T('label.left_tip')];
  return ['quiet', T('label.untested'), T('label.untested_tip')];
}

// ------------------------------------------------------------------ 2 — test and assign: the list and the panel
/**
 * Step 2 as ONE thing built once and patched in place (the owner's three tests, 2026-11-15: cards «jump», a click was
 * swallowed, nothing said what runs): the strip (what runs now, the last refusal, «Stop everything»), the quiet switch
 * with its honest hints, a compact list of every output and ONE panel for the output at hand — its big wheel, what it
 * is doing, its buttons, and everything that says what it is. A look that brings a new state changes words and wheels
 * where they stand: no node under a finger is replaced, no row changes its height, the wheels go on turning.
 */
let bench = null;
let sel = '';                   // the output the panel shows (the user's click; follows the running output unless he types)
let ranLast = '';               // the output that ran at the last look (the panel follows a NEW one only)
const heard = new Map();        // output → true («found») | false («heard nothing»): his answer while it ran, for a silent output
function benchMake() {
  const root = el('div', 'fb-bench');
  // the strip
  const strip = el('div', 'fb-strip');
  const stripWave = wave();
  const now = el('p', 'fb-strip-now');
  const note = el('p', 'fb-strip-note');
  note.setAttribute('role', 'status');
  note.setAttribute('aria-live', 'polite');
  const words = el('div', 'fb-strip-text');
  words.append(now, note);
  const main = el('div', 'fb-strip-main');
  main.append(stripWave, words);
  const meterBar = el('span', 'fb-meter-bar');
  const meter = el('div', 'fb-meter');
  meter.appendChild(meterBar);
  const since = el('span', 'fb-strip-since');
  const side = el('div', 'fb-strip-side');
  const clock = el('div', 'fb-strip-clock');
  clock.append(since, meter);
  const stripActs = el('div', 'fb-strip-acts');
  side.append(clock, stripActs);
  strip.append(main, side);
  // the quiet switch and its honest hints
  const quiet = Office.place('quiet.setting', el('div', 'fb-quiet'));
  const qHead = el('div', 'fb-quiet-head');
  const qSeg = el('div', 'seg');
  qSeg.setAttribute('role', 'group');
  qSeg.setAttribute('aria-label', T('quiet.setting'));
  const qBtn = {};
  [['on', true], ['off', false]].forEach(([k, v]) => {
    const b = btn(T(`quiet.${k}`), '', () => {
      if (v === quietOn()) return;
      Office.store('fanboy.quiet', v ? null : '0');       // a choice, kept per browser; a test already running keeps its own
      update();
    }, T(`quiet.${k}_title`));
    qBtn[k] = b;
    qSeg.appendChild(b);
  });
  qHead.append(el('h3', 'fb-head', T('quiet.setting')), qSeg);
  const qList = el('ul', 'fb-quiet-hints');
  ['hint_1', 'hint_2', 'hint_3'].forEach((k) => qList.appendChild(el('li', '', T(`quiet.${k}`))));
  const qOff = el('p', 'fb-quiet-off', T('quiet.off_note'));
  quiet.append(qHead, qList, qOff);
  // the list and the panel
  const list = el('div', 'fb-list');
  list.setAttribute('role', 'group');
  list.setAttribute('aria-label', T('list.label'));
  const slot = el('div', 'fb-panel-slot');
  const two = el('div', 'fb-two');
  two.append(list, slot);
  // what is still open, and the way on
  const next = el('div', 'fb-next');
  const nextText = el('div', 'fb-next-text');
  const nextActs = el('div', 'fb-next-acts');
  next.append(nextText, nextActs);
  root.append(strip, quiet, two, next);

  const items = new Map();      // output → its row
  const panels = new Map();     // output → its panel (built when first shown, kept: its fields, its picker)
  let ticker = null;
  let run = runNow();
  const benchRun = () => run;

  function item(id) {
    let it = items.get(id);
    if (it) return it;
    const b = el('button', 'fb-item');
    b.type = 'button';
    b.dataset.fan = id;
    const A = art();
    const wh = A ? A.wheel({ px: 40, air: false, rpm: 0 }) : null;
    const pic2 = el('span', 'fb-item-wheel');
    if (wh) pic2.appendChild(wh.el);
    const name = el('span', 'fb-item-name');
    const sub = el('span', 'fb-item-sub');
    const txt = el('span', 'fb-item-text');
    txt.append(name, sub);
    const lab = el('span', 'fb-item-label');
    b.append(pic2, txt, lab);
    b.onclick = () => { if (sel !== id) { sel = id; update(); } };
    it = { el: b, wh, name, sub, lab };
    items.set(id, it);
    return it;
  }
  function itemUpdate(id) {
    const it = item(id);
    const f = setupFan(id);
    const p = idParts(id);
    if (it.wh) it.wh.update({ ...wheelNow(id, run), badge: '' });       // the row's label says «stands»; the small wheel just stands
    setText(it.name, T('out.name', { n: p ? p.output : '?' }));
    const rpm = f && f.found === 'tach' ? rpmNow(id, f.tachs) : null;
    setText(it.sub, [f && f.name ? f.name : '', rpm !== null ? T('pic.rpm', { rpm: fmt.number(rpm) }) : ''].filter(Boolean).join(' · ') || (f && f.found ? '' : ' '));
    const [cls, text, tip] = rowLabel(id, run);
    swap(it.lab, [cls, text, run.id === id && !run.pause], () => {
      const c = chipOfText(cls, text, tip);
      if (cls === 'solid') { c.classList.add('running'); c.prepend(wave()); }
      return c;
    });
    it.el.setAttribute('aria-pressed', sel === id ? 'true' : 'false');
    it.el.classList.toggle('fb-live', run.id === id && !run.pause && !!run.kind);
    it.el.classList.toggle('fb-item-warn', !!safetyOf(run, id));
    it.el.title = T('list.row_tip', { name: fanName(id) });
  }

  function update() {
    if (!state) return;
    run = runNow();
    const su = setup();
    const outs = su.outputs || [];
    // the panel follows a newly started output — never while he types in the panel's fields
    const typing = slot.contains(document.activeElement) && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName);
    if (run.id && run.id !== ranLast && run.kind !== 'measure' && !typing) sel = run.id;
    ranLast = run.id || '';
    if (!outs.includes(sel)) sel = [run.id, (run.safety[0] || {}).id, outs.find((id) => openWhy(id)), outs[0]].find((id) => id && outs.includes(id)) || '';
    stripUpdate();
    quietUpdate();
    // the rows: every output, in the board's order (built once; a board never changes its outputs under the page)
    if (list.children.length !== outs.length || outs.some((id, i) => list.children[i] !== item(id).el)) list.replaceChildren(...outs.map((id) => item(id).el));
    outs.forEach(itemUpdate);
    list.classList.toggle('fb-list-many', outs.length > 8);
    // the panel of the output at hand
    if (sel) {
      let pn = panels.get(sel);
      if (!pn) { pn = panelMake(sel, benchRun, update); panels.set(sel, pn); }
      if (slot.firstChild !== pn.el) slot.replaceChildren(pn.el);
      pn.update();
    } else slot.replaceChildren(para(T('step.nothing')));
    nextUpdate();
    if (run.kind && !ticker) ticker = setInterval(tick, 1000);
    if (!run.kind && ticker) { clearInterval(ticker); ticker = null; }
  }

  function stripUpdate() {
    const s = step();
    const noTest = situation() === 'other' ? T('step.blocked_other') : control().running ? T('step.blocked_steering') : '';
    let mood = 'idle', text = T('strip.idle');
    if (run.pause) {
      mood = 'run';
      const fan = run.pause.next || (run.kind === 'test' ? run.queue[0] : '') || '';
      text = run.pause.left !== null ? (fan ? T('strip.pause_next', { s: Math.max(0, Math.ceil(run.pause.left)), fan: fanName(fan) }) : T('strip.pause', { s: Math.max(0, Math.ceil(run.pause.left)) })) : T('strip.pause_plain');
    } else if (run.kind === 'test' && run.id) { mood = 'run'; text = T(run.quiet ? 'strip.test_quiet' : 'strip.test', { fan: fanName(run.id) }); }
    else if (run.kind === 'show' && run.id) { mood = 'run'; text = T(run.quiet ? 'strip.show_quiet' : 'strip.show', { fan: fanName(run.id) }); }
    else if (run.kind === 'measure') { mood = 'run'; text = T('strip.measure', { fan: run.id ? fanName(run.id) : '' }); }
    else if (run.kind) { mood = 'run'; text = T('strip.starting'); }
    else if (run.safety.length) { mood = 'warn'; text = T('strip.safety', { n: run.safety.length, list: run.safety.map((y) => fanName(y.id)).join(', ') }); }
    else if (noTest) text = noTest;
    strip.dataset.mood = mood;
    strip.classList.toggle('running', mood === 'run');       // office.css shows the wave inside .running
    setText(now, text);
    // line 2: what was refused or said last; else how the last run ended; else what to know while it runs
    let line = '', warn = false;
    if (said) { line = said.text; warn = said.warn; }
    else if (!run.kind && endedText(s) && !(s.why === 'no_restart' && run.safety.length)) { line = endedText(s); warn = true; }     // (line 1 says the safety-full itself)
    else if (!run.kind && showEndedText()) { line = showEndedText(); warn = true; }
    else if (run.kind === 'test' || run.kind === 'measure') line = T('strip.keep_open');
    else if (sends > 0) line = T('strip.moment');
    setText(note, line);
    note.classList.toggle('fb-strip-warn', warn);
    strip.classList.toggle('fb-strip-told', warn);
    swap(stripActs, [!!run.kind, run.safety.map((y) => y.id), Office.agent.running], () => {
      const out = [];
      run.safety.forEach((y) => out.push(btn(T('safety.back_named', { fan: fanName(y.id) }), 'btn small', () => send('safety_back', { fan: y.id }, { done: () => Office.toast(T('safety.back_toast', { fan: fanName(y.id) })) }), T('safety.back_tip'))));
      if (run.kind) out.push(btn(T('strip.stop_all'), 'btn small danger', () => stopAll(), T('strip.stop_all_tip')));
      out.forEach((b) => { b.disabled = !Office.agent.running; });
      return out;
    });
    strip.classList.toggle('fb-strip-stuck', !!run.kind);
    if (run.kind) {
      const menu = document.getElementById('menu');
      const head = root.closest('section') ? root.closest('section').querySelector('.section-head.sticky') : null;
      const above = (menu && /^(sticky|fixed)$/.test(getComputedStyle(menu).position) ? menu.offsetHeight : 0)
        + (head && getComputedStyle(head).position === 'sticky' ? head.offsetHeight : 0);
      strip.style.top = `${above}px`;
    }
    tick();
  }
  /** Each second while something runs: «for 12 s» and the bar — in place, nothing else moves */
  function tick() {
    const on = !!run.since && (run.kind === 'test' || run.kind === 'show');
    clock.classList.toggle('fb-clock-off', !on);
    if (!on) { setText(since, ''); return; }
    const secs = Math.min(FULL_MAX, Math.max(0, Math.floor((Date.now() - run.since) / 1000)));
    setText(since, T('strip.since', { s: secs }));
    meterBar.style.width = `${Math.round(100 * secs / FULL_MAX)}%`;
  }

  function quietUpdate() {
    const on = quietOn();
    qBtn.on.setAttribute('aria-pressed', String(on));
    qBtn.off.setAttribute('aria-pressed', String(!on));
    qList.hidden = !on;
    qOff.hidden = on;
  }

  function nextUpdate() {
    const su = setup();
    const outs = su.outputs || [];
    const open = outs.map((id) => [id, openWhy(id)]).filter(([, w]) => w);
    const toTest = open.filter(([, w]) => w === 'test').map(([id]) => id);
    const tested = outs.length - toTest.length;
    const roles = outs.filter((id) => { const f = setupFan(id); return f && (f.role || f.found === 'empty'); }).length;
    swap(nextText, [tested, outs.length, roles, open, sel], () => {
      const out = [el('span', 'fb-next-count', `${T('step.v.tested', { n: tested, m: outs.length })} · ${T('step.v.assigned', { n: roles })}`)];
      if (open.length) {
        const line = el('span', 'fb-next-open', T('next.open'));
        open.forEach(([id, w]) => {
          const b = btn(T('out.name', { n: (idParts(id) || {}).output }), 'fb-link', () => { sel = id; update(); }, T(`next.open_${w}`));
          line.append(' ', b);
        });
        out.push(line);
      } else out.push(el('span', 'fb-next-open', T('next.all_done')));
      return out;
    });
    const noTest = situation() === 'other' || !!control().running;
    const selOpen = openWhy(sel) === 'test';
    swap(nextActs, [open.length, toTest.length, !!run.kind, noTest, selOpen, Office.agent.running], () => {
      const out = [];
      // the ONE primary button: the panel's own «Start the test» while its output is untested; else here
      if (toTest.length && !selOpen && !run.kind && !noTest) {
        out.push(btn(T('next.test_open', { n: toTest.length }), 'btn', () => startTest(toTest), T('test.all_tip')));
      }
      const go = btn(T('step.next', { name: T('step.measure') }), open.length || run.kind ? 'btn small plain' : 'btn', () => { setupSwitcher().pick('measure'); drawn = ''; redraw(); });
      out.push(go);
      out.forEach((b) => { if (b !== go) b.disabled = !Office.agent.running; });
      return out;
    });
  }

  update();
  return {
    el: root,
    update,
    destroy() {
      clearInterval(ticker);
      items.forEach((it) => { if (it.wh) it.wh.destroy(); });
      panels.forEach((p) => p.destroy());
    },
  };
}
/**
 * His answer while it ran, for an output no tach followed (the two buttons replace the later question «did anything get
 * louder?»): «found» → a fan without feedback, «heard nothing» → nothing attached. Sent once its verdict is in.
 */
function settleHeard() {
  const run = runNow();
  heard.forEach((v, id) => {
    const f = setupFan(id);
    if ((run.id === id && !run.pause) || !f || !f.found) return;     // still at full, or no verdict yet
    heard.delete(id);
    if (['ask', 'unclear'].includes(f.found)) send('answer', { fan: id, louder: v }, { quietly: true });
  });
}
/** Start the test for these outputs, in the board's order — the quiet switch as it stands */
function startTest(ids) {
  if (!ids.length) return null;
  return send('test_up', { fans: ids, quiet: quietOn() }, { fresh: true });
}
/** How the last test ended, when it didn't simply finish: the page gone, stopped, too warm, a fan that didn't start again */
function endedText(s) {
  if (!s || s.kind !== 'test_up' || running(s) || !['stopped', 'failed'].includes(s.status)) return '';
  const why = s.status === 'failed' ? 'error' : s.why || 'stopped';
  if (['quiet_warm', 'no_restart'].includes(why) && s.quiet_stop) return T(`step.ended.${why}`, { what: s.quiet_stop.what || '', c: num(s.quiet_stop.c) === null ? '' : fmt.number(s.quiet_stop.c) });
  return has(`step.ended.${why}`) ? T(`step.ended.${why}`) : T('step.ended.stopped');
}
function showEndedText() {
  const sh = showNow();
  if (!sh || showRuns()) return '';
  if (sh.why === 'give_back') return T('show.give_back_failed', { fan: fanName(sh.fan) });
  if (['quiet_warm', 'no_restart'].includes(sh.why) && sh.quiet_stop) return T(`show.${sh.why}`, { what: sh.quiet_stop.what || '', c: num(sh.quiet_stop.c) === null ? '' : fmt.number(sh.quiet_stop.c) });
  return '';
}

/**
 * The panel of ONE output: the big wheel, what the output does right now, its buttons (one of them the primary one of the
 * moment), then what it is — «Was ist das?» as buttons, its name, where it sits, how many fans hang on it —, the CPU
 * cooler's own block where it is one, and behind two folds named for what they hold: model and colour (for the
 * picture), «give back to» (only for an output found on a fixed value). Built once per output, patched in place.
 */
function panelMake(id, benchRun, benchUpdate) {
  const p = idParts(id);
  const root = Office.place(`test:${id}`, el('div', 'fb-panel'));
  root.dataset.fan = id;
  const A = art();
  const wh = A ? A.wheel({ px: 116, air: false, rpm: 0 }) : null;
  const pict = el('div', 'fb-panel-wheel');
  if (wh) pict.appendChild(wh.el);
  const title = el('h3', 'fb-panel-title');
  const line = el('p', 'fb-panel-line');
  const acts = el('div', 'fb-panel-acts');
  const why = el('p', 'fb-panel-why');
  const headText = el('div', 'fb-panel-headtext');
  headText.append(title, line, acts, why);
  const head = el('div', 'fb-panel-head');
  head.append(pict, headText);
  const safety = el('div', 'callout warn fb-safety');
  safety.hidden = true;
  // «Was ist das?»
  const what = el('div', 'fb-what');
  what.appendChild(el('h4', 'fb-q', T('what.title')));
  const roleBtns = new Map();
  const group = (label, keys) => {
    const g = el('div', 'fb-what-group');
    g.appendChild(el('span', 'fb-what-label', label));
    const row = el('div', 'fb-what-row');
    keys.forEach((k) => {
      const b = btn(k === 'empty' ? T('role.empty') : T(`role.${k}`), 'fb-pick', () => pickRole(k), k === 'empty' ? T('what.empty_tip') : T(`what.tip.${ROLES_OURS.includes(k) ? 'his' : k}`));
      roleBtns.set(k, b);
      row.appendChild(b);
    });
    g.appendChild(row);
    what.appendChild(g);
  };
  group(T('roles.his'), ROLES_OURS);
  group(T('roles.bios'), ROLES_BIOS);
  group(T('what.nothing'), ['empty']);
  const cpu = el('div', 'fb-cpu-slot');
  // its name, where it sits, how many
  const fields = el('div', 'fb-fields');
  const field = (label, input, cls) => { const l = el('label', `fb-field ${cls || ''}`); l.append(el('span', 'fb-field-label', label), input); return l; };
  const name = nameField(id, setupFan(id), false);
  const place = inputEl('select');
  place.appendChild(new Option(T('roles.place_any'), ''));
  PLACES.forEach((pl) => place.appendChild(new Option(T(`place.${pl}`), pl)));
  place.onchange = () => { const f = setupFan(id); if (f && f.role) send('role', { fan: id, role: f.role, place: place.value, off_ok: !!f.off_ok && ROLES_OURS.includes(f.role) }, { quietly: true }); };
  const count = inputEl('select');
  for (let n = 1; n <= 8; n++) count.appendChild(new Option(String(n), String(n)));
  count.onchange = () => { const f = setupFan(id); if (f && f.role) send('role', { fan: id, role: f.role, count: Number(count.value), off_ok: !!f.off_ok && ROLES_OURS.includes(f.role) }, { quietly: true }); };
  fields.append(field(T('fields.name'), name, 'fb-field-name'), field(T('fields.place'), place), field(T('roles.count'), count, 'fb-field-count'));
  const note = el('p', 'fb-panel-note');
  // the folds
  const look = keepFold(el('details', 'fb-fold'), `look:${id}`, false);
  const lookSum = el('summary', '');
  const lookBody = el('div', 'fb-fold-body');
  look.append(lookSum, lookBody);
  const back = keepFold(el('details', 'fb-fold'), `back:${id}`, false);
  const backSum = el('summary', '', T('back.fold'));
  const backBody = el('div', 'fb-fold-body');
  back.append(backSum, backBody);
  root.append(head, safety, what, cpu, fields, note, look, back);
  let pk = null, colour = null, lookNote = null;

  function pickRole(k) {
    const f = setupFan(id);
    if (k === 'empty') { send('answer', { fan: id, louder: false }); return; }
    if (f && ['ask', 'unclear'].includes(f.found) && benchRun().id !== id) send('answer', { fan: id, louder: true }, { quietly: true });
    send('role', { fan: id, role: k, off_ok: !!(f && f.off_ok) && ROLES_OURS.includes(k) });
    if (k !== 'cpu') cpuAsk.delete(id);
  }

  function update() {
    const run = benchRun();
    const f = setupFan(id);
    const found = f ? f.found : null;
    const here = run.id === id && !!run.kind && !run.pause;
    const away = !Office.agent.running;
    const never = ['ignored', 'inverted'].includes(found);
    const noTest = situation() === 'other' ? T('step.blocked_other') : control().running ? T('step.blocked_steering') : '';
    const sf = safetyOf(run, id);
    if (wh) wh.update(wheelNow(id, run));
    root.classList.toggle('fb-live', here);
    setText(title, f && f.name ? T('panel.title_named', { n: p ? p.output : '?', name: f.name }) : T('out.name', { n: p ? p.output : '?' }));
    // what it does right now, in a sentence
    const rpm = f && found === 'tach' ? rpmNow(id, f.tachs) : null;
    let text = foundLine(f);
    const res = step() && step().kind === 'test_up' && step().results ? step().results[id] : null;
    if (res && res.found === 'busy') text = T('test.busy');
    if (here && run.kind === 'show') text = T('panel.showing');
    else if (here) text = T('panel.now');
    else if (sf) text = T('panel.safety');
    else if (run.stopped.includes(id)) text = found ? `${foundLine(f)} · ${T('panel.stands')}` : T('panel.stands');
    else if (run.kind === 'test' && run.queue.includes(id)) text = T('test.waiting');
    else if (rpm !== null) text = `${text} · ${T('pic.rpm', { rpm: fmt.number(rpm) })}`;
    if (!here && ['ask', 'unclear'].includes(found) && !run.kind) text = `${text} — ${T('panel.ask')}`;
    setText(line, text);
    // its buttons: the one of the moment first; a button that can't act is off and the line under them says why
    const open = (setup().outputs || []).filter((x) => openWhy(x) === 'test');
    const more = run.kind === 'test' && run.queue.length > 0;
    const queued = run.kind === 'test' && !here && (run.queue.includes(id) || (run.pause && run.pause.next === id));
    let reason = '';
    if (queued) reason = T('panel.queued');
    else if (!here && run.pause) reason = T('panel.pause');
    else if (!here && run.kind) reason = T('panel.busy', { fan: run.id ? fanName(run.id) : '' });
    else if (!run.kind && noTest) reason = noTest;
    else if (!run.kind && never) reason = T(`test.${found}_hint`);
    else if (away) reason = T('strip.away');
    setText(why, reason);
    swap(acts, [here, run.kind, more, found, leftAlone(f), !!noTest, away, open.length, open[0] === id, !!sf, queued], () => {
      const out = [];
      if (queued) return out;                  // its turn comes in this run: nothing to start here (the strip has «Stop everything»)
      const blocked = !!run.kind || !!noTest || away;
      if (here && run.kind === 'show') {
        out.push(btn(T('show.stop'), 'btn', () => send('show_stop', { fan: id }), T('show.stop_tip')));
      } else if (here) {
        out.push(btn(T(more ? 'test.found_next' : 'test.found_done'), 'btn', () => { heard.set(id, true); send('step_next', { fan: id }); }, T('test.found_next_tip')));
        out.push(btn(T(more ? 'test.none_next' : 'test.none_done'), 'btn small plain', () => { heard.set(id, false); send('step_next', { fan: id }); }, T('test.none_tip')));
        out.push(btn(T('test.abort'), 'btn small plain', () => send('step_stop', {}, { done: () => Office.toast(T('step.stopped_toast')) }), T('test.abort_tip')));
      } else if (['ask', 'unclear'].includes(found) && !run.kind) {
        out.push(btn(T('ask.yes'), 'btn small', () => send('answer', { fan: id, louder: true }), T('test.yes_tip')));
        out.push(btn(T('ask.no'), 'btn small', () => send('answer', { fan: id, louder: false }), T('test.no_tip')));
        out.push(btn(T('test.again_long'), 'btn small plain', () => startTest([id]), T('test.again_tip')));
      } else if (!found && !leftAlone(f)) {
        // untested: the primary «Start the test» runs every open output in the board's order, this one first
        const rest = [id, ...open.filter((x) => x !== id)];
        out.push(btn(open.length > 1 ? T('test.start') : T('test.start_one'), 'btn', () => startTest(open.length > 1 ? rest : [id]), open.length > 1 ? T('test.all_tip') : T('test.one_tip')));
        if (open.length > 1) out.push(btn(T('test.only'), 'btn small plain', () => startTest([id]), T('test.one_tip')));
      } else {
        out.push(btn(leftAlone(f) ? T('test.start_one') : T('test.again_long'), 'btn small plain', () => startTest([id]), T('test.one_tip')));
        if (['tach', 'silent'].includes(found)) out.push(btn(T('show.button'), 'btn small plain', () => send('show', { fan: id, quiet: quietOn() && !control().running }, { fresh: true }), T('show.tip')));
      }
      // answering the question writes no output: only the messenger away turns it off; a test or a show needs the way free
      if (!here) out.forEach((b) => { b.disabled = ['ask', 'unclear'].includes(found) && !run.kind && b !== out[2] ? away : blocked; });
      return out;
    });
    // set to full for safety: said here, with the way back
    safety.hidden = !sf;
    if (sf) {
      swap(safety, [sf.why || '', away], () => {
        const b = btn(T('safety.back'), 'btn small', () => send('safety_back', { fan: id }, { done: () => Office.toast(T('safety.back_toast', { fan: fanName(id) })) }), T('safety.back_tip'));
        b.disabled = away;
        return [el('p', '', T(has(`safety.why.${sf.why}`) ? `safety.why.${sf.why}` : 'safety.why.other', { fan: fanName(id) })), b];
      });
    }
    // «Was ist das?»
    const cur = found === 'empty' ? 'empty' : (f && f.role) || '';
    roleBtns.forEach((b, k) => {
      b.setAttribute('aria-pressed', k === cur ? 'true' : 'false');
      b.disabled = away || never || (found === 'empty' && k !== 'empty');
      if (k === 'unknown') setText(b, !found && !run.kind ? T('role.left') : T('role.unknown'));
    });
    // the CPU cooler: who steers it — right under the question, never behind a fold
    const isCpu = !!f && f.role === 'cpu';
    swap(cpu, [isCpu, isCpu && f.cpu_own, isCpu && found, cpuAsk.has(id), (cpuAsk.get(id) || {}).tick, away, ((setup().before || {})[id] || {}).auto ?? null], () => (isCpu ? cpuBlock(f, benchUpdate) : []));
    // the fields
    nameField(id, f, away || never);
    const roleSet = !!(f && f.role);
    place.disabled = count.disabled = away || never || !roleSet;
    if (document.activeElement !== place) place.value = (f && f.place) || '';
    if (document.activeElement !== count) count.value = String((f && f.count) || 1);
    let hint = '';
    if (found === 'empty') hint = T('panel.empty_note');
    else if (f && (f.role === 'cpu' || f.role === 'pump')) hint = T('assign.never_stopped');
    else if (f && f.role === 'unknown') hint = T('roles.bios_note.unknown');
    else if (!roleSet && p && p.output === 1 && !never) hint = T('roles.cpu_hint');
    else if (f && (f.count || 1) > 1) hint = T('roles.count_note', { n: f.count });
    else if (!roleSet && !never) hint = T('panel.role_first');
    setText(note, hint);
    // model and colour (for the picture)
    const m = f && f.model ? modelText(f.model) : '';
    setText(lookSum, m ? T('look.fold_is', { model: m }) : T('look.fold'));
    if (A && cat && !pk) {
      pk = A.picker({ catalog: cat, value: f && f.model ? (f.model.id ? { id: f.model.id } : { mm: f.model.mm, blades: f.model.blades }) : null, colour: (f && f.colour) || 'auto', texts: pickerTexts(),
        onChange: (v) => { if (v) send('fan_look', v.id ? { fan: id, model: v.id } : { fan: id, model: '', mm: v.mm, blades: v.blades }, { quietly: true }); } });
      pickers.set(id, pk);
      pk.el.querySelectorAll('select').forEach((x) => { x.classList.add('input', 'small', 'fb-input'); });
      colour = inputEl('select');
      COLOURS.forEach((c) => colour.appendChild(new Option(T(`colour.${c}`), c)));
      colour.onchange = () => { if (pk.colour) pk.colour(colour.value); send('fan_look', { fan: id, colour: colour.value }, { quietly: true }); };
      lookNote = el('div', 'fb-look-note');
      lookBody.append(el('p', 'fb-hint-text', T('look.text')), pk.el, field(T('look.colour'), colour, 'fb-field-colour'), lookNote);
    }
    if (colour) {
      const isModel = !!(f && f.model && f.model.id);
      setText(colour.options[0], isModel ? T('colour.model') : T('colour.auto'));
      if (document.activeElement !== colour) colour.value = (f && f.colour) || 'auto';
      colour.disabled = away;
      const c = catFan(f && f.model && f.model.id);
      const offBy = c && f.measured && num(f.max_rpm) && num(c.rpm_max) && Math.abs(f.max_rpm - c.rpm_max) > Math.max(250, 0.3 * c.rpm_max);
      swap(lookNote, [offBy ? f.max_rpm : 0], () => (offBy ? para(T('roles.model_off', { measured: fmt.number(f.max_rpm), sheet: fmt.number(c.rpm_max) }), 'callout') : []));
    }
    // «give back to»: only for an output found on a fixed value that Fanboy may get
    const b = (setup().before || {})[id];
    const hasBack = !!(f && b && b.fixed && !ROLES_BIOS.includes(f.role));
    back.hidden = !hasBack;
    if (hasBack) swap(backBody, [f.back, b.pct, b.auto ?? null, setup().back_default, away], () => backChoice(f, b));
  }
  update();
  return { el: root, update, destroy() { if (wh) wh.destroy(); } };
}
/**
 * The CPU cooler's block (round 4's rule, the owner 2026-11-15: «Zurück ans BIOS» was «completely in the wrong place»):
 * who steers it — the BIOS (the default) or Fanboy on the owner's own risk behind the tick; given to him: since when,
 * and «Back to the BIOS» right here.
 */
function cpuBlock(f, again) {
  const box = el('div', 'fb-cpu');
  const auto = ((setup().before || {})[f.id] || {}).auto;
  box.appendChild(el('h4', 'fb-q', T('cpu.who')));
  if (f.cpu_own) {
    box.classList.add('fb-cpu-given');
    box.appendChild(para(T('cpu.own_since', { date: Office.fmt.date(f.cpu_own) })));
    box.appendChild(bar(btn(T('cpu.back'), 'btn small', () => send('role', { fan: f.id, role: 'cpu', name: f.name || '', place: f.place || '', cpu_own: false }), T('cpu.back_tip'))));
    return box;
  }
  if (!['tach', 'silent', 'unclear'].includes(f.found)) { box.appendChild(para(T('roles.bios_note.cpu'))); return box; }
  if (auto === null || auto === undefined) { box.append(para(T('roles.bios_note.cpu')), para(T('cpu.no_auto'))); return box; }
  const asking = cpuAsk.has(f.id);
  const seg = el('div', 'seg');
  seg.setAttribute('role', 'group');
  seg.setAttribute('aria-label', T('cpu.who'));
  [['bios', !asking], ['fanboy', asking]].forEach(([w, on]) => {
    const b = btn(T(`cpu.${w}`), '', () => { if (w === 'fanboy') cpuAsk.set(f.id, { tick: false }); else cpuAsk.delete(f.id); again(); });
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    seg.appendChild(b);
  });
  box.appendChild(seg);
  if (!asking) { box.appendChild(para(T('roles.bios_note.cpu'))); return box; }
  const risk = el('div', 'fb-cpu-risk');
  ['risk_1', 'risk_2', 'risk_3', 'risk_4'].forEach((k) => risk.appendChild(para(T(`cpu.${k}`, { floor: setup().cpu_floor || 40 }))));
  const tick = el('input');
  tick.type = 'checkbox';
  tick.checked = !!cpuAsk.get(f.id).tick;
  const go = btn(T('cpu.go'), 'btn', () => send('role', { fan: f.id, role: 'cpu', name: f.name || '', place: f.place || '', cpu_own: true, cpu_confirm: tick.checked }, { done: () => { cpuAsk.delete(f.id); } }), T('cpu.go_tip'));
  go.disabled = !tick.checked;
  tick.onchange = () => { cpuAsk.set(f.id, { tick: tick.checked }); go.disabled = !tick.checked; };
  const l = el('label', 'fb-check');
  l.append(tick, el('span', '', T('cpu.confirm')));
  risk.append(l, bar(go));
  box.appendChild(risk);
  return box;
}
function testStep() {
  if (!bench) bench = benchMake();
  else bench.update();
  return bench.el;
}
/**
 * «Wo sitzt er?» — the fan's name, typed while he hears it: the same field kept across every drawing (the page redraws
 * every 2 s while a test runs — focus and what is typed stay), saved at once (a moment after the last key; never through
 * the page's busy gate, so a test's own buttons stay free)
 */
const nameFields = new Map();
function nameField(id, f, off) {
  let n = nameFields.get(id);
  if (!n) {
    n = inputEl('input', 'text');
    n.maxLength = 40;
    n.placeholder = T('assign.where_ph');
    n.dataset.fbKeep = '1';
    n.dataset.own = '1';
    n.value = (f && f.name) || '';
    let t = null;
    const save = async () => {
      clearTimeout(t);
      t = null;
      if (!Office.agent.running) return;
      const value = n.value.trim();
      n.dataset.saving = value;
      const j = await Office.api.post(`${ID}.fan_name`, { fan: id, name: value });
      if (!j.ok) { const text = Office.errorText(j.error, ID); Office.toast(text, true); say(T('strip.refused', { why: text })); }
      else { const sf = setupFan(id); if (sf) sf.name = value; if (bench) bench.update(); }
      if (n.dataset.saving === value) delete n.dataset.saving;
    };
    n.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 700); });
    n.addEventListener('change', save);
    nameFields.set(id, n);
  } else if (document.activeElement !== n && !n.dataset.saving && f && typeof f.name === 'string' && n.value !== f.name) {
    n.value = f.name;                // changed elsewhere (another browser): as saved, unless he is typing here
  }
  n.disabled = off;
  return n;
}
/** What the running step does now, the wave, «Stop» — it can always be stopped */
function runningLine(s, text) {
  const p = el('div', 'fb-running');
  p.append(wave(), el('span', '', text));
  const stop = el('button', 'btn small', T('step.stop'));
  stop.type = 'button';
  stop.title = T('step.stop_tip');
  stop.disabled = !Office.agent.running;
  stop.onclick = async () => {
    stop.disabled = true;
    const j = await Office.api.post(`${ID}.step_stop`, {});
    if (!j.ok) Office.toast(Office.errorText(j.error, ID), true);
    else Office.toast(T('step.stopped_toast'));
    await load(true);
  };
  p.appendChild(stop);
  const wrap = el('div', 'fb-running-box');
  wrap.append(p, para(T('step.alive_note')));
  return wrap;
}
/** How the last step of this kind ended, honestly: the page gone, stopped, too warm, failed */
function stepEnded(box, s, kind) {
  if (!s || s.kind !== kind || running(s) || !['stopped', 'failed'].includes(s.status)) return;
  const why = s.status === 'failed' ? 'error' : s.why || 'stopped';
  // too warm: which source, how warm, its limit (round 4: by its own kind — an NVMe pool's 50 °C stops nothing)
  const w = why === 'warm' && s.warm && s.warm.source ? T('step.ended.warm_what', { where: srcLabel({ kind: s.warm.kind === 'disk' || s.warm.kind === 'ssd' ? s.warm.kind : 'other', id: s.warm.source, label: s.warm.source }),
    c: fmt.number(s.warm.c), limit: fmt.number(s.warm.limit) }) : null;
  // round 8: the quiet test stopped for a temperature, or a fan that didn't turn again
  const q = ['quiet_warm', 'no_restart'].includes(why) && s.quiet_stop ? T(`step.ended.${why}`, { what: s.quiet_stop.what || '', c: num(s.quiet_stop.c) === null ? '' : fmt.number(s.quiet_stop.c) }) : null;
  box.appendChild(para(w || q || (has(`step.ended.${why}`) ? T(`step.ended.${why}`) : T('step.ended.stopped')), 'callout warn'));
}
/**
 * A step can't run now — another controller steers, or Fanboy does: the banner says who and why; the step says only its
 * own short line, its cards stay to read and its buttons are off (noRun) — never the banner's text again
 */
let noRun = false;
function blocked(box) {
  if (situation() === 'other') {
    box.appendChild(para(T('step.blocked_other'), 'fb-hint-text fb-strong'));
    return false;
  }
  if (control().running) {
    box.appendChild(para(T('step.blocked_steering'), 'fb-hint-text fb-strong'));        // «Hand back» is the take-over step's
    return false;
  }
  return false;
}
async function startStep(kind, ids, b) {
  if (!ids.length) return;
  await act(kind, kind === 'test_up' ? { fans: ids, quiet: quietOn() } : { fans: ids }, b, async () => { await load(true); });
}

function modelText(m) {
  const c = catFan(m && m.id);
  return c ? `${c.brand} ${c.model}` : m && m.mm ? T('roles.other_fan', { mm: m.mm }) : '';
}
function pickerTexts() {
  return {
    brand: T('pick.brand'), model: T('pick.model'), other: T('pick.other'), choose: T('pick.choose'), size: T('pick.size'), blades: T('pick.blades'),
    mm: (n) => T('pick.mm', { n }), option: (f) => T('pick.option', { model: f.model, mm: f.mm }),
    facts: (f) => [f.mm ? T('pick.mm', { n: f.mm }) : '', f.rpm_max ? (num(f.rpm_min) !== null ? T('pick.range', { min: fmt.number(f.rpm_min), max: fmt.number(f.rpm_max) }) : T('pick.upto', { max: fmt.number(f.rpm_max) })) : '',
      f.blades ? T('pick.blades_n', { n: f.blades }) : '', f.connector || ''].filter(Boolean).join(' · ') + (f.id ? '' : ` · ${T('pick.measured_later')}`),
  };
}
/** «Give back to»: the mainboard's automatic (where the chip has one) or this fixed value — open until chosen */
function backChoice(f, b) {
  const box = el('fieldset', 'fb-back');
  box.appendChild(el('legend', '', T('back.title')));
  box.appendChild(para(T('back.text', { pct: b.pct ?? '–' })));
  const name = `fb-back-${f.id}`;
  const noAuto = b.auto === null || b.auto === undefined;
  let cur = f.back || setup().back_default || '';
  if (cur === 'auto' && noAuto) cur = 'fixed';           // no automatic on this chip: the fixed value stays (said below)
  [['auto', noAuto ? T('back.auto_none') : T('back.auto'), noAuto], ['fixed', T('back.fixed', { pct: b.pct ?? '–' }), false]].forEach(([v, label, off]) => {
    const l = el('label', 'fb-radio');
    const r = el('input');
    r.type = 'radio';
    r.name = name;
    r.value = v;
    r.checked = cur === v;
    r.disabled = off || !Office.agent.running;
    r.onchange = () => send('back', { fan: f.id, back: v }, { quietly: true });
    l.append(r, el('span', '', label));
    if (off) l.title = T('back.no_auto');
    box.appendChild(l);
  });
  if (noAuto) box.appendChild(para(T('back.no_auto_note', { pct: b.pct ?? '–' }), 'fb-strong'));
  else if (!f.back) box.appendChild(para(T('back.default_note')));
  return box;
}

// 4 — measure: the table % ↔ rpm as it is measured
function measureStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const s = step();
  const live = running(s) && s.kind === 'measure';
  if (blocked(box)) return box;
  stepEnded(box, s, 'measure');
  const ours = (su.fans || []).filter((f) => isOurs(f) && f.found === 'tach');
  if (!ours.length) {
    box.appendChild(para(T('measure.none')));
    box.appendChild(bar(nextButton('measure')));
    return box;
  }
  const list = el('div', 'fb-cards fb-cards-wide');
  ours.forEach((f) => {
    const under = live && s.current === f.id;
    const card = Office.place(`measure:${f.id}`, el('div', 'fb-card' + (under ? ' fb-card-now' : '')));
    const look = fanLook(f);
    const o = outputNow(f.id);
    card.appendChild(cardWheel({ rpm: rpmNow(f.id, f.tachs), mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: under ? 'accent' : look.colour, look: look.look }));
    const txt = el('div', 'fb-card-text');
    txt.appendChild(el('div', 'fb-card-name', fanName(f.id)));
    const r = s && s.kind === 'measure' && s.results ? s.results[f.id] : null;
    const table = under && s.progress && s.progress.id === f.id ? s.progress.table : f.table;
    if (under) txt.appendChild(el('div', 'fb-card-line', T('measure.now', { pct: o && num(o.pct) !== null ? o.pct : '–' })));
    else if (live && (s.fans || []).includes(f.id) && !(s.done || []).includes(f.id)) txt.appendChild(el('div', 'fb-card-line', T('test.waiting')));
    else if (f.no_turn) txt.appendChild(el('div', 'fb-card-line fb-strong', T('measure.no_turn')));
    else if (f.measured) txt.appendChild(el('div', 'fb-card-line', T('measure.done', { min: f.min_pct, start: f.start_pct, max: fmt.number(f.max_rpm || 0) })));
    else txt.appendChild(el('div', 'fb-card-line', T('measure.not_yet')));
    if (r && r.result === 'warm') txt.appendChild(el('div', 'fb-card-line fb-strong', T('measure.warm')));
    if (Array.isArray(table) && table.length) txt.appendChild(tableEl(table, under ? null : f));
    if (!live) txt.appendChild(bar(button(f.measured ? T('measure.again') : T('measure.one'), T('measure.one_tip'), (b) => startStep('measure', [f.id], b))));
    card.appendChild(txt);
    list.appendChild(card);
  });
  box.appendChild(list);
  if (live) box.appendChild(runningLine(s, T('measure.running', { n: (s.done || []).length + 1, m: (s.fans || []).length })));
  else {
    const todo = ours.filter((f) => !f.measured).map((f) => f.id);
    box.appendChild(bar(button(T('measure.all'), T('measure.all_tip'), (b) => startStep('measure', todo.length ? todo : ours.map((f) => f.id), b), 'btn'), nextButton('measure')));
  }
  return box;
}
/** The table % ↔ rpm: each step as measured, the lowest steady and the start value named */
function tableEl(table, f) {
  const t2 = el('table', 'fb-table');
  const h = el('tr');
  h.append(el('th', '', T('measure.pct')), el('th', '', T('measure.rpm')));
  t2.appendChild(h);
  table.forEach(([p, r]) => {
    const tr = el('tr');
    const mark = f && p === f.min_pct ? ` · ${T('measure.lowest')}` : '';
    tr.append(el('td', '', T('value', { pct: p }) + mark), el('td', '', fmt.number(r)));
    t2.appendChild(tr);
  });
  if (f && num(f.start_pct) !== null && f.start_pct !== f.min_pct) {
    const tr = el('tr');
    tr.append(el('td', '', T('value', { pct: f.start_pct }) + ` · ${T('measure.start')}`), el('td', '', '–'));
    t2.appendChild(tr);
  }
  const w = el('div', 'fb-table-wrap');
  w.appendChild(t2);
  return w;
}

// 5 — zones: fans and heat sources moved between them, the curve to drag
function zonesNow() {
  if (zdraft) return zdraft;
  return (setup().zones || []).map((z) => JSON.parse(JSON.stringify(z)));
}
function changeZones(fn) {
  const z = zonesNow();
  fn(z);
  zdraft = z;
  drawn = '';
  redraw();
}
function zonesStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const ours = (su.fans || []).filter((f) => isOurs(f) && ['tach', 'silent'].includes(f.found));
  if (!ours.length) {
    box.appendChild(para(T('zones.no_fans')));
    return box;
  }
  const zones = zonesNow();
  const tools2 = bar(
    button(T('zones.propose'), T('zones.propose_tip'), (b) => act('zones_propose', {}, b, (j) => { zdraft = (j.zones || []).map((z) => ({ ...z, name: z.name || T(`zones.kind.${z.kind}`) })); })),
    zones.length < 8 ? button(T('zones.add'), T('zones.add_tip'), () => changeZones((z) => z.push({ id: freeId(z), name: T('zones.new_name', { n: z.length + 1 }), fans: [], sources: [], unit: 'pct', target: { preset: 'normal' } }))) : null);
  box.appendChild(tools2);
  box.appendChild(para(T('zones.how_often', { s: setup().round || 2 }) + (num(S().poll) !== null && S().poll > 300 ? ' ' + T('zones.poll', { min: Math.round(S().poll / 60) })
    + (num(S().disk_allow) > 0 ? ' ' + T('zones.poll_add', { add: S().disk_allow }) : '') : '')));
  if (!zones.length) box.appendChild(para(T('zones.empty')));
  const unzoned = ours.filter((f) => !zones.some((z) => (z.fans || []).includes(f.id)));
  if (unzoned.length && zones.length) box.appendChild(para(T('zones.unzoned', { list: unzoned.map((f) => fanName(f.id)).join(', ') })));
  zones.forEach((z, i) => box.appendChild(zoneCard(z, i, zones, ours)));
  if (zdraft) {
    const save = button(T('zones.save'), T('zones.save_tip'), (b) => act('zones_save', { zones: zdraft.map(cleanZone) }, b, () => { zdraft = null; curves.clear(); dry = null; }), 'btn');
    const undo = button(T('zones.undo'), T('zones.undo_tip'), () => { zdraft = null; curves.clear(); drawn = ''; redraw(); });
    const p = el('div', 'fb-unsaved');
    p.append(el('span', 'chip warn', T('zones.unsaved')), save, undo);
    box.appendChild(p);
  } else box.appendChild(bar(nextButton('zones')));
  return box;
}
const freeId = (z) => { for (let i = 1; ; i++) { const id = `zone${i}`; if (!z.some((x) => x.id === id)) return id; } };
/** Only what zones_save reads: numbers as numbers, nothing of the page's own */
function cleanZone(z) {
  const out = { id: z.id, name: String(z.name || '').trim() || z.id, fans: (z.fans || []).slice(), unit: z.unit === 'rpm' ? 'rpm' : 'pct',
    sources: (z.sources || []).map((s) => { const o = { id: s.id }; ['low', 'high', 'limit'].forEach((k) => { if (num(s[k]) !== null) o[k] = s[k]; }); return o; }),
    target: z.target && Array.isArray(z.target.points) ? { points: z.target.points.map((p) => [Math.round(p[0]), Math.round(p[1])]) } : { preset: (z.target && z.target.preset) || 'normal' } };
  if (num(z.low) !== null) out.low = z.low;
  if (num(z.high) !== null) out.high = z.high;
  out.react = z.react === 'quick' ? 'quick' : 'balanced';
  // the table «Temperaturen»: only what the user set (numbers), per kind the zone has
  const kinds = {};
  Object.entries(z.kinds || {}).forEach(([k, v]) => {
    const o = {};
    ['low', 'high', 'limit'].forEach((f) => { if (v && num(v[f]) !== null) o[f] = v[f]; });
    if (Object.keys(o).length) kinds[k] = o;
  });
  if (Object.keys(kinds).length) out.kinds = kinds;
  return out;
}
function zoneCard(z, i, zones, ours) {
  const card = Office.place(`zone:${z.id}`, el('div', 'fb-zone-card'));
  const head = el('div', 'fb-zone-head');
  const name = el('input');
  name.type = 'text';
  name.maxLength = 40;
  name.value = z.name || '';
  name.setAttribute('aria-label', T('zones.name'));
  name.onchange = () => changeZones((all) => { all[i].name = name.value; });
  head.appendChild(name);
  const rm = el('button', 'btn small plain', T('zones.remove'));
  rm.type = 'button';
  rm.title = T('zones.remove_tip');
  rm.onclick = () => changeZones((all) => { all.splice(i, 1); });
  head.appendChild(rm);
  card.appendChild(head);
  // its fans: each in exactly one zone, «may stand still» per fan (only measured, default no)
  card.appendChild(el('h4', 'fb-sub', T('zones.fans')));
  const fl = el('div', 'fb-zone-fans');
  (z.fans || []).forEach((id) => {
    const f = setupFan(id) || { id };
    const row = el('div', 'fb-zone-fan');
    const look = fanLook(f);
    row.appendChild(cardWheel({ rpm: rpmNow(id, f.tachs), mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: look.colour, look: look.look, px: 40, state: f.found === 'silent' ? 'unknown' : 'run' }));
    row.appendChild(el('span', 'fb-zone-fan-name', fanName(id)));
    const move = el('select');
    move.setAttribute('aria-label', T('zones.move', { fan: fanName(id) }));
    zones.forEach((x, k) => move.appendChild(new Option(x.name || x.id, String(k))));
    move.value = String(i);
    move.onchange = () => changeZones((all) => { all[i].fans = all[i].fans.filter((x) => x !== id); all[+move.value].fans.push(id); });
    row.appendChild(move);
    if (f.measured) {
      const l = el('label', 'fb-check');
      const c = el('input');
      c.type = 'checkbox';
      c.checked = !!f.off_ok;
      c.disabled = busy || !Office.agent.running;
      c.onchange = () => act('role', { fan: id, role: f.role, name: f.name || '', place: f.place || '', off_ok: c.checked }, null);
      l.append(c, el('span', '', T('zones.off_ok')));
      l.title = T('zones.off_ok_tip');
      row.appendChild(l);
    }
    fl.appendChild(row);
  });
  const free = ours.filter((f) => !zones.some((x) => (x.fans || []).includes(f.id)));
  if (free.length) {
    const add = el('select');
    add.appendChild(new Option(T('zones.add_fan'), ''));
    free.forEach((f) => add.appendChild(new Option(fanName(f.id), f.id)));
    add.onchange = () => { if (add.value) changeZones((all) => { all[i].fans.push(add.value); }); };
    fl.appendChild(add);
  }
  if (!(z.fans || []).length) fl.appendChild(para(T('zones.no_fan')));
  card.appendChild(fl);
  // its heat sources: any the server shows (a source may count in several zones), the warmest counts
  card.appendChild(el('h4', 'fb-sub', T('zones.sources')));
  card.appendChild(para(T('zones.sources_sub')));
  const kinds = ['disk', 'ssd', 'nvme', 'cpu', 'ram', 'board', 'gpu', 'other'];
  const srcBox = el('div', 'fb-sources');
  const inZone = new Set((z.sources || []).map((s) => s.id));
  kinds.forEach((k) => {
    const list = (setup().sources || []).filter((s) => (s.kind === k) || (k === 'other' && !kinds.slice(0, -1).includes(s.kind)));
    if (!list.length) return;
    const g = el('div', 'fb-src-group');
    g.appendChild(el('span', 'fb-src-kind', T(`kind.${k}`)));
    list.forEach((s) => {
      const on = inZone.has(s.id);
      const b2 = el('button', 'fb-src' + (on ? ' on' : ''), `${srcLabel(s)}${num(s.c) !== null ? ' · ' + T('temp', { c: fmt.number(s.c) }) : s.asleep ? ' · ' + T('pic.asleep') : ''}`);
      b2.type = 'button';
      b2.setAttribute('aria-pressed', on ? 'true' : 'false');
      b2.dataset.own = '1';
      b2.title = on ? T('zones.src_out') : T('zones.src_in');
      b2.onclick = () => changeZones((all) => {
        const has2 = all[i].sources.some((x) => x.id === s.id);
        all[i].sources = has2 ? all[i].sources.filter((x) => x.id !== s.id) : [...all[i].sources, { id: s.id, kind: s.kind }];
      });
      g.appendChild(b2);
    });
    srcBox.appendChild(g);
  });
  card.appendChild(srcBox);
  card.appendChild(tempsPart(z, i));
  card.appendChild(reactPart(z, i));
  card.appendChild(curvePart(z, i));
  return card;
}
/** A source's kind as the zone counts it (from the server's look: a pool not rotating is «ssd») */
const srcKind = (id) => ((setup().sources || []).find((x) => x.id === id) || {}).kind || 'other';
/** The kinds a zone has, in a steady order */
function zoneKinds(z) {
  const order = ['disk', 'ssd', 'nvme', 'cpu', 'gpu', 'ram', 'board', 'other'];
  return [...new Set((z.sources || []).map((x) => srcKind(x.id)))].sort((a, b) => order.indexOf(a) - order.indexOf(b));
}
/** A kind's values in a zone: the user's for it, else the kind's defaults */
/** The three presets on a scale lo … hi — exactly fanboyPreset() of agent/desks/fanboy-control.php (a test compares them) */
function presetsOn(lo, hi) {
  const mid = (lo + hi) / 2;
  return { quiet: [[lo, 0], [mid, 30], [hi, 60], [hi + 5, 100]], normal: [[lo, 20], [mid, 45], [hi, 80], [hi + 3, 100]], cool: [[lo - 3, 35], [mid - 3, 65], [hi - 3, 100]] };
}
function kindValues(z, kind) {
  const d = (setup().scales || {})[kind] || (setup().scales || {}).other || { low: 35, high: 55, limit: 75 };
  const own = (z.kinds || {})[kind] || {};
  return { low: num(own.low) ?? d.low, high: num(own.high) ?? d.high, limit: num(own.limit) ?? d.limit, d };
}
/**
 * «Temperaturen» (round 4, the owner): per kind in the zone «ab» (from here the fans speed up), «volle Drehzahl bei»
 * (full speed from here), «Grenze» (the alarm) — the defaults filled in, each editable, «back to the proposal»; a single
 * source with its own values where it differs. Disks: Unraid's own warning/critical beside them, never copied.
 */
function tempsPart(z, i) {
  const box = el('div', 'fb-temps');
  box.appendChild(el('h4', 'fb-sub', T('temps.title')));
  const kinds = zoneKinds(z);
  if (!kinds.length) return box;
  box.appendChild(para(kinds.length > 1 ? T('temps.mixed', { kind: T(`kind.${kinds[0]}`) }) : T('temps.single')));
  const tb = el('table', 'fb-table fb-temps-table');
  const h = el('tr');
  h.append(el('th', '', T('temps.kind')), el('th', '', T('temps.low')), el('th', '', T('temps.high')), el('th', '', T('temps.limit')), el('th', '', ''));
  tb.appendChild(h);
  const field = (val, def, set, label) => {
    const n = el('input');
    n.type = 'number';
    n.step = '1';
    n.value = String(val);
    n.placeholder = String(def);
    n.className = 'fb-num';
    n.setAttribute('aria-label', label);
    n.onchange = () => set(n.value === '' ? null : Number(n.value));
    return n;
  };
  const src = new Map((setup().sources || []).map((x) => [x.id, x]));
  kinds.forEach((k) => {
    const v = kindValues(z, k);
    const tr = el('tr');
    const set = (f) => (x) => changeZones((all) => {
      const kk = { ...(all[i].kinds || {}) };
      const o = { ...(kk[k] || {}) };
      if (x === null || x === v.d[f]) delete o[f]; else o[f] = x;
      if (Object.keys(o).length) kk[k] = o; else delete kk[k];
      all[i].kinds = kk;
      curves.delete(z.id);
    });
    const name = el('td', 'fb-strong', T(`kind.${k}`));
    tr.append(name, el('td'), el('td'), el('td'), el('td', 'fb-temps-note'));
    tr.children[1].appendChild(field(v.low, v.d.low, set('low'), `${T(`kind.${k}`)} · ${T('temps.low')}`));
    tr.children[2].appendChild(field(v.high, v.d.high, set('high'), `${T(`kind.${k}`)} · ${T('temps.high')}`));
    tr.children[3].appendChild(field(v.limit, v.d.limit, set('limit'), `${T(`kind.${k}`)} · ${T('temps.limit')}`));
    const notes = [];
    if (!(v.low < v.high && v.high < v.limit)) notes.push(T('temps.order'));
    // a disk: Unraid's own warning and critical temperatures beside it — a hint, never copied
    if (k === 'disk' || k === 'ssd') {
      const d = (S().disks || []).find((x) => (z.sources || []).some((y) => y.id === x.name) && (k === 'ssd') === (x.rotational === false) && num(x.warn) !== null);
      if (d) notes.push(T('temps.unraid', { warn: d.warn, crit: num(d.crit) ?? '–' }));
    }
    const over = (z.sources || []).map((y) => src.get(y.id)).filter((x) => x && srcKind(x.id) === k && num(x.c) !== null && x.c >= v.limit);
    if (over.length) notes.push(T('temps.over', { where: srcLabel(over[0]), c: fmt.number(over[0].c) }));
    tr.lastChild.textContent = notes.join(' · ');
    tb.appendChild(tr);
  });
  const wrap = el('div', 'fb-table-wrap');
  wrap.appendChild(tb);
  box.appendChild(wrap);
  // single sources with values of their own
  const own = (z.sources || []).filter((y) => ['low', 'high', 'limit'].some((f) => num(y[f]) !== null));
  own.forEach((y) => {
    const k = srcKind(y.id);
    const v = kindValues(z, k);
    const row = el('div', 'fb-row-fields fb-own');
    row.appendChild(el('span', 'fb-strong', srcLabel(src.get(y.id) || { id: y.id, kind: k })));
    ['low', 'high', 'limit'].forEach((f) => {
      const l = el('label', 'fb-field');
      l.append(el('span', 'fb-field-label', T(`temps.${f}`)), field(num(y[f]) ?? v[f], v[f], (x) => changeZones((all) => {
        const s2 = all[i].sources.find((q) => q.id === y.id);
        if (x === null) delete s2[f]; else s2[f] = x;
        curves.delete(z.id);
      }), `${srcLabel(src.get(y.id) || { id: y.id, kind: k })} · ${T(`temps.${f}`)}`));
      row.appendChild(l);
    });
    const rm = el('button', 'btn small plain', T('temps.own_remove'));
    rm.type = 'button';
    rm.onclick = () => changeZones((all) => { const s2 = all[i].sources.find((q) => q.id === y.id); ['low', 'high', 'limit'].forEach((f) => delete s2[f]); });
    row.appendChild(rm);
    box.appendChild(row);
  });
  const pick = el('select');
  pick.appendChild(new Option(T('temps.own_add'), ''));
  (z.sources || []).filter((y) => !own.includes(y)).forEach((y) => pick.appendChild(new Option(srcLabel(src.get(y.id) || { id: y.id, kind: srcKind(y.id) }), y.id)));
  pick.onchange = () => {
    if (!pick.value) return;
    changeZones((all) => { const s2 = all[i].sources.find((q) => q.id === pick.value); const v = kindValues(all[i], srcKind(pick.value)); s2.low = v.low; s2.high = v.high; s2.limit = v.limit; });
  };
  const back = el('button', 'btn small plain', T('temps.reset'));
  back.type = 'button';
  back.onclick = () => changeZones((all) => { all[i].kinds = {}; all[i].sources.forEach((q) => { delete q.low; delete q.high; delete q.limit; }); curves.delete(z.id); });
  box.appendChild(bar(pick, back));
  return box;
}
/** The way down in words for a kind: «up: at once · down: after 6 s, about 5 % every 2 s» */
function downWords(kind, react) {
  const p = (((setup().down || {})[kind] || (setup().down || {}).other || {})[react === 'quick' ? 'quick' : 'balanced']) || { gap: 2, hold: 0, step: 1 };
  const r = setup().round || 2;
  return p.hold > 0 ? T('react.profile', { gap: fmt.number(p.gap, 1), hold: fmt.number(p.hold), step: fmt.number(p.step), s: r })
    : T('react.profile_now', { gap: fmt.number(p.gap, 1), step: fmt.number(p.step), s: r });
}
/** How he reacts: «Ausgewogen reagieren» (default) or «Sofort reagieren» — and what that means per kind, in words */
function reactPart(z, i) {
  const box = el('div', 'fb-react');
  box.appendChild(el('h4', 'fb-sub', T('react.title')));
  const seg = el('div', 'seg');
  seg.setAttribute('role', 'group');
  seg.setAttribute('aria-label', T('react.title'));
  const cur = z.react === 'quick' ? 'quick' : 'balanced';
  ['balanced', 'quick'].forEach((r) => {
    const b = el('button', '', T(`react.${r}`));
    b.type = 'button';
    b.dataset.own = '1';
    b.setAttribute('aria-pressed', r === cur ? 'true' : 'false');
    b.onclick = () => { if (r !== cur) changeZones((all) => { all[i].react = r; }); };
    seg.appendChild(b);
  });
  box.appendChild(seg);
  // one line per way down: kinds with the same profile share it («NVMe, RAM: …»)
  const ways = new Map();
  zoneKinds(z).forEach((k) => { const w = downWords(k, cur); ways.set(w, [...(ways.get(w) || []), T(`kind.${k}`)]); });
  ways.forEach((names, w) => box.appendChild(el('p', 'fb-hint-text', `${names.join(', ')}: ${w}`)));
  return box;
}
/** The zone's curve: quiet, normal, cool — or its own, dragged; percent, or rpm where every fan is measured */
function curvePart(z, i) {
  const box = el('div', 'fb-curve-part');
  box.appendChild(el('h4', 'fb-sub', T('zones.curve')));
  const fans = (z.fans || []).map(setupFan).filter(Boolean);
  const rpmOk = fans.length > 0 && fans.every((f) => f.found === 'tach' && f.measured);
  const tgt = z.target || { preset: 'normal' };
  const own = Array.isArray(tgt.points);
  const kind = (z.sources && z.sources[0] && z.sources[0].kind) || ((setup().sources || []).find((s) => (z.sources || []).some((x) => x.id === s.id)) || {}).kind || 'disk';
  // the presets on the zone's own scale: its first kind's «ab» … «volle Drehzahl bei» (round 4: the degrees are settable)
  const kv = kindValues(z, kind);
  const presets = presetsOn(kv.low, kv.high);
  const choose = el('select');
  ['quiet', 'normal', 'cool'].forEach((p) => choose.appendChild(new Option(T(`preset.${p}`), p)));
  choose.appendChild(new Option(T('curve.own'), 'own'));
  choose.value = own ? 'own' : tgt.preset || 'normal';
  choose.disabled = z.unit === 'rpm';
  choose.onchange = () => changeZones((all) => {
    if (choose.value === 'own') {
      const base = presets ? presets[(all[i].target && all[i].target.preset) || 'normal'] : [[30, 20], [45, 80]];
      all[i].target = { points: base.map((p) => [Math.round(p[0]), Math.round(p[1])]) };
    } else all[i].target = { preset: choose.value };
    curves.delete(z.id);
  });
  const unit = el('select');
  unit.appendChild(new Option(T('zones.unit_pct'), 'pct'));
  unit.appendChild(new Option(T('zones.unit_rpm'), 'rpm'));
  unit.value = z.unit === 'rpm' ? 'rpm' : 'pct';
  unit.disabled = !rpmOk && z.unit !== 'rpm';
  unit.title = rpmOk ? T('zones.unit_tip') : T('zones.unit_off');
  unit.onchange = () => changeZones((all) => {
    all[i].unit = unit.value;
    const maxR = Math.min(...fans.map((f) => f.max_rpm || 2000));
    const base = Array.isArray(all[i].target.points) ? all[i].target.points : presets ? presets[all[i].target.preset || 'normal'] : [[30, 20], [45, 80]];
    all[i].target = { points: base.map((p) => [Math.round(p[0]), unit.value === 'rpm' ? Math.round(maxR * p[1] / 100 / 10) * 10 : Math.min(100, Math.round(100 * p[1] / maxR))]) };
    curves.delete(z.id);
  });
  const f2 = (label, input) => { const l = el('label', 'fb-field'); l.append(el('span', 'fb-field-label', label), input); return l; };
  box.appendChild(el('div', 'fb-row-fields'));
  box.lastChild.append(f2(T('zones.target'), choose), f2(T('zones.unit'), unit));
  const pts = own ? tgt.points : presets ? presets[tgt.preset || 'normal'] : null;
  const A = art();
  if (!A || !pts) { box.appendChild(waiting()); return box; }
  const lead = fans[0] || {};
  const look = fanLook(lead);
  const rpmUnit = z.unit === 'rpm';
  const vMax = rpmUnit ? Math.max(...fans.map((f) => f.max_rpm || 0), 500) : 100;
  const temps = pts.map((p) => p[0]);
  const tMin = Math.max(0, Math.floor((Math.min(...temps) - 10) / 5) * 5), tMax = Math.ceil((Math.max(...temps) + 10) / 5) * 5;
  // round 4: a CPU cooler given to him never under its own floor (cpu_floor) — that line instead of the lowest steady value
  const cpuFloor = lead.role === 'cpu' && lead.cpu_own ? Math.max(setup().cpu_floor || 40, num(lead.min_pct) ?? 0) : null;
  const floorPct = cpuFloor ?? (lead.measured && num(lead.min_pct) !== null ? lead.min_pct : null);
  const floor = floorPct === null ? null : rpmUnit ? (lead.table ? tableRpm(lead.table, floorPct) : null) : floorPct;
  const cz = (control().zones || []).find((x) => x.id === z.id);
  const nowC = cz && cz.warmest ? num(cz.warmest.mapped) ?? num(cz.warmest.c) : null;
  const key = `${z.id}|${rpmUnit}|${tMin}|${tMax}|${vMax}|${own}|${own ? '' : tgt.preset}|${floor}|${kv.low}|${kv.high}`;
  let cv = curves.get(z.id);
  if (!cv || cv.key !== key) {
    if (cv) cv.c.destroy();
    const c = A.curve({
      points: pts.map((p) => ({ t: p[0], v: p[1] })), unit: rpmUnit ? 'rpm' : 'percent', tMin, tMax, vMin: 0, vMax, floor: floor ?? undefined,
      now: nowC ?? undefined, fan: { mm: look.mm, blades: look.blades, min: look.min, max: look.max || 2000, colour: look.colour, look: look.look },
      rpmAt: lead.measured ? (v) => (v <= 0 ? 0 : tableRpm(lead.table, v)) : undefined,
      texts: {
        axisT: T('curve.axis_t'), axisV: rpmUnit ? T('curve.axis_rpm') : T('curve.axis_pct'), tickT: (x) => `${x}°`, tickV: (v) => (rpmUnit ? fmt.number(v) : `${v} %`),
        point: (k, n) => T('curve.point', { i: k + 1, n }), value: (x, v) => (rpmUnit ? T('curve.value_rpm', { t: x, v: fmt.number(v) }) : T('curve.value_pct', { t: x, v })),
        readout: (x, v, r) => T('curve.readout', { t: x, v: rpmUnit ? T('pic.rpm', { rpm: fmt.number(v) }) : T('value', { pct: Math.round(v) }), rpm: fmt.number(Math.round(r || 0)) }),
        floor: cpuFloor !== null ? T('curve.floor_cpu', { pct: cpuFloor }) : T('curve.floor'), now: nowC !== null ? T('curves.now', { c: fmt.number(nowC) }) : '',
      },
      // dragging a preset makes it the zone's own curve; kept on the page until «Save»
      onChange: (p) => {
        const zs = zonesNow();
        if (!zs[i]) return;
        zs[i].target = { points: p.map((x) => [x.t, x.v]) };
        zdraft = zs;
        cv.key = `${z.id}|${rpmUnit}|${tMin}|${tMax}|${vMax}|true||${floor}|${kv.low}|${kv.high}`;
        drawn = '';
        redraw();
      },
    });
    cv = { c, key };
    curves.set(z.id, cv);
  }
  cv.c.el.classList.add('fb-curve-edit');
  box.appendChild(cv.c.el);
  box.appendChild(para(T('zones.curve_sub')));
  return box;
}

// 6 — dry run
function dryStep() {
  const box = el('div', 'fb-step-body');
  if (!(setup().zones || []).length) {
    box.appendChild(para(T('dry.no_zones')));
    return box;
  }
  const run = button(dry ? T('dry.again') : T('dry.run'), T('dry.run_tip'), (b) => act('dry_run', {}, b, (j) => { dry = j.dry || null; }), 'btn');
  box.appendChild(bar(run));
  if (dry) {
    if (!dry.found) box.appendChild(para(T('dry.not_found', { why: dry.why || '' }), 'callout warn'));
    (dry.zones || []).forEach((z) => {
      const card = el('div', 'fb-dry-zone');
      card.appendChild(el('h4', 'fb-sub', z.name));
      const w = z.warmest && num(z.warmest.c) !== null ? T('dry.warmest', { where: srcLabel(z.warmest), c: fmt.number(z.warmest.c) }) : T('dry.nothing');
      card.appendChild(para(`${w}${z.asleep ? ' · ' + T('dry.asleep', { n: z.asleep }) : ''}`));
      // how he reacts here, in words (round 4): up at once, down by the leading kind's profile
      const lead = z.warmest && z.warmest.kind ? z.warmest.kind : 'disk';
      card.appendChild(para(T('dry.react', { react: T(`react.${z.react === 'quick' ? 'quick' : 'balanced'}`), how: downWords(lead, z.react) })));
      const tb = el('table', 'fb-table');
      const h = el('tr');
      h.append(el('th', '', T('dry.fan')), el('th', '', T('dry.would')), el('th', '', T('dry.now')));
      tb.appendChild(h);
      (z.fans || []).forEach((f) => {
        const tr = el('tr');
        const nowT = f.now ? (f.now.enable === 1 ? T('dry.now_fixed', { pct: Math.round((f.now.value || 0) / 2.55) }) : f.now.enable === 0 ? T('mode.full') : T('dry.now_auto')) : '–';
        tr.append(el('td', '', fanName(f.id)), el('td', 'fb-strong', num(f.pct) === null ? '–' : T('dry.would_pct', { pct: f.pct })), el('td', '', nowT));
        tb.appendChild(tr);
      });
      const wrap = el('div', 'fb-table-wrap');
      wrap.appendChild(tb);
      card.appendChild(wrap);
      box.appendChild(card);
    });
    box.appendChild(para(T('dry.nothing_written')));
    box.appendChild(bar(nextButton('dry')));
  }
  return box;
}

// 7 — take over / hand back
function takeStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const c = control();
  if (steers() || su.armed) {
    box.appendChild(para(steers() ? T('take.steers', { n: who().zones || 0 }) : myWhy(who().why) || T('take.armed'), steers() ? 'callout' : 'callout warn'));
    box.appendChild(para(T('take.hand_back_text')));
    box.appendChild(bar(button(T('take.hand_back'), T('take.hand_back_tip'), (b) => handBack(b), 'btn')));
    return box;
  }
  const why = [];
  if (situation() === 'other') why.push(T('step.blocked_other'));
  if (!(su.zones || []).length) why.push(T('take.no_zones'));
  if (stepRuns()) why.push(T('take.step_runs'));
  box.appendChild(para(T(cpuGiven() ? 'take.text_cpu' : 'take.text')));
  why.forEach((w) => box.appendChild(para(w, 'callout warn')));
  // round 7 (F3): his fans whose «before» is a fixed value someone left — said once, plainly, with the restart first
  const mine = new Set((su.zones || []).flatMap((z) => z.fans || []));
  const left = [...mine].map((id) => ({ id, b: (su.before || {})[id], f: setupFan(id) })).filter((x) => x.b && (x.b.fixed || x.b.mode === 'full'));
  if (left.length) {
    const toFixed = left.filter((x) => ((x.f && x.f.back) || su.back_default) !== 'auto' || x.b.auto === null || x.b.mode === 'full');
    const list = (toFixed.length ? toFixed : left).map((x) => ({ id: x.id, pct: x.b.mode === 'full' ? 100 : x.b.pct }));
    box.appendChild(para(T(toFixed.length ? 'take.fixed_before' : 'take.fixed_before_auto', { list: fixedList(list) }), 'callout warn'));
  }
  const b = button(T('take.go'), T('take.go_tip'), (btn) => {
    Office.dialog({
      title: T('take.confirm_title'),
      body: T('take.confirm', { n: (su.zones || []).length }),
      buttons: [{ text: t('common.cancel') }, { text: T('take.go'), kind: '', act: async () => { await act('take_over', {}, btn, () => Office.toast(T('take.done_toast'))); return true; } }],
    });
  }, 'btn');
  b.disabled = b.disabled || why.length > 0;
  box.appendChild(bar(b));
  if (c.why === 'hand_back' || c.why === 'stopped') box.appendChild(para(T('take.given_back')));
  return box;
}
function handBack(b) {
  Office.dialog({
    title: T('take.hand_back'),
    body: T('take.hand_back_confirm'),
    buttons: [{ text: t('common.cancel') }, { text: T('take.hand_back'), kind: '', act: async () => { await act('hand_back', {}, b, (j) => Office.toast(T('take.handed_toast', { n: j.given || 0 }))); return true; } }],
  });
}

// ------------------------------------------------------------------ the page
function render() {
  if (!view) return;
  if (!ready()) {
    // the head at once, the sections once everything they draw is there (no second drawing a moment later)
    const { head } = Office.deskHead(Office.desks.get(ID), { bubble: bubbleText(), pages: { list: PAGES(), current: page } });
    view.replaceChildren(head, waiting());
    return;
  }
  const plain = plainNow();
  const actions = page === '' ? [reportButton(true)] : [];
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: plain ? bubbleText() : Office.withGreeting(ID, bubbleText()), actions,
    pages: { list: PAGES(), current: page } });
  if (page === 'setup') {
    const help = Office.pageHelp(`${ID}-setup`, [[T('help.setup'), T('help.setup_text')], [T('help.safe'), T('help.safe_text')], [T('help.quiet'), T('help.quiet_text')],
      [T('help.later'), T('help.later_text')]]);
    const ssw = setupSwitcher();
    view.replaceChildren(head, help, ssw.el);
    if (!banner || banner.parentNode !== ssw.el) banner = ssw.el.insertBefore(el('div', 'fb-banner'), ssw.body);
    paintBanner();
    if (state) ssw.render();
    else ssw.body.replaceChildren(waiting());
    return;
  }
  const help = Office.pageHelp(ID, [
    [T('help.who'), T('help.who_text')],
    [T('help.picture'), T('help.picture_text')],
    [T('help.outputs'), T('help.outputs_text')],
    [T('help.show'), T('help.show_text')],
    [T('help.sensors'), T('help.sensors_text')],
    [T('help.curves'), T('help.curves_text')],
    [T('help.report'), T('help.report_text')],
  ]);
  view.replaceChildren(head, help, sw.el);
  if (!banner || banner.parentNode !== sw.el) banner = sw.el.insertBefore(el('div', 'fb-banner'), sw.body);
  paintBanner();
  sw.render();
}

Office.desk({
  id: ID,
  async mount(root, sub) {
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : '';
    drawn = '';
    banner = null;
    warmFrom = Date.now();
    // still waiting for something after WARM_MS: draw without it (ready() is in the signature — no redraw when it was there)
    setTimeout(() => { if (view === root) redraw(); }, WARM_MS + 100);
    if (page === 'setup') {
      // #/fanboy/setup/<step> picks that step like a click; else the step at hand once his state is in (never kept)
      let rest = String(sub || '').split('/').slice(1).join('/');
      if (rest === 'roles') rest = 'test';         // round 8: the roles step lives in «Testen und zuordnen» now
      const picked = setupSwitcher().mount(rest);
      if (picked) Office.subroute('setup');
      render();
      await load(false);
      if (view !== root) return;
      if (!picked) { setupSwitcher().pick(stepAtHand(), 'start'); drawn = ''; redraw(); }
      return;
    }
    sensorsBar().reset();
    sw.mount(sub);
    render();
    load(false);
    loadHistory();
  },
  unmount() {
    view = null;
    clearTimeout(timer);
    timer = null;
    stopAlive();
    if (pic) { pic.destroy(); pic = null; }
    pickers.forEach((p) => p.destroy());
    pickers.clear();
    if (bench) { bench.destroy(); bench = null; }
    said = null;
    curves.forEach((c) => c.c.destroy());
    curves.clear();
  },
  agentChanged() { if (view) redraw(); },            // the messenger's word with every answer: drawn only when it changed something (its state is in the signature)
  async reception() {
    if (!state) await load(false);
    if (!state) return null;
    const facts = [];
    const c = counts();
    if (c.fans) facts.push(T('fans.reporting', { n: c.turning || 0 }));
    if (S().hottest) facts.push(`${T('tile.hottest')}: ${T('temp', { c: fmt.number(S().hottest.c) })}`);
    return { bubble: bubbleText(), facts };
  },
});

// his places for the search (places.json beside desk.json lists the same keys)
const SETUP_ROUTE = { route: `#/${ID}/setup`, crumb: 'page.setup' };
Office.places(ID, [
  { kind: 'section', key: 'server.title', route: `#/${ID}/who` },
  { kind: 'section', key: 'notes.title', route: `#/${ID}/who` },
  { kind: 'section', key: 'dash.setting', route: `#/${ID}/who` },
  { kind: 'section', key: 'fans.title', route: `#/${ID}/fans` },
  { kind: 'section', key: 'watch.setting', route: `#/${ID}/fans` },
  { kind: 'section', key: 'sensors.title', route: `#/${ID}/hottest` },
  { kind: 'section', key: 'curves.title', route: `#/${ID}/today` },
  { kind: 'section', key: 'hints.title', route: `#/${ID}/hints` },
  { kind: 'section', key: 'drivers.title', route: `#/${ID}/hints` },
  { kind: 'help', key: 'help.who', text: 'help.who_text' },
  { kind: 'help', key: 'help.picture', text: 'help.picture_text' },
  { kind: 'help', key: 'help.outputs', text: 'help.outputs_text' },
  { kind: 'help', key: 'help.show', text: 'help.show_text' },
  { kind: 'help', key: 'help.sensors', text: 'help.sensors_text' },
  { kind: 'help', key: 'help.curves', text: 'help.curves_text' },
  { kind: 'help', key: 'help.report', text: 'help.report_text' },
  { kind: 'help', key: 'help.setup', text: 'help.setup_text', ...SETUP_ROUTE },
  { kind: 'help', key: 'help.safe', text: 'help.safe_text', ...SETUP_ROUTE },
  { kind: 'help', key: 'help.quiet', text: 'help.quiet_text', ...SETUP_ROUTE },
  { kind: 'setting', key: 'quiet.setting', route: `#/${ID}/setup/test`, crumb: 'page.setup' },
  { kind: 'help', key: 'help.later', text: 'help.later_text', ...SETUP_ROUTE },
  ...STEPS.map((k) => ({ kind: 'step', key: `step.${k}`, ...SETUP_ROUTE, route: `#/${ID}/setup/${k}` })),
  ...STEPS.map((k) => ({ kind: 'tile', key: `step.${k}`, anchor: `setup.tile.${k}`, ...SETUP_ROUTE, route: `#/${ID}/setup/${k}` })),
  ...sw.places(),
]);
// what his look holds worth finding: each output, tach input, sensor and zone (his words — never a path)
Office.placesFrom(ID, (s) => {
  if (!s || !Array.isArray(s.chips)) return [];
  const out = [];
  const su = s.setup || {};
  const named = new Map((su.fans || []).map((f) => [f.id, f.name]));
  s.chips.forEach((c) => {
    (c.outputs || []).forEach((o) => out.push({ text: named.get(`${c.name}:${c.address}:pwm${o.n}`) || T('out.name', { n: o.n }), sub: T('fans.title'), route: `#/${ID}/fans`, anchor: `out:${c.address}:${o.n}` }));
    (c.temps || []).forEach((x) => out.push({ text: x.label || T('sensors.row', { n: x.n }), sub: `${T('sensors.title')} · ${T(`kind.${c.kind}`)}`, route: `#/${ID}/hottest`, anchor: `temp:${c.address}:${x.n}` }));
  });
  (s.disks || []).forEach((d) => out.push({ text: srcLabel({ kind: 'disk', id: d.name }), sub: `${T('sensors.title')} · ${T('kind.disk')}`, route: `#/${ID}/hottest`, anchor: `temp:disk:${d.name}` }));
  (s.tools || []).filter((x) => x.installed || x.active).forEach((x) => out.push({ text: x.name, sub: `${T('hints.there_title')} · ${x.author}`, route: `#/${ID}/hints`, anchor: `plugin:${x.id}` }));
  (su.zones || []).forEach((z) => out.push({ text: z.name || z.id, sub: T('curves.title'), route: `#/${ID}/today`, anchor: `curve:${z.id}` }));
  return out.slice(0, 200);
});
})();
