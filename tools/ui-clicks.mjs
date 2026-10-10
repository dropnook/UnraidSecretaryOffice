#!/usr/bin/env node
// The click test: every desk page of the office, built from this repository's files (tests/ui/harness.mjs, a stub state
// per desk from tests/ui/states/), in a headless Chrome with a throwaway profile - both themes (Dark, Light) at 1440 and
// 375 px. On each page it clicks what only opens or closes something - every <summary>, every row that unfolds, every
// folding group head, every tile, every other control with aria-expanded - and checks:
//   - the clicked one changes state (opens or closes),
//   - nothing else that folds changes with it (another <details>, row, group, tile - a tile's siblings excepted:
//     tiles are one choice of several),
//   - it and everything else stay as they were when the desk draws itself anew right after the click (the messenger's
//     word, which every answer of the agent brings - also the answer to a post the click itself made),
//   - all of it stays as clicked after 1.5 s, and after the desk draws itself anew on its own (the minute's poll with a
//     newer look, and the messenger's word once more),
//   - what comes back into view (a tile's section shown again) shows as it was left,
//   - no console error, no page error, no horizontal scrolling (after the first paint and after each round of clicks).
// Two rounds: every candidate clicked once (opened or closed) - first what the page shows, then what that brought
// into view (a row's details, a group's rows), the tiles last -, then once more back, in reverse. What opens a dialog,
// a menu or another page isn't a toggle: closed again, counted as skipped. Actions that only post (buttons without
// aria-expanded) are never clicked. Many alike (rows of one list) are capped: 5 of a kind per section, 60 a page.
// Every page in a context of its own (a fresh profile: nothing remembered from another page).
//
//   node tools/ui-clicks.mjs [--only advisor,backup/setup] [--themes dark,light] [--widths 1440,375] [--jobs 6] [-v]
//   UC_DEBUG=1 …   prints every click and the state it left
//
// Needs node and playwright-core (USO_PLAYWRIGHT=<its folder>, else found in node's own paths or npx's cache) and a
// Chrome (USO_CHROME=<binary>, else Google Chrome in /Applications, else playwright's own Chromium).
// Exit: 0 all green, 1 a failure (the table says which), 2 wrong call, 3 playwright-core or Chrome missing (the caller
// may skip with a note - tools/ui-clicks.sh, tools/release.sh).
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..');
const require = createRequire(import.meta.url);

// ------------------------------------------------------------------ the call
const ROUTES = ['caretaker', 'backup', 'backup/setup', 'restore', 'snapshot', 'cleanup/tidy', 'cleanup/where', 'logs', 'watchman', 'advisor', 'emby', 'emby/setup'];
const opt = { only: null, themes: ['dark', 'light'], widths: [1440, 375], jobs: 6, verbose: false, perGroup: 5, perPage: 60 };
const args = process.argv.slice(2);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  const val = () => { if (i + 1 >= args.length) usage(); return args[++i]; };
  if (a === '--only') opt.only = val().split(',').filter(Boolean);
  else if (a === '--themes') opt.themes = val().split(',').filter((x) => ['dark', 'light'].includes(x));
  else if (a === '--widths') opt.widths = val().split(',').map(Number).filter((n) => n >= 320);
  else if (a === '--jobs') opt.jobs = Math.max(1, Number(val()) || 1);
  else if (a === '-v' || a === '--verbose') opt.verbose = true;
  else if (a === '-h' || a === '--help') usage(0);
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/ui-clicks.mjs [--only <route>,…] [--themes dark,light] [--widths 1440,375] [--jobs n] [-v]');
  console.error('routes: ' + ROUTES.join(' '));
  process.exit(code);
}
const routes = opt.only ? opt.only.map((r) => r.replace(/^#?\/?/, '')) : ROUTES;
if (!routes.length || !opt.themes.length || !opt.widths.length) usage();
for (const r of routes) {
  if (!/^[a-z][a-z0-9_-]*(\/[A-Za-z0-9:._-]+)*$/.test(r) || !fs.existsSync(path.join(REPO, 'public', 'desks', r.split('/')[0], 'desk.js'))) {
    console.error(`ui clicks: no desk page «${r}»`);
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
  console.log('ui clicks: playwright-core not found (USO_PLAYWRIGHT=<folder>, or `npx playwright-core --version` once) - not run');
  process.exit(3);
}
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p)) || (() => { try { const p = pw.chromium.executablePath(); return p && fs.existsSync(p) ? p : null; } catch (e) { return null; } })();
if (!chrome) {
  console.log('ui clicks: no Chrome found (USO_CHROME=<binary>) - not run');
  process.exit(3);
}

const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));

// ------------------------------------------------------------------ in the page
// Installed into every page: finds the candidates, gives each a key that survives the desk drawing itself anew (its
// place, its kind, its words without numbers, its rank among its likes), reads what is open.
const PAGE_HELPERS = () => {
  const desk = () => document.getElementById('sso-desk');
  const visible = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; };
  const words = (s) => String(s || '').toLowerCase().replace(/[^\p{L} ]+/gu, ' ').replace(/\s+/g, ' ').trim().slice(0, 40);
  function kindOf(e) {
    if (e.matches('details > summary')) return 'summary';
    if (e.matches('.unfolds')) return 'row';
    if (e.matches('.group-head')) return 'group';
    if (e.matches('button.card')) return 'tile';
    if (e.matches('[aria-expanded]')) return 'expand';
    return null;
  }
  function label(e, kind) {
    if (kind === 'row') return words((e.querySelector('.row-name, td, th') || e).textContent);
    if (kind === 'group') return words((e.querySelector('.group-title, .group-name, strong, span') || e).textContent);
    if (kind === 'expand') return (e.id || e.className || e.tagName).toString().slice(0, 40);
    return words(e.textContent);
  }
  function all() {
    const d = desk();
    const out = [];
    const ranks = new Map();
    d.querySelectorAll('details > summary, .unfolds, .group-head, button.card, [aria-expanded]').forEach((e) => {
      const kind = kindOf(e);
      if (!kind) return;
      // a control inside a row that unfolds is the row's own (its name, «Details»): the row stands for it
      if (kind === 'expand' && e.closest('.unfolds')) return;
      if (kind === 'expand' && e.closest('.group-head')) return;
      const place = e.closest('[data-place]');
      const base = `${kind}|${place ? place.dataset.place : ''}|${label(e, kind)}`;
      const n = (ranks.get(base) || 0) + 1;
      ranks.set(base, n);
      const section = e.closest('section, .section');
      const sectionPlace = section && section.querySelector('[data-place]');
      out.push({ e, kind, key: `${base}|${n}`, group: `${kind}|${sectionPlace ? sectionPlace.dataset.place : ''}` });
    });
    return out;
  }
  function stateOf(e, kind) {
    if (kind === 'summary') return e.parentElement.open ? 'open' : 'closed';
    if (kind === 'row') return e.classList.contains('open') || e.getAttribute('aria-expanded') === 'true'
      || !!e.querySelector(':scope [aria-expanded="true"]') ? 'open' : 'closed';
    if (kind === 'group') { const g = e.closest('.group'); return g && g.classList.contains('closed') ? 'closed' : 'open'; }
    if (kind === 'tile') return e.classList.contains('active') || e.getAttribute('aria-pressed') === 'true' ? 'on' : 'off';
    return e.getAttribute('aria-expanded') === 'true' ? 'open' : 'closed';
  }
  window.__uc = {
    list: () => all().filter((c) => visible(c.e) && !c.e.disabled && !c.e.closest('[disabled]'))
      .map(({ kind, key, group }) => ({ kind, key, group })),
    snap: () => Object.fromEntries(all().map((c) => [c.key, stateOf(c.e, c.kind)])),
    // mark the one to click (and where: a row's name, never its chips or buttons) - false when it isn't there
    mark(key) {
      document.querySelectorAll('[data-uc]').forEach((x) => x.removeAttribute('data-uc'));
      const c = all().find((x) => x.key === key);
      if (!c) return false;
      let t = c.e;
      if (c.kind === 'row') t = c.e.querySelector('.row-name') || c.e.querySelector('td, th') || c.e;
      if (c.kind === 'group') t = c.e.querySelector('.group-title, .group-name') || c.e;
      t.setAttribute('data-uc', '1');
      return true;
    },
    sibling(a, b) {          // two tiles of one choice: the same parent
      const list = all();
      const x = list.find((c) => c.key === a);
      const y = list.find((c) => c.key === b);
      return !!(x && y && x.kind === 'tile' && y.kind === 'tile' && x.e.parentElement === y.e.parentElement);
    },
    within(a, b) {           // b lies inside a's own part (an unfolded row's details, a group's rows, a details' body)
      const list = all();
      const x = list.find((c) => c.key === a);
      const y = list.find((c) => c.key === b);
      if (!x || !y) return false;
      const host = x.kind === 'summary' ? x.e.parentElement : x.kind === 'group' ? (x.e.closest('.group') || x.e) : x.e;
      return host !== y.e && host.contains(y.e);
    },
    scroll() {
      const w = document.documentElement.clientWidth;
      const sw = document.documentElement.scrollWidth;
      if (sw <= w + 1) return null;
      let worst = null;
      document.querySelectorAll('#sso *').forEach((e) => {
        const r = e.getBoundingClientRect();
        if (r.width && r.right > w + 1 && (!worst || r.right > worst.r)) worst = { r: r.right, what: `${e.tagName.toLowerCase()}.${String(e.className).split(' ').join('.')}` };
      });
      return `${sw} px wide in ${w} px${worst ? ` (${worst.what}, right edge ${Math.round(worst.r)})` : ''}`;
    },
    busy: () => !!(window.Office && (Office.dialogOpen() || Office.menuOpen() || (Office.paletteOpen && Office.paletteOpen()))),
  };
};

// ------------------------------------------------------------------ one page
async function runPage(browser, harness, route, theme, width) {
  const t0 = Date.now();
  const res = { route, theme, width, tested: 0, skipped: 0, capped: 0, hidden: 0, fails: [], ms: 0 };
  const fail = (what) => { if (res.fails.length < 40 && !res.fails.includes(what)) res.fails.push(what); };
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US', deviceScaleFactor: 1 });
  await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, theme);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => {
    const at = (/\/(desks\/[^?]+|assets\/[^?]+)\?[^:]*:(\d+)/.exec(String(e.stack || '')) || []).slice(1).join(':');
    errors.push(`page error: ${String(e.message).slice(0, 160)}${at ? ` (${at})` : ''}`);
  });
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  try {
    await page.goto(`${harness.url}#/${route}`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.Office && Office.current && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), null, { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1200);
    await page.evaluate(PAGE_HELPERS);
    const wide = await page.evaluate(() => __uc.scroll());
    if (wide) fail(`horizontal scrolling after the first paint: ${wide}`);

    // the candidates, capped per section and kind
    const per = new Map();
    const list = [];
    const known = new Set();
    const pick = (found) => {
      const picked = [];
      for (const c of found) {
        if (known.has(c.key)) continue;
        known.add(c.key);
        const n = (per.get(c.group) || 0) + 1;
        per.set(c.group, n);
        if (n > opt.perGroup || list.length + picked.length >= opt.perPage) { res.capped++; continue; }
        picked.push(c);
      }
      return picked;
    };
    const settle = async (ms) => { await page.waitForTimeout(ms); };
    // every key's state when last seen: what comes back into view (a tile's section shown again, a row unfolded
    // again) shows as it was left - the desk drew it anew, the user's choice stays
    const lastSeen = new Map();
    let shownBefore = new Set();
    const snap = async () => {
      const now = await page.evaluate(() => __uc.snap());
      for (const [k, v] of Object.entries(now)) {
        if (!shownBefore.has(k) && lastSeen.has(k) && lastSeen.get(k) !== v && clicked.has(k)) {
          fail(`${kindOf(k)} «${short(k)}» came back into view ${v}, was left ${lastSeen.get(k)}`);
        }
        lastSeen.set(k, v);
      }
      shownBefore = new Set(Object.keys(now));
      return now;
    };
    const clicked = new Set();
    const short = (key) => key.split('|').filter(Boolean).slice(0, 3).join(' · ');
    const kindOf = (key) => key.split('|')[0];
    let expected = null;      // the whole page's folding as the clicks left it
    const dropped = new Set(); // no toggle after all (a dialog, a menu, another page) or broken: not clicked again

    async function clickOne(c, round) {
      if (dropped.has(c.key)) return;
      const before = await snap();
      if (!(c.key in before)) { if (round === 2) res.hidden++; return; }     // hidden by another's click (a tile's section)
      if (!(await page.evaluate((k) => __uc.mark(k), c.key))) return;
      const hash = await page.evaluate(() => location.hash);
      try {
        await page.click('[data-uc]', { timeout: 3000 });
      } catch (e) {
        fail(`${c.kind} «${short(c.key)}»: could not be clicked (${String(e.message).split('\n')[0].slice(0, 80)})`);
        dropped.add(c.key);
        return;
      }
      await settle(250);
      // a dialog, a menu or another page: no toggle - put back, skipped
      const away = await page.evaluate((h) => location.hash.split('/')[1] !== h.split('/')[1], hash);
      if (await page.evaluate(() => __uc.busy()) || away) {
        await page.keyboard.press('Escape');
        await page.evaluate(() => { if (Office.dialogOpen() && Office.closeDialog) Office.closeDialog(); });
        if (away) { await page.evaluate((h) => { location.hash = h; }, hash); await settle(800); }
        await settle(200);
        dropped.add(c.key);
        res.skipped++;
        return;
      }
      const after = await snap();
      clicked.add(c.key);
      if (process.env.UC_DEBUG) console.log(`    [${route} ${theme} ${width}] round ${round} ${short(c.key)}: ${before[c.key]} → ${after[c.key]}`);
      if (round === 1) res.tested++;
      if (!(c.key in after)) {
        fail(`${c.kind} «${short(c.key)}»: gone after its click`);
        dropped.add(c.key);
        expected = after;
        return;
      }
      if (after[c.key] === before[c.key]) fail(`${c.kind} «${short(c.key)}» (${before[c.key]}): a click didn't change it - it stays ${after[c.key]}`);
      for (const k of Object.keys(after)) {
        if (k === c.key || !(k in before) || after[k] === before[k]) continue;
        // tiles are one choice: another one goes off when this one goes on
        if (c.kind === 'tile' && kindOf(k) === 'tile' && after[c.key] === 'on' && after[k] === 'off') continue;
        if (await page.evaluate(([a, b]) => __uc.sibling(a, b) || __uc.within(a, b), [c.key, k])) continue;
        fail(`${c.kind} «${short(c.key)}»: its click changed ${kindOf(k)} «${short(k)}» too (${before[k]} → ${after[k]})`);
      }
      // the desk draws itself anew right after (as at every answer of the agent, which brings the messenger's word)
      await page.evaluate(() => Office.setAgent({ ...Office.agent }));
      await settle(80);
      const redrawn = await snap();
      for (const [k, v] of Object.entries(after)) {
        if (!(k in redrawn) || redrawn[k] === v) continue;
        fail(k === c.key ? `${c.kind} «${short(c.key)}» (${v} by the click): ${redrawn[k]} again once the desk drew itself anew`
          : `${c.kind} «${short(c.key)}»: after its click the desk drew itself anew and ${kindOf(k)} «${short(k)}» went ${v} → ${redrawn[k]}`);
      }
      expected = redrawn;
    }
    async function afterRound(round) {
      if (!expected) return;
      await settle(1500);
      const later = await snap();
      for (const [k, v] of Object.entries(expected)) {
        if (k in later && later[k] !== v) fail(`round ${round}: ${kindOf(k)} «${short(k)}» was ${v}, 1.5 s later ${later[k]}`);
      }
      // what the desk does on its own: the messenger's word (every answer of the agent brings it) and the minute's poll
      await page.evaluate(() => { Office.setAgent({ ...Office.agent }); if (Office.current && Office.current.poll) Office.current.poll(); });
      await settle(900);
      const anew = await snap();
      for (const [k, v] of Object.entries(expected)) {
        if (k in anew && anew[k] !== v) fail(`round ${round}: ${kindOf(k)} «${short(k)}» was ${v}, ${anew[k]} after the desk drew itself anew`);
      }
      expected = anew;
      const w = await page.evaluate(() => __uc.scroll());
      if (w) fail(`horizontal scrolling after round ${round} of clicks: ${w}`);
    }

    // round 1: each opened or closed - then what that showed (a row's details, a group's rows) the same way; tiles last
    // (a tile shows another section in place of this one)
    const first = pick(await page.evaluate(() => __uc.list()));
    const tiles = first.filter((c) => c.kind === 'tile');
    const plain = first.filter((c) => c.kind !== 'tile');
    for (const c of plain) await clickOne(c, 1);
    const more = pick(await page.evaluate(() => __uc.list()));
    const inner = more.filter((x) => x.kind !== 'tile');
    for (const c of inner) await clickOne(c, 1);
    tiles.push(...more.filter((x) => x.kind === 'tile'));
    await afterRound(1);
    for (const c of tiles) await clickOne(c, 1);
    if (tiles.length) await afterRound(1);
    // round 2: back again - the tiles first (this section again), then the inner ones, then the rest
    for (const c of [...tiles].reverse()) await clickOne(c, 2);
    if (tiles.length) await afterRound(2);
    for (const c of [...plain, ...inner].reverse()) await clickOne(c, 2);
    await afterRound(2);
  } catch (e) {
    fail(`the run broke off: ${String(e.message).split('\n')[0].slice(0, 160)}`);
  }
  // a log dialog (status line + a long log) fits the window at every text size: title and «Close» in sight
  // (1.55.0 pushed «Close» below a short window - 2026-10-10); once per theme and width, on one page
  if (route === 'emby') {
    try {
      await page.setViewportSize({ width, height: 650 });
      for (const size of ['', 'medium', 'large']) {
        const r = await page.evaluate((size) => {
          const root = document.getElementById('sso');
          if (size) root.dataset.size = size; else delete root.dataset.size;
          const box = document.createElement('div');
          const st = document.createElement('p');
          st.textContent = 'status line';
          const pre = document.createElement('pre');
          pre.className = 'code';
          pre.textContent = Array.from({ length: 400 }, (_, i) => 'line ' + i).join('\n');
          box.append(st, pre);
          const d = Office.dialog({ title: 'log', body: box, wide: 'log' });
          const a = document.getElementById('sso-dialog').getBoundingClientRect();
          const f = document.getElementById('sso-dialog-foot').getBoundingClientRect();
          d.close();
          delete root.dataset.size;
          return { top: a.top, bottom: f.bottom, vh: innerHeight };
        }, size);
        if (r.top < 0 || r.bottom > r.vh + 1) fail(`log dialog cut at text size ${size || 'small'} (top ${Math.round(r.top)}, buttons end ${Math.round(r.bottom)} of ${r.vh})`);
      }
    } catch (e) {
      fail(`log dialog check: ${String(e.message).split('\n')[0].slice(0, 120)}`);
    }
  }
  for (const e of [...new Set(errors)].slice(0, 5)) fail(`console: ${e}`);
  await ctx.close().catch(() => {});
  res.ms = Date.now() - t0;
  return res;
}

// ------------------------------------------------------------------ all of them
const started = Date.now();
const harness = await startHarness();
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check'] });
const jobs = [];
for (const route of routes) for (const theme of opt.themes) for (const width of opt.widths) jobs.push({ route, theme, width });
const results = [];
let next = 0;
await Promise.all(Array.from({ length: Math.min(opt.jobs, jobs.length) }, async () => {
  while (next < jobs.length) {
    const j = jobs[next++];
    const r = await runPage(browser, harness, j.route, j.theme, j.width);
    results.push(r);
    if (opt.verbose) console.log(`  ${r.fails.length ? 'FAIL' : 'ok  '} ${j.route} ${j.theme} ${j.width} (${(r.ms / 1000).toFixed(1)} s)`);
  }
}));
await browser.close();
await harness.close();

// ------------------------------------------------------------------ the table
results.sort((a, b) => routes.indexOf(a.route) - routes.indexOf(b.route) || opt.themes.indexOf(a.theme) - opt.themes.indexOf(b.theme) || b.width - a.width);
const pad = (s, n) => String(s).padEnd(n);
console.log(`${pad('page', 15)}${pad('theme', 7)}${pad('width', 7)}${pad('clicked', 9)}${pad('skipped', 9)}${pad('capped', 8)}result`);
for (const r of results) {
  console.log(`${pad(r.route, 15)}${pad(r.theme, 7)}${pad(r.width, 7)}${pad(r.tested, 9)}${pad(r.skipped, 9)}${pad(r.capped, 8)}${r.fails.length ? `FAIL (${r.fails.length})` : 'ok'}`);
}
const failed = results.filter((r) => r.fails.length);
for (const r of failed) {
  console.log(`\n${r.route} ${r.theme} ${r.width}:`);
  for (const f of r.fails) console.log(`  - ${f}`);
}
const clicks = results.reduce((s, r) => s + r.tested, 0);
console.log(`\nui clicks: ${results.length} pages, ${clicks} toggles clicked twice, ${failed.length} failed - ${((Date.now() - started) / 1000).toFixed(0)} s`);
process.exit(failed.length ? 1 : 0);
