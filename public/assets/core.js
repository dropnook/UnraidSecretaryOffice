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
     started()          optional, once after the page started (any desk shown)
   Its strings live in public/desks/<id>/lang/<code>.json and are reached as
   t('<id>.<key>'), or with Office.scope('<id>') as a shortcut. */
(() => {
'use strict';

const CONFIG = JSON.parse(document.getElementById('sso-config').textContent);
const API    = (CONFIG.base || '') + 'api.php';     // inside Unraid's page: the plugin's folder
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
/** Where a link may lead: the office's own pages (#…), Unraid's (/…) and http(s) — never javascript:, data: … from a state file. Else null */
Office.safeHref = (url) => (typeof url === 'string' && /^(#|\/(?![\/\\])|https?:\/\/)/i.test(url) ? url : null);
/** The office's own element: everything it shows lives in it (office.css styles nothing outside) */
const ROOT = $('#sso');

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
  return codes.includes(CONFIG.unraid_lang) ? CONFIG.unraid_lang : 'en';   // like Unraid
}

async function loadStrings(code) {
  const r = await fetch(`${API}?a=strings&lang=${encodeURIComponent(code)}&v=${CONFIG.stamp}`);
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
/** The page sits behind Unraid's login: an ended session answers with the login page */
function loggedOut(r) {
  if (!r.redirected || new URL(r.url).pathname !== '/login') return false;
  location.reload();             // Unraid shows its login, then the office again
  return true;
}

// ------------------------------------------------------------------ busy
// An action that takes more than a moment shows a wave and keeps clicks off the
// page until it is answered: Unraid's own (div.spinner.fixed, the animated logo
// every Unraid page has; ours only if a page lacks it). Reads that poll or run
// beside the page (a log being followed, an estimate) stay quiet.
const QUIET = /\.(read|output|log|estimate|detail|measure)$/;
let busyCount = 0, busyTimer = null;
function busyEl() {
  const unraid = document.querySelector('div.spinner.fixed');
  if (unraid) return unraid;
  let el = $('#sso-busy');
  if (!el) {
    el = Object.assign(document.createElement('div'), { id: 'sso-busy', className: 'busy' });
    el.appendChild(document.createElement('span'));
    $('#sso').appendChild(el);
  }
  return el;
}
const busyHolds = new Map();   // a desk waiting for work it started in the background -> its safety timer
function busy(on) {
  if (on === true) busyCount++;
  else if (on === false) busyCount = Math.max(0, busyCount - 1);
  const want = busyCount > 0 || busyHolds.size > 0;
  if (want && !busyTimer) {
    busyTimer = setTimeout(() => { if (busyCount || busyHolds.size) busyEl().style.display = 'block'; }, 400);
  } else if (!want) {
    clearTimeout(busyTimer);
    busyTimer = null;
    busyEl().style.display = 'none';
  }
}
/** Office.busy('backup.setup', true) while a desk waits for a job it started (an apply, a plan);
    false when it is done. Lets go by itself after two minutes, so the page never stays blocked. */
Office.busy = function officeBusy(key, on) {
  clearTimeout(busyHolds.get(key));
  busyHolds.delete(key);
  if (on) busyHolds.set(key, setTimeout(() => Office.busy(key, false), 120000));
  busy(null);
};
async function postBusy(action, data) {
  if (QUIET.test(action)) return postOnce(action, data);
  busy(true);
  try { return await postOnce(action, data); } finally { busy(false); }
}

Office.api = {
  async get(params) {
    const r = await fetch(API + '?' + new URLSearchParams(params), { cache: 'no-store' });
    if (loggedOut(r)) return { ok: false, error: { key: 'logged_out' } };
    const j = await r.json();
    if (j.agent) Office.setAgent(j.agent);
    return j;
  },
  /** post('snapshot.delete', {ids}) — the agent answers {ok, …} or {ok:false, error:{key, params}} */
  async post(action, data) {
    return postBusy(action, data);
  },
};

async function postOnce(action, data) {
  let j;
  try {
    const headers = { 'Content-Type': 'application/json', 'X-Office': '1' };
    if (CONFIG.csrf) headers['X-CSRF-Token'] = CONFIG.csrf;     // Unraid refuses any POST without it
    const r = await fetch(API, { method: 'POST', headers, body: JSON.stringify({ a: action, ...data }) });
    if (loggedOut(r)) return { ok: false, error: { key: 'logged_out' }, desk: action.split('.')[0] };
    const text = await r.text();
    try {
      j = JSON.parse(text);
    } catch (e) {
      // Unraid ends a POST with a stale csrf_token (new after a reboot) without a word
      j = { ok: false, error: { key: r.ok && text === '' ? 'stale_page' : 'bad_answer', params: { status: r.status } } };
    }
  } catch (e) {
    j = { ok: false, error: { key: 'offline' } };
  }
  if (j.agent) Office.setAgent(j.agent);
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
  const dot = $('#sso-dot');
  dot.className = 'dot ' + (Office.agent.running ? 'on' : 'off');
  dot.title = Office.agent.running ? t('agent.running', { version: Office.agent.version || '?' }) : t('agent.away');
  // a word next to the dot: what runs, or what doesn't
  const state = $('#sso-state');
  const no = Office.agent.no_data;
  state.className = 'agent-state ' + (Office.agent.running ? 'on' : 'off');
  $('#sso-state-label').textContent = t(Office.agent.running ? 'agent.label_on'
    : no ? (no.array !== 'Started' ? 'agent.label_array' : 'agent.label_no_data') : 'agent.label_off');
  state.title = dot.title;
  dot.removeAttribute('title');
  const notice = $('#sso-notice');
  if (Office.agent.running) {
    if (notice.dataset.kind === 'agent') { notice.hidden = true; notice.dataset.kind = ''; }
  } else if (Office.agent.no_data) {
    // the data folder lies in appdata and comes with the array
    const stopped = Office.agent.no_data.array !== 'Started';
    notice.innerHTML = '';
    notice.className = 'notice';
    notice.dataset.kind = 'agent';
    notice.append(el('strong', '', t(stopped ? 'agent.array_stopped_title' : 'agent.no_data_title')), ' ',
      stopped ? t('agent.array_stopped_text') : t('agent.no_data_text', { dir: Office.agent.no_data.dir }));
    notice.hidden = false;
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
  $('#sso-toasts').appendChild(n);
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

/** buttons: [{text, kind, act}] — when act() returns false the dialog stays open.
    onClose() runs however the dialog ends (button, Escape, click outside, another dialog). */
Office.dialog = function dialog({ title, body, buttons, wide, onClose }) {
  if (closeDialog) closeDialog();
  const backdrop = $('#sso-dialog-backdrop');
  $('#sso-dialog').classList.toggle('wide', !!wide);
  $('#sso-dialog-title').textContent = title;
  const box = $('#sso-dialog-body');
  box.innerHTML = '';
  if (typeof body === 'string') box.appendChild(el('p', '', body));
  else if (body) box.appendChild(body);

  const foot = $('#sso-dialog-foot');
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
  const box = $('#sso-menu');
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
function hideMenu() { $('#sso-menu').hidden = true; }
Office.menuOpen = () => !$('#sso-menu').hidden;

// ------------------------------------------------------------------ selection bar
let selbar = null;
/** Office.selbar({title, sub, buttons:[{text, kind, act}]}) or Office.selbar(null) */
Office.selbar = function setSelbar(spec) {
  if (!spec) {
    if (selbar) { selbar.remove(); selbar = null; }
    ROOT.classList.remove('with-selbar');
    return null;
  }
  if (!selbar) {
    selbar = el('div', 'selbar');
    ROOT.appendChild(selbar);
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
  ROOT.classList.add('with-selbar');
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
  const root = $('#sso-desk');
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
  const nav = $('#sso-tabs');
  nav.innerHTML = '';
  let current = null;
  const add = (href, id, text, active) => {
    const a = el('a', active ? 'active' : '');
    a.href = href;
    if (active) { a.setAttribute('aria-current', 'page'); current = a; }
    const icon = el('span', 'tab-icon');
    icon.appendChild(Office.deskIcon(id));
    a.append(icon, el('span', '', text));
    nav.appendChild(a);
  };
  add('#/', '', t('office.reception'), !Office.current);
  for (const d of Office.desks.values()) if (d.hired) add(`#/${d.id}`, d.id, t(`${d.id}.name`), Office.current === d);
  // on a phone the tabs scroll sideways in their row: the current one in view (only the row moves, not the page)
  if (current && nav.scrollWidth > nav.clientWidth) {
    nav.scrollLeft = Math.max(0, current.offsetLeft - nav.offsetLeft - (nav.clientWidth - current.offsetWidth) / 2);
  }
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

/**
 * A section heading: a title bar (inside Unraid like its own) with the extras
 * (counts, buttons) on its right, the explanation right underneath
 */
Office.sectionHead = function sectionHead(title, sub, ...right) {
  const head = el('div', 'section-head');
  const bar = el('div', 'section-bar');
  bar.appendChild(el('h2', '', title));
  right.filter(Boolean).forEach((x) => bar.appendChild(x));
  head.appendChild(bar);
  if (sub) head.appendChild(el('div', 'section-sub', sub));
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
    ROOT.appendChild(tipBox);
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
  const desk = $('#sso-desk');
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
  const desk = $('#sso-desk');
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
  text.appendChild(el('p', '', t('office.tip_credit')));     // a share goes to helmi1987, who wrote Jack Emby's tools
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

/**
 * A desk's picture: its own drawing (desks/<id>/avatar.svg), else its emoji.
 * '' is the reception. For avatars, tabs and chips alike. A desk in a mood
 * (Office.setDeskMood) shows desks/<id>/avatar-<mood>.svg instead.
 */
const deskSrc = (d) => (d.mood ? d.avatar.replace('/avatar.svg', `/avatar-${d.mood}.svg`) : d.avatar);
Office.deskIcon = function deskIcon(id) {
  const d = id ? (Office.desks.get(id) || CONFIG.desks.find((x) => x.id === id) || {}) : null;
  const src = id ? d.avatar && deskSrc(d) : CONFIG.reception_icon;
  if (!src) return el('span', 'desk-emoji', id ? d.icon || '•' : '🛎️');
  const img = el('img', 'desk-icon');
  img.src = src;
  img.alt = '';
  img.decoding = 'async';
  if (id) img.dataset.desk = id;
  return img;
};
/** Change a desk's picture everywhere it is shown, e.g. the caretaker's badge ('' = its plain one) */
Office.setDeskMood = function setDeskMood(id, mood) {
  const d = Office.desks.get(id);
  if (!d || !d.avatar || (d.mood || '') === (mood || '')) return;
  d.mood = mood || '';
  document.querySelectorAll(`#sso img.desk-icon[data-desk="${id}"]`).forEach((img) => { img.src = deskSrc(d); });
};
/** A round avatar with a desk's picture ('' = the reception); big at the reception */
Office.avatar = function avatar(id, big) {
  const box = el('div', 'avatar' + (big ? ' big' : ''));
  box.appendChild(Office.deskIcon(id));
  return box;
};

/** The desk head every secretary uses: avatar, name, role, speech bubble, actions */
Office.deskHead = function deskHead(desk, { bubble, actions, page, pageSub }) {
  const head = el('div', 'deskhead');
  head.appendChild(Office.avatar(desk.id));
  const text = el('div', 'deskhead-text');
  const b = el('div', 'bubble');
  if (page) {
    // a page of the desk (its setup …): the desk as a way back, the page's name big, no bubble
    head.classList.add('subpage');
    const crumb = el('a', 'deskhead-crumb', t(`${desk.id}.name`));
    crumb.href = `#/${desk.id}`;
    text.append(crumb, el('h1', '', page));
    if (pageSub) text.appendChild(el('div', 'role', pageSub));
  } else {
    text.append(el('h1', '', t(`${desk.id}.name`)), el('div', 'role', t(`${desk.id}.role`)));
    if (bubble) b.append(bubble);
    text.appendChild(b);
  }
  head.appendChild(text);
  const act = el('div', 'deskhead-actions');
  (actions || []).forEach((a) => act.appendChild(a));
  head.appendChild(act);
  return { head, bubble: b, actions: act };
};

// ------------------------------------------------------------------ reception
async function reception(root) {
  const head = el('div', 'deskhead');
  head.appendChild(Office.avatar(''));
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
    top.append(Office.avatar(desk.id, true), name);
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

/**
 * The office's entry in Unraid: where (its own entry in the menu bar, an
 * icon under Settings → User Utilities as before 1.17, or only a button in
 * Unraid's header) and what it is called
 * (a few names to pick or one of the user's own). The caretaker's agent
 * writes it into the plugin's page and its .cfg. Unraid's menu bar on this
 * page gets a new name right away; a move takes the browser to the new place.
 */
function menuNameDialog() {
  const presets = [CONFIG.menu_default, 'Office', 'USO'].filter((n, i, all) => all.indexOf(n) === i);
  const now = CONFIG.menu_name;
  const box = el('div');
  const group = (name, title) => {
    box.appendChild(el('div', 'field-title sso-group-title', title));
    const list = [];
    const option = (value, label, hint) => {
      const l = el('label', 'check');
      const r = el('input');
      r.type = 'radio';
      r.name = name;
      r.value = value;
      const span = el('span', '', label);
      if (hint) span.appendChild(el('small', '', hint));
      l.append(r, span);
      box.appendChild(l);
      list.push(r);
      return r;
    };
    return { list, option };
  };
  const where = group('sso-menu-place', t('office.menu_where'));
  where.option('menu', t('office.menu_place_menu'));
  where.option('settings', t('office.menu_place_settings'));
  where.option('button', t('office.menu_place_button'));
  (where.list.find((r) => r.value === CONFIG.menu_place) || where.list[0]).checked = true;

  const what = group('sso-menu-name', t('office.menu_what'));
  box.appendChild(el('p', 'role', t('office.menu_text')));
  presets.forEach((n) => what.option(n, n, n === 'USO' ? t('office.menu_uso') : ''));
  const own = what.option('', t('office.menu_own'));
  const field = el('div', 'field sso-menu-own');
  const input = el('input', 'input');
  input.maxLength = CONFIG.menu_max || 15;
  input.autocomplete = 'off';
  field.append(input, el('small', '', t('office.menu_own_hint', { max: CONFIG.menu_max || 15 })));
  box.appendChild(field);
  const msg = el('p', 'callout warn');
  msg.hidden = true;
  box.appendChild(msg);
  const pick = what.list.find((r) => r.value === now) || own;
  if (pick === own) input.value = now;
  Office.dialog({
    title: t('office.menu_title'),
    body: box,
    buttons: [
      { text: t('common.cancel') },
      { text: t('office.menu_save'), kind: '', act: async () => {
        const place = where.list.find((r) => r.checked).value;
        const chosen = what.list.find((r) => r.checked);
        const name = (chosen === own ? input.value : chosen.value).trim();
        if (!name) { msg.textContent = t('office.menu_empty'); msg.hidden = false; input.focus(); return false; }
        const j = await Office.api.post('caretaker.menu_name', { name, place });
        if (!j.ok) { msg.textContent = Office.errorText(j.error, 'caretaker'); msg.hidden = false; return false; }
        if (j.place !== CONFIG.menu_place || j.place !== 'menu') {
          // a new place (or its title bar): load the page there — the same address only reloads
          if (j.url === location.pathname) location.reload(); else location.href = j.url + location.hash;
          return true;
        }
        CONFIG.menu_name = j.name;
        document.querySelectorAll(`#menu .nav-item a[href="/${CONFIG.menu_page}"]`).forEach((a) => { a.textContent = j.name; });
        Office.toast(t('office.menu_saved', { name: j.name }));
        return true;
      } },
    ],
  });
  // the dialog focuses the first text field; here the current choice comes first
  pick.checked = true;
  if (pick !== own) pick.focus();
  input.onfocus = input.oninput = () => { own.checked = true; };
}

/** Which language: like Unraid (or this device), or one chosen for this browser */
function languageDialog() {
  const box = el('div');
  box.appendChild(el('p', '', t('office.language_text')));
  const saved = Office.store('lang');
  const radios = [];
  const option = (value, label) => {
    const l = el('label', 'check');
    const r = el('input');
    r.type = 'radio';
    r.name = 'sso-language';
    r.value = value;
    r.checked = value === (saved || '');
    l.append(r, el('span', '', label));
    box.appendChild(l);
    radios.push(r);
  };
  const like = CONFIG.languages.find((l) => l.code === CONFIG.unraid_lang);
  option('', t('office.language_unraid', { name: like ? like.name : 'English' }));
  CONFIG.languages.forEach((l) => option(l.code, l.name));
  Office.dialog({
    title: t('office.language_title'),
    body: box,
    buttons: [
      { text: t('common.cancel') },
      { text: t('office.language_use'), kind: '', act: async () => {
        const value = (radios.find((r) => r.checked) || {}).value || '';
        Office.store('lang', value || null);
        await loadStrings(pickLanguage());
        Office.setAgent(Office.agent);
        route();
        return true;
      } },
    ],
  });
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
  item(t('help.agent_title'), t('help.agent_text'));
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
    { text: t('office.menu_name', { name: CONFIG.menu_name }), act: menuNameDialog },
  ];
  if (CONFIG.languages.length > 1) items.push({ text: t('office.language'), act: languageDialog });
  items.push({ text: t('help.title'), act: Office.help });
  if (Office.current && !Office.current.always) {
    items.push({ separator: true }, { text: t('office.fire_menu', { name: t(`${Office.current.id}.name`) }), act: () => Office.fireDialog(Office.current.id) });
  }
  if (Office.current && Office.current.menu) items.unshift(...Office.current.menu(), { separator: true });
  Office.menu(e, items);
}

function footer() {
  const f = $('#sso-footer');
  f.innerHTML = '';
  f.append(el('span', '', `Unraid Secretary Office v${CONFIG.version}`));
  if (Office.agent.version) f.append(el('span', '', t('agent.version', { version: Office.agent.version })));
  const a = el('a', '', 'GitHub');
  a.href = 'https://github.com/vipermark2/UnraidSecretaryOffice';
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  f.append(a);
}

// ------------------------------------------------------------------ start
async function start() {
  await loadStrings(pickLanguage());
  footer();
  $('#sso-more').onclick = officeMenu;
  $('#sso-state').onclick = Office.help;
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideMenu(); });
  initTips();
  window.addEventListener('scroll', relaxDesk, { passive: true });
  window.addEventListener('hashchange', route);
  route();
  // desks that want to know something on every page (the caretaker's badge), unless the office is locked
  for (const d of Office.desks.values()) if (d.started) d.started();
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
