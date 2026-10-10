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
// --moments [nr,…]: the product page's «moments» instead (tests/ui/demo/moments.json) - per desk an element that does
// something, clipped, after a small «prepare» (open a row, a dialog, a tooltip), each in its own demo state; black theme,
// 1280 px, scale 2, de and en: <nr>-<desk>-<moment>-<lang>.png and a contact sheet (contact-sheet.html, contact-sheet-<lang>.png)
// with all of them small and named. A moment: {nr, desk, moment, title, route ('' = the reception), states {name: file},
// prepare [{click|hover|wait|scroll|select: <css>, value (select), all (click every match), pause (ms)} | {js: …}…], clip (css, or a list: the box around all), pad, height,
// viewport (true: the element is fixed - a dialog - and shot as the window shows it), hide [css…]}.
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
const opt = { routes: ['emby'], themes: ['black', 'white'], langs: ['de', 'en'], widths: [1440, 390], shots: [], verbose: false, moments: null,
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
  else if (a === '--out') { opt.out = path.resolve(val()); opt.outGiven = true; }
  else if (a === '-v' || a === '--verbose') opt.verbose = true;
  else if (a === '--moments') opt.moments = i + 1 < args.length && /^\d+(,\d+)*$/.test(args[i + 1]) ? list().map(Number) : [];
  else if (a === '-h' || a === '--help') usage(0);
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/ui-shots.mjs [--routes emby] [--themes black,white] [--langs de,en] [--widths 1440,390]');
  console.error('                               [--shot full|until:<css>|clip:<css>]… [--out <folder>] [-v]');
  console.error('       node tools/ui-shots.mjs --moments [1,3,…] [--langs de,en] [--out <folder>] [-v]');
  process.exit(code);
}
if (opt.moments && !opt.outGiven) opt.out = `${opt.out}-moments`;      // shots/<yyyy-mm-dd>-moments/
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

// ------------------------------------------------------------------ the moments (--moments)
const MOMENTS_FILE = path.join(REPO, 'tests', 'ui', 'demo', 'moments.json');
const MOMENT_WIDTH = 1280;
const momentFile = (m, lang) => path.join(opt.out, `${String(m.nr).padStart(2, '0')}-${m.desk}-${m.moment}-${lang}.png`);

/** One step of a moment's «prepare»: click, hover, wait for, scroll to an element, or run a bit of the page's JS */
async function prepareStep(page, st) {
  const sel = st.click || st.hover || st.wait || st.scroll || st.select;
  if (sel) {
    const loc = page.locator(sel);
    await loc.first().waitFor({ state: 'visible', timeout: 8000 });
    if (st.click) {
      const n = st.all ? await loc.count() : 1;
      for (let i = 0; i < n; i++) await loc.nth(i).click();
    } else if (st.hover) await loc.first().hover();
    else if (st.scroll) await loc.first().scrollIntoViewIfNeeded();
    else if (st.select) await loc.first().selectOption(String(st.value));
  }
  if (st.js) await page.evaluate(st.js);
  await page.waitForTimeout(st.pause ?? 250);
}

async function shootMoment(browser, m, lang) {
  const harness = await startHarness({ demo: true, alias: m.states || {} });
  const width = m.width || MOMENT_WIDTH;
  const ctx = await browser.newContext({ viewport: { width, height: m.height || 900 }, deviceScaleFactor: 2,
    locale: LOCALES[lang] || lang, colorScheme: 'dark' });
  await ctx.addInitScript((l) => { try { localStorage.setItem('office.lang', l); localStorage.removeItem('office.theme'); } catch (e) { /* none */ } }, lang);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (x) => { if (x.type() === 'error') errors.push(x.text().slice(0, 200)); });
  page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  const res = { file: momentFile(m, lang), route: `${m.nr} ${m.desk}/${m.moment}`, shot: m.moment, theme: 'black', lang, width, fails: [] };
  try {
    await page.goto(`${harness.url}?theme=black#/${m.route || ''}`, { waitUntil: 'load' });
    await page.waitForFunction((r) => window.Office && (r ? Office.current && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box')
      : document.querySelector('#sso-desk .reception .desk-card .facts li')), m.route || '', { timeout: 15000 });
    await page.evaluate(async () => {
      await document.fonts.ready;
      await Promise.all(['14px clear-sans', 'bold 14px clear-sans', '12px bitstream'].map((f) => document.fonts.load(f).catch(() => null)));
    });
    await page.waitForTimeout(500);
    for (const st of m.prepare || []) await prepareStep(page, st);
    if ((m.hide || []).length) await page.addStyleTag({ content: `${m.hide.join(',')}{visibility:hidden!important}` });
    const sels = [].concat(m.clip || []);
    for (const sel of sels) {
      if (!(await page.waitForSelector(sel, { state: 'visible', timeout: 8000 }).catch(() => null))) res.fails.push(`no «${sel}» on the page`);
    }
    if (!res.fails.length) {
      await page.waitForTimeout(300);
      await page.evaluate(() => new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok))));
      // the box around all the clip's elements, in the page's coordinates (or the window's, for a fixed one)
      const box = await page.evaluate(({ sels, fixed }) => {
        const r = { x1: Infinity, y1: Infinity, x2: -Infinity, y2: -Infinity };
        for (const sel of sels) {
          for (const n of document.querySelectorAll(sel)) {
            const b = n.getBoundingClientRect();
            if (!b.width || !b.height) continue;
            const dx = fixed ? 0 : window.scrollX;
            const dy = fixed ? 0 : window.scrollY;
            r.x1 = Math.min(r.x1, b.left + dx); r.y1 = Math.min(r.y1, b.top + dy);
            r.x2 = Math.max(r.x2, b.right + dx); r.y2 = Math.max(r.y2, b.bottom + dy);
            break;           // the first of each selector, as the click test and --shot clip: take it
          }
        }
        return r;
      }, { sels, fixed: !!m.viewport });
      const pad = m.pad ?? AROUND;
      const clip = { x: Math.max(0, box.x1 - pad), y: Math.max(0, box.y1 - pad) };
      clip.width = Math.min(width, box.x2 + pad) - clip.x;
      clip.height = box.y2 + pad - clip.y;
      if (m.max_height && clip.height > m.max_height) clip.height = m.max_height;
      await page.screenshot({ path: res.file, animations: 'disabled', caret: 'hide', fullPage: !m.viewport, clip });
    }
  } catch (e) {
    res.fails.push(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  for (const e of [...new Set(errors)].slice(0, 5)) res.fails.push(`console: ${e}`);
  await ctx.close().catch(() => {});
  await harness.close();
  return res;
}

/** All moments small with their names, the de set beside the en one: contact-sheet.html, and a PNG of it per language */
async function contactSheet(browser, moments, shot) {
  const esc = (x) => String(x).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const card = (m, lang) => {
    const f = path.basename(momentFile(m, lang));
    return fs.existsSync(path.join(opt.out, f)) ? `<figure><a href="${esc(f)}"><img src="${esc(f)}" alt=""></a>`
      + `<figcaption><b>${m.nr}</b> ${esc(m.title || `${m.desk} · ${m.moment}`)}<br><code>${esc(f)}</code></figcaption></figure>` : '';
  };
  const html = (langs) => `<!doctype html><html><head><meta charset="utf-8"><title>Moments ${esc(day)}</title><style>
body{background:#111;color:#ddd;font:14px/1.4 -apple-system,Helvetica,sans-serif;margin:24px}
h1{font-size:20px;margin:0 0 4px} p{margin:0 0 20px;color:#999}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:20px;align-items:start}
figure{margin:0;background:#1b1b1b;border:1px solid #333;border-radius:8px;padding:10px}
img{display:block;width:100%;max-height:420px;object-fit:contain;object-position:left top;background:#000;border-radius:4px}
figcaption{margin-top:8px} code{color:#888;font-size:11px}</style></head><body>
<h1>Unraid Secretary Office - moments (${esc(day)})</h1><p>Black theme, 1280 px, scale 2 - invented family data (tests/ui/demo). ${langs.join(' / ')}</p>
${langs.map((l) => `<h2>${l}</h2><div class="grid">${moments.map((m) => card(m, l)).join('')}</div>`).join('\n')}
</body></html>`;
  fs.writeFileSync(path.join(opt.out, 'contact-sheet.html'), html(opt.langs));
  const files = [path.join(opt.out, 'contact-sheet.html')];
  if (!shot) return files;
  for (const l of opt.langs) {
    const tmp = path.join(opt.out, `.contact-sheet-${l}.html`);
    fs.writeFileSync(tmp, html([l]));
    const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto('file://' + tmp);
    await page.waitForTimeout(300);
    const f = path.join(opt.out, `contact-sheet-${l}.png`);
    await page.screenshot({ path: f, fullPage: true });
    await ctx.close();
    fs.unlinkSync(tmp);
    files.push(f);
  }
  return files;
}

async function runMoments() {
  const all = JSON.parse(fs.readFileSync(MOMENTS_FILE, 'utf8'));
  const chosen = opt.moments.length ? all.filter((m) => opt.moments.includes(m.nr)) : all;
  if (!chosen.length) { console.error('ui shots: no such moment'); process.exit(2); }
  const t0 = Date.now();
  fs.mkdirSync(opt.out, { recursive: true });
  const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check', '--font-render-hinting=none'] });
  const jobs = [];
  for (const m of chosen) for (const lang of opt.langs) jobs.push({ m, lang });
  const results = [];
  let next = 0;
  await Promise.all(Array.from({ length: Math.min(4, jobs.length) }, async () => {
    while (next < jobs.length) {
      const j = jobs[next++];
      const r = await shootMoment(browser, j.m, j.lang);
      results.push(r);
      if (opt.verbose) console.log(`  ${r.fails.length ? 'FAIL' : 'ok  '} ${path.basename(r.file)}`);
    }
  }));
  const sheet = await contactSheet(browser, all, true);
  await browser.close();
  results.sort((a, b) => a.file.localeCompare(b.file));
  const failed = results.filter((r) => r.fails.length);
  for (const r of results) console.log(`${r.fails.length ? 'FAIL' : 'ok  '}  ${r.file}`);
  for (const f of sheet) console.log(`sheet ${f}`);
  for (const r of failed) {
    console.log(`\n${path.basename(r.file)}:`);
    for (const f of r.fails) console.log(`  - ${f}`);
  }
  console.log(`\nui shots: ${results.length - failed.length} of ${results.length} moments taken into ${opt.out} - ${((Date.now() - t0) / 1000).toFixed(0)} s`);
  process.exit(failed.length ? 1 : 0);
}
if (opt.moments) await runMoments();

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
