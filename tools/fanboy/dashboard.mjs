#!/usr/bin/env node
// Fanboy's own tile on Unraid's Dashboard (src/dashboard-fanboy.php) as pictures: the tile as the web side draws it
// (php on this Mac, a plugin's layout in a temporary folder, the invented demo states of tests/ui/demo/fanboy*.json),
// placed in Unraid's own Dashboard markup (div.frame > div.grid > div.tile > table.dashboard, a neighbour tile, the
// chevron and close icons Unraid's script adds) with Unraid 7.4's own style sheets (tests/ui/unraid/: default-base.css,
// sheets/DashStats.css, font-awesome, the palette and the black and white themes as they are), in a headless Chrome
// with a throwaway profile. The office's own tile was never pictured by a tool; this is the smallest harness that
// shows a tile inside Unraid's Dashboard. Dev-only.
//
//   node tools/fanboy/dashboard.mjs [--out <folder>] [--only <name>,…] [-v]
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
const ON = { 'fanboy/show.json': '{}', 'office/staff.json': { hired: { fanboy: 1 } }, 'fanboy/dashboard.json': { v: 1, show: 'speeds' } };
// name → [data folder files (null: none — the array stopped), RAM files, a zone to unfold, all combinations]
const SCENES = [
  ['steering', { ...ON, 'fanboy.json': lookedAt(demo('fanboy'), NOW - 4) }, {}, 'case', true],
  ['alarm', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-alarm'), NOW - 4) }, {}, null, false],
  ['chip-missing', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-missing'), NOW - 4) }, {}, null, false],
  ['other', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-other'), NOW - 4) }, {}, null, false],
  ['before-setup', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-fresh'), NOW - 4) }, {}, null, false],
  ['array-stopped', null, { 'fanboy/dash.json': { v: 1, show: 'speeds' }, 'fanboy/control.json': { phase: 'ended', why: 'array_stopped', who: 'bios', time: NOW - 900, zones: [] } }, null, false],
  ['stale', { ...ON, 'fanboy.json': lookedAt(demo('fanboy-ready'), NOW - 8400) }, {}, null, false],
  ['many', { ...ON, 'fanboy.json': many() }, {}, 'z0', false],
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
  return r.stdout.replace('<tr><td><span class="tile-header">', "<tr><td><i class='fa fa-fw fa-close control tile' title='Close Tile'></i><span class=\"tile-header\">")
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

fs.mkdirSync(opt.out, { recursive: true });
const browser = await pw.chromium.launch({ executablePath: chrome, headless: true, args: ['--no-first-run', '--allow-file-access-from-files'] });
const failed = [];
let taken = 0;
try {
  for (const [name, files, run, open, every] of SCENES) {
    const tiles = { de: tile(name, files, run, 'de'), en: tile(name, files, run, 'en') };
    for (const c of every ? ALL : SOME) {
      const file = path.join(tmp, `${name}-${c.theme}-${c.lang}.html`);
      fs.writeFileSync(file, page(c.theme, tiles[c.lang]));
      const ctx = await browser.newContext({ viewport: { width: c.width, height: 700 }, deviceScaleFactor: 2, locale: c.lang });
      const p = await ctx.newPage();
      const errors = [];
      p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      p.on('pageerror', (e) => errors.push(String(e)));
      await p.goto(pathToFileURL(file).href);
      await p.evaluate(() => document.fonts.ready);
      if (open) await p.evaluate((z) => { const d = document.querySelector(`#db-sso-fanboy details[data-zone="${z}"]`); if (d) d.open = true; }, open);
      const wide = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      const box = await p.evaluate(() => { const r = document.querySelector('#db-sso-fanboy').getBoundingClientRect(); return { h: r.height, w: r.width }; });
      const out = path.join(opt.out, `${name}-${c.theme}-${c.lang}-${c.width}.png`);
      await p.screenshot({ path: out, fullPage: true });
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
        const rgb = (c) => (c.match(/[\d.]+/g) || []).map(Number);
        const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
          .reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
        const bg = (el) => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0)) return c.slice(0, 3); } return [255, 255, 255]; };
        return [...document.querySelectorAll('#db-sso-fanboy .red-text, #db-sso-fanboy .orange-text, #db-sso-fanboy .green-text, #db-sso-fanboy .sso-fb-dash a:not(.red-text):not(.orange-text)')].map((e) => {
          const [a, b] = [lum(rgb(getComputedStyle(e).color).slice(0, 3)), lum(bg(e))];
          return [e.className || 'a', getComputedStyle(e).color, Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100];
        });
      });
      for (const [cls, colour, ratio] of got) {
        const line = `${name} ${theme} ${cls} ${colour} ${ratio}:1`;
        if (!contrast.includes(line)) contrast.push(line);
        if (ratio < 4.5) failed.push(`contrast below 4.5:1 — ${line}`);
      }
      await ctx.close();
    }
  }
  fs.writeFileSync(path.join(opt.out, 'contrast.txt'), contrast.join('\n') + '\n');

  // the setting on his page (the office's harness, tests/ui/harness.mjs): «Show on the Dashboard» under the server picture
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
      await p.goto(`${h.url}?theme=${c.theme}#/fanboy/who`, { waitUntil: 'load' });
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
