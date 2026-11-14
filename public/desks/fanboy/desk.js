/* Fanboy — the office's building services and his fans' biggest fan (docs/DEVELOPMENT.md «The desks»).
   Part 3, the page a person sets him up and lives with — two pages, each a row of tiles (core.js Office.sections()):
   · the overview: the server as a picture (fan-art.js FanArt.server(): the zones as pieces of a case, the fans turning
     with their air, the heat sources between them, the warmest framed, sleeping disks marked — fed by every look, the
     speeds gliding), the fans and outputs, the heat sources, the curves with «now» and what happened lately, the hints
     (why it's tricky, drivers, FanCtrl Plus, the report for the makers);
   · the setup (the wizard), each step a tile in the order of part 2's steps: detect → test each output upward → roles
     (the catalogue picker, name, place, «give back to») → measure → zones (the proposal or FanCtrl Plus's, the curve to
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
const HISTORY_HOURS = 6;        // the curve view's window
const PAGES = () => [{ id: '', text: t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];
const t = Office.t;
const ROLES_OURS = ['case', 'disks', 'ram', 'nvme', 'other'];
const ROLES_BIOS = ['cpu', 'pump', 'unknown'];
const PLACES = ['front', 'rear', 'top', 'bottom', 'side'];
const COLOURS = ['auto', 'black', 'graphite', 'grey', 'white', 'slate', 'accent'];
const STEPS = ['detect', 'test', 'roles', 'measure', 'zones', 'dry', 'take'];

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
const setup = () => S().setup || { fans: [], zones: [], outputs: [], sources: [], curves: {}, before: {}, back_open: [] };
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

/** An output's id parts: chip, address, output number */
function idParts(id) {
  const m = /^(.*):([^:]+):pwm(\d{1,2})$/.exec(String(id || ''));
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
  return { mm: (c && c.mm) || m.mm || 120, blades: (c && c.blades) || m.blades || 7, min, max, colour: (f && f.colour) || 'auto' };
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
  if (s.kind === 'disk') {
    const m = /^disk(\d+)$/.exec(s.id);
    if (m) return T('src.disk', { n: m[1] });
    const p = /^parity(\d*)$/.exec(s.id);
    if (p) return p[1] ? T('src.parity_n', { n: p[1] }) : T('src.parity');
    return s.id;
  }
  return s.label || T(`kind.${s.kind}`);
}
const srcLimit = (s) => {
  const d = (setup().scales || {})[s.kind] || (setup().scales || {}).other;
  return num(s.limit) ?? (d ? d.limit : null);
};

/** The step at hand: the first not done (detect, test, roles, measure, zones, dry, take) */
function stepAtHand() {
  const su = setup();
  if (!(su.outputs || []).length) return 'detect';
  const s = step();
  if (running(s)) return s.kind === 'measure' ? 'measure' : 'test';
  const fans = su.fans || [];
  const tested = (id) => { const f = fans.find((x) => x.id === id); return f && ['tach', 'silent', 'empty'].includes(f.found); };
  if (!(su.outputs || []).every(tested)) return 'test';
  const found = fans.filter((f) => ['tach', 'silent', 'unclear'].includes(f.found));
  if (found.some((f) => !f.role) || (su.back_open || []).length) return 'roles';
  const toMeasure = found.filter((f) => ROLES_OURS.includes(f.role) && f.found === 'tach');
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
  if (view) redraw();
  plan();
}
/** The next look, and the step's lease while one runs */
function plan() {
  clearTimeout(timer);
  timer = null;
  if (!view) { stopAlive(); return; }
  timer = setTimeout(() => load(false), stepRuns() ? LOOK_STEP_MS : LOOK_MS);
  if (stepRuns()) startAlive(); else stopAlive();
}
function startAlive() {
  if (alive) return;
  const beat = async () => {
    if (!view || !stepRuns() || !Office.agent.running) return;
    const j = await Office.api.post(`${ID}.step_alive`, {});
    if (j.ok && state) { state.step = j.step || null; if (view) redraw(); }
  };
  beat();
  alive = setInterval(beat, ALIVE_MS);
}
function stopAlive() {
  clearInterval(alive);
  alive = null;
}
function loadHistory() {
  if (!Office.agent.running && history) return;
  Office.loadState(ID, { part: 'history' }, (j) => {
    if (j.ok && j.part) history = j.part;
    if (view && page === '' && sw.active() !== 'hints') redraw();
  });
}

/** The whole page again — the setup page only when what it shows changed (a field being typed in stays) */
function redraw() {
  if (!view) return;
  if (page === 'setup') {
    const sig = setupSig();
    if (sig === drawn) return;
    drawn = sig;
  }
  Office.keepInPlace(null, render);
}
function setupSig() {
  const su = setup();
  return JSON.stringify([!!art(), !!cat, Office.agent.running, busy, who(), situation(), control().running, control().alarm, su.fans, su.zones, su.armed,
    su.outputs, su.back_open, su.curves, step(), dry, zdraft, S().import ? S().import.files : 0, setupSw ? setupSw.active() : '']);
}

// ------------------------------------------------------------------ the bubble
function bubbleText() {
  if (!state) return T('bubble.loading');
  const w = who();
  const c = control();
  if (w.who === 'fanboy') return w.why === 'alarm' ? T('bubble.alarm') : T('bubble.steers', { n: w.zones || 0 });
  if (stepRuns()) return step().kind === 'measure' ? T('bubble.measuring') : T('bubble.testing');
  if (w.why === 'chip_missing') return T('bubble.chip_missing');
  if (w.why === 'array_stopped') return T('bubble.array_stopped');
  const s = situation();
  if (s === 'other') return T('bubble.other', { who: otherNames() });
  if (s === 'driver_missing' || s === 'no_output' || s === 'ipmi') return T(`bubble.${s}`);
  if (s === 'ok' && c.armed === false && (setup().zones || []).length) return T('bubble.ready');
  if (s === 'ok') return T('bubble.ok', { n: counts().outputs || 0 });
  return T('bubble.loading');
}
const otherNames = () => ((S().situation || {}).names || []).join(', ') || (who().name || '');
const plainNow = () => ['alarm', 'chip_missing', 'crashed', 'array_stopped'].includes(who().why) || (control().running && control().alarm);

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
function unfoldRow(key, place, name, meta, value, detail) {
  const r = Office.place(place, el('div', 'row unfolds fb-row' + (opened.has(key) ? ' open' : '')));
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
    id, label: fanName(id), sub, mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: look.colour,
    rpm: st === 'unknown' ? null : shown, state: silent && held ? 'run' : st, place: f.place || (opts && opts.place) || 'front',
    badge: st === 'bios' ? T('pic.bios') : '', title: st === 'bios' ? (ROLES_BIOS.includes(f.role) ? T('pic.watched_title') : T('pic.bios_title')) : T('pic.his_title'),
  };
}
/** A zone's line under its name: its curve, the warmest spot and what it asks for */
function zoneNote(z, cz) {
  const parts = [];
  const tgt = z.target || {};
  parts.push(tgt.points ? T('curve.own') : T(`preset.${has(`preset.${tgt.preset}`) ? tgt.preset : 'normal'}`));
  if (cz && control().running && steers()) {
    if (cz.warmest && num(cz.warmest.c) !== null) {
      const lvl = num(cz.level) === null ? null : cz.unit === 'rpm' ? T('pic.rpm', { rpm: fmt.number(Math.round(cz.level)) }) : T('value', { pct: Math.round(cz.level) });
      parts.push(lvl ? T('pic.asks', { where: srcLabel({ ...cz.warmest, id: cz.warmest.id }), c: fmt.number(cz.warmest.c), level: lvl }) : T('pic.warmest', { where: srcLabel(cz.warmest), c: fmt.number(cz.warmest.c) }));
    } else parts.push(T('pic.quiet'));
  } else parts.push(T('pic.bios_now'));
  return parts.join(' · ');
}
/** The server as the picture shows it: his zones (or, before the setup, what the board shows), the BIOS's fans watched */
function picture() {
  const su = setup();
  const src = new Map((su.sources || []).map((s) => [s.id, s]));
  const asSource = (s) => ({ id: s.id, label: srcLabel(s), kind: s.kind === 'disk' ? 'disk' : s.kind, temp: num(s.c), asleep: !!s.asleep, limit: srcLimit(s),
    title: s.asleep ? T('pic.asleep_title') : '' });
  const zones = [];
  const zonesNow = new Map((control().zones || []).map((z) => [z.id, z]));
  (su.zones || []).forEach((z) => {
    const cz = zonesNow.get(z.id);
    zones.push({
      id: z.id, name: z.name || z.id, note: zoneNote(z, cz),
      fans: (z.fans || []).map((id) => wheelOf(id)),
      sources: (z.sources || []).map((s) => src.get(s.id)).filter(Boolean).map((s) => asSource({ ...s, limit: s.limit ?? (z.sources.find((x) => x.id === s.id) || {}).limit })),
      warmest: cz && cz.warmest ? cz.warmest.id : null,
    });
  });
  // the BIOS's own: the CPU cooler and pumps he only watches (and, before any zone, every fan the board reports)
  const watched = (su.fans || []).filter((f) => ['cpu', 'pump'].includes(f.role) && f.found === 'tach');
  if (zones.length) {
    if (watched.length) {
      zones.push({ id: '_bios', name: T('pic.bios_zone'), note: T('pic.bios_zone_note'), fans: watched.map((f) => wheelOf(f.id, { place: 'rear' })),
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
      min: look.min, max: look.max, colour: look.colour, rpm: f.rpm, state: 'bios', badge: T('pic.bios'), title: T('pic.bios_title'),
      place: (out && out.place) || (fans.length % 2 ? 'rear' : 'front') });
  }));
  const kinds = ['cpu', 'nvme', 'disk', 'ram', 'board'];
  const sources = (su.sources || []).filter((s) => kinds.includes(s.kind))
    .sort((a, b) => kinds.indexOf(a.kind) - kinds.indexOf(b.kind)).filter((s, i, all) => s.kind === 'disk' || all.findIndex((x) => x.kind === s.kind && x.label === s.label) === i)
    .slice(0, 14).map(asSource);
  return { zones: [{ id: '_all', name: T('pic.all'), note: steers() ? '' : T('pic.all_note'), fans, sources }] };
}
function pictureEl() {
  const A = art();
  if (!A) return waiting();
  const p = picture();
  const texts = { temp: (c) => T('temp', { c: fmt.number(c) }), asleep: T('pic.asleep'), warmest: T('pic.warmest_mark'), none: T('pic.none') };
  if (!pic) pic = A.server(p, { texts });
  else pic.update(p);
  pic.el.classList.add('fb-server');
  return pic.el;
}

// ------------------------------------------------------------------ the overview's tiles
function whoTile(a) {
  const w = who();
  const value = !state ? '…' : w.who === 'fanboy' ? T('who.fanboy', { n: w.zones || 0 }) : w.who === 'bios' ? T('who.bios') : w.who === 'bmc' ? T('who.bmc') : (w.name || w.who || '…');
  const alert = ['alarm', 'crashed', 'chip_missing'].includes(w.why);
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
  return Office.stat({ label: T('tile.fans'), value: !state ? '…' : all ? T('fans.turning', { n: turning }) : T('fans.none'), sub, alert: !!stands, ...a });
}
function hottestTile(a) {
  const h = S().hottest;
  const where = h ? (h.disk ? srcLabel({ kind: 'disk', id: h.disk }) : [T(`kind.${h.kind}`), h.label].filter(Boolean).join(' · ')) : '';
  return Office.stat({ label: T('tile.hottest'), value: !state ? '…' : h ? T('temp', { c: fmt.number(h.c) }) : T('hottest.none'), sub: where, ...a });
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
  const start = new Date();
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
/** A zone's quiet level: its curve's lowest value */
function quietLevel(zid) {
  const c = (setup().curves || {})[zid];
  return c && Array.isArray(c.points) && c.points.length ? Math.min(...c.points.map((p) => p[1])) : null;
}
function durationText(s) {
  const m = Math.round(s / 60);
  if (m < 60) return T('dur.min', { n: m });
  return T('dur.h_min', { h: Math.floor(m / 60), m: m % 60 });
}

const sw = Office.sections({
  id: ID,
  grid: 'stats',
  default: '',
  tiles: () => [
    { id: 'who', key: 'tile.who', place: 'tile.who', tile: whoTile, section: () => serverSection() },
    { id: 'fans', key: 'tile.fans', place: 'tile.fans', tile: fansTile, section: () => fansSection() },
    { id: 'hottest', key: 'tile.hottest', place: 'tile.hottest', tile: hottestTile, section: () => sensorsSection() },
    { id: 'today', key: 'tile.today', place: 'tile.today', tile: todayTile, section: () => curvesSection() },
    { id: 'hints', key: 'tile.hints', place: 'tile.hints', tile: hintsTile, section: () => hintsSection() },
  ],
});

// ------------------------------------------------------------------ the overview's sections
function serverSection() {
  const s = el('section', 'section');
  const go = el('a', 'btn small', steers() || (setup().zones || []).length ? T('server.change') : T('server.set_up'));
  go.href = `#/${ID}/setup`;
  s.appendChild(Office.sectionHead(T('server.title'), steers() ? T('server.sub_steers') : T('server.sub'), go, { place: 'server.title' }));
  s.appendChild(pictureEl());
  const notes = notesBox();
  if (notes) s.append(Office.place('notes.title', el('h3', 'fb-head', T('notes.title'))), notes);
  return s;
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

/** What an output is to him: his (zone), the BIOS's watched, empty, not tested — a label */
function outputRole(id) {
  const f = setupFan(id);
  const cf = controlFan(id);
  if (cf && cf.foreign) return chipOfText('warn', T('out.foreign'), T('out.foreign_title'));
  if (cf && cf.held && control().running) return chipOfText('accent', T('out.held', { pct: num(cf.pct) === null ? '–' : cf.pct }), T('out.held_title', { zone: zoneName(cf.zone || zoneOfFan(id)) }));
  if (!f || !f.found) return chipOfText('quiet', T('out.untested'), T('out.untested_title'));
  if (f.found === 'empty') return chipOfText('quiet', T('out.empty'), T('out.empty_title'));
  if (f.role && ROLES_BIOS.includes(f.role)) return chipOfText('quiet', T(`role.${f.role}`) + ' · ' + T('out.watched'), T('out.watched_title'));
  if (f.role) return chipOfText('quiet', T(`role.${f.role}`), T('out.his_title'));
  return chipOfText('quiet', T('out.no_role'), T('out.no_role_title'));
}
const zoneOfFan = (id) => ((setup().zones || []).find((z) => (z.fans || []).includes(id)) || {}).id || '';
const zoneName = (zid) => ((setup().zones || []).find((z) => z.id === zid) || {}).name || zid;

function fansSection() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('fans.title'), steers() ? T('fans.sub_steers') : T('fans.sub'), { place: 'fans.title' }));
  const list = el('div', 'box');
  chips().filter((c) => c.outputs.length).forEach((c) => c.outputs.forEach((o) => {
    const id = `${c.name}:${c.address}:pwm${o.n}`;
    const f = setupFan(id);
    const meta = el('div', 'row-meta');
    if (!offered(c)) meta.appendChild(el('span', 'note', T('outputs.gpu')));
    else meta.appendChild(outputRole(id));
    const mode = o.mode || 'unknown';
    meta.appendChild(chipOfText(mode === 'auto' ? 'ok' : mode === 'unknown' ? 'quiet' : 'accent', T(`mode.${mode}`), T(`mode.${mode}_title`)));
    const rpm = f ? rpmNow(id, f.tachs) : null;
    if (rpm !== null) meta.appendChild(el('span', '', T('pic.rpm', { rpm: fmt.number(rpm) })));
    const v = el('div', 'fb-value', num(o.pct) === null ? '–' : T('value', { pct: o.pct }));
    v.title = T('value_title', { value: o.value ?? '–' });
    const b = (setup().before || {})[id];
    list.appendChild(unfoldRow(`out:${id}`, `out:${c.address}:${o.n}`, f && f.name ? f.name : T('out.name', { n: o.n }), meta, v, [
      [T('tech.chip'), `${c.name} · ${c.address}${c.module ? ' · ' + c.module : ''}`],
      [T('tech.output'), `pwm${o.n} · ${c.hwmon}`],
      [T('tech.mode'), `pwm${o.n}_enable = ${o.enable ?? '–'} · ${o.value ?? '–'} / 255`],
      [T('tech.tachs'), f && (f.tachs || []).length ? f.tachs.map((n) => `fan${n}_input`).join(', ') : '–'],
      [T('tech.before'), b ? `${b.enable ?? '–'} · ${b.value ?? '–'} / 255` : '–'],
    ]));
  }));
  // tach inputs no output of his has: what the board reports (the CPU cooler on its own header, an empty one)
  const known = new Set();
  (setup().fans || []).forEach((f) => (f.tachs || []).forEach((n) => known.add(`${f.chip}#${n}`)));
  chips().forEach((c) => (c.fans || []).forEach((f) => {
    if (known.has(`${c.name}#${f.n}`)) return;
    const meta = el('div', 'row-meta');
    meta.appendChild(chipOfText('quiet', T('fans.input'), T('fans.input_title')));
    if (f.rpm === null || f.rpm === undefined) meta.appendChild(chipOfText('quiet', T('fans.no_value'), T('fans.no_value_title')));
    list.appendChild(unfoldRow(`tach:${c.address}:${f.n}`, `fan:${c.address}:${f.n}`, f.label || T('fans.row', { n: f.n }), meta,
      el('div', 'fb-value', f.rpm === null || f.rpm === undefined ? '–' : T('pic.rpm', { rpm: fmt.number(f.rpm) })),
      [[T('tech.chip'), `${c.name} · ${c.address}`], [T('tech.input'), `fan${f.n}_input · ${c.hwmon}`]]));
  }));
  if (!list.children.length) list.appendChild(el('p', 'empty', T('outputs.none')));
  s.appendChild(list);
  return s;
}

/** Every temperature: the chips' and Unraid's disks' (a sleeping disk: none, said) */
function sensorRows() {
  const out = [];
  chips().forEach((c) => c.temps.forEach((x) => out.push({ name: x.label || T('sensors.row', { n: x.n }), chip: c, kind: c.kind, c: x.c, key: `${c.address}:${x.n}`, feature: `temp${x.n}` })));
  (S().disks || []).forEach((d) => out.push({ name: srcLabel({ kind: 'disk', id: d.name }), chip: null, kind: 'disk', c: d.temp, asleep: d.asleep, key: `disk:${d.name}`, disk: d.name }));
  return out;
}
const nameOf = (c, feature) => (S().names || []).find((x) => x.address === c.address && x.chip === c.name && x.feature === feature) || null;
function sensorsBar() {
  sensorBar = sensorBar || Office.filterBar({ key: 'fanboy.sensors', label: T('sensors.filter_label'), onChange: () => sw.render() });
  return sensorBar;
}
function sensorsSection() {
  const s = el('section', 'section');
  const bar = sensorsBar();
  s.append(Office.sectionHead(T('sensors.title'), T('sensors.sub'), { place: 'sensors.title' }), bar.el);
  const f = bar.read();
  const all = sensorRows();
  const fits = all.filter((x) => Office.fits(f.words, [x.name, T(`kind.${x.kind}`), x.chip ? ((nameOf(x.chip, x.feature) || {}).label || '') : '']));
  const list = el('div', 'box');
  fits.forEach((x) => {
    const meta = el('div', 'row-meta');
    meta.appendChild(chipOfText('quiet', T(`kind.${x.kind}`), T('kind_title')));
    const n = x.chip ? nameOf(x.chip, x.feature) : null;
    if (n && n.label) meta.appendChild(chipOfText('quiet', `«${n.label}»`, T('hints.names')));
    if (n && n.ignore) meta.appendChild(chipOfText('quiet', T('hints.names_hidden'), T('hints.names')));
    if (x.asleep) meta.appendChild(chipOfText('quiet', T('sensors.asleep'), T('sensors.asleep_title')));
    else if (x.c === null || x.c === undefined) meta.appendChild(chipOfText('quiet', T('sensors.no_value'), T('sensors.no_value_title')));
    const zs = (setup().zones || []).filter((z) => (z.sources || []).some((y) => y.id === (x.disk || (x.chip ? `${x.chip.name}@` : '\u0000')) || (x.chip && y.id.startsWith(`${x.chip.name}@`) && y.id.endsWith(`#${x.feature.slice(4)}`))));
    zs.forEach((z) => meta.appendChild(chipOfText('accent', z.name, T('sensors.in_zone'))));
    const v = el('div', 'fb-value', x.c === null || x.c === undefined ? '–' : T('temp', { c: fmt.number(x.c) }));
    list.appendChild(unfoldRow(`temp:${x.key}`, `temp:${x.key}`, x.name, meta, v,
      x.chip ? [[T('tech.chip'), `${x.chip.name} · ${x.chip.address}`], [T('tech.sensor'), `${x.feature}_input · ${x.chip.hwmon}`]]
        : [[T('tech.disk'), x.disk], [T('tech.from'), 'disks.ini']]));
  });
  if (!list.children.length) list.appendChild(el('p', 'empty', all.length ? T('sensors.none_fit') : T('sensors.none')));
  s.appendChild(list);
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
/** The zone's last hours: the warmest temperature and the level, a point a minute */
function historyChart(zid) {
  const pts = ((history && history.zones && history.zones[zid]) || []).filter((p) => Array.isArray(p) && p.length === 4);
  const now = Date.now() / 1000;
  const from = now - HISTORY_HOURS * 3600;
  const shown = pts.filter((p) => p[0] >= from);
  if (shown.length < 2) return el('p', 'fb-hint-text', T('curves.history_none'));
  const W = 320, H = 150, L = 34, R = 34, Tp = 8, B = 22;
  const temps = shown.map((p) => p[1]).filter((v) => num(v) !== null);
  const tLo = Math.floor((Math.min(...temps, 30) - 2) / 5) * 5, tHi = Math.ceil((Math.max(...temps, 40) + 2) / 5) * 5;
  const x = (ts) => L + (ts - from) / (now - from) * (W - L - R);
  const yT = (c) => Tp + (1 - (c - tLo) / (tHi - tLo)) * (H - Tp - B);
  const yL = (v) => Tp + (1 - v / 100) * (H - Tp - B);
  const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'fb-chart', role: 'img', 'aria-label': T('curves.history_label', { h: HISTORY_HOURS }) });
  for (let c = tLo; c <= tHi; c += Math.max(5, Math.round((tHi - tLo) / 4 / 5) * 5)) {
    s.appendChild(svg('line', { x1: L, x2: W - R, y1: yT(c), y2: yT(c), class: 'fb-grid' }));
    s.appendChild(svg('text', { x: L - 4, y: yT(c) + 3, class: 'fb-tick fb-t', 'text-anchor': 'end' }, `${c}°`));
  }
  [0, 50, 100].forEach((v) => s.appendChild(svg('text', { x: W - R + 4, y: yL(v) + 3, class: 'fb-tick fb-l' }, `${v} %`)));
  for (let h = HISTORY_HOURS; h >= 0; h -= 2) s.appendChild(svg('text', { x: x(now - h * 3600), y: H - 6, class: 'fb-tick', 'text-anchor': 'middle' }, h ? `−${h} h` : T('curves.now_short')));
  const path = (ys) => {
    let d = '', pen = false, last = null;
    shown.forEach((p) => {
      const v = ys(p);
      if (v === null || (last !== null && p[0] - last > 180)) { pen = false; }
      if (v !== null) { d += `${pen ? 'L' : 'M'}${x(p[0]).toFixed(1)} ${v.toFixed(1)}`; pen = true; }
      last = p[0];
    });
    return d;
  };
  s.appendChild(svg('path', { d: path((p) => (num(p[2]) === null ? null : yL(p[2]))), class: 'fb-level-line' }));
  s.appendChild(svg('path', { d: path((p) => (num(p[1]) === null ? null : yT(p[1]))), class: 'fb-temp-line' }));
  return s;
}
function curvesSection() {
  const s = el('section', 'section');
  const go = el('a', 'btn small', T('curves.change'));
  go.href = `#/${ID}/setup/zones`;
  s.appendChild(Office.sectionHead(T('curves.title'), T('curves.sub', { h: HISTORY_HOURS }), (setup().zones || []).length ? go : null, { place: 'curves.title' }));
  const zones = setup().zones || [];
  if (!zones.length) {
    s.appendChild(el('p', 'empty', T('curves.none')));
    return s;
  }
  const grid = el('div', 'fb-curves');
  const now = new Map((control().zones || []).map((z) => [z.id, z]));
  zones.forEach((z) => {
    const cz = now.get(z.id);
    const card = Office.place(`curve:${z.id}`, el('div', 'fb-curve-card'));
    card.appendChild(el('h3', 'fb-head', z.name || z.id));
    const warm = cz && cz.warmest && num(cz.warmest.c) !== null ? cz.warmest : null;
    card.appendChild(el('p', 'fb-hint-text', warm && control().running
      ? T('curves.line_now', { where: srcLabel(warm), c: fmt.number(warm.c), level: num(cz.level) === null ? '–' : (cz.unit === 'rpm' ? T('pic.rpm', { rpm: fmt.number(Math.round(cz.level)) }) : T('value', { pct: Math.round(cz.level) })) })
      : T('curves.line_bios')));
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

// ------------------------------------------------------------------ hints, drivers, FanCtrl Plus, the report
function toolChip(x) {
  if (x.kind === 'controller' && x.id === 'md12xx.fancontrol') return x.active ? chipOfText('quiet', T('hints.st_other_fans'), T('hints.st_other_fans_title')) : chipOfText('quiet', T('hints.st_idle'), T('hints.st_idle_title'));
  if (x.kind === 'controller') return x.active ? chipOfText('warn', T('hints.st_steering'), T('hints.st_steering_title')) : chipOfText('quiet', T('hints.st_idle'), T('hints.st_idle_title'));
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
  const imp = importBlock();
  if (imp) body.append(Office.place('import.title', hintHead(T('import.title'))), imp);
  body.appendChild(hintHead(T('hints.why_title')));
  const why = el('ol', 'fb-hint-list');
  for (let i = 1; has(`hints.why.${i}`); i++) why.appendChild(el('li', '', T(`hints.why.${i}`)));
  body.appendChild(why);
  body.appendChild(hintHead(T('hints.bios_title')));
  hintParas(body, 'hints.bios');
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
function importBlock() {
  const imp = S().import;
  if (!imp || !imp.files) return null;
  const box = el('div', 'fb-import');
  box.appendChild(el('p', 'fb-hint-text', T('import.text', { n: imp.files })));
  const list = el('div', 'box');
  (imp.zones || []).forEach((z) => {
    const r = Office.place(`import:${z.id}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', z.name));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('import.outputs', { list: (z.outputs || []).join(', ') })));
    const disks = (z.sources || []).filter((x) => x.kind === 'disk').map((x) => srcLabel(x));
    if (disks.length) meta.appendChild(el('span', '', T('import.disks', { list: disks.join(', ') })));
    const p = z.target && Array.isArray(z.target.points) && z.target.points.length === 2 ? z.target.points : null;
    if (p) meta.appendChild(el('span', '', T('import.curve', { t1: p[0][0], t2: p[1][0], v1: p[0][1], v2: p[1][1] })));
    main.appendChild(meta);
    r.appendChild(main);
    list.appendChild(r);
  });
  box.appendChild(list);
  if ((imp.unmatched || []).length) box.appendChild(el('p', 'fb-hint-text', T('import.unmatched', { n: imp.unmatched.length })));
  if (imp.active) box.appendChild(el('p', 'fb-hint-text', T('import.active')));
  const go = el('a', 'btn small', T('import.use'));
  go.href = `#/${ID}/setup/zones`;
  const bar = el('div', 'toolbar');
  bar.appendChild(go);
  box.appendChild(bar);
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
    box('warn', T(`alarm.${c.alarm.code}`, { fan: '', source: '', c: '', zone: '', ...(c.alarm.params || {}) }), { alarm: c.alarm.code });
  }
  if (['chip_missing', 'crashed'].includes(w.why)) {
    const p = box('warn', T(`control.${w.why}`), { why: w.why });
    p.appendChild(button(T('control.look_now'), T('control.look_now_tip'), (b) => act('look_now', {}, b, () => Office.toast(T('control.looked')))));
  }
  if (w.why === 'array_stopped' || (c.armed && c.why === 'array_stopped')) box('warn', T('control.array_stopped'), { why: 'array_stopped' });
  if (s === 'other') {
    box('warn', T('situation.other', { who: otherNames() }), { situation: s });
  } else if (s === 'driver_missing') {
    box('warn', T('situation.driver_missing', { plugins: (st.plugins || []).join(', '), modules: (st.modules || []).join(', ') }), { situation: s });
  } else if (s === 'ipmi') {
    const p = box('warn', T('situation.ipmi'), { situation: s });
    p.appendChild(reportButton(true));
  } else if (s === 'no_output') {
    box('warn', T('situation.no_output'), { situation: s });
  } else if (s === 'ok' && !banner.children.length) {
    box('', steers() ? T('situation.steers', { n: w.zones || 0 }) : c.armed === false && (setup().zones || []).length ? T('situation.not_armed') : T('situation.ok'), { situation: s });
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
  const ours = found.filter((f) => ROLES_OURS.includes(f.role) && f.found === 'tach');
  const s = step();
  let value = '';
  const sub = [];
  if (k === 'detect') { value = T('step.v.outputs', { n: (su.outputs || []).length }); }
  if (k === 'test') {
    const done = (su.outputs || []).filter((id) => { const f = fans.find((x) => x.id === id); return f && f.found; }).length;
    value = running(s) && s.kind === 'test_up' ? T('step.v.running') : T('step.v.of', { n: done, m: (su.outputs || []).length });
  }
  if (k === 'roles') value = found.length ? T('step.v.of', { n: found.filter((f) => f.role).length, m: found.length }) : '–';
  if (k === 'measure') value = running(s) && s.kind === 'measure' ? T('step.v.running') : ours.length ? T('step.v.of', { n: ours.filter((f) => f.measured || f.no_turn).length, m: ours.length }) : '–';
  if (k === 'zones') value = (su.zones || []).length ? T('step.v.zones', { n: su.zones.length }) : '–';
  if (k === 'dry') value = dry ? T('step.v.seen') : '–';
  if (k === 'take') value = steers() ? T('who.fanboy', { n: who().zones || 0 }) : su.armed ? T('step.v.armed') : T('who.bios');
  if (here && !(k === 'take' && (steers() || su.armed))) sub.push(T('step.here'));
  if (k === 'roles' && (su.back_open || []).length) sub.push(T('step.back_open', { n: su.back_open.length }));
  return Office.stat({ label: T('step.n', { n: i + 1, name: T(`step.${k}`) }), value, sub, alert: k === 'take' && control().running && !!control().alarm, ...a });
}
function stepSection(k) {
  const s = el('section', 'section fb-step');
  s.dataset.step = k;
  s.appendChild(Office.sectionHead(T('step.n', { n: STEPS.indexOf(k) + 1, name: T(`step.${k}`) }), T(`step.${k}_sub`), { place: `step.${k}` }));
  const body = ({ detect: detectStep, test: testStep, roles: rolesStep, measure: measureStep, zones: zonesStep, dry: dryStep, take: takeStep })[k]();
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
    box.appendChild(para(s === 'ipmi' ? T('detect.ipmi') : s === 'driver_missing' ? T('detect.driver_missing') : T('detect.none'), 'callout warn'));
    const go = el('a', 'btn small', T('detect.hints'));
    go.href = `#/${ID}/hints`;
    box.appendChild(bar(go, reportButton(true)));
    return box;
  }
  if (s === 'other') box.appendChild(para(T('situation.other', { who: otherNames() }), 'callout warn'));
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

// 2 — test each output upward
function testStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const s = step();
  const live = running(s) && s.kind === 'test_up';
  if (blocked(box)) return box;
  stepEnded(box, s, 'test_up');
  const list = el('div', 'fb-cards');
  (su.outputs || []).forEach((id) => {
    const f = setupFan(id);
    const r = s && s.kind === 'test_up' && s.results ? s.results[id] : null;
    const under = live && s.current === id;
    const o = outputNow(id);
    const card = Office.place(`test:${id}`, el('div', 'fb-card' + (under ? ' fb-card-now' : '')));
    // the output under test turns faster: its wheel shows what the output gets now (full while tested)
    card.appendChild(cardWheel({ rpm: o && num(o.pct) !== null ? 2000 * o.pct / 100 : 0, min: 400, max: 2000, state: under ? 'run' : 'bios', mm: 120,
      colour: under ? 'accent' : 'auto' }));
    const txt = el('div', 'fb-card-text');
    txt.appendChild(el('div', 'fb-card-name', fanName(id)));
    const found = f ? f.found : null;
    let line = T('test.not_yet');
    if (under) line = T('test.now');
    else if (live && (s.fans || []).includes(id) && !(s.done || []).includes(id)) line = T('test.waiting');
    else if (found === 'tach') line = (f.tachs || []).length > 1 ? T('test.hub', { list: f.tachs.join(', ') }) : T('test.tach', { n: (f.tachs || [])[0] });
    else if (found === 'silent') line = T('test.silent');
    else if (found === 'empty') line = T('test.empty');
    else if (found === 'unclear') line = T('test.unclear');
    else if (found === 'ask') line = T('test.ask');
    if (r && r.found === 'busy') line = T('test.busy');
    txt.appendChild(el('div', 'fb-card-line', line));
    if (!live && ['ask', 'unclear'].includes(found)) {
      txt.appendChild(el('div', 'fb-card-line fb-strong', T('test.louder')));
      txt.appendChild(bar(
        button(T('test.yes'), T('test.yes_tip'), (b) => act('answer', { fan: id, louder: true }, b)),
        button(T('test.no'), T('test.no_tip'), (b) => act('answer', { fan: id, louder: false }, b)),
        button(T('test.again'), T('test.again_tip'), (b) => startStep('test_up', [id], b))));
    } else if (!live) {
      txt.appendChild(bar(button(found ? T('test.again') : T('test.one'), T('test.one_tip'), (b) => startStep('test_up', [id], b))));
    }
    card.appendChild(txt);
    list.appendChild(card);
  });
  box.appendChild(list);
  if (live) {
    box.appendChild(runningLine(s, T('test.running', { n: (s.done || []).length + 1, m: (s.fans || []).length })));
  } else {
    const all = button(T('test.all'), T('test.all_tip'), (b) => startStep('test_up', su.outputs || [], b), 'btn');
    box.appendChild(bar(all, nextButton('test')));
  }
  return box;
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
  box.appendChild(para(has(`step.ended.${why}`) ? T(`step.ended.${why}`) : T('step.ended.stopped'), 'callout warn'));
}
/** A step can't run now: another controller steers, or Fanboy steers (hand back first) */
function blocked(box) {
  if (situation() === 'other') {
    box.appendChild(para(T('situation.other', { who: otherNames() }), 'callout warn'));
    return true;
  }
  if (control().running) {
    box.appendChild(para(T('step.steering'), 'callout'));
    box.appendChild(bar(button(T('take.hand_back'), T('take.hand_back_tip'), (b) => handBack(b))));
    return true;
  }
  return false;
}
async function startStep(kind, ids, b) {
  if (!ids.length) return;
  await act(kind, { fans: ids }, b, async () => { await load(true); });
}

// 3 — roles: what each fan is, its name, place, model, colour, «give back to»
function rolesStep() {
  const box = el('div', 'fb-step-body');
  const su = setup();
  const found = (su.fans || []).filter((f) => ['tach', 'silent', 'unclear'].includes(f.found));
  if (!found.length) {
    box.appendChild(para(T('roles.none')));
    return box;
  }
  const list = el('div', 'fb-cards fb-cards-wide');
  found.forEach((f) => list.appendChild(roleCard(f)));
  box.appendChild(list);
  if ((su.back_open || []).length) box.appendChild(para(T('back.open_note', { n: su.back_open.length }), 'callout warn'));
  box.appendChild(bar(nextButton('roles')));
  return box;
}
function roleCard(f) {
  const id = f.id;
  const su = setup();
  const card = Office.place(`role:${id}`, el('div', 'fb-card fb-card-role'));
  const look = fanLook(f);
  card.appendChild(cardWheel({ rpm: rpmNow(id, f.tachs), mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: look.colour,
    state: f.found === 'silent' ? 'unknown' : 'run' }));
  const form = el('div', 'fb-form');
  const head = el('div', 'fb-card-name', T('out.name', { n: f.output }) + (f.found === 'tach' ? ` · ${T('pic.rpm', { rpm: fmt.number(rpmNow(id, f.tachs) ?? 0) })}` : f.found === 'silent' ? ` · ${T('pic.no_tach')}` : ''));
  form.appendChild(head);
  if (f.output === 1 && !f.role) form.appendChild(para(T('roles.cpu_hint')));
  // the role: his kinds, the BIOS's kinds («don't know» stays the BIOS's)
  const role = el('select');
  role.appendChild(new Option(T('roles.choose'), ''));
  const g1 = el('optgroup');
  g1.label = T('roles.his');
  ROLES_OURS.forEach((r) => g1.appendChild(new Option(T(`role.${r}`), r)));
  const g2 = el('optgroup');
  g2.label = T('roles.bios');
  ROLES_BIOS.forEach((r) => g2.appendChild(new Option(T(`role.${r}`), r)));
  role.append(g1, g2);
  role.value = f.role || '';
  const name = el('input');
  name.type = 'text';
  name.maxLength = 40;
  name.value = f.name || '';
  name.placeholder = T('roles.name_ph');
  const place = el('select');
  place.appendChild(new Option(T('roles.place_any'), ''));
  PLACES.forEach((p) => place.appendChild(new Option(T(`place.${p}`), p)));
  place.value = f.place || '';
  const save = () => {
    if (!role.value) return;
    act('role', { fan: id, role: role.value, name: name.value.trim(), place: place.value, off_ok: !!f.off_ok && ROLES_OURS.includes(role.value) }, null);
  };
  role.onchange = save;
  place.onchange = save;
  name.onchange = save;
  const field = (label, input) => { const l = el('label', 'fb-field'); l.append(el('span', 'fb-field-label', label), input); return l; };
  form.append(field(T('roles.role'), role), field(T('roles.name'), name), field(T('roles.place'), place));
  if (ROLES_BIOS.includes(f.role)) form.appendChild(para(T(`roles.bios_note.${f.role}`)));
  // the model from the catalogue, or «other fan» by size; the wheel's colour
  const A = art();
  if (A && cat) {
    let pk = pickers.get(id);
    if (!pk) {
      pk = A.picker({ catalog: cat, value: f.model ? (f.model.id ? { id: f.model.id } : { mm: f.model.mm, blades: f.model.blades }) : null, texts: pickerTexts(),
        onChange: (v) => { if (!v) return; act('fan_look', v.id ? { fan: id, model: v.id } : { fan: id, model: '', mm: v.mm, blades: v.blades }, null); } });
      pickers.set(id, pk);
    }
    const det = keepFold(el('details', 'fb-model'), `model:${id}`, !f.model);
    det.appendChild(el('summary', '', f.model ? T('roles.model_is', { model: modelText(f.model) }) : T('roles.model')));
    det.appendChild(pk.el);
    const colour = el('select');
    COLOURS.forEach((c) => colour.appendChild(new Option(T(`colour.${c}`), c)));
    colour.value = f.colour || 'auto';
    colour.onchange = () => act('fan_look', { fan: id, colour: colour.value }, null);
    det.appendChild(field(T('roles.colour'), colour));
    const c = catFan(f.model && f.model.id);
    if (c && f.measured && num(f.max_rpm) && num(c.rpm_max) && Math.abs(f.max_rpm - c.rpm_max) > Math.max(250, 0.3 * c.rpm_max)) {
      det.appendChild(para(T('roles.model_off', { measured: fmt.number(f.max_rpm), sheet: fmt.number(c.rpm_max) }), 'callout'));
    }
    form.appendChild(det);
  } else form.appendChild(waiting());
  // «give back to» for a fan found on a fixed value someone else set
  const b = (su.before || {})[id];
  if (b && b.fixed && !ROLES_BIOS.includes(f.role)) form.appendChild(backChoice(f, b));
  card.appendChild(form);
  return card;
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
    r.disabled = off || busy || !Office.agent.running;
    r.onchange = () => act('back', { fan: f.id, back: v }, null);
    l.append(r, el('span', '', label));
    if (off) l.title = T('back.no_auto');
    box.appendChild(l);
  });
  if (noAuto) box.appendChild(para(T('back.no_auto_note', { pct: b.pct ?? '–' }), 'fb-strong'));
  else if (!f.back && !setup().back_default) box.appendChild(para(T('back.open'), 'fb-strong'));
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
  const ours = (su.fans || []).filter((f) => ROLES_OURS.includes(f.role) && f.found === 'tach');
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
    card.appendChild(cardWheel({ rpm: rpmNow(f.id, f.tachs), mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: under ? 'accent' : look.colour }));
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
  const ours = (su.fans || []).filter((f) => ROLES_OURS.includes(f.role) && ['tach', 'silent'].includes(f.found));
  if (!ours.length) {
    box.appendChild(para(T('zones.no_fans')));
    return box;
  }
  const zones = zonesNow();
  const tools2 = bar(
    button(T('zones.propose'), T('zones.propose_tip'), (b) => act('zones_propose', {}, b, (j) => { zdraft = (j.zones || []).map((z) => ({ ...z, name: z.name || T(`zones.kind.${z.kind}`) })); })),
    S().import && (S().import.zones || []).length ? button(T('zones.import'), T('zones.import_tip'), () => {
      zdraft = importAsZones();
      drawn = '';
      redraw();
    }) : null,
    zones.length < 8 ? button(T('zones.add'), T('zones.add_tip'), () => changeZones((z) => z.push({ id: freeId(z), name: T('zones.new_name', { n: z.length + 1 }), fans: [], sources: [], unit: 'pct', target: { preset: 'normal' } }))) : null);
  box.appendChild(tools2);
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
  return out;
}
/** FanCtrl Plus's zones as a proposal: only fans he may steer, sources the server shows */
function importAsZones() {
  const su = setup();
  const okFan = new Set((su.fans || []).filter((f) => ROLES_OURS.includes(f.role) && ['tach', 'silent'].includes(f.found)).map((f) => f.id));
  const okSrc = new Set((su.sources || []).map((s) => s.id));
  const used = new Set();
  return (S().import.zones || []).map((z, i) => {
    const fans = (z.fans || []).filter((id) => okFan.has(id) && !used.has(id));
    fans.forEach((id) => used.add(id));
    return { id: `fcp${i + 1}`, name: z.name || T('zones.new_name', { n: i + 1 }), fans, unit: 'pct', sources: (z.sources || []).filter((s) => okSrc.has(s.id)).map((s) => ({ id: s.id, kind: s.kind })),
      target: z.target && Array.isArray(z.target.points) ? { points: z.target.points } : { preset: 'normal' } };
  });
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
    row.appendChild(cardWheel({ rpm: rpmNow(id, f.tachs), mm: look.mm, blades: look.blades, min: look.min, max: look.max, colour: look.colour, px: 40, state: f.found === 'silent' ? 'unknown' : 'run' }));
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
  const kinds = ['disk', 'nvme', 'cpu', 'ram', 'board', 'gpu', 'other'];
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
  card.appendChild(curvePart(z, i));
  return card;
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
  const presets = ((setup().presets_of || {})[kind]) || ((setup().curves || {})[z.id] || {}).presets || null;
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
  const floor = lead.measured && num(lead.min_pct) !== null ? (rpmUnit ? tableRpm(lead.table, lead.min_pct) : lead.min_pct) : null;
  const cz = (control().zones || []).find((x) => x.id === z.id);
  const nowC = cz && cz.warmest ? num(cz.warmest.mapped) ?? num(cz.warmest.c) : null;
  const key = `${z.id}|${rpmUnit}|${tMin}|${tMax}|${vMax}|${own}|${own ? '' : tgt.preset}|${floor}`;
  let cv = curves.get(z.id);
  if (!cv || cv.key !== key) {
    if (cv) cv.c.destroy();
    const c = A.curve({
      points: pts.map((p) => ({ t: p[0], v: p[1] })), unit: rpmUnit ? 'rpm' : 'percent', tMin, tMax, vMin: 0, vMax, floor: floor ?? undefined,
      now: nowC ?? undefined, fan: { mm: look.mm, blades: look.blades, min: look.min, max: look.max || 2000, colour: look.colour },
      rpmAt: lead.measured ? (v) => (v <= 0 ? 0 : tableRpm(lead.table, v)) : undefined,
      texts: {
        axisT: T('curve.axis_t'), axisV: rpmUnit ? T('curve.axis_rpm') : T('curve.axis_pct'), tickT: (x) => `${x}°`, tickV: (v) => (rpmUnit ? fmt.number(v) : `${v} %`),
        point: (k, n) => T('curve.point', { i: k + 1, n }), value: (x, v) => (rpmUnit ? T('curve.value_rpm', { t: x, v: fmt.number(v) }) : T('curve.value_pct', { t: x, v })),
        readout: (x, v, r) => T('curve.readout', { t: x, v: rpmUnit ? T('pic.rpm', { rpm: fmt.number(v) }) : T('value', { pct: Math.round(v) }), rpm: fmt.number(Math.round(r || 0)) }),
        floor: T('curve.floor'), now: nowC !== null ? T('curves.now', { c: fmt.number(nowC) }) : '',
      },
      // dragging a preset makes it the zone's own curve; kept on the page until «Save»
      onChange: (p) => {
        const zs = zonesNow();
        if (!zs[i]) return;
        zs[i].target = { points: p.map((x) => [x.t, x.v]) };
        zdraft = zs;
        cv.key = `${z.id}|${rpmUnit}|${tMin}|${tMax}|${vMax}|true||${floor}`;
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
  if (situation() === 'other') why.push(T('situation.other', { who: otherNames() }));
  if (!(su.zones || []).length) why.push(T('take.no_zones'));
  if ((su.back_open || []).length) why.push(T('back.open_note', { n: su.back_open.length }));
  if (stepRuns()) why.push(T('take.step_runs'));
  box.appendChild(para(T('take.text')));
  why.forEach((w) => box.appendChild(para(w, 'callout warn')));
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
  const plain = plainNow();
  const actions = page === '' ? [reportButton(true)] : [];
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: plain ? bubbleText() : Office.withGreeting(ID, bubbleText()), actions,
    pages: { list: PAGES(), current: page } });
  if (page === 'setup') {
    const help = Office.pageHelp(`${ID}-setup`, [[T('help.setup'), T('help.setup_text')], [T('help.safe'), T('help.safe_text')], [T('help.later'), T('help.later_text')]]);
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
    if (page === 'setup') {
      // #/fanboy/setup/<step> picks that step like a click; else the step at hand once his state is in (never kept)
      const rest = String(sub || '').split('/').slice(1).join('/');
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
    curves.forEach((c) => c.c.destroy());
    curves.clear();
  },
  agentChanged() { drawn = ''; if (view) redraw(); },
  async reception() {
    if (!state) await load(false);
    if (!state) return null;
    const facts = [];
    const c = counts();
    if (c.fans) facts.push(T('fans.turning', { n: c.turning || 0 }));
    if (S().hottest) facts.push(`${T('tile.hottest')}: ${T('temp', { c: fmt.number(S().hottest.c) })}`);
    return { bubble: bubbleText(), facts };
  },
});

// his places for the search (places.json beside desk.json lists the same keys)
const SETUP_ROUTE = { route: `#/${ID}/setup`, crumb: 'page.setup' };
Office.places(ID, [
  { kind: 'section', key: 'server.title', route: `#/${ID}/who` },
  { kind: 'section', key: 'notes.title', route: `#/${ID}/who` },
  { kind: 'section', key: 'fans.title', route: `#/${ID}/fans` },
  { kind: 'section', key: 'sensors.title', route: `#/${ID}/hottest` },
  { kind: 'section', key: 'curves.title', route: `#/${ID}/today` },
  { kind: 'section', key: 'hints.title', route: `#/${ID}/hints` },
  { kind: 'section', key: 'drivers.title', route: `#/${ID}/hints` },
  { kind: 'section', key: 'import.title', route: `#/${ID}/hints` },
  { kind: 'help', key: 'help.who', text: 'help.who_text' },
  { kind: 'help', key: 'help.picture', text: 'help.picture_text' },
  { kind: 'help', key: 'help.outputs', text: 'help.outputs_text' },
  { kind: 'help', key: 'help.sensors', text: 'help.sensors_text' },
  { kind: 'help', key: 'help.curves', text: 'help.curves_text' },
  { kind: 'help', key: 'help.report', text: 'help.report_text' },
  { kind: 'help', key: 'help.setup', text: 'help.setup_text', ...SETUP_ROUTE },
  { kind: 'help', key: 'help.safe', text: 'help.safe_text', ...SETUP_ROUTE },
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
