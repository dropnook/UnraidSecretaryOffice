/*
 * Mr. Backupsy's overview tiles and the table they filter (public/desks/backup/desk.js, under node; tiles v2, 2026-10-11 —
 * a tile is a section): overviewTiles() — which tiles in the row's order, what each says, the section each shows and
 * which filters the table, the last backup carrying how long it took, the containers' part and a newer tour, the
 * schedule a section (no dialog opener), «Protection», «Changed» and «Restore» —, tileList() (what the switcher gets: ids,
 * keys, sections, the filter's words; every tile before a state), tileNode() (Office.stat with the switcher's attributes
 * spread in), protRows() + protView() — the rows each tile leaves (the flash's own row as an archive or not backed up),
 * the bar's words and «only what isn't protected», a group with no fit keeping its head — and historyRows() (the
 * backups' words). The matcher is core.js's own (Office.fold / filterWords / fits, read from it).
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
  // core.js's label: the level's word, «local + partner (+ offsite)» for partners that received it; the note kept to look at
  backupChip: (level, note) => (level ? { tag: 'span', cls: 'chip', dataset: {}, note: note || null,
    text: note && (note.partners || []).length && level !== 'none' ? (level === 'offsite' ? 'protect.partner_offsite' : 'protect.partner') : 'protect.' + level } : null),
  fmt: { date: (t) => `date(${t})`, relative: (t) => `rel(${t})`, size: (b) => `${b} B`, number: (n) => String(n), bitrate: (m) => `${m} Mbit/s`, cron: (c) => `cron(${c})`,
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
// a share at «kopia» has its good copy offsite (the rows go by results: the truth review P2)
const share = (name, mode, more) => ({ name, mode, method: 'auto', retention: null, ignores: [], excluded: [], locations: ['maple'], fs: ['zfs'],
  last: mode === 'kopia' ? { ok: true, good: NOW - 3600, time: NOW - 3600, seconds: 5 } : null, ...more });
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
    share('flash', 'kopia', { method: 'flash', locations: ['boot'], flash: true, last: { ok: true, good: NOW - 7200, time: NOW - 7200 } })],
  vms: [vm('win'), vm('lab', { configured: false }), vm('old', { mode: 'off' })],
  items: [{ kind: 'vm', name: 'win', last: { ok: true, good: NOW - 3600 } }, { kind: 'app', name: 'emby', last: { ok: true, good: NOW - 3600 } }],
  packages: { found: true, time: NOW - 3600, base: '/mnt/user/media/unraid-backup', apps: [app('immich', ['dump', 'template']), app('nextcloud', ['template'], { result: 'errors' }),
    app('emby', ['sqlite', 'template'])], vms: [], old_runs: 0 },
  containers: { up: true, total: 10, running: 8, kept: 6, stopped: 2, kopia: { name: 'kopia', exists: true, running: true } },
  partners: [{ id: 'p1', name: 'Vault', units: ['place'], current: null,
    last: { sent: 2, bytes: 2048, mbit: 80, time: NOW - 600, units: [{ unit: 'place' }], skipped: [{ unit: 'share:x', why: 'not_agreed' }], failed: [] } }],
  schedule: { script: true, frequency: 'custom', custom: '30 4 * * *', enabled: true, next: NOW + 18000 },
  status: { mode: 'backup', finished: NOW - 3000, result: 'ok' },
  history: [run(NOW - 3600, { result: 'warnings', warnings: 1 }), run(NOW - 90000)],
  skips: [{ time: NOW - 50000, reason: 'skipped_busy_setup' }],
  dumps: [], drift: null,
});

const byKeyOf = (list, k) => list.find((t) => t.key === k) || null;
// ---------------------------------------------------------------- overviewTiles(): a tile is a section (tiles v2)
B.setState(base());
let tiles = B.overviewTiles();
const byKey = (k) => tiles.find((t) => t.key === k) || null;
same('tiles: which, in the row\'s order (no «Took», «Containers» or «Last tour» of their own; drift only with something drifted)',
  tiles.map((t) => t.key), ['last', 'schedule', 'kopia', 'dumps', 'apps', 'vms', 'flash', 'prot', 'partners', 'restore']);
same('tiles: the section each shows (the table shared by the filter tiles and «Protection»)', tiles.map((t) => t.section),
  ['history', 'schedule', 'prot', 'prot', 'prot', 'prot', 'prot', 'prot', 'partners', 'restore']);
same('tiles: which filters the table (the others show their section whole)', tiles.map((t) => t.filters),
  [null, null, 'kopia', 'dumps', 'apps', 'vms', 'flash', null, null, null]);
same('tiles: no dialog opener any more (the schedule is a section with «Change…»)', tiles.filter((t) => 'dialog' in t).length, 0);
same('tiles: the row\'s ids are the switcher\'s (TILES), in its order', tiles.map((t) => t.key),
  B.TILES.map(([id]) => id).filter((id) => tiles.some((t) => t.key === id)));
const last = byKey('last');
same('last backup: the result, its date and how long it took in the first sub line',
  [last.value, last.sub[0]], ['result.warnings', `date(${NOW - 3600}) · 14\u00a0s`]);
same('last backup: the containers\' part in the second (downtime, stopped, kept running)', last.sub[1],
  'stat.downtime {"duration":"1 min"} · stat.ct_stopped {"n":2} · stat.ct_kept {"n":6}');
same('last backup: no third line while no tour came after it', last.sub.length, 2);
same('schedule: the time and the next backup (no «set here» — the list shows what is set, 2026-10-12)', [byKey('schedule').value, byKey('schedule').sub],
  ['cron(30 4 * * *)', [`stat.next {"when":"rel(${NOW + 18000})"}`]]);
same('partners: one short line per partner, the whole text in the tip (the section has a row each, the search\'s anchor)',
  [byKey('partners').sub, byKey('partners').tip.startsWith('partner.tile_sent'), byKey('partners').alert],
  [[`partner.short_sent {"name":"Vault","when":"rel(${NOW - 600})"} · partner.short_skipped {"n":1}`], true, true]);
same('flash: snapshot + offsite, its last copy', [byKey('flash').value, byKey('flash').sub], ['stat.flash_offsite', [`stat.flash_last {"when":"rel(${NOW - 7200})"}`]]);
// the backup place (media) goes to Vault: media and its two packages count «local + partner» (2026-10-23), not «only local»
same('protection: the rows not protected as its value, the levels in its line, alert while any',
  [byKey('prot').value, byKey('prot').sub, byKey('prot').alert],
  ['stat.prot_off {"n":4}', ['protection_sum_partner {"kopia":4,"partner":3,"snapshot":2,"off":2}'], true]);
same('restore: without Mr. Restori\'s certificate his name and where the backups lie', [byKey('restore').value, byKey('restore').sub, byKey('restore').alert],
  ['restore.name', ['stat.restore_where'], false]);

// the tour: the last backup's third line, only when it finished after the last backup
let s = base();
s.status = { mode: 'check', finished: NOW - 60, result: 'ok' };
B.setState(s);
same('tour: newer than the last backup - the last backup\'s third line, no tile of its own',
  [B.overviewTiles().find((t) => t.key === 'last').sub[2], B.overviewTiles().some((t) => t.key === 'tour')],
  [`stat.tour_line {"what":"stat.last_check","result":"result.ok","when":"rel(${NOW - 60})"}`, false]);
s.status = { mode: 'check', finished: NOW - 60, result: 'errors' };
B.setState(s);
same('tour: one that went wrong makes the tile alert', B.overviewTiles().find((t) => t.key === 'last').alert, true);
s.status = { mode: 'dryrun', finished: NOW - 7200, result: 'ok' };
B.setState(s);
same('tour: older than the last backup - not said', B.overviewTiles().find((t) => t.key === 'last').sub.length, 2);
s.history = [];
B.setState(s);
same('tour: no backup yet - said under «no backup yet»', B.overviewTiles().find((t) => t.key === 'last').sub,
  ['bubble.no_runs', `stat.tour_line {"what":"stat.last_dryrun","result":"result.ok","when":"rel(${NOW - 7200})"}`]);
// the schedule off; Docker off; no settings yet
s = base();
s.schedule = { script: true, frequency: 'disabled', custom: null, enabled: false, next: null };
s.containers = { up: false };
B.setState(s);
tiles = B.overviewTiles();
same('schedule off: «not scheduled», alert, «click to set it», its section', [byKey('schedule').value, byKey('schedule').alert, byKey('schedule').sub, byKey('schedule').section],
  ['stat.not_scheduled', true, ['stat.schedule_hint'], 'schedule']);
same('Docker off: said in the last backup\'s sub line', byKey('last').sub[1], 'common.docker_off');
// drift and the drill
s = base();
s.drift = { time: NOW - 300, items: [{ level: 'warn', code: '', value: '', text: 'x' }, { level: 'info', code: '', value: '', text: 'y' }] };
B.setState(s);
tiles = B.overviewTiles();
same('drift: a tile «Changed» before «Restore» while something drifted — its count, when checked, alert for a warning',
  [tiles.map((t) => t.key).slice(-2), byKey('drift').value, byKey('drift').sub, byKey('drift').alert, byKey('drift').section],
  [['drift', 'restore'], 'stat.drift_n {"n":2}', [`drift_checked {"when":"rel(${NOW - 300})"}`], true, 'drift']);
s.drift.items = [{ level: 'info', code: '', value: '', text: 'y' }];
B.setState(s);
same('drift: only infos - no alert', B.overviewTiles().find((t) => t.key === 'drift').alert, false);
Office.desks = new Map([['restore', { hired: true }]]);
s.drill = { result: 'failed', ended: NOW - 86400, proven: 5, total: 6, failed: ['immich'] };
B.setState(s);
same('restore: Mr. Restori hired — his drill\'s certificate, a failed one alerts', ['value', 'sub', 'alert'].map((k) => B.overviewTiles().find((t) => t.key === 'restore')[k]),
  ['stat.drill_failed', [`date(${NOW - 86400})`], true]);
delete Office.desks;

// ---------------------------------------------------------------- tileList() and tileNode(): what the switcher gets
B.setState(base());
let list = B.tileList();
same('tileList: the switcher\'s tiles — id, lang key (its place), the filter\'s words in the note line',
  list.map((x) => [x.id, x.key, x.filters || null]),
  [['last', 'stat.last', null], ['schedule', 'stat.schedule', null], ['kopia', 'tile.kopia', 'tile.kopia'], ['dumps', 'tile.dumps', 'tile.dumps'],
    ['apps', 'tile.apps', 'tile.apps'], ['vms', 'tile.vms', 'tile.vms'], ['flash', 'tile.flash', 'tile.flash'], ['prot', 'tile.prot', null],
    ['partners', 'partner.tile', null], ['restore', 'tile.restore', null]]);
same('tileList: the filter tiles and «Protection» share the table (prot), every other tile brings its own section',
  list.map((x) => (typeof x.section === 'string' ? x.section : typeof x.section)),
  ['function', 'function', 'prot', 'prot', 'prot', 'prot', 'prot', 'prot', 'function', 'function']);
B.setState(null);
list = B.tileList();
same('tileList: no state yet - every tile of the row (a route or the search may name any), the filter tiles marked as such',
  [list.map((x) => x.id), list.filter((x) => x.filters).map((x) => x.id)],
  [B.TILES.map(([id]) => id), ['kopia', 'dumps', 'apps', 'vms', 'flash']]);
B.setState(base());
tiles = B.overviewTiles();
const attrs = { on: () => 1, pressed: true, active: true, controls: 'backup-sections', filters: 'bk-protection', mark: 'common.tile_filter', place: 'tile.vms' };
const vmsNode = B.tileNode(tiles.find((t) => t.key === 'vms'), attrs).o;
same('tileNode: Office.stat with the tile\'s words and the switcher\'s attributes spread in (the click, pressed, controls, filters, mark, place)',
  [vmsNode.label, vmsNode.value, typeof vmsNode.on, vmsNode.pressed, vmsNode.controls, vmsNode.filters, vmsNode.mark, vmsNode.place],
  ['stat.vms', '3', 'function', true, 'backup-sections', 'bk-protection', 'common.tile_filter', 'tile.vms']);
same('tileNode: a partner\'s sub line is plain text (the anchor partner:<id> is its row in the section)', B.tileNode(tiles.find((t) => t.key === 'partners'), {}).o.sub.every((x) => typeof x === 'string'), true);

// ---------------------------------------------------------------- the counts: rows each filter tile leaves
B.setState(base());
same('counts: rows each tile shows (offsite: appdata, the flash, a VM with its own source, an app with its own; dumps; apps; VMs; flash)',
  B.protTileCounts(), { kopia: 4, flash: 1, vms: 3, apps: 3, dumps: 1 });

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
same('flash as an archive: a row of its own (as protected as the backup place: media is a snapshot share - local)',
  [B.protTileCounts().flash, B.protRows().filter((r) => r.id === '\u0000flash').map((r) => [r.name, r.level, r.off, r.x.archive])],
  [1, [['flash', 'local', false, true]]]);
same('flash as an archive: its row stands with the local shares (before what isn\'t backed up)', B.protRows().filter((r) => r.kind === 'share').map((r) => r.name),
  ['appdata', 'Écoles', 'media', 'flash', 'system']);
s.settings.flash = 'off';
B.setState(s);
same('flash not backed up: its row says so and counts as unprotected', B.protRows().filter((r) => r.id === '\u0000flash').map((r) => [r.level, r.off]), [['none', true]]);
same('flash not backed up: the protection tile counts it', B.overviewTiles().find((t) => t.key === 'prot').value, 'stat.prot_off {"n":5}');

// ---------------------------------------------------------------- historyRows(): the backups' words
B.setState(base());
const h = B.historyRows();
same('history: runs and skips newest first', h.map((x) => (x.skip ? 'skip' : 'run')), ['run', 'skip', 'run']);
same('history: the words - the date as the row shows it, the result', h.map((x) => x.hay.slice(0, 2)),
  [[`date(${NOW - 3600})`, 'result.warnings'], [`date(${NOW - 50000})`, 'result.skipped'], [`date(${NOW - 90000})`, 'result.ok']]);
same('history: a word fits its rows only', h.filter((x) => Office.fits(W('warnings'), x.hay)).length, 1);

// ---------------------------------------------------------------- progress(): the very first backup's rough estimate (2026-10-15)
{
  const now = Math.floor(Date.now() / 1000);
  const GB = 10 ** 9;
  const run = (o) => ({ found: true, running: true, estimates: { sources: {}, before: null, prepare: null, total: null, partner: {} }, upload: null, rough: null,
    status: { run: 'r1', mode: 'backup', phase: 'kopia', started: now - 7200,
      kopia: { planned: ['appdata', 'media', 'flash'], done: [{ name: 'appdata', ok: true, seconds: 1000 }], current: 'media', current_since: now - 1000 } }, ...o });
  // appdata 100 GB in 1000 s, media 500 GB: 100 GB read in its first 1000 s → 200 GB in 2000 s = 100 MB/s, 400 GB left = 4000 s
  const up = { source: 'media', first: true, since: now - 1000, size: 500 * GB, read: 100 * GB, sent: 0, rate: null, left: null, time: now };
  B.setState(run({ upload: up, rough: { sizes: { appdata: 100 * GB, media: 500 * GB, flash: null }, complete: true } }));
  let p = B.progress();
  same('first estimate: the first backup in the Kopia step — rough, from this run\'s rate (done + going up), the flash as nothing',
    [p.rough, Math.round(p.eta - now), p.percent], [true, 4000, 64]);
  same('first estimate: the run card and the bubble say it is rough', [B.bubbleText().some((x) => x.startsWith('bubble.eta_rough'))], [true]);
  // the one going up measured on its own (windowed rate, left): its own measure; the next source at this run's rate
  B.setState(run({ upload: { ...up, rate: 50 * 10 ** 6, left: 3000 }, status: { ...run().status, kopia: { planned: ['media', 'isos'], done: [], current: 'media', current_since: now - 1000 } },
    rough: { sizes: { media: 500 * GB, isos: 10 * GB }, complete: true } }));
  p = B.progress();
  same('first estimate: the one going up by its own measure, the next at this run\'s rate (100 GB in 1000 s)', [p.rough, Math.round(p.eta - now)], [true, 3100]);
  // a size not known (a place that isn't ZFS): no estimate, as before
  B.setState(run({ upload: up, rough: { sizes: { appdata: 100 * GB, media: 500 * GB, flash: null, isos: null }, complete: true },
    status: { ...run().status, kopia: { ...run().status.kopia, planned: ['appdata', 'media', 'isos'] } } }));
  same('first estimate: a source of unknown size — none', [B.progress().eta, B.progress().rough], [null, false]);
  // no sizes yet (an agent before this, or not looked at yet): none
  B.setState(run({ upload: up }));
  same('first estimate: without the sizes — none, as before', B.progress().eta, null);
  // before the upload: none yet, and the card says a rough one comes
  B.setState(run({ status: { ...run().status, phase: 'snapshots', kopia: { planned: ['appdata'], done: [], current: null } } }));
  same('first estimate: before the upload — none yet, a rough one announced', [B.progress().eta, B.progress().firstRun], [null, true]);
  // a later run: unchanged — earlier runs' durations, never rough
  B.setState(run({ estimates: { sources: { appdata: 60, media: 600, flash: 5 }, prepare: 100, total: 900, partner: {} }, upload: null,
    rough: { sizes: { appdata: 1, media: 1 }, complete: true }, status: { ...run().status, kopia: { ...run().status.kopia, current_since: now - 100 } } }));
  p = B.progress();
  same('first estimate: a later run goes by earlier runs, never rough', [p.rough, Math.round(p.eta - now), p.overdue], [false, 505, false]);
}

// ---------------------------------------------------------------- 2026-10-23: a unit at a partner says so (the owner: a VM that goes to
// Nostromo with domains read «only local» on the overview) - its share's partner, its own, the backup place for the packages
{
  const s = base();
  s.shares.push(share('domains', 'snapshot'), share('docs', 'off'));
  s.vms = [vm('inshare', { own: [] }), vm('ownds'), vm('ownsent', { name: 'ownsent' }), vm('elsewhere', { own: [], share: 'isos' })];
  s.partners = [{ id: 'p1', name: 'Vault', units: ['place', 'share:domains', 'share:docs', 'vm:ownsent'], current: null,
    unit_last: { place: { ok: true }, 'share:domains': { ok: true }, 'share:docs': { ok: true }, 'vm:ownsent': { ok: true } }, last: null },
    { id: 'p2', name: 'Gone', units: ['share:appdata'], ended: true, current: null, last: null }];
  s.packages = { found: true, base: '/mnt/maple/media/backup', apps: [app('web', ['template']), app('db', ['template'], { dirs: ['appdata/db'] })], vms: [] };
  B.setState(s);
  const rows = B.protRows();
  const by = (kind, name) => rows.find((r) => r.kind === kind && r.name === name);
  same('travel: a share at a partner, the backup place\'s share by its place unit, an ended pair never, a share at «off» not protected (no partner word)',
    [by('share', 'domains').partners, by('share', 'media').partners, by('share', 'appdata').partners, B.protChip(by('share', 'docs')) && by('share', 'docs').level],
    [['Vault'], ['Vault'], [], 'none']);
  same('travel: a VM in its share\'s dataset goes with the share; one in a dataset of its own only as its own unit; another share\'s VM not',
    ['inshare', 'ownds', 'ownsent', 'elsewhere'].map((n) => by('vm', n).partners), [['Vault'], [], ['Vault'], []]);
  same('travel: the packages lie in the backup place - every app at its partner', [by('app', 'web').partners, by('app', 'db').partners], [['Vault'], ['Vault']]);
  const c = B.protChip(by('vm', 'inshare'));
  same('travel: the chip says «local + partner» (offsite: «… + offsite»), green, the partner named in its tip; the bar finds the word and the name',
    [c.text, c.note.partners, B.protChip(by('share', 'appdata')).text, by('vm', 'inshare').hay.includes('setup.level.partner') && by('vm', 'inshare').hay.includes('Vault')],
    ['protect.partner', ['Vault'], 'protect.offsite', true]);
  s.shares = s.shares.map((x) => (x.name === 'domains' ? { ...x, mode: 'kopia', last: { ok: true, good: NOW - 3600 } } : x));
  s.vms = s.vms.map((x) => (x.share === 'domains' ? { ...x, share_mode: 'kopia' } : x));
  B.setState(s);
  same('travel: … its share offsite too: «local + partner + offsite»', B.protChip(B.protRows().find((r) => r.name === 'inshare')).text, 'protect.partner_offsite');
  // a VM only local in a share at Kopia (its folder in kopia_ignore): «only local», never «offsite»
  const vs = { ...s, shares: s.shares.map((x) => (x.name === 'domains' ? { ...x, ignores: ['/left/'] } : x)), partners: [],
    vms: [vm('left', { own: [], share_mode: 'kopia', disks: [{ source: '/mnt/user/domains/left/vdisk1.img' }] }), vm('in', { own: [], share_mode: 'kopia', disks: [{ source: '/mnt/user/domains/in/vdisk1.img' }] })] };
  B.setState(vs);
  same('travel: a VM left out of its share\'s Kopia source is only local; one inside it offsite; no partner - the line as before',
    [B.vmLevel(vs.vms[0], true), B.vmLevel(vs.vms[1], true), B.protSum(B.protRows()).split(' ')[0]], ['local', 'offsite', 'protection_sum']);
}

// ---------------------------------------------------------------- INV-TRUTH (the truth review 2026-10, P2): a row says what the
// results show, never more than settings.ini — state files → the label
{
  const rowOf = (rows, kind, name) => rows.find((r) => r.kind === kind && r.name === name);
  // T02: the repository suddenly empty (kopia_held) — no row offsite, the Kopia and flash tiles alert, the bubble never «saved offsite»
  let s = base();
  s.kopia_held = { repo: 'r1', identity: 'root@tower', before: 48, now: 0, when: NOW - 600, time: NOW - 600, gone: false };
  s.status = null;
  s.history = [run(NOW - 3600), run(NOW - 90000)];
  B.setState(s);
  let rows = B.protRows();
  tiles = B.overviewTiles();
  same('truth T02: held — no row offsite; appdata only local with «held» beside it; the Kopia tile alerts and says so first; the flash tile alerts',
    [rows.filter((r) => r.level === 'offsite').length, rowOf(rows, 'share', 'appdata').level, rowOf(rows, 'share', 'appdata').why,
      byKeyOf(tiles, 'kopia').alert, byKeyOf(tiles, 'kopia').sub[0].startsWith('stat.kopia_held'), byKeyOf(tiles, 'flash').alert, byKeyOf(tiles, 'flash').sub],
    [0, 'local', ['kopia_held'], true, true, true, ['stat.kopia_held']]);
  same('truth T02: held — the bubble says the last backup went, never «… saved offsite», and says the copy is empty',
    [B.bubbleText().some((x) => x.startsWith('bubble.last_ok_kopia')), B.bubbleText().some((x) => x.startsWith('bubble.last_ok ')), B.bubbleText().includes('bubble.kopia_held')],
    [false, true, true]);
  // T10: the storage takes nothing — the copies there stay (offsite), each with the reason; the tile alerts
  s = base();
  s.kopia_writes = { state: 'stuck', since: NOW - 7200, by: 'server', storage: 's3', time: NOW };
  s.status = null;
  s.history = [run(NOW - 3600), run(NOW - 90000)];
  B.setState(s);
  rows = B.protRows();
  tiles = B.overviewTiles();
  same('truth T10: stuck — appdata offsite with «takes nothing since» beside it (never green alone); the Kopia tile alerts',
    [rowOf(rows, 'share', 'appdata').level, rowOf(rows, 'share', 'appdata').why, rowOf(rows, 'share', 'appdata').since, byKeyOf(tiles, 'kopia').alert,
      byKeyOf(tiles, 'kopia').sub[0].startsWith('stat.kopia_stuck'), B.bubbleText().some((x) => x.startsWith('bubble.last_ok_kopia'))],
    ['offsite', ['kopia_stuck'], NOW - 7200, true, true, false]);
  // T09: set offsite, Kopia never made a copy — only local, «no copy yet»
  s = base();
  s.shares = s.shares.map((x) => (x.name === 'appdata' ? { ...x, last: null } : x));
  B.setState(s);
  rows = B.protRows();
  same('truth T09: no copy yet — appdata only local, «set: offsite — no copy yet»; still in the Kopia tile\'s filter',
    [rowOf(rows, 'share', 'appdata').level, rowOf(rows, 'share', 'appdata').set, rowOf(rows, 'share', 'appdata').why, rowOf(rows, 'share', 'appdata').tiles.includes('kopia')],
    ['local', 'offsite', ['kopia_never'], true]);
  same('truth T09: … the protection tile is not «all protected» while a row is less than set',
    [B.overviewTiles().find((t) => t.key === 'prot').value.startsWith('stat.prot_ok')], [false]);
  // T05: the partner skipped (or never received) a unit — no «local + partner», the partner named as missed, counted only local
  s = base();
  s.partners = [{ id: 'p1', name: 'Vault', units: ['place', 'share:Écoles'], current: null,
    last: { sent: 1, time: NOW - 600, units: [], skipped: [{ unit: 'place', why: 'asleep' }], failed: [] } }];
  B.setState(s);
  rows = B.protRows();
  same('truth T05: skipped at the partner — media (the backup place) only local, Vault missed «skipped»; Écoles never sent — missed «never»; the sum counts no partner',
    [rowOf(rows, 'share', 'media').partners, rowOf(rows, 'share', 'media').missed, rowOf(rows, 'share', 'Écoles').missed, B.protSum(rows).startsWith('protection_sum ')],
    [[], [{ name: 'Vault', why: 'skipped', code: 'asleep' }], [{ name: 'Vault', why: 'never', code: '' }], true]);
  s.partners[0].unit_last = { place: { ok: false, why: 'failed', code: 'partner_refused' }, 'share:Écoles': { ok: true } };
  B.setState(s);
  rows = B.protRows();
  same('truth T05: the agent\'s unit_last (the whole history) decides over the last run: Écoles received earlier, the place failed',
    [rowOf(rows, 'share', 'Écoles').partners, rowOf(rows, 'share', 'media').missed.map((m) => m.why)], [['Vault'], ['failed']]);
  // T07: a new share (drift share_new) is a row, not backed up; the protection tile never «all protected»
  s = base();
  s.shares = s.shares.filter((x) => x.name !== 'system');
  s.drift = { time: NOW - 60, items: [{ level: 'warn', code: 'share_new', params: { name: 'photos', locations: 'maple', bytes: '1' } }], policies: null };
  B.setState(s);
  rows = B.protRows();
  tiles = B.overviewTiles();
  same('truth T07: a new share is a row at «not backed up» with its reason, counted unprotected',
    [rowOf(rows, 'share', 'photos') && rowOf(rows, 'share', 'photos').level, rowOf(rows, 'share', 'photos') && rowOf(rows, 'share', 'photos').why,
      rowOf(rows, 'share', 'photos') && rowOf(rows, 'share', 'photos').off, byKeyOf(tiles, 'prot').value.startsWith('stat.prot_ok')],
    ['none', ['new_share'], true, false]);
  s.truth = { runs: 2, no_snaps: [], new: [] };
  B.setState(s);
  same('truth T07: the agent\'s truth decides (a share it no longer calls new: no row)', B.protRows().some((r) => r.name === 'photos'), false);
  // T08: a share whose file system takes no snapshot — «local» holds nothing: not backed up, its reason beside it
  s = base();
  s.truth = { runs: 2, no_snaps: ['Écoles'], new: [] };
  B.setState(s);
  rows = B.protRows();
  same('truth T08: no snapshot possible — Écoles at «snapshot» is not backed up («no snapshot possible»), counted unprotected',
    [rowOf(rows, 'share', 'Écoles').level, rowOf(rows, 'share', 'Écoles').set, rowOf(rows, 'share', 'Écoles').why, rowOf(rows, 'share', 'Écoles').off],
    ['none', 'local', ['no_snaps'], true]);
  s.truth = undefined;
  s.drift = { time: NOW - 60, items: [{ level: 'warn', code: 'share_snapshot_no_fs', params: { name: 'Écoles' } }], policies: null };
  B.setState(s);
  same('truth T08: an older agent — the engine\'s drift share_snapshot_no_fs says it', rowOf(B.protRows(), 'share', 'Écoles').level, 'none');
  // no backup run yet: nothing is protected, whatever is set
  s = base();
  s.history = [];
  s.truth = { runs: 0, no_snaps: [], new: [] };
  B.setState(s);
  same('truth: before the first backup no row is protected (each says «no backup yet»)',
    [B.protRows().filter((r) => r.level !== 'none').length, rowOf(B.protRows(), 'share', 'appdata').why], [0, ['no_run']]);
  // T21: the section's line is the tile's line (both protSum(protRows()))
  B.setState(base());
  same('truth T21: the protection tile\'s line is protSum of the rows', B.overviewTiles().find((t) => t.key === 'prot').sub, [B.protSum(B.protRows())]);
}

console.log(JSON.stringify(out));
