/*
 * Mr. Backupsy's overview tiles and the table they filter (public/desks/backup/desk.js, under node; the tiles rebuild,
 * 2026-10-11): overviewTiles() — which tiles, what each says, which one filters what, the schedule a dialog opener, the
 * last backup carrying how long it took and the containers' part, the tour shown only when newer than the backup, one
 * short line per partner —, tileNode() (button with aria-pressed / dialog opener / div), protRows() + protView() — the
 * rows each tile leaves, the bar's words and «only what isn't protected», a group with no fit keeping its head —, and
 * historyRows() (the backups' words). The matcher is core.js's own (Office.fold / filterWords / fits, read from it).
 *
 *   node tests/backuptiles.js public/desks/backup/desk.js public/assets/core.js   → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testBackupTiles runs it on the server; on the Mac it runs as it is.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const deskFile = process.argv[2] || path.join(__dirname, '../public/desks/backup/desk.js');
const coreFile = process.argv[3] || path.join(__dirname, '../public/assets/core.js');
globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.Office = {
  scope: () => T, t: T, has: () => false, store: () => null, desk: () => {}, places: () => {}, placesFrom: () => {}, placesTook: () => {},
  selbar: () => {},
  el: (tag, cls, text) => ({ tag, cls, text, dataset: {}, appendChild() {}, append() {} }),
  place: (anchor, node) => { node.place = anchor; return node; },
  stat: (o) => ({ o, dataset: {} }),
  fmt: { date: (t) => `date(${t})`, relative: (t) => `rel(${t})`, size: (b) => `${b} B`, number: (n) => String(n), cron: (c) => `cron(${c})`,
    duration: (s) => `${Math.round(s / 60)} min`, time: (t) => `time(${t})` },
};
// core.js's matcher as it is (the block between «const fold» and the next helper)
const core = fs.readFileSync(coreFile, 'utf8');
const a = core.indexOf('const fold = '), z = core.indexOf('/** Text or a node');
new Function('Office', core.slice(a, z))(globalThis.Office);
(0, eval)(fs.readFileSync(deskFile, 'utf8'));
const B = OFFICE_DESK_TESTS.backup;

const out = { pass: 0, fail: [] };
const same = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) out.pass++; else out.fail.push([name, got, want]);
};

// ---------------------------------------------------------------- a server: Kopia on, the flash in a snapshot
const NOW = 1791600000;
const share = (name, mode, more) => ({ name, mode, method: 'auto', retention: null, ignores: [], excluded: [], locations: ['maple'], fs: ['zfs'], last: null, ...more });
const vm = (name, more) => ({ name, running: true, configured: true, mode: 'snapshot', prepare: 'freeze', retention: null, share: 'domains', share_mode: 'snapshot',
  snap: 'yes', own: [`maple/domains/${name}`], disks: [], last: null, ...more });
const app = (name, files, more) => ({ name, folder: name, type: 'template', result: 'ok', stale: false, run: 'r1', time: NOW - 3600, bytes: 100,
  files: files.map((what) => ({ what, path: `${name}/${what}`, bytes: 10, run: 'r1', time: NOW - 3600 })), ...more });
const run = (started, more) => ({ run: 'r' + started, started, finished: started + 14, result: 'ok', message: '', errors: 0, warnings: 0, downtime: 65,
  packages: null, kopia: [{ name: 'appdata', ok: true, seconds: 5 }], kopia_skipped: [], kopia_asleep: [], asleep: null, log: 'run.log', ...more });
const base = () => ({
  found: true, compatible: true, running: false,
  settings: { kopia_enabled: true, dumps: [{ container: 'immich_postgres', type: 'postgres' }], nextcloud: [], flash: 'snapshot', dumps_share: 'media',
    zfs_retention: '7 4 6', kopia_keep: {} },
  shares: [share('appdata', 'kopia'), share('Écoles', 'snapshot', { locations: ['hazel'] }), share('media', 'snapshot'), share('system', 'off'),
    share('flash', 'kopia', { method: 'flash', locations: ['boot'], flash: true, last: { ok: true, time: NOW - 7200 } })],
  vms: [vm('win'), vm('lab', { configured: false }), vm('old', { mode: 'off' })],
  items: [{ kind: 'vm', name: 'win' }, { kind: 'app', name: 'emby' }],
  packages: { found: true, time: NOW - 3600, base: '/mnt/user/media/unraid-backup', apps: [app('immich', ['dump', 'template']), app('nextcloud', ['template'], { result: 'errors' }),
    app('emby', ['sqlite', 'template'])], vms: [], old_runs: 0 },
  containers: { up: true, total: 10, running: 8, kept: 6, stopped: 2, kopia: { name: 'kopia', exists: true, running: true } },
  partners: [{ id: 'p1', name: 'Vault', units: ['place'], current: null,
    last: { sent: 2, bytes: 2048, mbit: 80, time: NOW - 600, skipped: [{ unit: 'share:x', why: 'not_agreed' }], failed: [] } }],
  schedule: { script: true, frequency: 'custom', custom: '30 4 * * *', enabled: true, next: NOW + 18000 },
  status: { mode: 'backup', finished: NOW - 3000, result: 'ok' },
  history: [run(NOW - 3600, { result: 'warnings', warnings: 1 }), run(NOW - 90000)],
  skips: [{ time: NOW - 50000, reason: 'skipped_busy_setup' }],
  dumps: [], drift: null,
});

// ---------------------------------------------------------------- overviewTiles()
B.setState(base());
let tiles = B.overviewTiles();
const byKey = (k) => tiles.find((t) => t.key === k) || null;
same('tiles: which, in order (no «Took», no «Containers» of their own; the tour only when newer)',
  tiles.map((t) => t.key), ['last', 'schedule', 'kopia', 'dumps', 'apps', 'vms', 'partners', 'flash']);
same('tiles: which filters what', tiles.map((t) => t.filters), [null, null, 'kopia', 'dumps', 'apps', 'vms', null, 'flash']);
same('tiles: only the schedule opens a dialog', tiles.filter((t) => t.dialog).map((t) => t.key), ['schedule']);
const last = byKey('last');
same('last backup: the result, its date and how long it took in the first sub line',
  [last.value, last.sub[0]], ['result.warnings', `date(${NOW - 3600}) · 14 s`]);
same('last backup: the containers\' part in the second (downtime, stopped, kept running)', last.sub[1],
  'stat.downtime {"duration":"1 min"} · stat.ct_stopped {"n":2} · stat.ct_kept {"n":6}');
same('schedule: the time, the next backup and «set here»', [byKey('schedule').value, byKey('schedule').sub],
  ['cron(30 4 * * *)', [`stat.next {"when":"rel(${NOW + 18000})"} · stat.by_office`]]);
same('partners: one short line per partner (its place for the search), the whole text in the tip',
  [byKey('partners').sub, byKey('partners').tip.startsWith('partner.tile_sent'), byKey('partners').alert],
  [[{ text: `partner.short_sent {"name":"Vault","when":"rel(${NOW - 600})"} · partner.short_skipped {"n":1}`, place: 'partner:p1' }], true, true]);
same('flash: snapshot + offsite, its last copy', [byKey('flash').value, byKey('flash').sub], ['stat.flash_offsite', [`stat.flash_last {"when":"rel(${NOW - 7200})"}`]]);

// the tour: shown only when it finished after the last backup
let s = base();
s.status = { mode: 'check', finished: NOW - 60, result: 'ok' };
B.setState(s);
same('tour: newer than the last backup - shown after the schedule', B.overviewTiles().map((t) => t.key).slice(0, 3), ['last', 'schedule', 'tour']);
s.status = { mode: 'dryrun', finished: NOW - 7200, result: 'ok' };
B.setState(s);
same('tour: older than the last backup - not shown', B.overviewTiles().some((t) => t.key === 'tour'), false);
s.history = [];
B.setState(s);
same('tour: no backup yet - shown', B.overviewTiles().map((t) => t.key).slice(0, 3), ['last', 'schedule', 'tour']);
// the schedule off; Docker off; no settings yet
s = base();
s.schedule = { script: true, frequency: 'disabled', custom: null, enabled: false, next: null };
s.containers = { up: false };
B.setState(s);
tiles = B.overviewTiles();
same('schedule off: «not scheduled», alert, «click to set it», still a dialog', [byKey('schedule').value, byKey('schedule').alert, byKey('schedule').sub, byKey('schedule').dialog],
  ['stat.not_scheduled', true, ['stat.schedule_hint'], true]);
same('Docker off: said in the last backup\'s sub line', byKey('last').sub[1], 'common.docker_off');
s.schedule = { script: false, frequency: 'disabled', custom: null, enabled: false, next: null };
B.setState(s);
same('no settings: the schedule opens nothing', B.overviewTiles().find((t) => t.key === 'schedule').dialog, false);

// ---------------------------------------------------------------- tileNode(): button, dialog opener, div
B.setState(base());
const counts = B.protTileCounts();
same('counts: rows each tile shows (offsite: appdata, the flash, a VM with its own source, an app with its own; dumps; apps; VMs; flash)',
  counts, { kopia: 4, flash: 1, vms: 3, apps: 3, dumps: 1 });
tiles = B.overviewTiles();
B.setProtTile('');
const node = (k) => B.tileNode(tiles.find((t) => t.key === k), {}, counts).o;
const sched = node('schedule');
same('schedule tile: a button without aria-pressed (opens its dialog), no data-filters',
  [typeof sched.on, 'pressed' in sched, sched.filters || null], ['function', false, null]);
const vmsTile = node('vms');
same('VMs tile: a button, aria-pressed false, controls and filters the table\'s section, a place of its own',
  [typeof vmsTile.on, vmsTile.pressed, vmsTile.controls, vmsTile.filters, vmsTile.place], ['function', false, B.PROT_ID, B.PROT_ID, 'tile.vms']);
B.setProtTile('vms');
same('VMs tile on: aria-pressed true', B.tileNode(tiles.find((t) => t.key === 'vms'), {}, counts).o.pressed, true);
B.setProtTile('');
same('last backup and partners: no click (div)', [node('last').on, node('partners').on], [undefined, undefined]);
same('a filtering tile with no row to show: a div (marked as its place still)',
  [B.tileNode(tiles.find((t) => t.key === 'dumps'), {}, { ...counts, dumps: 0 }).o.on, B.tileNode(tiles.find((t) => t.key === 'dumps'), {}, { ...counts, dumps: 0 }).o.place],
  [undefined, 'tile.dumps']);

// ---------------------------------------------------------------- protView(): what each tile leaves, words, «only …»
const rows = B.protRows();
const W = (text) => Office.filterWords(text);
const view = (f) => {
  const v = B.protView(rows, { words: [], only: false, tile: '', ...f });
  const g = {};
  Object.keys(v.groups).forEach((k) => { g[k] = v.groups[k] === null ? null : v.groups[k].map((r) => r.name); });
  return { g, n: v.n, total: v.total };
};
same('rows: shares (offsite, local, off; the flash among them), VMs, apps', rows.map((r) => `${r.kind}:${r.name}`),
  ['share:appdata', 'share:flash', 'share:Écoles', 'share:media', 'share:system', 'vm:win', 'vm:lab', 'vm:old', 'app:immich', 'app:nextcloud', 'app:emby']);
same('no filter: everything', view({}), { g: { share: ['appdata', 'flash', 'Écoles', 'media', 'system'], vm: ['win', 'lab', 'old'], app: ['immich', 'nextcloud', 'emby'] }, n: 11, total: 11 });
same('tile offsite: shares with Kopia, VMs and apps that go offsite', view({ tile: 'kopia' }),
  { g: { share: ['appdata', 'flash'], vm: ['win'], app: ['emby'] }, n: 4, total: 11 });
same('tile databases: the apps with a dump; the other groups not drawn', view({ tile: 'dumps' }), { g: { share: null, vm: null, app: ['immich'] }, n: 1, total: 11 });
same('tile packages: the apps group', view({ tile: 'apps' }), { g: { share: null, vm: null, app: ['immich', 'nextcloud', 'emby'] }, n: 3, total: 11 });
same('tile VMs: the VMs group', view({ tile: 'vms' }), { g: { share: null, vm: ['win', 'lab', 'old'], app: null }, n: 3, total: 11 });
same('tile flash: the flash row', view({ tile: 'flash' }), { g: { share: ['flash'], vm: null, app: null }, n: 1, total: 11 });
same('words: a pool (the shares\' locations, the VMs\' datasets)', view({ words: W('maple') }).n, 6);
same('words: accents folded, case', view({ words: W('ECOLES') }).g.share, ['Écoles']);
same('words: the protection word as the row shows it (a VM not set up shows its share\'s)', view({ words: W('protect.none') }).g, { share: ['system'], vm: ['old'], app: [] });
same('words: a group drawn but with no fit keeps its head (an empty list: one «nothing fits» line)', view({ words: W('immich') }).g,
  { share: [], vm: [], app: ['immich'] });
same('only what isn\'t protected: a share off, a VM not set up or left out, a package with errors', view({ only: true }).g,
  { share: ['system'], vm: ['lab', 'old'], app: ['nextcloud'] });
same('only + tile VMs', view({ only: true, tile: 'vms' }), { g: { share: null, vm: ['lab', 'old'], app: null }, n: 2, total: 11 });
same('the unprotected rows in all (the switch is offered while there are any)', B.protView(rows, { words: [], only: false, tile: '' }).off, 4);
// Kopia off: nothing goes offsite
s = base();
s.settings.kopia_enabled = false;
B.setState(s);
same('Kopia off: the offsite tile counts nothing, the flash still has its row', [B.protTileCounts().kopia || 0, B.protTileCounts().flash],
  [0, 1]);
same('Kopia off: no offsite tile at all', B.overviewTiles().some((t) => t.key === 'kopia'), false);
s.settings.flash = 'tar';
s.shares = s.shares.filter((x) => !x.flash);
B.setState(s);
same('flash as an archive: no row to show - the tile filters nothing', B.protTileCounts().flash || 0, 0);

// ---------------------------------------------------------------- historyRows(): the backups' words
B.setState(base());
const h = B.historyRows();
same('history: runs and skips newest first', h.map((x) => (x.skip ? 'skip' : 'run')), ['run', 'skip', 'run']);
same('history: the words - the date as the row shows it, the result', h.map((x) => x.hay.slice(0, 2)),
  [[`date(${NOW - 3600})`, 'result.warnings'], [`date(${NOW - 50000})`, 'result.skipped'], [`date(${NOW - 90000})`, 'result.ok']]);
same('history: a word fits its rows only', h.filter((x) => Office.fits(W('warnings'), x.hay)).length, 1);

console.log(JSON.stringify(out));
