/* Unraid Secretary Office — core
   Builds the office (language, top bar, reception, routing between desks) and
   provides what every desk needs: API, dialogs, menus, toasts, formatting.
   No framework, no build step. Desks register themselves with Office.desk().

   A desk is a plain object:
     id          folder name under public/desks/
     mount(root, sub)   render into root; sub is the rest of the URL hash
     unmount()          optional, when another desk is shown
     reception()        optional, async: { bubble, facts[] } for the reception
     poll()             optional, called every minute while the desk is shown
   Its strings live in public/desks/<id>/lang/<code>.json and are reached as
   t('<id>.<key>'), or with Office.scope('<id>') as a shortcut. */
(() => {
'use strict';

const CONFIG = JSON.parse(document.getElementById('office-config').textContent);
const STORE  = 'office.';
const POLL   = 60000;

const Office = window.Office = {
  config: CONFIG,
  agent: { running: false },
  desks: new Map(),
  current: null,
  lang: 'en',
  locale: 'en-GB',
  strings: {},
};

// ------------------------------------------------------------------ small helpers
const $  = (s, root) => (root || document).querySelector(s);
const el = (tag, cls, text) => {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (text !== undefined && text !== null) n.textContent = text;
  return n;
};
Office.$ = $;
Office.el = el;

Office.store = function store(key, value) {
  try {
    if (value === undefined) return localStorage.getItem(STORE + key);
    if (value === null) localStorage.removeItem(STORE + key);
    else localStorage.setItem(STORE + key, value);
  } catch (e) { /* private mode, blocked storage */ }
  return null;
};
Office.storeJson = function storeJson(key, value) {
  if (value !== undefined) { Office.store(key, JSON.stringify(value)); return value; }
  try { return JSON.parse(Office.store(key)); } catch (e) { return null; }
};

// ------------------------------------------------------------------ i18n
let plural = null;

/** t('key', {n: 3, name: 'x'}) — plurals as {one, other, …}, placeholders as {name} */
function t(key, params) {
  let s = Office.strings[key];
  if (s === undefined || s === null) return key;   // visible on purpose: missing strings get noticed
  if (typeof s === 'object') {
    const n = params && typeof params.n === 'number' ? params.n : 0;
    let cat = 'other';
    try { cat = plural.select(n); } catch (e) { /* unknown locale */ }
    s = s[n === 0 && s.zero !== undefined ? 'zero' : cat] ?? s.other ?? '';
  }
  if (!params) return s;
  return s.replace(/\{(\w+)\}/g, (m, k) => {
    if (params[k] === undefined || params[k] === null) return m;
    return typeof params[k] === 'number' ? Office.fmt.number(params[k]) : String(params[k]);
  });
}
Office.t = t;
Office.scope = (desk) => (key, params) => t(`${desk}.${key}`, params);
Office.has = (key) => Office.strings[key] !== undefined;

function pickLanguage() {
  const codes = CONFIG.languages.map((l) => l.code);
  const saved = Office.store('lang');
  if (saved && codes.includes(saved)) return saved;
  for (const want of navigator.languages || [navigator.language || 'en']) {
    const w = String(want).toLowerCase();
    const exact = codes.find((c) => c.toLowerCase() === w);
    if (exact) return exact;
    const base = codes.find((c) => c.toLowerCase() === w.split('-')[0]);
    if (base) return base;
  }
  return 'en';
}

async function loadStrings(code) {
  const r = await fetch(`api.php?a=strings&lang=${encodeURIComponent(code)}&v=${CONFIG.stamp}`);
  const j = await r.json();
  Office.strings = j.strings || {};
  Office.lang = j.lang || 'en';
  const meta = Office.strings._meta || {};
  Office.locale = meta.locale || Office.lang;
  try { plural = new Intl.PluralRules(Office.locale); } catch (e) { plural = new Intl.PluralRules('en'); }
  document.documentElement.lang = Office.lang;
  document.documentElement.dir = meta.dir || 'ltr';
}

// ------------------------------------------------------------------ formatting
const intl = (make, fallback) => { try { return make(Office.locale); } catch (e) { return make(fallback || 'en'); } };

Office.fmt = {
  number(n, digits) {
    return intl((l) => new Intl.NumberFormat(l, { maximumFractionDigits: digits ?? 0 })).format(n);
  },
  size(bytes) {
    if (bytes === 0) return '0 B';
    if (bytes === null || bytes === undefined || isNaN(bytes)) return '–';
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.min(Math.floor(Math.log(Math.abs(bytes)) / Math.log(1024)), units.length - 1);
    const value = bytes / Math.pow(1024, i);
    return `${Office.fmt.number(value, i === 0 ? 0 : value < 10 ? 1 : 0)} ${units[i]}`;
  },
  date(seconds, withWeekday) {
    if (!seconds) return '–';
    const d = new Date(seconds * 1000);
    const day = intl((l) => new Intl.DateTimeFormat(l, withWeekday
      ? { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' }
      : { day: '2-digit', month: '2-digit', year: 'numeric' })).format(d);
    return `${day} ${Office.fmt.time(seconds)}`;
  },
  time(seconds) {
    return intl((l) => new Intl.DateTimeFormat(l, { hour: '2-digit', minute: '2-digit' })).format(new Date(seconds * 1000));
  },
  /** "3 hours ago", "vor 3 Stunden", … from the browser itself */
  relative(seconds) {
    if (!seconds) return '–';
    const diff = seconds - Date.now() / 1000;
    const abs = Math.abs(diff);
    const rtf = intl((l) => new Intl.RelativeTimeFormat(l, { numeric: 'auto' }));
    if (abs < 50) return t('time.just_now');
    if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
    if (abs < 86400 * 45) return rtf.format(Math.round(diff / 86400), 'day');
    if (abs < 86400 * 548) return rtf.format(Math.round(diff / 86400 / 30.4), 'month');
    return rtf.format(Math.round(diff / 86400 / 365), 'year');
  },
  dayKey(seconds) {
    const d = new Date(seconds * 1000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  },
  dayTitle(key) {
    const [y, m, d] = key.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    const days = Math.round((date - new Date(new Date().toDateString())) / 86400000);
    if (days >= -1 && days <= 0) {
      const s = intl((l) => new Intl.RelativeTimeFormat(l, { numeric: 'auto' })).format(days, 'day');
      return s.charAt(0).toUpperCase() + s.slice(1);
    }
    return intl((l) => new Intl.DateTimeFormat(l, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })).format(date);
  },
  duration(seconds) {
    const s = Math.max(0, Math.round(seconds));
    const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    if (d) return t('time.days_hours', { d, h });
    if (h) return t('time.hours_minutes', { h, m });
    return t('time.minutes', { m });
  },
  /** Common cron patterns in words, everything else as it is */
  cron(expr) {
    if (!expr) return '';
    const p = expr.trim().split(/\s+/);
    if (p.length !== 5) return expr;
    const [min, hour, dom, mon, dow] = p;
    const num = (x) => /^\d+$/.test(x);
    const at = () => Office.fmt.time(new Date(2000, 0, 1, Number(hour), Number(min)).getTime() / 1000);
    if (/^\*\/\d+$/.test(min) && hour === '*' && dom === '*' && mon === '*' && dow === '*') return t('cron.every_minutes', { n: Number(min.slice(2)) });
    if (min === '*' && hour === '*' && dom === '*' && mon === '*' && dow === '*') return t('cron.every_minutes', { n: 1 });
    if (num(min) && hour === '*' && dom === '*' && mon === '*' && dow === '*') return t('cron.hourly_at', { m: Number(min) });
    if (num(min) && /^\*\/\d+$/.test(hour) && dom === '*' && mon === '*' && dow === '*') return t('cron.every_hours', { n: Number(hour.slice(2)), m: Number(min) });
    if (num(min) && num(hour) && dom === '*' && mon === '*' && dow === '*') return t('cron.daily_at', { time: at() });
    if (num(min) && num(hour) && dom === '*' && mon === '*' && /^[0-7]$/.test(dow)) {
      const day = intl((l) => new Intl.DateTimeFormat(l, { weekday: 'long' })).format(new Date(2024, 0, 7 + (Number(dow) % 7)));
      return t('cron.weekly_at', { day, time: at() });
    }
    if (num(min) && num(hour) && num(dom) && mon === '*' && dow === '*') return t('cron.monthly_at', { day: Number(dom), time: at() });
    return expr;
  },
};

// ------------------------------------------------------------------ API
Office.api = {
  async get(params) {
    const r = await fetch('api.php?' + new URLSearchParams(params), { cache: 'no-store' });
    const j = await r.json();
    if (j.agent) Office.setAgent(j.agent);
    return j;
  },
  /** post('snapshot.delete', {ids}) — the agent answers {ok, …} or {ok:false, error:{key, params}} */
  async post(action, data) {
    let j;
    try {
      const r = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Office': '1' },
        body: JSON.stringify({ a: action, ...data }),
      });
      try { j = await r.json(); } catch (e) { j = { ok: false, error: { key: 'bad_answer', params: { status: r.status } } }; }
    } catch (e) {
      j = { ok: false, error: { key: 'offline' } };
    }
    if (j.agent) Office.setAgent(j.agent);
    j.desk = action.split('.')[0];
    return j;
  },
};

/** Text for an error {key, params} — desk-specific first, then the office's */
Office.errorText = function errorText(error, desk) {
  if (!error) return t('errors.internal', { detail: '?' });
  const params = error.params || {};
  if (desk && Office.has(`${desk}.errors.${error.key}`)) return t(`${desk}.errors.${error.key}`, params);
  if (Office.has(`errors.${error.key}`)) return t(`errors.${error.key}`, params);
  return t('errors.unknown', { key: error.key, detail: params.detail || '' });
};

// ------------------------------------------------------------------ agent status
Office.setAgent = function setAgent(info) {
  Office.agent = info || { running: false };
  const dot = $('#agent-dot');
  dot.className = 'dot ' + (Office.agent.running ? 'on' : 'off');
  dot.title = Office.agent.running ? t('agent.running', { version: Office.agent.version || '?' }) : t('agent.away');
  const notice = $('#notice');
  if (Office.agent.running) {
    if (notice.dataset.kind === 'agent') { notice.hidden = true; notice.dataset.kind = ''; }
  } else {
    notice.innerHTML = '';
    notice.className = 'notice';
    notice.dataset.kind = 'agent';
    notice.append(el('strong', '', t('agent.away_title')), ' ', t('agent.away_text'), ' ');
    const a = el('a', '', t('agent.how_to_start'));
    a.href = '#';
    a.onclick = (e) => { e.preventDefault(); Office.help(); };
    notice.append(a);
    notice.hidden = false;
  }
  if (Office.current && Office.current.agentChanged) Office.current.agentChanged();
  footer();
};

// ------------------------------------------------------------------ toasts, copy
Office.toast = function toast(text, warn) {
  const n = el('div', 'toast' + (warn ? ' warn' : ''), text);
  $('#toasts').appendChild(n);
  setTimeout(() => { n.style.opacity = '0'; setTimeout(() => n.remove(), 300); }, warn ? 6000 : 3200);
};

Office.copy = function copy(text) {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(() => Office.toast(t('common.copied')), () => Office.toast(t('common.copy_failed'), true));
    return;
  }
  // plain http in the LAN: no clipboard API, so the old way
  const ta = el('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
  document.body.appendChild(ta);
  ta.select();
  let ok = false;
  try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
  ta.remove();
  Office.toast(ok ? t('common.copied') : t('common.copy_failed'), !ok);
};

// ------------------------------------------------------------------ dialog
let closeDialog = null;
Office.dialogOpen = () => !!closeDialog;

/** buttons: [{text, kind, act}] — when act() returns false the dialog stays open */
Office.dialog = function dialog({ title, body, buttons, wide }) {
  if (closeDialog) closeDialog();
  const backdrop = $('#dialog-backdrop');
  $('#dialog').classList.toggle('wide', !!wide);
  $('#dialog-title').textContent = title;
  const box = $('#dialog-body');
  box.innerHTML = '';
  if (typeof body === 'string') box.appendChild(el('p', '', body));
  else if (body) box.appendChild(body);

  const foot = $('#dialog-foot');
  foot.innerHTML = '';
  const made = [];
  (buttons || [{ text: t('common.close') }]).forEach((b) => {
    const btn = el('button', 'btn' + (b.kind === undefined ? ' plain' : b.kind ? ' ' + b.kind : ''), b.text);
    btn.type = 'button';
    btn.onclick = async () => {
      if (b.act) {
        const before = made.map((x) => x.disabled);
        made.forEach((x) => { x.disabled = true; });   // no double requests
        let r;
        try { r = await b.act(); } finally { made.forEach((x, i) => { x.disabled = before[i]; }); }
        if (r === false) return;
      }
      close();
    };
    foot.appendChild(btn);
    made.push(btn);
  });

  backdrop.hidden = false;
  const field = box.querySelector('input.input:not([type=search])');
  if (field) { field.focus(); field.select(); } else if (made[0]) made[0].focus();

  function close() {
    backdrop.hidden = true;
    closeDialog = null;
    document.removeEventListener('keydown', onKey);
  }
  function onKey(e) {
    if (e.key === 'Escape') close();
    if (e.key === 'Enter' && e.target.matches && e.target.matches('input.input:not([type=search])') && made.length) {
      e.preventDefault();
      made[made.length - 1].click();
    }
  }
  document.addEventListener('keydown', onKey);
  backdrop.onclick = (e) => { if (e.target === backdrop) close(); };
  closeDialog = close;
  return { buttons: made, close };
};

Office.showErrors = function showErrors(title, errors, desk) {
  const box = el('div');
  const ul = el('ul', 'shortlist');
  errors.forEach((e) => ul.appendChild(el('li', 'error', typeof e === 'string' ? e : Office.errorText(e, desk))));
  box.appendChild(ul);
  Office.dialog({ title, body: box });
};

// ------------------------------------------------------------------ menu
Office.menu = function menu(event, items) {
  const box = $('#menu');
  box.innerHTML = '';
  items.forEach((item) => {
    if (item.separator) { box.appendChild(el('hr')); return; }
    const b = el('button', item.kind === 'danger' ? 'danger' : '', item.text);
    b.type = 'button';
    b.disabled = !!item.disabled || !item.act;
    b.onclick = () => { hideMenu(); item.act(); };
    box.appendChild(b);
  });
  box.hidden = false;

  let x = event.clientX, y = event.clientY;
  if ((!x && !y) || event.detail === 0) {
    const r = event.currentTarget.getBoundingClientRect();
    x = r.right - 200; y = r.bottom + 4;
  }
  const rect = box.getBoundingClientRect();
  x = Math.min(x, window.innerWidth - rect.width - 8);
  y = Math.min(y, window.innerHeight - rect.height - 8);
  box.style.left = Math.max(8, x) + 'px';
  box.style.top = Math.max(8, y) + 'px';
  const first = box.querySelector('button:not(:disabled)');
  if (first && event.detail === 0) first.focus();
  setTimeout(() => {
    document.addEventListener('click', hideMenu, { once: true });
    document.addEventListener('scroll', hideMenu, { once: true, capture: true });
  }, 0);
};
function hideMenu() { $('#menu').hidden = true; }
Office.menuOpen = () => !$('#menu').hidden;

// ------------------------------------------------------------------ selection bar
let selbar = null;
/** Office.selbar({title, sub, buttons:[{text, kind, act}]}) or Office.selbar(null) */
Office.selbar = function setSelbar(spec) {
  if (!spec) {
    if (selbar) { selbar.remove(); selbar = null; }
    document.body.classList.remove('with-selbar');
    return null;
  }
  if (!selbar) {
    selbar = el('div', 'selbar');
    document.body.appendChild(selbar);
  }
  selbar.innerHTML = '';
  const text = el('div', 'selbar-text');
  const strong = el('strong', '', spec.title);
  const sub = el('span', '', spec.sub || '');
  text.append(strong, sub);
  const buttons = el('div', 'selbar-buttons');
  (spec.buttons || []).forEach((b) => {
    const btn = el('button', 'btn' + (b.kind ? ' ' + b.kind : ''), b.text);
    btn.type = 'button';
    btn.disabled = !!b.disabled;
    btn.onclick = b.act;
    buttons.appendChild(btn);
  });
  selbar.append(text, buttons);
  document.body.classList.add('with-selbar');
  return { sub };
};

// ------------------------------------------------------------------ desks & routing
Office.desk = function registerDesk(desk) {
  const meta = CONFIG.desks.find((d) => d.id === desk.id) || {};
  Office.desks.set(desk.id, { ...meta, ...desk });
};

function route() {
  const parts = (location.hash.replace(/^#\/?/, '') || '').split('/');
  const id = parts[0] || '';
  const sub = parts.slice(1).join('/');
  const next = Office.desks.get(id) || null;
  if (Office.current && Office.current !== next && Office.current.unmount) Office.current.unmount();
  if (Office.current !== next) Office.selbar(null);
  Office.current = next;
  hideMenu();
  tabs();
  const root = $('#desk');
  root.innerHTML = '';
  window.scrollTo(0, 0);
  if (next) {
    document.title = `${t(next.id + '.name')} · ${CONFIG.host}`;
    next.mount(root, sub);
  } else {
    document.title = `${t('office.name')} · ${CONFIG.host}`;
    reception(root);
  }
}
Office.go = (hash) => { if (location.hash === hash) route(); else location.hash = hash; };
Office.subroute = (sub) => {
  const id = Office.current ? Office.current.id : '';
  history.replaceState(null, '', `#/${id}${sub ? '/' + sub : ''}`);
};

function tabs() {
  const nav = $('#tabs');
  nav.innerHTML = '';
  const add = (href, icon, text, active) => {
    const a = el('a', active ? 'active' : '');
    a.href = href;
    a.append(el('span', 'tab-icon', icon), el('span', '', text));
    nav.appendChild(a);
  };
  add('#/', '🛎️', t('office.reception'), !Office.current);
  for (const d of Office.desks.values()) add(`#/${d.id}`, d.icon, t(`${d.id}.name`), Office.current === d);
}

/** The desk head every secretary uses: avatar, name, role, speech bubble, actions */
Office.deskHead = function deskHead(desk, { bubble, actions }) {
  const head = el('div', 'deskhead');
  head.appendChild(el('div', 'avatar', desk.icon));
  const text = el('div', 'deskhead-text');
  text.append(el('h1', '', t(`${desk.id}.name`)), el('div', 'role', t(`${desk.id}.role`)));
  const b = el('div', 'bubble');
  if (bubble) b.append(bubble);
  text.appendChild(b);
  head.appendChild(text);
  const act = el('div', 'deskhead-actions');
  (actions || []).forEach((a) => act.appendChild(a));
  head.appendChild(act);
  return { head, bubble: b, actions: act };
};

// ------------------------------------------------------------------ reception
async function reception(root) {
  const head = el('div', 'deskhead');
  head.appendChild(el('div', 'avatar', '🛎️'));
  const text = el('div', 'deskhead-text');
  text.append(el('h1', '', t('office.welcome', { host: CONFIG.host })), el('div', 'role', t('office.reception_role')));
  head.appendChild(text);
  root.appendChild(head);

  const grid = el('div', 'reception');
  root.appendChild(grid);
  for (const desk of Office.desks.values()) {
    const card = el('div', 'desk-card');
    const top = el('div', 'desk-card-head');
    const name = el('div');
    name.append(el('h2', '', t(`${desk.id}.name`)), el('div', 'role', t(`${desk.id}.role`)));
    top.append(el('div', 'avatar big', desk.icon), name);
    const bubble = el('div', 'bubble', '…');
    const facts = el('ul', 'facts');
    const go = el('button', 'btn', t('office.visit', { name: t(`${desk.id}.name`) }));
    go.type = 'button';
    go.onclick = () => Office.go(`#/${desk.id}`);
    card.append(top, bubble, facts, go);
    grid.appendChild(card);
    if (desk.reception) {
      desk.reception().then((r) => {
        bubble.innerHTML = '';
        if (r && r.bubble) bubble.append(r.bubble);
        else bubble.textContent = t('office.no_news');
        (r && r.facts || []).forEach((f) => facts.appendChild(el('li', '', f)));
      }).catch(() => { bubble.textContent = t('office.no_news'); });
    } else {
      bubble.textContent = t('office.no_news');
    }
  }
}

// ------------------------------------------------------------------ office menu, help, log
async function showLog() {
  const pre = el('pre', 'code', t('common.loading'));
  Office.dialog({ title: t('office.log'), body: pre, wide: true });
  try {
    const j = await Office.api.get({ a: 'log' });
    pre.textContent = (j.lines || []).join('\n') || t('office.log_empty');
    pre.scrollTop = pre.scrollHeight;
  } catch (e) {
    pre.textContent = t('office.log_failed');
  }
}

Office.help = function help() {
  const box = el('div');
  const dl = el('dl');
  const item = (title, ...parts) => {
    dl.appendChild(el('dt', '', title));
    const dd = el('dd');
    parts.forEach((p) => dd.append(p));
    dl.appendChild(dd);
  };
  const code = (s) => el('code', '', s);
  item(t('help.office_title'), t('help.office_text'));
  item(t('help.agent_title'), t('help.agent_text'), ' ', code('docker compose'), '.');
  item(t('help.dot_title'), t('help.dot_text'));
  item(t('help.start_title'), t('help.start_text'));
  item(t('help.languages_title'), t('help.languages_text'), ' ', code('public/lang/<code>.json'), ', ',
    code('public/desks/<desk>/lang/<code>.json'), '.');
  item(t('help.security_title'), t('help.security_text'));
  box.appendChild(dl);
  Office.dialog({ title: t('help.title'), body: box, wide: true });
};

function officeMenu(e) {
  e.stopPropagation();
  const items = [
    { text: t('office.refresh'), act: () => route() },
    { text: t('office.log'), act: showLog },
    { separator: true },
    { text: t('help.title'), act: Office.help },
  ];
  if (Office.current && Office.current.menu) items.unshift(...Office.current.menu(), { separator: true });
  Office.menu(e, items);
}

function footer() {
  const f = $('#footer');
  f.innerHTML = '';
  f.append(el('span', '', `Unraid Secretary Office v${CONFIG.version}`));
  if (Office.agent.version) f.append(el('span', '', t('agent.version', { version: Office.agent.version })));
  const a = el('a', '', 'GitHub');
  a.href = 'https://github.com/vipermark2/UnraidSecretaryOffice';
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  f.append(a);
}

function languagePicker() {
  const sel = $('#lang');
  sel.innerHTML = '';
  CONFIG.languages.forEach((l) => sel.appendChild(new Option(l.name, l.code)));
  sel.value = Office.lang;
  sel.hidden = CONFIG.languages.length < 2;
  sel.onchange = async () => {
    Office.store('lang', sel.value);
    await loadStrings(sel.value);
    $('#brand-name').textContent = t('office.name');
    Office.setAgent(Office.agent);
    route();
  };
}

// ------------------------------------------------------------------ start
async function start() {
  await loadStrings(pickLanguage());
  $('#brand-name').textContent = t('office.name');
  languagePicker();
  footer();
  $('#btn-more').onclick = officeMenu;
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideMenu(); });
  window.addEventListener('hashchange', route);
  route();
  setInterval(() => {
    if (document.hidden || Office.dialogOpen() || Office.menuOpen()) return;
    if (Office.current && Office.current.poll) Office.current.poll();
  }, POLL);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Office.current && Office.current.poll && !Office.dialogOpen()) Office.current.poll();
  });
}

document.addEventListener('DOMContentLoaded', start);
})();
