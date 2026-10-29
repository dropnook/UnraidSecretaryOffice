/*
 * Ms. Snapshotini's pool tiles: a pool's snapshots by where they come from (public/desks/snapshot/desk.js poolSplit() and
 * splitLines(), under node) — the counts and sums per source (the engine's, a schedule's, by hand, a partner's copies,
 * other tools', Docker's last), sources without a snapshot left out, «All» from the pool's usedbysnapshots, the first
 * sized line «≥ … freed by deleting», the others «≥ …», each with the tip why «at least»; btrfs (and Docker) counts only.
 * And her row on the section switcher (tiles v2, a stand-in for Office.sections()): the tiles from her state in order,
 * the pool tiles filtering her list, «Array» holding every array disk (btrfs and ZFS, by the agent's `array` flag —
 * never the name), a btrfs pool a card of its own, the pool kept as snapshot.pool (a section tile or nothing forgets it), the
 * «Snapshots» card with what the switches hide, her list counting what they hide («0 of 57», never «0 of 0»).
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
const stored = {};
let spec = null;
let active = '';
globalThis.Office = {
  scope: () => T, t: T, el: () => ({}), storeJson: () => null, desk: () => {}, places: () => {}, placesFrom: () => {},
  placesTook: () => {}, agent: { running: true }, lang: 'en', fits: () => true,
  store(k, v) { if (arguments.length > 1) stored[k] = v; return k in stored ? stored[k] : null; },
  tile: (o) => ({ o }),
  scheduleTile: (d, o) => ({ id: 'schedule', key: o.key, place: 'tile.schedule', section: o.section, tile: (a) => ({ o: { ...o, ...a } }) }),
  sections: (o) => { spec = o; return { el: {}, grid: { contains: () => false }, active: () => active, places: () => [], mount: () => false, render() {}, clear() { active = ''; },
    pick(id) { active = id; }, note: () => ({ tile: '' }) }; },
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
const mixed = [...bt, { id: 'z1', fs: 'zfs', pool: 'disk3', name: 'uso-backup-20261009-0430', used: 50 }, { id: 'z2', fs: 'zfs', pool: 'disk9', name: 'manual-x', used: 5 }];
same('split «Array»: its disks\' snapshots only (btrfs and ZFS, by name), counts only when mixed',
  [S.poolSplit(mixed, { fs: 'btrfs', names: ['disk1', 'disk3'] }).lines.map((l) => [l.key, l.n, l.bytes]), S.poolSplit(mixed, { fs: 'btrfs', names: ['disk1', 'disk3'] }).n],
  [[['backup', 2, null], ['manual', 1, null]], 3]);
same('split «Array» of ZFS disks only: sized, «All» from their usedbysnapshots together', [S.poolSplit(mixed, { fs: 'zfs', names: ['disk3', 'disk9'], snapused: 70 }).used,
  S.poolSplit(mixed, { fs: 'zfs', names: ['disk3', 'disk9'], snapused: 70 }).lines.map((l) => [l.key, l.n, l.bytes])], [70, [['backup', 1, 50], ['manual', 1, 5]]]);
same('split: no «≥» anywhere without a size', S.splitLines(bs).some((l) => l.text.includes('line_free') || l.text.includes('line_size')), false);

// ------------------------------------------------------------------ her row on the section switcher (tiles v2)
const view = { partnerShow: { box: { checked: false } }, backupShow: { box: { checked: false } }, dockerBox: { checked: false }, bar: { read: () => ({ words: [], pick: '', only: false }) } };
const zsnap = (pool, name, extra) => ({ id: `zfs:${pool}/ds@${name}`, fs: 'zfs', pool, ds: `${pool}/ds`, vol: `${pool}/ds`, name, used: 1, t: 1, ...extra });
const st = {
  backup: { prefixes: ['uso-backup-'] }, plans: { plans: [] },
  zfs: { pools: [{ name: 'daten', snapused: 600 }, { name: 'cache', snapused: 50 }], snapshots: [
    zsnap('daten', 'uso-backup-20261009-0430'), zsnap('daten', 'uso-backup-20261008-0430'), zsnap('daten', 'manual-20261001-0900'),
    zsnap('daten', 'uso-backup-20261007-0430', { partner: { id: 'a1', name: 'vault' } }), zsnap('cache', 'abc', { docker: true })] },
  btrfs: { devices: [{ name: 'disk1', mount: '/mnt/disk1' }], snapshots: [{ id: 'btrfs:/mnt/disk1/.s/x', fs: 'btrfs', pool: 'disk1', ds: '/mnt/disk1', vol: 'disk1', name: 'x', used: 7, t: 1 }] },
  vm: { available: true, domains: ['HA'], snapshots: [] },
};
S.setState(st);
S.setView(view);               // after her state: a desk with a page would draw itself
const tiles = () => spec.tiles();
same('row: her tiles from her state — a card per ZFS pool, «Array» and a row per disk, the VMs, «Schedules», «Snapshots» last; no start tile',
  [spec.default, tiles().map((x) => [x.id, typeof x.section === 'string' ? x.section : 'own', x.filters || '', x.place || ''])],
  ['', [['pool:daten', 'list', 'daten', 'pool:daten'], ['pool:cache', 'list', 'cache', 'pool:cache'], ['card:array', 'list', '', ''], ['pool:disk1', 'list', 'disk1', 'pool:disk1'],
    ['pool:VMs', 'list', 'VMs', 'pool:VMs'], ['schedule', 'own', '', 'tile.schedule'], ['snapshots', 'list', '', 'tile.snapshots']]]);
spec.onPick('pool:daten', 'click');
const a1 = stored['snapshot.pool'];
spec.onPick('schedule', 'click');
const a2 = stored['snapshot.pool'];
spec.onPick('pool:disk1', 'route');
spec.onPick('', 'store');
const a3 = stored['snapshot.pool'];
spec.onPick('', 'clear');
same('row: a pool tile keeps its pool (snapshot.pool), a section tile and the note line\'s chip forget it, a new visit\'s store pick leaves it', [a1, a2, a3, stored['snapshot.pool']],
  ['daten', null, 'disk1', null]);
active = 'pool:daten';
same('list: the pool tile filters her list; the switches hide Mr. Backupsy\'s and the partner\'s — counted as hidden, the bar counts them in «of m» (Docker\'s not)',
  [S.poolFilter(), S.filtered().map((x) => x.name), S.hiddenCounts(S.filtered()), S.hiddenCounts(st.zfs.snapshots), S.listed().length],
  ['daten', ['manual-20261001-0900'], { backup: 0, partner: 0 }, { backup: 2, partner: 1 }, 5]);
view.backupShow.box.checked = true;
same('list: Mr. Backupsy\'s switched on — his snapshots in the list, no longer hidden', [S.filtered().length, S.hiddenCounts(st.zfs.snapshots)], [3, { backup: 0, partner: 1 }]);
view.backupShow.box.checked = false;
active = '';
const card = S.snapsCard({ on: () => {}, pressed: false });
// the array's ZFS disk (disks.ini type="Data": the agent's `array`), a pool someone called «disk9», a btrfs pool outside the array
const st2 = JSON.parse(JSON.stringify(st));
st2.zfs.pools.push({ name: 'disk2', snapused: 3, count: 1, array: true }, { name: 'disk9', snapused: 0, count: 0, array: false });
st2.zfs.snapshots.push(zsnap('disk2', 'manual-20261002-0900'));
st2.btrfs.devices.push({ name: 'fast', mount: '/mnt/fast', array: false }, { name: 'disk10', mount: '/mnt/disk10', array: true });
S.setView(null);              // her state anew without drawing (no page here)
S.setState(st2);
S.setView(view);
same('row: «Array» holds every array disk — btrfs and the ZFS disk2, in Unraid\'s order (disk10 after disk2) —; disk9 (no array disk) and the btrfs pool «fast» cards of their own',
  tiles().map((x) => x.id), ['pool:daten', 'pool:cache', 'pool:disk9', 'card:array', 'pool:disk1', 'pool:disk2', 'pool:disk10', 'card:btrfs:fast', 'pool:fast', 'pool:VMs', 'schedule', 'snapshots']);
same('row: the cards\' disks — kinds and titles', S.diskCards().map((g) => [g.id, g.title, g.disks.map((x) => x.kind + ':' + x.name)]),
  [['card:array', 'array', ['btrfs:disk1', 'zfs:disk2', 'btrfs:disk10']], ['card:btrfs:fast', 'fast', ['btrfs:fast']]]);
same('row: the ZFS disk\'s row filters her list by its pool, as its card did', tiles().find((x) => x.id === 'pool:disk2').filters, 'disk2');
active = 'pool:disk2';
view.backupShow.box.checked = true;
same('list: the ZFS array disk\'s tile — only its snapshots', [S.poolFilter(), S.filtered().map((x) => x.id)], ['disk2', ['zfs:disk2/ds@manual-20261002-0900']]);
view.backupShow.box.checked = false;
active = '';
S.setView(null);
S.setState(st);
S.setView(view);
same('«Snapshots» card: how many (Docker\'s layers not) and what they take, what the switches hide', [card.o.name, card.o.lines],
  ['snapshots', ['count {"n":5} · 657B', 'snaps_tile.hidden {"what":"count.hidden_backup {\\"n\\":2}, count.hidden_partner {\\"n\\":1}"}']]);
console.log(JSON.stringify(out));
