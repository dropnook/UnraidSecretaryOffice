#!/usr/bin/env node
// Fanboy's own tile on Unraid's Dashboard (src/dashboard-fanboy.php) as pictures: the tile as the web side draws it
// (php on this Mac, a plugin's layout in a temporary folder, the invented demo states of tests/ui/demo/fanboy*.json),
// placed in Unraid's own Dashboard markup (div.frame > div.grid > div.tile > table.dashboard, a neighbour tile, the
// chevron and close icons Unraid's script adds) with Unraid 7.4's own style sheets (tests/ui/unraid/: default-base.css,
// sheets/DashStats.css, font-awesome, the palette and the black and white themes as they are), in a headless Chrome
// with a throwaway profile. The office's own tile was never pictured by a tool; this is the smallest harness that
// shows a tile inside Unraid's Dashboard. Dev-only.
//
//   node tools/fanboy/dashboard.mjs [--out <folder>] [--only <name>,…] [-v] [--cost [seconds]] [--frames] [--gap dark]
// --frames: no pictures — the tile's turning symbols never change the Dashboard's size (tools/fanboy/frames.mjs's checks:
// each turning part clipped by a box of its own, the page's scrollWidth, scrollHeight and scroll position steady over
// the animation's moments and frames), at 1440 and 390 px, the tile as the last thing on the page.
// --cost: no pictures — what the tile costs the browser's main thread while it stays open (Chrome's own counters over the
// seconds given, 20 by default): without symbols, with the symbols turning, with reduced motion, scrolled out of view.
// Pictures: <out>/<name>-<theme>-<lang>-<width>.png. Fails (exit 1) on a console error, a page error, a PHP word on
// stderr or a horizontal scroll. Exit 3: php, playwright-core or Chrome missing.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const UNRAID = path.join(REPO, 'tests', 'ui', 'unraid');
const require = createRequire(import.meta.url);
const args = process.argv.slice(2);
const opt = { out: path.join(process.env.USO_SHOTS || path.join(os.homedir(), 'uso-shots'), 'fanboy-dashboard'), only: null, v: false };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--out') opt.out = path.resolve(args[++i]);
  else if (a === '--only') opt.only = args[++i].split(',');
  else if (a === '-v') opt.v = true;
  else if (a === '--cost') opt.cost = /^\d+$/.test(args[i + 1] || '') ? Number(args[++i]) : 20;
  else if (a === '--frames') opt.frames = true;
  // --gap dark: Fanboy's dark air between the blades, as a browser that chose it keeps it (fan-art.js reads office.fanboy.gap)
  else if (a === '--gap') opt.gap = args[++i] === 'dark' ? 'dark' : 'light';
  else { console.log(`fanboy dashboard: unknown argument ${a}`); process.exit(2); }
}
try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch (e) { console.log('fanboy dashboard: php not found - not run'); process.exit(3); }
function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try { for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core')); } catch (e) { /* no npx cache */ }
  for (const t of tries.filter(Boolean)) { try { return require(t); } catch (e) { /* the next */ } }
  return null;
}
const pw = findPlaywright();
if (!pw || !pw.chromium) { console.log('fanboy dashboard: playwright-core not found (USO_PLAYWRIGHT=<folder>) - not run'); process.exit(3); }
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p));
if (!chrome) { console.log('fanboy dashboard: no Chrome found (USO_CHROME=<binary>) - not run'); process.exit(3); }

const NOW = Math.floor(Date.now() / 1000);
const demo = (name) => JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'demo', `${name}.json`), 'utf8'));
/** A demo state looked at $at (its time and its control's) */
function lookedAt(state, at) {
  const s = structuredClone(state);
  const d = at - s.time;
  s.time = at;
  if (s.control && typeof s.control.time === 'number') s.control.time += d;
  if (s.control && s.control.alarm && typeof s.control.alarm.since === 'number') s.control.alarm.since += d;
  if (s.control && s.control.missing && typeof s.control.missing.since === 'number') s.control.missing.since += d;
  return s;
}
/** Six zones, one with nine fans: the tile caps both */
function many() {
  const s = lookedAt(demo('fanboy'), NOW - 5);
  s.who = { who: 'fanboy', why: 'steers', zones: 6 };
  const names = ['Plattenkäfig', 'Gehäuse vorn', 'Gehäuse hinten', 'Deckel', 'Netzteilkammer', 'Grafikkarte'];
  s.setup.zones = [];
  s.control.zones = [];
  names.forEach((name, i) => {
    const fans = Array.from({ length: i === 0 ? 9 : 1 }, (x, k) => ({ id: `it8696_a0060607:it87.2608:pwm${k + 1}`, name: i === 0 ? `Käfig ${k + 1}` : name,
      output: k + 1, pct: 35 + i * 4, rpm: 760 + k * 25 + i * 30, held: true, foreign: false }));
    s.setup.zones.push({ id: `z${i}`, name, fans: fans.map((f) => f.id), unit: 'pct', sources: [] });
    s.control.zones.push({ id: `z${i}`, name, unit: 'pct', level: 35 + i * 4, warmest: { id: `disk${i + 1}`, label: `disk${i + 1}`, c: 31 + i, kind: 'disk' }, asleep: 0, fans });
  });
  return s;
}
/**
 * The control's history in RAM (RUN_DIR/fanboy/history.json) for «with history»: round 3's invented three days
 * (tests/ui/demo/post/fanboy.history.json) moved to end at $at, each zone shifted so its last point is what the state
 * shows now (its warmest °C, its level) — a zone without a history of its own borrows the case zone's shape
 */
function history(state, at) {
  const days = demo('post/fanboy.history').state;
  const zones = {};
  const now = Object.fromEntries(((state.control && state.control.zones) || []).map((z) => [z.id, z]));
  for (const z of (state.setup && state.setup.zones) || []) {
    const pts = days.zones[z.id] || days.zones.case;
    const last = pts[pts.length - 1];
    const live = now[z.id] || {};
    const dc = live.warmest && typeof live.warmest.c === 'number' ? live.warmest.c - last[1] : 0;
    const dl = typeof live.level === 'number' && !live.full ? live.level - last[2] : 0;
    const swing = days.zones[z.id] ? 1 : 2.5;          // a borrowed shape: a little livelier, so it isn't the case zone's twice
    zones[z.id] = pts.map((p) => [p[0] - days.now + at, Math.round((live.warmest && typeof live.warmest.c === 'number' ? live.warmest.c + (p[1] - last[1]) * swing : p[1] + dc) * 10) / 10,
      Math.max(0, Math.min(100, p[2] + dl)), p[3]]);
    if (live.full) zones[z.id][zones[z.id].length - 1][2] = 100;          // an alarm here: its last minute at 100 %
  }
  // who led beside the points, as fanboyHistoryPack() writes it (a borrowed shape: none — «warmest spot»)
  const leads = Object.fromEntries(Object.keys(zones).filter((z) => days.zones[z] && days.leads && days.leads[z]).map((z) => [z, days.leads[z]]));
  return { v: 1, board: state.setup.board, every: 60, zones, src: days.src || [], leads };
}
/**
 * 2026-11-20 (his fans on the tile): two zones, four fans of different looks — the disk cage's beige Noctua, the case's black
 * be quiet!, a grey Phanteks on top that the user painted white, a frosted Corsair at the rear that may stand and stands now —
 * and the CPU cooler the BIOS keeps (a Noctua in graphite), each with a name the user gave it ($lang: the names in English)
 */
const EN_NAMES = { Plattenkäfig: 'Disk cage', Gehäuse: 'Case', Vorn: 'Front', Deckel: 'Top', Hinten: 'Rear', 'CPU-Kühler': 'CPU cooler' };
function fans(lang, { plain = false, stand = true } = {}) {
  const s = lookedAt(demo('fanboy'), NOW - 4);
  const id = (n) => `it8696_a0060607:it87.2608:pwm${n}`;
  const name = (n) => (plain ? '' : lang === 'en' ? EN_NAMES[n] || n : n);
  const set = (n, more) => { const f = s.setup.fans.find((x) => x.id === id(n)); Object.assign(f, more); return f; };
  set(4, { model: { id: 'noctua-nf-a12x25-pwm', mm: null, blades: null }, colour: null, name: name('Plattenkäfig') });
  set(5, { model: { id: 'be-quiet-silent-wings-4-140mm-pwm', mm: null, blades: null }, colour: null, name: name('Vorn') });
  set(2, { model: { id: 'phanteks-t30-120', mm: null, blades: null }, colour: null, wheel: 'white', found: 'tach', tachs: [2], name: name('Deckel') });
  set(6, { model: { id: 'corsair-icue-ml120-rgb-elite', mm: null, blades: null }, colour: null, found: 'tach', tachs: [3], role: 'case', place: 'rear', off_ok: true, measured: true, name: name('Hinten') });
  set(1, { name: name('CPU-Kühler') });
  for (const w of (s.control && s.control.watch) || []) if (w.id === id(1)) w.name = name('CPU-Kühler');
  if (plain) for (const f of s.setup.fans) Object.assign(f, { model: null, colour: null, frame: null, wheel: null, style: null });
  const zone = (zid) => s.setup.zones.find((z) => z.id === zid);
  zone('disks').name = name('Plattenkäfig') || 'Plattenkäfig';
  zone('case').name = (lang === 'en' ? 'Case' : 'Gehäuse');
  zone('case').fans = [id(5), id(2), id(6)];
  const cz = s.control.zones.find((z) => z.id === 'case');
  cz.name = zone('case').name;
  s.control.zones.find((z) => z.id === 'disks').name = zone('disks').name;
  cz.fans = [{ ...cz.fans[0], name: name('Vorn') }, { ...cz.fans[1], name: name('Deckel'), rpm: 760 },
    { id: id(6), name: name('Hinten'), output: 6, pct: stand ? 0 : 30, rpm: stand ? 0 : 640, held: true, foreign: false, may_stand: true, stand_since: stand ? NOW - 600 : null, starts_at: null }];
  s.control.zones.find((z) => z.id === 'disks').fans[0].name = name('Plattenkäfig');
  return s;
}
/** The case zone with seven fans of the user's, the fifth («Seite 2») standing though it should turn (the tile shows four) */
function fansMany(lang) {
  const s = fans(lang, { stand: false });
  const cz = s.control.zones.find((z) => z.id === 'case');
  const models = ['arctic-p12-pwm-pst', 'noctua-nf-p12-redux-1700-pwm', 'phanteks-t30-120', 'corsair-af120-elite'];
  for (let n = 7; n <= 10; n++) {
    const id = `it8696_a0060607:it87.2608:pwm${n}`;
    const name = (lang === 'en' ? 'Side ' : 'Seite ') + (n - 6);
    s.setup.fans.push({ ...s.setup.fans[1], id, output: n, tachs: [n], found: 'tach', role: 'case', name, model: { id: models[n - 7], mm: null, blades: null }, colour: null, wheel: null });
    s.setup.zones.find((z) => z.id === 'case').fans.push(id);
    cz.fans.push({ id, name, output: n, pct: 35, rpm: n === 8 ? 0 : 640 + n * 20, held: true, foreign: false });
  }
  return s;
}
const ON = { 'office/staff.json': { hired: { fanboy: 1 } }, 'fanboy/dashboard.json': { v: 1, show: 'speeds' } };
const WITH = { ...ON, 'fanboy/dashboard.json': { v: 1, show: 'history' } };
const looked = (name, at) => lookedAt(demo(name), at);
// name → [data folder files (null: none — the array stopped), RAM files, a zone to unfold, all combinations]
const SCENES = [
  // 2026-11-20: the fans the user set up, each with its symbol and speed (three widths: Unraid's three columns, two, a phone)
  ['fans', (lang) => ({ ...ON, 'fanboy.json': fans(lang) }), {}, null, true, { widths: [1920, 1024, 390] }],
  ['fans-plain', (lang) => ({ ...ON, 'fanboy.json': fans(lang, { plain: true }) }), {}, null, false, { widths: [1920, 1024, 390] }],
  ['fans-history', (lang) => ({ ...WITH, 'fanboy.json': fans(lang) }), { 'fanboy/history.json': history(fans('de'), NOW - 30) }, null, false, { widths: [1920, 1024, 390] }],
  // the same with every zone unfolded: under each curve its fans with their symbols (2026-11-20, the owner: built)
  ['fans-history-open', (lang) => ({ ...WITH, 'fanboy.json': fans(lang) }), { 'fanboy/history.json': history(fans('de'), NOW - 30) }, '*', false, { widths: [1920, 1024, 390], langs: ['de'] }],
  // seven fans in the case, the fifth standing though it should turn: four shown — the alarm among them, in his page's order
  ['fans-many', (lang) => ({ ...ON, 'fanboy.json': fansMany(lang) }), {}, null, false, { widths: [1920, 1024, 390], langs: ['de'] }],
  ['steering', { ...ON, 'fanboy.json': lookedAt(demo('fanboy'), NOW - 4) }, {}, 'case', true],
  ['history', { ...WITH, 'fanboy.json': looked('fanboy', NOW - 4) }, { 'fanboy/history.json': history(looked('fanboy', NOW - 4), NOW - 30) }, null, true],
  // 2026-11-16: the span clicked through to 24 h (the label a button to the next span)
  ['history-24h', { ...WITH, 'fanboy/dashboard.json': { v: 1, show: 'history', hours: 24 }, 'fanboy.json': looked('fanboy', NOW - 4) }, { 'fanboy/history.json': history(looked('fanboy', NOW - 4), NOW - 30) }, null, false],
  ['cpu-history', { ...WITH, 'fanboy.json': looked('fanboy-cpu', NOW - 4) }, { 'fanboy/history.json': history(looked('fanboy-cpu', NOW - 4), NOW - 30) }, 'cpu', false],
  ['alarm-history', { ...WITH, 'fanboy.json': looked('fanboy-alarm', NOW - 4) }, { 'fanboy/history.json': history(looked('fanboy-alarm', NOW - 4), NOW - 30) }, null, false],
  ['alarm', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-alarm'), NOW - 4) }, {}, null, false],
  ['chip-missing', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-missing'), NOW - 4) }, {}, null, false],
  ['other', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-other'), NOW - 4) }, {}, null, false],
  ['before-setup', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-fresh'), NOW - 4) }, {}, null, false],
  ['array-stopped', null, { 'fanboy/dash.json': { v: 1, show: 'speeds' }, 'fanboy/control.json': { phase: 'ended', why: 'array_stopped', who: 'bios', time: NOW - 900, zones: [] } }, null, false],
  ['stale', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-ready'), NOW - 8400) }, {}, null, false],
  ['many', { ...ON, 'fanboy.json': many() }, {}, 'z0', false],
  // 2026-11-17: the marker — the pointer on the CPU zone's curve (a phone: a tap), its line and its words in place of the caption
  ['history-marker', { ...WITH, 'fanboy.json': looked('fanboy-cpu', NOW - 4) }, { 'fanboy/history.json': history(looked('fanboy-cpu', NOW - 4), NOW - 30) }, null, 'marker', { marker: 0.6 }],
].filter((s) => !opt.only || opt.only.includes(s[0]));
const ALL = [];
for (const theme of ['black', 'white']) for (const lang of ['de', 'en']) for (const width of [1440, 390]) ALL.push({ theme, lang, width });
const SOME = [{ theme: 'black', lang: 'de', width: 1440 }, { theme: 'white', lang: 'en', width: 1440 }, { theme: 'black', lang: 'en', width: 390 }, { theme: 'white', lang: 'de', width: 390 }];

// the plugin's layout: src/ beside the web files
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'uso-fanboy-dash-'));
fs.mkdirSync(path.join(tmp, 'plugin', 'src'), { recursive: true });
for (const f of fs.readdirSync(path.join(REPO, 'src'))) if (f.endsWith('.php')) fs.copyFileSync(path.join(REPO, 'src', f), path.join(tmp, 'plugin', 'src', f));
for (const d of ['assets', 'desks', 'lang']) fs.symlinkSync(path.join(REPO, 'public', d), path.join(tmp, 'plugin', d));

/** The tile as the web side draws it for one scene in one language */
function tile(name, files, run, lang) {
  const dir = path.join(tmp, name);
  fs.rmSync(dir, { recursive: true, force: true });
  const write = (root, map) => Object.entries(map || {}).forEach(([p, c]) => {
    fs.mkdirSync(path.dirname(path.join(root, p)), { recursive: true });
    fs.writeFileSync(path.join(root, p), typeof c === 'string' ? c : JSON.stringify(c));
  });
  if (typeof files === 'function') files = files(lang);
  write(path.join(dir, 'data'), files);
  fs.mkdirSync(path.join(dir, 'run', 'fanboy'), { recursive: true });
  write(path.join(dir, 'run'), run);
  const src = path.join(tmp, 'plugin', 'src');
  const code = `define("OFFICE_IN_UNRAID", true); require "${src}/bootstrap.php"; require_once "${src}/page.php"; require_once "${src}/dashboard.php";`
    + ` require_once "${src}/dashboard-fanboy.php"; echo officeFanboyDashTile();`;
  const r = spawnSync('php', ['-r', code], { encoding: 'utf8', env: { PATH: process.env.PATH, OFFICE_DATA_DIR: path.join(dir, files ? 'data' : 'none'),
    OFFICE_RUN_DIR: path.join(dir, 'run'), HTTP_ACCEPT_LANGUAGE: lang, TZ: 'Europe/Zurich' } });
  if (r.status !== 0 || r.stderr.trim()) throw new Error(`php for ${name}/${lang}: ${r.stderr}`);
  if (!r.stdout.startsWith('<tbody id="db-sso-fanboy"')) throw new Error(`no tile for ${name}/${lang}: ${r.stdout.slice(0, 200)}`);
  // what Unraid's own script adds to every tile (DashStats.page addProperties()): the close and the chevron icons
  // his pictures from the repository (the page is a file here, not served by Unraid)
  const pics = pathToFileURL(path.join(REPO, 'public', 'desks', 'fanboy')).href + '/';
  return r.stdout.replaceAll('/plugins/unraid-secretary-office/desks/fanboy/', pics).replace('<tr><td><span class="tile-header">', "<tr><td><i class='fa fa-fw fa-close control tile' title='Close Tile'></i><span class=\"tile-header\">")
    .replace('</span></span></span></td></tr>', "<i class='fa fa-fw fa-chevron-up control openclose' title='Show/Hide Content'></i></span></span></span></td></tr>");
}

/** A neighbour tile in Unraid's own words (invented board), so the picture shows the house style around his */
const NEIGHBOUR = `<tbody title="Motherboard Information"><tr><td><i class='fa fa-fw fa-close control tile'></i><span class='tile-header'><span class='tile-header-left'>`
  + `<i class='fa fa-desktop' style='font-size:32px'></i><div class='section'><h3 class='tile-header-main'>Motherboard</h3><span>41 °C</span><br></div></span>`
  + `<span class='tile-header-right'><span class='tile-header-right-controls'><a href='#'><i class='fa fa-fw fa-info-circle control'></i></a>`
  + `<i class='fa fa-fw fa-chevron-up control openclose'></i></span></span></span></td></tr><tr><td>Example Boards X570 Home<br>BIOS 1.20<br>2026-03-02</td></tr></tbody>`;

function page(theme, tileHtml) {
  const read = (f) => fs.readFileSync(path.join(UNRAID, f), 'utf8');
  const url = (f) => pathToFileURL(path.join(UNRAID, f)).href;
  const font = (family, weight, style, file) => `@font-face{font-family:${family};font-weight:${weight};font-style:${style};src:url('${url(file)}') format('woff')}`;
  const css = [
    font('clear-sans', 'normal', 'normal', 'clear-sans.woff'), font('clear-sans', 'bold', 'normal', 'clear-sans-bold.woff'),
    font('clear-sans', 'normal', 'italic', 'clear-sans-italic.woff'),
    read('font-awesome.css').replace(/url\('\/webGui\/styles\/font-awesome\.woff[^']*'\)/g, `url('${url('font-awesome.woff')}')`),
    read('default-color-palette.css'), read(`${theme}.css`), read('default-base.css').replace(/url\([^)]*notice\.png\)/g, 'none'), read('DashStats.css'),
    'html{font-family:clear-sans,sans-serif;font-size:62.5%}',
    'body{font-size:1.3rem;color:var(--text-color);background-color:var(--background-color);margin:0;padding:1rem 1rem 3rem}',
    'div.tile{display:block}',              // Unraid's script shows the tiles that have content
  ].join('\n');
  const gap = "<thead class='stopgap'><tr><td class='stopgap'></td></tr></thead>";
  return `<!doctype html><html class="Theme--${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head>`
    + `<body><div class='frame'><div class='grid'><div class='tile' id='tile1'><table id='db_box1' class='dashboard'>${gap}${NEIGHBOUR}</table></div>`
    + `<div class='tile' id='tile2'><table id='db_box2' class='dashboard'>${gap}${tileHtml}</table></div></div></div></body></html>`;
}

/**
 * The cost of the tile left open: Chrome's counters (CDP Performance.getMetrics: the main thread's task, script, style and
 * layout time) over $secs, after a settling second; plus the animations the page runs. The turning is the compositor's
 * (transform only): what the main thread pays shows here, the GPU's share does not.
 */
async function cost(browser, secs) {
  const runs = [['without symbols', 'fans-plain', {}], ['symbols turning', 'fans', {}], ['symbols, reduced motion', 'fans', { reducedMotion: 'reduce' }],
    ['symbols scrolled out of view', 'fans', { away: true }], ['symbols, tab hidden', 'fans', { hidden: true }]];
  const rows = [];
  for (const [label, name, how] of runs) {
    const scene = SCENES.find((x) => x[0] === name) || [name, name === 'fans' ? (l) => ({ ...ON, 'fanboy.json': fans(l) }) : (l) => ({ ...ON, 'fanboy.json': fans(l, { plain: true }) }), {}];
    const file = path.join(tmp, `cost-${name}.html`);
    fs.writeFileSync(file, page('black', tile(name, scene[1], scene[2], 'de')).replace('</body>', `<div style="height:${how.away ? 4000 : 0}px"></div></body>`));
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1000 }, reducedMotion: how.reducedMotion || 'no-preference' });
    const p = await ctx.newPage();
    const cdp = await ctx.newCDPSession(p);
    await cdp.send('Performance.enable');
    await p.goto(pathToFileURL(file).href);
    await p.waitForFunction(() => !document.querySelector('#db-sso-fanboy .sso-fb-sym[data-fan]') || !!document.querySelector('#db-sso-fanboy .fan-wheel'));
    if (how.away) await p.evaluate(() => window.scrollTo(0, 3000));
    if (how.hidden) await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: false }).then(() => p.evaluate(() => { Object.defineProperty(document, 'hidden', { get: () => true }); document.dispatchEvent(new Event('visibilitychange')); }));
    await p.waitForTimeout(1500);
    const m = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((x) => [x.name, x.value]));
    const a = await m();
    await p.waitForTimeout(secs * 1000);
    const b = await m();
    const anim = await p.evaluate(() => { const l = document.getAnimations(); return [l.filter((x) => x.playState === 'running').length, l.length]; });
    const ms = (k) => Math.round((b[k] - a[k]) * 1000 / secs * 10) / 10;
    rows.push(`${label.padEnd(30)} task ${String(ms('TaskDuration')).padStart(5)} ms/s · script ${String(ms('ScriptDuration')).padStart(4)} · style ${String(ms('RecalcStyleDuration')).padStart(4)} · layout ${String(ms('LayoutDuration')).padStart(4)} · animations running ${anim[0]} of ${anim[1]}`);
    await ctx.close();
  }
  console.log(`fanboy dashboard: the tile's cost over ${secs} s (headless Chrome, 1920 px, black, de)\n  ${rows.join('\n  ')}`);
}

fs.mkdirSync(opt.out, { recursive: true });
const browser = await pw.chromium.launch({ executablePath: chrome, headless: true, args: ['--no-first-run', '--allow-file-access-from-files'] });
const failed = [];
let taken = 0;
if (opt.cost) {
  try { await cost(browser, opt.cost); } finally { await browser.close(); fs.rmSync(tmp, { recursive: true, force: true }); }
  process.exit(0);
}
if (opt.frames) {
  const { sizeChecks, sizeVerdicts } = await import(path.join(HERE, 'frames.mjs'));
  const bad = [];
  try {
    for (const width of [1440, 390]) {
      const scene = SCENES.find((x) => x[0] === 'fans');
      const file = path.join(tmp, `frames-${width}.html`);
      fs.writeFileSync(file, page('black', tile('fans', scene[1], scene[2], 'de')));
      const ctx = await browser.newContext({ viewport: { width, height: 700 }, hasTouch: width < 768 });
      const p = await ctx.newPage();
      await p.goto(pathToFileURL(file).href);
      await p.waitForFunction(() => document.querySelectorAll('#db-sso-fanboy .sso-fb-sym .fan-wheel').length > 0);
      await p.waitForTimeout(1200);
      await p.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
      for (const [what, ok, detail] of sizeVerdicts(`tile ${width}`, await sizeChecks(p))) if (!ok) bad.push(`${what} — ${detail}`); else if (opt.v) console.log(`ok  ${what}`);
      await ctx.close();
    }
  } finally { await browser.close(); fs.rmSync(tmp, { recursive: true, force: true }); }
  if (bad.length) { console.log(`fanboy dashboard frames: ${bad.length} failed`); bad.forEach((b) => console.log(`  ✗ ${b}`)); process.exit(1); }
  console.log('fanboy dashboard frames: all checks passed');
  process.exit(0);
}
try {
  for (const [name, files, run, open, every, how] of SCENES) {
    const tiles = { de: tile(name, files, run, 'de'), en: tile(name, files, run, 'en') };
    const combos = every === 'marker' ? [{ theme: 'black', lang: 'de', width: 1440 }, { theme: 'white', lang: 'en', width: 390 }] : every || (how && how.langs) ? ALL : SOME;
    const widths = how && how.widths;
    for (const c of (widths ? combos.filter((x) => x.width === 1440).flatMap((x) => widths.map((width) => ({ ...x, width }))) : combos).filter((x) => !(how && how.langs) || how.langs.includes(x.lang))) {
      const file = path.join(tmp, `${name}-${c.theme}-${c.lang}.html`);
      fs.writeFileSync(file, page(c.theme, tiles[c.lang]));
      const ctx = await browser.newContext({ viewport: { width: c.width, height: how && how.marker ? 1400 : 700 }, deviceScaleFactor: 2, locale: c.lang, hasTouch: c.width < 768 });
      if (opt.gap === 'dark') await ctx.addInitScript(() => { try { localStorage.setItem('office.fanboy.gap', 'dark'); } catch (e) { /* none */ } });
      const p = await ctx.newPage();
      const errors = [];
      p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      p.on('pageerror', (e) => errors.push(String(e)));
      await p.goto(pathToFileURL(file).href);
      await p.evaluate(() => document.fonts.ready);
      // the symbols drawn (fan-art.js loads after the tile)
      await p.waitForFunction(() => !document.querySelector('#db-sso-fanboy .sso-fb-sym[data-fan]') || document.querySelectorAll('#db-sso-fanboy .sso-fb-sym[data-fan]').length === document.querySelectorAll('#db-sso-fanboy .sso-fb-sym .fan-wheel').length);
      if (open) await p.evaluate((z) => { for (const d of document.querySelectorAll(z === '*' ? '#db-sso-fanboy details[data-zone]' : `#db-sso-fanboy details[data-zone="${z}"]`)) d.open = true; }, open);
      if (open) await p.waitForTimeout(200);
      const wide = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      const box = await p.evaluate(() => { const r = document.querySelector('#db-sso-fanboy').getBoundingClientRect(); return { h: r.height, w: r.width }; });
      const out = path.join(opt.out, `${name}-${c.theme}-${c.lang}-${c.width}.png`);
      if (how && how.marker) {
        // the pointer (a phone: a tap) at a share of the first curve: the readout equals the span's words from the server
        // with its times; the caption hidden meanwhile; away from the curve the caption back
        const curve = p.locator('#db-sso-fanboy .sso-fb-curve').last();
        const b = await curve.boundingBox();
        if (c.width < 768) await p.touchscreen.tap(b.x + b.width * how.marker, b.y + b.height / 2);
        else await p.mouse.move(b.x + b.width * how.marker, b.y + b.height / 2);
        await p.waitForTimeout(200);
        const got = await p.evaluate((share) => {
          const g = [...document.querySelectorAll('#db-sso-fanboy .sso-fb-graph')].pop();
          const m = JSON.parse(g.getAttribute('data-m'));
          const i = Math.round(share * (m.length - 1));
          return { on: g.classList.contains('sso-fb-on'), read: g.querySelector('.sso-fb-read').textContent, want: m[i], caption: getComputedStyle(g.querySelector('.sso-fb-span')).display,
            line: g.querySelector('.sso-fb-mark').getAttribute('x1'), x: (i * 120 / (m.length - 1)).toFixed(1) };
        }, how.marker);
        if (!got.on || !got.read.endsWith(` · ${got.want === null ? '–' : got.want}`) || got.caption !== 'none' || got.line !== got.x) failed.push(`${path.basename(out)}: the marker — ${JSON.stringify(got)}`);
        if (opt.v) console.log(`marker: ${got.read}`);
        await p.screenshot({ path: out, fullPage: false, clip: await p.locator('#db-sso-fanboy').boundingBox().then((r) => ({ x: 0, y: 0, width: c.width, height: Math.ceil(r.y + r.height + 16) })) });
        if (c.width >= 768) {
          await p.mouse.move(2, 2);
          await p.waitForTimeout(150);
          const back = await p.evaluate(() => { const g = [...document.querySelectorAll('#db-sso-fanboy .sso-fb-graph')].pop(); return !g.classList.contains('sso-fb-on') && getComputedStyle(g.querySelector('.sso-fb-span')).display !== 'none'; });
          if (!back) failed.push(`${path.basename(out)}: the pointer gone — the marker stays`);
        }
      } else await p.screenshot({ path: out, fullPage: true });
      taken++;
      if (opt.v) console.log(`${path.basename(out)}  tile ${Math.round(box.w)}×${Math.round(box.h)}`);
      if (errors.length || wide) failed.push(`${path.basename(out)}: ${wide ? 'horizontal scroll ' : ''}${errors.join(' | ')}`);
      await ctx.close();
    }
  }
  // contrast of his coloured lines (alarm, warnings, who steers, the links) on the tile in all four of Unraid's themes —
  // at least 4.5:1 (WCAG AA), measured as Chrome draws it: the text's colour against the tile's background
  const contrast = [];
  for (const [name, files, run] of SCENES) {
    const html = tile(name, files, run, 'en');
    for (const theme of ['black', 'gray', 'white', 'azure']) {
      const file = path.join(tmp, `${name}-${theme}-contrast.html`);
      fs.writeFileSync(file, page(theme, html));
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 700 } });
      const p = await ctx.newPage();
      await p.goto(pathToFileURL(file).href);
      const got = await p.evaluate(() => {
        const rgb = (c) => { const n = (c.match(/[\d.]+/g) || []).map(Number); return c.startsWith('color(srgb') ? n.map((v, i) => (i < 3 ? v * 255 : v)) : n; };   // Chrome writes a color-mix() as color(srgb 0…1)
        const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
          .reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
        const bg = (el) => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0)) return c.slice(0, 3); } return [255, 255, 255]; };
        return [...document.querySelectorAll('#db-sso-fanboy .red-text, #db-sso-fanboy .orange-text, #db-sso-fanboy .green-text, #db-sso-fanboy .sso-fb-dash a:not(.red-text):not(.orange-text)')].map((e) => {
          const [a, b] = [lum(rgb(getComputedStyle(e).color).slice(0, 3)), lum(bg(e))];
          return [e.className || 'a', getComputedStyle(e).color, Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100];
        });
      });
      const lines = await p.evaluate(() => {
        const rgb = (c) => { const n = (c.match(/[\d.]+/g) || []).map(Number); return c.startsWith('color(srgb') ? n.map((v, i) => (i < 3 ? v * 255 : v)) : n; };   // Chrome writes a color-mix() as color(srgb 0…1)
        const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
          .reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
        const bg = (el) => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0)) return c.slice(0, 3); } return [255, 255, 255]; };
        return [...document.querySelectorAll('#db-sso-fanboy .sso-fb-temp')].slice(0, 1).map((e) => {
          const [a, b] = [lum(rgb(getComputedStyle(e).stroke).slice(0, 3)), lum(bg(e.closest('details')))];
          return ['sso-fb-temp (line)', getComputedStyle(e).stroke, Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100];
        });
      });
      for (const [cls, colour, ratio] of lines) {
        const line = `${name} ${theme} ${cls} ${colour} ${ratio}:1`;
        if (!contrast.includes(line)) contrast.push(line);
        if (ratio < 3) failed.push(`a line below 3:1 — ${line}`);
      }
      for (const [cls, colour, ratio] of got) {
        const line = `${name} ${theme} ${cls} ${colour} ${ratio}:1`;
        if (!contrast.includes(line)) contrast.push(line);
        if (ratio < 4.5) failed.push(`contrast below 4.5:1 — ${line}`);
      }
      await ctx.close();
    }
  }
  fs.writeFileSync(path.join(opt.out, 'contrast.txt'), contrast.join('\n') + '\n');

  // the setting on his page (the office's harness, tests/ui/harness.mjs): «Show on the Dashboard» in the setup's settings
  if (!opt.only || opt.only.includes('setting')) {
    const { startHarness, DEMO_NAMES_EN } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
    for (const c of [{ theme: 'black', lang: 'de', width: 1440 }, { theme: 'white', lang: 'en', width: 390 }]) {
      const h = await startHarness({ demo: true, alias: { fanboy: 'fanboy' }, names: c.lang === 'de' ? null : DEMO_NAMES_EN });
      const ctx = await browser.newContext({ viewport: { width: c.width, height: 900 }, deviceScaleFactor: 2, isMobile: c.width < 768, hasTouch: c.width < 768,
        colorScheme: c.theme === 'white' ? 'light' : 'dark' });
      await ctx.addInitScript((l) => { try { localStorage.setItem('office.lang', l); localStorage.removeItem('office.theme'); } catch (e) { /* none */ } }, c.lang);
      const p = await ctx.newPage();
      const errors = [];
      p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      p.on('pageerror', (e) => errors.push(String(e)));
      await p.goto(`${h.url}?theme=${c.theme}#/fanboy/setup/settings`, { waitUntil: 'load' });
      await p.waitForSelector('#sso .fb-dash-choice', { timeout: 15000 });
      await p.evaluate(async () => { await document.fonts.ready; });
      const el = await p.$('#sso .fb-dash-choice');
      const out = path.join(opt.out, `setting-${c.theme}-${c.lang}-${c.width}.png`);
      await el.screenshot({ path: out });
      taken++;
      const wide = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      if (errors.length || wide) failed.push(`${path.basename(out)}: ${wide ? 'horizontal scroll ' : ''}${errors.join(' | ')}`);
      await ctx.close();
      if (h.close) await h.close();
    }
  }
} catch (e) {
  failed.push(String(e && e.stack || e));
} finally {
  await browser.close();
  fs.rmSync(tmp, { recursive: true, force: true });
}
console.log(`fanboy dashboard: ${taken} pictures in ${opt.out}${failed.length ? `, ${failed.length} failed:\n  ${failed.join('\n  ')}` : ''}`);
process.exit(failed.length ? 1 : 0);
