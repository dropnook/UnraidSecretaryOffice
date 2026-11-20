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
  // (round 13b: a disk outside array and pools carries its group, the Unassigned Devices plugin's name or none, its size, «in use»)
  const out = disks.map((d) => ({ kind: d.rotational === false ? 'ssd' : 'disk', id: d.name, label: d.type === 'unassigned' ? d.label || '' : d.name, c: d.temp, asleep: d.asleep,
    ...(d.type === 'unassigned' ? { group: 'unassigned', name: d.label || null, size: d.size || 0, in_use: !!d.in_use } : {}) }));
  for (const c of chips) {
    if (c.kind === 'disk') continue;
    for (const t of c.temps) out.push({ kind: c.kind, id: `${c.name}@${devKey(c.device)}#${t.n}`, label: t.label || `${c.name} #${t.n}`, c: t.c, asleep: false, chip: c.name, hwmon: c.hwmon, n: t.n });
  }
  return out;
}
/** The heat sources no zone has, as fanboyUncovered() lists them: disks, SSDs and NVMe (FANBOY_NET_KINDS) in none of the zones */
function uncovered(zones, all) {
  const inZones = new Set((zones || []).flatMap((z) => (z.sources || []).map((x) => x.id)));
  return all.filter((x) => ['disk', 'ssd', 'nvme'].includes(x.kind) && !inZones.has(x.id)).map((x) => ({ kind: x.kind, id: x.id, name: x.label, c: x.c, asleep: x.asleep }));
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
  const f = { id: P(n), chip: CHIP, output: n, tachs: [], model: null, colour: null, frame: null, wheel: null, style: null, back: null, count: 1, found: null, role: null, name: '', place: null, off_ok: false,
    measured: false, cpu_own: null, min_pct: null, start_pct: null, max_rpm: null, table: null, no_turn: false, kick_start: null, stand: null, stand_cut: null, floor: null, start: null, curve: null, fallback: null, off_why: null, may_stand: false, cpu_off: null, alike: null, hub: null };
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
/** fanboyKindValues(), fanboyZoneScale(), fanboyZoneCurve(), fanboyKindCurve() of agent/desks/fanboy-control.php (round 13), for the invented data */
function kindValues(z, k) {
  const own = (z.kinds || {})[k] || {};
  const d = DEFAULTS[k] || DEFAULTS.other;
  return { low: own.low ?? d[0], high: own.high ?? d[1] };
}
function zoneScale(z) {
  const d = kindValues(z, z.sources[0] ? z.sources[0].kind : 'disk');
  const lo = z.low ?? d.low, hi = z.high ?? d.high;
  return [lo, hi > lo ? hi : lo + 10];
}
const zoneCurve = (z) => (z.target.points ? z.target.points.map((p) => [p[0], p[1]]).sort((a, b) => a[0] - b[0]) : preset(z.target.preset, ...zoneScale(z)));
function kindCurve(z, k) {
  const kv = kindValues(z, k), own = (z.curves || {})[k];
  if (own && own.points) return { points: own.points.map((p) => [p[0], p[1]]).sort((a, b) => a[0] - b[0]), own: true };
  if (own && own.preset) return { points: preset(own.preset, kv.low, kv.high > kv.low ? kv.high : kv.low + 10), own: true };
  const [lo, hi] = zoneScale(z), curve = zoneCurve(z);
  return { points: kv.high <= kv.low ? curve : curve.map((p) => [kv.low + (p[0] - lo) * (kv.high - kv.low) / (hi - lo), p[1]]), own: false };
}
const zoneKinds = (z) => [...new Set(z.sources.map((x) => x.kind))];
function curveAt(pts, t) {
  if (t <= pts[0][0]) return pts[0][1];
  for (let i = 1; i < pts.length; i++) if (t <= pts[i][0]) return pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * (t - pts[i - 1][0]) / (pts[i][0] - pts[i - 1][0]);
  return pts[pts.length - 1][1];
}
function curves(zones) {
  const out = {};
  for (const z of zones) {
    const [lo, hi] = zoneScale(z);
    const kinds = {};
    for (const k of zoneKinds(z)) {
      const kv = kindValues(z, k), kh = kv.high > kv.low ? kv.high : kv.low + 10;
      kinds[k] = { ...kindCurve(z, k), scale: [kv.low, kh], presets: presets(kv.low, kh) };
    }
    out[z.id] = { points: zoneCurve(z), scale: [lo, hi], presets: presets(lo, hi), kinds };
  }
  return out;
}
/**
 * fanboyFanPlan() of agent/desks/fanboy-control.php for the invented fans: the floor (lowest steady + 5, blind 40, a CPU
 * cooler of his 40 at least; several unlike fans on one connector: lowest steady + 10, 25 at least, plus what the look
 * added) and the start value — what the setup state carries per fan of his (`floor`, `start`, `hub`)
 */
function plan(f) {
  const tach = f.found === 'tach', m = f.measured && f.table;
  const cpu = f.role === 'cpu' && !!f.cpu_own;
  let floor = m && tach && typeof f.min_pct === 'number' ? Math.min(100, f.min_pct + 5) : 40;
  if (cpu) floor = Math.max(floor, 40);
  let start = m && typeof f.start_pct === 'number' ? f.start_pct : floor;
  if (m && f.stand && f.stand.stops === true) start = typeof f.stand.start_from_stop === 'number' ? Math.min(100, f.stand.start_from_stop + 5) : 100;
  const hub = (f.count || 1) > 1 && f.alike !== true;
  if (hub) {
    const extra = f.hub_extra || 0;
    floor = Math.min(100, Math.max(25, m && tach && typeof f.min_pct === 'number' ? f.min_pct + 10 : floor) + extra);
    start = Math.min(100, start + 10);
  }
  if (cpu) floor = Math.max(floor, 40);
  return { floor, start };
}
const ours = (f) => ['tach', 'silent'].includes(f.found) && !!f.role && !['pump', 'unknown', 'empty'].includes(f.role) && (f.role !== 'cpu' || !!f.cpu_own);
function withPlan(f) {
  const p = plan(f);
  const out = { ...f, floor: ours(f) ? p.floor : null };
  out.hub = (f.count || 1) > 1 ? { mixed: f.alike !== true, one_fan_table: true, ok: (f.hub && f.hub.ok) || null, not_safe: (f.hub && f.hub.not_safe) || null, extra: f.hub_extra || 0, floor: p.floor, start: p.start } : null;
  delete out.hub_extra;
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
  const fans = (o.fans || []).map((f) => withPlan(clone(f)));
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
    control: { running: false, armed: !!o.armed, who: 'bios', why: null, zones: [], alarm: null, missing: null, notes: [], watch: [], time: null, guard: null,
      // round 12 fix, the net: while he steers, the heat sources in none of his zones (what the net watches); the net itself off
      loose: o.control && o.control.running ? uncovered(zones, sources(chips, disks)).filter((u) => !u.asleep).map((u) => ({ id: u.id, kind: u.kind, label: u.name, c: u.c })) : [], net: null, ...(o.control || {}) },
    setup: {
      auto_back_seen: null, uncovered: uncovered(zones, sources(chips, disks)), board: 'Example Boards Inc. | EB-Z100 PRO', others: 0, fans, zones, armed: !!o.armed, outputs: offered.length ? OUTPUTS : [], missing: [],
      sources: sources(chips, disks), curves: curves(zones), before: offered.length ? clone(BEFORE) : {}, back_default: 'auto',
      scales: SCALES, down: DOWN, round: 2, presets_of: PRESETS_OF,
      // the numbers the page's sentences state, as the agent's setup state gives them (fanboyTimes(); testFanboyTimes ties both)
      times: { clear_after: 120, up_for: 60, up_min: 8, show_for: 60, lease: 45, tell_after: 900, idle_warm_after: 120, stand_after: 15, history_every: 60,
        history_periods: [1, 3, 6, 12, 24, 72], poll_live: 300, crash_pause: 30, crash_window: 1800, crash_max: 3, net: { warm_pct: 60, hot_pct: 100, gap: 5, clear: 120 } },
      limits: { name_max: 40, count_max: 8, zones_max: 8, sources_max: 32 },
      restarts: { deaths: 0, max: 3, window: 1800, given_up: false },
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
  // round 13: while he steers, each zone says its kinds' curves, what each kind's warmest source asks for (by_kind) and
  // who leads — worked out from the invented temperatures by the control's rule (the highest ask wins)
  for (const cz of s.control.running ? s.control.zones : []) {
    const z = zones.find((x) => x.id === cz.id);
    if (!z) continue;
    cz.curves = Object.fromEntries(zoneKinds(z).map((k) => [k, kindCurve(z, k)]));
    cz.by_kind = {};
    for (const k of zoneKinds(z)) {
      const asks = z.sources.filter((x) => x.kind === k).map((x) => s.setup.sources.find((y) => y.id === x.id)).filter((x) => x && !x.asleep && typeof x.c === 'number')
        .map((x) => ({ source: x.id, label: x.label, c: x.c, value: Math.round(curveAt(cz.curves[k].points, x.c) * 10) / 10 })).sort((a, b) => b.value - a.value || b.c - a.c);
      if (asks.length) cz.by_kind[k] = asks[0];
    }
    const top = Object.entries(cz.by_kind).sort((a, b) => b[1].value - a[1].value)[0];
    cz.leader = top ? { source: top[1].source, kind: top[0] } : null;
    cz.in_use = [];
    if (top && !cz.full) {
      // (the invented level follows what the leader asks, never under each fan's floor)
      cz.level = Math.round(top[1].value);
      cz.warmest = { ...cz.warmest, id: top[1].source, label: top[1].label, c: top[1].c, mapped: top[1].c, kind: top[0], value: top[1].value };
      cz.fans.forEach((f) => { const sf = fans.find((x) => x.id === f.id); f.pct = Math.max(cz.level, sf && typeof sf.floor === 'number' ? sf.floor : 40); });
    }
  }
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
// round 13b: disks outside array and pools (devs.ini) — one the Unassigned Devices plugin names, two alike without a
// name (one asleep, one a VM uses while Unraid shows no temperature); the named one is a heat source of the disk cage
const DISKS_UD = [
  { name: 'parity', type: 'parity', rotational: true, asleep: false, temp: 38, warn: 45, crit: 55 },
  { name: 'disk1', type: 'data', rotational: true, asleep: true, temp: null, warn: 45, crit: 55 },
  { name: 'disk2', type: 'data', rotational: true, asleep: false, temp: 41, warn: 40, crit: 50 },
  { name: 'cache', type: 'pool', rotational: false, asleep: false, temp: 46, warn: 60, crit: 70 },
  { name: 'ud-3f9a6c21d07e', type: 'unassigned', rotational: true, asleep: false, temp: 36, device: 'sdx', label: 'Familienfilme', size: 16000900608000, in_use: false, warn: 45, crit: 55 },
  { name: 'ud-a41b77e09c52', type: 'unassigned', rotational: true, asleep: true, temp: null, device: 'sdy', label: null, size: 14000519643136, in_use: false, warn: 45, crit: 55 },
  { name: 'ud-5d2e80f1b6a3', type: 'unassigned', rotational: true, asleep: false, temp: null, device: 'sdz', label: null, size: 14000519643136, in_use: true, warn: 45, crit: 55 },
];
// … and as the owner's server shows them: four such disks with Unraid's own names, all asleep, none in a zone
files['fanboy-uxloose'] = state({ ite: ite({}, RPM), fans: SET_UP, zones: ZONES, who: { who: 'bios', why: 'not_armed' },
  disks: [...DISKS_UD.slice(0, 3), ...[1, 2, 3, 4].map((n) => ({ name: `ud-${'7c1e9a0b3d5f'.slice(n)}${'42a6'.slice(0, n)}`, type: 'unassigned', rotational: true, asleep: true, temp: null, device: `sd${'wxyz'[n - 1]}`, label: `dev${n}`, size: 14000519643136, in_use: false, warn: 45, crit: 55 }))] });
files['fanboy-uxdevs'] = state({ ite: ite({}, RPM), fans: SET_UP, disks: DISKS_UD, who: { who: 'bios', why: 'not_armed' },
  zones: [{ ...ZONES[0], sources: [...ZONES[0].sources, { kind: 'disk', id: 'ud-3f9a6c21d07e', low: null, high: null, limit: null }, { kind: 'disk', id: 'ud-5d2e80f1b6a3', low: null, high: null, limit: null }] }, ZONES[1]] });
// round 12 fix, the net: the SSD of the pool is in no zone and warm — every fan he steers at 60 % or more, said in the banner and under «Zuletzt»
{
  const st = steering({ pwm: { 2: [153, 1], 4: [153, 1], 5: [153, 1] }, rpm: { 4: 1240, 5: 1090 }, notes: [...NOTES, { code: 'net', params: { source: 'cache', c: 61 }, at: T0 - 90 }] });
  const cache = st.setup.sources.find((x) => x.id === 'cache');
  cache.c = 61;
  st.disks.forEach((d) => { if (d.name === 'cache') d.temp = 61; });
  st.setup.uncovered.forEach((u) => { if (u.id === 'cache') u.c = 61; });
  st.control.loose.forEach((u) => { if (u.id === 'cache') u.c = 61; });
  st.control.zones.forEach((z) => { z.level = Math.max(z.level, 60); z.fans.forEach((f) => { f.pct = Math.max(f.pct, 60); }); });
  st.control.net = { level: 60, since: T0 - 90, source: { id: 'cache', label: 'cache', kind: 'ssd', c: 61, warm: 60, limit: 70 }, clearing: false };
  files['fanboy-uxnet'] = st;
}
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

// ---- the setup thought through as one flow (fanboy-ux, 2026-11-15): step 2's list and panel in each of its moments ----
// a quiet test running, the fields in use: output 1 is known as the CPU cooler (never stopped again), 2 heard nothing
// (nothing attached), 3 at full right now — named and given its role while it runs —, 4 to 6 wait and stand meanwhile
const UX_DONE = { [P(1)]: { found: 'tach', tachs: [1] }, [P(2)]: { found: 'empty', tachs: [] } };
files['fanboy-uxrun'] = state({ ite: ite({ 1: [128, 1], 2: [0, 1], 3: [255, 0], 4: [0, 1], 5: [0, 1], 6: [0, 1] }, { ...RPM, 4: 0, 5: 0, 6: 0 }),
  fans: [fan(1, { tachs: [1], found: 'tach', role: 'cpu', name: 'CPU-Kühler', place: 'rear' }), fan(2, { found: 'empty' }), fan(3, { name: 'Vorn unten', role: 'case' })],
  who: { who: 'bios', why: 'not_set_up' },
  step: running('test_up', OUTPUTS, P(3), [P(1), P(2)], UX_DONE, { quiet: true, progress: { id: P(3), phase: 'full', quiet: true, until: T0 + 41 } }) });
// the restart pause after output 3: everything back as it was, the fans starting again, output 4 next
files['fanboy-uxpause'] = state({ ite: ite({}, { ...RPM, 4: 310, 5: 420, 6: 380 }),
  fans: [fan(1, { tachs: [1], found: 'tach', role: 'cpu', name: 'CPU-Kühler', place: 'rear' }), fan(2, { found: 'empty' }), fan(3, { found: 'silent', name: 'Vorn unten', role: 'case', place: 'front' })],
  who: { who: 'bios', why: 'not_set_up' },
  step: running('test_up', OUTPUTS, P(3), [P(1), P(2)], UX_DONE, { quiet: true, progress: { id: P(3), phase: 'pause', until: T0 + 11, next: P(4) } }) });
// everything tested and assigned (the panel of the disk cage: found, named, its model chosen)
const UX_FOUND = [fan(1, { tachs: [1], found: 'tach', role: 'cpu', name: 'CPU-Kühler', place: 'rear' }), fan(2, { found: 'empty' }),
  fan(3, { found: 'silent', name: 'Vorn unten', role: 'case', place: 'front' }),
  fan(4, { tachs: [4], found: 'tach', role: 'disks', name: 'Plattenkäfig', place: 'front', model: { id: 'noctua-nf-a12x25-pwm', mm: null, blades: null } }),
  // (output 5: a beige model in its black edition — the user's colour paints the whole fan)
  fan(5, { tachs: [5, 6], found: 'tach', role: 'case', name: 'Vorn', place: 'front', count: 2, model: { id: 'noctua-nf-a12x25-pwm', mm: null, blades: null }, colour: 'black' }), fan(6, { found: 'empty' })];
files['fanboy-uxfound'] = state({ ite: ite({}, RPM), fans: UX_FOUND,
  step: { id: 'a1b2c3d4e5f6', kind: 'test_up', fans: OUTPUTS, status: 'done', why: null, pid: 4242, start: '123456', current: null, done: OUTPUTS, results: {}, progress: null, at: T0 - 30 } });
// a fan that didn't start again after standing: Fanboy set its output to full for safety — said, with the way back
files['fanboy-uxsafety'] = state({ ite: ite({ 5: [255, 1] }, { ...RPM, 5: 1440, 6: 1410 }), fans: UX_FOUND.slice(0, 5),
  safety: { [P(5)]: { why: 'no_restart', at: T0 - 50, before: { value: 128, enable: 1 } } },
  step: { id: 'a1b2c3d4e5f6', kind: 'test_up', fans: OUTPUTS, status: 'stopped', why: 'no_restart', quiet: true, quiet_stop: { what: 'Vorn', c: null }, pid: 4242, start: '123456', current: null,
    done: [P(1), P(2), P(3), P(4), P(5)], results: {}, progress: null, at: T0 - 50 } });
// one output still to test (the picture tool has its start refused: the CPU is warm — the strip says so and keeps it)
files['fanboy-uxopen'] = state({ ite: ite({}, RPM), fans: UX_FOUND.slice(0, 5) });

// round 14: two unlike fans on «Vorn»'s connector — Fanboy keeps them higher (the hub's floor and start), «darf stehen»
// waits for the look for the eye; and that look running: «do all fans turn at 40 %?»
const HUB = SET_UP.map((f) => (f.output === 5 ? { ...f, count: 2, alike: false, stand: { stops: true, floor_rpm: null, start_from_stop: 35, kick_start: null, stops_safe: true }, off_ok: true, off_why: 'mixed_hub', may_stand: false } : f));
files['fanboy-uxhub'] = state({ ite: ite({}, RPM), fans: HUB, zones: ZONES, who: { who: 'bios', why: 'not_armed' } });
files['fanboy-uxhublook'] = state({ ite: ite({ 5: [102, 1] }, { ...RPM, 5: 610 }), fans: HUB, zones: ZONES, who: { who: 'bios', why: 'not_armed' },
  step: running('hub_look', [P(5)], P(5), [], {}, { progress: { phase: 'hub_turn', pct: 40, until: T0 + 48 } }) });
// round 12 on the page: the measuring's standstill part running (the disk cage's output at 0 — does it stop?), and its
// results — the disk cage stands and starts again from 35 %, «Vorn» never stands quite still, the CPU cooler on the
// owner's risk stands and needs a push (it may stand only behind its own confirmation)
files['fanboy-uxstop'] = state({ ite: ite({ 4: [0, 1] }, { ...RPM, 4: 260 }), fans: SET_UP.map((f) => (f.output === 5 ? { ...f, measured: false, min_pct: null, start_pct: null, max_rpm: null, table: null } : f)),
  step: running('measure', [P(4), P(5)], P(4), [], {}, { progress: { id: P(4), table: TABLE4, phase: 'stop_check', until: T0 + 12, next: null } }) });
const STAND = { 1: { stops: true, floor_rpm: null, start_from_stop: null, kick_start: 30, stops_safe: true }, 4: { stops: true, floor_rpm: null, start_from_stop: 35, kick_start: null, stops_safe: true },
  5: { stops: false, floor_rpm: 420, start_from_stop: null, kick_start: null, stops_safe: null } };
files['fanboy-uxstand'] = state({ ite: ite({}, RPM), zones: [...ZONES, CPU_ZONE], who: { who: 'bios', why: 'not_armed' },
  fans: CPU_OWN.map((f) => (STAND[f.output] ? { ...f, stand: STAND[f.output], off_ok: f.output === 4, may_stand: f.output === 4 } : f)) });

// the curve view's history (invented): three days for the disk cage and the CPU, 30 hours for the case (a zone added later
// — the page says «recorded only since …»), a point a minute, then kept as the agent keeps it (fanboyHistoryThin(): 12 h
// of minutes, older ones a 5-minute span carrying its warmest, highest level and rpm, and who led at the warmest
// moment). Warm afternoons, cool nights. 2026-11-17: each point names the source that led (the fifth value); the disk
// cage's points older than two days carry none — recorded before the agent kept it (the page says «wärmste Stelle»).
function thin(pts, wall) {
  const full = [], spans = new Map();
  for (const p of pts) {
    if (p[0] < wall - 259200) continue;
    if (p[0] >= wall - 43200) { full.push(p); continue; }
    const k = Math.floor(p[0] / 300), b = spans.get(k);
    const peak = b && b[1] > p[1] ? b : p;
    spans.set(k, b ? [Math.max(b[0], p[0]), Math.max(b[1], p[1]), Math.max(b[2], p[2]), Math.max(b[3], p[3]), ...(peak.length > 4 ? [peak[4]] : [])] : p);
  }
  return [...[...spans.keys()].sort((a, b) => a - b).map((k) => spans.get(k)), ...full].slice(-1460);
}
/** fanboyHistoryPack(): points of four, the leaders beside them (src once each, leads per zone a point) */
function pack(zones, curves = {}) {
  const src = [], leads = {}, out = {};
  for (const [z, pts] of Object.entries(zones)) {
    out[z] = pts.map((p) => p.slice(0, 4));
    const l = pts.map((p) => (p.length > 4 ? (src.includes(p[4]) ? src.indexOf(p[4]) : src.push(p[4]) - 1) : null));
    if (l.some((x) => x !== null)) leads[z] = l;
  }
  // 2026-11-20: each curve's line of a zone of two or more curves — a row per 5-minute span from `t0`, each curve's highest whole °C
  const cv = {};
  for (const [z, c] of Object.entries(curves)) {
    const ts = [...c.rows.keys()].sort((a, b) => a - b);
    if (!ts.length) continue;
    const v = [];
    for (let t = ts[0]; t <= ts[ts.length - 1]; t += 300) v.push(c.rows.has(t) ? c.rows.get(t) : null);
    cv[z] = { kinds: c.kinds, every: 300, t0: ts[0], v };
  }
  return { zones: out, src, leads, ...(Object.keys(cv).length ? { curves: cv } : {}) };
}
const raw = { disks: [], case: [], cpu: [] };
// the case zone's two curves (NVMe, RAM): where each stood, the one leading at the zone's temperature
const caseCurves = { kinds: ['nvme', 'ram'], rows: new Map() };
for (let m = 72 * 60; m >= 0; m--) {
  const t = T0 - m * 60;
  const day = Math.sin(((t / 3600 + 1) % 24 - 9) / 24 * 2 * Math.PI);        // warmest mid-afternoon
  const dc = Math.round((34.5 + 4 * day + Math.sin(m / 9) * 0.6 + (m % 977 === 0 ? 3 : 0)) * 10) / 10;
  const cc = Math.round((26 + 3.5 * day + Math.sin(m / 7) * 0.8) * 10) / 10;
  const uc = Math.round((44 + 6 * day + Math.sin(m / 5) * 3 + ((m + 150) % 311 < 6 ? 14 : 0)) * 10) / 10;       // the CPU: short busy bursts
  const dl = Math.max(20, Math.min(100, Math.round(20 + (dc - 35) / 5 * 25)));
  const cl = Math.max(25, Math.min(100, Math.round(25 + (cc - 30) * 1.5)));
  const ul = Math.max(30, Math.min(100, Math.round(30 + (uc - 45) * 2.5)));
  const dLead = m > 48 * 60 ? [] : [dc >= 35 ? 'disk2' : 'parity'];
  raw.disks.push([t, dc, dl, Math.round(tableRpm(TABLE4, dl)), ...dLead]);
  if (m <= 30 * 60) {
    const ramLeads = Math.sin(m / 50) > 0.6;
    raw.case.push([t, cc, cl, Math.round(tableRpm(TABLE5, cl)), ramLeads ? RAM : NVME]);
    const nv = Math.round(ramLeads ? cc - 4 - Math.sin(m / 13) : cc), ram = Math.round(ramLeads ? cc : cc - 6 + Math.sin(m / 11) * 2);
    const k = Math.floor(t / 300) * 300, r = caseCurves.rows.get(k);
    caseCurves.rows.set(k, r ? [Math.max(r[0], nv), Math.max(r[1], ram)] : [nv, ram]);
  }
  raw.cpu.push([t, uc, ul, Math.round(740 + (ul - 40) * 9), CPU_SRC((m + 150) % 311 < 6 ? 'Core 4' : 'Package id 0')]);
}
const kept = { disks: thin(raw.disks, T0), case: thin(raw.case, T0), cpu: thin(raw.cpu, T0) };
const window = (h) => Object.fromEntries(Object.entries(kept).map(([z, pts]) => [z, pts.filter((p) => p[0] >= T0 - h * 3600)]));
const curvesIn = (h) => ({ case: { kinds: caseCurves.kinds, rows: new Map([...caseCurves.rows].filter(([k]) => k > T0 - h * 3600 - 600)) } });
const histOf = (h) => ({ v: 1, time: T0, now: T0, hours: h, every: 60, thin: 300, full: 43200, ...pack(window(h), curvesIn(h)) });
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
