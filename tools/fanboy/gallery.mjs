#!/usr/bin/env node
// Fanboy's gallery of fans (desk.js lookGallery(), 2026-11-20, the owner: «das komplette Übersichtsbild als Vorschau
// öffnen, um alle zu sehen, bevor man über das Dropdown auswählt») clicked through in the office's test harness with the
// invented demo states, in a headless Chrome with a throwaway profile, at 1440 and 375 px, black and white theme:
//   - the set-up's output 5 opened, «Alle ansehen…» in its look panel opens a dialog inside #sso;
//   - every catalogue entry is there, by maker, the fan's current model marked;
//   - nothing turns until a wheel is pointed at; then that one alone;
//   - the size filter shows only that size; «all» shows them all again;
//   - a click on another fan chooses it (the model select shows it, the panel says «Von dir …» or the proposal) and closes;
//   - with the dialog open the page's size and scroll never change (frames.mjs sizeChecks);
//   - pictures with --shots <folder> (black and white, 1440 and 375).
// Dev-only.  node tools/fanboy/gallery.mjs [--shots <folder>] [-v]     Exit 1 on a failed check, 3: no Chrome.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { sizeChecks, sizeVerdicts } from './frames.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);
const args = process.argv.slice(2);
const opt = { shots: null, v: false };
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--shots') opt.shots = path.resolve(args[++i]);
  else if (args[i] === '-v') opt.v = true;
  else { console.log(`fanboy gallery: unknown argument ${args[i]}`); process.exit(2); }
}
const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
try { for (const d of fs.readdirSync(path.join(os.homedir(), '.npm', '_npx'))) tries.push(path.join(os.homedir(), '.npm', '_npx', d, 'node_modules', 'playwright-core')); } catch (e) { /* none */ }
let pw = null;
for (const t of tries.filter(Boolean)) { try { pw = require(t); break; } catch (e) { /* the next */ } }
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => p && fs.existsSync(p));
if (!pw || !pw.chromium || !chrome) { console.log('fanboy gallery: playwright-core or Chrome missing - not run'); process.exit(3); }
const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
const catalog = JSON.parse(fs.readFileSync(path.join(REPO, 'public', 'desks', 'fanboy', 'fans-catalog.json'), 'utf8')).fans;
const failed = [];
const check = (what, ok, detail) => { if (!ok) failed.push(`${what}${detail ? ` — ${detail}` : ''}`); else if (opt.v) console.log(`ok  ${what}`); };
if (opt.shots) fs.mkdirSync(opt.shots, { recursive: true });

const browser = await pw.chromium.launch({ executablePath: chrome, headless: true, args: ['--no-first-run'] });
const h = await startHarness({ demo: true, alias: { fanboy: 'fanboy-uxfound' } });
try {
  for (const [theme, width] of [['black', 1440], ['white', 1440], ['black', 375], ['white', 375]]) {
    const tag = `${theme} ${width}`;
    const phone = width < 768;
    const ctx = await browser.newContext({ viewport: { width, height: phone ? 844 : 900 }, deviceScaleFactor: opt.shots ? 2 : 1, isMobile: phone, hasTouch: phone,
      locale: 'de-CH', colorScheme: theme === 'white' ? 'light' : 'dark' });
    await ctx.addInitScript(() => { try { localStorage.setItem('office.lang', 'de'); } catch (e) { /* none */ } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(`${h.url}?theme=${theme}#/fanboy/setup/test`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.Office && window.FanArt && document.querySelector('#sso-desk .fb-item[data-row$=pwm5]'), null, { timeout: 15000 });
    await page.click('#sso-desk .fb-item[data-row$=pwm5]');
    await page.waitForSelector('#sso-desk .fb-gallery-open', { timeout: 8000 });
    const before = await page.evaluate(() => document.querySelector('#sso-desk .fan-pick select:nth-of-type(1)') && [...document.querySelectorAll('#sso-desk .fan-pick select')].map((s) => s.value));
    await page.click('#sso-desk .fb-gallery-open');
    await page.waitForSelector('#sso .fb-gallery-item', { timeout: 8000 });
    await page.mouse.move(1, 1);             // the pointer off the wheels (it rested where «Alle ansehen…» was)
    await page.waitForTimeout(1500);
    const seen = await page.evaluate(() => {
      const dlg = document.querySelector('#sso-dialog');
      const items = [...document.querySelectorAll('#sso .fb-gallery-item')];
      return { inSso: !!dlg && !!dlg.closest('#sso'), n: items.length, makers: document.querySelectorAll('#sso .fb-gallery-brand').length,
        marked: items.filter((b) => b.getAttribute('aria-pressed') === 'true').map((b) => b.dataset.fan),
        running: document.getAnimations().filter((a) => a.playState === 'running' && a.effect && a.effect.target && a.effect.target.closest('.fb-gallery')).length };
    });
    check(`${tag}: the gallery opens inside #sso with every catalogue entry (${catalog.length}), by maker`, seen.inSso && seen.n === catalog.length && seen.makers === new Set(catalog.map((f) => f.brand)).size, JSON.stringify(seen));
    check(`${tag}: the fan's current model marked (the demo's output 5: NF-A12x25 PWM)`, seen.marked.length === 1 && seen.marked[0] === 'noctua-nf-a12x25-pwm', JSON.stringify(seen.marked));
    check(`${tag}: nothing turns while no wheel is pointed at`, seen.running === 0, String(seen.running));
    if (opt.shots) await page.screenshot({ path: path.join(opt.shots, `gallery-${theme}-de-${width}.png`) });
    // the work it costs: the page's main thread over 5 s, the gallery open and still, then with one wheel pointed at
    if (theme === 'black' && width === 1440) {
      const cdp = await ctx.newCDPSession(page);
      await cdp.send('Performance.enable');
      const busy = async () => {
        const m = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((x) => [x.name, x.value]));
        const a = await m(), t0 = Date.now();
        await page.waitForTimeout(5000);
        const z = await m();
        return (100 * (z.TaskDuration - a.TaskDuration) / ((Date.now() - t0) / 1000)).toFixed(2);
      };
      const still = await busy();
      await page.hover('#sso .fb-gallery-item[data-fan="arctic-p12-pwm-pst"]');
      const one = await busy();
      await page.mouse.move(1, 1);
      await page.waitForTimeout(400);
      console.log(`fanboy gallery: main thread with the gallery open — still ${still} %, one wheel pointed at ${one} %`);
    }
    // the one pointed at turns, alone
    if (!phone) {
      await page.hover('#sso .fb-gallery-item[data-fan="noctua-nf-a8-pwm"]');
      await page.waitForTimeout(1200);
      const turning = await page.evaluate(() => [...new Set(document.getAnimations().filter((a) => a.playState === 'running' && a.effect && a.effect.target && a.effect.target.closest('.fb-gallery-item'))
        .map((a) => a.effect.target.closest('.fb-gallery-item').dataset.fan))]);
      check(`${tag}: pointed at, that wheel alone turns`, turning.length === 1 && turning[0] === 'noctua-nf-a8-pwm', JSON.stringify(turning));
    }
    // the page's size and scroll stay with the dialog open (and a wheel turning in it)
    for (const [what, ok, detail] of sizeVerdicts(`${tag} gallery open`, await sizeChecks(page, 2))) check(what, ok, detail);
    // the size filter
    const sizes = await page.evaluate(() => [...document.querySelectorAll('#sso .fb-gallery-sizes button')].map((b) => b.textContent));
    await page.locator('#sso .fb-gallery-sizes button').nth(1).click();          // 80 mm
    await page.waitForTimeout(300);
    const only80 = await page.evaluate(() => [...document.querySelectorAll('#sso .fb-gallery-item')].filter((b) => !b.hidden && b.getClientRects().length).map((b) => b.dataset.fan));
    const want80 = catalog.filter((f) => f.mm === 80).map((f) => f.id).sort();
    check(`${tag}: the filter «80 mm» shows the 80 mm fans alone (${want80.length})`, JSON.stringify([...only80].sort()) === JSON.stringify(want80), `${sizes.join(' | ')} → ${only80.length}`);
    if (opt.shots && width === 1440 && theme === 'black') await page.screenshot({ path: path.join(opt.shots, `gallery-80mm-${theme}-de-${width}.png`) });
    await page.locator('#sso .fb-gallery-sizes button').nth(0).click();          // all again
    await page.waitForTimeout(300);
    const allAgain = await page.evaluate(() => [...document.querySelectorAll('#sso .fb-gallery-item')].filter((b) => !b.hidden).length);
    check(`${tag}: «Alle» shows them all again`, allAgain === catalog.length, String(allAgain));
    // a click chooses and closes
    await page.click('#sso .fb-gallery-item[data-fan="lian-li-uni-fan-sl-v2-120"]');
    await page.waitForTimeout(700);
    const after = await page.evaluate(() => ({ open: !document.querySelector('#sso-dialog-backdrop').hidden,
      selects: [...document.querySelectorAll('#sso-desk .fan-pick select')].map((s) => s.value), title: (document.querySelector('#sso-desk .fb-look-top') || {}).textContent || '' }));
    check(`${tag}: a click chooses it — the selects show Lian Li UNI FAN SL V2 120 — and closes the gallery`,
      !after.open && after.selects.includes('Lian Li') && after.selects.includes('lian-li-uni-fan-sl-v2-120'), JSON.stringify({ before, after: after.selects, open: after.open }));
    if (opt.shots) await page.locator('#sso-desk .fb-look-top').first().screenshot({ path: path.join(opt.shots, `gallery-chosen-${theme}-de-${width}.png`) });
    check(`${tag}: no page error`, !errors.length, errors.join(' | ').slice(0, 300));
    await ctx.close();
  }
} finally {
  await browser.close();
  if (h.close) await h.close();
}
if (failed.length) {
  console.log(`fanboy gallery: ${failed.length} failed`);
  failed.forEach((f) => console.log(`  ✗ ${f}`));
  process.exit(1);
}
console.log('fanboy gallery: all checks passed');
