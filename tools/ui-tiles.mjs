#!/usr/bin/env node
// What a tile click shows (tiles v2, CLAUDE.md «Tiles»): every tile of a desk page, built from this repository's files
// with the invented family data of tests/ui/demo (tests/ui/harness.mjs), in a headless Chrome with a throwaway profile -
// dark (Unraid's black theme), German, at 1440 and 390 px. For every tile (button.card, button.stat, anything with
// aria-pressed and data-filters) a fresh page: the tile brought into view as a user would scroll to it, a VIEWPORT
// screenshot before the click and one after it, and what is in view then (the section heads, the note line, a dialog,
// the switcher's body). Also the whole page once per desk and width (as it stands without a click). This is how a desk
// shows its tiles work - not only a green click test: the chosen section in the window after the click.
//
//   node tools/ui-tiles.mjs [--only restore,cleanup/where] [--widths 1440,390] [--lang de] [--theme black] [--jobs 4]
//                           [--out <folder>] [-v]
//   default folder: $USO_SHOTS/<yyyy-mm-dd>-tiles/, without USO_SHOTS ~/uso-shots/<yyyy-mm-dd>-tiles/
//   names: <route>-all-<width>.png (the whole page), <route>-<tile>-before|after-<width>.png (the route's / as -; the
//          tile's name: its switcher id, else its place without tile./stat., else kind and label); log.txt (one line per
//          tile and width), log.json
//
// Needs node and playwright-core (USO_PLAYWRIGHT=<its folder>, else found in node's own paths or npx's cache) and a
// Chrome (USO_CHROME=<binary>, else Google Chrome in /Applications, else playwright's own Chromium) - like the click
// test (tools/ui-clicks.mjs). Exit: 0 every tile shot, 1 a tile failed (not there again, could not be clicked, a console
// error), 2 wrong call, 3 playwright-core or Chrome missing.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..');
const require = createRequire(import.meta.url);

// ------------------------------------------------------------------ the call
// the desk pages with tiles (the Consultant has none)
const ROUTES = ['caretaker', 'backup', 'emby', 'emby/setup', 'snapshot', 'restore', 'logs', 'cleanup/where', 'cleanup/tidy', 'watchman', 'moverelli'];
const LOCALES = { de: 'de-CH', en: 'en-US', fr: 'fr-CH', it: 'it-CH', es: 'es-ES' };
const today = new Date();
const day = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
const opt = { routes: ROUTES, widths: [1440, 390], lang: 'de', theme: 'black', jobs: 4, verbose: false,
  out: path.join(process.env.USO_SHOTS || path.join(os.homedir(), 'uso-shots'), `${day}-tiles`) };
const args = process.argv.slice(2);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  const val = () => { if (i + 1 >= args.length) usage(); return args[++i]; };
  const list = () => val().split(',').map((x) => x.trim()).filter(Boolean);
  if (a === '--only' || a === '--routes') opt.routes = list().map((r) => r.replace(/^#?\/?/, ''));
  else if (a === '--widths') opt.widths = list().map(Number).filter((n) => n >= 320);
  else if (a === '--lang') opt.lang = val();
  else if (a === '--theme') opt.theme = val();
  else if (a === '--jobs') opt.jobs = Math.max(1, Number(val()) || 1);
  else if (a === '--out') opt.out = path.resolve(val());
  else if (a === '-v' || a === '--verbose') opt.verbose = true;
  else if (a === '-h' || a === '--help') usage(0);
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/ui-tiles.mjs [--only <route>,…] [--widths 1440,390] [--lang de] [--theme black|white] [--jobs n] [--out <folder>] [-v]');
  console.error('routes: ' + ROUTES.join(' '));
  process.exit(code);
}
if (!opt.routes.length || !opt.widths.length || !LOCALES[opt.lang] || !['black', 'white'].includes(opt.theme)) usage();
for (const r of opt.routes) {
  if (!/^[a-z][a-z0-9_-]*(\/[A-Za-z0-9:._-]+)*$/.test(r) || !fs.existsSync(path.join(REPO, 'public', 'desks', r.split('/')[0], 'desk.js'))) {
    console.error(`ui tiles: no desk page «${r}»`);
    usage();
  }
}

// ------------------------------------------------------------------ playwright-core and a Chrome
function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try {
    for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core'));
  } catch (e) { /* no npx cache */ }
  for (const t of tries.filter(Boolean)) {
    try { return require(t); } catch (e) { /* the next */ }
  }
  return null;
}
const pw = findPlaywright();
if (!pw || !pw.chromium) {
  console.log('ui tiles: playwright-core not found (USO_PLAYWRIGHT=<folder>, or `npx playwright-core --version` once) - not run');
  process.exit(3);
}
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p)) || (() => { try { const p = pw.chromium.executablePath(); return p && fs.existsSync(p) ? p : null; } catch (e) { return null; } })();
if (!chrome) {
  console.log('ui tiles: no Chrome found (USO_CHROME=<binary>) - not run');
  process.exit(3);
}
const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
fs.mkdirSync(opt.out, { recursive: true });

const slug = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 28);

// ------------------------------------------------------------------ in the page
const PAGE_HELPERS = () => {
  const SEL = '#sso-desk button.card, #sso-desk button.stat, #sso-desk [aria-pressed][data-filters]';
  const shown = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !e.closest('[hidden]'); };
  const menuBottom = () => { const m = document.getElementById('menu'); return m && /^(sticky|fixed)$/.test(getComputedStyle(m).position) ? m.getBoundingClientRect().bottom : 0; };
  const inView = (e) => { const r = e.getBoundingClientRect(); return r.bottom > menuBottom() && r.top < innerHeight && r.width > 0 && r.height > 0; };
  const text = (e) => e.textContent.trim().replace(/\s+/g, ' ');
  const tiles = () => { const seen = new Set(); return [...document.querySelectorAll(SEL)].filter((b) => !seen.has(b) && shown(b) && seen.add(b)); };
  window.__ts = {
    tiles: () => tiles().map((b) => ({
      kind: b.classList.contains('card') ? 'card' : b.classList.contains('stat') ? 'stat' : 'other',
      label: text(b.querySelector('.card-name, .stat-label') || b).slice(0, 60), tile: b.dataset.tile || '',
      place: b.dataset.place || '', parent: b.parentElement ? String(b.parentElement.className) : '', pressed: b.getAttribute('aria-pressed'),
    })),
    mark(i) {
      document.querySelectorAll('[data-ts]').forEach((x) => x.removeAttribute('data-ts'));
      const b = tiles()[i];
      if (!b) return false;
      b.setAttribute('data-ts', '1');
      return true;
    },
    // the user scrolls until the tile is in view (to the middle of the window when it was out of view)
    scrollTo() {
      const b = document.querySelector('[data-ts]');
      const r = b.getBoundingClientRect();
      if (r.top < menuBottom() || r.bottom > innerHeight) window.scrollTo(0, Math.max(0, scrollY + r.top - innerHeight / 2 + r.height / 2));
    },
    info() {
      const heads = [...document.querySelectorAll('#sso-desk .section-head')].filter(shown);
      const t = document.querySelector('[data-ts]');
      const grid = t ? t.parentElement : null;
      const body = grid && grid.parentElement ? [...grid.parentElement.children].find((x) => x.matches('[data-sections-body]')) : null;
      const tr = t ? t.getBoundingClientRect() : null;
      return {
        scrollY: Math.round(scrollY), pageH: document.documentElement.scrollHeight, winH: innerHeight,
        heads: heads.map((h) => text(h.querySelector('h2') || h).slice(0, 50)),
        headsInView: heads.filter(inView).map((h) => text(h.querySelector('h2') || h).slice(0, 50)),
        notes: [...document.querySelectorAll('#sso-desk .filter-note')].filter(shown).map((n) => (inView(n) ? '' : '(below) ') + text(n).slice(0, 100)),
        dialog: !!(window.Office && Office.dialogOpen && Office.dialogOpen()),
        tile: tr ? { top: Math.round(tr.top), bottom: Math.round(tr.bottom), pressed: t.getAttribute('aria-pressed'), glow: t.classList.contains('tile-on'),
          mark: (t.querySelector('.tile-mark') || { textContent: '' }).textContent } : null,
        body: body ? { active: body.dataset.active || '', sections: body.dataset.sections || '' } : null,
      };
    },
  };
};

// ------------------------------------------------------------------ one desk page at one width
async function newPage(browser, width) {
  const phone = width < 768;
  const ctx = await browser.newContext({ viewport: { width, height: phone ? 844 : 900 }, deviceScaleFactor: phone ? 2 : 1, isMobile: phone, hasTouch: phone,
    locale: LOCALES[opt.lang], colorScheme: opt.theme === 'white' ? 'light' : 'dark' });
  await ctx.addInitScript((lang) => { try { localStorage.setItem('office.lang', lang); localStorage.removeItem('office.theme'); } catch (e) { /* none */ } }, opt.lang);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  return { ctx, page, errors };
}

async function open(page, harness, route) {
  await page.goto(`${harness.url}?theme=${opt.theme}#/${route}`, { waitUntil: 'load' });
  await page.waitForFunction(() => window.Office && Office.current && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), null, { timeout: 15000 });
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all(['14px clear-sans', 'bold 14px clear-sans'].map((f) => document.fonts.load(f).catch(() => null)));
  });
  await page.evaluate(PAGE_HELPERS);
  // the state (two parts for cleanup) arrives after the first paint: wait until the tiles are still for 600 ms
  let last = -1; let still = 0;
  for (let i = 0; i < 20 && still < 2; i++) {
    await page.waitForTimeout(300);
    const n = await page.evaluate(() => __ts.tiles().length);
    if (n === last) still++; else { still = 0; last = n; }
  }
  await page.waitForTimeout(400);
}

async function shootRoute(browser, harness, route, width) {
  const name = route.replace(/\//g, '-');
  const log = [];
  const shot = (page, file) => page.screenshot({ path: path.join(opt.out, file), animations: 'disabled', caret: 'hide' });
  let tiles = [];
  {
    const { ctx, page, errors } = await newPage(browser, width);
    try {
      await open(page, harness, route);
      const info = await page.evaluate(() => __ts.info());
      await page.screenshot({ path: path.join(opt.out, `${name}-all-${width}.png`), fullPage: true, animations: 'disabled', caret: 'hide' });
      tiles = await page.evaluate(() => __ts.tiles());
      log.push({ route, width, tile: '(all)', pageH: info.pageH, winH: info.winH, heads: info.heads, tiles: tiles.length, errors });
    } catch (e) {
      log.push({ route, width, tile: '(all)', fail: String(e.message).split('\n')[0].slice(0, 160), errors });
    }
    await ctx.close().catch(() => {});
  }
  // a name per tile: its switcher id, its place, else kind and label; doubles numbered
  const used = new Set();
  const names = tiles.map((x) => {
    let base = x.tile ? slug(x.tile) : x.place ? slug(x.place.replace(/^(tile|stat)\./, '')) : `${x.kind}-${slug(x.label)}`;
    if (!x.tile && /clw-places/.test(x.parent)) base = `place-${slug(x.label)}`;
    else if (!x.tile && /clw-sections/.test(x.parent)) base = `corner-${slug(x.label)}`;
    let n = base; let k = 2;
    while (used.has(n)) n = `${base}-${k++}`;
    used.add(n);
    return n;
  });
  for (let i = 0; i < tiles.length; i++) {
    const x = tiles[i];
    const { ctx, page, errors } = await newPage(browser, width);
    try {
      await open(page, harness, route);
      if (!(await page.evaluate((k) => __ts.mark(k), i))) throw new Error('tile not there on the second load');
      await page.evaluate(() => __ts.scrollTo());
      await page.waitForTimeout(250);
      const before = await page.evaluate(() => __ts.info());
      await shot(page, `${name}-${names[i]}-before-${width}.png`);
      await page.click('[data-ts]', { timeout: 3000 });
      await page.waitForTimeout(700);
      await page.evaluate((k) => __ts.mark(k), i);          // drawn anew by the desk: marked again (the order stays)
      const after = await page.evaluate(() => __ts.info());
      await shot(page, `${name}-${names[i]}-after-${width}.png`);
      log.push({ route, width, tile: names[i], label: x.label, kind: x.kind, place: x.place, before, after, errors });
    } catch (e) {
      log.push({ route, width, tile: names[i], label: x.label, fail: String(e.message).split('\n')[0].slice(0, 160), errors });
    }
    await ctx.close().catch(() => {});
  }
  return log;
}

// ------------------------------------------------------------------ all of them
const t0 = Date.now();
const harness = await startHarness({ demo: true });
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check', '--font-render-hinting=none'] });
const jobs = [];
for (const route of opt.routes) for (const width of opt.widths) jobs.push({ route, width });
const logs = [];
let next = 0;
await Promise.all(Array.from({ length: Math.min(opt.jobs, jobs.length) }, async () => {
  while (next < jobs.length) {
    const j = jobs[next++];
    const l = await shootRoute(browser, harness, j.route, j.width);
    logs.push(...l);
    if (opt.verbose) console.log(`  ${j.route} ${j.width}: ${l.length - 1} tiles`);
  }
}));
await browser.close();
await harness.close();

logs.sort((a, b) => opt.routes.indexOf(a.route) - opt.routes.indexOf(b.route) || b.width - a.width || (a.tile === '(all)' ? -1 : b.tile === '(all)' ? 1 : a.tile.localeCompare(b.tile)));
fs.writeFileSync(path.join(opt.out, 'log.json'), JSON.stringify(logs, null, 1));
// one line per tile and width: what the click did in the window
const lines = ['route | width | tile | label | pressed before→after (glow, mark) | scrollY before→after | page height before→after | heads in view before | heads in view after | switcher body after | note line | dialog'];
let failed = 0;
for (const l of logs) {
  const errs = (l.errors || []).length ? ` | console: ${[...new Set(l.errors)].slice(0, 2).join(' ; ')}` : '';
  if ((l.errors || []).length) failed++;
  if (l.fail) { failed++; lines.push(`${l.route} | ${l.width} | ${l.tile} | FAIL ${l.fail}${errs}`); continue; }
  if (l.tile === '(all)') { lines.push(`${l.route} | ${l.width} | (all) | page ${l.pageH} px in a ${l.winH} px window | ${l.tiles} tiles | heads: ${l.heads.join(' / ')}${errs}`); continue; }
  const b = l.before; const a = l.after;
  const mark = a.tile ? `${a.tile.glow ? ', glow' : ''}${a.tile.mark ? `, «${a.tile.mark}»` : ''}` : '';
  lines.push([l.route, l.width, l.tile, l.label, `${b.tile && b.tile.pressed}→${a.tile && a.tile.pressed}${mark}`, `${b.scrollY}→${a.scrollY}`, `${b.pageH}→${a.pageH}`,
    b.headsInView.join(' / '), a.headsInView.join(' / '), a.body ? `${a.body.active || '(nothing)'}: ${a.body.sections}` : '-', a.notes.join(' ; '), a.dialog ? 'DIALOG' : ''].join(' | ') + errs);
}
fs.writeFileSync(path.join(opt.out, 'log.txt'), lines.join('\n') + '\n');
const shots = logs.filter((l) => l.tile !== '(all)' && !l.fail).length;
console.log(`ui tiles: ${opt.routes.length} routes × ${opt.widths.length} widths, ${shots} tiles shot before/after, ${failed} failed - ${((Date.now() - t0) / 1000).toFixed(0)} s → ${opt.out}`);
process.exit(failed ? 1 : 0);
