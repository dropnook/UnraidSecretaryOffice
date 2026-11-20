#!/usr/bin/env node
// The zone editor's heat sources (public/desks/fanboy/desk.js zoneSources(), 2026-11-20) in the office's test harness
// (tests/ui/harness.mjs) with the invented demo state tests/ui/demo/fanboy-uxmany.json (70 heat sources; the disk cage
// takes 28, the case NVMe and RAM — RAM on a curve of its own —, «Zone 3» has nothing yet), in a headless Chrome with a
// throwaway profile. Checks, then pictures:
//   - every kind is a block of its own, folded to one line while the zone has sources; each chosen kind's line says how
//     many, the warmest of the chosen ones as the state has it (a sleeping disk never the warmest, never a temperature),
//     how many sleep, the curve it follows in the curve cards' words; a kind without a choice says so, quietly;
//   - the line's control unfolds that kind's chips in place (aria-expanded, aria-controls) and folds them again; what is
//     open stays open when the page draws itself anew (a chip clicked: the zone changes and the page redraws);
//   - with several kinds the line's curve word brings that kind's card in front; a zone without any source is open,
//     with the line that it needs one, and stays open while the first are chosen;
//   - no horizontal scroll at 375 px, no console or page error.
// Dev-only.
//
//   node tools/fanboy/zones.mjs [--out <folder>] [--no-shots] [-v]
// Pictures: <out>/zone-sources-<scene>-<theme>-<lang>-<width>.png. Exit 1 on a failed check, 3: playwright-core or Chrome missing.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);
const args = process.argv.slice(2);
const opt = { out: path.join(process.env.USO_SHOTS || path.join(os.homedir(), 'uso-shots'), 'fanboy-zones'), shots: true, v: false };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--out') opt.out = path.resolve(args[++i]);
  else if (a === '--no-shots') opt.shots = false;
  else if (a === '-v') opt.v = true;
  else { console.log(`fanboy zones: unknown argument ${a}`); process.exit(2); }
}
function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try { for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core')); } catch (e) { /* no npx cache */ }
  for (const t of tries.filter(Boolean)) { try { return require(t); } catch (e) { /* the next */ } }
  return null;
}
const pw = findPlaywright();
if (!pw || !pw.chromium) { console.log('fanboy zones: playwright-core not found (USO_PLAYWRIGHT=<folder>) - not run'); process.exit(3); }
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p));
if (!chrome) { console.log('fanboy zones: no Chrome found (USO_CHROME=<binary>) - not run'); process.exit(3); }

const { startHarness, DEMO_NAMES_EN } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
const STATE = JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'demo', 'fanboy-uxmany.json'), 'utf8'));
const lang = (code) => JSON.parse(fs.readFileSync(path.join(REPO, 'public', 'desks', 'fanboy', 'lang', `${code}.json`), 'utf8'));
const EN = lang('en');
const failed = [];
const check = (what, ok, detail) => { if (!ok) failed.push(`${what}${detail ? ` — ${detail}` : ''}`); else if (opt.v) console.log(`ok  ${what}`); };

const browser = await pw.chromium.launch({ executablePath: chrome, headless: true, args: ['--no-first-run'] });
/** The zones step of the setup with the big demo state, the zone given picked */
async function open(c, zone) {
  const h = await startHarness({ demo: true, alias: { fanboy: 'fanboy-uxmany' }, names: c.lang === 'de' ? null : DEMO_NAMES_EN });
  const ctx = await browser.newContext({ viewport: { width: c.width, height: 1000 }, deviceScaleFactor: 2, isMobile: c.width < 768, hasTouch: c.width < 768,
    colorScheme: c.theme === 'white' ? 'light' : 'dark' });
  await ctx.addInitScript((l) => { try { localStorage.setItem('office.lang', l); localStorage.removeItem('office.theme'); } catch (e) { /* none */ } }, c.lang);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(`${h.url}?theme=${c.theme}#/fanboy/setup/zones`, { waitUntil: 'load' });
  await page.waitForSelector('#sso .fb-zone-side .fb-item', { timeout: 15000 });
  if (zone) { await page.click(`#sso .fb-zone-side .fb-item[data-row="${zone}"]`); await page.waitForTimeout(300); }
  await page.waitForSelector('#sso .fb-sources');
  await page.evaluate(async () => { await document.fonts.ready; });
  return { h, ctx, page, errors };
}
const blocks = (page) => page.evaluate(() => [...document.querySelectorAll('#sso .fb-sources .fb-src-block')].map((b) => {
  const t = b.querySelector('.fb-src-toggle');
  const chips = b.querySelector('.fb-src-chips');
  return { kind: b.dataset.kind, has: b.classList.contains('fb-src-has'), expanded: t.getAttribute('aria-expanded'), controls: t.getAttribute('aria-controls') === chips.id,
    hidden: chips.hidden, sum: b.querySelector('.fb-src-sum').textContent, nums: b.querySelector('.fb-src-sum').dataset.nums || '',
    curve: (b.querySelector('.fb-src-curve, .fb-src-curve-text') || {}).textContent || null, link: !!b.querySelector('button.fb-src-curve'), chips: chips.querySelectorAll('.fb-src').length,
    on: chips.querySelectorAll('.fb-src.on').length };
}));
const wide = (page) => page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);

try {
  // ---------------------------------------------------------------------------------------------- the checks (en, 1440)
  {
    const c = { theme: 'black', lang: 'en', width: 1440 };
    const { h, ctx, page, errors } = await open(c, 'disks');
    let b = await blocks(page);
    const disk = b.find((x) => x.kind === 'disk');
    const zone = STATE.setup.zones.find((z) => z.id === 'disks');
    const chosen = STATE.setup.sources.filter((s) => zone.sources.some((x) => x.id === s.id));
    const warm = chosen.filter((s) => typeof s.c === 'number').sort((a, b2) => b2.c - a.c)[0];
    const sleeping = chosen.filter((s) => s.c === null && s.asleep).length;
    check('the disk cage: every kind a block, all folded (the zone has sources)', b.length >= 8 && b.every((x) => x.hidden && x.expanded === 'false' && x.controls), JSON.stringify(b.map((x) => [x.kind, x.hidden])));
    check('… the disk line: how many, the warmest as the state has it, how many sleep — no temperature for a sleeping one',
      disk && disk.sum.startsWith(`${chosen.length} chosen`) && disk.sum.includes(`Disk ${warm.id.replace('disk', '')} at ${warm.c} °C`) && disk.sum.endsWith(`${sleeping} asleep`)
      && JSON.parse(disk.nums)[0].v === warm.c, JSON.stringify(disk));
    check('… one kind: its curve as a word, no link', disk && disk.curve === EN['curve.kind_zone'] && !disk.link, JSON.stringify(disk));
    const cpu = b.find((x) => x.kind === 'cpu');
    const nCpu = STATE.setup.sources.filter((s) => s.kind === 'cpu').length;
    check('… a kind without a choice: quiet, how many there are, no curve word', cpu && !cpu.has && cpu.sum === `none chosen · ${nCpu} here` && cpu.curve === null, JSON.stringify(cpu));
    const unas = b.find((x) => x.kind === 'unassigned');
    check('… the unassigned disks a block of their own', !!unas && unas.chips === 2, JSON.stringify(unas));
    // unfold the disks: the chips there, the line says «Hide», nothing else opens
    await page.click('#sso .fb-src-block[data-kind="disk"] .fb-src-toggle');
    b = await blocks(page);
    const d2 = b.find((x) => x.kind === 'disk');
    check('the disk line clicked: its chips in place (aria-expanded), the others stay folded', d2.expanded === 'true' && !d2.hidden && d2.chips === STATE.setup.sources.filter((s) => s.kind === 'disk' && !s.group).length
      && d2.on === chosen.length && b.filter((x) => x.kind !== 'disk').every((x) => x.hidden), JSON.stringify(d2));
    check('… its control says it folds again', (await page.textContent('#sso .fb-src-block[data-kind="disk"] .fb-src-act')) === EN['zones.src_hide'], '');
    // a chip clicked: the zone changes, the page draws itself anew — the disks stay open, one fewer chosen
    await page.click('#sso .fb-src-block[data-kind="disk"] .fb-src.on');
    await page.waitForTimeout(300);
    b = await blocks(page);
    const d3 = b.find((x) => x.kind === 'disk');
    check('a chip taken out: the page drew anew, the disks still open, one fewer chosen', !d3.hidden && d3.expanded === 'true' && d3.sum.startsWith(`${chosen.length - 1} chosen`), JSON.stringify(d3));
    await page.click('#sso .fb-src-block[data-kind="disk"] .fb-src-toggle');
    check('… folded again by its line', (await blocks(page)).find((x) => x.kind === 'disk').hidden, '');
    check('no error, no horizontal scroll', !errors.length && !(await wide(page)), errors.join(' | '));
    await ctx.close();
    if (h.close) await h.close();
  }
  {
    // the case: NVMe follows the zone, RAM its own curve — the words, the link to the card
    const c = { theme: 'black', lang: 'en', width: 1440 };
    const { h, ctx, page, errors } = await open(c, 'case');
    const b = await blocks(page);
    const nvme = b.find((x) => x.kind === 'nvme');
    const ram = b.find((x) => x.kind === 'ram');
    check('the case: NVMe «follows the zone», RAM «own curve» — links (several kinds)', nvme && ram && nvme.curve === EN['curve.kind_zone'] && ram.curve === EN['curve.kind_own'] && nvme.link && ram.link,
      JSON.stringify([nvme, ram]));
    await page.click('#sso .fb-src-block[data-kind="ram"] .fb-src-curve');
    await page.waitForTimeout(400);
    const front = await page.evaluate(() => {
      const b2 = document.querySelector('#sso .fb-curve-part .fb-kindpick[aria-pressed="true"]');
      const r = b2 && b2.getBoundingClientRect();
      return { kind: b2 && b2.dataset.kind, focused: document.activeElement === b2, seen: !!r && r.top >= 0 && r.bottom <= window.innerHeight };
    });
    check('… RAM\'s word clicked: RAM\'s card in front, in view, focused', front.kind === 'ram' && front.seen && front.focused, JSON.stringify(front));
    check('… no error', !errors.length, errors.join(' | '));
    await ctx.close();
    if (h.close) await h.close();
  }
  {
    // a zone without any source: open, the line that it needs one; the first chosen — still open
    const c = { theme: 'black', lang: 'en', width: 375 };
    const { h, ctx, page, errors } = await open(c, 'zone3');
    let b = await blocks(page);
    check('Zone 3 (nothing yet): every kind open, the line that it needs a source', b.length >= 8 && b.every((x) => !x.hidden && x.expanded === 'true')
      && (await page.textContent('#sso .fb-src-first')) === EN['zones.sources_first'], JSON.stringify(b.map((x) => [x.kind, x.hidden])));
    await page.click('#sso .fb-src-block[data-kind="cpu"] .fb-src');
    await page.waitForTimeout(300);
    b = await blocks(page);
    check('… the first source chosen: still all open, the CPU line says one chosen, the line gone', b.every((x) => !x.hidden) && b.find((x) => x.kind === 'cpu').sum.startsWith('1 chosen')
      && !(await page.$('#sso .fb-src-first')), JSON.stringify(b.find((x) => x.kind === 'cpu')));
    check('… 375 px: no horizontal scroll, no error', !(await wide(page)) && !errors.length, errors.join(' | '));
    await ctx.close();
    if (h.close) await h.close();
  }

  // ------------------------------------------------------------------------------------------------------- pictures
  if (opt.shots) {
    fs.mkdirSync(opt.out, { recursive: true });
    for (const theme of ['black', 'white']) for (const code of ['de', 'en']) for (const width of [1440, 375]) {
      for (const [scene, zone, unfold] of [['folded', 'disks', null], ['unfolded', 'disks', 'disk'], ['new-zone', 'zone3', null]]) {
        const { h, ctx, page, errors } = await open({ theme, lang: code, width }, zone);
        if (unfold) { await page.click(`#sso .fb-src-block[data-kind="${unfold}"] .fb-src-toggle`); await page.waitForTimeout(200); }
        const card = await page.$('#sso .fb-zone');
        const out = path.join(opt.out, `zone-sources-${scene}-${theme}-${code}-${width}.png`);
        await card.screenshot({ path: out });
        if (errors.length || await wide(page)) failed.push(`${path.basename(out)}: ${errors.join(' | ') || 'horizontal scroll'}`);
        await ctx.close();
        if (h.close) await h.close();
      }
    }
  }
} catch (e) {
  failed.push(String(e && e.stack || e));
} finally {
  await browser.close();
}
console.log(`fanboy zones: ${failed.length ? `${failed.length} failed:\n  ${failed.join('\n  ')}` : 'all checks passed'}${opt.shots ? ` · pictures in ${opt.out}` : ''}`);
process.exit(failed.length ? 1 : 0);
