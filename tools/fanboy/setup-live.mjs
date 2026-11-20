#!/usr/bin/env node
// Step 2 of Fanboy's setup («Test and assign», public/desks/fanboy/desk.js benchMake()/panelMake()) while a test runs —
// the owner's first real set-up (2026-11-20): «the whole page jumps all the time», and the test ran on to output 3 while
// he was still telling it about the CPU cooler. In the office's test harness (tests/ui/harness.mjs) with the invented
// demo state tests/ui/demo/fanboy-uxrun.json (a quiet test, output 3 at full), in a headless Chrome with a throwaway
// profile. Each scene: he types in a fan's name field, then three looks of the running step arrive (every 2 s, as the
// agent's) — what he types into keeps its focus and caret, the field does not move on the screen, the page's scroll
// position does not move:
//   - typing   output 3 under test, he names it while it runs: the looks bring new speeds, his name stored, the ceiling's
//              seconds going down;
//   - found    he answered output 3 and went back to output 1 (the CPU cooler) to name it: the looks bring output 3
//              ending, output 4 at full, new speeds — the panel stays output 1's, the running one is a click away;
//   - reading  as «found», but he only clicked output 1's row (no field): the panel stays his all the same.
// Also: the running output's panel says it waits for his answer (no «moves on»), and the strip counts down to the pause,
// not to a next output; the places offered per role (a CPU cooler «on the CPU» first), the look's picker open on the
// setup tab, a CPU cooler on the CPU drawn among its zone's heat sources. Dev-only.
//
//   node tools/fanboy/setup-live.mjs [--width 1440|375] [-v]
// Exit 1 on a failed check, 3: playwright-core or Chrome missing.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);
const args = process.argv.slice(2);
const opt = { widths: [1440, 375], v: false };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--width') opt.widths = [Number(args[++i])];
  else if (a === '-v') opt.v = true;
  else { console.log(`fanboy setup-live: unknown argument ${a}`); process.exit(2); }
}
function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try { for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core')); } catch (e) { /* no npx cache */ }
  for (const t of tries.filter(Boolean)) { try { return require(t); } catch (e) { /* the next */ } }
  return null;
}
const pw = findPlaywright();
if (!pw || !pw.chromium) { console.log('fanboy setup-live: playwright-core not found (USO_PLAYWRIGHT=<folder>) - not run'); process.exit(3); }
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p));
if (!chrome) { console.log('fanboy setup-live: no Chrome found (USO_CHROME=<binary>) - not run'); process.exit(3); }

const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
const lang = (code) => JSON.parse(fs.readFileSync(path.join(REPO, 'public', 'desks', 'fanboy', 'lang', `${code}.json`), 'utf8'));
const EN = lang('en');
const failed = [];
const check = (what, ok, detail) => { if (!ok) failed.push(`${what}${detail ? ` — ${detail}` : ''}`); else if (opt.v) console.log(`ok  ${what}`); };
const clone = (x) => JSON.parse(JSON.stringify(x));
/** every `rpm` in the state a little different (a look's new speeds) */
const wobble = (v, d) => {
  if (Array.isArray(v)) return v.map((x) => wobble(x, d));
  if (!v || typeof v !== 'object') return v;
  const out = {};
  for (const [k, x] of Object.entries(v)) out[k] = k === 'rpm' && typeof x === 'number' && x > 0 ? x + d : wobble(x, d);
  return out;
};

const browser = await pw.chromium.launch({ executablePath: chrome, headless: true, args: ['--no-first-run'] });
/** Step 2 with the running quiet test (output 3 at full, unnamed yet); the base state as the harness serves it */
async function open(width) {
  const h = await startHarness({ demo: true, alias: { fanboy: 'fanboy-uxrun' } });
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1, isMobile: width < 768, hasTouch: width < 768, colorScheme: 'dark' });
  await ctx.addInitScript(() => { try { localStorage.setItem('office.lang', 'en'); localStorage.removeItem('office.theme'); } catch (e) { /* none */ } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(`${h.url}?theme=black#/fanboy/setup/test`, { waitUntil: 'load' });
  await page.waitForSelector('#sso .fb-panel', { timeout: 15000 });
  await page.evaluate(async () => { await document.fonts.ready; });
  const api = await page.evaluate(() => document.querySelector('script[src*="assets/core.js"]').src.replace(/assets\/core\.js.*$/, 'api.php'));
  const base = (await (await fetch(`${api}?a=state&desk=fanboy`)).json()).state;
  const P = (n) => base.setup.outputs[n - 1];
  // output 3 has no name yet: he gives it one while it runs; the agent of 2026-11-20 says it waits for the answer
  base.setup.fans = base.setup.fans.map((f) => (f.id === P(3) ? { ...f, name: '' } : f));
  base.step.progress = { ...base.step.progress, waits: true, until: base.time + 170 };
  h.setState('fanboy', base);
  await page.waitForTimeout(2600);
  return { h, ctx, page, errors, base, P };
}
/** Where he is: the focused element (and its caret), its place on the screen, the scroll position, the panel shown */
const where = (page) => page.evaluate(() => {
  const a = document.activeElement;
  const r = a ? a.getBoundingClientRect() : null;
  const p = document.querySelector('#sso .fb-panel-slot .fb-panel');
  return { tag: a ? a.tagName : '', panel: p ? p.dataset.fan : '', keep: !!(a && a.dataset && a.dataset.mark), value: a && 'value' in a ? a.value : null,
    caret: a && typeof a.selectionStart === 'number' ? a.selectionStart : null, top: r ? Math.round(r.top) : null, y: Math.round(window.scrollY),
    panelTop: p ? Math.round(p.getBoundingClientRect().top) : null };
});
/** Three looks of the running step (each a new state, the page looks every 2 s); after each: nothing of his moved */
async function looks(page, h, states, what, field) {
  const was = await where(page);
  for (let i = 0; i < states.length; i++) {
    h.setState('fanboy', states[i]);
    await page.waitForTimeout(2600);
    const now = await where(page);
    const tag = `${what}, look ${i + 1}`;
    if (field) {
      check(`${tag}: the field keeps its focus and caret`, now.keep && now.value === was.value && now.caret === was.caret, JSON.stringify({ was, now }));
      check(`${tag}: the field does not move on the screen`, now.top !== null && Math.abs(now.top - was.top) <= 1, `${was.top} → ${now.top}`);
    }
    check(`${tag}: the panel stays the one he chose`, now.panel === was.panel, `${was.panel} → ${now.panel}`);
    check(`${tag}: the panel does not move on the screen`, Math.abs(now.panelTop - was.panelTop) <= 1, `${was.panelTop} → ${now.panelTop}`);
    check(`${tag}: the scroll position does not move`, Math.abs(now.y - was.y) <= 1, `${was.y} → ${now.y}`);
  }
}
/** Put a field of the shown panel in the middle of the screen, focus it, type */
async function typeInto(page, sel, text) {
  await page.evaluate((s) => { const n = document.querySelector(s); n.dataset.mark = '1'; n.scrollIntoView({ block: 'center' }); }, sel);
  await page.waitForTimeout(200);
  await page.click(sel);
  await page.keyboard.type(text, { delay: 30 });
}

try {
  for (const width of opt.widths) {
    // ------------------------------------------------------------------ typing: he names output 3 while it runs
    {
      const { h, ctx, page, errors, base, P } = await open(width);
      const panel = `#sso .fb-panel[data-fan="${P(3)}"]`;
      check(`${width}: output 3's panel shown while it runs`, (await where(page)).panel === P(3), '');
      const line = await page.evaluate((s) => (document.querySelector(s) || {}).textContent, `${panel} .fb-panel-line`);
      check(`${width}: its line says it stays at 100 % until he answers`, line === EN['panel.now'], line);
      const strip = await page.evaluate(() => (document.querySelector('#sso .fb-strip-since') || {}).textContent);
      check(`${width}: the strip counts down to the pause, not to a next output`, /^waits for your answer/.test(strip), strip);
      await typeInto(page, `${panel} .fb-field-name input`, 'Front lo');
      await page.waitForTimeout(900);         // his name saved (700 ms after the last key)
      const s1 = wobble(clone(base), 7);
      const s2 = wobble(clone(base), -11);
      s2.setup.fans = s2.setup.fans.map((f) => (f.id === P(3) ? { ...f, name: 'Front lo' } : f));
      const s3 = wobble(clone(s2), 23);
      s3.step.at = base.step.at + 6;
      await looks(page, h, [s1, s2, s3], `${width} typing`, true);
      check(`${width} typing: no error, no horizontal scroll`, !errors.length && !(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), errors.join(' | '));
      await ctx.close();
      if (h.close) await h.close();
    }
    // ------------------------------------------------- found: output 3 answered, he names output 1 while 4 comes on
    for (const how of ['found', 'reading']) {
      const { h, ctx, page, errors, base, P } = await open(width);
      await page.click(`#sso .fb-item[data-row="${P(1)}"]`);
      await page.waitForTimeout(300);
      const panel = `#sso .fb-panel[data-fan="${P(1)}"]`;
      if (how === 'found') await typeInto(page, `${panel} .fb-field-name input`, ' oben');
      else await page.evaluate((s) => { const n = document.querySelector(s); n.scrollIntoView({ block: 'center' }); }, `${panel} .fb-field-name input`);
      await page.waitForTimeout(how === 'found' ? 900 : 300);
      const t = base.time;
      const s1 = wobble(clone(base), 5);
      s1.step.progress = { id: P(3), phase: 'pause', quiet: true, until: t + 8, next: P(4) };
      s1.step.done = [P(1), P(2), P(3)];
      s1.step.results[P(3)] = { found: 'tach', tachs: [3] };
      const s2 = wobble(clone(s1), 9);
      s2.step.current = P(4);
      s2.step.progress = { id: P(4), phase: 'full', quiet: true, waits: true, until: t + 190 };
      const s3 = wobble(clone(s2), -4);
      s3.step.at = t + 12;
      await looks(page, h, [s1, s2, s3], `${width} ${how}`, how === 'found');
      // the running one is a click away: its row says it runs, and his panel names it with a way there
      const go = await page.$(`${panel} .fb-panel-why .fb-link`);
      check(`${width} ${how}: output 1's panel names the running output 4 with a link there`, !!go,
        await page.evaluate((s) => (document.querySelector(s) || { textContent: '(output 1\'s panel is gone)' }).textContent, `${panel} .fb-panel-why`));
      if (go) {
        await go.click();
        await page.waitForTimeout(300);
        check(`${width} ${how}: … the link shows output 4's panel`, (await where(page)).panel === P(4), '');
      }
      check(`${width} ${how}: no error, no horizontal scroll`, !errors.length && !(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), errors.join(' | '));
      await ctx.close();
      if (h.close) await h.close();
    }
  }
  // ------------------------------------------------ the places and the look (2026-11-20, the owner's points 3 and 4)
  for (const width of opt.widths) {
    const h = await startHarness({ demo: true, alias: { fanboy: 'fanboy-uxfound' } });
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, isMobile: width < 768, hasTouch: width < 768, colorScheme: 'dark' });
    await ctx.addInitScript(() => { try { localStorage.setItem('office.lang', 'en'); } catch (e) { /* none */ } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(`${h.url}?theme=black#/fanboy/setup/test`, { waitUntil: 'load' });
    await page.waitForSelector('#sso .fb-panel', { timeout: 15000 });
    await page.waitForTimeout(800);
    const panelOf = (n) => page.evaluate((k) => {
      const p = document.querySelector(`#sso .fb-panel[data-fan$="pwm${k}"]`);
      const sel = p && p.querySelector('.fb-fields select');
      const look = p && p.querySelector('.fb-look-open');
      return { opts: sel ? [...sel.options].map((o) => o.value) : [], open: !!look && look.offsetHeight > 0 && !!look.querySelector('.fan-pick'), fold: !!(p && p.querySelector('details.fb-fold .fan-pick')) };
    }, n);
    await page.click('#sso .fb-item[data-row$="pwm1"]');
    await page.waitForTimeout(300);
    const cpu = await panelOf(1);
    check(`${width} places: the CPU cooler offers «on the CPU» first`, cpu.opts[1] === 'cpu' && cpu.opts.includes('inside'), JSON.stringify(cpu.opts));
    check(`${width} look: the look's picker shown open, in no fold`, cpu.open && !cpu.fold, JSON.stringify(cpu));
    await page.click('#sso .fb-item[data-row$="pwm4"]');
    await page.waitForTimeout(300);
    const cage = await panelOf(4);
    check(`${width} places: the disk cage a wall first, never «on the CPU», «inside» offered`, cage.opts[1] === 'front' && !cage.opts.includes('cpu') && cage.opts.includes('inside'), JSON.stringify(cage.opts));
    check(`${width} places: no error`, !errors.length, errors.join(' | '));
    await ctx.close();
    if (h.close) await h.close();
    // the picture: a CPU cooler on the CPU sits among the heat sources of its zone
    const h2 = await startHarness({ demo: true, alias: { fanboy: 'fanboy-cpu' } });
    const ctx2 = await browser.newContext({ viewport: { width, height: 900 }, isMobile: width < 768, hasTouch: width < 768, colorScheme: 'dark' });
    const page2 = await ctx2.newPage();
    await page2.goto(`${h2.url}?theme=black#/fanboy`, { waitUntil: 'load' });
    await page2.waitForSelector('#sso .fan-server .fan-zone', { timeout: 15000 });
    await page2.waitForTimeout(800);
    const inner = await page2.evaluate(() => [...document.querySelectorAll('#sso .fan-server .fan-zone')].map((z) => ({ id: z.dataset.id, inner: z.querySelectorAll('.fan-heat .fan-inner .fan-wheel').length })));
    check(`${width} picture: the CPU zone's cooler drawn among its heat sources`, inner.some((z) => z.id === 'cpu' && z.inner >= 1) && inner.filter((z) => z.id !== 'cpu').every((z) => z.inner === 0), JSON.stringify(inner));
    check(`${width} picture: no horizontal scroll`, !(await page2.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), '');
    await ctx2.close();
    if (h2.close) await h2.close();
  }
} finally {
  await browser.close();
}
if (failed.length) {
  console.log(`fanboy setup-live: ${failed.length} failed`);
  failed.forEach((f) => console.log(`  ✗ ${f}`));
  process.exit(1);
}
console.log('fanboy setup-live: all checks passed');
