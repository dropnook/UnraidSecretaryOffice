/* The Consultant — from outside the office. He knows the externals the office
   relies on but doesn't make itself (Fix Common Problems, Files Viewer, Kopia,
   Stream Viewer; unbalanced only for whoever wants it): whether
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
    copy: { image: 'ghcr.io/imagegenius/kopia', path: '/mnt/addons/UnraidSecretaryOffice/snapshots', target: '/backup-snapshots' },
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

function bubbleText() {
  if (!state) return T('bubble.loading');
  const m = missing();
  return m.length ? T('bubble.missing', { names: names(m), n: m.length }) : T('bubble.all_there');
}

/** A link into Unraid's web UI: same tab inside Unraid, a new one from the stack's page of its own */
function unraidLink(path, text, kind) {
  const a = el('a', 'btn small' + (kind ? ' ' + kind : ''), text);
  if (Office.config.in_unraid) {
    a.href = path;
  } else {
    if (!state.gui) return null;
    a.href = state.gui + path;
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
    [el('span', 'chip warn', T('stopped')), T('help.stopped')],
    [T('howto'), T('help.howto')],
    [T('look_again'), T('help.again')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }

  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('externals'), T('externals_sub')));
  externals().forEach(([id, x]) => s.appendChild(external(id, x)));
  root.appendChild(s);
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
  if (!x.there && x.optional) meta.appendChild(el('span', 'chip quiet', T('absent')));
  else if (!x.there) meta.appendChild(el('span', 'chip danger', T('missing')));
  else if (x.kind === 'container' && !x.running) meta.appendChild(el('span', 'chip warn', T('stopped')));
  else meta.appendChild(el('span', 'chip ok', T('there')));
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
  const link = x.there ? unraidLink(e.open, T(`open.${id}`), 'plain') : unraidLink(e.install, T('install'), '');
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

/** "Install by hand": the steps (lang keys install.<id>.1 …), what to copy below them */
function howto(id, x) {
  const e = EXTERNALS[id];
  const det = el('details', 'ad-howto');
  det.open = !x.there && !x.optional;      // missing: the steps right away
  det.appendChild(el('summary', '', T('howto')));
  const ol = el('ol', 'ad-steps');
  for (let i = 1; Office.has(`${ID}.install.${id}.${i}`); i++) ol.appendChild(el('li', '', T(`install.${id}.${i}`, { ...e.copy, media: state.media || 'Emby' })));
  det.appendChild(ol);
  const copies = el('div', 'ad-copies');
  Object.entries(e.copy).forEach(([key, value]) => {
    const line = el('div', 'ad-copy');
    line.append(el('span', 'ad-copy-label', T(`copy.${id}.${key}`)), el('code', '', value));
    const b = el('button', 'btn small plain', Office.t('common.copy'));
    b.type = 'button';
    b.onclick = () => Office.copy(value);
    line.appendChild(b);
    copies.appendChild(line);
  });
  det.appendChild(copies);
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
