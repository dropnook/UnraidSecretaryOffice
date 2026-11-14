#!/usr/bin/env node
// Fanboy's pictures (public/desks/fanboy/fan-art.js) on their demo page (tools/fanboy/demo.html, invented data) in a
// headless Chrome with a throwaway profile, inside Unraid's look (tests/ui/unraid: palette, black and white theme, fonts).
// Dev-only: tools/ is never in the package.
//
//   node tools/fanboy/look.mjs [--out <folder>] [--serve [port]] [--no-shots] [--no-cpu] [--no-movie] [-v]
//
//   --serve     only serve the demo page (http://127.0.0.1:<port>/?theme=white …) until Ctrl-C
//   default     the pictures: demo-<theme>-<width>.png (black, white × 1440, 390), wheels-still-<theme>.png (reduced
//               motion), wheels.png (an animated PNG: slow, medium and fast wheels and a zone with its air, 40 frames a second, stepped on the animations'
//               own clock so no frame is skipped) and cpu.txt: twelve wheels on one page, 10 s each — the page's main
//               thread (Chrome's TaskDuration) and all of Chrome's processes together (ps), steady and with a new speed
//               every 3 s; the empty page (no wheel) as the baseline.
// Fails (exit 1) on a console error, a horizontal scroll at phone width or a missing catalogue.
// Needs node and playwright-core and a Chrome — found like tools/ui-shots.mjs (USO_PLAYWRIGHT, USO_CHROME). Exit 3: not there.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import zlib from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const UNRAID = path.join(REPO, 'tests', 'ui', 'unraid');
const PUBLIC = path.join(REPO, 'public');
const BASE = '/plugins/unraid-secretary-office/';
const require = createRequire(import.meta.url);

const args = process.argv.slice(2);
const opt = { out: path.join(process.env.USO_SHOTS || path.join(os.homedir(), 'uso-shots'), 'fanboy-look'), serve: null, shots: true, cpu: true, movie: true, v: false };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--out') opt.out = path.resolve(args[++i]);
  else if (a === '--serve') opt.serve = /^\d+$/.test(args[i + 1] || '') ? +args[++i] : 8099;
  else if (a === '--no-shots') opt.shots = false;
  else if (a === '--no-cpu') opt.cpu = false;
  else if (a === '--no-movie') opt.movie = false;
  else if (a === '-v') opt.v = true;
  else { console.log(`fanboy look: unknown argument ${a}`); process.exit(2); }
}

// ------------------------------------------------------------------ the page: Unraid's look, the office's files, the demo
function unraidCss() {
  const read = (f) => { try { return fs.readFileSync(path.join(UNRAID, f), 'utf8'); } catch (e) { return ''; } };
  const theme = (name) => read(`${name}.css`).replace(/:root\s*\{/, `:root[data-unraid-theme="${name}"] {`);
  const font = (family, weight, style, file) => `@font-face{font-family:${family};font-weight:${weight};font-style:${style};src:url('/webGui/styles/${file}') format('woff')}`;
  return [
    font('clear-sans', 'normal', 'normal', 'clear-sans.woff'), font('clear-sans', 'bold', 'normal', 'clear-sans-bold.woff'),
    font('bitstream', 'normal', 'normal', 'bitstream.woff'),
    read('default-color-palette.css'), theme('black'), theme('white'),
    'html{font-family:clear-sans,sans-serif;font-size:62.5%;height:100%}',
    'body{font-size:1.3rem;color:var(--text-color);background-color:var(--background-color);margin:0;padding:1rem 1rem 4rem;-webkit-font-smoothing:antialiased}',
  ].join('\n');
}
const TYPES = { '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.html': 'text/html', '.woff': 'font/woff', '.svg': 'image/svg+xml' };
function inside(dir, rel) {
  const p = path.resolve(dir, '.' + path.sep + rel);
  return p.startsWith(dir + path.sep) && fs.existsSync(p) && fs.statSync(p).isFile() ? p : null;
}
function serve(port) {
  const server = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    let file = null, body = null;
    if (u.pathname === '/favicon.ico') { res.writeHead(204); res.end(); return; }
    if (u.pathname === '/' || u.pathname === '/index.html') file = path.join(HERE, 'demo.html');
    else if (u.pathname === '/unraid.css') body = unraidCss();
    else if (u.pathname.startsWith('/webGui/styles/')) file = inside(UNRAID, decodeURIComponent(u.pathname.slice(15)));
    else if (u.pathname.startsWith(BASE)) file = inside(PUBLIC, decodeURIComponent(u.pathname.slice(BASE.length)));
    else if (u.pathname.startsWith('/demo/')) file = inside(HERE, decodeURIComponent(u.pathname.slice(6)));
    const type = body != null ? 'text/css' : file ? TYPES[path.extname(file)] || 'application/octet-stream' : null;
    if (!type) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': type + '; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(body != null ? body : fs.readFileSync(file));
  });
  return new Promise((ok) => server.listen(port, '127.0.0.1', () => ok({ server, url: `http://127.0.0.1:${server.address().port}/` })));
}

if (opt.serve != null) {
  const { url } = await serve(opt.serve);
  console.log(`fanboy look: ${url}  (?theme=white · ?only=wheels · ?calm)`);
  await new Promise(() => {});
}

// ------------------------------------------------------------------ playwright-core and a Chrome (as tools/ui-shots.mjs)
function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try { for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core')); } catch (e) { /* no npx cache */ }
  for (const t of tries.filter(Boolean)) { try { return require(t); } catch (e) { /* the next */ } }
  return null;
}
const pw = findPlaywright();
if (!pw || !pw.chromium) { console.log('fanboy look: playwright-core not found (USO_PLAYWRIGHT=<folder>) - not run'); process.exit(3); }
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p));
if (!chrome) { console.log('fanboy look: no Chrome found (USO_CHROME=<binary>) - not run'); process.exit(3); }

fs.mkdirSync(opt.out, { recursive: true });
const { server, url } = await serve(0);
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check', '--font-render-hinting=none'] });
const faults = [];
const log = (...a) => { if (opt.v) console.log(...a); };

async function open(query, { width = 1440, height = 900, scale = 2, reduce = false } = {}) {
  const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: scale, reducedMotion: reduce ? 'reduce' : 'no-preference' });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error') faults.push(`${query} ${width}: console: ${m.text()}`); });
  page.on('pageerror', (e) => faults.push(`${query} ${width}: ${e.message}`));
  await page.goto(url + query, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  return { ctx, page };
}

// ------------------------------------------------------------------ the pictures
if (opt.shots) {
  for (const theme of ['black', 'white']) {
    for (const width of [1440, 390]) {
      const { ctx, page } = await open(`?theme=${theme}&calm`, { width, height: width < 600 ? 844 : 900 });
      await page.waitForSelector('html[data-catalog]', { timeout: 5000 }).catch(() => faults.push(`${theme} ${width}: the catalogue never came`));
      await page.waitForTimeout(1300);
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (wide > 0) faults.push(`${theme} ${width}: ${wide} px of horizontal scroll`);
      const file = path.join(opt.out, `demo-${theme}-${width}.png`);
      await page.screenshot({ path: file, fullPage: true });
      log(file);
      await ctx.close();
    }
    const { ctx, page } = await open(`?theme=${theme}&only=wheels&calm`, { width: 1100, reduce: true });
    const box = await page.locator('.fl-wheels').boundingBox();
    const file = path.join(opt.out, `wheels-still-${theme}.png`);
    await page.screenshot({ path: file, clip: { x: box.x - 12, y: box.y - 12, width: box.width + 24, height: box.height + 24 } });
    log(file);
    await ctx.close();
  }
}

// ------------------------------------------------------------------ what the hands do: the curve, the picker, a new speed, off screen
if (opt.shots) {
  const { ctx, page } = await open('?theme=black&calm', { width: 1440, height: 900, scale: 1 });
  await page.waitForSelector('html[data-catalog]', { timeout: 5000 }).catch(() => {});
  const want = (what, ok) => { if (!ok) faults.push(`checks: ${what}`); log(`${ok ? 'ok  ' : 'FAIL'} ${what}`); };
  const pts = () => page.evaluate(() => window.flDemo.curve.points());
  const before = await pts();
  await page.locator('.fan-curve-pt').nth(1).focus();
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('Shift+ArrowUp');
  await page.keyboard.press('ArrowRight');
  let now = await pts();
  want('curve keys: ↑ and Shift+↑ raise the value by 6, → the temperature by 1', now[1].v === before[1].v + 6 && now[1].t === before[1].t + 1);
  want('curve keys: onChange told the caller', /Changed: /.test(await page.locator('.fl-box .fl-note').first().textContent()));
  await page.keyboard.press('+');
  want('curve keys: + adds a point after it', (await pts()).length === before.length + 1);
  await page.keyboard.press('Delete');
  want('curve keys: Delete removes it again', (await pts()).length === before.length);
  const dot = await page.locator('.fan-curve-pt').nth(2).boundingBox();
  const first = await page.locator('.fan-curve-pt').nth(0).boundingBox();
  await page.mouse.move(dot.x + dot.width / 2, dot.y + dot.height / 2);
  await page.mouse.down();
  await page.mouse.move(first.x - 80, dot.y + 200, { steps: 8 });      // far left of the point before it, and down
  await page.mouse.up();
  now = await pts();
  want('curve drag: the point keeps its order (after the one before it, never below its value)', now[2].t > now[1].t && now[2].v >= now[1].v);
  want('curve: the wheel beside turns at the temperature under the pointer', /°C → \d+ % · [\d,]+ rpm/.test(await page.locator('.fan-curve-read').textContent()));
  await page.selectOption('.fan-pick select >> nth=0', 'Arctic');
  const models = await page.locator('.fan-pick select >> nth=1').locator('option').count();
  want('picker: a maker lists its models', models > 5);
  await page.selectOption('.fan-pick select >> nth=0', { index: (await page.locator('.fan-pick select >> nth=0 >> option').count()) - 1 });
  want('picker: «other fan» shows size and blades by hand', await page.locator('.fan-pick-field').nth(2).isVisible() && !(await page.locator('.fan-pick-field').nth(1).isVisible()));
  const same = await page.evaluate(async () => {
    const d = window.flDemo, el = document.querySelector('.fan-zone .fan-wheel');
    const pic = JSON.parse(JSON.stringify(d.pic));
    pic.zones[0].fans[0].rpm = 400;
    d.server.update(pic);
    return el === document.querySelector('.fan-zone .fan-wheel') && el.isConnected;
  });
  want('server update: the same wheel goes on turning (no new one)', same);
  await page.evaluate(() => window.scrollTo(0, 0));         // the picker brought the page down: the wheels back in sight
  await page.waitForTimeout(400);
  const glide = await page.evaluate(async () => {
    const { x } = window.flDemo.wheels[0];
    const a = x.el.querySelector('.fan-rotor').getAnimations()[0];
    const from = a.playbackRate;
    x.update({ rpm: 300 });
    await new Promise((ok) => setTimeout(ok, 300));
    const mid = a.playbackRate;
    await new Promise((ok) => setTimeout(ok, 900));
    return { from, mid, end: a.playbackRate };
  });
  want(`a new speed glides in (${glide.from.toFixed(2)} → ${glide.mid.toFixed(2)} → ${glide.end.toFixed(2)})`, glide.mid < glide.from && glide.mid > glide.end && Math.abs(glide.end - 0.12) < 0.01);
  const air = await page.evaluate(() => {
    const seen = (el) => [...el.querySelectorAll('.fan-streak')].filter((s) => s.style.visibility !== 'hidden').length;
    const ws = window.flDemo.wheels;
    const by = (id) => ws.find((w) => w.w.id === id).x.el;
    return { stands: seen(by('w10')) + seen(by('w11')), slow: seen(by('w9')), fast: seen(by('w6')), bios: seen(by('w12')),
      zone: [...document.querySelectorAll('.fan-flow')].map(seen) };
  });
  want(`air: none while it stands, more the faster, a BIOS fan's calmer (stands ${air.stands}, slow ${air.slow}, fast ${air.fast}, BIOS ${air.bios}; zones ${air.zone})`,
    air.stands === 0 && air.slow >= 1 && air.fast > air.slow && air.bios <= 2 && air.zone.length === 2 && air.zone.every((n) => n >= 2));
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(400);
  const paused = await page.evaluate(() => [...document.querySelectorAll('.fl-wheels .fan-rotor')].map((r) => r.getAnimations()[0].playState));
  want('off screen: the wheels rest', paused.every((s) => s !== 'running'));
  await ctx.close();
}

// ------------------------------------------------------------------ the turning wheels as an animated PNG
function chunks(buf) {
  const out = [];
  for (let o = 8; o < buf.length;) {
    const len = buf.readUInt32BE(o), type = buf.toString('latin1', o + 4, o + 8);
    out.push({ type, data: buf.subarray(o + 8, o + 8 + len) });
    o += 12 + len;
  }
  return out;
}
function chunk(type, data) {
  const head = Buffer.alloc(8);
  head.writeUInt32BE(data.length, 0);
  head.write(type, 4, 'latin1');
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(zlib.crc32(Buffer.concat([head.subarray(4), data])) >>> 0, 0);
  return Buffer.concat([head, data, crc]);
}
function apng(frames, fps) {
  const first = chunks(frames[0]);
  const ihdr = first.find((c) => c.type === 'IHDR').data;
  const w = ihdr.readUInt32BE(0), h = ihdr.readUInt32BE(4);
  const parts = [frames[0].subarray(0, 8), chunk('IHDR', ihdr)];
  const ac = Buffer.alloc(8);
  ac.writeUInt32BE(frames.length, 0);
  parts.push(chunk('acTL', ac));
  let seq = 0;
  frames.forEach((f, i) => {
    const fc = Buffer.alloc(26);
    fc.writeUInt32BE(seq++, 0); fc.writeUInt32BE(w, 4); fc.writeUInt32BE(h, 8);
    fc.writeUInt16BE(1, 20); fc.writeUInt16BE(fps, 22);
    parts.push(chunk('fcTL', fc));
    for (const c of chunks(f).filter((c) => c.type === 'IDAT')) {
      if (i === 0) { parts.push(chunk('IDAT', c.data)); continue; }
      const sq = Buffer.alloc(4);
      sq.writeUInt32BE(seq++, 0);
      parts.push(chunk('fdAT', Buffer.concat([sq, c.data])));
    }
  });
  parts.push(chunk('IEND', Buffer.alloc(0)));
  return Buffer.concat(parts);
}
if (opt.movie) {
  const fps = 40, n = 100;
  const { ctx, page } = await open('?theme=black&only=movie&calm', { width: 720, height: 700, scale: 1 });
  await page.waitForTimeout(1200);       // the glide from 0 to each wheel's speed is over
  const box = await page.locator('#fl-demo section').first().boundingBox();
  await page.evaluate(() => {
    window.flFrames = document.getAnimations().map((a) => ({ a, t: a.currentTime, r: a.playState === 'running' ? a.playbackRate : 0 }));
    for (const f of window.flFrames) f.a.pause();
  });
  const frames = [];
  for (let k = 0; k < n; k++) {
    await page.evaluate(([k, ms]) => { for (const f of window.flFrames) f.a.currentTime = f.t + k * ms * f.r; }, [k, 1000 / fps]);
    frames.push(await page.screenshot({ clip: { x: box.x - 8, y: box.y - 8, width: Math.ceil(box.width + 16), height: Math.ceil(box.height + 16) } }));
  }
  const file = path.join(opt.out, 'wheels.png');
  fs.writeFileSync(file, apng(frames, fps));
  log(file, Math.round(fs.statSync(file).size / 1024) + ' KB');
  await ctx.close();
}

// ------------------------------------------------------------------ CPU: twelve wheels, 10 s
function chromeTree() {
  const rows = execFileSync('ps', ['-A', '-o', 'pid=,ppid=,time=,command='], { encoding: 'utf8' }).split('\n').filter(Boolean).map((l) => {
    const m = /^\s*(\d+)\s+(\d+)\s+(\S+)\s+(.*)$/.exec(l);
    return m && { pid: +m[1], ppid: +m[2], time: m[3], cmd: m[4] };
  }).filter(Boolean);
  const secs = (t) => t.split(/[:-]/).reverse().reduce((s, x, i) => s + parseFloat(x) * [1, 60, 3600, 86400][i], 0);
  const roots = rows.filter((r) => r.cmd.startsWith(chrome) && r.cmd.includes('--remote-debugging-pipe') && !rows.some((p) => p.pid === r.ppid && p.cmd.startsWith(chrome)));
  const keep = new Set(roots.map((r) => r.pid));
  for (let grew = true; grew;) { grew = false; for (const r of rows) if (!keep.has(r.pid) && keep.has(r.ppid)) { keep.add(r.pid); grew = true; } }
  return new Map(rows.filter((r) => keep.has(r.pid)).map((r) => [r.pid, secs(r.time)]));
}
/** CPU seconds between two looks, of the processes there at both (one that ended or began between them would lie) */
const spent = (a, b) => [...b].reduce((s, [pid, t]) => s + (a.has(pid) ? t - a.get(pid) : 0), 0);
async function measure(query, secs) {
  const { ctx, page } = await open(query, { width: 1440, height: 900, scale: 1 });
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Performance.enable');
  await page.waitForTimeout(3000);       // the last page's processes gone, this one's settled
  const metric = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
  const a = await metric(), ca = chromeTree(), t0 = Date.now();
  await page.waitForTimeout(secs * 1000);
  const b = await metric(), cb = chromeTree(), dt = (Date.now() - t0) / 1000;
  const wheels = await page.evaluate(() => document.querySelectorAll('.fan-wheel').length);
  const streaks = await page.evaluate(() => document.querySelectorAll('.fan-streak').length);
  const running = await page.evaluate(() => document.getAnimations().filter((x) => x.playState === 'running').length);
  await ctx.close();
  return { query, wheels, streaks, running, main: 100 * (b.TaskDuration - a.TaskDuration) / dt, script: 100 * (b.ScriptDuration - a.ScriptDuration) / dt, all: 100 * spent(ca, cb) / dt };
}
if (opt.cpu) {
  // the machine may be busy with other work: each look three times, interleaved, the median kept
  const looks = [['?theme=black&only=wheels&calm&none', 'baseline: the page without a wheel'],
    ['?theme=black&only=wheels&calm&noair', '12 wheels, no air (the first look)'], ['?theme=black&only=wheels&calm', '12 wheels with their air'],
    ['?theme=black&only=wheels', '12 wheels with air, a new speed every 3 s'],
    ['?theme=black&only=server&calm&noair', 'server picture, no air'], ['?theme=black&only=server&calm', 'server picture with its air'],
    ['?theme=black&only=server', 'server picture with air, new speeds every 3 s']];
  const got = looks.map(() => []);
  for (let round = 0; round < 3; round++) for (const [i, [q]] of looks.entries()) got[i].push(await measure(q, 8));
  const median = (list, key) => list.map((r) => r[key]).sort((a, b) => a - b)[1];
  const lines = looks.map(([q, name], i) => `${name.padEnd(46)} streaks ${String(got[i][0].streaks).padStart(2)}  main thread ${median(got[i], 'main').toFixed(2)} %`
    + `  all Chrome processes ${median(got[i], 'all').toFixed(1)} % of one core  (runs: ${got[i].map((r) => r.all.toFixed(1)).join(' / ')})`);
  const text = `Fanboy's wheels and air: CPU in headless Chrome (${new Date().toISOString().slice(0, 16)}, ${os.cpus()[0].model}, load ${os.loadavg()[0].toFixed(1)}, 1440×900, scale 1, 8 s each, median of 3)\n${lines.join('\n')}\n`;
  fs.writeFileSync(path.join(opt.out, 'cpu.txt'), text);
  console.log(text.trim());
}

await browser.close();
server.close();
if (faults.length) { console.log('fanboy look: ' + faults.length + ' fault(s)\n  ' + faults.join('\n  ')); process.exit(1); }
console.log(`fanboy look: done → ${opt.out}`);
