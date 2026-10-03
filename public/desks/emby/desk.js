/* Jack Emby — the intern and Emby fan ("check Emby"). He looks after EmbyCache
   (github.com/helmi1987/embycache-for-unraid): it keeps what people are about
   to watch on the fast pool, so the array disks can sleep. He fetches and
   updates it from its public repository, sets it up (#/emby/setup), runs it
   and shows what it does. The agent part lives in agent/desks/emby.php. */
(() => {
'use strict';

const ID = 'emby';
const T = Office.scope(ID);
const { el, fmt } = Office;
const REPO = 'https://github.com/helmi1987/embycache-for-unraid';
const POLL = 3000;

let state = null;
let view = null;
let page = 'main';            // main | setup
let outTimer = null;

// ------------------------------------------------------------------ loading
async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  if (view && page === 'main') render();
  return j;
}

async function act(action, data, okText) {
  const j = await Office.api.post(`${ID}.${action}`, data || {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return null; }
  if (j.state) state = j.state;
  if (okText) Office.toast(okText);
  if (view && page === 'main') render();
  return j;
}

// ------------------------------------------------------------------ helpers
function button(text, kind, onclick) {
  const b = el('button', 'btn' + (kind ? ' ' + kind : ''), text);
  b.type = 'button';
  b.onclick = onclick;
  return b;
}
function chip(text, cls, title) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (title) c.title = title;
  return c;
}
function section(title, sub, ...extra) {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(title, sub, ...extra));
  return s;
}
function stat(box, label, value, sub, alert) {
  const s = el('div', 'stat' + (alert ? ' alert' : ''));
  s.append(el('div', 'stat-label', label), el('div', 'stat-value', value));
  if (sub) s.appendChild(el('div', 'stat-sub', sub));
  box.appendChild(s);
}
const ready = () => !!(state && state.installed && Office.agent.running);

function bubbleText() {
  if (!state) return T('bubble.loading');
  const intro = T('bubble.intro');
  if (!state.emby.length) return `${intro} ${T('bubble.no_emby')}`;
  if (!state.python) return `${intro} ${T('bubble.no_python')}`;
  if (!state.installed) return `${intro} ${T('bubble.fetch')}`;
  if (!state.configured) return `${intro} ${T('bubble.setup')}`;
  if (state.running) return T('bubble.running');
  const c = state.cache;
  const parts = [c.files ? T('bubble.on_pool', { n: c.files, size: fmt.size(c.bytes) }) : T('bubble.nothing_yet')];
  if (state.update && state.update.behind) parts.push(T('bubble.update', { n: state.update.behind }));
  if (!state.schedule.enabled) parts.push(T('bubble.no_schedule'));
  return parts.join(' ');
}

// ------------------------------------------------------------------ main view
function render() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  const actions = [];
  if (state && state.installed) {
    actions.push(button(T('setup_open'), 'plain', () => Office.go(`#/${ID}/setup`)));
    if (state.configured) {
      actions.push(button(T('report'), 'plain', () => startRun('report')));
      actions.push(button(T('start'), '', chooseRun));
    }
  } else if (state && state.emby.length && state.python) {
    actions.push(button(T('fetch'), '', fetchTool));
  }
  actions.forEach((b) => { b.disabled = !Office.agent.running || (state && state.running); });
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: bubbleText(), actions });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID, [
    [T('help.what'), T('help.what_text')],
    [T('help.tool'), T('help.tool_text')],
    [T('report'), T('help.report')],
    [T('mode.dry'), T('help.dry')],
    [T('mode.run'), T('help.run')],
    [T('help.pool'), T('help.pool_text')],
    [T('help.schedule'), T('help.schedule_text')],
  ]));
  if (!state) return;

  if (!state.emby.length) root.appendChild(el('p', 'callout warn', T('notice.no_emby')));
  if (!state.python) root.appendChild(el('p', 'callout warn', T('notice.no_python')));

  root.appendChild(toolSection());
  if (!state.installed) return;
  if (!state.configured) {
    const p = el('p', 'callout', T('notice.setup') + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    root.appendChild(p);
    return;
  }
  root.appendChild(overview());
  root.appendChild(poolSection());
}

/** EmbyCache itself: version, updates */
function toolSection() {
  const s = section(T('tool'), T('tool_sub'));
  const stats = el('div', 'stats');
  if (!state.installed) {
    stat(stats, 'EmbyCache', T('not_fetched'), T('not_fetched_sub'));
  } else {
    const v = state.version || {};
    stat(stats, 'EmbyCache', v.version ? v.version.replace(/\s*\(.*\)$/, '') : (v.commit || '?'),
      [v.commit, v.time ? fmt.date(v.time) : null].filter(Boolean).join(' · '), v.changed);
    const u = state.update;
    const sub = u ? T('checked', { when: fmt.relative(u.checked) }) : T('never_checked');
    stat(stats, T('updates'), u ? (u.behind ? T('behind', { n: u.behind }) : T('up_to_date')) : '–', sub, !!(u && u.behind));
    stat(stats, 'Python', state.python || T('none'), T('python_sub'));
  }
  s.appendChild(stats);
  if (state.installed) {
    const bar = el('div', 'toolbar');
    bar.appendChild(button(T('check_updates'), 'small plain', () => act('check_updates', {}, T('checked_now'))));
    if (state.update && state.update.behind) bar.appendChild(button(T('do_update'), 'small', updateTool));
    const a = el('a', 'btn small plain', 'GitHub');
    a.href = REPO;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    bar.appendChild(a);
    s.appendChild(bar);
    if (state.version && state.version.changed) s.appendChild(el('p', 'callout warn', T('notice.changed')));
    if (state.update && state.update.commits && state.update.commits.length) {
      const ul = el('ul', 'shortlist');
      state.update.commits.forEach((c) => {
        const li = el('li', '', c.subject);
        li.appendChild(el('span', '', `${c.commit} · ${fmt.date(c.time)}`));
        ul.appendChild(li);
      });
      s.appendChild(ul);
    }
  }
  return s;
}

/** The settings in short, the last run, the schedule */
function overview() {
  const s = section(T('overview'), T('overview_sub'));
  const stats = el('div', 'stats');
  const set = state.settings || {};
  const inst = set.instances || [];
  stat(stats, 'Emby', inst.map((i) => i.servername).join(', ') || '–', inst.map((i) => i.url).join(', '));
  stat(stats, T('mode'), set.cache_budget ? T('budget', { size: set.cache_budget }) : T('count', { n: set.number_episodes || 0 }),
    T('pool_free', { pool: set.cache_path || '?', n: set.min_free_percent ?? '?' }));
  const users = Array.isArray(set.valid_users) ? set.valid_users.length : Object.keys(set.valid_users || {}).length;
  stat(stats, T('libraries'), (set.libraries || []).join(', ') || T('all'), users ? T('users_n', { n: users }) : T('users_all'));
  const last = state.last;
  stat(stats, T('last_run'), last && last.time ? fmt.relative(last.time) : T('never'),
    last ? T('counts', { errors: last.errors, warnings: last.warnings }) : '', !!(last && last.errors));
  const sc = state.schedule || {};
  stat(stats, T('schedule'), sc.enabled ? (sc.frequency === 'custom' ? fmt.cron(sc.custom) : sc.frequency) : T('not_scheduled'),
    sc.script ? `User Scripts: ${sc.script}` : T('no_script'), !sc.enabled);
  s.appendChild(stats);

  if (last && last.results && last.results.length) {
    const box = el('div', 'box jo-results');
    last.results.forEach((r) => box.appendChild(el('div', 'mono', r)));
    s.appendChild(box);
  }
  const bar = el('div', 'toolbar');
  bar.appendChild(button(T('show_log'), 'small plain', showLog));
  if (state.output) bar.appendChild(button(T('show_output', { mode: T('mode.' + state.output.mode) }), 'small plain', () => showOutput(false)));
  s.appendChild(bar);
  if (!sc.enabled) s.appendChild(scheduleHint());
  return s;
}

function scheduleHint() {
  const box = el('div', 'callout');
  box.appendChild(el('div', '', T('schedule_hint')));
  const cmd = `#!/bin/bash\nEMBYCACHE_DIR=${state.data_dir || '/mnt/user/appdata/UnraidSecretaryOffice/data/embycache'} python3 ${state.app_dir || '…/app'}/embycache_run.py --run`;
  const pre = el('pre', 'code', cmd);
  const b = button(Office.t('common.copy'), 'small plain', () => Office.copy(cmd));
  box.append(pre, b);
  return box;
}

/** What EmbyCache keeps on the pool right now */
function poolSection() {
  const c = state.cache;
  const s = section(T('on_pool'), c.listed_at ? T('on_pool_sub', { when: fmt.relative(c.listed_at) }) : T('on_pool_none'),
    el('span', 'hint', c.files ? T('pool_sum', { n: c.files, size: fmt.size(c.bytes) }) : ''));
  if (!c.files) return s;
  const box = el('div', 'box');
  const most = Math.max(...c.groups.map((g) => g.bytes), 1);
  c.groups.slice(0, 60).forEach((g) => {
    const row = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', g.title.replace(/\./g, ' ')));
    const meta = el('div', 'row-meta');
    meta.append(chip(g.share, 'quiet'), el('span', '', T('files_n', { n: g.files })));
    main.appendChild(meta);
    const bar = el('div', 'bar thin jo-bar');
    const fill = el('i', 'snaps');
    fill.style.width = Math.max(2, Math.round(g.bytes / most * 100)) + '%';
    bar.appendChild(fill);
    const fig = el('div', 'figures');
    fig.append(el('b', '', fmt.size(g.bytes)), el('span', '', ''));
    row.append(main, bar, fig);
    box.appendChild(row);
  });
  s.appendChild(box);
  return s;
}

// ------------------------------------------------------------------ actions
async function fetchTool() {
  Office.toast(T('fetching'));
  await act('install', {}, T('fetched'));
}

function updateTool() {
  Office.dialog({
    title: T('do_update'),
    body: el('p', '', T('update_text', { n: state.update.behind })),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('do_update'), kind: '', act: async () => !!(await act('update', {}, T('updated'))) }],
  });
}

function chooseRun() {
  const box = el('div');
  let mode = 'dry';
  ['dry', 'run'].forEach((m) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'jo-mode';
    input.checked = m === mode;
    input.onchange = () => { mode = m; };
    const text = el('span', '', T('mode.' + m));
    text.appendChild(el('small', '', T('mode_hint.' + m)));
    label.append(input, text);
    box.appendChild(label);
  });
  Office.dialog({
    title: T('start_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('start_go'), kind: '', act: () => startRun(mode) }],
  });
}

async function startRun(mode) {
  const j = await act('start_run', { mode });
  if (!j) return false;
  showOutput(true);
  return true;
}

/** The output of the last report / dry run / run, following it while it runs */
async function showOutput(follow) {
  clearTimeout(outTimer);
  const pre = el('pre', 'code', Office.t('common.loading'));
  const status = el('p', 'role');
  const box = el('div');
  box.append(status, pre);
  const dlg = Office.dialog({ title: T('output_title'), body: box, wide: true, onClose: () => clearTimeout(outTimer) });
  const tick = async () => {
    const j = await Office.api.post(`${ID}.output`, {});
    if (!j.ok) { pre.textContent = Office.errorText(j.error, ID); return; }
    const atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
    pre.textContent = j.text || T('output_empty');
    const info = j.info || {};
    status.textContent = info.mode ? `${T('mode.' + info.mode)} · ${info.running ? T('output_running') : T('output_done')} · ${fmt.date(info.started)}` : '';
    if (atEnd || follow) pre.scrollTop = pre.scrollHeight;
    if (info.running) outTimer = setTimeout(tick, POLL);
    else if (follow) { follow = false; load(true); }
  };
  tick();
  return dlg;
}

async function showLog() {
  const pre = el('pre', 'code', Office.t('common.loading'));
  Office.dialog({ title: 'embycache.log', body: pre, wide: true });
  const j = await Office.api.post(`${ID}.log`, {});
  pre.textContent = j.ok ? (j.text || T('output_empty')) : Office.errorText(j.error, ID);
  pre.scrollTop = pre.scrollHeight;
}

// ------------------------------------------------------------------ setup (#/emby/setup)
let form = null;        // { instance:{servername,url,api_key}, server, libraries, users, choice:{…} }

function initForm() {
  const set = state.settings || {};
  const inst = (set.instances || [])[0];
  const container = state.emby[0] || {};
  form = {
    instance: { servername: inst ? inst.servername : (container.name || 'Emby'), url: inst ? inst.url : (container.url || ''), api_key: '', has_key: !!(inst && inst.has_key) },
    server: null, libraries: [], users: [],
    mappings: { ...((inst && inst.path_mappings) || {}) },
    chosenLibs: new Set(set.libraries || []),
    chosenUsers: new Set(Array.isArray(set.valid_users) ? set.valid_users : Object.keys(set.valid_users || {})),
    values: {
      cache_path: set.cache_path && state.pools.includes(set.cache_path) ? set.cache_path : (state.pools[0] || ''),
      budget_mode: !!set.cache_budget,
      cache_budget: set.cache_budget || '',
      number_episodes: set.number_episodes ?? 3,
      movie_share_percent: set.movie_share_percent ?? 50,
      max_episodes_per_series: set.max_episodes_per_series ?? 0,
      max_resume_items: set.max_resume_items ?? 10,
      max_favorite_series: set.max_favorite_series ?? 10,
      use_next_up: set.use_next_up ?? true,
      min_free_percent: set.min_free_percent ?? 20,
      movie_mode: set.movie_mode || 'folder',
    },
  };
}

/** Docker path -> host path: from the Emby container's own mounts (longest match) */
function suggestMapping(path) {
  const mounts = (state.emby[0] || {}).mounts || {};
  let best = null;
  for (const [dest, src] of Object.entries(mounts)) {
    if ((path === dest || path.startsWith(dest + '/')) && (!best || dest.length > best[0].length)) best = [dest, src];
  }
  return best ? best[1] + path.slice(best[0].length) : '';
}

function renderSetup() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  const back = button(T('back'), 'plain', () => Office.go(`#/${ID}`));
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: T('setup.bubble'), actions: [back] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('setup.key'), T('setup.help_key')],
    [T('setup.mapping'), T('setup.help_mapping')],
    [T('setup.scope'), T('setup.help_scope')],
    [T('setup.save'), T('setup.help_save')],
  ]));
  if (!state) return;
  if (!state.installed) { root.appendChild(el('p', 'callout warn', T('notice.fetch_first'))); return; }
  if (!form) initForm();

  // 1. server
  const s1 = section(T('setup.server'), T('setup.server_sub'));
  const f1 = el('div', 'jo-form');
  const name = input(form.instance.servername, (v) => { form.instance.servername = v; });
  const url = input(form.instance.url, (v) => { form.instance.url = v; });
  const key = input('', (v) => { form.instance.api_key = v.trim(); });
  key.type = 'password';
  key.autocomplete = 'off';
  key.placeholder = form.instance.has_key ? T('setup.key_kept') : T('setup.key_placeholder');
  f1.append(field(T('setup.name'), name), field(T('setup.url'), url, T('setup.url_hint')), field(T('setup.key'), key, T('setup.key_hint')));
  s1.appendChild(f1);
  const connect = button(T('setup.connect'), '', async () => {
    connect.disabled = true;
    const j = await Office.api.post(`${ID}.connect`, { url: form.instance.url, api_key: form.instance.api_key });
    connect.disabled = false;
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
    form.server = j.server;
    form.libraries = j.libraries;
    form.users = j.users;
    if (!form.chosenLibs.size) j.libraries.filter((l) => ['movies', 'tvshows'].includes(l.type)).forEach((l) => form.chosenLibs.add(l.name));
    for (const lib of j.libraries) for (const p of lib.locations) if (!form.mappings[p]) form.mappings[p] = suggestMapping(p);
    renderSetup();
  });
  const bar = el('div', 'toolbar');
  bar.appendChild(connect);
  if (form.server) bar.appendChild(chip(T('setup.connected', { name: form.server.name, version: form.server.version }), 'ok'));
  s1.appendChild(bar);
  root.appendChild(s1);
  if (!form.server) return;

  // 2. libraries and their paths
  const s2 = section(T('setup.libraries'), T('setup.libraries_sub'));
  const box2 = el('div', 'box');
  form.libraries.forEach((lib) => {
    const row = el('div', 'row nocheck jo-lib');
    const main = el('div', 'row-main');
    main.appendChild(check(`${lib.name}`, form.chosenLibs.has(lib.name), (v) => { if (v) form.chosenLibs.add(lib.name); else form.chosenLibs.delete(lib.name); renderSetup(); },
      T('type.' + (lib.type || 'other'))));
    if (form.chosenLibs.has(lib.name)) {
      lib.locations.forEach((p) => {
        const inp = input(form.mappings[p] || '', (v) => { form.mappings[p] = v.trim(); });
        inp.classList.add('mono');
        main.appendChild(field(T('setup.map', { path: p }), inp));
      });
    }
    row.appendChild(main);
    box2.appendChild(row);
  });
  s2.appendChild(box2);
  root.appendChild(s2);

  // 3. users
  const s3 = section(T('setup.users'), T('setup.users_sub'));
  const f3 = el('div', 'jo-users');
  form.users.forEach((u) => f3.appendChild(check(u.name, form.chosenUsers.has(u.id), (v) => { if (v) form.chosenUsers.add(u.id); else form.chosenUsers.delete(u.id); }, u.admin ? T('setup.admin') : '')));
  s3.appendChild(f3);
  root.appendChild(s3);

  // 4. pool and scope
  const v = form.values;
  const s4 = section(T('setup.scope'), T('setup.scope_sub'));
  const f4 = el('div', 'jo-form');
  const pool = el('select', 'picker');
  state.pools.forEach((p) => pool.appendChild(new Option(p, p)));
  pool.value = v.cache_path;
  pool.onchange = () => { v.cache_path = pool.value; };
  f4.appendChild(field(T('setup.pool'), pool, T('setup.pool_hint')));
  const mode = el('select', 'picker');
  mode.append(new Option(T('setup.mode_count'), 'count'), new Option(T('setup.mode_budget'), 'budget'));
  mode.value = v.budget_mode ? 'budget' : 'count';
  mode.onchange = () => { v.budget_mode = mode.value === 'budget'; renderSetup(); };
  f4.appendChild(field(T('setup.mode'), mode, T(v.budget_mode ? 'setup.mode_budget_hint' : 'setup.mode_count_hint')));
  if (v.budget_mode) {
    f4.appendChild(field(T('setup.budget'), input(v.cache_budget, (x) => { v.cache_budget = x.trim(); }), T('setup.budget_hint')));
    f4.appendChild(field(T('setup.movie_share'), number(v, 'movie_share_percent', 0, 100)));
    f4.appendChild(field(T('setup.max_per_series'), number(v, 'max_episodes_per_series', 0, 999), T('setup.zero_budget')));
  } else {
    f4.appendChild(field(T('setup.episodes'), number(v, 'number_episodes', 0, 99)));
  }
  f4.appendChild(field(T('setup.resume'), number(v, 'max_resume_items', 0, 999)));
  f4.appendChild(field(T('setup.favorites'), number(v, 'max_favorite_series', 0, 999), T('setup.zero_off')));
  f4.appendChild(field(T('setup.min_free'), number(v, 'min_free_percent', 0, 95), T('setup.min_free_hint')));
  const mm = el('select', 'picker');
  mm.append(new Option(T('setup.movie_folder'), 'folder'), new Option(T('setup.movie_file'), 'file'));
  mm.value = v.movie_mode;
  mm.onchange = () => { v.movie_mode = mm.value; };
  f4.appendChild(field(T('setup.movie_mode'), mm));
  f4.appendChild(check(T('setup.next_up'), v.use_next_up, (x) => { v.use_next_up = x; }, T('setup.next_up_hint')));
  s4.appendChild(f4);
  root.appendChild(s4);

  const save = button(T('setup.save'), '', saveSetup);
  const foot = el('div', 'toolbar');
  foot.append(save, el('span', 'role', T('setup.save_hint')));
  root.appendChild(foot);
}

function input(value, onchange) {
  const i = el('input', 'input');
  i.value = value ?? '';
  i.oninput = () => onchange(i.value);
  return i;
}
function number(obj, key, min, max) {
  const i = el('input', 'input');
  i.type = 'number';
  i.min = min;
  i.max = max;
  i.value = obj[key];
  i.oninput = () => { obj[key] = Number(i.value); };
  return i;
}
function field(label, inputEl, hint) {
  const f = el('div', 'field');
  f.append(el('label', '', label), inputEl);
  if (hint) f.appendChild(el('small', '', hint));
  return f;
}
function check(text, checked, onchange, small) {
  const label = el('label', 'check');
  const i = el('input');
  i.type = 'checkbox';
  i.checked = !!checked;
  i.onchange = () => onchange(i.checked);
  const span = el('span', '', text);
  if (small) span.appendChild(el('small', '', small));
  label.append(i, span);
  return label;
}

async function saveSetup() {
  const v = form.values;
  const mappings = {};
  for (const lib of form.libraries) {
    if (!form.chosenLibs.has(lib.name)) continue;
    for (const p of lib.locations) {
      if (!form.mappings[p] || !form.mappings[p].startsWith('/mnt/')) { Office.toast(T('setup.map_missing', { path: p }), true); return; }
      mappings[p] = form.mappings[p];
    }
  }
  const settings = {
    instances: [{ servername: form.instance.servername, url: form.instance.url, api_key: form.instance.api_key, path_mappings: mappings }],
    libraries: [...form.chosenLibs],
    valid_users: [...form.chosenUsers],
    cache_path: v.cache_path,
    cache_budget: v.budget_mode ? v.cache_budget : '',
    number_episodes: v.number_episodes,
    movie_share_percent: v.movie_share_percent,
    max_episodes_per_series: v.max_episodes_per_series,
    max_resume_items: v.max_resume_items,
    max_favorite_series: v.max_favorite_series,
    use_next_up: v.use_next_up,
    min_free_percent: v.min_free_percent,
    movie_mode: v.movie_mode,
  };
  const j = await act('save', { settings });
  if (!j) return;
  Office.toast(T('setup.saved'));
  form = null;
  Office.go(`#/${ID}`);
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root, sub) {
    view = root;
    page = sub === 'setup' ? 'setup' : 'main';
    if (page === 'setup') {
      if (!state) await load(false);
      renderSetup();
    } else {
      render();
      load(false);
    }
  },
  unmount() { view = null; clearTimeout(outTimer); },
  poll() { if (page === 'main') load(false); },
  agentChanged() { if (view) (page === 'setup' ? renderSetup() : render()); },
  menu() {
    const items = [{ text: T('menu.refresh'), act: () => load(true) }];
    if (state && state.installed) {
      items.push({ text: T('check_updates'), act: () => act('check_updates', {}, T('checked_now')) });
      items.push({ text: T('show_log'), act: showLog });
    }
    return items;
  },
  async reception() {
    if (!state) await load(false);
    const facts = [];
    if (state && state.installed && state.configured) {
      facts.push(T('fact.pool', { n: state.cache.files, size: fmt.size(state.cache.bytes) }));
      if (state.last && state.last.time) facts.push(T('fact.last', { when: fmt.relative(state.last.time) }));
    } else {
      facts.push(T(state && state.installed ? 'fact.setup' : 'fact.fetch'));
    }
    return { bubble: T('bubble.hello_short'), facts };
  },
});
})();
