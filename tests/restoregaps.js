// Mr. Restori's page, the restore matrix's gaps 6–12 (task/restore-gaps), under node: desk.js loaded with a stand-in
// Office (as testTruthRestPages), its helpers run on small states; one JSON line with what each case gave — checked by
// testRestoreGapsPage in tests/run.php.
//
//   node tests/restoregaps.js public/desks/restore/desk.js
const fs = require('fs');
globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
const mk = (tag, cls, text) => ({ tag, cls, text, textContent: text == null ? '' : String(text), children: [], dataset: {}, style: {}, title: '',
  append(...c) { this.children.push(...c); }, appendChild(c) { this.children.push(c); return c; }, setAttribute() {}, addEventListener() {} });
const fmt = { size: (b) => b + ' B', number: (n) => String(n), date: (t) => 'D' + t, relative: (t) => 'R' + t, time: (t) => 'T' + t, duration: (s) => s + 's', cron: (c) => c };
globalThis.document = { createTextNode: (t) => t };
globalThis.CSS = { escape: (s) => s };
globalThis.Office = { scope: () => T, t: T, el: mk, fmt, store: () => null, storeJson: () => null, desk: () => {}, places: () => {}, placesFrom: () => {}, placesTook: () => {},
  has: () => true, errorText: (e) => 'error ' + e.key, api: { get: async () => ({ ok: true, part: null }) }, agent: { running: true }, lang: 'en', fits: () => true,
  desks: new Map(), backupChip: () => null, safeHref: (h) => h,
  place: (k, n) => n, tile: (o) => o, sections: () => ({ el: {}, body: { querySelector: () => null }, active: () => '', places: () => [], mount: () => false, render() {}, clear() {}, pick() {}, note: () => ({ tile: '' }) }) };
(0, eval)(fs.readFileSync(process.argv[2], 'utf8'));
const r = OFFICE_DESK_TESTS.restore;
const out = {};
/** every text of an element and its children, in order */
const texts = (e) => (e == null ? [] : typeof e === 'string' ? [e] : [e.textContent, ...(e.children || []).flatMap(texts)].filter((x) => x !== ''));

// ---- #10: a new server with a ticket — the empty Apps and VMs say where their packages come from
const base = { time: 1, place: { base: null, found: false, share: '' }, engine: { found: true }, kopia: {}, apps: [], vms: [], shares_back: [], restores: [], kept: [] };
const ticket = { id: 'a1b2c3d4', name: 'OldNAS', kind: 'ticket', of: 'Tower', expires: 99, looked: 5, units: [{ unit: 'place', snaps: [{ name: 's', creation: 5 }], newest: 5 }] };
r.setState({ ...base, partners: [ticket] });
out.t10 = { apps: r.noPackagesText('apps'), vms: r.noPackagesText('vms'), dbs: r.noPackagesText('dbs'), link: texts(r.noPackages('apps')), bubble: r.bubbleText(), tile: r.tileLine('partner:a1b2c3d4')[0] };
r.setState({ ...base, partners: [] });
out.t10.plain = [r.noPackagesText('apps'), r.noPackagesText('vms'), r.bubbleText()];
r.setState({ ...base, place: { base: '/mnt/user/UnraidSecretaryOffice/backup', found: true }, partners: [ticket] });
out.t10.found = [r.noPackagesText('apps'), r.noPackagesText('vms')];

// ---- #12: the boot pool's own snapshots named on «The flash» — read-only, single pieces by hand, never rolled back
const boot = { dataset: 'flash/boot', count: 3, newest: { name: 'uso-backup-20261025-0300', time: 9 }, oldest: 1, path: '/boot/.zfs/snapshot', asleep: false };
out.t12 = {
  on: texts(r.bootSnapsPart({ mode: 'tar', boot })),
  asleep: texts(r.bootSnapsPart({ mode: 'snapshot', boot: { ...boot, count: null, newest: null, asleep: true } })),
  these: texts(r.bootSnapsPart({ mode: 'snapshot', boot }))[0],
  none: r.bootSnapsPart({ mode: 'tar', boot: { ...boot, count: 0, newest: null } }),
  stick: r.bootSnapsPart({ mode: 'tar', boot: null }),
  old: r.bootSnapsPart({ mode: 'tar' }),
};

// ---- #9 (and #8's second step): what a Kopia restore or a partner pull brought back goes to its place from its journal
const st9 = { ...base, place: { base: '/b', found: true }, partners: [], kopia: { enabled: true, running: true, restore: { dest: '/restore', source: '/mnt/user/k/restore' }, root: '/data' },
  apps: [{ id: 'Nextcloud', name: 'Nextcloud', folders: [{ path: '/mnt/user/appdata/nextcloud', share: 'appdata' }], shares: [{ path: '/mnt/user/nextcloud', share: 'nextcloud', whole: true }], kopia: [{ source: '.apps/Nextcloud', own: true, covers: 'all' }] }],
  vms: [{ id: 'Win', name: 'Win', folders: [{ path: '/mnt/user/domains/Win', share: 'domains' }], kopia: [{ source: '.vms/Win', own: true, covers: 'all' }] }],
  shares_back: [{ name: 'appdata', path: '/mnt/user/appdata' }, { name: 'nextcloud', path: '/mnt/user/nextcloud' }, { name: 'domains', path: '/mnt/user/domains' }, { name: 'photos', path: '/mnt/user/photos' }] };
r.setState(st9);
const jr = (kind, target, o = {}) => ({ id: 'J1', kind, result: 'ok', target, putback: null, ...o });
const pl = (x) => r.placesBack(x).map((u) => [u.path, u.snap]);
out.t9 = {
  share: pl(jr('kopia', { source: 'appdata', snapshot: 'ab' })),
  own: pl(jr('kopia', { source: '.apps/Nextcloud', snapshot: 'ab' })),
  vm: pl(jr('kopia', { source: '.vms/Win', snapshot: 'ab' })),
  path: pl(jr('kopia', { source: 'appdata', snapshot: 'ab', path: 'nextcloud', mode: 'stage', live: '/mnt/user/appdata/nextcloud' })),
  pathCopy: pl(jr('kopia', { source: 'appdata', snapshot: 'ab', path: 'nextcloud', mode: 'copy', live: '/mnt/user/appdata/nextcloud' })),
  partner: pl(jr('partner', { pair: 'p1', unit: 'share:appdata', path: '/mnt/user/appdata', dataset: 'cache/appdata.restored-20261025-120000' })),
  partnerPlace: pl(jr('partner', { pair: 'p1', unit: 'place', path: null })),
  putBack: pl(jr('partner', { pair: 'p1', unit: 'share:appdata', path: '/mnt/user/appdata' }, { putback: { id: 'x', result: 'ok' } })),
  failed: pl(jr('kopia', { source: 'appdata', snapshot: 'ab' }, { result: 'failed' })),
  labels: r.placesBack(jr('kopia', { source: 'appdata', snapshot: 'ab' })).map((u) => u.label),
};
// the app row's «From offsite»: the source's own «Restore…», the guide named as the way by hand
const kb = (k) => { r.setState({ ...st9, kopia: { ...st9.kopia, ...k } }); return texts(r.kopiaBlock([{ source: 'appdata', covers: 'data', last: 5 }])); };
out.t9.block = kb({});
out.t9.blockNoMap = kb({ restore: null });

// ---- #8: «From a partner» in an app's and a VM's row — the unit that holds it pulled whole (said first), then its folders
const snapsOf = (n) => [{ name: 'uso-backup-20261024-0300', creation: 20, referenced: 244850688 }, { name: 'uso-backup-20261023-0300', creation: 10, referenced: 1 }].slice(0, n);
const pnas = { id: 'p1', name: 'PartnerNAS', kind: 'pair', units: [{ unit: 'place', snaps: snapsOf(2) }, { unit: 'share:appdata', snaps: snapsOf(2) }, { unit: 'vm:Win', snaps: snapsOf(1) }] };
const nc = { ...st9.apps[0], at_partner: [{ id: 'p1', name: 'PartnerNAS', unit: 'share:appdata', newest: 20, n: 2 }] };
const win = { ...st9.vms[0], at_partner: [{ id: 'p1', name: 'PartnerNAS', unit: 'vm:Win', newest: 20, n: 1 }] };
const st8 = { ...st9, partners: [pnas], apps: [nc], vms: [win] };
r.setState(st8);
out.t8 = { fresh: texts(r.partnerPart(nc, 'app')), lead: texts(r.fetchLead(nc, nc.at_partner[0])), leadOwn: texts(r.fetchLead(win, win.at_partner[0])),
  chip: r.atPartnerChip(nc.at_partner[0], nc).title, packageOnly: texts(r.partnerPart({ ...nc, at_partner: [] }, 'app')) };
r.setState({ ...st8, partners: [] });
out.t8.none = r.partnerPart({ ...nc, at_partner: [] }, 'app');
r.setState(st8);
const pulled = jr('partner', { pair: 'p1', unit: 'share:appdata', path: '/mnt/user/appdata', dataset: 'cache/appdata.restored-20261025-120000', snap: 'uso-backup-20261024-0300' }, { finished: 30 });
const placePulled = jr('partner', { pair: 'p1', unit: 'place', path: null, snap: 'uso-backup-20261024-0300' }, { id: 'J2', finished: 31 });
r.setState({ ...st8, restores: [placePulled, pulled] });
out.t8.pulled = texts(r.partnerPart(nc, 'app'));
out.t8.owner = r.ownerPlaces(pulled, 'app', 'Nextcloud').map((u) => [u.path, u.snap]);
r.setState({ ...st8, restores: [{ ...pulled, putback: { id: 'x', result: 'ok' } }] });
out.t8.dropped = texts(r.partnerPart(nc, 'app')).filter((t) => t.startsWith('pa.'));
r.setState({ ...st8, restores: [{ ...pulled, swapped_by: 'J9' }] });
out.t8.swapped = texts(r.partnerPart(nc, 'app')).filter((t) => t.startsWith('pa.'));

// ---- #6: a pull of a whole share — «Swap it in…» from its entry; swapped: the way to the swap's entry
r.setState(st8);
const sw = (t, o = {}) => r.swapPart(jr('partner', { pair: 'p1', unit: 'share:appdata', path: '/mnt/user/appdata', partner: 'PartnerNAS', ...t }, o));
out.t6 = {
  beside: texts(sw({ dataset: 'cache/appdata.restored-20261025-120000' })),
  ticket: texts(sw({ dataset: 'tank/appdata.restored-20261025-120000', how: 'share_exists' })),
  parent: sw({ dataset: 'tank/UnraidSecretaryOffice-restored/share-appdata' }),
  vm: r.swapPart(jr('partner', { pair: 'p1', unit: 'vm:Win', dataset: 'cache/domains/Win.restored-20261025-120000' })),
  swapped: texts(sw({ dataset: 'cache/appdata.restored-20261025-120000' }, { swapped_by: 'J9' })),
  places: r.placesBack(jr('partner', { pair: 'p1', unit: 'share:appdata', path: '/mnt/user/appdata' }, { swapped_by: 'J9' })),
  putBack: sw({ dataset: 'cache/appdata.restored-20261025-120000' }, { putback: { id: 'x', result: 'ok' } }),
};

// ---- #7: all of libvirt.img from the page — what it replaces said first, only with the VM service off
const buttons = (e) => (e == null || typeof e === 'string' ? [] : [...(e.tag === 'button' ? [[e.textContent, !!e.disabled]] : []), ...(e.children || []).flatMap(buttons)]);
const lvState = (on) => ({ ...st9, vm_service: on, libvirt_img: '/mnt/user/system/libvirt/libvirt.img', server: { libvirt: '/b/server/libvirt.tar.gz', libvirt_time: 40, libvirt_bytes: 9 } });
r.setState(lvState(false));
const offFold = r.vmAllFold('/b/server/libvirt.tar.gz');
out.t7 = { off: texts(offFold), offButtons: buttons(offFold) };
r.setState(lvState(true));
const onFold = r.vmAllFold('/b/server/libvirt.tar.gz');
out.t7.on = texts(onFold);
out.t7.onButtons = buttons(onFold);

console.log(JSON.stringify(out));
