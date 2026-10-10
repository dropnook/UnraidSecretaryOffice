#!/usr/bin/env node
// Screenshots for the product page and the forum: desk pages built from this repository's files (tests/ui/harness.mjs)
// with the invented family data of tests/ui/demo - never a real server's - in a headless Chrome with a throwaway profile,
// inside Unraid's look (its palette, black or white theme, fonts and background: the harness), at device scale 2.
// For each route, theme (black, white), language (de, en) and width (1440 desktop, 390 phone) one PNG per shot:
//   full            the whole page
//   until:<css>     from the top of the page down to the end of that element (e.g. the head and the live panel)
//   clip:<css>      that element alone, with a little of the page around it
// Without --shot a route takes its own (SHOTS below), else the whole page. Each shot waits for Unraid's fonts, the
// desk's first paint and the element; a console error or a missing element fails the shot (exit 1, the PNG still
// written when there is one).
//
//   node tools/ui-shots.mjs [--routes emby] [--themes black,white] [--langs de,en] [--widths 1440,390]
//                           [--shot until:.jo-prog --shot clip:.jo-prog …] [--out <folder>] [-v]
//   default folder: ~/Claude/UnraidSecretaryOffice-briefs/shots/<yyyy-mm-dd>/
//   names: <route>-<shot>-<theme>-<lang>-<width>.png  (the route's / as -, the shot's name: full, top, panel, or n)
//
// Needs node and playwright-core (USO_PLAYWRIGHT=<its folder>, else found in node's own paths or npx's cache) and a
// Chrome (USO_CHROME=<binary>, else Google Chrome in /Applications, else playwright's own Chromium) - like the click
// test (tools/ui-clicks.mjs). Exit: 0 all shots taken, 1 a shot failed, 2 wrong call, 3 playwright-core or Chrome missing.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..');
const require = createRequire(import.meta.url);

// each route's own shots, named: what the forum and the product page want of it
const SHOTS = {
  emby: [{ name: 'top', until: '.jo-prog' }, { name: 'panel', clip: '.jo-prog' }],
};
const LOCALES = { de: 'de-CH', en: 'en-US', fr: 'fr-CH', it: 'it-CH', es: 'es-ES' };

// ------------------------------------------------------------------ the call
const today = new Date();
const day = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
const opt = { routes: ['emby'], themes: ['black', 'white'], langs: ['de', 'en'], widths: [1440, 390], shots: [], verbose: false,
  out: path.join(os.homedir(), 'Claude', 'UnraidSecretaryOffice-briefs', 'shots', day) };
const args = process.argv.slice(2);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  const val = () => { if (i + 1 >= args.length) usage(); return args[++i]; };
  const list = () => val().split(',').map((x) => x.trim()).filter(Boolean);
  if (a === '--routes' || a === '--only') opt.routes = list().map((r) => r.replace(/^#?\/?/, ''));
  else if (a === '--themes') opt.themes = list();
  else if (a === '--langs') opt.langs = list();
  else if (a === '--widths') opt.widths = list().map(Number);
  else if (a === '--shot') opt.shots.push(val());
  else if (a === '--out') opt.out = path.resolve(val());
  else if (a === '-v' || a === '--verbose') opt.verbose = true;
  else if (a === '-h' || a === '--help') usage(0);
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/ui-shots.mjs [--routes emby] [--themes black,white] [--langs de,en] [--widths 1440,390]');
  console.error('                               [--shot full|until:<css>|clip:<css>]… [--out <folder>] [-v]');
  process.exit(code);
}
if (!opt.routes.length || !opt.themes.length || !opt.langs.length || !opt.widths.length) usage();
if (opt.themes.some((t) => !['black', 'white'].includes(t))) { console.error('ui shots: themes are black and white'); usage(); }
if (opt.widths.some((w) => !(w >= 320 && w <= 3840))) { console.error('ui shots: widths from 320 to 3840'); usage(); }
const langs = fs.readdirSync(path.join(REPO, 'public', 'lang')).filter((f) => /^[a-z]{2}\.json$/.test(f)).map((f) => f.slice(0, 2));
if (opt.langs.some((l) => !langs.includes(l))) { console.error(`ui shots: languages are ${langs.join(', ')}`); usage(); }
for (const r of opt.routes) {
  if (!/^[a-z][a-z0-9_-]*(\/[A-Za-z0-9:._-]+)*$/.test(r) || !fs.existsSync(path.join(REPO, 'public', 'desks', r.split('/')[0], 'desk.js'))) {
    console.error(`ui shots: no desk page «${r}»`);
    usage();
  }
}
/** «full», «until:<css>», «clip:<css>» → {name, until|clip}; named by kind (top, panel), numbered when there are several */
function parseShot(s, n) {
  if (s === 'full') return { name: 'full' };
  const m = /^(until|clip):(.+)$/.exec(s);
  if (!m) { console.error(`ui shots: a shot is full, until:<css> or clip:<css> - not «${s}»`); usage(); }
  return { name: `${m[1] === 'until' ? 'top' : 'panel'}${n ? n + 1 : ''}`, [m[1]]: m[2] };
}
const given = opt.shots.map((s, i) => parseShot(s, opt.shots.length > 1 ? i : 0));
const shotsOf = (route) => (given.length ? given : SHOTS[route] || [{ name: 'full' }]);

// ------------------------------------------------------------------ playwright-core and a Chrome (as tools/ui-clicks.mjs)
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
  console.log('ui shots: playwright-core not found (USO_PLAYWRIGHT=<folder>, or `npx playwright-core --version` once) - not run');
  process.exit(3);
}
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p)) || (() => { try { const p = pw.chromium.executablePath(); return p && fs.existsSync(p) ? p : null; } catch (e) { return null; } })();
if (!chrome) {
  console.log('ui shots: no Chrome found (USO_CHROME=<binary>) - not run');
  process.exit(3);
}

const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));

// ------------------------------------------------------------------ one page: every shot of it
const AROUND = 8;           // px of the page around a clipped element (the next thing on the page starts ~12 px below)

async function shootPage(browser, harness, route, theme, lang, width) {
  const phone = width < 768;
  const ctx = await browser.newContext({
    viewport: { width, height: phone ? 844 : 900 }, deviceScaleFactor: 2, isMobile: phone, hasTouch: phone,
    locale: LOCALES[lang] || lang, colorScheme: theme === 'white' ? 'light' : 'dark',
  });
  // the office's language as if chosen (⋯ → Language), its theme switch on Automatic: Unraid's theme decides
  await ctx.addInitScript((l) => { try { localStorage.setItem('office.lang', l); localStorage.removeItem('office.theme'); } catch (e) { /* none */ } }, lang);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  const out = [];
  try {
    await page.goto(`${harness.url}?theme=${theme}#/${route}`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.Office && Office.current && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), null, { timeout: 15000 });
    for (const shot of shotsOf(route)) {
      const file = path.join(opt.out, `${route.replace(/\//g, '-')}-${shot.name}-${theme}-${lang}-${width}.png`);
      const res = { file, route, shot: shot.name, theme, lang, width, fails: [] };
      out.push(res);
      const sel = shot.until || shot.clip;
      if (sel && !(await page.waitForSelector(sel, { state: 'visible', timeout: 8000 }).catch(() => null))) {
        res.fails.push(`no «${sel}» on the page`);
        continue;
      }
      // Unraid's fonts loaded, the desk drawn and still (two frames after the last change)
      await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(['14px clear-sans', 'bold 14px clear-sans', '12px bitstream'].map((f) => document.fonts.load(f).catch(() => null)));
      });
      const fonts = await page.evaluate(() => document.fonts.check('14px clear-sans') && [...document.fonts].some((f) => f.family.replace(/"/g, '') === 'clear-sans' && f.status === 'loaded'));
      if (!fonts) res.fails.push('Unraid\'s font (clear-sans) did not load');
      await page.waitForTimeout(400);
      await page.evaluate(() => new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok))));
      const shotOpts = { path: file, animations: 'disabled', caret: 'hide' };
      if (shot.clip) {
        const box = await page.locator(shot.clip).first().boundingBox();
        await page.screenshot({ ...shotOpts, fullPage: true, clip: {
          x: Math.max(0, box.x - AROUND), y: Math.max(0, box.y - AROUND),
          width: Math.min(width, box.x + box.width + AROUND) - Math.max(0, box.x - AROUND), height: box.height + 2 * AROUND } });
      } else if (shot.until) {
        const box = await page.locator(shot.until).first().boundingBox();
        await page.screenshot({ ...shotOpts, fullPage: true, clip: { x: 0, y: 0, width, height: Math.ceil(box.y + box.height + AROUND) } });
      } else {
        await page.screenshot({ ...shotOpts, fullPage: true });
      }
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      if (wide > 1) res.fails.push(`the page scrolls sideways by ${wide} px`);
    }
  } catch (e) {
    if (!out.length) out.push({ file: null, route, shot: '-', theme, lang, width, fails: [] });
    out[out.length - 1].fails.push(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  for (const e of [...new Set(errors)].slice(0, 5)) out.forEach((r) => r.fails.push(`console: ${e}`));
  await ctx.close().catch(() => {});
  return out;
}

// ------------------------------------------------------------------ all of them
const started = Date.now();
fs.mkdirSync(opt.out, { recursive: true });
const harness = await startHarness({ demo: true });
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check', '--font-render-hinting=none'] });
const jobs = [];
for (const route of opt.routes) for (const theme of opt.themes) for (const lang of opt.langs) for (const width of opt.widths) jobs.push({ route, theme, lang, width });
const results = [];
let next = 0;
await Promise.all(Array.from({ length: Math.min(4, jobs.length) }, async () => {
  while (next < jobs.length) {
    const j = jobs[next++];
    const r = await shootPage(browser, harness, j.route, j.theme, j.lang, j.width);
    results.push(...r);
    if (opt.verbose) r.forEach((x) => console.log(`  ${x.fails.length ? 'FAIL' : 'ok  '} ${x.file ? path.basename(x.file) : `${j.route} ${j.theme} ${j.lang} ${j.width}`}`));
  }
}));
await browser.close();
await harness.close();

results.sort((a, b) => String(a.file).localeCompare(String(b.file)));
const failed = results.filter((r) => r.fails.length);
for (const r of results) console.log(`${r.fails.length ? 'FAIL' : 'ok  '}  ${r.file || `${r.route} ${r.theme} ${r.lang} ${r.width}`}`);
for (const r of failed) {
  console.log(`\n${r.file ? path.basename(r.file) : `${r.route} ${r.theme} ${r.lang} ${r.width}`}:`);
  for (const f of r.fails) console.log(`  - ${f}`);
}
console.log(`\nui shots: ${results.length - failed.length} of ${results.length} taken into ${opt.out} - ${((Date.now() - started) / 1000).toFixed(0)} s`);
process.exit(failed.length ? 1 : 0);
