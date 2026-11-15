#!/usr/bin/env node
// Fanboy's demo and stub states for the page (tests/ui/demo/fanboy*.json, tests/ui/states/fanboy*.json): one invented
// board (the suite's fake ITE board, part 1's look of it in tests/ui/demo/fanboy-steering.json as the base) in every
// situation the page shows — the overview steering, the alarm, the chip missing, another controller, IPMI, no output, the
// array stopped — and the setup at each step of the wizard. Everything invented: no real server's name, path or person;
// the fans' names German first (tests/ui/harness.mjs DEMO_NAMES_EN gives the English shots theirs).
// The state's shape follows agent/desks/fanboy.php fanboyScan() (testFanboyDesk compares the keys with a real look).
// Dev-only (tools/ is never in the package).
//
//   node tools/fanboy/demo-states.mjs        writes the files and lists them
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const DEMO = path.join(REPO, 'tests', 'ui', 'demo');
const STATES = path.join(REPO, 'tests', 'ui', 'states');
const T0 = 1763100000;                       // the demo's «now» (the harness moves `time` and its kin to the real now)
const clone = (x) => JSON.parse(JSON.stringify(x));
const base = JSON.parse(fs.readFileSync(path.join(DEMO, 'fanboy-steering.json'), 'utf8'));
const CHIP = 'it8696_a0060607', ADDR = 'it87.2608';
const P = (n) => `${CHIP}:${ADDR}:pwm${n}`;

// the plugins he knows (agent/desks/fanboy-detect.php FANBOY_PLUGINS), none steering
const TOOLS = [
  ['it87-driver', 'ITE IT87 Driver', 'ich777', 'driver', true, true, null],
  ['nct6687-driver', 'Nuvoton NCT6687 Driver', 'ich777', 'driver', false, false, null],
  ['dynamix.system.temp', 'Dynamix System Temp', 'Bergware', 'sensors', true, null, null],
  ['fanctrlplus', 'FanCtrl Plus', 'ck9393', 'controller', false, null, false],
  ['fanctrlplus2', 'FanCtrl Plus 2', 'ck9393 / andrebrait', 'controller', false, null, false],
  ['dynamix.system.autofan', 'Dynamix System Autofan', 'Bergware/gfjardim', 'controller', false, null, false],
  ['pwm-fan', 'PWM Fan', 'alturismo', 'controller', false, null, false],
  ['md12xx.fancontrol', 'WAZ Cooling Control', 'TheIlluminate92', 'controller', false, null, false],
].map(([id, name, author, kind, installed, loaded, active]) => ({ id, name, author, kind, installed, loaded, active }));

// ------------------------------------------------------------------ the server's parts
/** The fake board's ITE chip with these outputs ({n: [value, enable]}) and tach readings ({n: rpm}) */
function ite(outs, rpms) {
  const c = clone(base.chips.find((x) => x.name === CHIP));
  c.outputs = c.outputs.map((o) => {
    const [value, enable] = outs[o.n] || [128, 1];
    return { ...o, value, pct: Math.round(value / 2.55), enable, mode: enable === 0 ? 'full' : enable === 1 ? 'manual' : 'auto', auto: true };
  });
  c.fans = c.fans.map((f) => ({ ...f, rpm: rpms[f.n] ?? f.rpm }));
  return c;
}
const devKey = (d) => d.replace(/\/([a-z]+)\/\1\d+$/, '/$1');
/** Every heat source as fanboySources() lists them */
function sources(chips, disks) {
  const out = disks.map((d) => ({ kind: d.rotational === false ? 'ssd' : 'disk', id: d.name, label: d.name, c: d.temp, asleep: d.asleep }));
  for (const c of chips) {
    if (c.kind === 'disk') continue;
    for (const t of c.temps) out.push({ kind: c.kind, id: `${c.name}@${devKey(c.device)}#${t.n}`, label: t.label || `${c.name} #${t.n}`, c: t.c, asleep: false, chip: c.name, hwmon: c.hwmon, n: t.n });
  }
  return out;
}
const DEFAULTS = { disk: [35, 45, 52], ssd: [40, 60, 70], nvme: [40, 65, 75], cpu: [45, 80, 92], ram: [40, 60, 75], gpu: [45, 80, 90], board: [35, 55, 70], other: [35, 55, 75] };
const SCALES = Object.fromEntries(Object.entries(DEFAULTS).map(([k, [low, high, limit]]) => [k, { low, high, limit }]));
function preset(p, lo, hi) {
  const mid = (lo + hi) / 2;
  return { quiet: [[lo, 0], [mid, 30], [hi, 60], [hi + 5, 100]], cool: [[lo - 3, 35], [mid - 3, 65], [hi - 3, 100]] }[p] || [[lo, 20], [mid, 45], [hi, 80], [hi + 3, 100]];
}
const presets = (lo, hi) => ({ quiet: preset('quiet', lo, hi), normal: preset('normal', lo, hi), cool: preset('cool', lo, hi) });
const PRESETS_OF = Object.fromEntries(Object.entries(DEFAULTS).map(([k, [lo, hi]]) => [k, presets(lo, hi)]));
// FANBOY_DOWN_PROFILES of agent/desks/fanboy-control.php (round 4): the way down per kind, balanced or quick
const DP = (b, q) => ({ balanced: { gap: b[0], hold: b[1], step: b[2] }, quick: { gap: q[0], hold: q[1], step: q[2] } });
const MID = DP([2, 4, 2], [1, 2, 4]);
const DOWN = { disk: DP([2, 0, 1], [1, 0, 3]), ssd: MID, nvme: MID, ram: MID, board: MID, cpu: DP([2.5, 6, 5], [1.5, 3, 8]), gpu: DP([2.5, 6, 5], [1.5, 3, 8]), other: MID };

const NVME = `nvme@${devKey(base.chips.find((c) => c.address === 'nvme0').device)}#1`;
const RAM = `spd5118@pci0000:00/0000:00:1f.4/i2c-0/0-0051#1`;
const CORE = base.chips.find((c) => c.name === 'coretemp');
const CPU_SRC = (label) => `coretemp@${devKey(CORE.device)}#${CORE.temps.find((t) => t.label === label).n}`;
const TABLE4 = [[100, 1600], [90, 1490], [80, 1344], [70, 1210], [60, 1088], [50, 960], [45, 896], [40, 832], [35, 768], [30, 704], [25, 640]];
const TABLE5 = [[100, 1450], [90, 1340], [80, 1220], [70, 1100], [60, 980], [50, 860], [40, 740], [30, 610]];
/** His fans as the store keeps them, at a step of the setup */
function fan(n, more) {
  // the keys in fanboySetupState()'s order
  const f = { id: P(n), chip: CHIP, output: n, tachs: [], model: null, colour: null, back: null, count: 1, found: null, role: null, name: '', place: null, off_ok: false,
    measured: false, cpu_own: null, min_pct: null, start_pct: null, max_rpm: null, table: null, no_turn: false, kick_start: null };
  return Object.assign(f, more);
}
const FANS = {
  // the CPU cooler: two fans on one connector (a Y-cable) — one speed for both (round 3)
  1: fan(1, { tachs: [1], found: 'tach', role: 'cpu', name: 'CPU-Kühler', place: 'rear', model: { id: 'noctua-nf-a12x25-pwm', mm: null, blades: null }, colour: 'graphite', count: 2 }),
  2: fan(2, { found: 'silent', role: 'case', name: 'Deckel', place: 'top', model: { id: 'arctic-p14-pwm-pst', mm: null, blades: null }, colour: 'black' }),
  3: fan(3, { found: 'empty' }),
  4: fan(4, { tachs: [4], found: 'tach', role: 'disks', name: 'Plattenkäfig', place: 'front', measured: true, min_pct: 25, start_pct: 30, max_rpm: 1600, table: TABLE4,
    model: { id: 'noctua-nf-a12x25-pwm', mm: null, blades: null }, colour: 'graphite' }),
  5: fan(5, { tachs: [5, 6], found: 'tach', role: 'case', name: 'Vorn', place: 'front', measured: true, min_pct: 30, start_pct: 35, max_rpm: 1450, table: TABLE5,
    model: { id: 'be-quiet-silent-wings-4-140mm-pwm', mm: null, blades: null }, colour: 'black' }),
  6: fan(6, { found: 'empty' }),
};
const ZONES = [
  { id: 'disks', name: 'Plattenkäfig', fans: [P(4)], unit: 'pct', target: { preset: 'normal' }, low: null, high: null,
    sources: ['parity', 'disk1', 'disk2'].map((id) => ({ kind: 'disk', id, low: null, high: null, limit: null })), react: 'balanced', kinds: { disk: { high: 44 } } },
  { id: 'case', name: 'Gehäuse', fans: [P(5), P(2)], unit: 'pct', target: { points: [[30, 25], [40, 40], [50, 70], [60, 100]] }, low: null, high: null,
    sources: [{ kind: 'nvme', id: NVME, low: null, high: null, limit: null }, { kind: 'ram', id: RAM, low: null, high: null, limit: 70 }], react: 'quick', kinds: { nvme: { low: 38, high: 60 } } },
];
function curves(zones) {
  const out = {};
  for (const z of zones) {
    const k = z.sources[0] ? z.sources[0].kind : 'disk';
    const own = (z.kinds || {})[k] || {};
    const lo = own.low ?? DEFAULTS[k][0], hi = own.high ?? DEFAULTS[k][1];     // fanboyZoneScale: the first kind's degrees, the zone's own
    out[z.id] = { points: z.target.points || preset(z.target.preset, lo, hi), scale: [lo, hi], presets: presets(lo, hi) };
  }
  return out;
}
const OUTPUTS = [1, 2, 3, 4, 5, 6].map(P);
const BEFORE = Object.fromEntries(OUTPUTS.map((id) => [id, { value: 128, pct: 50, enable: 1, mode: 'manual', fixed: true, auto: 2, from: 'journal' }]));

/** fanboyHottestKinds() and fanboyHottestLead() of agent/desks/fanboy-detect.php, for the invented data */
function hottestKinds(chips, disks) {
  const cand = disks.map((d) => ({ kind: d.rotational ? 'hdd' : 'ssd', c: d.temp, label: '', disk: d.name, chip: null, n: null, asleep: d.asleep, warn: d.warn ?? null, crit: d.crit ?? null }));
  for (const c of chips) {
    if (c.kind === 'disk') continue;
    const kind = { nvme: 'ssd', cpu: 'cpu', board: 'board', ram: 'ram' }[c.kind] || 'other';
    for (const t of c.temps) cand.push({ kind, c: t.c, label: t.label, disk: null, chip: c.name, n: t.n, asleep: false, warn: null, crit: null });
  }
  const out = [];
  for (const kind of ['hdd', 'ssd', 'cpu', 'board', 'ram', 'other']) {
    const of = cand.filter((x) => x.kind === kind);
    if (!of.length) continue;
    let warm = null, near = null;
    for (const x of of) {
      if (x.c !== null && (!warm || x.c > warm.c)) warm = x;
      if (x.c !== null && x.warn !== null && (!near || x.c - x.warn > near.c - near.warn)) near = x;
    }
    out.push({ kind, warmest: warm, near: near && { ...near, margin: near.c - near.warn, over: near.crit !== null && near.c >= near.crit ? 'crit' : near.c >= near.warn ? 'warn' : null },
      count: of.length, asleep: of.filter((x) => x.asleep).length });
  }
  return out;
}
const leadOf = (kinds) => kinds.reduce((l, k) => (k.near && (!l || k.near.margin > l.margin) ? k.near : l), null);

/** A whole state: the look, then who/control/setup/step/drivers as fanboyScan() puts them on top */
function state(o) {
  const chips = (o.chips || base.chips.filter((c) => c.name !== CHIP).concat(o.ite ? [o.ite] : [])).sort((a, b) => +a.hwmon.slice(5) - +b.hwmon.slice(5))
    .map((c) => ({ ...c, twin: (o.twins || []).includes(c.name) }));          // round 5: a chip with two drivers on it
  // Unraid's limits (round 3): hard disks warn at 45 °C, SSDs at 60 °C — disk2 has its own, lower warning (40 °C) and
  // is over it at 41 °C, cooler in degrees than the NVMe pool at 46 °C, which is far from its SSD limit
  const disks = o.disks || [
    { name: 'parity', type: 'parity', rotational: true, asleep: false, temp: 38, warn: 45, crit: 55 },
    { name: 'disk1', type: 'data', rotational: true, asleep: true, temp: null, warn: 45, crit: 55 },
    { name: 'disk2', type: 'data', rotational: true, asleep: false, temp: 41, warn: 40, crit: 50 },
    { name: 'cache', type: 'pool', rotational: false, asleep: false, temp: 46, warn: 60, crit: 70 },
  ];
  const fansAll = chips.flatMap((c) => c.fans);
  const turning = fansAll.map((f) => f.rpm).filter((r) => typeof r === 'number' && r > 0);
  const offered = chips.filter((c) => c.outputs.length && c.kind !== 'gpu');
  const fans = (o.fans || []).map((f) => clone(f));
  const zones = clone(o.zones || []);
  let hottest = null;
  for (const c of chips) for (const t of c.temps) if (t.c !== null && (!hottest || t.c > hottest.c)) hottest = { c: t.c, kind: c.kind, chip: c.name, label: t.label, n: t.n, disk: null };
  for (const d of disks) if (d.temp !== null && (!hottest || d.temp > hottest.c)) hottest = { c: d.temp, kind: 'disk', chip: null, label: '', n: null, disk: d.name };
  const kinds = hottestKinds(chips, disks);
  const s = {
    v: 1, time: T0, hwmon: true, board: base.board, kernel: '6.18.60-Unraid', unraid: '7.4.0', chips, disks, ipmi: o.ipmi || null,
    controllers: o.controllers || [], drivers_missing: o.drivers_missing || {}, situation: o.situation || { code: 'ok' }, tools: o.tools || TOOLS,
    suggest: o.suggest || [], board_hint: 'ite', board_maker: base.board_maker || 'Example Boards', acpi_lax: false,
    // round 5: Unraid's poll_attributes (its default 1800 s — the hint shows), a virtual machine, chips with two drivers
    poll: o.poll === undefined ? 1800 : o.poll, vm: !!o.vm, twins: o.twins || [], names: [],
    hints_open: ['no_output', 'driver_missing', 'other', 'ipmi'].includes((o.situation || { code: 'ok' }).code),
    counts: { outputs: offered.reduce((n, c) => n + c.outputs.length, 0), fans: fansAll.length, turning: turning.length, zero: fansAll.filter((f) => f.rpm === 0).length,
      rpm_min: turning.length ? Math.min(...turning) : null, rpm_max: turning.length ? Math.max(...turning) : null, temps: chips.reduce((n, c) => n + c.temps.length, 0) },
    hottest, hottest_kinds: kinds, hottest_lead: leadOf(kinds),
    who: o.who || { who: 'bios', why: offered.length ? 'not_set_up' : 'no_output' },
    control: { running: false, armed: !!o.armed, who: 'bios', why: null, zones: [], alarm: null, missing: null, notes: [], watch: [], time: null, guard: null, ...(o.control || {}) },
    setup: {
      board: 'Example Boards Inc. | EB-Z100 PRO', others: 0, fans, zones, armed: !!o.armed, outputs: offered.length ? OUTPUTS : [], missing: [],
      sources: sources(chips, disks), curves: curves(zones), before: offered.length ? clone(BEFORE) : {}, back_default: 'auto',
      scales: SCALES, down: DOWN, round: 2, cpu_floor: 40, presets_of: PRESETS_OF,
    },
    step: o.step || null,
    drivers: o.drivers || { mine: [], last: null, can: true },
    // round 6: the fans he doesn't steer, watched (fanboyIdleState()): on, nothing standing
    idle: o.idle || { on: true, warm: null, tachs: {}, time: T0 },
    show: o.show || null,                     // round 6: «show me this fan» (fanboyShowRead())
    safety: o.safety || {},                   // round 9 (H2): outputs he set to full for safety (fanboySafetyFull())
    restore: o.restore || null,               // round 9 (H2): «back to the value before» running or done
    // round 6: °C his zones add to an old disk value (fanboyPollAllowance(): +1 per started 10 minutes over 300 s, at most 3)
    disk_allow: (() => { const p = o.poll === undefined ? 1800 : o.poll; return p === null || p <= 300 ? 0 : Math.min(3, Math.ceil(p / 600)); })(),
  };
  return s;
}

// ------------------------------------------------------------------ the scenarios
const all = (pick) => [1, 2, 3, 4, 5, 6].map((n) => FANS[n]).filter((f) => !pick || pick.includes(f.output));
const SET_UP = Object.values(FANS);
const RPM = { 1: 1310, 2: 0, 3: 0, 4: 1018, 5: 905, 6: 878 };
const NOTES = [{ code: 'chip_back', params: { minutes: 3 }, at: T0 - 5400 }, { code: 'alarm_over', params: { code: 'hot' }, at: T0 - 2400 }];
function steering(more = {}) {
  const zonesSet = more.zones || ZONES, fansSet = more.fans || SET_UP;
  const rpm = { ...RPM, ...(more.rpm || {}) };
  // an alarm in the case zone (round 4: only that zone at 100 %, its outputs' values too; the disk cage steers on)
  const pc = more.caseFull ? 100 : 40;
  const itec = ite({ 1: [128, 2], 2: [more.caseFull ? 255 : 102, 1], 4: [107, 1], 5: [more.caseFull ? 255 : 102, 1], ...(more.pwm || {}) }, rpm);
  const zonesNow = [
    { id: 'disks', name: 'Plattenkäfig', unit: 'pct', level: 42, full: false, warmest: { id: 'disk2', label: 'disk2', c: 41, mapped: 41, kind: 'disk' }, asleep: 1,
      fans: [{ id: P(4), name: 'Plattenkäfig', output: 4, pct: 42, rpm: rpm[4], held: true, foreign: false }] },
    { id: 'case', name: 'Gehäuse', unit: 'pct', level: pc, full: !!more.caseFull, warmest: { id: NVME, label: 'Composite', c: 27.9, mapped: 27.9, kind: 'nvme' }, asleep: 0,
      fans: [{ id: P(5), name: 'Vorn', output: 5, pct: pc, rpm: rpm[5], held: true, foreign: false }, { id: P(2), name: 'Deckel', output: 2, pct: pc, rpm: null, held: true, foreign: false }] },
  ];
  if (more.zonesNow) zonesNow.push(...more.zonesNow);
  return state({ ite: itec, fans: fansSet, zones: zonesSet, armed: true, who: more.who || { who: 'fanboy', why: 'steers', zones: 2 },
    control: { running: true, armed: true, who: 'fanboy', why: more.why || null, phase: 'run', zones: zonesNow, alarm: more.alarm || null, notes: more.notes || NOTES,
      watch: more.watch || [{ id: P(1), name: 'CPU-Kühler', role: 'cpu', rpm: rpm[1] }], time: T0 } });
}
const fresh = () => state({ ite: ite({}, RPM) });
// round 4: the CPU cooler given to Fanboy on the owner's risk (ticked 3 days ago), in a zone of its own on the CPU's temperatures
const CPU_OWN = SET_UP.map((f) => (f.output === 1 ? { ...f, cpu_own: T0 - 3 * 86400 + 600, measured: true, min_pct: 20, start_pct: 25, max_rpm: 1800,
  table: [[100, 1800], [80, 1460], [60, 1100], [40, 740], [20, 380]] } : f));
const CPU_ZONE = { id: 'cpu', name: 'CPU', fans: [P(1)], unit: 'pct', target: { preset: 'normal' }, low: null, high: null,
  sources: ['Package id 0', 'Core 0', 'Core 4'].map((l) => ({ kind: 'cpu', id: CPU_SRC(l), low: null, high: null, limit: null })), react: 'balanced', kinds: { cpu: { high: 75 } } };
const files = {};
files.fanboy = steering();
files['fanboy-steering'] = steering();
files['fanboy-cpu'] = steering({ fans: CPU_OWN, zones: [...ZONES, CPU_ZONE], watch: [], who: { who: 'fanboy', why: 'steers', zones: 3 }, pwm: { 1: [102, 1] },
  zonesNow: [{ id: 'cpu', name: 'CPU', unit: 'pct', level: 40, full: false, warmest: { id: CPU_SRC('Package id 0'), label: 'Package id 0', c: CORE.temps.find((t) => t.label === 'Package id 0').c, mapped: CORE.temps.find((t) => t.label === 'Package id 0').c, kind: 'cpu' }, asleep: 0,
    fans: [{ id: P(1), name: 'CPU-Kühler', output: 1, pct: 40, rpm: 740, held: true, foreign: false }] }], rpm: { 1: 740 } });
// the alarm acts where it is (round 4): the case zone at 100 %, the disk cage steers on
files['fanboy-alarm'] = steering({ rpm: { 5: 0, 6: 0 }, who: { who: 'fanboy', why: 'alarm', zones: 2 }, why: 'alarm', caseFull: true,
  alarm: { code: 'stands', params: { fan: 'Vorn', zone: 'Gehäuse', zid: 'case', alone: false }, since: T0 - 40 },
  notes: [...NOTES, { code: 'alarm_stands', params: { fan: 'Vorn' }, at: T0 - 40 }] });
// the fan chip gone (its driver missing after an update): no outputs seen, the control waits for it
files['fanboy-missing'] = state({ fans: SET_UP, zones: ZONES, armed: true, situation: { code: 'driver_missing', plugins: ['it87-driver'], modules: ['it87'] },
  drivers_missing: { 'it87-driver': ['it87'] }, tools: TOOLS.map((t) => (t.id === 'it87-driver' ? { ...t, loaded: false } : t)),
  who: { who: 'bios', why: 'chip_missing' },
  control: { running: true, armed: true, why: 'chip_missing', phase: 'wait_chip', missing: { since: T0 - 1200, why: 'missing', told: true }, notes: [...NOTES, { code: 'missing_told', params: {}, at: T0 - 300 }], time: T0 } });
// round 5: the chip went away while he steered — nobody steers, the fans stay where he left them (not «the BIOS»)
files['fanboy-chipgone'] = state({ fans: SET_UP, zones: ZONES, armed: true, situation: { code: 'driver_missing', plugins: ['it87-driver'], modules: ['it87'] },
  drivers_missing: { 'it87-driver': ['it87'] }, tools: TOOLS.map((t) => (t.id === 'it87-driver' ? { ...t, loaded: false } : t)),
  who: { who: 'nobody', why: 'chip_gone' },
  control: { running: true, armed: true, who: 'nobody', why: 'chip_gone', phase: 'wait_chip', missing: { since: T0 - 95, why: 'missing', told: false },
    notes: [...NOTES, { code: 'alarm_chip', params: { fan: 'Plattenkäfig' }, at: T0 - 95 }], time: T0 } });
// another controller steering: FanCtrl Plus
files['fanboy-other'] = state({ ite: ite({}, RPM), situation: { code: 'other', who: ['fanctrlplus'], names: ['FanCtrl Plus'] },
  controllers: [{ id: 'fanctrlplus', name: 'FanCtrl Plus', settings: true, running: true, blocks: true }],
  tools: TOOLS.map((t) => (t.id === 'fanctrlplus' ? { ...t, installed: true, active: true } : t)),
  who: { who: 'fanctrlplus', name: 'FanCtrl Plus', why: 'other' } });
// WAZ Cooling Control running beside him: a hint only (it steers shelves and IPMI fans)
files['fanboy-waz'] = (() => {
  const s = steering();
  s.tools = TOOLS.map((t) => (t.id === 'md12xx.fancontrol' ? { ...t, installed: true, active: true } : t));
  s.controllers = [{ id: 'md12xx.fancontrol', name: 'WAZ Cooling Control', settings: false, running: true, blocks: false }];
  return s;
})();
// a server board: no output Linux shows, a BMC
files['fanboy-ipmi'] = state({ situation: { code: 'ipmi' }, ipmi: { bmc: true, manufacturer: 10876, product: 2398, firmware: '1.74' }, who: { who: 'bmc', why: 'ipmi' }, suggest: [] });
// nothing Linux could steer: the hints open
files['fanboy-nooutput'] = state({ situation: { code: 'no_output' }, suggest: ['it87-driver', 'dynamix.system.temp'],
  tools: TOOLS.map((t) => ({ ...t, installed: false, loaded: t.kind === 'driver' ? false : t.loaded })), who: { who: 'bios', why: 'no_output' } });
// round 5: a virtual machine without a fan chip (the hint: the fans belong to the host); two drivers on one chip; the test
// step having found an output the BIOS keeps (doesn't obey) and one that works the other way round
files['fanboy-vm'] = state({ situation: { code: 'no_output' }, vm: true, suggest: ['it87-driver', 'dynamix.system.temp'],
  tools: TOOLS.map((t) => ({ ...t, installed: false, loaded: t.kind === 'driver' ? false : t.loaded })), who: { who: 'bios', why: 'no_output' } });
files['fanboy-twins'] = state({ ite: ite({}, RPM), twins: ['nct6687', 'nct6683'] });
files['fanboy-obey'] = state({ ite: ite({}, RPM), fans: [fan(1, { tachs: [1], found: 'tach' }), fan(2, { found: 'silent' }), fan(3, { found: 'ignored' }),
  fan(4, { tachs: [4], found: 'tach' }), fan(5, { tachs: [5, 6], found: 'tach' }), fan(6, { tachs: [], found: 'inverted' })] });
// round 6, part 1: before any setup, every fan watched — fan input 4 stood 2 minutes while disk2 is over its warning
// (told), fan input 5 stands since a moment, fan input 6 turns and was seen stopping while everything was cool (learned)
const T = (n) => `${CHIP}@${ADDR}#${n}`;
files['fanboy-watch'] = state({ ite: ite({}, { ...RPM, 4: 0, 5: 0 }),
  idle: { on: true, time: T0, warm: { id: 'disk2', kind: 'disk', c: 41, warn: 40, name: 'Disk 2' }, tachs: {
    [T(1)]: { state: 'turning', quiet_stop: null, since: null, name: 'Fan input 1', strict: false },
    [T(2)]: { state: 'never', quiet_stop: null, since: null, name: 'Fan input 2', strict: false },
    [T(3)]: { state: 'never', quiet_stop: null, since: null, name: 'Fan input 3', strict: false },
    [T(4)]: { state: 'alarm', quiet_stop: null, since: T0 - 180, name: 'Fan input 4', strict: false },
    [T(5)]: { state: 'stands_warm', quiet_stop: null, since: T0 - 40, name: 'Fan input 5', strict: false },
    [T(6)]: { state: 'turning', quiet_stop: T0 - 86400, since: null, name: 'Fan input 6', strict: false } } } });
// round 6, part 4: «show me this fan» — the disk cage shown by the control (100 % for 5 s, then its curve again)
files['fanboy-show'] = (() => {
  const s = steering({ pwm: { 4: [255, 1] }, rpm: { 4: 1590 } });
  s.show = { id: 'b1c2d3e4f5a6', fan: P(4), via: 'control', status: 'running', why: null, until: T0 + 14, pid: 4242, start: '123456', ok: null, begun: T0 - 1, at: T0 - 1, tachs: [4] };
  return s;
})();
// round 7 (F3): another controller uninstalled, every output left on a fixed 50 % — nobody steers (not the BIOS)
files['fanboy-fixed'] = state({ ite: ite({}, RPM), who: { who: 'nobody', why: 'fixed', was: 'not_set_up', fixed: [1, 2, 3, 4, 5, 6].map((n) => ({ id: P(n), output: n, pct: 50 })) } });
// the array stopped while armed: the BIOS runs them
files['fanboy-array'] = state({ ite: ite({}, RPM), fans: SET_UP, zones: ZONES, armed: true, who: { who: 'bios', why: 'array_stopped' },
  control: { running: false, armed: true, why: 'array_stopped', phase: 'ended', notes: NOTES, time: T0 } });

// the wizard, step by step
files['fanboy-fresh'] = fresh();
const running = (kind, fans, current, done, results, more = {}) => ({ id: 'a1b2c3d4e5f6', kind, fans, status: 'running', why: null, pid: 4242, start: '123456',
  current, done, results, progress: null, at: T0 - 2, begun: T0 - 40, ...more });
files['fanboy-test'] = state({ ite: ite({ 3: [255, 1] }, { ...RPM }), fans: [fan(1, { tachs: [1], found: 'tach' }), fan(2, { found: 'ask' })],
  who: { who: 'bios', why: 'not_set_up' },
  step: running('test_up', OUTPUTS, P(3), [P(1), P(2)], { [P(1)]: { found: 'tach', tachs: [1] }, [P(2)]: { found: 'ask', tachs: [] } }) });
files['fanboy-ask'] = state({ ite: ite({}, RPM), fans: [fan(1, { tachs: [1], found: 'tach' }), fan(2, { found: 'ask' }), fan(3, { found: 'empty' }),
  fan(4, { tachs: [4], found: 'tach' }), fan(5, { tachs: [5, 6], found: 'tach' }), fan(6, { found: 'empty' })],
  step: { id: 'a1b2c3d4e5f6', kind: 'test_up', fans: OUTPUTS, status: 'done', why: null, pid: 4242, start: '123456', current: null, done: OUTPUTS, results: {}, progress: null, at: T0 - 30 } });
// round 8 (G1): the quiet test running — output 3 at full, the others standing meanwhile
files['fanboy-quiet'] = state({ ite: ite({ 1: [0, 1], 2: [0, 1], 3: [255, 0], 4: [0, 1], 5: [0, 1], 6: [0, 1] }, { ...RPM, 1: 0, 4: 0, 5: 0, 6: 0 }),
  // round 8: assigned while testing — output 1 the CPU cooler, 2 «Deckel hinten», 3 (under test now) named as he hears it
  fans: [fan(1, { tachs: [1], found: 'tach', role: 'cpu', name: 'CPU-Kühler' }), fan(2, { found: 'ask', name: 'Deckel hinten' }), fan(3, { name: 'Vorn unten', role: 'case' })],
  who: { who: 'bios', why: 'not_set_up' },
  step: running('test_up', OUTPUTS, P(3), [P(1), P(2)], { [P(1)]: { found: 'tach', tachs: [1] }, [P(2)]: { found: 'ask', tachs: [] } },
    { quiet: true, progress: { id: P(3), phase: 'full', quiet: true, until: T0 + 48 } }) });
// round 9 (H3): the pause after output 2 — everything back, the fans starting again
files['fanboy-pause'] = state({ ite: ite({}, { ...RPM, 4: 380, 5: 0 }),
  fans: [fan(1, { tachs: [1], found: 'tach', role: 'cpu', name: 'CPU-Kühler' }), fan(2, { found: 'ask', name: 'Deckel hinten' })],
  who: { who: 'bios', why: 'not_set_up' },
  step: running('test_up', OUTPUTS, P(2), [P(1)], { [P(1)]: { found: 'tach', tachs: [1] } }, { quiet: true, progress: { id: P(2), phase: 'pause', until: T0 + 4 } }) });
// round 9 (H2): output 5's fan didn't start again after the quiet test, not even after a kick — at 100 % for safety
files['fanboy-safety'] = state({ ite: ite({ 5: [255, 1] }, { ...RPM, 5: 2960, 6: 2880 }),
  fans: [fan(1, { tachs: [1], found: 'tach', role: 'cpu', name: 'CPU-Kühler' }), fan(5, { tachs: [5, 6], found: 'tach', name: 'Vorn unten' })],
  who: { who: 'bios', why: 'not_set_up' },
  safety: { [P(5)]: { why: 'no_restart', at: T0 - 120, before: { value: 128, enable: 1 } } } });
const tested = [fan(1, { tachs: [1], found: 'tach' }), fan(2, { found: 'silent' }), fan(3, { found: 'empty' }), fan(4, { tachs: [4], found: 'tach' }),
  fan(5, { tachs: [5, 6], found: 'tach' }), fan(6, { found: 'empty' })];
files['fanboy-roles'] = state({ ite: ite({}, RPM), fans: [{ ...tested[0], role: 'cpu', name: 'CPU-Kühler', place: 'rear' }, tested[1], tested[2],
  { ...tested[3], role: 'disks', name: 'Plattenkäfig', place: 'front', model: { id: 'noctua-nf-a12x25-pwm', mm: null, blades: null } }, tested[4], tested[5]] });
files['fanboy-measure'] = state({ ite: ite({ 4: [115, 1] }, { ...RPM, 4: 896 }), fans: SET_UP.map((f) => (f.output === 5 ? { ...f, measured: false, min_pct: null, start_pct: null, max_rpm: null, table: null }
  : f.output === 4 ? { ...f, measured: false, min_pct: null, start_pct: null, max_rpm: null, table: null } : f)),
  step: running('measure', [P(4), P(5)], P(4), [], {}, { progress: { id: P(4), table: TABLE4.slice(0, 7) } }) });
files['fanboy-zones'] = state({ ite: ite({}, RPM), fans: SET_UP });
files['fanboy-ready'] = state({ ite: ite({}, RPM), fans: SET_UP, zones: ZONES, who: { who: 'bios', why: 'not_armed' } });
files['fanboy-gone'] = state({ ite: ite({}, RPM), fans: [fan(1, { tachs: [1], found: 'tach' }), fan(2, { found: 'ask' })],
  step: { id: 'a1b2c3d4e5f6', kind: 'test_up', fans: OUTPUTS, status: 'stopped', why: 'page_gone', pid: 4242, start: '123456', current: null, done: [P(1), P(2)], results: {}, progress: null, at: T0 - 20 } });

// the curve view's history (invented): three days for the disk cage, 30 hours for the case (a zone added later — the page
// says «recorded only since …»), a point a minute, then kept as the agent keeps it (fanboyHistoryThin(): 12 h of minutes,
// older ones a 5-minute span carrying its warmest, highest level and rpm). Warm afternoons, cool nights.
function thin(pts, wall) {
  const full = [], spans = new Map();
  for (const p of pts) {
    if (p[0] < wall - 259200) continue;
    if (p[0] >= wall - 43200) { full.push(p); continue; }
    const k = Math.floor(p[0] / 300), b = spans.get(k);
    spans.set(k, b ? [Math.max(b[0], p[0]), Math.max(b[1], p[1]), Math.max(b[2], p[2]), Math.max(b[3], p[3])] : p);
  }
  return [...[...spans.keys()].sort((a, b) => a - b).map((k) => spans.get(k)), ...full].slice(-1460);
}
const raw = { disks: [], case: [] };
for (let m = 72 * 60; m >= 0; m--) {
  const t = T0 - m * 60;
  const day = Math.sin(((t / 3600 + 1) % 24 - 9) / 24 * 2 * Math.PI);        // warmest mid-afternoon
  const dc = Math.round((34.5 + 4 * day + Math.sin(m / 9) * 0.6 + (m % 977 === 0 ? 3 : 0)) * 10) / 10;
  const cc = Math.round((26 + 3.5 * day + Math.sin(m / 7) * 0.8) * 10) / 10;
  const dl = Math.max(20, Math.min(100, Math.round(20 + (dc - 35) / 5 * 25)));
  const cl = Math.max(25, Math.min(100, Math.round(25 + (cc - 30) * 1.5)));
  raw.disks.push([t, dc, dl, Math.round(tableRpm(TABLE4, dl))]);
  if (m <= 30 * 60) raw.case.push([t, cc, cl, Math.round(tableRpm(TABLE5, cl))]);
}
const kept = { disks: thin(raw.disks, T0), case: thin(raw.case, T0) };
const window = (h) => Object.fromEntries(Object.entries(kept).map(([z, pts]) => [z, pts.filter((p) => p[0] >= T0 - h * 3600)]));
const histOf = (h) => ({ v: 1, time: T0, now: T0, hours: h, every: 60, thin: 300, full: 43200, zones: window(h) });
const hist = histOf(24);
function tableRpm(table, pct) {
  const pts = table.slice().sort((a, b) => a[0] - b[0]);
  if (pct <= pts[0][0]) return pts[0][1];
  for (let i = 1; i < pts.length; i++) if (pct <= pts[i][0]) { const [p0, r0] = pts[i - 1], [p1, r1] = pts[i]; return r0 + (r1 - r0) * (pct - p0) / (p1 - p0); }
  return pts[pts.length - 1][1];
}
// the dry run's answer (a canned post for the shots: demo/post/fanboy.dry_run.json)
const dry = { ok: true, dry: { found: true, why: null, time: T0, zones: [
  { id: 'disks', name: 'Plattenkäfig', unit: 'pct', level: 42, warmest: { id: 'disk2', label: 'disk2', c: 41, mapped: 41, kind: 'disk' }, asleep: 1, quiet: 0, react: 'balanced', down: DOWN.disk.balanced,
    fans: [{ id: P(4), name: 'Plattenkäfig', output: 4, pct: 42, value: 107, now: { value: 128, enable: 1 }, floor: 30 }] },
  { id: 'case', name: 'Gehäuse', unit: 'pct', level: 25, warmest: { id: NVME, label: 'Composite', c: 27.9, mapped: 27.9, kind: 'nvme' }, asleep: 0, quiet: 0, react: 'quick', down: DOWN.nvme.quick,
    fans: [{ id: P(5), name: 'Vorn', output: 5, pct: 35, value: 89, now: { value: 128, enable: 1 }, floor: 35 }, { id: P(2), name: 'Deckel', output: 2, pct: 40, value: 102, now: { value: 128, enable: 1 }, floor: 40 }] }] } };

const write = (dir, name, v) => { fs.writeFileSync(path.join(dir, `${name}.json`), JSON.stringify(v, null, 1) + '\n'); console.log(path.relative(REPO, path.join(dir, `${name}.json`))); };
for (const [name, v] of Object.entries(files)) write(DEMO, name, v);
write(DEMO, 'fanboy-history', hist);
fs.mkdirSync(path.join(DEMO, 'post'), { recursive: true });
write(path.join(DEMO, 'post'), 'fanboy.dry_run', dry);
// the 3 days the page asks for (history {hours: 72})
write(path.join(DEMO, 'post'), 'fanboy.history', { ok: true, state: histOf(72) });
fs.mkdirSync(path.join(STATES, 'post'), { recursive: true });
write(path.join(STATES, 'post'), 'fanboy.history', { ok: true, state: histOf(72) });
// the click test's stub: the overview steering (every tile has its section), its history
write(STATES, 'fanboy', files.fanboy);
write(STATES, 'fanboy-history', hist);
