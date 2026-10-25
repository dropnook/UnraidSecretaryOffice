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

console.log(JSON.stringify(out));
