#!/usr/bin/env node
// A desk's page as text, from a folder of state files (a server's real ones - tools/scenarios/page.sh copies them): the
// page built from this clone's files (tests/ui/harness.mjs with opts.states), opened in a headless Chrome with a throwaway
// profile (as tools/ui-clicks.mjs), the desk drawn, then the innerText of #sso printed - once per language (the browser's
// language: the page speaks it as for a user whose browser does).
//
//   node tools/scenarios/page.mjs --states <folder> <desk>[/<tab>…] [--lang de,en] [--host <name>] [--theme dark|light]
//                                 [--width 1440] [--json] [--want <desk.key>]… [--not <desk.key>]… [--clean] [--open]
//                                 [--tree <tag>] [--value <desk.key>:<ph>[..<ph2>][=<number>]]… [--replay <readings.jsonl>]
//                                 (also --value <desk.key>:..<ph> a range from 0, <desk.key>@<n> a text that stands for n)
//                                 [--factor 1] [--slack 0]
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
// --open: before the text is read, every <details> of #sso opened and every folded row (.row.unfolds) clicked open once
// (a click that made the page's text shorter folded an open one: clicked back) — so a text in a closed guide or a folded
// share row counts. Again whenever the page redraws while it settles.
// --tree <tag> (or USO_PAGE_TREE=<tag>): the page of that tag instead of this clone's — public/, src/ and tests/ui/ of the
// tag (git archive, once, into $TMPDIR/uso-page-tree/<commit>/), today's tests/ui/harness.mjs beside them; lang keys for
// --want/--not/--value from the tag's lang files, a key the tag doesn't have yet from this clone's (today's words, looked
// for on the old page). So a scenario run on an older release draws its state files with that release's page.
// --value <desk.key>:<ph>: the value the page shows for placeholder {ph} of that lang key (its text in the language, the
// placeholder captured), read as a number — a duration in the language's own words (time.* of the office: «2 h 5 min»
// = 7500) or a plain number (separators left out); `<ph>..<ph2>`: a range (rc.left_range's {lo} to {hi}). `=<number>`:
// compared with the number the server knows — within --factor (1: exactly) or less than --slack off; a range holds it
// within the same leeway. The first spec found on the page counts. Printed as «ok|FAIL value …» per language.
// --replay <readings.jsonl> (with --value): a live part fed to the page reading by reading, as an open page sees it
// (only the first language): lines {t, progress, expect} (the scenario's readings of the desk's part «progress»; expect
// = the number the server knows for that moment), the state folder's <desk>-progress.json as the frame. The page's
// clock (Playwright's) starts at the first reading and is run forward to each next one, so the page's own timers poll
// the part and its own code says the value; after each reading the --value specs are read off the page and compared
// with its expect. A reading where the page shows none (still being estimated) is not judged; none shown at all is a FAIL.
// Exit 0, 1 the desk didn't draw in a language or a --want/--not/--clean/--value check failed, 2 wrong call, 3
// playwright-core or Chrome missing.
// Nothing here touches a server.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);

const opt = { states: null, route: null, langs: ['en'], host: null, theme: 'dark', width: 1440, json: false, want: [], not: [], clean: false,
  open: false, tree: process.env.USO_PAGE_TREE || null, value: [], replay: null, factor: 1, slack: 0 };
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
  else if (a === '--open') opt.open = true;
  else if (a === '--tree') opt.tree = val();
  else if (a === '--value') opt.value.push(val());
  else if (a === '--replay') opt.replay = path.resolve(val());
  else if (a === '--factor') opt.factor = Number(val());
  else if (a === '--slack') opt.slack = Number(val());
  else if (a === '--want' || a === '--not') opt[a.slice(2)].push(val());
  else if (a === '-h' || a === '--help') usage(0);
  else if (!a.startsWith('-') && opt.route === null) opt.route = a.replace(/^#?\/?/, '');
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/scenarios/page.mjs --states <folder> <desk>[/<tab>] [--lang de,en] [--host <name>] [--theme dark|light] [--width 1440] [--json] [--want|--not <desk.key>]… [--clean] [--open] [--tree <tag>] [--value <desk.key>:<ph>[..<ph2>][=<n>]]… [--replay <jsonl>] [--factor f] [--slack s]');
  process.exit(code);
}
if (!opt.states || !opt.route || !opt.langs.length || !['dark', 'light'].includes(opt.theme)) usage();
if (!(opt.factor >= 1) || !(opt.slack >= 0) || (opt.replay && !opt.value.length)) usage();
if (opt.tree === '' || opt.tree === '-') opt.tree = null;
if (opt.tree && !/^[A-Za-z0-9][A-Za-z0-9._/-]*$/.test(opt.tree)) { console.error(`page: «${opt.tree}» is no tag`); usage(); }
// the page drawn: this clone's, or a tag's (git archive into a cache folder, today's harness beside it)
function pageTree(tag) {
  const run = (cmd, a, o = {}) => execFileSync(cmd, a, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...o });
  let sha;
  try { sha = run('git', ['-C', REPO, 'rev-parse', '--verify', '--quiet', `${tag}^{commit}`]).trim(); } catch (e) { sha = ''; }
  if (!/^[0-9a-f]{40}$/.test(sha)) { console.error(`page: no tag or commit «${tag}» in ${REPO} (git fetch --tags)`); process.exit(2); }
  const dir = path.join(os.tmpdir(), 'uso-page-tree', sha);
  if (!fs.existsSync(path.join(dir, '.complete'))) {
    const tmp = `${dir}.${process.pid}`;
    fs.rmSync(tmp, { recursive: true, force: true });
    fs.mkdirSync(tmp, { recursive: true });
    const tar = run('git', ['-C', REPO, 'archive', '--format=tar', sha, 'public', 'src', 'tests/ui'], { encoding: 'buffer', maxBuffer: 1 << 30 });
    run('tar', ['-x', '-C', tmp], { input: tar, stdio: ['pipe', 'pipe', 'pipe'] });
    fs.copyFileSync(path.join(REPO, 'tests', 'ui', 'harness.mjs'), path.join(tmp, 'tests', 'ui', 'harness.mjs'));
    fs.writeFileSync(path.join(tmp, '.complete'), `${tag}\n`);
    fs.rmSync(dir, { recursive: true, force: true });
    fs.renameSync(tmp, dir);
  }
  return dir;
}
const PAGE = opt.tree ? pageTree(opt.tree) : REPO;
if (opt.tree) console.error(`page: drawn with the page of ${opt.tree} (${PAGE})`);
const desk = opt.route.split('/')[0];
if (!/^[a-z][a-z0-9_-]*(\/[A-Za-z0-9:._-]+)*$/.test(opt.route) || !fs.existsSync(path.join(PAGE, 'public', 'desks', desk, 'desk.js'))) {
  console.error(`page: no desk page «${opt.route}»`);
  usage();
}
for (const k of [...opt.want, ...opt.not]) {
  if (!/^[a-z][a-z0-9_-]*\.[A-Za-z0-9_.-]+$/.test(k)) { console.error(`page: «${k}» is no lang key (desk.key)`); usage(); }
}
// --value specs: {key, ph, ph2, expect}
// `<key>:..<ph>` a range from 0 («at most {hi}»); `<key>@<n>` a text without a number that stands for n («a few seconds»)
const valueSpecs = opt.value.map((v) => {
  const f = /^([a-z][a-z0-9_-]*\.[A-Za-z0-9_.-]+)@(\d+(?:\.\d+)?)$/.exec(v);
  if (f) return { spec: v, key: f[1], ph: null, ph2: null, fixed: Number(f[2]), expect: null };
  const m = /^([a-z][a-z0-9_-]*\.[A-Za-z0-9_.-]+):([a-z_][a-z0-9_]*)?(?:\.\.([a-z_][a-z0-9_]*))?(?:=(-?\d+(?:\.\d+)?))?$/.exec(v);
  if (!m || (!m[2] && !m[3])) { console.error(`page: «${v}» is no value spec (<desk.key>:<placeholder>[..<placeholder>][=<number>], <desk.key>:..<placeholder>, <desk.key>@<number>)`); usage(); }
  return { spec: v, key: m[1], ph: m[2] || null, ph2: m[3] || null, expect: m[4] === undefined ? null : Number(m[4]) };
});
if (opt.replay && !fs.existsSync(opt.replay)) { console.error(`page: no readings «${opt.replay}»`); usage(); }
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
// a lang key's text: the drawn page's lang files first (a tag's), then this clone's (a key the tag doesn't have yet)
function keyText(key, lang) {
  const [d, ...rest] = key.split('.');
  const files = [];
  for (const root of PAGE === REPO ? [REPO] : [PAGE, REPO]) {
    files.push(...(d === 'office' ? [path.join(root, 'public', 'lang', `${lang}.json`), path.join(root, 'public', 'lang', 'en.json')]
      : [path.join(root, 'public', 'desks', d, 'lang', `${lang}.json`), path.join(root, 'public', 'desks', d, 'lang', 'en.json')]));
  }
  const k = d === 'office' ? key : rest.join('.');
  for (const f of files) { const j = readJson(f); if (j && j[k] !== undefined) return j[k]; }
  return undefined;
}
function keyPatterns(key, lang) {
  const v = keyText(key, lang);
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
for (const [dir, pre] of [[path.join(PAGE, 'public', 'desks', desk, 'lang'), ''], [path.join(PAGE, 'public', 'lang'), '']]) {
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

// --value: the text of {ph} (and {ph2}) of a lang key on the page, in every form of the key (a plural's), as a number
const esc = (w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const words = (t) => t.trim().split(/\s+/).filter(Boolean).map(esc).join('\\s+');
function valuePatterns(key, lang, phs) {
  const v = keyText(key, lang);
  if (v === undefined) return null;
  const forms = typeof v === 'string' ? [v] : Object.values(v || {}).filter((x) => typeof x === 'string');
  return forms.map((t) => {
    const parts = t.split(/(\{[^}]*\}|⟦[^⟧]*⟧|<[^>]*>)/);
    const order = [];
    const src = parts.map((x, i) => {
      const m = /^\{([a-z_][a-z0-9_]*)\}$/.exec(x);
      if (m && phs.includes(m[1])) {
        order.push(m[1]);
        // the last piece of the text: up to the line's end or the next «·»
        return i >= parts.length - 2 && !parts.slice(i + 1).join('').trim() ? '([^\\n·]+?)(?=\\s*(?:·|\\n|$))' : '([^\\n·]+?)';
      }
      if (/^(\{[^}]*\}|⟦[^⟧]*⟧|<[^>]*>)$/.test(x)) return '[^\\n]*?';
      return x.trim() ? (/^\s/.test(x) ? '\\s*' : '') + words(x) + (/\s$/.test(x) ? '\\s*' : '') : '\\s*';
    }).join('');
    return { re: new RegExp(src, 'm'), order };
  });
}
// a shown value as a number: a duration in the language's own words (the office's time.* keys), else a plain number
function durationPatterns(lang) {
  const out = [];
  for (const [k, mult] of [['time.days_hours', { d: 86400, h: 3600 }], ['time.hours_minutes', { h: 3600, m: 60 }], ['time.minutes', { m: 60 }], ['time.seconds', { s: 1 }]]) {
    let t;
    for (const f of [PAGE, REPO].flatMap((root) => [lang, 'en'].map((l) => path.join(root, 'public', 'lang', `${l}.json`)))) {
      const j = readJson(f);
      if (j && typeof j[k] === 'string') { t = j[k]; break; }
    }
    if (typeof t !== 'string') continue;
    const order = [];
    const src = t.split(/(\{[a-z]\})/).map((x) => {
      const m = /^\{([a-z])\}$/.exec(x);
      if (m) { order.push(m[1]); return '(\\d+)'; }
      return x.trim() ? '\\s*' + words(x) + '\\s*' : '\\s*';
    }).join('');
    out.push({ re: new RegExp(`^\\s*${src}\\s*$`), order, mult });
  }
  return out;
}
function asNumber(text, lang) {
  for (const d of durationPatterns(lang)) {
    const m = d.re.exec(text);
    if (m) return d.order.reduce((sum, u, i) => sum + Number(m[i + 1]) * (d.mult[u] || 0), 0);
  }
  const n = /^\s*(-?\d[\d\s\u00a0\u202f.,']*)$/.exec(text);       // the whole text a number (1 504, 1.504, 1,504)
  return n ? Number(n[1].replace(/[\s\u00a0\u202f.,']/g, '')) : null;
}
/** The first value spec found in the text: {spec, shown, value | lo, hi} or null */
function readValue(text, lang) {
  for (const v of valueSpecs) {
    const phs = [v.ph, v.ph2].filter(Boolean);
    for (const p of valuePatterns(v.key, lang, phs) || []) {
      const m = p.re.exec(text);
      if (!m) continue;
      if (v.fixed !== undefined) return { spec: v, shown: m[0].trim(), value: v.fixed };
      const got = {};
      p.order.forEach((ph, i) => { got[ph] = m[i + 1].trim(); });
      if ((v.ph && got[v.ph] === undefined) || (v.ph2 && got[v.ph2] === undefined)) continue;
      const r = { spec: v, shown: v.ph2 ? `${v.ph ? got[v.ph] : '0'} … ${got[v.ph2]}` : got[v.ph] };
      if (v.ph2) { r.lo = v.ph ? asNumber(got[v.ph], lang) : 0; r.hi = asNumber(got[v.ph2], lang); if (r.lo === null || r.hi === null) continue; }
      else { r.value = asNumber(got[v.ph], lang); if (r.value === null) continue; }
      return r;
    }
  }
  return null;
}
/** A shown value against the server's number: within --factor, or less than --slack off (a range: the number inside it, with the same leeway) */
function valueOk(r, want) {
  const f = opt.factor, sl = opt.slack;
  if (r.value !== undefined) return Math.abs(r.value - want) <= sl || (r.value <= f * Math.max(want, 1) && want <= f * Math.max(r.value, 1) && (f > 1 || r.value === want));
  return (want >= r.lo - sl && want <= r.hi + sl) || (f > 1 && want >= r.lo / f && want <= r.hi * f);
}
const shownText = (r) => (r.value !== undefined ? `«${r.shown}» = ${r.value}` : `«${r.shown}» = ${r.lo}…${r.hi}`);

const { startHarness } = await import(path.join(PAGE, 'tests', 'ui', 'harness.mjs'));
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
  // the text settles: the same for 600 ms (parts and the minute's look come in after the first paint), at most 10 s;
  // --open: the folds opened first, and again when a redraw folded something
  let text = '';
  const until = Date.now() + 10000;
  for (let calm = 0; calm < 3 && Date.now() < until;) {
    await page.waitForTimeout(200);
    if (opt.open && drawn && await openFolds(page)) calm = 0;
    const now = await page.evaluate(() => (document.getElementById('sso') || document.body).innerText);
    calm = now === text ? calm + 1 : 0;
    text = now;
  }
  await ctx.close();
  return { text, errors, drawn };
}
// --open: every closed <details> of #sso opened, every folded row clicked open (each row once while it stays drawn — a
// redrawn one again); a click that made the page's text shorter folded an open row: clicked back. Returns how many
// changed (rounds until nothing does, at most 4)
async function openFolds(page) {
  let all = 0;
  for (let round = 0; round < 4; round++) {
    const n = await page.evaluate(() => {
      const root = document.getElementById('sso') || document.body;
      let changed = 0;
      root.querySelectorAll('details:not([open])').forEach((d) => { d.open = true; changed++; });
      for (const r of root.querySelectorAll('.row.unfolds')) {
        if (!r.isConnected || r.dataset.usoOpened) continue;
        r.dataset.usoOpened = '1';
        const before = root.innerText.length;
        r.click();
        if (root.innerText.length < before && r.isConnected) r.click();
        else changed++;
        if (r.isConnected) r.dataset.usoOpened = '1';
      }
      return changed;
    });
    await page.waitForTimeout(n ? 150 : 0);
    all += n;
    if (!n) break;
  }
  return all;
}
// --replay: the readings fed to the page one by one as its live part «progress», its clock run forward to each; after
// each, the --value specs read off the page and judged against the reading's expect
async function replay(lang) {
  const lines = fs.readFileSync(opt.replay, 'utf8').split('\n').filter(Boolean)
    .map((l) => { try { return JSON.parse(l); } catch (e) { return null; } })
    .filter((x) => x && typeof x.t === 'number' && x.progress && typeof x.progress === 'object');
  const frame = readJson(path.join(opt.states, `${desk}-progress.json`)) || {};
  const out = { lang, n: 0, bad: 0, readings: lines.length, judged: [], errors: [], drawn: false };
  if (!lines.length) { out.errors.push('no readings with a progress'); return out; }
  const part = (x) => ({ ...frame, ok: undefined, time: x.t, real: true, progress: x.progress });
  harness.setState(`${desk}-progress`, part(lines[0]));
  const ctx = await browser.newContext({ viewport: { width: opt.width, height: 900 }, locale: lang === 'en' ? 'en-US' : lang });
  await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, opt.theme);
  const page = await ctx.newPage();
  page.on('pageerror', (e) => out.errors.push(`page error: ${String(e.message).slice(0, 200)}`));
  page.on('console', (m) => { if (m.type() === 'error') out.errors.push(m.text().slice(0, 200)); });
  await page.clock.install({ time: Math.round((lines[0].t - 2) * 1000) });
  await page.goto(`${harness.url}#/${opt.route}`, { waitUntil: 'load' });
  out.drawn = await page.waitForFunction((d) => window.Office && Office.current && Office.current.id === d
    && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), desk, { timeout: 15000 }).then(() => true, () => false);
  const pause = (ms) => new Promise((ok) => { setTimeout(ok, ms); });
  for (let i = 0; out.drawn && i < lines.length; i++) {
    const x = lines[i];
    harness.setState(`${desk}-progress`, part(x));
    const at = await page.evaluate(() => Date.now());
    const to = Math.round(x.t * 1000);
    if (to > at) await page.clock.runFor(to - at);
    await pause(250);                 // the poll's answer (a real request) in, the card drawn
    const text = await page.evaluate(() => (document.getElementById('sso') || document.body).innerText);
    const r = readValue(text, lang);
    const want = typeof x.expect === 'number' ? x.expect : null;
    if (!r || want === null) { out.judged.push({ t: x.t, shown: r ? shownText(r) : null, expect: want, ok: null }); continue; }
    const ok = valueOk(r, want);
    out.n++;
    if (!ok) out.bad++;
    out.judged.push({ t: x.t, shown: shownText(r), expect: want, ok });
  }
  harness.setState(`${desk}-progress`);
  await ctx.close();
  return out;
}
// how many greetings the desk has (greet.1 … greet.N in its en.json): that many drawings cover every pick
function greetSlots() {
  const j = readJson(path.join(PAGE, 'public', 'desks', desk, 'lang', 'en.json')) || {};
  let n = 0;
  while (j[`greet.${n + 1}`] !== undefined) n++;
  return n;
}
const greetNot = opt.not.filter((k) => /^[a-z][a-z0-9_-]*\.greet\.\d+$/.test(k));

if (opt.replay) {
  const lang = opt.langs[0];
  const r = await replay(lang);
  if (!r.drawn) { bad++; r.errors.unshift(`the desk «${desk}» did not draw`); }
  for (const e of new Set(r.errors)) console.error(`page ${opt.route} ${lang}: ${e}`);
  for (const j of r.judged) {
    console.error(`page ${opt.route} ${lang}: t=${j.t} ${j.shown === null ? 'none shown' : `shown ${j.shown}`}${j.expect === null ? '' : `, server ${j.expect}`}${j.ok === null ? '' : j.ok ? ' — ok' : ' — FAIL'}`);
  }
  const verdict = r.bad || !r.n ? 'FAIL' : 'ok  ';
  if (r.bad || !r.n) bad++;
  const worst = r.judged.filter((j) => j.ok === false)[0];
  console.error(`page ${opt.route} ${lang}: ${verdict} value ${opt.value.join(' | ')}: ${r.n ? `${r.n - r.bad} of ${r.n} readings within the leeway (×${opt.factor}, ${opt.slack})` : `never shown in ${r.readings} readings`}${worst ? `; first off: t=${worst.t} shown ${worst.shown}, server ${worst.expect}` : ''}`);
  pages.push({ lang, replay: r });
  await browser.close();
  await harness.close();
  if (opt.json) console.log(JSON.stringify({ route: opt.route, tree: opt.tree, pages }));
  process.exit(bad ? 1 : 0);
}
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
  // --value <spec>=<n>: the value shown against the server's number
  const values = [];
  if (valueSpecs.length && drawn) {
    const r = readValue(text, lang);
    const want = r ? r.spec.expect : null;
    const ok = !!r && (want === null || valueOk(r, want));
    values.push({ lang, shown: r ? shownText(r) : null, spec: r ? r.spec.spec : opt.value.join(' | '), expect: want, ok });
    if (!ok) bad++;
    console.error(`page ${opt.route} ${lang}: ${ok ? 'ok  ' : 'FAIL'} value ${r ? `${r.spec.key}:${r.spec.ph} shown ${shownText(r)}${want === null ? '' : `, server ${want}`}` : `${opt.value.join(' | ')}: not on the page`}`);
  }
  const clean = opt.clean && drawn ? cleanChecks(text, lang) : [];
  for (const c of clean) console.error(`page ${opt.route} ${lang}: FAIL clean ${c}`);
  if (opt.clean && drawn && !clean.length) console.error(`page ${opt.route} ${lang}: ok   clean`);
  bad += clean.length;
  pages.push({ lang, text, errors: [...new Set(errors)], checks, clean, values });
}
await browser.close();
await harness.close();
if (opt.json) console.log(JSON.stringify({ route: opt.route, tree: opt.tree, pages }));
else for (const p of pages) console.log(`===== ${opt.route} ${p.lang} =====\n${p.text}\n`);
process.exit(bad ? 1 : 0);
