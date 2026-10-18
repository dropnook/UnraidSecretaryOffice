#!/usr/bin/env node
// A desk's page as text, from a folder of state files (a server's real ones - tools/scenarios/page.sh copies them): the
// page built from this clone's files (tests/ui/harness.mjs with opts.states), opened in a headless Chrome with a throwaway
// profile (as tools/ui-clicks.mjs), the desk drawn, then the innerText of #sso printed - once per language (the browser's
// language: the page speaks it as for a user whose browser does).
//
//   node tools/scenarios/page.mjs --states <folder> <desk>[/<tab>…] [--lang de,en] [--host <name>] [--theme dark|light]
//                                 [--width 1440] [--json]
//
// The folder holds <desk>.json and the parts (<desk>-<part>.json) as the data folder does, and post/<desk>.<action>.json
// for canned answers to a dialog's post (a scenario's `ask … --save-post`). Prints, per language:
//   ===== <route> <lang> =====
//   <the text>
// --json: one JSON line instead: {route, pages: [{lang, text, errors}]}. Console and page errors go to stderr (and into
// the JSON). Exit 0, 1 the desk didn't draw in a language, 2 wrong call, 3 node's playwright-core or Chrome missing.
// Nothing here touches a server.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);

const opt = { states: null, route: null, langs: ['en'], host: null, theme: 'dark', width: 1440, json: false };
const args = process.argv.slice(2);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  const val = () => { if (i + 1 >= args.length) usage(); return args[++i]; };
  if (a === '--states') opt.states = path.resolve(val());
  else if (a === '--lang') opt.langs = val().split(',').filter((l) => /^[a-z]{2}$/.test(l));
  else if (a === '--host') opt.host = val();
  else if (a === '--theme') opt.theme = val();
  else if (a === '--width') opt.width = Number(val()) || 1440;
  else if (a === '--json') opt.json = true;
  else if (a === '-h' || a === '--help') usage(0);
  else if (!a.startsWith('-') && opt.route === null) opt.route = a.replace(/^#?\/?/, '');
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/scenarios/page.mjs --states <folder> <desk>[/<tab>] [--lang de,en] [--host <name>] [--theme dark|light] [--width 1440] [--json]');
  process.exit(code);
}
if (!opt.states || !opt.route || !opt.langs.length || !['dark', 'light'].includes(opt.theme)) usage();
const desk = opt.route.split('/')[0];
if (!/^[a-z][a-z0-9_-]*(\/[A-Za-z0-9:._-]+)*$/.test(opt.route) || !fs.existsSync(path.join(REPO, 'public', 'desks', desk, 'desk.js'))) {
  console.error(`page: no desk page «${opt.route}»`);
  usage();
}
if (!fs.existsSync(opt.states)) {
  console.error(`page: no states folder «${opt.states}»`);
  usage();
}

// playwright-core and a Chrome, found as tools/ui-clicks.mjs finds them
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
  console.error('page: playwright-core not found (USO_PLAYWRIGHT=<folder>, or `npx playwright-core --version` once)');
  process.exit(3);
}
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p)) || (() => { try { const p = pw.chromium.executablePath(); return p && fs.existsSync(p) ? p : null; } catch (e) { return null; } })();
if (!chrome) {
  console.error('page: no Chrome found (USO_CHROME=<binary>)');
  process.exit(3);
}

const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
const harness = await startHarness({ states: opt.states, ...(opt.host ? { host: opt.host } : {}) });
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check'] });
const pages = [];
let bad = 0;
for (const lang of opt.langs) {
  const ctx = await browser.newContext({ viewport: { width: opt.width, height: 900 }, locale: lang === 'en' ? 'en-US' : lang });
  await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, opt.theme);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 200)}`));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  await page.goto(`${harness.url}#/${opt.route}`, { waitUntil: 'load' });
  const drawn = await page.waitForFunction((d) => window.Office && Office.current && Office.current.id === d
    && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), desk, { timeout: 15000 }).then(() => true, () => false);
  // the text settles: the same for 600 ms (parts and the minute's look come in after the first paint), at most 10 s
  let text = '';
  const until = Date.now() + 10000;
  for (let calm = 0; calm < 3 && Date.now() < until;) {
    await page.waitForTimeout(200);
    const now = await page.evaluate(() => (document.getElementById('sso') || document.body).innerText);
    calm = now === text ? calm + 1 : 0;
    text = now;
  }
  if (!drawn) { bad++; errors.unshift(`the desk «${desk}» did not draw`); }
  for (const e of new Set(errors)) console.error(`page ${opt.route} ${lang}: ${e}`);
  pages.push({ lang, text, errors: [...new Set(errors)] });
  await ctx.close();
}
await browser.close();
await harness.close();
if (opt.json) console.log(JSON.stringify({ route: opt.route, pages }));
else for (const p of pages) console.log(`===== ${opt.route} ${p.lang} =====\n${p.text}\n`);
process.exit(bad ? 1 : 0);
