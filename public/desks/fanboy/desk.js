/* Fanboy — the office's building services and his fans' biggest fan. Part 1, «Erkennen»: what the server shows (the
   chips Linux sees, their outputs and tach inputs, every temperature, Unraid's disks from Unraid's own records), the
   situation as a banner (ok · another plugin steers · no output · driver missing · IPMI), who steers now (part 1: never
   Fanboy) and a button that opens the office's report form as a wish with his report in it (the user sees it all in the
   form and its preview before anything goes). He changes nothing: no write action exists. His look runs on the server
   (agent/desks/fanboy-detect.php), read only; the page asks for a new one every few seconds while it is shown.
   Part 2 adds, small on purpose (the setup's wizard and the pictures come in part 3): who steers and why (Fanboy and his
   zones, or the BIOS with the reason), the alarm, «Look now» while the fan chip is missing, each output's label (his,
   the BIOS's, watched), his recent events, «Load drivers» and its undo, FanCtrl Plus's files as a proposal.
   Hidden until the owner lets him out (desk.json "switch"): without the switch file the office has no such desk. */
(() => {
'use strict';

const ID = 'fanboy';
const T = Office.scope(ID);
const { el, fmt } = Office;
const LOOK_MS = 5000;           // a new look while the page is shown (the agent's look is a few file reads)

let state = null;
let view = null;
let timer = null;
let banner = null;              // the situation, between the row of tiles and the sections (it stays at every click)
let sensorBar = null;           // the sensors' filter bar (core.js Office.filterBar): built once, words never kept

const chips = () => (state && Array.isArray(state.chips) ? state.chips : []);
const offered = (c) => c.outputs.length > 0 && c.kind !== 'gpu';
const situation = () => (state && state.situation && state.situation.code) || '';
const counts = () => (state && state.counts) || {};
const otherNames = () => ((state && state.situation && state.situation.names) || []).join(', ');
const control = () => (state && state.control) || {};
const who = () => (state && state.who) || {};
/** His fans in the zones as the control says them: output number → {pct, held, zone} (only his chip's) */
function heldOutputs() {
  const out = new Map();
  (control().zones || []).forEach((z) => (z.fans || []).forEach((f) => out.set(f.id, { ...f, zone: z.name })));
  return out;
}
const myWhy = (why) => (why && Office.has(`${ID}.why.${why}`) ? T(`why.${why}`) : '');
let busy = false;
const tools = () => (state && Array.isArray(state.tools) ? state.tools : []);
/** What the user named or hid in Dynamix System Temp for a chip's feature (fan1, temp2) — a suggestion, read only */
const nameOf = (c, feature) => ((state && state.names) || []).find((x) => x.address === c.address && x.chip === c.name && x.feature === feature) || null;

/**
 * A fold the user opened or closed stays so while the page is shown (it is drawn anew with every look): the default
 * counts only until it is touched (the Consultant's keepFold())
 */
const folds = new Map();
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

// ------------------------------------------------------------------ loading
/** His state: as kept at once, a new look following (core.js Office.loadState()) */
function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view) Office.keepInPlace(null, render);
  clearTimeout(timer);
  if (view) timer = setTimeout(() => load(false), LOOK_MS);
}

function bubbleText() {
  if (!state) return T('bubble.loading');
  const w = who();
  if (w.who === 'fanboy') return w.why === 'alarm' ? T('bubble.alarm') : T('bubble.steers', { n: w.zones || 0 });
  const s = situation();
  if (s === 'ok') return T('bubble.ok', { n: counts().outputs || 0 });
  if (s === 'other') return T('bubble.other', { who: otherNames() });
  if (s === 'driver_missing' || s === 'no_output' || s === 'ipmi') return T(`bubble.${s}`);
  return T('bubble.loading');
}

// ------------------------------------------------------------------ the report for the makers' wish form
/**
 * «Send to the makers…»: the agent writes his report (fanboy.report, read only); the office's report form opens as a wish
 * of Fanboy's desk with it in the text — the user writes his wish above it, sees everything in the form and in its
 * preview, and nothing goes without his «Send» (core.js Office.reportDialog()). A draft of his own stays: the report goes
 * under it, once.
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

// ------------------------------------------------------------------ the tiles: a tile is a section
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
  const zero = Number(c.zero) || 0;          // 0 rpm reported (an input that says nothing isn't one)
  const sub = [];
  if (turning) sub.push(T('fans.range', { min: fmt.number(c.rpm_min), max: fmt.number(c.rpm_max) }));
  if (zero) sub.push(T('fans.zero', { n: zero }));
  return Office.stat({ label: T('tile.fans'), value: !state ? '…' : all ? T('fans.turning', { n: turning }) : T('fans.none'), sub, ...a });
}
function hintsTile(a) {
  const n = state && Array.isArray(state.suggest) ? state.suggest.length : 0;
  const there = tools().filter((x) => x.installed).length;
  const value = !state ? '…' : n ? T('hints.fits', { n }) : state.hints_open ? T('hints.read') : T('hints.why');
  return Office.stat({ label: T('tile.hints'), value, sub: state ? T('hints.there', { n: there }) : '', alert: !!(state && state.hints_open), ...a });
}
function hottestTile(a) {
  const h = state && state.hottest;
  const where = h ? (h.disk || [T(`kind.${h.kind}`), h.label].filter(Boolean).join(' · ')) : '';
  return Office.stat({ label: T('tile.hottest'), value: !state ? '…' : h ? T('hottest.value', { c: fmt.number(h.c) }) : T('hottest.none'), sub: where, ...a });
}

const sw = Office.sections({
  id: ID,
  grid: 'stats',
  default: '',
  tiles: () => [
    { id: 'who', key: 'tile.who', place: 'tile.who', tile: whoTile, section: () => outputsSection() },
    { id: 'fans', key: 'tile.fans', place: 'tile.fans', tile: fansTile, section: () => fansSection() },
    { id: 'hottest', key: 'tile.hottest', place: 'tile.hottest', tile: hottestTile, section: () => sensorsSection() },
    { id: 'hints', key: 'tile.hints', place: 'tile.hints', tile: hintsTile, section: () => hintsSection() },
  ],
});

// ------------------------------------------------------------------ the sections
/** A chip's name and its address (the stable one, never this boot's hwmonN) */
function chipLabel(c) {
  const s = el('span', 'mono', c.address && c.address !== c.name ? `${c.name} · ${c.address}` : c.name);
  s.title = T('chip_title');
  return s;
}
function chipOf(cls, text, tip) {
  const c = el('span', `chip ${cls}`, text);
  c.title = tip;
  return c;
}

/** His zones as the control says them: the warmest spot, the level, how many sources sleep */
function zonesBox() {
  const zones = control().running ? (control().zones || []) : [];
  if (!zones.length) return null;
  const box = el('div', 'box');
  zones.forEach((z) => {
    const r = Office.place(`zone:${z.id}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('zones.row', { name: z.name })));
    const meta = el('div', 'row-meta');
    if (z.warmest) meta.appendChild(el('span', '', T('zones.warmest', { where: z.warmest.label, c: fmt.number(z.warmest.c) })));
    else meta.appendChild(el('span', '', T('zones.nothing_warm')));
    if (z.asleep) meta.appendChild(chipOf('quiet', T('zones.asleep', { n: z.asleep }), T('zones.asleep_title')));
    meta.appendChild(el('span', '', T('zones.fans', { n: (z.fans || []).length })));
    main.appendChild(meta);
    // what its fans get (the curve's level raised to each fan's lowest steady value), not the bare curve
    const pcts = (z.fans || []).filter((f) => f.held && typeof f.pct === 'number').map((f) => f.pct);
    const lvl = pcts.length ? T('value', { pct: Math.round(pcts.reduce((a, b) => a + b, 0) / pcts.length) })
      : z.level === null || z.level === undefined ? '–' : z.unit === 'rpm' ? T('fans.rpm', { rpm: fmt.number(Math.round(z.level)) }) : T('value', { pct: Math.round(z.level) });
    r.append(main, el('div', 'fb-value', lvl));
    box.appendChild(r);
  });
  return box;
}

/** An output's label: his (with the percentage he set), the BIOS's, watched (a CPU cooler or pump of the setup) */
function outputLabel(c, o, held) {
  const id = `${c.name}:${c.address}:pwm${o.n}`;
  const h = held.get(id);
  if (h && h.held && control().running) return chipOf('accent', T('out.held', { pct: h.pct === null || h.pct === undefined ? '–' : h.pct }), T('out.held_title', { zone: h.zone }));
  if (h && h.foreign) return chipOf('warn', T('out.foreign'), T('out.foreign_title'));
  const watched = (control().watch || []).some((w) => w.id === id);
  if (watched) return chipOf('quiet', T('out.watched'), T('out.watched_title'));
  return null;
}

/** What happened lately at his fans (the control's notes, newest first, known kinds only) */
function notesBox() {
  const notes = (control().notes || []).filter((n) => n && Office.has(`${ID}.note.${n.code}`)).slice(-6).reverse();
  if (!notes.length) return null;
  const box = el('div', 'box');
  notes.forEach((n) => {
    const r = el('div', 'row nocheck fb-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T(`note.${n.code}`, n.params || {})));
    if (n.at) main.appendChild(el('div', 'row-meta', fmt.relative(n.at)));
    r.appendChild(main);
    box.appendChild(r);
  });
  return box;
}

function outputsSection() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('outputs.title'), who().who === 'fanboy' ? T('outputs.sub_steers') : T('outputs.sub'), { place: 'outputs.title' }));
  const zones = zonesBox();
  if (zones) s.append(Office.place('zones.title', el('h3', 'fb-hint-head', T('zones.title'))), zones);
  const held = heldOutputs();
  const list = el('div', 'box');
  chips().filter((c) => c.outputs.length).forEach((c) => c.outputs.forEach((o) => {
    const r = Office.place(`out:${c.address}:${o.n}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('outputs.row', { n: o.n })));
    const meta = el('div', 'row-meta');
    meta.appendChild(chipLabel(c));
    if (!offered(c)) meta.appendChild(el('span', 'note', T('outputs.gpu')));
    const mine = outputLabel(c, o, held);
    if (mine) meta.appendChild(mine);
    const mode = o.mode || 'unknown';
    meta.appendChild(chipOf(mode === 'auto' ? 'ok' : mode === 'unknown' ? 'quiet' : 'accent', T(`mode.${mode}`), T(`mode.${mode}_title`)));
    if (o.auto && mode !== 'auto') meta.appendChild(chipOf('quiet', T('can_auto'), T('can_auto_title')));
    main.appendChild(meta);
    const v = el('div', 'fb-value', o.pct === null || o.pct === undefined ? '–' : T('value', { pct: o.pct }));
    if (o.value !== null && o.value !== undefined) v.title = T('value_title', { value: o.value });
    r.append(main, v);
    list.appendChild(r);
  }));
  if (!list.children.length) list.appendChild(el('p', 'empty', T('outputs.none')));
  s.appendChild(list);
  const notes = notesBox();
  if (notes) s.append(el('h3', 'fb-hint-head', T('notes.title')), notes);
  return s;
}

function fansSection() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('fans.title'), T('fans.sub'), { place: 'fans.title' }));
  const list = el('div', 'box');
  chips().forEach((c) => c.fans.forEach((f) => {
    const r = Office.place(`fan:${c.address}:${f.n}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', f.label || T('fans.row', { n: f.n })));
    const meta = el('div', 'row-meta');
    meta.appendChild(chipLabel(c));
    namedChip(meta, c, `fan${f.n}`);
    if (f.rpm === null || f.rpm === undefined) meta.appendChild(chipOf('quiet', T('fans.no_value'), T('fans.no_value_title')));
    main.appendChild(meta);
    r.append(main, el('div', 'fb-value', f.rpm === null || f.rpm === undefined ? '–' : T('fans.rpm', { rpm: fmt.number(f.rpm) })));
    list.appendChild(r);
  }));
  if (!list.children.length) list.appendChild(el('p', 'empty', T('fans.empty')));
  s.appendChild(list);
  return s;
}

/** Every temperature: the chips' and Unraid's disks' (a sleeping disk: none, said) */
function sensorRows() {
  const out = [];
  chips().forEach((c) => c.temps.forEach((t) => out.push({ name: t.label || T('sensors.row', { n: t.n }), chip: c, kind: c.kind, c: t.c, key: `${c.address}:${t.n}`, feature: `temp${t.n}` })));
  ((state && state.disks) || []).forEach((d) => out.push({ name: d.name, chip: null, kind: 'disk', c: d.temp, asleep: d.asleep, key: `disk:${d.name}` }));
  return out;
}

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
  const fits = all.filter((x) => Office.fits(f.words, [x.name, T(`kind.${x.kind}`), x.chip ? x.chip.name : '', x.chip ? ((nameOf(x.chip, x.feature) || {}).label || '') : '']));
  const list = el('div', 'box');
  fits.forEach((x) => {
    const r = Office.place(`temp:${x.key}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', x.name));
    const meta = el('div', 'row-meta');
    meta.appendChild(chipOf('quiet', T(`kind.${x.kind}`), T('kind_title')));
    if (x.chip) meta.appendChild(chipLabel(x.chip));
    if (x.chip) namedChip(meta, x.chip, x.feature);
    if (x.asleep) meta.appendChild(chipOf('quiet', T('sensors.asleep'), T('sensors.asleep_title')));
    else if (x.c === null || x.c === undefined) meta.appendChild(chipOf('quiet', T('sensors.no_value'), T('sensors.no_value_title')));
    main.appendChild(meta);
    r.append(main, el('div', 'fb-value', x.c === null || x.c === undefined ? '–' : T('hottest.value', { c: fmt.number(x.c) })));
    list.appendChild(r);
  });
  if (!list.children.length) list.appendChild(el('p', 'empty', all.length ? T('sensors.none_fit') : T('sensors.none')));
  s.appendChild(list);
  bar.done(fits.length, all.length, sw.note());
  return s;
}

/** A row's name or «hidden» from Dynamix System Temp, as a quiet label (a suggestion for the setup) */
function namedChip(meta, c, feature) {
  const n = nameOf(c, feature);
  if (!n) return;
  if (n.label) meta.appendChild(chipOf('quiet', `«${n.label}»`, T('hints.names')));
  if (n.ignore) meta.appendChild(chipOf('quiet', T('hints.names_hidden'), T('hints.names')));
}

/** A plugin's status as a label: a driver loaded or not, installed, steering now or not */
function toolChip(x) {
  if (x.kind === 'controller') return x.active ? chipOf('warn', T('hints.st_steering'), T('hints.st_steering_title')) : chipOf('quiet', T('hints.st_idle'), T('hints.st_idle_title'));
  if (x.kind === 'driver') return x.loaded ? chipOf('ok', T('hints.st_loaded'), T('hints.st_loaded_title')) : chipOf('warn', T('hints.st_not_loaded'), T('hints.st_not_loaded_title'));
  return chipOf('ok', T('hints.st_installed'), T('hints.st_installed_title'));
}

function hintHead(text) {
  return el('h3', 'fb-hint-head', text);
}
function hintParas(box, prefix) {
  for (let i = 1; Office.has(`${ID}.${prefix}.${i}`); i++) box.appendChild(el('p', 'fb-hint-text', T(`${prefix}.${i}`)));
}

/**
 * «Why is this tricky?» — always there, folded open by itself when no output shows, a driver is missing, another plugin
 * steers or the board steers by IPMI: what is already there, the plugins that fit (only those, by their exact names in
 * ⟦Apps⟧ — Fanboy installs nothing), why he may not see the fans, what belongs in the BIOS, who gets in each other's
 * way, the IPMI note with the wish button
 */
function hintsSection() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('hints.title'), T('hints.sub'), { place: 'hints.title' }));
  const det = keepFold(el('details', 'fb-hints'), 'hints', !!(state && state.hints_open));
  det.appendChild(el('summary', '', T('hints.fold')));
  const body = el('div', 'fb-hints-body');

  // what is already there
  body.appendChild(hintHead(T('hints.there_title')));
  const list = el('div', 'box');
  tools().filter((x) => x.installed || x.active).forEach((x) => {
    const r = Office.place(`plugin:${x.id}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', x.name));
    const meta = el('div', 'row-meta');
    meta.append(el('span', '', T('hints.by', { author: x.author })), toolChip(x));
    main.appendChild(meta);
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
      state.acpi_lax ? chipOf('ok', T('hints.acpi_set'), T('hints.acpi_set_title')) : chipOf('quiet', T('hints.acpi_unset'), T('hints.acpi_unset_title')));
    main.appendChild(meta);
    r.appendChild(main);
    list.appendChild(r);
  }
  const names = (state && state.names) || [];
  if (names.length) {
    const r = el('div', 'row nocheck fb-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('hints.names')));
    main.appendChild(el('div', 'row-meta', T('hints.names_count', { named: names.filter((x) => x.label).length, hidden: names.filter((x) => x.ignore).length })));
    r.appendChild(main);
    list.appendChild(r);
  }
  body.appendChild(list);

  // what fits here: only what the agent found fitting, by the exact name in ⟦Apps⟧
  const fits = ((state && state.suggest) || []).map((id) => tools().find((x) => x.id === id)).filter(Boolean);
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
      if (Office.has(`${ID}.plugin.${x.id}`)) main.appendChild(el('div', 'row-detail', T(`plugin.${x.id}`)));
      const chip = { 'it87-driver': 'ite', 'nct6687-driver': 'nuvoton' }[x.id];
      if (chip && state.board_hint === chip) main.appendChild(el('div', 'row-detail', T(`hints.board_${chip}`, { maker: state.board_maker })));
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

  // the three reasons, the BIOS, who gets in each other's way, IPMI
  body.appendChild(hintHead(T('hints.why_title')));
  const why = el('ol', 'fb-hint-list');
  for (let i = 1; Office.has(`${ID}.hints.why.${i}`); i++) why.appendChild(el('li', '', T(`hints.why.${i}`)));
  body.appendChild(why);
  body.appendChild(hintHead(T('hints.bios_title')));
  hintParas(body, 'hints.bios');
  body.appendChild(hintHead(T('hints.clash_title')));
  hintParas(body, 'hints.clash');
  body.appendChild(hintHead(T('hints.ipmi_title')));
  hintParas(body, 'hints.ipmi');
  const wish = el('button', 'btn small', T('report'));
  wish.type = 'button';
  wish.title = T('report_tip');
  wish.disabled = !Office.agent.running || !state;
  wish.onclick = () => sendReport(wish);
  const bar = el('div', 'toolbar');
  bar.appendChild(wish);
  body.appendChild(bar);
  det.appendChild(body);
  s.appendChild(det);
  return s;
}

/**
 * «Load drivers yourself»: only when no output shows (or he loaded some here) — the button, the hint that Dynamix System
 * Temp does the same, the last try's steps, the undo
 */
function driversBlock() {
  const d = (state && state.drivers) || {};
  const mine = d.mine || [];
  const s = situation();
  if (!mine.length && !['no_output', 'driver_missing'].includes(s)) return null;
  const box = el('div', 'fb-drivers');
  box.appendChild(el('p', 'fb-hint-text', T('drivers.text')));
  box.appendChild(el('p', 'fb-hint-text', T('drivers.self')));
  const last = d.last && Array.isArray(d.last.steps) ? d.last : null;
  if (last) {
    const list = el('div', 'box');
    last.steps.filter((x) => Office.has(`${ID}.drivers.r.${x.result}`)).forEach((x) => {
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
    const b = el('button', 'btn small', T('drivers.load'));
    b.type = 'button';
    b.title = T('drivers.load_tip');
    b.disabled = busy || !Office.agent.running;
    b.onclick = () => act('drivers_load', b, (j) => Office.toast(j.kept ? T('drivers.kept_toast', { module: j.kept }) : T('drivers.none_toast'), !j.kept));
    bar.appendChild(b);
  }
  if (mine.length) {
    const u = el('button', 'btn small', T('drivers.undo'));
    u.type = 'button';
    u.title = T('drivers.undo_tip');
    u.disabled = busy || !Office.agent.running;
    u.onclick = () => act('drivers_undo', u, () => Office.toast(T('drivers.undone_toast')));
    bar.appendChild(u);
  }
  if (bar.children.length) box.appendChild(bar);
  return box;
}

/** FanCtrl Plus's files as a proposal: what zone he would make of them — he changes nothing there */
function importBlock() {
  const imp = state && state.import;
  if (!imp || !imp.files) return null;
  const box = el('div', 'fb-import');
  box.appendChild(el('p', 'fb-hint-text', T('import.text', { n: imp.files })));
  const list = el('div', 'box');
  (imp.zones || []).forEach((z) => {
    const r = Office.place(`import:${z.id}`, el('div', 'row nocheck fb-row'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T('zones.row', { name: z.name })));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('import.outputs', { list: (z.outputs || []).join(', ') })));
    const disks = (z.sources || []).filter((x) => x.kind === 'disk').map((x) => x.id);
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
  return box;
}

/** An action of his from the page: the button waits, the new state drawn, a problem said */
async function act(action, b, done) {
  if (busy || !Office.agent.running) return;
  busy = true;
  if (b) b.disabled = true;
  await Office.freshState(ID);
  const j = await Office.api.post(`${ID}.${action}`, {});
  busy = false;
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); if (view) render(); return; }
  if (j.state) state = j.state;
  if (done) done(j);
  if (view) Office.keepInPlace(null, render);
}

// ------------------------------------------------------------------ the page
/** The control's word, between the tiles and the sections: an alarm, the chip missing (with «Look now»), a crash */
function controlCallout() {
  const c = control();
  const w = who();
  if (c.running && c.alarm && Office.has(`${ID}.alarm.${c.alarm.code}`)) {
    const p = el('p', 'callout warn', T(`alarm.${c.alarm.code}`, { fan: '', source: '', c: '', zone: '', ...(c.alarm.params || {}) }));
    p.dataset.alarm = c.alarm.code;
    return p;
  }
  if (!['chip_missing', 'crashed'].includes(w.why)) return null;
  const box = el('div', 'callout warn fb-control');
  box.appendChild(el('p', '', T(`control.${w.why}`)));
  const b = el('button', 'btn small', T('control.look_now'));
  b.type = 'button';
  b.title = T('control.look_now_tip');
  b.disabled = busy || !Office.agent.running;
  b.onclick = () => act('look_now', b, () => Office.toast(T('control.looked')));
  box.appendChild(b);
  return box;
}

/** The situation, plain: what holds here and what the user can do (the report's button beside the honest ones) */
function paintBanner() {
  if (!banner) return;
  banner.replaceChildren();
  const s = situation();
  if (!s) return;
  const warn = s !== 'ok';
  const p = el('p', 'callout' + (warn ? ' warn' : ''));
  p.dataset.situation = s;
  const st = (state && state.situation) || {};
  p.textContent = s === 'other' ? T('situation.other', { who: otherNames() })
    : s === 'driver_missing' ? T('situation.driver_missing', { plugins: (st.plugins || []).join(', '), modules: (st.modules || []).join(', ') })
      : s === 'ok' && who().who === 'fanboy' ? T('situation.steers', { n: who().zones || 0 })
        : s === 'ok' && who().why === 'not_armed' ? T('situation.not_armed')
          : T(`situation.${s}`);
  banner.appendChild(p);
  const c = controlCallout();
  if (c) banner.appendChild(c);
}

function render() {
  if (!view) return;
  const report = el('button', 'btn small', T('report'));
  report.type = 'button';
  report.title = T('report_tip');
  report.disabled = !Office.agent.running || !state;
  report.onclick = () => sendReport(report);
  // an alarm or a missing chip stays plain: no greeting in front of it
  const plain = ['alarm', 'chip_missing', 'crashed'].includes(who().why);
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: plain ? bubbleText() : Office.withGreeting(ID, bubbleText()), actions: [report] });
  const help = Office.pageHelp(ID, [
    [T('help.who'), T('help.who_text')],
    [T('help.outputs'), T('help.outputs_text')],
    [T('help.fans'), T('help.fans_text')],
    [T('help.sensors'), T('help.sensors_text')],
    [T('help.report'), T('help.report_text')],
  ]);
  view.replaceChildren(head, help, sw.el);
  if (!banner || banner.parentNode !== sw.el) banner = sw.el.insertBefore(el('div', 'fb-banner'), sw.body);
  paintBanner();
  sw.render();
}

Office.desk({
  id: ID,
  mount(root, sub) {
    view = root;
    sensorsBar().reset();             // a new visit: the whole list
    sw.mount(sub);
    render();
    load(false);
  },
  unmount() { view = null; clearTimeout(timer); timer = null; },
  agentChanged() { if (view) render(); },
  async reception() {
    if (!state) await load(false);
    if (!state) return null;
    const facts = [];
    const c = counts();
    if (c.fans) facts.push(T('fans.turning', { n: c.turning || 0 }));
    if (state.hottest) facts.push(`${T('tile.hottest')}: ${T('hottest.value', { c: fmt.number(state.hottest.c) })}`);
    return { bubble: bubbleText(), facts };
  },
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys)
Office.places(ID, [
  { kind: 'section', key: 'outputs.title', route: '#/fanboy/who' },
  { kind: 'section', key: 'fans.title', route: '#/fanboy/fans' },
  { kind: 'section', key: 'sensors.title', route: '#/fanboy/hottest' },
  { kind: 'help', key: 'help.who', text: 'help.who_text' },
  { kind: 'help', key: 'help.outputs', text: 'help.outputs_text' },
  { kind: 'help', key: 'help.fans', text: 'help.fans_text' },
  { kind: 'help', key: 'help.sensors', text: 'help.sensors_text' },
  { kind: 'help', key: 'help.report', text: 'help.report_text' },
  { kind: 'section', key: 'hints.title', route: '#/fanboy/hints' },
  { kind: 'section', key: 'zones.title', route: '#/fanboy/who' },
  { kind: 'section', key: 'drivers.title', route: '#/fanboy/hints' },
  { kind: 'section', key: 'import.title', route: '#/fanboy/hints' },
  ...sw.places(),
]);
// what his look holds worth finding: each output, tach input and sensor (his words, the chip's address — never a path)
Office.placesFrom(ID, (s) => {
  if (!s || !Array.isArray(s.chips)) return [];
  const out = [];
  s.chips.forEach((c) => {
    (c.outputs || []).forEach((o) => out.push({ text: T('outputs.row', { n: o.n }), sub: `${T('outputs.title')} · ${c.name}`, route: '#/fanboy/who', anchor: `out:${c.address}:${o.n}` }));
    (c.fans || []).forEach((f) => out.push({ text: f.label || T('fans.row', { n: f.n }), sub: `${T('fans.title')} · ${c.name}`, route: '#/fanboy/fans', anchor: `fan:${c.address}:${f.n}` }));
    (c.temps || []).forEach((t) => out.push({ text: t.label || T('sensors.row', { n: t.n }), sub: `${T('sensors.title')} · ${c.name}`, route: '#/fanboy/hottest', anchor: `temp:${c.address}:${t.n}` }));
  });
  (s.disks || []).forEach((d) => out.push({ text: d.name, sub: `${T('sensors.title')} · ${T('kind.disk')}`, route: '#/fanboy/hottest', anchor: `temp:disk:${d.name}` }));
  (s.tools || []).filter((x) => x.installed || x.active).forEach((x) => out.push({ text: x.name, sub: `${T('hints.there_title')} · ${x.author}`, route: '#/fanboy/hints', anchor: `plugin:${x.id}` }));
  ((s.control && s.control.running && s.control.zones) || []).forEach((z) => out.push({ text: z.name, sub: T('zones.title'), route: '#/fanboy/who', anchor: `zone:${z.id}` }));
  return out.slice(0, 200);
});
})();
