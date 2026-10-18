#!/usr/bin/env node
// A desk's page as text, from a folder of state files (a server's real ones - tools/scenarios/page.sh copies them): the
// page built from this clone's files (tests/ui/harness.mjs with opts.states), opened in a headless Chrome with a throwaway
// profile (as tools/ui-clicks.mjs), the desk drawn, then the innerText of #sso printed - once per language (the browser's
// language: the page speaks it as for a user whose browser does).
//
//   node tools/scenarios/page.mjs --states <folder> <desk>[/<tab>…] [--lang de,en] [--host <name>] [--theme dark|light]
//                                 [--width 1440] [--json] [--want <desk.key>]… [--not <desk.key>]… [--clean]
//
// The folder holds <desk>.json and the parts (<desk>-<part>.json) as the data folder does, and post/<desk>.<action>.json
// for canned answers to a dialog's post (a scenario's `ask … --save-post`). Prints, per language:
//   ===== <route> <lang> =====
//   <the text>
// --json: one JSON line instead: {route, pages: [{lang, text, errors}]}. Console and page errors go to stderr (and into
// the JSON). --want / --not (repeatable): a lang key that must / must not be on the page in every language asked
// (`backup.bubble.last_ok`; `office.…` the office's own) - its text in that language, a plural any of its forms,
// placeholders {…}, ⟦Unraid labels⟧ and markup matching anything; each result printed (and in the JSON's `checks`).
// A greeting key (<desk>.greet.N) in --not: the page is drawn once per greeting with Math.random pinned, so whichever
// greeting the desk's own logic could pick is seen.
// --clean (INV-PAGE, tools/scenarios/checks.sh): the text holds no raw lang key (a key of the desk's or the office's
// lang files as it is), no «undefined», «NaN», «[object», no placeholder left ({n}), no script name (setup.sh,
// backup.sh, custommover), no «0 of 0» / «0 von 0», no «?» where a value belongs (alone, or before a unit), no «null» in
// English, and German no English fallback (a line with two or more of «the of and is to», commands and code shown on
// the page left out — and Protokolli's and the watchman's log lines);
// each finding printed as «FAIL clean <lang>: …».
// Exit 0, 1 the desk didn't draw in a language or a --want/--not/--clean check failed, 2 wrong call, 3 playwright-core or
// Chrome missing.
// Nothing here touches a server.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);

const opt = { states: null, route: null, langs: ['en'], host: null, theme: 'dark', width: 1440, json: false, want: [], not: [], clean: false };
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
  else if (a === '--clean') opt.clean = true;
  else if (a === '--want' || a === '--not') opt[a.slice(2)].push(val());
  else if (a === '-h' || a === '--help') usage(0);
  else if (!a.startsWith('-') && opt.route === null) opt.route = a.replace(/^#?\/?/, '');
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/scenarios/page.mjs --states <folder> <desk>[/<tab>] [--lang de,en] [--host <name>] [--theme dark|light] [--width 1440] [--json] [--want|--not <desk.key>]…');
  process.exit(code);
}
if (!opt.states || !opt.route || !opt.langs.length || !['dark', 'light'].includes(opt.theme)) usage();
const desk = opt.route.split('/')[0];
if (!/^[a-z][a-z0-9_-]*(\/[A-Za-z0-9:._-]+)*$/.test(opt.route) || !fs.existsSync(path.join(REPO, 'public', 'desks', desk, 'desk.js'))) {
  console.error(`page: no desk page «${opt.route}»`);
  usage();
}
for (const k of [...opt.want, ...opt.not]) {
  if (!/^[a-z][a-z0-9_-]*\.[A-Za-z0-9_.-]+$/.test(k)) { console.error(`page: «${k}» is no lang key (desk.key)`); usage(); }
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

// a lang key's text in a language as a pattern: every form of a plural, {…} ⟦…⟧ and <…> as «anything», blanks as blanks
const readJson = (f) => { try { return JSON.parse(fs.readFileSync(f, 'utf8')); } catch (e) { return null; } };
function keyPatterns(key, lang) {
  const [d, ...rest] = key.split('.');
  const files = d === 'office' ? [path.join(REPO, 'public', 'lang', `${lang}.json`), path.join(REPO, 'public', 'lang', 'en.json')]
    : [path.join(REPO, 'public', 'desks', d, 'lang', `${lang}.json`), path.join(REPO, 'public', 'desks', d, 'lang', 'en.json')];
  const k = d === 'office' ? key : rest.join('.');
  let v;
  for (const f of files) { const j = readJson(f); if (j && j[k] !== undefined) { v = j[k]; break; } }
  if (v === undefined) return null;
  const forms = typeof v === 'string' ? [v] : Object.values(v || {}).filter((x) => typeof x === 'string');
  return forms.map((t) => new RegExp(t.split(/\{[^}]*\}|⟦[^⟧]*⟧|<[^>]*>/).map((part) => part.trim().split(/\s+/).filter(Boolean)
    .map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('\\s+')).join('[\\s\\S]*?')));
}
function keyChecks(text, lang) {
  const out = [];
  for (const [want, keys] of [[true, opt.want], [false, opt.not]]) {
    for (const key of keys) {
      const pats = keyPatterns(key, lang);
      const found = pats ? pats.some((re) => re.test(text)) : null;
      out.push({ lang, key, want, found, ok: found !== null && found === want });
    }
  }
  return out;
}

// INV-PAGE: what a page must never say (the text as innerText gives it)
const langKeys = new Set();
for (const [dir, pre] of [[path.join(REPO, 'public', 'desks', desk, 'lang'), ''], [path.join(REPO, 'public', 'lang'), '']]) {
  const j = readJson(path.join(dir, 'en.json')) || {};
  Object.keys(j).filter((k) => k.includes('.')).forEach((k) => { langKeys.add(pre + k); langKeys.add(`${desk}.${k}`); });
}
function cleanChecks(text, lang) {
  const out = [];
  const add = (what, m) => out.push(`${what}: «${String(m).slice(0, 80)}»`);
  const raw = desk === 'logs' || desk === 'watchman';        // they show log lines as they are
  for (const m of text.matchAll(/\b[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+\b/g)) if (langKeys.has(m[0])) add('raw key', m[0]);
  for (const m of text.matchAll(/\{[a-z_]+\}/g)) add('placeholder', m[0]);
  if (!raw) {
    for (const re of [/\bundefined\b/, /\bNaN\b/, /\[object /, /\b(?:setup|backup)\.sh\b/, /custommover/i, /\b0 (?:of|von|di|sur|de) 0\b/]) {
      const m = re.exec(text);
      if (m) add('nonsense', m[0]);
    }
    // a «?» where a value belongs: standing alone (« ? », «(?)») or before a unit («? GB», «?%») — never a question
    for (const m of text.matchAll(/(?:(?:^|[\s(:·])\?(?=[\s).,·]|$)|\?\s?(?:[KMGTP]i?B|B|%)(?![A-Za-z]))/gm)) add('a «?» for a value', text.slice(Math.max(0, m.index - 30), m.index + 6).replace(/\s+/g, ' '));
    if (lang === 'en' && /\bnull\b/.test(text)) add('nonsense', 'null');
    if (lang === 'de') {
      for (const line of text.split('\n')) {
        if (/[$|&<>`=]|\b(?:echo|mkdir|cat|docker|zfs)\b/.test(line)) continue;     // a command or code shown as it is
        const w = (line.match(/\b(?:the|of|and|is|to)\b/g) || []).length;
        if (w >= 2) add('English in German', line.trim());
      }
    }
  }
  return [...new Set(out)];
}

const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
const harness = await startHarness({ states: opt.states, ...(opt.host ? { host: opt.host } : {}) });
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check'] });
const pages = [];
let bad = 0;
// one drawing of the route in a language: the text once it settled, the errors, whether the desk drew. random (0…1):
// Math.random pinned to it — a desk's greeting is picked at random, so a greeting key in --not is looked for in one
// drawing per slot (greetSlots()), every greeting the page could pick seen once
async function draw(lang, random) {
  const ctx = await browser.newContext({ viewport: { width: opt.width, height: 900 }, locale: lang === 'en' ? 'en-US' : lang });
  await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, opt.theme);
  if (random !== undefined) await ctx.addInitScript((r) => { Math.random = () => r; }, random);
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
  await ctx.close();
  return { text, errors, drawn };
}
// how many greetings the desk has (greet.1 … greet.N in its en.json): that many drawings cover every pick
function greetSlots() {
  const j = readJson(path.join(REPO, 'public', 'desks', desk, 'lang', 'en.json')) || {};
  let n = 0;
  while (j[`greet.${n + 1}`] !== undefined) n++;
  return n;
}
const greetNot = opt.not.filter((k) => /^[a-z][a-z0-9_-]*\.greet\.\d+$/.test(k));

for (const lang of opt.langs) {
  const { text, errors, drawn } = await draw(lang);
  if (!drawn) { bad++; errors.unshift(`the desk «${desk}» did not draw`); }
  for (const e of new Set(errors)) console.error(`page ${opt.route} ${lang}: ${e}`);
  const checks = keyChecks(text, lang).filter((c) => !greetNot.includes(c.key));
  // a greeting that must not show: one drawing per random slot — found in any of them is a FAIL
  if (greetNot.length && drawn) {
    const n = greetSlots();
    const seen = new Set();
    for (let i = 0; i < n; i++) {
      const d = await draw(lang, (i + 0.5) / n);
      for (const k of greetNot) {
        const pats = keyPatterns(k, lang);
        if (pats && pats.some((re) => re.test(d.text))) seen.add(k);
      }
    }
    for (const k of greetNot) checks.push({ lang, key: k, want: false, found: keyPatterns(k, lang) ? seen.has(k) : null, ok: keyPatterns(k, lang) ? !seen.has(k) : false, slots: n });
  }
  for (const c of checks) {
    if (!c.ok) bad++;
    console.error(`page ${opt.route} ${lang}: ${c.ok ? 'ok  ' : 'FAIL'} ${c.want ? 'want' : 'not '} ${c.key}${c.found === null ? ' (no such key)' : ''}${c.slots ? ` (${c.slots} drawings, one per greeting)` : ''}`);
  }
  const clean = opt.clean && drawn ? cleanChecks(text, lang) : [];
  for (const c of clean) console.error(`page ${opt.route} ${lang}: FAIL clean ${c}`);
  if (opt.clean && drawn && !clean.length) console.error(`page ${opt.route} ${lang}: ok   clean`);
  bad += clean.length;
  pages.push({ lang, text, errors: [...new Set(errors)], checks, clean });
}
await browser.close();
await harness.close();
if (opt.json) console.log(JSON.stringify({ route: opt.route, pages }));
else for (const p of pages) console.log(`===== ${opt.route} ${p.lang} =====\n${p.text}\n`);
process.exit(bad ? 1 : 0);
