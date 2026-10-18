#!/usr/bin/env node
// Ms. Moverelli's run card's «time left» judged against the real end (cell M3, moverelli-sh-yes-run-picture.sh): her live
// progress read every few seconds during a real run (readings.jsonl: {t, progress} per line, then {t, end: true}) is fed,
// reading by reading as an open page would, to desk.js's own runCardData(p, now) — the page's code, loaded with
// core.js's Office.runRate (the old card's rate) and every other page global a no-op. For each reading that shows a time
// left for the share in flight: that time against the seconds the share really still took.
//
//   node tools/scenarios/moverelli-eta.mjs <desk.js> <core.js> <readings.jsonl> [--factor 3] [--slack 30]
//
// Honest: within --factor of the real rest, or less than --slack seconds off. Prints one line per reading and a last
// line «ETA ok|bad n=… worst=…»; exit 0 all shown times honest (or none shown), 1 one wasn't, 2 wrong call or the page's
// code didn't load. Older desk.js (1.66.1: bytes left ÷ a short window's byte rate) loads the same way — so the same
// readings show a release's card red or green (README: «red before»).
import fs from 'node:fs';
import vm from 'node:vm';

const args = process.argv.slice(2);
const opt = { factor: 3, slack: 30 };
const files = [];
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--factor') opt.factor = Number(args[++i]) || 3;
  else if (args[i] === '--slack') opt.slack = Number(args[++i]) || 30;
  else files.push(args[i]);
}
if (files.length !== 3 || !files.every((f) => fs.existsSync(f))) {
  console.error('usage: moverelli-eta.mjs <desk.js> <core.js> <readings.jsonl> [--factor 3] [--slack 30]');
  process.exit(2);
}
const [deskJs, coreJs, readingsFile] = files;

// a stub that is everything: callable, constructible, any property is itself — the page's DOM and Office calls do nothing
const stub = new Proxy(function () {}, {
  get: (t, k) => (k === Symbol.toPrimitive ? () => '' : k === 'then' ? undefined : stub),
  apply: () => stub,
  construct: () => stub,
});
const Office = new Proxy({}, { get: (t, k) => (k in t ? t[k] : stub), set: (t, k, v) => { t[k] = v; return true; } });
const ctx = vm.createContext({ Office, console, Math, Date, JSON, Map, Set, Number, String, Array, Object, Symbol, Promise,
  setTimeout: () => 0, clearTimeout: () => {}, setInterval: () => 0, clearInterval: () => {}, requestAnimationFrame: () => 0 });
for (const k of ['window', 'document', 'navigator', 'location', 'localStorage', 'sessionStorage', 'el', 'T', 'fmt', 'getComputedStyle', 'matchMedia', 'CSS']) ctx[k] = stub;

// core.js: only Office.runRate (and what it needs) — the rest of the office is the stub
const core = fs.readFileSync(coreJs, 'utf8');
const rr = core.match(/const RUN_RATE_WINDOW[^\n]*\n/);
const samples = core.match(/const runRateSamples[^\n]*\n/);
const fn = core.match(/Office\.runRate = function runRate[\s\S]*?\n\};\n/);
if (!fn) { console.error('eta: no Office.runRate in core.js'); process.exit(2); }
vm.runInContext(`${rr ? rr[0] : 'const RUN_RATE_WINDOW = 600;\n'}${samples ? samples[0] : 'const runRateSamples = new Map();\n'}${fn[0]}`, ctx);

// desk.js as a whole (its own test hook OFFICE_DESK_TESTS hands out runCardData, as tests/run.php uses it); the drawing
// it starts at load hits only stubs
ctx.OFFICE_DESK_TESTS = {};
try {
  vm.runInContext(fs.readFileSync(deskJs, 'utf8'), ctx, { filename: deskJs });
} catch (e) {
  console.error(`eta: desk.js stopped at load: ${e.message}`);
}
const card = ctx.OFFICE_DESK_TESTS.moverelli && ctx.OFFICE_DESK_TESTS.moverelli.runCardData;
if (typeof card !== 'function') { console.error('eta: runCardData not found in ' + deskJs); process.exit(2); }

const lines = fs.readFileSync(readingsFile, 'utf8').split('\n').filter(Boolean).map((l) => { try { return JSON.parse(l); } catch (e) { return null; } }).filter(Boolean);
// when each share's part of the run ended: the first reading after it that names another share or phase, or the run's end
const endOf = (i, share) => {
  for (let j = i + 1; j < lines.length; j++) {
    const p = lines[j].progress;
    if (lines[j].end || !p || p.phase !== 'share' || p.share !== share) return lines[j].t;
  }
  return null;
};
let n = 0, bad = 0, worst = null;
for (let i = 0; i < lines.length; i++) {
  const { t, progress: p } = lines[i];
  if (!p || p.phase !== 'share' || !p.share) continue;
  let d;
  try { d = card(p, t); } catch (e) { console.log(`t=${t} the card threw: ${e.message}`); continue; }
  if (!d || d.left === null || d.left === undefined) { console.log(`t=${t} ${p.share} ${p.share_done_files}/${p.share_files}: no time left shown`); continue; }
  const end = endOf(i, p.share);
  if (end === null) continue;
  const real = Math.max(0, end - t), left = Number(d.left);
  const ok = Math.abs(left - real) < opt.slack || (left <= opt.factor * Math.max(real, 1) && real <= opt.factor * Math.max(left, 1));
  n++;
  if (!ok) bad++;
  const off = Math.abs(left - real);
  if (!worst || off > worst.off) worst = { off, left, real, t };
  console.log(`t=${t} ${p.share} ${p.share_done_files}/${p.share_files}: card says ${Math.round(left)} s left${d.range ? ` (${Math.round(d.range[0])}–${Math.round(d.range[1])})` : ''}, really ${Math.round(real)} s — ${ok ? 'ok' : 'NOT HONEST'}`);
}
console.log(`ETA ${bad ? 'bad' : 'ok'} n=${n} bad=${bad}${worst ? ` worst: said ${Math.round(worst.left)} s, really ${Math.round(worst.real)} s` : ''}`);
process.exit(bad ? 1 : 0);
