/* The Night Watchman — the office's security department. His page: the last
   round (when, the next one, what is open); how secure it stands (his posture
   tips: what he would set differently, with why, a link into Unraid and «I
   know, thanks» kept on the server); what changed — the watch book (newest
   first, under day headings; entries of one kind and area on one day fold into
   one row; a filter for its words and one for its area over the whole book; a
   row unfolds to its details; «I know, thanks» per entry, per folded row and
   «Note all») and what he keeps an eye on (what is normal, summarised; also what
   starts on its own: crontabs, the plugins' .cron files, User Scripts, at,
   notification agents; the data flow: who pulls how much, containers, what is
   written into ZFS shares, SMB's users and machines — with a link to its
   history in Grafana where the office's dashboard is; the snapshots he
   follows: gone or released without the office; the network: what the router
   sends to Unraid's syslog server — its senders, devices, detections, blocks,
   counts only for other devices, the «Whole LAN» switch). His rounds run on the
   server every five minutes, read only: agent/desks/watchman.php (the network:
   agent/lib/watchnet.php). Everything from his state goes into the page as text,
   never as HTML. */
(() => {
'use strict';

const ID = 'watchman';
const T = Office.scope(ID);
const { el, fmt } = Office;
const POLL_MS = 2000;
const PAGE = 30;                // rows of the book shown at first (and per «Show more»)
/** The kernel's names of CPU flaws (/sys/devices/system/cpu/vulnerabilities) as people know them */
const VULN = {
  spectre_v1: 'Spectre v1', spectre_v2: 'Spectre v2', spec_store_bypass: 'Speculative Store Bypass', vmscape: 'VMScape',
  spec_rstack_overflow: 'SRSO (Inception)', retbleed: 'Retbleed', mds: 'MDS', meltdown: 'Meltdown', l1tf: 'L1TF',
  tsa: 'TSA', gather_data_sampling: 'Downfall (GDS)', mmio_stale_data: 'MMIO Stale Data', reg_file_data_sampling: 'RFDS',
  srbds: 'SRBDS', tsx_async_abort: 'TAA', itlb_multihit: 'iTLB Multihit', indirect_target_selection: 'ITS',
  ghostwrite: 'GhostWrite', old_microcode: 'old microcode',
};

let state = null;
let view = null;
let timer = null;
let before = null;              // entry ids before a round this page asked for: say how many came
let beforeLast = null;          // … and when the last round ended then: «done» only for a round that ended after it
let shown = PAGE;
// the book's filter bar (core.js Office.filterBar, built once): its words are never kept (a new visit starts with the
// whole book; «only open» is the tile «Not noted yet»), its area select is a choice, kept in this browser
// ('watchman.book.pick')
let bookBar = null;
const foldsOpen = new Set();    // rows of the book that fold several entries, unfolded on this visit (their keys)
const foldsShut = new Set();    // … folded again by hand while filter words unfold them all
const openGroups = Office.storeJson('watchman.groups') || {};     // groups of «What I keep an eye on» that are open
const unfolded = new Set();     // entries unfolded on this visit
let bookRows = [];              // the book's unfoldable rows: {open(), set(open)}
let bookLabel = null;           // brings «Unfold all» up to date
let watchWords = [];            // the area's filter words while its folds of what he knows are drawn (group() reads them)
const watchShut = new Set();    // groups folded by hand while filter words unfold them (forgotten with the words)
let showKnownTips = false;      // posture tips you know about: shown on request
let wanted = null;              // an entry the search asked for (#/watchman/entry/<id>): the book shows rows down to it

const openCount = () => Object.values((state && state.open) || {}).reduce((a, b) => a + (Number(b) || 0), 0);
const postureOpen = () => Number((state && state.posture && state.posture.open) || 0);
const running = () => !!(state && state.round && state.round.running);
const hired = () => !!(Office.desks.get(ID) || {}).hired;

// ------------------------------------------------------------------ loading
/** His state: as kept at once, a new look following on his page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  mood();
  if (view) Office.keepInPlace(null, render);
  follow();
}

/** His picture: the lantern — with a mark while something in the book is not noted */
function mood() {
  if (state) Office.setDeskMood(ID, openCount() ? 'alert' : '');
}

/** While a round or a look at what holds the array runs (and his page is shown), look again every two seconds; say when a round is done */
function follow() {
  clearTimeout(timer);
  if (!view) return;
  if (running() || holdRunning()) {
    timer = setTimeout(() => load(true), POLL_MS);
    return;
  }
  if (before) {
    const n = (state.book || []).filter((e) => !before.has(e.id)).length;
    const r = state.round || {};
    // «done» only when a round really ended after the click: one stopped or crashed wrote none (truth review T29)
    const ended = r.last && r.last !== beforeLast;
    before = null;
    if (r.failed || r.broken || !ended) Office.toast(T(r.broken && r.broken.why === 'stopped' ? 'round_stopped' : 'round_failed'), true);
    else Office.toast(n ? T('round_done_new', { n }) : T('round_done'));
  }
}

async function roundNow() {
  if (running() || !Office.agent.running || !hired()) return;
  const ids = new Set(((state && state.book) || []).map((e) => e.id));
  const last = (state && state.round && state.round.last) || null;
  const j = await Office.api.post(`${ID}.round`, {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  before = ids;
  beforeLast = last;
  if (j.state) state = j.state;
  if (view) Office.keepInPlace(null, render);
  follow();
}

// ------------------------------------------------------------------ «I know, thanks»
// ------------------------------------------------------------------ his setup (1.96 step 5; «einfacher»: by tile)
/*
 * The second page «Setup»: the same 12 tiles on a switcher of its own (#/watchman/setup/<tile>), no lights but how each
 * stands — «defaults», «n changed», «off», what is always in order. A tile's section: «Watch this area» (off only after
 * a clear question naming what you won't see), when he tells (its settings, each with its default in words; «Apply»,
 * «Back to the defaults»), «Always in order (n)» with «Add…», «Remembered (n)», «Re-learn this area…». Over the row
 * «Only what is changed» (it was the tile «Everything changed»): every tile with a setting off its default, an area
 * off, something always in order. Whoever never opens it notices nothing of it.
 */
// a setting shown on another tile than its area's (the flash's go file: what runs at the start)
const SETTING_TILE = { 'flash.go_blank': 'plugins' };
// a hand-made «always in order» shown on another tile than its area's (an extra package on the flash: what runs at the start)
const HAND_TILE = { 'flash.extra': 'plugins' };
const setupAreas = () => CATS.filter(catShown);
/** A tile's settings: per area its keys shown here, [[area, {key: setting}], …] */
// settings his rules file keeps but his setup no longer shows: «maybe affected» is never told (2026-11-23)
const SETTING_GONE = new Set(['vuln.maybe']);
function settingsFor(tid) {
  const out = [];
  Object.entries((state && state.settings) || {}).forEach(([cat, keys]) => {
    const here = Object.fromEntries(Object.entries(keys || {})
      .filter(([k]) => !SETTING_GONE.has(`${cat}.${k}`) && (SETTING_TILE[`${cat}.${k}`] || tileOfCat(cat)) === tid));
    if (Object.keys(here).length) out.push([cat, here]);
  });
  return out;
}
/** A tile's hand-made types: [[area, type], …] */
const handFor = (tid) => Object.keys((state && state.hand) || {}).flatMap((cat) => handTypes(cat).map((t) => [cat, t]))
  .filter(([cat, t]) => (HAND_TILE[`${cat}.${t}`] || tileOfCat(cat)) === tid);
const tileRules = (tid) => ((state && state.rules) || []).filter((r) => r && (r.tile || tileOfCat(r.cat)) === tid);
const tileKnown = (tid) => ((state && state.known) || []).filter((k) => k && (k.tile || tileOfCat(k.cat)) === tid);
/** What differs from the defaults on a tile: its settings off their default */
const tileChanged = (tid) => settingsFor(tid).flatMap(([cat, set]) => Object.entries(set).filter(([, v]) => v && v.value !== v.def).map(([k, v]) => [cat, k, v]));
const setupSw = Office.sections({
  id: `${ID}/setup`,
  key: `${ID}.setup.tile`,
  bodyId: 'wm-setup-areas',
  grid: 'stats wm-setup-tiles',
  default: '',
  tiles: () => TILES.map((id) => ({ id, key: `area.${id}`, place: `setup.${id}`, tile: (a) => setupTile(id, a), section: () => setupSection(id),
    words: () => [tileName(id), T(`area.${id}_sub`), ...tileParts(id).map((c) => catName(c))] })),
  wants: (sub) => TILES.includes(sub),
  // «Only what is changed» (the tile «Everything changed» up to 1.96 «einfacher»)
  filter: { label: () => T('setup.row_filter'), only: () => ({ text: T('setup.only_changed'), hint: T('setup.only_changed_hint') }),
    words: (x) => (x.words ? x.words() : [x.id]), finding: (x) => !!(offAt(x.id) || partsOff(x.id).length || tileChanged(x.id).length || tileRules(x.id).length) },
});
function renderSetup(root) {
  root.appendChild(Office.pageHelp(`${ID}-setup`, [
    [T('help.setup'), T('help.setup_text')],
    [T('help.setup_off'), T('help.setup_off_text')],
    [T('help.setup_rules'), T('help.setup_rules_text')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  const top = el('div', 'wm-setup-top');
  top.append(syslogSwitch(), relearnAllBar());        // for experts: each new entry also into Unraid's syslog (a SIEM)
  root.appendChild(top);
  root.appendChild(setupSw.el);
  setupSw.render();
}
const offAt = (id) => Number(tileOf(id) && tileOf(id).off) || 0;
/** The areas of a tile switched off on their own (an older office's switch per area) */
const partsOff = (tid) => tileParts(tid).filter((c) => Number(state && state.off && state.off[c]));
function setupTile(id, a) {
  const n = tileChanged(id).length;
  const r = tileRules(id).length;
  const value = offAt(id) ? T('setup.off') : n ? T('setup.changed_n', { n }) : T('setup.default');
  return Office.stat({ label: tileName(id), value, alert: !!offAt(id), sub: r ? T('setup.rules_n', { n: r }) : '', ...a });
}
/** A tile's setup: watch it or not, when he tells, what is always in order (by hand too), what you had him remember, re-learn */
function setupSection(id) {
  const s = el('section', 'section wm-setup');
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  const vuln = id === 'vuln' && state.vuln;           // «Known vulnerabilities»: its own switch (off until switched on — nothing fetched)
  cb.checked = vuln ? state.vuln.on !== false && !offAt(id) : !offAt(id);
  cb.disabled = !Office.agent.running || !hired();
  label.append(cb, el('span', '', T('setup.watch_switch')));
  label.title = T(vuln ? 'vuln.switch_title' : 'setup.watch_switch_title');
  cb.onchange = () => (vuln ? vulnSet(cb.checked, cb) : tileOffAsk(id, !cb.checked, cb));
  s.appendChild(Office.sectionHead(tileName(id), T(`area.${id}_sub`), label));
  if (offAt(id)) s.appendChild(el('p', 'callout', T('setup.off_since', { when: fmt.date(offAt(id)) })));
  else partsOff(id).forEach((c) => s.appendChild(el('p', 'callout', T('setup.part_off', { area: catName(c), when: fmt.date(Number(state.off[c])) }))));
  if (id === 'net') {
    const box = el('div', 'box wm-settings');
    box.appendChild(lanSwitch());           // «Whole LAN» (his switch from before 1.96, kept in his state)
    s.appendChild(box);
  }
  if (id === 'wakes' && state.wakes) {
    const box = el('div', 'box wm-settings');
    const row = el('div', 'row nocheck wm-item');
    row.appendChild(wakesSwitch());         // «Watch the wakes» (his switch, kept in wakes.json)
    box.appendChild(row);
    s.appendChild(box);
  }
  settingsFor(id).forEach(([cat, set]) => s.appendChild(settingsBox(cat, set)));
  const rules = catRules(id);
  if (rules) s.appendChild(rules);
  if (handFor(id).length) {
    const add = el('button', 'btn small', T('setup.add'));
    add.type = 'button';
    add.disabled = !Office.agent.running || !hired();
    add.onclick = () => ruleHandDialog(id, add);
    const acts = el('div', 'wm-setup-acts');
    acts.appendChild(add);
    s.appendChild(acts);
  }
  const known = catKnown(id);
  if (known) s.appendChild(known);
  s.appendChild(relearnBox(id));
  return s;
}
/** A setting's value in words: a number with its unit, a switch on or off, a choice by its name */
function settingWords(id, k, v, x) {
  if (typeof x === 'boolean') return T(x ? 'setup.switch_on' : 'setup.switch_off');
  if (v.unit === 'n0') return Number(x) ? T('setup.unit.n', { n: x }) : T('setup.no_judgement');
  if (typeof x === 'string') return T(`setting.${id}.${k}.${x}`);
  return T(`setup.unit.${v.unit || 'n'}`, { n: x });
}
/** An area's settings: a field each (a number, a switch, a choice) with its default in words, «Apply» and «Back to the defaults» */
function settingsBox(id, set) {
  const box = el('div', 'box wm-settings');
  const fields = {};
  Object.entries(set).forEach(([k, v]) => {
    const row = el('div', 'row nocheck wm-setting');
    let inp;
    if (typeof v.def === 'boolean') {
      inp = el('input');
      inp.type = 'checkbox';
      inp.checked = !!v.value;
      fields[k] = () => inp.checked;
    } else if (typeof v.def === 'string') {
      inp = el('select', 'picker');
      (v.of || []).forEach((x) => inp.appendChild(new Option(T(`setting.${id}.${k}.${x}`), x)));
      inp.value = v.value;
      fields[k] = () => inp.value;
    } else {
      inp = el('input', 'search wm-setting-num');
      inp.type = 'number';
      inp.min = String(v.min);
      inp.max = String(v.max);
      inp.step = '1';
      inp.value = String(v.value);
      fields[k] = () => { const n = Number(inp.value); return Number.isInteger(n) ? n : null; };
    }
    inp.setAttribute('aria-label', T(`setting.${id}.${k}`));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T(`setting.${id}.${k}`)));
    main.appendChild(el('div', 'row-meta', typeof v.def === 'number'
      ? T('setup.def', { value: settingWords(id, k, v, v.def), min: settingWords(id, k, v, v.min), max: settingWords(id, k, v, v.max) })
      : T('setup.def_value', { value: settingWords(id, k, v, v.def) })));
    row.append(main, inp);
    box.appendChild(row);
  });
  const acts = el('div', 'wm-entry-acts');
  const apply = el('button', 'btn small', T('setup.apply'));
  apply.type = 'button';
  apply.disabled = !Office.agent.running || !hired();
  apply.onclick = () => {
    const out = {};
    for (const [k, get] of Object.entries(fields)) {
      const x = get();
      if (x === null) { Office.toast(T('setup.not_number'), true); return; }
      out[k] = x;
    }
    catSet(id, { set: out }, apply);
  };
  const back = el('button', 'btn small plain', T('setup.reset'));
  back.type = 'button';
  back.disabled = apply.disabled || !changedOf(id).length;
  back.onclick = () => catSet(id, { reset: true }, back);
  acts.append(apply, back);
  box.appendChild(acts);
  return box;
}
async function catSet(id, body, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.cat_set`, { cat: id, ...body });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); b.disabled = false; return; }
  if (j.state) state = j.state;
  renderAt('.wm-setup');
  Office.toast(T(body.reset ? 'setup.reset_done' : 'setup.saved'));
}
/** «Known vulnerabilities» on or off from his setup (off: nothing fetched any more; an area switched off by hand is on again with it) */
async function vulnSet(on, cb) {
  cb.disabled = true;
  if (on && offAt('vuln')) {
    const k = await Office.api.post(`${ID}.cat_off`, { cat: 'vuln', off: false, sure: false });
    if (!k.ok) { Office.toast(Office.errorText(k.error, ID), true); cb.checked = !on; cb.disabled = false; return; }
  }
  const j = await Office.api.post(`${ID}.vuln_set`, { on });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); cb.checked = !on; cb.disabled = false; return; }
  if (j.state) state = j.state;
  mood();
  renderAt('.wm-setup');
  Office.toast(T(on ? 'vuln.on' : 'vuln.off_done'));
}
/** «Watch this area» off: one clear question naming what you won't see any more (every area of the tile); on again at once */
function tileOffAsk(id, off, cb) {
  const go = async () => {
    cb.disabled = true;
    const j = await Office.api.post(`${ID}.tile_off`, { tile: id, off, sure: off });
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); cb.checked = off; cb.disabled = false; return; }
    if (j.state) state = j.state;
    mood();
    renderAt('.wm-setup');
    Office.toast(T(off ? 'setup.turned_off' : 'rule.cat_on_done'));
  };
  if (!off) { go(); return; }
  const body = el('div', 'wm-rule-ask');
  body.append(el('p', 'callout warn', T('setup.off_ask', { area: tileName(id) })), el('p', '', T(`area.${id}_sub`)), el('p', '', T('setup.off_note')));
  if (id === 'access') body.appendChild(el('p', 'role', T('setup.off_flash')));      // the flash is one area: its go file and start files too
  Office.dialog({ title: T('setup.off_title'), body, onClose: () => { cb.checked = !offAt(id); }, buttons: [
    { text: T('setup.off_yes', { area: tileName(id) }), kind: 'danger', act: () => { go(); } },
    { text: Office.t('common.cancel') },
  ] });
}
/** The exceptions an area takes by hand (the messenger's list, WATCH_HAND) */
const handTypes = (id) => ((state && state.hand && state.hand[id]) || []).filter((x) => typeof x === 'string');
// an example where he knows no names to suggest (invented, never a name from a real server)
const HAND_EXAMPLE = { net: '192.168.1.0/24', ip: '203.0.113.7', user_net: '192.168.1.0/24', machine: 'LAPTOP', ports: 'plex', rights: 'plex',
  privileged: 'plex', host: 'plex', new: 'plex', source: 'github.com/someone', script: 'backup', cron_file: 'myplugin/daily.cron', crond: 'myjob',
  client: '192.168.1.20', client_min: '192.168.1.20', container: 'plex', gone: 'Media', written: 'Media', gone_min: 'Media', written_min: 'Media',
  files_min: 'Media', kept: 'Media', layers: 'Media', disk_gone: 'disk1', disk_written: 'disk1', series: 'zfs-auto-snap_hourly-#',
  series_ds: 'tank/Media', dataset: 'tank/Media', hold: 'tank/Media', extra: 'tool.txz', guest_read: 'Media', guest_write: 'Media', port: '8384',
  prog: 'syncthing', flood: '443', tunnel: 'wg0', admin: '192.168.1.0/24', vpn: 'phone', link: 'ether5', sender: '192.168.1.1', network: 'LAN',
  vm: 'Windows 11', userscript: 'backup', cause: 'plex', disk: 'disk3', crash: 'smbd', oom: 'emby', cve: 'CVE-2024-4577',
  product: 'php' };
/*
 * The names he knows, suggested as you type (state.hand_names, watchmanHandNames(): only what he already has): the list a
 * type's value takes. HAND_FULL: the lists that hold every such name he knows — a name typed outside one gets a calm line
 * beneath the field (a typo makes no silent, useless exception); «Add» still takes it.
 */
const HAND_LIST = { machine: 'machine', ports: 'container', rights: 'container', privileged: 'container', host: 'container', new: 'container',
  container: 'container', source: 'source', script: 'script', userscript: 'script', cron_file: 'cron_file', crond: 'crond', gone: 'share', written: 'share',
  gone_min: 'share', written_min: 'share', files_min: 'share', kept: 'share', layers: 'share', guest_read: 'share', guest_write: 'share',
  disk_gone: 'disk', disk_written: 'disk', series: 'series', series_ds: 'dataset', dataset: 'dataset', hold: 'dataset', extra: 'extra', prog: 'prog',
  crash: 'prog', oom: 'prog', flood: 'flood', tunnel: 'tunnel', vpn: 'vpn', link: 'iface', sender: 'sender', network: 'network', vm: 'vm',
  disk: 'wake_disk', product: 'product' };
const HAND_FULL = new Set(['machine', 'container', 'script', 'cron_file', 'crond', 'share', 'disk', 'series', 'extra', 'tunnel', 'vpn', 'sender',
  'vm', 'wake_disk', 'product']);
/** The names of a list (sorted, names only), [] when he knows none — a wake's cause: those of the kind chosen (and its containers, VMs) */
function handNames(list, kind) {
  const all = (state && state.hand_names) || {};
  const of = (id) => (Array.isArray(all[id]) ? all[id].filter((x) => typeof x === 'string') : []);
  if (list !== 'cause') return of(list);
  const pre = `${kind}:`;
  const named = of('cause').filter((x) => x.startsWith(pre)).map((x) => x.slice(pre.length));
  const more = kind === 'container' ? of('container') : kind === 'vm' ? of('vm') : [];
  return [...new Set([...named, ...more])].sort((a, b) => a.localeCompare(b));
}
/*
 * What a wide exception made by hand means, said beneath its field as he types (1.96, Benj: typing it and «Add» IS the
 * decision — no second yes): a public address or net (the agent's watchmanIpPrivate(); a public net at most /24, IPv6
 * /48 — else the agent refuses: said here first), a whole plugin source, the data flow's let-through, snapshots that may
 * go, guests writing, a router's whole sender or a VPN user, a whole product or a CVE. Null: nothing beyond what it says.
 */
function ipPublic(v) {
  const [addr, bits] = String(v || '').trim().split('/');
  const m = /^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/.exec(addr);
  if (m) {
    const a = +m[1], b = +m[2];        // 10/8, 172.16/12, 192.168/16, 100.64/10 (the agent's list)
    const priv = a === 10 || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127);
    return priv ? null : { wide: bits !== undefined && Number(bits) < 24 };
  }
  if (/^[0-9a-f:]+$/i.test(addr) && addr.includes(':')) {
    const head = parseInt(addr.split(':')[0] || '0', 16);      // fc00::/7, fe80::/10
    const priv = (head & 0xfe00) === 0xfc00 || (head & 0xffc0) === 0xfe80;
    return priv ? null : { wide: bits !== undefined && Number(bits) < 48 };
  }
  return null;                    // not an address (yet): the agent says what is wrong
}
function handNote(id, t, v) {
  if (['net', 'ip', 'user_net', 'admin'].includes(t)) {
    const p = ipPublic(v);
    if (!p) return null;
    if (p.wide) return 'setup.add_net_wide';
    return t === 'admin' ? 'setup.add_public_router' : 'setup.add_public';
  }
  if (id === 'plugin' && t === 'source') return v.trim() && !v.includes('/') ? 'setup.add_wide.plugin' : null;
  if (id === 'data') return 'setup.add_wide.data';
  if (id === 'snap') return 'setup.add_wide.snap';
  if (t === 'guest_write') return 'setup.add_wide.share';
  if (t === 'vpn' || t === 'sender') return 'setup.add_wide.router';
  if (t === 'privileged' || t === 'host' || t === 'new') return `setup.add_wide.${t}`;      // a container's wide rights
  if (t === 'product') return 'setup.add_wide.vuln';
  if (t === 'cve') return 'setup.add_wide.cve';
  return null;
}
// a second field: a user from a net, a series on a dataset, an admin, a router — typed ([label, example, its list]); the
// protocol of a share open to guests and the kind of a wake's cause — a choice ([label, null, the values the agent takes])
const HAND_SECOND = { user_net: ['setup.add_user', 'root'], series_ds: ['setup.add_second.series_ds', 'zfs-auto-snap_hourly-#', 'series'],
  guest_read: ['setup.add_second.proto', null, ['smb', 'nfs']], guest_write: ['setup.add_second.proto', null, ['smb', 'nfs']],
  admin: ['setup.add_second.admin', 'admin', 'admin'], link: ['setup.add_second.sender', '192.168.1.1', 'sender'],
  cause: ['setup.add_second.cause', null, ['container', 'vm', 'smb', 'nfs', 'office', 'mover', 'cron', 'program']] };
const handAmount = (t) => (state && state.hand_amount && state.hand_amount[t]) || null;
/**
 * «Add an exception…» by hand: what kind of exception (the area's types), its value typed, a user for «a user from a net»
 * — the messenger checks it (a public net at most a /24); what a wide one means is said beneath the field as he types,
 * never a second yes (handNote(); 1.96, Benj: «Add» is the decision)
 */
function ruleHandDialog(tid, b) {
  const pairs = handFor(tid);
  const body = el('div', 'wm-rule-hand');
  const type = el('select', 'picker');
  pairs.forEach(([cat, x]) => type.appendChild(new Option(T('setup.add_type.' + x), `${cat}|${x}`)));
  const catOfType = () => String(type.value).split('|')[0];
  const typeOf = () => String(type.value).split('|')[1] || '';
  type.setAttribute('aria-label', T('setup.add_type_label'));
  // the names he knows as suggestions while typing (a <datalist>: free typing stays), one list per field
  const names = el('datalist');
  names.id = 'wm-hand-names';
  const names2 = el('datalist');
  names2.id = 'wm-hand-names2';
  const fill = (dl, list) => { dl.replaceChildren(...list.map((n) => new Option(n))); };
  const value = el('input', 'search');
  value.type = 'text';
  value.setAttribute('list', names.id);
  value.autocomplete = 'off';
  const user = el('input', 'search');
  user.type = 'text';
  user.placeholder = 'root';
  user.setAttribute('aria-label', T('setup.add_user'));
  user.setAttribute('list', names2.id);
  user.autocomplete = 'off';
  const pick = el('select', 'picker');          // a second field that is a choice (SMB or NFS, the kind of a cause)
  const userRow = el('label', 'wm-hand-row');
  const userName = el('span', '', T('setup.add_user'));
  userRow.append(userName, user, pick);
  const valueName = el('span', '');
  const amount = el('input', 'search wm-setting-num');
  amount.type = 'number';
  amount.min = '1';
  amount.step = '1';
  const amountName = el('span', '');
  const amountRow = el('label', 'wm-hand-row');
  amountRow.append(amountName, amount);
  const login = ['net', 'ip', 'user_net'];
  const netNot = el('p', 'role', T('rule.ask_net_not'));
  const second = () => HAND_SECOND[typeOf()] || null;
  const choice = () => Array.isArray((second() || [])[2]);
  const known = () => (HAND_LIST[typeOf()] ? handNames(HAND_LIST[typeOf()], pick.value) : typeOf() === 'cause' ? handNames('cause', pick.value) : []);
  const sync = () => {
    const t = typeOf();
    const s2 = second();
    userRow.hidden = !s2;
    user.hidden = !s2 || choice();
    pick.hidden = !s2 || !choice();
    if (s2) {
      userName.textContent = T(s2[0]);
      if (choice()) {
        const was = pick.value;
        pick.replaceChildren(...s2[2].map((v) => new Option(T(`side.kind.${v}`), v)));
        if (s2[2].includes(was)) pick.value = was;
        pick.setAttribute('aria-label', userName.textContent);
      } else {
        const list2 = typeof s2[2] === 'string' ? handNames(s2[2]) : [];
        fill(names2, list2);
        user.placeholder = list2[0] || s2[1];
        user.setAttribute('aria-label', userName.textContent);
      }
    }
    netNot.hidden = !login.includes(t);
    const list = known();
    fill(names, list);
    value.placeholder = list[0] || HAND_EXAMPLE[t] || '';
    valueName.textContent = login.includes(t) ? T('setup.add_value') : T('setup.add_value.' + t);
    value.setAttribute('aria-label', valueName.textContent);
    const unit = handAmount(t);
    amountRow.hidden = !unit;
    if (unit) {
      amountName.textContent = T('setup.add_amount.' + unit);
      amount.setAttribute('aria-label', amountName.textContent);
    }
  };
  // what it means, beneath the field as he types — never a second yes (handNote()); a name he doesn't know (HAND_FULL)
  const note = el('p', 'callout wm-hand-note');
  const unknown = el('p', 'role wm-hand-note', T('setup.add_unknown'));
  const said = () => {
    const k = handNote(catOfType(), typeOf(), value.value);
    note.hidden = !k;
    note.textContent = k ? T(k) : '';
    const list = known();
    const v = value.value.trim();
    unknown.hidden = !(v && list.length && HAND_FULL.has(HAND_LIST[typeOf()]) && !list.includes(v));
  };
  type.addEventListener('change', () => { sync(); said(); });
  pick.addEventListener('change', () => { sync(); said(); });
  value.addEventListener('input', said);
  const tr = el('label', 'wm-hand-row');
  tr.append(el('span', '', T('setup.add_type_label')), type);
  const vr = el('label', 'wm-hand-row');
  vr.append(valueName, value);
  body.append(tr, vr, unknown, note, amountRow, userRow, netNot, names, names2);
  sync();
  said();
  Office.dialog({ title: T('setup.add'), body, buttons: [
    { text: T('rule.add'), kind: 'primary', act: () => {
      (async () => {
        b.disabled = true;
        const n = Number(amount.value);
        if (handAmount(typeOf()) && !(Number.isInteger(n) && n >= 1)) { b.disabled = false; Office.toast(T('errors.watch_amount_bad'), true); return; }
        // the second field as the agent takes it: typed, or the choice's value (smb/nfs, a cause's kind)
        const two = !second() ? '' : choice() ? pick.value : user.value.trim();
        const j = await Office.api.post(`${ID}.rule_hand`, { cat: catOfType(), type: typeOf(), value: value.value.trim(), user: two,
          ...(handAmount(typeOf()) ? { amount: n } : {}) });      // no `sure` (1.96): the note said what it means
        b.disabled = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
        if (j.state) state = j.state;
        if (j.rule) justMade.add(j.rule);
        renderAt('.wm-setup');
        Office.toast(T('rule.added'));
      })();
    } },
    { text: Office.t('common.cancel') },
  ] });
}

// ------------------------------------------------------------------ exceptions (1.96, agent/desks/watchman-rules.php)
const OFFERS = ['key', 'ip', 'net', 'user_net', 'machine', 'source', 'file', 'cmd', 'share', 'disk', 'min', 'kept', 'layers', 'series_ds', 'series', 'hold', 'open',
  'prog', 'tunnel', 'pair_ip', 'pair_net', 'admin_ip', 'admin_net', 'vpn', 'link', 'sender', 'network', 'thing', 'max_c'];
// kinds whose key is «<kind>:<the thing's name>»: an exception for exactly it reads «The ports of plex may change» (6a)
const KEY_NAMED = ['container_ports', 'container_rights', 'script_changed', 'cron_file', 'flow_client', 'flow_container', 'flash_extra', 'listen_new',
  'container_privileged', 'container_host', 'container_new', 'net_blocked_from_server'];
/** A named key's thing in words: a client of the data flow «192.168.1.5 (SMB)» (its key: ip|service), else the name */
function keyThing(kind, name) {
  if (kind === 'listen_new') return name.replace(/^tcp:/, '');
  if (kind !== 'flow_client') return name;
  const [ip, svc] = name.split('|');
  return svc ? `${ip} (${({ smb: 'SMB', nfs: 'NFS', ssh: 'SSH', web: 'WebGUI' })[svc] || svc})` : ip;
}
/** What a threshold of the data flow is about, in words: a share's or disk's gone / written / files, a computer, a container */
function flowWhat(o) {
  const on = o.on || {};
  const kind = (o.kinds || [])[0] || '';
  const opt = o.opt || {};
  if (on.share || on.disk) {
    const what = on.share || on.disk;
    return T(opt.files !== undefined ? 'rule.flow.files' : kind === 'flow_written' ? 'rule.flow.written' : 'rule.flow.gone', { what });
  }
  if (on.ip) return on.ip;
  if (typeof on.key === 'string') {
    const named = KEY_NAMED.find((k) => on.key.startsWith(`${k}:`));
    if (named) return keyThing(named, on.key.slice(named.length + 1));
  }
  return '…';
}
const justMade = new Set();     // exceptions made on this visit: «Undo» beside «Lift» (their entries open again)
/**
 * An exception in words: «192.168.1.0/24 always okay», «root from 192.168.1.0/24 …»; exactly one thing: on its own
 * entry «This one always okay», elsewhere (the list, the dialog) «‹the entry, shortened› always okay»
 */
function offerName(o, e, here) {
  const on = o.on || {};
  // 1.96's new kinds: this thing across days and boots (a sensor, a disk, a program, a CVE …); a sensor up to so many °C
  if (typeof on.thing === 'string') {
    const what = on.thing.split(':')[0];
    const name = o.what || on.thing.slice(what.length + 1);
    if (on.max_c) return T('rule.offer.max_c', { name, c: on.max_c });
    if (on.thing === 'port:conntrack') return T('rule.offer.thing.conntrack');
    return T(`rule.offer.thing.${what === 'cve' || what === 'product' ? what : (o.kinds || [])[0] || what}`, { name });
  }
  // what holds the array, the wakes (6f)
  if (on.vm) return T('rule.offer.vm', { name: on.vm });
  if (on.script) return T('rule.offer.script', { name: on.script });
  if (on.cause) return T('rule.offer.cause', { name: on.cause.slice(on.cause.indexOf(':') + 1), kind: T(`side.kind.${on.cause.split(':')[0]}`) });
  if (on.disk && (o.kinds || [])[0] === 'wakes') return T('rule.offer.wake_disk', { disk: on.disk });
  // the partner door and the network (6e) — before the logins' shapes: they carry an address or a net too
  if (on.pair) return T('rule.offer.pair', { name: o.what || on.pair, where: on.net || on.ip });
  if (on.admin) return T('rule.offer.admin', { admin: on.admin, where: on.net || on.ip });
  if (on.vpn) return T('rule.offer.vpn', { user: on.vpn });
  if (on.iface) return T('rule.offer.link', { iface: on.iface, sender: on.sender });
  if (on.sender) return T('rule.offer.sender', { sender: on.sender });
  if (on.network) return T('rule.offer.network', { network: on.network });
  if (on.open) return T(`rule.offer.open_${on.open === 'write' ? 'write' : 'read'}`, { share: on.share, proto: String(on.proto || '').toUpperCase() });
  if (on.prog) return T('rule.offer.prog', { prog: on.prog });
  if (on.tunnel) return T('rule.offer.tunnel', { tunnel: on.tunnel });
  if (on.series) return on.dataset ? T('rule.offer.series_ds', { series: on.series, dataset: on.dataset }) : T('rule.offer.series', { series: on.series });
  if (on.dataset) return T((o.kinds || [])[0] === 'snap_hold_released' ? 'rule.offer.hold' : 'rule.offer.dataset', { dataset: on.dataset });
  if (on.user) return T('rule.offer.user_net', { user: on.user, net: on.net });
  if (on.net) return T('rule.offer.net', { net: on.net });
  if (on.ip && !(o.opt) && (o.kinds || []).length === 1 && o.kinds[0] === 'flow_client') return T('rule.key.flow_client', { name: on.ip });
  if (on.ip && !(o.opt)) return T('rule.offer.ip', { ip: on.ip });
  if (on.machine) return T('rule.offer.machine', { machine: on.machine });
  if (on.source) return T('rule.offer.source', { source: on.source });
  if (on.file) return T('rule.offer.file', { file: on.file });
  if (on.cmd) return T('rule.offer.cmd', { cmd: o.what || '…' });
  const opt = o.opt || {};
  if (opt.min !== undefined) return T('rule.offer.min', { what: flowWhat(o), amount: fmt.size(opt.min) });
  if (opt.files !== undefined) return T('rule.offer.min', { what: flowWhat(o), amount: Number(opt.files).toLocaleString() });
  if (opt.kept === false) return T('rule.offer.kept', { share: on.share || '' });
  if (opt.layers === false) return T('rule.offer.layers', { share: on.share || '' });
  if (on.share) return T(`rule.offer.share.${(o.kinds || [])[0] === 'flow_written' ? 'flow_written' : 'flow_gone'}`, { share: on.share });
  if (on.disk) return T(`rule.offer.disk.${(o.kinds || [])[0] === 'flow_written' ? 'flow_written' : 'flow_gone'}`, { disk: on.disk });
  const named = typeof on.key === 'string' ? KEY_NAMED.find((k) => on.key.startsWith(`${k}:`)) : null;
  if (named && (o.kinds || []).length === 1 && o.kinds[0] === named) {
    const thing = on.key.slice(named.length + 1);
    // a program on changing ports (its key «prog:*»): its own words
    if (named === 'listen_new' && thing.endsWith(':*')) return T('rule.offer.prog', { prog: thing.slice(0, -2) });
    return T(`rule.key.${named}`, { name: keyThing(named, thing) });
  }
  if (here) return T('rule.offer.key');
  const src = e || ((state && state.book) || []).find((x) => x && x.id === o.from);
  const what = src ? entryName(src) : '';
  return what ? T('rule.key_of', { what: what.length > 70 ? what.slice(0, 69) + '…' : what }) : T('rule.offer.key');
}
/**
 * «Such a thing is always in order …» on an open entry (1.96 «einfacher»: one button, before «… always okay» and «more ▾»):
 * a short choice in whole sentences, the narrowest first — under the chosen one what it covers, what not, where it is
 * lifted, a clearer word for the kinds he asks about; the confirm button names what follows. The messenger looks the
 * offer up anew from the entry (never trusts a net or a name from the page). Then the undo line.
 */
function ruleAsk(e, offers, b) {
  const body = el('div', 'wm-rule-ask');
  const name = `wm-offer-${e.id}`;
  let chosen = offers[0];
  const why = el('div', 'wm-rule-why');
  const yes = { text: '', kind: 'primary', act: () => { ruleAdd(e, chosen, b); } };
  const say = () => {
    const what = offerName(chosen, e, true);
    const n = Math.max(1, Number(chosen.covers) || 1);
    why.replaceChildren();
    [T('rule.ask_covers', { what: offerName(chosen, e), n }), chosen.type === 'net' || chosen.type === 'ip' ? T('rule.ask_net_not') : '',
      chosen.ask ? T('rule.ask_serious') : '', e.again ? T('rule.ask_incident') : '',
      (chosen.kinds || []).some((k) => k === 'flow_gone') ? T('rule.ask_must') : '', T('rule.ask_where')]
      .filter(Boolean).forEach((x) => why.appendChild(el('p', chosen.ask && x === T('rule.ask_serious') ? 'callout warn' : '', x)));
    yes.text = T('rule.confirm', { what });
    const btn = document.querySelector('#sso-dialog-foot .btn.primary');
    if (btn) btn.textContent = yes.text;
  };
  offers.forEach((o, i) => {
    const label = el('label', 'switch wm-offer-pick');
    const r = el('input');
    r.type = 'radio';
    r.name = name;
    r.checked = i === 0;
    r.onchange = () => { if (r.checked) { chosen = o; say(); } };
    label.append(r, el('span', '', offerName(o, e, true)));
    body.appendChild(label);
  });
  body.appendChild(why);
  say();
  Office.dialog({ title: T('rule.always'), body, cls: 'wm-ask-dialog', buttons: [yes, { text: Office.t('common.cancel') }] });
  if (b) b.blur();
}
async function ruleAdd(e, o, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.rule_add`, { id: e.id, type: o.type, sure: !!o.ask });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  if (j.rule) justMade.add(j.rule);
  mood();
  renderKeeping(e.id);
  const more = Math.max(0, (Number(j.noted) || 1) - 1);
  const rid = j.rule;
  undoLine(more ? `${T('undo.rule')} ${T('rule.added_more', { n: more })}` : T('undo.rule'),
    () => (rid ? ruleRemove({ id: rid }, true, el('button')) : null));
}
/** «Lift» an exception (undo: right after it was made — its entries open again) */
async function ruleRemove(r, undo, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.rule_remove`, { rule: r.id, undo });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_rule_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  justMade.delete(r.id);
  mood();
  renderAt('.wm-rules');
  Office.toast(T(undo ? 'rule.undone' : 'rule.lifted'));
}
/** What differs from the defaults in an area: its settings off their default */
const changedOf = (id) => Object.entries((state && state.settings && state.settings[id]) || {}).filter(([k, v]) => v && v.value !== v.def && !SETTING_GONE.has(`${id}.${k}`));
/**
 * «Noted (n)»: your lasting answers in an area (1.96, concept 3.4) — folded until opened on this visit; each with when,
 * and «Take back» (it comes out of what is normal: still there, his next round tells it)
 */
const knownOpen = new Set();      // areas whose list is unfolded on this visit
function catKnown(id) {
  const list = tileKnown(id);      // a tile's (1.96 «einfacher»)
  if (!list.length) return null;
  const box = el('div', 'wm-known');
  const head = el('button', 'btn small plain wm-known-head', `${knownOpen.has(id) ? '▾' : '▸'} ${T('known.list', { n: list.length })}`);
  head.type = 'button';
  head.setAttribute('aria-expanded', knownOpen.has(id) ? 'true' : 'false');
  head.onclick = () => { if (knownOpen.has(id)) knownOpen.delete(id); else knownOpen.add(id); Office.keepInPlace(head, () => pageSw().render()); };
  box.appendChild(head);
  if (!knownOpen.has(id)) return box;
  const bar = listBar('known.' + id, T('known.filter_label'));
  box.appendChild(bar.el);
  const rows = el('div', 'box');
  const knownName = (k) => entryName({ kind: k.kind, p: k.p || {}, t: k.t || {}, v: null });
  barRows(bar, list.slice().sort((a, b) => b.at - a.at).slice(0, 200), (k) => [knownName(k), entryHay({ kind: k.kind, p: k.p || {}, t: k.t || {}, v: null })], (k) => {
    const row = el('div', 'row nocheck wm-rule');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', knownName(k)));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('known.since', { when: fmt.date(k.at) })));
    main.appendChild(meta);
    row.appendChild(main);
    const acts = el('div', 'wm-entry-acts');
    const b = el('button', 'btn small plain', T('known.take_back'));
    b.type = 'button';
    b.title = T('known.take_back_title');
    b.disabled = !Office.agent.running || !hired();
    b.onclick = () => knownRemove(k, b);
    acts.appendChild(b);
    row.appendChild(acts);
    return row;
  }, rows);
  box.appendChild(rows);
  return box;
}
async function knownRemove(k, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.known_remove`, { id: k.id });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_known_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  renderAt('.wm-known');
  Office.toast(T('known.taken_back'));
}

// ------------------------------------------------------------------ re-learn (1.96 step 17)
/*
 * «Re-learn this area…» at the end of an area's setup, «Re-learn everything…» at the top of the setup — never on the watch
 * page, so it isn't hit by chance. One clear question (what becomes normal, how many open entries are noted as done, the
 * warning), two checkboxes for the exceptions and the settings; what he takes is set aside on the server (never deleted)
 * and can be brought back for seven days (agent: watchman-relearn.php).
 */
const relearnOne = (id) => (state && state.relearn && state.relearn[id] && typeof state.relearn[id] === 'object' ? state.relearn[id] : null);
/** An area's re-learn — a tile's: the newest of its areas' */
const relearnOf = (id) => (TILE_PARTS[id] ? tileParts(id).map((c) => relearnOne(c)).filter(Boolean).sort((x, y) => y.at - x.at)[0] || null : relearnOne(id));
const relearnFlow = (cats) => cats.some((c) => c === 'pull' || c === 'data');
function relearnAllBar() {
  const bar = el('div', 'wm-setup-acts wm-relearn-all');
  const b = el('button', 'btn small plain', T('setup.relearn_all'));
  b.type = 'button';
  b.title = T('setup.relearn_title');
  b.disabled = !Office.agent.running || !hired() || !state.on_watch;
  b.onclick = () => relearnAsk(null, b);
  bar.appendChild(b);
  return bar;
}
function relearnBox(id) {
  const box = el('div', 'wm-relearn');
  const r = relearnOf(id);
  if (r) {
    const all = /-all(-\d+)?$/.test(String(r.aside || ''));
    const note = el('div', 'callout');
    note.appendChild(el('p', '', T(all ? 'setup.relearn_since_all' : 'setup.relearn_since', { when: fmt.date(r.at) })
      + (r.pending ? ' ' + T('setup.relearn_pending') : '')));
    note.appendChild(el('p', '', T('setup.relearn_until', { until: fmt.date(r.until) })));
    const back = el('button', 'btn small plain', T('setup.relearn_back'));
    back.type = 'button';
    back.title = T('setup.relearn_back_title');
    back.disabled = !Office.agent.running || !hired();
    back.onclick = () => relearnBackAsk(id, r, all, back);
    note.appendChild(back);
    box.appendChild(note);
  }
  const acts = el('div', 'wm-setup-acts');
  const b = el('button', 'btn small plain', T('setup.relearn'));
  b.type = 'button';
  b.title = T('setup.relearn_title');
  b.disabled = !Office.agent.running || !hired() || !state.on_watch;
  b.onclick = () => relearnAsk(id, b);
  acts.appendChild(b);
  box.appendChild(acts);
  return box;
}
/** The clear question: id a tile (its areas), null all of them */
function relearnAsk(id, b) {
  const cats = id ? tileParts(id) : setupAreas();
  const open = ((state && state.book) || []).filter((e) => e && e.open && (id ? entryTile(e) === id : true)).length;
  const rules = id ? tileRules(id).length : ((state && state.rules) || []).length;
  const changed = id ? tileChanged(id).length : TILES.reduce((n, t) => n + tileChanged(t).length, 0);
  const area = id ? tileName(id) : '';
  const body = el('div', 'wm-rule-ask');
  body.appendChild(el('p', '', T(id ? 'setup.relearn_ask' : 'setup.relearn_all_ask')));
  if (open) body.appendChild(el('p', '', T('setup.relearn_open', { n: open })));
  if (relearnFlow(cats)) body.appendChild(el('p', '', T('setup.relearn_flow', { n: (state.flow && state.flow.learn) || 7 })));
  body.appendChild(el('p', 'callout warn', T('setup.relearn_warn')));
  body.appendChild(el('p', 'wm-note', T('setup.relearn_keep')));
  const check = (text) => {
    const label = el('label', 'switch wm-relearn-check');
    const cb = el('input');
    cb.type = 'checkbox';
    label.append(cb, el('span', '', text));
    body.appendChild(label);
    return cb;
  };
  const cbRules = rules ? check(T('setup.relearn_rules', { n: rules })) : null;
  const cbSet = changed ? check(T('setup.relearn_settings')) : null;
  Office.dialog({ title: id ? T('setup.relearn_ask_title', { area }) : T('setup.relearn_all_ask_title'), body, buttons: [
    { text: id ? T('setup.relearn_yes', { area }) : T('setup.relearn_all_yes'), kind: 'danger',
      act: () => relearnGo(id, !!(cbRules && cbRules.checked), !!(cbSet && cbSet.checked), b) },
    { text: Office.t('common.cancel') },
  ] });
}
async function relearnGo(id, rules, settings, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.relearn`, id ? { tile: id, rules, settings } : { cat: 'all', rules, settings });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); b.disabled = false; return; }
  if (j.state) state = j.state;
  mood();
  renderAt('.wm-setup');
  Office.toast(T('setup.relearn_done'));
  load(true);         // the round he started learns what needs its look
}
function relearnBackAsk(id, r, all, b) {
  const body = el('div', 'wm-rule-ask');
  body.append(el('p', '', T(all ? 'setup.relearn_back_ask_all' : 'setup.relearn_back_ask', { area: tileName(id), when: fmt.date(r.at) })),
    el('p', 'wm-note', T('setup.relearn_back_note')));
  Office.dialog({ title: T('setup.relearn_back_ask_title'), body, buttons: [
    { text: T('setup.relearn_back_yes'), kind: 'danger', act: () => relearnBack(r, b) },
    { text: Office.t('common.cancel') },
  ] });
}
async function relearnBack(r, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.relearn_back`, { name: r.aside });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && ['watch_relearn_gone', 'watch_relearn_old'].includes(j.error.key)) await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  mood();
  renderAt('.wm-setup');
  Office.toast(T('setup.relearn_back_done'));
}

/*
 * His lists' filter bars show from the first row on, not from Office.FILTER_MIN (Benj, 1.96: «hier will man vielleicht
 * kurz eine IP einfügen, ohne die Zeilen zu lesen») — the book, an area's entries, its exceptions and your answers, what
 * he keeps an eye on, the wakes, his history. Only his: the other desks keep the office's rule. An empty list has none.
 */
const WM_FILTER_MIN = 1;
/** The sections of the page shown now (his setup lists your answers and exceptions too) */
const pageSw = () => (page === 'setup' ? setupSw : sw);
const listBars = {};
/** A short list's own bar (built once, held across the renders like the book's): its words only, never kept */
function listBar(key, label) {
  listBars[key] ??= Office.filterBar({ label, min: WM_FILTER_MIN, onChange: () => { if (view) pageSw().render(); } });
  return listBars[key];
}
/** Rows through a list bar: those whose words fit, the bar told how many; a line when none fits */
function barRows(bar, list, hay, row, into) {
  const words = bar.read().words;
  const fit = list.filter((x) => Office.fits(words, hay(x)));
  fit.forEach((x) => into.appendChild(row(x)));
  if (!fit.length && list.length) into.appendChild(el('p', 'empty', T('list.none_fit')));
  bar.done(fit.length, list.length);
  return fit;
}

/** An area's exceptions: what each lets through, since when, how often it did, «Lift» (and «Undo» right after) */
function catRules(id) {
  const list = tileRules(id);      // a tile's (1.96 «einfacher»)
  if (!list.length) return null;
  const box = el('div', 'wm-rules');
  box.appendChild(el('h4', 'wm-hold-group', T('rule.list', { n: list.length })));
  const bar = listBar('rules.' + id, T('rule.filter_label'));
  box.appendChild(bar.el);
  const rows = el('div', 'box');
  barRows(bar, list, (r) => [offerName(r), r.note || '', ...Object.values(r.on || {}).map(String)], (r) => {
    const row = el('div', 'row nocheck wm-rule');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', offerName(r)));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('rule.since', { when: fmt.date(r.at) })));
    meta.appendChild(el('span', '', r.hits ? T('rule.hits', { n: r.hits, when: fmt.relative(r.hit) }) : T('rule.no_hits')));
    main.appendChild(meta);
    row.appendChild(main);
    const acts = el('div', 'wm-entry-acts');
    const mk = (undo) => {
      const b = el('button', 'btn small plain', T(undo ? 'rule.undo' : 'rule.lift'));
      b.type = 'button';
      b.title = T(undo ? 'rule.undo_title' : 'rule.lift_title');
      b.disabled = !Office.agent.running || !hired();
      b.onclick = () => ruleRemove(r, undo, b);
      return b;
    };
    if (justMade.has(r.id)) acts.appendChild(mk(true));
    acts.appendChild(mk(false));
    row.appendChild(acts);
    return row;
  }, rows);
  box.appendChild(rows);
  return box;
}

async function ack(e, b) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.ack`, { id: e.id });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  mood();
  renderKeeping(e.id);
  undoLine(T(e.lasts === false ? 'undo.ack_once' : 'undo.ack'), () => ackUndo([e.id]));
}

/*
 * After each answer a line at the bottom says what holds now, with «Undo» (1.96 «einfacher»): «Remembered. He no longer
 * tells it, unless it changes.» — the answer taken back at once (ack_undo: the entry open again; rule_remove with undo;
 * serious_undo then ack_undo). One at a time; it goes by itself after a while. In #sso, never body.
 */
let undoNode = null;
function undoLine(text, undo) {
  if (undoNode) undoNode.remove();
  const host = document.getElementById('sso');
  if (!host) return;
  const n = el('div', 'wm-undo');
  n.setAttribute('role', 'status');
  const b = el('button', 'btn small', T('undo.do'));
  b.type = 'button';
  b.disabled = !Office.agent.running;
  b.onclick = async () => { b.disabled = true; n.remove(); undoNode = null; await undo(); };
  n.append(el('span', '', text), b);
  host.appendChild(n);
  undoNode = n;
  setTimeout(() => { if (undoNode === n) { n.remove(); undoNode = null; } }, 15000);
}
/** «Undo» on «In order …»: each entry open again, what it made known taken out */
async function ackUndo(ids) {
  let j = null;
  for (const id of ids) {
    j = await Office.api.post(`${ID}.ack_undo`, { id });
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); break; }
  }
  if (j && j.state) state = j.state;
  mood();
  if (view) Office.keepInPlace(null, render);
  if (j && j.ok) Office.toast(T('undo.done'));
}

/**
 * «This was a real incident…»: closes the entry as a real incident (nothing of it becomes normal), with an optional
 * one-line note; it goes into his history of serious incidents and, while it is there, the same from the same source is
 * reported at once and as important.
 * The dialog says what follows and offers «I know, thanks» ($ab, the row's button) as the other answer.
 */
function seriousAck(e, b, ab) {
  const box = el('div');
  box.appendChild(el('p', 'wm-serious-what', entryName(e)));
  box.appendChild(el('p', 'role', T('serious_text')));
  const f = el('div', 'field');
  const label = el('label', '', T('serious_note'));
  const note = el('input', 'input');
  note.type = 'text';
  note.maxLength = 200;
  note.placeholder = T('serious_note_ph');
  note.autocomplete = 'off';
  note.id = 'wm-serious-note';
  label.htmlFor = note.id;
  f.append(label, note);
  box.appendChild(f);
  Office.dialog({
    title: T('serious_dialog_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('serious_confirm'), kind: 'plain danger', act: async () => {
      if (!Office.agent.running) return false;
      const j = await Office.api.post(`${ID}.ack_serious`, { id: e.id, note: note.value.trim() });
      if (!j.ok) {
        Office.toast(Office.errorText(j.error, ID), true);
        if (j.error && j.error.key === 'watch_gone') { await load(true); return true; }
        return false;
      }
      if (j.state) state = j.state;
      mood();
      renderKeeping(e.id);
      undoLine(T('undo.serious'), async () => {
        const k = await Office.api.post(`${ID}.serious_undo`, { id: e.id });
        if (!k.ok) { Office.toast(Office.errorText(k.error, ID), true); return; }
        await ackUndo([e.id]);            // «Not an incident after all» leaves it as «In order» would: open again
      });
      return true;
    } }, { text: T(e.lasts === false ? 'ack_once' : 'ack'), kind: '', act: async () => {
      if (!Office.agent.running) return false;
      await ack(e, ab);
      return true;
    } }],
  });
  if (b) b.blur();
}

/**
 * «Not serious after all…» on a row of his list of serious incidents: it leaves the list, the same is reported as usual again, and it
 * ends as «I know, thanks» would have left it — also what came back louder since from the same source
 */
async function seriousUndo(shown, b) {
  if (!(await Office.freshState(ID))) return;
  const x = ((state && state.incidents) || []).find((y) => y && y.id === shown.id);
  if (!x) { Office.toast(T('errors.watch_incident_gone'), true); renderAt('.wm-incidents'); return; }
  const box = el('div');
  box.appendChild(el('p', 'wm-serious-what', entryName(x)));
  box.appendChild(el('p', 'role', T('serious_undo_text')));
  if (x.again) box.appendChild(el('p', 'role', T('serious_undo_again', { n: x.again })));
  Office.dialog({
    title: T('serious_undo'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('serious_undo_confirm'), kind: '', act: async () => {
      if (!Office.agent.running) return false;
      const j = await Office.api.post(`${ID}.serious_undo`, { id: x.id });
      if (!j.ok) {
        Office.toast(Office.errorText(j.error, ID), true);
        if (j.error && (j.error.key === 'watch_incident_gone' || j.error.key === 'watch_gone')) { await load(true); return true; }
        return false;
      }
      if (j.state) state = j.state;
      mood();
      renderAt('.wm-incidents', '.wm-book');
      Office.toast(T('serious_undo_done'));
      return true;
    } }],
  });
  if (b) b.blur();
}

/** His serious incidents: each makes the same from the same source reported at once and as important while it is on the
 * list (no end since 2026-11-20 — they count in «How secure does it stand?») */
const louderCount = () => ((state && state.incidents) || []).filter(Boolean).length;

/** «All n: I know, thanks» on a row that folds several entries: those of it still open, in one request (ack_some) */
async function ackSome(list, b, key) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.ack_some`, { ids: list.map((e) => e.id) });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  mood();
  renderAt(`.wm-fold-head[data-fold="${CSS.escape(key)}"]`, '.wm-book', '.wm-cat');
  undoLine(T('undo.ack_all', { n: Number(j.noted) || list.length }), () => ackUndo(list.map((e) => e.id)));
}

async function ackAll() {
  if (!(await Office.freshState(ID))) return;      // «all» means what is in his book now: never a stale look at it
  const n = openCount();
  if (!n || !Office.agent.running) return;
  Office.dialog({
    title: T('ack_all'),
    body: el('p', '', T('ack_all_text', { n })),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('ack_all'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.ack_all`, {});
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
      if (j.state) state = j.state;
      mood();
      renderKeeping(null);
      Office.toast(T('acked_all', { n: Number(j.noted) || n }));
      return true;
    } }],
  });
}

/** Re-render without the page jumping: the row that was noted stays where it is (or, gone from the list, the book) */
function renderKeeping(id) {
  renderAt(id && `.wm-entry[data-id="${CSS.escape(id)}"]`, '.wm-book');
}

/** Re-render; the first of these that is there stays where it was on screen */
function renderAt(...selectors) {
  if (!view) return;
  const find = () => selectors.map((x) => x && view.querySelector(x)).find(Boolean);
  const a = find();
  const top = a ? a.getBoundingClientRect().top : null;
  Office.keepInPlace(null, render);
  const b = find();
  if (top !== null && b) {
    const d = b.getBoundingClientRect().top - top;
    if (Math.abs(d) > 1) window.scrollBy(0, d);
  }
}

// ------------------------------------------------------------------ rendering
// greet.1 «Evening.» only in the evening and the night (17:00–05:59 on the reader's clock — 2026-10-18: a bubble is true
// right now); the others are his round's, said at any hour
const greetOk = (key, hour = new Date().getHours()) => key !== 'greet.1' || hour >= 17 || hour < 6;

/** «Known vulnerabilities» off (until switched on once — agent: watchman-vuln.php): his one sentence, the page's one button */
const vulnOff = () => !!(state && state.on_watch && state.vuln && state.vuln.on === false && hired());

function bubbleText() {
  const text = bubbleBase();
  return vulnOff() && !running() ? `${text} ${T('bubble.vuln_off')}` : text;
}

function bubbleBase() {
  if (!state) return T('bubble.loading');
  if (running()) return T('bubble.touring');
  if (!state.on_watch) return T('bubble.first');
  const n = openCount();
  const tips = postureOpen() ? ' ' + T('bubble.posture', { n: postureOpen() }) : '';
  if (n) return T('bubble.open', { n }) + tips;
  if (state.round && (state.round.failed || state.round.broken)) return T('bubble.failed') + tips;
  // the syslog stopped growing: no logins seen is no «all quiet» (T30)
  if (state.round && state.round.syslog_full) return T('bubble.syslog_full', { when: fmt.relative(state.round.last) }) + tips;
  return T('bubble.quiet', { when: fmt.relative(state.round && state.round.last) }) + tips;
}

function chip(text, cls, tip) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (tip) c.title = tip;
  return c;
}

function render() {
  const root = view;
  if (!root) return;
  root.innerHTML = '';
  bookRows = [];
  const btn = el('button', 'btn plain' + (running() ? ' running' : ''));
  btn.type = 'button';
  btn.append(el('span', 'spin'), T('round_now'));
  btn.title = T('round_now_title');
  btn.disabled = !Office.agent.running || running() || !hired();
  btn.onclick = roundNow;
  const actions = [btn];
  if (vulnOff()) actions.push(vulnButton());
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions,
    pages: { list: PAGES(), current: page } });
  root.appendChild(head);
  if (page === 'setup') { renderSetup(root); return; }
  const lim = (state && state.limits) || {};
  root.appendChild(helpAreas(Office.pageHelp(ID, [
    // «How to read this page» first (1.96 «einfacher»: one screen — the tiles, your answers, where things are, notifications)
    [T('help.read'), T('help.read_text')],
    [T('help.read_lights'), T('help.read_lights_text')],
    [T('help.read_answers'), T('help.read_answers_text')],
    [T('help.read_where'), T('help.read_where_text')],
    [T('help.read_notify'), T('help.read_notify_text')],
    [T('help.read_areas'), ''],         // filled below: each area in a line (its subtitle)
    [T('help.posture'), T('help.posture_text')],
    [T('help.book'), T('help.book_text', { keep: lim.keep || 500, days: lim.days || 90 })],
    [T('help.history'), T('help.history_text')],
    [T('help.serious'), T('help.serious_text')],
    [T('help.normal'), T('help.normal_text')],
    [T('round_now'), T('help.round_text')],
    [T('help.logins'), T('help.logins_text', { burst: lim.burst || 5, minutes: Math.round((lim.window || 600) / 60) })],
    [T('help.containers'), T('help.containers_text')],
    [T('help.plugins'), T('help.plugins_text')],
    [T('help.office'), T('help.office_text')],
    [T('help.flash'), T('help.flash_text')],
    [T('help.shares'), T('help.shares_text')],
    [T('help.sched'), T('help.sched_text')],
    [T('help.flow'), T('help.flow_text', flowLimits())],
    [T('help.flow_gone'), T('help.flow_gone_text', goneLimits())],
    [T('help.flow_not'), T('help.flow_not_text')],
    [T('help.snaps'), T('help.snaps_text')],
    [T('help.host'), T('help.host_text')],
    [T('help.host_not'), T('help.host_not_text')],
    [T('help.net'), T('help.net_text')],
    [T('help.net_not'), T('help.net_not_text')],
    [T('help.attack'), T('help.attack_text')],
    [T('help.siem'), T('help.siem_text')],
    [T('help.chain'), T('help.chain_text')],
    [T('help.night'), T('help.night_text')],
    [T('help.parity'), T('help.parity_text')],
    [T('help.hold'), T('help.hold_text')],
    [T('help.wakes'), T('help.wakes_text')],
    [T('help.grafana'), T('help.grafana_text')],
    [T('help.notify'), T('help.notify_text')],
    [T('help.safe'), T('help.safe_text')],
  ])));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  root.appendChild(sw.el);
  sw.render();
  sw.el.querySelectorAll(':scope > .wm-alerts').forEach((n) => n.remove());   // a redraw keeps one of each
  const alerts = alertBox();          // what went wrong: under the row, over every section (the tiles rule: the tiles first)
  if (alerts) sw.el.insertBefore(alerts, sw.body);
}

// ------------------------------------------------------------------ the row: his 12 tiles (1.96 «einfacher»)
/*
 * His page on core's switcher (CLAUDE.md «Tiles», Office.sections; Fable's nw196-einfacher.md, Benj 2026-11-23): 12
 * tiles over his 19 areas (agent/desks/watchman-cats.php WATCH_TILES — state.tiles: a light, its reasons, what waits),
 * then «Watch book» (everything done) and «Schedule» (when he checked, whether it worked). Nothing chosen: every tile's
 * section in the row's order — a quiet one a line, one where something waits its entries. One meaning per colour. The
 * filter above the row: a tile's words, «Only what waits for you» (red and yellow). Old routes land on their tile.
 */
// his two pages (1.96 step 5): the watch and his setup — two tabs (core.js Office.pages)
const PAGES = () => [{ id: '', text: T('page.watch') }, { id: 'setup', text: T('page.setup') }];
let page = '';
const TILES = ['login', 'access', 'container', 'plugins', 'server', 'data', 'snap', 'net', 'hw', 'vuln', 'array', 'wakes'];
// the areas each tile shows (the messenger's WATCH_TILES — an older messenger's state has no `tiles`: these)
const TILE_PARTS = { login: ['login', 'partner'], access: ['door', 'share', 'flash'], container: ['container'], plugins: ['plugin', 'sched'],
  server: ['host'], data: ['pull', 'data'], snap: ['snap'], net: ['router', 'lan'], hw: ['hw'], vuln: ['vuln'], array: ['array', 'hold'], wakes: ['wakes'] };
// kinds shown on another tile than their area's (WATCH_TILE_KINDS): what runs at the start, the server's start line
const TILE_KINDS = { flash_go: 'plugins', flash_extra: 'plugins', flash_boot: 'plugins', server_boot: 'server' };
// old places (1.96's first steps had a tile per area, 1.95 its own): where they land now ('' = the whole page)
const TILE_ROUTE = { pull: 'data', router: 'net', lan: 'net', host: 'server', door: 'access', share: 'access', flash: 'access', plugin: 'plugins',
  sched: 'plugins', partner: 'login', hold: 'array', watch: 'book', open: 'book', incidents: 'book', posture: '' };
// his 19 areas (rules.json, his settings, the switches): what the tiles are made of
const CATS = ['login', 'container', 'plugin', 'flash', 'share', 'sched', 'pull', 'data', 'snap', 'host', 'door', 'partner', 'router', 'lan', 'array', 'vuln', 'hw'];
const CAT_LATER = ['vuln', 'hw'];
// what an area knows (its folds «Known: …», 1.96 — up to then one tile «What I keep an eye on» for all): the groups of
// what is normal there, by their keys (their places stay `watch:<key>`: the search's anchors)
const CAT_KNOWN = { login: ['ips', 'fail_ips', 'flow_smb'], container: ['containers'], plugin: ['plugins'], flash: ['flash'], share: ['shares'],
  sched: ['sched'], pull: ['flow_clients', 'flow_containers'], data: ['flow_shares', 'flow_gone'], snap: ['snaps'], host: ['host'], door: ['doors'],
  partner: ['partner'], router: ['net'], lan: ['net'] };
const CAT_SEEN = ['open', 'open_tell', 'tips'];        // reasons the light says by itself; the others stand in its section
/** «Only what waits for you» (the row's switch): red and yellow — nothing else (one meaning per colour, 1.96) */
const tileWaits = (level) => level === 'danger' || level === 'warn';
const catOf = (id) => (state && state.cats && state.cats[id] && typeof state.cats[id] === 'object' ? state.cats[id] : null);
const catLevel = (id) => { const c = catOf(id); return c ? String(c.level || '') : ''; };
const catName = (id) => T('cat.' + id);
const catShown = (id) => (catOf(id) ? !(catOf(id).why || []).includes('not_yet') : !CAT_LATER.includes(id));
const crossLevel = (id, fallback) => (state && state.cats_cross && state.cats_cross[id]) || fallback;
const tileOf = (id) => (state && state.tiles && state.tiles[id] && typeof state.tiles[id] === 'object' ? state.tiles[id] : null);
const tileLevel = (id) => { const t = tileOf(id); return t ? String(t.level || '') : ''; };
const tileName = (id) => T('area.' + id);
const tileParts = (id) => (tileOf(id) && Array.isArray(tileOf(id).parts) ? tileOf(id).parts : TILE_PARTS[id] || []);
const tileOfCat = (cat) => TILES.find((t) => (TILE_PARTS[t] || []).includes(cat)) || null;
/** An entry's tile: the messenger's, else by its kind and area (an older state) */
const entryTile = (e) => (e && (e.tile || TILE_KINDS[e.kind] || (e.cat ? tileOfCat(e.cat) : null))) || null;
/** A tile's entries in his book (open and done) */
const tileEntries = (id) => ((state && state.book) || []).filter((e) => e && typeof e.id === 'string' && entryTile(e) === id);

const sw = Office.sections({
  id: ID,
  grid: 'lights wm-tiles',
  default: '',                 // nothing chosen: the whole page — what waits, every tile in its order
  tiles: () => [               // a function: the words in the language the page has now
    ...TILES.map((id) => ({ id, key: `area.${id}`, place: `tile.${id}`, tile: (a) => (id === 'wakes' ? wakesTile(a) : tileLight(id, a)), section: () => tileSection(id),
      words: () => tileWords(id), level: () => tileLevel(id) })),
    { id: 'book', key: 'tile.book', place: 'tile.book', tile: (a) => bookTile(a), section: 'book', words: () => [T('tile.book'), T('book.sub')],
      level: () => (state ? 'ok' : '') },
    { ...(roundSchedule()), words: () => [T('tile.schedule'), T('round.sub')], level: () => crossLevel('schedule', '') },
  ],
  sections: { book: () => bookSection() },
  wants: (sub) => TILES.includes(sub),
  filter: { label: () => T('cat.row_filter'), only: () => ({ text: T('cat.row_only'), hint: T('cat.row_only_hint') }),
    words: (x) => (x.words ? x.words() : [x.id]), finding: (x) => tileWaits(x.level ? x.level() : '') },
});

/** A tile of his row as a light (core.js Office.light): what the number tile said becomes its mouse-over text */
function lightTile(o) {
  const subs = [].concat(o.sub ?? []).filter((x) => x !== '' && x !== null && x !== undefined);
  const head = o.value !== undefined && o.value !== '' && o.value !== '–' ? `${o.label}: ${o.value}` : String(o.label);
  const { value, sub, alert, ...rest } = o;
  return Office.light({ ...rest, tip: [head, ...subs, ...(o.more || [])].join('\n') });
}

/** A tile's light; its mouse-over text: what waits (how much of it urgent), the newest, its reasons, when he checked */
function tileLight(id, a) {
  return Office.light({ label: tileName(id), level: tileLevel(id), tip: tileTip(id).join('\n'), ...a });
}
function tileTip(id) {
  const t = tileOf(id);
  if (!t) return [tileName(id), T('cat.tip_unknown')];
  const first = t.open ? [T('cat.tip_open', { n: t.open }), t.tell ? T('cat.tip_tell', { n: t.tell }) : ''].filter(Boolean).join(', ')
    : Office.t('common.light.' + (Office.LIGHTS.includes(t.level) ? t.level : 'unknown'));
  const lines = [`${tileName(id)}: ${first}`];
  const newest = tileEntries(id).filter((e) => e.open).sort((x, y) => y.last - x.last)[0];
  if (newest) {
    const what = entryName(newest);
    lines.push(T('cat.tip_newest', { what: what.length > 110 ? what.slice(0, 109) + '…' : what, when: fmt.relative(newest.last) }));
  }
  (t.why || []).filter((w) => !CAT_SEEN.includes(w) && Office.has(`${ID}.cat.why.${w}`)).slice(0, 2).forEach((w) => lines.push(whyWords(id, w)));
  if (state && state.round && state.round.last) lines.push(T('cat.tip_checked', { when: fmt.relative(state.round.last) }));
  return lines.slice(0, 4);
}
/** The words the row's filter meets a tile by: its name, what it looks at, its areas' names, what waits there */
function tileWords(id) {
  return [tileName(id), T(`area.${id}_sub`), ...tileParts(id).map((c) => catName(c)),
    ...tileEntries(id).filter((e) => e.open).slice(0, 50).map((e) => entryHay(e))];
}
/** A reason of an area's light in words — a re-learn says since when (the data flow: how many days it still learns) */
function whyWords(id, w) {
  const r = relearnOf(id) || tileParts(id).map((c) => relearnOf(c)).find(Boolean) || null;
  if ((w === 'relearn' || w === 'relearn_flow') && r) {
    const learn = ((state && state.flow && state.flow.learn) || 7) * 86400;
    return T('cat.why.' + w, { when: fmt.date(r.at), n: Math.max(1, Math.ceil((r.at + learn - ((state && state.time) || Date.now() / 1000)) / 86400)) });
  }
  return T('cat.why.' + w);
}
/**
 * «Schedule» on the office's schedule tile (Office.scheduleTile): his rounds go by themselves every five minutes — never
 * «Off» —, the next one, the last and how it ended (went wrong, stopped after too long, on it now). Its section says there
 * is nothing to set
 */
function roundSchedule() {
  const r = (state && state.round) || {};
  const broken = r.broken ? (r.broken.why === 'stopped' ? 'stopped' : 'failed') : '';
  const last = running() ? { at: r.last || Date.now() / 1000, how: 'running' } : r.last ? { at: r.last, how: r.failed ? 'failed' : broken || 'ok' } : null;
  const entry = Office.scheduleTile(ID, { every: T('schedule.every'), next: running() ? 0 : r.next || 0, last, section: () => roundScheduleSection() });
  // as a light: the schedule tile's own words (when next, how the last ended) become its mouse-over text
  return { ...entry, tile: (a) => {
    const n = entry.tile({});
    const txt = (c) => (n && n.querySelectorAll ? [...n.querySelectorAll(c)].map((x) => x.textContent).filter(Boolean) : []);
    return lightTile({ label: T('tile.schedule'), value: txt('.stat-value')[0] || '', sub: txt('.stat-sub'),
      level: crossLevel('schedule', r.failed || broken ? 'danger' : r.last ? 'ok' : ''), ...a });
  } };
}
/**
 * «Schedule» (1.96 «einfacher»: with what «The last round» said above the row before): when he checked last, how long
 * it took, the next time, since when he watches, until when he learns; what to know about the last check; where his
 * notifications go. Nothing chosen: one line.
 */
function roundScheduleSection() {
  const s = el('section', 'section wm-round');
  if (sw.active() !== 'schedule') {
    const r = state.round || {};
    const b = el('button', 'wm-quiet-row ' + (r.failed || r.broken ? 'light-danger' : 'light-ok'));
    b.type = 'button';
    b.append(el('span', 'light-dot'), el('b', '', T('tile.schedule')), r.last ? T('round.quiet_line', { when: fmt.relative(r.last) }) : T('stat.never'));
    b.onclick = () => sw.pick('schedule', 'click');
    s.className = 'section wm-quiet';
    s.appendChild(b);
    return s;
  }
  const head = roundHead();
  s.append(...head.children);
  s.appendChild(el('p', 'role', T('schedule.fixed')));
  return s;
}

/** Over the row only when something went wrong (his check failed or broke off, /var/log full) or before his first check */
function alertBox() {
  const box = el('div', 'wm-notes wm-alerts');
  const r = (state && state.round) || {};
  if (!state.on_watch) box.appendChild(el('p', 'callout', T('round.first')));
  if (r.failed) box.appendChild(el('p', 'callout warn', T('round.failed')));
  else if (r.broken) box.appendChild(el('p', 'callout warn', T(r.broken.why === 'stopped' ? 'round.stopped' : 'round.failed')));
  if (r.syslog_full) {
    const full = el('p', 'callout warn', T('round.syslog_full', { free: fmt.size(r.syslog_full.free || 0), total: fmt.size(r.syslog_full.total || 0) }) + ' ');
    const a = el('a', '', T('round.syslog_full_link'));
    a.href = '#/logs';
    full.appendChild(a);
    box.appendChild(full);
  }
  return box.children.length ? box : null;
}

/** Anything open in the book? (what «only open» could show) */
const bookHasOpen = () => ((state && state.book) || []).some((e) => e && e.open);

/** «Watch book» (1.96 «einfacher»): everything done — how much, the incidents among it, the newest */
function bookTile(a) {
  const done = ((state && state.book) || []).filter((e) => e && typeof e.id === 'string' && !e.open);
  const newest = Math.max(0, ...done.map((e) => Number(e.last) || 0));
  const incidents = louderCount();
  return lightTile({ label: T('tile.book'), value: T('tile.book_done', { n: done.length }), level: state ? 'ok' : '',
    sub: [incidents ? T('tile.book_incidents', { n: incidents }) : '', newest ? T('tile.book_newest', { when: fmt.relative(newest) }) : ''].filter(Boolean).join(' · '),
    ...a });
}

function incidentRow(x) {
  const r = Office.place(`incident:${x.id}`, el('div', 'row nocheck wm-incident'));
  r.dataset.id = x.id;
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', entryName(x)));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('incidents.louder'), 'warn', T('incidents.louder_title')));
  meta.appendChild(chip(T('group.' + x.group), '', T('group_title.' + x.group)));
  const tech = attackLink(x.attack);
  if (tech) meta.appendChild(tech);
  if (x.again) meta.appendChild(chip(T('incidents.again', { n: x.again }), 'warn', T('incidents.again_title')));
  const when = el('span', '', fmt.date(x.last));
  when.dataset.tip = fmt.relative(x.last);
  meta.appendChild(when);
  const acked = el('span', '', T('incidents.acked', { when: fmt.relative(x.at) }));
  acked.dataset.tip = fmt.date(x.at);
  meta.appendChild(acked);
  main.appendChild(meta);
  if (x.note) main.appendChild(el('div', 'row-detail wm-incident-note', x.note));
  r.appendChild(main);
  const acts = el('div', 'wm-entry-acts');
  const b = el('button', 'btn small plain', T('serious_undo'));
  b.type = 'button';
  b.title = T('serious_undo_title');
  b.disabled = !Office.agent.running;
  b.onclick = () => seriousUndo(x, b);
  acts.appendChild(b);
  r.appendChild(acts);
  return r;
}

/** The last round got no answer from Docker: what he shows of containers is the last round that reached it (W7) */
const dockerOff = () => !!(state && state.on_watch && state.round && state.round.docker === false);

// ------------------------------------------------------------------ the last round: the row's head
/** A day as «08.10.» (its full date as the tip) */
function shortDay(t) {
  const x = el('span', '', new Date(t * 1000).toLocaleDateString(Office.locale || undefined, { day: '2-digit', month: '2-digit' }));
  x.title = fmt.date(t);
  return x;
}

/**
 * «The last round» over the row (a hint, not a section — it stays at every click): one line — when (how long it took),
 * the next one, on watch since, learning until — and his two switches on its right; what to know about the round under it
 */
function roundHead() {
  const s = el('div', 'wm-round');
  const r = state.round || {};
  const head = Office.sectionHead(T('tile.schedule'), T('round.sub'), running() ? el('span', 'hint', T('stat.running')) : null, notifySwitch(),
    { place: 'round.title' });
  const line = el('div', 'section-sub wm-round-line');
  line.title = T('round.sub');
  const parts = [];
  const last = el('span', '', r.last ? (r.duration_ms ? T('round.last_ms', { when: fmt.relative(r.last), ms: r.duration_ms }) : fmt.relative(r.last)) : T('stat.never'));
  if (r.last) last.title = fmt.date(r.last);
  parts.push(last);
  if (!running() && r.next) {
    const next = el('span', '', T('round.next', { when: fmt.relative(r.next) }));
    next.title = fmt.date(r.next);
    parts.push(next);
  }
  if (state.on_watch) {
    const since = el('span', '', T('round.since') + ' ');
    since.appendChild(shortDay(state.on_watch));
    parts.push(since);
    // still learning what is normal for the data flow? and until when (2026-10-10)
    const f = state.flow;
    if (f && f.since && f.learn) {
      const end = f.since + f.learn * 86400;
      const learn = el('span', '', end > Date.now() / 1000 ? T('round.learning') + ' ' : T('round.learned'));
      if (end > Date.now() / 1000) learn.appendChild(shortDay(end));
      parts.push(learn);
    }
  }
  parts.forEach((x, i) => { if (i) line.append(' · '); line.appendChild(x); });
  head.appendChild(line);
  s.appendChild(head);
  const notes = el('div', 'wm-notes');
  if (r.skipped) notes.appendChild(el('p', 'role', T('round.skipped', { size: fmt.size(r.skipped) })));
  if (state.on_watch && r.docker === false) notes.appendChild(el('p', 'role', T('round.no_docker')));
  if (state.on_watch && r.shares === false) notes.appendChild(el('p', 'role', T('round.no_shares')));
  const told = state.notified;
  if (told && told.time) notes.appendChild(el('p', 'role', T('round.notified', { when: fmt.relative(told.time), n: (told.items || []).length })));
  if (state.notify && state.notify.available === false) notes.appendChild(el('p', 'role', T('notify_missing')));
  const night = state.night;
  if (night && night.until) {
    notes.appendChild(el('p', 'role', T('round.night', { from: fmt.date(night.since), until: fmt.date(night.until) })
      + (night.from === 'flash' ? ' ' + T('round.night_flash') : '')));
  }
  if (notes.children.length) s.appendChild(notes);
  return s;
}

/**
 * Reports to Unraid's notifications: since 1.68 the Team Lead's notification centre decides how much and when (his
 * «Reports» tile) — here only the way there
 */
function notifySwitch() {
  const a = el('a', 'btn small plain', Office.t('notifycenter.where_link'));
  a.href = '#/caretaker/notify';
  a.title = Office.t('notifycenter.where');
  return a;
}

/** For a SIEM: also write each new entry to Unraid's syslog (its remote syslog sends it on) — off by default */
function syslogSwitch() {
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = !!(state.syslog && state.syslog.on);
  cb.disabled = !Office.agent.running || !hired();
  label.append(cb, el('span', '', T('syslog_switch')));
  label.title = T('syslog_title');
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.syslog_set`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    if (view) Office.keepInPlace(null, render);
    Office.toast(T(on ? 'syslog_on' : 'syslog_off'));
  };
  return label;
}

// ------------------------------------------------------------------ how secure it stands
/** A tip's words: names and numbers from the agent, the CPU's flaws by the names people know */
function postureParams(x) {
  const p = { ...(x.p || {}) };
  if (Array.isArray(p.open)) p.open = p.open.map((v) => VULN[v] || v).join(', ') || '–';
  if (x.id === 'flash') p.access = T('level.' + (p.level === 'public' ? 'public' : 'secure'));
  return p;
}

function postureRow(x) {
  const r = el('div', 'row nocheck wm-tip' + (x.known ? ' wm-tip-known' : ''));
  r.dataset.id = x.id;      // not data-tip: that is the office's tooltip hook (it swallowed the row's clicks up to 1.36.0)
  const main = el('div', 'row-main');
  const params = postureParams(x);
  main.appendChild(el('div', 'row-name text', T(`posture.${x.id}.title`, params)));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T(`posture.level_${x.level}`), x.level === 'advice' ? 'accent' : 'quiet', T(`posture.level_${x.level}_text`)));
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T(`posture.${x.id}.why`, params)));
  // privileged containers that are stopped and start by nothing: not part of the tip, a quiet line in it (inbox #10)
  if (x.id === 'privileged' && params.quiet) main.appendChild(el('div', 'row-detail role', T('posture.privileged.quiet', { quiet: params.quiet, n: Number(params.quiet_n) || 1 })));
  // what your exceptions call intended (1.96): named, no advice (lifted in his setup)
  if ((x.id === 'privileged' || x.id === 'public') && params.intended) main.appendChild(el('div', 'row-detail role', T('posture.intended', { names: params.intended })));
  if (x.id === 'privileged' && dockerOff()) main.appendChild(el('div', 'row-detail role', T('posture.as_last_seen', { docker: Office.t('common.docker_off') })));
  r.appendChild(main);
  const acts = el('div', 'wm-tip-acts');
  const href = x.link && Office.safeHref(x.link.path);
  if (href && Office.has(`${ID}.posture.to_${x.link.to}`)) {
    const a = el('a', 'btn small plain', T(`posture.to_${x.link.to}`, { name: x.link.name || '' }));      // into Unraid, in the same tab like its own links
    a.href = href;
    acts.appendChild(a);
  }
  const b = el('button', 'btn small plain', T(x.known ? 'posture.show_again' : 'posture.ack'));      // a hint: «I know, thanks» (only here, 1.96 «einfacher»)
  b.type = 'button';
  b.title = T(x.known ? 'posture.show_again_title' : 'posture.ack_title');
  b.disabled = !Office.agent.running || !hired();
  b.onclick = () => postureAck(x, !x.known, b);
  acts.appendChild(b);
  r.appendChild(acts);
  return r;
}

async function postureAck(x, on, b) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.posture_ack`, { id: x.id, on });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_tip_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  renderAt('.wm-posture', '.wm-cat-tips');
  Office.toast(T(on ? 'posture.acked' : 'posture.unacked'));
}

// ------------------------------------------------------------------ the watch book
/** The area an entry is filtered by: its group; a router's log too long for a round counts to the network */
const entryArea = (e) => (e.kind === 'watch' && e.p && e.p.too_much ? 'net' : e.group || 'watch');
/** What folds with what (beside the area and the day): the same kind — a router's log too long is a kind of its own */
const foldKind = (e) => (e.kind === 'watch' && e.p && e.p.too_much ? 'net_too_much' : e.kind);
/** Words as the filter compares them: lower case, without accents, ß as ss — core's Office.fold, as the bar's own */
const plainWords = (s) => Office.fold(s);

/**
 * The watch book as his page shows it — no DOM, nothing of the page's state (tests/watchbook.js runs it under node).
 * book: the entries newest first, as his state has them. o: {onlyOpen, area ('' = every area), words (plainWords, [] =
 * none), hay(e) → the entry's words (plainWords), day(seconds) → 'YYYY-MM-DD', chains ([{ids}]), shown, page, wanted (an
 * entry's id or null)}. Entries of the same kind and area on the same day (of their last time) fold into one row.
 * Returns {
 *   areas: [[area, n], …] — the entries per area that «only open» leaves (the words and the area itself not counted),
 *   rows: every row the switches and words leave: {key, day, kind, area, members (newest first — one member: the entry
 *     itself), total (the members without the words), open (members not noted), last, night, chain},
 *   items: what to draw — {day} before the first row of each day, then the rows, the first `shown` of them,
 *   shown: rows drawn (more when the entry asked for lies further down),
 *   wanted: {row, key, entry} where the entry asked for is, or null; hidden: it is in the book but the switches hide it }
 */
function bookView(book, o) {
  const list = (book || []).filter((e) => e && typeof e.id === 'string');
  const page = o.page || PAGE;
  const chained = new Set();
  (o.chains || []).forEach((c) => (c.ids || []).forEach((id) => chained.add(id)));
  const counts = new Map();
  list.forEach((e) => { if (!o.onlyOpen || e.open) counts.set(entryArea(e), (counts.get(entryArea(e)) || 0) + 1); });
  const areas = [...counts.entries()];
  const base = list.filter((e) => (!o.onlyOpen || e.open) && (!o.area || entryArea(e) === o.area));
  const keyOf = (e) => `${o.day(e.last)}|${entryArea(e)}|${foldKind(e)}`;
  const totals = new Map();
  base.forEach((e) => totals.set(keyOf(e), (totals.get(keyOf(e)) || 0) + 1));
  const words = o.words || [];
  const hits = words.length ? base.filter((e) => { const h = o.hay(e); return words.every((w) => h.includes(w)); }) : base;
  const byKey = new Map();
  const rows = [];
  hits.forEach((e) => {
    const key = keyOf(e);
    let r = byKey.get(key);
    if (!r) {
      r = { key, day: o.day(e.last), kind: foldKind(e), area: entryArea(e), members: [], total: totals.get(key), open: 0, last: e.last, night: false, chain: false };
      byKey.set(key, r);
      rows.push(r);
    }
    r.members.push(e);
    if (e.open) r.open++;
    if (e.last > r.last) r.last = e.last;
    if (e.night) r.night = true;
    if (chained.has(e.id)) r.chain = true;
  });
  let shown = Math.max(0, o.shown || page);
  let wanted = null;
  let hidden = false;
  if (o.wanted) {
    const at = rows.findIndex((r) => r.members.some((e) => e.id === o.wanted));
    if (at >= 0) {
      const r = rows[at];
      wanted = { row: at, key: r.key, entry: o.wanted };
      shown = Math.max(shown, Math.ceil((at + 1) / page) * page);
    } else hidden = list.some((e) => e.id === o.wanted);
  }
  const items = [];
  let day = null;
  rows.slice(0, shown).forEach((r) => {
    if (r.day !== day) { day = r.day; items.push({ day }); }
    items.push(r);
  });
  return { areas, rows, items, shown, wanted, hidden };
}

/** An entry's words for the filter: its text, its area and kind, what it names (addresses, MACs, names …) */
function entryHay(e) {
  const out = [entryName(e), areaName(entryArea(e)), e.kind, foldKind(e)];
  const add = (v, depth) => {
    if (v === null || v === undefined || depth > 3) return;
    if (typeof v === 'string' || typeof v === 'number') out.push(String(v));
    else if (Array.isArray(v)) v.forEach((x) => add(x, depth + 1));
    else if (typeof v === 'object') Object.values(v).forEach((x) => add(x, depth + 1));
  };
  add(e.p, 0);
  add(e.t, 0);
  return plainWords(out.join(' \u0001 '));
}

/** An area's name for the select (the take-over lines have no group chip: «His watch») */
const areaName = (a) => (a === 'watch' ? T('book.area_watch') : T('group.' + a));

/** The text of a row that folds several entries: «8 devices never seen on the LAN» — or «{n} × <area>» for a kind without one */
function foldName(r) {
  return Office.has(`${ID}.fold.${r.kind}`) ? T('fold.' + r.kind, { n: r.members.length })
    : T('fold.any', { n: r.members.length, area: areaName(r.area) });
}

/**
 * The book's bar (core.js Office.filterBar), built once and held across the renders: the words (entryHay), the area
 * (counts per area, kept per browser — today's 'watchman.area' read once into the bar's key), «Unfold all»; all on the
 * whole book. «n of m fit» stands in the bar; while the tile «Not noted yet» filters, the note line says it.
 */
function bookBarGet() {
  if (bookBar) return bookBar;
  const old = Office.store('watchman.area');
  if (old && !Office.store('watchman.book.pick')) Office.store('watchman.book.pick', old);
  if (old) Office.store('watchman.area', null);
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  unfold.onclick = () => {
    const open = bookRows.some((x) => !x.open());
    Office.keepInPlace(unfold, () => {
      bookRows.filter((x) => x.fold).forEach((x) => x.set(open));       // the folded rows first: their entries come or go
      bookRows.filter((x) => !x.fold).forEach((x) => x.set(open));
    });
    bookLabel();
  };
  bookLabel = () => {
    unfold.textContent = bookRows.some((x) => !x.open()) ? T('unfold_all') : T('fold_all');
    unfold.hidden = bookRows.length < 2;
  };
  bookBar = Office.filterBar({
    key: 'watchman.book',
    min: WM_FILTER_MIN,
    label: T('book.filter_label'),
    select: { label: T('book.area_label'), all: T('book.area_all') },
    only: { text: T('book.only_incidents'), hint: T('book.only_incidents_title') },     // the incidents (their own tile up to 1.96 «einfacher»)
    extra: [unfold],
    onChange: (f, what) => {
      if (what === 'words' || what === 'all') foldsShut.clear();
      if (what !== 'gone') shown = PAGE;
      if (view) sw.render();
    },
  });
  return bookBar;
}

/** The book's own switch, also set from elsewhere (#/watchman/incidents, a tile's «Done here») */
const bookOnly = { incidents: false };
/**
 * «Watch book» (1.96 «einfacher»): everything done, the newest first — what still waits stands in its tile. Its area is a
 * tile; «Only incidents» shows the incidents (with «Not an incident after all…»); his history (by month) below it.
 */
function bookSection() {
  const s = el('section', 'section');
  s.id = 'wm-book';
  s.appendChild(Office.sectionHead(T('book.title'), T('book.sub'), { place: 'book.title' }));
  const bar = bookBarGet();
  if (bookOnly.incidents) { bar.set({ only: true }); bookOnly.incidents = false; }
  s.appendChild(bar.el);
  if (sw.active() !== 'book') {
    // the whole page: the book in a line — a click shows it
    s.replaceChildren(quietBook());
    return s;
  }
  // what the book can't hold is said, never dropped quietly (concept 3.4, cases 6 and the answers' list)
  const keep = Number(state.limits && state.limits.keep) || 500;
  if (((state.book || []).length) >= keep) s.appendChild(el('p', 'callout warn', T('book.full', { n: keep })));
  if (state.known_full) s.appendChild(el('p', 'callout warn', T('known.full')));
  const box = el('div', 'box wm-book');
  const done = (state.book || []).filter((e) => e && !e.open);
  if (bar.read().only) {
    // the incidents: each with its note and «Not an incident after all…»
    const f = bar.read();
    const list = (state.incidents || []).filter((x) => x && typeof x.id === 'string' && (!f.pick || entryTile(x) === f.pick)
      && (!f.words.length || f.words.every((w) => entryHay(x).includes(w) || plainWords(x.note || '').includes(w))));
    list.forEach((x) => box.appendChild(incidentRow(x)));
    if (!list.length) box.appendChild(el('p', 'empty', T('incidents.none')));
    s.appendChild(box);
    bar.done(list.length, (state.incidents || []).length, { onlyAvail: true });
    return s;
  }
  const look = (asked) => {
    const f = bar.read();
    return bookView(done, {
      onlyOpen: false, area: f.pick, words: f.words, hay: entryHay, day: fmt.dayKey,
      chains: state.chains, shown, page: PAGE, wanted: asked,
    });
  };
  const fillAreas = (v) => {
    const pick = bar.read().pick;
    const list = v.areas.map(([a, n]) => [a, n, areaName(a)]);
    if (pick && !list.some(([a]) => a === pick)) list.push([pick, 0, areaName(pick)]);     // chosen, none left now: it stays
    list.sort((x, y) => x[2].localeCompare(y[2], Office.locale));
    bar.options(list.map(([a, n, name]) => [a, T('book.area_n', { area: name, n })]));
  };
  const fill = () => {
    box.innerHTML = '';
    bookRows = [];
    let v = look(wanted);
    if (v.hidden) {          // the entry asked for (the search, a link) hides behind the words or the area: the whole book
      bar.set({ text: '', pick: '' });
      v = look(wanted);
    }
    if (v.wanted) {
      shown = v.shown;
      if (v.wanted.key) { foldsOpen.add(v.wanted.key); foldsShut.delete(v.wanted.key); }
      unfolded.add(v.wanted.entry);
      wanted = null;
    }
    fillAreas(v);
    const f = bar.read();
    v.items.forEach((x) => box.appendChild(x.members ? (x.members.length > 1 ? foldRow(x) : entryRow(x.members[0])) : dayHead(x.day)));
    if (!v.rows.length) box.appendChild(el('p', 'empty', f.words.length || f.pick ? T('book.empty_filter') : T('book.empty')));
    if (v.rows.length > v.shown) {
      const more = el('button', 'btn small plain wm-more', T('book.more', { n: Math.min(PAGE, v.rows.length - v.shown) }));
      more.type = 'button';
      more.onclick = () => { shown += PAGE; Office.keepInPlace(null, fill); };
      box.appendChild(more);
    }
    bookLabel();
    bar.done(v.rows.reduce((a, r) => a + r.members.length, 0), done.length, { onlyAvail: (state.incidents || []).length > 0 });
  };
  fill();
  s.appendChild(box);
  const hist = journalBox();
  if (hist) s.appendChild(hist);
  return s;
}
/** The book on the whole page: one line — how much is done, the incidents among it; a click shows it */
function quietBook() {
  const done = (state.book || []).filter((e) => e && !e.open).length;
  const b = el('button', 'wm-quiet-row light-ok');
  b.type = 'button';
  b.append(el('span', 'light-dot'), el('b', '', T('tile.book')), [T('tile.book_done', { n: done }), louderCount() ? T('tile.book_incidents', { n: louderCount() }) : ''].filter(Boolean).join(' · '));
  b.onclick = () => sw.pick('book', 'click');
  const s = el('div', 'wm-quiet');
  s.appendChild(b);
  return s;
}

// ------------------------------------------------------------------ his history (1.96 step 7, agent/desks/watchman-journal.php)
/*
 * What he noted himself (or an exception did) leaves his book after a quiet week into his history, by month: folded under
 * the book, loaded only when unfolded (the action `journal`), a hundred lines at a time; a month that was full says how many
 * lines it couldn't take. Read only.
 */
const journal = { open: false, data: null, loading: false };
const NOTED_BY = ['teamlead', 'baseline', 'auto', 'office', 'office_partner', 'office_drill', 'office_cron', 'schedule', 'array', 'unraid', 'plugin', 'back',
  'router', 'parity', 'rule', 'known', 'relearn'];
function journalBox() {
  const j = state.journal || {};
  if (!j.has && !Number(j.cut)) return null;
  const wrap = el('div', 'wm-journal');
  const head = el('button', 'btn small plain wm-known-head', `${journal.open ? '▾' : '▸'} ${T('journal.title')}`);
  head.type = 'button';
  head.title = T('journal.title_title');
  head.setAttribute('aria-expanded', journal.open ? 'true' : 'false');
  head.onclick = () => {
    journal.open = !journal.open;
    if (journal.open && !journal.data) journalLoad('', 0, head);
    else Office.keepInPlace(head, () => sw.render());
  };
  wrap.appendChild(head);
  if (!journal.open) return wrap;
  const d = journal.data;
  if (!d) { wrap.appendChild(el('span', 'spin')); return wrap; }
  if (!d.months.length) { wrap.appendChild(el('p', 'empty', T('journal.none'))); return wrap; }
  const pick = el('select', 'picker');
  d.months.forEach((m) => pick.appendChild(new Option(T('journal.month', { month: m.month, n: m.lines }), m.month)));
  pick.value = d.month || '';
  pick.setAttribute('aria-label', T('journal.month_label'));
  pick.onchange = () => journalLoad(pick.value, 0, pick);
  wrap.appendChild(pick);
  const cur = d.months.find((m) => m.month === d.month);
  if (cur && cur.cut) wrap.appendChild(el('p', 'callout warn', T('journal.cut', { n: cur.cut })));
  const bar = listBar('journal', T('journal.filter_label'));      // over the lines loaded so far («Show more» loads more)
  wrap.appendChild(bar.el);
  const rows = el('div', 'box');
  barRows(bar, d.entries, (e) => entryHay(e), (e) => {
    const r = el('div', 'row nocheck wm-journal-row');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', entryName(e)));
    const by = NOTED_BY.includes(e.by) ? e.by : 'page';
    main.appendChild(el('div', 'row-meta', `${fmt.date(e.last)} · ${T('noted.' + by, { when: fmt.date(e.noted || e.last), at: fmt.date(e.noted || e.last) })}`));
    r.appendChild(main);
    return r;
  }, rows);
  if (!d.entries.length) rows.appendChild(el('p', 'empty', T('journal.none')));
  wrap.appendChild(rows);
  if (d.more) {
    const more = el('button', 'btn small plain', T('journal.more'));
    more.type = 'button';
    more.onclick = () => journalLoad(d.month || '', d.offset + d.entries.length, more, true);
    wrap.appendChild(more);
  }
  return wrap;
}
async function journalLoad(month, offset, b, append = false) {
  if (journal.loading) return;
  journal.loading = true;
  if (b) b.disabled = true;
  const j = await Office.api.post(`${ID}.journal`, { month, offset });
  journal.loading = false;
  if (b) b.disabled = false;
  if (!j.ok || !j.journal) { Office.toast(Office.errorText(j.error, ID), true); return; }
  const was = append && journal.data ? journal.data.entries : [];
  journal.data = { ...j.journal, entries: was.concat(j.journal.entries || []), offset: append ? 0 : j.journal.offset };
  Office.keepInPlace(b, () => sw.render());
}

// ------------------------------------------------------------------ an area's section (1.96)
/*
 * One area of his (agent/desks/watchman-cats.php): what he looks at there, what he couldn't see in his last round (a
 * callout each — state.cats[id].why), its entries of the book in the book's own rows (open first is the book's order by
 * time; folded by day and kind, «I know, thanks» as there) under a bar of its own (words, «only open», the kind), and its
 * folds «Known: …» (what is normal there, found by the same words). An older messenger's state has no areas: one line says so.
 */
const catBars = {};             // each area's bar, built once and held across the renders (its words never kept)
function catBarGet(id) {
  catBars[id] ??= Office.filterBar({
    label: T('cat.filter_label'),
    min: WM_FILTER_MIN,
    select: { label: T('cat.kind_label'), all: T('cat.kind_all') },      // no key: the kind is never kept
    onChange: () => { if (view) sw.render(); },
  });
  return catBars[id];
}
const CAT_CALLOUT_WARN = ['syslog_full', 'snaps_failed', 'snaps_capped', 'router_asleep', 'router_unreadable', 'router_unknown', 'parity_todo',
  'parity_again', 'parity_errors'];

/**
 * A tile's section (1.96 «einfacher»: each thing in one place): what waits for your answer first, then his advice there,
 * what holds the array (the «Array» tile), what he knows («Known: …», folded) and two lines that jump: what is always in
 * order and what you had him remember (his setup), what is done (the watch book). Nothing chosen and nothing waiting:
 * one line. «Who woke my disks?» keeps its own section.
 */
function tileSection(id) {
  const t = tileOf(id);
  const open = tileEntries(id).filter((e) => e.open);
  if (sw.active() !== id && !open.length && !tileWaits(tileLevel(id))) return quietLine(id);
  if (id === 'wakes') {
    const w = wakesView();
    // the help's «what he checks» only with something to explain: before his first look, or with no spinning disk, one line
    if (sw.active() === id && state && state.wakes && !wakesNoHdd()) {
      const j = el('div', 'wm-jumps');
      j.appendChild(scopeLink(id));
      w.appendChild(j);
    }
    return w;
  }
  const s = el('section', 'section wm-cat');
  const said = tileState(id);
  s.appendChild(Office.sectionHead(tileName(id), T(`area.${id}_sub`), ...(said ? [said] : []), { place: `area.${id}` }));
  if (!t) {
    s.appendChild(el('p', 'role', T('cat.unknown')));
    return s;
  }
  (t.why || []).filter((w) => !CAT_SEEN.includes(w) && Office.has(`${ID}.cat.why.${w}`)).forEach((w) => {
    if (w === 'vuln_off' && vulnOff()) { s.appendChild(vulnOffBox()); return; }      // what he would check, and the switch right there
    s.appendChild(el('p', 'callout' + (CAT_CALLOUT_WARN.includes(w) ? ' warn' : ''), whyWords(id, w)));
  });
  tileChains(id).forEach((c) => s.appendChild(chainCallout(c)));
  if (t.off) {
    const on = el('button', 'btn small', T('rule.cat_on'));
    on.type = 'button';
    on.disabled = !Office.agent.running || !hired();
    on.onclick = () => tileOn(id, on);
    s.appendChild(on);
  }
  // what waits for your answer (only that: the done is the watch book's); «Known vulnerabilities»: its calm lists too
  const bar = catBarGet(id);
  const calm = id === 'vuln' ? vulnListCount() : 0;
  let openShown = 0;
  if (calm && !open.length) {
    s.appendChild(bar.el);
    bar.options([]);
  }
  if (open.length) {
    s.appendChild(bar.el);
    const f = bar.read();
    const kinds = new Map();
    open.forEach((e) => kinds.set(e.kind, (kinds.get(e.kind) || 0) + 1));
    bar.options([...kinds.entries()].map(([k, n]) => [k, Office.has(`${ID}.fold.${k}`) ? T('fold.' + k, { n }) : k]));
    const list = open.filter((e) => !f.pick || e.kind === f.pick);
    const box = el('div', 'box wm-book wm-cat-book');
    const v = bookView(list, { onlyOpen: false, area: '', words: f.words, hay: entryHay, day: fmt.dayKey, chains: [], shown: 1000, page: 1000, wanted: null });
    v.items.forEach((x) => box.appendChild(x.members ? (x.members.length > 1 ? foldRow(x, f.words.length > 0) : entryRow(x.members[0])) : dayHead(x.day)));
    if (!v.rows.length) box.appendChild(el('p', 'empty', T('cat.empty_filter')));
    s.appendChild(box);
    openShown = v.rows.reduce((n, r) => n + r.members.length, 0);
    if (!calm) bar.done(openShown, open.length);
  } else s.appendChild(el('p', 'role wm-quiet-line', quietWords(id)));
  const advice = tileAdvice(id);
  if (advice) s.appendChild(advice);
  if (id === 'array') s.appendChild(holdBlock());
  const extra = id === 'vuln' ? vulnLists(open.length || calm ? bar.read().words : []) : null;
  if (extra) s.appendChild(extra.el);
  if (calm) {
    bar.done(openShown + (extra ? extra.shown : 0), open.length + calm);
    const pick = bar.el.querySelector('.filter-pick');
    if (pick && !open.length) pick.hidden = true;           // the kinds are those of what waits: none, no choice
  }
  const inv = knownFolds(id, open.length ? bar.read().words : []);       // what he knows here: the tile's filter words find in it too
  if (inv) s.appendChild(inv);
  const jumps = jumpLines(id);
  if (jumps) s.appendChild(jumps);
  return s;
}

// ------------------------------------------------------------------ «What he checks here» (1.96 «einfacher»)
// Per tile a fold, closed at first: what he looks at, when he speaks up, how often, an example (keys scope.<tile>.<part>_<i>,
// as many as the language file has). The numbers are the settings in effect, so the words never tell an old default.
const SCOPE_PARTS = ['look', 'tell', 'when', 'example'];
// the folds as the user left them while this page lives (the convention: choices, not stored) — keepFold()
const folds = new Map();
/** A <details> keeps what the user chose across the page's redraws; its default counts only until it is touched */
function keepFold(det, key, open) {
  det.open = folds.has(key) ? folds.get(key) : open;
  let shown = det.open;
  det.addEventListener('toggle', () => {
    if (det.open === shown) return;          // the page setting it (toggle comes later, for the open it was built with)
    shown = det.open;
    folds.set(key, det.open);
  });
  return det;
}
/** A setting in effect (the page state: {value, def …}; an older state: the bare value), else the default given */
function setting(cat, key, def) {
  const x = state && state.settings && state.settings[cat] ? state.settings[cat][key] : undefined;
  const v = x && typeof x === 'object' ? x.value : x;
  return typeof v === 'number' && Number.isFinite(v) ? v : def;
}
/** A time span in its largest whole unit: «10 min», «6 h», «45 s» */
const scopeSpan = (sec) => (sec >= 3600 && sec % 3600 === 0 ? T('scope.unit_h', { n: sec / 3600 })
  : sec >= 60 && sec % 60 === 0 ? T('scope.unit_m', { n: sec / 60 }) : T('scope.unit_s', { n: sec }));
function scopeValues() {
  const gb = (n) => fmt.size(n * 1024 ** 3);
  return {
    burst: setting('login', 'burst', 5), window: scopeSpan(setting('login', 'window', 600)),
    p_burst: setting('partner', 'burst', 3), p_window: scopeSpan(setting('partner', 'window', 60)),
    factor: setting('pull', 'factor', 4), min: gb(setting('pull', 'min', 2)), new: gb(setting('pull', 'new', 50)),
    learn: (state && state.flow && Number(state.flow.learn)) || 7,
    d_factor: setting('data', 'factor', 4), written_min: gb(setting('data', 'written_min', 2)), gone_min: gb(setting('data', 'gone_min', 2)),
    files_min: fmt.number(setting('data', 'files_min', 2000)),
    offsite: setting('snap', 'offsite', 50), silent: scopeSpan(setting('router', 'silent', 6) * 3600),
    nvme_c: setting('hw', 'hot_nvme_c', 70), nvme_time: scopeSpan(setting('hw', 'hot_nvme_min', 120) * 60),
    disk_c: setting('hw', 'hot_disk_c', 55), disk_time: scopeSpan(setting('hw', 'hot_disk_min', 120) * 60),
    cpu_time: scopeSpan(setting('hw', 'hot_cpu_min', 30) * 60), ecc_day: setting('hw', 'ecc_day', 10), worn: setting('hw', 'worn', 90),
    crashes: setting('host', 'crashes', 3),
  };
}
const SCOPE_N = { 'plugins.tell_3': () => setting('sched', 'at_late', 2) };      // a line counted by a setting (its plural)
function scopeItems(id, part, values) {
  const out = [];
  for (let i = 1; Office.has(`${ID}.scope.${id}.${part}_${i}`); i++) {
    const k = `${id}.${part}_${i}`;
    out.push(T(`scope.${k}`, SCOPE_N[k] ? { ...values, n: SCOPE_N[k]() } : values));
  }
  return out;
}
/** The fold «What he checks here» of a tile (null: no words for it) */
function scopeBox(id) {
  if (!Office.has(`${ID}.scope.${id}.look_1`)) return null;
  const det = keepFold(el('details', 'wm-scope'), `scope:${id}`, false);
  const sum = el('summary');
  sum.append(el('b', '', T('scope.title')), el('span', 'wm-scope-sub', T('scope.sub')));
  det.appendChild(sum);
  const body = el('div', 'wm-scope-body');
  const values = scopeValues();
  SCOPE_PARTS.forEach((part) => {
    const items = scopeItems(id, part, values);
    if (!items.length) return;
    body.appendChild(el('h4', 'wm-scope-head', T(`scope.${part}`)));
    if (part === 'tell') body.appendChild(el('p', 'wm-note', T('scope.words')));
    const ul = el('ul', 'wm-scope-list' + (part === 'example' ? ' wm-scope-ex' : ''));
    items.forEach((x) => ul.appendChild(el('li', '', x)));
    body.appendChild(ul);
  });
  det.appendChild(body);
  return det;
}
/** The help's «The areas»: each tile in a line (its subtitle), then the watch book and the schedule */
function helpAreas(help) {
  const dt = [...help.querySelectorAll('dt')].find((x) => x.textContent === T('help.read_areas'));
  const kids = dt && dt.parentNode ? [...dt.parentNode.children] : [];
  const dd = dt ? kids[kids.indexOf(dt) + 1] : null;
  if (!dd) return help;
  const ul = el('ul', 'wm-help-areas');
  TILES.forEach((id) => {
    const li = el('li');
    li.append(el('b', '', tileName(id)), el('span', '', ` — ${T(`area.${id}_sub`)}`));
    const fold = scopeBox(id);
    if (fold) li.appendChild(Office.place(`scope.${id}`, fold));
    if (id === 'vuln') {
      vulnDownLines().forEach((x) => li.appendChild(x));      // a source away for days: said here too
      li.appendChild(el('p', 'wm-vuln-nvd-note', T('vuln.nvd_notice')));     // NVD's terms of use
    }      // the search's place (scope.<tile>.help) and the area's link land here
    ul.appendChild(li);
  });
  dd.append(ul, el('p', '', T('help.read_areas_more')));
  return help;
}

/** A tile's light in words (its head; a phone has no mouse): what waits, else how it stands, when he checked */
function tileState(id) {
  const t = tileOf(id);
  if (!t) return null;
  const lines = tileTip(id);
  const first = String(lines[0] || '').slice(tileName(id).length + 2);
  const looked = state && state.round && state.round.last ? T('cat.tip_checked', { when: fmt.relative(state.round.last) }) : '';
  const span = el('span', 'wm-cat-state wm-cat-' + (Office.LIGHTS.includes(t.level) ? t.level : 'unknown'), [first, looked].filter(Boolean).join(' · '));
  span.title = lines.join('\n');
  return span;
}

/** A quiet tile in a line — grey: why he doesn't look; else «all quiet», his advice if any, when he checked */
function quietWords(id) {
  const t = tileOf(id);
  const when = state && state.round && state.round.last ? fmt.relative(state.round.last) : '';
  if (!t) return T('cat.tip_unknown');
  if (t.level === 'none') {
    const w = (t.why || []).find((x) => Office.has(`${ID}.cat.why.${x}`));
    return w ? whyWords(id, w) : T('area.not_looked');
  }
  const tips = Number(t.tips) || 0;
  return [when ? T('cat.empty', { when }) : T('area.quiet'), tips ? T('area.tips_n', { n: tips }) : ''].filter(Boolean).join(' ');
}
/** The whole page (nothing chosen): a quiet tile is one line — a click shows it alone */
function quietLine(id) {
  const s = el('section', 'section wm-quiet');
  const lv = Office.LIGHTS.includes(tileLevel(id)) ? tileLevel(id) : 'unknown';
  const b = el('button', 'wm-quiet-row light-' + lv);
  b.type = 'button';
  b.append(el('span', 'light-dot', lv === 'note' ? '!' : ''), el('b', '', tileName(id)), el('span', 'wm-quiet-words', quietWords(id)));
  b.onclick = () => sw.pick(id, 'click');
  s.appendChild(b);
  return s;
}

/**
 * The two lines that jump from a tile (each thing in one place): «Here: 2× always in order · 14 remembered → setup»,
 * «Done here → watch book»
 */
function jumpLines(id) {
  const rules = ((state && state.rules) || []).filter((r) => r && (r.tile || tileOfCat(r.cat)) === id).length;
  const known = ((state && state.known) || []).filter((k) => k && (k.tile || tileOfCat(k.cat)) === id).length;
  const done = tileEntries(id).filter((e) => !e.open).length;
  const told = Office.has(`${ID}.scope.${id}.look_1`);
  if (!rules && !known && !done && !told) return null;
  const box = el('div', 'wm-jumps');
  if (rules || known) {
    const a = el('a', 'wm-jump', [rules ? T('jump.rules', { n: rules }) : '', known ? T('jump.known', { n: known }) : ''].filter(Boolean).join(' · ') + ' → ' + T('page.setup'));
    a.href = `#/${ID}/setup/${id}`;
    box.appendChild(a);
  }
  if (done) {
    const b = el('button', 'btn small plain wm-jump', T('jump.done', { n: done }));
    b.type = 'button';
    b.onclick = () => { bookBarGet().set({ text: '', pick: id }); bookOnly.incidents = false; sw.pick('book', 'click'); };
    box.appendChild(b);
  }
  if (told) box.appendChild(scopeLink(id));
  return box;
}

/**
 * «What he checks here and how he knows →» (2026-11-23: the explanation stands in the help, not at the area's foot): opens
 * the page's help, «The areas», this area's fold — and goes there
 */
function scopeLink(id) {
  const b = el('button', 'btn small plain wm-jump wm-scope-link', T('scope.link'));
  b.type = 'button';
  b.onclick = () => {
    const help = view && view.querySelector('details.page-help');
    const det = help && help.querySelector(`[data-place="scope.${id}"]`);
    if (!det) return;
    help.open = true;
    det.open = true;
    folds.set(`scope:${id}`, true);
    det.scrollIntoView({ block: 'start', behavior: 'smooth' });      /* reveal */      // the jump this link asks for
    const sum = det.querySelector('summary');
    if (sum) sum.focus({ preventScroll: true });
  };
  return b;
}

/** His advice in a tile (each advice only here since 1.96 «einfacher»): the ones you don't know yet, the known ones counted */
function tileAdvice(id) {
  const tips = ((state && state.posture && state.posture.tips) || []).filter((x) => x && (x.tile || tileOfCat(x.cat)) === id);
  if (!tips.length) return null;
  const open = tips.filter((x) => !x.known || showKnownTips);
  const known = tips.length - tips.filter((x) => !x.known).length;
  const box = el('div', 'wm-cat-tips');
  box.appendChild(el('h4', 'wm-hold-group', T('cat.tips_title')));
  if (open.length) {
    const list = el('div', 'box');
    open.forEach((x) => list.appendChild(postureRow(x)));
    box.appendChild(list);
  }
  if (known) {
    const b = el('button', 'btn small plain', showKnownTips ? T('posture.hide_known') : T('cat.tips_known', { n: known }));
    b.type = 'button';
    b.onclick = () => { showKnownTips = !showKnownTips; Office.keepInPlace(b, () => sw.render()); };
    box.appendChild(b);
  }
  return box;
}

/** A tile switched on again (every area of it) */
async function tileOn(id, b) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.tile_off`, { tile: id, off: false });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); b.disabled = false; return; }
  if (j.state) state = j.state;
  mood();
  renderAt('.wm-cat');
  Office.toast(T('rule.cat_on_done'));
}

/** A day's heading between the rows: «Today», «Yesterday», then the date */
function dayHead(day) {
  const h = el('div', 'wm-day', fmt.dayTitle(day));
  h.setAttribute('role', 'heading');
  h.setAttribute('aria-level', '3');
  return h;
}

/**
 * Several entries of one kind and area on one day, as one row: how many, the newest time, the strongest state of its
 * members (open before noted), «All n: I know, thanks» for those still open; a click unfolds the entries, each as its
 * own row. While filter words are typed the rows unfold by themselves (only the entries the words meet are in them).
 */
function foldRow(x, typed) {
  const n = x.members.length;
  const first = x.members[0];
  const watch = first.kind === 'watch';
  const words = typed !== undefined ? !!typed : !!(bookBar && bookBar.read().words.length);      // an area's section hands its own bar's
  const wrap = el('div', 'wm-fold');
  const r = el('div', 'row nocheck wm-entry wm-fold-head unfolds' + (x.open ? ' wm-open' : ''));
  r.dataset.fold = x.key;
  const main = el('div', 'row-main');
  const name = el('div', 'row-name text', foldName(x));
  name.title = T('fold_title');
  const meta = el('div', 'row-meta');
  if (watch) {
    meta.appendChild(first.p && first.p.too_much ? chip(T('state.too_much'), 'warn', T('state.too_much_title')) : chip(T('state.watch'), 'accent', T('state.watch_title')));
  } else {
    // in the line only how many are open (or «done») and the time (1.96 «einfacher»); each entry's tags when unfolded
    meta.appendChild(x.open ? chip(T('book.open_n', { n: x.open }), 'warn', T('state.open_title')) : chip(T('state.noted'), 'quiet', T('state.noted_title')));
    if (x.chain) meta.appendChild(chip(T('chain_chip'), 'warn', T('chain_title_fold')));
  }
  const when = el('span', '', fmt.date(x.last));
  when.dataset.tip = fmt.relative(x.last);
  meta.appendChild(when);
  if (words && x.total > n) meta.appendChild(el('span', '', T('book.hits', { n, total: x.total })));
  main.append(name, meta);
  r.appendChild(main);
  const open = x.members.filter((e) => e.open);
  if (open.length) {
    const b = el('button', 'btn small plain', open.length > 1 ? T('book.ack_n', { n: open.length }) : T('ack'));
    b.type = 'button';
    b.title = T('book.ack_n_title');
    b.disabled = !Office.agent.running;
    b.onclick = () => ackSome(open, b, x.key);
    r.appendChild(b);
  }
  const body = el('div', 'wm-members');
  wrap.append(r, body);
  let isOpen = false;
  const set = (on) => {
    if (on === isOpen) return;
    isOpen = on;
    r.classList.toggle('open', on);
    r.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) {
      foldsOpen.add(x.key);
      foldsShut.delete(x.key);
      x.members.forEach((e) => { const row = entryRow(e, x.key); row.classList.add('wm-member'); body.appendChild(row); });
    } else {
      foldsOpen.delete(x.key);
      if (words) foldsShut.add(x.key);
      bookRows = bookRows.filter((y) => y.owner !== x.key);
      body.innerHTML = '';
    }
  };
  r.onclick = (ev) => {
    if (ev.target.closest('button, a, input, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;     // selecting text
    Office.keepInPlace(r, () => set(!isOpen));
    if (bookLabel) bookLabel();
  };
  bookRows.push({ open: () => isOpen, set, fold: true });
  r.setAttribute('aria-expanded', 'false');
  if (foldsOpen.has(x.key) || (words && !foldsShut.has(x.key))) set(true);
  return wrap;
}

/** Entries that may belong together (a SOC's correlation): what came within how many minutes, oldest first */
/** What may belong together, in a tile: alone, each chain with a waiting entry here; on the whole page, each chain once,
 *  in the first tile that holds one of its waiting entries */
function tileChains(id) {
  const byId = new Map((state.book || []).map((e) => [e.id, e]));
  return (state.chains || []).filter((c) => c && Array.isArray(c.ids)).filter((c) => {
    const tiles = c.ids.map((x) => byId.get(x)).filter((e) => e && e.open).map(entryTile);
    if (!tiles.length) return false;
    if (sw.active() === id) return tiles.includes(id);
    return TILES.find((t) => tiles.includes(t)) === id;
  });
}

function chainCallout(c) {
  const box = el('div', 'callout warn wm-chain');
  const minutes = Math.max(1, Math.round((c.last - c.first) / 60));
  box.appendChild(el('p', '', T('chain.text', { n: c.ids.length, minutes })));
  const byId = new Map((state.book || []).map((e) => [e.id, e]));
  const ul = el('ul', 'wm-chain-list');
  c.ids.map((id) => byId.get(id)).filter(Boolean).forEach((e) => {
    ul.appendChild(el('li', '', `${fmt.time(e.time)} · ${T('group.' + e.group)} · ${entryName(e)}`));
  });
  box.appendChild(ul);
  box.appendChild(el('p', 'wm-note', T('chain.how')));
  return box;
}

/** An entry in words, as its row names it (and the search finds it) */
function entryName(e) {
  if (e.kind === 'watch' && e.p && e.p.too_much) {
    return T(e.p.ros ? 'entry.net_too_much_mikrotik' : 'entry.net_too_much', { sender: e.p.sender || '', size: fmt.size(e.p.skipped || 0) });
  }
  if (e.kind === 'watch' && e.p && (e.p.relearn || e.p.relearn_back)) {      // an area re-learned, or brought back (step 17)
    return T(e.p.relearn ? 'entry.watch_relearn' : 'entry.watch_relearn_back', { area: catName(e.p.relearn || e.p.relearn_back) });
  }
  return T('entry.' + (e.v || atVariant(e) || e.kind), entryParams(e));     // e.v: the shape a network entry has now (agent: watchnetEntryVariant())
}

/** An at job he doesn't know: running (=…) says since when, one still waiting days past its time says so (agent: watchmanAtSet()); a plugin's running */
function atVariant(e) {
  if (e.kind === 'at_plugin' && e.p && String(e.p.job || '').startsWith('=')) return 'at_plugin_run';      // atd runs it (=…)
  if (e.kind !== 'at_job' || !e.p) return null;
  return e.p.run ? 'at_job_run' : e.tell ? 'at_job_late' : null;
}

/** Kinds whose words say how many already (failed logins, a port's link losses, outages, restarts a day) */
const COUNT_IN_WORDS = ['login_failures', 'net_router_login_failures', 'net_link_down', 'net_outage', 'net_router_reboot'];

/** One entry: what, when, noted or not; a click unfolds its details (owner: the folded row it is drawn in, if any) */
function entryRow(e, owner) {
  const watch = e.kind === 'watch';
  const tooMuch = watch && !!(e.p && e.p.too_much);       // more of a router's log than a round reads (agent: watchnetTooMuch())
  const r = Office.place(`entry:${e.id}`, el('div', 'row nocheck wm-entry' + (watch ? ' wm-watch' : ' unfolds') + (e.open ? ' wm-open' : '')));
  r.dataset.id = e.id;
  const main = el('div', 'row-main');
  const name = el('div', 'row-name text', entryName(e));
  const meta = el('div', 'row-meta');
  const more = [];                // its other tags, shown when the row is unfolded
  if (tooMuch) {
    meta.appendChild(chip(T('state.too_much'), 'warn', T('state.too_much_title')));
  } else if (watch) {
    meta.appendChild(chip(T('state.watch'), 'accent', T('state.watch_title')));
  } else {
    name.title = T('details');
    // in the line only «open» or «done» (an incident says so) and the time (1.96 «einfacher»); the rest when unfolded
    meta.appendChild(e.open ? chip(T('state.open'), 'warn', T('state.open_title'))
      : e.serious ? chip(T('state.serious'), 'warn', T('state.serious_title')) : chip(T('state.noted'), 'quiet', T('state.noted_title')));
    if (e.again) more.push(chip(T('state.again'), 'warn', T('state.again_title')));
    const chain = (state.chains || []).find((c) => c.ids.includes(e.id));
    if (chain) more.push(chip(T('chain_chip'), 'warn', T('chain_title', { n: chain.ids.length })));
    if (e.night) more.push(chip(T('state.night'), 'accent', T('state.night_title')));
    more.push(chip(T('group.' + e.group), '', T('group_title.' + e.group)));
    const tech = attackLink(e.attack);            // for experts: the ATT&CK technique
    if (tech) more.push(tech);
  }
  const when = el('span', '', fmt.date(e.last));
  when.dataset.tip = fmt.relative(e.last);
  meta.appendChild(when);
  // snapshots: the count is how many, it stands in the words already
  if (e.count > 1 && !COUNT_IN_WORDS.includes(e.kind) && e.group !== 'snap') meta.appendChild(el('span', '', T('times', { n: e.count })));
  main.append(name, meta);
  r.appendChild(main);
  if (e.open) {
    const acts = el('div', 'wm-entry-acts');
    // 1.96: a state's answer holds until you take it back; an event's is for this time (the messenger says which: lasts)
    const once = e.lasts === false;
    const b = el('button', 'btn small plain', T(once ? 'ack_once' : 'ack'));
    b.type = 'button';
    b.title = e.lasts === true ? `${T('ack_title')} ${T('ack_lasts_title')}` : T('ack_title');
    b.disabled = !Office.agent.running;
    b.onclick = () => ack(e, b);
    acts.appendChild(b);
    // «Such a thing is always in order …» (1.96 «einfacher»): one button, its choice in whole sentences (ruleAsk())
    const offers = watch ? [] : (Array.isArray(e.offers) ? e.offers : []).filter((o) => o && OFFERS.includes(o.type));
    if (offers.length) {
      const ob = el('button', 'btn small plain wm-offer', T('rule.always'));
      ob.type = 'button';
      ob.title = T('rule.offer_title');
      ob.disabled = !Office.agent.running || !hired();
      ob.onclick = () => ruleAsk(e, offers, ob);
      acts.appendChild(ob);
    }
    if (!watch) {
      const sb = el('button', 'btn small plain wm-serious', T('serious'));
      sb.type = 'button';
      sb.title = T('serious_title');
      sb.disabled = !Office.agent.running;
      sb.onclick = () => seriousAck(e, sb, b);
      acts.appendChild(sb);
    }
    r.appendChild(acts);
  }
  if (watch) return r;
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; unfolded.delete(e.id); r.classList.remove('open'); }
    if (open && !box) {
      box = details(e);
      if (more.length) { const tags = el('div', 'row-meta wm-more-tags'); more.forEach((x) => tags.appendChild(x)); box.prepend(tags); }
      r.appendChild(box); unfolded.add(e.id); r.classList.add('open');
    }
  };
  r.onclick = (ev) => {
    if (ev.target.closest('button, a, input, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;     // selecting text
    Office.keepInPlace(r, () => set(!box));
    if (bookLabel) bookLabel();
  };
  bookRows.push({ open: () => !!box, set, owner });
  if (unfolded.has(e.id)) set(true);
  return r;
}

/** The entry's nearest MITRE ATT&CK technique: a quiet chip with its explanation — no link out (2026-10-09:
 * a click opened nothing inside Unraid's page, and a link out is one more thing that can break) */
function attackLink(id) {
  if (typeof id !== 'string' || !/^T\d{4}(\.\d{3})?$/.test(id)) return null;
  const c = el('span', 'chip quiet wm-attack', id);
  c.title = T('attack_title', { id });
  return c;
}

/** A way in, in words (agent: watchmanDoorWords()) */
function doorWords(d) {
  return T('door.' + (d.door || d.what || 'ssh'), { name: d.name || '', port: d.port === null || d.port === undefined ? '–' : String(d.port),
    issuer: d.issuer || '', n: d.new_peers || d.peers || 0 });
}

/** An entry's words; the data flow's sizes, what is normal and the hours in this browser's language */
function entryParams(e) {
  const t = { ...(e.t || {}), n: e.count };
  const p = e.p || {};
  if (e.kind === 'watch' && !p.too_much) {      // «Took over the watch»: each count in its own words (count.*: one/other)
    const c = (what, k) => T('count.' + what, { n: Number(t[k]) || 0 });
    Object.assign(t, { ips: c('login_addresses', 'ips'), containers: c('containers', 'containers'), plugins: c('plugins', 'plugins'), shares: c('shares', 'shares') });
  }
  if (e.kind === 'proc_odd') t.where = p.where || T('where.host');
  if (e.kind === 'door_new') t.door = doorWords(p);
  if (e.kind === 'door_changed') t.what = doorWhat(p.what);
  if (e.kind === 'net_firewall_change') t.area = T('net_area.' + (['firewall', 'nat', 'port_forward', 'policy'].includes(p.area) ? p.area : 'firewall'));
  if (e.kind === 'parity_check') t.why = parityWhy(p);
  if (e.kind.startsWith('at_') && p.when) Object.assign(t, { when: fmt.date(p.when), since: fmt.date(p.since || p.when) });     // the browser's own way, like its details
  if (e.group !== 'flow') return t;
  const n = flowShape(e) === 'hour' ? p.hour : p.bytes !== undefined ? p.bytes : p.files;      // what its sentence says (agent: watchmanFlowSaid())
  if (p.bytes !== undefined) t.size = fmt.size(n);
  else if (p.files !== undefined) t.size = T('gone.files_n', { n: Number(n) || 0 });     // files deleted: counted
  if (flowShape(e) === 'span') t.span = fmt.duration(60 * (p.minutes || 0));
  if (e.kind.startsWith('flow_')) t.usual = usualText(e.kind, p);
  if (e.kind === 'flow_gone') t.more = goneMore(p);
  if (e.kind === 'flow_written') t.from = srcFrom(p);
  if (Array.isArray(p.hours)) t.hours = p.hours.map(hourName).join(', ');
  return t;
}

/** A data-flow entry's sentence (agent: watchmanFlowShape(), sent as e.v): 'hour' — the hour that tripped, 'span' — so
 * much over a long time, '' — the pull in its minutes */
function flowShape(e) {
  const m = /_(hour|span)$/.exec(e.group === 'flow' && e.v ? e.v : '');
  return m ? m[1] : '';
}

/** "3.1 GB in 12 min", "… in one hour", "… in 5 h 40 min" — as its sentence says it */
function flowSaid(e, size) {
  const p = e.p || {};
  const shape = flowShape(e);
  return shape === 'hour' ? T('detail.in_hour', { size }) : shape === 'span' ? T('detail.in_span', { size, span: fmt.duration(60 * (p.minutes || 0)) })
    : T('detail.in_minutes', { size, minutes: p.minutes || 1 });
}

/**
 * Who wrote into a share (agent: watchmanFlowSources(), watchmanFlowSourceWhat()): a container's writable layer, Docker's
 * image layers per image, libvirt's dataset, another dataset, the share's own, the rest
 */
function srcWhat(x) {
  const name = x.name || '';
  switch (x.t) {
    case 'ct': return T('src.container', { name });
    case 'img': return T(x.new ? 'src.layers_new' : 'src.layers', { n: Number(x.n) || 1, image: name ? T('src.image', { name }) : T('src.image_unknown') });
    case 'libvirt': return T('src.libvirt', { name });
    case 'ds': return T('src.dataset', { name });
    case 'own': return x.dir !== undefined ? T('src.own_libvirt', { name, folder: [name.split('/').slice(1).join('/'), x.dir].filter(Boolean).join('/') }) : T('src.own', { name });
    default: return T('src.rest');
  }
}

/** One source with its bytes, new or deleted since */
function srcLine(x) {
  const key = x.new && x.t !== 'img' ? (x.gone ? 'src.line_new_gone' : 'src.line_new') : (x.gone ? 'src.line_gone' : 'src.line');
  return T(key, { what: srcWhat(x), size: fmt.size(x.b || 0) });
}

/**
 * Where no source can be seen (a share of one dataset): who likely wrote it, from the setup (agent: watchmanFlowGuess(),
 * watchmanFlowGuessText()) — at most three named, «and {n} more»; without a guess that none can be seen. short: the
 * entry's line — names only, two of them; the full words in its detail row
 */
function srcNone(x, share, short) {
  const guess = (Array.isArray(x.guess) ? x.guess : []).filter((g) => g && typeof g === 'object');
  if (!guess.length) return T('src.none', { share: share || '' });
  if (short) {
    const names = guess.slice(0, 2).map((g) => String(g.name || '')).join(', ');
    const rest = guess.length + (Number(x.more) || 0) - Math.min(guess.length, 2);
    return T('src.guess_short', { list: rest > 0 ? T('src.guess_more', { list: names, n: rest }) : names });
  }
  return T('src.guess', { list: guessList(x, share) });
}

/**
 * The likely writers in full words (agent: watchmanFlowGuessText()): a container, VM or stack mapping the path, a User
 * Script naming it, a container's template while Docker didn't answer — at most three, «and {n} more»
 */
function guessList(x, share) {
  const guess = (Array.isArray(x.guess) ? x.guess : []).filter((g) => g && typeof g === 'object');
  const name = String(share || '').split('/').slice(1).join('/') || String(share || '');
  const one = (g) => {
    const how = [g.disk ? T('src.guess_disk', { share: name }) : T(g.t === 'us' ? 'src.guess_names' : 'src.guess_maps', { path: String(g.path || '') })];
    if (g.t === 'tpl') how.push(T('src.guess_tpl'));
    if (g.ro) how.push(T('src.guess_ro'));
    if (g.off && g.t !== 'tpl') how.push(T('src.guess_off'));
    const key = { vm: 'src.guess_vm', stack: 'src.guess_stack', us: 'src.guess_us' }[g.t] || 'src.guess_ct';
    return T(key, { name: String(g.name || ''), how: how.join(', ') });
  };
  const list = guess.slice(0, 3).map(one).join(', ');
  const more = Number(x.more) || 0;
  return more > 0 ? T('src.guess_more', { list, n: more }) : list;
}

/** Whose a router's line about this server likely is (agent: watchmanNetGuess()) — a guess, never a fact */
function netGuess(p) {
  if (!Array.isArray(p.guess)) return '';
  const one = (g) => {
    const t = g.t === 'vm' ? T('net_guess.vm', { name: String(g.name || '') })
      : g.port ? T('net_guess.port', { name: String(g.name || ''), port: String(g.port) }) : T('net_guess.addr', { name: String(g.name || '') });
    return g.off ? `${t}, ${T('src.guess_off')}` : t;
  };
  const list = p.guess.filter((g) => g && typeof g === 'object').map(one).join(', ');
  return list ? T('net_guess.text', { list }) : T('net_guess.none');
}

/** The biggest source behind an entry's line, or that none can be seen ('' without sources: an entry of an older office) */
function srcFrom(p) {
  if (p.disk) {        // written to an XFS/btrfs disk: measured for it whole (btrfs: where, if it could tell — agent: watchmanBtrfsWhere())
    const top = (p.where && Array.isArray(p.where.top)) ? p.where.top : [];
    const list = top.map((x) => `${x.share} (${fmt.size(x.b || 0)})`).join(', ');
    return ' ' + (top.length ? T(p.where.part ? 'src.disk_most_part' : 'src.disk_most', { list, n: fmt.number(20000) }) + ' ' : '') + T('gone.disk', { fs: p.fs || '' });
  }
  const top = Array.isArray(p.src) ? p.src[0] : null;
  if (!top || top.t === 'rest') return '';
  return ' ' + (top.t === 'none' ? srcNone(top, p.share, true) : T('src.most', { what: srcWhat(top), size: fmt.size(top.b || 0) }));
}

/**
 * Why a parity check runs (agent/lib/paritywhy.php paritywhyWhy()): its reason in words — after an unclean stop how it
 * ended and what held up the array, as far as Unraid's log shows it; a tip to keep the syslog when nothing was kept
 */
function parityWhy(p) {
  const reason = ['unclean', 'schedule', 'tuning', 'manual', 'rebuild'].includes(p.reason) ? p.reason : 'unknown';
  if (reason !== 'unclean') {
    const who = (p.logins || []).map((l) => `${l.user ? l.user + '@' : ''}${l.ip} (${loginService(l.service)})`);
    return T('parity.why.' + reason, { action: p.action || '', who: who.length ? [...new Set(who)].join(', ') : '–' });
  }
  const stop = ['timeout', 'late', 'crash'].includes(p.stop) ? p.stop : 'unknown';
  let out = T('parity.why.unclean_' + stop, { timeout: Number(p.timeout) || 90 });
  if (stop === 'timeout') out += p.held ? T('parity.held', { held: p.held }) : T('parity.held_none');
  if (!p.kept && (stop === 'timeout' || stop === 'unknown') && p.from !== 'previous' && !p.held) out += T('parity.keep_syslog');
  if ((Number(p.streak) || 0) >= 2) out += T('parity.repeat', { n: Number(p.streak) });
  return out;
}

/** What of a partner's door line changed (agent: watchmanPartnerCompare()), in words */
const doorWhat = (list) => (list || []).map((w) => T('door_what.' + (['from', 'restrict', 'command', 'key', 'options'].includes(w) ? w : 'options'))).join(', ');

/** What else an entry of vanished data says (agent: watchmanGoneMore()): its snapshots, who moved data then, a whole disk, pools asleep */
function goneMore(p) {
  const out = [];
  if (p.kept > 0) out.push(T('gone.kept', { size: fmt.size(p.kept) }));
  if (p.from === 'clients') out.push(T('gone.who_clients', { list: (p.clients || []).join(', ') }));
  else if (p.from === 'server') out.push(Array.isArray(p.guess) && p.guess.length ? T('gone.who_server_guess', { list: guessList(p, 'user/' + (p.share || '')) }) : T('gone.who_server'));
  if ((p.users || []).length) out.push(T('gone.who_smb', { users: p.users.join(', ') }));
  if (p.files !== undefined && p.bytes !== undefined) out.push(T('gone.files', { n: Number(p.files) || 0 }));
  if (p.files !== undefined && p.renames) out.push(T('gone.renames'));
  if (p.disk) out.push(T('gone.disk', { fs: p.fs || '' }));
  if ((p.asleep || []).length) out.push(T('gone.asleep', { pools: p.asleep.join(', ') }));
  return out.length ? ' ' + out.join(' ') : '';
}

/** What moves data now (agent: watchmanFlowMovers()), in words */
const movingText = (list) => (list || []).map((m) => (Office.has(`${ID}.moving.${m}`) ? T('moving.' + m) : m)).join(', ');

/** "usually at most 1 GB per hour at this time" — or why it was told while still learning */
function usualText(kind, p) {
  if (p.bytes === undefined && p.files !== undefined) {             // files deleted: counted, not measured
    if (p.learning) return T('flow.learning_files', { n: Number(p.limit) || 0 });
    return p.usual > 0 ? T('flow.usual_files', { n: Number(p.usual) }) : T('flow.usual_none');
  }
  if (p.learning) return (kind === 'flow_written' && !p.disk) || (kind === 'flow_gone' && p.part) ? T('flow.learning_share', { pct: p.pct || 0 }) : T('flow.learning', { size: fmt.size(p.limit || 0) });
  return p.usual > 0 ? T('flow.usual', { size: fmt.size(p.usual) }) : T('flow.usual_none');
}

/** An hour of the week (Monday 0:00 = 0) as "Mon 03:00" in this browser's language */
function hourName(h) {
  const d = new Date(2024, 0, 1 + Math.floor(h / 24), h % 24);      // 1 January 2024 was a Monday
  let day = '';
  try { day = new Intl.DateTimeFormat(Office.locale, { weekday: 'short' }).format(d); } catch (err) { day = String(Math.floor(h / 24) + 1); }
  return `${day} ${String(h % 24).padStart(2, '0')}:00`;
}

const goneLimits = () => {
  const l = (state && state.flow && state.flow.limits) || {};
  return { new: fmt.size(l.gone_new || 100 * 1024 ** 3), part: l.gone_part || 10, learn: (state && state.flow && state.flow.learn) || 7,
    files_min: fmt.number(l.files_min || 2000), files_new: fmt.number(l.files_new || 20000) };
};
const flowLimits = () => {
  const l = (state && state.flow && state.flow.limits) || {};
  return { keep: l.keep || 14, factor: l.factor || 4, min: fmt.size(l.min || 2 * 1024 ** 3), learn: (state && state.flow && state.flow.learn) || 7,
    new: fmt.size(l.new || 50 * 1024 ** 3), part: l.part || 20, files_new: fmt.number(l.files_new || 20000) };
};

/** docker run flags as small code chips (their words for the list's filter: wmWords, read by item()) */
function flags(tokens) {
  const box = el('span', 'wm-flags');
  (tokens || []).forEach((t) => box.appendChild(el('span', 'wm-flag', t)));
  box.wmWords = (tokens || []).map(String);
  return box;
}

const level = (n) => T('level.' + (n >= 2 ? 'public' : n === 1 ? 'secure' : 'none'));

/** A User Scripts schedule: its word (daily, at the array's start …) or the cron line in words */
const freq = (c) => (c && Office.has(`${ID}.freq.${c}`) ? T('freq.' + c) : fmt.cron(c || '') || '–');

/** Lines as a small list (mono), "… and N more" when there were more */
function lines(list, total) {
  const box = el('div', 'wm-lines');
  (list || []).forEach((x) => box.appendChild(el('div', 'mono', x)));
  const more = (Number(total) || 0) - (list || []).length;
  if (more > 0) box.appendChild(el('div', 'wm-note', T('detail.more', { n: more })));
  return box;
}

/** A command for Unraid's terminal: shown, copied on a click — never run by the office */
function fixBox(cmd) {
  const box = el('div', 'wm-fix');
  const code = el('code', 'mono', cmd);
  const b = el('button', 'btn small plain', Office.t('common.copy'));
  b.type = 'button';
  b.dataset.own = '1';
  b.onclick = () => Office.copy(cmd);
  box.append(code, b, el('div', 'wm-note', T('detail.fix_note')));
  return box;
}

function details(e) {
  const box = el('div', 'row-detail wm-detail');
  const dl = el('dl', 'kv');
  const add = (label, value, mono) => {
    if (value === null || value === undefined || value === '') return;
    const dd = el('dd', mono ? 'mono' : '');
    if (value instanceof Node) dd.appendChild(value); else dd.textContent = String(value);
    dl.append(el('dt', '', label), dd);
  };
  const p = e.p || {};
  add(T('detail.first'), fmt.date(e.time));
  if (e.last !== e.time) add(T('detail.last'), fmt.date(e.last));
  if (e.count > 1 && e.group !== 'snap') add(T('detail.count'), T('times', { n: e.count }));
  if (e.kind === 'kopia_emptied') {
    // the offsite copy emptied: the way back is the Consultant's Object Lock guide, its step with the read-only --point-in-time connection
    const adv = Office.desks.get('advisor');
    if (adv && adv.hired) {
      const b = el('button', 'btn small', T('detail.kopia_back'));
      b.type = 'button';
      b.dataset.own = '1';
      b.onclick = () => { Office.go('#/advisor'); Office.reveal('lock.8', { name: T('detail.kopia_back'), part: 'ext.kopia' }); };
      add(Office.t('advisor.name'), b);
    }
  } else if (e.kind === 'parity_check') {
    // the operation, how far it is, and where the reason was seen (Unraid's diagnostics, the syslog it kept, its own line)
    add(T('detail.parity_action'), p.action, true);
    const errors = T('parity.errors', { n: Number(p.errors) || 0 });
    add(T('detail.parity_state'), p.end ? T(Number(p.exit) === 0 ? 'parity.state_done' : 'parity.state_failed', { when: fmt.date(p.end), code: p.exit, errors })
      : T('parity.state_running'));
    const from = [];
    if (p.evidence && Office.has(`${ID}.parity.from_${p.evidence}`)) from.push(T('parity.from_' + p.evidence));
    if (p.from === 'diag' && p.diag) from.push(T('parity.from_diag', { name: p.diag }));
    else if (p.from === 'previous') from.push(T('parity.from_previous'));
    if (from.length) {
      const box = el('div', '');
      from.forEach((x) => box.appendChild(el('div', '', x)));
      add(T('detail.parity_from'), box);
    }
    if (p.reason === 'unclean' && !p.kept) {
      const a = el('a', '', T('parity.to_syslog'));
      a.href = '/Settings/SyslogSettings';      // into Unraid, in the same tab like its own links
      add(T('detail.parity_keep'), a);
    }
    if ((p.logins || []).length) add(T('detail.logins'), lines(p.logins.map((l) => `${fmt.time(l.t)} · ${l.user ? l.user + '@' : ''}${l.ip} · ${loginService(l.service)}`)));
  } else if (e.group === 'array') {
    // who had logged in around then (the syslog never says who clicked)
    const who = (p.logins || []).map((l) => `${fmt.time(l.t)} · ${l.user ? l.user + '@' : ''}${l.ip} · ${loginService(l.service)}`);
    add(T('detail.logins'), who.length ? lines(who) : T('detail.logins_none'));
    if (who.length) dl.lastChild.title = T('detail.logins_how');
  } else if (e.group === 'login') {
    add(T('detail.ip'), p.ip, true);
    const users = (p.users || []).join(', ');
    add(T('detail.users'), [users, p.unknown ? T('detail.unknown', { n: p.unknown }) : ''].filter(Boolean).join(' · '));
    add(T('detail.services'), (e.t && e.t.service) || '');
  } else if (e.kind === 'drill_throwaway') {
    // Mr. Restori's drill: its throwaways, as its record names them
    add(T('detail.drill'), p.id, true);
    add(T('detail.name'), (p.names || []).join(', '), true);
    add(T('detail.image'), p.image, true);
  } else if (e.group === 'container') {
    add(T('detail.name'), p.name);
    add(T('detail.image'), p.image, true);
    add(T('detail.rights'), flags(p.tokens));
  } else if (e.group === 'plugin') {
    add(T('detail.plugin'), p.name);
    add(T('detail.source'), p.source || T('watch.no_source'), true);
    if (e.kind === 'plugin_source') add(T('detail.old'), p.old || T('watch.no_source'), true);
    add(T('detail.version'), p.version);
  } else if (e.kind === 'flash_go') {
    add(T('detail.go'), p.gone ? T('detail.go_gone') : T('detail.go_lines', { added: p.added || 0, removed: p.removed || 0, lines: p.lines || 0 }));
    // the added lines' text (the only text of go he keeps, in this entry); of removed lines only their number
    if (!p.gone && (p.text || []).length) add(T('detail.go_added'), lines(p.text, p.added));
    if (!p.gone && p.removed > 0) add(T('detail.go_removed'), T('detail.go_removed_n', { n: Number(p.removed) }));
  } else if (e.kind === 'flash_extra') {
    add(T('detail.file'), '/boot/extra/' + (p.file || ''), true);
    add(T('detail.size'), fmt.size(p.size));
    add(T('detail.changed'), fmt.date(p.time));
  } else if (e.group === 'flash') {
    add(T('detail.user'), p.user);
    if (e.kind === 'flash_ssh_key') {
      add(T('detail.key'), [p.type, p.comment].filter(Boolean).join(' '), true);
      add(T('detail.fp'), p.fp, true);
    }
  } else if (e.group === 'share') {
    add(T('detail.share'), p.share);
    add(T('detail.access'), `${String(p.proto || '').toUpperCase()}: ${level(p.level === 'public' ? 2 : 1)}`);
  } else if (e.group === 'flow') {
    if (p.ip) add(T('detail.ip'), p.ip, true);
    if (p.machine) add(T('detail.machine'), p.machine);
    if (p.user) add(T('detail.user'), p.user);
    if (Array.isArray(p.users) && p.users.length) add(T('detail.users'), p.users.join(', '));
    if (p.service) add(T('detail.services'), (e.t && e.t.service) || p.service);
    if (p.name) add(T('detail.name'), p.name);
    if (p.image) add(T('detail.image'), p.image, true);
    if (p.share && !p.disk) add(T('detail.share'), p.share, true);      // a disk's entry carries its name as share for older pages only
    if (p.disk) add(T('detail.disk'), `${p.disk} (${p.fs || '?'})`, true);
    if (p.disk && (p.shares || []).length) add(T('detail.shares_on'), p.shares.join(', '));
    if (p.bytes !== undefined) {
      add(T(e.kind === 'flow_written' ? 'detail.written' : e.kind === 'flow_gone' ? 'detail.gone' : e.kind === 'flow_container' ? 'detail.sent' : 'detail.pulled'),
        flowSaid(e, fmt.size(flowShape(e) === 'hour' ? p.hour : p.bytes)));
      if ((e.kind === 'flow_written' || e.kind === 'flow_gone') && p.pct) add(T('detail.part'), `${fmt.number(p.pct)} %`);
      if (e.kind === 'flow_written' && Array.isArray(p.src) && p.src.length) {
        const box = el('div', '');
        p.src.forEach((x) => box.appendChild(el('div', '', x.t === 'none' ? srcNone(x, p.share) : srcLine(x))));
        add(T('detail.sources'), box);
      }
      if (e.kind === 'flow_gone' && p.kept) add(T('detail.kept'), fmt.size(p.kept));
      if (e.kind === 'flow_gone' && p.from) add(T('detail.who'), p.from === 'clients' ? (p.clients || []).join(', ') : T('detail.who_server'));
      if (e.kind === 'flow_gone' && (p.asleep || []).length) add(T('detail.asleep'), p.asleep.join(', '));
      if (e.kind === 'flow_gone' && p.files !== undefined) add(T('detail.files'), T('gone.files_n', { n: Number(p.files) || 0 }));
      if (!p.learning) add(T('detail.usual'), usualText(e.kind, p));
      if (p.peak) add(T('detail.peak'), fmt.size(p.peak));
    } else if (p.files !== undefined) {           // files deleted (ZFS per share, XFS per disk): a count
      add(T('detail.files'), flowSaid(e, T('gone.files_n', { n: Number(flowShape(e) === 'hour' ? p.hour : p.files) || 0 })));
      if (p.from) add(T('detail.who'), p.from === 'clients' ? (p.clients || []).join(', ') : T('detail.who_server'));
      if (!p.learning) add(T('detail.usual'), usualText(e.kind, p));
      if (p.peak) add(T('detail.peak'), T('gone.files_n', { n: Number(p.peak) }));
    }
    if (Array.isArray(p.hours) && p.hours.length) add(T('detail.hours'), p.hours.map(hourName).join(', '));
  } else if (e.group === 'snap') {
    add(T('detail.where'), `${p.where || '?'} (${p.fs === 'btrfs' ? 'btrfs' : 'ZFS'})`, true);
    if ((p.datasets || []).length) add(T('detail.datasets'), lines(p.datasets));
    add(T(e.kind === 'snap_gone' ? 'detail.snaps_gone' : 'detail.snaps_released'), lines(p.names, e.count));
    if (p.held) add(T('detail.held'), T('detail.held_n', { n: p.held }));
    if (p.fs !== 'btrfs') add(T('detail.history'), (p.history || []).length ? lines(p.history) : T('detail.history_none'));
    add(T('detail.evidence'), (p.evidence || []).length ? lines(p.evidence) : T('detail.evidence_none_snap'));
    if (e.kind === 'snap_gone' && (p.series || []).length) add(T('detail.series'), lines(p.series));
  } else if (e.group === 'host') {
    if (e.kind === 'log_cleared') {
      add(T('detail.log'), p.log, true);
      add(T('detail.how'), (p.how || []).map((h) => T('detail.how_' + h)).join(' · '));
      if ((p.sizes || []).length) add(T('detail.sizes'), lines(p.sizes));
    } else if (e.kind === 'user_ram') {
      add(T('detail.user'), p.user);
      add(T('detail.user_id'), String(p.uid));
      add(T('detail.why'), (p.why || []).map((w) => T('detail.why_' + w)).join(' · '));
    } else if (e.kind === 'listen_new') {
      add(T('detail.program'), p.prog, true);
      add(T('detail.port'), p.port === null || p.port === undefined ? T('detail.port_dynamic') : String(p.port));
      add(T('detail.addresses'), (p.addr || []).map((a) => (a === '*' ? T('detail.addr_all') : a)).join(', '));
    } else if (e.kind === 'proc_odd') {
      add(T('detail.program'), p.prog, true);
      add(T('detail.exe'), p.exe, true);
      add(T('detail.runs_in'), p.where || T('where.host'));
    } else if (e.kind === 'door_new') {
      add(T('detail.door'), doorWords(p));
      if (p.door === 'wg' || p.door === 'api_sso') add(T('detail.peers'), T('detail.peers_n', { n: p.peers || 0, new: p.new_peers || 0 }));
    } else if (e.kind === 'api_key_new' || e.kind === 'api_key_changed') {
      // Unraid's API: the key's name, roles and permissions as its file says — never its value (the agent never keeps it)
      add(T('detail.api_key'), p.name);
      add(T('detail.roles'), (p.roles || []).join(', ') || T('detail.roles_none'));
      if (e.kind === 'api_key_changed') add(T('detail.roles_old'), (p.old_roles || []).join(', ') || T('detail.roles_none'));
      add(T('detail.permissions'), (p.rights || []).length ? lines(p.rights, p.perms) : T('detail.roles_none'));
      if (p.full) add(T('detail.api_full'), T('detail.api_full_text'));
    }
  } else if (e.group === 'partner') {
    // a partner office's door (the Team Lead's pairing): never a key, only its fingerprint and addresses
    add(T('detail.partner'), p.name || p.id);
    if (e.kind === 'partner_paired') {
      if (p.kind === 'ticket') add(T('detail.ticket'), T('detail.ticket_text', { of: p.of || '', until: p.expires ? fmt.date(p.expires) : '?' }));
      add(T('detail.address'), p.address, true);
      add(T('detail.from'), p.from, true);
      add(T('detail.fingerprint'), p.fp, true);
    } else if (e.kind === 'door_changed') {
      add(T('detail.changed'), doorWhat(p.what));
      add(T('detail.from'), p.from || T('detail.from_none'), !!p.from);
      if (p.from_old !== p.from) add(T('detail.from_old'), p.from_old || T('detail.from_none'), !!p.from_old);
      add(T('detail.restrict'), T(p.restrict ? 'detail.restrict_on' : 'detail.restrict_off'));
      add(T('detail.command'), T(p.command ? 'detail.command_door' : 'detail.command_other'));
      if ((p.others || []).length) add(T('detail.options'), p.others.join(', '), true);
    } else if (e.kind === 'door_key_moved') {
      add(T('detail.ip'), p.ip, true);
      add(T('detail.how'), T(p.how === 'key' ? 'detail.moved_key' : 'detail.moved_address'));
      add(T('detail.partner_ips'), (p.ips || []).join(', '), true);
      add(T('detail.fingerprint'), p.fp, true);
      if ((p.users || []).length) add(T('detail.user'), p.users.join(', '));
    } else if (e.kind === 'door_refused') {
      add(T('detail.refused_why'), (p.why || []).join(', '), true);
      if (!p.known) add(T('detail.partner_unknown'), T('detail.partner_unknown_text'));
    }
  } else if (e.group === 'net') {
    netDetails(e, p, add);
  } else if (e.group === 'sched') {
    // a plugin's at job names its file in «runs» (a file of the plugin, or /usr/bin/plugin_update_helper — not on the flash)
    if (p.file && e.kind !== 'at_plugin') add(T('detail.cron_file'), '/boot/config/plugins/' + p.file + (p.new ? ` (${T('detail.file_new')})` : ''), true);
    if (p.plugin) add(T('detail.plugin'), p.plugin);
    const atNum = e.kind.startsWith('at_') && /^[a-zA-Z=][0-9a-f]{13}$/.test(p.job || '') ? parseInt(p.job.slice(1, 6), 16) : null;     // as atq shows it
    if (atNum !== null) add(T('detail.at_number'), String(atNum), true);
    else if (p.job) add(T('detail.job'), p.job, true);
    if (p.jobs && p.jobs.length) add(T('detail.jobs'), lines(p.jobs, p.lines));
    if (e.kind === 'script_new' || e.kind === 'script_changed') {
      add(T('detail.script'), p.name);
      add(T('detail.schedule'), freq(p.cron));
      if (e.kind === 'script_changed' && p.old !== p.cron) add(T('detail.schedule_old'), freq(p.old));
      if (e.kind === 'script_changed') add(T('detail.content'), T(p.content ? 'detail.content_changed' : 'detail.content_same'));
    }
    if (e.kind === 'at_job') {
      if (p.queued) add(T('detail.queued'), fmt.date(p.queued));
      add(T('detail.when'), fmt.date(p.when));
      if (p.run && p.since) add(T('detail.running_since'), fmt.date(p.since));
      add(T('detail.command'), p.cmd || '?', true);
      if (p.user || (p.uid !== null && p.uid !== undefined)) add(T('detail.uid'), p.user || String(p.uid));
      if (p.prog) add(T('detail.file_owner'), `${p.prog}: ${p.owner || '–'}`, true);
    }
    if (e.kind === 'at_userscript') {
      add(T('detail.script'), p.name);
      add(T('detail.started'), fmt.date(p.when));
    }
    if (e.kind === 'at_plugin') {
      add(T('detail.when'), fmt.date(p.when));
      add(T('detail.command'), p.file || '?', true);
      if (p.left) add(T('detail.plugin_removed'), fmt.date(p.left));     // what its removed plugin left (agent: watchmanAtLeftNote())
    }
    if (e.kind === 'notify_agent') {
      add(T('detail.agent'), p.name);
      add(T('detail.content'), T(p.new ? 'detail.file_new' : 'detail.content_changed'));
    }
    if (p.mtime) add(T('detail.file_time'), fmt.date(p.mtime));
    if (Array.isArray(p.evidence)) add(T('detail.evidence'), p.evidence.length ? lines(p.evidence) : T('detail.evidence_none'));
    if (e.t && e.t.fix) add(T('detail.fix'), fixBox(e.t.fix));
  }
  box.appendChild(dl);
  const notes = [];
  if (e.group === 'flow' && p.learning) notes.push(T('detail.learning'));
  if (p.office && e.kind.startsWith('cron_file')) notes.push(T('detail.office_cron'));
  else if (p.office_file && e.kind.startsWith('cron_file')) notes.push(T('detail.office_file'));
  if (e.open) notes.push(T('adopt.' + (e.v === 'net_ips_server_list' || e.v === 'login_new_user' ? e.v : e.kind)));     // a block list: its own words (the whole list)
  const by = e.by === 'office' && e.kind === 'partner_paired' ? 'office_partner' : e.by === 'office' && e.kind === 'drill_throwaway' ? 'office_drill' : e.by;
  if (e.noted && by === 'plugin' && e.kind === 'at_plugin' && p.left) notes.push(T('noted.plugin_left', { when: fmt.date(p.left) }));     // agent: watchmanAtLeftNote()
  else if (e.noted) notes.push(T('noted.' + (NOTED_BY.includes(by) ? by : 'page'),
    { when: fmt.date(e.noted), at: fmt.date(Number(e.p && e.p.known_at) || e.noted) }));
  // you knew it — as it was then: something has changed since (1.96, the answer's fingerprint differs)
  if (e.open && e.p && Number(e.p.known_at) > 0) notes.push(T('known.changed', { when: fmt.date(Number(e.p.known_at)) }));
  if (e.told) notes.push(T('detail.told', { when: fmt.date(e.told) }));
  else if (e.muted && e.tell) notes.push(T('detail.muted'));
  else if (e.open) notes.push(T(e.tell ? 'detail.not_told' : 'detail.book_only'));
  notes.forEach((n) => box.appendChild(el('p', 'wm-note', n)));
  return box;
}

/**
 * A network entry's details (agent: watchnetEvent()): the router, its file, what the entry is about — another device's
 * address, MAC or name only for a new device or one claiming the server's place —, the router's line (scrubbed there)
 */
function netDetails(e, p, add) {
  add(T('detail.router'), p.router || p.sender || '');
  if (p.sender && p.sender !== p.router) add(T('detail.sender'), p.sender, true);
  switch (e.kind) {
    case 'net_sender_new':
      if (p.product) add(T('detail.version'), `${p.product} ${p.version || ''}`.trim());
      break;
    case 'net_new_device':
      add(T('detail.device'), p.name || '–');
      add(T('detail.mac'), p.mac + (p.random ? ` (${T('detail.random')})` : ''), true);
      add(T('detail.ip'), p.ip || '–', true);
      if (p.network) add(T('detail.network'), p.network);
      if (p.taken) add(T('detail.taken'), T('detail.taken_text', { mac: p.taken }));
      break;
    case 'net_spoof':
      add(T('detail.mac'), p.mac, true);
      add(T('detail.device'), [p.device, p.device_ip].filter(Boolean).join(' · ') || '–');
      add(T('detail.server_macs'), (p.known || []).join(', ') || '–', true);
      break;
    case 'net_router_login':
      add(T('detail.admin'), p.admin + (p.new_admin ? ` (${T('detail.admin_new')})` : ''));
      add(T('detail.ip'), p.ip || '?', true);
      if (p.method) add(T('detail.how'), p.method);
      break;
    case 'net_firewall_change':
    case 'net_router_config':
      add(T('detail.admin'), (p.admins || []).join(', '));
      if (p.area) add(T('detail.area'), T('net_area.' + p.area));
      if ((p.msgs || []).length) add(T('detail.changes'), lines(p.msgs, e.count));
      break;
    case 'net_vpn_login':
      add(T('detail.vpn_user'), p.user || '?');
      add(T('detail.ip'), p.ip || '?', true);
      if (p.how) add(T('detail.how'), p.how);
      break;
    case 'net_ips_server':
      add(T('detail.signature'), `${p.signature || ''} (${p.sig || '?'})`);
      add(T('detail.direction'), T(p.dir === 'out' ? 'detail.dir_out' : 'detail.dir_in'));
      add(T('detail.remote'), [p.remote, p.dpt ? `${(p.proto || '').toLowerCase()}/${p.dpt}` : ''].filter(Boolean).join(' '), true);
      if (p.risk) add(T('detail.risk'), p.risk);
      if (Array.isArray(p.guess)) add(T('detail.net_guess'), netGuess(p));
      break;
    case 'net_blocked_from_server':
      add(T('detail.destination'), `${p.dst} ${(p.proto || '').toLowerCase()}${p.dpt ? '/' + p.dpt : ''}`, true);
      add(T('detail.rule'), `${p.zone || ''} ${p.action || ''} ${p.rule || ''}`.trim(), true);
      break;
    case 'net_log_silent':
      add(T('detail.last_line'), fmt.date(p.last));
      if (p.usual) add(T('detail.usual_gap'), fmt.duration(p.usual));
      break;
    case 'net_router_login_failures':
      add(T('detail.ip'), p.ip || T('detail.console'), !!p.ip);
      if ((p.methods || []).length) add(T('detail.how'), p.methods.join(', '));
      if ((p.users || []).length) add(T('detail.admins_tried'), p.users.join(', '));
      if (p.unknown) add(T('detail.names_unknown'), T('detail.names_unknown_text', { n: p.unknown }));
      if (p.vpn) add(T('detail.vpn_fail'), T('detail.vpn_fail_text'));
      break;
    case 'net_link_down':
      add(T('detail.iface'), p.iface, true);
      add(T('detail.link_down'), fmt.date(p.down));
      add(T('detail.link_back'), p.back ? `${fmt.date(p.back)} (${T('detail.minutes', { n: p.minutes || 0 })})` : T('detail.still_down'));
      if (p.flapping) add(T('detail.flapping'), T('detail.flapping_text', { n: e.count }));
      break;
    case 'net_outage':
      add(T('detail.iface'), `${p.iface}${p.via ? ` (${p.via === 'pppoe' ? 'PPPoE' : 'DHCP'})` : ''}`, true);
      add(T('detail.outage_from'), fmt.date(p.from));
      if (p.down) add(T('detail.outage_now'), T('detail.outage_since', { when: fmt.date(p.down), n: p.minutes || 0 }));
      else add(T('detail.outage_to'), p.to ? fmt.date(p.to) : T('detail.outage_unknown'));
      if (e.count > 1) add(T('detail.outages'), T('detail.outages_text', { n: e.count, minutes: p.minutes || 0, longest: Math.max(1, Math.round((p.longest || 0) / 60)) }));
      if (p.reason) add(T('detail.reason'), p.reason, true);
      break;
    case 'net_router_reboot':
      add(T('detail.restart'), T(p.clean ? 'detail.restart_clean' : 'detail.restart_unclean'));
      if ((p.admins || []).length) add(T('detail.restart_by'), p.admins.join(', '));
      if (p.power) add(T('detail.power'), T('detail.power_text', { when: fmt.date(p.power) }));
      break;
    default:
  }
  if (p.evidence) add(T('detail.router_line'), p.evidence, true);
}

// ------------------------------------------------------------------ what holds the array
/*
 * «What holds the array?» (agent/lib/arrayhold.php): what would hold up stopping the array or shutting down, right now —
 * looked at in a job of his on the server when the page opens (at most every HOLD_AGAIN s) and on «Check now»; read only.
 * Four groups: what holds it (a file or a working folder there), whom Unraid waits for (VMs, containers: how long), what
 * stops by itself, what is not the array — and whether Unraid's shutdown time-out is enough.
 */
const HOLD_AGAIN = 120;
const holdOf = () => (state && state.hold) || {};
const holdLook = () => holdOf().look || null;
const holdRunning = () => !!holdOf().running;

async function holdCheck(quiet) {
  if (holdRunning() || !Office.agent.running || !hired()) return;
  const j = await Office.api.post(`${ID}.hold`, {});
  if (!j.ok) { if (!quiet) Office.toast(Office.errorText(j.error, ID), true); return; }
  if (j.state) state = j.state;
  if (view) Office.keepInPlace(null, render);
  follow();
}

/** Opening his page looks again when the last look is older than HOLD_AGAIN (or there is none since the server started) */
function holdAuto() {
  if (((state && state.settings && state.settings.hold && state.settings.hold.auto) || {}).value === false) return;      // his setting (1.96)
  const l = holdLook();
  if (!l || Date.now() / 1000 - (Number(l.time) || 0) > HOLD_AGAIN) holdCheck(true);
}

/**
 * What would hold the array up and still waits for you (agent: watchmanHoldWaiting()) — in words, as its rows name them: a
 * User Script you said may run and a VM without the guest agent you know never count; what lies beyond the look's list
 * counts as «n more»
 */
function holdWaiting(l) {
  if (!l || !l.started) return { names: [], known: 0, more: 0 };
  const holds = (l.holds || []).filter((h) => h && typeof h.kind === 'string');
  const vms = (l.vms || []).filter((v) => v && typeof v.name === 'string' && v.agent !== 'connected');
  const hKnown = holds.filter((h) => h.kind === 'userscript' && sideKnown('script', String(h.name || '')));
  const vKnown = vms.filter((v) => sideKnown('vm', String(v.name)));
  const names = holds.filter((h) => !hKnown.includes(h)).map(holdName)
    .concat(vms.filter((v) => !vKnown.includes(v)).map((v) => T('tile.hold_vm', { name: v.name })));
  const known = hKnown.length + vKnown.length;
  return { names, known, more: Math.max(0, (Number(l.count) || 0) - known - names.length) };
}
function holdTile(a) {
  const l = holdLook();
  const w = holdWaiting(l);
  const n = w.names.length + w.more;
  const short = !!(l && l.started && l.times && l.times.fits === false);
  const value = holdRunning() && !l ? T('hold.running') : !l ? '–' : !l.started ? T('tile.hold_stopped') : n ? T('tile.hold_n', { n }) : T('tile.hold_none');
  const why = n ? T('tile.hold_why', { n, what: w.names.slice(0, 3).join(', ') + (n > 3 ? ' ' + T('tile.hold_why_more', { n: n - Math.min(3, w.names.length) }) : '') }) : '';
  return lightTile({ label: T('tile.hold'), value, level: catLevel('hold') || (n || short ? 'warn' : l ? 'ok' : ''),
    sub: !l ? T('tile.hold_never') : [short ? T('tile.hold_short') : '', w.known ? T('tile.hold_known', { n: w.known }) : '',
      T('tile.hold_when', { when: fmt.relative(l.time) })].filter(Boolean).join(' · '),
    more: [why, short ? T('tile.hold_short_why', { disk: l.times.disk, need: l.times.need }) : ''].filter(Boolean), ...a });
}

/** «What holds the array?» inside the tile «Array» (1.96 «einfacher»: its own tile before) */
function holdBlock() {
  return holdSection(true);
}
function holdSection(inner) {
  const s = el(inner ? 'div' : 'section', inner ? 'wm-hold wm-hold-block' : 'section wm-hold');
  const b = el('button', 'btn small plain' + (holdRunning() ? ' running' : ''));
  b.type = 'button';
  b.append(el('span', 'spin'), T('hold.check'));
  b.title = T('hold.check_title');
  b.disabled = !Office.agent.running || holdRunning() || !hired();
  b.onclick = () => holdCheck(false);
  if (inner) {
    const head = Office.place('hold.title', el('div', 'wm-subhead'));
    head.append(el('h4', 'wm-hold-group', T('hold.title')), b);
    s.append(head, el('p', 'role', T('hold.sub')));
  } else s.appendChild(Office.sectionHead(T('hold.title'), T('hold.sub'), b, { place: 'hold.title' }));
  const l = holdLook();
  if (!l) {
    s.appendChild(el('p', 'role', T(holdRunning() ? 'hold.looking' : 'hold.never')));
    return s;
  }
  if (!l.started) {
    s.appendChild(el('p', 'role', T('hold.stopped')));
    return s;
  }
  const n = Number(l.count) || 0;
  const t = l.times || {};
  const waiting = holdWaiting(l);
  s.appendChild(el('p', 'callout' + (waiting.names.length + waiting.more || t.fits === false ? ' warn' : ''), [n ? T('hold.verdict_n', { n }) : T('hold.verdict_none'),
    T(t.fits === false ? 'hold.times_short' : 'hold.times_fits', { disk: t.disk, need: t.need, detail: holdNeed(t) })].join(' ')));
  const when = el('p', 'role', T('hold.when', { when: fmt.relative(l.time), ms: Number(l.ms) || 0 }) + (l.cut ? ' ' + T('hold.cut') : ''));
  when.title = fmt.date(l.time);
  s.appendChild(when);
  const group = (key, sub, rows) => {
    s.appendChild(el('h4', 'wm-hold-group', T(key)));
    if (sub) s.appendChild(el('p', 'role', sub));
    const box = el('div', 'box');
    rows.forEach((r) => box.appendChild(r));
    s.appendChild(box);
  };
  const holds = (l.holds || []).filter((h) => h && typeof h.kind === 'string');
  group('hold.holds', T(holds.length ? 'hold.holds_sub' : 'hold.holds_none'), holds.map(holdRow)
    .concat(l.more ? [el('div', 'row nocheck role', T('hold.more_things', { n: l.more }))] : []));
  const vms = (l.vms || []).filter((v) => v && typeof v.name === 'string');
  const cts = Array.isArray(l.containers) ? l.containers.filter((c) => c && typeof c.name === 'string') : null;
  const waits = vms.map((v) => vmRow(v, t)).concat((cts || []).map((c) => ctRow(c, t)));
  if (!vms.length) waits.unshift(el('div', 'row nocheck role', T('hold.no_vms')));
  if (!cts) waits.push(el('div', 'row nocheck role', Office.t('common.docker_off')));
  else if (!cts.length) waits.push(el('div', 'row nocheck role', T('hold.no_containers')));
  group('hold.waits', T('hold.waits_sub', { docker: t.docker, vm: t.vm }), waits);
  const itself = (l.itself || []).filter((x) => x && typeof x.kind === 'string');
  if (itself.length) group('hold.itself', T('hold.itself_sub'), itself.map(itselfRow));
  // ZFS snapshots mounted right now (on the server, inside containers, in other processes) — said, not counted as holding the array
  const sn = l.snaps && typeof l.snaps === 'object' ? l.snaps : null;
  const snCts = sn && Array.isArray(sn.containers) ? sn.containers.filter((c) => c && typeof c.name === 'string' && Number(c.n) > 0) : [];
  if (sn && (Number(sn.host) > 0 || snCts.length || Number(sn.process) > 0)) {
    const snRow = (text, title) => {
      const r = el('div', 'row nocheck');
      const main = el('div', 'row-main');
      const name = el('div', 'row-name text', text);
      name.title = title;
      main.appendChild(name);
      r.appendChild(main);
      return r;
    };
    group('hold.snaps', T('hold.snaps_sub'), (Number(sn.host) > 0 ? [snRow(T('hold.snaps_host', { n: Number(sn.host) }), T('hold.snaps_host_title'))] : [])
      .concat(snCts.map((c) => snRow(T('hold.snaps_container', { name: c.name, n: Number(c.n) }), T('hold.snaps_container_title'))))
      .concat(Number(sn.process) > 0 ? [snRow(T('hold.snaps_process', { n: Number(sn.process) }), T('hold.snaps_process_title'))] : []));
  }
  const outside = (l.outside || []).filter((x) => x && typeof x.mount === 'string');
  if (outside.length) group('hold.outside', T('hold.outside_sub'), outside.map((x) => {
    const r = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', x.mount));
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(T('hold.out_' + (x.kind === 'remote' ? 'remote' : 'ud')), 'quiet', T('hold.out_' + (x.kind === 'remote' ? 'remote' : 'ud') + '_title')));
    if (x.type) meta.appendChild(chip(x.type, '', T('hold.out_type')));
    main.appendChild(meta);
    r.appendChild(main);
    return r;
  }));
  return s;
}

/** «about 140 s: 50 s for the containers + 60 s for the VMs + 30 s to unmount» — only the parts there are */
function holdNeed(t) {
  return [t.containers ? T('hold.need_ct', { s: t.containers }) : '', t.vms ? T('hold.need_vm', { s: t.vms }) : '', T('hold.need_margin', { s: t.margin })]
    .filter(Boolean).join(' + ');
}

/** A thing that holds it, in words (its row's name; the tile's tip names it so too) */
function holdName(h) {
  const kind = ['shell', 'userscript', 'plugin', 'cron', 'loop'].includes(h.kind) ? h.kind : 'program';
  const via = ['ssh', 'web', 'tmux', 'screen'].includes(h.via) ? h.via : 'ssh';
  return kind === 'shell' ? T('hold.k.shell_' + via) : T('hold.k.' + kind, { name: String(h.name || ''), prog: String(h.prog || '') });
}
/** One thing that holds it: what it is, its files there (five at most), what to do */
function holdRow(h) {
  const r = el('div', 'row nocheck wm-hold-row');
  const main = el('div', 'row-main');
  const kind = ['shell', 'userscript', 'plugin', 'cron', 'loop'].includes(h.kind) ? h.kind : 'program';
  main.appendChild(el('div', 'row-name text', holdName(h)));
  const meta = el('div', 'row-meta');
  const known = kind === 'userscript' && sideKnown('script', String(h.name || ''));
  meta.appendChild(known ? chip(T('hold.known'), 'quiet', T('hold.known_title')) : chip(T('hold.holds_chip'), 'warn', T('hold.holds_chip_title')));
  if (kind !== 'loop' && h.prog) meta.appendChild(chip(String(h.prog), '', T('hold.prog_title', { pid: Number(h.pid) || 0 })));
  if (Number(h.procs) > 1) meta.appendChild(chip(T('hold.procs', { n: Number(h.procs) }), '', T('hold.procs_title')));
  if (kind === 'loop' && h.mount) meta.appendChild(chip(T('hold.loop_at', { mount: String(h.mount) }), '', T('hold.loop_at_title')));
  main.appendChild(meta);
  (h.files || []).forEach((f) => { if (typeof f === 'string') main.appendChild(el('div', 'row-detail text wm-hold-file', f)); });
  if (h.more) main.appendChild(el('div', 'row-detail role', T('hold.more_files', { n: Number(h.more) })));
  main.appendChild(el('div', 'row-detail role', T('hold.how.' + kind)));
  r.appendChild(main);
  if (kind === 'userscript' && h.name && !known) r.appendChild(sideButton('hold', 'userscript', String(h.name), ''));
  return r;
}

/**
 * Your exceptions for «What holds the array?» and «Who woke my disks?» (1.96 step 6f; agent/desks/watchman-cats.php
 * watchmanHoldKnown()): a VM without the guest agent, a User Script that may run; a cause of a wake, a disk not counted
 */
function sideKnown(what, name) {
  return ((state && state.rules) || []).some((r) => r && (r.cat === 'hold' || r.cat === 'wakes') && r.on && r.on[what] === name);
}
/** «Known» / «Expected» on a row: the exception by hand, as his setup makes it (listed there, lifted there) */
function sideButton(cat, type, value, second) {
  const b = el('button', 'btn small plain', T(`side.${type}`));
  b.type = 'button';
  b.title = T(`side.${type}_title`);
  b.disabled = !Office.agent.running || !hired();
  b.onclick = async () => {
    if (!(await Office.freshState(ID))) return;
    b.disabled = true;
    const j = await Office.api.post(`${ID}.rule_hand`, { cat, type, value, user: second, sure: false });
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); b.disabled = false; return; }
    if (j.state) state = j.state;
    if (j.rule) justMade.add(j.rule);
    mood();
    Office.keepInPlace(b, () => sw.render());
    Office.toast(T('rule.added'));
  };
  const acts = el('div', 'wm-entry-acts');
  acts.appendChild(b);
  return acts;
}

/** A running VM: its guest agent, how long Unraid waits for it */
function vmRow(v, t) {
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', v.name));
  const meta = el('div', 'row-meta');
  const agent = ['connected', 'disconnected', 'none'].includes(v.agent) ? v.agent : 'none';
  meta.appendChild(chip(T('hold.vm_agent_' + agent), agent === 'connected' ? 'ok' : 'warn', T('hold.vm_agent_' + agent + '_title')));
  if (v.state === 'paused') meta.appendChild(chip(T('hold.vm_paused'), '', T('hold.vm_paused_title')));
  meta.appendChild(chip(T('hold.wait_s', { s: t.vm }), '', T('hold.vm_wait_title', { s: t.vm })));
  main.appendChild(meta);
  const known = agent !== 'connected' && sideKnown('vm', String(v.name));
  if (known) main.appendChild(el('div', 'row-detail role', T('hold.vm_known')));
  else if (agent !== 'connected') main.appendChild(el('div', 'row-detail role', T(v.windows ? 'hold.vm_windows' : 'hold.vm_no_agent', { s: t.vm })));
  r.appendChild(main);
  if (agent !== 'connected' && !known) r.appendChild(sideButton('hold', 'vm', String(v.name), ''));
  return r;
}

/** A running container: Unraid's own or not, how long it gets, whether it asks for more */
function ctRow(c, t) {
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', c.name));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('hold.wait_s', { s: Number(c.gets) || 0 }), '', T(c.managed ? 'hold.ct_unraid_title' : 'hold.ct_compose_title', { s: Number(c.gets) || 0 })));
  if (!c.managed) meta.appendChild(chip(T('hold.ct_compose'), 'quiet', T('hold.ct_compose_title', { s: Number(c.gets) || 0 })));
  if (c.short) meta.appendChild(chip(T('hold.ct_short', { own: Number(c.own) || 0 }), 'warn', T('hold.ct_short_title', { own: Number(c.own) || 0, s: Number(c.gets) || 0 })));
  main.appendChild(meta);
  r.appendChild(main);
  return r;
}

/** What stops by itself: the office's jobs, Unraid's mover, an SMB client's open files, Unraid's local syslog server */
function itselfRow(x) {
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  const files = Number(x.files) || 0;
  let name;
  if (x.kind === 'office') name = T('hold.office', { desk: Office.t(`${x.desk}.name`) });
  else if (x.kind === 'mover') name = T('hold.mover');
  else if (x.kind === 'smb') name = x.machine ? T('hold.smb', { machine: String(x.machine) }) : T('hold.smb_someone');
  else if (x.kind === 'syslog') name = T('hold.syslog');
  else name = T('hold.nfs');
  main.appendChild(el('div', 'row-name text', name));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('hold.itself_chip'), 'ok', T('hold.itself_' + (['office', 'mover', 'smb', 'syslog'].includes(x.kind) ? x.kind : 'nfs') + '_title')));
  if (files) meta.appendChild(chip(T('hold.files_open', { n: files }), '', T('hold.files_open_title')));
  main.appendChild(meta);
  if (x.kind === 'smb' && files) main.appendChild(el('div', 'row-detail role', T('hold.smb_text')));
  r.appendChild(main);
  return r;
}

// ------------------------------------------------------------------ who woke my disks?
/*
 * «Who woke my disks?» (agent/desks/watchman-diskwakes.php; Ms. Protocolli's up to 1.95): every wake of a spinning disk he
 * noticed in the last 7 days — per disk how often (a click shows only its wakes: a filter, never kept, said in the note
 * line) and per wake who he caught waiting for it while it spun up, the surest first; «Not found out» when nobody was. Only
 * rotating disks are watched; on a server without one (all flash: SSDs, NVMe) the tile and its section say so in one line —
 * a hidden tile looked like a bug. Beside «What holds the array?»: both say who touches the disks.
 */
const wakeDisksOpen = new Map(); // «By disk» (2026-11-23): a disk unfolded on this visit → how many of its wakes it shows
const WAKE_DISK_PAGE = 10;       // a disk unfolded shows this many of its wakes, «more» the next as many
let shownWakes = [];            // what «unfold all» unfolds: the disks of «By disk», {open(), set(open)}
const unfoldedWakes = new Set(); // wakes unfolded on this visit
const wakesOf = () => (state && state.wakes && Array.isArray(state.wakes.wakes) ? state.wakes.wakes : []);
/** No rotating disk there (the state says so) and no wake kept from before: nothing to watch — an older messenger's
    state says nothing (no `hdds`), the tile shows as it always did */
const wakesNoHdd = () => !!(state && state.wakes && Array.isArray(state.wakes.hdds) && !state.wakes.hdds.length && !wakesOf().length);
// the wakes' filter bar (core.js Office.filterBar, built once like the book's): its words and the disk chosen are never kept
let wakesBar = null;
function wakesBarGet() {
  wakesBar ??= Office.filterBar({ key: 'watchman.wakes', label: T('wakes.filter_label'), min: WM_FILTER_MIN, onChange: () => { if (view) sw.render(); } });
  return wakesBar;
}
/** Midnight of the state's day (the server looked just now; a kept state counts by its own day) */
const startOfToday = () => { const d = new Date(((state && state.time) || Date.now() / 1000) * 1000); d.setHours(0, 0, 0, 0); return d.getTime() / 1000; };
const wakeHead = (text) => { const h = el('div', 'wm-wake-subhead'); h.appendChild(el('span', 'wm-wake-subhead-name', text)); return h; };

/** What woke it, in words: «Plex (container)», «SMB: laptop», «Scheduled task: …», «Mr. Backupsy (the office)» */
/** A cause as an exception names it: «<kind>:<its name, desk or program>» (watchmanWakeCauseKey()) */
const causeKey = (c) => `${c.kind}:${c.name || c.desk || c.prog || ''}`;
function causeName(c) {
  if (!c) return T('wakes.cause.none');
  const name = c.name || '';
  switch (c.kind) {
    case 'container': return T('wakes.cause.container', { name: name || c.prog });
    case 'vm': return T('wakes.cause.vm', { name: name || c.prog });
    case 'smb': return name ? T('wakes.cause.smb', { name }) : T('wakes.cause.smb_anon');
    case 'nfs': return T('wakes.cause.nfs');
    case 'office': return c.desk && Office.desks.has(c.desk) ? T('wakes.cause.office', { name: Office.t(c.desk + '.name') }) : T('wakes.cause.office_anon');
    case 'mover': return T('wakes.cause.mover');
    case 'cron': return T('wakes.cause.cron', { name: name || c.prog });
    default:
      if (c.prog === 'shfs') return T('wakes.cause.shfs');
      return c.by ? T('wakes.cause.program_by', { name: c.prog, by: starterName(c) }) : T('wakes.cause.program', { name: c.prog });
  }
}

/** Who started a plain program: Unraid, its web page, a plugin, a user script, a terminal (agent/lib/procwho.php procwhoStarter()) */
function starterName(c) {
  switch (c.by) {
    case 'plugin': return T('wakes.by.plugin', { name: c.byname || '?' });
    case 'user_script': return T('wakes.by.user_script', { name: c.byname || '?' });
    default: return T('wakes.by.' + c.by);
  }
}

/** The tile (a number, like «What holds the array?»): the wakes in 7 days and today's — or why there is no number */
function wakesTile(a) {
  const all = wakesOf();
  const w = state && state.wakes;
  let value = '–';
  let sub = [];
  if (!w) sub = [state ? T('tile.wakes_not_yet') : Office.t('common.loading')];
  else if (wakesNoHdd()) sub = [T('tile.wakes_no_hdd')];
  else if (w.on === false) [value, sub] = [T('wakes.tile_off'), all.length ? [T('wakes.tile_week', { n: all.length })] : []];
  else if (!all.length) [value, sub] = [T('tile.wakes_none'), [T('wakes.tile_since', { when: fmt.date(w.since) })]];
  else {
    const today = all.filter((x) => x.time >= startOfToday()).length;
    [value, sub] = [T('tile.wakes_n', { n: all.length }), [T('tile.wakes_week'), today ? T('tile.wakes_today', { n: today }) : ''].filter(Boolean).join(' · ')];
  }
  return lightTile({ label: T('wakes.title'), value, sub, level: catLevel('wakes'), cls: 'wm-wakes-tile', ...a });
}

/** His switch in the section's head: on unless switched off, kept on the server (never in the browser) */
function wakesSwitch() {
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = state.wakes.on !== false;
  cb.disabled = !Office.agent.running;
  label.append(cb, el('span', '', T('wakes.switch')));
  label.title = T('wakes.switch_title');
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.wakes_watch`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    Office.keepInPlace(label, () => (page === 'setup' ? render() : sw.render()));
    Office.toast(T(on ? 'wakes.switched_on' : 'wakes.switched_off'));
  };
  return label;
}

/** Per disk: how often today and in 7 days, and what woke it most often */
function wakesByDisk(all) {
  const by = new Map();
  for (const w of all) {
    for (const d of w.disks) {
      if (!by.has(d)) by.set(d, { today: 0, week: 0, causes: new Map() });
      const x = by.get(d);
      x.week += 1;
      if (w.time >= startOfToday()) x.today += 1;
      const who = trailLeads(w) ? T('wakes.trail_most', { dir: trailTop(trailDirs(w)[0]) }) : causeName(w.who[0]);
      x.causes.set(who, (x.causes.get(who) || 0) + 1);
    }
  }
  return [...by.entries()].sort((p, q) => q[1].week - p[1].week || p[0].localeCompare(q[0]));
}

function wakesView() {
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  const label = () => { unfold.textContent = shownWakes.some((x) => !x.open()) ? T('unfold_all') : T('fold_all'); };
  unfold.onclick = () => {
    const open = shownWakes.some((x) => !x.open());
    Office.keepInPlace(unfold, () => shownWakes.forEach((x) => x.set(open)));
    label();
  };
  shownWakes = [];
  const look = (state && state.wakes && state.wakes.look) || 4;
  const box = Office.place('search.wakes', el('section', 'section wm-wakes'));      // the search's «disk wake-ups, spin-up»
  const known = !!(state && state.wakes);
  const noHdd = wakesNoHdd();
  box.appendChild(Office.sectionHead(T('wakes.title'), T('wakes.sub', { s: look }), ...(known && !noHdd ? [wakesSwitch()] : []), unfold, { place: 'wakes.title' }));
  unfold.hidden = true;
  if (!known || noHdd) {
    // before her first look: not yet / loading; no spinning disk: that one line — no switch, no filter bar, no empty list
    box.appendChild(el('p', 'empty', noHdd ? T('wakes.no_hdd') : state ? T('wakes.not_yet') : Office.t('common.loading')));
    return box;
  }
  if (state.wakes.on === false) box.appendChild(el('p', 'callout', T('wakes.off_note')));
  const all = wakesOf().slice().sort((p, q) => q.time - p.time);
  // by disk: a row each; a click unfolds its wakes right beneath it (like Mr. Backupsy's rows), a second click folds them
  const disks = el('div', 'box wm-wake-disks');
  disks.appendChild(wakeHead(T('wakes.by_disk')));
  for (const [disk, x] of wakesByDisk(all)) disks.appendChild(wakeDiskRow(disk, x, all.filter((w) => w.disks.includes(disk)), look));
  if (all.length) box.appendChild(disks);
  // every wake, newest first (the whole page's list): the bar's words over disks, causes, files, commands; the time by the wake's
  const bar = wakesBarGet();
  box.appendChild(bar.el);
  const f = bar.read();
  const fits = all.filter((w) => Office.fits(f.words, [w.disks.join(' '), ...w.who.map((c) => [causeName(c), T('wakes.sure.' + c.sure), c.prog, c.cmd, ...(c.files || [])].join(' ')), ...(w.cron || []), ...(w.started || []),
      w.who.length ? '' : T('wakes.cause.none'), ...trailDirs(w).map((d) => trailName(w, d))]));
  const list = el('div', 'box');
  list.appendChild(wakeHead(T('wakes.every')));
  for (const w of fits) list.appendChild(wakeRow(w, look));
  if (!fits.length) list.appendChild(el('p', 'empty', all.length ? T('wakes.none_fit') : T('wakes.none')));
  box.appendChild(list);
  bar.done(fits.length, all.length);
  if (state.wakes.on !== false) box.appendChild(el('p', 'role wm-wake-note', T('wakes.note', { when: fmt.date(state.wakes.since), s: look })));
  label();
  unfold.hidden = shownWakes.length < 2;
  return box;
}

/**
 * One disk of «By disk»: its name, today, the week, who woke it most; a click unfolds its wakes right beneath (newest
 * first, the rows of «Every wake», WAKE_DISK_PAGE at a time with «more»), a second click folds them — without a jump
 */
function wakeDiskRow(disk, x, mine, look) {
  const wrap = el('div', 'wm-wake-disk-wrap');
  const r = el('div', 'row nocheck unfolds wm-wake-disk');
  const main = el('div', 'row-main');
  const name = el('div', 'row-name', disk);
  const meta = el('div', 'row-meta');
  if (x.today) meta.appendChild(el('span', '', T('wakes.n_today', { n: x.today })));
  meta.appendChild(el('span', '', T('wakes.n_week', { n: x.week })));
  const [most, n] = [...x.causes.entries()].sort((p, q) => q[1] - p[1])[0];
  meta.appendChild(el('span', 'wm-wake-most', T('wakes.most', { who: most, n })));
  main.append(name, meta);
  r.appendChild(main);
  wrap.appendChild(r);
  let fold = null;
  const draw = () => {
    if (fold) fold.remove();
    fold = el('div', 'wm-wake-disk-fold');
    const shown = wakeDisksOpen.get(disk) || WAKE_DISK_PAGE;
    mine.slice(0, shown).forEach((w) => fold.appendChild(wakeRow(w, look)));
    const rest = mine.length - shown;
    if (rest > 0) {
      const more = el('button', 'btn small plain wm-wake-disk-more', T('book.more', { n: Math.min(rest, WAKE_DISK_PAGE) }));
      more.type = 'button';
      more.onclick = () => Office.keepInPlace(more, () => { wakeDisksOpen.set(disk, shown + WAKE_DISK_PAGE); draw(); });
      fold.appendChild(more);
    }
    wrap.appendChild(fold);
  };
  const set = (open) => {
    if (open && !fold) { if (!wakeDisksOpen.has(disk)) wakeDisksOpen.set(disk, WAKE_DISK_PAGE); draw(); }
    if (!open && fold) { fold.remove(); fold = null; wakeDisksOpen.delete(disk); }
    r.classList.toggle('open', !!fold);
    r.setAttribute('aria-expanded', fold ? 'true' : 'false');
    name.title = fold ? T('wakes.disk_all_title') : T('wakes.disk_only_title', { disk });
  };
  r.onclick = (e) => {
    if (e.target.closest('button, a, input')) return;
    if (String(window.getSelection && window.getSelection()).length) return;      // selecting text
    Office.keepInPlace(r, () => set(!fold));
  };
  shownWakes.push({ open: () => !!fold, set });
  set(wakeDisksOpen.has(disk));
  return wrap;
}

/** A cron line due then: the office's own by the desk whose job it is (office:<job>), else its command */
const JOB_DESK = { backup: 'backup', snapshots: 'snapshot', embycache: 'jack', gather: 'jack', moverelli: 'moverelli' };
function cronName(x) {
  const job = /^office:([a-z]+)$/.exec(x);
  if (!job) return x;
  const desk = JOB_DESK[job[1]];
  return T('wakes.office_job', { name: desk && Office.desks.has(desk) ? Office.t(desk + '.name') : job[1] });
}

/** A time to the second (the trail's «17:56:18–17:56:23») */
function secTime(seconds) {
  const d = new Date(seconds * 1000);
  try { return new Intl.DateTimeFormat(Office.locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(d); } catch (e) { return d.toLocaleTimeString(); }
}

/** The trail's folders (what was written on the woken disk then): their names, the disk said when there are several */
const trailDirs = (w) => (w.trail && Array.isArray(w.trail.dirs) ? w.trail.dirs : []);
/** The trail leads a wake when nobody surer than «maybe» was caught (everyone just waiting, no file of theirs there) */
const trailLeads = (w) => trailDirs(w).length > 0 && (!w.who.length || w.who.every((c) => c.sure === 'maybe'));
/** A trail folder's first two levels for counting by disk («Media/Music videos» of «Media/Music videos/Rock/Season 47») */
const trailTop = (d) => (d.dir ? d.dir.split('/').slice(0, 2).join('/') : T('wakes.trail_top'));
function trailName(w, d) {
  const many = new Set(trailDirs(w).map((x) => x.disk)).size > 1;
  return (many ? d.disk + ': ' : '') + (d.dir || T('wakes.trail_top'));
}

/** One folder of the trail: «Mediathek/Musik-Videos: 12 files (3.1 MB) written 17:56:18–17:56:23» */
function trailLine(w, d) {
  const p = { n: d.n, dir: trailName(w, d), size: fmt.size(d.bytes) };
  if (!d.first) return T('wakes.trail_dir_untimed', p);
  const when = d.last && d.last !== d.first ? `${secTime(d.first)}–${secTime(d.last)}` : secTime(d.first);
  return T('wakes.trail_dir', { ...p, when });
}

/** The trail unfolded: a hint, not proof — its folders, the rest counted, how it was looked for */
function trailBox(w) {
  const box = el('div', 'wm-wake-cause wm-wake-trail');
  const dirs = trailDirs(w);
  if (!dirs.length) {
    box.appendChild(el('p', 'role wm-wake-fact', T('wakes.trail_nothing')));
    return box;
  }
  box.appendChild(el('div', 'wm-wake-cause-head', T('wakes.trail_head')));
  const lines = dirs.map((d) => trailLine(w, d));
  if (w.trail.more) lines.push(T('wakes.trail_more', { n: w.trail.more }));
  box.appendChild(el('pre', 'code wm-wake-files', lines.join('\n')));
  if (w.trail.how !== 'btrfs') box.appendChild(el('p', 'role wm-wake-fact', T('wakes.trail_walk')));
  if (w.trail.cut) box.appendChild(el('p', 'role wm-wake-fact', T('wakes.trail_cut')));
  return box;
}

/** A label: its words and its own tip */
function sureChip(c) {
  return chip(T('wakes.sure.' + c.sure), c.sure === 'sure' || c.sure === 'wrote' ? 'ok' : c.sure === 'maybe' ? 'quiet' : '', T('wakes.sure.' + c.sure + '_title'));
}

/**
 * One wake: when, which disks, the likeliest cause; unfolded its files (its name stands in the head line already),
 * every other cause with its name and files, what was due then, how timed, Unraid's own look said apart
 */
function wakeRow(w, look) {
  const r = el('div', 'row nocheck unfolds wm-wake');
  const main = el('div', 'row-main');
  const name = el('div', 'row-name', `${fmt.date(w.time)} · ${w.disks.join(' + ')}`);
  name.title = T('details');
  const meta = el('div', 'row-meta');
  const first = w.who[0];
  if (first && trailLeads(w)) {
    // only a «maybe» caught (waiting, no file of it on the disk): what was written then says more — it leads, the maybe follows
    const v = el('span', 'wm-wake-trail-verdict', T('wakes.trail_verdict_maybe', { dir: trailName(w, trailDirs(w)[0]) }));
    v.title = T('wakes.trail_verdict_maybe_title');
    meta.append(v, chip(T('wakes.trail_also', { who: w.who.map(causeName).join(', ') }), 'quiet', T('wakes.trail_also_title')));
  } else if (first) {
    meta.appendChild(el('b', 'wm-wake-who', causeName(first)));
    meta.appendChild(sureChip(first));
    if (w.who.every((c) => sideKnown('cause', causeKey(c)))) meta.appendChild(chip(T('wakes.expected'), 'quiet', T('wakes.expected_title')));
    if (w.who.length > 1) meta.appendChild(chip(T('wakes.more', { n: w.who.length - 1 }), '', T('wakes.more_title')));
  } else if (trailDirs(w).length) {
    // nobody caught, but something was written on it just then: that folder, as a hint
    const v = el('span', 'wm-wake-trail-verdict', T('wakes.trail_verdict', { dir: trailName(w, trailDirs(w)[0]) }));
    v.title = T('wakes.trail_verdict_title');
    meta.appendChild(v);
  } else meta.appendChild(chip(T('wakes.cause.none'), 'quiet', T('wakes.cause.none_title')));
  if (w.cut) meta.appendChild(chip(T('wakes.cut'), 'warn', T('wakes.cut_title')));
  main.append(name, meta);
  r.appendChild(main);
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; unfoldedWakes.delete(w.id); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail wm-wake-detail');
      const lead = trailLeads(w);       // the trail stands in the head line: it comes first, every cause with its name
      if (lead) box.appendChild(trailBox(w));
      w.who.forEach((c, i) => {
        const one = el('div', 'wm-wake-cause');
        const head = el('div', 'wm-wake-cause-head');
        if (i > 0 || lead) head.append(el('b', '', causeName(c)), ' ', sureChip(c));
        if (c.kind !== 'program' && c.prog) head.append(i > 0 ? ' ' : '', el('span', 'role', T('wakes.prog', { name: c.prog })));
        if (head.childNodes.length) one.appendChild(head);
        if (!sideKnown('cause', causeKey(c)) && (c.name || c.desk || c.prog)) one.appendChild(sideButton('wakes', 'cause', causeKey(c).slice(c.kind.length + 1), c.kind));
        if (c.files && c.files.length) one.appendChild(el('pre', 'code wm-wake-files', c.files.join('\n')));
        else if (c.cmd) one.appendChild(el('pre', 'code wm-wake-files', c.cmd));
        box.appendChild(one);
      });
      if (!w.who.length) box.appendChild(el('p', 'role', T('wakes.cause.none_title')));
      if (w.trail && !lead) box.appendChild(trailBox(w));
      const facts = [];
      if (w.unraid) facts.push(T('wakes.unraid'));
      if (w.cron && w.cron.length) facts.push(T('wakes.cron_due', { list: w.cron.map(cronName).join(' · ') }));
      if (w.started && w.started.length) facts.push(T('wakes.started', { list: w.started.join(', ') }));
      facts.push(w.how === 'caught' ? T(w.who.length ? 'wakes.how.caught' : 'wakes.how.asked') : w.how === 'syslog' ? T('wakes.how.syslog')
        : T('wakes.how.noticed', { from: fmt.date(w.from), to: fmt.date(w.time) }));
      if (w.smart && w.how === 'caught') facts.push(T('wakes.smart', { when: fmt.date(w.smart) }));
      if (!w.named) facts.push(T('wakes.naming'));
      for (const x of facts) box.appendChild(el('p', 'role wm-wake-fact', x));
      r.appendChild(box);
      unfoldedWakes.add(w.id);
      r.classList.add('open');
    }
  };
  r.onclick = (e) => {
    if (e.target.closest('button, a, input, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;      // selecting text
    Office.keepInPlace(r, () => set(!box));
  };
  if (unfoldedWakes.has(w.id)) set(true);        // («unfold all» unfolds the disks of «By disk», 2026-11-23)
  return r;
}

// ------------------------------------------------------------------ what he keeps an eye on
/**
 * One thing he keeps an eye on: its name, its sub lines, its chips (flags()). Its hay for the list's filter (wmHay, built
 * here once per drawing): the name, every sub line, the chips' words — what the row shows, nothing more.
 */
function item(name, parts, extra, label) {
  const r = el('div', 'row nocheck wm-item');
  r.wmHay = [name, ...parts.filter(Boolean), ...((extra && extra.wmWords) || [])];
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name' + (label ? ' text' : ''), name));
  const meta = el('div', 'row-meta');
  parts.filter(Boolean).forEach((x) => meta.appendChild(el('span', '', x)));
  if (extra) meta.appendChild(extra);
  if (meta.children.length) main.appendChild(meta);
  r.appendChild(main);
  return r;
}

/**
 * What the filter words leave of a group (pure: tests/watchfilter.js). Words in its head — title, summary and the
 * group's own extra words («Docker» for the two container groups) — keep the whole group as it is; else each item (a row
 * with a hay: item()) fits on its own words together with the head (the words may fall on both: «container immich»), a
 * row without one (a note, a link, a switch) goes. A group with nothing left is not shown. No words: everything.
 *   head: [title, summary, …], hays: per row its hay or null → {shown, whole, rows: [bool per row], n (items that fit), total (items)}
 */
function groupFit(words, head, hays) {
  const total = hays.filter(Boolean).length;
  if (!words || !words.length || Office.fits(words, head)) return { shown: true, whole: true, rows: hays.map(() => true), n: total, total };
  const rows = hays.map((h) => !!h && Office.fits(words, [...head, ...h]));
  const n = rows.filter(Boolean).length;
  return { shown: n > 0, whole: false, rows, n, total };
}
/** The whole list's count from its groups' fits: {n, total, shown (groups shown)} — the bar's «n of m fit» */
const fitTally = (fits) => fits.reduce((a, f) => ({ n: a.n + f.n, total: a.total + f.total, shown: a.shown + (f.shown ? 1 : 0) }), { n: 0, total: 0, shown: 0 });
/**
 * Is a group open? Without words as kept in this browser (opened: 'watchman.groups'); with words every group shown is
 * unfolded, unless folded by hand meanwhile (shut) — the kept state stays untouched, «Show all» brings it back
 */
const groupOpen = (key, words, opened, shut) => ((words && words.length) ? !shut.has(key) : !!opened[key]);

/**
 * A tinted title bar that folds its rows (closed unless opened, remembered in this browser). With filter words
 * (watchWords) only its fitting rows (groupFit(); more: words the group is found by besides its title and summary),
 * unfolded, its summary followed by «n of m fit»; box.wmFit says what was left — the section leaves out a group with nothing.
 */
function group(key, title, sum, rows, more) {
  const fit = groupFit(watchWords, [title, sum, ...(more || [])], rows.map((x) => x.wmHay || null));
  const words = watchWords.length > 0;
  const box = Office.place(`watch:${key}`, el('div', 'group' + (groupOpen(key, watchWords, openGroups, watchShut) ? '' : ' closed')));
  box.wmFit = fit;
  box.wmKey = key;
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');
  const mid = el('div', 'group-mid');
  const tl = el('div', 'group-title');
  tl.appendChild(el('span', '', T('inv.title', { what: sum })));     // «Known: 12 addresses» — what it is beneath
  mid.append(tl, el('div', 'group-meta', words && !fit.whole ? `${title} · ${Office.t('common.filter_hits', { n: fit.n, total: fit.total })}` : title));
  head.append(el('span', 'group-arrow', '▼'), mid);
  const body = el('div', 'group-rows');
  const drawn = rows.filter((x, i) => fit.rows[i]);
  drawn.forEach((x) => body.appendChild(x));
  const set = (open) => {
    box.classList.toggle('closed', !open);
    if (words) {             // folded or unfolded while the words are typed: for as long as they are
      if (open) watchShut.delete(key); else watchShut.add(key);
      return;
    }
    if (open) openGroups[key] = true; else delete openGroups[key];
    Office.storeJson('watchman.groups', openGroups);
  };
  const toggle = () => Office.keepInPlace(head, () => set(box.classList.contains('closed')));
  head.onclick = (e) => { toggle(); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggle(); } };
  box.append(head, body);
  if (!drawn.length) head.classList.add('wm-empty-group');
  return box;
}

/**
 * What an area knows (CAT_KNOWN): its groups of what is normal, each a fold «Known: …» in the area's section — the
 * area's filter words (the bar over its entries) find in them too, unfolding what fits. Null: the area has none, or he
 * hasn't taken over yet (his first round records it).
 */
function knownFolds(id, words) {
  const keys = [...new Set((TILE_PARTS[id] ? tileParts(id) : [id]).flatMap((c) => CAT_KNOWN[c] || []))];
  if (!keys.length || !state || !state.on_watch) return null;
  const w = state.watch;
  watchWords = words || [];
  const gs = keys.map((k) => knownGroup(k, w)).filter(Boolean);
  watchWords = [];
  const shown = gs.filter((g) => g.wmFit.shown);
  if (!shown.length) return null;
  const box = el('div', 'box wm-watchlist wm-known-inv');
  shown.forEach((g) => box.appendChild(g));
  return box;
}

/** One group of what he knows, by its key (null: nothing of it here — no failing addresses, no partner) */
function knownGroup(key, w) {
  if (!w && !['net', 'snaps', 'flow_clients', 'flow_containers', 'flow_shares', 'flow_gone', 'flow_smb'].includes(key)) return null;
  switch (key) {
    case 'ips':
      return group('ips', T('watch.ips'), T('watch.ips_sum', { n: w.ips.length }),
        w.ips.map((x) => item(x.ip, [(x.users || []).join(', '), (x.services || []).join(', '), T('watch.last_seen', { when: fmt.relative(x.last) })])));
    case 'fail_ips':
      return w.fail_ips.length ? group('fail_ips', T('watch.fail_ips'), T('watch.fail_ips_sum', { n: w.fail_ips.length }),
        w.fail_ips.map((x) => item(x.ip, [T('watch.since', { when: fmt.date(x.since) }), x.quiet ? T('watch.quiet', { n: x.quiet }) : '',
          T('watch.last_seen', { when: fmt.relative(x.last) })]))) : null;
    case 'containers': {
      const c = w.containers;
      const cSum = c ? T('watch.containers_sum', { special: c.special.length, count: c.count, n: Number(c.count) || 0 }) : '';
      return group('containers', T('watch.containers'),
        !c ? T('watch.containers_wait') : dockerOff() ? T('watch.containers_last', { docker: Office.t('common.docker_off'), sum: cSum }) : cSum,
        c ? c.special.map((x) => item(x.name, [], flags(x.tokens))) : [], ['Docker']);
    }
    case 'plugins':
      return group('plugins', T('watch.plugins'), T('watch.plugins_sum', { n: w.plugins.length }),
        w.plugins.map((x) => item(x.name, [x.source || T('watch.no_source'), x.version || ''])));
    case 'flash': {
      const f = w.flash || { extra: [], users: [], keys: [] };
      const flash = [
        item(T('watch.go'), [f.go ? T('watch.go_lines', { n: f.go.lines }) : T('watch.go_none')]),
        item(T('watch.extra'), f.extra.length ? f.extra.map((x) => `${x.file} · ${fmt.size(x.size)}`) : [T('watch.extra_none')]),
        item(T('watch.users'), [f.users.join(', ')], null, true),
      ];
      if (f.keys.length) f.keys.forEach((k) => flash.push(item(T('watch.keys'), [k.user, [k.type, k.comment].filter(Boolean).join(' '), k.fp], null, true)));
      else flash.push(item(T('watch.keys'), [T('watch.keys_none')], null, true));
      return group('flash', T('watch.flash'), T('watch.flash_sum', { extra: f.extra.length,
        users: T('watch.flash_users', { n: f.users.length }), keys: T('watch.flash_keys', { n: f.keys.length }) }), flash);
    }
    case 'shares': {
      const sh = w.shares;
      return group('shares', T('watch.shares'),
        !sh ? T('watch.shares_wait') : sh.open.length ? T('watch.shares_sum', { open: sh.open.length, count: sh.count }) : T('watch.shares_none'),
        sh ? sh.open.map((x) => item(x.share, [x.smb ? `SMB: ${level(x.smb)}` : '', x.nfs ? `NFS: ${level(x.nfs)}` : ''])) : []);
    }
    case 'sched': return schedGroup(w.sched);
    case 'host': return hostGroup(w.host, 'host');
    case 'doors': return hostGroup(w.host, 'doors');
    case 'partner': return w.partner && (w.partner.pairs.length || w.partner.strays.length) ? partnerGroup(w.partner) : null;
    case 'net': return netGroup(state.net);
    case 'snaps': return snapGroup(state.snaps);
    default: {      // the data flow's groups
      const f = state.flow;
      if (!f) return key === 'flow_clients' || key === 'flow_shares' ? group(key, T('watch.' + key), T('watch.flow_wait'), []) : null;
      return flowGroups(f).find((g) => g.wmKey === key) || null;
    }
  }
}

/** What starts on its own: root's own crontab line by line, the .cron files, User Scripts, at, the notification agents */
function schedGroup(s) {
  if (!s) return group('sched', T('watch.sched'), T('watch.sched_wait'), []);
  const rows = [];
  // root's own lines as they are; another user's or /etc/cron.d's other files carry their place in front ("cron.d/x: …")
  (s.crontab || []).forEach((x) => rows.push(item(x, [/^[\w.-]+(\/[^:]+)?: /.test(x) ? '' : T('watch.crontab')])));
  if (s.twice) rows.push(item(T('watch.crontab'), [T('watch.crontab_twice', { n: s.twice })], null, true));
  (s.files || []).forEach((x) => rows.push(item(x.file, ['.cron', T('watch.cron_lines', { n: x.lines })])));
  (s.scripts || []).forEach((x) => rows.push(item(x.name, [T('watch.script'), freq(x.cron)])));
  if (s.at) rows.push(item(T('watch.at'), [T('watch.at_sum', { n: s.at })], null, true));
  (s.agents || []).forEach((x) => rows.push(item(x, [T('watch.agent')])));
  const sum = T('watch.sched_sum', { lines: (s.crontab || []).length, files: (s.files || []).length,
    scripts: (s.scripts || []).length, agents: (s.agents || []).length });
  return group('sched', T('watch.sched'), sum, rows);
}

/**
 * The server itself (part 'host': the ports programs listen on, programs from odd places he knows, its accounts) or its
 * ways in (part 'doors': what Unraid's settings open, Unraid's API keys — name and roles, never more of them)
 */
function hostGroup(h, part) {
  const doors = part === 'doors';
  if (!h) return group(doors ? 'doors' : 'host', T(doors ? 'watch.doors' : 'watch.host'), T('watch.host_wait'), []);
  const rows = [];
  if (doors) {
    h.doors.forEach((d) => rows.push(item(doorWords(d), [T('watch.door')], null, true)));
    (h.api || []).forEach((k) => rows.push(item(k.name, [T('watch.api_key'), (k.roles || []).join(', ') || T('detail.roles_none'),
      k.full ? T('watch.api_full') : ''])));
    return group('doors', T('watch.doors'), T('watch.doors_sum', { doors: h.doors.length, keys: (h.api || []).length }), rows);
  }
  h.listen.forEach((l) => rows.push(item(l.port === null || l.port === undefined ? `${l.prog} (${T('detail.port_dynamic')})` : `${l.port} · ${l.prog}`,
    [T('watch.port'), (l.addr || []).map((a) => (a === '*' ? T('detail.addr_all') : a)).join(', ')])));
  h.procs.forEach((x) => rows.push(item(x.exe, [x.prog, x.where || T('where.host')])));
  return group('host', T('watch.host'), T('watch.host_own_sum', { ports: h.listen.length, procs: h.procs.length, users: h.users }), rows);
}

/** The partner offices' doors: per pair whether its line is there as the office wrote it; lines of no pair */
function partnerGroup(x) {
  const rows = [];
  x.pairs.forEach((p) => rows.push(item(p.name, [
    p.address,
    !p.door ? T('watch.partner_no_door') : T('watch.partner_line_' + (['ok', 'changed', 'missing'].includes(p.line) ? p.line : 'missing')),
    [p.receives ? T('watch.partner_receives') : '', p.sends ? T('watch.partner_sends') : ''].filter(Boolean).join(' · '),
  ])));
  x.strays.forEach((id) => rows.push(item(`uso-partner:${id}`, [T('watch.partner_stray')], null, true)));
  const sum = T('watch.partner_sum', { n: x.pairs.length }) + (x.transfers ? ' · ' + T('watch.partner_transfers', { n: x.transfers }) : '');
  return group('partner', T('watch.partner'), sum, rows);
}

/**
 * The network: Unraid's syslog server and its senders (the router's name and version from its lines, the last line's
 * age, lines a day, lines not understood), devices known, detections this week (other devices only counted), what the
 * router blocked from the server, what isn't in the book; the «Whole LAN» switch and its counts. Never another
 * device's address (the agent sends none here).
 */
function netGroup(n) {
  if (!n) return group('net', T('watch.net'), T('watch.net_wait'), []);
  const rows = [];
  const toAdvisor = () => {
    const a = el('a', '', T('watch.net_to_advisor'));
    a.href = '#/advisor';
    return a;
  };
  const say = (text, link) => {
    const p = note(text);
    if (link) p.append(' ', toAdvisor());
    rows.push(p);
  };
  const stateText = { off: 'watch.net_off', asleep: 'watch.net_asleep', array: 'watch.net_array', unknown: 'watch.net_unknown', unreadable: 'watch.net_unreadable' };
  if (stateText[n.state]) say(T(stateText[n.state], { folder: (n.cfg && n.cfg.folder) || '' }), n.state === 'off');
  if (n.cfg && n.cfg.folder && n.state !== 'off') rows.push(note(T('watch.net_folder', { folder: n.cfg.folder })));
  const senders = n.senders || [];
  if (n.state === 'on' && !senders.length) {
    const c = el('p', 'callout wm-net-listening', T('watch.net_listening'));
    c.append(' ', toAdvisor());
    rows.push(c);
  }
  senders.forEach((x) => rows.push(item(x.host ? `${x.host} · ${x.sender}` : x.sender, [
    x.product ? `${x.product} ${x.version || (x.ros ? T('watch.net_version_unknown') : '')}`.trim() : '',
    x.last ? T('watch.net_last', { when: fmt.relative(x.last) }) : '',
    T('watch.net_per_day', { n: x.per_day || 0 }),
    x.other_pct > 0 ? T('watch.net_other', { pct: fmt.number(x.other_pct) }) : '',
    x.prefix_other ? T('watch.net_prefix_other', { n: x.prefix_other }) : '',
  ])));
  // a MikroTik sending without RouterOS's topics (the default format, or the syslog format without add-topics-string)
  senders.filter((x) => x.ros && x.ros_format).forEach((x) => say(T('watch.net_ros_format', { router: x.host || x.sender }), true));
  if (senders.length) {
    rows.push(item(T('watch.net_counts', { devices: n.devices, admins: n.admins, vpn: n.vpn }), [], null, true));
    const d = n.detections || {};
    rows.push(item(senders.some((x) => x.detections) ? T('watch.net_detections', { in: d.in || 0, out: d.out || 0, other: d.other || 0 })
      : T('watch.net_no_detections'), [], null, true));
    const b = n.blocked || { week: 0, top: [] };
    if (senders.some((x) => x.firewall) || b.week) {
      rows.push(item(T('watch.net_blocked', { n: b.week || 0 }), (b.top || []).map((k) => T('watch.net_blocked_key', { key: k.key, n: k.n })), null, true));
    } else {
      rows.push(item(T('watch.net_no_firewall'), [], null, true));
    }
    const dr = n.dropped || {};
    if (dr.update || dr.wan) rows.push(item(T('watch.net_dropped', { update: T('count.updates', { n: dr.update || 0 }), wan: T('count.wan_changes', { n: dr.wan || 0 }) }), [], null, true));
  }
  if ((n.own || []).length) rows.push(note(T('watch.net_own', { list: n.own.join(', ') })));
  if (n.skipped) rows.push(note(T('watch.net_skipped', { size: fmt.size(n.skipped) })));
  if (n.more) rows.push(note(T('watch.net_more', { n: n.more })));
  if (senders.length) rows.push(lanSwitch());
  (n.lan || []).forEach((x) => rows.push(item(x.name || x.mac, [T('watch.net_lan_row', { c: T('count.connections', { n: Number(x.connects) || 0 }), d: T('count.detections', { n: Number(x.detections) || 0 }) })])));
  if (state.net_lan && !(n.lan || []).length) rows.push(note(T('watch.net_lan_none')));
  const det = n.detections || {};
  // the head says the state while the files aren't read (asleep …), never the last counts as now (W9)
  const sum = senders.length && !stateText[n.state] ? T('watch.net_sum', { senders: senders.length, devices: n.devices, det: (det.in || 0) + (det.out || 0) })
    : T(stateText[n.state] || 'watch.net_listening', { folder: (n.cfg && n.cfg.folder) || '' });
  return group('net', T('watch.net'), sum, rows);
}

/**
 * «Known vulnerabilities» switched off, in its section: what he checks once on — which public lists, what of this server
 * he compares, that nothing of it goes along, what he says — and the switch right there (Benj: «welcher Klick wo?»)
 */
function vulnOffBox() {
  const box = el('div', 'callout wm-vuln-off');
  box.append(el('p', '', T('cat.why.vuln_off')), el('p', '', T('vuln.explain_lists')), el('p', '', T('vuln.explain_here')),
    el('p', '', T('vuln.explain_says')), el('p', '', T('vuln.limits')));      // his limits: «nothing found» is never «safe»
  const b = vulnButton();
  b.className = 'btn small wm-vuln-on';
  box.appendChild(b);
  return box;
}
/**
 * «Known vulnerabilities» beyond what waits (1.96 «einfacher»): what he compares with (CISA's list, once in its head), and
 * — folded, with its count — «Checked: doesn't concern you»: the programs here a listed vulnerability is about, whose
 * version is already fixed or was never affected. The calm proof that he looked (state.vuln.not, the messenger's).
 */
/** The calm lists of «Known vulnerabilities» (checked: doesn't concern you; can't be checked for sure): their lines */
const vulnLines = (list) => (Array.isArray(list) ? list : []).filter((x) => x && typeof x.cve === 'string');
/** A line's words for the area's filter: the vulnerability, the product, its name, where it runs, the version */
const vulnHay = (x) => [x.cve, x.label || '', x.product || '', x.name || '', x.where && x.where !== 'host' ? x.where : '', x.have || ''];
/** How many lines the calm lists hold (the area's filter counts them with what waits), «What he checked» with them */
const vulnListCount = () => (state && state.vuln && state.vuln.on !== false
  ? vulnLines(state.vuln.not).length + vulnLines(state.vuln.unsure).length + vulnLookItems(state.vuln).length : 0);
/** An unsure line's reason (2026-11-25): a bound coarser than the version, a release line past its end then, or the NVD's word */
const vulnUnsureWhy = (x) => (x.why === 'rough' && x.bound ? T('vuln.unsure_why_rough', { bound: x.bound })
  : x.why === 'eol' && x.bound ? T('vuln.unsure_why_eol', { line: x.bound }) : T(x.nvd ? 'vuln.unsure_nvd_asked' : 'vuln.unsure_nvd_wait'));
/**
 * «What he checked» (2026-11-25, agent: watchmanVulnLook()): its lines — this server's programs, the containers read, those
 * not read, the routers — each with the words the area's filter meets it by. None in an older state (host null).
 */
function vulnLookItems(v) {
  if (!v || !Array.isArray(v.host)) return [];
  const str = (x) => (typeof x === 'string' ? x : '');
  const unknown = new Set(Array.isArray(v.unknown) ? v.unknown : []);
  return [
    ...v.host.filter((x) => x && typeof x.version === 'string').map((x) => ({ part: 'host', x, hay: [str(x.label), str(x.product), x.version] })),
    ...(Array.isArray(v.cts) ? v.cts : []).filter((x) => x && typeof x.where === 'string').map((x) => ({ part: 'ct', x, hay: [x.where, str(x.label), str(x.product), str(x.version)] })),
    ...(Array.isArray(v.unread) ? v.unread : []).filter((n) => typeof n === 'string').map((n) => ({ part: unknown.has(n) ? 'none' : 'unasked', x: n, hay: [n] })),
    ...(Array.isArray(v.routers) ? v.routers : []).filter((x) => x && typeof x.label === 'string').map((x) => ({ part: 'router', x, hay: [x.label, str(x.version)] })),
  ];
}
/** A router's line: the version its own log said, and since when — or plainly that it hasn't said one yet */
const vulnRouterWords = (x) => (!x.version ? T('vuln.router_unknown', { product: x.label })
  : x.at ? T('vuln.router_seen', { product: x.label, version: x.version, date: fmt.date(x.at) }) : T('vuln.router_seen_undated', { product: x.label, version: x.version }));

/**
 * What he looked at and the calm lists, under what waits: the list he compares with, how many of its entries concern
 * programs here (by product) and how many records are still to come (2026-11-23: an empty result must be believable);
 * then «Checked: doesn't concern you» and «Can't be checked for sure», folded — the area's filter words search them too
 * (CVE, product, name, where), and with words a list holding hits stands open. → {el, shown}
 */
function vulnLists(words) {
  const v = state && state.vuln;
  if (!v || v.on === false) return null;
  const box = el('div', 'wm-vuln-lists');
  vulnDownLines().forEach((x) => box.appendChild(x));
  if (Number.isInteger(v.kev_total)) {
    const prods = (Array.isArray(v.kev_products) ? v.kev_products : []).filter((x) => x && Number(x.n) > 0).sort((a, b) => b.n - a.n || String(a.label).localeCompare(String(b.label)));
    const here = Number(v.kev_here) || 0;
    const line = here ? T('vuln.counted', { n: here, total: fmt.number(v.kev_total), list: prods.map((x) => `${x.label} ${x.n}`).join(' · ') })
      : T('vuln.counted_none', { total: fmt.number(v.kev_total) });
    box.appendChild(el('p', 'role wm-vuln-counted', [line, v.pending ? T('vuln.pending', { n: v.pending }) : ''].filter(Boolean).join(' ')));
  }
  // his limits (2026-11-25), always there, calm: he compares only with what is listed, only for what he knows and can read
  box.appendChild(el('p', 'wm-vuln-limits', T('vuln.limits')));
  // the routers by their own log (2026-11-23): under «What he checked» since 2026-11-25 — an older state without it keeps them here
  if (!Array.isArray(v.host)) {
    (Array.isArray(v.routers) ? v.routers : []).filter((x) => x && typeof x.label === 'string')
      .forEach((x) => box.appendChild(el('p', 'role wm-vuln-router', vulnRouterWords(x))));
  }
  if (Number.isInteger(v.kev_total)) box.appendChild(el('p', 'wm-vuln-nvd-note', T('vuln.nvd_notice')));     // NVD's terms of use: this notice, as they word it
  const where = (x) => (x.where && x.where !== 'host' ? T('vuln.not_in', { where: x.where }) : '');
  const fit = (list) => vulnLines(list).filter((x) => !words || !words.length || Office.fits(words, vulnHay(x)));
  const checked = fit(v.not);
  const not = checked.filter((x) => !x.ancient);
  const old = checked.filter((x) => x.ancient);           // fixed long before anything the office runs on: folded apart
  const unsure = fit(v.unsure);
  const forced = !!(words && words.length);
  const notRow = (x) => T(x.fixed ? 'vuln.not_row' : 'vuln.not_row_never', { product: x.label || x.product, cve: x.cve, fixed: x.fixed || '', have: x.have || '', where: where(x) });
  const nvdSaid = (x) => (x.src === 'nvd' ? T('vuln.src_nvd') : '');
  vulnFold(box, 'vuln.not', not, forced, notRow, nvdSaid);
  vulnFold(box, 'vuln.old', old, forced, notRow, nvdSaid, { floor: v.floor || '7.3.2' });
  vulnFold(box, 'vuln.unsure', unsure, forced, (x) => T('vuln.unsure_row', { product: x.label || x.product, cve: x.cve, have: x.have || '', where: where(x) }),
    vulnUnsureWhy);
  const look = vulnLookItems(v).filter((i) => !forced || Office.fits(words, i.hay));
  vulnLookFold(box, v, look, forced);
  return { el: box, shown: checked.length + unsure.length + look.length };
}

/**
 * «What he checked» (2026-11-25), folded like the calm lists: this server's programs, the containers — how many had a
 * version he could read, those he couldn't by name, and why —, the routers, and plainly what he never looks at (VMs, the
 * other devices, inside containers, programs not on his list, the plugins themselves). The filter's words find a container
 * here too; with words only the lines they hit, the fold held open.
 */
function vulnLookFold(box, v, items, forced) {
  if (!Array.isArray(v.host) || (forced && !items.length)) return;
  const key = 'vuln.look';
  const open = forced || knownOpen.has(key);
  const total = Number.isInteger(v.ct_total) ? v.ct_total : null;
  const ctLine = total ? T('vuln.look_ct', { n: total, read: Number(v.ct_read) || 0 }) : '';
  const head = el('button', 'btn small plain wm-known-head', `${open ? '▾' : '▸'} ${[T('vuln.look_title'), ctLine].filter(Boolean).join(' · ')}`);
  head.type = 'button';
  head.setAttribute('aria-expanded', open ? 'true' : 'false');
  head.disabled = forced;
  head.onclick = () => { if (open) knownOpen.delete(key); else knownOpen.add(key); Office.keepInPlace(head, () => sw.render()); };
  box.appendChild(head);
  if (!open) return;
  const body = el('div', 'wm-vuln-look');
  body.appendChild(el('p', 'role', T('vuln.look_sub')));
  const rows = (list, words) => {
    if (!list.length) return;
    const b = el('div', 'box');
    list.forEach((i) => {
      const r = el('div', 'row nocheck wm-vuln-not');
      const main = el('div', 'row-main');
      main.appendChild(el('div', 'row-name text', words(i.x)));
      r.appendChild(main);
      b.appendChild(r);
    });
    body.appendChild(b);
  };
  const part = (p) => items.filter((i) => i.part === p);
  const names = (list) => list.map((i) => i.x).join(', ');
  const host = part('host');
  if (host.length) {
    body.appendChild(el('h4', 'wm-scope-head', T('vuln.look_host')));
    rows(host, (x) => T('vuln.look_host_row', { product: x.label || x.product, version: x.version }));
  }
  const cts = part('ct');
  const none = part('none');
  const unasked = part('unasked');
  if (!forced || cts.length || none.length || unasked.length) {
    body.appendChild(el('h4', 'wm-scope-head', T('vuln.look_cts')));
    if (total === null) body.appendChild(el('p', 'role', T('vuln.look_ct_none')));
    else if (!total) body.appendChild(el('p', 'role', T('vuln.look_ct_zero')));
    else if (!forced) body.appendChild(el('p', 'role', ctLine));
    rows(cts, (x) => T('vuln.look_ct_row', { where: x.where, product: x.label || x.product, version: x.version }));
    // the unread by reason: the image names none of the names he reads, or he couldn't read the image this time; past the
    // list's cap (the state keeps WATCH_VULN_LOOK_MAX names) only how many more
    if (none.length) body.appendChild(el('p', 'role', T('vuln.look_unread_none', { n: none.length, names: names(none) })));
    if (unasked.length) body.appendChild(el('p', 'role', T('vuln.look_unread_unasked', { n: unasked.length, names: names(unasked) })));
    const more = forced ? 0 : Math.max(0, (Number(v.unread_n) || 0) - none.length - unasked.length);
    if (more) body.appendChild(el('p', 'role', T('vuln.look_unread_more', { n: more })));
    if (none.length || unasked.length || more) body.appendChild(el('p', 'role', T('vuln.look_inside')));
  }
  const routers = part('router');
  if (routers.length || !forced) {
    body.appendChild(el('h4', 'wm-scope-head', T('vuln.look_routers')));
    if (routers.length) rows(routers, vulnRouterWords);
    else body.appendChild(el('p', 'role', T('vuln.look_routers_none')));
  }
  if (!forced) {
    body.appendChild(el('h4', 'wm-scope-head', T('vuln.look_not')));
    const ul = el('ul', 'wm-scope-list');
    const vms = Number.isInteger(v.vms) ? v.vms : null;
    [vms === null ? T('vuln.look_not_vm') : vms === 0 ? T('vuln.look_not_vm_none') : T('vuln.look_not_vm_n', { n: vms }),
      T('vuln.look_not_net'), T('vuln.look_not_inside'), T('vuln.look_not_other'), T('vuln.look_plugins')]
      .forEach((x) => ul.appendChild(el('li', '', x)));
    body.appendChild(ul);
  }
  box.appendChild(body);
}
/**
 * A source that hasn't answered for days (2026-11-23): one calm line each — which, since when, when he tries again, and
 * what stays as it is meanwhile; never a colour, never told (agent: watchmanVulnDown(), after WATCH_VULN_DOWN_FAILS)
 */
function vulnDownLines() {
  const v = state && state.vuln;
  if (!v || v.on === false || !Array.isArray(v.down)) return [];
  const now = Number(state.time) || Date.now() / 1000;      // his state's time (the round's clock)
  return v.down.filter((x) => x && Office.has(`${ID}.vuln.source.${x.src}`)).map((x) => {
    const days = Math.max(1, Math.round((now - Number(x.since)) / 86400));
    const said = [T('vuln.down', { source: T(`vuln.source.${x.src}`), n: days, when: x.next ? fmt.date(x.next) : '–' })];
    const unsure = vulnLines(v.unsure).length;
    if (x.src === 'nvd' && unsure) said.push(T('vuln.down_nvd', { n: unsure }));
    else if (x.src === 'cve') said.push(T('vuln.down_cve'));
    else if (x.ok && Office.has(`${ID}.vuln.down_${x.src}`)) said.push(T(`vuln.down_${x.src}`, { date: fmt.date(x.ok) }));
    return el('p', 'role wm-vuln-down', said.join(' '));
  });
}

/** One calm list, folded: its head with the count, unfolded (or holding the filter's hits) its sentence and a row each */
function vulnFold(box, key, list, forced, words, more, params) {
  if (!list.length) return;
  const open = forced || knownOpen.has(key);
  const head = el('button', 'btn small plain wm-known-head', `${open ? '▾' : '▸'} ${T(`${key}_title`, { ...(params || {}), n: list.length })}`);
  head.type = 'button';
  head.setAttribute('aria-expanded', open ? 'true' : 'false');
  head.disabled = forced;          // the filter's words hold it open
  head.onclick = () => { if (open) knownOpen.delete(key); else knownOpen.add(key); Office.keepInPlace(head, () => sw.render()); };
  box.appendChild(head);
  if (!open) return;
  box.appendChild(el('p', 'role', T(`${key}_sub`, params || {})));
  const rows = el('div', 'box');
  list.forEach((x) => {
    const r = el('div', 'row nocheck wm-vuln-not');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', words(x)));
    const meta = [x.name || '', more ? more(x) : ''].filter(Boolean).join(' · ');
    if (meta) main.appendChild(el('div', 'row-meta', meta));
    r.appendChild(main);
    rows.appendChild(r);
  });
  box.appendChild(rows);
}
/** One click switches «Known vulnerabilities» on (the public lists once a day; off again in his setup) */
function vulnButton() {
  const b = el('button', 'btn plain wm-vuln-on', T('vuln.switch_on'));
  b.type = 'button';
  b.title = T('vuln.switch_on_title');
  b.disabled = !Office.agent.running;
  b.onclick = async () => {
    b.disabled = true;
    const j = await Office.api.post(`${ID}.vuln_set`, { on: true });
    if (!j.ok) {
      b.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    render();
    Office.toast(T('vuln.on'));
  };
  return b;
}

/** The «Whole LAN» switch: per device on the LAN how often it connected and how many detections named it — counts, off by default */
function lanSwitch() {
  const label = el('label', 'switch wm-net-lan');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = !!state.net_lan;
  cb.disabled = !Office.agent.running || !hired();
  label.append(cb, el('span', '', T('watch.net_lan_switch')));
  label.title = T('watch.net_lan_title');
  label.dataset.own = '1';
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.net_lan_set`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    if (view) Office.keepInPlace(label, render);
    Office.toast(T(on ? 'watch.net_lan_on' : 'watch.net_lan_off'));
  };
  const row = el('div', 'row nocheck wm-item');
  row.appendChild(label);
  return row;
}

/** The snapshots he follows: per pool and disk how many, which sleep (compared once awake), the series you taught him */
function snapGroup(s) {
  if (!s) return group('snaps', T('watch.snaps'), T('watch.snaps_wait'), []);
  const rows = [];
  let total = 0;
  Object.entries(s.zfs || {}).forEach(([pool, n]) => { total += n; rows.push(item(pool, ['ZFS', T('watch.snaps_n', { n })])); });
  Object.entries(s.btrfs || {}).forEach(([disk, n]) => { total += n; rows.push(item(disk, ['btrfs', T('watch.snaps_n', { n })])); });
  (s.asleep || []).forEach((x) => rows.push(item(x, [T('watch.snaps_asleep')])));
  (s.capped || []).forEach((x) => rows.push(item(x, [T('watch.snaps_capped')])));
  (s.series || []).forEach((x) => rows.push(item(x, [T('watch.snaps_learned')])));
  const office = Object.values(s.expected || {}).reduce((a, b) => a + (Number(b) || 0), 0);
  if (office) rows.push(item(T('watch.snaps_office'), [T('watch.snaps_office_n', { n: office })], null, true));
  if (s.zfs === null && s.btrfs === null) rows.push(note(T('watch.snaps_none')));
  // zfs didn't answer on the last round: nothing was compared — never «0 snapshots · pools and disks: 0» (truth review W5)
  if (s.failed) rows.unshift(note(T('watch.snaps_failed')));
  const sum = T('watch.snaps_sum', { n: total, places: Object.keys(s.zfs || {}).length + Object.keys(s.btrfs || {}).length });
  return group('snaps', T('watch.snaps'), s.failed && !total ? T('watch.snaps_failed_sum') : sum, rows);
}

// ------------------------------------------------------------------ data flow
/** "learning: 2 of 7 days" on a row — only when it is behind the rest (it came later); nothing once learned */
const learningText = (days, f) => (days === null || days === undefined || (days === f.days && f.days < f.learn) ? ''
  : T('watch.flow_learning', { days, learn: f.learn || 7 }));
/** A quiet line in a group: what can't be looked at here, and why */
const note = (text) => el('p', 'wm-note wm-flow-note', text);
const total = (list, k) => (list || []).reduce((a, x) => a + (Number(x[k]) || 0), 0);
const serviceName = (s) => ({ smb: 'SMB', nfs: 'NFS', ssh: 'SSH', web: 'WebGUI' }[s] || s);
/** A login's service as the agent names it (watchmanServiceName()): WebGUI, SSH (publickey) */
const loginService = (s) => (s === 'web' ? 'WebGUI' : String(s || '').startsWith('ssh:') ? `SSH (${String(s).slice(4)})` : 'SSH');

/** "usually at most 1 GB per hour now", or what «I know, thanks» made normal */
function usualRow(x) {
  if (x.learning !== null && x.learning !== undefined) return '';
  const n = Math.max(x.usual || 0, x.ack || 0);
  return n > 0 ? T('watch.flow_usual', { size: fmt.size(n) }) : T('watch.flow_usual_none');
}

/**
 * The data flow's history in Grafana: the office's dashboard at this group's panel — only where the
 * consultant found Grafana with the office's dashboard (agent: watchmanGrafana()). A link, never embedded.
 */
function grafanaRow(key) {
  const href = state.grafana && Office.safeHref(state.grafana[key]);
  if (!href) return null;
  const p = el('p', 'wm-note wm-flow-note');
  const a = el('a', '', T('watch.grafana'));
  a.href = href;
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  a.title = T('watch.grafana_title');
  p.appendChild(a);
  return p;
}

/** «What I keep an eye on» of the data flow: who pulls, containers, written into shares, SMB's users and machines */
function flowGroups(f) {
  if (!f) return [group('flow_clients', T('watch.flow_clients'), T('watch.flow_wait'), [])];
  const can = f.can || {};
  const overall = f.days < f.learn ? T('watch.flow_learning', { days: f.days, learn: f.learn }) : T('watch.flow_learned');
  const out = [];

  // who pulls: per client and service
  const clients = [];
  if (can.ss === false) clients.push(note(T('watch.flow_no_ss')));
  (f.clients || []).forEach((x) => clients.push(item(x.name ? `${x.ip} · ${x.name}` : x.ip,
    [serviceName(x.service), T('watch.flow_day', { size: fmt.size(x.day) }), usualRow(x), learningText(x.learning, f),
      x.ack ? T('watch.flow_noted', { size: fmt.size(x.ack) }) : '', T('watch.last_seen', { when: fmt.relative(x.last) })])));
  const gc = grafanaRow('flow_clients');
  if (gc) clients.push(gc);
  out.push(group('flow_clients', T('watch.flow_clients'),
    T('watch.flow_clients_sum', { learning: overall, n: (f.clients || []).length, size: fmt.size(total(f.clients, 'day')) })
      + (can.office ? ' · ' + T('watch.flow_office_now') : ''), clients));

  // containers: the top senders
  const cts = [];
  if (can.docker === false) cts.push(note(T('watch.flow_no_docker')));
  (f.containers || []).forEach((x) => cts.push(item(x.name, [T('watch.flow_day', { size: fmt.size(x.day) }),
    x.office ? T('watch.flow_office', { size: fmt.size(x.office) }) : '',
    x.media ? T('watch.flow_media') : x.kopia ? T('watch.flow_kopia') : usualRow(x), learningText(x.learning, f),
    x.with && x.with.length ? T('watch.flow_with', { names: x.with.join(', ') }) : ''])));
  if (can.host && can.host.length) cts.push(item(can.host.join(', '), [T('watch.flow_host')]));
  if (f.idle) cts.push(note(T('watch.flow_idle', { n: f.idle })));
  out.push(group('flow_containers', T('watch.flow_containers'),
    can.docker === false ? T('watch.flow_no_docker') : T('watch.flow_containers_sum', { size: fmt.size(total(f.containers, 'day')) }), cts, ['Docker']));

  // written into ZFS shares
  const zfs = can.zfs;
  const shares = [];
  (f.shares || []).forEach((x) => shares.push(item(x.share, [T('watch.flow_day', { size: fmt.size(x.day) }),
    x.office ? T('watch.flow_office', { size: fmt.size(x.office) }) : '',
    x.snapshots ? (x.snap ? T('watch.flow_written_now', { when: fmt.relative(x.snap), size: fmt.size(x.written) }) : '') : T('watch.flow_nosnap'),
    usualRow(x), learningText(x.learning, f)])));
  (f.disks || []).forEach((x) => shares.push(item(`${x.disk} · ${(x.shares || []).join(', ') || '–'}`,
    [T('watch.flow_gone_disk', { fs: x.fs }), T('watch.flow_day', { size: fmt.size(x.day) }),
      x.office ? T('watch.flow_office', { size: fmt.size(x.office) }) : '', usualRow(x), learningText(x.learning, f),
      x.ack ? T('watch.flow_noted', { size: fmt.size(x.ack) }) : ''])));
  if (zfs && zfs.asleep && zfs.asleep.length) shares.push(item(zfs.asleep.join(', '), [T('watch.flow_asleep')]));
  const fsDisks = Object.keys(can.fs || {});
  const noShares = (f.disks || []).length || fsDisks.length ? ''
    : !zfs ? T('watch.flow_no_zfs') : !(f.shares || []).length && !(zfs.asleep || []).length ? T('watch.flow_no_zfs_shares') : '';
  if (noShares) shares.push(note(noShares));
  const gs = !noShares && grafanaRow('flow_shares');
  if (gs) shares.push(gs);
  out.push(group('flow_shares', T('watch.flow_shares'), noShares || ((f.disks || []).length
    ? T('watch.flow_written_sum', { shares: T('count.zfs_shares', { n: (f.shares || []).length }), disks: T('count.disks', { n: f.disks.length }),
      size: fmt.size(total(f.shares, 'day') + total(f.disks, 'day')) })
    : T('watch.flow_shares_sum', { n: (f.shares || []).length, size: fmt.size(total(f.shares, 'day')) })), shares));

  // gone from shares: ZFS per share, XFS/btrfs per disk
  const gone = [];
  if (can.moving && can.moving.length) gone.push(note(T('watch.flow_moving', { what: movingText(can.moving) })));
  (f.gone || []).forEach((x) => gone.push(item(x.disk ? `${x.disk} · ${(x.shares || []).join(', ') || '–'}` : x.share,
    [x.disk ? T('watch.flow_gone_disk', { fs: x.fs }) : '', x.day || x.expected || !x.files ? T('watch.flow_day', { size: fmt.size(x.day) }) : '',
      x.files ? T('watch.flow_files_day', { n: x.files }) : '',
      x.files_expected ? T('watch.flow_files_expected', { n: x.files_expected }) : '',
      x.expected ? T('watch.flow_gone_expected', { size: fmt.size(x.expected) }) : '', usualRow(x), learningText(x.learning, f),
      x.ack ? T('watch.flow_noted', { size: fmt.size(x.ack) }) : '', (x.asleep || []).length ? T('watch.flow_gone_asleep', { pools: x.asleep.join(', ') }) : ''])));
  const disks = can.disks;
  if (disks && disks.asleep && disks.asleep.length) gone.push(item(disks.asleep.join(', '), [T('watch.flow_asleep')]));
  const btrfs = fsDisks.filter((d) => can.fs[d] === 'btrfs');
  if (btrfs.length) gone.push(note(T('watch.flow_btrfs_files', { disks: btrfs.join(', ') })));
  const noGone = !(f.gone || []).length && !zfs && !(disks && disks.disks && disks.disks.length) ? T('watch.flow_gone_none') : '';
  if (noGone) gone.push(note(noGone));
  out.push(group('flow_gone', T('watch.flow_gone'),
    noGone || T('watch.flow_gone_sum', { n: (f.gone || []).length, size: fmt.size(total(f.gone, 'day')) }), gone));

  // SMB: users and machines
  const smb = f.smb || { users: [], clients: [] };
  const rows = [];
  const smbState = can.smb === 'off' ? T('watch.flow_smb_off') : can.smb === null ? T('watch.flow_smb_none') : '';
  if (smbState) rows.push(note(smbState));
  if (smb.users.length) rows.push(item(T('watch.flow_smb_users_row'), [smb.users.join(', ')], null, true));
  smb.clients.forEach((x) => rows.push(item(x.name ? `${x.ip} · ${x.name}` : x.ip,
    [T('watch.flow_smb_hours', { n: x.hours }), learningText(x.learning, f), T('watch.last_seen', { when: fmt.relative(x.last) })])));
  out.push(group('flow_smb', T('watch.flow_smb'), smbState || T('watch.flow_smb_sum', {
    users: T('watch.flow_smb_users', { n: smb.users.length }), machines: T('watch.flow_smb_machines', { n: smb.clients.length }) }), rows));
  return out;
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  /** sub: a tile (#/watchman/book, …/open — only what's not noted yet —, …/posture, …/watch); «entry/<id>» (the search's):
      that entry of the whole book among the rows shown */
  async mount(root, sub) {
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : '';
    if (page === 'setup') {
      // #/watchman/setup/<tile> picks that tile like a click (an area of before: its tile); «changed» (the tile «Everything
      // changed» up to 1.96 «einfacher»): the whole setup, «Only what is changed» on
      const want = String(sub || '').split('/').slice(1).join('/');
      const picked = setupSw.mount(TILE_ROUTE[want] && TILES.includes(TILE_ROUTE[want]) ? TILE_ROUTE[want] : want);
      if (want === 'changed' && setupSw.bar) setupSw.bar.set({ only: true });
      if (picked) Office.subroute('setup');
      render();
      await load(false);
      return;
    }
    const m = /^entry\/([\w-]{1,64})$/.exec(sub || '');
    if (m) wanted = m[1];
    bookBarGet().reset();          // a new visit: the whole book (the area is a choice and stays)
    watchShut.clear();             // what he knows, folded as kept
    wakesBarGet().reset();         // … and every wake, of every disk
    wakeDisksOpen.clear();         // … with every disk folded
    Object.values(catBars).forEach((b) => b.reset().set({ pick: '' }));      // … and every tile's list whole
    // old places land on their tile (TILE_ROUTE: an area of 1.96's first steps, «Not noted yet», «What I keep an eye on»,
    // «How secure» — the whole page —, «Serious incidents» — the book showing them); never a dead link
    const old = Object.prototype.hasOwnProperty.call(TILE_ROUTE, sub || '') ? TILE_ROUTE[sub] : null;
    if (sub === 'incidents') bookOnly.incidents = true;
    sw.mount(m ? 'book' : old !== null ? old : sub);    // a tile's sub-route picks it like a click (the address back to #/watchman)
    if (old === '') sw.pick('', 'start');
    render();
    await load(false);
    holdAuto();
  },
  unmount() { clearTimeout(timer); view = null; bookRows = []; shownWakes = []; wanted = null; },
  poll() { if (!running()) load(false); },
  started() { if (hired() && !state) load(false); },      // his picture shows the mark on every page
  greetOk: (key) => greetOk(key),
  agentChanged() { if (view) render(); },
  menu() {
    return [
      { text: T('round_now'), act: roundNow, disabled: !Office.agent.running || running() || !hired() },
      { text: T('ack_all'), act: ackAll, disabled: !Office.agent.running || !openCount() },
    ];
  },
  async reception() {
    if (!state) await load(false);
    if (!state) return { bubble: T('bubble.loading'), facts: [] };
    const facts = [];
    facts.push(state.round && state.round.last ? T('fact.last', { when: fmt.relative(state.round.last) }) : T('fact.first'));
    const n = openCount();
    if (state.on_watch) facts.push(n ? T('fact.open', { n }) : T('fact.quiet'));
    if (postureOpen()) facts.push(T('fact.posture', { n: postureOpen() }));
    if (louderCount()) facts.push(T('tile.posture_incidents', { n: louderCount() }));
    if (state.watch) facts.push(T('fact.known', { ips: T('count.known_addresses', { n: state.watch.ips.length }), plugins: T('count.plugins', { n: state.watch.plugins.length }) }));
    return { bubble: bubbleText(), facts };
  },
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys)
Office.places(ID, [
  { kind: 'section', key: 'round.title' },
  { kind: 'section', key: 'book.title', route: `#/${ID}/book` },
  { kind: 'section', key: 'hold.title', route: `#/${ID}/array`, anchor: 'hold.title' },      // inside «Array» (1.96 «einfacher»)
  { kind: 'section', key: 'wakes.title', route: `#/${ID}/wakes` },
  { kind: 'help', key: 'help.setup', text: 'help.setup_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
  { kind: 'help', key: 'help.setup_off', text: 'help.setup_off_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
  { kind: 'help', key: 'help.setup_rules', text: 'help.setup_rules_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
  // the same section by the words people search it with (disk wake-ups, spin-up), in every language — its page alone
  { kind: 'section', key: 'search.wakes', route: `#/${ID}/wakes`, anchor: 'search.wakes', text: 'help.wakes_text' },
  ...sw.places(),             // his tiles: the book, how secure, serious incidents, his areas
  { kind: 'help', key: 'round_now', text: 'help.round_text' },
  ...['read', 'read_lights', 'read_answers', 'read_where', 'read_notify', 'posture', 'book', 'serious', 'normal', 'logins', 'containers', 'plugins', 'office', 'flash', 'shares', 'sched', 'flow', 'flow_gone', 'flow_not',
    'snaps', 'host', 'host_not', 'net', 'net_not', 'attack', 'siem', 'chain', 'night', 'parity', 'hold', 'wakes', 'grafana', 'notify', 'safe',
    'history']
    .map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  { kind: 'help', key: 'help.read_areas', text: 'help.read_areas_more' },     // each area in a line (helpAreas())
  // each area's «What he checks» in the help (2026-11-23): found by its words, the fold opened
  ...TILES.map((id) => ({ kind: 'section', key: `scope.${id}.help`, text: `scope.${id}.look_1`, anchor: `scope.${id}` })),
]);

// what his state holds for the search (core.js «items»): the entries of his book not noted yet (in his words, never a
// router's or a log's line), his posture tips not known yet (their rows carry the tip's id: data-id), what he keeps an
// eye on — each group, found by its title and the names in it (plugins, containers, shares, User Scripts); each through
// its tile's sub-route, so its section is drawn
const ENTRIES_MAX = 120;
Office.placesFrom(ID, (s) => {
  const out = [];
  (s.book || []).filter((e) => e && e.open).slice(0, ENTRIES_MAX).forEach((e) => out.push({
    text: entryName(e), sub: e.kind === 'watch' ? T('state.watch') : `${entryTile(e) ? tileName(entryTile(e)) : T('group.' + e.group)} · ${T('state.open')}`,
    route: entryTile(e) ? `#/${ID}/${entryTile(e)}` : `#/${ID}/entry/${e.id}`, anchor: `entry:${e.id}`,       // what waits: in its tile
  }));
  ((s.posture && s.posture.tips) || []).filter((x) => x && !x.known).forEach((x) => out.push({
    text: T(`posture.${x.id}.title`, postureParams(x)), sub: `${tileName(x.tile || tileOfCat(x.cat) || 'server')} · ${T('posture.level_' + x.level)}`,
    route: `#/${ID}/${x.tile || tileOfCat(x.cat) || ''}`, anchor: x.id,       // an advice: in its tile (1.96 «einfacher»)
  }));
  (s.incidents || []).filter((x) => x && typeof x.id === 'string').forEach((x) => out.push({
    text: entryName(x), sub: `${T('incidents.title')} · ${T('group.' + x.group)}`, route: `#/${ID}/incidents`, anchor: `incident:${x.id}`,
    words: typeof x.note === 'string' ? x.note : '',
  }));
  const w = s.watch;
  if (!w) return out;
  const names = (list, k) => (list || []).map((x) => x && x[k]).filter((x) => typeof x === 'string').join(' ');
  const groups = [['ips', ''], ...((w.fail_ips || []).length ? [['fail_ips', '']] : []), ['containers', names(w.containers && w.containers.special, 'name')],
    ['plugins', names(w.plugins, 'name')], ['flash', ''], ['shares', names(w.shares && w.shares.open, 'share')], ['sched', names(w.sched && w.sched.scripts, 'name')],
    ['host', ''], ['doors', ''], ...(w.partner && ((w.partner.pairs || []).length || (w.partner.strays || []).length) ? [['partner', '']] : []), ['net', ''], ['snaps', ''],
    ...(s.flow ? ['flow_clients', 'flow_containers', 'flow_shares', 'flow_gone', 'flow_smb'] : ['flow_clients']).map((k) => [k, ''])];
  // each in its area's section (1.96: no tile of its own any more)
  const areaOf = (key) => tileOfCat(Object.keys(CAT_KNOWN).find((c) => CAT_KNOWN[c].includes(key)) || '') || 'login';
  groups.forEach(([key, words]) => out.push({ text: T('watch.' + key), sub: tileName(areaOf(key)), route: `#/${ID}/${areaOf(key)}`, anchor: `watch:${key}`, words }));
  return out;
});

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.watchman = { setState: (s) => { state = s; }, greetOk, bookView, entryArea, foldKind, plainWords, entryHay, foldName,
    groupFit, fitTally, groupOpen, knownFolds, srcFrom, srcNone, wakesTile, wakesView, causeName, catLevel, holdWaiting, holdTile, tileWaits, tileLevel, entryTile, TILES, TILE_PARTS, TILE_ROUTE, tileSection, handNote, handNames, ipPublic };
}
})();
