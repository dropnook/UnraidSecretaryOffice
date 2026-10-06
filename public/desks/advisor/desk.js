/* The Consultant — from outside the office. He knows the externals the office
   relies on but doesn't make itself (Fix Common Problems, Files Viewer, Kopia,
   Stream Viewer; unbalanced only for whoever wants it; and, optional, the
   monitoring: Node Exporter → Prometheus → Grafana, Loki later): whether
   they are there, what they are good for, who in the office needs them, and
   how to install them by hand. Read only. The agent part lives in
   agent/desks/advisor.php. */
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
  // is copied, {ip} becomes the server's address, {dir} the office's metrics folder
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
global:
  scrape_interval: 60s

scrape_configs:
  - job_name: prometheus
    static_configs:
      - targets: ['localhost:9090']
  - job_name: node
    static_configs:
      - targets: ['{ip}:9100']
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

let state = null;
let view = null;

async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  if (view) render();
  return j;
}

const externals = () => Object.entries((state && state.externals) || {}).filter(([id]) => EXTERNALS[id]);
const missing = () => externals().filter(([, x]) => !x.there && !x.optional);
const names = (list) => list.map(([id]) => T(`ext.${id}.name`)).join(', ');
const group = (g) => externals().filter(([, x]) => (x.group || null) === g);
const begun = (g) => group(g).some(([, x]) => x.there && !x.later);
/** Monitoring begun but not complete: what is still missing (Loki, for later, doesn't count) */
const gaps = () => (begun('monitoring') ? group('monitoring').filter(([, x]) => !x.there && !x.later) : []);

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

/** An external's values to copy, {ip} and {dir} filled in */
function copies(id) {
  const ip = serverIp() || '<server-ip>';
  const dir = state.metrics_dir || '';
  return Object.fromEntries(Object.entries(EXTERNALS[id].copy).map(([k, v]) => [k, v.replaceAll('{ip}', ip).replaceAll('{dir}', dir)]));
}

/** A link into Unraid's web UI: same tab inside Unraid, a new one from the stack's page of its own */
function unraidLink(path, text, kind) {
  const a = el('a', 'btn small' + (kind ? ' ' + kind : ''), text);
  if (Office.config.in_unraid) {
    a.href = path;
  } else {
    if (!state.gui || !Office.safeHref(state.gui + path)) return null;
    a.href = Office.safeHref(state.gui + path);
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
  }
  return a;
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
    [T('howto'), T('help.howto')],
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
  if (x.textfile === true || x.textfile === false) {     // the node exporter: does it read the office's folder?
    const yes = x.textfile;
    const chip = el('span', 'chip ' + (yes ? 'ok' : 'warn'), T(yes ? 'textfile_yes' : 'textfile_no'));
    chip.title = T(yes ? 'textfile_yes_tip' : 'textfile_no_tip', { dir: state.metrics_dir || '' });
    meta.appendChild(chip);
  }
  if (x.version) meta.appendChild(el('span', '', 'v' + x.version));
  if (x.name) meta.appendChild(el('span', 'mono', x.name));
  if (e.desk && Office.desks.has(e.desk)) {
    const d = Office.desks.get(e.desk);
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
  row.appendChild(main);

  const acts = el('div', 'ad-acts');
  const byKind = typeof e.open === 'object';     // a plugin or a container (the node exporter)
  const open = byKind ? e.open[x.kind] : e.open;
  const link = x.there ? unraidLink(open, T(byKind && x.kind === 'plugin' ? `open.${id}_plugin` : `open.${id}`), 'plain')
    : e.install ? unraidLink(e.install, T('install'), '') : null;
  if (link) acts.appendChild(link);
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
  const src = x.icon && (Office.config.in_unraid ? x.icon : state.gui && state.gui + x.icon);
  if (!src) { box.textContent = e.icon; return box; }
  const img = el('img');
  img.alt = '';
  img.src = src;
  img.onerror = () => { box.innerHTML = ''; box.textContent = e.icon; };
  box.appendChild(img);
  return box;
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
  return det;
}

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
})();
