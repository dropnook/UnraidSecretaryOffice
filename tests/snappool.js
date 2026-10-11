/*
 * Ms. Snapshotini's pool tiles: a pool's snapshots by where they come from (public/desks/snapshot/desk.js poolSplit() and
 * splitLines(), under node) — the counts and sums per source (the engine's, a schedule's, by hand, a partner's copies,
 * other tools', Docker's last), sources without a snapshot left out, «All» from the pool's usedbysnapshots, the first
 * sized line «≥ … freed by deleting», the others «≥ …», each with the tip why «at least»; btrfs (and Docker) counts only.
 *
 *   node tests/snappool.js public/desks/snapshot/desk.js     → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testSnapPoolSplit runs it on the server; on the Mac it runs as it is.
 */
'use strict';
const fs = require('fs');
const path = require('path');

globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.Office = {
  scope: () => T, t: T, el: () => ({}), store: () => null, storeJson: () => null, desk: () => {}, places: () => {}, placesFrom: () => {},
  placesTook: () => {}, agent: { running: true }, lang: 'en',
  fmt: { size: (b) => `${b}B`, number: (n) => String(n), date: (t) => `D${t}`, relative: (t) => `R${t}`, time: (t) => `H${t}` },
};
const file = process.argv[2] || path.join(__dirname, '../public/desks/snapshot/desk.js');
(0, eval)(fs.readFileSync(file, 'utf8'));
const S = OFFICE_DESK_TESTS.snapshot;

const out = { pass: 0, fail: [] };
const same = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) out.pass++; else out.fail.push([name, got, want]);
};

// her state: the engine's prefix, one schedule; snapshots on «daten» of every kind, one on «cache»
S.setState({ backup: { prefixes: ['uso-backup-'] }, plans: { plans: [{ id: 'hourly', label: 'Hourly', enabled: true }] }, zfs: { pools: [], snapshots: [] } });
let n = 0;
const snap = (pool, name, used, extra) => ({ id: `zfs:${pool}/ds@${name}-${++n}`, fs: 'zfs', pool, ds: `${pool}/ds`, name, used, ...extra });
const list = [
  snap('daten', 'uso-backup-20261009-0430', 400),
  snap('daten', 'uso-backup-20261008-0430', 10),
  snap('daten', 'uso-plan-hourly-20261010-1400', 100),
  snap('daten', 'uso-plan-hourly-20261010-1500', 20),
  snap('daten', 'manual-20261001-0900', 9),
  snap('daten', 'uso-backup-20261009-0430', 150, { partner: { id: 'a1b2c3d4', name: 'vault' } }),
  snap('daten', 'autosnap_2026-10-01_hourly', 0),
  snap('daten', 'a1b2c3d4e5f6', 7, { docker: true }),
  snap('cache', 'uso-backup-20261009-0430', 5000),
];
const sp = S.poolSplit(list, { name: 'daten', snapused: 692, fs: 'zfs' });
same('split: «All» counts without Docker\'s, its size the pool\'s usedbysnapshots, sized', [sp.n, sp.used, sp.sized], [7, 692, true]);
same('split: per source — counts and the sums of `used`, in order, Docker last without a size, another pool\'s not counted',
  sp.lines.map((l) => [l.key, l.n, l.bytes]),
  [['backup', 2, 410], ['plans', 2, 120], ['manual', 1, 9], ['partner', 1, 150], ['other', 1, 0], ['docker', 1, null]]);
same('split: in words — «All» with what they hold, the first size «freed by deleting», the others «≥», a zero sum and Docker counts only',
  S.splitLines(sp).map((l) => l.text), [
    'split.all_used {"n":"7","size":"692B"}',
    'split.line_free {"source":"split.backup","n":"2","size":"410B"}',
    'split.line_size {"source":"split.plans","n":"2","size":"120B"}',
    'split.line_size {"source":"split.manual","n":"1","size":"9B"}',
    'split.line_size {"source":"split.partner","n":"1","size":"150B"}',
    'split.line {"source":"split.other","n":"1"}',
    'split.line {"source":"split.docker","n":"1"}']);
same('split: the tip why «at least» on every line with a size, none on the others', S.splitLines(sp).map((l) => !!l.tip),
  [false, true, true, true, true, false, false]);
const only = S.poolSplit(list, { name: 'cache', snapused: 5000, fs: 'zfs' });
same('split: sources without a snapshot are left out', only.lines.map((l) => l.key), ['backup']);
same('split: a pool without snapshots — «All: 0», no lines', [S.poolSplit(list, { name: 'disk2', snapused: 0, fs: 'zfs' }).lines, S.splitLines(S.poolSplit([], { name: 'x', snapused: 0, fs: 'zfs' })).map((l) => l.text)],
  [[], ['split.all_used {"n":"0","size":"0B"}']]);

// btrfs: no size per snapshot — counts only (the whole list: the array's card)
const bt = [{ id: 'b1', fs: 'btrfs', pool: 'disk1', name: '20261006-1945', used: null }, { id: 'b2', fs: 'btrfs', pool: 'disk2', name: '20261007-1945', used: null },
  { id: 'b3', fs: 'btrfs', pool: 'disk1', name: 'manual-20261001-0900', used: null }];
const bs = S.poolSplit(bt, { fs: 'btrfs' });
same('split btrfs: counts only — not sized, no «All» size, no bytes', [bs.sized, bs.used, bs.lines.map((l) => [l.key, l.n, l.bytes])],
  [false, null, [['backup', 2, null], ['manual', 1, null]]]);
same('split btrfs: in words — no size, no «≥», no tip', S.splitLines(bs).map((l) => [l.text, l.tip]),
  [['split.all {"n":"3"}', ''], ['split.line {"source":"split.backup","n":"2"}', ''], ['split.line {"source":"split.manual","n":"1"}', '']]);
same('split: no «≥» anywhere without a size', S.splitLines(bs).some((l) => l.text.includes('line_free') || l.text.includes('line_size')), false);

console.log(JSON.stringify(out));
