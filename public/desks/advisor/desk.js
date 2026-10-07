/* The Consultant — from outside the office. He knows the externals the office
   relies on but doesn't make itself (Fix Common Problems, Files Viewer, Kopia,
   Stream Viewer; unbalanced only for whoever wants it; and, optional, the
   monitoring: Node Exporter → Prometheus → Grafana, Loki later): whether
   they are there, what they are good for, who in the office needs them, and
   how to install them by hand — and, the second offer, he installs them:
   plugins through Unraid's plugin manager, containers through Unraid's own
   «Add Container» form (the user clicks Apply), Kopia's repository with the
   user's keys (through RAM only) — with ransomware protection (S3 Object Lock)
   where the bucket keeps it — and a recovery sheet made here in the browser.
   The agent part lives in agent/desks/advisor.php. */
(() => {
'use strict';

const ID = 'advisor';
const T = Office.scope(ID);
const { el, fmt } = Office;

// per external: its icon, where it lives in Unraid, who in the office needs it,
// what to copy for the manual installation
const EXTERNALS = {
  fcp: {
    icon: '🩺', open: '/Settings/FixProblems', install: '/Apps', desk: null,
    copy: { url: 'https://raw.githubusercontent.com/unraid/fix.common.problems/master/plugins/fix.common.problems.plg' },
  },
  filesviewer: {
    icon: '🗂️', open: '/Tools/FilesViewerTool', install: '/Apps', desk: null,
    copy: { url: 'https://raw.githubusercontent.com/Lazaros-Chalkidis/unraid-filesviewer/main/filesviewer.plg' },
  },
  kopia: {
    icon: '☁️', open: '/Docker', install: '/Apps', desk: 'backup',
    copy: { image: 'ghcr.io/imagegenius/kopia', path: '/mnt/addons/UnraidSecretaryOffice/snapshots', target: '/uso' },
  },
  // only where Emby, Jellyfin or Plex runs (the agent leaves it out otherwise)
  streamviewer: {
    icon: '🎬', open: '/Settings/StreamViewerSettings', install: '/Apps', desk: 'emby',
    copy: { url: 'https://github.com/Lazaros-Chalkidis/unraid-streamviewer/raw/main/streamviewer.plg' },
  },
  // optional (the agent says so): never counted as missing — and the desks it can get in the way of
  unbalanced: {
    icon: '⚖️', open: '/Settings/unbalanced', install: '/Apps', desk: null, clash: ['backup', 'emby'],
    copy: { url: 'https://github.com/jbrodriguez/unbalance/releases/latest/download/unbalanced.plg' },
  },
  // monitoring (the agent's group, optional), in the order it is set up. In what
  // is copied, {ip} becomes the server's address, {dir} the office's metrics folder,
  // {yml} the prometheus.yml the agent would write (state.prometheus_yml)
  nodeexporter: {
    icon: '🌡️', open: { container: '/Docker', plugin: '/Plugins' }, install: '/Apps', desk: null,
    copy: {
      image: 'quay.io/prometheus/node-exporter:latest-distroless',
      // the template's own Post Arguments, the office's folder at the end (the host's / is /host in there)
      postargs: '--path.rootfs=/host --path.procfs=/host/proc --path.sysfs=/host/sys --path.udev.data=/host/run/udev/data --collector.textfile.directory=/host{dir}',
      check: 'http://{ip}:9100/metrics',
      // ich777's plugin takes its start options from a file on the flash and is started through at, like its .plg does
      plugin: "echo 'start_parameters=--collector.textfile.directory={dir}' > /boot/config/plugins/prometheus_node_exporter/settings.cfg; kill $(pidof prometheus_node_exporter); sleep 1; echo '/usr/bin/prometheus_node_exporter --collector.textfile.directory={dir}' | at now",
    },
  },
  prometheus: {
    icon: '🔥', open: '/Docker', install: '/Apps', desk: null,
    copy: {
      // the template stops at once without prometheus.yml; an existing one is left alone; 99:100 = the template's --user
      config: `P=/mnt/user/appdata/prometheus; mkdir -p $P/etc $P/data
[ -e $P/etc/prometheus.yml ] && echo "prometheus.yml is already there - left as it is" || cat > $P/etc/prometheus.yml <<'EOF'
{yml}
EOF
chown -R 99:100 $P`,
      image: 'prom/prometheus',
      check: 'http://{ip}:9090/targets',
    },
  },
  grafana: {
    icon: '📊', open: '/Docker', install: '/Apps', desk: null,
    copy: { image: 'grafana/grafana', root_url: 'http://{ip}:3000/', datasource: 'http://{ip}:9090' },
  },
  // later (the agent says so): no install button, only why not yet
  loki: { icon: '📜', open: '/Docker', install: null, desk: null, copy: {} },
};

// S3 providers for Kopia's repository: an example endpoint each (only a placeholder, never filled in)
const PROVIDERS = {
  s3: 's3.example.com', aws: 's3.amazonaws.com', b2: 's3.eu-central-003.backblazeb2.com', r2: '<account>.r2.cloudflarestorage.com',
  mega: 's3.eu-central-1.s4.mega.io', wasabi: 's3.eu-central-1.wasabisys.com', hetzner: 'fsn1.your-objectstorage.com',
  idrive: '<region>.idrivee2-<n>.com', minio: 'minio.lan:9000',
};
const PROVIDER_NAMES = { aws: 'Amazon S3', b2: 'Backblaze B2', r2: 'Cloudflare R2', mega: 'MEGA S4', wasabi: 'Wasabi',
  hetzner: 'Hetzner Object Storage', idrive: 'IDrive e2', minio: 'MinIO' };

let state = null;
let view = null;

/** His state: as kept at once, a new look following on his page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view) render();
}

const externals = () => Object.entries((state && state.externals) || {}).filter(([id]) => EXTERNALS[id]);
const missing = () => externals().filter(([, x]) => !x.there && !x.optional);
const names = (list) => list.map(([id]) => T(`ext.${id}.name`)).join(', ');
const group = (g) => externals().filter(([, x]) => (x.group || null) === g);
const begun = (g) => group(g).some(([, x]) => x.there && !x.later);
/** Monitoring begun but not complete: what is still missing (Loki, for later, doesn't count) */
const gaps = () => (begun('monitoring') ? group('monitoring').filter(([, x]) => !x.there && !x.later) : []);
const hired = () => !!(Office.desks.has(ID) && Office.desks.get(ID).hired);

function bubbleText() {
  if (!state) return T('bubble.loading');
  const m = missing();
  if (m.length) return T('bubble.missing', { names: names(m), n: m.length });
  const g = gaps();
  return g.length ? T('bubble.monitoring', { names: names(g), n: g.length }) : T('bubble.all_there');
}

/** The server's address for what is copied (from Unraid's network settings), or null */
function serverIp() {
  const m = /^https?:\/\/([^/:]+)/.exec((state && state.gui) || '');
  return m ? m[1] : null;
}

/** An external's values to copy, {yml}, {ip} and {dir} filled in */
function copies(id) {
  const ip = serverIp() || '<server-ip>';
  const dir = state.metrics_dir || '';
  const yml = state.prometheus_yml || '';
  return Object.fromEntries(Object.entries(EXTERNALS[id].copy).map(([k, v]) => [k,
    v.replaceAll('{yml}', yml).replaceAll('{ip}', ip).replaceAll('{dir}', dir)]));
}

/** A link into Unraid's web UI, in the same tab */
function unraidLink(path, text, kind) {
  const a = el('a', 'btn small' + (kind ? ' ' + kind : ''), text);
  const href = Office.safeHref(path);
  if (!href) return null;
  a.href = href;
  return a;
}

/** A button of the consultant's own (his second offer, after the manual way) */
function offerButton(text, onclick, why) {
  const b = el('button', 'btn small plain', text);
  b.type = 'button';
  b.disabled = !Office.agent.running || !hired() || !!why;
  if (why) b.title = why;
  // what he offers rests on his last look: a fresh one first (never on a stale one), the offer read from it again
  b.onclick = async () => { if (await Office.freshState(ID)) onclick(); };
  return b;
}

// ------------------------------------------------------------------ rendering
function render() {
  const root = view;
  root.innerHTML = '';
  const again = el('button', 'btn plain', T('look_again'));
  again.type = 'button';
  again.disabled = !Office.agent.running;
  again.onclick = async () => {
    again.disabled = true;
    await load(true);
    Office.toast(T('looked'));
  };
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions: [again] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID, [
    [el('span', 'chip ok', T('there')), T('help.there')],
    [el('span', 'chip danger', T('missing')), T('help.missing')],
    [el('span', 'chip quiet', T('absent')), T('help.absent')],
    [el('span', 'chip quiet', T('later')), T('help.later')],
    [el('span', 'chip warn', T('stopped')), T('help.stopped')],
    [el('span', 'chip ok', T('textfile_yes')), T('help.textfile')],
    [el('span', 'chip', T('by_me')), T('help.by_me')],
    [T('howto'), T('help.howto')],
    [T('help.do_term'), T('help.do')],
    [T('do.sheet'), T('help.sheet')],
    [T('look_again'), T('help.again')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }

  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('externals'), T('externals_sub')));
  group(null).forEach(([id, x]) => s.appendChild(external(id, x)));
  root.appendChild(s);

  const mon = group('monitoring');
  if (mon.length) {
    const core = mon.filter(([, x]) => !x.later);
    const count = el('span', 'chip quiet', T('monitoring_count', { n: core.filter(([, x]) => x.there).length, of: core.length }));
    const m = el('section', 'section');
    m.appendChild(Office.sectionHead(T('monitoring'), T('monitoring_sub'), count));
    mon.forEach(([id, x]) => m.appendChild(external(id, x)));
    if (state.dashboard) m.appendChild(dashboard());
    root.appendChild(m);
  }
  root.appendChild(el('p', 'role', T('looked_at', { when: fmt.relative(state.time) })));
}

function external(id, x) {
  const e = EXTERNALS[id];
  const box = el('div', 'box ad-external');
  const row = el('div', 'row nocheck ad-row');
  row.appendChild(avatar(e, x));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', T(`ext.${id}.name`)));
  const meta = el('div', 'row-meta');
  if (!x.there && x.later) meta.appendChild(el('span', 'chip quiet', T('later')));
  else if (!x.there && x.optional) meta.appendChild(el('span', 'chip quiet', T('absent')));
  else if (!x.there) meta.appendChild(el('span', 'chip danger', T('missing')));
  else if (x.kind === 'container' && !x.running) meta.appendChild(el('span', 'chip warn', T('stopped')));
  else meta.appendChild(el('span', 'chip ok', T('there')));
  const tf = textfileChip(x);
  if (tf) {                                              // the node exporter: does it read the office's folder?
    const chip = el('span', 'chip ' + tf.cls, tf.text);
    chip.title = tf.tip;
    meta.appendChild(chip);
  }
  const repo = x.kopia && x.kopia.repo;
  if (repo) {                                            // Kopia: connected to a repository? (its non-secret facts)
    const chip = el('span', 'chip ' + (repo.connected ? 'ok' : 'warn'), T(repo.connected ? 'repo_yes' : 'repo_no'));
    chip.title = repo.connected
      ? T('repo_yes_tip', { type: repo.type || '?', where: [repo.bucket || repo.path, repo.endpoint].filter(Boolean).join(' @ '), client: repo.client || '–' })
      : T('repo_no_tip');
    meta.appendChild(chip);
  }
  const prov = x.grafana;
  if (prov && prov.done !== null && prov.done !== undefined) {   // Grafana: does it get the office's data source and dashboard?
    const chip = el('span', 'chip ' + (prov.done && prov.points ? 'ok' : 'warn'), T(prov.done && prov.points ? 'prov_yes' : 'prov_no'));
    chip.title = T(prov.done && prov.points ? 'prov_yes_tip' : 'prov_no_tip');
    meta.appendChild(chip);
  }
  if (x.by_consultant) {
    const chip = el('span', 'chip', T('by_me'));
    chip.title = T('by_me_tip');
    meta.appendChild(chip);
  }
  const job = state.job && state.job.id === id && state.job.state === 'running' ? state.job : null;
  if (job) {
    const chip = el('span', 'chip warn', T('installing'));
    chip.title = T('installing_tip');
    meta.appendChild(chip);
  }
  if (x.version) meta.appendChild(el('span', '', 'v' + x.version));
  if (x.name) meta.appendChild(el('span', 'mono', x.name));
  if (e.desk && Office.desks.has(e.desk)) {
    const chip = el('span', 'chip');
    chip.append(Office.deskIcon(e.desk), Office.t(e.desk + '.name'));
    chip.title = T(`ext.${id}.for`, { media: state.media || 'Emby' });
    meta.appendChild(chip);
  }
  (e.clash || []).filter((d) => Office.desks.has(d)).forEach((d) => {
    const chip = el('span', 'chip warn');
    chip.append(Office.deskIcon(d), Office.t(d + '.name'));
    chip.title = T(`ext.${id}.clash`, { name: Office.t(d + '.name') });
    meta.appendChild(chip);
  });
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T(`ext.${id}.what`, { media: state.media || 'Emby' })));
  if (Office.has(`${ID}.ext.${id}.careful`)) main.appendChild(el('div', 'row-detail ad-careful', T(`ext.${id}.careful`)));
  const where = x.there && x.webui && Office.safeHref(x.webui);
  if (where && Office.has(`${ID}.ui.${id}`)) {               // where its page is, and with which login (never the login itself)
    const line = el('div', 'row-detail ad-where');
    const a = el('a', '', x.webui);
    a.href = where;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    line.append(T(`ui.${id}`), ' ', a);
    main.appendChild(line);
  }
  if (x.offer && x.offer.refuse) main.appendChild(el('div', 'row-detail ad-careful', Office.errorText(x.offer.refuse, ID)));
  row.appendChild(main);

  const acts = el('div', 'ad-acts');
  const byKind = typeof e.open === 'object';     // a plugin or a container (the node exporter)
  const open = byKind ? e.open[x.kind] : e.open;
  const link = x.there ? unraidLink(open, T(byKind && x.kind === 'plugin' ? `open.${id}_plugin` : `open.${id}`), 'plain')
    : e.install ? unraidLink(e.install, T('install'), '') : null;
  if (link) acts.appendChild(link);
  // his own offer comes second: the manual way stays the first
  if (!x.there && x.offer === true) {
    acts.appendChild(job ? offerButton(T('installing'), () => jobDialog(state.job)) : offerButton(T('do.plugin'), () => { const now = (state.externals || {})[id]; if (now && !now.there) pluginDialog(id, now); }));
  } else if (!x.there && x.offer && x.offer.template) {
    acts.appendChild(offerButton(T('do.container'), () => containerDialog(id), x.offer.refuse ? Office.errorText(x.offer.refuse, ID) : null));
  }
  if (id === 'kopia' && x.there && repo && repo.connected === false) {
    acts.appendChild(offerButton(T('do.kopia_repo'), () => { const now = (state.externals || {})[id]; if (now && now.there) kopiaIntro(now); }));
  }
  if (id === 'grafana' && x.there && prov && prov.host && !(prov.done && prov.points)) {
    acts.appendChild(offerButton(T('do.grafana_prov'), () => provisionDialog()));
  }
  if (e.desk && Office.desks.has(e.desk) && Office.desks.get(e.desk).hired) {
    const a = el('a', 'btn small plain', T('to_desk', { name: Office.t(e.desk + '.name') }));
    a.href = `#/${e.desk}`;
    acts.appendChild(a);
  }
  row.appendChild(acts);
  box.appendChild(row);
  box.appendChild(howto(id, x));
  return box;
}

/** The maker's icon as Unraid has it (recognised at a glance), else the emoji */
function avatar(e, x) {
  const box = el('div', 'avatar ad-avatar');
  const src = x.icon;
  if (!src) { box.textContent = e.icon; return box; }
  const img = el('img');
  img.alt = '';
  img.src = src;
  img.onerror = () => { box.innerHTML = ''; box.textContent = e.icon; };
  box.appendChild(img);
  return box;
}

/**
 * The node exporter's chip: whether its textfile collector reads the office's folder — «reads the
 * office's folder» only while that folder is really there on the host (an older state without
 * metrics_there counts as there); null for anything else
 */
function textfileChip(x) {
  if (x.textfile !== true && x.textfile !== false) return null;
  const dir = { dir: state.metrics_dir || '' };
  if (!x.textfile) return { cls: 'warn', text: T('textfile_no'), tip: T('textfile_no_tip', dir) };
  if (state.metrics_there === false) return { cls: 'warn', text: T('textfile_nodir'), tip: T('textfile_nodir_tip', dir) };
  return { cls: 'ok', text: T('textfile_yes'), tip: T('textfile_yes_tip', dir) };
}

/**
 * Whether the steps start unfolded: something missing that the office needs;
 * in a group (monitoring) once it is begun, what is still missing, stopped or
 * not set up for the office yet — never what is for later
 */
function startsOpen(x) {
  if (x.later) return false;
  if (!x.there && !x.optional) return true;
  const stopped = x.there && x.kind === 'container' && !x.running;
  return !!x.group && begun(x.group) && (!x.there || stopped || x.textfile === false);
}

/** "Install by hand": the steps (lang keys install.<id>.1 …), what to copy below them */
function howto(id, x) {
  const values = copies(id);
  const det = el('details', 'ad-howto');
  det.open = startsOpen(x);
  det.appendChild(el('summary', '', T('howto')));
  const ol = el('ol', 'ad-steps');
  const params = { ...values, ip: serverIp() || '<server-ip>', dir: state.metrics_dir || '', media: state.media || 'Emby' };
  for (let i = 1; Office.has(`${ID}.install.${id}.${i}`); i++) ol.appendChild(el('li', '', T(`install.${id}.${i}`, params)));
  det.appendChild(ol);
  const box = el('div', 'ad-copies');
  Object.entries(values).forEach(([key, value]) => {
    const block = value.includes('\n');          // a command of several lines: below its label, as it is
    const line = el('div', 'ad-copy' + (block ? ' ad-block' : ''));
    const b = el('button', 'btn small plain', Office.t('common.copy'));
    b.type = 'button';
    b.onclick = () => Office.copy(value);
    const label = el('span', 'ad-copy-label', T(`copy.${id}.${key}`));
    if (block) line.append(label, b, el('pre', 'code', value));
    else line.append(label, el('code', '', value), b);
    box.appendChild(line);
  });
  if (Object.values(values).some((v) => v.includes('<server-ip>'))) box.appendChild(el('div', 'ad-note', T('ip_unknown')));
  det.appendChild(box);
  if (id === 'kopia') det.appendChild(lockGuide());
  return det;
}

/** Kopia by hand: ransomware protection with S3 Object Lock — what, why, who offers it, how, the price, after an attack */
const LOCK_COPIES = {
  create: 'kopia repository create s3 --bucket=<bucket> --endpoint=<endpoint> --access-key=<access key> --secret-access-key=<secret key> --retention-mode=COMPLIANCE --retention-period=30d',
  extend: 'kopia maintenance set --extend-object-locks=true',
  check: 'kopia repository status',
};
function lockGuide() {
  const box = el('div', 'ad-lock-guide');
  box.appendChild(el('div', 'field-title', T('lock.title')));
  for (let i = 1; Office.has(`${ID}.lock.${i}`); i++) box.appendChild(el('p', 'ad-lock-p', T(`lock.${i}`, { days: 30 })));
  const copies = el('div', 'ad-copies');
  Object.entries(LOCK_COPIES).forEach(([key, value]) => {
    const line = el('div', 'ad-copy');
    const b = el('button', 'btn small plain', Office.t('common.copy'));
    b.type = 'button';
    b.onclick = () => Office.copy(value);
    line.append(el('span', 'ad-copy-label', T(`copy.lock.${key}`)), el('code', '', value), b);
    copies.appendChild(line);
  });
  box.appendChild(copies);
  box.appendChild(callout(T('lock.minio'), true));
  return box;
}

/**
 * The office's own dashboard for Grafana (monitoring/grafana-dashboard.json): what it shows; when
 * the consultant provisioned it (Grafana's chip "set up") it is simply there and kept current — a link
 * into Grafana, the import guide folded away for a Grafana installed by hand; otherwise how to import
 * it, and that admin/admin must go first (also when he installed Grafana: its admin password is optional)
 */
function dashboard() {
  const g = (state.externals || {}).grafana || {};
  const prov = g.grafana || {};
  const provisioned = !!(g.there && prov.done && prov.points);
  const box = el('div', 'box ad-external');
  const row = el('div', 'row nocheck ad-row');
  row.appendChild(avatar({ icon: '📈' }, {}));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', T('dashboard.name')));
  main.appendChild(el('div', 'row-detail', T('dashboard.what')));
  main.appendChild(el('div', 'row-detail', T(provisioned ? 'dashboard.provisioned' : 'dashboard.import_hint')));
  main.appendChild(el('div', 'row-detail ad-careful', T('dashboard.admin')));
  row.appendChild(main);
  const inGrafana = provisioned && g.webui ? Office.safeHref(String(g.webui).replace(/\/+$/, '') + '/d/unraid-secretary-office') : null;
  const href = inGrafana || Office.safeHref(state.dashboard);
  if (href) {
    const acts = el('div', 'ad-acts');
    const a = el('a', 'btn small plain', T(inGrafana ? 'dashboard.in_grafana' : 'dashboard.open'));
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    acts.appendChild(a);
    row.appendChild(acts);
  }
  box.appendChild(row);

  const det = el('details', 'ad-howto');
  det.appendChild(el('summary', '', T(provisioned ? 'dashboard.howto_manual' : 'dashboard.howto')));
  const ol = el('ol', 'ad-steps');
  for (let i = 1; Office.has(`${ID}.dashboard.${i}`); i++) ol.appendChild(el('li', '', T(`dashboard.${i}`, { dir: state.metrics_dir || '' })));
  det.appendChild(ol);
  const line = el('div', 'ad-copy');
  const b = el('button', 'btn small plain', Office.t('common.copy'));
  b.type = 'button';
  b.onclick = () => Office.copy(state.dashboard);
  line.append(el('span', 'ad-copy-label', T('copy.dashboard.url')), el('code', '', state.dashboard), b);
  const copies = el('div', 'ad-copies');
  copies.appendChild(line);
  det.appendChild(copies);
  box.appendChild(det);
  return box;
}

// ------------------------------------------------------------------ small dialog parts
/** label: value rows (dl.props); values in mono unless plain */
function props(rows) {
  const dl = el('dl', 'props ad-props');
  rows.filter(Boolean).forEach(([k, v, plain]) => {
    const dd = el('dd');
    if (v instanceof Node) dd.appendChild(v); else dd.appendChild(el('span', plain ? '' : 'mono', v));
    dl.append(el('dt', '', k), dd);
  });
  return dl;
}

function callout(text, warn) {
  return el('p', 'callout' + (warn ? ' warn' : ''), text);
}

function errorOf(j) {
  const e = j.error || { key: 'internal', params: {} };
  if (e.key === 'ad_kopia_field') return T('errors.ad_kopia_field', { field: T(`kr.${(e.params || {}).field}`) });     // kr.lock_days too
  return Office.errorText(e, ID);
}

async function afterAction(j) {
  if (j.state) { state = j.state; if (view) render(); } else await load(true);
}

// ------------------------------------------------------------------ plugins
/** Preview: which plugin, from where, by whom — then Unraid's plugin manager installs it as a job */
function pluginDialog(id, x) {
  const box = el('div');
  box.appendChild(el('p', '', T('pi.text', { name: T(`ext.${id}.name`) })));
  box.appendChild(props([[T('pi.from'), x.plg], [T('pi.by'), x.author, true]]));
  box.appendChild(el('p', 'ad-note', T('pi.note')));
  Office.dialog({
    title: T('pi.title', { name: T(`ext.${id}.name`) }),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('pi.go'), kind: '', act: async () => {
        const j = await Office.api.post('advisor.plugin_install', { id });
        if (!j.ok) { Office.toast(errorOf(j), true); return true; }
        setTimeout(() => jobDialog(j.job), 0);
        return true;
      } },
    ],
  });
}

/** The plugin manager's job: its output while it runs, then what came of it (only what was seen) */
function jobDialog(job) {
  if (!job) return;
  const name = T(`ext.${job.id}.name`);
  const box = el('div');
  const status = el('p', 'callout');
  const out = el('pre', 'code ad-output');
  box.append(status, out);
  let timer = null;
  let closed = false;
  const show = (jb) => {
    out.textContent = jb.output || '…';
    out.scrollTop = out.scrollHeight;
    const text = { running: T('pi.running'), done: T('pi.done', { name }), failed: T('pi.failed', { name, code: jb.exit }),
                   unregistered: T('pi.unregistered', { name }), unknown: T('pi.unknown', { name }) }[jb.state] || T('pi.unknown', { name });
    status.textContent = text;
    status.className = 'callout' + (['failed', 'unregistered', 'unknown'].includes(jb.state) ? ' warn' : '');
  };
  const poll = async () => {
    if (closed) return;
    const j = await Office.api.post('advisor.job', {});
    if (closed) return;
    if (j.ok && j.job) {
      show(j.job);
      if (j.job.state === 'running') { timer = setTimeout(poll, 2000); return; }
      load(true);
    } else {
      timer = setTimeout(poll, 4000);
    }
  };
  show(job);
  Office.dialog({ title: T('pi.title', { name }), body: box, wide: true, onClose: () => { closed = true; clearTimeout(timer); } });
  poll();
}

// ------------------------------------------------------------------ containers
/** A field of the template as one line: what Unraid's form will show */
function templateRow(c) {
  const mode = c.mode ? ` (${c.mode})` : '';
  switch (c.type) {
    case 'Path': return [T('f.path'), c.value ? `${c.value} → ${c.target}${mode}` : `${T('f.empty')} → ${c.target}`];
    case 'Port': return [T('f.port'), `${c.value || c.target} → ${c.target}/${c.mode || 'tcp'}`];
    case 'Variable': return [T('f.variable'), c.mask ? `${c.target} = ${T('f.yours')}` : `${c.target} = ${c.value}`];
    case 'Label': return [T('f.label'), `${c.target}=${c.value}`];
    case 'Device': return [T('f.device'), c.value];
    default: return [c.type, c.value];
  }
}

/**
 * Unraid's «Add Container» form, prepared: a preview of every field and of the
 * files written beforehand, then the form opens — the user clicks Apply there
 */
async function containerDialog(id) {
  let anon = false;
  const j = await Office.api.post('advisor.install_preview', { id });
  if (!j.ok) { Office.toast(errorOf(j), true); return; }
  const plan = j.plan;
  const box = el('div');
  box.appendChild(el('p', '', T('ci.text', { name: plan.name })));
  if (plan.refuse) box.appendChild(callout(Office.errorText(plan.refuse, ID), true));
  if (Office.has(`${ID}.ci.${id}`)) box.appendChild(callout(T(`ci.${id}`)));
  const fields = el('div');
  const draw = () => {
    fields.innerHTML = '';
    fields.appendChild(el('div', 'field-title', T('ci.fields')));
    const rows = [[T('f.name'), plan.name], [T('f.repository'), plan.repository], [T('f.network'), plan.network]];
    plan.config.forEach((c) => rows.push(templateRow(c.target === 'GF_AUTH_ANONYMOUS_ENABLED' ? { ...c, value: anon ? 'true' : 'false' } : c)));
    if (plan.extra) rows.push([T('f.extra'), plan.extra]);
    if (plan.post) rows.push([T('f.post'), plan.post]);
    fields.appendChild(props(rows));
  };
  draw();
  box.appendChild(fields);
  if (id === 'grafana') {
    const label = el('label', 'check');
    const cb = el('input');
    cb.type = 'checkbox';
    const span = el('span', '', T('ci.anon'));
    span.appendChild(el('small', '', T('ci.anon_hint')));
    label.append(cb, span);
    cb.onchange = () => { anon = cb.checked; draw(); };
    box.appendChild(label);
  }
  if (plan.files.length) {
    box.appendChild(el('div', 'field-title', T('ci.files')));
    const ul = el('ul', 'shortlist');
    plan.files.forEach((f) => {
      const li = el('li', '', f.path);
      li.appendChild(el('span', '', T(f.there ? 'ci.file_keep' : 'ci.file_write') + ' · ' + T(`ci.kind_${f.kind}`)));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }
  if (id === 'grafana' && !plan.dashboard) box.appendChild(callout(T('ci.no_dashboard')));
  box.appendChild(el('p', 'ad-note', T('ci.label_note')));
  const d = Office.dialog({
    title: T('ci.title', { name: T(`ext.${id}.name`) }),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('ci.go'), kind: '', act: async () => {
        const r = await Office.api.post('advisor.install_prepare', { id, anon });
        if (!r.ok) { Office.toast(errorOf(r), true); return false; }
        await afterAction(r);
        const href = Office.safeHref(r.url);
        if (!href) { Office.toast(T('ci.no_gui'), true); return true; }
        Office.toast(T('ci.opened'));
        location.href = href;
        return true;
      } },
    ],
  });
  if (plan.refuse) d.buttons[1].disabled = true;
}

// ------------------------------------------------------------------ Grafana's provisioning
async function provisionDialog() {
  const j = await Office.api.post('advisor.provision_preview', {});
  if (!j.ok) { Office.toast(errorOf(j), true); return; }
  const p = j.plan;
  const box = el('div');
  box.appendChild(el('p', '', T('gp.text')));
  box.appendChild(callout(p.points ? T('gp.points', { dir: p.provisioning }) : T('gp.change', { now: p.provisioning, want: p.want }), !p.points));
  const ul = el('ul', 'shortlist');
  p.files.forEach((f) => {
    const li = el('li', '', f.path);
    li.appendChild(el('span', '', T(f.there ? 'ci.file_keep' : 'ci.file_write') + ' · ' + T(`ci.kind_${f.kind}`)));
    ul.appendChild(li);
  });
  box.appendChild(ul);
  if (!p.dashboard) box.appendChild(callout(T('ci.no_dashboard')));
  box.appendChild(el('p', 'ad-note', T('gp.never')));
  const todo = p.files.filter((f) => !f.there).length;
  const d = Office.dialog({
    title: T('gp.title'),
    body: box,
    wide: true,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('gp.go'), kind: '', act: async () => {
        const r = await Office.api.post('advisor.provision', {});
        if (!r.ok) { Office.toast(errorOf(r), true); return false; }
        await afterAction(r);
        Office.toast(T(r.points ? 'gp.done' : 'gp.done_change', { n: r.written.length }));
        return true;
      } },
    ],
  });
  if (!todo) d.buttons[1].disabled = true;
}

// ------------------------------------------------------------------ Kopia's repository
/**
 * Step 0: plain words about the keys, the way of maximum security, and an
 * explicit confirmation (a checkbox) before anything can be typed
 */
function kopiaIntro(x) {
  const box = el('div');
  box.appendChild(callout(T('kr.intro'), true));
  box.appendChild(el('p', '', T('kr.how', { config: (x.kopia && x.kopia.config) || '/mnt/user/appdata/kopia' })));
  box.appendChild(callout(T('kr.max')));
  const label = el('label', 'check');
  const cb = el('input');
  cb.type = 'checkbox';
  label.append(cb, el('span', '', T('kr.confirm')));
  box.appendChild(label);
  const d = Office.dialog({
    title: T('kr.title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('kr.next'), kind: '', act: () => {
        if (!cb.checked) return false;
        setTimeout(() => kopiaForm(x, freshForm(x)), 0);
        return true;
      } },
    ],
  });
  d.buttons[1].disabled = true;
  cb.onchange = () => { d.buttons[1].disabled = !cb.checked; };
}

function freshForm(x) {
  const folders = (x.kopia && x.kopia.folders) || [];
  return { mode: 'create', storage: 's3', provider: 's3', endpoint: '', region: '', bucket: '', prefix: '',
           path: folders.length ? folders[0].target.replace(/\/$/, '') + '/kopia-repository' : '', client: '',
           access_key: '', secret_key: '', password: '', password2: '', generated: false, x,
           lock: null, lockOn: false, lockDays: 30 };
}

/** Forget what was typed (as far as the browser lets us) */
function wipe(form) {
  ['access_key', 'secret_key', 'password', 'password2'].forEach((k) => { form[k] = ''; });
}

/** A random repository password: 24 characters from an alphabet without look-alikes */
function generatePassword() {
  const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  const out = [];
  const buf = new Uint32Array(24);
  crypto.getRandomValues(buf);
  buf.forEach((n) => out.push(abc[n % abc.length]));
  return out.join('').replace(/(.{6})(?=.)/g, '$1-');
}

/** Step 1: what, where, the keys and the password */
function kopiaForm(x, form, problem) {
  let done = false;
  const box = el('div');
  if (problem) box.appendChild(callout(problem, true));
  const input = (key, { type = 'text', mono = true, placeholder = '', autocomplete = 'off' } = {}) => {
    const i = el('input', 'input' + (mono ? ' mono' : ''));
    i.type = type;
    i.value = form[key] || '';
    i.placeholder = placeholder;
    i.autocomplete = autocomplete;
    i.spellcheck = false;
    i.autocapitalize = 'off';
    i.oninput = () => { form[key] = i.value; };
    return i;
  };
  const field = (labelText, node, hint) => {
    const f = el('div', 'field');
    f.appendChild(el('label', '', labelText));
    f.appendChild(node);
    if (hint) f.appendChild(el('small', '', hint));
    return f;
  };
  const radios = (name, options, onchange) => {
    const wrap = el('div', 'ad-radios');
    options.forEach(([value, text, hint]) => {
      const label = el('label', 'check');
      const r = el('input');
      r.type = 'radio';
      r.name = name;
      r.value = value;
      r.checked = form[name] === value;
      r.onchange = () => { form[name] = value; onchange(); };
      const span = el('span', '', text);
      if (hint) span.appendChild(el('small', '', hint));
      label.append(r, span);
      wrap.appendChild(label);
    });
    return wrap;
  };
  const parts = el('div');
  const draw = () => {
    parts.innerHTML = '';
    if (form.storage === 's3') {
      const sel = el('select', 'input');
      Object.keys(PROVIDERS).forEach((p) => {
        const o = el('option', '', p === 's3' ? T('kr.provider_other') : PROVIDER_NAMES[p]);
        o.value = p;
        o.selected = form.provider === p;
        sel.appendChild(o);
      });
      const endpoint = input('endpoint', { placeholder: PROVIDERS[form.provider] || '' });
      endpoint.onblur = () => { form.endpoint = endpoint.value = endpoint.value.trim().replace(/^https?:\/\//i, '').replace(/\/+$/, ''); };
      sel.onchange = () => { form.provider = sel.value; endpoint.placeholder = PROVIDERS[sel.value] || ''; };
      parts.append(field(T('kr.provider'), sel),
        field(T('kr.endpoint'), endpoint, T('kr.endpoint_hint')),
        field(T('kr.region'), input('region', { placeholder: 'eu-central-1' })),
        field(T('kr.bucket'), input('bucket')),
        field(T('kr.prefix'), input('prefix', { placeholder: 'unraid/' }), T('kr.prefix_hint')),
        field(T('kr.access_key'), input('access_key')),
        field(T('kr.secret_key'), input('secret_key', { type: 'password', autocomplete: 'new-password' })));
    } else {
      const folders = (x.kopia && x.kopia.folders) || [];
      if (!folders.length) parts.appendChild(callout(T('kr.no_folder'), true));
      else parts.appendChild(field(T('kr.path'), input('path'), T('kr.path_hint', { paths: folders.map((f) => `${f.target} (${f.host})`).join(', ') })));
    }
    const pw = input('password', { type: form.generated ? 'text' : 'password', autocomplete: 'new-password' });
    const pwBox = el('div', 'ad-pw');
    const gen = el('button', 'btn small plain', T('kr.generate'));
    gen.type = 'button';
    gen.onclick = () => { form.password = form.password2 = generatePassword(); form.generated = true; draw(); };
    const show = el('button', 'btn small plain', T('kr.show'));
    show.type = 'button';
    show.onclick = () => { pw.type = pw.type === 'password' ? 'text' : 'password'; };
    pwBox.append(pw, gen, show);
    parts.appendChild(field(T('kr.password'), pwBox, T(form.mode === 'create' ? 'kr.password_hint' : 'kr.password_hint_connect')));
    if (form.mode === 'create') parts.appendChild(field(T('kr.password2'), input('password2', { type: form.generated ? 'text' : 'password', autocomplete: 'new-password' })));
    if (form.mode === 'connect') {
      parts.appendChild(field(T('kr.client'), input('client', { placeholder: 'root@kopia' }), T('kr.client_hint')));
    }
  };
  box.appendChild(el('div', 'field-title', T('kr.mode')));
  box.appendChild(radios('mode', [['create', T('kr.mode_create'), T('kr.mode_create_hint')], ['connect', T('kr.mode_connect'), T('kr.mode_connect_hint')]], draw));
  box.appendChild(el('div', 'field-title', T('kr.storage')));
  box.appendChild(radios('storage', [['s3', T('kr.storage_s3'), T('kr.storage_s3_hint')], ['filesystem', T('kr.storage_fs'), T('kr.storage_fs_hint')]], draw));
  draw();
  box.appendChild(parts);
  const msg = callout('', true);
  msg.hidden = true;
  box.appendChild(msg);
  Office.dialog({
    title: T('kr.title'),
    body: box,
    wide: true,
    onClose: () => { if (!done) wipe(form); },
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('kr.check'), kind: '', act: async () => {
        const bad = kopiaCheck(form);
        if (bad) { msg.className = 'callout warn'; msg.textContent = bad; msg.hidden = false; return false; }
        form.lock = null;
        if (form.storage === 's3' && form.mode === 'create') {
          // a new repository in S3: ask the bucket whether it keeps Object Lock (the keys through RAM, like the setup itself)
          msg.className = 'callout';
          msg.textContent = T('kr.lock_asking');
          msg.hidden = false;
          const j = await Office.api.post('advisor.kopia_repo', { step: 'probe', ...requestFields(form),
            secret: { access_key: form.access_key.trim(), secret_key: form.secret_key.trim() } });
          if (!j.ok) { msg.className = 'callout warn'; msg.textContent = errorOf(j); return false; }
          form.lock = j.lock || { state: 'unknown', why: 'other' };
          const range = form.lock.range || [30, 7, 365];
          form.lockOn = form.lock.state === 'enabled';
          form.lockDays = form.lock.days >= range[1] && form.lock.days <= range[2] ? form.lock.days : range[0];
        }
        done = true;
        setTimeout(() => kopiaPreview(form), 0);
        return true;
      } },
    ],
  });
}

/** The request's fields without the secrets */
function requestFields(form) {
  return { mode: form.mode, storage: form.storage, provider: form.provider, endpoint: form.endpoint.trim(), region: form.region.trim(),
    bucket: form.bucket.trim(), prefix: form.prefix.trim(), path: form.path.trim(), client: form.client.trim() };
}

/**
 * The preview's ransomware protection (a new repository in S3): what the bucket said — Object Lock there:
 * on (compliance) for so many days, with the price; made without it: where providers switch it on; a
 * provider without it: said plainly; couldn't ask: why, and no offer
 */
function lockBox(form) {
  const l = form.lock || { state: 'unknown', why: 'other' };
  const [, min, max] = l.range || [30, 7, 365];
  const box = el('div', 'ad-lock');
  box.appendChild(el('div', 'field-title', T('kr.lock')));
  if (l.state === 'enabled') {
    const label = el('label', 'check');
    const cb = el('input');
    cb.type = 'checkbox';
    cb.checked = form.lockOn;
    const span = el('span', '', T('kr.lock_on'));
    span.appendChild(el('small', '', T('kr.lock_hint')));
    label.append(cb, span);
    const days = el('input', 'input ad-days');
    days.type = 'number';
    days.min = String(min);
    days.max = String(max);
    days.step = '1';
    days.value = String(form.lockDays);
    const f = el('div', 'field');
    f.append(el('label', '', T('kr.lock_days')), days, el('small', '', T('kr.lock_days_hint', { min, max })));
    const cost = callout('');
    const show = () => {
      f.hidden = !form.lockOn;
      cost.hidden = !form.lockOn;
      cost.textContent = T('kr.lock_cost', { days: Number(form.lockDays) || 30 });
    };
    cb.onchange = () => { form.lockOn = cb.checked; show(); };
    days.oninput = () => { form.lockDays = days.value.trim() === '' ? '' : Number(days.value); show(); };
    box.append(label, f, cost);
    if (l.mode && l.days) box.appendChild(el('p', 'ad-note', T('kr.lock_default', { mode: l.mode, days: l.days })));
    show();
  } else if (l.state === 'off') {
    box.appendChild(callout(T('kr.lock_off')));
  } else if (l.state === 'unsupported') {
    box.appendChild(callout(T('kr.lock_unsupported', { provider: PROVIDER_NAMES[form.provider] || T('kr.lock_this') })));
  } else {
    const why = ['keys', 'denied', 'no_bucket', 'unreachable'].includes(l.why) ? l.why : 'other';
    box.appendChild(callout(T('kr.lock_unknown', { why: T(`kr.why_${why}`, { bucket: form.bucket.trim(), endpoint: form.endpoint.trim(), code: String(l.code || '?') }) }), true));
  }
  return box;
}

/** Days kept that will do, or null */
function lockDaysOk(form) {
  const [, min, max] = (form.lock && form.lock.range) || [30, 7, 365];
  const d = Number(form.lockDays);
  return Number.isInteger(d) && d >= min && d <= max ? d : null;
}

/** What the page can tell before sending: missing fields, the passwords, the endpoint's shape */
function kopiaCheck(form) {
  const need = form.storage === 's3' ? ['endpoint', 'bucket', 'access_key', 'secret_key', 'password'] : ['path', 'password'];
  const empty = need.filter((k) => !String(form[k] || '').trim());
  if (empty.length) return T('kr.need', { fields: empty.map((k) => T(`kr.${k}`)).join(', ') });
  if (form.storage === 's3' && !/^[A-Za-z0-9][A-Za-z0-9.-]*(:\d{1,5})?$/.test(form.endpoint.trim())) return T('errors.ad_kopia_field', { field: T('kr.endpoint') });
  if (form.mode === 'create' && form.password.length < 12) return T('kr.short');
  if (form.mode === 'create' && form.password !== form.password2) return T('kr.mismatch');
  return null;
}

/** Step 2: the preview — what Kopia will be asked to do, the secrets only masked */
function kopiaPreview(form) {
  let done = false;
  const x = form.x;
  const name = x.name || 'kopia';
  const mask = (s) => (s.length > 4 ? '•••• ' + s.slice(-4) : '••••');
  const box = el('div');
  box.appendChild(el('p', '', T('kr.preview', { name })));
  box.appendChild(el('p', '', T(form.mode === 'create' ? 'kr.do_create' : 'kr.do_connect')));
  const rows = form.storage === 's3'
    ? [[T('kr.provider'), form.provider === 's3' ? T('kr.provider_other') : PROVIDER_NAMES[form.provider], true], [T('kr.endpoint'), form.endpoint.trim()],
       form.region.trim() && [T('kr.region'), form.region.trim()], [T('kr.bucket'), form.bucket.trim()], form.prefix.trim() && [T('kr.prefix'), form.prefix.trim()],
       [T('kr.access_key'), mask(form.access_key)], [T('kr.secret_key'), '••••••••']]
    : [[T('kr.path'), form.path.trim()]];
  rows.push([T('kr.password'), '••••••••']);
  if (form.mode === 'connect' && form.client.trim()) rows.push([T('kr.client'), form.client.trim()]);
  box.appendChild(props(rows));
  if (form.storage === 's3' && form.mode === 'create') box.appendChild(lockBox(form));
  const bad = callout('', true);
  bad.hidden = true;
  box.appendChild(bad);
  box.appendChild(callout(T('kr.restart')));
  box.appendChild(el('p', 'ad-note', T('kr.keeps', { config: (x.kopia && x.kopia.config) || '/config' })));
  Office.dialog({
    title: T('kr.title'),
    body: box,
    onClose: () => { if (!done) wipe(form); },
    buttons: [
      { text: T('kr.back'), act: () => { done = true; setTimeout(() => kopiaForm(x, form), 0); return true; } },
      { text: T(form.mode === 'create' ? 'kr.go_create' : 'kr.go_connect'), kind: '', act: async () => {
        const locked = form.storage === 's3' && form.mode === 'create' && form.lock && form.lock.state === 'enabled' && form.lockOn;
        if (locked && lockDaysOk(form) === null) {
          const [, min, max] = form.lock.range || [30, 7, 365];
          bad.textContent = T('kr.lock_days_bad', { min, max });
          bad.hidden = false;
          return false;
        }
        const secret = { password: form.password };
        if (form.storage === 's3') { secret.access_key = form.access_key.trim(); secret.secret_key = form.secret_key.trim(); }
        // the web side takes "secret" out of the request and hands it to the agent through RAM only (src/api.php)
        const j = await Office.api.post('advisor.kopia_repo', { ...requestFields(form), lock_days: locked ? lockDaysOk(form) : '', secret });
        done = true;
        if (!j.ok) { setTimeout(() => kopiaForm(x, form, errorOf(j)), 0); return true; }
        await afterAction(j);
        setTimeout(() => kopiaDone(form, j.facts), 0);
        return true;
      } },
    ],
  });
}

/** Step 3: connected — now the recovery sheet; what was typed is forgotten when this closes */
function kopiaDone(form, facts, warned = false) {
  let printed = false;
  let leaving = false;
  const box = el('div');
  box.appendChild(callout(T('kr.done')));
  const lk = facts.lock;
  if (lk && lk.mode) {
    // ransomware protection: switched on now, or what the existing repository keeps (its format)
    const words = form.mode === 'create' ? T('kr.lock_done', { mode: lk.mode, days: lk.days })
      : T('kr.lock_has', { mode: lk.mode, days: lk.days }) + (lk.extend ? ' ' + T('kr.lock_extends') : '');
    box.appendChild(callout(words));
    if (lk.extend === false) box.appendChild(callout(T('kr.lock_extend_no', { days: lk.days }), true));
  } else if (lk && facts.storage === 's3') {
    box.appendChild(el('p', 'ad-note', T('kr.lock_none')));
  }
  box.appendChild(el('p', '', T('kr.sheet_hint')));
  const sheet = el('button', 'btn', T('do.sheet'));
  sheet.type = 'button';
  sheet.onclick = () => { recoverySheet(form, facts); printed = true; };
  box.appendChild(el('p')).appendChild(sheet);
  const warn = callout(T('kr.close_warn'), true);
  warn.hidden = !warned;
  box.appendChild(warn);
  if (Office.desks.has('backup')) {
    const p = el('p', '', T('kr.after') + ' ');
    const a = el('a', '', T('kr.after_link'));
    a.href = '#/backup/setup';
    p.appendChild(a);
    box.appendChild(p);
  }
  if (facts.webui && Office.safeHref(facts.webui)) {
    const p = el('p', 'ad-note', T('ui.kopia') + ' ');
    const a = el('a', '', facts.webui);
    a.href = Office.safeHref(facts.webui);
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    p.appendChild(a);
    box.appendChild(p);
  }
  Office.dialog({
    title: T('kr.title'),
    body: box,
    onClose: () => {
      // Escape or a click beside it before the sheet was made: once more, with the warning
      if (!printed && !warned && !leaving) { setTimeout(() => kopiaDone(form, facts, true), 0); return; }
      wipe(form);
    },
    buttons: [{ text: Office.t('common.close'), act: () => {
      if (printed || warned) { leaving = true; return true; }
      warned = true;
      warn.hidden = false;
      return false;
    } }],
  });
}

// ------------------------------------------------------------------ the recovery sheet
/**
 * Everything needed to get the backups back on a new server — the keys and
 * the password included — on one printable page, made here in the browser
 * and nowhere else: a page of its own in a frame over the office (printing
 * the frame prints only the sheet; no pop-up to be blocked), or in a window
 * of its own. Nothing is sent anywhere, nothing stored; closing it drops it.
 */
function recoverySheet(form, f) {
  const host = document.getElementById('sso') || document.body;
  const overlay = el('div', 'ad-sheet-overlay');
  const bar = el('div', 'ad-sheet-bar');
  const own = el('button', 'btn small plain', T('sheet.window'));
  own.type = 'button';
  const close = el('button', 'btn small', Office.t('common.close'));
  close.type = 'button';
  bar.append(el('span', 'ad-sheet-title', T('sheet.title')), own, close);
  const frame = el('iframe');
  frame.title = T('sheet.title');
  overlay.append(bar, frame);
  host.appendChild(overlay);
  sheetInto(frame.contentWindow, form, f);
  close.onclick = () => overlay.remove();
  own.onclick = () => {
    const w = window.open('', '_blank');
    if (!w) { Office.toast(T('sheet.popup'), true); return; }
    sheetInto(w, form, f);
    try { w.opener = null; } catch (e) { /* not ours to change */ }
    w.focus();
  };
}

/** Writes the sheet into an empty window (the frame's or one of its own): built node by node, no markup from data */
function sheetInto(w, form, f) {
  const d = w.document;
  d.open();
  d.write('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body></body></html>');
  d.close();
  d.documentElement.lang = Office.lang;
  const mk = (tag, cls, text) => {
    const n = d.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  };
  const date = new Date((f.time || Date.now() / 1000) * 1000).toLocaleString(Office.locale);
  d.title = `${T('sheet.title')} — ${f.server}`;
  const style = mk('style');
  style.textContent = SHEET_CSS;
  d.head.appendChild(style);
  const main = mk('main');
  const bar = mk('div', 'bar');
  const print = mk('button', '', T('sheet.print'));
  print.type = 'button';
  print.onclick = () => w.print();
  bar.appendChild(print);
  main.appendChild(bar);
  main.appendChild(mk('h1', '', T('sheet.title')));
  main.appendChild(mk('p', 'meta', `${T('sheet.server')}: ${f.server} · ${T('sheet.date')}: ${date}`));
  main.appendChild(mk('p', 'keep', T('sheet.keep')));
  const section = (title, rows) => {
    main.appendChild(mk('h2', '', title));
    const dl = mk('dl');
    rows.filter(Boolean).forEach(([k, v, secret]) => {
      if (v === null || v === undefined || v === '') return;
      dl.append(mk('dt', '', k), mk('dd', secret ? 'secret' : '', v));
    });
    main.appendChild(dl);
  };
  const s3 = f.storage === 's3';
  const bare = (key) => T(key).replace(/\s*\([^)]*\)\s*$/, '');      // the form's "(optional)" means nothing on the sheet
  section(T('sheet.repo'), [
    [T('sheet.storage'), s3 ? T('kr.storage_s3') : T('kr.storage_fs')],
    s3 && [T('kr.provider'), f.provider === 's3' || !f.provider ? T('kr.provider_other') : PROVIDER_NAMES[f.provider]],
    s3 && [T('kr.endpoint'), f.endpoint], s3 && [bare('kr.region'), f.region], s3 && [T('kr.bucket'), f.bucket], s3 && [bare('kr.prefix'), f.prefix],
    s3 && [T('kr.access_key'), form.access_key.trim(), true], s3 && [T('kr.secret_key'), form.secret_key.trim(), true],
    !s3 && [T('sheet.path_container'), f.path], !s3 && [T('sheet.path_host'), f.path_host],
    [T('kr.password'), form.password, true],
    [T('sheet.client'), f.client],
    s3 && f.lock && [T('sheet.lock'), f.lock.mode ? T('sheet.lock_on', { mode: f.lock.mode, days: f.lock.days }) : T('sheet.lock_off')],
  ]);
  section(T('sheet.kopia'), [
    [T('sheet.container'), f.container], [T('sheet.image'), f.image], [T('sheet.version'), f.version],
    [T('sheet.config'), f.config ? `${f.config} → /config` : null],
    [T('sheet.sources'), f.sources ? `${f.sources.host} → ${f.sources.target} (ro,slave)` : null],
    [T('sheet.restore'), f.restore ? `${f.restore.host} → ${f.restore.target}` : null],
    [T('sheet.webui'), f.webui],
  ]);
  main.appendChild(mk('h2', '', T('sheet.steps')));
  const ol = mk('ol');
  const target = (f.sources && f.sources.target) || '/uso';
  const hostPath = (f.sources && f.sources.host) || '/mnt/addons/UnraidSecretaryOffice/snapshots';
  [T('sheet.step1', { image: f.image || 'ghcr.io/imagegenius/kopia' }), T('sheet.step2', { target, host: hostPath }),
   !s3 && T('sheet.step_fs', { path: f.path, host: f.path_host }),
   T('sheet.step3', { client: f.client || 'root@kopia' }), T('sheet.step4'),
   s3 && f.lock && f.lock.mode && T('sheet.step_lock', { days: f.lock.days })].filter(Boolean).forEach((x) => ol.appendChild(mk('li', '', x)));
  main.appendChild(ol);
  main.appendChild(mk('p', '', T('sheet.cli')));
  const cli = s3
    ? ['kopia repository connect s3', `--bucket=${f.bucket}`, `--endpoint=${f.endpoint}`, f.region && `--region=${f.region}`, f.prefix && `--prefix=${f.prefix}`,
       `--access-key=<${T('kr.access_key')}>`, `--secret-access-key=<${T('kr.secret_key')}>`].filter(Boolean).join(' \\\n  ')
    : `kopia repository connect filesystem --path=${f.path}`;
  const client = (f.client || 'root@kopia').split('@');
  main.appendChild(mk('pre', '', `${cli}\nkopia repository set-client --username=${client[0]} --hostname=${client[1] || 'kopia'}`));
  main.appendChild(mk('p', 'foot', T('sheet.foot', { date })));
  d.body.appendChild(main);
}

const SHEET_CSS = `
:root{color-scheme:light}
*{box-sizing:border-box}
body{margin:0;padding:16px;background:#fff;color:#111;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
main{max-width:760px;margin:0 auto}
h1{font-size:22px;margin:0 0 4px}
h2{font-size:16px;margin:22px 0 8px;padding-bottom:3px;border-bottom:1px solid #999}
.meta{color:#444;margin:0 0 12px}
.keep{border:2px solid #b00020;padding:10px 12px;margin:0 0 8px;font-weight:600}
dl{display:grid;grid-template-columns:minmax(0,13em) minmax(0,1fr);gap:7px 14px;margin:0}
dt{color:#444}
dd{margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;font-size:13.5px;overflow-wrap:anywhere;word-break:break-all}
dd.secret{font-size:15px;font-weight:700;letter-spacing:.03em}
ol{padding-left:22px;margin:0 0 10px}
li{margin:0 0 7px}
pre{margin:0;white-space:pre-wrap;word-break:break-all;background:#f2f2f2;border:1px solid #ddd;padding:8px 10px;font:12.5px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.foot{margin-top:22px;color:#555;font-size:12px}
.bar{margin:0 0 14px}
button{font:inherit;padding:8px 16px;cursor:pointer}
@media (max-width:520px){body{padding:12px}dl{grid-template-columns:minmax(0,1fr);gap:2px}dd{margin-bottom:8px}}
@media print{.bar{display:none}body{padding:0}h2{break-after:avoid}dl,li,pre{break-inside:avoid}}
@page{margin:16mm}
`;

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root) {
    view = root;
    render();
    await load(false);
  },
  unmount() { view = null; },
  poll() { load(false); },
  agentChanged() { if (view) render(); },
  async reception() {
    if (!state) await load(false);
    if (!state) return null;
    const facts = externals().filter(([, x]) => x.there || !x.optional)
      .map(([id, x]) => T(x.there ? (x.kind === 'container' && !x.running ? 'fact.stopped' : 'fact.there') : 'fact.missing',
        { name: T(`ext.${id}.name`) }));
    return { bubble: bubbleText(), facts };
  },
});

// tests/run.php runs this under node (Unraid's own) - never set in a browser
if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.advisor = { setState: (s) => { state = s; }, textfileChip };
}
})();
