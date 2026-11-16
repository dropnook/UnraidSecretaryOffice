/* Fanboy — the office's building services and his fans' biggest fan (docs/DEVELOPMENT.md «The desks»).
   Part 3, the page a person sets him up and lives with — two pages, each a row of tiles (core.js Office.sections()):
   · the overview: the server as a picture (fan-art.js FanArt.server(): the zones as pieces of a case, the fans turning
     with their air, the heat sources between them, the warmest framed, sleeping disks marked — fed by every look, the
     speeds gliding), the fans and outputs, the heat sources, the curves with «now» and what happened lately, the hints
     (why it's tricky, drivers, the report for the makers);
   · the setup (the wizard), each step a tile in the order of part 2's steps: detect → test each output upward and assign it (round 8: roles on its card)
     (the catalogue picker, name, place, «give back to») → measure → zones (his proposal, the curve to
     drag) → «Take over» (with what he would set right now). Nothing changes for good before «Take over»; a step that writes (test, measure) runs
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
const T0 = Office.scope(ID);
// The agent's own times in every sentence that names one (2026-11-16, the sentence audit; the state's `setup.times`):
// how long an output stays at full at most (test, show), the page's lease, the minutes until he tells. An older agent
// without them: the constants mirrored here (a test compares them with the agent's).
const TIMES = { up_for: 60, show_for: 60, lease: 45, tell_after: 900, idle_warm_after: 120, poll_live: 300 };
function timesNow() {
  try { return { ...TIMES, ...(state.setup.times || {}) }; } catch (e) { return TIMES; }     // (before the first state: the mirror)
}
const T = (k, p) => {
  const tm = timesNow();
  return T0(k, { up_s: tm.up_for, show_s: tm.show_for, lease_s: tm.lease, tell_min: Math.round(tm.tell_after / 60), idle_min: Math.round(tm.idle_warm_after / 60), ...(p || {}) });
};
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
// a fan's look (2026-11-16, the owner: «Farbe soll für Lüfter-Blätter und Lüfter-Gehäuse separat gewählt werden können,
// ebenfalls Art und Anzahl der Blätter. Modellauswahl macht nur typischen Vorschlag»): the page owns these lists, the
// agent stores the keys (fan_look frame, wheel, style, blades) — fan-art.js knows how to draw each
const LOOK_COLOURS = ['black', 'charcoal', 'graphite', 'grey', 'silver', 'white', 'beige', 'brown', 'frost'];
const LOOK_STYLES = ['swept-narrow', 'swept-wide', 'broad', 'straight', 'sickle'];
const NEUTRAL_LOOK = { frame: 'charcoal', blades: 'grey', hub: 'graphite', pads: null, style: 'swept-wide', shape: 'square', vanes: 0, rim: false, sticker: null, sticker_colour: 'black' };
// round 8 (the owner): «Testen und zuordnen» — the test and the roles in ONE step (the old route …/setup/roles opens it)
// 2026-11-16 (the owner): no dry-run step — five steps; what he would set now is a preview inside «take over»
const STEPS = ['detect', 'test', 'measure', 'zones', 'take'];

let state = null;
let history = null;             // the part `history`: {every, zones: {id: [[time, °C, %, rpm]…]}}
let view = null;
let page = '';                  // '' overview · 'setup'
let timer = null;
let alive = null;               // the step's lease while a step runs and a page of his is shown
// ONE banner element for his page, moved to the page shown (2026-11-15: a banner made anew at every visit left the old one
// standing beside it — the same red text twice)
const banner = el('div', 'fb-banner');
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
// (the sentence audit, 2026-11-16: «a zone stands at 100 %» is said only while one does — the alarm for a fan the BIOS
// steers, `bios_stands`, puts no zone to full: his zones steer on, he only watches that fan)
const alarmFull = () => (control().zones || []).some((z) => z.full === true);
const whyKey = (why) => (why === 'alarm' && !alarmFull() ? 'alarm_watch' : why);
const myWhy = (why) => (why && has(`why.${whyKey(why)}`) ? T(`why.${whyKey(why)}`) : '');
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
/**
 * Round 9 (H2): an output Fanboy set to full for safety (a fan that didn't start again; one the mainboard didn't take back)
 * — said on its card and in the fans list, undone there with «Zurück auf den Wert von vorher» (a process of its own: back,
 * a kick when it doesn't start)
 */
const safetyOf = (id) => (S().safety || {})[id] || null;
const restoreNow = () => S().restore || null;
const restoreRuns = () => { const x = restoreNow(); return !!x && (x.status === 'starting' || x.status === 'running'); };
function safetyBlock(id) {
  const x = safetyOf(id);
  if (!x) return null;
  const box = el('div', 'fb-safety');
  const pct = num((x.before || {}).value) === null ? '–' : Math.round(x.before.value / 2.55);
  box.appendChild(el('div', 'fb-strong', T(x.why === 'not_back' ? 'safety.not_back' : 'safety.no_restart', { pct })));
  const mine = restoreRuns() && (restoreNow().fans || []).includes(id);
  const b = button(T('safety.back'), T('safety.back_tip', { pct }), (btn) => act('safety_back', { fan: id }, btn));
  b.dataset.own = '1';
  if (mine) { b.replaceChildren(wave(), el('span', '', T('safety.backing'))); b.disabled = true; }
  else if (stepRuns() || showRuns() || restoreRuns()) { b.disabled = true; b.title = T('safety.wait'); }
  box.appendChild(bar(b));
  return box;
}
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
    : situation() === 'other' ? T('show.off.other') : stepRuns() ? T('show.off.step') : showRuns() && !mine ? T('show.off.one') : control().running && control().alarm ? T('show.off.alarm')
      : restoreRuns() ? T('safety.wait') : '';
  // round 9 (H3): in the show's pause its own button waits too (everything is back already; the fans start again)
  if (mine && ['pause', 'kick'].includes(x.phase)) { on.replaceChildren(wave(), el('span', '', T('show.pausing'))); b.disabled = true; b.title = T('now.pause_tip'); b.onclick = null; return b; }
  b.title = mine ? T('show.stop_tip') : why || T('show.tip');
  b.disabled = (!mine && !!why) || !state || !Office.agent.running;
  // round 8: the quiet switch counts for a show too — only while he doesn't steer (his control never stops another fan)
  b.onclick = () => (mine ? act('show_stop', { fan: id }, b)
    : act('show', { fan: id, quiet: quietOn() && !control().running }, b, () => Office.toast(T('show.toast', { fan: fanSay(id) }))));
  return b;
}
/** Seconds left until a server time (the state's own clock, moved on by the page's since it came) */
const secsTo = (until) => (num(until) === null ? null : Math.max(0, Math.round(until - ((S().time || 0) + (Date.now() - stateAt) / 1000))));
/** Is the output at full because of him right now (a test's, a show's, a safety full)? — its card and row framed */
function atFullNow(id) {
  const s = step();
  if (running(s) && s.kind === 'test_up' && s.current === id && (s.progress || {}).phase === 'full') return true;
  const x = showNow();
  if (showRuns() && x.fan === id && !['pause', 'kick'].includes(x.phase)) return true;
  return !!safetyOf(id);
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
  // the model proposes (its catalogue look); the frame's colour, the wheel's colour and the blades' kind are his where he
  // chose them. The one «colour» of before round 12 counts for frame and wheel. Nothing chosen and no model: the neutral wheel.
  const base = (c && c.look) || null;
  const old = f && LOOK_COLOURS.includes(f.colour) ? f.colour : null;
  const frame = (f && LOOK_COLOURS.includes(f.frame) ? f.frame : null) || old;
  const wheel = (f && LOOK_COLOURS.includes(f.wheel) ? f.wheel : null) || old;
  const style = f && LOOK_STYLES.includes(f.style) ? f.style : null;
  const look = frame || wheel || style ? { ...(base || NEUTRAL_LOOK), ...(frame ? { frame } : {}), ...(wheel ? { blades: wheel, hub: wheel } : {}), ...(style ? { style } : {}) } : base;
  // the blades' number: his, else the model's (a model whose maker doesn't say gets its blade kind's own number in fan-art.js)
  const blades = num(m.blades) ?? ((c && c.blades) || (look ? null : 7));
  return { mm: (c && c.mm) || m.mm || 120, blades, min, max, colour: 'auto', look, base,
    own: { frame: !!frame, wheel: !!wheel, style: !!style, blades: !!c && num(m.blades) !== null } };
}
/** A fan's name for the page: the one given, else «Output 4» */
function fanName(id) {
  const f = setupFan(id);
  const p = idParts(id);
  return f && f.name ? f.name : T('out.name', { n: p ? p.output : '?' });
}
/** «Output 4» — the connector's own name; it never disappears (he needs it to find the connector) */
const outName = (id) => T('out.name', { n: (idParts(id) || {}).output ?? '?' });
/** A fan in a sentence, a tip or a list: «Hinten rechts (Ausgang 4)» once it has a name, else «Ausgang 4» */
function fanSay(id) {
  const f = setupFan(id);
  return f && f.name ? T('fan.say', { name: f.name, out: outName(id) }) : outName(id);
}
/** The small line under a fan's bold name: its output (only when a name is the title), then whatever else belongs there */
const underName = (id, ...more) => [(setupFan(id) || {}).name ? outName(id) : '', ...more].filter(Boolean).join(' · ');
/** A heat source's name: Unraid's disks as Unraid names them (Disk 1, Parity), a sensor by its label */
function srcLabel(s) {
  if (!s) return '';
  if (s.kind === 'disk' || s.kind === 'ssd') {
    const m = /^disk(\d+)$/.exec(s.id);
    if (m) return T('src.disk', { n: m[1] });
    const p = /^parity(\d*)$/.exec(s.id);
    if (p) return p[1] ? T('src.parity_n', { n: p[1] }) : T('src.parity');
    if (/^ud-/.test(s.id)) return udLabel(s.id);
    return s.id;
  }
  const named = labels().get(s.id);
  return named || s.label || T(`kind.${has(`kind.${s.kind}`) ? s.kind : 'other'}`);
}
/**
 * A disk outside the array and the pools (round 13b; the agent's fanboySourceNames()): the Unassigned Devices plugin's
 * name for it, else «Nicht zugewiesen · 14.6 TB» — numbered when several without a name are the same size; never its id
 */
const udSize = (b) => (b / 1e12 >= 1 ? `${fmt.number(b / 1e12, b / 1e12 >= 10 ? 1 : 2)} TB` : `${fmt.number(b / 1e9)} GB`);
function udLabel(id, bare) {
  const all = (setup().sources || []).filter((x) => x.group === 'unassigned');
  const x = all.find((y) => y.id === id) || {};
  const d = (S().disks || []).find((y) => y.name === id) || {};
  // (a name like «dev1» says little on its own: outside its group — a sentence, the sensors' list — it says what it is)
  const name = (typeof x.name === 'string' && x.name) || (typeof d.label === 'string' && d.label) || '';
  if (name) return bare ? name : T('src.unassigned_name', { name });
  const size = udSize(num(x.size) ?? num(d.size) ?? 0);
  const alike = all.filter((y) => !y.name && udSize(num(y.size) ?? 0) === size);
  const nth = alike.length > 1 && alike.some((y) => y.id === id) ? alike.findIndex((y) => y.id === id) + 1 : 0;
  if (bare) return nth ? T('src.nth', { label: size, n: nth }) : size;
  return nth ? T('src.unassigned_n', { size, n: nth }) : T('src.unassigned', { size });
}
/** What a heat source reads, said after its name: its degrees, «asleep», or — a disk a VM uses while Unraid sees no temperature — «in use, temperature unknown» */
const srcReads = (x) => (num(x.c) !== null ? T('temp', { c: fmt.number(x.c) }) : x.in_use ? T('src.in_use') : x.asleep ? T('pic.asleep') : '');
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

/** The step at hand: the first not done (detect, test and assign, measure, zones, take) */
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
  return 'take';
}

// ------------------------------------------------------------------ loading
function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
let stateAt = 0;                // when the state came (the page's clock) — «noch n s» counts from the server's time then
function took(j) {
  if (j.ok && j.state) { state = j.state; stateAt = Date.now(); sawRun(); }
  // his picture with a red mark while something is wrong at his fans (its alarm variant, as the Night Watchman has one)
  if (state) Office.setDeskMood(ID, (control().running && control().alarm) || ['chip_missing', 'chip_gone', 'crashed'].includes(who().why)
    || (idle().on && Object.values(idle().tachs || {}).some((x) => x && x.state === 'alarm')) ? 'alarm' : '');
  if (view) redraw();
  plan();
}
/** The next look, and the step's lease while one runs */
function plan() {
  clearTimeout(timer);
  timer = null;
  if (!view) { stopAlive(); return; }
  timer = setTimeout(() => load(false), expectNow() ? 1000 : stepRuns() || showRuns() || restoreRuns() ? LOOK_STEP_MS : LOOK_MS);
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
    if (bannerSig() !== bannerDrawn) { bannerDrawn = bannerSig(); Office.keepInPlace(strip ? strip.el : null, paintBanner); }
    patch();
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
  const rest = ['test', 'measure'].includes(active) ? null : [busy, su.fans, su.zones, su.curves, step(), showNow(), dry, zdraft];
  return quiet([ready(), !!art(), !!cat, !!state, Office.agent.running, bubbleText(), plainNow(), situation(), control().running, su.armed, su.outputs, active, rest]);
}
/** What the step tiles say */
function tilesSig() {
  const su = setup(), s = step();
  return quiet([(su.fans || []).map((f) => [f.id, f.found, f.role, f.measured, f.no_turn, f.cpu_own]), (su.zones || []).length, s && [s.kind, s.status], !!dry, who(), su.armed, (control().alarm || {}).code,
    setupSw ? setupSw.active() : '']);
}
/** What the banner above the steps says */
const bannerSig = () => quiet([stepRuns(), showRuns(), Object.keys(S().safety || {}).length, who(), S().situation, control().running, control().alarm, control().net ? [control().net.level, control().net.source] : 0, control().armed, control().why, idle(), S().twins, S().vm, setup().zones && setup().zones.length, cpuGiven()]);
function overviewSig() {
  return quiet([ready(), !!art(), !!cat, Office.agent.running, busy, state, history && history.zones, period, period > 24 && history72 ? history72.zones : null, sw.active(), (sensorBar && sensorBar.read().words) || []]);
}

// ------------------------------------------------------------------ the bubble
function bubbleText() {
  if (!state) return T('bubble.loading');
  const w = who();
  const c = control();
  if (w.who === 'fanboy') return w.why === 'alarm' ? T(alarmFull() ? 'bubble.alarm' : 'bubble.alarm_watch') : T('bubble.steers', { n: w.zones || 0 });
  if (stepRuns()) return step().kind === 'measure' ? T('bubble.measuring') : step().kind === 'hub_look' ? T('bubble.hub_look') : T('bubble.testing');
  // round 9 (H4): while he shows a fan or one stands at full for safety — and that «Alles stoppen» brings everything back
  if (showRuns()) return T('bubble.showing');
  if (Object.keys(S().safety || {}).length) return T('bubble.safety');
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
const fixedList = (list) => (list || []).map((x) => T('fixed.item', { name: fanSay(x.id), pct: x.pct })).join(', ');
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
  b.disabled = busy || !Office.agent.running;
  b.onclick = () => onClick(b);
  return b;
}
/** An action of his from the page: the button waits, the new state drawn, a problem said plainly */
async function act(action, body, b, done) {
  // round 9 (H3): a click that can't go through now says why — never silence
  if (busy) { Office.toast(T('busy_wait'), true); return null; }
  if (!Office.agent.running) { Office.toast(t('errors.agent_away'), true); return null; }
  busy = true;
  const worked = working(b);
  if (b) b.disabled = true;
  await Office.freshState(ID);
  const j = await Office.api.post(`${ID}.${action}`, body || {});
  busy = false;
  worked();
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
    id, label: fanName(id), sub: underName(id, cf && cf.stand_since && held ? (num(cf.starts_at) !== null ? T('pic.stands_until', { c: fmt.number(cf.starts_at) }) : T('pic.stands_ok')) : sub), mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: look.colour, look: look.look,
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
    title: s.in_use ? T('src.in_use_title') : s.asleep ? T('pic.asleep_title') : '' });
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
  if (f && f.name) meta.appendChild(el('span', 'fb-row-out', T('out.name', { n: o.n })));       // named: its output stays, small
  const rpm = f ? rpmNow(id, f.tachs) : null;
  if (rpm !== null) meta.appendChild(el('span', '', T('pic.rpm', { rpm: fmt.number(rpm) })));
  const wc = watchChipOf(c, f);
  if (wc) meta.appendChild(wc);
  if (offered(c)) meta.appendChild(showButton(id, f));
  // round 9 (H2): set to full by him for safety — said here and undone here
  const sx = safetyOf(id);
  if (sx) {
    meta.appendChild(chipOfText('warn', T('safety.chip'), T(sx.why === 'not_back' ? 'safety.not_back' : 'safety.no_restart', { pct: num((sx.before || {}).value) === null ? '–' : Math.round(sx.before.value / 2.55) })));
    const sb = safetyBlock(id);
    if (sb) meta.appendChild(sb.lastChild);
  }
  const v = el('div', 'fb-value', num(o.pct) === null ? '–' : T('value', { pct: o.pct }));
  v.title = T('value_title', { value: o.value ?? '–' });
  const b = (setup().before || {})[id];
  const row = unfoldRow(`out:${id}`, { place: `out:${c.address}:${o.n}` }, f && f.name ? f.name : T('out.name', { n: o.n }), meta, v, [
    [T('tech.chip'), `${c.name} · ${c.address}${c.module ? ' · ' + c.module : ''}`],
    [T('tech.output'), `pwm${o.n} · ${c.hwmon}`],
    [T('tech.mode'), `pwm${o.n}_enable = ${o.enable ?? '–'} · ${o.value ?? '–'} / 255`],
    [T('tech.tachs'), f && (f.tachs || []).length ? f.tachs.map((n) => `fan${n}_input`).join(', ') : '–'],
    [T('tech.before'), b ? `${b.enable ?? '–'} · ${b.value ?? '–'} / 255` : '–'],
  ]);
  if (atFullNow(id)) row.classList.add('fb-row-now');         // round 9 (H3): at full because of him — framed
  return row;
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
  (S().disks || []).forEach((d) => out.push({ id: d.name, name: srcLabel({ kind: 'disk', id: d.name }), chip: null, kind: 'disk', c: d.temp, asleep: d.asleep, in_use: d.in_use === true, key: `disk:${d.name}`, disk: d.name,
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
  if (x.in_use) meta.appendChild(chipOfText('warn', T('src.in_use'), T('src.in_use_title')));
  else if (x.asleep) meta.appendChild(chipOfText('quiet', T('sensors.asleep'), T('sensors.asleep_title')));
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
  // round 12 fix, the net: a heat source in none of his zones is warm — every fan he steers at least at the net's level
  // (both numbers and the source from the control's state: control.net)
  const net = c.running && c.net && c.net.source && num(c.net.level) !== null ? c.net : null;
  if (net) {
    const known = (setup().sources || []).find((x) => x.id === net.source.id);
    const p = box('warn', T('net.on', { source: known ? srcLabel(known) : net.source.label || '', c: num(net.source.c) === null ? '–' : fmt.number(Math.round(net.source.c)), level: net.level }), { net: String(net.level) });
    p.dataset.nums = JSON.stringify([{ v: net.level, from: ['control', 'net', 'level'] }, ...(num(net.source.c) === null ? [] : [{ v: Math.round(net.source.c), from: ['control', 'net', 'source', 'c'], via: 'round' }])]);
  }
  // round 9 (H2): outputs he set to full for safety — named, undone in one click (each card has its own button too)
  const safe = page === 'setup' ? [] : Object.keys(S().safety || {});      // (the setup's strip says it and puts them back)
  if (safe.length) {
    const p = box('warn', T('safety.banner', { list: safe.map((id) => fanSay(id)).join(', ') }), { safety: 'full' });
    const b = button(T('safety.back_all'), T('safety.back_tip_all'), (btn) => act('safety_back', { fan: safe[0], all: true }, btn));
    if (stepRuns() || showRuns() || restoreRuns()) { b.disabled = true; b.title = T('safety.wait'); }
    p.appendChild(b);
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
    // (crashed: when he starts it again and when not any more — the state's times and restarts; an older agent: no numbers)
    const tm = setup().times || {}, rs = setup().restarts || null;
    const crashNums = w.why === 'crashed' && rs && num(rs.max) !== null && num(rs.window) !== null && num(tm.crash_pause) !== null;
    const p = box('warn', crashNums ? T(rs.given_up ? 'control.crashed_given_up' : 'control.crashed_n', { pause: tm.crash_pause, max: rs.max, window: Math.round(rs.window / 60) }) : T(`control.${w.why}`), { why: w.why });
    if (crashNums) p.dataset.nums = JSON.stringify([{ v: rs.max, from: ['setup', 'restarts', 'max'] }, { v: Math.round(rs.window / 60), from: ['setup', 'restarts', 'window'], via: 'mins' },
      ...(rs.given_up ? [] : [{ v: tm.crash_pause, from: ['setup', 'times', 'crash_pause'] }])]);
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
  } else if (s === 'ok' && !banner.children.length && !(page === 'setup' && (stepRuns() || showRuns() || Object.keys(S().safety || {}).length))) {
    // (while the setup holds outputs, «the BIOS steers every fan» would be untrue — the setup's strip says what runs)
    box('', steers() ? T(cpuGiven() ? 'situation.steers_cpu' : 'situation.steers', { n: w.zones || 0 }) : c.armed === false && (setup().zones || []).length ? T('situation.not_armed') : T('situation.ok'), { situation: s });
  }
  // round 6: a show whose give-back didn't read back as before (the fan may sit at 100 %)
  const sh = page === 'setup' ? null : showNow();        // the setup page's strip says how a show or a step ended
  if (sh && freshEnd(sh) && sh.status !== 'running' && sh.status !== 'starting' && sh.why === 'give_back') box('warn', T('show.give_back_failed', { fan: fanSay(sh.fan) }), { show: 'give_back' });
  if (sh && freshEnd(sh) && sh.status !== 'running' && sh.status !== 'starting' && sh.why === 'quiet_warm' && sh.quiet_stop) {
    box('warn', T(`show.${sh.why}`, { what: sh.quiet_stop.what || '', c: num(sh.quiet_stop.c) === null ? '' : fmt.number(sh.quiet_stop.c) }), { show: sh.why });
  }
  const s2 = step();
  if (s2 && freshEnd(s2) && s2.status === 'stopped' && ['page_gone', 'interrupted'].includes(s2.why) && page === '') {
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
  if (k === 'take') value = steers() ? T('who.fanboy', { n: who().zones || 0 }) : su.armed ? T('step.v.armed') : T('who.bios');
  if (here && !(k === 'take' && (steers() || su.armed))) sub.push(T('step.here'));
  return Office.stat({ label: T('step.n', { n: i + 1, name: T(`step.${k}`) }), value, sub, alert: k === 'take' && control().running && !!control().alarm, ...a });
}
function stepSection(k) {
  const s = el('section', 'section fb-step');
  s.dataset.step = k;
  s.appendChild(Office.sectionHead(T(`step.${k}`), T(`step.${k}_sub`), { place: `step.${k}` }));
  const body = ({ detect: detectStep, test: testStep, measure: measureStep, zones: zonesStep, take: takeStep })[k]();
  s.appendChild(body);
  return s;
}
const bar = (...nodes) => { const b = el('div', 'toolbar fb-bar'); nodes.filter(Boolean).forEach((n) => b.appendChild(n)); return b; };
const para = (text, cls) => el('p', cls || 'fb-hint-text', text);
/** A small wheel for a card (the setup's): turning as the output turns now */
function cardWheel(w) {
  const A = art();
  if (!A) return el('span', 'fb-wheel-wait');
  return A.wheel({ px: 64, air: false, ...w }).el;
}

// 1 — detect: what the board shows (read only) — the same rows as every step, nothing to click but «Next»
/** A row to read (not a button: nothing happens on a click, so it doesn't look as if) */
function readRow(wheel, name, sub, label, extra) {
  const r = el('div', 'fb-item fb-item-read' + (wheel ? '' : ' fb-item-plain'));
  if (wheel) { const w = el('span', 'fb-item-wheel'); w.appendChild(wheel); r.appendChild(w); }
  const txt = el('span', 'fb-item-text');
  txt.append(el('span', 'fb-item-name', name), el('span', 'fb-item-sub', sub || ' '));
  const lab = el('span', 'fb-item-label');
  if (label) lab.appendChild(label);
  if (extra) lab.appendChild(extra);
  r.append(txt, lab);
  r.title = sub || '';
  return r;
}
/** The next bar of a step drawn whole: its line(s) left, its button(s) right — one of them the primary one */
function nextBar(lines, acts) {
  const next = el('div', 'fb-next');
  const text = el('div', 'fb-next-text');
  [].concat(lines).filter(Boolean).forEach((x, i) => text.appendChild(typeof x === 'string' ? el('span', i ? 'fb-next-open' : 'fb-next-count', x) : x));
  const a = el('div', 'fb-next-acts');
  [].concat(acts).filter(Boolean).forEach((b) => a.appendChild(b));
  next.append(text, a);
  return next;
}
function detectStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  if (!(su.outputs || []).length) {
    // the banner above says why (never the same text twice on a page); here only that there is nothing to set up
    box.appendChild(para(T('step.nothing')));
    const go = el('a', 'btn small', T('detect.hints'));
    go.href = `#/${ID}/hints`;
    box.appendChild(bar(go, reportButton(true)));
    return box;
  }
  const list = el('div', 'fb-list fb-list-read');
  (su.outputs || []).forEach((id) => {
    const o = outputNow(id);
    const b = (su.before || {})[id] || {};
    const mode = o ? o.mode || 'unknown' : 'unknown';
    const label = !o ? chipOfText('quiet', T('detect.gone'), T('detect.gone'))
      : chipOfText(mode === 'auto' ? 'ok' : mode === 'unknown' ? 'quiet' : 'warn', T(`mode.${mode}`), b.fixed ? T('detect.fixed', { pct: b.pct ?? '–' }) : T(`mode.${mode}_title`));
    const A = art();
    const wheel = A ? A.wheel({ px: 40, air: false, rpm: o && num(o.pct) ? 2000 * o.pct / 100 : 0, max: 2000, state: 'bios', mm: 120 }).el : null;
    list.appendChild(Office.place(`detect:${id}`, readRow(wheel, T('out.name', { n: (idParts(id) || {}).output }), o ? T(`detect.mode.${mode}`, { pct: num(o.pct) ?? '–' }) : T('detect.gone'), label)));
  });
  box.appendChild(list);
  const fixed = Object.values(su.before || {}).filter((b) => b && b.fixed).length;
  const tachs = chips().filter(offered).reduce((n, c) => n + (c.fans || []).length, 0);
  const go = btn(T('step.next', { name: T('step.test') }), 'btn', () => { setupSwitcher().pick('test'); drawn = ''; redraw(); });
  box.appendChild(nextBar([T('detect.summary', { outputs: (su.outputs || []).length, inputs: tachs, sensors: (su.sources || []).length }), fixed ? T('detect.fixed_note', { n: fixed }) : ''], go));
  return box;
}

// ------------------------------------------------------------------ what runs now, said in one place
/**
 * What the setup holds right now — ONE reading for the strip, the list and the panel (the owner, 2026-11-15: «the GUI
 * doesn't show which one is running»), from what round 9's agent says: the step's `progress` {phase: full | pause |
 * kick, quiet, until}, the show's `phase` and `until`, `safety` (outputs he set to full for safety), `restore` (one going
 * back to its value from before). An older agent's first state lacks the phases: then the step's `current` is «at full».
 *   kind  test | show | measure | restore | null     id  the output at hand     quiet  the others stand meanwhile
 *   stopped [ids]   pause {left, kick, next} | null   queue [ids still to come]   safety [{id, why, before}]
 *   left  seconds it stays at full at most (the agent's `until`), else null — then `since` (the page's clock, ms)
 */
function runNow() {
  const s = step(), x = showNow();
  const r = { kind: null, id: null, quiet: false, stopped: [], pause: null, queue: [], safety: [], since: null, left: null, back: [], part: null };
  let phase = null, until = null;
  if (showRuns()) { r.kind = 'show'; r.id = x.fan; r.quiet = !!x.quiet; phase = x.phase || 'full'; until = x.until; }
  else if (running(s)) {
    const p = s.progress || {};
    r.kind = s.kind === 'measure' ? 'measure' : s.kind === 'hub_look' ? 'hub' : 'test';
    r.id = s.current || null;
    r.quiet = r.kind === 'test' && !!(p.quiet ?? s.quiet);
    r.queue = (s.fans || []).filter((id) => id !== s.current && !(s.done || []).includes(id));
    if (r.kind === 'test') { phase = p.phase || (r.id ? 'full' : null); until = p.until; }
    // round 12: the measuring's standstill part — the output at 0 (does it stop?), the start value looked for, the kick,
    // and the restart pause with its own kick (a kick without a percentage)
    if (r.kind === 'measure' && (p.phase === 'pause' || (p.phase === 'kick' && num(p.pct) === null))) { phase = p.phase; until = p.until; }
    else if (r.kind === 'measure' && ['stop_check', 'start_find', 'kick'].includes(p.phase)) r.part = { phase: p.phase, pct: num(p.pct), left: secsTo(p.until) };
    // round 14, the look for the eye at several fans on one connector: do all turn (hub_turn), all stand (hub_stop), do
    // all start again (hub_start) — then the restart pause as after every step
    if (r.kind === 'hub' && ['hub_turn', 'hub_stop', 'hub_start'].includes(p.phase)) r.part = { phase: p.phase, pct: num(p.pct), left: secsTo(p.until) };
    else if (r.kind === 'hub') { phase = p.phase === 'kick' ? 'kick' : 'pause'; until = p.until; }
  } else if (restoreRuns()) { r.kind = 'restore'; r.back = (restoreNow().fans || []).slice(); }
  // (round 11: the step says which output follows the pause — null on the last; an older state: the first still to come)
  const after = r.kind === 'test' || r.kind === 'measure' ? ((s.progress || {}).next !== undefined ? s.progress.next : r.kind === 'test' ? r.queue[0] || null : null) : null;
  if (phase === 'pause' || phase === 'kick') r.pause = { left: secsTo(until), kick: phase === 'kick', next: after || null };
  if (phase === 'full') r.left = secsTo(until);
  // standing on purpose: every other output the quiet test holds at 0 right now
  if (r.quiet && r.id && !r.pause) {
    r.stopped = (setup().outputs || []).filter((id) => { const o = outputNow(id); return id !== r.id && o && o.pct === 0 && o.mode === 'manual'; });
  }
  r.safety = Object.keys(S().safety || {}).map((id) => ({ id, ...S().safety[id] }));
  // without the agent's `until`: since when this output is at full, by the page's own clock from the look that first showed it
  const key = r.id && !r.pause && (r.kind === 'test' || r.kind === 'show') ? `${r.kind}:${(s && s.id) || (x && x.id) || ''}:${r.id}` : '';
  if (key !== fullKey) { fullKey = key; fullFrom = key ? Date.now() : 0; }
  r.since = key ? fullFrom : null;
  return r;
}
let fullKey = '', fullFrom = 0;
/** Seconds an output stays at full at most: the state's times (the agent's FANBOY_UP_FOR for a test, FANBOY_SHOW_FOR for «show me») */
const fullMax = (run) => (run && run.kind === 'show' ? timesNow().show_for : timesNow().up_for);
/** The percent an output had before Fanboy set it to full for safety (its tip and its words) */
const safetyPct = (x) => (num((x.before || {}).value) === null ? '–' : Math.round(x.before.value / 2.55));
/** What the page said last that must not get lost: a refusal, a click that came too early — until the next action */
let said = null;                // {text, warn}
function say(text, warn) {
  said = text ? { text, warn: warn !== false } : null;
  patch();
}
/** Everything built once, patched in place: the strip and the list-and-panel of the steps shown */
function patch() {
  if (strip && strip.el.isConnected) strip.update();
  [bench, mbench].forEach((b) => { if (b && b.el.isConnected) b.update(); });
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
  const done = working(clicked);
  sends++;
  if (!o.quietly) said = null;
  patch();
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
    if (j.state) { state = j.state; stateAt = Date.now(); sawRun(); }
    if (o.done) await o.done(j);
    if (!j.state) await load(true);
    return j;
  }).catch(() => null).then((j) => {
    sends--;
    done();
    if (view) redraw();
    plan();
    return j;
  });
  sending = job;
  return job;
}
/** «Alles stoppen»: whatever the setup holds goes back — a step, a show, the fans standing for a quiet test */
function stopAll() {
  expect({ note: T('strip.stopping'), done: () => !runNow().kind });
  return send('stop_all', {}, { done: () => Office.toast(T('stop_all_toast')) });
}

// ------------------------------------------------------------------ small parts of the list-and-panel
/** Why no test or show can start now, in a short line: another controller steers, or Fanboy does («Hand back» is step 6's) */
const noTestWhy = () => (situation() === 'other' ? T('step.blocked_other') : control().running ? T('step.blocked_steering') : '');
/** A button of the bench: its click queued (send), off only with its reason said beside it — never by a silent gate */
function btn(text, cls, on, tip) {
  const b = el('button', cls === undefined ? 'btn plain' : cls, text);
  b.type = 'button';
  b.dataset.own = '1';
  if (tip) b.title = tip;
  b.onclick = () => { clicked = b; on(b); clicked = null; };
  return b;
}
/**
 * A button that can't act right now is never dead (the owner, 2026-11-15: «GUI ist tot, ich kann nicht klicken»): it
 * looks set back and a click on it says why — as the toast and in the strip. Nothing of his setup is silently disabled.
 */
function offBtn(b, reason) {
  b.classList.add('fb-off');
  b.setAttribute('aria-disabled', 'true');
  b.title = reason;
  b.onclick = () => { Office.toast(reason, true); say(reason); };
  return b;
}
/**
 * The page waits for the agent to do what was asked (a test to begin, an output to end, everything to stop): it says so
 * with the wave — in the strip from the click until the state shows it (the owner saw a still page for seconds and took
 * it for dead). {now} replaces the strip's first line, {note} its second; over when done() holds, a refusal comes, or 25 s.
 */
let expecting = null;
function expect(e) {
  said = null;                    // a new action: what was said before is over
  expecting = { ...e, until: Date.now() + 25000 };
  patch();
  plan();
}
function expectNow() {
  if (expecting && (expecting.done() || Date.now() > expecting.until || (said && said.warn))) expecting = null;
  return expecting;
}
/**
 * A click answers within a blink (the owner clicks fast; the agent may take a second): the clicked button itself carries
 * the wave from the click until the agent has answered — whatever the strip says meanwhile
 */
let clicked = null;
function working(b) {
  if (!b || !b.isConnected || b.classList.contains('fb-working')) return () => {};
  const w = wave();
  b.classList.add('running', 'fb-working');
  b.prepend(w);
  return () => { w.remove(); b.classList.remove('running', 'fb-working'); };
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
  const full = (run.id === id && !run.pause && run.kind !== 'measure') || !!safetyOf(id);
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
  const sx = safetyOf(id);
  if (sx) return ['warn', T('safety.chip'), T(sx.why === 'not_back' ? 'safety.not_back' : 'safety.no_restart', { pct: safetyPct(sx) })];
  if (run.id === id && !run.pause && run.kind) return ['solid', T('label.now'), T('label.now_tip')];
  if (run.stopped.includes(id)) return ['quiet', T('label.stands'), T('label.stands_tip')];
  if (run.kind === 'test' && run.queue.includes(id)) return ['quiet', T('label.waits'), T('label.waits_tip')];
  if (found === 'ignored' || found === 'inverted') return ['warn', T(`out.${found}`), T(`out.${found}_title`)];
  if (found === 'empty') return ['quiet', T('label.empty'), T('label.empty_tip')];
  if (found === 'ask' || found === 'unclear') return ['warn', T('label.ask'), T('label.ask_tip')];
  if (f && f.role === 'cpu' && found) return f.cpu_own ? ['warn', T('label.cpu_own'), T('cpu.own_since', { date: Office.fmt.date(f.cpu_own) })] : ['quiet', T('label.cpu_bios'), T('roles.bios_note.cpu')];
  if (f && f.role && found) return [ROLES_BIOS.includes(f.role) ? 'quiet' : 'accent', T(`role.${f.role}`), isOurs(f) ? T('label.his_tip') : T('label.bios_tip')];
  if (found) return ['warn', T('label.what'), T('label.what_tip')];
  if (leftAlone(f)) return ['quiet', T('label.left'), T('label.left_tip')];
  return ['quiet', T('label.untested'), T('label.untested_tip')];
}

// ------------------------------------------------------------------ the strip: what runs now, on both pages
/**
 * ONE strip for the whole page, built once and patched in place: line 1 says what the setup holds right now (a test's
 * output at 100 % with the others standing or not, the pause with its seconds, a kick, «show me», measuring, an output
 * going back, one at 100 % for safety — or «nothing runs»), line 2 the last refusal or how a run ended, right the clock
 * of the running output and the buttons «Stop everything» / «Back to the value before». On the setup page it stands
 * above every step (two lines high from the start — nothing moves when a run begins) and sticks to the top of the
 * window while anything runs; on the overview it shows only while there is something to say.
 */
let strip = null;
function stripMake() {
  const root = el('div', 'fb-strip');
  const now = el('p', 'fb-strip-now');
  const note = el('p', 'fb-strip-note');
  note.setAttribute('role', 'status');
  note.setAttribute('aria-live', 'polite');
  const words = el('div', 'fb-strip-text');
  words.append(now, note);
  const main = el('div', 'fb-strip-main');
  main.append(wave(), words);
  const meterBar = el('span', 'fb-meter-bar');
  const meter = el('div', 'fb-meter');
  meter.appendChild(meterBar);
  const since = el('span', 'fb-strip-since');
  const side = el('div', 'fb-strip-side');
  const clock = el('div', 'fb-strip-clock');
  clock.append(since, meter);
  const acts = el('div', 'fb-strip-acts');
  side.append(clock, acts);
  root.append(main, side);
  let run = runNow();
  let ticker = null;
  let part = null;                // the seconds of the measuring's standstill look, as last said

  function update() {
    if (!state) return;
    run = runNow();
    part = run.part ? run.part.left : null;
    const s = step();
    let mood = 'idle', text = T('strip.idle');
    if (run.pause) {
      mood = 'run';
      const fan = run.pause.next || '';
      const left = run.pause.left;
      text = run.pause.kick ? (left !== null ? T('strip.kick', { s: left }) : T('strip.pause_plain'))
        : left !== null ? (fan ? T('strip.pause_next', { s: left, fan: fanSay(fan) }) : T('strip.pause', { s: left })) : T('strip.pause_plain');
    } else if (run.kind === 'restore') { mood = 'run'; text = T('strip.restore', { fan: run.back.map((id) => fanSay(id)).join(', ') }); }
    else if (run.kind === 'hub' && run.id) { mood = 'run'; text = run.part ? T(`strip.h.${run.part.phase}`, { fan: fanSay(run.id), pct: run.part.pct ?? '–' }) : T('strip.h.look', { fan: fanSay(run.id) }); }
    else if (run.kind === 'test' && run.id) { mood = 'run'; text = T(run.quiet ? 'strip.test_quiet' : 'strip.test', { fan: fanSay(run.id) }); }
    else if (run.kind === 'show' && run.id) { mood = 'run'; text = T(run.quiet ? 'strip.show_quiet' : 'strip.show', { fan: fanSay(run.id) }); }
    else if (run.kind === 'measure' && run.id) {
      const o = outputNow(run.id);
      const pt = run.part;
      text = !pt ? T('strip.measure', { fan: fanSay(run.id), pct: o && num(o.pct) !== null ? o.pct : '–' })
        : pt.phase === 'stop_check' ? (pt.left !== null ? T('strip.m.stop_check_s', { fan: fanSay(run.id), s: pt.left }) : T('strip.m.stop_check', { fan: fanSay(run.id) }))
          : T(`strip.m.${pt.phase}`, { fan: fanSay(run.id), pct: pt.pct ?? '–' });
      mood = 'run';
    } else if (run.kind) { mood = 'run'; text = T('strip.starting'); }
    else if (!Office.agent.running) { mood = 'warn'; text = T('strip.away'); }
    else if (run.safety.length) { mood = 'warn'; text = T('strip.safety', { n: run.safety.length, list: run.safety.map((y) => fanSay(y.id)).join(', ') }); }
    else if (page === 'setup' && situation() === 'other') text = T('strip.other');
    else if (page === 'setup' && control().running) text = T('strip.steers');
    // waiting for the agent to do what was asked: said with the wave from the click on
    const ex = expectNow();
    if (ex && ex.now && mood !== 'run') { mood = 'run'; text = ex.now; }
    const waits = !!ex || sends > 0;
    root.dataset.mood = mood === 'idle' && waits ? 'run' : mood;
    root.classList.toggle('running', mood === 'run' || waits);       // office.css shows the wave inside .running
    setText(now, text);
    // line 2: what was refused or said last; else how the last run ended; else what to know while it runs
    let line = '', warn = false;
    if (said) { line = said.text; warn = said.warn; }
    else if (ex && ex.note) line = ex.note;
    else if (sends > 0) line = T('busy_wait');
    else if (!run.kind && endedText(s)) { line = endedText(s); warn = true; }
    else if (!run.kind && showEndedText()) { line = showEndedText(); warn = true; }
    else if (run.kind === 'test' || run.kind === 'measure') line = T('strip.keep_open');
    setText(note, line);
    note.classList.toggle('fb-strip-warn', warn);
    root.classList.toggle('fb-strip-told', warn);
    swap(acts, [run.kind, run.safety.map((y) => y.id), Office.agent.running], () => {
      const out = [];
      // set to full for safety: back to the value before, for all of them at once (each panel has its own button)
      if (run.safety.length && !run.kind) {
        out.push(btn(T(run.safety.length > 1 ? 'safety.back_all' : 'safety.back'), 'btn', () => send('safety_back', { fan: run.safety[0].id, all: true }),
          run.safety.length > 1 ? T('safety.back_tip_all') : T('safety.back_tip', { pct: safetyPct(run.safety[0]) })));
      }
      if (['test', 'show', 'measure'].includes(run.kind)) out.push(btn(T('stop_all'), 'btn danger', () => stopAll(), T('stop_all_tip')));
      if (!Office.agent.running) out.forEach((b) => offBtn(b, T('strip.away')));
      return out;
    });
    root.classList.toggle('fb-strip-stuck', !!run.kind);
    if (run.kind) {
      // under Unraid's own header, and under the phone's sticky section head where there is one
      const menu = document.getElementById('menu');
      const head = view ? view.querySelector('.section-head.sticky') : null;
      const above = (menu && /^(sticky|fixed)$/.test(getComputedStyle(menu).position) ? menu.offsetHeight : 0)
        + (head && getComputedStyle(head).position === 'sticky' ? head.offsetHeight : 0);
      root.style.top = `${above}px`;
    }
    clockUpdate();
    if (run.kind && !ticker) ticker = setInterval(tick, 1000);
    if (!run.kind && ticker) { clearInterval(ticker); ticker = null; }
  }
  /** Each second while something runs: the seconds left and the bar — in place, nothing else moves */
  function tick() {
    const was = run.pause ? run.pause.left : null;
    run = runNow();
    const partWas = part;
    part = run.part ? run.part.left : null;
    if ((run.pause ? run.pause.left : null) !== was || part !== partWas) update(); else clockUpdate();
  }
  function clockUpdate() {
    const on = !!run.since && (run.kind === 'test' || run.kind === 'show');
    clock.classList.toggle('fb-clock-off', !on);
    if (!on) { setText(since, ''); return; }
    // how long it stays at full at most: the agent's word; without it, since when (the page's clock)
    const most = fullMax(run);
    const secs = run.left !== null ? most - Math.min(most, run.left) : Math.min(most, Math.max(0, Math.floor((Date.now() - run.since) / 1000)));
    setText(since, run.left !== null ? T('strip.left', { s: run.left }) : T('strip.since', { s: secs, max: most }));
    meterBar.style.width = `${Math.round(100 * secs / most)}%`;
  }
  update();
  return { el: root, update, destroy() { clearInterval(ticker); ticker = null; } };
}
/** Is there anything the strip must say on the overview (where it shows only then)? */
const stripNeeded = () => { const r = runNow(); return !!r.kind || r.safety.length > 0 || !!said || !!expectNow() || sends > 0; };

// ------------------------------------------------------------------ the list and the panel (every step that has rows)
/**
 * The setup's one way to show several things and work on one of them: a compact list (one row of one height each: a
 * small wheel, a name, a second line, ONE label) beside ONE panel for the row at hand, and under both the next bar
 * (what is still open, the one button that leads on). Built once, patched in place: a look changes words and wheels
 * where they stand. Narrow windows: the list becomes a grid of small tiles above the panel.
 *   o.ids()            the rows, in order
 *   o.row(id, run)     {name, sub, label: [chip class, text, tip], wheel: {…} | null, live, warn}
 *   o.panel(id, api)   → {el, update(), destroy()} — built when first shown, kept (api: now() what runs, sel(), select(id), update())
 *   o.next(api, run)   → {sig, text: () => [nodes], acts: () => [buttons]}
 *   o.first(run)       the row to show when none is chosen · o.follow(run) the row a new run moves the panel to
 */
function listPanel(o) {
  const root = el('div', 'fb-lp');
  const list = el('div', 'fb-list');
  list.setAttribute('role', 'group');
  list.setAttribute('aria-label', o.label);
  const slot = el('div', 'fb-panel-slot');
  const two = el('div', 'fb-two');
  two.append(list, slot);
  const next = el('div', 'fb-next');
  const nextText = el('div', 'fb-next-text');
  const nextActs = el('div', 'fb-next-acts');
  next.append(nextText, nextActs);
  root.append(two, next);
  const items = new Map();
  const panels = new Map();
  let sel = '';
  let followed = '';
  let run = runNow();
  const api = { now: () => run, sel: () => sel, select: (id) => { if (sel !== id) { sel = id; update(); } }, update: () => update() };

  function item(id) {
    let it = items.get(id);
    if (it) return it;
    const b = el('button', 'fb-item');
    b.type = 'button';
    b.dataset.row = id;
    const A = art();
    const wh = A && o.wheels !== false ? A.wheel({ px: 40, air: false, rpm: 0 }) : null;
    const pic2 = el('span', 'fb-item-wheel');
    if (wh) pic2.appendChild(wh.el);
    const name = el('span', 'fb-item-name');
    const sub = el('span', 'fb-item-sub');
    const txt = el('span', 'fb-item-text');
    txt.append(name, sub);
    const lab = el('span', 'fb-item-label');
    if (wh) b.append(pic2, txt, lab); else { b.classList.add('fb-item-plain'); b.append(txt, lab); }
    b.onclick = () => api.select(id);
    it = { el: b, wh, name, sub, lab };
    items.set(id, it);
    return it;
  }
  function update() {
    if (!state) return;
    run = runNow();
    const ids = o.ids();
    // the panel follows a newly started run's row — never while he types in the panel's fields
    const typing = slot.contains(document.activeElement) && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName);
    const to = o.follow ? o.follow(run) || '' : '';
    if (to && to !== followed && ids.includes(to) && !typing) sel = to;
    followed = to;
    if (!ids.includes(sel)) sel = [o.first ? o.first(run) : '', ids[0]].find((id) => id && ids.includes(id)) || '';
    if (list.children.length !== ids.length || ids.some((id, i) => list.children[i] !== item(id).el)) list.replaceChildren(...ids.map((id) => item(id).el));
    ids.forEach((id) => {
      const it = item(id);
      const r = o.row(id, run);
      if (it.wh && r.wheel) it.wh.update({ ...r.wheel, badge: '' });       // the row's label says «stands»; the small wheel just stands
      setText(it.name, r.name);
      setText(it.sub, r.sub || ' ');
      const [cls, text, tip] = r.label;
      swap(it.lab, [cls, text, !!r.live], () => {
        const c = chipOfText(cls, text, tip);
        if (r.live) { c.classList.add('running'); c.prepend(wave()); }
        return c;
      });
      it.el.setAttribute('aria-pressed', sel === id ? 'true' : 'false');
      it.el.classList.toggle('fb-live', !!r.live);
      it.el.classList.toggle('fb-item-warn', !!r.warn);
      it.el.title = T('list.row_tip', { name: r.title || r.name });
    });
    if (sel) {
      let pn = panels.get(sel);
      if (!pn) { pn = o.panel(sel, api); panels.set(sel, pn); }
      if (slot.firstChild !== pn.el) slot.replaceChildren(pn.el);
      pn.update();
    } else slot.replaceChildren(para(o.none || T('step.nothing')));
    two.classList.toggle('fb-two-none', !ids.length);
    const n = o.next(api, run);
    swap(nextText, ['t', n.sig], n.text);
    swap(nextActs, ['a', n.sig, Office.agent.running], () => { const out = n.acts(); if (!Office.agent.running) out.forEach((b) => { if (!b.dataset.go) offBtn(b, T('strip.away')); }); return out; });
  }
  update();
  return {
    el: root, update, api,
    destroy() {
      items.forEach((it) => { if (it.wh) it.wh.destroy(); });
      panels.forEach((p) => p.destroy());
    },
  };
}
/** «Next: <step>» in a next bar: primary once nothing of this step is open */
function goButton(to, primary) {
  const b = btn(T('step.next', { name: T(`step.${to}`) }), primary ? 'btn' : 'btn plain', () => { setupSwitcher().pick(to); drawn = ''; redraw(); });
  b.dataset.go = '1';
  return b;
}
/** The next bar's first line and, where something is open, its rows by name — a click shows that row */
function nextLines(count, open, api, done) {
  const out = [el('span', 'fb-next-count', count)];
  if (open.length) {
    const line = el('span', 'fb-next-open', T('next.open'));
    open.forEach(([id, name, tip]) => line.append(' ', btn(name, 'fb-link', () => api.select(id), tip)));
    out.push(line);
  } else if (done) out.push(el('span', 'fb-next-open', done));
  return out;
}

// ------------------------------------------------------------------ 2 — test and assign
/**
 * Step 2 (the owner's three tests, 2026-11-15: cards «jump», a click was swallowed, nothing said what runs): the quiet
 * switch with its honest hints, the list of every output and ONE panel for the output at hand — its big wheel, what it
 * is doing, its buttons, and everything that says what it is. While an output runs its panel answers «did you hear it?»
 * with the two buttons «found» / «heard nothing»: the answer travels with `step_next {fan, heard}` and the agent stores
 * it with the verdict (round 11) — no tach + found → a fan without feedback, no tach + nothing heard → nothing attached.
 */
let bench = null;
function benchMake() {
  const root = el('div', 'fb-bench');
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
  const outs = () => setup().outputs || [];
  const lp = listPanel({
    label: T('list.label'),
    ids: outs,
    first: (run) => [run.id, (run.safety[0] || {}).id, outs().find((id) => openWhy(id))].find((id) => id && outs().includes(id)) || '',
    follow: (run) => (run.kind === 'test' || run.kind === 'show' ? run.id : ''),
    row(id, run) {
      const f = setupFan(id);
      const rpm = f && f.found === 'tach' ? rpmNow(id, f.tachs) : null;
      return { name: fanName(id), title: fanSay(id), sub: underName(id, rpm !== null ? T('pic.rpm', { rpm: fmt.number(rpm) }) : ''),
        label: rowLabel(id, run), wheel: wheelNow(id, run), live: run.id === id && !run.pause && (run.kind === 'test' || run.kind === 'show'), warn: !!safetyOf(id) };
    },
    panel: (id, api) => panelMake(id, api.now, api.update),
    next(api, run) {
      const open = outs().map((id) => [id, openWhy(id)]).filter(([, w]) => w);
      const toTest = open.filter(([, w]) => w === 'test').map(([id]) => id);
      const tested = outs().length - toTest.length;
      const roles = outs().filter((id) => { const f = setupFan(id); return f && (f.role || f.found === 'empty'); }).length;
      const noTest = !!noTestWhy();
      const selOpen = openWhy(api.sel()) === 'test';
      return {
        sig: [tested, outs().length, roles, open, !!run.kind, noTest, selOpen],
        text: () => nextLines(`${T('step.v.tested', { n: tested, m: outs().length })} · ${T('step.v.assigned', { n: roles })}`,
          open.map(([id, w]) => [id, T('next.item', { fan: fanSay(id), why: T({ test: 'label.untested', ask: 'label.ask', role: 'label.what' }[w]) }), T(`next.open_${w}`)]), api, T('next.all_done')),
        acts: () => {
          const out = [];
          // the ONE primary button: the panel's own «Start the test» while its output is untested; else here
          if (toTest.length && !selOpen && !run.kind && !noTest) out.push(btn(T('next.test_open', { n: toTest.length }), 'btn', () => startTest(toTest), T('test.all_tip')));
          out.push(goButton('measure', !open.length && !run.kind));
          return out;
        },
      };
    },
  });
  root.append(quiet, lp.el);
  function update() {
    const on = quietOn();
    qBtn.on.setAttribute('aria-pressed', String(on));
    qBtn.off.setAttribute('aria-pressed', String(!on));
    qList.hidden = !on;
    qOff.hidden = on;
    lp.update();
  }
  update();
  return { el: root, update, destroy() { lp.destroy(); } };
}
/** Start the test for these outputs, in the board's order — the quiet switch as it stands */
function startTest(ids) {
  if (!ids.length) return null;
  expect({ now: T('strip.starting'), done: () => !!runNow().kind });
  return send('test_up', { fans: ids, quiet: quietOn() }, { fresh: true });
}
/**
 * A run's ending is said only while it is news (the owner's server, 2026-11-15: an hour-old «did not start again» greeted
 * him in red after a page load): the run was seen running on this page, or it ended within the last minute and a half
 */
const ranHere = new Set();
const FRESH_S = 90;
function sawRun() {
  const s = step(), x = showNow();
  if (running(s) && s.id) ranHere.add(s.id);
  if (showRuns() && x.id) ranHere.add(x.id);
}
const freshEnd = (x) => !!x && ((!!x.id && ranHere.has(x.id)) || (num(S().time) !== null && num(x.at) !== null && S().time - x.at <= FRESH_S));
/** How the last test or measuring ended, when it didn't simply finish: the page gone, stopped, too warm, a fan that didn't start again */
function endedText(s) {
  if (!s || !['test_up', 'measure'].includes(s.kind) || running(s) || !['stopped', 'failed'].includes(s.status) || !freshEnd(s)) return '';
  const why = s.status === 'failed' ? 'error' : s.why || 'stopped';
  // «did not start again — set to 100 %» is never claimed from a past run: while an output stands at 100 % the strip's first
  // line and its panel say so from `safety`; once it is back there is nothing to claim
  if (why === 'no_restart') return '';
  // too warm while measuring: which source, how warm, its limit (round 4: by its own kind — an NVMe pool's 50 °C stops nothing)
  if (why === 'warm' && s.warm && s.warm.source) {
    return T('step.ended.warm_what', { where: srcLabel({ kind: s.warm.kind === 'disk' || s.warm.kind === 'ssd' ? s.warm.kind : 'other', id: s.warm.source, label: s.warm.source }), c: fmt.number(s.warm.c), limit: fmt.number(s.warm.limit) });
  }
  if (why === 'quiet_warm' && s.quiet_stop) return T(`step.ended.${why}`, { what: s.quiet_stop.what || '', c: num(s.quiet_stop.c) === null ? '' : fmt.number(s.quiet_stop.c) });
  return has(`step.ended.${why}`) ? T(`step.ended.${why}`) : T('step.ended.stopped');
}
function showEndedText() {
  const sh = showNow();
  if (!sh || showRuns() || !freshEnd(sh)) return '';
  if (sh.why === 'give_back') return T('show.give_back_failed', { fan: fanSay(sh.fan) });
  if (sh.why === 'quiet_warm' && sh.quiet_stop) return T(`show.${sh.why}`, { what: sh.quiet_stop.what || '', c: num(sh.quiet_stop.c) === null ? '' : fmt.number(sh.quiet_stop.c) });
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
  const under = el('p', 'fb-panel-under');
  const line = el('p', 'fb-panel-line');
  const acts = el('div', 'fb-panel-acts');
  const why = el('p', 'fb-panel-why');
  const headText = el('div', 'fb-panel-headtext');
  headText.append(title, under, line, acts, why);
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
  // place and count can be filled at any time (the agent takes them only with a role: kept here and sent with it)
  let wantPlace = null, wantCount = null;
  place.onchange = () => { const f = setupFan(id); if (f && f.role) send('role', { fan: id, role: f.role, place: place.value, off_ok: !!f.off_ok && ROLES_OURS.includes(f.role) }, { quietly: true }); else { wantPlace = place.value; update(); } };
  const count = inputEl('select');
  for (let n = 1; n <= (num((setup().limits || {}).count_max) ?? 8); n++) count.appendChild(new Option(String(n), String(n)));
  count.onchange = () => { const f = setupFan(id); if (f && f.role) send('role', { fan: id, role: f.role, count: Number(count.value), off_ok: !!f.off_ok && ROLES_OURS.includes(f.role) }, { quietly: true }); else { wantCount = Number(count.value); update(); } };
  fields.append(field(T('fields.name'), name, 'fb-field-name'), field(T('fields.place'), place), field(T('roles.count'), count, 'fb-field-count'));
  // round 14: several fans on this connector — are they the same model? (yes: the measured table counts for all; no or
  // not said: Fanboy keeps them a little higher, the agent's `hub`)
  const alike = el('div', 'fb-what-row fb-alike');
  alike.hidden = true;
  const alikeBtns = new Map();
  const alikeBox = el('div', 'fb-what-picks');
  [['yes', true], ['no', false], ['unknown', null]].forEach(([k, v]) => {
    const b = btn(T(`hub.alike_${k}`), 'fb-pick', () => { const f = setupFan(id); if (f && f.role) send('role', { fan: id, role: f.role, alike: v, off_ok: !!f.off_ok && ROLES_OURS.includes(f.role) }, { quietly: true }); }, T(`hub.alike_${k}_tip`));
    alikeBtns.set(k, b);
    alikeBox.appendChild(b);
  });
  alike.append(el('span', 'fb-what-label', T('hub.alike')), alikeBox);
  const hubSays = el('p', 'fb-hub-says');
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
  root.append(head, safety, what, cpu, fields, alike, hubSays, note, look, back);
  let pk = null, lookUI = null;
  /**
   * «Aussehen (fürs Bild)»: maker and model only PROPOSE (the model's typical look fills the four controls); the frame's
   * colour, the wheel's colour, the blades' kind and their number are his, each saved alone and shown at once in the
   * preview. Another model proposes anew (one click back); «Zurück zum Vorschlag» resets the four to the model's.
   */
  function lookMake() {
    const want = {};                // what he just chose, shown before the agent has stored it
    let undo = null;                // his four values before another model proposed anew
    const prev = A.wheel({ px: 120, air: false, rpm: 0 });
    const prevBox = el('div', 'fb-look-prev');
    prevBox.appendChild(prev.el);
    const fNow = () => setupFan(id) || { id };
    pk = A.picker({ catalog: cat, value: fNow().model ? (fNow().model.id ? { id: fNow().model.id } : { mm: fNow().model.mm, blades: fNow().model.blades }) : null, preview: false, texts: pickerTexts(),
      onChange: (v) => {
        if (!v) return;
        const f = fNow();
        const L = fanLook(f);
        if (!v.id) { send('fan_look', { fan: id, model: '', mm: v.mm }, { quietly: true }).then(paint); return; }   // «another fan»: its size here, the rest below
        // a model proposes its typical look: the four controls take it (his own choices are kept for one click back)
        const before = L.own.frame || L.own.wheel || L.own.style || L.own.blades ? { model: f.model || null, frame: f.frame || '', wheel: f.wheel || '', style: f.style || '', blades: (f.model && f.model.blades) || 0 } : null;
        send('fan_look', { fan: id, model: v.id, frame: '', wheel: '', style: '', blades: 0, colour: 'auto' }, { quietly: true }).then((j) => { undo = j ? before : null; paint(); });
      } });
    pickers.set(id, pk);
    pk.el.querySelectorAll('select').forEach((x) => { x.classList.add('input', 'small', 'fb-input'); });
    const top = el('div', 'fb-look-top');
    top.append(pk.el, prevBox);
    const says = el('div', 'fb-look-says');
    const set = (key, value) => {
      const f0 = fNow();
      const body = { fan: id, [key]: value };
      // the one colour of before round 12 lives on as the frame's and the wheel's (whichever he isn't choosing just now)
      const old = LOOK_COLOURS.includes(f0.colour) ? f0.colour : null;
      if (old) {
        body.colour = 'auto';
        if (key !== 'frame' && !f0.frame) body.frame = old;
        if (key !== 'wheel' && !f0.wheel) body.wheel = old;
      }
      want[key] = value;
      paint();
      send('fan_look', body, { quietly: true }).then(() => { delete want[key]; paint(); });
    };
    const rowOf = (label, body) => { const r = el('div', 'fb-look-row'); r.append(el('span', 'fb-what-label', label), body); return r; };
    const swatches = (key) => {
      const box = el('div', 'fb-swatches fan-k');
      box.setAttribute('role', 'group');
      const btns = LOOK_COLOURS.map((name) => {
        const b = btn('', 'fb-swatch', () => set(key, name), T(`colour.${name}`));
        b.setAttribute('aria-label', T(`colour.${name}`));
        b.style.background = `var(--fan-k-${name})`;
        box.appendChild(b);
        return [name, b];
      });
      return { el: box, show: (v, off) => btns.forEach(([name, b]) => { b.setAttribute('aria-pressed', name === v ? 'true' : 'false'); b.disabled = off; }) };
    };
    const frame = swatches('frame'), wheel = swatches('wheel');
    const kinds = el('div', 'fb-kinds');
    const kindBtns = LOOK_STYLES.map((st) => {
      const b = btn('', 'fb-kind', () => set('style', st), T(`style.${st}`));
      const w = A.wheel({ px: 38, air: false, rpm: 0 });
      b.append(w.el, el('span', '', T(`style.${st}`)));
      kinds.appendChild(b);
      return [st, b, w];
    });
    const count = el('div', 'seg');
    count.setAttribute('role', 'group');
    count.setAttribute('aria-label', T('look.blades'));
    const countBtns = [5, 6, 7, 8, 9, 10, 11].map((n) => { const b = btn(String(n), '', () => set('blades', n)); count.appendChild(b); return [n, b]; });
    const rows = el('div', 'fb-look-rows');
    rows.append(rowOf(T('look.frame'), frame.el), rowOf(T('look.wheel'), wheel.el), rowOf(T('look.style'), kinds), rowOf(T('look.blades'), count));
    const note = el('div', 'fb-look-note');
    lookBody.append(el('p', 'fb-hint-text', T('look.text')), top, says, rows, note);
    let off = false;
    function paint() {
      const f0 = fNow();
      // as chosen a moment ago, before the agent has answered
      const f = { ...f0, frame: want.frame ?? f0.frame, wheel: want.wheel ?? f0.wheel, style: want.style ?? f0.style,
        model: { ...(f0.model || { id: null, mm: null }), blades: want.blades ?? (f0.model || {}).blades ?? null } };
      const L = fanLook(f);
      const c = catFan(f.model && f.model.id);
      const shown = L.look || NEUTRAL_LOOK;
      const n = L.blades || A.STYLE_BLADES[shown.style] || 7;
      prev.update({ mm: L.mm, blades: L.blades, min: L.min, max: L.max || 1500, rpm: Math.round((L.max || 1500) * 0.6), state: 'run', look: L.look, colour: 'auto' });
      frame.show(L.look ? shown.frame : null, off);
      wheel.show(L.look ? shown.blades : null, off);
      kindBtns.forEach(([st, b, w]) => {
        b.setAttribute('aria-pressed', L.look && shown.style === st ? 'true' : 'false');
        b.disabled = off;
        w.update({ mm: 120, blades: A.STYLE_BLADES[st] || 7, look: { ...shown, style: st, sticker: null, vanes: 0 }, rpm: 0, state: 'run', colour: 'auto' });
      });
      countBtns.forEach(([k, b]) => { b.setAttribute('aria-pressed', k === n ? 'true' : 'false'); b.disabled = off; });
      const mine = L.own.frame || L.own.wheel || L.own.style || L.own.blades;
      swap(says, [!!c, c && c.id, mine, !!undo, off], () => {
        const out = [];
        if (c) out.push(chipOfText(mine ? 'accent' : 'quiet', mine ? T('look.changed') : T('look.proposal'), mine ? T('look.changed_tip') : T('look.proposal_tip')));
        if (c && mine) out.push(btn(T('look.reset'), 'btn plain', () => { undo = null; send('fan_look', { fan: id, frame: '', wheel: '', style: '', blades: 0, colour: 'auto' }, { quietly: true }); }, T('look.reset_tip')));
        if (undo) {
          out.push(el('span', 'fb-look-took', T('look.took', { model: modelText({ id: c && c.id }) })));
          const u = undo;
          out.push(btn(T('look.undo'), 'btn plain', () => { undo = null; send('fan_look', { fan: id, model: (u.model && u.model.id) || '', frame: u.frame, wheel: u.wheel, style: u.style, blades: u.blades }, { quietly: true }).then(() => { const g = fNow(); pk.set(g.model ? (g.model.id ? { id: g.model.id } : { mm: g.model.mm, blades: g.model.blades }) : null); paint(); }); }, T('look.undo_tip')));
        }
        if (off) out.forEach((x) => { if (x.tagName === 'BUTTON') x.disabled = true; });
        return out;
      });
      const offBy = c && f0.measured && num(f0.max_rpm) && num(c.rpm_max) && Math.abs(f0.max_rpm - c.rpm_max) > Math.max(250, 0.3 * c.rpm_max);
      swap(note, [offBy ? f0.max_rpm : 0], () => (offBy ? para(T('roles.model_off', { measured: fmt.number(f0.max_rpm), sheet: fmt.number(c.rpm_max) }), 'callout') : []));
    }
    paint();
    return { update(f, away) { off = !!away; paint(); }, destroy() { prev.destroy(); kindBtns.forEach(([, , w]) => w.destroy()); } };
  }

  let picked = null;              // the role just clicked, shown pressed until the agent has answered
  function pickRole(k) {
    const f = setupFan(id);
    cpuAsk.delete(id);              // round 9 (H6): a role click never carries a half-made «Fanboy steers it» along
    picked = k;
    roleBtns.forEach((b, r) => b.setAttribute('aria-pressed', r === k ? 'true' : 'false'));
    const settle = () => { picked = null; update(); };
    if (f && f.found === 'empty' && k !== 'empty') { picked = null; update(); Office.toast(T('panel.empty_note'), true); say(T('panel.empty_note')); return; }      // nothing attached by its test: said, not sent
    if (k === 'empty') { send('answer', { fan: id, louder: false }).then(settle); return; }
    if (f && ['ask', 'unclear'].includes(f.found) && benchRun().id !== id) send('answer', { fan: id, louder: true }, { quietly: true });
    const extra = { ...(wantPlace !== null ? { place: wantPlace } : {}), ...(wantCount !== null ? { count: wantCount } : {}) };
    send('role', { fan: id, role: k, off_ok: !!(f && f.off_ok) && ROLES_OURS.includes(k), ...extra }).then((j) => { if (j) { wantPlace = null; wantCount = null; } settle(); });
  }

  function update() {
    const run = benchRun();
    const f = setupFan(id);
    const found = f ? f.found : null;
    const here = run.id === id && !!run.kind && !run.pause;
    const away = !Office.agent.running;
    const never = ['ignored', 'inverted'].includes(found);
    const noTest = noTestWhy();
    const sf = safetyOf(id);
    if (wh) wh.update(wheelNow(id, run));
    root.classList.toggle('fb-live', here);
    // the name is the title once he gave one; the output stays under it (he needs it to find the connector)
    setText(title, fanName(id));
    setText(under, f && f.name ? outName(id) : '');
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
    else if (!here && run.kind) reason = T('panel.busy', { fan: run.id ? fanSay(run.id) : '' });
    else if (!run.kind && noTest) reason = noTest;
    else if (!run.kind && never) reason = T(`test.${found}_hint`);
    else if (away) reason = T('strip.away');
    setText(why, reason);
    const ex = expectNow();
    const ending = here && !!ex && ex.ending === id;       // «weiter» clicked: the agent is ending this output
    swap(acts, [here, run.kind, more, found, leftAlone(f), !!noTest, away, open.length, open[0] === id, !!sf, queued, reason, ending], () => {
      const out = [];
      if (ending) { const w = el('span', 'fb-wait running'); w.append(wave(), el('span', '', T('strip.ending'))); return [w]; }
      if (queued) return out;                  // its turn comes in this run: nothing to start here (the strip has «Stop everything»)
      const blocked = !!run.kind || !!noTest || away;
      if (here && run.kind === 'show') {
        out.push(btn(T('show.stop'), 'btn', () => send('show_stop', { fan: id }), T('show.stop_tip')));
      } else if (here) {
        const next = (heard) => { expect({ note: T('strip.ending'), ending: id, done: () => { const r = runNow(); return r.id !== id || !!r.pause || !r.kind; } }); send('step_next', { fan: id, heard }); };
        out.push(btn(T(more ? 'test.found_next' : 'test.found_done'), 'btn', () => next(true), T('test.found_next_tip')));
        out.push(btn(T(more ? 'test.none_next' : 'test.none_done'), 'btn plain', () => next(false), T('test.none_tip')));
        out.push(btn(T('test.abort'), 'btn plain', () => send('step_stop', {}, { done: () => Office.toast(T('step.stopped_toast')) }), T('test.abort_tip')));
      } else if (['ask', 'unclear'].includes(found) && !run.kind) {
        out.push(btn(T('ask.yes'), 'btn', () => send('answer', { fan: id, louder: true }), T('test.yes_tip')));
        out.push(btn(T('ask.no'), 'btn', () => send('answer', { fan: id, louder: false }), T('test.no_tip')));
        out.push(btn(T('test.again_long'), 'btn plain', () => startTest([id]), T('test.again_tip')));
      } else if (!found && !leftAlone(f)) {
        // untested: the primary «Start the test» runs every open output in the board's order, this one first
        const rest = [id, ...open.filter((x) => x !== id)];
        out.push(btn(open.length > 1 ? T('test.start') : T('test.start_one'), 'btn', () => startTest(open.length > 1 ? rest : [id]), open.length > 1 ? T('test.all_tip') : T('test.one_tip')));
        if (open.length > 1) out.push(btn(T('test.only'), 'btn plain', () => startTest([id]), T('test.one_tip')));
      } else {
        out.push(btn(leftAlone(f) ? T('test.start_one') : T('test.again_long'), 'btn plain', () => startTest([id]), T('test.one_tip')));
        if (['tach', 'silent'].includes(found)) out.push(btn(T('show.button'), 'btn plain', () => { expect({ now: T('strip.starting_show', { fan: fanSay(id) }), done: () => !!runNow().kind }); send('show', { fan: id, quiet: quietOn() && !control().running }, { fresh: true }); }, T('show.tip')));
      }
      // answering the question writes no output: only the messenger away sets it back; a test or a show needs the way free.
      // Set back, never dead: a click says why.
      if (!here) out.forEach((b) => { const no = ['ask', 'unclear'].includes(found) && !run.kind && b !== out[2] ? away : blocked; if (no) offBtn(b, reason || T('strip.away')); });
      return out;
    });
    // set to full for safety: said here, with the way back (a process of its own: back, a kick when it doesn't start)
    safety.hidden = !sf;
    if (sf) {
      const going = restoreRuns() && (restoreNow().fans || []).includes(id);
      const waits = !going && (stepRuns() || showRuns() || restoreRuns());
      swap(safety, [sf.why || '', safetyPct(sf), away, going, waits], () => {
        const b = btn(T('safety.back'), 'btn', () => send('safety_back', { fan: id }), T('safety.back_tip', { pct: safetyPct(sf) }));
        if (going) { b.classList.add('running'); b.replaceChildren(wave(), el('span', '', T('safety.backing'))); b.disabled = true; }
        else if (away || waits) offBtn(b, away ? T('strip.away') : T('safety.wait'));
        const text = el('p', '', T(sf.why === 'not_back' ? 'safety.not_back' : 'safety.no_restart', { pct: safetyPct(sf) }));
        return [text, b, waits ? el('p', 'fb-safety-wait', T('safety.wait')) : null];
      });
    }
    // «Was ist das?»
    const cur = picked || (found === 'empty' ? 'empty' : (f && f.role) || '');
    roleBtns.forEach((b, k) => {
      b.setAttribute('aria-pressed', k === cur ? 'true' : 'false');
      b.disabled = away;
      if (k === 'unknown') setText(b, !found && !run.kind ? T('role.left') : T('role.unknown'));
    });
    // the CPU cooler: who steers it — right under the question, never behind a fold
    const isCpu = !!f && f.role === 'cpu';
    swap(cpu, [isCpu, isCpu && f.cpu_own, isCpu && found, cpuAsk.has(id), (cpuAsk.get(id) || {}).tick, away, ((setup().before || {})[id] || {}).auto ?? null], () => (isCpu ? cpuBlock(f, benchUpdate) : []));
    // the fields
    // an output Fanboy never touches (it doesn't obey, or works the other way round): nothing to say about a fan there
    what.hidden = fields.hidden = look.hidden = never;
    nameField(id, f, away);
    const roleSet = !!(f && f.role);
    place.disabled = count.disabled = away;
    if (document.activeElement !== place) place.value = roleSet ? f.place || '' : wantPlace || '';
    if (document.activeElement !== count) count.value = String(roleSet ? f.count || 1 : wantCount || 1);
    // several fans on the connector (the state's `hub`): the question, and what it means in the agent's own numbers
    const hub = roleSet && !never && f.hub ? f.hub : null;
    alike.hidden = !hub;
    const said2 = hub ? (f.alike === true ? 'yes' : f.alike === false ? 'no' : 'unknown') : '';
    alikeBtns.forEach((b, k) => { b.setAttribute('aria-pressed', k === said2 ? 'true' : 'false'); b.disabled = away; });
    const ours2 = hub && ROLES_OURS.includes(f.role) || (hub && f.role === 'cpu' && !!f.cpu_own);
    setText(hubSays, !hub ? '' : !ours2 ? '' : hub.mixed ? T('hub.mixed', { floor: hub.floor, start: hub.start }) : T('hub.same'));
    hubSays.hidden = !hubSays.textContent;
    hubSays.dataset.nums = JSON.stringify(hub && ours2 && hub.mixed ? [{ v: hub.floor, from: ['setup', 'fans', `=${id}`, 'hub', 'floor'] }, { v: hub.start, from: ['setup', 'fans', `=${id}`, 'hub', 'start'] }] : []);
    let hint = '';
    if (!roleSet && !never && found !== 'empty' && (wantPlace !== null || wantCount !== null)) hint = T('panel.kept');
    else if (found === 'empty') hint = T('panel.empty_note');
    else if (f && f.role === 'cpu') hint = f.cpu_own ? '' : T('assign.cpu_bios');        // (given to Fanboy: its block above says since when)
    else if (f && f.role === 'pump') hint = T('assign.never_stopped');
    else if (f && f.role === 'unknown') hint = T('roles.bios_note.unknown');
    else if (!roleSet && p && p.output === 1 && !never) hint = T('roles.cpu_hint');
    else if (f && num(f.kick_start) !== null) hint = T('assign.kick', { pct: Math.round(f.kick_start / 2.55) });
    else if (f && (f.count || 1) > 1) hint = T('roles.count_note', { n: f.count });
    else if (!roleSet && !never) hint = T('panel.role_first');
    setText(note, hint);
    // model and colour (for the picture)
    const m = f && f.model ? modelText(f.model) : '';
    setText(lookSum, m ? T('look.fold_is', { model: m }) : T('look.fold'));
    if (A && cat && !lookUI) lookUI = lookMake();
    if (lookUI) lookUI.update(f, away);
    // «give back to»: only for an output found on a fixed value that Fanboy may get
    const b = (setup().before || {})[id];
    const hasBack = !!(f && b && b.fixed && !ROLES_BIOS.includes(f.role)) && !never;
    back.hidden = !hasBack;
    if (hasBack) swap(backBody, [f.back, b.pct, b.auto ?? null, setup().back_default, away], () => backChoice(f, b));
  }
  update();
  return { el: root, update, destroy() { if (wh) wh.destroy(); if (lookUI) lookUI.destroy(); } };
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
    box.appendChild(bar(btn(T('cpu.back'), 'btn', () => send('role', { fan: f.id, role: 'cpu', name: f.name || '', place: f.place || '', cpu_own: false }), T('cpu.back_tip'))));
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
  const goes = go.onclick;
  const arm = () => { go.classList.toggle('fb-off', !tick.checked); go.onclick = tick.checked ? goes : () => { Office.toast(T('errors.fanboy_cpu_confirm'), true); say(T('errors.fanboy_cpu_confirm')); }; };
  arm();
  tick.onchange = () => { cpuAsk.set(f.id, { tick: tick.checked }); arm(); };
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
      else { const sf = setupFan(id); if (sf) sf.name = value; patch(); }
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
  [['auto', T('back.auto'), noAuto], ['fixed', T('back.fixed', { pct: b.pct ?? '–' }), false]].filter(([, , gone]) => !gone).forEach(([v, label]) => {
    const l = el('label', 'fb-radio');
    const r = el('input');
    r.type = 'radio';
    r.name = name;
    r.value = v;
    r.checked = cur === v;
    r.disabled = !Office.agent.running;
    r.onchange = () => send('back', { fan: f.id, back: v }, { quietly: true });
    l.append(r, el('span', '', label));
    box.appendChild(l);
  });
  if (noAuto) box.appendChild(para(T('back.no_auto_note', { pct: b.pct ?? '–' }), 'fb-strong'));
  else if (!f.back) box.appendChild(para(T('back.default_note')));
  return box;
}

// ------------------------------------------------------------------ 3 — measure: his fans, each its table % ↔ rpm
/**
 * Step 3 in the same parts as step 2: the list of his fans with feedback and ONE panel for the fan at hand — its wheel
 * turning as it turns now, where the measuring stands, the table as it grows (its room kept from the start). Hands off:
 * «Measure all» starts it, the strip says which fan and percent, «Stop everything» ends it.
 */
let mbench = null;
const measured = () => (setup().fans || []).filter((f) => isOurs(f) && f.found === 'tach').map((f) => f.id);
const toMeasure = () => measured().filter((id) => { const f = setupFan(id); return !f.measured && !f.no_turn; });
function startMeasure(ids) {
  if (!ids.length) return null;
  expect({ now: T('strip.starting_measure'), done: () => !!runNow().kind });
  return send('measure', { fans: ids }, { fresh: true });
}
function measureMake() {
  const lp = listPanel({
    label: T('measure.list'),
    none: T('measure.none'),
    ids: measured,
    first: (run) => ((run.kind === 'measure' || run.kind === 'hub') && run.id) || toMeasure()[0] || '',
    follow: (run) => (run.kind === 'measure' || run.kind === 'hub' ? run.id : ''),
    row(id, run) {
      const f = setupFan(id) || {};
      const under = run.kind === 'measure' && run.id === id;
      const look = fanLook(f);
      const rpm = rpmNow(id, f.tachs);
      const label = under ? ['solid', T('label.measuring'), T('label.measuring_tip')]
        : run.kind === 'measure' && run.queue.includes(id) ? ['quiet', T('label.waits'), T('label.waits_tip')]
          : f.no_turn ? ['warn', T('label.no_turn'), T('measure.no_turn')]
            : f.measured ? ['accent', T('label.measured'), T('measure.done', { min: f.min_pct, start: f.start_pct, max: fmt.number(f.max_rpm || 0) })]
              : ['quiet', T('label.unmeasured'), T('measure.not_yet')];
      return { name: fanName(id), title: fanSay(id), label, live: under,
        sub: underName(id, f.role === 'cpu' ? T(f.cpu_own ? 'label.cpu_own' : 'label.cpu_bios') : has(`role.${f.role}`) ? T(`role.${f.role}`) : '', rpm !== null ? T('pic.rpm', { rpm: fmt.number(rpm) }) : ''),
        wheel: { mm: look.mm, blades: look.blades, min: look.min, max: look.max || 2000, look: look.look, colour: under && !look.look && look.colour === 'auto' ? 'accent' : look.colour, rpm: rpm ?? 0, state: 'run' } };
    },
    panel: (id, api) => measurePanel(id, api),
    next(api, run) {
      const all = measured(), open = toMeasure();
      const noRun = !!noTestWhy();
      const selOpen = open.includes(api.sel());
      return {
        sig: [all, open, !!run.kind, noRun, selOpen],
        text: () => (all.length ? nextLines(T('step.v.measured', { n: all.length - open.length, m: all.length }), open.map((id) => [id, fanSay(id), T('measure.not_yet')]), api, T('next.measured_all')) : []),
        acts: () => {
          const out = [];
          if (open.length && !selOpen && !run.kind && !noRun) out.push(btn(open.length > 1 ? T('measure.all') : T('measure.rest'), 'btn', () => startMeasure(open), T('measure.all_tip')));
          out.push(goButton('zones', !open.length && !run.kind));
          return out;
        },
      };
    },
  });
  lp.el.classList.add('fb-measure');
  return lp;
}
/**
 * What the measuring's standstill part found about a fan (round 12), in a sentence — '' when it wasn't tried (a fan
 * measured before, or the measuring ended before that part)
 */
function standLine(f) {
  const st = f && f.stand;
  if (!st) return '';
  if (st.stops === false) return T('stand.floor', { rpm: num(st.floor_rpm) === null ? '–' : fmt.number(st.floor_rpm) });
  if (st.stops_safe === false) return T('stand.unsafe');
  if (num(st.start_from_stop) !== null) return T('stand.starts', { pct: st.start_from_stop });
  if (num(st.kick_start) !== null) return T('stand.kick', { pct: st.kick_start });
  return T('stand.stops');
}
function measurePanel(id, api) {
  const root = Office.place(`measure:${id}`, el('div', 'fb-panel'));
  root.dataset.fan = id;
  const A = art();
  const wh = A ? A.wheel({ px: 116, air: false, rpm: 0 }) : null;
  const pict = el('div', 'fb-panel-wheel');
  if (wh) pict.appendChild(wh.el);
  const title = el('h3', 'fb-panel-title');
  const small = el('p', 'fb-panel-under');
  const line = el('p', 'fb-panel-line');
  const acts = el('div', 'fb-panel-acts');
  const why = el('p', 'fb-panel-why');
  const headText = el('div', 'fb-panel-headtext');
  headText.append(title, small, line, acts, why);
  const head = el('div', 'fb-panel-head');
  head.append(pict, headText);
  const table = el('div', 'fb-measure-table');
  const note = el('p', 'fb-panel-note');
  const hubBox = el('div', 'fb-hub');
  root.append(head, table, note, hubBox);
  function update() {
    const run = api.now();
    const f = setupFan(id) || { id };
    const s = step();
    const under = run.kind === 'measure' && run.id === id;
    const away = !Office.agent.running;
    const noRun = noTestWhy();
    const look = fanLook(f);
    const o = outputNow(id);
    const rpm = rpmNow(id, f.tachs);
    if (wh) wh.update({ mm: look.mm, blades: look.blades, min: look.min, max: look.max || 2000, look: look.look, colour: under && !look.look && look.colour === 'auto' ? 'accent' : look.colour, rpm: rpm ?? 0, state: 'run' });
    root.classList.toggle('fb-live', under);
    setText(title, fanName(id));
    setText(small, underName(id, f.role === 'cpu' ? T(f.cpu_own ? 'label.cpu_own' : 'label.cpu_bios') : ''));
    const queued = run.kind === 'measure' && !under && run.queue.includes(id);
    const r = s && s.kind === 'measure' && s.results ? s.results[id] : null;
    let text = T('measure.not_yet');
    if (under && run.part) text = T(`measure.p.${run.part.phase}`, { pct: run.part.pct ?? '–' });
    else if (under) text = T('measure.now', { pct: o && num(o.pct) !== null ? o.pct : '–' });
    else if (queued) text = T('test.waiting');
    else if (f.no_turn) text = T('measure.no_turn');
    else if (f.measured) text = T('measure.done', { min: f.min_pct, start: f.start_pct, max: fmt.number(f.max_rpm || 0) });
    if (!under && r && r.result === 'warm') text = T('measure.warm');
    if (rpm !== null && !f.no_turn) text = `${text} · ${T('pic.rpm', { rpm: fmt.number(rpm) })}`;
    setText(line, text);
    let reason = '';
    const looking = run.kind === 'hub' && run.id === id;         // (round 14: its own block below asks and answers)
    if (queued) reason = T('panel.queued_measure');
    else if (looking) reason = '';
    else if (!under && run.kind) reason = T(run.kind === 'hub' ? 'panel.busy_hub' : 'panel.busy_measure', { fan: run.id ? fanSay(run.id) : '' });
    else if (!run.kind && noRun) reason = noRun;
    else if (away) reason = T('strip.away');
    setText(why, reason);
    const open = toMeasure();
    swap(acts, [under, run.kind, queued, !!f.measured, !!f.no_turn, !!noRun, away, open, open[0] === id, reason, looking], () => {
      const out = [];
      if (looking) return out;
      if (under) out.push(btn(T('test.abort'), 'btn plain', () => stopAll(), T('step.stop_tip')));
      else if (queued) return out;
      else if (!f.measured && !f.no_turn) {
        // the primary «Measure all»: every fan not measured yet, this one first
        const rest = [id, ...open.filter((x) => x !== id)];
        out.push(btn(open.length > 1 ? T('measure.all') : T('measure.one'), 'btn', () => startMeasure(open.length > 1 ? rest : [id]), open.length > 1 ? T('measure.all_tip') : T('measure.one_tip')));
        if (open.length > 1) out.push(btn(T('measure.only'), 'btn plain', () => startMeasure([id]), T('measure.one_tip')));
      } else out.push(btn(T('measure.again'), 'btn plain', () => startMeasure([id]), T('measure.one_tip')));
      if (!under && (run.kind || noRun || away)) out.forEach((b) => offBtn(b, reason || T('strip.away')));
      return out;
    });
    // the table as it is measured (the running fan's as it grows), its room kept from the start
    const rows = under && s.progress && s.progress.id === id && Array.isArray(s.progress.table) ? s.progress.table : f.table;
    swap(table, [rows || null, under ? null : [f.min_pct, f.start_pct]], () => (Array.isArray(rows) && rows.length ? tableEl(rows, under ? null : f) : para(T('measure.table_none'))));
    // what the standstill part found; a fan measured before it existed says what a new measuring would add
    setText(note, under ? '' : standLine(f) || (f.measured && !f.stand ? T('stand.unknown') : num(f.kick_start) !== null ? T('assign.kick', { pct: Math.round(f.kick_start / 2.55) }) : ''));
    const st = f.stand || {};
    const nums = under ? [] : st.stops === false ? [['floor_rpm', st.floor_rpm]] : st.stops_safe === false ? [] : num(st.start_from_stop) !== null ? [['start_from_stop', st.start_from_stop]] : num(st.kick_start) !== null ? [['kick_start', st.kick_start]] : [];
    note.dataset.nums = JSON.stringify(nums.filter(([, v]) => num(v) !== null).map(([k, v]) => ({ v, from: ['setup', 'fans', `=${id}`, 'stand', k] })));
    line.dataset.nums = JSON.stringify(!under && !queued && !f.no_turn && f.measured ? [{ v: f.min_pct, from: ['setup', 'fans', `=${id}`, 'min_pct'] }, { v: f.start_pct, from: ['setup', 'fans', `=${id}`, 'start_pct'] },
      { v: f.max_rpm, from: ['setup', 'fans', `=${id}`, 'max_rpm'] }] : []);
    hubPart(f, run, s, away, noRun);
  }
  /**
   * Round 14, several fans on this connector (the state's `hub`): whose table this is, the margin Fanboy keeps for
   * unlike fans (the agent's floor and start), and the look for the eye — «Mit den Augen prüfen» runs the agent's
   * `hub_look`; its three questions are answered here («Ja, alle» / «Nein» = step_next {heard}). What it found is the
   * state's (`hub.ok`, `hub.not_safe`, `hub.extra`), never the page's guess.
   */
  function hubPart(f, run, s, away, noRun) {
    const hub = f.hub || null;
    const looking = run.kind === 'hub' && run.id === id;
    const part = looking ? run.part : null;
    const res = !looking && s && s.kind === 'hub_look' && s.results && s.results[id] && freshEnd(s) ? s.results[id] : null;
    const busy = !looking && (run.kind || noRun || away) ? (run.kind ? T(run.kind === 'hub' ? 'panel.busy_hub' : 'panel.busy_measure', { fan: run.id ? fanSay(run.id) : '' }) : noRun || T('strip.away')) : '';
    hubBox.hidden = !hub;
    hubBox.classList.toggle('fb-live', looking);
    swap(hubBox, [hub, looking, part && [part.phase, part.pct], run.pause && looking, res, busy, f.found, f.role, f.cpu_own], () => {
      if (!hub) return [];
      const out = [el('h4', 'fb-sub', T('hub.title', { n: f.count || 2 })), para(T('hub.table'))];
      if (!hub.mixed) { out.push(para(T('hub.same'))); return out; }
      const margin = para(T('hub.mixed', { floor: hub.floor, start: hub.start }));
      margin.dataset.nums = JSON.stringify([{ v: hub.floor, from: ['setup', 'fans', `=${id}`, 'hub', 'floor'] }, { v: hub.start, from: ['setup', 'fans', `=${id}`, 'hub', 'start'] }]);
      out.push(margin);
      if (num(hub.extra) > 0) { const up = para(T('hub.extra', { extra: hub.extra })); up.dataset.nums = JSON.stringify([{ v: hub.extra, from: ['setup', 'fans', `=${id}`, 'hub', 'extra'] }]); out.push(up); }
      if (looking) {
        // the question of the moment, and its two answers
        const asks = part ? T(`hub.ask.${part.phase}`, { pct: part.pct ?? '–' }) : T('hub.ask.pause');
        out.push(el('p', 'fb-hub-ask', asks));
        const answer = part && part.phase !== 'hub_stop';
        out.push(bar(answer ? btn(T('hub.yes'), 'btn', () => send('step_next', { fan: id, heard: true }), T('hub.yes_tip')) : null,
          answer ? btn(T('hub.no'), 'btn plain', () => send('step_next', { fan: id, heard: false }), T('hub.no_tip')) : null,
          btn(T('test.abort'), 'btn plain', () => stopAll(), T('step.stop_tip'))));
        return out;
      }
      out.push(para(hub.not_safe ? T('hub.not_safe') : hub.ok ? T('hub.ok') : T('hub.unchecked')));
      if (res && has(`hub.result.${res.result}`)) out.push(para(T(`hub.result.${res.result}`, { floor: num(res.floor) ?? '–' }), 'callout'));
      // the look: for one of his with rpm feedback (the agent refuses any other)
      const his = ROLES_OURS.includes(f.role) || (f.role === 'cpu' && !!f.cpu_own);
      if (his && f.found === 'tach') {
        const b = btn(T(hub.ok || hub.not_safe ? 'hub.look_again' : 'hub.look'), hub.ok || hub.not_safe ? 'btn plain' : 'btn', () => { expect({ now: T('hub.starting'), done: () => !!runNow().kind }); send('hub_look', { fan: id }); }, T('hub.look_tip'));
        if (busy) offBtn(b, busy);
        out.push(bar(b));
      } else out.push(para(T(his ? 'hub.look_silent' : 'hub.look_bios')));
      return out;
    });
  }
  update();
  return { el: root, update, destroy() { if (wh) wh.destroy(); } };
}
function measureStep() {
  if (!mbench) mbench = measureMake();
  else mbench.update();
  return mbench.el;
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
let zsel = '';                  // the zone the panel shows
function zonesStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const ours = (su.fans || []).filter((f) => isOurs(f) && ['tach', 'silent'].includes(f.found));
  if (!ours.length) {
    box.appendChild(para(T('zones.no_fans')));
    const go = btn(T('step.next', { name: T('step.test') }), 'btn plain', () => { setupSwitcher().pick('test'); drawn = ''; redraw(); });
    box.appendChild(bar(go));
    return box;
  }
  const zones = zonesNow();
  const propose = () => button(T('zones.propose'), T('zones.propose_tip'), (b) => act('zones_propose', {}, b, (j) => { zdraft = (j.zones || []).map((z) => ({ ...z, name: z.name || T(`zones.kind.${z.kind}`) })); zsel = ''; }), zones.length ? 'btn plain' : 'btn');
  const add = () => (zones.length < (num((setup().limits || {}).zones_max) ?? 8) ? button(T('zones.add'), T('zones.add_tip'), () => changeZones((z) => { const id = freeId(z); z.push({ id, name: T('zones.new_name', { n: z.length + 1 }), fans: [], sources: [], unit: 'pct', target: { preset: 'normal' } }); zsel = id; }), 'btn plain') : null);
  if (!zones.some((z) => z.id === zsel)) zsel = zones.length ? zones[0].id : '';
  const unzoned = ours.filter((f) => !zones.some((z) => (z.fans || []).includes(f.id)));
  if (!zones.length) {
    // nothing yet: his proposal is the one clear next action
    const p = el('div', 'fb-panel fb-zone-start');
    p.append(el('h3', 'fb-panel-title', T('zones.start')), para(T('zones.empty')), bar(propose(), add()));
    box.appendChild(p);
  } else {
    const two = el('div', 'fb-two');
    const side = el('div', 'fb-zone-side');
    const list = el('div', 'fb-list');
    list.setAttribute('role', 'group');
    list.setAttribute('aria-label', T('step.zones'));
    zones.forEach((z) => {
      const b = el('button', 'fb-item fb-item-plain');
      b.type = 'button';
      b.dataset.row = z.id;
      b.setAttribute('aria-pressed', z.id === zsel ? 'true' : 'false');
      const txt = el('span', 'fb-item-text');
      txt.append(el('span', 'fb-item-name', z.name || z.id), el('span', 'fb-item-sub', T('zones.row', { fans: (z.fans || []).length, sources: (z.sources || []).length })));
      const lab = el('span', 'fb-item-label');
      const tgt = z.target || {};
      const empty = !(z.fans || []).length || !(z.sources || []).length;
      lab.appendChild(empty ? chipOfText('warn', T('zones.incomplete'), T('zones.incomplete_tip')) : chipOfText('quiet', tgt.points ? T('curve.own') : T(`preset.${has(`preset.${tgt.preset}`) ? tgt.preset : 'normal'}`), T('zones.curve')));
      b.append(txt, lab);
      b.title = T('list.row_tip', { name: z.name || z.id });
      b.onclick = () => { if (zsel !== z.id) { zsel = z.id; drawn = ''; redraw(); } };
      list.appendChild(b);
    });
    side.append(list, bar(add(), propose()));
    if (unzoned.length) side.appendChild(para(T('zones.unzoned', { list: unzoned.map((f) => fanSay(f.id)).join(', ') })));
    const slot = el('div', 'fb-panel-slot');
    const i = zones.findIndex((z) => z.id === zsel);
    if (i >= 0) slot.appendChild(zoneCard(zones[i], i, zones, ours));
    // heat sources in none of the zones: said above the zones, each one click into the zone shown (kept with «Save» like every change here)
    const loose = uncoveredNow(zones);
    if (loose.length) {
      const zn = i >= 0 ? zones[i].name || zones[i].id : '';
      const into = (ids) => changeZones((all) => { const z = all.find((x) => x.id === zsel); if (z) z.sources = [...(z.sources || []), ...ids.filter((sid) => !(z.sources || []).some((y) => y.id === sid)).map((sid) => ({ id: sid, kind: srcKind(sid) }))]; });
      box.appendChild(uncoveredBox(loose, ...(i >= 0 ? [el('span', 'fb-what-label', T('zones.uncovered_into', { zone: zn })),
        ...loose.map((x) => btn(srcLabel(x), 'btn plain', () => into([x.id]), T('zones.uncovered_in_tip'))),
        loose.length > 1 ? btn(T('zones.uncovered_all'), 'btn plain', () => into(loose.map((x) => x.id)), T('zones.uncovered_in_tip')) : null] : [])));
    }
    two.append(side, slot);
    box.appendChild(two);
  }
  const how = T('zones.how_often', { s: setup().round || 2 }) + (num(S().poll) !== null && S().poll > timesNow().poll_live ? ' ' + T('zones.poll', { min: Math.round(S().poll / 60) })
    + (num(S().disk_allow) > 0 ? ' ' + T('zones.poll_add', { add: S().disk_allow }) : '') : '');
  if (zdraft) {
    // changed here, not kept yet: «Save» is the one button of the moment
    const save = button(T('zones.save'), T('zones.save_tip'), (b) => act('zones_save', { zones: zdraft.map(cleanZone) }, b, () => { zdraft = null; curves.clear(); dry = null; dryFor = ''; }), 'btn');
    const undo = button(T('zones.undo'), T('zones.undo_tip'), () => { zdraft = null; curves.clear(); drawn = ''; redraw(); }, 'btn plain');
    const mark = el('span', 'fb-next-count');
    mark.appendChild(chipOfText('warn', T('zones.unsaved'), T('zones.save_tip')));
    box.append(para(how), nextBar([mark], [save, undo]));
  } else box.append(para(how), nextBar([zones.length ? T('step.v.zones', { n: zones.length }) : ''], zones.length ? goButton('take', true) : null));
  return box;
}
/**
 * The heat sources no zone has (round 12 fix, the agent's net): for the kept zones the agent's own list (setup.uncovered);
 * while he changes zones here, the same rule on what is drawn — the kinds the net watches (FANBOY_NET_KINDS, a test
 * compares) that are in none of these zones
 */
const NET_KINDS = ['disk', 'ssd', 'nvme'];
function uncoveredNow(zones) {
  const all = setup().sources || [];
  if (!zdraft || !zones) return (setup().uncovered || []).map((u) => all.find((x) => x.id === u.id) || { id: u.id, kind: u.kind, label: u.name, c: u.c, asleep: u.asleep });
  const inZones = new Set(zones.flatMap((z) => (z.sources || []).map((x) => x.id)));
  return all.filter((x) => NET_KINDS.includes(x.kind) && !inZones.has(x.id));
}
/** A heat source said in a sentence: its name and what it reads now (asleep: said, never a number) */
const srcSay = (x) => `${srcLabel(x)}${num(x.c) !== null ? ` (${T('temp', { c: fmt.number(Math.round(x.c)) })})` : x.in_use ? ` (${T('src.in_use')})` : x.asleep ? ` (${T('pic.asleep')})` : ''}`;
/** «These heat sources are in no zone: …» — the sentence, its numbers marked for the check, and what the caller adds */
function uncoveredBox(loose, ...more) {
  // (a calm hint, not an alarm — the owner's four sleeping disks outside the array; the banner warns once the net acts)
  const p = el('div', 'callout fb-callout fb-uncovered');
  // (the level the net holds the fans at: the state's times.net.warm_pct — an older agent without it: no number)
  const pct = num(((setup().times || {}).net || {}).warm_pct);
  const text = el('p', '', T(pct === null ? 'zones.uncovered' : 'zones.uncovered_pct', { list: loose.map(srcSay).join(', '), pct }));
  text.dataset.nums = JSON.stringify([...loose.filter((x) => num(x.c) !== null).map((x) => ({ v: Math.round(x.c), from: ['setup', 'sources', `=${x.id}`, 'c'], via: 'round' })),
    ...(pct === null ? [] : [{ v: pct, from: ['setup', 'times', 'net', 'warm_pct'] }])]);
  p.appendChild(text);
  const acts = more.filter(Boolean);
  if (acts.length) p.appendChild(bar(...acts));
  return p;
}
const freeId = (z) => { for (let i = 1; ; i++) { const id = `zone${i}`; if (!z.some((x) => x.id === id)) return id; } };
/** Only what zones_save reads: numbers as numbers, nothing of the page's own */
function cleanZone(z) {
  const out = { id: z.id, name: String(z.name || '').trim() || z.id, fans: (z.fans || []).slice(), unit: z.unit === 'rpm' ? 'rpm' : 'pct',
    sources: (z.sources || []).map((s) => { const o = { id: s.id }; ['low', 'high', 'limit'].forEach((k) => { if (num(s[k]) !== null) o[k] = s[k]; }); return o; }),
    target: z.target && Array.isArray(z.target.points) ? { points: z.target.points.map((p) => [Math.round(p[0]), Math.round(p[1])]) } : { preset: (z.target && z.target.preset) || 'normal' } };
  if (num(z.low) !== null) out.low = z.low;
  if (num(z.high) !== null) out.high = z.high;
  // round 13: the kinds' own curves — only those of kinds the zone still has a source of (the rest stay derived)
  const has2 = zoneKinds(z);
  const own = {};
  Object.entries(z.curves || {}).forEach(([k, v]) => {
    if (!has2.includes(k) || !v) return;
    if (Array.isArray(v.points) && v.points.length >= 2) own[k] = { points: v.points.map((p) => [Math.round(p[0]), Math.round(p[1])]) };
    else if (KC_PRESETS.includes(v.preset) && out.unit === 'pct') own[k] = { preset: v.preset };
  });
  if (Object.keys(own).length) out.curves = own;
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
  const card = Office.place(`zone:${z.id}`, el('div', 'fb-panel fb-zone'));
  const head = el('div', 'fb-zone-head');
  const name = inputEl('input', 'text');
  name.maxLength = 40;
  name.value = z.name || '';
  name.setAttribute('aria-label', T('zones.name'));
  name.onchange = () => changeZones((all) => { all[i].name = name.value; });
  head.appendChild(name);
  const rm = el('button', 'btn plain', T('zones.remove'));
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
    const nm = el('span', 'fb-zone-fan-name', fanName(id));
    const un = underName(id, f.role === 'cpu' ? T(f.cpu_own ? 'label.cpu_own' : 'label.cpu_bios') : '');
    if (un) nm.appendChild(el('span', 'fb-zone-fan-out', un));
    row.appendChild(nm);
    const move = inputEl('select');
    move.setAttribute('aria-label', T('zones.move', { fan: fanSay(id) }));
    zones.forEach((x, k) => move.appendChild(new Option(x.name || x.id, String(k))));
    move.value = String(i);
    move.onchange = () => changeZones((all) => { all[i].fans = all[i].fans.filter((x) => x !== id); all[+move.value].fans.push(id); });
    row.appendChild(move);
    const no = standWhy(f);
    if (no) {
      row.appendChild(el('span', 'fb-stand-no', no));
      // round 14, the way out right here: several fans on its connector and nobody said they are the same — say so, or
      // let Fanboy show them (the look for the eye in step 3, opened at this fan)
      if (f.hub && f.hub.mixed && !f.hub.ok && !f.hub.not_safe && no === T('zones.stand_hub')) {
        row.appendChild(btn(T('zones.hub_same'), 'btn plain', () => act('role', { fan: id, role: f.role, alike: true, off_ok: !!f.off_ok }, null), T('zones.hub_same_tip')));
        row.appendChild(btn(T('hub.look'), 'btn plain', () => { setupSwitcher().pick('measure'); drawn = ''; redraw(); if (mbench) mbench.select(id); }, T('zones.hub_look_tip')));
      }
    } else {
      const l = el('label', 'fb-check');
      const c = el('input');
      c.type = 'checkbox';
      c.checked = !!f.off_ok;
      const save = (extra) => act('role', { fan: id, role: f.role, name: f.name || '', place: f.place || '', off_ok: c.checked, ...(extra || {}) }, null);
      c.onchange = () => {
        if (f.role !== 'cpu' || !c.checked) { save(); return; }
        // the CPU cooler on the owner's risk may stand only behind its own plain confirmation
        c.checked = false;
        Office.dialog({
          title: T('zones.cpu_stand_title'),
          body: cpuStandWords(f, z),
          buttons: [{ text: t('common.cancel') }, { text: T('zones.cpu_stand_go'), kind: '', act: async () => { c.checked = true; await save({ cpu_off_confirm: true }); return true; } }],
        });
      };
      l.append(c, el('span', '', T('zones.off_ok')));
      l.title = T('zones.off_ok_tip');
      row.appendChild(l);
      // chosen but not in effect: said beside the box (the agent's word)
      if (f.off_ok && f.off_why && has(`off_why.${f.off_why}`)) row.appendChild(el('span', 'fb-stand-no', T(`off_why.${f.off_why}`)));
    }
    fl.appendChild(row);
  });
  const free = ours.filter((f) => !zones.some((x) => (x.fans || []).includes(f.id)));
  if (free.length) {
    const add = inputEl('select');
    add.appendChild(new Option(T('zones.add_fan'), ''));
    free.forEach((f) => add.appendChild(new Option(fanSay(f.id), f.id)));
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
  // round 13b: the disks outside array and pools (a VM's, or not assigned) are a group of their own, after the SSDs
  [...kinds.slice(0, 2), 'unassigned', ...kinds.slice(2)].forEach((k) => {
    const loose2 = (s) => s.group === 'unassigned';
    const list = (setup().sources || []).filter((s) => (k === 'unassigned' ? loose2(s) : !loose2(s) && ((s.kind === k) || (k === 'other' && !kinds.slice(0, -1).includes(s.kind)))));
    if (!list.length) return;
    const g = el('div', 'fb-src-group');
    g.appendChild(el('span', 'fb-src-kind', k === 'unassigned' ? T('src_group.unassigned') : T(`kind.${k}`)));
    if (k === 'unassigned') g.title = T('src_group.unassigned_tip');
    list.forEach((s) => {
      const on = inZone.has(s.id);
      const b2 = el('button', 'fb-src' + (on ? ' on' : ''), [k === 'unassigned' ? udLabel(s.id, true) : srcLabel(s), srcReads(s)].filter(Boolean).join(' · '));
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
  card.appendChild(curvePart(z, i));
  card.appendChild(reactPart(z, i));
  // the degrees per kind: his proposal is right for most — behind a fold named for what it holds
  const fine = keepFold(el('details', 'fb-fold'), `temps:${z.id}`, false);
  fine.appendChild(el('summary', '', T('temps.fold')));
  fine.appendChild(tempsPart(z, i));
  card.appendChild(fine);
  return card;
}
/**
 * What he agrees to when the CPU cooler may stand — built from the state only (the owner, 2026-11-15: «kein AI-Slop, der
 * nicht stimmt»): no temperature and no seconds the state doesn't give. From which temperature it runs again is said
 * comes from his curve by the control's own rule (curveRule() — fanboyCurveStart(), the 5 °C and the minute are the
 * agent's constants); what happens when Fanboy fails only as far as the board proved it (`auto_back_seen`).
 */
function cpuStandWords(f, z) {
  const r = curveRule(zonePts(z));
  const out = [T('cpustand.what')];
  out.push(r.start !== null ? T('cpustand.curve', { start: r.start, hard: r.hard, below: r.start - CPU_STAND_GAP, cool: CPU_STAND_AFTER }) : T('cpustand.never_zero'));
  // what he falls back to when Fanboy fails: the mainboard's automatic (round 4's rule for this cooler) — and whether that
  // automatic has ever been SEEN to take a fan back on this board (the state's auto_back_seen), nothing more
  const seen = setup().auto_back_seen;
  out.push(seen && num(seen.at) !== null ? T('cpustand.gone_seen', { date: Office.fmt.date(seen.at) }) : T('cpustand.gone_unseen'));
  out.push(T('cpustand.hang'));
  return out.join(' ');
}
/**
 * Why a fan may NOT stand still, in words — '' when «may stand still» can be offered. Never a box that can't be: a fan
 * without feedback or not measured yet (or measured before round 12: no standstill tried), one that proved it keeps
 * turning at 0 % or doesn't start again safely (`stand` from the measuring), a pump, a CPU cooler the BIOS steers. The
 * CPU cooler on the owner's risk is offered like any fan that proved both — behind its own confirmation.
 */
function standWhy(f) {
  if (!f) return T('zones.stand_measure');
  if (f.role === 'pump' || (f.role === 'cpu' && !f.cpu_own)) return T('zones.stand_never');
  if (f.found !== 'tach') return T('zones.stand_silent');
  if (!f.measured) return T('zones.stand_measure');
  const st = f.stand;
  if (!st) return T('zones.stand_again');                       // measured before the standstill was part of it
  if (st.stops === false) return T('zones.stand_floor', { rpm: num(st.floor_rpm) === null ? '–' : fmt.number(st.floor_rpm) });
  if (st.stops_safe !== true) return T('zones.stand_unsafe');
  // round 14: several unlike fans on the connector — only once the look for the eye said all of them start again
  if (f.hub && f.hub.mixed && f.hub.not_safe) return T('zones.stand_hub_no');
  if (f.hub && f.hub.mixed && !f.hub.ok) return T('zones.stand_hub');
  return '';
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
  const kinds = zoneKinds(z);
  if (!kinds.length) return box;
  box.appendChild(para(kinds.length > 1 ? T('temps.mixed', { kind: T(`kind.${kinds[0]}`) }) : T('temps.single')));
  const tb = el('table', 'fb-table fb-temps-table');
  const h = el('tr');
  h.append(el('th', '', T('temps.kind')), el('th', '', T('temps.low')), el('th', '', T('temps.high')), el('th', '', T('temps.limit')), el('th', '', ''));
  tb.appendChild(h);
  const field = (val, def, set, label) => {
    const n = inputEl('input', 'number');
    n.step = '1';
    n.value = String(val);
    n.placeholder = String(def);
    n.classList.add('fb-num');
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
    const rm = el('button', 'btn plain', T('temps.own_remove'));
    rm.type = 'button';
    rm.onclick = () => changeZones((all) => { const s2 = all[i].sources.find((q) => q.id === y.id); ['low', 'high', 'limit'].forEach((f) => delete s2[f]); });
    row.appendChild(rm);
    box.appendChild(row);
  });
  const pick = inputEl('select');
  pick.appendChild(new Option(T('temps.own_add'), ''));
  (z.sources || []).filter((y) => !own.includes(y)).forEach((y) => pick.appendChild(new Option(srcLabel(src.get(y.id) || { id: y.id, kind: srcKind(y.id) }), y.id)));
  pick.onchange = () => {
    if (!pick.value) return;
    changeZones((all) => { const s2 = all[i].sources.find((q) => q.id === pick.value); const v = kindValues(all[i], srcKind(pick.value)); s2.low = v.low; s2.high = v.high; s2.limit = v.limit; });
  };
  const back = el('button', 'btn plain', T('temps.reset'));
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
/** A choice that can't be: the control goes back to what holds, and the reason is said in words (toast and strip) */
function snapBack(select, value, reason) {
  select.value = value;
  Office.toast(reason, true);
  say(reason);
}
// the control's floors (agent/desks/fanboy-control.php FANBOY_FLOOR_MARGIN, FANBOY_BLIND_FLOOR — a test compares them): a
// measured fan with feedback never under its lowest steady value + 5, any other never under 40 %, a CPU cooler never
// under the state's cpu_floor
const FLOOR_MARGIN = 5;
const BLIND_FLOOR = 40;
function fanFloor(f) {
  // the agent's own number where the state has it (the setup's plan per fan: the hub's margin, the CPU cooler's floor included)
  if (f && num(f.floor) !== null) return f.floor;
  const base = f && f.measured && f.found === 'tach' && num(f.min_pct) !== null ? Math.min(100, f.min_pct + FLOOR_MARGIN) : BLIND_FLOOR;
  return f && f.role === 'cpu' && f.cpu_own ? Math.max(base, setup().cpu_floor || 40) : base;
}
/**
 * What the curve means for the zone's first fan, in one sentence built from its numbers only: where it stands (only a
 * fan that may stand, and only where the curve asks for nothing), the least it ever gets, from where it runs at full
 */
function curveWords(pts, floor, stands, cpu, more) {
  const out = [];
  const r = curveRule(pts);
  const m = more || {};
  // several kinds in the zone (round 13; fanboyCurveNeverZero()): a fan that isn't the CPU cooler stands only while no
  // kind asks for anything — a kind whose curve never reaches 0 keeps it running while one of its sources is awake;
  // the CPU cooler on his risk goes by the CPU's curve alone
  if (m.cpuElse) out.push(T('curve.says_cpu_else', { floor }));
  else if (stands && m.neverKind) out.push(T('curve.says_never_zero_kind', { kind: m.neverKind, floor }));
  else if (stands && m.multi && r.start !== null) out.push(T('curve.says_stand_all', { t: r.start, floor }));
  else if (stands && r.start !== null) out.push(T('curve.says_stand', { t: r.start, floor }));
  else if (stands) out.push(T('curve.says_never_zero', { floor }));
  else out.push(T('curve.says_floor', { floor }));
  const full = pts.find((p) => p[1] >= 100);
  if (full) out.push(T('curve.says_full', { t: full[0] }));
  // a CPU cooler that stands: when it may stop again and when one reading alone starts it (the control's rule)
  if (cpu && stands && !m.cpuElse && r.start !== null) out.push(T('curve.says_cpu', { below: r.start - CPU_STAND_GAP, cool: CPU_STAND_AFTER, hard: r.hard }));
  return out.join(' ');
}
// the CPU cooler's standstill by its curve (agent/desks/fanboy-control.php: FANBOY_CPU_STAND_GAP, FANBOY_CPU_STAND_AFTER,
// fanboyCurveStart() — a test compares the constants; testFanboyR12Curve shows the rule)
const CPU_STAND_GAP = 5;
const CPU_STAND_AFTER = 60;
/**
 * What a curve means for standing, as the control reads it: `start` — the last temperature at which it asks for nothing
 * before it rises (null: it never asks for nothing) —, `hard` — where it first reaches its highest value
 */
function curveRule(pts) {
  const zeros = pts.filter((p, i) => p[1] < 0.5 && pts.slice(i + 1).some((q) => q[1] >= 0.5));
  const top = Math.max(...pts.map((p) => p[1]));
  return { start: zeros.length ? zeros[zeros.length - 1][0] : null, hard: (pts.find((p) => p[1] >= top) || [null])[0] };
}
/** The CPU's curve of a zone as it stands on the page, in the CPU's own degrees (round 13: fanboyCpuCurve() — the CPU kind's own curve or the one derived from the zone's) */
function zonePts(z) {
  return kindCurveOf(zoneKinded(z), 'cpu', setup().scales || {}).points;
}
/** The zone's curve: quiet, normal, cool — or its own, dragged; percent, or rpm where every fan is measured */
// >>> kind curves (pure — a test runs this block in node against the agent's fanboyKindCurve())
// Round 13: every kind of a zone's heat sources has its curve, in its own degrees: its own (points, or a preset on the
// kind's «ab» … «volle Drehzahl bei»), else the zone's curve moved from the zone's scale onto the kind's.
const KC_PRESETS = ['quiet', 'normal', 'cool'];
const kcNum = (v) => typeof v === 'number' && Number.isFinite(v);
function kcValues(zone, kind, scales) {
  const d = (scales || {})[kind] || (scales || {}).other || { low: 35, high: 55 };
  const own = (zone.kinds || {})[kind] || {};
  return { low: kcNum(own.low) ? own.low : d.low, high: kcNum(own.high) ? own.high : d.high };
}
function kcPreset(preset, lo, hi) {
  const mid = (lo + hi) / 2;
  if (preset === 'quiet') return [[lo, 0], [mid, 30], [hi, 60], [hi + 5, 100]];
  if (preset === 'cool') return [[lo - 3, 35], [mid - 3, 65], [hi - 3, 100]];
  return [[lo, 20], [mid, 45], [hi, 80], [hi + 3, 100]];
}
function kcZoneScale(zone, scales) {
  const kind = (zone.sources && zone.sources[0] && zone.sources[0].kind) || 'disk';
  const d = kcValues(zone, kind, scales);
  const lo = kcNum(zone.low) ? zone.low : d.low, hi = kcNum(zone.high) ? zone.high : d.high;
  return [lo, hi > lo ? hi : lo + 10];
}
function kcSorted(points) { return points.map((p) => [Number(p[0]), Number(p[1])]).sort((a, b) => a[0] - b[0]); }
function kcZoneCurve(zone, scales) {
  const points = zone.target && zone.target.points;
  if (Array.isArray(points) && points.length >= 2) return kcSorted(points);
  const [lo, hi] = kcZoneScale(zone, scales);
  return kcPreset(zone.target && KC_PRESETS.includes(zone.target.preset) ? zone.target.preset : 'normal', lo, hi);
}
function kindCurveOf(zone, kind, scales) {
  const kv = kcValues(zone, kind, scales);
  const kl = kv.low, kh = kv.high;
  const own = zone.curves && typeof zone.curves === 'object' ? zone.curves[kind] : null;
  if (own && typeof own === 'object') {
    if (Array.isArray(own.points) && own.points.length >= 2) return { points: kcSorted(own.points), own: true };
    if (KC_PRESETS.includes(own.preset)) return { points: kcPreset(own.preset, kl, kh > kl ? kh : kl + 10), own: true };
  }
  const [lo, hi] = kcZoneScale(zone, scales);
  const curve = kcZoneCurve(zone, scales);
  if (kh <= kl) return { points: curve, own: false };
  return { points: curve.map((p) => [kl + (p[0] - lo) * (kh - kl) / (hi - lo), p[1]]), own: false };
}
// <<< kind curves
/** A zone with each source's kind on it (as the agent stores zones) — what the block above reads */
const zoneKinded = (z) => ({ ...z, sources: (z.sources || []).map((x) => ({ ...x, kind: x.kind || srcKind(x.id) })) });
/** Degrees as they are: whole, or with their tenth (a preset's middle point may lie at 39.5 °C) */
const degrees = (c) => fmt.number(c, Number.isInteger(c) ? 0 : 1);
let curveFocus = null;           // the curve's typed field he is in while its chart is built anew (zone, point, axis)
const kindSel = new Map();      // zone id → the kind whose curve is in front
/**
 * A zone's curves, ONE chart (round 13, the owner: «eine Grafik pro Zone, eine Kurve pro Art»): the zone's «Ziel» counts
 * for every kind of its heat sources, each on its own degrees; dragging a kind's curve makes it that kind's own. The
 * kind picked is in front with its handles, the others faded behind it; while he steers, a dot on each curve says where
 * that kind's warmest source stands and what it asks for, and one line says which of them the fans follow right now —
 * all from the control's state (by_kind, leader).
 */
function curvePart(z, i) {
  const box = el('div', 'fb-curve-part');
  box.appendChild(el('h4', 'fb-sub', T('zones.curve')));
  const fans = (z.fans || []).map(setupFan).filter(Boolean);
  const rpmOk = fans.length > 0 && fans.every((f) => f.found === 'tach' && f.measured);
  const tgt = z.target || { preset: 'normal' };
  const zoneOwn = Array.isArray(tgt.points);
  const scales = setup().scales || {};
  const zk = zoneKinded(z);
  // the presets on the zone's own scale: its first kind's «ab» … «volle Drehzahl bei» (round 4: the degrees are settable)
  const [zLo, zHi] = kcZoneScale(zk, scales);
  const presets = presetsOn(zLo, zHi);
  const kinds = zoneKinds(z);
  const multi = kinds.length > 1;
  const cz = (control().zones || []).find((x) => x.id === z.id);
  const byKind = (cz && cz.by_kind) || {};
  const leadKind = cz && cz.leader && kinds.includes(cz.leader.kind) ? cz.leader.kind : null;
  let K = kindSel.get(z.id);
  if (!kinds.includes(K)) K = leadKind || kinds[0] || 'disk';
  const ownK = !!(z.curves && z.curves[K]);
  const choose = inputEl('select');
  ['quiet', 'normal', 'cool'].forEach((p) => choose.appendChild(new Option(T(`preset.${p}`), p)));
  choose.appendChild(new Option(T('curve.own'), 'own'));
  choose.value = zoneOwn ? 'own' : tgt.preset || 'normal';
  choose.onchange = () => (z.unit === 'rpm' && choose.value !== 'own' ? snapBack(choose, 'own', T('zones.preset_pct')) : changeZones((all) => {
    if (choose.value === 'own') {
      const base = presets[(all[i].target && all[i].target.preset) || 'normal'];
      all[i].target = { points: base.map((p) => [Math.round(p[0]), Math.round(p[1])]) };
    } else all[i].target = { preset: choose.value };
    curves.delete(z.id);
  }));
  const unit = inputEl('select');
  unit.appendChild(new Option(T('zones.unit_pct'), 'pct'));
  unit.appendChild(new Option(T('zones.unit_rpm'), 'rpm'));
  unit.value = z.unit === 'rpm' ? 'rpm' : 'pct';
  unit.title = rpmOk ? T('zones.unit_tip') : T('zones.unit_off');
  unit.onchange = () => (unit.value === 'rpm' && !rpmOk ? snapBack(unit, 'pct', T('zones.unit_off')) : changeZones((all) => {
    const maxR = Math.min(...fans.map((f) => f.max_rpm || 2000));
    const turn = (pts2) => pts2.map((p) => [Math.round(p[0]), unit.value === 'rpm' ? Math.round(maxR * p[1] / 100 / 10) * 10 : Math.min(100, Math.round(100 * p[1] / maxR))]);
    // every curve of the zone goes into the other unit: the zone's and each kind's own (as points — presets are percent)
    const was = zoneKinded(all[i]);
    const own2 = {};
    Object.keys(all[i].curves || {}).forEach((k) => { own2[k] = { points: turn(kindCurveOf(was, k, scales).points) }; });
    all[i].target = { points: turn(kcZoneCurve(was, scales)) };
    all[i].curves = own2;
    all[i].unit = unit.value;
    curves.delete(z.id);
  }));
  const f2 = (label, input) => { const l = el('label', 'fb-field'); l.append(el('span', 'fb-field-label', label), input); return l; };
  box.appendChild(el('div', 'fb-row-fields'));
  box.lastChild.append(f2(T('zones.target'), choose), f2(T('zones.unit'), unit));
  const A = art();
  if (!A || !kinds.length) { box.appendChild(kinds.length ? waiting() : para(T('zones.curve_no_source'))); return box; }
  const rpmUnit = z.unit === 'rpm';
  // each kind's curve as it counts; with several kinds: which one is in front (a click), which follows the zone, which is its own
  const curveOf = (k) => kindCurveOf(zk, k, scales);
  const front = curveOf(K);
  const pts = front.points;
  const asks = (k) => (byKind[k] && num(byKind[k].c) !== null && num(byKind[k].value) !== null ? byKind[k] : null);
  const valueSay = (v) => (rpmUnit ? T('pic.rpm', { rpm: fmt.number(Math.round(v)) }) : T('value', { pct: Math.round(v) }));
  if (multi) {
    const row = el('div', 'fb-kindrow');
    row.setAttribute('role', 'group');
    row.setAttribute('aria-label', T('curve.kinds'));
    kinds.forEach((k) => {
      const kc = curveOf(k);
      const b = el('button', 'fb-kindpick');
      b.type = 'button';
      b.dataset.kind = k;
      b.setAttribute('aria-pressed', k === K ? 'true' : 'false');
      const now2 = asks(k);
      const sub = el('span', 'fb-kindpick-sub', [T(kc.own ? 'curve.kind_own' : 'curve.kind_zone'), now2 ? T('curve.kind_now', { c: degrees(now2.c), value: valueSay(now2.value) }) : ''].filter(Boolean).join(' · '));
      if (now2) sub.dataset.nums = JSON.stringify([{ v: now2.c, from: ['control', 'zones', `=${z.id}`, 'by_kind', k, 'c'] }, { v: Math.round(now2.value), from: ['control', 'zones', `=${z.id}`, 'by_kind', k, 'value'], via: 'round' }]);
      const head = el('span', 'fb-kindpick-name', T(`kind.${has(`kind.${k}`) ? k : 'other'}`));
      b.append(head, sub);
      if (k === leadKind) b.appendChild(chipOfText('accent', T('curve.leads'), T('curve.leads_tip')));
      b.title = T('curve.kind_tip', { kind: T(`kind.${has(`kind.${k}`) ? k : 'other'}`) });
      b.onclick = () => { if (kindSel.get(z.id) !== k) { kindSel.set(z.id, k); drawn = ''; redraw(); } };
      row.appendChild(b);
    });
    box.appendChild(row);
  }
  const lead = fans[0] || {};
  const look = fanLook(lead);
  const vMax = rpmUnit ? Math.max(...fans.map((f) => f.max_rpm || 0), 500) : 100;
  // one chart, one scale of degrees for all of the zone's curves
  const all = kinds.map((k) => ({ k, pts: curveOf(k).points }));
  const temps = all.flatMap((x) => x.pts.map((p) => p[0]));
  const tMin = Math.max(0, Math.floor((Math.min(...temps) - 10) / 5) * 5), tMax = Math.ceil((Math.max(...temps) + 10) / 5) * 5;
  // the chart shows what the control really applies for the zone's first fan (2026-11-15: a curve along 0 % under a line
  // «never under 40 %» contradicted itself): it stands only where it may and the curve asks for nothing, else it never
  // gets less than its floor — the same rule for a CPU cooler on the owner's risk (round 12 correction: the curve decides).
  const isCpu = lead.role === 'cpu' && !!lead.cpu_own;
  const floorPct = fanFloor(lead);
  // (the CPU cooler on his risk stands by the CPU's curve alone — on another kind's curve it never does)
  const cpuElse = isCpu && K !== 'cpu';
  const stands = !!lead.off_ok && !standWhy(lead) && !cpuElse;
  const neverKind = !isCpu && multi ? kinds.find((k) => curveRule(curveOf(k).points).start === null) : null;
  const floor = rpmUnit ? (lead.table ? tableRpm(lead.table, floorPct) : null) : floorPct;
  const others = all.filter((x) => x.k !== K).map((x) => ({ points: x.pts.map((p) => ({ t: p[0], v: p[1] })) }));
  // live (the control's state): the front kind's «now» line, a dot on every curve where its kind's source stands
  const mine = asks(K);
  const nowC = mine ? mine.c : !multi && cz && cz.warmest ? num(cz.warmest.mapped) ?? num(cz.warmest.c) : null;
  const marks = kinds.map((k) => ({ k, a: asks(k) })).filter((x) => x.a).map((x) => ({ t: x.a.c, v: x.a.value, lead: x.k === leadKind,
    text: multi ? T('curve.mark', { kind: T(`kind.${has(`kind.${x.k}`) ? x.k : 'other'}`), c: degrees(x.a.c) }) : '' }));
  const liveNow = { now: nowC ?? undefined, nowText: nowC !== null ? T('curves.now', { c: degrees(nowC) }) : '', marks, others };
  const shape = JSON.stringify([all.map((x) => [x.k, x.k === K ? 0 : x.pts]), front.own, zoneOwn ? '' : tgt.preset]);
  const key = `${z.id}|${K}|${rpmUnit}|${tMin}|${tMax}|${vMax}|${floor}|${stands}|${shape}`;
  let cv = curves.get(z.id);
  if (!cv || cv.key !== key) {
    if (cv) cv.c.destroy();
    const c = A.curve({
      points: pts.map((p) => ({ t: p[0], v: p[1] })), unit: rpmUnit ? 'rpm' : 'percent', tMin, tMax, vMin: 0, vMax, floor: floor ?? undefined,
      now: liveNow.now, others, marks, fan: { mm: look.mm, blades: look.blades, min: look.min, max: look.max || 2000, colour: look.colour, look: look.look },
      rpmAt: lead.measured ? (v) => (v <= 0 ? 0 : tableRpm(lead.table, v)) : undefined,
      apply: rpmUnit ? undefined : (v) => (stands && v < 0.5 ? 0 : Math.max(v, floorPct)),
      texts: {
        axisT: T('curve.axis_t'), axisV: rpmUnit ? T('curve.axis_rpm') : T('curve.axis_pct'), tickT: (x) => `${x}°`, tickV: (v) => (rpmUnit ? fmt.number(v) : `${v} %`),
        point: (k, n) => T('curve.point', { i: k + 1, n }), value: (x, v) => (rpmUnit ? T('curve.value_rpm', { t: degrees(x), v: fmt.number(v) }) : T('curve.value_pct', { t: degrees(x), v })),
        readout: (x, v, r) => T('curve.readout', { t: x, v: rpmUnit ? T('pic.rpm', { rpm: fmt.number(v) }) : T('value', { pct: Math.round(v) }), rpm: fmt.number(Math.round(r || 0)) }),
        // the live mark with the degrees as they are read (a tenth where the sensor gives one), never rounded away
        floor: rpmUnit ? T('curve.floor') : T('curve.floor_min', { pct: floorPct }), now: liveNow.nowText,
        // exact degrees (2026-11-16, the owner): every point says its values beside it and can be typed below the chart
        label: (x, v) => (rpmUnit ? T('curve.label_rpm', { t: degrees(x), v: fmt.number(v) }) : T('curve.label_pct', { t: degrees(x), v: fmt.number(v, Number.isInteger(v) ? 0 : 1) })),
        field: (k) => T('curve.field', { i: k + 1 }), fieldT: (k) => T('curve.field_t', { i: k + 1 }), fieldV: (k) => T(rpmUnit ? 'curve.field_v_rpm' : 'curve.field_v_pct', { i: k + 1 }),
        unitT: T('curve.unit_c'), unitV: T(rpmUnit ? 'curve.unit_rpm' : 'curve.unit_pct'),
      },
      fields: true,
      onField: (inp) => { inp.classList.add('input', 'small', 'fb-input'); inp.dataset.fbKeep = '1'; },
      // a changed curve is his own: with several kinds this kind's (the others keep following the zone), with one kind
      // the zone's — kept on the page until «Save»
      onChange: (p) => {
        const zs = zonesNow();
        if (!zs[i]) return;
        // (a typed point may move the chart's scale, and the chart is then built anew: the field he went on to is remembered)
        const a = document.activeElement;
        curveFocus = a && a.dataset && a.dataset.axis && c.el.contains(a) ? { zone: z.id, i: a.closest('.fan-curve-field').dataset.i, axis: a.dataset.axis } : null;
        const own2 = p.map((x) => [x.t, x.v]);
        if (multi || ownK) zs[i].curves = { ...(zs[i].curves || {}), [K]: { points: own2 } };
        else zs[i].target = { points: own2 };
        zdraft = zs;
        drawn = '';
        redraw();
      },
    });
    cv = { c, key };
    curves.set(z.id, cv);
    if (curveFocus && curveFocus.zone === z.id) {
      const want = curveFocus;
      setTimeout(() => { const inp = c.el.querySelector(`.fan-curve-field[data-i="${want.i}"] input[data-axis="${want.axis}"]`); if (inp && inp.isConnected) { inp.focus({ preventScroll: true }); inp.select(); } }, 0);
    }
  } else cv.c.live(liveNow);
  curveFocus = null;
  cv.c.el.classList.add('fb-curve-edit');
  box.appendChild(cv.c.el);
  if (multi) {
    // this kind's curve: its own, or the zone's on its degrees — and the one click back
    const state2 = el('div', 'fb-kindstate');
    state2.appendChild(para(T(front.own ? 'curve.kind_own_text' : 'curve.kind_zone_text', { kind: T(`kind.${has(`kind.${K}`) ? K : 'other'}`) })));
    if (ownK) state2.appendChild(btn(T('curve.kind_follow'), 'btn plain', () => changeZones((zs) => { const rest = { ...(zs[i].curves || {}) }; delete rest[K]; zs[i].curves = rest; curves.delete(z.id); }), T('curve.kind_follow_tip')));
    box.appendChild(state2);
  }
  // which kind the fans follow right now — the control's own answer (leader, by_kind), never worked out on the page
  const led = leadKind ? asks(leadKind) : null;
  if (led) {
    const known = (setup().sources || []).find((x) => x.id === led.source);
    const follows = para(T('curve.follows', { kind: T(`kind.${has(`kind.${leadKind}`) ? leadKind : 'other'}`), source: known ? srcLabel(known) : led.label || '', c: degrees(led.c), value: valueSay(led.value) }), 'fb-curve-follows');
    follows.dataset.nums = JSON.stringify([{ v: led.c, from: ['control', 'zones', `=${z.id}`, 'by_kind', leadKind, 'c'] }, { v: Math.round(led.value), from: ['control', 'zones', `=${z.id}`, 'by_kind', leadKind, 'value'], via: 'round' }]);
    box.appendChild(follows);
  }
  if (!rpmUnit) {
    const says = para(curveWords(pts, floorPct, stands, isCpu, { multi: multi && !isCpu, cpuElse: cpuElse && !!lead.off_ok && !standWhy(lead),
      neverKind: neverKind ? T(`kind.${has(`kind.${neverKind}`) ? neverKind : 'other'}`) : '' }), 'fb-curve-says');
    says.dataset.nums = JSON.stringify([{ v: floorPct, from: ['setup', 'fans', `=${lead.id}`], via: 'floor' }]);
    box.appendChild(says);
  }
  box.appendChild(para(T('zones.curve_sub')));
  return box;
}

/**
 * «Das würde ich jetzt setzen» — what the dry run shows (the agent's dry_run: the control's own round, nothing written),
 * compact, inside «take over»: per zone the warmest source, per fan what he would set beside what the output has now.
 * Asked for by the step itself, once per zones as saved; «Neu rechnen» asks again.
 */
let dryFor = '';                // the zones the preview was asked for (their signature)
let dryAsking = false;
function dryAsk(force) {
  const sig = JSON.stringify([setup().zones, (setup().fans || []).map((f) => [f.id, f.off_ok, f.role])]);
  if (dryAsking || !Office.agent.running || (!force && dryFor === sig)) return;
  dryFor = sig;
  dryAsking = true;
  Office.api.post(`${ID}.dry_run`, {}).then((j) => {
    dryAsking = false;
    dry = j.ok ? j.dry || null : null;
    if (!j.ok) say(T('strip.refused', { why: Office.errorText(j.error, ID) }));
    drawn = '';
    if (view) redraw();
  });
}
function dryPreview() {
  const box = el('div', 'fb-preview');
  const head = el('div', 'fb-preview-head');
  head.append(el('h4', 'fb-q', T('dry.title')), btn(T('dry.again'), 'btn plain', () => dryAsk(true), T('dry.run_tip')));
  box.appendChild(head);
  if (!dry) {
    const p = el('p', 'fb-wait running');
    p.append(wave(), el('span', '', T('dry.asking')));
    box.appendChild(p);
    return box;
  }
  if (!dry.found) box.appendChild(para(T('dry.not_found', { why: dry.why || '' }), 'callout warn'));
  const tb = el('table', 'fb-table fb-preview-table');
  const h = el('tr');
  h.append(el('th', '', T('dry.zone')), el('th', '', T('dry.fan')), el('th', '', T('dry.would')), el('th', '', T('dry.now')));
  tb.appendChild(h);
  (dry.zones || []).forEach((z) => {
    const w = z.warmest && num(z.warmest.c) !== null ? T('dry.warmest', { where: srcLabel(z.warmest), c: fmt.number(z.warmest.c) }) : T('dry.nothing');
    (z.fans || []).forEach((f, k) => {
      const tr = el('tr');
      const nowT = f.now ? (f.now.enable === 1 ? T('dry.now_fixed', { pct: Math.round((f.now.value || 0) / 2.55) }) : f.now.enable === 0 ? T('mode.full') : T('dry.now_auto')) : '–';
      const zc = el('td', '');
      if (!k) zc.append(el('span', 'fb-strong', z.name), el('span', 'fb-preview-sub', `${w}${z.asleep ? ' · ' + T('dry.asleep', { n: z.asleep }) : ''}`));
      tr.append(zc, el('td', '', fanSay(f.id)), el('td', 'fb-strong', num(f.pct) === null ? '–' : T('dry.would_pct', { pct: f.pct })), el('td', '', nowT));
      tb.appendChild(tr);
    });
  });
  const wrap = el('div', 'fb-table-wrap');
  wrap.appendChild(tb);
  box.append(wrap, para(T('dry.nothing_written')));
  return box;
}

// 5 — take over / hand back: what he gets, what stays with the BIOS, what he would set right now — then the one button
function takeStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const c = control();
  const on = steers() || su.armed;
  // who gets what, as rows to read: his zones with their fans; the BIOS's own; the CPU cooler on the owner's risk with its way back
  const list = el('div', 'fb-list fb-list-read');
  (su.zones || []).forEach((z) => {
    const tgt = z.target || {};
    list.appendChild(readRow(null, z.name || z.id, (z.fans || []).map((id) => fanSay(id)).join(', ') || T('zones.no_fan'),
      chipOfText('accent', T('take.his'), T(on ? 'take.his_tip_on' : 'take.his_tip')), chipOfText('quiet', tgt.points ? T('curve.own') : T(`preset.${has(`preset.${tgt.preset}`) ? tgt.preset : 'normal'}`), T('zones.curve'))));
  });
  const bios = (su.fans || []).filter((f) => ['tach', 'silent'].includes(f.found) && !(su.zones || []).some((z) => (z.fans || []).includes(f.id)));
  if (bios.length) list.appendChild(readRow(null, T('take.bios_row'), bios.map((f) => fanSay(f.id)).join(', '), chipOfText('quiet', T('pic.bios'), T('take.bios_tip'))));
  (su.fans || []).filter((f) => f.role === 'cpu' && f.cpu_own).forEach((f) => {
    const back = btn(T('cpu.back'), 'btn', () => send('role', { fan: f.id, role: 'cpu', name: f.name || '', place: f.place || '', cpu_own: false }), T('cpu.back_tip'));
    list.appendChild(readRow(null, fanName(f.id), underName(f.id, T('role.cpu'), T('take.own_since', { date: Office.fmt.date(f.cpu_own) })), null, back));
  });
  if (list.children.length) box.appendChild(list);
  if (on) {
    // (steering: the banner above says so; armed but not steering — the array stopped, the chip missing — said here)
    if (!steers()) box.appendChild(para(myWhy(who().why) || T('take.armed'), 'callout warn'));
    box.appendChild(nextBar([para(T('take.hand_back_text'))], button(T('take.hand_back'), T('take.hand_back_tip'), (b) => handBack(b), 'btn')));
    return box;
  }
  const why = [];
  if (situation() === 'other') why.push(T('step.blocked_other'));
  if (!(su.zones || []).length) why.push(T('take.no_zones'));
  if (stepRuns()) why.push(T('take.step_runs'));
  why.forEach((w) => box.appendChild(para(w, 'callout warn')));
  // round 7 (F3): his fans whose «before» is a fixed value someone left — said once, plainly, with the restart first
  const mine = new Set((su.zones || []).flatMap((z) => z.fans || []));
  const left = [...mine].map((id) => ({ id, b: (su.before || {})[id], f: setupFan(id) })).filter((x) => x.b && (x.b.fixed || x.b.mode === 'full'));
  if (left.length) {
    const toFixed = left.filter((x) => ((x.f && x.f.back) || su.back_default) !== 'auto' || x.b.auto === null || x.b.mode === 'full');
    const names = (toFixed.length ? toFixed : left).map((x) => ({ id: x.id, pct: x.b.mode === 'full' ? 100 : x.b.pct }));
    box.appendChild(para(T(toFixed.length ? 'take.fixed_before' : 'take.fixed_before_auto', { list: fixedList(names) }), 'callout warn'));
  }
  // round 12 fix: heat sources in no zone — said here too; where a fan of his may stand, taking over needs his plain yes
  // (the agent refuses without it: fanboy_uncovered)
  const loose = (su.zones || []).length ? uncoveredNow() : [];
  const standing = (su.fans || []).filter((f) => f.may_stand === true && mine.has(f.id));
  if (loose.length) box.appendChild(uncoveredBox(loose, goButton('zones', false)));
  const b = button(T('take.go'), T('take.go_tip'), (b2) => {
    const sure = loose.length && standing.length;
    Office.dialog({
      title: T('take.confirm_title'),
      body: sure ? T('take.uncovered_confirm', { sources: loose.map((x) => srcLabel(x)).join(', '), fans: standing.map((f) => fanSay(f.id)).join(', ') }) : T('take.confirm', { n: (su.zones || []).length }),
      buttons: [{ text: t('common.cancel') }, { text: T(sure ? 'take.uncovered_go' : 'take.go'), kind: '', act: async () => { await act('take_over', sure ? { uncovered_ok: true } : {}, b2, () => Office.toast(T('take.done_toast'))); return true; } }],
    });
  }, 'btn');
  if (why.length) offBtn(b, why[0]);
  // what he would set right now, before the button (the dry run's answer; it reads only)
  if ((su.zones || []).length && situation() !== 'other') { dryAsk(false); box.appendChild(dryPreview()); }
  const lines = [para(T(cpuGiven() ? 'take.text_cpu' : 'take.text'))];
  if (c.why === 'hand_back' || c.why === 'stopped') lines.push(para(T('take.given_back')));
  box.appendChild(nextBar(lines, b));
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
      [T('help.later'), T('help.later_text')], [T('help.vm_disks'), T('help.vm_disks_text')]]);
    const ssw = setupSwitcher();
    view.replaceChildren(head, help, ssw.el);
    if (banner.parentNode !== ssw.el || banner.nextSibling !== ssw.body) ssw.el.insertBefore(banner, ssw.body);
    paintBanner();
    if (state) {
      if (!strip) strip = stripMake();
      ssw.el.insertBefore(strip.el, ssw.body);
      strip.update();
      ssw.render();
    }
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
  if (banner.parentNode !== sw.el) sw.el.insertBefore(banner, sw.body);
  paintBanner();
  // what runs now (a show, a test left running, an output at 100 % for safety) — said above the sections, with «Stop everything»
  if (state && stripNeeded()) {
    if (!strip) strip = stripMake();
    sw.el.insertBefore(strip.el, sw.body);
    strip.update();
  } else if (strip && strip.el.parentNode) strip.el.remove();
  sw.render();
}

Office.desk({
  id: ID,
  async mount(root, sub) {
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : '';
    drawn = '';
    warmFrom = Date.now();
    // still waiting for something after WARM_MS: draw without it (ready() is in the signature — no redraw when it was there)
    setTimeout(() => { if (view === root) redraw(); }, WARM_MS + 100);
    if (page === 'setup') {
      // #/fanboy/setup/<step> picks that step like a click; else the step at hand once his state is in (never kept)
      let rest = String(sub || '').split('/').slice(1).join('/');
      if (rest === 'roles') rest = 'test';         // round 8: the roles step lives in «Testen und zuordnen» now
      if (rest === 'dry') rest = 'take';           // 2026-11-16: the dry run is a preview inside «take over»
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
    [bench, mbench, strip].forEach((b) => { if (b) b.destroy(); });
    bench = mbench = strip = null;
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
