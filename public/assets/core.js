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
  auth: { mode: 'none', unlocked: true },
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
  /** post('snapshot.delete', {ids}) — the agent answers {ok, …} or {ok:false, error:{key, params}}.
      With a PIN set and this browser locked, it asks for the PIN first and then tries again. */
  async post(action, data) {
    let j = await postOnce(action, data);
    if (!j.ok && j.error && j.error.key === 'pin_required' && await Office.unlock()) j = await postOnce(action, data);
    return j;
  },
};

async function postOnce(action, data) {
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
  if (j.auth) Office.setAuth(j.auth);
  j.desk = action.split('.')[0];
  return j;
}

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

// ------------------------------------------------------------------ PIN
// Reading is open. With a PIN set, changing things needs an unlocked browser
// (a cookie the server signs, valid for some hours). See src/auth.php.

Office.setAuth = function setAuth(auth) {
  if (!auth) return;
  Office.auth = auth;
  const b = $('#btn-lock');
  b.hidden = auth.mode !== 'pin';
  b.textContent = auth.unlocked ? '🔓' : '🔒';
  b.title = auth.unlocked
    ? t('auth.unlocked_until', { time: auth.until ? Office.fmt.time(auth.until) : '–' })
    : t('auth.locked');
};

/** Asks for the PIN. Resolves true once this browser is unlocked. */
Office.unlock = function unlock() {
  return new Promise((resolve) => {
    let done = false;
    const finish = (value) => { if (!done) { done = true; resolve(value); } };
    const box = el('div');
    box.appendChild(el('p', '', t('auth.unlock_text')));
    const input = pinInput('current-password');
    const msg = el('p', 'callout warn');
    msg.hidden = true;
    box.append(input, msg);
    Office.dialog({
      title: t('auth.unlock_title'),
      body: box,
      onClose: () => finish(false),
      buttons: [
        { text: t('common.cancel') },
        { text: t('auth.unlock'), kind: '', act: async () => {
          const j = await postOnce('office.unlock', { pin: input.value });
          if (j.ok) { finish(true); return true; }
          msg.textContent = Office.errorText(j.error);
          msg.hidden = false;
          input.select();
          return false;
        } },
      ],
    });
  });
};

function pinInput(autocomplete) {
  const input = el('input', 'input');
  input.type = 'password';
  input.autocomplete = autocomplete;
  input.maxLength = 64;
  return input;
}

async function lockNow() {
  const j = await postOnce('office.lock', {});
  if (j.ok) Office.toast(t('auth.locked_now'));
}

/** Set, change or remove the PIN */
function pinSettings() {
  const a = Office.auth;
  const box = el('div');
  box.appendChild(el('p', '', t(a.mode === 'pin' ? 'auth.settings_text_on' : 'auth.settings_text_off')));
  if (!a.writable) box.appendChild(el('p', 'callout warn', t('auth.not_writable')));
  const field = (label, input) => {
    const f = el('div', 'field');
    const l = el('label', '', label);
    f.append(l, input);
    box.appendChild(f);
    return input;
  };
  const current = a.mode === 'pin' ? field(t('auth.current'), pinInput('current-password')) : null;
  const pin = field(t('auth.new'), pinInput('new-password'));
  const again = field(t('auth.again'), pinInput('new-password'));
  box.appendChild(el('p', 'role', t('auth.forgot')));
  const msg = el('p', 'callout warn');
  msg.hidden = true;
  box.appendChild(msg);
  const fail = (text) => { msg.textContent = text; msg.hidden = false; return false; };

  const save = async (remove) => {
    if (!remove && pin.value !== again.value) return fail(t('auth.mismatch'));
    if (!remove && !pin.value) return fail(t('auth.empty'));
    const j = await postOnce('office.pin', { pin: remove ? '' : pin.value, current: current ? current.value : '' });
    if (!j.ok) return fail(Office.errorText(j.error));
    Office.toast(t(remove ? 'auth.removed' : 'auth.saved'));
    return true;
  };
  const buttons = [{ text: t('common.cancel') }];
  if (a.mode === 'pin') buttons.push({ text: t('auth.remove'), kind: 'danger plain', act: () => save(true) });
  buttons.push({ text: t(a.mode === 'pin' ? 'auth.change' : 'auth.set'), kind: '', act: () => save(false) });
  Office.dialog({ title: t('auth.settings_title'), body: box, buttons });
}

// ------------------------------------------------------------------ dialog
let closeDialog = null;
Office.dialogOpen = () => !!closeDialog;

/** buttons: [{text, kind, act}] — when act() returns false the dialog stays open.
    onClose() runs however the dialog ends (button, Escape, click outside, another dialog). */
Office.dialog = function dialog({ title, body, buttons, wide, onClose }) {
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

  let closed = false;
  function close() {
    if (closed) return;
    closed = true;
    backdrop.hidden = true;
    closeDialog = null;
    document.removeEventListener('keydown', onKey);
    if (onClose) onClose();
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
  let next = Office.desks.get(id) || null;
  if (next && !next.hired) {               // not working here (yet): the caretaker knows who could come
    Office.toast(t('office.not_hired', { name: t(`${next.id}.name`) }));
    history.replaceState(null, '', '#/caretaker');
    next = Office.desks.get('caretaker') || null;
  }
  greetings.clear();
  if (Office.current && Office.current !== next && Office.current.unmount) Office.current.unmount();
  if (Office.current !== next) Office.selbar(null);
  Office.current = next;
  hideMenu();
  tabs();
  const root = $('#desk');
  root.innerHTML = '';
  root.style.minHeight = '';
  hideTip();
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
  for (const d of Office.desks.values()) if (d.hired) add(`#/${d.id}`, d.icon, t(`${d.id}.name`), Office.current === d);
}

/**
 * "How to read this page", folded out under a desk's head — closed unless the
 * user opened it (remembered per desk). items: [[term (text or node), text], …]
 */
Office.pageHelp = function pageHelp(desk, items) {
  const det = el('details', 'page-help');
  det.open = Office.store(desk + '.help') === '1';
  det.ontoggle = () => Office.store(desk + '.help', det.open ? '1' : null);
  det.appendChild(el('summary', '', t('common.page_help')));
  const dl = el('dl', 'page-help-list');
  items.forEach(([term, text]) => {
    const dt = el('dt');
    if (term instanceof Node) dt.appendChild(term); else dt.textContent = term;
    dl.append(dt, el('dd', '', text));
  });
  det.appendChild(dl);
  return det;
};

/** A section heading with its explanation right underneath; extras (counts, buttons) go to the right */
Office.sectionHead = function sectionHead(title, sub, ...right) {
  const head = el('div', 'section-head');
  const left = el('div', 'section-title');
  left.appendChild(el('h2', '', title));
  if (sub) left.appendChild(el('div', 'section-sub', sub));
  head.appendChild(left);
  right.filter(Boolean).forEach((x) => head.appendChild(x));
  return head;
};

/** How the backup protects something — the same label on every desk: offsite / local / none */
Office.backupChip = function backupChip(level) {
  if (!level) return null;
  const c = el('span', 'chip ' + ({ offsite: 'ok', local: 'warn', none: 'danger' }[level] || ''), t('protect.' + level));
  c.title = t('protect.' + level + '_text');
  return c;
};

/**
 * Explanations on chips (and anything with data-tip). A chip's title becomes
 * a small bubble: shown on hover with a mouse, and on click or tap everywhere —
 * the click stays with the chip, so a row under it doesn't fold. Chips that do
 * something themselves (own onclick, data-own, inside a button or link) keep
 * their click and only show the bubble on hover.
 */
let tipBox = null, tipFor = null, tipPinned = false, tipTimer = 0;
const TIP_SEL = '.chip[title], .chip[data-tip], [data-tip]';
const fineHover = window.matchMedia ? window.matchMedia('(hover: hover) and (pointer: fine)') : { matches: true };

function tipText(node) {
  if (node.hasAttribute('title')) {       // no native tooltip on top of ours
    node.dataset.tip = node.getAttribute('title');
    node.removeAttribute('title');
  }
  return node.dataset.tip || '';
}
function ownClick(node) {
  return !!(node.onclick || node.dataset.own || node.parentElement && node.parentElement.closest('button, a, label'));
}
function showTip(node, pinned) {
  const text = tipText(node);
  if (!text) return;
  if (!tipBox) {
    tipBox = el('div', 'tip');
    tipBox.setAttribute('role', 'tooltip');
    document.body.appendChild(tipBox);
  }
  tipBox.textContent = text;
  tipBox.hidden = false;
  tipFor = node;
  tipPinned = pinned;
  const r = node.getBoundingClientRect();
  const w = tipBox.offsetWidth, h = tipBox.offsetHeight;
  const left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
  const below = r.bottom + 6 + h <= window.innerHeight - 8;
  tipBox.style.left = left + 'px';
  tipBox.style.top = (below ? r.bottom + 6 : Math.max(8, r.top - 6 - h)) + 'px';
}
function hideTip() {
  clearTimeout(tipTimer);
  if (tipBox) tipBox.hidden = true;
  tipFor = null;
  tipPinned = false;
}
Office.hideTip = hideTip;

function initTips() {
  document.addEventListener('click', (e) => {
    const node = e.target.closest && e.target.closest(TIP_SEL);
    if (!node || ownClick(node)) { if (tipPinned && !(tipBox && tipBox.contains(e.target))) hideTip(); return; }
    e.stopPropagation();               // capture phase: the row under the chip never hears of it
    e.preventDefault();
    if (tipFor === node && tipPinned) hideTip(); else showTip(node, true);
  }, true);
  document.addEventListener('mouseover', (e) => {
    if (!fineHover.matches || tipPinned) return;
    const node = e.target.closest && e.target.closest(TIP_SEL);
    if (!node) return;
    tipText(node);
    clearTimeout(tipTimer);
    tipTimer = setTimeout(() => showTip(node, false), 250);
  });
  document.addEventListener('mouseout', (e) => {
    if (tipPinned) return;
    const node = e.target.closest && e.target.closest(TIP_SEL);
    if (node && !node.contains(e.relatedTarget)) hideTip();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideTip(); });
  window.addEventListener('scroll', hideTip, { passive: true });
  window.addEventListener('resize', hideTip);
}

/**
 * Fold or unfold something without the page jumping: the anchor (the row or
 * heading that was clicked) stays where it is on screen. Content that
 * disappears below keeps its space until the user scrolls up, so the browser
 * doesn't have to pull the page down at the bottom.
 */
Office.keepInPlace = function keepInPlace(anchor, change) {
  const desk = $('#desk');
  const before = anchor && anchor.isConnected ? anchor.getBoundingClientRect().top : null;
  desk.style.minHeight = Math.max(desk.offsetHeight, parseFloat(desk.style.minHeight) || 0) + 'px';
  change();
  if (before !== null && anchor.isConnected) {
    const diff = anchor.getBoundingClientRect().top - before;
    if (Math.abs(diff) > 1) window.scrollBy(0, diff);
  }
  relaxDesk();
};
/** give back the kept space as far as it is below the visible part */
function relaxDesk() {
  const desk = $('#desk');
  const min = parseFloat(desk.style.minHeight);
  if (!min) return;
  const slack = document.documentElement.scrollHeight - window.scrollY - window.innerHeight;
  const next = min - Math.max(0, slack);
  desk.style.minHeight = next > 0 ? next + 'px' : '';
  if (next > 0 && desk.offsetHeight > next + 1) desk.style.minHeight = '';    // the content is taller anyway
}

// ------------------------------------------------------------------ staff
/** Hire one or more desks (the caretaker suggests whom); afterwards the tip jar says hello */
Office.hire = async function hire(ids) {
  const j = await Office.api.post('office.hire', { desks: ids });
  if (!j.ok) { Office.toast(Office.errorText(j.error), true); return false; }
  staffChanged(j.hired);
  tipJar(ids);
  return true;
};
/** Fire a desk: it leaves the tabs and the reception, its data stays */
Office.fire = async function fire(id) {
  const j = await Office.api.post('office.fire', { desk: id });
  if (!j.ok) { Office.toast(Office.errorText(j.error), true); return false; }
  staffChanged(j.hired);
  return true;
};
/** "Let X go?" — with what keeps running (the desk's fire_note); used by the caretaker and every desk's ⋯ menu */
Office.fireDialog = function fireDialog(id, after) {
  const name = t(`${id}.name`);
  const box = el('div');
  box.appendChild(el('p', '', t('office.fire_text', { name })));
  if (Office.has(`${id}.fire_note`)) box.appendChild(el('p', 'callout', t(`${id}.fire_note`)));
  Office.dialog({
    title: t('office.fire_title', { name }),
    body: box,
    buttons: [
      { text: t('common.cancel') },
      { text: t('office.fire'), kind: 'danger', act: async () => {
        if (!await Office.fire(id)) return false;
        Office.toast(t('office.fired', { name }));
        if (after) after();
        else if (Office.current && Office.current.id === id) Office.go('#/');
        return true;
      } },
    ],
  });
};
function staffChanged(hired) {
  for (const d of CONFIG.desks) d.hired = hired.includes(d.id);
  for (const d of Office.desks.values()) d.hired = hired.includes(d.id);
  tabs();
}

/** "If they do their jobs well, they'd be glad of a tip!" — after hiring, unless switched off */
function tipJar(ids) {
  if (Office.store('tip.never') === '1') return;
  Office.tipJar(ids);
}
/** The tip jar itself — after hiring (ids), or asked for (no ids: the button at the caretaker's team) */
Office.tipJar = function openTipJar(ids) {
  const names = (ids || []).map((id) => t(`${id}.name`));
  const box = el('div', 'tip-jar');
  box.appendChild(el('div', 'tip-jar-icon', '☕'));
  const text = el('div');
  const key = !ids ? 'office.tip_text_team' : ids.length > 1 ? 'office.tip_text_many' : 'office.tip_text';
  text.appendChild(el('p', '', t(key, { names: names.join(', ') })));
  const cb = el('input');
  cb.type = 'checkbox';
  if (ids) {                         // after hiring it may stop asking; asked for, it never nags
    const never = el('label', 'check');
    never.append(cb, el('span', '', t('office.tip_never')));
    text.appendChild(never);
  }
  box.appendChild(text);
  const buttons = [{ text: t('office.tip_later'), act: () => { if (cb.checked) Office.store('tip.never', '1'); } }];
  if (CONFIG.tip_url) {
    buttons.push({ text: t('office.tip_give'), kind: '', act: () => {
      if (cb.checked) Office.store('tip.never', '1');
      window.open(CONFIG.tip_url, '_blank', 'noopener,noreferrer');
    } });
  }
  Office.dialog({ title: t(ids ? 'office.tip_title' : 'office.tip_title_team'), body: box, buttons });
};

/**
 * A desk's greeting: one of its lang keys greet.1, greet.2, … picked at random,
 * the same one until the page changes. '' if the desk has none.
 */
const greetings = new Map();
Office.greet = function greet(id) {
  if (!greetings.has(id)) {
    const all = [];
    for (let i = 1; Office.has(`${id}.greet.${i}`); i++) all.push(t(`${id}.greet.${i}`));
    greetings.set(id, all.length ? all[Math.floor(Math.random() * all.length)] : '');
  }
  return greetings.get(id);
};
/** "Greeting. Text" — for bubbles */
Office.withGreeting = (id, text) => {
  const g = Office.greet(id);
  return g ? `${g} ${text}` : text;
};

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
  const order = [...Office.desks.values()].filter((d) => d.hired).sort((a, b) => (a.reception_order ?? a.order ?? 0) - (b.reception_order ?? b.order ?? 0));
  for (const desk of order) {
    const card = el('div', 'desk-card');
    const top = el('div', 'desk-card-head');
    const name = el('div');
    name.append(el('h2', '', t(`${desk.id}.name`)), el('div', 'role', t(`${desk.id}.role`)));
    top.append(el('div', 'avatar big', desk.icon), name);
    const bubble = el('div', 'bubble', '…');
    const facts = el('ul', 'facts');
    // a desk may say how to go there ("Zum Hauswart"), else the office's "Visit {name}"
    const go = el('button', 'btn', Office.has(`${desk.id}.visit`) ? t(`${desk.id}.visit`) : t('office.visit', { name: t(`${desk.id}.name`) }));
    go.type = 'button';
    go.onclick = () => Office.go(`#/${desk.id}`);
    const foot = el('div', 'desk-card-foot');
    foot.appendChild(go);
    if (!desk.always) {          // the caretaker comes with the house; everyone else can be let go
      const fire = el('button', 'btn small plain', t('office.fire'));
      fire.type = 'button';
      fire.onclick = () => Office.fireDialog(desk.id, () => route());
      foot.appendChild(fire);
    }
    card.append(top, bubble, facts, foot);
    grid.appendChild(card);
    if (desk.reception) {
      desk.reception().then((r) => {
        bubble.innerHTML = '';
        const greeting = Office.greet(desk.id);
        if (greeting && r && r.bubble) bubble.append(greeting, ' ');
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
    { text: t(Office.auth.mode === 'pin' ? 'auth.menu_change' : 'auth.menu_set'), act: pinSettings },
  ];
  if (Office.auth.mode === 'pin' && Office.auth.unlocked) items.push({ text: t('auth.lock_now'), act: lockNow });
  items.push({ separator: true }, { text: t('help.title'), act: Office.help });
  if (Office.current && !Office.current.always) {
    items.push({ separator: true }, { text: t('office.fire_menu', { name: t(`${Office.current.id}.name`) }), act: () => Office.fireDialog(Office.current.id) });
  }
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
  $('#btn-lock').onclick = () => (Office.auth.unlocked ? lockNow() : Office.unlock());
  Office.api.get({ a: 'auth' }).then((j) => Office.setAuth(j.auth)).catch(() => {});
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideMenu(); });
  initTips();
  window.addEventListener('scroll', relaxDesk, { passive: true });
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
